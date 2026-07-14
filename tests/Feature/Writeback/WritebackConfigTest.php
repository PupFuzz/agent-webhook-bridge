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

    // --- DL-194: unpark_from_stages / hold_marker_tags / draft_block_reason ---

    public function test_loads_unpark_from_stages(): void
    {
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'stages' => ['started' => 49], 'unpark_from_stages' => [51]],
        ]]));
        $this->assertSame([51], WritebackConfig::load($this->dir)->mappingFor('o/r')->unparkFromStages);
    }

    public function test_absent_unpark_from_stages_is_null(): void
    {
        $this->write(json_encode(['mappings' => ['o/r' => ['board_id' => 8, 'stages' => ['started' => 49]]]]));
        $this->assertNull(WritebackConfig::load($this->dir)->mappingFor('o/r')->unparkFromStages);
    }

    public function test_non_list_unpark_from_stages_throws(): void
    {
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'stages' => ['started' => 49], 'unpark_from_stages' => ['a' => 51]],
        ]]));
        $this->expectException(ConfigException::class);
        WritebackConfig::load($this->dir);
    }

    public function test_empty_unpark_from_stages_throws(): void
    {
        // An empty list silently disables auto-unpark (fail-closed but invisible) —
        // reject it so the operator omits the key to disable instead.
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'stages' => ['started' => 49], 'unpark_from_stages' => []],
        ]]));
        $this->expectException(ConfigException::class);
        WritebackConfig::load($this->dir);
    }

    public function test_non_numeric_unpark_from_stages_element_throws(): void
    {
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'stages' => ['started' => 49], 'unpark_from_stages' => [51, 'held']],
        ]]));
        $this->expectException(ConfigException::class);
        WritebackConfig::load($this->dir);
    }

    public function test_unpark_overlapping_started_from_stages_throws(): void
    {
        // Fail-closed: a stage cannot be both refuse-if-pinned (started_from_stages)
        // and move-if-pinned (unpark_from_stages).
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'stages' => ['started' => 49], 'started_from_stages' => [46, 51], 'unpark_from_stages' => [51]],
        ]]));
        $this->expectException(ConfigException::class);
        WritebackConfig::load($this->dir);
    }

    public function test_disjoint_started_and_unpark_stages_load_together(): void
    {
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'stages' => ['started' => 49], 'started_from_stages' => [46, 47], 'unpark_from_stages' => [51]],
        ]]));
        $mapping = WritebackConfig::load($this->dir)->mappingFor('o/r');
        $this->assertSame([46, 47], $mapping->startedFromStages);
        $this->assertSame([51], $mapping->unparkFromStages);
    }

    public function test_loads_hold_marker_tags(): void
    {
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'stages' => ['started' => 49], 'hold_marker_tags' => ['gate', 'parked']],
        ]]));
        $this->assertSame(['gate', 'parked'], WritebackConfig::load($this->dir)->mappingFor('o/r')->holdMarkerTags);
    }

    public function test_absent_hold_marker_tags_defaults_to_empty_list(): void
    {
        $this->write(json_encode(['mappings' => ['o/r' => ['board_id' => 8, 'stages' => ['started' => 49]]]]));
        $this->assertSame([], WritebackConfig::load($this->dir)->mappingFor('o/r')->holdMarkerTags);
    }

    public function test_empty_hold_marker_tags_is_allowed(): void
    {
        // Unlike unpark_from_stages, an empty hold_marker_tags is the meaningful
        // "no marker declared" state (fail-safe alerts on a bare park), not disabled.
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'stages' => ['started' => 49], 'hold_marker_tags' => []],
        ]]));
        $this->assertSame([], WritebackConfig::load($this->dir)->mappingFor('o/r')->holdMarkerTags);
    }

    public function test_non_list_hold_marker_tags_throws(): void
    {
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'stages' => ['started' => 49], 'hold_marker_tags' => ['x' => 'gate']],
        ]]));
        $this->expectException(ConfigException::class);
        WritebackConfig::load($this->dir);
    }

    public function test_non_string_hold_marker_tag_element_throws(): void
    {
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'stages' => ['started' => 49], 'hold_marker_tags' => ['gate', 7]],
        ]]));
        $this->expectException(ConfigException::class);
        WritebackConfig::load($this->dir);
    }

    public function test_loads_draft_block_reason(): void
    {
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'stages' => ['started' => 49], 'draft_block_reason' => 'draft in progress'],
        ]]));
        $this->assertSame('draft in progress', WritebackConfig::load($this->dir)->mappingFor('o/r')->draftBlockReason);
    }

    public function test_absent_draft_block_reason_is_null(): void
    {
        // Absent ⇒ null; the handler resolves the KanbanBlockReasonHandler::MARKER default.
        $this->write(json_encode(['mappings' => ['o/r' => ['board_id' => 8, 'stages' => ['started' => 49]]]]));
        $this->assertNull(WritebackConfig::load($this->dir)->mappingFor('o/r')->draftBlockReason);
    }

    public function test_empty_draft_block_reason_throws(): void
    {
        // An empty string would collapse the benign-draft/human-hold distinction and
        // silently disable draft-park suppression (a noise regression) — reject it.
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'stages' => ['started' => 49], 'draft_block_reason' => ''],
        ]]));
        $this->expectException(ConfigException::class);
        WritebackConfig::load($this->dir);
    }

    public function test_non_string_draft_block_reason_throws(): void
    {
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'stages' => ['started' => 49], 'draft_block_reason' => 42],
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

    // --- FR-4: alert_channel ---

    public function test_alert_channel_absent_is_null(): void
    {
        $this->write(json_encode(['mappings' => ['o/r' => ['board_id' => 8, 'stages' => ['merged' => 52]]]]));
        $this->assertNull(WritebackConfig::load($this->dir)?->alertChannel);
    }

    public function test_alert_channel_socket_parsed(): void
    {
        $this->write(json_encode([
            'alert_channel' => ['socket' => '/run/alert.sock'],
            'mappings' => ['o/r' => ['board_id' => 8, 'stages' => ['merged' => 52]]],
        ]));
        $ac = WritebackConfig::load($this->dir)?->alertChannel;
        $this->assertNotNull($ac);
        $this->assertSame('/run/alert.sock', $ac->socket);
        $this->assertNull($ac->url);
        $this->assertNull($ac->tokenPath);
    }

    public function test_alert_channel_url_with_token_parsed(): void
    {
        $this->write(json_encode([
            'alert_channel' => ['url' => 'http://127.0.0.1:9931/', 'auth' => ['token_path' => '/secret/tok']],
            'mappings' => ['o/r' => ['board_id' => 8, 'stages' => ['merged' => 52]]],
        ]));
        $ac = WritebackConfig::load($this->dir)?->alertChannel;
        $this->assertNotNull($ac);
        $this->assertSame('http://127.0.0.1:9931/', $ac->url);
        $this->assertSame('/secret/tok', $ac->tokenPath);
        $this->assertNull($ac->socket);
    }

    public function test_malformed_alert_channel_does_not_fail_the_config_closed(): void
    {
        // A malformed alert_channel (both socket+url) is an opt-in diagnostic — it
        // must NOT disable the whole writeback; it loads, and bridge:check / the
        // notifier surface/handle the bad channel.
        $this->write(json_encode([
            'alert_channel' => ['socket' => '/run/alert.sock', 'url' => 'http://127.0.0.1:9931/'],
            'mappings' => ['o/r' => ['board_id' => 8, 'stages' => ['merged' => 52]]],
        ]));
        $cfg = WritebackConfig::load($this->dir);   // does not throw
        $this->assertNotNull($cfg);
        $this->assertNotNull($cfg->mappingFor('o/r'));   // mappings still usable
    }

    public function test_alert_channel_socket_expands_runtime_tokens(): void
    {
        // FR-A: alert_channel.socket gets the same DL-039 ${XDG_RUNTIME_DIR}/${uid}
        // expansion channel.socket has — applied at load, so the resolved path flows
        // to both the runtime push and bridge:check.
        $prev = getenv('XDG_RUNTIME_DIR');
        putenv('XDG_RUNTIME_DIR=/tmp/xdg-fr-a');
        try {
            $this->write(json_encode([
                'alert_channel' => ['socket' => '${XDG_RUNTIME_DIR}/agent-webhook-bridge-channel-x.sock'],
                'mappings' => ['o/r' => ['board_id' => 8, 'stages' => ['merged' => 52]]],
            ]));
            $ac = WritebackConfig::load($this->dir)?->alertChannel;
            $this->assertNotNull($ac);
            $this->assertSame('/tmp/xdg-fr-a/agent-webhook-bridge-channel-x.sock', $ac->socket);
        } finally {
            putenv($prev === false ? 'XDG_RUNTIME_DIR' : "XDG_RUNTIME_DIR={$prev}");
        }
    }

    public function test_alert_channel_unresolvable_socket_token_degrades_not_throws(): void
    {
        // DL-171 fail-OPEN: an unresolvable ${...} token in alert_channel.socket must NOT
        // fail the whole writeback closed (unlike the fail-closed channel.socket). The
        // expansion throw is caught; the unexpanded value is kept so SocketPath::isValid
        // rejects it → bridge:check warns + the runtime push is caught (log-only).
        $this->write(json_encode([
            'alert_channel' => ['socket' => '${BOGUS}/a.sock'],
            'mappings' => ['o/r' => ['board_id' => 8, 'stages' => ['merged' => 52]]],
        ]));
        $cfg = WritebackConfig::load($this->dir);   // does not throw
        $this->assertNotNull($cfg);
        $this->assertNotNull($cfg->mappingFor('o/r'));                       // mappings still usable
        $this->assertSame('${BOGUS}/a.sock', $cfg->alertChannel?->socket);   // kept unexpanded
    }

    public function test_board_is_shared_detects_multi_repo_boards(): void
    {
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 1,
            'mappings' => [
                'octo/web' => ['board_id' => 8, 'stages' => ['opened' => 50]],
                'octo/api' => ['board_id' => 8, 'stages' => ['opened' => 50]],
                'octo/cli' => ['board_id' => 12, 'stages' => ['opened' => 87]],
            ],
        ]));
        $cfg = WritebackConfig::load($this->dir);
        $this->assertTrue($cfg->boardIsShared(8));
        $this->assertFalse($cfg->boardIsShared(12));
        $this->assertFalse($cfg->boardIsShared(999));
    }
}
