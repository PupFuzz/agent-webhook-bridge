<?php

namespace Tests\Unit\Tools;

use App\Bridge\Tools\BoardToolArgs;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * {@see BoardToolArgs} must normalise a caller string EXACTLY as the HTTP door's global
 * `TrimStrings` middleware does — that identity is the whole deliverable of card#9155, and
 * it is asserted against the MIDDLEWARE ITSELF rather than against a fixture list of
 * codepoints. A fixture would be a copy of `Str::INVISIBLE_CHARACTERS`, i.e. the very
 * restatement the primitive exists to remove: it would stay green while the framework
 * widened its set underneath both doors.
 *
 * ⭐ THE POPULATION IS DERIVED FROM THE FRAMEWORK'S OWN CONSTANT ON EVERY RUN. Reading
 * `Str::INVISIBLE_CHARACTERS` is reading Laravel's set, not restating it, so a framework
 * upgrade that adds a codepoint adds a case here without anybody editing this file.
 */
class BoardToolArgsTest extends TestCase
{
    /**
     * The middleware, by identity — a subclass whose only content is exposing the protected
     * `transform()` the framework calls on every HTTP request parameter.
     */
    private function middleware(): object
    {
        return new class extends TrimStrings
        {
            public function apply(string $value): mixed
            {
                return $this->transform('some_arg', $value);
            }
        };
    }

    /**
     * Every codepoint the framework considers invisible, parsed out of its own constant.
     *
     * @return list<string>
     */
    private function invisibleCodepoints(): array
    {
        preg_match_all('/\\\\x\{([0-9A-Fa-f]+)\}/', Str::INVISIBLE_CHARACTERS, $m);
        $this->assertNotSame([], $m[1], 'Str::INVISIBLE_CHARACTERS did not parse — the population could not be derived, which is not the same as an empty one.');

        return array_map(static fn (string $hex): string => (string) mb_chr((int) hexdec($hex), 'UTF-8'), $m[1]);
    }

    public function test_the_primitive_agrees_with_the_middleware_on_every_invisible_codepoint(): void
    {
        $middleware = $this->middleware();

        foreach ($this->invisibleCodepoints() as $char) {
            $hex = strtoupper(dechex((int) mb_ord($char, 'UTF-8')));

            $padded = $char.'a real title'.$char;
            $this->assertSame($middleware->apply($padded), BoardToolArgs::trimmed($padded), "U+{$hex} padding: the primitive and the middleware must strip the same thing");
            $this->assertSame('a real title', BoardToolArgs::trimmed($padded), "U+{$hex} padding survived the primitive");

            $this->assertTrue(BoardToolArgs::emptyAfterTrim($char), "a value of U+{$hex} alone is visually blank and must read as empty");
        }
    }

    /**
     * ⚑ THE CONTROL THAT MAKES THE TEST ABOVE CAPABLE OF FAILING. If the primitive delegated
     * to PHP's ASCII `trim()` — the implementation this card replaced — this assertion is the
     * one that reds: `trim()` strips none of the non-ASCII invisible characters, so for every
     * such codepoint the two answers differ. Without it, a regression to `trim()` would leave
     * the suite green on every ASCII-whitespace case and silently wrong on the rest.
     */
    public function test_php_ascii_trim_would_disagree_on_the_non_ascii_invisibles(): void
    {
        $disagreements = 0;
        foreach ($this->invisibleCodepoints() as $char) {
            if (trim($char) !== BoardToolArgs::trimmed($char)) {
                $disagreements++;
            }
        }

        $this->assertGreaterThan(0, $disagreements, "PHP's trim() agreed with the primitive on EVERY invisible codepoint — either the primitive has regressed to trim(), or the population was not derived.");
        $this->assertFalse(trim("\u{00A0}") === '', 'the worked case from the card: ASCII trim() leaves a non-breaking space standing');
        $this->assertTrue(BoardToolArgs::emptyAfterTrim("\u{00A0}"), 'the worked case from the card: the primitive sees it as blank');
    }

    /**
     * The other side of the same rule — the primitive must not eat a legitimate value. A
     * hand-rolled "strip anything unusual" implementation passes the arm above and fails
     * this one.
     */
    public function test_legitimate_values_survive_unchanged(): void
    {
        $middleware = $this->middleware();

        foreach ([
            'Fix the parser' => '   Fix the parser   ',
            'Café façade — ünïcode' => 'Café façade — ünïcode',
            '日本語のカード' => ' 日本語のカード ',
            "a\u{00A0}b" => "a\u{00A0}b",              // invisible INSIDE the value is content
            'ünï' => "\tünï\n",
        ] as $expected => $sent) {
            $this->assertSame((string) $expected, BoardToolArgs::trimmed($sent));
            $this->assertSame($middleware->apply($sent), BoardToolArgs::trimmed($sent));
            $this->assertFalse(BoardToolArgs::emptyAfterTrim($sent));
        }
    }
}
