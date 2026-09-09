<?php

namespace Tests\Unit\Bridge\Scheduling;

use App\Bridge\Scheduling\TickPosture;
use App\Bridge\Scheduling\TickState;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A `stale` tick's REMEDIATION must name a channel that has something in it (card#9058 / DL-361).
 *
 * ⛔ IT USED TO SEND THE READER TO CRON'S MAIL. The verdict ended *"check the crontab line and its
 * account's mail"* — but the line this repo offers, and the one its own owner doc carries, ends
 * `2>&1` into a log file, so cron mails NOTHING, ever. A `stale` verdict buys exactly one action
 * from the operator who reads it, and half of that instruction spent it on a guaranteed-empty
 * inbox. The line's redirect semantics are now owned by this repo (DL-361 Decisions 4-5), so the
 * remediation that assumes the opposite is this repo's defect to fix.
 */
class TickPostureStaleRemediationTest extends TestCase
{
    private function stale(): TickPosture
    {
        $posture = TickPosture::resolve(Carbon::now()->subSeconds(9_999), 600);

        // The premise of every assertion below: this really is the stale arm.
        $this->assertSame(TickState::Stale, $posture->state);

        return $posture;
    }

    public function test_the_stale_verdict_does_not_send_the_reader_to_an_inbox_the_offered_line_keeps_empty(): void
    {
        $summary = $this->stale()->summary();

        $this->assertStringNotContainsString('mail', $summary);
        $this->assertStringContainsString('bridge:jobs', $summary);
        $this->assertStringContainsString('tick.log', $summary);
    }

    public function test_the_stale_verdict_still_prints_the_grace_it_derived(): void
    {
        // The control on the test above: it asserts about the STALE string, so something must
        // pin that the string is still the one the verdict derives rather than any text at all.
        $summary = $this->stale()->summary();

        $this->assertStringContainsString('STALE', $summary);
        $this->assertStringContainsString((string) TickPosture::graceS(600), $summary);
        $this->assertStringContainsString((string) (600 + TickPosture::graceS(600)), $summary);
    }
}
