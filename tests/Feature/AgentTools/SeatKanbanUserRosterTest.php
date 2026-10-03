<?php

namespace Tests\Feature\AgentTools;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CallingSeatSeal;
use Tests\Support\CoordRosterFixture;
use Tests\TestCase;

/**
 * `board_take_card` and its start form read the calling seat's kanban user id from the COORD
 * ROSTER at `bridge.coord_config_path`, and from nowhere else (card#11172). Every case below goes
 * through the HTTP door, so the id the board receives is the one a seat would get.
 *
 * The agent's YAML carries a DIFFERENT `identity.kanban_user_id` throughout: a test that passed
 * by reading the YAML would write {@see YAML_ID}, so each assertion on the written id is also the
 * proof that the YAML is not read.
 */
class SeatKanbanUserRosterTest extends TestCase
{
    use RefreshDatabase;

    private const ROSTER_ID = 7001;

    private const YAML_ID = 9009;

    private const STAGES = [
        ['id' => 47, 'name' => 'Prioritized', 'position' => 1, 'lane_type' => 'backlog_inventory'],
        ['id' => 49, 'name' => 'In Progress', 'position' => 2, 'lane_type' => 'in_progress'],
        ['id' => 113, 'name' => 'Done', 'position' => 6, 'lane_type' => 'done'],
    ];

    private string $dir;

    private string $token = 'tools-bearer-roster';   // gitleaks:allow — test fixture

    /** @var list<array<string, mixed>> */
    private array $patches = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/tools-roster-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/kanban');
        File::put($this->dir.'/kanban/writeback-token', 'wb-token');   // gitleaks:allow — test fixture
        chmod($this->dir.'/kanban/writeback-token', 0o600);
        File::put($this->dir.'/me-tools-token', $this->token);
        chmod($this->dir.'/me-tools-token', 0o600);
        $this->writeAgent('me', 'kanban_user_id: '.self::YAML_ID);
        File::put($this->dir.'/writeback.json', (string) json_encode(['mappings' => ['o/mine' => [
            'board_id' => 10,
            'stages' => ['started' => 49, 'merged' => 113],
            'started_from_stages' => [47],
        ]]]));

        config([
            'bridge.config_dir' => $this->dir,
            'bridge.secret_dir' => $this->dir,
            'bridge.providers.kanban.api_base_url' => 'https://'.CoordRosterFixture::HOST.'/api/v3',
        ]);
        CoordRosterFixture::configure($this->dir.'/coord', ['me' => self::ROSTER_ID]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function writeAgent(string $name, string $identity, bool $boardTools = true): void
    {
        File::put($this->dir."/{$name}.yml", "identity:\n  {$identity}\nsubscriptions: []\n"
            .($boardTools ? "board_tools:\n  enabled: true\n  transport: http\n  auth:\n    token_path: {$this->dir}/me-tools-token\n"
            ."  board_id: 10\n  swimlane_id: 4\n  create_stage_id: 47\n" : ''));
    }

    private function board(): void
    {
        $card = [
            'id' => 42, 'board_id' => 10, 'swimlane_id' => 4, 'name' => 'queued for me',
            'tags' => [], 'assigned_user_id' => null, 'workflow_stage_id' => 47,
        ];

        Http::fake(function ($request) use (&$card) {
            $url = urldecode($request->url());
            if (str_contains($url, '/tasks/search.json')) {
                return Http::response(['data' => str_contains($url, 'archived=1') ? [] : [$card]]);
            }
            if (str_contains($url, '/boards/10/preload.json')) {
                return Http::response(['data' => ['workflows' => [['stages' => self::STAGES]]]]);
            }
            if ($request->method() === 'PATCH') {
                $this->patches[] = $request->data();
                $card = array_merge($card, $request->data());

                return Http::response(['data' => ['id' => 42]]);
            }

            return Http::response('unexpected '.$request->method().' '.$url, 500);
        });
    }

    /** @param array<string, mixed> $args */
    private function take(array $args = ['card_id' => 42]): TestResponse
    {
        CallingSeatSeal::forANewServingProcess();

        return $this->call('POST', '/agent-tools/call', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
        ], (string) json_encode(['tool' => 'board_take_card', 'args' => $args]));
    }

    public function test_a_take_writes_the_roster_id_and_not_the_differing_yaml_id(): void
    {
        $this->board();

        $this->take()->assertStatus(200)->assertJsonPath('result.assigned_user_id', self::ROSTER_ID);
        $this->assertSame([['assigned_user_id' => self::ROSTER_ID]], $this->patches);
    }

    public function test_a_start_writes_the_roster_id_and_not_the_differing_yaml_id(): void
    {
        $this->board();

        $this->take(['card_id' => 42, 'start' => true])->assertStatus(200);
        $this->assertSame([['workflow_stage_id' => 49, 'assigned_user_id' => self::ROSTER_ID]], $this->patches);
    }

