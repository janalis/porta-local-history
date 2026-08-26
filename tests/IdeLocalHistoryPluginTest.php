<?php

declare(strict_types=1);

namespace Porta\LocalHistory\Tests;

use PHPUnit\Framework\TestCase;
use Porta\LocalHistory\SnapshotStore;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\UsesBundledPlugins;
use Porta\Widget\ContextMenu;
use Porta\Workbench\Dock\BottomDock;
use Porta\Workbench\Menu\MenuItem;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
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
                    $this->awaitPainted($tui, $terminal, 'Local History');
                    $this->assertStringContainsString('Local History', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $tui->handleInput("\x1b[B"); // the older snapshot
                    $tui->handleInput("\r");
                },
                750 => function ($tui, $terminal): void {
                    $this->awaitPainted($tui, $terminal, 'Restored from local history');
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
                    $this->awaitPainted($tui, $terminal, 'No local history for this file yet');
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
                450 => function ($tui, $ignored, $ide) use ($project): void {
                    $tui->handleInput("\x1b\x08"); // list A's history
                    $this->frame($tui);
                    // Switch the editor to B while the tab is up.
                    Services::resolve($ide, \Porta\Editor\BufferService::class)
                        ->openFile($project->path('B.php'), \Porta\Editor\OpenFocus::Editor);
                    $this->frame($tui);
                    $tui->setFocus($tui->getById('results'));
                    $tui->handleInput("\r");
                },
                700 => function ($tui) use ($project): void {
                    $this->frame($tui);
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
                    $this->frame($tui);

                    $dock = Services::resolve($ide, BottomDock::class);
                    $this->assertNull($dock->visiblePanel(), 'no panel opened');
                    $this->assertFalse(is_dir($tui->getById('editor')->getFilePath().'/../.porta/history'), 'and nothing was snapshotted');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    /** The context menu's labels, as one string. */
    private function menuLabels(mixed $ide): string
    {
        $widget = Services::resolve($ide, ContextMenu::class);

        return implode(' | ', array_map(
            static fn (array $row): string => trim(AnsiUtils::stripAnsiCodes($row['segment'])),
            $widget->segments($widget->panelWidth()),
        ));
    }

    /** The tree row index whose rendered text names $file. */
    private function treeRowOf(mixed $tui, mixed $ide, string $file): int
    {
        $tree = $tui->getById('tree');
        $rect = Services::resolve($ide, Renderer::class)->getWidgetRect($tree);
        foreach ($tree->render(new RenderContext($rect->columns, $rect->rows)) as $i => $line) {
            if (str_contains(AnsiUtils::stripAnsiCodes($line), $file)) {
                return $i;
            }
        }

        self::fail('no tree row for '.$file);
    }

    /** Right-click at a 0-based rect offset (SGR is 1-based, hence the +1 on the row). */
    private function rightClick(mixed $tui, mixed $ide, string $widgetId, int $colOffset, int $rowOffset): void
    {
        $rect = Services::resolve($ide, Renderer::class)->getWidgetRect($tui->getById($widgetId));
        $tui->handleInput(\sprintf("\x1b[<2;%d;%dM", $rect->col + $colOffset, $rect->row + $rowOffset + 1));
        $this->frame($tui);
    }

    public function testEveryRightClickOnAFileOffersShowLocalHistory(): void
    {
        // Ctrl+Alt+H is not something anyone finds by looking, and "what did
        // this file look like before?" is a question about the thing under the
        // pointer — the editor text, the tab, or the tree row, which is where
        // it gets asked about a file you are NOT currently editing.
        putenv('PATH=/nonexistent');
        $project = self::project(['a.php' => "x\n", 'other.php' => "y\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal, $ide): void {
                    $this->frame($tui);

                    $this->rightClick($tui, $ide, 'editor', colOffset: 3, rowOffset: 0);
                    $this->assertStringContainsString('Show Local History', $this->menuLabels($ide), 'the editor menu offers it');
                    $tui->handleInput("\x1b");
                    $this->frame($tui);

                    $this->rightClick($tui, $ide, 'tabs', colOffset: 1, rowOffset: 0);
                    $this->assertStringContainsString('Show Local History', $this->menuLabels($ide), 'the tab menu offers it');
                    $tui->handleInput("\x1b");
                    $this->frame($tui);
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testATreeRowShowsTheHistoryOfTheFileYouClicked(): void
    {
        // Not the active file's: the whole reason the file surface carries the
        // clicked path is that right-clicking `other.php` while editing `a.php`
        // must answer about `other.php`.
        putenv('PATH=/nonexistent');
        $project = self::project(['a.php' => "x\n", 'other.php' => "one\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                300 => function ($tui, $terminal, $ide) use ($project): void {
                    // Give other.php a snapshot, without ever making it active.
                    $project->write('other.php', "two\n");
                    $store = new SnapshotStore($project->dir, new NativeClock(), maxPerFile: 25);
                    $store->snapshot($project->path('other.php'), "one\n");

                    $this->frame($tui);
                    $row = $this->treeRowOf($tui, $ide, 'other.php');
                    $this->rightClick($tui, $ide, 'tree', colOffset: 3, rowOffset: $row);
                    $labels = $this->menuLabels($ide);
                    $this->assertStringContainsString('Show Local History', $labels, 'the tree row offers it: '.$labels);

                    // Run it: the dock lists OTHER.php's snapshot, while a.php
                    // is still the active tab.
                    $menu = Services::resolve($ide, ContextMenu::class);
                    /** @var list<MenuItem> $items */
                    $items = Probe::get($menu, 'items');
                    $index = null;
                    foreach ($items as $i => $item) {
                        if (!str_contains($item->label, 'Show Local History')) {
                            continue;
                        }
                        $index = $i;
                    }
                    $this->assertNotNull($index, 'the item is in the list');
                    $menu->runItem($index);
                    // The panel OPENING is the discriminator: a.php has no
                    // snapshots, so had the tool read the active file it would
                    // have flashed "No local history for this file yet" and
                    // opened nothing.
                    $this->await(static fn (): bool => null !== Services::resolve($ide, BottomDock::class)->visiblePanel(), what: "other.php's history list");
                    $this->assertSame('a.php', basename((string) $tui->getById('editor')->getFilePath()), 'and the active tab never changed');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }
}
