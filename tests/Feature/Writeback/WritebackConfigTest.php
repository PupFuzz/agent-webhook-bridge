<?php

namespace Tests\Feature\Writeback;

use App\Bridge\Exceptions\ConfigException;
use App\Bridge\Writeback\WritebackConfig;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class WritebackConfigTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/wb-'.uniqid();
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function write(string $json): void
    {
        File::put($this->dir.'/writeback.json', $json);
    }

    public function test_absent_file_is_null_writeback_disabled(): void
    {
        $this->assertNull(WritebackConfig::load($this->dir));
    }

    public function test_loads_identity_and_mappings(): void
    {
        $this->write(json_encode([
            'identity_id' => 4242,
            'mappings' => [
                'owner/repo' => ['board_id' => 8, 'stages' => ['opened' => 50, 'merged' => 52, 'merged_to_main' => 53, 'closed_unmerged' => 49]],
            ],
        ]));

        $cfg = WritebackConfig::load($this->dir);
        $this->assertNotNull($cfg);
        $this->assertSame(4242, $cfg->identityId);
        $mapping = $cfg->mappingFor('owner/repo');
        $this->assertNotNull($mapping);
        $this->assertSame(8, $mapping->boardId);
        $this->assertSame(52, $mapping->stageFor('merged'));
        $this->assertSame(53, $mapping->stageFor('merged_to_main'));
        $this->assertNull($mapping->stageFor('unmapped_outcome'));
        $this->assertNull($cfg->mappingFor('other/repo'));
        $this->assertNull($mapping->swimlaneId);   // DL-027: absent ⇒ null
    }

    public function test_loads_optional_swimlane_id(): void
    {
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'swimlane_id' => 31, 'stages' => ['opened' => 50]],
        ]]));

        $this->assertSame(31, WritebackConfig::load($this->dir)->mappingFor('o/r')->swimlaneId);
    }

    public function test_non_numeric_swimlane_id_throws(): void
    {
        // Strict like board_id/stages (not the identity_id silent-null pattern) —
        // a typo must NOT fail-quiet into the default lane (DL-027).
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'swimlane_id' => 'lane-a', 'stages' => ['opened' => 50]],
        ]]));

        $this->expectException(ConfigException::class);
        WritebackConfig::load($this->dir);
    }

    public function test_loads_started_outcome_and_promote_from_stages(): void
    {
        // DL-160: `started` is a valid outcome and `started_from_stages` is parsed
        // as a numeric list.
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'stages' => ['started' => 49], 'started_from_stages' => [46, 47]],
        ]]));

        $mapping = WritebackConfig::load($this->dir)->mappingFor('o/r');
        $this->assertSame(49, $mapping->stageFor('started'));
        $this->assertSame([46, 47], $mapping->startedFromStages);
    }

    public function test_absent_started_from_stages_is_null(): void
    {
        $this->write(json_encode(['mappings' => ['o/r' => ['board_id' => 8, 'stages' => ['started' => 49]]]]));
        $this->assertNull(WritebackConfig::load($this->dir)->mappingFor('o/r')->startedFromStages);
    }

    public function test_non_list_started_from_stages_throws(): void
    {
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'stages' => ['started' => 49], 'started_from_stages' => ['a' => 46]],
        ]]));
        $this->expectException(ConfigException::class);
        WritebackConfig::load($this->dir);
    }

    public function test_empty_started_from_stages_throws(): void
    {
        // An empty list would silently disable the `started` move (fail-closed but
        // invisible); reject it so the operator omits the key to disable instead.
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'stages' => ['started' => 49], 'started_from_stages' => []],
        ]]));
        $this->expectException(ConfigException::class);
        WritebackConfig::load($this->dir);
    }

    public function test_non_numeric_started_from_stages_element_throws(): void
    {
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'stages' => ['started' => 49], 'started_from_stages' => [46, 'backlog']],
        ]]));
        $this->expectException(ConfigException::class);
        WritebackConfig::load($this->dir);
    }

    public function test_malformed_json_is_fail_closed(): void
    {
        $this->write('not json {');
        $this->expectException(ConfigException::class);
        WritebackConfig::load($this->dir);
    }

    public function test_unknown_stage_outcome_throws(): void
    {
        $this->write(json_encode(['mappings' => ['o/r' => ['board_id' => 8, 'stages' => ['bogus' => 1]]]]));
        $this->expectException(ConfigException::class);
        WritebackConfig::load($this->dir);
    }

    public function test_missing_board_id_throws(): void
    {
        $this->write(json_encode(['mappings' => ['o/r' => ['stages' => ['merged' => 52]]]]));
        $this->expectException(ConfigException::class);
        WritebackConfig::load($this->dir);
    }

    public function test_non_array_stages_throws_configexception_not_type_error(): void
    {
        // Sibling of the other guards — a non-object `stages` must be a clean
        // ConfigException, not a raw TypeError from foreach.
        $this->write(json_encode(['mappings' => ['o/r' => ['board_id' => 8, 'stages' => 'nope']]]));
        $this->expectException(ConfigException::class);
        WritebackConfig::load($this->dir);
    }

    public function test_top_level_json_list_is_rejected(): void
    {
        $this->write(json_encode([1, 2, 3]));
        $this->expectException(ConfigException::class);
        WritebackConfig::load($this->dir);
    }
}
