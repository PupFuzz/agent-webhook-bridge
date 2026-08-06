<?php

namespace Tests\Unit\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Checks\SharedIdentitiesCheck;
use App\Bridge\Support\AgentRegistry;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use Illuminate\Support\Facades\File;
use Tests\Support\MaterializesChecks;
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
    use MaterializesChecks;

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
     * The shape the golden fixture actually writes, and it is a CLEAN parse rather than a
     * degraded one: the bytes are a valid JSON object that simply carries no
     * `shared_identities` key, so the read succeeds silently — nothing is logged and no
     * entry is skipped — and the zero is a real answer. Reporting it at all is the point:
     * silence here would read as "no such file".
     */
    public function test_a_file_that_parses_to_nothing_is_still_reported_at_zero(): void
    {
        $this->writeSharedIdentities(['shared-account' => ['kanban_user_id' => 137]]);

        $findings = $this->findings();

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Ok, $findings[0]->severity);
        $this->assertSame('shared-identities.json: 0 shared account(s)', $findings[0]->message);
    }

    /**
     * DL-259 (card#5698): the three states `loadSharedIdentities()` collapses onto `[]`
     * are not one state, and `readSharedIdentities()` is what keeps them apart for this
     * check to pronounce on.
     * Malformed JSON is MEASURED — the bytes were read and they are not an
     * object — and it is a real fault: the loader ignores the file, so every agent sharing
     * an account loses attribution silently. It used to render GREEN at `0 shared
     * account(s)`, identical to a file that genuinely declares none.
     */
    public function test_a_file_that_is_not_a_json_object_warns_instead_of_certifying_zero(): void
    {
        File::put($this->dir.'/shared-identities.json', 'not json at all {');

        $findings = $this->findings();

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Warn, $findings[0]->severity);
        $this->assertStringContainsString('is not a valid JSON object', $findings[0]->message);
        // The count must not appear: nothing was counted.
        $this->assertStringNotContainsString('shared account(s)', $findings[0]->message);
    }

    /**
     * DL-259: the could-not-READ state. Distinct from the malformed one above — nothing
     * was measured at all, so this is `unvalidated` and not a verdict about the file's
     * contents. A green "0 shared account(s)" here was the class's exact shape: a definite
     * fact asserted on evidence that cannot tell absence from this run's own blindness.
     */
    public function test_a_present_but_unreadable_file_is_unvalidated_not_a_green_zero(): void
    {
        $path = $this->dir.'/shared-identities.json';
        File::put($path, (string) json_encode(['shared_identities' => []]));
        chmod($path, 0o000);
        if (@file_get_contents($path) !== false) {
            $this->markTestSkipped('this process can read a 0000 file (running as root) — the unreadable state is unreachable here');
        }

        $findings = $this->findings();

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Unvalidated, $findings[0]->severity);
        $this->assertStringContainsString('could NOT be read', $findings[0]->message);
        $this->assertStringNotContainsString('shared account(s)', $findings[0]->message);
    }

    /** The file is optional; absence is the common install and must stay silent. */
    public function test_it_is_silent_when_the_file_is_absent(): void
    {
        $this->assertSame([], $this->findings());
    }

    /**
     * A context the derivation never populated — the state a hand-built context is in, and
     * the one an install with no config dir produces, since no path can be formed to read.
     * The check must treat the null as nothing to report rather than dereference it: it
     * asks the context nothing else, so this is the ONLY input that can reach it that way.
     */
    public function test_it_is_silent_when_the_derivation_never_ran(): void
    {
        $ctx = new CheckContext;

        $this->assertSame([], $this->findingsOf((new SharedIdentitiesCheck), $ctx));
    }

    /** @param array<string, mixed> $payload */
    private function writeSharedIdentities(array $payload): void
    {
        File::put($this->dir.'/shared-identities.json', (string) json_encode($payload));
    }

    /**
     * The context PRODUCTION hands this check: the derivation performs the one read and
     * publishes its state, and the check reads nothing itself (card#5546). Building it
     * any other way here would test a composition the command never performs.
     *
     * @return list<Finding>
     */
    private function findings(): array
    {
        $ctx = new CheckContext;
        $ctx->configDir = $this->dir;
        $ctx->sharedIdentities = AgentRegistry::readSharedIdentities($this->dir);

        return $this->findingsOf((new SharedIdentitiesCheck), $ctx);
    }
}
