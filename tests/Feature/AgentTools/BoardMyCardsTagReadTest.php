<?php

namespace Tests\Feature\AgentTools;

use App\Bridge\Tools\BoardMyCardsTool;
use App\Bridge\Tools\ToolsCallStdio;
use App\Bridge\Writeback\KanbanClient;
use App\Bridge\Writeback\KanbanFieldLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use Tests\Support\CallingSeatSeal;
use Tests\Support\FakeToolsCallStdio;
use Tests\Support\TaggedBoardFake;
use Tests\TestCase;

/**
 * `board_my_cards`' `tag` read (card#9260, DL-383), on BOTH front doors.
 *
 * ⛔ THE INCIDENT. A seat read its own lane, found no `lane:A` cards, and wrote that its sprint was
 * empty — while three `lane:A` cards sat at `swimlane_id: null`. The lane search never contained
 * them, and no key in the response varied between "none exist" and "none are in your lane". Every
 * test here that asserts a laneless card is VISIBLE also asserts, where it matters, that the lane
 * read still cannot see it: the tag read is an addition, not a change to the lane read.
 */
class BoardMyCardsTagReadTest extends TestCase
{
    use RefreshDatabase;
    use TaggedBoardFake;

    private string $dir;

    private string $token = 'tools-bearer-tagread';   // gitleaks:allow — test fixture

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/tools-tagread-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/kanban');
        File::put($this->dir.'/kanban/writeback-token', 'wb-token');   // gitleaks:allow — test fixture
        chmod($this->dir.'/kanban/writeback-token', 0o600);
        File::put($this->dir.'/me-tools-token', $this->token);
        chmod($this->dir.'/me-tools-token', 0o600);

        config([
            'bridge.config_dir' => $this->dir,
            'bridge.secret_dir' => $this->dir,
            'bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function writeAgent(string $transport): void
    {
        $auth = $transport === 'http' ? "  auth:\n    token_path: {$this->dir}/me-tools-token\n" : '';

        File::put($this->dir.'/me.yml', "identity:\n  kanban_user_id: ".crc32('me')."\nsubscriptions: []\n"
            ."board_tools:\n  enabled: true\n  transport: {$transport}\n".$auth
            ."  board_id: 10\n  swimlane_id: 4\n  create_stage_id: 50\n");
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, status: int, body: array<string, mixed>}
     */
    private function http(array $args = []): array
    {
        CallingSeatSeal::forANewServingProcess();
        $this->writeAgent('http');

        $call = ['tool' => 'board_my_cards'] + ($args === [] ? [] : ['args' => $args]);
        $response = $this->call('POST', '/agent-tools/call', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
        ], (string) json_encode($call));

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true);

        return ['ok' => $response->status() === 200, 'status' => $response->status(), 'body' => $body];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, status: int, body: array<string, mixed>}
     */
    private function ssh(array $args = []): array
    {
        CallingSeatSeal::forANewServingProcess();
        $this->writeAgent('ssh');

        $call = ['tool' => 'board_my_cards'] + ($args === [] ? [] : ['args' => $args]);
        $fake = new FakeToolsCallStdio((string) json_encode($call));
        $this->app->instance(ToolsCallStdio::class, $fake);
        $exit = $this->artisan('bridge:tools-call', ['--agent' => 'me'])->run();

        /** @var array<string, mixed> $body */
        $body = json_decode($fake->capturedOut(), true);

        return ['ok' => $exit === 0, 'status' => $exit, 'body' => $body];
    }

    /** @return array<string, array{string}> */
    public static function doors(): array
    {
        return ['http door' => ['http'], 'ssh door' => ['ssh']];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, status: int, body: array<string, mixed>}
     */
    private function through(string $door, array $args = []): array
    {
        return $door === 'http' ? $this->http($args) : $this->ssh($args);
    }

