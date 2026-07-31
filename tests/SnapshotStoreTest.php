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
}
