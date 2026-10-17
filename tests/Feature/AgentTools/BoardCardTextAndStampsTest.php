<?php

namespace Tests\Feature\AgentTools;

use App\Bridge\Tools\BoardCreateCardTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CallingSeatSeal;
use Tests\Support\CoordRosterFixture;
use Tests\Support\PlainCardText;
use Tests\TestCase;

/**
 * card#11987, on the wire: `board_create_card` and `board_correct_card` refuse what `kbcard` refuses
 * (the plain-language title rules and the description's filing bar and D1), before any request; and
 * `board_create_card` writes its provenance and idempotency stamps as payload keys on a board that
 * registers them, the legacy tags on one that does not, and correlates under BOTH. The text verdicts
 * themselves are `Tests\Unit\Support\CardTextParityTest`'s; this class holds the tools to using them.
 *
 * ⚠ It deliberately does not register {@see PlainCardText::legacyBoardFields()}: every test here
 * answers the custom-field read itself, because which stamp is written is the subject.
 */
class BoardCardTextAndStampsTest extends TestCase
{
    use RefreshDatabase;

    private const BOARD = 10;

    private string $dir;

    private string $token = 'tools-bearer-cardtext';   // gitleaks:allow — test fixture

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/tools-cardtext-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/kanban');
        File::put($this->dir.'/kanban/writeback-token', 'wb-token');   // gitleaks:allow — test fixture
        chmod($this->dir.'/kanban/writeback-token', 0o600);
        File::put($this->dir.'/me-tools-token', $this->token);
        chmod($this->dir.'/me-tools-token', 0o600);
        File::put($this->dir.'/me.yml', "subscriptions: []\nboard_tools:\n  enabled: true\n  transport: http\n"
            ."  auth:\n    token_path: {$this->dir}/me-tools-token\n"
            .'  board_id: '.self::BOARD."\n  swimlane_id: 4\n  create_stage_id: 50\n");
        config([
            'bridge.config_dir' => $this->dir,
            'bridge.secret_dir' => $this->dir,
            'bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3',
        ]);
        CoordRosterFixture::configure($this->dir.'/coord', ['me' => 7001]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{status: int, body: array<string, mixed>}
     */
    private function tool(string $tool, array $args): array
    {
        CallingSeatSeal::forANewServingProcess();
        $response = $this->call('POST', '/agent-tools/call', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
        ], (string) json_encode(['tool' => $tool, 'args' => $args]));

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true);

