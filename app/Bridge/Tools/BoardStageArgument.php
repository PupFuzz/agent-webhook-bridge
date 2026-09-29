<?php

namespace App\Bridge\Tools;

use App\Bridge\Exceptions\ToolRefusalException;

/**
 * ONE caller-named column, resolved to its NUMERIC stage id — the rule every read on this door that
 * takes a `stage` applies (extracted from {@see BoardMyCardsTool} at its second caller,
 * `board_search`, card#10832). Whether the argument may be absent, null or a list is the calling
 * tool's; this owns what one value means.
 *
 * The numeric id is the primary form — it is what the rows carry and what every other board
 * consumer keys on — and a string is accepted as a NAME, never as an id: `"7"` is looked up as a
 * stage called `7`, not as stage 7. That is the one reading a caller cannot be surprised by, because
 * the alternative (guess which the caller meant) picks a column for them.
 *
 * ⛔ AN AMBIGUOUS NAME IS A REFUSAL, NOT A GUESS. Two stages that differ only in case, or two
 * genuinely identical names, resolve to a set — and answering about one of them produces an answer
 * that is indistinguishable from a correct one about the other. The refusal names the board's stages
 * so the caller can send an id instead.
 *
 * ⚠ The trim is {@see BoardToolArgs::trimmed}, which delegates to the framework's own `Str::trim` —
 * NOT PHP's ASCII `trim()` — so a name carrying a non-breaking space resolves on both front doors
 * alike (DL-365 Decision 10, card#9155).
 *
 * ⚠ An id is checked against the board's stages ONLY when the stage read produced any.
 * `boardStageNames()` answers an empty map when the preload read carried no stages (already logged
 * upstream), and validating against an empty map would refuse every filter on a board whose
 * structure this bridge could not read — turning a degraded read into a dead argument. A NAME still
 * cannot be resolved in that state and says so.
 */
final class BoardStageArgument
{
    /**
     * @param  array<int, string>  $stageNames  the board's stages, id => name
     */
    public static function resolve(mixed $stage, array $stageNames, int $boardId, string $tool): int
    {
        if (is_int($stage)) {
            if ($stageNames !== [] && ! isset($stageNames[$stage])) {
                throw new ToolRefusalException("{$tool}: `stage` {$stage} is not a stage on board {$boardId} — its stages are ".self::stageList($stageNames).'. Nothing was filtered; no cards were returned for a column that does not exist.');
            }

            return $stage;
        }

        if (! is_string($stage)) {
            throw new ToolRefusalException("{$tool}: `stage` must be the NUMERIC stage id (as the board tools report it under each card's `stage`), or a stage NAME as a string. It is never coerced from another type.");
        }

        if ($stageNames === []) {
            throw new ToolRefusalException("{$tool}: `stage` was given as a NAME, but this bridge read no stages for board {$boardId}, so there is nothing to resolve it against. Pass the numeric stage id, and tell your operator the board structure read came back empty.");
        }

        $wanted = mb_strtolower(BoardToolArgs::trimmed($stage));
        if ($wanted === '') {
            throw new ToolRefusalException("{$tool}: `stage` was sent EMPTY (it contains nothing but invisible characters). Omit the argument entirely to read every column of board {$boardId}; an empty value is not a filter and is refused rather than silently ignored, which would hand you more cards than you asked for.");
        }
        $matches = [];
        foreach ($stageNames as $id => $name) {
            if (mb_strtolower(BoardToolArgs::trimmed($name)) === $wanted) {
                $matches[$id] = $name;
            }
        }

        if (count($matches) === 1) {
            return (int) array_key_first($matches);
        }
        if ($matches === []) {
            throw new ToolRefusalException("{$tool}: `stage` does not name any stage on board {$boardId} — its stages are ".self::stageList($stageNames).'. Names are matched case-insensitively and whitespace-trimmed; nothing else is inferred.');
        }

        throw new ToolRefusalException("{$tool}: `stage` names MORE THAN ONE stage on board {$boardId} — ".self::stageList($matches).'. The bridge does not guess which column you meant, because a guessed answer is indistinguishable from a correct one. Pass the numeric stage id.');
    }

    /**
     * `50 (Backlog), 51 (In Review)` — the board's own stages, id first because the id is what a
     * refusal is asking the caller to send. Sorted by id so the message is stable across calls.
     *
     * @param  array<int, string>  $stageNames
     */
    private static function stageList(array $stageNames): string
    {
        ksort($stageNames);
        $parts = [];
        foreach ($stageNames as $id => $name) {
            $parts[] = "{$id} ({$name})";
        }

        return implode(', ', $parts);
    }
}
