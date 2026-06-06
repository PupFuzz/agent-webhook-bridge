# Changelog

All notable changes to the agent-webhook-bridge are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The changelog is **release-event only** — entries land in the release-tag commit, not in feature PRs. See [`../VERSIONING.md`](../VERSIONING.md) for the full policy.

> This repository's git history begins at **v0.12.0**. The bridge existed earlier (v0.1–v0.11, a Python-consumer + PHP-receiver implementation), but that history was not carried into this repository. The design rationale that is still load-bearing for the current code is preserved in [`../CLAUDE_DECISIONS.md`](../CLAUDE_DECISIONS.md); v0.12.0 itself is recorded in **DL-001**.

## [Unreleased]

_(empty after each tagged release; accumulates as feature PRs land on dev)_

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
