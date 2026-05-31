<?php

namespace App\Bridge\Handlers;

use App\Bridge\Contracts\Handler;
use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Exceptions\ChannelTokenException;
use App\Bridge\Exceptions\HandlerException;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\ChannelToken;
use App\Bridge\Validation\SocketPath;
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
 *
 * Auth (DL-008): when the endpoint comes from the agent's own config (the
 * fallback branch), the agent's channel.auth.token_path — if set — is read
 * fail-closed and attached as `Authorization: Bearer <token>`. The token is
 * read at point-of-use and never placed in the payload (which is staged to
 * inbox.jsonl and the dispatch ledger), keeping the secret out of every
 * serializable/logged structure. A classifier-emitted target that sets its OWN
 * url does NOT get the agent's token — the predicate is "endpoint is agent-
 * config-sourced", so a credential minted for the agent's endpoint never rides
 * a target the classifier pointed elsewhere (it carries its own `headers`).
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
        $usedAgentChannel = false;
        if (! array_key_exists('socket', $payload) && ! array_key_exists('url', $payload)) {
            if ($agent->channel->socket !== null) {
                $socket = $agent->channel->socket;
                $usedAgentChannel = true;
            } elseif ($agent->channel->url !== null) {
                $url = $agent->channel->url;
                $usedAgentChannel = true;
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

        // Attach the agent's configured Bearer token ONLY when the endpoint came
        // from agent config (never a classifier-supplied url — see class doc).
        if ($usedAgentChannel && $agent->channel->tokenPath !== null) {
            try {
                $token = ChannelToken::read($agent->channel->tokenPath);
            } catch (ChannelTokenException $e) {
                throw new HandlerException('channel_push: '.$e->getMessage());
            }
            // Config auth is authoritative: drop any payload-supplied Authorization
            // (case-insensitively — PSR-7/Guzzle merge same-name headers, so a
            // lowercase 'authorization' would otherwise ride alongside ours).
            foreach (array_keys($headers) as $name) {
                if (strcasecmp((string) $name, 'Authorization') === 0) {
                    unset($headers[$name]);
                }
            }
            $headers['Authorization'] = 'Bearer '.$token;
        }

        if ($socketSet) {
            /** @var string $socket */
            if (! $usedAgentChannel) {
                // Classifier-supplied socket (payload, attacker-influenced):
                // constrain it to the operator-configured prefix so a custom
                // classifier can't point the push at another tenant's UDS (DL-014;
                // same trust class as spawn_detached). The agent's own
                // channel.socket is operator-authored and exempt.
                $this->assertClassifierSocketAllowed($socket);
            }
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

    /**
     * Fail-closed prefix gate for a CLASSIFIER-supplied socket (DL-014). The
     * SocketPath::isValid check rejects `..` segments, so the str_starts_with
     * prefix test below can't be escaped (`/allowed/../other.sock` is refused
     * before it reaches the prefix compare).
     */
    private function assertClassifierSocketAllowed(string $socket): void
    {
        if (! SocketPath::isValid($socket)) {
            throw new HandlerException("channel_push: classifier-supplied socket is not a valid absolute path (no '..'): {$socket}");
        }
        $allowed = config('bridge.channel.allowed_socket_dir');
        if (! is_string($allowed) || $allowed === '') {
            throw new HandlerException('channel_push: a classifier-supplied socket requires bridge.channel.allowed_socket_dir (BRIDGE_CHANNEL_ALLOWED_SOCKET_DIR) to be set; an agent\'s own channel.socket is exempt');
        }
        $prefix = rtrim($allowed, '/').'/';
        if (! str_starts_with($socket, $prefix)) {
            throw new HandlerException("channel_push: classifier-supplied socket {$socket} is outside the allowed dir {$allowed}");
        }
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
