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
     * @param  non-empty-array<int, array<string, mixed>>  $cards  id => card
     * @param  array<string, mixed>  $logContext  handler-specific correlation context (repo, pr/issue, tag)
     * @return array<string, mixed> the survivor card
     */
    public static function toSurvivor(KanbanClient $client, array $cards, string $subsystem, array $logContext): array
    {
        ksort($cards);
        $survivorId = array_key_first($cards);
        foreach (array_keys($cards) as $id) {
            if ($id === $survivorId) {
                continue;
            }
            $ctx = ['card_id' => $id, 'survivor' => $survivorId] + $logContext;
            if ($client->archiveCard($id)) {
                Log::info("{$subsystem}: archived duplicate card sharing the same correlation key", $ctx);
            } else {
                Log::error("{$subsystem}: duplicate archive returned 200 but the card is not archived (archived_at null); NOT retrying", $ctx);
            }
        }

        return $cards[$survivorId];
    }
}
