<?php

namespace App\Bridge\Writeback;

use App\Bridge\Exceptions\ConfigException;
use App\Bridge\Support\SecretFile;
use App\Bridge\Support\TokenPath;

/**
 * Builds a KanbanClient with the dedicated least-privilege writeback token
 * (DL-021), shared by the correlation classifier (read) and the move handler
 * (write) so the config + token plumbing lives in one place. Throws
 * ConfigException on a missing API base URL or a missing/insecure token — an
 * operator-fixable condition that should surface as a treatment-A classify error
 * / a treatment-B 5xx in the durable handler, both retryable.
 */
final class WritebackClientFactory
{
    public static function make(string $provider = 'kanban'): KanbanClient
    {
        $secretDir = (string) config('bridge.secret_dir');
        $baseUrl = (string) config("bridge.providers.{$provider}.api_base_url");
        if ($baseUrl === '') {
            throw new ConfigException("kanban writeback: bridge.providers.{$provider}.api_base_url is not configured");
        }
        $tokenPath = TokenPath::forWriteback($secretDir, $provider);
        $token = SecretFile::read($tokenPath);   // throws on insecure perms
        if ($token === null) {
            throw new ConfigException("kanban writeback: no token at {$tokenPath} (place a least-privilege token, chmod 600)");
        }

        $correlation = (string) config('bridge.writeback.correlation', 'scan');

        return new KanbanClient($baseUrl, $token, $correlation);
    }
}
