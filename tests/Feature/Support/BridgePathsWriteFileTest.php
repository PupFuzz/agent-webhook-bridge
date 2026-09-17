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

    public function test_write_file_atomic_replaces_the_whole_file_and_leaves_no_temp(): void
    {
        $dir = sys_get_temp_dir().'/bp-'.uniqid();
        mkdir($dir, 0o755, true);
        file_put_contents($dir.'/state.json', 'old-and-longer-than-the-new-bytes');

        BridgePaths::writeFileAtomic($dir.'/state.json', 'new');

        $this->assertSame('new', file_get_contents($dir.'/state.json'));
        $this->assertSame(['state.json'], array_values(array_diff(scandir($dir), ['.', '..'])));
        array_map('unlink', glob($dir.'/*'));
        rmdir($dir);
    }

    public function test_write_file_atomic_throws_when_the_rename_fails_and_cleans_up(): void
    {
        // The target is a non-empty DIRECTORY, so rename() over it fails: the temp file must
        // not be left behind and the failure must not read as success.
        $dir = sys_get_temp_dir().'/bp-'.uniqid();
        mkdir($dir.'/state.json/occupied', 0o755, true);

        try {
            BridgePaths::writeFileAtomic($dir.'/state.json', 'new');
            $this->fail('a failed rename must throw');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('failed to replace', $e->getMessage());
        }

        $this->assertSame(['state.json'], array_values(array_diff(scandir($dir), ['.', '..'])));
        rmdir($dir.'/state.json/occupied');
        rmdir($dir.'/state.json');
        rmdir($dir);
    }

    public function test_write_file_atomic_throws_when_the_directory_is_missing(): void
    {
        $this->expectException(\RuntimeException::class);

        BridgePaths::writeFileAtomic('/nonexistent-'.uniqid().'/state.json', 'data');
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
