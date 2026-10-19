<?php

namespace Tests\Feature\AgentTools;

use App\Bridge\Tools\BoardGetCardCommentsTool;
use App\Bridge\Tools\BoardToolsRegistry;
use App\Bridge\Tools\ServedTools;
use App\Bridge\Tools\ToolsCallStdio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CallingSeatSeal;
use Tests\Support\FakeToolsCallStdio;
use Tests\TestCase;

/**
 * `board_get_card_comments` (card#12010), on both front doors.
 *
 * ⛔ THE INCIDENT. A seat that recorded its results as card comments could not read them back: no
 * board tool projected comments, so it derived "owed" work from repo searches and sent its operator to
 * re-run tests whose results were already on the card. Every failure arm here therefore asserts the
 * answer is a REFUSAL, never a successful empty page — an empty page is the answer that misled.
 */
class BoardGetCardCommentsTest extends TestCase
{
    use RefreshDatabase;

    private const BOARD = 10;

    private const CARD = 42;

    private const WRITEBACK_USER = 8;

    private string $dir;

    private string $token = 'tools-bearer-comments';   // gitleaks:allow — test fixture

    /** @var array<string, mixed>|int what the task read answers: its `data`, or a status */
    private array|int $task = [];

    /** @var int|array{status: int} who the writeback token is (`users/current.json`): a user id, or a status it answers with */
    private int|array $whoami = self::WRITEBACK_USER;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/tools-comments-'.uniqid();
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

