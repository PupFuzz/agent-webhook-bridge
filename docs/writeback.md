# GitHub-PR → kanban card-move writeback (FR #2016)

The bridge can keep a kanban card in sync with its PR's lifecycle **deterministically, with no agent in the loop** — a GitHub `pull_request` webhook moves the card to a stage, and a branch-create `push` promotes the card to In Progress (DL-160). This is the bridge's only *event-driven* writeback. The bridge is no longer purely one-way: the two-way board tools (DL-217, [`docs/board-tools.md`](board-tools.md)) add an agent-initiated request/response surface (`board_my_cards` / `board_create_card` / `board_correct_card`) over the channel — but that is a distinct, loopback-gated ingress with its own audit trail, not this GitHub-event writeback. Design + rationale: `CLAUDE_DECISIONS.md` DL-009 (the seam) → DL-018/019/020/021 (the implementation) → DL-160 (the branch-create → In-Progress trigger).

## How it works

1. GitHub POSTs a `pull_request` **or `push`** webhook → the bridge's github receiver (HMAC-verified like any event).
2. A github-subscribed agent runs `GitHubPrCardMoveClassifier`, which:
   - **`pull_request`** → derives the **outcome** from GitHub-controlled fields (`opened`/`reopened` → `opened`; `closed`+merged to `main` → `merged_to_main`; `closed`+merged to another base → `merged`; `closed`+not-merged → `closed_unmerged`) — never the PR title; finds the card by the `DL-NNN` token **or the card token** in the PR title / head branch (try-in-order-with-fallback, DL-179 — see *Card-token correlation* below for both). (With the opt-in `revive_on_reopen`, a `reopened` action instead derives a distinct **`reopened`** outcome — see *revive a Won't-Do card* below, DL-195.)
   - **`push` that CREATED a branch** (`payload.created === true`) whose ref carries a `DL-NNN` **or a card token** → outcome **`started`** (codifies "work has begun" from the artifact — the branch). Fires once on branch creation (a later push to the same branch is a no-op); a `dependabot/*` branch, or a ref carrying **neither** token, is ignored. The card is found by whichever token resolved — a `DL-NNN` against the mapped board's `dl_number`, a card token by native id.
   - **on a MERGE outcome, additionally requires CLOSURE EVIDENCE** that this merge finishes the card that moves — **either** the PR merged into the **integration branch** from a **head branch whose ref names that card** (the structural route, DL-308), **or** an explicit **closing form in the PR title** naming it (`Closes card#N` / `Fixes DL-NNN`, DL-305). See *Mention vs closure* below. ⛔ **A `[no-close]` PR TITLE satisfies NEITHER route** (card#8344) — the author declaring that this PR CITES the card rather than finishing it. ⛔ **A REVERT satisfies NEITHER route** (card#8306) — GitHub quotes the original title and wraps the original branch, so both would otherwise fire on work the PR undoes. A PR that merely CITES a card **it is not the work for** moves nothing on merge; every other outcome is keyed on correlation alone, exactly as before.
   - emits a `kanban_move_card` durable reaction per correlated card (or no-ops if the repo is unmapped / no correlating token / no matching card / no closure evidence on a merge).
   - **(opt-in with `create_dependabot_cards`) a `pull_request.edited` that CHANGED THE TITLE of a dependabot PR** → emits a MOVE-LESS `kanban_dependabot_card` reaction (outcome `renamed`) carrying both the new title and the PREVIOUS one (`changes.title.from`), so the handler can restamp a card name the bridge still owns — see *Optional: dependabot cards* below (DL-328). Every other `edited` (a body or base change) stays the no-op it was.
   - **(opt-in) `draft_overlay`** → additionally emits a `kanban_block_reason` durable reaction that mirrors the PR's *draft* state onto the card's `block_reason` (overlay only, **no stage move**) — see the *PR draft → `block_reason` overlay* section below.
3. `KanbanMoveCardHandler` (durable) moves the card — board + stage come **only** from your `writeback.json` (keyed on the outcome), it **refuses** a card it cannot read, a card not on the mapped board, a card named only by an uncorroborated PR-title `card#` that already tracks a different PR (DL-270), and a **pinned** card (see the next bullet), and it is idempotent (no-op if already there). The `started` outcome additionally enforces a **no-regression guard** (see below): it only promotes a card currently in one of the mapping's `started_from_stages` **or `unpark_from_stages`** (DL-194), never dragging an already-progressed card backward.
   - **Pinned cards are refused on EVERY outcome (DL-178, corrected by card#8289).** A non-empty `block_reason` **or** a `no-automove` tag means a human has parked the card, and the writeback then never moves it — on `started`, `opened`, `merged`, `merged_to_main`, `closed_unmerged`, or the opt-in `reopened`, and regardless of stage. `closed_unmerged` is included on purpose: a hold holds in **both** directions. **Two exceptions**, each of which overrides the pin deliberately and emits a compensating alert *after* the move: a `started` event from an `unpark_from_stages` stage (DL-194, see *Auto-unpark* below), and a `reopened` revival from the mapped `closed_unmerged` stage (DL-195, see *revive a Won't-Do card* below). The refusal is loud — a `Log::warning` plus an `alert_channel` signal under `pinned_no_automove`. ⚠ **The pin governs the card's STAGE, not its correlation refs:** a refused move still stamps `dl_number` / `pr_number` / `pr_url` add-if-missing, so the card stays inside `bridge:reconcile`'s population and the backstop can complete the move once you lift the pin. Dropping the stamp as well would strand the card permanently — GitHub delivers each PR event exactly once. *(Before card#8289 this consult ran only on the `started` outcome, so a merge moved a pinned card to the shipped stage while `bridge:reconcile` and the release-promote sweep both skipped it.)*
   - **No-regression guard on the PR outcomes too (DL-163).** A stale or redelivered `pull_request` event — or a **release PR whose title carries a card's `DL-NNN`** — can re-fire an outcome on a card that has already advanced past it. The handler refuses any move that would drag a card **backward** in the board's workflow order (e.g. `opened`→In-Review on a card already Released, or a redelivered `merged` on a Released card). `closed_unmerged` is the one **legitimately backward** outcome by default (an abandoned PR returns its In-Review card to In-Progress), so it is allowed to regress **unless** the card has already reached a terminal (`merged`/`merged_to_main`) stage. (The opt-in `reopened` revival outcome, DL-195, is the *other* deliberately-backward move — allowed only from the mapped `closed_unmerged` abandon stage; see *revive a Won't-Do card* below.) The order is read from the board (preload); if it can't be read, the move proceeds (fail-open — the guard never blocks the writeback on missing order data). No config needed. *Mitigation that is now belt-and-braces, not required: keeping `DL` tokens out of release-PR titles avoids the spurious `opened` move in the first place.*

## Mention vs closure — what makes a merge move a card (card#7348 / DL-305, widened DL-308, revert-exempt card#8306, author opt-out card#8344)

*"This PR mentions card N"* and *"card N's work is shipped"* are different propositions, and until DL-305 the writeback collapsed them: the merge outcome was a pure function of the base ref, so a PR whose title or branch carried a `card#N` / `DL-NNN` token moved that card to `stages.merged` on merge — subject only to the handler's existing guards, none of which asks whether the PR finished anything — and, with `promote_on_release`, on into the terminal Released stage. A PR citing a card for context retired it. A peer install measured **17 wrong-retirement candidates in one release bundle**, and reported a card whose explicit human ruling ("this card does not close on that commit") was undone three hours later by a service write. *(That second item's ATTRIBUTION is pending a check on that install — `last_stage_move.actor_id` names a kanban USER, not a writer, and on some installs two writers share one. The defect itself is verified at source and needs no actor field.)*

⚠ **DL-305 first shipped a TITLE-ONLY rule, and it was too narrow to be usable.** Measured against real merged-PR titles from the four repos on this seat, **0 of 351** correlated merged PRs carried a closing verb — the house convention is `type(scope): summary (card#N)` and never has. A rule nothing satisfies does not protect a board, it freezes one, **quietly**: CI green, merge fine, card motionless. DL-308 widened the accept-set with a **structural** term before any of it reached prod. What follows is the rule as it now stands.

**The rule.** On the two MERGE outcomes — `merged` and `merged_to_main` — a card moves only when the event carries **closure evidence** naming it. There are **two routes, and either is sufficient**:

| Route | What it requires | Reads |
| --- | --- | --- |
| **Structural** (DL-308) | the PR **merged into the integration branch** (any base but `main`) **AND** the **head branch ref names that card** (`card-4811-widget`) | the branch, not prose |
| **Lexical** (DL-305) | a **closing form in the PR title** naming the card (`Closes card#4811`) or the DL that resolved it (`Fixes DL-239`) | the title |

⛔ **Either route is sufficient, and `[no-close]` in the title empties BOTH** (card#8344) — see the marker's bullet below.

| PR | On merge |
| --- | --- |
| branch `card-4811-widget`, title `feat: widget rework (card#4811)`, base `dev` | card 4811 → `stages.merged` — **structural** |
| branch `fix/streaming-timeout`, title `feat: widget rework, Closes card#4811` | card 4811 → `stages.merged` — **lexical** |
| branch `fix/streaming-timeout`, title `fix: thing (Fixes DL-239)` | every card DL-239 tracks → `stages.merged` — **lexical** |
| branch `fix/streaming-timeout`, title `feat: rework, follows card#4811` | **nothing moves** — a mention, warned in the log |
| branch `card-9999-other`, title `feat: rework, follows card#4811` | card **9999** moves; card **4811 does not** — the foreign mention |
| branch `release/v1.2`, title `chore: release v1.2 — includes DL-239` | **nothing moves** |
| branch `revert-611-card-4811-widget`, title `Revert "feat: widget rework (Closes card#4811)"` | **nothing moves** — a revert takes NEITHER route (card#8306) |
| branch `card-4811-widget`, title `docs: cite the prior ruling [no-close] (card#4811)`, base `dev` | **nothing moves** — the author declared a non-closure (card#8344) |

**Why the branch may speak, when the title citing the same card may not.** The head ref is not prose: it is minted by this install's own tooling (`board-card-start` → `card-<id>-slug`), which is why the *Card-token correlation* rules below already treat it as **authoritative over the title**. The property the gate exists for is *no token that merely APPEARS may move a stage* — and **quoting someone else's card id does not rename your branch**. A citation cannot manufacture either half of the structural term, which is why widening to it preserves the property rather than trading it away. `merged-into-integration` is, in turn, exactly the proposition the Shipped stage asserts.

The lexical verbs are GitHub's own linking keywords — `close`/`closes`/`closed`, `fix`/`fixes`/`fixed`, `resolve`/`resolves`/`resolved`, case-insensitive. `App\Bridge\Support\ClosureGrammar` owns that half, `App\Bridge\Writeback\PrOutcome::mergeClosesCard()` owns the structural half, and `PrOutcome::describeClosure()` renders **both** for operators — `bridge:check` prints it at you per mapping, so this table can go stale but the check cannot.

**Properties worth knowing before you adopt it:**

- **A bare mention is a NO-OP, never a demotion.** The writeback re-classifies in-window PRs on every pass (redeliveries, `bridge:replay`, and `bridge:reconcile` on a schedule), so a rule that returned an *earlier* stage for a mention would not gently correct the mistaken cards — it would **mass-demote every already-correct card on the first run**. Withholding the move instead leaves every existing stage exactly where it is. **Nothing needs backfilling on upgrade.**
- **The failure direction is safe.** Missing evidence leaves a card UNDER-promoted, which you fix by moving it, and the log says which card and which two surfaces were read. The old behaviour failed toward an irreversible stage.
- **⛔ Release merges take the structural route NOT AT ALL.** Only a merge into the integration branch can close a card structurally. A release PR's head is a disposable `release/vX` naming no card, so the term would rarely fire there anyway — making it a *condition* is what stops a future release convention that DID name a card from silently acquiring a terminal-stage move. `merged_to_main` still needs a closing form.
- **⚠ The branch must name a card TOKEN, not merely carry its digits.** `card-4811-widget`, `card#4811-…`, `fix/card-4811-…` and glued `card4811-…` all close card 4811; the older **`fix/4811-widget`** spelling does **not** — it carries the id but no token. This is deliberate: the bare-id test used elsewhere for *corroboration* accepts an accidental match (`chore/bump-1-2-3` vs `card#2`) on the grounds that it can never authorize anything, and that is untrue of a gate onto a terminal stage. ⚠ **That cost figure has since MOVED, and how it moved is the point.** *About 1 merged PR in 59* was measured while this seat's branches were `card-<id>-slug`, where the structural route fires on almost everything. The convention then changed to `<type>/<id>-slug` and the same strict reading went to **8 in 8** — every PR, for three days, each one loudly warned into a log nobody was reading, each card corrected by hand (card#8294). Nothing was wrong with the rule; what was missing is that **no document declared card promotion depended on the branch shape, and no check asked**. So read the number as what it was: a measurement of one convention, not a property of this gate. If your branches are `<type>/<id>-slug`, the structural route **never** fires — write the closing form in the title. In THIS repo that is enforced at PR time by `.github/workflows/pr-title-lint.yml` § *Require a closure claim on a card-correlating PR*, which reds a PR correlating a card that claims closure on neither route; the convention itself is stated in `CLAUDE_CONVENTIONS.md` § *PR titles*. An install without that gate has the log warning and nothing else.
- **⛔ A REVERT closes nothing on either route (card#8306).** GitHub composes a revert PR by **quoting** the original's title (`Revert "feat: widget rework (Closes card#4811)"`) and **wrapping** its branch (`revert-611-card-4811-widget`), so once titles carry a closing form — as this repo's convention now requires — a revert inherits both a closing verb and a card token **for work it UNDOES**, and merging it moved the card FORWARD. Both routes fired: the quoted verb on the lexical one, and the wrapped ref on the structural one wherever the original branch was spelled `card-<id>-slug`. `App\Bridge\Support\RevertGrammar` owns both shapes; `ClosureGrammar` subtracts the quoted span and `PrOutcome::mergeClosesCard()` asks `RevertGrammar::isRevert()` — **both surfaces, not the ref alone**, because a hand-made `git revert` pushed to an ordinary branch wraps no ref and announces itself only in the title. Both authorities are the ones the event path and `bridge:reconcile` already share, so neither can disagree with the other. **Correlation is untouched** — the revert still stamps PR refs, and the `opened` outcome still fires. ⚠ Read that second half precisely: the `opened` TARGET is emitted, and `KanbanMoveCardHandler::isRegressiveMove()` then refuses it whenever the card already sits at or past the In-Review stage — which is the DOMINANT case, a revert of a merged PR whose card reached Shipped. It moves only where the card is still BEHIND that stage, where *a PR exists* is true of a revert too. Only the completion claim is refused. **A revert of a revert does not close either**, deliberately: GitHub does not escape the inner quotes so the nesting depth is not reliably parseable, and the first revert never moved the card back, so there is nothing for the re-apply to promote. **To close a card ON a revert, put the closing form OUTSIDE the quoted title** (`Revert "…" — deliberate, this completes it (Closes card#4811)`); that re-closes the card the PR correlates, and it cannot redirect the move to a different card, because on a GitHub revert the branch is authoritative and names the reverted one. ⚠ **Only the RUNTIME half changed** — `.github/workflows/pr-title-lint.yml` has always exempted `revert-*` branches, but that governs what CI DEMANDS of a revert, never what the writeback READS in one.
- **⭐ `[no-close]` in the PR TITLE closes nothing on either route (card#8344 / DL-327)** — the one refusal here that no predicate over the artifact could derive. Since DL-308 the **head ref's identity** closes a card structurally, and a PR written *for* a card while deliberately not finishing it (a design note, a reference doc, a partial spike) is built on **exactly that ref** — so merging it promoted the card into a terminal stage, asserting work landed that did not. Measured twice on real merges. Nothing in the title, the branch or the diff distinguishes the two cases; only the author can, and the marker is how they say so. **It is the same literal `pr-title-lint` has always accepted** (card#8294) — no second vocabulary — and it is now read by the writeback as well as by CI. ⛔ **It only ever WITHHOLDS a move.** It cannot select a card, authorize a stage, overturn a guard, or move anything the writeback would otherwise have left alone, which is precisely why it is not the accept-surface DL-318 declined for the inverse `[close-anyway]` marker: that one would have MOVED a card the writeback refused to move. ⛔ **It is a literal, not a grammar** — `no close intended`, `does not close this card` and `[noclose]` are prose and close the card exactly as before; case is free and position is free. **Correlation is untouched:** the card is still selected, `opened` / `started` / `closed_unmerged` still fire, the PR refs are still stamped, and `bridge:reconcile` still sees the card — so removing the marker (or moving the card by hand) is all it takes to recover. ⚠ **A marker inside a quoted revert title is the ORIGINAL author's** and does not veto a closing form this author wrote outside the quotes (DL-318's quotation ruling, applied to the second marker). ⛔ **It is INDEPENDENT of the card-side pin** (`block_reason` / `no-automove`, DL-178 / card#8289): both hold, either alone withholds the move, and neither can override the other — the marker is read at classify time off the event payload's title (so no move target is emitted at all), the pin at write time off the card. The withheld-merge warning and `bridge:reconcile`'s skip line both name the marker as the reason rather than the default sentence, which is false about a marked PR whose branch does name its card. ⚠ **A title that merely MENTIONS the marker is marked by it** — a literal has no quoting rule, so a docs PR *about* this feature declares a non-closure by talking about one. Disclosed rather than repaired: the cure would be a position or context rule, which is the grammar this deliberately is not, and the failure direction is under-promotion. ⚠ **CI and the writeback read one literal through two engines** — `App\Bridge\Support\NoCloseGrammar::MARKER` owns the spelling and `PrTitleLintTest` ties the workflow's copy to it, comparing answer sets as well as the string. ⛔ **It withholds THIS writeback's move and nothing else.** Any other promoter you run that keys on a card's **DL / PR stamps** rather than on its **current stage** can still move a marked card, because correlation is deliberately untouched — the marker keeps the card out of `stages.merged`, so what protects it downstream is a SOURCE-STAGE guard on that promoter. This repo's own release CI is the worked case: `.github/workflows/release-promote-cards.yml` passes `shipped-stage-ids` to the toolkit's `promote` action, and without that input the action would promote every DL/PR-matched card to Released whatever stage it sits in. Read it as the general rule (`CLAUDE_DECISIONS.md` DL-327 § *Bounds*): a stage the marker keeps a card out of only helps against a promoter that reads stages.
- **The token must sit flush against the verb, and one verb closes one token.** `Closes the regression card#4811 documents` closes nothing; `Closes card#1 and card#2` closes only card 1 (write `Closes card#1, closes card#2`). Both are GitHub's rules for the same reason: the loose reading would call almost every title a closure.
- **Evidence must name the card that MOVES, through the same resolution that selected it** — the gate COMPOSES with the existing foreign-mention discrimination rather than replacing it. `Closes DL-239` authorizes the cards DL-239 resolved to. It does **not** authorize a co-present `card#N` that the DL-218 foreign-mention guard just ruled authoritative. Likewise a branch naming card 9999 closes card 9999 and nothing else — a bundled DL resolving to several cards, merged from a branch naming one of them, moves **that one alone** (a branch names one card; a `Closes DL-NNN` claim is made about the whole DL).

**What is NOT gated, and why:**

- **`opened` / `reopened` / `closed_unmerged` / `started`.** None of them claims a card is done. `opened` is reversible and is what stamps the card's PR refs so `bridge:reconcile` can find the PR later; `closed_unmerged` is an abandon disposition (revivable, DL-195); `started` has no merge for the structural route to read and a branch ref cannot carry a closing verb, so gating it would make every branch-create inert.
- **Dependabot cards** (`create_dependabot_cards`). They carry no card token at all and are correlated by PR number on their own handler — untouched.
- **The draft `block_reason` overlay** (`draft_overlay`). An overlay is a marker, not a stage move.
- **The release-promote sweep** (`promote_on_release`). It asks whether a card ALREADY in the Shipped stage has its commit on `main` — a question about a transition some earlier decision already made, not a fresh completion claim. The gate belongs where the claim is first made. See that section below.

**`bridge:reconcile` applies the identical gate over the identical two fields** (`GitHubReadClient::getPull()` projects both `title` and `head_ref`), because it re-derives the same proposition from the same evidence; without that, the backstop would keep re-planning on a schedule exactly the move the event path declined — or declining one it had just made.

**⚠ What this cannot do, and it is not a gap that a better grammar would close.** The gate reads **intent**; it cannot read **authority**. Two consequences follow and both are accepted, not fixed here: a card tracked by **several** PRs promotes at its **first** merged one, and a human who has ruled *"this card does not close on that commit"* is **overridden** if the card's own work PR then merges **and they left no marker on the card**. No predicate over a title and a branch name can know a person decided otherwise. ⭐ **The durable fix is card-side, and since card#8289 it is BUILT rather than proposed:** pin the card — a non-empty `block_reason` or a `no-automove` tag — and the writeback refuses to move it whatever the gate concludes, on **every** outcome including the merge (see *Pinned cards* under **How it works**). It lives on the board, not in this classifier, so it remains the human's lever rather than the grammar's; what stays true is that a human who rules and records nothing is still overridden.

## Setup (operator)

### 1. A least-privilege writeback token
Create a kanban API token for the mapped boards (NOT the broad provisioning token). ⚠ **"Card moves" is NOT the scope** — that spelling stood here from DL-019, when `KanbanClient` exposed `getCard` + `moveCard` and nothing else, and it understates what the writeback needs today. kanban authorizes a card PATCH by which fields it carries: a PATCH whose SOLE key is `workflow_stage_id` takes `task.move`, every other field set takes `task.update` (kanban DL-204 — `TaskMutator::update()` → `TaskPolicy` → `BoardPermissions`). The board permissions the writeback actually needs on every mapped board are:

| Permission | What needs it |
| --- | --- |
| `board.view` | every read (`getCard`, the board-scoped searches, the correlation lookups, `board_my_cards`) |
| `task.move` | the stage-only move — the move handler, the coord-card move, the **dependabot card's stage move** (`KanbanDependabotCardHandler`, the create-or-move arm's survivor move), the release-promote sweep, `bridge:reconcile --fix` |
| `task.create` | the dependabot tracking card, the coord card, `board_create_card` |
| `task.archive` | `_action: archive` — the closed-unmerged retire (DL-161) and the duplicate collapse |
| `task.update` | every non-stage-only field PATCH — the draft `block_reason` overlay, the `payload` correlation stamp, the **DL-328 dependabot name restamp** (`{name}` alone, on a card whose name is still the one the bridge stamped), and (DL-326) a `board_correct_card` correction of `name`/`description`/`tags` |
| `comment.create` | the card note the writeback posts when it drops a correlation leg or refuses a move (card#7064, `KanbanClient::addComment`) — **fail-soft**: without it the note 403s and alerts, the drop still reaches the log and the alert channel, and no move outcome changes. See the card-note paragraph below for what the silence costs |

A kanban **Member** role carries all six and is the simple answer. A **custom** role grants `create` / `update` / `move` / `delete` independently from its pivot JSON (and inherits `task.archive` and `comment.create` from Member), so a token narrowed to moves alone gets a **permanent 403 on every create, every overlay/stamp write, every dependabot name restamp and every correction** — surfaced as that arm's permanent refusal (`dependabot_card_4xx` for the restamp; `board_correct_card` reports it as a named INSTALL fault, [`docs/board-tools.md`](board-tools.md)), never a silent skip. ⚠ **`task.update` is NEW for the board-tools door at DL-326** — `board_my_cards` needs only `board.view` and `board_create_card` only `task.create`, so an install that granted exactly those two now 403s on every correction. Place the token:
```bash
# `read -rs` keeps the token off the terminal and out of your shell history — a
# here-string (or any command-line literal) is written to that file verbatim.
read -rsp 'writeback token: ' TOKEN
printf '%s' "$TOKEN" | install -m 600 /dev/stdin "$BRIDGE_DIR/kanban/writeback-token"
unset TOKEN
```

`printf` is a shell builtin and `install` reads the value on **stdin**, so the token reaches no argv — see [`docs/config-schema.md § Handling a secret VALUE`](config-schema.md#handling-a-secret-value-not-just-its-file) for the rule this follows.

The writeback also posts a **card comment** when it drops a correlation leg or refuses a move (card#7064), so grant the token **comment-create** on those boards as well. It is not required for the writeback to work: a note the token cannot post fails soft — the drop still reaches the log and the alert channel (`cardnote_403_not_writable_by_this_token`) — but the card itself then shows nothing, which is the silence the note exists to remove. **Not retried within a delivery, but re-attempted on the next event asserting the same drop:** the once-per-note check reads the card's stored comments, so a note that never landed is never "already there" — an install missing the grant re-POSTs (and re-alerts) on every later event carrying that drop rather than going quiet after the first.

> **A note write EMITS a kanban webhook.** `POST /api/v3/tasks/{id}/comments.json` records a `comment.created` changelog event (kanban `CommentMutator::create` → `ChangelogRecorder`), delivered to that board's subscribers — a new event **type** out of a bridge path that previously emitted nothing back. It reaches this bridge only if an agent subscribes to it, and what keeps the writeback from waking on its own comment either way is the global echo set seeded from `writeback.json`'s `identity_id` (suppression is keyed on the ACTOR, not the event type — DL-018/019). `bridge:check` only **warns** when `identity_id` is unset, so confirm it is set.

> ⛔ **Mint this token as its OWN kanban user — never a human's, and never one your board CLI already authenticates as.** `identity_id` is first of all the **echo-suppression key** (§ above); this section is about the second thing it consequently declares — which user the writeback authenticates as. `last_stage_move.actor_id` is the only writer attribution a kanban card carries, so the moment two writers share a user it stops discriminating: `actor_type: service, actor_id: N` then means *"some PAT"*, not *"the bridge"*, and no move on that board can be attributed to anything. Measured in both directions on two installs — one where the board CLI's token and the writeback token both resolved to user 3 (a card move was very nearly mis-attributed to the writeback on exactly that evidence; that install has since cut the writeback over to its own dedicated kanban user, so its `actor_id` now discriminates), and one where they were distinct users and the same question answered cleanly. **The repair is the argument for the check, not against it** — the property is a function of how an install was provisioned, and a fresh one can be misconfigured the same way tomorrow. `bridge:check` **reports the `identity_id` your config declares** so you can see it; it neither resolves the token against the API nor makes any **separation claim**, because a CLI's token on the same host is outside this install's config and invisible from here. Resolve every kanban token this seat holds against the API and check that none of them is that user.

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

> **The mapping key names a repo, and repo names are case-insensitive (DL-293).** GitHub treats
> `owner/repo` case-insensitively, so `PupFuzz/kanban-board` and `pupfuzz/kanban-board` are one
> repo and the bridge matches them as one — the key is canonicalized for the compare exactly as
> the kanban server canonicalizes a card's `source`. Before DL-293 the lookup was a raw key match
> against the payload's `repository.full_name`, so a key spelled any other way than the owner's
> registered display casing matched **nothing, silently, forever**: every writeback leg reads "no
> mapping" as "this repo is not tracked" and returns — the handlers on an info log nobody reads,
> the classifiers with no log at all — so a misconfigured install and a deliberately untracked
> repo produced identical output. **Two keys that name the same repo fail
> the config closed at load**, naming both spellings — they used to be two mappings, they can only
> be one, and picking one silently is how a board gets written by the wrong mapping.
>
> The spelling you write is **kept**: it is what `bridge:reconcile` resolves a per-repo token with
> (the credential store's `[git-credential-map]` IS case-sensitive — DL-185), what the
> `promote_on_release` leg's runtime token probe uses, what `{repo}` renders into a dependabot
> `id:` tag, and what every `bridge:check` line prints back to you, so you can find the line in
> your own file.
>
> ⛔ **The DISPATCHER does not share this — spell the agent YAML `scopes:` entry and the mapping key
> the same way.** `SubscriptionRegistry` matches a subscription to a delivery by **exact** spelling,
> so a delivery whose `repository.full_name` differs from the `scopes:` entry reaches **no agent at
> all** — no dispatch, no log, no alert, and `mappingFor()` never even runs. `bridge:check` warns
> **`SPELLING SPLIT`** naming both spellings when it sees the two files disagree; the writeback
> matching them as one repo is not, on its own, evidence that anything is being delivered.

> **`closed_unmerged` — In Progress vs a "Won't Do" terminal (operator choice).** The example maps `closed_unmerged → In Progress` because a closed-unmerged **DL-tracked** PR usually means *work continues* (rework, not abandonment). If your board has a **terminal "Won't Do" / "Cancelled" column** (`lane_type: done`, positioned far-right) and you'd rather treat a closed-unmerged PR as an *abandon-disposition* (dependabot dismissals, `[DO NOT MERGE]` diagnostics, superseded/rejected PRs), map `closed_unmerged → <that stage id>` instead. The no-regression guard (DL-163) **allows this terminal move** — it special-cases `closed_unmerged` and never applies the forward-only check to it, so a card in In-Review moving to a far-right Won't-Do terminal is permitted (it's a disposition, not a regression), and once there the card is sticky (no stale PR event can drag it back out) — **unless you opt into `revive_on_reopen` (DL-195), which revives such a card from that stage when its PR is reopened (see below).** The **dependabot** path is unaffected either way — a closed-unmerged dependabot card always **archives** (DL-161), ignoring this mapping.

### Branch-create → In Progress (DL-160)

A GitHub `push` that **creates a branch** whose ref carries a `DL-NNN` (e.g. `feat/DL-160-x`) or a **card token** (e.g. `feat/card-3054-fix` — same FR-7 try-in-order resolution as the PR path, DL-179/DL-201) promotes the correlated card to the **`started`** stage — "work has begun" derived from the artifact, no agent involved. It is gated three ways so it can only ever advance a card forward:

- **Fires once, on creation** (`payload.created === true`) — a subsequent push to the same branch is a no-op.
- **No-regression guard.** The move is applied **only** when the card's current stage is in the mapping's **`started_from_stages`** (the board's Backlog/Prioritized stage ids, and optionally **Held** — see below) **or `unpark_from_stages`** (DL-194). A card already in In-Review/Shipped/Released is left untouched, so re-creating or force-pushing an old branch never drags it backward. `started_from_stages` is parsed strictly (a numeric list — a non-list / non-numeric element fails the config closed). **If `started_from_stages` is absent, a `started` move is refused** (the guard can't know what's safe to promote from) — set it to enable the trigger.
- **Held auto-promote + pinned opt-out (contract PR #113).** To un-park a **Held** card automatically when a branch is created for it, add the board's Held stage id to `started_from_stages`. The `created === true` classifier gate already makes this safe against re-fires — a re-push/force-push never emits `started`, only a genuine branch birth does. The escape hatch: a card a human wants to keep parked is not auto-promoted if it carries a non-empty `block_reason` **or** a `no-automove` tag — the handler refuses the move (loud `warning` + an `alert_channel` signal), regardless of stage **and regardless of outcome** (card#8289) — **except from an `unpark_from_stages` stage (DL-194), where the branch-cut deliberately overrides the pin and emits a compensating auto-unpark alert instead of refusing; see *Auto-unpark* below.** This mirrors the toolkit `board-card-start` hook's local half of the same contract.
- A `dependabot/*` branch, or a ref carrying **neither** a `DL-NNN` **nor** a card token, emits no target.

Which card spellings count is **not restated here** — this path runs the same parse as the PR path, so the accepted set is the one under **Card-token correlation** below. (It was restated here once, as `card-<id>`/`card#<id>`, and stayed narrower than the code for two releases after the glued spelling landed — card#5267.)

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
`bridge:check` probes each mapped board with the writeback token via a cheap `limit=1` read of the pagination `meta.total` (DL-029): it reports the visible card count and warns loudly if it sees **0 cards** (the token's user is likely not a board member, or `board_id` is wrong — the writeback would silently no-op). In `scan` correlation mode it also warns if the board is larger than the scan ceiling (`MAX_PAGES × 200` = 10,000 cards) and points at `BRIDGE_WRITEBACK_CORRELATION=ref`. If a mapping pins a `swimlane_id`, it also checks the lane actually exists on that board. It also warns when a mapping is **orphaned** (#2162) — no agent runs a writeback-emitting classifier (`EmitsWritebackReactions`, i.e. `GitHubPrCardMoveClassifier`) subscribed to that repo's github scope, so the mapping is inert and no card would ever move. It warns when the branch-create `started` trigger is **half-configured** (#2652) — exactly one of `stages.started` / `started_from_stages` set, which leaves the trigger silently inert — and when any **mapped stage id** (a `stages.*` value or a `started_from_stages` id) is **not on the board** (a typo that would 422 the move, or make the `started`/no-regression guard silently never match).

**None of these fail the check** — a genuinely-empty new board, or a mapping added before its agent, won't. They are *not* all `warn`, though: since DL-251 these legs split across two severities, `warn` where the leg answered and the answer is bad, and **`unvalidated`** where it could not answer at all — a board read that threw, a comparand that did not resolve, or (DL-255) an agent whose config `bridge:check` could not read, which leaves the orphan and coord-card-move questions unanswerable because the maps behind them are short an agent. `App\Bridge\Support\Severity` owns the rule that decides which; this paragraph deliberately does not restate it.

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
| **`renamed`** (a `pull_request.edited` that changed the title) | yes, **and its name is still byte-identical to the title the bridge stamped** | **restamp the card name** to the new title (DL-328) — no column move |
| `renamed` | yes, but the name was changed by a human | **nothing** — the name is not the bridge's to overwrite (DL-328) |
| `renamed` | no | **skip** — nothing to restamp, and never a create |
| move / closed-unmerged / **collapse** | yes, and **PINNED** | **refused** — no stage-or-lifecycle write at all: the lifecycle move, the closed-unmerged archive and the create-race collapse's duplicate-archive are all withheld (DL-335, widened by DL-340, see below); the DL-328 **restamp is NOT** — it writes `name` alone, see below |

**Closed-unmerged dependabot PRs archive the card (DL-161).** Dependabot routinely closes its own PRs (a newer bump supersedes an older one, or a maintainer closes it), so a closed-unmerged dependabot card is dead weight. It is **archived** (retired off the board), not moved to a column — so it needs **no `closed_unmerged` stage mapping** (that key is ignored for the dependabot path). Archiving uses the kanban lifecycle verb (`PATCH {"_action":"archive"}`), and the bridge checks the response confirms it (a field-write `archived_at` PATCH silently no-ops); a 200-that-didn't-archive is logged loudly and skipped (never retried — that failure is deterministic). Idempotent: an archived card is excluded from correlation, so a redelivered close finds nothing and no-ops. (The DL-tracked move path is unchanged — a closed-unmerged *DL* PR still just moves, since work there typically continues.)

**A PINNED card is refused on every write that touches its STAGE or its LIFECYCLE (DL-335, card#8454; widened to the collapse by DL-340, card#8523).** A non-empty `block_reason` **or** a `no-automove` tag is the same DL-178 human hold the move handler honours on every outcome, and until this shipped the dependabot handler was the one event-path mover that never read it — so a closed-unmerged dependabot PR **retired a card a human had parked**, while `bridge:reconcile` and the release-promote sweep both skipped it and the backstop could not repair what the event path had taken off the board (card#8289's asymmetry, one handler over). The refusal is loud — a `Log::warning` plus an `alert_channel` signal under `pinned_no_automove`, the same reason the move handler emits. ⛔ **There is no override here:** the DL-194 unpark and the DL-195 revive are `started` / `reopened` outcomes a dependabot PR never produces. ⚠ **The card stays correlated** — nothing is unstamped and nothing is created, so lifting the pin lets the PR's next event complete the move; a close, however, is delivered by GitHub once, so a card pinned through its own close stays live on the board until someone archives it by hand. That is the intended reading of a hold. ⭐ **The create-race collapse is covered too, since DL-340 (card#8523).** Where a repo+PR correlates to **more than one** card, `CardCollapse::toSurvivor()` runs *before* the move consult and used to be pin-blind, so a pinned **duplicate twin** was archived anyway while the survivor's move was withheld — the residual DL-335 alternative (b) disclosed. The operator reversed that ruling for the reason the disclosure itself named: the twin a human notices is the twin a human pins. The consult now lives **inside the shared kernel**, so it reaches *every* caller of that kernel rather than the one whose call order was reordered — `CardCollapse`'s own docblock owns that caller population and states the grep that re-derives it, which is why no count is repeated here; it is taken **per card**, so the unpinned duplicates of the same key are still retired and the unpinned survivor still moves. ⚠ **What that leaves on the board:** a refused twin means two live cards for one PR until a human resolves it — the honest outcome of a hold, and the alternative was archiving the card whose hold was the whole point. Its alert carries `kanban_dependabot_card` as the `outcome` (the subsystem, not the synthetic `dependabot_card`), so a collapse refusal and this handler's own pin refusal never share a dedup marker. ⚠ **One alert per repo, not per card:** this handler's dedup tuple carries the synthetic `dependabot_card` outcome (see *the alert reasons* below), so the first pinned card in a repo signals and later ones reach the durable log only. ⚠ **The DL-328 name restamp is NOT one of them, and this states it rather than leaving it to the word "refused":** DL-335 was ruled before that arm existed, and DL-340 widened the pin across the stage-and-lifecycle writes only — the scope DL-178's own 2026-08-30 annotation sets — so a pinned card whose name is still the one the bridge stamped is restamped anyway. That is a narrower write than the three above — it touches `name` alone, moves no card and retires none — but whether a hold should stop it is a ruling nobody has made, and it is filed as such rather than decided here — on **card#8557**, which is where the question now lives and where its arms are enumerated (there are more of them than when DL-178's *"the pin governs the card's STAGE only"* annotation was written, which is the point of re-opening it).

**An upstream RETITLE restamps the card name — but only a name the bridge still owns (DL-328).** Dependabot retitles its PR *in place* when it retargets a bump (`…from 5.9.0 to 7.0.2` becomes `…to 6.0.3`), and a card named once at birth then asserts a version that never shipped. On a `pull_request.edited` carrying a title change the bridge writes the new title onto the correlated card — **gated on the card's current name being byte-identical to the PR's PREVIOUS title**, which GitHub delivers on that same event as `changes.title.from` and which is exactly the string the bridge stamped when it minted the card. A card whose name differs by so much as a space was renamed by somebody, and is left alone: trading a stale machine name for a destroyed human one is not an improvement, and the classifier hands the handler the upstream title on *every* event, so an unconditional write would clobber that rename on the next webhook. Nothing here reads the *shape* of a name, nothing assumes the version moved forward (the observed drift runs both ways), and nothing derives the name from the head branch ref — the ref is frozen at branch creation while the diff is retargeted, so it names the bump the PR no longer carries.

> ⚠ **Bound: the evidence lives on the rename event.** A retitle the bridge never received — it was down, the repo opted in afterwards, or the card predates this leg — leaves a card no later event can prove ownership of: the merge event carries the new title but no witness for the old one. Those cards are a **one-off backfill**, not something this leg repairs. The write is a flat `PATCH {"name": …}` on the card and touches no other field, so a lane, a column or a payload edit made in the meantime survives it.

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

> **Card-token correlation — the one place the accepted spellings are written for operators (FR-7 / framework v0.2.229, DL-177; dash alias + DL-shaped boundary DL-201).** A PR whose **title or head branch** carries `card-<id>`, `card#<id>`, or the glued `card<id>` (case-insensitive on `card`) moves that card by its **native kanban task-id** — the channel for cards with no DL number. The token boundary is **DL-shaped: leading `\b` only, no trailing `\b`** (`/\bcard(?|[-#](\d+)|(\d{2,}))/i`), the same shape the `DL-NNN` regex has always had — so `feat/card-3054_fix` correlates card 3054 (a trailing `\b` made that a *silent* no-op, roundtable #48), while embedded words (`discard-1`, `wildcard-2`) never match. The digit class is **ASCII `[0-9]` only** and that is ratified fleet-wide (DL-231): the pattern deliberately carries no `/u`, because PCRE `\d` would then also match Unicode decimal digits that the fleet's bash engines cannot match — the same token would correlate through one consumer and be invisible to another. Any other engine implementing this grammar matches ASCII digits only. The **glued** spelling is accepted since DL-233 (roundtable #159) and requires **≥2 digits**, while a separated token accepts one: the 2-digit floor is the toolkit's (`board-card-start`, glued since v0.17.0) and is what stops an ordinary word correlating — `card2go` names no card, `card-3` does. A branch/PR that *appears* to name a card in a shape the token still doesn't accept (`card_123`, `card.123`, `card:123`, `card #123`, single-digit glued `card4`, and every **separated or glued plural** spelling — `cards #123`, `cards#123`, `cards-123`, `cards123`, `cards_123`, `cards:123`, `cards.123`, none of which names a card, DL-250) is warned loudly as an **FR-7 near-miss** rather than silently dropped — the ratified convention is `card-<id>` (dash), with `card#<id>` and glued `card<id>` also accepted. **Beside a `DL-NNN` that RESOLVES, such a spelling now REFUSES the move, where before it was invisible to the guard entirely (card#6027 / DL-287)**: the near-miss is *read* for the id it appears to name, and if that id is one of the cards the DL already resolved to it is redundant (the DL wins, nothing dropped, one `info` line); otherwise this event names two different cards and only one of them readably, so the move is refused and alerted (`card_token_near_miss`) rather than handed to the DL's card. The recovered id may **refuse or warn, never select** — a spelling the grammar rejects does not become a correlation channel through the back door. A **bare-space** `cards 123` is the one plural spelling that stays **silent**, exactly as `card 123` does: DL-201 ruled that prose ("supports card 2") warns nowhere, and DL-250 kept that ruling rather than letting the plural widen into it. (The CI PR-title lint's warning text says *"the plural in any spelling"* and is correct where it stands — that list is headed **Common misses**, i.e. shapes that do not CORRELATE, and bare-space `cards 123` correlates to nothing either. This list is the narrower one: the shapes that actually WARN. A sweep that finds both should change only this one.) The near-miss probe's digit class is ASCII too, so a **Unicode-digit** token (`card#٣`) still warns nowhere in the bridge log; the PR-title lint warns on that class in CI instead. (Since DL-273 that probe is a shared primitive, `NearMissProbe`, bound to a stem: one separator list serves both tokens, so the card and DL near-miss sets cannot drift apart. See *`DL-NNN` near-miss* below.) No `source` qualifier, no classify-time kanban read: native ids are globally unique. What the durable handler then refuses is **not one guarantee** and is worth stating as the code delivers it (card#5287 — the single-clause version of this sentence claimed only the board guard, and an auditor who read it stopped there): a card it **cannot read** is refused at the `getCard` 4xx arm, which returns *before* the board guard ever reads `board_id` (403 vs 404 split so you get the right hypothesis — see *Why the `getCard` refusal splits 404 from 403*); a card it **can** read that is not on the operator-mapped board is refused by the board guard; and a card named by an **uncorroborated title-only** `card#` is refused unless that card tracks no PR yet or already tracks this one (see *Which SURFACE the `card#` came from* under **Precedence** below). On the same mapped board the first two never fire, which is why the third exists. The core no-regression guard + `*_from_stages` allowlists then apply exactly as for a DL move. The token only *selects* the card — board + stage always come from the repo's `writeback.json` mapping, never PR text — and since DL-305 selecting is no longer sufficient on a MERGE outcome: the merge must additionally CLOSE the card it selected, which since DL-308 the **head branch ref** can do structurally as well as the title lexically (*Mention vs closure* above owns both routes, and it is a separate question from which spellings correlate — every spelling listed here still correlates). ⚠ Note the asymmetry the structural route introduces: a **bare id** in the ref (`fix/5287-slug`) still CORROBORATES a title token here, but it does not CLOSE the card — closing needs a full token in the ref. `pr_number` stays the orthogonal PR-first (dependabot) path.

> **`DL-NNN` near-miss (DL-273).** The DL token has the same warn-on-a-near-miss treatment as the card token above, and until DL-273 it had none at all: a branch or title carrying `DL_239`, glued `DL239`, `DL#239`, `DL.239`, `DL:239`, `DL #239` or any **plural** spelling (`DLs-239`, `DLs239`, …) named no DL, moved no card, and said nothing. Each of those is now warned loudly as an **FR-7 near-miss**, with the accepted set rendered from `DlTokenGrammar` rather than spelled out here — the separator is **mandatory** and there is no glued arm, which is the one place the DL token is stricter than the card token. A **bare-space** `DL 239` stays **silent**, the same DL-201 prose ruling that keeps `card 123` silent.
>
> **The two silences DL-273 named are CLOSED (card#5961), and how each closed is the point.** (1) A **Unicode-digit** DL (`DL-<U+0663>239`) still warns nowhere in the bridge log — the runtime probe is ASCII by ratification (DL-231) and that is not being reopened — but the CI PR-title lint now carries a **DL arm** beside its card arm, at the same severity (a `::warning::`, exit 0 pinned — DL-234(c)), whose operator text names the non-ASCII-digit class **by name**. That is exactly the position the card token has always been in, reached for the DL token. (2) A subject whose **card token parses** beside a malformed DL — `feat/card-3410-slug-DL_272` — still draws **no near-miss line in the bridge log**: the probe stays behind the whole-subject guard (DL-234(e)), because a correlating token means the card *does* move and the near-miss line's own "no move" clause would be false about it. What is no longer silent is the **consequence**: the move target carries **no `dl_number` stamp** (`DlTokenGrammar::sole()` returns null on the malformed token), and a **distinct** warning is emitted where the stamp is built. It claims only what is true at classify time — a move is *emitted* (the durable handler may still refuse it, as it may any move) and no `dl_number` rides it. A lost STAMP and a lost MOVE are two signals, made at two sites, and neither is the other reused.
>
> **The THIRD silence was the CARD-stem mirror of (2), and it closed the same way — at its own site (card#6027 / DL-287).** A subject whose **DL resolves** beside a malformed **card** token — `Guard against DL-9 (card_4811)` — drew no line either, and for the same structural reason: the probe sits behind the both-null guard, and this subject *does* move. It moved the **wrong card**. `card_4811` did not parse, the conflict predicate read the resulting null as "no card token", and the DL-218 guard — which exists precisely to stop a descriptive DL outranking an intentional `card#` — switched itself off, moving the DL's card and stamping this PR's number onto it. So the signal is made where the consequence is: at the DL-win sites, where the token is now three-state (absent / parsed / **unreadable**), a redundant near-miss logs `info` and a differing one **refuses**. The probe's own guard is untouched, exactly as in (2). What DID change there: the **no-move** arms (a DL that resolves to nothing with no `card#` fallback) now run the probe too — nothing moves on those arms, so its "no move" clause is true about them, and the misspelled fallback that was sitting in the subject all along is finally named. The probe answers per stem, so an arm reached with a *parsing* DL never draws a DL line.

> **Correlation-key stamping (DL-187, extended by card#4852).** A card moved by the writeback may carry no `dl_number`/`pr_number`/`pr_url` — which is exactly what `promote-released-cards` correlates released cards by (and `pr_url` is what kanban's multi-repo by-ref `source` derivation keys on), so such a card strands in Shipped-to-Dev at release time. The writeback therefore stamps those refs **add-if-missing** (never overwriting an existing/human-set value) as a step distinct from the column-only move. **Both** the `card#` path and the **DL-resolved** path stamp the **PR provenance** (`pr_number` + `pr_url`) — a DL-only feature PR (no `card#`) otherwise moved its card but stranded it without either. The `dl_number` is stamped on the **`card#` path only**, and only when the title+branch carries **exactly one** `DL-NNN` (a bundled/release-shaped PR with 2+ DLs, or a foreign DL, stamps the PR refs only — never a wrong DL), stored canonical zero-padded (`DL-%04d`); a **DL-resolved** card never re-stamps `dl_number` — it already carries the `dl_number` that resolved it. add-if-missing is what keeps a release PR whose title merely names a feature card's DL from overwriting that card's own PR refs. The stamp is best-effort with the move's transient/permanent split (a 4xx like "board has no such custom field" is logged + skipped; a 5xx propagates so redelivery re-stamps) and runs only once the card is legitimately at/entering its target stage (never from a guard-rejected event). This makes `kbcard --dl` at decision-time unnecessary going forward for card-first-tracked cards.
>
> **A ref that is offered and DIFFERS is a dropped leg, and it is now RECORDED (card#7064).** add-if-missing is a guard, not the whole story: a card carries **one** `pr_number`/`pr_url`/`dl_number`, so a **second pull request** naming the same card has its refs dropped. That is the correct outcome — overwriting would re-point an already-merged leg's correlation, and the second PR is then simply not reachable by a by-ref lookup on that card. What was wrong is that it used to be dropped **in silence on the more common path**: a well-formed PR whose head branch carries the card token is corroborated, so the DL-270 gate below never fires, and the stamp had nothing left to write and returned without a word. The better-behaved the contributor, the less trace the lost leg left. Now the drop emits a `Log::warning` + an alert (`correlation_ref_not_stamped`) **and a comment on the card**, naming the ref the card keeps and the one this PR offered. **Which PR ends up stamped changes in exactly one case:** a `pr_url` holding the `.../pull/0` source-only qualifier (what `bridge:check` tells you to stamp for a shared-board `source`) names no pull request, so **a PR from the repo that qualifier NAMES** stamps its real url over it — previously the placeholder blocked that stamp forever *and* got the card's own PR reported as a second one. **Only that repo.** The `0` is the part of the placeholder carrying no information; the **repo** is the load-bearing half — a by-ref source an operator stamped on purpose — so a PR from a DIFFERENT repo leaves it alone and records the drop instead, rather than quietly re-pointing the card's source. That note is true as written: the card really does name a different repo than the PR that correlated to it. Everywhere else the never-overwrite guard is untouched. An idempotent **replay of the card's own PR** — the same refs it already stores, however many redeliveries — stays silent: the test is *a ref was offered whose value DIFFERS*, never *there was nothing to write*, and DIFFERS is asked per ref through that ref's own notion of equality: `dl_number` on digits, `pr_number` through the shared `CardTokenCorroboration::tracksPr`, and `pr_url` through **pull-request identity** (`PrUrlRef` — the same `(owner/repo, number)` parse `TrackedCardRef` resolves a card's tracked PR with, so repo case and a `/files` suffix are one PR, and a url naming the PR the card's own `pr_number` already answers is not a second one). The note is written at most **once per card per dropped SET of values**: its marker line is derived from those facts, not from the event, so the same drop re-asserted with the same values on `opened`, then `merged`, then a redelivery re-derives one marker and is matched against the comments the card read already returned. **The unit is the SET, not the pull request** — the offered refs are re-derived from each event (`stamp_dl` from the title+branch text), so a title EDITED between two events can offer a DL the first did not, growing the dropped set into a different marker and a second note about the same PR; that second note records a drop the first could not have named. **Not** a `pr_refs` list — that is a kanban payload-schema change (by-ref index, release-promote, `kbcard` projections) and is deliberately not what this is.
>
> **Precedence — try-in-order-with-fallback (framework #112, DL-179).** A PR/branch MAY carry both card-first tokens, and the resolver keys on the *outcome* of a token, not its presence: **(1)** `DL-NNN` **resolves** to a card → it wins (a co-present `card#` is logged as ignored — a ref naming two cards is almost always an operator error); **(2)** `DL-NNN` **unresolved** → fall through to a present `card#` (native-id move); **(4)** a token was present but **nothing resolved** and there is no `card#` fallback → a **high-value miss**, warned loudly, never a silent no-op. Committing to the DL the moment it is *present* (rather than when it *resolves*) was the live dead-end (DL-179): a `DL`-titled PR against an as-yet-unstamped card resolved nothing and never tried the `card#` that would have matched. The `card#` fallback stays **board-scoped** by the durable handler's existing board-membership guard (`card_not_on_mapped_board`), which gates DL and `card#` moves identically — so the classifier stays classify-time-read-free.
>
> **Which SURFACE the `card#` came from decides how far it is trusted (card#5287 / DL-270).** The token has two surfaces and they do not carry equal authority. The **head branch ref** is minted by your own tooling (`board-card-start` → `card-<id>-slug`), so it is the authoritative referent for what the PR is work *on*. The **title** is prose — a `card#NNNN` in it is as often a *descriptive citation of another card* as a claim about this PR, and the two are lexically identical. So: a title token that **disagrees** with the branch's token is treated as foreign and **refused** (the branch's card moves; the conflict is warned loudly), and a title token the ref does **not** back is passed to the handler marked *uncorroborated* and moves the card **only if that card tracks no PR yet, or already tracks this one**. Otherwise the move is refused (`card_token_uncorroborated`). **"Already tracks this one" is the same one-decorated-integer question the kanban by-ref index answers (DL-311):** `85`, `85.0` and `'085'` are all PR 85, while a stored `pr_number` naming no single pull request (`'1.5'`, `'2026-08-23'`) corroborates **nothing** and the write is refused. That direction is the point — this gate returns *refuse* when the card does **not** track the event's PR, so the pre-DL-311 `(int)` cast (which read `'1.5'` as PR 1) did not fail closed here, it **allowed** a title-only write onto a card the PR had nothing to do with. **The draft overlay applies the identical gate to a `block_reason` SET** (card#5953 — one shared predicate, not a second copy), and only to a SET; see *PR draft → `block_reason` overlay* below for why a CLEAR is not gated.
>
> **What counts as the ref backing it is deliberately wider than what counts as a token.** The ref corroborates when it carries a full `card-<id>` token **or simply the card's bare id** — so the ordinary `<type>/<id>-slug` branch (`fix/5287-widget`) corroborates `card#5287` in the title. A bare number can only ever **confirm** a token the title already produced; it can never **select** a card, which is exactly why the token grammar requires the `card` prefix (otherwise `chore/2026-cleanup` would correlate). Requiring a full token in the ref would have made almost every real PR uncorroborated — and the gate would then refuse any **second** PR against a card that already tracks a first, which is routine work, not an attack. (Bound: a numeric branch can accidentally corroborate a very low-id card — `chore/bump-1-2-3` vs `card#2`. That only relaxes a guard that did not exist before, and never widens what can be selected.) This uses evidence the handler's existing `getCard` already returns, so it costs no extra read. Before this, title and branch were concatenated and matched with a single non-global regex, so the **leftmost** match won — meaning a descriptively-cited foreign `card#` in the title silently outranked the branch's own correct token, and on the same mapped board every downstream guard passed. The branch-create `started` push path parses the ref only, so it is corroborated by construction and unaffected.

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
- **Create-only + idempotent.** This create path never moves or archives a card. (The bridge as a whole is no longer create-only for coord cards: its sibling **`move_coord_cards`** (DL-200, a guarded fleet default since DL-204 — below) carries close→terminal / reopen→revive, and the opt-in `coord-card-relane` family (card#6393) carries lane→lane on a later `stage:*` label. The reconcile still owns column/lifecycle wherever the move leg is **off**, and **archival remains the reconcile's alone**.) It correlates by the **`id:<sid>` tag**: if a card already carries it, it **skips** — which covers redelivery, opened+reopened, **and** the bridge-vs-reconcile race (both movers key on the same tag). After a create it re-reads by tag and collapses a raced duplicate (keep lowest id, archive the rest — the shared deterministic tie-break; a **pinned** duplicate is left alone since DL-340, card#8523). Durable, transient(5xx→retry)/permanent(4xx→log+no-op).
- **An ARCHIVED card is a retire, and the create leg honours it (DL-296).** Both correlation reads above are **live-only** — kanban's search applies `whereNull('archived_at')` unless `?archived` is passed, and its by-ref endpoint excludes archived rows outright — so a thread whose **only** card was archived reads as *un-carded*, and before DL-296 the next `issues.reopened` minted a second card over the retire (observed on a live board, not inferred). The create leg now asks the archive side explicitly (one `archived=1` tag search, issued **only** on the branch immediately before a create, so a skip pays nothing): an archived card carrying the `id:<sid>` tag **suppresses the create**, and the refusal **signals** (`coord_card_archived_twin`) rather than passing in silence — archival is the reconcile's and the operator's, never the bridge's to undo. **The one exception is the consumer's own reroute bookkeeping:** `kanban-reclass.py` archives a coord twin whose source re-routed to another board and stamps it **`coord:reroute-archived`**; an archived card carrying that tag does **not** suppress, so a source that routes back is carded again. That partition is the consumer's (`kanban_common.archived_stable_ids(...)` minus `reroute_archived_stable_ids(...)`) and the bridge holds the same one **at the same granularity — per THREAD, not per card**: the consumer subtracts sets of STABLE-IDS, so one reroute-tagged twin exempts the whole thread and a mixed archived set (one reroute-tagged twin **and** one hand-retired twin) is **carded again** on both sides. A per-card bridge would refuse a create the reconcile's next pass makes anyway, and tell the operator to unarchive a card that would not fix it. **Bound — the exemption keys on a MARKER, and only the consumer stamps one.** The bridge archives cards too and marks none: the duplicate collapse (`CardCollapse`) retires a raced twin carrying the same `id:<sid>` tag, and the dependabot leg retires its card on `closed_unmerged` (DL-161). So a bridge-made archive reads here as a hand retire. Harmless for the collapse, whose survivor stays live and answers the live pre-check first; it would bite only if that survivor were later hard-deleted. Tracked on card#7222. **Stated gap:** the non-prefixed (`issue_population: all`) path has no tag, and the by-ref endpoint takes no `archived` parameter, so there is no archived-visible key there — a retired **by-ref** card is still re-created on reopen. Tracked on card#7169.
- **`identity_id` is REQUIRED (echo-gate).** A created card fires a kanban `task.created` webhook that comes back to the bridge; if any agent runs the `kanban-triage` family on that board, that echo reads as an untriaged card and could **self-wake**. The **only** guard is the global-echo gate keyed on `writeback.json` `identity_id`. `bridge:check` **warns** when `create_coord_cards` is set but `identity_id` is null.
- **`bridge:check`.** Validates `coord_card_stage_id` (and any `swimlane_id`, and every `coord_card_lane_stage_ids` id) exists on the board — a typo'd id makes every write that reads it 422 and silently no-op. Which writes those are differs per key: `swimlane_id` is create-only (DL-027), `coord_card_stage_id` is the create *and* the revive, and a **lane** id is read by all three coord-card writes since card#6393 (create, revive, relane). Missing `coord_card_stage_id` while `create_coord_cards` is on **fails the config closed at load** (a create with no stage can't POST). **It also WARNS when this mapping sets `create_coord_cards` but no agent enables the `coord-card-create` family on that scope** (card#8292), naming both keys: the classifier dispatches on the family before it ever reads the mapping, so that install classifies nothing and creates nothing — `issues.opened`/`reopened` arrive and no card is ever made, with no error and no retry. Unlike the lane-model advisory below this is a **warning**, because a leg the operator explicitly turned on is genuinely INERT rather than merely un-adopted. Where a periodic reconcile runs it still backstops the **prefixed** set (not in real time); under `issue_population: all` the non-prefixed set is carded by nothing at all. It degrades to a cannot-verify **disclosure** when an agent config this run could not read might be the one enabling the family. **Since card#8305 the OTHER direction warns too:** an agent that enables `coord-card-create` on a scope whose mapping does **not** set `create_coord_cards` is equally inert — the family dispatches and then returns at its own mapping gate, so `issues.opened`/`reopened` arrive and nothing is carded — and that line names `coord_card_stage_id` beside the key, because a mapping setting `create_coord_cards` without it fails the config closed at load. ⛔ That direction is **not** a disclosure when an agent goes unread, and the asymmetry is deliberate: its family term is a POSITIVE map read, so an unread agent cannot make it accuse anything — only leave it silent, exactly as it is for every install that does not enable the family.

### Priority lanes: `coord_card_lane_stage_ids` (card#6371)

**Set this whenever the coordination board has a priority-lane model** (`user_lanes` in
`coordination.config.json` — Now / Next / Later / Maybe). Without it every coord card is created at
the single `coord_card_stage_id`, and on a lane-model board that is **not a placement, it is a
rewrite**: the consumer's `kanban-writeback` pass runs **before** its `kanban-issues-sync` and maps
each card's lane back onto the issue's `stage:*` label (over the board's `user_lanes`, which default
to all four lanes including Now), so the ISSUE's label is rewritten to whatever lane the bridge
created the card in — and issues-sync, reading the label the writeback just wrote, agrees. So a
`[TASK]` filed `stage:later` is carded in the create stage and its `stage:*` label edited to match.
Measured on the reference install: 9 issues flipped to `stage:now`, one within 7 minutes of filing.

```jsonc
"mappings": {
  "org/coord": {
    "board_id": 8,
    "create_coord_cards": true,
    "coord_card_stage_id": 21,                 // still required — the non-lane-model itypes' stage, and their revive target
    "coord_card_lane_stage_ids": {             // optional — the board's priority-lane stage ids
      "now": 13, "next": 14, "later": 15, "maybe": 16
    }
  }
}
```

- **What it changes.** A card whose issue the lane model governs is created in the stage its
  **`stage:*` label declares** (`stage:now` → the `now` id, and so on) instead of at
  `coord_card_stage_id`. Absent from the key ⇒ **byte-identical** DL-198 (every card at the fixed
  stage).
- **Which issues.** Only an issue whose **title starts with `[TASK]`** (case-insensitive, anchored,
  untrimmed) — mirroring the consumer's `classify_coord`, which gates the lane model on exactly that
  and *deliberately not* on the itype: `_itype` (like the bridge's own `itype`) calls an un-prefixed
  title `task` as well, so an itype gate would sweep the whole un-prefixed population — and
  `[PROPOSAL]`, which the bridge's `itype` also reads as `task` — into lane derivation, where the
  consumer's reconcile sends them to **Now**. A `[BRIEF]`/`[ANNOUNCE]`/`[QUERY]`/`[REVIEW]`/
  `[PROPOSAL]`/un-prefixed issue keeps landing at `coord_card_stage_id` — the **pre-existing
  fixed-stage behaviour, unchanged by this key, and not a claim that the two movers agree there**:
  the consumer's `classify_coord` sends a non-`[TASK]` open issue to *Awaiting ACK* when it is an
  announce and to *Now* otherwise, so the bridge agrees with it only where `coord_card_stage_id`
  IS the board's Now column, and never for `[ANNOUNCE]`. That bridge-vs-reconcile create
  disagreement predates this key and is recorded in DL-286's sibling audit; narrowing the lane
  model to the consumer's own `[TASK]` set is what keeps this change from widening it.
- **The CREATE path reads the labels the OPEN delivery carried.** It fires on `issues.opened` /
  `issues.reopened` only, so a `[TASK]` opened unlabelled is carded in `later`. Filing through
  `coord-post create --label stage:<lane>` (or the `coord-task --now|--next|--later|--maybe` wrapper
  over it) puts the label in the create call; an issue filed in the GitHub UI and labelled
  afterwards does not. **That is no longer a gap that ends in a rewritten label** — the opt-in
  `coord-card-relane` family (card#6393, below) moves the card to the lane a later `stage:*` label
  declares. Without that family enabled the card stays in `later` and the board→issue writeback
  converges the issue's newly-added label back to match it.
- **No label, or an unrecognized one ⇒ `later`** — `_task_lane`'s own default. Several `stage:*`
  labels resolve in the order **now → next → later → maybe**, again mirroring `_task_lane`, so both
  movers pick the same one.
- **A declared lane your map does not carry is SKIPPED and the scan continues** to the issue's next
  `stage:*` label in that same order, landing in `later` only when none of its declared lanes is
  mapped — mirroring `_task_lane`, whose column-availability test sits *inside* its loop (so an
  issue labelled `stage:now` + `stage:next` lands in **Next** on both movers: here because your map
  omits `now`, there because the board has no Now column — the two agree exactly insofar as your map
  mirrors the board's lane columns, which nothing checks for you). Either way a `WARN` names the unmapped lane(s), the lane used, and the lanes you did map.
  Deliberate: refusing the create would leave a thread untracked over a priority hint, and using the
  fixed stage would re-impose the lane this key exists to stop imposing.
- **Fail-closed at load** (so a half-configured lane model never silently no-ops): the value must be a
  **non-empty object**, every key must be one of `now`/`next`/`later`/`maybe` (an unknown key throws —
  a typo would otherwise match no label forever), every value must be numeric, it must carry
  **`later`** (the target of both fallbacks above), the lanes must map to **distinct** stage ids
  (two lanes on one stage cannot express the priority the label declares — the placement resolves to
  a stage that no longer says which lane it meant, and the board→issue writeback then relabels the
  issue with whichever lane owns it), **no lane may equal `coord_card_terminal_stage_id`** (the same
  disjointness the terminal already has with `coord_card_stage_id`: a card PLACED into the
  concluded stage — by a create, or by a revive on a create-off mapping — is one the move leg then
  reads as already-terminal, so its close no-ops), and the mapping must set
  **`create_coord_cards` and/or `move_coord_cards`** (DL-294) — every write that reads these ids belongs to one of the two families: the create leg places a NEW card in its
  declared lane, and the move leg's **revive** (plus the opt-in `coord-card-relane` family)
  RE-places an existing one, including a card the consumer's reconcile created and the shared
  `id:<sid>` tag correlates. With neither family on nothing reads the map, so it fails closed as
  configured scenery — the same inertness test, and the same either-family rule, that
  `coord_card_stage_id` already carries. **A move-on / create-off mapping may configure a lane
  model** (below).
  Overlapping the fixed
  `coord_card_stage_id` is fine — a board whose Now column IS the fixed create stage is a
  legitimate config.
- **The lane is read by three writes, not one (card#6393).** The key started as create-time only;
  since card#6393 the **revive** and **relane** legs read it too, through the one shared
  `CoordCardLanePlacement` primitive, so the three cannot disagree about where a `[TASK]` belongs.
  With `move_coord_cards` on, a reopened `[TASK]`'s card now returns to the lane its `stage:*` label
  declares instead of to the fixed `coord_card_stage_id` — the DL-286 sibling that used to re-impose
  that stage on every reopen. Everything else about re-laning is still a human/reconcile action: no
  other bridge path moves a card between lanes.

### Following a label added after the card exists: `coord-card-relane` (card#6393)

**Opt-in, and not a default.** Add **`coord-card-relane`** to the agent's `classifier.config.families`
— it is the only family that reacts to a label edit, and on a live coordination repo `issues.labeled`
is high-volume: **641** such deliveries had already arrived on the reference install and been dropped
as action-undeclared (an operator measurement, last delivery 2026-08-20 15:31:53 UTC — no delivery
corpus was reachable from the branch that wrote this to re-derive it). **No webhook-subscription change is
needed** — enabling the family turns a stream that already arrives into board writes, which is
exactly why the trigger filter below is the load-bearing part. It additionally requires
`move_coord_cards` (a relane *is* the bridge moving a coord card) and `coord_card_lane_stage_ids`
(without a lane model there is no lane to write). **Both are checked at classify time**, so a
half-configured install emits nothing at all rather than paying a card search and a card read per
delivery to discover it has nowhere to move anything.

- **What it does.** On `issues.labeled` whose **own announced label names a lane**, it moves the
  issue's tracking card to the lane that label set declares — the same resolution the create leg
  uses, over the issue's labels **unioned with** the announced one.
- **The trigger is the label this delivery announces**, read from the payload's top-level
  `label.name` — never "the issue happens to carry a `stage:*` label", which would relane on every
  unrelated edit to an already-sequenced issue.
- **And it must be one of the four lane labels** — `stage:now`/`stage:next`/`stage:later`/
  `stage:maybe`, not merely the `stage:` prefix. A `stage:`-prefixed label your install invents for
  something else (`stage:done`, `stage:blocked`) declares no lane this model knows, so resolving it
  would fall back to `later` and **move** the card there — demoting a sequenced `[TASK]` on a label
  that expressed no sequencing, and handing the board→issue writeback a `stage:later` to write onto
  the issue. On an already-arriving stream that is not one misfire, so the filter is the vocabulary
  and not the namespace.
- **`unlabeled` is deliberately NOT consumed.** Removing a `stage:*` label states no lane; re-deriving
  on it would land the card in the `later` default, inventing a sequencing decision nobody made.
- **Three gates, and they are what keep this from becoming a third mover:** the answer must be
  lane-derived (an anchored `[TASK]` title on a mapping with a lane model); the card must currently
  **be in one of the mapped lanes** — a card advanced to a working column, or parked in
  `coord_card_terminal_stage_id` by a close, is never yanked back into a lane by a label edit; and
  the card's current stage must be **service-set** (`last_stage_move.actor_type === "service"`, the
  same allow-list the revive arm carries). **A human who drags a card to a lane has expressed a
  placement, and a label must never override it** — that would be this key's own defect pointing the
  other way.
- **Idempotent** under at-least-once redelivery: a card already in the declared lane is skipped.
- **`bridge:check`.** Warns when an agent enables `coord-card-relane` on a scope whose mapping is
  missing `move_coord_cards` or `coord_card_lane_stage_ids`, naming the missing key(s) — that
  install classifies nothing and moves nothing, and no other check leg reports it (a mapping with
  no lane model is perfectly valid for every other leg). **The reverse direction is REPORTED, and
  deliberately NOT as a warning** (card#8290): a lane model with no agent enabling this family
  draws a green setup-time line naming the race above. The install is CORRECT — the key is read by
  every coord-card write and accepted with either family, so it declares no intent this one family
  contradicts, and leaving the family off stays a valid choice; what an operator cannot learn from
  their own `writeback.json` is that the family exists at all. The line names `move_coord_cards`
  too where the mapping does not set it (the family needs it, and a create-only lane model is valid
  since DL-294), stays silent on a fully adopted install, and degrades to a cannot-verify
  disclosure when an agent config this run could not read might be the one enabling the family.

## Optional: card non-prefixed issues too (`issue_population`, #4553)

By default `create_coord_cards`/`move_coord_cards` track only issues with a recognized
`[BRIEF]`/`[ANNOUNCE]`/`[QUERY]`/`[REVIEW]`/`[TASK]` title (the `id:<sid>` tag key). Set
**`issue_population: "all"`** on the mapping to ALSO track **non-prefixed** issues (`[PROPOSAL]`,
`[FR]`, plain titles) — each correlated by the **`github_issue` by-ref** key instead of a tag.

```jsonc
"mappings": {
  "org/coord": {
    "board_id": 8,
    "create_coord_cards": true,
    "coord_card_stage_id": 21,
    "issue_population": "all"       // default "prefixed" ⇒ byte-identical DL-198/200
  }
}
```

- **Per-issue key, never per-mapping.** A **prefixed** issue always uses the `id:<sid>` tag
  (unchanged — the same key the reconcile uses, so they never double-card it). A **non-prefixed**
  issue uses the `github_issue` by-ref key. Under `all` a prefixed card is **dual-keyed** (tag +
  `issue_number` in payload), and create/move **pre-check both keys** — so a card is found whether
  its title carried a prefix at create or at a later reopen.
- **The ref is the payload `issue_number` field.** A by-ref card stamps `issue_number` in its
  payload (that is what the kanban by-ref index derives from — NOT `external_link`, which only
  derives the `source` repo). So the board **MUST register the `issue_number` custom field** (add
  `issue_url` too, for `source`). `bridge:check` **FAILS** (exit non-zero) under `all` if it is
  absent — without it every by-ref create 422s permanently AND an empty by-ref lookup can't be told
  from "not indexed", so the bridge would silently double-card.
- **Backstop is a consumer commitment.** The prefix/tag-keyed reconcile does **not** card
  non-prefixed issues, so under `all` the bridge is the **sole real-time mover** for them — a
  bridge-missed non-prefixed event self-heals nowhere **unless** the consumer extends its reconcile
  to correlate by `github_issue` by-ref (framework #299). `bridge:check` **warns** on this, and
  performs a **three-state cross-config compare** (agree / DISAGREE / CANNOT-VERIFY) binding this
  bridge's `issue_population` to the reconcile's (`$COORD_CONFIG` `kanban.boards[].issue_population`):
  `bridge=all` + `reconcile=prefixed` surfaces as a **DISAGREE** rather than a silent gap.
  (Prefixed issues stay backstopped regardless, via the shared `id:` tag.)
- **Default is byte-identical.** Absent (or `"prefixed"`) ⇒ exactly DL-198/200 behavior.

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
  off. Absent, the leg would half-work: closes land, reopens silently no-op. **With
  `coord_card_lane_stage_ids` configured, a reopened `[TASK]` revives to its declared LANE instead**
  (card#6393) — the same derivation the create leg runs, for the same reason; `coord_card_stage_id`
  stays the revive target for every issue the lane model does not govern, and for every install that
  configures no lane model.
- **A lane model is configurable on a move-on / create-off mapping** (DL-294) — the shape where the
  consumer's reconcile creates the cards and the bridge only moves them, correlating on the same
  `id:<sid>` tag. `coord_card_lane_stage_ids` needs **either** family, not the create leg
  specifically, so such an install gets a lane-aware revive (and relane) **without** having to turn
  on `create_coord_cards`, which would change which mover creates its cards and race the reconcile.
  Set the lane ids beside `move_coord_cards`; the create-leg keys stay off.
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
- **A close is unconditional over a user LANE — but not over a PIN (DL-340, card#8523).** A human's
  priority *placement* yields to closure (close→terminal is the terminal case). That ruling is about a
  lane, and it was carried here as though it settled the pin too: it does not. A card carrying a
  non-empty `block_reason` or a `no-automove` tag is **refused** — the same DL-178 hold every other
  card write the bridge makes now honours — loudly (`Log::warning` + a `pinned_no_automove` signal
  naming the `card_id` and the issue). ⛔ **Read the previous behaviour, because an install with this
  leg on has been living with it:** the close leg had *no* gate at all — the actor-gate below guards
  revive and relane only — so until DL-340 an `issues.closed` concluded a parked card with nothing
  between it and the write but this opt-in. **Lifting the pin does not replay the close** (GitHub
  delivers it once); the consumer's periodic reconcile is the backstop, or move the card by hand.
  There is no override: the DL-194 unpark and DL-195 revive are PR outcomes with no counterpart here.
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

  It **never fails** `bridge:check` — the bridge must not become unrunnable because a coord file
  moved. **Since DL-251 the CANNOT-VERIFY arms report `unvalidated` rather than `warn`** (they render
  plain and join the run's closing tally): the comparison could not be made, which is not evidence
  that the terminal is wrong. The DISAGREE arm — where both configs were read and they differ — stays
  a `warn`, because that one is measured.

  The read is **CLI-only by design**: `bridge:check` runs with the operator's
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

**Uncorroborated title-only `card#` (card#5953).** A **SET** obeys the same corroboration gate as a move (see *Which SURFACE the `card#` came from* under **Precedence**): when the card# was found in the PR **title** with nothing agreeing in the head branch, the marker is written only if the card **tracks no PR yet or already tracks this one**; otherwise the overlay is **refused** (`card_token_uncorroborated` — logged, and signalled if you have an `alert_channel`). Without it, a draft PR whose title descriptively cites another card on the same mapped board pinned that card (DL-178) on the strength of its prose alone. **A CLEAR is deliberately never gated**: clear-if-ours can only ever null a `block_reason` that is *exactly our own marker*, so a human's differing text is untouchable — the constant-sentinel **Sentinel ambiguity** note (under DL-194's accepted residuals below) is the bound on that claim: a human who typed the *exact* marker as their own reason is indistinguishable from our own SET, and a foreign PR's `ready_for_review` can null it. Gating it would strand any marker on a card that now tracks a different PR — including those set before this shipped — permanently pinning the card the guard exists to protect. Accepted residual: a foreign PR's `ready_for_review` can clear the marker (releasing the DL-178 pin) that another PR's still-open draft set, and nothing re-sets it until a fresh draft event.

**⚠ A REFUSED CLEAR STRANDS THE MARKER (card#8415).** The board-scoped tenant check runs *before* the card is read and its refusal is a **permanent no-op** — the receiver answers 200 and kanban never redelivers — so on a `ready_for_review` the `"PR is in draft"` marker is simply left on the card. Three of the four refusal reasons (`mapped_board_unreadable_to_this_token`, `board_scope_lookup_unfiltered`, and the `boardscope_*` 4xx arm) can fire on a card that **is** on the mapped board — a writeback token that lost its board membership is the realistic one — and a non-empty `block_reason` **pins** the card (`PinGuard::isPinned()`, DL-178), with nothing re-setting or re-clearing it until a fresh draft event. Failing closed is deliberate (the alternative is writing to a card whose board membership could not be established), so the remedy is operational: **after fixing whatever the refusal reason named, sweep the mapped board for `"PR is in draft"` block_reasons on cards whose PR is no longer a draft and clear them by hand** — each refusal's `Log::warning` carries the card id (it is withheld only from the alert channel, DL-314).

To use it: set `draft_overlay: true` on the mapping and subscribe the repo webhook to **Pull requests** (which already carries the `converted_to_draft` / `ready_for_review` / `opened` actions — no extra event class needed). Same durable, transient(5xx→retry)/permanent(4xx→log+no-op) split and belongs-to-mapped-board guard as the move handler.

## Optional: auto-unpark a parked card on branch-cut (DL-194)

By default **every** outcome **refuses a pinned card** (a non-empty `block_reason` or a `no-automove` tag) — "parked means parked" (DL-178; `started`-only until card#8289 corrected it). But cutting a fresh branch **for a specific card** is an unambiguous human *"work has begun"* signal, and column position is not the enforcement boundary (deploy/merge + a persistent tag is). Set **`unpark_from_stages`** on a mapping and a `started` event **promotes the card even if it is pinned**, *scoped to those stage ids only* — and emits a compensating operator **alert** whenever it overrode a *deliberate* hold, so a genuinely-held card is never unparked silently.

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

## Optional: promote Shipped cards to Released on a release merge (`promote_on_release`, DL-207)

On a board that splits **Shipped-to-dev** (`stages.merged`) from **Released-to-main** (`stages.merged_to_main`), a feature PR merges to `dev` and its card moves to **Shipped** — but its commits reach `main` only later, folded into a **release PR** (`release/vX → main`). That release merge fires exactly ONE `merged_to_main` event (for the release PR's own card), so the constituent feature cards **stay at Shipped** until a manual `bridge:reconcile` — and reconcile deliberately **defers** Shipped→Released to "the promote workflow". Set **`promote_on_release: true`** and the bridge becomes that workflow: on the release merge it scans the board and promotes every Shipped card whose work is now on `main`.

```jsonc
{
  "mappings": {
    "owner/repo": {
      "board_id": 8,
      "stages": { "opened": 50, "merged": 52, "merged_to_main": 53, "closed_unmerged": 49 },
      "promote_on_release": true                // release merge → promote Shipped cards now on main to Released (DL-207)
    }
  }
}
```

- **Opt-in, byte-identical when off; reuses the existing stages.** No new stage keys — the scan reads `stages.merged` (Shipped) and moves to `stages.merged_to_main` (Released). Both are **required** when `promote_on_release` is on; the config **fails closed at load** if either is missing.
- **Which cards.** Only cards **currently at `stages.merged`** that are **tooling-managed** (carry a PR reference — `pr_url`, or `pr_number` on a 1:1 board) and **not pinned** (`block_reason` / `no-automove`, mirroring the reconcile). DL-only cards (no PR reference) are out of scope — the same PR-driven boundary `bridge:reconcile` draws. On a board shared by several repos, a bare `pr_number` is ambiguous and skipped (needs a repo-qualified `pr_url`), again matching reconcile.
- **How "on main" is decided — positive reachability.** For each candidate the bridge reads the PR and, when it is **merged**, asks GitHub whether the PR's `merge_commit_sha` is reachable from `main` (`compare(sha...main).status ∈ {ahead, identical}`). Reachable ⇒ released ⇒ promote; otherwise the card stays Shipped (a card merged to dev *after* the release cut correctly waits for the next release). This is a positive "is it on main" test — an open PR (whose test-merge sha is on no branch) and a PR merged to some other base are never promoted.
- **Merge-strategy precondition.** Correctness needs the release→`main` merge to **preserve dev's commit shas** — a **merge-commit or fast-forward** (the reference workflow's method; dev PRs squash-merge, so a squash-sha on dev becomes reachable from main via the release merge-commit). A **squash/rebase RELEASE merge** rewrites shas, so no feature sha ever joins main and the leg promotes nothing. `bridge:check` cannot see the merge strategy, so this is a documented precondition, not a guard.
- **Needs a runtime GitHub read token — a placed file.** This is the one writeback leg that reads GitHub **from the receiver** (not just the `bridge:reconcile` CLI). Under PHP-FPM `GH_TOKEN` is absent and the credential-store helper is CLI-only, so you must place a read-only **`<secret_dir>/github/token`** (or set `providers.github.token_path`), `chmod 600` — the same least-privilege PR-read token `bridge:reconcile` uses. Without a file-resolvable token the leg is **inert** (durable alert + log, no move); `bridge:check` **warns**.
- **`bridge:check` guards.** With `promote_on_release` on, the check **warns** if (a) no GitHub token resolves **from a file** (store/`GH_TOKEN`-only tokens don't hold under FPM), or (b) `stages.merged` and `stages.merged_to_main` are the **same** stage (the promote is a no-op).
- **Idempotent + self-healing.** A promoted card leaves the Shipped filter, so a redelivered event (or a mid-scan transient failure) re-scans and moves nothing already done. A card stranded by an earlier failed/disabled release is promoted on the **next** release event (its sha is on main via the earlier merge-commit). A synchronous per-event candidate **cap** bounds the webhook cost; overflow is alerted and drains across successive releases (steady-state N is a handful — a large first-adoption backlog is the documented worst case).
- **Not back-stopped by `bridge:reconcile` (deliberate).** Reconcile treats `merged_to_main` as terminal and never promotes into the released stage — this leg *is* the only promoter, so its inert/failure paths are made **loud** (durable alert + `bridge:check`) rather than silently relying on a backstop.
- **Fires on any merge to `main`.** A hotfix or dependabot PR merged directly to `main` also triggers a scan; it is idempotent and only promotes cards genuinely on main, so this is safe (anything landing work on main releases it).
- **NOT closure-gated (DL-305), deliberately.** The scan asks whether a card **already at `stages.merged`** has its commit on `main`; it does not re-decide the transition that put it there. Since DL-305 (widened DL-308) that transition needs closure evidence, so the sweep's INPUT is gated at the source — which is where the completion claim is actually made. ⚠ The one thing this does not do is re-examine cards that reached Shipped **before** you upgraded: they stay eligible, exactly as they are today. If your board holds a large Shipped backlog accumulated under the old behaviour, audit it before enabling `promote_on_release` — the gate protects what arrives from now on, not what is already there.

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

`socket` and `url` are **mutually exclusive** (exactly one), mirroring an agent's `channel` config. The signal body is one line: `{"type": "writeback_move_failed", "repo": <repo>, "outcome": <outcome>, "card_id": <id|null>, "issue_number": <n|null>, "reason": <reason>}` — plus **`"card_id_withheld": true`** on the arms that HAD an id and deliberately did not send it (see *`card_id_withheld`* below; the key is ABSENT everywhere else, never `false`). (The same `alert_channel` also carries the DL-194 **`writeback_auto_unparked`** and the DL-195 **`writeback_revived_on_reopen`** signals — each a distinct `type`, no dedup — see *auto-unpark a parked card on branch-cut* and *revive a Won't-Do card* above.)

**`issue_number` (DL-285).** The GitHub **issue or pull-request** number a refusal is keyed by — one field, because GitHub numbers issues and PRs in a single space. It is populated by the three handlers whose refusals happen while *creating* or *finding* a card (`kanban_coord_card`, `kanban_coord_card_move`, `kanban_dependabot_card`); it is `null` on every card-keyed arm. `card_id` is `null` alongside it on every issue-keyed arm **except** `kanban_coord_card_move`'s two per-card arms, which run inside a loop over already-correlated cards and so carry both. **It is context, not a dedup key** — the dedup tuple stays `(repo, outcome, reason)`, so a repeated refusal across many issues alerts once and the body carries the FIRST one's number; the per-event `Log::warning` is where you enumerate the rest. Putting the number in `reason` instead was rejected for exactly that: it would grow the marker set by one entry per issue, forever.

**`card_id_withheld` (DL-314, card#7846).** `card_id` reaches the card-keyed handlers as a literal integer parsed out of **author-controlled text** — a `card#NNNN` token in a PR title or branch ref (`CardTokenGrammar`) — and **kanban's card id space is GLOBAL across every board on the instance**: nothing about the id says which install it belongs to. The arms that refuse because the card **READ FAILED** therefore hold an id this bridge never established as its own, and pushing it put a **foreign install's card id into this install's alert channel** (observed live on a shared instance: a repo event alerted `card_id: 7756`, a card on another install's board — only the write-side refusal stopped a move). Those arms — `kanban_move_card`'s and `kanban_block_reason`'s **`getCard` 4xx** rows, and since card#8375 `kanban_move_card`'s four **board-scope** refusals, which are the case this rule was written for — send `card_id: null` with `card_id_withheld: true`. ⛔ **The id is NOT lost, and the flag is not decoration:** the arm's `Log::warning` context carries it verbatim, because the local operator legitimately needs it and the log is *their* surface; the flag distinguishes *withheld* from the plain `card_id: null` the malformed-payload and issue-keyed arms mean by it, which is *the arm had no id at all* — two different next steps. **Everything else is unchanged:** same event `type`, same dedup tuple (`card_id_withheld` is a property of the arm, constant per `reason`, so it is not in the signature), same swallow-and-ack behaviour. ⚑ **Bound, stated rather than implied:** the withholding is keyed on *the read failed*, not on the status — a 404 withholds too, though a 404 discloses nothing (the card does not exist). The arms that alert with an unverified id **without ever probing kanban** (`repo_or_outcome_invalid`, `repo_or_action_invalid` — malformed-payload refusals, which send no request and get no existence answer) still carry it; that is a smaller shape, recorded in DL-314 rather than changed here.

**Which failures signal.** **The tables below are the authority — there is no shorter rule that is true.** Since DL-285 the coverage is near-total: every permanent-refusal branch in all **six** writeback handlers signals — as does the one CLI refusal, `bridge:reconcile`'s board re-check (DL-301) — with the **two** accounted-for exceptions named in *Still log-only* below (plus the inherent `writeback_not_configured` degradation, which routes through the same primitive but has no channel to reach). A test — `tests/Feature/Writeback/WritebackRefusalSignalCoverageTest.php` — re-derives that population from the handler sources on every run and reds on a `Log::warning`/`Log::error` that is neither routed nor listed, so a new arm in those files cannot re-mint the omission. **Stated bound on that guard:** its population is the `app/Bridge/Handlers/Kanban*Handler.php` glob, so a future writeback handler named outside that pattern is not covered until the glob is widened. (Its *second*, level-independent leg is wider since DL-301 — it also scans `app/Console/Commands/Bridge/*.php`, because the reconcile arm is the first caller of the board guard outside the handler files. The two legs no longer share a population.) The `Log::info` "not tracked" branches stay **quiet** — they're the normal case for an event the operator simply hasn't mapped, not a failure. **Second stated bound, and the sharper one:** that guard's population is the `Log::warning`/`Log::error` call sites, so a refusal *written at `Log::info`* is invisible to it. That is how `kanban_coord_card_move`'s belongs-to-mapped-board refusal stayed silent through DL-285's sweep and after it, until **card#7133**, while this section read as total coverage — it was found by reading the three copies of that guard side by side, not by the guard. A green run therefore says "no bare warning/error is unaccounted for", not "no refusal is silent".

**That bound still holds — and one refusal KIND is now closed underneath it (card#7138, DL-292).** The fix for the blind spot above is not to widen the population by level: `Log::info` is also every "not tracked", success and policy line in these handlers, and sweeping them in would drown the signal it exists to carry. It is to make the refusal that was missed *unwritable* anywhere else. The belongs-to-mapped-board rule and its refusal report now live in **one primitive** (`MappedBoardGuard::refuses()`) that all three arms call, and the same test carries a **second, level-independent leg**: it re-derives, every run, every non-comment line in the handler glob naming the card field `board_id` or the reason `card_not_on_mapped_board`, and requires that set to be **empty**. A membership decision cannot be written without reading the card's board, so a fourth copy reds — at `Log::info`, at `Log::warning`, or with no log line at all. **It closes exactly that one kind**; every other refusal kind is still covered only by the level-keyed leg and its bound above.

**Three more arms joined that guard at DL-298 (card#7211), and their refused set is expected to be EMPTY.** The belongs-to-mapped-board rule used to run only on the **token** path — the sites that parse `card#NNNN` / `DL-NNN` out of a PR title or branch, read the card and refuse it if it is not on the mapped board. Three writeback paths resolve their cards from a **board-scoped search** instead (`kanban_dependabot_card`'s PR correlation, `kanban_promote_released`'s board scan, `kanban_coord_card`'s post-create collapse), parse no token, and so had nothing checking that the rows kanban handed back were on the board the query asked for. They now call the same `MappedBoardGuard::refuses()`, so a row naming another board is dropped from the write set and reported with the same `card_not_on_mapped_board` reason. **On a correctly-scoped instance nothing is ever refused, and that is the point:** `q=board_id=<id>` is enforced server-side (measured with a control, rt#327), but the same endpoint silently drops an unrecognised **top-level** parameter and answers 200 with an unfiltered result set — and `board_id` happens to be recognised top-level too, so hoisting it out of `q` filters in a manual test and reviews as equivalent. The guard turns the scope from a property of how the call is written into a property of the data that came back. **A row with an absent or unreadable `board_id` is refused like a foreign one** (fail-closed).

**⭐ The fourth site of that class closed at DL-301 (card#7211): `bridge:reconcile --fix`.** The reconciler reads a mapped board with the same `q=board_id=<b>` search and applies forward moves from its plan; until DL-301 nothing re-checked the rows, so the CLI was the one arm of this class where a dropped filter would still have moved another tenant's card. It now calls the same `MappedBoardGuard::refuses()` — under the synthetic outcome **`reconcile`** and the arm name `bridge_reconcile` — at the moment a row becomes a card the run will decide a move for, which is BEFORE the per-card GitHub read: a refused row is never written to, never dereferences its PR reference with this repo's GitHub token, and never lands in the in-sync / backward counts that would otherwise report a reconcile over another board. **The refusal is loud:** an error line naming the card, plus the alert, plus a non-zero exit — a cron reading exit 0 over a cross-board row in its board read would be reporting a clean reconcile over an unfiltered result set. **And the applied move is now recorded durably**, `Log::info('bridge_reconcile: moved', …)` carrying the same `card_board` + `mapped_board` pair (card#7212): the console `MOVED` line goes wherever the operator's cron redirects stdout, while the refusal was always in the log — recording only the refusal answers *"did we ever stop it?"* and never *"did this ever happen?"*.

**⭐ AND SINCE card#8375 (both token arms since card#8415) THE TOKEN PATH ASKS THE QUESTION BEFORE IT READS THE CARD.** Everything above happens *after* a `GET /tasks/{id}.json` that names no board. On the token path that id is a literal parsed out of **author-controlled text** (`card#NNNN` in a PR title or head ref) against a kanban id space that is **global across every board on the instance**, so the belongs-to-mapped-board compare's precondition was *a successful cross-tenant read* — and on a 403 the compare was not reached at all (DL-314). Measured live: a repo event on one install resolved to a card another install owns, and the only thing that stopped it was the API answering 403. That is a property of the **token**, not of the code. the token path now resolves the id through a **board-scoped lookup first** — `GET /tasks/search.json?q=board_id=<mapped> id=<card>`, both terms inside `q=` — and refuses an id that does not come back, **without ever issuing the unscoped read**. ⛔ **The verdict is read off the ROWS, not off the call:** a row counts only if its own `id` names the card AND its own `board_id` is the mapped board (the DL-298 rule, applied to the same rows), because this endpoint drops a filter it does not recognise and still answers 200. **Both sides of the archive switch are asked** (DL-296 gives no both-sides mode), so an archived card on the mapped board still moves exactly as before — the archived probe runs only when the live side misses, and the happy path costs **one** extra request. **Three verdicts, because the operator's next step differs and a 403 could never choose (DL-314 recorded that as undecidable and deferred):** the id is not on the mapped board *and the board itself reads back* (`card_id_outside_mapped_board` — the foreign id); the id is not on it *and the board reads back empty* (`mapped_board_unreadable_to_this_token` — audit the writeback token's board membership first; an unreadable board and a genuinely empty one are one answer here); or the lookup answered somebody else's row (`board_scope_lookup_unfiltered` — a broken read, never a tenant verdict). Every one of the three **withholds the card id from the alert channel** (DL-314) and keeps it in the `Log::warning`. ⭐ **BOTH token-resolved arms make the check since card#8415** — the draft overlay (`kanban_block_reason`) resolves its id from the same grammar against the same global id space, so it asks the same question before its own `GET /tasks/{id}.json` and its `getCard` 403 is narrowed the same way. The overlay writes one field and never moves a card, which made it a smaller blast radius, not a different class. What each arm still owns separately is its **synthetic outcome** — the dedup tuple is `(repo, outcome, reason)`, so the two arms' identical refusals on one repo do not silence each other.

**Stated bound shared by the three DL-298 rows and the DL-301 one, and it is the promote-arm bound again.** All four fire inside a loop over correlated cards (a whole board read, for the reconciler), and dedup stays `(repo, outcome, reason)` — so N foreign rows in one delivery, or one reconcile run, produce **one** push, carrying the first card's id, and the same marker suppresses that reason on later deliveries until it is cleared by hand. Since a fleet-wide unfiltered read is exactly the scenario that would produce N of them at once, read the push as *"this started happening"* and the per-card `Log::warning` (which fires unconditionally, per row, per delivery, and names each `card_board`) as the enumeration.

**⭐ THE SUCCESS SIDE, and why it belongs in a section about failures (card#7212, rt#327 R4).** Everything above answers *"did we ever stop it?"*. Until card#7212 nothing answered *"did this ever happen?"* — because the evidence was emitted on the **refusal path only**. A successful writeback logged the board it INTENDED to write to (`$mapping->boardId`, straight from `writeback.json`), while only a refusal logged the board the card was actually on, so **a write that LANDED on an out-of-mapping card was byte-identical in the log to a correct one**. With a 14-day retention and no audit table recording a card or board id, that made the question not merely unanswered but **unanswerable** — and it is the reason the Group-B write sites' blast radius (card#7211) cannot be measured retrospectively. **An absence of record is not a record of absence.**

Every writeback **write to a card whose id was RESOLVED from kanban** — in a handler, or in a shared writeback write primitive — now records both boards, from the guard's own rendering (`MappedBoardGuard::boardContext()`, the same pair the refusal arm emits): **`card_board`** — the card's own `board_id`, verbatim as kanban returned it — and **`mapped_board`**, the operator's configured board for the repo. Equal is the happy path; a divergence is the record doing its job. **Twelve sites: eleven in the handlers and the shared primitives, plus the `bridge:reconcile --fix` move (DL-301) — which the DL-301 review put INSIDE that census's file population rather than leaving it as a residue beside it.** It **replaces** the old single `board` key on exactly two of them — `kanban_move_card: moved` and `kanban_block_reason: <action>` — and is purely **additive** on the other ten, which recorded no board at all: `kanban_move_card: stamped correlation refs`, `kanban_move_card: recorded a correlation note on the card`, the three `kanban_coord_card_move` legs (terminal / revived / re-laned), `kanban_dependabot_card: archived (closed-unmerged)`, `kanban_dependabot_card: moved`, `kanban_promote_released: promoted Shipped→Released`, the shared `CardCollapse` duplicate-archive both create-capable handlers delegate to, and `bridge_reconcile: moved` — which recorded nothing durable at all before DL-301, not merely no board.

**⛔ The rule for a record that is not a success, stated once so the arms cannot drift apart.** The pair records where a write LANDED, so it goes on every record reporting the outcome of a write kanban **ACCEPTED** (2xx) — including the two `archive returned 200 but the card is not archived` `Log::error`s (the dependabot arm and the `CardCollapse` one). An accepted archive whose effect did not take still REACHED that card, and a 200-not-archived on a foreign card is precisely the cross-board touch this record exists to expose. A **4xx** arm sits on the other side of that line: kanban rejected the request and nothing landed on any board, so there is no landed board to name. Every write-refusal arm is therefore unchanged and names no `card_board` — `kanban_move_card: kanban refused the move (4xx)` keeps its pre-existing config-sourced `board` key, and the `blockreason` / `stamp` / `cardnote` / `promote_movecard` arms keep logging no board at all. (The two create-capable handlers' flat 4xx catches span correlation READS as well as the write, so the pair would be wrong-but-specific there under either rule.)

Three bounds, all deliberate. (1) **`card_board` is the card's RAW value, not normalised through the guard's predicate** — the accepted set is an interval (below), so `'8'` and `8.9` both belong to a mapping of 8 and which spelling kanban returned is exactly what a reader wants; and the compare the Group-B arms gained at DL-298 accepts that whole interval, so normalising here would render a value no gate on either path ever computed. (2) **A CREATE carries `board` only** — `kanban_coord_card: created`, `kanban_dependabot_card: created` — because there is no resolved card to read a board from: the board is the POST target and the create response returns an id. That a created card's ACTUAL placement is never read back is a sibling shape, filed as **card#7225** rather than fixed here. (3) **`CardCollapse::toSurvivor()` takes its mapping as `?WritebackMapping`** — the board-tools caller (`BoardCreateCardTool`, `docs/board-tools.md`) is outside the DL-009 mapped-board regime entirely, its board being FORCED from `BoardToolsConfig` rather than from a per-repo mapping, so its archives record no pair at all. Every caller INSIDE the regime hands the collapse real rows — DL-298 converted the coord-card tag arm to the row-returning `cardRowsByTag` twin and its by-ref arm to a per-card read, for the re-check — so `card_board` is the row's own value there. Where a caller ever hands in ids with no rows behind them the primitive records `card_board: null`: the absence measured, never a fallback to the mapped board, which would manufacture the very agreement the pair exists to test.

**⭐ AND THE PAIR NOW OUTLIVES THE LOG, on the divergent case only (card#7212 second half, DL-300).** Everything above is a LOG record, and the log is retention-bounded — 14 days, pruned by the receiver's own gate since DL-199 — so on its own it answers *"did this ever happen?"* for a fortnight and then stops. **A record that expires is an absence on a timer.** So `MappedBoardGuard::boardContext()`, the one place that holds both the card and the mapping, also writes a row to **`writeback_board_divergences`** when — and only when — `MappedBoardGuard::belongs()` is false for the card it is rendering. The row carries the **`disposition`** (`refused` — the guard stopped the write, nothing was written to that card; `recorded` — the pair was rendered for a record of a write the bridge MADE), the **card id**, the card's own board **verbatim**, the **mapped board**, the **call site** (read from the stack, so the N+1th write site cannot omit it the way an `$arm` argument would), and the **time**. It is the same observation as the log line, minted by the same predicate — not a second reading of the card.

**⛔ Nothing is persisted on the happy path, and `bridge:prune` does not touch this table.** A row per successful writeback was refused as a design: it would put the bridge's whole write rate into the DB to record the one thing every other row's absence already implies. The healthy state of the table is therefore EMPTY — **growth is the signal** — and a retention window on the record that exists to outlive a retention window would be the same defect with a longer fuse. `bridge:stats` prints the total and the two dispositions **on every run, zero included**, because a line that appeared only when non-empty would make *"nothing was ever recorded"* indistinguishable from *"nothing measured it"*. **Read the dispositions apart:** `refused` says the guard did its job (DL-298's arms refuse nothing on a correctly-scoped instance, so even that is expected to be zero); a **`recorded`** row is the one that answers this section's question with a YES, and on today's code it is unreachable — every arm inside the mapped-board regime is gated — which is precisely why it is worth a durable row rather than an assertion that it cannot happen. **The accepted INTERVAL is not a divergence:** kanban answering `'8'` for a mapping of 8 names the same board (DL-292), so it mints no row; a ledger keyed on raw inequality instead of the predicate would mint a permanent, unprunable record of nothing. `tests/Feature/Writeback/WritebackBoardDivergenceLedgerTest.php` owns both directions, each happy-path leg paired with a witness that the write really happened and the pair really rendered. **A REPEATED observation counts on the row it already has** rather than appending one: `observation_key` (a hash of the whole stored observation) is unique, and a repeat bumps `observations` and `last_seen_at` while `created_at` keeps naming the FIRST sighting. That is what makes "never pruned" affordable now that `bridge:reconcile` — an hourly cron, and the seventh arm of the guard since DL-301 — re-observes the same divergence for as long as it lasts; the table is bounded by the number of distinct divergences an install has had, not by its uptime, and the `bridge:stats` counts are of rows, i.e. of distinct divergences. On an install that upgraded without running `php artisan migrate` those lines read `NOT MEASURED — table missing` rather than `0`: an absent table is a third state, and printing a zero for it would assert something nobody checked.

`tests/Feature/Writeback/WritebackSuccessBoardRecordTest.php` re-derives the write population every run on **all three** of its axes — the file glob (`app/Bridge/Handlers/Kanban*Handler.php`, `app/Bridge/Writeback/*.php` **and**, since DL-301, `app/Console/Commands/Bridge/*.php`), the write VERBS (every `KanbanClient` method that REACHES a mutating verb — one whose body issues `->patch(` / `->post(` / `->delete(` itself, **or** one that calls another method of the class that does, followed to a fixed point; `writeMethodsOf()`'s docblock owns the rule and why the transitive leg is load-bearing — the narrow verbs delegate to one shared `patchCard` primitive and issue no verb of their own), and the RECEIVERS (`->verb(` on any receiver except the handler's own `$this->`) — and reds on a kanban write in that population that is not accounted for, on any caller that renders the pair by hand instead of calling the primitive, and on a `CardCollapse::toSurvivor()` call in that population that does not pass its mapping.

Every signalling arm emits its durable `Log::warning` **first** and then the additive push, through a paired primitive — `WritebackAlertNotifier::warnAndNotify`, or its withheld-id twin `warnAndNotifyCardIdWithheld` (DL-314), which differs only in that the push carries no `card_id`. An arm cannot log a refusal without alerting on it. Before DL-274 the notifier was opt-in *per call site* and 11 of the 12 permanent-refusal arms had simply never opted in.

**`kanban_move_card`** (`outcome` = the PR outcome that drove the event):

| Branch | `reason` | Signals? |
|---|---|---|
| `payload.card_id` not an integer | `card_id_not_int` | ✅ |
| `payload.repo`/`outcome` not non-empty strings | `repo_or_outcome_invalid` | ✅ |
| writeback not configured (no `writeback.json`) | `writeback_not_configured` | ⚠ degrades to log-only (see below) |
| no mapping for repo (`Log::info`) | — | ❌ (expected "not tracked") |
| no stage mapped for outcome (`Log::info`) | — | ❌ (expected "not tracked") |
| **the card id does not resolve ON the mapped board** (security refusal, card#8375 — the board-scoped check, made BEFORE the card is read; the mapped board itself reads back, so the id is not this install's) | `card_id_outside_mapped_board` | ✅ (`card_id` **withheld**) |
| **the card id does not resolve on the mapped board AND the mapped board reads back EMPTY** (card#8375 — check the token's board membership; an unreadable board and an empty one are one answer here) | `mapped_board_unreadable_to_this_token` | ✅ (`card_id` **withheld**) |
| **the board-scoped lookup answered a row that is NOT this card** (card#8375 — a broken/unfiltered read, never a tenant verdict) | `board_scope_lookup_unfiltered` | ✅ (`card_id` **withheld**) |
| **the board-scoped lookup was itself refused by kanban (4xx)** (card#8375 — the query named our own board, so the foreign-id cause is excluded) | `boardscope_403_token_scope` · `boardscope_404_no_such_card` · `boardscope_4xx` | ✅ (`card_id` **withheld**) |
| `getCard` refused by kanban — **404** | `getcard_404_no_such_card` | ✅ (`card_id` **withheld**) |
| `getCard` refused by kanban — **403** (⚠ **NARROWED at card#8375** — the scoped check above has already found this id on the mapped board, so a foreign card id is EXCLUDED and the slug names the one cause left. The `kanban_block_reason` overlay narrows the same way since card#8415, so `getcard_403_foreign_card_id_or_token_scope` is now emitted by no shipped arm) | `getcard_403_token_scope` | ✅ (`card_id` **withheld**) |
| `getCard` refused by kanban — any other 4xx | `getcard_4xx` | ✅ (`card_id` **withheld**) |
| card not on the mapped board (security refusal, DL-009 — one shared `MappedBoardGuard` predicate + report across all **seven** arms — three at **DL-292**, three more at DL-298, the CLI one at DL-301) | `card_not_on_mapped_board` | ✅ |
| uncorroborated title-only `card#` naming a card that tracks a **different** PR (security refusal, DL-270) | `card_token_uncorroborated` | ✅ + a card note (card#7064) |
| move refused on **any** outcome — the card is pinned (`block_reason` / `no-automove`, DL-178; all-outcome since card#8289, minus the DL-194 unpark and DL-195 revive overrides). The message names the outcome; the dedup tuple is `(repo, outcome, reason)`, so each outcome signals once. The correlation-ref stamp still runs | `pinned_no_automove` | ✅ |
| **`moveCard` PATCH refused — 403** (DL-274) | `movecard_403_not_writable_by_this_token` | ✅ |
| **`moveCard` PATCH refused — 404** (DL-274) | `movecard_404_no_such_card` | ✅ |
| **`moveCard` PATCH refused — any other 4xx** (DL-274) | `movecard_4xx` | ✅ |
| **correlation-ref stamp refused — 403 / 404 / other 4xx** (DL-274) | `stamp_403_not_writable_by_this_token` · `stamp_404_no_such_card` · `stamp_4xx` | ✅ |
| **a correlation ref was offered whose value DIFFERS from the one the card stores — a SECOND PR's leg, dropped** (card#7064; an idempotent replay of the card's own PR offers the same value and is NOT here) | `correlation_ref_not_stamped` | ✅ + a card note |
| **card note refused by kanban — 403 / 404 / other 4xx** (card#7064 — the token lacks comment-create, which is NARROWER than the card writes it already makes) | `cardnote_403_not_writable_by_this_token` · `cardnote_404_no_such_card` · `cardnote_4xx` | ✅ |
| **card note undeliverable — 5xx, timeout, or any other throw** (card#7064; swallowed — not retried within the delivery, since an observability write must not 5xx a move that already happened; the next event asserting the same drop re-attempts it) | `cardnote_send_failed` | ✅ |
| `started`/`reopened` move skipped by a no-regression guard (`Log::info`) | — | ❌ (working as designed) |

**`kanban_promote_released`** (`outcome` is always the synthetic `promote_on_release`; this leg has **no reconcile backstop**, so its failure paths are the ones that most need to be loud):

| Branch | `reason` | Signals? |
|---|---|---|
| `payload.repo` missing / not a string (DL-285 — the tuple degrades to an empty repo) | `promote_repo_invalid` | ✅ |
| writeback not configured (no `writeback.json`) (DL-285) | `writeback_not_configured` | ⚠ degrades to log-only (see below) |
| mapping is missing its Shipped/Released stage | — | ❌ **log-only** — unreachable type-narrowing (see *Still log-only*) |
| no GitHub read token resolves for the repo (the leg is inert) | `promote_no_github_token` | ✅ |
| board read hit the page ceiling — cards beyond it never self-heal | `promote_board_truncated` | ✅ |
| Shipped candidates exceed the per-event cap (the remainder defer) | `promote_candidate_cap` | ✅ |
| **`getPull` refused by GitHub — 4xx** (DL-274) | `promote_getpull_4xx` | ✅ |
| **`compareStatus` refused by GitHub — 4xx** (DL-274) | `promote_compare_4xx` | ✅ |
| **`moveCard` refused by kanban — 403 / 404 / other 4xx** (DL-274) | `promote_movecard_403_not_writable_by_this_token` · `promote_movecard_404_no_such_card` · `promote_movecard_4xx` | ✅ |
| **a Shipped candidate's own row does not name the mapped board** (security refusal, DL-009 — **DL-298**, card#7211; the row came from the board-wide search, not from a token) | `card_not_on_mapped_board` | ✅ |

**Stated bound on the promote arms — one scan, one alert per reason, not one per card.** These three arms fire *inside a board-wide scan*, and dedup is per `(repo, outcome, reason)` with `outcome` fixed at `promote_on_release` — so N cards failing the same way in one scan produce **one** push (carrying the first card's id), not N. That is the intended bound, not an oversight: keying per card would let a single lost write-scope emit up to `MAX_CANDIDATES` pushes per release event, which is the storm the dedup exists to prevent. **The `Log::warning` still fires per card**, so the log is where you enumerate what was stranded; the push is the wake. The same marker also suppresses the signal on *subsequent* releases until it is cleared (see *Dedup* below) — worth knowing on the one leg with no reconcile backstop.

**`kanban_block_reason`** (the draft overlay; `outcome` is always the synthetic `draft_overlay`, which is what keeps its `getcard_*` reasons from sharing a dedup marker with the move handler's):

| Branch | `reason` | Signals? |
|---|---|---|
| `target_id` is not a card id (DL-285 — `card_id` is null; the payload's repo still keys the tuple) | `target_id_not_card_id` | ✅ |
| `payload.repo`/`action` invalid (DL-285) | `repo_or_action_invalid` | ✅ |
| writeback not configured (no `writeback.json`) (DL-285) | `writeback_not_configured` | ⚠ degrades to log-only (see below) |
| no mapping for repo / `draft_overlay` off (`Log::info`) | — | ❌ (expected "not tracked") |
| **the card id does not resolve ON the mapped board** (security refusal, card#8415 — the same board-scoped check the move handler makes, made BEFORE the card is read; the mapped board itself reads back, so the id is not this install's) | `card_id_outside_mapped_board` | ✅ (`card_id` **withheld**) |
| **the card id does not resolve on the mapped board AND the mapped board reads back EMPTY** (card#8415 — check the token's board membership) | `mapped_board_unreadable_to_this_token` | ✅ (`card_id` **withheld**) |
| **the board-scoped lookup answered a row that is NOT this card** (card#8415 — a broken/unfiltered read, never a tenant verdict) | `board_scope_lookup_unfiltered` | ✅ (`card_id` **withheld**) |
| **the board-scoped lookup was itself refused by kanban (4xx)** (card#8415 — the query named our own board, so the foreign-id cause is excluded) | `boardscope_403_token_scope` · `boardscope_404_no_such_card` · `boardscope_4xx` | ✅ (`card_id` **withheld**) |
| **`getCard` refused by kanban — 404 / 403 / other 4xx** (DL-274; ⚠ the **403** slug is **NARROWED at card#8415** for the same reason as the move handler's — the scoped check above has already found this id on the mapped board) | `getcard_404_no_such_card` · `getcard_403_token_scope` · `getcard_4xx` | ✅ (`card_id` **withheld**, DL-314) |
| card not on the mapped board (security refusal, DL-009 — **DL-285**; its `kanban_move_card` twin always signalled, and the gap was an asymmetry inside one guard, closed structurally at **DL-292**) | `card_not_on_mapped_board` | ✅ |
| **`setBlockReason` refused by kanban — 403 / 404 / other 4xx** (DL-274) | `blockreason_403_not_writable_by_this_token` · `blockreason_404_no_such_card` · `blockreason_4xx` | ✅ |
| **SET** refused — an uncorroborated title-only `card#` names a card that tracks a **different** PR (security refusal, card#5953) | `card_token_uncorroborated` | ✅ (log + push only — the card#7064 note is on the MOVE handler's twin of this row, not this one) |

**Stated bound on the two card-note rows (card#7064).** The note is the CARD's copy of the signal, never a replacement for the log or the push — both fire first and are untouched. It is written once per card per dropped set of values (marker-matched against the card's own comments), so unlike the `(repo, outcome, reason)` alert dedup below it does not go quiet across cards: N cards each losing a leg get N notes, one each. And it is the only writeback arm whose failure is never retried **within a delivery** — a 5xx here is dropped after one attempt, because the alternative is 5xx-ing a completed move over a comment. It is not dropped forever: the once-per-note check can only match a note kanban actually STORED, so every later event asserting the same drop re-POSTs and re-alerts. An install whose token lacks comment-create therefore keeps signalling rather than falling silent after the first failure — and a board where the note genuinely lands stays at one.

**Stated bound on both `card_token_uncorroborated` rows (move + overlay).** Their dedup tuple `(repo, outcome, reason)` is constant per repo per surface, so the **first** refused hijack on a surface pushes and every later one there is log-only until the marker is cleared — the `Log::warning` (per card) is where you enumerate what was refused; the push is the wake. Same intended shape as the promote-arm bound above.

**The three ISSUE/PR-keyed handlers** (DL-285; `card_id` is null on every row below except the two per-card coord-move arms, and `issue_number` carries the GitHub issue or PR number instead):

| Handler / `outcome` | Branch | `reason` | Signals? |
|---|---|---|---|
| `kanban_coord_card` · `coord_card_create` | malformed payload (repo/issue_number/itype/title) | `coord_card_payload_invalid` | ✅ |
| | writeback not configured (no `writeback.json`) | `writeback_not_configured` | ⚠ degrades to log-only (see below) |
| | repo not mapped / `create_coord_cards` off (`Log::info`) | — | ❌ (expected "not tracked") |
| | empty `sid` under `population: prefixed` — no correlation key | `coord_card_no_correlation_key` | ✅ |
| | the thread's only card is ARCHIVED — a deliberate retire (DL-296); one `coord:reroute-archived` twin exempts the whole thread | `coord_card_archived_twin` | ✅ |
| | a row in the post-create COLLAPSE set does not name the mapped board (security refusal, DL-009 — **DL-298**, card#7211; carries that `card_id` and the issue number) | `card_not_on_mapped_board` | ✅ |
| | the post-create COLLAPSE's duplicate-archive refused — that twin is **pinned** (**DL-340**, card#8523). Emitted by the shared `CardCollapse` kernel, so its `outcome` is the SUBSYSTEM (`kanban_coord_card`), the same value this handler's own rows carry | `pinned_no_automove` | ✅ |
| | kanban refused (4xx) — correlate or create | `coord_card_create_4xx` | ✅ |
| `kanban_coord_card_move` · `coord_card_move` | malformed payload (repo/issue_number/disposition) | `coord_card_move_payload_invalid` | ✅ |
| | writeback not configured (no `writeback.json`) | `writeback_not_configured` | ⚠ degrades to log-only (see below) |
| | repo not mapped / `move_coord_cards` off (`Log::info`) | — | ❌ (expected "not tracked") |
| | empty `sid` under `population: prefixed` — no correlation key | `coord_card_move_no_correlation_key` | ✅ |
| | kanban refused (4xx) **on one card** in the loop (carries that `card_id`) | `coord_card_move_card_4xx` | ✅ |
| | card not on the mapped board — the tag-collision refusal (security refusal, DL-009 — **card#7133**; carries that `card_id`; shared predicate + report since **DL-292**) | `card_not_on_mapped_board` | ✅ |
| | the `terminal` (close) move refused — the card is **pinned** (`block_reason` / `no-automove`, DL-178; **DL-340**, card#8523). No override, and the refusal is taken AFTER the already-concluded no-op, so a pinned card already in the terminal signals nothing. Carries that `card_id` and the issue number; the same per-card dedup bound as the two rows above applies | `pinned_no_automove` | ✅ |
| | kanban refused (4xx) on the **correlation read** | `coord_card_move_lookup_4xx` | ✅ |
| `kanban_dependabot_card` · `dependabot_card` | malformed payload (repo/outcome/pr_number) | `dependabot_card_payload_invalid` | ✅ |
| | writeback not configured (no `writeback.json`) | `writeback_not_configured` | ⚠ degrades to log-only (see below) |
| | repo not mapped / `create_dependabot_cards` off (`Log::info`) | — | ❌ (expected "not tracked") |
| | malformed **rename** payload — no `name_from`/`pr_title`, so no evidence the name is the bridge's (DL-328) | `dependabot_card_rename_payload_invalid` | ✅ |
| | kanban refused (4xx) — correlate, archive, move or create | `dependabot_card_4xx` | ✅ |
| | archive or move refused — the card is **pinned** (`block_reason` / `no-automove`, DL-178; DL-335, card#8454). No override; the message names which write was refused and the log carries the `card_id`. Both arms share the tuple, so a repo signals once | `pinned_no_automove` | ✅ |
| | the create-race COLLAPSE's duplicate-archive refused — that twin is **pinned** (**DL-340**, card#8523). Emitted by the shared `CardCollapse` kernel, so its `outcome` is the SUBSYSTEM (`kanban_dependabot_card`), not the synthetic `dependabot_card` the rows above carry — the two therefore dedup separately and cannot silence each other. Carries that twin's `card_id` and the surviving id | `pinned_no_automove` | ✅ |
| | a correlated card's own row does not name the mapped board (security refusal, DL-009 — **DL-298**, card#7211; carries that `card_id` and the PR number) | `card_not_on_mapped_board` | ✅ |
| | archive returned 200 but the card is not archived (`Log::error`) | — | ❌ **log-only** — accounted for (see *Still log-only*) |

**`bridge:reconcile` · `reconcile`** (the one CLI arm, **DL-301**, card#7211 — a command, not a handler, so it is outside the coverage test's level-keyed population; its KIND leg does scan it):

| Branch | `reason` | Signals? |
|---|---|---|
| a row from the board read does not name the mapped board — absent, unreadable or foreign (security refusal, DL-009; carries that `card_id`) | `card_not_on_mapped_board` | ✅ + a console `REFUSED` line + **non-zero exit** |

**Stated bound on that row, and it is the promote-arm bound once more.** It fires inside a loop over every card on the board read, dedup is `(repo, outcome, reason)` with `outcome` fixed at `reconcile`, so N foreign rows in one run produce **one** push carrying the first card's id — and the marker then suppresses it on later runs until it is cleared by hand, which matters more here than on the event arms because a reconcile is typically on a timer. **The console is the enumeration on this arm** (one `REFUSED` line per card, every run, unconditionally) alongside the per-card `Log::warning` the guard writes; the non-zero exit is what stops a cron reading a clean reconcile over an unfiltered result set. **Every other reconcile failure path stays console+exit-code only** — the command reports to an operator at a terminal, and DL-301 deliberately routed the ONE refusal that is a security decision rather than converting the command to a log-and-alert surface wholesale.

**Stated bound on the `coord_card_archived_twin` row (DL-296).** It is the one row here that reports a refusal the operator may have *intended* — archiving the card is how a human retires a thread — so its value is telling them the reopen went uncarded, not warning them of a fault. Dedup is the usual `(repo, outcome, reason)`: the **first** retired-twin reopen on a repo pushes and every later one there is log-only until the marker is cleared, so the per-event `Log::warning` (which names the archived card ids) is where you enumerate which threads it happened to. The remedy is on the board, not in config: unarchive the card if the thread is live again.

**Stated bound on the two per-card coord-move rows (`coord_card_move_card_4xx` and `card_not_on_mapped_board`).** Both fire *inside* `foreach ($ids as $id)` — a tag can correlate several cards — and dedup is per `(repo, outcome, reason)` with `outcome` fixed at `coord_card_move`, so N cards failing the same way in one delivery produce **one** push carrying the first card's id, not N. The same marker then suppresses that reason across **later deliveries** too, until it is cleared by hand (*Dedup* below owns the marker's lifecycle; `bridge:prune` does not reach it — `WritebackAlertNotifier` is the only thing in `app/` that touches that directory), so on the board-membership row in particular the operator is woken for the **first** cross-board collision on a repo and every later one is log-only. That is the intended bound and the same shape as the promote-arm and `card_token_uncorroborated` bounds above, but it qualifies what this row is worth: it converts *silent* into *told once*, not into *told each time*. **The per-event `Log::warning` is where you enumerate the rest** — it fires per card, per delivery, unconditionally.

**Why these three carry a synthetic `outcome` and FLAT `_4xx` reasons.** None has a PR outcome of its own that is safe to key on — `kanban_dependabot_card` does see one (`opened`/`merged`/`closed_unmerged`), and using it would split the dedup marker per PR state and re-alert a single misconfiguration once per state, so a constant naming the reaction is used instead. The 4xx reasons are deliberately **not** status-split the way `kanban_move_card`'s are: one `catch` in each of these handlers spans correlation **reads** and create/move/archive **writes**, so a `403_not_writable_by_this_token` would be wrong-but-specific on a refused read (the same reasoning that keeps the GitHub reads flat). The status and kanban's own words are in the log line's `body`. The coord-move handler's two 4xx arms carry **different** reasons because they share `(repo, outcome)` — one reason would give them one marker and whichever fired second would alert zero times.

**Still log-only — two branches inside the guarded handlers, both accounted for.** Both are on the coverage test's allow-list with the reason below, so they are exemptions on the record rather than omissions:

- `kanban_promote_released`'s *"mapping is missing the Shipped and/or Released stage"* — **type-narrowing, not a refusal an operator can hit**: `WritebackConfig::load` rejects a `promote_on_release` mapping missing either stage and validates every stage value as numeric, so no config that reaches the handler can leave either null. An alert there could never be seen to fail, and a check that cannot fail is a decoration. `KanbanPromoteReleasedHandlerTest` pins the fail-closed load that makes it unreachable, so if that guard is ever relaxed the branch becomes a real refusal and the test reds.
- `kanban_dependabot_card`'s **`Log::error`** *"archive returned 200 but the card is not archived"* — a 200 that did not archive is a kanban contract change, deterministic and therefore permanent. It is not routed because its **verbatim twin lives in the shared `CardCollapse` primitive**, which has no `(repo, outcome)` tuple to dedup on: routing one copy and not the other would mint a fresh asymmetry of exactly the kind DL-285 closed.

**Outside the guard's population**, and recorded here so they are not mistaken for coverage: `CardCollapse`'s twin of the archive-contract error above; `KanbanClient`'s correlation diagnostics (0-card read, page ceiling, no-card-collection body) — the same shape one layer down, at a shared client with no per-event tuple; and, since card#8523, `PinGuard`'s **`pin_row_unreadable`** detector, which fires when a row reaches the pin consult carrying neither `block_reason` nor `tags` (kanban emits both on every row, so their joint absence is a degraded read and the pin predicate is about to answer "not pinned" for a card nobody could read). All of them are tracked on card#5968 rather than left implicit; **no count is quoted here** — the set moves, and `grep -rn "Log::warning\|Log::error" app/Bridge/Writeback/` is what enumerates it.

**Why the write refusals split 403 from 404.** A `403` on a **write** is the one shape a read probe can never reveal: the token READS the card fine (so `getcard_*` stays quiet) and is refused on the PATCH — the scope-narrowed-token case, where read scopes are commonly broader than write scopes. It is a different operator hypothesis from the `getCard` 403 below, which is why the two carry different reason strings.

**Why the GitHub reads stay flat.** `promote_getpull_4xx` / `promote_compare_4xx` are deliberately **not** status-split: GitHub answers **404 for a private repo the token cannot see**, so a named `403 = forbidden` / `404 = absent` split would be wrong-but-specific. The status and the server's own words are in the log line's `body`.

**What the belongs-to-mapped-board guard ACCEPTS, and what changed at DL-292.** One predicate now answers it for all **seven** arms (`MappedBoardGuard::belongs()` — the three token-path sites DL-292 unified, the three DL-298 search-resolved re-checks, and `bridge:reconcile` since DL-301): the card's `board_id` must be **numeric** and must **equal the mapped `board_id` when cast to an int**. So `8`, `"8"`, `8.0`, `"08"` and `" 8"` all name board 8 and are accepted; `null`, `"abc"`, `""`, `true`, `9` and `"8abc"` are refused. The `is_numeric` half is load-bearing on that last one — `(int) "8abc"` is `8`, so a bare cast compare would wave a card from another board straight through.

**"Cast to an int" TRUNCATES, so the accepted set is the interval `[8, 9)`, not the value 8.** `8.9` and `"8.0000001"` are accepted for a mapped board of 8; `7.9` (→ `7`) is not, so truncation can only reach the mapped board from above. That is inherent to this predicate rather than a separate decision — there is no form of it that takes `"8"` and refuses `8.9` — and it is **ruled deliberate and left as-is** (DL-292): a fractional `board_id` is unreachable while kanban returns an integer, it opens no hole (every accepted value still truncates onto the mapped board), and a lossless compare would be a second behaviour change to the same guard to refuse a value no install emits. If you ever see one, it is a kanban contract change, not a tenancy event.

⚠ **DL-292 WIDENED this on `kanban_move_card` and `kanban_block_reason`.** Both previously compared with `!==` against a `readonly int`, which does no type juggling and so refused *any* non-int `board_id` — including a numeric string or float naming the mapped board itself. That was a false refusal: a legitimate move silently no-opped and a `card_not_on_mapped_board` alert pushed on correct work. **The accepted set only grew** — every value those two arms accepted before is still accepted, and every value that does not name the mapped board is still refused. **In practice nothing changes on a current install:** kanban returns `board_id` as a JSON integer today, so the widened set is reachable only if that ever stops being true.

**Why the `getCard` refusal splits 404 from 403.** The belongs-to-mapped-board security refusal below it (`card_not_on_mapped_board`) reads `board_id` **out of the card**, so it can only fire for a card this token was able to READ. A card on a board the token *cannot* see returns at the `getCard` refusal instead — which makes that reason string the operator's only signal for exactly the case the security guard exists to refuse. **⛔ Read that as a bound on the guard, not as a claim that the boundary held early:** the tenant boundary's precondition on this path is a **successful cross-tenant read**, so by the time it can be evaluated the id has already been SENT in a request on this install's credential (DL-314). What the refusal is, is fail-closed; what it is not, is a check that runs before the id leaves. So the two hypotheses are named separately:

- **`getcard_403_foreign_card_id_or_token_scope`** (renamed from `getcard_403_not_visible_to_this_token` at **DL-314**) — the card **exists** and is not visible to this writeback token. **Two causes, and a 403 cannot tell you which**, so the slug names both rather than picking one: (a) a **foreign install's card id** correlated onto this bridge, or (b) this **token's scope** missing a board of its OWN (rotation, lost board membership). ⛔ **Only the bare `card#NNNN` path can produce (a)** — that token is parsed as a literal out of author-controlled text (a PR title or a branch ref) and kanban's id space is GLOBAL across boards, so an id naming another install's card survives to the read. **A `DL-NNN` token CANNOT**: `correlateDl` resolves it through the board-scoped `GET /boards/{board}/tasks/by-ref.json`, so it can only ever return a card of the mapped board. (The line here previously named `card#`/`DL-NNN` together, which sent an operator hunting a token that is structurally incapable of the fault — corrected at DL-314.) **The alert carries no `card_id` on this row** (`card_id_withheld: true`); the id is in the `Log::warning` for this event, which is where to read it. **What to check first:** does the id appear in a PR title or branch ref on the repo named in the alert, and is it a card on **your** mapped board? If it is not yours, the token is fine and the correlation is the fault; if it is yours, audit the token's board membership. ⚑ **THE BRIDGE NOW DECIDES THIS ON BOTH TOKEN ARMS, so this slug is emitted by neither.** Deciding it needs a **board-scoped read** of the id against the mapped board — DL-314's deferred option — which `kanban_move_card` ships since card#8375 and the `kanban_block_reason` overlay since card#8415: cause (a) is excluded before `getCard` is called at all, an id that fails the check never reaches the read (it refuses under `card_id_outside_mapped_board` and its two siblings instead), and a 403 that does arrive is reported as **`getcard_403_token_scope`**. This slug is kept as the honest answer for any future arm that makes no such check, and is pinned by `tests/Unit/Support/RefusalContextTest.php` rather than by a shipped arm.
- **`getcard_404_no_such_card`** — the different hypothesis: a deleted card, or an id that never existed.
- **`getcard_4xx`** — the catch-all for every other 4xx (the server's own words are in the log line's `body`).

All three still swallow the event (permanent → log + no-op, never a 5xx retry), and each is a distinct dedup signature, so a 404 and a 403 on the same `(repo, outcome)` do not silence each other.

**Branch-#3 degradation (log-only).** The `writeback_not_configured` branch fires when there is no `writeback.json` at all — so there is also no `alert_channel` to load. That branch is therefore inherently **log-only**: the notifier loads its config from the same `writeback.json` and finds nothing, so it no-ops. (Place a `writeback.json` even if you only want the alert channel and no mappings, and the other branches signal.)

**Dedup — once per `(repo, outcome, reason)`.** A recurring failure (the same event redelivered, or a persistent misconfig) alerts **once**, not per delivery. Dedup is an atomic `O_EXCL` marker file under `<state_dir>/writeback-alerts/<sha1(repo, outcome, reason)>`. Remove the marker (or the directory) to re-arm a signature. A *failed* push releases the marker so a later redelivery re-attempts — a channel that was down when the first signal fired never permanently silences that signature (at the cost of a possible duplicate on a redelivery race).

**Best-effort, never breaks the move.** The push is wrapped so an undeliverable alert (channel down, bad config, HTTP error) is caught and logged — it never throws, so it can't turn a permanent no-op into a 5xx redelivery storm. The log line always runs regardless of whether the push succeeds. There is **no timer/poll/watchdog** — the signal is emitted inline, event-driven, on the failing delivery only. `bridge:check` warns on a malformed `alert_channel` (both/neither of socket+url, a missing socket parent dir, or a url the runtime sender would reject). For the url it applies the sender's own gate (`LocalhostUrl::assertValid`, DL-209) rather than a hand-rolled copy, so the check and the sender can never disagree: it warns on a non-http scheme, a non-loopback host, **or a userinfo component** (`http://user:pass@127.0.0.1/` — a credential-leaking SSRF shape the sender refuses).

## Failure behaviour (what retries vs not)

- **Transient** (kanban 5xx/timeout, a not-yet-placed token) → the webhook **5xx**s and kanban-board redelivers; the move retries once it's fixed.
- **Permanent** (no mapping, no stage, a malformed payload, a kanban **4xx** like a deleted card or a cross-board stage, the card isn't on the mapped board, or an uncorroborated title-only `card#` names a card tracking a different PR) → **logged + no-op**, the webhook acks 200 (a refused/un-actionable move is not a delivery failure — it would only retry-storm). With an `alert_channel` configured, the arms marked ✅ in *Which failures signal* above ALSO emit a live signal — the log is the durable record either way.

### Diagnosing a silent writeback (DL-026)

A writeback that "has no agent in the loop" can fail in two ways that **don't** error — they look identical to "nothing to do" — so the bridge now makes them loud (not as a 5xx; a genuine no-match still stays quiet):

- **Blind / degraded token (0 visible cards).** If the writeback token's user loses board membership (token rotation) or `writeback.json` has a wrong `board_id`/instance, kanban answers `200` with empty data. Every correlation then resolves to "no card" → moves silently no-op, **and** for `create_dependabot_cards` mappings the handler would *create a duplicate card* (it can't see the existing one). Caught both at config time (`bridge:check` 0-card probe) and at runtime (a `warning` log on the 0-card read).
- **Correlation mode `ref` vs `scan` (DL-029; default `ref` since DL-031).** `BRIDGE_WRITEBACK_CORRELATION` selects how the writeback finds a PR's card(s). **Default `ref`**; set `scan` for backwards compatibility or a kanban that predates `by-ref`. **⚠ Upgrading:** a `ref`-default bridge requires its kanban to be **v0.17.2+ and backfilled** (`php artisan kanban:backfill-external-references`) — else set `BRIDGE_WRITEBACK_CORRELATION=scan`. `bridge:check` probes `by-ref` reachability in `ref` mode and warns loudly if the kanban can't serve it, so a wrong default surfaces before any traffic. **The two modes answer one stored value identically (DL-311):** `scan`'s client-side match on `dl_number`, `pr_number` and `issue_number` runs through the same **one decorated integer** rule the kanban server applies to derive the by-ref index (kanban DL-251, mirrored by bridge DL-309), so a card storing `'1.5'` matches PR 1 in neither mode. Before DL-311 the PR and issue keys cast rather than canonicalized, and `scan` correlated such a card to a real, unrelated pull request or issue that `ref` mode had already refused.
  - `scan` (fallback): walks `/tasks/search.json` page by page (200/page) and digit-matches `payload.dl_number`/`pr_number` client-side. O(board size); a hard `MAX_PAGES`(50) ceiling bounds a runaway upstream, and a board beyond ~10,000 live cards would miss correlations past it (warned by `bridge:check`). Works against any kanban.
  - `ref`: one indexed `GET /boards/{b}/tasks/by-ref.json` per key (kanban DL-147/148) — server-canonicalized, O(1), no paging/ceiling. **Requires the kanban instance to expose `by-ref` AND its `task_external_references` to be backfilled** (`php artisan kanban:backfill-external-references`). Flip an install to `ref` only after confirming both (`bridge:check`).
- **One PR/DL can track multiple cards (kanban DL-148).** `by-ref` returns a collection and the scan returns all matches, so the writeback moves **every** correlated card (e.g. two FRs bundled in one PR). Each is a separate move target keyed by card id.

## Reconciliation — `bridge:reconcile` (DL-183)

The writeback is **event-driven**, and GitHub delivers each webhook **exactly once with no auto-retry**. So if the bridge is down during a PR event (a deploy, an outage), that card's move is lost and nothing re-drives it — the only backstop was the manual `board-session-close`. `bridge:reconcile` is the **rerunnable backstop**: it recomputes each tracked card's *expected* stage from **GitHub ground truth** (GET the PR, read its state/merged/base) and reports — or, with `--fix`, applies — the drift. This makes card movement **eventually consistent** (closes RC-B from the 2026-06-05 writeback-drift RCA).

```bash
php artisan bridge:reconcile                     # REPORT-ONLY: one line per drifted card + summary counts (exit 0)
php artisan bridge:reconcile --fix               # apply the forward moves
php artisan bridge:reconcile --repo owner/repo   # reconcile only one writeback.json mapping (matched case-insensitively, DL-293)
php artisan bridge:reconcile --fix --max-moves=20   # safety cap (default 20)
```

**⚠ "Report-only" means it makes no CARD move — it is not read-only for the bridge's own database.** Since DL-301 the belongs-to-mapped-board guard runs on every row the board read returns, *before* the run decides anything about that card, so a run without `--fix` still writes a `refused` row to `writeback_board_divergences` for every row that names another board (card#7212/DL-300). On a correctly-scoped instance that set is empty and the run writes nothing. It is also why that table dedups rather than appends: this command is the one arm of the record that runs on a **timer** (below), so an append-only row would have grown with the cron rather than with the number of divergences. A repeat bumps `observations` + `last_seen_at` on the row it already has.

**Reads PR state from GitHub → needs a github read token.** The **kanban-board repo is private**, so a `repo`-scoped read token is required in practice (a fine-grained read-only PAT is preferred — reconcile only reads). The token is resolved **per repo** (DL-185: a `[git-credential-map]` routes each repo to its own least-privilege PAT), in precedence order:

1. **`bridge.providers.github.token_path`** (env `BRIDGE_GITHUB_TOKEN_PATH`) — an explicit path to the token file. Point it at a centralized credential (e.g. `~/.config/coord/github-pat`) to reuse it without a per-install symlink. **When set it is authoritative**: a missing/blank file fails loud (no fallback), so a wrong path never silently resolves a different credential.
2. **`<secret_dir>/github/token`** — the conventional per-provider path (same convention `bridge:provision` uses; **not** the dedicated kanban writeback token), when no override is set.
3. **store-native — `git-credential-coord` + `[git-credential-map]`** (DL-185) — the default when no explicit token file is placed. `bridge:reconcile` calls the framework credential helper (`bridge.providers.github.credential_helper`, env `BRIDGE_GITHUB_CREDENTIAL_HELPER`, default `git-credential-coord`) with the repo's `host/owner/repo`; the store's `[git-credential-map]` (most-specific-first) selects the `[github]` key → a per-repo, least-privilege PAT with no second token copy to rotate. An **unmapped** repo (empty result) falls through to `GH_TOKEN`; a `REPLACE_ME` placeholder, an unreadable `*_file`, or a helper crash **fail loud** (never a silent fall-through to a wrong-scoped token). The helper is spawned inheriting the reconcile CLI env, so it needs `HOME`/`COORD_CREDENTIALS` to locate the store — fine for an interactive operator run (if you ever wire reconcile to a timer, set them in the unit). Set `credential_helper` empty to disable this leg. **A placed token file (leg 1 or 2) short-circuits the store map** — use a file *or* the store map for a repo, not both.
4. **`GH_TOKEN` env** — the last leg, used only when no override/file is set and the store returns nothing. It is present in an operator shell (`~/.bashrc`) but **not** in the webhook-spawned receiver, so it self-scopes to the reconcile CLI; it can never shadow a store-mapped token.

Without any usable source the command fails with a clear message naming the resolved path. On an auth failure, the per-repo probe error **names the resolved leg** — e.g. `github: cannot read repo owner/repo — HTTP 401 (token expired/revoked) (token from token file /path)` — so you can see *which* source won without instrumenting it (DL-186); `bridge:reconcile -v` prints the resolved leg per repo even on success. `bridge:check` warns when writeback is configured but no token resolves (or a file source is insecure), **and probes the resolved token's validity** against each mapped repo — a resolved-but-expired token gets a warn naming the leg (and the same status hint reconcile shows — `HTTP 401 (token expired/revoked)`, `HTTP 403 (… needs `repo` scope)`) at preflight, not a silent 401 on the first run. Both commands run the one shared resolve→probe→classify primitive, so their token diagnostics can't diverge.

> **⚠ UPGRADING to store-native per-repo tokens (DL-185)?** A pre-existing conventional `<secret_dir>/github/token` file — or `BRIDGE_GITHUB_TOKEN_PATH` — from the single-token era **short-circuits the `[git-credential-map]` store** (leg 1/2 beat leg 3), so every repo resolves that one file's token instead of its own per-repo PAT. On an upgraded install that file is frequently **stale** (nothing was rotating it once the store took over), which surfaces as *every repo 401s* on the first `bridge:reconcile` run despite a correctly-populated store map. **Fix:** remove the file (`ls <secret_dir>/github/token`; back it up, then `rm`/`mv`) so each repo resolves its own least-privilege token — or keep it deliberately if you *want* one shared token. `bridge:check` now flags this at preflight (the validity probe above names the shadowing leg).

**What it reconciles.** Only cards carrying a resolvable `(repo, PR)`: a `payload.pr_url` (yields both repo + number) or a `payload.pr_number` on a **1:1 board** (the mapping supplies the repo). A `dl_number`-only card is **skipped with an info line** — DL→PR resolution needs a GitHub search, out of v1 scope. A bare `pr_number` on a **shared** board is ambiguous (no repo) and skipped. **What counts as a resolvable `pr_number` is a POSITIVE BARE number naming ONE integer (DL-309)** — `85`, `85.0` and `'085'` all name PR 85, while `1.5`, `'2026-08-23'` and `'PR 12 of 34'` name no pull request and the card is **not tracked at all** (no GitHub read, no move, and no skip line — it is not a skipped card). That is the same answer kanban's by-ref index gives for the same stored value since its DL-251; before DL-309 the bridge derived PR 1 / 20260823 / 1234 here, each a real, unrelated pull request. A **decorated** value (`'#85'`) is not admitted either — unchanged, and deliberately not widened. The expected stage is derived from the PR state with the **same** outcome mapping as the event path (`open → opened`, `closed+merged` to the integration branch `→ merged`, to `main → merged_to_main`, `closed+unmerged → closed_unmerged`) — **and with the same closure gate (DL-305, widened DL-308): a PR merged to the integration branch that neither was merged from a head branch naming the card nor CLOSES it in its title yields no expected stage at all, and the card is skipped with a line naming the ref it read** (`merged_to_main` needs no gate here — it is out of this command's scope by construction, below). Without that, this leg would keep re-planning on a schedule the very move the event path declined. The card's own `dl_number` is compared through the reference normalizer, so a stored `DL-0305` and a title's `DL-305` are one DL.

**Safety posture** — it reuses the event-path guards rather than inventing new ones, and keeps read-side degradations LOUD (never a false-green):

- **Startup auth probe.** Before touching any card it does one `GET /repos/{owner}/{repo}` per mapped repo. A failure (401 = expired/revoked token, 403/404 = the token can't see that private repo) is reported loudly, that repo's cards are skipped, and the run exits non-zero — so an under-scoped or dead token can't silently 404 every card while the run exits 0.
- **Never moves a card backward** (DL-163 stage order). Backward drift is *reported*, not applied. When the board order can't be read, the drift is reported as *unorderable*, left alone (a batch mover must not guess direction), and the run exits non-zero (a drift left unreconciled for lack of order data is a degraded run, not a clean one).
- **Never moves a pinned card** (DL-178 `block_reason` / `no-automove`).
- **Never moves a card that is not on the mapped board** (DL-009 / **DL-301**, card#7211). The board read is a `q=board_id=<b>` server-side search, and nothing in a 200 response distinguishes a dropped filter from an honoured one — so each row is re-checked client-side against `writeback.json`'s `board_id` through the **same** `MappedBoardGuard::refuses()` the event path uses, *before* the per-card GitHub read. A row naming another board (or naming none) is refused: no move, no PR read against this repo's token, not counted as in-sync, a `REFUSED` console line, a durable `Log::warning`, an alert (`card_not_on_mapped_board`, outcome `reconcile`) and a **non-zero exit**. On a correctly-scoped instance this refuses nothing, which is the point — it makes the board scope a property of the rows that came back rather than of how the query was written.
- **Release-promotion is out of scope.** The `released_to_main` stage is treated as **terminal**: a card there is never moved out, and a merge-to-`main` PR (outcome `merged_to_main`) is never moved *in* — the `release-promote-cards` workflow is that stage's rerunnable owner.
- **A truncated board read aborts that board** (never reconciles a partial view) and fails the run loudly.
- **`--max-moves` (default 20) caps a run:** more planned moves than the cap **aborts before applying ANY** — mass movement means a bug, not drift. Raise the cap deliberately if a large legitimate backlog of drift is expected.
- **A per-card GitHub error after the probe:** a **404** is a genuinely deleted PR → warn + skip that card (the run continues, exit unaffected); a **401/403** means the token was revoked mid-run → the run exits non-zero. A timeout/connection error warns + skips that one card.

**Not reconciled in v1 (documented gaps):**

- **`dl_number`-only cards** and a **bare `pr_number` on a shared board** — no resolvable `(repo, PR)`; skipped with an info line.
- **The branch-create `started` outcome** (a card promoted to In-Progress by a `push` that created a branch, DL-160) — there is no PR to GET, so a dropped `push` event is *not* recovered here; it self-heals on the card's next PR event.
- **`closed_unmerged` (abandoned-PR) regression** — this is legitimately *backward* (In-Review → In-Progress) and the event handler applies it, but the reconciler declines all backward moves, so a dropped `pull_request.closed`-unmerged event is **reported** (with an accurate label) but not auto-fixed. It is left to the event path (redelivery) or a human in v1.

**Scheduling.** The command ships with **no new cron** — and since DL-199 the design accepts **no** periodic job at all (retention now runs off the inbound webhook; `bridge:prune` is the manual entry point). `bridge:reconcile` is the one thing you may still want on a timer, because nothing event-drives it. Run `bridge:reconcile` from a host cron (e.g. hourly, report-only; `--fix` less often or after review), or wire a report-only pass into the session-close ritual. (An hourly report-only pass is not free of writes — see the note above the token section.) Automating `--fix` is an operator choice; start report-only and add `--fix` once the report is boring.

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

**What an applied move records.** Each move logs `bridge_reconcile: moved` with the card id, the target stage, the PR outcome and the **`card_board` + `mapped_board` pair** (card#7212) — the board the moved card was actually on, beside the one config aimed at. That record is durable and independent of where you send the command's stdout; the `MOVED` console line above it is the operator's live view, not the record.

`--max-moves` is the **circuit-breaker**: a run planning MORE than the cap aborts before applying *any* move (mass movement means a bug, not drift — re-run manually with a higher cap once you've explained it). Start report-only for a few days; add the `--fix` line once the report is consistently boring. If the store-native leg is in use, first confirm the unit's env resolves the token: `HOME=… PATH=… php artisan bridge:reconcile -v` should print `github: <repo> — readable (token from store …)` per repo.

## Security notes

- Board + stage are **operator config only**, keyed on GitHub-controlled fields — the webhook body can't choose a board or stage. The worst an attacker-influenced PR can do (via title correlation) is nudge a card *that genuinely sits on the mapped board* to a *config-mapped stage* — bounded, reversible, logged. **That bound is real but was doing less work than it reads (card#5287):** "sits on the mapped board" is satisfied by *every other card on your own board*, so a `card#` in a PR **title** could nudge a colleague's card, and it did not even need to beat the branch's own token — the concatenated title-then-branch match preferred it. A title token that disagrees with the branch is now refused, and a title-only token is refused against any card that already tracks a different PR (see **Precedence**).
- The `started` trigger (DL-160) keys on `payload.created` + `payload.ref` (GitHub-controlled, not body-spoofable to the bridge — they ride a HMAC-verified delivery), and the move is **doubly bounded**: it only ever advances a card *that sits on the mapped board* *from a configured `started_from_stages`* to the *config-mapped `started` stage*. Worst case from a maliciously-named branch is the same bounded, reversible forward nudge as the PR path — and only for a card already in Backlog/Prioritized.
- The writeback token is least-privilege, `0600`, read fail-closed at point-of-use, never logged.
- The writeback identity is auto echo-suppressed (its `card_updated` webhook doesn't loop back).
