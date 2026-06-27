# Changelog

All notable changes to the agent-webhook-bridge are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The changelog is **release-event only** — entries land in the release-tag commit, not in feature PRs. See [`../VERSIONING.md`](../VERSIONING.md) for the full policy.

> This repository's git history begins at **v0.12.0**. The bridge existed earlier (v0.1–v0.11, a Python-consumer + PHP-receiver implementation), but that history was not carried into this repository. The design rationale that is still load-bearing for the current code is preserved in [`../CLAUDE_DECISIONS.md`](../CLAUDE_DECISIONS.md); v0.12.0 itself is recorded in **DL-001**.

## [Unreleased]

_(empty after each tagged release; accumulates as feature PRs land on dev)_

## [0.42.2] - 2026-06-27

**Docs: MCP-channel + upstream-reconcile is the recommended PM consumption model; `bridge:inbox` is the fallback (DL-170).** PR #185. **Docs only — no app code, classifier, schema, migration, or `.env`; no behavior change.**

### Changed

- **`docs/consumer-guide.md` § Consumption patterns — canonized the PM consumption standard (DL-170).** For a live, upstream-anchored agent (a PM): **MCP channel push** for the event-based live wake + **reconcile-from-source-of-truth** (GitHub via `gh`, kanban via API) for recovery — `SessionStart` = full-dump, mid-session = a **light delta** (only new-since-watermark, silent-when-empty, throttled, sub-second; the full reconciler is ~40s and would stall an inline tool call). The bridge `inbox` is repositioned as the **fallback** — for consumers with bridge-only intents or that can't reconcile from an upstream. Validated by two independent PM implementations (Sola + AIMLA), both of which confirmed zero bridge-only intents (so the inbox is redundant for them) and built the same SessionStart-full + mid-session-delta design. DL-169's `bridge:inbox` PreToolUse guidance stays valid for the fallback path; DL-170 sets the primary recommendation above it. The open convergence item — upstreaming the GitHub+kanban delta reconcile into the shared consumer-side framework — is consumer-side, recorded for the framework maintainer.

## [0.42.1] - 2026-06-27

**Docs: stress `PreToolUse` as the required mid-session `bridge:inbox` trigger (DL-169).** PR #182. **Docs + example only — no app code, classifier, schema, migration, or `.env`; no behavior change.** Prompted by a peer-integrator (Sola PM) "silently lost events" report that diagnosed to **consume-side hook wiring, not bridge code**.

### Changed

- **Hook-wiring guidance made complete (DL-169).** The live `channel_push` is best-effort by design (DL-001); the durable inbox is the recovery road, but it only surfaces when a hook fires `bridge:inbox`, and `SessionStart` fires once per session — so a long-lived session wired on `SessionStart` only never re-checks the inbox mid-session, and an intent that arrives during a long turn sits unseen until restart (tell-tale: a `inbox-seen-<agent>.json` cursor stale for days). `docs/consumer-guide.md § Wiring` now carries a "both legs are load-bearing" callout (`SessionStart` = boundary recovery; `PreToolUse` = the recommended per-tool-call mid-session trigger, `PostToolUse` equivalent) and recommends `"matcher": ""` over `"Bash"` (a narrow matcher reopens the gap on non-matching work), with the subprocess-per-tool tradeoff stated; plus a troubleshooting entry keyed on the observable symptom. `examples/claude-code/settings.json.example`: `PreToolUse` matcher `"Bash"`→`""` + a `_wiring_note`.
- **Fixed a pre-existing doc bug** in the same section: it claimed wiring on `Stop` *advances* the seen cursor — it does **not** (`Stop` ∉ `ADDITIONAL_CONTEXT_EVENTS` ⇒ `InboxCommand` leaves the intents unseen), contradicting the code and the correct statement earlier in the same doc.

## [0.42.0] - 2026-06-20

**Triage-wake classifier — a human-filed, untriaged card wakes the triage-owner session in near-real-time (DL-168).** PR #176. App code — **no migration, no config schema change, no change to what the receiver accepts/rejects.** Opt-in (OFF until an agent sets `classifier.class`). Requires kanban **v0.22.0+** for the `card` snapshot (degrades to over-wake on older). Closes peer-integrator (AIMLA PM) FR #3010.

### Added

