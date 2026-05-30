<?php

namespace Tests\Feature\Dispatch;

use App\Bridge\Support\AgentRegistry;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AgentRegistryTest extends TestCase
{
    /**
     * @param  list<array<string, mixed>>  $agents
     * @param  list<array<string, mixed>>  $sharedIdentities
     */
    private function writeRegistry(array $agents, array $sharedIdentities = [], int $schemaVersion = AgentRegistry::SCHEMA_VERSION): string
    {
        $path = sys_get_temp_dir().'/agents-'.uniqid().'.json';
        $body = ['schema_version' => $schemaVersion, 'agents' => $agents];
        if ($sharedIdentities !== []) {
            $body['shared_identities'] = $sharedIdentities;
        }
        File::put($path, (string) json_encode($body));

        return $path;
    }

    protected function tearDown(): void
    {
        foreach (glob(sys_get_temp_dir().'/agents-*.json') ?: [] as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    public function test_loads_and_resolves_by_kanban_user_id(): void
    {
        $path = $this->writeRegistry([
            ['name' => 'prod-agent', 'kanban_user_id' => 137],
            ['name' => 'acme-pm', 'kanban_user_id' => 42, 'github_user_id' => 9001],
        ]);

        $registry = AgentRegistry::load($path);

        $this->assertSame('prod-agent', $registry->byKanbanUserId(137)?->name);
        $this->assertSame('acme-pm', $registry->byKanbanUserId('42')?->name);   // string coerces
        $this->assertNull($registry->byKanbanUserId(999));
    }

    public function test_resolves_by_github_user_id(): void
    {
        $path = $this->writeRegistry([['name' => 'acme-pm', 'kanban_user_id' => 42, 'github_user_id' => 9001]]);
        $registry = AgentRegistry::load($path);

        $this->assertSame('acme-pm', $registry->byGithubUserId(9001)?->name);
        $this->assertSame('acme-pm', $registry->byGithubUserId('9001')?->name);   // string coerces
        $this->assertNull($registry->byGithubUserId(123));
        $this->assertNull($registry->byGithubUserId(null));
    }

    public function test_actor_from_event_kanban_precedence(): void
    {
        $path = $this->writeRegistry([['name' => 'prod-agent', 'kanban_user_id' => 137]]);
        $registry = AgentRegistry::load($path);

        $actor = $registry->actorFromEvent('kanban', '137', ['user_id' => 137]);

        $this->assertSame('137', $actor->id);
        $this->assertSame('prod-agent', $actor->name);
        $this->assertTrue($actor->isKnownAgent);
    }

    public function test_actor_from_event_falls_back_to_payload_user_id_for_kanban(): void
    {
        $registry = AgentRegistry::load($this->writeRegistry([]));

        $actor = $registry->actorFromEvent('kanban', null, ['user_id' => 55]);

        $this->assertSame('55', $actor->id);
        $this->assertFalse($actor->isKnownAgent);
        $this->assertNull($actor->name);
    }

    public function test_actor_from_event_resolves_github_by_immutable_id(): void
    {
        $path = $this->writeRegistry([['name' => 'acme-pm', 'github_user_id' => 9001]]);
        $registry = AgentRegistry::load($path);

        // The event carries the numeric sender.id (adapter contract), not the login.
        $actor = $registry->actorFromEvent('github', '9001', ['sender' => ['id' => 9001, 'login' => 'whatever-current-name']]);

        $this->assertSame('9001', $actor->id);
        $this->assertSame('acme-pm', $actor->name);
        $this->assertTrue($actor->isKnownAgent);
    }

    public function test_github_recognition_survives_a_username_rename(): void
    {
        // Configured login is the OLD username; the event carries a NEW one.
        // Recognition keys on the id, so attribution is unchanged by the rename.
        $path = $this->writeRegistry([['name' => 'acme-pm', 'github_user_id' => 9001, 'github_login' => 'old-name']]);
        $registry = AgentRegistry::load($path);

        $actor = $registry->actorFromEvent('github', '9001', ['sender' => ['id' => 9001, 'login' => 'new-name']]);

        $this->assertSame('acme-pm', $actor->name);
        $this->assertTrue($actor->isKnownAgent);
    }

    public function test_kanban_and_github_ids_do_not_cross_match(): void
    {
        // Same integer 137 on different axes must not collide across providers.
        $path = $this->writeRegistry([
            ['name' => 'kanban-only', 'kanban_user_id' => 137],
            ['name' => 'github-only', 'github_user_id' => 137],
        ]);
        $registry = AgentRegistry::load($path);

        $this->assertSame('kanban-only', $registry->actorFromEvent('kanban', '137', [])->name);
        $this->assertSame('github-only', $registry->actorFromEvent('github', '137', [])->name);
    }

    public function test_shared_identity_yields_null_name_for_classifier_reattribution(): void
    {
        // The shared-account shape: N agents under one GitHub account.
        $path = $this->writeRegistry(
            [
                ['name' => 'pm'], ['name' => 'device'],
                ['name' => 'backend'], ['name' => 'inventory'],
            ],
            [['github_user_id' => 12000042, 'github_login' => 'shared-bot', 'agents' => ['pm', 'device', 'backend', 'inventory']]],
        );
        $registry = AgentRegistry::load($path);

        $actor = $registry->actorFromEvent('github', '12000042', ['sender' => ['id' => 12000042, 'login' => 'shared-bot']]);

        // name=null + isKnownAgent=false: exactly today's collision-bypass result,
        // so the custom FROM:/repo re-attribution layer is untouched.
        $this->assertSame('12000042', $actor->id);
        $this->assertNull($actor->name);
        $this->assertFalse($actor->isKnownAgent);
    }

    public function test_shared_identity_wins_over_a_per_agent_github_user_id(): void
    {
        $path = $this->writeRegistry(
            [['name' => 'pm', 'github_user_id' => 12000042]],
            [['github_user_id' => 12000042, 'agents' => ['pm']]],
        );
        $registry = AgentRegistry::load($path);

        // The shared declaration is explicit → bypass, not per-agent attribution.
        $this->assertNull($registry->byGithubUserId(12000042));
        $this->assertNull($registry->actorFromEvent('github', '12000042', [])->name);
    }

    public function test_unknown_actor_surfaces_raw_id_with_no_name(): void
    {
        $registry = AgentRegistry::load($this->writeRegistry([['name' => 'prod-agent', 'kanban_user_id' => 137]]));

        $this->assertNull($registry->actorFromEvent('kanban', '999', [])->name);
        $this->assertNull($registry->actorFromEvent('github', '999', [])->name);
        $this->assertFalse($registry->actorFromEvent('github', '999', [])->isKnownAgent);
    }

    public function test_kanban_user_id_collision_is_bypassed_and_warned_dl074(): void
    {
        Log::spy();

        $path = $this->writeRegistry([
            ['name' => 'agent-a', 'kanban_user_id' => 137],
            ['name' => 'agent-b', 'kanban_user_id' => 137],
        ]);
        $registry = AgentRegistry::load($path);

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

        // Same id on two per-agent entries (without a shared_identities
        // declaration) is the accidental-collision shape: bypass + warn,
        // pointing at shared_identities as the intentional form.
        $path = $this->writeRegistry([
            ['name' => 'agent-a', 'github_user_id' => 555],
            ['name' => 'agent-b', 'github_user_id' => 555],
        ]);
        $registry = AgentRegistry::load($path);

        $this->assertNull($registry->byGithubUserId(555));
        $this->assertNull($registry->actorFromEvent('github', '555', [])->name);

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $msg) => str_contains($msg, 'github_user_id') && str_contains($msg, 'shared_identities')
        )->atLeast()->once();
    }

    public function test_stale_login_drift_warns_once_naming_the_new_login(): void
    {
        Log::spy();

        $path = $this->writeRegistry([['name' => 'acme-pm', 'github_user_id' => 9001, 'github_login' => 'old-name']]);
        $registry = AgentRegistry::load($path);

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

        $path = $this->writeRegistry([['name' => 'acme-pm', 'github_user_id' => 9001, 'github_login' => 'steady-name']]);
        $registry = AgentRegistry::load($path);

        $registry->actorFromEvent('github', '9001', ['sender' => ['id' => 9001, 'login' => 'steady-name']]);

        Log::shouldNotHaveReceived('warning');
    }

    public function test_outdated_schema_version_degrades_with_migration_warning(): void
    {
        Log::spy();

        $path = $this->writeRegistry([['name' => 'prod-agent', 'kanban_user_id' => 137]], [], schemaVersion: 1);
        $registry = AgentRegistry::load($path);

        $this->assertSame([], $registry->names());
        $this->assertNull($registry->byKanbanUserId(137));
        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $msg) => str_contains($msg, 'schema_version') && str_contains($msg, 'github_user_id')
        )->atLeast()->once();
    }

    public function test_missing_file_degrades_to_empty_with_warning(): void
    {
        Log::spy();

        $registry = AgentRegistry::load(sys_get_temp_dir().'/does-not-exist-'.uniqid().'.json');

        $this->assertSame([], $registry->names());
        $this->assertNull($registry->byKanbanUserId(1));
        Log::shouldHaveReceived('warning')->atLeast()->once();
    }

    public function test_malformed_entry_is_skipped(): void
    {
        Log::spy();

        $path = $this->writeRegistry([
            ['name' => 'good', 'kanban_user_id' => 1],
            ['kanban_user_id' => 2],   // no name → skipped
        ]);
        $registry = AgentRegistry::load($path);

        $this->assertSame(['good'], $registry->names());
    }

    public function test_shared_identity_referencing_unknown_agent_warns(): void
    {
        Log::spy();

        $path = $this->writeRegistry(
            [['name' => 'pm']],
            [['github_user_id' => 12000042, 'agents' => ['pm', 'ghost']]],
        );
        AgentRegistry::load($path);

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $msg) => str_contains($msg, 'unknown agent') && str_contains($msg, 'ghost')
        )->atLeast()->once();
    }
}
