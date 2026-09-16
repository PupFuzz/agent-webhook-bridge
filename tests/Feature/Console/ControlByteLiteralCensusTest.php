<?php

namespace Tests\Feature\Console;

use Tests\Support\SourceScan;
use Tests\TestCase;

/**
 * Does `app/` put a byte of the choke's stripped class on the console ON PURPOSE? (card#9251,
 * DL-393.) The choke removes C0 except `\n`/`\t`, DEL, C1 and `\p{Cf}` from every write, so an
 * install-authored progress `\r`, cursor move or colour escape would be silently eaten. This
 * is the population of source literals that could produce one, each ruled.
 *
 * ⭐ A TOKEN SCAN OF STRING LITERALS, not `grep '\r'` — a grep for a backslash-r matches every
 * file, and a grep for `\x1b[` cannot see `"\e["`, `"\033["`, `"\u{1b}"` or `chr(27)`. Only an
 * INTERPOLATING literal (double-quoted, heredoc) decodes an escape, so a single-quoted regex
 * pattern such as `'/[\x00-\x1F]/'` is correctly not a site; a raw byte of the class counts in
 * any literal. A `chr()`/`mb_chr()` whose argument is not an integer literal is reported as
 * undecidable by name.
 */
class ControlByteLiteralCensusTest extends TestCase
{
    /**
     * site => [descriptor, why it never reaches the console]
     *
     * @var array<string, array{string, string}>
     */
    private const RULINGS = [
        'Bridge/Tools/PublicKeyLineShape.php::isSingleAuthorizedKeyLine#1' => ['\r', 'a str_contains() refusal test on a candidate key line'],
        'Bridge/Validation/SocketPath.php::isValid#1' => ['\x00', 'a str_contains() refusal test on a socket path'],
        'Bridge/Writeback/PrCorrelationCommenter.php::post#1' => ['\x00', 'a delimiter inside the in-memory $attempted dedupe key'],
        'Bridge/Writeback/PrCorrelationCommenter.php::post#2' => ['\x00', 'a delimiter inside the in-memory $attempted dedupe key'],
        'Bridge/Writeback/WritebackAlertNotifier.php::emitMoveFailed#1' => ['\x00', 'a delimiter inside the dedupe key emit() hands to claimSignature(), which hashes it'],
        'Bridge/Writeback/WritebackAlertNotifier.php::emitMoveFailed#2' => ['\x00', 'a delimiter inside the dedupe key emit() hands to claimSignature(), which hashes it'],
        'Http/Middleware/LoopbackOnly.php::isLoopback#1' => ['\x7f', 'a byte compare against a packed IPv4-mapped address'],
    ];

    private const ESCAPE = '/\\\\(\\\\|e|r|v|f|[0-7]{1,3}|x[0-9A-Fa-f]{1,2}|u\{[0-9A-Fa-f]+\})/';

    public function test_every_literal_that_can_produce_a_stripped_byte_is_ruled(): void
    {
        $found = SourceScan::sitesInApp(self::siteAt(...));

        $ruled = array_map(static fn (array $r): string => $r[0], self::RULINGS);
        ksort($found);
        ksort($ruled);

        $this->assertSame($ruled, $found, 'a literal carrying a byte the output choke strips appeared or moved — rule whether it reaches the console');
    }

    /** ⭐ THE CONTROL: each spelling is found, each near miss is not. */
    public function test_the_scan_finds_each_planted_spelling_and_skips_each_near_miss(): void
    {
        $plant = '<?php
function planted($n, $x) {
    $a = "\e[2K";
    $b = "\x1b[0m";
    $c = "\033[1A";
    $d = "\u{1b}";
    $e = chr(27);
    $f = "\u{009B}";
    $g = "\x9b";
    $h = "line\r";
    $i = "\u{202E}";
    $j = \''."\x1B".'raw\';
    $k = chr($n);
    $l = "{$x}\r";
    $m = <<<EOT
\e
EOT;
    $n1 = \'single \e \x1b \r\';
    $n2 = "\\\\e and \\\\r";
    $n3 = "\n\t";
    $n4 = <<<\'EOT\'
\e
EOT;
    $n5 = chr(65);
    $n6 = "\u{E9}";
    $n7 = $o->chr(27);
}
';

        $this->assertSame([
            'Plant.php::planted#1' => '\e',
            'Plant.php::planted#2' => '\x1b',
            'Plant.php::planted#3' => '\033',
            'Plant.php::planted#4' => '\u{1b}',
            'Plant.php::planted#5' => 'chr(27)',
            'Plant.php::planted#6' => '\u{009B}',
            'Plant.php::planted#7' => '\x9b',
            'Plant.php::planted#8' => '\r',
            'Plant.php::planted#9' => '\u{202E}',
            'Plant.php::planted#10' => 'raw byte in literal',
            'Plant.php::planted#11' => 'chr(undecidable: $n)',
            'Plant.php::planted#12' => '\r',
            'Plant.php::planted#13' => '\e',
        ], SourceScan::sites($plant, 'Plant.php', self::siteAt(...)));
    }

