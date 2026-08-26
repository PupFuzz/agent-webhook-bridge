<?php

namespace Tests\Feature\Console\Check;

use Tests\TestCase;

/**
 * `docs/check-golden-coverage.md` states a NUMBER, and a number in a generated artifact goes
 * stale silently (card#7756 review finding).
 *
 * WHAT WENT WRONG WITHOUT THIS. The file's header says the measurement covered N branch
 * predicates in `CheckCommand::handle()`. `handle()` grows; the file does not, because
 * regenerating it is a ~40-minute mutation run nobody does casually. The header then reads
 * as a current fact to the one audience that opens the file — someone deciding whether a
 * green golden suite protects the branch they are about to change — and the disclosed-gap
 * list beside it is silently incomplete.
 *
 * WHY A GUARD RATHER THAN A CORRECTED NUMBER (canon #16). Hand-editing the header to today's
 * count would state that the mutation ran over that many predicates, which it did not, and
 * would go stale again on the next predicate added. The check is instead that the file's
 * number and the LIVE enumeration agree, and that a disagreement is DECLARED in the file:
 *
 *   - they agree   ⇒ the measurement covers this source; the stale banner must be gone.
 *   - they differ  ⇒ the file must carry the stale banner, so its reader is told before
 *                    they trust the split below it.
 *
 * Both directions are enforced, which is what stops the banner becoming permanent furniture
 * that outlives the staleness it announces.
 *
 * ⛔ WHAT A GREEN RUN HERE DOES NOT BUY. It compares a DENOMINATOR and nothing else: the
 * observed / UNOBSERVED verdicts below the header are only as current as the mutation run
 * that produced them, and this test cannot re-derive them (that is the ~40-minute run). It
 * says nothing about whether any particular predicate is protected, and absence from the
 * disclosed gaps list is not protection in either direction — the file is bounded to
 * `handle()`, so a migrated check has no predicates there at all.
 *
 * ⚑ THE LIVE COUNT COMES FROM THE ENUMERATOR, NOT FROM A REGEX HERE. `bin/check-golden-
 * predicates.php` is the same instrument `bin/check-golden-mutate.php` drives to produce the
 * file, so this compares the artifact against the thing that wrote it. A second
 * implementation of "count the predicates" could disagree with the generator and red on its
 * own defect.
 */
class CheckGoldenCoverageCurrencyTest extends TestCase
{
    private const DOC = 'docs/check-golden-coverage.md';

    private const STALE_BANNER = '> ⚠ **STALE — the counts below are NOT this branch\'s';

    public function test_the_stated_predicate_count_is_current_or_the_file_says_it_is_not(): void
    {
        $doc = (string) file_get_contents(base_path(self::DOC));
        $this->assertSame(
            1,
            preg_match('/(\d+) branch predicates in `handle\(\)`/', $doc, $m),
            self::DOC.' no longer states a predicate count in the header this guard reads. If the generator\'s wording moved, move this pattern with it — do not delete the guard.',
        );
        $stated = (int) $m[1];
        $live = $this->livePredicateCount();

        if ($stated === $live) {
            $this->assertStringNotContainsString(
                self::STALE_BANNER,
                $doc,
                self::DOC." states {$stated} predicates and `handle()` has {$live}, so the measurement is current — but the file still carries the STALE banner. Remove it; a banner that outlives its staleness trains readers to ignore it.",
            );

            return;
        }

        $this->assertStringContainsString(
            self::STALE_BANNER,
            $doc,
            self::DOC." says the mutation run covered {$stated} branch predicates in `handle()`, and the live enumeration finds {$live}. The counts below are therefore not this branch's: either re-run `php bin/check-golden-mutate.php` (~40 minutes) or add the STALE banner under the header so a reader is told before trusting the disclosed-gap list.",
        );
    }

    /**
     * The count the generator itself would use, obtained by running the enumerator.
     *
     * Non-vacuous by construction: a script that fails to run, or prints something that is
     * not a JSON list, fails the test here rather than returning a plausible zero that would
     * make the comparison above pass by accident on some branch.
     */
    private function livePredicateCount(): int
    {
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg(base_path('bin/check-golden-predicates.php')).' --json 2>/dev/null';
        exec($cmd, $lines, $code);

        $this->assertSame(0, $code, 'bin/check-golden-predicates.php did not exit 0, so this run measured nothing about the doc.');
        $decoded = json_decode(implode("\n", $lines), true);
        $this->assertIsArray($decoded, 'bin/check-golden-predicates.php --json did not produce a list.');
        $this->assertNotEmpty($decoded, 'the predicate enumeration came back EMPTY — an empty result is a measurement that never happened.');

        return count($decoded);
    }
}
