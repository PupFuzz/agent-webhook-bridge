<?php

namespace Tests\Unit\Bridge\Check\DeliveryHistory;

use App\Bridge\Check\DeliveryHistory\DeliveryHistoryState;
use App\Bridge\Check\DeliveryHistory\ScopeDeliveryHistory;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * The derivation replayed over the incident it was built for (DL-382).
 *
 * THE FIXTURE is `tests/Fixtures/delivery-gap-2026-08/`, and its README owns what it is and is not — read it before
 * reading a result here as a claim about a live install: it is the counts the affected seats PUBLISHED, not the
 * capture committed elsewhere, and a day's deliveries are placed evenly across that day by this test.
 *
 * WHAT IS ASSERTED, per series, by replaying `bridge:check` at every evaluation point across the series as if it had
 * run then, with only the deliveries recorded before that point:
 *  - the zero runs the fixture holds are exactly the deaf windows the seats named, so the test cannot quietly
 *    measure some other gap;
 *  - inside a deaf window the leg is loud from the moment the silence passes the threshold the record held at the
 *    last delivery, until the scope resumes, and quiet before that moment;
 *  - it is loud NOWHERE else — including the control scope's in-gap zero day and the single-delivery day of an intent
 *    series, each asserted to have actually been reached (a quiet stretch nothing evaluated proves nothing).
 */
class ScopeDeliveryHistoryFixtureTest extends TestCase
{
    private const FIXTURE = __DIR__.'/../../../../Fixtures/delivery-gap-2026-08/published-daily-counts.csv';

    private const EVAL_STEP = 3 * 3600;

    /**
     * The deaf window each seat NAMED on rt#434, as first and last zero day, and the state the replay must reach
     * inside it. Null for a series with no named window.
     *
     * @var array<string, array{0: string, 1: string, 2: DeliveryHistoryState}|null>
     */
    private const NAMED_WINDOWS = [
        'aimla-pm-roundtable|webhook_events' => ['2026-08-24', '2026-09-08', DeliveryHistoryState::UnderivedPastFloor],
        'aimla-pm-control|webhook_events' => null,
        'aimla-pm-roundtable|inbox_intents' => ['2026-08-23', '2026-09-08', DeliveryHistoryState::UnderivedPastFloor],
        // R1 finding 1: MIN_SPAN_SECONDS doubled to two weekly cycles. This series' pre-window record spans only
        // 2026-08-10 → 2026-08-22 (12 days), under the new floor, so the cut can no longer derive a threshold — it
        // reads UnderivedPastFloor instead of a (wrongly) derived PastThreshold.
        'kanban-solo-roundtable|inbox_intents' => ['2026-08-23', '2026-09-08', DeliveryHistoryState::UnderivedPastFloor],
    ];

    /**
     * The series that hold a QUIET STRETCH outside any deaf window, with how long it is at least — the control
     * scope's in-gap zero day, and the single-delivery day of the intent series. The other series deliver many times
     * a day on every day outside their window, so for them "not loud outside the window" is a claim about busy days
     * only, and nothing here pretends otherwise.
     *
     * @var array<string, int>
     */
    private const QUIET_WITNESS = [
        'aimla-pm-control|webhook_events' => 24 * 3600,
        'kanban-solo-roundtable|inbox_intents' => 12 * 3600,
    ];

    public function test_every_series_is_contiguous_and_its_zero_runs_are_the_named_windows(): void
    {
        $series = $this->series();
        $this->assertSame(array_keys(self::NAMED_WINDOWS), array_keys($series), 'the fixture and this test disagree about which series exist');

        foreach ($series as $key => $days) {
            $dates = array_keys($days);
            for ($i = 1; $i < count($dates); $i++) {
                $this->assertSame(
                    86400,
                    $this->dayStart($dates[$i]) - $this->dayStart($dates[$i - 1]),
                    "{$key}: {$dates[$i - 1]} → {$dates[$i]} is not contiguous, so an unpublished day would read as a zero",
                );
            }

            $runs = array_values(array_filter($this->zeroRuns($days), static fn (array $run): bool => $run[0] !== $run[1]));
            $named = self::NAMED_WINDOWS[$key];
            $this->assertSame($named === null ? [] : [[$named[0], $named[1]]], $runs, "{$key}: the multi-day zero runs are not the named window");
        }
    }

