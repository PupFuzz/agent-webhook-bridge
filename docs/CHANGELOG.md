# Changelog

All notable changes to the agent-webhook-bridge are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The changelog is **release-event only** — entries land in the release-tag commit, not in feature PRs. See [`../VERSIONING.md`](../VERSIONING.md) for the full policy.

> This repository's git history begins at **v0.12.0**. The bridge existed earlier (v0.1–v0.11, a Python-consumer + PHP-receiver implementation), but that history was not carried into this repository. The design rationale that is still load-bearing for the current code is preserved in [`../CLAUDE_DECISIONS.md`](../CLAUDE_DECISIONS.md); v0.12.0 itself is recorded in **DL-001**.

## [Unreleased]

_(empty after each tagged release; accumulates as feature PRs land on dev)_

## [0.12.0] - 2026-05-29

**The Laravel rewrite — a single synchronous app, shipped as a fresh repository.** See **DL-001** for the full rationale.

### Changed

- **Architecture: one Laravel 13 app, synchronous in-request dispatch.** The v0.1–v0.11 design was a five-layer asynchronous pipeline (PHP HTTP receiver → MariaDB event queue → Python consumer drained by a per-minute cron → classifier → inbox surfacing). v0.12 collapses that into a single Laravel app: a webhook is HMAC-verified in middleware, the adapter parses the envelope, and `DispatchService` runs classify → stage to `inbox.jsonl` → run handlers **synchronously in the same request**, returning `200` only when every subscribed agent is processed. **No queue worker, no consumer cron, no daemon.**
- **At-least-once is borrowed, not built.** Any internal/durability failure throws → Laravel returns `5xx` → kanban-board's webhook retry redelivers; `inbox.jsonl` is the durable pull-backstop. The three-way failure treatment (classify-throws → record + `200`; inbox-staging-throws → `5xx`; handler-throws → per-agent done-with-note) lives in `DispatchService` / `WebhookController`.
- **Stack:** PHP 8.3 / Laravel 13 / Eloquent over MariaDB 10.6+ (SQLite for tests). The Python tree (`lib/`, `bin/`, the pytest suite) and the standalone PHP receiver are gone; the per-agent YAML loader, adapters, classifiers, handlers, and HMAC verification are ported to `app/Bridge/*`. CLIs are now `php artisan bridge:*` (`check`, `provision`, `inbox`, `inspect`, `replay`, `stats`).
- **Config:** the DB connection and HMAC-secret directory move to Laravel's `.env` / `config/bridge.php` (one install per agent); per-agent YAML keeps `identity` / `api` / `receiver` / `subscriptions` / `echo_suppression` / optional `classifier.class` (FQCN) / `channel` / `surface`. Migrated v0.11 YAMLs load unchanged (leftover `db` / `secrets` keys are tolerated and ignored).

### Verification

- Pint clean · PHPStan level 7 (`app/Bridge`) 0 errors · PHPUnit **188/188** (SQLite + MariaDB 10.6/11 matrix in CI).
