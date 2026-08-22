<?php

namespace App\Bridge\Writeback;

use Illuminate\Support\Facades\Log;

/**
 * The shared duplicate-collapse kernel for the writeback create paths (DL-198,
 * extracted from KanbanDependabotCardHandler per canon #5). Both create-capable
 * handlers race the same way — a check-then-create is not atomic across concurrent
 * deliveries, so two workers can each correlate empty and each create — and both
 * must converge on the SAME survivor, or the two movers drift on which card wins.
 *
 * The kernel: keep the LOWEST id (a deterministic choice, so two racing workers
 * that observe the same set pick the same survivor and the same archive set),
 * archive the rest (idempotent — an archived card drops out of correlation, so a
 * redelivery re-presents nothing). Each handler keeps its OWN correlation (by-ref
 * PR vs `id:` tag); only the tie-break is single-sourced here.
 */
final class CardCollapse
{
    /**
     * Reduce an `id => card` map to a single survivor (lowest id), archiving every
     * other, and return the survivor's card. Assumes a non-empty map (callers guard
     * `count(...) > 1`). A 200-that-didn't-archive is deterministic (wrong-verb /
     * kanban contract break), so it is logged LOUD + left rather than 5xx-stormed
     * (the DL-020 posture) — same as the individual archive callers.
     *
     * ⛔ EVERY archive here is a GROUP-B write (card#7211/card#7212): the ids arrive from a
     * board-scoped correlate/search, so this is one of the arms most likely to touch a foreign
     * card — it fires exactly when correlation returned more rows than expected. DL-298 put a
     * `MappedBoardGuard::refuses()` re-check in front of every caller inside the mapped-board
     * regime, which decides WHETHER a row may be written to; the pair below records WHAT board
     * the write landed on, and the two are not substitutes — a gate emits evidence only when it
     * REFUSES, and this record is what answers "did this ever happen?" on the path it passes.
     * Both records therefore carry {@see MappedBoardGuard::boardContext()}: the archived line,
     * and the 200-but-not-archived `Log::error`, which reports a write kanban ACCEPTED whose
     * effect did not take — the request DID reach that card, so the board it reached is the
     * answer this record exists to give. A caller that hands in rows it never read
     * (`array_fill_keys($live, [])`) gets `card_board => null`, which is the honest answer: the
     * primitive records the absence rather than falling back to the mapped board, which would
     * manufacture agreement.
     *
     * ⚑ $mapping is NULLABLE, and that is a real state rather than a missing value: the
     * board-tools caller (`BoardCreateCardTool`) is outside the DL-009 mapped-board regime
     * altogether — its board is FORCED from `BoardToolsConfig`, and there is no per-repo
     * `WritebackMapping` to pair against. Null ⇒ no pair, not a guessed one — which is why it
     * sits LAST with a default rather than beside `$cards` where it reads better: the position
     * is what lets the one caller that genuinely has no mapping say so by omission instead of
     * inventing one from a config that means something else. Inside the writeback population
     * an omission is a defect, and it reds:
     * `WritebackSuccessBoardRecordTest::test_every_collapse_call_in_the_population_passes_its_mapping`.
     *
     * @param  non-empty-array<int, array<string, mixed>>  $cards  id => card
     * @param  array<string, mixed>  $logContext  handler-specific correlation context (repo, pr/issue, tag)
     * @param  ?WritebackMapping  $mapping  the repo mapping whose board every record is paired against;
     *                                      null ONLY for a caller outside the mapped-board regime
     * @return array<string, mixed> the survivor card
     */
    public static function toSurvivor(KanbanClient $client, array $cards, string $subsystem, array $logContext, ?WritebackMapping $mapping = null): array
    {
        ksort($cards);
        $survivorId = array_key_first($cards);
        foreach (array_keys($cards) as $id) {
            if ($id === $survivorId) {
                continue;
            }
            $ctx = ['card_id' => $id, 'survivor' => $survivorId]
                + ($mapping === null ? [] : MappedBoardGuard::boardContext($cards[$id], $mapping))
                + $logContext;
            if ($client->archiveCard($id)) {
                Log::info("{$subsystem}: archived duplicate card sharing the same correlation key", $ctx);
            } else {
                Log::error("{$subsystem}: duplicate archive returned 200 but the card is not archived (archived_at null); NOT retrying", $ctx);
            }
        }

        return $cards[$survivorId];
    }
}
