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
            'identity' => ['kanban_user_id' => 137],
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

    public function test_missing_socket_parent_dir_names_the_uid_mismatch(): void
    {
        // DL-039: a channel.socket whose PARENT dir is gone is the classic
        // uid-mismatch-after-restore break (the path pins /run/user/<uid>). The
        // error must name it, not the misleading "start the channel server first".
        try {
            $this->push(['intent' => ['kind' => 'new_card']], '/run/user/999999/nonexistent-dir/x.sock');
            $this->fail('expected HandlerException');
        } catch (HandlerException $e) {
            $this->assertStringContainsString('parent dir', $e->getMessage());
            $this->assertStringContainsString('uid mismatch', $e->getMessage());
        }
    }

    public function test_missing_socket_with_existing_parent_dir_says_start_the_server(): void
    {
        // Parent dir exists but no socket → the server just isn't up; the message
        // must point there, NOT at a uid mismatch (DL-039).
        try {
            $this->push(['intent' => ['kind' => 'new_card']], sys_get_temp_dir().'/chan-absent-'.uniqid().'.sock');
            $this->fail('expected HandlerException');
        } catch (HandlerException $e) {
            $this->assertStringContainsString('start the channel server first', $e->getMessage());
            $this->assertStringNotContainsString('uid mismatch', $e->getMessage());
        }
    }

    public function test_classifier_socket_missing_parent_dir_uses_generic_message(): void
    {
        // A CLASSIFIER-supplied socket missing its parent dir must NOT be blamed on
        // a uid restore / channel.socket — that narrative only fits the operator's
        // agent socket (DL-039, canon #10 honest attribution).
        config(['bridge.channel.allowed_socket_dir' => '/run']);
        try {
            $this->push(['socket' => '/run/user/999999/nonexistent-dir/x.sock', 'intent' => ['kind' => 'new_card']]);
            $this->fail('expected HandlerException');
        } catch (HandlerException $e) {
            $this->assertStringContainsString('parent dir', $e->getMessage());
            $this->assertStringNotContainsString('uid mismatch', $e->getMessage());
            $this->assertStringNotContainsString('channel.socket', $e->getMessage());
        }
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

    public function test_classifier_socket_rejected_when_no_allowed_dir(): void
    {
        // DL-014: a classifier-supplied socket is fail-closed unless the operator
        // configures an allowed dir.
        config(['bridge.channel.allowed_socket_dir' => null]);
        $this->expectException(HandlerException::class);
        $this->expectExceptionMessageMatches('/allowed_socket_dir/');
        $this->push(['socket' => '/run/some/'.uniqid().'.sock']);
    }

    public function test_classifier_socket_outside_allowed_dir_rejected(): void
    {
        config(['bridge.channel.allowed_socket_dir' => '/run/agent-bridge']);
        $this->expectException(HandlerException::class);
        $this->expectExceptionMessageMatches('/outside the allowed dir/');
        $this->push(['socket' => '/run/other/'.uniqid().'.sock']);
    }

    public function test_classifier_socket_traversal_rejected(): void
    {
        // `..` can't escape the prefix — refused before the prefix compare.
        config(['bridge.channel.allowed_socket_dir' => '/run/agent-bridge']);
        $this->expectException(HandlerException::class);
        $this->push(['socket' => '/run/agent-bridge/../other/x.sock']);
    }

    public function test_agent_config_socket_is_exempt_from_allowed_dir(): void
    {
        // No allowed_socket_dir set, but the socket comes from the AGENT's config
        // (operator-authored) → the DL-014 prefix gate is skipped; it fails later
        // at the does-not-exist check, NOT at the allowed_socket_dir gate.
        config(['bridge.channel.allowed_socket_dir' => null]);
        try {
            $this->push([], '/nonexistent/'.uniqid().'.sock');   // agent channel.socket
            $this->fail('expected HandlerException');
        } catch (HandlerException $e) {
            $this->assertStringNotContainsString('allowed_socket_dir', $e->getMessage());
            $this->assertStringContainsString('does not exist', $e->getMessage());
        }
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

    public function test_falls_back_to_agent_channel_url_when_no_socket(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $agent = AgentConfig::fromArray('prod-agent', [
            'identity' => ['kanban_user_id' => 137],
            'subscriptions' => [],
            'channel' => ['url' => 'http://127.0.0.1:8788/'],
        ]);

        // Payload carries neither socket nor url → falls back to channel.url.
        (new ChannelPushHandler)->handle(
            ReactionTarget::make('channel_push', '42', payload: ['kind' => 'new_card', 'subject_id' => '42']),
            $agent,
        );

        Http::assertSent(fn ($request) => $request->url() === 'http://127.0.0.1:8788/'
            && $request->data() === ['intent' => ['kind' => 'new_card', 'subject_id' => '42']]);
    }

    private function agentWithUrlAndToken(string $tokenPath): AgentConfig
    {
        return AgentConfig::fromArray('prod-agent', [
            'identity' => ['kanban_user_id' => 137],
            'subscriptions' => [],
            'channel' => ['url' => 'http://127.0.0.1:8789/', 'auth' => ['token_path' => $tokenPath]],
        ]);
    }

    public function test_config_token_attached_as_bearer_on_fallback_push(): void
    {
        Http::fake(['*' => Http::response('ok', 202)]);
        $tokenFile = tempnam(sys_get_temp_dir(), 'chtok');   // tempnam → 0600
        file_put_contents($tokenFile, "s3cr3t-value\n");     // trailing newline trimmed
        try {
            // Payload omits socket+url → endpoint AND auth come from agent config.
            (new ChannelPushHandler)->handle(
                ReactionTarget::make('channel_push', '42', payload: ['kind' => 'new_card', 'subject_id' => '42']),
                $this->agentWithUrlAndToken($tokenFile),
            );
            Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer s3cr3t-value')
                // Secret rides the header only — never the JSON body.
                && $request->data() === ['intent' => ['kind' => 'new_card', 'subject_id' => '42']]);
        } finally {
            @unlink($tokenFile);
        }
    }

    public function test_config_token_not_attached_to_classifier_supplied_url(): void
    {
        Http::fake(['*' => Http::response('ok', 202)]);
        $tokenFile = tempnam(sys_get_temp_dir(), 'chtok');
        file_put_contents($tokenFile, 'should-not-be-sent');
        try {
            // Payload sets its OWN url → not the agent-config endpoint, so the
            // agent's token must NOT ride along (it wasn't minted for this url).
            (new ChannelPushHandler)->handle(
                ReactionTarget::make('channel_push', '42', payload: ['url' => 'http://localhost:8788/', 'kind' => 'x']),
                $this->agentWithUrlAndToken($tokenFile),
            );
            Http::assertSent(fn ($request) => ! $request->hasHeader('Authorization'));
        } finally {
            @unlink($tokenFile);
        }
    }

    public function test_config_token_overrides_case_variant_payload_authorization(): void
    {
        Http::fake(['*' => Http::response('ok', 202)]);
        $tokenFile = tempnam(sys_get_temp_dir(), 'chtok');
        file_put_contents($tokenFile, 's3cr3t-value');
        try {
            // A classifier-emitted fallback target (no url/socket) that tries to
            // smuggle its own lowercase Authorization must NOT ride alongside the
            // config token — config auth is authoritative.
            (new ChannelPushHandler)->handle(
                ReactionTarget::make('channel_push', '42', payload: [
                    'kind' => 'x',
                    'headers' => ['authorization' => 'Bearer attacker'],
                ]),
                $this->agentWithUrlAndToken($tokenFile),
            );
            Http::assertSent(function ($request) {
                return $request->header('Authorization') === ['Bearer s3cr3t-value']
                    && ! str_contains(implode(',', $request->header('Authorization')), 'attacker');
            });
        } finally {
            @unlink($tokenFile);
        }
    }

    public function test_group_readable_token_file_rejected_fail_closed(): void
    {
        $tokenFile = tempnam(sys_get_temp_dir(), 'chtok');
        file_put_contents($tokenFile, 'secret');
        chmod($tokenFile, 0o640);   // group-readable → boundary defeated
        try {
            $this->expectException(HandlerException::class);
            (new ChannelPushHandler)->handle(
                ReactionTarget::make('channel_push', '42', payload: ['kind' => 'x']),
                $this->agentWithUrlAndToken($tokenFile),
            );
        } finally {
            @unlink($tokenFile);
        }
    }

    public function test_missing_token_file_rejected_fail_closed(): void
    {
        $this->expectException(HandlerException::class);
        (new ChannelPushHandler)->handle(
            ReactionTarget::make('channel_push', '42', payload: ['kind' => 'x']),
            $this->agentWithUrlAndToken('/nonexistent/'.uniqid().'.token'),
        );
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
