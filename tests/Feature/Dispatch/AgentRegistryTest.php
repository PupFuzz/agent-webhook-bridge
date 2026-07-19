<?php

namespace Tests\Feature\Dispatch;

use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\AgentRegistry;
use App\Bridge\Support\RegisteredAgent;
use App\Bridge\Support\SharedIdentity;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AgentRegistryTest extends TestCase
{
    /**
     * Build a registry directly from agent specs (mirrors what fromAgentConfigs
     * derives from the scanned YAMLs — the lookup/collision/drift logic is in
     * the constructor and is provider-source-agnostic).
     *
     * @param  list<array<string, mixed>>  $agents
     * @param  list<array<string, mixed>>  $sharedIdentities
     */
    private function registry(array $agents, array $sharedIdentities = []): AgentRegistry
    {
        $regAgents = array_map(fn (array $a): RegisteredAgent => new RegisteredAgent(
            name: (string) $a['name'],
            kanbanUserId: $a['kanban_user_id'] ?? null,
            githubUserId: $a['github_user_id'] ?? null,
            githubLogin: $a['github_login'] ?? null,
        ), $agents);

        $shared = array_map(fn (array $s): SharedIdentity => new SharedIdentity(
            githubUserId: (int) $s['github_user_id'],
            githubLogin: $s['github_login'] ?? null,
            agentNames: $s['agents'] ?? [],
        ), $sharedIdentities);

        return new AgentRegistry(array_values($regAgents), array_values($shared));
    }

    protected function tearDown(): void
    {
        foreach (glob(sys_get_temp_dir().'/sharedid-*') ?: [] as $f) {
            File::deleteDirectory($f);
        }
        parent::tearDown();
    }

    public function test_resolves_by_kanban_user_id(): void
    {
        $registry = $this->registry([
            ['name' => 'prod-agent', 'kanban_user_id' => 137],
            ['name' => 'acme-pm', 'kanban_user_id' => 42, 'github_user_id' => 9001],
        ]);

        $this->assertSame('prod-agent', $registry->byKanbanUserId(137)?->name);
        $this->assertSame('acme-pm', $registry->byKanbanUserId('42')?->name);   // string coerces
        $this->assertNull($registry->byKanbanUserId(999));
    }

    public function test_resolves_by_github_user_id(): void
    {
        $registry = $this->registry([['name' => 'acme-pm', 'kanban_user_id' => 42, 'github_user_id' => 9001]]);

        $this->assertSame('acme-pm', $registry->byGithubUserId(9001)?->name);
        $this->assertSame('acme-pm', $registry->byGithubUserId('9001')?->name);   // string coerces
        $this->assertNull($registry->byGithubUserId(123));
        $this->assertNull($registry->byGithubUserId(null));
    }

    public function test_non_numeric_id_does_not_false_match_an_id_zero_agent(): void
    {
        // A non-numeric id must be rejected by the shared numeric guard, NOT
        // `(int)`-coerced to 0 — otherwise it would false-match an agent whose
        // immutable id happens to be 0.
        $registry = $this->registry([
            ['name' => 'zero-agent', 'kanban_user_id' => 0, 'github_user_id' => 0],
        ]);

        $this->assertNull($registry->byKanbanUserId('not-a-number'));
        $this->assertNull($registry->byGithubUserId('nope'));
        // Sanity: the genuine 0 still resolves.
        $this->assertSame('zero-agent', $registry->byKanbanUserId(0)?->name);
        $this->assertSame('zero-agent', $registry->byGithubUserId(0)?->name);
    }

    public function test_actor_from_event_kanban(): void
    {
        $registry = $this->registry([['name' => 'prod-agent', 'kanban_user_id' => 137]]);

        $actor = $registry->actorFromEvent('kanban', '137', ['user_id' => 137]);

        $this->assertSame('137', $actor->id);
        $this->assertSame('prod-agent', $actor->name);
        $this->assertTrue($actor->isKnownAgent);
    }

    public function test_actor_from_event_falls_back_to_payload_user_id_for_kanban(): void
    {
        $registry = $this->registry([]);

        $actor = $registry->actorFromEvent('kanban', null, ['user_id' => 55]);

        $this->assertSame('55', $actor->id);
        $this->assertFalse($actor->isKnownAgent);
        $this->assertNull($actor->name);
    }

    public function test_actor_from_event_resolves_github_by_immutable_id(): void
    {
        $registry = $this->registry([['name' => 'acme-pm', 'github_user_id' => 9001]]);

        // The event carries the numeric sender.id (adapter contract), not the login.
        $actor = $registry->actorFromEvent('github', '9001', ['sender' => ['id' => 9001, 'login' => 'whatever-current-name']]);

        $this->assertSame('9001', $actor->id);
        $this->assertSame('acme-pm', $actor->name);
        $this->assertTrue($actor->isKnownAgent);
    }

    public function test_github_recognition_survives_a_username_rename(): void
    {
        $registry = $this->registry([['name' => 'acme-pm', 'github_user_id' => 9001, 'github_login' => 'old-name']]);

        $actor = $registry->actorFromEvent('github', '9001', ['sender' => ['id' => 9001, 'login' => 'new-name']]);

        $this->assertSame('acme-pm', $actor->name);
        $this->assertTrue($actor->isKnownAgent);
    }

    public function test_kanban_and_github_ids_do_not_cross_match(): void
    {
        $registry = $this->registry([
            ['name' => 'kanban-only', 'kanban_user_id' => 137],
            ['name' => 'github-only', 'github_user_id' => 137],
        ]);

        $this->assertSame('kanban-only', $registry->actorFromEvent('kanban', '137', [])->name);
        $this->assertSame('github-only', $registry->actorFromEvent('github', '137', [])->name);
    }

    public function test_shared_identity_yields_null_name_for_classifier_reattribution(): void
    {
        $registry = $this->registry(
            [['name' => 'pm'], ['name' => 'device'], ['name' => 'backend'], ['name' => 'inventory']],
            [['github_user_id' => 12000042, 'github_login' => 'shared-bot', 'agents' => ['pm', 'device', 'backend', 'inventory']]],
        );

        $actor = $registry->actorFromEvent('github', '12000042', ['sender' => ['id' => 12000042, 'login' => 'shared-bot']]);

        $this->assertSame('12000042', $actor->id);
        $this->assertNull($actor->name);
        $this->assertFalse($actor->isKnownAgent);
    }

    public function test_shared_identity_wins_over_a_per_agent_github_user_id(): void
    {
        $registry = $this->registry(
            [['name' => 'pm', 'github_user_id' => 12000042]],
            [['github_user_id' => 12000042, 'agents' => ['pm']]],
        );

        $this->assertNull($registry->byGithubUserId(12000042));
        $this->assertNull($registry->actorFromEvent('github', '12000042', [])->name);
    }

    public function test_unknown_actor_surfaces_raw_id_with_no_name(): void
    {
        $registry = $this->registry([['name' => 'prod-agent', 'kanban_user_id' => 137]]);

        $this->assertNull($registry->actorFromEvent('kanban', '999', [])->name);
        $this->assertNull($registry->actorFromEvent('github', '999', [])->name);
        $this->assertFalse($registry->actorFromEvent('github', '999', [])->isKnownAgent);
    }

    public function test_kanban_user_id_collision_is_bypassed_and_warned(): void
    {
        Log::spy();

        $registry = $this->registry([
            ['name' => 'agent-a', 'kanban_user_id' => 137],
            ['name' => 'agent-b', 'kanban_user_id' => 137],
        ]);

        $this->assertNull($registry->byKanbanUserId(137));
        $actor = $registry->actorFromEvent('kanban', '137', []);
        $this->assertNull($actor->name);
        $this->assertFalse($actor->isKnownAgent);

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $msg) => str_contains($msg, 'kanban_user_id') && str_contains($msg, 'shared')
        )->atLeast()->once();
    }

    public function test_per_agent_github_user_id_collision_is_bypassed_and_warned(): void
    {
        Log::spy();

        $registry = $this->registry([
            ['name' => 'agent-a', 'github_user_id' => 555],
            ['name' => 'agent-b', 'github_user_id' => 555],
        ]);

        $this->assertNull($registry->byGithubUserId(555));
        $this->assertNull($registry->actorFromEvent('github', '555', [])->name);

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $msg) => str_contains($msg, 'github_user_id') && str_contains($msg, 'shared-identities')
        )->atLeast()->once();
    }

    public function test_stale_login_drift_warns_once_naming_the_new_login(): void
    {
        Log::spy();

        $registry = $this->registry([['name' => 'acme-pm', 'github_user_id' => 9001, 'github_login' => 'old-name']]);

        $payload = ['sender' => ['id' => 9001, 'login' => 'new-name']];
        $registry->actorFromEvent('github', '9001', $payload);
        $registry->actorFromEvent('github', '9001', $payload);   // second call must not re-warn

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $msg) => str_contains($msg, 'stale') && str_contains($msg, 'old-name') && str_contains($msg, 'new-name')
        )->once();
    }

    public function test_matching_login_does_not_warn(): void
    {
        Log::spy();

        $registry = $this->registry([['name' => 'acme-pm', 'github_user_id' => 9001, 'github_login' => 'steady-name']]);

        $registry->actorFromEvent('github', '9001', ['sender' => ['id' => 9001, 'login' => 'steady-name']]);

        Log::shouldNotHaveReceived('warning');
    }

    public function test_from_agent_configs_builds_lookups_from_yaml_identity(): void
    {
        // The v2 source of truth: the registry is derived from the scanned
        // per-agent configs' identity blocks, not a separate roster.
        $configs = [
            AgentConfig::fromArray('prod-agent', ['identity' => ['kanban_user_id' => 137], 'subscriptions' => []]),
            AgentConfig::fromArray('acme-pm', ['identity' => ['github_user_id' => 9001, 'github_login' => 'pm-bot'], 'subscriptions' => []]),
        ];

        $registry = AgentRegistry::fromAgentConfigs($configs);

        $this->assertSame('prod-agent', $registry->byKanbanUserId(137)?->name);
        $this->assertSame('acme-pm', $registry->byGithubUserId(9001)?->name);
        $this->assertEqualsCanonicalizing(['prod-agent', 'acme-pm'], $registry->names());
    }

    public function test_load_shared_identities_missing_file_is_empty(): void
    {
        $dir = sys_get_temp_dir().'/sharedid-'.uniqid();
        File::ensureDirectoryExists($dir);

        // Optional file: absent → no shared identities, no warning.
        $this->assertSame([], AgentRegistry::loadSharedIdentities($dir));
    }

    public function test_load_shared_identities_parses_file_and_skips_malformed(): void
    {
        Log::spy();
        $dir = sys_get_temp_dir().'/sharedid-'.uniqid();
        File::ensureDirectoryExists($dir);
        File::put($dir.'/shared-identities.json', (string) json_encode(['shared_identities' => [
            ['github_user_id' => 12000042, 'github_login' => 'team-bot', 'agents' => ['pm', 'device']],
            ['github_login' => 'no-id'],   // missing numeric github_user_id → skipped
        ]]));

        $shared = AgentRegistry::loadSharedIdentities($dir);

        $this->assertCount(1, $shared);
        $this->assertSame(12000042, $shared[0]->githubUserId);
        $this->assertSame(['pm', 'device'], $shared[0]->agentNames);
        Log::shouldHaveReceived('warning')->atLeast()->once();
    }

    public function test_shared_identity_referencing_unknown_agent_warns(): void
    {
        Log::spy();

        $this->registry(
            [['name' => 'pm']],
            [['github_user_id' => 12000042, 'agents' => ['pm', 'ghost']]],
        );

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $msg) => str_contains($msg, 'unknown agent') && str_contains($msg, 'ghost')
        )->atLeast()->once();
    }
}
