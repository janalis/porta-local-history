<?php

declare(strict_types=1);

namespace Porta\LocalHistory;

use Porta\Support\AsyncProcess;
use Symfony\Component\Process\ExecutableFinder;

/**
 * What changed between a snapshot and the file as it stands.
 *
 * `git diff --no-index` does the work. That is not a shortcut around writing a
 * diff — it is the same source the git view already reads: nothing in Porta
 * computes a diff, it parses one the binary produced. `--no-index` is the form
 * that takes two arbitrary paths, so it works on a project that is not a
 * repository at all, which is exactly the project local history is for.
 *
 * Git is therefore a soft dependency: without it, snapshots still record and
 * still restore, and only the comparison is unavailable — said out loud rather
 * than shown as an empty panel.
 */
final readonly class SnapshotDiff
{
    /**
     * Long enough for a big file, short enough that a wedged binary does not
     * leave the panel spinning. The same order as the interactive tools'.
     */
    private const float TIMEOUT = 10.0;

    public function __construct(
        private string $cwd,
        private ?string $binary = null,
    ) {}

    /** The git binary, or null when there is none on PATH. */
    public function binary(): ?string
    {
        return $this->binary ?? new ExecutableFinder()->find('git');
    }

    /**
     * Diff $snapshot against $current, oldest on the left — the direction
     * PhpStorm shows, so a `-` line is what restoring would bring back and a
     * `+` line is what it would take away.
     *
     * @param \Closure(?list<string>): void $onDone the diff's body lines, header dropped; empty when the two
     *                                         match, null when git could not compare them
     */
    public function run(string $snapshot, string $current, \Closure $onDone): bool
    {
        $binary = $this->binary();
        if (null === $binary) {
            return false;
        }

        // Two files' bytes, and nothing of the repository they sit in: inside
        // one, git still reads its config and `.git/info/attributes`, and runs
        // the filter, textconv and external diff they name — the project's
        // own programs, on opening a panel. A GIT_DIR that is no repository
        // keeps it out; the flags keep the user's own drivers from rewriting
        // what is compared, and the output from being anything but a diff.
        return new AsyncProcess(
            // --no-color: a global `color.ui = always` still reaches here, and
            // a coloured `@@` is no hunk header to the scan below.
            [$binary, 'diff', '--no-index', '--no-color', '--no-ext-diff', '--no-textconv', '--unified=3', '--', $snapshot, $current],
            $this->cwd,
            timeout: self::TIMEOUT,
            env: [...\getenv(), 'GIT_DIR' => '/nonexistent'],
        )->start(static function (int $code, string $stdout) use ($onDone): void {
            // Exit 1 means "they differ", which is the interesting case; 0
            // means identical. Anything else is a real failure — said as one,
            // not as an empty diff the caller took for "they match". So is an
            // exit 1 that printed nothing: git exits 1 for a file it cannot
            // read too, its complaint on stderr, and a real difference always
            // prints something.
            $body = 0 === $code || 1 === $code ? self::body($stdout) : null;
            $onDone(1 === $code && [] === $body ? null : $body);
        });
    }

    /**
     * The hunks, without git's five header lines.
     *
     * They name the two files, and here those are a snapshot under
     * `.porta/history/<hash>/` and the working file — a pair of paths that
     * says nothing the panel's own title does not already say.
     *
     * @return list<string>
     */
    private static function body(string $stdout): array
    {
        $body = [];
        $started = false;
        // Every body line carries a marker column (' ', '-', '+', '\\'), so
        // there are no blanks to skip once the first hunk header has landed.
        $binary = false;
        foreach (\explode("\n", \rtrim($stdout, characters: "\n")) as $line) {
            $started = $started || \str_starts_with($line, '@@');
            $binary = $binary || 1 === \preg_match('/^Binary files .* differ$/', $line);
            if ($started) {
                $body[] = $line;
            }
        }
        // No hunk to show, and yet a difference: not "matches the file on disk".
        // The line without its two paths, which name a snapshot's own file.
        if ([] === $body && $binary) {
            $body[] = 'Binary files differ';
        }

        return $body;
    }
}
