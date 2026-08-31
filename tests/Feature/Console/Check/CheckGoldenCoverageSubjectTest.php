<?php

namespace Tests\Feature\Console\Check;

use Tests\Support\CheckGolden\ReadsGoldenPredicates;
use Tests\TestCase;

/**
 * `docs/check-golden-coverage.md` publishes a table of CONDITION STRINGS, and a condition
 * string in a generated artifact goes stale silently even when the count beside it does not
 * (card#7992).
 *
 * WHAT THIS TERM IS FOR, AND WHY IT IS A SEPARATE ONE. `CheckGoldenCoverageCurrencyTest`
 * compares the header's stated predicate COUNT against the live enumeration. That guard is
 * correct about what it claims and other readers rely on its predicate, so this is a new term
 * beside it rather than a widening of it. A count is a DENOMINATOR: it cannot see a predicate
 * set that changed identity while keeping its size. Measured, not hypothetical — between two
 * regenerations of this artifact one predicate DEPARTED and two arrived, so the published
 * gap table named a condition (`$configs !== [] && $ctx->configDir !== null`) that no longer
 * existed anywhere in the source. The count moved by one that time and would have caught it by
 * luck; one departure against one arrival is the same defect with the count standing still.
 *
 * WHY THAT MATTERS TO A READER. The disclosed-gap table's `condition` column is what someone
 * refactoring stage 1-7 actually greps for. A row naming a condition the tree no longer holds
 * either finds nothing — and reads as the reader's own mistake — or worse, matches a different
 * predicate that was never measured.
 *
 * ⚑ THE SUBJECT IS `(kind, source)`, NOT THE PREDICATE ID. Ids embed a line offset, so any edit
 * ABOVE a predicate renumbers every id below it; comparing on ids would red on pure line drift,
 * which is noise rather than staleness. `(kind, source)` is the identity the artifact actually
 * PRINTS, and it is compared as a MULTISET and never as a set — several predicates share one
 * condition text verbatim, so deduplicating would hide a departure behind its own twin.
 *
 * ⛔ WHAT A GREEN RUN HERE DOES NOT BUY. It compares the measurement's SUBJECT — which
 * predicates were measured — and says NOTHING about its RESULT. The observed /
 * observed-via-abort / UNOBSERVED verdicts are not re-derived by this term or by any other
 * guard in this repo, because re-deriving them is the ~57-minute mutation run. A regenerated
 * golden capture, a changed check implementation, a changed config default, or any other input
 * that run executes can move a verdict with this term green. It also says nothing about whether
 * any particular predicate is protected: absence from the disclosed-gap list is not protection
 * in either direction, the file being bounded to `CheckCommand::handle()` alone.
 *
 * ⚑ ONE FURTHER BOUND, because this term reads the JSON and banners the MARKDOWN. That the
 * rendered table was built from the record compared here is the GENERATOR's guarantee — it writes
 * the pair in sequence and reports loudly when only one write lands — and it is asserted nowhere.
 * A pair that disagreed would leave this term green about the record while the table a reader
 * opens came from an older run.
 *
 * ⚑ THE LIVE SET COMES FROM THE ENUMERATOR the generator itself drives, via
 * {@see ReadsGoldenPredicates}, so this compares the artifact against the thing that wrote it
 * rather than against a second opinion that could red on its own defect.
 */
class CheckGoldenCoverageSubjectTest extends TestCase
{
    use ReadsGoldenPredicates;

    private const DOC = 'docs/check-golden-coverage.md';

    private const ARTIFACT = 'docs/check-golden-coverage.json';

    /**
     * Deliberately NOT the currency guard's banner. That one declares a stale DENOMINATOR;
     * this one declares a moved SUBJECT. One string for two claims would make each guard's
     * remedy satisfy the other's assertion, and the currency guard requires ITS banner to be
     * ABSENT whenever the count agrees — so a shared string would deadlock the pair on the
     * exact case this term exists for: the subject moving while the count stands still.
     */
    private const SUBJECT_BANNER = '> ⚠ **SUBJECT MOVED — the predicates described below are NOT this branch\'s';

