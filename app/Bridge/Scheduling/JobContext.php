<?php

namespace App\Bridge\Scheduling;

use App\Models\ScheduledJob;
use Illuminate\Support\Carbon;

/**
 * Everything a {@see JobHandler} is given about the instance it is running for
 * (card#8425 / DL-325).
 *
 * ⛔ IT CARRIES THE INSTANCE'S DATA, NEVER THE MODEL. A handler holding the Eloquent row
 * could save it, and the scheduler's bookkeeping (`last_status`, `next_due_at`,
 * `consecutive_failures`) would then be written by two authors with no ordering between
 * them — a handler that saved after throwing would stamp a success it did not have. One
 * writer: {@see JobScheduler}.
 */
final class JobContext
{
    public function __construct(
        /** The instance name — the same handle `bridge:jobs` prints and callers remove by. */
        public readonly string $name,
        /** @var array<mixed> Handler input as stored on the row; `[]` when the row has none. */
        public readonly array $payload,
        /** When this instance last ran, or null if it never has. */
        public readonly ?Carbon $lastRunAt,
        /** The instance's configured cadence, in seconds. */
        public readonly int $intervalS,
        /** Which ingress drove this pass. Informational — never a permission axis. */
        public readonly JobPassSource $source,
    ) {}

    public static function forJob(ScheduledJob $job, JobPassSource $source): self
    {
        return new self(
            name: $job->name,
            payload: $job->payload ?? [],
            lastRunAt: $job->last_run_at,
            intervalS: $job->interval_s,
            source: $source,
        );
    }
}
