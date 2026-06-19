<?php

namespace Tests\Feature\Handlers;

use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Exceptions\ConfigException;
use App\Bridge\Handlers\KanbanMoveCardHandler;
use App\Bridge\Support\AgentConfig;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KanbanMoveCardHandlerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/mvcard-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/kanban');
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

    private function writeWriteback(array $stages = ['merged' => 52], array $extra = []): void
    {
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => ['board_id' => 8, 'stages' => $stages] + $extra],
        ]));
    }

    private function writeToken(): void
    {
        File::put($this->dir.'/kanban/writeback-token', 'wb-token');
        chmod($this->dir.'/kanban/writeback-token', 0o600);
    }

    private function handle(array $payload): void
    {
        (new KanbanMoveCardHandler)->handle(
            ReactionTarget::make('kanban_move_card', '5', payload: $payload),
            AgentConfig::fromArray('prod-agent', ['identity' => ['kanban_user_id' => 1], 'subscriptions' => []]),
        );
    }

    private function payload(array $over = []): array
    {
        return array_merge(['card_id' => 5, 'repo' => 'owner/repo', 'outcome' => 'merged'], $over);
    }

    public function test_happy_path_moves_card_to_mapped_stage(): void
    {
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 49]])   // GET
                ->push(['data' => ['id' => 5]]),                                                // PATCH
        ]);

        $this->handle($this->payload());

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && str_contains($r->url(), '/tasks/5.json')
            && $r['task'] === ['workflow_stage_id' => 52]
            && $r->hasHeader('Authorization', 'Bearer wb-token'));
    }

    public function test_already_in_target_stage_is_idempotent_noop(): void
    {
        $this->writeWriteback();
        $this->writeToken();
        Http::fake(['*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 52]])]);

        $this->handle($this->payload());

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');   // no move
    }

    public function test_card_on_wrong_board_is_refused_no_move(): void
    {
        $this->writeWriteback();
        $this->writeToken();
        Http::fake(['*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 999, 'workflow_stage_id' => 49]])]);

        $this->handle($this->payload());   // refused (belongs-to-board guard) — no throw, no move

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    }

    public function test_no_mapping_for_repo_is_noop(): void
    {
        $this->writeWriteback();
        $this->writeToken();
        Http::fake();

        $this->handle($this->payload(['repo' => 'other/repo']));

        Http::assertNothingSent();   // never even called the API
    }

    public function test_no_stage_for_outcome_is_noop(): void
    {
        $this->writeWriteback(['merged' => 52]);   // only 'merged' mapped
        $this->writeToken();
        Http::fake();

        $this->handle($this->payload(['outcome' => 'closed_unmerged']));

        Http::assertNothingSent();
    }

    public function test_writeback_disabled_is_noop(): void
    {
        // No writeback.json written.
        Http::fake();
        $this->handle($this->payload());
        Http::assertNothingSent();
    }

    public function test_missing_token_throws_for_redelivery(): void
    {
        // Transient/operator-fixable: throw → 5xx → redelivery succeeds once the
        // operator places the token (mirrors the HMAC-secret fail-closed).
        $this->writeWriteback();
        // No token written.
        Http::fake();
        $this->expectException(ConfigException::class);   // propagates → 5xx, same as before
        $this->handle($this->payload());
    }

    public function test_bad_payload_card_id_is_permanent_noop_not_a_throw(): void
    {
        // A malformed payload is a deterministic classifier bug — permanent, so it
        // must NOT throw (a durable throw would 5xx-storm an event that fails
        // identically every redelivery). Log + no-op.
        $this->writeWriteback();
        $this->writeToken();
        Http::fake();

        $this->handle($this->payload(['card_id' => 'not-a-number']));   // no exception

        Http::assertNothingSent();
    }

    public function test_kanban_4xx_on_get_is_permanent_noop(): void
    {
        // A deleted card / bad id → kanban 404 is PERMANENT: log + no-op, never
        // 5xx-storm. (No move attempted.)
        $this->writeWriteback();
        $this->writeToken();
        Http::fake(['*/tasks/5.json' => Http::response(['error' => 'not found'], 404)]);

        $this->handle($this->payload());   // no exception

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    }

    public function test_kanban_4xx_on_move_is_permanent_noop(): void
    {
        // e.g. the mapped stage isn't on the card's board (config typo) → kanban
        // 422/404 on the PATCH is PERMANENT: log + no-op, not a 5xx-storm.
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 49]])   // GET ok
                ->push(['error' => 'invalid stage'], 422),                                      // PATCH 4xx
        ]);

        $this->handle($this->payload());   // no exception

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH');   // move attempted; 4xx swallowed
    }

    public function test_kanban_5xx_is_transient_and_throws(): void
    {
        // A kanban 5xx / timeout is TRANSIENT: throw → 5xx → redelivery retries.
        $this->writeWriteback();
        $this->writeToken();
        Http::fake(['*/tasks/5.json' => Http::response('upstream error', 503)]);

        $this->expectException(RequestException::class);
        $this->handle($this->payload());
    }

    public function test_started_promotes_card_from_an_allowed_backlog_stage(): void
    {
        // DL-160: branch-create push → `started`. The card sits in Backlog (46),
        // an allowed promote-from stage → move it to In Progress (49).
        $this->writeWriteback(['started' => 49], ['started_from_stages' => [46, 47]]);
        $this->writeToken();
        Http::fake([
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 46]])   // GET (Backlog)
                ->push(['data' => ['id' => 5]]),                                                // PATCH
        ]);

        $this->handle($this->payload(['outcome' => 'started']));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && str_contains($r->url(), '/tasks/5.json')
            && $r['task'] === ['workflow_stage_id' => 49]);
    }

    public function test_started_does_not_regress_an_already_progressed_card(): void
    {
        // The card is In Review (50), NOT a promote-from stage → no move (a
        // re-created/force-pushed old branch must not drag it backward).
        $this->writeWriteback(['started' => 49], ['started_from_stages' => [46, 47]]);
        $this->writeToken();
        Http::fake(['*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 50]])]);

        $this->handle($this->payload(['outcome' => 'started']));

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');   // no regression
    }

    public function test_started_is_refused_when_no_promote_from_stages_configured(): void
    {
        // Fail-closed: with no `started_from_stages` we can't know what's safe to
        // promote from, so a `started` move is refused (log + no-op).
        $this->writeWriteback(['started' => 49]);
        $this->writeToken();
        Http::fake(['*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 46]])]);

        $this->handle($this->payload(['outcome' => 'started']));

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    }

    public function test_started_already_in_progress_is_idempotent_noop(): void
    {
        // Already at the target In-Progress stage → idempotent no-op (guard never reached).
        $this->writeWriteback(['started' => 49], ['started_from_stages' => [46, 47]]);
        $this->writeToken();
        Http::fake(['*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 49]])]);

        $this->handle($this->payload(['outcome' => 'started']));

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    }

    public function test_payload_board_id_is_ignored_config_is_authoritative(): void
    {
        // A payload board_id that disagrees with config must NOT change the
        // belongs-to-board decision — the card's real board (8) matches the
        // CONFIG mapping (8), so the move proceeds despite payload board_id 999.
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 49]])
                ->push(['data' => ['id' => 5]]),
        ]);

        $this->handle($this->payload(['board_id' => 999]));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r['task'] === ['workflow_stage_id' => 52]);
    }
}
