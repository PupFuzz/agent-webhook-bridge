<?php

namespace App\Bridge\Writeback;

use App\Bridge\Exceptions\ConfigException;
use App\Bridge\Exceptions\UnreadableSecretException;
use App\Bridge\Support\SecretFile;
use App\Bridge\Support\TokenPath;
use App\Bridge\Support\UrlValidator;

/**
 * Builds a KanbanClient with the dedicated least-privilege writeback token
 * (DL-021), shared by the correlation classifier (read) and the move handler
 * (write) so the config + token plumbing lives in one place. Throws
 * ConfigException on a missing API base URL, or on a token that is missing or
 * present-but-unreadable — an operator-fixable condition that should surface as a
 * treatment-A classify error / a treatment-B 5xx in the durable handler, both
 * retryable. An INSECURE token throws InsecureSecretPermsException instead: it comes
 * straight from the perms gate and is deliberately its own type fleet-wide (DL-010),
 * which this docblock claimed otherwise until card#5778.
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
        UrlValidator::secureHttpUrl($baseUrl, "bridge.providers.{$provider}.api_base_url");
        $tokenPath = TokenPath::forWriteback($secretDir, $provider);
        try {
            $token = SecretFile::read($tokenPath);   // throws on insecure perms
        } catch (UnreadableSecretException $e) {
            // Mapped onto this factory's declared channel rather than allowed to escape:
            // an unreadable token is the same operator-fixable, retryable condition as a
            // missing one below, and callers are written against ConfigException.
            throw new ConfigException("kanban writeback: {$e->getMessage()}");
        }
        if ($token === null) {
            throw new ConfigException("kanban writeback: no token at {$tokenPath} (place a least-privilege token, chmod 600)");
        }

        $correlation = (string) config('bridge.writeback.correlation', 'ref');

        return new KanbanClient($baseUrl, $token, $correlation);
    }
}
