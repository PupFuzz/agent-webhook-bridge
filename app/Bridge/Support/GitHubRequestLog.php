<?php

namespace App\Bridge\Support;

use App\Bridge\Writeback\GitHubApi;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * One line per GitHub REST request, written to the `github_requests` log channel so an
 * operator can count this install's share of the account's API use per path (card#12286).
 * A line is four tab-separated fields: UTC time, method, path, status — `ERR` when no response
 * came back. The path is the URI's path component alone, so the query string and fragment never
 * reach the line; no header, token or body is read.
 *
 * Installed on {@see GitHubApi::request()}, the builder every GitHub REST call in `app/` goes through.
 * Guzzle's redirect middleware sits outside this one, so each hop passes through it; a hop to
 * another host (a release asset's storage redirect) is not a REST call and is not logged.
 *
 * Outside `App\Bridge\Writeback` because its line is a count, not a board-mover diagnostic:
 * the catalog's `catalog_id` / `handler` / `op` context would be dropped by the channel's
 * formatter, and adding them would put more than the four fields on the line.
 */
final class GitHubRequestLog
{
    public const CHANNEL = 'github_requests';

    public static function middleware(): callable
    {
        return static fn (callable $handler): callable => static function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
            // A failure arrives as a rejected promise (curl's failed transfer) or as a throw from
            // inside the stack: curl's handle and option setup (`CurlFactory::create()`) raises
            // before anything is sent, and under `Http::fake()` the stub wrapper `wait()`s a
            // rejected stub such as `Http::failedConnection()` to build its transfer stats, so
            // that rejection is thrown here too, as is a stray-request refusal. A thrown attempt
            // left unlogged would undercount.
            try {
                /** @var PromiseInterface $promise */
                $promise = $handler($request, $options);
            } catch (Throwable $e) {
                self::record($request, 'ERR');

                throw $e;
            }

            return $promise->then(
                static function (ResponseInterface $response) use ($request): ResponseInterface {
                    self::record($request, (string) $response->getStatusCode());

                    return $response;
                },
                static function (mixed $reason) use ($request): PromiseInterface {
                    self::record($request, 'ERR');

                    return Create::rejectionFor($reason);
                },
            );
        };
    }

    private static function line(RequestInterface $request, string $status): string
    {
        return implode("\t", [now('UTC')->format('Y-m-d\TH:i:s\Z'), $request->getMethod(), $request->getUri()->getPath(), $status]);
    }

    private static function record(RequestInterface $request, string $status): void
    {
        try {
            if ($request->getUri()->getHost() !== (string) parse_url(GitHubApi::BASE, PHP_URL_HOST)) {
                return;
            }
            Log::channel(self::CHANNEL)->info(self::line($request, $status));
        } catch (Throwable) {
            // A count line is never worth a request: an unwritable log leaves the response as it was.
        }
    }
}
