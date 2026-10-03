<?php

namespace Tests\Unit\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Checks\GitHubTokenFileCheck;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use App\Bridge\Writeback\GitHubWriteDebt;
use App\Bridge\Writeback\ProtocolInvalidLabeler;
use App\Bridge\Writeback\WritebackConfig;
use App\Bridge\Writeback\WritebackMapping;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\Support\MaterializesChecks;
use Tests\TestCase;

/**
 * The arms of `github.token_file` the command-level tests do not drive (card#11201): every way
 * the file can fail to resolve, every answer the one GitHub read can give, the owed-writes count,
 * and the store/`GH_TOKEN`-only installs the promote-only probe used to cover before it moved
 * here (`WritebackMappingConfigCheck`, DL-207).
 *
 * ⛔ A SILENT ARM IS ASSERTED WITH A WITNESS — a sent request, or a sibling finding from the same
 * run — never by emptiness alone, which a check returning at its first line would also satisfy.
 */
class GitHubTokenFileCheckTest extends TestCase
{
    use MaterializesChecks;

    private const REPO = 'owner/repo';

    private string $dir;

    private string|false $origGhToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/github-token-file-check-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/github');
        config([
            'bridge.secret_dir' => $this->dir,
            'bridge.state_dir' => $this->dir.'/state',
            'bridge.providers.github.token_path' => null,
            'bridge.providers.github.credential_helper' => $this->dir.'/no-store-helper',
            'bridge.protocol_invalid_label.repos' => [],
        ]);
        $this->origGhToken = getenv('GH_TOKEN');
        putenv('GH_TOKEN');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir.'/locked')) {
            chmod($this->dir.'/locked', 0o700);
        }
        File::deleteDirectory($this->dir);
        $this->origGhToken === false ? putenv('GH_TOKEN') : putenv('GH_TOKEN='.$this->origGhToken);
        parent::tearDown();
    }

    // ---- the file does not resolve ----

    public function test_a_directory_where_the_token_file_belongs_fails(): void
    {
        File::ensureDirectoryExists($this->dir.'/github/token');

        $finding = $this->onlyFinding($this->runCheck());

        $this->assertSame(Severity::Fail, $finding->severity);
        $this->assertStringContainsString($this->dir.'/github/token is not a regular file', $finding->message);
    }

    public function test_a_group_readable_token_file_fails_because_the_receiver_refuses_it_too(): void
    {
        $this->tokenFile('ghp_x', 0o644);

        $finding = $this->onlyFinding($this->runCheck());

        $this->assertSame(Severity::Fail, $finding->severity);
        $this->assertStringContainsString('group/world-readable', $finding->message);
        $this->assertStringContainsString('chmod 600', $finding->message);
    }

    public function test_a_token_file_this_process_cannot_read_is_unvalidated_and_never_convicts_the_receiver(): void
    {
        $path = $this->tokenFile('ghp_x', 0o000);
        clearstatcache(true, $path);
        if (is_readable($path)) {
            $this->markTestSkipped('this process reads through mode 0000 (running as root?) — the unreadable state is not reachable here');
        }

        $finding = $this->onlyFinding($this->runCheck());

        $this->assertSame(Severity::Unvalidated, $finding->severity);
        $this->assertStringContainsString('THIS process could not read it', $finding->message);
        $this->assertStringContainsString('PR correlation comments (DL-390) on owner/repo', $finding->message);
        $this->assertStringNotContainsString('INERT', $finding->message);
    }

    public function test_a_secret_dir_this_process_cannot_traverse_is_unvalidated_rather_than_absent(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $this->markTestSkipped('root bypasses directory permission checks');
        }
        File::ensureDirectoryExists($this->dir.'/locked/github');
        config(['bridge.secret_dir' => $this->dir.'/locked']);
        chmod($this->dir.'/locked', 0o000);

        $finding = $this->onlyFinding($this->runCheck());

        $this->assertSame(Severity::Unvalidated, $finding->severity);
        $this->assertStringContainsString('is not visible to this user', $finding->message);
    }

    public function test_a_gh_token_only_install_fails_because_the_receiver_never_sees_gh_token(): void
    {
        // The sharp case the promote-only probe was written for (DL-207), now for every consumer:
        // the token RESOLVES for bridge:reconcile, and the FPM receiver resolves nothing.
        putenv('GH_TOKEN=ghp_ambient');

        $finding = $this->onlyFinding($this->runCheck());

        $this->assertSame(Severity::Fail, $finding->severity);
        $this->assertStringContainsString('absent', $finding->message);
    }

    public function test_a_credential_store_only_install_fails_because_the_helper_is_cli_only(): void
    {
        $helper = $this->dir.'/store-helper';
        File::put($helper, "#!/bin/sh\nprintf 'password=ghp_from_the_store\\n'\n");
        chmod($helper, 0o700);
        config(['bridge.providers.github.credential_helper' => $helper]);

        $this->assertSame(Severity::Fail, $this->onlyFinding($this->runCheck())->severity);
    }

    public function test_a_token_path_override_whose_file_is_missing_fails_even_with_a_gh_token(): void
    {
        // The override is authoritative: a missing file resolves nothing, and GH_TOKEN is not consulted.
        config(['bridge.providers.github.token_path' => $this->dir.'/no-such-override-token']);
        putenv('GH_TOKEN=ghp_ambient');

        $finding = $this->onlyFinding($this->runCheck());

        $this->assertSame(Severity::Fail, $finding->severity);
        $this->assertStringContainsString('the configured token_path: '.$this->dir.'/no-such-override-token absent', $finding->message);
    }

    public function test_a_token_path_override_counts_as_the_token_file(): void
    {
        $override = $this->dir.'/override-token';
        File::put($override, 'ghp_override');
        chmod($override, 0o600);
        config(['bridge.providers.github.token_path' => $override]);
        Http::fake(['https://api.github.com/rate_limit' => Http::response([], 200, ['X-OAuth-Scopes' => 'repo'])]);

        $finding = $this->onlyFinding($this->runCheck());

        $this->assertSame(Severity::Ok, $finding->severity);
        $this->assertStringContainsString("token_path override ({$override})", $finding->message);
    }

    public function test_a_placed_token_file_is_the_one_tried_beside_a_gh_token(): void
    {
        $this->tokenFile('ghp_from_a_file');
        putenv('GH_TOKEN=ghp_ambient');
        Http::fake(['https://api.github.com/rate_limit' => Http::response([], 200, ['X-OAuth-Scopes' => 'repo'])]);

        $this->assertSame(Severity::Ok, $this->onlyFinding($this->runCheck())->severity);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer ghp_from_a_file'));
    }

    public function test_a_writeback_json_that_did_not_load_is_disclosed_rather_than_read_as_off(): void
    {
        $ctx = new CheckContext;
        $ctx->writebackUnread = true;

        $findings = $this->findingsOf(new GitHubTokenFileCheck, $ctx);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Unvalidated, $findings[0]->severity);
        $this->assertStringContainsString('writeback.json did not load', $findings[0]->message);
    }

    // ---- the file resolves: the one GitHub read ----

    public function test_a_token_github_refuses_fails(): void
    {
        $this->tokenFile('ghp_dead');
        Http::fake(['https://api.github.com/rate_limit' => Http::response(['message' => 'Bad credentials'], 401)]);

        $finding = $this->onlyFinding($this->runCheck());

        $this->assertSame(Severity::Fail, $finding->severity);
        $this->assertStringContainsString('HTTP 401', $finding->message);
        $this->assertStringContainsString('PR correlation comments (DL-390) on owner/repo', $finding->message);
    }

    public function test_any_other_status_is_unvalidated(): void
    {
        $this->tokenFile('ghp_x');
        Http::fake(['https://api.github.com/rate_limit' => Http::response([], 503)]);

        $finding = $this->onlyFinding($this->runCheck());

        $this->assertSame(Severity::Unvalidated, $finding->severity);
        $this->assertStringContainsString('HTTP 503', $finding->message);
        $this->assertStringContainsString('not evidence the token is bad', $finding->message);
    }

    public function test_an_unreachable_github_is_unvalidated(): void
    {
        $this->tokenFile('ghp_x');
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $finding = $this->onlyFinding($this->runCheck());

        $this->assertSame(Severity::Unvalidated, $finding->severity);
        $this->assertStringContainsString('could NOT reach GitHub', $finding->message);
        $this->assertSame(1, $attempts);
    }

    public function test_a_token_with_no_scopes_header_has_its_write_scope_reported_unmeasured(): void
    {
        $this->tokenFile('github_pat_fine_grained');
        Http::fake(['https://api.github.com/rate_limit' => Http::response(['resources' => []])]);

        $finding = $this->onlyFinding($this->runCheck());

        $this->assertSame(Severity::Unvalidated, $finding->severity);
        $this->assertStringContainsString('write scope UNMEASURED', $finding->message);
        Http::assertSentCount(1);
    }

    public function test_a_classic_token_with_public_repo_only_warns(): void
    {
        $this->tokenFile('ghp_x');
        Http::fake(['https://api.github.com/rate_limit' => Http::response([], 200, ['X-OAuth-Scopes' => 'public_repo, gist'])]);

        $finding = $this->onlyFinding($this->runCheck());

        $this->assertSame(Severity::Warn, $finding->severity);
        $this->assertStringContainsString('PUBLIC repo only', $finding->message);
        $this->assertStringContainsString('scopes: public_repo, gist', $finding->message);
    }

    public function test_a_classic_token_with_no_repo_scope_fails(): void
    {
        $this->tokenFile('ghp_x');
        Http::fake(['https://api.github.com/rate_limit' => Http::response([], 200, ['X-OAuth-Scopes' => ''])]);

        $finding = $this->onlyFinding($this->runCheck());

        $this->assertSame(Severity::Fail, $finding->severity);
        $this->assertStringContainsString('neither `repo` nor `public_repo` (no scope at all)', $finding->message);
    }

    public function test_a_scope_verdict_names_only_the_legs_that_write(): void
    {
        // promote-on-release only READS, so a token without write scope does not make it inert;
        // the comment leg on the same mapping writes, and is the one the verdict names.
        $this->tokenFile('ghp_x');
        Http::fake(['https://api.github.com/rate_limit' => Http::response([], 200, ['X-OAuth-Scopes' => ''])]);

        $finding = $this->onlyFinding($this->runCheck(new WritebackMapping(8, ['merged' => 52, 'merged_to_main' => 53], promoteOnRelease: true)));

        $this->assertSame(Severity::Fail, $finding->severity);
        $this->assertStringContainsString('INERT on this install: PR correlation comments (DL-390) on owner/repo.', $finding->message);
        $this->assertStringNotContainsString('promote-on-release', $finding->message);
    }

    public function test_a_missing_file_names_a_reading_leg_beside_the_writing_one(): void
    {
        $finding = $this->onlyFinding($this->runCheck(new WritebackMapping(8, ['merged' => 52, 'merged_to_main' => 53], promoteOnRelease: true), labelRepos: ['owner/other']));

        $this->assertSame(Severity::Fail, $finding->severity);
        $this->assertStringContainsString('PR correlation comments (DL-390) on owner/repo; protocol:invalid labels (DL-408) on owner/other; promote-on-release (DL-207) on owner/repo', $finding->message);
    }

    public function test_a_valid_token_with_every_consumer_off_is_not_put_to_github(): void
    {
        $this->tokenFile('ghp_x');
        Http::fake();

        $findings = $this->findingsOf(new GitHubTokenFileCheck, new CheckContext);

        $this->assertSame([], $findings);
        Http::assertNothingSent();
    }

    // ---- what a missing file already cost ----

    public function test_writes_dropped_for_want_of_a_token_are_counted_from_the_owed_record(): void
    {
        $this->tokenFile('ghp_x');
        Http::fake(['https://api.github.com/rate_limit' => Http::response([], 200, ['X-OAuth-Scopes' => 'repo'])]);
        GitHubWriteDebt::settle(GitHubWriteDebt::KIND_LABEL, self::REPO, 7, ['comment_id' => '7'], ProtocolInvalidLabeler::REASON_TOKEN_UNRESOLVED, null, true);
        GitHubWriteDebt::settle(GitHubWriteDebt::KIND_LABEL, self::REPO, 8, ['comment_id' => '8'], ProtocolInvalidLabeler::REASON_TOKEN_UNRESOLVED, null, true);
        // A write owed for another reason is not a token-file drop.
        GitHubWriteDebt::settle(GitHubWriteDebt::KIND_LABEL, self::REPO, 9, ['comment_id' => '9'], ProtocolInvalidLabeler::REASON_ADD_FAILED, null, true);

        $findings = $this->runCheck();

        $this->assertCount(2, $findings);
        $this->assertSame(Severity::Ok, $findings[0]->severity, 'the witness: the token verdict still leads');
        $this->assertSame(Severity::Warn, $findings[1]->severity, $findings[1]->message);
        $this->assertStringContainsString('2 GitHub write(s) decided since', $findings[1]->message);
        $this->assertStringContainsString('bridge:github-owed', $findings[1]->message);
    }

    public function test_an_unreadable_owed_record_is_unvalidated_and_never_counted_as_zero(): void
    {
        $this->tokenFile('ghp_x');
        Http::fake(['https://api.github.com/rate_limit' => Http::response([], 200, ['X-OAuth-Scopes' => 'repo'])]);
        File::ensureDirectoryExists($this->dir.'/state');
        File::put($this->dir.'/state/'.GitHubWriteDebt::FILE, '{ not json');

        $findings = $this->runCheck();

        $this->assertCount(2, $findings);
        $this->assertSame(Severity::Unvalidated, $findings[1]->severity);
        $this->assertStringContainsString('were NOT counted', $findings[1]->message);
    }

    /**
     * @param  list<string>  $labelRepos
     * @return list<Finding>
     */
    private function runCheck(?WritebackMapping $mapping = null, array $labelRepos = []): array
    {
        config(['bridge.protocol_invalid_label.repos' => $labelRepos]);
        $ctx = new CheckContext;
        $ctx->writeback = new WritebackConfig(null, [self::REPO => $mapping ?? new WritebackMapping(8, ['merged' => 52])]);

        return $this->findingsOf(new GitHubTokenFileCheck, $ctx);
    }

    /** @param  list<Finding>  $findings */
    private function onlyFinding(array $findings): Finding
    {
        $this->assertCount(1, $findings);

        return $findings[0];
    }

    private function tokenFile(string $contents, int $mode = 0o600): string
    {
        $path = $this->dir.'/github/token';
        File::put($path, $contents);
        chmod($path, $mode);

        return $path;
    }
}
