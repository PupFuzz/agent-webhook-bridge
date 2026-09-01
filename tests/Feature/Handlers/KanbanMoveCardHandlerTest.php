<?php

namespace Tests\Feature\Handlers;

use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Exceptions\ConfigException;
use App\Bridge\Handlers\KanbanMoveCardHandler;
use App\Bridge\Support\AgentConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PreloadStub;
use Tests\Support\ScopeLookupStub;
use Tests\TestCase;

class KanbanMoveCardHandlerTest extends TestCase
{
    use RefreshDatabase;

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

    /**
     * Whether this test has already had the card#8375 board-scope fallback registered.
     *
     * ⛔ ONCE PER TEST, and the flag is what makes that true: `Http::fake()` also RESETS the
     * recorded-request log (G-020), so registering it on a SECOND `handle()` call would erase
     * the first delivery's requests — which several legs here assert across (the dedup and
     * re-arm cases call `handle()` twice and count the pushes).
     */
    private bool $scopeLookupStubbed = false;

    private function handle(array $payload): void
    {
        // The board-scoped tenant check (card#8375) is made on every delivery that gets as far
        // as building a client, so every leg below reaches this endpoint. Registered LAST so a
        // leg that stubs the search itself still wins (G-020: first match wins); see
        // {@see ScopeLookupStub} for why it is a fixture rather than an assertion.
        if (! $this->scopeLookupStubbed) {
            Http::fake(ScopeLookupStub::onMappedBoard(8));
            $this->scopeLookupStubbed = true;
        }

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
        ] + $this->fakePreload());

        $this->handle($this->payload());

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && str_contains($r->url(), '/tasks/5.json')
            && $r->data() === ['workflow_stage_id' => 52]
            && $r->hasHeader('Authorization', 'Bearer wb-token'));
        // The no-regression guard's stage-order read, pinned ON THE WIRE. It is the one
        // leg of this move that degrades SILENTLY: `noRegression()` fails open inside a
        // `catch (Throwable)`, so an unstubbed `/preload.json` leaves this test asserting a
        // move the guard never got to weigh — and until card#7300 it also left the request
        // (bearer attached) on the real network, where the verdict depended on whether the
        // runner could resolve the fixture host.
        Http::assertSent(fn (Request $r) => $r->method() === 'GET'
            && str_contains($r->url(), '/boards/8/preload.json'));
    }

    public function test_already_in_target_stage_is_idempotent_noop(): void
    {
        $this->writeWriteback();
        $this->writeToken();
        Http::fake(['*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 52]])]);

        $this->handle($this->payload());

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');   // no move
    }

    // --- card#5287 / DL-270: the uncorroborated title-only card# corroboration gate ---

    public function test_uncorroborated_title_only_token_is_refused_when_the_card_tracks_another_pr(): void
    {
        // THE case the gate exists for: a PR title descriptively cites card#5 while its
        // branch names nothing. Card 5 is on the mapped board, so every guard above
        // passes — and card 5 already answers to PR 900, which is the evidence that the
        // title was citing somebody else's work. Refuse, loudly, and write NOTHING.
        // (Revert the gate ⇒ a PATCH is sent ⇒ RED.)
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            '*/tasks/5/comments.json' => Http::response(['data' => ['id' => 9]], 201),   // card#7064 card note
            '*/tasks/5.json' => Http::response(['data' => [
                'id' => 5, 'board_id' => 8, 'workflow_stage_id' => 49, 'payload' => ['pr_number' => 900],
            ]]),
        ]);
        Log::spy();

        $this->handle($this->payload(['card_token_uncorroborated' => true, 'stamp_pr' => 148]));

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_contains((string) $msg, 'REFUSED')
            && str_contains((string) $msg, 'only in the PR title'))->once();
    }

    public function test_uncorroborated_title_only_token_moves_when_the_card_tracks_no_pr(): void
    {
        // The legitimate title-only PR — the reason refuse-all was declined. The card
        // carries no pr_number, so nothing contradicts the title's claim.
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 49, 'payload' => []]])
                ->push(['data' => ['id' => 5]])
                ->push(['data' => ['id' => 5]]),
        ] + $this->fakePreload());

        $this->handle($this->payload(['card_token_uncorroborated' => true, 'stamp_pr' => 148]));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && $r->data() === ['workflow_stage_id' => 52]);
    }

    public function test_uncorroborated_title_only_token_moves_when_the_card_already_tracks_this_pr(): void
    {
        // A later action on the SAME PR (or a redelivery): the card's own pr_number IS
        // this PR, which corroborates the title rather than contradicting it. The
        // numeric-string form is what a durable-inbox JSON round-trip produces.
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 49, 'payload' => ['pr_number' => '148']]])
                ->push(['data' => ['id' => 5]]),
        ] + $this->fakePreload());

        $this->handle($this->payload(['card_token_uncorroborated' => true, 'stamp_pr' => 148]));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && $r->data() === ['workflow_stage_id' => 52]);
    }

    public function test_uncorroborated_refusal_also_suppresses_the_already_in_stage_self_heal_stamp(): void
    {
        // The gate sits BEFORE the already-in-stage branch on purpose: that branch
        // still STAMPS correlation refs, so a refused move that reached it would write
        // this PR's refs onto a card it has no authority over — the hijack surviving
        // as a stamp instead of a move.
        // (Move the gate below the self-heal ⇒ a PATCH is sent ⇒ RED.)
        //
        // The card carries a pr_number (so the gate refuses) but NO dl_number, and the
        // payload carries a stamp_dl — so the self-heal has real work to do if it is
        // reached. Without that the stamp is add-if-missing-empty and the assertion
        // could not fail whatever the gate did: the first version of this test was a
        // decoration, and the mutation run is what said so.
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            '*/tasks/5/comments.json' => Http::response(['data' => ['id' => 9]], 201),   // card#7064 card note
            '*/tasks/5.json' => Http::response(['data' => [
                'id' => 5, 'board_id' => 8, 'workflow_stage_id' => 52, 'payload' => ['pr_number' => 900],
            ]]),
        ]);

        $this->handle($this->payload(['card_token_uncorroborated' => true, 'stamp_pr' => 148, 'stamp_dl' => 'DL-77']));

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    }

    public function test_a_corroborated_token_moves_a_card_that_tracks_another_pr(): void
    {
        // The gate is scoped to the flag. A branch-corroborated card# (or a DL move)
        // on a card that already tracks another PR is a NORMAL second PR against one
        // card and must keep moving — the gate must not widen into it.
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            '*/tasks/5/comments.json' => Http::response(['data' => ['id' => 9]], 201),   // card#7064 card note
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 49, 'payload' => ['pr_number' => 900]]])
                ->push(['data' => ['id' => 5]]),
        ] + $this->fakePreload());

        $this->handle($this->payload(['stamp_pr' => 148]));   // no uncorroborated flag

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && $r->data() === ['workflow_stage_id' => 52]);
    }

    public function test_uncorroborated_title_only_token_is_refused_when_the_event_carries_no_pr_number(): void
    {
        // Fail-closed: an event with no PR number corroborates nothing, so a card that
        // tracks a PR is not moved on the title's word alone.
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            '*/tasks/5/comments.json' => Http::response(['data' => ['id' => 9]], 201),   // card#7064 card note
            '*/tasks/5.json' => Http::response(['data' => [
                'id' => 5, 'board_id' => 8, 'workflow_stage_id' => 49, 'payload' => ['pr_number' => 900],
            ]]),
        ]);

        $this->handle($this->payload(['card_token_uncorroborated' => true]));   // no stamp_pr

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
        // card#7064: the note must not report a `pr_number` of `null` as if it were a
        // value — the event carrying none IS this arm's reason for refusing.
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && str_contains($r->url(), '/tasks/5/comments.json')
            && str_contains((string) $r['content'], 'pr_number=none')
            && str_contains((string) $r['content'], 'carrying no pull-request number'));
    }

    public function test_uncorroborated_title_only_token_is_refused_when_the_cards_pr_number_names_no_pull_request(): void
    {
        // card#7564 / DL-311 — the sharp end of the truncation class, at the real
        // surface. Card 5 stores `pr_number: '1.5'`, which names NO pull request (kanban
        // DL-251, mirrored here by DL-309), and this event is PR 1. The gate compared
        // `(int) '1.5' === (int) 1`, read "the card already tracks THIS PR" and let the
        // title-only token move a card the event has nothing to do with — the truncation
        // ALLOWS the write here rather than refusing it.
        // (Restore the `(int)` compare in tracksPr ⇒ a PATCH is sent ⇒ RED.)
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            '*/tasks/5/comments.json' => Http::response(['data' => ['id' => 9]], 201),   // card#7064 card note
            '*/tasks/5.json' => Http::response(['data' => [
                'id' => 5, 'board_id' => 8, 'workflow_stage_id' => 49, 'payload' => ['pr_number' => '1.5'],
            ]]),
        ]);
        Log::spy();

        $this->handle($this->payload(['card_token_uncorroborated' => true, 'stamp_pr' => 1]));

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_contains((string) $msg, 'REFUSED')
            && str_contains((string) $msg, 'only in the PR title'))->once();
    }

    public function test_uncorroborated_title_only_token_still_moves_on_a_leading_zero_pr_number(): void
    {
        // CONTROL for the test above: the refusal is scoped to values naming no single
        // pull request, NOT to every spelling of one. `'0148'` and 148 are one PR to the
        // kanban server and were one PR to the old cast; they must stay one here.
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 49, 'payload' => ['pr_number' => '0148']]])
                ->push(['data' => ['id' => 5]]),
        ] + $this->fakePreload());

        $this->handle($this->payload(['card_token_uncorroborated' => true, 'stamp_pr' => 148]));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && $r->data() === ['workflow_stage_id' => 52]);
    }

    // --- card#6027 / DL-287: the near-miss card-token refusal ---

    public function test_a_near_miss_flagged_target_is_refused_before_any_read(): void
    {
        // The classifier found a card-SHAPED token that does not parse beside a
        // resolving DL, and cannot tell whether it named this card. The move is
        // refused HERE rather than dropped at classify time, so the refusal alerts
        // through the one primitive every permanent refusal uses (DL-274/DL-285)
        // instead of becoming a third log-only branch. Refused BEFORE the client is
        // built: nothing is read, nothing is written, and a missing token cannot
        // 5xx-retry an event that is permanently refused.
        // (Ignore the flag ⇒ a GET + PATCH are sent ⇒ RED.)
        $this->writeWriteback();
        $this->writeToken();
        Http::fake();
        Log::spy();

        $this->handle($this->payload(['card_token_near_miss' => true]));

        Http::assertNothingSent();
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_contains((string) $msg, 'REFUSED')
            && str_contains((string) $msg, 'card-shaped'))->once();
    }

    public function test_the_near_miss_refusal_alerts(): void
    {
        // The reason the refusal lives in the handler at all: a permanent refusal
        // must emit a LIVE signal, not just a log line.
        $this->writeWritebackWithAlert();
        $this->writeToken();
        Http::fake([self::ALERT_URL.'*' => Http::response(['ok' => true])]);

        $this->handle($this->payload(['card_token_near_miss' => true]));

        Http::assertSent(fn (Request $r) => $this->isAlertPush($r)
            && $r['type'] === 'writeback_move_failed'
            && $r['reason'] === 'card_token_near_miss'
            && $r['repo'] === 'owner/repo'
            && $r['outcome'] === 'merged'
            && $r['card_id'] === 5);
    }

    public function test_an_unflagged_target_is_unaffected_by_the_near_miss_gate(): void
    {
        // The gate is scoped to the flag — the negative control. Without it the
        // refusal could be unconditional and every leg above would still pass.
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 49]])
                ->push(['data' => ['id' => 5]]),
        ] + $this->fakePreload());

        $this->handle($this->payload());   // no card_token_near_miss flag

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && $r->data() === ['workflow_stage_id' => 52]);
    }

    public function test_card_on_wrong_board_is_refused_no_move(): void
    {
        $this->writeWriteback();
        $this->writeToken();
        Http::fake(['*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 999, 'workflow_stage_id' => 49]])]);

        $this->handle($this->payload());   // refused (belongs-to-board guard) — no throw, no move

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    }

    // --- card#7138 / DL-292: the twins ADOPT the coord-move copy's predicate
    //     (`is_numeric` + `(int)`), which WIDENS what this arm accepts. The old
    //     `$boardId !== $mapping->boardId` compared against a `readonly int` and did no
    //     type juggling, so it refused ANY non-int `board_id` — including a numeric
    //     string or a JSON float naming the mapped board ITSELF. That was a false
    //     refusal on correct work, alerting `card_not_on_mapped_board` for a card that
    //     is on the mapped board. Latent, not live: kanban returns `board_id` as a JSON
    //     integer today, so these pin the NEW accepted set rather than fixing a live
    //     symptom. Revert the predicate ⇒ no PATCH ⇒ RED. ---

    public function test_a_numeric_string_board_id_naming_the_mapped_board_moves(): void
    {
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => '8', 'workflow_stage_id' => 49]])   // GET
                ->push(['data' => ['id' => 5]]),                                                 // PATCH
        ] + $this->fakePreload());

        $this->handle($this->payload());

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && $r->data() === ['workflow_stage_id' => 52]);
    }

    public function test_a_float_board_id_naming_the_mapped_board_moves(): void
    {
        // The body is a RAW JSON string, deliberately: `json_encode(8.0)` emits `8`, so
        // an array fixture would round-trip to an int and this method would silently
        // test the case above again. `8.0` on the wire decodes to float(8) — a vector
        // the old `!==` refused, and the only way to actually put one in front of the
        // handler.
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            '*/tasks/5.json' => Http::sequence()
                ->push('{"data":{"id":5,"board_id":8.0,"workflow_stage_id":49}}')   // GET
                ->push(['data' => ['id' => 5]]),                                     // PATCH
        ] + $this->fakePreload());

        $this->handle($this->payload());

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && $r->data() === ['workflow_stage_id' => 52]);
    }

    public function test_a_board_id_that_casts_onto_the_mapped_board_but_is_not_numeric_is_refused(): void
    {
        // The `is_numeric` disjunct's vector, on THIS arm now that it shares the
        // predicate: `'8abc'` is not numeric, and `(int) '8abc' === 8` — so without the
        // disjunct the cast compare would AGREE with the mapped board and move a card
        // that is not on it. Widening the accepted set to numeric strings is exactly
        // what makes this leg load-bearing here.
        $this->writeWriteback();
        $this->writeToken();
        Http::fake(['*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => '8abc', 'workflow_stage_id' => 49]])]);

        $this->handle($this->payload());

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
        ] + $this->fakePreload());

        $this->handle($this->payload());   // no exception

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH');   // move attempted; 4xx swallowed
    }

    public function test_move_4xx_logs_the_server_body_and_drops_the_guessed_cause(): void
    {
        // card#4409: a 4xx move refusal must hand over what kanban actually said (the
        // response body) instead of asserting a config cause the handler never checked.
        // The real DL-204 incident was a 403 authz refusal mislabelled as a
        // writeback.json stage-map typo — status alone couldn't tell them apart.
        $this->writeWriteback();
        $this->writeToken();
        Log::spy();
        Http::fake([
            '*/boards/8/preload.json' => Http::response(['data' => ['workflows' => [['stages' => [['id' => 49, 'position' => 3], ['id' => 52, 'position' => 5]]]]]]),
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 49]])   // GET ok
                ->push(['message' => 'you are not authorized to move this card'], 403),         // PATCH 403 authz
        ]);

        $this->handle($this->payload());

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $msg, array $ctx) => str_contains($msg, 'kanban refused the move')
            && ! str_contains($msg, 'check the writeback.json stage')
            && $ctx['status'] === 403
            && $ctx['board'] === 8
            && str_contains($ctx['body'], 'not authorized to move this card'));
    }

    public function test_stamp_4xx_logs_the_server_body_and_drops_the_custom_field_guess(): void
    {
        // card#4409: the stamp refusal previously asserted "the board likely lacks the
        // dl_number/pr_number custom field" — a cause it never verified. The card is
        // already at the target stage (self-heal path), so only the stamp PATCH fires.
        $this->writeWriteback();
        $this->writeToken();
        Log::spy();
        Http::fake([
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 52]])   // GET: already at target
                ->push(['message' => 'forbidden: token cannot write custom fields'], 403),      // PATCH stamp 403
        ]);

        $this->handle($this->payload(['stamp_dl' => 'DL-42', 'stamp_pr' => 77]));

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $msg, array $ctx) => str_contains($msg, 'stamp refused by kanban')
            && ! str_contains($msg, 'board likely lacks')
            && $ctx['status'] === 403
            && str_contains($ctx['body'], 'cannot write custom fields'));
    }

    public function test_move_4xx_scrubs_a_credential_echoed_in_the_body(): void
    {
        // A kanban error body that echoes the request could carry the writeback token;
        // the refusal log must scrub it before persisting.
        $this->writeWriteback();
        $this->writeToken();
        Log::spy();
        Http::fake([
            '*/boards/8/preload.json' => Http::response(['data' => ['workflows' => [['stages' => [['id' => 49, 'position' => 3], ['id' => 52, 'position' => 5]]]]]]),
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 49]])
                ->push(['message' => 'denied', 'echo' => ['token' => 'wb-SECRET-TOKEN-abc123']], 403),
        ]);

        $this->handle($this->payload());

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $msg, array $ctx) => str_contains($msg, 'kanban refused the move')
            && ! str_contains($ctx['body'], 'wb-SECRET-TOKEN-abc123')
            && str_contains($ctx['body'], '[REDACTED]'));
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
            && $r->data() === ['workflow_stage_id' => 49]);
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

    // --- Contract PR #113: Held promote-from + pinned-card opt-out on `started` ---

    public function test_started_promotes_a_held_card_when_held_is_in_promote_from(): void
    {
        // The Held-promote default is delivered by carrying the Held stage (51) in
        // started_from_stages — the mechanism is unchanged, only the config default.
        $this->writeWriteback(['started' => 49], ['started_from_stages' => [46, 47, 51]]);
        $this->writeToken();
        Http::fake([
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 51]])   // GET (Held)
                ->push(['data' => ['id' => 5]]),
        ]);

        $this->handle($this->payload(['outcome' => 'started']));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r->data() === ['workflow_stage_id' => 49]);
    }

    public function test_started_refused_when_card_has_a_block_reason(): void
    {
        // Pinned opt-out: a non-empty block_reason blocks the promotion regardless
        // of the card being in an allowed promote-from stage.
        $this->writeWriteback(['started' => 49], ['started_from_stages' => [46, 47, 51]]);
        $this->writeToken();
        Http::fake(['*/tasks/5.json' => Http::response(['data' => [
            'id' => 5, 'board_id' => 8, 'workflow_stage_id' => 51, 'block_reason' => 'waiting on upstream',
        ]])]);

        $this->handle($this->payload(['outcome' => 'started']));

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    }

    public function test_started_refused_when_card_has_no_automove_tag(): void
    {
        // Pinned opt-out via the `no-automove` tag — a human-pinned card is never
        // auto-promoted even from an allowed stage.
        $this->writeWriteback(['started' => 49], ['started_from_stages' => [46, 47, 51]]);
        $this->writeToken();
        Http::fake(['*/tasks/5.json' => Http::response(['data' => [
            'id' => 5, 'board_id' => 8, 'workflow_stage_id' => 51, 'tags' => ['triaged', 'no-automove'],
        ]])]);

        $this->handle($this->payload(['outcome' => 'started']));

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    }

    public function test_started_promotes_a_held_card_with_non_pinning_tags_and_blank_block_reason(): void
    {
        // Boundary: a whitespace-only block_reason must NOT pin (the trim guard), and a
        // tag list without `no-automove` must NOT pin (the exact-match guard). One case
        // pins both against a regression to "any non-null string" / substring/any-tag.
        $this->writeWriteback(['started' => 49], ['started_from_stages' => [46, 47, 51]]);
        $this->writeToken();
        Http::fake([
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 51, 'block_reason' => '   ', 'tags' => ['triaged']]])
                ->push(['data' => ['id' => 5]]),
        ]);

        $this->handle($this->payload(['outcome' => 'started']));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r->data() === ['workflow_stage_id' => 49]);
    }

    // --- card#8289: the pin is consulted on EVERY outcome, not just `started` ---

    public function test_merged_move_refused_when_card_is_pinned(): void
    {
        // THE card#8289 defect: the pin consult sat inside `if ($outcome === 'started')`,
        // so a merge PATCHed a human-pinned card straight to the shipped stage. Every
        // guard above this one passes (card on the mapped board, forward move, no
        // corroboration doubt), which is exactly why two correct consumers kept the
        // guard reading as working. Revert the hoist => a PATCH is sent => RED.
        $this->writeAllOutcomes();
        $this->fakeStageOrderAndCard(49, ['block_reason' => 'waiting on upstream', 'tags' => ['triaged', 'no-automove']]);

        $this->handle($this->payload(['outcome' => 'merged']));

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    }

    public function test_merged_move_refused_when_card_carries_only_a_no_automove_tag(): void
    {
        // The tag half of the predicate on the merge path — the `started` arm has had
        // both halves covered since PR #113; the merge path had neither.
        $this->writeAllOutcomes();
        $this->fakeStageOrderAndCard(49, ['tags' => ['triaged', 'no-automove']]);

        $this->handle($this->payload(['outcome' => 'merged']));

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    }

    /**
     * The ruling this fix encodes: a pin holds the card's STAGE against every outcome,
     * not a subset. A pin honoured on some moves and not others is the same defect one
     * layer down, so all four config outcomes are covered rather than one
     * representative. `closed_unmerged` is in the set deliberately: it is the lone
     * legitimately-BACKWARD outcome, and a hold holds in both directions.
     *
     * A DATA PROVIDER rather than a loop, because `Http::fake()` MERGES its stubs and
     * the FIRST matching one answers — a second `fake()` in one test body cannot replace
     * the card the first one stubbed, so a looped control/subject pair silently reuses
     * the control's unpinned card. One case per test instance is the only clean reset.
     *
     * @return array<string, array{string, int, int}> outcome, current stage, target stage
     */
    public static function pinnedOutcomes(): array
    {
        // Current stages are chosen so the DL-163 no-regression guard passes every case:
        // 49 is behind 50/52/53, and `closed_unmerged` — the backward outcome — runs from
        // 50 down to 49. Neither 49 nor 50 is terminal, so nothing here is guard-refused.
        return [
            'opened' => ['opened', 49, 50],
            'merged' => ['merged', 49, 52],
            'merged_to_main' => ['merged_to_main', 49, 53],
            'closed_unmerged' => ['closed_unmerged', 50, 49],
        ];
    }

    #[DataProvider('pinnedOutcomes')]
    public function test_an_unpinned_card_moves_on_this_outcome(string $outcome, int $from, int $target): void
    {
        // The CONTROL for the case below. `assertNotSent` is satisfied by ANY refusal,
        // and the DL-163 guard sits two statements past the pin consult — so without a
        // control that moves, the subject's silence is not evidence about the PIN.
        $this->writeAllOutcomes();
        $this->fakeStageOrderAndCard($from);

        $this->handle($this->payload(['outcome' => $outcome]));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r->data() === ['workflow_stage_id' => $target]);
    }

    #[DataProvider('pinnedOutcomes')]
    public function test_a_pinned_card_does_not_move_on_this_outcome(string $outcome, int $from, int $target): void
    {
        $this->writeAllOutcomes();
        $this->fakeStageOrderAndCard($from, ['block_reason' => 'parked by hand']);

        $this->handle($this->payload(['outcome' => $outcome]));

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH' && $r->data() === ['workflow_stage_id' => $target]);
    }

    public function test_non_revival_reopened_refuses_a_pinned_card(): void
    {
        // `reopened` is the outcome with an operator-ruled pin OVERRIDE (DL-195), but
        // that override is scoped to the REVIVAL — a card sitting in the mapped
        // `closed_unmerged` abandon stage. Elsewhere DL-195 says a `reopened` behaves
        // exactly like `opened`, so here it is held exactly like one. The companion
        // `test_reopened_pinned_card_is_revived_and_alerts` pins the override arm; this
        // pins the boundary between them.
        $this->writeReviveConfig();
        $this->fakeReviveStageOrderAndCard(49, ['tags' => ['no-automove']]);   // 49 is not the abandon stage (77)

        $this->handle($this->payload(['outcome' => 'reopened']));

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    }

    public function test_pinned_merge_refusal_alerts(): void
    {
        // A hold nobody can see fire is a hold nobody can debug: the refusal pairs its
        // durable log with a live signal through the one primitive every permanent
        // refusal here uses (DL-274), under the reason the `started` arm already used.
        $this->writeWritebackWithAlert(['opened' => 50, 'merged' => 52, 'merged_to_main' => 53, 'closed_unmerged' => 49]);
        $this->writeToken();
        Log::spy();
        Http::fake([
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            '*/boards/8/preload.json' => Http::response(['data' => ['workflows' => [['id' => 11, 'stages' => [
                ['id' => 49, 'position' => 3072.0],
                ['id' => 52, 'position' => 5120.0],
            ]]]]]),
            '*/tasks/5.json' => Http::response(['data' => [
                'id' => 5, 'board_id' => 8, 'workflow_stage_id' => 49, 'tags' => ['no-automove'],
            ]]),
        ]);

        $this->handle($this->payload(['outcome' => 'merged']));

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
        Http::assertSent(fn (Request $r) => $this->isAlertPush($r)
            && $r['type'] === 'writeback_move_failed'
            && $r['reason'] === 'pinned_no_automove'
            && $r['repo'] === 'owner/repo'
            && $r['card_id'] === 5);
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_contains((string) $msg, 'move refused')
            && str_contains((string) $msg, 'pinned'))->once();
    }

    public function test_pinned_merge_still_stamps_the_correlation_refs_it_refuses_to_move_on(): void
    {
        // The ruling on the second half: the pin governs the card's STAGE, not its
        // correlation refs. Dropping the stamp too would strand the card OUTSIDE
        // `bridge:reconcile`'s population — the reconciler only reconciles a card that
        // carries a resolvable (repo, PR) — so the backstop could never repair the move
        // once the human lifted the pin, and GitHub delivers the merge exactly once.
        // Stamping keeps the hold a PAUSE instead of a severance.
        $this->writeAllOutcomes();
        $this->fakeStageOrderAndCard(49, ['tags' => ['no-automove'], 'payload' => []]);

        $this->handle($this->payload([
            'outcome' => 'merged',
            'stamp_pr' => 148,
            'stamp_pr_url' => 'https://github.com/owner/repo/pull/148',
        ]));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && $r->data() === ['payload' => ['pr_number' => 148, 'pr_url' => 'https://github.com/owner/repo/pull/148']]);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH' && array_key_exists('workflow_stage_id', $r->data()));
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
        ] + $this->fakePreload());

        $this->handle($this->payload(['board_id' => 999]));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r->data() === ['workflow_stage_id' => 52]);
    }

    // --- #2935: no-regression guard for the four PR-driven outcomes ---

    private function writeAllOutcomes(): void
    {
        $this->writeWriteback(['opened' => 50, 'merged' => 52, 'merged_to_main' => 53, 'closed_unmerged' => 49]);
        $this->writeToken();
    }

    /**
     * Board-8 order for the guard: In-Progress 49 < In-Review 50 < Shipped 52 < Released 53.
     *
     * @param  array<string, mixed>  $cardExtra  extra card fields (block_reason / tags / payload)
     */
    private function fakeStageOrderAndCard(int $currentStage, array $cardExtra = []): void
    {
        Http::fake([
            '*/boards/8/preload.json' => Http::response(['data' => ['workflows' => [['id' => 11, 'stages' => [
                ['id' => 49, 'position' => 3072.0],
                ['id' => 50, 'position' => 4096.0],
                ['id' => 52, 'position' => 5120.0],
                ['id' => 53, 'position' => 6144.0],
            ]]]]]),
            '*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => $currentStage] + $cardExtra]),
        ]);
    }

    public function test_opened_does_not_regress_a_released_card(): void
    {
        // The core reported bug: a release PR whose title carries the card's DL-NNN
        // (or a redelivered `opened`) fires opened→In-Review on a card already at
        // Released — must be refused (no backward move), not silently applied.
        $this->writeAllOutcomes();
        $this->fakeStageOrderAndCard(53);   // Released

        $this->handle($this->payload(['outcome' => 'opened']));

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    }

    public function test_opened_still_promotes_a_card_forward(): void
    {
        // The guard must NOT block a legitimate forward move.
        $this->writeAllOutcomes();
        $this->fakeStageOrderAndCard(49);   // In-Progress → In-Review (50)

        $this->handle($this->payload(['outcome' => 'opened']));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r->data() === ['workflow_stage_id' => 50]);
    }

    public function test_merged_does_not_regress_a_released_card(): void
    {
        // A redelivered `merged` (PR merged to a non-main base) on an already-Released
        // card would drag Released(53)→Shipped(52) — refused.
        $this->writeAllOutcomes();
        $this->fakeStageOrderAndCard(53);

        $this->handle($this->payload(['outcome' => 'merged']));

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    }

    public function test_closed_unmerged_returns_an_in_review_card_to_in_progress(): void
    {
        // closed_unmerged is the ONE legitimately-backward outcome: an abandoned PR
        // returns its In-Review card to In-Progress. The guard must allow it.
        $this->writeAllOutcomes();
        $this->fakeStageOrderAndCard(50);   // In-Review → In-Progress (49)

        $this->handle($this->payload(['outcome' => 'closed_unmerged']));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r->data() === ['workflow_stage_id' => 49]);
    }

    public function test_closed_unmerged_does_not_resurrect_a_released_card(): void
    {
        // ...but a stale PR closing long after the card shipped/released must NOT
        // pull it back to In-Progress (current stage is at/past the terminal floor).
        $this->writeAllOutcomes();
        $this->fakeStageOrderAndCard(53);   // Released

        $this->handle($this->payload(['outcome' => 'closed_unmerged']));

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    }

    public function test_no_regression_guard_fails_open_when_stage_order_is_unavailable(): void
    {
        // The guard is a safety net layered on the existing behavior — it must never
        // BREAK the writeback. When the board order can't be read, the move proceeds.
        $this->writeAllOutcomes();
        Http::fake([
            '*/boards/8/preload.json' => Http::response('upstream error', 500),
            '*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 53]]),
        ]);

        $this->handle($this->payload(['outcome' => 'opened']));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r->data() === ['workflow_stage_id' => 50]);
    }

    public function test_board_stage_order_preload_is_read_once_across_cards_on_one_instance(): void
    {
        // #3575: a bundled PR/DL moving N cards on the same board dispatches N
        // targets through the SAME singleton handler in one request. The
        // no-regression guard's `/preload.json` read must be memoized to one call
        // per board, not repeated per card — while every card still moves.
        $this->writeAllOutcomes();
        Http::fake([
            '*/boards/8/preload.json' => Http::response(['data' => ['workflows' => [['id' => 11, 'stages' => [
                ['id' => 49, 'position' => 3072.0],
                ['id' => 50, 'position' => 4096.0],
                ['id' => 52, 'position' => 5120.0],
                ['id' => 53, 'position' => 6144.0],
            ]]]]]),
            '*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 49]]),
            '*/tasks/6.json' => Http::response(['data' => ['id' => 6, 'board_id' => 8, 'workflow_stage_id' => 49]]),
            // This leg dispatches the handler DIRECTLY (it needs one instance across two
            // targets), so it does not pass through `handle()` and stubs the card#8375
            // board-scope lookup itself. Both cards are asked about, one request each.
        ] + ScopeLookupStub::onMappedBoard(8));

        $handler = new KanbanMoveCardHandler;
        $agent = AgentConfig::fromArray('prod-agent', ['identity' => ['kanban_user_id' => 1], 'subscriptions' => []]);
        $handler->handle(ReactionTarget::make('kanban_move_card', '5', payload: $this->payload(['card_id' => 5, 'outcome' => 'opened'])), $agent);
        $handler->handle(ReactionTarget::make('kanban_move_card', '6', payload: $this->payload(['card_id' => 6, 'outcome' => 'opened'])), $agent);

        $preloadReads = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), '/preload.json'))
            ->count();
        $this->assertSame(1, $preloadReads, 'the board stage-order preload must be read once, not once per card');

        // Both cards still moved forward (49 In-Progress → 50 In-Review) — and to
        // their OWN card URLs, not the same one twice.
        $movedCards = collect(Http::recorded())
            ->filter(fn ($pair) => $pair[0]->method() === 'PATCH' && $pair[0]->data() === ['workflow_stage_id' => 50])
            ->map(fn ($pair) => $pair[0]->url())
            ->sort()
            ->values()
            ->all();
        $this->assertCount(2, $movedCards);
        $this->assertStringContainsString('/tasks/5', $movedCards[0]);
        $this->assertStringContainsString('/tasks/6', $movedCards[1]);
    }

    // --- FR-4: writeback.alert_channel (loud per-event signal on a permanent move-failure) ---

    private const ALERT_URL = 'http://127.0.0.1:9931/';

    private function writeWritebackWithAlert(array $stages = ['merged' => 52], array $extra = []): void
    {
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'alert_channel' => ['url' => self::ALERT_URL],
            'mappings' => ['owner/repo' => ['board_id' => 8, 'stages' => $stages] + $extra],
        ]));
    }

    private function isAlertPush(Request $r): bool
    {
        return $r->method() === 'POST' && str_starts_with($r->url(), self::ALERT_URL);
    }

    public function test_alert_channel_pushes_one_signal_on_a_warning_branch(): void
    {
        // A permanent move-failure on a Log::warning branch (card not on mapped
        // board) emits exactly one loud signal to the configured alert channel.
        $this->writeWritebackWithAlert();
        $this->writeToken();
        Log::spy();
        Http::fake([
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            '*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 999, 'workflow_stage_id' => 49]]),
        ]);

        $this->handle($this->payload());   // card on wrong board → refused

        Http::assertSent(fn (Request $r) => $this->isAlertPush($r)
            && $r['type'] === 'writeback_move_failed'
            && $r['reason'] === 'card_not_on_mapped_board'
            && $r['repo'] === 'owner/repo'
            && $r['outcome'] === 'merged'
            && $r['card_id'] === 5);
        // The push is ADDITIVE to the durable log — the warning fires regardless.
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $msg) => str_contains($msg, 'not on the mapped board'));
    }

    public function test_alert_channel_signals_card_id_branch_with_non_scalar_repo_without_throwing(): void
    {
        // Branch #1 (card_id not int) passes the best-available repo/outcome — which
        // at that point are un-validated payload values. A non-string (e.g. array)
        // repo must NOT throw an "Array to string conversion" out of the handler.
        $this->writeWritebackWithAlert();
        $this->writeToken();
        Http::fake([self::ALERT_URL.'*' => Http::response(['ok' => true])]);

        $this->handle(['card_id' => 'nope', 'repo' => ['not' => 'a string'], 'outcome' => 'merged']);

        Http::assertSent(fn (Request $r) => $this->isAlertPush($r)
            && $r['reason'] === 'card_id_not_int'
            && $r['repo'] === ''           // non-string coerced to empty, not a crash
            && $r['card_id'] === null);
    }

    public function test_alert_channel_unset_pushes_nothing(): void
    {
        // No alert_channel ⇒ log-only (unchanged behavior).
        $this->writeWriteback();   // no alert_channel key
        $this->writeToken();
        Http::fake([
            '*://127.0.0.1:*/*' => Http::response(['ok' => true]),
            '*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 999, 'workflow_stage_id' => 49]]),
        ]);

        $this->handle($this->payload());

        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), '127.0.0.1'));
    }

    public function test_alert_channel_silent_on_an_info_branch(): void
    {
        // The Log::info "not tracked" branches (#4 no mapping / #5 no stage) stay
        // QUIET — no alert even with a channel configured.
        $this->writeWritebackWithAlert(['merged' => 52]);   // only 'merged' mapped
        $this->writeToken();
        Http::fake([self::ALERT_URL.'*' => Http::response(['ok' => true])]);

        $this->handle($this->payload(['outcome' => 'closed_unmerged']));   // no stage for outcome (info branch)

        Http::assertNotSent(fn (Request $r) => $this->isAlertPush($r));
    }

    public function test_alert_channel_dedups_repeated_identical_signatures(): void
    {
        // The SAME (repo, outcome, reason) firing on N events alerts exactly once
        // (the O_EXCL dedup marker).
        $this->writeWritebackWithAlert();
        $this->writeToken();
        Http::fake([
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            '*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 999, 'workflow_stage_id' => 49]]),
        ]);

        $this->handle($this->payload());
        $this->handle($this->payload());
        $this->handle($this->payload());

        $alertPushes = collect(Http::recorded())->filter(fn ($pair) => $this->isAlertPush($pair[0]))->count();
        $this->assertSame(1, $alertPushes, 'an identical (repo, outcome, reason) must alert exactly once');
    }

    public function test_alert_channel_failure_does_not_throw_out_of_the_handler(): void
    {
        // Best-effort: the alert push failing (HTTP 500 / connection refused) must
        // never throw out of the handler — an unmovable card must not 5xx-storm.
        $this->writeWritebackWithAlert();
        $this->writeToken();
        Http::fake([
            self::ALERT_URL.'*' => Http::response('channel down', 500),
            '*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 999, 'workflow_stage_id' => 49]]),
        ]);

        $this->handle($this->payload());   // no exception escapes

        Http::assertSent(fn (Request $r) => $this->isAlertPush($r));   // push WAS attempted
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');   // move still a no-op
    }

    public function test_alert_channel_failed_push_releases_the_dedup_marker_for_a_retry(): void
    {
        // A FAILED first push must not permanently silence the signature — the
        // marker is released so a later redelivery re-attempts (claim-before-push
        // can't turn one dropped packet into forever-silence). First push 500s →
        // marker released; second delivery → a second push is attempted.
        $this->writeWritebackWithAlert();
        $this->writeToken();
        Http::fake([
            self::ALERT_URL.'*' => Http::sequence()
                ->push('channel down', 500)        // first attempt fails
                ->push(['ok' => true], 200),       // second attempt succeeds
            '*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 999, 'workflow_stage_id' => 49]]),
        ]);

        $this->handle($this->payload());
        $this->handle($this->payload());

        $alertPushes = collect(Http::recorded())->filter(fn ($pair) => $this->isAlertPush($pair[0]))->count();
        $this->assertSame(2, $alertPushes, 'a failed first push must re-arm the signature for the next delivery');
    }

    // --- card#5288: the getCard 4xx refusal splits 404 from 403 into distinct reasons.
    //     What each status establishes is owned by RefusalContext::readReason()'s docblock. ---

    public function test_getcard_404_and_403_produce_distinct_reasons_and_do_not_collapse_the_dedup(): void
    {
        // The DL-009 belongs-to-mapped-board guard reads board_id OUT of the card, so a
        // card this token cannot READ returns at this branch and never reaches the guard —
        // the reason string is the operator's ONLY signal for the case that guard exists to
        // refuse. A single collapsed `getcard_4xx` also DEDUPED the two statuses against
        // each other within one (repo, outcome): whichever arrived second alerted zero times.
        //
        // ⚑ The 403 slug is the NARROWED one (card#8375): the board-scoped check has already
        // read this id back off the mapped board, so a foreign card id is excluded here and
        // the slug names the one cause left. `kanban_block_reason`, which makes no such
        // check, still emits the two-cause slug — pinned in its own class.
        $this->writeWritebackWithAlert();
        $this->writeToken();
        Http::fake([
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            '*/tasks/5.json' => Http::sequence()
                ->push(['error' => 'not found'], 404)
                ->push(['message' => 'forbidden'], 403),
        ]);

        $this->handle($this->payload());   // 404
        $this->handle($this->payload());   // 403 — same (repo, outcome), different reason

        $reasons = collect(Http::recorded())
            ->filter(fn ($pair) => $this->isAlertPush($pair[0]))
            ->map(fn ($pair) => $pair[0]['reason'])
            ->values()->all();
        $this->assertSame(['getcard_404_no_such_card', 'getcard_403_token_scope'], $reasons);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');   // both still swallow — no move, no 5xx retry
    }

    public function test_getcard_other_4xx_keeps_the_generic_catchall_reason(): void
    {
        // Only 404/403 are named hypotheses; every other 4xx stays `getcard_4xx` (the
        // catch-all), so the split adds vocabulary without silently re-labelling the rest.
        $this->writeWritebackWithAlert();
        $this->writeToken();
        Http::fake([
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            '*/tasks/5.json' => Http::response(['message' => 'unprocessable'], 422),
        ]);

        $this->handle($this->payload());

        Http::assertSent(fn (Request $r) => $this->isAlertPush($r) && $r['reason'] === 'getcard_4xx');
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    }

    public function test_getcard_403_withholds_the_card_id_from_the_channel_and_keeps_it_in_the_log(): void
    {
        // card#7846 / DL-314 — BOTH HALVES OF ONE RULE, which is why they are one test:
        // the channel event must NOT carry the id, and the log line MUST. Splitting them
        // would let a "fix" that drops the id from both places pass the half it kept.
        //
        // The id reaching this arm was parsed as a literal out of author-controlled text
        // (`card#NNNN` in a PR title or branch ref) against a kanban id space that is
        // GLOBAL across every board on the instance, and the read that would have proved
        // it ours just FAILED. Observed live with exactly this id: a push on one install
        // alerted `card_id: 7756`, a card on a board another install owns; only the
        // write-side 403 stopped a move. The local operator legitimately needs the id to
        // diagnose, so it stays in the log.
        //
        // ⚑ WHAT THE WITHHOLDING IS KEYED ON DID NOT CHANGE WITH card#8375, and that is the
        // point of keeping this leg as it stands: it is keyed on THE READ FAILED, not on the
        // foreign-id hypothesis. Here the scope check PASSED (the fallback stub says 7756 is
        // on the mapped board) and `getCard` still 403'd, so this bridge holds an id whose
        // card it could not read — the channel copy stays empty either way. The scope-refused
        // shape, where the id really is not ours, is `WritebackTenantScopeTest`'s subject.
        $this->writeWritebackWithAlert();
        $this->writeToken();
        Log::spy();
        Http::fake([
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            '*/tasks/7756.json' => Http::response(['message' => 'forbidden'], 403),
        ]);

        $this->handle($this->payload(['card_id' => 7756]));

        $pushes = collect(Http::recorded())
            ->filter(fn ($pair) => $this->isAlertPush($pair[0]))
            ->map(fn ($pair) => $pair[0]->body())
            ->values()->all();

        $this->assertCount(1, $pushes, 'the 403 arm must still alert — withholding the id is not a reason to go quiet');
        // The RAW line, not the decoded field: the id must not be recoverable from ANY
        // key of the body (a future `context`/`message` key would re-leak it while a
        // `card_id === null` assertion stayed green).
        $this->assertStringNotContainsString('7756', $pushes[0], 'the alert channel carries a card id this bridge could not read');
        $body = json_decode($pushes[0], true);
        // `array_key_exists`, never `??`: the expected VALUE here is null, and `??` cannot
        // tell a null value from an absent key — it would report a dropped key as correct.
        $this->assertArrayHasKey('card_id', $body, 'the alert body must still CARRY a card_id key, valued null');
        $this->assertNull($body['card_id']);
        $this->assertTrue($body['card_id_withheld'] ?? false, 'a bare null reads as "the arm had no id"; the omission must say it is deliberate');
        $this->assertSame('getcard_403_token_scope', $body['reason']);

        // …and the other half: the operator's own surface keeps it.
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $msg, array $ctx) => ($ctx['card_id'] ?? null) === 7756);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');   // still swallowed — no move, no 5xx retry
    }

    public function test_getcard_403_log_says_the_foreign_card_id_hypothesis_is_excluded_here(): void
    {
        // ⚑ THIS LEG REVERSED WITH card#8375, and the reversal is the deliverable. It used to
        // require the text to name BOTH causes a 403 could have — a foreign install's card id,
        // or this token's own scope — because nothing in the status could choose between them
        // (DL-314). The board-scoped check now runs BEFORE this read and has already found
        // this id on the mapped board, so the foreign half is ruled out by a measurement and
        // the message must say so: a text still offering both would send the operator hunting
        // a cause this arm can no longer have. The two-cause wording lives on in
        // `kanban_block_reason`, which makes no such check — pinned in its own class, so
        // deleting it here does not retire the assertion that it exists somewhere.
        $this->writeWriteback();
        $this->writeToken();
        Log::spy();
        Http::fake(['*/tasks/5.json' => Http::response(['message' => 'forbidden'], 403)]);

        $this->handle($this->payload());

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $msg, array $ctx) => str_contains($msg, 'getCard 403')
            && str_contains($msg, 'board-scoped check')
            && str_contains($msg, 'EXCLUDED')
            && ! str_contains($msg, 'cannot tell the two apart')
            && $ctx['status'] === 403
            && $ctx['card_id'] === 5);
    }

    public function test_getcard_404_log_says_no_such_card(): void
    {
        $this->writeWriteback();
        $this->writeToken();
        Log::spy();
        Http::fake(['*/tasks/5.json' => Http::response(['error' => 'not found'], 404)]);

        $this->handle($this->payload());

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $msg, array $ctx) => str_contains($msg, 'getCard 404')
            && str_contains($msg, 'no such card')
            && $ctx['status'] === 404
            && $ctx['card_id'] === 5);
    }

    // --- card#5312 / DL-274: the moveCard + stamp refusal arms became live signals ---

    /**
     * The stage-order preload the no-regression guard reads for a `merged` outcome.
     *
     * ⚑ No `swimlanes` key, deliberately: this handler's guard reads the stage order only, and
     * the ABSENCE is itself a distinct input downstream ({@see PreloadStub}).
     *
     * @return array<string, mixed>
     */
    private function fakePreload(): array
    {
        return PreloadStub::stub(8, [49 => 3, 52 => 5]);
    }

    public function test_move_403_alerts_with_the_read_but_not_write_reason(): void
    {
        // THE card#5312 case: a scope-narrowed token READS the card fine — so the getCard
        // arm, the only one that alerted before DL-274, stays quiet — and is refused on the
        // PATCH. The card silently stopped moving and only a log line recorded it.
        $this->writeWritebackWithAlert();
        $this->writeToken();
        Log::spy();
        Http::fake($this->fakePreload() + [
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 49]])   // GET ok — read works
                ->push(['message' => 'this token cannot write board 8'], 403),                  // PATCH refused
        ]);

        $this->handle($this->payload());

        Http::assertSent(fn (Request $r) => $this->isAlertPush($r)
            && $r['type'] === 'writeback_move_failed'
            && $r['reason'] === 'movecard_403_not_writable_by_this_token'
            && $r['repo'] === 'owner/repo'
            && $r['outcome'] === 'merged'
            && $r['card_id'] === 5);
        // The durable record fires independently of the push (log first, then alert).
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $msg, array $ctx) => str_contains($msg, 'kanban refused the move')
            && $ctx['status'] === 403);
    }

    public function test_move_other_4xx_keeps_the_generic_catchall_reason(): void
    {
        // Only 403/404 are named hypotheses; the rest stay `movecard_4xx` so the split
        // adds vocabulary without silently re-labelling every other refusal.
        $this->writeWritebackWithAlert();
        $this->writeToken();
        Http::fake($this->fakePreload() + [
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 49]])
                ->push(['error' => 'invalid stage'], 422),
        ]);

        $this->handle($this->payload());

        Http::assertSent(fn (Request $r) => $this->isAlertPush($r) && $r['reason'] === 'movecard_4xx');
    }

    public function test_move_404_alerts_that_the_card_is_gone(): void
    {
        $this->writeWritebackWithAlert();
        $this->writeToken();
        Http::fake($this->fakePreload() + [
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 49]])
                ->push(['error' => 'gone'], 404),
        ]);

        $this->handle($this->payload());

        Http::assertSent(fn (Request $r) => $this->isAlertPush($r) && $r['reason'] === 'movecard_404_no_such_card');
    }

    public function test_stamp_4xx_alerts_on_the_self_heal_path(): void
    {
        // The card is already at the target stage, so ONLY the stamp PATCH fires — a
        // board with no dl_number custom field refuses it, and the card is left without
        // the correlation refs release-promote later looks for.
        $this->writeWritebackWithAlert();
        $this->writeToken();
        Log::spy();
        Http::fake([
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 52]])   // already at target
                ->push(['message' => 'unknown field dl_number'], 422),                          // stamp refused
        ]);

        $this->handle($this->payload(['stamp_dl' => 'DL-42', 'stamp_pr' => 77]));

        Http::assertSent(fn (Request $r) => $this->isAlertPush($r)
            && $r['reason'] === 'stamp_4xx'
            && $r['outcome'] === 'merged'
            && $r['card_id'] === 5);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $msg) => str_contains($msg, 'stamp refused by kanban'));
    }

    public function test_the_move_and_stamp_arms_do_not_dedup_each_other(): void
    {
        // Both arms refuse with the SAME status inside one (repo, outcome). Without the
        // per-call verb in the reason they would share one dedup marker and whichever
        // arrived second would alert ZERO times — the collapse card#5288 already found
        // once on getCard.
        $this->writeWritebackWithAlert();
        $this->writeToken();
        Http::fake($this->fakePreload() + [
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 49]])   // event 1 GET
                ->push(['message' => 'denied'], 403)                                            // event 1 move PATCH
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 52]])   // event 2 GET (already there)
                ->push(['message' => 'denied'], 403),                                           // event 2 stamp PATCH
        ]);

        $this->handle($this->payload());
        $this->handle($this->payload(['stamp_dl' => 'DL-42']));

        $reasons = collect(Http::recorded())
            ->filter(fn ($pair) => $this->isAlertPush($pair[0]))
            ->map(fn ($pair) => $pair[0]['reason'])
            ->values()->all();
        $this->assertSame(
            ['movecard_403_not_writable_by_this_token', 'stamp_403_not_writable_by_this_token'],
            $reasons,
        );
    }

    public function test_move_4xx_durable_log_still_fires_with_no_alert_channel(): void
    {
        // The alert is ADDITIVE. With no alert_channel the arm degrades to exactly its
        // pre-DL-274 behavior — log + no-op, no push, no throw.
        $this->writeWriteback();   // no alert_channel key
        $this->writeToken();
        Log::spy();
        Http::fake($this->fakePreload() + [
            '*://127.0.0.1:*/*' => Http::response(['ok' => true]),
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 49]])
                ->push(['message' => 'denied'], 403),
        ]);

        $this->handle($this->payload());

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $msg) => str_contains($msg, 'kanban refused the move'));
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), '127.0.0.1'));
    }

    public function test_move_5xx_still_throws_and_never_alerts(): void
    {
        // The transient/permanent split is untouched: a 5xx on the PATCH propagates for
        // redelivery, and the alert path (which is permanent-only) must not fire.
        $this->writeWritebackWithAlert();
        $this->writeToken();
        Http::fake($this->fakePreload() + [
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 49]])
                ->push('upstream error', 503),
        ]);

        try {
            $this->handle($this->payload());
            $this->fail('a 5xx on the move PATCH must propagate for redelivery');
        } catch (RequestException) {
            // expected
        }
        Http::assertNotSent(fn (Request $r) => $this->isAlertPush($r));
    }

    // --- FR #3866 / card#4852: stamp correlation refs (dl_number / pr_number / pr_url) add-if-missing ---

    public function test_card_fallback_move_stamps_missing_dl_and_pr(): void
    {
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 49, 'payload' => ['origin' => 'preemptive']]])  // GET
                ->push(['data' => ['id' => 5]])   // PATCH move
                ->push(['data' => ['id' => 5]]),  // PATCH stamp
        ] + $this->fakePreload());

        $this->handle($this->payload(['stamp_dl' => 'DL-42', 'stamp_pr' => 77]));

        // dl_number stored zero-padded (DL-%04d); pr_number as an int.
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && $r->data() === ['payload' => ['dl_number' => 'DL-0042', 'pr_number' => 77]]);
    }

    public function test_stamp_is_add_if_missing_never_overwrites_an_existing_dl(): void
    {
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            '*/tasks/5/comments.json' => Http::response(['data' => ['id' => 9]], 201),   // card#7064 card note
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 49, 'payload' => ['dl_number' => 'DL-0099']]])  // GET: dl already set
                ->push(['data' => ['id' => 5]])   // move
                ->push(['data' => ['id' => 5]]),  // stamp (pr only)
        ] + $this->fakePreload());

        $this->handle($this->payload(['stamp_dl' => 'DL-42', 'stamp_pr' => 77]));

        // only pr_number stamped — the existing dl_number is NOT overwritten.
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && $r->data() === ['payload' => ['pr_number' => 77]]);
    }

    public function test_stamp_is_add_if_missing_stamps_dl_when_only_pr_present(): void
    {
        // Inverse of the dl-present case: pr_number already set, dl_number absent →
        // stamp only dl_number, leaving the existing pr_number untouched.
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 49, 'payload' => ['pr_number' => 77]]])  // GET: pr set, dl absent
                ->push(['data' => ['id' => 5]])   // move
                ->push(['data' => ['id' => 5]]),  // stamp (dl only)
        ] + $this->fakePreload());

        $this->handle($this->payload(['stamp_dl' => 'DL-42', 'stamp_pr' => 77]));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && $r->data() === ['payload' => ['dl_number' => 'DL-0042']]);
    }

    public function test_no_stamp_patch_when_card_already_carries_both_refs(): void
    {
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 49, 'payload' => ['dl_number' => 'DL-0042', 'pr_number' => 77]]])
                ->push(['data' => ['id' => 5]]),  // move only
        ] + $this->fakePreload());

        $this->handle($this->payload(['stamp_dl' => 'DL-42', 'stamp_pr' => 77]));

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH' && isset($r['payload']));
    }

    public function test_stamp_pr_url_add_if_missing_when_card_has_no_pr_url(): void
    {
        // card#4852: a move target carrying stamp_pr_url stamps pr_url onto a card whose
        // payload.pr_url is empty — the flat DL-219 PATCH body carries it alongside pr_number.
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 49, 'payload' => []]])  // GET: no pr_url
                ->push(['data' => ['id' => 5]])   // move
                ->push(['data' => ['id' => 5]]),  // stamp
        ] + $this->fakePreload());

        $this->handle($this->payload(['stamp_pr' => 77, 'stamp_pr_url' => 'https://github.com/owner/repo/pull/77']));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && $r->data() === ['payload' => ['pr_number' => 77, 'pr_url' => 'https://github.com/owner/repo/pull/77']]);
    }

    public function test_stamp_pr_url_is_never_overwritten_when_card_already_has_one(): void
    {
        // card#4852 add-if-missing: an existing pr_url is authoritative — a stamp hint for
        // a different url must NOT overwrite it (mirrors the dl_number/pr_number guards).
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            '*/tasks/5/comments.json' => Http::response(['data' => ['id' => 9]], 201),   // card#7064 card note
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 49, 'payload' => ['pr_url' => 'https://github.com/owner/repo/pull/1']]])  // GET: pr_url already set
                ->push(['data' => ['id' => 5]]),  // move only — nothing to stamp
        ] + $this->fakePreload());

        $this->handle($this->payload(['stamp_pr_url' => 'https://github.com/owner/repo/pull/77']));

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH' && isset($r['payload']));
    }

    public function test_already_in_stage_self_heals_the_stamp_without_moving(): void
    {
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 52, 'payload' => ['origin' => 'x']]])  // GET: already in target stage 52
                ->push(['data' => ['id' => 5]]),  // stamp PATCH
        ]);

        $this->handle($this->payload(['stamp_dl' => 'DL-42', 'stamp_pr' => 77]));

        Http::assertNotSent(fn (Request $r) => isset($r['workflow_stage_id']));  // no move
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && $r->data() === ['payload' => ['dl_number' => 'DL-0042', 'pr_number' => 77]]);
    }

    public function test_stamp_permanent_4xx_is_swallowed_move_still_succeeds(): void
    {
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 49, 'payload' => []]])  // GET
                ->push(['data' => ['id' => 5]])                    // move OK
                ->push(['message' => 'unknown field'], 422),      // stamp 4xx — permanent
        ] + $this->fakePreload());

        // Must NOT throw: a permanent stamp failure is log + no-op (the move succeeded).
        $this->handle($this->payload(['stamp_dl' => 'DL-42']));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && isset($r['payload']));  // stamp was attempted
    }

    public function test_stamp_transient_5xx_propagates_for_redelivery(): void
    {
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 49, 'payload' => []]])  // GET
                ->push(['data' => ['id' => 5]])       // move OK
                ->push(['error' => 'boom'], 500),     // stamp 5xx — transient
        ] + $this->fakePreload());

        // A transient stamp failure propagates → 5xx → redelivery re-stamps (idempotent).
        $this->expectException(RequestException::class);
        $this->handle($this->payload(['stamp_dl' => 'DL-42']));
    }

    public function test_move_without_stamp_hints_sends_no_payload_patch(): void
    {
        // A DL-resolved move carries no stamp_dl/stamp_pr — stays column-only.
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 49, 'payload' => []]])
                ->push(['data' => ['id' => 5]]),
        ] + $this->fakePreload());

        $this->handle($this->payload());

        Http::assertNotSent(fn (Request $r) => isset($r['payload']));
    }

    // --- DL-194: auto-unpark a parked card on branch-cut (started + unpark_from_stages) ---

    /**
     * A `started` move from an unpark stage (51) to In-Progress (49). A single
     * non-sequence fake: GET returns the card at 51 (with the given pin signals),
     * the PATCH move returns 200. No re-GET, so this serves repeated deliveries too.
     *
     * @param  array<string, mixed>  $cardData  extra card fields (block_reason / tags)
     */
    private function fakeUnparkCard(array $cardData = [], bool $withAlert = true): void
    {
        $fakes = [
            '*/tasks/5.json' => Http::response(['data' => array_merge(
                ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 51], $cardData
            )]),
        ];
        if ($withAlert) {
            $fakes = [self::ALERT_URL.'*' => Http::response(['ok' => true])] + $fakes;
        }
        Http::fake($fakes);
    }

    /** unpark_from_stages=[51], started stage 49; started_from_stages disjoint. */
    private function unparkExtra(array $holdMarkerTags = [], array $over = []): array
    {
        return array_merge([
            'started_from_stages' => [46, 47],
            'unpark_from_stages' => [51],
            'hold_marker_tags' => $holdMarkerTags,
        ], $over);
    }

    private function alertPushCount(): int
    {
        return collect(Http::recorded())->filter(fn ($pair) => $this->isAlertPush($pair[0]))->count();
    }

    public function test_unpark_moves_a_no_automove_pinned_card_and_alerts(): void
    {
        // Row 1: a `no-automove` tag is a real pin — the move is applied anyway
        // (DL-194 reversal for the unpark stage) and the override alerts.
        $this->writeWritebackWithAlert(['started' => 49], $this->unparkExtra());
        $this->writeToken();
        Log::spy();
        $this->fakeUnparkCard(['tags' => ['triaged', 'no-automove']]);

        $this->handle($this->payload(['outcome' => 'started']));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r->data() === ['workflow_stage_id' => 49]);
        Http::assertSent(fn (Request $r) => $this->isAlertPush($r)
            && $r['type'] === 'writeback_auto_unparked'
            && $r['reason'] === 'auto_unparked'
            && $r['repo'] === 'owner/repo'
            && $r['card_id'] === 5
            && $r['from_stage'] === 51);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $msg, array $ctx) => str_contains($msg, 'auto-unparked') && $ctx['hold_signal'] === 'no_automove');
    }

    public function test_unpark_moves_a_human_block_reason_card_and_alerts(): void
    {
        // Row 2 (BLOCKER-round-1 fix): a human block_reason (≠ the draft sentinel)
        // ALWAYS alerts, even with hold_marker_tags configured.
        $this->writeWritebackWithAlert(['started' => 49], $this->unparkExtra(['gate']));
        $this->writeToken();
        Log::spy();
        $this->fakeUnparkCard(['block_reason' => 'waiting on upstream']);

        $this->handle($this->payload(['outcome' => 'started']));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r->data() === ['workflow_stage_id' => 49]);
        $this->assertSame(1, $this->alertPushCount());
        Log::shouldHaveReceived('warning')->withArgs(fn (string $msg, array $ctx) => str_contains($msg, 'auto-unparked') && $ctx['hold_signal'] === 'block_reason');
    }

    public function test_unpark_moves_a_configured_hold_tag_card_and_alerts(): void
    {
        // Row 3: a card carrying a configured hold tag (e.g. `gate`) alerts.
        $this->writeWritebackWithAlert(['started' => 49], $this->unparkExtra(['gate']));
        $this->writeToken();
        Log::spy();
        $this->fakeUnparkCard(['tags' => ['gate']]);

        $this->handle($this->payload(['outcome' => 'started']));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH');
        $this->assertSame(1, $this->alertPushCount());
        Log::shouldHaveReceived('warning')->withArgs(fn (string $msg, array $ctx) => str_contains($msg, 'auto-unparked') && $ctx['hold_signal'] === 'hold_tag');
    }

    public function test_unpark_draft_only_card_moves_without_alerting_hold_tags_configured(): void
    {
        // Row 4: block_reason == the benign draft sentinel, no other signal,
        // hold_marker_tags configured → moves, no alert (automated draft-park).
        $this->writeWritebackWithAlert(['started' => 49], $this->unparkExtra(['gate']));
        $this->writeToken();
        $this->fakeUnparkCard(['block_reason' => 'PR is in draft']);

        $this->handle($this->payload(['outcome' => 'started']));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH');
        $this->assertSame(0, $this->alertPushCount());
    }

    public function test_unpark_draft_only_card_moves_without_alerting_no_hold_tags(): void
    {
        // Row 5: draft sentinel, hold_marker_tags empty → moves, no alert (provably
        // an automated draft-park).
        $this->writeWritebackWithAlert(['started' => 49], $this->unparkExtra());
        $this->writeToken();
        $this->fakeUnparkCard(['block_reason' => 'PR is in draft']);

        $this->handle($this->payload(['outcome' => 'started']));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH');
        $this->assertSame(0, $this->alertPushCount());
    }

    public function test_unpark_bare_park_alerts_failsafe_when_no_hold_tags_configured(): void
    {
        // Row 6 (fail-safe): a card with NO recognized pin signal, hold_marker_tags
        // empty → moves and alerts (can't discriminate → an extra alert beats a miss).
        $this->writeWritebackWithAlert(['started' => 49], $this->unparkExtra());
        $this->writeToken();
        Log::spy();
        $this->fakeUnparkCard(['tags' => ['triaged']]);

        $this->handle($this->payload(['outcome' => 'started']));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH');
        $this->assertSame(1, $this->alertPushCount());
        Log::shouldHaveReceived('warning')->withArgs(fn (string $msg, array $ctx) => str_contains($msg, 'auto-unparked') && $ctx['hold_signal'] === 'failsafe');
    }

    public function test_unpark_bare_park_stays_quiet_when_hold_tags_configured(): void
    {
        // Row 7: a bare park, hold_marker_tags configured → the operator declared
        // their marker, so a card without it is trusted → moves, no alert.
        $this->writeWritebackWithAlert(['started' => 49], $this->unparkExtra(['gate']));
        $this->writeToken();
        $this->fakeUnparkCard(['tags' => ['triaged']]);

        $this->handle($this->payload(['outcome' => 'started']));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH');
        $this->assertSame(0, $this->alertPushCount());
    }

    public function test_started_pinned_card_in_a_started_from_stage_still_refused_dl178(): void
    {
        // Row 8 (DL-178 preserved): a pinned card in a started_from_stages stage that
        // is NOT an unpark stage is still refused — the reversal is scoped to unpark.
        $this->writeWritebackWithAlert(['started' => 49], [
            'started_from_stages' => [46, 47, 51],
            'unpark_from_stages' => [52],   // disjoint; the card at 51 is NOT an unpark stage
        ]);
        $this->writeToken();
        Http::fake([
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            '*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 51, 'block_reason' => 'parked']]),
        ]);

        $this->handle($this->payload(['outcome' => 'started']));

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');   // DL-178 refuse, no move
        Http::assertSent(fn (Request $r) => $this->isAlertPush($r) && $r['reason'] === 'pinned_no_automove');
        Http::assertNotSent(fn (Request $r) => $this->isAlertPush($r) && $r['type'] === 'writeback_auto_unparked');
    }

    public function test_unpark_alert_not_sent_when_the_move_4xx_noops(): void
    {
        // The alert is emitted only AFTER a confirmed move. A 4xx move-refusal
        // no-ops → no auto-unpark alert.
        $this->writeWritebackWithAlert(['started' => 49], $this->unparkExtra());
        $this->writeToken();
        Http::fake([
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 51, 'tags' => ['no-automove']]])   // GET
                ->push(['error' => 'invalid stage'], 422),                                                                // PATCH 4xx
        ]);

        $this->handle($this->payload(['outcome' => 'started']));   // no throw

        Http::assertNotSent(fn (Request $r) => $this->isAlertPush($r) && $r['type'] === 'writeback_auto_unparked');
    }

    public function test_unpark_durable_log_fires_even_with_no_alert_channel(): void
    {
        // The durable Log::warning records the override even when no alert channel is
        // configured (log = durable record; push = additive live wake).
        $this->writeWriteback(['started' => 49], $this->unparkExtra());   // NO alert_channel
        $this->writeToken();
        Log::spy();
        $this->fakeUnparkCard(['tags' => ['no-automove']], withAlert: false);

        $this->handle($this->payload(['outcome' => 'started']));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH');
        Log::shouldHaveReceived('warning')->withArgs(fn (string $msg, array $ctx) => str_contains($msg, 'auto-unparked') && $ctx['hold_signal'] === 'no_automove');
    }

    public function test_unpark_durable_log_fires_even_when_the_alert_push_is_down(): void
    {
        // Alert channel configured but the push 500s — the move still succeeds, the
        // durable log still fires, and nothing throws out of the handler.
        $this->writeWritebackWithAlert(['started' => 49], $this->unparkExtra());
        $this->writeToken();
        Log::spy();
        Http::fake([
            self::ALERT_URL.'*' => Http::response('channel down', 500),
            '*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 51, 'tags' => ['no-automove']]]),
        ]);

        $this->handle($this->payload(['outcome' => 'started']));   // no throw

        Http::assertSent(fn (Request $r) => $this->isAlertPush($r));   // push attempted
        Log::shouldHaveReceived('warning')->withArgs(fn (string $msg, array $ctx) => str_contains($msg, 'auto-unparked'));
    }

    public function test_reparked_card_re_alerts_on_a_second_unpark_no_dedup(): void
    {
        // A human re-parks the card (moves it back into the unpark stage), a fresh
        // branch-cut fires `started` again → a second alert (no per-card dedup).
        $this->writeWritebackWithAlert(['started' => 49], $this->unparkExtra());
        $this->writeToken();
        $this->fakeUnparkCard(['tags' => ['no-automove']]);   // non-sequence: every GET returns stage 51

        $this->handle($this->payload(['outcome' => 'started']));
        $this->handle($this->payload(['outcome' => 'started']));

        $this->assertSame(2, $this->alertPushCount());   // one per successful unpark, no dedup
    }

    public function test_redelivery_while_already_in_progress_does_not_re_alert(): void
    {
        // A partial-failure redelivery re-runs the handler, but the card is already at
        // the target In-Progress stage → the idempotent short-circuit returns before
        // the `started`/alert block → no second alert.
        $this->writeWritebackWithAlert(['started' => 49], $this->unparkExtra());
        $this->writeToken();
        Http::fake([
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            '*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 49, 'tags' => ['no-automove']]]),   // already at target 49
        ]);

        $this->handle($this->payload(['outcome' => 'started']));

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH' && isset($r['workflow_stage_id']));   // no move
        Http::assertNotSent(fn (Request $r) => $this->isAlertPush($r));   // no alert
    }

    public function test_notify_unpark_pushes_once_per_distinct_card_no_dedup(): void
    {
        // notifyUnpark has NO dedup — two distinct cards unparked → two pushes, each
        // carrying the writeback_auto_unparked type.
        $this->writeWritebackWithAlert(['started' => 49], $this->unparkExtra());
        $this->writeToken();
        Http::fake([
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            '*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 51, 'tags' => ['no-automove']]]),
            '*/tasks/6.json' => Http::response(['data' => ['id' => 6, 'board_id' => 8, 'workflow_stage_id' => 51, 'tags' => ['no-automove']]]),
        ]);

        $this->handle($this->payload(['card_id' => 5, 'outcome' => 'started']));
        $this->handle($this->payload(['card_id' => 6, 'outcome' => 'started']));

        $pushes = collect(Http::recorded())
            ->filter(fn ($pair) => $this->isAlertPush($pair[0]) && $pair[0]['type'] === 'writeback_auto_unparked')
            ->map(fn ($pair) => $pair[0]['card_id'])
            ->sort()->values()->all();
        $this->assertSame([5, 6], $pushes);
    }

    // --- DL-195: Won't-Do-revival (reopened → revive from the abandon stage) ---

    /**
     * closed_unmerged parks in Won't-Do (77), which sits AFTER In-Review (50) in stage
     * order, so a reopen revival is a backward move the DL-163 guard refuses without
     * DL-195. revive_on_reopen on; hold_marker_tags per arg.
     */
    private function writeReviveConfig(array $holdMarkerTags = [], bool $withAlert = false): void
    {
        $stages = ['opened' => 50, 'merged' => 52, 'merged_to_main' => 53, 'closed_unmerged' => 77];
        $extra = ['revive_on_reopen' => true, 'hold_marker_tags' => $holdMarkerTags];
        $withAlert
            ? $this->writeWritebackWithAlert($stages, $extra)
            : $this->writeWriteback($stages, $extra);
        $this->writeToken();
    }

    /** Board-8 order with Won't-Do (77) after In-Review; card at $currentStage. */
    private function fakeReviveStageOrderAndCard(int $currentStage, array $cardData = [], bool $withAlert = false): void
    {
        $fakes = [
            '*/boards/8/preload.json' => Http::response(['data' => ['workflows' => [['id' => 11, 'stages' => [
                ['id' => 49, 'position' => 3072.0],
                ['id' => 50, 'position' => 4096.0],
                ['id' => 52, 'position' => 5120.0],
                ['id' => 53, 'position' => 6144.0],
                ['id' => 77, 'position' => 7168.0],   // Won't-Do, AFTER In-Review
            ]]]]]),
            '*/tasks/5.json' => Http::response(['data' => array_merge(
                ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => $currentStage], $cardData
            )]),
        ];
        if ($withAlert) {
            $fakes = [self::ALERT_URL.'*' => Http::response(['ok' => true])] + $fakes;
        }
        Http::fake($fakes);
    }

    public function test_reopened_revives_a_card_from_the_abandon_stage(): void
    {
        // The core hole: a reopened PR whose card is parked in Won't-Do (77) is
        // revived back to In-Review (50) — the backward move the DL-163 guard refuses.
        $this->writeReviveConfig();
        $this->fakeReviveStageOrderAndCard(77);

        $this->handle($this->payload(['outcome' => 'reopened']));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r->data() === ['workflow_stage_id' => 50]);
    }

    public function test_reopened_on_a_non_abandoned_card_is_forward_only_like_opened(): void
    {
        // A reopen of a card NOT in the abandon stage behaves exactly like `opened`:
        // a forward move In-Progress(49) → In-Review(50) is allowed, no revival.
        $this->writeReviveConfig();
        $this->fakeReviveStageOrderAndCard(49);

        $this->handle($this->payload(['outcome' => 'reopened']));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r->data() === ['workflow_stage_id' => 50]);
    }

    public function test_reopened_does_not_revive_a_terminal_card(): void
    {
        // Terminal protection: a card at Released (53) is NOT in the abandon stage, so
        // a (stale) reopen targeting In-Review(50) is a backward move → refused.
        $this->writeReviveConfig();
        $this->fakeReviveStageOrderAndCard(53);

        $this->handle($this->payload(['outcome' => 'reopened']));

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    }

    public function test_reopened_redelivery_after_revival_no_double_move(): void
    {
        // A redelivered reopen after the revival: the card already sits at In-Review(50),
        // so the idempotent already-in-stage short-circuit no-ops before the guard.
        $this->writeReviveConfig();
        $this->fakeReviveStageOrderAndCard(50);   // already revived

        $this->handle($this->payload(['outcome' => 'reopened']));

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    }

    public function test_reopened_pinned_card_is_revived_and_alerts(): void
    {
        // A human-pinned Won't-Do card is revived anyway (operator chose override) and
        // the override alerts — the revived_on_reopen signal, symmetric with unpark.
        $this->writeReviveConfig(withAlert: true);
        Log::spy();
        $this->fakeReviveStageOrderAndCard(77, ['tags' => ['no-automove']], withAlert: true);

        $this->handle($this->payload(['outcome' => 'reopened']));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r->data() === ['workflow_stage_id' => 50]);
        Http::assertSent(fn (Request $r) => $this->isAlertPush($r)
            && $r['type'] === 'writeback_revived_on_reopen'
            && $r['reason'] === 'revived_on_reopen'
            && $r['repo'] === 'owner/repo'
            && $r['card_id'] === 5
            && $r['from_stage'] === 77);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $msg, array $ctx) => str_contains($msg, 'revived a card') && $ctx['hold_signal'] === 'no_automove');
    }

    public function test_reopened_bare_park_alerts_failsafe_when_no_hold_tags(): void
    {
        // A bare Won't-Do park (no recognized pin signal), no hold_marker_tags → the
        // fail-safe alerts on every revival (an extra alert beats a missed override).
        $this->writeReviveConfig(withAlert: true);
        Log::spy();
        $this->fakeReviveStageOrderAndCard(77, ['tags' => ['triaged']], withAlert: true);

        $this->handle($this->payload(['outcome' => 'reopened']));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH');
        $this->assertSame(1, $this->alertPushCount());
        Log::shouldHaveReceived('warning')->withArgs(fn (string $msg, array $ctx) => str_contains($msg, 'revived a card') && $ctx['hold_signal'] === 'failsafe');
    }

    public function test_reopened_bare_park_stays_quiet_when_hold_tags_configured(): void
    {
        // A bare park with hold_marker_tags declared: the operator declared their
        // marker, so a card without it is trusted → revived, no alert.
        $this->writeReviveConfig(['gate'], withAlert: true);
        $this->fakeReviveStageOrderAndCard(77, ['tags' => ['triaged']], withAlert: true);

        $this->handle($this->payload(['outcome' => 'reopened']));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH');
        $this->assertSame(0, $this->alertPushCount());
    }

    public function test_reopened_forward_move_does_not_alert(): void
    {
        // A non-revival reopen (forward, card not in the abandon stage) moves but must
        // NOT alert — only a genuine revival from the abandon stage is an override.
        $this->writeReviveConfig(withAlert: true);
        $this->fakeReviveStageOrderAndCard(49, [], withAlert: true);

        $this->handle($this->payload(['outcome' => 'reopened']));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH');
        $this->assertSame(0, $this->alertPushCount());
    }
    // --- card#7064: a DROPPED correlation leg becomes a RECORDED one, on the card ---

    /** The kanban comment-create endpoint for card 5 (`POST /tasks/5/comments.json`). */
    private const NOTE_URL = '*/tasks/5/comments.json';

    /** The card-note POSTs this event produced, newest last. */
    private function noteContents(): array
    {
        return collect(Http::recorded())
            ->filter(fn ($pair) => $pair[0]->method() === 'POST' && str_contains($pair[0]->url(), '/tasks/5/comments.json'))
            ->map(fn ($pair) => (string) $pair[0]['content'])
            ->values()->all();
    }

    public function test_a_second_pr_correlating_to_this_card_is_recorded_on_the_card(): void
    {
        // THE card#7064 case, and the one that left no trace anywhere: the token IS
        // corroborated (the head branch names the card), so the DL-270 gate never fires;
        // the card already answers pr_number/pr_url for a FIRST pull request; and the
        // add-if-missing stamp therefore had nothing to write and returned in silence.
        // The guard is right — overwriting would re-point an already-merged leg — so what
        // changes is that the drop is now recorded, not that anything is stamped.
        $this->writeWriteback();
        $this->writeToken();
        Log::spy();
        Http::fake([
            self::NOTE_URL => Http::response(['data' => ['id' => 9]], 201),
            '*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 52, 'payload' => [
                'pr_number' => 261, 'pr_url' => 'https://github.com/owner/repo/pull/261',
            ]]]),
        ]);

        $this->handle($this->payload(['stamp_pr' => 262, 'stamp_pr_url' => 'https://github.com/owner/repo/pull/262']));

        // The guard is UNTOUCHED: not one byte of the card's payload was written.
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
        $notes = $this->noteContents();
        $this->assertCount(1, $notes);
        $this->assertStringContainsString('[bridge:correlation-note correlation-ref-not-stamped · card=5', $notes[0]);
        $this->assertStringContainsString('pr_number=262', $notes[0]);
        $this->assertStringContainsString('the card keeps `261`; this pull request offered `262`', $notes[0]);
        $this->assertStringContainsString('the card keeps `https://github.com/owner/repo/pull/261`', $notes[0]);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $msg, array $ctx) => str_contains($msg, 'was NOT stamped')
            && $ctx['dropped']['pr_number'] === ['card' => 261, 'offered' => 262]);
    }

    public function test_an_idempotent_replay_of_the_cards_own_pr_records_nothing(): void
    {
        // The predicate that has to be exactly right: `nothing left to write` is ALSO what
        // a redelivery of the card's OWN pull request looks like, and that must stay
        // silent — otherwise every webhook retry mints a comment. The card's pr_number
        // comes back from kanban as a STRING here and the event carries an int (the JSON
        // round-trip through the durable inbox produces either), so this also pins that
        // the comparison is numeric and not `===`.
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            self::NOTE_URL => Http::response(['data' => ['id' => 9]], 201),
            '*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 52, 'payload' => [
                'dl_number' => 'DL-0042', 'pr_number' => '261', 'pr_url' => 'https://github.com/owner/repo/pull/261',
            ]]]),
        ]);

        $this->handle($this->payload([
            'stamp_dl' => 'DL-42', 'stamp_pr' => 261, 'stamp_pr_url' => 'https://github.com/owner/repo/pull/261',
        ]));

        $this->assertSame([], $this->noteContents());
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    }

    public function test_a_differing_dl_is_recorded_too(): void
    {
        // The same shape on the dl_number leg — one predicate over all three refs, not a
        // pr-only special case. `DL-42` vs a card holding `DL-0099` differs; the canonical
        // widths (`DL-42` / `dl-0042` / `42`) do NOT, which the replay test above pins.
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            self::NOTE_URL => Http::response(['data' => ['id' => 9]], 201),
            '*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 52, 'payload' => ['dl_number' => 'DL-0099']]]),
        ]);

        $this->handle($this->payload(['stamp_dl' => 'DL-42']));

        $notes = $this->noteContents();
        $this->assertCount(1, $notes);
        $this->assertStringContainsString('dl_number=DL-0042', $notes[0]);
        $this->assertStringContainsString('the card keeps `DL-0099`', $notes[0]);
    }

    public function test_a_dl_the_card_stores_unpadded_is_not_a_dropped_leg(): void
    {
        // The zero-padding is cosmetic: this handler writes the canonical `DL-%04d`, but a
        // card can carry `DL-42`, `dl-0042` or a bare numeric custom field `42` and every
        // correlation reader strips non-digits. Comparing the digit STRINGS would call
        // `42` and `0042` a conflict and mint a note about one DL disagreeing with itself.
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            self::NOTE_URL => Http::response(['data' => ['id' => 9]], 201),
            '*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 52, 'payload' => ['dl_number' => 42]]]),
        ]);

        $this->handle($this->payload(['stamp_dl' => 'DL-42']));

        $this->assertSame([], $this->noteContents());
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    }

    public function test_a_note_already_on_the_card_is_not_written_again(): void
    {
        // Idempotency without a marker file: the note's marker is derived from the FACTS
        // (this card, this dropped ref) and never from the event, so the same drop seen on
        // `opened`, then `merged`, then a redelivery of either re-derives one marker. The
        // check reads the `comments` the card's own getCard aggregate already returned.
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            self::NOTE_URL => Http::response(['data' => ['id' => 9]], 201),
            '*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 52,
                'payload' => ['pr_number' => 261],
                'comments' => [
                    ['id' => 1, 'content' => 'unrelated human comment'],
                    ['id' => 2, 'content' => "[bridge:correlation-note correlation-ref-not-stamped · card=5 · pr_number=262]\n\n…recorded on the `opened` event…"],
                ],
            ]]),
        ]);

        $this->handle($this->payload(['stamp_pr' => 262]));

        $this->assertSame([], $this->noteContents());
    }

    public function test_a_note_for_a_different_dropped_pr_is_still_written(): void
    {
        // The control for the dedup above: it must suppress the SAME note, not every note.
        // A card already carrying the note for PR 262 still records a third PR.
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            self::NOTE_URL => Http::response(['data' => ['id' => 9]], 201),
            '*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 52,
                'payload' => ['pr_number' => 261],
                'comments' => [['id' => 2, 'content' => '[bridge:correlation-note correlation-ref-not-stamped · card=5 · pr_number=262]']],
            ]]),
        ]);

        $this->handle($this->payload(['stamp_pr' => 263]));

        $notes = $this->noteContents();
        $this->assertCount(1, $notes);
        $this->assertStringContainsString('pr_number=263', $notes[0]);
    }

    public function test_a_pr_url_that_prefixes_an_existing_notes_url_is_still_recorded(): void
    {
        // The marker is CLOSED with a `]` for this case: with an open-ended marker ending in
        // a URL, the note for PR 262 has the note for PR 26 as a literal PREFIX, so the
        // substring check would read 262's note as already covering 26's drop and suppress
        // it — the idempotency check minting the very silence this class removes.
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            self::NOTE_URL => Http::response(['data' => ['id' => 9]], 201),
            '*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 52,
                'payload' => ['pr_url' => 'https://github.com/owner/repo/pull/1'],
                'comments' => [['id' => 2, 'content' => '[bridge:correlation-note correlation-ref-not-stamped · card=5 · pr_url=https://github.com/owner/repo/pull/262]']],
            ]]),
        ]);

        $this->handle($this->payload(['stamp_pr_url' => 'https://github.com/owner/repo/pull/26']));

        $notes = $this->noteContents();
        $this->assertCount(1, $notes);
        $this->assertStringContainsString('pr_url=https://github.com/owner/repo/pull/26]', $notes[0]);
    }

    public function test_the_uncorroborated_refusal_is_recorded_on_the_card(): void
    {
        // The already-logged path (DL-270). The log and the alert are the OPERATOR's
        // surfaces; the card said nothing at all, and the card is where somebody looking
        // for the missing correlation looks. The existing log + notify are untouched.
        $this->writeWritebackWithAlert();
        $this->writeToken();
        Log::spy();
        Http::fake([
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            self::NOTE_URL => Http::response(['data' => ['id' => 9]], 201),
            '*/tasks/5.json' => Http::response(['data' => [
                'id' => 5, 'board_id' => 8, 'workflow_stage_id' => 49, 'payload' => ['pr_number' => 900],
            ]]),
        ]);

        $this->handle($this->payload(['card_token_uncorroborated' => true, 'stamp_pr' => 148]));

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');   // still refused, still writes no field
        $notes = $this->noteContents();
        $this->assertCount(1, $notes);
        $this->assertStringContainsString('[bridge:correlation-note move-refused-uncorroborated-card-token · card=5 · pr_number=148]', $notes[0]);
        $this->assertStringContainsString('different pull request (`pr_number` `900`)', $notes[0]);
        // The pre-existing signal is ADDED TO, never replaced.
        Http::assertSent(fn (Request $r) => $this->isAlertPush($r) && $r['reason'] === 'card_token_uncorroborated');
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_contains((string) $msg, 'only in the PR title'))->once();
    }

    public function test_a_refused_card_note_alerts_with_its_own_reason_and_never_throws(): void
    {
        // The writeback token's comment-create permission is NARROWER than the card writes
        // it already makes, so a 403 here is its own operator hypothesis and must not read
        // as `the token cannot write this card`. And it must not throw: 5xx-ing a move that
        // already happened, over an observability write, is the redelivery storm every
        // refusal arm in this handler exists to avoid.
        $this->writeWritebackWithAlert();
        $this->writeToken();
        Log::spy();
        Http::fake([
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            self::NOTE_URL => Http::response(['message' => 'this token cannot comment'], 403),
            '*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 52, 'payload' => ['pr_number' => 261]]]),
        ]);

        $this->handle($this->payload(['stamp_pr' => 262]));

        Http::assertSent(fn (Request $r) => $this->isAlertPush($r) && $r['reason'] === 'cardnote_403_not_writable_by_this_token');
        Log::shouldHaveReceived('warning')->withArgs(fn (string $msg, array $ctx) => str_contains($msg, 'card note was refused')
            && $ctx['status'] === 403)->once();
    }

    public function test_a_transient_card_note_failure_neither_throws_nor_claims_a_refusal(): void
    {
        // A 5xx is not a refusal and must not borrow `writeReason`'s 4xx vocabulary — it
        // would name a permission problem the server never reported. Still swallowed: the
        // move is done, and the note is additive.
        $this->writeWritebackWithAlert();
        $this->writeToken();
        Http::fake([
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            self::NOTE_URL => Http::response(['message' => 'upstream down'], 503),
            '*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 52, 'payload' => ['pr_number' => 261]]]),
        ]);

        $this->handle($this->payload(['stamp_pr' => 262]));

        Http::assertSent(fn (Request $r) => $this->isAlertPush($r) && $r['reason'] === 'cardnote_send_failed');
    }

    public function test_a_dropped_leg_beside_a_missing_one_records_and_stamps(): void
    {
        // Mixed case, and a pre-existing asymmetry this change deliberately does NOT touch
        // (it would alter which ref ends up on the card): pr_number differs and is dropped,
        // while pr_url is absent and is still stamped add-if-missing — from the SECOND
        // pull request. The note is what makes that combination visible.
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            self::NOTE_URL => Http::response(['data' => ['id' => 9]], 201),
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 52, 'payload' => ['pr_number' => 261]]])
                ->push(['data' => ['id' => 5]]),
        ]);

        $this->handle($this->payload(['stamp_pr' => 262, 'stamp_pr_url' => 'https://github.com/owner/repo/pull/262']));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && $r->data() === ['payload' => ['pr_url' => 'https://github.com/owner/repo/pull/262']]);
        $this->assertCount(1, $this->noteContents());
    }

    public function test_the_ordinary_first_stamp_of_a_bare_card_records_nothing(): void
    {
        // The other half of the silence control: the replay test pins that offering what
        // the card ALREADY stores is quiet, this one pins that the everyday path — a card
        // carrying no refs at all, every offered ref written — is quiet too. Both arms of
        // "nothing was dropped" must stay noteless, or the note stops meaning a drop.
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            self::NOTE_URL => Http::response(['data' => ['id' => 9]], 201),
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 49, 'payload' => []]])   // GET: a bare card
                ->push(['data' => ['id' => 5]])    // move
                ->push(['data' => ['id' => 5]]),   // stamp
        ] + $this->fakePreload());

        $this->handle($this->payload([
            'stamp_dl' => 'DL-42', 'stamp_pr' => 261, 'stamp_pr_url' => 'https://github.com/owner/repo/pull/261',
        ]));

        // The stamp really ran (so the silence is the predicate's, not a path that never executed).
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r->data() === ['payload' => [
            'dl_number' => 'DL-0042', 'pr_number' => 261, 'pr_url' => 'https://github.com/owner/repo/pull/261',
        ]]);
        $this->assertSame([], $this->noteContents());
    }

    public function test_a_dropped_leg_is_recorded_on_the_path_that_moves_the_card_first(): void
    {
        // The other call site. Every drop test above enters through the already-in-stage
        // self-heal (the card is at the mapped stage); a second PR that finds its card
        // EARLIER in the board moves it first and stamps after — a different line, and the
        // one that runs on a `merged` event for a card still In-Review. The drop must be
        // recorded there too, and the move itself must be untouched by it.
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            self::NOTE_URL => Http::response(['data' => ['id' => 9]], 201),
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 49, 'payload' => ['pr_number' => 261]]])   // GET: earlier stage
                ->push(['data' => ['id' => 5]]),   // move
        ] + $this->fakePreload());

        $this->handle($this->payload(['stamp_pr' => 262]));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r->data() === ['workflow_stage_id' => 52]);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH' && array_key_exists('payload', $r->data()));
        $notes = $this->noteContents();
        $this->assertCount(1, $notes);
        $this->assertStringContainsString('pr_number=262', $notes[0]);
    }

    public function test_the_pull_zero_source_qualifier_is_stamped_over_not_reported_as_a_second_pr(): void
    {
        // card#7064 (A): `.../pull/0` is the SOURCE-ONLY qualifier this repo's own
        // WritebackSourceCoverageCheck tells operators to stamp so a card on a shared board
        // has a derivable `source`. It names NO pull request — TrackedCardRef has always
        // read it that way — so the card carries no pr_url to preserve: the real URL is an
        // ADD. Comparing bytes both blocked that stamp forever AND reported the card's own
        // first pull request as a second one correlating to it.
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            self::NOTE_URL => Http::response(['data' => ['id' => 9]], 201),
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 52, 'payload' => [
                    'pr_url' => 'https://github.com/owner/repo/pull/0',
                ]]])
                ->push(['data' => ['id' => 5]]),   // stamp
        ]);

        $this->handle($this->payload(['stamp_pr' => 261, 'stamp_pr_url' => 'https://github.com/owner/repo/pull/261']));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r->data() === ['payload' => [
            'pr_number' => 261, 'pr_url' => 'https://github.com/owner/repo/pull/261',
        ]]);
        $this->assertSame([], $this->noteContents());
    }

    public function test_a_pull_zero_qualifier_for_this_prs_own_repo_is_stamped_over(): void
    {
        // The SAME-repo half of the discriminating pair below. Isolated from the case above
        // by carrying no pr_number, so the stamp here is the pr_url leg alone: a placeholder
        // qualifying the very repo this pull request came from is replaced by its real url,
        // silently, because nothing about the card's source changes.
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            self::NOTE_URL => Http::response(['data' => ['id' => 9]], 201),
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 52, 'payload' => [
                    'pr_url' => 'https://github.com/owner/repo/pull/0',
                ]]])
                ->push(['data' => ['id' => 5]]),   // stamp
        ]);

        $this->handle($this->payload(['stamp_pr_url' => 'https://github.com/owner/repo/pull/261']));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && $r->data() === ['payload' => ['pr_url' => 'https://github.com/owner/repo/pull/261']]);
        $this->assertSame([], $this->noteContents());
    }

    public function test_a_pull_zero_qualifier_naming_another_repo_is_kept_and_the_drop_recorded(): void
    {
        // The DIFFERENT-repo half, and the fork this fix was ruled on: `.../pull/0` is not a
        // null value, it is a by-ref SOURCE stamped on purpose so a repo-qualified lookup
        // resolves the card before any pull request exists. The `0` carries no information;
        // the REPO is the load-bearing half. So a pull request from another repo does NOT
        // write over it — that would quietly re-point a field a human set deliberately —
        // and the drop is recorded instead. The note is true as written here: the card
        // really does name a different repo than the pull request that correlated to it.
        //
        // The HEADING is the point of this test, not just the bullet: the shared heading
        // ("this card stays correlated to the pull request it already names") is FALSE
        // here — the card names a repo, not a pull request — so this note must use the
        // repo-only phrasing instead of asserting a pull request that does not exist.
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            self::NOTE_URL => Http::response(['data' => ['id' => 9]], 201),
            '*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 52, 'payload' => [
                'pr_url' => 'https://github.com/owner/other-repo/pull/0',
            ]]]),
        ]);

        $this->handle($this->payload(['stamp_pr_url' => 'https://github.com/owner/repo/pull/261']));

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
        $notes = $this->noteContents();
        $this->assertCount(1, $notes);
        $this->assertStringContainsString('the card keeps `https://github.com/owner/other-repo/pull/0`', $notes[0]);
        $this->assertStringContainsString('this pull request offered `https://github.com/owner/repo/pull/261`', $notes[0]);
        $this->assertStringContainsString('this card stays correlated to the REPO', $notes[0]);
        $this->assertStringNotContainsString('this card stays correlated to the
pull request it already names', $notes[0]);
    }

    public function test_the_cards_own_pull_request_is_not_recorded_as_a_second_one(): void
    {
        // card#7064 (B), and NOT hypothetical: the stamp is add-if-missing PER REF, so the
        // dropped-leg-beside-a-missing-one test above leaves exactly this card — pr_number
        // written by PR 261, pr_url later filled in by PR 262. PR 261's next outcome then
        // offers its own url, which differs from the stored one BYTE-wise, and the note
        // would say the card keeps `.../262` while `this pull request offered .../261`
        // under a heading asserting the card stays correlated to the PR it already names.
        // It already names 261 — the pull request being reported as the intruder.
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            self::NOTE_URL => Http::response(['data' => ['id' => 9]], 201),
            '*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 52, 'payload' => [
                'pr_number' => 261, 'pr_url' => 'https://github.com/owner/repo/pull/262',
            ]]]),
        ]);

        $this->handle($this->payload(['stamp_pr' => 261, 'stamp_pr_url' => 'https://github.com/owner/repo/pull/261']));

        $this->assertSame([], $this->noteContents());
        // The guard is untouched: the card's stored pr_url is NOT re-pointed to 261 either.
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    }

    public function test_a_repo_case_difference_is_not_a_second_pull_request(): void
    {
        // card#7064 (C): GitHub's `owner/repo` is case-insensitive — which is precisely why
        // ExternalReferenceNormalizer::canonicalizeSource lower-cases — so `.../Owner/Repo/`
        // and `.../owner/repo/` are one pull request. The card carries NO pr_number here, so
        // the identity compare is the only thing that can keep this silent.
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            self::NOTE_URL => Http::response(['data' => ['id' => 9]], 201),
            '*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 52, 'payload' => [
                'pr_url' => 'https://github.com/Owner/Repo/pull/261',
            ]]]),
        ]);

        $this->handle($this->payload(['stamp_pr_url' => 'https://github.com/owner/repo/pull/261']));

        $this->assertSame([], $this->noteContents());
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    }

    public function test_a_genuinely_different_second_pr_url_is_still_recorded(): void
    {
        // The negative control for all three above: a comparator that suppresses everything
        // is worse than the raw one it replaced. A DIFFERENT pull request in the same repo,
        // on a card that answers no pr_number at all, must still record its dropped leg.
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            self::NOTE_URL => Http::response(['data' => ['id' => 9]], 201),
            '*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 52, 'payload' => [
                'pr_url' => 'https://github.com/owner/repo/pull/261',
            ]]]),
        ]);

        $this->handle($this->payload(['stamp_pr_url' => 'https://github.com/owner/repo/pull/262']));

        $notes = $this->noteContents();
        $this->assertCount(1, $notes);
        $this->assertStringContainsString('pr_url=https://github.com/owner/repo/pull/262]', $notes[0]);
        $this->assertStringContainsString('the card keeps `https://github.com/owner/repo/pull/261`', $notes[0]);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    }

    public function test_the_same_pr_number_in_a_different_repo_is_still_recorded(): void
    {
        // The second half of the control: identity is (repo, number), not the number. A
        // card qualified to one repo and a PR of the same number in another are two pull
        // requests, and collapsing them would silently hide a real cross-repo collision on
        // a shared board. The card carries the matching bare `pr_number` too, because that
        // is the leg that could collapse them: a stored pr_number has no repo, so the
        // card's-own-PR test is repo-qualified by the card's own pr_url before it counts.
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            self::NOTE_URL => Http::response(['data' => ['id' => 9]], 201),
            '*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 52, 'payload' => [
                'pr_number' => 261, 'pr_url' => 'https://github.com/owner/other-repo/pull/261',
            ]]]),
        ]);

        $this->handle($this->payload(['stamp_pr_url' => 'https://github.com/owner/repo/pull/261']));

        $notes = $this->noteContents();
        $this->assertCount(1, $notes);
        $this->assertStringContainsString('pr_url=https://github.com/owner/repo/pull/261]', $notes[0]);
        $this->assertStringContainsString('the card keeps `https://github.com/owner/other-repo/pull/261`', $notes[0]);
    }

    public function test_a_pr_url_the_card_answers_with_free_text_still_records_the_drop(): void
    {
        // A card whose pr_url is an operator's free text names no pull request the offered
        // one could BE, so the drop is still recorded and the text is still preserved (the
        // never-overwrite guard is untouched — only the `.../pull/0` placeholder, which
        // means "no pull request" by construction, is treated as absent). The arm that
        // proves the identity compare REPLACED a comparison rather than deleting one.
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            self::NOTE_URL => Http::response(['data' => ['id' => 9]], 201),
            '*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 52, 'payload' => [
                'pr_url' => 'see the linked PR',
            ]]]),
        ]);

        $this->handle($this->payload(['stamp_pr_url' => 'https://github.com/owner/repo/pull/261']));

        $notes = $this->noteContents();
        $this->assertCount(1, $notes);
        $this->assertStringContainsString('the card keeps `see the linked PR`', $notes[0]);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    }

    // --- card#7212: the success record names the board the write LANDED on ---

    public function test_a_successful_move_records_the_cards_own_board_beside_the_mapped_one(): void
    {
        // PRESENCE, asserted on the record's CONTENT. Before this the line carried a single
        // `board` key read from CONFIG — the board the write INTENDED to reach — which is
        // emitted identically whether or not the card it reached was on that board. The two
        // being EQUAL is the happy path; that they are two independent readings is the point.
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 49]])   // GET
                ->push(['data' => ['id' => 5]]),                                              // PATCH
        ] + $this->fakePreload());
        Log::spy();

        $this->handle($this->payload());

        Log::shouldHaveReceived('info')->withArgs(fn (string $m, array $ctx) => $m === 'kanban_move_card: moved'
            && array_key_exists('card_board', $ctx) && array_key_exists('mapped_board', $ctx)
            && $ctx['card_board'] === 8 && $ctx['mapped_board'] === 8);
    }

    public function test_the_move_record_reads_the_cards_own_board_and_is_not_a_second_copy_of_the_mapped_one(): void
    {
        // ⛔ THE CONTROL. A test asserting only that both keys are PRESENT passes against a
        // "fix" that renders `mapped_board` into both slots — which is precisely today's
        // defect wearing the new field's name. So force the two values APART and pin them
        // with `===`.
        //
        // A card on a genuinely different board cannot reach the success record on ANY arm:
        // MappedBoardGuard refuses it long before the write — on this token arm since DL-292,
        // and on the Group-B arms since DL-298 (card#7211). What can reach it is the accepted
        // INTERVAL (DL-292) — `is_numeric` + `(int)` admits the numeric STRING "8" onto a
        // mapped board of 8 — so a reading of the card gives `'8'` where an echo of the
        // mapping would give int 8. That is the mechanism every divergence leg in this class
        // now uses; see KanbanDependabotCardHandlerTest and KanbanPromoteReleasedHandlerTest.
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => '8', 'workflow_stage_id' => 49]])
                ->push(['data' => ['id' => 5]]),
        ] + $this->fakePreload());
        Log::spy();

        $this->handle($this->payload());

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH');   // the move still happens (DL-292)
        Log::shouldHaveReceived('info')->withArgs(fn (string $m, array $ctx) => $m === 'kanban_move_card: moved'
            && $ctx['card_board'] === '8'       // the CARD's spelling, verbatim
            && $ctx['mapped_board'] === 8);     // the CONFIG's, unchanged
    }

    public function test_the_self_heal_stamp_records_both_boards_when_no_move_line_is_emitted(): void
    {
        // The already-in-stage self-heal WRITES (it stamps correlation refs) and returns
        // before the `moved` line, so this is the delivery's ONLY success record. Without
        // the pair here that write's board would go unrecorded entirely.
        $this->writeWriteback();
        $this->writeToken();
        Http::fake([
            '*/tasks/5.json' => Http::sequence()
                ->push(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 52, 'payload' => []]])
                ->push(['data' => ['id' => 5]]),
        ]);
        Log::spy();

        $this->handle($this->payload(['stamp_pr' => 148]));

        Log::shouldHaveReceived('info')->withArgs(fn (string $m, array $ctx) => $m === 'kanban_move_card: stamped correlation refs'
            && $ctx['card_board'] === 8 && $ctx['mapped_board'] === 8);
    }
}
