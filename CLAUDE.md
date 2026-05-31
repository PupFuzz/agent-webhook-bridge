# agent-webhook-bridge

A webhook receiver, classifier framework, and CLI for AI agents (or operators) integrating with the [kanban-board](https://github.com/your-org/kanban-board) app — and any other webhook-emitting upstream following the same shape. **A single Laravel app** (Laravel 13, PHP 8.3, MariaDB 10.6+ / SQLite for tests) on Apache + PHP-FPM. The receiver verifies the webhook, then classifies → stages to the inbox → runs handlers **synchronously in the same request**, returning 200 only when done — **no queue, no consumer cron, no daemon**. At-least-once delivery is borrowed from kanban-board's webhook retry (any internal failure → 5xx → re-delivered) plus `inbox.jsonl` as the durable pull-backstop. See [`CLAUDE_ARCHITECTURE.md`](CLAUDE_ARCHITECTURE.md) + [`CLAUDE_DECISIONS.md`](CLAUDE_DECISIONS.md) DL-001. (v0.12 rewrote the v0.11.x PHP-receiver + Python-consumer split into this single runtime.)

> **If you're a future session of Claude (or any AI agent) opening this repo for the first time, this file is your map.** Read this index → jump to the relevant subfile per the "When to read" table below. The subfiles contain the load-bearing detail; this file should stay short.

## Quick-start commands

```bash
# from your install/checkout (typically ~/agent-webhook-bridge-dev for dev,
# ~/agent-webhook-bridge-prod for the prod-agent install — see CLAUDE_DEPLOYMENT.md)
composer install
vendor/bin/phpunit                                  # full test suite (SQLite in-memory)
vendor/bin/pint --test                              # code style (Laravel preset)
vendor/bin/phpstan analyse -c phpstan-laravel.neon  # static analysis (app/Bridge, level 7)

# Operator CLI (per-agent config under ~/.config/agent-webhook-bridge[-prod|-dev]/<agent>.yml)
php artisan bridge:check                 # validate the install (dirs, DB connectivity, agent YAMLs)
php artisan bridge:provision             # idempotent webhook subscription setup (--reconcile fixes drift)
php artisan bridge:inbox                 # surface staged intents (Claude Code hook-aware)
php artisan bridge:prune --older-than=30d # retention: prune old events/dispatches/inbox lines (the one cron the design accepts)
php artisan bridge:replay <N>            # re-dispatch a stored event by id (recovery for errored/missed dispatches)
php artisan bridge:inspect <N>           # pretty-print one event + its dispatch ledger
php artisan bridge:stats                 # event / dispatch counts
```

## Standing rules

1. **Senior-dev review-agent loop before every PR.** Iterate review → fix → re-review until CLEAN. Do not skip. CI passing is necessary, not sufficient. See [`feedback-review-agent-loop-before-pr`](../.claude/projects/-home-kanban/memory/feedback-review-agent-loop-before-pr.md) memory note.
2. **Doc-sync in every PR.** Behavior changes update the affected subfile in the same PR. Decision-worthy changes append `DL-NNN` to `CLAUDE_DECISIONS.md`. Don't "document later."
3. **No secrets in the repo.** Per-`(provider, scope)` webhook secrets live at `<secret_dir>/<provider>/webhook-secret-scope-<scope>` (chmod 600); the DB password lives in the Laravel `.env` (`DB_PASSWORD`); API tokens live by convention at `<secret_dir>/<provider>/token` (per-agent `api.<provider>.token_path` is an optional override). `secret_dir` defaults to `BRIDGE_DIR`. `.env`, the secret dir, and `~/.config/...` are outside the repo / gitignored. `.env.example` and `examples/sample-config/agent.yml.example` are the templates that DO ship.
4. **Two-branch workflow: `main` + `dev`.** `dev` is the integration branch where all feature work lands. `main` is the release branch — only the user merges to it (typically via a release PR from `dev`). All feature branches PR back to `dev`. Matches the kanban-board project's workflow.
5. **Ask before opening every PR; auto-merge dev-targeted PRs on green CI; never merge to `main`.** Per [`feedback-git-workflow`](../.claude/projects/-home-kanban/memory/feedback-git-workflow.md) (canonical for both agent-webhook-bridge AND kanban-board): opening a PR is an explicit checkpoint (visible artifact: notifications, CI runs) — ASK before `gh pr create`. Merging a dev-targeted PR is just integration of validated work — Claude auto-merges on green CI without further approval. **Wait for ALL CI workflows (Laravel Tests + Security) to complete + pass before auto-merging.** **Hard gate: only the user merges PRs to `main`** — even on green CI. **Post-release exception:** when the user confirms a release PR has been merged to `main`, that single confirmation authorizes Claude to (a) tag the merge commit `v<VERSION>` AND (b) open the back-merge sync PR `sync/main-to-dev-post-v<version>` autonomously — no separate ask for either. The sync PR then auto-merges on green like any dev-targeted PR. Security-critical surfaces (receiver / HMAC / secrets / DB-schema) get an additional explicit-approval gate on top.
6. **Bridge code stays out of the kanban-board repo.** Customer-side infrastructure lives in its own git repo for lifecycle decoupling. Same rationale as `kbcard` living in `~/.local/bin/` not `kanbanboard/bin/`.
7. **Per-agent installations, not shared.** Two agents (e.g. `prod-agent` + `dev-agent` on the canonical reference install, or any operator-chosen pair of identities) running on the same host install their own bridge copies with their own DBs. No shared runtime state across agents. See [`CLAUDE_DEPLOYMENT.md`](CLAUDE_DEPLOYMENT.md).
8. **SHA-pin every third-party GitHub Action.** Format: `uses: <owner>/<repo>@<full-40-char-SHA>  # vX.Y.Z`. The `# vX.Y.Z` comment is load-bearing — dependabot's github-actions ecosystem regex parses it to know what version is currently pinned. Reject mutable tag references (`@v4`) at PR review — that's the tj-actions/changed-files hijack class. Resolution recipe in [`feedback-ci-hygiene-going-forward`](../.claude/projects/-home-kanban/memory/feedback-ci-hygiene-going-forward.md). SHA-pinning was adopted over mutable tag references after a 2026-05-24 inventory review.
9. **Act vs. ask.** Default operating mode: senior-dev posture. Definitive answers from investigation + project conventions → act. Multiple correct ways and you're uncertain which is best for the project → ask, present options + tradeoffs + recommendation, then wait. Push back when you see a better approach — grounded in evidence (file:line, command output), not opinion. Silent compliance is the wrong default. The hard gate from the user-level `CLAUDE.md` still applies: error-handling changes, validation changes, business-logic changes, permissive "fixes," any git push/PR/merge to main, and destructive DB commands always need explicit approval.

## Subfile index

| File | Contents | When to read |
| --- | --- | --- |
| [`CLAUDE_ARCHITECTURE.md`](CLAUDE_ARCHITECTURE.md) | Synchronous request lifecycle, `app/Bridge` package map, data-flow walkthrough, at-least-once model, multi-agent + multi-provider mental model | Before any cross-cutting change, or when orienting cold |
| [`CLAUDE_DECISIONS.md`](CLAUDE_DECISIONS.md) | Append-only `DL-NNN` decision log with rationale + alternatives considered + consequences | Before changing a load-bearing pattern, or when proposing a deviation |
| [`CLAUDE_CONVENTIONS.md`](CLAUDE_CONVENTIONS.md) | Naming, file layout, comment policy, PHP/Laravel idioms | Before naming a new class / file |
| [`CLAUDE_TESTING.md`](CLAUDE_TESTING.md) | PHPUnit setup (SQLite in-memory, `RefreshDatabase`, `Http::fake`), fixture conventions, what to test where | Before adding or modifying tests |
| [`CLAUDE_DEPLOYMENT.md`](CLAUDE_DEPLOYMENT.md) | Install + update + cutover runbook, runtime ops (status contract, done-vs-errored, log/state locations, `bridge:*` commands, replay), diagnose, rollback, second-agent | Installing, operating, or diagnosing an install |
| [`CLAUDE_GOTCHAS.md`](CLAUDE_GOTCHAS.md) | Surprising behaviors + "looked like X but actually Y" notes (raw-body HMAC capture, MariaDB timestamp formats, dedupCreate races, scope_id traversal, etc.) | When hitting an unexpected error, or before touching the receiver / dispatch path |
| [`VERSIONING.md`](VERSIONING.md) | Version bump rules, release tagging, `docs/CHANGELOG.md` update flow | Before tagging a release |
| [`docs/customization.md`](docs/customization.md) | Writing custom classifiers + handlers + surface formatters (PHP) | Adding agent-specific behavior |
| [`docs/provider-adapters.md`](docs/provider-adapters.md) | Adding a third upstream provider (HMAC header, envelope shape, `WebhookAdapter`) | Integrating GitLab / Jira / etc. |
| [`docs/multi-agent.md`](docs/multi-agent.md) | Running parallel agents on the bridge | Onboarding a second agent |
| [`docs/multi-host.md`](docs/multi-host.md) | Running agents across multiple hosts | Scaling beyond one box |
| [`docs/consumer-guide.md`](docs/consumer-guide.md) | Agent-author's guide to consuming staged intents (inbox shape, hook wiring) | Building the agent that reads the bridge |

> Subfiles are **always** referenced from this index. If you add a new top-level doc, add it here too. If a topic doesn't have its own subfile yet, it lives in the most relevant existing one or doesn't need to be documented yet.

## Recent releases

Filled in by the release PR that produces each version tag. See [`docs/CHANGELOG.md`](docs/CHANGELOG.md) for the full per-version log.

| Version | Date | Highlights |
| --- | --- | --- |
| v0.17.0 | 2026-05-30 | **Install-easing docs + a CI fix.** ⚠ Channel-server example renamed `kanban-bridge-channel.mjs` → `agent-webhook-bridge-channel.mjs` to match `package.json`/README/`.mcp.json.example` (breaking for a `.mcp.json` pointing at the old name — update the `args` path). CI: removed the `paths-ignore` that permanently blocked docs-only PRs (required checks now always run+report). Docs: cross-install peer-YAML note, channels-are-CLI-only + a launcher (`examples/start-channel-session.sh`), a v0.16 config-v2 migration checklist, FPM-reload-needs-sudo note. Pint clean · PHPStan level 7 0 errors · PHPUnit 229/229. |
| v0.16.0 | 2026-05-30 | **Per-agent inbox surfacing (DL-006) + ⚠ breaking config schema v2 (DL-007).** Single-install fan-out gets per-agent views: `BRIDGE_INBOX_LAYOUT`, `bridge:inbox --agent` with isolated cursors, cross-user group convention, `channel.route_intents`, and a cursor-advance fix (wiring on `Stop` no longer eats intents). Config v2 kills duplication: the YAML filename is the agent name (no `identity.self`), per-install endpoints move to `.env` (`BRIDGE_RECEIVER_BASE_URL`/`BRIDGE_KANBAN_API_BASE_URL`), identity folds into the YAMLs (`agents.json` → optional `shared-identities.json`), `BRIDGE_DIR` collapses config+secret dir, token by `<secret_dir>/<provider>/token` convention, `channel.name` removed, self echo-ids auto-seeded, fail-closed config with full `bridge:check` validation. **Operators migrate per-agent config — see DL-007.** Pint clean · PHPStan level 7 0 errors · PHPUnit 229/229. |
| v0.15.0 | 2026-05-30 | **Custom-handler registration works as documented (DL-004) + per-agent echo restored for a shared upstream identity (DL-005).** `HandlerRegistry` is a container singleton, so the documented `afterResolving(HandlerRegistry::class, …)` path registers onto the exact instance the dispatcher uses (no more re-binding `DispatchService`). New optional `ClassifyResult::reattributedActor`: a classifier that recovers the true author of a shared-identity event returns it, and the dispatcher re-runs the same per-agent echo check post-classify — suppressing the agent's own write while a peer shared-id agent's still surfaces (`Classifier` contract unchanged; null is a no-op). Both reported by a peer integrator. Pint clean · PHPStan level 7 0 errors · PHPUnit 205/205. |
| v0.14.0 | 2026-05-30 | **Same-event ReactionTarget coalescing by `debounce_key` restored (DL-003)** — targets in one `ClassifyResult` sharing a key collapse last-wins at dispatch time (the v0.12 rewrite kept the contract but dropped the logic); `debounce_seconds` is advisory metadata, no cross-delivery window. Plus a divergent-duplication cleanup: purged stale Python-tree references from docblocks + schema docs, corrected the schema's GitHub actor.id, and deduped CI PHP setup into a composite action. Pint clean · PHPStan level 7 0 errors · PHPUnit 200/200. |
| v0.13.0 | 2026-05-30 | **Agent recognition keys on the immutable GitHub account id, not the renameable username (DL-002).** `GitHubAdapter` uses `sender.id`; matching is provider-aware; `agents.json` → `schema_version 2` (per-agent `github_user_id` + declared-once `shared_identities`; `github_login` is a display-only label with a stale-login drift warning). A username rename is a non-event. **Breaking:** v1 `agents.json` must be migrated to v2. Pint clean · PHPStan level 7 0 errors · PHPUnit 199/199. |
| v0.12.0 | 2026-05-29 | **The Laravel rewrite, shipped as a fresh repository (see CLAUDE_DECISIONS.md DL-001).** Collapsed the v0.1–v0.11 Python-consumer + PHP-receiver 5-layer async pipeline into a single Laravel 13 app doing synchronous in-request dispatch — no queue, no consumer cron, no daemon. At-least-once is borrowed from kanban-board's webhook retry plus the `inbox.jsonl` pull-backstop. Git history starts here (prior history was not carried over). Pint clean · PHPStan level 7 (`app/Bridge`) 0 errors · PHPUnit 188/188. |

## Critical paths

- `app/Http/Middleware/VerifyHmacSignature.php` + `EnvelopeSizeLimit.php` — receiver entry gates. Constant-time HMAC over the raw request body. Security-critical surface.
- `app/Http/Controllers/Webhook/WebhookController.php` — the synchronous request lifecycle: adapt → record (`dedupCreate`) → classify → stage to inbox → dispatch handlers → return. The three failure-treatment branches (classify-throws / inbox-throws / handler-throws) live here. Security-critical surface.
- `app/Bridge/Adapters/*` (`WebhookAdapter` contract + `KanbanAdapter` / `GitHubAdapter`) — per-provider HMAC header + envelope extraction. Read alongside the upstream's webhook spec.
- `database/migrations/*` — `webhook_events` (`UNIQUE(delivery_id)` is the at-least-once dedup gate) + `agent_dispatches` (per-agent dispatch ledger). Schema-critical surface.
- `app/Bridge/Dispatch/*` — the data shapes (`Intent`, `ReactionTarget`, `Actor`, `ClassifyResult`, `IntentLog`) + `DispatchService` (the dispatch loop + per-agent error isolation). `dedupCreate` is the at-least-once write primitive.
- `app/Bridge/Classifiers/*` + `app/Bridge/Contracts/*` — built-in classifiers; custom classifiers implement the `Classifier` contract. `app/Bridge/Handlers/*` are the dispatch targets (`log_intent`, `channel_push`, …).
- `app/Console/Commands/Bridge/InboxCommand.php` — surfaces staged intents to agent context (Claude Code hook-aware). Cursor-based dedup; silent-when-empty discipline.
- `app/Bridge/Support/SubscriptionRegistry.php` + `AgentConfig.php` + `AgentRegistry.php` — per-agent YAML loader (fail-closed: malformed YAML throws → 5xx). YAML schema: `identity` (the agent's own kanban/github ids), `subscriptions`, optional `echo_suppression` + `classifier` + `channel` + `surface` + `api.<provider>.token_path` override. The filename is the agent name (no `identity.self`); `AgentRegistry::fromAgentConfigs` builds the roster by scanning these YAMLs' `identity` blocks plus an optional `shared-identities.json` (the shared-account case). There is no `agents.json`. Per-install endpoints (`BRIDGE_RECEIVER_BASE_URL`, `BRIDGE_KANBAN_API_BASE_URL`) live in `.env`/`config/bridge.php`, not the YAML.
- `config/bridge.php` — bridge runtime config (`BRIDGE_DIR` and its `config_dir`/`secret_dir` overrides, `install_suffix`, the receiver + provider API base URLs). `app/Bridge/Support/SecretPath.php` + `TokenPath.php` are the shared secret/token-path shapes; `BridgePaths.php` resolves `BRIDGE_DIR`; `InstallGuard.php` is the dev/prod crosstalk guard.

For the system overview, see [`CLAUDE_ARCHITECTURE.md`](CLAUDE_ARCHITECTURE.md). For why anything is the way it is, see [`CLAUDE_DECISIONS.md`](CLAUDE_DECISIONS.md).
