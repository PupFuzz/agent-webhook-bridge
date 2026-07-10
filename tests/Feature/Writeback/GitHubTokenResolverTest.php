<?php

namespace Tests\Feature\Writeback;

use App\Bridge\Writeback\GitHubTokenResolver;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * GitHubTokenResolver — the single home of the reconcile token precedence
 * (DL-184 core + DL-185 store-native). Exercises the store-native leg against a
 * REAL stub `git-credential-coord` (a subprocess, not Process::fake) so the actual
 * exec + stdout/stderr/exit-code state machine is validated on the real surface.
 */
class GitHubTokenResolverTest extends TestCase
{
    private string $dir;

    private string|false $origGhToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/ghtok-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/github');
        config(['bridge.secret_dir' => $this->dir]);
        // Hermetic: neutralize an ambient GH_TOKEN and the host's real
        // git-credential-coord (this machine has one on PATH). Each test opts into
        // a token source explicitly by writing a file / pointing at a stub.
        $this->origGhToken = getenv('GH_TOKEN');
        putenv('GH_TOKEN');
        config(['bridge.providers.github.credential_helper' => $this->dir.'/no-such-helper']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        if ($this->origGhToken === false) {
            putenv('GH_TOKEN');
        } else {
            putenv('GH_TOKEN='.$this->origGhToken);
        }
        parent::tearDown();
    }

    private function writeFileToken(string $token = 'ghp_file', int $mode = 0o600): void
    {
        File::put($this->dir.'/github/token', $token);
        chmod($this->dir.'/github/token', $mode);
    }

    /** Write an executable stub helper (sh) and point credential_helper at it. */
    private function useStub(string $body): void
    {
        $path = $this->dir.'/stub-helper-'.substr(md5($body), 0, 8);
        File::put($path, $body);
        chmod($path, 0o755);
        config(['bridge.providers.github.credential_helper' => $path]);
    }

    /** A stub that echoes a token derived from the requested path (proves per-repo + raw casing). */
    private function stubEchoPath(): string
    {
        return "#!/bin/sh\npath=\$(sed -n 's/^path=//p')\n"
            ."printf 'protocol=https\\nhost=github.com\\nusername=x-access-token\\npassword=tok:%s\\n' \"\$path\"\n";
    }

    private function resolver(): GitHubTokenResolver
    {
        return new GitHubTokenResolver;
    }

    // ---- legs 1 + 2: explicit token file ----

    public function test_token_path_override_wins_and_is_authoritative(): void
    {
        $custom = $this->dir.'/coord-pat';
        File::put($custom, 'ghp_custom');
        chmod($custom, 0o600);
        config(['bridge.providers.github.token_path' => $custom]);
        putenv('GH_TOKEN=ghp_env');
        $this->useStub($this->stubEchoPath());   // store would resolve, but override short-circuits it

        $r = $this->resolver()->resolveFor('owner/repo');
        $this->assertSame('ghp_custom', $r->token);
        $this->assertStringContainsString('override', (string) $r->source);
    }

    public function test_configured_override_missing_fails_loud_with_no_fallback(): void
    {
        config(['bridge.providers.github.token_path' => $this->dir.'/missing-pat']);
        putenv('GH_TOKEN=ghp_env');
        $this->useStub($this->stubEchoPath());

        $r = $this->resolver()->resolveFor('owner/repo');
        $this->assertFalse($r->ok());
        $this->assertStringContainsString('token_path', (string) $r->problem);
    }

    public function test_override_insecure_perms_is_a_problem(): void
    {
        $custom = $this->dir.'/coord-pat';
        File::put($custom, 'ghp_custom');
        chmod($custom, 0o644);
        config(['bridge.providers.github.token_path' => $custom]);

        $r = $this->resolver()->resolveFor('owner/repo');
        $this->assertFalse($r->ok());
        $this->assertStringContainsString('readable', (string) $r->problem);
    }

    public function test_conventional_file_wins_over_store_and_env(): void
    {
        $this->writeFileToken('ghp_file');
        putenv('GH_TOKEN=ghp_env');
        $this->useStub($this->stubEchoPath());   // present, but the file short-circuits it

        $r = $this->resolver()->resolveFor('owner/repo');
        $this->assertSame('ghp_file', $r->token);
    }

    public function test_conventional_file_insecure_perms_is_a_problem(): void
    {
        $this->writeFileToken('ghp_file', 0o644);

        $r = $this->resolver()->resolveFor('owner/repo');
        $this->assertFalse($r->ok());
        $this->assertStringContainsString('readable', (string) $r->problem);
    }

    // ---- leg 3: store-native ----

