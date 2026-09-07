<?php

declare(strict_types=1);

namespace Porta\LocalHistory\Tests;

use PHPUnit\Framework\TestCase;
use Porta\LocalHistory\SnapshotStore;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Clock\MockClock;

final class SnapshotStoreTest extends TestCase
{
    /** A deterministic clock; advance it with ->sleep() to get distinct stamps. */
    private function clock(): MockClock
    {
        return new MockClock('2020-01-01T00:00:00+00:00');
    }

    /**
     * The store can say WHICH files it holds history for, newest change first.
     *
     * It could not before: the directory name is `md5(path)` and a hash does
     * not run backwards, so a project-wide view had nothing to name its rows
     * with. Each directory carries a `path` sidecar now.
     */
    public function testRecentNamesEveryFileNewestFirst(): void
    {
        $project = new TempProject(['a.php' => "a\n", 'b.php' => "b\n"]);
        $clock = $this->clock();
        $history = new SnapshotStore($project->dir, $clock);

        $history->snapshot($project->path('a.php'), "a1\n");
        $clock->sleep(1);
        $history->snapshot($project->path('b.php'), "b1\n");
        $clock->sleep(1);
        $history->snapshot($project->path('a.php'), "a2\n");

        $recent = $history->recent();
        $this->assertSame([$project->path('a.php'), $project->path('b.php')], array_column($recent, 'path'), 'a changed last');
        $this->assertSame([2, 1], array_column($recent, 'count'), 'and it has two versions');
    }

    /**
     * A directory written before the sidecar existed is SKIPPED, not listed as
     * a hash: a row nobody can act on is worse than no row, and the next save
     * on that file adds it back — the format heals rather than migrating.
     */
    public function testAHistoryDirectoryWithNoPathSidecarIsSkipped(): void
    {
        $project = new TempProject(['a.php' => "a\n"]);
        $history = new SnapshotStore($project->dir, $this->clock());
        $history->snapshot($project->path('a.php'), "a1\n");

        $dir = \dirname((string) $history->list($project->path('a.php'))[0]['file']);
        unlink($dir.'/path');

        $this->assertSame([], $history->recent());
    }

    /**
     * A rename moves the directory, and the sidecar inside it would otherwise
     * travel still naming the OLD path — the one place a rename could leave
     * this store lying about whose history it holds.
     */
    public function testTheSidecarFollowsARename(): void
    {
        $project = new TempProject(['old.php' => "x\n"]);
        $history = new SnapshotStore($project->dir, $this->clock());
        $history->snapshot($project->path('old.php'), "x1\n");

        $history->rename($project->path('old.php'), $project->path('new.php'));

        $this->assertSame([$project->path('new.php')], array_column($history->recent(), 'path'));
    }

    public function testSnapshotAndList(): void
    {
        $project = new TempProject(['a.php' => "v1\n"]);
        $clock = $this->clock();
        $history = new SnapshotStore($project->dir, $clock);
        $path = $project->path('a.php');

        $this->assertSame(0, $history->snapshot($path, "v1\n"));
        $clock->sleep(1);
        $this->assertSame(0, $history->snapshot($path, "v2\n"));

        $list = $history->list($path);
        $this->assertCount(2, $list);
        $this->assertSame("v2\n", $history->read($list[0]['file']), 'newest first');
        $this->assertSame("v1\n", $history->read($list[1]['file']));
        $this->assertGreaterThan($list[1]['stamp'], $list[0]['stamp']);
    }

    public function testIdenticalContentIsNotDuplicated(): void
    {
        $project = new TempProject(['a.php' => 'x']);
        $history = new SnapshotStore($project->dir, $this->clock());
        $path = $project->path('a.php');

        $history->snapshot($path, 'same');
        $history->snapshot($path, 'same'); // no-op
        $this->assertCount(1, $history->list($path));
    }

    public function testSameLengthDifferentContentIsStillSnapshotted(): void
    {
        // The dedupe shortcuts on size first — but equal size must fall
        // through to the byte compare, never read as "identical".
        $project = new TempProject(['a.php' => 'x']);
        $clock = $this->clock();
        $history = new SnapshotStore($project->dir, $clock);
        $path = $project->path('a.php');

        $history->snapshot($path, 'abc');
        $clock->sleep(1);
        $history->snapshot($path, 'abd');
        $this->assertCount(2, $history->list($path), 'same length is not sameness');
    }

    public function testASaveLandingOnAnExistingStampReplacesItWithoutOverPruning(): void
    {
        // Two saves in the same clock millisecond share a stamp: the second
        // overwrites the file. The prune math (fed from the reused list, not a
        // re-scan) must count that entry once — double-counting would prune
        // the snapshot that was just written.
        $project = new TempProject(['a.php' => 'x']);
        $clock = $this->clock();
        $history = new SnapshotStore($project->dir, $clock, maxPerFile: 1);
        $path = $project->path('a.php');

        $history->snapshot($path, 'first');
        $clock->sleep(1);
        $this->assertSame(1, $history->snapshot($path, 'second'), 'the cap prunes the older stamp');

        $this->assertSame(0, $history->snapshot($path, 'third'), 'the same-stamp overwrite prunes nothing');
        $list = $history->list($path);
        $this->assertCount(1, $list);
        $this->assertSame('third', $history->read($list[0]['file']), 'the overwrite survives its own prune');
    }

