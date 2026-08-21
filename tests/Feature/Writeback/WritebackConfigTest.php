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

    public function test_promote_on_release_defaults_false_and_parses_true(): void
    {
        $this->write(json_encode([
            'mappings' => [
                'owner/repo' => ['board_id' => 8, 'stages' => ['merged' => 52, 'merged_to_main' => 53]],
                'owner/promo' => ['board_id' => 9, 'stages' => ['merged' => 62, 'merged_to_main' => 63], 'promote_on_release' => true],
            ],
        ]));

        $cfg = WritebackConfig::load($this->dir);
        $this->assertFalse($cfg->mappingFor('owner/repo')->promoteOnRelease);
        $this->assertTrue($cfg->mappingFor('owner/promo')->promoteOnRelease);
    }

    public function test_promote_on_release_fails_closed_without_merged_stage(): void
    {
        $this->write(json_encode([
            'mappings' => [
                'owner/repo' => ['board_id' => 8, 'stages' => ['merged_to_main' => 53], 'promote_on_release' => true],
            ],
        ]));

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageMatches('/promote_on_release .* stages\.merged/');
        WritebackConfig::load($this->dir);
    }

    public function test_promote_on_release_fails_closed_without_released_stage(): void
    {
        $this->write(json_encode([
            'mappings' => [
                'owner/repo' => ['board_id' => 8, 'stages' => ['merged' => 52], 'promote_on_release' => true],
            ],
        ]));

        $this->expectException(ConfigException::class);
        WritebackConfig::load($this->dir);
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

    public function test_loads_revive_on_reopen(): void
    {
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'stages' => ['opened' => 50, 'closed_unmerged' => 77], 'revive_on_reopen' => true],
        ]]));
        $this->assertTrue(WritebackConfig::load($this->dir)->mappingFor('o/r')->reviveOnReopen);
    }

    public function test_absent_revive_on_reopen_defaults_false(): void
    {
        $this->write(json_encode(['mappings' => ['o/r' => ['board_id' => 8, 'stages' => ['opened' => 50]]]]));
        $this->assertFalse(WritebackConfig::load($this->dir)->mappingFor('o/r')->reviveOnReopen);
    }

    public function test_non_true_revive_on_reopen_is_false(): void
    {
        // Parsed like draft_overlay/create_dependabot_cards — a non-`true` value (here a
        // string) disables it; only strict boolean true opts in.
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'stages' => ['opened' => 50], 'revive_on_reopen' => 'yes'],
        ]]));
        $this->assertFalse(WritebackConfig::load($this->dir)->mappingFor('o/r')->reviveOnReopen);
    }

    public function test_absent_issue_population_defaults_to_prefixed(): void
    {
        $this->write(json_encode(['mappings' => ['o/r' => ['board_id' => 8, 'stages' => ['opened' => 50]]]]));
        $this->assertSame('prefixed', WritebackConfig::load($this->dir)->mappingFor('o/r')->issuePopulation);
    }

    public function test_issue_population_parses_all(): void
    {
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'stages' => ['opened' => 50], 'issue_population' => 'all'],
        ]]));
        $this->assertSame('all', WritebackConfig::load($this->dir)->mappingFor('o/r')->issuePopulation);
    }

    public function test_issue_population_parses_explicit_prefixed(): void
    {
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'stages' => ['opened' => 50], 'issue_population' => 'prefixed'],
        ]]));
        $this->assertSame('prefixed', WritebackConfig::load($this->dir)->mappingFor('o/r')->issuePopulation);
    }

    public function test_unknown_issue_population_value_throws(): void
    {
        // Fail-closed: an unrecognized population must not silently degrade to prefixed
        // (which would leave an operator who typed 'everything' thinking non-prefixed
        // issues are carded when they are not).
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'stages' => ['opened' => 50], 'issue_population' => 'everything'],
        ]]));
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageMatches('/issue_population/');
        WritebackConfig::load($this->dir);
    }

    public function test_non_string_issue_population_throws(): void
    {
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'stages' => ['opened' => 50], 'issue_population' => true],
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

    // ---- DL-198: create_coord_cards + coord_card_stage_id ----

    public function test_absent_create_coord_cards_defaults_false_byte_identical(): void
    {
        // The load-bearing back-compat property: a mapping with neither key parses
        // exactly as before — createCoordCards false, coordCardStageId null.
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'stages' => ['opened' => 50]],
        ]]));

        $mapping = WritebackConfig::load($this->dir)->mappingFor('o/r');
        $this->assertFalse($mapping->createCoordCards);
        $this->assertNull($mapping->coordCardStageId);
    }

    public function test_create_coord_cards_with_stage_parses(): void
    {
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'stages' => ['opened' => 50], 'create_coord_cards' => true, 'coord_card_stage_id' => 21],
        ]]));

        $mapping = WritebackConfig::load($this->dir)->mappingFor('o/r');
        $this->assertTrue($mapping->createCoordCards);
        $this->assertSame(21, $mapping->coordCardStageId);
    }

    public function test_create_coord_cards_without_stage_throws(): void
    {
        // Fail-closed at LOAD: a create with no stage can't POST, so it must fail
        // loud, not silently no-op at dispatch.
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'stages' => ['opened' => 50], 'create_coord_cards' => true],
        ]]));

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('create_coord_cards but no coord_card_stage_id');
        WritebackConfig::load($this->dir);
    }

    public function test_non_numeric_coord_card_stage_id_throws(): void
    {
        // Strict like swimlane_id — a typo must not fail-quiet.
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'stages' => ['opened' => 50], 'create_coord_cards' => true, 'coord_card_stage_id' => 'stage-x'],
        ]]));

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('coord_card_stage_id must be a numeric');
        WritebackConfig::load($this->dir);
    }

    public function test_coord_card_stage_id_without_create_flag_is_inert_but_parsed(): void
    {
        // A stage set without the flag is allowed (no throw) — createCoordCards
        // false ⇒ the handler/classifier never act on it.
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'stages' => ['opened' => 50], 'coord_card_stage_id' => 21],
        ]]));

        $mapping = WritebackConfig::load($this->dir)->mappingFor('o/r');
        $this->assertFalse($mapping->createCoordCards);
        $this->assertSame(21, $mapping->coordCardStageId);
    }

    // ---- DL-200: move_coord_cards + coord_card_terminal_stage_id ----

    public function test_absent_move_coord_cards_defaults_false_byte_identical(): void
    {
        // The load-bearing back-compat property: a mapping with neither key parses
        // exactly as before — moveCoordCards false, coordCardTerminalStageId null.
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'stages' => ['opened' => 50]],
        ]]));

        $mapping = WritebackConfig::load($this->dir)->mappingFor('o/r');
        $this->assertFalse($mapping->moveCoordCards);
        $this->assertNull($mapping->coordCardTerminalStageId);
    }

    public function test_move_coord_cards_with_both_stages_parses(): void
    {
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'stages' => ['opened' => 50], 'move_coord_cards' => true,
                'coord_card_stage_id' => 21, 'coord_card_terminal_stage_id' => 99],
        ]]));

        $mapping = WritebackConfig::load($this->dir)->mappingFor('o/r');
        $this->assertTrue($mapping->moveCoordCards);
        $this->assertSame(99, $mapping->coordCardTerminalStageId);
    }

    public function test_move_coord_cards_without_terminal_stage_throws(): void
    {
        // Fail-closed at LOAD, exactly like create_coord_cards/coord_card_stage_id:
        // a close→terminal move with no terminal stage has nowhere to PATCH to.
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'stages' => ['opened' => 50], 'move_coord_cards' => true,
                'coord_card_stage_id' => 21],
        ]]));

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('move_coord_cards but no coord_card_terminal_stage_id');
        WritebackConfig::load($this->dir);
    }

    public function test_move_coord_cards_without_create_stage_throws(): void
    {
        // coord_card_stage_id is the REVIVE target (the stage a reopened card returns
        // to — the same stage a fresh card would be created in, mirroring DL-195's
        // "revive reuses stages.opened"). Without it a revive has nowhere to go, so
        // the move leg would silently half-work: closes land, reopens no-op.
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'stages' => ['opened' => 50], 'move_coord_cards' => true,
                'coord_card_terminal_stage_id' => 99],
        ]]));

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('but no coord_card_stage_id');
        WritebackConfig::load($this->dir);
    }

    public function test_non_numeric_coord_card_terminal_stage_id_throws(): void
    {
        // Strict like coord_card_stage_id — a typo must not fail-quiet.
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'stages' => ['opened' => 50], 'move_coord_cards' => true,
                'coord_card_stage_id' => 21, 'coord_card_terminal_stage_id' => 'done'],
        ]]));

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('coord_card_terminal_stage_id must be a numeric');
        WritebackConfig::load($this->dir);
    }

    public function test_terminal_equal_to_create_stage_throws(): void
    {
        // Disjointness, fail-closed (the DL-194 unpark_from_stages precedent): if the
        // terminal IS the create/revive stage, close→terminal and reopen→revive resolve
        // to the same stage — the leg can never express either transition, and a revive
        // would be indistinguishable from a no-op.
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'stages' => ['opened' => 50], 'move_coord_cards' => true,
                'coord_card_stage_id' => 21, 'coord_card_terminal_stage_id' => 21],
        ]]));

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('coord_card_terminal_stage_id must differ from coord_card_stage_id');
        WritebackConfig::load($this->dir);
    }

    public function test_move_coord_cards_defaults_on_when_configured_without_the_flag(): void
    {
        // DL-204 (#4357) fleet default: an ABSENT move_coord_cards resolves ON where the move
        // config is complete (terminal + revive stage present + differ). The terminal key is the
        // "operator configured move" signal, so an install whose per-board stage ids are already
        // set activates without also setting the flag (aimla board 10 / sola board 2/3 shape).
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'stages' => ['opened' => 50],
                'coord_card_stage_id' => 21, 'coord_card_terminal_stage_id' => 99],
        ]]));

        $mapping = WritebackConfig::load($this->dir)->mappingFor('o/r');
        $this->assertTrue($mapping->moveCoordCards);
        $this->assertSame(99, $mapping->coordCardTerminalStageId);
    }

    public function test_move_coord_cards_absent_and_terminal_absent_is_inert(): void
    {
        // DL-204: the byte-identical upgrade. An install that never configured a terminal stays
        // OFF — no terminal ⇒ the move leg was never configured ⇒ inert, exactly as pre-flip.
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'stages' => ['opened' => 50], 'coord_card_stage_id' => 21],
        ]]));

        $this->assertFalse(WritebackConfig::load($this->dir)->mappingFor('o/r')->moveCoordCards);
    }

    public function test_move_coord_cards_default_on_without_a_revive_stage_throws(): void
    {
        // DL-204 point 3: a PARTIAL default-on config (terminal present, revive stage absent) is
        // made LOUD by the existing fail-closed guard — a half-configured move leg is a worse
        // failure than the load-throw, so it is never a silent no-op.
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'stages' => ['opened' => 50], 'coord_card_terminal_stage_id' => 99],
        ]]));

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('but no coord_card_stage_id');
        WritebackConfig::load($this->dir);
    }

    public function test_explicit_move_coord_cards_false_stays_off_even_when_fully_configured(): void
    {
        // DL-204: an EXPLICIT opt-out wins over the fleet default even with a complete config —
        // the presence-of-terminal heuristic only fires when the key is ABSENT.
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'stages' => ['opened' => 50], 'move_coord_cards' => false,
                'coord_card_stage_id' => 21, 'coord_card_terminal_stage_id' => 99],
        ]]));

        $this->assertFalse(WritebackConfig::load($this->dir)->mappingFor('o/r')->moveCoordCards);
    }

    public function test_move_coord_cards_is_independent_of_create_coord_cards(): void
    {
        // The two legs are separately opt-in (the ruling's "OPT-IN FIRST": the move leg
        // does NOT ride create_coord_cards). Move-on/create-off is a coherent state.
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'stages' => ['opened' => 50], 'move_coord_cards' => true,
                'coord_card_stage_id' => 21, 'coord_card_terminal_stage_id' => 99],
        ]]));

        $mapping = WritebackConfig::load($this->dir)->mappingFor('o/r');
        $this->assertTrue($mapping->moveCoordCards);
        $this->assertFalse($mapping->createCoordCards);
    }

    // ---- #75 / card-4485: card_id_tag_template ----

    public function test_loads_card_id_tag_template(): void
    {
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'stages' => ['opened' => 50], 'card_id_tag_template' => 'id:DEV-pr-{n}'],
        ]]));

        $this->assertSame('id:DEV-pr-{n}', WritebackConfig::load($this->dir)->mappingFor('o/r')->cardIdTagTemplate);
    }

    public function test_absent_card_id_tag_template_is_null(): void
    {
        $this->write(json_encode(['mappings' => ['o/r' => ['board_id' => 8, 'stages' => ['opened' => 50]]]]));
        $this->assertNull(WritebackConfig::load($this->dir)->mappingFor('o/r')->cardIdTagTemplate);
    }

    public function test_empty_card_id_tag_template_throws(): void
    {
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'stages' => ['opened' => 50], 'card_id_tag_template' => ''],
        ]]));
        $this->expectException(ConfigException::class);
        WritebackConfig::load($this->dir);
    }

    public function test_non_string_card_id_tag_template_throws(): void
    {
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'stages' => ['opened' => 50], 'card_id_tag_template' => 123],
        ]]));
        $this->expectException(ConfigException::class);
        WritebackConfig::load($this->dir);
    }

    // loadDefault() — the shared config('bridge.config_dir') → load() resolve every
    // event-time caller repeats (handlers + writeback classifiers). Each caller keeps
    // its own fail branch; loadDefault only folds the resolve.

    public function test_load_default_returns_null_when_config_dir_unset(): void
    {
        config(['bridge.config_dir' => '']);
        $this->assertNull(WritebackConfig::loadDefault());
    }

    public function test_load_default_returns_null_when_writeback_json_absent(): void
    {
        // config_dir set, but no writeback.json in it ⇒ writeback disabled.
        config(['bridge.config_dir' => $this->dir]);
        $this->assertNull(WritebackConfig::loadDefault());
    }

    public function test_load_default_loads_from_configured_dir(): void
    {
        $this->write(json_encode([
            'identity_id' => 4242,
            'mappings' => [
                'owner/repo' => ['board_id' => 8, 'stages' => ['merged' => 52, 'merged_to_main' => 53]],
            ],
        ]));
        config(['bridge.config_dir' => $this->dir]);

        $cfg = WritebackConfig::loadDefault();
        $this->assertNotNull($cfg);
        $this->assertSame(4242, $cfg->identityId);
        $this->assertSame(8, $cfg->mappingFor('owner/repo')->boardId);
    }

    // ---- card#6371 / DL-286: coord_card_lane_stage_ids ----

    public function test_absent_coord_card_lane_stage_ids_is_null_byte_identical(): void
    {
        // The back-compat property: an install that configured no lane model keeps the
        // fixed create stage, so DL-198 behaviour is untouched.
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'stages' => ['opened' => 50], 'create_coord_cards' => true, 'coord_card_stage_id' => 21],
        ]]));

        $this->assertNull(WritebackConfig::load($this->dir)->mappingFor('o/r')->coordCardLaneStageIds);
    }

    public function test_coord_card_lane_stage_ids_parse_to_ints_per_lane(): void
    {
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'stages' => ['opened' => 50], 'create_coord_cards' => true, 'coord_card_stage_id' => 21,
                'coord_card_lane_stage_ids' => ['now' => 40, 'next' => '41', 'later' => 42, 'maybe' => 43]],
        ]]));

        $mapping = WritebackConfig::load($this->dir)->mappingFor('o/r');
        $this->assertSame(['now' => 40, 'next' => 41, 'later' => 42, 'maybe' => 43], $mapping->coordCardLaneStageIds);
    }

    public function test_a_partial_lane_map_is_allowed_when_it_carries_later(): void
    {
        // An install whose lane model has three columns is legitimate — the unmapped
        // lane is the handler's warn-and-fall-back arm, not a config error.
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'stages' => ['opened' => 50], 'create_coord_cards' => true, 'coord_card_stage_id' => 21,
                'coord_card_lane_stage_ids' => ['now' => 40, 'later' => 42]],
        ]]));

        $this->assertSame(['now' => 40, 'later' => 42], WritebackConfig::load($this->dir)->mappingFor('o/r')->coordCardLaneStageIds);
    }

    public function test_lane_map_without_later_throws(): void
    {
        // `later` is the target of BOTH fallbacks (undeclared, and declared-but-unmapped) —
        // without it neither has a stage, so the create would have nowhere to land.
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'stages' => ['opened' => 50], 'create_coord_cards' => true, 'coord_card_stage_id' => 21,
                'coord_card_lane_stage_ids' => ['now' => 40, 'next' => 41]],
        ]]));

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage("must carry the 'later' lane");
        WritebackConfig::load($this->dir);
    }

    public function test_unknown_lane_key_throws(): void
    {
        // A typo'd lane would silently never match any `stage:*` label while looking
        // configured — the fail-quiet class every other stage key is strict about.
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'stages' => ['opened' => 50], 'create_coord_cards' => true, 'coord_card_stage_id' => 21,
                'coord_card_lane_stage_ids' => ['later' => 42, 'sonn' => 40]],
        ]]));

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage("unknown lane 'sonn'");
        WritebackConfig::load($this->dir);
    }

    public function test_non_numeric_lane_stage_id_throws(): void
    {
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'stages' => ['opened' => 50], 'create_coord_cards' => true, 'coord_card_stage_id' => 21,
                'coord_card_lane_stage_ids' => ['later' => 'Later']],
        ]]));

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage("lane 'later' must be a numeric workflow_stage_id");
        WritebackConfig::load($this->dir);
    }

    public function test_list_shaped_lane_map_throws(): void
    {
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'stages' => ['opened' => 50], 'create_coord_cards' => true, 'coord_card_stage_id' => 21,
                'coord_card_lane_stage_ids' => [40, 41, 42]],
        ]]));

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('must be a non-empty object keyed by lane');
        WritebackConfig::load($this->dir);
    }

    public function test_empty_lane_map_throws(): void
    {
        // Same fail-quiet as an empty started_from_stages: it disables the feature while
        // looking configured. Omit the key instead. `{}` decodes to `[]` — a LIST — so the
        // shape guard is what catches it; there is deliberately no second empty-map guard
        // (it would be unreachable).
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'stages' => ['opened' => 50], 'create_coord_cards' => true, 'coord_card_stage_id' => 21,
                'coord_card_lane_stage_ids' => new \stdClass],
        ]]));

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('must be a non-empty object keyed by lane');
        WritebackConfig::load($this->dir);
    }

    public function test_a_lane_equal_to_the_terminal_stage_throws(): void
    {
        // Same disjointness class as coord_card_stage_id-vs-terminal: an issue declaring
        // this lane would be CREATED into the concluded stage, and the move leg's close
        // would then read it as already-terminal and no-op.
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'stages' => ['opened' => 50], 'create_coord_cards' => true, 'coord_card_stage_id' => 21,
                'move_coord_cards' => true, 'coord_card_terminal_stage_id' => 99,
                'coord_card_lane_stage_ids' => ['now' => 40, 'later' => 42, 'maybe' => 99]],
        ]]));

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage("lane 'maybe' must differ from coord_card_terminal_stage_id");
        WritebackConfig::load($this->dir);
    }

    public function test_two_lanes_sharing_one_stage_id_throws(): void
    {
        // A lane map whose lanes collide cannot express the priority the label declares:
        // the create resolves to a stage that no longer says which lane it meant, and the
        // consumer's board→issue writeback relabels the issue with whichever lane owns it.
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'stages' => ['opened' => 50], 'create_coord_cards' => true, 'coord_card_stage_id' => 21,
                'coord_card_lane_stage_ids' => ['now' => 40, 'next' => 40, 'later' => 42]],
        ]]));

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage("maps lanes 'now' and 'next' to the same stage id 40");
        WritebackConfig::load($this->dir);
    }

    public function test_a_lane_may_equal_the_fixed_create_stage(): void
    {
        // The negative control for both guards above: overlapping the FIXED create stage
        // is legitimate (an operator whose Now column IS coord_card_stage_id), and distinct
        // lane ids with a terminal that matches none of them load clean.
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'stages' => ['opened' => 50], 'create_coord_cards' => true, 'coord_card_stage_id' => 21,
                'move_coord_cards' => true, 'coord_card_terminal_stage_id' => 99,
                'coord_card_lane_stage_ids' => ['now' => 21, 'next' => 41, 'later' => 42]],
        ]]));

        $this->assertSame(['now' => 21, 'next' => 41, 'later' => 42], WritebackConfig::load($this->dir)->mappingFor('o/r')->coordCardLaneStageIds);
    }

    public function test_lane_map_loads_with_the_move_family_alone_and_no_create_leg(): void
    {
        // DL-294 / card#7126 — THE WIDENING. A move-on/create-off mapping is a documented
        // shape (docs/writeback.md: coord_card_stage_id "is required here too, even with
        // create_coord_cards off"), its cards are created by the consumer's reconcile, and
        // since card#6393 the revive and relane legs READ these lane ids. So the lane model
        // is expressible on it, and the load must accept it. Before DL-294 this config threw.
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'stages' => ['opened' => 50],
                'move_coord_cards' => true, 'coord_card_stage_id' => 21, 'coord_card_terminal_stage_id' => 99,
                'coord_card_lane_stage_ids' => ['now' => 40, 'later' => 42]],
        ]]));

        $mapping = WritebackConfig::load($this->dir)->mappingFor('o/r');
        $this->assertSame(['now' => 40, 'later' => 42], $mapping->coordCardLaneStageIds);
        $this->assertFalse($mapping->createCoordCards);
        $this->assertTrue($mapping->moveCoordCards);
    }

    public function test_lane_map_loads_under_the_dl_204_move_default_with_no_explicit_flag(): void
    {
        // The same widening reached the way DL-204 says an install reaches the move leg —
        // the terminal present, no `move_coord_cards` key. The guard reads the RESOLVED
        // flag, not the raw key, so the default-on path is inside the widening rather than
        // beside it.
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'stages' => ['opened' => 50],
                'coord_card_stage_id' => 21, 'coord_card_terminal_stage_id' => 99,
                'coord_card_lane_stage_ids' => ['later' => 42]],
        ]]));

        $this->assertSame(['later' => 42], WritebackConfig::load($this->dir)->mappingFor('o/r')->coordCardLaneStageIds);
    }

    public function test_lane_map_with_neither_coord_card_family_throws(): void
    {
        // ⛔ THE NEGATIVE PIN for DL-294's widening: a loosening whose only test is the
        // newly-allowed case cannot tell "accepts the right thing" from "accepts
        // everything". A mapping that neither creates NOR moves coord cards runs no leg
        // that reads these ids, so the lane model has nothing to place — still fail-closed.
        // `move_coord_cards` is absent AND `coord_card_terminal_stage_id` is absent, so the
        // DL-204 default resolves the move leg OFF too.
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'stages' => ['opened' => 50], 'coord_card_stage_id' => 21,
                'coord_card_lane_stage_ids' => ['later' => 42]],
        ]]));

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('sets coord_card_lane_stage_ids but neither create_coord_cards nor move_coord_cards');
        WritebackConfig::load($this->dir);
    }

    public function test_lane_map_with_an_explicit_move_opt_out_throws(): void
    {
        // The other spelling of "neither family": the terminal is present (so the DL-204
        // default would turn the move leg on) but the operator explicitly opted OUT. The
        // guard must read the resolved flag, not the terminal's presence — reading the
        // terminal would accept a mapping whose every lane-reading leg is off.
        $this->write(json_encode(['mappings' => [
            'o/r' => ['board_id' => 8, 'stages' => ['opened' => 50], 'coord_card_stage_id' => 21,
                'move_coord_cards' => false, 'coord_card_terminal_stage_id' => 99,
                'coord_card_lane_stage_ids' => ['later' => 42]],
        ]]));

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('sets coord_card_lane_stage_ids but neither create_coord_cards nor move_coord_cards');
        WritebackConfig::load($this->dir);
    }

    public function test_load_default_propagates_config_exception_on_malformed_file(): void
    {
        // The fail branch every caller relies on: a malformed writeback.json still
        // throws through loadDefault (fail-closed), it is not swallowed to null.
        $this->write('not json');
        config(['bridge.config_dir' => $this->dir]);
        $this->expectException(ConfigException::class);
        WritebackConfig::loadDefault();
    }

    // ---- DL-293: a mapping key names a repo, and GitHub repo names are case-insensitive.

    public function test_mapping_key_matches_a_differently_cased_payload_repo(): void
    {
        // The armed shape: the operator wrote the key the way every GitHub URL accepts and
        // every `gh` command echoes, and the payload arrives in the owner's registered
        // display casing. A raw key lookup made that a permanent silent no-match.
        $this->write(json_encode(['mappings' => [
            'owner/Repo' => ['board_id' => 8, 'stages' => ['opened' => 50]],
        ]]));

        $cfg = WritebackConfig::load($this->dir);
        $this->assertSame(8, $cfg->mappingFor('Owner/repo')?->boardId);
        $this->assertSame(8, $cfg->mappingFor('OWNER/REPO')?->boardId);
        $this->assertSame(8, $cfg->mappingFor('owner/Repo')?->boardId);
    }

    public function test_a_mapping_key_spelled_exactly_as_the_payload_still_resolves(): void
    {
        // THE REGRESSION LEG, and the one that would have caught the outage: every live
        // mapping key on the reference fleet is non-canonical (`PupFuzz/...`) and so is the
        // `repository.full_name` it is matched against. Canonicalizing only ONE side would
        // have redded every production mapping at once, silently, through each call site's
        // "repo not tracked" return.
        $this->write(json_encode(['mappings' => [
            'PupFuzz/agent-webhook-bridge' => ['board_id' => 8, 'stages' => ['opened' => 50]],
            'PupFuzz/kanban-board' => ['board_id' => 5, 'stages' => ['opened' => 30]],
        ]]));

        $cfg = WritebackConfig::load($this->dir);
        $this->assertSame(8, $cfg->mappingFor('PupFuzz/agent-webhook-bridge')?->boardId);
        $this->assertSame(5, $cfg->mappingFor('PupFuzz/kanban-board')?->boardId);
    }

    public function test_an_unmapped_repo_still_resolves_to_null(): void
    {
        // The negative the widening must not swallow: matching case-insensitively is not
        // matching everything.
        $this->write(json_encode(['mappings' => [
            'owner/Repo' => ['board_id' => 8, 'stages' => ['opened' => 50]],
        ]]));

        $cfg = WritebackConfig::load($this->dir);
        $this->assertNull($cfg->mappingFor('owner/other-repo'));
        $this->assertNull($cfg->mappingFor('other-owner/repo'));
        $this->assertNull($cfg->mappingFor('owner/repo2'));
        $this->assertNull($cfg->mappingFor(''));
    }

    public function test_two_keys_naming_the_same_repo_fail_closed_naming_both_spellings(): void
    {
        $this->write(json_encode(['mappings' => [
            'Owner/Repo' => ['board_id' => 8, 'stages' => ['opened' => 50]],
            'owner/repo' => ['board_id' => 9, 'stages' => ['opened' => 60]],
        ]]));

        try {
            WritebackConfig::load($this->dir);
            $this->fail('expected a ConfigException for two mapping keys naming one repo');
        } catch (ConfigException $e) {
            // Both ORIGINAL spellings, so the operator can find the two lines in their file.
            $this->assertStringContainsString('"Owner/Repo"', $e->getMessage());
            $this->assertStringContainsString('"owner/repo"', $e->getMessage());
            $this->assertStringContainsString('same repo (owner/repo)', $e->getMessage());
        }
    }

    public function test_a_blank_mapping_key_fails_closed(): void
    {
        // A key that canonicalizes to nothing can match no payload repo: it is a dead
        // mapping, and a dead mapping is exactly the silent misconfiguration this fails
        // closed on.
        $this->write(json_encode(['mappings' => [
            '   ' => ['board_id' => 8, 'stages' => ['opened' => 50]],
        ]]));

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('mapping key is blank');
        WritebackConfig::load($this->dir);
    }

    public function test_configured_repo_for_returns_the_spelling_the_operator_wrote(): void
    {
        // The raw key is NOT interchangeable with the canonical one: it is what resolves a
        // per-repo token from the store's case-sensitive [git-credential-map], so anything
        // that matched a repo case-insensitively has to come back for it.
        $this->write(json_encode(['mappings' => [
            'PupFuzz/agent-webhook-bridge' => ['board_id' => 8, 'stages' => ['opened' => 50]],
        ]]));

        $cfg = WritebackConfig::load($this->dir);
        $this->assertSame('PupFuzz/agent-webhook-bridge', $cfg->configuredRepoFor('pupfuzz/agent-webhook-bridge'));
        $this->assertSame('PupFuzz/agent-webhook-bridge', $cfg->configuredRepoFor('PupFuzz/agent-webhook-bridge'));
        $this->assertNull($cfg->configuredRepoFor('pupfuzz/not-mapped'));
        $this->assertSame(['PupFuzz/agent-webhook-bridge'], array_keys($cfg->mappings));
    }
}
