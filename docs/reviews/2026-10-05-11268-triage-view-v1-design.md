# Design: `board_my_cards` triage view v1 — cards only (card#11268, DL-464)

**Status:** v1 r4: design review converged, with 0 MUST-FIX at r4 (rounds 1–4: 2, 2, 1, 0 MAJOR). Supersedes the r2 note on branch `card-11268-my-cards-triage` (`91f4725b`), whose
PR, thread and role sections are out of scope under the operator's 2026-10-05 decisions (card#11268
comment 9915).

Review dispositions:
- r1: F1 finished columns capped at one; F2 a column is the row's own stage id; F3 `truncated`;
  F4 the top-tier bound; F5 the `priority` contract; F6 the `remedy` primitive; F7 the inherited
  finished set. All fixed. F8 (ESOTERIC) needed no change.
- r2: F1 a `stage`-narrowed list is exempt from the one-card cap (D2 rule 5); F2 the basis for the
  top-tier exclusion is recorded (D1); F3 `per_stage` carries the `cards_by_stage` key; F4 column
  order, the dealing pool and one finished predicate are defined; F5 the stale finished slot and
  moved-in cards are stated; F6 doc surfaces are derived by grep; F7 the bridge reports an unread
  `priority` and the kanban CHECK leg is named as owed. All fixed. F8 (ESOTERIC) needed no change.
- r3: F1 a finished column is selected from its tail (D2 rule 6); F2 uncarried columns are ordered
  by rank, then id; F3 the unmapped-board growth bound is stated; F4 kanban's own DECLARE is cited
  and the owed test goes to a kanban-readable surface; F5 `priority_unread`'s population is
  defined; F6 § The default is capped is re-read in full. All fixed. The hygiene items were also
  fixed.
- r4 (0 MAJOR):
  - #1: the tail rule's per-lane bound is stated (rule 6). A lane-independent selection key was
    declined, because the row carries no "moved at" field and `updated_at` moves on any edit.
  - #2: this list gets its own remedy clause.
  - #3: a test for a budget below the column count.
  - #4: the PR is not merged, and the operator confirmation of D1's exclusion is the dispatching
    seat's to obtain.
  - #5 (ESOTERIC): no change.

## Scope (operator decisions, 2026-10-05)

1. **Cards only.** A PR appears only through its card (`pr_url`, already on every projected card).
   No PR lane, no per-PR next-move (ask 5), no card-less items (ask 6), **no GitHub read of any
   kind** — this change adds no request at all.
2. **Cut:** when the seat's cards exceed `limit`, each column gets a share, in rank order;
   finished columns get one card each; top-tier cards are never cut; per-column counts make the
   cut visible.
3. **Top tier:** kanban `priority === 1` (High). `-1` (Low) and `0` never are.

The population is unchanged: DL-459's `SeatCardScope` (assigned to the seat, plus unassigned in
its home lane). The column rank is unchanged: `BoardCardRank` (In Progress, pull, other, finished).

## D0 — columns, their order, and "finished" (one definition for D1 and D2)

- **A column is the row's own numeric `workflow_stage_id`**, carried by the board read or not; rows
  with no numeric stage form one column, `stage_id: null`.
- **Column order:** carried stages in `BoardCardRank` order, then uncarried stages by
  `(rank tier, stage id)` (the tier — In Progress, pull, other, finished — needs only the stage id and
  the mappings, so a degraded read still ranks In Progress first), then `null`. `BoardCardRank::sort` is changed to order rows the same way (today it interleaves
  uncarried stages by position), so `cards_by_stage`, `per_stage` and `triage.order` share one
  column order.
- **Finished** is one predicate, `BoardCardRank`'s (`FinishedStages` over the mappings and the
  board's declaration), asked of every numeric stage id, carried or not; `null` is unfinished.
  Its inherited bounds: on a board no writeback mapping covers, only the board's own terminal
  declaration answers (an unflagged "Shipped to dev" is not finished); on a degraded structure read
  only the mappings' own terminal ids are. Best-effort there, never refused. ⚠ On such a board the
  top-tier exclusion (D1) does not reach the unflagged column either, so High cards there are never
  cut and accumulate; DL-464 and `docs/board-tools.md` state it, and flagging the column
  `is_terminal` (or a writeback mapping) is the remedy.

## D1 — the one order, and top tier

`top tier` (in column order, then `(position, id)`), then every other card in column order, then
`(position, id)` within a column: In Progress, the pull columns, the other columns (Backlog among
them), the finished columns.

