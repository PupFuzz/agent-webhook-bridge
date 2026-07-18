<?php

namespace Tests\Feature\Support;

use App\Bridge\Support\BridgePaths;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Pins `updateSeenLocked`'s load-bearing property: the exclusive lock is held across
 * the WHOLE read → transform → write of a seen-cursor, not just across the write.
 *
 * Why this shape (mirrors BridgePathsRewriteLockedTest): the bug it fixes is a RACE —
 * bridge:inbox advancing a cursor (readSeen → merge → writeSeen) while a prune sweep
 * intersects the same cursor could interleave, so the prune writes a set computed from
 * a pre-advance read and drops a just-marked id back to unseen (one re-delivered wake).
 * A race can't be pinned by timing; pin the INVARIANT that makes it impossible instead:
 * while the transform runs, nobody else can take the lock. Both callers RMW under this
 * lock, so they serialize — the loser blocks and re-reads the post-write cursor.
 */
class BridgePathsSeenLockedTest extends TestCase
{
    private string $dir;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/seen-locked-'.uniqid();
        File::ensureDirectoryExists($this->dir);
        $this->path = $this->dir.'/inbox-seen.json';
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_the_exclusive_lock_is_held_across_the_whole_update(): void
    {
        File::put($this->path, json_encode(['a', 'b']));
        $lockWasFreeMidTransform = null;

        BridgePaths::updateSeenLocked($this->path, function (array $ids) use (&$lockWasFreeMidTransform) {
            // A separate open file description contends for flock exactly as another
            // process (a concurrent prune sweep) would — a real probe, not a mock.
            $other = fopen($this->path, 'c+');
            $lockWasFreeMidTransform = flock($other, LOCK_EX | LOCK_NB);
            if ($lockWasFreeMidTransform) {
                flock($other, LOCK_UN);
            }
            fclose($other);

            return [...$ids, 'c'];
        });

        $this->assertFalse(
            $lockWasFreeMidTransform,
            'the lock must be held across the whole read-transform-write — otherwise a concurrent cursor RMW interleaves and drops a just-marked id back to unseen',
        );
    }

    public function test_the_transform_sees_the_current_on_disk_ids_and_writes_its_result(): void
    {
        File::put($this->path, json_encode(['a', 'b']));
        $seenByTransform = null;

        BridgePaths::updateSeenLocked($this->path, function (array $ids) use (&$seenByTransform) {
            $seenByTransform = $ids;

            return array_values(array_unique([...$ids, 'c']));
        });

        $this->assertSame(['a', 'b'], $seenByTransform, 'the transform must receive the ids read under the lock');
        $this->assertSame(['a', 'b', 'c'], json_decode((string) File::get($this->path), true));
    }

    public function test_a_no_change_transform_does_not_rewrite_the_file(): void
    {
        File::put($this->path, json_encode(['a', 'b']));
        // mtime distinguishes "didn't write" from "wrote the same bytes" — the actual
        // property is that a steady-state prune sweep doesn't churn an unchanged cursor.
        $past = time() - 3600;
        touch($this->path, $past);
        clearstatcache(true, $this->path);

        BridgePaths::updateSeenLocked($this->path, fn (array $ids) => $ids);

        clearstatcache(true, $this->path);
        $this->assertSame($past, filemtime($this->path), 'an unchanged transform result must not rewrite the cursor');
    }

    public function test_a_missing_cursor_file_is_created_rather_than_throwing(): void
    {
        $absent = $this->dir.'/inbox-seen-absent.json';

        BridgePaths::updateSeenLocked($absent, fn (array $ids) => [...$ids, 'x']);

        $this->assertSame(['x'], json_decode((string) File::get($absent), true));
    }

    public function test_a_garbage_cursor_reads_as_an_empty_list(): void
    {
        File::put($this->path, '{not json');
        $seenByTransform = null;

        BridgePaths::updateSeenLocked($this->path, function (array $ids) use (&$seenByTransform) {
            $seenByTransform = $ids;

            return $ids;
        });

        $this->assertSame([], $seenByTransform, 'an undecodable cursor decodes to [] — same posture as readSeen');
    }

    public function test_a_shrinking_write_leaves_no_trailing_garbage(): void
    {
        // ftruncate-before-write, not write-over: a long list replaced by a short one
        // must not leave the tail of the old content behind as corrupt JSON.
        File::put($this->path, json_encode(['aaaaaaaaaaaaaaaaaaaa', 'bbbbbbbbbbbbbbbbbbbb', 'cccccccccccccccccccc']));

        BridgePaths::updateSeenLocked($this->path, fn (array $ids) => ['x']);

        $this->assertSame(json_encode(['x']), File::get($this->path));
        $this->assertSame(['x'], json_decode((string) File::get($this->path), true));
    }
}
