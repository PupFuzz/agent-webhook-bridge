<?php

namespace App\Bridge\Scheduling;

use App\Bridge\Scheduling\Handlers\IdleNudgeJob;
use App\Bridge\Scheduling\Handlers\OwedWriteRetryJob;
use App\Bridge\Scheduling\Handlers\OwedWriteWatchdogJob;
use App\Bridge\Scheduling\Handlers\StandupDigestJob;
use App\Bridge\Standup\StandupGate;
use App\Bridge\Support\CsvEnv;
use App\Bridge\Support\HandlerRegistry;

/**
 * The set of periodic-job handlers THIS BUILD has, and which of them this INSTALL has
 * DISARMED (card#8425 / DL-325, arming inverted by card#10918 / DL-441). The code half of the
 * governance split; {@see JobRegistry} is the data half.
 *
 * A container singleton, exactly like `App\Bridge\Support\HandlerRegistry` and for the same
 * reason: an operator registers custom handlers against the instance the scheduler
 * resolves, from a service provider (see `docs/customization.md`). "Singleton" means one
 * per PROCESS — the FPM worker running the event-gated pass and the CLI process running
 * `bridge:tick` are different processes with their own container, so a handler wired in one
 * request is not wired for the tick unless it is registered in a provider both load.
 *
 * ⭐ ARMING IS SEPARATE FROM REGISTRATION. Every handler is REGISTERED unconditionally — the
 * registry must be able to say "that handler exists but is disarmed", which is a different
 * fact from "no such handler" and takes a different remedy. Only
 * {@see JobCapability::MutatesState} handlers can be disarmed; a read-and-alert handler is
 * stopped by disabling its instance.
 *
 * ⭐ ARMED UNLESS DISARMED (DL-441). DL-325 shipped every state-mutating handler inert until
 * an operator named it; the operator reversed that (2026-09-29): new functionality ships
 * enabled, and a per-handler kill switch stays. The kill switch is
 * `BRIDGE_JOBS_DISARMED_MUTATORS`, plus `owed_write_retry`'s own
 * `BRIDGE_OWED_WRITE_RETRY_DISABLED` (card#10849 / DL-440), both read in this class and
 * nowhere else: {@see self::disarmedFromConfig()} builds the set the scheduler refuses, and
 * {@see self::disarmedBy()} names the setting to anyone explaining why a job is not running.
 */
final class JobHandlerRegistry
{
    /** @var array<string, JobHandler> */
    private array $handlers = [];

    /**
     * @param  list<string>  $disarmedMutators  handler names this install's operator has switched
     *                                          off — {@see self::disarmedFromConfig()} reads them
     */
    public function __construct(
        private readonly array $disarmedMutators,
        StandupGate $standupGate,
        HandlerRegistry $handlers,
    ) {
        $this->register(new StandupDigestJob($standupGate));
        $this->register(new IdleNudgeJob($handlers));
        $this->register(new OwedWriteRetryJob($handlers));
        $this->register(new OwedWriteWatchdogJob($handlers));
    }

    /**
     * The handler names this install has disarmed: `BRIDGE_JOBS_DISARMED_MUTATORS`, plus
     * {@see OwedWriteRetryJob::NAME} when its own kill switch is set. The `env()` reads stay in
     * config/bridge.php (larastan's noEnvCallsOutsideOfConfig).
     *
     * @return list<string>
     */
    public static function disarmedFromConfig(): array
    {
        $disarmed = self::disarmList();
        if ((bool) config('bridge.jobs.owed_write_retry_disabled') && ! in_array(OwedWriteRetryJob::NAME, $disarmed, true)) {
            $disarmed[] = OwedWriteRetryJob::NAME;
        }

        return $disarmed;
    }

    /**
     * The setting that disarms `$name` on this install, in the words an operator would type to
     * undo it — or null when nothing does. Read at call time, so a caller that decides whether
     * to declare an instance, and one that explains why a job is not running, both answer from
     * the config in force rather than from whatever the singleton was built with.
     */
    public static function disarmedBy(string $name): ?string
    {
        if ($name === OwedWriteRetryJob::NAME && (bool) config('bridge.jobs.owed_write_retry_disabled')) {
            return 'BRIDGE_OWED_WRITE_RETRY_DISABLED=true';
        }

        return in_array($name, self::disarmList(), true) ? 'BRIDGE_JOBS_DISARMED_MUTATORS' : null;
    }

    /**
     * The entries of `BRIDGE_JOBS_DISARMED_MUTATORS` that name no state-mutating handler in this
     * build — a typo, a read-and-alert handler, or one an upgrade removed. Each switches nothing
     * off while the operator believes it did, so `bridge:check` names them.
     *
     * @return list<string>
     */
    public function disarmEntriesThatNameNoMutator(): array
    {
        return array_values(array_filter(
            self::disarmList(),
            fn (string $name): bool => $this->resolve($name)?->capability() !== JobCapability::MutatesState,
        ));
    }

    /** @return list<string> */
    private static function disarmList(): array
    {
        $raw = config('bridge.jobs.disarmed_mutators');

        return is_string($raw) ? CsvEnv::parse($raw) : [];
    }

    public function register(JobHandler $handler): void
    {
        $this->handlers[$handler->name()] = $handler;
    }

    public function resolve(string $name): ?JobHandler
    {
        return $this->handlers[$name] ?? null;
    }

    /**
     * @return list<string>
     */
    public function known(): array
    {
        $names = array_keys($this->handlers);
        sort($names);

        return $names;
    }

    /**
     * Why this handler name may not be invoked here, or null when it may.
     *
     * ⭐ ONE PREDICATE, TWO CALL SITES. {@see JobRegistry::insert()} asks it so a job that
     * could never run is refused at the moment somebody tries to create it, and
     * {@see JobScheduler} asks it again before every pass so a handler that was armed at
     * insert and disarmed since is refused rather than run. A second copy of the rule in
     * the scheduler is how an install ends up refusing at one end and running at the other.
     */
    public function refusalFor(string $name): ?JobRefusal
    {
        $runnable = $this->runnable($name);

        return $runnable instanceof JobRefusal ? $runnable : null;
    }

    /**
     * The handler to invoke, or the refusal that says why nothing may be.
     *
     * ⭐ ONE CALL, NOT A CHECK-THEN-RESOLVE PAIR. A caller that asked
     * {@see self::refusalFor()} and then {@see self::resolve()} would hold a nullable it
     * had already established was not null, and would have to write a branch for a state
     * its own previous line excluded — defensive code for an unreachable case. Returning
     * the union removes the case instead of guarding it.
     */
    public function runnable(string $name): JobHandler|JobRefusal
    {
        $handler = $this->resolve($name);

        if ($handler === null) {
            return JobRefusal::unknownHandler($name, implode(', ', $this->known()));
        }

        if ($handler->capability() === JobCapability::MutatesState
            && in_array($name, $this->disarmedMutators, true)) {
            return JobRefusal::disarmedMutator($name);
        }

        return $handler;
    }
}
