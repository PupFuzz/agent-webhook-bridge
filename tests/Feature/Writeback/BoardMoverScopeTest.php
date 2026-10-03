<?php

namespace Tests\Feature\Writeback;

use App\Bridge\Contracts\DeclaresWriteOp;
use App\Bridge\Contracts\DurableReaction;
use App\Bridge\Contracts\Handler;
use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Handlers\KanbanDependabotCardHandler;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\HandlerRegistry;
use App\Bridge\Support\SubscriptionRegistry;
use App\Bridge\Writeback\BoardMoverScope;
use App\Bridge\Writeback\OwedWriteQueue;
use App\Bridge\Writeback\PinGuard;
use App\Bridge\Writeback\PrOutcome;
use App\Bridge\Writeback\WritebackAlertNotifier;
use App\Bridge\Writeback\WriteOp;
use App\Models\WebhookEvent;
use App\Models\WritebackOwedWrite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

/**
 * The runtime half of the board-mover `handler` / `op` keys (card#11223): `BoardMoverCatalogTest`
 * shows every site SPELLS them; this shows what they hold. A row written by a shared site carries
 * the handler that was running when it was written, and an owed write's rows carry the handler
 * that owes it and the kind of write that handler declares.
 */
class BoardMoverScopeTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private int $deliveries = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/bridge-11223-scope-'.uniqid();
        File::ensureDirectoryExists($this->dir);
        File::put($this->dir.'/prod-agent.yml', "subscriptions:\n  - provider: kanban\n    scopes: [5]\n");
        config(['bridge.config_dir' => $this->dir, 'bridge.secret_dir' => $this->dir, 'bridge.state_dir' => $this->dir.'/state']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_outside_any_scope_there_is_no_handler_and_no_declared_op(): void
    {
        $this->assertNull(BoardMoverScope::handler());
        $this->assertSame('undeclared', BoardMoverScope::op());
    }

    public function test_a_scope_sets_both_values_narrows_the_op_and_restores_on_return_and_on_a_throw(): void
    {
        $seen = BoardMoverScope::forHandler('kanban_move_card', WriteOp::Move, function (): array {
            $inner = BoardMoverScope::forOp(WriteOp::Write, fn (): array => [BoardMoverScope::handler(), BoardMoverScope::op()]);

            return [[BoardMoverScope::handler(), BoardMoverScope::op()], $inner, [BoardMoverScope::handler(), BoardMoverScope::op()]];
        });

        $this->assertSame([['kanban_move_card', 'move'], ['kanban_move_card', 'write'], ['kanban_move_card', 'move']], $seen);
        $this->assertNull(BoardMoverScope::handler());

        try {
            BoardMoverScope::forHandler('kanban_block_reason', WriteOp::Write, fn () => throw new RuntimeException('boom'));
        } catch (RuntimeException) {
        }
        $this->assertNull(BoardMoverScope::handler(), 'a throw out of the scope does not leave its handler behind');
        $this->assertSame('undeclared', BoardMoverScope::op());
    }

    public function test_a_shared_site_logs_the_handler_that_called_it_not_a_fixed_one(): void
    {
        Log::spy();
        $handlers = new HandlerRegistry;
        $handlers->register('probe_mover', $this->pinProbe(WriteOp::Move));
        $handlers->register('probe_labeler', $this->pinProbe(WriteOp::Label));
        $handlers->register('probe_undeclared', $this->pinProbe(null));

        foreach (['probe_mover', 'probe_labeler', 'probe_undeclared'] as $name) {
            $this->oweAndDrain($handlers, $name);
        }

        $this->assertSame([
            ['probe_mover', 'move'],
            ['probe_labeler', 'label'],
            ['probe_undeclared', 'undeclared'],
        ], $this->keysOf('pin_guard.pinned'));
    }

    public function test_an_owed_move_that_is_given_up_says_op_move(): void
    {
        Log::spy();
        $event = $this->event();
        WritebackOwedWrite::query()->create(['queued_at' => now()->subSeconds(OwedWriteQueue::MAX_AGE_S + 60)] + $this->row('kanban_move_card', 'card-9', $event));

        $this->assertSame(1, $this->queue(new HandlerRegistry)->expireAged(10));

        $this->assertSame([['kanban_move_card', 'move']], $this->keysOf('owed_write.gave_up'));
        $this->assertNull(BoardMoverScope::handler(), 'the watchdog leaves no scope behind');
    }

    public function test_an_owed_move_that_overflows_says_op_move(): void
    {
        Log::spy();
        $subject = OwedWriteQueue::subjectKey('kanban', '5', 'kanban_move_card', 'card-9');
        for ($i = 0; $i < OwedWriteQueue::MAX_QUEUE_PER_SUBJECT; $i++) {
            WritebackOwedWrite::query()->create($this->row('kanban_move_card', 'card-9', $this->event()));
        }

        $this->queue(new HandlerRegistry)->enqueue($subject, ReactionTarget::make('kanban_move_card', 'card-9', payload: []), $this->agent(), $this->event());

        $this->assertSame([['kanban_move_card', 'move']], $this->keysOf('owed_write.overflow_gave_up'));
    }

    public function test_every_shipped_durable_handler_declares_its_write(): void
    {
        $registry = new HandlerRegistry(spawnDetachedEnabled: true);
        $durable = 0;
        foreach ($registry->known() as $name) {
            $handler = $registry->resolve($name);
            if ($handler instanceof DurableReaction) {
                $durable++;
                $this->assertInstanceOf(DeclaresWriteOp::class, $handler, "{$name} is durable and does not declare its write, so its owed-write rows would say `undeclared`");
            }
        }
        $this->assertGreaterThan(0, $durable, 'the census found no durable handler at all');
    }

    public function test_a_dependabot_target_declares_write_for_an_archive_or_a_rename_and_move_otherwise(): void
    {
        $handler = new KanbanDependabotCardHandler;
        $op = fn (string $outcome): WriteOp => $handler->writeOp(ReactionTarget::make('kanban_dependabot_card', 'pr-1', payload: ['outcome' => $outcome]));

        $this->assertSame(WriteOp::Write, $op(PrOutcome::CLOSED_UNMERGED));
        $this->assertSame(WriteOp::Write, $op(KanbanDependabotCardHandler::RENAMED_OUTCOME));
        $this->assertSame(WriteOp::Move, $op('merged'));
        $this->assertSame(WriteOp::Move, $op('opened'));
    }

    /** A durable handler that refuses a pinned card through the shared guard, declaring $op (or nothing). */
    private function pinProbe(?WriteOp $op): Handler
    {
        $refuse = static fn (): bool => PinGuard::refuses(new WritebackAlertNotifier, ['id' => 3, 'block_reason' => 'held', 'tags' => []], 'probe', 'move', 3, 'o/r', 'merged');

        return $op === null
            ? new class($refuse) implements DurableReaction, Handler
            {
                public function __construct(private readonly \Closure $refuse) {}

                public function handle(ReactionTarget $target, AgentConfig $agent): void
                {
                    ($this->refuse)();
                }
            }
        : new class($refuse, $op) implements DeclaresWriteOp, DurableReaction, Handler
        {
            public function __construct(private readonly \Closure $refuse, private readonly WriteOp $op) {}

            public function writeOp(ReactionTarget $target): WriteOp
            {
                return $this->op;
            }

            public function handle(ReactionTarget $target, AgentConfig $agent): void
            {
                ($this->refuse)();
            }
        };
    }

    /**
     * The `[handler, op]` of every warning logged with $catalogId, in order.
     *
     * @return list<array{0: mixed, 1: mixed}>
     */
    private function keysOf(string $catalogId): array
    {
        $seen = [];
        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) use ($catalogId, &$seen): bool {
            if (($context['catalog_id'] ?? null) === $catalogId) {
                $seen[] = [$context['handler'] ?? '(absent)', $context['op'] ?? '(absent)'];
            }

            return true;
        });

        return $seen;
    }

    private function oweAndDrain(HandlerRegistry $handlers, string $name): void
    {
        $subject = OwedWriteQueue::subjectKey('kanban', '5', $name, 'card-3');
        $queue = $this->queue($handlers);
        $queue->enqueue($subject, ReactionTarget::make($name, 'card-3', payload: []), $this->agent(), $this->event());
        $queue->drain($subject);
    }

    private function queue(HandlerRegistry $handlers): OwedWriteQueue
    {
        return new OwedWriteQueue($handlers, new SubscriptionRegistry($this->dir));
    }

    private function agent(): AgentConfig
    {
        return AgentConfig::load('prod-agent', $this->dir);
    }

    private function event(): WebhookEvent
    {
        return WebhookEvent::query()->create([
            'delivery_id' => 'scope-'.(++$this->deliveries),
            'provider' => 'kanban', 'scope_id' => '5', 'event_type' => 'task.moved', 'payload' => [],
        ]);
    }

    /** @return array<string, mixed> */
    private function row(string $handler, string $targetId, WebhookEvent $event): array
    {
        return [
            'subject_key' => OwedWriteQueue::subjectKey('kanban', '5', $handler, $targetId),
            'provider' => 'kanban', 'scope_id' => '5', 'handler' => $handler,
            'debounce_key' => $targetId, 'target_id' => $targetId, 'agent_name' => 'prod-agent',
            'payload' => ['repo' => 'o/r'], 'webhook_event_id' => (int) $event->id,
            'queued_at' => now(), 'attempts' => 0,
        ];
    }
}