    /** @return list<int> every card id anywhere in a response body */
    private static function idsIn(array $result): array
    {
        $ids = [];
        array_walk_recursive($result, function ($value, $key) use (&$ids): void {
            if ($key === 'id' && is_int($value)) {
                $ids[] = $value;
            }
        });

        return $ids;
    }

    /**
     * The `tag_cards` block of an HTTP-door call, failing with the response body when the call was
     * refused — so a red names the refusal rather than an undefined index.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function tagCards(array $args): array
    {
        $res = $this->http($args);
        $this->assertTrue($res['ok'], (string) json_encode($res['body']));
        $this->assertArrayHasKey('tag_cards', $res['body']['result']);

        return $res['body']['result']['tag_cards'];
    }

    // ─── the incident, and the counts ────────────────────────────────────────

    #[DataProvider('doors')]
    public function test_a_laneless_sprint_card_the_lane_read_cannot_see_is_named_by_the_tag_read(string $door): void
    {
        $this->fakeTaggedBoard(
            [self::taggedRow(1, 50, 4, [])],
            [self::taggedRow(11, 50, null), self::taggedRow(12, 51, null), self::taggedRow(13, 50, null)],
        );

        $default = $this->through($door);
        $this->assertTrue($default['ok']);
        $this->assertSame([], array_intersect([11, 12, 13], self::idsIn($default['body']['result'])), 'the lane read never contained the laneless cards — the premise of the incident');

        $tagged = $this->through($door, ['tag' => 'lane:A']);
        $this->assertTrue($tagged['ok'], json_encode($tagged['body']));
        $block = $tagged['body']['result']['tag_cards'];
        $this->assertSame('lane:A', $block['tag']);
        $this->assertSame([11, 12, 13], array_column($block['cards'], 'id'));
        foreach ($block['cards'] as $card) {
            $this->assertArrayHasKey('swimlane_id', $card);
            $this->assertNull($card['swimlane_id'], 'a laneless card is named laneless BY NAME');
        }
        $this->assertSame(3, $block['no_swimlane']);
        $this->assertNull($block['no_swimlane_unmeasured']);
        $this->assertSame(0, $block['other_swimlanes']);
        $this->assertNull($block['other_swimlanes_unmeasured']);
        $this->assertSame(['total' => 3, 'returned' => 3, 'limit' => BoardMyCardsTool::DEFAULT_MAX_CARDS, 'truncated' => false, 'stage_filter' => null], $block['cards_window']);
    }

    public function test_other_swimlanes_counts_the_tagged_cards_in_another_lane_through_kanbans_own_predicate(): void
    {
        $this->fakeTaggedBoard([], [
            self::taggedRow(21, 50, 9), self::taggedRow(22, 51, 9), self::taggedRow(23, 50, 4), self::taggedRow(24, 50, null),
        ]);

        $block = $this->tagCards(['tag' => 'lane:A']);

        $this->assertSame(2, $block['other_swimlanes']);
        $this->assertSame(1, $block['no_swimlane']);
        $this->assertSame([9, 9, 4, null], array_column($block['cards'], 'swimlane_id'));
        $this->assertContains('board_id=10 swimlane_id=9 tags:"lane:A" workflow_stage_id=50,51 [count]', self::sentSearches(), 'the complement of the configured lane, from the board\'s own lane list, and nothing else');
        $this->assertContains('board_id=10 swimlane_id=none tags:"lane:A" workflow_stage_id=50,51 [count]', self::sentSearches());
        $this->assertContains('board_id=10 '.KanbanClient::FREE_TEXT_PROBE_TERM.' [count]', self::sentSearches(), 'the laneless count is reported only after the board was shown to disclose free text');
    }

    // ─── a count the tool cannot stand behind is NAMED, never a number ───────

    /** @return array<string, array{int}> */
    public static function preNoneParserAnswers(): array
    {
        return [
            'the old parser answers 0 and the board has laneless cards' => [0],
            'the old parser answers a number the board does not hold' => [7],
        ];
    }

