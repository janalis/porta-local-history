<?php

declare(strict_types=1);

use Porta\LocalHistory\HistoryTool;
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
        );

        $api->registerListTool(
            HistoryTool::TOOL,
            'Local History',
            rerun: $tool->show(...),
            // Enter restores; without it the row would take core's
            // open-and-jump, which for a location-less row means nothing.
            activate: static fn (string $path, int $line) => $tool->restore($line),
        );
        $api->adoptCommand('ide_local_history', $tool->show(...));
        // Right-click in the editor offers it too: "what did this file look
        // like before?" is a question about the file you are pointing at, and
        // Ctrl+Alt+H is not discoverable.
        $api->addMenuItem('ide_local_history', surface: MenuSurface::EditorContext);
        // …and on the FILE menus (a tree row, a tab), where it acts on the file
        // you pointed at rather than the one you happen to be editing.
        $api->addMenuItem('ide_local_history', surface: MenuSurface::FileContext);
        $api->onFileSaved($tool->snapshot(...));
    }
};
