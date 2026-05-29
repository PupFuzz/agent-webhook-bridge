<?php

namespace Tests\Feature\Dispatch;

use App\Bridge\Adapters\EventDto;
use App\Bridge\Dispatch\DispatchService;
use App\Bridge\Dispatch\Intent;
use App\Bridge\Dispatch\IntentLog;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\AgentRegistry;
use App\Bridge\Support\ClassifierResolver;
use App\Bridge\Support\HandlerRegistry;
use App\Bridge\Support\SubscriptionRegistry;
use App\Models\AgentDispatch;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\Fixtures\LogIntentClassifier;
use Tests\Fixtures\ThrowingClassifier;
use Tests\Fixtures\UnknownHandlerClassifier;
use Tests\TestCase;

class DispatchServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        ClassifierResolver::flush();
        $this->dir = sys_get_temp_dir().'/dispatch-'.uniqid();
        File::ensureDirectoryExists($this->dir);
        config(['bridge.config_dir' => $this->dir]);
        File::put($this->dir.'/agents.json', (string) json_encode(['agents' => [['name' => 'prod-agent', 'kanban_user_id' => 137]]]));
    }

    protected function tearDown(): void
    {
        ClassifierResolver::flush();
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /**
     * @param  list<string>  $treatAsEchoIds
     * @param  list<string>  $treatAsSignal
     */
    private function writeAgent(string $name, string $classifierClass, array $treatAsEchoIds = [], array $treatAsSignal = []): void
    {
        $yaml = "identity:\n  self: {$name}\n"
            ."api:\n  kanban:\n    base_url: https://k.example.com\n    token_path: /t\n"
            ."receiver:\n  base_url: https://b.example.com/webhooks\n"
            ."subscriptions:\n  - provider: kanban\n    scopes: [5]\n"
            // Single-quoted YAML treats backslashes literally, so the FQCN's
            // single backslashes are written as-is (no escaping).
            ."classifier:\n  class: '".$classifierClass."'\n"
            .'echo_suppression:'."\n";
        $yaml .= '  treat_as_echo_ids: ['.implode(', ', $treatAsEchoIds)."]\n";
        $yaml .= '  treat_as_signal: ['.implode(', ', $treatAsSignal)."]\n";
        File::put($this->dir."/{$name}.yml", $yaml);
    }

    private function dispatcher(?IntentLog $intentLog = null): DispatchService
    {
        return new DispatchService(
            new SubscriptionRegistry($this->dir),
            AgentRegistry::load($this->dir.'/agents.json'),
            new HandlerRegistry,
            $intentLog ?? new IntentLog,
        );
    }

    private function dto(string $deliveryId = 'evt-1', ?string $actorId = '999'): EventDto
    {
        return new EventDto(deliveryId: $deliveryId, scopeId: '5', eventType: 'task.created', actorId: $actorId);
    }

    /**
     * @return array<mixed>
     */
    private function payload(): array
    {
        return ['subject_id' => 42, 'board_id' => 5, 'payload' => ['name' => 'Ship it']];
    }

    private function inboxCount(): int
    {
        $path = $this->dir.'/state/inbox.jsonl';

        return File::exists($path) ? count(file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : 0;
    }

    public function test_happy_path_stores_event_stages_intent_runs_handler(): void
    {
        $this->writeAgent('prod-agent', LogIntentClassifier::class);

        $this->dispatcher()->dispatch('kanban', '5', $this->dto(), $this->payload());

        $this->assertDatabaseHas('webhook_events', ['delivery_id' => 'evt-1', 'event_type' => 'task.created']);
        $dispatch = AgentDispatch::firstOrFail();
        $this->assertNotNull($dispatch->processed_at);
        $this->assertNull($dispatch->error_message);
        $this->assertSame(1, $this->inboxCount());                                  // intent staged
        $this->assertFileExists($this->dir.'/state/handler-log.jsonl');             // log_intent handler ran
    }

    public function test_echo_actor_is_skipped_without_classifying(): void
    {
        // ThrowingClassifier would blow up if reached — echo must short-circuit first.
        $this->writeAgent('prod-agent', ThrowingClassifier::class, treatAsEchoIds: ['137']);

        $this->dispatcher()->dispatch('kanban', '5', $this->dto(actorId: '137'), $this->payload());

        $dispatch = AgentDispatch::firstOrFail();
        $this->assertNotNull($dispatch->processed_at);
        $this->assertNull($dispatch->error_message);
        $this->assertSame(0, $this->inboxCount());
    }

    public function test_signal_allowlist_filters_non_signal_actor(): void
    {
        $this->writeAgent('prod-agent', ThrowingClassifier::class, treatAsSignal: ['some-other-agent']);

        // actor 137 resolves to prod-agent (not in the allowlist) → filtered, done, no classify.
        $this->dispatcher()->dispatch('kanban', '5', $this->dto(actorId: '137'), $this->payload());

        $dispatch = AgentDispatch::firstOrFail();
        $this->assertNotNull($dispatch->processed_at);
        $this->assertNull($dispatch->error_message);
        $this->assertSame(0, $this->inboxCount());
    }

    public function test_classifier_failure_is_recorded_and_left_errored_case_a(): void
    {
        $this->writeAgent('prod-agent', ThrowingClassifier::class);

        // Must NOT throw out (no 5xx) — case A records + acks.
        $this->dispatcher()->dispatch('kanban', '5', $this->dto(), $this->payload());

        $dispatch = AgentDispatch::firstOrFail();
        $this->assertNull($dispatch->processed_at);                 // errored → replayable
        $this->assertStringContainsString('classifier boom', (string) $dispatch->error_message);
        $this->assertSame(0, $this->inboxCount());
    }

    public function test_handler_failure_marks_done_with_note_case_c(): void
    {
        $this->writeAgent('prod-agent', UnknownHandlerClassifier::class);

        // Must NOT throw out — case C records a note but marks done (intent durable).
        $this->dispatcher()->dispatch('kanban', '5', $this->dto(), $this->payload());

        $dispatch = AgentDispatch::firstOrFail();
        $this->assertNotNull($dispatch->processed_at);              // done
        $this->assertStringContainsString('does_not_exist', (string) $dispatch->error_message);   // note preserved
        $this->assertSame(1, $this->inboxCount());                 // intent staged before handler ran (B before C)
    }

    public function test_intent_staging_failure_propagates_case_b(): void
    {
        $this->writeAgent('prod-agent', LogIntentClassifier::class);

        $throwingLog = new class extends IntentLog
        {
            public function stage(AgentConfig $agent, WebhookEvent $event, Intent $intent, int $index): void
            {
                throw new RuntimeException('disk full');
            }
        };

        // Durability failure must propagate (→ 5xx), NOT be swallowed.
        $this->expectException(RuntimeException::class);
        $this->dispatcher($throwingLog)->dispatch('kanban', '5', $this->dto(), $this->payload());
    }

    public function test_mid_loop_failure_leaves_earlier_agents_processed_no_transaction(): void
    {
        $this->writeAgent('agent-a', LogIntentClassifier::class);
        $this->writeAgent('agent-b', LogIntentClassifier::class);
        File::put($this->dir.'/agents.json', (string) json_encode(['agents' => [
            ['name' => 'agent-a', 'kanban_user_id' => 1],
            ['name' => 'agent-b', 'kanban_user_id' => 2],
        ]]));

        $failOnB = new class extends IntentLog
        {
            public function stage(AgentConfig $agent, WebhookEvent $event, Intent $intent, int $index): void
            {
                if ($agent->agentName === 'agent-b') {
                    throw new RuntimeException('boom on b');
                }
                parent::stage($agent, $event, $intent, $index);
            }
        };

        try {
            $this->dispatcher($failOnB)->dispatch('kanban', '5', $this->dto(), $this->payload());
            $this->fail('expected the agent-b failure to propagate');
        } catch (RuntimeException) {
            // expected
        }

        // No surrounding transaction: agent-a's processed_at must have persisted
        // even though agent-b threw — so a redelivery resumes from agent-b.
        $this->assertNotNull(AgentDispatch::where('agent_name', 'agent-a')->firstOrFail()->processed_at);
        $this->assertNull(AgentDispatch::where('agent_name', 'agent-b')->firstOrFail()->processed_at);
    }

    public function test_already_processed_dispatch_is_skipped_on_redelivery(): void
    {
        $this->writeAgent('prod-agent', ThrowingClassifier::class);

        $event = WebhookEvent::create([
            'delivery_id' => 'evt-1', 'provider' => 'kanban', 'scope_id' => '5',
            'event_type' => 'task.created', 'actor_id' => '999', 'payload' => $this->payload(),
        ]);
        $already = AgentDispatch::create([
            'webhook_event_id' => $event->id, 'agent_name' => 'prod-agent', 'processed_at' => now(),
        ]);

        // Redelivery: ThrowingClassifier must NOT run (would error) — the row is skipped.
        $this->dispatcher()->dispatch('kanban', '5', $this->dto(), $this->payload());

        $this->assertNull($already->fresh()->error_message);
        $this->assertSame(1, WebhookEvent::count());   // deduped, no second event row
    }
}