    /**
     * ⛔ THE OLD-SERVER ARM. A kanban before v0.45.0 does not refuse `swimlane_id=none`: the token
     * is not a digit list, so it is searched as TEXT, and the count is of cards whose text matches.
     */
    #[DataProvider('preNoneParserAnswers')]
    public function test_no_swimlane_is_unmeasured_by_name_on_a_kanban_that_searches_none_as_text(int $oldParserTotal): void
    {
        $this->fakeTaggedBoard([], [self::taggedRow(31, 50, null), self::taggedRow(32, 50, 9)], ['parser' => 'pre-none', 'pre_none_total' => $oldParserTotal]);

        $block = $this->tagCards(['tag' => 'lane:A']);

        $this->assertNull($block['no_swimlane']);
        $this->assertSame(BoardMyCardsTool::UNMEASURED_FILTER_NOT_HONOURED, $block['no_swimlane_unmeasured']);
        $this->assertSame(1, $block['other_swimlanes'], 'a digit list is honoured by every parser, so its count still stands');
        $this->assertNull($block['cards'][0]['swimlane_id'], 'the card itself is still named laneless — that is read off the row, not the count');
    }

    /**
     * ⛔ THE "NEVER A MEASURED 0" ARM, on a board with NO laneless card: the old parser's 0 happens to
     * be the true answer, and it is still not reported, because the server did not apply the filter.
     */
    public function test_a_zero_from_a_kanban_that_did_not_apply_the_filter_is_not_reported_even_when_it_is_true(): void
    {
        $this->fakeTaggedBoard([], [self::taggedRow(33, 50, 4)], ['parser' => 'pre-none', 'pre_none_total' => 0]);

        $block = $this->tagCards(['tag' => 'lane:A']);

        $this->assertNull($block['no_swimlane']);
        $this->assertSame(BoardMyCardsTool::UNMEASURED_FILTER_NOT_HONOURED, $block['no_swimlane_unmeasured']);
    }

    /** @return array<string, array{list<array<string, mixed>>, int}> */
    public static function preDisclosureParserAnswers(): array
    {
        return [
            'no laneless card, and the text search answers the same 0' => [[self::taggedRow(34, 50, 4)], 0],
            'two laneless cards, and the text search happens to answer 2' => [[self::taggedRow(35, 50, null), self::taggedRow(36, 51, null)], 2],
        ];
    }

    /**
     * ⛔ THE "NEVER A MEASURED 0" ARM ON A KANBAN TOO OLD TO SAY SO (v0.36.0–v0.42.x). It has the
     * `swimlane_id=` term the lane read needs, but neither `none` nor DL-246's free-text
     * disclosure, so its count response looks exactly like an honoured one — and here it even
     * agrees with the rows. The disclosure probe is what tells it apart, and no laneless count is
     * asked of it at all.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    #[DataProvider('preDisclosureParserAnswers')]
    public function test_no_swimlane_is_unconfirmed_by_name_on_a_kanban_that_cannot_disclose_free_text(array $rows, int $oldParserTotal): void
    {
        $this->fakeTaggedBoard([], $rows, ['parser' => 'pre-disclosure', 'pre_none_total' => $oldParserTotal]);

        $block = $this->tagCards(['tag' => 'lane:A']);

        $this->assertNull($block['no_swimlane']);
        $this->assertSame(BoardMyCardsTool::UNMEASURED_FILTER_UNCONFIRMED, $block['no_swimlane_unmeasured']);
        $this->assertSame(0, $block['other_swimlanes'], 'a digit list is a filter on every kanban whose lane read works, so it takes no probe');
        $this->assertContains('board_id=10 '.KanbanClient::FREE_TEXT_PROBE_TERM.' [count]', self::sentSearches());
        $this->assertSame([], array_values(array_filter(self::sentSearches(), fn (string $q): bool => str_contains($q, 'swimlane_id=none'))), 'no laneless count is asked of a server that cannot say how it read the term');
    }

    /**
     * A server that discloses free text and honours `none` can still answer a count the rows this
     * call read do not agree with — a card moved between the two requests, say. The rows are the
     * other reading of the same population, and a count that disagrees with them is not reported.
     */
    public function test_a_server_count_that_disagrees_with_the_rows_is_unmeasured_by_name(): void
    {
        $this->fakeTaggedBoard([], [self::taggedRow(41, 50, null), self::taggedRow(42, 50, null)], ['none_total' => 0]);

        $block = $this->tagCards(['tag' => 'lane:A']);

        $this->assertNull($block['no_swimlane']);
        $this->assertSame(BoardMyCardsTool::UNMEASURED_DISAGREES_WITH_ROWS, $block['no_swimlane_unmeasured']);
    }

