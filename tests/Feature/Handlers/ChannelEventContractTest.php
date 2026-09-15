<?php

namespace Tests\Feature\Handlers;

use App\Bridge\Dispatch\Actor;
use App\Bridge\Dispatch\Intent;
use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Handlers\ChannelPushHandler;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\HandlerRegistry;
use Illuminate\Support\Facades\Http;
use Tests\Support\BundledChannelServer;
use Tests\TestCase;

/**
 * The bridge end of the channel-event contract (DL-388).
 *
 * The reference channel server turns the body this bridge POSTs into the `<channel …>` event
 * attributes every agent session is told to rely on. Its node suite cannot run PHP, so it
 * reads the body from a committed fixture — and a fixture is a restatement of the wire shape,
 * which is how the defect this pins survived: the node tests hand-built an `intent` carrying
 * `target_id`, a field `Intent::toArray()` has never emitted, so `deriveMeta()` read a field
 * no real push carries and nothing went red. This test is the check that the fixture IS the
 * body the bridge sends.
 */
class ChannelEventContractTest extends TestCase
{
    public const FIXTURE = 'examples/channel-servers/tests/fixtures/bridge-channel-push-body.json';

    public function test_the_channel_server_fixture_is_the_body_the_bridge_sends_for_an_intent(): void
    {
        Http::fake(['*' => Http::response('forwarded', 202, ['X-Channel-Delivery-Receipt' => 'none'])]);

        $intent = new Intent(
            kind: 'column_move',
            subjectId: '4719',
            provider: 'kanban',
            actor: new Actor('137', 'Example User', false),
            summary: 'moved by Example User: subject 4719 to In Progress',
            payload: ['board_id' => 8, 'from_stage' => 'Next', 'to_stage' => 'In Progress'],
        );

        // The target shape every shipped intent push builds (the route_intents dispatcher,
        // InboxOnlyClassifier::wakePush, AuthoredIntentPush): payload is the intent's
        // toArray(), with no socket/url, so the endpoint comes from the agent's config.
        (new ChannelPushHandler)->handle(
            ReactionTarget::make(
                handler: HandlerRegistry::CHANNEL_PUSH,
                targetId: $intent->subjectId,
                debounceSeconds: 0,
                payload: $intent->toArray(),
            ),
            AgentConfig::fromArray('prod-agent', [
                'identity' => ['kanban_user_id' => 137],
                'subscriptions' => [],
                'channel' => ['url' => 'http://127.0.0.1:8788/'],
            ]),
        );

        $recorded = Http::recorded();
        $this->assertCount(1, $recorded, 'the handler sent no request, so there is no wire body to compare');
        $sent = json_decode($recorded[0][0]->body(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($sent['intent'] ?? null, 'the sent body carries no "intent" object');

        $expected = json_encode($sent, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        $fixture = @file_get_contents(base_path(self::FIXTURE));

        $this->assertNotFalse($fixture, self::FIXTURE." does not exist; write it with exactly:\n".$expected);
        $this->assertSame(
            $sent,
            json_decode($fixture, true, flags: JSON_THROW_ON_ERROR),
            self::FIXTURE.' no longer matches the body ChannelPushHandler sends for Intent::toArray(), so the '
                ."channel server's tests read a shape no real push carries. Replace it with exactly the text below, "
                ."then bump examples/channel-servers/package.json version (the fixture ships in that directory):\n".$expected,
        );
    }

    public function test_the_kinds_the_channel_server_instructions_cite_are_kinds_the_bridge_emits(): void
    {
        // The instructions string is loaded inline into every session, so a stale example
        // cannot be replaced by a pointer — it is guarded here instead (the first cut of the
        // string cited `card_updated` / `card_assigned`, neither of which any Intent carries).
        $source = BundledChannelServer::source();
        $this->assertSame(
            1,
            preg_match('/kind identifies what happened upstream \(e\.g\. ([^)]+)\)/', $source, $m),
            'the instructions no longer cite example kinds in the shape this test reads — update the pattern, not the assertion',
        );
        $kinds = array_map('trim', explode(',', $m[1]));
        $this->assertNotEmpty($kinds);

        $emitted = '';
        foreach (glob(base_path('app/Bridge/Classifiers/*.php')) ?: [] as $file) {
            $emitted .= (string) file_get_contents($file);
        }
        $this->assertNotSame('', $emitted, 'no classifier source was read, so every kind below would look unemitted');

        foreach ($kinds as $kind) {
            $this->assertTrue(
                str_contains($emitted, "kind: '{$kind}'"),
                "the channel server instructions cite kind '{$kind}', but no classifier constructs an Intent with that literal kind",
            );
        }
    }
}
