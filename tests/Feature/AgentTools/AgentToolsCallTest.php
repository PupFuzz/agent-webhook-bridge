<?php

namespace Tests\Feature\AgentTools;

use App\Bridge\Tools\CallProvenance;
use App\Bridge\Tools\ServingProcessEnvironment;
use App\Models\BoardToolsClientCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeServingProcessEnvironment;
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
    // card#7756: a SUCCESSFUL dispatch now writes one durable row (ClientHalfLedger), so
    // this class touches the database transitively where it did not before. Without the
    // trait those rows COMMIT on the MariaDB legs and outlive the test — invisible on
    // SQLite `:memory:`, where the insert finds no table and the ledger swallows it. The
    // isolation guard in Tests\TestCase names both halves.
    use RefreshDatabase;

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

    // ─── client-half provenance (card#7836 / DL-316) ──────────────────────────

    /**
     * ⭐ THIS DOOR MUST NEVER STAMP THE STRONG PROVENANCE, AND THE REASON IS NOT THAT IT
     * FORGOT TO MEASURE. `LoopbackOnly` pins the peer to 127.0.0.1 for the seat and for
     * `bridge:check --probe-tools` alike, BY CONSTRUCTION, so there is nothing here that
     * separates them and any "measurement" would be about the SERVER process rather than the
     * caller. That process can carry an ssh session environment perfectly legitimately —
     * `php artisan serve` started inside an ssh session is the live case — so an
     * implementation that read the environment here would mint the stronger `bridge:check`
     * verdict out of a variable that says nothing about who called.
     *
     * ⛔ card#7836's controlling-terminal predicate makes this MORE load-bearing, not less: a
     * PHP-FPM pool or a `php artisan serve` under `nohup` has NO controlling terminal, so on
     * a host where the serving process inherited `SSH_CONNECTION` the whole predicate would
     * be satisfied by an http request that came from anywhere at all.
     *
     * The process is staged in the SHAPE that makes the ssh door say `sshd`, so this reds
     * against exactly that implementation and passes only because the door states a constant.
     */
    public function test_the_http_door_stamps_not_sshd_even_inside_an_ssh_session(): void
    {
        Http::fake([
            '*/tasks.json' => Http::response(['data' => ['id' => 1]], 201),
            '*/tasks/*.json' => Http::response(['data' => ['id' => 1, 'board_id' => 10, 'swimlane_id' => 4]]),
        ]);
        // Both halves of the shape are staged: the real variable, for an implementation that
        // read `getenv()` directly, AND the bound seam in the PROVEN configuration, for one
        // that went through CallProvenance. Either way this arm reds against a door that
        // measures, and passes only because this one states a constant.
        [$connection, $tty] = [getenv('SSH_CONNECTION'), getenv('SSH_TTY')];
        putenv('SSH_CONNECTION=203.0.113.9 53210 198.51.100.4 22');
        putenv('SSH_TTY');
        $this->app->instance(ServingProcessEnvironment::class, new FakeServingProcessEnvironment(sshSession: true, controllingTerminal: false, ptyMarker: false));

        try {
            $this->callTool(['tool' => 'board_create_card', 'args' => ['title' => 'x']])->assertStatus(200);
        } finally {
            is_string($connection) ? putenv('SSH_CONNECTION='.$connection) : putenv('SSH_CONNECTION');
            is_string($tty) ? putenv('SSH_TTY='.$tty) : putenv('SSH_TTY');
        }

        $row = BoardToolsClientCall::query()->where('agent', 'me')->sole();
        $this->assertSame('http', $row->transport);
        $this->assertSame(CallProvenance::NotSshd, $row->call_provenance);
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
        // Both stubs are load-bearing, and each reds differently — measured, card#7300:
        // drop '*/tasks/*.json' and the placement_observed pin below fails (the create arm
        // ALSO reads the card back, card#7225); drop '*/tasks.json' and this returns 500,
        // because the create call itself does NOT swallow — the StrayRequestException
        // propagates out of the controller. Before DL-303 neither was true: Http::fake()
        // does not block a request no stub answers, so a missing one went to the real
        // network. See CLAUDE_TESTING.md.
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
     * ⛔ THE CREATE TOOL'S REFUSAL TEXT IS A SHIPPED SURFACE, AND MOVING THE GUARD TO A
     * SHARED OWNER MUST NOT MOVE IT (card#8378). Extracting `CallerTagPolicy` reworded
     * this one message while the DL entry and the PR body both claimed the create tool's
     * messages were unchanged — a claim nothing checked. The exact string is pinned here
     * so the next extraction is a refactor rather than a silent contract edit; changing
     * it deliberately means changing this line and saying so.
     */
    public function test_the_create_tools_reserved_tag_message_is_exactly_what_it_shipped(): void
    {
        Http::fake(['*/tasks.json' => Http::response(['data' => ['id' => 1]], 201)]);

        $res = $this->callTool(['tool' => 'board_create_card', 'args' => ['title' => 't', 'tags' => ['triaged']]]);

        $this->assertSame(
            'board_create_card: the tag `triaged` is reserved — tool-created cards are born untriaged by design (they surface to the triage pass)',
            (string) $res->json('error'),
        );
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

    /**
     * card#8523 / DL-340 — the pin reaches the BOARD-TOOLS collapse, because the consult
     * lives in the shared kernel and not in its three callers (canon #5). This surface is
     * outside the DL-009 mapped-board regime entirely, which is the reason DL-335 gave for
     * NOT widening the pin into the kernel; the operator ruled the other way, and one
     * primitive is what makes the ruling reach a caller nobody would think to edit.
     *
     * ⛔ THE ROWS ARE THE POINT. This path used to hand the kernel `array_fill_keys($live,
     * [])` — ids with empty bodies — so a pin consult inside the kernel would have answered
     * "not pinned" for every row by construction: a check that cannot fire (canon #9). The
     * tool now reads its rows through `cardRowsByTag`, the row-returning twin of the same
     * one search, so this test would still pass with the consult in place and the rows
     * un-read — which is exactly why the unpinned control below is on the SAME fixture.
     */
    public function test_a_pinned_raced_duplicate_is_not_archived_by_the_collapse(): void
    {
        Http::fake($this->archiveAxisFake(
            live: [],
            archived: [],
            newId: 9,
            postCreate: [['id' => 8], ['id' => 9, 'block_reason' => 'a human parked this one']],
        ));

        $res = $this->callTool(['tool' => 'board_create_card', 'args' => ['title' => 't', 'idempotency_key' => 'k2p']]);

        $res->assertStatus(200)
            ->assertJsonPath('result.created', true)
            ->assertJsonPath('result.card_id', 8);   // the survivor is unchanged by the refusal
        Http::assertNotSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), '/tasks/9.json'));
    }

    /**
     * The control for the leg above, on the SAME fixture minus the pin: `test_idempotency
     * _raced_duplicate_is_collapsed` covers the block_reason-free shape, and this one pins
     * that the pin is what did it by carrying a NON-pin field in the same position — an
     * unrecognised tag, which PinGuard reads and rejects.
     */
    public function test_the_same_raced_duplicate_with_a_non_pin_tag_is_archived(): void
    {
        Http::fake($this->archiveAxisFake(
            live: [],
            archived: [],
            newId: 9,
            postCreate: [['id' => 8], ['id' => 9, 'block_reason' => '', 'tags' => ['dependencies']]],
        ));

        $res = $this->callTool(['tool' => 'board_create_card', 'args' => ['title' => 't', 'idempotency_key' => 'k2c']]);

        $res->assertStatus(200)->assertJsonPath('result.card_id', 8);
        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), '/tasks/9.json')
            && ($r['_action'] ?? null) === 'archive');
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

    // ─── board_my_cards: the board axis is READ, not restated (DL-302) ───────

    /**
     * One own-lane row, on the board named by $rowBoard, plus the stage names it
     * groups under. `board_id` is what the DL-302 reading is taken from — kanban
     * puts it on every search row, so no extra request pays for it.
     *
     * @param  array<string, mixed>  $rowBoard  merged into the row (a key ABSENT here is a row that carries none)
     * @param  list<array<string, mixed>>  $extraRows
     */
    private function fakeOwnLaneRows(array $rowBoard, array $extraRows = []): void
    {
        Http::fake([
            '*/boards/10/preload.json' => Http::response(['data' => ['workflows' => [
                ['stages' => [['id' => 50, 'name' => 'Backlog', 'position' => 1]]],
            ]]]),
            '*/tasks/search.json*' => Http::response(['data' => array_merge([
                array_merge([
                    'id' => 1, 'name' => 'mine', 'workflow_stage_id' => 50, 'swimlane_id' => 4,
                    'tags' => [], 'payload' => [], 'updated_at' => '2026-07-20',
                ], $rowBoard),
            ], $extraRows)]),
        ]);
    }

    public function test_my_cards_reports_the_board_the_returned_rows_are_actually_on(): void
    {
        $this->fakeOwnLaneRows(['board_id' => 10]);

        $this->callTool(['tool' => 'board_my_cards'])
            ->assertStatus(200)
            ->assertJsonPath('result.board_id', 10)
            ->assertJsonPath('result.board_observed', true)
            ->assertJsonPath('result.configured_board_id', 10);
    }

    public function test_my_cards_reports_a_foreign_board_as_the_rows_own_never_the_configured_one(): void
    {
        // THE DEFECT (card#7295): the response used to state the CONFIGURED board for
        // a row set nothing had re-checked, so a window of board-20 rows read as
        // board 10 — as fact, to a caller with no way to tell. The row is kept (this
        // change reports, it does not drop: dropping/refusing is a separate,
        // ask-gated question) and the board it is actually on is what is reported.
        $this->fakeOwnLaneRows(['board_id' => 20]);

        $this->callTool(['tool' => 'board_my_cards'])
            ->assertStatus(200)
            ->assertJsonPath('result.board_id', 20)
            ->assertJsonPath('result.board_observed', true)
            ->assertJsonPath('result.configured_board_id', 10)
            ->assertJsonPath('result.cards_by_stage.Backlog.0.id', 1);
    }

    /**
     * The board axis has NO legitimate null — kanban's `tasks.board_id` is a
     * non-nullable FK, so a card is always on exactly one board. Absent key,
     * present null and non-numeric therefore all mean the same thing: the row
     * answered nothing about its board. (The LANE axis is the opposite, which is
     * why its sibling has to tell a present null from a missing key — do not read
     * that discrimination across to here.)
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function unreadableRowBoards(): array
    {
        return [
            'key absent' => [[]],
            'present null' => [['board_id' => null]],
            'non-numeric' => [['board_id' => '10abc']],
            'non-scalar' => [['board_id' => ['10']]],
        ];
    }

    /**
     * @param  array<string, mixed>  $rowBoard
     */
    #[DataProvider('unreadableRowBoards')]
    public function test_my_cards_reports_no_board_when_a_row_does_not_carry_a_readable_one(array $rowBoard): void
    {
        $this->fakeOwnLaneRows($rowBoard);

        $this->callTool(['tool' => 'board_my_cards'])
            ->assertStatus(200)
            ->assertJsonPath('result.board_id', null)
            ->assertJsonPath('result.board_observed', false)
            ->assertJsonPath('result.configured_board_id', 10)
            // The cards are still returned — an unreadable board unobserves the
            // REPORT, it does not drop the window.
            ->assertJsonPath('result.cards_by_stage.Backlog.0.id', 1);
    }

    public function test_my_cards_reports_no_board_when_the_rows_span_more_than_one(): void
    {
        // No honest single value exists for a mixed set: reporting either board
        // would hide the other, and reporting the configured one is the defect.
        $this->fakeOwnLaneRows(['board_id' => 10], [
            ['id' => 2, 'name' => 'elsewhere', 'workflow_stage_id' => 50, 'swimlane_id' => 4,
                'tags' => [], 'payload' => [], 'updated_at' => '2026-07-20', 'board_id' => 20],
        ]);

        $this->callTool(['tool' => 'board_my_cards'])
            ->assertStatus(200)
            ->assertJsonPath('result.board_id', null)
            ->assertJsonPath('result.board_observed', false)
            ->assertJsonPath('result.configured_board_id', 10)
            ->assertJsonPath('result.cards_by_stage.Backlog.1.id', 2);
    }

    /** No rows at all — the commonest window, and the one every board key is null on. */
    private function fakeEmptyWindow(): void
    {
        Http::fake([
            '*/boards/10/preload.json' => Http::response(['data' => ['workflows' => [
                ['stages' => [['id' => 50, 'name' => 'Backlog', 'position' => 1]]],
            ]]]),
            '*/tasks/search.json*' => Http::response(['data' => []]),
        ]);
    }

    public function test_my_cards_reports_no_board_for_an_empty_window(): void
    {
        // The common case, and the one the old code was most wrong about: zero rows
        // read means zero boards read. `board_observed: false` — never the config
        // value dressed as a reading.
        $this->fakeEmptyWindow();

        $this->callTool(['tool' => 'board_my_cards'])
            ->assertStatus(200)
            ->assertJsonPath('result.board_id', null)
            ->assertJsonPath('result.board_observed', false)
            ->assertJsonPath('result.configured_board_id', 10);
    }

    /**
     * A configured shared lane, answered PER REQUEST: the own leg and the shared leg
     * are different searches, and one wildcard stub for both cannot tell them apart.
     * The shared row lands on $sharedRowBoard so a caller can put it on a foreign
     * board without moving the own leg.
     */
    private function fakeSharedLane(int $sharedRowBoard): void
    {
        $this->writeAgent('me', $this->token, [
            'board_id' => 10, 'swimlane_id' => 4, 'create_stage_id' => 55,
        ], "  shared_swimlane_id: 9\n");
        Http::fake([
            '*/boards/10/preload.json' => Http::response(['data' => ['workflows' => [
                ['stages' => [['id' => 50, 'name' => 'Backlog', 'position' => 1]]],
            ]]]),
            '*/tasks/search.json*' => function ($request) use ($sharedRowBoard) {
                $url = urldecode($request->url());

                return Http::response(['data' => [str_contains($url, 'swimlane_id=9')
                    ? ['id' => 2, 'name' => 'shared', 'workflow_stage_id' => 50, 'swimlane_id' => 9,
                        'tags' => [], 'payload' => [], 'updated_at' => '2026-07-20', 'board_id' => $sharedRowBoard]
                    : ['id' => 1, 'name' => 'mine', 'workflow_stage_id' => 50, 'swimlane_id' => 4,
                        'tags' => [], 'payload' => [], 'updated_at' => '2026-07-20', 'board_id' => 10],
                ]]);
            },
        ]);
    }

    public function test_my_cards_unobserves_the_window_when_the_shared_lane_is_on_another_board(): void
    {
        // The top-level board covers the WHOLE own+shared window (`shared_swimlane`
        // states a lane and inherits this board), so a foreign shared row has to
        // move it. Reporting board 10 here would hide the leak inside the very key
        // a caller reads to locate its cards.
        $this->fakeSharedLane(sharedRowBoard: 20);

        $this->callTool(['tool' => 'board_my_cards'])
            ->assertStatus(200)
            ->assertJsonPath('result.board_id', null)
            ->assertJsonPath('result.board_observed', false)
            ->assertJsonPath('result.cards_by_stage.Backlog.0.id', 1)
            ->assertJsonPath('result.shared_swimlane.cards_by_stage.Backlog.0.id', 2);
    }

    // ─── board_my_cards: the coord block carries its own board (DL-302) ──────

    /**
     * Own-lane rows on board 10 and coord rows on $coordRowBoard, answered per
     * REQUEST rather than by a shared wildcard: the two legs are different
     * searches against different boards, and a single stub for both is how a coord
     * row ends up counted in the own window (they cannot be told apart afterwards).
     *
     * @param  array<string, mixed>  $coordRowBoard
     */
    private function fakeCoordLeg(array $coordRowBoard): void
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
            '*/tasks/search.json*' => function ($request) use ($coordRowBoard) {
                $url = urldecode($request->url());
                if (str_contains($url, 'tags:"repo:me"')) {
                    return Http::response(['data' => [array_merge([
                        'id' => 9, 'name' => 'addressed to me', 'workflow_stage_id' => 70, 'swimlane_id' => 4,
                        'tags' => ['repo:me'], 'payload' => [], 'updated_at' => '2026-07-20',
                    ], $coordRowBoard)]]);
                }

                return Http::response(['data' => [
                    ['id' => 1, 'name' => 'mine', 'workflow_stage_id' => 50, 'swimlane_id' => 4,
                        'tags' => [], 'payload' => [], 'updated_at' => '2026-07-20', 'board_id' => 10],
                ]]);
            },
        ]);
    }

    public function test_my_cards_reports_the_board_the_coord_cards_are_actually_on(): void
    {
        // The missing-key half of card#7295: the coord block carried NO board key at
        // all, so a second card list from a DIFFERENT board sat under a top-level
        // board_id that does not describe it.
        $this->fakeCoordLeg(['board_id' => 12]);

        $this->callTool(['tool' => 'board_my_cards'])
            ->assertStatus(200)
            ->assertJsonPath('result.board_id', 10)
            ->assertJsonPath('result.coord_board_id', 12)
            ->assertJsonPath('result.coord_board_observed', true)
            ->assertJsonPath('result.configured_coord_board_id', 12)
            ->assertJsonPath('result.coord_cards.0.id', 9);
    }

    public function test_my_cards_reports_a_foreign_coord_board_as_the_rows_own(): void
    {
        $this->fakeCoordLeg(['board_id' => 99]);

        $this->callTool(['tool' => 'board_my_cards'])
            ->assertStatus(200)
            ->assertJsonPath('result.board_id', 10)
            ->assertJsonPath('result.coord_board_id', 99)
            ->assertJsonPath('result.coord_board_observed', true)
            ->assertJsonPath('result.configured_coord_board_id', 12)
            ->assertJsonPath('result.coord_cards.0.id', 9);
    }

    public function test_my_cards_reports_no_coord_board_when_the_coord_rows_carry_none(): void
    {
        $this->fakeCoordLeg([]);

        $this->callTool(['tool' => 'board_my_cards'])
            ->assertStatus(200)
            ->assertJsonPath('result.board_id', 10)
            ->assertJsonPath('result.board_observed', true)
            ->assertJsonPath('result.coord_board_id', null)
            ->assertJsonPath('result.coord_board_observed', false)
            ->assertJsonPath('result.configured_coord_board_id', 12);
    }

    public function test_my_cards_carries_no_coord_board_keys_when_the_coord_leg_is_not_configured(): void
    {
        // The keys belong to the coord block and appear on exactly the condition it
        // does — an install with no coord leg must not grow a null coord board it
        // never looked for.
        $this->fakeOwnLaneRows(['board_id' => 10]);

        $result = $this->callTool(['tool' => 'board_my_cards'])->assertStatus(200)->json('result');

        // The presence witness FIRST: four assertArrayNotHasKey alone are satisfied by
        // an empty `result`, so a call that returned nothing at all would score as a
        // clean absence. This pins that the tool ran and answered the own-lane window.
        $this->assertArrayHasKey('board_id', $result);
        $this->assertSame(10, $result['board_id']);
        $this->assertTrue($result['board_observed']);
        $this->assertArrayNotHasKey('coord_board_id', $result);
        $this->assertArrayNotHasKey('coord_board_observed', $result);
        $this->assertArrayNotHasKey('configured_coord_board_id', $result);
        $this->assertArrayNotHasKey('coord_cards', $result);
    }

    // ─── board_my_cards: the identity echo is UNCONDITIONAL (card#7325, DL-304) ──

    /**
     * Every arm of the response literal, named so a new one has to be added HERE to be
     * covered — the point of the witness is the enumeration, not the count.
     *
     * @return array<string, array{0: string}>
     */
    public static function everyResponseArm(): array
    {
        return [
            'an empty window' => ['empty'],
            'rows on the configured board' => ['own'],
            'rows on a FOREIGN board' => ['foreign'],
            'rows with no readable board' => ['unreadable'],
            'a configured shared lane' => ['shared'],
            'a configured coord block' => ['coord'],
        ];
    }

    /**
     * ⛔ THE FALLBACK IN `BoardToolsScopeHeader` IS UNREACHABLE FROM THIS TOOL, AND
     * THIS IS THE GUARD FOR THAT, not a reading of the file (card#7325, DL-304).
     *
     * The two live probes read a scope header under `configured_board_id` and fall back
     * to the legacy `board_id` spelling when it is absent. On a DL-302-or-later
     * responder `board_id` carries where the returned ROWS are, so if this tool ever
     * emitted the header conditionally, a row observation would silently become an
     * identity claim and the probes' `IDENTITY MISMATCH` verdict would be computed from
     * the wrong quantity with nothing red anywhere. The reason that cannot happen is
     * that `call()` emits the key in the BASE literal, on no condition at all —
     * a property of the code that was true, asserted nowhere, and stated in prose.
     *
     * ⚠ It asserts PRESENCE, and deliberately also the CALL: an arm that returned
     * nothing at all would satisfy a bare `assertArrayHasKey` on nothing, so each case
     * pins that the window itself was answered. The value is asserted too — a key
     * present but null is not an identity echo either.
     */
    #[DataProvider('everyResponseArm')]
    public function test_my_cards_emits_the_configured_board_header_on_every_response_arm(string $arm): void
    {
        switch ($arm) {
            case 'empty':
                $this->fakeEmptyWindow();
                break;
            case 'own':
                $this->fakeOwnLaneRows(['board_id' => 10]);
                break;
            case 'foreign':
                $this->fakeOwnLaneRows(['board_id' => 20]);
                break;
            case 'unreadable':
                $this->fakeOwnLaneRows([]);
                break;
            case 'shared':
                $this->fakeSharedLane(sharedRowBoard: 10);
                break;
            case 'coord':
                $this->fakeCoordLeg(['board_id' => 12]);
                break;
        }

        $result = $this->callTool(['tool' => 'board_my_cards'])->assertStatus(200)->json('result');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('cards_by_stage', $result);   // the window was answered
        $this->assertArrayHasKey('configured_board_id', $result);
        $this->assertSame(10, $result['configured_board_id']);
    }

    /**
     * The coord block's own echo is unconditional ON ITS OWN CONDITION — it appears with
     * `coord_cards` and never without it. Same shape as above and a separate assertion:
     * the two echoes sit in different literals and a change can drop one alone.
     */
    public function test_my_cards_emits_the_configured_coord_board_header_whenever_the_coord_block_appears(): void
    {
        $this->fakeCoordLeg(['board_id' => 12]);

        $result = $this->callTool(['tool' => 'board_my_cards'])->assertStatus(200)->json('result');

        $this->assertArrayHasKey('coord_cards', $result);
        $this->assertArrayHasKey('configured_coord_board_id', $result);
        $this->assertSame(12, $result['configured_coord_board_id']);
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

    // ─── board_correct_card: ownership scoping + the refusal table (card#8378) ─

    /**
     * The whole wire surface of a `board_correct_card` call: the board-scoped
     * ownership lookup (live and archived are the two sides of kanban's archive
     * SWITCH — DL-296 — and are told apart by the `archived=1` parameter), and the
     * PATCH.
     *
     * $patchStatus is what the BOARD answers the write with, so a test can stage a
     * 401/403/404/422 without touching the read; $patchBody is that answer's BODY, which
     * the 422 arm needs so a test can assert the seat never sees it.
     *
     * @param  list<array<string, mixed>>  $live
     * @param  list<array<string, mixed>>  $archived
     */
    private function correctFake(array $live, array $archived = [], int $patchStatus = 200, ?int $lookupStatus = null, string $patchBody = 'nope'): \Closure
    {
        return function ($request) use ($live, $archived, $patchStatus, $lookupStatus, $patchBody) {
            $url = urldecode($request->url());
            if (str_contains($url, '/tasks/search.json')) {
                if ($lookupStatus !== null) {
                    return Http::response('nope', $lookupStatus);
                }

                return Http::response(['data' => str_contains($url, 'archived=1') ? $archived : $live]);
            }

            if ($patchStatus !== 200) {
                return Http::response($patchBody, $patchStatus);
            }

            return Http::response(['data' => ['id' => 42]]);
        };
    }

    /**
     * A row for card 42 on this agent's board, minted by this agent unless
     * $overrides says otherwise.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function ownCardRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 42, 'board_id' => 10, 'swimlane_id' => 4, 'name' => 'wrong title',
            'tags' => ['created-by:me', 'priority:high'],
        ], $overrides);
    }

    /** @return array<string, mixed>|null the decoded body of the PATCH, or null if none was sent */
    private function sentPatchBody(): ?array
    {
        $body = null;
        Http::recorded(function ($request) use (&$body) {
            if ($request->method() === 'PATCH') {
                $decoded = json_decode((string) $request->body(), true);
                $body = is_array($decoded) ? $decoded : null;
            }

            return false;
        });

        return $body;
    }

    public function test_correct_writes_the_named_fields_on_a_card_this_agent_filed(): void
    {
        Http::fake($this->correctFake(live: [$this->ownCardRow()]));

        $res = $this->callTool(['tool' => 'board_correct_card', 'args' => [
            'card_id' => 42, 'name' => 'corrected title', 'description' => 'corrected body',
        ]]);

        $res->assertStatus(200)
            ->assertJsonPath('result.corrected', true)
            ->assertJsonPath('result.card_id', 42)
            ->assertJsonPath('result.board_id', 10)
            ->assertJsonPath('result.fields', ['name', 'description']);
        // The ownership lookup is BOARD-SCOPED and rides inside `q=` (card#8375/DL-323):
        // the id is caller-supplied against a GLOBAL id space, so it is established on
        // this agent's board before anything reads or writes the card.
        Http::assertSent(function ($r) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $query);

            return $r->method() === 'GET' && str_contains($r->url(), '/tasks/search.json')
                && ($query['q'] ?? null) === 'board_id=10 id=42';
        });
        // `getCard()` is never called — the unscoped `GET /tasks/{id}.json` is exactly
        // what DL-323 records as the defect for an author-supplied id.
        Http::assertNotSent(fn ($r) => $r->method() === 'GET' && ! str_contains($r->url(), 'search.json'));
        $this->assertSame(['name' => 'corrected title', 'description' => 'corrected body'], $this->sentPatchBody());
        // The live side answered, so the archived probe never runs on a successful call.
        Http::assertNotSent(fn ($r) => str_contains(urldecode($r->url()), 'archived=1'));
    }

    public function test_correct_preserves_every_bridge_stamped_tag_when_the_caller_replaces_the_tag_list(): void
    {
        // kanban replaces `tags` WHOLESALE, so a tag not re-sent is a tag deleted. The
        // caller may not supply any of these four, so it could not restore them either:
        // dropping `created-by:` would lock the seat out of its own card, dropping
        // `idem:` re-opens duplicate minting under that key, and dropping
        // `triaged`/`type:` undoes the triage pass.
        Http::fake($this->correctFake(live: [$this->ownCardRow([
            'tags' => ['created-by:me', 'idem:me:k1', 'type:feature', 'triaged', 'stale-caller-tag'],
        ])]));

        $res = $this->callTool(['tool' => 'board_correct_card', 'args' => [
            'card_id' => 42, 'tags' => ['fresh-caller-tag'],
        ]]);

        $res->assertStatus(200)->assertJsonPath('result.fields', ['tags']);
        $this->assertSame(
            ['tags' => ['fresh-caller-tag', 'created-by:me', 'idem:me:k1', 'type:feature', 'triaged']],
            $this->sentPatchBody(),
            'the caller list replaces only the caller-owned half; every reserved tag is re-sent'
        );
        // The caller's own stale tag IS dropped — that is what a correction is for, and
        // it is the control that makes the preservation above mean something.
        $this->assertNotContains('stale-caller-tag', $res->json('result.tags_written'));
    }

    public function test_correct_refuses_a_card_another_agent_filed_and_writes_nothing(): void
    {
        // ⭐ The acceptance criterion on card#8378: the refusal must be SEEN to fire.
        // Same board, same lane — only the mint stamp differs.
        Http::fake($this->correctFake(live: [$this->ownCardRow(['tags' => ['created-by:someoneelse']])]));

        $res = $this->callTool(['tool' => 'board_correct_card', 'args' => ['card_id' => 42, 'name' => 'hijacked']]);

        $res->assertStatus(422);
        $this->assertStringContainsString('not one of yours', (string) $res->json('error'));
        Http::assertNotSent(fn ($r) => $r->method() === 'PATCH');
    }

    public function test_correct_refuses_a_card_carrying_no_mint_stamp_at_all(): void
    {
        // A card the seat did not file — minted by the PM, by kbcard, by the reconcile.
        Http::fake($this->correctFake(live: [$this->ownCardRow(['tags' => ['triaged']])]));

        $this->callTool(['tool' => 'board_correct_card', 'args' => ['card_id' => 42, 'name' => 'x']])
            ->assertStatus(422);
        Http::assertNotSent(fn ($r) => $r->method() === 'PATCH');
    }

    public function test_correct_refuses_a_stamp_that_differs_only_in_case(): void
    {
        // Agent names are filesystem-cased config names, so `me` and `ME` can be two
        // seats; the compare is case-SENSITIVE deliberately. Nothing is lost by being
        // narrower than the writer — the bridge stamps the exact agent name.
        Http::fake($this->correctFake(live: [$this->ownCardRow(['tags' => ['created-by:ME']])]));

        $this->callTool(['tool' => 'board_correct_card', 'args' => ['card_id' => 42, 'name' => 'x']])
            ->assertStatus(422);
        Http::assertNotSent(fn ($r) => $r->method() === 'PATCH');
    }

    public function test_correct_refuses_a_card_that_is_not_on_this_agents_board(): void
    {
        // The board-scoped lookup answers nothing, and neither does the archive side.
        Http::fake($this->correctFake(live: [], archived: []));

        $this->callTool(['tool' => 'board_correct_card', 'args' => ['card_id' => 42, 'name' => 'x']])
            ->assertStatus(422);
        Http::assertNotSent(fn ($r) => $r->method() === 'PATCH');
        // Both sides of the archive switch were asked before the refusal — a live-only
        // check would report a retired card of the seat's own as "not yours".
        Http::assertSent(fn ($r) => str_contains(urldecode($r->url()), 'archived=1'));
    }

    public function test_correct_refuses_a_lookup_that_answers_a_different_card_as_a_broken_read(): void
    {
        // The endpoint drops a term it does not recognise and still answers 200, so the
        // verdict is read off the ROWS. A row that is not this card is a broken read,
        // explicitly NOT a verdict about ownership (DL-323 Decision 2).
        Http::fake($this->correctFake(live: [$this->ownCardRow(['id' => 99])]));

        $res = $this->callTool(['tool' => 'board_correct_card', 'args' => ['card_id' => 42, 'name' => 'x']]);

        $res->assertStatus(422);
        $this->assertStringContainsString('BROKEN READ', (string) $res->json('error'));
        Http::assertNotSent(fn ($r) => $r->method() === 'PATCH');
    }

    public function test_correct_refuses_a_row_whose_own_board_is_not_this_agents(): void
    {
        // Same id, foreign board: the row does not establish the card, so it is never
        // reached as "owned" even though it carries this agent's stamp.
        Http::fake($this->correctFake(live: [$this->ownCardRow(['board_id' => 999])]));

        $this->callTool(['tool' => 'board_correct_card', 'args' => ['card_id' => 42, 'name' => 'x']])
            ->assertStatus(422);
        Http::assertNotSent(fn ($r) => $r->method() === 'PATCH');
    }

    public function test_correct_refuses_an_owned_archived_card_by_naming_the_retire(): void
    {
        // Telling a seat that a card it demonstrably filed is "not one of yours" is a
        // false statement made by a guard; the stamp proves the card is the caller's, so
        // naming the retire discloses nothing.
        Http::fake($this->correctFake(live: [], archived: [$this->ownCardRow()]));

        $res = $this->callTool(['tool' => 'board_correct_card', 'args' => ['card_id' => 42, 'name' => 'x']]);

        $res->assertStatus(422);
        $this->assertStringContainsString('ARCHIVED', (string) $res->json('error'));
        Http::assertNotSent(fn ($r) => $r->method() === 'PATCH');
    }

    public function test_correct_does_not_disclose_an_archived_card_another_agent_filed(): void
    {
        // The archived arm names the retire only for a card the caller OWNS.
        Http::fake($this->correctFake(live: [], archived: [$this->ownCardRow(['tags' => ['created-by:someoneelse']])]));

        $res = $this->callTool(['tool' => 'board_correct_card', 'args' => ['card_id' => 42, 'name' => 'x']]);

        $res->assertStatus(422);
        $this->assertStringNotContainsString('ARCHIVED', (string) $res->json('error'));
    }

    /**
     * @return list<array{string, mixed}>
     */
    public static function bridgeOwnedFieldCases(): array
    {
        return [
            ['workflow_stage_id', 51], ['column', 'shipped'], ['stage', 'in_review'], ['move', 'done'],
            ['swimlane_id', 9], ['board_id', 999], ['payload', ['origin' => 'x']],
            ['dl_number', 'DL-1'], ['pr_number', 12], ['pr_url', 'https://example.test/1'],
            ['issue_number', 3], ['issue_url', 'https://example.test/i/3'], ['version', 'v1.0.0'],
            ['origin', 'consumer-driven'], ['external_id', '12345'], ['external_link', 'https://example.test'],
            ['type', 'feature'], ['card_type_id', 3], ['triaged', true], ['block_reason', 'blocked'],
            ['archived', true], ['archived_at', '2026-09-01'], ['_action', 'archive'],
            ['priority', 5], ['due_date', '2026-09-30'], ['assigned_user_id', 3],
        ];
    }

    #[DataProvider('bridgeOwnedFieldCases')]
    public function test_correct_refuses_a_bridge_owned_field_by_name_and_reads_nothing(string $key, mixed $value): void
    {
        // Refused BY NAME with its owner, and refused BEFORE any request: a silently
        // ignored argument would leave the seat believing it corrected something it did
        // not, which is the "never silently no-op" this card was filed on.
        Http::fake($this->correctFake(live: [$this->ownCardRow()]));

        $res = $this->callTool(['tool' => 'board_correct_card', 'args' => [
            'card_id' => 42, 'name' => 'fine', $key => $value,
        ]]);

        $res->assertStatus(422);
        $this->assertStringContainsString("`{$key}` is not correctable here", (string) $res->json('error'));
        Http::assertNothingSent();
    }

    public function test_correct_refuses_a_bridge_owned_field_whatever_its_case(): void
    {
        Http::fake($this->correctFake(live: [$this->ownCardRow()]));

        $res = $this->callTool(['tool' => 'board_correct_card', 'args' => ['card_id' => 42, 'Column' => 'shipped']]);

        $res->assertStatus(422);
        $this->assertStringContainsString('not correctable here', (string) $res->json('error'));
        Http::assertNothingSent();
    }

    public function test_correct_refuses_an_unknown_argument(): void
    {
        Http::fake($this->correctFake(live: [$this->ownCardRow()]));

        $res = $this->callTool(['tool' => 'board_correct_card', 'args' => ['card_id' => 42, 'nam' => 'typo']]);

        $res->assertStatus(422);
        $this->assertStringContainsString('unknown argument `nam`', (string) $res->json('error'));
        Http::assertNothingSent();
    }

    public function test_correct_refuses_a_call_that_names_no_field_to_correct(): void
    {
        Http::fake($this->correctFake(live: [$this->ownCardRow()]));

        $res = $this->callTool(['tool' => 'board_correct_card', 'args' => ['card_id' => 42]]);

        $res->assertStatus(422);
        $this->assertStringContainsString('nothing to correct', (string) $res->json('error'));
        Http::assertNothingSent();
    }

    /**
     * @return list<array{mixed}>
     */
    public static function badCardIdCases(): array
    {
        // ⚠ 42.0 is deliberately ABSENT: `json_encode(42.0)` emits `42`, so a float
        // card id cannot be expressed through this harness's body builder and a case
        // for it would assert the encoder, not the guard. 42.5 exercises the same
        // is_int arm and survives the round-trip.
        return [[null], ['42'], [42.5], [0], [-1], [true], [['42']]];
    }

    #[DataProvider('badCardIdCases')]
    public function test_correct_refuses_a_card_id_that_is_not_a_positive_integer(mixed $cardId): void
    {
        // A coerced id names a DIFFERENT card, and this id selects the row a write lands
        // on — so a decorated string or a float is refused, never cast.
        Http::fake($this->correctFake(live: [$this->ownCardRow()]));

        $args = ['name' => 'x'];
        if ($cardId !== null) {
            $args['card_id'] = $cardId;
        }
        $this->callTool(['tool' => 'board_correct_card', 'args' => $args])->assertStatus(422);
        Http::assertNothingSent();
    }

    /**
     * @return list<array{string}>
     */
    public static function correctReservedTagCases(): array
    {
        return [['created-by:someoneelse'], ['idem:me:forged'], ['id:123'], ['type:brief'], ['triaged'], ['IDEM:me:forged'], ['Triaged'], ['bad_tag'], ['ünïcode']];
    }

    #[DataProvider('correctReservedTagCases')]
    public function test_correct_refuses_a_reserved_or_out_of_charset_caller_tag(string $tag): void
    {
        // The SAME policy the create tool enforces, through the shared owner: a
        // correction authority wider than the create authority would be the laundering
        // route around the create guard (mint clean, then "correct" the reserved tag in).
        Http::fake($this->correctFake(live: [$this->ownCardRow()]));

        $this->callTool(['tool' => 'board_correct_card', 'args' => ['card_id' => 42, 'tags' => [$tag]]])
            ->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_correct_clears_a_description_that_is_present_and_empty(): void
    {
        // ONE rule: a PRESENT key is a correction, an ABSENT one leaves the field
        // alone. `""` and `null` are one value here because Laravel's global
        // ConvertEmptyStringsToNull rewrites `""` to null before the controller reads
        // `args` on the HTTP door, while the ssh door preserves it — so treating them
        // as one value is what keeps the contract identical on both transports.
        Http::fake($this->correctFake(live: [$this->ownCardRow()]));

        $this->callTool(['tool' => 'board_correct_card', 'args' => ['card_id' => 42, 'description' => '']])
            ->assertStatus(200)->assertJsonPath('result.fields', ['description']);
        $this->assertSame(['description' => ''], $this->sentPatchBody());

        $this->callTool(['tool' => 'board_correct_card', 'args' => ['card_id' => 42, 'description' => null]])
            ->assertStatus(200);
        $this->assertSame(['description' => ''], $this->sentPatchBody());
    }

    public function test_correct_treats_an_empty_tag_list_as_drop_my_tags_not_as_an_omission(): void
    {
        // The other half of the same rule, on the field where it bites: `tags: []` is a
        // real instruction, and it must not collapse into "leave the tags alone" — the
        // write replaces the list wholesale, so the two answers differ by every
        // caller-owned tag on the card. The bridge-stamped tags still survive.
        Http::fake($this->correctFake(live: [$this->ownCardRow([
            'tags' => ['created-by:me', 'stale-caller-tag'],
        ])]));

        $this->callTool(['tool' => 'board_correct_card', 'args' => ['card_id' => 42, 'tags' => []]])
            ->assertStatus(200)->assertJsonPath('result.tags_written', ['created-by:me']);
        $this->assertSame(['tags' => ['created-by:me']], $this->sentPatchBody());
    }

    public function test_correct_refuses_a_name_that_is_present_and_empty_rather_than_clearing_it(): void
    {
        // A card cannot be left without a name, so `name` has no clear form — and the
        // HTTP door's ConvertEmptyStringsToNull makes `""` arrive as null, which must
        // reach the same refusal rather than a null write.
        Http::fake($this->correctFake(live: [$this->ownCardRow()]));

        foreach (['', null] as $empty) {
            $this->callTool(['tool' => 'board_correct_card', 'args' => ['card_id' => 42, 'name' => $empty]])
                ->assertStatus(422);
        }
        Http::assertNothingSent();
    }

    public function test_correct_maps_a_403_on_the_ownership_lookup_to_a_named_install_fault(): void
    {
        // A 403 here is nothing the seat passed, and not retryable — reporting it as the
        // dispatcher's 502 would invite the retry loop DL-020 warns about.
        //
        // ⛔ AND THE CAUSE IT NAMES HAS TO BE ONE THAT CAN PRODUCE A 403. The message
        // said "the writeback token's membership of your board", which kanban's search
        // never answers 403 for: it FLOORS a caller to its member boards and returns 200
        // with zero rows. What does 403 on this route is the per-token ability gate
        // (kanban DL-055 — a GET needs `read`), so that is what the operator is sent to
        // audit; the membership cause belongs on the not-found refusal, where it is.
        Http::fake($this->correctFake(live: [], lookupStatus: 403));

        $res = $this->callTool(['tool' => 'board_correct_card', 'args' => ['card_id' => 42, 'name' => 'x']]);

        $res->assertStatus(422);
        $error = (string) $res->json('error');
        $this->assertStringContainsString('INSTALL fault', $error);
        $this->assertStringContainsString('abilities', $error);
        $this->assertStringContainsString('board membership does NOT produce a 403', $error);
        Http::assertNotSent(fn ($r) => $r->method() === 'PATCH');
    }

    public function test_correct_leaves_a_5xx_on_the_lookup_as_a_retryable_upstream_error(): void
    {
        // THE CONTROL for the two mappings above: the 403/404 arms are a NARROWING, not
        // a blanket that swallows every upstream failure into a permanent refusal. A
        // 5xx may clear, so it stays the dispatcher's retryable 502.
        Http::fake($this->correctFake(live: [], lookupStatus: 503));

        $this->callTool(['tool' => 'board_correct_card', 'args' => ['card_id' => 42, 'name' => 'x']])
            ->assertStatus(502);
    }

    public function test_correct_maps_a_403_on_the_write_to_a_named_refusal(): void
    {
        // ⛔ TWO INDEPENDENT GATES ANSWER 403 ON `PATCH /api/v3/tasks/{id}.json`, and the
        // message has to send the operator to BOTH or it sends them to audit half the
        // cause: the Sanctum per-token ABILITY gate (`EnforceTokenAbilities` — a PATCH
        // needs `write`) and the board ROLE permission (`task.update`, because a PATCH
        // carrying anything but `workflow_stage_id` alone authorizes `update`, kanban
        // DL-204). `task.update` is NEW for this door — the other two board tools need
        // only `board.view` / `task.create` — so an install granting exactly those 403s
        // here with a token whose abilities are perfectly fine.
        Http::fake($this->correctFake(live: [$this->ownCardRow()], patchStatus: 403));

        $res = $this->callTool(['tool' => 'board_correct_card', 'args' => ['card_id' => 42, 'name' => 'x']]);

        $res->assertStatus(422);
        $this->assertStringContainsString('403', (string) $res->json('error'));
        $this->assertStringContainsString('Nothing was written', (string) $res->json('error'));
        $this->assertStringContainsString('`write`', (string) $res->json('error'));
        $this->assertStringContainsString('`task.update`', (string) $res->json('error'));
    }

    public function test_correct_maps_a_404_on_the_write_to_a_named_refusal(): void
    {
        // The card stopped existing between the ownership check and the write.
        Http::fake($this->correctFake(live: [$this->ownCardRow()], patchStatus: 404));

        $res = $this->callTool(['tool' => 'board_correct_card', 'args' => ['card_id' => 42, 'name' => 'x']]);

        $res->assertStatus(422);
        $this->assertStringContainsString('no longer exists', (string) $res->json('error'));
    }

    public function test_correct_leaves_a_5xx_on_the_write_as_a_retryable_upstream_error(): void
    {
        Http::fake($this->correctFake(live: [$this->ownCardRow()], patchStatus: 500));

        $this->callTool(['tool' => 'board_correct_card', 'args' => ['card_id' => 42, 'name' => 'x']])
            ->assertStatus(502);
    }

    // ─── board_correct_card: what a wholesale replace must NOT delete (card#8378 R1) ─

    /** Write a `writeback.json` into the config dir this test's bridge reads. */
    private function writeWriteback(string $json): void
    {
        File::put($this->dir.'/writeback.json', $json);
    }

    /**
     * ⭐ THE PIN IS NOT A TAG THE CALLER MAY NOT SUPPLY — IT IS A TAG THE CALLER MAY NOT
     * DROP, AND THE TWO SETS ARE DIFFERENT. `no-automove` is `PinGuard`'s all-outcome
     * writeback hold: a human pins a card with it and the event path, the reconciler and
     * the release sweep all refuse to move it. A caller MAY supply it (pinning your own
     * card is legitimate), so it is deliberately absent from the reserved vocabulary —
     * which is exactly why a preserve set derived from that vocabulary dropped it, and a
     * `tags` correction un-pinned a card the human pinned. The next `merged` event then
     * makes the terminal move card#8289 exists to prevent.
     *
     * The control is in the same call: the caller's OWN stale tag is still dropped, so
     * the assertion cannot pass by preserving everything.
     */
    public function test_correct_preserves_the_writeback_pin_a_caller_may_supply_but_may_not_drop(): void
    {
        Http::fake($this->correctFake(live: [$this->ownCardRow([
            'tags' => ['created-by:me', 'no-automove', 'stale-caller-tag'],
        ])]));

        $res = $this->callTool(['tool' => 'board_correct_card', 'args' => [
            'card_id' => 42, 'tags' => ['fresh-caller-tag'],
        ]]);

        $res->assertStatus(200);
        $this->assertSame(
            ['tags' => ['fresh-caller-tag', 'created-by:me', 'no-automove']],
            $this->sentPatchBody(),
            'the human pin survives a wholesale tag replace; the caller-owned tag does not'
        );
    }

    /**
     * The OPERATOR-DECLARED half of the same set (DL-194 `hold_marker_tags`), and the
     * board filter that makes it a per-install answer rather than a global one.
     *
     * ⭐ THE SECOND MAPPING IS THE CONTROL, and it is what makes this a measurement: a
     * hold tag declared for a DIFFERENT board is not this board's convention, so an
     * implementation that unioned every mapping's tags would re-send `other-hold` and
     * red here. The seat knows only `board_tools.board_id`, so the board-keyed read
     * (`WritebackConfig::holdMarkerTagsForBoard`) is the whole reachability answer.
     */
    public function test_correct_preserves_the_hold_tags_this_install_declares_for_this_board_only(): void
    {
        $this->writeWriteback((string) json_encode(['mappings' => [
            'o/mine' => ['board_id' => 10, 'stages' => ['merged' => 52], 'hold_marker_tags' => ['gate']],
            'o/other' => ['board_id' => 999, 'stages' => ['merged' => 52], 'hold_marker_tags' => ['other-hold']],
        ]]));
        Http::fake($this->correctFake(live: [$this->ownCardRow([
            'tags' => ['created-by:me', 'gate', 'other-hold'],
        ])]));

        $res = $this->callTool(['tool' => 'board_correct_card', 'args' => [
            'card_id' => 42, 'tags' => ['fresh-caller-tag'],
        ]]);

        $res->assertStatus(200);
        $this->assertSame(
            ['tags' => ['fresh-caller-tag', 'created-by:me', 'gate']],
            $this->sentPatchBody(),
            'this board\'s declared hold survives; another board\'s declared hold is not this board\'s convention'
        );
    }

    /**
     * ⛔ AN UNKNOWN HOLD VOCABULARY IS NOT AN EMPTY ONE. A `writeback.json` the bridge
     * cannot parse means it cannot say which tags this install treats as a hold — and a
     * wholesale replace under that uncertainty is precisely the silent deletion the
     * preservation exists to stop. So the TAGS leg refuses, names the file, and writes
     * nothing; it does not fall back to "no holds declared".
     */
    public function test_correct_refuses_a_tag_correction_when_the_hold_vocabulary_cannot_be_read(): void
    {
        $this->writeWriteback('{ this is not json');
        Http::fake($this->correctFake(live: [$this->ownCardRow()]));

        $res = $this->callTool(['tool' => 'board_correct_card', 'args' => [
            'card_id' => 42, 'tags' => ['fresh-caller-tag'],
        ]]);

        $res->assertStatus(422);
        $this->assertStringContainsString('writeback.json', (string) $res->json('error'));
        $this->assertStringContainsString('INSTALL fault', (string) $res->json('error'));
        // Nothing was READ either: the vocabulary is resolved before the ownership
        // lookup, so a call that cannot establish it costs the board nothing.
        Http::assertNothingSent();
    }

    /**
     * THE CONTROL for the refusal above: it is scoped to the leg that needs the answer.
     * A `name` correction writes no tag list, so an unparseable `writeback.json` has
     * nothing to do with it — a blanket refusal would take a working tool offline over
     * a file it never reads.
     */
    public function test_a_name_correction_is_unaffected_by_an_unreadable_writeback_config(): void
    {
        $this->writeWriteback('{ this is not json');
        Http::fake($this->correctFake(live: [$this->ownCardRow()]));

        $this->callTool(['tool' => 'board_correct_card', 'args' => ['card_id' => 42, 'name' => 'corrected']])
            ->assertStatus(200);
        $this->assertSame(['name' => 'corrected'], $this->sentPatchBody());
    }

    // ─── board_correct_card: the value bounds kanban's validator imposes (R1/C2) ─

    /**
     * Kanban validates `name => string|max:255`. Unbounded on this side, a 256-character
     * title reached the board, came back 422, and the dispatcher reported it as
     * `502 upstream board error` — a retryable answer to a request that can never
     * succeed, i.e. the seat retries forever with no diagnosis. It is refused HERE, by
     * name, before anything is read or written.
     */
    public function test_correct_refuses_a_name_longer_than_kanban_accepts(): void
    {
        Http::fake($this->correctFake(live: [$this->ownCardRow()]));

        $res = $this->callTool(['tool' => 'board_correct_card', 'args' => [
            'card_id' => 42, 'name' => str_repeat('x', 256),
        ]]);

        $res->assertStatus(422);
        $this->assertStringContainsString('255', (string) $res->json('error'));
        Http::assertNothingSent();
        // The CONTROL: the boundary itself is accepted, so the refusal is a bound and
        // not a blanket. ⚠ No second `Http::fake()` — a second registration STACKS
        // behind the first (the first matching stub wins), so it would look like a new
        // fixture while the old one answered.
        $this->callTool(['tool' => 'board_correct_card', 'args' => [
            'card_id' => 42, 'name' => str_repeat('x', 255),
        ]])->assertStatus(200);
    }

    /**
     * The same bound on the other field kanban caps: `tags.* => string|max:64`. It lives
     * in the SHARED `CallerTagPolicy`, so it holds on both tools that write a tag list —
     * a bound on one of them is the divergence the shared policy exists to prevent.
     */
    public function test_correct_refuses_a_tag_longer_than_kanban_accepts(): void
    {
        Http::fake($this->correctFake(live: [$this->ownCardRow()]));

        $res = $this->callTool(['tool' => 'board_correct_card', 'args' => [
            'card_id' => 42, 'tags' => [str_repeat('x', 65)],
        ]]);

        $res->assertStatus(422);
        $this->assertStringContainsString('64', (string) $res->json('error'));
        Http::assertNothingSent();
    }

    public function test_create_refuses_a_tag_longer_than_kanban_accepts(): void
    {
        // The create tool's half of the shared bound — and the same 502-instead-of-422
        // misreport it fixes there (a create with an over-long tag 422'd at the board).
        // The create endpoint is stubbed to SUCCEED, so an unbounded tool answers 200
        // here: the refusal is what this measures, not a stub-less fake.
        Http::fake(['*/tasks.json' => Http::response(['data' => ['id' => 1]], 201)]);

        $res = $this->callTool(['tool' => 'board_create_card', 'args' => [
            'title' => 'a card', 'tags' => [str_repeat('x', 65)],
        ]]);

        $res->assertStatus(422);
        $this->assertStringContainsString('64', (string) $res->json('error'));
        Http::assertNothingSent();
    }

    /**
     * ⭐ THE BOUNDS ABOVE ARE A MIRROR OF RULES THAT LIVE IN KANBAN'S REPO, so they can go
     * stale — and this is what makes that survivable. A 422 the BOARD answers is
     * deterministic (its validator will refuse the same value forever), so it is a
     * refusal, not the retryable 502 the seat would loop on.
     *
     * ⛔ And the message is BRIDGE-AUTHORED: the board's response body is never echoed
     * into the seat's error, so this asserts the upstream text is ABSENT as well as the
     * bridge's own text present — an absence-only assertion would certify anything.
     */
    public function test_correct_maps_a_422_on_the_write_to_a_refusal_that_does_not_echo_the_board(): void
    {
        Http::fake($this->correctFake(
            live: [$this->ownCardRow()],
            patchStatus: 422,
            patchBody: '{"message":"The name field must not be greater than 255 characters.","errors":{}}',
        ));

        $res = $this->callTool(['tool' => 'board_correct_card', 'args' => ['card_id' => 42, 'name' => 'x']]);

        $res->assertStatus(422);
        $error = (string) $res->json('error');
        $this->assertStringContainsString('REJECTED the value you sent', $error);
        $this->assertStringNotContainsString('must not be greater', $error, 'the board\'s own response body must not reach the seat');
    }

    /**
     * A 401 is the rotated/revoked writeback token — kanban's v3 API is `auth:sanctum`,
     * so a token it no longer knows is refused at the door on every subsequent call.
     * Deterministic, and an INSTALL fault: retrying is the one thing that cannot help.
     */
    public function test_correct_maps_a_401_on_the_lookup_to_a_named_install_fault(): void
    {
        Http::fake($this->correctFake(live: [], lookupStatus: 401));

        $res = $this->callTool(['tool' => 'board_correct_card', 'args' => ['card_id' => 42, 'name' => 'x']]);

        $res->assertStatus(422);
        $this->assertStringContainsString('revoked, rotated', (string) $res->json('error'));
        $this->assertStringContainsString('INSTALL fault', (string) $res->json('error'));
        Http::assertNotSent(fn ($r) => $r->method() === 'PATCH');
    }

    public function test_correct_maps_a_401_on_the_write_to_a_named_install_fault(): void
    {
        Http::fake($this->correctFake(live: [$this->ownCardRow()], patchStatus: 401));

        $res = $this->callTool(['tool' => 'board_correct_card', 'args' => ['card_id' => 42, 'name' => 'x']]);

        $res->assertStatus(422);
        $this->assertStringContainsString('did not accept', (string) $res->json('error'));
        $this->assertStringContainsString('INSTALL fault', (string) $res->json('error'));
    }

    /**
     * ⭐ DECISION 9'S NON-DISCLOSURE, ASSERTED AS THE PROPERTY IT IS: the two arms answer
     * the SAME BYTES. A seat must not be able to tell "this card is on your board but is
     * not yours" from "no such card here" — otherwise the refusal is a card-existence
     * oracle for every id on the board. A message improved on one arm and not the other
     * (the shape this reds against) breaks it silently.
     *
     * ⚠ And the shared text now carries the third cause: kanban's search FLOORS a caller
     * to boards it is a member of and answers 200-with-zero-rows for the rest, so an
     * unreadable board arrives here looking exactly like an empty one (DL-323's
     * `mapped_board_unreadable_to_this_token`). Without that sentence the seat is told a
     * card it really did file "is not one of yours".
     */
    public function test_the_not_yours_refusal_is_byte_identical_across_its_arms_and_names_the_unreadable_board(): void
    {
        // ⛔ ONE fake, switched by a captured flag. `Http::fake()` STACKS — a second
        // registration does not replace the first, and the first matching stub wins — so
        // staging the second arm with a second `Http::fake()` leaves BOTH calls answered
        // by the foreign-stamp fixture and the comparison below passes against anything.
        // (Measured: the mutant that gives one arm a different sentence stayed green.)
        $rows = [$this->ownCardRow(['tags' => ['created-by:someoneelse']])];
        Http::fake(function ($request) use (&$rows) {
            $url = urldecode($request->url());
            if (str_contains($url, '/tasks/search.json')) {
                return Http::response(['data' => str_contains($url, 'archived=1') ? [] : $rows]);
            }

            return Http::response(['data' => ['id' => 42]]);
        });

        $foreign = (string) $this->callTool(['tool' => 'board_correct_card', 'args' => ['card_id' => 42, 'name' => 'x']])->json('error');

        $rows = [];   // same fixture, other arm: no such card on this board
        $absent = (string) $this->callTool(['tool' => 'board_correct_card', 'args' => ['card_id' => 42, 'name' => 'x']])->json('error');

        $this->assertSame($foreign, $absent, 'a card-existence oracle: the two arms must answer identically');
        $this->assertStringContainsString('membership of board 10', $foreign);
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
