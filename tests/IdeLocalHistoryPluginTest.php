<?php

declare(strict_types=1);

namespace Porta\LocalHistory\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\UsesBundledPlugins;
use Porta\Widget\ContextMenu;
use Porta\Workbench\Dock\BottomDock;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\Renderer;

/**
 * Local History through a real IDE, now that it is a plugin — and above all
 * that the move cost the user nothing: Ctrl+Alt+H, the File-menu entry and the
 * single-undo contract all still hold, because the plugin ADOPTED core's
 * `ide_local_history` id rather than taking a namespaced one.
 */
final class IdeLocalHistoryPluginTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPath;
    use RunsIde;
    use UsesBundledPlugins;

    /** @param array<string, string> $files */
    private static function project(array $files): TempProject
    {
        return new TempProject([...$files, '.porta.json' => '{"enabledPlugins":["local-history"]}']);
    }

    public function testSnapshotOnSaveThenRestoreIsOneUndoStep(): void
    {
        putenv('PATH=/nonexistent');
        $project = self::project(['A.php' => "version one\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => static function ($tui): void {
                    // Edit and save → first snapshot; edit and save → second.
                    $d = $tui->getById('editor')->getDocument();
                    $d->selectAll();
                    $d->insert("version two\n");
                    $tui->handleInput("\x13"); // ctrl+s
                },
                450 => static function ($tui): void {
                    $d = $tui->getById('editor')->getDocument();
                    $d->selectAll();
                    $d->insert("version three\n");
                    $tui->handleInput("\x13");
                },
                600 => function ($tui, $terminal): void {
                    $tui->handleInput("\x1b\x08"); // ctrl+alt+h — core's key, the plugin's handler
                    $this->assertSame('results', $tui->getFocus()?->getId());
                    $this->frame($tui);
                    $this->assertStringContainsString('Local History', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $tui->handleInput("\x1b[B"); // the older snapshot
                    $tui->handleInput("\r");
                },
                750 => function ($tui, $terminal): void {
                    $this->frame($tui);
                    $this->assertStringContainsString('Restored from local history', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $this->assertStringContainsString('version two', $tui->getById('editor')->getDocument()->getText());

                    // The whole reason this needed a core verb rather than the
                    // disk-rewrite dodge: one Ctrl+Z, back to what was on screen.
                    $tui->getById('editor')->handleInput("\x1a");
                    $this->assertStringContainsString('version three', $tui->getById('editor')->getDocument()->getText());
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testWithNoSnapshotsItSaysSoInsteadOfOpeningAnEmptyTab(): void
    {
        putenv('PATH=/nonexistent');
        $project = self::project(['A.php' => "x\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => static function ($tui): void {
                    $tui->handleInput("\x1b\x08"); // nothing saved yet
                },
                450 => function ($tui, $terminal): void {
                    $this->frame($tui);
                    $this->assertStringContainsString('No local history for this file yet', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testEnterRestoresIntoTheListedFileNotWhicheverTabIsActive(): void
    {
        // The bug the move carries a fix for: listing A's history, switching to
        // B and pressing Enter used to write A's snapshot into B.
        putenv('PATH=/nonexistent');
        $project = self::project(['A.php' => "a one\n", 'B.php' => "b untouched\n"]);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => static function ($tui): void {
                    $d = $tui->getById('editor')->getDocument();
                    $d->selectAll();
                    $d->insert("a two\n");
                    $tui->handleInput("\x13"); // save → snapshot of "a one"... and "a two"
                },
                450 => static function ($tui, $ignored, $ide) use ($project): void {
                    $tui->handleInput("\x1b\x08"); // list A's history
                    $tui->tick();
                    // Switch the editor to B while the tab is up.
                    Services::resolve($ide, \Porta\Editor\BufferService::class)
                        ->openFile($project->path('B.php'), \Porta\Editor\OpenFocus::Editor);
                    $tui->tick();
                    $tui->setFocus($tui->getById('results'));
                    $tui->handleInput("\r");
                },
                700 => function ($tui) use ($project): void {
                    $tui->tick();
                    $this->assertStringEndsWith('/A.php', (string) $tui->getById('editor')->getFilePath(), 'the listed file was re-opened');
                    $this->assertSame("b untouched\n", (string) file_get_contents($project->path('B.php')), 'B was never written');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testDisablingThePluginTakesTheFeatureWithIt(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['A.php' => "x\n", '.porta.json' => '{"enabledPlugins":[]}']);

        $this->runIde(
            $project,
            $project->path('A.php'),
            [
                300 => function ($tui, $terminal, $ide): void {
                    $tui->handleInput("\x13"); // save: nothing snapshots it
                    $tui->handleInput("\x1b\x08"); // ctrl+alt+h: nothing answers
                    $this->pump(0.2);
                    $tui->tick();

                    $dock = Services::resolve($ide, BottomDock::class);
                    $this->assertNull($dock->visiblePanel(), 'no panel opened');
                    $this->assertFalse(is_dir($tui->getById('editor')->getFilePath().'/../.porta/history'), 'and nothing was snapshotted');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testRightClickingTheEditorOffersShowLocalHistory(): void
    {
        // The discoverable route: Ctrl+Alt+H is not something anyone finds by
        // looking, and "what did this file look like before?" is a question
        // about the file under the pointer.
        putenv('PATH=/nonexistent');
        $project = self::project(['a.php' => "x\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal, $ide): void {
                    $tui->tick();
                    $rect = Services::resolve($ide, Renderer::class)->getWidgetRect($tui->getById('editor'));
                    $tui->handleInput(\sprintf("\x1b[<2;%d;%dM", $rect->col + 3, $rect->row + 1));
                    $tui->tick();

                    $widget = Services::resolve($ide, ContextMenu::class);
                    $labels = array_map(
                        static fn (array $row): string => trim(AnsiUtils::stripAnsiCodes($row['segment'])),
                        $widget->segments($widget->panelWidth()),
                    );
                    $this->assertNotSame([], array_filter($labels, static fn (string $l): bool => str_contains($l, 'Show Local History')), 'the item is offered: '.implode(' | ', $labels));
                },
            ],
        );

        $this->addToAssertionCount(1);
    }
}
