<?php

namespace Tests\Unit\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Checks\AgentKanbanUserRosterCheck;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use App\Bridge\Support\UntrustedPathContents;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CoordRosterFixture;
use Tests\Support\MaterializesChecks;
use Tests\TestCase;

/**
 * card#11172 / DL-450 — the coord roster is the ONLY source of each agent's kanban user id, read
 * at runtime from `BRIDGE_COORD_CONFIG_PATH`. This leg says whether that read works, whether every
 * board-tools agent's seat resolves to an id, and what to do with a retired
 * `identity.kanban_user_id` still in a YAML. One test per row of the check's state table, plus the
 * controls that keep each row honest about what separated it from its neighbour.
 */
class AgentKanbanUserRosterCheckTest extends TestCase
{
    use MaterializesChecks;

    private const API = 'https://kanban.example.com/api/v3';

    private const HOST = CoordRosterFixture::HOST;

    private string $dir;

    private string|false $origCoordConfig;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/roster-check-'.uniqid();
        File::ensureDirectoryExists($this->dir);
        $this->origCoordConfig = getenv('COORD_CONFIG');
        putenv('COORD_CONFIG');
        config(['bridge.providers.kanban.api_base_url' => self::API]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        $this->origCoordConfig === false ? putenv('COORD_CONFIG') : putenv('COORD_CONFIG='.$this->origCoordConfig);
        parent::tearDown();
    }

    // ---- the setting and the file: the runtime's one read ----

    public function test_an_unset_setting_fails_with_the_env_line_to_add(): void
    {
        config(['bridge.coord_config_path' => null]);

        $findings = $this->findings(['a' => []]);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertStringContainsString('BRIDGE_COORD_CONFIG_PATH is not set', $findings[0]->message);
        $this->assertStringContainsString('BRIDGE_COORD_CONFIG_PATH=<absolute path to coordination.config.json>', $findings[0]->message);
    }

    /**
     * The runtime never reads the ambient `$COORD_CONFIG`, so neither does this leg: a check that
     * found the roster through it would pass an install whose receiver reads nothing. The control
     * is the next test, which reads the same file through the setting.
     */
    public function test_the_ambient_coord_config_does_not_satisfy_the_setting(): void
    {
        config(['bridge.coord_config_path' => null]);
        putenv('COORD_CONFIG='.$this->roster(['impl' => 7]));
        config(['bridge.coord_config_path' => null]);

        $this->assertSame(Severity::Fail, $this->findings(['impl' => []])[0]->severity);
    }

    public function test_a_readable_roster_reports_who_read_it_and_what_it_did_not_measure(): void
    {
        $path = $this->roster(['impl' => 7]);

        $findings = $this->findings(['impl' => []]);

        $this->assertSame(Severity::Ok, $findings[0]->severity);
        $this->assertStringContainsString("coord roster at {$path} is readable by this run's OS user", $findings[0]->message);
        $this->assertStringContainsString('PHP-FPM pool user', $findings[0]->message);
        $this->assertStringContainsString('sudo -u <pool user> php artisan bridge:check', $findings[0]->message);
    }

    public function test_a_relative_setting_fails(): void
    {
        config(['bridge.coord_config_path' => 'coordination.config.json']);

        $findings = $this->findings(['a' => []]);

        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertStringContainsString('not an absolute path', $findings[0]->message);
    }

    public function test_a_roster_that_is_not_json_fails_because_every_reader_sees_the_same_bytes(): void
    {
        CoordRosterFixture::configureRaw($this->dir, '{ nope');

        $findings = $this->findings(['a' => []]);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertStringContainsString('is not a JSON object', $findings[0]->message);
    }

    /**
     * A file this process cannot read is UNMEASURED, never a pass and never a verdict about the
     * runtime's user — the receiver reads it as another OS user.
     */
    /**
     * No file at the path is the same answer for every reader, the receiver's pool user included
     * — a measured fault, so it FAILS (round-1 ruling: it used to read as UNVALIDATED, exit 0).
     */
    public function test_an_absent_roster_fails(): void
    {
        config(['bridge.coord_config_path' => $this->dir.'/absent.json']);

        $findings = $this->findings(['a' => []]);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertStringContainsString("there is no coord roster at {$this->dir}/absent.json", $findings[0]->message);
    }

