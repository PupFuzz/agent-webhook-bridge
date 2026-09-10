<?php

namespace App\Bridge\Support;

/**
 * ONE SPAN OF A FINDING'S MESSAGE THAT A FOREIGN PRINCIPAL WROTE, held IN PLACE among the
 * prose around it (card#9121, DL-366).
 *
 * ⭐ WHY IT IS A SEGMENT AND NOT A VALUE THE RENDERER LOOKS UP. The two cuts of this change
 * that shipped a live erase-line onto root's terminal both declared the span BY VALUE — the
 * call site handed the renderer a copy of the bytes and the renderer re-FOUND them in the
 * flat message by substring search. That throws away the one fact only the call site ever
 * had, the POSITION, and asks a search to reconstruct it from bytes an attacker chose. It
 * cannot be made sound:
 *  - a per-span `str_replace` loop rewrites span A INSIDE span B, after which B's exact
 *    match fails and B is emitted RAW (round 1 — reproduced, two live ESC bytes);
 *  - `strtr()` fixes THAT (it never re-processes its own output) but guarantees COVERAGE of
 *    nothing: it takes the longest key matching at each position, so a longer key that
 *    matches across the boundary between the bridge's OWN prose and a later span's real
 *    occurrence eats that span's prefix, the scan resumes INSIDE the span, the span's own
 *    key never matches from there, and its tail is emitted RAW (round 2 — reproduced, a
 *    live `ESC [ 2 K` erase-line, order-independently);
 *  - and a whitespace-only span renders EMPTY, which a value-matching renderer has to skip
 *    (an empty replacement is a deletion applied to the whole message) — so its raw bytes,
 *    `\r` included, reach the line untouched (found by the property leg of
 *    `UntrustedSpanCoverageTest`, which is why that leg is a property and not two cases).
 * The prose is not a secret an attacker has to guess: it is IN the message their bytes are
 * being interpolated into. Three defects, three mechanisms, one cause.
 *
 * ⛔ SO POSITION IS DECLARED AND NOTHING IS SEARCHED. A producer composes its message as a
 * LIST of segments — its own prose as plain strings, each foreign value wrapped here — and
 * {@see UntrustedText::render()} walks that list applying {@see UntrustedText::forOperator()}
 * to these and to nothing else. Coverage stops being an argument about matching and becomes
 * the structure of the value: every `Untrusted` in the list is rendered exactly once,
 * because a list has no other reading.
 *
 * ⛔ IT ESCAPES NOTHING AND CHANGES NO BYTE OF `Finding::$message`, which stays the plain
 * concatenation of the segments. `bridge:check --format=json` carries that string to machine
 * consumers verbatim, and sanitising here would move every consumer's bytes at once with no
 * schema bump to warn them. This records WHERE the seam is; the TERMINAL renderer decides
 * what to do about it.
 *
 * ⚠ WHAT IT STILL DOES NOT CLOSE, stated because an unstated bound reads as a guarantee: a
 * producer that interpolates a foreign string into a plain prose segment and never wraps it
 * gets no protection, and nothing here can see that omission. That bound is unchanged from
 * the value-matching design — what changed is that a span which IS declared can no longer be
 * missed. Review of the call site is the guard against the omission, and
 * `UntrustedFindingDetailTest` asserts it as an executing control rather than as prose.
 */
final class Untrusted
{
    private function __construct(public readonly string $raw) {}

    /**
     * Declare that `$raw` — at THIS position in the message being composed — is text this
     * install did not author.
     */
    public static function span(string $raw): self
    {
        return new self($raw);
    }
}
