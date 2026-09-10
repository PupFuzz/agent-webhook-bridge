<?php

namespace App\Bridge\Support;

/**
 * THE DISPLAY A GUARD IS ABOUT TO INTERPOLATE, WITH THE RULING ON WHO WROTE IT — required,
 * so a call site cannot make that ruling by not typing anything (card#9121, DL-366).
 *
 * ⭐ WHY A SUM TYPE AND NOT AN OPTIONAL PARAMETER. {@see PathVisibility} BUILDS the finding
 * its callers return, so the display is composed by the caller and interpolated inside the
 * guard — which means the caller is the only thing that knows whether any part of it is
 * foreign, and the guard is the only thing that knows where it lands. The first shape of
 * this took an optional `string $untrusted = ''`: ten call sites, two of them foreign, and
 * BOTH of those two were missed by the sweep that declared their own siblings two branches
 * away in the same function. A silent default makes the omission indistinguishable from the
 * ruling, which is the same failure mode that shipped a live escape sequence. There is no
 * default here and no way to build one of these without picking:
 *  - {@see self::ownConfig()} — every byte is this install's own (a `secret_dir` path, a
 *    configured `token_path`, a `channel.socket` parent, an agent name from this install's
 *    own YAML). The test is the PRINCIPAL, not the shape: declaring these foreign would say
 *    this install does not vouch for what this install wrote.
 *  - {@see self::carrying()} — at least one span a foreign principal chose, given BY
 *    POSITION. Its signature REQUIRES an {@see Untrusted} argument, so it cannot be called
 *    the way the old default could be reached.
 *
 * ⚠ IT IS NOT A GENERAL MESSAGE BUILDER. `Finding`'s own factories already take a segment
 * list; this exists for the one shape where a PRIMITIVE composes the sentence and a CALLER
 * supplies part of it. Two guards need it today, both on {@see PathVisibility}.
 */
final class Provenance
{
    /**
     * @param  list<string|Untrusted>  $segments
     */
    private function __construct(public readonly array $segments) {}

    /**
     * Every byte of this display is text THIS INSTALL authored or the OPERATOR configured.
     */
    public static function ownConfig(string $display): self
    {
        return new self([$display]);
    }

    /**
     * A display carrying at least one span a foreign principal chose.
     *
     * ⛔ THE FIRST TWO PARAMETERS ARE POSITIONAL AND REQUIRED, and the second is typed
     * {@see Untrusted}: that is what makes the ruling impossible to leave untyped. Prose
     * before the span goes in `$before` (pass `''` when the span opens the display); anything
     * after it, foreign or not, goes in `$rest`.
     */
    public static function carrying(string $before, Untrusted $span, string|Untrusted ...$rest): self
    {
        return new self([$before, $span, ...array_values($rest)]);
    }
}
