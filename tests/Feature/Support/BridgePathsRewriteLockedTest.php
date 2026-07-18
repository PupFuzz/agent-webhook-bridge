<?php

namespace Tests\Feature\Support;

use App\Bridge\Support\BridgePaths;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Pins `filterJsonlLocked`'s ONE load-bearing property: the exclusive lock is held
 * across read → transform → write, not just across the write.
 *
 * Why this shape: the bug it fixes is a RACE (an `appendJsonl` landing between an
 * unlocked read and a truncating rewrite is silently discarded — the append returns
 * OK, the receiver acks 200, the intent is gone). A race cannot be pinned by timing;
 * a timing test passes on a fast machine and lies. So pin the INVARIANT that makes
 * the race impossible instead: while the transform runs, nobody else can take the
 * lock. `appendJsonl` writes with `LOCK_EX`, so if this holds, an append cannot
 * interleave — it blocks and lands on the trimmed file.
 */
class BridgePathsRewriteLockedTest extends TestCase
{
    private string $dir;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/rewrite-locked-'.uniqid();
        File::ensureDirectoryExists($this->dir);
        $this->path = $this->dir.'/inbox.jsonl';
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function seedLines(string ...$ids): void
    {
        $body = '';
        foreach ($ids as $id) {
            $body .= json_encode(['id' => $id])."\n";
        }
        File::put($this->path, $body);
    }

    public function test_the_exclusive_lock_is_held_across_the_whole_rewrite(): void
    {
        $this->seedLines('a', 'b');
        $lockWasFreeMidTransform = null;

        BridgePaths::filterJsonlLocked($this->path, function (array $line) use (&$lockWasFreeMidTransform) {
            // A separate open file description contends for flock exactly as another
            // process would, so this is a real probe of the lock, not a mock.
            $other = fopen($this->path, 'c+');
            $lockWasFreeMidTransform = flock($other, LOCK_EX | LOCK_NB);
            if ($lockWasFreeMidTransform) {
                flock($other, LOCK_UN);
            }
            fclose($other);

            return $line['id'] !== 'a';
        });

        $this->assertFalse(
            $lockWasFreeMidTransform,
            'the lock must be held across the whole read-filter-write — otherwise a concurrent appendJsonl lands between the read and the rewrite and is truncated away (acked 200, never delivered)',
        );
    }

    public function test_it_drops_only_what_the_predicate_rejects(): void
    {
        $this->seedLines('a', 'b', 'c');

        [$before, $after] = BridgePaths::filterJsonlLocked($this->path, fn (array $l) => $l['id'] !== 'b');

        $this->assertSame([3, 2], [$before, $after]);
        $this->assertSame(['a', 'c'], array_column(BridgePaths::readJsonl($this->path), 'id'));
    }

    public function test_a_no_drop_pass_does_not_rewrite_the_file_at_all(): void
    {
        $this->seedLines('a', 'b');
        $original = File::get($this->path);
        // Comparing CONTENT can't pin this — a rewrite that keeps every line produces
        // identical bytes and the assertion passes either way (verified by mutation).
        // mtime distinguishes "didn't write" from "wrote the same thing", which is the
        // actual property: the steady-state pass must not churn a file readers tail.
        $past = time() - 3600;
        touch($this->path, $past);
        clearstatcache(true, $this->path);

        BridgePaths::filterJsonlLocked($this->path, fn (array $l) => true);

        clearstatcache(true, $this->path);
        $this->assertSame($past, filemtime($this->path), 'a pass that drops nothing must not rewrite the file');
        $this->assertSame($original, File::get($this->path));
    }

    public function test_a_shrinking_rewrite_leaves_no_trailing_garbage(): void
    {
        // ftruncate-before-write, not write-over: 3 long lines replaced by 1 short one
        // must not leave the tail of the old content behind as a corrupt final line.
        $this->seedLines('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'x', 'cccccccccccccccccccccccccccccc');

        BridgePaths::filterJsonlLocked($this->path, fn (array $l) => $l['id'] === 'x');

        $this->assertSame(json_encode(['id' => 'x'])."\n", File::get($this->path));
        $this->assertSame(['x'], array_column(BridgePaths::readJsonl($this->path), 'id'));
    }

    public function test_a_missing_file_is_created_rather_than_throwing(): void
    {
        // `c+` creates. The inbox may legitimately not exist yet on a fresh install.
        [$before, $after] = BridgePaths::filterJsonlLocked($this->dir.'/absent.jsonl', fn (array $l) => true);

        $this->assertSame([0, 0], [$before, $after]);
    }

    public function test_peak_memory_does_not_scale_with_the_file(): void
    {
        // The reason this streams. Slurping the file made peak memory track its size,
        // and this runs under PHP-FPM's memory_limit (128M on the reference install).
        // Blowing it raises an E_ERROR, which is NOT catchable by the gate's
        // `catch (\Throwable)` — the pass would die AFTER setting its 24h back-off
        // marker, so retention would go inert for a day at a time while bridge:check
        // still reported it healthy. That is DL-012's silent inertness rebuilt inside
        // its own fix, so the bound is the property, not an optimization.
        $fh = fopen($this->path, 'w');
        $fat = str_repeat('x', 4000);
        for ($i = 0; $i < 20000; $i++) {
            fwrite($fh, json_encode(['id' => "e{$i}", 'blob' => $fat])."\n");
        }
        fclose($fh);
        $fileBytes = filesize($this->path);

        $before = memory_get_usage(true);
        [$read, $kept] = BridgePaths::filterJsonlLocked($this->path, fn (array $l) => $l['id'] === 'e19999');
        $growth = memory_get_usage(true) - $before;

        $this->assertSame([20000, 1], [$read, $kept]);
        $this->assertGreaterThan(60 * 1024 * 1024, $fileBytes, 'the fixture must be big enough to matter');
        $this->assertLessThan(
            $fileBytes / 4,
            $growth,
            'peak memory must not scale with the inbox — a slurping rewrite OOMs uncatchably under FPM',
        );
    }

    public function test_a_malformed_line_is_dropped_not_fatal(): void
    {
        File::put($this->path, json_encode(['id' => 'a'])."\n{not json\n".json_encode(['id' => 'b'])."\n");

        BridgePaths::filterJsonlLocked($this->path, fn (array $l) => true);

        // Same posture as readJsonl: undecodable lines are skipped, not fatal.
        $this->assertSame(['a', 'b'], array_column(BridgePaths::readJsonl($this->path), 'id'));
    }
}
