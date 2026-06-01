<?php

namespace App\Bridge\Contracts;

/**
 * Marker for a Handler whose side effect is DURABLE — not loss-tolerant (DL-009).
 *
 * A normal Handler is best-effort: a throw is recorded as a note and the webhook
 * still acks 200 (treatment C — an idle-agent connection-refused is normal). That
 * is wrong for a side effect that must not be silently dropped — e.g. the
 * GitHub-PR→card-move writeback. A handler that also implements DurableReaction
 * is run by DispatchService BEFORE the best-effort handlers, and its failure
 * PROPAGATES (treatment B → 5xx → upstream redelivers) instead of being swallowed.
 *
 * Durability is a property of the HANDLER (operator-registered), never of the
 * ReactionTarget (classifier-emitted, attacker-influenceable) — so the classify
 * path can neither downgrade a durable side effect to best-effort nor upgrade a
 * best-effort one into a 5xx storm.
 *
 * CONTRACT: a DurableReaction handler MUST be idempotent. Treatment-B redelivery
 * re-runs the whole per-agent dispatch (classify → re-stage → re-run handlers),
 * so the handler must no-op when its effect is already applied (a move no-ops if
 * the card is already in the target stage).
 */
interface DurableReaction {}
