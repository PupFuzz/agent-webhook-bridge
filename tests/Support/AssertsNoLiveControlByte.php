<?php

namespace Tests\Support;

use App\Bridge\Support\UntrustedText;

/**
 * ⛔ THE CENSUS OVER A RENDERED OPERATOR LINE: no member of the escaped class survived it
 * (card#9121, DL-366).
 *
 * ⭐ WHY IT IS A CENSUS AND NOT A SEARCH FOR THE PLANTED PAYLOAD. Every leak this change
 * has had so far escaped through a mechanism nobody had thought of — span containment, a
 * longest-key straddle, an empty rendering, and now a call site that declared nothing at
 * all — so the shape that leaks next is by definition not one of the shapes a test could
 * think to look for. What IS assertable is the invariant: after the terminal renderer, the
 * line holds no live control codepoint, whatever put it there.
 *
 * ⚠ ABSENCE ALONE IS NOT THE ASSERTION. A renderer that dropped the span entirely would
 * satisfy it, so the census is always paired with a PRESENCE witness for the ESCAPED form.
 * {@see self::assertForeignValueEscapedInto()} is that pairing, hoisted here at its second
 * caller once the escape moved to the producer (card#9200) and every per-producer pin needed
 * the identical two-legged assertion — a caller that writes only one of the two legs is the
 * shape this trait's own docblock warns about.
 *
 * ⭐ HOISTED AT THE SECOND REAL CALLER (canon #5), not at the first: it began as one
 * producer's private method and the kanban card-field producer needed the identical
 * census. A second copy of a security-invariant assertion is the shape where one copy
 * silently stops matching the class the other one widened to — which is also why the
 * presence leg was folded in here rather than re-written at each caller.
 */
trait AssertsNoLiveControlByte
{
    /**
     * The class {@see UntrustedText::forOperator()} escapes, MINUS the
     * newline the console itself writes between findings.
     *
     * `\n` is the one member a rendered buffer legitimately contains — `line()`/`warn()`
     * terminate every finding with one — so it is excluded here and NOWHERE ELSE. `\r` and
     * `\t` are NOT excluded: neither appears in any sentence this install wrote, and `\r`
     * alone returns the cursor to column 0 and overwrites the line above it.
     */
    private const LIVE_CONTROL = '/[\x00-\x09\x0B-\x1F\x7F]|[\x{0080}-\x{009F}]|\p{Cf}/u';

    /**
     * ⛔ IT REPORTS THE OFFENDING BYTE BY NAME. A bare
     * `assertDoesNotMatchRegularExpression` on a buffer carrying an erase-line prints a
     * mangled diagnostic onto the very terminal reading the failure.
     */
    protected function assertNoLiveControlByte(string $rendered, string $case = ''): void
    {
        $hits = [];
        if (preg_match_all(self::LIVE_CONTROL, $rendered, $m) > 0) {
            foreach ($m[0] as $byte) {
                $hits[] = sprintf('U+%04X', (int) mb_ord($byte, 'UTF-8'));
            }
        }

        $this->assertSame([], $hits, trim(
            ($case === '' ? '' : "[{$case}] ")
            .'live control codepoints reached the operator terminal: '.implode(' ', $hits)
            .' — in: '.addcslashes($rendered, "\0..\37\177..\377")
        ));
    }

    /**
     * The two-legged assertion every per-producer pin owes: the operator line carries NO live
     * member of the escaped class, AND it carries the foreign value's ESCAPED form IN FULL.
     *
     * ⛔ THE PRESENCE LEG IS WHY THIS IS NOT A BARE CENSUS. An absence-only assertion is also
     * satisfied by a producer that DROPPED the value, which would withhold the one part of the
     * line naming the actual fault — so the escaped rendering is asserted as a substring, and
     * the expectation is DERIVED by calling the escape rather than written out as a literal
     * (a hand-written `\x{202E}` is a second implementation of the escape, and it drifts).
     *
     * ⚑ IT REPLACES THE SPAN-LIST READBACK the value-declaring design needed. That helper
     * could only assert a producer had DECLARED a span; this asserts the OUTCOME, which is
     * strictly stronger and does not care where in the sentence the value landed.
     */
    protected function assertForeignValueEscapedInto(string $rendered, string $raw, string $case = ''): void
    {
        $this->assertNoLiveControlByte($rendered, $case);
        $this->assertStringContainsString(
            UntrustedText::forOperator($raw),
            $rendered,
            trim(($case === '' ? '' : "[{$case}] ").'the foreign value did not reach the line in escaped form — a DROP satisfies an absence-only assertion, which is why this leg exists; line: '.addcslashes($rendered, "\0..\37\177..\377")),
        );
    }
}
