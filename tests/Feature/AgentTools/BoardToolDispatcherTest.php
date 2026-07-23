<?php

namespace Tests\Feature\AgentTools;

use App\Bridge\Support\BoardToolsConfig;
use App\Bridge\Tools\BoardToolDispatcher;
use App\Bridge\Tools\BoardToolsRegistry;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The shared BoardToolDispatcher (card 4952, Finding A) — the transport-neutral
 * machinery both front doors single-source. Asserts the DispatchOutcome status-class
 * for each branch (ok 200 / refusal 422 / upstream 502 / writeback-unavailable 503),
 * the exit-code mapping (0/1/2), and the body-shape both doors serialize.
 */
class BoardToolDispatcherTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/dispatcher-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/kanban');
        File::put($this->dir.'/kanban/writeback-token', 'wb');   // gitleaks:allow — test fixture
        chmod($this->dir.'/kanban/writeback-token', 0o600);
        config([
            'bridge.secret_dir' => $this->dir,
            'bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function dispatcher(): BoardToolDispatcher
    {
        return new BoardToolDispatcher(new BoardToolsRegistry);
    }

    private function cfg(): BoardToolsConfig
    {
        return new BoardToolsConfig(
            enabled: true, tokenPath: null, boardId: 10, swimlaneId: 4, createStageId: 55,
            sharedSwimlaneId: null, coordBoardId: null, addressTags: [], transport: 'ssh',
        );
    }

    public function test_ok_outcome_is_200_exit_0_with_parity_body(): void
    {
        Http::fake([
            '*/boards/10/preload.json' => Http::response(['data' => ['workflows' => [['stages' => []]]]]),
            '*/tasks/search.json*' => Http::response(['data' => []]),
        ]);

        $outcome = $this->dispatcher()->dispatch('board_my_cards', [], $this->cfg(), 'me');

        $this->assertTrue($outcome->ok);
        $this->assertSame(200, $outcome->status);
        $this->assertSame(0, $outcome->exitCode());
        $body = $outcome->body();
        $this->assertTrue($body['ok']);
        $this->assertSame('board_my_cards', $body['tool']);
        $this->assertArrayHasKey('result', $body);
    }

    public function test_refusal_is_422_exit_1(): void
    {
        Http::fake(['*/tasks.json' => Http::response(['data' => ['id' => 1]], 201)]);

        $outcome = $this->dispatcher()->dispatch('board_create_card', ['title' => 't', 'tags' => ['triaged']], $this->cfg(), 'me');

        $this->assertFalse($outcome->ok);
        $this->assertSame(422, $outcome->status);
        $this->assertSame(1, $outcome->exitCode());
        $this->assertFalse($outcome->body()['ok']);
        $this->assertArrayHasKey('error', $outcome->body());
        Http::assertNothingSent();
    }

    public function test_upstream_error_is_502_exit_2(): void
    {
        Http::fake([
            '*/boards/10/preload.json' => Http::response(['data' => ['workflows' => [['stages' => []]]]]),
            '*/tasks/search.json*' => Http::response('boom', 500),
        ]);

        $outcome = $this->dispatcher()->dispatch('board_my_cards', [], $this->cfg(), 'me');

        $this->assertSame(502, $outcome->status);
        $this->assertSame(2, $outcome->exitCode());
    }

    public function test_writeback_unavailable_is_503_exit_2(): void
    {
        // No writeback token → WritebackClientFactory throws ConfigException → 503.
        File::delete($this->dir.'/kanban/writeback-token');

        $outcome = $this->dispatcher()->dispatch('board_my_cards', [], $this->cfg(), 'me');

        $this->assertSame(503, $outcome->status);
        $this->assertSame(2, $outcome->exitCode());
    }

    public function test_unknown_tool_is_422(): void
    {
        $outcome = $this->dispatcher()->dispatch('board_delete_everything', [], $this->cfg(), 'me');
        $this->assertSame(422, $outcome->status);
    }

    public function test_non_array_args_is_422(): void
    {
        $outcome = $this->dispatcher()->dispatch('board_my_cards', 'not-an-object', $this->cfg(), 'me');
        $this->assertSame(422, $outcome->status);
    }

    public function test_empty_tool_name_is_422(): void
    {
        $outcome = $this->dispatcher()->dispatch('', [], $this->cfg(), 'me');
        $this->assertSame(422, $outcome->status);
    }
}
