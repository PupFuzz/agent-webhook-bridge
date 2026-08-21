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
 * STATED BOUND: `Log::info` is NOT in the population. Those are MOSTLY the "not tracked" /
 * normal branches, which docs/writeback.md records as deliberately quiet — but the exclusion
 * is by LEVEL, not by kind, so a real refusal written at info level is invisible here. That
 * is not hypothetical: `kanban_coord_card_move`'s belongs-to-mapped-board refusal was exactly
 * that — it sat at info level through DL-285's sweep and stayed there until card#7133, with this
 * guard green over it the whole time. A green run says "no bare warning/error is unaccounted
 * for", never "no refusal is silent".
 *
 * ⭐ THAT BOUND STILL HOLDS, and it is why this class carries a SECOND, level-independent leg
 * (card#7138 / DL-292). Widening the population by LEVEL is not the closure — `Log::info` is
 * also the ~34 not-tracked / success / policy sites, and pulling them in would drown the
 * signal. The closure is by KIND, and it is structural: the belongs-to-mapped-board refusal
 * now lives in ONE primitive that owns the compare AND the report, and
 * {@see test_the_belongs_to_mapped_board_guard_lives_in_exactly_one_place} reds on a handler
 * that reads a card's `board_id` at all — at any log level, or at none. It closes exactly one
 * refusal KIND; every other kind is still covered only by the level-keyed leg above. Neither is anything
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
        // The move handler's twin of the line above (card#6393): the same config-gap
        // diagnostic on a revive/relane that SUCCEEDS — the card lands in the next lane
        // the issue declares that the map does carry, else the default lane. Nothing was
        // refused, so there is no failure to signal.
        'kanban_coord_card_move: the issue declares a lane that is not mapped in coord_card_lane_stage_ids — moving to the next mapped lane it declares, else the default lane; add the lane to the mapping if this board has that column' => 'config-gap diagnostic — the move proceeds to a mapped lane, no refusal to signal',
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

    /**
     * The KIND-keyed leg (card#7138 / DL-292) — the one the level-keyed leg above cannot be.
     *
     * The belongs-to-mapped-board (DL-009) security rule was written out three times, in three
     * non-equivalent spellings, which is how it came to carry two different report severities
     * (card#7133) — and the level-keyed leg was green over that for the defect's whole life,
     * because the third copy reported at `Log::info`. Both defects have ONE cause: the rule was
     * duplicated. `MappedBoardGuard` now owns the compare and the refusal report together, so a
     * fourth copy cannot be minted with a different predicate, a different reason code, or a
     * different log level — and this method is what makes that structural rather than a
     * convention.
     *
     * POPULATION, re-derived every run: every non-comment line in
     * `app/Bridge/Handlers/Kanban*Handler.php` naming the card field `'board_id'` or the reason
     * literal `'card_not_on_mapped_board'`. A membership decision cannot be written without one
     * of the two — the card's board comes back under that key and nowhere else — so a fourth
     * copy lands in the population whatever level it reports at. The expected set is EMPTY:
     * after the hoist no handler reads a card's board at all.
     *
     * An empty expectation is a measurement only because two other assertions make failure
     * possible: {@see test_the_board_scanner_discriminates_a_real_read_from_a_comment} proves
     * the scanner finds a planted site, and the primitive-side assertion below reds if the
     * guard is deleted or renamed rather than reporting a clean repo over a rule that has
     * vanished.
     *
     * STATED BOUND: the same glob as the leg above — a membership compare minted OUTSIDE those
     * six handler files (a new handler named off-pattern, or a fresh `Writeback/` collaborator)
     * is not covered until the glob is widened.
     */
    public function test_the_belongs_to_mapped_board_guard_lives_in_exactly_one_place(): void
    {
        $files = glob(base_path('app/Bridge/Handlers/Kanban*Handler.php')) ?: [];
        $this->assertGreaterThanOrEqual(6, count($files), 'the handler population came back short — the glob, not the code, is what changed');

        $found = [];
        foreach ($files as $file) {
            foreach (self::boardMembershipSites((string) file_get_contents($file), basename($file)) as $site) {
                $found[] = $site;
            }
        }

        $this->assertSame(
            [],
            $found,
            'a writeback handler reads a card\'s `board_id` or names `card_not_on_mapped_board` directly. '
            .'The DL-009 belongs-to-mapped-board rule and its refusal report belong to MappedBoardGuard::refuses() '
            .'(DL-292) — a second copy is how one guard came to carry two severities and three predicates (card#7133). '
            .'Route it through the primitive, or extend the primitive if the new arm needs something it does not offer.',
        );

        // The other direction: the rule still EXISTS, and exists there. Without this a deleted
        // or renamed guard would leave the assertion above trivially green.
        $primitive = (string) file_get_contents(base_path('app/Bridge/Writeback/MappedBoardGuard.php'));
        $this->assertSame(
            ['MappedBoardGuard.php:37', 'MappedBoardGuard.php:47', 'MappedBoardGuard.php:83'],
            self::boardMembershipSites($primitive, 'MappedBoardGuard.php'),
            'MappedBoardGuard no longer holds the reason code, the compare and the reported card_board in the places this guard expects — '
            .'if the primitive was legitimately reshaped, re-derive these line numbers; if it lost the rule, the assertion above is now vacuous.',
        );
    }

    public function test_the_board_scanner_discriminates_a_real_read_from_a_comment(): void
    {
        // The KIND scanner's own control: its expectation is an EMPTY set over the handlers,
        // so without a fixture whose answer is known, a scanner that had stopped matching
        // would report the repo clean. Both directions on one fixture — prose mentions are
        // skipped, real reads are found, at every log level and at none.
        $source = <<<'PHP'
        <?php
        // A comment mentioning $card['board_id'] and 'card_not_on_mapped_board'.
        /** A docblock naming `board_id` too. */
        Log::info('quiet refusal', ['card_board' => $card['board_id'] ?? null]);
        if (($card['board_id'] ?? null) !== $mapping->boardId) {
        $this->alerts->warnAndNotify('x', [], 'r', 'o', null, 'card_not_on_mapped_board');
        $stage = $card['workflow_stage_id'] ?? null;
        PHP;

        $this->assertSame(
            ['Fixture.php:4', 'Fixture.php:5', 'Fixture.php:6'],
            self::boardMembershipSites($source, 'Fixture.php'),
        );
    }

    /**
     * Every non-comment line in $source naming the card's `board_id` field or the
     * `card_not_on_mapped_board` reason code, as `<file>:<line>`. Line-keyed rather than
     * message-keyed: this population is expected to be empty, so there is no message to key
     * on — what a red needs to say is WHERE the second copy was written.
     *
     * @return list<string>
     */
    private static function boardMembershipSites(string $source, string $file): array
    {
        $sites = [];
        foreach (explode("\n", $source) as $i => $line) {
            $trimmed = ltrim($line);
            if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
                continue;
            }
            if (preg_match('/[\'"]board_id[\'"]|[\'"]card_not_on_mapped_board[\'"]/', $line) === 1) {
                $sites[] = $file.':'.($i + 1);
            }
        }

        return $sites;
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