    /** @param  list<array{0: int|string, 1: string}>  $tokens */
    private static function siteAt(array $tokens, int $i, int $scopeStart): ?string
    {
        [$type, $text] = $tokens[$i];

        if ($type === T_STRING && in_array(strtolower($text), ['chr', 'mb_chr'], true)
            && ($tokens[$i + 1][1] ?? null) === '('
            && ! in_array($tokens[$i - 1][0] ?? null, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) {
            $argument = $tokens[$i + 2] ?? null;
            if ($argument !== null && $argument[0] === T_LNUMBER && ($tokens[$i + 3][1] ?? null) === ')') {
                return self::inClass(intval($argument[1], 0), strtolower($text) === 'chr') ? strtolower($text).'('.$argument[1].')' : null;
            }

            return strtolower($text).'(undecidable: '.($argument[1] ?? '').')';
        }

        if (! in_array($type, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true)) {
            return null;
        }

        if (preg_match('/[\x00-\x08\x0B-\x1F\x7F]/', $text) === 1
            || preg_match('/[\x{0080}-\x{009F}]|\p{Cf}/u', mb_scrub($text, 'UTF-8')) === 1) {
            return 'raw byte in literal';
        }

        if (! self::interpolates($tokens, $i) || preg_match_all(self::ESCAPE, $text, $m) === 0) {
            return null;
        }
        $hits = array_values(array_unique(array_filter($m[1], self::escapeInClass(...))));

        return $hits === [] ? null : implode(' ', array_map(static fn (string $e): string => '\\'.$e, $hits));
    }

    private static function escapeInClass(string $escape): bool
    {
        return match (true) {
            $escape === '\\' => false,
            $escape === 'e', $escape === 'r', $escape === 'v', $escape === 'f' => true,
            $escape[0] === 'x' => self::inClass((int) hexdec(substr($escape, 1)), true),
            $escape[0] === 'u' => self::inClass((int) hexdec(trim(substr($escape, 1), '{}')), false),
            default => self::inClass(octdec($escape) & 0xFF, true),
        };
    }

    /** A BYTE escape in 0x80–0x9F is a C1 byte on its own; a CODEPOINT is tested as the codepoint. */
    private static function inClass(int $value, bool $isByte): bool
    {
        if ($value <= 0x08 || ($value >= 0x0B && $value <= 0x1F) || ($value >= 0x7F && $value <= 0x9F)) {
            return true;
        }

        return ! $isByte && preg_match('/\p{Cf}/u', (string) mb_chr($value, 'UTF-8')) === 1;
    }

    /**
     * Whether the literal at $i decodes escapes: a double-quoted string, or a heredoc — not a
     * single-quoted string, a nowdoc, or inline HTML.
     *
     * @param  list<array{0: int|string, 1: string}>  $tokens
     */
    private static function interpolates(array $tokens, int $i): bool
    {
        [$type, $text] = $tokens[$i];
        if ($type === T_CONSTANT_ENCAPSED_STRING) {
            return str_starts_with($text, '"') || str_starts_with(strtolower($text), 'b"');
        }
        if ($type !== T_ENCAPSED_AND_WHITESPACE) {
            return false;
        }
        for ($j = $i - 1; $j >= 0; $j--) {
            if ($tokens[$j][0] === '"') {
                return true;
            }
            if ($tokens[$j][0] === T_START_HEREDOC) {
                return ! str_contains($tokens[$j][1], "'");
            }
        }

        return false;
    }
}
