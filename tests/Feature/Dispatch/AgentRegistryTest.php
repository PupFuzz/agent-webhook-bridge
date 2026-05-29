<?php

namespace Tests\Feature\Dispatch;

use App\Bridge\Support\AgentRegistry;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AgentRegistryTest extends TestCase
{
    private function writeRegistry(array $agents): string
    {
        $path = sys_get_temp_dir().'/agents-'.uniqid().'.json';
        File::put($path, (string) json_encode(['schema_version' => 1, 'agents' => $agents]));

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
            ['name' => 'acme-pm', 'kanban_user_id' => 42, 'github_login' => 'acme-pm-bot'],
        ]);

        $registry = AgentRegistry::load($path);

        $this->assertSame('prod-agent', $registry->byKanbanUserId(137)?->name);
        $this->assertSame('acme-pm', $registry->byKanbanUserId('42')?->name);   // string coerces
        $this->assertNull($registry->byKanbanUserId(999));
    }

    public function test_resolves_by_github_login(): void
    {
        $path = $this->writeRegistry([['name' => 'acme-pm', 'kanban_user_id' => 42, 'github_login' => 'acme-pm-bot']]);
        $registry = AgentRegistry::load($path);

        $this->assertSame('acme-pm', $registry->byGithubLogin('acme-pm-bot')?->name);
        $this->assertNull($registry->byGithubLogin('octocat'));
        $this->assertNull($registry->byGithubLogin(null));
    }

    public function test_actor_from_event_kanban_precedence(): void
    {
        $path = $this->writeRegistry([['name' => 'prod-agent', 'kanban_user_id' => 137]]);
        $registry = AgentRegistry::load($path);

        $actor = $registry->actorFromEvent('137', ['user_id' => 137]);

        $this->assertSame('137', $actor->id);
        $this->assertSame('prod-agent', $actor->name);
        $this->assertTrue($actor->isKnownAgent);
    }

    public function test_actor_from_event_falls_back_to_payload_user_id(): void
    {
        $registry = AgentRegistry::load($this->writeRegistry([]));

        $actor = $registry->actorFromEvent(null, ['user_id' => 55]);

        $this->assertSame('55', $actor->id);
        $this->assertFalse($actor->isKnownAgent);
        $this->assertNull($actor->name);
    }

    public function test_unknown_actor_surfaces_raw_id_with_no_name(): void
    {
        $registry = AgentRegistry::load($this->writeRegistry([['name' => 'prod-agent', 'kanban_user_id' => 137]]));

        $actor = $registry->actorFromEvent('999', []);

        $this->assertSame('999', $actor->id);
        $this->assertNull($actor->name);
        $this->assertFalse($actor->isKnownAgent);
    }

    public function test_collision_is_bypassed_and_warned_dl074(): void
    {
        Log::spy();

        // Two agents share kanban_user_id 137 — the silent-corruption shape.
        $path = $this->writeRegistry([
            ['name' => 'agent-a', 'kanban_user_id' => 137],
            ['name' => 'agent-b', 'kanban_user_id' => 137],
        ]);
        $registry = AgentRegistry::load($path);

        // Bypassed: the colliding id resolves to NO agent (raw id surfaces),
        // not a confidently-wrong last-listed name.
        $this->assertNull($registry->byKanbanUserId(137));
        $actor = $registry->actorFromEvent('137', []);
        $this->assertNull($actor->name);
        $this->assertFalse($actor->isKnownAgent);

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $msg) => str_contains($msg, 'kanban_user_id') && str_contains($msg, 'shared')
        )->atLeast()->once();
    }

    public function test_github_login_collision_is_bypassed_and_warned_dl074(): void
    {
        Log::spy();

        // The collision bug was originally reported on github_login; the bug shape
        // is symmetric, so the second identity axis gets its own regression.
        $path = $this->writeRegistry([
            ['name' => 'agent-a', 'kanban_user_id' => 1, 'github_login' => 'shared-bot'],
            ['name' => 'agent-b', 'kanban_user_id' => 2, 'github_login' => 'shared-bot'],
        ]);
        $registry = AgentRegistry::load($path);

        $this->assertNull($registry->byGithubLogin('shared-bot'));
        $actor = $registry->actorFromEvent('shared-bot', []);
        $this->assertNull($actor->name);
        $this->assertFalse($actor->isKnownAgent);

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $msg) => str_contains($msg, 'github_login') && str_contains($msg, 'shared')
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
}
