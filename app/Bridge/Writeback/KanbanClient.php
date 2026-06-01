<?php

namespace App\Bridge\Writeback;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Thin kanban-board writeback client (DL-009/019) — sibling to the
 * provisioning-scoped KanbanProvisionClient, but for the card-move WRITE path.
 * It authenticates with a DEDICATED least-privilege writeback token (its own
 * 0600 file, NOT the broad provisioning token) so a leak is bounded to card
 * moves. Exposes only the two writeback verbs; throws on non-2xx.
 */
final class KanbanClient
{
    public function __construct(
        private string $baseUrl,
        private string $token,
    ) {}

    private function http(): PendingRequest
    {
        return Http::withToken($this->token)
            ->acceptJson()
            ->timeout(15)
            ->baseUrl(rtrim($this->baseUrl, '/'));
    }

    /**
     * The card's current state (incl. board_id + workflow_stage_id) — read for
     * the belongs-to-mapped-board guard and the idempotent already-in-stage check.
     *
     * @return array<string, mixed>
     */
    public function getCard(int $cardId): array
    {
        $data = $this->http()->get("/tasks/{$cardId}.json")->throw()->json('data');

        return is_array($data) ? $data : [];
    }

    /** Move the card to a workflow stage (column-only; never touches payload/other fields). */
    public function moveCard(int $cardId, int $stageId): void
    {
        $this->http()->patch("/tasks/{$cardId}.json", ['task' => ['workflow_stage_id' => $stageId]])->throw();
    }
}
