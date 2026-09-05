<?php

namespace Tests\Unit\Bridge\Check\Checks;

use App\Bridge\Check\Checks\StandupPostureCheck;
use App\Bridge\Standup\StandupGate;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\Support\MaterializesChecks;
use Tests\TestCase;

/**
 * The `standup.posture` preflight leg (card#8683 / DL-345).
 *
 * WHAT IS HERE IS WHAT THE GOLDEN SUITE CANNOT SEE — the same one criterion
 * `RetentionPostureCheckTest` applies (NAMED, not `{@see}`-linked: pint's docblock fixer
 * turns a fully-qualified `{@see}` into a real `use`, and an import minted by a comment is
 * one an unused-import gate can never retire). A golden capture is plain text, so it
 * witnesses a MESSAGE byte-for-byte and a SEVERITY nowhere; and it is captured against a
 * working install, so it reaches no fail-soft `catch`. Three fixtures
 * (`standup-enabled`, `standup-last-pass-failed`, `standup-misconfigured`) already pin every
 * operator-visible byte of this leg, plus its silence on the whole rest of the corpus —
 * re-asserting a message here would duplicate that suite rather than strengthen it.
 *
 * ⭐ THE ONE THING THIS CLASS EXISTS FOR ABOVE ALL. The card this leg closes is *"a marker
 * asserted to be an alarm, with no test that the alarm ever fires"*. So the alarm is
 * asserted in BOTH directions and on its CONTENT: an absence-only assertion certifies
 * whatever replaces it, and a presence-only one is satisfied by a leg that shouts on every
 * install.
 */
class StandupPostureCheckTest extends TestCase
{
    use MaterializesChecks;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        // The armed posture — the only route that reaches the marker read at all.
        config([
            'bridge.standup.enabled' => true,
            'bridge.standup.agent' => 'pm',
            'bridge.standup.interval' => 86400,
        ]);
    }

    /** @param  list<Finding>  $findings */
    private function messages(array $findings): string
    {
        return implode("\n", array_map(fn (Finding $f) => $f->message, $findings));
    }

    public function test_a_wedged_pass_is_surfaced_with_the_marker_s_own_detail(): void
    {
        Cache::put(StandupGate::ERROR_KEY, [
            'exception' => 'App\Bridge\Exceptions\HandlerException',
            'error' => 'channel_push: connection refused',
            'at' => '2026-01-01T00:00:00+00:00',
        ], 60);

        $findings = $this->findingsOf(new StandupPostureCheck);

        // The healthy posture line still comes FIRST: the config is fine, the delivery is
        // not, and collapsing the two would tell an operator to go looking at the wrong one.
        $this->assertSame(Severity::Ok, $findings[0]->severity);
        $this->assertSame(Severity::Warn, $findings[1]->severity);
        // ⛔ CONTENT, NOT MERELY PRESENCE. A line that named the subject and dropped the
        // stored detail would satisfy a "the leg spoke" assertion while telling the operator
        // nothing they can act on — and all three fields come from the marker the gate
        // actually wrote, so each is a witness that this leg READ it rather than composed a
        // sentence about it.
        $this->assertStringContainsString('standup: the LAST PASS FAILED', $findings[1]->message);
        $this->assertStringContainsString('App\Bridge\Exceptions\HandlerException', $findings[1]->message);
        $this->assertStringContainsString('channel_push: connection refused', $findings[1]->message);
        $this->assertStringContainsString('at 2026-01-01T00:00:00+00:00', $findings[1]->message);
    }

    public function test_an_armed_install_with_no_standing_fault_says_only_that_it_is_armed(): void
    {
        // THE INVERSE, and it is half the measurement rather than a formality: an alarm that
        // cannot be shown to stay quiet is not an alarm, it is a banner.
        $findings = $this->findingsOf(new StandupPostureCheck);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Ok, $findings[0]->severity);
        $this->assertStringContainsString('standup: on (push to pm, every 86400s', $findings[0]->message);
    }

    public function test_a_misconfigured_digest_warns_and_does_not_claim_to_be_armed(): void
    {
        // `warn`, never `fail`: this leg gates deployment runbooks through `bridge:check`'s
        // exit code, and an opt-in report with a fat-fingered recipient leaves the receiver
        // serving every webhook correctly. The severity is invisible to the golden capture,
        // so a demotion to `ok` — a green line confirming the posture the operator is being
        // warned about — would be caught only here.
        config(['bridge.standup.agent' => '']);

        $findings = $this->findingsOf(new StandupPostureCheck);

        $this->assertSame(Severity::Warn, $findings[0]->severity);
        $this->assertStringContainsString('MISCONFIGURED', $findings[0]->message);
    }

    public function test_the_marker_read_still_happens_on_a_misconfigured_install(): void
    {
        // The two faults are independent and have different remedies. Suppressing the second
        // behind the first would cost the operator a round trip — fix the recipient, re-run,
        // discover the digest has also been failing — which is the reason JobsPostureCheck's
        // misconfigured arm deliberately does not return either.
        config(['bridge.standup.agent' => '']);
        Cache::put(StandupGate::ERROR_KEY, ['exception' => 'X', 'error' => 'y', 'at' => 'then'], 60);

        $findings = $this->findingsOf(new StandupPostureCheck);

        $this->assertCount(2, $findings);
        $this->assertStringContainsString('MISCONFIGURED', $findings[0]->message);
        $this->assertStringContainsString('LAST PASS FAILED', $findings[1]->message);
    }

    public function test_a_disabled_digest_is_silent_even_with_a_standing_fault_marker(): void
    {
        // The default install, and the arm the whole corpus is the control for. A marker
        // standing under a digest the operator has since switched OFF states a fault about
        // work nobody wants; it expires on its own. Both siblings skip their marker read on
        // the disabled arm, and this mirrors them rather than deciding it afresh.
        config(['bridge.standup.enabled' => false]);
        Cache::put(StandupGate::ERROR_KEY, ['exception' => 'X', 'error' => 'y', 'at' => 'then'], 60);

        $this->assertSame([], $this->findingsOf(new StandupPostureCheck));
    }

    public function test_an_unreachable_cache_backend_is_reported_rather_than_aborting_the_check(): void
    {
        // Unreachable by the golden suite by construction — a fixture is captured against a
        // working install. `CheckRunner` deliberately does not isolate, so without this arm
        // a dead cache store would abort the whole of `bridge:check` at this leg; and it is
        // `unvalidated` rather than `warn` because a read that never happened is not evidence
        // the last pass succeeded, which is this card's own defect one level up.
        Cache::swap(new Repository(new class extends ArrayStore
        {
            public function get($key)
            {
                throw new RuntimeException('no connection to localhost:6379');
            }
        }));

        $findings = $this->findingsOf(new StandupPostureCheck);

        // The witness that the throw was reached through the HEALTHY path and the check ran
        // to completion: without it, a leg that aborted on its first line would satisfy the
        // assertion below just as well.
        $this->assertSame(Severity::Ok, $findings[0]->severity);
        $this->assertSame(Severity::Unvalidated, $findings[1]->severity);
        $this->assertStringContainsString(
            'standup: could not read the last-failure marker (no connection to localhost:6379)',
            $this->messages($findings),
        );
    }
}
