<?php

declare(strict_types=1);

namespace Porta\LocalHistory\Tests;

use PHPUnit\Framework\TestCase;
use Porta\LocalHistory\SnapshotDiff;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\TempProject;

/**
 * `git diff --no-index` against two real files. Nothing here computes a diff —
 * the same arrangement the git view has always had, except that `--no-index`
 * takes two arbitrary paths and so works on a project that is not a repository.
 */
final class SnapshotDiffTest extends TestCase
{
    use PreservesPath;
    use PumpsLoop;

    /** @return array{list<string>|null, bool} the body lines once they land, and start()'s answer */
    private function diff(SnapshotDiff $differ, string $a, string $b): array
    {
        $lines = null;
        $started = $differ->run($a, $b, static function (array $body) use (&$lines): void {
            $lines = $body;
        });
        if ($started) {
            // By reference, not an arrow fn: an arrow fn captures $lines by
            // VALUE, so the condition would test the null it saw at creation
            // and await would burn its whole budget.
            $this->await(static function () use (&$lines): bool {
                return null !== $lines;
            });
        }

        return [$lines, $started];
    }

    public function testTheBodyIsTheHUNKSWithGitsHeaderDropped(): void
    {
        // The header names two paths — a file under `.porta/history/<hash>/`
        // and the working file — which says nothing the panel's title does not.
        $project = new TempProject(['old.txt' => "a\nb\nc\n", 'new.txt' => "a\nB\nc\nd\n"]);
        $differ = new SnapshotDiff($project->dir);

        [$lines] = $this->diff($differ, $project->path('old.txt'), $project->path('new.txt'));

        self::assertNotNull($lines);
        self::assertStringStartsWith('@@', $lines[0], 'the first line is a hunk header, not `diff --git`');
        self::assertContains('-b', $lines, 'what restoring would bring back');
        self::assertContains('+B', $lines, 'and what it would take away');
        self::assertContains('+d', $lines);
        self::assertContains(' a', $lines, 'context lines keep their marker column');
    }

    public function testIDENTICALFilesProduceNoLinesAtAll(): void
    {
        // git exits 0 and prints nothing; the caller says so rather than
        // opening an empty panel.
        $project = new TempProject(['one.txt' => "same\n", 'two.txt' => "same\n"]);
        $differ = new SnapshotDiff($project->dir);

        [$lines] = $this->diff($differ, $project->path('one.txt'), $project->path('two.txt'));

        self::assertSame([], $lines);
    }

    public function testGitsOWNFailureReadsAsNoDiffRatherThanAsOutput(): void
    {
        // A missing path: git exits 128 with a complaint. Whatever it says
        // belongs in neither the panel nor a row.
        $project = new TempProject(['one.txt' => "same\n"]);
        $differ = new SnapshotDiff($project->dir);

        [$lines] = $this->diff($differ, $project->path('gone.txt'), $project->path('one.txt'));

        self::assertSame([], $lines);
    }

    public function testWithNoGitOnPATHItRefusesToStart(): void
    {
        // Snapshots still record and still restore without git; only the
        // comparison needs it, and the caller turns this false into a message.
        putenv('PATH=/nonexistent');
        $project = new TempProject(['one.txt' => "a\n", 'two.txt' => "b\n"]);
        $differ = new SnapshotDiff($project->dir);

        self::assertNull($differ->binary());
        [$lines, $started] = $this->diff($differ, $project->path('one.txt'), $project->path('two.txt'));

        self::assertFalse($started);
        self::assertNull($lines, 'and the callback never fired');
    }

    public function testAnInjectedBinaryWinsOverThePathWalk(): void
    {
        // The constructor argument exists so a caller that already resolved
        // git does not pay for a second ExecutableFinder walk.
        $project = new TempProject([]);

        self::assertSame('/somewhere/git', new SnapshotDiff($project->dir, '/somewhere/git')->binary());
    }
}
