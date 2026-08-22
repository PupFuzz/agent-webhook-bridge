<?php

namespace Tests\Feature\AgentTools;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

        $yaml = "identity:\n  kanban_user_id: ".crc32($name)."\nsubscriptions: []\nboard_tools:\n  enabled: true\n  transport: http\n  auth:\n    token_path: {$tokenFile}\n  board_id: {$scope['board_id']}\n  swimlane_id: {$scope['swimlane_id']}\n  create_stage_id: {$scope['create_stage_id']}\n".($extra ?? '');
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
        // Both stubs are load-bearing: the create arm ALSO reads the card back
        // (card#7225), and an unstubbed request is NOT blocked by Http::fake — it
        // goes to the real network, where the tool's fail-soft catch swallows the
        // failure and this assertStatus(200) passes through the degraded path
        // instead of the one it names. See CLAUDE_TESTING.md.
        Http::fake([
            '*/tasks.json' => Http::response(['data' => ['id' => 1]], 201),
            '*/tasks/*.json' => Http::response(['data' => ['id' => 1, 'board_id' => 10, 'swimlane_id' => 4]]),
        ]);

        $this->callTool(['tool' => 'board_create_card', 'args' => ['title' => 'x']])
            ->assertStatus(200)
            ->assertJsonPath('result.placement_observed', true);   // the admitted call went down the STUBBED path
    }

    public function test_external_peer_with_spoofed_loopback_xff_is_refused(): void
    {
        // Posture pin (DL-220): the gate reads the TCP peer ($request->ip()), and
        // X-Forwarded-For is UNTRUSTED (no TrustProxies), so a spoofed XFF claiming
        // 127.0.0.1 cannot make an external peer look local. This test goes RED the
        // moment a TrustProxies registration lands (the gate would then honor the
        // forged header and admit the external peer) — that is the point of pinning it.
        Http::fake();

        $this->callTool(['tool' => 'board_create_card', 'args' => ['title' => 'x']], server: [
            'REMOTE_ADDR' => '203.0.113.9',
            'HTTP_X_FORWARDED_FOR' => '127.0.0.1',
        ])->assertStatus(403);
        Http::assertNothingSent();
    }

    public function test_loopback_peer_with_external_xff_is_admitted(): void
    {
        // The mirror of the spoof case: a loopback peer carrying an EXTERNAL XFF is
        // still admitted — the header is inert in BOTH directions (it neither grants
        // nor revokes access), proving the gate keys solely on the real TCP peer.
        Http::fake([
            '*/tasks.json' => Http::response(['data' => ['id' => 1]], 201),
            '*/tasks/*.json' => Http::response(['data' => ['id' => 1, 'board_id' => 10, 'swimlane_id' => 4]]),
        ]);

        $this->callTool(['tool' => 'board_create_card', 'args' => ['title' => 'x']], server: [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
        ])->assertStatus(200)
            ->assertJsonPath('result.placement_observed', true);   // stubbed read-back, not the fail-soft path
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

    public function test_default_block_reusing_the_channel_token_authenticates(): void
    {
        // The default-ON flip end to end: an agent with a DEFAULT board_tools block
        // (no enabled key, no auth) on an HTTP channel reuses its CHANNEL token as the
        // tools bearer — the resolver indexes it, so presenting that channel-token
        // value authenticates and the read runs.
        $channelTokenFile = $this->dir.'/you-channel-token';
        $channelToken = 'you-channel-token-value';   // gitleaks:allow — test fixture
        $this->writeSecret($channelTokenFile, $channelToken);
        File::put($this->dir.'/you.yml', "identity:\n  kanban_user_id: ".crc32('you')."\nsubscriptions: []\n"
            ."channel:\n  url: http://127.0.0.1:8788\n  auth:\n    token_path: {$channelTokenFile}\n"
            ."board_tools:\n  transport: http\n  board_id: 20\n  swimlane_id: 7\n  create_stage_id: 99\n");
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => []]), '*/boards/*/preload.json' => Http::response(['data' => ['swimlanes' => [['id' => 7]], 'workflows' => [['stages' => [['id' => 99, 'name' => 'Backlog', 'position' => 1]]]]]])]);

        $this->callTool(['tool' => 'board_my_cards'], bearer: $channelToken)->assertStatus(200);
    }

    public function test_mixed_source_collision_fails_both_agents_closed(): void
    {
        // Agent A ('me') presents its explicit ALIAS token; agent B reuses its CHANNEL
        // token — and both files hold the SAME value. The bearer is ambiguous, so BOTH
        // are excluded from the index and the shared value authenticates as neither.
        $channelTokenFile = $this->dir.'/you-channel-token';
        $this->writeSecret($channelTokenFile, $this->token);   // same value as agent A's alias
        File::put($this->dir.'/you.yml', "identity:\n  kanban_user_id: ".crc32('you')."\nsubscriptions: []\n"
            ."channel:\n  url: http://127.0.0.1:8788\n  auth:\n    token_path: {$channelTokenFile}\n"
            ."board_tools:\n  transport: http\n  board_id: 20\n  swimlane_id: 7\n  create_stage_id: 99\n");
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

    /**
     * A bearer file that is PRESENT and unreadable by the web user must refuse like any
     * other unresolvable bearer — 401, no body detail — not 500 (card#5778).
     *
     * WHY THE STATUS IS THE SECURITY ASSERTION, not just tidiness: this controller's
     * stated contract is that it does not distinguish "unknown token" from
     * "collided/unreadable token" to the caller. A 500 tells an UNAUTHENTICATED caller
     * that another agent's bearer file exists and could not be read — exactly the
     * distinction the design refuses to draw — and it does so on the one door reachable
     * without any credential at all.
     */
    public function test_present_but_unreadable_bearer_is_refused_401_not_500(): void
    {
        chmod($this->dir.'/me-tools-token', 0o000);
        if (is_readable($this->dir.'/me-tools-token')) {
            $this->markTestSkipped('this process reads through mode 0000 (running as root?) — the unreadable state is not reachable here');
        }
        Http::fake();

        $this->callTool(['tool' => 'board_my_cards'])->assertStatus(401);
        $this->callTool(['tool' => 'board_create_card', 'args' => ['title' => 'x']])->assertStatus(401);
        Http::assertNothingSent();
    }

    // ─── board_create_card: scope + sanitization ─────────────────────────────

    public function test_create_forces_swimlane_from_config_ignoring_caller(): void
    {
        Log::spy();
        Http::fake([
            '*/tasks.json' => Http::response(['data' => ['id' => 42]], 201),
            '*/tasks/*.json' => Http::response(['data' => ['id' => 42, 'board_id' => 10, 'swimlane_id' => 4]]),
        ]);

        $res = $this->callTool(['tool' => 'board_create_card', 'args' => [
            'title' => 'capture me', 'description' => 'body', 'swimlane_id' => 999, 'board_id' => 999,
        ]]);

        $res->assertStatus(200)->assertJsonPath('result.card_id', 42);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/tasks.json')
            && $r['swimlane_id'] === 4          // FORCED from config, not 999
            && $r['board_id'] === 10
            && $r['workflow_stage_id'] === 55
            && $r['name'] === 'capture me'
            && $r['description'] === 'body'
            && $r['payload'] === []             // {} in v1
            && in_array('created-by:me', $r['tags'], true));
        // CONTROL for the placement warnings (card#7225): the card came back where
        // this agent writes, so neither the divergence nor the unreadable warning
        // fires. Keyed on those two messages, never on `warning` at large.
        Log::shouldNotHaveReceived('warning', [\Mockery::pattern('/NOT where this agent is configured to write/'), \Mockery::any()]);
        Log::shouldNotHaveReceived('warning', [\Mockery::pattern('/could not be read back/'), \Mockery::any()]);
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
     * The reserved-tag guard is case-INSENSITIVE: whether the kanban tag search
     * it protects folds case is a per-driver collation fact (MariaDB utf8mb4_bin
     * does not; a kanban running on SQLite does), so the guard refuses every case
     * variant rather than betting on the deployed collation — a case-exact guard
     * would let a mixed/upper-case reserved tag through to a folding backend to
     * poison another agent's lowercase idempotency/provenance probe. Every
     * case-variant here must 422.
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
     * a non-ASCII byte folds differently under the guard's ASCII casefold than
     * under a Unicode-aware driver collation, and the metacharacters mis-split /
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
        Http::fake([
            '*/tasks.json' => Http::response(['data' => ['id' => 77]], 201),
            '*/tasks/*.json' => Http::response(['data' => ['id' => 77, 'board_id' => 10, 'swimlane_id' => 4]]),
        ]);

        $res = $this->callTool(['tool' => 'board_create_card', 'args' => ['title' => 't', 'tags' => [$tag]]]);

        $res->assertStatus(200)->assertJsonPath('result.card_id', 77);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/tasks.json')
            && in_array($tag, $r['tags'], true));
        // No idempotency_key ⇒ NO correlation read on either side of the archive
        // axis: there is no key to correlate on, so the DL-297 probe (like the
        // DL-198 pre-check it follows) must not fire and cost a search.
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/tasks/search.json'));
    }

    public function test_idempotency_key_is_normalized_to_lowercase(): void
    {
        // The stored/searched idem tag must be lowercased: a mixed-case key
        // `Report` produces the same `idem:me:report` needle as a lowercase call,
        // so the two correlate to the SAME card.
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 7]]]),
            '*/tasks/*.json' => Http::response(['data' => ['id' => 7, 'board_id' => 10, 'swimlane_id' => 4]]),
        ]);

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

    /**
     * One closure fake answering each read for WHAT IT IS. Kanban's archive axis is
     * a SWITCH (DL-296): `tasks/search.json` with `archived=1` returns archived rows
     * ONLY, without it live rows ONLY — so a `Http::sequence()` on a single
     * wildcard `tasks/search.json` pattern is POPPED by the archive-side probe and hands
     * the post-create re-read the wrong element (the harness defect DL-296 fixed on
     * the coord tests; this file inherited it the moment the tool grew a second
     * read). Keyed on the request instead, so adding a read cannot silently
     * re-target an existing stub.
     *
     * @param  list<array<string, mixed>>  $live  rows the LIVE pre-check answers
     * @param  list<array<string, mixed>>  $archived  rows the archive-side probe answers
     * @param  list<array<string, mixed>>|null  $postCreate  rows the post-create live re-read
     *                                                       answers (null ⇒ same as $live)
     * @param  array<string, mixed>  $readBack  the CARD ROW `GET /tasks/<id>.json` answers —
     *                                          the placement source since card#7225/DL-299.
     *                                          Defaults to a realistic in-scope row; a test
     *                                          about placement names its own.
     */
    private function archiveAxisFake(array $live, array $archived, int $newId, ?array $postCreate = null, array $readBack = ['board_id' => 10, 'swimlane_id' => 4]): \Closure
    {
        $liveReads = 0;

        return function ($request) use ($live, $archived, $newId, $postCreate, $readBack, &$liveReads) {
            $url = urldecode($request->url());
            if ($request->method() === 'GET' && preg_match('#/tasks/(\d+)\.json#', $url, $m) === 1) {
                return Http::response(['data' => ['id' => (int) $m[1]] + $readBack]);
            }
            if (str_contains($url, '/tasks/search.json')) {
                if (str_contains($url, 'archived=1')) {
                    return Http::response(['data' => $archived]);
                }
                $liveReads++;

                return Http::response(['data' => $liveReads > 1 && $postCreate !== null ? $postCreate : $live]);
            }
            if ($request->method() === 'POST') {
                return Http::response(['data' => ['id' => $newId]], 201);
            }

            // The collapse's archive PATCH — echo an archived card so the write reads
            // as applied.
            return Http::response(['data' => ['id' => $newId, 'archived_at' => '2026-08-22T00:00:00Z']]);
        };
    }

    // ─── board_create_card: idempotency both legs ────────────────────────────

    public function test_idempotency_correlate_before_create_returns_existing(): void
    {
        // Leg 1: a prior card already carries idem:me:k1 → return it, NO create.
        // PAIRED WITNESS for the archived-twin refusal below: the ordinary hit must
        // still hand back the same card, or "honours a retire" would be
        // indistinguishable from "stopped being idempotent".
        Http::fake($this->archiveAxisFake(live: [['id' => 7]], archived: [['id' => 99]], newId: 88));

        $res = $this->callTool(['tool' => 'board_create_card', 'args' => ['title' => 't', 'idempotency_key' => 'k1']]);

        $res->assertStatus(200)
            ->assertJsonPath('result.created', false)
            ->assertJsonPath('result.idempotent_hit', true)
            ->assertJsonPath('result.card_id', 7)
            ->assertJsonPath('result.board_id', 10)
            ->assertJsonPath('result.swimlane_id', 4)
            ->assertJsonPath('result.placement_observed', true);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/tasks.json') && ! str_contains($r->url(), 'search'));
        // A LIVE hit answers first and pays NOTHING for the archive axis (DL-297
        // Decision 2) — the archived row staged above is never consulted, so an
        // archived twin beside a live card cannot suppress anything.
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'archived=1'));
    }

    public function test_idempotency_raced_duplicate_is_collapsed(): void
    {
        // Leg 2: correlate empty → create (id 8) → re-read finds a raced 8 AND 9 →
        // collapse archives the higher id (9), survivor is 8.
        // Keyed by request, not Http::sequence(): the archive-side probe DL-297 added
        // pops a sequence and hands the post-create re-read the archived element.
        // THIS worker's create returns 9, the survivor is 8 — deliberately NOT the
        // same id, or "reports the survivor" and "reports the id kanban handed me"
        // would be indistinguishable, and the read-back assertion below could not
        // fail.
        Http::fake($this->archiveAxisFake(
            live: [],                                   // correlate-before-create: empty
            archived: [],                               // and nothing retired ⇒ the create fires
            newId: 9,                                   // what kanban answered THIS worker
            postCreate: [['id' => 8], ['id' => 9]],     // post-create re-read: raced pair
        ));

        $res = $this->callTool(['tool' => 'board_create_card', 'args' => ['title' => 't', 'idempotency_key' => 'k2']]);

        $res->assertStatus(200)
            ->assertJsonPath('result.created', true)     // positive: this cannot pass on a SUPPRESSED create
            ->assertJsonPath('result.card_id', 8);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/tasks.json') && ! str_contains($r->url(), 'search'));
        // The raced duplicate (9) was archived.
        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), '/tasks/9.json')
            && ($r['_action'] ?? null) === 'archive');
        // ⭐ The advertised property: the placement read-back targets the SURVIVOR
        // (8), the card the response actually names — not 9, the id this worker's
        // own create returned. Without this the response could name 8 while
        // describing 9's placement, and every assertion above would still pass.
        Http::assertSent(fn ($r) => $r->method() === 'GET' && str_contains($r->url(), '/tasks/8.json'));
        Http::assertNotSent(fn ($r) => $r->method() === 'GET' && str_contains($r->url(), '/tasks/9.json'));
    }

    public function test_idempotency_key_whose_only_card_is_archived_refuses_and_creates_nothing(): void
    {
        // BOARD STATE: the card this key minted (77) exists but is ARCHIVED — a
        // retire. kanban's search is a SWITCH (DL-296): no `archived` param ⇒ live
        // rows only, so the live pre-check sees nothing. Before DL-297 that fell
        // through and minted a SECOND card reporting `"created": true`.
        Http::fake($this->archiveAxisFake(live: [], archived: [['id' => 77]], newId: 88));

        $res = $this->callTool(['tool' => 'board_create_card', 'args' => ['title' => 't', 'idempotency_key' => 'k3']]);

        $res->assertStatus(422);
        $this->assertStringContainsString('77', $res->json('error') ?? '');
        // NOTHING was created — the refusal is the whole outcome.
        Http::assertNotSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/tasks.json') && ! str_contains($r->url(), 'search'));
        // The archived side was read ON THE WIRE (archived=1), not inferred.
        Http::assertSent(fn ($r) => str_contains($r->url(), '/tasks/search.json')
            && str_contains($r->url(), 'archived=1')
            && str_contains(urldecode($r->url()), 'idem:me:k3'));
    }

    public function test_every_archived_twin_is_named_in_the_refusal(): void
    {
        // The remedy is "unarchive THAT card", so a multi-card retire must name
        // both ids — a probe that returned after the first one would still refuse,
        // and still be wrong about which card to unarchive.
        Http::fake($this->archiveAxisFake(live: [], archived: [['id' => 77], ['id' => 91]], newId: 88));

        $res = $this->callTool(['tool' => 'board_create_card', 'args' => ['title' => 't', 'idempotency_key' => 'k3']]);

        $res->assertStatus(422);
        $error = $res->json('error') ?? '';
        $this->assertStringContainsString('77', $error);
        $this->assertStringContainsString('91', $error);
    }

    public function test_no_card_on_either_side_of_the_archive_axis_still_creates(): void
    {
        // The CONTROL for the refusal above: "honours a retire" must not pass as
        // "stopped creating". The archived read runs (asserted on the wire) and
        // finds nothing, so the create fires exactly as before.
        Http::fake($this->archiveAxisFake(live: [], archived: [], newId: 88));

        $res = $this->callTool(['tool' => 'board_create_card', 'args' => ['title' => 't', 'idempotency_key' => 'k4']]);

        $res->assertStatus(200)
            ->assertJsonPath('result.created', true)
            ->assertJsonPath('result.idempotent_hit', false)
            ->assertJsonPath('result.card_id', 88)
            ->assertJsonPath('result.board_id', 10)
            ->assertJsonPath('result.swimlane_id', 4)
            ->assertJsonPath('result.placement_observed', true);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/tasks/search.json') && str_contains($r->url(), 'archived=1'));
    }

    // ─── board_create_card: the placement it REPORTS (card#7225, DL-299) ─────

    public function test_a_created_cards_placement_is_read_back_from_the_card_not_restated_from_config(): void
    {
        // ⛔ THE DEFECT. `board_id` / `swimlane_id` used to be $cfg values — where
        // this agent is CONFIGURED to write — on keys a calling agent consumes as
        // where its card IS. `createCard()` returns an id only, so the placement
        // was never read back: a kanban that did not honour the posted
        // `swimlane_id` answers 201 + an id exactly like one that did, and the
        // tool reported the configured lane 4 for a card sitting in lane 99.
        // The two readings differ ONLY when something has gone wrong, so the old
        // answer was silently correct right up to the moment it mattered.
        Log::spy();
        Http::fake([
            '*/tasks.json' => Http::response(['data' => ['id' => 42]], 201),
            '*/tasks/*.json' => Http::response(['data' => ['id' => 42, 'board_id' => 10, 'swimlane_id' => 99]]),
        ]);

        $res = $this->callTool(['tool' => 'board_create_card', 'args' => ['title' => 't']]);

        $res->assertStatus(200)
            ->assertJsonPath('result.created', true)
            ->assertJsonPath('result.card_id', 42)
            ->assertJsonPath('result.board_id', 10)
            ->assertJsonPath('result.swimlane_id', 99)          // the card's OWN lane, NOT the configured 4
            ->assertJsonPath('result.placement_observed', true)
            // The intent is not deleted, it is RENAMED: the configured scope rides
            // along on its own keys, so this response says both "the card is in
            // lane 99" and "we asked for lane 4" — a divergence a caller can read
            // without a second call, and never one value dressed as the other.
            ->assertJsonPath('result.configured_board_id', 10)
            ->assertJsonPath('result.configured_swimlane_id', 4);
        // The WRITE is unchanged — it still asks for the configured lane. Only the
        // report moved from intent to observation.
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/tasks.json') && $r['swimlane_id'] === 4);
        // And the observation came off the wire, from the card itself.
        Http::assertSent(fn ($r) => $r->method() === 'GET' && str_contains($r->url(), '/tasks/42.json'));
        // The divergence is also RECORDED — the response tells the caller where the
        // card is, the log tells the operator that it is not where we write.
        Log::shouldHaveReceived('warning', [\Mockery::pattern('/NOT where this agent is configured to write/'), \Mockery::any()]);
    }

    public function test_an_idempotency_hit_reports_the_hit_cards_own_board_and_lane(): void
    {
        // ⛔ THE SHARPEST INSTANCE. This card id came out of a tag SEARCH, so the
        // tool has resolved a card it did not create and never re-checked (a
        // Group-B resolution, card#7211) — and it used to answer the configured
        // board for it, on the one path where the card can be anywhere at all.
        Log::spy();
        Http::fake($this->archiveAxisFake(
            live: [['id' => 7]], archived: [], newId: 88,
            readBack: ['board_id' => 77, 'swimlane_id' => 5],   // the hit card is NOT on this agent's board
        ));

        $res = $this->callTool(['tool' => 'board_create_card', 'args' => ['title' => 't', 'idempotency_key' => 'k5']]);

        $res->assertStatus(200)
            ->assertJsonPath('result.idempotent_hit', true)
            ->assertJsonPath('result.card_id', 7)
            ->assertJsonPath('result.board_id', 77)             // observed, not the configured 10
            ->assertJsonPath('result.swimlane_id', 5)           // observed, not the configured 4
            ->assertJsonPath('result.placement_observed', true)
            // BOTH axes diverge here, so this is the pair's sharpest witness: the
            // four keys must carry four values, or "reports the observation" and
            // "reports the config" are indistinguishable in the payload.
            ->assertJsonPath('result.configured_board_id', 10)
            ->assertJsonPath('result.configured_swimlane_id', 4);
        Http::assertSent(fn ($r) => $r->method() === 'GET' && str_contains($r->url(), '/tasks/7.json'));
        Log::shouldHaveReceived('warning', [\Mockery::pattern('/NOT where this agent is configured to write/'), \Mockery::any()]);
    }

    public function test_a_card_in_no_lane_reports_a_null_lane_as_an_observation(): void
    {
        // The PRESENCE half of the null pair below: a card legitimately in no
        // swimlane is a real reading, and `placement_observed: true` is what says
        // so. Without this the null in the next test would be indistinguishable
        // from "the bridge could not look".
        Log::spy();
        Http::fake([
            '*/tasks.json' => Http::response(['data' => ['id' => 42]], 201),
            '*/tasks/*.json' => Http::response(['data' => ['id' => 42, 'board_id' => 10, 'swimlane_id' => null]]),
        ]);

        $res = $this->callTool(['tool' => 'board_create_card', 'args' => ['title' => 't']]);

        $res->assertStatus(200)
            ->assertJsonPath('result.swimlane_id', null)
            ->assertJsonPath('result.board_id', 10)
            ->assertJsonPath('result.placement_observed', true);
        // CONTROL for the two key-shape tests below: a PRESENT null is a reading,
        // so the unusable-axis warning must NOT fire here. Keyed on that message,
        // never on `warning` at large (the divergence warning DOES fire — a
        // lane-less card is not the configured lane 4).
        Log::shouldNotHaveReceived('warning', [\Mockery::pattern('/no usable board_id\/swimlane_id/'), \Mockery::any()]);
    }

    public function test_an_unreadable_card_reports_no_placement_and_still_answers_the_card_id(): void
    {
        // FAIL-SOFT, and the pairing that makes the nulls mean something: the
        // create has already landed, so the id is the answer worth keeping. What
        // must NEVER happen is the config value filling the gap — that is the
        // defect wearing a fallback.
        Log::spy();
        Http::fake([
            '*/tasks.json' => Http::response(['data' => ['id' => 42]], 201),
            '*/tasks/*.json' => Http::response('boom', 500),
        ]);

        $res = $this->callTool(['tool' => 'board_create_card', 'args' => ['title' => 't']]);

        $res->assertStatus(200)
            ->assertJsonPath('result.created', true)            // presence witness: the create still answers
            ->assertJsonPath('result.card_id', 42)
            ->assertJsonPath('result.board_id', null)
            ->assertJsonPath('result.swimlane_id', null)
            ->assertJsonPath('result.placement_observed', false)
            // ⭐ THE ARM THE CONFIGURED PAIR EXISTS FOR. With the observation lost,
            // these two are the ONLY thing the response can still say about scope
            // — and the caller has no other channel to its own configured board or
            // lane. They are on their own keys, so this is not the rejected
            // fallback: nothing here claims the card IS on board 10.
            ->assertJsonPath('result.configured_board_id', 10)
            ->assertJsonPath('result.configured_swimlane_id', 4);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/tasks.json'));
        Log::shouldHaveReceived('warning', [\Mockery::pattern('/could not be read back/'), \Mockery::any()]);
    }

    public function test_a_read_back_carrying_no_board_id_reports_no_placement(): void
    {
        // A 200 whose body answers nothing about placement is not an observation.
        // `board_id` is the anchor kanban returns on every task row, so a row
        // without one leaves BOTH ids unclaimed rather than reporting a null lane.
        Log::spy();
        Http::fake([
            '*/tasks.json' => Http::response(['data' => ['id' => 42]], 201),
            '*/tasks/*.json' => Http::response(['data' => ['id' => 42, 'swimlane_id' => 4]]),
        ]);

        $res = $this->callTool(['tool' => 'board_create_card', 'args' => ['title' => 't']]);

        $res->assertStatus(200)
            ->assertJsonPath('result.card_id', 42)
            ->assertJsonPath('result.board_id', null)
            ->assertJsonPath('result.swimlane_id', null)        // NOT the 4 the row happens to carry
            ->assertJsonPath('result.placement_observed', false);
        // ⭐ WITHOUT THIS the test cannot tell its own branch from the throw
        // branch: an unreadable read-back yields a byte-identical body. The log
        // is the only channel that says WHICH way the placement was lost, and
        // `unusable` says which axis — so this also separates it from the two
        // swimlane-key cases below, whose bodies are identical again.
        Log::shouldHaveReceived('warning', [
            \Mockery::pattern('/no usable board_id\/swimlane_id/'),
            \Mockery::on(fn ($ctx) => is_array($ctx) && ($ctx['unusable'] ?? null) === 'board_id'),
        ]);
        Log::shouldNotHaveReceived('warning', [\Mockery::pattern('/could not be read back/'), \Mockery::any()]);
    }

    /**
     * ⛔ THE SWIMLANE AXIS WAS NOT OBSERVATION-GATED. `is_numeric($card['swimlane_id']
     * ?? null)` cannot tell an ABSENT key from a present null, so a read-back
     * carrying no `swimlane_id` at all reported `swimlane_id: null` with
     * `placement_observed: true` — i.e. it told the calling agent, as an
     * observation it may act on, that its card is in NO lane, for a card sitting
     * in one. The two halves of one change disagreed: the same branch's first
     * commit had already written `docs/kanban-integration-contract.md` §2 the
     * other way (*"or the tool reports an unobserved placement"*) while shipping
     * code that did the opposite. ⛔ That doc sentence is NOT prior authority —
     * it is an hour older than this test, not older than the defect, and
     * `git show origin/dev:docs/kanban-integration-contract.md | grep -c
     * "unobserved placement"` returns 0.
     */
    public function test_a_read_back_with_no_swimlane_key_reports_no_placement(): void
    {
        Log::spy();
        Http::fake([
            '*/tasks.json' => Http::response(['data' => ['id' => 42]], 201),
            '*/tasks/*.json' => Http::response(['data' => ['id' => 42, 'board_id' => 10]]),   // no swimlane_id KEY at all
        ]);

        $res = $this->callTool(['tool' => 'board_create_card', 'args' => ['title' => 't']]);

        $res->assertStatus(200)
            ->assertJsonPath('result.created', true)            // presence witness: the create still answers
            ->assertJsonPath('result.card_id', 42)
            ->assertJsonPath('result.board_id', null)           // unobserved on BOTH axes, exactly as a missing board_id is
            ->assertJsonPath('result.swimlane_id', null)
            ->assertJsonPath('result.placement_observed', false);
        Log::shouldHaveReceived('warning', [
            \Mockery::pattern('/no usable board_id\/swimlane_id/'),
            \Mockery::on(fn ($ctx) => is_array($ctx) && ($ctx['unusable'] ?? null) === 'swimlane_id'),
        ]);
    }

    /**
     * The other half of the same gate: a PRESENT key whose value is not a number
     * (and not null) is also a body that answered nothing about the lane — the
     * `board_id` axis has always treated a non-numeric that way.
     */
    public function test_a_read_back_whose_swimlane_id_is_not_a_number_reports_no_placement(): void
    {
        Log::spy();
        Http::fake([
            '*/tasks.json' => Http::response(['data' => ['id' => 42]], 201),
            '*/tasks/*.json' => Http::response(['data' => ['id' => 42, 'board_id' => 10, 'swimlane_id' => 'none']]),
        ]);

        $res = $this->callTool(['tool' => 'board_create_card', 'args' => ['title' => 't']]);

        $res->assertStatus(200)
            ->assertJsonPath('result.created', true)
            ->assertJsonPath('result.card_id', 42)
            ->assertJsonPath('result.board_id', null)
            ->assertJsonPath('result.swimlane_id', null)
            ->assertJsonPath('result.placement_observed', false);
        Log::shouldHaveReceived('warning', [
            \Mockery::pattern('/no usable board_id\/swimlane_id/'),
            \Mockery::on(fn ($ctx) => is_array($ctx) && ($ctx['unusable'] ?? null) === 'swimlane_id'),
        ]);
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

    // ─── board_my_cards: include_description (DL-245) ────────────────────────

    /**
     * One row carrying a description, plus the stage names it groups under. The
     * body is the same in every test below so each one differs only in the
     * argument / cap under test.
     *
     * @param  array<string, mixed>  $rowOverrides
     */
    private function fakeOneCardWithDescription(string $description, array $rowOverrides = []): void
    {
        Http::fake([
            '*/boards/10/preload.json' => Http::response(['data' => ['workflows' => [
                ['stages' => [['id' => 50, 'name' => 'Backlog', 'position' => 1]]],
            ]]]),
            '*/tasks/search.json*' => Http::response(['data' => [
                array_merge([
                    'id' => 1, 'name' => 'mine', 'workflow_stage_id' => 50, 'swimlane_id' => 4,
                    'tags' => [], 'payload' => [], 'updated_at' => '2026-07-20',
                    'description' => $description,
                ], $rowOverrides),
            ]]),
        ]);
    }

    public function test_my_cards_omits_the_description_keys_by_default(): void
    {
        // The byte-identical guarantee: without the argument the card shape is the
        // DL-217 one — the keys are ABSENT, not present-and-null.
        $this->fakeOneCardWithDescription('the scope nobody asked for');

        $card = $this->callTool(['tool' => 'board_my_cards'])
            ->assertStatus(200)
            ->json('result.cards_by_stage.Backlog.0');

        $this->assertArrayNotHasKey('description', $card);
        $this->assertArrayNotHasKey('description_truncated', $card);
        $this->assertSame(['id', 'name', 'stage', 'tags', 'dl_number', 'pr_number', 'updated_at'], array_keys($card));
    }

    public function test_my_cards_include_description_false_is_the_default_shape(): void
    {
        $this->fakeOneCardWithDescription('the scope nobody asked for');

        $card = $this->callTool(['tool' => 'board_my_cards', 'args' => ['include_description' => false]])
            ->assertStatus(200)
            ->json('result.cards_by_stage.Backlog.0');

        $this->assertArrayNotHasKey('description', $card);
        $this->assertArrayNotHasKey('description_truncated', $card);
    }

    public function test_my_cards_returns_the_description_when_opted_in(): void
    {
        $this->fakeOneCardWithDescription('## Scope\nimplement the thing');

        $card = $this->callTool(['tool' => 'board_my_cards', 'args' => ['include_description' => true]])
            ->assertStatus(200)
            ->json('result.cards_by_stage.Backlog.0');

        $this->assertSame('## Scope\nimplement the thing', $card['description']);
        $this->assertFalse($card['description_truncated']);
    }

    public function test_my_cards_reports_a_missing_description_as_null_not_truncated(): void
    {
        // A card with no body must not read as "truncated to nothing".
        $this->fakeOneCardWithDescription('', ['description' => null]);

        $card = $this->callTool(['tool' => 'board_my_cards', 'args' => ['include_description' => true]])
            ->assertStatus(200)
            ->json('result.cards_by_stage.Backlog.0');

        $this->assertNull($card['description']);
        $this->assertFalse($card['description_truncated']);
    }

    public function test_my_cards_cuts_a_body_past_the_configured_cap_and_flags_it(): void
    {
        $this->writeAgent('me', $this->token, [
            'board_id' => 10, 'swimlane_id' => 4, 'create_stage_id' => 55,
        ], "  description_max_bytes: 8\n");
        $this->fakeOneCardWithDescription('0123456789abcdef');

        $card = $this->callTool(['tool' => 'board_my_cards', 'args' => ['include_description' => true]])
            ->assertStatus(200)
            ->json('result.cards_by_stage.Backlog.0');

        $this->assertSame('01234567', $card['description']);
        $this->assertTrue($card['description_truncated']);
    }

    public function test_my_cards_never_cuts_a_multibyte_character_in_half(): void
    {
        // The cap is a BYTE budget, so a naive substr would split the 4-byte emoji
        // that straddles byte 8 and emit invalid UTF-8 — which fails json_encode for
        // the WHOLE response, blanking the caller's entire board window rather than
        // trimming one card. mb_strcut drops the whole character instead.
        $this->writeAgent('me', $this->token, [
            'board_id' => 10, 'swimlane_id' => 4, 'create_stage_id' => 55,
        ], "  description_max_bytes: 8\n");
        $this->fakeOneCardWithDescription('012345🚀tail');

        $res = $this->callTool(['tool' => 'board_my_cards', 'args' => ['include_description' => true]]);

        $res->assertStatus(200);
        $card = $res->json('result.cards_by_stage.Backlog.0');
        $this->assertSame('012345', $card['description']);
        $this->assertTrue($card['description_truncated']);
        // The response as a whole is well-formed UTF-8 — the failure this guards is
        // an encode error, which would surface here and nowhere in the card itself.
        $this->assertTrue(mb_check_encoding((string) $res->getContent(), 'UTF-8'));
    }

    public function test_my_cards_applies_the_opt_in_to_coord_cards_too(): void
    {
        $this->writeAgent('me', $this->token, [
            'board_id' => 10, 'swimlane_id' => 4, 'create_stage_id' => 55,
        ], "  coord_board_id: 12\n  address_tags:\n    - repo:me\n");
        Http::fake([
            '*/boards/10/preload.json' => Http::response(['data' => ['workflows' => [
                ['stages' => [['id' => 50, 'name' => 'Backlog', 'position' => 1]]],
            ]]]),
            '*/boards/12/preload.json' => Http::response(['data' => ['workflows' => [
                ['stages' => [['id' => 70, 'name' => 'Inbox', 'position' => 1]]],
            ]]]),
            '*/tasks/search.json*' => Http::response(['data' => [
                ['id' => 9, 'name' => 'addressed to me', 'workflow_stage_id' => 70, 'swimlane_id' => 4,
                    'tags' => ['repo:me'], 'payload' => [], 'updated_at' => '2026-07-20',
                    'description' => 'coord scope'],
            ]]),
        ]);

        $res = $this->callTool(['tool' => 'board_my_cards', 'args' => ['include_description' => true]]);

        $res->assertStatus(200)->assertJsonPath('result.coord_cards.0.description', 'coord scope');
        $this->assertFalse($res->json('result.coord_cards.0.description_truncated'));
    }

    /**
     * A truthy non-bool must be REFUSED, not coerced — coercion would silently
     * switch on the expensive projection the opt-in exists to gate.
     *
     * @return array<string, array{0: mixed}>
     */
    public static function nonBooleanIncludeDescription(): array
    {
        return ['string true' => ['true'], 'int 1' => [1], 'array' => [[]], 'string yes' => ['yes']];
    }

    #[DataProvider('nonBooleanIncludeDescription')]
    public function test_my_cards_refuses_a_non_boolean_include_description(mixed $value): void
    {
        Http::fake();   // the refusal must precede every board read

        $this->callTool(['tool' => 'board_my_cards', 'args' => ['include_description' => $value]])
            ->assertStatus(422);
        Http::assertNothingSent();
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
