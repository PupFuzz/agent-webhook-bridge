# GitHub-PR → kanban card-move writeback (FR #2016)

The bridge can keep a kanban card in sync with its PR's lifecycle **deterministically, with no agent in the loop** — a GitHub `pull_request` webhook moves the card to a stage, and a branch-create `push` promotes the card to In Progress (DL-160). This is the bridge's only *writeback* (it is otherwise surface-only / one-way). Design + rationale: `CLAUDE_DECISIONS.md` DL-009 (the seam) → DL-018/019/020/021 (the implementation) → DL-160 (the branch-create → In-Progress trigger).

## How it works

1. GitHub POSTs a `pull_request` **or `push`** webhook → the bridge's github receiver (HMAC-verified like any event).
2. A github-subscribed agent runs `GitHubPrCardMoveClassifier`, which:
   - **`pull_request`** → derives the **outcome** from GitHub-controlled fields (`opened`/`reopened` → `opened`; `closed`+merged to `main` → `merged_to_main`; `closed`+merged to another base → `merged`; `closed`+not-merged → `closed_unmerged`) — never the PR title; finds the card by the `DL-NNN` token in the PR title / head branch.
   - **`push` that CREATED a branch** (`payload.created === true`) whose ref carries a `DL-NNN` → outcome **`started`** (codifies "work has begun" from the artifact — the branch). Fires once on branch creation (a later push to the same branch is a no-op); a `dependabot/*` branch or a ref with no `DL-NNN` is ignored. The card is found by that `DL-NNN`, matched against the mapped board's `dl_number`.
   - emits a `kanban_move_card` durable reaction per correlated card (or no-ops if the repo is unmapped / no `DL-NNN` / no matching card).
3. `KanbanMoveCardHandler` (durable) moves the card — board + stage come **only** from your `writeback.json` (keyed on the outcome), it **refuses** a card not on the mapped board, and it is idempotent (no-op if already there). The `started` outcome additionally enforces a **no-regression guard** (see below): it only promotes a card currently in one of the mapping's `started_from_stages` (Backlog/Prioritized), never dragging an already-progressed card backward.

## Setup (operator)

### 1. A least-privilege writeback token
Create a kanban API token scoped to **card moves on the mapped boards** (NOT the broad provisioning token), and place it:
```bash
install -m 600 /dev/stdin "$BRIDGE_DIR/kanban/writeback-token" <<<'<the-token>'
```
The writeback acts as this token's kanban user — note that user's `user_id`. **That user MUST be a member/owner of every mapped board** — kanban-board scopes card search/read to the token-user's accessible boards, so a writeback user not on the board makes correlation silently return nothing. This used to fail invisibly; as of DL-026 it's caught: `bridge:check` probes that the token can see each mapped board (0 cards ⇒ a loud warning), and at runtime a 0-card board read logs a `warning` instead of silently no-opping.

### 2. `writeback.json` (in the config dir)
```jsonc
// $BRIDGE_DIR/writeback.json   (chmod 600)
{
  "identity_id": 4242,                 // the writeback user's kanban user_id (auto echo-suppressed)
  "mappings": {
    "your-org/your-repo": {
      "board_id": 8,
      "stages": {                      // outcome → workflow_stage_id (on board_id)
        "started": 49,                 // In Progress (branch-create push, DL-160)
        "opened": 50,                  // In Review
        "merged": 52,                  // Shipped to dev
        "merged_to_main": 53,          // Released to main
        "closed_unmerged": 49          // In Progress
      },
      "started_from_stages": [46, 47]  // DL-160 — Backlog/Prioritized stage ids the
                                       //   `started` move may promote a card FROM
    }
  }
}
```
Absent ⇒ writeback off. Malformed ⇒ fail-closed (`bridge:check` reports it). Every stage id must be a real stage **on that board** (a cross-board id is refused by kanban and logged, not retried).

### Branch-create → In Progress (DL-160)

A GitHub `push` that **creates a branch** whose ref carries a `DL-NNN` (e.g. `feat/DL-160-x`) promotes the correlated card to the **`started`** stage — "work has begun" derived from the artifact, no agent involved. It is gated three ways so it can only ever advance a card forward:

