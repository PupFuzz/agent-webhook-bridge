<?php

namespace Tests\Feature\Writeback;

use App\Bridge\Dispatch\DispatchService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class WritebackEchoWiringTest extends TestCase
{
    public function test_writeback_identity_is_seeded_into_global_echo_when_dispatcher_builds(): void
    {
        // DL-018/019: building DispatchService loads writeback.json and unions its
        // identity_id into bridge.global_echo_ids (preserving any existing ids),
        // so the writeback's own card_updated never re-enters as a signal.
        $dir = sys_get_temp_dir().'/wbecho-'.uniqid();
        File::ensureDirectoryExists($dir);
        File::put($dir.'/writeback.json', (string) json_encode(['identity_id' => 4242, 'mappings' => []]));
        config(['bridge.config_dir' => $dir, 'bridge.global_echo_ids' => ['100']]);

        app()->make(DispatchService::class);

        $ids = config('bridge.global_echo_ids');
        $this->assertContains('4242', $ids);   // writeback identity seeded
        $this->assertContains('100', $ids);     // existing global ids preserved

        File::deleteDirectory($dir);
    }
}
