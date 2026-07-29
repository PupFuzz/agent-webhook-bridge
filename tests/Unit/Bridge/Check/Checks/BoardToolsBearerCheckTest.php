<?php

namespace Tests\Unit\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Checks\BoardToolsBearerCheck;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use App\Bridge\Tools\BoardToolAgentResolver;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The DL-217 bearer problems (unreadable token file, token collision), migrated in DL-242
 * stage 7b. No golden fixture reaches them (card#5552), so these tests are the whole proof.
 *
 * THE SEVERITY IS THE ASSERTION, not a detail of it. `CheckCommandSeverityContractTest`
 * pins that `fail` — and only `fail` — flips `bridge:check`'s exit code, so asserting the
 * severity here is what completes the chain from "a dead or ambiguous bearer" to "the
 * install check exits non-zero". Under default-ON that is the DL-220 split: a broken
 * ENABLEMENT fails where a transient BOARD read only warns.
 *
 * THE PROBLEMS ARE THE RESOLVER'S, AND THAT IS DELIBERATE. The build reads each enabled
 * HTTP agent's token file and logs every collision; `problems()` only returns what the
 * build found. These tests therefore construct a REAL resolver over real 0600 files rather
 * than injecting a canned problem list — a stub would pass while the two were wired to
 * different populations.
 */
class BoardToolsBearerCheckTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/bt-bearer-'.uniqid();
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_a_missing_token_file_fails(): void
    {
        $findings = $this->findingsFor([$this->agent('prod-agent', $this->dir.'/absent-token')]);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertSame(
            "board_tools: agent prod-agent: no token at {$this->dir}/absent-token — board tools disabled for this agent until a token (chmod 600) is placed",
            $findings[0]->message,
        );
    }

    public function test_an_insecure_token_file_fails(): void
    {
        $path = $this->token('loose', 'shhh', 0o644);
        $findings = $this->findingsFor([$this->agent('prod-agent', $path)]);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertStringContainsString('board_tools: agent prod-agent: ', $findings[0]->message);
        $this->assertStringContainsString('board tools disabled for this agent until fixed', $findings[0]->message);
    }

    public function test_a_shared_token_value_fails_for_every_sharer(): void
    {
        $findings = $this->findingsFor([
            $this->agent('alpha', $this->token('a', 'same-secret')),
            $this->agent('beta', $this->token('b', 'same-secret')),
        ]);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertStringContainsString('the same auth token is shared by multiple agents (alpha, beta)', $findings[0]->message);
    }

    public function test_distinct_readable_tokens_report_nothing(): void
    {
        $findings = $this->findingsFor([
            $this->agent('alpha', $this->token('a', 'alpha-secret')),
            $this->agent('beta', $this->token('b', 'beta-secret')),
        ]);

        $this->assertSame([], $findings);
    }

    /**
     * `CheckCommand` builds no resolver when no agent has the block enabled, so the field
     * is null there — the check must be silent rather than assume one was built.
     */
    public function test_no_resolver_reports_nothing(): void
    {
        $ctx = new CheckContext;

        $this->assertNull($ctx->boardToolsResolver);
        $this->assertSame([], iterator_to_array((new BoardToolsBearerCheck)->run($ctx), false));
    }

    public function test_every_problem_is_reported_in_resolver_order(): void
    {
        $findings = $this->findingsFor([
            $this->agent('alpha', $this->dir.'/absent-a'),
            $this->agent('beta', $this->dir.'/absent-b'),
        ]);

        $this->assertCount(2, $findings);
        $this->assertStringContainsString('agent alpha:', $findings[0]->message);
        $this->assertStringContainsString('agent beta:', $findings[1]->message);
    }

    private function token(string $name, string $value, int $mode = 0o600): string
    {
        $path = $this->dir.'/'.$name;
        File::put($path, $value);
        chmod($path, $mode);

        return $path;
    }

    private function agent(string $name, string $tokenPath): AgentConfig
    {
        $config = AgentConfig::fromArray($name, [
            'identity' => ['kanban_user_id' => 1],
            'subscriptions' => [],
            'board_tools' => [
                'enabled' => true,
                'transport' => 'http',
                'auth' => ['token_path' => $tokenPath],
                'board_id' => 10,
                'swimlane_id' => 4,
                'create_stage_id' => 55,
            ],
        ]);
        $this->assertTrue($config->boardTools?->enabled, 'fixture precondition: the block must be enabled');

        return $config;
    }

    /**
     * @param  list<AgentConfig>  $configs
     * @return list<Finding>
     */
    private function findingsFor(array $configs): array
    {
        $ctx = new CheckContext;
        $ctx->boardToolsResolver = new BoardToolAgentResolver($configs);

        return iterator_to_array((new BoardToolsBearerCheck)->run($ctx), false);
    }
}
