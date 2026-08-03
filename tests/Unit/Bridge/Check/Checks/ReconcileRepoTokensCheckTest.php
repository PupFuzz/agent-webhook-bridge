<?php

namespace Tests\Unit\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Checks\ReconcileRepoTokensCheck;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use App\Bridge\Writeback\WritebackConfig;
use App\Bridge\Writeback\WritebackMapping;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\Support\MaterializesChecks;
use Tests\TestCase;

/**
 * The `Http` and `Network` arms of this check's probe switch, plus the one arm that is
 * deliberately SILENT — none of which the golden suite can reach.
 *
 * The `Unresolvable` arm IS golden-measured (two writeback fixtures land on it), so it is
 * absent here on purpose; duplicating it would not strengthen that measurement. The
 * remaining three arms all need a live-looking token AND a controlled HTTP outcome, which
 * a fixture captured against a real host cannot have.
 *
 * ⚠ A `switch` IS INVISIBLE TO THE COVERAGE MEASUREMENT, not merely unobserved by it:
 * `bin/check-golden-mutate.php` enumerates `if`/`elseif`/`foreach` only, so these arms
 * are absent from `docs/check-golden-coverage.md`'s disclosed-gap list ENTIRELY rather
 * than listed as UNOBSERVED. Absence from that list is therefore not protection for a
 * `switch`, and this test is the only thing standing behind these four arms.
 *
 * A SILENT ARM IS ASSERTED WITH A SENT-REQUEST WITNESS, never by emptiness alone: the
 * probe demonstrably ran and chose to say nothing, which is the actual contract for a
 * token that probed clean. Emptiness on its own would be equally satisfied by a check
 * that returned at its first line — and that indistinguishability is exactly what made
 * the `Network` arm's shared silence a defect (DL-251): only ONE arm is entitled to it.
 */
class ReconcileRepoTokensCheckTest extends TestCase
{
    use MaterializesChecks;

    private const REPO = 'owner/repo';

    private string $dir;

    private string|false $origGhToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/reconcile-tokens-check-'.uniqid();
        File::ensureDirectoryExists($this->dir);
        config([
            // No conventional token file and no store helper on this host, so the token
            // resolves deterministically from GH_TOKEN (source label 'GH_TOKEN').
            'bridge.secret_dir' => $this->dir,
            'bridge.providers.github.token_path' => null,
            'bridge.providers.github.credential_helper' => $this->dir.'/no-store-helper',
        ]);

        $this->origGhToken = getenv('GH_TOKEN');
        putenv('GH_TOKEN=ghp_reconcile_probe');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        $this->origGhToken === false ? putenv('GH_TOKEN') : putenv('GH_TOKEN='.$this->origGhToken);
        parent::tearDown();
    }

    public function test_a_401_names_the_shadowing_token_file_as_the_common_upgrade_cause(): void
    {
        Http::fake(['https://api.github.com/*' => Http::response([], 401)]);

        $findings = $this->findings();

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Warn, $findings[0]['severity']);
        $this->assertStringContainsString(
            'reconcile: '.self::REPO.': token from GH_TOKEN → HTTP 401 (token expired/revoked)',
            $findings[0]['message'],
        );
        $this->assertStringContainsString('bridge:reconcile will SKIP this repo', $findings[0]['message']);
        $this->assertStringContainsString('it SHADOWS the [git-credential-map] store', $findings[0]['message']);
    }

    /**
     * The 403/404 hint comes from the shared `GitHubRepoProbe::hintFor` table, which is
     * the whole point of the shared probe: bridge:check and bridge:reconcile cannot
     * diagnose one status differently.
     */
    public function test_a_403_carries_the_shared_private_repo_hint(): void
    {
        Http::fake(['https://api.github.com/*' => Http::response([], 403)]);

        $findings = $this->findings();

        $this->assertCount(1, $findings);
        $this->assertStringContainsString(
            'HTTP 403 (token lacks access to this private repo — needs `repo` scope)',
            $findings[0]['message'],
        );
    }

    public function test_a_working_token_reports_nothing(): void
    {
        Http::fake(['https://api.github.com/*' => Http::response(['full_name' => self::REPO])]);

        $this->assertSame([], $this->findings());

        // The witness: the probe ran and chose silence, rather than never running.
        Http::assertSentCount(1);
    }

    /**
     * DL-251: this arm shared `Ok`'s `break` until stage 10's follow-up, so an unreachable
     * GitHub and a token proven good produced the same output — nothing. It is still not
     * a token-validity signal, and the message says so; what changed is that "we never
     * found out" is now reported instead of being indistinguishable from "it is fine".
     */
    public function test_an_unreachable_github_reports_unvalidated_and_never_accuses_the_token(): void
    {
        // A THROWING fake is never recorded, so `assertSentCount` cannot witness this arm
        // (it reads 0 whether the probe ran or not). The handler's own side effect can.
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $findings = $this->findings();

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Unvalidated, $findings[0]['severity']);
        $this->assertStringContainsString('reconcile: '.self::REPO.': could NOT reach GitHub', $findings[0]['message']);
        // The three properties the message owes, asserted separately because a single
        // substring would green on a rewording that dropped any one of them: it names the
        // repo (above), it refuses to convict the token, and it names the operator action.
        $this->assertStringContainsString('was NOT validated for this repo', $findings[0]['message']);
        $this->assertStringContainsString('not evidence the token is bad', $findings[0]['message']);
        $this->assertStringContainsString('Re-run bridge:check once connectivity', $findings[0]['message']);

        $this->assertSame(1, $attempts, 'the probe must have run and reported the blip, not been skipped');
    }

    /**
     * The guard, not an arm: with no validated secret dir the token-path legs cannot form
     * a path at all, so the check must short-circuit BEFORE probing — a probe here would
     * spend a network round-trip per mapping to reach a conclusion it cannot act on.
     */
    public function test_no_secret_dir_short_circuits_before_any_probe(): void
    {
        Http::fake();

        $this->assertSame([], $this->findings(secretDir: null));

        Http::assertNothingSent();
    }

    /** @return list<array{severity: Severity, message: string}> */
    private function findings(?string $secretDir = 'set'): array
    {
        $ctx = new CheckContext;
        $ctx->secretDir = $secretDir === null ? null : $this->dir;
        $ctx->writeback = new WritebackConfig(7, [
            self::REPO => new WritebackMapping(boardId: 8, stages: []),
        ]);

        return array_map(
            fn (Finding $f) => ['severity' => $f->severity, 'message' => $f->message],
            $this->findingsOf((new ReconcileRepoTokensCheck), $ctx),
        );
    }
}