    public function test_the_measured_predicate_set_is_this_branch_s_or_the_file_says_it_is_not(): void
    {
        $measured = $this->measuredConditionCounts();
        $live = $this->conditionCounts($this->livePredicates(), 'the live enumeration');
        $doc = (string) file_get_contents(base_path(self::DOC));

        if ($measured === $live) {
            $this->assertStringNotContainsString(
                self::SUBJECT_BANNER,
                $doc,
                self::DOC.' describes exactly the branch predicates `handle()` holds, so its subject is '
                    .'current — but the file still carries the SUBJECT MOVED banner. Remove it; a banner '
                    .'that outlives the staleness it announces trains readers to ignore it.',
            );

            return;
        }

        $this->assertStringContainsString(
            self::SUBJECT_BANNER,
            $doc,
            'The measurement in '.self::ARTIFACT.' was taken over a different set of branch predicates '
                .'than `CheckCommand::handle()` holds now, so the condition strings '.self::DOC.' prints '
                ."no longer identify predicates in this source.\n\n"
                ."MEASURED but no longer present:\n".$this->render($this->surplus($measured, $live))
                ."PRESENT but never measured:\n".$this->render($this->surplus($live, $measured))
                ."\nEither re-run `php bin/check-golden-mutate.php` (~57 minutes) or add the SUBJECT MOVED "
                .'banner under the header, so a reader is told before they grep for a condition that is '
                ."not there.\n\n"
                .'SCOPE OF THIS TERM, so the banner is not read for more than it says: it compares WHICH '
                .'PREDICATES WERE MEASURED and nothing about the observed / observed-via-abort / '
                .'UNOBSERVED verdicts. Those are only as current as the mutation run that produced them '
                .'and NO guard re-derives them — a regenerated golden capture, a changed check '
                .'implementation or a changed config default moves a verdict with this term green.',
        );
    }

    /**
     * The predicate set the committed measurement was actually taken over.
     *
     * Read from the JSON rather than the rendered table because the table prints only the
     * UNOBSERVED and observed-via-abort rows — the `observed` majority appears nowhere in it,
     * so the Markdown cannot answer what was measured.
     *
     * @return array<string, int>
     */
    private function measuredConditionCounts(): array
    {
        $raw = file_get_contents(base_path(self::ARTIFACT));
        $this->assertIsString($raw, self::ARTIFACT.' could not be read, so this run measured nothing about the artifact.');

        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, self::ARTIFACT.' is not a JSON list; the generator writes one, so this file did not come from it intact.');
        $this->assertNotEmpty($decoded, self::ARTIFACT.' is EMPTY — an empty result is a measurement that never happened, not a measurement of zero predicates.');

        /** @var list<array<string, mixed>> $decoded */
        return $this->conditionCounts($decoded, self::ARTIFACT);
    }

    /**
     * `(kind, source)` occurrence counts — a multiset, keyed on a separator that cannot occur
     * in PHP source so two distinct predicates can never collide into one key.
     *
     * The $origin label is not decoration: both the committed artifact and the live
     * enumeration are parsed here, and a malformed-record failure that does not name which of
     * the two it came from sends the reader to the wrong file.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function conditionCounts(array $rows, string $origin): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $this->assertArrayHasKey('kind', $row, "a predicate record from {$origin} carries no `kind`, so it cannot be compared.");
            $this->assertArrayHasKey('source', $row, "a predicate record from {$origin} carries no `source`, so it cannot be compared.");
            $key = ((string) $row['kind'])."\0".((string) $row['source']);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }

    /**
     * Entries of $a beyond what $b holds, counting duplicates — the multiset difference.
     *
     * @param  array<string, int>  $a
     * @param  array<string, int>  $b
     * @return list<string>
     */
    private function surplus(array $a, array $b): array
    {
        $out = [];
        foreach ($a as $key => $count) {
            $extra = $count - ($b[$key] ?? 0);
            if ($extra > 0) {
                [$kind, $source] = explode("\0", $key, 2);
                $out[] = sprintf('%d× %s `%s`', $extra, $kind, $source);
            }
        }

        return $out;
    }

    /** @param  list<string>  $entries */
    private function render(array $entries): string
    {
        if ($entries === []) {
            return "  (none)\n";
        }

        return '  '.implode("\n  ", $entries)."\n";
    }
}
