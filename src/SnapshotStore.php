<?php

declare(strict_types=1);

namespace Porta\LocalHistory;

use Psr\Clock\ClockInterface;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Timestamped local snapshots of edited files, kept under
 * `<root>/.porta/history/<hash>/<stamp>.snap`. A hard per-file cap prunes
 * the oldest snapshots. Independent of git — this is the editor's own undo
 * safety net across sessions.
 *
 * The path layout is unchanged from when this lived in `src/`, deliberately:
 * every existing user's snapshots stay reachable across the move, and there is
 * no migration worth writing for what is a cache.
 */
final readonly class SnapshotStore
{
    /**
     * Holds the absolute path whose history a directory is.
     *
     * The directory name is `md5(path)`, and a hash does not run backwards —
     * so without this nothing can say which file a snapshot belongs to, which
     * is exactly what a project-wide view has to answer.
     */
    private const string PATH_FILE = 'path';

    private string $baseDir;
    private Filesystem $fs;

    public function __construct(
        string $rootDir,
        private ClockInterface $clock,
        private int $maxPerFile = 25,
    ) {
        $this->baseDir = $rootDir.'/.porta/history';
        $this->fs = new Filesystem();
    }

    /**
     * Record a snapshot of $content for $path. Returns the number of pruned
     * old snapshots (>= 0), or -1 when the snapshot could not be written.
     */
    public function snapshot(string $path, string $content): int
    {
        $dir = $this->dirFor($path);
        try {
            $this->fs->mkdir($dir);
        } catch (IOExceptionInterface) {
            return -1;
        }

        // Skip if identical to the newest snapshot (no-op saves are common).
        // The size check first: a Ctrl+S is synchronous on the loop, and most
        // non-identical saves differ in length — no need to read the old
        // snapshot back just to learn that.
        $existing = $this->list($path);
        $newest = $existing[0]['file'] ?? null;
        if (null !== $newest && is_file($newest) && filesize($newest) === \strlen($content) && file_get_contents($newest) === $content) {
            return 0;
        }

        $stamp = (int) $this->clock->now()->format('Uv');
        $file = $dir.'/'.$stamp.'.snap';
        try {
            $this->fs->dumpFile($file, $content);
            // AFTER the snapshot, and in its try: the directory name is a HASH
            // of the path and hashes do not run backwards, so without this
            // sidecar nothing can say which file a snapshot belongs to — which
            // is what a project-wide view has to answer. Written on every
            // snapshot rather than only on creation, so a directory made
            // before this existed gains its name the next time the file is
            // saved and the format heals itself instead of needing a
            // migration. Naming a directory that has no snapshot in it would
            // be the wrong order.
            $this->fs->dumpFile($dir.'/'.self::PATH_FILE, $path);
        } catch (IOExceptionInterface) {
            return -1;
        }

        // Prune from the list already in hand plus the file just written —
        // re-scanning the directory told us nothing this math cannot. A save
        // landing on an existing stamp (same clock millisecond) overwrote it,
        // so that entry is replaced, not doubled.
        $entries = array_values(array_filter($existing, static fn (array $e): bool => $e['stamp'] !== $stamp));
        $entries[] = ['stamp' => $stamp, 'file' => $file];
        usort($entries, static fn (array $a, array $b) => $b['stamp'] <=> $a['stamp']);

        return $this->prune($entries);
    }

    /**
     * Every file this store holds history for, and when it last changed —
     * newest first.
     *
     * A directory written before the path sidecar existed cannot be named, so
     * it is skipped rather than listed as a hash: a row nobody can act on is
     * worse than a row that is not there, and the next save on that file adds
     * it back.
     *
     * @return list<array{path: string, stamp: int, count: int}>
     */
    public function recent(): array
    {
        $names = is_dir($this->baseDir) ? scandir($this->baseDir) : false;
        $out = [];
        foreach (\is_array($names) ? $names : [] as $name) {
            $pathFile = $this->baseDir.'/'.$name.'/'.self::PATH_FILE;
            $path = is_file($pathFile) ? (string) file_get_contents($pathFile) : '';
            $entries = '' === $path ? [] : $this->list($path);
            if ([] === $entries) {
                continue;
            }
            $out[] = ['path' => $path, 'stamp' => $entries[0]['stamp'], 'count' => \count($entries)];
        }
        usort($out, static fn (array $a, array $b) => $b['stamp'] <=> $a['stamp']);

        return $out;
    }

    /**
     * Snapshots for $path, newest first.
     *
     * @return list<array{stamp: int, file: string}>
     */
    public function list(string $path): array
    {
        $dir = $this->dirFor($path);
        $entries = [];
        $names = is_dir($dir) ? scandir($dir) : false;
        foreach (\is_array($names) ? $names : [] as $name) {
            $m = [];
            if (preg_match('/^(\d+)\.snap$/', $name, $m)) {
                $entries[] = ['stamp' => (int) $m[1], 'file' => $dir.'/'.$name];
            }
        }
        usort($entries, static fn (array $a, array $b) => $b['stamp'] <=> $a['stamp']);

        return $entries;
    }

    /**
     * Move a file's snapshots to the name it now has.
     *
     * Without this a rename orphans a file's entire history — at the moment you
     * are most likely to want it — because the directory is keyed by a hash of
     * the path and nothing ever looks under the old one again.
     *
     * A rename ONTO a path that already has history keeps both sets: the
     * overwritten file's snapshots are the last copy of what it held, and they
     * are past states of the file at this path just as much as the incoming
     * ones. They are merged rather than replaced, and a stamp collision leaves
     * the resident snapshot alone — same millisecond, and one of them has to
     * win.
     *
     * Only a FILE rename is followed. A directory's is not reachable: the
     * layout is `md5(path)` and that does not run backwards, so there is no way
     * to enumerate the snapshot dirs belonging to files inside it.
     */
    public function rename(string $from, string $to): void
    {
        $old = $this->dirFor($from);
        if (!is_dir($old)) {
            return;
        }
        $new = $this->dirFor($to);
        try {
            if (!is_dir($new)) {
                $this->fs->rename($old, $new);
                // The sidecar travelled with the directory still naming the
                // OLD path, which is the one place a rename could leave this
                // store telling a lie about whose history it holds.
                $this->fs->dumpFile($new.'/'.self::PATH_FILE, $to);

                return;
            }
            foreach ($this->list($from) as $entry) {
                $target = $new.'/'.basename($entry['file']);
                if (!is_file($target)) {
                    $this->fs->rename($entry['file'], $target);
                }
            }
            $this->fs->dumpFile($new.'/'.self::PATH_FILE, $to);
            $this->fs->remove($old);

            // @mago-expect lint:no-empty-catch-clause -- a history that could not be moved
            // stays where it was: unreachable, but never destroyed on the way. There is
            // nothing to report to a user who did not ask for this and nothing they could do.
        } catch (IOExceptionInterface) {
        }
    }

    public function read(string $snapFile): ?string
    {
        $content = is_file($snapFile) ? file_get_contents($snapFile) : false;

        return false === $content ? null : $content;
    }

    /** @param list<array{stamp: int, file: string}> $entries newest first, the just-written snapshot included */
    private function prune(array $entries): int
    {
        // The snapshots live in the directory we just wrote to, so removal here
        // is expected to succeed (Filesystem::remove ignores absent paths).
        $stale = \array_slice($entries, $this->maxPerFile);
        $this->fs->remove(array_column($stale, 'file'));

        return \count($stale);
    }

    private function dirFor(string $path): string
    {
        $resolved = realpath($path);
        if (false === $resolved) {
            // The file is GONE — and a store whose key stops resolving the
            // moment a file is deleted cannot answer the one question it exists
            // for. `realpath()` returns false for a path with no file behind
            // it, so canonicalise the DIRECTORY (which is still there) and put
            // the name back: on a tree reached through a symlink — /var on
            // macOS, a symlinked checkout — that is the same string the file's
            // own realpath gave while it existed, so the history stays found.
            $parent = realpath(\dirname($path));
            $resolved = false === $parent ? $path : $parent.'/'.basename($path);
        }

        return $this->baseDir.'/'.substr(md5($resolved), offset: 0, length: 16);
    }
}
