<?php

namespace Tests\Unit\Console;

use App\Bridge\Check\CheckDisposition;
use App\Bridge\Check\CheckInventory;
use App\Console\Commands\Bridge\CheckCommand;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The composition of `bridge:check`'s inventory disclosure (DL-242 stage 8).
 *
 * WHY IT IS A UNIT AND NOT MORE GOLDEN FIXTURES. The composition has five predicates and
 * ten arms; the 33-fixture corpus renders six of them and never the other four. It renders
 * neither zero-arm (every fixture has both an un-requested opt-in probe and a not-run
 * plane), never a not-run population with no recorded reason, and never the
 * internal-defect disclosure — so before this file, DELETING the whole `bridge:check
 * internal:` block reds nothing, and changing its text reds nothing. The corpus' only
 * statement about it is `assertStringNotContainsString('bridge:check internal:',
 * $minimal)`, which is an ABSENCE assertion: it passes on day one and forever, including
 * against a renderer that cannot emit the string at all. This is the presence witness that
 * absence assertion needs, and reaching those arms needs an inventory no install shape in
 * the corpus produces.
 *
 * The two methods under test are private and pure, reached by reflection: what is under
 * test IS the composition, and a check-command run can only exhibit the arms some install
 * shape happens to produce — which is the gap, not the instrument.
 */
class CheckInventoryLineTest extends TestCase
{
    private function line(CheckInventory $inventory): string
    {
        $method = new ReflectionMethod(CheckCommand::class, 'inventoryLine');

        return (string) $method->invoke(new CheckCommand, $inventory);
    }

    private function internal(CheckInventory $inventory): ?string
    {
        $method = new ReflectionMethod(CheckCommand::class, 'inventoryInternalDefectLine');
        $out = $method->invoke(new CheckCommand, $inventory);

        return $out === null ? null : (string) $out;
    }

    /**
     * An inventory holding exactly the given populations, ids generated per disposition.
     *
     * Built directly rather than by driving a `CheckRunner`: the shapes below include ones
     * no install produces, which is the entire reason this file exists.
     *
     * @param  array<string, string>  $reasons  reason keyed by not-run ORDINAL (`not-run-0`)
     */
    private function inventory(
        int $reported = 0,
        int $silent = 0,
        int $notRequested = 0,
        int $notRun = 0,
        array $reasons = [],
    ): CheckInventory {
        $dispositions = [];
        foreach ([
            'reported' => [$reported, CheckDisposition::Reported],
            'silent' => [$silent, CheckDisposition::Silent],
            'not-requested' => [$notRequested, CheckDisposition::NotRequested],
            'not-run' => [$notRun, CheckDisposition::NotRun],
        ] as $prefix => [$count, $disposition]) {
            for ($i = 0; $i < $count; $i++) {
                $dispositions["{$prefix}-{$i}"] = $disposition;
            }
        }

        return new CheckInventory($dispositions, $reasons);
    }

    // ---- the always-present head ----

    public function test_the_head_states_the_registered_total_and_how_the_run_split(): void
    {
        $line = $this->line($this->inventory(reported: 2, silent: 3));

        $this->assertSame(
            'checks: 5 registered · 5 ran (2 reported above, 3 with nothing to report). '
                .'All 5 are accounted for — nothing was skipped uncounted.',
            $line,
        );
    }

    // ---- the opt-in arm: zero, one, many ----

    public function test_no_unrequested_opt_in_probe_prints_no_opt_in_segment(): void
    {
        // The zero-arm. No golden fixture renders it — every one of the 33 leaves at least
        // one probe flag off — so without this the `> 0` guard could be deleted and the
        // line would read `0 opt-in probes not requested` on every healthy install with
        // nothing red.
        $line = $this->line($this->inventory(reported: 1, notRequested: 0));

        $this->assertStringNotContainsString('opt-in', $line);
    }

    public function test_one_unrequested_opt_in_probe_is_singular(): void
    {
        $line = $this->line($this->inventory(reported: 1, notRequested: 1));

        $this->assertStringContainsString('· 1 opt-in probe not requested', $line);
        $this->assertStringNotContainsString('probes', $line);
    }

    public function test_several_unrequested_opt_in_probes_are_plural(): void
    {
        $line = $this->line($this->inventory(reported: 1, notRequested: 3));

        $this->assertStringContainsString('· 3 opt-in probes not requested', $line);
    }

