<?php

namespace App\Bridge\Writeback;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * The one place a request to the GitHub REST API is shaped: the base URL, the headers GitHub
 * requires, the bearer token and the timeout. Hoisted out of {@see GitHubReadClient} at its second
 * caller ({@see GitHubWriteClient}, DL-390) so a read and a write cannot drift apart on the API
 * version or the User-Agent GitHub rejects a request without.
 */
final class GitHubApi
{
    public const BASE = 'https://api.github.com';

    public static function request(string $token, int $timeoutSeconds): PendingRequest
    {
        return Http::withToken($token)
            ->withHeaders([
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
                'User-Agent' => 'agent-webhook-bridge',   // GitHub rejects a UA-less request
            ])
            ->timeout($timeoutSeconds);
    }
}
