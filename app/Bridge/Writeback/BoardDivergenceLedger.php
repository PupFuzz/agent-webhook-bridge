<?php

namespace App\Bridge\Writeback;

use App\Models\WritebackBoardDivergence;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The DURABLE half of card#7212: a row per writeback observation whose (card board,
 * mapped board) pair DIVERGED, and nothing at all on the happy path.
 *
 * #556 made the pair visible on both arms; it did not make it survive. The log line it
 * writes is retention-bounded — 14 days, pruned by the receiver's own gate since DL-199 —
 * so "has a cross-board write ever landed here?" was answerable only for the last
 * fortnight, which is not an answer to a question about the past. An absence of record is
 * not a record of absence, and a record that expires is an absence on a timer.
 *
 * ⛔ THE PREDICATE IS NOT HERE, and must not be. Whether the pair diverges is
 * `! MappedBoardGuard::belongs()` — the DL-292 rule, in the one place that spells it, with
 * the mapping still in hand. This class is handed the pair its caller already rendered
 * ({@see MappedBoardGuard::boardContext()}), never a card and a mapping to re-derive it
 * from: the row and the log line must be the SAME observation, or the durable store gets
 * to disagree with the record it exists to outlive.
 *
 * ⚑ WHY THE COLUMNS ARE NEVER NAMED IN THIS FILE. The rendered pair is spread into the
 * insert as-is, so the column names ARE the record's key names, by construction — rename a
 * key in the guard and this stops compiling into a valid insert instead of quietly writing
 * a row with a null board. It also keeps the file clear of the one thing
 * `WritebackSuccessBoardRecordTest`'s one-rendering leg reds on: a second hand-rolled
 * spelling of the pair inside `app/Bridge/Writeback/`.
 *
 * ⚑ A FAILED WRITE IS LOGGED, NEVER THROWN. This runs mid-writeback, after kanban has
 * already been written to, and on the refusal arm whose whole contract is a permanent
 * no-op (never a 5xx redelivery storm — the DL-020 posture). A missing table or an
 * unreachable DB is deterministic: retrying it re-writes the card, and losing the audit row
 * is strictly less bad than re-running the write it audits. The `Log::error` carries the
 * same rendered pair, so the observation still lands in the record that has 14 days.
 */
final class BoardDivergenceLedger
{
    /** The guard refused the write: nothing was written to that card. */
    public const DISPOSITION_REFUSED = 'refused';

    /**
     * The pair was rendered for a RECORD of a write — i.e. no gate stopped a divergent card
     * from reaching a writeback write site. This is the disposition that answers "did this
     * ever happen?" with a yes; on every arm gated by {@see MappedBoardGuard::refuses()} it
     * is unreachable, which is exactly why its appearance is worth a durable row.
     */
    public const DISPOSITION_RECORDED = 'recorded';

    /**
     * Persist ONE divergent observation. Callers must have established the divergence
     * already (see the class docblock) — this method does not re-test it.
     *
     * @param  array{card_board: mixed, mapped_board: int}  $boardContext  as rendered by {@see MappedBoardGuard::boardContext()}
     * @param  array<string, mixed>  $card  the card the pair was read from, for its id only
     */
    public static function observe(array $boardContext, array $card, string $disposition): void
    {
        try {
            WritebackBoardDivergence::create($boardContext + [
                'disposition' => $disposition,
                'card_id' => is_numeric($card['id'] ?? null) ? (int) $card['id'] : null,
                'site' => self::callSite(),
            ]);
        } catch (Throwable $e) {
            Log::error(
                'writeback: a board divergence could not be persisted — this observation now expires with the log',
                ['disposition' => $disposition, 'error' => $e->getMessage()] + $boardContext,
            );
        }
    }

    /**
     * The writeback call site that observed the divergence, as `Class::method (File.php:NN)`.
     *
     * ⚑ Read from the CALL STACK rather than passed in, and that is the design rather than a
     * shortcut: an `$arm` argument would have to be spelled at every one of the guard's
     * callers — beside a message string that already names the arm — and the site this table
     * exists to catch is the N+1th one, which is precisely the site that would omit it. The
     * stack cannot be omitted and cannot drift from where the code actually is.
     *
     * The frame taken is the OUTERMOST one still inside this class or the guard: its
     * file/line is where the guard was entered from, and the frame above it names the
     * function that entered it. Args are excluded from the trace — a card payload has no
     * business in an audit row.
     */
    private static function callSite(): ?string
    {
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8);
        $own = [self::class, MappedBoardGuard::class];
        $entry = null;
        foreach ($frames as $i => $frame) {
            if (in_array($frame['class'] ?? null, $own, true)) {
                $entry = $i;
            }
        }
        if ($entry === null) {
            return null;
        }

        $file = $frames[$entry]['file'] ?? null;
        $where = is_string($file) ? basename($file).':'.($frames[$entry]['line'] ?? '?') : null;
        $caller = $frames[$entry + 1] ?? null;
        $who = $caller === null
            ? null
            : trim(($caller['class'] ?? '').($caller['type'] ?? '').$caller['function']);

        $site = trim(($who ?? '').($where === null ? '' : " ({$where})"));

        return $site === '' ? null : mb_substr($site, 0, 191);
    }
}
