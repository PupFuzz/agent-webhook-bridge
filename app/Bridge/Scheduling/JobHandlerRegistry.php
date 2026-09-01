<?php

namespace App\Bridge\Scheduling;

use App\Bridge\Scheduling\Handlers\StandupDigestJob;
use App\Bridge\Standup\StandupGate;
use App\Bridge\Support\CsvEnv;

/**
 * The set of periodic-job handlers THIS BUILD has, and which of them this INSTALL has armed
 * (card#8425 / DL-325). The code half of the governance split; {@see JobRegistry} is the
 * data half.
 *
 * A container singleton, exactly like `App\Bridge\Support\HandlerRegistry` and for the same
 * reason: an operator registers custom handlers against the instance the scheduler
 * resolves, from a service provider (see `docs/customization.md`). "Singleton" means one
 * per PROCESS — the FPM worker running the event-gated pass and the CLI process running
 * `bridge:tick` are different processes with their own container, so a handler wired in one
 * request is not wired for the tick unless it is registered in a provider both load.
 *
 * ⭐ ARMING IS SEPARATE FROM REGISTRATION, and the separation is the governance. Every
 * handler is REGISTERED unconditionally — the registry must be able to say "that handler
 * exists but is not armed", which is a different fact from "no such handler" and takes a
 * different remedy. Only {@see JobCapability::MutatesState} handlers need arming; a
 * read-and-alert handler is runnable as soon as it exists, which is what makes inserting
 * instances of it free.
 */
final class JobHandlerRegistry
{
    /** @var array<string, JobHandler> */
    private array $handlers = [];

    /**
     * @param  list<string>  $armedMutators  handler names this install's operator has armed
     */
    public function __construct(
        private readonly array $armedMutators,
        StandupGate $standupGate,
    ) {
        $this->register(new StandupDigestJob($standupGate));
    }

    /**
     * Parse the operator's armed list out of the resolved config value. The `env()` read
     * stays in config/bridge.php (larastan's noEnvCallsOutsideOfConfig).
     *
     * @return list<string>
     */
    public static function armedFromConfig(): array
    {
        $raw = config('bridge.jobs.armed_mutators');

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
            && ! in_array($name, $this->armedMutators, true)) {
            return JobRefusal::unarmedMutator($name);
        }

        return $handler;
    }

    /**
     * Whether the periodic-job registry is switched on for this install. Delegates to
     * {@see JobsConfig} rather than reading the key itself — the resolved posture has one
     * home, and this is the name the gate calls it by.
     */
    public static function isEnabled(): bool
    {
        return JobsConfig::fromConfig()->enabled;
    }
}
