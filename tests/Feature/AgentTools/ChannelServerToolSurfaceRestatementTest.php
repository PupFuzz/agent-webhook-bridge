<?php

namespace Tests\Feature\AgentTools;

use App\Bridge\Tools\CallerTagPolicy;
use App\Bridge\Writeback\KanbanFieldLimits;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The reference channel server's `board_create_card` and `board_correct_card` tool
 * definitions RESTATE this repo's tag vocabulary and value caps to the model that calls
 * the tool, and this is the drift check canon #16 requires in exchange for keeping the
 * copies (card#8378 R1; widened to the create entry at R4).
 *
 * ⛔ DELETING THE COPY IS NOT AVAILABLE HERE, which is why this test exists instead. An
 * MCP `tools/list` schema is INLINE: the description string IS the surface the model
 * reads, there is no pointer it can follow to a doc, and a description that said "see the
 * bridge docs" would leave the model to guess which tags it may send. So the copies stay
 * and are GUARDED — a copy must move when the constant moves.
 *
 * WHAT IS PINNED IS THE VOCABULARY, NOT THE PROSE. Each entry of the reserved and
 * preserved sets, and each cap, must appear SOMEWHERE in that tool's definition; how the
 * sentence around it is worded is free. Adding a fifth reserved prefix and shipping a
 * server that still advertises four reds here — which is the failure that matters, since
 * the seat then sends a tag the bridge refuses and reads the 422 as a bridge bug.
 *
 * ⚠ THE EXTRACTION IS PER ENTRY, deliberately: `id:` is a substring of `card_id:` in
 * the correct-card schema, so a whole-file search would pass on an entry that named the
 * vocabulary nowhere. Each tool is cut out between its `name:` line and its own
 * `required:` line and searched alone.
 *
 * WHICH TERMS EACH ENTRY OWES follows what the bridge ENFORCES on that tool, read from
 * the tool classes rather than assumed: both tools run `CallerTagPolicy::sanitize`, so
 * both owe the reserved set and TAG_MAX; only `board_correct_card` refuses on NAME_MAX
 * (`BoardCorrectCardTool`) and only a correction has a PRESERVE set, so those two are
 * pinned on the correct-card entry alone. Pinning NAME_MAX on the create entry would
 * demand the schema advertise a cap the tool does not enforce.
 *
 * ⛔ A BARE NUMBER IS NOT A DISCRIMINATING NEEDLE OVER A WHOLE ENTRY — watched, not
 * reasoned: the first widening searched the create entry for `64` and stayed green with
 * the tag cap deleted, because `idempotency_key`'s `[A-Za-z0-9.-]{1,64}` carries the same
 * digits. So the TAG_MAX check is scoped to the `tags` PROPERTY block (cut out between
 * `tags: {` and the line that closes it), which is the surface a seat reads to learn what
 * a tag may be, and the reserved-set check stays on the whole entry, where the words are
 * distinctive. NAME_MAX stays on the whole correct-card entry: `255` occurs there once.
 *
 * ⛔ STATED BOUND, measured rather than assumed: the reserved-set predicate is *somewhere
 * in that tool's definition*, and the correct-card definition restates the vocabulary
 * TWICE (the tool description and the `tags` property description). Deleting a term from
 * ONE of the two therefore stays green — checked, both directions. That is deliberate
 * (which sentence carries a term is prose, and pinning both would freeze the wording),
 * and it costs nothing on the failure that matters: a constant that GROWS is named in
 * neither copy, which is the direction watched red. The create entry states the
 * vocabulary once, so there the bound does not apply.
 */
class ChannelServerToolSurfaceRestatementTest extends TestCase
{
    /** @var array<string, string> tool name => the `required:` line that closes its schema */
    private const TAG_TOOLS = [
        'board_create_card' => "required: ['title']",
        'board_correct_card' => "required: ['card_id']",
    ];

    private function toolDefinition(string $tool): string
    {
        $src = (string) file_get_contents(base_path('examples/channel-servers/agent-webhook-bridge-channel.mjs'));
        $start = strpos($src, "name: '{$tool}',");
        $this->assertNotFalse($start, "the channel server no longer defines {$tool} — a tool absent from TOOL_DEFINITIONS is unreachable from a seat");
        $end = strpos($src, self::TAG_TOOLS[$tool], $start);
        $this->assertNotFalse($end, "{$tool}'s definition no longer ends where this test expects — re-anchor the extraction");

        return substr($src, $start, $end - $start);
    }

    private function tagsProperty(string $tool): string
    {
        $definition = $this->toolDefinition($tool);
        $start = strpos($definition, 'tags: {');
        $this->assertNotFalse($start, "{$tool}'s schema no longer declares a `tags` property — re-anchor the extraction");
        $end = strpos($definition, "\n        },", $start);
        $this->assertNotFalse($end, "{$tool}'s `tags` property no longer closes where this test expects — re-anchor the extraction");

        return substr($definition, $start, $end - $start);
    }

    /** @return array<string, array{string}> */
    public static function tagTools(): array
    {
        return array_map(fn (string $tool) => [$tool], array_combine(array_keys(self::TAG_TOOLS), array_keys(self::TAG_TOOLS)));
    }

    #[DataProvider('tagTools')]
    public function test_the_channel_server_advertises_every_reserved_tag_the_bridge_refuses(string $tool): void
    {
        $definition = $this->toolDefinition($tool);

        foreach ([...CallerTagPolicy::RESERVED_PREFIXES, ...CallerTagPolicy::RESERVED_BARE] as $reserved) {
            $this->assertStringContainsString(
                $reserved,
                $definition,
                "the channel server's {$tool} definition does not name the reserved tag `{$reserved}`, so a seat reading it will send a tag the bridge refuses"
            );
        }
    }

    #[DataProvider('tagTools')]
    public function test_the_channel_server_advertises_the_tag_cap_the_bridge_enforces(string $tool): void
    {
        $this->assertStringContainsString(
            (string) KanbanFieldLimits::TAG_MAX,
            $this->tagsProperty($tool),
            "the channel server's {$tool} `tags` property does not state the ".KanbanFieldLimits::TAG_MAX.'-character tag cap the bridge refuses on'
        );
    }

    public function test_the_channel_server_advertises_every_tag_a_correction_will_not_delete(): void
    {
        // The PRESERVE half is the one a caller cannot infer from the refusals: these are
        // tags a caller MAY supply and may not drop, so a seat told only about the
        // reserved set expects `tags: []` to empty the card.
        $definition = $this->toolDefinition('board_correct_card');

        foreach (CallerTagPolicy::PRESERVED_BARE as $preserved) {
            $this->assertStringContainsString(
                $preserved,
                $definition,
                "the channel server's board_correct_card definition does not name `{$preserved}`, which a correction preserves — a seat cannot tell from the refusals alone"
            );
        }
    }

    public function test_the_channel_server_advertises_the_name_cap_a_correction_refuses_on(): void
    {
        $this->assertStringContainsString(
            (string) KanbanFieldLimits::NAME_MAX,
            $this->toolDefinition('board_correct_card'),
            "the channel server's board_correct_card definition does not state the ".KanbanFieldLimits::NAME_MAX.'-character name cap the bridge refuses on'
        );
    }
}
