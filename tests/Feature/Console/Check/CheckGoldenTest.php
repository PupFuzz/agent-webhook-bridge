<?php

namespace Tests\Feature\Console\Check;

use App\Bridge\Retention\RetentionGate;
use App\Bridge\Tools\SshProbeEnvironment;
use App\Models\BoardToolsClientCall;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CheckGolden\BootsGoldenInstall;
use Tests\Support\CheckGolden\GoldenCapture;
use Tests\Support\CheckGolden\GoldenChannelEnvironment;
use Tests\Support\CheckGolden\GoldenInstall;
use Tests\Support\CheckGolden\GoldenSshEnvironment;
use Tests\TestCase;

/**
 * The DL-242 stage-0 golden-output harness.
 *
 * Stages 1-7 move one check unit at a time out of `CheckCommand::handle()` into a
 * `Check`/`PerAgentCheck` registered at the same ordinal position, under a
 * byte-identical output contract. THIS is the thing that makes that contract a
 * measurement. Stage 0 exists to prove the contract is achievable at all — if it were
 * not, the strangler approach would have been re-planned for the cost of one stage
 * rather than half-executed.
 *
 * Each fixture is one install SHAPE, and its golden file is the exact bytes
 * `bridge:check` prints for it. When a stage 1-7 diff reds one of these, the refactor
 * changed behavior — that is the harness working, not the harness being wrong.
 *
 * WHAT THIS DOES NOT COVER, stated because an unstated bound reads as a guarantee: an
 * install shape absent from these fixtures can change silently, and so can a predicate
 * whose branches print identical bytes. `docs/check-golden-coverage.md` names those
 * gaps individually — and derives them by MUTATION rather than by assertion, because
 * "this fixture covers that branch" is a claim and "flipping that branch reds a golden
 * file" is evidence. That file is itself bounded to `CheckCommand::handle()`, so it
 * enumerates nothing belonging to a check that has already migrated into the registry;
 * absence from it is not protection.
 */
