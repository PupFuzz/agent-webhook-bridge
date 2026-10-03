<?php

namespace Tests\Feature\Dispatch;

use App\Bridge\Adapters\EventDto;
use App\Bridge\Dispatch\DispatchService;
use App\Bridge\Dispatch\IntentLog;
use App\Bridge\Exceptions\CoordRosterUnreadableException;
use App\Bridge\Support\AgentRegistry;
use App\Bridge\Support\ClassifierResolver;
use App\Bridge\Support\HandlerRegistry;
use App\Bridge\Support\SubscriptionRegistry;
use App\Models\AgentDispatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\LogIntentClassifier;
use Tests\Support\CoordRosterFixture;
use Tests\TestCase;

/**
 * Kanban event attribution and self echo-suppression read each agent's kanban user id from the
 * COORD ROSTER (card#11172 / DL-450), never from `identity.kanban_user_id` — and when the roster
 * cannot be read, a kanban delivery that needs it is REFUSED (the exception propagates, the
 * receiver answers 5xx, kanban redelivers) rather than attributed to nobody: an agent's own write
 * attributed to nobody is not suppressed as its echo, so it wakes the agent that made it.
 *
 * Every agent below carries a DIFFERENT `identity.kanban_user_id` in its YAML, so a pass that came
 * from reading the YAML would be visible as the wrong id matching.
 */
class RosterAttributionTest extends TestCase
{
    use RefreshDatabase;

    private const ME = 7001;

    private const PEER = 7002;

