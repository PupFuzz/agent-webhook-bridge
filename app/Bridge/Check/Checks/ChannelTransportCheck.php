<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\PerAgentCheck;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\ChannelProbeEnvironment;
use App\Bridge\Support\Finding;

/**
 * Whether this agent's live-wake channel can actually be reached — the socket legs
 * (DL-039, FR #2444) and their HTTP counterparts — migrated out of
 * `CheckCommand::handle()`'s per-agent loop (DL-242 stage 5b).
 *
 * ONE CHECK FOR BOTH TRANSPORTS, because an agent has one: the inline code selected them
 * with an `elseif`, and two checks would each have to re-derive that exclusivity (the
 * HTTP one asserting `socket === null` to stay off a socket install). Owning the
 * selection here keeps the derivation in one place.
 *
 * EACH LEG ANSWERS A DIFFERENT QUESTION, WHICH IS WHY THERE ARE THREE.
 * - The PARENT DIR is config-vs-host: the socket itself may legitimately be absent at
 *   preflight (the channel server has not started), but a missing or unwritable parent
 *   is a real misconfig — classically a uid mismatch after a host restore, because the
 *   path pins `/run/user/<uid>`. It makes live-wake silently no-op.
 * - The `.FAILED` MARKER is the connector's own report: a session that lost the bind
 *   race exits with a message Claude Code swallows, leaving that session deaf
 *   invisibly. Surfacing the marker is what makes it loud on demand.
 * - The LIVENESS PROBE is the one leg that distinguishes a live consumer from a stale
 *   socket. A present socket file proves nothing — the bridge would still deliver
 *   HTTP 202 to a dead endpoint and log `delivered`.
 *
 * WARN, NEVER FAIL, THROUGHOUT: at preflight the channel server legitimately may not be
 * up yet, and the socket is its to create.
 *
 * TOPOLOGY CAVEAT ON THE HTTP ARM. `bridge:check` runs on the RECEIVER host, so for a
 * remote/tunneled agent the connector AND its `…http-<port>.FAILED` marker live on the
 * AGENT host, unreachable from here — the launcher surfaces that marker there (FR-1).
 * The marker read here is therefore best-effort for the co-located case, keyed on the
 * agent name as the best available proxy for the server's own `BRIDGE_CHANNEL_NAME`; a
 * miss is harmless. What IS meaningful cross-host is the probe: a TCP connect to the
 * loopback endpoint reaches the remote listener through the tunnel's local end.
 *
 * THE PROBE IS THE ONLY HOST FACT BEHIND A SEAM — see {@see ChannelProbeEnvironment} for
 * why the filesystem legs are not. NO GOLDEN FIXTURE REACHES EITHER PROBE OR THE HTTP
 * MARKER: the unix probe needs a socket file no fixture creates, the one `channel.url`
 * fixture has no port, and no fixture writes a marker. THE COMMAND-LEVEL SUITE DOES REACH
 * BOTH PROBES, though — mutating them reds
 * `BridgeCommandsTest::test_check_reports_channel_socket_live_when_a_session_listens`
 * (which stands up a real listener, and skips where `pcntl` is absent) and
 * `::test_check_reports_channel_http_endpoint_live_when_listener_present`. The MARKER leg
 * was not in that mutation run, so nothing here claims a whole-suite scope for it.
 * `ChannelTransportCheckTest` is what asserts all three directly. (Named, never
 * `{@see}`-linked: pint would turn the FQCN into a real `use`.)
 */
final class ChannelTransportCheck implements PerAgentCheck
{
    public function __construct(private readonly ChannelProbeEnvironment $probe) {}

    public function id(): string
    {
        return 'channel.transport';
    }

    /**
     * @return iterable<Finding>
     */
    public function runFor(AgentConfig $config, CheckContext $ctx): iterable
    {
        $channel = $config->channel;
        if ($channel->socket !== null) {
            yield from $this->socketLegs($config->agentName, $channel->socket);
        } elseif ($channel->url !== null) {
            yield from $this->httpLegs($config->agentName, $channel->url);
        }
        // else: no channel configured; stage 8 turns this into a returned disposition
    }

