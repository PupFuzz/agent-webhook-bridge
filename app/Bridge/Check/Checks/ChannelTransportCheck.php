<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\PerAgentCheck;
use App\Bridge\Check\Silence;
use App\Bridge\Exceptions\PathResolvesToNoFileException;
use App\Bridge\Exceptions\UnreadableFileException;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\ChannelProbeEnvironment;
use App\Bridge\Support\Finding;
use App\Bridge\Support\PathVisibility;
use App\Bridge\Support\Provenance;
use App\Bridge\Support\Untrusted;
use App\Bridge\Support\UntrustedPathContents;

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
 * NEVER FAIL, THROUGHOUT: at preflight the channel server legitimately may not be
 * up yet, and the socket is its to create. Said "WARN, NEVER FAIL" until DL-251 — the
 * HTTP arm's no-explicit-port exit is `unvalidated`, because with no port there is
 * nothing to connect to and the liveness leg never ran.
 *
 * TOPOLOGY CAVEAT ON THE HTTP ARM. `bridge:check` runs on the RECEIVER host, so for a
 * remote/tunneled agent the connector AND its `…http-<port>.FAILED` marker live on the
 * AGENT host, unreachable from here — the launcher surfaces that marker there (FR-1).
 * The marker read here is therefore best-effort for the co-located case, keyed on the
 * agent name as the best available proxy for the server's own `BRIDGE_CHANNEL_NAME`; a
 * miss is harmless. What IS meaningful cross-host is the probe: a TCP connect to the
 * loopback endpoint reaches the remote listener through the tunnel's local end.
 * ⛔ AND THE HTTP MARKER LEG DOES NOT RUN AT ALL WITHOUT `XDG_RUNTIME_DIR` (card#9121,
 * DL-366): with no per-user runtime dir there is no path a marker could sit at that this
 * process could attribute to the connector, so the leg reports `unvalidated` and reads
 * nothing rather than reading a world-writable one. The SOCKET marker is unaffected — its
 * directory belongs to the agent account, which is a trust relationship the install
 * already has.
 *
 * THE PROBE IS THE ONLY HOST FACT BEHIND A SEAM — see {@see ChannelProbeEnvironment} for
 * why the filesystem legs are not. NO GOLDEN FIXTURE REACHES EITHER PROBE OR THE HTTP
 * MARKER: the unix probe needs a socket file no fixture creates, the one `channel.url`
 * fixture has no port, and no fixture writes a marker. THE COMMAND-LEVEL SUITE DOES REACH
 * BOTH PROBES, though — mutating them reds
 * `BridgeCommandsTest::test_check_reports_channel_socket_live_when_a_session_listens`
 * (which stands up a real in-process listener, on every host — card#7209 removed the fork
 * and with it the `pcntl` skip) and
 * `::test_check_reports_channel_http_endpoint_live_when_listener_present`. The MARKER leg
 * was not in that mutation run, so nothing here claims a whole-suite scope for it.
 * `ChannelTransportCheckTest` is what asserts all three directly. (Named, never
 * `{@see}`-linked: pint would turn the FQCN into a real `use`.)
 */
final class ChannelTransportCheck implements PerAgentCheck
{
    /**
     * ONE tail for BOTH transports' bind-FAILURE marker findings, and it adds NOTHING the
     * marker did not say.
     *
     * ⭐ WHY IT NAMES NO CAUSE. The tails this replaced asserted one — "so another session
     * holds the channel … Close the duplicate session" (unix) and "a TCP-port bind race"
     * (http). The bridge cannot establish either: it reads a file another account's process
     * wrote and has no way to see who holds the address. On the case roundtable #420
     * actually measured the holder was the SEAT'S OWN previous channel server after a
     * re-provision, so both tails sent the reader after a duplicate session that did not
     * exist.
     *
     * ⭐ WHY IT DOES NOT SUMMARISE THE MARKER EITHER. The marker was written by whatever
     * connector SNAPSHOT that seat is running, and this install cannot know which:
     * `_deploy_snapshot` reconciles only on a role-b run and short-circuits at
     * equal-or-newer (DL-237). A connector older than 0.9.13 writes a body naming only
     * "another session". So the tail POINTS at the doc that owns the cause list — which is
     * the authority for those older bodies too — rather than restating a list that may not
     * match the body printed right beside it.
     *
     * Valid with an empty `$detail`: it is a sentence about the marker's existence, not
     * about its contents.
     */
    private const MARKER_TAIL = ' — a Claude Code session came up DEAF: its connector could not bind the channel. Causes and remedies: docs/board-tools-enablement.md § Activating on a running seat. rm the marker once resolved.';

    public function __construct(private readonly ChannelProbeEnvironment $probe) {}

    public function id(): string
    {
        return 'channel.transport';
    }

