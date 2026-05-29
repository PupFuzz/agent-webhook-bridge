<?php

namespace Tests\Feature\Config;

use App\Bridge\Exceptions\ConfigException;
use App\Bridge\Support\SubscriptionRegistry;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SubscriptionRegistryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/subreg-'.uniqid();
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function writeAgent(string $name, string $provider, int $scope): void
    {
        File::put($this->dir."/{$name}.yml", <<<YAML
        identity:
          self: {$name}
        api:
          {$provider}:
            base_url: https://up.example.com/api
            token_path: /tokens/t
        receiver:
          base_url: https://bridge.example.com/webhooks
        subscriptions:
          - provider: {$provider}
            scopes: [{$scope}]
        YAML);
    }

    public function test_returns_only_agents_subscribed_to_the_scope(): void
    {
        $this->writeAgent('prod-agent', 'kanban', 5);
        $this->writeAgent('dev-agent', 'kanban', 6);

        $registry = new SubscriptionRegistry($this->dir);

        $this->assertSame(['prod-agent'], array_map(fn ($c) => $c->agentName, $registry->subscribedTo('kanban', '5')));
        $this->assertSame(['dev-agent'], array_map(fn ($c) => $c->agentName, $registry->subscribedTo('kanban', '6')));
        $this->assertSame([], $registry->subscribedTo('kanban', '99'));
        $this->assertSame([], $registry->subscribedTo('github', '5'));
    }

    public function test_malformed_config_fails_closed(): void
    {
        $this->writeAgent('prod-agent', 'kanban', 5);
        File::put($this->dir.'/broken.yml', "identity: {}\napi: {}\n");   // missing identity.self + empty api

        $registry = new SubscriptionRegistry($this->dir);

        // Fail-closed: a broken sibling config makes resolution THROW (→ 5xx →
        // kanban-board holds + redelivers), rather than silently returning the
        // valid subset and losing the broken agent's events.
        $this->expectException(ConfigException::class);
        $registry->subscribedTo('kanban', '5');
    }
}
