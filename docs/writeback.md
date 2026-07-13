# GitHub-PR → kanban card-move writeback (FR #2016)

The bridge can keep a kanban card in sync with its PR's lifecycle **deterministically, with no agent in the loop** — a GitHub `pull_request` webhook moves the card to a stage, and a branch-create `push` promotes the card to In Progress (DL-160). This is the bridge's only *writeback* (it is otherwise surface-only / one-way). Design + rationale: `CLAUDE_DECISIONS.md` DL-009 (the seam) → DL-018/019/020/021 (the implementation) → DL-160 (the branch-create → In-Progress trigger).

## How it works

1. GitHub POSTs a `pull_request` **or `push`** webhook → the bridge's github receiver (HMAC-verified like any event).
2. A github-subscribed agent runs `GitHubPrCardMoveClassifier`, which:
   - **`pull_request`** → derives the **outcome** from GitHub-controlled fields (`opened`/`reopened` → `opened`; `closed`+merged to `main` → `merged_to_main`; `closed`+merged to another base → `merged`; `closed`+not-merged → `closed_unmerged`) — never the PR title; finds the card by the `DL-NNN` token in the PR title / head branch.
   - **`push` that CREATED a branch** (`payload.created === true`) whose ref carries a `DL-NNN` → outcome **`started`** (codifies "work has begun" from the artifact — the branch). Fires once on branch creation (a later push to the same branch is a no-op); a `dependabot/*` branch or a ref with no `DL-NNN` is ignored. The card is found by that `DL-NNN`, matched against the mapped board's `dl_number`.
   - emits a `kanban_move_card` durable reaction per correlated card (or no-ops if the repo is unmapped / no `DL-NNN` / no matching card).
   - **(opt-in) `draft_overlay`** → additionally emits a `kanban_block_reason` durable reaction that mirrors the PR's *draft* state onto the card's `block_reason` (overlay only, **no stage move**) — see the *PR draft → `block_reason` overlay* section below.
