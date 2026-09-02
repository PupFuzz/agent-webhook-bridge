<?php

namespace Tests\Feature\Scheduling;

use App\Bridge\Scheduling\JobHandlerRegistry;
use App\Bridge\Scheduling\JobRegistry;
use App\Bridge\Scheduling\JobSpec;
use App\Bridge\Scheduling\TickRecord;
use App\Models\ScheduledJob;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Fixtures\RecordingJobHandler;
use Tests\TestCase;

/**
 * ⭐ THE LOAD-BEARING TEST OF card#8425 / DL-325: the registry runs from EITHER ingress, and
 * both directions are exercised end-to-end rather than argued.
 *
 * The claim this class exists to make falsifiable is the one the whole design rests on:
 *
 *  1. **A NO-CRON INSTALL STILL RUNS ITS JOBS.** An install that adds no crontab line runs
 *     the registry off the inbound webhook's after-response gate — exactly DL-199's shape —
 *     so adopting the tick is opt-in and never a dependency. Driven here through the REAL
 *     receiver: a signed delivery over HTTP, not a hand-called gate.
 *  2. **A TICKED INSTALL RUNS THEM AT ZERO TRAFFIC.** `bridge:tick` with no webhook ever
 *     delivered runs the same job. That is DL-306's documented dead end — *"an install
 *     receiving nothing pushes nothing"* — and it is the only thing the tick buys.
 *
 * ⚑ EACH DIRECTION ASSERTS THE OTHER INGRESS DID NOT FIRE, via the recorded
 * `JobPassSource`. A test that only counted runs would pass if both ingresses ran on every
 * install, which is precisely the coupling the design refuses.
 */
class DualIngressTest extends TestCase
{
    use RefreshDatabase;

    private string $secretDir;

    private string $secret = 'kanban-scope-5-secret'; // gitleaks:allow — fake HMAC secret used only by these tests

    private RecordingJobHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->secretDir = sys_get_temp_dir().'/jobs-ingress-'.uniqid();
        File::ensureDirectoryExists($this->secretDir.'/kanban');
        File::put($this->secretDir.'/kanban/webhook-secret-scope-5', $this->secret);
        chmod($this->secretDir.'/kanban/webhook-secret-scope-5', 0o600);

        config([
            'bridge.secret_dir' => $this->secretDir,
            'bridge.config_dir' => $this->secretDir,
            'bridge.jobs.enabled' => true,
            'bridge.jobs.min_pass_interval' => 60,
            'bridge.jobs.max_per_pass' => 3,
            // OFF, so nothing else on the delivery path competes for the assertion.
            'bridge.retention.enabled' => false,
            'bridge.standup.enabled' => false,
        ]);
        Cache::flush();

        // Registered against the container SINGLETON — the same instance both the receiver's
        // after-response pass and the tick command resolve.
        $this->handler = new RecordingJobHandler;
        $this->app->make(JobHandlerRegistry::class)->register($this->handler);

        $this->app->make(JobRegistry::class)->insert(new JobSpec(
            name: 'ingress-probe',
            handler: 'recording_job',
            intervalS: 60,
            owner: 'suite',
            docsRef: 'docs/periodic-jobs.md',
            justification: 'this instance exists to exercise both ingresses end to end',
        ));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->secretDir);
        parent::tearDown();
    }

    private function deliverWebhook(): void
    {
        $body = (string) json_encode([
            'event' => 'task.moved',
            'board_id' => 5,
            'delivery_id' => (string) Str::uuid(),
            'user_id' => 137,
            'payload' => ['from' => 1, 'to' => 2],
        ]);

        $this->call('POST', '/webhooks/kanban?b=5', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_KANBAN_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, $this->secret),
        ], $body)->assertStatus(200);

        // Laravel's test kernel terminates the request, which is what runs the
        // after-response callback. Clearing the list afterwards models FPM, where each
        // delivery is a fresh process: without it a second delivery would re-run the first
        // one's callback too (RetentionGateTest documents the same trap).
        $prop = new \ReflectionProperty($this->app, 'terminatingCallbacks');
        $prop->setValue($this->app, []);
    }

    public function test_a_no_cron_install_runs_its_jobs_off_the_inbound_webhook(): void
    {
        $this->deliverWebhook();

        $this->assertSame(['event_gate'], $this->handler->sources(), 'the delivery must have driven exactly one pass, on the event ingress');
        $this->assertSame(ScheduledJob::STATUS_OK, ScheduledJob::query()->where('name', 'ingress-probe')->value('last_status'));
        // Nothing ticked, so nothing may claim a tick — the alarm must not be armed by traffic.
        $this->assertNull(TickRecord::lastAt());
    }

    public function test_a_ticked_install_runs_its_jobs_at_zero_traffic(): void
    {
        $this->assertSame(0, WebhookEvent::query()->count(), 'the premise: this install has received nothing');

        $this->artisan('bridge:tick')->assertExitCode(0);

        $this->assertSame(['tick'], $this->handler->sources(), 'the tick must have driven exactly one pass, with no delivery involved');
        $this->assertSame(ScheduledJob::STATUS_OK, ScheduledJob::query()->where('name', 'ingress-probe')->value('last_status'));
        $this->assertSame(0, WebhookEvent::query()->count(), 'and still nothing was received');
    }

    /**
     * ⛔ THE CONTROL FOR BOTH LEGS ABOVE. Without it, either could pass because something
     * OTHER than the ingress under test ran the job — the suite would be measuring "does a
     * pass ever happen" rather than "does THIS ingress drive it".
     */
    public function test_neither_ingress_runs_the_job_when_the_registry_is_disabled(): void
    {
        config(['bridge.jobs.enabled' => false]);

        $this->deliverWebhook();
        $this->artisan('bridge:tick')->assertExitCode(0);

        $this->assertSame([], $this->handler->sources());
        $this->assertNull(ScheduledJob::query()->where('name', 'ingress-probe')->value('last_status'));
    }

    /**
     * The two ingresses share ONE minimum-pass-interval marker, so an install running both
     * does not double-run a job. Pinned because the alternative — a marker per ingress —
     * looks equivalent and silently doubles every job's cadence on a ticked, busy install.
     */
    public function test_the_two_ingresses_share_one_pass_marker(): void
    {
        $this->deliverWebhook();
        $this->artisan('bridge:tick')->assertExitCode(0);

        $this->assertSame(['event_gate'], $this->handler->sources(), 'the tick arrived inside the minimum pass interval and must not have run a second pass');
    }
}
