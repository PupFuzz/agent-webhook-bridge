<?php

namespace App\Bridge\Support;

/**
 * ONE OWNER for the rule that turns a string this install did NOT author into something
 * safe to put on an operator's terminal (card#9121, DL-366).
 *
 * ⭐ WHY IT IS A PRIMITIVE AND NOT A LINE AT THE ONE CALL SITE THAT NEEDS IT TODAY. The
 * channel bind-FAILURE marker is the first finding detail written by a foreign principal;
 * it will not be the last (an operator-supplied `--pubkey-from` file's contents, a remote
 * error body, a foreign config's parse error). Escaping at each site is the second
 * divergent implementation of one behaviour, which is canon #5's defect — so the rule
 * lives here, the RENDERER applies it, and a call site's only job is to DECLARE which span
 * of its message it did not author ({@see Finding::carryingUntrusted()}).
 *
 * ⛔ IT IS NOT APPLIED IN `Finding`'s CONSTRUCTOR, DELIBERATELY, and the reason is a write
 * contract. `bridge:check --format=json` carries `findings[].message` verbatim, machine
 * consumers already read it, and sanitising at construction would change those bytes for
 * every consumer at once — a shape change with no schema bump to warn anyone. The escape
 * is therefore a property of the TERMINAL rendering only: `CheckCommand::emitFinding()`
 * calls this on the way to `error()`/`warn()`/`line()`/`info()`, and the JSON document
 * reads `Finding::$message` untouched. `CheckJsonRenderer` is NAMED, never
 * `{@see}`-linked: pint would turn the FQCN into a real `use` and invert the layering.
 *
 * ⚠ WHAT THIS DOES NOT CLOSE, stated because an unstated bound reads as a guarantee:
 *  - **`--format=json` still carries the raw bytes.** That is the contract above, not an
 *    oversight. A consumer that renders those strings to a terminal owns this same rule at
 *    its own boundary; the document's own `message` bound (operator prose, never part of
 *    the contract) is where that is written down for consumers.
 *  - **It only sanitises DECLARED spans.** A future call site that interpolates a foreign
 *    string and forgets `carryingUntrusted()` gets no protection, and nothing here can see
 *    that omission — the message is one flat string by the time a renderer holds it. The
 *    guard against that is review of the call site, not this class.
 *  - **It is not an escape for any other sink.** These bytes are shaped for a terminal
 *    line. Anything writing a finding to HTML, a shell argument or a log format owns its
 *    own encoding.
 *  - **One arm has no red-once witness**, disclosed at the arm itself: the PCRE-failure
 *    fallback in {@see self::forOperator()} could not be reached with any input a review
 *    could construct. It is kept because the direction of that fallback is a security
 *    decision even if the state never occurs.
 */
final class UntrustedText
{
    /**
     * The cap, in CHARACTERS (not bytes) — a marker detail past this is a payload, not a
     * connector's error line.
     *
     * ⭐ WHY THE CAP IS ON THE SPAN AND NOT ON THE FINDING. The bridge's own finding prose
     * routinely runs past this on its own (the bind-FAILURE tail alone is close to it), so
     * a whole-message cap would truncate sentences this install wrote and vouches for,
     * while leaving a 199-character forged line entirely intact. The span is the part
     * nobody here authored, so the span is the part that is bounded.
     *
     * It is a DISPLAY bound layered over {@see UntrustedPathContents::MAX_BYTES}, which is
     * the READ bound: the reader stops a root process consuming an unbounded file, this
     * stops what it did read from filling an operator's screen.
     */
    public const MAX_CHARS = 200;

