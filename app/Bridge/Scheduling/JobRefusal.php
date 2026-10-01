<?php

namespace App\Bridge\Scheduling;

use App\Bridge\Scheduling\Handlers\OwedWriteRetryJob;

/**
 * WHY the scheduler will not invoke a handler — a refusal, which is not a failure
 * (card#8425 / DL-325).
 *
 * ⛔ THE DIRECTIVE'S WORDS WERE *"make an unknown handler name a LOUD refusal, never a
 * silent skip"*, and this class is what makes the loudness structural. A skip has no
 * vocabulary: a job that quietly does not run looks exactly like a job with nothing to do,
 * which is DL-012's failure — a shipped mechanism that never executed and never said so.
 * A refusal has a REASON, it is stamped on the instance row, it is logged at error, and
 * `bridge:check` reds on it.
 *
 * ⚑ THE TWO REASONS TAKE OPPOSITE REMEDIES, which is why they are not one string:
 *  - {@see self::UNKNOWN_HANDLER} — the row names a handler this build does not have. The
 *    fix is a name (a typo, or an instance that outlived a handler removed in an upgrade).
 *  - {@see self::DISARMED_MUTATOR} — the handler EXISTS and declares
 *    {@see JobCapability::MutatesState}, and this install's operator has switched it off
 *    (card#10918 / DL-441). The fix is an operator decision, not a code change.
 */
final class JobRefusal
{
    public const UNKNOWN_HANDLER = 'unknown_handler';

    public const DISARMED_MUTATOR = 'disarmed_mutating_handler';

    private function __construct(
        public readonly string $reason,
        public readonly string $message,
    ) {}

    public static function unknownHandler(string $handler, string $knownList): self
    {
        return new self(
            self::UNKNOWN_HANDLER,
            // The registered set is never empty (the registry's constructor registers the built-in handlers), so there is
            // no "(none)" arm to write here — that would be a branch for a state the
            // constructor excludes.
            "no handler named '{$handler}' exists in this build — a job may only reference a handler that is registered in bridge code. Registered: ".$knownList,
        );
    }

    public static function disarmedMutator(string $handler): self
    {
        return new self(
            self::DISARMED_MUTATOR,
            "handler '{$handler}' declares the state-mutating capability and this install has DISARMED it — "
                .'it is named in BRIDGE_JOBS_DISARMED_MUTATORS'.($handler === OwedWriteRetryJob::NAME ? ' or BRIDGE_OWED_WRITE_RETRY_DISABLED=true is set' : '')
                .'. Nothing was run. Remove that setting to re-arm it, or `php artisan bridge:jobs disable` this instance to stop it without a refusal (see docs/periodic-jobs.md).',
        );
    }
}
