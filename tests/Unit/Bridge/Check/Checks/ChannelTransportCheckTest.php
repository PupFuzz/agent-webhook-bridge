<?php

namespace Tests\Unit\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Checks\ChannelTransportCheck;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\ChannelProbeEnvironment;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use App\Bridge\Support\UntrustedPathContents;
use Illuminate\Support\Facades\File;
use Tests\Support\AssertsNoLiveControlByte;
use Tests\Support\MaterializesChecks;
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
    use AssertsNoLiveControlByte;
    use MaterializesChecks;

    private string $dir;

    /** @var resource|null */
    private $server = null;

    private string|false $origXdg;

    /**
     * A marker this test planted in the REAL `/tmp`, removed in tearDown.
     *
     * ⛔ IT HAS TO BE THE REAL `/tmp`, which is the whole subject: the leg under test used
     * to compose its path there when `XDG_RUNTIME_DIR` was unset, and pointing the test at
     * a temp dir would assert about a path the defect never used. The PORT is derived from
     * this process's pid so two parallel checkouts on one box cannot plant the same path.
     */
    private ?string $plantedTmpMarker = null;

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
        if ($this->plantedTmpMarker !== null && is_file($this->plantedTmpMarker)) {
            @unlink($this->plantedTmpMarker);
        }
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
     * The card#5698 arm. The parent dir EXISTS and holds a live channel; this process just
     * cannot traverse to it. Before the guard that printed "does not exist … Repoint
     * channel.socket", sending the operator to re-point a correct config — and the cause
     * it named (a uid change after a host restore) was not the cause at all.
     */
    public function test_a_socket_parent_dir_that_cannot_be_seen_is_unvalidated_not_reported_missing(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $this->markTestSkipped('root bypasses directory permission checks');
        }
        $parent = $this->dir.'/locked';
        File::ensureDirectoryExists($parent.'/inner');
        $probe = $this->probe(connected: false);
        chmod($parent, 0000);

        try {
            $findings = $this->socketFindings($parent.'/inner/agent.sock', $probe);
        } finally {
            chmod($parent, 0755);
        }

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Unvalidated, $findings[0]->severity);
        $this->assertStringContainsString('is not visible to this user', $findings[0]->message);
        $this->assertStringNotContainsString('does not exist', $findings[0]->message);
        $this->assertStringNotContainsString('Repoint channel.socket', $findings[0]->message);
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
        $this->assertSame($this->markerTail(), $this->tailOf($findings[0]->message));
    }

    /**
     * THE TAIL IS THE SAME SENTENCE ON BOTH LEGS, AND IT ASSERTS NOTHING ABOUT WHO HOLDS
     * THE ADDRESS (card#8984). The unix leg used to add "so another session holds the
     * channel … Close the duplicate session"; the http leg, "a TCP-port bind race". Both
     * were claims this process cannot establish — it reads a file another account's
     * process wrote — and on the case roundtable #420 measured, the holder was the seat's
     * OWN previous channel server, so both tails sent the reader after a session that did
     * not exist.
     *
     * ⭐ IT ALSO DOES NOT SUMMARISE THE MARKER. The connector that wrote it may be an older
     * snapshot whose body names only "another session" (DL-237: snapshots reconcile only
     * on a role-b run, and only upward), so the tail points at the doc that owns the cause
     * list rather than restating a list that may contradict the body beside it.
     *
     * Seen red: put either old tail back on either leg and this exact-match fails.
     */
    private function markerTail(): string
    {
        return ' — a Claude Code session came up DEAF: its connector could not bind the channel. '
            .'Causes and remedies: docs/board-tools-enablement.md § Activating on a running seat. '
            .'rm the marker once resolved.';
    }

    /** Everything from the tail's opening em dash on — the part this card owns. */
    private function tailOf(string $message): string
    {
        $at = strpos($message, ' — a Claude Code session came up DEAF');

        return $at === false ? "[no tail found in: {$message}]" : substr($message, $at);
    }

    /** The detail is optional, and an empty marker must not print an empty parenthetical. */
    public function test_an_empty_bind_failure_marker_still_warns_without_a_detail_clause(): void
    {
        $socket = $this->dir.'/agent.sock';
        File::put($socket.'.FAILED', '');

        $findings = $this->socketFindings($socket, $this->probe(connected: false));

        $this->assertCount(1, $findings);
        $this->assertStringContainsString("marker at {$socket}.FAILED — a Claude Code session", $findings[0]->message);
        // The tail is a sentence about the marker EXISTING, not about its contents, so it
        // has to read correctly with nothing interpolated in front of it.
        $this->assertSame($this->markerTail(), $this->tailOf($findings[0]->message));
    }

    /**
     * ⭐ THE MARKER IS WRITTEN BY ANOTHER ACCOUNT AND READ BY THIS ONE, WHICH IS THE WHOLE
     * REASON THE READ IS GUARDED (card#9121, adopting card#9037's reader). `bridge:check`
     * runs as the operator — routinely root under `sudo` — while the marker lives beside the
     * agent's socket in ITS runtime dir. Before this, `is_file()` followed the link and
     * answered about the TARGET, so a marker that is a SYMLINK to any regular file root can
     * read was read as the connector's own report and its bytes were printed verbatim into
     * an operator-facing finding.
     *
     * The finding still fires — the tail is a sentence about the marker EXISTING and
     * something IS at that path — and what it loses is the DETAIL, which is the one part
     * this run cannot attribute to the connector.
     */
    public function test_a_symlinked_bind_failure_marker_is_reported_without_the_bytes_it_names(): void
    {
        $socket = $this->dir.'/agent.sock';
        $elsewhere = $this->dir.'/not-the-connectors-file';
        File::put($elsewhere, "PLANTED-BYTES-9121\n");
        symlink($elsewhere, $socket.'.FAILED');

        $findings = $this->socketFindings($socket, $this->probe(connected: false));

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Warn, $findings[0]->severity);
        $this->assertStringNotContainsString('PLANTED-BYTES-9121', $findings[0]->message);
        $this->assertStringContainsString("channel bind-FAILURE marker at {$socket}.FAILED was NOT read", $findings[0]->message);
        // The marker still EXISTS, so the operator still gets the sentence about it.
        $this->assertSame($this->markerTail(), $this->tailOf($findings[0]->message));
    }

    /**
     * A path that RESOLVES TO NO FILE yields NOTHING, exactly as it did before the reader
     * was adopted: `is_file()` was false for a directory too, so this pins that THE
     * ESTABLISHING ARM mints no finding an operator did not already get. Route the
     * establishing refusal to the warn arm instead and this reds.
     *
     * ⛔ It pins that arm and no more. The WITHHOLDING arm is NOT verdict-neutral — a
     * symlink whose chain this process cannot resolve `lstat`s fine and warns, where
     * `is_file()` followed the link, failed, and yielded nothing. Nothing here covers that
     * shape: the cheap way to build it is a `0000` ancestor, and root — the principal
     * `bridge:check` actually runs as — traverses one anyway, so the fixture's verdict would
     * depend on the uid running the suite rather than on the code. `ChannelTransportCheck`'s
     * own docblock discloses the arm instead.
     */
    public function test_a_directory_at_the_marker_path_surfaces_no_marker_finding(): void
    {
        $socket = $this->dir.'/agent.sock';
        mkdir($socket.'.FAILED');

        $findings = $this->socketFindings($socket, $this->probe(connected: false));

        $this->assertSame([], $findings);
    }

    /**
     * The HTTP marker is the WEAKEST path of the two: with `XDG_RUNTIME_DIR` unset the
     * check composes it under `/tmp`, which every local account can write, and the name is
     * derived from the agent name and the port. The read is bounded by the opened file's
     * own `fstat`, so a marker sized past the reader's cap is not read at all.
     */
    public function test_an_oversize_http_bind_failure_marker_is_not_read_into_the_finding(): void
    {
        $marker = $this->dir.'/run/agent-webhook-bridge-channel-prod-agent.http-8765.FAILED';
        File::put($marker, 'OVERSIZE-BYTES-9121'.str_repeat('E', UntrustedPathContents::MAX_BYTES));

        $findings = $this->httpFindings('http://127.0.0.1:8765/push', $this->probe(connected: false));

        $this->assertCount(2, $findings);
        $this->assertSame(Severity::Warn, $findings[0]->severity);
        $this->assertStringNotContainsString('OVERSIZE-BYTES-9121', $findings[0]->message);
        $this->assertStringContainsString("channel bind-FAILURE marker at {$marker} was NOT read", $findings[0]->message);
        $this->assertSame($this->markerTail(), $this->tailOf($findings[0]->message));
        // Unchanged: the marker never short-circuits the liveness probe.
        $this->assertStringContainsString('not answering', $findings[1]->message);
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
     * The same contract for the symlink case, and it is the SAME clause that enforces it:
     * PHP's `filetype()` is lstat-based, so a symlink reads as `link` and the type test
     * alone already rejects it. This test asserts the outcome rather than a clause because
     * the predicate used to carry a redundant `! is_link()` alongside the type test — each
     * masked the other's mutation, so neither could be mutation-tested individually. That
     * clause was dropped (card#5538); the assertion is unchanged because the behavior was
     * never dependent on it. (`! is_link()` IS load-bearing in `SocketEndpoint::assertValid`,
     * where the two checks are separate statements throwing DIFFERENT messages — here they
     * collapsed to one silence, which is what made one of them removable.)
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
        // The SAME tail as the unix leg, character for character — see markerTail().
        $this->assertSame($this->markerTail(), $this->tailOf($findings[0]->message));
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

    /**
     * ⭐ THE WEAKEST PATH IN THIS FILE IS NOT WALKED AT ALL ANY MORE (card#9121, DL-366).
     *
     * With `XDG_RUNTIME_DIR` unset the leg used to compose `/tmp/agent-webhook-bridge-
     * channel-<agent>.http-<port>.FAILED` — a PREDICTABLE name in a WORLD-WRITABLE directory
     * — and read it as the operator, routinely root. Guarding that read was never enough:
     * a PLAIN REGULAR FILE planted there passes every check a reader can make and its bytes
     * still land in root's report. There is no integrity to recover, so the leg refuses.
     *
     * THE PLANT IS REAL, not a stand-in. If the leg reads anything at all, this reds.
     */
    public function test_the_http_marker_leg_refuses_to_look_when_xdg_runtime_dir_is_unset(): void
    {
        putenv('XDG_RUNTIME_DIR');
        $port = $this->tmpProbePort();
        $this->plantedTmpMarker = "/tmp/agent-webhook-bridge-channel-prod-agent.http-{$port}.FAILED";
        File::put($this->plantedTmpMarker, 'PLANTED-BY-ANOTHER-ACCOUNT-9121');

        $findings = $this->httpFindings("http://127.0.0.1:{$port}/push", $this->probe(connected: false));

        // WITHHELD FIRST, because that is the vulnerability: with the `/tmp` fallback in
        // place this line is what reds, and it reds carrying the planted bytes.
        foreach ($findings as $finding) {
            $this->assertStringNotContainsString('PLANTED-BY-ANOTHER-ACCOUNT-9121', $finding->message);
        }
        $this->assertCount(2, $findings);
        $this->assertSame(Severity::Unvalidated, $findings[0]->severity);
        // ...and neither does the tail, which asserts a session came up DEAF — a bind
        // failure this run has not established, because it read no marker.
        $this->assertStringNotContainsString('came up DEAF', $findings[0]->message);
        // PRESENCE WITNESSES, so the two absences above are not the absence of a finding:
        // the leg SAYS it did not run, names the cause, and names the remedy.
        $this->assertStringContainsString('marker leg NOT RUN', $findings[0]->message);
        $this->assertStringContainsString('XDG_RUNTIME_DIR is unset', $findings[0]->message);
        $this->assertStringContainsString('set XDG_RUNTIME_DIR for the context the connector runs in', $findings[0]->message);
        // The refusal bounds ONE leg, never the check: a deaf connector and a dead endpoint
        // are different diagnoses and the operator still gets the second one.
        $this->assertStringContainsString('not answering', $findings[1]->message);
        // The file is still there — nothing about this test's own plumbing removed it, so
        // the withholding above is the leg's doing.
        $this->assertFileExists($this->plantedTmpMarker);
    }

    /** An EMPTY `XDG_RUNTIME_DIR` composed `/tmp` too, so it refuses on the same arm. */
    public function test_the_http_marker_leg_refuses_when_xdg_runtime_dir_is_empty(): void
    {
        putenv('XDG_RUNTIME_DIR=');
        $port = $this->tmpProbePort();
        $this->plantedTmpMarker = "/tmp/agent-webhook-bridge-channel-prod-agent.http-{$port}.FAILED";
        File::put($this->plantedTmpMarker, 'PLANTED-BY-ANOTHER-ACCOUNT-9121');

        $findings = $this->httpFindings("http://127.0.0.1:{$port}/push", $this->probe(connected: false));

        $this->assertSame(Severity::Unvalidated, $findings[0]->severity);
        $this->assertStringNotContainsString('PLANTED-BY-ANOTHER-ACCOUNT-9121', $findings[0]->message);
        $this->assertStringContainsString('marker leg NOT RUN', $findings[0]->message);
    }

    /**
     * ⛔ THE SOCKET LEG IS UNAFFECTED, and that asymmetry is the decision rather than an
     * oversight: the socket marker's directory belongs to the AGENT ACCOUNT — a trust
     * relationship the install already has, since that account's connector is the thing the
     * marker reports on — while `/tmp` belongs to everyone. `XDG_RUNTIME_DIR` is unset here
     * to prove the refusal keys on the HTTP path's composition and not on the env var.
     */
    public function test_the_socket_marker_leg_still_reads_with_no_xdg_runtime_dir(): void
    {
        putenv('XDG_RUNTIME_DIR');
        $socket = $this->dir.'/agent.sock';
        File::put($socket.'.FAILED', 'EADDRINUSE');

        $findings = $this->socketFindings($socket, $this->probe(connected: false));

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Warn, $findings[0]->severity);
        $this->assertStringContainsString("channel bind-FAILURE marker at {$socket}.FAILED (EADDRINUSE)", $findings[0]->message);
    }

    /**
     * THE DETAIL IS DECLARED UNTRUSTED, AND THE MESSAGE IS STILL VERBATIM (card#9121,
     * DL-366, card#9200) — the marker's detail is bytes the connector's ACCOUNT wrote into a
     * file this process reads as the operator, and the escape is applied at the interpolation
     * in this check rather than deferred to a renderer.
     */
    public function test_the_marker_detail_is_escaped_on_both_transports(): void
    {
        $payload = "\x1b[2JEADDRINUSE";
        $marker = $this->dir.'/run/agent-webhook-bridge-channel-prod-agent.http-8765.FAILED';
        File::put($marker, $payload);
        $socket = $this->dir.'/agent.sock';
        File::put($socket.'.FAILED', $payload);

        $http = $this->httpFindings('http://127.0.0.1:8765/push', $this->probe(connected: false));
        $unix = $this->socketFindings($socket, $this->probe(connected: false));

        foreach (['http' => $http[0], 'unix' => $unix[0]] as $transport => $finding) {
            $this->assertForeignValueEscapedInto($finding->message, $payload, $transport);
            // ⛔ AND THE RAW BYTES ARE GONE FROM THE MESSAGE ITSELF, which is the half that
            // changed: `--format=json` reads this field, and it no longer hands a machine
            // consumer an erase-line. `message` is not a write contract —
            // `docs/check-json-contract.md` §2 — so this is a rewording that surface licenses.
            $this->assertStringNotContainsString($payload, $finding->message, "{$transport}: the raw bytes must not survive into the message");
        }
    }

    /** No detail, nothing foreign in the sentence — so no echo clause at all. */
    public function test_an_empty_marker_echoes_no_detail(): void
    {
        $marker = $this->dir.'/run/agent-webhook-bridge-channel-prod-agent.http-8765.FAILED';
        File::put($marker, '   ');

        $findings = $this->httpFindings('http://127.0.0.1:8765/push', $this->probe(connected: false));

        // The parenthesised detail is the ONLY `(` this line can carry, so its absence is the
        // assertion — and a whitespace-only detail must not render as an empty `()` either.
        $this->assertStringNotContainsString('(', $findings[0]->message);
        $this->assertStringContainsString('channel bind-FAILURE marker at ', $findings[0]->message);
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

        $findings = $this->findingsOfFor((new ChannelTransportCheck($probe)), $config, new CheckContext);

        $this->assertSame([], $findings);
        $this->assertSame([], $probe->dsns);
    }

    // ---- plumbing ----

    /**
     * A port unique to this OS process, so the REAL `/tmp` plant above cannot collide with
     * a parallel checkout running the same test on the same box.
     */
    private function tmpProbePort(): int
    {
        return 40000 + (getmypid() % 20000);
    }

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

        return $this->findingsOfFor((new ChannelTransportCheck($probe)), $config, new CheckContext);
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
