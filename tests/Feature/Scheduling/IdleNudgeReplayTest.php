<?php

namespace Tests\Feature\Scheduling;

use App\Bridge\Adapters\EventDto;
use App\Bridge\Classifiers\InboxOnlyClassifier;
use App\Bridge\Dispatch\DispatchService;
use App\Bridge\Dispatch\IntentLog;
use App\Bridge\IdleNudge\IdleNudgePassRecord;
use App\Bridge\Scheduling\JobContext;
use App\Bridge\Scheduling\JobHandlerRegistry;
use App\Bridge\Scheduling\JobPassSource;
use App\Bridge\Support\AgentRegistry;
use App\Bridge\Support\ClassifierResolver;
use App\Bridge\Support\HandlerRegistry;
use App\Bridge\Support\SubscriptionRegistry;
use App\Models\AgentDispatch;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\Feature\IdleNudge\FleetSnapshotReaderTest;
use Tests\TestCase;

/**
 * A REDELIVERED or REPLAYED event re-stages its line with the ORIGINAL `received_at` as `ts`
 * and pushes it NOW (`dedupCreate` + `refresh()` reuse the stored row). Ageing pending work from
 * `ts` alone therefore reads a push made seconds ago as minutes old, and nudges an idle seat
 * before the wake that push caused can show — exactly after an outage, when seats are idle and
 * `bridge:replay` is the documented recovery. (Adopted from design review R1 on PR #720.)
 *
 * Driven through the REAL dispatcher, so the push-time stamp is the one the receiver writes.
 */
class IdleNudgeReplayTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        ClassifierResolver::flush();
        $this->dir = sys_get_temp_dir().'/idle-nudge-replay-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/state');
        File::put($this->dir.'/fleet-token', FleetSnapshotReaderTest::CANARY);
        chmod($this->dir.'/fleet-token', 0o600);
        File::put($this->dir.'/pm.yml', "subscriptions:\n  - provider: kanban\n    scopes: [5]\nclassifier:\n  class: '".InboxOnlyClassifier::class."'\nchannel:\n  url: http://127.0.0.1:8788/\n  route_intents: true\n");
        config([
            'bridge.config_dir' => $this->dir,
            'bridge.secret_dir' => $this->dir,
            'bridge.state_dir' => $this->dir.'/state',
            'bridge.idle_nudge.enabled' => true,
            'bridge.idle_nudge.base_url' => 'https://mezzanine.example',
            'bridge.idle_nudge.token_path' => $this->dir.'/fleet-token',
            'bridge.idle_nudge.install' => 'inst-a',
            'bridge.idle_nudge.timeout' => '5',
            'bridge.idle_nudge.default_after' => '1800',
        ]);
        Cache::flush();
        Http::fake(function (Request $request) {
            if (str_starts_with($request->url(), 'https://mezzanine.example/')) {
                return Http::response(FleetSnapshotReaderTest::envelope([
                    'server_time' => '2026-09-14T12:00:00.000Z',
                    'installs' => [['install_id' => 'inst-a', 'seats' => [[
                        'install_id' => 'inst-a', 'seat_id' => 'seat-pm', 'render_state' => 'idle',
                        'protocol_agent_name' => 'pm', 'protocol_agent_name_check' => 'checked', 'retired' => null,
                        'derivation' => ['fold_lag_ms' => 0], 'idle_since' => '2026-09-14T11:00:00.000Z', 'idle_nudge_after_s' => 600,
                    ]]]],
                ]), 200);
            }
            if (str_starts_with($request->url(), 'http://127.0.0.1:87')) {
                return Http::response('ok', 202);
            }

            return null;
        });
    }

    protected function tearDown(): void
    {
        ClassifierResolver::flush();
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /** A stored event whose first delivery was received $ageS seconds ago and never finished. */
    private function storedEvent(int $ageS): WebhookEvent
    {
        $event = WebhookEvent::create(['delivery_id' => 'evt-old', 'provider' => 'kanban', 'scope_id' => '5', 'event_type' => 'task.created', 'actor_id' => '999', 'payload' => []]);
        $event->received_at = now()->subSeconds($ageS);   // NOT fillable — set post-create
        $event->save();

        return $event;
    }

    /** The redelivery / `bridge:replay`: the same stored row, pushed by route_intents NOW. */
    private function redeliver(): void
    {
        $subs = new SubscriptionRegistry($this->dir);
        (new DispatchService($subs, AgentRegistry::fromAgentConfigs($subs->agentConfigs(), AgentRegistry::loadSharedIdentities($this->dir)), new HandlerRegistry, new IntentLog))
            ->dispatch('kanban', '5', new EventDto(deliveryId: 'evt-old', scopeId: '5', eventType: 'task.created', actorId: '999'), ['subject_id' => 42, 'board_id' => 5, 'payload' => ['name' => 'Ship it']]);
    }

    private function pass(): void
    {
        $handler = $this->app->make(JobHandlerRegistry::class)->resolve('idle_nudge');
        $this->assertNotNull($handler);
        $handler->run(new JobContext('idle-nudge', [], null, 300, JobPassSource::Tick));
    }

    /** @return list<string|null> */
    private function pushedKinds(): array
    {
        $kinds = array_map(
            fn (array $pair) => json_decode($pair[0]->body(), true)['intent']['kind'] ?? null,
            Http::recorded(fn (Request $r): bool => str_starts_with($r->url(), 'http://127.0.0.1:87'))->all(),
        );

        return array_values($kinds);
    }

    public function test_a_redelivered_event_is_not_nudged_inside_the_wake_grace_of_its_own_push(): void
    {
        $this->storedEvent(600);
        $this->redeliver();

        $this->pass();

        $this->assertSame(['new_card'], $this->pushedKinds(), 'a nudge was sent within the wake grace of the push that carried the intent');
        // MEASURED, not merely silent: a dispatcher that stopped stamping would also send no
        // nudge (`push_time_unreadable`), and this test would pass for the wrong reason.
        $this->assertSame('nothing_pending', IdleNudgePassRecord::read()['agents']['pm']);
        $this->assertNotNull(AgentDispatch::query()->value('push_attempted_at'));
    }

    public function test_control_a_fresh_event_is_not_nudged(): void
    {
        $this->storedEvent(0);
        $this->redeliver();

        $this->pass();

        $this->assertSame(['new_card'], $this->pushedKinds());
    }

    public function test_witness_a_push_older_than_the_grace_is_still_nudged(): void
    {
        // Without this, a "fix" that stopped nudging altogether would pass both tests above.
        $this->storedEvent(600);
        $this->redeliver();
        AgentDispatch::query()->update(['push_attempted_at' => now()->subSeconds(600)]);

        $this->pass();

        $this->assertSame(['new_card', 'seat_idle_nudge'], $this->pushedKinds());
    }

    public function test_a_line_whose_push_time_cannot_be_read_makes_the_agent_unmeasured(): void
    {
        $this->storedEvent(600);
        $this->redeliver();
        AgentDispatch::query()->update(['push_attempted_at' => null]);

        $this->pass();

        $this->assertSame(['new_card'], $this->pushedKinds());
        $this->assertSame('push_time_unreadable', IdleNudgePassRecord::read()['agents']['pm']);
    }
}
