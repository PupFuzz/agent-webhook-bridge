<?php

namespace Tests\Feature\Config;

use App\Bridge\Exceptions\ConfigException;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\ClassifierConfig;
use Tests\TestCase;

class ClassifierConfigTest extends TestCase
{
    public function test_absent_config_block_yields_all_defaults(): void
    {
        $cfg = ClassifierConfig::fromClassifierSection(['class' => 'App\\Some\\Classifier']);

        $this->assertNull($cfg->sharedAccountId);
        $this->assertSame([], $cfg->scopeAuthorMap);
        $this->assertSame([], $cfg->enabledFamilies);
        $this->assertSame([], $cfg->raw);
    }

    public function test_parses_cross_cutting_knobs(): void
    {
        $cfg = ClassifierConfig::fromClassifierSection([
            'class' => 'App\\Some\\Classifier',
            'config' => [
                'shared_account_id' => 269788076,
                'scope_author_map' => ['Sola/Device' => 'device', 'sola/backend' => 'backend'],
                'families' => ['coord-message', 'impl-ci-wake'],
            ],
        ]);

        $this->assertSame('269788076', $cfg->sharedAccountId);          // scalar coerced to string
        // scope_id keys lowercased for case-insensitive scope matching
        $this->assertSame(['sola/device' => 'device', 'sola/backend' => 'backend'], $cfg->scopeAuthorMap);
        $this->assertSame(['coord-message', 'impl-ci-wake'], $cfg->enabledFamilies);
    }

    public function test_generic_accessors_read_family_specific_config(): void
    {
        $cfg = ClassifierConfig::fromClassifierSection([
            'config' => [
                'release_branch' => 'main',
                'wake_conclusions' => ['Failure', 'TIMED_OUT'],
                'impl_ci_wake' => ['provenance_patterns' => ['SLSA', 'Auto-tag']],
            ],
        ]);

        $this->assertSame('main', $cfg->string('release_branch', 'trunk'));
        $this->assertSame('trunk', $cfg->string('missing_key', 'trunk'));           // default
        $this->assertSame(['failure', 'timed_out'], $cfg->strings('wake_conclusions')); // lowercased
        $this->assertSame(['a'], $cfg->strings('missing_list', ['a']));             // default
        $this->assertSame(['provenance_patterns' => ['SLSA', 'Auto-tag']], $cfg->section('impl_ci_wake'));
        $this->assertSame([], $cfg->section('missing_section'));
    }

    public function test_non_mapping_config_throws(): void
    {
        $this->expectException(ConfigException::class);
        ClassifierConfig::fromClassifierSection(['config' => 'not-a-map']);
    }

    public function test_malformed_scope_author_map_throws(): void
    {
        $this->expectException(ConfigException::class);
        ClassifierConfig::fromClassifierSection(['config' => ['scope_author_map' => ['owner/repo' => '']]]);
    }

    public function test_empty_string_in_list_throws(): void
    {
        $this->expectException(ConfigException::class);
        ClassifierConfig::fromClassifierSection(['config' => ['families' => ['coord-message', '']]]);
    }

    public function test_blank_shared_account_id_throws(): void
    {
        $this->expectException(ConfigException::class);
        ClassifierConfig::fromClassifierSection(['config' => ['shared_account_id' => '']]);
    }

    public function test_agent_config_wires_classifier_config(): void
    {
        $cfg = AgentConfig::fromArray('pm', [
            'identity' => ['github_user_id' => 269788076],
            'subscriptions' => [],
            'classifier' => [
                'class' => 'App\\Bridge\\Classifiers\\CoordinationClassifier',
                'config' => ['shared_account_id' => 269788076, 'families' => ['coord-message']],
            ],
        ]);

        $this->assertSame('269788076', $cfg->classifierConfig->sharedAccountId);
        $this->assertSame(['coord-message'], $cfg->classifierConfig->enabledFamilies);
    }

    public function test_agent_config_without_classifier_config_defaults_empty(): void
    {
        $cfg = AgentConfig::fromArray('pm', [
            'identity' => ['kanban_user_id' => 1],
            'subscriptions' => [],
        ]);

        $this->assertNull($cfg->classifierConfig->sharedAccountId);
        $this->assertSame([], $cfg->classifierConfig->enabledFamilies);
    }
}
