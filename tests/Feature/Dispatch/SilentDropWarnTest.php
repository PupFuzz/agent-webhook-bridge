<?php

namespace Tests\Feature\Dispatch;

use App\Bridge\Adapters\EventDto;
use App\Bridge\Classifiers\CoordinationClassifier;
use App\Bridge\Classifiers\EventDrivenClassifier;
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
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\Fixtures\HandlerRecorder;
use Tests\Fixtures\RecordingDurableHandler;
use Tests\Fixtures\UnpairedChannelPushClassifier;
use Tests\Fixtures\WritebackEmittingClassifier;
use Tests\TestCase;

/**
 * DL-278 (card #6025 / the ratified card #2014 AC): the dispatcher WARNS when a
 * classifier emits a `channel_push` ReactionTarget whose targetId pairs with no
 * Intent subject_id in the same ClassifyResult — the wake fires with no durable
 * inbox backstop behind it. Warn only: no throw, no dispatch-outcome change.
 *
 * Every count assertion filters on `has no paired Intent`, never on total
 * `warning` calls: the same pass legitimately logs `bridge dispatch: handler
 * failed` when ChannelPushHandler cannot resolve a transport.
 */
class SilentDropWarnTest extends TestCase
{
    use RefreshDatabase;

    private const WARN = 'has no paired Intent';

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
     * @param  list<int>  $scopes
     */
    private function writeAgent(string $name, string $classifier, string $extra = '', array $scopes = [5]): void
    {
        File::put($this->dir."/{$name}.yml",
            "subscriptions:\n  - provider: kanban\n    scopes: [".implode(', ', $scopes)."]\n"
            // Single-quoted YAML treats backslashes literally — the FQCN's
            // single backslashes are written as-is (no escaping).
            ."classifier:\n  class: '".$classifier."'\n"
            .$extra);
    }

    private const CHANNEL = "channel:\n  url: http://127.0.0.1:8788/\n";

    private function dispatcher(?IntentLog $intentLog = null, ?HandlerRegistry $handlers = null): DispatchService
    {
        $subs = new SubscriptionRegistry($this->dir);

        return new DispatchService(
            $subs,
            AgentRegistry::fromAgentConfigs($subs->agentConfigs(), AgentRegistry::loadSharedIdentities($this->dir)),
            $handlers ?? new HandlerRegistry,
            $intentLog ?? new IntentLog,
        );
    }

    private function dto(string $deliveryId = 'evt-1', ?string $actorId = '999'): EventDto
    {
        return new EventDto(deliveryId: $deliveryId, scopeId: '5', eventType: 'task.created', actorId: $actorId);
    }

    /**
     * @param  list<array<string, string>>  $pushes
     * @return array<mixed>
     */
    private function payload(array $pushes = []): array
    {
        return array_filter([
            'subject_id' => 42,
            'board_id' => 5,
            'payload' => ['name' => 'Ship it'],
            'pushes' => $pushes ?: null,
        ], static fn ($v) => $v !== null);
    }

    private function inboxCount(): int
    {
        $path = $this->dir.'/state/inbox.jsonl';

        return File::exists($path) ? count(file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : 0;
    }

    public function test_unpaired_channel_push_warns_once_and_still_delivers(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        Log::spy();
        $this->writeAgent('prod-agent', UnpairedChannelPushClassifier::class, self::CHANNEL);

        $this->dispatcher()->dispatch('kanban', '5', $this->dto(), $this->payload());

        $eventId = WebhookEvent::firstOrFail()->id;
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg, $ctx) => str_contains((string) $msg, self::WARN)
            && ($ctx['agent'] ?? null) === 'prod-agent'
            && ($ctx['event'] ?? null) === $eventId
            && ($ctx['classifier'] ?? null) === UnpairedChannelPushClassifier::class
            && ($ctx['target_id'] ?? null) === 'unpaired-1')->once();

