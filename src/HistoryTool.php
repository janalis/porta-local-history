<?php

declare(strict_types=1);

namespace Porta\LocalHistory;

use Porta\Overlay\State\ToastSeverity;
use Porta\Plugin\Api\PluginApi;
use Porta\Support\Files;
use Porta\Workbench\Dock\ToolRun;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The feature itself: snapshot on save, list a file's snapshots in a dock tab,
 * restore the picked one into the buffer as a single undoable edit.
 *
 * It lives here rather than in `porta.php` because a plugin file only WIRES —
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
    public const string DIFF_TOOL = 'local-history-diff';
    public const string RECENT_TOOL = 'local-history-recent';

    /** @var list<string> snapshot files backing the rows currently on display */
    private array $listed = [];

    /** The file those rows belong to — Enter must restore into IT, not into whatever tab is active now. */
    private ?string $listedPath = null;

    /**
     * @param \Closure(): SnapshotStore $store rebuilt per operation, so the per-file cap setting stays live
     * @param \Closure(): SnapshotDiff  $diff  likewise: it resolves the git binary, and PATH is not ours
     */
    public function __construct(
        private readonly PluginApi $api,
        private readonly \Closure $store,
        private readonly \Closure $diff,
        // Defaulted, the {@see \Porta\GraphViewer\ArchitectureArtifact} pattern:
        // only the recreate path writes, and a test that wants it to fail
        // passes a throwing one.
        private readonly Filesystem $fs = new Filesystem(),
    ) {}

    /**
     * Every file with history, newest change first — the project-wide view.
     *
     * The per-file tool answers "what did THIS file look like before"; this one
     * answers "what have I been changing", which is the question you have when
     * you do not yet know which file to ask about. PhpStorm ships both.
     *
     * Rows carry a REAL location, unlike the per-file tool's: a path with no
     * line, so Enter opens the file and the panel prints its name. The verb a
     * row offers is therefore "take me there", not "restore" — restoring
     * needs a snapshot chosen, and choosing is what the per-file tool is for.
     */
    public function showRecent(): void
    {
        $recent = ($this->store)()->recent();
        if ([] === $recent) {
            $this->api->flash($this->api->trans('No local history yet'));

            return;
        }

        $rows = [];
        foreach ($recent as $entry) {
            $when = \date('Y-m-d H:i:s', \intdiv($entry['stamp'], num2: 1000));
            $rows[] = [
                'path' => $entry['path'],
                'line' => 0,
                'text' => $when.'  '.$this->api->trans('%count% version|%count% versions', ['%count%' => $entry['count']]),
            ];
        }

        $this->api->showList(self::RECENT_TOOL, $rows, ToolRun::Finished);
    }

    /** Save-time snapshot. The bytes are already on disk when onFileSaved fires. */
    public function snapshot(string $path): void
    {
        $content = Files::tryRead($path);
        if (null === $content) {
            return; // deleted or unreadable between the write and this hook
        }
        ($this->store)()->snapshot($path, $content);
    }

    /**
     * Keep the content a refactoring overwrote.
     *
     * Unlike {@see snapshot()} this does NOT read the file: by the time the
     * event arrives the new content is already on disk, and the version worth
     * keeping is the one the write replaced — which the event carries because
     * it exists nowhere else.
     */
    public function snapshotReplaced(string $path, string $previousText): void
    {
        ($this->store)()->snapshot($path, $previousText);
    }

    /**
     * Follow a renamed file, so its history is still under Ctrl+Alt+H.
     *
     * The store is rebuilt per operation like everywhere else here — the cap is
     * a live setting — and both paths arrive canonical from the fact.
     */
    public function follow(string $from, string $to): void
    {
        ($this->store)()->rename($from, $to);
    }

    /**
     * Show a file's snapshots, newest first — the one the invocation is about:
     * the right-clicked path from a file context menu, else the active file.
     */
    public function show(): void
    {
        $path = $this->api->contextFile();
        if (null === $path) {
            return;
        }

        $this->showFor($path);
    }

    /**
     * The same list for a path that is NOT the file on screen — which for a
     * file that has been deleted is the only way to reach its history at all,
     * since there is no buffer for it to be the context of.
     */
    public function showFor(string $path): void
    {
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
            $when = \date('Y-m-d H:i:s', \intdiv($snapshot['stamp'], num2: 1000));
            // A location-less row (empty path), like the log tools': the panel
            // then prints no `file:line`, where the old core version rendered a
            // basename and an index AS a line number — a fake location on every
            // row. The index still rides `line`, invisibly, because Enter's
            // callback is the only channel back.
            //
            // The NAME rides the text instead, on every row and unconditionally.
            // These rows are about a file that need not be on screen — reached
            // by right-clicking another file in the tree, or (since the file
            // may be gone entirely) from the project-wide view — and a panel
            // offering to overwrite a file it does not name is the wrong thing
            // to be looking at. Conditioning it on "is this the active file"
            // was the first draft and is wrong for the same reason: the answer
            // changes the moment the user switches tabs, and the rows do not.
            $rows[] = ['path' => '', 'line' => $index, 'text' => \basename($path).'  '.$when.(0 === $index ? '  ('.$this->api->trans('latest').')' : '')];
        }

        $this->api->showList(self::TOOL, $rows, ToolRun::Finished);
    }

    /**
     * Enter on a Recent Changes row: open the file, or — when it is gone —
     * show what is left of it.
     *
     * Core's own open-and-jump is right for a file that still exists and is a
     * dead end for one that does not: it flashes "cannot read" and the
     * snapshots, which are the only surviving copy, stay unreachable because
     * the per-file view asks the ACTIVE file what it is about. This is the
     * case local history exists for, so it is the case that must work.
     */
    public function openOrShowHistory(string $path, int $line): void
    {
        $absolute = \str_starts_with($path, '/') ? $path : $this->api->projectRoot().'/'.$path;
        if (\is_file($absolute)) {
            $this->api->openFile($absolute);

            return;
        }
        $this->api->flash($this->api->trans('%file% is gone — restoring a version will bring it back', ['%file%' => \basename($absolute)]));
        $this->showFor($absolute);
    }

    /**
     * Show what a snapshot would change: pick one, then read the diff between
     * it and the file as it stands.
     *
     * A second verb rather than a second meaning for Enter, because restoring
     * is what the panel is FOR — putting a confirmation step in front of it
     * would cost the feature its one keystroke.
     */
    public function compare(): void
    {
        $path = $this->api->contextFile();
        if (null === $path) {
            return;
        }

        $differ = ($this->diff)();
        if (null === $differ->binary()) {
            // Snapshots still record and still restore without git; only the
            // comparison needs it. Say which, rather than open an empty panel.
            $this->api->flash($this->api->trans('Comparing snapshots needs git on your PATH'));

            return;
        }

        $this->pickToCompare($path, $differ);
    }

    /** Offer $path's snapshots; the picked one is diffed against the file by $differ. */
    private function pickToCompare(string $path, SnapshotDiff $differ): void
    {
        $snapshots = ($this->store)()->list($path);
        if ([] === $snapshots) {
            $this->api->flash($this->api->trans('No local history for this file yet'));

            return;
        }

        $items = [];
        foreach ($snapshots as $snapshot) {
            $items[] = [
                'label' => \date('Y-m-d H:i:s', \intdiv($snapshot['stamp'], num2: 1000)),
                'hint' => \basename($path),
                'value' => $snapshot['file'],
            ];
        }

        $this->api->pick($this->api->trans('Compare with Snapshot'), $items, function (string $file) use ($path, $differ): void {
            $differ->run(
                $file,
                $path,
                /** @param list<string>|null $lines */ function (?array $lines) use ($path): void {
                    if (null === $lines) {
                        // git could not compare them — a file gone from disk, a run
                        // past its timeout: not "they match", which was said.
                        $this->api->notify($this->api->trans('Could not read that snapshot'), ToastSeverity::Error);

                        return;
                    }
                    if ([] === $lines) {
                        $this->api->flash($this->api->trans('That snapshot matches the file on disk'));

                        return;
                    }

                    $rows = [];
                    foreach ($lines as $line) {
                        // Location-less, like the history rows: a diff line has no
                        // place to jump to, and the panel prints no `file:line`
                        // rather than inventing one.
                        $rows[] = ['path' => '', 'line' => 0, 'text' => $line];
                    }
                    $this->api->showList(self::DIFF_TOOL, $rows, ToolRun::Finished);
                    $this->api->flash($this->api->trans('Comparing %file% with a snapshot', ['%file%' => \basename($path)]));
                },
            );
        });
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
            $this->api->notify($this->api->trans('Could not read that snapshot'), ToastSeverity::Error);

            return;
        }
        $this->restoreInto($path, $content);
    }

    private function restoreInto(string $path, string $content): void
    {
        // A file that is GONE is recreated from the snapshot rather than
        // refused. `replaceDocument()` never touches disk because a file that
        // exists is the user's to keep — but there is nothing here to keep, no
        // buffer to replace, and this copy is the last one anywhere. Core does
        // the same for a scratch file: mint it on disk, then open it.
        if (!\is_file($path) && !$this->recreate($path, $content)) {
            return;
        }
        if (!$this->isActive($path)) {
            $this->api->openFile($path);
        }
        // The POSTCONDITION, not the attempt. `replaceDocument()` replaces
        // whatever is active, and `openFile()` declines a file that has been
        // deleted or made unreadable — so without this the snapshot of a file
        // that is gone lands in whichever buffer the user happens to be looking
        // at. An undo safety net that overwrites an unrelated open file is the
        // worst thing this plugin could do.
        if (!$this->isActive($path)) {
            $this->api->notify($this->api->trans('Cannot restore %file% — it is no longer there', ['%file%' => \basename($path)]), ToastSeverity::Error);

            return;
        }
        $this->api->replaceDocument($content);
        $this->api->flash($this->api->trans('Restored from local history'));
    }

    /** Write $content to the vanished $path; false (and said so) when it cannot be. */
    private function recreate(string $path, string $content): bool
    {
        try {
            $this->fs->dumpFile($path, $content);
        } catch (IOExceptionInterface) {
            $this->api->notify($this->api->trans('Cannot write %file%', ['%file%' => \basename($path)]), ToastSeverity::Error);

            return false;
        }

        return true;
    }

    /**
     * Whether the active buffer is the file at $path. Compared resolved: a
     * tree row names the file as the root was typed, a buffer is keyed by its
     * real path, and in a project opened through a symlink the two never
     * matched — every restore from the tree said the file was gone.
     */
    private function isActive(string $path): bool
    {
        $active = $this->api->activeFile();
        $real = \realpath($path);

        return $active === $path || (null !== $active && false !== $real && \realpath($active) === $real);
    }
}
