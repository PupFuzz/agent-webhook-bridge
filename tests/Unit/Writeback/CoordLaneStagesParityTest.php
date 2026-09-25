<?php

namespace Tests\Unit\Writeback;

use App\Bridge\Writeback\CoordLaneStages;
use Tests\Support\MirrorParityTestCase;

/**
 * THE CHECK BEHIND THE BRIDGE'S MIRROR OF THE COORD FRAMEWORK'S LANE RULE (card#10273).
 *
 * `App\Bridge\Writeback\CoordLaneStages` is a VENDORED copy of a rule whose home is Python —
 * `kanban-issues-sync`'s `_STAGE_LANE` / `_task_lane` / `classify_coord`. The bridge is a PHP
 * runtime and cannot import them, which is why the copy exists.
 *
 * ⛔ WHY THIS EXISTS AT ALL is DL-414's argument, which this class discharges rather than restates;
 * see {@see CoordConfigTerminalsParityTest} for the same paragraph, and
 * {@see MirrorParityTestCase} for every leg.
 *
 * ⭐ THE HARM THIS MIRROR PREVENTS IS A CREATE-TIME DISAGREEMENT, which is what makes an unchecked
 * copy of it dangerous rather than untidy. The bridge creates a coord card in real time at a lane
 * DERIVED from the issue's `stage:*` label; the consumer's reconcile derives the same lane from the
 * same label, and `user_lanes` then PRESERVES whichever mover got there first. So a drifted mirror
 * does not produce a fight the next cycle repairs — it produces a card parked in the wrong lane
 * permanently, and the lane is what a burn-down is read off.
 *
 * ⚠ THIS CLASS IS ONE HALF — `bin/coord-mirror-parity.py` is the other. A green run here says the
 * mirror still answers what the last cross-end measurement recorded, never that the two ends agree
 * today.
 */
class CoordLaneStagesParityTest extends MirrorParityTestCase
{
    protected static function mirrorClass(): string
    {
        return CoordLaneStages::class;
    }

    protected static function corpusPath(): string
    {
        return 'docs/coord-lane-parity-corpus.json';
    }

    protected static function comparatorControl(): array
    {
        return [
            'method' => 'resolveLane',
            'args' => [['stage:now', 'stage:next'], ['next', 'later', 'maybe']],
            'right' => ['lane' => 'next', 'unmapped' => ['now']],
            'wrong' => ['lane' => 'now', 'unmapped' => []],
            'wrong_fragment' => '"lane":"next"',
        ];
    }

    /**
     * The corpus names a program the far end runs, so it must NAME one — see the sibling class for
     * why the base case's existence leg is not enough on its own.
     */
    public function test_the_corpus_ships_a_runner_the_far_end_can_execute(): void
    {
        $this->assertSame('bin/coord-mirror-parity.py', static::corpus()['mirror']['runner'] ?? null, 'the coord authority is PYTHON, so a prose recipe is not enough — the far end needs a program, and this corpus must name it.');
    }
}
