<?php

namespace App\Bridge\Handlers;

use App\Bridge\Contracts\Handler;
use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Exceptions\HandlerException;
use App\Bridge\Support\AgentConfig;
use Illuminate\Support\Facades\Http;

/**
 * Push the intent to a local channel endpoint so an active Claude Code session
 * sees it live. Two mutually-exclusive transports:
 *
 *  - socket: an absolute path to a Unix domain socket (recommended; trust
 *    boundary is filesystem perms). Validated: absolute, exists, not a
 *    symlink (TOCTOU defense), is a real socket.
 *  - url: a localhost HTTP endpoint. SSRF-gated: scheme must be http, host
 *    must be 127.0.0.1 / localhost / [::1], no userinfo.
 *
 * When neither is in the payload, falls back to the agent's configured
 * channel.socket. Best-effort: a connection-refused (no session up) throws and
 * is recorded — the durable inbox backstop already holds the intent. The short
 * timeout keeps the synchronous webhook response under kanban-board's delivery
 * timeout.
 */
final class ChannelPushHandler implements Handler
{
    /**
     * Payload keys that configure the handler — stripped from the default body
     * envelope so handler-config never leaks into the channel server's view.
     *
     * @var list<string>
     */
    private const HANDLER_FIELDS = ['url', 'socket', 'method', 'timeout_seconds', 'headers', 'body'];

    /**
     * @var list<string>
     */
    private const ALLOWED_METHODS = ['POST', 'PUT', 'PATCH'];

    public function handle(ReactionTarget $target, AgentConfig $agent): void
    {
        $payload = $target->payload;

        $socket = $payload['socket'] ?? null;
        $url = $payload['url'] ?? null;

        // Key-absent (not falsy) fallback: payload {"url": ""} still errors —
        // an empty string is a classifier bug, not a fallback signal. The
        // agent's configured channel.socket wins; channel.url is the fallback
        // for the SSH-tunneled remote-host case (they're mutually exclusive in
        // config, so at most one is non-null).
        if (! array_key_exists('socket', $payload) && ! array_key_exists('url', $payload)) {
            if ($agent->channelSocket !== null) {
                $socket = $agent->channelSocket;
            } elseif ($agent->channelUrl !== null) {
                $url = $agent->channelUrl;
            }
        }

        $socketSet = is_string($socket) && $socket !== '';
        $urlSet = is_string($url) && $url !== '';

        if ($socketSet && $urlSet) {
            throw new HandlerException("channel_push: payload must specify exactly one of 'socket' or 'url', not both");
        }
        if (! $socketSet && ! $urlSet) {
            throw new HandlerException("channel_push: payload must specify 'socket' or 'url' (no per-agent default available)");
        }

        $method = $payload['method'] ?? 'POST';
        if (! is_string($method) || ! in_array($method, self::ALLOWED_METHODS, true)) {
            throw new HandlerException('channel_push: payload.method must be one of '.implode('/', self::ALLOWED_METHODS));
        }

        $timeout = $this->resolveTimeout($payload['timeout_seconds'] ?? 2.0);
        $body = $this->buildBody($payload);
        $headers = $this->buildHeaders($payload);

        if ($socketSet) {
            /** @var string $socket */
            $this->validateSocketPath($socket);
            $request = Http::connectTimeout(1)->timeout($timeout)
                ->withOptions(['curl' => [CURLOPT_UNIX_SOCKET_PATH => $socket]])
                ->withHeaders($headers);
            $request->send($method, 'http://localhost/', ['json' => $body])->throw();

            return;
        }

        /** @var string $url */
        $this->validateLocalhostUrl($url);
        Http::connectTimeout(1)->timeout($timeout)
            ->withHeaders($headers)
            ->send($method, $url, ['json' => $body])
            ->throw();
    }

    private function resolveTimeout(mixed $value): float
    {
        if (! is_numeric($value)) {
            throw new HandlerException('channel_push: payload.timeout_seconds must be a number');
        }
        $timeout = (float) $value;
        if ($timeout <= 0) {
            throw new HandlerException('channel_push: payload.timeout_seconds must be positive');
        }

        return $timeout;
    }

    /**
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    private function buildBody(array $payload): array
    {
        $body = $payload['body'] ?? null;
        if ($body === null) {
            // Default envelope: {"intent": <payload minus handler-config>}.
            // No freeze/thaw — payloads are plain arrays in PHP.
            return ['intent' => array_diff_key($payload, array_flip(self::HANDLER_FIELDS))];
        }
        if (is_array($body)) {
            // The channel wire contract is a JSON object, not an array.
            if ($body !== [] && array_is_list($body)) {
                throw new HandlerException('channel_push: payload.body must be a JSON object, not an array');
            }

            return $body;
        }

        throw new HandlerException('channel_push: payload.body must be a JSON object');
    }

    /**
     * @param  array<mixed>  $payload
     * @return array<string, string>
     */
    private function buildHeaders(array $payload): array
    {
        $headers = ['Content-Type' => 'application/json'];
        $custom = $payload['headers'] ?? null;
        if (is_array($custom)) {
            foreach ($custom as $name => $value) {
                if (is_string($name) && is_scalar($value)) {
                    $headers[$name] = (string) $value;
                }
            }
        }

        return $headers;
    }

    private function validateSocketPath(string $path): void
    {
        if (! str_starts_with($path, '/')) {
            throw new HandlerException("channel_push: payload.socket must be an absolute path (got {$path})");
        }
        clearstatcache(true, $path);
        if (! file_exists($path) && ! is_link($path)) {
            throw new HandlerException("channel_push: payload.socket does not exist (start the channel server first): {$path}");
        }
        // lstat-based: a symlink at the socket path is a TOCTOU vector (a
        // same-uid attacker could swap its target between check and connect).
        if (is_link($path)) {
            throw new HandlerException("channel_push: payload.socket must not be a symlink: {$path}");
        }
        if (filetype($path) !== 'socket') {
            throw new HandlerException("channel_push: payload.socket is not a Unix domain socket: {$path}");
        }
    }

    private function validateLocalhostUrl(string $url): void
    {
        $parts = parse_url($url);
        // Normalize scheme + host: PHP parse_url (unlike Python urlparse) does
        // not lowercase the scheme and keeps the brackets on an IPv6 host, so
        // `HTTP://` and `[::1]` would otherwise miss the whitelist.
        if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'http') {
            throw new HandlerException('channel_push: payload.url must be http:// (loopback only)');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new HandlerException('channel_push: payload.url must not contain a userinfo component');
        }
        $host = strtolower(trim($parts['host'] ?? '', '[]'));
        if (! in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            throw new HandlerException('channel_push: payload.url must point at 127.0.0.1, localhost, or [::1]');
        }
    }
}