    private const YAML_ME = 9001;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        ClassifierResolver::flush();
        $this->dir = sys_get_temp_dir().'/roster-attr-'.uniqid();
        File::ensureDirectoryExists($this->dir);
        config([
            'bridge.config_dir' => $this->dir,
            'bridge.providers.kanban.api_base_url' => 'https://'.CoordRosterFixture::HOST.'/api/v3',
        ]);
        $this->writeAgent('me', self::YAML_ME, treatAsEcho: ['peer']);
        $this->writeAgent('peer', 9002, subscribed: false);
        CoordRosterFixture::configure($this->dir.'/coord', ['me' => self::ME, 'peer' => self::PEER]);
    }

    protected function tearDown(): void
    {
        ClassifierResolver::flush();
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /**
     * @param  list<string>  $treatAsEcho
     */
    private function writeAgent(string $name, int $yamlKanbanId, array $treatAsEcho = [], bool $subscribed = true): void
    {
        File::put($this->dir."/{$name}.yml", "identity:\n  kanban_user_id: {$yamlKanbanId}\n  github_user_id: ".(500 + strlen($name))."\n"
            .($subscribed
                ? "subscriptions:\n  - provider: kanban\n    scopes: [5]\n  - provider: github\n    scopes: ['o/r']\n"
                : "subscriptions: []\n")
            ."classifier:\n  class: '".LogIntentClassifier::class."'\n"
            ."echo_suppression:\n  treat_as_echo: [".implode(', ', $treatAsEcho)."]\n");
    }

    private function dispatcher(): DispatchService
    {
        $subs = new SubscriptionRegistry($this->dir);

        return new DispatchService($subs, AgentRegistry::fromAgentConfigs($subs->agentConfigs(), AgentRegistry::loadSharedIdentities($this->dir)), new HandlerRegistry, new IntentLog);
    }

    private function kanbanEvent(string $actorId, string $delivery = 'evt-1'): void
    {
        $this->dispatcher()->dispatch('kanban', '5', new EventDto(deliveryId: $delivery, scopeId: '5', eventType: 'task.created', actorId: $actorId), ['subject_id' => 42, 'board_id' => 5, 'payload' => ['name' => 'x']]);
    }

    /** The gate reason `me`'s dispatch was dropped with, or null when it was not dropped. */
    private function outcome(string $agent = 'me'): ?string
    {
        $dispatch = AgentDispatch::where('agent_name', $agent)->firstOrFail();
        $this->assertNotNull($dispatch->processed_at);

        return $dispatch->outcome === AgentDispatch::OUTCOME_DROPPED ? $dispatch->reason : null;
    }

    public function test_the_agent_s_own_write_by_its_roster_id_is_dropped_as_an_echo(): void
    {
        $this->kanbanEvent((string) self::ME);

        $this->assertSame('echo: own write', $this->outcome());
    }

    /** The control for the test above, and the proof the YAML is not read: its id is nobody. */
    public function test_a_write_by_the_retired_yaml_id_is_not_the_agent_s_own(): void
    {
        $this->kanbanEvent((string) self::YAML_ME);

        $this->assertNull($this->outcome());
    }

    /** Attribution BY NAME: the peer's roster id resolves to `peer`, which `me` treats as echo. */
    public function test_a_peer_is_attributed_by_its_roster_id(): void
    {
        $this->kanbanEvent((string) self::PEER);

        $this->assertSame('echo: own write', $this->outcome());
        $registry = AgentRegistry::fromAgentConfigs((new SubscriptionRegistry($this->dir))->agentConfigs());
        $this->assertSame('peer', $registry->byKanbanUserId(self::PEER)?->name);
        $this->assertNull($registry->byKanbanUserId(9002), 'the peer\'s retired YAML id names nobody');
    }

    /**
     * Two agents on one seat share its id: neither is named for it (the collision exclusion), and
     * each still drops that user's writes as its OWN — the raw-id echo arm.
     */
    public function test_a_shared_roster_id_still_drops_each_agent_s_own_write(): void
    {
        $this->writeAgent('me-ci', 9003);
        File::put($this->dir.'/me-ci.yml', str_replace("identity:\n", "identity:\n  coord_seat: me\n", (string) File::get($this->dir.'/me-ci.yml')));

        $this->kanbanEvent((string) self::ME);

        $this->assertSame('echo: own write', $this->outcome());
        $this->assertSame('echo: own write', $this->outcome('me-ci'));
        $registry = AgentRegistry::fromAgentConfigs((new SubscriptionRegistry($this->dir))->agentConfigs());
        $this->assertNull($registry->byKanbanUserId(self::ME));
    }

    /**
     * Round-1 ruling 3: a cross-install peer — NO seat of this roster — keeps its attribution
     * through `identity.peer_kanban_user_id`, so a `treat_as_echo` naming it still drops its
     * writes. The roster stays the only source for a seat: a seat that declares the field is
     * attributed by its roster id alone.
     */
    public function test_a_peer_named_in_treat_as_echo_is_still_suppressed_through_its_peer_id(): void
    {
        $this->writeAgent('me', self::YAML_ME, treatAsEcho: ['peer', 'remote']);
        File::put($this->dir.'/remote.yml', "identity:\n  peer_kanban_user_id: 7100\nsubscriptions: []\n");
        File::put($this->dir.'/peer.yml', "identity:\n  peer_kanban_user_id: 9999\nsubscriptions: []\n");

        $this->kanbanEvent('7100');

        $this->assertSame('echo: own write', $this->outcome());
        $registry = AgentRegistry::fromAgentConfigs((new SubscriptionRegistry($this->dir))->agentConfigs());
        $this->assertSame('remote', $registry->byKanbanUserId(7100)?->name);
        $this->assertSame('peer', $registry->byKanbanUserId(self::PEER)?->name, 'a seat is attributed by its roster id');
        $this->assertNull($registry->byKanbanUserId(9999), 'a seat\'s peer field is never read');
    }

    /**
     * Round 2 S1(c): a peer id equal to a roster seat's id is dropped at runtime, not only failed
     * by the check — otherwise it collides on the kanban axis and the SEAT stops being attributed
     * by name (its treat_as_echo / treat_as_signal matches go with it).
     */
    public function test_a_peer_id_equal_to_a_seat_s_roster_id_is_dropped_at_runtime(): void
    {
        File::put($this->dir.'/remote.yml', "identity:\n  peer_kanban_user_id: ".self::PEER."\nsubscriptions: []\n");
        // A coord_seat is a claim to BE a seat, so a peer id beside one is not used either.
        File::put($this->dir.'/claims-a-seat.yml', "identity:\n  coord_seat: nowhere\n  peer_kanban_user_id: 7300\nsubscriptions: []\n");

        $registry = AgentRegistry::fromAgentConfigs((new SubscriptionRegistry($this->dir))->agentConfigs());

        $this->assertSame('peer', $registry->byKanbanUserId(self::PEER)?->name, 'the seat keeps its attribution');
        $this->assertNull($registry->kanbanUserIdOf('remote'), 'the duplicate peer id is never used');
        $this->assertNull($registry->kanbanUserIdOf('claims-a-seat'), 'a peer id beside a coord_seat is never used');

        $this->kanbanEvent((string) self::PEER);
        $this->assertSame('echo: own write', $this->outcome(), 'me still drops peer\'s write by name (treat_as_echo: [peer])');
    }

    /** @return array<string, array{\Closure(self): void}> */
    public static function unreadableRosters(): array
    {
        return [
            'setting unset' => [static fn (self $t) => config(['bridge.coord_config_path' => null])],
            'file absent' => [static fn (self $t) => config(['bridge.coord_config_path' => $t->dir.'/nowhere.json'])],
            'file not JSON' => [static fn (self $t) => CoordRosterFixture::configureRaw($t->dir.'/coord', 'nope')],
            'no kanban host' => [static fn (self $t) => config(['bridge.providers.kanban.api_base_url' => ''])],
        ];
    }

    /**
     * The chosen unreadable-roster behaviour (DL-450): the kanban delivery is REFUSED, and the
     * dispatch is left unprocessed so the redelivery runs it. Never attributed to nobody.
     *
     * @param  \Closure(self): void  $arrange
     */
    #[DataProvider('unreadableRosters')]
    public function test_a_kanban_event_on_an_unreadable_roster_is_refused_for_redelivery(\Closure $arrange): void
    {
        $arrange($this);

        try {
            $this->kanbanEvent((string) self::ME);
            $this->fail('a kanban event must not be dispatched on a roster that cannot be read');
        } catch (CoordRosterUnreadableException $e) {
            $this->assertStringContainsString('coord roster', $e->getMessage());
        }
        $this->assertNull(AgentDispatch::where('agent_name', 'me')->firstOrFail()->processed_at, 'left for the redelivery');
    }

    /** A github delivery never asks the kanban axis, so an unreadable roster does not touch it. */
    public function test_a_github_event_is_dispatched_on_an_unreadable_roster(): void
    {
        config(['bridge.coord_config_path' => null]);

        $this->dispatcher()->dispatch('github', 'o/r', new EventDto(deliveryId: 'gh-1', scopeId: 'o/r', eventType: 'issues.opened', actorId: '777'), ['issue' => ['number' => 1]]);

        $this->assertNull($this->outcome());
    }

    /**
     * A github sender whose numeric id equals an agent's KANBAN id is not that agent's echo: the
     * kanban id is matched against kanban events only (the registry's provider split, DL-002).
     */
    public function test_a_github_sender_with_the_agent_s_kanban_id_is_not_its_echo(): void
    {
        $this->dispatcher()->dispatch('github', 'o/r', new EventDto(deliveryId: 'gh-2', scopeId: 'o/r', eventType: 'issues.opened', actorId: (string) self::ME), ['issue' => ['number' => 1]]);

        $this->assertNull($this->outcome());
    }

    /** End to end: the receiver answers 5xx on a signed kanban webhook when the roster is unset. */
    public function test_the_receiver_answers_5xx_on_a_kanban_webhook_when_the_roster_is_unset(): void
    {
        File::ensureDirectoryExists($this->dir.'/kanban');
        File::put($this->dir.'/kanban/webhook-secret-scope-5', 'scope-5-secret');   // gitleaks:allow — test fixture
        chmod($this->dir.'/kanban/webhook-secret-scope-5', 0o600);
        config(['bridge.secret_dir' => $this->dir, 'bridge.coord_config_path' => null]);
        $body = (string) json_encode(['event' => 'task.created', 'board_id' => 5, 'delivery_id' => 'e2e-roster', 'user_id' => self::ME, 'payload' => ['name' => 'x']]);

        $status = $this->call('POST', '/webhooks/kanban?b=5', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_KANBAN_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, 'scope-5-secret'),
        ], $body)->getStatusCode();

        $this->assertGreaterThanOrEqual(500, $status);
    }
}