    public function test_a_count_response_with_no_total_is_unmeasured_by_name(): void
    {
        $this->fakeTaggedBoard([], [self::taggedRow(43, 50, null)], ['count_meta_total' => false]);

        $block = $this->tagCards(['tag' => 'lane:A']);

        $this->assertNull($block['no_swimlane']);
        $this->assertSame(BoardMyCardsTool::UNMEASURED_TOTAL_ABSENT, $block['no_swimlane_unmeasured']);
        $this->assertNull($block['other_swimlanes']);
        $this->assertSame(BoardMyCardsTool::UNMEASURED_TOTAL_ABSENT, $block['other_swimlanes_unmeasured']);
    }

    public function test_other_swimlanes_is_unmeasured_when_the_board_read_carried_no_lane_list_and_no_count_is_sent(): void
    {
        $this->fakeTaggedBoard([], [self::taggedRow(44, 50, 9), self::taggedRow(45, 50, null)], ['swimlanes' => null]);

        $block = $this->tagCards(['tag' => 'lane:A']);

        $this->assertNull($block['other_swimlanes']);
        $this->assertSame(BoardMyCardsTool::UNMEASURED_SWIMLANES_UNREADABLE, $block['other_swimlanes_unmeasured']);
        $this->assertSame(1, $block['no_swimlane'], 'the laneless count needs no lane list');
        $this->assertSame([], array_values(array_filter(self::sentSearches(), fn (string $q): bool => (bool) preg_match('/swimlane_id=\d/', $q) && str_contains($q, '[count]'))));
    }

    /**
     * An EMPTY complement is a board with no lane but the seat's, so nothing can be in another
     * lane: zero without a request. It is still checked against the rows, which is what keeps
     * "zero by construction" from being a claim nothing tests.
     */
    public function test_a_board_with_no_other_lane_counts_zero_other_swimlanes_without_a_search_and_still_checks_the_rows(): void
    {
        $this->fakeTaggedBoard([], [self::taggedRow(46, 50, 4)], ['swimlanes' => [4]]);
        $block = $this->tagCards(['tag' => 'lane:A']);
        $this->assertSame(0, $block['other_swimlanes']);
        $this->assertSame([], array_values(array_filter(self::sentSearches(), fn (string $q): bool => (bool) preg_match('/swimlane_id=\d/', $q) && str_contains($q, '[count]'))));
    }

    public function test_zero_by_construction_is_unmeasured_when_a_row_says_it_is_in_a_lane_the_board_did_not_list(): void
    {
        $this->fakeTaggedBoard([], [self::taggedRow(47, 50, 12)], ['swimlanes' => [4]]);
        $block = $this->tagCards(['tag' => 'lane:A']);
        $this->assertNull($block['other_swimlanes']);
        $this->assertSame(BoardMyCardsTool::UNMEASURED_DISAGREES_WITH_ROWS, $block['other_swimlanes_unmeasured']);
    }

