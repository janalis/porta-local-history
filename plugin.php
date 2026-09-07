<?php

declare(strict_types=1);

use Porta\Event\FileRenamed;
use Porta\Event\PluginObservable;
use Porta\LocalHistory\HistoryTool;
use Porta\LocalHistory\SnapshotDiff;
use Porta\LocalHistory\SnapshotStore;
use Porta\Plugin\Api\AbstractPlugin;
use Porta\Plugin\Api\PluginApi;
use Porta\Workbench\Menu\MenuSurface;
use Symfony\Component\Clock\NativeClock;

/**
 * Local History as a bundled plugin: a per-file snapshot on every save, and a
 * dock tab that restores one as a single undoable edit.
 *
 * It keeps its identity — `adoptCommand('ide_local_history')` registers the
 * handler under core's BARE id, so Ctrl+Alt+H still works, an existing
 * `.porta.json` rebind still binds, and the File-menu entry stays where it was
 * instead of moving to the trailing Plugins menu. Core still NAMES the verb in
 * its ActionCatalog; only the implementation is here — which is also why the
 * `addMenuItem()` below names that bare id and adds only the CONTEXT-menu entry:
 * the File-menu one is core's own spec, already in place.
 *
 * Enabled by default ({@see \Porta\Config\IdeConfig::DEFAULT_ENABLED_PLUGINS}).
 * Disabling it stops snapshots being TAKEN; the ones already under
 * `.porta/history` are untouched and come back when it is re-enabled.
 */
return new class('local-history') extends AbstractPlugin {
    public function activate(PluginApi $api): void
    {
        $api->registerSetting('maxPerFile', 'Snapshots kept per file', 'int', 25, min: 1, max: 500);

        // ONE tool, because it remembers which file's snapshots are on display
        // — Enter has to restore into that file, and a per-call instance would
        // forget between showing the rows and pressing it.
        //
        // The STORE is what is rebuilt per operation: its cap comes from a
        // setting, so reading it fresh means an edit applies to the next
        // snapshot with nothing to invalidate.
        $tool = new HistoryTool(
            $api,
            static fn (): SnapshotStore => new SnapshotStore(
                $api->projectRoot(),
                new NativeClock(),
                max(1, (int) $api->setting('maxPerFile')),
            ),
            static fn (): SnapshotDiff => new SnapshotDiff($api->projectRoot()),
        );

        $api->registerListTool(
            HistoryTool::TOOL,
            'Local History',
            rerun: $tool->show(...),
            // Enter restores; without it the row would take core's
            // open-and-jump, which for a location-less row means nothing.
            activate: static fn (string $path, int $line) => $tool->restore($line),
        );
        $api->registerListTool(HistoryTool::DIFF_TOOL, 'Local History Diff');
        // Its rows carry a real path, so core's open-and-jump is exactly right
        // and no activate hook is needed — unlike the per-file tool above,
        // whose rows are location-less.
        $api->registerListTool(HistoryTool::RECENT_TOOL, 'Recent Changes', rerun: $tool->showRecent(...), activate: $tool->openOrShowHistory(...));
        $api->registerCommand('recent_changes', 'Recent Changes', $tool->showRecent(...));
        $api->adoptCommand('ide_local_history', $tool->show(...));
        // Its own id, not one of core's: core never named a compare verb, and
        // inventing a bare id here would claim a keybinding slot the keymap
        // page knows nothing about.
        $api->registerCommand('compare_snapshot', 'Compare with Local History', $tool->compare(...));
        $api->addMenuItem('compare_snapshot', surface: MenuSurface::EditorContext);
        $api->addMenuItem('compare_snapshot', surface: MenuSurface::FileContext);
        // Right-click in the editor offers it too: "what did this file look
        // like before?" is a question about the file you are pointing at, and
        // Ctrl+Alt+H is not discoverable.
        $api->addMenuItem('ide_local_history', surface: MenuSurface::EditorContext);
        // …and on the FILE menus (a tree row, a tab), where it acts on the file
        // you pointed at rather than the one you happen to be editing.
        $api->addMenuItem('ide_local_history', surface: MenuSurface::FileContext);
        $api->onFileSaved($tool->snapshot(...));
        // A refactoring rewriting a file with no buffer open is the case with
        // no other recovery: no save fires, so no snapshot was ever taken, and
        // there is no undo stack because there is no document. The event hands
        // over what the write replaced, which is the version worth keeping.
        $api->onFileRewritten($tool->snapshotReplaced(...));
        // Snapshots are keyed by a hash of the path, so a rename orphans a
        // file's whole history unless something moves it. Core's six
        // path-keyed stores follow the same fact.
        $api->on(FileRenamed::class, static function (PluginObservable $event) use ($tool): void {
            // Widened then narrowed, the way plugins/git does it: on() hands
            // every listener the marker type, and a closure typed to the
            // subclass is not one it can accept.
            \assert($event instanceof FileRenamed, description: 'on() delivers the class it was registered for');
            $tool->follow($event->from, $event->to);
        });
    }
};
