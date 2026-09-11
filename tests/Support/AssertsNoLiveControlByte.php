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
 * satisfy it, so every caller pairs this with a PRESENCE witness for the ESCAPED form.
 * That pairing is the caller's job — it is per-payload and cannot live here.
 *
 * ⭐ HOISTED AT THE SECOND REAL CALLER (canon #5), not at the first: it began as
 * `UntrustedSpanCoverageTest`'s private method, over the channel-probe producer, and the
 * kanban card-field producer needs the identical census. A second copy of a
 * security-invariant assertion is the shape where one copy silently stops matching the
 * class the other one widened to.
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
}
