<?php

namespace Tests\Feature\Handlers;

use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Handlers\KanbanDependabotCardHandler;
use App\Bridge\Support\AgentConfig;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class KanbanDependabotCardHandlerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/dbcard-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/kanban');
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => [
                'board_id' => 8,
                'stages' => ['opened' => 50, 'merged' => 52, 'merged_to_main' => 53, 'closed_unmerged' => 49],
                'create_dependabot_cards' => true,
            ]],
        ]));
        File::put($this->dir.'/kanban/writeback-token', 'wb-token');
        chmod($this->dir.'/kanban/writeback-token', 0o600);
        config([
            'bridge.config_dir' => $this->dir,
            'bridge.secret_dir' => $this->dir,
            'bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3',
            // Fakes the scan correlation path; pin scan (default is now `ref`, DL-031).
            'bridge.writeback.correlation' => 'scan',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function handle(string $outcome, int $pr = 42): void
    {
        (new KanbanDependabotCardHandler)->handle(
            ReactionTarget::make('kanban_dependabot_card', "pr-{$pr}", payload: [
                'repo' => 'owner/repo', 'outcome' => $outcome, 'pr_number' => $pr,
                'pr_title' => 'chore(deps): Bump x from 1 to 2', 'pr_url' => 'https://github.com/owner/repo/pull/'.$pr,
            ]),
            AgentConfig::fromArray('prod-agent', ['identity' => ['kanban_user_id' => 1], 'subscriptions' => []]),
        );
    }

    public function test_opened_with_no_existing_card_creates_one_at_the_opened_stage(): void
    {
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => []]),
            '*/tasks.json' => Http::response(['data' => ['id' => 99]], 201),
        ]);

        $this->handle('opened');

        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/tasks.json')
            && $r['task']['board_id'] === 8
            && $r['task']['workflow_stage_id'] === 50
            && $r['task']['payload']['pr_number'] === 42
            && $r['task']['payload']['pr_url'] === 'https://github.com/owner/repo/pull/42'
            && $r['task']['payload']['origin'] === 'dependabot'
            // Lock the payload key SET to the constant bridge:check validates (#2949),
            // so the create payload and the check's required-key list can't drift.
            && array_keys($r['task']['payload']) === KanbanDependabotCardHandler::CREATE_PAYLOAD_KEYS
            && in_array('dependencies', $r['task']['tags'], true)
            && in_array('triaged', $r['task']['tags'], true));
    }

    public function test_mapping_swimlane_id_is_applied_to_a_created_card(): void
    {
        // DL-027: a per-mapping swimlane_id lands the created card in that lane.
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => [
                'board_id' => 8, 'swimlane_id' => 31,
                'stages' => ['opened' => 50, 'merged' => 52, 'merged_to_main' => 53, 'closed_unmerged' => 49],
                'create_dependabot_cards' => true,
            ]],
        ]));
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => []]),
            '*/tasks.json' => Http::response(['data' => ['id' => 99]], 201),
        ]);

        $this->handle('opened');

        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/tasks.json')
            && ($r['task']['swimlane_id'] ?? null) === 31);
    }

    public function test_no_swimlane_id_omits_the_key_from_the_create(): void
    {
        // setUp's mapping has no swimlane_id → the POST must not carry the key at all.
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => []]),
            '*/tasks.json' => Http::response(['data' => ['id' => 99]], 201),
        ]);

        $this->handle('opened');

        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/tasks.json')
            && ! array_key_exists('swimlane_id', $r['task']));
    }

    public function test_existing_card_is_moved_not_recreated(): void
    {
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 7, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42]]]]),
            '*/tasks/7.json' => Http::response(['data' => ['id' => 7, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42, 'pr_url' => 'https://github.com/owner/repo/pull/42']]]),
        ]);

        $this->handle('merged');   // existing card at 50, target stage 52

        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), '/tasks/7.json') && $r['task']['workflow_stage_id'] === 52);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_repo_attribution_is_case_insensitive(): void
    {
        // Parity with the kanban server's `source` semantics (GitHub owner/repo is
        // case-insensitive): a card whose stored pr_url differs only in CASE from
        // the event repo is still attributed to it and moved. The pre-normalizer
        // exact-string match would have dropped it (latent bug).
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 7, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42]]]]),
            '*/tasks/7.json' => Http::response(['data' => ['id' => 7, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42, 'pr_url' => 'https://github.com/Owner/Repo/pull/42']]]),
        ]);

        $this->handle('merged');   // event repo is owner/repo (setUp); card url is Owner/Repo

        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), '/tasks/7.json') && $r['task']['workflow_stage_id'] === 52);
    }

    public function test_already_in_target_stage_is_a_noop(): void
    {
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 7, 'workflow_stage_id' => 52, 'payload' => ['pr_number' => 42]]]]),
            '*/tasks/7.json' => Http::response(['data' => ['id' => 7, 'workflow_stage_id' => 52, 'payload' => ['pr_number' => 42, 'pr_url' => 'https://github.com/owner/repo/pull/42']]]),
        ]);

        $this->handle('merged');

        Http::assertNotSent(fn ($r) => in_array($r->method(), ['PATCH', 'POST'], true));
    }

    public function test_closed_unmerged_with_no_card_creates_nothing(): void
    {
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => []])]);

        $this->handle('closed_unmerged');

        Http::assertNotSent(fn ($r) => in_array($r->method(), ['PATCH', 'POST'], true));
    }

    public function test_closed_unmerged_archives_an_existing_card_not_moves_it(): void
    {
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 7, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42]]]]),
            '*/tasks/7.json' => Http::response(['data' => ['id' => 7, 'archived_at' => '2026-06-19T00:00:00+00:00', 'payload' => ['pr_number' => 42, 'pr_url' => 'https://github.com/owner/repo/pull/42']]]),
        ]);

        $this->handle('closed_unmerged');   // DL-161: dependabot close-unmerged retires the card

        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), '/tasks/7.json') && $r['_action'] === 'archive');
        Http::assertNotSent(fn ($r) => $r->method() === 'PATCH' && isset($r['task']['workflow_stage_id']));   // archived, not moved
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_closed_unmerged_archives_even_with_no_closed_unmerged_stage_mapped(): void
    {
        // The fix's load-bearing case: archive needs no stage mapping, so a card
        // is retired on close even when the operator never mapped closed_unmerged.
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => [
                'board_id' => 8,
                'stages' => ['opened' => 50, 'merged' => 52, 'merged_to_main' => 53],   // NO closed_unmerged
                'create_dependabot_cards' => true,
            ]],
        ]));
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 7, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42]]]]),
            '*/tasks/7.json' => Http::response(['data' => ['id' => 7, 'archived_at' => '2026-06-19T00:00:00+00:00', 'payload' => ['pr_number' => 42, 'pr_url' => 'https://github.com/owner/repo/pull/42']]]),
        ]);

        $this->handle('closed_unmerged');

        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), '/tasks/7.json') && $r['_action'] === 'archive');
    }

    public function test_closed_unmerged_archive_not_confirmed_logs_and_noops_does_not_throw(): void
    {
        // A 200 whose archived_at is null (wrong-verb / contract break) is
        // deterministic — it must NOT propagate into a ~5xx retry storm. The
        // handler logs LOUD (error) and no-ops.
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 7, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42]]]]),
            '*/tasks/7.json' => Http::response(['data' => ['id' => 7, 'archived_at' => null, 'payload' => ['pr_number' => 42, 'pr_url' => 'https://github.com/owner/repo/pull/42']]]),
        ]);
        Log::spy();

        $this->handle('closed_unmerged');   // must not throw

        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $msg) => str_contains($msg, 'not archived'));
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_closed_unmerged_archives_all_matching_cards(): void
    {
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [
                ['id' => 7, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42]],
                ['id' => 8, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42]],
            ]]),
            '*/tasks/7.json' => Http::response(['data' => ['id' => 7, 'archived_at' => '2026-06-19T00:00:00+00:00', 'payload' => ['pr_number' => 42, 'pr_url' => 'https://github.com/owner/repo/pull/42']]]),
            '*/tasks/8.json' => Http::response(['data' => ['id' => 8, 'archived_at' => '2026-06-19T00:00:00+00:00', 'payload' => ['pr_number' => 42, 'pr_url' => 'https://github.com/owner/repo/pull/42']]]),
        ]);

        $this->handle('closed_unmerged');

        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), '/tasks/7.json') && $r['_action'] === 'archive');
        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), '/tasks/8.json') && $r['_action'] === 'archive');
    }

    public function test_create_collapses_a_concurrently_created_duplicate(): void
    {
        // The create-or-move race (#2982): the pre-create correlate sees no card,
        // but by the time the create returns a concurrent delivery for the same PR
        // has also created one. The post-create re-correlate now sees BOTH → the
        // handler keeps the lowest id (99) and archives the racer (100).
        $prUrl = 'https://github.com/owner/repo/pull/42';
        Http::fake([
            '*/tasks/search.json*' => Http::sequence()
                ->push(['data' => []])                                  // pre-create correlate: empty → create
                ->push(['data' => [                                     // post-create re-correlate: the race surfaced
                    ['id' => 100, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42]],
                    ['id' => 99, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42]],
                ]]),
            '*/tasks.json' => Http::response(['data' => ['id' => 99]], 201),
            // Both cards are attributed to THIS repo via pr_url (the cross-repo guard).
            '*/tasks/99.json' => Http::response(['data' => ['id' => 99, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42, 'pr_url' => $prUrl]]]),
            '*/tasks/100.json' => Http::response(['data' => ['id' => 100, 'workflow_stage_id' => 50, 'archived_at' => '2026-06-20T00:00:00+00:00', 'payload' => ['pr_number' => 42, 'pr_url' => $prUrl]]]),
        ]);

        $this->handle('opened');

        // The racer (higher id) is archived; the survivor (lowest id) is never PATCH-archived.
        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), '/tasks/100.json') && ($r['_action'] ?? null) === 'archive');
        Http::assertNotSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), '/tasks/99.json'));
    }

    public function test_create_does_not_collapse_a_same_pr_number_card_from_another_repo(): void
    {
        // Cross-repo guard: on a board shared across repos, a same-numbered PR in
        // ANOTHER repo correlates by bare PR number but is a DISTINCT card. The
        // handler attributes each card by its pr_url and must NOT archive the
        // foreign-repo card (id 100, repo `other/repo`).
        Http::fake([
            '*/tasks/search.json*' => Http::sequence()
                ->push(['data' => []])
                ->push(['data' => [
                    ['id' => 100, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42]],
                    ['id' => 99, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42]],
                ]]),
            '*/tasks.json' => Http::response(['data' => ['id' => 99]], 201),
            '*/tasks/99.json' => Http::response(['data' => ['id' => 99, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42, 'pr_url' => 'https://github.com/owner/repo/pull/42']]]),
            '*/tasks/100.json' => Http::response(['data' => ['id' => 100, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42, 'pr_url' => 'https://github.com/other/repo/pull/42']]]),
        ]);

        $this->handle('opened');   // our repo is owner/repo (setUp)

        // Only our card (99) survives uncollapsed; the foreign-repo card (100) is untouched.
        Http::assertNotSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), '/tasks/100.json'));
        Http::assertNotSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), '/tasks/99.json'));
    }

    public function test_no_duplicate_after_create_archives_nothing(): void
    {
        // The common path: the post-create re-correlate sees only the card we made
        // → no archive, no extra writes beyond the create.
        Http::fake([
            '*/tasks/search.json*' => Http::sequence()
                ->push(['data' => []])
                ->push(['data' => [['id' => 99, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42]]]]),
            '*/tasks.json' => Http::response(['data' => ['id' => 99]], 201),
            '*/tasks/99.json' => Http::response(['data' => ['id' => 99, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42, 'pr_url' => 'https://github.com/owner/repo/pull/42']]]),
        ]);

        $this->handle('opened');

        Http::assertNotSent(fn ($r) => $r->method() === 'PATCH');   // nothing archived/moved
    }

    public function test_move_path_collapses_pre_existing_duplicates_and_moves_the_survivor(): void
    {
        // Self-heal: duplicates minted before this guard shipped are collapsed on
        // the PR's next non-terminal event. merge with two correlated cards → the
        // racer (id 8) is archived, only the survivor (id 7) advances to the stage.
        $prUrl = 'https://github.com/owner/repo/pull/42';
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [
                ['id' => 7, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42]],
                ['id' => 8, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42]],
            ]]),
            '*/tasks/7.json' => Http::response(['data' => ['id' => 7, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42, 'pr_url' => $prUrl]]]),
            '*/tasks/8.json' => Http::response(['data' => ['id' => 8, 'workflow_stage_id' => 50, 'archived_at' => '2026-06-20T00:00:00+00:00', 'payload' => ['pr_number' => 42, 'pr_url' => $prUrl]]]),
        ]);

        $this->handle('merged');   // target stage 52

        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), '/tasks/8.json') && ($r['_action'] ?? null) === 'archive');
        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), '/tasks/7.json') && ($r['task']['workflow_stage_id'] ?? null) === 52);
        // The archived duplicate is never moved.
        Http::assertNotSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), '/tasks/8.json') && isset($r['task']['workflow_stage_id']));
    }

    public function test_card_with_unparseable_pr_url_is_never_archived(): void
    {
        // Conservative contract (cross-repo guard): a card whose repo can't be
        // attributed (absent/malformed pr_url) is DROPPED — never archived or moved
        // on a guess. Pins cardRepo's null-drop so a future classifier that stops
        // populating pr_url can't make the handler start archiving on a bad key.
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 7, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42]]]]),
            '*/tasks/7.json' => Http::response(['data' => ['id' => 7, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42, 'pr_url' => '']]]),
        ]);

        $this->handle('closed_unmerged');

        Http::assertNotSent(fn ($r) => $r->method() === 'PATCH');   // unattributable → dropped, not archived
    }

    public function test_opt_out_mapping_ignores_the_target(): void
    {
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => ['board_id' => 8, 'stages' => ['opened' => 50]]],   // no create_dependabot_cards
        ]));
        Http::fake();

        $this->handle('opened');

        Http::assertNothingSent();
    }

    // ---- #75 / card-4485: card_id_tag_template ----

    public function test_card_id_tag_template_stamps_a_rendered_id_tag_on_create(): void
    {
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => [
                'board_id' => 8,
                'stages' => ['opened' => 50, 'merged' => 52, 'merged_to_main' => 53, 'closed_unmerged' => 49],
                'create_dependabot_cards' => true,
                'card_id_tag_template' => 'id:DEV-pr-{n}',
            ]],
        ]));
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => []]),
            '*/tasks.json' => Http::response(['data' => ['id' => 99]], 201),
        ]);

        $this->handle('opened', 166);

        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/tasks.json')
            && in_array('id:DEV-pr-166', $r['task']['tags'], true)
            && in_array('dependencies', $r['task']['tags'], true)
            && in_array('triaged', $r['task']['tags'], true));
    }

    public function test_card_id_tag_template_supports_repo_placeholder(): void
    {
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['AIMLA/magento' => [
                'board_id' => 8,
                'stages' => ['opened' => 50, 'merged' => 52, 'merged_to_main' => 53, 'closed_unmerged' => 49],
                'create_dependabot_cards' => true,
                'card_id_tag_template' => 'id:dep:{repo}#{n}',
            ]],
        ]));
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => []]),
            '*/tasks.json' => Http::response(['data' => ['id' => 99]], 201),
        ]);

        (new KanbanDependabotCardHandler)->handle(
            ReactionTarget::make('kanban_dependabot_card', 'pr-166', payload: [
                'repo' => 'AIMLA/magento', 'outcome' => 'opened', 'pr_number' => 166,
                'pr_title' => 'chore(deps): Bump x from 1 to 2', 'pr_url' => 'https://github.com/AIMLA/magento/pull/166',
            ]),
            AgentConfig::fromArray('prod-agent', ['identity' => ['kanban_user_id' => 1], 'subscriptions' => []]),
        );

        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/tasks.json')
            && in_array('id:dep:magento#166', $r['task']['tags'], true));
    }

    public function test_no_card_id_tag_template_leaves_tags_back_compat(): void
    {
        // setUp's mapping carries no card_id_tag_template — the tags must be
        // exactly ['dependencies', 'triaged'], no id: tag added (back-compat).
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => []]),
            '*/tasks.json' => Http::response(['data' => ['id' => 99]], 201),
        ]);

        $this->handle('opened');

        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/tasks.json')
            && $r['task']['tags'] === ['dependencies', 'triaged']);
    }
}