    public function test_a_row_that_carries_no_lane_field_gets_no_swimlane_key_rather_than_a_null(): void
    {
        $this->fakeTaggedBoard([], [self::taggedRow(48, 50, false), self::taggedRow(49, 50, null)]);
        $cards = $this->tagCards(['tag' => 'lane:A'])['cards'];
        $this->assertArrayNotHasKey('swimlane_id', $cards[0], 'absent is UNREAD — a null would call the card laneless');
        $this->assertArrayHasKey('swimlane_id', $cards[1]);
        $this->assertNull($cards[1]['swimlane_id']);
    }

    // ─── terminal columns and `stage` ────────────────────────────────────────

    public function test_terminal_columns_are_left_out_by_default_and_the_block_names_them(): void
    {
        $this->fakeTaggedBoard([], [self::taggedRow(51, 52, null), self::taggedRow(52, 50, null)]);

        $block = $this->tagCards(['tag' => 'lane:A']);

        $this->assertFalse($block['include_terminal']);
        $this->assertSame([52], $block['excluded_terminal_stage_ids']);
        $this->assertSame([52], array_column($block['cards'], 'id'));
        $this->assertSame(1, $block['cards_window']['total']);
        $this->assertSame(1, $block['no_swimlane'], 'every number in the block counts the one population');
    }

    public function test_include_terminal_keeps_terminal_columns_and_counts_them(): void
    {
        $this->fakeTaggedBoard([], [self::taggedRow(51, 52, null), self::taggedRow(52, 50, null)]);

        $block = $this->tagCards(['tag' => 'lane:A', 'include_terminal' => true]);

        $this->assertTrue($block['include_terminal']);
        $this->assertSame([], $block['excluded_terminal_stage_ids']);
        $this->assertSame([51, 52], array_column($block['cards'], 'id'));
        $this->assertSame(2, $block['no_swimlane']);
        $this->assertContains('board_id=10 swimlane_id=none tags:"lane:A" [count]', self::sentSearches(), 'no column narrowing when nothing is excluded');
    }

    public function test_stage_narrows_the_tag_read_and_its_counts(): void
    {
        $this->fakeTaggedBoard([], [self::taggedRow(61, 50, null), self::taggedRow(62, 51, null), self::taggedRow(63, 51, 9)]);

        $block = $this->tagCards(['tag' => 'lane:A', 'stage' => 51]);

        $this->assertSame([62, 63], array_column($block['cards'], 'id'));
        $this->assertSame(51, $block['cards_window']['stage_filter']);
        $this->assertSame(1, $block['no_swimlane']);
        $this->assertSame(1, $block['other_swimlanes']);
        $this->assertContains('board_id=10 swimlane_id=none tags:"lane:A" workflow_stage_id=51 [count]', self::sentSearches());
    }

    public function test_a_terminal_stage_without_include_terminal_is_refused_before_any_card_search(): void
    {
        $this->fakeTaggedBoard([], [self::taggedRow(64, 52, null)]);

        $res = $this->http(['tag' => 'lane:A', 'stage' => 52]);

        $this->assertSame(422, $res['status']);
        $this->assertStringContainsString('include_terminal', (string) $res['body']['error']);
        $this->assertSame([], self::sentSearches());
    }

    public function test_the_tag_window_is_capped_like_every_other_list(): void
    {
        $rows = [];
        for ($id = 1000; $id < 1060; $id++) {
            $rows[] = self::taggedRow($id, 50, null);
        }
        $this->fakeTaggedBoard([], $rows);

        $block = $this->tagCards(['tag' => 'lane:A']);

        $this->assertCount(BoardMyCardsTool::DEFAULT_MAX_CARDS, $block['cards']);
        $this->assertSame(['total' => 60, 'returned' => BoardMyCardsTool::DEFAULT_MAX_CARDS, 'limit' => BoardMyCardsTool::DEFAULT_MAX_CARDS, 'truncated' => true, 'stage_filter' => null], $block['cards_window']);
        $this->assertSame(60, $block['no_swimlane'], 'the counts are over the population, not the cut');
    }