        // A warn, never a dispatch-outcome change.
        $d = AgentDispatch::firstOrFail();
        $this->assertSame(AgentDispatch::OUTCOME_DELIVERED, $d->outcome);
        $this->assertNotNull($d->processed_at);
        Http::assertSentCount(1);              // the unpaired wake still fired
        $this->assertSame(1, $this->inboxCount());
    }

    public function test_paired_channel_push_does_not_warn(): void
    {
        // EventDrivenClassifier pairs by construction: one channel_push per
        // Intent, targetId = that Intent's subjectId. route_intents is absent
        // (defaults false), so wakePush really does hand-emit here.
        Http::fake(['*' => Http::response('ok', 200)]);
        Log::spy();
        $this->writeAgent('prod-agent', EventDrivenClassifier::class, self::CHANNEL);

        $this->dispatcher()->dispatch('kanban', '5', $this->dto(), $this->payload());

        Http::assertSentCount(1);              // a channel_push DID reach the check
        Log::shouldNotHaveReceived('warning', fn ($msg) => str_contains((string) $msg, self::WARN));
    }

    public function test_kanban_triage_family_push_does_not_warn(): void
    {
        // The shipped hand-emitting family: its push targets the base new_card
        // Intent's subjectId. route_intents is deliberately ABSENT — under
        // route_intents:true wakePush returns [] and this would green vacuously.
        Http::fake(['*' => Http::response('ok', 200)]);
        Log::spy();
        $this->writeAgent('prod-agent', CoordinationClassifier::class,
            "  config:\n    families: [kanban-triage]\n".self::CHANNEL);

        $payload = $this->payload() + ['card' => ['tags' => [], 'external_references' => []]];
        $this->dispatcher()->dispatch('kanban', '5', $this->dto(), $payload);

        Http::assertSentCount(1);              // the family's push DID reach the check
        Log::shouldNotHaveReceived('warning', fn ($msg) => str_contains((string) $msg, self::WARN));
    }

    public function test_opt_out_suppresses_the_warn(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        Log::spy();
        $this->writeAgent('prod-agent', UnpairedChannelPushClassifier::class,
            self::CHANNEL."surface:\n  silent_drop_warnings: false\n");

        $this->dispatcher()->dispatch('kanban', '5', $this->dto(), $this->payload());

        Http::assertSentCount(1);              // same unpaired shape as test 1...
        Log::shouldNotHaveReceived('warning', fn ($msg) => str_contains((string) $msg, self::WARN));
    }

    public function test_two_unpaired_pushes_on_one_target_warn_once(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        Log::spy();
        $this->writeAgent('prod-agent', UnpairedChannelPushClassifier::class, self::CHANNEL);

        $this->dispatcher()->dispatch('kanban', '5', $this->dto(), $this->payload([
            ['target_id' => 'unpaired-1', 'debounce_key' => 'a'],
            ['target_id' => 'unpaired-1', 'debounce_key' => 'b'],
        ]));

        Http::assertSentCount(2);              // distinct debounce keys — both survived coalescing
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_contains((string) $msg, self::WARN))->once();
    }

    public function test_each_agent_warns_for_the_same_unpaired_target(): void
    {
        // The dedup key is per agent because the opt-out is per agent: agent-b
        // must still be told even though agent-a already was.
        Http::fake(['*' => Http::response('ok', 200)]);
        Log::spy();
        $this->writeAgent('agent-a', UnpairedChannelPushClassifier::class, self::CHANNEL);
        $this->writeAgent('agent-b', UnpairedChannelPushClassifier::class, self::CHANNEL);

        $this->dispatcher()->dispatch('kanban', '5', $this->dto(), $this->payload());

        Http::assertSentCount(2);
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_contains((string) $msg, self::WARN))->twice();
    }

    public function test_unpaired_target_coalesced_away_still_warns(): void
    {
        // Coalescing runs AFTER the check and does not repair the emitted shape:
        // the authoring defect exists whether or not this event's last-wins
        // collapse happened to mask the delivery.
        Http::fake(['*' => Http::response('ok', 200)]);
        Log::spy();
        $this->writeAgent('prod-agent', UnpairedChannelPushClassifier::class, self::CHANNEL);

        $this->dispatcher()->dispatch('kanban', '5', $this->dto(), $this->payload([
            ['target_id' => 'unpaired-1', 'debounce_key' => 'shared'],
            ['target_id' => '42', 'debounce_key' => 'shared'],
        ]));

        Http::assertSentCount(1);              // last-wins: only the PAIRED target was delivered
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg, $ctx) => str_contains((string) $msg, self::WARN)
            && ($ctx['target_id'] ?? null) === 'unpaired-1')->once();
    }

    public function test_stripped_channel_push_does_not_warn(): void
    {
        // The DL-203 strip removes every non-DurableReaction target, so no
        // channel_push reaches the check — push and intents are suppressed
        // TOGETHER as policy, which is not the drop asymmetry the guard flags.
        // The durable target is what keeps the dispatch on the strip branch
        // (a channel_push-only payload would exercise the wholesale drop).
        Log::spy();
        File::put($this->dir.'/gh-agent.yml',
            "subscriptions:\n  - provider: github\n    scopes: [\"acme/widget\"]\n"
            ."classifier:\n  class: '".WritebackEmittingClassifier::class."'\n"
            ."echo_suppression:\n  treat_as_echo_ids: [555]\n");

        $registry = new HandlerRegistry;
        $registry->register('dur', new RecordingDurableHandler('dur'));

        // subject_id 7 ≠ the fixture's literal push targetId 'card-1' — the
        // emitted shape IS unpaired, so a check hoisted above the strip warns.
        $this->dispatcher(handlers: $registry)->dispatch('github', 'acme/widget', new EventDto(
            deliveryId: 'gh-1', scopeId: 'acme/widget', eventType: 'issues.reopened', actorId: '555',
        ), ['subject_id' => 7, 'targets' => ['channel_push', 'dur']]);

        $this->assertSame(['dur'], HandlerRecorder::$calls);   // reached the strip branch
        Log::shouldNotHaveReceived('warning', fn ($msg) => str_contains((string) $msg, self::WARN));
    }

    public function test_no_warn_when_intent_staging_throws(): void
    {
        // The warn claims the inbox backstop will not carry this subject — only
        // a classifier-authoring claim once staging itself succeeded. A staging
        // throw is treatment B (propagates → 5xx → redelivery), and this event
        // never staged, so it is owed no warn.
        Log::spy();
        $this->writeAgent('prod-agent', UnpairedChannelPushClassifier::class, self::CHANNEL);

        $throwingLog = new class extends IntentLog
        {
            public function stage(AgentConfig $agent, WebhookEvent $event, Intent $intent, int $index): void
            {
                throw new RuntimeException('disk full');
            }
        };

        // NOT expectException: that ends the method, leaving the assertion below
        // unreachable — the test would green under its own mutation.
        try {
            $this->dispatcher($throwingLog)->dispatch('kanban', '5', $this->dto(), $this->payload());
            $this->fail('expected the staging failure to propagate');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('disk full', $e->getMessage());
        }

        Log::shouldNotHaveReceived('warning', fn ($msg) => str_contains((string) $msg, self::WARN));
    }
}
