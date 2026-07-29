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

        $this->assertSame([], iterator_to_array((new BoardToolsHttpProbeCheck(null))->run($ctx), false));
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
        $this->fakeResult(['board_id' => 10, 'swimlane_id' => 4, 'cards_by_stage' => ['backlog' => [], 'doing' => []]]);

        $findings = $this->findingsFor([$this->httpAgent('prod-agent')]);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Ok, $findings[0]->severity);
        $this->assertStringContainsString('board_tools probe: agent prod-agent: '.self::ENDPOINT.' → 200; window scoped to board 10 / swimlane 4 (2 stage group(s)).', $findings[0]->message);
    }

    public function test_a_missing_cards_by_stage_counts_zero_groups(): void
    {
        $this->fakeResult(['board_id' => 10, 'swimlane_id' => 4]);

        $this->assertStringContainsString('(0 stage group(s))', $this->findingsFor([$this->httpAgent('prod-agent')])[0]->message);
    }

    public function test_an_isolation_mismatch_fails(): void
    {
        $this->fakeResult(['board_id' => 99, 'swimlane_id' => 4]);

        $findings = $this->findingsFor([$this->httpAgent('prod-agent')]);

        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertStringContainsString('ISOLATION MISMATCH — board_my_cards returned board_id=99 swimlane_id=4, but this agent is configured for board 10 / swimlane 4.', $findings[0]->message);
    }

    /**
     * The scope header is TWO values, and either alone is an isolation violation — a test
     * that only mismatched the board would pass with the swimlane clause deleted, which is
     * the half of the filter that carries per-agent lane isolation.
     */
    public function test_a_swimlane_only_mismatch_fails(): void
    {
        $this->fakeResult(['board_id' => 10, 'swimlane_id' => 99]);

        $findings = $this->findingsFor([$this->httpAgent('prod-agent')]);

        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertStringContainsString('board_id=10 swimlane_id=99', $findings[0]->message);
    }

    /** A missing scope header is not a match — it renders as `null`, and it still fails. */
    public function test_an_absent_scope_header_fails_as_a_mismatch(): void
    {
        $this->fakeResult(['cards_by_stage' => []]);

        $findings = $this->findingsFor([$this->httpAgent('prod-agent')]);

        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertStringContainsString('board_id=null swimlane_id=null', $findings[0]->message);
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

    public function test_an_unreadable_bearer_fails_without_probing(): void
    {
        Http::fake();
        $path = $this->token('loose', 'shhh', 0o644);

        $findings = $this->findingsFor([$this->httpAgent('prod-agent', $path)]);

        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertStringContainsString('bearer not readable — ', $findings[0]->message);
        $this->assertStringContainsString('(chmod 600); cannot certify this agent.', $findings[0]->message);
        Http::assertNothingSent();
    }

    public function test_an_absent_bearer_fails_without_probing(): void
    {
        Http::fake();

        $findings = $this->findingsFor([$this->httpAgent('prod-agent', $this->dir.'/absent')]);

        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertStringContainsString("no bearer at {$this->dir}/absent — run bridge:provision-tools; cannot certify this agent.", $findings[0]->message);
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

        return iterator_to_array((new BoardToolsHttpProbeCheck(self::ENDPOINT))->run($ctx), false);
    }
}
