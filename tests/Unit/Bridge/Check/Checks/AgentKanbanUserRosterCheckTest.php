<?php

namespace Tests\Unit\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Checks\AgentKanbanUserRosterCheck;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use Illuminate\Support\Facades\File;
use Tests\Support\MaterializesChecks;
use Tests\TestCase;

/**
 * card#10869 — each agent's `identity.kanban_user_id` held against the coord roster, the single
 * store of it (card#10868 Q1). One test per row of the check's state table, plus the controls
 * that keep each row honest about what separated it from its neighbour.
 */
class AgentKanbanUserRosterCheckTest extends TestCase
{
    use MaterializesChecks;

    private const API = 'https://kanban.example.com/api/v3';

    private const HOST = 'kanban.example.com';

    private string $dir;

    private string|false $origCoordConfig;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/roster-check-'.uniqid();
        File::ensureDirectoryExists($this->dir);
        $this->origCoordConfig = getenv('COORD_CONFIG');
        putenv('COORD_CONFIG');
        config([
            'bridge.writeback.coord_config_path' => null,
            'bridge.providers.kanban.api_base_url' => self::API,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        $this->origCoordConfig === false ? putenv('COORD_CONFIG') : putenv('COORD_CONFIG='.$this->origCoordConfig);
        parent::tearDown();
    }

    // ---- the id-ABSENT path: WARN until the roster carries the ids (DL-439 ruling C; flip not built) ----

    public function test_a_roster_seat_with_no_kanban_user_id_warns_and_never_fails(): void
    {
        $this->roster([['name' => 'impl']]);

        $findings = $this->findings(['impl' => ['kanban_user_id' => 7]]);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Warn, $findings[0]->severity);
        $this->assertStringContainsString("seat 'impl' carries no kanban user id for this kanban instance ('kanban.example.com')", $findings[0]->message);
        $this->assertStringContainsString('`nofield`', $findings[0]->message);
        $this->assertStringContainsString('identity.kanban_user_id (7) is therefore UNVERIFIED against the roster', $findings[0]->message);
    }