- **Fires once, on creation** (`payload.created === true`) — a subsequent push to the same branch is a no-op.
- **No-regression guard.** The move is applied **only** when the card's current stage is in the mapping's **`started_from_stages`** (the board's Backlog/Prioritized stage ids). A card already in In-Review/Shipped/Released is left untouched, so re-creating or force-pushing an old branch never drags it backward. `started_from_stages` is parsed strictly (a numeric list — a non-list / non-numeric element fails the config closed). **If `started_from_stages` is absent, a `started` move is refused** (the guard can't know what's safe to promote from) — set it to enable the trigger.
- A `dependabot/*` branch, or a ref with no `DL-NNN`, emits no target.

To use it you must (a) map a `started` stage **and** set `started_from_stages`, and (b) subscribe the repo webhook to **push** events (see step 4).

> A mapping can also opt into **dependabot cards** (`create_dependabot_cards`) so dependency-update PRs auto-create cards — see the **Optional: dependabot cards** section below.

### 3. A github-subscribed agent using the classifier
```yaml
# $BRIDGE_DIR/writeback-agent.yml
identity:
  github_user_id: 99999999            # the bot account GitHub delivers the webhook as
subscriptions:
  - provider: github
    scopes: ["your-org/your-repo"]
classifier:
  class: App\Bridge\Classifiers\GitHubPrCardMoveClassifier
```

### 4. The repo webhook (one-time, in GitHub)
The bridge does **not** provision GitHub webhooks — `bridge:provision` manages only the `kanban` provider's subscriptions (it skips GitHub by design: no repo-admin token). So the repo webhook, **including which events it sends**, is configured by hand in GitHub and is the operator's responsibility. In the repo's **Settings → Webhooks**, add a webhook:
- **Payload URL:** `<BRIDGE_RECEIVER_BASE_URL>/webhooks/github?b=your-org/your-repo`
- **Content type:** `application/json`
- **Secret:** the per-scope HMAC secret at `$BRIDGE_DIR/github/webhook-secret-scope-your-org%2Fyour-repo`
- **Events:** **Pull requests** — and, to enable the branch-create → In-Progress trigger (DL-160), **Pushes** as well. (Choose "Let me select individual events" and tick both. A webhook subscribed to *Pull requests* only will silently never fire the `started` move.)

### 5. Verify
```bash
php artisan bridge:check        # validates writeback.json + the writeback token,
                                # and probes that the token can SEE each mapped board
```
`bridge:check` probes each mapped board with the writeback token via a cheap `limit=1` read of the pagination `meta.total` (DL-029): it reports the visible card count and warns loudly if it sees **0 cards** (the token's user is likely not a board member, or `board_id` is wrong — the writeback would silently no-op). In `scan` correlation mode it also warns if the board is larger than the scan ceiling (`MAX_PAGES × 200` = 10,000 cards) and points at `BRIDGE_WRITEBACK_CORRELATION=ref`. If a mapping pins a `swimlane_id`, it also checks the lane actually exists on that board. It also warns when a mapping is **orphaned** (#2162) — no agent runs a writeback-emitting classifier (`EmitsWritebackReactions`, i.e. `GitHubPrCardMoveClassifier`) subscribed to that repo's github scope, so the mapping is inert and no card would ever move. All warn-level — a genuinely-empty new board, or a mapping added before its agent, won't fail the check.

Open/merge a PR whose title or branch carries `DL-NNN` matching a card's `payload.dl_number` (the card's `dl_number` custom field, populated by your board automation when the card is created); the card moves. `php artisan bridge:inspect <event_id>` shows the dispatch + any logged refusal/no-op.

## Optional: dependabot cards (DL-024)

By default the writeback only **moves an existing card** correlated by a `DL-NNN`. Dependabot PRs carry no `DL-NNN` and have no card, so dependency updates never appear on the board. Set **`create_dependabot_cards: true`** on a mapping and the bridge will **create a card when a dependabot PR opens** and carry it through the same lifecycle on close — keyed by **PR number** (no DL needed). It builds on the base setup above (token, the repo webhook subscribed to *Pull requests*, the classifier agent); it just adds the one flag.

```jsonc
// $BRIDGE_DIR/writeback.json
{
  "identity_id": 4242,
  "mappings": {
    "your-org/your-repo": {
      "board_id": 8,
      "stages": {
        "opened": 50,                  // ← a created dependabot card lands here
        "merged": 52,
        "merged_to_main": 53,
        "closed_unmerged": 49
      },
      "create_dependabot_cards": true, // opt-in (default false)
      "swimlane_id": 31                // optional — lane for CREATED cards (see below)
    }
  }
}
```

**Detection.** A PR is treated as dependabot when its head branch is in dependabot's own namespace (`dependabot/*`) — a GitHub-controlled field, never the title.

**Lifecycle** — the same `stages` map drives it, correlated on `payload.pr_number`:

| PR event | Card exists? | Action |
| --- | --- | --- |
| opened / reopened | no | **create** at the `opened` stage |
| merged to `dev` | yes | move to `merged` |
| merged to `main` | yes | move to `merged_to_main` |
| closed, not merged | yes | **archive** the card (DL-161) |
| any move | already at target | no-op (idempotent) |
| merged / merged_to_main | no (open was missed) | create at that stage |
| closed, not merged | no | **skip** — don't mint a card just to close one we never tracked |

**Closed-unmerged dependabot PRs archive the card (DL-161).** Dependabot routinely closes its own PRs (a newer bump supersedes an older one, or a maintainer closes it), so a closed-unmerged dependabot card is dead weight. It is **archived** (retired off the board), not moved to a column — so it needs **no `closed_unmerged` stage mapping** (that key is ignored for the dependabot path). Archiving uses the kanban lifecycle verb (`PATCH {"_action":"archive"}`), and the bridge checks the response confirms it (a field-write `archived_at` PATCH silently no-ops); a 200-that-didn't-archive is logged loudly and skipped (never retried — that failure is deterministic). Idempotent: an archived card is excluded from correlation, so a redelivered close finds nothing and no-ops. (The DL-tracked move path is unchanged — a closed-unmerged *DL* PR still just moves, since work there typically continues.)

**New cards** are tagged `dependencies` + `triaged` (so routine dependency churn doesn't flood a triage sweep) and carry `payload.pr_number`, `payload.pr_url`, and `payload.origin = "dependabot"`.

**Token.** The writeback user must be able to **create** tasks on the board — i.e. write access + board membership, the same it already needs for moves. No extra config beyond the flag.

## Optional: pin created cards to a swimlane (DL-027)

Add **`swimlane_id`** to a mapping to land every card the writeback *creates* in a specific lane (e.g. one swimlane per source repo on a shared board). It applies **at creation only** — it never moves an existing card between lanes, so a human re-lane is preserved and a re-delivery won't yank a card back. Absent ⇒ the board assigns its default lane (today's behavior, unchanged).

```jsonc
"your-org/your-repo": {
  "board_id": 8,
  "swimlane_id": 31,                 // created cards go in this lane
  "stages": { "opened": 50, "merged": 52, "merged_to_main": 53, "closed_unmerged": 49 },
  "create_dependabot_cards": true
}
```

It is **strict** like `board_id`/`stages`: a non-numeric value fails `writeback.json` closed (no silent fallback to the default lane). `bridge:check` validates it against the board's actual lanes — a deleted lane, or one that lives on a *different* board, warns that created cards would `422` and silently no-op until fixed. (Only the dependabot-card path creates cards today; a DL-correlated card is created by your board automation, not the writeback.)

**Idempotency.** Correlation is by `payload.pr_number`, so a redelivered or reopened event never duplicates a card. (A rare *concurrent* duplicate `opened` delivery could create two — there's no unique constraint on `pr_number`; acceptable at dependabot's rate.)

**Worked example.** With the mapping above, dependabot opens `chore(deps): Bump x from 1 to 2` (PR #77, head `dependabot/composer/x-2.0`) → a card *"chore(deps): Bump x from 1 to 2"* appears on board 8 in **In Review** (50), tagged `dependencies`/`triaged`, with `payload.pr_number: 77`. When it auto-merges to `dev`, the card moves to **Shipped to dev** (52). `php artisan bridge:inspect <event_id>` shows each create/move.

## Failure behaviour (what retries vs not)

- **Transient** (kanban 5xx/timeout, a not-yet-placed token) → the webhook **5xx**s and kanban-board redelivers; the move retries once it's fixed.
- **Permanent** (no mapping, no stage, a malformed payload, a kanban **4xx** like a deleted card or a cross-board stage, or the card isn't on the mapped board) → **logged + no-op**, the webhook acks 200 (a refused/un-actionable move is not a delivery failure — it would only retry-storm).

### Diagnosing a silent writeback (DL-026)

A writeback that "has no agent in the loop" can fail in two ways that **don't** error — they look identical to "nothing to do" — so the bridge now makes them loud (not as a 5xx; a genuine no-match still stays quiet):

- **Blind / degraded token (0 visible cards).** If the writeback token's user loses board membership (token rotation) or `writeback.json` has a wrong `board_id`/instance, kanban answers `200` with empty data. Every correlation then resolves to "no card" → moves silently no-op, **and** for `create_dependabot_cards` mappings the handler would *create a duplicate card* (it can't see the existing one). Caught both at config time (`bridge:check` 0-card probe) and at runtime (a `warning` log on the 0-card read).
- **Correlation mode `ref` vs `scan` (DL-029; default `ref` since DL-031).** `BRIDGE_WRITEBACK_CORRELATION` selects how the writeback finds a PR's card(s). **Default `ref`**; set `scan` for backwards compatibility or a kanban that predates `by-ref`. **⚠ Upgrading:** a `ref`-default bridge requires its kanban to be **v0.17.2+ and backfilled** (`php artisan kanban:backfill-external-references`) — else set `BRIDGE_WRITEBACK_CORRELATION=scan`. `bridge:check` probes `by-ref` reachability in `ref` mode and warns loudly if the kanban can't serve it, so a wrong default surfaces before any traffic.
  - `scan` (fallback): walks `/tasks/search.json` page by page (200/page) and digit-matches `payload.dl_number`/`pr_number` client-side. O(board size); a hard `MAX_PAGES`(50) ceiling bounds a runaway upstream, and a board beyond ~10,000 live cards would miss correlations past it (warned by `bridge:check`). Works against any kanban.
  - `ref`: one indexed `GET /boards/{b}/tasks/by-ref.json` per key (kanban DL-147/148) — server-canonicalized, O(1), no paging/ceiling. **Requires the kanban instance to expose `by-ref` AND its `task_external_references` to be backfilled** (`php artisan kanban:backfill-external-references`). Flip an install to `ref` only after confirming both (`bridge:check`).
- **One PR/DL can track multiple cards (kanban DL-148).** `by-ref` returns a collection and the scan returns all matches, so the writeback moves **every** correlated card (e.g. two FRs bundled in one PR). Each is a separate move target keyed by card id.

## Security notes

- Board + stage are **operator config only**, keyed on GitHub-controlled fields — the webhook body can't choose a board or stage. The worst an attacker-influenced PR can do (via title correlation) is nudge a card *that genuinely sits on the mapped board* to a *config-mapped stage* — bounded, reversible, logged.
- The `started` trigger (DL-160) keys on `payload.created` + `payload.ref` (GitHub-controlled, not body-spoofable to the bridge — they ride a HMAC-verified delivery), and the move is **doubly bounded**: it only ever advances a card *that sits on the mapped board* *from a configured `started_from_stages`* to the *config-mapped `started` stage*. Worst case from a maliciously-named branch is the same bounded, reversible forward nudge as the PR path — and only for a card already in Backlog/Prioritized.
- The writeback token is least-privilege, `0600`, read fail-closed at point-of-use, never logged.
- The writeback identity is auto echo-suppressed (its `card_updated` webhook doesn't loop back).
