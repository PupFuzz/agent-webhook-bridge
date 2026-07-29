<?php

namespace Tests\Unit\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Checks\SharedIdentitiesCheck;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The optional `shared-identities.json` report (DL-242 stage 5c).
 *
 * The golden fixture `shared-identities-present` pins the file-present branch — BUT ONLY
 * AT ZERO. That fixture writes the object WITHOUT the `shared_identities` wrapper key, so
 * it parses to an empty list and the line it pins reads `0 shared account(s)`. Nothing in
 * the fixture set renders a non-zero count, which is the same distinction the plan's
 * stage-4 result draws: a predicate being `observed` says its branches are
 * distinguishable, never that the branch's MESSAGE was asserted.
 *
 * SO THE COUNT IS WHAT THIS FILE MEASURES. A check that hard-coded `0`, or lost the
 * `count()`, would pass the entire golden suite.
 *
 * (Golden fixtures are NAMED, never `{@see}`-linked: pint's docblock fixer turns a
 * fully-qualified `{@see}` into a real `use`.)
 */
class SharedIdentitiesCheckTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/shared-ids-'.uniqid();
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    /** The case no golden fixture reaches. */
    public function test_it_reports_the_number_of_shared_accounts(): void
    {
        // A numeric `github_user_id` is what makes an entry a shared identity at all —
        // an entry without one is logged and skipped, so it would count zero.
        $this->writeSharedIdentities(['shared_identities' => [
            ['github_user_id' => 42, 'github_login' => 'shared-bot', 'agents' => ['alpha', 'beta']],
            ['github_user_id' => 77, 'github_login' => 'other-bot', 'agents' => ['gamma']],
        ]]);

        $findings = $this->findings();

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Ok, $findings[0]->severity);
        $this->assertSame('shared-identities.json: 2 shared account(s)', $findings[0]->message);
    }

    /**
     * The shape the golden fixture actually writes. The read is fail-soft — it logs and
     * returns an empty list — so a present-but-wrongly-shaped file reports zero rather
     * than failing the run, and reporting it at all is the point: silence here reads as
     * "no such file".
     */
    public function test_a_file_that_parses_to_nothing_is_still_reported_at_zero(): void
    {
        $this->writeSharedIdentities(['shared-account' => ['kanban_user_id' => 137]]);

        $findings = $this->findings();

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Ok, $findings[0]->severity);
        $this->assertSame('shared-identities.json: 0 shared account(s)', $findings[0]->message);
    }

    /** The file is optional; absence is the common install and must stay silent. */
    public function test_it_is_silent_when_the_file_is_absent(): void
    {
        $this->assertSame([], $this->findings());
    }

    /** No config dir means no path to test — the leg cannot run, and must not throw. */
    public function test_it_is_silent_when_there_is_no_config_dir(): void
    {
        $ctx = new CheckContext;

        $this->assertSame([], iterator_to_array((new SharedIdentitiesCheck)->run($ctx), false));
    }

    /** @param array<string, mixed> $payload */
    private function writeSharedIdentities(array $payload): void
    {
        File::put($this->dir.'/shared-identities.json', (string) json_encode($payload));
    }

    /** @return list<Finding> */
    private function findings(): array
    {
        $ctx = new CheckContext;
        $ctx->configDir = $this->dir;

        return iterator_to_array((new SharedIdentitiesCheck)->run($ctx), false);
    }
}
