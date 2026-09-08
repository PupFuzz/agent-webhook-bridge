<?php

namespace Tests\Unit\Bridge\Scheduling;

use App\Bridge\Scheduling\TickAdoptionNotice;
use App\Bridge\Scheduling\TickPosture;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The install-time TICK OFFER, arm by arm (card#9058 / DL-361).
 *
 * ⭐ THE RENDERER IS PURE, SO EVERY ARM IS DRIVEN HERE RATHER THAN AROUND A REAL INSTALL. The
 * posture, the base path and the interpreter are all constructor arguments, so the cases that
 * matter most — an unnameable interpreter, a base path that cannot be pasted — are exercised
 * directly instead of being reasoned about.
 *
 * ⛔ THE SELF-LIMITING BEHAVIOUR IS PINNED IN BOTH DIRECTIONS, because only one of them is a
 * feature: an offer that appears is easy, an offer that STOPS appearing once the install has
 * answered is the property that makes it safe to hang off a per-agent command.
 */
class TickAdoptionNoticeTest extends TestCase
{
    private const BASE = '/srv/bridge';

    private const PHP = '/usr/bin/php8.5';

    private function notice(?int $ageS, ?int $declared, string $base = self::BASE, string $php = self::PHP): TickAdoptionNotice
    {
        return new TickAdoptionNotice(
            posture: TickPosture::resolve($ageS === null ? null : Carbon::now()->subSeconds($ageS), $declared),
            basePath: $base,
            phpBinary: $php,
        );
    }

    // ─── the self-limiting behaviour, both directions ─────────────────────────

    public function test_an_install_that_has_not_adopted_a_tick_is_offered_the_line(): void
    {
        $lines = $this->notice(ageS: null, declared: null)->lines();

        $this->assertNotSame([], $lines);
        $this->assertStringContainsString('this bridge INSTALL runs no periodic tick', implode("\n", $lines));
        $this->assertContains(
            '0,10,20,30,40,50 * * * * cd /srv/bridge && /usr/bin/php8.5 /srv/bridge/artisan bridge:tick'
                .' > /srv/bridge/storage/logs/tick.log 2>&1',
            $lines,
            'the crontab line is offered flush-left, as one whole pasteable line',
        );
    }

    public function test_an_install_that_declared_a_horizon_is_offered_nothing_at_all(): void
    {
        // ⭐ THE DIRECTION THAT MATTERS. A declared horizon IS adoption, and from there
        // `bridge:check`'s jobs.posture leg owns the subject. An offer that came back for every
        // agent onboarded afterwards would be a worse defect than the silence it replaces.
        // ⚑ Both DECLARED shapes are asserted, because they resolve to different TickStates and
        // only the `adopted` flag is common to them: a fresh tick, and one never observed at all.
        $this->assertSame([], $this->notice(ageS: 42, declared: 600)->lines(), 'declared and ticking');
        $this->assertSame([], $this->notice(ageS: null, declared: 600)->lines(), 'declared, never observed');
        $this->assertSame([], $this->notice(ageS: 99_999, declared: 600)->lines(), 'declared and stale');
    }

    public function test_an_install_already_ticking_is_asked_to_declare_and_offered_no_second_line(): void
    {
        // A recorded tick with no declaration means the crontab line EXISTS. Handing this
        // install a line would be handing it a duplicate — the exact per-agent defect the
        // notice's whole scope exists to avoid, arriving by the other route.
        $lines = $this->notice(ageS: 42, declared: null)->lines();
        $text = implode("\n", $lines);

        $this->assertStringContainsString('already running on this bridge INSTALL (last tick recorded 42s ago)', $text);
        $this->assertStringContainsString('do NOT add a crontab line', $text);
        $this->assertStringNotContainsString('0,10,20,30,40,50', $text);
        $this->assertStringContainsString("BRIDGE_JOBS_TICK_EXPECTED_EVERY=<seconds between that line's runs>", $text);
        // ⛔ It must not guess the horizon. Only the operator knows what their line runs at,
        // and a declared 600 against a line that runs hourly arms the alarm against a fiction.
        $this->assertStringNotContainsString('BRIDGE_JOBS_TICK_EXPECTED_EVERY=600', $text);
    }

    // ─── the scope statement ──────────────────────────────────────────────────

    public function test_the_offer_states_its_own_per_install_scope(): void
    {
        $text = implode("\n", $this->notice(ageS: null, declared: null)->lines());

        $this->assertStringContainsString('ONE LINE PER INSTALL, NEVER PER AGENT', $text);
        $this->assertStringContainsString('onboarding another agent here needs no second line', $text);
    }

    // ─── the two folded defects ───────────────────────────────────────────────

    public function test_the_declared_horizon_is_derived_from_the_schedule_rather_than_typed_beside_it(): void
    {
        // ⭐ ONE FIGURE, TWO OUTPUTS. A hand-typed `600` beside a hand-typed `0,10,20,…` is two
        // numbers free to disagree, and the disagreement arms the freshness alarm against an
        // interval the offered line does not run at — a false `stale`, or a dead line reading
        // fresh. Both come off EVERY_MINUTES, so this asserts they cannot part company.
        $minuteFields = explode(',', explode(' ', TickAdoptionNotice::schedule())[0]);

        $this->assertGreaterThan(1, count($minuteFields));
        $this->assertSame(
            (float) ((60 / count($minuteFields)) * 60),
            (float) TickAdoptionNotice::horizonS(),
        );
        $this->assertStringContainsString(
            'BRIDGE_JOBS_TICK_EXPECTED_EVERY='.TickAdoptionNotice::horizonS(),
            implode("\n", $this->notice(ageS: null, declared: null)->lines()),
        );
    }

    public function test_the_offered_line_truncates_its_log_rather_than_appending_to_it(): void
    {
        // ⛔ CONTROL: change `>` back to `>>` in crontabLine() and this reds. The mutation is
        // the live defect — six lines an hour, forever, with no logrotate stanza anywhere in
        // this repo and nothing that tails the file.
        $line = (string) $this->notice(ageS: null, declared: null)->crontabLine();

        $this->assertStringContainsString('> /srv/bridge/storage/logs/tick.log 2>&1', $line);
        $this->assertStringNotContainsString('>> ', $line);
    }

    public function test_the_offered_line_names_the_interpreter_absolutely(): void
    {
        // ⛔ CONTROL: render the interpreter as a bare `php` and this reds. That is the live
        // defect the card names — cron's PATH is minimal, and a line that cannot find its
        // interpreter fails in exactly the silent way this subsystem exists to report.
        $line = (string) $this->notice(ageS: null, declared: null)->crontabLine();

        $this->assertStringContainsString(' && /usr/bin/php8.5 /srv/bridge/artisan bridge:tick', $line);
        $this->assertStringNotContainsString(' php artisan', $line);
    }

    public function test_an_interpreter_this_process_cannot_name_falls_back_and_flags_the_assumption(): void
    {
        // The honest degradation: a bare `php` is the status quo, and it is only acceptable
        // while the line beside it says so. ⛔ The absolute-interpreter sentence must NOT print
        // here — a claim contradicting the very line above it is worse than no claim, because a
        // reader who trusts the sentence never re-reads the line.
        foreach (['' => 'empty', 'php' => 'relative', '/usr/local/my php' => 'unpasteable'] as $binary => $why) {
            $notice = $this->notice(ageS: null, declared: null, php: (string) $binary);
            $text = implode("\n", $notice->lines());

            $this->assertFalse($notice->interpreterIsNamed(), "expected {$why} to be unnameable");
            $this->assertStringContainsString('&& php /srv/bridge/artisan bridge:tick', (string) $notice->crontabLine());
            $this->assertStringContainsString('THE INTERPRETER IS A BARE `php`', $text);
            $this->assertStringNotContainsString('the interpreter is ABSOLUTE', $text);
        }

        // The other half of the control: a nameable interpreter takes the opposite arm, so the
        // guard is not passing by flagging everything.
        $named = $this->notice(ageS: null, declared: null);
        $this->assertTrue($named->interpreterIsNamed());
        $this->assertStringContainsString('the interpreter is ABSOLUTE', implode("\n", $named->lines()));
    }

    // ─── the value that reaches a pasted command ──────────────────────────────

    public function test_a_base_path_that_cannot_be_pasted_renders_no_line_and_names_the_cause(): void
    {
        // The packet's rule, one surface over: a path carrying a space or a shell metacharacter
        // does not make a line that runs, it makes a line that breaks. Offering it would spend
        // the operator's attention on a paste that was never going to work.
        $lines = $this->notice(ageS: null, declared: null, base: '/opt/my bridge')->lines();
        $text = implode("\n", $lines);

        $this->assertNull($this->notice(ageS: null, declared: null, base: '/opt/my bridge')->crontabLine());
        $this->assertStringContainsString('NO LINE IS RENDERED', $text);
        $this->assertStringContainsString('/opt/my bridge', $text);
        $this->assertStringNotContainsString('0,10,20,30,40,50 * * * * cd', $text);
        // The rest of the offer still prints — the declaration and the assert are still owed.
        $this->assertStringContainsString('BRIDGE_JOBS_TICK_EXPECTED_EVERY=600', $text);
    }

    // ─── the agent reader ─────────────────────────────────────────────────────

    public function test_the_lines_meant_to_be_copied_are_flush_left(): void
    {
        // `laravel/pao` collapses runs of spaces for an AI-agent reader, and a leading space on
        // a crontab or dotenv line is one more thing between the reader and a clean paste.
        foreach ($this->notice(ageS: null, declared: null)->lines() as $line) {
            if (str_starts_with($line, '0,') || str_starts_with($line, 'BRIDGE_JOBS_TICK_EXPECTED_EVERY')) {
                $this->assertSame($line, ltrim($line));
            }
        }

        $this->assertStringStartsWith('PERIODIC TICK — ', $this->notice(ageS: null, declared: null)->lines()[0]);
    }
}