    public function test_a_board_tools_seat_with_no_id_anywhere_warns_that_every_take_refuses(): void
    {
        $this->roster([['name' => 'impl', 'kanban_user_id' => ['other.example.com' => 3]]]);

        $findings = $this->findingsOfConfigs([$this->boardToolsAgent('impl', [])]);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Warn, $findings[0]->severity);
        $this->assertStringContainsString('`nohost`', $findings[0]->message);
        $this->assertStringContainsString('board_take_card refuses every call from this agent', $findings[0]->message);
    }

    /**
     * A string "7" is refused, not coerced — the toolkit's `seat_uid_verdict` rule. The
     * control is the next test: the same seat with the integer 7 matches.
     */
    public function test_a_string_roster_id_is_malformed_not_coerced(): void
    {
        $this->roster([['name' => 'impl', 'kanban_user_id' => [self::HOST => '7']]]);

        $findings = $this->findings(['impl' => ['kanban_user_id' => 7]]);

        $this->assertSame(Severity::Warn, $findings[0]->severity);
        $this->assertStringContainsString('is "7", not a positive integer kanban user id (roster verdict `bad`)', $findings[0]->message);
    }

    /**
     * An integral FLOAT is not an id either (toolkit card#10868 comment 7670): the file's own
     * `7.0` literal decodes to a float, and it is `bad`, not user 7.
     */
    public function test_an_integral_float_roster_id_is_malformed_not_user_seven(): void
    {
        $path = $this->roster([]);
        File::put($path, '{"project":"p","roster":[{"name":"impl","kanban_user_id":{"'.self::HOST.'":7.0}}]}');

        $findings = $this->findings(['impl' => ['kanban_user_id' => 7]]);

        $this->assertSame(Severity::Warn, $findings[0]->severity);
        $this->assertStringContainsString('(roster verdict `bad`)', $findings[0]->message);
    }

    public function test_an_equal_id_is_ok(): void
    {
        $this->roster([['name' => 'impl', 'kanban_user_id' => [self::HOST => 7]]]);

        $findings = $this->findings(['impl' => ['kanban_user_id' => 7]]);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Ok, $findings[0]->severity);
        $this->assertStringContainsString('matches the coord roster', $findings[0]->message);
    }

    // ---- drift: the cache disagrees with the store ----

    public function test_a_yaml_id_that_differs_from_the_roster_fails(): void
    {
        $this->roster([['name' => 'impl', 'kanban_user_id' => [self::HOST => 7]]]);

        $findings = $this->findings(['impl' => ['kanban_user_id' => 8]]);

        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertStringContainsString('identity.kanban_user_id is 8, but the coord roster says seat \'impl\' is kanban user 7', $findings[0]->message);
    }

    public function test_a_roster_id_the_yaml_does_not_carry_fails(): void
    {
        $this->roster([['name' => 'impl', 'kanban_user_id' => [self::HOST => 7]]]);

        $findings = $this->findingsOfConfigs([$this->boardToolsAgent('impl', [])]);

        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertStringContainsString('Set identity.kanban_user_id: 7', $findings[0]->message);
    }

    /**
     * The instance KEY is the host of the kanban API base, read like the toolkit's
     * `kb_url_host` — so a port and a path do not change which roster entry answers.
     */
    public function test_the_roster_is_keyed_by_the_api_base_host_not_the_whole_url(): void
    {
        config(['bridge.providers.kanban.api_base_url' => 'https://kanban.example.com:8443/api/v3']);
        $this->roster([['name' => 'impl', 'kanban_user_id' => [self::HOST => 7, 'kanban.example.com:8443' => 9]]]);

        $findings = $this->findings(['impl' => ['kanban_user_id' => 7]]);

        $this->assertSame(Severity::Ok, $findings[0]->severity);
    }

    // ---- the join: which roster seat an agent is ----

    public function test_coord_seat_joins_an_agent_whose_name_is_not_its_seat(): void
    {
        $this->roster([['name' => 'kanban', 'kanban_user_id' => [self::HOST => 7]]]);

        $findings = $this->findings(['kanban-solo' => ['kanban_user_id' => 8, 'coord_seat' => 'kanban']]);

        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertStringContainsString("seat 'kanban' is kanban user 7", $findings[0]->message);
    }

    public function test_the_first_roster_entry_with_the_name_decides(): void
    {
        $this->roster([
            'not-a-seat',
            ['name' => 'impl', 'kanban_user_id' => [self::HOST => 7]],
            ['name' => 'impl', 'kanban_user_id' => [self::HOST => 8]],
        ]);

        $this->assertSame(Severity::Ok, $this->findings(['impl' => ['kanban_user_id' => 7]])[0]->severity);
    }

    public function test_an_agent_that_is_no_seat_is_named_in_an_ok_line_not_warned(): void
    {
        $this->roster([['name' => 'impl']]);

        $findings = $this->findings(['prod-agent' => ['kanban_user_id' => 3], 'dev-agent' => ['kanban_user_id' => 4]]);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Ok, $findings[0]->severity);
        $this->assertStringContainsString('dev-agent, prod-agent carry identity.kanban_user_id but serve no coord roster seat', $findings[0]->message);
        $this->assertStringContainsString('their ids are attribution-only', $findings[0]->message);
    }

    public function test_a_board_tools_agent_that_is_no_seat_warns(): void
    {
        $this->roster([['name' => 'impl']]);

        $findings = $this->findingsOfConfigs([$this->boardToolsAgent('worker', ['kanban_user_id' => 5])]);

        $this->assertSame(Severity::Warn, $findings[0]->severity);
        $this->assertStringContainsString("the coord roster has no seat named 'worker'", $findings[0]->message);
    }

    public function test_two_agents_resolving_to_one_seat_are_named(): void
    {
        $this->roster([['name' => 'kanban', 'kanban_user_id' => [self::HOST => 7]]]);

        $findings = $this->findings([
            'kanban' => ['kanban_user_id' => 7],
            'kanban-solo' => ['kanban_user_id' => 8, 'coord_seat' => 'kanban'],
        ]);

        $warns = array_values(array_filter($findings, fn (Finding $f): bool => $f->severity === Severity::Warn));
        $this->assertCount(1, $warns);
        $this->assertStringContainsString("agents kanban, kanban-solo all resolve to coord roster seat 'kanban'", $warns[0]->message);
        // …and the one whose copy disagrees with the store still FAILS on its own row.
        $this->assertCount(1, array_filter($findings, fn (Finding $f): bool => $f->severity === Severity::Fail));
    }

    /**
     * `coord_seat` alone names a seat; it does not put an agent in the comparison. The control
     * for the pairing warn above: the same seat, the second agent carrying no id and no board
     * tools, is not a second member and draws nothing.
     */
    public function test_a_second_identity_with_only_coord_seat_is_not_compared(): void
    {
        $this->roster([['name' => 'kanban', 'kanban_user_id' => [self::HOST => 7]]]);

        $findings = $this->findings([
            'kanban' => ['kanban_user_id' => 7],
            'kanban-solo' => ['coord_seat' => 'kanban'],
        ]);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Ok, $findings[0]->severity);
    }

    // ---- could not look ----

    public function test_no_coord_config_is_unvalidated_once_when_an_agent_can_take_cards(): void
    {
        $findings = $this->findingsOfConfigs([$this->boardToolsAgent('a', ['kanban_user_id' => 7]), $this->boardToolsAgent('b', ['kanban_user_id' => 8])]);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Unvalidated, $findings[0]->severity);
        $this->assertStringContainsString('$COORD_CONFIG is not set', $findings[0]->message);
    }

    /**
     * An install that names no coordination project and runs no board tools has no roster: its
     * ids are attribution-only, so there is nothing to verify and nothing is said. The control is
     * the test above — the same unset path with a card-taking agent is CANNOT-VERIFY.
     */
    public function test_an_install_naming_no_coord_config_and_taking_no_cards_is_silent(): void
    {
        $this->assertSame([], $this->findings(['a' => ['kanban_user_id' => 7]]));
    }

    public function test_a_named_coord_config_that_cannot_be_read_is_unvalidated_even_without_board_tools(): void
    {
        config(['bridge.writeback.coord_config_path' => $this->dir.'/absent.json']);

        $findings = $this->findings(['a' => ['kanban_user_id' => 7]]);

        $this->assertSame(Severity::Unvalidated, $findings[0]->severity);
        $this->assertStringContainsString('absent.json is absent, unreadable, or malformed', $findings[0]->message);
    }

    public function test_the_ambient_coord_config_is_read_when_the_install_names_none(): void
    {
        putenv('COORD_CONFIG='.$this->roster([['name' => 'impl', 'kanban_user_id' => [self::HOST => 7]]]));
        config(['bridge.writeback.coord_config_path' => null]);

        $this->assertSame(Severity::Ok, $this->findings(['impl' => ['kanban_user_id' => 7]])[0]->severity);
    }

    public function test_an_api_base_with_no_host_is_unvalidated(): void
    {
        $this->roster([['name' => 'impl', 'kanban_user_id' => [self::HOST => 7]]]);
        config(['bridge.providers.kanban.api_base_url' => '']);

        $findings = $this->findings(['impl' => ['kanban_user_id' => 7]]);

        $this->assertSame(Severity::Unvalidated, $findings[0]->severity);
        $this->assertStringContainsString('names no host', $findings[0]->message);
    }

    public function test_an_install_with_no_agent_carrying_an_id_reports_nothing(): void
    {
        $this->assertSame([], $this->findings(['a' => [], 'b' => ['github_user_id' => 9]]));
    }

    // ---- helpers ----

    /**
     * @param  list<mixed>  $roster
     */
    private function roster(array $roster): string
    {
        $path = $this->dir.'/coordination.config.json';
        File::put($path, (string) json_encode(['project' => 'p', 'roster' => $roster]));
        config(['bridge.writeback.coord_config_path' => $path]);

        return $path;
    }

    /**
     * @param  array<string, array<string, mixed>>  $identities  agent name => identity block
     * @return list<Finding>
     */
    private function findings(array $identities): array
    {
        $configs = [];
        foreach ($identities as $name => $identity) {
            $configs[] = AgentConfig::fromArray($name, ['identity' => $identity, 'subscriptions' => []]);
        }

        return $this->findingsOfConfigs($configs);
    }

    /**
     * @param  list<AgentConfig>  $configs
     * @return list<Finding>
     */
    private function findingsOfConfigs(array $configs): array
    {
        $ctx = new CheckContext;
        $ctx->configs = $configs;

        return $this->findingsOf(new AgentKanbanUserRosterCheck, $ctx);
    }

    /**
     * @param  array<string, mixed>  $identity
     */
    private function boardToolsAgent(string $name, array $identity): AgentConfig
    {
        return AgentConfig::fromArray($name, [
            'identity' => $identity,
            'subscriptions' => [],
            'board_tools' => ['enabled' => true, 'transport' => 'ssh', 'board_id' => 10, 'swimlane_id' => 4, 'create_stage_id' => 55],
        ]);
    }
}