        return ['status' => $response->status(), 'body' => $body];
    }

    /**
     * A board answering every request a create or a correction sends. `$fields` is the custom-field
     * read's answer (an int is that HTTP status); the search rows are split by which stamp the
     * search asks under, and by the archive switch.
     *
     * @param  list<array<string, mixed>>|int|null  $fields
     * @param  array{tag?: list<array<string, mixed>>, payload?: list<array<string, mixed>>, tag_archived?: list<array<string, mixed>>, payload_archived?: list<array<string, mixed>>, id?: list<array<string, mixed>>}  $rows
     */
    private function board(array|int|null $fields, array $rows = []): void
    {
        Http::fake(function (Request $request) use ($fields, $rows) {
            $url = urldecode($request->url());
            if (str_contains($url, '/custom_fields.json')) {
                return is_int($fields) ? Http::response('boom', $fields) : Http::response($fields === null ? ['nodata' => true] : ['data' => $fields]);
            }
            if (str_contains($url, '/tasks/search.json')) {
                $archived = str_contains($url, 'archived=1');
                $which = match (true) {
                    str_contains($url, 'custom_field_idempotency_key:') => 'payload',
                    str_contains($url, 'tags:"idem:') => 'tag',
                    default => 'id',
                };
                $key = $which.($archived && $which !== 'id' ? '_archived' : '');

                return Http::response(['data' => $archived && $which === 'id' ? [] : ($rows[$key] ?? [])]);
            }
            if ($request->method() === 'POST') {
                return Http::response(['data' => ['id' => 77]], 201);
            }
            if ($request->method() === 'GET') {
                return Http::response(['data' => ['id' => 77, 'board_id' => self::BOARD, 'swimlane_id' => 4, 'assigned_user_id' => null]]);
            }

            return Http::response(['data' => ['id' => 42]]);
        });
    }

    /** @return list<array<string, mixed>> */
    private static function fields(bool $createdBy = true, bool $idempotency = true, bool $filterable = true): array
    {
        return array_values(array_filter([
            $createdBy ? ['key' => BoardCreateCardTool::CREATED_BY_KEY, 'type' => 'string', 'is_filterable' => true] : null,
            $idempotency ? ['key' => BoardCreateCardTool::IDEMPOTENCY_KEY, 'type' => 'string', 'is_filterable' => $filterable] : null,
        ]));
    }

    /** @return array<string, mixed>|null */
    private static function sentCreate(): ?array
    {
        $body = null;
        Http::recorded(function (Request $r) use (&$body) {
            if ($r->method() === 'POST' && str_ends_with($r->url(), '/tasks.json')) {
                $body = $r->data();
            }

            return false;
        });

        return $body;
    }

    /** @return list<string> */
    private static function searches(): array
    {
        $out = [];
        Http::recorded(function (Request $r) use (&$out) {
            if (str_contains($r->url(), '/tasks/search.json')) {
                $out[] = urldecode($r->url());
            }

            return false;
        });

        return $out;
    }

    // ─── the plain-language rules ─────────────────────────────────────────────

    public function test_create_refuses_a_title_the_rules_refuse_with_kbcards_text_and_sends_nothing(): void
    {
        $this->board(self::fields());

        $r = $this->tool('board_create_card', ['title' => 'Follow up on card#11140', 'description' => PlainCardText::DESCRIPTION]);

        $this->assertSame(422, $r['status']);
        $this->assertSame('card_text_title', $r['body']['reason'] ?? null);
        $this->assertStringStartsWith('board_create_card: the title breaks the plain-language card rules', (string) $r['body']['error']);
        $this->assertStringContainsString('T4 (no card, PR, issue or DL number): card#11140', (string) $r['body']['error']);
        $this->assertStringContainsString('suggested title: Follow up on', (string) $r['body']['error']);
        Http::assertNothingSent();
    }

    public function test_create_refuses_a_description_without_the_filing_bar_or_d1_and_sends_nothing(): void
    {
        $this->board(self::fields());

        foreach ([
            'no IMPACT line' => ['Plain words only', "the card's description states no IMPACT line"],
            'no divider' => ["Plain words\nIMPACT: RUNTIME", "no 'Technical notes (for agents)' line"],
            'absent' => [null, "the card's description states no IMPACT line"],
        ] as $label => [$description, $expected]) {
            $args = ['title' => 'Fix the login page'] + ($description === null ? [] : ['description' => $description]);
            $r = $this->tool('board_create_card', $args);

            $this->assertSame(422, $r['status'], $label);
            $this->assertSame('card_text_description', $r['body']['reason'] ?? null, $label);
            $this->assertStringContainsString($expected, (string) $r['body']['error'], $label);
        }
        Http::assertNothingSent();

        // CONTROL: the same title with a description in the layout is created.
        $this->assertSame(200, $this->tool('board_create_card', ['title' => 'Fix the login page', 'description' => PlainCardText::DESCRIPTION])['status']);
    }

    public function test_correct_refuses_a_name_or_description_the_rules_refuse_and_a_clear_is_not_held_to_them(): void
    {
        $this->board(self::fields(), ['id' => [['id' => 42, 'board_id' => self::BOARD, 'swimlane_id' => 4, 'tags' => ['created-by:me']]]]);

        $name = $this->tool('board_correct_card', ['card_id' => 42, 'name' => 'Edit docs/README.md']);
        $this->assertSame(422, $name['status']);
        $this->assertSame('card_text_title', $name['body']['reason'] ?? null);
        $this->assertStringContainsString('T2 (no file path): docs/README.md', (string) $name['body']['error']);
        $this->assertStringContainsString('nothing was written.', (string) $name['body']['error']);

        $description = $this->tool('board_correct_card', ['card_id' => 42, 'description' => 'no layout here']);
        $this->assertSame(422, $description['status']);
        $this->assertSame('card_text_description', $description['body']['reason'] ?? null);
        Http::assertNothingSent();

        $clear = $this->tool('board_correct_card', ['card_id' => 42, 'description' => '']);
        $this->assertSame(200, $clear['status'], json_encode($clear['body']));
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r->data() === ['description' => '']);
    }

    // ─── the stamps, decided per board ────────────────────────────────────────

    public function test_a_board_registering_both_keys_gets_payload_stamps_and_no_legacy_tags(): void
    {
        $this->board(self::fields());

        $r = $this->tool('board_create_card', ['title' => 'Fix the login page', 'description' => PlainCardText::DESCRIPTION, 'idempotency_key' => 'K1', 'tags' => ['priority:high']]);

        $this->assertSame(200, $r['status'], json_encode($r['body']));
        $sent = self::sentCreate();
        $this->assertSame(['created_by' => 'me', 'idempotency_key' => 'me:k1'], $sent['payload'] ?? null);
        $this->assertSame(['priority:high'], $sent['tags'] ?? null);
        // Correlated under BOTH stamps, on both sides of the archive switch.
        $searches = self::searches();
        foreach (['tags:"idem:me:k1"', 'custom_field_idempotency_key:"me:k1"'] as $term) {
            $this->assertNotEmpty(preg_grep('/'.preg_quote($term, '/').'(?!.*archived=1)/', $searches), "no live search for {$term}");
            $this->assertNotEmpty(preg_grep('/'.preg_quote($term, '/').'.*archived=1/', $searches), "no archived search for {$term}");
        }
    }

    public function test_a_board_registering_neither_key_gets_the_legacy_tags_and_no_payload(): void
    {
        $this->board([]);

        $this->assertSame(200, $this->tool('board_create_card', ['title' => 'Fix the login page', 'description' => PlainCardText::DESCRIPTION, 'idempotency_key' => 'k1'])['status']);

        $sent = self::sentCreate();
        $this->assertSame([], $sent['payload'] ?? null);
        $this->assertSame(['created-by:me', 'idem:me:k1'], $sent['tags'] ?? null);
        $this->assertSame([], preg_grep('/custom_field_/', self::searches()), 'a key the board does not register is never searched — kanban would answer empty');
    }

    public function test_an_idempotency_field_kanban_may_not_filter_on_keeps_the_legacy_idem_tag(): void
    {
        $this->board(self::fields(filterable: false));

        $this->assertSame(200, $this->tool('board_create_card', ['title' => 'Fix the login page', 'description' => PlainCardText::DESCRIPTION, 'idempotency_key' => 'k1'])['status']);

        $sent = self::sentCreate();
        $this->assertSame(['created_by' => 'me'], $sent['payload'] ?? null);
        $this->assertSame(['idem:me:k1'], $sent['tags'] ?? null);
        $this->assertSame([], preg_grep('/custom_field_/', self::searches()), 'a search on an unfilterable field is a kanban 422');
    }

    public function test_an_earlier_card_stamped_only_by_payload_key_is_the_idempotent_hit(): void
    {
        $this->board(self::fields(), ['payload' => [['id' => 31, 'board_id' => self::BOARD]]]);

        $r = $this->tool('board_create_card', ['title' => 'Fix the login page', 'description' => PlainCardText::DESCRIPTION, 'idempotency_key' => 'k1']);

        $this->assertSame(200, $r['status']);
        $this->assertFalse($r['body']['result']['created']);
        $this->assertSame(31, $r['body']['result']['card_id']);
        $this->assertNull(self::sentCreate(), 'a keyed retry created a second card beside the one its payload key names');
    }

    public function test_an_earlier_card_stamped_by_legacy_tag_is_still_the_hit_on_a_board_that_now_registers_the_key(): void
    {
        $this->board(self::fields(), ['tag' => [['id' => 30, 'board_id' => self::BOARD]]]);

        $r = $this->tool('board_create_card', ['title' => 'Fix the login page', 'description' => PlainCardText::DESCRIPTION, 'idempotency_key' => 'k1']);

        $this->assertSame(30, $r['body']['result']['card_id'] ?? null);
        $this->assertNull(self::sentCreate());
    }

    public function test_an_archived_card_stamped_only_by_payload_key_refuses_the_create(): void
    {
        $this->board(self::fields(), ['payload_archived' => [['id' => 33, 'board_id' => self::BOARD]]]);

        $r = $this->tool('board_create_card', ['title' => 'Fix the login page', 'description' => PlainCardText::DESCRIPTION, 'idempotency_key' => 'k1']);

        $this->assertSame(422, $r['status']);
        $this->assertStringContainsString('ARCHIVED card(s) — 33', (string) $r['body']['error']);
        $this->assertNull(self::sentCreate());
    }

    public function test_an_unreadable_registration_falls_back_to_the_legacy_tag_on_an_unkeyed_create(): void
    {
        $this->board(500);

        $r = $this->tool('board_create_card', ['title' => 'Fix the login page', 'description' => PlainCardText::DESCRIPTION]);

        $this->assertSame(200, $r['status']);
        $this->assertSame([], self::sentCreate()['payload'] ?? null);
        $this->assertSame(['created-by:me'], self::sentCreate()['tags'] ?? null);
    }

    /**
     * @return array<string, array{list<array<string, mixed>>|int|null}>
     */
    public static function unreadableRegistrations(): array
    {
        return ['a 5xx' => [500], 'a body with no collection' => [null]];
    }

    /**
     * ⛔ A KEYED create that cannot read the registration cannot tell where an earlier attempt's card
     * is stamped, so it creates nothing — the retryable 502, as an unreadable idempotency search is.
     * One case per test: a second `Http::fake()` in one test stacks BEHIND the first and never answers.
     *
     * @param  list<array<string, mixed>>|int|null  $fields
     */
    #[DataProvider('unreadableRegistrations')]
    public function test_an_unreadable_registration_refuses_a_keyed_create_and_creates_nothing(array|int|null $fields): void
    {
        $this->board($fields);

        $r = $this->tool('board_create_card', ['title' => 'Fix the login page', 'description' => PlainCardText::DESCRIPTION, 'idempotency_key' => 'k1']);

        $this->assertSame(502, $r['status']);
        $this->assertNull(self::sentCreate());
    }

    /** The registration read is a BOARD read: a 403 on it names membership, which a search 403 denies by name. */
    public function test_a_refused_registration_read_is_diagnosed_as_a_board_read(): void
    {
        $this->board(403);

        $r = $this->tool('board_create_card', ['title' => 'Fix the login page', 'description' => PlainCardText::DESCRIPTION, 'idempotency_key' => 'k1']);

        $this->assertSame(422, $r['status']);
        $this->assertStringContainsString("USER's membership of that board", (string) $r['body']['error']);
        $this->assertNull(self::sentCreate());
    }

    // ─── the mint stamp authorizes a correction under either spelling ─────────

    public function test_a_card_stamped_by_the_payload_key_alone_is_the_seats_to_correct(): void
    {
        $this->board(self::fields(), ['id' => [['id' => 42, 'board_id' => self::BOARD, 'swimlane_id' => 4, 'tags' => [], 'payload' => ['created_by' => 'me'], 'assigned_user_id' => null]]]);

        $r = $this->tool('board_correct_card', ['card_id' => 42, 'name' => 'Fix the login page']);

        $this->assertSame(200, $r['status'], json_encode($r['body']));
        $this->assertSame('minted', $r['body']['result']['authorized_by']);
    }

    public function test_another_agents_payload_stamp_does_not_authorize_a_correction(): void
    {
        $this->board(self::fields(), ['id' => [['id' => 42, 'board_id' => self::BOARD, 'swimlane_id' => 4, 'tags' => [], 'payload' => ['created_by' => 'someone-else'], 'assigned_user_id' => null]]]);

        $r = $this->tool('board_correct_card', ['card_id' => 42, 'name' => 'Fix the login page']);

        $this->assertSame(422, $r['status']);
        $this->assertStringContainsString('is not one of yours', (string) $r['body']['error']);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    }
}
