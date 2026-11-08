<?php

namespace Tests\Unit\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Checks\GitHubRepoAccessCheck;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use App\Bridge\Writeback\WritebackConfig;
use App\Bridge\Writeback\WritebackMapping;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CoordCredentialStoreFixture;
use Tests\Support\MaterializesChecks;
use Tests\TestCase;

/**
 * `github.repo_access` (card#12968): the token each repo resolves, asked for the repo, the reads its
 * legs declare and the writes they declare, each on the thread kind it lands on — a comment by a POST
 * of `{}` to an existing unlocked thread (422 = can write, a permission-refusal 403 = cannot, as
 * measured on GitHub; see DL-500), a label from the answers on both kinds plus the account's role. WARN on a refusal naming the source and only the legs that depend on it, OK when
 * every leg answered, UNVALIDATED when a leg was not measured.
 *
 * The repo is mapped in a synthetic store to {@see self::KEY}, so the source every finding must name
 * is the store key — the incident shape: a repo mapped to a read-only credential-store key.
 */
class GitHubRepoAccessCheckTest extends TestCase
{
    use MaterializesChecks;

    private const REPO = 'acme/widget';

    private const KEY = 'readonly_key';

    private const TOKEN = 'github_pat_synthetic_store_token_value';

    private const BASE = 'https://api.github.com/repos/acme/widget';

    /** The newest unlocked pull request the default stubs list. */
    private const PROBE_URL = self::BASE.'/issues/1031/comments';

    /** The newest unlocked issue (not a pull request) the default stubs list. */
    private const ISSUE_PROBE_URL = self::BASE.'/issues/900/comments';

    private const PERMISSION_REFUSAL = ['message' => 'Resource not accessible by personal access token'];

    private string $dir;