**Top tier = `priority === 1` AND the column is not finished.** The finished-column exclusion is
not new here: the reviewed r2 design defined T1 as "the seat's **unfinished** cards with
`priority === 1`", and its Q3 — "Is 'live defect' (T1) kanban's high priority (`=== 1`)?" — is the
question the operator's decision 3 answered. Ask 7's tier 1 is a *live* defect. Without it a High
card in Done would be `order[0]`, and would never be cut, so the list would grow with every shipped
High card. DL-464 records this basis, and the PR names it for the operator to confirm.

**The read is strict `=== 1`.** kanban's field is `-1 / 0 / 1` (migration comment
`-1 = low, 0 = normal, 1 = high`; writes validated `in:-1,0,1`). Its search serializer sends
`(int) $this->priority` (`V3\TaskResource`, source-read in the local kanban checkout at
`8de6c0df`). A truthiness test would put every Low card on top, which is the defect framework
card#11293 carries.

**New cross-repo field dependency.** The bridge reads no `priority` today.
- **DECLARE:** kanban already declares it — `docs/api-stability-contract.md` § 1, "Documented
  response fields — the keys named in the resource shapes (`TaskResource`, …) … stays present and
  keeps its documented type". The bridge's `docs/kanban-integration-contract.md` search rows gain
  `priority`, citing that clause, with the failure direction.
- **Bridge half:** a row whose `priority` is not an integer is read as not top tier and **counted**
  in `triage.priority_unread`. Silence would read as "no High cards"; the count says "unknown". The
  read is never refused.
- **CHECK leg (kanban's):** a kanban contract test that search rows carry an integer `priority` is
  owed on the kanban side. Nothing in this repo can red on it. It belongs on a surface kanban reads:
  a card on kanban's board, which the dispatching seat files (a builder makes no board writes). The
  bridge PR and DL-464 point at it.

**On the wire.** `cards_by_stage` is keyed by stage name, so it cannot carry "top tier first". A new
additive block does:

```jsonc
"triage": {
  "order":    [412, 377, 390, ...],  // every card in cards_by_stage, in D1's order
  "top_tier": [412, 377],            // the subset that is top tier (never cut)
  "priority_unread": 0               // of the seat's cards after `stage`, before the cut, in every
                                     // column: how many rows carried no integer priority
}
```

`order[0]` is the seat's next card. `cards_by_stage` keeps its shape.

## D2 — the cut (resolves DL-459 Decision 4; supersedes DL-365 Decision 7 for this list)

The cut applies to the seat's cards after `stage`, and only when they number more than `limit`.
A set that fits is returned whole, finished columns included.

1. **Top-tier cards are all kept** and count against `limit`. Budget `B = max(0, limit − |top|)`.
   Only non-top-tier cards are dealt.
2. **Round 1:** every column holding a non-top-tier card, in column order, gets one slot while `B`
   lasts. With `B`
   below the column count, the earliest columns get theirs.
3. **Later rounds:** one slot at a time to the **unfinished** columns only, in column order,
   skipping a column with nothing left. Over the unfinished columns the share is max-min fair, with
   ties going to the earlier column.
4. **So a finished column returns at most one card**, the operator's rule read literally. Budget
   left over once the unfinished columns are exhausted is not spent, so `returned` can be below
   `limit` while cards are hidden.
5. **A `stage`-narrowed list is one column the caller asked for by name.** It gets the whole budget,
   finished or not. Rule 4 exists to keep finished cards from crowding out live work across
   columns, and a single column has none to crowd. This keeps `stage: <Done>` returning `limit`
   cards, as it does today, and makes `stage` the way to read a finished column.
6. **Which cards a column keeps.** An unfinished column keeps its head by `(position, id)`, the PM's
   priority order. **A finished column keeps its tail**, the most recently placed cards. kanban
   appends a card moved in without an index (`TaskMutator::move` → `placeInCell(…, $index ??
   PHP_INT_MAX, …)`), and the writeback's `KanbanClient::moveCard` sends no index, so the tail is
   what was just finished. The head would be the oldest-shipped card, forever, which is DL-365 r1's
   defect. The same rule serves `stage: <Done>`: it returns the most recent `limit`, as the
   newest-id cut does today. Display order is always the board's `(position, id)`. ⚠ **Bound:**
   kanban appends within a CELL (stage × swimlane; `BoardPositionService::cellPositions`), so the
   stage-wide tail is the last card appended to the highest-positioned cell. For a seat whose
   finished cards span lanes, that is not always the card finished last.

`truncated` becomes `returned < total` for this list (it was `total > limit`). An all-top-tier set
larger than `limit` is not cut, and reads `truncated: false`.

**The bound this reopens (DL-365 Decision 1).** Top-tier cards are never cut, so this list's size is
`max(limit, |top tier|)` cards, not `limit`. A seat with many High cards gets them all, even under
`limit: 1`. That is the operator's rule; DL-464, the CHANGELOG and every surface the grep below
finds say so.

**DL-459 D4's two traps.**
- **(a)** An unmapped board with a leading column of more than `limit` cards no longer hides later
  columns. Each unfinished column gets a share, so DL-365's goal test
  (`test_my_cards_default_read_shows_the_live_column_not_a_wall_of_done`) still passes, now for the
  share's reason.
- **(b)** kanban appends a card at the END of its column, both on create and on a move without an
  index (kanban `TaskMutator` / `BoardPositionService::moveTask`). So a card new to a column holding
  more than its share is off the default read until the PM ranks it. **Accepted by the operator's
  decision 2:** `per_stage` counts it, and `stage` or `limit` reach it.
- **The same append in a finished column** is why rule 6 takes a finished column's tail.

**The window says what was cut.** `cards_window` gains `per_stage`, a list in column order of every
column the population has a card in:

```jsonc
"per_stage": [ { "stage_id": 49, "stage": "In Progress", "total": 3, "returned": 3 }, ... ]
```

- `stage` is the column's `cards_by_stage` key, from one helper (`stageLabel`) that `groupByStage`
  also calls. Two columns
  with the same name share one `cards_by_stage` key, which is pre-existing; `per_stage` keeps them
  apart by `stage_id`.
- `total` and `returned` include top-tier cards.

**The `remedy` text.** `remedy()` is one primitive for four lists. It takes the cut-description
clause as a parameter, and only this list passes the share rule's clause, which names `stage` as
the way to read a finished column. The other three keep "cut to the newest `limit`".
`ClientUpdateClauseTest` pins the lane wording, and it moves with this change. The whole list's
"how" clause is its own, too. `limit` does not "grow the response in proportion" here, because a
finished column shows more than one card only once `limit` reaches `total`.

## D3 — what does not change

- `shared_swimlane`, `tag_cards` and `coord_cards` keep DL-365 D7 (the newest ids). They are not
  the seat's cards (DL-459's population), and the operator's ruling is about the seat's set.
