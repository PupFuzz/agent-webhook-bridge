<?php

namespace Tests\Feature\Writeback;

use App\Bridge\Writeback\CoordLaneStages;
use Tests\TestCase;

/**
 * card#6348 / DL-286 — the PHP mirror of the consumer's `kanban-issues-sync`
 * `_STAGE_LANE` / `_task_lane` / `classify_coord` lane model (Python), which the
 * coord-card CREATE path needs to place a card in the lane its issue already
 * declares instead of rewriting it.
 *
 * Like {@see CoordConfigTerminalsTest}, this IS a second implementation of a rule
 * the bridge does not own — the bridge is PHP and cannot import the Python. The
 * vectors below are the ones the Python's own behaviour fixes: the four labels and
 * their names, the resolution ORDER (`_STAGE_LANE`'s insertion order, which decides
 * a multi-labelled issue), the `Later` default, the skip-and-continue on a lane this
 * board does not carry (`_task_lane`'s availability test sits INSIDE its loop), and
 * the gate (`classify_coord` routes an issue through `_task_lane` iff its TITLE
 * starts with `[TASK]`). If the consumer changes the rule, re-port these and this
 * test reds.
 */
class CoordLaneStagesTest extends TestCase
{
    /** Every lane mapped — the board that carries the full lane model. */
    private const ALL_LANES = ['now', 'next', 'later', 'maybe'];

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
            $this->assertSame(
                ['lane' => $lane, 'unmapped' => []],
                CoordLaneStages::resolveLane(['stage:'.$lane], self::ALL_LANES),
                $lane,
            );
        }
    }

    public function test_no_stage_label_declares_no_lane(): void
    {
        $this->assertSame(['lane' => null, 'unmapped' => []], CoordLaneStages::resolveLane([], self::ALL_LANES));
        $this->assertSame(['lane' => null, 'unmapped' => []], CoordLaneStages::resolveLane(['from:pm', 'to:all', 'stage'], self::ALL_LANES));
    }

    public function test_an_unrecognized_stage_label_declares_no_lane(): void
    {
        // `stage:someday` is not in the lane model — the caller's DEFAULT_LANE arm, not
        // a fifth lane invented at the read site. It is not "unmapped" either: the lane
        // model does not know it, so there is no config gap to report.
        $this->assertSame(
            ['lane' => null, 'unmapped' => []],
            CoordLaneStages::resolveLane(['stage:someday', 'stage:'], self::ALL_LANES),
        );
    }

    public function test_labels_resolve_case_insensitively(): void
    {
        // The Python read-site lowercases (`(l.get("name") or "").lower()`); a hand-edited
        // `Stage:Now` label must not silently mean "unlabelled" on one mover only.
        $this->assertSame('now', CoordLaneStages::resolveLane(['STAGE:NOW'], self::ALL_LANES)['lane']);
        $this->assertSame('maybe', CoordLaneStages::resolveLane(['Stage:Maybe'], self::ALL_LANES)['lane']);
    }

    public function test_multiple_stage_labels_resolve_in_lane_order_not_label_order(): void
    {
        $this->assertSame('now', CoordLaneStages::resolveLane(['stage:maybe', 'stage:now'], self::ALL_LANES)['lane']);
        $this->assertSame('next', CoordLaneStages::resolveLane(['stage:later', 'stage:next'], self::ALL_LANES)['lane']);
    }

    public function test_an_unmapped_lane_is_skipped_and_the_scan_continues(): void
    {
        // `_task_lane`'s availability test is INSIDE the loop (`if label in names and
        // LANE_TO_COLUMN[lane] in columns`): a lane this board does not carry is skipped
        // and the NEXT declared label decides. Resolving first and falling back after
        // would answer `later` here where the consumer answers `Next` — a create-time
        // disagreement `user_lanes` then preserves.
        $this->assertSame(
            ['lane' => 'next', 'unmapped' => ['now']],
            CoordLaneStages::resolveLane(['stage:now', 'stage:next'], ['next', 'later', 'maybe']),
        );
    }

    public function test_every_declared_lane_unmapped_falls_through_to_the_default(): void
    {
        // The scan exhausts: both declared lanes are reported unmapped and the caller is
        // handed the null cue for DEFAULT_LANE.
        $this->assertSame(
            ['lane' => null, 'unmapped' => ['now', 'next']],
            CoordLaneStages::resolveLane(['stage:next', 'stage:now'], ['later']),
        );
    }

    public function test_a_lane_after_the_winner_is_not_reported_unmapped(): void
    {
        // The consumer's loop stops at the first available match, so a lower-priority
        // label it never examined is not a config gap to warn about.
        $this->assertSame(
            ['lane' => 'now', 'unmapped' => []],
            CoordLaneStages::resolveLane(['stage:now', 'stage:maybe'], ['now', 'later']),
        );
    }

    public function test_only_an_anchored_task_title_is_governed_by_the_lane_model(): void
    {
        // `classify_coord` gates on `title.upper().startswith("[TASK]")` — by TITLE, not
        // by `_itype`, and its own comment says why: `_itype` defaults un-prefixed issues
        // to "task" too, so an itype gate would sweep the whole un-prefixed population
        // into the lane model and create those cards in Later where the consumer's
        // reconcile wants Now — a disagreement `preserve_stage` then freezes.
        $this->assertTrue(CoordLaneStages::governs('[TASK] do the thing'));
        $this->assertTrue(CoordLaneStages::governs('[task] lowercased still anchors'));
        $this->assertTrue(CoordLaneStages::governs('[TASK]'));

        foreach ([
            'plain title with no prefix at all',   // coordItype() calls this 'task' — the itype gate's blind spot
            '[PROPOSAL] a proposal',               // coordItype() calls this 'task' too
            '[BRIEF] a brief',
            '[ANNOUNCE] an announcement',
            '[QUERY] a question',
            '[REVIEW] a review',
            'RE: [TASK] not anchored',
            ' [TASK] leading space — the consumer does not strip, so neither does this gate',
            '',
        ] as $title) {
            $this->assertFalse(CoordLaneStages::governs($title), $title);
        }
    }
}
