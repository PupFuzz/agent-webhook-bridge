<?php

namespace App\Bridge\IdleNudge;

use App\Bridge\Support\PathHelper;
use App\Bridge\Validation\SocketPath;

/**
 * `idle_nudge.seat_record` — its SHAPE is judged when the agent YAML loads, and its `~` is
 * resolved only when the pass reads the record.
 *
 * ⛔ NEVER RESOLVED AT LOAD. Every process that loads the agent YAMLs runs the load — the webhook
 * receiver under PHP-FPM included, whose `clear_env` default leaves it no HOME — and an agent
 * YAML that does not load fails every delivery (`SubscriptionRegistry` is fail-closed). A home
 * that cannot be resolved is therefore a fact about ONE pass's read, never a config error.
 *
 * ⚠ `~` RESOLVES AGAINST THE HOME OF THE PROCESS THAT RUNS THE PASS (the tick), and the record
 * lives in the SEAT's home. The two are one account only when the tick runs as the seat's OS
 * user; otherwise the operator writes the seat's absolute path.
 */
final class SeatRecordPath
{
    /** Non-empty, no null byte, no `..` segment, and absolute or `~/`-prefixed. */
    public static function isDeclarable(string $value): bool
    {
        return SocketPath::isValid(str_starts_with($value, '~/') ? substr($value, 1) : $value);
    }

    /**
     * The path the pass reads for a declared value.
     *
     * @throws SeatRecordUnmeasured `seat_record_home_unresolved` — `~/` with no usable HOME
     */
    public static function resolve(string $declared): string
    {
        $path = PathHelper::expandUser($declared);
        if (! SocketPath::isValid($path)) {
            throw new SeatRecordUnmeasured('seat_record_home_unresolved');
        }

        return $path;
    }
}
