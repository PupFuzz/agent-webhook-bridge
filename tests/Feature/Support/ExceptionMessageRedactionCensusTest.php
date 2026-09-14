<?php

namespace Tests\Feature\Support;

use Tests\Support\SourceScan;
use Tests\TestCase;

/**
 * No `getMessage()` in `app/` is handed straight to a redactor or an escape — every one goes
 * through `App\Bridge\Support\RedactedErrorText::of()` (card#9486).
 *
 * ⛔ WHY THE LITERAL IS THE DEFECT. A `RequestException`'s message is already cut at
 * `RequestException::$truncateAt`, so redacting or escaping it afterwards works on a fragment:
 * a password cut before its `@` survives the scrub. A call site that wraps `getMessage()` in a
 * scrubber is the one place an author has shown the text needs to be SAFE, and whether that
 * exception can be a `RequestException` is a property of the callee, which can change without
 * the site changing. So the only disposition is the primitive's own read.
 *
 * ⚠ WHAT IT DOES NOT SEE, stated so a green is not read as more:
 *  - It is LEXICAL over one statement: a message stored in a variable and scrubbed later, or
 *    read inside a closure body passed to the scrubber, is not a site.
 *  - It reads only the three wrappers named in {@see self::WRAPPERS}. A relay with NO wrapper —
 *    `'error' => $e->getMessage()` in a log context — is a different question (is the text
 *    foreign at all?) and is not answered here.
 *  - It cannot see a message that already EMBEDS a truncated one (`new X('…'.$e->getMessage())`).
 */
class ExceptionMessageRedactionCensusTest extends TestCase
{
    /** Class => the static methods of it whose argument is text made safe to show. `null` = every one. */
    private const WRAPPERS = [
        'SecretScrubber' => null,
        'UntrustedText' => null,
        'OutputFormatter' => ['escape'],
    ];

    /**
     * The sites that are the primitive itself, and why each is not the defect. Set-equal to what
     * the scan finds, so a disposition whose site has gone reds as stale.
     *
     * @var array<string, string>
     */
    private const DISPOSITIONS = [
        'Bridge/Support/RedactedErrorText.php::of#1' => 'the primitive\'s own non-RequestException branch — no response summary is cut into that message',
    ];

    public function test_no_get_message_in_app_is_handed_straight_to_a_redactor_or_an_escape(): void
    {
        $found = SourceScan::sitesInApp(self::siteAt(...));
        ksort($found);
        $disposed = self::DISPOSITIONS;
        ksort($disposed);

        $this->assertSame(
            array_keys($disposed),
            array_keys($found),
            'a getMessage() is wrapped in a redactor or an escape directly. Route it through '
            .'RedactedErrorText::of($e): a RequestException message is already truncated, and a '
            .'credential cut at that truncation survives the redactor. A disposition whose site is gone is stale: delete it.',
        );
    }

    /** The instrument's control: real sites found in both wrapper shapes, prose and near-misses skipped. */
    public function test_the_scanner_finds_a_wrapped_get_message_and_nothing_else(): void
    {
        $source = <<<'PHP'
        <?php
        class Fixture
        {
            /** SecretScrubber::text($e->getMessage()) in a docblock is prose. */
            public function wrapped(Throwable $e): void
            {
                // UntrustedText::forOperator($e->getMessage())
                $a = SecretScrubber::text($e->getMessage());
                Finding::fail('x — '.UntrustedText::forOperator('(' . $e?->getMessage() . ')'));
                $this->warn(OutputFormatter::escape(sprintf('%s', $e->getPrevious()->getMessage())));
            }

            public function notWrapped(Throwable $e): void
            {
                Log::warning('x', ['error' => $e->getMessage()]);
                $b = SecretScrubber::text('fixed');
                $c = $e->getMessage();
                $d = UntrustedText::forOperator(RedactedErrorText::of($e));
                $this->warn(OutputFormatter::format($e->getMessage()));
                $f = 'SecretScrubber::text($e->getMessage())';
            }
        }
        PHP;

        $this->assertSame(
            [
                'Fixture.php::wrapped#1' => 'SecretScrubber::text',
                'Fixture.php::wrapped#2' => 'UntrustedText::forOperator',
                'Fixture.php::wrapped#3' => 'OutputFormatter::escape',
            ],
            SourceScan::sites($source, 'Fixture.php', self::siteAt(...)),
        );
    }

    /**
     * A `->getMessage()` / `?->getMessage()` call is a site when a call enclosing it within the
     * same statement is one of {@see self::WRAPPERS}; the value is that wrapper.
     *
     * @param  list<array{0: int|string, 1: string}>  $tokens
     */
    private static function siteAt(array $tokens, int $i, int $scopeStart): ?string
    {
        if ($tokens[$i][0] !== T_STRING || $tokens[$i][1] !== 'getMessage'
            || ! in_array($tokens[$i - 1][0] ?? null, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
            || ($tokens[$i + 1][1] ?? null) !== '(') {
            return null;
        }

        $depth = 0;
        for ($j = $i - 1; $j >= $scopeStart; $j--) {
            $text = $tokens[$j][1];
            if ($text === ')') {
                $depth++;
            } elseif ($text === '(' && $depth > 0) {
                $depth--;
            } elseif ($text === '(') {
                $wrapper = self::wrapperBefore($tokens, $j);
                if ($wrapper !== null) {
                    return $wrapper;
                }
            } elseif ($depth === 0 && in_array($text, [';', '{', '}'], true)) {
                return null;
            }
        }

        return null;
    }

    /** @param  list<array{0: int|string, 1: string}>  $tokens */
    private static function wrapperBefore(array $tokens, int $open): ?string
    {
        $method = $tokens[$open - 1] ?? null;
        $colons = $tokens[$open - 2] ?? null;
        $class = $tokens[$open - 3] ?? null;
        if ($method === null || $colons === null || $class === null
            || $method[0] !== T_STRING || $colons[0] !== T_DOUBLE_COLON) {
            return null;
        }

        $short = substr((string) strrchr('\\'.$class[1], '\\'), 1);
        if (! array_key_exists($short, self::WRAPPERS)) {
            return null;
        }
        $methods = self::WRAPPERS[$short];

        return $methods === null || in_array($method[1], $methods, true) ? $short.'::'.$method[1] : null;
    }
}
