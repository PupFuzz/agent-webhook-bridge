<?php

namespace App\Bridge\Standup;

/**
 * One board's row in the standup digest (DL-306): the depth of its Now lane, or the
 * reason the bridge cannot state one.
 *
 * THREE STATES, NOT TWO — the same discipline `bridge:stats` applies to the divergence
 * ledger. A board the bridge has no Now-lane model for (no `coord_card_lane_stage_ids`
 * in its `writeback.json` mapping) yields NO ROW AT ALL, so it can never be read as an
 * empty Now lane; a board whose read failed or came back truncated yields a row whose
 * `now_depth` is ABSENT and whose `now_depth_unavailable` names the cause. Only a
 * completed, untruncated read produces a number — including the honest 0.
 *
 * The two constructors are the invariant: there is no way to build a row that carries
 * both a depth and an excuse, or neither.
 */
final class BoardSnapshot
{
    private function __construct(
        public readonly int $boardId,
        public readonly ?int $nowDepth,
        public readonly ?string $nowDepthUnavailable,
    ) {}

    public static function depth(int $boardId, int $nowDepth): self
    {
        return new self($boardId, $nowDepth, null);
    }

    public static function unavailable(int $boardId, string $reason): self
    {
        return new self($boardId, null, $reason);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $row = ['board_id' => $this->boardId];

        if ($this->nowDepth !== null) {
            $row['now_depth'] = $this->nowDepth;

            return $row;
        }

        $row['now_depth_unavailable'] = $this->nowDepthUnavailable;

        return $row;
    }
}
