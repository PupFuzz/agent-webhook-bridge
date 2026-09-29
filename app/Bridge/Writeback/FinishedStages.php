<?php

namespace App\Bridge\Writeback;

/**
 * Is a card in a FINISHED column — the operator's set, by name: Done, Won't Do, Shipped to dev,
 * Shipped to main (card#10868 Q2). A finished card's assignee is the record of who did the work,
 * so replacing it needs an explicit steal (ruling 7624), which no board tool has.
 *
 * ⭐ THE BRIDGE KNOWS THOSE COLUMNS BY TWO DECLARATIONS AND NEEDS BOTH. The writeback mapping
 * names Shipped (`merged`) and Released (`merged_to_main`), and its terminal floor adds every
 * column the board places at or past the earlier of them ({@see WritebackMapping::isTerminalStage}).
 * The board's own declaration ({@see BoardStructure::$terminalStageIds}, `is_terminal` else
 * `lane_type: done`) names the columns it considers done. Neither alone covers the set: the
 * reference board does not declare "Shipped to dev" terminal (`lane_type: waiting`), and a
 * mapping says nothing about a Done column placed before Shipped. So finished is the UNION —
 * the direction that protects more records.
 *
 * ⛔ AND AN UNANSWERABLE LEG IS NOT "NOT FINISHED". {@see unanswerable} names every input
 * without which a finished card could read as current: no mapping on the board that maps
 * `merged` (Shipped to dev would be invisible), an unread column collection, or a stage the
 * read order does not place (the floor cannot reach it). A caller that is about to overwrite a
 * record refuses on any of them.
 *
 * ⚠ `board_my_cards`' terminal exclusion answers from the board's declaration ALONE, so the two
 * can disagree about one card (a Shipped-to-dev card is current there and finished here). Named
 * on card#10869; moving that tool onto this class is a separate change.
 */
final class FinishedStages
{
    /**
     * Why "is this stage finished?" cannot be answered in full from these inputs, or null when
     * it can.
     *
     * @param  list<WritebackMapping>  $mappings  the mappings narrowed onto this board
     * @param  array<int, float>  $order  stage id => board position
     */
    public static function unanswerable(int $stageId, array $mappings, BoardStructure $structure, array $order): ?string
    {
        if (array_filter($mappings, static fn (WritebackMapping $m): bool => $m->stageFor('merged') !== null) === []) {
            return 'no writeback.json mapping on this board maps `merged` (Shipped to dev), so that finished column cannot be recognised';
        }
        if ($structure->terminalBasis === TerminalBasis::Unreadable) {
            return "the board's column collection could not be read";
        }
        if (! isset($order[$stageId])) {
            return "the card's column ({$stageId}) is not placed in the board's column order, so whether it is at or past Shipped cannot be read";
        }

        return null;
    }

    /**
     * Whether the stage is finished — meaningful only when {@see unanswerable} returned null.
     *
     * @param  list<WritebackMapping>  $mappings
     * @param  array<int, float>  $order
     */
    public static function isFinished(int $stageId, array $mappings, BoardStructure $structure, array $order): bool
    {
        if (in_array($stageId, $structure->terminalStageIds, true)) {
            return true;
        }
        foreach ($mappings as $mapping) {
            if ($mapping->isTerminalStage($stageId, $order)) {
                return true;
            }
        }

        return false;
    }
}
