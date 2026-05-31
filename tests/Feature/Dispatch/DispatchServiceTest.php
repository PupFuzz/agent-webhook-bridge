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
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Fixtures\CoalescingTargetsClassifier;
use Tests\Fixtures\LogIntentClassifier;
use Tests\Fixtures\ReattributingClassifier;
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
     * @param  list<int>  $scopes
     */
    private function writeAgent(string $name, string $classifierClass, array $treatAsEchoIds = [], array $treatAsSignal = [], ?int $kanbanUserId = null, array $scopes = [5]): void
    {
        $yaml = '';
        if ($kanbanUserId !== null) {
            $yaml .= "identity:\n  kanban_user_id: {$kanbanUserId}\n";
        }
        $yaml .= 'subscriptions:'."\n  - provider: kanban\n    scopes: [".implode(', ', $scopes)."]\n"
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
        $subs = new SubscriptionRegistry($this->dir);

        return new DispatchService(
            $subs,
            AgentRegistry::fromAgentConfigs($subs->agentConfigs(), AgentRegistry::loadSharedIdentities($this->dir)),
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
        // peer-agent exists so the allowlist name is valid (unknown names are
        // fail-closed) but subscribes to a different scope, so only prod-agent
        // dispatches here. actor 137 → prod-agent (its kanban_user_id), not in
        // the [peer-agent] allowlist → filtered, done, no classify.
        $this->writeAgent('peer-agent', LogIntentClassifier::class, scopes: [99]);
        $this->writeAgent('prod-agent', ThrowingClassifier::class, treatAsSignal: ['peer-agent'], kanbanUserId: 137);

        $this->dispatcher()->dispatch('kanban', '5', $this->dto(actorId: '137'), $this->payload());

        $dispatch = AgentDispatch::where('agent_name', 'prod-agent')->firstOrFail();
        $this->assertNotNull($dispatch->processed_at);
        $this->assertNull($dispatch->error_message);
        $this->assertSame(0, $this->inboxCount());
    }

    public function test_reattributed_self_author_is_echo_suppressed_after_classify(): void
    {
        // Shared-identity case (DL-005): the raw actor (999) is unattributable
        // pre-classify (name null), so it passes the pre-classify echo gate and
        // reaches classify. The classifier recovers the true author as
        // prod-agent (this agent's own identity.self) → the post-classify echo
        // recheck suppresses it: done, no error, nothing staged.
        $this->writeAgent('prod-agent', ReattributingClassifier::class);

        $payload = ['subject_id' => 42, 'board_id' => 5, 'reattributed_to' => 'prod-agent', 'payload' => ['name' => 'Ship it']];
        $this->dispatcher()->dispatch('kanban', '5', $this->dto(), $payload);

        $dispatch = AgentDispatch::firstOrFail();
        $this->assertNotNull($dispatch->processed_at);
        $this->assertNull($dispatch->error_message);
        $this->assertSame(0, $this->inboxCount());      // own write suppressed
    }

    public function test_reattributed_other_shared_agent_author_still_surfaces(): void
    {
        // Same shared account, but the recovered author is a DIFFERENT agent
        // (peer-agent ≠ this agent's identity.self) → not a self-echo → the
        // intent surfaces. This is the middle ground the all-or-nothing
        // treat_as_echo_ids could not express.
        $this->writeAgent('prod-agent', ReattributingClassifier::class);

        $payload = ['subject_id' => 42, 'board_id' => 5, 'reattributed_to' => 'peer-agent', 'payload' => ['name' => 'Ship it']];
        $this->dispatcher()->dispatch('kanban', '5', $this->dto(), $payload);

        $dispatch = AgentDispatch::firstOrFail();
        $this->assertNotNull($dispatch->processed_at);
        $this->assertNull($dispatch->error_message);
        $this->assertSame(1, $this->inboxCount());      // a peer's write is signal
    }

    public function test_null_reattributed_actor_keys_only_on_reattribution_not_intent_actor(): void
    {
        // The echo recheck keys on ClassifyResult::reattributedActor ONLY. Here
        // the classifier leaves it null but stamps the emitted intent's OWN
        // actor name as prod-agent (this agent's identity.self). The intent must
        // STILL surface — the dispatcher never inspects intent.actor for echo,
        // and the raw actor (999) already cleared the pre-classify gate. Pins
        // the `reattributedActor !== null` guard as load-bearing.
        $this->writeAgent('prod-agent', ReattributingClassifier::class);

        $payload = ['subject_id' => 42, 'board_id' => 5, 'intent_author' => 'prod-agent', 'payload' => ['name' => 'Ship it']];
        $this->dispatcher()->dispatch('kanban', '5', $this->dto(), $payload);

        $dispatch = AgentDispatch::firstOrFail();
        $this->assertNotNull($dispatch->processed_at);
        $this->assertNull($dispatch->error_message);
        $this->assertSame(1, $this->inboxCount());      // null reattribution → no suppression
    }

    public function test_unresolvable_classifier_fqcn_is_treatment_a_not_5xx(): void
    {
        // A bad classifier.class FQCN is a deterministic config error. The
        // resolver throw is caught like any classify error (it's evaluated
        // inside the classify try) → recorded + acked, NOT propagated to a 5xx
        // that would wedge delivery into the upstream retry storm. Locks this
        // behavior so a future refactor can't move the resolver call out of the
        // try and silently regress it.
        $this->writeAgent('prod-agent', 'App\\Bridge\\Classifiers\\NoSuchClassifier');

        // Must NOT throw out of dispatch().
        $this->dispatcher()->dispatch('kanban', '5', $this->dto(), $this->payload());

        $dispatch = AgentDispatch::firstOrFail();
        $this->assertNull($dispatch->processed_at);                            // errored → replayable
        $this->assertStringContainsString('NoSuchClassifier', (string) $dispatch->error_message);
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
        $this->writeAgent('agent-a', LogIntentClassifier::class, kanbanUserId: 1);
        $this->writeAgent('agent-b', LogIntentClassifier::class, kanbanUserId: 2);

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

    public function test_same_event_targets_coalesce_by_debounce_key_last_wins(): void
    {
        $this->writeAgent('prod-agent', CoalescingTargetsClassifier::class);

        $this->dispatcher()->dispatch('kanban', '5', $this->dto(), $this->payload());

        $lines = array_map(
            fn ($l) => json_decode($l, true),
            file($this->dir.'/state/handler-log.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
        );
        // bucket-x (targets A,B) collapses to one (last-wins = B); bucket-y (C)
        // fires on its own → two handler invocations, not three.
        $this->assertCount(2, $lines);
        $byKey = [];
        foreach ($lines as $l) {
            $byKey[$l['debounce_key']] = $l['target_id'];
        }
        $this->assertSame('B', $byKey['bucket-x']);   // last-wins within the shared bucket
        $this->assertSame('C', $byKey['bucket-y']);
    }

    public function test_channel_route_intents_pushes_each_staged_intent(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        // Agent with channel.url + route_intents → the dispatcher auto-pushes
        // each staged intent to the channel (no classifier channel_push). Uses a
        // plain inbox classifier (LogIntentClassifier) — the routing is what
        // produces the channel_push.
        File::put($this->dir.'/prod-agent.yml',
            "subscriptions:\n  - provider: kanban\n    scopes: [5]\n"
            ."classifier:\n  class: '".LogIntentClassifier::class."'\n"
            ."channel:\n  url: http://127.0.0.1:8788/\n  route_intents: true\n");

        $this->dispatcher()->dispatch('kanban', '5', $this->dto(), $this->payload());

        // Routed channel_push reached the configured channel with the intent.
        Http::assertSent(fn ($r) => $r->url() === 'http://127.0.0.1:8788/'
            && ($r->data()['intent']['subject_id'] ?? null) === '42');
        // The intent is still durably staged (channel push is layered on top).
        $this->assertSame(1, $this->inboxCount());
        $this->assertNull(AgentDispatch::firstOrFail()->error_message);
    }

    public function test_route_intents_attaches_bearer_token_and_never_persists_it(): void
    {
        Http::fake(['*' => Http::response('ok', 202)]);

        $tokenValue = 'tok-'.bin2hex(random_bytes(8));
        $tokenFile = $this->dir.'/channel-token';
        File::put($tokenFile, $tokenValue);
        chmod($tokenFile, 0o600);

        File::put($this->dir.'/prod-agent.yml',
            "subscriptions:\n  - provider: kanban\n    scopes: [5]\n"
            ."classifier:\n  class: '".LogIntentClassifier::class."'\n"
            ."channel:\n  url: http://127.0.0.1:8788/\n  route_intents: true\n"
            ."  auth:\n    token_path: {$tokenFile}\n");

        $this->dispatcher()->dispatch('kanban', '5', $this->dto(), $this->payload());

        // The token rides the Authorization header on the routed push...
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', "Bearer {$tokenValue}"));

        // ...but never lands in any serializable/logged structure (DL-008 invariant).
        $inbox = File::get($this->dir.'/state/inbox.jsonl');
        $this->assertStringNotContainsString($tokenValue, $inbox, 'token leaked into inbox.jsonl');
        $ledger = AgentDispatch::all()->toJson();
        $this->assertStringNotContainsString($tokenValue, $ledger, 'token leaked into agent_dispatches');
    }

    public function test_shared_github_id_is_not_pre_suppressed_so_dl005_can_reattribute(): void
    {
        // pm shares a github account (declared in shared-identities.json). pm's
        // own github_user_id is auto-seeded as a self echo-id — but because it's
        // SHARED, the pre-classify gate must NOT suppress it; the event must reach
        // classify so DL-005 re-attribution can decide per agent. (Regression for
        // DL-007: auto-seed must not re-introduce the all-or-nothing shared-inbox
        // suppression DL-005 removed.)
        File::put($this->dir.'/pm.yml', "identity:\n  github_user_id: 41000042\n"
            ."classifier:\n  class: '".LogIntentClassifier::class."'\n"
            ."subscriptions:\n  - provider: github\n    scopes: [acme/widget]\n");
        File::put($this->dir.'/shared-identities.json', (string) json_encode([
            'shared_identities' => [['github_user_id' => 41000042, 'agents' => ['pm']]],
        ]));

        $dto = new EventDto(deliveryId: 'gh-1', scopeId: 'acme/widget', eventType: 'pull_request.opened', actorId: '41000042');
        $this->dispatcher()->dispatch('github', 'acme/widget', $dto, ['subject_id' => 'pr-1']);

        // Reached classify (intent staged) — not pre-suppressed by the shared self id.
        $this->assertSame(1, $this->inboxCount());
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
