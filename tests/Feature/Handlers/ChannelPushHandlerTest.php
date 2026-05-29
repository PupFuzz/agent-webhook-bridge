<?php

namespace Tests\Feature\Handlers;

use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Exceptions\HandlerException;
use App\Bridge\Handlers\ChannelPushHandler;
use App\Bridge\Support\AgentConfig;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChannelPushHandlerTest extends TestCase
{
    private function agent(?string $channelSocket = null): AgentConfig
    {
        $raw = [
            'identity' => ['self' => 'prod-agent'],
            'api' => ['kanban' => ['base_url' => 'https://k.example.com', 'token_path' => '/t']],
            'receiver' => ['base_url' => 'https://b.example.com/webhooks'],
            'subscriptions' => [],
        ];
        if ($channelSocket !== null) {
            $raw['channel'] = ['socket' => $channelSocket];
        }

        return AgentConfig::fromArray('prod-agent', $raw);
    }

    private function push(array $payload, ?string $channelSocket = null): void
    {
        (new ChannelPushHandler)->handle(
            ReactionTarget::make('channel_push', 'card-1', payload: $payload),
            $this->agent($channelSocket),
        );
    }

    public function test_both_socket_and_url_throws(): void
    {
        $this->expectException(HandlerException::class);
        $this->push(['socket' => '/run/x.sock', 'url' => 'http://localhost:8788']);
    }

    public function test_neither_and_no_default_throws(): void
    {
        $this->expectException(HandlerException::class);
        $this->push(['intent' => ['kind' => 'new_card']]);
    }

    public function test_non_localhost_url_rejected_ssrf(): void
    {
        $this->expectException(HandlerException::class);
        $this->push(['url' => 'http://evil.example.com/steal']);
    }

    public function test_userinfo_url_rejected(): void
    {
        $this->expectException(HandlerException::class);
        $this->push(['url' => 'http://user:pass@localhost:8788/']);
    }

    public function test_https_url_rejected(): void
    {
        $this->expectException(HandlerException::class);
        $this->push(['url' => 'https://localhost:8788/']);
    }

    public function test_bad_method_rejected(): void
    {
        $this->expectException(HandlerException::class);
        $this->push(['url' => 'http://localhost:8788/', 'method' => 'DELETE']);
    }

    public function test_non_positive_timeout_rejected(): void
    {
        $this->expectException(HandlerException::class);
        $this->push(['url' => 'http://localhost:8788/', 'timeout_seconds' => 0]);
    }

    public function test_relative_socket_rejected(): void
    {
        $this->expectException(HandlerException::class);
        $this->push(['socket' => 'relative/x.sock']);
    }

    public function test_nonexistent_socket_rejected(): void
    {
        $this->expectException(HandlerException::class);
        $this->push(['socket' => '/nonexistent/'.uniqid().'.sock']);
    }

    public function test_regular_file_socket_rejected(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'notsock');
        try {
            $this->expectException(HandlerException::class);
            $this->push(['socket' => $file]);
        } finally {
            @unlink($file);
        }
    }

    public function test_symlink_socket_rejected_toctou(): void
    {
        $target = tempnam(sys_get_temp_dir(), 'realtarget');
        $link = sys_get_temp_dir().'/sym-'.uniqid().'.sock';
        symlink($target, $link);
        try {
            $this->expectException(HandlerException::class);
            $this->push(['socket' => $link]);
        } finally {
            @unlink($link);
            @unlink($target);
        }
    }

    public function test_localhost_url_dispatch_sends_default_intent_envelope(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $this->push(['url' => 'http://localhost:8788/', 'kind' => 'new_card', 'subject_id' => '42']);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->url() === 'http://localhost:8788/'
                && $request->method() === 'POST'
                // Default envelope wraps the payload-minus-handler-fields under "intent".
                && $body === ['intent' => ['kind' => 'new_card', 'subject_id' => '42']];
        });
    }

    public function test_explicit_body_passed_through(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $this->push(['url' => 'http://localhost:8788/', 'body' => ['custom' => true]]);

        Http::assertSent(fn ($request) => $request->data() === ['custom' => true]);
    }

    public function test_non_array_body_rejected(): void
    {
        $this->expectException(HandlerException::class);
        $this->push(['url' => 'http://localhost:8788/', 'body' => 'not-an-object']);
    }

    public function test_list_body_rejected(): void
    {
        // The channel wire contract is a JSON object, not an array.
        $this->expectException(HandlerException::class);
        $this->push(['url' => 'http://localhost:8788/', 'body' => [1, 2, 3]]);
    }

    public function test_ipv6_and_uppercase_loopback_accepted(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $this->push(['url' => 'http://[::1]:8788/', 'kind' => 'x']);
        $this->push(['url' => 'HTTP://LOCALHOST:8788/', 'kind' => 'x']);

        Http::assertSentCount(2);   // both normalize to the loopback whitelist
    }

    public function test_channel_socket_fallback_used_when_neither_key_present(): void
    {
        // Bind a real UDS so validateSocketPath passes; Http::fake intercepts
        // the actual send, so we only assert the fallback routed to the socket.
        $socketPath = sys_get_temp_dir().'/fallback-'.uniqid().'.sock';
        $server = stream_socket_server('unix://'.$socketPath, $errno, $errstr);
        $this->assertNotFalse($server, "failed to bind UDS: {$errstr}");

        Http::fake(['*' => Http::response('ok', 200)]);
        try {
            $this->push(['kind' => 'new_card', 'subject_id' => '42'], channelSocket: $socketPath);
            Http::assertSent(fn ($request) => $request->data() === ['intent' => ['kind' => 'new_card', 'subject_id' => '42']]);
        } finally {
            fclose($server);
            @unlink($socketPath);
        }
    }
}
