# Changelog

All notable changes to the agent-webhook-bridge are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The changelog is **release-event only** — entries land in the release-tag commit, not in feature PRs. See [`../VERSIONING.md`](../VERSIONING.md) for the full policy.

> This repository's git history begins at **v0.12.0**. The bridge existed earlier (v0.1–v0.11, a Python-consumer + PHP-receiver implementation), but that history was not carried into this repository. The design rationale that is still load-bearing for the current code is preserved in [`../CLAUDE_DECISIONS.md`](../CLAUDE_DECISIONS.md); v0.12.0 itself is recorded in **DL-001**.

## [Unreleased]

_(empty after each tagged release; accumulates as feature PRs land on dev)_

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