    public function test_store_resolves_per_repo_with_raw_casing(): void
    {
        $this->useStub($this->stubEchoPath());

        $a = $this->resolver()->resolveFor('MixedOrg/RepoA');
        $b = $this->resolver()->resolveFor('other/repo-b');
        $this->assertSame('tok:MixedOrg/RepoA', $a->token, 'store leg must receive the RAW (case-preserved) repo key');
        $this->assertSame('tok:other/repo-b', $b->token);
    }

    public function test_store_wins_over_a_set_gh_token(): void
    {
        putenv('GH_TOKEN=ghp_env');
        $this->useStub($this->stubEchoPath());

        $r = $this->resolver()->resolveFor('owner/repo');
        $this->assertSame('tok:owner/repo', $r->token, 'GH_TOKEN must never shadow a store-mapped token');
    }

    public function test_store_replace_me_placeholder_fails_loud(): void
    {
        putenv('GH_TOKEN=ghp_env');
        $this->useStub("#!/bin/sh\nprintf 'password=REPLACE_ME\\n'\n");

        $r = $this->resolver()->resolveFor('owner/repo');
        $this->assertFalse($r->ok(), 'a REPLACE_ME placeholder must not fall through to GH_TOKEN');
        $this->assertStringContainsString('REPLACE_ME', (string) $r->problem);
    }

    public function test_store_helper_nonzero_exit_fails_loud(): void
    {
        putenv('GH_TOKEN=ghp_env');
        $this->useStub("#!/bin/sh\necho 'boom' >&2\nexit 3\n");

        $r = $this->resolver()->resolveFor('owner/repo');
        $this->assertFalse($r->ok());
        $this->assertStringContainsString('boom', (string) $r->problem);
    }

    public function test_store_unreadable_keyfile_fails_loud_not_fallthrough(): void
    {
        // The real helper's unreadable-*_file path: exit 0, empty stdout, stderr set.
        putenv('GH_TOKEN=ghp_env');
        $this->useStub("#!/bin/sh\necho 'coordination_file unreadable' >&2\nexit 0\n");

        $r = $this->resolver()->resolveFor('owner/repo');
        $this->assertFalse($r->ok(), 'exit0+empty-stdout+stderr (unreadable *_file) must fail loud, not use GH_TOKEN');
        $this->assertStringContainsString('unreadable', (string) $r->problem);
    }

    public function test_store_unmapped_falls_through_to_gh_token(): void
    {
        // Genuinely unmapped: exit 0, empty stdout, EMPTY stderr.
        putenv('GH_TOKEN=ghp_env');
        $this->useStub("#!/bin/sh\nexit 0\n");

        $r = $this->resolver()->resolveFor('owner/repo');
        $this->assertSame('ghp_env', $r->token);
        $this->assertSame('GH_TOKEN', $r->source);
    }

    public function test_store_unmapped_and_no_env_fails_loud(): void
    {
        $this->useStub("#!/bin/sh\nexit 0\n");

        $r = $this->resolver()->resolveFor('owner/repo');
        $this->assertFalse($r->ok());
        $this->assertStringContainsString('GH_TOKEN is unset', (string) $r->problem);
    }

    // ---- leg 4 + helper availability ----

    public function test_missing_helper_falls_through_to_gh_token(): void
    {
        // credential_helper points at a nonexistent path (setUp default) → leg N/A.
        putenv('GH_TOKEN=ghp_env');

        $r = $this->resolver()->resolveFor('owner/repo');
        $this->assertSame('ghp_env', $r->token);
    }

    public function test_missing_helper_and_no_env_fails_loud(): void
    {
        $r = $this->resolver()->resolveFor('owner/repo');
        $this->assertFalse($r->ok());
    }

    public function test_empty_credential_helper_disables_store_leg(): void
    {
        config(['bridge.providers.github.credential_helper' => '']);
        putenv('GH_TOKEN=ghp_env');

        $r = $this->resolver()->resolveFor('owner/repo');
        $this->assertSame('ghp_env', $r->token);
    }

    public function test_resolution_is_memoized_per_repo(): void
    {
        // A stub that appends a call marker per invocation; a memoized resolveFor
        // must invoke the helper only once for the same repo.
        $marker = $this->dir.'/calls';
        $this->useStub("#!/bin/sh\necho x >> '{$marker}'\nprintf 'password=ghp_store\\n'\n");

        $resolver = $this->resolver();
        $resolver->resolveFor('owner/repo');
        $resolver->resolveFor('owner/repo');

        $this->assertSame(1, substr_count((string) @file_get_contents($marker), 'x'));
    }
}