class CheckGoldenTest extends TestCase
{
    use BootsGoldenInstall;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->tearDownGoldenInstall();
        parent::tearDown();
    }

    /**
     * Set up the named install shape and return the args + host pins it needs.
     *
     * @return array{args: array<string, mixed>, fpm: bool, coordConfig: string|null}
     */
    private function buildFixture(GoldenInstall $i, string $name): array
    {
        $default = ['args' => [], 'fpm' => false, 'coordConfig' => null];

        switch ($name) {
            // ---- the baseline shape, and the one host input that changes it ----
            case 'minimal':
                // One kanban-only agent, no writeback, no channel: the smallest install
                // that exits 0. Everything else is this plus one thing.
                $i->boot()->agent('prod-agent', $this->kanbanAgentYaml());

                return $default;

            case 'minimal-fpm-present':
                // Identical fixture, opposite host. The ONLY difference from `minimal`
                // is whether a php-fpm binary is on PATH (read by
                // `RetentionPostureCheck::earlyFinishIndicated()`), which is
                // reached on the default retention-on path — so it moves nearly every
                // install's output. This pair is the potency proof for that pin.
                $i->boot()->agent('prod-agent', $this->kanbanAgentYaml());

                return ['args' => [], 'fpm' => true, 'coordConfig' => null];

                // ---- the top-of-handle() install shell ----
            case 'config-dir-missing':
                $i->boot();
                config(['bridge.config_dir' => $i->path('does-not-exist')]);

                return $default;

            case 'secret-dir-unset':
                $i->boot()->agent('prod-agent', $this->kanbanAgentYaml());
                config(['bridge.secret_dir' => null]);

                return $default;

            case 'provider-without-adapter':
                $i->boot()->agent('prod-agent', $this->kanbanAgentYaml());
                config(['bridge.providers.gitlab' => ['api_base_url' => 'https://gitlab.example.com/api/v4']]);

                return $default;

            case 'bad-receiver-url':
                $i->boot()->agent('prod-agent', $this->kanbanAgentYaml());
                config(['bridge.receiver_base_url' => 'not-a-url']);

                return $default;

            case 'default-agent-has-no-config':
                $i->boot()->agent('prod-agent', $this->kanbanAgentYaml());
                config(['bridge.default_agent' => 'ghost-agent']);

                return $default;

                // ---- retention: all four postures ----
            case 'retention-disabled':
                $i->boot()->agent('prod-agent', $this->kanbanAgentYaml());
                config(['bridge.retention.enabled' => false]);

                return $default;

            case 'retention-misconfigured':
                $i->boot()->agent('prod-agent', $this->kanbanAgentYaml());
                config(['bridge.retention.older_than' => 'not-a-window']);

                return $default;

            case 'retention-last-pass-failed':
                $i->boot()->agent('prod-agent', $this->kanbanAgentYaml());
                // The ambient-cache input, set deliberately. `at` is a literal, not a
                // clock read — a captured timestamp would make the golden file expire.
                Cache::put(RetentionGate::ERROR_KEY, [
                    'exception' => 'RuntimeException',
                    'error' => 'disk full',
                    'at' => '2026-01-01T00:00:00+00:00',
                ], 60);

                return $default;

                // ---- per-agent legs (the interleaved output constraint (b) protects) ----
            case 'agent-yaml-malformed':
                $i->boot()->agent('prod-agent', "identity:\n  kanban_user_id: 137\nsubscriptions: [\n");

                return $default;

            case 'agent-classifier-missing':
                $i->boot()->agent('prod-agent', "identity:\n  kanban_user_id: 137\n"
                    ."subscriptions:\n  - provider: kanban\n    scopes: [5]\n"
                    ."classifier:\n  class: App\\Bridge\\Classifiers\\NoSuchClassifier\n");

                return $default;

            case 'agent-missing-secret-and-token':
                // github subscription with no secret file and no API token: two warns
                // per subscription, the provisioning-pending shape.
                $i->boot()->agent('prod-agent', "identity:\n  github_user_id: 555\n"
                    ."subscriptions:\n  - provider: github\n    scopes: [\"owner/repo\"]\n"
                    ."classifier:\n  class: App\\Bridge\\Classifiers\\GitHubPrCardMoveClassifier\n");

                return $default;

            case 'agent-channel-socket-parent-missing':
                $i->boot()->agent('prod-agent', "identity:\n  kanban_user_id: 137\n"
                    ."subscriptions:\n  - provider: kanban\n    scopes: [5]\n"
                    ."channel:\n  socket: ".$i->path('no-such-dir/agent.sock')."\n");

                return $default;

            case 'agent-channel-http-no-port':
                $i->boot()->agent('prod-agent', "identity:\n  kanban_user_id: 137\n"
                    ."subscriptions:\n  - provider: kanban\n    scopes: [5]\n"
                    ."channel:\n  url: http://127.0.0.1/push\n");

                return $default;

            case 'channel-probe-pin-potency':
                // NOT a golden fixture — deliberately absent from `fixtures()`. It is the
                // only install shape that reaches a channel liveness probe, and adding it
                // to the golden set mid-migration would move the measured baseline, so a
                // later reader could not tell "the measurement improved" from "the
                // measured region moved" (DL-242 stage 5b). It exists solely so the probe
                // pin has a way to fail.
                $i->boot()->agent('prod-agent', "identity:\n  kanban_user_id: 137\n"
                    ."subscriptions:\n  - provider: kanban\n    scopes: [5]\n"
                    ."channel:\n  url: http://127.0.0.1:9/push\n");

                return $default;

            case 'agent-ci-failure-patterns-and-wake-membership':
                // Two lazily-parsed classifier config keys that first throw at this
                // command, both on their WARN path.
                $i->boot()->agent('prod-agent', "identity:\n  github_user_id: 555\n"
                    ."subscriptions:\n  - provider: github\n    scopes: [\"owner/repo\"]\n"
                    ."classifier:\n  class: App\\Bridge\\Classifiers\\CoordinationClassifier\n"
                    ."  config:\n    families: [coord-message, impl-ci-wake]\n"
                    ."    ci_failure_workflow_patterns: [\"Laravel Tests\"]\n"
                    ."    wake_membership: [to_me]\n");

                return $default;

            case 'shared-identities-present':
                $i->boot()
                    ->agent('prod-agent', $this->kanbanAgentYaml())
                    ->json('shared-identities.json', ['shared-account' => ['kanban_user_id' => 137]]);

                return $default;

                // ---- writeback: config-only legs, no client ----
            case 'writeback-orphaned-mapping':
                // No token ⇒ the client cannot be constructed. The orphan check must
                // still fire: it reads only in-memory state, and a half-configured
                // install is exactly where an orphan is most likely.
                $i->boot()
                    ->agent('prod-agent', $this->kanbanAgentYaml())
                    ->json('writeback.json', ['identity_id' => 4242, 'mappings' => [
                        'owner/repo' => ['board_id' => 8, 'stages' => ['merged' => 52]],
                    ]]);
                Http::fake();

                return $default;

            case 'writeback-orphan-survives-unrelated-unread-agent':
                // THE PRECISION CONTROL for card#5698, and the reason the coverage ledger
                // records a SCOPE SET rather than a count. The agent aborts — so the run's
                // roster is incomplete — but it aborted at the CLASSIFIER gate, with its
                // config already parsed, and it subscribes to `other/repo`. It therefore
                // cannot be the missing driver of `owner/repo`, and the ORPHANED warn must
                // survive unchanged. A ledger that only counted aborts would soften it.
                $i->boot()
                    ->agent('prod-agent', "identity:\n  github_user_id: 555\n"
                        ."subscriptions:\n  - provider: github\n    scopes: [\"other/repo\"]\n"
                        ."classifier:\n  class: App\\Bridge\\Classifiers\\NoSuchClassifier\n")
                    ->json('writeback.json', ['identity_id' => 4242, 'mappings' => [
                        'owner/repo' => ['board_id' => 8, 'stages' => ['merged' => 52]],
                    ]]);
                Http::fake();

                return $default;

            case 'writeback-move-leg-agent-unread':
                // All THREE card#5698 map-fed legs in one run, which is the only place they
                // can be seen at the real command surface: no fixture before this one
                // combined an aborted agent with a writeback mapping, so the corpus could
                // not tell a leg that answered from one that never could.
                //   - the orphan leg, whose ORPHANED accusation is not evidence here;
                //   - the coord-card-move family gate, whose "no agent enables it" is the
                //     same non-evidence in the mirror direction;
                //   - the MANDATORY coord-terminal preflight, which this gate would
                //     otherwise skip in silence on a mapping that opted into the move leg.
                $this->moveLegInstall($i);
                // Overwrite the healthy agent in place: the mapping, token and board stubs
                // must survive, because the point is a well-formed writeback plane read by
                // a run that could not finish reading its agents.
                $i->agent('prod-agent', "identity:\n  kanban_user_id: 137\nsubscriptions: [\n");

                return $default;

            case 'writeback-half-configured-triggers':
                // The silent-inert config shapes: `started` half-set, revive_on_reopen
                // without its two stages, create_coord_cards with no identity_id.
                $i->boot()
                    ->agent('prod-agent', $this->kanbanAgentYaml())
                    ->json('writeback.json', ['mappings' => [
                        'owner/repo' => [
                            'board_id' => 8,
                            'stages' => ['merged' => 52, 'started' => 49],
                            'revive_on_reopen' => true,
                            'create_coord_cards' => true,
                            'coord_card_stage_id' => 48,
                        ],
                    ]]);
                Http::fake();

                return $default;

            case 'writeback-malformed':
                $i->boot()->agent('prod-agent', $this->kanbanAgentYaml());
                // Not via json(): the point is that it does not parse.
                file_put_contents($i->path('writeback.json'), '{ this is not json');

                return $default;

                // ---- writeback: the deep leg that reaches the board client ----
            case 'writeback-move-leg-coord-config-unset':
                $this->moveLegInstall($i);

                return $default;

            case 'writeback-move-leg-coord-config-unreadable':
                // The PROVEN divergence, pinned as its own fixture. Same verdict class
                // as the unset case, DIFFERENT diagnosis and DIFFERENT operator
                // instruction — which is why it must not be normalized away.
                $this->moveLegInstall($i);

                return ['args' => [], 'fpm' => false, 'coordConfig' => $i->path('absent-coord.json')];

            case 'writeback-move-leg-coord-config-agrees':
                $this->moveLegInstall($i);
                $i->json('coordination.config.json', ['kanban' => ['boards' => [
                    ['key' => 'issues', 'board_id' => 8, 'reference_type' => 'product', 'user_lanes' => ['Now']],
                ]]]);

                return ['args' => [], 'fpm' => false, 'coordConfig' => $i->path('coordination.config.json')];

            case 'writeback-board-unreadable':
                // The two 500s replace the matching defaults' entries, so they sit ahead
                // of the '*' catch-all — where a later Http::fake() would land behind it
                // (see CLAUDE_GOTCHAS.md G-020). Scoped to the
                // KANBAN board reads rather than blanketed over `'*'`: a blanket 500 also
                // fails the github token probe, so the fixture would carry a second,
                // unrelated diagnosis and a later diff could not say which leg moved.
                $this->moveLegInstall($i, [
                    '*/tasks/search.json*' => Http::response(['message' => 'nope'], 500),
                    '*/boards/8/preload.json' => Http::response(['message' => 'nope'], 500),
                ]);

                return $default;

            case 'writeback-swimlane-collection-absent':
                // card#5698. Before this slice NO fixture could witness the class at all:
                // `moveLegInstall`'s preload already omits `data.swimlanes`, but its mapping
                // declares no `swimlane_id`, so the leg that would have lied never ran — the
                // same corpus blindness DL-255 recorded for the scope maps. This fixture is
                // the witness: a mapping that DOES declare a lane, against a preload carrying
                // no lane collection. Pre-fix it printed a confident "swimlane_id 4 not found
                // on board 8 … (a deleted lane, or a lane on a different board)".
                //
                // The stage list is kept WELL-FORMED on purpose, so the only thing this
                // fixture's output can move is the lane line — a preload broken in two ways
                // would not say which leg the diff belongs to.
                $this->moveLegInstall($i);
                $i->json('writeback.json', ['identity_id' => 4242, 'mappings' => [
                    'owner/repo' => [
                        'board_id' => 8,
                        'swimlane_id' => 4,
                        'stages' => ['opened' => 50],
                    ],
                ]]);

                return $default;

                // ---- board_tools + the opt-in probes ----
            case 'board-tools-http-enabled':
                $i->boot()
                    ->agent('prod-agent', "identity:\n  kanban_user_id: 137\n"
                        ."subscriptions:\n  - provider: kanban\n    scopes: [5]\n"
                        // The bearer sits on `board_tools.auth`, NOT on `channel.auth`:
                        // a channel bearer is legal only alongside `channel.url`, so the
                        // first cut of this fixture threw at AgentConfig::load and the
                        // whole board-tools plane was unreachable (card#5552). Keeping
                        // the block channel-free also keeps this the http TWIN of the ssh
                        // fixtures — they differ in the transport and nothing else — and
                        // leaves "no golden fixture reaches a channel probe" true, which
                        // {@see GoldenChannelEnvironment} still rests on.
                        ."board_tools:\n  transport: http\n  board_id: 10\n  swimlane_id: 4\n  create_stage_id: 55\n"
                        ."  auth:\n    token_path: ".$i->path('tools-bearer')."\n")
                    ->secret('tools-bearer', 'bearer-value')
                    ->secret('kanban/writeback-token', 'wb-token');
                Http::fake([
                    '*/tasks/search.json*' => Http::response(['data' => [], 'meta' => ['total' => 0]]),
                    '*/boards/10/preload.json' => Http::response(['data' => ['workflows' => [['stages' => [
                        ['id' => 55, 'name' => 'Backlog', 'position' => 1024.0],
                    ]]], 'swimlanes' => [['id' => 4, 'name' => 'Default']]]]),
                ]);

                return $default;

            case 'board-tools-client-half-wired':
                // DL-313, the OK arm — and the only fixture that reaches it. Its twin is
                // `board-tools-http-enabled`, which is the SAME install with no recorded
                // call: the pair is the whole leg, because the UNREPORTED line and the
                // WIRED line are the two verdicts and neither is legible without the other.
                //
                // ⚑ THE STAMP IS RELATIVE, NOT ABSOLUTE, which is the opposite of what the
                // event-consumer fixtures do — and deliberately: those print an absolute
                // last-seen, so a clock read would expire the golden file overnight, while
                // this leg prints an AGE, so an absolute stamp would expire it instead. The
                // half-hour of slack is what keeps `3h` floored at 3 for any run of this
                // suite that finishes inside 30 minutes.
                $i->boot()
                    ->agent('prod-agent', "identity:\n  kanban_user_id: 137\n"
                        ."subscriptions:\n  - provider: kanban\n    scopes: [5]\n"
                        ."board_tools:\n  transport: http\n  board_id: 10\n  swimlane_id: 4\n  create_stage_id: 55\n"
                        ."  auth:\n    token_path: ".$i->path('tools-bearer')."\n")
                    ->secret('tools-bearer', 'bearer-value')
                    ->secret('kanban/writeback-token', 'wb-token');
                BoardToolsClientCall::query()->create([
                    'agent' => 'prod-agent',
                    'transport' => 'http',
                    'last_success_at' => now()->subHours(3)->subMinutes(30),
                ]);
                Http::fake([
                    '*/tasks/search.json*' => Http::response(['data' => [], 'meta' => ['total' => 0]]),
                    '*/boards/10/preload.json' => Http::response(['data' => ['workflows' => [['stages' => [
                        ['id' => 55, 'name' => 'Backlog', 'position' => 1024.0],
                    ]]], 'swimlanes' => [['id' => 4, 'name' => 'Default']]]]),
                ]);

                return $default;

                // ---- board_tools over ssh (DL-242 stage 1) ----
                // Before stage 1 no fixture reached the ssh legs at all, so the golden
                // suite was green on two of the three call sites that stage migrates.
                // These four make that green mean something.
            case 'board-tools-ssh-pinned-line':
                // The certified shape: explicit `transport: ssh`, a good forced-command
                // line, non-root. Exercises the per-agent pinned-line check's ok path.
                $this->sshInstall($i, transport: "  transport: ssh\n");
                $this->app->instance(SshProbeEnvironment::class, new GoldenSshEnvironment(
                    authorizedKeys: self::GOOD_PINNED_LINE,
                ));

                return $default;

            case 'board-tools-ssh-default-transport-advisory':
                // The DL-225 pre-upgrade advisory: no explicit `transport:` key (so the
                // v0.68.0 flipped default lands the agent on ssh) AND an unverifiable
                // setup. The advisory reads the pinned-line SEVERITIES back off the
                // check's report, so this fixture is what proves that wiring survived.
                $this->sshInstall($i, transport: '');
                $this->app->instance(SshProbeEnvironment::class, new GoldenSshEnvironment);

                return $default;

            case 'board-tools-ssh-live-probe':
                // The opt-in live round trip, certified: a clean envelope whose scope
                // header identifies the configured agent. No fixture reached this leg before.
                // The envelope is the shape a CURRENT responder answers (DL-302:
                // configured_board_id is the header; board_id is the row reading) — pinning
                // the pre-DL-302 spelling here would make this, the only end-to-end witness
                // of the ok line, an exercise of the version-skew fallback instead. Both
                // probes' fallbacks are unit-covered; the golden must show the live shape.
                $this->sshInstall($i, transport: "  transport: ssh\n");
                $this->app->instance(SshProbeEnvironment::class, new GoldenSshEnvironment(
                    authorizedKeys: self::GOOD_PINNED_LINE,
                    roundTrip: ['exit' => 0, 'stdout' => '{"ok":true,"result":{"board_id":null,"board_observed":false,"configured_board_id":10,"swimlane_id":4}}', 'stderr' => ''],
                ));

                return ['args' => ['--probe-tools-ssh' => 'bridge@host-a'], 'fpm' => false, 'coordConfig' => null];

            case 'probe-tools-ssh-with-no-ssh-agent':
                // The opt-in probe's REQUESTED-but-nothing-to-certify path, the ssh twin of
                // `probe-tools-with-no-enabled-agent`. It prints one warn and STILL DOES
                // after stage 8 — an earlier version of this comment predicted the state
                // would become a `not requested`/`not applicable` disposition, which
                // CONFLATED TWO AXES. The resolved opt-in decision bounds itself to the
                // flag's ABSENCE; here the flag was GIVEN, so an answer is owed, and
                // re-assigning that warn was card#5291's separately-gated sweep, which
                // adjudicated it and KEPT it (DL-251): the leg answered its own question.
                $i->boot()->agent('prod-agent', $this->kanbanAgentYaml());
                $this->app->instance(SshProbeEnvironment::class, new GoldenSshEnvironment);

                return ['args' => ['--probe-tools-ssh' => 'bridge@host-a'], 'fpm' => false, 'coordConfig' => null];

            case 'probe-tools-with-no-enabled-agent':
                // The http twin of the above: --probe-tools GIVEN, no enabled agent. It
                // prints a warn and still does. What stage 8 changed here is the INVENTORY
                // line, not this warn: because the flag was passed, that probe RAN, so this
                // fixture reports one opt-in probe not requested where its control
                // `no-opt-in-probes-requested` reports two — asserted in
                // test_the_opt_in_control_pair_now_differs_in_the_inventory_too().
                $i->boot()->agent('prod-agent', $this->kanbanAgentYaml());

                return ['args' => ['--probe-tools' => 'https://bridge.example.com/agent-tools/call'], 'fpm' => false, 'coordConfig' => null];

            case 'no-opt-in-probes-requested':
                // The default invocation of the fixture above's install, plus an inert
                // writeback token (inert because there is no writeback.json to use it — the
                // pairing holds, and saying "the same install" would not). The opt-in legs
                // print NOTHING; pairing it with the fixture above is what makes a stage-8
                // change to the opt-in disposition visible in a diff. That is also why it
                // is byte-identical to `minimal` BY DESIGN — see CONTROL_PAIRS.
                $i->boot()->agent('prod-agent', $this->kanbanAgentYaml())
                    ->secret('kanban/writeback-token', 'wb-token');

                return $default;

                // ---- event-follows-consumer (reads the bridge's own inbound history) ----
            case 'event-consumer-unconsumed-type':
                $i->boot()->agent('wb', "identity:\n  github_user_id: 555\n"
                    ."subscriptions:\n  - provider: github\n    scopes: [\"owner/repo\"]\n"
                    ."classifier:\n  class: App\\Bridge\\Classifiers\\GitHubPrCardMoveClassifier\n");
                // received_at is pinned: it is printed as the warn's last-seen, so a
                // clock read here would make the golden file expire overnight.
                $this->githubEvent('pull_request.opened', 'e1');
                $this->githubEvent('issues.closed', 'e2');

                return $default;

            case 'event-consumer-nothing-arrived':
                $i->boot()->agent('wb', "identity:\n  github_user_id: 555\n"
                    ."subscriptions:\n  - provider: github\n    scopes: [\"owner/repo\"]\n"
                    ."classifier:\n  class: App\\Bridge\\Classifiers\\GitHubPrCardMoveClassifier\n");

                return $default;

            case 'event-consumer-declaration-unreadable':
                // The card#5698 witness, and the DISCRIMINATING PAIR for the fixture two
                // cases up: the same scope, the same arrival, the same unconsumed type —
                // differing only in that this classifier's declaration threw. Its golden
                // file therefore shows the whole delta between an asserted verdict and a
                // withheld one, which no single-fixture capture could.
                $i->boot()->agent('wb', "identity:\n  github_user_id: 555\n"
                    ."subscriptions:\n  - provider: github\n    scopes: [\"owner/repo\"]\n"
                    .'classifier:'."\n".'  class: Tests\Fixtures\UnreadableDeclarationClassifier'."\n");
                $this->githubEvent('issues.closed', 'e1');

                return $default;
        }

        $this->fail("unknown golden fixture: {$name}");
    }

    /** @return list<array{string}> */
    public static function fixtures(): array
    {
        return array_map(fn (string $n) => [$n], [
            'minimal',
            'minimal-fpm-present',
            'config-dir-missing',
            'secret-dir-unset',
            'provider-without-adapter',
            'bad-receiver-url',
            'default-agent-has-no-config',
            'retention-disabled',
            'retention-misconfigured',
            'retention-last-pass-failed',
            'agent-yaml-malformed',
            'agent-classifier-missing',
            'agent-missing-secret-and-token',
            'agent-channel-socket-parent-missing',
            'agent-channel-http-no-port',
            'agent-ci-failure-patterns-and-wake-membership',
            'shared-identities-present',
            'writeback-orphaned-mapping',
            'writeback-orphan-survives-unrelated-unread-agent',
            'writeback-move-leg-agent-unread',
            'writeback-half-configured-triggers',
            'writeback-malformed',
            'writeback-move-leg-coord-config-unset',
            'writeback-move-leg-coord-config-unreadable',
            'writeback-move-leg-coord-config-agrees',
            'writeback-board-unreadable',
            'writeback-swimlane-collection-absent',
            'board-tools-http-enabled',
            'board-tools-client-half-wired',
            'board-tools-ssh-pinned-line',
            'board-tools-ssh-default-transport-advisory',
            'board-tools-ssh-live-probe',
            'probe-tools-ssh-with-no-ssh-agent',
            'probe-tools-with-no-enabled-agent',
            'no-opt-in-probes-requested',
            'event-consumer-unconsumed-type',
            'event-consumer-nothing-arrived',
            'event-consumer-declaration-unreadable',
        ]);
    }

    /**
     * What each fixture must PROVE it reached: one or more substrings its rendered output
     * has to contain, keyed by fixture name.
     *
     * THE INVARIANT THIS EXISTS FOR (card#5553). A golden file is captured once and
     * thereafter only ever compared against ITSELF, so a fixture that never reached the
     * install shape it is named for is indistinguishable from one that works — it just
     * pins some other, healthier output forever. Three fixtures were in exactly that state
     * with three unrelated causes (a config-load abort, a shadowed `Http::fake` stub, a
     * harness ordering defect; card#5552), which is one missing invariant rather than three
     * mistakes. The program already refuses a check that cannot fail and an empty result
     * that stands in for a measurement; this applies the same bar to the FIXTURES those
     * instruments run on.
     *
     * A subject is deliberately NOT required to be unique across fixtures — several
     * fixtures legitimately share a line. Distinctness is a separate property, asserted by
     * {@see test_every_golden_file_is_distinct_except_the_declared_control_pairs()}.
     *
     * @return array<string, list<string>>
     */
    private static function subjects(): array
    {
        return [
            // ---- the baseline shape, and the one host input that changes it ----
            'minimal' => ['exit: 0', 'agent config ok: prod-agent'],
            'minimal-fpm-present' => ['retention: on (delete >30d'],

            // ---- the top-of-handle() install shell ----
            // The subject moved with card#5698: `is_dir()` cannot tell an absent dir from an
            // untraversable one, so the leg names both causes. Anchored on the invariant
            // stem rather than the full sentence — the remediation prose is operator text.
            'config-dir-missing' => ['config dir is not usable:'],
            'secret-dir-unset' => ['bridge.secret_dir (BRIDGE_SECRET_DIR) is not set or not absolute'],
            'provider-without-adapter' => ['bridge.providers.gitlab is configured but has no adapter'],
            'bad-receiver-url' => ["bridge.receiver_base_url 'not-a-url' must use http or https"],
            'default-agent-has-no-config' => ["BRIDGE_DEFAULT_AGENT 'ghost-agent' has no matching config"],

            // ---- retention: all four postures ----
            'retention-disabled' => ['retention: DISABLED (BRIDGE_RETENTION_ENABLED=false)'],
            'retention-misconfigured' => ['retention: enabled but MISCONFIGURED'],
            'retention-last-pass-failed' => ['retention: the LAST PASS FAILED'],

            // ---- per-agent legs ----
            'agent-yaml-malformed' => ['is not valid YAML'],
            'agent-classifier-missing' => ['classifier class App\Bridge\Classifiers\NoSuchClassifier not found'],
            // Named for BOTH warns, so both are asserted — a one-string subject would let
            // either half rot silently.
            'agent-missing-secret-and-token' => [
                'github:owner/repo has no secret at',
                'github API token not readable at',
            ],
            'agent-channel-socket-parent-missing' => ['channel.socket parent dir'],
            'agent-channel-http-no-port' => ['has no explicit port — cannot liveness-probe the HTTP channel.'],
            'agent-ci-failure-patterns-and-wake-membership' => [
                'classifier.config.ci_failure_workflow_patterns',
                'classifier.config.wake_membership',
            ],
            // The ZERO is the subject, not an accident: this fixture writes the file
            // without the `shared_identities` wrapper key, so it pins the file-PRESENT
            // branch at an empty list. `SharedIdentitiesCheck`'s docblock owns that
            // disclosure and names the unit test covering a non-zero count — if this ever
            // renders a non-zero count, update that disclosure rather than this line.
            'shared-identities-present' => ['shared-identities.json: 0 shared account(s)'],

            // ---- writeback: config-only legs, no client ----
            'writeback-orphaned-mapping' => ['writeback: mapping for owner/repo is ORPHANED'],
            // BOTH halves are subjects, and that is the point of the pair: the abort has to
            // be reached (else this is `writeback-orphaned-mapping` under another name) AND
            // the ORPHANED accusation has to SURVIVE it (else the ledger is blanketing every
            // scope instead of the ones an unread agent could actually cover).
            'writeback-orphan-survives-unrelated-unread-agent' => [
                'classifier class App\Bridge\Classifiers\NoSuchClassifier not found',
                'writeback: mapping for owner/repo is ORPHANED',
            ],
            'writeback-move-leg-agent-unread' => [
                'could NOT determine whether the mapping for owner/repo is orphaned',
                'could NOT determine whether any agent enables the coord-card-move family',
                'CANNOT VERIFY the terminal against the coordination config',
            ],
            'writeback-half-configured-triggers' => [
                'sets stages.started but not started_from_stages',
                'sets revive_on_reopen but not stages.opened',
                'sets create_coord_cards but writeback.json has no identity_id',
            ],
            'writeback-malformed' => ['is not a valid JSON object'],

            // ---- writeback: the deep leg that reaches the board client ----
            'writeback-move-leg-coord-config-unset' => ['$COORD_CONFIG is not set'],
            'writeback-move-leg-coord-config-unreadable' => ['is absent, unreadable, or malformed'],
            'writeback-move-leg-coord-config-agrees' => ['coord config agrees'],
            'writeback-board-unreadable' => ['writeback: could not read board 8'],
            // Both halves pinned: the disclosure that must appear, AND the confident
            // accusation that must not. Pinning only the first would stay green if the
            // fix regressed into printing both.
            'writeback-swimlane-collection-absent' => ['could NOT check swimlane_id 4', 'carried no swimlane collection'],

            // ---- board_tools + the opt-in probes ----
            // The http plane prints nothing http-SPECIFIC, so the subject is that the plane
            // was reached at all — which is precisely what an aborted agent config denied it.
            'board-tools-http-enabled' => ['board_tools: agent prod-agent:'],
            // Anchored on the verdict STEM, not the whole sentence: the remediation prose
            // beside it is operator text and gets reworded, while `WIRED` + the age is the
            // invariant this fixture exists to reach.
            'board-tools-client-half-wired' => ['client half WIRED — the seat\'s last successful board-tools call was 3h ago, over http'],
            'board-tools-ssh-pinned-line' => ['board_tools ssh: the pinned line for agent prod-agent forces bridge:tools-call'],
            'board-tools-ssh-default-transport-advisory' => ['is on ssh by the v0.68.0 default'],
            'board-tools-ssh-live-probe' => ['board_my_cards ok; window scoped to board 10 / swimlane 4'],
            'probe-tools-ssh-with-no-ssh-agent' => ['--probe-tools-ssh was given but no agent has an enabled ssh-transport board_tools block'],
            'probe-tools-with-no-enabled-agent' => ['--probe-tools was given but no agent has an enabled board_tools block'],
            'no-opt-in-probes-requested' => ['agent config ok: prod-agent'],

            // ---- event-follows-consumer ----
            'event-consumer-unconsumed-type' => ["event-consumer: github:owner/repo has received 'issues'"],
            'event-consumer-nothing-arrived' => ['agent config ok: wb'],
            // Both halves are subjects: the disclosure that must appear AND the withheld
            // verdict that replaced the accusation. Pinning only the first would stay
            // green if the fix printed the disclosure ABOVE the old confident warn.
            'event-consumer-declaration-unreadable' => [
                'threw when asked which events it consumes',
                'could not be determined',
            ],
        ];
    }

    /**
     * The fixtures whose subject is an ABSENCE, and the substring that must stay absent.
     *
     * These three exist to pin a leg printing NOTHING, which a contains-assertion cannot
     * express. A notContains ALONE would be satisfied by an empty capture, so it never
     * stands on its own here: every fixture also carries a positive subject above, and the
     * pair is what makes silence asserted rather than assumed — the same shape
     * {@see test_the_php_fpm_pin_changes_the_retention_posture_line()} already draws by hand
     * for the fpm pair.
     *
     * @return array<string, list<string>>
     */
    private static function absentSubjects(): array
    {
        return [
            // The fpm pin's whole effect is REMOVING the no-fastcgi warn from `minimal`.
            'minimal-fpm-present' => ['no fastcgi_finish_request()'],
            // The default invocation of `probe-tools-with-no-enabled-agent`'s install: the
            // opt-in leg must print nothing at all.
            'no-opt-in-probes-requested' => ['board_tools probe:'],
            // A consumer with nothing to report is silent, not reassuring.
            'event-consumer-nothing-arrived' => ['event-consumer:'],
        ];
    }

    #[DataProvider('fixtures')]
    public function test_golden_output(string $name): void
    {
        $capture = $this->captureFixture($name);

        GoldenCapture::assertMatchesGolden($name, $capture);
        // Asserted on the CAPTURE, in the same run, and deliberately AFTER the golden
        // compare — including under `UPDATE_GOLDEN=1`, where the compare returns early. A
        // regen must not be able to mint a fixture that measures nothing.
        $this->assertFixtureReachesItsSubject($name, $capture);
    }

    #[DataProvider('fixtures')]
    public function test_the_json_document_agrees_with_the_committed_text_capture(string $name): void
    {
        // DL-249 STAGE 9 — THE CROSS-RENDERER AGREEMENT, over every install shape.
        //
        // It lives in this class because this class owns the install-shape corpus, and a
        // second copy of that corpus is the defect the whole program keeps carding. The
        // JSON document's own SHAPE is pinned in CheckJsonContractTest; what is asserted
        // here is that the two renderers cannot disagree about the same run.
        //
        // THE TEXT SIDE IS THE COMMITTED FILE, NOT A SECOND RUN. That halves the cost, and
        // it is also the stronger comparison: the golden file is what a reviewer reads in
        // the PR diff, so agreement with IT is agreement with the reviewed artifact rather
        // than with whatever a re-run happens to produce.
        $golden = $this->goldenFor($name);
        $doc = $this->captureFixtureAsJson($name);

        preg_match('/^exit: (\d+)$/m', $golden, $exitMatch);
        $this->assertNotEmpty($exitMatch, "golden fixture '{$name}' has no exit line to compare against");
        $textExit = (int) $exitMatch[1];

        // THE EXIT CONTRACT, asserted rather than asserted-by-construction. `--format`
        // gates only which bytes are printed, so a run that exits differently means a
        // renderer reached the verdict — the one risk card#5229 named for this stage.
        $this->assertSame($textExit, $doc['exit'], "fixture '{$name}': --format=json exits differently from the text run");
        $this->assertSame($textExit === 0, $doc['document']['ok'], "fixture '{$name}': the document's `ok` disagrees with the exit code");

        preg_match(
            '/^checks: (\d+) registered · (\d+) ran \((\d+) reported above, (\d+) with nothing to report\)(.*?)\. All \d+ are accounted for/m',
            $golden,
            $m,
        );
        $this->assertNotEmpty($m, "golden fixture '{$name}' has no parseable inventory line");
        $inventory = $doc['document']['inventory'];

        $this->assertSame((int) $m[1], $inventory['registered'], "fixture '{$name}': registered");
        $this->assertSame((int) $m[2], $inventory['ran'], "fixture '{$name}': ran");
        $this->assertSame((int) $m[3], $inventory['reported'], "fixture '{$name}': reported");
        $this->assertSame((int) $m[4], $inventory['silent'], "fixture '{$name}': silent");
        $this->assertSame(
            preg_match('/(\d+) opt-in probes? not requested/', $m[5], $nr) ? (int) $nr[1] : 0,
            $inventory['not_requested'],
            "fixture '{$name}': not_requested",
        );
        $this->assertSame(
            preg_match('/(\d+) did not run/', $m[5], $dnr) ? (int) $dnr[1] : 0,
            $inventory['not_run'],
            "fixture '{$name}': not_run",
        );
    }

    public function test_every_fixture_declares_a_subject(): void
    {
        // Without this, the guard is opt-in and the next fixture added silently has no
        // subject — which is the defect it exists to prevent, one level up.
        $fixtures = array_map(fn (array $row) => $row[0], self::fixtures());

        $this->assertSame(
            [],
            array_values(array_diff($fixtures, array_keys(self::subjects()))),
            'every golden fixture must declare at least one subject in subjects()',
        );
        $this->assertSame(
            [],
            array_values(array_diff(array_keys(self::subjects()), $fixtures)),
            'subjects() names a fixture that is not in fixtures()',
        );
        $this->assertSame(
            [],
            array_values(array_diff(array_keys(self::absentSubjects()), $fixtures)),
            'absentSubjects() names a fixture that is not in fixtures()',
        );
        foreach (self::subjects() as $name => $needles) {
            $this->assertNotEmpty($needles, "fixture '{$name}' declares an empty subject list");
        }
    }

    public function test_every_golden_file_is_distinct_except_the_declared_control_pairs(): void
    {
        // A fixture byte-identical to a sibling measures whatever the sibling measures, not
        // what its own name claims — two of card#5552's three dead fixtures presented
        // exactly that way. The allow-list is load-bearing rather than an escape hatch, so
        // it is asserted in BOTH directions: a declared pair that stops being identical is
        // a stale declaration, and that is a defect too.
        foreach (self::CONTROL_PAIRS as $copy => $original) {
            $this->assertSame(
                $this->goldenFor($original),
                $this->goldenFor($copy),
                "'{$copy}' is declared a control copy of '{$original}' but they now differ — "
                    ."either the pairing is dead and the declaration should go, or '{$copy}' regressed",
            );
        }

        $byContent = [];
        foreach (self::fixtures() as [$name]) {
            $byContent[md5($this->goldenFor($name))][] = self::CONTROL_PAIRS[$name] ?? $name;
        }

        foreach ($byContent as $names) {
            $distinct = array_values(array_unique($names));
            $this->assertCount(
                1,
                $distinct,
                'these golden files are byte-identical but are not a declared control pair: '
                    .implode(', ', $distinct).' — one of them is measuring the other\'s subject',
            );
        }
    }

    /**
     * The golden files a fixture may legitimately duplicate, as `copy => the original`.
     *
     * `no-opt-in-probes-requested` is deliberately identical to `minimal`: its value is not
     * its own bytes but the DIFFERENCE against `probe-tools-with-no-enabled-agent`, which is
     * exactly the one opt-in warn. A naive uniqueness assertion reds on it, so the pairing
     * is declared rather than worked around.
     */
    private const CONTROL_PAIRS = [
        'no-opt-in-probes-requested' => 'minimal',
    ];

    private function assertFixtureReachesItsSubject(string $name, string $capture): void
    {
        // Strict rather than `?? []`: a missing entry must fail HERE and not rely on
        // test_every_fixture_declares_a_subject still existing. A guard that silently
        // asserts nothing when its declaration is absent is the shape this whole change
        // exists to remove.
        $this->assertArrayHasKey(
            $name,
            self::subjects(),
            "golden fixture '{$name}' declares no subject — add one to subjects()",
        );

        foreach (self::subjects()[$name] as $needle) {
            $this->assertStringContainsString(
                $needle,
                $capture,
                "golden fixture '{$name}' no longer reaches its subject: the rendered output does "
                    ."not contain '{$needle}'. The fixture is capturing SOMETHING, but not the thing "
                    .'it is named for — fix the install shape rather than the subject, unless the '
                    .'subject itself is what changed.',
            );
        }

        foreach (self::absentSubjects()[$name] ?? [] as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $capture,
                "golden fixture '{$name}' exists to pin the ABSENCE of '{$needle}', and it is present.",
            );
        }
    }

    public function test_every_golden_file_carries_a_self_conserving_inventory_line(): void
    {
        // DL-242 STAGE 8. The inventory's counts SUM to the registered total, and that
        // arithmetic is the line's own control: a reader can see nothing fell out of it
        // without trusting the renderer. Asserted over the COMMITTED corpus — every
        // install shape at once — because a per-fixture spot check would not notice a
        // disposition that leaks on one shape only.
        //
        // It also pins 38 as the registered total, which the registration test pins BY ID.
        // Two independent statements of the same fact on purpose: the id list catches a
        // check being swapped, this catches the operator-facing line disagreeing with it.
        foreach (self::fixtures() as [$name]) {
            $golden = $this->goldenFor($name);

            $this->assertMatchesRegularExpression(
                '/^checks: \d+ registered · /m',
                $golden,
                "golden fixture '{$name}' has no inventory line — every run must state what it covered",
            );
            preg_match(
                '/^checks: (\d+) registered · (\d+) ran \((\d+) reported above, (\d+) with nothing to report\)(.*?)\. All (\d+) are accounted for/m',
                $golden,
                $m,
            );
            [, $registered, $ran, $reported, $silent, $rest, $trailing] = array_map('strval', $m);

            $notRequested = preg_match('/(\d+) opt-in probes? not requested/', $rest, $nr) ? (int) $nr[1] : 0;
            // ONE pattern, because the renderer now has one label. It used to say `not
            // applicable here` whenever it had reasons to print, and that read as a claim
            // about the install for reasons that are could-not-look ("no agent config
            // parsed"); the weaker label covers the union, so matching the old one here
            // would be matching a string nothing can emit.
            $notRun = preg_match('/(\d+) did not run/', $rest, $dnr) ? (int) $dnr[1] : 0;

            $this->assertSame(38, (int) $registered, "fixture '{$name}': registered total moved");
            $this->assertSame((int) $trailing, (int) $registered, "fixture '{$name}': the trailing total disagrees with the registered count");
            $this->assertSame(
                (int) $ran,
                (int) $reported + (int) $silent,
                "fixture '{$name}': reported + silent does not equal ran",
            );
            $this->assertSame(
                (int) $registered,
                (int) $ran + $notRequested + $notRun,
                "fixture '{$name}': the dispositions do not sum to the registered total — a check fell out of the inventory",
            );
        }
    }

    public function test_the_opt_in_control_pair_now_differs_in_the_inventory_too(): void
    {
        // The CONTROL_PAIRS rationale, sharpened by stage 8. `no-opt-in-probes-requested`
        // exists for its DIFFERENCE against `probe-tools-with-no-enabled-agent`, which
        // before stage 8 was exactly one warn line. Now it is also a disposition: passing
        // --probe-tools makes that probe RUN (it reports the nothing-to-probe warn), so
        // one fewer probe is "not requested". That is the resolved opt-in decision visible
        // in output — a requested probe with nothing to certify still owes an answer.
        $neither = $this->goldenFor('no-opt-in-probes-requested');
        $httpAsked = $this->goldenFor('probe-tools-with-no-enabled-agent');

        $this->assertStringContainsString('2 opt-in probes not requested', $neither);
        $this->assertStringContainsString('1 opt-in probe not requested', $httpAsked);
        $this->assertStringNotContainsString('2 opt-in probes not requested', $httpAsked);
    }

    public function test_the_baseline_install_names_why_each_plane_did_not_run(): void
    {
        // "14 did not run" without a cause is alarming and un-actionable; with one it is
        // information. These two reasons are the whole writeback plane (9 checks) and the
        // board-tools plane (5, the fifth being DL-313's client-half leg) — the shape was
        // measured at 13 of 37 before stage 8 was built, which is what made an exact
        // inventory worth having; the total moves with the registered set, the PROPERTY
        // (every not-run check names its cause) does not.
        $minimal = $this->goldenFor('minimal');

        $this->assertStringContainsString('14 did not run', $minimal);
        $this->assertStringContainsString('no readable writeback.json', $minimal);
        $this->assertStringContainsString('no agent has an enabled board_tools block', $minimal);
        // And never the internal-defect line: every not-run check here has a reason.
        $this->assertStringNotContainsString('bridge:check internal:', $minimal);
    }

    public function test_a_capture_is_immune_to_the_ambient_host(): void
    {
        // The positive control the falsifier's own near-miss demands. Perturbing the
        // ambient environment BEFORE the pins go on proves the pins override rather
        // than inherit — and `minimal` vs `minimal-fpm-present` above proves the
        // perturbation is potent, so this is not immunity to something that never
        // mattered.
        $perturbed = $this->captureFixture('writeback-move-leg-coord-config-unset', perturb: true);

        $this->assertSame(
            file_get_contents(base_path(GoldenCapture::DIR.'/writeback-move-leg-coord-config-unset.txt')),
            $perturbed,
        );
    }

    public function test_the_pinned_coord_config_changes_the_diagnosis_it_is_pinned_for(): void
    {
        // Potency for pin #3, asserted against the golden files rather than re-derived:
        // if these three ever collapse to one text, the pin has stopped mattering and
        // the fixture pair is dead weight that should be deleted, not kept as decoration.
        $unset = $this->goldenFor('writeback-move-leg-coord-config-unset');
        $unreadable = $this->goldenFor('writeback-move-leg-coord-config-unreadable');
        $agrees = $this->goldenFor('writeback-move-leg-coord-config-agrees');

        $this->assertStringContainsString('$COORD_CONFIG is not set', $unset);
        $this->assertStringContainsString('is absent, unreadable, or malformed', $unreadable);
        $this->assertNotSame($unset, $unreadable);
        $this->assertNotSame($unset, $agrees);
    }

    public function test_the_channel_probe_pin_is_what_the_command_resolves(): void
    {
        // Potency for the seam pin (DL-242 stage 5b). Binding it changed no golden file
        // — necessarily, since no golden fixture reaches a probe — so on the golden set
        // alone the binding is indistinguishable from a binding that never took effect.
        // This is the shape that can fail: an install whose channel.url HAS a port, where
        // an unbound seam would connect to the real 127.0.0.1:9 and print its own verdict.
        $capture = $this->captureFixture('channel-probe-pin-potency');

        $this->assertStringContainsString('pinned by the golden harness: no fixture endpoint', $capture);
    }

    public function test_the_php_fpm_pin_changes_the_retention_posture_line(): void
    {
        // Potency for pin #1. This is the input the stage-0 NAMED GAP was about —
        // whether CI's runner has php-fpm was unverified, and it is reached on the
        // default path. Pinning removes the question; this asserts the pin has teeth.
        //   The asserted text moved with DL-261: the leg no longer concludes that the
        // install lacks `fastcgi_finish_request()` — a PATH miss in a console process is
        // no evidence about the receiver — so it discloses that it could not measure.
        // What this test needs is unchanged either way: a string the pin's two values
        // put on opposite sides.
        $absent = $this->goldenFor('minimal');
        $present = $this->goldenFor('minimal-fpm-present');

        $this->assertStringContainsString('could NOT determine whether the receiver ends the request early', $absent);
        $this->assertStringNotContainsString('could NOT determine whether the receiver ends the request early', $present);

        // The retired claim must not come back anywhere in the pair: this is the assertion
        // that would have caught the old wording surviving a partial regen.
        $this->assertStringNotContainsString('no fastcgi_finish_request()', $absent);
        $this->assertStringNotContainsString('no fastcgi_finish_request()', $present);
    }

    public function test_the_capture_does_not_depend_on_terminal_width(): void
    {
        // Symfony console wraps some output primitives on terminal width. If any
        // `bridge:check` line went through one of those, every golden file would be a
        // property of the capturing terminal. Measured rather than assumed — and if
        // this ever reds, COLUMNS joins the pinned set.
        // No teardown between the two: {@see BootsGoldenInstall::bootGoldenInstall()} does it,
        // and a redundant call here would read as load-bearing to the next author — the exact
        // remember-to-do-it discipline that hoist exists to remove.
        $narrow = $this->withColumns('40', fn () => $this->captureFixture('writeback-half-configured-triggers'));
        $wide = $this->withColumns('400', fn () => $this->captureFixture('writeback-half-configured-triggers'));

        $this->assertSame($narrow, $wide);
    }

    // ---- fixture plumbing ----

    private function captureFixture(string $name, bool $perturb = false): string
    {
        // The pin set and its ordering are the trait's — every one of them applies to
        // every fixture, so no caller gets to assemble a subset.
        $spec = $this->bootGoldenInstall($name, fn (GoldenInstall $i) => $this->buildFixture($i, $name), perturb: $perturb);

        return GoldenCapture::capture($this->install->path(), $spec['args']);
    }

    /**
     * The same install shape, run through the JSON renderer (DL-249 stage 9).
     *
     * It goes through `Artisan` directly rather than {@see GoldenCapture}: that helper's
     * path/uid normalization exists to keep a TEXT capture comparable across hosts, and
     * substituting tokens inside a document under structural assertion would be doing
     * work for nothing. Nothing here reads a message.
     *
     * @return array{exit: int, document: array<string, mixed>}
     */
    private function captureFixtureAsJson(string $name): array
    {
        $spec = $this->bootGoldenInstall($name, fn (GoldenInstall $i) => $this->buildFixture($i, $name));

        $exit = Artisan::call('bridge:check', $spec['args'] + ['--format' => 'json']);
        $raw = trim(Artisan::output());
        $document = json_decode($raw, true);

        $this->assertIsArray(
            $document,
            "fixture '{$name}': --format=json did not put a single JSON document on stdout — got: ".substr($raw, 0, 300),
        );

        return ['exit' => $exit, 'document' => $document];
    }

    private function goldenFor(string $name): string
    {
        // Read the committed file, not a fresh capture: the golden files are what the
        // stage 1-7 diffs are checked against, so these properties must hold of THEM.
        $path = base_path(GoldenCapture::DIR."/{$name}.txt");
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** @param callable(): string $fn */
    private function withColumns(string $columns, callable $fn): string
    {
        $saved = getenv('COLUMNS');
        putenv("COLUMNS={$columns}");
        try {
            return $fn();
        } finally {
            $saved === false ? putenv('COLUMNS') : putenv("COLUMNS={$saved}");
        }
    }

    private function kanbanAgentYaml(): string
    {
        return "identity:\n  kanban_user_id: 137\nsubscriptions:\n  - provider: kanban\n    scopes: [5]\n";
    }

    /** A forced-command line that denies pty+forwarding — the shape the probe certifies. */
    private const GOOD_PINNED_LINE = 'command="php artisan bridge:tools-call --agent=prod-agent",restrict ssh-ed25519 AAAA prod-agent';

    /**
     * An install whose one agent has an ENABLED board_tools block on the ssh transport.
     *
     * @param  string  $transport  the `transport:` line, or '' to omit it and land on the
     *                             v0.68.0 flipped default (which is what DL-225 advises on)
     */
    private function sshInstall(GoldenInstall $i, string $transport): void
    {
        $i->boot()
            ->agent('prod-agent', "identity:\n  kanban_user_id: 137\n"
                ."subscriptions:\n  - provider: kanban\n    scopes: [5]\n"
                // No `channel:` block: `channel.auth.token_path` is only valid with an
                // HTTP channel, and an ssh agent has none — copying the http fixture's
                // bearer line here aborts the agent's config leg before board_tools is
                // ever reached, which is what the FIRST cut of this fixture did.
                ."board_tools:\n{$transport}  board_id: 10\n  swimlane_id: 4\n  create_stage_id: 55\n")
            ->secret('kanban/writeback-token', 'wb-token');
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [], 'meta' => ['total' => 0]]),
            '*/boards/10/preload.json' => Http::response(['data' => ['workflows' => [['stages' => [
                ['id' => 55, 'name' => 'Backlog', 'position' => 1024.0],
            ]]], 'swimlanes' => [['id' => 4, 'name' => 'Default']]]]),
        ]);
    }

    /**
     * The fixture shape that provably REACHES the deep writeback/coord legs.
     *
     * @param  array<string, mixed>  $stubs  Http stubs registered AHEAD of the defaults.
     *                                       Passing them here rather than via a later
     *                                       `Http::fake()` is the only ordering that works
     *                                       once this helper has run; see CLAUDE_GOTCHAS.md
     *                                       G-020 for why.
     */
    private function moveLegInstall(GoldenInstall $i, array $stubs = []): void
    {
        $i->boot()
            ->agent('prod-agent', "identity:\n  kanban_user_id: 137\n  github_user_id: 555\n"
                ."subscriptions:\n  - provider: kanban\n    scopes: [5]\n"
                ."  - provider: github\n    scopes: [\"owner/repo\"]\n"
                ."classifier:\n  class: App\\Bridge\\Classifiers\\CoordinationClassifier\n"
                ."  config:\n    families: [coord-message, coord-card-move]\n")
            ->json('writeback.json', ['identity_id' => 4242, 'mappings' => [
                'owner/repo' => [
                    'board_id' => 8,
                    'stages' => ['opened' => 50],
                    'move_coord_cards' => true,
                    'coord_card_stage_id' => 50,
                    'coord_card_terminal_stage_id' => 53,
                ],
            ]])
            ->secret('kanban/writeback-token', 'wb-token')
            ->secret('github/token', 'gh-token');
        config(['bridge.writeback.correlation' => 'scan']);
        Http::fake($stubs + [
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 1, 'payload' => []]]]),
            '*/boards/8/preload.json' => Http::response(['data' => ['workflows' => [['stages' => [
                ['id' => 50, 'name' => 'In Progress', 'position' => 1024.0],
                ['id' => 53, 'name' => 'Done', 'position' => 2048.0],
                ['id' => 54, 'name' => "Won't Do", 'position' => 3072.0],
            ]]]]]),
            '*' => Http::response(['data' => []]),
        ]);
    }

    private function githubEvent(string $eventType, string $delivery): void
    {
        $event = WebhookEvent::create([
            'delivery_id' => $delivery, 'provider' => 'github', 'scope_id' => 'owner/repo',
            'event_type' => $eventType, 'actor_id' => '1', 'payload' => ['x' => 1],
        ]);
        // `received_at` is NOT fillable — it defaults to the insert clock, and the
        // event-consumer warn prints it as its last-seen. The harness caught that as a
        // golden diff on the second run; force it so the capture answers for the
        // fixture and not for the minute it ran in.
        $event->forceFill(['received_at' => '2026-01-01 00:00:00'])->save();
    }
}
