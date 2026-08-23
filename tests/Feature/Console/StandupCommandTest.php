<?php

namespace Tests\Feature\Console;

use App\Models\AgentDispatch;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `bridge:standup` (DL-306) — the manual entry point to the digest, and the surface an
 * operator uses to answer the only question worth asking about a report: is every number
 * in it one the bridge actually measured?
 */
class StandupCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/standup-cmd-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/state');
        File::put($this->dir.'/pm.yml', "identity:\n  kanban_user_id: 5\nsubscriptions: []\nchannel:\n  url: http://127.0.0.1:8788/\n");
        File::put($this->dir.'/quiet.yml', "identity:\n  kanban_user_id: 6\nsubscriptions: []\n");
        config([
            'bridge.config_dir' => $this->dir,
            'bridge.secret_dir' => $this->dir,
            'bridge.state_dir' => $this->dir.'/state',
            'bridge.standup.enabled' => true,
            'bridge.standup.agent' => 'pm',
            'bridge.standup.interval' => 86400,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function deliverTo(string $agent): void
    {
        $event = WebhookEvent::create([
            'provider' => 'kanban', 'scope_id' => '5', 'delivery_id' => 'd-'.uniqid(),
            'event_type' => 'task.moved', 'payload' => ['a' => 1],
        ]);
        AgentDispatch::create([
            'webhook_event_id' => $event->id, 'agent_name' => $agent,
            'outcome' => AgentDispatch::OUTCOME_DELIVERED, 'processed_at' => now(),
        ]);
    }

    public function test_dry_run_prints_the_digest_and_pushes_nothing(): void
    {
        Http::fake(['127.0.0.1:8788/*' => Http::response('ok', 200)]);
        $this->deliverTo('pm');

        $this->artisan('bridge:standup', ['--dry-run' => true])
            ->expectsOutputToContain('"last_delivery_at"')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_the_printed_digest_omits_a_field_it_cannot_source_rather_than_defaulting_it(): void
    {
        // The operator-visible half of the load-bearing property. `quiet` has no delivered
        // dispatch, so the JSON must carry no delivery key for it — not a null, not a zero,
        // not "unknown". The witness that the row was built at all is its measured field.
        // Artisan::call, not $this->artisan(): the assertion is on the STRUCTURE of the
        // printed document (a key that must not be there), which no output-substring
        // matcher can state — and Artisan::output() is the only handle on the real stdout.
        $this->assertSame(0, Artisan::call('bridge:standup', ['--dry-run' => true]));

        $json = json_decode(Artisan::output(), true);
        $this->assertIsArray($json, 'the dry run must print a parseable digest');
        $quiet = collect($json['seats'])->firstWhere('agent', 'quiet');

        $this->assertNotNull($quiet, 'the quiet seat must appear in the digest');
        $this->assertArrayNotHasKey('last_delivery_at', $quiet);
        $this->assertSame(0, $quiet['unseen_inbox_intents']);
    }

    public function test_a_misconfigured_standup_fails_loudly_instead_of_pushing_somewhere(): void
    {
        config(['bridge.standup.agent' => null]);

        $this->artisan('bridge:standup')
            ->expectsOutputToContain('names no seat')
            ->assertExitCode(1);
    }

    public function test_dry_run_still_builds_when_the_digest_is_disabled_and_says_so(): void
    {
        // The flag governs the AUTOMATIC pass. Refusing an explicit operator invocation on
        // it would leave no way to inspect a digest before switching it on — which is
        // exactly when an operator most needs to see one.
        config(['bridge.standup.enabled' => false]);

        $this->artisan('bridge:standup', ['--dry-run' => true])
            ->expectsOutputToContain('BRIDGE_STANDUP_ENABLED is false')
            ->assertExitCode(0);
    }

    public function test_a_real_run_pushes_to_the_configured_seat(): void
    {
        Http::fake(['127.0.0.1:8788/*' => Http::response('ok', 200)]);

        $this->artisan('bridge:standup')
            ->expectsOutputToContain('pushed to pm')
            ->assertExitCode(0);

        Http::assertSent(fn ($request) => ($request->data()['intent']['kind'] ?? null) === 'pm_standup');
    }
}