    /**
     * @return iterable<Finding>
     */
    private function socketLegs(string $name, string $socket): iterable
    {
        $dir = dirname($socket);
        if (! is_dir($dir)) {
            yield Finding::warn("agent {$name}: channel.socket parent dir {$dir} does not exist — live-wake will silently no-op. On systemd Linux this is /run/user/<uid>; a uid change (host restore) breaks it. Repoint channel.socket, or write it uid-agnostically as \${XDG_RUNTIME_DIR}/…");
        } elseif (! is_writable($dir)) {
            $uid = function_exists('posix_getuid') ? (string) posix_getuid() : '?';
            yield Finding::warn("agent {$name}: channel.socket parent dir {$dir} is not writable by this user (uid {$uid}) — live-wake will fail. Likely a uid mismatch after a host restore.");
        }

        $marker = $socket.'.FAILED';
        clearstatcache(true, $marker);
        if (is_file($marker)) {
            $detail = trim((string) @file_get_contents($marker));
            yield Finding::warn("agent {$name}: channel bind-FAILURE marker at {$marker}".($detail !== '' ? " ({$detail})" : '').' — a Claude Code session came up DEAF: its connector could not bind, so another session holds the channel and this one receives nothing. Close the duplicate session, restart the intended one, then rm the marker.');
        }

        // filetype() over a bare file_exists(): a path that is a regular file or a
        // symlink is a misconfig, not a channel, and connecting to it would report a
        // liveness verdict about the wrong thing.
        clearstatcache(true, $socket);
        if (is_dir($dir) && file_exists($socket)
            && ! is_link($socket)
            && filetype($socket) === 'socket'
        ) {
            if ($this->probe->probe('unix://'.$socket)['connected']) {
                yield Finding::ok("agent {$name}: channel socket live — a session is listening on {$socket}");
            } else {
                yield Finding::warn("agent {$name}: channel socket {$socket} exists but nothing is listening (stale socket / no live session) — live-wake no-ops until a session starts. If a session IS running, its connector may have come up deaf (look for a .FAILED marker).");
            }
        }
    }

    /**
     * @return iterable<Finding>
     */
    private function httpLegs(string $name, string $url): iterable
    {
        $parts = parse_url($url);
        $host = is_array($parts) && isset($parts['host']) ? $parts['host'] : '127.0.0.1';
        $port = is_array($parts) && isset($parts['port']) ? (int) $parts['port'] : null;

        if ($port === null) {
            yield Finding::warn("agent {$name}: channel.url {$url} has no explicit port — cannot liveness-probe the HTTP channel.");

            return;
        }

        $xdg = getenv('XDG_RUNTIME_DIR');
        $xdgDir = is_string($xdg) && $xdg !== '' ? $xdg : '/tmp';
        $httpMarker = $xdgDir.'/agent-webhook-bridge-channel-'.$name.'.http-'.$port.'.FAILED';
        clearstatcache(true, $httpMarker);
        if (is_file($httpMarker)) {
            $detail = trim((string) @file_get_contents($httpMarker));
            yield Finding::warn("agent {$name}: channel bind-FAILURE marker at {$httpMarker}".($detail !== '' ? " ({$detail})" : '').' — a Claude Code session came up DEAF on the HTTP transport (a TCP-port bind race). Close the duplicate session, restart the intended one, then rm the marker.');
        }

        $result = $this->probe->probe("tcp://{$host}:{$port}");
        if ($result['connected']) {
            yield Finding::ok("agent {$name}: channel HTTP endpoint live — something is listening on {$host}:{$port} (the connector, or the reverse-tunnel local end).");
        } else {
            yield Finding::warn("agent {$name}: channel HTTP endpoint {$host}:{$port} not answering".($result['error'] !== '' ? " ({$result['error']})" : '').' — no live session, or the reverse tunnel is down. live-wake no-ops until it is up.');
        }
    }
}
