<?php

namespace App\Bridge\Handlers;

use App\Bridge\Contracts\Handler;
use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Exceptions\ChannelTokenException;
use App\Bridge\Exceptions\HandlerException;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\ChannelPushTransport;
use App\Bridge\Support\ChannelToken;
use App\Bridge\Support\SecretScrubber;
use App\Bridge\Validation\EndpointValidationException;
use App\Bridge\Validation\LocalhostUrl;
use App\Bridge\Validation\SocketEndpoint;
use App\Bridge\Validation\SocketPath;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

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

    /**
     * The header a channel endpoint DECLARES its delivery-receipt status on (card#9172).
     * The shipped reference server answers `none` on its 202: it resolves the push once
     * the notification is written to the stdio transport, and the MCP notification
     * contract gives it nothing back about what the session did with it.
     *
     * READ, NEVER ASSUMED. The bridge could hard-code "channel pushes are unconfirmed"
     * and be right about the server it ships — but the endpoint is operator-configurable,
     * and a belief restated on this side of a seam is a copy that drifts when the far end
     * moves (canon #7 DECLARE/CHECK, canon #16). An endpoint that sends no such header
     * has told the bridge nothing, and {@see reportAcceptance} says exactly that rather
     * than picking an answer for it.
     */
    private const RECEIPT_HEADER = 'X-Channel-Delivery-Receipt';

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
            $this->validateSocketPath($socket, $usedAgentChannel);
            $this->reportAcceptance(
                ChannelPushTransport::send($socket, null, $method, $headers, $body, $timeout),
                $target,
                $agent,
            );

            return;
        }

        /** @var string $url */
        $this->validateLocalhostUrl($url);
        $this->reportAcceptance(
            ChannelPushTransport::send(null, $url, $method, $headers, $body, $timeout),
            $target,
            $agent,
        );
    }

    /**
     * What the push actually established, and what the far end said it could establish
     * (card#9172).
     *
     * The transport `->throw()`s on any non-2xx, so reaching here means the endpoint
     * accepted the write and NOTHING MORE: the bridge holds no receipt that the seat
     * received the intent. ⚠ It equally holds no evidence that the seat did NOT — whether
     * an unseen notification is dropped or deferred to the session's next turn boundary
     * is not established here, and this line claims neither. The whole point is that the
     * question has no answer on this path, which is why it is stated rather than left for
     * an operator to read out of a bare success.
     */
    private function reportAcceptance(Response $response, ReactionTarget $target, AgentConfig $agent): void
    {
        // ⛔ SCRUBBED AND BOUNDED BEFORE IT IS LOGGED. The header value is composed by the
        // ENDPOINT, not by the bridge, which is exactly what {@see SecretScrubber}'s own
        // docblock says must pass through it before reaching an operator-facing stream —
        // and this line runs once per push, the busiest such stream the bridge has, so an
        // unbounded value lets the far end choose how long every one of them is.
        //
        // The bound is taken over the COMPOSED value, not over the header value alone, so
        // what is capped is the thing an operator actually reads. The header NAME leads,
        // so it survives the truncation and the line still says which declaration it is
        // reporting. The declared-nothing arm below is text the BRIDGE composed and is
        // deliberately not truncated.
        $declared = $response->header(self::RECEIPT_HEADER);

        Log::info('bridge channel_push: accepted by transport (unconfirmed)', [
            'agent' => $agent->agentName,
            'target_id' => $target->targetId,
            'status' => $response->status(),
            'endpoint_declares' => $declared !== ''
                ? mb_strimwidth(self::RECEIPT_HEADER.': '.SecretScrubber::text($declared), 0, 200, '…')
                : 'declared nothing — this endpoint sends no '.self::RECEIPT_HEADER
                    .' header, so whether it can confirm a seat received a push is unknown to the bridge',
        ]);
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

    private function validateSocketPath(string $path, bool $isAgentSocket = true): void
    {
        // The absolute-path gate stays here (not in SocketEndpoint): the agent
        // socket accepts a bare `/`-prefixed path while the classifier path is
        // already `..`-gated upstream by assertClassifierSocketAllowed — sharing
        // it would change what each surface accepts.
        if (! str_starts_with($path, '/')) {
            throw new HandlerException("channel_push: payload.socket must be an absolute path (got {$path})");
        }
        // The uid-restore narrative only fits the operator's channel.socket — a
        // classifier-supplied socket gets the plain parent-dir message so we never
        // misattribute its missing dir to a config/uid problem (canon #10).
        try {
            SocketEndpoint::assertValid(
                $path,
                subject: 'channel_push: payload.socket',
                parentSubject: 'channel_push: socket',
                configField: 'channel.socket',
                diagnoseUidMismatch: $isAgentSocket,
            );
        } catch (EndpointValidationException $e) {
            throw new HandlerException($e->getMessage(), 0, $e);
        }
    }

    private function validateLocalhostUrl(string $url): void
    {
        try {
            LocalhostUrl::assertValid($url, 'channel_push: payload.url');
        } catch (EndpointValidationException $e) {
            throw new HandlerException($e->getMessage(), 0, $e);
        }
    }
}
