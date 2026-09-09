<?php

namespace Tests\Unit\Bridge\Support;

use App\Bridge\Support\Finding;
use App\Bridge\Support\UntrustedText;
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
            str_repeat('E', UntrustedText::MAX_CHARS).' [TRUNCATED, '.(UntrustedText::MAX_CHARS + 50).' CHARS]',
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
            str_repeat('é', UntrustedText::MAX_CHARS).' [TRUNCATED, '.(UntrustedText::MAX_CHARS + 1).' CHARS]',
            $rendered,
        );
    }

    public function test_render_into_replaces_every_occurrence_of_a_declared_span(): void
    {
        $raw = "\x1b[2Jwiped";
        $message = "marker at /run/x ({$raw}) — and again: {$raw}";

        $this->assertSame(
            'marker at /run/x (\x1B[2Jwiped) — and again: \x1B[2Jwiped',
            UntrustedText::renderInto($message, [$raw]),
        );
    }

    public function test_render_into_is_the_identity_on_a_finding_that_declared_nothing(): void
    {
        // Every finding the bridge itself composed is this case, so the whole terminal
        // report is byte-unchanged by this rule existing.
        $message = Finding::warn("agent prod-agent: channel.socket parent dir /run/user/1000 does not exist\n")->message;

        $this->assertSame($message, UntrustedText::renderInto($message, []));
        $this->assertSame($message, UntrustedText::renderInto($message, ['']));
    }
}
