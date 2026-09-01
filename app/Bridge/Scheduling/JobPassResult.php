<?php

namespace App\Bridge\Scheduling;

/**
 * What one {@see JobScheduler} pass did (card#8425 / DL-325).
 *
 * ⭐ "DID NOT RUN" IS A FIRST-CLASS ANSWER AND CARRIES ITS REASON. A pass that lost the
 * non-blocking lock, and a pass that ran and found nothing due, are different facts:
 * `bridge:tick` prints them differently, and an operator debugging "why did nothing
 * happen?" needs the difference. Returning `0 jobs` for both is the collapse that makes a
 * scheduler unfalsifiable.
 */
final class JobPassResult
{
    /** Another process held the pass lock; this caller skipped instantly (never blocked). */
    public const SKIP_LOCKED = 'another pass is already running';

    /** The shared minimum cadence has not elapsed since the last pass on either ingress. */
    public const SKIP_TOO_SOON = 'a pass ran within the minimum pass interval';

    /** The registry itself is switched off for this install. */
    public const SKIP_DISABLED = 'the job registry is disabled (BRIDGE_JOBS_ENABLED=false)';

    private function __construct(
        public readonly JobPassSource $source,
        /** Null when the pass ran; otherwise why it did not. */
        public readonly ?string $skipped,
        public readonly int $ok,
        public readonly int $failed,
        public readonly int $refused,
        /** @var list<string> instance names touched, in the order they ran */
        public readonly array $names,
    ) {}

    /**
     * @param  list<string>  $names
     */
    public static function ran(JobPassSource $source, int $ok, int $failed, int $refused, array $names): self
    {
        return new self($source, null, $ok, $failed, $refused, $names);
    }

    public static function skipped(JobPassSource $source, string $reason): self
    {
        return new self($source, $reason, 0, 0, 0, []);
    }

    public function didRun(): bool
    {
        return $this->skipped === null;
    }

    /** One line for `bridge:tick` / `bridge:jobs run`, in operator vocabulary. */
    public function summary(): string
    {
        if ($this->skipped !== null) {
            return 'no pass: '.$this->skipped;
        }

        if ($this->names === []) {
            return 'pass ran ('.$this->source->value.'): nothing due';
        }

        return sprintf(
            'pass ran (%s): %d ok, %d failed, %d refused — %s',
            $this->source->value,
            $this->ok,
            $this->failed,
            $this->refused,
            implode(', ', $this->names),
        );
    }
}