- **`KanbanTriageClassifier` (DL-168, #3010).** In the DL-driven board model a work item a human files **directly on the board** is untriaged (no `DL-NNN`, no `triaged` tag) and needs the PM to triage it — but today the PM discovers these only at SessionStart (session-cadence; a card filed mid-session waits for the next session). The new opt-in classifier (extends `InboxOnlyClassifier`) pairs the `new_card` Intent for a **human-filed** (`!isKnownAgent`), **untriaged** (no `triaged`/`id:pr:*` tag, no `dl` external reference) `task.created` with a `channel_push` to the triage-owner's cfg-default channel — the same live-wake transport the bridge already uses. Everything else stays inbox-only. **The filter runs entirely at classify time with NO API call and NO read token** off the kanban DL-164 `card` state snapshot the `task.created` webhook now carries — this is why the upstream snapshot was built first (it avoids the per-consumer `GET /tasks/{id}` + read-token workarounds). **No self-wake** (each automated creator is suppressed by a *different* mechanism): registered agents by `isKnownAgent`; the bridge's own dependabot-card creations carry `triaged` (DL-024) → dropped by the untriaged filter; the writeback identity is dropped pre-classify by the global-echo gate. **Degrade:** a pre-v0.22.0 kanban omits the `card` key → reads as untriaged → over-wake (never a miss; the SessionStart untriaged-snapshot is the durable backstop). **Opt-in:** set `classifier.class: App\Bridge\Classifiers\KanbanTriageClassifier` on the triage-owner agent + subscribe it to `task.created`; other agents keep `InboxOnly` and never wake.

## [0.41.0] - 2026-06-20

**Source-aware correlation — the writeback passes the repo as the kanban `source` qualifier (DL-167).** PR #172. App code — **no migration, no new config.** Requires kanban **v0.21.0+** for the `source` param (degrades to any-source on older). Closes the cross-repo collision reported by both peer integrators.

### Fixed

- **Repo-qualified by-ref correlation (DL-167).** A bare PR/DL number collides across repos on a board aggregating multiple repos (live: AIMLA board-9 `by-ref?system=github_pr&ref=33` → 4 rows across platform/magento/moodle; Sola board-3 shares 296 PR numbers across 3 repos). kanban added a `source` (repo) dimension on `by-ref` (kanban DL-163, v0.21.0); the bridge now uses it. `KanbanClient::correlatePr`/`correlateDl` take the event's repo and, in **`ref` mode** (the default, the only mode the multi-repo adopters run), pass it as the canonicalized `source` query param so the server returns only **this repo's** card(s) — for **both** the dependabot path (`KanbanDependabotCardHandler`) and the DL move path (`GitHubPrCardMoveClassifier`). Omitted repo / single-repo board ⇒ no `source` key ⇒ byte-identical to before. `scan` mode (legacy) keeps its existing `cardsForRepo` client-side guard. Closes the collision AIMLA + Sola reported — they pull this update to get repo-precise correlation.

## [0.40.0] - 2026-06-20

**Dependabot writeback card-create made idempotent on `(repo, PR)` — collapses concurrent-delivery duplicates (DL-166).** PR #168. App code — **no DB migration, no new config, no change to what the receiver accepts/rejects.** Plus the `closed_unmerged → Won't Do` operator-doc option (#167, docs only). Behavior change scoped to the dependabot-card path (`create_dependabot_cards`); the DL-tracked move path is byte-identical.

### Fixed

- **Dependabot card create is idempotent on `(repo, PR)` (#2982, DL-166).** Closes peer-integrator (Sola PM) report #2982 — the DL-024 dependabot writeback **double-created** a card for one PR (live: board-3 cards 2965+2968 for `actions/checkout` PR #289) and **orphaned the duplicate** in In-Review on merge. Root cause is a **check-then-create race**, not a correlation miss: `correlatePr`→`createCard` isn't atomic across concurrent deliveries (`opened`+`reopened`, or a fresh-`delivery_id` re-emit), so two parallel workers both correlate empty and both create. `KanbanDependabotCardHandler` now **collapses duplicates on the `(repo, PR)` key** — keep the lowest-id survivor (deterministic ⇒ racing workers converge), archive the rest (idempotent) — applied after create (closes the race) and on the move path (self-heals duplicates minted before this shipped). **Cross-repo guard (load-bearing):** correlation keys on the bare PR number (kanban's `github_pr` by-ref isn't repo-qualified), so on a board **shared across repos** (DL-027) a same-numbered PR in another repo collides; the handler attributes each correlated card by its `pr_url` and only ever moves/archives **this repo's** cards — a co-hosted repo's identically-numbered card is never touched (this also fixes a pre-existing cross-repo mis-*move*).

### Docs

- **`closed_unmerged → Won't Do` documented as a per-deployment operator option (#167, AIMLA FR Part B1).** `docs/writeback.md` now documents mapping `closed_unmerged` to a terminal "Won't Do" stage as an abandon-disposition (the default stays In Progress; the DL-163 guard already permits the terminal move; the dependabot path always archives regardless). Docs only — no app code.

## [0.39.0] - 2026-06-20

**Writeback no-regression guard generalized to all four PR outcomes (DL-163) + a `bridge:check` guard for silently-misconfigured dependabot cards (DL-162).** PRs #159–#160 + #163 (#2652, DL-164: bridge:check started/stage-id guards) + #164 (#2446, DL-165: promote-released-cards loud-fail) since v0.38.0, plus dependency bumps. No DB migration, no new config. The writeback change is the load-bearing one: it stops released/shipped cards being dragged backward by stale or redelivered `pull_request` events — **deploy this to halt the recurring board drift** that the prior writeback left unguarded.

### Fixed

- **No-regression guard on the four PR-move outcomes (DL-163, #2935).** DL-160 added a backward-move guard only to the `started` outcome; the original four — `opened` / `merged` / `merged_to_main` / `closed_unmerged` — still moved the card **unconditionally**, so a stale or redelivered `pull_request` event, or a **release PR whose title carries a card's `DL-NNN`**, could drag an already-Released card back to In-Review (seen live: cards #2650/#2659 drifted Released→In-Review repeatedly). `KanbanMoveCardHandler` now refuses any PR-move that would regress a card to an earlier stage, using the board's workflow-stage **order** (read from the lightweight preload via `KanbanClient::boardStageOrder`). `closed_unmerged` — the one legitimately-backward outcome (an abandoned PR returns its In-Review card to In-Progress) — is allowed to regress **unless** the card has already reached a terminal (Shipped/Released) stage, so a stale close can't resurrect a shipped card. **Fail-open:** when the order can't be read, the move proceeds as before, so the guard never breaks the writeback. One extra lightweight preload GET per PR-move event.

### Added

- **`bridge:check` flags a `create_dependabot_cards` mapping whose board lacks the create-payload custom fields (DL-162, #2949).** A mapping with `create_dependabot_cards: true` POSTs a card with the payload keys `pr_number` / `pr_url` / `origin`; kanban 422s any unregistered payload key and the handler treats the 4xx as permanent (logs + no-ops), so a board missing even one field drops **every** dependabot-card create silently (200 delivery, no card — found live on board 8 with no `pr_url`). `bridge:check` now reads the board's registered custom-field keys (`GET /boards/{id}/custom_fields.json`) and warns loudly, naming the missing field(s), when the flag is on but the board lacks them — warn-never-fail, the create-path twin of the DL-027 swimlane check. The required-key list is `KanbanDependabotCardHandler::CREATE_PAYLOAD_KEYS`, now the authoritative single source (the create payload is built from it, so the check can't drift). Diagnostics only — no delivery-path change.

### Dependencies

- Bump `laravel/framework` 13.12.0 → 13.16.1 (#149), `phpunit/phpunit` 12.5.28 → 13.2.1 (#148), `symfony/yaml` 7.4.13 → 8.1.0 (#147), `shivammathur/setup-php` 2.37.1 → 2.37.2 (#145), `laravel/pao` 1.1.0 → 1.1.1 (#146), `actions/checkout` 6.0.2 → 6.0.3 (#113).
- **Security:** bump `hono` 4.12.23 → 4.12.26 in `examples/channel-servers` (#165) — fixes the high-severity path-traversal advisory GHSA-wwfh-h76j-fc44 (`serve-static` on Windows via encoded backslash). Channel-server example → **0.4.4**.

## [0.38.0] - 2026-06-19

**Dependabot writeback archives the card when its PR closes unmerged (DL-160-sibling, DL-161).** PR #155 since v0.37.1. App code — no DB migration, no new config. **Behavior change, scoped to the dependabot-card path** (`create_dependabot_cards: true`); the DL-tracked move path is byte-identical. Closes a peer-integrator (Sola PM) FR (#2659).

### Changed

- **A closed-unmerged dependabot PR now ARCHIVES its card instead of moving it (DL-161).** Dependabot routinely closes its own PRs (a newer bump supersedes an older one, or a maintainer closes it), so the old behavior — `moveCard` to the `closed_unmerged` stage — left dead cards accumulating on the board (the reporter had 7 stale cards sitting in Backlog 8–11 days after their PRs closed). `KanbanDependabotCardHandler` now archives every correlated card on `closed_unmerged`, which needs **no `closed_unmerged` stage mapping** for the dependabot path. "No card on close → skip" is unchanged.

### Added

- **`KanbanClient::archiveCard()`** — issues the kanban lifecycle verb `PATCH {"_action":"archive"}` (a `{"task":{"archived_at":…}}` *field* PATCH returns 200 but silently no-ops) and returns whether the response confirms the archive (`data.archived_at` set). An unconfirmed archive is **deterministic**, so the handler logs an `error` and no-ops rather than 5xx-ing into a ~11-day redelivery storm (the DL-020 anti-pattern); a genuine HTTP error still throws (transient 5xx → retry, 4xx → permanent). Idempotent for free: kanban excludes archived cards from by-ref/search correlation, so a redelivered close finds nothing.

## [0.37.1] - 2026-06-19

**Docs: warn that the DL-160 `started` outcome must be added to `writeback.json` AFTER deploying v0.37.0+ (#2658).** Docs only — no app code, classifier, schema, migration, or `.env` change; no behavior change. Follow-up to v0.37.0 (DL-160), surfaced during the prod activation.

### Docs

- **`docs/writeback.md` § Branch-create → In Progress: upgrade-ordering warning.** Adding `started` to `writeback.json` while a pre-v0.37.0 bridge is still serving fails **closed for every mapping in the file** — a pre-v0.37.0 `WritebackConfig` rejects the unknown `started` outcome as a *malformed config*, which disables the whole writeback (all repos), not just the edited mapping. Documented the required sequence: deploy + reload → `bridge:check` green → *then* edit config → `bridge:check` again.
- **`CLAUDE_DEPLOYMENT.md` § Update an existing install: same ordering callout, generalized** to any newer-version writeback outcome (deploy code first, edit `writeback.json` second), cross-linked to the `started` config + required `push` webhook event.

**Branch-create push → card "In Progress": derive work-begun from the artifact (DL-160).** PR #150 since v0.36.0. **App code — no DB migration; opt-in and OFF until configured.** Closes the gap where a card sat in Backlog/Prioritized through the whole first stretch of work and only advanced at PR-open. Adds a fifth writeback outcome, `started`, driven by the GitHub `push` that **creates** a feature branch — "work has begun" derived from the branch, no agent in the loop, consistent with the writeback's machine-only posture. **Requires the operator to (a) map a `started` stage + set `started_from_stages` in `writeback.json`, and (b) subscribe the repo webhook to `push` events** — an upgraded install with neither is inert. See [`docs/writeback.md`](writeback.md) § *Branch-create → In Progress*.

### Added

- **`started` writeback outcome — branch-create push promotes the correlated card to In Progress (DL-160, #2650).** `GitHubPrCardMoveClassifier` now classifies the already-parsed `push` event (previously ignored): a push that **created a branch** (`payload.created === true`) whose `refs/heads/…` ref carries a `DL-NNN` emits a `kanban_move_card` target with `outcome: 'started'`, correlating DL→card exactly as the PR path. Fires **once at branch birth** (not on subsequent pushes to the same branch); a `dependabot/*` branch, a tag ref, or a DL-less ref is a no-op. Which board+stage `started` maps to is operator config (`writeback.json` `stages.started`), never hard-coded. The four existing PR outcomes and their tests are byte-identical.
- **No-stage-regression guard via `started_from_stages` (the load-bearing safety decision).** A `started` move must only ever **promote** a card — never drag an already-In-Review/Shipped/Released card backward (re-creating or force-pushing an old branch re-fires `push`+`created`). `KanbanMoveCardHandler` reads the card's current `workflow_stage_id` and applies the `started` move **only** when that stage is in the mapping's new optional **`started_from_stages`** (the board's Backlog/Prioritized stage ids), parsed strictly like `board_id`/`stages` (a non-list or non-numeric element fails the config closed). **Absent `started_from_stages` ⇒ the `started` move is refused** (fail-closed — the guard can't know what's safe to promote from; logged + no-op), so the trigger can't silently regress a card. Idempotent: the already-in-target-stage short-circuit still applies first.

### Operator action required

- **Subscribe the repo webhook to `push` events** (in addition to *Pull requests*). `bridge:provision` does **not** manage GitHub webhooks (no repo-admin token, by design) — set this by hand in the repo's **Settings → Webhooks**. A webhook left on *Pull requests* only will silently never fire the `started` move.
- **Map a `started` stage and set `started_from_stages`** in `writeback.json`. Both are required to enable the trigger; neither set ⇒ inert (no behavior change on upgrade).

## [0.36.0] - 2026-06-14

**Channel-server example cleans up its UNIX socket on every ordinary quit (DL-159).** PR #141 since v0.35.0. **Example + docs only — no app code, classifier, schema, migration, or `.env` change.** Channel-server example → **0.4.3**. Server-side counterpart to DL-154/155/157; closes a peer integrator's (Sola PM) report (#2533).

### Fixed

- **`examples/channel-servers/agent-webhook-bridge-channel.mjs` unlinks its socket on `SIGINT`/`SIGHUP`/stdin-EOF, not just `SIGTERM` (DL-159).** The server had one signal handler (`SIGTERM`) and no explicit `unlink`, so terminal-close (`SIGHUP`), Ctrl-C (`SIGINT`), and parent-pipe close (stdin EOF) leaked the pathname AF_UNIX socket — and a leftover pathname socket makes the next direct `bind()` fail `EADDRINUSE` on Linux **regardless of any listener**, so the server wrote a `<socket>.FAILED` "another session already holds the channel" deaf marker when zero sessions ran. Now one idempotent `shutdown()` handles `SIGTERM`/`SIGINT`/`SIGHUP` and unlinks **synchronously** (`server.close()` unlinks asynchronously while `process.exit()` is synchronous, so an in-flight connection at `SIGTERM` could leak even on the "clean" path). The unlink is gated on a `bound` flag set at listen-success, so a signal during the `EADDRINUSE` failure window never removes a live peer's socket. Parent-death self-exit uses `process.stdin.on('end')` — the MCP SDK's `StdioServerTransport` registers stdin `'data'`/`'error'` but no `'end'`/`'close'`, so it doesn't surface EOF via `onclose`; a bare `'end'` listener is the correct hook (no `resume()` needed, the SDK already flows stdin), with `mcp.onclose` wired as defense-in-depth. `SIGKILL` and hard crashes still leak by design — that's what the launcher's stale-socket guard + the `.FAILED` marker backstop are for. Example → **0.4.3** (DL-038 drift signal).

## [0.35.0] - 2026-06-14

**Canonical GitHub-issue-comment classifier reference + custom-classifier reconcile step (DL-158).** PR #137 since v0.34.0. **Docs only — no app code, classifier, schema, migration, or `.env` change; no behavior change to any shipped class.** Closes the canonicalization follow-on to a peer integrator's (Sola PM) FR (#2514); the consumer-side issue was already resolved in the integrator's own classifier.

### Docs

- **`docs/customization.md`: "Surfacing GitHub issue comments to a channel (forward the comment identity)" (DL-158).** A minimal worked-example custom classifier that turns `issue_comment.created` into a `github_issue_comment` Intent forwarding the **comment identity** — `comment_id` + `comment_created_at` + `comment_html_url` — paired with a `channel_push` (`target_id == subject_id`). It lets a consumer **exact-fetch** the triggering comment (`GET /repos/<repo>/issues/comments/<comment_id>`) and de-dup replays **by id**, instead of re-reading the whole thread and positionally guessing the newest comment — GitHub's issue-comments endpoint paginates 30/page oldest-first with no `sort`/`direction` param, so a naive `.[-1]` returns the 30th comment, not the newest. The shared-identity/recipient machinery is cross-referenced as an **optional** layer, kept out of the base; the shape generalizes to PR review comments.
- **`CLAUDE_DEPLOYMENT.md`: "Reconcile out-of-repo copies" now covers the custom classifier (DL-158).** A custom classifier lives in the install's `app/Bridge/Classifiers/` and survives `git pull` untouched — so it freezes at the reference it was copied from. New installs start from the `customization.md` reference; each update **diff-merges** improvements (e.g. the `comment_id` forwarding) while preserving deployment-specific extensions, never blind-replacing. `bridge:check` confirms the classifier *loads*, not that it's *current*.

## [0.34.0] - 2026-06-13

**Multi-topology channel live-wake: HTTP-aware `bridge:check` (DL-156) + a canonical self-resolving cross-platform launcher (DL-157).** PRs #132–#133 since v0.33.0. Examples + docs + one diagnostic command; **no receiver/handler/schema/migration/`.env` change.** Channel-server example → **0.4.2**. Closes a peer integrator's (Sola PM) FR-1/2/3 for the multi-agent, multi-host (HTTP-over-SSH-tunnel) + Windows topology.

### Added

- **Canonical self-resolving, transport-aware channel launcher (DL-157, FR-1).** `examples/start-channel-session.sh` is rewritten and `examples/start-claude.ps1` + `examples/start-claude.bat` are added, so **one launcher per OS** serves any agent with no per-agent hardcoding (killing the hand-rolled-copy drift). Identity self-resolves `--channel` → `$BRIDGE_CHANNEL_NAME` → `settings.local.json` `.env` → `<namespace>-<agent>` from `$COORD_CONFIG` + `$COORD_AGENT` (the launcher runs in the login shell, which can't see Claude Code's session-injected env — the #1 "can't resolve channel" cause). Transport-aware guards for **UDS and HTTP** (socket-curl/`pgrep` vs TCP-port probe), DL-154/155 marker surfacing rendered for both, and the resolved identity is **exported before `exec`** so the channel server binds exactly the endpoint the launcher guarded. Windows half (PowerShell + a `.bat` ExecutionPolicy shim) owns the SSH-reverse-tunnel lifecycle (hidden side process, PID-tree teardown).
- **`bridge:check` HTTP-transport awareness (DL-156, FR-2).** For an HTTP-transport agent (`channel.url`, no `channel.socket` — the SSH-tunnel topology), `bridge:check` now **TCP-probes** the loopback/tunnel `host:port` for liveness (reaches the remote connector through the tunnel) and surfaces an HTTP `.FAILED` marker best-effort when run on the agent host. DL-154/155's deaf-session surfacing was UDS-only before. Warn/info only, never fails the check.

### Changed

- **Channel server `markerPath()` HTTP base `'/tmp'` → `os.tmpdir()` (DL-156).** A literal `/tmp` resolved to `C:\tmp` under Node on Windows and never matched the Windows launcher's `%TEMP%` lookup, so the HTTP marker was unfindable there; `os.tmpdir()` is `%TEMP%` on Windows and `/tmp`/`$TMPDIR` on Linux. Example → **0.4.2** (DL-038 drift signal).

### Docs

- **`CLAUDE_DEPLOYMENT.md`: "The canonical channel launcher" + "Multi-agent channel-server distribution" (FR-3).** The launcher's resolution chain, `COORD_CONFIG` shape, transport selection, and Windows tunnel lifecycle; plus the canonical per-snapshot reconcile — pin the **release tag via `git` (not `gh` — auth mismatch misleads)**, copy `examples/channel-servers/`, `npm ci`, **at a session boundary**, uniform provenance = the same tag on every agent. `docs/config-schema.md` `channel.url` row notes the new probe.

## [0.33.0] - 2026-06-13

**Channel launcher fail-loud fix (DL-155) + update-runbook & doc-citation hardening.** Commits since v0.32.0. No app code, no DB migration, no new `.env` keys. Channel-server example → **0.4.1**.

### Fixed

- **`start-channel-session.sh` surfaces the `<socket>.FAILED` marker instead of silently clearing it (DL-155, correction to DL-154).** The launcher `rm -f`'d the deaf-session marker *before* binding — wiping the durable fail-loud signal FR #2444 created, at the exact moment (a relaunch) an operator should see it. The connector already clears the marker unconditionally on a successful bind (which the launch triggers moments later), so the launcher clear was both redundant and silent. The launcher now prints the prior deaf-session's marker (timestamp + pid + reason) to stderr and leaves the connector as the **sole** clearer; surfacing is best-effort (`|| true`) so a stray unreadable marker can never block a session start. The deployed `~/start-claude.sh` gets the same change (operational sync, outside the repo).
- **Channel-server example bumped 0.4.0 → 0.4.1** for the README lifecycle-note doc-sync (DL-038 snapshot-drift signal). Also corrects the `package-lock.json` `version` field left at 0.3.0 when v0.32.0 bumped `package.json` to 0.4.0 (`npm ci` doesn't validate the top-level version field).

### Changed (docs only)

- **Update runbook: reconcile out-of-repo copies after a pull (`CLAUDE_DEPLOYMENT.md`).** Explicit instructions for a Claude Code agent to reconcile the files copied/hand-derived out of `examples/` at install time — the session launcher and, depending on topology, the channel-server `.mjs` (loaded directly from the repo on single-agent hosts vs. snapshotted into a per-agent `*-coordination/OUTBOUND/<agent>/channel-setup/` dir on multi-agent hosts) — which `git pull` can't update and which never trip `bridge:check`. Includes the `~/.mcp.json` topology check, the `package.json` `version` drift signal (DL-038), and a `find` recipe so no per-agent copy is missed.
- **Corrected the v0.32.0 PHP 8.5 DL citation: DL-040, not DL-153.** The v0.32.0 release notes cited the *kanban* lockstep decision (DL-153) where the bridge's own decision is **DL-040** (the registry in `CLAUDE_DECISIONS.md`); fixed in `CLAUDE.md` + `docs/CHANGELOG.md`. The "kanban DL-153" cross-reference in DL-040's context line is correct and left intact.

## [0.32.0] - 2026-06-12

**Make a deaf/duplicate channel connector visible (DL-154) + addressing/contract polish (#2202) + PHP 8.5 standardization (DL-040).** PRs #119, #120, #121 since v0.31.0. No DB migration, no new `.env` keys.

### Added

- **Visible bind-failure marker + single-session guardrail + `bridge:check` liveness ping for deaf/duplicate channel sessions (FR #2444, DL-154).** When an agent runs two Claude Code sessions, the active one's channel connector can lose the socket-bind race (`EADDRINUSE` → `exit(2)`) — and because Claude Code swallows MCP-server startup stderr, that session came up **deaf to live-wake invisibly** while the bridge kept delivering `HTTP 202` to the other session's connector and logging `delivered`. Three composing guards now surface it (no cron, no daemon): (1) the reference connector writes a visible **`<socket>.FAILED` marker** (timestamp + pid + reason) on `EADDRINUSE` and the connector that *successfully* binds clears it — surfaced by `bridge:check` for the UDS transport; (2) **`start-channel-session.sh`** refuses to launch if a `claude … server:<channel>` process already holds the channel (a `pgrep` guardrail — the connector's refusal is the backstop) and clears a stale marker on start; (3) **`bridge:check`** adds an on-demand **socket liveness ping** (distinguishes a live, listening session from a stale socket) and reports any `.FAILED` marker. The reference channel server example is bumped to **0.4.0** (re-sync copied snapshots per DL-038).

### Changed

- **`RecipientAddressing::author()` returns the first `FROM:` token, not the verbatim tail (#2202).** A decorated/multi-name FROM line (`FROM: alice (pls review)`, `FROM: alice, bob`) used to return the whole tail verbatim, so a classifier doing `author($body) === $agentName` silently failed to match. It now tokenizes to the first whitespace/comma-delimited token (symmetric with `recipients()`), so both yield `alice`. Behavior change to a helper for operator classifiers; `author()` is new (v0.29.0 / DL-035) and no internal consumer depended on the verbatim tail. The kanban integration contract now also pins the by-ref `system` enum (`{dl, github_pr}`).
- **Standardize on PHP 8.5 (DL-040).** `composer.json` requires `^8.5`; CI runners pinned to 8.5 (the validated surface now matches the deployed runtime). An install on PHP <8.5 fails the `composer install` platform check — intentional. No app-logic change.

### Operator notes

- **No DB migration, no new `.env` keys.** Re-sync any copied channel-server snapshot to example `0.4.0` (DL-038). The launcher single-session guardrail also applies to a hand-rolled `~/start-claude.sh` (operational sync, outside the repo). PHP <8.5 installs must upgrade PHP before `composer install`.

## [0.31.0] - 2026-06-09

**Uid-agnostic `channel.socket` + loud uid-mismatch errors (DL-039).** PR #115 since v0.30.0. No DB migration, no new `.env` keys.

### Added

- **`${XDG_RUNTIME_DIR}` / `${uid}` placeholder expansion in `channel.socket` (DL-039).** An agent's `channel.socket` may now be written uid-agnostically — `${XDG_RUNTIME_DIR}/agent-webhook-bridge-channel-<name>.sock` — instead of pinning a literal `/run/user/<uid>/…`. Expanded at config-load (before validation): `${XDG_RUNTIME_DIR}` → `$XDG_RUNTIME_DIR`, or `/run/user/<uid>` when the env is unset (PHP-FPM usually doesn't inherit it, so the bridge derives it from the running uid); `${uid}` → the running uid. So restoring an install on a host where the OS uid changed just works — previously the literal path silently broke live-wake. An unrecognized/typo'd token fails closed at load. Mirrors the channel server's existing `$XDG_RUNTIME_DIR` derivation; the `0600` UDS trust model and the macOS/container explicit-path escape hatch are unchanged.

### Changed

- **`channel_push` no longer misdiagnoses a uid mismatch as a stopped server (DL-039).** A stale socket whose parent dir is gone now reports *"socket parent dir … does not exist — likely a uid mismatch after a host restore … repoint channel.socket or derive it with `${XDG_RUNTIME_DIR}`"* instead of the misleading *"start the channel server first"* (the server may be fine). The uid-restore wording is used only for the operator's agent socket, not a classifier-supplied one.
- **`bridge:check` now validates `channel.socket` reachability (DL-039).** Warns (doesn't fail — the socket is the channel server's to create) when a configured `channel.socket`'s parent dir is missing or non-writable, surfacing the uid mismatch at preflight instead of a silent runtime no-op.

### Operator notes

- **No DB migration, no new `.env` keys.** Optional adoption: on systemd Linux, rewrite `channel.socket` from `/run/user/<uid>/…` to `${XDG_RUNTIME_DIR}/…` so a future host/uid restore needs no edit. Existing literal paths keep working. The reference channel server example bumped to `0.3.0` (README guidance; copied snapshots should re-sync per DL-038).

## [0.30.0] - 2026-06-08

**Adversarial bug-hunt sweep (DL-037) + channel-server snapshot drift signal (DL-038).** PRs #108, #109 since v0.29.0. No DB migration. No app code in DL-038 (example + CI + docs).

### Security

- **`spawn_detached` resolves `setsid` to an absolute path (DL-037).** `setsid` was exec'd by bare name, so a classifier-payload `env.PATH` could redirect which `setsid` binary ran — sidestepping the `cmd[0]` absolute-path allowlist (the launcher execs `cmd`). Now resolved to an absolute path (`BRIDGE_SPAWN_SETSID_PATH`, auto-detected, fail-closed). Opt-in surface (`BRIDGE_SPAWN_ENABLED`), but a real allowlist bypass.

### Changed

- **Receiver rejects an over-length envelope field with a deterministic 400, not a 5xx (DL-037).** Only `delivery_id` length was asserted; an over-length `scope_id`/`event_type`/`actor_id` hit the DB column as a `QueryException` → 5xx → an upstream retry-storm of a deterministically-bad body. `assertFieldLengths` now guards every field written to `webhook_events` (fix the primitive). **The one receiver accept/reject change in this release** — realistic kanban/GitHub values are well under the column limits.
- **Same-event target coalescing keys on `(handler, debounceKey)`, not `debounceKey` alone (DL-037).** Two targets for one subject routed to different handlers (default `debounceKey` is the `targetId`) no longer collide last-wins and silently drop one. No shipped classifier triggers it; a custom-classifier footgun.
- **`KanbanClient::readBoard` scan stops on `links.next === null` per the documented board-read contract (DL-037).** Was a short-page heuristic while the contract specified `links.next`; the in-house consumer is now consistent with the rule the bridge wrote down (short-page fallback retained for a pre-DL-146 kanban that serves no `links`). Not a data-loss fix — contract alignment + no wasted extra request at an exact page multiple.

### Fixed

- **`bridge:replay --force` resets the whole terminal tuple (DL-037).** `--force` nulled only `processed_at`; a re-run exiting via a non-terminal path (durable/config throw → 5xx) left the prior `outcome`/`error_message` next to a now-null `processed_at` — the inconsistency DL-036 exists to prevent. Now nulls `outcome`/`reason`/`error_message` too.
- **`bridge:check` surfaces id-collisions to the operator console (DL-037).** A duplicate `kanban_user_id`/`github_user_id` (silent attribution bypass) was `Log::warning`-only despite a comment claiming preflight surfaces it; now rendered warn-level (exit unchanged).

### Added

- **Channel-server snapshot drift signal + CI bump-gate (DL-038).** Consumers copy `examples/channel-servers/` per deployment, so a snapshot drifts silently on a bridge update (DL-033's package.json was never bumped — it sat at `0.1.0` since the first commit). `package.json` `version` is now the drift signal (bumped to `0.2.0`); a PR-only `version-bump-guard` job in `channel-server-supply-chain.yml` **fails the build** when a shipped file under `examples/channel-servers/**` changes without a version bump. README § Staying in sync added.

### Operator notes

- **No DB migration.** New optional env **`BRIDGE_SPAWN_SETSID_PATH`** (absolute path to `setsid`; auto-detected when unset — most installs need nothing). The receiver now returns **400** (was 5xx) for a hostile/malformed over-length envelope field. Adopters of the reference channel server: compare your copy's `package.json` `version` against canonical to detect drift (a symlink never drifts).

## [0.29.0] - 2026-06-08

**Dispatch outcome ledger + operator-diagnostics polish — three peer-integrator FRs.** PRs #103, #104 since v0.28.0. ⚠ One non-destructive DB migration (FR-2).

### Added

- **Dispatch outcome ledger (DL-036, #104 / FR-2).** `agent_dispatches` gains a nullable `outcome` (`delivered` | `dropped` | `errored`) + `reason`, recorded at each terminal in `DispatchService`. A deliberate **gate-drop** (echo of the agent's own write / actor-not-a-signal / classifier-emitted-no-reactions) and a real **delivery** were previously byte-identical in the ledger (both `processed_at` set, `error_message` null), so `bridge:inspect` couldn't tell them apart and `bridge:replay` without `--force` silently no-op'd the gate-dropped rows it should re-run after a gate fix. `bridge:inspect` now shows the `outcome` + `reason / error`; `bridge:replay` (no `--force`) reports how many skipped rows were gate-DROPPED and that `--force` re-runs them. Each terminal write nulls the inapplicable satellite field, so a `--force` replay outcome *transition* can't leave a stale reason/error.
- **`RecipientAddressing::author()` — symmetric `FROM:` parser (DL-035, #103 / FR-3).** Mirrors `recipients()`: the first `FROM:` line of a comment body, lowercased + trimmed, or `null` (bare/empty `FROM:` is absent; `FROMAGE:` doesn't match). For custom classifiers routing shared-identity threads; recipient/author *policy* still lives in the operator's classifier (DL-022/DL-032).

### Changed

- **`bridge:check` 0-card writeback warning no longer asserts non-membership (DL-034, #103 / FR-1).** 0 cards on a 200 board read is ambiguous (empty board vs membership gap); the warning now presents **both** possibilities instead of claiming the token's user is "likely not a member." True inaccessibility is already caught separately by the `ref`-mode by-ref reachability probe (DL-031). Message-only; still warn-level, per mapped board.

### Documentation

- **Reply-direction footgun callout + role-reversal example in `docs/customization.md` (DL-035).** Route a comment by the comment's OWN `TO:`/`FROM:`, never the parent issue's frozen labels (those silently drop a reversed-direction reply); use labels only as the `null` fallback. The shared-identity echo example now dogfoods `author()` instead of a hand-rolled `preg_match`.

### Operator notes

- **⚠ Run `php artisan migrate`** — FR-2 adds nullable `outcome` + `reason` columns to `agent_dispatches`. Non-destructive, no backfill: pre-migration rows read `outcome=null` and every reader falls back to the legacy `processed_at`/`error_message` inference (`bridge:inspect` shows `done (pre-DL036)`). No config change.

## [0.28.0] - 2026-06-07

**Supply-chain + integration-docs hardening — no app code, no DB migration.** PRs #98, #99 since v0.27.0; both prompted by peer integrators (AIMLA PM).

### Added

- **Pinned dependency tree for `examples/channel-servers/` (DL-033, #99).** Commits a `package-lock.json` (lockfileVersion 3) for the reference channel MCP server — it reads a bearer token and accepts loopback POSTs as the agent's OS user, a real trust boundary — and switches the README + `start-channel-session.sh` launcher from `npm install` to **`npm ci`** (installs the exact pinned tree; fails on `package.json`/lock drift). **Node ≥ 20** is now stated up front. A cost-scoped CI gate (`channel-server-supply-chain.yml`) keeps the pin *watched*: `npm ci --ignore-scripts` (drift) + `npm audit --audit-level=high` (fails loud on a high/critical CVE), path-filtered to `examples/channel-servers/**` plus a weekly cron. Chose the audit-flag over dependabot auto-bumps for a single-dep example — loud-on-CVE + deliberate manual bump, lowest CI cost.

### Documentation

- **Canonical board-read pattern in the kanban↔consumer integration contract (#98).** Documents the scale-safe full-board read — **structure** from `GET /boards/{id}/preload.json`, the **card list** from paged `GET /tasks/search.json` (`board_id=N`, stop on `links.next`), **fail loud** on a non-200 mid-page (a partial read must never look like a shorter board) — so consumers stop re-deriving it per integration. Also corrects a misconception: the kanban board GET is **complete-but-heavy**, not silently truncated.

### Operator notes

- No DB migration, no config change. Adopters of the reference channel server should re-install with `npm ci` (the committed lockfile is now the source of truth); existing installs are unaffected.

## [0.27.0] - 2026-06-07

**Writeback correlation defaults to `ref` (DL-031) + comment-level recipient helper (DL-032).** PRs #92, #95 since v0.26.0. No DB migration.

### Changed

- **`BRIDGE_WRITEBACK_CORRELATION` now defaults to `ref`** (correction to DL-029's `scan` default). An undefined env uses the indexed `by-ref` lookup; set **`BRIDGE_WRITEBACK_CORRELATION=scan`** for backwards compatibility or a kanban that predates `by-ref`. Flipped across all three layers (config env default, `WritebackClientFactory` fallback, `KanbanClient` constructor default).

### Added

- **`bridge:check` by-ref reachability probe (DL-031).** Safety net for the `ref` default: in `ref` mode, `bridge:check` actively verifies the kanban exposes `by-ref` (`KanbanClient::byRefAvailable`) and warns loudly — naming a pre-DL-147 kanban *or* an inaccessible board — instead of letting every correlation 404 silently.
- **`RecipientAddressing` helper for comment-level `TO:` filtering (DL-032, #95 / #2173).** A reusable parser, `App\Bridge\Support\RecipientAddressing::addresses($commentBody, $agentName): ?bool`, for custom classifiers that filter channel pushes by a comment body's `TO:` line (so a multi-recipient thread doesn't wake every recipient on every comment). Three-state: `true` (names the agent or `all`), `false` (names others → drop), `null` (no/empty `TO:` line → caller falls back to issue/card labels). Case-insensitive; first `TO:` line wins. Recipient *policy* stays in the operator's classifier (DL-022) — this is just the shared parse; nothing wired into the runtime. New `docs/customization.md` § Comment-level recipient filtering recipe.

### Operator notes

- **⚠ Upgrading:** a `ref`-default bridge requires its kanban to be **v0.17.2+ and backfilled** (`php artisan kanban:backfill-external-references`). If yours isn't, set `BRIDGE_WRITEBACK_CORRELATION=scan` before/at upgrade. `bridge:check` will name the problem before traffic. No DB migration; no other config change.

## [0.26.0] - 2026-06-06

**Writeback correlation cutover to the kanban `by-ref` lookup + orphaned-mapping guard.** PRs #85, #86, #87, #88 since v0.25.0. No DB migration.

### Added

- **Indexed `by-ref` correlation + move-ALL-matching cards (DL-029, #87 / #2160).** Correlation now dispatches on **`BRIDGE_WRITEBACK_CORRELATION`** (default `scan`): `ref` does one indexed `GET /boards/{b}/tasks/by-ref.json` per key (kanban DL-147/148 — server-canonicalized, O(1), no paging/ceiling); `scan` is the existing board-scan fallback. A PR/DL is **one-to-many** (kanban DL-148), so both modes return **all** matching card ids — the classifier emits one `kanban_move_card` target per card (distinct `targetId`, no coalesce) and the dependabot handler moves every match. Default `scan` ⇒ upgrading the bridge is **inert**; flip an install to `ref` after its kanban is on v0.17.2+ and `task_external_references` is backfilled (`bridge:check` confirms). The blind/degraded-token probe is decoupled into a cheap `KanbanClient::visibility()` (`limit=1` read of the kanban DL-146 `meta.total`, row-count fallback for a pre-DL-146 kanban).
- **`bridge:check` flags an orphaned writeback mapping (DL-030, #88 / #2162).** A `writeback.json` mapping is inert unless some agent runs a writeback-emitting classifier subscribed to its github scope; `bridge:check` now warns when none does. Detection uses a marker interface `App\Bridge\Contracts\EmitsWritebackReactions` (implemented by `GitHubPrCardMoveClassifier`) checked **out of process** (`ClassifierResolver::probeImplements`, DL-025) and runs independently of the board-visibility probe.
- **Writeback correlation paging stopgap (DL-028, #85 / #2151).** The scan-mode board read pages past 200 cards (superseded as the primary path by DL-029's `ref` mode, retained as the `scan` fallback).

### Documentation

- **The kanban-board ↔ bridge integration contract (#86).** `docs/kanban-integration-contract.md` pins the seam (inbound webhook envelope/HMAC, outbound v3 surface, correlation keys, load-bearing invariants, change protocol); updated for `by-ref` correlation. `docs/customization.md` notes that a custom writeback-emitting classifier must implement the `EmitsWritebackReactions` marker.

### Operator notes

- **No DB migration, no required config change.** `BRIDGE_WRITEBACK_CORRELATION` is additive (absent ⇒ `scan`, today's behavior). To activate the indexed path on an install whose kanban is v0.17.2+ **and** backfilled: set `BRIDGE_WRITEBACK_CORRELATION=ref` and run `bridge:check`.

## [0.25.0] - 2026-06-06

**Per-mapping writeback swimlane + test/doc hardening.** One opt-in runtime addition (swimlane on created cards); the rest is test-hermeticity and operator docs. PRs #79, #80, #81 since v0.24.0. No DB migration.

### Added

- **Per-mapping `swimlane_id` for writeback-created cards (DL-027, #81 / #2148).** A writeback `mappings` entry may declare an optional `swimlane_id`; cards the bridge **creates** (today, the `create_dependabot_cards` path) land in that lane — the lane-per-repo-on-a-shared-board case. Applied at **create only, never on a move** (a move stays a column-only `workflow_stage_id` PATCH, so a human re-laning a card survives and a redelivery can't yank it back). Strict-numeric and fail-closed (a non-numeric value throws `ConfigException`, never silently drops to the default lane — the DL-026 posture); absent ⇒ the POST omits the key ⇒ byte-identical to prior behaviour. `bridge:check` validates a pinned lane against the board's actual swimlanes (via the lightweight `GET /boards/{id}/preload.json`) and **warns** (never fails) when it's missing. Opt-in and backward-compatible; existing `writeback.json` is unaffected. See `docs/writeback.md` § Optional: pin created cards to a swimlane.

### Fixed

- **Test suite is hermetic against an operator's ambient `BRIDGE_*` env (G-017, #79).** A shell with `BRIDGE_INBOX_LAYOUT=per-agent` (or `BRIDGE_STATE_DIR`) exported leaked into the suite and reproduced as ~26 failures on the operator's host while CI stayed green — `env()` reads the `getenv()` layer the shell export lives in, which a phpunit `<env force="true">` does **not** override. The base `TestCase` now pins these via a runtime `config()` call in `setUp`, so the suite resolves the same config regardless of the host shell.

### Documentation

- **Operator-update doc gaps closed (#80).** `CLAUDE_DEPLOYMENT.md` gains the custom-classifier migration step, a `bridge:check`-before-serving note, and a signed smoke-test recipe; `CLAUDE_GOTCHAS.md` adds G-018 (401 `scope_mismatch`); `docs/customization.md` + `docs/config-schema.md` cross-links and the writeback-token warning are clarified.

## [0.24.0] - 2026-06-06

**Release-automation robustness (sibling to kanban-board DL-143).** Release-tooling only — no change to the bridge runtime, receiver, or any app code. PR #75 since v0.23.0.

### Changed

- **`bin/promote-released-cards` correlates by PR-number in addition to DL (sibling to kanban-board DL-143).** A tracking card is promoted to "released" if its `payload.dl_number` matches a shipped `DL-NNN` **or** its `payload.pr_number` matches a shipped PR number (from each commit's trailing `(#NNN)`). Previously DL-only, so PR-only cards (bug/chore cards with no DL) were **silently** left in "shipped-to-dev" each release — observed live on v0.23.0 (five PR-only cards + one missed-DL card sat un-promoted until reconciled by hand). Also: the script now **pages the whole board** (no silent 200-card truncation), **refuses an empty base range** (no full-history sweep), warns on an unstamped shipped DL, and numeric-validates the board/stage config. Same "a degraded-but-not-erroring read must be loud" rule as the writeback (DL-026). The script is shared byte-identical with kanban-board.

## [0.23.0] - 2026-06-06

**BREAKING classifier-interface change + writeback robustness + opt-in dependabot cards.** PRs #60, #61, #66–#71 since v0.22.0.

### Added

- **Dependabot cards, opt-in per repo (DL-024, #66/#67).** Set `create_dependabot_cards: true` on a writeback mapping and a dependabot PR (head `dependabot/*`, no `DL-NNN`) gets a card **created on open** and carried through the same lifecycle on close — correlated by **PR number** (no DL needed). New cards are tagged `dependencies`/`triaged` and carry `payload.pr_number`/`pr_url`/`origin`. Builds on the existing writeback setup; default `false` (no behaviour change). See `docs/writeback.md` § Optional: dependabot cards.

### Changed

- **BREAKING — `Classifier::classify()` now takes a single `ClassifyContext $ctx` (DL-025, #70).** Replaces the prior positional parameter list (`eventType`/`payload`/`actor`/`provider`/`scopeId`/`agent`) with one readonly DTO. **Adding future context is now non-breaking — this is the LAST breaking change to `classify()`.** Every custom classifier must migrate to `classify(ClassifyContext $ctx): ClassifyResult` (read inputs from `$ctx->*`, thread `$ctx` through any `parent::classify()`). Also adds an **out-of-process `bridge:check` pre-flight** that loads each classifier in a child php process, so an incompatible-signature `E_COMPILE_ERROR` surfaces as a named check failure instead of crashing the command/request. The 3 in-tree classifiers + `docs/customization.md` are updated.

### Fixed

- **Writeback fails loudly on a blind/degraded token + page-cap truncation (DL-026, #71).** A writeback token that returns **0 cards** (its user lost board membership, or a wrong `board_id`/instance — kanban answers `200` + empty data, so no HTTP error) no longer **silently no-ops every move** (or, for `create_dependabot_cards` mappings, **creates a duplicate card** each redelivery). A runtime `warning` on the shared board read **and** a `bridge:check` board-visibility probe surface it; a read hitting the **200-card cap** is warned too. Non-transient (never a 5xx retry-storm); a genuine no-match stays quiet.
- **Durable write-or-throw + boot-safe replay (#69 / #2055, #2054).** The durable-reaction write path propagates failures (write-or-throw) so a lost write becomes a retryable 5xx rather than a silent drop; `bridge:replay` is hardened to run boot-safe.
- **Backlog hygiene (#68 / #2057, #2056, #2058).** Stored exception text is redacted, DB errors are surfaced cleanly, and the 413 envelope-size-limit response is documented.

### Dependencies

- Bump `gitleaks/gitleaks-action` 2.3.9 → 3.0.0 (#60).
- Bump `laravel/pao` (dev) 1.0.6 → 1.1.0 (#61).

### Operator notes

- **BREAKING — migrate custom classifiers** to the `ClassifyContext` signature before updating (in-tree usage is already migrated). After updating, run **`php artisan bridge:check`** — it now validates each classifier's signature out-of-process and names a stale one instead of fataling. **No DB migration.**
- `bridge:check` now also **probes that the writeback token can see each mapped board** (0 cards / 200-cap ⇒ a loud warning, never a check failure). Opt-in posture unchanged: no `writeback.json` ⇒ writeback off.

## [0.22.0] - 2026-06-05

**Release card-promotion for board 8 + the auto-tag workflow now publishes a GitHub Release.** PRs #59, #62 since v0.21.0.

### Added

- **Release card-promotion to "released" for board 8 (DL-023, #62).** On merge to `main`, a new isolated workflow (`release-promote-cards.yml`) derives the shipped `DL-NNN` set deterministically from `git log <prev-tag>..HEAD` and moves each tracking card to the "released to main" stage (53) via `bin/promote-released-cards` — a generic, framework-free script (bash + curl + jq) shared with kanban-board. Closes the gap where a bundled release PR carries no single DL token, so the bridge's own webhook writeback couldn't advance these cards. Idempotent, best-effort per card, with a loud empty-board guard (refuses if the token can't see the board). Per-repo config in `.release-pr.json` `.promote`.

### Changed

- **The auto-tag workflow also publishes a GitHub Release (#59).** On merge to `main` it now creates a GitHub Release from the version's `docs/CHANGELOG.md` section, not just the tag — so the repo's Releases page matches the tags.

### Operator notes

- **Board-8 promotion requires a `KANBAN_WRITEBACK_TOKEN` Actions secret** whose kanban user is a **member of board 8**; without it the step refuses loudly (never a silent no-op). No migration, no new runtime env keys. The promote job needs no PHP/composer (`jq`/`curl`/`git` only).

## [0.21.0] - 2026-06-01

### Changed

- **BREAKING — `Classifier::classify()` gains a required final `AgentConfig $agent` parameter (DL-022).** The dispatcher already invokes `classify()` once per subscribed agent; now it passes that serving agent, so a classifier can make **per-agent (recipient-aware)** decisions — e.g. drop an event not addressed to the serving agent (keyed on `$agent->agentName` / `$agent->identity`). **Every custom classifier must add the parameter** to its `classify()` signature (and thread it through any `parent::classify()` call) or it fatals on load (`Declaration … must be compatible`) — a default cannot avoid the break (PHP rejects a narrower implementor signature). The three in-tree classifiers + all docs are updated. Keeps recipient-addressing *policy* in the operator's classifier rather than the bridge core (option 1 over a dispatcher-side label filter). See `docs/customization.md` § Per-agent (recipient-aware) classification.

## [0.20.0] - 2026-06-01

**The GitHub-PR → kanban card-move writeback (FR #2016) — the bridge's first writeback, otherwise still surface-only/one-way.** Opt-in; **off by default** (absent `writeback.json` ⇒ no-op, no behaviour change).

### Added

- **The card-move writeback (DL-009 design → DL-018/019/020/021).** A GitHub `pull_request` webhook deterministically moves a kanban card to a stage — no agent in the loop:
  - **`DurableReaction` contract + durable-first dispatch (DL-018).** A handler whose side effect must not be silently dropped runs before the best-effort handlers, and its failure propagates (→ 5xx → redelivery) instead of becoming a note. Plus a global-echo seam (`BRIDGE_GLOBAL_ECHO_IDS`) so the bridge's own machine writes never loop back.
  - **`writeback.json` policy + `KanbanClient` + a dedicated least-privilege writeback token (DL-019).** Per-install repo→board+stage mapping in the config dir (not tracked config); the move authenticates with a `0600` `writeback-token` distinct from the broad provisioning token.
  - **`KanbanMoveCardHandler` (DL-020)** — durable, **idempotent** (no-op if already in stage), with a **belongs-to-mapped-board** security guard and a transient-vs-permanent failure split (a kanban 5xx retries; a 4xx / refusal / malformed payload logs + no-ops, never 5xx-storms).
  - **`GitHubPrCardMoveClassifier` (DL-021)** — derives the move outcome from **GitHub-controlled fields only** (`action` + `pull_request.merged` + `base.ref`, never the title) and correlates the card by the `DL-NNN` token in the PR title/branch.
  - **`docs/writeback.md`** — the operator runbook (token, `writeback.json`, the classifier agent, the repo webhook).
- `bridge:check` validates `writeback.json` + the writeback token. New: `BRIDGE_GLOBAL_ECHO_IDS` env; `<config_dir>/writeback.json`; `<secret_dir>/<provider>/writeback-token`.

### Operator notes

- **Writeback is OFF until configured** — no migration, no change for existing installs. To enable, see `docs/writeback.md`: place `writeback.json` + a least-privilege token (whose kanban user is a member of the mapped board), run a github-subscribed agent with `classifier.class: …\GitHubPrCardMoveClassifier`, and add the repo webhook. **First outward-facing write — a real-install soak is recommended before relying on it.**

### Verification

- PHPUnit **310/310** (SQLite + MariaDB 10.6/11) · Pint clean · PHPStan level 7 0 errors · doc-refs + `composer audit --locked` green. Every phase passed an adversarial security review (ground-truthed against the kanban-board source) before merge.

## [0.19.0] - 2026-05-31

**The architecture-review hardening tail (B-13…B-19 + the B-9/B-10 partials) — security tightenings, CI gates, a config reference, and cleanups.** No migration; the only operator-facing change is one new opt-in env (see Security).

### Security

- **DL-014.** Three fail-closed tightenings: `ProviderName`/`ScopeId` gain the `D` regex anchor (a trailing-newline can't slip a second line past `$`); a **classifier-supplied** `channel_push` socket must sit under `BRIDGE_CHANNEL_ALLOWED_SOCKET_DIR` (refused when unset — an agent's own `channel.socket` is exempt); and `bridge:check` warns when the config / secret dir is group/world-accessible. ⚠ A custom classifier that hand-emits a `channel_push` with its own `socket:` now needs `BRIDGE_CHANNEL_ALLOWED_SOCKET_DIR` set — the no-classifier `route_intents` path and the reference installs are unaffected. (#39)

### Added

- **DL-015.** `bridge:check` fails if a configured provider has no adapter (`config.providers ⊆ WebhookAdapterFactory::SUPPORTED`); a `composer audit --locked` CI job (every PR + nightly) reds the build on a known dependency CVE. (#40)
- **`docs/config-schema.md` (B-11)** — a current-state config reference: every per-agent YAML key + every `BRIDGE_*` env, with type, default, and fail-closed-vs-warn behaviour. The *what*, to the decision log's *why*. (#43)

### Changed

- **DL-017.** `AgentConfig`'s identity triple (`kanban_user_id`/`github_user_id`/`github_login`) is grouped into an `IdentityConfig` DTO (the DTO idiom of `EchoSuppressionConfig`/`ChannelConfig`); the constructor drops 11→9 args. No runtime change. (#42)
- **DL-016.** One `BridgePaths::ensureDir` (0700) replaces four inline `mkdir` sites so the mode can't drift; `CheckCommand` extends `BridgeCommand` (completing the base-class consolidation); trimmed dead Python-provenance docstrings; documented the deliberate no-`TrustProxies` posture. No runtime change. (#41)

### Docs

- Architecture review marked up end-to-end (every B-item ✅ Addressed / ⚠ Partial / Declined) with a **Deferred / declined (with justification)** section for the speculative/churn items not taken (B-7, B-8, B-12, B-20, and the B-9 file-splits / B-10 validator-extraction). (#44, #42)

### Verification

- PHPUnit **272/272** (SQLite + MariaDB 10.6/11) · Pint clean · PHPStan level 7 0 errors · doc-refs guard + `composer audit --locked` green. Every item passed an adversarial review loop before merge.

## [0.18.0] - 2026-05-31

**The architecture-review hardening pass (B-1…B-6) + config-level channel auth.** ⚠ **Breaking — fail-closed tightening on secrets + `spawn_detached`, and a DB migration; see Security/Changed below.**

### Added

- **Config-level channel auth — `channel.auth.token_path` (DL-008).** The no-code `route_intents` push can now carry an `Authorization: Bearer <token>` (a file path, chmod 600, enforced + read at point-of-use, never placed in a payload) so a cross-user / loopback-TCP channel server is authenticated — not just a 0600-UDS whose filesystem perms were the only boundary. Rejected at config load unless `channel.url` is set (the token surface stays on the TCP transport). (#27)
- **`bridge:prune` retention command (DL-012).** `--older-than=Nd` deletes old `webhook_events` (cascading `agent_dispatches`) and trims old inbox lines + bounds their seen-cursor; `--null-payloads-older-than=Md` sheds 50–100 KB payload bodies past the replay window while keeping the row's dedup-gate + audit metadata; `--dry-run`. The one (optional) periodic job in the otherwise daemonless design — nothing on the dispatch path depends on it. (#32)
- **Doc-sync CI guard — `bin/check-doc-refs.php` (DL-013).** A CI step that fails the build if a `CLAUDE_*.md` doc names a PHP file path / `App\` FQCN that no longer exists (with a `(removed in …)` / `~~strikethrough~~` escape hatch). Converts the soft "doc-sync in every PR" rule into an enforced one. (#34)
- **DL-009 — durable-reaction + writeback-authz seam designed (design-only, no runtime change).** The typed contract a future GitHub-PR→card-move writeback builds against: a durable-reaction handler class (failure → 5xx/retry, not a swallowed note), a dedicated least-privilege writeback token, operator-config-only repo→board mapping, and global echo-suppression of the writeback identity. (#29)

### Security

- ⚠ **Unified 0600 secret-perms enforcement across every secret reader (DL-010).** DL-008's SSH-style `mode & 0o077` gate now also covers the two higher-value secrets — the per-`(provider, scope)` HMAC secret and the kanban API token — plus the provisioner's reconcile re-read, via a shared `SecretFile` (live-perms, fail-closed). **A group/world-readable HMAC secret now returns `500 secret_perms_insecure` (kanban-board holds + redelivers); a readable API token fails `bridge:provision`.** Safe direction — the provisioner already writes `0600`, so a correctly-provisioned install is unaffected; `bridge:check` warns on all three at preflight (G-016). (#30)
- ⚠ **`spawn_detached` is opt-in + executable-allowlisted + shell-free (DL-011).** The highest-blast-radius handler is **no longer registered unless `BRIDGE_SPAWN_ENABLED=true`**, and the program (`cmd[0]`) **must be in `BRIDGE_SPAWN_ALLOWLIST`** (absolute paths). Execution moved from an `exec()` shell string to `proc_open` with an argv array + `setsid -f` — no `/bin/sh`, so no shell-metacharacter surface. Allowlist fixed-purpose wrapper scripts, never an interpreter (`php`, `bash`, `git`, …), which would reopen RCE via `cmd[1..]`. (#31)

### Changed

- ⚠ **Inbox dedup moved off the synchronous hot path + `webhook_events.payload` is now nullable (DL-012).** `IntentLog` no longer scans the whole inbox file per intent (an O(file) cost that grew on calendar time and inflated webhook latency); idempotency is the upstream `agent_dispatches.processed_at` gate plus a read-side id-collapse in `bridge:inbox`. **Run `php artisan migrate` on deploy** (the payload-nullable migration). `BridgePaths::jsonlContainsId` removed (dead). (#32)

### Removed

- **Dead `ChannelName` validator deleted (B-6).** `channel.name` was removed in DL-007 but `app/Bridge/Validation/ChannelName.php` survived — referenced nowhere in app code, kept green only by its own tests, so it looked load-bearing. Deleted with its tests + the four `CLAUDE_*` doc references. (#33)

### Docs

- **Architecture review** (`docs/reviews/2026-05-31-architecture-review.md`) across scalability / maintainability / security, with every backlog item (B-1…B-6) now marked addressed. (#28)
- **Doc drift fixed (DL-013 / B-5):** the `CLAUDE_*.md` onboarding map no longer describes the deleted `ProviderApiConfig` or the removed `agents.json` / `identity.self` as current; `CLAUDE_GOTCHAS.md` G-015 rewritten to the post-DL-007 reality. (#34)

### Verification

- PHPUnit **262/262** (SQLite + MariaDB 10.6/11) · Pint clean · PHPStan level 7 (`app/Bridge`) 0 errors · doc-refs guard green. Each item passed an adversarial review loop before merge.

## [0.17.0] - 2026-05-30

**Install-easing docs + a CI fix, surfaced while wiring live channel push on a real install.**

### Changed

- ⚠ **Channel-server example renamed** `examples/channel-servers/kanban-bridge-channel.mjs` → `agent-webhook-bridge-channel.mjs`, to match `package.json` (`bin`/`start`), the README, and `.mcp.json.example` — all of which already referenced the new name — plus the bound socket prefix and the project name. **Breaking for anyone whose `.mcp.json` points at the old filename:** update the `args` path to `agent-webhook-bridge-channel.mjs`, or the channel server won't launch (and `npm start` was already broken before the rename). (#22)

### Fixed

- **CI: docs-only PRs are no longer permanently blocked.** The three Laravel Tests jobs are required status checks but were `paths-ignore`'d on docs/examples PRs, so on those PRs the required check never reported and the PR was un-mergeable without an admin override. Removed the filter so the (fast) checks always run + report on every PR. (#23)

### Docs

- Cross-install peer-YAML note (an agent naming a peer in `treat_as_signal`/`treat_as_echo` that runs in a *separate* install needs a local author-only `<peer>.yml`, since the v2 registry is per-install); an explicit "channels are CLI-only — no config auto-load" statement + a launcher script (`examples/start-channel-session.sh`); an "Upgrading to v0.16 (config schema v2)" checklist; an FPM-reload-needs-sudo note. (#22)

### Verification

- PHPUnit **229/229** (SQLite + MariaDB 10.6/11) · Pint clean · PHPStan level 7 (`app/Bridge`) 0 errors.

## [0.16.0] - 2026-05-30

**Per-agent inbox surfacing for a single multi-agent install, then a config-schema cleanup that kills the duplication it exposed.** ⚠ **Breaking — operators must migrate their per-agent config (see below).**

### Added

- **Per-agent inbox surfacing (DL-006).** A single install fanning out to N agents can now give each agent a clean view. Every staged inbox line carries the serving `agent`; `BRIDGE_INBOX_LAYOUT=shared|per-agent|both` (default `shared`); `bridge:inbox --agent <name>` reads that agent's file (or the shared file filtered by tag) with an isolated per-agent seen cursor; `BRIDGE_DEFAULT_AGENT` for a bare invocation. Cross-user read via a group convention (`BRIDGE_STATE_DIR` outside the secret dir + `BRIDGE_INBOX_GROUP`/`BRIDGE_INBOX_FILE_MODE`, requires `per-agent` layout). `channel.route_intents` (+ `channel.url`) routes each staged intent to an agent's channel without a hand-coded `channel_push`. `--agent` added to `bridge:stats` / `bridge:inspect`. (#16)
- **`bridge:inbox` cursor-advance reliability fix (DL-006).** Advances the seen cursor only when output can reach a consumer — wiring it on a `Stop`/`Notification` hook no longer silently eats intents. `--no-cursor-advance` for a non-advancing peek. (#16)

### Changed

- ⚠ **BREAKING — config schema v2 (DL-007).** Kills config duplication the surfacing work exposed.
  - The `<agent>.yml` **filename is the agent name** — `identity.self` removed.
  - Per-**install** settings moved to `.env`/`config/bridge.php`: `BRIDGE_RECEIVER_BASE_URL` and provider API base URLs (`BRIDGE_KANBAN_API_BASE_URL`); the per-agent YAML keeps only an optional `api.<provider>.token_path` override.
  - Per-agent identity folded into the YAML (`identity: {kanban_user_id, github_user_id, github_login}`); the registry is built by scanning the YAMLs. **`agents.json` → optional `shared-identities.json`** (shared accounts only).
  - **`BRIDGE_DIR`** collapses `BRIDGE_CONFIG_DIR`+`BRIDGE_SECRET_DIR` (both still overridable). API **token by convention** `<secret_dir>/<provider>/token` (per-agent override allowed). **`channel.name` removed** (dead field).
  - An agent's own echo-suppression ids are **auto-seeded** from its `identity` (`echo_suppression: {}` is the common case). Fail-closed: a malformed YAML 5xx's; an unknown `treat_as_signal` name throws. `bridge:check` validates the whole config surface (classifier FQCN, endpoint URLs, token/secret presence, signal names, default agent) with actionable messages.

  **Migration:** move ids into each `<agent>.yml`'s `identity` block; drop `identity.self` / `receiver` / `api.<provider>.base_url` / `channel.name`; set `BRIDGE_DIR` + `BRIDGE_RECEIVER_BASE_URL` + `BRIDGE_KANBAN_API_BASE_URL`; move the API token to `<secret_dir>/<provider>/token`; rename `agents.json` → `shared-identities.json` keeping only the `shared_identities` block. See `CLAUDE_DECISIONS.md` DL-007 and the rewritten `examples/sample-config/*`.

### Internal

- A bad `classifier.class` FQCN is locked as treatment-A (record + ack 200, not a 5xx) by a regression test, and surfaced early by `bridge:check`. (#17)
- Consolidation: one canonical JSONL reader (`BridgePaths::readJsonl`/`jsonlContainsId`/`agentInboxLines`); new `UrlValidator` + `TokenPath` + a `BridgeCommand` base (`strOption`); `ProviderApiConfig` removed. (#16, #18)

### Verification

- PHPUnit **229/229** (SQLite + MariaDB 10.6/11) · Pint clean · PHPStan level 7 (`app/Bridge`) 0 errors.

## [0.15.0] - 2026-05-30

**Custom-handler registration now works as documented, and per-agent echo suppression is restored for a shared upstream identity.** Both reported by a peer integrator.

### Added

- **Per-agent echo suppression for a *shared* upstream identity (DL-005).** New optional `ClassifyResult::$reattributedActor`. When several agents share one upstream account (`shared_identities`), the registry resolves `Actor.name = null` on purpose, so the pre-classify echo gate can only match the raw id (`treat_as_echo_ids`) — all-or-nothing across every agent. A classifier that recovers the true author (FROM:-line / repo-scope) now returns it on the result; **after** classify, the dispatcher re-runs the **same** per-agent echo check on it, suppressing only the serving agent's own write while a different shared-id agent's write still surfaces. The classifier reports *who* authored the event; the dispatcher decides *is that me?* per agent — so the `Classifier` contract and the "classifiers don't filter" invariant are both unchanged, and `null` (every shipped classifier) is a no-op. Completes the `shared_identities` design (DL-002). (#12)

### Fixed

- **The documented custom-handler extension point is functional again (DL-004).** `HandlerRegistry` is now bound as a container **singleton** in `BridgeServiceProvider`, and `DispatchService` resolves it from the container instead of constructing its own. So `afterResolving(HandlerRegistry::class, fn ($r) => $r->register('x', new XHandler))` in a `ServiceProvider` — the path `docs/customization.md` always advertised — registers onto the **exact** instance the dispatcher uses, with no provider-ordering requirement. Previously the only working path was re-binding `DispatchService` wholesale and duplicating its constructor wiring (fragile across upgrades). (#11)

### Verification

- PHPUnit **205/205** (SQLite + MariaDB 10.6/11) · Pint clean · PHPStan level 7 (`app/Bridge`) 0 errors.

## [0.14.0] - 2026-05-30

**Same-event ReactionTarget coalescing restored (DL-003), plus a post-v0.13 divergent-duplication cleanup.**

### Changed

- **Same-event ReactionTarget coalescing by `debounce_key` is enforced again (DL-003).** Within one `ClassifyResult`, targets sharing a `debounce_key` collapse last-wins at dispatch time, so a classifier emitting several targets to one bucket fires that handler once. The v0.12 rewrite kept the field, docblocks, schema, and handler-log key but dropped the implementation; this restores it. Built-in classifiers emit ≤1 target so are unaffected — the fix protects the custom-classifier extension point. **`debounce_seconds` is advisory metadata only** (carried to the handler/handler-log); the synchronous model does not enforce a cross-delivery time window — redelivery dedup is the `webhook_events` `UNIQUE(delivery_id)` gate + upstream retry. (#7)
- **Docs/comments de-staled after the v0.12 rewrite.** Removed references to the deleted Python tree (`lib/*.py`, `receiver/webhook.php`, `examples/classifiers/*.py`, `DebounceTracker`, drain-pass, `frozenset`, `payload_dict()`) from validator/middleware/classifier/provision docblocks and the `event-schema.json` / `reaction-target-schema.json` docs; corrected `event-schema.json`'s actor.id (GitHub is `sender.id` since v0.13.0, not `sender.login`). Reworded dangling `DL-074` citations (the repo's decision log holds DL-001…DL-003). (#5)

### Internal

- **CI: shared PHP setup extracted into a local composite action** (`.github/actions/setup-app`) so the SQLite and MariaDB test jobs can't drift; dropped the dead scaffold-era PHPStan guard. No change to test coverage. (#6)

### Verification

- PHPUnit **200/200** (SQLite + MariaDB 10.6/11) · Pint clean · PHPStan level 7 (`app/Bridge`) 0 errors.

## [0.13.0] - 2026-05-30

**Agent recognition keys on the immutable GitHub account id, not the renameable username (DL-002).** A username rename is now a non-event.

### Changed

- **GitHub actor identity is now `sender.id` (immutable numeric), not `sender.login`.** `GitHubAdapter` extracts the numeric account id into `actor_id`; `AgentRegistry::actorFromEvent($provider, …)` is **provider-aware** (kanban events match `kanban_user_id`, GitHub events match `github_user_id`), so the same integer on different axes never cross-matches. Keying on the immutable id means a GitHub username rename no longer breaks recognition or echo-suppression. (DL-002, #1)
- **`agents.json` → `schema_version: 2`.** Per-agent identity is `kanban_user_id` + `github_user_id` (both immutable ints). A GitHub account shared by multiple agents is declared **once** under a top-level `shared_identities[]` block (`{github_user_id, github_login?, agents[]}`) → resolves to `Actor.name = null` (custom-classifier re-attribution, preserving the shared-login collision-bypass behavior byte-for-byte). `github_login` is now a **display-only label** with a one-line stale-login drift warning.

### Breaking

- **`agents.json` must be migrated to `schema_version: 2`.** A v1 file is not parsed — `AgentRegistry::load` warns with a migration note and degrades to an empty registry. Replace any `github_login` matching key with the immutable `github_user_id`; declare shared accounts under `shared_identities`. Kanban-only registries migrate by bumping the version number alone. No in-code compatibility shim (single-operator project).

### Verification

- PHPUnit **199/199** (SQLite + MariaDB 10.6/11 matrix) · Pint clean · PHPStan level 7 (`app/Bridge`) 0 errors · two adversarial review passes, zero must-fix.

## [0.12.0] - 2026-05-29

**The Laravel rewrite — a single synchronous app, shipped as a fresh repository.** See **DL-001** for the full rationale.

### Changed

- **Architecture: one Laravel 13 app, synchronous in-request dispatch.** The v0.1–v0.11 design was a five-layer asynchronous pipeline (PHP HTTP receiver → MariaDB event queue → Python consumer drained by a per-minute cron → classifier → inbox surfacing). v0.12 collapses that into a single Laravel app: a webhook is HMAC-verified in middleware, the adapter parses the envelope, and `DispatchService` runs classify → stage to `inbox.jsonl` → run handlers **synchronously in the same request**, returning `200` only when every subscribed agent is processed. **No queue worker, no consumer cron, no daemon.**
- **At-least-once is borrowed, not built.** Any internal/durability failure throws → Laravel returns `5xx` → kanban-board's webhook retry redelivers; `inbox.jsonl` is the durable pull-backstop. The three-way failure treatment (classify-throws → record + `200`; inbox-staging-throws → `5xx`; handler-throws → per-agent done-with-note) lives in `DispatchService` / `WebhookController`.
- **Stack:** PHP 8.3 / Laravel 13 / Eloquent over MariaDB 10.6+ (SQLite for tests). The Python tree (`lib/`, `bin/`, the pytest suite) and the standalone PHP receiver are gone; the per-agent YAML loader, adapters, classifiers, handlers, and HMAC verification are ported to `app/Bridge/*`. CLIs are now `php artisan bridge:*` (`check`, `provision`, `inbox`, `inspect`, `replay`, `stats`).
- **Config:** the DB connection and HMAC-secret directory move to Laravel's `.env` / `config/bridge.php` (one install per agent); per-agent YAML keeps `identity` / `api` / `receiver` / `subscriptions` / `echo_suppression` / optional `classifier.class` (FQCN) / `channel` / `surface`. Migrated v0.11 YAMLs load unchanged (leftover `db` / `secrets` keys are tolerated and ignored).

### Verification

- Pint clean · PHPStan level 7 (`app/Bridge`) 0 errors · PHPUnit **188/188** (SQLite + MariaDB 10.6/11 matrix in CI).
