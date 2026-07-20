<?php

namespace Tests\Feature\AgentTools;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * POST /agent-tools/call — the two-way board tools ingress (DL-217). Security-
 * significant: these assert the guard DECISIONS (loopback gate, bearer
 * resolution, swimlane-force, tag/charset sanitization, idempotency), each
 * mutation-checked by also asserting the side effect that must NOT happen (no
 * create on a refusal).
 */
class AgentToolsCallTest extends TestCase
{
    private string $dir;

    private string $token = 'tools-bearer-abc123';   // gitleaks:allow — test fixture

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/agent-tools-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/kanban');

        $this->writeSecret($this->dir.'/kanban/writeback-token', 'wb-token');   // gitleaks:allow — test fixture
        $this->writeAgent('me', $this->token, [
            'board_id' => 10, 'swimlane_id' => 4, 'create_stage_id' => 55,
        ]);

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

    private function writeSecret(string $path, string $value): void
    {
        File::put($path, $value);
        chmod($path, 0o600);
    }

    /** @param array<string, int> $scope */
    private function writeAgent(string $name, string $tokenValue, array $scope, ?string $extra = null): void
    {
        $tokenFile = $this->dir."/{$name}-tools-token";
        $this->writeSecret($tokenFile, $tokenValue);

        $yaml = "identity:\n  kanban_user_id: ".crc32($name)."\nsubscriptions: []\nboard_tools:\n  enabled: true\n  auth:\n    token_path: {$tokenFile}\n  board_id: {$scope['board_id']}\n  swimlane_id: {$scope['swimlane_id']}\n  create_stage_id: {$scope['create_stage_id']}\n".($extra ?? '');
        File::put($this->dir."/{$name}.yml", $yaml);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $server
     */
    private function callTool(array $body, ?string $bearer = null, array $server = [])
    {
        $bearer ??= $this->token;
        $server = array_merge(['REMOTE_ADDR' => '127.0.0.1'], $server);
        if ($bearer !== '') {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$bearer;
        }

        return $this->call('POST', '/agent-tools/call', [], [], [], array_merge([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $server), (string) json_encode($body));
    }

    // ─── loopback gate ───────────────────────────────────────────────────────

    public function test_non_loopback_peer_is_refused_and_creates_nothing(): void
    {
        Http::fake();   // any outbound call would be recorded; assert none happens

        $res = $this->callTool(['tool' => 'board_create_card', 'args' => ['title' => 'x']], server: ['REMOTE_ADDR' => '203.0.113.9']);

        $res->assertStatus(403);
        Http::assertNothingSent();
    }

    public function test_loopback_peer_is_admitted(): void
    {
        Http::fake(['*/tasks.json' => Http::response(['data' => ['id' => 1]], 201)]);

        $this->callTool(['tool' => 'board_create_card', 'args' => ['title' => 'x']])
            ->assertStatus(200);
    }

    // ─── bearer resolution ───────────────────────────────────────────────────

    public function test_missing_bearer_is_refused(): void
    {
        Http::fake();
        $this->callTool(['tool' => 'board_my_cards'], bearer: '')->assertStatus(401);
        Http::assertNothingSent();
    }

    public function test_unknown_bearer_is_refused(): void
    {
        Http::fake();
        $this->callTool(['tool' => 'board_my_cards'], bearer: 'not-a-real-token')->assertStatus(401);
        Http::assertNothingSent();
    }

    public function test_colliding_token_fails_closed_for_both_agents(): void
    {
        // Second agent minted with the SAME bearer value → both excluded from the
        // index → the shared bearer authenticates as neither (401).
        $this->writeAgent('you', $this->token, ['board_id' => 20, 'swimlane_id' => 7, 'create_stage_id' => 99]);
        Http::fake();

        $this->callTool(['tool' => 'board_my_cards'])->assertStatus(401);
        Http::assertNothingSent();
    }

    public function test_install_with_no_board_tools_configured_refuses_every_bearer(): void
    {
        // Deactivation is by "no resolvable bearer", not route-absence: with no
        // `board_tools` block on ANY agent, the roster indexes zero tokens, so any
        // bearer resolves to no agent → 401 (and nothing is created). This is the
        // end-to-end no-op assertion for the fail-closed opt-in.
        File::put($this->dir.'/me.yml', "identity:\n  kanban_user_id: ".crc32('me')."\nsubscriptions: []\n");
        Http::fake();

        $this->callTool(['tool' => 'board_create_card', 'args' => ['title' => 'x']], bearer: $this->token)
            ->assertStatus(401);
        $this->callTool(['tool' => 'board_my_cards'], bearer: 'any-other-bearer')
            ->assertStatus(401);
        Http::assertNothingSent();
    }

    // ─── board_create_card: scope + sanitization ─────────────────────────────

    public function test_create_forces_swimlane_from_config_ignoring_caller(): void
    {
        Http::fake(['*/tasks.json' => Http::response(['data' => ['id' => 42]], 201)]);

        $res = $this->callTool(['tool' => 'board_create_card', 'args' => [
            'title' => 'capture me', 'description' => 'body', 'swimlane_id' => 999, 'board_id' => 999,
        ]]);

        $res->assertStatus(200)->assertJsonPath('result.card_id', 42);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/tasks.json')
            && $r['task']['swimlane_id'] === 4          // FORCED from config, not 999
            && $r['task']['board_id'] === 10
            && $r['task']['workflow_stage_id'] === 55
            && $r['task']['name'] === 'capture me'
            && $r['task']['description'] === 'body'
            && $r['task']['payload'] === []             // {} in v1
            && in_array('created-by:me', $r['task']['tags'], true));
    }

    /**
     * @return list<array{string}>
     */
    public static function reservedTagCases(): array
    {
        return [['created-by:someoneelse'], ['idem:me:forged'], ['id:123'], ['type:brief'], ['triaged']];
    }

    #[DataProvider('reservedTagCases')]
    public function test_reserved_caller_tag_is_refused_and_creates_nothing(string $tag): void
    {
        Http::fake(['*/tasks.json' => Http::response(['data' => ['id' => 1]], 201)]);

        $this->callTool(['tool' => 'board_create_card', 'args' => ['title' => 't', 'tags' => [$tag]]])
            ->assertStatus(422);
        Http::assertNothingSent();
    }

    /**
     * The reserved-tag guard is case-INSENSITIVE: the kanban tag search it
     * protects folds under a `_ci` collation, so a case-exact guard would let a
     * mixed/upper-case reserved tag through to poison another agent's lowercase
     * idempotency/provenance probe. Every case-variant here must 422.
     *
     * @return list<array{string}>
     */
    public static function caseVariantReservedTagCases(): array
    {
        return [
            ['IDEM:agentB:daily'], ['Idem:x'], ['Created-By:victim'], ['ID:foo'],
            ['TYPE:bug'], ['Triaged'], ['TRIAGED'], [' triaged '],
        ];
    }

    #[DataProvider('caseVariantReservedTagCases')]
    public function test_case_variant_reserved_caller_tag_is_refused_and_creates_nothing(string $tag): void
    {
        Http::fake(['*/tasks.json' => Http::response(['data' => ['id' => 1]], 201)]);

        $this->callTool(['tool' => 'board_create_card', 'args' => ['title' => 't', 'tags' => [$tag]]])
            ->assertStatus(422);
        Http::assertNothingSent();
    }

    /**
     * The tag-search metacharacters (" * _ %) and any non-ASCII byte are refused:
     * they defeat the ASCII casefold vs MariaDB's `_ci` folding, or mis-split /
     * wildcard-over-match the kanban tokenizer.
     *
     * @return list<array{string}>
     */
    public static function outOfCharsetTagCases(): array
    {
        return [['bad"quote'], ['star*tag'], ['under_score'], ['per%cent'], ['café']];
    }

    #[DataProvider('outOfCharsetTagCases')]
    public function test_out_of_charset_caller_tag_is_refused_and_creates_nothing(string $tag): void
    {
        Http::fake(['*/tasks.json' => Http::response(['data' => ['id' => 1]], 201)]);

        $this->callTool(['tool' => 'board_create_card', 'args' => ['title' => 't', 'tags' => [$tag]]])
            ->assertStatus(422);
        Http::assertNothingSent();
    }

    /**
     * Regression guard: the charset constraint must NOT reject ordinary ASCII
     * labels, and a non-reserved colon (not a reserved PREFIX) is allowed.
     *
     * @return list<array{string}>
     */
    public static function legitimateTagCases(): array
    {
        return [['feature'], ['needs-review'], ['priority:high']];
    }

    #[DataProvider('legitimateTagCases')]
    public function test_legitimate_caller_tag_is_accepted(string $tag): void
    {
        Http::fake(['*/tasks.json' => Http::response(['data' => ['id' => 77]], 201)]);

        $res = $this->callTool(['tool' => 'board_create_card', 'args' => ['title' => 't', 'tags' => [$tag]]]);

        $res->assertStatus(200)->assertJsonPath('result.card_id', 77);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/tasks.json')
            && in_array($tag, $r['task']['tags'], true));
    }