    public function test_the_named_deaf_windows_are_flagged_and_nothing_else_is(): void
    {
        foreach ($this->series() as $key => $days) {
            $deliveries = $this->deliveries($days);
            $dates = array_keys($days);
            $named = self::NAMED_WINDOWS[$key];

            $windowOpen = null;
            $windowClose = null;
            $loudFrom = null;
            if ($named !== null) {
                $windowOpen = max(array_filter($deliveries, fn (int $t): bool => $t < $this->dayStart($named[0])));
                $windowClose = min(array_filter($deliveries, fn (int $t): bool => $t >= $this->dayStart($named[1]) + 86400));
                $atCut = ScopeDeliveryHistory::fromAscendingTimestamps(array_filter($deliveries, static fn (int $t): bool => $t <= $windowOpen));
                $loudFrom = $windowOpen + ($atCut->derivedThreshold() ?? ScopeDeliveryHistory::FLOOR_SECONDS);
            }

            $longestQuietOutsideWindow = 0;
            $statesInWindow = [];
            for ($t = $this->dayStart($dates[0]); $t <= $this->dayStart(end($dates)) + 86400; $t += self::EVAL_STEP) {
                $history = ScopeDeliveryHistory::fromAscendingTimestamps(array_filter($deliveries, static fn (int $d): bool => $d < $t));
                if ($history->deliveries === 0) {
                    continue;
                }
                $state = $history->stateAt($t);
                // `<=` on the close: a replay at the resuming delivery's own instant has not recorded it yet.
                $inWindow = $windowOpen !== null && $t > $windowOpen && $t <= $windowClose;

                if (! $inWindow) {
                    $longestQuietOutsideWindow = max($longestQuietOutsideWindow, $t - (int) $history->lastAt);
                    $this->assertFalse($state->isLoud(), "{$key}: loud ({$state->value}) at ".gmdate('Y-m-d H:i', $t).', outside any named deaf window');

                    continue;
                }

                if ($t > $loudFrom) {
                    $this->assertTrue($state->isLoud(), "{$key}: silent ".intdiv($t - $windowOpen, 3600).'h into the named window at '.gmdate('Y-m-d H:i', $t).' and not loud');
                    $statesInWindow[$state->value] = true;
                } else {
                    $this->assertFalse($state->isLoud(), "{$key}: loud at ".gmdate('Y-m-d H:i', $t).', before the silence passed the threshold the record held at its last delivery');
                }
            }

            if ($named !== null) {
                $this->assertSame([$named[2]->value], array_keys($statesInWindow), "{$key}: the window was flagged through an unexpected arm");
            }
            // ⛔ THE QUIET-STRETCH CONTROL IS NOT VACUOUS: where a series holds a quiet stretch outside its window,
            // the replay REACHED it (and the loop above asserted it stayed quiet there).
            if (isset(self::QUIET_WITNESS[$key])) {
                $this->assertGreaterThanOrEqual(self::QUIET_WITNESS[$key], $longestQuietOutsideWindow, "{$key}: the replay never reached the quiet stretch outside the window, so 'not loud' there proves nothing");
            }
        }
    }

    public function test_the_control_scopes_in_gap_zero_day_was_a_full_day_of_silence_and_stayed_quiet(): void
    {
        $days = $this->series()['aimla-pm-control|webhook_events'];
        $this->assertSame(0, $days['2026-08-30']);
        $deliveries = $this->deliveries($days);

        // The last moment before the scope resumes on 2026-08-31.
        $at = $this->dayStart('2026-08-31');
        $history = ScopeDeliveryHistory::fromAscendingTimestamps(array_filter($deliveries, static fn (int $t): bool => $t < $at));

        $this->assertGreaterThan(86400, $at - (int) $history->lastAt);
        $this->assertFalse($history->stateAt($at)->isLoud());
    }

    public function test_after_the_scope_resumes_the_outage_does_not_become_its_threshold(): void
    {
        $days = $this->series()['kanban-solo-roundtable|inbox_intents'];
        $history = ScopeDeliveryHistory::fromAscendingTimestamps($this->deliveries($days));

        $this->assertGreaterThan(16 * 86400, $history->longestGap, 'the outage is in the record');
        $this->assertSame(ScopeDeliveryHistory::FLOOR_SECONDS, $history->derivedThreshold(), 'and the threshold did not learn from it');
    }

    /**
     * @return array<string, array<string, int>> keyed `series|instrument`, then by date, in fixture order
     */
    private function series(): array
    {
        $handle = fopen(self::FIXTURE, 'r');
        $this->assertNotFalse($handle);
        $this->assertSame(['series', 'instrument', 'date', 'count'], fgetcsv($handle, escape: ''));

        $out = [];
        while (($row = fgetcsv($handle, escape: '')) !== false) {
            [$series, $instrument, $date, $count] = $row;
            $out["{$series}|{$instrument}"][(string) $date] = (int) $count;
        }
        fclose($handle);

        return $out;
    }

    /**
     * Each day's `count` deliveries, placed at the midpoints of `count` equal slices of that day.
     *
     * @param  array<string, int>  $days
     * @return list<int>
     */
    private function deliveries(array $days): array
    {
        $out = [];
        foreach ($days as $date => $count) {
            $start = $this->dayStart($date);
            for ($i = 0; $i < $count; $i++) {
                $out[] = $start + intdiv((2 * $i + 1) * 86400, 2 * $count);
            }
        }

        return $out;
    }

    /**
     * @param  array<string, int>  $days
     * @return list<array{0: string, 1: string}>
     */
    private function zeroRuns(array $days): array
    {
        $runs = [];
        $open = null;
        $last = null;
        foreach ($days as $date => $count) {
            if ($count === 0) {
                $open ??= $date;
                $last = $date;

                continue;
            }
            if ($open !== null) {
                $runs[] = [$open, (string) $last];
                $open = null;
            }
        }
        if ($open !== null) {
            $runs[] = [$open, (string) $last];
        }

        return $runs;
    }

    private function dayStart(string $date): int
    {
        return (new DateTimeImmutable($date, new DateTimeZone('UTC')))->getTimestamp();
    }
}
