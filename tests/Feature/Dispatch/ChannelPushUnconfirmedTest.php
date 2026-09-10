<?php

namespace Tests\Feature\Dispatch;

use App\Bridge\Adapters\EventDto;
use App\Bridge\Classifiers\EventDrivenClassifier;
use App\Bridge\Classifiers\InboxOnlyClassifier;
use App\Bridge\Dispatch\DispatchService;
use App\Bridge\Dispatch\IntentLog;
use App\Bridge\Support\AgentRegistry;
use App\Bridge\Support\ClassifierResolver;
use App\Bridge\Support\HandlerRegistry;
use App\Bridge\Support\SubscriptionRegistry;
use App\Models\AgentDispatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * What the dispatch ledger's operator-facing surfaces SAY about a `channel_push`
 * delivery, and what the ledger STORES for it (card#9172).
 *
 * The defect: `ChannelPushTransport` calls `->throw()`, so a 2xx is the sole success
 * condition, and the channel server answers 202 the moment the notification is written
 * to its stdio transport. That 202 reached `markDelivered()` and logged `bridge dispatch:
 * delivered` — a claim about the SEAT built out of a fact about the TRANSPORT. Nothing on
 * this path can tell an operator whether the seat received it.
 *
 * ⛔ THE STORED ENUM VALUE IS PINNED AND MUST NOT MOVE. `outcome` is read by
 * `bridge:replay`, by the `--force` transitions and by `bridge:standup`; only what the
 * bridge SAYS changes here. The pin below is the guard on that half, and its control is
 * a mutation: change the constant and it reds.
 *
 * ⚠ Nothing here asserts whether an unseen notification is dropped or merely deferred to
 * the seat's next turn boundary — that is not established. The defect is that no surface
 * on this path can tell an operator which.
 */
class ChannelPushUnconfirmedTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        ClassifierResolver::flush();
        $this->dir = sys_get_temp_dir().'/chanpush-unconfirmed-'.uniqid();
        File::ensureDirectoryExists($this->dir);
        config(['bridge.config_dir' => $this->dir]);
    }

    protected function tearDown(): void
    {
        ClassifierResolver::flush();
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function writeAgent(string $classifier, bool $withChannel): void
    {
        $yaml = "subscriptions:\n  - provider: kanban\n    scopes: [5]\n"
            ."classifier:\n  class: '".$classifier."'\n";
        if ($withChannel) {
            $yaml .= "channel:\n  url: http://127.0.0.1:8788/\n";
        }
        File::put($this->dir.'/prod-agent.yml', $yaml);
    }

    private function dispatch(): void
    {
        $subs = new SubscriptionRegistry($this->dir);
        (new DispatchService(
            $subs,
            AgentRegistry::fromAgentConfigs($subs->agentConfigs(), AgentRegistry::loadSharedIdentities($this->dir)),
            new HandlerRegistry,
            new IntentLog,
        ))->dispatch(
            'kanban',
            '5',
            new EventDto(deliveryId: 'evt-1', scopeId: '5', eventType: 'task.created', actorId: '999'),
            ['subject_id' => 42, 'board_id' => 5, 'payload' => ['name' => 'Ship it']],
        );
    }

    public function test_a_channel_push_dispatch_is_logged_as_accepted_by_transport_unconfirmed(): void
    {
        Http::fake(['*' => Http::response('forwarded', 202)]);
        $this->writeAgent(EventDrivenClassifier::class, withChannel: true);
        Log::spy();

        $this->dispatch();

        // The push really went out — without this the log assertion below is equally
        // consistent with a dispatch that pushed nothing at all.
        Http::assertSent(fn ($r) => $r->url() === 'http://127.0.0.1:8788/');
        Log::shouldHaveReceived('info')->withArgs(
            fn (string $m, array $ctx) => str_contains($m, 'bridge dispatch: accepted by transport (unconfirmed)')
                && isset($ctx['unconfirmed'])
                && str_contains($ctx['unconfirmed'], 'channel_push')
                && str_contains($ctx['unconfirmed'], 'no delivery receipt')
        );
        // ⛔ THE FULL 2-ARG FORM IS LOAD-BEARING — do not "simplify" it back to one element.
        // `Log::info()` is called with (message, context), and a 1-element expectation goes
        // through Mockery's withArgsInArray(), which never matches a 2-arg call: the
        // never() is then trivially satisfied and the assertion says nothing. Measured —
        // with a spurious `delivered` line emitted alongside the real one, the 1-element
        // form PASSED and this one reds with `exactly 0 times but called 1 times`.
        Log::shouldNotHaveReceived('info', ['bridge dispatch: delivered', Mockery::any()]);
    }

    public function test_a_channel_push_that_threw_reads_as_unconfirmed_too_and_keeps_its_error_note(): void
    {
        // ⛔ THE INVERSION THIS TEST EXISTS TO PREVENT. `ChannelPushTransport::send()` ends
        // in `->throw()`, which raises on ANY non-2xx — that is the endpoint having been
        // REACHED and having ANSWERED, not a push that went nowhere. Keying the wording on
        // "handle() returned" therefore gave the FAILED push the strongest word and the
        // possibly-successful 202 the hedged one, exactly backwards, and on the COMMON
        // case: an idle seat's connection-refused is the documented normal outcome for
        // this handler. Either way the agent-facing leg is unconfirmed, so either way it
        // reads that way.
        //
        // The `handler_note` is what still tells the operator WHICH failure, and it is
        // asserted here so the wording change cannot be read as swallowing the error.
        Http::fake(['*' => Http::response('channel transport closed: stdio gone', 503)]);
        $this->writeAgent(EventDrivenClassifier::class, withChannel: true);
        Log::spy();

        $this->dispatch();

        Http::assertSent(fn ($r) => $r->url() === 'http://127.0.0.1:8788/');
        Log::shouldHaveReceived('info')->withArgs(
            fn (string $m, array $ctx) => $m === 'bridge dispatch: accepted by transport (unconfirmed)'
                && isset($ctx['unconfirmed'])
                && isset($ctx['handler_note'])
                && str_contains($ctx['handler_note'], '503')
        );
        Log::shouldNotHaveReceived('info', ['bridge dispatch: delivered', Mockery::any()]);
        // The stored outcome is STILL `delivered` on the throw path, unchanged from before
        // this card: a best-effort push failure was never a dispatch failure (DL-009).
        $this->assertSame(AgentDispatch::OUTCOME_DELIVERED, AgentDispatch::firstOrFail()->outcome);
    }

    public function test_a_dispatch_with_no_channel_push_still_reads_as_delivered(): void
    {
        // THE HALF THAT MUST NOT MOVE. `inbox_only` stages an intent and runs no push, so
        // there is no unconfirmed transport leg to disclaim — relabelling this one would
        // be the same false claim pointing the other way. This case is what makes the
        // case above a DISCRIMINATION rather than a blanket rename.
        $this->writeAgent(InboxOnlyClassifier::class, withChannel: false);
        Log::spy();

        $this->dispatch();

        Log::shouldHaveReceived('info')->withArgs(
            fn (string $m, array $ctx) => $m === 'bridge dispatch: delivered' && ! isset($ctx['unconfirmed'])
        );
    }

    public function test_the_stored_outcome_enum_value_is_unchanged_by_the_wording(): void
    {
        // ⛔ THE GUARD ON THE HALF THAT WAS NOT AUTHORISED TO CHANGE. Two legs, because
        // the constant and the column can drift apart: the literal the enum has always
        // carried, and the byte actually written to the row by a channel_push delivery
        // (read raw off the connection, not through the model, so a cast could not
        // launder it). `bridge:replay`, the `--force` transitions and `bridge:standup`
        // all key on this value.
        $this->assertSame('delivered', AgentDispatch::OUTCOME_DELIVERED);

        Http::fake(['*' => Http::response('forwarded', 202)]);
        $this->writeAgent(EventDrivenClassifier::class, withChannel: true);

        $this->dispatch();

        $row = AgentDispatch::query()->getConnection()->table('agent_dispatches')->first();
        $this->assertNotNull($row);
        $this->assertSame('delivered', $row->outcome);
    }
}
