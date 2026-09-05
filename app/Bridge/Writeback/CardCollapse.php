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
 *
 * ⚑ THIS CLASS IS THE ONE OWNER OF ITS CALLER POPULATION, and it states a RECIPE rather than
 * a number: `grep -rnP '^(?!\s*\*).*CardCollapse::toSurvivor\(' app/` names every caller,
 * and `WritebackSuccessBoardRecordTest::test_every_collapse_call_in_the_population_passes_its_mapping`
 * re-derives the same set on every run. ⚠ The anchor is load-bearing: without it the recipe
 * also returns comment prose — the recipe line above included — and `@see` mentions that call
 * nothing. Point here rather than restating a count anywhere else — the count moves the
 * next time a handler adopts this kernel, and a restated one is stale the moment it does.
 *
 * ⛔ A PINNED duplicate is NOT archived (card#8523, DL-340), and the consult lives HERE
 * rather than in the callers on purpose. DL-335 rejected widening the pin into this
 * kernel on the reading that it retires a *bridge-minted create-race twin* — a
 * data-integrity repair rather than an act on a card's lifecycle — and disclosed the live
 * consequence: on a duplicated repo+PR the collapse ran BEFORE the dependabot move consult,
 * so a human's hold was honoured on the survivor and ignored on the twin. The operator
 * reversed that on card#8523, for the reason the disclosure itself named: the twin a human
 * notices is the twin a human pins. A caller-side consult would have fixed ONE caller and
 * left every other one (canon #5), so the refusal is per-card and inside the loop — the
 * unpinned duplicates of the same key are still retired, because a hold is a property of
 * the CARD, never of the delivery.
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
     * answer this record exists to give. A caller with no $mapping gets NO pair rather than a
     * guessed one: the primitive records the absence instead of falling back to the mapped
     * board, which would manufacture agreement. (Until card#8523 the same caller also handed
     * in rows it had never read, so `card_board` would have been null even with a mapping;
     * that is no longer true of any caller — every one now reads its rows, because the pin
     * consult below is read off them.)
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
     * ⛔ $cards MUST be rows the caller actually READ, because the pin is read off them
     * (card#8523). It is a REQUIRED parameter shape, not a best-effort one: a row handed in
     * as `[]` carries no `block_reason` and no `tags`, so the predicate would answer "not
     * pinned" for a card nobody looked at — a check that cannot fire (canon #9). The
     * board-tools caller used to hand exactly that (`array_fill_keys($live, [])`) and now
     * reads its rows through `KanbanClient::cardRowsByTag()` instead, which is the write-site
     * fix rather than a read-time fallback here (canon #5). ⚠ Most callers get their rows from
     * kanban's SEARCH projection rather than from the by-id read, so that projection must keep
     * `block_reason` and `tags` top-level or this consult answers "not pinned" silently —
     * DECLARED on the `tags:"<tag>"` row of `docs/kanban-integration-contract.md`, which is the
     * surface the far end can read. ⭐ AND IT IS CHECKED FROM THIS SIDE: a row reaching the
     * consult with NEITHER field is exactly that degradation, and
     * {@see PinGuard::reportUnreadableRow} makes it LOUD at the predicate. It does not refuse —
     * that would change what the system refuses, and is card#8557's to rule on — so the seam is
     * audible rather than closed, which is the honest state and not the same as undetectable.
     *
     * ⚑ $repo is REQUIRED and may legitimately be `''`. It is the first element of the pin
     * refusal's `(repo, outcome, reason)` alert dedup tuple, and the board-tools caller has no
     * repo at all — an empty string is the honest value there, and it dedups that caller's
     * refusals install-wide rather than per repo, which is correct because the tool's cards
     * belong to an agent, not to a repo. The alert's `outcome` is $subsystem, so a collapse
     * refusal and its caller's own pin refusal (whose outcome is a synthetic constant) never
     * share a marker and cannot silence each other.
     *
     * @param  non-empty-array<int, array<string, mixed>>  $cards  id => card, each one READ by the caller
     * @param  string  $repo  the repo whose delivery is collapsing, `''` outside the writeback regime
     * @param  array<string, mixed>  $logContext  handler-specific correlation context (repo, pr/issue, tag)
     * @param  ?WritebackMapping  $mapping  the repo mapping whose board every record is paired against;
     *                                      null ONLY for a caller outside the mapped-board regime
     * @return array<string, mixed> the survivor card
     */
    public static function toSurvivor(KanbanClient $client, array $cards, string $subsystem, string $repo, array $logContext, ?WritebackMapping $mapping = null): array
    {
        ksort($cards);
        $survivorId = array_key_first($cards);
        // The notifier is constructed here rather than injected: this is a static kernel with
        // no caller that holds a different one (every handler builds the same class in its own
        // constructor default), and a parameter for it would be a seam no caller uses. ONE
        // instance for the whole collapse rather than one per iteration: the class holds no
        // per-card state (its config and its dedup marker are both read inside the emit), so a
        // fresh instance per refused card could not differ from this one — writing it that way
        // said the opposite.
        $alerts = new WritebackAlertNotifier;
        foreach (array_keys($cards) as $id) {
            if ($id === $survivorId) {
                continue;
            }
            $ctx = ['card_id' => $id, 'survivor' => $survivorId]
                + ($mapping === null ? [] : MappedBoardGuard::boardContext($cards[$id], $mapping))
                + $logContext;
            if (PinGuard::refuses($alerts, $cards[$id], $subsystem, 'duplicate archive', $id, $repo, $subsystem, $ctx)) {
                continue;
            }
            if ($client->archiveCard($id)) {
                Log::info("{$subsystem}: archived duplicate card sharing the same correlation key", $ctx);
            } else {
                Log::error("{$subsystem}: duplicate archive returned 200 but the card is not archived (archived_at null); NOT retrying", $ctx);
            }
        }

        return $cards[$survivorId];
    }
}
