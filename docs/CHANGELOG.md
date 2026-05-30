# Changelog

All notable changes to the agent-webhook-bridge are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The changelog is **release-event only** — entries land in the release-tag commit, not in feature PRs. See [`../VERSIONING.md`](../VERSIONING.md) for the full policy.

> This repository's git history begins at **v0.12.0**. The bridge existed earlier (v0.1–v0.11, a Python-consumer + PHP-receiver implementation), but that history was not carried into this repository. The design rationale that is still load-bearing for the current code is preserved in [`../CLAUDE_DECISIONS.md`](../CLAUDE_DECISIONS.md); v0.12.0 itself is recorded in **DL-001**.

## [Unreleased]

_(empty after each tagged release; accumulates as feature PRs land on dev)_

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
