<?php

namespace Tests\Feature\Dispatch;

use App\Bridge\Adapters\EventDto;
use App\Bridge\Classifiers\CoordinationClassifier;
use App\Bridge\Dispatch\DispatchService;
use App\Bridge\Dispatch\IntentLog;
use App\Bridge\Support\AdvisoryNotice;
use App\Bridge\Support\AgentRegistry;
use App\Bridge\Support\ClassifierResolver;
use App\Bridge\Support\HandlerRegistry;
use App\Bridge\Support\SubscriptionRegistry;
use App\Models\AgentDispatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A dependency-advisory notice issue wakes the seats subscribed to its repo, whatever families
 * they run, unless a seat opted out (card#13823 / DL-506). Asserted through dispatch, because the
 * gates that could swallow it — the `treat_as_signal` allowlist, the `scope_author_map` echo — live
 * there.
 */
class AdvisoryWakeTest extends TestCase
{
    use RefreshDatabase;

    private const SEAT_URL = 'http://127.0.0.1:8791/';

    /** github-actions[bot], the account a workflow's GITHUB_TOKEN acts as. */
    private const BOT = ['login' => 'github-actions[bot]', 'id' => 41898282];

    /** Any other account that can label or reopen an issue: a triage collaborator, another App. */
    private const COLLABORATOR = ['login' => 'some-collaborator', 'id' => 777001];

    private string $dir;

    private int $delivery = 0;

    protected function setUp(): void
    {
        parent::setUp();
        ClassifierResolver::flush();
        $this->dir = sys_get_temp_dir().'/advisory-wake-'.uniqid();
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

    /**
     * One seat on `org/impl`, plus the YAML lines given for its `classifier.config` and the rest of
     * its file.
     *
     * @param  list<string>  $config  lines under `classifier.config`
     */
    private function writeSeat(array $config = ["families: ['impl-ci-wake']"], string $extra = '', bool $routeIntents = false, string $class = CoordinationClassifier::class): void
    {
        File::put($this->dir.'/seat-a.yml',
            "subscriptions:\n  - provider: github\n    scopes: ['org/impl']\n"
            ."classifier:\n  class: '".$class."'\n"
            .($config === [] ? '' : "  config:\n".implode('', array_map(static fn (string $l): string => "    {$l}\n", $config)))
            ."channel:\n  url: ".self::SEAT_URL."\n"
            .($routeIntents ? "  route_intents: true\n" : '')
            .$extra);
    }

    /**
     * @param  list<string>  $labels
     * @param  array<string, mixed>  $extra  top-level payload keys (e.g. `label` on a `labeled`)
     */
    private function issueEvent(string $action, array $labels = ['dependency-advisory'], array $extra = [], int $number = 17, array $sender = self::BOT): void
    {
        $subs = new SubscriptionRegistry($this->dir);
        $dispatcher = new DispatchService(
            $subs,
            AgentRegistry::fromAgentConfigs($subs->agentConfigs(), AgentRegistry::loadSharedIdentities($this->dir)),
            new HandlerRegistry,
            new IntentLog,
        );
        $payload = [
            'action' => $action,
            'issue' => [
                'number' => $number,
                'title' => 'GHSA-xxxx-yyyy-zzzz: guzzlehttp/guzzle < 8.5.5',
                'html_url' => "https://github.com/org/impl/issues/{$number}",
                'body' => 'An advisory affects this repo.',
                'labels' => array_map(static fn (string $l): array => ['name' => $l], $labels),
            ],
            'repository' => ['full_name' => 'org/impl'],
            'sender' => $sender,
        ] + $extra;
        $dispatcher->dispatch('github', 'org/impl', new EventDto(
            deliveryId: 'd-'.(++$this->delivery), scopeId: 'org/impl', eventType: "issues.{$action}", actorId: (string) $sender['id'],
        ), $payload);
    }

    /** @return list<array<string, mixed>> the intents pushed to the seat, in order */
    private function pushedIntents(): array
    {
        return Http::recorded(fn ($r) => $r->url() === self::SEAT_URL)
            ->map(static fn (array $pair): array => $pair[0]->data()['intent'])
            ->values()
            ->all();
    }

    public function test_an_opened_notice_wakes_a_seat_that_runs_only_impl_ci_wake(): void
    {
        $this->writeSeat();

        $this->issueEvent('opened');

        $pushed = $this->pushedIntents();
        $this->assertCount(1, $pushed);
        $this->assertSame(AdvisoryNotice::KIND, $pushed[0]['kind']);
        $this->assertSame('org/impl#17', $pushed[0]['subject_id']);
        $this->assertSame([
            'repo' => 'org/impl',
            'event' => 'issues.opened',
            'action' => 'opened',
            'number' => 17,
            'url' => 'https://github.com/org/impl/issues/17',
            'title' => 'GHSA-xxxx-yyyy-zzzz: guzzlehttp/guzzle < 8.5.5',
            'label' => 'dependency-advisory',
        ], $pushed[0]['payload']);
    }

    public function test_a_reopened_notice_wakes_again(): void
    {
        $this->writeSeat();

        $this->issueEvent('opened');
        $this->issueEvent('reopened');

        $this->assertSame(['opened', 'reopened'], array_column(array_column($this->pushedIntents(), 'payload'), 'action'));
    }

    public function test_the_advisory_label_added_to_an_open_issue_wakes(): void
    {
        $this->writeSeat();

        $this->issueEvent('labeled', extra: ['label' => ['name' => 'dependency-advisory']]);

        $this->assertSame(['labeled'], array_column(array_column($this->pushedIntents(), 'payload'), 'action'));
    }

    /** GitHub sends `opened` AND a `labeled` per label for an issue created with its labels: one wake. */
    public function test_an_issue_created_with_the_label_wakes_once_from_its_two_deliveries(): void
    {
        $this->writeSeat();

        $this->issueEvent('opened');
        $this->issueEvent('labeled', extra: ['label' => ['name' => 'dependency-advisory']]);

        $this->assertCount(1, $this->pushedIntents());
        $this->assertSame(AdvisoryNotice::ALREADY_CARRIED, AgentDispatch::where('outcome', AgentDispatch::OUTCOME_DROPPED)->value('reason'));
    }

    public function test_no_wake_without_the_label_or_on_another_label_or_on_another_action(): void
    {
        $this->writeSeat();

        $this->issueEvent('opened', labels: ['bug']);
        $this->issueEvent('labeled', extra: ['label' => ['name' => 'bug']]);
        $this->issueEvent('edited');
        $this->issueEvent('closed');
        $this->issueEvent('unlabeled', extra: ['label' => ['name' => 'dependency-advisory']]);

        Http::assertNothingSent();
    }

    public function test_a_seat_that_opted_out_is_not_woken(): void
    {
        $this->writeSeat(["families: ['impl-ci-wake']", 'advisory_wake: false']);

        $this->issueEvent('opened');

        Http::assertNothingSent();
    }

    public function test_a_configured_label_replaces_the_default(): void
    {
        $this->writeSeat(["families: ['impl-ci-wake']", "advisory_label: 'Security-Notice'"]);

        $this->issueEvent('opened');
        $this->issueEvent('opened', labels: ['security-notice'], number: 18);

        $pushed = $this->pushedIntents();
        $this->assertSame(['org/impl#18'], array_column($pushed, 'subject_id'));
        $this->assertSame('security-notice', $pushed[0]['payload']['label']);
    }

    /**
     * `scope_author_map` names the serving seat as the actor of every event on its repo, and the
     * dispatcher drops a result whose actor is the serving seat as its own write. A bot's notice
     * must not be read as the seat's own issue — here with coord-message staging non-addressed
     * issues, the profile that returns a result carrying that attribution.
     */
    public function test_a_repo_scope_author_map_names_for_the_seat_still_wakes_it(): void
    {
        $this->writeSeat(['scope_author_map:', "  'org/impl': seat-a", 'coord_non_addressed_disposition: inbox_stage']);

        $this->issueEvent('opened');

        $this->assertSame([AdvisoryNotice::KIND], array_column($this->pushedIntents(), 'kind'));
    }

    /** A `treat_as_signal` seat drops every actor it does not name — but not an advisory notice. */
    public function test_a_treat_as_signal_seat_still_wakes(): void
    {
        File::put($this->dir.'/pm.yml', "subscriptions: []\n");
        $this->writeSeat(extra: "echo_suppression:\n  treat_as_signal: [pm]\n");

        $this->issueEvent('opened');
        $this->issueEvent('labeled', extra: ['label' => ['name' => 'dependency-advisory']]);

        $this->assertSame([AdvisoryNotice::KIND], array_column($this->pushedIntents(), 'kind'), 'one wake: the once key survives the strip');
    }

    /** Control for the bypass above: the same seat still drops a non-advisory issue from the bot. */
    public function test_a_treat_as_signal_seat_still_drops_the_bots_other_issues(): void
    {
        File::put($this->dir.'/pm.yml', "subscriptions: []\n");
        $this->writeSeat(['wake_membership: [to_all]'], extra: "echo_suppression:\n  treat_as_signal: [pm]\n");

        $this->issueEvent('opened', labels: ['to:all']);

        Http::assertNothingSent();
        $this->assertSame('actor is not a signal', AgentDispatch::value('reason'));
    }

    /**
     * Only the producer's issue is a notice. Anyone else labelling or reopening an issue must not
     * get past the allowlist, nor re-wake the seat each time they toggle the label.
     */
    public function test_a_non_producer_labelling_or_reopening_on_an_allowlisted_seat_is_dropped(): void
    {
        File::put($this->dir.'/pm.yml', "subscriptions: []\n");
        $this->writeSeat(extra: "echo_suppression:\n  treat_as_signal: [pm]\n");

        $this->issueEvent('labeled', extra: ['label' => ['name' => 'dependency-advisory']], sender: self::COLLABORATOR);
        $this->issueEvent('reopened', sender: self::COLLABORATOR);
        $this->issueEvent('labeled', extra: ['label' => ['name' => 'dependency-advisory']], sender: self::COLLABORATOR);
        $this->issueEvent('reopened', sender: self::COLLABORATOR);

        Http::assertNothingSent();
        $this->assertSame(['actor is not a signal'], AgentDispatch::distinct()->pluck('reason')->all());
    }

    /** On a seat with no allowlist, a non-producer's labelled issue is coord-message's, as before. */
    public function test_a_non_producer_labelled_issue_is_an_ordinary_coordination_issue(): void
    {
        $this->writeSeat(['wake_membership: [to_all]']);

        $this->issueEvent('opened', labels: ['dependency-advisory', 'to:all'], sender: self::COLLABORATOR);
        $this->issueEvent('opened', labels: ['dependency-advisory'], number: 18, sender: self::COLLABORATOR);

        $pushed = $this->pushedIntents();
        $this->assertSame(['coord_issue'], array_column($pushed, 'kind'));
        $this->assertSame('17', $pushed[0]['subject_id']);
    }

    /** `advisory_actors` names the producer by login (any case) or by numeric id. */
    public function test_a_configured_producer_replaces_the_default(): void
    {
        $this->writeSeat(["families: ['impl-ci-wake']", "advisory_actors: ['Some-Collaborator']"]);

        $this->issueEvent('opened', sender: self::BOT);
        $this->issueEvent('opened', number: 18, sender: self::COLLABORATOR);

        $this->assertSame(['org/impl#18'], array_column($this->pushedIntents(), 'subject_id'));
    }

    /**
     * The bypass carries the notice and nothing else: another agent surface a (sub)classifier puts
     * on the same non-signal event is still stripped — its hand-emitted push on a `route_intents:
     * false` channel, and its intent, which a `route_intents: true` channel would push.
     */
    #[DataProvider('routeIntents')]
    public function test_the_signal_bypass_keeps_only_the_notice(bool $routeIntents): void
    {
        File::put($this->dir.'/pm.yml', "subscriptions: []\n");
        $this->writeSeat(extra: "echo_suppression:\n  treat_as_signal: [pm]\n", routeIntents: $routeIntents, class: ExtraIntentCoordinationClassifier::class);

        $this->issueEvent('opened');

        $this->assertSame([AdvisoryNotice::KIND], array_column($this->pushedIntents(), 'kind'));
    }

    /** @return array<string, array{bool}> */
    public static function routeIntents(): array
    {
        return ['hand-emitted push' => [false], 'routed intents' => [true]];
    }

    /** Control: the same classifier on a seat with no allowlist delivers both. */
    #[DataProvider('routeIntents')]
    public function test_without_an_allowlist_the_extra_surface_is_delivered(bool $routeIntents): void
    {
        $this->writeSeat(routeIntents: $routeIntents, class: ExtraIntentCoordinationClassifier::class);

        $this->issueEvent('opened');

        $this->assertEqualsCanonicalizing([AdvisoryNotice::KIND, 'test_event'], array_column($this->pushedIntents(), 'kind'));
    }

    /** An addressed advisory issue is still ONE wake: the notice, not also a coord_issue. */
    public function test_a_notice_coord_message_would_also_wake_on_is_one_wake(): void
    {
        $this->writeSeat([]);

        $this->issueEvent('opened', labels: ['dependency-advisory', 'to:all']);

        $this->assertSame([AdvisoryNotice::KIND], array_column($this->pushedIntents(), 'kind'));
    }

    public function test_on_a_route_intents_channel_the_notice_is_one_push(): void
    {
        $this->writeSeat(["families: ['impl-ci-wake']"], routeIntents: true);

        $this->issueEvent('opened');

        $this->assertSame([AdvisoryNotice::KIND], array_column($this->pushedIntents(), 'kind'));
    }
}
