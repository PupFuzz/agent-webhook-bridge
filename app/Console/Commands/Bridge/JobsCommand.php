<?php

namespace App\Console\Commands\Bridge;

use App\Bridge\Scheduling\JobPassSource;
use App\Bridge\Scheduling\JobRegistry;
use App\Bridge\Scheduling\JobScheduler;
use App\Bridge\Scheduling\JobSpec;
use App\Bridge\Scheduling\JobSpecException;
use App\Bridge\Scheduling\TickRecord;
use App\Models\ScheduledJob;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * THE AUDIT SURFACE for the periodic-job registry (card#8425 / DL-325), and the operator's
 * hand-entry to the same runtime insert/remove API any code path uses.
 *
 * ⭐ *"GET the job list and you have named the entire periodic population of this install"*
 * — which no sweep across N crontabs on N accounts could give. That claim is only worth
 * anything if the listing filters NOTHING, so it prints disabled instances, refused
 * instances and instances whose handler no longer exists, each said out loud.
 *
 * ⭐ IT LEADS WITH THE JUSTIFICATIONS, not with the schedule. The operator's standing rule
 * is that a periodic job is the LAST resort and an agent must look for a non-cron answer
 * first; a listing that showed only names and intervals would answer *"what runs?"* and
 * leave *"why is any of this periodic?"* to archaeology. Every row prints the sentence its
 * inserter had to write, so a population that grew for bad reasons is visible at a glance.
 *
 *   bridge:jobs                          list every instance + the tick posture
 *   bridge:jobs --json                   the same, machine-readable (session-start hooks)
 *   bridge:jobs --assert-tick            exit non-zero if a DECLARED tick is not fresh
 *   bridge:jobs add <name> --handler= --interval= --owner= --docs-ref= --justification=
 *   bridge:jobs remove|enable|disable <name>
 *   bridge:jobs run                      one pass now (an operator asking, not a clock)
 *
 * ⚑ `add` IS AN UPSERT and refuses loudly: an unknown handler, a state-mutating handler
 * this install has not armed, or a missing justification all throw rather than storing a
 * row that could never run. `App\Bridge\Scheduling\JobSpec` owns those rules — this command
 * only parses options, so a hand-added job and a programmatically-inserted one cannot
 * diverge.
 */
class JobsCommand extends BridgeCommand
{
    protected $signature = 'bridge:jobs '
        .'{action=list : list|add|remove|enable|disable|run} '
        .'{name? : the instance name, for add/remove/enable/disable} '
        .'{--handler= : the registered handler an added instance invokes} '
        .'{--interval= : seconds between passes} '
        .'{--owner= : who is answerable for this job} '
        .'{--docs-ref= : where this job is documented} '
        .'{--justification= : REQUIRED on add — one sentence on why this cannot be event-driven} '
        .'{--payload= : JSON handler input} '
        .'{--json : machine-readable output} '
        .'{--assert-tick : exit non-zero when a DECLARED tick is not fresh}';

    protected $description = 'Enumerate and edit the periodic-job registry; report tick freshness';

    public function __construct(
        private readonly JobRegistry $registry,
        private readonly JobScheduler $scheduler,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        return $this->guardDatabase($this->handleGuarded(...));
    }

    private function handleGuarded(): int
    {
        return match ((string) $this->argument('action')) {
            'list' => $this->list(),
            'add' => $this->add(),
            'remove' => $this->mutate('remove'),
            'enable' => $this->mutate('enable'),
            'disable' => $this->mutate('disable'),
            'run' => $this->runPassNow(),
            default => $this->unknownAction(),
        };
    }

    private function unknownAction(): int
    {
        $this->error("unknown action '".(string) $this->argument('action')."' — one of: list, add, remove, enable, disable, run");

        return self::FAILURE;
    }

    private function list(): int
    {
        $posture = TickRecord::posture();
        $jobs = $this->registry->all();

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'tick' => $posture->toArray(),
                'jobs' => $jobs->map(fn (ScheduledJob $j): array => $this->rowToArray($j))->all(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line($posture->summary());
            $this->newLine();

            if ($jobs->isEmpty()) {
                // NOT an error and NOT a warning. An empty registry is the correct state of
                // an install that found an event-driven answer for everything — which is
                // what this design asks an agent to try first.
                $this->line('registry: EMPTY — this install runs no periodic jobs.');
            }

            foreach ($jobs as $job) {
                $this->line($this->describe($job));
            }
        }

        // ⭐ The assertion is a FLAG on the enumeration rather than its own command: a hook
        // that asserts should be able to print what it asserted on when it fires, and two
        // commands would let the two answers be taken a minute apart.
        //
        // ⛔ THE REASON GOES TO STDERR, NEVER STDOUT, and this is not a style choice. Under
        // `--json` stdout carries ONE document that a hook parses; `$this->error()` writes
        // to stdout, so it would append a human sentence to that document and the hook
        // would fail to decode it at exactly the moment the alarm fired — the alarm
        // breaking its own consumer. Stderr is where a message that is not the answer goes
        // (the same rule `bridge:sign` follows for its diagnostics, DL-322).
        if ($this->option('assert-tick') && $posture->failsAssertion()) {
            $this->stderr()->writeln('<error>'.$posture->summary().'</error>');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * The stream a message that is NOT the answer goes to.
     *
     * ⚑ THE FALLBACK ARM IS REACHABLE, not a guard against an impossible state: a console
     * invocation gets a `ConsoleOutputInterface` with a separate error stream, and
     * `Artisan::call()` / the suite pass a plain buffer that has none. Where there is no
     * second stream there is also no document to protect, so writing to the one stream is
     * the right answer there rather than a degraded one.
     */
    private function stderr(): OutputInterface
    {
        $output = $this->output->getOutput();

        return $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }

    private function describe(ScheduledJob $job): string
    {
        $head = sprintf(
            '%s%s  handler=%s  every=%ds  owner=%s  docs=%s',
            $job->enabled ? '' : '[disabled] ',
            (string) $job->name,
            (string) $job->handler,
            (int) $job->interval_s,
            (string) $job->owner,
            (string) $job->docs_ref,
        );

        $last = $job->last_status === null
            ? '    last: never run'
            : sprintf(
                '    last: %s at %s%s',
                strtoupper((string) $job->last_status),
                $job->last_run_at?->toIso8601String() ?? '?',
                $job->last_status === ScheduledJob::STATUS_OK
                    ? ' — '.(string) $job->last_summary
                    : ' — '.(string) $job->last_error.' ('.(int) $job->consecutive_failures.' consecutive)',
            );

        // Last, and on its own line, because it is the line a reader auditing the
        // population is actually there to read.
        return $head."\n".$last."\n    why periodic: ".(string) $job->justification."\n";
    }

    /**
     * @return array<string, mixed>
     */
    private function rowToArray(ScheduledJob $job): array
    {
        return [
            'name' => (string) $job->name,
            'handler' => (string) $job->handler,
            'interval_s' => (int) $job->interval_s,
            'owner' => (string) $job->owner,
            'docs_ref' => (string) $job->docs_ref,
            'justification' => (string) $job->justification,
            'enabled' => (bool) $job->enabled,
            'last_status' => $job->last_status,
            'last_run_at' => $job->last_run_at?->toIso8601String(),
            'next_due_at' => $job->next_due_at?->toIso8601String(),
            'last_summary' => $job->last_summary,
            'last_error' => $job->last_error,
            'consecutive_failures' => (int) $job->consecutive_failures,
        ];
    }

    private function add(): int
    {
        $name = $this->argument('name');
        if (! is_string($name) || $name === '') {
            $this->error('bridge:jobs add needs a name');

            return self::FAILURE;
        }

        $payload = [];
        $rawPayload = $this->strOption('payload');
        if ($rawPayload !== null) {
            $decoded = json_decode($rawPayload, true);
            if (! is_array($decoded)) {
                $this->error('--payload must be a JSON object or array');

                return self::FAILURE;
            }
            $payload = $decoded;
        }

        try {
            $spec = new JobSpec(
                name: $name,
                handler: (string) $this->strOption('handler'),
                intervalS: (int) $this->strOption('interval'),
                owner: (string) $this->strOption('owner'),
                docsRef: (string) $this->strOption('docs-ref'),
                justification: (string) $this->strOption('justification'),
                payload: $payload,
            );
            $this->registry->insert($spec);
        } catch (JobSpecException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("declared '{$name}'.");

        return self::SUCCESS;
    }

    private function mutate(string $action): int
    {
        $name = $this->argument('name');
        if (! is_string($name) || $name === '') {
            $this->error("bridge:jobs {$action} needs a name");

            return self::FAILURE;
        }

        $found = match ($action) {
            'remove' => $this->registry->remove($name),
            'enable' => $this->registry->setEnabled($name, true),
            default => $this->registry->setEnabled($name, false),
        };

        if (! $found) {
            $this->error("no job named '{$name}' in the registry");

            return self::FAILURE;
        }

        $this->info("{$action}d '{$name}'.");

        return self::SUCCESS;
    }

    private function runPassNow(): int
    {
        $this->line($this->scheduler->passSafely(JobPassSource::Manual)->summary());

        return self::SUCCESS;
    }
}
