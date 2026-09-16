<?php

namespace Tests\Unit\Console;

use App\Bridge\Console\TerminalSafeText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * The transform the output choke applies to every formatted write (card#9251, DL-393).
 *
 * ⚠ The expected values are written as ESCAPES, never as the raw bytes, and a failure is
 * reported through `bin2hex` — a diff carrying a live erase-line would mangle the very terminal
 * reading it.
 */
class TerminalSafeTextTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function stripped(): array
    {
        return [
            'ESC and a CSI erase-line' => ["a\e[2Kb", 'a[2Kb'],
            'carriage return is stripped, not kept' => ["line\roverwrite", 'lineoverwrite'],
            'NUL, BEL, BS, VT, FF' => ["a\x00b\x07c\x08d\x0Be\x0Cf", 'abcdef'],
            'DEL' => ["a\x7Fb", 'ab'],
            'a whole C1 CSI (U+009B)' => ["a\u{009B}31mb", 'a31mb'],
            'C1 range edges U+0080 and U+009F' => ["a\u{0080}b\u{009F}c", 'abc'],
            'bidi override RLO U+202E' => ["fix/\u{202E}elif", 'fix/elif'],
            'bidi isolate U+2066' => ["a\u{2066}b", 'ab'],
            'zero-width space U+200B' => ["ad\u{200B}min", 'admin'],
            'zero-width no-break space U+FEFF' => ["a\u{FEFF}b", 'ab'],
            'soft hyphen U+00AD' => ["a\u{00AD}b", 'ab'],
        ];
    }

    #[DataProvider('stripped')]
    public function test_each_member_of_the_stripped_class_is_removed(string $input, string $expected): void
    {
        $this->assertSame(bin2hex($expected), bin2hex(TerminalSafeText::strip($input)));
    }

    /** @return array<string, array{string}> */
    public static function preserved(): array
    {
        return [
            'newline' => ["one\ntwo\n"],
            'tab' => ["col\tcol"],
            'backslash, never doubled' => ['Illuminate\\Http\\Client\\RequestException: \\x1B'],
            'combining mark (bound: display noise, not spoofing)' => ["e\u{0301}"],
            'private-use codepoint (same bound)' => ["\u{E000}"],
            'ordinary multibyte text' => ['déjà vu — ✓'],
            'an already-escaped forOperator() rendering' => ['head_ref fix/\\x{202E}elif'],
        ];
    }

    #[DataProvider('preserved')]
    public function test_bytes_outside_the_class_pass_through_unchanged(string $input): void
    {
        $this->assertSame(bin2hex($input), bin2hex(TerminalSafeText::strip($input)));
    }

    public function test_invalid_utf8_is_scrubbed_to_the_replacement_character_before_the_strip(): void
    {
        $this->assertSame(bin2hex("a\u{FFFD}b"), bin2hex(TerminalSafeText::strip("a\xC2b")));
        $this->assertSame(bin2hex("a\u{FFFD}b"), bin2hex(TerminalSafeText::strip("a\x9Bb")));
    }

    /**
     * ⛔ A C1 CSI split across two writes. Stripped per write, neither half is a valid
     * codepoint, so the scrub turns each into U+FFFD — the terminal never sees `C2 9B`
     * reassembled, and never a lone raw `9B`.
     */
    public function test_a_c1_introducer_split_across_two_writes_never_reaches_the_stream_raw(): void
    {
        $joined = TerminalSafeText::strip("x\xC2").TerminalSafeText::strip("\x9B31m");

        $this->assertTrue(mb_check_encoding($joined, 'UTF-8'), bin2hex($joined));
        $this->assertStringNotContainsString("\xC2\x9B", $joined);
        $this->assertSame(bin2hex("x\u{FFFD}\u{FFFD}31m"), bin2hex($joined));
    }

    public function test_the_scrub_does_not_leak_its_substitute_character_into_the_process(): void
    {
        $before = mb_substitute_character();

        TerminalSafeText::strip("a\xC2b");

        $this->assertSame($before, mb_substitute_character());
    }

    /**
     * ⛔ FAIL CLOSED, AND NEVER EMPTY. The PCRE failure is forced — the interpreter with a zero
     * backtrack limit fails on every subject — so the arm has been seen to run. What comes out
     * is ASCII with every other byte named by a `?`: no raw byte of the class, and not silence.
     *
     * A separate process, because PCRE caches a pattern compiled under JIT and a JIT-compiled
     * pattern ignores the backtrack limit: once an earlier test has used the pattern, turning
     * JIT off here would force nothing.
     */
    #[RunInSeparateProcess]
    public function test_a_regex_failure_neither_passes_raw_bytes_nor_prints_nothing(): void
    {
        $jit = (string) ini_get('pcre.jit');
        $limit = (string) ini_get('pcre.backtrack_limit');
        ini_set('pcre.jit', '0');
        ini_set('pcre.backtrack_limit', '0');

        try {
            $out = TerminalSafeText::strip("ok\e[2K\r\u{202E}\u{009B}é\tend\n");
            $failed = preg_last_error() !== PREG_NO_ERROR;
        } finally {
            ini_set('pcre.jit', $jit);
            ini_set('pcre.backtrack_limit', $limit);
        }

        $this->assertTrue($failed, 'the forced PCRE failure did not happen — this test measured nothing');
        $this->assertSame(0, preg_match('/[^\x20-\x7E\n\t]/', $out), bin2hex($out));
        $this->assertStringContainsString('ok', $out);
        $this->assertStringContainsString("end\n", $out);
        $this->assertStringContainsString("\t", $out);
    }
}
