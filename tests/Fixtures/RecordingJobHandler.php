<?php

namespace Tests\Fixtures;

use App\Bridge\Scheduling\JobCapability;
use App\Bridge\Scheduling\JobContext;
use App\Bridge\Scheduling\JobHandler;
use App\Bridge\Scheduling\JobOutcome;
use RuntimeException;

/**
 * A periodic-job handler the suite can watch, drive and break (card#8425 / DL-325).
 *
 * It records every {@see JobContext} it was handed — which is what lets a test assert WHICH
 * INGRESS ran a job, the property the dual-ingress design turns on. A counter alone could
 * not tell an event-gated pass from a ticked one.
 */
final class RecordingJobHandler implements JobHandler
{
    /** @var list<JobContext> */
    public array $calls = [];

    public function __construct(
        private readonly string $name = 'recording_job',
        private readonly JobCapability $capability = JobCapability::ReadAndAlert,
        /** When set, the handler throws it instead of returning — the failure path. */
        public ?string $throwMessage = null,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function capability(): JobCapability
    {
        return $this->capability;
    }

    public function run(JobContext $ctx): JobOutcome
    {
        $this->calls[] = $ctx;

        if ($this->throwMessage !== null) {
            throw new RuntimeException($this->throwMessage);
        }

        return JobOutcome::ok('recorded call '.count($this->calls).' from '.$ctx->source->value);
    }

    /** @return list<string> the ingress each call arrived on, in order */
    public function sources(): array
    {
        return array_map(fn (JobContext $c): string => $c->source->value, $this->calls);
    }
}
