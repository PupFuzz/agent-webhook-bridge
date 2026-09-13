<?php

namespace Tests\Unit\Bridge\Support;

use App\Bridge\Support\ForeignText;
use App\Bridge\Support\UntrustedText;
use Error;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;
use Tests\TestCase;

/**
 * THE CHOKEPOINT ON THE VALUE, NOT ON THE SINK (card#9200, DL-366).
 *
 * ⭐ WHAT IS UNDER TEST IS AN ABSENCE — `ForeignText` has no `__toString()` — so the
 * assertions are about what PHP REFUSES to do with one. That is the whole mechanism:
 * interpolating the value is a phpstan level-7 error at build time AND a runtime `Error` at
 * the interpolation, so a producer of foreign text that reaches an operator sink is a broken
 * build rather than a line nobody notices.
 *
 * ⛔ THE BUILD-TIME HALF IS NOT ASSERTED HERE, AND THE GAP IS NAMED RATHER THAN IMPLIED. A
 * phpunit test cannot run phpstan, and the analyser's own scope decides where that half
 * exists at all: `phpstan-laravel.neon`'s `paths` cover `app/Bridge` plus four named files,
 * and `app/Console/Commands/Bridge/ReconcileCommand.php` — where the two measured `head_ref`
 * sinks live (card#9266) — is NOT among them. So on that file the guarantee is the runtime
 * `Error` alone, which is what {@see self::test_interpolating_a_foreign_text_raises_an_error}
 * asserts over the exact expression that file used to carry. Widening the analyser's scope
 * needs six pre-existing level-7 findings in that command cleared first, two of which are
 * behaviour rulings; that is its own change and it is not laundered in here.
 */
class ForeignTextTest extends TestCase
{
    /** The hostile ref card#9266 measured GitHub accepting and returning byte-identical. */
    private const HOSTILE = "fix/\u{202E}drofnats-elif\u{200B} \x1B[2J \u{009B}2K";

    /**
     * ⛔ THE MECHANISM IS THE MISSING `__toString()`, asserted as a fact about the class and
     * not inferred from the throw below — a future revision that added one would keep every
     * other assertion in this file green while silently re-opening card#9200.
     */
    public function test_the_class_has_no_string_conversion_at_all(): void
    {
        $this->assertFalse(
            (new ReflectionClass(ForeignText::class))->hasMethod('__toString'),
            'ForeignText must have no __toString(): its ABSENCE is the guard. Adding one — even '
                .'"just for tests" — makes every interpolation compile and run again, which is the '
                .'state card#9200 records.',
        );
    }

    /**
     * ⛔ THE RUNTIME RED, WATCHED ON THE REAL EXPRESSION. This is byte-for-byte the
     * interpolation `ReconcileCommand` carried at the two sinks card#9266 measured live.
     *
     * ⚑ THE CONTROL IS THE SECOND HALF, and without it this proves nothing: the SAME
     * expression over a bare `string` must NOT throw, and must put a live ESC on the line. A
     * test that only watched the throw would also pass if `Error` were being raised for some
     * unrelated reason.
     */
    public function test_interpolating_a_foreign_text_raises_an_error(): void
    {
        $headRef = ForeignText::of(self::HOSTILE);

        try {
            /** @phpstan-ignore-next-line the refusal IS the subject */
            $line = "PR is merged but takes NEITHER closure route (head branch ref '{$headRef}')";
            $this->fail("no Error was raised — the chokepoint is not holding; got: {$line}");
        } catch (Error $e) {
            $this->assertStringContainsString('could not be converted to string', $e->getMessage());
        }

        // THE CONTROL, one variable away: a bare string reaches the same line, and carries a
        // live erase-display onto it.
        $bare = self::HOSTILE;
        $control = "PR is merged but takes NEITHER closure route (head branch ref '{$bare}')";
        $this->assertStringContainsString("\x1B", $control, 'the control must actually carry a live ESC, or the throw above measures nothing');
    }

    /**
     * The sanctioned display exit delegates to the ONE owner of the rule rather than carrying
     * a second escape — asserted by deriving the expectation from that owner, because a
     * hand-written `\x{202E}` here would be that second implementation.
     */
    public function test_for_operator_delegates_to_the_one_escape(): void
    {
        $this->assertSame(
            UntrustedText::forOperator(self::HOSTILE),
            ForeignText::of(self::HOSTILE)->forOperator(),
        );
        // And it is actually safe, not merely equal to something: no live member of the
        // escaped class survives, and the escaped form is present.
        $rendered = ForeignText::of(self::HOSTILE)->forOperator();
        $this->assertSame(0, preg_match_all('/[\x00-\x09\x0B-\x1F\x7F]|[\x{0080}-\x{009F}]|\p{Cf}/u', $rendered));
        $this->assertStringContainsString('\x{202E}', $rendered);
        $this->assertStringContainsString('\x9B', $rendered);
    }

