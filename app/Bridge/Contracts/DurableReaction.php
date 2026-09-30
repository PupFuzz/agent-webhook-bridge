<?php

namespace App\Bridge\Contracts;

/**
 * Marker for a Handler whose side effect is DURABLE — not loss-tolerant (DL-009).
 *
 * A normal Handler is best-effort: a throw is recorded as a note and the webhook
 * still acks 200 (treatment C — an idle-agent connection-refused is normal). That
 * is wrong for a side effect that must not be silently dropped — e.g. the
 * GitHub-PR→card-move writeback. A handler that also implements DurableReaction
 * is run by DispatchService BEFORE the best-effort handlers, and its failure is not
 * swallowed as a note. It is run THROUGH the owed-write queue
 * (`App\Bridge\Writeback\OwedWriteQueue::drain()`, card#10849 / DL-440): the target is
 * recorded as owed first, and deleted once handle() returns. A RATE-LIMITED
 * `RequestException` (408/429, `RefusalContext::isRateLimited()`) is caught there and the
 * write stays owed — the delivery acks 200 and the bridge retries it itself, in order,
 * per subject. Anything else PROPAGATES (→ 5xx) exactly as before.
 *
 * Durability is a property of the HANDLER (operator-registered), never of the
 * ReactionTarget (classifier-emitted, attacker-influenceable) — so the classify
 * path can neither downgrade a durable side effect to best-effort nor upgrade a
 * best-effort one into a 5xx storm.
 *
 * CONTRACT:
 *  - a DurableReaction handler MUST be idempotent. It can be re-run by a redelivery
 *    (the whole per-agent dispatch: classify → re-stage → re-run handlers), by the
 *    owed-write queue's retry, and — in the rare case a lock outlives its TTL — twice
 *    concurrently, so it must no-op when its effect is already applied (a move no-ops if
 *    the card is already in the target stage).
 *  - it must LET A RATE LIMIT ESCAPE: rethrow it rather than swallow it as a permanent
 *    refusal, or the queue never sees it and the write is lost.
 *  - it must NOT touch the queue itself. One apply path; a handler with an internal
 *    loop stops calling a rate-limited source and lets the refusal escape once, and the
 *    queue holds the whole target as one owed write.
 */
interface DurableReaction {}
