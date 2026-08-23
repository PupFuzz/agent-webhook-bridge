<?php

namespace Tests\Unit\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Checks\WritebackIdentityCheck;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use App\Bridge\Writeback\WritebackConfig;
use App\Bridge\Writeback\WritebackMapping;
use Tests\Support\MaterializesChecks;
use Tests\TestCase;

/**
 * `writeback.identity` — the three states of the leg that reports WHICH kanban user the
 * writeback writes as (card#7348 / roundtable #343).
 *
 * The absent-identity warn was already measured byte-for-byte by the golden suite
 * (`CheckGoldenTest` — named, not `{@see}`-linked: pint's docblock fixer turns a
 * fully-qualified `{@see}` into a real import). What had NO test of any kind was the
 * healthy path, because until DL-305 it printed nothing — so the change from *silent* to
 * *reports the user* is exactly the kind of edit a golden regeneration can absorb without
 * anything asserting what the new line must SAY. These three cases are that assertion.
 */
class WritebackIdentityCheckTest extends TestCase
{
    use MaterializesChecks;

    public function test_a_set_identity_reports_the_user_the_writeback_writes_as(): void
    {
        $ctx = new CheckContext;
        $ctx->writeback = new WritebackConfig(4242, ['owner/repo' => new WritebackMapping(8, ['merged' => 52])]);

        $findings = $this->findingsOf(new WritebackIdentityCheck, $ctx);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Ok, $findings[0]->severity);
        $message = $findings[0]->message;
        // The id itself — the fact the operator cannot get anywhere else at setup time.
        $this->assertStringContainsString('kanban user 4242', $message);
        // WHY it matters: it is what a moved card records, and the board's only writer
        // attribution.
        $this->assertStringContainsString('last_stage_move.actor_id', $message);
        // The instruction, which is the half roundtable #343 asked for by name.
        $this->assertStringContainsString('OWN kanban user', $message);
        // ⛔ AND THE BOUND, asserted so it cannot be dropped in an edit that shortens the
        // line: this leg REPORTS, it does not certify. A board CLI's token on the same host
        // is invisible from here — that is the exact shape that was measured colliding —
        // so a green run must never read as "separation verified".
        $this->assertStringContainsString('does not certify separation', $message);
    }

    public function test_an_absent_identity_still_warns_about_the_echo_loop(): void
    {
        // The pre-existing leg, unchanged: no identity_id means the writeback's own
        // card_updated webhook is not echo-suppressed. It is a WARN and must not have been
        // softened into the new report.
        $ctx = new CheckContext;
        $ctx->writeback = new WritebackConfig(null, ['owner/repo' => new WritebackMapping(8, ['merged' => 52])]);

        $findings = $this->findingsOf(new WritebackIdentityCheck, $ctx);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Warn, $findings[0]->severity);
        $this->assertStringContainsString('no identity_id', $findings[0]->message);
        // The two arms are exclusive: an install with nothing set gets the actionable warn,
        // never a report of a user it does not have.
        $this->assertStringNotContainsString('writes to the board as kanban user', $findings[0]->message);
    }

    public function test_no_writeback_config_reports_nothing(): void
    {
        // Not an install this leg has anything to say about — and the silence is DECLARED,
        // so the runner records it rather than inferring it from an empty yield.
        $this->assertSame([], array_map(
            fn (Finding $f) => $f->message,
            $this->findingsOf(new WritebackIdentityCheck, new CheckContext),
        ));
    }
}
