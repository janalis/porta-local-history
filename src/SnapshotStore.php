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
        $existing = $this->list($path);
        if ([] !== $existing && (is_file($existing[0]['file']) ? file_get_contents($existing[0]['file']) : false) === $content) {
            return 0;
        }

        $stamp = (int) $this->clock->now()->format('Uv');
        try {
            $this->fs->dumpFile($dir.'/'.$stamp.'.snap', $content);
        } catch (IOExceptionInterface) {
            return -1;
        }

        return $this->prune($path);
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

    public function read(string $snapFile): ?string
    {
        $content = is_file($snapFile) ? file_get_contents($snapFile) : false;

        return false === $content ? null : $content;
    }

    private function prune(string $path): int
    {
        // The snapshots live in the directory we just wrote to, so removal here
        // is expected to succeed (Filesystem::remove ignores absent paths).
        $stale = \array_slice($this->list($path), $this->maxPerFile);
        $this->fs->remove(array_column($stale, 'file'));

        return \count($stale);
    }

    private function dirFor(string $path): string
    {
        $resolved = realpath($path);
        $real = $resolved ? $resolved : $path;

        return $this->baseDir.'/'.substr(md5($real), offset: 0, length: 16);
    }
}