    // ─── the default is unchanged ────────────────────────────────────────────

    /**
     * ⛔ THE UNCHANGED-DEFAULT PIN, with its presence witness. A call that does not pass `tag` sends
     * the requests it sent before this read existed — the structure read and the lane search — and
     * its result carries exactly the keys it carried before. The second call is the witness: the
     * same fake, with `tag`, does grow both, so this is not an assertion that could never fail.
     */
    #[DataProvider('doors')]
    public function test_a_call_without_tag_sends_the_same_requests_and_returns_the_same_keys_as_before(string $door): void
    {
        $this->fakeTaggedBoard([self::taggedRow(1, 50, 4, [])], [self::taggedRow(11, 50, null)]);

        $default = $this->through($door)['body']['result'];
        $this->assertSame(['board_id', 'board_observed', 'configured_board_id', 'swimlane_id', 'board_stages', 'cards_by_stage', 'cards_window'], array_keys($default));
        $this->assertSame(['board_id=10 swimlane_id=4'], self::sentSearches());
        $this->assertSame(['id', 'name', 'stage', 'tags', 'assigned_user_id', 'dl_number', 'pr_number', 'updated_at'], array_keys($default['cards_by_stage']['Backlog'][0]), 'the lane card gains no swimlane_id key');

        $tagged = $this->through($door, ['tag' => 'lane:A'])['body']['result'];
        $this->assertArrayHasKey('tag_cards', $tagged);
        $this->assertSame([
            'board_id=10 swimlane_id=4',
            'board_id=10 swimlane_id=4',
            'board_id=10 tags:"lane:A"',
            'board_id=10 swimlane_id=9 tags:"lane:A" workflow_stage_id=50,51 [count]',
            'board_id=10 '.KanbanClient::FREE_TEXT_PROBE_TERM.' [count]',
            'board_id=10 swimlane_id=none tags:"lane:A" workflow_stage_id=50,51 [count]',
        ], self::sentSearches(), 'presence witness: the second call adds the tag read, its two counts and the disclosure probe after the same lane search');
    }

    public function test_the_board_axis_reads_the_tag_rows_too(): void
    {
        $this->fakeTaggedBoard([], [self::taggedRow(71, 50, null)]);

        $this->assertFalse($this->http()['body']['result']['board_observed'], 'an empty lane read observes no board');
        $this->assertTrue($this->http(['tag' => 'lane:A'])['body']['result']['board_observed'], 'the tag rows are rows this call read');
    }

