<?php

namespace Tests\Feature\Console\Check;

use App\Bridge\Retention\RetentionGate;
use App\Bridge\Support\ChannelProbeEnvironment;
use App\Bridge\Tools\SshProbeEnvironment;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CheckGolden\GoldenCapture;
use Tests\Support\CheckGolden\GoldenChannelEnvironment;
use Tests\Support\CheckGolden\GoldenInstall;
use Tests\Support\CheckGolden\GoldenSshEnvironment;
use Tests\Support\CheckGolden\PinnedHost;
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
    use RefreshDatabase;

    private ?GoldenInstall $install = null;

    private ?PinnedHost $host = null;

    protected function tearDown(): void
    {
        // The assertions that only READ golden files never build an install, so both
        // are legitimately unset here — and an un-restored PATH would leak into every
        // later test in the process.
        $this->tearDownFixture();
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
                // `RetentionPostureCheck::receiverSapiFinishesEarly()`), which is
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
                $this->moveLegInstall($i);
                Http::fake(fn () => Http::response(['message' => 'nope'], 500));

                return $default;

                // ---- board_tools + the opt-in probes ----
            case 'board-tools-http-enabled':
                $i->boot()
                    ->agent('prod-agent', "identity:\n  kanban_user_id: 137\n"
                        ."subscriptions:\n  - provider: kanban\n    scopes: [5]\n"
                        ."board_tools:\n  transport: http\n  board_id: 10\n  swimlane_id: 4\n  create_stage_id: 55\n"
                        ."channel:\n  auth:\n    token_path: ".$i->path('tools-bearer')."\n")
                    ->secret('tools-bearer', 'bearer-value')
                    ->secret('kanban/writeback-token', 'wb-token');
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
                // header matches the configured lane. No fixture reached this leg before.
                $this->sshInstall($i, transport: "  transport: ssh\n");
                $this->app->instance(SshProbeEnvironment::class, new GoldenSshEnvironment(
                    authorizedKeys: self::GOOD_PINNED_LINE,
                    roundTrip: ['exit' => 0, 'stdout' => '{"ok":true,"result":{"board_id":10,"swimlane_id":4}}', 'stderr' => ''],
                ));

                return ['args' => ['--probe-tools-ssh' => 'bridge@host-a'], 'fpm' => false, 'coordConfig' => null];

            case 'probe-tools-ssh-with-no-ssh-agent':
                // The opt-in probe's not-applicable path, the ssh twin of
                // `probe-tools-with-no-enabled-agent`. Today it prints one warn; from
                // stage 8 the same state is a `not requested`/`not applicable`
                // disposition, and this fixture is what will show that change.
                $i->boot()->agent('prod-agent', $this->kanbanAgentYaml());
                $this->app->instance(SshProbeEnvironment::class, new GoldenSshEnvironment);

                return ['args' => ['--probe-tools-ssh' => 'bridge@host-a'], 'fpm' => false, 'coordConfig' => null];

            case 'probe-tools-with-no-enabled-agent':
                // The opt-in probe's not-applicable path. Today it prints a warn; from
                // stage 8 the same state is a `not requested`/`not applicable`
                // disposition in the inventory. This fixture is what will show that
                // change when it lands.
                $i->boot()->agent('prod-agent', $this->kanbanAgentYaml());

                return ['args' => ['--probe-tools' => 'https://bridge.example.com/agent-tools/call'], 'fpm' => false, 'coordConfig' => null];

            case 'no-opt-in-probes-requested':
                // The default invocation of the same install: the opt-in legs print
                // NOTHING. Pairing it with the fixture above is what makes a stage-8
                // change to the opt-in disposition visible in a diff.
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
            'writeback-half-configured-triggers',
            'writeback-malformed',
            'writeback-move-leg-coord-config-unset',
            'writeback-move-leg-coord-config-unreadable',
            'writeback-move-leg-coord-config-agrees',
            'writeback-board-unreadable',
            'board-tools-http-enabled',
            'board-tools-ssh-pinned-line',
            'board-tools-ssh-default-transport-advisory',
            'board-tools-ssh-live-probe',
            'probe-tools-ssh-with-no-ssh-agent',
            'probe-tools-with-no-enabled-agent',
            'no-opt-in-probes-requested',
            'event-consumer-unconsumed-type',
            'event-consumer-nothing-arrived',
        ]);
    }

    #[DataProvider('fixtures')]
    public function test_golden_output(string $name): void
    {
        GoldenCapture::assertMatchesGolden($name, $this->captureFixture($name));
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
        $absent = $this->goldenFor('minimal');
        $present = $this->goldenFor('minimal-fpm-present');

        $this->assertStringContainsString('no fastcgi_finish_request()', $absent);
        $this->assertStringNotContainsString('no fastcgi_finish_request()', $present);
    }

    public function test_the_capture_does_not_depend_on_terminal_width(): void
    {
        // Symfony console wraps some output primitives on terminal width. If any
        // `bridge:check` line went through one of those, every golden file would be a
        // property of the capturing terminal. Measured rather than assumed — and if
        // this ever reds, COLUMNS joins the pinned set.
        $narrow = $this->withColumns('40', fn () => $this->captureFixture('writeback-half-configured-triggers'));
        $this->tearDownFixture();
        $wide = $this->withColumns('400', fn () => $this->captureFixture('writeback-half-configured-triggers'));

        $this->assertSame($narrow, $wide);
    }

    // ---- fixture plumbing ----

    private function captureFixture(string $name, bool $perturb = false): string
    {
        $install = new GoldenInstall($name);
        $host = new PinnedHost($install->path());
        $this->install = $install;
        $this->host = $host;
        // Pinned for EVERY fixture, ahead of buildFixture() so a fixture that ever wants
        // a live endpoint overrides it deliberately rather than inheriting the box.
        $this->app->instance(ChannelProbeEnvironment::class, new GoldenChannelEnvironment);
        if ($perturb) {
            $host->perturbAmbient();
        }
        $spec = $this->buildFixture($install, $name);
        $host->apply(fpmPresent: $spec['fpm'], coordConfig: $spec['coordConfig']);

        return GoldenCapture::capture($install->path(), $spec['args']);
    }

    private function tearDownFixture(): void
    {
        $this->host?->restore();
        $this->install?->destroy();
        $this->host = null;
        $this->install = null;
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

    /** The fixture shape that provably REACHES the deep writeback/coord legs. */
    private function moveLegInstall(GoldenInstall $i): void
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
        Http::fake([
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
