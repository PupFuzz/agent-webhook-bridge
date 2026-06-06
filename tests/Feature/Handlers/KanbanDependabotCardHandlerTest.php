<?php

namespace Tests\Feature\Handlers;

use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Handlers\KanbanDependabotCardHandler;
use App\Bridge\Support\AgentConfig;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
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
            && $r['task']['payload']['origin'] === 'dependabot'
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
            '*/tasks/7.json' => Http::response(['data' => ['id' => 7, 'workflow_stage_id' => 50]]),
        ]);

        $this->handle('merged');   // existing card at 50, target stage 52

        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), '/tasks/7.json') && $r['task']['workflow_stage_id'] === 52);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_already_in_target_stage_is_a_noop(): void
    {
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 7, 'workflow_stage_id' => 52, 'payload' => ['pr_number' => 42]]]]),
            '*/tasks/7.json' => Http::response(['data' => ['id' => 7, 'workflow_stage_id' => 52]]),
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
}
