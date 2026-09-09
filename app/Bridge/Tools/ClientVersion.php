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
 * whitelist and not a sanitiser-by-removal. `[0-9A-Za-z.+-]`, anchored `^…\z`, admits every
 * version npm can declare and admits no whitespace, no newline (INCLUDING A TRAILING ONE —
 * see the anchor note on {@see self::fromCall()}), no ANSI escape and no shell
 * metacharacter, so nothing a caller sends can rewrite an operator's terminal or split one
 * finding across two lines. An over-long value is REFUSED rather than truncated: a truncated
 * version is a WRONG version, and it would be compared and reported as one.
 *
 * ⚠ WHAT THE WHITELIST DOES NOT BUY, stated because the first cut of this class claimed it
 * did: admitting only these characters is not the same as admitting only COMPARABLE
 * versions. `v1.0.0`, `abc` and `release-3` all pass here, and the comparator the check uses
 * coerces a segment with no leading digit to `0` — so the CHECK, not this class, is where an
 * uncomparable-but-well-charactered value is kept out of a version verdict. This class
 * answers "may this string be stored and printed", and only that.
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
     *
     * ⛔ FROZEN IS NOT THE SAME AS WRITE-ONCE, and this constant had to MOVE before it ever
     * shipped: the branch that introduced it bumped the snapshot 0.9.13 → 0.9.14, and while
     * it was open `dev` shipped 0.9.14 for an unrelated dependency bump — a release that
     * reports NO version. Left at 0.9.14 the check would have told an operator running that
     * release that their client was older than it. The rule is that this names the snapshot
     * that FIRST SENDS the field, which is only knowable once the branch lands its own bump.
     */
    public const FIRST_REPORTING_SNAPSHOT = '0.9.15';

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

        // ⛔ `\z`, NEVER `$`. PCRE's `$` matches BEFORE a final newline, so `/^…+$/` ACCEPTS
        // "0.9.15\n" — measured, not reasoned. That is the one shape this whitelist exists to
        // stop and the one it was blind to: the value is printed verbatim into a `bridge:check`
        // finding, and a trailing newline renders the leg's single line as TWO. `\z` is the
        // absolute end of the subject and admits nothing after the last permitted character.
        return preg_match('/^[0-9A-Za-z.+-]+\z/', $raw) === 1 ? $raw : null;
    }
}
