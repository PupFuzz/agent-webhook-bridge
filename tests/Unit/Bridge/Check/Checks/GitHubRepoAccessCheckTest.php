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
 * legs declare and the writes they declare — a comment by a POST of `{}` to an existing thread (422 =
 * can write, 403 = cannot, as measured on GitHub; see DL-500), a label from that answer plus the
 * account's role. WARN on a refusal naming the source and only the legs that depend on it, OK when
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

    private const PROBE_URL = self::BASE.'/issues/1031/comments';

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
        $this->fakeGitHub(write: Http::response(['message' => 'Resource not accessible by personal access token'], 403));

        $finding = $this->onlyFinding();

        $this->assertSame(Severity::Warn, $finding->severity);
        $this->assertStringContainsString('acme/widget resolves its GitHub token from store key readonly_key', $finding->message);
        $this->assertStringContainsString('create issue and pull-request comments (HTTP 403 to a comment on #1031)', $finding->message);
        $this->assertStringContainsString('Issues or Pull requests WRITE', $finding->message);
        $this->assertNoSecret($finding);
    }

    /** The probe posts a body GitHub can never accept, to a thread that exists. */
    public function test_the_write_probe_posts_an_empty_object_to_the_latest_issue(): void
    {
        $this->fakeGitHub(write: Http::response(['message' => 'Validation Failed'], 422));

        $this->onlyFinding();

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === self::PROBE_URL && $r->body() === '{}');
        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && str_starts_with($r->url(), self::BASE.'/issues?') && str_contains($r->url(), 'state=all'));
    }

    public function test_a_422_to_the_empty_comment_is_ok(): void
    {
        $this->fakeGitHub(write: Http::response(['message' => 'Validation Failed'], 422));

        $finding = $this->onlyFinding();

        $this->assertSame(Severity::Ok, $finding->severity);
        $this->assertStringContainsString('the token acme/widget resolves from store key readonly_key can read the repo (HTTP 200); read issue and pull-request comments (HTTP 200); create issue and pull-request comments (HTTP 422', $finding->message);
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
        $this->assertStringContainsString('NOT measured: create issue and pull-request comments (HTTP 404)', $finding->message);
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
        $this->assertStringContainsString('NOT measured: create issue and pull-request comments', $finding->message);
        $this->assertStringContainsString('Measured: it can read the repo (HTTP 200)', $finding->message);
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

    public function test_a_repo_with_no_issue_to_post_against_is_unvalidated_and_posts_nothing(): void
    {
        $this->fakeGitHub(write: Http::response([], 422), issues: Http::response([]));

        $finding = $this->onlyFinding();

        $this->assertSame(Severity::Unvalidated, $finding->severity);
        $this->assertStringContainsString('not asked: the repo has no issue or pull request to post against', $finding->message);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    }

    public function test_an_unanswered_issue_list_read_leaves_the_write_unmeasured(): void
    {
        $this->fakeGitHub(write: Http::response([], 422), issues: Http::response(['message' => 'boom'], 503));

        $finding = $this->onlyFinding();

        $this->assertSame(Severity::Unvalidated, $finding->severity);
        $this->assertStringContainsString('the read of an issue to post against did not answer (HTTP 503)', $finding->message);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    }

    public function test_no_answer_to_the_write_probe_is_unvalidated(): void
    {
        $this->fakeGitHub(write: fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $finding = $this->onlyFinding();

        $this->assertSame(Severity::Unvalidated, $finding->severity);
        $this->assertStringContainsString('create issue and pull-request comments (no answer (cURL error 28', $finding->message);
    }

    public function test_an_unanswered_repo_read_leaves_the_write_unmeasured(): void
    {
        $this->fakeGitHub(repo: Http::response(['message' => 'Bad credentials'], 401), write: Http::response([], 422));

        $finding = $this->onlyFinding();

        $this->assertSame(Severity::Unvalidated, $finding->severity);
        $this->assertStringContainsString('read the repo (HTTP 401)', $finding->message);
        $this->assertStringContainsString('create issue and pull-request comments (not asked: the repo read did not answer)', $finding->message);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    }

    public function test_a_token_that_cannot_see_the_repo_warns_and_asks_no_write(): void
    {
        $this->fakeGitHub(repo: Http::response(['message' => 'Not Found'], 404), write: Http::response([], 422));

        $finding = $this->onlyFinding();

        $this->assertSame(Severity::Warn, $finding->severity);
        $this->assertStringContainsString('read the repo (HTTP 404)', $finding->message);
        $this->assertStringContainsString('create issue and pull-request comments (not asked: the token cannot see the repo)', $finding->message);
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
            self::BASE.'/pulls*' => Http::response(['message' => 'Resource not accessible by personal access token'], 403),
            self::BASE.'/compare/main...main*' => Http::response(['status' => 'identical']),
        ]);

        $finding = $this->onlyFinding(promote: true);

        $this->assertSame(Severity::Warn, $finding->severity);
        $this->assertStringContainsString('read pull requests (HTTP 403)', $finding->message);
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

    // ---- labels: Issues write (the comment probe) AND an account role of at least Triage ----

    public function test_a_label_is_refused_when_the_role_is_below_triage_even_though_comments_can_be_written(): void
    {
        config(['bridge.protocol_invalid_label.repos' => [self::REPO]]);
        $this->fakeGitHub(repo: $this->repoWithRoles(['admin' => false, 'maintain' => false, 'push' => false, 'triage' => false, 'pull' => true]), write: Http::response([], 422));

        $finding = $this->onlyFinding();

        $this->assertSame(Severity::Warn, $finding->severity);
        $this->assertStringContainsString("add labels to issues and pull requests (the account's role on the repo is below Triage", $finding->message);
        $this->assertStringContainsString('that need it — protocol:invalid labels (DL-408) — are refused', $finding->message);
        $this->assertStringNotContainsString('NOT measured', $finding->message);
    }

    public function test_a_label_is_ok_with_issues_write_and_a_triage_role(): void
    {
        config(['bridge.protocol_invalid_label.repos' => [self::REPO]]);
        $this->fakeGitHub(repo: $this->repoWithRoles(['admin' => false, 'maintain' => false, 'push' => false, 'triage' => true, 'pull' => true]), write: Http::response([], 422));

        $finding = $this->onlyFinding();

        $this->assertSame(Severity::Ok, $finding->severity);
        $this->assertStringContainsString('add labels to issues and pull requests (the token can write issues (HTTP 422', $finding->message);
    }

    public function test_a_label_is_unvalidated_when_the_repo_read_reports_no_role(): void
    {
        config(['bridge.protocol_invalid_label.repos' => [self::REPO]]);
        $this->fakeGitHub(write: Http::response([], 422));

        $finding = $this->onlyFinding();

        $this->assertSame(Severity::Unvalidated, $finding->severity);
        $this->assertStringContainsString('the repo read reported no role', $finding->message);
    }

    /** The role is the ACCOUNT's: an admin role does not make a read-only token able to label. */
    public function test_a_label_is_refused_when_the_token_cannot_write_issues_whatever_the_role(): void
    {
        config(['bridge.protocol_invalid_label.repos' => [self::REPO]]);
        $this->fakeGitHub(repo: $this->repoWithRoles(['admin' => true, 'maintain' => true, 'push' => true, 'triage' => true, 'pull' => true]), write: Http::response([], 403));

        $finding = $this->onlyFinding();

        $this->assertSame(Severity::Warn, $finding->severity);
        $this->assertStringContainsString('add labels to issues and pull requests (the token cannot write issues (HTTP 403', $finding->message);
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
     * @param  mixed  $write  the write probe's answer
     * @param  mixed  $repo  the `GET /repos/{repo}` answer (default: 200 naming `main`, fine-grained, no role)
     * @param  mixed  $issues  the issue-list answer (default: one thread, #1031)
     * @param  array<string, mixed>  $extra  further URL stubs, registered ahead of the defaults
     */
    private function fakeGitHub(mixed $write, mixed $repo = null, mixed $issues = null, array $extra = []): void
    {
        Http::fake($extra + [
            self::PROBE_URL => $write,
            self::BASE.'/issues/comments*' => Http::response([]),
            self::BASE.'/issues?*' => $issues ?? Http::response([['number' => 1031]]),
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