    /**
     * A symlink, a directory, or a file past the read bound is refused by EVERY reader the same
     * way, so each FAILS — never the UNVALIDATED a permission refusal earns.
     *
     * @return array<string, array{\Closure(string): string, string}>
     */
    public static function notAFileShapes(): array
    {
        return [
            'a symlink to a readable roster' => [static function (string $dir): string {
                file_put_contents($dir.'/real.json', '{"roster":[]}');
                symlink($dir.'/real.json', $dir.'/link.json');

                return $dir.'/link.json';
            }, 'it is a symlink'],
            'a directory' => [static function (string $dir): string {
                mkdir($dir.'/adir');

                return $dir.'/adir';
            }, 'not a regular file'],
            'a file past the read bound' => [static function (string $dir): string {
                file_put_contents($dir.'/big.json', str_repeat(' ', UntrustedPathContents::MAX_BYTES + 1));

                return $dir.'/big.json';
            }, 'byte bound'],
        ];
    }

    /** @param \Closure(string): string $make */
    #[DataProvider('notAFileShapes')]
    public function test_a_path_that_is_not_a_file_the_bridge_will_read_fails(\Closure $make, string $why): void
    {
        config(['bridge.coord_config_path' => $make($this->dir)]);

        $findings = $this->findings(['a' => []]);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertStringContainsString('is not a file the bridge will read', $findings[0]->message);
        $this->assertStringContainsString($why, $findings[0]->message);
    }

