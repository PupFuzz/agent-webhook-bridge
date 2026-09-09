<?php

namespace App\Bridge\Tools;

/**
 * The optional `client_version` a board-tools call may carry — the calling channel
 * server's own `package.json` version (card#8974 / DL-364).
 *
 * WHY IT EXISTS. Every other fact on the client-half row is established BY THE BRIDGE.
 * This one is the seat's own report of which snapshot it is running, and it is the only
 * way the bridge can learn it: it may not read the seat's deployed directory (an account
 * may only read its own files — the same rule behind DL-229/DL-313). Without it a tool
 * missing from a STALE seat copy is indistinguishable from a tool the bridge never
 * shipped, and the two take opposite remedies.
 *
 * ⛔ IT MAY NEVER CHANGE WHAT THE DOOR ACCEPTS. Both front doors call this on a value the
 * far end sent, and there is no failure exit: an absent, wrongly-typed, over-long or
 * otherwise unusable value becomes NULL — "not reported" — and the call proceeds exactly
 * as it did before the field existed. Turning any of those into a refusal would break every
 * client older than {@see self::FIRST_REPORTING_SNAPSHOT} for a field that is an audit
 * observation, not a credential.
 *
 * ⛔ THE OUTPUT IS PRINTED VERBATIM INTO A `bridge:check` LINE, so the shape is a
 * whitelist and not a sanitiser-by-removal. `[0-9A-Za-z.+-]` admits every version npm can
 * declare and admits no whitespace, no newline, no ANSI escape and no shell metacharacter,
 * so nothing a caller sends can rewrite an operator's terminal or forge a second finding
 * line. An over-long value is REFUSED rather than truncated: a truncated version is a
 * WRONG version, and it would be compared and reported as one.
 */
final class ClientVersion
{
    /**
     * The first reference channel-server snapshot that sends `client_version`.
     *
     * ⚑ A PIN, NOT A RESTATEMENT OF THE CURRENT VERSION. It names a historical fact — the
     * release at which the field started being sent — so it is frozen while
     * `examples/channel-servers/package.json` keeps moving, and it is what lets the check
     * say what an ABSENT report means (a client older than this) instead of leaving the
     * operator to guess. `ClientVersionTest` pins it at or below the bundled version, which
     * is the one way it could become false.
     */
    public const FIRST_REPORTING_SNAPSHOT = '0.9.14';

    /** The column's width, and therefore the longest value that can be recorded whole. */
    private const MAX_LENGTH = 32;

    /**
     * The value to RECORD for a call carrying `$raw`, or null for "not reported".
     *
     * @param  mixed  $raw  the `client_version` field exactly as the caller sent it — any
     *                      type, including absent (null)
     */
    public static function fromCall(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }
        if (strlen($raw) > self::MAX_LENGTH) {
            return null;
        }

        return preg_match('/^[0-9A-Za-z.+-]+$/', $raw) === 1 ? $raw : null;
    }
}
