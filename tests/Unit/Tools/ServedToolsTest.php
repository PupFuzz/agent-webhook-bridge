<?php

namespace Tests\Unit\Tools;

use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\BoardToolsConfig;
use App\Bridge\Tools\BoardToolsRegistry;
use App\Bridge\Tools\SelfScopedTool;
use App\Bridge\Tools\ServedTools;
use App\Bridge\Tools\ServedToolsRule;
use App\Bridge\Tools\Tool;
use App\Bridge\Writeback\KanbanClient;
use Tests\TestCase;

/**
 * card#11283 / DL-461 — `ServedTools` is the one answer to "what does the bridge serve this
 * agent". The served set is pinned here against the REGISTRY, not a list typed into the test, so
 * a tool added later is classified by what it is (self-scoped or not), never by being forgotten.
 */
class ServedToolsTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $block
     */
    private static function block(array $block): ?BoardToolsConfig
    {
        return BoardToolsConfig::fromArray(['board_tools' => $block]);
    }

    private static function scoped(array $extra = []): ?BoardToolsConfig
    {
        return self::block(['enabled' => true, 'transport' => 'ssh', 'board_id' => 10, 'swimlane_id' => 4, 'create_stage_id' => 55] + $extra);
    }

    private static function scopeless(array $extra = []): ?BoardToolsConfig
    {
        return self::block(['enabled' => true, 'transport' => 'ssh'] + $extra);
    }

    /** @return list<string> */
    private static function selfScopedNames(BoardToolsRegistry $registry): array
    {
        return array_values(array_filter($registry->known(), static fn (string $n): bool => $registry->resolve($n) instanceof SelfScopedTool));
    }

    public function test_the_ci_tools_are_the_self_scoped_ones(): void
    {
        $this->assertSame(['ci_await', 'ci_await_cancel'], self::selfScopedNames(new BoardToolsRegistry));
    }

    public function test_a_scoped_agent_is_served_every_registered_tool(): void
    {
        $registry = new BoardToolsRegistry;

        $this->assertSame($registry->known(), (new ServedTools($registry))->namesFor(self::scoped()));
    }

    public function test_a_scope_less_agent_is_served_the_ci_tools_and_nothing_else(): void
    {
        $this->assertSame(['ci_await', 'ci_await_cancel'], (new ServedTools(new BoardToolsRegistry))->namesFor(self::scopeless()));
    }

    public function test_the_ci_tools_opt_out_removes_exactly_the_self_scoped_tools(): void
    {
        $registry = new BoardToolsRegistry;
        $served = new ServedTools($registry);

        $this->assertSame(array_values(array_diff($registry->known(), self::selfScopedNames($registry))), $served->namesFor(self::scoped(['ci_tools' => false])));
        $this->assertSame([], $served->namesFor(self::scopeless(['ci_tools' => false])));
        $this->assertSame([], $served->namesFor(self::scopeless(['ci_tools' => 'no'])), 'an unreadable opt-out is OFF');
    }

    public function test_an_absent_disabled_retired_or_suppressed_block_is_served_nothing(): void
    {
        $served = new ServedTools(new BoardToolsRegistry);

        $this->assertSame([], $served->namesFor(null));
        $this->assertSame([], $served->namesFor(self::block(['enabled' => false])));
        $this->assertSame([], $served->namesFor(self::block(['retired' => '2026-10-05 — test'])));
        $this->assertSame([], $served->namesFor(self::block(['transport' => 'ssh'])), 'a default-class block with no scope suppresses');
    }

    /** An operator's custom tool reads the board scope like every board tool, so it is served only to a scoped agent. */
    public function test_a_custom_registered_tool_is_served_only_to_a_scoped_agent(): void
    {
        $registry = new BoardToolsRegistry;
        $registry->register(new class implements Tool
        {
            public function name(): string
            {
                return 'custom_board_thing';
            }

            public function acceptedArguments(): array
            {
                return [];
            }

            public function refusedArgumentReason(string $key): ?string
            {
                return null;
            }

            public function call(array $args, BoardToolsConfig $cfg, KanbanClient $client, string $agentName): array
            {
                return [];
            }
        });
        $served = new ServedTools($registry);

        $this->assertContains('custom_board_thing', $served->namesFor(self::scoped()));
        $this->assertNotContains('custom_board_thing', $served->namesFor(self::scopeless()));
    }

    public function test_the_reporting_call_is_one_the_agent_is_served(): void
    {
        $served = new ServedTools(new BoardToolsRegistry);

        $this->assertSame('board_my_cards', $served->reportingCall(self::scoped()));
        $this->assertSame('ci_await_cancel', $served->reportingCall(self::scopeless()));
        $this->assertNull($served->reportingCall(self::scopeless(['ci_tools' => false])));
    }

    /**
     * MF-1: the take's population. A scope-less agent sharing a seat with a scoped one is NOT a
     * second taker, so the seat's kanban user still names one agent.
     */
    public function test_a_scope_less_agent_is_never_a_card_taker(): void
    {
        $configs = [
            AgentConfig::fromArray('kanban', ['subscriptions' => [], 'board_tools' => ['enabled' => true, 'transport' => 'ssh', 'board_id' => 10, 'swimlane_id' => 4, 'create_stage_id' => 55]]),
            AgentConfig::fromArray('kanban-ci', ['identity' => ['coord_seat' => 'kanban'], 'subscriptions' => [], 'board_tools' => ['enabled' => true, 'transport' => 'ssh']]),
            AgentConfig::fromArray('impl', ['subscriptions' => [], 'board_tools' => ['enabled' => true, 'transport' => 'ssh']]),
        ];

        $this->assertSame(['kanban' => ['kanban']], ServedToolsRule::takersBySeat($configs));
        $this->assertTrue(ServedToolsRule::takesCards($configs[0]));
        $this->assertFalse(ServedToolsRule::takesCards($configs[1]));
        $this->assertFalse(ServedToolsRule::takesCards($configs[2]));
    }

    /** The rule and the registry-backed list cannot disagree about `board_take_card`. */
    public function test_takes_cards_agrees_with_the_served_set_on_board_take_card(): void
    {
        $served = new ServedTools(new BoardToolsRegistry);
        $blocks = [
            'scoped' => ['enabled' => true, 'transport' => 'ssh', 'board_id' => 10, 'swimlane_id' => 4, 'create_stage_id' => 55],
            'scope-less' => ['enabled' => true, 'transport' => 'ssh'],
            'scoped, ci off' => ['enabled' => true, 'transport' => 'ssh', 'board_id' => 10, 'swimlane_id' => 4, 'create_stage_id' => 55, 'ci_tools' => false],
            'disabled' => ['enabled' => false],
            'suppressed' => ['transport' => 'ssh'],
            'absent' => null,
        ];
        foreach ($blocks as $label => $block) {
            $agent = AgentConfig::fromArray('a', ['subscriptions' => []] + ($block === null ? [] : ['board_tools' => $block]));

            $this->assertSame(in_array('board_take_card', $served->namesFor($agent->boardTools), true), ServedToolsRule::takesCards($agent), $label);
        }
    }
}