    public function test_the_id_is_the_one_keyed_by_this_install_s_kanban_host(): void
    {
        CoordRosterFixture::configure($this->dir.'/coord', ['me' => [
            'other-kanban.example.org' => 5555,
            CoordRosterFixture::HOST => 6006,
        ]]);
        $this->board();

        $this->take()->assertStatus(200)->assertJsonPath('result.assigned_user_id', 6006);
        $this->assertSame([['assigned_user_id' => 6006]], $this->patches);
    }

    public function test_the_seat_is_identity_coord_seat_when_the_agent_declares_one(): void
    {
        $this->writeAgent('me', 'coord_seat: builder');
        CoordRosterFixture::configure($this->dir.'/coord', ['me' => 1111, 'builder' => 2222]);
        $this->board();

        $this->take()->assertStatus(200);
        $this->assertSame([['assigned_user_id' => 2222]], $this->patches);
    }

    /**
     * Every state in which the roster cannot name the caller's id refuses as its own named
     * install fault, naming the configured path where there is one, and sends NOTHING to the
     * board — never an unassigned move, never a YAML value in its place.
     *
     * @return array<string, array{\Closure(self): void, string, list<string>}>
     */
    public static function failClosedCases(): array
    {
        return [
            'setting unset' => [
                static fn (self $t) => config(['bridge.coord_config_path' => null]),
                'install_fault.coord_config_unset',
                ['BRIDGE_COORD_CONFIG_PATH', 'is not set'],
            ],
            'setting not an absolute path' => [
                static fn (self $t) => config(['bridge.coord_config_path' => 'coord/coordination.config.json']),
                'install_fault.coord_config_not_absolute',
                ['coord/coordination.config.json', 'not an absolute path'],
            ],
            'file absent' => [
                static fn (self $t) => config(['bridge.coord_config_path' => $t->dir.'/nowhere/coordination.config.json']),
                'install_fault.coord_config_unreadable',
                ['/nowhere/coordination.config.json'],
            ],
            'file is not JSON' => [
                static fn (self $t) => CoordRosterFixture::configureRaw($t->dir.'/coord', '{"roster": [ not json'),
                'install_fault.coord_config_malformed',
                ['/coord/coordination.config.json', 'not a JSON object'],
            ],
            'seat absent from the roster' => [
                static fn (self $t) => CoordRosterFixture::configure($t->dir.'/coord', ['somebody-else' => 4]),
                'install_fault.roster_seat_absent',
                ['/coord/coordination.config.json', "no seat named 'me'"],
            ],
            'seat carries no id for this host' => [
                static fn (self $t) => CoordRosterFixture::configure($t->dir.'/coord', ['me' => ['other-kanban.example.org' => 5555]]),
                'install_fault.no_kanban_user',
                ['/coord/coordination.config.json', CoordRosterFixture::HOST],
            ],
        ];
    }

    /**
     * @param  \Closure(self): void  $arrange
     * @param  list<string>  $mentions
     */
    #[DataProvider('failClosedCases')]
    public function test_each_fail_closed_case_is_its_own_install_fault_and_writes_nothing(\Closure $arrange, string $reason, array $mentions): void
    {
        $arrange($this);
        Http::fake();

        foreach ([['card_id' => 42], ['card_id' => 42, 'start' => true]] as $args) {
            $res = $this->take($args)->assertStatus(422)->assertJsonPath('reason', $reason);
            $error = (string) $res->json('error');
            $this->assertStringContainsString('INSTALL fault', $error);
            $this->assertStringContainsString('NOTHING WAS WRITTEN', $error);
            foreach ($mentions as $mention) {
                $this->assertStringContainsString($mention, $error);
            }
            $this->assertStringNotContainsString((string) self::YAML_ID, $error, 'the retired YAML value must not surface as if it were an answer');
        }
        Http::assertNothingSent();
    }

    /**
     * Two agents serving the SAME seat share its id, and that does not stop the take: the id
     * still names exactly one seat. Two DIFFERENT seats resolving to one id do — that id does not
     * say which seat is calling.
     */
    public function test_a_second_agent_on_the_same_seat_does_not_refuse_the_take(): void
    {
        $this->writeAgent('me-ci', 'coord_seat: me', boardTools: false);
        $this->board();

        $this->take()->assertStatus(200);
        $this->assertSame([['assigned_user_id' => self::ROSTER_ID]], $this->patches);
    }

    public function test_two_seats_resolving_to_one_id_refuse_the_take_as_shared(): void
    {
        $this->writeAgent('twin', 'coord_seat: twin', boardTools: false);
        CoordRosterFixture::configure($this->dir.'/coord', ['me' => self::ROSTER_ID, 'twin' => self::ROSTER_ID]);
        Http::fake();

        $this->take()->assertStatus(422)->assertJsonPath('reason', 'install_fault.shared_kanban_user');
        Http::assertNothingSent();
    }
}
