<?php

namespace Tests\Feature\Console;

use App\Bridge\Scheduling\JobHandlerRegistry;
use App\Bridge\Scheduling\JobRegistry;
use App\Bridge\Scheduling\JobSpec;
use App\Console\Commands\Bridge\JobsCommand;
use App\Models\ScheduledJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Input\ArrayInput;
use Tests\Support\SplitConsoleOutput;
use Tests\TestCase;

/**
 * What `bridge:jobs` PRINTS, and on WHICH STREAM (card#8425 / DL-325).
 *
 * ⭐ THE ENUMERATION'S HEADLINE CLAIM IS *"GET the job list and you have named the entire
 * periodic population"*, and a column the listing never prints is a part of that population
 * nobody named. `payload` is the handler's INPUT — a row whose input is invisible is a job
 * whose behaviour the audit cannot see — and it is also the column the no-secrets rule is
 * stated over, in the model docblock and in the migration; that rule is only honest while
 * the value really is operator-visible. `last_duration_ms` is what turns "it ran" into "it
 * ran and held a worker for 4 seconds", which is the DL-199 bound's own instrument.
 */
class JobsCommandOutputTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function insert(array $payload = []): ScheduledJob
    {
        return (new JobRegistry($this->app->make(JobHandlerRegistry::class)))->insert(new JobSpec(
            name: 'a-job',
            handler: 'standup_digest',
            intervalS: 600,
            owner: 'suite',
            docsRef: 'docs/periodic-jobs.md',
            justification: 'there is no inbound arrival on this install that creates this work',
            payload: $payload,
        ));
    }

    /** `last_duration_ms` is the scheduler's own column, so it is written, not mass-assigned. */
    private function withLastRun(ScheduledJob $job): ScheduledJob
    {
        $job->last_status = ScheduledJob::STATUS_OK;
        $job->last_run_at = now();
        $job->last_summary = 'checked 12 threads';
        $job->last_duration_ms = 4321;
        $job->save();

        return $job;
    }

    public function test_the_human_listing_prints_the_payload_and_the_duration(): void
    {
        $this->withLastRun($this->insert(['board_id' => 12, 'window' => '7d']));

        Artisan::call('bridge:jobs');
        $out = Artisan::output();

        $this->assertStringContainsString('board_id', $out);
        $this->assertStringContainsString('4321ms', $out);
    }

    /** An empty payload prints no line rather than an empty one — it is the common row. */
    public function test_a_row_with_no_payload_prints_no_payload_line(): void
    {
        $this->insert();

        Artisan::call('bridge:jobs');

        $this->assertStringNotContainsString('payload:', Artisan::output());
    }

    public function test_the_json_document_carries_the_payload_and_the_duration(): void
    {
        $this->withLastRun($this->insert(['board_id' => 12]));

        Artisan::call('bridge:jobs', ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(['board_id' => 12], $decoded['jobs'][0]['payload']);
        $this->assertSame(4321, $decoded['jobs'][0]['last_duration_ms']);
    }

    /**
     * ⛔ UNDER `--json` STDOUT CARRIES ONE DOCUMENT, and a DB fault must not append prose to
     * it. `$this->error()` writes to STDOUT, so the fault broke its own consumer at exactly
     * the moment it fired — the hook got an undecodable document instead of an alarm. The
     * same rule `--assert-tick` already followed one method away.
     *
     * ⚑ THE INSTRUMENT HAS TO HAVE TWO STREAMS OR IT CANNOT ANSWER. `Artisan::call()` and
     * `$this->artisan()` both hand the command a plain buffer with no error stream, where
     * `stderr()` correctly falls back to the one stream — so a test run through either is
     * byte-identical before and after the fix and proves nothing. This drives the command
     * with a real `ConsoleOutputInterface` whose error half is a separate buffer.
     */
    public function test_a_db_fault_under_json_goes_to_stderr_and_leaves_stdout_empty(): void
    {
        $this->insert();
        Schema::drop('scheduled_jobs');

        $output = new SplitConsoleOutput;
        $command = $this->app->make(JobsCommand::class);
        $command->setLaravel($this->app);

        $exit = $command->run(new ArrayInput(['action' => 'list', '--json' => true]), $output);

        $this->assertSame(JobsCommand::FAILURE, $exit);
        $this->assertStringContainsString('database', $output->errors->fetch());
        $this->assertSame('', trim($output->fetch()), 'stdout stayed a parseable (empty) document');
    }

    /**
     * ⚑ THE CONTROL FOR THE TEST ABOVE. The instrument must be able to see something on
     * stdout, or "stdout was empty" is a measurement that never happened.
     */
    public function test_the_split_instrument_sees_the_document_on_stdout_when_there_is_no_fault(): void
    {
        $this->insert();

        $output = new SplitConsoleOutput;
        $command = $this->app->make(JobsCommand::class);
        $command->setLaravel($this->app);

        $exit = $command->run(new ArrayInput(['action' => 'list', '--json' => true]), $output);

        $this->assertSame(JobsCommand::SUCCESS, $exit);
        $this->assertSame('', $output->errors->fetch());
        $this->assertIsArray(json_decode($output->fetch(), true));
    }
}
