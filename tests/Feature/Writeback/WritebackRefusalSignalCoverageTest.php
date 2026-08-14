<?php

namespace Tests\Feature\Writeback;

use Tests\TestCase;

/**
 * The class guard for DL-274/DL-285 (card#5312, card#5968).
 *
 * DL-274 fixed the minting primitive — `WritebackAlertNotifier::warnAndNotify` does the
 * durable log and the live push in one call, so an arm that uses it cannot log a refusal
 * without alerting on it. What that does NOT prevent is the next arm being written with a
 * bare `Log::warning(...)` and no notifier at all, which is exactly how 11 of 12 arms came
 * to be live-silent in the first place. Fixing N copies without a guard leaves the N+1th
 * to mint the bug again (canon #7).
 *
 * POPULATION, re-derived every run rather than counted once: every `Log::warning(` and
 * `Log::error(` call site in `app/Bridge/Handlers/Kanban*Handler.php`. `Log::error` is IN
 * the population deliberately — a permanent refusal that only writes an error line is the
 * same defect at a different level, and excluding it would report clean over a population
 * that had been narrowed to exclude the sibling.
 *
 * STATED BOUND: `Log::info` is NOT in the population. Those are the "not tracked" / normal
 * branches, which docs/writeback.md records as deliberately quiet. Neither is anything
 * outside these six handlers — `KanbanClient`'s three correlation diagnostics and
 * `CardCollapse`'s archive-contract error are a real sibling shape at the shared-client
 * layer, where there is no `(repo, outcome)` tuple to dedup on; they are recorded as a
 * remainder on card#5968 rather than silently included or silently dropped.
 *
 * The allow-list below is the whole point: a bare log call is not forbidden, it is
 * ACCOUNTED FOR. Set equality, not subset — a new bare call reds, and so does an
 * allow-list entry whose call site has gone (a stale exemption is its own defect).
 */
class WritebackRefusalSignalCoverageTest extends TestCase
{
    /**
     * Every bare `Log::warning`/`Log::error` in the writeback handlers, each with the
     * reason it does NOT route through the paired alert primitive.
     *
     * @var array<string, string>
     */
    private const ALLOWED = [
        // Paired by hand with notifyUnpark/notifyRevive: these are conditional OVERRIDE
        // notices with their own gate and their own signal `type`, not refusals (DL-274).
        'kanban_move_card: auto-unparked a card from a parked stage' => 'paired with notifyUnpark — a distinct signal type, not a refusal',
        'kanban_move_card: revived a card from the abandon stage on PR reopen' => 'paired with notifyRevive — a distinct signal type, not a refusal',
        // A documented FAIL-OPEN diagnostic: the move still happens, so nothing was refused.
        'kanban_move_card: could not read board stage order for the no-regression guard — allowing the move' => 'fail-open diagnostic — the move proceeds, no refusal to signal',
        // TYPE-NARROWING for a state WritebackConfig::load refuses at parse time (it
        // rejects a promote_on_release mapping missing either stage, and validates every
        // stage value as numeric). An alert here could never be seen to fail (canon #9).
        'kanban_promote_released: mapping is missing the Shipped and/or Released stage; ignoring' => 'unreachable type-narrowing — load fails closed first (KanbanPromoteReleasedHandlerTest pins that)',
        // A config-gap diagnostic on a create that SUCCEEDS: the card is created in the
        // next lane the issue declares that the map does carry, else the default lane —
        // so nothing was refused and there is no failure to signal. Routing it would emit
        // a writeback_move_failed alert for a completed create (DL-286).
        'kanban_coord_card: the issue declares a lane that is not mapped in coord_card_lane_stage_ids — creating in the next mapped lane it declares, else the default lane; add the lane to the mapping if this board has that column' => 'config-gap diagnostic — the create proceeds in a mapped lane, no refusal to signal',
        // Log::error, and its twin lives in the shared CardCollapse primitive where there
        // is no (repo, outcome) dedup tuple. Recorded as a remainder on card#5968; routing
        // one copy and not the other would be a fresh asymmetry.
        'kanban_dependabot_card: archive returned 200 but the card is not archived (archived_at null) — kanban _action:archive contract may have changed; NOT retrying' => 'Log::error with a shared-primitive twin in CardCollapse — filed, not orphaned',
    ];

    public function test_every_bare_log_call_in_a_writeback_handler_is_accounted_for(): void
    {
        $files = glob(base_path('app/Bridge/Handlers/Kanban*Handler.php')) ?: [];
        $this->assertGreaterThanOrEqual(6, count($files), 'the handler population came back short — the glob, not the code, is what changed');

        $found = [];
        foreach ($files as $file) {
            foreach (self::bareLogCalls((string) file_get_contents($file), basename($file)) as $site) {
                $found[$site] = true;
            }
        }

        // Set equality in both directions, order-independent (glob order is filesystem
        // order). An empty $found cannot pass: ALLOWED is non-empty, so a scanner that
        // stopped working reds on the missing side rather than reporting a clean repo.
        $allowed = array_keys(self::ALLOWED);
        $actual = array_keys($found);
        sort($allowed);
        sort($actual);
        $this->assertSame(
            $allowed,
            $actual,
            'a Log::warning/Log::error in a writeback handler is not routed through WritebackAlertNotifier::warnAndNotify and is not on the accounted-for list. '
            .'Either route it (a permanent refusal must emit a live signal — DL-274/DL-285) or add it to ALLOWED with the reason it is deliberately quiet.',
        );
    }

    public function test_the_scanner_discriminates_a_real_call_from_a_comment(): void
    {
        // The instrument's own control. Without it, a scanner that matched nothing would
        // report a clean repo — and one that matched comment prose would report noise as
        // defects. Both directions are exercised on a fixture whose answer is known.
        $source = <<<'PHP'
        <?php
        // A prose mention of Log::warning( inside a line comment.
        /** A docblock mentioning Log::error( as well. */
        Log::info('not in the population at all', []);
        Log::warning('subject: a real bare warning', ['k' => 'v']);
                Log::error('subject: a real bare error', []);
        $this->alerts->warnAndNotify('subject: routed, not bare', [], 'r', 'o', null, 'x');
        PHP;

        $this->assertSame(
            ['subject: a real bare warning', 'subject: a real bare error'],
            self::bareLogCalls($source, 'Fixture.php'),
        );
    }

    /**
     * The message of every `Log::warning(`/`Log::error(` call in $source, skipping `//`
     * and `*` comment lines. A call whose message is not a single-quoted literal on the
     * same line degrades to `<file>:<line>` rather than vanishing — an unparsed site must
     * surface as an unexpected entry, never as a silent absence.
     *
     * @return list<string>
     */
    private static function bareLogCalls(string $source, string $file): array
    {
        $sites = [];
        foreach (explode("\n", $source) as $i => $line) {
            $trimmed = ltrim($line);
            if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
                continue;
            }
            if (preg_match('/\bLog::(?:warning|error)\(/', $line) !== 1) {
                continue;
            }
            $sites[] = preg_match("/\bLog::(?:warning|error)\('((?:[^'\\\\]|\\\\.)*)'/", $line, $m) === 1
                ? str_replace("\\'", "'", $m[1])
                : $file.':'.($i + 1);
        }

        return $sites;
    }
}
