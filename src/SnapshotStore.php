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

    /** Trees never worth hashing: nobody's local history is in one, and they hold most of the files. */
    private const array SKIP_DIRS = ['.git', 'vendor', 'node_modules', '.idea', '.phpunit.cache'];

    private string $baseDir;
    private string $rootDir;
    private Filesystem $fs;

    /**
     * @param int $scanLimit files {@see nameOrphans()} may hash before giving up.
     *                      A knob for the same reason `$maxPerFile` is one: the
     *                      scan is bounded because a project can be arbitrarily
     *                      large, and the bound is what stops a one-off backfill
     *                      from being a hang on somebody's monorepo.
     */
    public function __construct(
        string $rootDir,
        private ClockInterface $clock,
        private int $maxPerFile = 25,
        private int $scanLimit = 20_000,
    ) {
        $this->rootDir = $rootDir;
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
        // `.porta/` comes with a cloned project: a history folder that is a
        // link would have snapshots written — and its target chmod'ed — elsewhere.
        if (\is_link($this->baseDir) || \is_link(\dirname($this->baseDir))) {
            return -1;
        }
        try {
            // Owner-only: a snapshot of a 0600 `.env` is as secret as the file,
            // and lands 0644 under the umask — the folder is what keeps it in.
            // Tightened on every save, for a history made before this was so.
            $this->fs->mkdir($dir, mode: 0o700);
            $this->fs->chmod($this->baseDir, mode: 0o700);
        } catch (IOExceptionInterface) {
            return -1;
        }

        // Skip if identical to the newest snapshot (no-op saves are common).
        // The size check first: a Ctrl+S is synchronous on the loop, and most
        // non-identical saves differ in length — no need to read the old
        // snapshot back just to learn that.
        $existing = $this->list($path);
        $newest = $existing[0]['file'] ?? null;
        if (null !== $newest && \is_file($newest) && \filesize($newest) === \strlen($content) && \file_get_contents($newest) === $content) {
            return 0;
        }

        $stamp = (int) $this->clock->now()->format('Uv');
        $file = $dir.'/'.$stamp.'.snap';
        try {
            $this->fs->dumpFile($file, $content);
            // AFTER the snapshot, and in its try: the directory name is a HASH
            // of the path, so without this sidecar nothing READS back which
            // file a snapshot belongs to — which is what a project-wide view
            // has to answer. Written on every snapshot rather than only on
            // creation, so a directory made before this existed gains its name
            // the next time the file is saved; {@see nameOrphans()} recovers
            // the rest without waiting. Naming a directory that has no
            // snapshot in it would be the wrong order.
            $this->fs->dumpFile($dir.'/'.self::PATH_FILE, $path);
        } catch (IOExceptionInterface) {
            return -1;
        }

        // Prune from the list already in hand plus the file just written —
        // re-scanning the directory told us nothing this math cannot. A save
        // landing on an existing stamp (same clock millisecond) overwrote it,
        // so that entry is replaced, not doubled.
        $entries = \array_values(\array_filter($existing, static fn (array $e): bool => $e['stamp'] !== $stamp));
        $entries[] = ['stamp' => $stamp, 'file' => $file];
        \usort($entries, static fn (array $a, array $b): int => $b['stamp'] <=> $a['stamp']);

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
        $this->nameOrphans();
        $names = \is_dir($this->baseDir) ? \scandir($this->baseDir) : false;
        $out = [];
        foreach (\is_array($names) ? $names : [] as $name) {
            $pathFile = $this->baseDir.'/'.$name.'/'.self::PATH_FILE;
            $path = \is_file($pathFile) ? (string) \file_get_contents($pathFile) : '';
            $entries = '' === $path ? [] : $this->list($path);
            if ([] === $entries) {
                continue;
            }
            $out[] = ['path' => $path, 'stamp' => $entries[0]['stamp'], 'count' => \count($entries)];
        }
        \usort($out, static fn (array $a, array $b): int => $b['stamp'] <=> $a['stamp']);

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
        $names = \is_dir($dir) ? \scandir($dir) : false;
        foreach (\is_array($names) ? $names : [] as $name) {
            $m = [];
            if (\preg_match('/^(\d+)\.snap$/', $name, $m)) {
                $entries[] = ['stamp' => (int) $m[1], 'file' => $dir.'/'.$name];
            }
        }
        \usort($entries, static fn (array $a, array $b): int => $b['stamp'] <=> $a['stamp']);

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
        if (!\is_dir($old)) {
            return;
        }
        $new = $this->dirFor($to);
        try {
            if (!\is_dir($new)) {
                $this->fs->rename($old, $new);
                // The sidecar travelled with the directory still naming the
                // OLD path, which is the one place a rename could leave this
                // store telling a lie about whose history it holds.
                $this->fs->dumpFile($new.'/'.self::PATH_FILE, $to);

                return;
            }
            foreach ($this->list($from) as $entry) {
                $target = $new.'/'.\basename($entry['file']);
                if (!\is_file($target)) {
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
        $content = \is_file($snapFile) ? \file_get_contents($snapFile) : false;

        return false === $content ? null : $content;
    }

    /** @param list<array{stamp: int, file: string}> $entries newest first, the just-written snapshot included */
    private function prune(array $entries): int
    {
        // The snapshots live in the directory we just wrote to, so removal here
        // is expected to succeed (Filesystem::remove ignores absent paths).
        $stale = \array_slice($entries, $this->maxPerFile);
        $this->fs->remove(\array_column($stale, 'file'));

        return \count($stale);
    }

    /**
     * Give a name back to every history directory written before the sidecar
     * existed, by RECOGNISING its hash rather than inverting one.
     *
     * A hash does not run backwards — but this one was taken over a path in
     * this project, and a project is a list you can walk. Hashing every file
     * and looking the result up costs one directory walk and turns a store
     * that a project-wide view would show as empty into one it can read. The
     * alternative was to wait for the next save on each file, which for a file
     * nobody is editing any more never comes — and that file is precisely the
     * one whose history is worth having.
     *
     * A match writes the sidecar, so it happens once per directory rather than
     * once per call; a directory whose file has been deleted or lives outside
     * the project matches nothing and is simply skipped, as before.
     */
    private function nameOrphans(): void
    {
        $names = \is_dir($this->baseDir) ? \scandir($this->baseDir) : false;
        $wanted = [];
        foreach (\is_array($names) ? $names : [] as $name) {
            if (\str_starts_with($name, '.') || !\is_dir($this->baseDir.'/'.$name) || \is_file($this->baseDir.'/'.$name.'/'.self::PATH_FILE)) {
                continue;
            }
            // Keyed by name, as a STRING: an all-digit name would become an
            // int key, and an int is a TypeError at writeSidecar(string).
            $wanted[$name] = $name;
        }
        if ([] === $wanted || !\is_dir($this->rootDir)) {
            return;
        }

        $seen = 0;
        $walk = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($this->rootDir, \FilesystemIterator::SKIP_DOTS),
                static fn (mixed $file): bool => !$file instanceof \SplFileInfo || !$file->isDir() || !\in_array($file->getFilename(), self::SKIP_DIRS, strict: true),
            ),
            \RecursiveIteratorIterator::LEAVES_ONLY,
            \RecursiveIteratorIterator::CATCH_GET_CHILD,
        );
        /** @var \SplFileInfo $file */
        foreach ($walk as $file) {
            if (++$seen > $this->scanLimit) {
                break;
            }
            $path = $file->getPathname();
            $real = \realpath($path);
            $hash = \substr(\md5(false === $real ? $path : $real), offset: 0, length: 16);
            if (!\array_key_exists($hash, $wanted)) {
                continue;
            }
            unset($wanted[$hash]);
            $this->writeSidecar($hash, $path);
        }

        // Whatever is still unnamed gets an EMPTY sidecar, which `recent()`
        // already reads as "skip". The marker is what makes this scan happen
        // ONCE: a directory with no sidecar at all is the only thing the walk
        // looks for, and on this project the walk is 7,000 files and ~200ms —
        // a cost worth paying once and not on every invocation. The files that
        // land here are pre-sidecar directories whose file has since been
        // deleted or moved out, which nothing could name anyway, and the next
        // save on any of them writes the real name over the marker.
        foreach ($wanted as $hash) {
            $this->writeSidecar($hash, '');
        }
    }

    /** An unwritable store is the next save's problem, not this view's. */
    private function writeSidecar(string $hash, string $path): void
    {
        try {
            $this->fs->dumpFile($this->baseDir.'/'.$hash.'/'.self::PATH_FILE, $path);

            // @mago-expect lint:no-empty-catch-clause -- a name that could not be written is a directory
            // that stays unnamed, which is the state it was already in; a project-wide list is not the
            // place to report that the history directory has gone read-only.
        } catch (IOExceptionInterface) {
        }
    }

    private function dirFor(string $path): string
    {
        $resolved = \realpath($path);
        if (false === $resolved) {
            // The file is GONE — and a store whose key stops resolving the
            // moment a file is deleted cannot answer the one question it exists
            // for. `realpath()` returns false for a path with no file behind
            // it, so canonicalise the DIRECTORY (which is still there) and put
            // the name back: on a tree reached through a symlink — /var on
            // macOS, a symlinked checkout — that is the same string the file's
            // own realpath gave while it existed, so the history stays found.
            $parent = \realpath(\dirname($path));
            $resolved = false === $parent ? $path : $parent.'/'.\basename($path);
        }

        return $this->baseDir.'/'.\substr(\md5($resolved), offset: 0, length: 16);
    }
}
