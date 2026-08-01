<?php

namespace Tests\Unit\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Checks\AgentWebhookSecretCheck;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\Finding;
use App\Bridge\Support\SecretPath;
use App\Bridge\Support\Severity;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The per-subscription webhook-secret legs (DL-242 stage 5b).
 *
 * THE GOLDEN CORPUS CANNOT SEE THE INSECURE-PERMS ARM. Fixtures reach the MISSING arm
 * (`agent-missing-secret-and-token`), but no fixture writes a group/world-readable secret,
 * so flipping the perms predicate changes no golden file. Mutating that arm reds this file
 * and nothing else in the suite — measured by mutation, which is the only instrument that
 * answers a whole-suite question (CLAUDE_TESTING.md). The blind spot is not an oversight in
 * the fixture set: a fixture COULD close it, but adding one mid-migration moves the
 * measured baseline, which is the thing the stage-5 split exists to avoid.
 *
 * THE SILENT PATHS ARE ASSERTED AGAINST A STATE THAT WOULD OTHERWISE SPEAK, never by
 * emptiness alone — a check that returned at its first line satisfies a bare
 * assertEmpty, and both of this check's silences (a healthy secret, an unset secret dir)
 * are contracts rather than absences.
 */
class AgentWebhookSecretCheckTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/webhook-secret-check-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/kanban');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_a_missing_secret_names_the_path_and_the_command_that_writes_it(): void
    {
        $findings = $this->findingsFor([['provider' => 'kanban', 'scopes' => [5]]]);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Warn, $findings[0]->severity);
        $this->assertSame(
            "agent prod-agent: kanban:5 has no secret at {$this->dir}/kanban/webhook-secret-scope-5 — run bridge:provision",
            $findings[0]->message,
        );
    }

    /**
     * The arm no fixture reaches. The mode is asserted verbatim rather than as "is insecure": the
     * operator's next action is `chmod 600`, and a message that lost the mode would still
     * pass a laxer assertion while telling them less than the golden harness would have.
     */
    public function test_a_group_readable_secret_warns_that_the_receiver_will_500(): void
    {
        $this->secret('kanban', '5', 0o644);

        $findings = $this->findingsFor([['provider' => 'kanban', 'scopes' => [5]]]);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Warn, $findings[0]->severity);
        $this->assertSame(
            "agent prod-agent: secret file at {$this->dir}/kanban/webhook-secret-scope-5 is group/world-readable (mode 0644) — chmod 600 — the receiver will 500 (secret_perms_insecure) until fixed",
            $findings[0]->message,
        );
    }

    /**
     * The discriminating control for BOTH arms above: without it, a check hard-wired to
     * warn passes them. Silence is this check's healthy verdict — the provisioned install
     * prints nothing here — so it is the contract, not an absence of one.
     */
    public function test_a_provisioned_secret_is_silent(): void
    {
        $this->secret('kanban', '5', 0o600);

        $this->assertSame([], $this->findingsFor([['provider' => 'kanban', 'scopes' => [5]]]));
    }

    /**
     * Per-SUBSCRIPTION, not per-agent: a check that yielded only its first finding passes
     * every single-subscription test above. The two arms are paired deliberately so the
     * order is asserted too — output order is the byte-identical contract stages 0-7 hold.
     */
    public function test_every_subscription_is_reported_in_config_order(): void
    {
        $this->secret('kanban', '5', 0o644);

        $findings = $this->findingsFor([['provider' => 'kanban', 'scopes' => [5, 7]]]);

        $this->assertCount(2, $findings);
        $this->assertStringContainsString('secret file at', $findings[0]->message);
        $this->assertStringContainsString('kanban:7 has no secret at', $findings[1]->message);
    }

    /**
     * The early return, asserted against the state that would otherwise produce the
     * missing-secret warn above — with no absolute secret dir there is no path to check
     * at all, so the check declines rather than reporting a path it invented.
     */
    public function test_an_unset_secret_dir_yields_nothing_even_where_a_secret_is_missing(): void
    {
        $this->assertSame([], $this->findingsFor([['provider' => 'kanban', 'scopes' => [5]]], withSecretDir: false));
    }

    /**
     * The card#5698 arm: a secret that EXISTS but sits under a directory this process
     * cannot traverse. `is_file()` returns false exactly as for a missing one, so before
     * the guard this printed "has no secret … run bridge:provision" — a definite
     * accusation, and the wrong remediation, for a correctly-provisioned install.
     */
    public function test_a_secret_that_cannot_be_seen_is_unvalidated_not_reported_missing(): void
    {
        $this->skipAsRoot();
        $this->secret('kanban', '5', 0o600);
        chmod($this->dir.'/kanban', 0000);

        try {
            $findings = $this->findingsFor([['provider' => 'kanban', 'scopes' => [5]]]);
        } finally {
            chmod($this->dir.'/kanban', 0755);
        }

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Unvalidated, $findings[0]->severity);
        $this->assertStringContainsString('is not visible to this user', $findings[0]->message);
        $this->assertStringNotContainsString('has no secret', $findings[0]->message);
        $this->assertStringNotContainsString('run bridge:provision', $findings[0]->message);
    }

    private function skipAsRoot(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $this->markTestSkipped('root bypasses directory permission checks');
        }
    }

    private function secret(string $provider, string $scope, int $mode): void
    {
        $path = SecretPath::for($this->dir, $provider, $scope);
        File::put($path, 'shhh');
        chmod($path, $mode);
    }

    /**
     * @param  list<array{provider: string, scopes: list<int|string>}>  $subscriptions
     * @return list<Finding>
     */
    private function findingsFor(array $subscriptions, bool $withSecretDir = true): array
    {
        $config = AgentConfig::fromArray('prod-agent', [
            'identity' => ['kanban_user_id' => 1],
            'subscriptions' => $subscriptions,
        ]);

        $ctx = new CheckContext;
        $ctx->secretDir = $withSecretDir ? $this->dir : null;

        return iterator_to_array((new AgentWebhookSecretCheck)->runFor($config, $ctx), false);
    }
}