    private function writeAgent(string $transport, bool $scoped = true): void
    {
        $auth = $transport === 'http' ? "  auth:\n    token_path: {$this->dir}/me-tools-token\n" : '';
        $scope = $scoped ? '  board_id: '.self::BOARD."\n  swimlane_id: 4\n  create_stage_id: 50\n" : '';

        File::put($this->dir.'/me.yml', "subscriptions: []\nboard_tools:\n  enabled: true\n  transport: {$transport}\n".$auth.$scope);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, status: int, body: array<string, mixed>}
     */
    private function http(array $args, bool $scoped = true): array
    {
        CallingSeatSeal::forANewServingProcess();
        $this->writeAgent('http', $scoped);

        $response = $this->call('POST', '/agent-tools/call', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
        ], (string) json_encode(['tool' => 'board_get_card_comments', 'args' => $args]));

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true);

        return ['ok' => $response->status() === 200, 'status' => $response->status(), 'body' => $body];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, status: int, body: array<string, mixed>}
     */
    private function ssh(array $args): array
    {
        CallingSeatSeal::forANewServingProcess();
        $this->writeAgent('ssh');

        $fake = new FakeToolsCallStdio((string) json_encode(['tool' => 'board_get_card_comments', 'args' => $args]));
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
    private function through(string $door, array $args): array
    {
        return $door === 'http' ? $this->http($args) : $this->ssh($args);
    }

    /**
     * One comment row as kanban's `CommentResource` answers it.
     *
     * @return array<string, mixed>
     */
    private static function comment(int $id, string $createdAt, string $content = 'a note', ?int $userId = self::WRITEBACK_USER): array
    {
        return [
            'id' => $id, 'task_id' => self::CARD, 'user_id' => $userId, 'content' => $content,
            'deleted_at' => null, 'created_at' => $createdAt, 'updated_at' => $createdAt,
        ];
    }

    /**
     * The kanban surface this tool reads: the board-scoped search (both archive sides) and the full
     * task read. `$task` is the `data` the task read answers (its comments in kanban's own order,
     * `created_at` ascending), or a status it answers with.
     *
     * @param  array<string, mixed>|int  $task
     */
    private function fakeBoard(string $side = 'live', array|int $task = [], ?int $searchStatus = null): void
    {
        $this->task = $task;
        Http::fake([
            '*/tasks/search.json*' => function (Request $request) use ($side, $searchStatus) {
                if ($searchStatus !== null) {
                    return Http::response('refused', $searchStatus);
                }
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                if (($query['q'] ?? null) !== 'board_id='.self::BOARD.' id='.self::CARD) {
                    return Http::response('unexpected search '.json_encode($query), 500);
                }
                $archived = ($query['archived'] ?? null) === '1';
                $hit = ($side === 'live' && ! $archived) || ($side === 'archived' && $archived);

                return Http::response(['data' => $hit ? [['id' => self::CARD, 'board_id' => self::BOARD, 'name' => 'c', 'tags' => [], 'block_reason' => null]] : []]);
            },
            // Read at request time, so a test can change what the card holds between two calls
            // (`Http::fake()` stacks: a second fake would never be reached).
            '*/users/current.json' => fn () => is_int($this->whoami)
                ? Http::response(['data' => ['id' => $this->whoami, 'name' => 'bridge writeback', 'email' => 'SENSITIVE-EMAIL']])
                : Http::response(['message' => 'refused'], $this->whoami['status']),
            '*/tasks/'.self::CARD.'.json' => fn () => is_int($this->task)
                ? Http::response(['message' => 'refused'], $this->task)
                : Http::response(['data' => $this->task]),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $comments
     * @return array<string, mixed>
     */
    private static function task(array $comments, int $boardId = self::BOARD): array
    {
        return ['id' => self::CARD, 'board_id' => $boardId, 'name' => 'c', 'comments' => $comments];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return list<int>
     */
    private static function ids(array $body): array
    {
        return array_map(static fn (array $c): int => $c['id'], $body['result']['comments']);
    }

    private function assertNoTaskRead(): void
    {
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/tasks/'.self::CARD.'.json'));
    }

    // ─── order, shape, paging, since ─────────────────────────────────────────

    #[DataProvider('doors')]
    public function test_comments_come_back_newest_first_with_author_body_and_writer(string $door): void
    {
        $this->fakeBoard(task: self::task([
            self::comment(301, '2026-10-01T09:00:00+00:00', "FROM: impl-a\n\nall green"),
            self::comment(302, '2026-10-02T09:00:00+00:00', 'a human note', 5),
            // Same second as 302: the higher id is the later row.
            self::comment(303, '2026-10-02T09:00:00+00:00', "FROM: impl-b\n\nred on mariadb"),
        ]));

        $r = $this->through($door, ['card_id' => self::CARD]);

        $this->assertTrue($r['ok'], json_encode($r['body']) ?: '');
        $result = $r['body']['result'];
        $this->assertSame([303, 302, 301], self::ids($r['body']));
        $this->assertSame(
            ['id' => 303, 'author' => ['user_id' => self::WRITEBACK_USER], 'created_at' => '2026-10-02T09:00:00+00:00', 'body' => "FROM: impl-b\n\nred on mariadb", 'writer' => 'impl-b'],
            $result['comments'][0],
        );
        $this->assertSame(['user_id' => 5], $result['comments'][1]['author']);
        $this->assertNull($result['comments'][1]['writer']);
        $this->assertSame('impl-a', $result['comments'][2]['writer']);
        $this->assertSame(self::CARD, $result['card_id']);
        $this->assertSame(self::BOARD, $result['board_id']);
        $this->assertSame('found', $result['card_status']);
        $this->assertSame(3, $result['total']);
        $this->assertNull($result['next_cursor']);
    }

    public function test_paging_walks_every_comment_once_across_pages_and_ends_with_no_cursor(): void
    {
        $comments = [];
        for ($i = 1; $i <= 5; $i++) {
            $comments[] = self::comment(400 + $i, sprintf('2026-10-0%dT00:00:00+00:00', $i));
        }
        $this->fakeBoard(task: self::task($comments));

        $first = $this->http(['card_id' => self::CARD, 'limit' => 2]);
        $this->assertSame([405, 404], self::ids($first['body']));
        $this->assertSame(5, $first['body']['result']['total']);
        $cursor = $first['body']['result']['next_cursor'];
        $this->assertIsString($cursor);

        $second = $this->http(['card_id' => self::CARD, 'limit' => 2, 'cursor' => $cursor]);
        $this->assertSame([403, 402], self::ids($second['body']));

        $third = $this->http(['card_id' => self::CARD, 'limit' => 2, 'cursor' => $second['body']['result']['next_cursor']]);
        $this->assertSame([401], self::ids($third['body']));
        $this->assertNull($third['body']['result']['next_cursor']);
    }

    public function test_a_comment_added_between_pages_does_not_repeat_or_skip_an_older_one(): void
    {
        $comments = [self::comment(1, '2026-10-01T00:00:00+00:00'), self::comment(2, '2026-10-02T00:00:00+00:00'), self::comment(3, '2026-10-03T00:00:00+00:00')];
        $this->fakeBoard(task: self::task($comments));
        $first = $this->http(['card_id' => self::CARD, 'limit' => 2]);
        $this->assertSame([3, 2], self::ids($first['body']));

        $comments[] = self::comment(4, '2026-10-04T00:00:00+00:00');
        $this->task = self::task($comments);
        $second = $this->http(['card_id' => self::CARD, 'limit' => 2, 'cursor' => $first['body']['result']['next_cursor']]);

        $this->assertSame([1], self::ids($second['body']));
        $this->assertSame(4, $second['body']['result']['total']);
    }

    public function test_since_returns_only_comments_created_strictly_after_it_whatever_its_offset(): void
    {
        $this->fakeBoard(task: self::task([
            self::comment(1, '2026-10-01T10:00:00+00:00'),
            self::comment(2, '2026-10-01T12:00:00+00:00'),
            self::comment(3, '2026-10-01T12:00:01+00:00'),
            self::comment(4, '2026-10-02T00:00:00+00:00'),
        ]));

        // 14:00 at +02:00 is 12:00 UTC: comment 2 is AT it, so it is not after it.
        $r = $this->http(['card_id' => self::CARD, 'since' => '2026-10-01T14:00:00+02:00']);

        $this->assertTrue($r['ok'], json_encode($r['body']) ?: '');
        $this->assertSame([4, 3], self::ids($r['body']));
        $this->assertSame(2, $r['body']['result']['total']);
        $this->assertSame('2026-10-01T14:00:00+02:00', $r['body']['result']['since']);
    }

    public function test_since_combines_with_paging(): void
    {
        $this->fakeBoard(task: self::task([
            self::comment(1, '2026-10-01T00:00:00Z'), self::comment(2, '2026-10-02T00:00:00Z'),
            self::comment(3, '2026-10-03T00:00:00Z'), self::comment(4, '2026-10-04T00:00:00Z'),
        ]));

        $first = $this->http(['card_id' => self::CARD, 'since' => '2026-10-01T12:00:00Z', 'limit' => 2]);
        $this->assertSame([4, 3], self::ids($first['body']));
        $this->assertSame(3, $first['body']['result']['total']);
        $second = $this->http(['card_id' => self::CARD, 'since' => '2026-10-01T12:00:00Z', 'limit' => 2, 'cursor' => $first['body']['result']['next_cursor']]);
        $this->assertSame([2], self::ids($second['body']));
        $this->assertNull($second['body']['result']['next_cursor']);
    }

    public function test_the_default_page_is_the_tools_default_limit(): void
    {
        $comments = [];
        for ($i = 1; $i <= BoardGetCardCommentsTool::DEFAULT_LIMIT + 1; $i++) {
            $comments[] = self::comment($i, '2026-10-01T00:00:00+00:00');
        }
        $this->fakeBoard(task: self::task($comments));

        $r = $this->http(['card_id' => self::CARD]);

        $this->assertCount(BoardGetCardCommentsTool::DEFAULT_LIMIT, $r['body']['result']['comments']);
        $this->assertSame(BoardGetCardCommentsTool::DEFAULT_LIMIT + 1, $r['body']['result']['total']);
        $this->assertIsString($r['body']['result']['next_cursor']);
    }

    public function test_a_card_with_no_comments_is_a_successful_empty_page(): void
    {
        $this->fakeBoard(task: self::task([]));

        $r = $this->http(['card_id' => self::CARD]);

        $this->assertTrue($r['ok']);
        $this->assertSame([], $r['body']['result']['comments']);
        $this->assertSame(0, $r['body']['result']['total']);
        $this->assertNull($r['body']['result']['next_cursor']);
    }

    // ─── writer ──────────────────────────────────────────────────────────────

    /** @return array<string, array{string, ?string}> */
    public static function bodies(): array
    {
        return [
            'the bridge\'s own line' => ["FROM: impl-a\n\nresults: 12 passed", 'impl-a'],
            'FROM: mentioned mid-text' => ["results below\nFROM: impl-a\n\nnot an attribution", null],
            'FROM: mid-line' => ['as noted FROM: impl-a earlier', null],
            'a decorated first line' => ["FROM: impl-a (pls review)\n\ntext", null],
            'no blank line after it' => ["FROM: impl-a\ntext", null],
            'lower-case from' => ["from: impl-a\n\ntext", null],
            'a body that is only the line' => ['FROM: impl-a', null],
        ];
    }

    #[DataProvider('bodies')]
    public function test_writer_is_the_seat_only_when_the_first_line_is_the_bridges_attribution(string $body, ?string $writer): void
    {
        $this->fakeBoard(task: self::task([self::comment(1, '2026-10-01T00:00:00+00:00', $body)]));

        $r = $this->http(['card_id' => self::CARD]);

        $this->assertTrue($r['ok'], json_encode($r['body']) ?: '');
        $this->assertSame($writer, $r['body']['result']['comments'][0]['writer']);
        $this->assertSame($body, $r['body']['result']['comments'][0]['body']);
    }

    // ─── writer is authenticated by the kanban author ────────────────────────

    public function test_writer_is_set_when_the_bridges_line_is_authored_by_the_writeback_user(): void
    {
        $this->fakeBoard(task: self::task([self::comment(1, '2026-10-01T00:00:00+00:00', "FROM: impl-a\n\nall green", self::WRITEBACK_USER)]));

        $r = $this->http(['card_id' => self::CARD]);

        $this->assertTrue($r['ok'], json_encode($r['body']) ?: '');
        $this->assertSame('impl-a', $r['body']['result']['comments'][0]['writer']);
        $this->assertSame('verified', $r['body']['result']['writer_check']);
        $this->assertSame(self::WRITEBACK_USER, $r['body']['result']['writeback_user_id']);
        $this->assertStringNotContainsString('SENSITIVE-EMAIL', (string) json_encode($r['body']));
    }

    public function test_the_same_line_authored_by_any_other_user_names_no_writer(): void
    {
        // A person, or any other kanban client, can type the bridge's line; only the writeback
        // user's comments are the bridge's.
        $this->fakeBoard(task: self::task([
            self::comment(1, '2026-10-01T00:00:00+00:00', "FROM: impl-a\n\nforged", 5),
            self::comment(2, '2026-10-02T00:00:00+00:00', "FROM: impl-a\n\nreal", self::WRITEBACK_USER),
            self::comment(3, '2026-10-03T00:00:00+00:00', "FROM: impl-a\n\ndeleted account", null),
        ]));

        $r = $this->http(['card_id' => self::CARD]);

        $this->assertTrue($r['ok'], json_encode($r['body']) ?: '');
        $writers = array_column($r['body']['result']['comments'], 'writer', 'id');
        $this->assertSame([3 => null, 2 => 'impl-a', 1 => null], $writers);
        $this->assertSame('verified', $r['body']['result']['writer_check']);
    }

    /** @return array<string, array{array{status: int}|int}> */
    public static function unresolvableWritebackUsers(): array
    {
        return ['a 5xx' => [['status' => 500]], 'a 401' => [['status' => 401]], 'a 403' => [['status' => 403]]];
    }

    /**
     * @param  array{status: int}  $whoami
     */
    #[DataProvider('unresolvableWritebackUsers')]
    public function test_when_the_writeback_user_cannot_be_resolved_no_comment_names_a_writer(array $whoami): void
    {
        $this->fakeBoard(task: self::task([self::comment(1, '2026-10-01T00:00:00+00:00', "FROM: impl-a\n\nall green", self::WRITEBACK_USER)]));
        $this->whoami = $whoami;

        $r = $this->http(['card_id' => self::CARD]);

        $this->assertTrue($r['ok'], json_encode($r['body']) ?: '');
        $this->assertNull($r['body']['result']['comments'][0]['writer']);
        $this->assertSame('unavailable', $r['body']['result']['writer_check']);
        $this->assertNull($r['body']['result']['writeback_user_id']);
    }

    public function test_a_page_with_no_attribution_line_does_not_ask_who_the_writeback_user_is(): void
    {
        $this->fakeBoard(task: self::task([self::comment(1, '2026-10-01T00:00:00+00:00', 'a plain note')]));

        $r = $this->http(['card_id' => self::CARD]);

        $this->assertSame('no_attribution_line', $r['body']['result']['writer_check']);
        $this->assertNull($r['body']['result']['writeback_user_id']);
        Http::assertNotSent(fn (Request $req): bool => str_contains($req->url(), '/users/current.json'));
    }

    // ─── reach ───────────────────────────────────────────────────────────────

    #[DataProvider('doors')]
    public function test_a_card_not_on_this_board_is_refused_out_of_scope_and_its_comments_are_never_read(string $door): void
    {
        // Another board's card, a trashed card and a missing id all miss the board-scoped search on
        // both archive sides — one answer.
        $this->fakeBoard(side: 'none', task: self::task([self::comment(1, '2026-10-01T00:00:00+00:00', 'FOREIGN-TENANT-CONTENT')], boardId: 77));

        $r = $this->through($door, ['card_id' => self::CARD]);

        $this->assertFalse($r['ok']);
        $this->assertSame('out_of_scope', $r['body']['reason'] ?? null);
        $this->assertStringContainsString('is not on your board '.self::BOARD, $r['body']['error']);
        $this->assertStringNotContainsString('FOREIGN-TENANT-CONTENT', (string) json_encode($r['body']));
        $this->assertNoTaskRead();
    }

    public function test_an_archived_card_on_this_board_is_read_and_says_it_is_archived(): void
    {
        $this->fakeBoard(side: 'archived', task: self::task([self::comment(1, '2026-10-01T00:00:00+00:00', "FROM: impl-a\n\ndone")]));

        $r = $this->http(['card_id' => self::CARD]);

        $this->assertTrue($r['ok'], json_encode($r['body']) ?: '');
        $this->assertSame('archived', $r['body']['result']['card_status']);
        $this->assertSame([1], self::ids($r['body']));
    }

    public function test_a_card_that_moved_to_another_board_after_the_lookup_is_refused_without_its_comments(): void
    {
        $this->fakeBoard(task: self::task([self::comment(1, '2026-10-01T00:00:00+00:00', 'FOREIGN-TENANT-CONTENT')], boardId: 77));

        $r = $this->http(['card_id' => self::CARD]);

        $this->assertFalse($r['ok']);
        $this->assertSame('out_of_scope', $r['body']['reason'] ?? null);
        $this->assertStringNotContainsString('FOREIGN-TENANT-CONTENT', (string) json_encode($r['body']));
    }

    public function test_a_seat_with_no_board_scope_is_not_served_and_nothing_is_read(): void
    {
        Http::fake();

        $r = $this->http(['card_id' => self::CARD], scoped: false);

        $this->assertSame(422, $r['status']);
        $this->assertSame('not_served', $r['body']['reason'] ?? null);
        Http::assertNothingSent();
    }

    // ─── roles ───────────────────────────────────────────────────────────────

    /**
     * The bridge has no seat ROLE: what it serves follows the `board_tools` block alone
     * ({@see ServedTools}). An impl (role-B) seat with a scoped block is served this tool exactly where
     * it is served `board_get_cards`; a scope-less one (what a fresh framework install writes for an
     * impl seat) is served neither.
     */
    public function test_an_impl_seat_is_served_the_tool_wherever_it_is_served_board_get_cards(): void
    {
        $this->writeAgent('http');
        CallingSeatSeal::forANewServingProcess();

        $served = $this->call('POST', '/agent-tools/client', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
        ], (string) json_encode(['op' => 'served_tools']))->json('served');

        $this->assertIsArray($served);
        $this->assertContains('board_get_cards', $served);
        $this->assertContains('board_get_card_comments', $served);
        $this->assertContains('board_get_card_comments', (new BoardToolsRegistry)->known());

        $this->writeAgent('http', scoped: false);
        CallingSeatSeal::forANewServingProcess();
        $scopeless = $this->call('POST', '/agent-tools/client', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
        ], (string) json_encode(['op' => 'served_tools']))->json('served');
        $this->assertIsArray($scopeless);
        $this->assertNotContains('board_get_cards', $scopeless);
        $this->assertNotContains('board_get_card_comments', $scopeless);
    }

    // ─── input validation ────────────────────────────────────────────────────

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function badArguments(): array
    {
        return [
            'no card_id' => [[], '`card_id` is required'],
            'a string card_id' => [['card_id' => '42'], '`card_id` is required'],
            'a zero card_id' => [['card_id' => 0], '`card_id` is required'],
            'limit zero' => [['card_id' => 42, 'limit' => 0], '`limit` must be an integer from 1 to '.BoardGetCardCommentsTool::MAX_LIMIT],
            'limit over the max' => [['card_id' => 42, 'limit' => BoardGetCardCommentsTool::MAX_LIMIT + 1], '`limit` must be an integer from 1 to '.BoardGetCardCommentsTool::MAX_LIMIT],
            'a string limit' => [['card_id' => 42, 'limit' => '5'], '`limit` must be an integer'],
            'a date-only since' => [['card_id' => 42, 'since' => '2026-10-01'], '`since` must be an ISO-8601 timestamp'],
            'a since with no offset' => [['card_id' => 42, 'since' => '2026-10-01T12:00:00'], '`since` must be an ISO-8601 timestamp'],
            'a since on a day that does not exist' => [['card_id' => 42, 'since' => '2026-02-30T12:00:00Z'], '`since` must be an ISO-8601 timestamp'],
            'a numeric since' => [['card_id' => 42, 'since' => 1760000000], '`since` must be an ISO-8601 timestamp'],
            'a garbage cursor' => [['card_id' => 42, 'cursor' => 'not-a-cursor'], '`cursor` is not one this tool issued'],
            'a non-string cursor' => [['card_id' => 42, 'cursor' => 5], '`cursor` is not one this tool issued'],
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     */
    #[DataProvider('badArguments')]
    public function test_a_bad_argument_is_refused_before_any_board_request(array $args, string $needle): void
    {
        Http::fake();

        $r = $this->http($args);

        $this->assertSame(422, $r['status'], json_encode($r['body']) ?: '');
        $this->assertSame('bad_arguments', $r['body']['reason'] ?? null);
        $this->assertStringContainsString($needle, $r['body']['error']);
        Http::assertNothingSent();
    }

    public function test_a_cursor_issued_for_another_card_is_refused(): void
    {
        $this->fakeBoard(task: self::task([self::comment(1, '2026-10-01T00:00:00Z'), self::comment(2, '2026-10-02T00:00:00Z')]));
        $cursor = $this->http(['card_id' => self::CARD, 'limit' => 1])['body']['result']['next_cursor'];
        $this->assertIsString($cursor);
        Http::fake();

        $r = $this->http(['card_id' => 43, 'cursor' => $cursor]);

        $this->assertSame(422, $r['status']);
        $this->assertSame('bad_arguments', $r['body']['reason'] ?? null);
        $this->assertStringContainsString('issued for card '.self::CARD, $r['body']['error']);
        Http::assertNothingSent();
    }

    public function test_an_unknown_argument_is_refused_by_the_door_including_a_text_filter(): void
    {
        Http::fake();

        $r = $this->http(['card_id' => self::CARD, 'prefix' => 'FROM: impl-a']);

        $this->assertSame(422, $r['status']);
        $this->assertSame('bad_arguments', $r['body']['reason'] ?? null);
        $this->assertStringContainsString('unknown argument `prefix`', $r['body']['error']);
        Http::assertNothingSent();
    }

    // ─── a failed read is never an empty page ────────────────────────────────

    #[DataProvider('doors')]
    public function test_a_board_5xx_on_the_comment_read_is_the_retryable_upstream_error_not_an_empty_page(string $door): void
    {
        $this->fakeBoard(task: 500);

        $r = $this->through($door, ['card_id' => self::CARD]);

        $this->assertFalse($r['ok']);
        $this->assertSame($door === 'http' ? 502 : 2, $r['status']);
        $this->assertSame(['ok' => false, 'error' => 'upstream board error'], $r['body']);
    }

    public function test_a_board_403_on_the_comment_read_is_a_named_install_fault(): void
    {
        $this->fakeBoard(task: 403);

        $r = $this->http(['card_id' => self::CARD]);

        $this->assertSame(422, $r['status']);
        $this->assertSame('board_read_failed', $r['body']['reason'] ?? null);
        $this->assertStringContainsString('the comments of card '.self::CARD, $r['body']['error']);
        $this->assertStringContainsString('NO comments were returned', $r['body']['error']);
    }

    public function test_a_card_removed_between_the_lookup_and_the_read_is_refused_card_gone(): void
    {
        $this->fakeBoard(task: 404);

        $r = $this->http(['card_id' => self::CARD]);

        $this->assertSame(422, $r['status']);
        $this->assertSame('card_gone', $r['body']['reason'] ?? null);
    }

    public function test_a_permanent_refusal_of_the_board_lookup_is_a_named_install_fault(): void
    {
        $this->fakeBoard(searchStatus: 401);

        $r = $this->http(['card_id' => self::CARD]);

        $this->assertSame(422, $r['status']);
        $this->assertSame('board_read_failed', $r['body']['reason'] ?? null);
        $this->assertNoTaskRead();
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function unreadableTasks(): array
    {
        $ok = ['id' => 1, 'user_id' => 8, 'content' => 'x', 'created_at' => '2026-10-01T00:00:00+00:00'];

        return [
            'no comments key' => [['id' => self::CARD, 'board_id' => self::BOARD]],
            'comments null' => [['id' => self::CARD, 'board_id' => self::BOARD, 'comments' => null]],
            'comments an object' => [['id' => self::CARD, 'board_id' => self::BOARD, 'comments' => ['a' => $ok]]],
            'a comment with no content' => [['id' => self::CARD, 'board_id' => self::BOARD, 'comments' => [array_diff_key($ok, ['content' => 1])]]],
            'a comment with a string id' => [['id' => self::CARD, 'board_id' => self::BOARD, 'comments' => [['id' => '1'] + $ok]]],
            'a comment with no created_at' => [['id' => self::CARD, 'board_id' => self::BOARD, 'comments' => [array_diff_key($ok, ['created_at' => 1])]]],
            'a comment with an unreadable created_at' => [['id' => self::CARD, 'board_id' => self::BOARD, 'comments' => [['created_at' => 'yesterday'] + $ok]]],
            'a comment with a string user_id' => [['id' => self::CARD, 'board_id' => self::BOARD, 'comments' => [['user_id' => '8'] + $ok]]],
            'a different card' => [['id' => 99, 'board_id' => self::BOARD, 'comments' => [$ok]]],
            'no board id' => [['id' => self::CARD, 'comments' => [$ok]]],
            'an empty body' => [[]],
        ];
    }

    /**
     * @param  array<string, mixed>  $task
     */
    #[DataProvider('unreadableTasks')]
    public function test_a_2xx_the_comments_cannot_be_read_out_of_is_refused_never_answered_empty(array $task): void
    {
        $this->fakeBoard(task: $task);

        $r = $this->http(['card_id' => self::CARD]);

        $this->assertSame(422, $r['status'], json_encode($r['body']) ?: '');
        $this->assertSame('comments_unreadable', $r['body']['reason'] ?? null);
        $this->assertArrayNotHasKey('result', $r['body']);
    }

    public function test_a_comment_with_a_null_user_id_is_a_deleted_account_and_is_read(): void
    {
        // kanban DL-0331: a deleted account's comment is kept with `user_id: null`.
        $this->fakeBoard(task: self::task([self::comment(1, '2026-10-01T00:00:00+00:00', 'old note', null)]));

        $r = $this->http(['card_id' => self::CARD]);

        $this->assertTrue($r['ok']);
        $this->assertSame(['user_id' => null], $r['body']['result']['comments'][0]['author']);
    }

    public function test_one_successful_call_costs_one_lookup_and_one_task_read(): void
    {
        $this->fakeBoard(task: self::task([self::comment(1, '2026-10-01T00:00:00+00:00')]));

        $this->http(['card_id' => self::CARD]);

        Http::assertSentCount(2);
    }
}
