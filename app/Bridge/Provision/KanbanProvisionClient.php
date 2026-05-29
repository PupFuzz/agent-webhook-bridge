<?php

namespace App\Bridge\Provision;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Thin client for kanban-board's webhook API, scoped to what provisioning
 * needs. Mirrors lib/providers/kanban.py: board-scoped list/create plus the
 * Laravel-style {"data": ...} envelope. Throws on non-2xx (the command
 * surfaces it).
 */
final class KanbanProvisionClient
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
     * @return list<array<string, mixed>>
     */
    public function listWebhooks(string $scopeId): array
    {
        $data = $this->http()->get("/boards/{$scopeId}/webhooks.json")->throw()->json('data');

        return is_array($data) ? array_values(array_filter($data, 'is_array')) : [];
    }

    /**
     * @param  ?list<string>  $eventFilter  null/empty = all events
     * @return array<string, mixed>
     */
    public function createWebhook(string $scopeId, string $url, string $secret, ?array $eventFilter): array
    {
        $body = ['url' => $url, 'secret' => $secret, 'active' => true];
        if ($eventFilter !== null && $eventFilter !== []) {
            $body['event_filter'] = $eventFilter;
        }

        $created = $this->http()->post("/boards/{$scopeId}/webhooks.json", $body)->throw()->json('data');

        return is_array($created) ? $created : [];
    }

    public function deleteWebhook(int|string $webhookId): void
    {
        $this->http()->delete("/webhooks/{$webhookId}.json")->throw();
    }
}
