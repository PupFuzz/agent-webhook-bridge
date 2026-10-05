<?php

namespace App\Bridge\Tools;

use App\Bridge\ClientUpdate\ClientUpdateDoor;
use App\Bridge\Support\BoardToolsConfig;

/**
 * WHICH TOOLS THE BRIDGE SERVES AN AGENT — the one answer (card#11283).
 *
 * An enabled `board_tools` block is the door: it is what every front door authenticates against,
 * whether or not it carries a board scope. What the door then SERVES is decided here and nowhere
 * else:
 *  - a self-scoped tool ({@see SelfScopedTool}: the CI tools) — to every enabled agent that has not
 *    opted out (`board_tools.ci_tools`), scoped or not;
 *  - every other registered tool (the board tools, and any tool an operator registered) — only to
 *    an agent with a board scope, because every one of them reads that scope from its config;
 *  - nothing to an agent whose block is absent, disabled, retired or suppressed.
 *
 * ⛔ THREE READERS, ONE RULE. {@see BoardToolDispatcher} refuses an unserved tool with `not_served`
 * before it builds a writeback client or calls the tool; the `served_tools` answer
 * ({@see ClientUpdateDoor}) tells the seat's channel server what to
 * advertise; and the "may take cards" question ({@see ServedToolsRule::takersBySeat()}) is the
 * same rule asked about `board_take_card`. ⛔ This class applies {@see ServedToolsRule} to each
 * registered tool and adds nothing to it. A second copy of any of them is how an agent would be
 * advertised a tool it is refused, or refused at the door for a tool it was never offered.
 */
final class ServedTools
{
    public function __construct(private readonly BoardToolsRegistry $tools) {}

    public static function make(): self
    {
        return new self(app(BoardToolsRegistry::class));
    }

    public function serves(?BoardToolsConfig $bt, Tool $tool): bool
    {
        return $tool instanceof SelfScopedTool ? ServedToolsRule::servesCiTools($bt) : ServedToolsRule::servesBoardTools($bt);
    }

    /**
     * The served tool names for this block, sorted — what the `served_tools` answer carries.
     *
     * @return list<string>
     */
    public function namesFor(?BoardToolsConfig $bt): array
    {
        $served = [];
        foreach ($this->tools->known() as $name) {
            $tool = $this->tools->resolve($name);
            if ($tool !== null && $this->serves($bt, $tool)) {
                $served[] = $name;
            }
        }

        return $served;
    }

    /**
     * The one served tool a seat should call to report its client half: `board_my_cards` for an
     * agent served it, else a self-scoped tool, else null (an agent served nothing).
     */
    public function reportingCall(?BoardToolsConfig $bt): ?string
    {
        $names = $this->namesFor($bt);
        if (in_array('board_my_cards', $names, true)) {
            return 'board_my_cards';
        }
        if (in_array('ci_await_cancel', $names, true)) {
            return 'ci_await_cancel';
        }

        return $names[0] ?? null;
    }
}
