<?php

namespace Tests\Feature\Console;

use App\Bridge\Scheduling\JobHandlerRegistry;
use App\Bridge\Scheduling\JobRegistry;
use App\Bridge\Scheduling\JobSpec;
use App\Console\Commands\Bridge\JobsCommand;
use App\Models\ScheduledJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

    /**
     * ⛔ THE DECLARED CARVE-OUT, and the only one. The surrogate key and the framework
     * timestamps carry no caller value, which is why the model docblock and the migration
     * comment both name them as not printed. Everything else in the table is a value some
     * caller put there, and the no-secrets rule is stated over the whole store.
     */
    private const NOT_PRINTED = ['id', 'created_at', 'updated_at'];

    /**
     * column => [the text the HUMAN listing must carry, the text the `--json` VALUE must
     * carry]. The two differ where a surface renders rather than dumps — `enabled` is a
     * `[disabled]` marker to a reader and `false` to a parser — so one needle for both would
     * be a needle for neither.
     *
     * @var array<string, array{string, string}>
     */
    private const WITNESS = [
        'name' => ['guarded-job', 'guarded-job'],
        'handler' => ['handler=standup_digest', 'standup_digest'],
        'interval_s' => ['every=1800s', '1800'],
        'owner' => ['the-suite-owner', 'the-suite-owner'],
        'docs_ref' => ['docs/periodic-jobs.md#guard', 'docs/periodic-jobs.md#guard'],
        'justification' => ['nothing that arrives here creates or gates this work', 'nothing that arrives here creates or gates this work'],
        'enabled' => ['[disabled]', 'false'],
        'payload' => ['"board_id":4242', '4242'],
        'last_run_at' => ['2026-08-30T11:22:33', '2026-08-30T11:22:33'],
        'next_due_at' => ['2026-08-30T11:52:33', '2026-08-30T11:52:33'],
        'last_status' => ['FAILED', 'failed'],
        'last_summary' => ['the board refused the digest', 'the board refused the digest'],
        'last_error' => ['the board said no', 'the board said no'],
        'last_duration_ms' => ['4321ms', '4321'],
        'consecutive_failures' => ['7 consecutive', '7'],
    ];

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

    /** Every column of the table given a value that is findable as text on both surfaces. */
    private function plantEveryColumn(): ScheduledJob
    {
        $job = (new JobRegistry($this->app->make(JobHandlerRegistry::class)))->insert(new JobSpec(
            name: 'guarded-job',
            handler: 'standup_digest',
            intervalS: 1800,
            owner: 'the-suite-owner',
            docsRef: 'docs/periodic-jobs.md#guard',
            justification: 'nothing that arrives here creates or gates this work',
            enabled: false,
            payload: ['board_id' => 4242],
        ));

        // The scheduler's own columns are deliberately not fillable, so they are written the
        // way the scheduler writes them. A FAILED row is the case that carried the defect:
        // `last_summary` holds the exception's own account on `ok` and the failure/refusal
        // reason otherwise, and the listing used to drop it on everything but `ok`.
        $job->last_run_at = Carbon::parse('2026-08-30T11:22:33+00:00');
        $job->next_due_at = Carbon::parse('2026-08-30T11:52:33+00:00');
        $job->last_status = ScheduledJob::STATUS_FAILED;
        $job->last_summary = 'the board refused the digest';
        $job->last_error = 'the board said no';
        $job->last_duration_ms = 4321;
        $job->consecutive_failures = 7;
        $job->save();

        return $job;
    }

    /**
     * ⭐ THE UNIVERSAL IS A GUARD NOW, NOT A SENTENCE SOMEBODY MAINTAINS. `ScheduledJob`'s
     * docblock, the migration comment and the changelog all assert that EVERY stored column
     * is printed by `bridge:jobs` on BOTH surfaces — and the model states the no-secrets rule
     * over the whole store *because* of it: "a column the enumeration omits is a place a
     * secret could sit unread". That hand-maintained claim was false twice (`payload` and
     * `last_duration_ms`, then `next_due_at` and a `last_summary` printed only on `ok`), and
     * each repair narrowed to the columns the last review named — which is the shape that
     * mints the third instance.
     *
     * ⛔ THE POPULATION COMES FROM THE SCHEMA, which is what makes this different from the
     * per-column assertions below it: a column ADDED to `scheduled_jobs` and printed nowhere
     * reds this test at the first leg, naming itself, instead of quietly widening the space
     * the rule is stated over.
     */
    public function test_every_stored_column_is_printed_on_both_surfaces(): void
    {
        $this->plantEveryColumn();

        $columns = array_values(array_diff(Schema::getColumnListing('scheduled_jobs'), self::NOT_PRINTED));
        $witnessed = array_keys(self::WITNESS);
        sort($columns);
        sort($witnessed);
        $this->assertSame(
            $columns,
            $witnessed,
            'a column of scheduled_jobs has no witness here: print it on BOTH surfaces and add it to WITNESS, or add it to the declared NOT_PRINTED carve-out and say so in the model docblock',
        );

        Artisan::call('bridge:jobs');
        $human = Artisan::output();

        Artisan::call('bridge:jobs', ['--json' => true]);
        $row = json_decode(Artisan::output(), true)['jobs'][0];

        foreach (self::WITNESS as $column => [$onHuman, $inJson]) {
            $this->assertStringContainsString($onHuman, $human, "'{$column}' carries no value on the HUMAN listing");
            $this->assertArrayHasKey($column, $row, "'{$column}' is missing from the --json document");
            $this->assertStringContainsString($inJson, (string) json_encode($row[$column], JSON_UNESCAPED_SLASHES), "'{$column}' carries no value under --json");
        }
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
