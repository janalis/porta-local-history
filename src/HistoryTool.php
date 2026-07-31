<?php

declare(strict_types=1);

namespace Porta\LocalHistory;

use Porta\Plugin\Api\PluginApi;
use Porta\Workbench\Dock\ToolRun;

/**
 * The feature itself: snapshot on save, list a file's snapshots in a dock tab,
 * restore the picked one into the buffer as a single undoable edit.
 *
 * It lives here rather than in `plugin.php` because a plugin file only WIRES —
 * everything under `src/` is analysed by mago and held at 100% by this plugin's
 * own tests, and a restore that swaps a whole document is exactly the logic
 * that must be.
 *
 * It talks to the IDE through {@see PluginApi} alone. The one thing that took a
 * new core verb is `replaceDocument()`: the disk-rewrite dodge the formatters
 * used would lose both the single undo step and the dirty-buffer semantics,
 * which for an undo safety net is the whole product.
 */
final class HistoryTool
{
    public const string TOOL = 'local-history';

    /** @var list<string> snapshot files backing the rows currently on display */
    private array $listed = [];

    /** The file those rows belong to — Enter must restore into IT, not into whatever tab is active now. */
    private ?string $listedPath = null;

    /** @param \Closure(): SnapshotStore $store rebuilt per operation, so the per-file cap setting stays live */
    public function __construct(
        private readonly PluginApi $api,
        private readonly \Closure $store,
    ) {}

    /** Save-time snapshot. The bytes are already on disk when onFileSaved fires. */
    public function snapshot(string $path): void
    {
        $content = is_file($path) ? file_get_contents($path) : false;
        if (false === $content) {
            return; // deleted or unreadable between the write and this hook
        }
        ($this->store)()->snapshot($path, $content);
    }

    /** Show the active file's snapshots, newest first. */
    public function show(): void
    {
        $path = $this->api->activeFile();
        if (null === $path) {
            return;
        }

        $snapshots = ($this->store)()->list($path);
        if ([] === $snapshots) {
            $this->api->flash($this->api->trans('No local history for this file yet'));

            return;
        }

        $this->listed = [];
        $this->listedPath = $path;
        $rows = [];
        foreach ($snapshots as $index => $snapshot) {
            $this->listed[] = $snapshot['file'];
            $when = date('Y-m-d H:i:s', intdiv($snapshot['stamp'], num2: 1000));
            // A location-less row (empty path), like the log tools': the panel
            // then prints no `file:line`, where the old core version rendered a
            // basename and an index AS a line number — a fake location on every
            // row. The index still rides `line`, invisibly, because Enter's
            // callback is the only channel back.
            $rows[] = ['path' => '', 'line' => $index, 'text' => $when.(0 === $index ? '  ('.$this->api->trans('latest').')' : '')];
        }

        $this->api->showList(self::TOOL, $rows, ToolRun::Finished);
    }

    /**
     * Restore the picked snapshot.
     *
     * Into the file the rows belong to, which is not necessarily the active tab
     * — listing A's history, switching to B and pressing Enter used to write
     * A's snapshot into B.
     */
    public function restore(int $index): void
    {
        $file = $this->listed[$index] ?? null;
        $path = $this->listedPath;
        if (null === $file || null === $path) {
            return;
        }
        $content = ($this->store)()->read($file);
        if (null === $content) {
            $this->api->flash($this->api->trans('Could not read that snapshot'));

            return;
        }
        if ($this->api->activeFile() !== $path) {
            $this->api->openFile($path);
        }
        $this->api->replaceDocument($content);
        $this->api->flash($this->api->trans('Restored from local history'));
    }
}