    private string $tokenPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/repo-access-check-'.uniqid();
        File::ensureDirectoryExists($this->dir);
        config(['bridge.config_dir' => $this->dir, 'bridge.secret_dir' => $this->dir, 'bridge.providers.github.token_path' => null, 'bridge.protocol_invalid_label.repos' => []]);
        $store = (new CoordCredentialStoreFixture($this->dir.'/coord'))->use();
        $this->tokenPath = $store->tokenFile('readonly-token', self::TOKEN);
        $store->write(['github.com/acme' => self::KEY], [self::KEY.'_file' => $this->tokenPath]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /** The card's done-when: a read-only store key mapped to a repo makes the check WARN naming the key. */
    public function test_a_read_only_store_key_warns_naming_the_key(): void
    {
        $this->fakeGitHub(write: Http::response(self::PERMISSION_REFUSAL, 403));

        $finding = $this->onlyFinding();

        $this->assertSame(Severity::Warn, $finding->severity);
        $this->assertStringContainsString('acme/widget resolves its GitHub token from store key readonly_key', $finding->message);
        $this->assertStringContainsString("create pull-request comments (HTTP 403, GitHub's permission refusal, to a comment on #1031, a pull request)", $finding->message);
        $this->assertStringContainsString('Pull requests WRITE', $finding->message);
        $this->assertNoSecret($finding);
    }

    /** The probe posts a body GitHub can never accept, to a thread of the kind the leg writes to. */
    public function test_the_comment_probe_posts_an_empty_object_to_the_newest_unlocked_pull_request(): void
    {
        $this->fakeGitHub(write: Http::response(['message' => 'Validation Failed'], 422));

        $this->onlyFinding();

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === self::PROBE_URL && $r->body() === '{}');
        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && str_starts_with($r->url(), self::BASE.'/pulls?') && str_contains($r->url(), 'state=all'));
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST' && $r->url() !== self::PROBE_URL);
    }

    public function test_a_422_to_the_empty_comment_is_ok(): void
    {
        $this->fakeGitHub(write: Http::response(['message' => 'Validation Failed'], 422));

        $finding = $this->onlyFinding();

        $this->assertSame(Severity::Ok, $finding->severity);
        $this->assertStringContainsString('the token acme/widget resolves from store key readonly_key can read the repo (HTTP 200); read issue and pull-request comments (HTTP 200); create pull-request comments (HTTP 422', $finding->message);
        $this->assertNoSecret($finding);
    }

    /**
     * Measured on GitHub: to an issue number that does not exist, a write token AND a read-only
     * fine-grained token both get 404. So a 404 from a fine-grained token measures nothing.
     */
    public function test_a_404_from_a_fine_grained_token_is_unvalidated_never_ok(): void
    {
        $this->fakeGitHub(write: Http::response(['message' => 'Not Found'], 404));

        $finding = $this->onlyFinding();

        $this->assertSame(Severity::Unvalidated, $finding->severity);
        $this->assertStringContainsString('NOT measured: create pull-request comments (HTTP 404 from #1031, a pull request)', $finding->message);
    }

    /** @return array<string, array{0: int, 1: array<string, mixed>, 2: array<string, string>}> */
    public static function unmeasuredWriteAnswers(): array
    {
        return [
            '5xx' => [502, ['message' => 'boom'], []],
            '401' => [401, ['message' => 'Bad credentials'], []],
            'rate-limited 403' => [403, ['message' => 'API rate limit exceeded'], ['X-RateLimit-Remaining' => '0']],
            'secondary limit named only in the body' => [403, ['message' => 'You have exceeded a secondary rate limit'], []],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    #[DataProvider('unmeasuredWriteAnswers')]
    public function test_a_write_probe_answer_that_measures_nothing_is_unvalidated_never_a_verdict(int $status, array $body, array $headers): void
    {
        $this->fakeGitHub(write: Http::response($body, $status, $headers));

        $finding = $this->onlyFinding();

        $this->assertSame(Severity::Unvalidated, $finding->severity);
        $this->assertStringContainsString('NOT measured: create pull-request comments', $finding->message);
        $this->assertStringContainsString('Measured: it can read the repo (HTTP 200)', $finding->message);
    }

    /**
     * The probe POST never follows a redirect — Guzzle would replay it as a GET, whose 2xx would read
     * as GitHub accepting the comment — so a 3xx is returned as itself and measures nothing.
     */
    public function test_the_write_probe_does_not_follow_redirects_and_a_3xx_is_unvalidated(): void
    {
        $redirects = null;
        $this->fakeGitHub(write: function (Request $request, array $options) use (&$redirects) {
            $redirects = $options['allow_redirects'] ?? 'unset';

            return Http::response('', 301, ['Location' => 'https://api.github.com/repositories/1/issues/1031/comments']);
        });

        $finding = $this->onlyFinding();

        $this->assertFalse($redirects, 'the probe POST must be sent with allow_redirects=false');
        $this->assertSame(Severity::Unvalidated, $finding->severity);
        $this->assertStringContainsString('create pull-request comments (HTTP 301 from #1031, a pull request)', $finding->message);
        $this->assertStringNotContainsString('ACCEPTED', $finding->message);
    }

    /** A read 403 that is not GitHub's permission refusal is a refusal no permission fixes — no permission remedy. */
    public function test_a_read_403_that_is_not_the_permission_refusal_is_refused_without_a_permission_remedy(): void
    {
        $this->fakeGitHub(write: Http::response([], 422), extra: [
            self::BASE.'/issues/comments*' => Http::response(['message' => 'Resource protected by organization SAML enforcement.'], 403),
        ]);

        $finding = $this->onlyFinding();

        $this->assertSame(Severity::Warn, $finding->severity);
        $this->assertStringContainsString('read issue and pull-request comments (HTTP 403, refused for a cause that is not a missing permission', $finding->message);
        $this->assertStringContainsString('granting one will not fix it', $finding->message);
        $this->assertStringNotContainsString('Issues or Pull requests READ', $finding->message);
    }

    /** Issues disabled (410 to the issue list) is structural: named, and no re-run advice for it. */
    public function test_disabled_issues_are_named_as_structural_without_re_run_advice(): void
    {
        config(['bridge.protocol_invalid_label.repos' => [self::REPO]]);
        $this->fakeGitHub(repo: $this->repoWithRoles(['triage' => true]), write: Http::response([], 422), extra: [
            self::BASE.'/issues?*' => Http::response(['message' => 'Issues are disabled for this repo'], 410),
        ]);

        $finding = $this->onlyFinding();

        $this->assertSame(Severity::Unvalidated, $finding->severity);
        $this->assertStringContainsString("NOT measurable on this repo: add labels to issues and pull requests (not asked: GitHub answered HTTP 410 to the repo's issues list — issues are disabled on this repo)", $finding->message);
        $this->assertStringNotContainsString('re-run bridge:check', $finding->message);
    }

    /** A 2xx means GitHub accepted what it documents it never accepts: a defect to report, not a re-run. */
    public function test_a_2xx_to_the_write_probe_warns_that_it_was_accepted(): void
    {
        $this->fakeGitHub(write: Http::response(['body' => ''], 201));

        $finding = $this->onlyFinding();

        $this->assertSame(Severity::Warn, $finding->severity);
        $this->assertStringContainsString('was ACCEPTED', $finding->message);
        $this->assertStringContainsString('acme/widget#1031', $finding->message);
        $this->assertStringContainsString('report this as a bridge defect', $finding->message);
        $this->assertStringNotContainsString('re-run bridge:check', $finding->message);
    }

    public function test_a_repo_with_no_pull_request_to_post_against_is_unvalidated_and_posts_nothing(): void
    {
        $this->fakeGitHub(write: Http::response([], 422), pulls: Http::response([]));

        $finding = $this->onlyFinding();

        $this->assertSame(Severity::Unvalidated, $finding->severity);
        $this->assertStringContainsString("NOT measurable on this repo: create pull-request comments (not asked: no unlocked pull request among the repo's newest 30 pull requests to post against)", $finding->message);
        $this->assertStringNotContainsString('re-run bridge:check', $finding->message);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    }

    public function test_a_failed_pull_request_list_read_leaves_the_write_unmeasured(): void
    {
        $this->fakeGitHub(write: Http::response([], 422), pulls: Http::response(['message' => 'boom'], 503));

        $finding = $this->onlyFinding();

        $this->assertSame(Severity::Unvalidated, $finding->severity);
        $this->assertStringContainsString("not asked: the read of the repo's pull requests, to find one to post against, answered HTTP 503", $finding->message);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    }

    public function test_no_answer_to_the_write_probe_is_unvalidated(): void
    {
        $this->fakeGitHub(write: fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $finding = $this->onlyFinding();

        $this->assertSame(Severity::Unvalidated, $finding->severity);
        $this->assertStringContainsString('create pull-request comments (no answer (cURL error 28', $finding->message);
    }

    public function test_an_unanswered_repo_read_leaves_the_write_unmeasured(): void
    {
        $this->fakeGitHub(repo: Http::response(['message' => 'Bad credentials'], 401), write: Http::response([], 422));

        $finding = $this->onlyFinding();

        $this->assertSame(Severity::Unvalidated, $finding->severity);
        $this->assertStringContainsString('read the repo (HTTP 401)', $finding->message);
        $this->assertStringContainsString('create pull-request comments (not asked: the repo read did not answer)', $finding->message);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    }

    public function test_a_token_that_cannot_see_the_repo_warns_and_asks_no_write(): void
    {
        $this->fakeGitHub(repo: Http::response(['message' => 'Not Found'], 404), write: Http::response([], 422));

        $finding = $this->onlyFinding();

        $this->assertSame(Severity::Warn, $finding->severity);
        $this->assertStringContainsString('read the repo (HTTP 404)', $finding->message);
        $this->assertStringContainsString('create pull-request comments (not asked: the token cannot see the repo)', $finding->message);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    }

    /** GitHub documents a 404, not a 403, for a classic token missing a scope. */
    public function test_a_classic_token_without_a_covering_scope_is_refused_on_a_404(): void
    {
        $this->fakeGitHub(repo: Http::response(['default_branch' => 'main', 'private' => false], 200, ['X-OAuth-Scopes' => 'read:org']), write: Http::response([], 404));

        $finding = $this->onlyFinding();

        $this->assertSame(Severity::Warn, $finding->severity);
        $this->assertStringContainsString('neither `repo` nor, on a public repo, `public_repo`', $finding->message);
    }

    public function test_a_404_from_a_classic_token_whose_scope_covers_the_repo_is_unvalidated(): void
    {
        $this->fakeGitHub(repo: Http::response(['default_branch' => 'main', 'private' => false], 200, ['X-OAuth-Scopes' => 'public_repo']), write: Http::response([], 404));

        $this->assertSame(Severity::Unvalidated, $this->onlyFinding()->severity);
    }

    /**
     * The scope addition: a write token placed for a repo answers its READS too. A refused read names
     * ONLY the legs that make it — here promote-on-release, not the correlation comment beside it.
     */
    public function test_a_refused_read_names_only_the_legs_that_make_it(): void
    {
        $this->fakeGitHub(write: Http::response([], 422), extra: [
            self::BASE.'/pulls*' => Http::response(self::PERMISSION_REFUSAL, 403),
            self::BASE.'/compare/main...main*' => Http::response(['status' => 'identical']),
        ]);

        $finding = $this->onlyFinding(promote: true);

        $this->assertSame(Severity::Warn, $finding->severity);
        $this->assertStringContainsString("read pull requests (HTTP 403, GitHub's permission refusal)", $finding->message);
        $this->assertStringContainsString('Pull requests READ', $finding->message);
        $this->assertStringContainsString('that need it — promote-on-release (DL-207) — are refused', $finding->message);
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), self::BASE.'/compare/main...main'));
    }

    public function test_compare_is_not_asked_without_a_default_branch_and_says_so(): void
    {
        $this->fakeGitHub(repo: Http::response(['private' => true]), write: Http::response([], 422), extra: [
            self::BASE.'/pulls*' => Http::response([]),
        ]);

        $finding = $this->onlyFinding(promote: true);

        $this->assertSame(Severity::Unvalidated, $finding->severity);
        $this->assertStringContainsString('compare commits (not asked: the repo read named no default branch to compare)', $finding->message);
    }

    // ---- thread kind: each write is measured only on the kind it lands on ----

    /**
     * The incident shape with a green check, before kind-specific probing: a token with Issues write
     * and no Pull requests write. The correlation comment lands on pull requests, so it is measured on
     * one — and refused — however an issue would have answered.
     */
    public function test_the_correlation_comment_is_measured_on_a_pull_request_never_an_issue(): void
    {
        $this->fakeGitHub(write: Http::response(self::PERMISSION_REFUSAL, 403), issueWrite: Http::response([], 422));

        $finding = $this->onlyFinding();

        $this->assertSame(Severity::Warn, $finding->severity);
        $this->assertStringContainsString('on #1031, a pull request', $finding->message);
        Http::assertNotSent(fn (Request $r) => $r->url() === self::ISSUE_PROBE_URL);
    }

    public function test_a_locked_pull_request_is_skipped(): void
    {
        $this->fakeGitHub(write: Http::response([], 422), pulls: Http::response([['number' => 1040, 'locked' => true], ['number' => 1031, 'locked' => false]]));

        $this->assertSame(Severity::Ok, $this->onlyFinding()->severity);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/issues/1040/'));
    }

    /** A 403 that is not GitHub's permission refusal (a locked conversation, a block, SAML) measures nothing. */
    public function test_a_403_that_is_not_the_permission_refusal_is_unvalidated(): void
    {
        $this->fakeGitHub(write: Http::response(['message' => 'Unable to create comment because issue is locked.'], 403));

        $finding = $this->onlyFinding();

        $this->assertSame(Severity::Unvalidated, $finding->severity);
        $this->assertStringContainsString("HTTP 403 to a comment on #1031, a pull request, and the answer is not GitHub's permission refusal", $finding->message);
    }

    // ---- labels: write on BOTH kinds (a comment can sit on either) AND a role of at least Triage ----

    public function test_a_label_is_refused_on_the_kind_the_token_cannot_write_and_names_only_the_label_leg(): void
    {
        config(['bridge.protocol_invalid_label.repos' => [self::REPO]]);
        $this->fakeGitHub(repo: $this->repoWithRoles(['admin' => true, 'maintain' => true, 'push' => true, 'triage' => true, 'pull' => true]), write: Http::response([], 422), issueWrite: Http::response(self::PERMISSION_REFUSAL, 403));

        $finding = $this->onlyFinding();

        $this->assertSame(Severity::Warn, $finding->severity);
        $this->assertStringContainsString("add labels to issues and pull requests (HTTP 403, GitHub's permission refusal, to a comment on #900, an issue)", $finding->message);
        $this->assertStringContainsString('that need it — protocol:invalid labels (DL-408) — are refused', $finding->message);
        Http::assertSent(fn (Request $r) => $r->url() === self::ISSUE_PROBE_URL && $r->body() === '{}');
    }

    /** A role below Triage is an INFERENCE from the repo read — never worded as GitHub's refusal. */
    public function test_a_label_below_triage_is_worded_as_an_inference_with_an_account_remedy(): void
    {
        config(['bridge.protocol_invalid_label.repos' => [self::REPO]]);
        $this->fakeGitHub(repo: $this->repoWithRoles(['admin' => false, 'maintain' => false, 'push' => false, 'triage' => false, 'pull' => true]), write: Http::response([], 422));

        $finding = $this->onlyFinding();

        $this->assertSame(Severity::Warn, $finding->severity);
        $this->assertStringContainsString('belongs to an account whose role on acme/widget cannot add labels to issues and pull requests', $finding->message);
        $this->assertStringContainsString('inferred from the repo read, not a request GitHub refused', $finding->message);
        $this->assertStringContainsString("Give the token's ACCOUNT at least the Triage role on acme/widget", $finding->message);
        $this->assertStringContainsString('protocol:invalid labels (DL-408) — will be refused', $finding->message);
        $this->assertStringNotContainsString('GitHub REFUSES', $finding->message);
    }

    public function test_a_label_is_ok_with_write_on_both_kinds_and_a_triage_role(): void
    {
        config(['bridge.protocol_invalid_label.repos' => [self::REPO]]);
        $this->fakeGitHub(repo: $this->repoWithRoles(['admin' => false, 'maintain' => false, 'push' => false, 'triage' => true, 'pull' => true]), write: Http::response([], 422));

        $finding = $this->onlyFinding();

        $this->assertSame(Severity::Ok, $finding->severity);
        $this->assertStringContainsString("on #900, an issue — past the permission check, nothing created; HTTP 422 to a comment with no body on #1031, a pull request — past the permission check, nothing created, and the account's role is at least Triage", $finding->message);
    }

    public function test_a_label_is_unvalidated_when_the_repo_read_reports_no_role(): void
    {
        config(['bridge.protocol_invalid_label.repos' => [self::REPO]]);
        $this->fakeGitHub(write: Http::response([], 422));

        $finding = $this->onlyFinding();

        $this->assertSame(Severity::Unvalidated, $finding->severity);
        $this->assertStringContainsString('the repo read reported no role', $finding->message);
    }

    public function test_a_label_with_no_issue_to_post_against_is_unvalidated_naming_the_kind(): void
    {
        config(['bridge.protocol_invalid_label.repos' => [self::REPO]]);
        $this->fakeGitHub(repo: $this->repoWithRoles(['triage' => true]), write: Http::response([], 422), extra: [
            self::BASE.'/issues?*' => Http::response([['number' => 1031, 'locked' => false, 'pull_request' => []]]),
        ]);

        $finding = $this->onlyFinding();

        $this->assertSame(Severity::Unvalidated, $finding->severity);
        $this->assertStringContainsString("add labels to issues and pull requests (not asked: no unlocked issue among the repo's newest 30 issues to post against)", $finding->message);
    }

    /** The role is the ACCOUNT's: an admin role does not make a read-only token able to label. */
    public function test_a_label_is_refused_when_the_token_cannot_write_whatever_the_role(): void
    {
        config(['bridge.protocol_invalid_label.repos' => [self::REPO]]);
        $this->fakeGitHub(repo: $this->repoWithRoles(['admin' => true, 'maintain' => true, 'push' => true, 'triage' => true, 'pull' => true]), write: Http::response(self::PERMISSION_REFUSAL, 403), issueWrite: Http::response(self::PERMISSION_REFUSAL, 403));

        $finding = $this->onlyFinding();

        $this->assertSame(Severity::Warn, $finding->severity);
        $this->assertStringContainsString('add labels to issues and pull requests (HTTP 403', $finding->message);
        $this->assertStringContainsString('PR correlation comments (DL-390), protocol:invalid labels (DL-408)', $finding->message);
    }

    public function test_a_repo_whose_token_does_not_resolve_is_left_to_github_token_file(): void
    {
        (new CoordCredentialStoreFixture($this->dir.'/coord'))->write(['github.com/acme' => self::KEY], []);
        Http::fake();

        $this->assertSame([], $this->findings());
        Http::assertNothingSent();
    }

    public function test_no_leg_switched_on_asks_nothing(): void
    {
        Http::fake();

        $this->assertSame([], $this->findingsOf(new GitHubRepoAccessCheck, new CheckContext));
        Http::assertNothingSent();
    }

    /** @param  array<string, bool>  $roles */
    private function repoWithRoles(array $roles): mixed
    {
        return Http::response(['default_branch' => 'main', 'private' => true, 'permissions' => $roles]);
    }

    /**
     * @param  mixed  $write  the comment probe's answer on the pull request (#1031)
     * @param  mixed  $repo  the `GET /repos/{repo}` answer (default: 200 naming `main`, fine-grained, no role)
     * @param  mixed  $pulls  the pull-request list (default: #1031, unlocked)
     * @param  mixed  $issueWrite  the comment probe's answer on the issue (#900)
     * @param  array<string, mixed>  $extra  further URL stubs, registered ahead of the defaults
     */
    private function fakeGitHub(mixed $write, mixed $repo = null, mixed $pulls = null, mixed $issueWrite = null, array $extra = []): void
    {
        Http::fake($extra + [
            self::PROBE_URL => $write,
            self::ISSUE_PROBE_URL => $issueWrite ?? Http::response(['message' => 'Validation Failed'], 422),
            self::BASE.'/issues/comments*' => Http::response([]),
            // The newest entry is a PULL REQUEST (it carries `pull_request`), so the issue pick must skip it.
            self::BASE.'/issues?*' => Http::response([['number' => 1031, 'locked' => false, 'pull_request' => []], ['number' => 900, 'locked' => false]]),
            self::BASE.'/pulls?*' => $pulls ?? Http::response([['number' => 1031, 'locked' => false]]),
            self::BASE => $repo ?? Http::response(['default_branch' => 'main', 'private' => true]),
        ]);
    }

    private function onlyFinding(bool $promote = false): Finding
    {
        $findings = $this->findings($promote);
        $this->assertCount(1, $findings);

        return $findings[0];
    }

    /** @return list<Finding> */
    private function findings(bool $promote = false): array
    {
        $ctx = new CheckContext;
        $ctx->writeback = new WritebackConfig(7, [self::REPO => new WritebackMapping(boardId: 8, stages: [], promoteOnRelease: $promote)]);

        return $this->findingsOf(new GitHubRepoAccessCheck, $ctx);
    }

    private function assertNoSecret(Finding $finding): void
    {
        $this->assertStringNotContainsString(self::TOKEN, $finding->message);
        $this->assertStringNotContainsString($this->tokenPath, $finding->message);
    }
}
