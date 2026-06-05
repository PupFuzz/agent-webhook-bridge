# GitHub-PR → kanban card-move writeback (FR #2016)

The bridge can keep a kanban card in sync with its PR's lifecycle **deterministically, with no agent in the loop** — a GitHub `pull_request` webhook moves the card to a stage. This is the bridge's only *writeback* (it is otherwise surface-only / one-way). Design + rationale: `CLAUDE_DECISIONS.md` DL-009 (the seam) → DL-018/019/020/021 (the implementation).

## How it works

1. GitHub POSTs a `pull_request` webhook → the bridge's github receiver (HMAC-verified like any event).
2. A github-subscribed agent runs `GitHubPrCardMoveClassifier`, which:
   - derives the **outcome** from GitHub-controlled fields (`opened`/`reopened` → `opened`; `closed`+merged to `main` → `merged_to_main`; `closed`+merged to another base → `merged`; `closed`+not-merged → `closed_unmerged`) — never the PR title;
   - finds the card by the `DL-NNN` token in the PR title / head branch, matched against the mapped board's `dl_number`;
   - emits a `kanban_move_card` durable reaction (or no-ops if the repo is unmapped / no `DL-NNN` / no matching card).
3. `KanbanMoveCardHandler` (durable) moves the card — board + stage come **only** from your `writeback.json` (keyed on the outcome), it **refuses** a card not on the mapped board, and it is idempotent (no-op if already there).

## Setup (operator)

### 1. A least-privilege writeback token
Create a kanban API token scoped to **card moves on the mapped boards** (NOT the broad provisioning token), and place it:
```bash
install -m 600 /dev/stdin "$BRIDGE_DIR/kanban/writeback-token" <<<'<the-token>'
```
The writeback acts as this token's kanban user — note that user's `user_id`. **That user MUST be a member/owner of every mapped board** — kanban-board scopes card search/read to the token-user's accessible boards, so a writeback user not on the board makes correlation silently return nothing.

### 2. `writeback.json` (in the config dir)
```jsonc
// $BRIDGE_DIR/writeback.json   (chmod 600)
{
  "identity_id": 4242,                 // the writeback user's kanban user_id (auto echo-suppressed)
  "mappings": {
    "your-org/your-repo": {
      "board_id": 8,
      "stages": {                      // outcome → workflow_stage_id (on board_id)
        "opened": 50,                  // In Review
        "merged": 52,                  // Shipped to dev
        "merged_to_main": 53,          // Released to main
        "closed_unmerged": 49          // In Progress
      },
      "create_dependabot_cards": true  // optional, default false — see below (DL-024)
    }
  }
}
```
Absent ⇒ writeback off. Malformed ⇒ fail-closed (`bridge:check` reports it). Every stage id must be a real stage **on that board** (a cross-board id is refused by kanban and logged, not retried).

**`create_dependabot_cards`** (optional, default `false`, DL-024) — when `true`, a **dependabot** PR (head ref `dependabot/*`), which carries no `DL-NNN` and has no pre-existing card, gets a card **created on open** and **moved on close**, keyed by **PR number** (the same `stages` map drives the lifecycle). New cards are tagged `dependencies` + `triaged`. Idempotent on `payload.pr_number`; a `closed_unmerged` for a PR never tracked is skipped. The writeback token's kanban user must be able to **create** tasks on the board (it already needs write + board membership for moves).

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
The bridge does **not** provision GitHub webhooks (no repo-admin token by design). In the repo's **Settings → Webhooks**, add a webhook:
- **Payload URL:** `<BRIDGE_RECEIVER_BASE_URL>/webhooks/github?b=your-org/your-repo`
- **Content type:** `application/json`
- **Secret:** the per-scope HMAC secret at `$BRIDGE_DIR/github/webhook-secret-scope-your-org%2Fyour-repo`
- **Events:** Pull requests

### 5. Verify
```bash
php artisan bridge:check        # validates writeback.json + the writeback token
```
Open/merge a PR whose title or branch carries `DL-NNN` matching a card's `payload.dl_number` (the card's `dl_number` custom field, populated by your board automation when the card is created); the card moves. `php artisan bridge:inspect <event_id>` shows the dispatch + any logged refusal/no-op.

## Failure behaviour (what retries vs not)

- **Transient** (kanban 5xx/timeout, a not-yet-placed token) → the webhook **5xx**s and kanban-board redelivers; the move retries once it's fixed.
- **Permanent** (no mapping, no stage, a malformed payload, a kanban **4xx** like a deleted card or a cross-board stage, or the card isn't on the mapped board) → **logged + no-op**, the webhook acks 200 (a refused/un-actionable move is not a delivery failure — it would only retry-storm).

## Security notes

- Board + stage are **operator config only**, keyed on GitHub-controlled fields — the webhook body can't choose a board or stage. The worst an attacker-influenced PR can do (via title correlation) is nudge a card *that genuinely sits on the mapped board* to a *config-mapped stage* — bounded, reversible, logged.
- The writeback token is least-privilege, `0600`, read fail-closed at point-of-use, never logged.
- The writeback identity is auto echo-suppressed (its `card_updated` webhook doesn't loop back).
