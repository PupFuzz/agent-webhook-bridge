<?php

namespace Tests\Feature\Config;

use App\Bridge\Classifiers\EventDrivenClassifier;
use App\Bridge\Classifiers\InboxOnlyClassifier;
use App\Bridge\Exceptions\ConfigException;
use App\Bridge\Support\AgentConfig;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AgentConfigTest extends TestCase
{
    /**
     * Note: array_replace_recursive merges list values BY INDEX, so an
     * override list must be at least as long as the base to fully replace it
     * (all current overrides satisfy this).
     *
     * @param  array<mixed>  $overrides
     * @return array<mixed>
     */
    private function raw(array $overrides = []): array
    {
        return array_replace_recursive([
            'identity' => ['self' => 'prod-agent'],
            'api' => ['kanban' => ['base_url' => 'https://kanban.example.com/api/v3/', 'token_path' => '/tokens/kanban']],
            'receiver' => ['base_url' => 'https://bridge.example.com/webhooks/'],
            'subscriptions' => [['provider' => 'kanban', 'scopes' => [5], 'event_filter' => ['task.*']]],
            'echo_suppression' => ['treat_as_echo_ids' => ['137']],
        ], $overrides);
    }

    public function test_parses_a_valid_config(): void
    {
        $cfg = AgentConfig::fromArray('prod-agent', $this->raw());

        $this->assertSame('prod-agent', $cfg->agentName);
        $this->assertSame('prod-agent', $cfg->selfIdentity);
        $this->assertSame('https://kanban.example.com/api/v3', $cfg->api['kanban']->baseUrl);  // trailing / stripped
        $this->assertSame('/tokens/kanban', $cfg->api['kanban']->tokenPath);
        $this->assertSame('https://bridge.example.com/webhooks', $cfg->receiverBaseUrl);       // trailing / stripped
        $this->assertCount(1, $cfg->subscriptions);
        $this->assertSame('kanban', $cfg->subscriptions[0]->provider);
        $this->assertSame('5', $cfg->subscriptions[0]->scopeId);
        $this->assertSame(['137'], $cfg->echoSuppression->treatAsEchoIds);
        $this->assertSame(InboxOnlyClassifier::class, $cfg->classifierClass);   // default
        $this->assertSame('prod-agent', $cfg->channelName);                     // falls back to identity.self
        $this->assertNull($cfg->channelSocket);
        $this->assertTrue($cfg->surfaceSilentDropWarnings);
    }

    public function test_missing_identity_self_throws(): void
    {
        $raw = $this->raw();
        unset($raw['identity']);

        $this->expectException(ConfigException::class);
        AgentConfig::fromArray('a', $raw);
    }

    public function test_empty_api_section_throws(): void
    {
        $raw = $this->raw();
        $raw['api'] = [];

        $this->expectException(ConfigException::class);
        AgentConfig::fromArray('a', $raw);
    }

    public function test_receiver_url_rejects_whitespace(): void
    {
        $this->expectException(ConfigException::class);
        AgentConfig::fromArray('a', $this->raw(['receiver' => ['base_url' => 'https://bad host.example.com/webhooks']]));
    }

    public function test_receiver_url_rejects_missing_scheme(): void
    {
        $this->expectException(ConfigException::class);
        AgentConfig::fromArray('a', $this->raw(['receiver' => ['base_url' => 'bridge.example.com/webhooks']]));
    }

    public function test_subscriptions_expand_multiple_scopes(): void
    {
        $cfg = AgentConfig::fromArray('a', $this->raw([
            'subscriptions' => [['provider' => 'kanban', 'scopes' => [5, 6, 7]]],
        ]));

        $this->assertCount(3, $cfg->subscriptions);
        $this->assertSame(['5', '6', '7'], array_map(fn ($s) => $s->scopeId, $cfg->subscriptions));
    }

    public function test_subscription_invalid_scope_throws(): void
    {
        $this->expectException(ConfigException::class);
        AgentConfig::fromArray('a', $this->raw([
            'subscriptions' => [['provider' => 'kanban', 'scopes' => ['../etc/passwd']]],
        ]));
    }

    public function test_subscription_invalid_provider_throws(): void
    {
        $this->expectException(ConfigException::class);
        AgentConfig::fromArray('a', $this->raw([
            'subscriptions' => [['provider' => 'GitLab', 'scopes' => [5]]],
        ]));
    }

    public function test_classifier_class_override_and_backslash_trim(): void
    {
        $cfg = AgentConfig::fromArray('a', $this->raw([
            'classifier' => ['class' => '\\'.EventDrivenClassifier::class],
        ]));

        $this->assertSame(EventDrivenClassifier::class, $cfg->classifierClass);
    }

    public function test_channel_name_explicit_validated(): void
    {
        $cfg = AgentConfig::fromArray('a', $this->raw(['channel' => ['name' => 'kanbanboard-agent']]));
        $this->assertSame('kanbanboard-agent', $cfg->channelName);
    }

    public function test_channel_name_invalid_throws(): void
    {
        $this->expectException(ConfigException::class);
        AgentConfig::fromArray('a', $this->raw(['channel' => ['name' => 'Has Spaces']]));
    }

    public function test_channel_socket_validated(): void
    {
        $cfg = AgentConfig::fromArray('a', $this->raw(['channel' => ['socket' => '/run/user/1000/x.sock']]));
        $this->assertSame('/run/user/1000/x.sock', $cfg->channelSocket);
    }

    public function test_channel_socket_relative_throws(): void
    {
        $this->expectException(ConfigException::class);
        AgentConfig::fromArray('a', $this->raw(['channel' => ['socket' => 'relative/x.sock']]));
    }

    public function test_channel_url_parsed(): void
    {
        $cfg = AgentConfig::fromArray('a', $this->raw(['channel' => ['url' => 'http://127.0.0.1:8788/']]));
        $this->assertSame('http://127.0.0.1:8788/', $cfg->channelUrl);
        $this->assertNull($cfg->channelSocket);
    }

    public function test_channel_url_with_whitespace_throws(): void
    {
        $this->expectException(ConfigException::class);
        AgentConfig::fromArray('a', $this->raw(['channel' => ['url' => 'http://127.0.0.1 :8788/']]));
    }

    public function test_channel_socket_and_url_mutually_exclusive_throws(): void
    {
        $this->expectException(ConfigException::class);
        AgentConfig::fromArray('a', $this->raw(['channel' => ['socket' => '/run/x.sock', 'url' => 'http://127.0.0.1:8788/']]));
    }

    public function test_channel_route_intents_defaults_false(): void
    {
        $cfg = AgentConfig::fromArray('a', $this->raw());
        $this->assertFalse($cfg->channelRouteIntents);
    }

    public function test_channel_route_intents_parsed_with_socket(): void
    {
        $cfg = AgentConfig::fromArray('a', $this->raw(['channel' => ['socket' => '/run/user/1000/x.sock', 'route_intents' => true]]));
        $this->assertTrue($cfg->channelRouteIntents);
    }

    public function test_channel_route_intents_non_bool_throws(): void
    {
        $this->expectException(ConfigException::class);
        AgentConfig::fromArray('a', $this->raw(['channel' => ['socket' => '/run/x.sock', 'route_intents' => 'yes']]));
    }

    public function test_channel_route_intents_without_target_throws(): void
    {
        // route_intents needs somewhere to route — no socket and no url.
        $this->expectException(ConfigException::class);
        AgentConfig::fromArray('a', $this->raw(['channel' => ['route_intents' => true]]));
    }

    public function test_surface_silent_drop_warnings_bool(): void
    {
        $cfg = AgentConfig::fromArray('a', $this->raw(['surface' => ['silent_drop_warnings' => false]]));
        $this->assertFalse($cfg->surfaceSilentDropWarnings);
    }

    public function test_surface_silent_drop_warnings_non_bool_throws(): void
    {
        $this->expectException(ConfigException::class);
        AgentConfig::fromArray('a', $this->raw(['surface' => ['silent_drop_warnings' => 'yes']]));
    }

    public function test_non_mapping_classifier_section_throws(): void
    {
        // A plausible operator typo: `classifier: SomeName` instead of
        // `classifier: {class: SomeName}`. Must fail loud, not silently use
        // the default classifier.
        $this->expectException(ConfigException::class);
        AgentConfig::fromArray('a', $this->raw(['classifier' => 'SomeClassName']));
    }

    public function test_non_mapping_surface_section_throws(): void
    {
        $this->expectException(ConfigException::class);
        AgentConfig::fromArray('a', $this->raw(['surface' => 'yes']));
    }

    public function test_unknown_top_level_key_warns(): void
    {
        Log::spy();
        AgentConfig::fromArray('prod-agent', $this->raw(['identitiy' => ['self' => 'typo']]));

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $msg) => str_contains($msg, 'identitiy') && str_contains($msg, 'unknown top-level key')
        )->once();
    }

    public function test_legacy_db_and_secrets_keys_are_tolerated_without_warning(): void
    {
        Log::spy();
        $cfg = AgentConfig::fromArray('prod-agent', $this->raw([
            'db' => ['dsn' => 'mysql://u:p@h/agent_webhook_bridge_prod'],
            'secrets' => ['base_dir' => '/home/x/.config/agent-webhook-bridge-prod'],
        ]));

        $this->assertSame('prod-agent', $cfg->selfIdentity);   // loads fine, db/secrets ignored
        Log::shouldNotHaveReceived('warning');
    }

    public function test_load_reads_yaml_file(): void
    {
        $dir = sys_get_temp_dir().'/agentcfg-'.uniqid();
        File::ensureDirectoryExists($dir);
        File::put($dir.'/prod-agent.yml', <<<'YAML'
        identity:
          self: prod-agent
        api:
          kanban:
            base_url: https://kanban.example.com/api/v3
            token_path: /tokens/kanban
        receiver:
          base_url: https://bridge.example.com/webhooks
        subscriptions:
          - provider: kanban
            scopes: [5]
        YAML);

        $cfg = AgentConfig::load('prod-agent', $dir);
        $this->assertSame('prod-agent', $cfg->selfIdentity);
        $this->assertSame('5', $cfg->subscriptions[0]->scopeId);

        File::deleteDirectory($dir);
    }

    public function test_load_missing_file_throws(): void
    {
        $this->expectException(ConfigException::class);
        AgentConfig::load('nope', sys_get_temp_dir());
    }

    public function test_load_invalid_yaml_throws(): void
    {
        $dir = sys_get_temp_dir().'/agentcfg-'.uniqid();
        File::ensureDirectoryExists($dir);
        File::put($dir.'/bad.yml', "identity:\n  self: x\n :\n  - broken: [unclosed");

        try {
            $this->expectException(ConfigException::class);
            AgentConfig::load('bad', $dir);
        } finally {
            File::deleteDirectory($dir);
        }
    }
}