- Every existing key keeps its meaning; `triage` and `cards_window.per_stage` are new keys. Which
  cards a capped own-list returns changes. That is the decision, recorded in DL-464 and the
  CHANGELOG's Upgrade warnings.
- No migration, config key, route or token scope. The tool description changes, so the reference
  snapshot bumps (DL-038) and `client-capabilities.json` is regenerated.
- **Surfaces stating the old cut** are re-derived at build time, not listed here:
  `grep -rnE "newest|NEWEST|cut to|each list|highest id" app/Bridge/Tools/BoardMyCardsTool.php docs/board-tools.md examples/channel-servers/agent-webhook-bridge-channel.mjs`.
  Each hit is either this list's rule (updated) or another list's (left). **And
  `docs/board-tools.md` § The default is capped is re-read in full**, because its budget sentences
  ("It bounds ONE list", "costs no more than one description") become false for this list without
  matching the grep.

## Tests (each watched failing on `origin/dev` first)

- **Order:** a top-tier card in Backlog is `triage.order[0]`, ahead of an In Progress card.
- **Top tier:**
  - a Low (`-1`) or Normal (`0`) card is not top tier;
  - a High card in Done is not top tier;
  - a row with no `priority` is counted in `priority_unread`.
- **Cut:**
  - an overflowing set keeps a top-tier card that both the newest-id rule and its column share
    would drop;
  - every non-empty column appears when the budget covers the column count, and only the earliest
    get a slot when it does not;
  - a finished column returns exactly one card, even with budget left over, and it is the column's
    LAST by position;
  - `stage: <Done>` with more than `limit` cards returns the column's last `limit` cards;
  - a degraded structure read still lists In Progress first;
  - `per_stage` counts are exact;
  - `returned > limit` happens only through top-tier cards, and then `truncated` is false;
  - a set that fits is uncut.
- **Retired with D7 for this list:** tests asserting the newest-id survivor set on `cards_by_stage`
  are rewritten to the share rule; the shared-lane and coord versions stay.

## Out of scope, recorded

Asks 5 and 6 (PR next-move, card-less items) stay deferred on card#11268's thread. Nothing here
reads GitHub, roles, approvals or `awaiting:` labels.
