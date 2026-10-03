<?php

namespace Tests\Unit\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Checks\AgentIdentityCollisionsCheck;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\AgentKanbanUsers;
use App\Bridge\Support\AgentRegistry;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use App\Bridge\Support\SharedIdentity;
use Illuminate\Support\Facades\File;
use Tests\Support\CoordRosterFixture;
use Tests\Support\MaterializesChecks;
use Tests\TestCase;

/**
 * The agent-roster id-collision walk (DL-242 stage 5c).
 *
 * NO GOLDEN FIXTURE HAS TWO AGENTS SHARING AN IDENTITY, so flipping the walk changes no
 * golden file and a green golden run is not evidence here. THE COMMAND-LEVEL SUITE DOES
 * REACH IT: mutating the walk also reds
 * `BridgeCommandsTest::test_check_surfaces_an_id_collision_on_the_console`. What this file
 * adds over that is one finding PER colliding axis, below.
 *
 * THE COLLISION IS BUILT FROM REAL CONFIGS THROUGH THE REAL REGISTRY, never from a
 * hand-written message. The text belongs to `AgentRegistry`, and a test that asserted a
 * literal copy of it would go green if the check stopped reading the registry at all. Since
 * DL-450 a kanban id is the coord roster's: the `kanban_user_id` given per agent below is
 * written into a roster for that agent's seat and read through `AgentKanbanUsers`, as
 * `CheckCommand` reads it.
 */
class AgentIdentityCollisionsCheckTest extends TestCase
{
    use MaterializesChecks;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/collisions-'.uniqid();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_it_warns_once_per_colliding_identity_axis(): void
    {
        $findings = $this->findingsFor([
            'alpha' => ['kanban_user_id' => 7],
            'beta' => ['kanban_user_id' => 7],
        ]);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Warn, $findings[0]->severity);
        $this->assertStringContainsString('kanban_user_id 7 is shared by multiple agents', $findings[0]->message);
        $this->assertStringContainsString('alpha, beta', $findings[0]->message);
    }

    /**
     * Two axes colliding is two findings, not one. The walk yields whatever the registry
     * accumulated, so a check that returned only the first would still pass the
     * single-collision case above.
     *
     * THE AXES ARE THE TWO INTEGER IDS, and that is a property of `AgentRegistry`, not of
     * this check: it detects collisions only on `kanban_user_id` and `github_user_id`. A
     * shared `github_login` is NOT a collision — the login feeds drift mapping, and two
     * agents carrying the same one is reported by nothing here.
     */
    public function test_it_yields_one_finding_per_axis_when_several_collide(): void
    {
        $findings = $this->findingsFor([
            'alpha' => ['kanban_user_id' => 7, 'github_user_id' => 42],
            'beta' => ['kanban_user_id' => 7, 'github_user_id' => 42],
        ]);

        $this->assertCount(2, $findings);
        foreach ($findings as $finding) {
            $this->assertSame(Severity::Warn, $finding->severity);
        }

        $messages = implode("\n", array_map(fn (Finding $f): string => $f->message, $findings));
        $this->assertStringContainsString('kanban_user_id 7', $messages);
        $this->assertStringContainsString('github_user_id 42', $messages);
    }

    /**
     * A shared `github_login` is not an axis — the control that keeps the test above
     * honest about WHICH duplication the registry reports.
     */
    public function test_a_shared_github_login_is_not_reported_as_a_collision(): void
    {
        $this->assertSame([], $this->findingsFor([
            'alpha' => ['kanban_user_id' => 7, 'github_login' => 'shared-bot'],
            'beta' => ['kanban_user_id' => 8, 'github_login' => 'shared-bot'],
        ]));
    }

    /**
     * A `github_user_id` DECLARED shared in shared-identities.json is excluded from the
     * unique lookup on purpose, so the shared bypass wins deterministically — and it must
     * therefore not be reported as a collision. This is the case that separates "the
     * check reports what the registry decided" from "the check re-scans for duplicates",
     * which would look identical on every test above.
     */
    public function test_a_declared_shared_github_id_is_not_a_collision(): void
    {
        $this->assertSame([], $this->findingsFor([
            'alpha' => ['kanban_user_id' => 7, 'github_user_id' => 42],
            'beta' => ['kanban_user_id' => 8, 'github_user_id' => 42],
        ], [new SharedIdentity(githubUserId: 42, githubLogin: 'shared-bot', agentNames: ['alpha', 'beta'])]));
    }

    /** The healthy population — distinct identities must stay silent. */
    public function test_it_is_silent_when_no_identity_collides(): void
    {
        $this->assertSame([], $this->findingsFor([
            'alpha' => ['kanban_user_id' => 7],
            'beta' => ['kanban_user_id' => 8],
        ]));
    }

    /**
     * The guard. `CheckCommand` leaves the registry null when there was nothing to build
     * one from, and a check that dereferenced it would abort the whole run rather than
     * skip its leg.
     */
    public function test_it_is_silent_when_no_registry_was_built(): void
    {
        $findings = $this->findingsOf((new AgentIdentityCollisionsCheck), new CheckContext);

        $this->assertSame([], $findings);
    }

    /**
     * @param  array<string, array<string, mixed>>  $identities  agent => its identity block, plus
     *                                                           the `kanban_user_id` its roster
     *                                                           seat carries
     * @param  list<SharedIdentity>  $shared
     * @return list<Finding>
     */
    private function findingsFor(array $identities, array $shared = []): array
    {
        $configs = [];
        $seats = [];
        foreach ($identities as $name => $identity) {
            if (isset($identity['kanban_user_id'])) {
                $seats[$name] = $identity['kanban_user_id'];
                unset($identity['kanban_user_id']);
            }
            $configs[] = AgentConfig::fromArray($name, [
                'identity' => $identity,
                'subscriptions' => [],
            ]);
        }
        CoordRosterFixture::configure($this->dir, $seats);

        $ctx = new CheckContext;
        $ctx->registry = AgentRegistry::fromAgentConfigs($configs, $shared, AgentKanbanUsers::of($configs)->ids());

        return $this->findingsOf((new AgentIdentityCollisionsCheck), $ctx);
    }
}
