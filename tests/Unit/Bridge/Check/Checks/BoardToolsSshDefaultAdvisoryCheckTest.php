<?php

namespace Tests\Unit\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Checks\BoardToolsSshDefaultAdvisoryCheck;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use Tests\Support\MaterializesChecks;
use Tests\TestCase;

/**
 * The DL-225 flipped-default advisory, migrated in DL-242 stage 7b.
 *
 * ITS INPUT IS ANOTHER CHECK'S REPORT, so the interesting assertions are about the
 * READBACK, not the message: which agent the map keys on, and what an ABSENT key means.
 * `CheckGoldenTest`'s `board-tools-ssh-default-transport-advisory` install pins the
 * fire-together path end to end (a pinned-line warn followed by this advisory), so what
 * this file owns is the three ways it must stay SILENT — each of which prints nothing and
 * is therefore invisible to a corpus of rendered output.
 *
 * `transportExplicit` IS THE WHOLE POINT OF THE ADVISORY. An operator who WROTE
 * `transport: ssh` chose it; the advisory exists for the agent that landed on ssh because
 * v0.68.0 flipped the default under it. A test that only asserted the warn text would pass
 * with that guard deleted, and the check would then nag every correctly-configured ssh
 * install forever.
 */
class BoardToolsSshDefaultAdvisoryCheckTest extends TestCase
{
    use MaterializesChecks;

    private const MESSAGE = 'board_tools ssh: agent prod-agent is on ssh by the v0.68.0 default (no explicit transport:); its ssh setup is incomplete or could not be verified from here — pin `transport: http` to keep the loopback path, or complete ssh setup and run `sudo bridge:check` to certify.';

    public function test_an_implicit_ssh_agent_with_incomplete_setup_is_advised(): void
    {
        $findings = $this->findingsFor($this->agent(explicit: false), ['prod-agent' => true]);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Warn, $findings[0]->severity);
        $this->assertSame(self::MESSAGE, $findings[0]->message);
    }

    /** An explicit `transport: ssh` is an operator CHOICE — never advised. */
    public function test_an_explicit_ssh_agent_is_never_advised(): void
    {
        $this->assertSame([], $this->findingsFor($this->agent(explicit: true), ['prod-agent' => true]));
    }

    /** Setup verified clean ⇒ nothing to advise. */
    public function test_a_complete_setup_is_not_advised(): void
    {
        $this->assertSame([], $this->findingsFor($this->agent(explicit: false), []));
    }

    /**
     * The map is keyed by AGENT NAME, so another agent's incomplete setup must not advise
     * this one — the readback loop writes one key per agent for exactly this reason.
     */
    public function test_another_agents_incomplete_setup_does_not_advise_this_one(): void
    {
        $this->assertSame([], $this->findingsFor($this->agent(explicit: false), ['other-agent' => true]));
    }

    public function test_an_agent_without_a_board_tools_block_is_never_advised(): void
    {
        $config = AgentConfig::fromArray('prod-agent', ['identity' => ['kanban_user_id' => 1], 'subscriptions' => []]);

        $this->assertSame([], $this->findingsFor($config, ['prod-agent' => true]));
    }

    /**
     * The advisory NEVER flips the exit — the runtime break is already loud, this is the
     * pre-deploy heads-up. `warn` is what carries that, since the renderer's `fail` arm is
     * the only one that returns false.
     */
    public function test_it_is_advisory_only(): void
    {
        foreach ($this->findingsFor($this->agent(explicit: false), ['prod-agent' => true]) as $finding) {
            $this->assertNotSame(Severity::Fail, $finding->severity);
        }
    }

    private function agent(bool $explicit): AgentConfig
    {
        $config = AgentConfig::fromArray('prod-agent', [
            'identity' => ['kanban_user_id' => 1],
            'subscriptions' => [],
            'board_tools' => array_merge([
                'enabled' => true,
                'board_id' => 10,
                'swimlane_id' => 4,
                'create_stage_id' => 55,
            ], $explicit ? ['transport' => 'ssh'] : []),
        ]);
        // Both fixtures must land on the ssh transport — one by the flipped default, one
        // by writing it — or the guard under test would not be the discriminator.
        $this->assertSame('ssh', $config->boardTools?->transport);
        $this->assertSame($explicit, $config->boardTools?->transportExplicit);

        return $config;
    }

    /**
     * @param  array<string, true>  $incomplete
     * @return list<Finding>
     */
    private function findingsFor(AgentConfig $config, array $incomplete): array
    {
        $ctx = new CheckContext;
        $ctx->sshSetupIncomplete = $incomplete;

        return $this->findingsOfFor((new BoardToolsSshDefaultAdvisoryCheck), $config, $ctx);
    }
}
