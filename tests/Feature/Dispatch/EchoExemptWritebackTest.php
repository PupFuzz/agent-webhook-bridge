<?php

namespace Tests\Feature\Dispatch;

use App\Bridge\Adapters\EventDto;
use App\Bridge\Classifiers\KanbanTriageClassifier;
use App\Bridge\Dispatch\DispatchService;
use App\Bridge\Dispatch\IntentLog;
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
use Tests\Fixtures\HandlerRecorder;
use Tests\Fixtures\LogIntentClassifier;
use Tests\Fixtures\ReattributingClassifier;
use Tests\Fixtures\RecordingDurableHandler;
use Tests\Fixtures\RecordingHandler;
use Tests\Fixtures\ThrowingClassifier;
use Tests\Fixtures\WritebackEmittingClassifier;
use Tests\TestCase;

/**
 * DL-203 (card #4386): the machine writeback survives echo. For a github
 * dispatch whose classifier is marked EmitsWritebackReactions, the echo /
 * signal / reattributed-author gates classify-then-STRIP (intents + non-
 * DurableReaction targets removed; machine targets proceed) instead of
 * dropping wholesale. Every other dispatch keeps the cheap drop byte-identical.
 */
class EchoExemptWritebackTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        ClassifierResolver::flush();
        HandlerRecorder::reset();
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
     */
    private function writeGithubAgent(
        string $name,
        string $classifierClass,
        ?int $githubUserId = null,
        array $treatAsEchoIds = [],
        array $treatAsSignal = [],
        bool $channel = false,
        bool $routeIntents = false,
    ): void {
        $yaml = '';
        if ($githubUserId !== null) {
            $yaml .= "identity:\n  github_user_id: {$githubUserId}\n";
        }
        $yaml .= "subscriptions:\n  - provider: github\n    scopes: [\"acme/widget\"]\n"
            ."classifier:\n  class: '".$classifierClass."'\n";
        if ($channel) {
            $yaml .= "channel:\n  url: http://127.0.0.1:8788/\n  route_intents: ".($routeIntents ? 'true' : 'false')."\n";
        }
        $yaml .= "echo_suppression:\n"
            .'  treat_as_echo_ids: ['.implode(', ', $treatAsEchoIds)."]\n"
            .'  treat_as_signal: ['.implode(', ', $treatAsSignal)."]\n";
        File::put($this->dir."/{$name}.yml", $yaml);
    }

    /**
     * @param  list<string>  $treatAsEchoIds
     * @param  list<int>  $scopes
     */
    private function writeKanbanAgent(string $name, string $classifierClass, array $treatAsEchoIds = [], array $scopes = [5], bool $channel = false): void
    {
        $yaml = "subscriptions:\n  - provider: kanban\n    scopes: [".implode(', ', $scopes)."]\n"
            ."classifier:\n  class: '".$classifierClass."'\n";
        if ($channel) {
            $yaml .= "channel:\n  url: http://127.0.0.1:8788/\n  route_intents: true\n";
        }
        $yaml .= "echo_suppression:\n  treat_as_echo_ids: [".implode(', ', $treatAsEchoIds)."]\n";
        File::put($this->dir."/{$name}.yml", $yaml);
    }

    private function dispatcher(?HandlerRegistry $handlers = null): DispatchService
    {
        $subs = new SubscriptionRegistry($this->dir);

        return new DispatchService(
            $subs,
            AgentRegistry::fromAgentConfigs($subs->agentConfigs(), AgentRegistry::loadSharedIdentities($this->dir)),
            $handlers ?? new HandlerRegistry,
            new IntentLog,
        );
    }

    private function githubDto(string $deliveryId = 'gh-1', ?string $actorId = '555'): EventDto
    {
        return new EventDto(deliveryId: $deliveryId, scopeId: 'acme/widget', eventType: 'issues.reopened', actorId: $actorId);
    }

    /**
     * @param  list<string>  $targets
     * @return array<mixed>
     */
    private function githubPayload(array $targets, ?string $reattributedTo = null, bool $throw = false): array
    {
        return array_filter([
            'subject_id' => 7,
            'targets' => $targets,
            'reattributed_to' => $reattributedTo,
            'throw' => $throw ?: null,
        ], static fn ($v) => $v !== null);
    }

    private function durableRegistry(string ...$names): HandlerRegistry
    {
        $registry = new HandlerRegistry;
        foreach ($names as $name) {
            $registry->register($name, new RecordingDurableHandler($name));
        }

        return $registry;
    }

    private function inboxCount(): int
    {
        $path = $this->dir.'/state/inbox.jsonl';

        return File::exists($path) ? count(file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : 0;
    }

    public function test_echo_strips_agent_surface_and_delivers_machine_targets(): void
    {
        // The matrix headline: intent + wake + two durable coord targets on the
        // agent's OWN write. Delivered; inbox empty; no push; BOTH durable
        // handlers ran; the DL-203 ledger marker set; error_message untouched.
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->writeGithubAgent('gh-agent', WritebackEmittingClassifier::class, treatAsEchoIds: ['555'], channel: true);

        $this->dispatcher($this->durableRegistry('dur1', 'dur2'))
            ->dispatch('github', 'acme/widget', $this->githubDto(), $this->githubPayload(['channel_push', 'dur1', 'dur2']));

        $d = AgentDispatch::firstOrFail();
        $this->assertSame(AgentDispatch::OUTCOME_DELIVERED, $d->outcome);
        $this->assertSame('echo: agent surface suppressed', $d->reason);
        $this->assertNull($d->error_message);
        $this->assertNotNull($d->processed_at);
        $this->assertSame(0, $this->inboxCount());          // intent stripped — nothing staged
        Http::assertNothingSent();                          // wake stripped — no channel push
        $calls = HandlerRecorder::$calls;
        sort($calls);
        $this->assertSame(['dur1', 'dur2'], $calls);        // machine targets both ran
    }

    public function test_route_intents_on_suppressed_dispatch_synthesizes_zero_pushes(): void
    {
        // route_intents pushes derive from $result->intents — the strip empties
        // it, so a route_intents:true channel gets ZERO routed pushes for a
        // suppressed dispatch. (Mutation check: skipping the intent strip makes
        // the routed push fire and reds this test.)
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->writeGithubAgent('gh-agent', WritebackEmittingClassifier::class, treatAsEchoIds: ['555'], channel: true, routeIntents: true);

        $this->dispatcher($this->durableRegistry('dur'))
            ->dispatch('github', 'acme/widget', $this->githubDto(), $this->githubPayload(['dur']));

        Http::assertNothingSent();
        $this->assertSame(0, $this->inboxCount());
        $this->assertSame(AgentDispatch::OUTCOME_DELIVERED, AgentDispatch::firstOrFail()->outcome);
        $this->assertSame(['dur'], HandlerRecorder::$calls);
    }

    public function test_non_writeback_classifier_echo_drop_is_byte_identical(): void
    {
        // Regression pin: a github dispatch whose classifier carries NO marker
        // keeps today's cheap pre-classify drop — exact reason, no classify
        // (ThrowingClassifier would flip the outcome to errored if reached).
        $this->writeGithubAgent('gh-agent', ThrowingClassifier::class, githubUserId: 555);

        $this->dispatcher()->dispatch('github', 'acme/widget', $this->githubDto(), ['subject_id' => 7]);

        $d = AgentDispatch::firstOrFail();
        $this->assertSame(AgentDispatch::OUTCOME_DROPPED, $d->outcome);
        $this->assertSame('echo: own write', $d->reason);
        $this->assertSame(0, $this->inboxCount());
    }

    public function test_non_writeback_classifier_signal_drop_is_byte_identical(): void
    {
        $this->writeKanbanAgent('peer-agent', LogIntentClassifier::class, scopes: [99]);
        $this->writeGithubAgent('gh-agent', ThrowingClassifier::class, treatAsSignal: ['peer-agent']);

        $this->dispatcher()->dispatch('github', 'acme/widget', $this->githubDto(actorId: '999'), ['subject_id' => 7]);

        $d = AgentDispatch::where('agent_name', 'gh-agent')->firstOrFail();
        $this->assertSame(AgentDispatch::OUTCOME_DROPPED, $d->outcome);
        $this->assertSame('actor is not a signal', $d->reason);
    }

    public function test_non_writeback_classifier_reattributed_drop_is_byte_identical(): void
    {
        // The DL-005 completion gate for an unmarked classifier still DROPS the
        // reattributed own write, exact reason preserved.
        $this->writeGithubAgent('gh-agent', ReattributingClassifier::class);

        $payload = ['subject_id' => 7, 'reattributed_to' => 'gh-agent'];
        $this->dispatcher()->dispatch('github', 'acme/widget', $this->githubDto(actorId: '999'), $payload);

        $d = AgentDispatch::firstOrFail();
        $this->assertSame(AgentDispatch::OUTCOME_DROPPED, $d->outcome);
        $this->assertSame('echo: own write (reattributed author)', $d->reason);
        $this->assertSame(0, $this->inboxCount());
    }

    public function test_seeded_github_user_id_no_longer_kills_the_writeback(): void
    {
        // The retired footgun (writeback half only): identity.github_user_id is
        // auto-seeded into the echo ids, so the seat's own PR/issue events are
        // pre-classify echo — the machine writeback must run anyway (card
        // created/moved), with no wake and no inbox row.
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->writeGithubAgent('gh-agent', WritebackEmittingClassifier::class, githubUserId: 555, channel: true);

        $this->dispatcher($this->durableRegistry('dur'))
            ->dispatch('github', 'acme/widget', $this->githubDto(), $this->githubPayload(['channel_push', 'dur']));

        $this->assertSame(['dur'], HandlerRecorder::$calls);   // the writeback ran
        Http::assertNothingSent();                             // no own-write wake
        $this->assertSame(0, $this->inboxCount());             // no own-write inbox row
        $d = AgentDispatch::firstOrFail();
        $this->assertSame(AgentDispatch::OUTCOME_DELIVERED, $d->outcome);
        $this->assertSame('echo: agent surface suppressed', $d->reason);
    }

    public function test_unresolvable_classifier_plus_echo_records_errored_not_the_gate_drop(): void
    {
        // The DL-203 ruling on the ONE measured non-qualifying behavior change:
        // the marker cannot be read without loading the class, so the resolver is
        // hoisted ABOVE the gates — an unresolvable FQCN + an echo actor now
        // records treatment-A (errored, replayable) where the gate previously
        // dropped it and masked the config error. Pinning it so a future
        // "optimization" that moves the resolver back below the gates cannot
        // silently revert the ruling with a green suite. Holds on BOTH providers
        // (the resolver runs ahead of the provider gate) — the kanban leg is the
        // one an earlier draft of DL-203 wrongly called byte-identical.
        $this->writeGithubAgent('gh-agent', 'App\\Bridge\\Classifiers\\NoSuchClassifier', githubUserId: 555);
        $this->writeKanbanAgent('kb-agent', 'App\\Bridge\\Classifiers\\NoSuchClassifier', treatAsEchoIds: ["'99'"]);

        $this->dispatcher()->dispatch('github', 'acme/widget', $this->githubDto(), ['subject_id' => 7]);
        $gh = AgentDispatch::where('agent_name', 'gh-agent')->firstOrFail();
        $this->assertSame(AgentDispatch::OUTCOME_ERRORED, $gh->outcome);
        $this->assertNull($gh->processed_at, 'an errored row stays replayable');
        $this->assertNull($gh->reason, 'errored carries error_message, not a gate reason');

        $this->dispatcher()->dispatch('kanban', '5', new EventDto(
            deliveryId: 'kb-1', scopeId: '5', eventType: 'task.created', actorId: '99',
        ), ['subject_id' => 42, 'board_id' => 5]);
        $kb = AgentDispatch::where('agent_name', 'kb-agent')->firstOrFail();
        $this->assertSame(AgentDispatch::OUTCOME_ERRORED, $kb->outcome, 'kanban is NOT shielded by the provider gate — the resolver runs first');
        $this->assertNull($kb->processed_at);
    }

    public function test_kanban_triage_marker_seat_keeps_the_global_echo_drop(): void
    {
        // The kanban-side loop shape, end-to-end: a marker-inheriting seat
        // (KanbanTriageClassifier extends CoordinationClassifier) sees the
        // writeback identity's own task.created echo and emits no wake, no inbox
        // row, reason preserved. NB this case is provider-gate-INDEPENDENT (the
        // classifier emits no durable target for this payload, so a strip would
        // also drop it under the same reason) — the gate itself is pinned by
        // test_kanban_provider_marker_classifier_never_classifies_on_echo, which
        // reds when the gate is removed. Kept as the composed no-loop assertion.
        Http::fake(['*' => Http::response('ok', 200)]);
        config(['bridge.global_echo_ids' => ['137']]);
        $this->writeKanbanAgent('triage', KanbanTriageClassifier::class, channel: true);

        $payload = ['subject_id' => 42, 'board_id' => 5, 'payload' => ['name' => 'Ship it'],
            'card' => ['tags' => [], 'external_references' => []]];
        $this->dispatcher()->dispatch('kanban', '5',
            new EventDto(deliveryId: 'evt-1', scopeId: '5', eventType: 'task.created', actorId: '137'), $payload);

        $d = AgentDispatch::firstOrFail();
        $this->assertSame(AgentDispatch::OUTCOME_DROPPED, $d->outcome);
        $this->assertSame('echo: own write', $d->reason);
        $this->assertSame(0, $this->inboxCount());
        Http::assertNothingSent();
    }

    public function test_kanban_provider_marker_classifier_never_classifies_on_echo(): void
    {
        // Pins the provider half of the strip gate: the SAME marker classifier
        // that classifies-then-strips on github keeps the cheap no-classify
        // drop on kanban — payload `throw` would flip the outcome to errored
        // if classify ran.
        $this->writeKanbanAgent('triage', WritebackEmittingClassifier::class, treatAsEchoIds: ['137']);

        $this->dispatcher()->dispatch('kanban', '5',
            new EventDto(deliveryId: 'evt-1', scopeId: '5', eventType: 'task.created', actorId: '137'),
            ['subject_id' => 7, 'throw' => true]);

        $d = AgentDispatch::firstOrFail();
        $this->assertSame(AgentDispatch::OUTCOME_DROPPED, $d->outcome);
        $this->assertSame('echo: own write', $d->reason);
    }

    public function test_non_signal_actor_machine_target_survives_on_writeback_classifier(): void
    {
        // The isSignal sibling (same defect one line down): a non-allowlisted
        // actor's dispatch on a marker classifier strips the surface but keeps
        // the machine writeback.
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->writeKanbanAgent('peer-agent', LogIntentClassifier::class, scopes: [99]);
        $this->writeGithubAgent('gh-agent', WritebackEmittingClassifier::class, treatAsSignal: ['peer-agent'], channel: true);

        $this->dispatcher($this->durableRegistry('dur'))
            ->dispatch('github', 'acme/widget', $this->githubDto(actorId: '999'), $this->githubPayload(['channel_push', 'dur']));

        $this->assertSame(['dur'], HandlerRecorder::$calls);
        Http::assertNothingSent();
        $this->assertSame(0, $this->inboxCount());
        $d = AgentDispatch::where('agent_name', 'gh-agent')->firstOrFail();
        $this->assertSame(AgentDispatch::OUTCOME_DELIVERED, $d->outcome);
        $this->assertSame('echo: agent surface suppressed', $d->reason);
    }

    public function test_suppressed_delivery_is_not_rerun_on_redelivery(): void
    {
        // delivered-with-suppression is a processed terminal — a redelivery
        // skips it like any delivered row (durable handlers not re-invoked).
        $this->writeGithubAgent('gh-agent', WritebackEmittingClassifier::class, treatAsEchoIds: ['555']);
        $registry = $this->durableRegistry('dur');

        $this->dispatcher($registry)->dispatch('github', 'acme/widget', $this->githubDto(), $this->githubPayload(['dur']));
        $this->assertSame(['dur'], HandlerRecorder::$calls);

        HandlerRecorder::reset();
        $this->dispatcher($registry)->dispatch('github', 'acme/widget', $this->githubDto(), $this->githubPayload(['dur']));

        $this->assertSame([], HandlerRecorder::$calls);        // skipped, not re-fired
        $this->assertSame(1, WebhookEvent::count());
        $this->assertSame('echo: agent surface suppressed', AgentDispatch::firstOrFail()->reason);
    }

    public function test_durable_throw_on_suppressed_path_propagates_then_redelivery_completes(): void
    {
        // Treatment B holds on the suppressed path: the durable throw → 5xx,
        // dispatch left unprocessed; the redelivery (handler recovered) completes.
        $this->writeGithubAgent('gh-agent', WritebackEmittingClassifier::class, treatAsEchoIds: ['555']);
        $throwing = new HandlerRegistry;
        $throwing->register('dur', new RecordingDurableHandler('dur', throw: true));

        try {
            $this->dispatcher($throwing)->dispatch('github', 'acme/widget', $this->githubDto(), $this->githubPayload(['dur']));
            $this->fail('expected the durable failure to propagate');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('dur failed', $e->getMessage());
        }
        $this->assertNull(AgentDispatch::firstOrFail()->processed_at);   // unprocessed → redelivered

        HandlerRecorder::reset();
        $this->dispatcher($this->durableRegistry('dur'))
            ->dispatch('github', 'acme/widget', $this->githubDto(), $this->githubPayload(['dur']));

        $d = AgentDispatch::firstOrFail();
        $this->assertSame(AgentDispatch::OUTCOME_DELIVERED, $d->outcome);
        $this->assertSame('echo: agent surface suppressed', $d->reason);
        $this->assertSame(['dur'], HandlerRecorder::$calls);
    }

    public function test_force_replay_of_pre_fix_reattributed_author_drop_delivers_machine_target(): void
    {
        // The historical backfill path (aimla's dead creates): a row dropped
        // pre-DL-203 at the reattributed-author gate, re-run after `bridge:replay
        // --force` reset its terminal tuple — the machine target now delivers.
        $this->writeGithubAgent('gh-agent', WritebackEmittingClassifier::class);
        $payload = $this->githubPayload(['dur'], reattributedTo: 'gh-agent');
        $event = WebhookEvent::create([
            'delivery_id' => 'gh-1', 'provider' => 'github', 'scope_id' => 'acme/widget',
            'event_type' => 'issues.reopened', 'actor_id' => '999', 'payload' => $payload,
        ]);
        // Post---force state: the whole terminal tuple nulled (DL-037).
        AgentDispatch::create([
            'webhook_event_id' => $event->id, 'agent_name' => 'gh-agent',
            'processed_at' => null, 'outcome' => null, 'reason' => null,
        ]);

        $this->dispatcher($this->durableRegistry('dur'))
            ->dispatch('github', 'acme/widget', $this->githubDto(actorId: '999'), $payload);

        $d = AgentDispatch::firstOrFail();
        $this->assertSame(AgentDispatch::OUTCOME_DELIVERED, $d->outcome);
        $this->assertSame('echo: agent surface suppressed', $d->reason);
        $this->assertSame(['dur'], HandlerRecorder::$calls);
        $this->assertSame(0, $this->inboxCount());
    }

    public function test_custom_registered_unmarked_handler_is_stripped_on_echo(): void
    {
        // Fail-closed partition: an operator-registered handler WITHOUT the
        // DurableReaction marker is agent-facing — suppressed on echo like the
        // shipped wake/spawn handlers (a classifier bug can't leak an own-write
        // side effect through an unmarked custom handler).
        $this->writeGithubAgent('gh-agent', WritebackEmittingClassifier::class, treatAsEchoIds: ['555']);
        $registry = $this->durableRegistry('dur');
        $registry->register('custom', new RecordingHandler('custom'));

        $this->dispatcher($registry)->dispatch('github', 'acme/widget', $this->githubDto(), $this->githubPayload(['custom', 'dur']));

        $this->assertSame(['dur'], HandlerRecorder::$calls);   // custom never ran
        $this->assertSame(AgentDispatch::OUTCOME_DELIVERED, AgentDispatch::firstOrFail()->outcome);
    }

    public function test_classify_throw_on_pre_known_echo_records_error(): void
    {
        // Ruled (DL-203): a classifier throw on a dispatch the echo gate had
        // already flagged is STILL treatment A — recorded + replayable, never
        // silently folded into the drop.
        $this->writeGithubAgent('gh-agent', WritebackEmittingClassifier::class, treatAsEchoIds: ['555']);

        $this->dispatcher()->dispatch('github', 'acme/widget', $this->githubDto(), $this->githubPayload([], throw: true));

        $d = AgentDispatch::firstOrFail();
        $this->assertSame(AgentDispatch::OUTCOME_ERRORED, $d->outcome);
        $this->assertNull($d->processed_at);
        $this->assertStringContainsString('writeback classifier boom', (string) $d->error_message);
    }

    public function test_strip_to_nothing_drops_with_the_original_gate_reason(): void
    {
        // The :227 empty-check pin: intents stripped to nothing + no machine
        // target ⇒ dropped under the ORIGINAL gate reason — never 'classifier
        // emitted no reactions' (the classifier DID emit; the gate ate it).
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->writeGithubAgent('gh-agent', WritebackEmittingClassifier::class, treatAsEchoIds: ['555'], channel: true);

        $this->dispatcher()->dispatch('github', 'acme/widget', $this->githubDto(), $this->githubPayload(['channel_push']));

        $d = AgentDispatch::firstOrFail();
        $this->assertSame(AgentDispatch::OUTCOME_DROPPED, $d->outcome);
        $this->assertSame('echo: own write', $d->reason);
        $this->assertSame(0, $this->inboxCount());
        Http::assertNothingSent();
    }

    public function test_non_gated_dispatch_on_writeback_classifier_is_untouched(): void
    {
        // Control: a signal actor's dispatch on a marker classifier keeps the
        // full agent surface — the strip fires only on a gate hit.
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->writeGithubAgent('gh-agent', WritebackEmittingClassifier::class, treatAsEchoIds: ['555'], channel: true);

        $this->dispatcher($this->durableRegistry('dur'))
            ->dispatch('github', 'acme/widget', $this->githubDto(actorId: '999'), $this->githubPayload(['channel_push', 'dur']));

        $d = AgentDispatch::firstOrFail();
        $this->assertSame(AgentDispatch::OUTCOME_DELIVERED, $d->outcome);
        $this->assertNull($d->reason);                          // no suppression marker
        $this->assertSame(1, $this->inboxCount());              // intent staged
        Http::assertSentCount(1);                               // wake fired
        $this->assertSame(['dur'], HandlerRecorder::$calls);
    }
}
