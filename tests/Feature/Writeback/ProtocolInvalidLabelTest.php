<?php

namespace Tests\Feature\Writeback;

use App\Bridge\Adapters\EventDto;
use App\Bridge\Classifiers\CoordinationClassifier;
use App\Bridge\Classifiers\GitHubPrCardMoveClassifier;
use App\Bridge\Dispatch\DispatchService;
use App\Bridge\Dispatch\IntentLog;
use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\AgentRegistry;
use App\Bridge\Support\ClassifierResolver;
use App\Bridge\Support\HandlerRegistry;
use App\Bridge\Support\SubscriptionRegistry;
use App\Bridge\Writeback\PrCorrelationComment;
use App\Bridge\Writeback\ProtocolInvalidLabeler;
use App\Models\AgentDispatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

/**
 * card#10218 / DL-408: a coordination comment the classifier cannot attribute gets `protocol:invalid`
 * on its issue — once per event, only on an opted-in repo, and never at the cost of routing.
 *
 * END TO END THROUGH THE DISPATCHER: real agent YAMLs, the real CoordinationClassifier and the real
 * HandlerRegistry; only the two HTTP peers (GitHub and the agents' channel endpoint) are stubbed, by
 * ONE `Http::fake()` per test, because stubs stack and the first match wins. `Http::preventStrayRequests()`
 * is on (here and in `Tests\TestCase`), so an unstubbed request throws instead of leaving the box.
 */
class ProtocolInvalidLabelTest extends TestCase
{
    use RefreshDatabase;

    private const REPO = 'acme/coord';

    private const LABELS_URL = 'https://api.github.com/repos/acme/coord/issues/42/labels';

    private const CHANNEL_URL = 'http://127.0.0.1:8788/';

    private string $dir;

    private string|false $origGhToken;

    /** @var list<array{method: string, url: string, body: string, auth: string}> */
    private array $github = [];

    /** @var list<string> the channel-push bodies the agents' endpoint received */
    private array $pushes = [];

    /** What GitHub answers the label POST with: a status, or `transport` for a failed connection. */
    private int|string $githubAnswer = 200;