    /**
     * One untrusted span, rendered for an operator's terminal.
     *
     * IN ORDER, and the order is load-bearing:
     *  1. invalid UTF-8 is scrubbed to U+FFFD, so every step below operates on text rather
     *     than on bytes that could split a multi-byte sequence at the cap;
     *  2. every WHITESPACE RUN — newlines included — collapses to one space, so a payload
     *     cannot forge a second finding-shaped line, and so the cap counts content;
     *  3. what remains of C0 (`\x00`-`\x1F` less the whitespace already gone), DEL, and the
     *     C1 range (U+0080 to U+009F) is escaped to a visible `\xNN`. ⭐ C1 MATTERS AS MUCH
     *     AS `\x1B`: on a terminal decoding UTF-8, U+009B IS the Control Sequence Introducer
     *     — dropping the ESC and keeping the single-codepoint C1 form is the obvious way
     *     past a guard that only looks for `\x1B[`;
     *  4. the cap, with a marker naming the FULL length, so a truncated line says it was
     *     truncated and by how much rather than silently ending.
     *
     * ⛔ ESCAPED, NOT STRIPPED. `\x1B` deleted leaves `[31m` on the operator's line, which
     * reads as content the connector wrote; `\x1B` shown as `\x1B` says what was actually
     * in the file, which is the diagnostic an operator looking at a planted marker needs.
     *
     * ⛔ THE MARKER CARRIES NO `…` AND NO `...`: `laravel/pao` binds its own `OutputStyle`
     * when it detects an AI agent running the command, and its `OutputCleaner` deletes a
     * fixed glyph set and rewrites `...` to `..`. Uppercase and brackets survive both
     * readers, so a truncation that must not be silent is spelled in them.
     */
    public static function forOperator(string $raw): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', mb_scrub($raw, 'UTF-8'));
        $escaped = is_string($collapsed)
            ? preg_replace_callback(
                '/[\x00-\x1F\x7F]|[\x{0080}-\x{009F}]/u',
                static fn (array $m): string => sprintf('\\x%02X', (int) mb_ord($m[0], 'UTF-8')),
                $collapsed,
            )
            : null;

        // ⛔ FAIL CLOSED. The arm exists because `preg_replace` returns `string|null`, and
        // what is decided here is the DIRECTION of the fallback, not that the state is
        // reachable: returning `$raw` would hand the terminal the exact bytes this function
        // exists to withhold — a guard failing OPEN at the input its attacker controls.
        // ⚑ IT IS UNEXERCISED, and this says so rather than claiming a demonstrated trigger
        // (the same disclosure `UntrustedPathContents` makes about its `fstat()` arm).
        // MEASURED, not assumed: neither pattern backtracks, so `pcre.backtrack_limit` and
        // `pcre.recursion_limit` at 1 over 1 MiB subjects both return a string; the only
        // null either yields is `PREG_BAD_UTF8_ERROR`, and `mb_scrub` above removes that
        // input (checked over six malformed shapes, lone surrogate and overlong included).
        if (! is_string($escaped)) {
            return '[UNRENDERABLE: '.strlen($raw).' bytes this process could not safely escape]';
        }

        $text = trim($escaped);

        $length = mb_strlen($text, 'UTF-8');
        if ($length > self::MAX_CHARS) {
            return mb_substr($text, 0, self::MAX_CHARS, 'UTF-8')." [TRUNCATED, {$length} CHARS]";
        }

        return $text;
    }

    /**
     * A finding's message with each declared untrusted span replaced by its rendered form.
     *
     * SUBSTRING REPLACEMENT, not a re-composition, because the message is already one flat
     * string by the time any renderer holds it and the call site is the only thing that
     * ever knew where the seam was. The span is matched EXACTLY as it was interpolated —
     * control bytes and all — so the match is on bytes the check itself passed through,
     * never on a pattern guessed from the sentence around it.
     *
     * EVERY occurrence is replaced, deliberately: a message that interpolates one
     * untrusted value twice is one this rule must not half-apply.
     *
     * An empty span is skipped rather than special-cased downstream — `str_replace` with an
     * empty needle is a no-op, and skipping says so.
     *
     * @param  list<string>  $untrusted
     */
    public static function renderInto(string $message, array $untrusted): string
    {
        foreach ($untrusted as $raw) {
            if ($raw === '') {
                continue;
            }
            $message = str_replace($raw, self::forOperator($raw), $message);
        }

        return $message;
    }
}
