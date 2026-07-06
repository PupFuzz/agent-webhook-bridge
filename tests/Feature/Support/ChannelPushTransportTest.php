<?php

namespace Tests\Feature\Support;

use App\Bridge\Support\ChannelPushTransport;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Contract of the shared channel-push transport (the extract behind
 * ChannelPushHandler + WritebackAlertNotifier). The socket branch's real
 * curl/UDS round-trip is covered end-to-end by ChannelPushUdsTest; this pins the
 * url branch's method/headers/body pass-through and the non-2xx throw.
 */
class ChannelPushTransportTest extends TestCase
{
    public function test_url_send_passes_method_headers_and_json_body(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        ChannelPushTransport::send(
            socket: null,
            url: 'http://localhost:8788/',
            method: 'PUT',
            headers: ['Content-Type' => 'application/json', 'Authorization' => 'Bearer tok'],
            body: ['type' => 'writeback_move_failed', 'repo' => 'o/r'],
            timeout: 2.0,
        );

        Http::assertSent(function ($request) {
            return $request->method() === 'PUT'
                && $request->url() === 'http://localhost:8788/'
                && $request->hasHeader('Authorization', 'Bearer tok')
                && $request->data() === ['type' => 'writeback_move_failed', 'repo' => 'o/r'];
        });
    }

    public function test_non_2xx_response_throws(): void
    {
        Http::fake(['*' => Http::response('nope', 500)]);

        $this->expectException(RequestException::class);

        ChannelPushTransport::send(null, 'http://localhost:8788/', 'POST', [], ['x' => 1], 2.0);
    }
}
