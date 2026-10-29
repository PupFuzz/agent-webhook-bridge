<?php

namespace Tests\Feature\Dispatch;

use App\Bridge\Adapters\EventDto;
use App\Bridge\Classifiers\CoordinationClassifier;
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
use Illuminate\Support\Facades\Schema;
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

    private function dispatch(string $deliveryId, string $eventType, array $payload): void
    {
        $subs = new SubscriptionRegistry($this->dir);
        $dispatcher = new DispatchService(
            $subs,
            AgentRegistry::fromAgentConfigs($subs->agentConfigs(), AgentRegistry::loadSharedIdentities($this->dir)),
            new HandlerRegistry,
            new IntentLog,
        );
        $dispatcher->dispatch('github', 'org/impl', new EventDto(deliveryId: $deliveryId, scopeId: 'org/impl', eventType: $eventType, actorId: '999'), $payload);
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
        Schema::drop('release_landings');
        Log::spy();

        $this->push();

        $this->assertSame(1, $this->releaseLandedPushesTo('http://127.0.0.1:8791/'));
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_starts_with($message, 'bridge release_landed: the landing could not be claimed'))->once();
    }
}
