<?php

namespace Tests\Feature\Standup;

use App\Bridge\Standup\StandupGate;
use App\Models\AgentDispatch;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\DeadBackend;
use Tests\TestCase;

/**
 * Pins the DL-306 gate's DECISIONS — when it pushes, when it refuses, and what it does
 * when the world is hostile. "It is registered" is not the property worth testing: DL-012
 * shipped a correct command that nothing ever invoked, and this gate exists because of
 * that. What matters is whether it RUNS, and whether it can be made to run when it should
 * not — a digest is a push at someone's live session, so an over-firing gate is not a
 * cosmetic defect.
 */
class StandupGateTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/standup-gate-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/state');
        File::put($this->dir.'/pm.yml', "identity:\n  kanban_user_id: 5\nsubscriptions: []\nchannel:\n  url: http://127.0.0.1:8788/\n");
        config([
            'bridge.config_dir' => $this->dir,
            'bridge.secret_dir' => $this->dir,
            'bridge.state_dir' => $this->dir.'/state',
            'bridge.standup.enabled' => true,
            'bridge.standup.agent' => 'pm',
            'bridge.standup.interval' => 86400,
        ]);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /**
     * Run ONE gate pass the way a real request does: schedule, then terminate.
     *
     * The reset is load-bearing for multi-fire tests: `Application::terminate()` runs every
     * registered terminating callback and never clears them, so a second fire() would
     * re-run the first one too. Under PHP-FPM each delivery is a fresh process, so one
     * callback per fire IS the real shape.
     */
    private function fire(): void
    {
        $this->app->make(StandupGate::class)->schedule();
        $this->app->terminate();

        $prop = new \ReflectionProperty($this->app, 'terminatingCallbacks');
        $prop->setValue($this->app, []);
    }

    private function fakeChannel(): void
    {
        Http::fake(['127.0.0.1:8788/*' => Http::response('ok', 200)]);
    }

    public function test_a_due_gate_pushes_a_digest_to_the_configured_seat(): void
    {
        $this->fakeChannel();

        $this->fire();

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->url() === 'http://127.0.0.1:8788/'
                && ($body['intent']['kind'] ?? null) === 'pm_standup'
                && is_array($body['intent']['payload']['seats'] ?? null);
        });
    }

    public function test_a_disabled_gate_pushes_nothing(): void
    {
        // Off by default is the shipped posture, so this is the arm every install that has
        // not opted in runs. It must register no callback at all, not push an empty digest.
        config(['bridge.standup.enabled' => false]);
        $this->fakeChannel();

        $this->fire();

        Http::assertNothingSent();
    }

    public function test_the_interval_marker_suppresses_a_second_pass(): void
    {
        Cache::put('bridge:standup:last-run', true, 86400);
        $this->fakeChannel();

        $this->fire();

        Http::assertNothingSent();
    }

    public function test_a_pass_arms_the_interval_so_the_next_delivery_pays_nothing(): void
    {
        $this->fakeChannel();

        $this->fire();
        Http::fake(['127.0.0.1:8788/*' => Http::response('ok', 200)]);   // reset the recorder
        $this->fire();

        $this->assertTrue(Cache::has('bridge:standup:last-run'));
        Http::assertNothingSent();
    }

    public function test_a_held_lock_makes_the_loser_skip_instead_of_queueing(): void
    {
        // A BLOCKING lock here would queue every concurrent receive behind a pass that does
        // outbound HTTP — the DL-001 latency regression, and worse than retention's because
        // this pass talks to the network. Asserting only "nothing was pushed" does not pin
        // it: a blocking lock also pushes nothing (it waits, times out, and runSafely
        // swallows it). The observable difference is the WARNING; assert on that.
        $this->fakeChannel();
        $held = Cache::lock('bridge:standup:lock', 300);
        $this->assertTrue($held->get());
        Log::spy();

        try {
            $this->fire();

            Http::assertNothingSent();
            Log::shouldNotHaveReceived('warning');
        } finally {
            $held->release();
        }
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function misconfiguredCases(): array
    {
        return [
            'no recipient' => [['bridge.standup.agent' => null], 'names no seat'],
            'blank recipient' => [['bridge.standup.agent' => '   '], 'names no seat'],
            // BRIDGE_STANDUP_AGENT=true in .env reaches config as a BOOL, exactly as the
            // retention windows do — and `(string) true` is '1', a name-shaped value that
            // would resolve a `1.yml`. A bool is plausible here because the sibling key
            // above IS one.
            'recipient is a bare true (env bool)' => [['bridge.standup.agent' => true], 'must be an agent name'],
            // The name is concatenated into a `<config_dir>/<agent>.yml` path, so a
            // traversal segment would read a YAML outside the config dir and push this
            // install's fleet snapshot at whatever channel that file names.
            'recipient escapes the config dir' => [['bridge.standup.agent' => '../other/pm'], 'is not an agent name'],
            'recipient is a dotfile' => [['bridge.standup.agent' => '.hidden'], 'is not an agent name'],
            'interval is zero' => [['bridge.standup.interval' => 0], 'positive number of seconds'],
            'interval is negative' => [['bridge.standup.interval' => -1], 'positive number of seconds'],
        ];
    }

    /**
     * @param  array<string, mixed>  $cfg
     */
    #[DataProvider('misconfiguredCases')]
    public function test_a_misconfigured_gate_pushes_nothing_and_warns(array $cfg, string $expect): void
    {
        config($cfg);
        $this->fakeChannel();
        Log::spy();

        $this->fire();

        Http::assertNothingSent();
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $m, array $c) => $m === 'standup is enabled but misconfigured; nothing pushed'
                && str_contains((string) $c['problem'], $expect))
            ->once();
    }

    public function test_a_misconfigured_gate_backs_off_instead_of_warning_per_delivery(): void
    {
        config(['bridge.standup.agent' => null]);
        Log::spy();

        $this->fire();
        $this->fire();
        $this->fire();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $m) => $m === 'standup is enabled but misconfigured; nothing pushed')
            ->once();
    }

    public function test_a_failing_push_never_escapes_the_callback(): void
    {
        // An escaping throw would surface as a fatal after the response, in the one process
        // nobody watches — and a digest is never worth failing a webhook over: a 5xx makes
        // the provider redeliver, compounding whatever broke. A dead channel server (the
        // ordinary case: the seat is not running) must be a log line, not an incident.
        Http::fake(['127.0.0.1:8788/*' => Http::response('down', 503)]);
        Log::spy();

        $this->fire();   // must not throw

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $m) => $m === 'standup pass failed')
            ->once();
    }

    public function test_a_failing_push_records_a_last_error_marker(): void
    {
        // The marker-before-push back-off means a persistently failing push retries at most
        // once per interval. Without a durable record, the seat simply stops receiving
        // digests and the only trace is one line in a log nobody tails — DL-012 rebuilt.
        Http::fake(['127.0.0.1:8788/*' => Http::response('down', 503)]);

        $this->fire();

        $marker = Cache::get(StandupGate::ERROR_KEY);
        $this->assertIsArray($marker);
        $this->assertArrayHasKey('exception', $marker);
        $this->assertArrayHasKey('error', $marker);
    }

    public function test_a_successful_pass_clears_a_stale_error_marker(): void
    {
        Cache::put(StandupGate::ERROR_KEY, ['at' => 'earlier', 'exception' => 'X', 'error' => 'stale'], 3600);
        $this->fakeChannel();

        $this->fire();

        Http::assertSentCount(1);   // it really pushed
        $this->assertFalse(Cache::has(StandupGate::ERROR_KEY));
    }

    public function test_an_interval_suppressed_pass_does_not_clear_a_standing_failure(): void
    {
        Cache::put('bridge:standup:last-run', true, 86400);
        Cache::put(StandupGate::ERROR_KEY, ['at' => 'earlier', 'exception' => 'X', 'error' => 'stuck'], 86400);
        $this->fakeChannel();

        $this->fire();

        Http::assertNothingSent();
        $this->assertTrue(Cache::has(StandupGate::ERROR_KEY));
    }

    public function test_a_pass_reports_what_it_covered(): void
    {
        // DL-012's failure was invisible: nothing said "I did nothing, forever". The counts
        // are of what the snapshot COVERED — deliberately not a "N stalled" reading, which
        // is the sentence the missing liveness signal cannot support.
        $event = WebhookEvent::create([
            'provider' => 'kanban', 'scope_id' => '5', 'delivery_id' => 'd-'.uniqid(),
            'event_type' => 'task.moved', 'payload' => ['a' => 1],
        ]);
        AgentDispatch::create([
            'webhook_event_id' => $event->id, 'agent_name' => 'pm',
            'outcome' => AgentDispatch::OUTCOME_DELIVERED, 'processed_at' => now(),
        ]);
        $this->fakeChannel();
        Log::spy();

        $this->fire();

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $m, array $c) => $m === 'standup pass' && $c['agent'] === 'pm' && $c['seats'] === 1)
            ->once();
    }

    /**
     * ⛔ THE FAULT THE ARM ITSELF COULD NOT SURVIVE (card#8425 / DL-325). Every other test
     * of "nothing escapes this callback" breaks the WORK and leaves the recorder intact.
     * With the CACHE as the fault the arm's own marker write re-raised — an unhandled fatal
     * in the FPM worker, after the response, on every delivery. `App\Bridge\Support\FaultMarker`
     * owns the order and the guards; this pins that this gate goes through it.
     */
    public function test_a_dead_cache_backend_does_not_escape_the_callback(): void
    {
        Log::spy();
        Cache::swap(new DeadBackend('cache'));

        $this->fire();   // must not throw

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $m) => $m === 'standup pass failed')
            ->once();
    }
}
