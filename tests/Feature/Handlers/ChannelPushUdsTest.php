<?php

namespace Tests\Feature\Handlers;

use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Handlers\ChannelPushHandler;
use App\Bridge\Support\AgentConfig;
use Tests\TestCase;

/**
 * Real Unix-domain-socket round-trip through curl (not Http::fake), so a
 * Guzzle/curl UDS regression is caught in CI. A forked child binds a UDS,
 * accepts one connection, captures the POSTed body, and replies 200; the
 * parent runs the real ChannelPushHandler against that socket.
 */
class ChannelPushUdsTest extends TestCase
{
    public function test_posts_intent_body_over_unix_socket(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl required for the UDS round-trip listener');
        }

        $socketPath = sys_get_temp_dir().'/chan-'.uniqid().'.sock';
        $resultFile = sys_get_temp_dir().'/chan-'.uniqid().'.body';

        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->fail('pcntl_fork failed');
        }

        if ($pid === 0) {
            // --- child: one-shot UDS HTTP listener ---
            $server = @stream_socket_server('unix://'.$socketPath, $errno, $errstr);
            if ($server === false) {
                exit(1);
            }
            $conn = @stream_socket_accept($server, 5);
            if ($conn !== false) {
                stream_set_timeout($conn, 5);
                $raw = '';
                while (! str_contains($raw, "\r\n\r\n")) {
                    $chunk = fread($conn, 4096);
                    if ($chunk === '' || $chunk === false) {
                        break;
                    }
                    $raw .= $chunk;
                }
                $split = explode("\r\n\r\n", $raw, 2);
                $head = $split[0];
                $body = $split[1] ?? '';
                if (preg_match('/Content-Length:\s*(\d+)/i', $head, $m) === 1) {
                    $need = (int) $m[1];
                    while (strlen($body) < $need) {
                        $chunk = fread($conn, 4096);
                        if ($chunk === '' || $chunk === false) {
                            break;
                        }
                        $body .= $chunk;
                    }
                }
                file_put_contents($resultFile, $body);
                fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nok");
                fclose($conn);
            }
            fclose($server);
            exit(0);
        }

        // --- parent: wait for the socket to bind, then push through it ---
        $deadline = microtime(true) + 5.0;
        while (! file_exists($socketPath) && microtime(true) < $deadline) {
            usleep(20_000);
        }
        $this->assertFileExists($socketPath, 'child failed to bind the UDS');

        $agent = AgentConfig::fromArray('prod-agent', [
            'identity' => ['self' => 'prod-agent'],
            'api' => ['kanban' => ['base_url' => 'https://k.example.com', 'token_path' => '/t']],
            'receiver' => ['base_url' => 'https://b.example.com/webhooks'],
            'subscriptions' => [],
        ]);

        (new ChannelPushHandler)->handle(
            ReactionTarget::make('channel_push', '42', payload: ['socket' => $socketPath, 'kind' => 'new_card', 'subject_id' => '42']),
            $agent,
        );

        pcntl_waitpid($pid, $status);

        $received = json_decode((string) @file_get_contents($resultFile), true);
        @unlink($socketPath);
        @unlink($resultFile);

        $this->assertSame(['intent' => ['kind' => 'new_card', 'subject_id' => '42']], $received);
    }
}
