<?php

namespace Tests\Feature\Writeback;

use App\Bridge\Support\ProcessIdentity;
use App\Bridge\Support\SystemProcessIdentity;
use App\Bridge\Writeback\GitHubTokenResolver;
use App\Bridge\Writeback\TokenFileFault;
use App\Bridge\Writeback\TokenSource;
use App\Bridge\Writeback\WritebackConfig;
use App\Bridge\Writeback\WritebackMapping;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CoordCredentialStoreFixture;
use Tests\Support\PastedTokenFixture;
use Tests\TestCase;

/**
 * GitHubTokenResolver — the one home of the per-repo token precedence (DL-184, DL-185, DL-456):
 * the repo's `write_token_path`, then the coord credential store read in-process, then the single
 * file, then `GH_TOKEN` for a caller that asks. Every test writes real files in a temp dir; the
 * store is a real `credentials.ini` ({@see CoordCredentialStoreFixture}), never the operator's.
 */
class GitHubTokenResolverTest extends TestCase
{
    private string $dir;

    private string|false $origGhToken;

    private CoordCredentialStoreFixture $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/ghtok-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/github');
        config(['bridge.secret_dir' => $this->dir, 'bridge.config_dir' => $this->dir, 'bridge.providers.github.token_path' => null]);
        $this->store = new CoordCredentialStoreFixture($this->dir.'/coord');
        $this->store->use();   // absent until a test writes it: an empty store
        $this->origGhToken = getenv('GH_TOKEN');
        putenv('GH_TOKEN');
    }

    protected function tearDown(): void
    {
        if (is_file($this->store->path())) {
            chmod($this->store->path(), 0o600);
        }
        File::deleteDirectory($this->dir);
        putenv($this->origGhToken === false ? 'GH_TOKEN' : 'GH_TOKEN='.$this->origGhToken);
        parent::tearDown();
    }

    private function writeFileToken(string $token = 'ghp_file', int $mode = 0o600): void
    {
        File::put($this->dir.'/github/token', $token);
        chmod($this->dir.'/github/token', $mode);
    }

    /** @param  array<string, WritebackMapping>  $mappings */
    private function resolver(array $mappings = []): GitHubTokenResolver
    {
        return GitHubTokenResolver::forWriteback($mappings === [] ? null : new WritebackConfig(null, $mappings));
    }

    /** A store mapping `owner` to key `k`, whose `k_file` holds `$token`; returns the token file's path. */
    private function storeMapping(string $coordinate, string $key, string $token, int $mode = 0o600): string
    {
        $path = $this->store->tokenFile($key.'-token', $token, $mode);
        $this->store->write([$coordinate => $key], ["{$key}_file" => $path]);

        return $path;
    }

    // ---- leg 3: the single file ----

    public function test_token_path_override_wins_over_the_conventional_file_and_is_authoritative(): void
    {
        $this->writeFileToken('ghp_conventional');
        $custom = $this->dir.'/coord-pat';
        File::put($custom, 'ghp_custom');
        chmod($custom, 0o600);
        config(['bridge.providers.github.token_path' => $custom]);

        $r = $this->resolver()->resolveFor('owner/repo');

        $this->assertSame('ghp_custom', $r->token);
        $this->assertSame("token_path override ({$custom})", $r->source);
        $this->assertSame(TokenSource::TokenFile, $r->sourceKind);
        $this->assertSame($custom, $r->path);
    }

    public function test_a_missing_token_path_override_fails_loud_even_for_a_caller_that_asks_for_gh_token(): void
    {
        config(['bridge.providers.github.token_path' => $this->dir.'/missing-pat']);
        putenv('GH_TOKEN=ghp_env');

        $r = $this->resolver()->resolveForCli('owner/repo');

        $this->assertFalse($r->ok());
        $this->assertSame("no github token at the configured token_path: {$this->dir}/missing-pat absent", $r->problem);
    }

    public function test_an_empty_override_says_so_and_stays_authoritative(): void
    {
        $custom = $this->dir.'/coord-pat';
        File::put($custom, '');
        chmod($custom, 0o600);
        config(['bridge.providers.github.token_path' => $custom]);
        putenv('GH_TOKEN=ghp_env');

        $r = $this->resolver()->resolveForCli('owner/repo');

        $this->assertSame(TokenFileFault::Empty, $r->fileFault);
        $this->assertSame("no github token at the configured token_path: {$custom} is empty", $r->problem);
    }

    public function test_the_conventional_file_serves_an_unmapped_repo(): void
    {
        $this->writeFileToken('ghp_file');

        $r = $this->resolver()->resolveFor('owner/repo');

        $this->assertSame('ghp_file', $r->token);
        $this->assertSame(TokenSource::TokenFile, $r->sourceKind);
    }

    /**
     * card#11201: WHY the file did not resolve is a type on the resolution, so `bridge:check` can
     * tell a fault every reader shares from one only this process has.
     *
     * @return array<string, array{0: callable(string): void, 1: TokenFileFault, 2: string}>
     */
    public static function fileFaults(): array
    {
        return [
            'absent' => [fn (string $p) => null, TokenFileFault::Absent, 'no github token file: %s absent'],
            'zero bytes' => [function (string $p) {
                File::put($p, '');
                chmod($p, 0o600);
            }, TokenFileFault::Empty, 'no github token file: %s is empty'],
            'whitespace only' => [function (string $p) {
                File::put($p, " \n");
                chmod($p, 0o600);
            }, TokenFileFault::Empty, 'no github token file: %s is empty'],
            'a directory' => [fn (string $p) => File::ensureDirectoryExists($p), TokenFileFault::NotAFile, 'no github token file: %s is not a regular file'],
            'group-readable' => [function (string $p) {
                File::put($p, 'ghp_x');
                chmod($p, 0o644);
            }, TokenFileFault::InsecurePermissions, 'github token file %s: secret file at %s is group/world-readable'],
        ];
    }

    /** @param  callable(string): void  $place */
    #[DataProvider('fileFaults')]
    public function test_a_single_file_that_does_not_resolve_says_why_on_both_reads(callable $place, TokenFileFault $fault, string $problem): void
    {
        $path = $this->dir.'/github/token';
        $place($path);

        foreach ([$this->resolver()->resolveFromFile(), $this->resolver()->resolveFor('owner/repo')] as $r) {
            $this->assertFalse($r->ok());
            $this->assertSame($fault, $r->fileFault);
            $this->assertSame(TokenSource::TokenFile, $r->sourceKind);
            $this->assertStringStartsWith(sprintf($problem, $path, $path), (string) $r->problem);
        }
    }

    public function test_a_file_this_process_cannot_read_is_unreadable_not_absent(): void
    {
        $this->writeFileToken('ghp_file', 0o000);
        clearstatcache();
        if (is_readable($this->dir.'/github/token')) {
            $this->markTestSkipped('this process reads through mode 0000 (running as root?) — the unreadable state is not reachable here');
        }

        $this->assertSame(TokenFileFault::Unreadable, $this->resolver()->resolveFor('owner/repo')->fileFault);
    }

    public function test_an_unfilled_placeholder_is_never_handed_out_as_a_token(): void
    {
        $this->writeFileToken('REPLACE_ME');

        $r = $this->resolver()->resolveFor('owner/repo');

        $this->assertFalse($r->ok());
        $this->assertSame(TokenFileFault::Empty, $r->fileFault);
        $this->assertStringContainsString('REPLACE_ME placeholder', (string) $r->problem);
    }

    // ---- leg 4: GH_TOKEN, for a caller that asks ----

    public function test_gh_token_serves_only_a_caller_that_asks_for_it(): void
    {
        putenv('GH_TOKEN=ghp_env');

        $runtime = $this->resolver()->resolveFor('owner/repo');
        $cli = $this->resolver()->resolveForCli('owner/repo');

        $this->assertFalse($runtime->ok(), 'the receiver never reads GH_TOKEN, so a shell replay cannot post as an identity the receiver never uses');
        $this->assertSame('no github token file: '.$this->dir.'/github/token absent', $runtime->problem);
        $this->assertSame('ghp_env', $cli->token);
        $this->assertSame(TokenSource::Ambient, $cli->sourceKind);
    }

    public function test_an_empty_conventional_file_still_falls_through_to_gh_token_for_a_cli_caller(): void
    {
        $this->writeFileToken('');

        $r = $this->resolver()->resolveForCli('owner/repo');
        $this->assertSame('no github token: '.$this->dir.'/github/token is empty, no [git-credential-map] entry for owner/repo, and GH_TOKEN is unset', $r->problem);

        putenv('GH_TOKEN=ghp_env');
        $this->assertSame('ghp_env', $this->resolver()->resolveForCli('owner/repo')->token);
    }

    // ---- leg 2: the coord credential store ----

    public function test_a_mapped_repo_resolves_its_keys_file_ahead_of_the_single_file(): void
    {
        $this->writeFileToken('ghp_single');
        $path = $this->storeMapping('github.com/owner', 'framework', 'ghp_store');

        $r = $this->resolver()->resolveFor('owner/repo');

        $this->assertSame('ghp_store', $r->token);
        $this->assertSame(TokenSource::Store, $r->sourceKind);
        $this->assertSame($path, $r->path);
        $this->assertSame("store key framework ([git-credential-map] github.com/owner) ({$path})", $r->source);
    }

    public function test_a_one_owner_single_file_never_shadows_another_owners_mapped_repo(): void
    {
        // The rt#593 install: one owner's repos ride the single file, the other owner's are mapped.
        $this->writeFileToken('ghp_owner_a');
        $this->storeMapping('github.com/owner-b', 'owner_b', 'ghp_owner_b');
        $resolver = $this->resolver();

        $this->assertSame('ghp_owner_a', $resolver->resolveFor('owner-a/repo')->token);
        $this->assertSame('ghp_owner_b', $resolver->resolveFor('owner-b/repo')->token);
    }

    public function test_the_map_is_read_most_specific_first(): void
    {
        $repoFile = $this->store->tokenFile('repo-token', 'ghp_repo');
        $ownerFile = $this->store->tokenFile('owner-token', 'ghp_owner');
        $hostFile = $this->store->tokenFile('host-token', 'ghp_host');
        $this->store->write(
            ['github.com' => 'host', 'github.com/o' => 'owner', 'github.com/o/r' => 'repo'],
            ['host_file' => $hostFile, 'owner_file' => $ownerFile, 'repo_file' => $repoFile],
        );
        $resolver = $this->resolver();

        $this->assertSame('ghp_repo', $resolver->resolveFor('o/r')->token);
        $this->assertSame('ghp_owner', $resolver->resolveFor('o/s')->token);
        $this->assertSame('ghp_host', $resolver->resolveFor('x/y')->token);
    }

    public function test_a_blank_map_value_moves_on_to_the_next_candidate_as_the_helper_does(): void
    {
        $ownerFile = $this->store->tokenFile('owner-token', 'ghp_owner');
        $this->store->write(['github.com/o/r' => '', 'github.com/o' => 'owner'], ['owner_file' => $ownerFile]);

        $this->assertSame('ghp_owner', $this->resolver()->resolveFor('o/r')->token);
    }

    public function test_the_map_is_matched_with_the_case_written_and_asked_with_the_configured_spelling(): void
    {
        $this->writeFileToken('ghp_single');
        $this->storeMapping('github.com/MixedOrg/RepoA', 'mixed', 'ghp_mixed');

        // Unmapped in writeback.json, a different spelling does not match — the helper's exact case.
        $this->assertSame('ghp_single', $this->resolver()->resolveFor('mixedorg/repoa')->token);
        // Mapped, the resolver asks the store with the spelling writeback.json uses (card#7124).
        $mapped = $this->resolver(['MixedOrg/RepoA' => new WritebackMapping(8, ['merged' => 52])]);
        $this->assertSame('ghp_mixed', $mapped->resolveFor('mixedorg/repoa')->token);
    }

    public function test_the_github_key_is_matched_exactly_then_case_insensitively_as_the_frameworks_reader_does(): void
    {
        $path = $this->store->tokenFile('t', 'ghp_folded');
        $this->store->write(['github.com/o' => 'Coordination'], ['coordination_file' => $path]);

        $this->assertSame('ghp_folded', $this->resolver()->resolveFor('o/r')->token);
    }

    public function test_a_tilde_pointer_expands_against_the_stores_owner_home(): void
    {
        if (! function_exists('posix_getpwuid') || ! function_exists('posix_geteuid')) {
            $this->markTestSkipped('no posix extension');
        }
        $home = posix_getpwuid(posix_geteuid())['dir'] ?? null;
        $this->store->write(['github.com/o' => 'k'], ['k_file' => '~/.bridge-test-no-such-token-'.uniqid()]);

        $r = $this->resolver()->resolveFor('o/r');

        $this->assertSame(TokenFileFault::Absent, $r->fileFault);
        $this->assertStringStartsWith("{$home}/.bridge-test-no-such-token-", (string) $r->path);
    }

    /**
     * ⛔ FAIL LOUD: a mapped key that cannot give its repo a token is that repo's fault, never a
     * fall-through to the single file — which, here, is present and would resolve (the control).
     *
     * @return array<string, array{0: callable(CoordCredentialStoreFixture): void, 1: TokenFileFault, 2: string}>
     */
    public static function mappedKeyFaults(): array
    {
        $mapped = fn (CoordCredentialStoreFixture $s, string $pointer) => $s->write(['github.com/o' => 'k'], ['k_file' => $pointer]);

        return [
            'its file is absent' => [fn ($s) => $mapped($s, $s->dir.'/nope'), TokenFileFault::Absent, 'absent'],
            'its file is empty' => [fn ($s) => $mapped($s, $s->tokenFile('t', '')), TokenFileFault::Empty, 'is empty'],
            'its file is whitespace' => [fn ($s) => $mapped($s, $s->tokenFile('t', "  \n")), TokenFileFault::Empty, 'is empty'],
            'its file is the placeholder' => [fn ($s) => $mapped($s, $s->tokenFile('t', 'REPLACE_ME')), TokenFileFault::Empty, 'REPLACE_ME placeholder'],
            'its file is group-readable' => [fn ($s) => $mapped($s, $s->tokenFile('t', 'ghp_x', 0o644)), TokenFileFault::InsecurePermissions, 'group/world-readable'],
            'its file is a directory' => [function ($s) use ($mapped) {
                File::ensureDirectoryExists($s->dir.'/adir');
                $mapped($s, $s->dir.'/adir');
            }, TokenFileFault::NotAFile, 'not a regular file'],
            'the key has no _file pointer' => [fn ($s) => $s->write(['github.com/o' => 'k'], ['other_file' => '/x']), TokenFileFault::Misconfigured, 'no `k_file` pointer'],
            'the pointer is relative' => [fn ($s) => $mapped($s, 'tokens/k'), TokenFileFault::Misconfigured, 'not an absolute path'],
            'the pointer holds %%' => [fn ($s) => $mapped($s, '/tmp/a%%b'), TokenFileFault::Misconfigured, '`%%` or `%(`'],
            'the pointer is ~user/' => [fn ($s) => $mapped($s, '~root/token'), TokenFileFault::Misconfigured, 'not an absolute path'],
        ];
    }

    /** @param  callable(CoordCredentialStoreFixture): void  $arrange */
    #[DataProvider('mappedKeyFaults')]
    public function test_a_mapped_key_that_cannot_serve_its_repo_fails_loud_and_never_falls_through(callable $arrange, TokenFileFault $fault, string $says): void
    {
        $this->writeFileToken('ghp_single');
        putenv('GH_TOKEN=ghp_env');
        $arrange($this->store);

        foreach (['resolveFor', 'resolveForCli'] as $method) {
            $r = $this->resolver()->{$method}('o/r');
            $this->assertFalse($r->ok(), 'the single file and GH_TOKEN must not stand in for a mapped repo');
            $this->assertSame($fault, $r->fileFault);
            $this->assertSame(TokenSource::Store, $r->sourceKind);
            $this->assertStringContainsString($says, (string) $r->problem);
            $this->assertStringContainsString('store key k ([git-credential-map] github.com/o)', (string) $r->problem);
        }
        $this->assertSame('ghp_single', $this->resolver()->resolveFor('elsewhere/repo')->token, 'the control: an unmapped repo still resolves the single file');
    }

    public function test_an_inline_store_value_is_refused_and_never_printed(): void
    {
        $this->store->write(['github.com/o' => 'k'], ['k' => 'ghp_inline_value_never_printed']);

        $r = $this->resolver()->resolveFor('o/r');

        $this->assertSame(TokenFileFault::Misconfigured, $r->fileFault);
        $this->assertStringContainsString('INLINE value', (string) $r->problem);
        $this->assertStringNotContainsString('ghp_inline_value_never_printed', (string) $r->problem);
    }

    public function test_a_token_pasted_into_a_file_slot_is_never_printed(): void
    {
        $this->store->write(['github.com/o' => 'k'], ['k_file' => 'ghp_pasted_into_the_slot_0123456789']);

        $r = $this->resolver()->resolveFor('o/r');

        $this->assertSame(TokenFileFault::Misconfigured, $r->fileFault);
        $this->assertStringNotContainsString('ghp_pasted', (string) $r->problem);
    }

    /**
     * ⛔ A STORE NAME OR VALUE WITH A CREDENTIAL'S SHAPE IS NEVER RENDERED (round-1 review, MF 1):
     * every problem below is logged on each delivery and printed by `bridge:check`.
     *
     * @return array<string, array{0: callable(CoordCredentialStoreFixture): void, 1: string}>
     */
    public static function pastedTokenShapes(): array
    {
        $t = PastedTokenFixture::value();

        return [
            'the token as the map value' => [fn ($s) => $s->write(['github.com/o/r' => $t], []), 'credential-shaped'],
            'the token as the map value, with a _file under it' => [fn ($s) => $s->write(['github.com/o/r' => $t], ["{$t}_file" => $s->dir.'/nope']), 'credential-shaped'],
            'the token as the map value, with an inline value under it' => [fn ($s) => $s->write(['github.com/o/r' => $t], [$t => 'x']), 'credential-shaped'],
            'the token as a map name, with a value holding a space' => [fn ($s) => $s->raw("[git-credential-map]\n{$t} = two words\n"), 'line 2'],
            'the token as a map name, with a value holding %%' => [fn ($s) => $s->raw("[git-credential-map]\n{$t} = a%%b\n"), 'line 2'],
        ];
    }

    /** @param  callable(CoordCredentialStoreFixture): void  $arrange */
    #[DataProvider('pastedTokenShapes')]
    public function test_a_store_name_or_value_shaped_like_a_token_is_never_printed(callable $arrange, string $says): void
    {
        $arrange($this->store);

        $r = $this->resolver()->resolveFor('o/r');

        $this->assertFalse($r->ok());
        $this->assertStringNotContainsString(PastedTokenFixture::value(), (string) $r->problem);
        $this->assertStringNotContainsString(substr(PastedTokenFixture::value(), 0, 12), (string) $r->problem);
        $this->assertStringContainsString($says, (string) $r->problem, 'the witness: the problem is the one this shape raises');
    }

    public function test_a_long_key_name_is_elided_from_messages_but_still_serves_its_repo(): void
    {
        // The framework's heuristic flags any separator-free name of 24+ characters without a dot;
        // a key like this is legitimate, so it is elided where printed, never refused.
        $path = $this->storeMapping('github.com/o', 'agent_webhook_bridge_writer', 'ghp_long_key');

        $r = $this->resolver()->resolveFor('o/r');

        $this->assertSame('ghp_long_key', $r->token);
        $this->assertSame($path, $r->path);
    }

    public function test_a_token_file_another_user_owns_is_refused(): void
    {
        $path = $this->storeMapping('github.com/o', 'k', 'ghp_x');
        $this->ownersAre([$path => 4242]);

        $r = $this->resolver()->resolveFor('o/r');

        $this->assertSame(TokenFileFault::Misconfigured, $r->fileFault);
        $this->assertStringContainsString('not by the store\'s owner', (string) $r->problem);
    }

    public function test_a_token_file_whose_owner_cannot_be_read_is_undetermined(): void
    {
        $path = $this->storeMapping('github.com/o', 'k', 'ghp_x');
        $this->ownersAre([$path => null]);

        $r = $this->resolver()->resolveFor('o/r');

        $this->assertSame(TokenFileFault::Undetermined, $r->fileFault);
    }

    // ---- the store itself ----

    public function test_an_absent_store_is_an_empty_store(): void
    {
        $this->writeFileToken('ghp_single');

        $this->assertSame('ghp_single', $this->resolver()->resolveFor('o/r')->token);
    }

    public function test_a_malformed_store_resolves_nothing_for_any_repo_and_names_the_line_not_its_text(): void
    {
        $this->writeFileToken('ghp_single');
        $this->store->raw("[github]\nghp_a_bare_token_line_never_printed\n");

        $r = $this->resolver()->resolveFor('anyone/any');

        $this->assertFalse($r->ok(), 'the store may map this repo, so the single file must not stand in');
        $this->assertSame(TokenFileFault::Misconfigured, $r->fileFault);
        $this->assertSame(TokenSource::Store, $r->sourceKind);
        $this->assertStringContainsString('line 2 is neither a comment', (string) $r->problem);
        $this->assertStringNotContainsString('ghp_a_bare', (string) $r->problem);
    }

    public function test_a_store_this_process_cannot_read_is_unreadable_and_resolves_nothing(): void
    {
        $this->writeFileToken('ghp_single');
        $this->storeMapping('github.com/o', 'k', 'ghp_x');
        chmod($this->store->path(), 0o000);
        clearstatcache();
        if (is_readable($this->store->path())) {
            $this->markTestSkipped('this process reads through mode 0000 (running as root?)');
        }

        $r = $this->resolver()->resolveFor('elsewhere/repo');

        $this->assertSame(TokenFileFault::Unreadable, $r->fileFault);
    }

    public function test_a_symlinked_store_is_refused(): void
    {
        $this->writeFileToken('ghp_single');
        $real = $this->dir.'/real.ini';
        File::put($real, "[github]\n");
        symlink($real, $this->store->path());

        $r = $this->resolver()->resolveFor('o/r');

        $this->assertSame(TokenFileFault::Misconfigured, $r->fileFault);
        $this->assertStringContainsString('not a file the bridge will read', (string) $r->problem);
    }

    public function test_with_no_store_setting_and_no_roster_setting_the_store_is_undetermined_and_nothing_resolves(): void
    {
        $this->writeFileToken('ghp_single');
        config(['bridge.coord_credentials_path' => null, 'bridge.coord_config_path' => null]);

        $r = $this->resolver()->resolveFor('o/r');

        $this->assertSame(TokenFileFault::Misconfigured, $r->fileFault);
        $this->assertStringContainsString('BRIDGE_COORD_CREDENTIALS_PATH', (string) $r->problem);
    }

    public function test_the_default_store_sits_beside_the_coord_roster(): void
    {
        $this->storeMapping('github.com/o', 'k', 'ghp_beside_the_roster');
        config(['bridge.coord_credentials_path' => null, 'bridge.coord_config_path' => $this->store->dir.'/coordination.config.json']);

        $this->assertSame('ghp_beside_the_roster', $this->resolver()->resolveFor('o/r')->token);
    }

    public function test_a_relative_store_setting_is_refused(): void
    {
        config(['bridge.coord_credentials_path' => 'relative/credentials.ini']);

        $this->assertSame(TokenFileFault::Misconfigured, $this->resolver()->resolveFor('o/r')->fileFault);
    }

    // ---- leg 1: the repo's write_token_path ----

    public function test_write_token_path_wins_over_the_store_and_the_single_file(): void
    {
        $this->writeFileToken('ghp_single');
        $this->storeMapping('github.com/o', 'readonly', 'ghp_readonly');
        $write = $this->store->tokenFile('write-token', 'ghp_write');

        $r = $this->resolver(['O/R' => new WritebackMapping(8, ['merged' => 52], writeTokenPath: $write)])->resolveFor('o/r');

        $this->assertSame('ghp_write', $r->token);
        $this->assertSame(TokenSource::WriteTokenPath, $r->sourceKind);
        $this->assertSame("write_token_path for O/R ({$write})", $r->source);
    }

    public function test_a_write_token_path_that_does_not_resolve_fails_loud_with_no_fallback(): void
    {
        $this->writeFileToken('ghp_single');
        $this->storeMapping('github.com/o', 'readonly', 'ghp_readonly');

        $r = $this->resolver(['o/r' => new WritebackMapping(8, ['merged' => 52], writeTokenPath: $this->dir.'/missing')])->resolveFor('o/r');

        $this->assertFalse($r->ok());
        $this->assertSame(TokenFileFault::Absent, $r->fileFault);
        $this->assertSame("no github token at the write_token_path writeback.json declares for o/r: {$this->dir}/missing absent", $r->problem);
    }

    public function test_a_write_token_path_another_user_owns_is_refused_and_the_single_file_does_not_stand_in(): void
    {
        $this->writeFileToken('ghp_single');
        config(['bridge.config_dir' => $this->dir]);
        $write = $this->store->tokenFile('write-token', 'ghp_write');
        $this->ownersAre([$write => 4242]);

        $r = $this->resolver(['o/r' => new WritebackMapping(8, ['merged' => 52], writeTokenPath: $write)])->resolveFor('o/r');

        $this->assertFalse($r->ok());
        $this->assertSame(TokenFileFault::Misconfigured, $r->fileFault);
        $this->assertSame(TokenSource::WriteTokenPath, $r->sourceKind);
        $this->assertStringContainsString("not by the config dir's owner", (string) $r->problem);
    }

    public function test_a_write_token_path_whose_owner_cannot_be_read_is_undetermined(): void
    {
        config(['bridge.config_dir' => $this->dir]);
        $write = $this->store->tokenFile('write-token', 'ghp_write');
        $this->ownersAre([$write => null]);

        $r = $this->resolver(['o/r' => new WritebackMapping(8, ['merged' => 52], writeTokenPath: $write)])->resolveFor('o/r');

        $this->assertSame(TokenFileFault::Undetermined, $r->fileFault);
    }

    public function test_the_single_file_is_not_held_to_an_owner_rule(): void
    {
        // The documented `token_path` override is a CENTRALIZED credential another account may own;
        // the rule covers the legs another account's file names (store, write_token_path) only.
        $this->writeFileToken('ghp_single');
        $this->ownersAre([$this->dir.'/github/token' => 4242]);

        $this->assertSame('ghp_single', $this->resolver()->resolveFor('o/r')->token);
    }

    public function test_an_unloadable_writeback_json_resolves_nothing_because_an_override_may_apply(): void
    {
        $this->writeFileToken('ghp_single');
        File::put($this->dir.'/writeback.json', '{ not json');
        config(['bridge.config_dir' => $this->dir]);

        $r = (new GitHubTokenResolver)->resolveFor('o/r');

        $this->assertSame(TokenFileFault::Undetermined, $r->fileFault);
        $this->assertStringContainsString('writeback.json did not load', (string) $r->problem);
        $this->assertSame(TokenFileFault::Undetermined, GitHubTokenResolver::forUnreadWriteback('x')->resolveFor('o/r')->fileFault);
    }

    public function test_the_plain_constructor_reads_the_override_from_the_installs_writeback_json(): void
    {
        $write = $this->store->tokenFile('write-token', 'ghp_write');
        File::put($this->dir.'/writeback.json', (string) json_encode(['mappings' => ['o/r' => ['board_id' => 8, 'stages' => ['merged' => 52], 'write_token_path' => $write]]]));
        config(['bridge.config_dir' => $this->dir]);

        $this->assertSame('ghp_write', (new GitHubTokenResolver)->resolveFor('o/r')->token);
    }

    public function test_resolution_is_memoized_per_repo_and_per_caller_kind(): void
    {
        $this->writeFileToken('ghp_first');
        $resolver = $this->resolver();
        $this->assertSame('ghp_first', $resolver->resolveFor('o/r')->token);

        $this->writeFileToken('ghp_second');

        $this->assertSame('ghp_first', $resolver->resolveFor('o/r')->token);
        $this->assertSame('ghp_second', $resolver->resolveForCli('o/r')->token);
    }

    /** @param  array<string, ?int>  $owners  path => owner (null: unreadable); every other path its real owner */
    private function ownersAre(array $owners): void
    {
        $this->app->instance(ProcessIdentity::class, new class($owners) implements ProcessIdentity
        {
            /** @param  array<string, ?int>  $owners */
            public function __construct(private array $owners) {}

            public function euid(): ?int
            {
                return (new SystemProcessIdentity)->euid();
            }

            public function ownerOf(string $path): ?int
            {
                return array_key_exists($path, $this->owners) ? $this->owners[$path] : (new SystemProcessIdentity)->ownerOf($path);
            }

            public function accountName(int $uid): ?string
            {
                return null;
            }
        });
    }
}