    // ---- the not-run arm: zero, with reasons, without ----

    public function test_a_run_that_reached_every_check_prints_no_not_run_segment(): void
    {
        // The other zero-arm, and the one an operator most wants to be able to trust:
        // silence here means every registered check was reached. No fixture renders it —
        // the writeback plane alone is 9 not-run checks on the baseline install.
        $line = $this->line($this->inventory(reported: 4, silent: 1));

        $this->assertStringNotContainsString('did not run', $line);
        $this->assertStringContainsString('5 ran (4 reported above, 1 with nothing to report)', $line);
    }

    public function test_not_run_checks_are_counted_and_their_reasons_listed_once(): void
    {
        $line = $this->line($this->inventory(
            reported: 1,
            notRun: 3,
            reasons: [
                'not-run-0' => 'no writeback.json',
                'not-run-1' => 'no writeback.json',
                'not-run-2' => 'no board_tools block',
            ],
        ));

        // Deduplicated: one envelope closing accounts for several checks, and the operator
        // needs the cause once, not once per check.
        $this->assertStringContainsString('· 3 did not run (no writeback.json; no board_tools block)', $line);
    }

    public function test_not_run_checks_with_no_recorded_reason_still_state_the_count(): void
    {
        // The reasonless arm, which the corpus cannot render: it needs a not-run check
        // whose envelope recorded nothing, and `CheckCommand` records a reason at every
        // envelope it has. Degrading to a bare count is the whole design — the check stays
        // COUNTED when the explanation is missing.
        $line = $this->line($this->inventory(reported: 1, notRun: 2));

        $this->assertStringContainsString('· 2 did not run.', $line);
        $this->assertStringNotContainsString('did not run (', $line);
    }

    public function test_the_not_run_label_never_claims_the_checks_were_inapplicable(): void
    {
        // The label is `did not run` and not `not applicable here` because several of the
        // reasons `CheckCommand` records are COULD-NOT-LOOK — "no agent config parsed (see
        // the errors above)" is an exit-1 install where the agent plane is fully
        // applicable and merely unmeasured. The weakest label covers the union; the
        // stronger one was a false claim on those installs.
        $line = $this->line($this->inventory(
            reported: 1,
            notRun: 1,
            reasons: ['not-run-0' => 'no agent config parsed (see the errors above)'],
        ));

        $this->assertStringNotContainsString('not applicable', $line);
    }

    // ---- the arithmetic that is the line's own control ----

    public function test_every_disposition_reaches_the_line_so_the_counts_conserve(): void
    {
        $line = $this->line($this->inventory(
            reported: 2,
            silent: 1,
            notRequested: 2,
            notRun: 4,
            reasons: ['not-run-0' => 'no writeback.json'],
        ));

        $this->assertSame(
            'checks: 9 registered · 3 ran (2 reported above, 1 with nothing to report)'
                .' · 2 opt-in probes not requested · 4 did not run (no writeback.json).'
                .' All 9 are accounted for — nothing was skipped uncounted.',
            $line,
        );
    }

    // ---- the internal-defect disclosure ----

    public function test_a_not_run_check_with_no_reason_is_disclosed_by_id(): void
    {
        // THE PRESENCE WITNESS. `CheckGoldenTest` asserts this string is ABSENT from a
        // healthy run, which cannot tell a working disclosure from a deleted one.
        $internal = $this->internal($this->inventory(
            reported: 1,
            notRun: 2,
            reasons: ['not-run-0' => 'no writeback.json'],
        ));

        $this->assertNotNull($internal);
        $this->assertStringContainsString('bridge:check internal: 1 registered check(s) did not run', $internal);
        $this->assertStringContainsString('(not-run-1)', $internal);
        // Named, not merely counted: an operator reporting the bug needs the id, and the
        // count alone would not say which envelope forgot.
        $this->assertStringNotContainsString('not-run-0', $internal);
    }

    public function test_every_not_run_check_having_a_reason_discloses_nothing(): void
    {
        $this->assertNull($this->internal($this->inventory(
            reported: 1,
            notRun: 1,
            reasons: ['not-run-0' => 'no writeback.json'],
        )));
    }

    public function test_a_run_with_nothing_unexplained_and_nothing_not_run_discloses_nothing(): void
    {
        $this->assertNull($this->internal($this->inventory(reported: 3)));
    }
}
