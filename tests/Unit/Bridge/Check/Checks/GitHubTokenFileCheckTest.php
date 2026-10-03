<?php

namespace Tests\Unit\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Checks\GitHubTokenFileCheck;
use App\Bridge\Support\Finding;
use App\Bridge\Support\ProcessIdentity;
use App\Bridge\Support\Severity;
use App\Bridge\Support\SystemProcessIdentity;
use App\Bridge\Writeback\GitHubWriteDebt;
use App\Bridge\Writeback\ProtocolInvalidLabeler;
use App\Bridge\Writeback\WritebackConfig;
use App\Bridge\Writeback\WritebackMapping;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\Support\CoordCredentialStoreFixture;
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

    private CoordCredentialStoreFixture $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/github-token-file-check-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/github');
        config([
            'bridge.secret_dir' => $this->dir,
            'bridge.state_dir' => $this->dir.'/state',
            'bridge.providers.github.token_path' => null,
            'bridge.protocol_invalid_label.repos' => [],
        ]);
        $this->store = (new CoordCredentialStoreFixture($this->dir.'/coord'))->use();
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

    // ---- per repo, by source (card#11208 / DL-456) ----

    public function test_a_credential_store_only_install_is_judged_on_the_file_the_store_names(): void
    {
        // Until DL-456 the store was a CLI-only helper and this install FAILED; the receiver now
        // reads the store in-process, so the check judges the file it names.
        $this->runAs($this->realEuid());
        $path = $this->storeKey('github.com/owner', 'framework', 'ghp_from_the_store');
        Http::fake(['https://api.github.com/rate_limit' => Http::response([], 200, ['X-OAuth-Scopes' => 'repo'])]);

        $finding = $this->onlyFinding($this->runCheck());

        $this->assertSame(Severity::Ok, $finding->severity, $finding->message);
        $this->assertStringContainsString("store key framework ([git-credential-map] github.com/owner) ({$path})", $finding->message);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer ghp_from_the_store'));
    }

    public function test_repos_on_two_sources_get_one_finding_and_one_read_each(): void
    {
        $this->runAs($this->realEuid());
        $this->tokenFile('ghp_owner_a');
        $this->storeKey('github.com/owner-b', 'owner_b', 'ghp_owner_b');
        Http::fake(['https://api.github.com/rate_limit' => Http::response([], 200, ['X-OAuth-Scopes' => 'repo'])]);

        $findings = $this->runCheckOn(['owner-a/one' => $this->mapping(), 'owner-b/two' => $this->mapping(), 'owner-b/three' => $this->mapping()]);

        $this->assertCount(2, $findings);
        $this->assertStringContainsString('token file ('.$this->dir.'/github/token)', $findings[0]->message);
        $this->assertStringContainsString('PR correlation comments (DL-390) on owner-a/one need', $findings[0]->message);
        $this->assertStringContainsString('store key owner_b ([git-credential-map] github.com/owner-b)', $findings[1]->message);
        $this->assertStringContainsString('on owner-b/two, owner-b/three', $findings[1]->message);
        Http::assertSentCount(2);
    }

    public function test_a_mapped_repo_whose_key_file_is_missing_fails_by_name_and_is_never_judged_on_the_single_file(): void
    {
        // THE CONTROL for a wrongly scoped fall-through: the single file is present and good, and
        // a resolver that let it stand in for the mapped repo would print an `ok` for it here.
        $this->runAs($this->realEuid());
        $this->tokenFile('ghp_owner_a');
        $this->store->write(['github.com/owner-b' => 'owner_b'], ['owner_b_file' => $this->store->dir.'/owner-b-token']);
        Http::fake(['https://api.github.com/rate_limit' => Http::response([], 200, ['X-OAuth-Scopes' => 'repo'])]);

        $findings = $this->runCheckOn(['owner-a/one' => $this->mapping(), 'owner-b/two' => $this->mapping()]);

        $this->assertCount(2, $findings);
        $this->assertSame(Severity::Ok, $findings[0]->severity, 'the witness: the single file serves the unmapped repo');
        $this->assertStringContainsString('owner-a/one', $findings[0]->message);
        $this->assertStringNotContainsString('owner-b/two', $findings[0]->message);
        $this->assertSame(Severity::Fail, $findings[1]->severity);
        $this->assertStringContainsString('the file store key owner_b ([git-credential-map] github.com/owner-b) names for owner-b/two', $findings[1]->message);
        $this->assertStringContainsString($this->store->dir.'/owner-b-token absent', $findings[1]->message);
        $this->assertStringContainsString('The single token file does not stand in for a repo the store maps; these legs reach GitHub with no token', $findings[1]->message);
        Http::assertSentCount(1);
    }

    public function test_a_store_the_bridge_cannot_parse_is_one_named_failure_over_every_repo(): void
    {
        $this->tokenFile('ghp_single');
        $this->store->raw("[github]\nghp_a_bare_token\n");
        Http::fake();

        $findings = $this->runCheckOn(['owner-a/one' => $this->mapping(), 'owner-b/two' => $this->mapping()]);

        $finding = $this->onlyFinding($findings);
        $this->assertSame(Severity::Fail, $finding->severity);
        $this->assertStringContainsString('the coord credential store at '.$this->store->path().' is not in the shape the bridge reads (line 2', $finding->message);
        $this->assertStringContainsString('on owner-a/one, owner-b/two', $finding->message);
        $this->assertStringNotContainsString('ghp_a_bare_token', $finding->message);
        Http::assertNothingSent();
    }

    public function test_a_store_this_process_cannot_read_is_unvalidated_never_ok_or_failed(): void
    {
        $this->tokenFile('ghp_single');
        $this->store->write(['github.com/o' => 'k'], ['k_file' => '/x']);
        chmod($this->store->path(), 0o000);
        clearstatcache();
        if (is_readable($this->store->path())) {
            $this->markTestSkipped('this process reads through mode 0000 (running as root?)');
        }

        $finding = $this->onlyFinding($this->runCheck());

        chmod($this->store->path(), 0o600);
        $this->assertSame(Severity::Unvalidated, $finding->severity);
        $this->assertStringContainsString('could not be read by this OS user', $finding->message);
    }

    public function test_a_write_token_path_is_named_as_the_source(): void
    {
        $this->runAs($this->realEuid());
        $this->tokenFile('ghp_single');
        $write = $this->store->tokenFile('write-token', 'ghp_write');
        Http::fake(['https://api.github.com/rate_limit' => Http::response([], 200, ['X-OAuth-Scopes' => 'repo'])]);

        $finding = $this->onlyFinding($this->runCheck(new WritebackMapping(8, ['merged' => 52], writeTokenPath: $write)));

        $this->assertSame(Severity::Ok, $finding->severity);
        $this->assertStringContainsString('write_token_path for owner/repo ('.$write.')', $finding->message);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer ghp_write'));
    }

    public function test_a_missing_write_token_path_fails_and_names_writeback_json(): void
    {
        $this->tokenFile('ghp_single');

        $finding = $this->onlyFinding($this->runCheck(new WritebackMapping(8, ['merged' => 52], writeTokenPath: $this->dir.'/missing')));

        $this->assertSame(Severity::Fail, $finding->severity);
        $this->assertStringContainsString('remove the repo\'s write_token_path from writeback.json', $finding->message);
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
        $this->runAs($this->realEuid());
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
        $this->runAs($this->realEuid());
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

    public function test_a_resolved_token_with_writeback_json_unread_and_no_leg_on_is_unvalidated_not_silent(): void
    {
        // Review r1 MINOR: "no leg that uses it is switched on" is unknowable when writeback.json,
        // which switches legs on, did not load.
        $this->tokenFile('ghp_x');
        Http::fake();
        $ctx = new CheckContext;
        $ctx->writebackUnread = true;

        $findings = $this->findingsOf(new GitHubTokenFileCheck, $ctx);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Unvalidated, $findings[0]->severity);
        $this->assertStringContainsString('a token file resolves (token file ('.$this->dir.'/github/token))', $findings[0]->message);
        $this->assertStringContainsString('writeback.json did not load', $findings[0]->message);
        Http::assertNothingSent();
    }

    public function test_with_writeback_json_unread_a_leg_switched_on_elsewhere_is_unvalidated_because_an_override_may_apply(): void
    {
        // Since DL-456 a repo's own write_token_path, in writeback.json, outranks every other
        // source — so with writeback.json unread, which file the label leg's repo uses is unknown,
        // and the receiver resolves nothing for it either.
        $this->tokenFile('ghp_x');
        Http::fake();
        config(['bridge.protocol_invalid_label.repos' => ['owner/other']]);
        $ctx = new CheckContext;
        $ctx->writebackUnread = true;

        $findings = $this->findingsOf(new GitHubTokenFileCheck, $ctx);

        $this->assertCount(2, $findings);
        $this->assertSame(Severity::Unvalidated, $findings[0]->severity);
        $this->assertStringContainsString('whether owner/other declares a write_token_path was NOT determined', $findings[0]->message);
        $this->assertStringContainsString('protocol:invalid labels (DL-408) on owner/other', $findings[0]->message);
        $this->assertSame(Severity::Unvalidated, $findings[1]->severity);
        $this->assertStringContainsString('writeback.json did not load', $findings[1]->message);
        Http::assertNothingSent();
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

    public function test_an_oauth_app_token_naming_no_scope_fails_as_classic(): void
    {
        $this->tokenFile('gho_oauth');
        Http::fake(['https://api.github.com/rate_limit' => Http::response([], 200, ['X-OAuth-Scopes' => ''])]);

        $this->assertSame(Severity::Fail, $this->onlyFinding($this->runCheck())->severity);
    }

    public function test_an_empty_scopes_header_on_a_token_that_is_not_classic_is_unmeasured_never_failed(): void
    {
        // Review r1 MINOR: an empty X-OAuth-Scopes is "no scope" only for a classic token; on a
        // fine-grained one (which writeback.md recommends) it would false-FAIL a working install.
        $this->tokenFile('github_pat_SECRETPART');
        Http::fake(['https://api.github.com/rate_limit' => Http::response([], 200, ['X-OAuth-Scopes' => ''])]);

        $finding = $this->onlyFinding($this->runCheck());

        $this->assertSame(Severity::Unvalidated, $finding->severity, $finding->message);
        $this->assertStringContainsString('write scope UNMEASURED', $finding->message);
        $this->assertStringContainsString('names no scope', $finding->message);
        $this->assertStringNotContainsString('SECRETPART', $finding->message);
        $this->assertStringNotContainsString('github_pat_', $finding->message);
    }

    public function test_the_repo_ok_names_what_a_scope_read_does_not_measure(): void
    {
        $this->runAs($this->realEuid());
        $this->tokenFile('ghp_x');
        Http::fake(['https://api.github.com/rate_limit' => Http::response([], 200, ['X-OAuth-Scopes' => 'repo'])]);

        $finding = $this->onlyFinding($this->runCheck());

        $this->assertSame(Severity::Ok, $finding->severity);
        $this->assertStringContainsString('scope only; repo access and SSO authorisation not measured', $finding->message);
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

    // ---- the file resolves: can the RECEIVER's user read it? ----

    public function test_a_token_file_owned_by_another_user_than_the_receivers_own_record_is_never_ok(): void
    {
        // The review's MAJOR (PR #854 r1): a 0600 file placed by the operator's login user reads fine
        // here and is unreadable to the receiver. The owed-writes record, in a state dir only its
        // owner can write, is the receiver's — the receiver must write there.
        $token = $this->tokenFile('ghp_x');
        $this->owedForAnotherReason();
        $me = $this->realEuid();
        $this->runAs($me, [$me => 'operator', $me + 1 => 'www-data'], [GitHubWriteDebt::path() => $me + 1, dirname(GitHubWriteDebt::path()) => $me + 1]);
        Http::fake(['https://api.github.com/rate_limit' => Http::response([], 200, ['X-OAuth-Scopes' => 'repo'])]);

        $finding = $this->onlyFinding($this->runCheck());

        $this->assertSame(Severity::Unvalidated, $finding->severity, $finding->message);
        $this->assertStringContainsString("{$token} is owned by operator", $finding->message);
        $this->assertStringContainsString(GitHubWriteDebt::path().' — in a state dir only www-data can write, where the receiver must write — is owned by www-data', $finding->message);
        $this->assertStringContainsString('sudo -u <pool user> php artisan bridge:check', $finding->message);
    }

    public function test_matching_owners_with_a_receiver_owned_record_still_carry_the_disclosure_on_the_ok(): void
    {
        // Review r4: ownership is inference, and an inference may only LOWER the verdict. A
        // matching owner cannot see an open_basedir or a service-unit sandbox, so its ok says
        // what was not measured, exactly as the ok with no record to compare does.
        $this->tokenFile('ghp_x');
        $this->owedForAnotherReason();
        $me = $this->realEuid();
        $this->runAs($me, [$me => 'www-data']);
        Http::fake(['https://api.github.com/rate_limit' => Http::response([], 200, ['X-OAuth-Scopes' => 'repo'])]);

        $finding = $this->onlyFinding($this->runCheck());

        $this->assertSame(Severity::Ok, $finding->severity, $finding->message);
        $this->assertStringContainsString("Whether the receiver's PHP-FPM pool user can read it was not measured: run `sudo -u <pool user> php artisan bridge:check`", $finding->message);
        $this->assertStringNotContainsString('so it reads the token file as its owner', $finding->message);
    }

    public function test_with_no_receiver_owned_file_to_compare_the_ok_discloses_the_pool_user_it_did_not_measure(): void
    {
        $this->tokenFile('ghp_x');
        $this->runAs($this->realEuid());
        Http::fake(['https://api.github.com/rate_limit' => Http::response([], 200, ['X-OAuth-Scopes' => 'repo'])]);

        $finding = $this->onlyFinding($this->runCheck());

        $this->assertSame(Severity::Ok, $finding->severity, $finding->message);
        $this->assertStringContainsString("Whether the receiver's PHP-FPM pool user can read it was not measured: run `sudo -u <pool user> php artisan bridge:check`", $finding->message);
    }

    public function test_a_root_run_with_no_receiver_owned_file_to_compare_is_never_ok(): void
    {
        $this->tokenFile('ghp_x');
        $this->runAs(0, [], [], tokenOwner: 1000);
        Http::fake(['https://api.github.com/rate_limit' => Http::response([], 200, ['X-OAuth-Scopes' => 'repo'])]);

        $finding = $this->onlyFinding($this->runCheck());

        $this->assertSame(Severity::Unvalidated, $finding->severity, $finding->message);
        $this->assertStringContainsString('this run is root, which reads any file', $finding->message);
        $this->assertStringContainsString('sudo -u <pool user> php artisan bridge:check', $finding->message);
    }

    public function test_a_root_owned_token_file_is_never_ok_because_the_receiver_never_runs_as_root(): void
    {
        // The sudo-placed file: root reads it, and a 0600 file root owns is closed to every other user.
        $token = $this->tokenFile('ghp_x');
        $this->runAs(0, [0 => 'root'], [], tokenOwner: 0);
        Http::fake(['https://api.github.com/rate_limit' => Http::response([], 200, ['X-OAuth-Scopes' => 'repo'])]);

        $finding = $this->onlyFinding($this->runCheck());

        $this->assertSame(Severity::Unvalidated, $finding->severity, $finding->message);
        $this->assertStringContainsString("{$token} is owned by root", $finding->message);
    }

    public function test_a_root_run_is_never_ok_even_where_the_token_file_and_the_receivers_record_share_an_owner(): void
    {
        // Review r2: root traverses a root:root 0700 directory above a token the receiver cannot
        // reach, so a shared owner is not enough under root.
        $this->tokenFile('ghp_x');
        $this->owedForAnotherReason();
        $this->runAs(0, [1000 => 'www-data'], [GitHubWriteDebt::path() => 1000, dirname(GitHubWriteDebt::path()) => 1000], tokenOwner: 1000);
        Http::fake(['https://api.github.com/rate_limit' => Http::response([], 200, ['X-OAuth-Scopes' => 'repo'])]);

        $finding = $this->onlyFinding($this->runCheck());

        $this->assertSame(Severity::Unvalidated, $finding->severity, $finding->message);
        $this->assertStringContainsString('this run is root', $finding->message);
        $this->assertStringContainsString('`sudo -u <pool user> php artisan bridge:check`', $finding->message);
    }

    public function test_a_record_in_a_group_writable_state_dir_is_no_evidence_of_the_receivers_user(): void
    {
        // Review r2 MAJOR: StateWriterRefusal does not refuse the FIRST write of an absent record,
        // so in a group-writable state dir the operator (say, `bridge:replay --force`) can own it.
        // Matching owners there prove nothing; the run falls through to the disclosure.
        $this->tokenFile('ghp_x');
        $this->owedForAnotherReason();
        chmod(dirname(GitHubWriteDebt::path()), 0o770);
        $me = $this->realEuid();
        $this->runAs($me, [$me => 'operator']);
        Http::fake(['https://api.github.com/rate_limit' => Http::response([], 200, ['X-OAuth-Scopes' => 'repo'])]);

        $finding = $this->onlyFinding($this->runCheck());

        $this->assertStringNotContainsString('the owner of', $finding->message, 'the record must not be cited as evidence');
        $this->assertStringContainsString("Whether the receiver's PHP-FPM pool user can read it was not measured: run `sudo -u <pool user> php artisan bridge:check`", $finding->message);
    }

    public function test_a_record_whose_state_dir_another_user_owns_is_no_evidence_of_the_receivers_user(): void
    {
        $this->tokenFile('ghp_x');
        $this->owedForAnotherReason();
        $me = $this->realEuid();
        $this->runAs($me, [$me => 'operator'], [dirname(GitHubWriteDebt::path()) => $me + 1]);
        Http::fake(['https://api.github.com/rate_limit' => Http::response([], 200, ['X-OAuth-Scopes' => 'repo'])]);

        $finding = $this->onlyFinding($this->runCheck());

        $this->assertStringNotContainsString('the owner of', $finding->message);
        $this->assertStringContainsString('was not measured', $finding->message);
    }

    public function test_a_root_run_with_a_group_writable_state_dir_is_unvalidated(): void
    {
        $this->tokenFile('ghp_x');
        $this->owedForAnotherReason();
        chmod(dirname(GitHubWriteDebt::path()), 0o770);
        $this->runAs(0, [1000 => 'operator'], [GitHubWriteDebt::path() => 1000, dirname(GitHubWriteDebt::path()) => 1000], tokenOwner: 1000);
        Http::fake(['https://api.github.com/rate_limit' => Http::response([], 200, ['X-OAuth-Scopes' => 'repo'])]);

        $finding = $this->onlyFinding($this->runCheck());

        $this->assertSame(Severity::Unvalidated, $finding->severity, $finding->message);
        $this->assertStringNotContainsString('the owner of', $finding->message);
    }

    public function test_a_state_dir_this_run_cannot_traverse_means_it_is_not_the_receivers_user(): void
    {
        // The review's scenario as it most often lands: the operator's login user owns the token,
        // the receiver owns a 0700 state dir the operator cannot enter. And the owed-record count
        // must not read that blindness as "nothing owed".
        if ($this->realEuid() === 0) {
            $this->markTestSkipped('root bypasses directory permission checks');
        }
        $this->tokenFile('ghp_x');
        File::ensureDirectoryExists($this->dir.'/locked/state');
        config(['bridge.state_dir' => $this->dir.'/locked/state']);
        chmod($this->dir.'/locked/state', 0o000);
        $this->runAs($this->realEuid());
        Http::fake(['https://api.github.com/rate_limit' => Http::response([], 200, ['X-OAuth-Scopes' => 'repo'])]);

        try {
            $findings = $this->runCheck();
        } finally {
            chmod($this->dir.'/locked/state', 0o700);
        }

        $this->assertCount(2, $findings);
        $this->assertSame(Severity::Unvalidated, $findings[0]->severity, $findings[0]->message);
        $this->assertStringContainsString('this run cannot see into '.$this->dir.'/locked/state', $findings[0]->message);
        $this->assertSame(Severity::Unvalidated, $findings[1]->severity, $findings[1]->message);
        $this->assertStringContainsString('the record of GitHub writes this install owes', $findings[1]->message);
        $this->assertStringContainsString('is not visible to this user', $findings[1]->message);
    }

    // ---- an identity this run cannot measure is never evidence (review r3) ----

    public function test_a_run_that_cannot_identify_its_own_user_is_never_ok(): void
    {
        // `sudo php artisan bridge:check` on a host without the posix extension: euid() is null,
        // which is not "not root".
        $this->tokenFile('ghp_x');
        $this->runAs(null);
        Http::fake(['https://api.github.com/rate_limit' => Http::response([], 200, ['X-OAuth-Scopes' => 'repo'])]);

        $finding = $this->onlyFinding($this->runCheck());

        $this->assertSame(Severity::Unvalidated, $finding->severity, $finding->message);
        $this->assertStringContainsString('this run could not identify its own user (no posix extension)', $finding->message);
        $this->assertStringContainsString('`sudo -u <pool user> php artisan bridge:check`', $finding->message);
    }

    public function test_a_run_that_cannot_identify_its_own_user_is_never_ok_even_with_matching_owners(): void
    {
        $this->tokenFile('ghp_x');
        $this->owedForAnotherReason();
        $this->runAs(null);
        Http::fake(['https://api.github.com/rate_limit' => Http::response([], 200, ['X-OAuth-Scopes' => 'repo'])]);

        $finding = $this->onlyFinding($this->runCheck());

        $this->assertSame(Severity::Unvalidated, $finding->severity, $finding->message);
        $this->assertStringContainsString('could not identify its own user', $finding->message);
    }

    public function test_a_token_file_whose_owner_this_run_cannot_read_is_never_ok(): void
    {
        $token = $this->tokenFile('ghp_x');
        $this->runAs($this->realEuid(), [], [], unknownOwners: [$token]);
        Http::fake(['https://api.github.com/rate_limit' => Http::response([], 200, ['X-OAuth-Scopes' => 'repo'])]);

        $finding = $this->onlyFinding($this->runCheck());

        $this->assertSame(Severity::Unvalidated, $finding->severity, $finding->message);
        $this->assertStringContainsString("could not read the owner of {$token}", $finding->message);
    }

    public function test_a_state_dir_whose_owner_this_run_cannot_read_is_never_ok(): void
    {
        $this->tokenFile('ghp_x');
        $this->owedForAnotherReason();
        $dir = dirname(GitHubWriteDebt::path());
        $this->runAs($this->realEuid(), [], [], unknownOwners: [$dir]);
        Http::fake(['https://api.github.com/rate_limit' => Http::response([], 200, ['X-OAuth-Scopes' => 'repo'])]);

        $finding = $this->onlyFinding($this->runCheck());

        $this->assertSame(Severity::Unvalidated, $finding->severity, $finding->message);
        $this->assertStringContainsString("could not read the mode or owner of {$dir}", $finding->message);
    }

    public function test_a_present_record_whose_owner_this_run_cannot_read_is_never_ok(): void
    {
        // Without the rule the LOCK would be read next and, matching, would pass.
        $this->tokenFile('ghp_x');
        $this->owedForAnotherReason();
        $this->runAs($this->realEuid(), [], [], unknownOwners: [GitHubWriteDebt::path()]);
        Http::fake(['https://api.github.com/rate_limit' => Http::response([], 200, ['X-OAuth-Scopes' => 'repo'])]);

        $finding = $this->onlyFinding($this->runCheck());

        $this->assertSame(Severity::Unvalidated, $finding->severity, $finding->message);
        $this->assertStringContainsString('could not read the owner of '.GitHubWriteDebt::path(), $finding->message);
    }

    public function test_a_present_lock_whose_owner_this_run_cannot_read_is_never_ok(): void
    {
        $this->tokenFile('ghp_x');
        $this->owedForAnotherReason();
        unlink(GitHubWriteDebt::path());
        $lock = GitHubWriteDebt::path().'.lock';
        $this->assertFileExists($lock);
        $this->runAs($this->realEuid(), [], [], unknownOwners: [$lock]);
        Http::fake(['https://api.github.com/rate_limit' => Http::response([], 200, ['X-OAuth-Scopes' => 'repo'])]);

        $finding = $this->onlyFinding($this->runCheck());

        $this->assertSame(Severity::Unvalidated, $finding->severity, $finding->message);
        $this->assertStringContainsString("could not read the owner of {$lock}", $finding->message);
    }

    public function test_an_owner_mismatch_is_named_on_an_unmeasured_scope_line_too(): void
    {
        $this->tokenFile('github_pat_fine_grained');
        $this->owedForAnotherReason();
        $me = $this->realEuid();
        $this->runAs($me, [$me => 'operator', $me + 1 => 'www-data'], [GitHubWriteDebt::path() => $me + 1, dirname(GitHubWriteDebt::path()) => $me + 1]);
        Http::fake(['https://api.github.com/rate_limit' => Http::response(['resources' => []])]);

        $finding = $this->onlyFinding($this->runCheck());

        $this->assertSame(Severity::Unvalidated, $finding->severity);
        $this->assertStringContainsString('write scope UNMEASURED', $finding->message);
        $this->assertStringContainsString('is owned by www-data', $finding->message);
    }

    // ---- what a missing file already cost ----

    public function test_writes_dropped_for_want_of_a_token_are_counted_from_the_owed_record(): void
    {
        $this->runAs($this->realEuid());
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

        return $this->runCheckOn([self::REPO => $mapping ?? $this->mapping()]);
    }

    /**
     * @param  array<string, WritebackMapping>  $mappings
     * @return list<Finding>
     */
    private function runCheckOn(array $mappings): array
    {
        $ctx = new CheckContext;
        $ctx->writeback = new WritebackConfig(null, $mappings);

        return $this->findingsOf(new GitHubTokenFileCheck, $ctx);
    }

    private function mapping(): WritebackMapping
    {
        return new WritebackMapping(8, ['merged' => 52]);
    }

    /** The store maps `$coordinate` to `$key`, whose file holds `$token`; returns that file. */
    private function storeKey(string $coordinate, string $key, string $token): string
    {
        $path = $this->store->tokenFile($key.'-token', $token);
        $this->store->write([$coordinate => $key], ["{$key}_file" => $path]);

        return $path;
    }

    /** @param  list<Finding>  $findings */
    private function onlyFinding(array $findings): Finding
    {
        $this->assertCount(1, $findings);

        return $findings[0];
    }

    /** A record of owed writes that holds no token-file drop, so the leg's drop count stays silent. */
    private function owedForAnotherReason(): void
    {
        GitHubWriteDebt::settle(GitHubWriteDebt::KIND_LABEL, self::REPO, 9, ['comment_id' => '9'], ProtocolInvalidLabeler::REASON_ADD_FAILED, null, true);
        $this->assertFileExists(GitHubWriteDebt::path());
    }

    private function realEuid(): int
    {
        $euid = (new SystemProcessIdentity)->euid();
        if ($euid === null) {
            $this->markTestSkipped('no posix extension: this suite cannot name its own uid');
        }

        return $euid;
    }

    /**
     * Run the rest of the test as $euid (null: no posix extension). An EXISTING file's owner is its
     * real one unless overridden per path; $tokenOwner overrides the token file's; a path in
     * $unknownOwners answers null — an owner this run could not read — though the file is there.
     *
     * @param  array<int, string>  $names
     * @param  array<string, int>  $owners
     * @param  list<string>  $unknownOwners
     */
    private function runAs(?int $euid, array $names = [], array $owners = [], ?int $tokenOwner = null, array $unknownOwners = []): void
    {
        if ($tokenOwner !== null) {
            $owners[$this->dir.'/github/token'] = $tokenOwner;
        }
        $this->app->instance(ProcessIdentity::class, new class($euid, $names, $owners, $unknownOwners) implements ProcessIdentity
        {
            /**
             * @param  array<int, string>  $names
             * @param  array<string, int>  $owners
             * @param  list<string>  $unknownOwners
             */
            public function __construct(private ?int $euid, private array $names, private array $owners, private array $unknownOwners) {}

            public function euid(): ?int
            {
                return $this->euid;
            }

            public function ownerOf(string $path): ?int
            {
                $real = (new SystemProcessIdentity)->ownerOf($path);
                if ($real === null || in_array($path, $this->unknownOwners, true)) {
                    return null;
                }

                return $this->owners[$path] ?? $real;
            }

            public function accountName(int $uid): ?string
            {
                return $this->names[$uid] ?? null;
            }
        });
    }

    private function tokenFile(string $contents, int $mode = 0o600): string
    {
        $path = $this->dir.'/github/token';
        File::put($path, $contents);
        chmod($path, $mode);

        return $path;
    }
}