    public function test_idempotency_key_is_normalized_to_lowercase(): void
    {
        // The stored/searched idem tag must be lowercased: a mixed-case key
        // `Report` produces the same `idem:me:report` needle as a lowercase call,
        // so the two correlate to the SAME card.
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => [['id' => 7]]])]);

        $res = $this->callTool(['tool' => 'board_create_card', 'args' => ['title' => 't', 'idempotency_key' => 'Report']]);

        $res->assertStatus(200)
            ->assertJsonPath('result.created', false)
            ->assertJsonPath('result.idempotent_hit', true)
            ->assertJsonPath('result.card_id', 7);
        // The correlation needle sent to kanban is lowercased (idem:me:report).
        Http::assertSent(fn ($r) => str_contains($r->url(), '/tasks/search.json')
            && str_contains(urldecode($r->url()), 'idem:me:report'));
    }

    public function test_out_of_charset_idempotency_key_is_refused_and_creates_nothing(): void
    {
        Http::fake(['*/tasks.json' => Http::response(['data' => ['id' => 1]], 201)]);

        $this->callTool(['tool' => 'board_create_card', 'args' => ['title' => 't', 'idempotency_key' => 'bad key!%_*']])
            ->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_missing_title_is_refused(): void
    {
        Http::fake(['*/tasks.json' => Http::response(['data' => ['id' => 1]], 201)]);

        $this->callTool(['tool' => 'board_create_card', 'args' => ['description' => 'no title']])
            ->assertStatus(422);
        Http::assertNothingSent();
    }

    // ─── board_create_card: idempotency both legs ────────────────────────────

    public function test_idempotency_correlate_before_create_returns_existing(): void
    {
        // Leg 1: a prior card already carries idem:me:k1 → return it, NO create.
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => [['id' => 7]]])]);

        $res = $this->callTool(['tool' => 'board_create_card', 'args' => ['title' => 't', 'idempotency_key' => 'k1']]);

        $res->assertStatus(200)
            ->assertJsonPath('result.created', false)
            ->assertJsonPath('result.idempotent_hit', true)
            ->assertJsonPath('result.card_id', 7);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/tasks.json') && ! str_contains($r->url(), 'search'));
    }

    public function test_idempotency_raced_duplicate_is_collapsed(): void
    {
        // Leg 2: correlate empty → create (id 8) → re-read finds a raced 8 AND 9 →
        // collapse archives the higher id (9), survivor is 8.
        Http::fake([
            '*/tasks/search.json*' => Http::sequence()
                ->push(['data' => []])                          // correlate-before-create: empty
                ->push(['data' => [['id' => 8], ['id' => 9]]]), // post-create re-read: raced pair
            '*/tasks.json' => Http::response(['data' => ['id' => 8]], 201),
            '*/tasks/9.json' => Http::response(['data' => ['id' => 9, 'archived_at' => '2026-07-20T00:00:00Z']]),
        ]);

        $res = $this->callTool(['tool' => 'board_create_card', 'args' => ['title' => 't', 'idempotency_key' => 'k2']]);

        $res->assertStatus(200)->assertJsonPath('result.card_id', 8);
        // The raced duplicate (9) was archived.
        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), '/tasks/9.json')
            && ($r['_action'] ?? null) === 'archive');
    }

    // ─── board_my_cards: swimlane row filter ─────────────────────────────────

    public function test_my_cards_drops_a_foreign_swimlane_row(): void
    {
        Http::fake([
            '*/boards/10/preload.json' => Http::response(['data' => ['workflows' => [
                ['stages' => [['id' => 50, 'name' => 'Backlog', 'position' => 1]]],
            ]]]),
            '*/tasks/search.json*' => Http::response(['data' => [
                ['id' => 1, 'name' => 'mine', 'workflow_stage_id' => 50, 'swimlane_id' => 4, 'tags' => ['x'], 'payload' => ['dl_number' => 'DL-1'], 'updated_at' => '2026-07-20'],
                ['id' => 2, 'name' => 'FOREIGN', 'workflow_stage_id' => 50, 'swimlane_id' => 99, 'tags' => [], 'payload' => [], 'updated_at' => '2026-07-20'],
            ]]),
        ]);

        $res = $this->callTool(['tool' => 'board_my_cards']);

        $res->assertStatus(200)
            ->assertJsonPath('result.cards_by_stage.Backlog.0.id', 1)
            ->assertJsonPath('result.cards_by_stage.Backlog.0.name', 'mine');
        // The foreign-swimlane row is NOT present anywhere in the result.
        $this->assertStringNotContainsString('FOREIGN', $res->getContent());
        $this->assertCount(1, $res->json('result.cards_by_stage.Backlog'));
    }

    // ─── tool + body validation ──────────────────────────────────────────────

    public function test_unknown_tool_is_refused(): void
    {
        Http::fake();
        $this->callTool(['tool' => 'board_delete_everything', 'args' => []])->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_missing_tool_key_is_refused(): void
    {
        Http::fake();
        $this->callTool(['args' => []])->assertStatus(422);
        Http::assertNothingSent();
    }
}
