<?php

namespace Tests\Feature\Writeback;

use App\Bridge\Writeback\KanbanClient;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KanbanClientTest extends TestCase
{
    private function client(): KanbanClient
    {
        return new KanbanClient('https://kanban.example.com/api/v3', 'wb-token');
    }

    public function test_get_card_returns_the_data_envelope(): void
    {
        Http::fake(['*/tasks/5.json' => Http::response(['data' => ['id' => 5, 'board_id' => 8, 'workflow_stage_id' => 49]])]);

        $card = $this->client()->getCard(5);

        $this->assertSame(8, $card['board_id']);
        $this->assertSame(49, $card['workflow_stage_id']);
        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && str_contains($r->url(), '/tasks/5.json')
            && $r->hasHeader('Authorization', 'Bearer wb-token'));
    }

    public function test_move_card_patches_workflow_stage_only(): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => 5]])]);

        $this->client()->moveCard(5, 52);

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && str_contains($r->url(), '/tasks/5.json')
            && $r['task'] === ['workflow_stage_id' => 52]);   // column-only, no other fields
    }

    public function test_non_2xx_throws(): void
    {
        Http::fake(['*' => Http::response(['error' => 'forbidden'], 403)]);
        $this->expectException(RequestException::class);
        $this->client()->moveCard(5, 52);
    }
}
