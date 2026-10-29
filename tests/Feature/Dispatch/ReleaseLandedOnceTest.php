<?php

namespace Tests\Feature\Dispatch;

use App\Bridge\Adapters\EventDto;
use App\Bridge\Classifiers\CoordinationClassifier;
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
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * One merge into the release branch reaches the bridge as up to TWO deliveries — the `push` to
 * that branch and the `pull_request.closed` (merged) — in either order, and must wake each seat
 * once (card#12576). Asserted through dispatch, because the claim is about delivery.
 */
class ReleaseLandedOnceTest extends TestCase
{
    use RefreshDatabase;

    private const SHA = '0123456789abcdef0123456789abcdef01234567';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        ClassifierResolver::flush();
        $this->dir = sys_get_temp_dir().'/release-landed-'.uniqid();
        File::ensureDirectoryExists($this->dir);
        config(['bridge.config_dir' => $this->dir]);
        Http::fake(['*' => Http::response('ok', 200)]);
    }

    protected function tearDown(): void
    {
        ClassifierResolver::flush();
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /** @param  array<string, int>  $seats  seat => channel port */
    private function writeSeats(array $seats, bool $routeIntents = false): void
    {
        foreach ($seats as $seat => $port) {
            File::put($this->dir."/{$seat}.yml",
                "subscriptions:\n  - provider: github\n    scopes: ['org/impl']\n"
                ."classifier:\n  class: '".CoordinationClassifier::class."'\n"
                ."  config:\n"
                ."    families: ['impl-ci-wake']\n"
                .($routeIntents ? "    impl_non_wake_disposition: inbox_stage\n" : '')
                ."channel:\n  url: http://127.0.0.1:{$port}/\n"
                .($routeIntents ? "  route_intents: true\n" : ''));
        }
    }

    private function dispatch(string $deliveryId, string $eventType, array $payload, ?IntentLog $intentLog = null, string $actorId = '999'): void
    {
        $subs = new SubscriptionRegistry($this->dir);
        $dispatcher = new DispatchService(
            $subs,
            AgentRegistry::fromAgentConfigs($subs->agentConfigs(), AgentRegistry::loadSharedIdentities($this->dir)),
            new HandlerRegistry,
            $intentLog ?? new IntentLog,
        );
        $dispatcher->dispatch('github', 'org/impl', new EventDto(deliveryId: $deliveryId, scopeId: 'org/impl', eventType: $eventType, actorId: $actorId), $payload);
    }

    private function push(string $sha = self::SHA, string $deliveryId = 'd-push'): void
    {
        $this->dispatch($deliveryId, 'push', [
            'ref' => 'refs/heads/main',
            'after' => $sha,
            'head_commit' => ['id' => $sha, 'message' => "Merge pull request #942 from org/release/v0.55.0\n\nbody", 'url' => "https://github.com/org/impl/commit/{$sha}"],
            'commits' => [['id' => $sha]],
            'repository' => ['full_name' => 'org/impl', 'html_url' => 'https://github.com/org/impl'],
            'sender' => ['login' => 'operator', 'id' => 999],
        ]);
    }

    private function mergedPr(string $sha = self::SHA, string $base = 'main', bool $merged = true): void
    {
        $this->dispatch('d-pr-'.$base.'-'.($merged ? 'm' : 'u'), 'pull_request.closed', [
            'action' => 'closed',
            'number' => 942,
            'pull_request' => [
                'number' => 942, 'title' => 'release: v0.55.0', 'html_url' => 'https://github.com/org/impl/pull/942',
                'merged' => $merged, 'merge_commit_sha' => $sha, 'commits' => 26, 'base' => ['ref' => $base],
            ],
            'repository' => ['full_name' => 'org/impl', 'html_url' => 'https://github.com/org/impl'],
            'sender' => ['login' => 'operator', 'id' => 999],
        ]);
    }

    private function releaseLandedPushesTo(string $url): int
    {
        return count(Http::recorded(fn ($r) => $r->url() === $url
            && ($r->data()['intent']['kind'] ?? null) === 'impl_release_landed'
            && ($r->data()['intent']['subject_id'] ?? null) === self::SHA));
    }

    public function test_a_merged_pr_into_the_release_branch_wakes_alone(): void
    {
        $this->writeSeats(['seat-a' => 8791]);

        $this->mergedPr();

        $this->assertSame(1, $this->releaseLandedPushesTo('http://127.0.0.1:8791/'));
        Http::assertSentCount(1);
    }

    public function test_push_then_pr_for_one_merge_wakes_each_seat_once(): void
    {
        $this->writeSeats(['seat-a' => 8791, 'seat-b' => 8792]);

        $this->push();
        $this->mergedPr();

        $this->assertSame(1, $this->releaseLandedPushesTo('http://127.0.0.1:8791/'));
        $this->assertSame(1, $this->releaseLandedPushesTo('http://127.0.0.1:8792/'));
        Http::assertSentCount(2);
        $this->assertSame(2, AgentDispatch::where('outcome', AgentDispatch::OUTCOME_DROPPED)
            ->where('reason', CoordinationClassifier::IMPL_RELEASE_LANDED_ALREADY_CARRIED)->count());
    }

    public function test_pr_then_push_for_one_merge_wakes_once(): void
    {
        $this->writeSeats(['seat-a' => 8791]);

        $this->mergedPr();
        $this->push();

        $this->assertSame(1, $this->releaseLandedPushesTo('http://127.0.0.1:8791/'));
        Http::assertSentCount(1);
    }

    /**
     * The inbox_stage + route_intents profile: a release push the PR already carried must not
     * fall through to the broad-inbox `impl_push`, which that channel would route as a second
     * delivery for the same merge.
     */
    public function test_on_a_route_intents_channel_one_merge_is_one_delivery(): void
    {
        $this->writeSeats(['seat-a' => 8791], routeIntents: true);

        $this->mergedPr();
        $this->push();

        $this->assertSame(1, $this->releaseLandedPushesTo('http://127.0.0.1:8791/'));
        Http::assertSentCount(1);
    }

    public function test_a_later_landing_of_a_different_commit_still_wakes(): void
    {
        $this->writeSeats(['seat-a' => 8791]);

        $this->mergedPr();
        $this->push(str_repeat('b', 40), 'd-push-2');

        Http::assertSentCount(2);
    }

    public function test_an_unmerged_close_or_a_merge_into_dev_wakes_nobody(): void
    {
        $this->writeSeats(['seat-a' => 8791]);

        $this->mergedPr(merged: false);
        $this->mergedPr(base: 'dev');

        Http::assertNothingSent();
    }

    /**
     * An update serves the new code before `migrate` creates the ledger's table: the landing
     * must still wake, and say why it could not be de-duplicated.
     */
    public function test_a_landing_the_ledger_cannot_claim_still_wakes(): void
    {
        $this->writeSeats(['seat-a' => 8791]);
        Schema::drop('dispatch_once_claims');
        Log::spy();

        $this->push();

        $this->assertSame(1, $this->releaseLandedPushesTo('http://127.0.0.1:8791/'));
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_starts_with($message, 'bridge dispatch: a once key could not be claimed'))->once();
    }

    /**
     * The dispatcher can still suppress a delivery AFTER classify — here the shared-identity echo
     * check on the actor coord-message recovers for an addressed `pull_request.closed` (DL-005).
     * That delivery woke nobody, so it must not hold the landing: the push for the same merge
     * wakes. A claim taken inside classify left this merge with ZERO wakes (review of #1005).
     */
    public function test_a_pr_delivery_the_echo_gate_drops_does_not_hold_the_landing(): void
    {
        File::put($this->dir.'/me.yml',
            "subscriptions:\n  - provider: github\n    scopes: ['org/impl']\n"
            ."classifier:\n  class: '".CoordinationClassifier::class."'\n"
            ."  config:\n    families: ['coord-message', 'impl-ci-wake']\n"
            ."    scope_author_map:\n      org/impl: me\n"
            ."    coord_extra_actions:\n      pull_request: [closed]\n"
            ."channel:\n  url: http://127.0.0.1:8791/\n");
        File::put($this->dir.'/shared-identities.json', (string) json_encode([
            'shared_identities' => [['github_user_id' => 12000042, 'github_login' => 'shared-bot', 'agents' => ['me']]],
        ]));
        $sender = ['login' => 'shared-bot', 'id' => 12000042];

        $this->dispatch('d-pr', 'pull_request.closed', [
            'action' => 'closed', 'number' => 942,
            'pull_request' => ['number' => 942, 'title' => 'release: v1', 'body' => '', 'html_url' => 'https://x/942',
                'merged' => true, 'merge_commit_sha' => self::SHA, 'commits' => 3, 'base' => ['ref' => 'main'],
                'labels' => [['name' => 'to:all']]],
            'repository' => ['full_name' => 'org/impl', 'html_url' => 'https://x'], 'sender' => $sender,
        ], actorId: '12000042');
        $this->assertSame(1, AgentDispatch::where('outcome', AgentDispatch::OUTCOME_DROPPED)->where('reason', 'like', 'echo:%')->count(), 'the PR delivery is the echo-dropped one');

        $this->dispatch('d-push', 'push', [
            'ref' => 'refs/heads/main', 'after' => self::SHA,
            'head_commit' => ['id' => self::SHA, 'message' => 'Merge'], 'commits' => [['id' => self::SHA]],
            'repository' => ['full_name' => 'org/impl', 'html_url' => 'https://x'], 'sender' => $sender,
        ], actorId: '12000042');

        $this->assertSame(1, $this->releaseLandedPushesTo('http://127.0.0.1:8791/'));
    }

    /**
     * A delivery that claims the landing and then fails before it is staged returns a 5xx GitHub
     * never retries; it must give the landing back so its twin wakes.
     */
    public function test_a_delivery_that_fails_to_stage_gives_the_landing_back(): void
    {
        $this->writeSeats(['seat-a' => 8791]);
        $failing = new class extends IntentLog
        {
            public function stage(AgentConfig $agent, WebhookEvent $event, Intent $intent, int $index): void
            {
                throw new RuntimeException('inbox unwritable');
            }
        };

        try {
            $this->dispatch('d-pr', 'pull_request.closed', [
                'action' => 'closed', 'number' => 942,
                'pull_request' => ['number' => 942, 'title' => 'release: v0.55.0', 'html_url' => 'https://github.com/org/impl/pull/942',
                    'merged' => true, 'merge_commit_sha' => self::SHA, 'commits' => 26, 'base' => ['ref' => 'main']],
                'repository' => ['full_name' => 'org/impl', 'html_url' => 'https://github.com/org/impl'],
            ], $failing);
            $this->fail('the staging failure must propagate (a 5xx)');
        } catch (RuntimeException $e) {
            $this->assertSame('inbox unwritable', $e->getMessage());
        }

        $this->push();

        $this->assertSame(1, $this->releaseLandedPushesTo('http://127.0.0.1:8791/'));
    }

    /**
     * A lost claim removes the landing it governs and nothing else: the same `pull_request.closed`
     * can also be a coordination message the seat is addressed by, and that still arrives.
     */
    public function test_a_lost_landing_leaves_the_rest_of_the_delivery_intact(): void
    {
        File::put($this->dir.'/me.yml',
            "subscriptions:\n  - provider: github\n    scopes: ['org/impl']\n"
            ."classifier:\n  class: '".CoordinationClassifier::class."'\n"
            ."  config:\n    families: ['coord-message', 'impl-ci-wake']\n"
            ."    coord_extra_actions:\n      pull_request: [closed]\n"
            ."channel:\n  url: http://127.0.0.1:8791/\n");

        $this->push();
        $this->dispatch('d-pr', 'pull_request.closed', [
            'action' => 'closed', 'number' => 942,
            'pull_request' => ['number' => 942, 'title' => 'release: v1', 'body' => 'FROM: other', 'html_url' => 'https://x/942',
                'merged' => true, 'merge_commit_sha' => self::SHA, 'commits' => 3, 'base' => ['ref' => 'main'],
                'labels' => [['name' => 'to:all'], ['name' => 'from:other']]],
            'repository' => ['full_name' => 'org/impl', 'html_url' => 'https://x'],
        ]);

        $this->assertSame(1, $this->releaseLandedPushesTo('http://127.0.0.1:8791/'));
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => ($r->data()['intent']['kind'] ?? null) === 'coord_pr');
    }
}
