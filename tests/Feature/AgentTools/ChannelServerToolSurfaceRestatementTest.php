<?php

namespace Tests\Feature\AgentTools;

use App\Bridge\Tools\CallerTagPolicy;
use App\Bridge\Writeback\KanbanFieldLimits;
use Tests\TestCase;

/**
 * The reference channel server's `board_correct_card` tool definition RESTATES this
 * repo's tag vocabulary and value caps to the model that calls the tool, and this is the
 * drift check canon #16 requires in exchange for keeping the copy (card#8378 R1).
 *
 * ⛔ DELETING THE COPY IS NOT AVAILABLE HERE, which is why this test exists instead. An
 * MCP `tools/list` schema is INLINE: the description string IS the surface the model
 * reads, there is no pointer it can follow to a doc, and a description that said "see the
 * bridge docs" would leave the model to guess which tags it may send. So the copy stays
 * and is GUARDED — the copy must move when the constant moves.
 *
 * WHAT IS PINNED IS THE VOCABULARY, NOT THE PROSE. Each entry of the reserved and
 * preserved sets, and each cap, must appear SOMEWHERE in that tool's definition; how the
 * sentence around it is worded is free. Adding a fifth reserved prefix and shipping a
 * server that still advertises four reds here — which is the failure that matters, since
 * the seat then sends a tag the bridge refuses and reads the 422 as a bridge bug.
 *
 * ⚠ SCOPED TO THE `board_correct_card` ENTRY, deliberately: `id:` is a substring of
 * `card_id:` in the schema, so a whole-file search would pass on an entry that named the
 * vocabulary nowhere.
 *
 * ⛔ STATED BOUND, measured rather than assumed: the predicate is *somewhere in that
 * tool's definition*, and the definition restates the vocabulary TWICE (the tool
 * description and the `tags` property description). Deleting a term from ONE of the two
 * therefore stays green — checked, both directions. That is deliberate (which sentence
 * carries a term is prose, and pinning both would freeze the wording), and it costs
 * nothing on the failure that matters: a constant that GROWS is named in neither copy,
 * which is the direction watched red.
 */
class ChannelServerToolSurfaceRestatementTest extends TestCase
{
    private function correctCardDefinition(): string
    {
        $src = (string) file_get_contents(base_path('examples/channel-servers/agent-webhook-bridge-channel.mjs'));
        $start = strpos($src, "name: 'board_correct_card',");
        $this->assertNotFalse($start, 'the channel server no longer defines board_correct_card — a tool absent from TOOL_DEFINITIONS is unreachable from a seat');
        $end = strpos($src, "required: ['card_id']", $start);
        $this->assertNotFalse($end, 'board_correct_card\'s definition no longer ends where this test expects — re-anchor the extraction');

        return substr($src, $start, $end - $start);
    }

    public function test_the_channel_server_advertises_every_reserved_tag_the_bridge_refuses(): void
    {
        $definition = $this->correctCardDefinition();

        foreach ([...CallerTagPolicy::RESERVED_PREFIXES, ...CallerTagPolicy::RESERVED_BARE] as $reserved) {
            $this->assertStringContainsString(
                $reserved,
                $definition,
                "the channel server's board_correct_card definition does not name the reserved tag `{$reserved}`, so a seat reading it will send a tag the bridge refuses"
            );
        }
    }

    public function test_the_channel_server_advertises_every_tag_a_correction_will_not_delete(): void
    {
        // The PRESERVE half is the one a caller cannot infer from the refusals: these are
        // tags a caller MAY supply and may not drop, so a seat told only about the
        // reserved set expects `tags: []` to empty the card.
        $definition = $this->correctCardDefinition();

        foreach (CallerTagPolicy::PRESERVED_BARE as $preserved) {
            $this->assertStringContainsString(
                $preserved,
                $definition,
                "the channel server's board_correct_card definition does not name `{$preserved}`, which a correction preserves — a seat cannot tell from the refusals alone"
            );
        }
    }

    public function test_the_channel_server_advertises_the_value_caps_the_bridge_enforces(): void
    {
        $definition = $this->correctCardDefinition();

        foreach ([KanbanFieldLimits::NAME_MAX, KanbanFieldLimits::TAG_MAX] as $cap) {
            $this->assertStringContainsString(
                (string) $cap,
                $definition,
                "the channel server's board_correct_card definition does not state the {$cap}-character cap the bridge refuses on"
            );
        }
    }
}
