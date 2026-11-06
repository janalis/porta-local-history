<?php

declare(strict_types=1);

namespace Porta\LocalHistory\Tests;

use PHPUnit\Framework\TestCase;
use Porta\LocalHistory\SnapshotDiff;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Process\Process;

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

        $this->assertNotNull($lines);
        $this->assertStringStartsWith('@@', $lines[0], 'the first line is a hunk header, not `diff --git`');
        $this->assertContains('-b', $lines, 'what restoring would bring back');
        $this->assertContains('+B', $lines, 'and what it would take away');
        $this->assertContains('+d', $lines);
        $this->assertContains(' a', $lines, 'context lines keep their marker column');
    }

    public function testIDENTICALFilesProduceNoLinesAtAll(): void
    {
        // git exits 0 and prints nothing; the caller says so rather than
        // opening an empty panel.
        $project = new TempProject(['one.txt' => "same\n", 'two.txt' => "same\n"]);
        $differ = new SnapshotDiff($project->dir);

        [$lines] = $this->diff($differ, $project->path('one.txt'), $project->path('two.txt'));

        $this->assertSame([], $lines);
    }

    /** Two different binary files have no hunk — and still differ. */
    public function testBinaryFilesThatDifferSaySo(): void
    {
        $project = new TempProject(['old.png' => "\x89PNG\x00\x01", 'new.png' => "\x89PNG\x00\x02"]);

        [$lines] = $this->diff(new SnapshotDiff($project->dir), $project->path('old.png'), $project->path('new.png'));

        $this->assertSame(['Binary files differ'], $lines);
    }

    /** The user's own `color.ui = always` reaches a `--no-index` diff: a coloured `@@` is no hunk to find. */
    public function testAColouredGlobalConfigStillReadsTheHunks(): void
    {
        $project = new TempProject(['old.txt' => "a\n", 'new.txt' => "b\n", 'gitconfig' => "[color]\n\tui = always\n"]);
        $before = \getenv('GIT_CONFIG_GLOBAL');
        \putenv('GIT_CONFIG_GLOBAL='.$project->path('gitconfig'));

        try {
            [$lines] = $this->diff(new SnapshotDiff($project->dir), $project->path('old.txt'), $project->path('new.txt'));
        } finally {
            \putenv(false === $before ? 'GIT_CONFIG_GLOBAL' : 'GIT_CONFIG_GLOBAL='.$before);
        }

        $this->assertContains('-a', $lines ?? []);
    }

    public function testGitsOWNFailureReadsAsNoDiffRatherThanAsOutput(): void
    {
        // A missing path: git exits 128 with a complaint. Whatever it says
        // belongs in neither the panel nor a row.
        $project = new TempProject(['one.txt' => "same\n"]);
        $differ = new SnapshotDiff($project->dir);

        [$lines] = $this->diff($differ, $project->path('gone.txt'), $project->path('one.txt'));

        $this->assertSame([], $lines);
    }

    /**
     * In a repository whose config names a filter and a textconv for the file
     * (`.git/info/attributes`), and an external diff: none of them runs, and
     * the body is the bytes' own diff. The control is the same diff by plain
     * git, which runs them.
     */
    public function testTheRepositoryItSitsInHasNoSay(): void
    {
        $project = new TempProject(['old.txt' => "a\n", 'new.txt' => "b\n", 'hook.sh' => "#!/bin/sh\ntouch \"\$(dirname \"\$0\")/ran-\$\$\"\ncat\n"]);
        \chmod($project->path('hook.sh'), permissions: 0o755);
        $project->initGit();
        $project->write('.git/info/attributes', "*.txt filter=x diff=x\n");
        foreach (['filter.x.clean', 'diff.x.textconv', 'diff.external'] as $key) {
            $project->git('config', $key, $project->path('hook.sh'));
        }

        // and the user's own: an external diff and a textconv in their global config
        $project->write('home/attributes', "*.txt diff=y\n");
        $project->write('home/.gitconfig', \sprintf(
            "[core]\n\tattributesFile = %s\n[diff]\n\texternal = %2\$s\n[diff \"y\"]\n\ttextconv = %2\$s\n",
            $project->path('home/attributes'),
            $project->path('hook.sh'),
        ));
        \putenv('GIT_CONFIG_GLOBAL='.$project->path('home/.gitconfig'));
        try {
            [$lines] = $this->diff(new SnapshotDiff($project->dir), $project->path('old.txt'), $project->path('new.txt'));
        } finally {
            \putenv('GIT_CONFIG_GLOBAL');
        }

        $this->assertSame([], \glob($project->path('ran-*')), 'nothing either names ran');
        $this->assertSame(['@@ -1 +1 @@', '-a', '+b'], $lines);

        new Process(['git', 'diff', '--no-index', '--', 'old.txt', 'new.txt'], $project->dir, ['PATH' => (string) \PORTA_TEST_PRISTINE_PATH])->run();
        $this->assertNotSame([], \glob($project->path('ran-*')), 'where plain git, in that repository, runs them');
    }

    public function testWithNoGitOnPATHItRefusesToStart(): void
    {
        // Snapshots still record and still restore without git; only the
        // comparison needs it, and the caller turns this false into a message.
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['one.txt' => "a\n", 'two.txt' => "b\n"]);
        $differ = new SnapshotDiff($project->dir);

        $this->assertNull($differ->binary());
        [$lines, $started] = $this->diff($differ, $project->path('one.txt'), $project->path('two.txt'));

        $this->assertFalse($started);
        $this->assertNull($lines, 'and the callback never fired');
    }

    public function testAnInjectedBinaryWinsOverThePathWalk(): void
    {
        // The constructor argument exists so a caller that already resolved
        // git does not pay for a second ExecutableFinder walk.
        $project = new TempProject([]);

        $this->assertSame('/somewhere/git', new SnapshotDiff($project->dir, '/somewhere/git')->binary());
    }
}
