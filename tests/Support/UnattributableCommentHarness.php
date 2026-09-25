<?php

namespace Tests\Support;

use App\Bridge\Adapters\EventDto;
use App\Bridge\Classifiers\CoordinationClassifier;
use App\Bridge\Dispatch\DispatchService;
use App\Bridge\Dispatch\IntentLog;
use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\AgentRegistry;
use App\Bridge\Support\ClassifierResolver;
use App\Bridge\Support\HandlerRegistry;
use App\Bridge\Support\SubscriptionRegistry;
use App\Bridge\Writeback\ProtocolInvalidLabeler;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * The world an unattributable coordination comment arrives in (card#10218 / DL-408): an install
 * with one coordination agent, an opted-in repo, a placed GitHub token, and both HTTP peers stubbed.
 *
 * ⭐ HOISTED AT THE SECOND CALLER, not written twice. `ProtocolInvalidLabelTest` owns the write and
 * `ProtocolInvalidLabelRepairTest` (card#10242 / DL-419) owns what happens to a write that did not
 * land; a second copy of this fixture would let the two tests exercise different installs while
 * reading as if they exercised one.
 *
 * ⛔ ONE `Http::fake()` PER TEST. Stubs registered by a second call do not replace these: every
 * stacked callback RUNS and the first non-null answer wins, so a second status would never be
 * answered. A test changes what GitHub answers through {@see $githubAnswer} / {@see $githubOkBody}
 * instead, and may change it BETWEEN requests — which is what makes a refusal-then-recovery
 * sequence expressible.
 */
trait UnattributableCommentHarness
{
    protected const REPO = 'acme/coord';

    protected const LABELS_URL = 'https://api.github.com/repos/acme/coord/issues/42/labels';

    protected const CHANNEL_URL = 'http://127.0.0.1:8788/';

    protected string $dir;

    protected string|false $origGhToken;

    /** @var list<array{method: string, url: string, body: string, auth: string}> */
    protected array $github = [];

    /** @var list<string> the channel-push bodies the agents' endpoint received */
    protected array $pushes = [];

    /** What GitHub answers the label POST with: a status, or `transport` for a failed connection. */
    protected int|string $githubAnswer = 200;

    /**
     * The body a 2xx answers with. Null is what GitHub really answers `POST .../labels` with — the
     * labels now on the thread — so a test that leaves it alone gets a CONFIRMED add.
     *
     * @var ?list<array<string, mixed>>
     */
    protected ?array $githubOkBody = null;

    private bool $faked = false;

    protected function setUpUnattributableComment(): void
    {
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

    protected function tearDownUnattributableComment(): void
    {
        ClassifierResolver::flush();
        File::deleteDirectory($this->dir);
        putenv($this->origGhToken === false ? 'GH_TOKEN' : 'GH_TOKEN='.$this->origGhToken);
    }

    /** @param  list<string>  $scopes */
    protected function coordAgent(string $name, string $extra = '', array $scopes = [self::REPO], bool $inClassifier = false): void
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
    protected function comment(string $action, string $body, int $number = 42, array $labels = ['to:alpha'], int $commentId = 9001): array
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

    /** The ONE `Http::fake()` a test gets — see this trait's docblock for why it is one. */
    protected function fakePeers(): void
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
                if ($githubAnswer < 300) {
                    return Http::response($this->githubOkBody ?? [['name' => ProtocolInvalidLabeler::LABEL]], $githubAnswer);
                }

                return Http::response(['message' => 'Resource not accessible by personal access token'], $githubAnswer);
            },
            self::CHANNEL_URL.'*' => function (Request $request) {
                $this->pushes[] = $request->body();

                return Http::response('ok', 200);
            },
        ]);
    }

    /**
     * Run the label handler directly on a classifier-emitted target — the write site's own gate,
     * reached without a delivery.
     *
     * @param  array<mixed>  $payload
     */
    protected function handle(array $payload): void
    {
        (new HandlerRegistry)->resolve(ProtocolInvalidLabeler::HANDLER)?->handle(
            ReactionTarget::make(ProtocolInvalidLabeler::HANDLER, 'x', payload: $payload),
            AgentConfig::fromArray('alpha', ['subscriptions' => []]),
        );
    }

    /** @param  array<string, mixed>  $payload */
    protected function dispatch(string $deliveryId, array $payload, ?string $eventType = null, ?SubscriptionRegistry $subs = null): void
    {
        $subs ??= new SubscriptionRegistry($this->dir);
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
