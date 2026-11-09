<?php

namespace App\Bridge\Writeback;

use App\Bridge\Support\GitHubRequestLog;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The one place a request to the GitHub REST API is shaped: the base URL, the headers GitHub
 * requires, the bearer token and the timeout. Hoisted out of {@see GitHubReadClient} at its second
 * caller ({@see GitHubWriteClient}, DL-390) so a read and a write cannot drift apart on the API
 * version or the User-Agent GitHub rejects a request without. Every request built here is
 * counted in the GitHub request log ({@see GitHubRequestLog}).
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
            ->timeout($timeoutSeconds)
            ->withMiddleware(GitHubRequestLog::middleware());
    }

    /** Whether GitHub's refusal says it is a rate limit, by its headers or its body. */
    public static function saysRateLimit(Response $response): bool
    {
        return self::headerSaysRateLimit($response) || self::bodySaysRateLimit($response);
    }

    /**
     * Whether GitHub's refusal is a rate limit by its status or headers: a 429, or a 403 carrying
     * `X-RateLimit-Remaining: 0` (a primary limit) or `Retry-After` (a secondary limit that may
     * leave Remaining above zero) — GitHub Docs, *Rate limits for the REST API*.
     */
    public static function headerSaysRateLimit(Response $response): bool
    {
        $status = $response->status();

        return $status === 429 || ($status === 403 && ($response->header('X-RateLimit-Remaining') === '0' || $response->header('Retry-After') !== ''));
    }

    /**
     * Whether a 403 says it is a rate limit only in its body's message — a secondary limit can carry
     * neither header. The body is read for this one test and never printed.
     */
    public static function bodySaysRateLimit(Response $response): bool
    {
        $message = $response->status() === 403 ? $response->json('message') : null;

        return is_string($message) && stripos($message, 'rate limit') !== false;
    }
}
