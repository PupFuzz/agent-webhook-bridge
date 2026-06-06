<?php

namespace Tests\Feature\Support;

use App\Bridge\Support\BridgePaths;
use Tests\TestCase;

class BridgePathsWriteFileTest extends TestCase
{
    public function test_write_file_writes_normally(): void
    {
        $path = sys_get_temp_dir().'/bp-'.uniqid().'.txt';

        BridgePaths::writeFile($path, 'hello');

        $this->assertSame('hello', file_get_contents($path));
        @unlink($path);
    }

    public function test_write_file_throws_on_a_failed_write(): void
    {
        // Parent dir doesn't exist and there's no FILE_APPEND-creates-it →
        // file_put_contents returns false → writeFile throws (#2055), so a
        // durability write can't silently drop data behind a false success.
        $this->expectException(\RuntimeException::class);

        BridgePaths::writeFile('/nonexistent-'.uniqid().'/sub/file.txt', 'data');
    }

    public function test_append_jsonl_propagates_a_write_failure(): void
    {
        // appendJsonl is the intent-staging durability primitive: a write failure
        // MUST propagate (treatment-B → 5xx → redelivery), not be swallowed into a
        // false 200. Forcing the target to be a directory makes the write fail.
        $dir = sys_get_temp_dir().'/bp-'.uniqid();
        mkdir($dir, 0o755, true);

        $this->expectException(\RuntimeException::class);

        BridgePaths::appendJsonl($dir, ['x' => 1]);   // path is a directory → write fails
    }
}
