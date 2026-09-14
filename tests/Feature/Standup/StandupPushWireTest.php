<?php

namespace Tests\Feature\Standup;

use App\Bridge\Standup\StandupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The FULL wire of a bridge-authored push: the exact recorded body and the exact bearer.
 *
 * ⛔ WHY THE WHOLE BODY AND NOT A FEW KEYS. The push path is shared by every intent the
 * bridge authors itself, and a refactor of that shared path is only behaviour-preserving if
 * nothing a seat receives moved. A test that checks `kind` and one payload key stays green
 * over a dropped actor, a renamed member or a leaked handler field.
 */
class StandupPushWireTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/standup-wire-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/state');
        File::put($this->dir.'/pm-token', 'tok-standup-wire-0123456789');
        chmod($this->dir.'/pm-token', 0o600);
        File::put($this->dir.'/pm.yml', "identity:\n  kanban_user_id: 5\nsubscriptions: []\nchannel:\n  url: http://127.0.0.1:8788/\n  auth:\n    token_path: {$this->dir}/pm-token\n");
        config([
            'bridge.config_dir' => $this->dir,
            'bridge.secret_dir' => $this->dir,
            'bridge.state_dir' => $this->dir.'/state',
        ]);
        Carbon::setTestNow('2026-09-14T01:02:03+00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_a_standup_push_sends_exactly_this_body_and_this_bearer(): void
    {
        Http::fake(['127.0.0.1:8788/*' => Http::response('ok', 200)]);

        $service = $this->app->make(StandupService::class);
        $service->push($service->build(), 'pm');

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $this->assertSame('http://127.0.0.1:8788/', $request->url());
            $this->assertSame('POST', $request->method());
            $this->assertSame(['Bearer tok-standup-wire-0123456789'], $request->header('Authorization'));
            $this->assertSame([
                'intent' => [
                    'kind' => 'pm_standup',
                    'subject_id' => 'standup:2026-09-14T01:02:03+00:00',
                    'provider' => 'bridge',
                    'actor' => ['id' => null, 'name' => null, 'is_known_agent' => false],
                    'summary' => 'standup: 1 seat(s), 0 board(s) with a Now lane, as of 2026-09-14T01:02:03+00:00 (delivery times, not activity)',
                    'payload' => [
                        'generated_at' => '2026-09-14T01:02:03+00:00',
                        'seats' => [['agent' => 'pm', 'unseen_inbox_intents' => 0]],
                        'boards' => [],
                    ],
                ],
            ], json_decode($request->body(), true));

            return true;
        });
    }
}
