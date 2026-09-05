<?php

namespace Tests\Feature\Scheduling;

use App\Bridge\Scheduling\JobHandlerRegistry;
use App\Bridge\Scheduling\JobPassSource;
use App\Bridge\Scheduling\JobRegistry;
use App\Bridge\Scheduling\JobScheduler;
use App\Bridge\Scheduling\JobSpec;
use App\Bridge\Standup\StandupGate;
use App\Bridge\Support\FaultMarker;
use App\Models\ScheduledJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\Fixtures\RecordingJobHandler;
use Tests\TestCase;

/**
 * ⭐ THE `JobHandler` EXTENSION POINT IS A SYSTEM BOUNDARY, AND ITS TEXT IS SCRUBBED BEFORE
 * IT IS STORED (card#8433). `docs/customization.md` and `docs/periodic-jobs.md` invite
 * operator and third-party handlers into this app; whatever such a handler throws — or
 * summarises — is persisted VERBATIM into `scheduled_jobs.last_error` / `.last_summary`, a
 * DURABLE column, and printed back by `bridge:jobs` and `bridge:check`. Validating at a
 * boundary is the case canon #6 explicitly keeps.
 *
 * ⛔ EVERY CASE ASSERTS BOTH DIRECTIONS. Absence alone is satisfied by a redactor that
 * dropped the field, which would take the operator's whole diagnosis with it and pass this
 * suite in silence.
 *
 * ⚠ WHAT THIS DOES NOT CLAIM. The scrubber matches credential SHAPES and URL COMPONENTS; a
 * handler that throws a bare, unkeyed, non-URL secret is not reachable by any reader of a
 * finished string, and no assertion here pretends otherwise. That limit is exactly why the
 * bridge's OWN interpolations redact at the source instead — see `UrlValidatorTest`.
 */
class HandlerTextRedactionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Synthetic. Never a real credential — a planted value is the only kind that may appear
     * in a transcript, and the assertions below are only meaningful against a value nothing
     * else in the suite could produce.
     */
    private const CANARY = 'CANARY8433SYNTHETICVALUE';

    private JobHandlerRegistry $handlers;

    private RecordingJobHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'bridge.jobs.enabled' => true,
            'bridge.jobs.min_pass_interval' => 60,
            'bridge.jobs.max_per_pass' => 3,
        ]);

        $this->handler = new RecordingJobHandler('boundary_job');
        $this->handlers = new JobHandlerRegistry([], $this->app->make(StandupGate::class));
        $this->handlers->register($this->handler);
    }

    private function insert(string $name = 'boundary', int $interval = 600): ScheduledJob
    {
        return (new JobRegistry($this->handlers))->insert(new JobSpec(
            name: $name,
            handler: 'boundary_job',
            intervalS: $interval,
            owner: 'suite',
            docsRef: 'docs/periodic-jobs.md',
            justification: 'nothing that arrives on this install creates or gates this work',
        ));
    }

    /** Re-open both the pass marker and the instance's own due time. */
    private function reopen(): void
    {
        Cache::forget('bridge:jobs:last-pass');
        $this->travel(11)->minutes();
    }

    public function test_a_thrown_message_is_scrubbed_before_it_reaches_the_durable_column(): void
    {
        $this->handler->throwMessage = 'push to https://ops.example/hook?k='.self::CANARY.' failed';
        $this->insert();

        (new JobScheduler($this->handlers))->pass(JobPassSource::Manual);

        $stored = (string) ScheduledJob::query()->where('name', 'boundary')->value('last_error');

        $this->assertStringNotContainsString(self::CANARY, $stored);
        $this->assertStringContainsString('[REDACTED]', $stored);
        // The PRESENCE half: the diagnosis the operator is there for survives the redaction.
        $this->assertStringContainsString('push to https://ops.example/hook', $stored);
        $this->assertStringContainsString('failed', $stored);
    }

    public function test_an_ok_summary_is_scrubbed_on_the_same_boundary(): void
    {
        // The summary is handler-composed text on the SAME durable row and the SAME listing.
        // Covering the throw and not this would leave the boundary half-validated.
        $this->handler->okSummary = 'pushed 3 digests via https://ops.example/hook?k='.self::CANARY;
        $this->insert();

        (new JobScheduler($this->handlers))->pass(JobPassSource::Manual);

        $stored = (string) ScheduledJob::query()->where('name', 'boundary')->value('last_summary');

        $this->assertStringNotContainsString(self::CANARY, $stored);
        $this->assertStringContainsString('[REDACTED]', $stored);
        $this->assertStringContainsString('pushed 3 digests', $stored);
    }

    public function test_neither_bridge_jobs_surface_prints_the_planted_value(): void
    {
        $this->handler->throwMessage = 'kanban PATCH refused: Authorization: Bearer '.self::CANARY;
        $this->insert();

        (new JobScheduler($this->handlers))->pass(JobPassSource::Manual);

        Artisan::call('bridge:jobs');
        $human = Artisan::output();
        Artisan::call('bridge:jobs', ['--json' => true]);
        $json = Artisan::output();

        foreach (['human listing' => $human, '--json document' => $json] as $surface => $output) {
            $this->assertStringNotContainsString(self::CANARY, $output, "the planted value reached the {$surface}");
            $this->assertStringContainsString('[REDACTED]', $output, "the {$surface} lost the field instead of redacting it");
            $this->assertStringContainsString('kanban PATCH refused', $output, "the {$surface} lost the operator's diagnosis");
        }
    }

    public function test_a_failure_streak_reaches_bridge_check_without_the_planted_value(): void
    {
        // `JobsPostureCheck` prints `last_error` once an instance has failed FAILURE_STREAK
        // times, so the streak is driven for real rather than written into the row: the
        // column has exactly one writer, and a hand-planted row would be asserting against
        // a value the scheduler never produced.
        $this->handler->throwMessage = 'handler gave up: token='.self::CANARY;
        $this->insert();

        for ($i = 0; $i < 3; $i++) {
            (new JobScheduler($this->handlers))->pass(JobPassSource::Manual);
            $this->reopen();
        }
        $this->assertSame(3, (int) ScheduledJob::query()->where('name', 'boundary')->value('consecutive_failures'));

        Artisan::call('bridge:check');
        $output = Artisan::output();

        $this->assertStringNotContainsString(self::CANARY, $output);
        $this->assertStringContainsString('[REDACTED]', $output);
        $this->assertStringContainsString('handler gave up', $output);
    }

    public function test_the_whole_pass_fault_marker_reaches_bridge_check_without_the_planted_value(): void
    {
        // The OTHER store an escaping throw lands in, and the one the three after-response
        // subsystems share: `FaultMarker` writes it, `JobsPostureCheck` reads it. Recorded
        // through the primitive with the production key, not by planting a cache entry —
        // planting one would assert about the check and nothing about the redaction.
        FaultMarker::record(
            JobScheduler::ERROR_KEY,
            new RuntimeException('pass aborted: GET https://svc:'.self::CANARY.'@board.example/api/v3'),
            60,
            'scheduled job pass failed',
        );

        Artisan::call('bridge:check');
        $output = Artisan::output();

        $this->assertStringNotContainsString(self::CANARY, $output);
        $this->assertStringContainsString('LAST SCHEDULER PASS FAILED', $output);
        $this->assertStringContainsString('board.example/api/v3', $output);
    }
}
