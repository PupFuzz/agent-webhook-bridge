<?php

namespace App\Bridge\Classifiers;

use App\Bridge\Dispatch\ClassifyContext;
use App\Bridge\Dispatch\ClassifyResult;
use App\Bridge\Dispatch\ReactionTarget;

/**
 * Triage-wake classifier (DL-168, #3010): surfaces kanban activity to the inbox
 * (inherited from InboxOnlyClassifier) AND, for a card a HUMAN filed directly on
 * the board that is still UNTRIAGED, wakes the serving (PM / triage-owner) session
 * in near-real-time via a channel_push — instead of waiting for the SessionStart
 * untriaged-snapshot to surface it at the next session.
 *
 * The wake fires ONLY for `task.created` that is:
 *   - human-filed — the actor is NOT a known agent (a card filed by any agent
 *     registered in this install's YAMLs is dropped), and
 *   - untriaged — the card carries no `triaged` tag, no `id:pr:*` tag, and no `dl`
 *     external reference.
 *
 * No-self-wake — what actually suppresses each automated creator (NOT a single
 * `isKnownAgent` check, which only covers registered agents):
 *   - The bridge's OWN card creations are the dependabot-card path, which tags
 *     every card `triaged` at create (DL-024) — so the untriaged filter below drops
 *     them. (The bridge has no other card-CREATE path; the writeback move path only
 *     moves existing cards.)
 *   - The dedicated writeback `identity_id` user's events are dropped PRE-classify
 *     by the dispatcher's global-echo gate (`DispatchService` / `globalEchoIds`) —
 *     so they never reach this classifier. That gate is only active if the operator
 *     set `identity_id` in `writeback.json` (or `BRIDGE_GLOBAL_ECHO_IDS`).
 *   - The poll adapter's auto-`triaged` backstops are dropped by the same untriaged
 *     filter (they carry `triaged`).
 *
 * The filter reads the **`card` state snapshot** the `task.created` webhook now
 * carries (kanban DL-164) — so it runs entirely at classify time with NO callback
 * to the kanban API and NO read token (the prohibition on network calls in
 * classify() is honored). Against a kanban that predates the snapshot, `card` is
 * absent → the card reads as untriaged and the wake fires (the SessionStart
 * snapshot remains the durable backstop, so an over-wake is at worst minor noise,
 * never a miss).
 *
 * OPT-IN: set `classifier.class: App\Bridge\Classifiers\KanbanTriageClassifier`
 * on the triage-owner agent and subscribe it to `task.created`. The push goes to
 * that agent's own channel (the cfg default) — so the triage owner IS the wake
 * recipient by configuration; other agents keep InboxOnly and never wake.
 */
class KanbanTriageClassifier extends InboxOnlyClassifier
{
    public function classify(ClassifyContext $ctx): ClassifyResult
    {
        $result = parent::classify($ctx);

        if ($ctx->provider !== 'kanban'
            || $ctx->eventType !== 'task.created'
            || $result->intents === []
            || $ctx->actor->isKnownAgent          // registered agents only; bridge/dependabot/writeback suppressed elsewhere (see docstring)
            || $this->isAlreadyClassified($ctx->payload)) {
            return $result;
        }

        // Human-filed + untriaged → pair the new_card Intent with a channel_push to
        // the serving (triage-owner) session. targetId === the Intent's subjectId so
        // the dispatcher's silent-drop guard never warns; payload is the Intent's
        // wire shape (handler sends {"intent": <toArray()>}); transport is the
        // agent's cfg-default channel.
        $push = ReactionTarget::make(
            handler: 'channel_push',
            targetId: $result->intents[0]->subjectId,
            debounceSeconds: 0,
            payload: $result->intents[0]->toArray(),
        );

        return new ClassifyResult(
            targets: array_merge($result->targets, [$push]),
            intents: $result->intents,
        );
    }

    /**
     * Whether the new card is ALREADY classified — a `triaged` tag, an `id:pr:*`
     * tag, or a `dl` external reference — read from the DL-164 `card` snapshot on
     * the `task.created` webhook (no API call). Such a card is not a fresh human
     * triage item, so it must NOT wake the triage owner.
     *
     * @param  array<mixed>  $payload
     */
    private function isAlreadyClassified(array $payload): bool
    {
        $card = is_array($payload['card'] ?? null) ? $payload['card'] : [];

        $tags = is_array($card['tags'] ?? null) ? $card['tags'] : [];
        foreach ($tags as $tag) {
            if ($tag === 'triaged' || (is_string($tag) && str_starts_with($tag, 'id:pr:'))) {
                return true;
            }
        }

        $refs = is_array($card['external_references'] ?? null) ? $card['external_references'] : [];
        foreach ($refs as $ref) {
            if (is_array($ref) && ($ref['system'] ?? null) === 'dl') {
                return true;
            }
        }

        return false;
    }
}
