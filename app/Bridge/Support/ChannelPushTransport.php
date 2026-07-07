<?php

namespace App\Bridge\Support;

use Illuminate\Support\Facades\Http;

/**
 * The shared UNIX-socket / loopback-URL JSON transport for pushing onto an agent
 * channel. Both the live `channel_push` handler (`ChannelPushHandler`) and the
 * best-effort writeback move-failure alert (`WritebackAlertNotifier`) reach the
 * channel the same way; this collapses their `Http` call sites (a socket + a url
 * branch each — semantically identical modulo method/timeout, hence those
 * params) into one, the shared extract the v0.43.0 changelog flagged.
 *
 * Endpoint VALIDATION deliberately stays at the call sites: the two surfaces gate
 * their socket/url differently (the handler additionally prefix-gates a
 * classifier-supplied socket and throws `HandlerException`; the notifier throws
 * `RuntimeException`, with distinct subject strings). Only the transport itself
 * is shared — callers MUST validate the endpoint before calling {@see send}.
 */
final class ChannelPushTransport
{
    /**
     * Send a JSON body to a channel endpoint — exactly one of $socket / $url,
     * the other null. A UNIX socket connects via `CURLOPT_UNIX_SOCKET_PATH` to
     * `http://localhost/`; a URL is sent directly. `connectTimeout(1)` bounds a
     * dead endpoint and `->throw()` surfaces a non-2xx so the caller's failure
     * path runs.
     *
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $body
     */
    public static function send(?string $socket, ?string $url, string $method, array $headers, array $body, float $timeout): void
    {
        $request = Http::connectTimeout(1)->timeout($timeout)->withHeaders($headers);

        if ($socket !== null) {
            $request->withOptions(['curl' => [CURLOPT_UNIX_SOCKET_PATH => $socket]])
                ->send($method, 'http://localhost/', ['json' => $body])
                ->throw();

            return;
        }

        $request->send($method, (string) $url, ['json' => $body])->throw();
    }
}
