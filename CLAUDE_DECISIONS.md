# Decision log

> **Append-only.** Every load-bearing decision in the bridge gets an entry here, with the rationale + alternatives considered + consequences, so a future session can reconstruct the why of each design choice. Corrections to a prior decision get a **new** entry titled "Correction to DL-NNN"; the original stays frozen.
>
> This repository's history begins at **v0.12.0**. An earlier implementation existed (a Python consumer + standalone PHP receiver) but is not part of this repo; only the decisions that are load-bearing for the current code are recorded here.

---

## DL-001 — Single synchronous Laravel app: receive → classify → stage → dispatch, in one request

- **Date:** 2026-05-29
- **Context:** The bridge gives an AI agent (or operator) real-time visibility into kanban-board activity — and any other webhook-emitting upstream following the same shape — without burning tokens polling. It runs as one install per agent, co-located with its database on a single host. The core design question is whether to decouple the fast "receive" path from the slower "process" path with a durable queue, or to do the whole thing inline.
- **Decision:** One Laravel 13 app, **synchronous in-request dispatch**. A webhook is HMAC-verified in middleware (`VerifyHmacSignature`, constant-time over the raw body) and size-bounded (`EnvelopeSizeLimit`); the per-provider `WebhookAdapter` parses the envelope; then `DispatchService` runs **classify → stage to `inbox.jsonl` → run handlers** synchronously in the same request, returning `200` only when every subscribed agent has been processed. **No queue worker, no consumer cron, no daemon.** `webhook_events` is a dedup gate (`UNIQUE(delivery_id)`) plus an audit/replay store — not a work queue; `agent_dispatches` is the per-agent, per-event outcome ledger.
- **Three-way failure treatment** (the load-bearing reliability contract, in `WebhookController` + `DispatchService`):
  - **(A) classify throws** → record the error on that agent's dispatch (`processed_at` stays null), ack **200**. A classifier bug must not wedge delivery; the raw event is stored and `bridge:replay <id>` re-runs it.
  - **(B) inbox staging throws** → propagate → **5xx** → kanban-board redelivers. `inbox.jsonl` is the durable pull-backstop; silently losing a staged intent is the one unacceptable outcome.
  - **(C) handler throws** → mark that agent's dispatch *done-with-note* and continue. Per-agent isolation: one agent's channel server being down must not fail the delivery or the other agents.
- **At-least-once is borrowed, not built.** Any internal/durability failure throws → Laravel returns `5xx` → kanban-board's webhook retry redelivers; `inbox.jsonl` is the durable pull-backstop that `bridge:inbox` reads even if a push never arrived. The dispatch write primitive is `dedupCreate` (create + catch `UniqueConstraintViolationException` → refetch), so a redelivery resumes safely. There is deliberately **no** `DB::transaction` around the dispatch loop — a handler does network I/O (channel_push) and a rollback cannot un-send a POST; each dispatch records its own outcome independently.
- **Alternatives considered:**
  - **A durable queue + worker / scheduled command.** Rejected: the receiver and the processor run on the *same host* against the *same database*, so a queue would decouple two things that are never actually decoupled in time or space — while adding a daemon/cron failure surface (silent stalls, lock contention, lag). If a future install genuinely needs to absorb a receive/process throughput mismatch, Laravel's queue is a localized change behind the same `DispatchService` — re-introduce it then, with evidence, not speculatively.
  - **Wrapping the dispatch loop in a DB transaction.** Rejected: a handler making an external call inside a transaction holds a connection across network I/O, and a rollback can't un-send a push. There is no all-or-nothing semantic to protect.
  - **Pure polling** (cron against the changelog API). Rejected for token cost + latency; the webhook push with an `inbox.jsonl` pull-backstop is strictly better.
- **Consequences:** One PHP runtime (Laravel 13 / PHP 8.3 / Eloquent over MariaDB 10.6+, SQLite for tests). Per-`(provider, scope)` HMAC secrets live under `BRIDGE_SECRET_DIR`; the DB password in `.env`; per-agent policy in `~/.config/agent-webhook-bridge[-prod|-dev]/<agent>.yml`. Latency is the handler runtime (single-digit ms for the shipped `log_intent` / `channel_push`), comfortably under kanban-board's delivery timeout — a genuinely slow handler is the signal to reach for a queue, not a reason to pre-build one. CI is `Laravel Tests` (PHPUnit on a SQLite + MariaDB 10.6/11 matrix) + `Security` (gitleaks); static analysis is `phpstan-laravel.neon` (level 7, scoped to `app/Bridge`).

---

> **How to add a DL entry.** Use the next available `DL-NNN`. Lead with Date + Context (what made the decision necessary), then Decision (what was chosen), then Alternatives considered (with one-line rejections), then Consequences (what this enables or constrains downstream). Cite specific files/lines when load-bearing. If correcting a prior DL, write a new one titled "Correction to DL-NNN" and leave the original frozen.
