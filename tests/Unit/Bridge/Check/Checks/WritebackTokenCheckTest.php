<?php

namespace Tests\Unit\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Checks\WritebackTokenCheck;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use App\Bridge\Support\TokenPath;
use App\Bridge\Writeback\WritebackConfig;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The dedicated kanban writeback token leg (DL-242 stage 3a).
 *
 * THIS CHECK HAD NO TEST FILE until card#5698 adopted the not-visible guard here — the leg
 * was covered only through the golden corpus, which asserts the whole command's output and
 * so could not exercise an untraversable secret dir (a fixture cannot carry a 0000
 * directory through git). The absent/insecure/silent arms below are written alongside the
 * new one deliberately: without them the file would pin the arm I changed and leave the
 * three it sits between unguarded.
 */
class WritebackTokenCheckTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/wb-token-check-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/kanban');
    }

    protected function tearDown(): void
    {
        @chmod($this->dir.'/kanban', 0755);
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_a_genuinely_absent_token_warns_and_names_the_least_privilege_remedy(): void
    {
        $findings = $this->findings();

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Warn, $findings[0]->severity);
        $this->assertSame(
            'writeback: no kanban writeback token at '.$this->tokenPath().' — the move will fail until you place a least-privilege token (chmod 600)',
            $findings[0]->message,
        );
    }

    /**
     * The card#5698 arm. The token EXISTS and is correctly moded; the secret dir just
     * denies this process traversal, and `is_file()` reports that identically to absence.
     * Before the guard the operator was told to place a token they had already placed.
     */
    public function test_a_token_that_cannot_be_seen_is_unvalidated_not_reported_missing(): void
    {
        $this->skipAsRoot();
        File::put($this->tokenPath(), 'shhh');
        chmod($this->tokenPath(), 0o600);
        chmod($this->dir.'/kanban', 0000);

        try {
            $findings = $this->findings();
        } finally {
            chmod($this->dir.'/kanban', 0755);
        }

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Unvalidated, $findings[0]->severity);
        $this->assertStringContainsString('is not visible to this user', $findings[0]->message);
        $this->assertStringNotContainsString('no kanban writeback token', $findings[0]->message);
    }

    public function test_a_group_readable_token_warns_that_the_move_will_fail(): void
    {
        File::put($this->tokenPath(), 'shhh');
        chmod($this->tokenPath(), 0o644);

        $findings = $this->findings();

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Warn, $findings[0]->severity);
        $this->assertStringContainsString('is group/world-readable (mode 0644) — chmod 600', $findings[0]->message);
    }

    /**
     * The discriminating control: a healthy token is this check's silence, so the arms
     * above are verdicts rather than a check that always speaks.
     */
    public function test_a_correctly_moded_token_is_silent(): void
    {
        File::put($this->tokenPath(), 'shhh');
        chmod($this->tokenPath(), 0o600);

        $this->assertSame([], $this->findings());
    }

    /**
     * The three guards that mean the token is not yet a requirement — asserted against a
     * state that WOULD otherwise warn (no token exists in any of them), so the silence is
     * the guard and not an empty run.
     */
    public function test_the_not_yet_required_guards_are_silent_even_with_no_token(): void
    {
        $this->assertSame([], $this->findings(secretDir: null), 'no secret dir ⇒ no path to form');
        $this->assertSame([], $this->findings(writeback: false), 'no writeback config ⇒ writeback off');
        $this->assertSame([], $this->findings(mappings: []), 'no mappings ⇒ nothing to move');
    }

    /**
     * @return list<Finding>
     */
    private function findings(?string $secretDir = '', bool $writeback = true, ?array $mappings = null): array
    {
        $ctx = new CheckContext;
        $ctx->secretDir = $secretDir === '' ? $this->dir : $secretDir;
        $ctx->writeback = $writeback
            ? new WritebackConfig(7, $mappings ?? ['owner/repo' => 5])
            : null;

        return iterator_to_array((new WritebackTokenCheck)->run($ctx), false);
    }

    private function tokenPath(): string
    {
        return TokenPath::forWriteback($this->dir, 'kanban');
    }

    private function skipAsRoot(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $this->markTestSkipped('root bypasses directory permission checks');
        }
    }
}
