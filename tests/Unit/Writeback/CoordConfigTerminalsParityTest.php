<?php

namespace Tests\Unit\Writeback;

use App\Bridge\Writeback\CoordConfigTerminals;
use Tests\Support\MirrorParityTestCase;

/**
 * THE CHECK BEHIND THE BRIDGE'S MIRROR OF THE COORD FRAMEWORK'S TERMINAL RULE (card#10273).
 *
 * `App\Bridge\Writeback\CoordConfigTerminals` is a VENDORED copy of a rule whose home is Python —
 * `kanban_common.terminals_for_board` and `kanban-inbox-check._terminal_columns_by_board`. The
 * bridge is a PHP runtime and cannot import either, which is why the copy exists.
 *
 * ⛔ WHY THIS EXISTS AT ALL is DL-414's own argument, which this class discharges rather than
 * restates: the mirror's docblock declared an obligation to "re-port on a rule change", and
 * `CoordConfigTerminalsTest` pins LOCAL behaviour only, which says nothing about far-end agreement.
 * A declaration with no check is not a contract, it is a comment — and worse than silence, because
 * the next auditor reads it and gets confidence instead of a question.
 *
 * ⚠ THIS CLASS IS ONE HALF. It holds the mirror against the published corpus, both ways. The other
 * half — does the AUTHORITY still answer the same thing — is `bin/coord-mirror-parity.py`, which
 * imports the Python from source and runs the same file. It is deliberately not in CI: CI has no
 * copy of the coord plugin, and a check that cannot reach its subject would either be skipped
 * silently or red on every run. The corpus's `not_checked_by_this_repo` says so in the place the
 * far end reads.
 *
 * ⚠ A GREEN RUN HERE DOES NOT SAY THE TWO ENDS AGREE TODAY. It says the mirror still answers what
 * the last cross-end measurement recorded. Every leg is owned by {@see MirrorParityTestCase}.
 */
class CoordConfigTerminalsParityTest extends MirrorParityTestCase
{
    protected static function mirrorClass(): string
    {
        return CoordConfigTerminals::class;
    }

    protected static function corpusPath(): string
    {
        return 'docs/coord-terminals-parity-corpus.json';
    }

    protected static function comparatorControl(): array
    {
        return [
            'method' => 'terminalsForBoard',
            'args' => [['user_lanes' => ['stage-next']]],
            'right' => ['Done'],
            'wrong' => [],
            'wrong_fragment' => '["Done"]',
        ];
    }

    /**
     * The corpus names a program the far end runs, so it must NAME one. The base case checks that a
     * named runner EXISTS; this checks that this corpus names one at all — without it the base's
     * leg is vacuous for exactly the corpora whose far end cannot read PHP.
     */
    public function test_the_corpus_ships_a_runner_the_far_end_can_execute(): void
    {
        $this->assertSame('bin/coord-mirror-parity.py', static::corpus()['mirror']['runner'] ?? null, 'the coord authority is PYTHON, so a prose recipe is not enough — the far end needs a program, and this corpus must name it.');
    }
}