    /**
     * @return iterable<Finding|Silence>
     */
    public function runFor(AgentConfig $config, CheckContext $ctx): iterable
    {
        $channel = $config->channel;
        if ($channel->socket !== null) {
            yield from $this->socketLegs($config->agentName, $channel->socket);
        } elseif ($channel->url !== null) {
            yield from $this->httpLegs($config->agentName, $channel->url);
        } else {
            // DECLARED HERE AND NOT AS ONE TRAILING LINE (card#5596): this arm's silence
            // and a clean socket leg's silence are different facts about the install, and
            // a single declaration at the end of the method would launder them into one
            // sentence that is only true of one of them.
            yield Silence::because('this agent configures neither channel.socket nor channel.url, so there is no transport to probe — an agent that needs one is reported where the push is attempted');
        }
    }

    /**
     * @return iterable<Finding|Silence>
     */
    private function socketLegs(string $name, string $socket): iterable
    {
        $dir = dirname($socket);
        if (! is_dir($dir)) {
            yield PathVisibility::unverifiedUnlessVisible($dir, Provenance::ownConfig("agent {$name}: channel.socket parent dir {$dir}"))
                ?? Finding::warn("agent {$name}: channel.socket parent dir {$dir} does not exist — live-wake will silently no-op. On systemd Linux this is /run/user/<uid>; a uid change (host restore) breaks it. Repoint channel.socket, or write it uid-agnostically as \${XDG_RUNTIME_DIR}/…");
        } elseif (! is_writable($dir)) {
            $uid = function_exists('posix_getuid') ? (string) posix_getuid() : '?';
            yield Finding::warn("agent {$name}: channel.socket parent dir {$dir} is not writable by this user (uid {$uid}) — live-wake will fail. Likely a uid mismatch after a host restore.");
        }

        yield from $this->markerLeg($name, $socket.'.FAILED');

        // filetype() over a bare file_exists(): a path that is a regular file or a
        // symlink is a misconfig, not a channel, and connecting to it would report a
        // liveness verdict about the wrong thing. filetype() alone excludes BOTH because
        // it is lstat-based — it returns 'link' for a symlink, never the target's type,
        // so no separate is_link() exclusion can change this conjunction's value.
        clearstatcache(true, $socket);
        if (is_dir($dir) && file_exists($socket)
            && filetype($socket) === 'socket'
        ) {
            if ($this->probe->probe('unix://'.$socket)['connected']) {
                yield Finding::ok("agent {$name}: channel socket live — a session is listening on {$socket}");
            } else {
                yield Finding::warn("agent {$name}: channel socket {$socket} exists but nothing is listening (stale socket / no live session) — live-wake no-ops until a session starts. If a session IS running, its connector may have come up deaf (look for a .FAILED marker).");
            }
        }

        yield Silence::because('the socket parent dir exists and is writable, no bind-FAILURE marker is present, and no socket file exists to probe — the ordinary between-sessions state, where there is nothing wrong to report and nothing live to certify');
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
            yield Finding::unvalidated("agent {$name}: channel.url {$url} has no explicit port — cannot liveness-probe the HTTP channel.");

            return;
        }

        $xdg = getenv('XDG_RUNTIME_DIR');
        if (! is_string($xdg) || $xdg === '') {
            yield Finding::unvalidated("agent {$name}: channel bind-FAILURE marker leg NOT RUN for the HTTP channel — XDG_RUNTIME_DIR is unset in this process, so there is no per-user runtime dir that could hold a marker attributable to the connector. This leg says NOTHING about whether the connector bound port {$port}; the liveness line below is unaffected. Remedy: set XDG_RUNTIME_DIR for the context the connector runs in (a systemd user session provides /run/user/<uid>; a cron or bare-systemd context does not) and point the connector at it.");
        } else {
            yield from $this->markerLeg($name, $xdg.'/agent-webhook-bridge-channel-'.$name.'.http-'.$port.'.FAILED');
        }

