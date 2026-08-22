<?php

namespace Tests\Unit\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Checks\BoardToolsHttpProbeCheck;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\Support\MaterializesChecks;
use Tests\TestCase;

/**
 * The opt-in `--probe-tools` HTTP live probe (DL-217), migrated in DL-242 stage 7b.
 *
 * THE GOLDEN CORPUS REACHES EXACTLY ONE LINE OF THIS CHECK — the "nothing to probe" warn,
 * from an install with no enabled block (card#5552 measured that the corpus's one
 * HTTP-enabled board-tools install dies at agent-config load, so nothing downstream of the
 * enabled subset is rendered anywhere). Every certify arm below therefore has this file as
 * its ONLY proof, which is also why each asserts the SEVERITY: this probe is the one
 * board-tools leg that FAILS rather than warns, and that split is the whole reason it is
 * opt-in.
 *
 * THE TRANSPORT SKIP IS ASSERTED AS A CONTINUE, NOT A RETURN. An ssh agent cannot be
 * certified over the HTTP door, and the inline code this replaced named that instead of
 * skipping silently (F6, card 4952) — but it must also keep probing the agents AFTER it.
 * A test with one ssh agent passes either way; the ordered two-agent case below is what
 * distinguishes them.
 */
class BoardToolsHttpProbeCheckTest extends TestCase
{
    use MaterializesChecks;

    private const ENDPOINT = 'https://bridge.test/agent-tools/call';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/bt-http-probe-'.uniqid();
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /** Not requested ⇒ silent, and it must not reach the network to decide that. */
    public function test_without_the_flag_it_is_silent(): void
    {
        Http::fake();

        $ctx = new CheckContext;
        $ctx->boardToolsEnabled = [$this->httpAgent('prod-agent')];

        $this->assertSame([], $this->findingsOf((new BoardToolsHttpProbeCheck(null)), $ctx));
        Http::assertNothingSent();
    }

