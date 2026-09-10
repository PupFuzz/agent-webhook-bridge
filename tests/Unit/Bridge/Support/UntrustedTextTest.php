<?php

namespace Tests\Unit\Bridge\Support;

use App\Bridge\Support\Finding;
use App\Bridge\Support\Untrusted;
use App\Bridge\Support\UntrustedText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The rule that makes a string this install did NOT author safe on an operator's terminal
 * (card#9121, DL-366).
 *
 * THE SUBJECT IS THE RULE, NOT ITS ONE CALLER. `CheckCommand::emitFinding()` applying it,
 * and `--format=json` NOT applying it, are the renderer's properties and are asserted at
 * the renderer (`UntrustedFindingDetailTest`). What is here is what the rule does to bytes,
 * because that is the part a second caller will inherit unread.
 *
 * ⚠ THE CONTROL EVERY ASSERTION HERE NEEDS IS THE UNTOUCHED CASE, and it is deliberately
 * the first test: a rule that mangled every input would satisfy every "the payload is not
 * present verbatim" assertion below while destroying the operator prose the leg exists to
 * print. An ordinary connector error line must come back character for character.
 */
class UntrustedTextTest extends TestCase
{
    public function test_an_ordinary_detail_line_comes_back_untouched(): void
    {
        // THE CONTROL. Every withholding assertion below is meaningless without it.
        $this->assertSame(
            'EADDRINUSE: bind failed on 127.0.0.1:8765 (pid 4711)',
            UntrustedText::forOperator('EADDRINUSE: bind failed on 127.0.0.1:8765 (pid 4711)'),
        );
    }

    public function test_an_ansi_escape_is_shown_rather_than_stripped(): void
    {
        // STRIPPING would leave `[31mRED[0m` on the line, which reads as text the connector
        // wrote. The operator needs to see that the file contained an escape.
        $rendered = UntrustedText::forOperator("\x1b[31mRED\x1b[0m");

        $this->assertSame('\x1B[31mRED\x1B[0m', $rendered);
        $this->assertStringNotContainsString("\x1b", $rendered);
    }

    public function test_the_single_codepoint_c1_introducer_is_escaped_too(): void
    {
        // U+009B IS the Control Sequence Introducer on a terminal decoding UTF-8, so a guard
        // that only looked for ESC-bracket would pass this straight through.
        $rendered = UntrustedText::forOperator("\u{009b}2J\u{0090}payload\u{009c}");

        $this->assertSame('\x9B2J\x90payload\x9C', $rendered);
    }

    public function test_newlines_and_tabs_collapse_so_a_payload_cannot_forge_a_second_line(): void
    {
        $rendered = UntrustedText::forOperator("EADDRINUSE\r\nagent prod-agent: channel socket live\n\n\tok");

        $this->assertSame('EADDRINUSE agent prod-agent: channel socket live ok', $rendered);
        $this->assertStringNotContainsString("\n", $rendered);
        $this->assertStringNotContainsString("\r", $rendered);
    }

    public function test_the_remaining_c0_bytes_and_del_are_escaped(): void
    {
        $this->assertSame('a\x00b\x07c\x7Fd', UntrustedText::forOperator("a\x00b\x07c\x7fd"));
    }

    public function test_invalid_utf8_is_scrubbed_rather_than_carried_onto_the_line(): void
    {
        $rendered = UntrustedText::forOperator("ok\xC3\x28bytes");

        $this->assertStringNotContainsString("\xC3\x28", $rendered);
        // A presence witness beside the absence: the surrounding text survives, so this is
        // measuring the scrub and not an empty return.
        $this->assertStringContainsString('bytes', $rendered);
        $this->assertTrue(mb_check_encoding($rendered, 'UTF-8'));
    }

    public function test_a_span_past_the_cap_is_truncated_and_says_so_with_its_full_length(): void
    {
        $rendered = UntrustedText::forOperator(str_repeat('E', UntrustedText::MAX_CHARS + 50));

        $this->assertSame(
            str_repeat('E', UntrustedText::MAX_CHARS).' [TRUNCATED, '.(UntrustedText::MAX_CHARS + 50).' SOURCE CHARS]',
            $rendered,
        );
        // The marker is not a silent ellipsis: `laravel/pao`'s OutputCleaner deletes glyphs
        // and rewrites `...`, so a truncation that must survive both readers is spelled in
        // uppercase and brackets.
        $this->assertStringNotContainsString('...', $rendered);
    }

    public function test_a_span_exactly_at_the_cap_is_not_truncated(): void
    {
        // The boundary, asserted in both directions so the comparison cannot be off by one
        // in the direction that silently eats a character of a legitimate detail.
        $this->assertSame(
            str_repeat('E', UntrustedText::MAX_CHARS),
            UntrustedText::forOperator(str_repeat('E', UntrustedText::MAX_CHARS)),
        );
    }

    public function test_the_cap_counts_characters_and_never_splits_a_multibyte_one(): void
    {
        $rendered = UntrustedText::forOperator(str_repeat('é', UntrustedText::MAX_CHARS + 1));

        $this->assertTrue(mb_check_encoding($rendered, 'UTF-8'));
        $this->assertSame(
            str_repeat('é', UntrustedText::MAX_CHARS).' [TRUNCATED, '.(UntrustedText::MAX_CHARS + 1).' SOURCE CHARS]',
            $rendered,
        );
    }

    /**
     * ⭐ THE ATTACK THAT NEEDS NO CONTROL BYTE. `U+202E` RIGHT-TO-LEFT OVERRIDE is not in
     * C0, not in C1, not `\s`, and is not stripped by anything above — it simply reorders
     * the REST OF THE LINE on every bidi-aware terminal. On `bridge:check`'s output, which
     * root reads to decide whether an install is compromised, that is line spoofing on the
     * one surface this class exists to make trustworthy (Trojan Source, CVE-2021-42574).
     *
     * The isolates are here for the same reason and are the harder half: `U+2066`-`U+2068`
     * OPEN a directional run that `U+2069` closes, so an UNCLOSED one carries past the span
     * and reorders text this install wrote and vouches for.
     */
    public function test_bidi_overrides_and_isolates_are_escaped_rather_than_reordering_the_line(): void
    {
        $rendered = UntrustedText::forOperator("bind failed\u{202E}gpj.exe\u{202D}\u{2066}\u{2067}\u{2068}\u{2069}");

        $this->assertSame('bind failed\\x{202E}gpj.exe\\x{202D}\\x{2066}\\x{2067}\\x{2068}\\x{2069}', $rendered);
        // Spelled out beside the composed assertion above, because a reader skims a long
        // literal: not one of these codepoints survives into the operator's line.
        foreach (["\u{202E}", "\u{202D}", "\u{2066}", "\u{2067}", "\u{2068}", "\u{2069}"] as $codepoint) {
            $this->assertStringNotContainsString($codepoint, $rendered);
        }
    }

    /**
     * The ZERO-WIDTH half of the same class: nothing about these is a control sequence, and
     * their whole effect is that an operator cannot see them. A zero-width space inside a
     * token an operator reads for IDENTITY — an agent name, a path, a version — forges a
     * word break, and a soft hyphen forges one only when the line wraps, so the rendered
     * text differs between two terminals looking at the same bytes.
     */
    public function test_zero_width_and_soft_hyphen_format_characters_are_escaped(): void
    {
        $rendered = UntrustedText::forOperator("prod\u{200B}agent\u{200C}x\u{200D}y\u{00AD}z\u{FEFF}");

        $this->assertSame('prod\\x{200B}agent\\x{200C}x\\x{200D}y\\xADz\\x{FEFF}', $rendered);
        $this->assertSame('prodagentxyz', preg_replace('/\\\\x\{?[0-9A-F]+\}?/', '', $rendered));
    }

    /**
     * ⛔ THE WIDTH IS PART OF THE ESCAPE, not formatting. `sprintf('\x%02X', …)` does not
     * TRUNCATE a value past `0xFF` — it widens the field — so `U+202E` came out as the bare
     * `\x202E`, which reads as `\x20` (a space) followed by the literal text `2E`. An escape
     * that renders a spoofing codepoint as a space and two digits is a wrong-but-specific
     * diagnostic on exactly the codepoint an operator is trying to identify (canon #10).
     */
    public function test_a_codepoint_past_one_byte_renders_in_the_unambiguous_braced_form(): void
    {
        $rendered = UntrustedText::forOperator("\u{202E}");

        $this->assertSame('\\x{202E}', $rendered);
        $this->assertNotSame('\\x202E', $rendered);
        // The boundary in both directions: at or below U+00FF the existing two-digit form is
        // unchanged, so no C0/C1/DEL rendering moved when the class widened.
        $this->assertSame('\\xAD', UntrustedText::forOperator("\u{00AD}"));
        $this->assertSame('\\x9B', UntrustedText::forOperator("\u{009B}"));
    }

    /**
     * THE CONTROL FOR THE THREE TESTS ABOVE. `\p{Cf}` is a narrow Unicode category, and a
     * class that had widened to "anything non-ASCII" — or to `\p{C}`, which swallows
     * unassigned and private-use codepoints — would satisfy every escape assertion above
     * while mangling a legitimate non-English error line into unreadable hex. Ordinary
     * letters, marks, symbols and CJK must come back character for character.
     */
    public function test_ordinary_non_ascii_text_is_not_touched_by_the_widened_class(): void
    {
        foreach (['é', 'ü', '日本語', 'Ω', '→', '—', 'café ☕', 'ß'] as $text) {
            $this->assertSame($text, UntrustedText::forOperator($text));
        }
        // NBSP is the one that is NOT identity, and deliberately so: it IS `\s` under PCRE's
        // UCP, so step 2 has already collapsed it to a plain space before the escape runs.
        $this->assertSame('a b', UntrustedText::forOperator("a\u{00A0}b"));
    }

    /**
     * ⛔ THE THREE SHAPES THAT LEAKED WHILE A RENDERER STILL HAD TO FIND ITS SPANS, asserted
     * over the segment list that ended the search (card#9121, DL-366).
     *
     * Each is the live `ChannelSnapshotProbe::versionLeg()` composition — a deployment PATH
     * and a `package.json` `version`, both chosen by the same principal, with the bridge's
     * own prose between them — and each defeated a DIFFERENT matching strategy:
     *  (a) CONTAINMENT defeated the per-span `str_replace` loop: A inside B, A rewritten
     *      first, B's exact match then fails and B is emitted ENTIRELY raw;
     *  (b) the STRADDLE defeated `strtr()`: a longer key matching from inside the prose into
     *      the next span's occurrence eats that span's prefix, the scan resumes inside the
     *      span, and its tail is emitted raw — order-independently, which is why (a)'s fix
     *      being order-independent proved nothing about it;
     *  (c) an EMPTY RENDERING defeated both: a whitespace-only span renders to `''`, a
     *      value-matching renderer must SKIP it (an empty replacement is a deletion applied
     *      to the whole message, which stripped the spaces out of the bridge's own prose),
     *      and skipping puts the span's RAW bytes — `\r` and `\t` among them — on the line.
     *
     * ⚠ ASSERTED ON A CENSUS OF LIVE CONTROL BYTES, never only on the escaped form being
     * present: every broken renderer above escaped SOME of the spans, so an assertion that
     * merely finds `\x1B` somewhere passes on all of them. A presence witness sits beside
     * each census, because a renderer that dropped the span would satisfy the census alone.
     *
     * @param  list<string|Untrusted>  $segments
     */
    #[DataProvider('leakingCompositions')]
    public function test_no_declared_span_escapes_the_render(string $case, array $segments, string $witness): void
    {
        $rendered = UntrustedText::render($segments);

        $this->assertSame(0, preg_match_all('/[\x00-\x09\x0B-\x1F\x7F]|[\x{0080}-\x{009F}]|\p{Cf}/u', $rendered), "[{$case}] a live control byte survived");
        $this->assertStringContainsString($witness, $rendered, "[{$case}] presence witness");
        // The bridge's OWN prose is untouched, which is what makes the census a measurement
        // of the spans and not of a renderer that mangled the whole line.
        $this->assertStringContainsString('is STALE (deployed ', $rendered, "[{$case}] the prose must survive verbatim");
    }

    /** @return iterable<string, array{string, list<string|Untrusted>, string}> */
    public static function leakingCompositions(): iterable
    {
        $path = "/deploy/ch\x1bx";
        $stale = static fn (string $p, string $v): array => [
            'snapshot at ', Untrusted::span($p), ' is STALE (deployed ', Untrusted::span($v), ' < bundled 9.9.9)',
        ];

        yield 'containment — the version quotes the whole path' => [
            'containment',
            $stale($path, "0.0 from {$path} \x1b[2K\x1b[1;31m"),
            '0.0 from /deploy/ch\x1Bx \x1B[2K\x1B[1;31m',
        ];
        yield 'straddle — the version quotes the prose plus the path prefix' => [
            'straddle',
            $stale($path, 'snapshot at /deploy/ch'),
            'ch\x1Bx is STALE',
        ];
        yield 'empty rendering — a whitespace-only version' => [
            'empty rendering',
            $stale($path, "\n\t "),
            'ch\x1Bx is STALE (deployed  < bundled',
        ];
    }

    /**
     * ⭐ THE ORDER A PRODUCER DECLARES ITS SPANS IN IS NOT A FREE VARIABLE ANY MORE, and this
     * is the leg that says so as an executing fact.
     *
     * Under the value-matching design a finding carried a LIST of span values beside a flat
     * message, so "which was declared first" was a real degree of freedom the renderer had to
     * be argued to be independent of — and round 2's defence of `strtr()` rested on exactly
     * that argument, correctly, while the renderer was leaking for a different reason. Here
     * the declaration IS the position, so the same two foreign values in the opposite
     * arrangement is a DIFFERENT MESSAGE and both render clean. There is no ordering left to
     * get wrong, and no argument left to make.
     */
    public function test_the_same_two_foreign_values_render_clean_in_either_arrangement(): void
    {
        $path = "/deploy/ch\x1bx";
        $version = 'snapshot at /deploy/ch';

        foreach ([
            'path then version' => ['snapshot at ', Untrusted::span($path), ' is STALE (deployed ', Untrusted::span($version), ')'],
            'version then path' => ['snapshot at ', Untrusted::span($version), ' is STALE (deployed ', Untrusted::span($path), ')'],
        ] as $case => $segments) {
            $rendered = UntrustedText::render($segments);
            $this->assertSame(0, substr_count($rendered, "\x1b"), "[{$case}] a live ESC survived");
            $this->assertStringContainsString('ch\x1Bx', $rendered, "[{$case}] presence witness");
            $this->assertStringContainsString('is STALE (deployed ', $rendered, "[{$case}] the prose must survive verbatim");
        }
    }

    /**
     * ⛔ THE TRUNCATION FIGURE IS THE SPAN'S OWN SIZE, NOT THE RENDERED SIZE. Both existing
     * cap tests use `E` and `é`, where escaping is the identity and the two numbers are
     * equal — so neither could discriminate. Escaping is 8:1 here, which is exactly the
     * ratio that made the old figure a wrong-but-specific answer to *how big was the thing
     * planted in my file*.
     */
    public function test_the_truncation_marker_reports_the_span_size_and_not_the_escaped_size(): void
    {
        $rendered = UntrustedText::forOperator(str_repeat("\u{202E}", 100));

        $this->assertStringEndsWith(' [TRUNCATED, 100 SOURCE CHARS]', $rendered);
        $this->assertStringNotContainsString('800', $rendered);
        // The 4:1 case too, so the assertion is about the rule and not about one ratio.
        $this->assertStringEndsWith(' [TRUNCATED, 100 SOURCE CHARS]', UntrustedText::forOperator(str_repeat("\x1b", 100)));
        // The cap itself is still on what fills the screen: MAX_CHARS of escaped text.
        $this->assertSame(UntrustedText::MAX_CHARS, mb_strlen(explode(' [TRUNCATED', $rendered)[0], 'UTF-8'));
    }

    /**
     * A LITERAL backslash-x-1-B rendered identically to a REAL ESC, so a payload could forge
     * the diagnostic *there was a control byte here* — falsifying the one claim this
     * rendering makes, that it says what was actually in the file. One-directional (a false
     * ESC can be claimed; a real one can never be hidden), which is why it is a fix to the
     * DIAGNOSTIC rather than to the escape.
     */
    public function test_a_literal_backslash_cannot_forge_the_rendering_of_a_control_byte(): void
    {
        $this->assertSame('\\\\x1B[31m', UntrustedText::forOperator('\x1B[31m'));
        $this->assertSame('\x1B[31m', UntrustedText::forOperator("\x1b[31m"));
        // THE POINT, stated as an assertion rather than left to the reader of the two above.
        $this->assertNotSame(
            UntrustedText::forOperator('\x1B[31m'),
            UntrustedText::forOperator("\x1b[31m"),
        );
    }

    /**
     * A value interpolated TWICE is TWO segments, and each is rendered at its own position.
     * Under value matching this was one declaration and a replace-all — the same output by a
     * mechanism that also rewrote any occurrence the producer never declared, including one
     * an attacker arranged to appear inside the bridge's own prose.
     */
    public function test_a_value_that_lands_twice_is_escaped_at_both_positions(): void
    {
        $raw = "\x1b[2Jwiped";

        $this->assertSame(
            'marker at /run/x (\x1B[2Jwiped) — and again: \x1B[2Jwiped',
            UntrustedText::render(['marker at /run/x (', Untrusted::span($raw), ') — and again: ', Untrusted::span($raw)]),
        );
    }

    /**
     * ⛔ THE RENDER IS THE IDENTITY ON PROSE THIS INSTALL WROTE, which is every finding the
     * bridge composed for itself — so the whole terminal report is byte-unchanged by this
     * rule existing, and a regression in it would be a regression in `bridge:check`'s output.
     *
     * Driven off a real `Finding`'s own segments rather than a literal, because what must be
     * the identity is the path a finding actually takes to the terminal.
     */
    public function test_the_render_is_the_identity_on_a_finding_that_declared_nothing(): void
    {
        $finding = Finding::warn("agent prod-agent: channel.socket parent dir /run/user/1000 does not exist\n");

        $this->assertSame($finding->message, UntrustedText::render($finding->segments));
        $this->assertSame('', UntrustedText::render([]));
        $this->assertSame('', UntrustedText::render([Untrusted::span('')]));
    }
}
