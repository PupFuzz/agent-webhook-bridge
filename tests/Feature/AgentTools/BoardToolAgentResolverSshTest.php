<?php

namespace Tests\Feature\AgentTools;

use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\BoardToolsConfig;
use App\Bridge\Support\ChannelConfig;
use App\Bridge\Support\ClassifierConfig;
use App\Bridge\Support\EchoSuppressionConfig;
use App\Bridge\Support\IdentityConfig;
use App\Bridge\Tools\BoardToolAgentResolver;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The HTTP token→agent index must NEVER reach an ssh-transport agent (card 4952,
 * §2.2). The loader already rejects a contradictory ssh+auth block (DR2-1), so this
 * exercises the resolver's OWN `transport !== 'http'` exclusion belt-and-suspenders:
 * even a SPURIOUS ssh config carrying a real token file is not indexed, and presenting
 * that token over the HTTP door resolves to null. Red-when-reverted: dropping the
 * `transport !== 'http'` clause would index it.
 */
class BoardToolAgentResolverSshTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/resolver-ssh-'.uniqid();
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function agent(string $name, BoardToolsConfig $bt): AgentConfig
    {
        return new AgentConfig(
            agentName: $name,
            identity: new IdentityConfig,
            subscriptions: [],
            echoSuppression: new EchoSuppressionConfig,
            classifierClass: 'x',
            classifierConfig: ClassifierConfig::empty(),
            channel: new ChannelConfig,
            tokenPathOverrides: [],
            surfaceSilentDropWarnings: true,
            raw: [],
            boardTools: $bt,
        );
    }

    public function test_ssh_agent_with_a_spurious_token_file_is_not_indexed(): void
    {
        $tokenFile = $this->dir.'/ssh-agent-token';
        File::put($tokenFile, 'spurious-ssh-token');   // gitleaks:allow — test fixture
        chmod($tokenFile, 0o600);

        // A spurious ssh config: transport ssh AND a non-null tokenPath (a state the
        // loader would reject — constructed directly to isolate the resolver clause).
        $ssh = new BoardToolsConfig(
            enabled: true, tokenPath: $tokenFile, boardId: 10, swimlaneId: 4, createStageId: 55,
            sharedSwimlaneId: null, coordBoardId: null, addressTags: [], transport: 'ssh',
        );

        $resolver = new BoardToolAgentResolver([$this->agent('ssh-agent', $ssh)]);

        // Presenting the ssh agent's token value over the HTTP door resolves to NO agent.
        $this->assertNull($resolver->resolve('spurious-ssh-token'));
    }

    public function test_http_agent_alongside_is_still_indexed(): void
    {
        // Control: the exclusion is transport-scoped, not a blanket refusal — an http
        // agent with the same token shape IS resolvable.
        $tokenFile = $this->dir.'/http-agent-token';
        File::put($tokenFile, 'http-token-value');   // gitleaks:allow — test fixture
        chmod($tokenFile, 0o600);

        $http = new BoardToolsConfig(
            enabled: true, tokenPath: $tokenFile, boardId: 10, swimlaneId: 4, createStageId: 55,
            sharedSwimlaneId: null, coordBoardId: null, addressTags: [], transport: 'http',
        );

        $resolver = new BoardToolAgentResolver([$this->agent('http-agent', $http)]);

        $resolved = $resolver->resolve('http-token-value');
        $this->assertNotNull($resolved);
        $this->assertSame('http-agent', $resolved->agentName);
    }
}