    /**
     * ONLY a permission refusal is uid-relative — the pool user may read what this run cannot —
     * so it alone is UNVALIDATED, naming this run's uid and how to measure the pool user.
     */
    public function test_a_roster_this_process_is_refused_permission_to_read_is_unvalidated(): void
    {
        $this->skipAsRoot();
        $path = CoordRosterFixture::configureRaw($this->dir, '{"roster":[]}');
        chmod($path, 0o000);

        try {
            $findings = $this->findings(['a' => []]);
        } finally {
            chmod($path, 0o644);
        }

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Unvalidated, $findings[0]->severity);
        $this->assertStringContainsString($path, $findings[0]->message);
        $this->assertStringContainsString('(uid ', $findings[0]->message);
        $this->assertStringContainsString('sudo -u <pool user> php artisan bridge:check', $findings[0]->message);
    }

    /** The runtime cannot key an id without a host and refuses, so the leg FAILS rather than pass. */
    public function test_an_api_base_with_no_host_fails(): void
    {
        $this->roster(['impl' => 7]);
        config(['bridge.providers.kanban.api_base_url' => '']);

        $findings = $this->findings(['impl' => []]);

        $this->assertSame(Severity::Fail, $findings[1]->severity);
        $this->assertStringContainsString('names no kanban host', $findings[1]->message);
    }

    public function test_an_install_with_no_agents_is_silent(): void
    {
        config(['bridge.coord_config_path' => null]);

        $this->assertSame([], $this->findingsOfConfigs([]));
    }

    /**
     * An install no agent of which reads a kanban id at runtime — github subscriptions only, no
     * board tools — is not asked for the setting. The control is the unset test above, whose
     * agent subscribes to kanban.
     */
    public function test_a_github_only_install_without_board_tools_is_not_asked_for_the_roster(): void
    {
        config(['bridge.coord_config_path' => null]);
        $configs = [AgentConfig::fromArray('ci', ['identity' => ['kanban_user_id' => 7], 'subscriptions' => [['provider' => 'github', 'scopes' => ['o/r']]]])];

        $this->assertSame([], $this->findingsOfConfigs($configs));
    }

    // ---- every board-tools agent's seat resolves to an id ----

    public function test_a_board_tools_agent_whose_seat_resolves_is_ok(): void
    {
        $this->roster(['impl' => 7]);

        $findings = $this->agentFindings([$this->boardToolsAgent('impl', [])]);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Ok, $findings[0]->severity);
        $this->assertStringContainsString("agent impl: kanban user 7 (seat 'impl' on '".self::HOST."' in the coord roster)", $findings[0]->message);
    }

    public function test_a_board_tools_agent_whose_seat_is_absent_fails_with_the_take_refusal_code(): void
    {
        $this->roster(['somebody' => 7]);

        $findings = $this->agentFindings([$this->boardToolsAgent('worker', [])]);

        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertStringContainsString("the coord roster has no seat named 'worker'", $findings[0]->message);
        $this->assertStringContainsString('install_fault.roster_seat_absent', $findings[0]->message);
    }

    public function test_a_board_tools_agent_whose_seat_has_no_id_for_this_host_fails(): void
    {
        $this->roster(['impl' => ['other.example.com' => 3]]);

        $findings = $this->agentFindings([$this->boardToolsAgent('impl', [])]);

        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertStringContainsString('`nohost`', $findings[0]->message);
        $this->assertStringContainsString('install_fault.no_kanban_user', $findings[0]->message);
    }

    /**
     * A non-board-tools agent whose roster seat carries no id is not refused anything, but it has
     * no kanban user: WARN, with the remedy and its bound (the framework writes the id for pm and
     * solo seats only, card#11147).
     */
    public function test_a_seat_with_no_kanban_user_id_warns_for_an_agent_that_takes_no_cards(): void
    {
        $this->roster(['impl' => null]);

        $findings = $this->agentFindings($this->configs(['impl' => []]));

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Warn, $findings[0]->severity);
        $this->assertStringContainsString("seat 'impl' carries no kanban user id for this kanban instance ('".self::HOST."')", $findings[0]->message);
        $this->assertStringContainsString('`nofield`', $findings[0]->message);
        $this->assertStringContainsString('writes it for pm and solo seats only', $findings[0]->message);
        $this->assertStringContainsString('set by hand', $findings[0]->message);
    }

    /** An agent that is no seat, takes no cards and declares no coord_seat has nothing to report. */
    /** A non-seat that subscribes to nothing kanban and takes no cards has no kanban id to lose. */
    public function test_an_agent_that_is_no_seat_and_reads_no_kanban_id_is_silent(): void
    {
        $this->roster(['impl' => 7]);
        $configs = $this->configs(['impl' => []]);
        $configs[] = AgentConfig::fromArray('ci-bot', ['identity' => ['github_user_id' => 9], 'subscriptions' => [['provider' => 'github', 'scopes' => ['o/r']]]]);

        $findings = $this->agentFindings($configs);

        $this->assertCount(1, $findings, 'only impl\'s own ok line');
        $this->assertStringContainsString('agent impl:', $findings[0]->message);
    }

    /**
     * Round-1 ruling 2: an agent subscribed to kanban events that is no seat and declares no
     * coord_seat has NO kanban user — its own writes are not suppressed as its echoes — and is
     * WARNED, never passed over in silence. The control is the test above.
     */
    public function test_a_kanban_subscribed_agent_that_is_no_seat_warns(): void
    {
        $this->roster(['impl' => 7]);

        $findings = $this->agentFindings($this->configs(['ci-bot' => ['github_user_id' => 9]]));

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Warn, $findings[0]->severity);
        $this->assertStringContainsString('subscribes to kanban events but is no seat of the coord roster', $findings[0]->message);
        $this->assertStringContainsString('identity.peer_kanban_user_id', $findings[0]->message);
    }

    // ---- identity.peer_kanban_user_id: an id this roster does not own ----

    public function test_a_peer_id_on_a_non_seat_is_reported_as_attribution_only(): void
    {
        $this->roster(['impl' => 7]);

        $findings = $this->agentFindings($this->configs(['peer' => ['peer_kanban_user_id' => 42]]));

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Ok, $findings[0]->severity);
        $this->assertStringContainsString('attribution-only kanban user 42', $findings[0]->message);
    }

    /** On an agent that IS a seat the peer field would be a second copy of the roster's id. */
    public function test_a_peer_id_on_a_roster_seat_fails(): void
    {
        $this->roster(['impl' => 7]);

        $findings = $this->agentFindings($this->configs(['impl' => ['peer_kanban_user_id' => 7]]));

        $fails = array_values(array_filter($findings, fn (Finding $f): bool => $f->severity === Severity::Fail));
        $this->assertCount(1, $fails);
        $this->assertStringContainsString("declares identity.peer_kanban_user_id 7, but it IS coord roster seat 'impl'", $fails[0]->message);
    }

    /**
     * Round 2 S1(a): an agent that is no seat BY NAME but whose peer id is a roster seat's id is
     * carrying that seat's id under another name — the duplicate the field must never hold.
     */
    public function test_a_peer_id_equal_to_any_roster_seat_s_id_fails(): void
    {
        $this->roster(['impl' => 7]);

        $findings = $this->agentFindings($this->configs(['impl-bridge' => ['peer_kanban_user_id' => 7]]));

        $fails = array_values(array_filter($findings, fn (Finding $f): bool => $f->severity === Severity::Fail));
        $this->assertCount(1, $fails);
        $this->assertStringContainsString("identity.peer_kanban_user_id 7 is the kanban user the coord roster gives seat 'impl'", $fails[0]->message);
        $this->assertSame([], array_filter($findings, fn (Finding $f): bool => $f->severity === Severity::Ok), 'never reported as a valid attribution-only id');
    }

    /**
     * Round 2 S1(b): a coord_seat is a claim to BE a seat, so it and a peer id are exclusive — and
     * a mistyped coord_seat still WARNS whatever the peer field says.
     */
    public function test_coord_seat_and_a_peer_id_together_fail_and_the_absent_seat_still_warns(): void
    {
        $this->roster(['impl' => 7]);

        $findings = $this->agentFindings($this->configs(['me' => ['coord_seat' => 'typo', 'peer_kanban_user_id' => 42]]));

        $fails = array_values(array_filter($findings, fn (Finding $f): bool => $f->severity === Severity::Fail));
        $warns = array_values(array_filter($findings, fn (Finding $f): bool => $f->severity === Severity::Warn));
        $this->assertCount(1, $fails);
        $this->assertStringContainsString('declares both identity.coord_seat and identity.peer_kanban_user_id', $fails[0]->message);
        $this->assertCount(1, $warns);
        $this->assertStringContainsString("declares identity.coord_seat 'typo'", $warns[0]->message);
    }

    // ---- a seat whose id does not identify one taker (round-1 rulings 4 and 8) ----

    public function test_two_board_tools_agents_on_one_seat_both_fail(): void
    {
        $this->roster(['kanban' => 7]);

        $findings = $this->agentFindings([
            $this->boardToolsAgent('kanban', []),
            $this->boardToolsAgent('kanban-copy', ['coord_seat' => 'kanban']),
        ]);

        $fails = array_values(array_filter($findings, fn (Finding $f): bool => $f->severity === Severity::Fail));
        $this->assertCount(2, $fails);
        foreach ($fails as $fail) {
            $this->assertStringContainsString('install_fault.shared_kanban_user', $fail->message);
        }
        $this->assertSame([], array_filter($findings, fn (Finding $f): bool => $f->severity === Severity::Warn), 'the FAIL replaces the shared-seat warn');
    }

    /**
     * card#11283 MF-1: a SCOPE-LESS agent (CI tools only) on a scoped agent's seat cannot take, so
     * it is not a second taker and the seat's id still names one agent. Control: the test above,
     * where the second agent IS scoped and both fail.
     */
    public function test_a_scope_less_agent_on_a_scoped_agents_seat_is_not_a_second_taker(): void
    {
        $this->roster(['kanban' => 7]);

        $findings = $this->agentFindings([
            $this->boardToolsAgent('kanban', []),
            AgentConfig::fromArray('kanban-ci', ['identity' => ['coord_seat' => 'kanban'], 'subscriptions' => [], 'board_tools' => ['enabled' => true, 'transport' => 'ssh']]),
        ]);

        $this->assertSame([], array_values(array_filter($findings, fn (Finding $f): bool => $f->severity === Severity::Fail)));
    }

    /** A scope-less agent is never failed for a take it cannot make. */
    public function test_a_scope_less_agent_with_no_roster_seat_is_not_failed_for_the_take(): void
    {
        $this->roster(['kanban' => 7]);

        $findings = $this->findingsOfConfigs([
            AgentConfig::fromArray('impl', ['subscriptions' => [], 'board_tools' => ['enabled' => true, 'transport' => 'ssh']]),
        ]);

        $this->assertSame([], array_values(array_filter(
            $findings,
            fn (Finding $f): bool => $f->severity === Severity::Fail || str_contains($f->message, 'board_take_card'),
        )));
    }

    public function test_a_board_tools_agent_whose_roster_id_another_seat_has_fails(): void
    {
        $this->roster(['impl' => 7, 'twin' => 7]);

        $findings = $this->agentFindings([$this->boardToolsAgent('impl', []), ...$this->configs(['twin' => []])]);

        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertStringContainsString("to seat 'twin' as well", $findings[0]->message);
        $this->assertStringContainsString('install_fault.shared_kanban_user', $findings[0]->message);
    }

    public function test_a_declared_coord_seat_the_roster_lacks_warns(): void
    {
        $this->roster(['impl' => 7]);

        $findings = $this->agentFindings($this->configs(['me' => ['coord_seat' => 'typo']]));

        $this->assertSame(Severity::Warn, $findings[0]->severity);
        $this->assertStringContainsString("declares identity.coord_seat 'typo'", $findings[0]->message);
    }

    // ---- the roster read itself: ports of the toolkit's reader ----

    /** A string "7" is refused, not coerced; the control is the integer in the test above. */
    public function test_a_string_roster_id_is_malformed_not_coerced(): void
    {
        $this->roster(['impl' => [self::HOST => '7']]);

        $findings = $this->agentFindings([$this->boardToolsAgent('impl', [])]);

        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertStringContainsString('is "7", not a positive integer kanban user id (roster verdict `bad`)', $findings[0]->message);
    }

    /** An integral FLOAT is not an id either (toolkit card#10868 comment 7670). */
    public function test_an_integral_float_roster_id_is_malformed_not_user_seven(): void
    {
        CoordRosterFixture::configureRaw($this->dir, '{"project":"p","roster":[{"name":"impl","kanban_user_id":{"'.self::HOST.'":7.0}}]}');

        $findings = $this->agentFindings([$this->boardToolsAgent('impl', [])]);

        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertStringContainsString('(roster verdict `bad`)', $findings[0]->message);
    }

    /** The instance key is the HOST of the API base, so a port and a path do not change it. */
    public function test_the_roster_is_keyed_by_the_api_base_host_not_the_whole_url(): void
    {
        config(['bridge.providers.kanban.api_base_url' => 'https://kanban.example.com:8443/api/v3']);
        $this->roster(['impl' => [self::HOST => 7, 'kanban.example.com:8443' => 9]]);

        $findings = $this->agentFindings([$this->boardToolsAgent('impl', [])]);

        $this->assertStringContainsString('kanban user 7', $findings[0]->message);
    }

    public function test_coord_seat_joins_an_agent_whose_name_is_not_its_seat(): void
    {
        $this->roster(['kanban' => 7]);

        $findings = $this->agentFindings([$this->boardToolsAgent('kanban-solo', ['coord_seat' => 'kanban'])]);

        $this->assertSame(Severity::Ok, $findings[0]->severity);
        $this->assertStringContainsString("kanban user 7 (seat 'kanban'", $findings[0]->message);
    }

    public function test_the_first_roster_entry_with_the_name_decides(): void
    {
        CoordRosterFixture::configureRaw($this->dir, (string) json_encode(['roster' => [
            'not-a-seat',
            ['name' => 'impl', 'kanban_user_id' => [self::HOST => 7]],
            ['name' => 'impl', 'kanban_user_id' => [self::HOST => 8]],
        ]]));

        $this->assertStringContainsString('kanban user 7', $this->agentFindings([$this->boardToolsAgent('impl', [])])[0]->message);
    }

    public function test_two_agents_resolving_to_one_seat_with_an_id_are_named(): void
    {
        $this->roster(['kanban' => 7]);

        $findings = $this->agentFindings($this->configs([
            'kanban' => [],
            'kanban-solo' => ['coord_seat' => 'kanban'],
        ]));

        $warns = array_values(array_filter($findings, fn (Finding $f): bool => $f->severity === Severity::Warn));
        $this->assertCount(1, $warns);
        $this->assertStringContainsString("agents kanban, kanban-solo all resolve to coord roster seat 'kanban' (kanban user 7)", $warns[0]->message);
    }

    // ---- the migration: a retired identity.kanban_user_id still in a YAML ----

    public function test_a_retired_yaml_id_equal_to_the_roster_warns_remove_it(): void
    {
        $this->roster(['impl' => 7]);

        $findings = $this->agentFindings($this->configs(['impl' => ['kanban_user_id' => 7]]));

        $warns = array_values(array_filter($findings, fn (Finding $f): bool => $f->severity === Severity::Warn));
        $this->assertCount(1, $warns);
        $this->assertStringContainsString('identity.kanban_user_id 7 is no longer read', $warns[0]->message);
        $this->assertStringContainsString('remove it from impl.yml', $warns[0]->message);
        $this->assertSame([], array_filter($findings, fn (Finding $f): bool => $f->severity === Severity::Fail));
    }

    public function test_a_retired_yaml_id_that_differs_from_the_roster_fails(): void
    {
        $this->roster(['impl' => 7]);

        $findings = $this->agentFindings($this->configs(['impl' => ['kanban_user_id' => 8]]));

        $fails = array_values(array_filter($findings, fn (Finding $f): bool => $f->severity === Severity::Fail));
        $this->assertCount(1, $fails);
        $this->assertStringContainsString("identity.kanban_user_id is 8, but the bridge reads seat 'impl's id in the coord roster", $fails[0]->message);
        $this->assertStringContainsString("which is 7 on '".self::HOST."'", $fails[0]->message);
        $this->assertStringContainsString('the bridge acts as kanban user 7', $fails[0]->message);
    }

    /**
     * The fourth migration row: the YAML carried an id the roster does not — the agent LOST its
     * kanban user on upgrade, which is a FAIL that says where to put the id.
     */
    public function test_a_retired_yaml_id_on_a_seat_the_roster_gives_no_id_fails_as_lost(): void
    {
        $this->roster(['impl' => null]);

        $findings = $this->agentFindings($this->configs(['impl' => ['kanban_user_id' => 3]]));

        $fails = array_values(array_filter($findings, fn (Finding $f): bool => $f->severity === Severity::Fail));
        $this->assertCount(1, $fails);
        $this->assertStringContainsString('identity.kanban_user_id 3 is no longer read', $fails[0]->message);
        $this->assertStringContainsString('has NO kanban user', $fails[0]->message);
        $this->assertStringContainsString('"'.self::HOST.'": 3', $fails[0]->message);
    }

    /**
     * Round-1 ruling 3: an agent that is NO seat must not be told to put itself into the roster —
     * its message points at the peer field (or at coord_seat, if it is a seat after all).
     */
    public function test_a_retired_yaml_id_on_a_non_seat_points_at_the_peer_field(): void
    {
        $this->roster(['impl' => 7]);

        $findings = $this->agentFindings($this->configs(['prod-agent' => ['kanban_user_id' => 3]]));

        $fails = array_values(array_filter($findings, fn (Finding $f): bool => $f->severity === Severity::Fail));
        $this->assertCount(1, $fails);
        $this->assertStringContainsString('is no seat of the coord roster', $fails[0]->message);
        $this->assertStringContainsString('move the id to identity.peer_kanban_user_id: 3', $fails[0]->message);
        $this->assertStringNotContainsString('"'.self::HOST.'": 3', $fails[0]->message, 'a non-seat is never told to write itself into the roster');
    }

    public function test_a_retired_yaml_id_equal_to_the_peer_id_warns_remove_it(): void
    {
        $this->roster(['impl' => 7]);

        $findings = $this->agentFindings($this->configs(['peer' => ['kanban_user_id' => 3, 'peer_kanban_user_id' => 3]]));

        $warns = array_values(array_filter($findings, fn (Finding $f): bool => $f->severity === Severity::Warn));
        $this->assertCount(1, $warns);
        $this->assertStringContainsString('the bridge reads its identity.peer_kanban_user_id, which is the same 3', $warns[0]->message);
    }

    public function test_an_agent_without_the_retired_key_draws_no_migration_line(): void
    {
        $this->roster(['impl' => 7]);

        $findings = $this->agentFindings($this->configs(['impl' => []]));

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Ok, $findings[0]->severity);
    }

    // ---- helpers ----

    /**
     * @param  array<string, int|array<string, mixed>|null>  $seats
     */
    private function roster(array $seats): string
    {
        return CoordRosterFixture::configure($this->dir, $seats);
    }

    /**
     * @param  array<string, array<string, mixed>>  $identities  agent name => identity block
     * @return list<AgentConfig>
     */
    private function configs(array $identities): array
    {
        $configs = [];
        foreach ($identities as $name => $identity) {
            $configs[] = AgentConfig::fromArray($name, ['identity' => $identity, 'subscriptions' => [['provider' => 'kanban', 'scopes' => [5]]]]);
        }

        return $configs;
    }

    /**
     * @param  array<string, array<string, mixed>>  $identities
     * @return list<Finding>
     */
    private function findings(array $identities): array
    {
        return $this->findingsOfConfigs($this->configs($identities));
    }

    /**
     * The findings after the leading "readable by this run's OS user" line a readable roster always opens with.
     *
     * @param  list<AgentConfig>  $configs
     * @return list<Finding>
     */
    private function agentFindings(array $configs): array
    {
        $findings = $this->findingsOfConfigs($configs);
        $this->assertStringContainsString("is readable by this run's OS user", $findings[0]->message);

        return array_slice($findings, 1);
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