    /**
     * The matching exit keeps the bytes BYTE-IDENTICAL, which is the whole reason the value is
     * a type here instead of being escaped at its producer: `RevertGrammar` and
     * `NoCloseGrammar` match on the raw ref, and an escaped ref matches nothing.
     */
    public function test_raw_for_matching_is_byte_identical_to_the_source(): void
    {
        $this->assertSame(self::HOSTILE, ForeignText::of(self::HOSTILE)->rawForMatching());
        $this->assertSame('', ForeignText::of('')->rawForMatching());
    }

    public function test_is_empty_answers_without_unwrapping(): void
    {
        $this->assertTrue(ForeignText::of('')->isEmpty());
        $this->assertFalse(ForeignText::of(self::HOSTILE)->isEmpty());
    }

    /**
     * ⛔ EVERY USE OF THE RAW EXIT IS ENUMERATED AND RULED ON, because the exit is
     * interpolatable and so is not a boundary the language enforces.
     *
     * `rawForMatching()` is greppable and deliberate — a reviewer had to type it — but
     * "a reviewer will notice" is exactly the guarantee card#9200 exists to replace. So the
     * population is DERIVED from `app/` on every run and compared against the ruling table:
     * a new call site reds here and forces its author to write down where the raw bytes go.
     * ⚑ THE RULING IS THE POINT, not the count. Every legitimate use takes raw text to a
     * MATCHER; none takes it to an output stream, and that is the property a reader of this
     * table is checking.
     *
     * @var array<string, array{uses: int, ruling: string}>
     */
    private const RAW_USES = [
        'Bridge/Support/ForeignText.php' => [
            'uses' => 2,
            'ruling' => '✔ THE DECLARATION ITSELF ×2 — the method, and the docblock line naming it. Not call sites.',
        ],
        'Console/Commands/Bridge/ReconcileCommand.php' => [
            'uses' => 5,
            'ruling' => '✔ TO A MATCHER ×5, never to a sink — the mention-vs-closure backstop (DL-305/DL-308), '
                .'counted PER VALUE because that is what the derivation counts. `closes()` ×2 (the title and the '
                .'ref, for a closing form); `NoCloseGrammar::marks()` ×1 (the title, for the `[no-close]` '
                .'marker); `RevertGrammar::isRevert()` ×2 (both, for a revert). All three answer a boolean and '
                .'print nothing. The same two values reach the operator-facing skip line through '
                .'`forOperator()`, which is why this file needs BOTH exits. ⚑ This entry read 3 on its first '
                .'draft, from a `grep -c` that counts matching LINES while the derivation counts OCCURRENCES — '
                .'two of these three lines carry two uses each. The derivation is what corrected it, which is '
                .'the argument for deriving rather than writing a figure down.',
        ],
    ];

    public function test_every_raw_use_in_app_is_ruled_on(): void
    {
        $expected = [];
        foreach (self::RAW_USES as $file => $row) {
            $expected[$file] = $row['uses'];
        }

        $this->assertSame($expected, $this->deriveRawUses());
    }

    /**
     * ⚑ THE CONTROL FOR THE DERIVATION. A pin over a walk that returned an empty map would be
     * satisfied the moment the table were emptied to match it, so the same counter is run over
     * a synthetic source carrying two uses and must find both — and over one carrying none.
     */
    public function test_the_raw_use_derivation_discriminates(): void
    {
        $this->assertSame(2, $this->countRawUses("<?php\n\$a->rawForMatching();\n\$b->rawForMatching();\n"));
        $this->assertSame(0, $this->countRawUses("<?php\nclass Fake {}\n"));
    }

    /** @return array<string, int> */
    private function deriveRawUses(): array
    {
        $root = base_path('app');
        $found = [];
        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $uses = $this->countRawUses((string) file_get_contents($file->getPathname()));
            if ($uses > 0) {
                $found[str_replace($root.'/', '', $file->getPathname())] = $uses;
            }
        }
        ksort($found);

        return $found;
    }

    /**
     * ⚠ COMMENTS ARE COUNTED HERE, unlike `ForeignRelayAdoptionTest`'s walk, and the reason is
     * the opposite of that one's: a docblock that NAMES the raw exit is part of what a reviewer
     * of this table reads, and `ForeignText`'s own entry exists to account for exactly that.
     * Stripping comments would make its `uses` figure mean something different from every
     * other entry's.
     */
    private function countRawUses(string $source): int
    {
        return substr_count($source, 'rawForMatching()');
    }
}