    private bool $faked = false;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        ClassifierResolver::flush();
        $this->dir = sys_get_temp_dir().'/protocol-invalid-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/github');
        File::put($this->dir.'/github/token', 'gh-test-token');
        chmod($this->dir.'/github/token', 0o600);
        config([
            'bridge.config_dir' => $this->dir,
            'bridge.secret_dir' => $this->dir,
            'bridge.state_dir' => $this->dir.'/state',
            'bridge.providers.github.credential_helper' => '',
            'bridge.protocol_invalid_label.repos' => ['Acme/Coord'],   // case differs from the scope on purpose
        ]);
        $this->origGhToken = getenv('GH_TOKEN');
        putenv('GH_TOKEN');
        $this->coordAgent('alpha');
    }

    protected function tearDown(): void
    {
        ClassifierResolver::flush();
        File::deleteDirectory($this->dir);
        putenv($this->origGhToken === false ? 'GH_TOKEN' : 'GH_TOKEN='.$this->origGhToken);
        parent::tearDown();
    }

    // --- the write --------------------------------------------------------------------------------

    public function test_an_unattributable_comment_labels_its_issue_with_exactly_one_request(): void
    {
        $this->fakePeers();

        $this->dispatch('d1', $this->comment('created', 'no from line here'));

        $this->assertSame([[
            'method' => 'POST',
            'url' => self::LABELS_URL,
            'body' => '{"labels":["protocol:invalid"]}',
            'auth' => 'Bearer gh-test-token',
        ]], $this->github);
    }

    public function test_a_comment_on_a_pull_request_labels_that_pull_request(): void
    {
        $this->fakePeers();
        $payload = $this->comment('created', 'no from line here', number: 77);
        $payload['issue']['pull_request'] = ['url' => 'https://api.github.com/repos/acme/coord/pulls/77'];

        $this->dispatch('d1', $payload);

        $this->assertSame(['https://api.github.com/repos/acme/coord/issues/77/labels'], array_column($this->github, 'url'));
    }

    public function test_one_event_served_to_several_agents_is_one_label_write(): void
    {
        // alpha is addressed by the thread's `to:alpha` label; beta and gamma are not. Each of the
        // three classifies the event and emits the same target.
        $this->coordAgent('beta');
        $this->coordAgent('gamma');
        $this->fakePeers();

        $this->dispatch('d1', $this->comment('created', 'no from line here'));

        $this->assertCount(1, $this->github);
        $this->assertSame(3, AgentDispatch::query()->whereNotNull('processed_at')->count());
    }

    public function test_the_label_is_written_when_no_agent_is_addressed(): void
    {
        // A malformed thread is the case the label exists for: nobody's `to:` matches it.
        $this->fakePeers();

        $this->dispatch('d1', $this->comment('created', 'no from line here', labels: []));

        $this->assertCount(1, $this->github);
        $this->assertSame([], $this->pushes);
    }

    public function test_a_gated_agent_still_labels_because_the_reaction_is_durable(): void
    {
        // treat_as_signal names another agent, so this event is not a signal for delta: the DL-203
        // strip removes everything agent-facing and keeps only durable targets.
        File::delete($this->dir.'/alpha.yml');
        $this->coordAgent('omega', scopes: ['acme/elsewhere']);
        $this->coordAgent('delta', extra: "echo_suppression:\n  treat_as_signal: [omega]\n");
        $this->fakePeers();

        $this->dispatch('d1', $this->comment('created', 'no from line here', labels: ['to:delta']));

        $this->assertCount(1, $this->github);
        $this->assertSame([], $this->pushes);
        $row = AgentDispatch::query()->where('agent_name', 'delta')->sole();
        $this->assertSame([AgentDispatch::OUTCOME_DELIVERED, 'echo: agent surface suppressed'], [$row->outcome, $row->reason]);
    }

    // --- default OFF --------------------------------------------------------------------------------

    public function test_with_no_repo_listed_nothing_is_written_and_the_ledger_is_what_it_was(): void
    {
        config(['bridge.protocol_invalid_label.repos' => []]);
        $this->coordAgent('beta');
        $this->fakePeers();

        $this->dispatch('d1', $this->comment('created', 'no from line here'));

        $this->assertSame([], $this->github);
        $this->assertSame([
            'alpha' => [AgentDispatch::OUTCOME_DELIVERED, null],
            'beta' => [AgentDispatch::OUTCOME_DROPPED, 'classifier emitted no reactions'],
        ], $this->ledger());
        $this->assertCount(1, $this->pushes);
    }

    public function test_a_listed_repo_changes_only_the_non_addressed_agents_ledger_row_and_nothing_agent_facing(): void
    {
        // The one ledger consequence of the label, stated as a test rather than left to be found:
        // a non-addressed agent's row now carries a reaction (the label), so it reads `delivered`.
        // Nothing agent-facing moves — the same one push, to the same agent, with the same body.
        $this->coordAgent('beta');
        $this->fakePeers();
        config(['bridge.protocol_invalid_label.repos' => []]);
        $this->dispatch('off', $this->comment('created', 'no from line here'));
        $offPushes = $this->pushes;

        $this->pushes = [];
        config(['bridge.protocol_invalid_label.repos' => [self::REPO]]);
        $this->dispatch('on', $this->comment('created', 'no from line here'));

        $this->assertSame($offPushes, $this->pushes);
        $this->assertCount(1, $this->github);
        $this->assertSame(AgentDispatch::OUTCOME_DELIVERED, AgentDispatch::query()->where('agent_name', 'beta')->latest('id')->value('outcome'));
    }

    public function test_an_unlisted_repo_is_not_written_even_when_another_repo_is_listed(): void
    {
        config(['bridge.protocol_invalid_label.repos' => ['acme/other']]);
        $this->fakePeers();

        $this->dispatch('d1', $this->comment('created', 'no from line here'));

        $this->assertSame([], $this->github);
    }

    // --- what it does not fire on ----------------------------------------------------------------

    public function test_an_edited_comment_is_not_labelled_even_when_the_install_surfaces_edits(): void
    {
        // `coord_extra_actions` lets `edited` reach the classifier's subject; without it the event
        // would never be classified and the test would prove nothing.
        $this->coordAgent('alpha', extra: "  config:\n    coord_extra_actions:\n      issue_comment: [edited, deleted]\n", inClassifier: true);
        $this->fakePeers();

        $this->dispatch('d1', $this->comment('edited', 'no from line here'));
        $this->dispatch('d2', $this->comment('deleted', 'no from line here'));

        $this->assertSame([], $this->github);
        $this->assertCount(2, $this->pushes);   // both WERE classified and delivered — just not labelled
    }

    public function test_an_opened_issue_or_pull_request_with_no_attribution_is_not_labelled(): void
    {
        $this->fakePeers();

        $this->dispatch('d1', [
            'action' => 'opened',
            'issue' => ['number' => 42, 'title' => '[QUERY] x', 'body' => 'no from line', 'labels' => [['name' => 'to:alpha']], 'html_url' => 'https://github.com/acme/coord/issues/42'],
            'repository' => ['full_name' => self::REPO], 'sender' => ['id' => 555],
        ], 'issues.opened');
        $this->dispatch('d2', [
            'action' => 'opened',
            'pull_request' => ['number' => 43, 'title' => 'x', 'body' => 'no from line', 'labels' => [['name' => 'to:alpha']], 'html_url' => 'https://github.com/acme/coord/pull/43'],
            'repository' => ['full_name' => self::REPO], 'sender' => ['id' => 555],
        ], 'pull_request.opened');

        $this->assertSame([], $this->github);
        $this->assertCount(2, $this->pushes);
    }

    public function test_a_comment_with_a_from_line_is_not_labelled(): void
    {
        $this->fakePeers();

        $this->dispatch('d1', $this->comment('created', "FROM: beta\nTO: alpha\n\nhello"));

        $this->assertSame([], $this->github);
        $this->assertCount(1, $this->pushes);
    }

    public function test_a_comment_on_a_scope_author_mapped_repo_is_not_labelled(): void
    {
        $this->coordAgent('alpha', extra: "  config:\n    scope_author_map:\n      acme/coord: beta\n", inClassifier: true);
        $this->fakePeers();

        $this->dispatch('d1', $this->comment('created', 'no from line here'));

        $this->assertSame([], $this->github);
    }

    public function test_a_comment_whose_sender_the_registry_names_is_not_labelled(): void
    {
        // A distinct, non-shared account the registry resolves to an agent: attributed, FROM: or not.
        $this->coordAgent('beta', extra: "identity:\n  github_user_id: 555\n", scopes: ['acme/elsewhere']);
        $this->fakePeers();

        $this->dispatch('d1', $this->comment('created', 'no from line here'));

        $this->assertSame([], $this->github);
    }

    public function test_a_scope_where_no_agent_runs_the_coord_message_family_is_not_labelled(): void
    {
        File::delete($this->dir.'/alpha.yml');
        $this->coordAgent('impl', extra: "  config:\n    families: [impl-ci-wake]\n", inClassifier: true);
        File::put($this->dir.'/writeback-agent.yml', "subscriptions:\n  - provider: github\n    scopes: [\"".self::REPO."\"]\n"
            ."classifier:\n  class: '".GitHubPrCardMoveClassifier::class."'\n");
        $this->fakePeers();

        $this->dispatch('d1', $this->comment('created', 'no from line here'));

        $this->assertSame([], $this->github);
    }

    public function test_the_bridges_own_correlation_comment_is_not_labelled(): void
    {
        $this->fakePeers();

        $this->dispatch('d1', $this->comment('created', PrCorrelationComment::MARKER_PREFIX."outcome=merged -->\n**report**", labels: []));

        $this->assertSame([], $this->github);
    }

    // --- failures: one warning, no throw, routing untouched -----------------------------------------

    public function test_no_token_file_writes_nothing_and_logs_one_warning(): void
    {
        File::delete($this->dir.'/github/token');
        $this->assertFailureLeavesRoutingAlone(null, 'token_unresolved');
        $this->assertSame([], $this->github);
    }

    public function test_a_403_is_logged_with_its_status_and_not_retried(): void
    {
        $this->assertFailureLeavesRoutingAlone(403, 'add_refused', 403);
        $this->assertCount(1, $this->github);
    }

    public function test_a_500_is_logged_with_its_status_and_not_retried(): void
    {
        $this->assertFailureLeavesRoutingAlone(500, 'add_refused', 500);
        $this->assertCount(1, $this->github);
    }

    public function test_a_transport_failure_is_logged_and_not_retried(): void
    {
        $this->assertFailureLeavesRoutingAlone('transport', 'add_failed');
        $this->assertCount(1, $this->github);
    }

    public function test_an_unexpected_failure_is_logged_and_does_not_throw(): void
    {
        // A log sink that fails on the success line: outside every named step, after the POST landed.
        $this->fakePeers();
        Log::spy();
        Log::shouldReceive('info')->withArgs(fn (string $message) => $message === 'protocol_invalid_label: applied')
            ->andThrow(new RuntimeException('log sink down'));

        $this->dispatch('d1', $this->comment('created', 'no from line here'));

        $this->assertCount(1, $this->github);
        $this->assertCount(1, $this->pushes);
        $this->assertNull(AgentDispatch::query()->sole()->error_message);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => str_starts_with($message, 'protocol_invalid_label: NOT applied')
            && ($context['reason'] ?? null) === 'unexpected'
            && ($context['catalog_id'] ?? null) === 'protocol_invalid_label.unexpected_failure')->once();
    }

    public function test_the_write_site_refuses_a_target_for_an_unlisted_repo_whoever_emitted_it(): void
    {
        // The opt-in is enforced where the write happens, not only where the shipped classifier
        // decides: a custom classifier can emit this handler's name for any repo.
        $this->fakePeers();
        Log::spy();

        $this->handle(['repo' => 'acme/other', 'number' => 42, 'comment_id' => 1]);
        $this->handle(['repo' => self::REPO, 'number' => '42']);

        $this->assertSame([], $this->github);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => str_starts_with($message, 'protocol_invalid_label: NOT applied')
            && ($context['reason'] ?? null) === 'repo_not_enabled')->once();
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => str_starts_with($message, 'protocol_invalid_label: NOT applied')
            && ($context['reason'] ?? null) === 'payload_invalid')->once();
    }

    // --- helpers ------------------------------------------------------------------------------------

    /**
     * The failure arms share one shape: the delivery completes, the push that routing owed goes out
     * byte-for-byte as it does when the label succeeds, the ledger records no error, and exactly one
     * warning names the step.
     */
    private function assertFailureLeavesRoutingAlone(int|string|null $githubAnswer, string $reason, ?int $status = null): void
    {
        $tokenFile = $this->dir.'/github/token';
        $hadToken = File::exists($tokenFile);
        File::put($tokenFile, 'gh-test-token');
        chmod($tokenFile, 0o600);
        $this->fakePeers();
        $this->dispatch('ok', $this->comment('created', 'no from line here'));
        $pushOnSuccess = $this->pushes;
        $this->assertCount(1, $pushOnSuccess);
        $this->assertCount(1, $this->github);
        $this->github = [];
        $this->pushes = [];
        if (! $hadToken) {
            File::delete($tokenFile);
        }

        $this->githubAnswer = $githubAnswer ?? 200;
        Log::spy();

        $this->dispatch('bad', $this->comment('created', 'no from line here'));

        $this->assertSame($pushOnSuccess, $this->pushes);
        $row = AgentDispatch::query()->latest('id')->firstOrFail();
        $this->assertNotNull($row->processed_at);
        $this->assertNull($row->error_message);
        $this->assertSame(AgentDispatch::OUTCOME_DELIVERED, $row->outcome);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => str_starts_with($message, 'protocol_invalid_label: NOT applied')
            && ($context['reason'] ?? null) === $reason
            && ($status === null || ($context['status'] ?? null) === $status))->once();
    }

    /** @param  array<mixed>  $payload */
    private function handle(array $payload): void
    {
        (new HandlerRegistry)->resolve(ProtocolInvalidLabeler::HANDLER)?->handle(
            ReactionTarget::make(ProtocolInvalidLabeler::HANDLER, 'x', payload: $payload),
            AgentConfig::fromArray('alpha', ['subscriptions' => []]),
        );
    }

    /** @return array<string, array{0: ?string, 1: ?string}> */
    private function ledger(): array
    {
        $out = [];
        foreach (AgentDispatch::query()->orderBy('agent_name')->get() as $row) {
            $out[$row->agent_name] = [$row->outcome, $row->reason];
        }

        return $out;
    }

    /** @param  list<string>  $scopes */
    private function coordAgent(string $name, string $extra = '', array $scopes = [self::REPO], bool $inClassifier = false): void
    {
        $yaml = "subscriptions:\n  - provider: github\n    scopes: [\"".implode('", "', $scopes)."\"]\n"
            ."classifier:\n  class: '".CoordinationClassifier::class."'\n"
            .($inClassifier ? $extra : '')
            ."channel:\n  url: ".self::CHANNEL_URL."\n  route_intents: false\n"
            .($inClassifier ? '' : $extra);
        File::put($this->dir."/{$name}.yml", $yaml);
    }

    /**
     * @param  list<string>  $labels  the thread's labels
     * @return array<string, mixed>
     */
    private function comment(string $action, string $body, int $number = 42, array $labels = ['to:alpha'], int $commentId = 9001): array
    {
        return [
            'action' => $action,
            'issue' => [
                'number' => $number,
                'title' => '[QUERY] a thread',
                'labels' => array_map(fn (string $n) => ['name' => $n], $labels),
                'html_url' => 'https://github.com/'.self::REPO.'/issues/'.$number,
            ],
            'comment' => [
                'id' => $commentId,
                'body' => $body,
                'html_url' => 'https://github.com/'.self::REPO.'/issues/'.$number.'#issuecomment-'.$commentId,
                'created_at' => '2026-09-22T00:00:00Z',
            ],
            'repository' => ['full_name' => self::REPO],
            'sender' => ['id' => 555, 'login' => 'shared-account'],
        ];
    }

    /**
     * The ONE `Http::fake()` a test gets. Stubs registered by a second call do not replace these:
     * every stacked callback RUNS (so a second recorder would record the same request again) and the
     * first non-null answer wins (so a second status would never be answered). A test changes what
     * GitHub answers through {@see $githubAnswer} instead.
     */
    private function fakePeers(): void
    {
        if ($this->faked) {
            return;
        }
        $this->faked = true;
        Http::fake([
            'https://api.github.com/*' => function (Request $request) {
                $githubAnswer = $this->githubAnswer;
                $this->github[] = [
                    'method' => $request->method(),
                    'url' => $request->url(),
                    'body' => $request->body(),
                    'auth' => $request->header('Authorization')[0] ?? '',
                ];
                if ($githubAnswer === 'transport') {
                    return Http::failedConnection()($request);
                }

                return Http::response($githubAnswer < 300 ? [['name' => ProtocolInvalidLabeler::LABEL]] : ['message' => 'Resource not accessible by personal access token'], $githubAnswer);
            },
            self::CHANNEL_URL.'*' => function (Request $request) {
                $this->pushes[] = $request->body();

                return Http::response('ok', 200);
            },
        ]);
    }

    /** @param  array<string, mixed>  $payload */
    private function dispatch(string $deliveryId, array $payload, ?string $eventType = null): void
    {
        $subs = new SubscriptionRegistry($this->dir);
        (new DispatchService(
            $subs,
            AgentRegistry::fromAgentConfigs($subs->agentConfigs(), AgentRegistry::loadSharedIdentities($this->dir)),
            new HandlerRegistry,
            new IntentLog,
        ))->dispatch('github', self::REPO, new EventDto(
            deliveryId: $deliveryId,
            scopeId: self::REPO,
            eventType: $eventType ?? 'issue_comment.'.$payload['action'],
            actorId: '555',
        ), $payload);
    }
}
