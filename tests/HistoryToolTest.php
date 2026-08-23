<?php

declare(strict_types=1);

namespace Porta\LocalHistory\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\I18n\Translator;
use Porta\LocalHistory\HistoryTool;
use Porta\LocalHistory\SnapshotStore;
use Porta\Plugin\Api\PluginApi;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginRuntime;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Support\Clipboard;
use Porta\Support\ProjectPaths;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\RecordingChangeMarkSink;
use Porta\Tests\Support\RecordingDiagnosticsSink;
use Porta\Tests\Support\RecordingFileOpener;
use Porta\Tests\Support\StatusReads;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestBottomDock;
use Porta\Tests\Support\TestDockTools;
use Porta\Tests\Support\TestTui;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Widget\TerminalPanelWidget;
use Porta\Workbench\Dock\BottomDock;
use Porta\Workbench\Dock\DockToolController;
use Porta\Workbench\Menu\MenuFileContext;
use Porta\Workbench\Notifier;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * The tool's refusals — the paths an IDE-level flow never reaches on purpose,
 * because each of them is "the user did something while the list was up".
 */
final class HistoryToolTest extends TestCase
{
    use PreservesPath;
    use StatusReads;

    private BufferManager $buffers;
    private BottomDock $dock;
    private StatusBarWidget $status;
    private TempProject $project;
    private ResultsPanelWidget $results;
    private MockClock $clock;

    private function tool(): HistoryTool
    {
        $this->project = new TempProject(['a.php' => "one\n"]);
        $settings = new PluginSettings($this->project->dir, []);
        $manager = new PluginManager(new EventDispatcher(), $settings);
        $tui = TestTui::create(100, 24);
        $this->buffers = new BufferManager();
        $this->status = new StatusBarWidget();
        $this->results = new ResultsPanelWidget();
        $this->dock = TestBottomDock::create($tui, new TerminalPanelWidget(), $this->results, new DebugPanelWidget());
        $manager->wireRuntime(new PluginRuntime(
            new Notifier($this->status, $tui),
            new Translator('en'),
            new CodeEditorWidget(),
            new RecordingFileOpener(),
            $tui,
            new RecordingDiagnosticsSink(),
            new CodeEditorWidget(),
            new RecordingChangeMarkSink(),
            $this->buffers,
            new DockToolController($manager, $this->dock, new ProjectPaths($this->project->dir)),
            TestDockTools::replacer($this->buffers),
            new CodeEditorWidget(),
            new MenuFileContext(),
            new ViewRegistry(),
            new Clipboard($tui),
            new StatusBarWidget(),
            new CodeEditorWidget(),
        ));
        $api = new PluginApi('local-history', $manager, $this->project->dir, $settings);
        $api->registerListTool(HistoryTool::TOOL, 'Local History');
        $manager->commit($api);

        $this->clock = new MockClock();
        $store = new SnapshotStore($this->project->dir, $this->clock, maxPerFile: 25);

        return new HistoryTool($api, static fn (): SnapshotStore => $store);
    }

    /** The rendered results panel, stripped of ANSI escapes. */
    private function panel(): string
    {
        return AnsiUtils::stripAnsiCodes(implode("\n", $this->results->render(new RenderContext(100, 12))));
    }

    public function testSnapshottingAFileThatIsNoLongerThereGivesUpSilently(): void
    {
        // The window between the disk write and the onFileSaved hook: the file
        // can be gone (a git checkout, a rename) and losing one snapshot is a
        // better outcome than a plugin fault toast on every such save.
        $tool = $this->tool();
        $tool->snapshot($this->project->path('vanished.php'));

        $this->assertSame('', $this->flashText($this->status), 'nothing was reported');
        $this->assertFalse(is_dir($this->project->path('.porta/history')), 'and nothing was written');
    }

    public function testShowingWithNoFileOpenDoesNothingAtAll(): void
    {
        // Ctrl+Alt+H with an empty workspace: not even a flash, because there
        // is no file the message could name.
        $tool = $this->tool();
        $tool->show();

        $this->assertSame('', $this->flashText($this->status));
        $this->assertSame([], $this->dock->aliveTabs());
    }

    public function testRestoringWithoutAListedSnapshotIsANoOp(): void
    {
        // Enter on a row whose index no longer resolves — the list was never
        // shown, or was shown for a file and then re-shown for another.
        $tool = $this->tool();
        $tool->restore(0);

        $this->assertSame('', $this->flashText($this->status));
    }

    public function testAnUnreadableSnapshotSaysSoRatherThanRestoringNothing(): void
    {
        $tool = $this->tool();
        $path = $this->project->path('a.php');
        $this->buffers->open($path, "one\n");
        $this->buffers->setActive($path);
        $tool->snapshot($path);
        $tool->show();

        // The snapshot file disappears between listing and Enter.
        $dir = $this->project->path('.porta/history');
        $snaps = glob($dir.'/*/*.snap');
        foreach (false === $snaps ? [] : $snaps as $snap) {
            unlink($snap);
        }
        $tool->restore(0);

        $this->assertStringContainsString('Could not read that snapshot', $this->flashText($this->status));
        $this->assertSame("one\n", $this->buffers->active()?->document->getText(), 'the buffer is untouched');
    }

    public function testOnlyTheNewestRowIsMarkedLatest(): void
    {
        $tool = $this->tool();
        $path = $this->project->path('a.php');
        $this->buffers->open($path, "one\n");
        $this->buffers->setActive($path);

        $tool->snapshot($path);
        $this->buffers->active()->document->insert(' v2');
        file_put_contents($path, (string) $this->buffers->active()?->document->getText());
        $this->clock->sleep(1);
        $tool->snapshot($path);

        $tool->show();

        $plain = $this->panel();
        $latestCount = substr_count($plain, needle: '(latest)');
        $this->assertSame(1, $latestCount, 'exactly one row is marked latest: '.$plain);

        $lines = array_values(array_filter(explode("\n", $plain), static fn (string $l): bool => str_contains($l, ':')));
        $this->assertStringContainsString('(latest)', $lines[0] ?? '', 'the newest row (first, on top) carries the label');
        $this->assertStringNotContainsString('(latest)', $lines[1] ?? '', 'the older row does not');
    }
}