    public function testPruneKeepsOnlyTheCap(): void
    {
        $project = new TempProject(['a.php' => 'x']);
        $clock = $this->clock();
        $history = new SnapshotStore($project->dir, $clock, maxPerFile: 3);
        $path = $project->path('a.php');

        for ($i = 0; $i < 6; ++$i) {
            $pruned = $history->snapshot($path, "content {$i}");
            $clock->sleep(1);
        }
        $this->assertSame(1, $pruned, 'each save past the cap prunes one');
        $list = $history->list($path);
        $this->assertCount(3, $list);
        // The three most recent survive.
        $this->assertSame('content 5', $history->read($list[0]['file']));
        $this->assertSame('content 3', $history->read($list[2]['file']));
    }

    public function testListForUnknownFileIsEmpty(): void
    {
        $project = new TempProject();
        $history = new SnapshotStore($project->dir, $this->clock());
        $this->assertSame([], $history->list($project->path('never.php')));
    }

    public function testReadMissingSnapshotReturnsNull(): void
    {
        $project = new TempProject();
        $history = new SnapshotStore($project->dir, $this->clock());
        $this->assertNull($history->read($project->path('.porta/history/x/1.snap')));
    }

    public function testSnapshotFailsWhenDirCannotBeCreated(): void
    {
        $project = new TempProject(['a.php' => 'x']);
        // Make the history base path a file so mkdir underneath fails.
        if (!is_dir($project->path('.porta'))) {
            mkdir($project->path('.porta'), permissions: 0o777, recursive: true);
        }
        file_put_contents($project->path('.porta/history'), data: 'not a dir');

        $history = new SnapshotStore($project->dir, $this->clock());
        $this->assertSame(-1, $history->snapshot($project->path('a.php'), 'x'));
    }

    public function testSnapshotFailsWhenFileCannotBeWritten(): void
    {
        $project = new TempProject(['a.php' => 'x']);
        $history = new SnapshotStore($project->dir, $this->clock());
        $path = $project->path('a.php');

        // First snapshot creates the per-file directory; make it read-only so
        // the next write fails.
        $history->snapshot($path, 'first');
        $dir = Probe::invoke($history, 'dirFor', $path);
        chmod($dir, permissions: 0o555);

        $this->assertSame(-1, $history->snapshot($path, 'second'));

        chmod($dir, permissions: 0o755); // restore so TempProject cleanup succeeds
    }

    public function testARENAMEDFileKeepsItsHistory(): void
    {
        // The snapshot directory is a hash of the path, so without this the
        // whole history is orphaned by a rename — at the moment you are most
        // likely to want it.
        $project = new TempProject(['a.php' => "v1\n"]);
        $clock = $this->clock();
        $history = new SnapshotStore($project->dir, $clock);
        $old = (string) realpath($project->path('a.php'));
        $history->snapshot($old, "v1\n");
        $clock->sleep(1);
        $history->snapshot($old, "v2\n");
        rename($old, $new = $project->path('b.php'));

        $history->rename($old, $new);

        $list = $history->list($new);
        $this->assertCount(2, $list, 'both snapshots came with the file');
        $this->assertSame("v2\n", $history->read($list[0]['file']));
        $this->assertSame([], $history->list($old), 'and none is left on the old name');
    }

    public function testRenamingONTOAPathThatHasHistoryKeepsBothSets(): void
    {
        // A server's RenameFile overwrites. The overwritten file's snapshots
        // are the last copy of what it held, and they are past states of the
        // file at this path just as much as the incoming ones.
        $project = new TempProject(['a.php' => "moved\n", 'b.php' => "resident\n"]);
        $clock = $this->clock();
        $history = new SnapshotStore($project->dir, $clock);
        $from = (string) realpath($project->path('a.php'));
        $to = (string) realpath($project->path('b.php'));
        $history->snapshot($to, "resident\n");
        $clock->sleep(1);
        $history->snapshot($from, "moved\n");
        rename($from, $to);

        $history->rename($from, $to);

        $contents = array_map($history->read(...), array_column($history->list($to), 'file'));
        $this->assertSame(["moved\n", "resident\n"], $contents, 'newest first, and neither set was dropped');
        $this->assertSame([], $history->list($from));
    }

    public function testRenamingAFileWithNoHistoryDoesNothing(): void
    {
        $project = new TempProject(['a.php' => "v1\n", 'b.php' => "v1\n"]);
        $history = new SnapshotStore($project->dir, $this->clock());
        $history->snapshot($project->path('b.php'), "v1\n");

        $history->rename($project->path('a.php'), $project->path('b.php'));

        $this->assertCount(1, $history->list($project->path('b.php')), 'the resident history was not touched');
    }

    public function testAHistoryThatCannotBeMovedIsLeftWhereItIs(): void
    {
        // Never destroyed on the way: the snapshots are the only copy of those
        // past states, so a failed move keeps them unreachable rather than
        // losing them.
        $project = new TempProject(['a.php' => 'x']);
        $history = new SnapshotStore($project->dir, $this->clock());
        $from = $project->path('a.php');
        $history->snapshot($from, 'v1');
        $base = \dirname((string) Probe::invoke($history, 'dirFor', $from));
        chmod($base, permissions: 0o555);

        $history->rename($from, $project->path('b.php'));

        $this->assertCount(1, $history->list($from), 'still under the old name, still readable');

        chmod($base, permissions: 0o755); // restore so TempProject cleanup succeeds
    }
}
