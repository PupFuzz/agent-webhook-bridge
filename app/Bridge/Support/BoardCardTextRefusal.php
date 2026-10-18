<?php

namespace App\Bridge\Support;

/**
 * The plain-language refusal a BOARD TOOL answers: kbcard's text from {@see CardTextCheck}, unchanged,
 * plus the lines a bridge seat needs because that text was written for a `kbcard` user.
 *
 * kbcard's words stay verbatim — the parity corpus holds {@see CardTextCheck} to them, and a seat
 * should see one answer from either writer — so what kbcard's text gets wrong for THIS door is
 * corrected by ADDING lines, never by rewriting the shared ones:
 *  - T5's hint says "pass --component". Neither `board_create_card` nor `board_correct_card` takes a
 *    component argument or writes the component field, so {@see T5_ON_THIS_DOOR} follows the T5
 *    line with the advice a seat can act on.
 *  - Every refusal cites "README.md §", which is agent-board-toolkit's README, not this repo's;
 *    {@see README_POINTER} says so and names the bridge doc that covers the rules.
 */
final class BoardCardTextRefusal
{
    public const T5_LINE_PREFIX = '  T5 (';

    public const T5_ON_THIS_DOOR = '    on this bridge: board_create_card and board_correct_card take no component argument and write no component field, so there is nothing to pass — drop the prefix from the title; the technical notes line below keeps it for the description.';

    public const README_POINTER = "  README.md above is agent-board-toolkit's README, not the bridge's; the bridge's own account of these rules is agent-webhook-bridge docs/board-tools.md § The plain-language card rules.";

    public static function title(string $title, string $verb, string $tail): ?string
    {
        $refusal = CardTextCheck::title($title, $verb, $tail);
        if ($refusal === null) {
            return null;
        }

        $lines = [];
        foreach (explode("\n", $refusal) as $line) {
            $lines[] = $line;
            if (str_starts_with($line, self::T5_LINE_PREFIX)) {
                $lines[] = self::T5_ON_THIS_DOOR;
            }
        }
        $lines[] = self::README_POINTER;

        return implode("\n", $lines);
    }

    public static function description(string $description, string $verb, string $tail): ?string
    {
        $refusal = CardTextCheck::description($description, $verb, $tail);

        return $refusal === null ? null : $refusal."\n".self::README_POINTER;
    }
}