    public function test_the_flag_with_no_enabled_agent_warns_and_certifies_nothing(): void
    {
        Http::fake();

        $findings = $this->findingsFor([]);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Warn, $findings[0]->severity);
        $this->assertSame('board_tools probe: --probe-tools was given but no agent has an enabled board_tools block — nothing to probe.', $findings[0]->message);
        Http::assertNothingSent();
    }

    public function test_a_successful_round_trip_reports_the_scoped_window(): void
    {
        $this->fakeResult(['board_id' => 10, 'board_observed' => true, 'configured_board_id' => 10, 'swimlane_id' => 4, 'cards_by_stage' => ['backlog' => [], 'doing' => []]]);

        $findings = $this->findingsFor([$this->httpAgent('prod-agent')]);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Ok, $findings[0]->severity);
        $this->assertStringContainsString('board_tools probe: agent prod-agent: '.self::ENDPOINT.' → 200; window scoped to board 10 / swimlane 4 (2 stage group(s)).', $findings[0]->message);
    }

    public function test_a_missing_cards_by_stage_counts_zero_groups(): void
    {
        $this->fakeResult(['configured_board_id' => 10, 'swimlane_id' => 4]);

        $this->assertStringContainsString('(0 stage group(s))', $this->findingsFor([$this->httpAgent('prod-agent')])[0]->message);
    }

    public function test_an_isolation_mismatch_fails(): void
    {
        $this->fakeResult(['configured_board_id' => 99, 'swimlane_id' => 4]);

        $findings = $this->findingsFor([$this->httpAgent('prod-agent')]);

        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertStringContainsString('ISOLATION MISMATCH — board_my_cards answered for board=99 swimlane=4, but this agent is configured for board 10 / swimlane 4.', $findings[0]->message);
    }

    /**
     * ⛔ THE HEADER IS THE IDENTITY ECHO, THE ROWS ARE NOT (DL-302). Since the tool
     * reports the board its ROWS are on, a window holding a foreign row answers
     * `board_id: 20` while still being THIS agent's window — and this probe certifies
     * bearer→agent resolution, so it must stay OK. Failing here would turn a board-state
     * observation into a check that rejects, which is a gated behaviour change and not
     * this leg's question.
     */
    public function test_an_observed_row_board_that_differs_from_config_is_not_an_isolation_failure(): void
    {
        $this->fakeResult(['board_id' => 20, 'board_observed' => true, 'configured_board_id' => 10, 'swimlane_id' => 4]);

        $findings = $this->findingsFor([$this->httpAgent('prod-agent')]);

        $this->assertSame(Severity::Ok, $findings[0]->severity);
        $this->assertStringContainsString('window scoped to board 10 / swimlane 4', $findings[0]->message);
    }

    /**
     * An EMPTY window observes no board at all, so `board_id` is null there. Reading the
     * header off that key would fail every agent with no cards — the regression this
     * check would have shipped if the probe had not moved to `configured_board_id`.
     */
    public function test_an_unobserved_board_still_certifies_from_the_header(): void
    {
        $this->fakeResult(['board_id' => null, 'board_observed' => false, 'configured_board_id' => 10, 'swimlane_id' => 4, 'cards_by_stage' => []]);

        $findings = $this->findingsFor([$this->httpAgent('prod-agent')]);

        $this->assertSame(Severity::Ok, $findings[0]->severity);
    }

    /**
     * The VERSION-SKEW tolerance, and the only place it is exercised: `--probe-tools-ssh`
     * can round-trip to a bridge install that is not this one, and one predating DL-302
     * answers the header under the old `board_id` spelling with no `configured_board_id`
     * at all. Read strictly, that install would report an ISOLATION failure for a version
     * difference — a specific WRONG cause. Delete this test with the fallback, not before.
     */
    public function test_a_responder_predating_the_header_rename_is_read_under_the_old_key(): void
    {
        $this->fakeResult(['board_id' => 10, 'swimlane_id' => 4, 'cards_by_stage' => []]);

        $findings = $this->findingsFor([$this->httpAgent('prod-agent')]);

        $this->assertSame(Severity::Ok, $findings[0]->severity);
        $this->assertStringContainsString('window scoped to board 10 / swimlane 4', $findings[0]->message);
    }

    /**
     * The scope header is TWO values, and either alone is an isolation violation — a test
     * that only mismatched the board would pass with the swimlane clause deleted, which is
     * the half of the filter that carries per-agent lane isolation.
     */
    public function test_a_swimlane_only_mismatch_fails(): void
    {
        $this->fakeResult(['configured_board_id' => 10, 'swimlane_id' => 99]);

        $findings = $this->findingsFor([$this->httpAgent('prod-agent')]);

        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertStringContainsString('board=10 swimlane=99', $findings[0]->message);
    }

    /** A missing scope header is not a match — it renders as `null`, and it still fails. */
    public function test_an_absent_scope_header_fails_as_a_mismatch(): void
    {
        $this->fakeResult(['cards_by_stage' => []]);

        $findings = $this->findingsFor([$this->httpAgent('prod-agent')]);

        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertStringContainsString('board=null swimlane=null', $findings[0]->message);
    }

    public function test_a_200_without_a_result_object_fails(): void
    {
        Http::fake(fn () => Http::response(['error' => 'no such tool']));

        $findings = $this->findingsFor([$this->httpAgent('prod-agent')]);

        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertStringContainsString('200 but the response carries no `result` object — cannot confirm board_my_cards ran (error: no such tool).', $findings[0]->message);
    }

    public function test_a_403_names_the_loopback_trap(): void
    {
        Http::fake(fn () => Http::response(['error' => 'nope'], 403));

        $findings = $this->findingsFor([$this->httpAgent('prod-agent')]);

        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertStringContainsString('403 (loopback gate refused)', $findings[0]->message);
        $this->assertStringContainsString('/etc/hosts recipe', $findings[0]->message);
    }

    public function test_a_401_names_the_bearer(): void
    {
        Http::fake(fn () => Http::response(['error' => 'nope'], 401));

        $findings = $this->findingsFor([$this->httpAgent('prod-agent')]);

        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertStringContainsString('401 (bearer rejected)', $findings[0]->message);
    }

    public function test_another_non_2xx_reports_the_body(): void
    {
        Http::fake(fn () => Http::response('gateway exploded', 502));

        $findings = $this->findingsFor([$this->httpAgent('prod-agent')]);

        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertStringContainsString('→ HTTP 502 — the tool call did not succeed (body: gateway exploded).', $findings[0]->message);
    }

    public function test_a_connection_failure_fails(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 7: connection refused'));

        $findings = $this->findingsFor([$this->httpAgent('prod-agent')]);

        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertStringContainsString('could NOT connect to '.self::ENDPOINT.' (cURL error 7: connection refused)', $findings[0]->message);
    }

    /**
     * Renamed from `test_an_unreadable_bearer_…` (card#5778): the fixture is a 0644 file,
     * which is the INSECURE-PERMS fault, and the two stopped being one thing when
     * present-but-unreadable got its own arm below. `chmod 600` is the right remedy HERE
     * and only here.
     */
    public function test_an_insecure_perms_bearer_fails_without_probing(): void
    {
        Http::fake();
        $path = $this->token('loose', 'shhh', 0o644);

        $findings = $this->findingsFor([$this->httpAgent('prod-agent', $path)]);

        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertStringContainsString('bearer not readable — ', $findings[0]->message);
        $this->assertStringContainsString('(chmod 600); cannot certify this agent.', $findings[0]->message);
        Http::assertNothingSent();
    }

    /**
     * The card#5778 arm: the bearer is PRESENT with a correct mode and this process still
     * cannot read it (owned by another OS user). It used to leave `SecretFile::read` as an
     * uncaught `ErrorException` and abort the whole run.
     *
     * STAYS `fail` — this probe certifies its OWN run, which the operator explicitly asked
     * for, and it presented no bearer in either world. What it must NOT do is inherit the
     * perms arm's remedy: the mode is already correct, so `chmod 600` would send the
     * operator to loosen permissions on a healthy bearer.
     */
    public function test_a_present_but_unreadable_bearer_fails_without_probing_and_does_not_advise_chmod(): void
    {
        Http::fake();
        $path = $this->token('unreadable', 'shhh', 0o000);
        clearstatcache(true, $path);
        if (is_readable($path)) {
            $this->markTestSkipped('this process reads through mode 0000 (running as root?) — the unreadable state is not reachable here');
        }

        $findings = $this->findingsFor([$this->httpAgent('prod-agent', $path)]);

        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertStringContainsString('could not be read by this process', $findings[0]->message);
        $this->assertStringContainsString('re-run bridge:check as a user that can read it', $findings[0]->message);
        $this->assertStringNotContainsString('chmod 600', $findings[0]->message);
        Http::assertNothingSent();
    }

    public function test_an_absent_bearer_fails_without_probing(): void
    {
        Http::fake();

        $findings = $this->findingsFor([$this->httpAgent('prod-agent', $this->dir.'/absent')]);

        // STAYS `fail` (DL-259, card#5698): the operator asked for certification with
        // --probe-tools and this probe presented no bearer, which is certain in both
        // worlds. Only the CLAIM changed — `is_file()` is false for EACCES exactly as for
        // ENOENT, so it may no longer name absence as the sole cause and send the operator
        // to re-mint a token that is already there.
        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertStringContainsString("no usable bearer at {$this->dir}/absent", $findings[0]->message);
        $this->assertStringContainsString('denies this process traversal', $findings[0]->message);
        // The old single-cause claim, pinned as an ABSENCE: asserting only the new
        // sentence would stay green if the fix appended it alongside the wrong one.
        $this->assertStringNotContainsString("no bearer at {$this->dir}/absent — run bridge:provision-tools", $findings[0]->message);
        Http::assertNothingSent();
    }

    /**
     * The continue, not the return: the ssh agent is NAMED and the HTTP agent after it is
     * still certified. Ordered deliberately — ssh first.
     */
    public function test_an_ssh_agent_is_named_and_the_probe_continues(): void
    {
        $this->fakeResult(['board_id' => 10, 'swimlane_id' => 4, 'cards_by_stage' => []]);

        $findings = $this->findingsFor([$this->sshAgent('ssh-agent'), $this->httpAgent('http-agent')]);

        $this->assertCount(2, $findings);
        $this->assertSame(Severity::Warn, $findings[0]->severity);
        $this->assertSame('board_tools probe: agent ssh-agent: uses the ssh transport — --probe-tools (HTTP) cannot certify it; run --probe-tools-ssh=<user@host> instead.', $findings[0]->message);
        $this->assertSame(Severity::Ok, $findings[1]->severity);
        $this->assertStringContainsString('agent http-agent', $findings[1]->message);
    }

    public function test_the_bearer_is_presented_to_the_endpoint(): void
    {
        $this->fakeResult(['board_id' => 10, 'swimlane_id' => 4, 'cards_by_stage' => []]);

        $this->findingsFor([$this->httpAgent('prod-agent')]);

        Http::assertSent(function ($request) {
            return $request->url() === self::ENDPOINT
                && $request['tool'] === 'board_my_cards'
                && $request->hasHeader('Authorization', 'Bearer probe-secret');
        });
    }

    private function token(string $name, string $value = 'probe-secret', int $mode = 0o600): string
    {
        $path = $this->dir.'/'.$name;
        File::put($path, $value);
        chmod($path, $mode);

        return $path;
    }

    private function httpAgent(string $name, ?string $tokenPath = null): AgentConfig
    {
        return AgentConfig::fromArray($name, [
            'identity' => ['kanban_user_id' => 1],
            'subscriptions' => [],
            'board_tools' => [
                'enabled' => true,
                'transport' => 'http',
                'auth' => ['token_path' => $tokenPath ?? $this->token($name)],
                'board_id' => 10,
                'swimlane_id' => 4,
                'create_stage_id' => 55,
            ],
        ]);
    }

    private function sshAgent(string $name): AgentConfig
    {
        return AgentConfig::fromArray($name, [
            'identity' => ['kanban_user_id' => 1],
            'subscriptions' => [],
            'board_tools' => ['enabled' => true, 'transport' => 'ssh', 'board_id' => 11, 'swimlane_id' => 5, 'create_stage_id' => 55],
        ]);
    }

    /** @param array<string, mixed> $result */
    private function fakeResult(array $result): void
    {
        Http::fake(fn () => Http::response(['result' => $result]));
    }

    /**
     * @param  list<AgentConfig>  $enabled
     * @return list<Finding>
     */
    private function findingsFor(array $enabled): array
    {
        $ctx = new CheckContext;
        $ctx->boardToolsEnabled = $enabled;

        return $this->findingsOf((new BoardToolsHttpProbeCheck(self::ENDPOINT)), $ctx);
    }
}
