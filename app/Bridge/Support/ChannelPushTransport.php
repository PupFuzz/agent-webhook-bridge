<?php

namespace App\Bridge\Support;

use Illuminate\Http\Client\Response;
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
     * THE RESPONSE IS RETURNED, not swallowed, and that is what makes a 2xx here
     * readable as the narrow thing it is (card#9172). `->throw()` makes a 2xx the
     * SOLE success condition, so every caller's success path is built out of one,
     * and a 2xx on a channel says only that the far end accepted the write. What
     * more it does or does not promise is the far end's to DECLARE (canon #7) and
     * the caller's to read off this response — never this transport's to assume.
     *
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $body
     */
    public static function send(?string $socket, ?string $url, string $method, array $headers, array $body, float $timeout): Response
    {
        $request = Http::connectTimeout(1)->timeout($timeout)->withHeaders($headers);

        if ($socket !== null) {
            return $request->withOptions(['curl' => [CURLOPT_UNIX_SOCKET_PATH => $socket]])
                ->send($method, 'http://localhost/', ['json' => $body])
                ->throw();
        }

        return $request->send($method, (string) $url, ['json' => $body])->throw();
    }
}
