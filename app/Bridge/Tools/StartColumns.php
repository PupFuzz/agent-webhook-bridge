<?php

namespace App\Bridge\Tools;

use App\Bridge\Writeback\WritebackMapping;

/**
 * A board's In Progress column and the columns work is pulled from, as its writeback.json mappings
 * declare them: each mapping's `stages.started`, and the union of their `started_from_stages`.
 *
 * ⭐ ONE READING OF THOSE DECLARATIONS FOR BOTH CONSUMERS. `board_take_card`'s start form moves a
 * card from a pull column into In Progress (DL-449) and refuses when the declaration is missing or
 * ambiguous; `board_my_cards` ranks a seat's cards In Progress first, then the pull columns
 * ({@see BoardCardRank}, card#11267). Read twice, the two could disagree about which column is
 * which on the same board.
 *
 * Mappings that map no `started` stage contribute nothing to {@see $stages}; several that map one
 * may disagree, and {@see inProgress} then answers null rather than picking one.
 */
final class StartColumns
{
    /**
     * @param  list<int>  $stages  the distinct `started` stages the mappings name
     * @param  list<int>  $from  the union of their `started_from_stages`
     */
    private function __construct(
        public readonly array $stages,
        public readonly array $from,
    ) {}

    /**
     * @param  list<WritebackMapping>  $mappings  the mappings on ONE board
     */
    public static function of(array $mappings): self
    {
        $stages = [];
        $from = [];
        foreach ($mappings as $mapping) {
            $stage = $mapping->stageFor('started');
            if ($stage === null) {
                continue;
            }
            $stages[] = $stage;
            array_push($from, ...($mapping->startedFromStages ?? []));
        }

        return new self(array_values(array_unique($stages)), array_values(array_unique($from)));
    }

    /** The In Progress column when the mappings name exactly one, else null. */
    public function inProgress(): ?int
    {
        return count($this->stages) === 1 ? $this->stages[0] : null;
    }
}