    // ─── arguments refused before any board request ──────────────────────────

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function refusedArguments(): array
    {
        return [
            'tag not a string' => [['tag' => 5], 'ONE tag'],
            'tag a list' => [['tag' => ['lane:A']], 'ONE tag'],
            'tag a glob' => [['tag' => 'lane:*'], '`*`'],
            'tag with a quote' => [['tag' => 'lane:"A'], '`*`'],
            'tag longer than kanban accepts' => [['tag' => str_repeat('a', KanbanFieldLimits::TAG_MAX + 1)], 'at most'],
            'tag an explicit null' => [['tag' => null], 'EMPTY'],
            'tag empty' => [['tag' => ''], 'EMPTY'],
            'tag whitespace' => [['tag' => '   '], 'EMPTY'],
            'tag a non-breaking space' => [['tag' => "\u{00A0}"], 'EMPTY'],
            'include_terminal without tag' => [['include_terminal' => true], 'names no `tag`'],
            'include_terminal not a boolean' => [['tag' => 'lane:A', 'include_terminal' => 'yes'], 'must be a boolean'],
            'include_terminal an explicit null' => [['tag' => 'lane:A', 'include_terminal' => null], 'must be a boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     */
    #[DataProvider('refusedArguments')]
    public function test_an_unusable_tag_argument_is_refused_on_both_doors_before_any_board_request(array $args, string $needle): void
    {
        $this->fakeTaggedBoard([], [self::taggedRow(1, 50, null)]);

        foreach (['http', 'ssh'] as $door) {
            $before = Http::recorded()->count();
            $res = $this->through($door, $args);
            $this->assertFalse($res['ok'], "{$door}: ".json_encode($res['body']));
            $this->assertStringContainsString($needle, (string) $res['body']['error'], $door);
            $this->assertSame($before, Http::recorded()->count(), "{$door}: a refused argument costs no board request");
        }
    }

    public function test_a_tag_with_invisible_padding_is_read_as_the_tag_on_both_doors(): void
    {
        $this->fakeTaggedBoard([], [self::taggedRow(81, 50, null)]);

        foreach (['http', 'ssh'] as $door) {
            $res = $this->through($door, ['tag' => "lane:A\u{00A0}"]);
            $this->assertTrue($res['ok'], $door);
            $this->assertSame('lane:A', $res['body']['result']['tag_cards']['tag'], $door);
        }
    }

    // ─── a permanent board 4xx on each of the tag read's requests ────────────

    /**
     * @return array<string, array{string, int, string, string}>
     */
    public static function refusedSearches(): array
    {
        $cases = [];
        foreach (AgentToolsCallTest::permanentBoardSearchStatuses() as $label => [$status, $cause, $forbidden]) {
            foreach (['tag' => 'carrying your `tag` on your board 10', 'other' => 'in other swimlanes', 'probe' => 'says when it falls back to free text', 'none' => 'the count of cards carrying your `tag` in no swimlane on'] as $read => $what) {
                $cases["{$read} search, {$label}"] = [$read, $status, $cause, $forbidden, $what];
            }
        }

        return $cases;
    }

    #[DataProvider('refusedSearches')]
    public function test_a_permanent_4xx_on_a_tag_read_search_is_a_named_search_refusal_on_both_doors(string $read, int $status, string $cause, string $forbidden, string $what): void
    {
        $this->fakeTaggedBoard([], [self::taggedRow(91, 50, null), self::taggedRow(92, 50, 9)], ['refuse' => [$read => $status]]);

        foreach (['http', 'ssh'] as $door) {
            $res = $this->through($door, ['tag' => 'lane:A']);
            $this->assertFalse($res['ok'], $door);
            $this->assertSame($door === 'http' ? 422 : 1, $res['status'], $door);
            $error = (string) $res['body']['error'];
            $this->assertStringContainsString($cause, $error, $door);
            $this->assertStringContainsString($what, $error, "{$door}: the refusal names the read that was refused");
            $this->assertStringNotContainsString($forbidden, $error, $door);
            $this->assertStringContainsString('NO cards were returned', $error, $door);
            $this->assertStringNotContainsString('the board said something', $error, $door);
        }
    }

    #[DataProviderExternal(AgentToolsCallTest::class, 'permanentBoardScopedStatuses')]
    public function test_a_permanent_4xx_on_the_structure_read_refuses_a_tag_call_with_the_board_scoped_cause_on_both_doors(int $status, string $cause, string $forbidden): void
    {
        $this->fakeTaggedBoard([], [self::taggedRow(93, 50, null)], ['refuse' => ['preload' => $status]]);

        foreach (['http', 'ssh'] as $door) {
            $res = $this->through($door, ['tag' => 'lane:A']);
            $this->assertFalse($res['ok'], $door);
            $error = (string) $res['body']['error'];
            $this->assertStringContainsString($cause, $error, $door);
            $this->assertStringContainsString('the structure of your board 10', $error, $door);
            $this->assertStringNotContainsString($forbidden, $error, $door);
        }
        $this->assertSame([], self::sentSearches(), 'no card search is paid for after the structure read refused');
    }
}
