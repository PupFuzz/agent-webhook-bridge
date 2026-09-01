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
 *
 * ⛔ AND "DID NOT RUN" SPLITS IN TWO, which is why {@see self::failed()} exists beside
 * {@see self::skipped()}. An ORDINARY skip (another pass holds the lock, the cadence has not
 * elapsed, the registry is switched off) is the scheduler working as designed and happens
 * many times a minute on a busy install. A pass FAULT (the DB unreachable, the cache backend
 * gone, a config value this install cannot act on) is a broken install where nothing in the
 * registry runs at all. Collapsing them into one nullable reason string leaves every consumer
 * re-deriving the difference by matching on prose: `bridge:tick`'s exit code and the
 * last-pass-failure marker ({@see JobScheduler::ERROR_KEY}) both turn on it, so it is a
 * FIELD. ⚑ The command is named in prose and not as a `{@see}` deliberately: Pint turns a
 * fully-qualified `{@see}` into a real `use`, and a domain class importing a console command
 * is a dependency pointing the wrong way for a doc reference.
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
        /** True when {@see self::$skipped} is a FAULT rather than an ordinary skip. */
        public readonly bool $faulted,
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
        return new self($source, null, false, $ok, $failed, $refused, $names);
    }

    /** The pass declined to run, by design. Nothing is wrong with this install. */
    public static function skipped(JobPassSource $source, string $reason): self
    {
        return new self($source, $reason, false, 0, 0, 0, []);
    }

    /**
     * The pass could not run because something is WRONG — not because it declined to.
     * The only two producers are {@see JobScheduler::passSafely()}'s catch and the
     * unusable-config refusal, and both are conditions an operator must fix.
     */
    public static function failed(JobPassSource $source, string $reason): self
    {
        return new self($source, $reason, true, 0, 0, 0, []);
    }

    public function didRun(): bool
    {
        return $this->skipped === null;
    }

    /** Whether the pass did not run BECAUSE OF A FAULT. False for every ordinary skip. */
    public function passFailed(): bool
    {
        return $this->faulted;
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
