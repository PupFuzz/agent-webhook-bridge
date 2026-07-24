<?php

namespace Tests\Feature\Console;

use App\Bridge\Retention\RetentionGate;
use App\Bridge\Support\BridgePaths;
use App\Bridge\Tools\SshProbeEnvironment;
use App\Bridge\Writeback\KanbanClient;
use App\Console\Commands\Bridge\InboxCommand;
use App\Console\Commands\Bridge\ReplayCommand;
use App\Models\AgentDispatch;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BridgeCommandsTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private string|false $origGhToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/cli-'.uniqid();
        File::ensureDirectoryExists($this->dir);
        config([
            'bridge.config_dir' => $this->dir,
            'bridge.secret_dir' => $this->dir,
            // Neutralize the store-native reconcile-token leg (this host has a real
            // git-credential-coord on PATH) so bridge:check is deterministic.
            'bridge.providers.github.credential_helper' => $this->dir.'/no-store-helper',
        ]);
        // Hermetic: the host/CI may export GH_TOKEN, now a reconcile-token leg — clear
        // it so the reconcile-token check resolves deterministically (a test that
        // asserts no HTTP must not have GH_TOKEN silently satisfy the validity probe).
        $this->origGhToken = getenv('GH_TOKEN');
        putenv('GH_TOKEN');
    }

    protected function tearDown(): void
    {
        if ($this->origGhToken === false) {
            putenv('GH_TOKEN');
        } else {
            putenv('GH_TOKEN='.$this->origGhToken);
        }
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function writeAgent(): void
    {
        File::put($this->dir.'/prod-agent.yml', "identity:\n  kanban_user_id: 137\n"
            ."subscriptions:\n  - provider: kanban\n    scopes: [5]\n");
    }

    private function event(): WebhookEvent
    {
        return WebhookEvent::create([
            'delivery_id' => 'evt-1', 'provider' => 'kanban', 'scope_id' => '5',
            'event_type' => 'task.created', 'actor_id' => '999',
            'payload' => ['subject_id' => 42, 'board_id' => 5, 'payload' => ['name' => 'Ship it']],
        ]);
    }

    public function test_check_passes_with_valid_config(): void
    {
        $this->writeAgent();
        $this->artisan('bridge:check')->assertExitCode(0);
    }

    public function test_check_fails_without_secret_dir(): void
    {
        config(['bridge.secret_dir' => null]);
        $this->artisan('bridge:check')->assertExitCode(1);
    }

    public function test_check_fails_on_configured_provider_without_adapter(): void
    {
        // B-15: a config('bridge.providers') key with no WebhookAdapterFactory
        // adapter is dead config (the receiver would 400 unknown_provider).
        $this->writeAgent();
        config(['bridge.providers.gitlab' => ['api_base_url' => 'https://gitlab.example.com/api/v4']]);
        $this->artisan('bridge:check')
            ->expectsOutputToContain('no adapter')
            ->assertExitCode(1);
    }

    public function test_check_warns_on_missing_writeback_token(): void
    {
        $this->writeAgent();
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => ['board_id' => 8, 'stages' => ['merged' => 52]]],
        ]));
        // The board-visibility probe (DL-026) is reachable here; with no token the
        // factory throws before any HTTP, but fake to harden against a real call.
        Http::fake();
        $this->artisan('bridge:check')
            ->expectsOutputToContain('writeback token')
            ->assertExitCode(0);   // warn, not fail
        Http::assertNothingSent();
    }

    private function writeSshAgent(): void
    {
        $this->writeSecret($this->dir.'/kanban/writeback-token', 'wb');   // gitleaks:allow — test fixture
        File::put($this->dir.'/me.yml', "identity:\n  kanban_user_id: 1\nsubscriptions: []\n"
            ."board_tools:\n  transport: ssh\n  board_id: 10\n  swimlane_id: 4\n  create_stage_id: 55\n");
        config(['bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3']);
    }

    private function bindSshEnv(string $authorizedKeys, bool $isRoot = false, bool $fips = false, ?string $sshdConfig = null): void
    {
        $this->app->instance(SshProbeEnvironment::class, new class($authorizedKeys, $isRoot, $fips, $sshdConfig) implements SshProbeEnvironment
        {
            public function __construct(private string $keys, private bool $root, private bool $fips, private ?string $sshd) {}

            public function isRoot(): bool
            {
                return $this->root;
            }

            public function fipsEnabled(): bool
            {
                return $this->fips;
            }

            public function runUser(): string
            {
                return 'bridge';
            }

            public function runUserHome(): string
            {
                return '/home/bridge';
            }

            public function homeForUser(string $user): string
            {
                return "/home/{$user}";
            }

            public function sshdEffectiveConfig(?string $forUser = null): ?string
            {
                return $this->root ? $this->sshd : null;
            }

            public function readAuthorizedKeys(string $path): ?string
            {
                return $this->keys === '' ? null : $this->keys;
            }

            public function sshRoundTrip(string $target, string $stdin): array
            {
                return ['exit' => 1, 'stdout' => '', 'stderr' => 'not used'];
            }
        });
    }

    public function test_check_fails_when_ssh_pinned_line_grants_pty(): void
    {
        // A present-but-bad forced-command line (restrict,pty) flips bridge:check to
        // FAILURE via the offline SSH-transport probe.
        Http::fake();
        $this->writeSshAgent();
        $this->bindSshEnv('command="php artisan bridge:tools-call --agent=me",restrict,pty ssh-ed25519 AAAA me');
        $this->artisan('bridge:check')
            ->expectsOutputToContain('still grants')
            ->assertExitCode(1);
    }

    public function test_check_passes_with_good_ssh_line_unprivileged(): void
    {
        // A good pinned line + a non-root run stays exit 0. The account sshd posture is no
        // longer probed (card 5091 retired it), so there is no UNVERIFIED posture warn.
        Http::fake();
        $this->writeSshAgent();
        $this->bindSshEnv('command="php artisan bridge:tools-call --agent=me",restrict ssh-ed25519 AAAA me');
        $this->artisan('bridge:check')
            ->doesntExpectOutputToContain('PasswordAuthentication')
            ->assertExitCode(0);
    }

    public function test_check_ignores_account_password_auth_posture(): void
    {
        // Card 5091 (red-when-reverted): the ssh-account routinely doubles as the operator's
        // interactive login, so bridge:check must NOT flip red on its PasswordAuthentication /
        // idle posture — the pinned forced-command key is the sole boundary. As root with a good
        // pinned line at the authoritative path, PasswordAuthentication yes is now IGNORED (was a
        // hard FAIL). Reintroducing the posture leg turns this exit-0 assertion red.
        Http::fake();
        $this->writeSshAgent();
        $this->bindSshEnv(
            'command="php artisan bridge:tools-call --agent=me",restrict ssh-ed25519 AAAA me',
            isRoot: true,
            sshdConfig: "authorizedkeysfile .ssh/authorized_keys\npasswordauthentication yes\n",
        );
        $this->artisan('bridge:check')
            ->doesntExpectOutputToContain('PasswordAuthentication')
            ->assertExitCode(0);
    }

    /** An agent that landed on ssh via the DL-225 default (no explicit transport: key). */
    private function writeImplicitDefaultSshAgent(): void
    {
        $this->writeSecret($this->dir.'/kanban/writeback-token', 'wb');   // gitleaks:allow — test fixture
        File::put($this->dir.'/me.yml', "identity:\n  kanban_user_id: 1\nsubscriptions: []\n"
            ."board_tools:\n  board_id: 10\n  swimlane_id: 4\n  create_stage_id: 55\n");   // NO transport: key
        config(['bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3']);
    }

    public function test_check_advises_when_agent_is_on_ssh_by_the_v0680_default_without_setup(): void
    {
        // DL-225 pre-upgrade advisory: an agent with no explicit transport: key now reads
        // as ssh, and with no completed ssh setup its loopback path breaks on upgrade.
        // Empty authorized_keys + non-root ⇒ incomplete ⇒ the advisory FIRES (warn, exit 0).
        Http::fake();
        $this->writeImplicitDefaultSshAgent();
        $this->bindSshEnv('');   // no pinned line, unprivileged → setup incomplete
        $this->artisan('bridge:check')
            ->expectsOutputToContain('on ssh by the v0.68.0 default')
            ->assertExitCode(0);
    }

    public function test_check_does_not_advise_when_transport_is_explicit(): void
    {
        // Negative control: an EXPLICIT transport: ssh choice is intentional — the
        // advisory must NOT fire even though this setup is equally incomplete.
        Http::fake();
        $this->writeSshAgent();   // explicit transport: ssh
        $this->bindSshEnv('');    // equally incomplete setup
        $this->artisan('bridge:check')
            ->doesntExpectOutputToContain('on ssh by the v0.68.0 default')
            ->assertExitCode(0);
    }

    public function test_check_warns_when_ci_failure_workflow_patterns_is_set(): void
    {
        // DL-197: the failure-name filter inverts the family's fail-loud posture — a
        // stale/typo'd pattern silently blackholes every CI-failure wake — so
        // bridge:check surfaces the configured patterns at preflight (warn, never fail).
        File::put($this->dir.'/prod-agent.yml', "identity:\n  kanban_user_id: 137\n"
            ."subscriptions:\n  - provider: kanban\n    scopes: [5]\n"
            ."classifier:\n  class: App\\Bridge\\Classifiers\\CoordinationClassifier\n"
            ."  config:\n    families: [impl-ci-wake]\n    ci_failure_workflow_patterns: [protocol integrity]\n");
        $this->artisan('bridge:check')
            ->expectsOutputToContain('ci_failure_workflow_patterns')
            ->assertExitCode(0);   // warn, not fail
    }

    public function test_check_fails_on_malformed_ci_failure_workflow_patterns(): void
    {
        // A non-list value throws at parse (the classify path would 5xx on it) — the
        // check surfaces it per-agent and fails, instead of crashing the whole run.
        File::put($this->dir.'/prod-agent.yml', "identity:\n  kanban_user_id: 137\n"
            ."subscriptions:\n  - provider: kanban\n    scopes: [5]\n"
            ."classifier:\n  class: App\\Bridge\\Classifiers\\CoordinationClassifier\n"
            ."  config:\n    families: [impl-ci-wake]\n    ci_failure_workflow_patterns: not-a-list\n");
        $this->artisan('bridge:check')
            ->expectsOutputToContain('ci_failure_workflow_patterns')
            ->assertExitCode(1);
    }

    public function test_check_warns_when_explicit_wake_membership_omits_comment_to(): void
    {
        // DL-213: comment_to is a fleet default, but an install that set wake_membership
        // explicitly overrides it — bridge:check surfaces that its directed-reply wakes
        // are dark (warn, never fail). coord-message family is on (unset families default).
        File::put($this->dir.'/prod-agent.yml', "identity:\n  kanban_user_id: 137\n"
            ."subscriptions:\n  - provider: kanban\n    scopes: [5]\n"
            ."classifier:\n  class: App\\Bridge\\Classifiers\\CoordinationClassifier\n"
            ."  config:\n    wake_membership: [to_me, to_all]\n");
        $this->artisan('bridge:check')
            ->expectsOutputToContain('omits comment_to')
            ->assertExitCode(0);   // warn, not fail
    }

    public function test_check_does_not_warn_when_wake_membership_is_absent(): void
    {
        // The absent-key install inherits the default (now WITH comment_to) — no gap, no warn.
        File::put($this->dir.'/prod-agent.yml', "identity:\n  kanban_user_id: 137\n"
            ."subscriptions:\n  - provider: kanban\n    scopes: [5]\n"
            ."classifier:\n  class: App\\Bridge\\Classifiers\\CoordinationClassifier\n");
        $this->artisan('bridge:check')
            ->doesntExpectOutputToContain('omits comment_to')
            ->assertExitCode(0);
    }

    public function test_check_does_not_warn_when_wake_membership_includes_comment_to(): void
    {
        // Explicit list that already carries comment_to → no gap, no warn.
        File::put($this->dir.'/prod-agent.yml', "identity:\n  kanban_user_id: 137\n"
            ."subscriptions:\n  - provider: kanban\n    scopes: [5]\n"
            ."classifier:\n  class: App\\Bridge\\Classifiers\\CoordinationClassifier\n"
            ."  config:\n    wake_membership: [to_me, to_all, comment_to]\n");
        $this->artisan('bridge:check')
            ->doesntExpectOutputToContain('omits comment_to')
            ->assertExitCode(0);
    }

    public function test_check_fails_on_malformed_wake_membership(): void
    {
        // A non-list value throws at parse (the classify path would 5xx on it) — the check
        // surfaces it per-agent and fails, instead of crashing the whole run.
        File::put($this->dir.'/prod-agent.yml', "identity:\n  kanban_user_id: 137\n"
            ."subscriptions:\n  - provider: kanban\n    scopes: [5]\n"
            ."classifier:\n  class: App\\Bridge\\Classifiers\\CoordinationClassifier\n"
            ."  config:\n    wake_membership: not-a-list\n");
        $this->artisan('bridge:check')
            ->expectsOutputToContain('wake_membership')
            ->assertExitCode(1);
    }

    public function test_check_reports_retention_on_when_enabled_and_usable(): void
    {
        // The posture line is the anti-DL-012 signal; pin that a healthy config
        // actually emits it (and does NOT emit the failure line when nothing failed).
        $this->writeAgent();
        config(['bridge.retention.enabled' => true, 'bridge.retention.older_than' => '30d']);
        Cache::forget(RetentionGate::ERROR_KEY);

        $this->artisan('bridge:check')
            ->expectsOutputToContain('retention: on')
            ->doesntExpectOutputToContain('LAST PASS FAILED')
            ->assertExitCode(0);
    }

    public function test_check_surfaces_a_persistent_retention_failure_from_the_marker(): void
    {
        // Finding from the DL-199 review: config being valid does NOT mean retention
        // is RUNNING — a pass that throws backs off a full interval and drains nothing
        // while the posture line reads healthy. The gate records its last throw; the
        // preflight must surface it (warn, never fail — an install-check must not fail
        // on a runtime condition it can only report).
        $this->writeAgent();
        config(['bridge.retention.enabled' => true, 'bridge.retention.older_than' => '30d']);
        Cache::put(RetentionGate::ERROR_KEY, [
            'at' => '2026-07-18T00:00:00+00:00',
            'exception' => 'RuntimeException',
            'error' => 'bridge: failed to lock inbox.jsonl for rewrite',
        ], 3600);

        $this->artisan('bridge:check')
            ->expectsOutputToContain('LAST PASS FAILED')
            ->assertExitCode(0);
    }

    public function test_check_fails_on_malformed_writeback_json(): void
    {
        $this->writeAgent();
        File::put($this->dir.'/writeback.json', 'not json {');
        $this->artisan('bridge:check')
            ->expectsOutputToContain('writeback.json')
            ->assertExitCode(1);
    }

    public function test_check_warns_on_malformed_alert_channel(): void
    {
        // FR-4: alert_channel with both socket+url is malformed → warn, never fail
        // (an opt-in diagnostic must not fail the install check).
        $this->writeAgent();
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'alert_channel' => ['socket' => '/run/a.sock', 'url' => 'http://127.0.0.1:9931/'],
            'mappings' => ['owner/repo' => ['board_id' => 8, 'stages' => ['merged' => 52]]],
        ]));
        Http::fake();
        $this->artisan('bridge:check')
            ->expectsOutputToContain('alert_channel: specify exactly one')
            ->assertExitCode(0);   // warn, not fail
    }

    public function test_check_warns_on_non_localhost_alert_channel_url(): void
    {
        // FR-4: a non-loopback alert url is rejected (warn).
        $this->writeAgent();
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'alert_channel' => ['url' => 'http://example.com/'],
            'mappings' => ['owner/repo' => ['board_id' => 8, 'stages' => ['merged' => 52]]],
        ]));
        Http::fake();
        $this->artisan('bridge:check')
            ->expectsOutputToContain('alert_channel: url must point at')
            ->assertExitCode(0);
    }

    public function test_check_warns_on_alert_channel_url_with_userinfo(): void
    {
        // card#4495: the check must not green-light a userinfo URL that the
        // runtime sender (LocalhostUrl::assertValid) rejects at send time —
        // http://user:pass@127.0.0.1/ passes the scheme+host checks but is a
        // credential-leaking SSRF shape the sender refuses.
        $this->writeAgent();
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'alert_channel' => ['url' => 'http://user:pass@127.0.0.1:9931/hook'],
            'mappings' => ['owner/repo' => ['board_id' => 8, 'stages' => ['merged' => 52]]],
        ]));
        Http::fake();
        $this->artisan('bridge:check')
            ->expectsOutputToContain('must not contain a userinfo component')
            ->assertExitCode(0);
    }

    private function writeWritebackWithToken(bool $sharedBoard = false): void
    {
        $this->writeAgent();
        $mappings = ['owner/repo' => ['board_id' => 8, 'stages' => ['merged' => 52]]];
        if ($sharedBoard) {
            $mappings['owner/other'] = ['board_id' => 8, 'stages' => ['merged' => 52]];
        }
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => $mappings,
        ]));
        File::ensureDirectoryExists($this->dir.'/kanban');
        File::put($this->dir.'/kanban/writeback-token', 'wb-token');
        chmod($this->dir.'/kanban/writeback-token', 0o600);
        config([
            'bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3',
            // These visibility/swimlane/orphan checks fake the scan path; pin scan
            // (default is now `ref`, DL-031). The ref by-ref reachability probe has
            // its own dedicated tests.
            'bridge.writeback.correlation' => 'scan',
        ]);
    }

    public function test_check_warns_when_the_resolved_reconcile_token_is_invalid(): void
    {
        // DL-186: a resolved-but-expired reconcile token (classically a stale
        // <secret_dir>/github/token shadowing the store map) passes resolvability
        // but 401s every repo at reconcile time. bridge:check probes VALIDITY and
        // warns (never fails), naming the resolved leg so the shadow surfaces at
        // preflight rather than on the first reconcile run.
        $this->writeWritebackWithToken();
        File::ensureDirectoryExists($this->dir.'/github');
        File::put($this->dir.'/github/token', 'stale-token');
        chmod($this->dir.'/github/token', 0o600);
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => []]),
            'https://api.github.com/*' => Http::response(['message' => 'Bad credentials'], 401),
        ]);

        $this->artisan('bridge:check')
            ->expectsOutputToContain('token from token file')
            ->assertExitCode(0);
    }

    public function test_check_classifies_the_reconcile_token_probe_status_into_a_hint(): void
    {
        // The shared GitHubRepoProbe gives bridge:check the status classification it
        // previously lacked: a 401 reads "expired/revoked" and a 403/404 "needs `repo`
        // scope" (matching bridge:reconcile), not a bare "HTTP {status}". One substring
        // per case — Laravel's expectsOutputToContain sets one Mockery expectation per
        // call, so two substrings from the SAME warn line collide.
        $this->writeWritebackWithToken();
        File::ensureDirectoryExists($this->dir.'/github');
        File::put($this->dir.'/github/token', 'stale-token');
        chmod($this->dir.'/github/token', 0o600);
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => []]),
            'https://api.github.com/*' => Http::response(['message' => 'Bad credentials'], 401),
        ]);

        $this->artisan('bridge:check')
            ->expectsOutputToContain('HTTP 401 (token expired/revoked)')
            ->assertExitCode(0);
    }

    public function test_check_classifies_a_403_probe_as_a_scope_hint(): void
    {
        $this->writeWritebackWithToken();
        File::ensureDirectoryExists($this->dir.'/github');
        File::put($this->dir.'/github/token', 'scopeless-token');
        chmod($this->dir.'/github/token', 0o600);
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => []]),
            'https://api.github.com/*' => Http::response(['message' => 'Forbidden'], 403),
        ]);

        $this->artisan('bridge:check')
            ->expectsOutputToContain('HTTP 403 (token lacks access to this private repo — needs `repo` scope)')
            ->assertExitCode(0);
    }

    /** @param array<string,mixed> $extra */
    private function writePromoteConfig(array $extra, bool $withGithubTokenFile): void
    {
        $this->writeAgent();
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => array_merge(['board_id' => 8, 'stages' => [
                'merged' => 52, 'merged_to_main' => 53,
            ], 'promote_on_release' => true], $extra)],
        ]));
        File::ensureDirectoryExists($this->dir.'/kanban');
        File::put($this->dir.'/kanban/writeback-token', 'wb-token');
        chmod($this->dir.'/kanban/writeback-token', 0o600);
        if ($withGithubTokenFile) {
            File::ensureDirectoryExists($this->dir.'/github');
            File::put($this->dir.'/github/token', 'ghp_read');
            chmod($this->dir.'/github/token', 0o600);
        }
        config([
            'bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3',
            'bridge.writeback.correlation' => 'scan',
        ]);
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => []]),
            'https://api.github.com/*' => Http::response(['full_name' => 'owner/repo']),
        ]);
    }

    public function test_check_warns_when_promote_on_release_lacks_a_github_token_file(): void
    {
        // DL-207: the promote leg runs under FPM where GH_TOKEN is absent + the store helper
        // is CLI-only, so only a placed token FILE works. No file ⇒ the leg is inert at runtime.
        $this->writePromoteConfig([], withGithubTokenFile: false);

        $this->artisan('bridge:check')
            ->expectsOutputToContain('promote_on_release but no GitHub read token resolves from a FILE')
            ->assertExitCode(0);
    }

    public function test_check_warns_when_promote_on_release_maps_shipped_and_released_to_one_stage(): void
    {
        // DL-207: shipped === released ⇒ the promote is a no-op (nothing to move).
        $this->writePromoteConfig(['stages' => ['merged' => 52, 'merged_to_main' => 52]], withGithubTokenFile: true);

        $this->artisan('bridge:check')
            ->expectsOutputToContain('stages.merged and stages.merged_to_main are the same stage')
            ->assertExitCode(0);
    }

    public function test_check_is_quiet_about_promote_on_release_when_token_file_present_and_stages_distinct(): void
    {
        $this->writePromoteConfig([], withGithubTokenFile: true);

        $this->artisan('bridge:check')
            ->doesntExpectOutputToContain('promote_on_release but no GitHub read token')
            ->doesntExpectOutputToContain('stages.merged and stages.merged_to_main are the same stage')
            ->assertExitCode(0);
    }

    public function test_check_warns_when_the_writeback_token_sees_zero_cards(): void
    {
        // DL-026: a 200 + empty board read = blind/degraded token (user not a
        // board member / wrong board_id). bridge:check must surface it LOUDLY at
        // config time, but stay a warning (exit 0) — an empty new board is legit.
        $this->writeWritebackWithToken();
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => []])]);

        $this->artisan('bridge:check')
            ->expectsOutputToContain('sees 0 cards on board 8')
            ->assertExitCode(0);
    }

    public function test_check_reports_visible_card_count_when_token_can_see_the_board(): void
    {
        $this->writeWritebackWithToken();
        // DL-029: the visibility probe reads the DL-146 pagination meta.total.
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => [['id' => 1]], 'meta' => ['total' => 2]])]);

        $this->artisan('bridge:check')
            ->expectsOutputToContain('sees 2 card(s) on board 8')
            ->assertExitCode(0);
    }

    public function test_check_warns_when_a_board_exceeds_the_scan_ceiling_in_scan_mode(): void
    {
        // DL-029: in scan mode (default), a board larger than the scan ceiling would
        // silently miss correlations — bridge:check surfaces it (warn, not fail) and
        // points at BRIDGE_WRITEBACK_CORRELATION=ref. The probe reads meta.total, so
        // there's no need to fake thousands of rows.
        $this->writeWritebackWithToken();
        $over = KanbanClient::SEARCH_LIMIT * KanbanClient::MAX_PAGES + 1;
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => [['id' => 1]], 'meta' => ['total' => $over]])]);

        $this->artisan('bridge:check')
            ->expectsOutputToContain('beyond the scan ceiling')
            ->assertExitCode(0);
    }

    public function test_check_warns_when_the_board_read_fails(): void
    {
        $this->writeWritebackWithToken();
        Http::fake(['*/tasks/search.json*' => Http::response(['error' => 'forbidden'], 403)]);

        $this->artisan('bridge:check')
            ->expectsOutputToContain('could not read board 8')
            ->assertExitCode(0);
    }

    public function test_check_confirms_by_ref_reachable_in_ref_mode(): void
    {
        // DL-031: ref is the default; bridge:check probes by-ref reachability.
        $this->writeWritebackWithToken();
        config(['bridge.writeback.correlation' => 'ref']);
        Http::fake([
            '*/tasks/by-ref.json*' => Http::response(['data' => []]),                       // route exists
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 1]], 'meta' => ['total' => 1]]),
        ]);

        $this->artisan('bridge:check')
            ->expectsOutputToContain('by-ref reachable')
            ->assertExitCode(0);
    }

    public function test_check_warns_when_ref_mode_but_kanban_lacks_by_ref(): void
    {
        // DL-031: the safety net for the ref default — a kanban predating by-ref
        // (404 on the route) would 404 every correlation. Warn loudly (exit 0).
        $this->writeWritebackWithToken();
        config(['bridge.writeback.correlation' => 'ref']);
        Http::fake([
            '*/tasks/by-ref.json*' => Http::response(['message' => 'Not Found'], 404),       // route missing
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 1]], 'meta' => ['total' => 1]]),
        ]);

        $this->artisan('bridge:check')
            ->expectsOutputToContain('by-ref returned 404')
            ->assertExitCode(0);
    }

    public function test_check_warns_on_dl_card_with_null_source_on_a_shared_board_in_ref_mode(): void
    {
        // #3399 + DL-174: only on a SHARED board is correlation repo-qualified, so only
        // there does a null-source dl card silently never self-move. Warn (exit 0).
        $this->writeWritebackWithToken(sharedBoard: true);
        config(['bridge.writeback.correlation' => 'ref']);
        Http::fake([
            '*/tasks/by-ref.json*' => Http::response(['data' => []]),
            '*/tasks/search.json*' => Http::response([
                'data' => [['id' => 7, 'payload' => ['dl_number' => 'DL-9001']]],   // no pr_url → source=null
                'meta' => ['total' => 1],
            ]),
        ]);

        $this->artisan('bridge:check')
            ->expectsOutputToContain('card 7 (DL DL-9001)')
            ->assertExitCode(0);   // warn, never fail
    }

    public function test_check_no_null_source_warning_on_a_non_shared_board(): void
    {
        // DL-174: on a 1:1 board the source qualifier is omitted, so a null-source
        // dl card correlates fine — the #3399 warn must NOT fire (false alarm).
        $this->writeWritebackWithToken();
        config(['bridge.writeback.correlation' => 'ref']);
        Http::fake([
            '*/tasks/by-ref.json*' => Http::response(['data' => []]),
            '*/tasks/search.json*' => Http::response([
                'data' => [['id' => 7, 'payload' => ['dl_number' => 'DL-9001']]],
                'meta' => ['total' => 1],
            ]),
        ]);

        $this->artisan('bridge:check')
            ->doesntExpectOutputToContain('source=null')
            ->assertExitCode(0);
    }

    public function test_check_no_source_warning_when_dl_card_has_a_mapped_pr_url(): void
    {
        // #3399: a dl_number card whose pr_url yields a source matching a mapped repo
        // (owner/repo) self-moves fine → no source warning.
        $this->writeWritebackWithToken();
        config(['bridge.writeback.correlation' => 'ref']);
        Http::fake([
            '*/tasks/by-ref.json*' => Http::response(['data' => []]),
            '*/tasks/search.json*' => Http::response([
                'data' => [['id' => 7, 'payload' => ['dl_number' => 'DL-9001', 'pr_url' => 'https://github.com/owner/repo/pull/0']]],
                'meta' => ['total' => 1],
            ]),
        ]);

        $this->artisan('bridge:check')
            ->doesntExpectOutputToContain('source=null')
            ->assertExitCode(0);
    }

    public function test_check_no_source_warning_when_dl_card_sources_via_payload_repo(): void
    {
        // #3399 (review): the kanban derives source from payload.repo too (not just pr_url),
        // so a dl card with `repo` set (no pr_url) self-moves fine → NO false source=null warn.
        $this->writeWritebackWithToken();
        config(['bridge.writeback.correlation' => 'ref']);
        Http::fake([
            '*/tasks/by-ref.json*' => Http::response(['data' => []]),
            '*/tasks/search.json*' => Http::response([
                'data' => [['id' => 7, 'payload' => ['dl_number' => 'DL-9001', 'repo' => 'owner/repo']]],
                'meta' => ['total' => 1],
            ]),
        ]);

        $this->artisan('bridge:check')
            ->doesntExpectOutputToContain('source=null')
            ->assertExitCode(0);
    }

    public function test_check_warns_on_an_orphaned_writeback_mapping(): void
    {
        // #2162: a writeback.json mapping with no agent running a writeback-emitting
        // classifier subscribed to its github scope is inert — warn (exit 0). The
        // default writeAgent() only subscribes to kanban, so owner/repo is orphaned.
        $this->writeWritebackWithToken();
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => [['id' => 1]], 'meta' => ['total' => 1]])]);

        $this->artisan('bridge:check')
            ->expectsOutputToContain('mapping for owner/repo is ORPHANED')
            ->assertExitCode(0);
    }

    public function test_check_warns_on_orphaned_mapping_even_without_a_writeback_client(): void
    {
        // M1 regression: orphan detection must be INDEPENDENT of the board probe —
        // it must fire even when the writeback client can't be constructed (no
        // api_base_url / token), the half-configured install where it matters most.
        $this->writeAgent();   // kanban-only agent → owner/repo is orphaned
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => ['board_id' => 8, 'stages' => ['merged' => 52]]],
        ]));
        Http::fake();   // no token, no api_base_url → make() throws before any HTTP

        $this->artisan('bridge:check')
            ->expectsOutputToContain('mapping for owner/repo is ORPHANED')
            ->assertExitCode(0);
        Http::assertNothingSent();   // never reached the probe, yet still warned
    }

    public function test_check_no_orphan_warning_when_an_emitting_agent_is_subscribed(): void
    {
        // An agent running GitHubPrCardMoveClassifier (EmitsWritebackReactions)
        // subscribed to github:owner/repo DRIVES the mapping → not orphaned.
        File::put($this->dir.'/wb-agent.yml',
            "identity:\n  github_user_id: 41000\n"
            ."subscriptions:\n  - provider: github\n    scopes: [\"owner/repo\"]\n"
            ."classifier:\n  class: App\\Bridge\\Classifiers\\GitHubPrCardMoveClassifier\n");
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => ['board_id' => 8, 'stages' => ['merged' => 52]]],
        ]));
        File::ensureDirectoryExists($this->dir.'/kanban');
        File::put($this->dir.'/kanban/writeback-token', 'wb');
        chmod($this->dir.'/kanban/writeback-token', 0o600);
        config([
            'bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3',
            'bridge.writeback.correlation' => 'scan',
        ]);
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => [['id' => 1]], 'meta' => ['total' => 1]])]);

        $this->artisan('bridge:check')
            ->doesntExpectOutputToContain('ORPHANED')
            ->assertExitCode(0);
    }

    // --- card#4183 (DL-196): event-follows-consumer ---

    private function writeGithubAgent(string $name, string $classifierClass, ?string $familiesLine = null): void
    {
        $yaml = "identity:\n  github_user_id: ".crc32($name)."\n"
            ."subscriptions:\n  - provider: github\n    scopes: [\"owner/repo\"]\n"
            ."classifier:\n  class: {$classifierClass}\n";
        if ($familiesLine !== null) {
            $yaml .= "  config:\n    families: [{$familiesLine}]\n";
        }
        File::put($this->dir.'/'.$name.'.yml', $yaml);
    }

    private function githubEvent(string $eventType, string $delivery): void
    {
        WebhookEvent::create([
            'delivery_id' => $delivery, 'provider' => 'github', 'scope_id' => 'owner/repo',
            'event_type' => $eventType, 'actor_id' => '1', 'payload' => ['x' => 1],
        ]);
    }

    public function test_check_event_consumer_clean_under_the_union_of_two_agents_on_one_scope(): void
    {
        // Load-bearing AIMLA case: github:owner/repo is subscribed by TWO agents —
        // GitHubPrCardMoveClassifier ({pull_request,push}) + CoordinationClassifier
        // with impl-ci-wake ({push,workflow_run}). Observed {pull_request,push,
        // workflow_run} is fully covered by the UNION → CLEAN, no unconsumed warn.
        // A one-per-scope evaluation would false-WARN workflow_run — this locks it.
        $this->writeGithubAgent('wb', 'App\\Bridge\\Classifiers\\GitHubPrCardMoveClassifier');
        $this->writeGithubAgent('ci', 'App\\Bridge\\Classifiers\\CoordinationClassifier', 'impl-ci-wake');
        $this->githubEvent('pull_request.opened', 'e1');
        $this->githubEvent('push', 'e2');
        $this->githubEvent('workflow_run.completed', 'e3');

        $this->artisan('bridge:check')
            ->doesntExpectOutputToContain('no enabled classifier consumes it')
            ->assertExitCode(0);
    }

    public function test_check_event_consumer_warns_on_a_genuinely_unconsumed_event(): void
    {
        // Only GitHubPrCardMoveClassifier ({pull_request,push}) is subscribed, but a
        // workflow_run has ARRIVED for the scope → unconsumed → warn (never fail).
        $this->writeGithubAgent('wb', 'App\\Bridge\\Classifiers\\GitHubPrCardMoveClassifier');
        $this->githubEvent('pull_request.opened', 'e1');
        $this->githubEvent('workflow_run.completed', 'e2');

        $this->artisan('bridge:check')
            ->expectsOutputToContain("github:owner/repo has received 'workflow_run' (1x, last")
            ->assertExitCode(0);
    }

    public function test_check_event_consumer_warn_carries_occurrences_and_last_seen(): void
    {
        // #4321: the observed set is unbounded (retention is event-gated or manual),
        // so a single remediated stray WARNs on every run, indistinguishable from
        // live drift — the occurrence count + last-seen timestamp is the datum that
        // separates them, WITHOUT a recency window (which would let old-but-real
        // drift read CLEAN and invert the false-clean-impossible invariant).
        $this->writeGithubAgent('wb', 'App\\Bridge\\Classifiers\\GitHubPrCardMoveClassifier');
        $this->githubEvent('issue_comment.created', 'e1');
        $this->githubEvent('issue_comment.created', 'e2');
        WebhookEvent::query()->where('delivery_id', 'e1')->update(['received_at' => '2026-06-06 08:00:00']);
        WebhookEvent::query()->where('delivery_id', 'e2')->update(['received_at' => '2026-07-01 09:30:00']);

        $this->artisan('bridge:check')
            ->expectsOutputToContain("has received 'issue_comment' (2x, last 2026-07-01 09:30:00 UTC) but no enabled classifier consumes it")
            ->assertExitCode(0);
    }

    public function test_check_event_consumer_silent_when_nothing_has_arrived(): void
    {
        // A github agent subscribed but NO events received yet → nothing dropped →
        // no warn (an empty webhook_events is not a false clean).
        $this->writeGithubAgent('wb', 'App\\Bridge\\Classifiers\\GitHubPrCardMoveClassifier');

        $this->artisan('bridge:check')
            ->doesntExpectOutputToContain('no enabled classifier consumes it')
            ->assertExitCode(0);
    }

    public function test_check_event_consumer_disambiguates_an_undeclared_classifier(): void
    {
        // An agent running a classifier that does NOT implement DeclaresConsumedEvents
        // (the default InboxOnlyClassifier) contributes nothing to `consumed` → the
        // observed event lands in unconsumed (a possible FALSE positive). The check
        // co-emits a disambiguation line naming the undeclared classifier.
        $this->writeGithubAgent('legacy', 'App\\Bridge\\Classifiers\\InboxOnlyClassifier');
        $this->githubEvent('pull_request.opened', 'e1');

        $this->artisan('bridge:check')
            ->expectsOutputToContain('does not declare its consumed events')
            ->expectsOutputToContain("has received 'pull_request' (1x, last")
            ->assertExitCode(0);
    }

    // --- card #4354: action-level consumer declarations + the INFO inventory ---

    public function test_check_action_inventory_names_an_observed_action_no_family_declares(): void
    {
        // The motivating gap: coord-card-create declares issues.[opened,reopened]
        // (qualified, DL-198) — an issues.closed arriving on the scope was invisible
        // to the top-level compare. It now surfaces as an aggregated INFO line
        // (never a WARN: GitHub has no per-action unsubscribe), with count+last-seen.
        $this->writeGithubAgent('coord', 'App\\Bridge\\Classifiers\\CoordinationClassifier', "'coord-card-create'");
        $this->githubEvent('issues.opened', 'e1');
        $this->githubEvent('issues.closed', 'e2');
        $this->githubEvent('issues.closed', 'e3');
        WebhookEvent::query()->where('delivery_id', 'e3')->update(['received_at' => '2026-07-02 10:00:00']);
        WebhookEvent::query()->where('delivery_id', 'e2')->update(['received_at' => '2026-07-01 10:00:00']);

        $this->artisan('bridge:check')
            ->expectsOutputToContain("'issues' actions observed but not action-declared by any family: closed (2x, last 2026-07-02 10:00:00 UTC)")
            ->doesntExpectOutputToContain("has received 'issues'")   // the type IS consumed — no top-level WARN
            ->assertExitCode(0);
    }

    public function test_check_action_inventory_silent_when_a_bare_declaration_owns_the_type(): void
    {
        // A bare declaration means the type is OWNED — unlisted actions are
        // deliberate no-ops, not inventory. GitHubPrCardMoveClassifier declares
        // bare pull_request; a pull_request.synchronize must NOT inventory.
        $this->writeGithubAgent('wb', 'App\\Bridge\\Classifiers\\GitHubPrCardMoveClassifier');
        $this->githubEvent('pull_request.opened', 'e1');
        $this->githubEvent('pull_request.synchronize', 'e2');

        $this->artisan('bridge:check')
            ->doesntExpectOutputToContain('actions observed but not action-declared')
            ->assertExitCode(0);
    }

    public function test_check_action_inventory_honors_coord_extra_actions(): void
    {
        // DL-190's per-install allow-list extension IS consumption — an install
        // that added pull_request.synchronize via coord_extra_actions must not see
        // it inventoried (design-review find: the false INFO would land on exactly
        // the installs that customized).
        $yaml = "identity:\n  github_user_id: 77\n"
            ."subscriptions:\n  - provider: github\n    scopes: [\"owner/repo\"]\n"
            ."classifier:\n  class: App\\Bridge\\Classifiers\\CoordinationClassifier\n"
            ."  config:\n    families: [coord-message]\n"
            ."    coord_extra_actions:\n      pull_request: [synchronize]\n";
        File::put($this->dir.'/coord.yml', $yaml);
        $this->githubEvent('pull_request.opened', 'e1');
        $this->githubEvent('pull_request.synchronize', 'e2');
        $this->githubEvent('pull_request.labeled', 'e3');

        $this->artisan('bridge:check')
            ->expectsOutputToContain('actions observed but not action-declared by any family: labeled (1x')
            ->assertExitCode(0);
        // and synchronize is NOT in the line (covered by the extra-action):
        $this->artisan('bridge:check')
            ->doesntExpectOutputToContain('synchronize (1x')
            ->assertExitCode(0);
    }

    public function test_check_action_inventory_carries_the_undeclared_classifier_caveat(): void
    {
        // An undeclared classifier might consume an action just as it might a type
        // — the inventory says so instead of asserting a state it cannot back.
        $this->writeGithubAgent('coord', 'App\\Bridge\\Classifiers\\CoordinationClassifier', "'coord-card-create'");
        $this->writeGithubAgent('legacy', 'App\\Bridge\\Classifiers\\InboxOnlyClassifier');
        $this->githubEvent('issues.closed', 'e1');

        $this->artisan('bridge:check')
            ->expectsOutputToContain('possible false inventory')
            ->assertExitCode(0);
    }

    public function test_check_warn_compare_is_unchanged_by_qualified_declarations(): void
    {
        // Projection preserves WARN semantics: a scope with ONLY qualified issues
        // coverage still top-level-consumes issues (no WARN), and a genuinely
        // unconsumed type still WARNs alongside the inventory.
        $this->writeGithubAgent('coord', 'App\\Bridge\\Classifiers\\CoordinationClassifier', "'coord-card-create'");
        $this->githubEvent('issues.opened', 'e1');
        $this->githubEvent('workflow_run.completed', 'e2');

        $this->artisan('bridge:check')
            ->expectsOutputToContain("has received 'workflow_run' (1x, last")
            ->doesntExpectOutputToContain("has received 'issues'")
            ->assertExitCode(0);
    }

    public function test_check_confirms_a_mapping_swimlane_id_that_exists_on_the_board(): void
    {
        // DL-027: when a mapping pins a swimlane_id, bridge:check validates it
        // against the board's lanes so a deleted/wrong lane is caught at config
        // time rather than as a silent 422-no-op on the first created card.
        $this->writeAgent();
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => ['board_id' => 8, 'swimlane_id' => 31, 'stages' => ['merged' => 52]]],
        ]));
        File::ensureDirectoryExists($this->dir.'/kanban');
        File::put($this->dir.'/kanban/writeback-token', 'wb-token');
        chmod($this->dir.'/kanban/writeback-token', 0o600);
        config([
            'bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3',
            'bridge.writeback.correlation' => 'scan',
        ]);
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 1, 'payload' => []]]]),
            '*/boards/8/preload.json' => Http::response(['data' => ['swimlanes' => [['id' => 31], ['id' => 32]]]]),
        ]);

        $this->artisan('bridge:check')
            ->expectsOutputToContain('swimlane_id 31 ok on board 8')
            ->assertExitCode(0);
    }

    public function test_check_warns_when_a_mapping_swimlane_id_is_not_on_the_board(): void
    {
        $this->writeAgent();
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => ['board_id' => 8, 'swimlane_id' => 99, 'stages' => ['merged' => 52]]],
        ]));
        File::ensureDirectoryExists($this->dir.'/kanban');
        File::put($this->dir.'/kanban/writeback-token', 'wb-token');
        chmod($this->dir.'/kanban/writeback-token', 0o600);
        config([
            'bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3',
            'bridge.writeback.correlation' => 'scan',
        ]);
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 1, 'payload' => []]]]),
            '*/boards/8/preload.json' => Http::response(['data' => ['swimlanes' => [['id' => 31], ['id' => 32]]]]),
        ]);

        $this->artisan('bridge:check')
            ->expectsOutputToContain('swimlane_id 99 not found on board 8')
            ->assertExitCode(0);
    }

    public function test_check_warns_when_a_dependabot_mapping_board_lacks_the_create_payload_custom_fields(): void
    {
        // #2949: create_dependabot_cards=true but the board is missing a custom
        // field the create payload sets (here pr_url) → every create 422s and is
        // silently swallowed. bridge:check names it at config time (DL-026 posture).
        $this->writeAgent();
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => ['board_id' => 8, 'create_dependabot_cards' => true, 'stages' => ['merged' => 52]]],
        ]));
        File::ensureDirectoryExists($this->dir.'/kanban');
        File::put($this->dir.'/kanban/writeback-token', 'wb-token');
        chmod($this->dir.'/kanban/writeback-token', 0o600);
        config([
            'bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3',
            'bridge.writeback.correlation' => 'scan',
        ]);
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 1, 'payload' => []]]]),
            // Board has pr_number + origin but NOT pr_url.
            '*/boards/8/custom_fields.json' => Http::response(['data' => [['key' => 'pr_number'], ['key' => 'origin']]]),
        ]);

        $this->artisan('bridge:check')
            ->expectsOutputToContain('create_dependabot_cards is on for owner/repo but board 8 is MISSING the custom field(s) pr_url')
            ->assertExitCode(0);
    }

    public function test_check_confirms_a_dependabot_mapping_board_with_all_create_payload_custom_fields(): void
    {
        $this->writeAgent();
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => ['board_id' => 8, 'create_dependabot_cards' => true, 'stages' => ['merged' => 52]]],
        ]));
        File::ensureDirectoryExists($this->dir.'/kanban');
        File::put($this->dir.'/kanban/writeback-token', 'wb-token');
        chmod($this->dir.'/kanban/writeback-token', 0o600);
        config([
            'bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3',
            'bridge.writeback.correlation' => 'scan',
        ]);
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 1, 'payload' => []]]]),
            '*/boards/8/custom_fields.json' => Http::response(['data' => [['key' => 'pr_number'], ['key' => 'pr_url'], ['key' => 'origin']]]),
        ]);

        $this->artisan('bridge:check')
            ->expectsOutputToContain('create_dependabot_cards custom fields ok on board 8')
            ->assertExitCode(0);
    }

    /** @param array<string,mixed> $extra */
    private function writeCoordAllMapping(array $extra = [], ?string $coordConfigPath = null): void
    {
        $this->writeAgent();
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => array_merge([
                'board_id' => 8, 'stages' => ['merged' => 52],
                'create_coord_cards' => true, 'coord_card_stage_id' => 21, 'issue_population' => 'all',
            ], $extra)],
        ]));
        File::ensureDirectoryExists($this->dir.'/kanban');
        File::put($this->dir.'/kanban/writeback-token', 'wb-token');
        chmod($this->dir.'/kanban/writeback-token', 0o600);
        config([
            'bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3',
            'bridge.writeback.correlation' => 'scan',
            'bridge.writeback.coord_config_path' => $coordConfigPath,
        ]);
    }

    public function test_check_fails_closed_when_issue_population_all_and_board_lacks_issue_number(): void
    {
        // #4553 FAIL-CLOSED: population=all needs the issue_number custom field or every
        // by-ref create 422s permanently AND by-ref correlation can't tell no-match from
        // not-indexed → silent double-card. This is a hard failure, not a warn.
        $this->writeCoordAllMapping();
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 1, 'payload' => []]]]),
            '*/boards/8/custom_fields.json' => Http::response(['data' => [['key' => 'dl_number']]]),   // no issue_number
        ]);

        $this->artisan('bridge:check')
            ->expectsOutputToContain("issue_population=all for owner/repo but board 8 does not register the 'issue_number' custom field")
            ->assertExitCode(1);
    }

    public function test_check_passes_when_issue_population_all_and_board_registers_issue_number(): void
    {
        $this->writeCoordAllMapping();
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 1, 'payload' => []]]]),
            '*/boards/8/custom_fields.json' => Http::response(['data' => [['key' => 'issue_number'], ['key' => 'issue_url']]]),
        ]);

        $this->artisan('bridge:check')
            ->expectsOutputToContain('issue_number custom field registered on board 8')
            ->assertExitCode(0);   // CANNOT-VERIFY on the compare (no $COORD_CONFIG) is a warn, not a fail
    }

    public function test_check_warns_when_bridge_all_disagrees_with_reconcile_prefixed(): void
    {
        // The load-bearing DISAGREE: bridge=all + reconcile=prefixed = the non-prefixed
        // no-backstop gap, now checkable rather than silent.
        $coordPath = $this->dir.'/coord.json';
        File::put($coordPath, (string) json_encode(['kanban' => ['boards' => [['board_id' => 8, 'issue_population' => 'prefixed']]]]));
        $this->writeCoordAllMapping(coordConfigPath: $coordPath);
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 1, 'payload' => []]]]),
            '*/boards/8/custom_fields.json' => Http::response(['data' => [['key' => 'issue_number']]]),
        ]);

        $this->artisan('bridge:check')
            ->expectsOutputToContain('DISAGREE on issue_population')
            ->assertExitCode(0);   // warn-never-fail
    }

    public function test_check_reports_issue_population_agreement_when_reconcile_also_all(): void
    {
        $coordPath = $this->dir.'/coord.json';
        File::put($coordPath, (string) json_encode(['kanban' => ['boards' => [['board_id' => 8, 'issue_population' => 'all']]]]));
        $this->writeCoordAllMapping(coordConfigPath: $coordPath);
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 1, 'payload' => []]]]),
            '*/boards/8/custom_fields.json' => Http::response(['data' => [['key' => 'issue_number']]]),
        ]);

        $this->artisan('bridge:check')
            ->expectsOutputToContain('coord config agrees')
            ->assertExitCode(0);
    }

    public function test_check_fails_closed_when_issue_number_read_throws_under_all(): void
    {
        // A fail-closed invariant that cannot be VERIFIED (the custom-fields read errors)
        // must exit non-zero, not degrade to a warn — else an unverifiable `all` board
        // certifies green and the silent double-card path ships.
        $this->writeCoordAllMapping();
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 1, 'payload' => []]]]),
            '*/boards/8/custom_fields.json' => Http::response(['error' => 'boom'], 500),
        ]);

        $this->artisan('bridge:check')
            ->expectsOutputToContain("could NOT read board 8's custom fields to verify issue_number")
            ->assertExitCode(1);
    }

    public function test_check_fails_closed_for_a_move_only_mapping_missing_issue_number_under_all(): void
    {
        // The preflight is gated on create OR move — a move-only mapping under `all` also
        // correlates non-prefixed cards by-ref, so it needs issue_number too.
        $this->writeAgent();
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => [
                'board_id' => 8, 'stages' => ['opened' => 50],
                'move_coord_cards' => true, 'coord_card_stage_id' => 21, 'coord_card_terminal_stage_id' => 99,
                'issue_population' => 'all',   // create_coord_cards intentionally OFF
            ]],
        ]));
        File::ensureDirectoryExists($this->dir.'/kanban');
        File::put($this->dir.'/kanban/writeback-token', 'wb-token');
        chmod($this->dir.'/kanban/writeback-token', 0o600);
        config([
            'bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3',
            'bridge.writeback.correlation' => 'scan',
        ]);
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 1, 'payload' => []]]]),
            '*/boards/8/custom_fields.json' => Http::response(['data' => [['key' => 'dl_number']]]),   // no issue_number
        ]);

        $this->artisan('bridge:check')
            ->expectsOutputToContain("board 8 does not register the 'issue_number' custom field")
            ->assertExitCode(1);
    }

    public function test_check_warns_when_population_all_paired_with_scan_correlation(): void
    {
        // by-ref correlation is only correct in `ref` mode; scan can't repo-disambiguate.
        $this->writeCoordAllMapping();   // helper pins correlation=scan
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 1, 'payload' => []]]]),
            '*/boards/8/custom_fields.json' => Http::response(['data' => [['key' => 'issue_number']]]),
        ]);

        $this->artisan('bridge:check')
            ->expectsOutputToContain('BRIDGE_WRITEBACK_CORRELATION is not `ref`')
            ->assertExitCode(0);   // warn, not fail
    }

    public function test_check_skips_the_dependabot_custom_field_probe_when_the_flag_is_off(): void
    {
        // create_dependabot_cards absent → the mapping never creates cards, so the
        // custom-field requirement does not apply and the probe must not fire.
        $this->writeAgent();
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => ['board_id' => 8, 'stages' => ['merged' => 52]]],
        ]));
        File::ensureDirectoryExists($this->dir.'/kanban');
        File::put($this->dir.'/kanban/writeback-token', 'wb-token');
        chmod($this->dir.'/kanban/writeback-token', 0o600);
        config([
            'bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3',
            'bridge.writeback.correlation' => 'scan',
        ]);
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 1, 'payload' => []]]]),
        ]);

        $this->artisan('bridge:check')
            ->doesntExpectOutputToContain('create_dependabot_cards custom fields')
            ->assertExitCode(0);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'custom_fields.json'));
    }

    public function test_check_warns_when_started_stage_is_set_without_started_from_stages(): void
    {
        // #2652: the DL-160 `started` trigger needs BOTH; with only stages.started
        // it's silently inert (refused for lack of a promote-from set).
        $this->writeAgent();
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => ['board_id' => 8, 'stages' => ['started' => 49]]],
        ]));
        config(['bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3', 'bridge.writeback.correlation' => 'scan']);
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => []])]);

        $this->artisan('bridge:check')
            ->expectsOutputToContain('sets stages.started but not started_from_stages')
            ->assertExitCode(0);
    }

    public function test_check_warns_when_started_from_stages_is_set_without_started_stage(): void
    {
        $this->writeAgent();
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => ['board_id' => 8, 'stages' => ['merged' => 52], 'started_from_stages' => [46, 47]]],
        ]));
        config(['bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3', 'bridge.writeback.correlation' => 'scan']);
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => []])]);

        $this->artisan('bridge:check')
            ->expectsOutputToContain('sets started_from_stages but not stages.started')
            ->assertExitCode(0);
    }

    public function test_check_no_started_half_config_warning_when_both_are_set(): void
    {
        $this->writeAgent();
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => ['board_id' => 8, 'stages' => ['started' => 49], 'started_from_stages' => [46, 47]]],
        ]));
        config(['bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3', 'bridge.writeback.correlation' => 'scan']);
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => []])]);

        $this->artisan('bridge:check')
            ->doesntExpectOutputToContain('the branch-create `started` trigger (DL-160) needs BOTH')
            ->assertExitCode(0);
    }

    public function test_check_warns_on_a_mapped_stage_id_not_on_the_board(): void
    {
        // #2652: a typo'd stage id silently 422s the move / never matches the guard.
        $this->writeAgent();
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => ['board_id' => 8, 'stages' => ['merged' => 52, 'opened' => 9999]]],
        ]));
        File::ensureDirectoryExists($this->dir.'/kanban');
        File::put($this->dir.'/kanban/writeback-token', 'wb-token');
        chmod($this->dir.'/kanban/writeback-token', 0o600);
        config(['bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3', 'bridge.writeback.correlation' => 'scan']);
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 1, 'payload' => []]]]),
            '*/boards/8/preload.json' => Http::response(['data' => ['workflows' => [['stages' => [
                ['id' => 49, 'position' => 1024.0], ['id' => 50, 'position' => 2048.0], ['id' => 52, 'position' => 3072.0],
            ]]]]]),
        ]);

        $this->artisan('bridge:check')
            ->expectsOutputToContain('references workflow stage id(s) 9999 not on board 8')
            ->assertExitCode(0);
    }

    public function test_check_warns_when_coord_card_stage_id_is_not_on_the_board(): void
    {
        // DL-198: a typo'd coord_card_stage_id silently 422s every coord-card create,
        // same class as a mapped stage id not on the board.
        $this->writeAgent();
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => ['board_id' => 8, 'stages' => ['opened' => 50], 'create_coord_cards' => true, 'coord_card_stage_id' => 9999]],
        ]));
        File::ensureDirectoryExists($this->dir.'/kanban');
        File::put($this->dir.'/kanban/writeback-token', 'wb-token');   // gitleaks:allow — test fixture
        chmod($this->dir.'/kanban/writeback-token', 0o600);
        config(['bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3', 'bridge.writeback.correlation' => 'scan']);
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 1, 'payload' => []]]]),
            '*/boards/8/preload.json' => Http::response(['data' => ['workflows' => [['stages' => [
                ['id' => 49, 'position' => 1024.0], ['id' => 50, 'position' => 2048.0], ['id' => 52, 'position' => 3072.0],
            ]]]]]),
        ]);

        $this->artisan('bridge:check')
            ->expectsOutputToContain('references workflow stage id(s) 9999 not on board 8')
            ->assertExitCode(0);
    }

    public function test_check_warns_when_create_coord_cards_set_but_identity_id_null(): void
    {
        // DL-198 R5: the echo-gate guard. Without identity_id a created coord card's
        // task.created echoes back and could self-wake a kanban-triage session.
        // Config-only warn (no board read needed), never fails.
        $this->writeAgent();
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'mappings' => ['owner/repo' => ['board_id' => 8, 'stages' => ['opened' => 50], 'create_coord_cards' => true, 'coord_card_stage_id' => 21]],
        ]));
        Http::fake();   // no token → board probe self-skips; the config-only warn fires regardless

        $this->artisan('bridge:check')
            ->expectsOutputToContain('create_coord_cards but writeback.json has no identity_id')
            ->assertExitCode(0);
    }

    // ---- DL-200: the coord-config cross-config terminal compare ----

    /** Write a writeback.json with the move leg on, + the board fake. Returns nothing. */
    private function writeMoveLegInstall(int $terminalStageId = 53): void
    {
        // An agent that actually DRIVES the move leg: subscribed to github:owner/repo with the
        // coord-card-move family enabled (gate 1), so the mapping isn't orphaned and the DL-204
        // family gate lets the terminal-agreement compare run.
        File::put($this->dir.'/prod-agent.yml', "identity:\n  kanban_user_id: 137\n  github_user_id: 555\n"
            ."subscriptions:\n  - provider: kanban\n    scopes: [5]\n"
            ."  - provider: github\n    scopes: [\"owner/repo\"]\n"
            ."classifier:\n  class: App\\Bridge\\Classifiers\\CoordinationClassifier\n"
            ."  config:\n    families: [coord-message, coord-card-move]\n");
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => ['board_id' => 8, 'stages' => ['opened' => 50],
                'move_coord_cards' => true, 'coord_card_stage_id' => 50,
                'coord_card_terminal_stage_id' => $terminalStageId]],
        ]));
        File::ensureDirectoryExists($this->dir.'/kanban');
        File::put($this->dir.'/kanban/writeback-token', 'wb-token');   // gitleaks:allow — test fixture
        chmod($this->dir.'/kanban/writeback-token', 0o600);
        config(['bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3', 'bridge.writeback.correlation' => 'scan']);
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 1, 'payload' => []]]]),
            '*/boards/8/preload.json' => Http::response(['data' => ['workflows' => [['stages' => [
                ['id' => 50, 'name' => 'In Progress', 'position' => 1024.0],
                ['id' => 53, 'name' => 'Done', 'position' => 2048.0],
                ['id' => 54, 'name' => "Won't Do", 'position' => 3072.0],
            ]]]]]),
        ]);
    }

    /** @param array<string, mixed> $config */
    private function writeCoordConfig(array $config): string
    {
        $p = $this->dir.'/coordination.config.json';
        File::put($p, (string) json_encode($config));
        config(['bridge.writeback.coord_config_path' => $p]);

        return $p;
    }

    public function test_check_falls_back_to_the_ambient_coord_config_env_when_no_override_is_set(): void
    {
        // The getenv() leg. Pinned because it is the ONLY leg that survives
        // `php artisan optimize`: config/bridge.php resolves BRIDGE_COORD_CONFIG_PATH
        // via env(), which config-caching FREEZES at deploy time. If the ambient
        // $COORD_CONFIG were read there too it would freeze to the deploying shell's
        // value (usually nothing) and this "mandatory" compare would report
        // CANNOT-VERIFY forever — present, running, never doing its job. So the ambient
        // fallback MUST be read live at this CLI read-site, and that must not silently
        // regress to an env() lookup in some future tidy-up.
        $this->writeMoveLegInstall(terminalStageId: 53);
        $p = $this->dir.'/ambient-coordination.config.json';
        File::put($p, (string) json_encode(['kanban' => ['boards' => [
            ['key' => 'issues', 'board_id' => 8, 'user_lanes' => ['Now']],
        ]]]));
        config(['bridge.writeback.coord_config_path' => null]);   // no per-install override
        putenv("COORD_CONFIG={$p}");

        try {
            $this->artisan('bridge:check')
                ->expectsOutputToContain('coord config agrees')
                ->assertExitCode(0);
        } finally {
            putenv('COORD_CONFIG');
        }
    }

    public function test_check_prefers_the_per_install_override_over_the_ambient_env(): void
    {
        // Two installs on one host share ONE ambient $COORD_CONFIG. The .env override
        // must WIN, or a -prod install silently compares against a -dev operator's
        // coordination project and reports a confident, wrong answer.
        $this->writeMoveLegInstall(terminalStageId: 53);
        $override = $this->dir.'/override.json';
        File::put($override, (string) json_encode(['kanban' => ['boards' => [
            ['key' => 'issues', 'board_id' => 8, 'user_lanes' => ['Now']],       // -> "Done" = 53 = agrees
        ]]]));
        $ambient = $this->dir.'/ambient.json';
        File::put($ambient, (string) json_encode(['kanban' => ['boards' => [
            ['key' => 'issues', 'board_id' => 8, 'terminal_columns' => ["Won't Do"]],   // -> 54 = would DISAGREE
        ]]]));
        config(['bridge.writeback.coord_config_path' => $override]);
        putenv("COORD_CONFIG={$ambient}");

        try {
            $this->artisan('bridge:check')
                ->expectsOutputToContain('coord config agrees')   // the override won
                ->doesntExpectOutputToContain('DISAGREE')          // the ambient did NOT
                ->assertExitCode(0);
        } finally {
            putenv('COORD_CONFIG');
        }
    }

    public function test_check_agrees_when_the_coord_config_terminal_resolves_to_the_mapped_stage(): void
    {
        // The lane-model fallback path: the canonical `issues` board declares user_lanes
        // and NO terminal_columns → resolves to "Done" → stage 53 → agrees with the
        // mapping. This is the case a literal terminal_columns read would have MISSED.
        $this->writeMoveLegInstall(terminalStageId: 53);
        $this->writeCoordConfig(['kanban' => ['boards' => [
            ['key' => 'issues', 'board_id' => 8, 'user_lanes' => ['Now', 'Next']],
        ]]]);

        $this->artisan('bridge:check')
            ->expectsOutputToContain('coord config agrees')
            ->assertExitCode(0);
    }

    public function test_check_warns_when_the_two_movers_disagree_on_the_terminal(): void
    {
        // THE case the compare exists for (Q1): the bridge concludes cards into stage 53
        // while the reconcile treats "Won't Do" (54) as terminal → they fight every cycle.
        $this->writeMoveLegInstall(terminalStageId: 53);
        $this->writeCoordConfig(['kanban' => ['boards' => [
            ['key' => 'issues', 'board_id' => 8, 'terminal_columns' => ["Won't Do"]],
        ]]]);

        $this->artisan('bridge:check')
            ->expectsOutputToContain('DISAGREE')
            ->assertExitCode(0);   // never fails the bridge (condition (b))
    }

    public function test_check_cannot_verify_when_coord_config_is_absent(): void
    {
        // Condition (a): a missing input is NOT evidence of agreement — it is evidence
        // we could not ask. Must NOT print "agrees".
        $this->writeMoveLegInstall();
        config(['bridge.writeback.coord_config_path' => '/nonexistent/coordination.config.json']);

        $this->artisan('bridge:check')
            ->expectsOutputToContain('CANNOT VERIFY')
            ->doesntExpectOutputToContain('coord config agrees')
            ->assertExitCode(0);
    }

    public function test_check_cannot_verify_when_coord_config_is_malformed(): void
    {
        $this->writeMoveLegInstall();
        $p = $this->dir.'/coordination.config.json';
        File::put($p, '{not json');
        config(['bridge.writeback.coord_config_path' => $p]);

        $this->artisan('bridge:check')
            ->expectsOutputToContain('CANNOT VERIFY')
            ->doesntExpectOutputToContain('coord config agrees')
            ->assertExitCode(0);
    }

    public function test_check_cannot_verify_when_the_board_has_no_coord_config_entry(): void
    {
        // The coord config exists but knows nothing about this board — we cannot ask.
        $this->writeMoveLegInstall();
        $this->writeCoordConfig(['kanban' => ['boards' => [
            ['key' => 'issues', 'board_id' => 999, 'user_lanes' => ['Now']],
        ]]]);

        $this->artisan('bridge:check')
            ->expectsOutputToContain('CANNOT VERIFY')
            ->doesntExpectOutputToContain('coord config agrees')
            ->assertExitCode(0);
    }

    public function test_check_cannot_verify_when_the_board_resolves_several_terminals(): void
    {
        // >1 terminal is legal framework-wide, but the MOVER needs exactly one column to
        // write into — so which one it should agree with is genuinely unknowable here.
        $this->writeMoveLegInstall();
        $this->writeCoordConfig(['kanban' => ['boards' => [
            ['key' => 'prs', 'board_id' => 8, 'terminal_columns' => ['Done']],
            ['key' => 'product-tasks', 'board_id' => 8, 'terminal_columns' => ["Won't Do"]],
        ]]]);

        $this->artisan('bridge:check')
            ->expectsOutputToContain('CANNOT VERIFY')
            ->doesntExpectOutputToContain('coord config agrees')
            ->assertExitCode(0);
    }

    public function test_check_cannot_verify_when_the_terminal_name_is_not_on_the_board(): void
    {
        $this->writeMoveLegInstall();
        $this->writeCoordConfig(['kanban' => ['boards' => [
            ['key' => 'issues', 'board_id' => 8, 'terminal_columns' => ['Nonexistent Column']],
        ]]]);

        $this->artisan('bridge:check')
            ->expectsOutputToContain('CANNOT VERIFY')
            ->doesntExpectOutputToContain('coord config agrees')
            ->assertExitCode(0);
    }

    // ---- DL-204 (#4357): the fleet-default no-silent-inert discovery nudge + family gating ----

    public function test_check_warns_when_the_coord_card_move_family_is_enabled_but_the_terminal_is_unset(): void
    {
        // Gate 1 (coord-card-move family) on, gate 2 inert (no coord_card_terminal_stage_id ⇒ the
        // fleet default resolves move_coord_cards false): issues.closed/reopened are classified but
        // no card moves — silent-inert. The config-only nudge names the activation path.
        $this->writeMoveLegInstall();   // writes the coord-card-move family agent + plumbing
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => ['board_id' => 8, 'stages' => ['opened' => 50], 'coord_card_stage_id' => 50]],
        ]));   // terminal + move flag omitted ⇒ inert

        $this->artisan('bridge:check')
            ->expectsOutputToContain('enables the coord-card-move family but its writeback mapping has no coord_card_terminal_stage_id')
            ->assertExitCode(0);
    }

    public function test_check_does_not_nudge_the_move_leg_for_a_pure_pr_writeback_install(): void
    {
        // The nudge is scoped to family-enabled scopes — a pure PR-lifecycle writeback (no
        // coord-card-move family) gets NO coord-move noise even with a terminal-less mapping
        // (DL-196 no-false-alarm posture).
        $this->writeGithubAgent('prod-agent', 'App\\Bridge\\Classifiers\\GitHubPrCardMoveClassifier');
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => ['board_id' => 8, 'stages' => ['opened' => 50]]],
        ]));

        $this->artisan('bridge:check')
            ->doesntExpectOutputToContain('enables the coord-card-move family')
            ->assertExitCode(0);
    }

    public function test_check_warns_when_the_terminal_is_set_but_the_move_family_is_not_enabled(): void
    {
        // DL-204 MIRROR silent-inert: gate 2 on (terminal present ⇒ default move_coord_cards true)
        // but gate 1 off (the serving coord agent lacks the coord-card-move family) ⇒ the handler
        // would move but nothing classifies a move ⇒ dead leg. bridge:check must nudge (the
        // adoption-path death — set the terminal but forget the family — DL-204 warns against).
        $this->writeGithubAgent('prod-agent', 'App\\Bridge\\Classifiers\\CoordinationClassifier', 'coord-message, coord-card-create');
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => ['board_id' => 8, 'stages' => ['opened' => 50],
                'coord_card_stage_id' => 50, 'coord_card_terminal_stage_id' => 53]],
        ]));   // terminal present + move flag absent ⇒ default on; family lacks coord-card-move

        $this->artisan('bridge:check')
            ->expectsOutputToContain('no agent enables the coord-card-move family on that scope')
            ->assertExitCode(0);
    }

    public function test_check_skips_the_terminal_compare_when_the_move_family_is_not_enabled(): void
    {
        // Finding-1 gate: after the DL-204 flip move_coord_cards can resolve true from
        // terminal-presence alone. Without the coord-card-move family (gate 1) the leg cannot
        // fire, so the terminal-agreement compare must NOT run and imply the leg is live.
        $this->writeGithubAgent('prod-agent', 'App\\Bridge\\Classifiers\\GitHubPrCardMoveClassifier');
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => ['board_id' => 8, 'stages' => ['opened' => 50],
                'coord_card_stage_id' => 50, 'coord_card_terminal_stage_id' => 53]],
        ]));   // move_coord_cards absent + terminal present ⇒ default resolves true, but family off
        $this->writeCoordConfig(['kanban' => ['boards' => [
            ['key' => 'issues', 'board_id' => 8, 'terminal_columns' => ['Done']],
        ]]]);

        $this->artisan('bridge:check')
            ->doesntExpectOutputToContain('coord config agrees')
            ->doesntExpectOutputToContain('the two movers DISAGREE')
            ->assertExitCode(0);
    }

    public function test_check_does_not_run_the_coord_compare_when_the_move_leg_is_off(): void
    {
        // Nothing to verify when the leg is off — no CANNOT-VERIFY noise on the
        // overwhelming majority of installs that never enable it.
        $this->writeAgent();
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => ['board_id' => 8, 'stages' => ['opened' => 50]]],
        ]));
        Http::fake();

        $this->artisan('bridge:check')
            ->doesntExpectOutputToContain('coord config')
            ->assertExitCode(0);
    }

    public function test_check_skips_the_board_probe_without_a_base_url_and_makes_no_request(): void
    {
        // Guard-lock (S3): the probe block IS reached (writeback.json + mapping),
        // but with no api_base_url the factory throws → the probe self-skips and
        // must make NO stray network call. Locks the base-url guard so a refactor
        // that drops it fails here.
        $this->writeAgent();
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => ['board_id' => 8, 'stages' => ['merged' => 52]]],
        ]));
        File::ensureDirectoryExists($this->dir.'/kanban');
        File::put($this->dir.'/kanban/writeback-token', 'wb-token');
        chmod($this->dir.'/kanban/writeback-token', 0o600);
        // Explicitly unset api_base_url (the dev .env populates it) → the factory
        // throws ConfigException → the probe self-skips, no network call.
        config(['bridge.providers.kanban.api_base_url' => null]);
        Http::fake();

        $this->artisan('bridge:check')
            ->expectsOutputToContain('skipped board-visibility probe')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_check_warns_on_group_accessible_config_dir(): void
    {
        // DL-014: the config dir holds secrets → warn (not fail) if it's not 0700.
        $this->writeAgent();
        chmod($this->dir, 0o755);
        $this->artisan('bridge:check')
            ->expectsOutputToContain('group/world-accessible')
            ->assertExitCode(0);
    }

    public function test_inbox_collapses_duplicate_ids_on_read(): void
    {
        // DL-012: the writer is append-only, so a partial-staging redelivery can
        // leave two lines with the same id — bridge:inbox must surface it once.
        File::ensureDirectoryExists($this->dir.'/state');
        File::put($this->dir.'/state/inbox.jsonl',
            json_encode(['id' => 'e:agent:0', 'ts' => 1.0, 'kind' => 'new_card', 'summary' => 'dup'])."\n".
            json_encode(['id' => 'e:agent:0', 'ts' => 1.0, 'kind' => 'new_card', 'summary' => 'dup'])."\n",
        );

        $this->artisan('bridge:inbox', ['--hook-format' => 'plain'])
            ->expectsOutputToContain('new_card')
            ->assertExitCode(0);

        // Surfaced once → cursor records exactly one id; a second run is silent.
        $this->assertSame(['e:agent:0'], json_decode((string) File::get($this->dir.'/state/inbox-seen.json'), true));
        $this->artisan('bridge:inbox', ['--hook-format' => 'plain'])->doesntExpectOutputToContain('new_card')->assertExitCode(0);
    }

    public function test_prune_deletes_events_and_dispatches_older_than(): void
    {
        $old = $this->event();
        $old->received_at = now()->subDays(40);
        $old->save();
        AgentDispatch::create(['webhook_event_id' => $old->id, 'agent_name' => 'prod-agent']);

        $recent = WebhookEvent::create([
            'delivery_id' => 'evt-2', 'provider' => 'kanban', 'scope_id' => '5',
            'event_type' => 'task.created', 'actor_id' => '1', 'payload' => ['x' => 1],
        ]);

        $this->artisan('bridge:prune', ['--older-than' => '30d'])->assertExitCode(0);

        $this->assertNull(WebhookEvent::find($old->id));                 // deleted
        $this->assertSame(0, AgentDispatch::where('webhook_event_id', $old->id)->count());   // cascade
        $this->assertNotNull(WebhookEvent::find($recent->id));           // recent kept
    }

    public function test_prune_nulls_payloads_older_than_keeping_the_row(): void
    {
        $e = $this->event();
        $e->received_at = now()->subDays(10);
        $e->save();

        $this->artisan('bridge:prune', ['--null-payloads-older-than' => '7d'])->assertExitCode(0);

        $e->refresh();
        $this->assertNotNull($e->id);          // row kept (dedup gate + audit)
        $this->assertNull($e->payload);        // body shed
    }

    public function test_prune_trims_inbox_lines_and_seen_cursor(): void
    {
        File::ensureDirectoryExists($this->dir.'/state');
        $oldTs = (float) now()->subDays(40)->format('U.u');
        $newTs = (float) now()->format('U.u');
        File::put($this->dir.'/state/inbox.jsonl',
            json_encode(['id' => 'old:0', 'ts' => $oldTs, 'kind' => 'x', 'summary' => 'old'])."\n".
            json_encode(['id' => 'new:0', 'ts' => $newTs, 'kind' => 'x', 'summary' => 'new'])."\n",
        );
        File::put($this->dir.'/state/inbox-seen.json', json_encode(['old:0', 'new:0']));

        $this->artisan('bridge:prune', ['--older-than' => '30d'])->assertExitCode(0);

        $remaining = array_column(BridgePaths::readJsonl($this->dir.'/state/inbox.jsonl'), 'id');
        $this->assertSame(['new:0'], $remaining);                        // old line trimmed
        $this->assertSame(['new:0'], json_decode((string) File::get($this->dir.'/state/inbox-seen.json'), true));   // seen bounded
    }

    public function test_prune_bounds_a_shared_layout_per_agent_seen_cursor(): void
    {
        // Positive control for the DL-012 retention gap: under shared layout,
        // bridge:inbox --agent=pm advances inbox-seen-pm.json while reading the
        // SHARED inbox.jsonl (no inbox-pm.jsonl on disk). The old prune paired
        // seen-cursors to inbox FILES, so inbox-seen-pm.json had nothing to pair
        // with and grew unbounded — prune must bound it against the surviving ids.
        File::ensureDirectoryExists($this->dir.'/state');
        $oldTs = (float) now()->subDays(40)->format('U.u');
        $newTs = (float) now()->format('U.u');
        File::put($this->dir.'/state/inbox.jsonl',
            json_encode(['id' => 'old:pm:0', 'ts' => $oldTs, 'agent' => 'pm', 'kind' => 'x', 'summary' => 'old'])."\n".
            json_encode(['id' => 'new:pm:0', 'ts' => $newTs, 'agent' => 'pm', 'kind' => 'x', 'summary' => 'new'])."\n",
        );
        // pm's per-agent cursor (advanced by prior shared-layout --agent=pm runs).
        // No inbox-pm.jsonl exists — that is the whole point of the gap.
        File::put($this->dir.'/state/inbox-seen-pm.json', json_encode(['old:pm:0', 'new:pm:0']));

        $this->artisan('bridge:prune', ['--older-than' => '30d'])->assertExitCode(0);

        $remaining = array_column(BridgePaths::readJsonl($this->dir.'/state/inbox.jsonl'), 'id');
        $this->assertSame(['new:pm:0'], $remaining);                     // shared inbox trimmed (harness sanity)
        // The aged-out id is bounded out of pm's cursor even with no paired inbox file.
        $this->assertSame(['new:pm:0'], json_decode((string) File::get($this->dir.'/state/inbox-seen-pm.json'), true));
    }

    public function test_prune_dry_run_leaves_per_agent_seen_cursor_untouched(): void
    {
        File::ensureDirectoryExists($this->dir.'/state');
        $oldTs = (float) now()->subDays(40)->format('U.u');
        File::put($this->dir.'/state/inbox.jsonl',
            json_encode(['id' => 'old:pm:0', 'ts' => $oldTs, 'agent' => 'pm', 'kind' => 'x', 'summary' => 'old'])."\n");
        File::put($this->dir.'/state/inbox-seen-pm.json', json_encode(['old:pm:0']));

        $this->artisan('bridge:prune', ['--older-than' => '30d', '--dry-run' => true])->assertExitCode(0);

        // Dry-run changes nothing: the would-be-pruned cursor id survives.
        $this->assertSame(['old:pm:0'], json_decode((string) File::get($this->dir.'/state/inbox-seen-pm.json'), true));
    }

    public function test_prune_dry_run_changes_nothing(): void
    {
        $old = $this->event();
        $old->received_at = now()->subDays(40);
        $old->save();

        $this->artisan('bridge:prune', ['--older-than' => '30d', '--dry-run' => true])->assertExitCode(0);

        $this->assertNotNull(WebhookEvent::find($old->id));   // still there
    }

    public function test_prune_requires_a_window(): void
    {
        $this->artisan('bridge:prune')->assertExitCode(1);
    }

    public function test_prune_rejects_an_absurd_window_without_deleting(): void
    {
        // A 20-digit value would overflow now()->subDays() into a FUTURE cutoff
        // and wipe everything — it must be rejected, changing nothing.
        $old = $this->event();
        $old->received_at = now()->subDays(40);
        $old->save();

        $this->artisan('bridge:prune', ['--older-than' => '99999999999999999999d'])->assertExitCode(1);
        $this->assertNotNull(WebhookEvent::find($old->id));
    }

    // --- card#4322 (DL-199): retention characterization ---
    // The window parser is the only input guard on a destructive command, and
    // the inbox trim's fail-open is the kind of subtlety an extraction drops
    // silently. Both get pinned here before the logic moves to a service.

    /**
     * @return array<string, array{string, bool}>
     */
    public static function retentionWindowCases(): array
    {
        return [
            'plain days' => ['30', true],
            'd suffix' => ['30d', true],
            'minimum' => ['1', true],
            'cap boundary' => ['36500', true],
            'one past the cap' => ['36501', false],
            'zero' => ['0', false],
            'negative' => ['-1', false],
            'non-numeric' => ['abc', false],
            'fractional' => ['1.5', false],
            'leading space' => [' 30', false],
            'double suffix' => ['30dd', false],
            'absurd overflow' => ['99999999999999999999', false],
        ];
    }

    #[DataProvider('retentionWindowCases')]
    public function test_prune_window_parser_accepts_only_1_to_36500_days(string $window, bool $accepted): void
    {
        $run = $this->artisan('bridge:prune', ['--older-than' => $window]);
        if (! $accepted) {
            $run->expectsOutputToContain('must be a number of days between 1 and 36500');
        }
        $run->assertExitCode($accepted ? 0 : 1);
    }

    public function test_prune_window_parser_guards_the_null_payloads_leg_too(): void
    {
        // Same guard, second leg: an extraction that validates only --older-than
        // leaves this one able to overflow into a future cutoff and null every row.
        $this->artisan('bridge:prune', ['--null-payloads-older-than' => '36501'])
            ->expectsOutputToContain('must be a number of days between 1 and 36500')
            ->assertExitCode(1);
    }

    public function test_prune_runs_the_first_leg_before_parsing_the_second(): void
    {
        // Pre-existing behavior, pinned because the DL-199 extraction could silently
        // "improve" it: each leg parses and RUNS before the next is parsed, so a valid
        // --older-than followed by an invalid --null-payloads-older-than deletes first,
        // THEN fails. Partial-apply-on-invalid-input is a wart, but changing it is a
        // behavior change that must be its own declared decision — not a refactor's
        // side effect. If this test reds, the extraction changed the ordering.
        $old = $this->event();
        $old->received_at = now()->subDays(40);
        $old->save();

        $this->artisan('bridge:prune', ['--older-than' => '30d', '--null-payloads-older-than' => 'bogus'])
            ->assertExitCode(1);

        $this->assertNull(WebhookEvent::find($old->id));   // leg 1 ALREADY deleted it
    }

    public function test_prune_treats_an_empty_window_as_absent_not_invalid(): void
    {
        // strOption() coerces '' → null, so an empty window falls through to the
        // "specify a window" refusal rather than the parser's range error.
        $this->artisan('bridge:prune', ['--older-than' => ''])
            ->expectsOutputToContain('specify --older-than')
            ->assertExitCode(1);
    }

    public function test_prune_keeps_an_inbox_line_with_no_numeric_ts(): void
    {
        // Fail-open (DL-012): a line that can't be aged is never dropped — only
        // the sibling with a parseable, aged ts goes.
        File::ensureDirectoryExists($this->dir.'/state');
        $oldTs = (float) now()->subDays(40)->format('U.u');
        File::put($this->dir.'/state/inbox.jsonl',
            json_encode(['id' => 'aged:0', 'ts' => $oldTs, 'kind' => 'x', 'summary' => 'aged'])."\n"
            .json_encode(['id' => 'no-ts:0', 'kind' => 'x', 'summary' => 'no ts key'])."\n"
            .json_encode(['id' => 'bad-ts:0', 'ts' => 'not-a-number', 'kind' => 'x', 'summary' => 'unparseable ts'])."\n",
        );

        $this->artisan('bridge:prune', ['--older-than' => '30d'])->assertExitCode(0);

        $this->assertSame(
            ['no-ts:0', 'bad-ts:0'],
            array_column(BridgePaths::readJsonl($this->dir.'/state/inbox.jsonl'), 'id'),
        );
    }

    public function test_prune_dry_run_leaves_the_inbox_and_seen_cursor_untouched(): void
    {
        // test_prune_dry_run_changes_nothing covers the DB leg only; the file leg
        // rewrites state on disk, so it needs its own proof — and it must still
        // REPORT the count it would have removed.
        File::ensureDirectoryExists($this->dir.'/state');
        $oldTs = (float) now()->subDays(40)->format('U.u');
        $inbox = json_encode(['id' => 'old:0', 'ts' => $oldTs, 'kind' => 'x', 'summary' => 'old'])."\n";
        $seen = (string) json_encode(['old:0']);
        File::put($this->dir.'/state/inbox.jsonl', $inbox);
        File::put($this->dir.'/state/inbox-seen.json', $seen);

        $this->artisan('bridge:prune', ['--older-than' => '30d', '--dry-run' => true])
            ->expectsOutputToContain('1 removed')
            ->assertExitCode(0);

        $this->assertSame($inbox, File::get($this->dir.'/state/inbox.jsonl'));
        $this->assertSame($seen, File::get($this->dir.'/state/inbox-seen.json'));
    }

    public function test_check_fails_on_invalid_inbox_layout(): void
    {
        $this->writeAgent();
        config(['bridge.inbox_layout' => 'bogus']);
        $this->artisan('bridge:check')
            ->expectsOutputToContain('BRIDGE_INBOX_LAYOUT')
            ->assertExitCode(1);
    }

    public function test_check_fails_on_cross_user_group_without_per_agent_layout(): void
    {
        $this->writeAgent();
        // group read under shared/both would expose the shared inbox → refused.
        config(['bridge.inbox_group' => 'agent-bridge', 'bridge.inbox_layout' => 'both']);
        $this->artisan('bridge:check')
            ->expectsOutputToContain('per-agent')
            ->assertExitCode(1);
    }

    public function test_check_passes_with_per_agent_layout_and_group(): void
    {
        $this->writeAgent();
        config(['bridge.inbox_group' => 'agent-bridge', 'bridge.inbox_layout' => 'per-agent']);
        $this->artisan('bridge:check')->assertExitCode(0);
    }

    public function test_check_fails_on_unresolvable_classifier_fqcn(): void
    {
        File::put($this->dir.'/prod-agent.yml', "identity:\n  kanban_user_id: 137\n"
            ."subscriptions:\n  - provider: kanban\n    scopes: [5]\n"
            ."classifier:\n  class: 'App\\Bridge\\Classifiers\\NoSuchClassifier'\n");

        // A bad FQCN would be a dispatch error; bridge:check catches it early.
        $this->artisan('bridge:check')->assertExitCode(1);
    }

    public function test_check_fails_on_malformed_receiver_url(): void
    {
        $this->writeAgent();
        config(['bridge.receiver_base_url' => 'not a url']);
        $this->artisan('bridge:check')
            ->expectsOutputToContain('receiver_base_url')
            ->assertExitCode(1);
    }

    public function test_check_fails_on_unknown_treat_as_signal_name(): void
    {
        $this->writeAgent();   // prod-agent
        File::put($this->dir.'/pm.yml', "identity:\n  kanban_user_id: 100\n"
            ."subscriptions:\n  - provider: kanban\n    scopes: [6]\n"
            ."echo_suppression:\n  treat_as_signal: [ghost]\n");

        // 'ghost' has no config → fail-closed (would 5xx at dispatch); caught at preflight.
        $this->artisan('bridge:check')
            ->expectsOutputToContain('treat_as_signal')
            ->assertExitCode(1);
    }

    public function test_check_warns_on_unknown_default_agent(): void
    {
        $this->writeAgent();
        config(['bridge.default_agent' => 'ghost']);
        $this->artisan('bridge:check')
            ->expectsOutputToContain('BRIDGE_DEFAULT_AGENT')
            ->assertExitCode(0);   // warn, not fail
    }

    public function test_stats_reports_counts(): void
    {
        $event = $this->event();
        AgentDispatch::create(['webhook_event_id' => $event->id, 'agent_name' => 'a', 'processed_at' => now()]);
        AgentDispatch::create(['webhook_event_id' => $event->id, 'agent_name' => 'b', 'error_message' => 'boom']);

        $this->artisan('bridge:stats')->assertExitCode(0);
    }

    public function test_inspect_shows_event_or_fails(): void
    {
        $event = $this->event();
        $this->artisan('bridge:inspect', ['id' => $event->id])
            ->expectsOutputToContain('evt-1')
            ->assertExitCode(0);

        $this->artisan('bridge:inspect', ['id' => 99999])->assertExitCode(1);
    }

    public function test_replay_reprocesses_an_errored_dispatch(): void
    {
        $this->writeAgent();
        $event = $this->event();
        AgentDispatch::create([
            'webhook_event_id' => $event->id, 'agent_name' => 'prod-agent', 'error_message' => 'old failure',
        ]);

        $this->artisan('bridge:replay', ['id' => $event->id])->assertExitCode(0);

        $dispatch = AgentDispatch::where('agent_name', 'prod-agent')->firstOrFail();
        $this->assertNotNull($dispatch->processed_at);   // re-ran and succeeded
        $this->assertNull($dispatch->error_message);
    }

    public function test_replay_force_reruns_succeeded_dispatch(): void
    {
        $this->writeAgent();
        $event = $this->event();
        $done = AgentDispatch::create([
            'webhook_event_id' => $event->id, 'agent_name' => 'prod-agent', 'processed_at' => now()->subDay(),
        ]);
        $originalProcessedAt = $done->processed_at;

        $this->artisan('bridge:replay', ['id' => $event->id, '--force' => true])->assertExitCode(0);

        // --force cleared processed_at, so it re-ran and got a fresh timestamp.
        $this->assertTrue($done->fresh()->processed_at->greaterThan($originalProcessedAt));
    }

    public function test_inbox_surfaces_unseen_then_is_silent(): void
    {
        File::ensureDirectoryExists($this->dir.'/state');
        File::put($this->dir.'/state/inbox.jsonl',
            json_encode(['id' => 'evt-1:prod-agent:0', 'ts' => 1.0, 'kind' => 'new_card', 'summary' => 'card 42'])."\n");

        $this->artisan('bridge:inbox', ['--hook-format' => 'plain'])
            ->expectsOutputToContain('new_card')
            ->assertExitCode(0);

        // Seen advanced → second run surfaces nothing (no new output).
        $this->artisan('bridge:inbox', ['--hook-format' => 'plain'])
            ->doesntExpectOutput()
            ->assertExitCode(0);
    }

    public function test_inbox_agent_flag_filters_shared_inbox_and_isolates_cursor(): void
    {
        File::ensureDirectoryExists($this->dir.'/state');
        File::put($this->dir.'/state/inbox.jsonl',
            json_encode(['id' => 'e:pm:0', 'ts' => 1.0, 'agent' => 'pm', 'kind' => 'new_card', 'summary' => 'pm card'])."\n"
            .json_encode(['id' => 'e:backend:0', 'ts' => 1.0, 'agent' => 'backend', 'kind' => 'new_card', 'summary' => 'backend card'])."\n");

        $this->artisan('bridge:inbox', ['--agent' => 'pm', '--hook-format' => 'plain'])
            ->expectsOutputToContain('pm card')
            ->assertExitCode(0);

        // pm saw ONLY its own line (strict filtering proof) and its cursor is separate.
        $this->assertSame(['e:pm:0'], json_decode((string) File::get($this->dir.'/state/inbox-seen-pm.json'), true));

        // backend's cursor is untouched by pm's mark-seen → backend still surfaces its own.
        $this->artisan('bridge:inbox', ['--agent' => 'backend', '--hook-format' => 'plain'])
            ->expectsOutputToContain('backend card')
            ->assertExitCode(0);
    }

    public function test_inbox_reads_per_agent_file_when_present(): void
    {
        File::ensureDirectoryExists($this->dir.'/state');
        File::put($this->dir.'/state/inbox-pm.jsonl',
            json_encode(['id' => 'e:pm:0', 'ts' => 1.0, 'agent' => 'pm', 'kind' => 'new_card', 'summary' => 'from per-agent file'])."\n");

        $this->artisan('bridge:inbox', ['--agent' => 'pm', '--hook-format' => 'plain'])
            ->expectsOutputToContain('from per-agent file')
            ->assertExitCode(0);
    }

    public function test_inbox_honors_default_agent_env(): void
    {
        config(['bridge.default_agent' => 'pm']);
        File::ensureDirectoryExists($this->dir.'/state');
        File::put($this->dir.'/state/inbox.jsonl',
            json_encode(['id' => 'e:pm:0', 'ts' => 1.0, 'agent' => 'pm', 'kind' => 'new_card', 'summary' => 'pm card'])."\n");

        // Bare bridge:inbox (no --agent) surfaces the default agent and uses its cursor.
        $this->artisan('bridge:inbox', ['--hook-format' => 'plain'])
            ->expectsOutputToContain('pm card')
            ->assertExitCode(0);
        $this->assertFileExists($this->dir.'/state/inbox-seen-pm.json');
        $this->assertFileDoesNotExist($this->dir.'/state/inbox-seen.json');
    }

    public function test_inbox_no_cursor_advance_leaves_intents_unseen(): void
    {
        File::ensureDirectoryExists($this->dir.'/state');
        File::put($this->dir.'/state/inbox.jsonl',
            json_encode(['id' => 'e:x:0', 'ts' => 1.0, 'kind' => 'new_card', 'summary' => 'card 42'])."\n");

        // Peek without advancing → the next run still surfaces it.
        $this->artisan('bridge:inbox', ['--hook-format' => 'plain', '--no-cursor-advance' => true])
            ->expectsOutputToContain('card 42')
            ->assertExitCode(0);
        $this->assertFileDoesNotExist($this->dir.'/state/inbox-seen.json');

        $this->artisan('bridge:inbox', ['--hook-format' => 'plain'])
            ->expectsOutputToContain('card 42')
            ->assertExitCode(0);
    }

    public function test_stats_agent_flag_scopes_metrics(): void
    {
        $event = $this->event();
        AgentDispatch::create(['webhook_event_id' => $event->id, 'agent_name' => 'pm', 'processed_at' => now()]);
        AgentDispatch::create(['webhook_event_id' => $event->id, 'agent_name' => 'backend', 'error_message' => 'boom']);

        $this->artisan('bridge:stats', ['--agent' => 'pm'])
            ->expectsOutputToContain('[pm]')
            ->assertExitCode(0);
    }

    public function test_inbox_build_output_envelope_logic(): void
    {
        $cmd = new InboxCommand;
        $lines = [['id' => 'x', 'kind' => 'new_card', 'summary' => 'hi']];

        $this->assertStringNotContainsString('hookSpecificOutput', $cmd->buildOutput($lines, 'plain', 'SessionStart'));

        $wrapped = $cmd->buildOutput($lines, 'claude-code', 'PreToolUse');
        $this->assertStringContainsString('"hookSpecificOutput"', $wrapped);
        $this->assertStringContainsString('"hookEventName":"PreToolUse"', $wrapped);

        // auto: wrap only for additionalContext-supporting events.
        $this->assertStringContainsString('hookSpecificOutput', $cmd->buildOutput($lines, 'auto', 'SessionStart'));
        $this->assertStringNotContainsString('hookSpecificOutput', $cmd->buildOutput($lines, 'auto', 'Stop'));
        $this->assertStringNotContainsString('hookSpecificOutput', $cmd->buildOutput($lines, 'auto', null));
    }

    public function test_replay_command_constructs_without_resolving_dispatch_service(): void
    {
        // #2054: ReplayCommand must NOT constructor-inject DispatchService — its
        // bind reads every agent YAML and console bootstrap instantiates every
        // command, so injecting it would make one malformed YAML crash EVERY
        // artisan command (incl. bridge:check). Constructing the command must
        // not touch the config / resolve DispatchService.
        config(['bridge.config_dir' => '/nonexistent-'.uniqid()]);

        $cmd = new ReplayCommand;

        $this->assertInstanceOf(ReplayCommand::class, $cmd);
    }

    // --- DL-036: outcome surfaced in inspect + replay reports skipped/gate-dropped ---

    public function test_inspect_shows_the_dispatch_outcome_and_reason(): void
    {
        $event = $this->event();
        AgentDispatch::create([
            'webhook_event_id' => $event->id, 'agent_name' => 'prod-agent',
            'processed_at' => now(), 'outcome' => AgentDispatch::OUTCOME_DROPPED, 'reason' => 'echo: own write',
        ]);

        // Capture via Artisan::output() — robust for table/warn output that
        // expectsOutputToContain doesn't reliably match.
        $code = Artisan::call('bridge:inspect', ['id' => $event->id]);
        $out = Artisan::output();
        $this->assertSame(0, $code);
        $this->assertStringContainsString('dropped', $out);            // the outcome column
        $this->assertStringContainsString('echo: own write', $out);    // the reason column
    }

    public function test_replay_reports_skipped_processed_rows_and_counts_gate_drops(): void
    {
        $this->writeAgent();
        $event = $this->event();
        // A gate-dropped row (processed + dropped) — exactly what a replay-after-a-
        // gate-fix wants to re-run, but plain replay skips it (it's marked processed).
        AgentDispatch::create([
            'webhook_event_id' => $event->id, 'agent_name' => 'prod-agent',
            'processed_at' => now(), 'outcome' => AgentDispatch::OUTCOME_DROPPED, 'reason' => 'echo',
        ]);

        $code = Artisan::call('bridge:replay', ['id' => $event->id]);
        $out = Artisan::output();
        $this->assertSame(0, $code);
        $this->assertStringContainsString('skipping 1 already-processed', $out);
        $this->assertStringContainsString('gate-DROPPED', $out);   // names the recoverable class
        $this->assertStringContainsString('--force', $out);
    }

    public function test_replay_force_resets_the_full_terminal_tuple_not_just_processed_at(): void
    {
        // --force must clear outcome/reason/error_message too: a re-run can exit via
        // a non-terminal path (durable-handler throw / config throw → 5xx) that
        // reaches no mark*() stamper, which would otherwise leave the prior pass's
        // outcome next to a now-null processed_at — the DL-036 inconsistency.
        // Asserted on an orphan row (an agent no longer in config) so --force resets
        // it but dispatch() never re-stamps it, exposing the raw reset.
        $this->writeAgent();
        $event = $this->event();
        AgentDispatch::create([
            'webhook_event_id' => $event->id, 'agent_name' => 'gone-agent',
            'processed_at' => now(), 'outcome' => AgentDispatch::OUTCOME_DELIVERED,
            'error_message' => 'stale handler note',
        ]);

        $this->artisan('bridge:replay', ['id' => $event->id, '--force' => true])->assertExitCode(0);

        $d = AgentDispatch::where('agent_name', 'gone-agent')->firstOrFail();
        $this->assertNull($d->processed_at);
        $this->assertNull($d->outcome);
        $this->assertNull($d->reason);
        $this->assertNull($d->error_message);
    }

    public function test_check_surfaces_an_id_collision_on_the_console(): void
    {
        // Two agents sharing a kanban_user_id silently bypasses attribution (DL-007
        // warn). bridge:check must surface it to the operator console, not only to
        // the log where it goes unnoticed. Warn-level (exit 0), not a failure.
        $yaml = "identity:\n  kanban_user_id: 500\nsubscriptions:\n  - provider: kanban\n    scopes: [5]\n";
        File::put($this->dir.'/agent-a.yml', $yaml);
        File::put($this->dir.'/agent-b.yml', $yaml);

        $code = Artisan::call('bridge:check');
        $out = Artisan::output();
        $this->assertSame(0, $code);
        $this->assertStringContainsString('shared by multiple agents', $out);
    }

    public function test_check_warns_when_channel_socket_parent_dir_is_missing(): void
    {
        // DL-039: a channel.socket whose parent dir doesn't exist makes live-wake
        // silently no-op — classically a uid mismatch after a host restore. bridge:
        // check must surface it at preflight (warn, not fail — the socket itself is
        // the channel server's to create).
        File::put($this->dir.'/prod-agent.yml',
            "identity:\n  kanban_user_id: 137\n"
            ."subscriptions:\n  - provider: kanban\n    scopes: [5]\n"
            ."channel:\n  socket: /run/user/999999/nonexistent-dir/x.sock\n");

        $code = Artisan::call('bridge:check');
        $out = Artisan::output();
        $this->assertSame(0, $code);
        $this->assertStringContainsString('channel.socket parent dir', $out);
        $this->assertStringContainsString('does not exist', $out);
    }

    private function writeAgentWithChannelSocket(string $socket): void
    {
        File::put($this->dir.'/prod-agent.yml',
            "identity:\n  kanban_user_id: 137\n"
            ."subscriptions:\n  - provider: kanban\n    scopes: [5]\n"
            ."channel:\n  socket: {$socket}\n");
    }

    public function test_check_surfaces_channel_bind_failure_marker(): void
    {
        // FR #2444: a session whose connector lost the bind race leaves a visible
        // .FAILED marker (the swallowed stderr never showed it). bridge:check
        // surfaces it loudly (warn, not fail).
        $sock = $this->dir.'/x.sock';
        File::put($sock.'.FAILED', "2026-06-12T00:00:00Z pid=1 prod-agent: EADDRINUSE binding unix:{$sock} — another session holds the channel\n");
        $this->writeAgentWithChannelSocket($sock);

        $code = Artisan::call('bridge:check');
        $out = Artisan::output();
        $this->assertSame(0, $code);
        $this->assertStringContainsString('bind-FAILURE marker', $out);
    }

    public function test_check_reports_channel_socket_live_when_a_session_listens(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl required for the UDS liveness listener');
        }
        $sock = $this->dir.'/live.sock';
        $pid = pcntl_fork();
        if ($pid === 0) {
            $server = @stream_socket_server('unix://'.$sock, $errno, $errstr);
            if ($server !== false) {
                @stream_socket_accept($server, 3); // accept the liveness probe
            }
            // Hard-exit: a graceful exit runs PHP's shutdown, which closes the DB
            // connection inherited over fork by sending COM_QUIT on the shared
            // socket — the PARENT then errors "MySQL server has gone away" under a
            // real MySQL/MariaDB driver (CI), though it's invisible under the local
            // SQLite-in-memory driver.
            posix_kill(posix_getpid(), SIGKILL);
        }
        $deadline = microtime(true) + 3.0;
        while (! file_exists($sock) && microtime(true) < $deadline) {
            usleep(20_000);
        }
        $this->writeAgentWithChannelSocket($sock);

        $code = Artisan::call('bridge:check');
        $out = Artisan::output();
        pcntl_waitpid($pid, $status);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('channel socket live', $out);
    }

    private function writeAgentWithChannelUrl(string $url): void
    {
        File::put($this->dir.'/prod-agent.yml',
            "identity:\n  kanban_user_id: 137\n"
            ."subscriptions:\n  - provider: kanban\n    scopes: [5]\n"
            ."channel:\n  url: {$url}\n");
    }

    public function test_check_reports_channel_http_endpoint_live_when_listener_present(): void
    {
        // FR-2: on an HTTP-transport agent (channel.url, no socket) the liveness
        // signal is a TCP connect to the loopback/tunnel port — not a UDS probe.
        // A bound, listening TCP socket completes the connect handshake even
        // without accept(), so no fork is needed.
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($server, 'could not bind a TCP listener');
        $port = (int) explode(':', (string) stream_socket_get_name($server, false))[1];
        $this->writeAgentWithChannelUrl("http://127.0.0.1:{$port}/");

        $code = Artisan::call('bridge:check');
        $out = Artisan::output();
        fclose($server);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('channel HTTP endpoint live', $out);
    }

    public function test_check_warns_when_channel_http_endpoint_not_answering(): void
    {
        // Bind then close to get a port that is free at probe time.
        $tmp = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($tmp);
        $port = (int) explode(':', (string) stream_socket_get_name($tmp, false))[1];
        fclose($tmp);
        $this->writeAgentWithChannelUrl("http://127.0.0.1:{$port}/");

        $code = Artisan::call('bridge:check');
        $out = Artisan::output();
        $this->assertSame(0, $code);
        $this->assertStringContainsString('not answering', $out);
    }

    public function test_check_surfaces_http_channel_bind_failure_marker(): void
    {
        // The HTTP marker the server writes is $XDG_RUNTIME_DIR (or os.tmpdir())
        // /agent-webhook-bridge-channel-<name>.http-<port>.FAILED. bridge:check
        // surfaces it best-effort when run on the agent host. Pin XDG to the temp
        // dir so the test controls the base, and restore it after.
        $prevXdg = getenv('XDG_RUNTIME_DIR');
        putenv('XDG_RUNTIME_DIR='.$this->dir);
        try {
            $port = 8790;
            File::put($this->dir."/agent-webhook-bridge-channel-prod-agent.http-{$port}.FAILED",
                "2026-06-13T00:00:00Z pid=1 prod-agent: EADDRINUSE binding http://127.0.0.1:{$port} — another process holds the port\n");
            $this->writeAgentWithChannelUrl("http://127.0.0.1:{$port}/");

            $code = Artisan::call('bridge:check');
            $out = Artisan::output();
            $this->assertSame(0, $code);
            $this->assertStringContainsString('bind-FAILURE marker', $out);
        } finally {
            $prevXdg === false ? putenv('XDG_RUNTIME_DIR') : putenv('XDG_RUNTIME_DIR='.$prevXdg);
        }
    }

    public function test_check_warns_when_channel_socket_is_stale_with_no_listener(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl required for the UDS stale-socket listener');
        }
        $sock = $this->dir.'/stale.sock';
        $pid = pcntl_fork();
        if ($pid === 0) {
            $server = @stream_socket_server('unix://'.$sock, $errno, $errstr);
            if ($server !== false) {
                sleep(30); // hold the bind; the parent SIGKILLs us mid-sleep
            }
            // Hard-exit (see the liveness test) so the failure path can't COM_QUIT
            // the fork-inherited DB connection either.
            posix_kill(posix_getpid(), SIGKILL);
        }
        $deadline = microtime(true) + 3.0;
        while (! file_exists($sock) && microtime(true) < $deadline) {
            usleep(20_000);
        }
        // Kill the listener: the UDS inode persists on disk but nothing listens now.
        posix_kill($pid, SIGKILL);
        pcntl_waitpid($pid, $status);
        clearstatcache();
        $this->assertFileExists($sock);
        $this->writeAgentWithChannelSocket($sock);

        $code = Artisan::call('bridge:check');
        $out = Artisan::output();
        $this->assertSame(0, $code);
        $this->assertStringContainsString('nothing is listening', $out);
    }

    // ─── DL-217 board_tools probes ───────────────────────────────────────────

    private function writeSecret(string $path, string $value): void
    {
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $value);
        chmod($path, 0o600);
    }

    private function writeBoardToolsAgent(string $name, string $tokenValue, int $swimlaneId = 4, int $createStageId = 55): void
    {
        $tokenFile = $this->dir."/{$name}-tools-token";
        $this->writeSecret($tokenFile, $tokenValue);
        File::put($this->dir."/{$name}.yml", "identity:\n  kanban_user_id: ".crc32($name)."\nsubscriptions: []\n"
            ."board_tools:\n  enabled: true\n  transport: http\n  auth:\n    token_path: {$tokenFile}\n  board_id: 10\n  swimlane_id: {$swimlaneId}\n  create_stage_id: {$createStageId}\n");
    }

    private function fakeBoardOk(): void
    {
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 1]], 'meta' => ['total' => 1]]),
            '*/boards/10/preload.json' => Http::response(['data' => [
                'swimlanes' => [['id' => 4], ['id' => 9]],
                'workflows' => [['stages' => [['id' => 50, 'name' => 'Backlog', 'position' => 1], ['id' => 55, 'name' => 'Doing', 'position' => 2]]]],
            ]]),
            '*/tasks/by-ref.json*' => Http::response(['data' => []]),
        ]);
    }

    public function test_check_warns_when_board_tools_swimlane_not_on_board(): void
    {
        config(['bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3']);
        $this->writeSecret($this->dir.'/kanban/writeback-token', 'wb-token');   // gitleaks:allow — test fixture
        $this->writeBoardToolsAgent('impl', 'tok-impl-1', swimlaneId: 999);      // 999 not on the board
        $this->fakeBoardOk();

        $this->artisan('bridge:check')
            ->expectsOutputToContain('swimlane_id 999 is not on board 10')
            ->assertExitCode(0);   // warn, never fail
    }

    public function test_check_fails_when_board_tools_token_collides(): void
    {
        // Under default-ON a token collision is a broken enablement (tools DEAD for
        // both agents), so bridge:check FAILs — the opt-in-era WARN (exit 0) is
        // superseded (DL-217 v5/v7). Reverting the resolver's typed problem to a warn
        // reds this on the exit code.
        config(['bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3']);
        $this->writeSecret($this->dir.'/kanban/writeback-token', 'wb-token');   // gitleaks:allow — test fixture
        $this->writeBoardToolsAgent('impl-a', 'same-token');
        $this->writeBoardToolsAgent('impl-b', 'same-token');
        $this->fakeBoardOk();

        $this->artisan('bridge:check')
            ->expectsOutputToContain('the same auth token is shared by multiple agents')
            ->assertExitCode(1);
    }

    public function test_check_healthy_board_tools_reports_ok(): void
    {
        config(['bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3']);
        $this->writeSecret($this->dir.'/kanban/writeback-token', 'wb-token');   // gitleaks:allow — test fixture
        $this->writeBoardToolsAgent('impl', 'tok-impl-1');
        $this->fakeBoardOk();

        $this->artisan('bridge:check')
            ->expectsOutputToContain('board_tools: agent impl: writeback token can see board 10')
            ->assertExitCode(0);
    }

    public function test_check_fails_on_a_suppressed_default_board_tools_block(): void
    {
        // A DEFAULT-class block (no enabled key) that cannot be satisfied is INERT
        // (enabled=false) — the only place its failure surfaces is the all-configs
        // suppressedReason scan. See it fail once (canon #9): reverting the scan reds
        // this on the exit code. Missing swimlane_id on an HTTP channel → suppressed.
        config(['bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3']);
        $this->writeSecret($this->dir.'/kanban/writeback-token', 'wb-token');   // gitleaks:allow — test fixture
        File::put($this->dir.'/impl.yml', "identity:\n  kanban_user_id: ".crc32('impl')."\nsubscriptions: []\n"
            ."channel:\n  url: http://127.0.0.1:8788\n  auth:\n    token_path: /secrets/channel-token\n"
            ."board_tools:\n  board_id: 10\n  create_stage_id: 55\n");   // no swimlane_id
        $this->fakeBoardOk();

        $this->artisan('bridge:check')
            ->expectsOutputToContain('a default-on block could not be satisfied')
            ->assertExitCode(1);
    }

    public function test_check_fails_on_an_unreadable_board_tools_bearer(): void
    {
        // Under default-ON a dead bearer is a broken enablement → FAIL (was WARN/exit
        // 0). The enabled agent's token file is removed, so the resolver accumulates a
        // typed bearer_unreadable problem the check renders as an error.
        config(['bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3']);
        $this->writeSecret($this->dir.'/kanban/writeback-token', 'wb-token');   // gitleaks:allow — test fixture
        $this->writeBoardToolsAgent('impl', 'tok-impl-1');
        File::delete($this->dir.'/impl-tools-token');   // bearer now unreadable
        $this->fakeBoardOk();

        $this->artisan('bridge:check')
            ->expectsOutputToContain('board tools disabled for this agent')
            ->assertExitCode(1);
    }

    // ─── DL-217 bridge:check --probe-tools live probe ────────────────────────

    /**
     * Board-config fakes (writeback token board reads) PLUS the live tool-call
     * endpoint fake, so bridge:check --probe-tools is otherwise clean and the exit
     * code is driven by the probe alone.
     *
     * @param  array<string, mixed>  $endpointResponse
     */
    private function fakeProbe(string $endpoint, array $endpointResponse, int $status = 200): void
    {
        Http::fake([
            '*/boards/10/preload.json' => Http::response(['data' => [
                'swimlanes' => [['id' => 4], ['id' => 9]],
                'workflows' => [['stages' => [['id' => 50, 'name' => 'Backlog', 'position' => 1], ['id' => 55, 'name' => 'Doing', 'position' => 2]]]],
            ]]),
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 1]], 'meta' => ['total' => 1]]),
            '*/tasks/by-ref.json*' => Http::response(['data' => []]),
            $endpoint => Http::response($endpointResponse, $status),
        ]);
    }

    public function test_check_probe_tools_reports_ok_on_scoped_200(): void
    {
        $endpoint = 'http://127.0.0.1/agent-tools/call';
        config(['bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3']);
        $this->writeSecret($this->dir.'/kanban/writeback-token', 'wb-token');   // gitleaks:allow — test fixture
        $this->writeBoardToolsAgent('impl', 'tok-impl-1');   // board 10, swimlane 4
        $this->fakeProbe($endpoint, ['ok' => true, 'tool' => 'board_my_cards', 'result' => [
            'board_id' => 10, 'swimlane_id' => 4, 'cards_by_stage' => ['Backlog' => []],
        ]]);

        $this->artisan('bridge:check', ['--probe-tools' => $endpoint])
            ->expectsOutputToContain('window scoped to board 10 / swimlane 4')
            ->assertExitCode(0);
    }

    public function test_check_probe_tools_403_names_the_loopback_cause_and_fails(): void
    {
        $endpoint = 'http://bad-host/agent-tools/call';
        config(['bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3']);
        $this->writeSecret($this->dir.'/kanban/writeback-token', 'wb-token');   // gitleaks:allow — test fixture
        $this->writeBoardToolsAgent('impl', 'tok-impl-1');
        $this->fakeProbe($endpoint, ['ok' => false, 'error' => 'this endpoint is reachable from loopback only'], 403);

        $this->artisan('bridge:check', ['--probe-tools' => $endpoint])
            ->expectsOutputToContain('loopback gate refused')
            ->assertExitCode(1);
    }

    public function test_check_probe_tools_401_names_the_token_path_and_fails(): void
    {
        $endpoint = 'http://127.0.0.1/agent-tools/call';
        config(['bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3']);
        $this->writeSecret($this->dir.'/kanban/writeback-token', 'wb-token');   // gitleaks:allow — test fixture
        $this->writeBoardToolsAgent('impl', 'tok-impl-1');
        $this->fakeProbe($endpoint, ['ok' => false, 'error' => 'unrecognized bearer token'], 401);

        $this->artisan('bridge:check', ['--probe-tools' => $endpoint])
            ->expectsOutputToContain('bearer rejected')
            ->assertExitCode(1);
    }

    public function test_check_probe_tools_isolation_mismatch_fails(): void
    {
        $endpoint = 'http://127.0.0.1/agent-tools/call';
        config(['bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3']);
        $this->writeSecret($this->dir.'/kanban/writeback-token', 'wb-token');   // gitleaks:allow — test fixture
        $this->writeBoardToolsAgent('impl', 'tok-impl-1');   // configured swimlane 4
        // The endpoint returns a window scoped to a DIFFERENT swimlane (99) than config.
        $this->fakeProbe($endpoint, ['ok' => true, 'tool' => 'board_my_cards', 'result' => [
            'board_id' => 10, 'swimlane_id' => 99, 'cards_by_stage' => [],
        ]]);

        $this->artisan('bridge:check', ['--probe-tools' => $endpoint])
            ->expectsOutputToContain('ISOLATION MISMATCH')
            ->assertExitCode(1);
    }

    public function test_check_probe_tools_missing_bearer_fails(): void
    {
        // An enabled agent with no minted bearer is a BROKEN enablement — the probe
        // certifies before the operator flips traffic on, so it must fail, not warn.
        $endpoint = 'http://127.0.0.1/agent-tools/call';
        config(['bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3']);
        $this->writeSecret($this->dir.'/kanban/writeback-token', 'wb-token');   // gitleaks:allow — test fixture
        $this->writeBoardToolsAgent('impl', 'tok-impl-1');
        File::delete($this->dir.'/impl-tools-token');
        $this->fakeProbe($endpoint, ['ok' => true]);

        $this->artisan('bridge:check', ['--probe-tools' => $endpoint])
            ->expectsOutputToContain('cannot certify this agent')
            ->assertExitCode(1);
    }

    // ─── DL-217 bridge:provision-tools ───────────────────────────────────────

    private function writeToolsAgentYaml(string $name, string $tokenPath, bool $enabled = true): void
    {
        $block = $enabled
            ? "board_tools:\n  enabled: true\n  transport: http\n  auth:\n    token_path: {$tokenPath}\n  board_id: 10\n  swimlane_id: 4\n  create_stage_id: 55\n"
            : '';
        File::put($this->dir."/{$name}.yml", "identity:\n  kanban_user_id: ".crc32($name)."\nsubscriptions: []\n".$block);
    }

    public function test_provision_tools_mints_an_absent_bearer_0600(): void
    {
        $tokenPath = $this->dir.'/impl-board-tools-token';
        $this->writeToolsAgentYaml('impl', $tokenPath);
        $this->assertFileDoesNotExist($tokenPath);

        $this->artisan('bridge:provision-tools')
            ->expectsOutputToContain('MINTED')
            ->assertExitCode(0);

        $this->assertFileExists($tokenPath);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($tokenPath)), -4));
        $this->assertSame(64, strlen(trim((string) file_get_contents($tokenPath))));   // bin2hex(32) = 64 hex chars
    }

    public function test_provision_tools_prints_the_ssh_role_invocations(): void
    {
        // FR #5010 §2: an ssh-transport agent mints NO bridge-side secret. provision-tools
        // now PRINTS the ready-to-run `provision-board-tools.py --role a|b` invocations
        // with this agent's params filled in (--agent from config, --artisan from
        // base_path, --ssh-account from board_tools.ssh_account) — replacing the old
        // generated root-run bash script. The static python program owns both legs; its
        // full-line pubkey validator supersedes the prefix-only generated-bash guard
        // (#5033), so no `HOST_B_PUBKEY`/`case … ecdsa-*` scaffold appears at all.
        File::put($this->dir.'/impl.yml', "identity:\n  kanban_user_id: 1\nsubscriptions: []\n"
            ."board_tools:\n  transport: ssh\n  ssh_account: bridge-user\n  board_id: 10\n  swimlane_id: 4\n  create_stage_id: 55\n");

        $artisan = base_path('artisan');
        $script = base_path('bin/provision-board-tools.py');

        $this->artisan('bridge:provision-tools')
            // host-A leg: run as root, --role a with agent filled in + the python path
            ->expectsOutputToContain("sudo python3 {$script} --role a --agent impl")
            // --artisan + --ssh-account resolved from base_path + the configured account
            ->expectsOutputToContain("--artisan {$artisan} --ssh-account bridge-user --pubkey-stdin")
            // same-box hint (§6): hand the .pub path to --role a --pubkey-from
            ->expectsOutputToContain('--pubkey-from')
            // host-B leg: --role b on the calling seat, ssh-target user from ssh_account
            ->expectsOutputToContain('python3 provision-board-tools.py --role b --agent impl')
            ->expectsOutputToContain('--ssh-target bridge-user@<host-A>')
            // cert hint retained
            ->expectsOutputToContain('bridge:check --probe-tools-ssh=bridge-user@<host-A>')
            // the old generated-bash scaffold (+ its prefix-only pubkey guard, #5033) is gone
            ->doesntExpectOutputToContain('HOST_B_PUBKEY')
            ->doesntExpectOutputToContain('ecdsa-*|ssh-*|sk-*')
            ->assertExitCode(0);

        // No bridge-side token file was created for the ssh agent.
        $this->assertFileDoesNotExist($this->dir.'/impl-board-tools-token');
    }

    public function test_provision_tools_never_prints_the_token_value(): void
    {
        $tokenPath = $this->dir.'/impl-board-tools-token';
        $this->writeToolsAgentYaml('impl', $tokenPath);

        $this->artisan('bridge:provision-tools')->assertExitCode(0);

        $value = trim((string) file_get_contents($tokenPath));
        // The minted value must never appear in the artisan buffer.
        $this->artisan('bridge:provision-tools')
            ->doesntExpectOutputToContain($value)
            ->assertExitCode(0);
    }

    public function test_provision_tools_leaves_an_existing_secure_bearer_alone(): void
    {
        $tokenPath = $this->dir.'/impl-board-tools-token';
        $this->writeToolsAgentYaml('impl', $tokenPath);
        $this->writeSecret($tokenPath, 'already-here');   // gitleaks:allow — test fixture

        $this->artisan('bridge:provision-tools')
            ->expectsOutputToContain('already minted')
            ->assertExitCode(0);

        $this->assertSame('already-here', trim((string) file_get_contents($tokenPath)));
    }

    public function test_provision_tools_fails_on_insecure_bearer_perms(): void
    {
        $tokenPath = $this->dir.'/impl-board-tools-token';
        $this->writeToolsAgentYaml('impl', $tokenPath);
        File::put($tokenPath, 'exposed');   // gitleaks:allow — test fixture
        chmod($tokenPath, 0o644);           // group/world-readable

        $this->artisan('bridge:provision-tools')
            ->expectsOutputToContain('FAIL')
            ->assertExitCode(1);
    }

    public function test_provision_tools_dry_run_mints_nothing(): void
    {
        $tokenPath = $this->dir.'/impl-board-tools-token';
        $this->writeToolsAgentYaml('impl', $tokenPath);

        $this->artisan('bridge:provision-tools', ['--dry-run' => true])
            ->expectsOutputToContain('DRY-RUN')
            ->assertExitCode(0);

        $this->assertFileDoesNotExist($tokenPath);
    }

    public function test_provision_tools_fails_both_agents_on_token_collision(): void
    {
        $shared = $this->dir.'/shared-board-tools-token';
        $this->writeToolsAgentYaml('impl-a', $shared);
        $this->writeToolsAgentYaml('impl-b', $shared);
        $this->writeSecret($shared, 'same-token');   // gitleaks:allow — test fixture

        $this->artisan('bridge:provision-tools')
            ->expectsOutputToContain('impl-a, impl-b')
            ->assertExitCode(1);
    }

    public function test_provision_tools_skeleton_for_named_agent_without_block(): void
    {
        File::put($this->dir.'/impl.yml', "identity:\n  kanban_user_id: 1\nsubscriptions: []\n");

        $this->artisan('bridge:provision-tools', ['--agent' => 'impl'])
            ->expectsOutputToContain('board_tools:')
            ->assertExitCode(1);
    }

    public function test_provision_tools_skips_agent_without_block_when_unfiltered(): void
    {
        File::put($this->dir.'/impl.yml', "identity:\n  kanban_user_id: 1\nsubscriptions: []\n");

        $this->artisan('bridge:provision-tools')
            ->expectsOutputToContain('no board_tools block')
            ->assertExitCode(0);
    }

    public function test_provision_tools_skips_a_channel_token_reuse_agent(): void
    {
        // Default-ON reuse: a DEFAULT board_tools block (no explicit auth.token_path)
        // on an HTTP channel reuses the channel token as the bearer — there is nothing
        // to mint (the command is the explicit-override path). Minting into the channel
        // token file would clobber channel auth, so it must SKIP.
        $channelTokenFile = $this->dir.'/impl-channel-token';
        $this->writeSecret($channelTokenFile, 'chan-token');   // gitleaks:allow — test fixture
        File::put($this->dir.'/impl.yml', "identity:\n  kanban_user_id: ".crc32('impl')."\nsubscriptions: []\n"
            ."channel:\n  url: http://127.0.0.1:8788\n  auth:\n    token_path: {$channelTokenFile}\n"
            ."board_tools:\n  transport: http\n  board_id: 10\n  swimlane_id: 4\n  create_stage_id: 55\n");

        $this->artisan('bridge:provision-tools')
            ->expectsOutputToContain('reuses the channel token')
            ->assertExitCode(0);

        // The channel token file is untouched (never minted over).
        $this->assertSame('chan-token', trim((string) file_get_contents($channelTokenFile)));
    }
}
