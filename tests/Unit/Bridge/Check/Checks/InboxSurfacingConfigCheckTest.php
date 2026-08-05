<?php

namespace Tests\Unit\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Checks\InboxSurfacingConfigCheck;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use Tests\Support\MaterializesChecks;
use Tests\TestCase;

/**
 * The inbox-surfacing config leg (DL-242 stage 6).
 *
 * WHAT THIS FILE ADDS IS THE COMPOSED LINE, not the refusals. `BridgePaths` owns whether
 * a layout or a group setting is legal, and `BridgeCommandsTest` already drives
 * `bridge:check` into both refusals — but it asserts a SUBSTRING of each thrown message
 * plus the exit code, which a check that dropped its own `inbox surfacing config: ` prefix
 * would satisfy unchanged. The prefix is the operator's only clue which leg failed, and
 * the byte-identical output contract this migration runs under is exactly a contract about
 * that text.
 *
 * THE OK LINE'S LAYOUT IS INTERPOLATED, and every golden fixture runs the same layout, so
 * the corpus reads identically against a check that hardcoded `shared`. The two ok cases
 * below differ only in the configured layout, which is what makes the interpolation
 * measured rather than assumed.
 */
class InboxSurfacingConfigCheckTest extends TestCase
{
    use MaterializesChecks;

    public function test_the_ok_line_carries_the_configured_layout(): void
    {
        config(['bridge.inbox_layout' => 'shared']);

        $findings = $this->findings();

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Ok, $findings[0]->severity);
        $this->assertSame('inbox surfacing config: ok (layout=shared)', $findings[0]->message);
    }

    /** The discriminating half of the pair: a different layout must move the rendered line. */
    public function test_a_non_default_layout_appears_in_the_ok_line(): void
    {
        config(['bridge.inbox_layout' => 'per-agent']);

        $findings = $this->findings();

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Ok, $findings[0]->severity);
        $this->assertSame('inbox surfacing config: ok (layout=per-agent)', $findings[0]->message);
    }

    public function test_an_invalid_layout_is_reported_under_this_checks_own_prefix(): void
    {
        config(['bridge.inbox_layout' => 'bogus']);

        $findings = $this->findings();

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertSame(
            "inbox surfacing config: BRIDGE_INBOX_LAYOUT 'bogus' is invalid — use one of: shared, per-agent, both",
            $findings[0]->message,
        );
    }

    /**
     * The second refusal, which reaches this check from `validateInboxConfig()` rather
     * than from `inboxLayout()` — a valid layout, rejected for the combination. Asserting
     * both proves the check reports whatever the validator threw instead of restating one
     * rule of its own.
     */
    public function test_a_cross_user_inbox_group_under_a_shared_layout_is_reported_under_the_same_prefix(): void
    {
        config(['bridge.inbox_layout' => 'both', 'bridge.inbox_group' => 'agent-bridge']);

        $findings = $this->findings();

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertStringStartsWith(
            "inbox surfacing config: BRIDGE_INBOX_GROUP is set (cross-user read) but BRIDGE_INBOX_LAYOUT is 'both'.",
            $findings[0]->message,
        );
        $this->assertStringContainsString('Set BRIDGE_INBOX_LAYOUT=per-agent.', $findings[0]->message);
    }

    /** @return list<Finding> */
    private function findings(): array
    {
        return $this->findingsOf((new InboxSurfacingConfigCheck), new CheckContext);
    }
}
