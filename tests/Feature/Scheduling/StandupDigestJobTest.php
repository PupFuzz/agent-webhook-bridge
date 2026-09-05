<?php

namespace Tests\Feature\Scheduling;

use App\Bridge\Scheduling\JobHandlerRegistry;
use App\Bridge\Scheduling\JobPassSource;
use App\Bridge\Scheduling\JobRegistry;
use App\Bridge\Scheduling\JobScheduler;
use App\Bridge\Scheduling\JobSpec;
use App\Bridge\Standup\StandupGate;
use App\Models\ScheduledJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The one handler the registry ships with (card#8425 / DL-325), and the property that makes
 * shipping it safe: TWO INGRESSES, ONE DIGEST.
 *
 * ⭐ WHY THIS HANDLER AND NOT A DEMO. DL-306 recorded the event gate's cost against itself —
 * *"the pass fires on the first inbound webhook AFTER the interval lapses, so an install
 * receiving nothing pushes nothing"*. The digest is therefore the one shipped subsystem with
 * a documented dead end that only a clock can close, which makes it the honest first
 * instance rather than a placeholder.
 *
 * ⛔ THE HAZARD IT WOULD HAVE INTRODUCED, pinned here: a handler that re-implemented the
 * pass instead of calling {@see StandupGate::runPass()} would push a SECOND digest at
 * somebody's live session every time both ingresses fired inside one interval. The shared
 * interval marker is what makes that impossible, and it is only shared because there is one
 * pass.
 */
class StandupDigestJobTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/standup-job-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/state');
        File::put($this->dir.'/pm.yml', "identity:\n  kanban_user_id: 5\nsubscriptions: []\nchannel:\n  url: http://127.0.0.1:8788/\n");
        config([
            'bridge.config_dir' => $this->dir,
            'bridge.secret_dir' => $this->dir,
            'bridge.state_dir' => $this->dir.'/state',
            'bridge.standup.enabled' => true,
            'bridge.standup.agent' => 'pm',
            'bridge.standup.interval' => 86400,
            'bridge.jobs.enabled' => true,
            'bridge.jobs.min_pass_interval' => 60,
        ]);
        Cache::flush();

        $this->app->make(JobRegistry::class)->insert(new JobSpec(
            name: 'standup',
            handler: 'standup_digest',
            intervalS: 600,
            owner: 'pm',
            docsRef: 'docs/periodic-jobs.md',
            justification: 'DL-306: a silent install has no arrival to gate the digest on, so only a clock can fire it',
        ));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /**
     * ⛔ Http::fake() STACKS and the FIRST matching stub wins, so this must be called once
     * per test rather than from setUp — a second fake() in a test would be shadowed by an
     * earlier one and the test would silently assert against the wrong response.
     */
    private function fakeChannel(int $status): void
    {
        Http::fake(['127.0.0.1:8788/*' => Http::response($status === 200 ? 'ok' : 'down', $status)]);
    }

    private function scheduler(): JobScheduler
    {
        return $this->app->make(JobScheduler::class);
    }

    public function test_the_shipped_handler_is_registered_and_needs_no_arming(): void
    {
        $registry = $this->app->make(JobHandlerRegistry::class);

        $this->assertContains('standup_digest', $registry->known());
        $this->assertNull($registry->refusalFor('standup_digest'), 'a read-and-alert handler is runnable as soon as it exists');
    }

    public function test_a_ticked_pass_pushes_the_digest_on_an_install_with_no_traffic(): void
    {
        $this->fakeChannel(200);
        $this->scheduler()->pass(JobPassSource::Tick);

        Http::assertSentCount(1);
        $this->assertSame(ScheduledJob::STATUS_OK, ScheduledJob::query()->where('name', 'standup')->value('last_status'));
    }

    /**
     * ⭐ THE NO-DOUBLE-PUSH PROPERTY. Both ingresses ask inside one digest interval; exactly
     * one push leaves the install.
     */
    public function test_asking_from_both_ingresses_inside_one_interval_pushes_once(): void
    {
        $this->fakeChannel(200);
        $this->scheduler()->pass(JobPassSource::Tick);

        // A later pass, past the scheduler's own minimum but well inside the digest's
        // interval — which is the ordinary shape: ask every 10 minutes, push daily.
        Cache::forget('bridge:jobs:last-pass');
        $this->travel(11)->minutes();
        $this->scheduler()->pass(JobPassSource::EventGate);

        Http::assertSentCount(1);
    }

    public function test_the_handler_reports_an_off_digest_rather_than_failing_or_going_quiet(): void
    {
        $this->fakeChannel(200);
        config(['bridge.standup.enabled' => false]);

        $this->scheduler()->pass(JobPassSource::Tick);

        Http::assertNothingSent();
        $row = ScheduledJob::query()->where('name', 'standup')->firstOrFail();
        $this->assertSame(ScheduledJob::STATUS_OK, $row->last_status);
        $this->assertStringContainsString('digest is OFF for this install', (string) $row->last_summary);
    }

    public function test_a_failing_push_is_recorded_on_the_instance_and_never_escapes(): void
    {
        $this->fakeChannel(503);

        $result = $this->scheduler()->passSafely(JobPassSource::Tick);

        $this->assertSame(1, $result->failed);
        $row = ScheduledJob::query()->where('name', 'standup')->firstOrFail();
        $this->assertSame(ScheduledJob::STATUS_FAILED, $row->last_status);
        $this->assertSame(1, (int) $row->consecutive_failures);
    }
}
