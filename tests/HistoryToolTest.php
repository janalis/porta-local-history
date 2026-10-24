<?php

declare(strict_types=1);

namespace Porta\LocalHistory\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Editor\BufferSaver;
use Porta\Editor\FileOpener;
use Porta\Editor\OpenFocus;
use Porta\I18n\Translator;
use Porta\LocalHistory\HistoryTool;
use Porta\LocalHistory\SnapshotDiff;
use Porta\LocalHistory\SnapshotStore;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginRuntime;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Support\Clipboard;
use Porta\Support\ProjectPaths;
use Porta\Tests\Support\FakePluginLifecycle;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\RecordingChangeMarkSink;
use Porta\Tests\Support\RecordingDiagnosticsSink;
use Porta\Tests\Support\RecordingFileOpener;
use Porta\Tests\Support\StatusReads;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestBottomDock;
use Porta\Tests\Support\TestDockTools;
use Porta\Tests\Support\TestLspQuery;
use Porta\Tests\Support\TestParticipants;
use Porta\Tests\Support\TestProjectSwitcher;
use Porta\Tests\Support\TestRuns;
use Porta\Tests\Support\TestTui;
use Porta\Tests\Support\TestWorkspaceEditor;
use Porta\Tree\FileStatuses;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\FileTreeWidget;
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
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
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

    /** Set before {@see tool()} by the tests that need git absent or stubbed. */
    private ?SnapshotDiff $differ = null;

    /** Set before {@see tool()} by the test that needs the recreate write to fail. */
    private ?Filesystem $fs = null;

    /** Set before {@see tool()} by the test that needs an open to take effect; else a recorder. */
    private ?FileOpener $files = null;

    private RecordingFileOpener $opener;

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
            $this->files ?? ($this->opener = new RecordingFileOpener()),
            $tui,
            new RecordingDiagnosticsSink(),
            new CodeEditorWidget()->decorations,
            new RecordingChangeMarkSink(),
            new FileStatuses(),
            $this->buffers,
            new DockToolController($manager, $this->dock, new ProjectPaths($this->project->dir)),
            TestDockTools::replacer($this->buffers),
            new CodeEditorWidget(),
            new MenuFileContext(),
            new ViewRegistry(),
            new Clipboard($tui),
            new StatusBarWidget(),
            new CodeEditorWidget(),
            new FakePluginLifecycle(),
            new FileTreeWidget(\sys_get_temp_dir()),
            TestLspQuery::create(),
            new BufferSaver(new BufferManager(), new EventDispatcher()),
            TestRuns::create(),
            TestWorkspaceEditor::create(),
            TestParticipants::create(),
            TestProjectSwitcher::create(),
        ));
        $api = $manager->api('local-history', $this->project->dir, $settings);
        $api->registerListTool(HistoryTool::TOOL, 'Local History');
        $api->registerListTool(HistoryTool::RECENT_TOOL, 'Recent Changes');
        $manager->commit($api);

        $api->registerListTool(HistoryTool::DIFF_TOOL, 'Local History Diff');
        $this->clock = new MockClock();
        $store = new SnapshotStore($this->project->dir, $this->clock, maxPerFile: 25);
        $this->differ ??= new SnapshotDiff($this->project->dir);

        return new HistoryTool($api, static fn (): SnapshotStore => $store, fn (): SnapshotDiff => $this->differ, $this->fs ?? new Filesystem());
    }

    /** The rendered results panel, stripped of ANSI escapes. */
    private function panel(): string
    {
        return AnsiUtils::stripAnsiCodes(\implode("\n", $this->results->render(new RenderContext(100, 12))));
    }

    public function testSnapshottingAFileThatIsNoLongerThereGivesUpSilently(): void
    {
        // The window between the disk write and the onFileSaved hook: the file
        // can be gone (a git checkout, a rename) and losing one snapshot is a
        // better outcome than a plugin fault toast on every such save.
        $tool = $this->tool();
        $tool->snapshot($this->project->path('vanished.php'));

        $this->assertSame('', $this->flashText($this->status), 'nothing was reported');
        $this->assertDirectoryDoesNotExist($this->project->path('.porta/history'), 'and nothing was written');
    }

    /**
     * A refactoring's overwrite keeps the version it replaced.
     *
     * The distinction from {@see HistoryTool::snapshot()} is the whole point:
     * that one READS the file, and by the time this event arrives the file
     * already holds the new content. Reading here would store the refactored
     * version and call it history — the one version that is no help at all,
     * since it is what is on disk.
     */
    public function testAnOverwrittenFileKeepsTheVersionItReplacedAndNotTheNewOne(): void
    {
        // The tool builds the project, so it comes first.
        $tool = $this->tool();
        $path = $this->project->path('a.php');
        \file_put_contents($path, data: "<?php // AFTER the refactoring\n");

        $tool->snapshotReplaced($path, "<?php // BEFORE the refactoring\n");

        $store = new SnapshotStore($this->project->dir, $this->clock, maxPerFile: 25);
        $listed = $store->list($path);
        $this->assertCount(1, $listed);
        $this->assertSame("<?php // BEFORE the refactoring\n", \file_get_contents($listed[0]['file']), 'the replaced text, not what is on disk now');
    }

    /**
     * The project-wide view lists every file with history, newest first, and
     * its rows carry a REAL path.
     *
     * That is the difference from the per-file tool, whose rows are
     * location-less because they name snapshots rather than files: here Enter
     * takes core's open-and-jump, and the panel prints the name.
     */
    public function testRecentChangesListsEveryFileWithARealLocation(): void
    {
        $tool = $this->tool();
        // Through the TOOL's own store, like every other test here: it is
        // built inside tool() and a second instance would be a second opinion
        // about the same directory.
        $tool->snapshot($this->project->path('a.php'));
        $this->clock->sleep(1);
        // On disk, because snapshot() reads the file rather than the buffer.
        \file_put_contents($this->project->path('b.php'), data: "b\n");
        $tool->snapshot($this->project->path('b.php'));

        $tool->showRecent();

        $panel = $this->panel();
        $rows = \array_values(\array_filter(\explode("\n", $panel), static fn (string $l): bool => \str_contains($l, '.php')));
        $this->assertStringContainsString('b.php', $rows[0] ?? '', 'the file changed last is on top');
        $this->assertStringContainsString('a.php', $rows[1] ?? '');
        // Read off the rendered panel: one snapshot is "1 version", and the
        // count is the only number on the row that can be either.
        $this->assertStringContainsString('1 version ', $rows[0] ?? '', 'a count of one is not plural');
        $this->assertStringNotContainsString('1 versions', $rows[0] ?? '');
        // A real location, unlike the per-file tool's rows: Enter takes core's
        // open-and-jump, and line 1 is where opening a file puts you.
        $this->assertStringContainsString(':1', $rows[0] ?? '');
        // The row's own words, exactly: the moment to the second, two spaces, the count.
        $when = \date('Y-m-d H:i:s', $this->clock->now()->getTimestamp());
        $this->assertStringContainsString($when.'  1 version', $rows[0] ?? '');
    }

    /** And more than one is. */
    public function testTheVersionCountIsPluralWhenThereIsMoreThanOne(): void
    {
        $tool = $this->tool();
        $path = $this->project->path('a.php');
        $tool->snapshot($path);
        $this->clock->sleep(1);
        \file_put_contents($path, data: "two\n");
        $tool->snapshot($path);

        $tool->showRecent();

        $rows = \array_values(\array_filter(\explode("\n", $this->panel()), static fn (string $l): bool => \str_contains($l, '.php')));
        $this->assertStringContainsString('2 versions', $rows[0] ?? '');
    }

    /** With no history anywhere it says so rather than opening an empty tab. */
    public function testRecentChangesWithNoHistoryAtAllSaysSo(): void
    {
        $this->tool()->showRecent();

        $this->assertSame('No local history yet', $this->flashText($this->status));
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
        $snaps = \glob($dir.'/*/*.snap');
        foreach (false === $snaps ? [] : $snaps as $snap) {
            \unlink($snap);
        }
        $tool->restore(0);

        $this->assertStringContainsString('Could not read that snapshot', $this->flashText($this->status));
        $this->assertSame("one\n", $this->buffers->active()?->document->getText(), 'the buffer is untouched');
    }

    /** The listed file, its history shown, then deleted — with something else on screen. */
    private function listedThenDeleted(HistoryTool $tool): string
    {
        $gone = $this->project->path('a.php');
        $this->buffers->open($gone, "one\n");
        $this->buffers->setActive($gone);
        $tool->snapshot($gone);
        $tool->show();

        \unlink($gone);
        $this->buffers->close($gone);
        $other = $this->project->path('other.php');
        \file_put_contents($other, data: "other\n");
        $this->buffers->open($other, "other\n");
        $this->buffers->setActive($other);

        return $gone;
    }

    /**
     * The case local history exists for. The file is gone and the snapshots are
     * the only copy left, so a restore RECREATES it — `replaceDocument()`
     * declines to touch disk because a file that exists is the user's to keep,
     * and there is nothing here to keep.
     */
    public function testRestoringAFileThatWasDeletedBringsItBack(): void
    {
        $tool = $this->tool();
        $gone = $this->listedThenDeleted($tool);

        $tool->restore(0);

        $this->assertFileExists($gone);
        $this->assertSame("one\n", (string) \file_get_contents($gone), 'and it holds the version that was picked');
    }

    /**
     * The whole of it, through an opener that opens: the recreated file ends up
     * on screen holding the version that was picked, and says so. (The rig's
     * recording opener cannot tell a restore from a recreate that stopped
     * halfway: both leave the file on disk and another buffer active.)
     */
    public function testARestoredDeletedFileIsOpenHoldingThePickedVersion(): void
    {
        // The buffers are the rig's, built inside tool(): asked for at the open.
        $this->files = new readonly class(fn (): BufferManager => $this->buffers) implements FileOpener {
            /** @param \Closure(): BufferManager $buffers */
            public function __construct(
                private \Closure $buffers,
            ) {}

            #[\Override]
            public function openFile(string $path, OpenFocus $focus = OpenFocus::Keep): void
            {
                $buffers = ($this->buffers)();
                $buffers->open($path, (string) \file_get_contents($path));
                $buffers->setActive($path);
            }
        };
        $tool = $this->tool();
        $gone = $this->listedThenDeleted($tool);

        $tool->restore(0);

        $this->assertSame($gone, $this->buffers->active()?->path, 'the recreated file is the one on screen');
        $this->assertSame("one\n", $this->buffers->active()->document->getText());
        $this->assertStringContainsString('Restored from local history', $this->flashText($this->status));
    }

    /**
     * The listed file cannot be recreated. `restore()` ends in
     * `replaceDocument()`, which replaces whatever is ACTIVE — so giving up
     * has to mean giving up, not falling through into the buffer on screen.
     */
    public function testAFileThatCannotBeRecreatedIsNamedRatherThanRestoredElsewhere(): void
    {
        $this->fs = new class extends Filesystem {
            #[\Override]
            public function dumpFile(string $filename, $content): void
            {
                throw new IOException('disk full');
            }
        };
        $tool = $this->tool();
        $gone = $this->listedThenDeleted($tool);

        $tool->restore(0);

        $this->assertFileDoesNotExist($gone);
        $this->assertSame("other\n", $this->buffers->active()?->document->getText(), 'the file on screen is not the one that was listed');
        $this->assertStringContainsString('a.php', $this->flashText($this->status), 'and it says which file it could not write');
    }

    /**
     * The same refusal one step later: the file is there, so nothing is
     * recreated, but the open does not take effect. (The rig's opener records
     * rather than opens, which is exactly that shape.) `replaceDocument()` must
     * still not fire.
     */
    public function testARestoreWhoseOpenDoesNotTakeEffectLeavesTheScreenAlone(): void
    {
        $tool = $this->tool();
        $path = $this->project->path('a.php');
        $this->buffers->open($path, "one\n");
        $this->buffers->setActive($path);
        $tool->snapshot($path);
        $tool->show();

        $other = $this->project->path('other.php');
        \file_put_contents($other, data: "other\n");
        $this->buffers->open($other, "other\n");
        $this->buffers->setActive($other);

        $tool->restore(0);

        $this->assertSame("other\n", $this->buffers->active()?->document->getText());
        $this->assertStringContainsString('a.php', $this->flashText($this->status));
    }

    /**
     * The snapshot rows carry no LOCATION on purpose, so the name has to ride
     * the text — these rows are about a file that need not be on screen (right
     * click another file in the tree, or come from the project-wide view for
     * one that is gone), and a panel offering to overwrite a file it does not
     * name is the wrong thing to be looking at.
     */
    public function testEverySnapshotRowNamesTheFileItIsAbout(): void
    {
        $tool = $this->tool();
        $path = $this->project->path('a.php');
        $this->buffers->open($path, "one\n");
        $this->buffers->setActive($path);
        $tool->snapshot($path);
        $this->clock->sleep(1);
        \file_put_contents($path, data: "two\n");
        $tool->snapshot($path);

        $tool->show();

        $rows = \array_values(\array_filter(\explode("\n", $this->panel()), static fn (string $l): bool => \str_contains($l, '20')));
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertStringContainsString('a.php', $row, 'every row, not just the first');
        }
    }

    /** Enter on a Recent Changes row whose file is gone reaches its history instead of a dead end. */
    public function testRecentChangesOpensTheHistoryOfAFileThatIsGone(): void
    {
        $tool = $this->tool();
        $gone = $this->listedThenDeleted($tool);
        // Away from that file's list first: it is already on the panel from
        // the setup, and seeing it there would prove nothing.
        $tool->showRecent();
        $this->assertStringNotContainsString('latest', $this->panel());

        $tool->openOrShowHistory('a.php', 0);

        $this->assertStringContainsString('is gone', $this->flashText($this->status));
        $this->assertStringContainsString('a.php  ', $this->panel(), 'and the panel names the file it is about — nothing else on screen does');
        $this->assertStringContainsString('latest', $this->panel(), 'and lists that file\'s snapshots');
        $this->assertStringContainsString(\date('Y-m-d H:i:s', $this->clock->now()->getTimestamp()), $this->panel(), 'each to the second it was taken');
        $this->assertFileDoesNotExist($gone, 'looking is not restoring');
    }

    /** And a row whose file is still there just opens it. */
    public function testRecentChangesOpensAFileThatIsStillThere(): void
    {
        $tool = $this->tool();
        $tool->openOrShowHistory('a.php', 0);

        $this->assertSame([$this->project->path('a.php')], \array_column($this->opener->opened, 'path'), 'it is opened');
        $this->assertStringNotContainsString('is gone', $this->flashText($this->status));
    }

    public function testComparingWithNoFileOpenDoesNothingAtAll(): void
    {
        $tool = $this->tool();

        $tool->compare();

        $this->assertSame('', $this->flashText($this->status), 'no file, no question to answer');
    }

    public function testComparingWithoutGitSaysWHICHHalfIsMissing(): void
    {
        // Snapshots still record and still restore without git. Only the
        // comparison needs it, and an empty panel would not say so.
        \putenv('PATH=/nonexistent');
        $this->differ = new SnapshotDiff(\sys_get_temp_dir());
        $tool = $this->tool();
        $this->buffers->open($this->project->path('a.php'), "one\n");

        $tool->compare();

        $this->assertSame('Comparing snapshots needs git on your PATH', $this->flashText($this->status));
    }

    public function testComparingAFileWithNoHistoryYetSaysSo(): void
    {
        // The same refusal `show()` gives, and for the same reason: there is
        // nothing to pick from, so opening a picker would be a dead end.
        $tool = $this->tool();
        $this->buffers->open($this->project->path('a.php'), "one\n");

        $tool->compare();

        $this->assertSame('No local history for this file yet', $this->flashText($this->status));
    }

    public function testOnlyTheNewestRowIsMarkedLatest(): void
    {
        $tool = $this->tool();
        $path = $this->project->path('a.php');
        $this->buffers->open($path, "one\n");
        $this->buffers->setActive($path);

        $tool->snapshot($path);
        $this->buffers->active()->document->insert(' v2');
        \file_put_contents($path, $this->buffers->active()?->document->getText());
        $this->clock->sleep(1);
        $tool->snapshot($path);

        $tool->show();

        $plain = $this->panel();
        $latestCount = \substr_count($plain, needle: '(latest)');
        $this->assertSame(1, $latestCount, 'exactly one row is marked latest: '.$plain);

        $lines = \array_values(\array_filter(\explode("\n", $plain), static fn (string $l): bool => \str_contains($l, ':')));
        $this->assertStringContainsString('(latest)', $lines[0] ?? '', 'the newest row (first, on top) carries the label');
        $this->assertStringNotContainsString('(latest)', $lines[1] ?? '', 'the older row does not');
    }
}
