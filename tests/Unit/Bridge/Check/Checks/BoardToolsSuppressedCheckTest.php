<?php

namespace Tests\Unit\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Checks\BoardToolsSuppressedCheck;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use Tests\TestCase;

/**
 * The DL-217 suppression scan, migrated in DL-242 stage 7b.
 *
 * NO GOLDEN FIXTURE REACHES THIS CHECK — card#5552 measured that the corpus's one
 * HTTP board-tools install dies at agent-config load, and the three ssh fixtures all
 * carry a satisfiable block. So these tests are the whole proof for every arm below.
 *
 * THE PROPERTY WORTH THE FILE is not the message: it is WHICH POPULATION the check reads.
 * A suppressed block resolves to `enabled === false`, so it is absent from
 * `CheckContext::$boardToolsEnabled` and from every other check in the plane. If this one
 * read that subset too, a fleet whose ONLY board-tools agent is suppressed would report
 * NOTHING and exit 0 — the exact green-because-never-looked shape the registry exists to
 * make impossible. The empty-subset test below is that assertion, and it is the reason
 * this check has its own slot rather than sharing the bearer check's.
 */
class BoardToolsSuppressedCheckTest extends TestCase
{
    public function test_a_suppressed_default_block_fails(): void
    {
        // `board_tools:` present with no strict-bool `enabled` and an unsatisfiable
        // requirement ⇒ the DEFAULT class, which suppresses rather than throwing.
        $findings = $this->findingsFor([$this->suppressedAgent('prod-agent')]);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertStringStartsWith('board_tools: agent prod-agent: ', $findings[0]->message);
        $this->assertStringContainsString('board tools are OFF for this agent (a default-on block could not be satisfied). Fix the config, or set enabled: false to stage it silently.', $findings[0]->message);
    }

    /**
     * The load-bearing one. The suppressed agent is enabled=false by construction, so the
     * enabled subset every other check in this plane is bounded by is EMPTY — and the
     * finding must still be reported.
     */
    public function test_it_fires_when_the_enabled_subset_is_empty(): void
    {
        $ctx = new CheckContext;
        $ctx->configs = [$this->suppressedAgent('prod-agent')];
        $ctx->boardToolsEnabled = [];

        $findings = iterator_to_array((new BoardToolsSuppressedCheck)->run($ctx), false);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Fail, $findings[0]->severity);
    }

    public function test_the_reason_is_carried_verbatim(): void
    {
        $config = $this->suppressedAgent('prod-agent');
        $reason = $config->boardTools?->suppressedReason;

        $this->assertNotNull($reason, 'fixture precondition: the block must be suppressed');
        $this->assertStringContainsString($reason, $this->findingsFor([$config])[0]->message);
    }

    public function test_an_absent_block_is_silent(): void
    {
        $config = AgentConfig::fromArray('prod-agent', [
            'identity' => ['kanban_user_id' => 1],
            'subscriptions' => [],
        ]);

        $this->assertNull($config->boardTools);
        $this->assertSame([], $this->findingsFor([$config]));
    }

    public function test_a_satisfiable_block_is_silent(): void
    {
        $config = AgentConfig::fromArray('prod-agent', [
            'identity' => ['kanban_user_id' => 1],
            'subscriptions' => [],
            'channel' => ['url' => 'http://127.0.0.1:9099', 'auth' => ['token_path' => '/tmp/bt-token']],
            'board_tools' => ['transport' => 'http', 'board_id' => 10, 'swimlane_id' => 4, 'create_stage_id' => 55],
        ]);

        $this->assertNull($config->boardTools?->suppressedReason);
        $this->assertSame([], $this->findingsFor([$config]));
    }

    public function test_every_suppressed_agent_reports_once_in_config_order(): void
    {
        $findings = $this->findingsFor([
            $this->suppressedAgent('alpha'),
            AgentConfig::fromArray('beta', ['identity' => ['kanban_user_id' => 2], 'subscriptions' => []]),
            $this->suppressedAgent('gamma'),
        ]);

        $this->assertCount(2, $findings);
        $this->assertStringContainsString('agent alpha:', $findings[0]->message);
        $this->assertStringContainsString('agent gamma:', $findings[1]->message);
    }

    /**
     * A block whose `enabled:` is a bare null (present-but-not-a-bool) is default-class
     * too — `array_key_exists` discriminates absent from null — so it suppresses on the
     * same path rather than throwing.
     */
    private function suppressedAgent(string $name): AgentConfig
    {
        $config = AgentConfig::fromArray($name, [
            'identity' => ['kanban_user_id' => 1],
            'subscriptions' => [],
            // No channel token and no board_tools.auth.token_path ⇒ under the HTTP
            // transport there is no bearer to resolve, which a DEFAULT-class block
            // reports by suppressing itself.
            'board_tools' => ['transport' => 'http', 'board_id' => 10, 'swimlane_id' => 4, 'create_stage_id' => 55],
        ]);
        $this->assertFalse($config->boardTools?->enabled, 'fixture precondition: a suppressed block is enabled=false');

        return $config;
    }

    /**
     * @param  list<AgentConfig>  $configs
     * @return list<Finding>
     */
    private function findingsFor(array $configs): array
    {
        $ctx = new CheckContext;
        $ctx->configs = $configs;

        return iterator_to_array((new BoardToolsSuppressedCheck)->run($ctx), false);
    }
}