3. `KanbanMoveCardHandler` (durable) moves the card — board + stage come **only** from your `writeback.json` (keyed on the outcome), it **refuses** a card not on the mapped board, and it is idempotent (no-op if already there). The `started` outcome additionally enforces a **no-regression guard** (see below): it only promotes a card currently in one of the mapping's `started_from_stages`, never dragging an already-progressed card backward — and it **refuses a pinned card** (non-empty `block_reason` or a `no-automove` tag) regardless of stage.
   - **No-regression guard on the PR outcomes too (DL-163).** A stale or redelivered `pull_request` event — or a **release PR whose title carries a card's `DL-NNN`** — can re-fire an outcome on a card that has already advanced past it. The handler refuses any move that would drag a card **backward** in the board's workflow order (e.g. `opened`→In-Review on a card already Released, or a redelivered `merged` on a Released card). `closed_unmerged` is the one **legitimately backward** outcome (an abandoned PR returns its In-Review card to In-Progress), so it is allowed to regress **unless** the card has already reached a terminal (`merged`/`merged_to_main`) stage. The order is read from the board (preload); if it can't be read, the move proceeds (fail-open — the guard never blocks the writeback on missing order data). No config needed. *Mitigation that is now belt-and-braces, not required: keeping `DL` tokens out of release-PR titles avoids the spurious `opened` move in the first place.*

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
      "started_from_stages": [46, 47]  // DL-160 — stage ids the `started` move may
                                       //   promote a card FROM. Include the board's
                                       //   Held stage id to auto-promote a parked
                                       //   card on branch-create (contract PR #113).
    }
  }
}
```
Absent ⇒ writeback off. Malformed ⇒ fail-closed (`bridge:check` reports it). Every stage id must be a real stage **on that board** (a cross-board id is refused by kanban and logged, not retried).

> **`closed_unmerged` — In Progress vs a "Won't Do" terminal (operator choice).** The example maps `closed_unmerged → In Progress` because a closed-unmerged **DL-tracked** PR usually means *work continues* (rework, not abandonment). If your board has a **terminal "Won't Do" / "Cancelled" column** (`lane_type: done`, positioned far-right) and you'd rather treat a closed-unmerged PR as an *abandon-disposition* (dependabot dismissals, `[DO NOT MERGE]` diagnostics, superseded/rejected PRs), map `closed_unmerged → <that stage id>` instead. The no-regression guard (DL-163) **allows this terminal move** — it special-cases `closed_unmerged` and never applies the forward-only check to it, so a card in In-Review moving to a far-right Won't-Do terminal is permitted (it's a disposition, not a regression), and once there the card is sticky (no stale PR event can drag it back out). The **dependabot** path is unaffected either way — a closed-unmerged dependabot card always **archives** (DL-161), ignoring this mapping.

### Branch-create → In Progress (DL-160)

A GitHub `push` that **creates a branch** whose ref carries a `DL-NNN` (e.g. `feat/DL-160-x`) promotes the correlated card to the **`started`** stage — "work has begun" derived from the artifact, no agent involved. It is gated three ways so it can only ever advance a card forward:

- **Fires once, on creation** (`payload.created === true`) — a subsequent push to the same branch is a no-op.
- **No-regression guard.** The move is applied **only** when the card's current stage is in the mapping's **`started_from_stages`** (the board's Backlog/Prioritized stage ids, and optionally **Held** — see below). A card already in In-Review/Shipped/Released is left untouched, so re-creating or force-pushing an old branch never drags it backward. `started_from_stages` is parsed strictly (a numeric list — a non-list / non-numeric element fails the config closed). **If `started_from_stages` is absent, a `started` move is refused** (the guard can't know what's safe to promote from) — set it to enable the trigger.
- **Held auto-promote + pinned opt-out (contract PR #113).** To un-park a **Held** card automatically when a branch is created for it, add the board's Held stage id to `started_from_stages`. The `created === true` classifier gate already makes this safe against re-fires — a re-push/force-push never emits `started`, only a genuine branch birth does. The escape hatch: a card a human wants to keep parked is **never** auto-promoted if it carries a non-empty `block_reason` **or** a `no-automove` tag — the handler refuses the move (loud `warning` + an `alert_channel` signal), regardless of stage. This mirrors the toolkit `board-card-start` hook's local half of the same contract.
- A `dependabot/*` branch, or a ref with no `DL-NNN`, emits no target.

To use it you must (a) map a `started` stage **and** set `started_from_stages`, and (b) subscribe the repo webhook to **push** events (see step 4).

> **⚠ Upgrade ordering — deploy the v0.37.0+ code BEFORE adding `started` to `writeback.json`.** `started` is an outcome a pre-v0.37.0 bridge does not recognize: its `WritebackConfig` rejects an **unknown stage outcome**, which is a *malformed-config* error — and a malformed `writeback.json` fails **closed for every mapping in the file**, not just the one you edited. So adding `started` while an older bridge is still serving (e.g. between `git pull` and an FPM reload, or on a host where you edited config first) silently disables your *entire* writeback until the new code is running. Sequence it: deploy + reload → `bridge:check` green → *then* edit `writeback.json` → `bridge:check` again.

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
`bridge:check` probes each mapped board with the writeback token via a cheap `limit=1` read of the pagination `meta.total` (DL-029): it reports the visible card count and warns loudly if it sees **0 cards** (the token's user is likely not a board member, or `board_id` is wrong — the writeback would silently no-op). In `scan` correlation mode it also warns if the board is larger than the scan ceiling (`MAX_PAGES × 200` = 10,000 cards) and points at `BRIDGE_WRITEBACK_CORRELATION=ref`. If a mapping pins a `swimlane_id`, it also checks the lane actually exists on that board. It also warns when a mapping is **orphaned** (#2162) — no agent runs a writeback-emitting classifier (`EmitsWritebackReactions`, i.e. `GitHubPrCardMoveClassifier`) subscribed to that repo's github scope, so the mapping is inert and no card would ever move. It warns when the branch-create `started` trigger is **half-configured** (#2652) — exactly one of `stages.started` / `started_from_stages` set, which leaves the trigger silently inert — and when any **mapped stage id** (a `stages.*` value or a `started_from_stages` id) is **not on the board** (a typo that would 422 the move, or make the `started`/no-regression guard silently never match). All warn-level — a genuinely-empty new board, or a mapping added before its agent, won't fail the check.

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

**Required board custom fields (DL-162).** A created card's payload sets the custom-field keys **`pr_number`, `pr_url`, `origin`** — every one of these must be **registered as a custom field on the target board**. Kanban 422s a payload with an unregistered key, and the handler treats that 4xx as permanent (logs + no-ops), so a board missing even one field makes **every** dependabot-card create vanish silently (delivery still returns 200). `bridge:check` verifies this up front and warns, naming the missing field(s), when `create_dependabot_cards` is on but the board lacks them — fix it by adding the fields on the board (or setting `create_dependabot_cards: false`).

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

**Idempotency (DL-166).** Correlation is by `payload.pr_number`, so a redelivered or reopened event never duplicates a card. The correlate→create steps aren't atomic, though, so two *concurrent* deliveries for one PR (`opened` + `reopened`, or a fresh-`delivery_id` re-emit) can each correlate empty and each create — a check-then-create race (seen live: two cards for one PR). The handler closes it by **collapsing on the (repo, PR) key**: right after a create it re-correlates and, if more than one card matches **for this repo**, keeps the **lowest id** (a deterministic survivor, so racing workers converge) and **archives the rest**. The same collapse runs on the move path, so any duplicate minted before this shipped is retired on the PR's next non-terminal event. Net: at most one live card per dependabot PR.

> **`card#<task-id>` correlation (FR-7 / framework v0.2.229, DL-177).** A PR whose **title or head branch** carries `card#<id>` (case-insensitive on `card`) moves that card by its **native kanban task-id** — the channel for cards with no DL number. No `source` qualifier, no classify-time kanban read: native ids are globally unique, and the durable handler already rejects a card not on the operator-mapped board and applies the core no-regression guard + `*_from_stages` allowlists exactly as for a DL move. The token only *selects* the card — board + stage always come from the repo's `writeback.json` mapping, never PR text. `pr_number` stays the orthogonal PR-first (dependabot) path.

> **Correlation-key stamping on the `card#` path (DL-187).** A card moved by `card#` is one that carries no `dl_number`/`pr_number` — which is exactly what `promote-released-cards` correlates released cards by, so such a card strands in Shipped-to-Dev at release time. On the `card#` path **only**, the writeback therefore stamps the card's `payload.dl_number`/`pr_number` **add-if-missing** (never overwriting an existing/human-set value) as a step distinct from the column-only move: the `dl_number` when the title+branch carries **exactly one** `DL-NNN` (a bundled/release-shaped PR with 2+ DLs, or a foreign DL, stamps `pr_number` only — never a wrong DL), stored canonical zero-padded (`DL-%04d`); the `pr_number` from the PR. A **DL-resolved** move stamps nothing — its card already carries the `dl_number` that resolved it. The stamp is best-effort with the move's transient/permanent split (a 4xx like "board has no such custom field" is logged + skipped; a 5xx propagates so redelivery re-stamps) and runs only once the card is legitimately at/entering its target stage (never from a guard-rejected event). This makes `kbcard --dl` at decision-time unnecessary going forward for `card#`-tracked cards.
>
> **Precedence — try-in-order-with-fallback (framework #112, DL-179).** A PR/branch MAY carry both card-first tokens, and the resolver keys on the *outcome* of a token, not its presence: **(1)** `DL-NNN` **resolves** to a card → it wins (a co-present `card#` is logged as ignored — a ref naming two cards is almost always an operator error); **(2)** `DL-NNN` **unresolved** → fall through to a present `card#` (native-id move); **(4)** a token was present but **nothing resolved** and there is no `card#` fallback → a **high-value miss**, warned loudly, never a silent no-op. Committing to the DL the moment it is *present* (rather than when it *resolves*) was the live dead-end (DL-179): a `DL`-titled PR against an as-yet-unstamped card resolved nothing and never tried the `card#` that would have matched. The `card#` fallback stays **board-scoped** by the durable handler's existing board-membership guard (`card_not_on_mapped_board`), which gates DL and `card#` moves identically — so the classifier stays classify-time-read-free.

> **Shared-board safety (DL-167, refined by DL-174).** A bare PR/DL number collides when several repos map to **one board** (a `swimlane_id` lane per repo — see above): a same-numbered PR/DL in another repo correlates too. On a **shared** board (two or more repo mappings targeting one `board_id`) the bridge passes the event's **repo as the kanban `source` qualifier** (kanban DL-163, requires kanban **v0.21.0+**) so in `ref` mode the server returns only **this repo's** card(s) — for both the dependabot path *and* the DL move path. On a **1:1 board** the qualifier is **omitted** (DL-174): there is no collision to disambiguate, and kanban's `source` filter is strict, so qualifying would exclude every card whose derived source is null (any operator-stamped `dl_number`/`pr_number` card with no `pr_url`/`repo` field) — the silent never-self-moves failure #3399 diagnosed. In `scan` mode (legacy, no `source`), the dependabot handler still attributes each correlated card by the `github.com/<owner>/<repo>/pull/` segment of its `pr_url` and drops a co-hosted repo's identically-numbered card. Either way, a foreign repo's collision is never moved or archived. (Against a pre-v0.21.0 kanban the `source` key is ignored → any-source behavior, same as before.)

**Worked example.** With the mapping above, dependabot opens `chore(deps): Bump x from 1 to 2` (PR #77, head `dependabot/composer/x-2.0`) → a card *"chore(deps): Bump x from 1 to 2"* appears on board 8 in **In Review** (50), tagged `dependencies`/`triaged`, with `payload.pr_number: 77`. When it auto-merges to `dev`, the card moves to **Shipped to dev** (52). `php artisan bridge:inspect <event_id>` shows each create/move.

## Optional: PR draft → `block_reason` overlay (DL-193)

Set **`draft_overlay: true`** on a mapping and the writeback mirrors a PR's **draft** state onto its correlated card's **`block_reason`** field — so a card whose PR is a draft is visibly *blocked*. This is an **overlay only**: it writes one field, it **never moves the card** between stages/columns. It rides the same DL/`card#` correlation as the move path (move ALL matching cards, one-to-many), and off/absent ⇒ the draft actions are ignored (byte-identical to today).

```jsonc
"your-org/your-repo": {
  "board_id": 8,
  "stages": { "opened": 50, "merged": 52, "merged_to_main": 53, "closed_unmerged": 49 },
  "draft_overlay": true              // opt-in (default false)
}
```

**Triggers** (all driven by `pull_request` events for the mapped repo):

- **`converted_to_draft`** → **SET** `block_reason`.
- **`opened` / `reopened` with `pull_request.draft === true`** → **SET** `block_reason`. (Covers a PR *born* a draft — GitHub sends `opened` with the draft flag, not `converted_to_draft`.) The existing **`opened` move still fires unchanged** in addition — the card moves to In Review *and* gets the block_reason; the overlay only adds the set.
- **`ready_for_review`** → **CLEAR** `block_reason`.
- Every other action is unchanged.

**Data-preservation (load-bearing — must not stomp a human's `block_reason`).** Both operations GET the card first:

- **SET = add-if-missing.** The marker `"PR is in draft"` is written **only when `block_reason` is empty** (null / blank). If the card already has *any* reason — a human's, or our marker already there — it is **left untouched** (idempotent).
- **CLEAR = clear-if-ours.** `block_reason` is nulled **only when its current value is exactly the marker** `"PR is in draft"`. A human-set reason is left intact.

**DL-178 pin interaction (intended).** A non-empty `block_reason` **pins** the card (PinGuard, DL-178), so a **`started`** (branch-push) auto-promote is refused while the PR is a draft; clearing on `ready_for_review` releases the pin. This is desired — a drafted card is gated against the branch-push promote. (The pin is consulted **only** on the `started` outcome; the four PR-outcome moves are gated by the no-regression stage order, not the pin — and GitHub blocks merging a draft PR, so `merged` can't fire on a still-drafted card regardless.) No change to PinGuard.

To use it: set `draft_overlay: true` on the mapping and subscribe the repo webhook to **Pull requests** (which already carries the `converted_to_draft` / `ready_for_review` / `opened` actions — no extra event class needed). Same durable, transient(5xx→retry)/permanent(4xx→log+no-op) split and belongs-to-mapped-board guard as the move handler.

## Optional: a loud alert on a permanent move-failure (FR-4)

By default a **permanent** move-failure (a refused/un-actionable move — see *Failure behaviour* below) is **logged + no-op**: a durable record in the log, but no live signal. Add a top-level **`alert_channel`** to `writeback.json` to ALSO emit a loud per-event signal to a local channel when that happens — log = durable record, push = live wake. Opt-in; absent ⇒ log-only (unchanged).

```jsonc
{
  "identity_id": 4242,
  "alert_channel": { "socket": "/run/user/1000/agent-webhook-bridge-channel-ops.sock" },
  // ── OR ──
  "alert_channel": { "url": "http://127.0.0.1:9931/", "auth": { "token_path": "/abs/path/to/token" } },
  "mappings": { /* … */ }
}
```

`socket` and `url` are **mutually exclusive** (exactly one), mirroring an agent's `channel` config. The signal body is one line: `{"type": "writeback_move_failed", "repo": <repo>, "outcome": <outcome>, "card_id": <id|null>, "reason": <reason>}`.

**Which failures signal.** Only the **`Log::warning` permanent branches** — the ones that indicate a real misconfiguration or a refused move — fire a signal:

| Branch | `reason` | Signals? |
|---|---|---|
| `payload.card_id` not an integer | `card_id_not_int` | ✅ |
| `payload.repo`/`outcome` not non-empty strings | `repo_or_outcome_invalid` | ✅ |
| writeback not configured (no `writeback.json`) | `writeback_not_configured` | ⚠ degrades to log-only (see below) |
| no mapping for repo (`Log::info`) | — | ❌ (expected "not tracked") |
| no stage mapped for outcome (`Log::info`) | — | ❌ (expected "not tracked") |
| `getCard` refused by kanban (4xx) | `getcard_4xx` | ✅ |
| card not on the mapped board (security refusal) | `card_not_on_mapped_board` | ✅ |

The "not tracked" `Log::info` branches stay **quiet** — they're the normal case for an event the operator simply hasn't mapped, not a failure.

**Branch-#3 degradation (log-only).** The `writeback_not_configured` branch fires when there is no `writeback.json` at all — so there is also no `alert_channel` to load. That branch is therefore inherently **log-only**: the notifier loads its config from the same `writeback.json` and finds nothing, so it no-ops. (Place a `writeback.json` even if you only want the alert channel and no mappings, and the other branches signal.)

**Dedup — once per `(repo, outcome, reason)`.** A recurring failure (the same event redelivered, or a persistent misconfig) alerts **once**, not per delivery. Dedup is an atomic `O_EXCL` marker file under `<state_dir>/writeback-alerts/<sha1(repo, outcome, reason)>`. Remove the marker (or the directory) to re-arm a signature. A *failed* push releases the marker so a later redelivery re-attempts — a channel that was down when the first signal fired never permanently silences that signature (at the cost of a possible duplicate on a redelivery race).

**Best-effort, never breaks the move.** The push is wrapped so an undeliverable alert (channel down, bad config, HTTP error) is caught and logged — it never throws, so it can't turn a permanent no-op into a 5xx redelivery storm. The log line always runs regardless of whether the push succeeds. There is **no timer/poll/watchdog** — the signal is emitted inline, event-driven, on the failing delivery only. `bridge:check` warns on a malformed `alert_channel` (both/neither of socket+url, a missing socket parent dir, or a non-loopback url).

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

## Reconciliation — `bridge:reconcile` (DL-183)

The writeback is **event-driven**, and GitHub delivers each webhook **exactly once with no auto-retry**. So if the bridge is down during a PR event (a deploy, an outage), that card's move is lost and nothing re-drives it — the only backstop was the manual `board-session-close`. `bridge:reconcile` is the **rerunnable backstop**: it recomputes each tracked card's *expected* stage from **GitHub ground truth** (GET the PR, read its state/merged/base) and reports — or, with `--fix`, applies — the drift. This makes card movement **eventually consistent** (closes RC-B from the 2026-06-05 writeback-drift RCA).

```bash
php artisan bridge:reconcile                     # REPORT-ONLY: one line per drifted card + summary counts (exit 0)
php artisan bridge:reconcile --fix               # apply the forward moves
php artisan bridge:reconcile --repo owner/repo   # reconcile only one writeback.json mapping
php artisan bridge:reconcile --fix --max-moves=20   # safety cap (default 20)
```

**Reads PR state from GitHub → needs a github read token.** The **kanban-board repo is private**, so a `repo`-scoped read token is required in practice (a fine-grained read-only PAT is preferred — reconcile only reads). The token is resolved **per repo** (DL-185: a `[git-credential-map]` routes each repo to its own least-privilege PAT), in precedence order:

1. **`bridge.providers.github.token_path`** (env `BRIDGE_GITHUB_TOKEN_PATH`) — an explicit path to the token file. Point it at a centralized credential (e.g. `~/.config/coord/github-pat`) to reuse it without a per-install symlink. **When set it is authoritative**: a missing/blank file fails loud (no fallback), so a wrong path never silently resolves a different credential.
2. **`<secret_dir>/github/token`** — the conventional per-provider path (same convention `bridge:provision` uses; **not** the dedicated kanban writeback token), when no override is set.
3. **store-native — `git-credential-coord` + `[git-credential-map]`** (DL-185) — the default when no explicit token file is placed. `bridge:reconcile` calls the framework credential helper (`bridge.providers.github.credential_helper`, env `BRIDGE_GITHUB_CREDENTIAL_HELPER`, default `git-credential-coord`) with the repo's `host/owner/repo`; the store's `[git-credential-map]` (most-specific-first) selects the `[github]` key → a per-repo, least-privilege PAT with no second token copy to rotate. An **unmapped** repo (empty result) falls through to `GH_TOKEN`; a `REPLACE_ME` placeholder, an unreadable `*_file`, or a helper crash **fail loud** (never a silent fall-through to a wrong-scoped token). The helper is spawned inheriting the reconcile CLI env, so it needs `HOME`/`COORD_CREDENTIALS` to locate the store — fine for an interactive operator run (if you ever wire reconcile to a timer, set them in the unit). Set `credential_helper` empty to disable this leg. **A placed token file (leg 1 or 2) short-circuits the store map** — use a file *or* the store map for a repo, not both.
4. **`GH_TOKEN` env** — the last leg, used only when no override/file is set and the store returns nothing. It is present in an operator shell (`~/.bashrc`) but **not** in the webhook-spawned receiver, so it self-scopes to the reconcile CLI; it can never shadow a store-mapped token.

Without any usable source the command fails with a clear message naming the resolved path. On an auth failure, the per-repo probe error **names the resolved leg** — e.g. `github: cannot read repo owner/repo — HTTP 401 (token expired/revoked) (token from token file /path)` — so you can see *which* source won without instrumenting it (DL-186); `bridge:reconcile -v` prints the resolved leg per repo even on success. `bridge:check` warns when writeback is configured but no token resolves (or a file source is insecure), **and probes the resolved token's validity** against each mapped repo — a resolved-but-expired token gets a warn naming the leg at preflight, not a silent 401 on the first run.

> **⚠ UPGRADING to store-native per-repo tokens (DL-185)?** A pre-existing conventional `<secret_dir>/github/token` file — or `BRIDGE_GITHUB_TOKEN_PATH` — from the single-token era **short-circuits the `[git-credential-map]` store** (leg 1/2 beat leg 3), so every repo resolves that one file's token instead of its own per-repo PAT. On an upgraded install that file is frequently **stale** (nothing was rotating it once the store took over), which surfaces as *every repo 401s* on the first `bridge:reconcile` run despite a correctly-populated store map. **Fix:** remove the file (`ls <secret_dir>/github/token`; back it up, then `rm`/`mv`) so each repo resolves its own least-privilege token — or keep it deliberately if you *want* one shared token. `bridge:check` now flags this at preflight (the validity probe above names the shadowing leg).

**What it reconciles.** Only cards carrying a resolvable `(repo, PR)`: a `payload.pr_url` (yields both repo + number) or a `payload.pr_number` on a **1:1 board** (the mapping supplies the repo). A `dl_number`-only card is **skipped with an info line** — DL→PR resolution needs a GitHub search, out of v1 scope. A bare `pr_number` on a **shared** board is ambiguous (no repo) and skipped. The expected stage is derived from the PR state with the **same** outcome mapping as the event path (`open → opened`, `closed+merged` to the integration branch `→ merged`, to `main → merged_to_main`, `closed+unmerged → closed_unmerged`).

**Safety posture** — it reuses the event-path guards rather than inventing new ones, and keeps read-side degradations LOUD (never a false-green):

- **Startup auth probe.** Before touching any card it does one `GET /repos/{owner}/{repo}` per mapped repo. A failure (401 = expired/revoked token, 403/404 = the token can't see that private repo) is reported loudly, that repo's cards are skipped, and the run exits non-zero — so an under-scoped or dead token can't silently 404 every card while the run exits 0.
- **Never moves a card backward** (DL-163 stage order). Backward drift is *reported*, not applied. When the board order can't be read, the drift is reported as *unorderable*, left alone (a batch mover must not guess direction), and the run exits non-zero (a drift left unreconciled for lack of order data is a degraded run, not a clean one).
- **Never moves a pinned card** (DL-178 `block_reason` / `no-automove`).
- **Release-promotion is out of scope.** The `released_to_main` stage is treated as **terminal**: a card there is never moved out, and a merge-to-`main` PR (outcome `merged_to_main`) is never moved *in* — the `release-promote-cards` workflow is that stage's rerunnable owner.
- **A truncated board read aborts that board** (never reconciles a partial view) and fails the run loudly.
- **`--max-moves` (default 20) caps a run:** more planned moves than the cap **aborts before applying ANY** — mass movement means a bug, not drift. Raise the cap deliberately if a large legitimate backlog of drift is expected.
- **A per-card GitHub error after the probe:** a **404** is a genuinely deleted PR → warn + skip that card (the run continues, exit unaffected); a **401/403** means the token was revoked mid-run → the run exits non-zero. A timeout/connection error warns + skips that one card.

**Not reconciled in v1 (documented gaps):**

- **`dl_number`-only cards** and a **bare `pr_number` on a shared board** — no resolvable `(repo, PR)`; skipped with an info line.
- **The branch-create `started` outcome** (a card promoted to In-Progress by a `push` that created a branch, DL-160) — there is no PR to GET, so a dropped `push` event is *not* recovered here; it self-heals on the card's next PR event.
- **`closed_unmerged` (abandoned-PR) regression** — this is legitimately *backward* (In-Review → In-Progress) and the event handler applies it, but the reconciler declines all backward moves, so a dropped `pull_request.closed`-unmerged event is **reported** (with an accurate label) but not auto-fixed. It is left to the event path (redelivery) or a human in v1.

**Scheduling.** The command ships with **no new cron** — the daemonless design accepts exactly one periodic job (`bridge:prune`). Run `bridge:reconcile` from a host cron (e.g. hourly, report-only; `--fix` less often or after review), or wire a report-only pass into the session-close ritual. Automating `--fix` is an operator choice; start report-only and add `--fix` once the report is boring.

### Running reconcile unattended (worked example)

Reconcile is **operator maintenance** (like `bridge:prune`), *not* an agent poll — it's a periodic backstop that catches the drift a dropped webhook left behind. The one non-obvious requirement: a cron/systemd context has a **stripped environment**, and the store-native token leg spawns `git-credential-coord`, which needs `HOME` (and `COORD_CREDENTIALS` if the store isn't at `~/.config/coord/`) to find the store, plus the helper on `PATH`. Set them explicitly:

```cron
# hourly report-only; a daily --fix pass with a circuit-breaker. Adjust to taste.
HOME=/home/<user>
PATH=/home/<user>/.local/bin:/usr/local/bin:/usr/bin:/bin
BRIDGE_DIR=/home/<user>/.config/agent-webhook-bridge-prod
17 * * * *  cd /home/<user>/agent-webhook-bridge-prod && php artisan bridge:reconcile           >> "$HOME/reconcile.log" 2>&1
23 4 * * *  cd /home/<user>/agent-webhook-bridge-prod && php artisan bridge:reconcile --fix --max-moves=20 >> "$HOME/reconcile.log" 2>&1
```

`--max-moves` is the **circuit-breaker**: a run planning MORE than the cap aborts before applying *any* move (mass movement means a bug, not drift — re-run manually with a higher cap once you've explained it). Start report-only for a few days; add the `--fix` line once the report is consistently boring. If the store-native leg is in use, first confirm the unit's env resolves the token: `HOME=… PATH=… php artisan bridge:reconcile -v` should print `github: <repo> — readable (token from store …)` per repo.

## Security notes

- Board + stage are **operator config only**, keyed on GitHub-controlled fields — the webhook body can't choose a board or stage. The worst an attacker-influenced PR can do (via title correlation) is nudge a card *that genuinely sits on the mapped board* to a *config-mapped stage* — bounded, reversible, logged.
- The `started` trigger (DL-160) keys on `payload.created` + `payload.ref` (GitHub-controlled, not body-spoofable to the bridge — they ride a HMAC-verified delivery), and the move is **doubly bounded**: it only ever advances a card *that sits on the mapped board* *from a configured `started_from_stages`* to the *config-mapped `started` stage*. Worst case from a maliciously-named branch is the same bounded, reversible forward nudge as the PR path — and only for a card already in Backlog/Prioritized.
- The writeback token is least-privilege, `0600`, read fail-closed at point-of-use, never logged.
- The writeback identity is auto echo-suppressed (its `card_updated` webhook doesn't loop back).
