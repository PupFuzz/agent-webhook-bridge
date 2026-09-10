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
 * of its message it did not author — BY POSITION, by composing that message as a list of
 * segments with the foreign parts wrapped in {@see Untrusted}. {@see self::render()} owns
 * why nothing is searched for, and {@see Untrusted} owns the three defects that taught it.
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
 *    string into a plain prose segment, rather than wrapping it in {@see Untrusted}, gets no
 *    protection, and nothing here can see that omission — a string segment is indivisible by
 *    the time a renderer holds it. The guard against that is review of the call site, not
 *    this class. ⚑ What IS closed, and was not under the value-matching design this
 *    replaced, is the span that IS declared: it can no longer be missed, straddled or
 *    skipped, because {@see self::render()} performs no matching at all.
 *  - **It is not an escape for any other sink.** These bytes are shaped for a terminal
 *    line. Anything writing a finding to HTML, a shell argument or a log format owns its
 *    own encoding.
 *  - **The escaped class is `Cc | Cf` and NOT all of `\p{C}`, so two neighbours pass
 *    through** and are named here rather than left for the next reader to discover:
 *    COMBINING MARKS (`\p{Mn}`, `\p{Me}`) — a stack of them renders as a smear over the
 *    preceding glyph, which mangles a line without forging one — and PRIVATE-USE
 *    codepoints (`\p{Co}`), whose glyph is whatever font the operator's terminal happens
 *    to load. Neither reorders text, neither introduces a line break, and neither is a
 *    control sequence, so both are DISPLAY noise rather than the spoofing class this
 *    escapes; escaping them would hex out the accented and scripted text a legitimate
 *    non-English connector error is made of. Named, not closed — if a spoof is ever
 *    demonstrated through one, that is a reason to widen, and the demonstration is the
 *    bar (`\p{Cn}`, unassigned, is out for the same reason and moves every time the
 *    Unicode tables do).
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
     *  3. what remains of C0 (`\x00`-`\x1F` less the whitespace already gone), DEL, the
     *     C1 range (U+0080 to U+009F) and EVERY Unicode FORMAT character (`\p{Cf}`) is
     *     escaped to a visible form: `\xNN` at or below U+00FF, `\x{NNNN}` above it, so a
     *     codepoint past one byte can never be read as a shorter one followed by digits.
     *     ⭐ C1 MATTERS AS MUCH AS `\x1B`: on a terminal decoding UTF-8, U+009B IS the
     *     Control Sequence Introducer — dropping the ESC and keeping the single-codepoint
     *     C1 form is the obvious way past a guard that only looks for `\x1B[`.
     *     ⭐ `\p{Cf}` MATTERS FOR THE SAME REASON ONE LEVEL UP, and it is what the first
     *     cut of this class missed: the BIDI overrides and isolates (U+202A-U+202E,
     *     U+2066-U+2069) reorder the REST OF THE LINE on every bidi-aware terminal without
     *     emitting a control byte at all — Trojan-Source line spoofing, aimed here at
     *     root's own security-diagnostic output — and the zero-width and soft-hyphen
     *     members (U+200B, U+200C, U+200D, U+00AD, U+FEFF) let a payload hide a word break
     *     or forge one inside a token an operator is reading for identity. MEASURED on this
     *     build, not assumed: none of them is matched by step 2's `\s+` (PCRE's `/u` sets
     *     UCP, and Cf is not Unicode whitespace), so the collapse never reached them;
     *     U+00A0 IS `\s` under UCP and is therefore already a space by the time this runs.
     *     ⭐ THE BACKSLASH (`\x5C`) IS IN THE CLASS TOO, doubled to `\\`, and it is here
     *     rather than in an earlier pass ON PURPOSE — see the callback;
     *  4. the cap, applied to the ESCAPED text (that is what fills a screen) with a marker
     *     naming the SPAN's OWN character count, so a truncated line says it was truncated
     *     and how big the thing actually was rather than how wide this rendering of it got.
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
        // Scrubbed ONCE and reused by the cap below. A second `mb_scrub($raw)` down there
        // would be a second derivation of one value (canon #5) — and the one the marker's
        // figure is measured on, so a drift between them is a wrong figure, not a slow one.
        $scrubbed = mb_scrub($raw, 'UTF-8');
        $collapsed = preg_replace('/\s+/u', ' ', $scrubbed);
        $escaped = is_string($collapsed)
            ? preg_replace_callback(
                '/[\x00-\x1F\x5C\x7F]|[\x{0080}-\x{009F}]|\p{Cf}/u',
                static function (array $m): string {
                    // ⭐ THE BACKSLASH IS ESCAPED, AND IT IS IN THIS SAME CLASS RATHER THAN
                    // IN A PASS BEFORE IT. Without it `forOperator('\\x1B[31m')` — a
                    // LITERAL backslash, x, 1, B — returns bytes identical to the rendering
                    // of a real ESC, so a payload could forge the diagnostic *there was a
                    // control byte here* and falsify the one claim this rendering makes:
                    // that it says what was actually in the file. It is one-directional
                    // (false positives only, never a missed control byte), which is why it
                    // is a correctness fix to the DIAGNOSTIC and not to the escape.
                    // ⛔ ONE PASS IS WHY THE ORDERING CANNOT BE WRONG. "Escape backslashes
                    // first" is the usual instruction and the usual bug — a second pass
                    // re-escapes the backslashes the first pass just emitted. Here the
                    // regex consumes each source character exactly once and nothing
                    // re-reads its output, so the rule needs no ordering to be correct.
                    if ($m[0] === '\\') {
                        return '\\\\';
                    }

                    $codepoint = (int) mb_ord($m[0], 'UTF-8');

                    // TWO WIDTHS, and the wider one is not cosmetic. `%02X` does not
                    // truncate a value past 0xFF — it WIDENS the field — so U+202E came
                    // out as the bare `\x202E`, which reads as `\x20` followed by the
                    // literal text `2E`: an escape that renders a spoofing codepoint as a
                    // space and two digits is a wrong-but-specific diagnostic (canon #10)
                    // on exactly the byte an operator is trying to identify.
                    return $codepoint <= 0xFF
                        ? sprintf('\\x%02X', $codepoint)
                        : sprintf('\\x{%04X}', $codepoint);
                },
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
        if (mb_strlen($text, 'UTF-8') <= self::MAX_CHARS) {
            return $text;
        }

        // ⛔ THE FIGURE IS THE SPAN'S OWN SIZE, NOT THE RENDERED SIZE, and the distinction
        // is the whole point of printing one. The cap is applied to the ESCAPED text —
        // correctly, because that is what fills the operator's screen — but reporting the
        // escaped length as the span's size tells an operator sizing a planted marker that
        // a file held 800 characters when it held 100 (one `\x{202E}` renders eight wide).
        // That is the same wrong-but-specific-figure defect the widened escape class exists
        // to stop, re-minted one layer up, so the count is taken on the SCRUBBED SOURCE and
        // the label names which of the two it is.
        $sourceChars = mb_strlen($scrubbed, 'UTF-8');

        return mb_substr($text, 0, self::MAX_CHARS, 'UTF-8')." [TRUNCATED, {$sourceChars} SOURCE CHARS]";
    }

    /**
     * A finding's SEGMENTS, rendered as one operator line: this install's own prose verbatim,
     * every {@see Untrusted} span through {@see self::forOperator()}.
     *
     * ⛔ NOTHING IS SEARCHED FOR, AND THAT IS THE WHOLE PROPERTY. Two earlier cuts of this
     * change declared each span by VALUE and re-found it here — one with a per-span
     * `str_replace` loop, one with a single `strtr()` map — and BOTH shipped a live
     * `ESC [ 2 K` erase-line onto root's terminal, by different mechanisms, each with a green
     * suite. {@see Untrusted} records all three failures and why no third matching strategy
     * could be sound: a search reconstructs a position from bytes the attacker chose, and the
     * prose it has to disambiguate against is public, because it is in the message their
     * bytes are going into.
     *
     * What replaces the argument is arithmetic. Each segment is rendered exactly once, in
     * order, independent of every other segment — so COVERAGE is not a property of the input
     * (which an attacker picks) but of the loop (which they do not). A span cannot be eaten
     * by a neighbour's match, cannot be skipped because its rendering is empty, and cannot be
     * re-processed: there is no match, no skip and no second pass to exploit.
     *
     * ⚑ A SPAN WHOSE RENDERING IS EMPTY RENDERS EMPTY, and needs no special case any more.
     * Under value matching it needed one and got the wrong one twice: an empty replacement is
     * a DELETION applied to every occurrence, which stripped the spaces out of the bridge's
     * own prose (a `version` of `" "` sufficed), and the fix — dropping the pair — put the
     * span's RAW bytes back on the line, `\r` and `\t` included. Here the span is at a
     * position and its rendering goes at that position, whatever its length; the prose either
     * side is not reachable from it at all.
     *
     * @param  list<string|Untrusted>  $segments
     */
    public static function render(array $segments): string
    {
        $line = '';
        foreach ($segments as $segment) {
            $line .= $segment instanceof Untrusted ? self::forOperator($segment->raw) : $segment;
        }

        return $line;
    }
}
