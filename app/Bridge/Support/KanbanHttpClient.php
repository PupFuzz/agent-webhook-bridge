<?php

namespace App\Bridge\Support;

use App\Bridge\Provision\KanbanProvisionClient;
use App\Bridge\Writeback\KanbanClient;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * The shared configured PendingRequest for kanban-board's webhook API: bearer
 * auth, JSON accept, a 15s ceiling (kept under kanban's webhook-delivery
 * timeout), and a trailing-slash-normalized base URL. Both the writeback
 * {@see KanbanClient} and the provisioning
 * {@see KanbanProvisionClient} compose this so the
 * transport config can't drift between the read/write and provisioning paths.
 */
final class KanbanHttpClient
{
    public const TIMEOUT_SECONDS = 15;

    public static function configured(string $baseUrl, string $token): PendingRequest
    {
        return Http::withToken($token)
            ->acceptJson()
            ->timeout(self::TIMEOUT_SECONDS)
            ->baseUrl(rtrim($baseUrl, '/'));
    }
}
