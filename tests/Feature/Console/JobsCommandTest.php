<?php

namespace Tests\Feature\Console;

use App\Bridge\Scheduling\JobHandlerRegistry;
use App\Bridge\Scheduling\TickRecord;
use App\Models\ScheduledJob;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\PendingCommand;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Tests\Fixtures\RecordingJobHandler;
use Tests\TestCase;

/**
 * The AUDIT SURFACE (card#8425 / DL-325): what `bridge:jobs` shows, refuses and asserts.
 *
 * ⭐ The claim the registry is worth having for is *"GET the job list and you have named the
 * entire periodic population of this install"* — which is only true of a listing that
 * filters nothing and that answers WHY, not only WHAT. Both halves are pinned here.
 */
class JobsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['bridge.jobs.tick_expected_every' => null]);
        $this->app->make(JobHandlerRegistry::class)->register(new RecordingJobHandler);
    }

    private function add(array $args = []): PendingCommand
    {
        return $this->artisan('bridge:jobs', array_merge([
            'action' => 'add',
            'name' => 'a-job',
            '--handler' => 'recording_job',
            '--interval' => '600',
            '--owner' => 'suite',
            '--docs-ref' => 'docs/periodic-jobs.md',
            '--justification' => 'no arrival on this install can create or gate this work',
        ], $args));
    }

    public function test_add_stores_an_instance_and_list_prints_its_justification(): void
    {
        $this->add()->assertExitCode(0);

        $this->artisan('bridge:jobs')
            ->expectsOutputToContain('why periodic: no arrival on this install can create or gate this work')
            ->assertExitCode(0);
    }

    /**
     * ⭐ The operator's anti-proliferation rule at the hand-entry door. It is a REQUIRED
     * ARGUMENT and not an approval gate: nothing queues, nobody is asked, and the refusal
     * arrives immediately with the reason.
     */
    public function test_add_refuses_an_instance_with_no_justification(): void
    {
        $this->add(['--justification' => ''])->assertExitCode(1);

        $this->assertSame(0, ScheduledJob::query()->count(), 'a refused insert must store nothing');
    }

    public function test_add_refuses_a_handler_that_does_not_exist_in_this_build(): void
    {
        $this->add(['--handler' => 'not_a_handler'])->assertExitCode(1);

        $this->assertSame(0, ScheduledJob::query()->count());
    }

    public function test_list_says_so_when_the_registry_is_empty(): void
    {
        $this->artisan('bridge:jobs')
            ->expectsOutputToContain('registry: EMPTY')
            ->assertExitCode(0);
    }

    public function test_a_disabled_instance_is_still_listed(): void
    {
        $this->add()->assertExitCode(0);
        $this->artisan('bridge:jobs', ['action' => 'disable', 'name' => 'a-job'])->assertExitCode(0);

        $this->artisan('bridge:jobs')
            ->expectsOutputToContain('[disabled] a-job')
            ->assertExitCode(0);
    }

    public function test_remove_reports_a_name_that_is_not_there(): void
    {
        $this->artisan('bridge:jobs', ['action' => 'remove', 'name' => 'ghost'])->assertExitCode(1);
    }

    public function test_an_unknown_action_is_refused_rather_than_silently_listing(): void
    {
        $this->artisan('bridge:jobs', ['action' => 'frobnicate'])->assertExitCode(1);
    }

    public function test_run_drives_one_pass_now_and_says_what_it_did(): void
    {
        $this->add()->assertExitCode(0);

        // A hand-run pass is an OPERATOR asking, and it is recorded as such — the source is
        // carried through so the log and the row can say which clock (or none) woke a job.
        $this->artisan('bridge:jobs', ['action' => 'run'])
            ->expectsOutputToContain('pass ran (manual)')
            ->assertExitCode(0);

        $this->assertSame(ScheduledJob::STATUS_OK, ScheduledJob::query()->where('name', 'a-job')->value('last_status'));
    }

    public function test_the_json_document_carries_the_tick_posture_and_every_justification(): void
    {
        $this->add()->assertExitCode(0);

        $this->artisan('bridge:jobs', ['--json' => true])->assertExitCode(0)->run();

        // Re-run capturing output through the kernel so the document itself can be parsed:
        // a hook consumes this, so its SHAPE is the contract, not a substring of it.
        $out = $this->captureJson();
        $this->assertSame('unmeasured', $out['tick']['state']);
        $this->assertFalse($out['tick']['adopted']);
        $this->assertSame('no arrival on this install can create or gate this work', $out['jobs'][0]['justification']);
        $this->assertSame('recording_job', $out['jobs'][0]['handler']);
    }

    /**
     * ⭐ THE HOOK'S CONTRACT: `--assert-tick` exits non-zero ONLY when a DECLARED tick is not
     * fresh. All three of the other states are exercised so the guard cannot be green by
     * always passing or red by always failing.
     */
    public function test_assert_tick_fails_only_for_a_declared_and_unfresh_tick(): void
    {
        // Not adopted, never seen — the ordinary no-cron install. Must NOT fail.
        $this->artisan('bridge:jobs', ['--assert-tick' => true])->assertExitCode(0);

        // Adopted, never seen — unmeasured, and loud.
        config(['bridge.jobs.tick_expected_every' => 600]);
        $this->artisan('bridge:jobs', ['--assert-tick' => true])->assertExitCode(1);

        // Adopted and fresh.
        TickRecord::stamp();
        $this->artisan('bridge:jobs', ['--assert-tick' => true])->assertExitCode(0);

        // Adopted and stale.
        $this->travel(1500)->seconds();
        $this->artisan('bridge:jobs', ['--assert-tick' => true])->assertExitCode(1);
    }

    /**
     * ⛔ THE ALARM MUST NOT BREAK ITS OWN CONSUMER. A session-start hook runs
     * `--json --assert-tick` and parses stdout; if the failure reason were written there
     * too, the document would stop decoding at exactly the moment the tick died — the one
     * run where the hook has to work.
     */
    public function test_a_failing_assertion_leaves_the_json_document_on_stdout_parseable(): void
    {
        config(['bridge.jobs.tick_expected_every' => 600]);

        $stderr = new BufferedOutput;
        $console = new class extends ConsoleOutput
        {
            public string $captured = '';

            protected function doWrite(string $message, bool $newline): void
            {
                $this->captured .= $message.($newline ? "\n" : '');
            }
        };
        $console->setErrorOutput($stderr);

        $code = $this->app->make(Kernel::class)
            ->call('bridge:jobs', ['--json' => true, '--assert-tick' => true], $console);

        $this->assertSame(1, $code, 'a declared-but-never-observed tick must fail the assertion');
        $this->assertIsArray(json_decode($console->captured, true), 'stdout must still be exactly one JSON document');
        $this->assertStringContainsString('NEVER OBSERVED', $stderr->fetch(), 'and the reason must be on stderr, not lost');
    }

    /**
     * @return array<string, mixed>
     */
    private function captureJson(): array
    {
        $buffer = new BufferedOutput;
        $this->app->make(Kernel::class)->call('bridge:jobs', ['--json' => true], $buffer);
        $decoded = json_decode($buffer->fetch(), true);
        $this->assertIsArray($decoded, 'bridge:jobs --json must emit one JSON document and nothing else');

        return $decoded;
    }
}
