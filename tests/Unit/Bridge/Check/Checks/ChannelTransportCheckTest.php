<?php

namespace Tests\Unit\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Checks\ChannelTransportCheck;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\ChannelProbeEnvironment;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The channel transport legs (DL-039, FR #2444), migrated in DL-242 stage 5b.
 *
 * FOUR LEGS OF THIS CHECK ARE INVISIBLE TO THE GOLDEN CORPUS — the unwritable parent dir,
 * the HTTP bind-failure marker, and BOTH liveness probes. A green golden run is evidence
 * for none of them. THE COMMAND-LEVEL SUITE REACHES THE TWO PROBES: mutating either reds
 * `BridgeCommandsTest::test_check_reports_channel_socket_live_when_a_session_listens` or
 * `::test_check_reports_channel_http_endpoint_live_when_listener_present`. The other two
 * legs were not in that mutation run, so this file claims no whole-suite scope for them —
 * it asserts all four directly.
 *
 * THE PROBE IS FAKED; EVERY OTHER HOST FACT IS REAL. The filesystem legs run against a
 * real temp dir and a real unix socket, and `XDG_RUNTIME_DIR` is a real env read — those
 * are constructible, which is why the seam is only the connect. The production side of
 * that seam is exercised separately (`SystemChannelProbeEnvironmentTest`); a fake alone
 * would be a check of the fake. (Named, never `{@see}`-linked: pint would turn the FQCN
 * into a real `use`.)
 *
 * THE DSN IS ASSERTED, NOT JUST THE VERDICT. It is the one thing this check computes for
 * the seam rather than reads from config, so a transport prefix lost in the migration
 * would otherwise pass every verdict assertion here while probing nothing reachable.
 */
class ChannelTransportCheckTest extends TestCase
{
    private string $dir;

    /** @var resource|null */
    private $server = null;

    private string|false $origXdg;

    protected function setUp(): void
    {
        parent::setUp();

        // Short by construction: a unix socket path is capped near 108 bytes by the
        // kernel, well below PHP's usual path limits, and the overflow is a bind error
        // rather than a truncation warning.
        $this->dir = sys_get_temp_dir().'/chan-tx-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/run');
        $this->origXdg = getenv('XDG_RUNTIME_DIR');
        putenv('XDG_RUNTIME_DIR='.$this->dir.'/run');
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            fclose($this->server);
        }
        $this->origXdg === false ? putenv('XDG_RUNTIME_DIR') : putenv('XDG_RUNTIME_DIR='.$this->origXdg);
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    // ---- socket transport ----

    public function test_a_missing_socket_parent_dir_warns_with_the_uid_agnostic_repoint(): void
    {
        $probe = $this->probe(connected: false);

        $findings = $this->socketFindings($this->dir.'/no-such-dir/agent.sock', $probe);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Warn, $findings[0]->severity);
        $this->assertStringContainsString(
            "channel.socket parent dir {$this->dir}/no-such-dir does not exist — live-wake will silently no-op",
            $findings[0]->message,
        );
        $this->assertStringContainsString('${XDG_RUNTIME_DIR}', $findings[0]->message);
        // The dir does not exist, so the liveness gate cannot be reached either — a
        // regression that probed anyway would report a verdict about a path with no
        // channel at all.
        $this->assertSame([], $probe->dsns);
    }

    /**
     * Invisible to the golden corpus, and the one leg whose measurability depends on WHO
     * runs the suite:
     * root bypasses the write bit, so PHP's `is_writable()` answers true for a 0500
     * directory and the branch is unreachable. Skipped with that reason rather than
     * asserted vacuously — an assertion that cannot fail is not coverage.
     */
    public function test_an_unwritable_socket_parent_dir_warns_and_names_the_uid(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $this->markTestSkipped('root bypasses the write bit: is_writable() returns true for any directory, so this predicate cannot be measured as root.');
        }

        $dir = $this->dir.'/locked';
        File::ensureDirectoryExists($dir);
        chmod($dir, 0o500);

        try {
            $findings = $this->socketFindings($dir.'/agent.sock', $this->probe(connected: false));
        } finally {
            chmod($dir, 0o700);   // else File::deleteDirectory cannot unlink through it
        }

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Warn, $findings[0]->severity);
        $this->assertStringContainsString(
            "channel.socket parent dir {$dir} is not writable by this user (uid ".posix_getuid().')',
            $findings[0]->message,
        );
    }

    public function test_a_bind_failure_marker_is_surfaced_with_the_connectors_own_detail(): void
    {
        $socket = $this->dir.'/agent.sock';
        File::put($socket.'.FAILED', "  EADDRINUSE\n");

        $findings = $this->socketFindings($socket, $this->probe(connected: false));

        $this->assertCount(1, $findings);
        $this->assertStringContainsString("channel bind-FAILURE marker at {$socket}.FAILED (EADDRINUSE)", $findings[0]->message);
        $this->assertStringContainsString('came up DEAF', $findings[0]->message);
    }

    /** The detail is optional, and an empty marker must not print an empty parenthetical. */
    public function test_an_empty_bind_failure_marker_still_warns_without_a_detail_clause(): void
    {
        $socket = $this->dir.'/agent.sock';
        File::put($socket.'.FAILED', '');

        $findings = $this->socketFindings($socket, $this->probe(connected: false));

        $this->assertCount(1, $findings);
        $this->assertStringContainsString("marker at {$socket}.FAILED — a Claude Code session", $findings[0]->message);
    }

    /** No fixture creates a real socket file, so neither arm is golden-measured. */
    public function test_a_live_socket_reports_ok_and_probes_the_unix_dsn(): void
    {
        $socket = $this->listeningSocket();
        $probe = $this->probe(connected: true);

        $findings = $this->socketFindings($socket, $probe);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Ok, $findings[0]->severity);
        $this->assertSame("agent prod-agent: channel socket live — a session is listening on {$socket}", $findings[0]->message);
        $this->assertSame(['unix://'.$socket], $probe->dsns);
    }

    /** The other half of the same gap — the stale-socket verdict live-wake actually no-ops on. */
    public function test_a_socket_nothing_answers_on_warns_that_it_is_stale(): void
    {
        $socket = $this->listeningSocket();

        $findings = $this->socketFindings($socket, $this->probe(connected: false));

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Warn, $findings[0]->severity);
        $this->assertStringContainsString("channel socket {$socket} exists but nothing is listening", $findings[0]->message);
    }

    /**
     * The `filetype()` gate. A regular file at the socket path is a misconfig, and
     * connecting to it would report a liveness verdict about the wrong kind of thing — so
     * the contract is that the probe is not reached at all, which only a call witness can
     * assert (both arms of the probe would otherwise print something plausible).
     */
    public function test_a_regular_file_at_the_socket_path_is_never_probed(): void
    {
        $socket = $this->dir.'/agent.sock';
        File::put($socket, 'not a socket');
        $probe = $this->probe(connected: true);

        $this->assertSame([], $this->socketFindings($socket, $probe));
        $this->assertSame([], $probe->dsns);
    }

    /**
     * The same contract for the symlink case — and the exclusion is OVER-DETERMINED, which
     * is why this asserts the outcome rather than a clause. PHP's `filetype()` is
     * lstat-based, so a symlink reads as `link` and the type test alone already rejects it;
     * the migrated predicate's separate `! is_link()` clause cannot change the conjunction's
     * value for any input. (It is load-bearing in `SocketEndpoint::assertValid`, where the
     * two clauses throw DIFFERENT messages — here they collapse to one silence. Recorded in
     * the plan doc's Stage 5b result; not touched by a byte-identical migration stage.)
     */
    public function test_a_symlink_to_a_live_socket_is_never_probed(): void
    {
        $socket = $this->listeningSocket();
        $link = $this->dir.'/link.sock';
        symlink($socket, $link);
        $probe = $this->probe(connected: true);

        $this->assertSame([], $this->socketFindings($link, $probe));
        $this->assertSame([], $probe->dsns);
    }

    // ---- http transport ----

    public function test_a_url_with_no_port_is_unvalidated_and_is_never_probed(): void
    {
        $probe = $this->probe(connected: true);

        $findings = $this->httpFindings('http://127.0.0.1/push', $probe);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Unvalidated, $findings[0]->severity);
        $this->assertSame(
            'agent prod-agent: channel.url http://127.0.0.1/push has no explicit port — cannot liveness-probe the HTTP channel.',
            $findings[0]->message,
        );
        $this->assertSame([], $probe->dsns);
    }

    /** The one `channel.url` fixture has no port, so the golden corpus never reaches this leg. */
    public function test_a_live_http_endpoint_reports_ok_and_probes_the_tcp_dsn(): void
    {
        $probe = $this->probe(connected: true);

        $findings = $this->httpFindings('http://127.0.0.1:8765/push', $probe);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Ok, $findings[0]->severity);
        $this->assertStringContainsString('channel HTTP endpoint live — something is listening on 127.0.0.1:8765', $findings[0]->message);
        $this->assertSame(['tcp://127.0.0.1:8765'], $probe->dsns);
    }

    /**
     * The transport's own error text is the reason the connect is behind a seam at all —
     * it is platform-dependent and it reaches the operator, so it is pinned rather than
     * inherited from the host.
     */
    public function test_a_dead_http_endpoint_warns_and_carries_the_transport_error(): void
    {
        $findings = $this->httpFindings('http://127.0.0.1:8765/push', $this->probe(connected: false, error: 'Connection refused'));

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Warn, $findings[0]->severity);
        $this->assertStringContainsString(
            'channel HTTP endpoint 127.0.0.1:8765 not answering (Connection refused) — no live session',
            $findings[0]->message,
        );
    }

    /** A transport that reports a failure with no message must not print an empty parenthetical. */
    public function test_a_dead_http_endpoint_with_no_transport_error_omits_the_detail_clause(): void
    {
        $findings = $this->httpFindings('http://127.0.0.1:8765/push', $this->probe(connected: false));

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('127.0.0.1:8765 not answering — no live session', $findings[0]->message);
    }

    /**
     * No fixture writes a marker. The path is composed from `XDG_RUNTIME_DIR`, the AGENT name
     * and the PORT — all three asserted, because the check keys on the agent name only as
     * a proxy for the channel server's own name, and a miss is silent by design.
     */
    public function test_an_http_bind_failure_marker_is_surfaced_for_the_agent_and_port(): void
    {
        $marker = $this->dir.'/run/agent-webhook-bridge-channel-prod-agent.http-8765.FAILED';
        File::put($marker, 'EADDRINUSE');

        $findings = $this->httpFindings('http://127.0.0.1:8765/push', $this->probe(connected: false));

        $this->assertCount(2, $findings);
        $this->assertStringContainsString("channel bind-FAILURE marker at {$marker} (EADDRINUSE)", $findings[0]->message);
        $this->assertStringContainsString('a TCP-port bind race', $findings[0]->message);
        // The marker does not short-circuit the probe: a deaf connector and a dead
        // endpoint are different diagnoses and the operator gets both.
        $this->assertStringContainsString('not answering', $findings[1]->message);
    }

    public function test_a_marker_for_another_port_is_not_surfaced(): void
    {
        File::put($this->dir.'/run/agent-webhook-bridge-channel-prod-agent.http-9999.FAILED', 'EADDRINUSE');

        $findings = $this->httpFindings('http://127.0.0.1:8765/push', $this->probe(connected: true));

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Ok, $findings[0]->severity);
    }

    // ---- neither ----

    /**
     * The exclusivity this check owns instead of making two checks re-derive it. Asserted
     * with a call witness: an agent with no channel must reach neither transport's legs,
     * and the probe is the only one of those legs that leaves a trace when it is silent.
     */
    public function test_an_agent_with_no_channel_reaches_neither_transport(): void
    {
        $probe = $this->probe(connected: true);
        $config = AgentConfig::fromArray('prod-agent', [
            'identity' => ['kanban_user_id' => 1],
            'subscriptions' => [],
        ]);

        $findings = iterator_to_array((new ChannelTransportCheck($probe))->runFor($config, new CheckContext), false);

        $this->assertSame([], $findings);
        $this->assertSame([], $probe->dsns);
    }

    // ---- plumbing ----

    /** A real listening unix socket, closed in tearDown. */
    private function listeningSocket(): string
    {
        $path = $this->dir.'/agent.sock';
        $server = stream_socket_server('unix://'.$path, $errno, $errstr);
        $this->assertNotFalse($server, "could not bind a test socket at {$path}: {$errstr}");
        $this->server = $server;

        return $path;
    }

    /** @return list<Finding> */
    private function socketFindings(string $socket, ChannelProbeEnvironment $probe): array
    {
        return $this->findingsFor(['socket' => $socket], $probe);
    }

    /** @return list<Finding> */
    private function httpFindings(string $url, ChannelProbeEnvironment $probe): array
    {
        return $this->findingsFor(['url' => $url], $probe);
    }

    /**
     * @param  array<string, string>  $channel
     * @return list<Finding>
     */
    private function findingsFor(array $channel, ChannelProbeEnvironment $probe): array
    {
        $config = AgentConfig::fromArray('prod-agent', [
            'identity' => ['kanban_user_id' => 1],
            'subscriptions' => [],
            'channel' => $channel,
        ]);

        return iterator_to_array((new ChannelTransportCheck($probe))->runFor($config, new CheckContext), false);
    }

    /** A probe that records what it was asked, so a silent leg can be told from an unreached one. */
    private function probe(bool $connected, string $error = ''): ChannelProbeEnvironment
    {
        return new class($connected, $error) implements ChannelProbeEnvironment
        {
            /** @var list<string> */
            public array $dsns = [];

            public function __construct(private readonly bool $connected, private readonly string $error) {}

            /** @return array{connected: bool, error: string} */
            public function probe(string $dsn): array
            {
                $this->dsns[] = $dsn;

                return ['connected' => $this->connected, 'error' => $this->error];
            }
        };
    }
}