        $result = $this->probe->probe("tcp://{$host}:{$port}");
        if ($result['connected']) {
            yield Finding::ok("agent {$name}: channel HTTP endpoint live — something is listening on {$host}:{$port} (the connector, or the reverse-tunnel local end).");
        } else {
            yield Finding::warn("agent {$name}: channel HTTP endpoint {$host}:{$port} not answering".($result['error'] !== '' ? " ({$result['error']})" : '').' — no live session, or the reverse tunnel is down. live-wake no-ops until it is up.');
        }
    }

    /**
     * ONE bind-FAILURE marker leg, for BOTH transports (card#9121). The two were
     * character-identical apart from the path they compose, so the read, the refusal
     * routing and the sentence live here once rather than in two copies free to drift
     * (canon #5); each transport still composes its OWN path, which is the only part that
     * differs between them.
     *
     * ⭐ THE READER IS {@see UntrustedPathContents}, NOT `FileContents` OR A BARE
     * `file_get_contents()`, AND THE MARKER IS THE WEAKEST PATH IN THIS FILE. `bridge:check`
     * runs as the operator — routinely root — while the marker is WRITTEN by the agent
     * account's connector, so the path lives in a directory this process does not control:
     * the socket marker sits beside the socket in the agent's runtime dir, and the HTTP
     * marker sits under `XDG_RUNTIME_DIR`. ⛔ THAT PATH USED TO FALL BACK TO `/tmp` WHEN
     * `XDG_RUNTIME_DIR` WAS UNSET, and it no longer does — see `self::httpLegs()`, which
     * refuses the leg outright there (card#9121, DL-366): a marker in a world-writable
     * directory under a predictable name has no integrity to recover by reading it more
     * carefully. The shape this replaces was `is_file()` plus an unbounded
     * `@file_get_contents()`: `is_file()` follows the link and answers about the TARGET, so
     * the marker could be a symlink to ANY regular file root can read, and the read had no
     * size bound. What the reader does NOT close is its own docblock's to state.
     *
     * ⛔ THE TWO REFUSALS DO NOT LAND ON ONE ARM, for the reason card#9037 states at the
     * `authorized_keys` leg. A path that RESOLVES TO NO FILE is ESTABLISHING, it is a strict
     * subset of `is_file() === false`, and `is_file()` false is exactly what used to yield
     * NOTHING here — so it stays nothing: THE ESTABLISHING ARM mints no finding an operator
     * did not already get. A refusal that established nothing still yields the warn, because
     * the finding is a sentence about the marker EXISTING (see self::MARKER_TAIL) and a
     * refusal is only ever raised after an `lstat` found something at the path — what it
     * loses is the DETAIL, which is the one part this run cannot attribute to the connector.
     *
     * ⚠ THE WITHHOLDING ARM IS NOT VERDICT-NEUTRAL, AND THE NARROWER CLAIM ABOVE IS THE ONLY
     * ONE THIS MIGRATION SUPPORTS. `lstat` answers about the final component; `is_file()`
     * FOLLOWED the link. So a marker that is a symlink whose chain this process cannot
     * resolve — an ancestor of the target denying traversal, or a chain past
     * `UntrustedPathContents::MAX_SYMLINK_HOPS` with neither an absence nor a loop confirmed —
     * `lstat`s fine, reaches `CHAIN_UNRESOLVABLE`, and raises a plain
     * {@see UnreadableFileException}, which yields the warn below. `is_file()` was FALSE for
     * that shape and yielded nothing. MEASURED, not reasoned: a symlink to a file under a
     * `0000` directory gives `is_file() === false` and the unresolvable-chain refusal.
     * ⛔ That new warn carries self::MARKER_TAIL, which asserts a session came up DEAF — a
     * bind failure this run has NOT established, because it never read a marker. It is
     * DISCLOSED here rather than routed away: sending `CHAIN_UNRESOLVABLE` to the silent arm
     * would soften a withholding refusal into a measurement, and re-wording or suppressing an
     * operator-facing finding changes how errors are reported, which is not this change's to
     * make. card#9121 carries it.
     *
     * ⚠ IT BOUNDS THE READ; IT DOES NOT SANITIZE THE BYTES — THE RENDERER DOES (card#9121,
     * DL-366). On the success arm the marker's content is still interpolated verbatim into
     * the message, and that is what keeps `--format=json` byte-identical for the consumers
     * already parsing it. What changed is that the detail is now DECLARED untrusted on the
     * finding, so `CheckCommand::emitFinding()` escapes and caps it on the way to a
     * terminal. ⛔ THE JSON DOCUMENT STILL CARRIES THE RAW BYTES, deliberately: a consumer
     * rendering those strings owns this same rule at its own boundary.
     *
     * @return iterable<Finding>
     */
    private function markerLeg(string $name, string $marker): iterable
    {
        clearstatcache(true, $marker);

        try {
            $detail = UntrustedPathContents::read($marker, "agent {$name}: channel bind-FAILURE marker");
        } catch (PathResolvesToNoFileException) {
            // MEASURED: nothing following this path reads any bytes from it. `is_file()` was
            // false for every one of these shapes too, so this arm reports what it always
            // reported — no marker.
            return;
        } catch (UnreadableFileException $e) {
            // The refusal sentence is the READER's, not a second phrasing minted here: it
            // names the path, what was refused, and that nothing this process meant to read
            // was read.
            yield Finding::warn($e->getMessage().self::MARKER_TAIL);

            return;
        }

        if ($detail === null) {
            return;
        }

        // DECLARED IN PLACE, NOT ESCAPED HERE (card#9121, DL-366). The detail is bytes a
        // foreign principal wrote; the RULE that makes them safe on a terminal has one owner
        // in {@see UntrustedText} and is applied by the renderer, so this site says only
        // WHERE the seam is — the one fact no renderer can recover from a flat message
        // string, and the fact two value-matching cuts of this change threw away. Escaping
        // here would change `--format=json`'s bytes, which consumers already read.
        $detail = trim($detail);

        yield Finding::warn([
            "agent {$name}: channel bind-FAILURE marker at {$marker}",
            ...($detail !== '' ? [' (', Untrusted::span($detail), ')'] : []),
            self::MARKER_TAIL,
        ]);
    }
}
