<?php

namespace App\Bridge\Writeback;

/**
 * THE CAPS KANBAN'S OWN VALIDATOR PUTS ON THE FIELDS THE BRIDGE WRITES (card#8378) —
 * one owner for a set of numbers that live in another repo.
 *
 * ⚠ EVERY VALUE HERE IS A MIRROR OF `App\Support\TaskWriteRules` IN KANBAN-BOARD, the
 * single authority its create and update paths both validate against. The bridge cannot
 * import that ruleset, so these can only be right until kanban moves them — which is why
 * they are a DIAGNOSTIC and never the safety:
 *
 *  - The safety is kanban's 422, which fires whatever this file says.
 *  - The diagnostic is that a caller-fixable over-long value is named to the caller
 *    BEFORE the request, instead of arriving as a board 422 the seat cannot read.
 *  - The backstop for this file being stale is that the board tools map a kanban 422 on
 *    the write to a named deterministic refusal rather than to the retryable 502
 *    (DL-326): a cap that narrows upstream degrades the message, never the outcome.
 *
 * They live together, in ONE place, because the alternative measured itself: two
 * constants in two classes each carrying its own copy of this reasoning is the
 * restatement that drifts. `docs/kanban-integration-contract.md` is where the seam
 * records that the bridge mirrors them at all.
 */
final class KanbanFieldLimits
{
    /** `name => sometimes|string|max:255` (update path; create is `required` + the same cap). */
    public const NAME_MAX = 255;

    /** `tags.* => string|max:64` — the cap on ONE tag, not on the list. */
    public const TAG_MAX = 64;
}
