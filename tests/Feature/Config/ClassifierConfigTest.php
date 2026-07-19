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

        $this->assertSame([], $cfg->scopeAuthorMap);
        $this->assertSame([], $cfg->enabledFamilies);
        $this->assertSame([], $cfg->raw);
    }

    public function test_parses_cross_cutting_knobs(): void
    {
        $cfg = ClassifierConfig::fromClassifierSection([
            'class' => 'App\\Some\\Classifier',
            'config' => [
                'scope_author_map' => ['Sola/Device' => 'device', 'sola/backend' => 'backend'],
                'families' => ['coord-message', 'impl-ci-wake'],
            ],
        ]);

        // scope_id keys lowercased for case-insensitive scope matching
        $this->assertSame(['sola/device' => 'device', 'sola/backend' => 'backend'], $cfg->scopeAuthorMap);
        $this->assertSame(['coord-message', 'impl-ci-wake'], $cfg->enabledFamilies);
    }

    public function test_generic_accessors_read_family_specific_config(): void
    {
        $cfg = ClassifierConfig::fromClassifierSection([
            'config' => [
                'release_branch' => 'main',
                'sample_list' => ['Failure', 'TIMED_OUT'],   // arbitrary key — exercises strings() lowercasing generically
                'impl_ci_wake' => ['provenance_patterns' => ['SLSA', 'Auto-tag']],
            ],
        ]);

        $this->assertSame('main', $cfg->string('release_branch', 'trunk'));
        $this->assertSame('trunk', $cfg->string('missing_key', 'trunk'));           // default
        $this->assertSame(['failure', 'timed_out'], $cfg->strings('sample_list')); // lowercased
        $this->assertSame(['a'], $cfg->strings('missing_list', ['a']));             // default
        $this->assertSame(['provenance_patterns' => ['SLSA', 'Auto-tag']], $cfg->section('impl_ci_wake'));
        $this->assertSame([], $cfg->section('missing_section'));
    }

    public function test_section_non_mapping_throws_with_the_prefixed_label(): void
    {
        $cfg = ClassifierConfig::fromClassifierSection(['config' => ['impl_ci_wake' => 'scalar']]);
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('classifier.config.impl_ci_wake must be a mapping');
        $cfg->section('impl_ci_wake');
    }

    public function test_has_distinguishes_an_explicit_key_from_a_defaulted_one(): void
    {
        // DL-213: the explicit-vs-default distinction a warn needs — an explicitly-set
        // key (even to a falsy/empty value) reads present; an absent one reads absent
        // regardless of what strings()'s default would supply.
        $cfg = ClassifierConfig::fromClassifierSection(['config' => ['wake_membership' => ['to_me'], 'empty_key' => []]]);

        $this->assertTrue($cfg->has('wake_membership'));
        $this->assertTrue($cfg->has('empty_key'));       // present-but-empty is still present
        $this->assertFalse($cfg->has('never_set'));
    }

    public function test_string_groups_parses_and_lowercases(): void
    {
        $cfg = ClassifierConfig::fromClassifierSection([
            'config' => [
                'drop_title_all_of' => [
                    ['Rule E back-merge sync', 'paper-trail anchor'],
                    ['some other anchor'],
                ],
            ],
        ]);

        $this->assertSame([
            ['rule e back-merge sync', 'paper-trail anchor'],
            ['some other anchor'],
        ], $cfg->stringGroups('drop_title_all_of'));
        $this->assertSame([], $cfg->stringGroups('missing_groups')); // absent ⇒ []
    }

    public function test_string_groups_non_list_throws(): void
    {
        $cfg = ClassifierConfig::fromClassifierSection(['config' => ['drop_title_all_of' => 'not-a-list']]);
        $this->expectException(ConfigException::class);
        $cfg->stringGroups('drop_title_all_of');
    }

    public function test_string_groups_non_list_group_throws(): void
    {
        $cfg = ClassifierConfig::fromClassifierSection(['config' => ['drop_title_all_of' => ['not-a-group']]]);
        $this->expectException(ConfigException::class);
        $cfg->stringGroups('drop_title_all_of');
    }

    public function test_string_groups_empty_substring_throws(): void
    {
        $cfg = ClassifierConfig::fromClassifierSection(['config' => ['drop_title_all_of' => [['ok', '']]]]);
        $this->expectException(ConfigException::class);
        $cfg->stringGroups('drop_title_all_of');
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

    public function test_agent_config_wires_classifier_config(): void
    {
        $cfg = AgentConfig::fromArray('pm', [
            'identity' => ['github_user_id' => 269788076],
            'subscriptions' => [],
            'classifier' => [
                'class' => 'App\\Bridge\\Classifiers\\CoordinationClassifier',
                'config' => ['scope_author_map' => ['org/impl' => 'device'], 'families' => ['coord-message']],
            ],
        ]);

        $this->assertSame(['org/impl' => 'device'], $cfg->classifierConfig->scopeAuthorMap);
        $this->assertSame(['coord-message'], $cfg->classifierConfig->enabledFamilies);
    }

    public function test_agent_config_without_classifier_config_defaults_empty(): void
    {
        $cfg = AgentConfig::fromArray('pm', [
            'identity' => ['kanban_user_id' => 1],
            'subscriptions' => [],
        ]);

        $this->assertSame([], $cfg->classifierConfig->scopeAuthorMap);
        $this->assertSame([], $cfg->classifierConfig->enabledFamilies);
    }
}
