<?php

namespace Tests\Feature\ClientUpdate;

use App\Bridge\ClientUpdate\SeatClientLedger;
use App\Models\SeatClientEvent;
use App\Models\SeatClientState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The live path's bounded chain read agrees with the full read (card#10567 B4, review r4): after
 * every report of a random sequence — two installs, re-sends, gaps, late fills, bad links and
 * different-bytes re-sends — the row's mark equals `SeatClientLedger::chainBreak()` over the whole
 * stored log. Adopted from the r4 reviewer's harness.
 *
 * FIXED SEEDS, BOUNDED RUNTIME: the seeds are a constant range, so a red run re-runs identically,
 * and the range is sized for the suite. The CONTROLS below assert the generator actually produced
 * the cases the property is about — marked and unmarked checks, resumes, each break kind — so a
 * generator that drifted into only-clean logs cannot pass vacuously.
 */
class ChainBreakFuzzTest extends TestCase
{
    use RefreshDatabase;

    private const SEEDS = 120;

    private const LINES = 8;

    private const STEPS = 10;

    private static function line(string $install, int $seq, ?string $prev, bool $alternate): string
    {
        return (string) json_encode([
            'install_id' => $install, 'seq' => $seq, 'time' => '2026-09-28T10:00:00Z',
            'action' => $alternate ? 'skip' : 'prune', 'actor' => 'provision', 'result' => $alternate ? 'skipped' : 'ok',
            'prev_sha256' => $prev,
        ], JSON_UNESCAPED_SLASHES);
    }

    public function test_the_bounded_read_always_agrees_with_the_full_read(): void
    {
        $full = new ReflectionMethod(SeatClientLedger::class, 'chainBreak');
        $installs = ['aaaa', 'bbbb'];
        $checks = $marked = $resumes = 0;
        $kinds = ['gap' => 0, 'link' => 0, 'conflict' => 0];

        for ($seed = 1; $seed <= self::SEEDS; $seed++) {
            mt_srand($seed);
            SeatClientEvent::query()->delete();
            SeatClientState::query()->delete();
            $good = $different = $badLink = [];
            foreach ($installs as $in) {
                $prev = null;
                for ($k = 1; $k <= self::LINES; $k++) {
                    $good[$in][$k] = self::line($in, $k, $prev, false);
                    $different[$in][$k] = self::line($in, $k, $prev, true);
                    $badLink[$in][$k] = self::line($in, $k, str_repeat('0', 64), false);
                    $prev = hash('sha256', $good[$in][$k]);
                }
            }
            $trace = [];
            $last = null;
            for ($step = 0; $step < self::STEPS; $step++) {
                $in = mt_rand(0, 4) === 0 ? $installs[1] : $installs[0];
                if ($last !== null && $last !== $in) {
                    $resumes++;
                }
                $last = $in;
                $head = (int) SeatClientEvent::query()->where('agent', 'seat')->where('install_id', $in)->max('seq');
                $lo = mt_rand(0, 2) > 0 ? max(1, min(self::LINES, $head + mt_rand(-1, 1))) : mt_rand(1, self::LINES);
                $hi = mt_rand($lo, min(self::LINES, $lo + 3));
                $seqs = [];
                for ($k = $lo; $k <= $hi; $k++) {
                    if (mt_rand(0, 9) > 0) {
                        $seqs[] = $k;
                    }
                }
                $seqs = $seqs === [] ? [$lo] : $seqs;
                $entries = [];
                foreach ($seqs as $k) {
                    $r = mt_rand(0, 39);
                    $entries[] = $r === 0 ? $different[$in][$k] : ($r === 1 ? $badLink[$in][$k] : $good[$in][$k]);
                }
                SeatClientLedger::report('seat', $in, $entries);
                $trace[] = $in.':'.implode(',', $seqs);

                $row = SeatClientState::query()->where('agent', 'seat')->firstOrFail();
                $want = $full->invoke(null, 'seat', $in);
                $checks++;
                if ($want !== null) {
                    $marked++;
                    $kinds[str_contains($want, 'never arrived') ? 'gap' : (str_contains($want, 'does not chain') ? 'link' : 'conflict')]++;
                }
                $this->assertSame([$want !== null, $want], [$row->log_discontinuity, $row->log_discontinuity_reason], "seed {$seed}: ".implode(' | ', $trace));
            }
        }

        // Controls: the property was exercised on the cases it is about.
        $this->assertGreaterThan(0, $marked, 'no check saw a broken log');
        $this->assertGreaterThan(0, $checks - $marked, 'no check saw a whole log');
        $this->assertGreaterThan(0, $resumes, 'no report switched install');
        foreach ($kinds as $kind => $n) {
            $this->assertGreaterThan(0, $n, "no {$kind} break was generated");
        }
    }
}
