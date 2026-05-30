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
     * v2 minimal config: the filename is the agent name (no identity.self), and
     * per-install endpoints (receiver / api base urls) are in config/bridge.php,
     * not here.
     *
     * @param  array<mixed>  $overrides
     * @return array<mixed>
     */
    private function raw(array $overrides = []): array
    {
        return array_replace_recursive([
            'identity' => ['kanban_user_id' => 137],
            'subscriptions' => [['provider' => 'kanban', 'scopes' => [5], 'event_filter' => ['task.*']]],
        ], $overrides);
    }

    public function test_parses_a_valid_config(): void
    {
        $cfg = AgentConfig::fromArray('prod-agent', $this->raw());

        $this->assertSame('prod-agent', $cfg->agentName);
        $this->assertSame(137, $cfg->kanbanUserId);
        $this->assertNull($cfg->githubUserId);
        $this->assertCount(1, $cfg->subscriptions);
        $this->assertSame('kanban', $cfg->subscriptions[0]->provider);
        $this->assertSame('5', $cfg->subscriptions[0]->scopeId);
        $this->assertSame(['137'], $cfg->echoSuppression->treatAsEchoIds);   // auto-seeded from identity
        $this->assertSame(InboxOnlyClassifier::class, $cfg->classifierClass);  // default
        $this->assertNull($cfg->channelSocket);
        $this->assertNull($cfg->channelUrl);
        $this->assertFalse($cfg->channelRouteIntents);
        $this->assertTrue($cfg->surfaceSilentDropWarnings);
    }

    public function test_identity_ids_parsed(): void
    {
        $cfg = AgentConfig::fromArray('pm', $this->raw([
            'identity' => ['kanban_user_id' => 100, 'github_user_id' => 9001, 'github_login' => 'pm-bot'],
        ]));

        $this->assertSame(100, $cfg->kanbanUserId);
        $this->assertSame(9001, $cfg->githubUserId);
        $this->assertSame('pm-bot', $cfg->githubLogin);
    }

    public function test_self_echo_ids_are_auto_seeded_from_identity(): void
    {
        // No echo_suppression block at all — the agent's own ids are still
        // suppressed (the operator never hand-lists self ids).
        $cfg = AgentConfig::fromArray('pm', [
            'identity' => ['kanban_user_id' => 100, 'github_user_id' => 9001],
            'subscriptions' => [],
        ]);

        $this->assertEqualsCanonicalizing(['100', '9001'], $cfg->echoSuppression->treatAsEchoIds);
    }

    public function test_explicit_echo_ids_union_with_self_ids(): void
    {
        $cfg = AgentConfig::fromArray('pm', $this->raw([
            'identity' => ['kanban_user_id' => 137],
            'echo_suppression' => ['treat_as_echo_ids' => ['50']],
        ]));

        $this->assertEqualsCanonicalizing(['50', '137'], $cfg->echoSuppression->treatAsEchoIds);
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
        $this->expectException(ConfigException::class);
        AgentConfig::fromArray('a', $this->raw(['channel' => ['route_intents' => true]]));
    }

    public function test_token_path_convention_and_override(): void
    {
        $cfg = AgentConfig::fromArray('pm', $this->raw());
        $this->assertSame('/secrets/kanban/token', $cfg->tokenPath('/secrets', 'kanban'));

        $overridden = AgentConfig::fromArray('pm', $this->raw(['api' => ['kanban' => ['token_path' => '/custom/tok']]]));
        $this->assertSame('/custom/tok', $overridden->tokenPath('/secrets', 'kanban'));
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
        // A plausible typo: `classifier: SomeName` instead of `{class: SomeName}`.
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
        AgentConfig::fromArray('prod-agent', $this->raw(['identitiy' => ['kanban_user_id' => 1]]));

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $msg) => str_contains($msg, 'identitiy') && str_contains($msg, 'unknown top-level key')
        )->once();
    }

    public function test_load_reads_yaml_file(): void
    {
        $dir = sys_get_temp_dir().'/agentcfg-'.uniqid();
        File::ensureDirectoryExists($dir);
        File::put($dir.'/prod-agent.yml', <<<'YAML'
        identity:
          kanban_user_id: 137
        subscriptions:
          - provider: kanban
            scopes: [5]
        YAML);

        $cfg = AgentConfig::load('prod-agent', $dir);
        $this->assertSame('prod-agent', $cfg->agentName);
        $this->assertSame(137, $cfg->kanbanUserId);
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
        File::put($dir.'/bad.yml', "identity:\n  kanban_user_id: 1\n :\n  - broken: [unclosed");

        try {
            $this->expectException(ConfigException::class);
            AgentConfig::load('bad', $dir);
        } finally {
            File::deleteDirectory($dir);
        }
    }
}
