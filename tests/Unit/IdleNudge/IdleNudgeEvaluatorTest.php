<?php

namespace Tests\Unit\IdleNudge;

use App\Bridge\IdleNudge\AgentVerdict;
use App\Bridge\IdleNudge\Evaluation;
use App\Bridge\IdleNudge\FleetSnapshot;
use App\Bridge\IdleNudge\IdleNudgeEvaluator;
use App\Bridge\IdleNudge\InboxUnreadable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every verdict branch of the idle nudge, one fixture each, over the real wire shape
 * (rt#479 issuecomment-5657433728 / -5657493757).
 *
 * ⚑ THE TWO CLOCKS ARE DELIBERATELY FAR APART in every fixture: the bridge DB's "now" is two
 * hours AHEAD of Mezzanine's `server_time`. A predicate that subtracted one clock from the other
 * would misjudge every case below, not just a skew-specific one.
 */
class IdleNudgeEvaluatorTest extends TestCase
{
    private const INSTALL = 'inst-a';

    private const SERVER_TIME = '2026-09-14T12:00:00.000Z';

    private const SKEW_S = 7200;

    private function serverMs(): int
    {
        return (int) FleetSnapshot::instantMs(self::SERVER_TIME);
    }

    private function dbNowS(): float
    {
        return $this->serverMs() / 1000 + self::SKEW_S;
    }

    /**
     * An idle seat of the configured install, declared by `pm`, idle for an hour, horizon 600.
     *
     * @param  array<string, mixed>  $over
     * @return array<string, mixed>
     */
    private function seat(array $over = []): array
    {
        return array_merge([
            'install_id' => self::INSTALL,
            'seat_id' => 'seat-1',
            'render_state' => 'idle',
            'activity_state' => 'idle',
            'link_state' => 'live',
            'protocol_agent_name' => 'pm',
            'protocol_agent_name_check' => 'checked',
            'retired' => null,
            'derivation' => ['computed_at' => self::SERVER_TIME, 'fold_lag_ms' => 0, 'cursor_event_id' => 9],
            'idle_since' => '2026-09-14T11:00:00.000Z',
            'idle_nudge_after_s' => 600,
        ], $over);
    }

    /** A staged line whose age on the DB clock is $ageS. */
    private function line(string $id, float $ageS): array
    {
        return ['id' => $id, 'ts' => $this->dbNowS() - $ageS, 'kind' => 'card_moved', 'subject_id' => '42', 'summary' => 'moved', 'agent' => 'pm'];
    }

    /**
     * @param  list<array<string, mixed>>  $seats
     * @param  array<string, bool>  $agents
     * @param  array<string, int>  $nudged
     * @param  list<array<string, mixed>>|null  $lines  null = the inbox cannot be read
     */
    private function evaluate(array $seats, array $agents = ['pm' => true], array $nudged = [], ?array $lines = null, float $transitS = 0.05): Evaluation
    {
        $lines ??= [$this->line('d1:pm:0', 600)];

        return (new IdleNudgeEvaluator)->evaluate(
            new FleetSnapshot($this->serverMs(), $seats, $transitS),
            self::INSTALL,
            $agents,
            $nudged,
            fn (string $agent): array => $lines,
            $this->dbNowS(),
            1800,
        );
    }

    private function only(Evaluation $e): AgentVerdict
    {
        $this->assertCount(1, $e->verdicts);

        return $e->verdicts[0];
    }

    public function test_an_idle_seat_past_its_declared_horizon_with_pushed_work_is_nudged(): void
    {
        $v = $this->only($this->evaluate([$this->seat()]));

        $this->assertSame('nudge', $v->code);
        $this->assertNotNull($v->plan);
        $this->assertFalse($v->plan->suspect);
        $this->assertSame(600, $v->plan->horizonS);
        $this->assertSame(3600, $v->plan->idleAgeS);
        $this->assertSame(1, $v->plan->pendingTotal);
        $this->assertSame('seat-1', $v->plan->seatId);
    }

    public function test_an_absent_horizon_uses_the_default_and_is_suspect(): void
    {
        $seat = $this->seat();
        unset($seat['idle_nudge_after_s']);

        $v = $this->only($this->evaluate([$seat]));

        $this->assertSame('nudge', $v->code);
        $this->assertTrue($v->plan?->suspect);
        $this->assertSame(1800, $v->plan?->horizonS);
    }

    public function test_an_agent_whose_intents_are_not_push_routed_is_never_nudged(): void
    {
        // The inbox-only default: its intents were never pushed at the seat, so a nudge on them
        // would turn the operator's inbox-only choice into a delayed wake.
        $v = $this->only($this->evaluate([$this->seat()], ['pm' => false]));

        $this->assertSame('not_push_routed', $v->code);
        $this->assertNull($v->plan);
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function perSeatVerdicts(): iterable
    {
        yield 'present-null horizon' => [['idle_nudge_after_s' => null], 'malformed_horizon'];
        yield 'string horizon' => [['idle_nudge_after_s' => '600'], 'malformed_horizon'];
        yield 'zero horizon' => [['idle_nudge_after_s' => 0], 'malformed_horizon'];
        yield 'within horizon' => [['idle_nudge_after_s' => 7200], 'idle_within_horizon'];
        yield 'no idle_since' => [['idle_since' => null], 'no_idle_since'];
        yield 'non-rfc3339 idle_since' => [['idle_since' => 'yesterday'], 'no_idle_since'];
        yield 'idle_since after server_time' => [['idle_since' => '2026-09-14T12:00:01.000Z'], 'no_idle_since'];
        yield 'fold lag past the grace' => [['derivation' => ['fold_lag_ms' => 120000]], 'fold_lag'];
        yield 'fold lag unreadable' => [['derivation' => ['fold_lag_ms' => 'x']], 'fold_lag_unreadable'];
        yield 'working' => [['render_state' => 'working'], 'not_idle'];
        yield 'disabled is a measured state' => [['render_state' => 'disabled', 'enabled' => false], 'not_idle'];
        yield 'stale link' => [['render_state' => 'stale', 'activity_state' => 'idle'], 'not_idle'];
        yield 'offline link with masked idle activity' => [['render_state' => 'offline', 'activity_state' => 'idle'], 'not_idle'];
        yield 'unrecognised render_state' => [['render_state' => 'sleeping'], 'unrecognised_state'];
        yield 'non-string render_state' => [['render_state' => 3], 'unrecognised_state'];
        yield 'served retired render_state' => [['render_state' => 'retired'], 'served_retired'];
        yield 'served non-null retired member' => [['retired' => '2026-09-01T00:00:00.000Z'], 'served_retired'];
        yield 'null seat_id' => [['seat_id' => null], 'malformed_seat_identity'];
        yield 'empty seat_id' => [['seat_id' => ''], 'malformed_seat_identity'];
        yield 'lone disagreed declarer' => [['protocol_agent_name_check' => 'disagreed'], 'no_declaring_seat'];
        yield 'lone unchecked declarer resolves' => [['protocol_agent_name_check' => 'unchecked'], 'nudge'];
    }

    /** @param  array<string, mixed>  $over */
    #[DataProvider('perSeatVerdicts')]
    public function test_per_seat_verdict(array $over, string $expected): void
    {
        $v = $this->only($this->evaluate([$this->seat($over)]));

        $this->assertSame($expected, $v->code);
        $this->assertSame($expected === 'nudge', $v->plan !== null);
    }

    public function test_every_documented_render_state_other_than_idle_is_a_measured_not_idle(): void
    {
        foreach (array_diff(IdleNudgeEvaluator::RENDER_STATES, ['idle', 'retired']) as $state) {
            $v = $this->only($this->evaluate([$this->seat(['render_state' => $state])]));
            $this->assertSame('not_idle', $v->code, $state);
            $this->assertTrue($v->measured(), $state);
        }
    }

    public function test_no_declaring_seat_when_nothing_declares_the_agent(): void
    {
        // Today's live shape: every seat has a NULL protocol_agent_name.
        $e = $this->evaluate([$this->seat(['protocol_agent_name' => null]), $this->seat(['seat_id' => 's2', 'protocol_agent_name' => null])]);

        $this->assertSame('no_declaring_seat', $this->only($e)->code);
        $this->assertSame([], $e->plans());
        $this->assertSame(2, $e->seatTally['unmapped']);
    }

    public function test_two_declarers_resolve_to_nothing_whatever_their_check_state(): void
    {
        $e = $this->evaluate([
            $this->seat(),
            $this->seat(['seat_id' => 'seat-2', 'protocol_agent_name_check' => 'disagreed']),
        ]);

        $this->assertSame('duplicate_declaration', $this->only($e)->code);
    }

    public function test_an_undeclared_check_never_counts_toward_a_duplicate(): void
    {
        $e = $this->evaluate([
            $this->seat(),
            $this->seat(['seat_id' => 'seat-2', 'protocol_agent_name_check' => 'undeclared']),
        ]);

        $this->assertSame('nudge', $this->only($e)->code);
    }

    public function test_a_declarer_with_a_malformed_seat_id_still_counts_toward_a_duplicate(): void
    {
        $e = $this->evaluate([$this->seat(), $this->seat(['seat_id' => null])]);

        $this->assertSame('duplicate_declaration', $this->only($e)->code);
    }

    public function test_foreign_and_malformed_install_ids_never_join(): void
    {
        $e = $this->evaluate([
            $this->seat(['install_id' => 'inst-b']),
            $this->seat(['install_id' => null]),
            $this->seat(['install_id' => 7]),
        ]);

        $this->assertSame('no_declaring_seat', $this->only($e)->code);
        $this->assertSame(3, $e->seatTally['foreign']);
    }

    public function test_the_seat_tally_is_closed(): void
    {
        $e = $this->evaluate([
            $this->seat(['install_id' => 'inst-b']),
            $this->seat(['seat_id' => 's2', 'protocol_agent_name' => null]),
            $this->seat(['seat_id' => 's3', 'protocol_agent_name' => ['pm']]),
            $this->seat(['seat_id' => 's4', 'protocol_agent_name' => 'someone-else']),
            $this->seat(),
        ], ['pm' => true]);

        $this->assertSame(['foreign' => 1, 'unmapped' => 1, 'malformed_name' => 1, 'unknown_agent' => 1, 'declarer' => 1], $e->seatTally);
        $this->assertSame(5, array_sum($e->seatTally));
    }

    public function test_already_nudged_for_this_idle_since_is_not_nudged_again(): void
    {
        $idleSince = (int) FleetSnapshot::instantMs('2026-09-14T11:00:00.000Z');

        $this->assertSame('already_nudged', $this->only($this->evaluate([$this->seat()], nudged: ['pm' => $idleSince]))->code);
        $this->assertSame('nudge', $this->only($this->evaluate([$this->seat()], nudged: ['pm' => $idleSince - 1]))->code);
    }

    public function test_idle_since_is_compared_as_an_instant_not_as_a_string(): void
    {
        // The same instant spelled with an offset and without milliseconds is the same period.
        $idleSince = (int) FleetSnapshot::instantMs('2026-09-14T11:00:00.000Z');
        $seat = $this->seat(['idle_since' => '2026-09-14T13:00:00+02:00']);

        $this->assertSame('already_nudged', $this->only($this->evaluate([$seat], nudged: ['pm' => $idleSince]))->code);
    }

    public function test_an_unreadable_inbox_is_unmeasured_not_empty(): void
    {
        $e = (new IdleNudgeEvaluator)->evaluate(
            new FleetSnapshot($this->serverMs(), [$this->seat()], 0.05),
            self::INSTALL,
            ['pm' => true],
            [],
            fn (string $agent): array => throw new InboxUnreadable('no'),
            $this->dbNowS(),
            1800,
        );

        $this->assertSame('inbox_unreadable', $this->only($e)->code);
    }

    public function test_pending_counts_only_work_staged_after_the_idle_edge(): void
    {
        $v = $this->only($this->evaluate([$this->seat()], lines: [$this->line('before', 3700)]));

        $this->assertSame('nothing_pending', $v->code);
    }

    public function test_pending_excludes_work_younger_than_the_wake_grace(): void
    {
        $v = $this->only($this->evaluate([$this->seat()], lines: [$this->line('fresh', 60)]));

        $this->assertSame('nothing_pending', $v->code);
    }

    public function test_the_wake_grace_grows_with_the_seats_own_fold_lag(): void
    {
        // 150 s old clears the bare grace (120 + transit + quantum) but not grace + 60 s of lag.
        $lagging = $this->seat(['derivation' => ['fold_lag_ms' => 60000]]);

        $this->assertSame('nudge', $this->only($this->evaluate([$this->seat()], lines: [$this->line('x', 150)]))->code);
        $this->assertSame('nothing_pending', $this->only($this->evaluate([$lagging], lines: [$this->line('x', 150)]))->code);
    }

    public function test_the_grace_budget_subtracts_the_measured_transit(): void
    {
        $line = [$this->line('x', 130)];

        $this->assertSame('nudge', $this->only($this->evaluate([$this->seat()], lines: $line, transitS: 0.5))->code);
        $this->assertSame('nothing_pending', $this->only($this->evaluate([$this->seat()], lines: $line, transitS: 20.0))->code);
    }

    public function test_pending_is_capped_oldest_first_with_the_true_total(): void
    {
        $lines = [];
        for ($i = 0; $i < IdleNudgeEvaluator::PENDING_CAP + 3; $i++) {
            $lines[] = $this->line("l{$i}", 300 + $i);
        }

        $plan = $this->only($this->evaluate([$this->seat()], lines: $lines))->plan;

        $this->assertNotNull($plan);
        $this->assertSame(IdleNudgeEvaluator::PENDING_CAP + 3, $plan->pendingTotal);
        $this->assertCount(IdleNudgeEvaluator::PENDING_CAP, $plan->pending);
        $this->assertSame('l'.(IdleNudgeEvaluator::PENDING_CAP + 2), $plan->pending[0]['id']);
    }

    public function test_every_declared_agent_gets_exactly_one_verdict(): void
    {
        $e = $this->evaluate([$this->seat()], ['pm' => true, 'impl' => true, 'quiet' => false]);

        $this->assertSame(['impl', 'pm', 'quiet'], array_map(fn (AgentVerdict $v): string => $v->agent, $e->verdicts));
        $this->assertSame(['no_declaring_seat', 'nudge', 'not_push_routed'], array_map(fn (AgentVerdict $v): string => $v->code, $e->verdicts));
    }
}
