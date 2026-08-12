<?php

namespace Tests\Feature\Writeback;

use App\Bridge\Writeback\CoordLaneStages;
use Tests\TestCase;

/**
 * card#6348 / DL-286 — the PHP mirror of the consumer's `kanban-issues-sync`
 * `_STAGE_LANE` / `_task_lane` / `classify_b2` lane model (Python), which the
 * coord-card CREATE path needs to place a card in the lane its issue already
 * declares instead of rewriting it.
 *
 * Like {@see CoordConfigTerminalsTest}, this IS a second implementation of a rule
 * the bridge does not own — the bridge is PHP and cannot import the Python. The
 * vectors below are the ones the Python's own behaviour fixes: the four labels and
 * their names, the resolution ORDER (`_STAGE_LANE`'s insertion order, which decides
 * a multi-labelled issue), the `Later` default, and the itype gate (`classify_b2`
 * routes only `task` through `_task_lane`). If the consumer changes the rule,
 * re-port these and this test reds.
 */
class CoordLaneStagesTest extends TestCase
{
    public function test_the_four_lanes_and_their_order_mirror_stage_lane(): void
    {
        // The ORDER is load-bearing, not cosmetic — it is what decides a doubly
        // labelled issue, so it is pinned rather than left to a set comparison.
        $this->assertSame(['now', 'next', 'later', 'maybe'], CoordLaneStages::LANES);
        $this->assertSame('later', CoordLaneStages::DEFAULT_LANE);
    }

    public function test_each_stage_label_resolves_to_its_lane(): void
    {
        foreach (['now', 'next', 'later', 'maybe'] as $lane) {
            $this->assertSame($lane, CoordLaneStages::laneFromLabels(['stage:'.$lane]), $lane);
        }
    }

    public function test_no_stage_label_declares_no_lane(): void
    {
        $this->assertNull(CoordLaneStages::laneFromLabels([]));
        $this->assertNull(CoordLaneStages::laneFromLabels(['from:pm', 'to:all', 'stage']));
    }

    public function test_an_unrecognized_stage_label_declares_no_lane(): void
    {
        // `stage:someday` is not in the lane model — the caller's DEFAULT_LANE arm, not
        // a fifth lane invented at the read site.
        $this->assertNull(CoordLaneStages::laneFromLabels(['stage:someday', 'stage:']));
    }

    public function test_labels_resolve_case_insensitively(): void
    {
        // The Python read-site lowercases (`(l.get("name") or "").lower()`); a hand-edited
        // `Stage:Now` label must not silently mean "unlabelled" on one mover only.
        $this->assertSame('now', CoordLaneStages::laneFromLabels(['STAGE:NOW']));
        $this->assertSame('maybe', CoordLaneStages::laneFromLabels(['Stage:Maybe']));
    }

    public function test_multiple_stage_labels_resolve_in_lane_order_not_label_order(): void
    {
        $this->assertSame('now', CoordLaneStages::laneFromLabels(['stage:maybe', 'stage:now']));
        $this->assertSame('next', CoordLaneStages::laneFromLabels(['stage:later', 'stage:next']));
    }

    public function test_only_the_task_itype_is_governed_by_the_lane_model(): void
    {
        // `classify_b2`: `if itype == "task": return columns[_task_lane(...)]`, every other
        // open itype goes to a fixed column. A bridge that lane-derived a brief would place
        // it where the reconcile does not — two movers disagreeing at create.
        $this->assertTrue(CoordLaneStages::governs('task'));
        foreach (['brief', 'announce', 'query', 'review', ''] as $itype) {
            $this->assertFalse(CoordLaneStages::governs($itype), $itype);
        }
    }
}
