<?php

namespace Tests\Feature\Config;

use App\Bridge\Exceptions\ConfigException;
use App\Bridge\Support\AgentConfig;
use Tests\TestCase;

class BoardToolsConfigTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $extra
     */
    private function config(array $extra): AgentConfig
    {
        return AgentConfig::fromArray('me', array_merge([
            'identity' => ['kanban_user_id' => 1],
            'subscriptions' => [],
        ], $extra));
    }

    public function test_absent_board_tools_is_a_no_op(): void
    {
        $this->assertNull($this->config([])->boardTools);
    }

    public function test_disabled_block_needs_no_scope_fields(): void
    {
        $bt = $this->config(['board_tools' => ['enabled' => false]])->boardTools;
        $this->assertNotNull($bt);
        $this->assertFalse($bt->enabled);
        $this->assertNull($bt->tokenPath);
    }

    public function test_enabled_block_parses_the_full_scope(): void
    {
        $bt = $this->config(['board_tools' => [
            'enabled' => true,
            'auth' => ['token_path' => '/secrets/tools-token'],
            'board_id' => 10,
            'swimlane_id' => 4,
            'create_stage_id' => 55,
            'shared_swimlane_id' => 9,
            'coord_board_id' => 11,
            'address_tags' => ['repo:me'],
        ]])->boardTools;

        $this->assertNotNull($bt);
        $this->assertTrue($bt->enabled);
        $this->assertSame('/secrets/tools-token', $bt->tokenPath);
        $this->assertSame(10, $bt->boardId);
        $this->assertSame(4, $bt->swimlaneId);
        $this->assertSame(55, $bt->createStageId);
        $this->assertSame(9, $bt->sharedSwimlaneId);
        $this->assertSame(11, $bt->coordBoardId);
        $this->assertSame(['repo:me'], $bt->addressTags);
    }

    public function test_enabled_without_token_path_throws_at_load(): void
    {
        $this->expectException(ConfigException::class);
        $this->config(['board_tools' => [
            'enabled' => true,
            'board_id' => 10, 'swimlane_id' => 4, 'create_stage_id' => 55,
        ]]);
    }

    public function test_enabled_without_swimlane_throws_at_load(): void
    {
        $this->expectException(ConfigException::class);
        $this->config(['board_tools' => [
            'enabled' => true,
            'auth' => ['token_path' => '/t'],
            'board_id' => 10, 'create_stage_id' => 55,
        ]]);
    }

    public function test_non_mapping_block_throws(): void
    {
        $this->expectException(ConfigException::class);
        $this->config(['board_tools' => 'yes']);
    }

    public function test_non_boolean_enabled_throws(): void
    {
        $this->expectException(ConfigException::class);
        $this->config(['board_tools' => ['enabled' => 'true']]);
    }

    public function test_address_tags_without_coord_board_throws(): void
    {
        $this->expectException(ConfigException::class);
        $this->config(['board_tools' => [
            'enabled' => true,
            'auth' => ['token_path' => '/t'],
            'board_id' => 10, 'swimlane_id' => 4, 'create_stage_id' => 55,
            'address_tags' => ['repo:me'],
        ]]);
    }

    public function test_string_id_field_throws(): void
    {
        $this->expectException(ConfigException::class);
        $this->config(['board_tools' => [
            'enabled' => true,
            'auth' => ['token_path' => '/t'],
            'board_id' => '10', 'swimlane_id' => 4, 'create_stage_id' => 55,
        ]]);
    }
}
