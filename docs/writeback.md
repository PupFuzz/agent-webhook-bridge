# GitHub-PR → kanban card-move writeback (FR #2016)

The bridge can keep a kanban card in sync with its PR's lifecycle **deterministically, with no agent in the loop** — a GitHub `pull_request` webhook moves the card to a stage, and a branch-create `push` promotes the card to In Progress (DL-160). This is the bridge's only *writeback* (it is otherwise surface-only / one-way). Design + rationale: `CLAUDE_DECISIONS.md` DL-009 (the seam) → DL-018/019/020/021 (the implementation) → DL-160 (the branch-create → In-Progress trigger).

## How it works

1. GitHub POSTs a `pull_request` **or `push`** webhook → the bridge's github receiver (HMAC-verified like any event).
2. A github-subscribed agent runs `GitHubPrCardMoveClassifier`, which:
   - **`pull_request`** → derives the **outcome** from GitHub-controlled fields (`opened`/`reopened` → `opened`; `closed`+merged to `main` → `merged_to_main`; `closed`+merged to another base → `merged`; `closed`+not-merged → `closed_unmerged`) — never the PR title; finds the card by the `DL-NNN` token in the PR title / head branch. (With the opt-in `revive_on_reopen`, a `reopened` action instead derives a distinct **`reopened`** outcome — see *revive a Won't-Do card* below, DL-195.)
   - **`push` that CREATED a branch** (`payload.created === true`) whose ref carries a `DL-NNN` → outcome **`started`** (codifies "work has begun" from the artifact — the branch). Fires once on branch creation (a later push to the same branch is a no-op); a `dependabot/*` branch or a ref with no `DL-NNN` is ignored. The card is found by that `DL-NNN`, matched against the mapped board's `dl_number`.
   - emits a `kanban_move_card` durable reaction per correlated card (or no-ops if the repo is unmapped / no `DL-NNN` / no matching card).
   - **(opt-in) `draft_overlay`** → additionally emits a `kanban_block_reason` durable reaction that mirrors the PR's *draft* state onto the card's `block_reason` (overlay only, **no stage move**) — see the *PR draft → `block_reason` overlay* section below.
3. `KanbanMoveCardHandler` (durable) moves the card — board + stage come **only** from your `writeback.json` (keyed on the outcome), it **refuses** a card not on the mapped board, and it is idempotent (no-op if already there). The `started` outcome additionally enforces a **no-regression guard** (see below): it only promotes a card currently in one of the mapping's `started_from_stages` **or `unpark_from_stages`** (DL-194), never dragging an already-progressed card backward — and it **refuses a pinned card** (non-empty `block_reason` or a `no-automove` tag) regardless of stage, **except from an `unpark_from_stages` stage, where a branch-cut deliberately overrides the pin and emits a compensating alert (DL-194, see *Auto-unpark* below).**
   - **No-regression guard on the PR outcomes too (DL-163).** A stale or redelivered `pull_request` event — or a **release PR whose title carries a card's `DL-NNN`** — can re-fire an outcome on a card that has already advanced past it. The handler refuses any move that would drag a card **backward** in the board's workflow order (e.g. `opened`→In-Review on a card already Released, or a redelivered `merged` on a Released card). `closed_unmerged` is the one **legitimately backward** outcome by default (an abandoned PR returns its In-Review card to In-Progress), so it is allowed to regress **unless** the card has already reached a terminal (`merged`/`merged_to_main`) stage. (The opt-in `reopened` revival outcome, DL-195, is the *other* deliberately-backward move — allowed only from the mapped `closed_unmerged` abandon stage; see *revive a Won't-Do card* below.) The order is read from the board (preload); if it can't be read, the move proceeds (fail-open — the guard never blocks the writeback on missing order data). No config needed. *Mitigation that is now belt-and-braces, not required: keeping `DL` tokens out of release-PR titles avoids the spurious `opened` move in the first place.*

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

> **`closed_unmerged` — In Progress vs a "Won't Do" terminal (operator choice).** The example maps `closed_unmerged → In Progress` because a closed-unmerged **DL-tracked** PR usually means *work continues* (rework, not abandonment). If your board has a **terminal "Won't Do" / "Cancelled" column** (`lane_type: done`, positioned far-right) and you'd rather treat a closed-unmerged PR as an *abandon-disposition* (dependabot dismissals, `[DO NOT MERGE]` diagnostics, superseded/rejected PRs), map `closed_unmerged → <that stage id>` instead. The no-regression guard (DL-163) **allows this terminal move** — it special-cases `closed_unmerged` and never applies the forward-only check to it, so a card in In-Review moving to a far-right Won't-Do terminal is permitted (it's a disposition, not a regression), and once there the card is sticky (no stale PR event can drag it back out) — **unless you opt into `revive_on_reopen` (DL-195), which revives such a card from that stage when its PR is reopened (see below).** The **dependabot** path is unaffected either way — a closed-unmerged dependabot card always **archives** (DL-161), ignoring this mapping.

### Branch-create → In Progress (DL-160)

A GitHub `push` that **creates a branch** whose ref carries a `DL-NNN` (e.g. `feat/DL-160-x`) or a `card-<id>`/`card#<id>` token (e.g. `feat/card-3054-fix` — same FR-7 try-in-order resolution as the PR path, DL-179/DL-201) promotes the correlated card to the **`started`** stage — "work has begun" derived from the artifact, no agent involved. It is gated three ways so it can only ever advance a card forward:

- **Fires once, on creation** (`payload.created === true`) — a subsequent push to the same branch is a no-op.
- **No-regression guard.** The move is applied **only** when the card's current stage is in the mapping's **`started_from_stages`** (the board's Backlog/Prioritized stage ids, and optionally **Held** — see below) **or `unpark_from_stages`** (DL-194). A card already in In-Review/Shipped/Released is left untouched, so re-creating or force-pushing an old branch never drags it backward. `started_from_stages` is parsed strictly (a numeric list — a non-list / non-numeric element fails the config closed). **If `started_from_stages` is absent, a `started` move is refused** (the guard can't know what's safe to promote from) — set it to enable the trigger.
- **Held auto-promote + pinned opt-out (contract PR #113).** To un-park a **Held** card automatically when a branch is created for it, add the board's Held stage id to `started_from_stages`. The `created === true` classifier gate already makes this safe against re-fires — a re-push/force-push never emits `started`, only a genuine branch birth does. The escape hatch: a card a human wants to keep parked is not auto-promoted if it carries a non-empty `block_reason` **or** a `no-automove` tag — the handler refuses the move (loud `warning` + an `alert_channel` signal), regardless of stage — **except from an `unpark_from_stages` stage (DL-194), where the branch-cut deliberately overrides the pin and emits a compensating auto-unpark alert instead of refusing; see *Auto-unpark* below.** This mirrors the toolkit `board-card-start` hook's local half of the same contract.
- A `dependabot/*` branch, or a ref with no `DL-NNN`, emits no target.

To use it you must (a) map a `started` stage **and** set `started_from_stages`, and (b) subscribe the repo webhook to **push** events (see step 4).

> **⚠ Upgrade ordering — deploy the v0.37.0+ code BEFORE adding `started` to `writeback.json`.** `started` is an outcome a pre-v0.37.0 bridge does not recognize: its `WritebackConfig` rejects an **unknown stage outcome**, which is a *malformed-config* error — and a malformed `writeback.json` fails **closed for every mapping in the file**, not just the one you edited. So adding `started` while an older bridge is still serving (e.g. between `git pull` and an FPM reload, or on a host where you edited config first) silently disables your *entire* writeback until the new code is running. Sequence it: deploy + reload → `bridge:check` green → *then* edit `writeback.json` → `bridge:check` again.

> A mapping can also opt into **dependabot cards** (`create_dependabot_cards`) so dependency-update PRs auto-create cards — see the **Optional: dependabot cards** section below.

### 3. A github-subscribed agent using the classifier
```yaml
# $BRIDGE_DIR/writeback-agent.yml
# `identity` is OPTIONAL and deliberately omitted here: this seat is machine-only
# (it emits durable card writebacks, never a wake or an inbox surface), so it has
# no own-writes to suppress — an identity-less seat's echo/signal gates never fire
# at all, which is the trivially-correct posture (DL-203 § seat placement below).
# Seeding `github_user_id: <the bot account>` is SUPPORTED (since DL-203 a gate hit
# strips only the agent-facing surface and the writeback still runs) but buys this
# seat nothing.
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

> **Reading a refusal.** When kanban refuses a writeback call with a `4xx`, the handler logs a `warning` carrying **`status`** and **`body`** — kanban's response body verbatim (truncated to ~500 chars, with any credential-shaped value redacted). The body is the authority for *why* it refused (a `403` authz refusal, a `422` unregistered custom field / bad stage, a `404` deleted card, …); the log message states only what was observed and defers the cause to the `body`, so trust the server's words over any guess.

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

**Optional: stamp an `id:` provenance tag (`card_id_tag_template`, DL-206).** The bridge correlates its own cards **by-ref** (`payload.pr_number`/`pr_url`) and mints dependabot cards **without** the `id:{PREFIX}-pr-{N}` provenance tag that impl-side tooling stamps on impl-created PR cards — so a **tag-keyed** Shipped→Released promoter (one that reads the `id:` tag rather than the payload) never sees a dependabot card. Set **`card_id_tag_template`** on a mapping and the bridge renders it into an `id:` tag **prepended** to every dependabot card it creates. The template is a **free-form per-tenant grammar** (so it can match whatever your `id:`-keyed reader already parses); placeholders are **`{n}` / `{pr_number}`** = the PR number and **`{repo}`** = the repo NAME (the last path segment of `owner/repo`). Examples: `"id:DEV-pr-{n}"` → `id:DEV-pr-166`; `"id:dep:{repo}#{n}"` → `id:dep:magento#166`. Absent ⇒ **no tag** (byte-identical to today). The card stays idempotent on `payload.pr_number` for its lifecycle **and** correlatable by the tag for external promoters — additive, no read-side fallback. An empty or non-string value **fails the config closed at load**.

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

> **`card-<task-id>` / `card#<task-id>` correlation (FR-7 / framework v0.2.229, DL-177; dash alias + DL-shaped boundary DL-201).** A PR whose **title or head branch** carries `card-<id>` or `card#<id>` (case-insensitive on `card`) moves that card by its **native kanban task-id** — the channel for cards with no DL number. The token boundary is **DL-shaped: leading `\b` only, no trailing `\b`** (`/\bcard[-#](\d+)/i`), the same shape the `DL-NNN` regex has always had — so `feat/card-3054_fix` correlates card 3054 (a trailing `\b` made that a *silent* no-op, roundtable #48), while embedded words (`discard-1`, `wildcard-2`) never match. A branch/PR that *appears* to name a card in a shape the token doesn't accept (`card_123`, `card123`, `card:123`, `card #123`) is warned loudly as an **FR-7 near-miss** rather than silently dropped — the ratified convention is `card-<id>` (dash), with `card#<id>` accepted as the original form. No `source` qualifier, no classify-time kanban read: native ids are globally unique, and the durable handler already rejects a card not on the operator-mapped board and applies the core no-regression guard + `*_from_stages` allowlists exactly as for a DL move. The token only *selects* the card — board + stage always come from the repo's `writeback.json` mapping, never PR text. `pr_number` stays the orthogonal PR-first (dependabot) path.

> **Correlation-key stamping on the `card#` path (DL-187).** A card moved by `card#` is one that carries no `dl_number`/`pr_number` — which is exactly what `promote-released-cards` correlates released cards by, so such a card strands in Shipped-to-Dev at release time. On the `card#` path **only**, the writeback therefore stamps the card's `payload.dl_number`/`pr_number` **add-if-missing** (never overwriting an existing/human-set value) as a step distinct from the column-only move: the `dl_number` when the title+branch carries **exactly one** `DL-NNN` (a bundled/release-shaped PR with 2+ DLs, or a foreign DL, stamps `pr_number` only — never a wrong DL), stored canonical zero-padded (`DL-%04d`); the `pr_number` from the PR. A **DL-resolved** move stamps nothing — its card already carries the `dl_number` that resolved it. The stamp is best-effort with the move's transient/permanent split (a 4xx like "board has no such custom field" is logged + skipped; a 5xx propagates so redelivery re-stamps) and runs only once the card is legitimately at/entering its target stage (never from a guard-rejected event). This makes `kbcard --dl` at decision-time unnecessary going forward for `card#`-tracked cards.
>
> **Precedence — try-in-order-with-fallback (framework #112, DL-179).** A PR/branch MAY carry both card-first tokens, and the resolver keys on the *outcome* of a token, not its presence: **(1)** `DL-NNN` **resolves** to a card → it wins (a co-present `card#` is logged as ignored — a ref naming two cards is almost always an operator error); **(2)** `DL-NNN` **unresolved** → fall through to a present `card#` (native-id move); **(4)** a token was present but **nothing resolved** and there is no `card#` fallback → a **high-value miss**, warned loudly, never a silent no-op. Committing to the DL the moment it is *present* (rather than when it *resolves*) was the live dead-end (DL-179): a `DL`-titled PR against an as-yet-unstamped card resolved nothing and never tried the `card#` that would have matched. The `card#` fallback stays **board-scoped** by the durable handler's existing board-membership guard (`card_not_on_mapped_board`), which gates DL and `card#` moves identically — so the classifier stays classify-time-read-free.

> **Shared-board safety (DL-167, refined by DL-174).** A bare PR/DL number collides when several repos map to **one board** (a `swimlane_id` lane per repo — see above): a same-numbered PR/DL in another repo correlates too. On a **shared** board (two or more repo mappings targeting one `board_id`) the bridge passes the event's **repo as the kanban `source` qualifier** (kanban DL-163, requires kanban **v0.21.0+**) so in `ref` mode the server returns only **this repo's** card(s) — for both the dependabot path *and* the DL move path. On a **1:1 board** the qualifier is **omitted** (DL-174): there is no collision to disambiguate, and kanban's `source` filter is strict, so qualifying would exclude every card whose derived source is null (any operator-stamped `dl_number`/`pr_number` card with no `pr_url`/`repo` field) — the silent never-self-moves failure #3399 diagnosed. In `scan` mode (legacy, no `source`), the dependabot handler still attributes each correlated card by the `github.com/<owner>/<repo>/pull/` segment of its `pr_url` and drops a co-hosted repo's identically-numbered card. Either way, a foreign repo's collision is never moved or archived. (Against a pre-v0.21.0 kanban the `source` key is ignored → any-source behavior, same as before.)

**Worked example.** With the mapping above, dependabot opens `chore(deps): Bump x from 1 to 2` (PR #77, head `dependabot/composer/x-2.0`) → a card *"chore(deps): Bump x from 1 to 2"* appears on board 8 in **In Review** (50), tagged `dependencies`/`triaged`, with `payload.pr_number: 77`. When it auto-merges to `dev`, the card moves to **Shipped to dev** (52). `php artisan bridge:inspect <event_id>` shows each create/move.

## Optional: real-time coordination issue → card (DL-198)

If you run the bridge on a **coordination repo** (the Agent Board Framework's `[BRIEF]`/`[QUERY]`/… thread repo), a periodic `reconcile_simple_board` pass already mints a tracking card for each recognized-prefix issue. Set **`create_coord_cards: true`** (plus a **`coord_card_stage_id`**) on that repo's mapping and the bridge instead creates the card **in real time** the moment the issue opens — the reconcile stays the **backstop** (it adopts the bridge-created card by its `id:<sid>` tag, so the bridge stays **registry-free** and the two movers never duplicate).

```jsonc
// $BRIDGE_DIR/writeback.json
{
  "identity_id": 4242,                 // REQUIRED — the created card's task.created echoes back; this gates it (see below)
  "mappings": {
    "your-org/your-coord-repo": {
      "board_id": 8,
      "create_coord_cards": true,      // opt-in (default false ⇒ byte-identical)
      "coord_card_stage_id": 21,       // required-when-create_coord_cards — the stage a new coord card lands in
      "swimlane_id": 31,               // optional — created cards land in this lane
      "stages": { /* … */ }            // PR outcomes (unused if the coord repo has no PR writeback)
    }
  }
}
```

- **Enable the family.** The classifier that cards coord issues is a `CoordinationClassifier` **family** — add **`coord-card-create`** to that agent's `classifier.config.families` (it is **not** a default). **Seat placement:** the **preferred** seat is a dedicated, identity-less writeback agent (no `identity.github_user_id`, no channel) whose only job is emitting the durable card writebacks — its echo/signal gates never fire, so its behavior is trivially independent of who authored the event. Running the family on the same agent already handling coord wakes is **supported too** (no new agent, no new webhook subscription — `issues` is already delivered): since DL-203 an echo/signal gate hit on a writeback-emitting classifier strips only the agent-facing surface (wake/inbox) while the machine writeback still runs, so a seeded `github_user_id` on that seat no longer kills its own issue-open/close card writebacks. Scope that retirement precisely: it applies to **writeback targets only** — a wake-purposed seat that seeds `identity.github_user_id` still loses its own-push **wakes** (by design; the DL-190 never-seed-`github_user_id`-on-a-wake-identity rule stands). It cards **every** recognized-prefix issue on the repo (board-level, **not** addressed-to-me) — its own gate is a recognized prefix AND this mapping's `create_coord_cards`.
- **What gets carded.** An issue whose **trimmed title** starts with `[BRIEF]`, `[ANNOUNCE]`, `[QUERY]`, `[REVIEW]`, or `[TASK]` (case-insensitive), on `issues.opened` **or** `issues.reopened`. An un-prefixed / `[PROPOSAL]` / unrecognized-prefix issue is **not** carded (the create-set equals the reconcile's own-prefix set, so a carded issue is always one the reconcile backstops).
- **The card.** Named the issue title verbatim; tagged **`id:<sid>`** + **`type:<itype>`** only (`sid = "<PREFIX>-<num>"` from the **anchored** first prefix, e.g. `QUERY-42`; `itype` mirrors the reconcile's `_itype` — an **unanchored** priority-substring scan `[BRIEF]`>`[ANNOUNCE]`>`[QUERY]`>`[REVIEW]`, else `task`, so a multi-bracket title's `type:` matches the reconcile even where it differs from the anchored `sid` prefix). `repo:` is **omitted** at create (non-critical — the reconcile folds it). It also sets `description = "Coordination thread <repo>#<num>"`, `priority = 1` for a `[BRIEF]` else `0`, and `external_link = https://github.com/<repo>/issues/<num>` — mirroring the reconcile's create so its next pass doesn't update-churn them. `external_id` is **not** set (the reconcile's `build_create` omits it, and kanban's `(board_id, external_id)` uniqueness would 422 a colliding issue number on a multi-repo coord board — `external_link` carries the correlation).
- **Create-only + idempotent.** This create path never moves or archives a card. (The bridge as a whole is no longer create-only for coord cards: its sibling **`move_coord_cards`** (DL-200, a guarded fleet default since DL-204 — below) carries close→terminal / reopen→revive. The reconcile still owns column/lifecycle wherever the move leg is **off**, and **archival remains the reconcile's alone**.) It correlates by the **`id:<sid>` tag**: if a card already carries it, it **skips** — which covers redelivery, opened+reopened, **and** the bridge-vs-reconcile race (both movers key on the same tag). After a create it re-reads by tag and collapses a raced duplicate (keep lowest id, archive the rest — the shared deterministic tie-break). Durable, transient(5xx→retry)/permanent(4xx→log+no-op).
- **`identity_id` is REQUIRED (echo-gate).** A created card fires a kanban `task.created` webhook that comes back to the bridge; if any agent runs the `kanban-triage` family on that board, that echo reads as an untriaged card and could **self-wake**. The **only** guard is the global-echo gate keyed on `writeback.json` `identity_id`. `bridge:check` **warns** when `create_coord_cards` is set but `identity_id` is null.
- **`bridge:check`.** Validates `coord_card_stage_id` (and any `swimlane_id`) exists on the board — a typo'd id makes every create 422 and silently no-op. Missing `coord_card_stage_id` while `create_coord_cards` is on **fails the config closed at load** (a create with no stage can't POST).

## Optional: coordination issue close/reopen → card move (`move_coord_cards`, DL-200)

The sibling of `create_coord_cards` above, and **separately opt-in** — it does *not* ride
`create_coord_cards`. With it on, a coordination issue **closing** moves its tracking card to a
terminal column in real time, and a **reopen** revives it. Without it, that only happens on the
consumer's next periodic reconcile pass.

**Guarded fleet default (DL-204, #4357).** `move_coord_cards` is no longer plainly opt-in: when the
key is **absent** it defaults **ON wherever the move config is complete** — i.e. wherever
`coord_card_terminal_stage_id` is present. That key is the "operator configured the move leg" signal
(it has no other consumer), so an install that never set a terminal upgrades **byte-identically**
(inert), while one whose per-board stage ids are already present activates **without** also setting the
flag. Set **`move_coord_cards: false`** to opt out explicitly even with a terminal configured. The leg
still fires only where **both** gates are on — this handler-side default **and** the `coord-card-move`
family (below); `bridge:check` nudges an install that enabled the family but left the terminal (hence
the leg) inert. A partial config (terminal present, revive stage missing or equal to the terminal)
fails **closed at load** — never a silent no-op.

```json
{
  "mappings": {
    "org/coord": {
      "board_id": 8,
      "stages": { "opened": 50 },
      "create_coord_cards": true,
      "coord_card_stage_id": 21,
      "move_coord_cards": true,
      "coord_card_terminal_stage_id": 99
    }
  }
}
```

- **Enable the family.** Add **`coord-card-move`** to the agent's `classifier.config.families` (it is
  **not** a default). Same agent, no new webhook subscription — `issues` is already delivered.
- **`coord_card_terminal_stage_id`** is the column a closed issue's card concludes into. **Required**
  when `move_coord_cards` is on, and it **must differ from `coord_card_stage_id`** — both are
  fail-closed at load.
- **`coord_card_stage_id` doubles as the revive target** (the stage a reopened card returns to — the
  same stage a fresh card is created in), so it is required here too, even with `create_coord_cards`
  off. Absent, the leg would half-work: closes land, reopens silently no-op.
- **What moves.** Same set as the create leg (recognized `[PREFIX]`, correlated by the **`id:<sid>`
  tag**) on `issues.closed` → terminal and `issues.reopened` → revive. `issues.opened` belongs to the
  create leg; `issues.edited` is not a lifecycle transition. **Nothing carrying the tag ⇒ nothing
  moves** — this leg never creates.
- **Reopen composition.** `issues.reopened` reaches **both** families: create-if-absent
  (`coord-card-create`) vs revive-if-present (`coord-card-move`). Each resolves on the tag lookup, so
  exactly one acts — never both.
- **The revive actor-gate (fail-closed).** A card is revived **only if** its terminal was
  **service-set** — `last_stage_move.actor_type === "service"`, an **allow-list**, not a deny-list of
  the human value. (kanban emits exactly `human` for a UI move, `service` for api/system, and `null`
  on a pre-feature row — it never emits `"user"`.) Absent, null, unknown, or human provenance ⇒
  **no revive**. A human who drags a card to the
  terminal has expressed a closure intent the bridge must never reverse. Revive also requires the card
  to currently *be* in that terminal: one that has since moved on is live work, and dragging it back
  would be a backward regression.
- **A close is unconditional over a user lane.** A human's priority placement yields to closure
  (close→terminal is the terminal case) — so unlike the unpark/pin paths, there is no pin side to pick.
- **Idempotent + redelivery-safe.** A card already in the destination is skipped, so at-least-once
  delivery never re-PATCHes. Durable, transient(5xx→retry)/permanent(4xx→log+no-op).
- **`bridge:check` cross-config compare (read this).** The bridge owns `coord_card_terminal_stage_id`
  (a **stage id**), while the consumer's reconcile derives its terminal from `terminal_columns`
  (column **names**) in the coordination project's `coordination.config.json`. If the two disagree they
  **fight every cycle** — the bridge concludes a card, the reconcile drags it back — with each side
  individually "working". So `bridge:check` reads `$COORD_CONFIG` (override:
  `bridge.writeback.coord_config_path`), resolves that board's terminal through the framework's own
  rule (explicit `terminal_columns`, else the `user_lanes` → `"Done"` lane-model fallback, unioned
  across every `boards[]` entry sharing the `board_id`), and compares:
  - **agrees** → an `info` line naming the column and stage.
  - **DISAGREE** → a warn naming both stages and the fix.
  - **CANNOT VERIFY** → `$COORD_CONFIG` unset/absent/malformed, no entry for this board, more than one
    terminal (ambiguous — the bridge writes exactly one), the board read failed so the column name
    couldn't be resolved to a stage id, or the column isn't a stage on the board.
    This is reported **distinctly from agreement**: a missing input is not evidence of agreement, it is
    evidence we could not ask.

  It **never fails** `bridge:check` (warn-never-fail) — the bridge must not become unrunnable because a
  coord file moved. The read is **CLI-only by design**: `bridge:check` runs with the operator's
  environment, while the receiver runs under PHP-FPM, whose environment does **not** carry
  `$COORD_CONFIG`. Nothing on the request path reads it.
  **If you run `php artisan optimize`** (config cache), the ambient `$COORD_CONFIG` is still honored —
  it is read live at the check via `getenv()`, deliberately not baked into cached config (which would
  freeze it to the deploying shell's value forever). To pin a path independent of the invoking shell,
  set `BRIDGE_COORD_CONFIG_PATH` in that install's `.env`.

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

**DL-178 pin interaction (intended).** A non-empty `block_reason` **pins** the card (PinGuard, DL-178), so a **`started`** (branch-push) auto-promote is refused while the PR is a draft (**unless the draft card's stage is in `unpark_from_stages`, DL-194 — there the move proceeds; a benign draft sentinel emits no alert**); clearing on `ready_for_review` releases the pin. This is desired — a drafted card is gated against the branch-push promote. (The pin is consulted **only** on the `started` outcome; the four PR-outcome moves are gated by the no-regression stage order, not the pin — and GitHub blocks merging a draft PR, so `merged` can't fire on a still-drafted card regardless.) No change to PinGuard.

To use it: set `draft_overlay: true` on the mapping and subscribe the repo webhook to **Pull requests** (which already carries the `converted_to_draft` / `ready_for_review` / `opened` actions — no extra event class needed). Same durable, transient(5xx→retry)/permanent(4xx→log+no-op) split and belongs-to-mapped-board guard as the move handler.

## Optional: auto-unpark a parked card on branch-cut (DL-194)

By default the `started` (branch-create) move **refuses a pinned card** (a non-empty `block_reason` or a `no-automove` tag) — "parked means parked" (DL-178). But cutting a fresh branch **for a specific card** is an unambiguous human *"work has begun"* signal, and column position is not the enforcement boundary (deploy/merge + a persistent tag is). Set **`unpark_from_stages`** on a mapping and a `started` event **promotes the card even if it is pinned**, *scoped to those stage ids only* — and emits a compensating operator **alert** whenever it overrode a *deliberate* hold, so a genuinely-held card is never unparked silently.

```jsonc
"owner/repo": {
  "board_id": 8,
  "stages": { "started": 49, /* … */ },
  "started_from_stages": [46, 47],       // refuse-if-pinned promote-from (DL-160)
  "unpark_from_stages": [51],            // move-EVEN-IF-pinned (e.g. a Held/Parked stage) — DL-194
  "hold_marker_tags": ["gate"],          // optional — this install's extra hold convention
  "draft_block_reason": "PR is in draft" // optional — the benign draft sentinel (default shown)
}
```

- **`unpark_from_stages`** — the stage ids from which a `started` event promotes to In-Progress *even when the card is pinned*. Parsed strictly (a non-empty numeric list, like `started_from_stages`). It **must be disjoint from `started_from_stages`** — a stage can't be both refuse-if-pinned and move-if-pinned; an overlap **fails the config closed** (`bridge:check` reports it). The move is still bounded by the belongs-to-mapped-board guard and only ever advances **forward** to the `started` stage.
- Everywhere *outside* `unpark_from_stages`, **DL-178 is byte-identical**: a pinned card in a plain `started_from_stages` stage is still refused (`pinned_no_automove`). With no `unpark_from_stages` set, this feature is entirely inert.

**The alert predicate + fail-safe.** After a *confirmed* move (never on a 4xx move no-op), the handler alerts (a new `writeback_auto_unparked` signal on the `alert_channel`, no dedup) **only when it actually overrode a deliberate hold** — the durable `Log::warning` labels which one (`hold_signal`):

| Card's pin signal | `hold_marker_tags` | Alerts? |
|---|---|---|
| `no-automove` tag | any | ✅ `no_automove` |
| human `block_reason` (≠ the draft sentinel) | any | ✅ `block_reason` |
| a configured hold tag (e.g. `gate`) | `["gate"]` | ✅ `hold_tag` |
| draft-only (`block_reason` == the sentinel, no other signal) | any | ❌ (benign automated draft) |
| bare park (no recognized pin signal) | **empty** | ✅ `failsafe` |
| bare park (no recognized pin signal) | configured | ❌ (operator declared their marker → trust it) |

The **fail-safe** is deliberate: an install that opts into `unpark_from_stages` but hasn't listed its hold-tag convention alerts on *every* non-benign-draft unpark — a spurious, dismissable alert beats a **missed** alert on a real gate. A real PinGuard pin (a `no-automove` tag or a human `block_reason`) **always** alerts regardless of `hold_marker_tags`; declaring `hold_marker_tags` only *quiets bare-park noise*, never the pinned/held cases. `draft_block_reason` (default the DL-193 marker `"PR is in draft"`) names the benign automated-draft sentinel so a drafted-then-branch-cut card doesn't alert; set it if your draft overlay writes a different value.

- **Durable-first.** The `Log::warning` record is written **before** the alert push (log = durable record, push = additive live wake), so the override is recorded even when no `alert_channel` is configured or the push is down.
- **One alert per successful unpark, storm-free (no marker to persist).** The alert sits *between the move and the correlation-ref stamp*: a first delivery moves → alerts once → (a later stamp 5xx just re-runs the delivery, which then hits the idempotent already-in-stage short-circuit **before** the alert line). A genuine **re-park** (a human moves the card back into an unpark stage) followed by a fresh branch-cut is a new event and **correctly re-alerts** — cardid dedup would wrongly collapse those distinct human cycles.
- **Cross-mover scope note.** This is the **bridge** half only. The toolkit `board-card-start` hook is a *separate* build, so a purely **local** checkout won't unpark a card until the branch is **pushed** (the bridge only sees the push). The card still ends up moved via the push path — the operator story just isn't fully symmetric between a local branch-cut and a pushed one.

**Accepted-by-design residual risks:**
- **Concurrent double-delivery.** Two in-flight deliveries of the same event (e.g. an operator "Redeliver" while the original is still processing) can both read `processed_at = null` and both alert. Bounded to the concurrency count (GitHub serializes *automatic* redeliveries), consistent with the "extra alert > missed alert" posture — not eliminated.
- **Sentinel ambiguity.** A human who types the exact `draft_block_reason` sentinel as their own `block_reason` is treated as a benign draft and unparks silently. Inherent to a constant sentinel.

## Optional: revive a Won't-Do card when its PR is reopened (DL-195)

If you map `closed_unmerged → <a "Won't Do" terminal stage>` (see the `closed_unmerged` operator-choice note above), an abandoned PR parks its card there. When that PR is **reopened**, GitHub fires `pull_request.reopened` — which normally collapses to the `opened` outcome, and the **DL-163 no-regression guard refuses the backward Won't-Do → In-Review move**, so the card **strands in Won't-Do while its PR is alive again**. Set **`revive_on_reopen: true`** on the mapping and a reopen **revives** the card from the abandon stage back to the `opened` (In-Review) stage.

```jsonc
{
  "mappings": {
    "owner/repo": {
      "board_id": 8,
      "stages": { "opened": 50, "merged": 52, "merged_to_main": 53, "closed_unmerged": 77 },  // closed_unmerged = a "Won't Do" stage
      "revive_on_reopen": true                 // reopen an abandoned PR → revive its parked card (DL-195)
    }
  }
}
```

- **Opt-in, byte-identical when off.** Absent/`false` ⇒ a `reopened` action stays the `opened` outcome exactly as before. When `true`, the classifier emits a distinct `reopened` move-outcome that the handler treats specially. There is **no `stages.reopened` key** — revival reuses **`stages.opened`** as the target.
- **Scoped to the abandon stage (terminal-safe).** Revival fires **only** when the card's current stage is the mapped **`closed_unmerged`** stage. A card that has advanced to Shipped/Released is never in that stage, so it can never be revived — and GitHub can't reopen a *merged* PR anyway. A reopen of a card that is **not** in the abandon stage behaves exactly like `opened` (forward-only; a stale reopen on a later stage is refused).
- **Only meaningful with a Won't-Do-*terminal* `closed_unmerged`.** This feature exists to unstick a card the guard would otherwise strand — i.e. when the abandon stage sits *after* In-Review. On the **default** `closed_unmerged → In-Progress` mapping a reopen already advances the card forward (In-Progress → In-Review) with no guard to override, so `revive_on_reopen` there is functionally inert and only adds a (harmless) revival alert on that forward move. Enable it **only** when `closed_unmerged` maps to a terminal Won't-Do stage.
- **Overrides a pin, with a compensating alert (symmetric with auto-unpark).** A human-pinned Won't-Do card (`block_reason` / `no-automove` tag) is revived anyway, and the override emits a **`writeback_revived_on_reopen`** signal on the `alert_channel` (no dedup), gated by the **same** `hold_marker_tags` / `draft_block_reason` predicate as the auto-unpark alert: a genuinely-held card always alerts; a bare-stage park alerts via the fail-safe unless you've declared `hold_marker_tags`. The durable `Log::warning` labels which hold it overrode (`hold_signal`).
- **Redelivery-safe.** After a revival the card sits at the `opened` stage, so a redelivered `reopened` hits the idempotent already-in-stage short-circuit **before** the guard and the alert — no double-move, no double-alert.
- **`bridge:check` guard.** With `revive_on_reopen` on, the check **warns** if `stages.opened` or `stages.closed_unmerged` is missing (revival is inert without both).
- **Not back-stopped by `bridge:reconcile` (deliberate).** The reconciler sees only static state (PR open, card in Won't-Do) with **no reopen signal**, so it cannot distinguish an automated `closed_unmerged` park from a deliberate human abandon of a still-open PR — reviving there would risk overriding a human decision. Only the live `reopened` event carries "work resumed"; a **missed** reopen (bridge down through redelivery exhaustion) needs a manual operator revive.

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

`socket` and `url` are **mutually exclusive** (exactly one), mirroring an agent's `channel` config. The signal body is one line: `{"type": "writeback_move_failed", "repo": <repo>, "outcome": <outcome>, "card_id": <id|null>, "reason": <reason>}`. (The same `alert_channel` also carries the DL-194 **`writeback_auto_unparked`** and the DL-195 **`writeback_revived_on_reopen`** signals — each a distinct `type`, no dedup — see *auto-unpark a parked card on branch-cut* and *revive a Won't-Do card* above.)

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
