# Consumer guide — building on the bridge's event stream

Audience: operator or developer building a downstream agent that observes the bridge's classified events (Claude Code session, Slack notifier, dashboard).

The bridge does provider-agnostic normalization, classification, echo suppression, and synchronous in-request dispatch. This guide covers the consumer boundary only. Internals (request lifecycle, inbox staging, handler dispatch) are in `CLAUDE_ARCHITECTURE.md` and the `CLAUDE_*.md` set.

## How dispatch works in v0.12

The bridge is a **single Laravel app**. When a webhook arrives: classify → stage intents to `inbox.jsonl` → run configured handlers — all **synchronously in that one request**. No consumer cron, no queue worker, no daemon. No "queue depth" or "consumer lag."

`inbox.jsonl` is written in the webhook request itself as the durable pull-backstop. Push handlers (e.g. `channel_push`) reach an active agent session immediately; if no session is up, the intent is already in `inbox.jsonl` for the next hook firing. Two surfacing paths for the same staged intent; the inbox is always written first.

If `bridge:inbox` shows nothing new, the event either:
- was not subscribed by this agent,
- was echo-suppressed (the agent's own write), or
- the classifier emitted no intents for it.

It is never "the consumer hasn't caught up yet" — there is no consumer process to fall behind.

**But the inbox only surfaces when a hook actually fires `bridge:inbox`.** If you are *missing addressed events during long active sessions* (they're present in `inbox-<agent>.jsonl` but never surfaced), or your `inbox-seen-<agent>.json` cursor is **stale for days while sessions keep starting**, the cause is almost always **hook wiring that has no mid-session trigger** — `bridge:inbox` wired on `SessionStart` only (or `PreToolUse` with a narrow `matcher`). `SessionStart` fires once per session, so a long-lived session that never restarts never re-checks the inbox, and a stale cursor is the tell. Fix: wire `PreToolUse` with `"matcher": ""` (see [Wiring `bridge:inbox` into Claude Code hooks](#wiring-bridgeinbox-into-claude-code-hooks)). The live `channel_push` is best-effort by design — the inbox hook is the recovery road, and it must be wired to fire mid-session.

## Where to read

Each agent's inbox:

```
<BRIDGE_CONFIG_DIR>/state/inbox.jsonl
```

`BRIDGE_CONFIG_DIR` = `config('bridge.config_dir')`, which defaults to `BRIDGE_DIR` — the one base dir set in the Laravel `.env` (`BRIDGE_CONFIG_DIR` is an optional override only when the config dir lives elsewhere). Default: `~/.config/agent-webhook-bridge-<suffix>/state/inbox.jsonl`. See `CLAUDE_DEPLOYMENT.md § Where things land` for the full path table.

One JSON document per line. Each document is an `Intent` (schema below).

## Schemas

| Surface | Schema |
|---|---|
| Intent (line in `inbox.jsonl`) | [`docs/event-schema.json`](event-schema.json) |
| ReactionTarget (in handler-log + custom-handler args) | [`docs/reaction-target-schema.json`](reaction-target-schema.json) |
| Agent registry | [`docs/multi-agent.md § Agent registry`](multi-agent.md) — built from per-agent YAML `identity` blocks |

Both schemas are JSON Schema 2020-12 draft. Non-additive changes (renames, removals, type tightening) bump the version; additive fields do not. Pin your parser to the schema version you built against; fail-soft on bump.

## Consumption patterns

> ### Recommended model for live agents (e.g. PM agents): **MCP channel + upstream reconcile**
>
> For a Claude Code agent whose events all originate in an upstream **source of truth** (GitHub issues/PRs/comments, kanban cards), this is the standard — verified by two independent PM implementations (DL-170). It has two layers; **the bridge `inbox` is neither of them** (it's the fallback below):
>
> 1. **Live wake = the MCP channel push** (`notifications/claude/channel`, via the channel server in `examples/channel-servers/` over a unix socket). This is the only genuinely **event-based** path — the bridge pushes the instant it processes the webhook. It's *best-effort* (a push while the session is mid-turn or disconnected is dropped), so it must be paired with the recovery layer.
> 2. **Durable recovery = reconcile from the source of truth** (re-derive open work from GitHub via `gh` and from the kanban API) — **not** from the bridge inbox. Run it in two modes:
>    - **SessionStart → full-dump reconcile.** Re-establish complete open state (every open `to:<you>`, every untriaged/assigned card).
>    - **Mid-session → light *delta* reconcile.** Surface only items **new since a watermark**, **silent when nothing is new**, and **throttled** (periodic — e.g. once per 3–10 min — gated by a state-file timestamp; it hits the GitHub/kanban APIs, so it must NOT run on every tool call). It **must be a light delta (sub-second)**, not the full reconciler run inline — the full sweep can be tens of seconds and would stall the tool call. This bounds worst-case recovery latency in a long quiet turn without spamming context.
>
> **Why reconcile, not the inbox:** reconcile-from-truth is idempotent and drift-proof — it recovers *what is actually open now*, even if the bridge inbox drifted or was pruned. The inbox replays a notification log, which is weaker, and **redundant** for an agent whose every event is an upstream artifact (confirmed: both reference PMs subscribe only to GitHub `coord_*` + kanban `card_*` intents — zero bridge-only events).
>
> **Known refinement (in progress):** today the reference implementations cover **GitHub mid-session** (delta) + **kanban at SessionStart**; folding kanban into the mid-session delta — and moving the whole delta reconciler into the shared consumer-side framework so every agent runs the identical thing (with an event-aware surface envelope) — is the durable next step. The current gap (a kanban card change missed by the live push recovers only at the next SessionStart) is bounded and low-risk: card events are low-frequency board-state, and the kanban triage-wake classifier already pushes the high-value ones live.
>
> The patterns below (`bridge:inbox`, tail, custom handler) are the consumption **mechanisms**. For a live, upstream-anchored agent, use the model above and treat `bridge:inbox` as the **fallback** (next).

### A. `bridge:inbox` (the fallback pull — Claude Code hooks)

Reads `inbox.jsonl`, deduplicates on the stable per-line `id` field (NOT a wall-clock cursor), and prints only unseen intents. **Silent when there is nothing new.**

```bash
php artisan bridge:inbox
php artisan bridge:inbox --hook-format=claude-code
php artisan bridge:inbox --hook-format=plain
```

**Flags:**

- `--hook-format={auto|claude-code|plain}` (default `auto`):
  - `auto` — reads stdin for a `hook_event_name` key. If the detected event supports `additionalContext` injection, wraps output in the hook envelope; otherwise emits plain markdown.
  - `claude-code` — forces the hook envelope regardless of stdin shape. Use in wrapper scripts that can't pipe stdin through.
  - `plain` — forces plain markdown. Useful for ad-hoc inspection or piping.
- `--agent=<name>` — surface only that agent's intents (its `inbox-<agent>.jsonl`, or the shared file filtered by the `agent` tag), with its own seen cursor. For a single install fanning out to N agents; see [`multi-agent.md` § Per-agent surfacing](multi-agent.md#per-agent-surfacing-one-install-n-agents). Defaults to `BRIDGE_DEFAULT_AGENT` when unset.
- `--no-cursor-advance` — print unseen intents without marking them seen (a peek). The next run re-surfaces them.

**Hook envelope.** Plain stdout from hook events that support `additionalContext` goes to Claude Code's debug log only — it never reaches the model. `bridge:inbox` wraps output automatically:

```json
{
  "hookSpecificOutput": {
    "hookEventName": "SessionStart",
    "additionalContext": "## Kanban bridge — new activity\n- **card_moved** — ..."
  }
}
```

Events that support `additionalContext` (wrapping applies):

```
SessionStart, Setup, SubagentStart, UserPromptSubmit,
UserPromptExpansion, PreToolUse, PostToolUse, PostToolUseFailure, PostToolBatch
```

Events that do NOT support `additionalContext` (`Stop`, `Notification`, etc.) receive plain markdown that can't reach model context. On those events `bridge:inbox` prints but **does not advance the seen cursor** — the intents stay unseen so the next `SessionStart`/`PreToolUse` surfaces them. So wiring `bridge:inbox` on `Stop` is safe: it never silently eats an intent. (A manual, non-hook run reaches the terminal, so it does advance; use `--no-cursor-advance` to force a non-advancing peek.)

**Dedup state** is a JSON array of seen `id` strings: `<state_dir>/inbox-seen.json` for the shared inbox, or `<state_dir>/inbox-seen-<agent>.json` per agent under `--agent`. A redelivered or re-staged line with the same `id` never re-surfaces, and one agent's cursor never hides another's intents.

### B. Tail `inbox.jsonl` directly

Decoupled, language-agnostic. Suitable for Slack notifiers, dashboards, and other non-Claude-Code consumers.

```python
import json
import time
from pathlib import Path

state_dir = Path.home() / ".config" / "agent-webhook-bridge-prod" / "state"
inbox = state_dir / "inbox.jsonl"
last_position = 0

while True:
    if inbox.exists() and inbox.stat().st_size > last_position:
        with inbox.open("r", encoding="utf-8") as f:
            f.seek(last_position)
            for line in f:
                if line.strip():
                    intent = json.loads(line)
                    handle(intent)  # your routing logic
            last_position = f.tell()
    time.sleep(1)
```

`bridge:inbox` only reads `inbox.jsonl` and tracks seen ids in `inbox-seen.json`; it never rewrites or prunes `inbox.jsonl`. A tail consumer with a byte-offset cursor will not have lines pulled out from under it. To recover a missed dispatch: `php artisan bridge:replay <N>` consults `webhook_events` (the authoritative audit store, never touched by inbox operations) and re-runs dispatch.

TypeScript/Bun/Node tail libraries (`tail-file`, `chokidar` + readline) work the same way. File is plain UTF-8 JSONL with `\n` line terminators.

### C. Custom handler

Write a PHP handler class implementing `App\Bridge\Contracts\Handler` that the bridge's classifier dispatches to synchronously in the webhook request. See `docs/customization.md` for the full extension walkthrough.

| | `bridge:inbox` hooks (A) | Tail (B) | Custom handler (C) |
|---|---|---|---|
| Language | Any (artisan CLI) | Any | PHP |
| Coupling | Pull — agent reads when active | Decoupled (consumer = separate process) | Tight (runs in-request) |
| Latency | Next hook firing (sub-second for active sessions) | Poll interval | Sub-second (in-request) |
| Per-event filtering | Classifier-side | Consumer-side | Classifier-side |
| Setup complexity | Wire hooks in `settings.json` | Just read a file | PHP class + registration |

For a live, upstream-anchored Claude Code agent (a PM agent), use the **MCP channel + upstream reconcile** model above; `bridge:inbox` (A) is the **fallback** — use it as the primary only when the agent has **bridge-only intents** (events with no GitHub/kanban artifact to reconcile from) or you specifically want the bridge inbox as the durable layer. Pick B (tail) for cross-language consumers (TypeScript MCP servers, Bun adapters, Slack notifiers). Pick C (custom handler) for PHP-native services that must act synchronously in the webhook request.

## Wiring `bridge:inbox` into Claude Code hooks

```json
{
  "hooks": {
    "SessionStart": [
      {
        "matcher": "",
        "hooks": [
          {
            "type": "command",
            "command": "php /path/to/your/agent-webhook-bridge/artisan bridge:inbox"
          }
        ]
      }
    ],
    "PreToolUse": [
      {
        "matcher": "",
        "hooks": [
          {
            "type": "command",
            "command": "php /path/to/your/agent-webhook-bridge/artisan bridge:inbox"
          }
        ]
      }
    ]
  }
}
```

Full template at `examples/claude-code/settings.json.example`. Adjust the artisan path to match your install.

> **Both legs are load-bearing — wire SessionStart AND PreToolUse.** They cover different windows, and the live `channel_push` is best-effort (an intent pushed while the session is mid-turn is dropped from the live path and recovered only via the inbox):
> - **`SessionStart`** surfaces what accrued *between* sessions — recovery at session **boundaries** only. It fires once, at session start.
> - **`PreToolUse`** is the **per-tool-call mid-session trigger** (recommended) — it re-checks the inbox before each tool call, so an intent that arrives *during* a long active session surfaces within that session, before the agent's next action. (`PostToolUse` is the after-the-tool equivalent; either gives mid-session coverage. `UserPromptSubmit` only fires on a user turn, so it doesn't cover a long autonomous tool-turn.) **Without a per-tool-call trigger, a long-lived session surfaces nothing new until it restarts** — and a session sitting in a long uninterrupted turn is exactly when a live `channel_push` is most likely to be missed. `SessionStart`-only is the most common misconfiguration and looks like "the bridge dropped my event" when the event is sitting unseen in the inbox.
>
> **Use `"matcher": ""` (all tools), not `"Bash"`, for the PreToolUse leg.** A narrow matcher only fires before *that* tool — a long stretch of non-matching work (Read/Edit/Grep/…) still surfaces nothing, the same gap one level down. `bridge:inbox` is id-deduped and silent-when-empty (DL-072), so firing before every tool is idempotent and cheap-when-quiet; the only cost is one short-lived `php artisan` subprocess per tool call. If that overhead is unacceptable on a hot host, narrow the matcher deliberately — and accept the reduced mid-session coverage.

On events that don't support `additionalContext` (e.g. `Stop`, `Notification`): `bridge:inbox` prints but **does not advance the seen cursor** — the intents stay unseen so the next `SessionStart`/`PreToolUse` surfaces them. So wiring it on `Stop` is safe (it never silently eats an intent), but it is **not** a substitute for the `PreToolUse` leg: it can't inject into model context, so nothing reaches the model there.

If nothing surfaces to the model after wiring: verify the hook event type is in the `ADDITIONAL_CONTEXT_EVENTS` list above, confirm the artisan command runs successfully from the shell, and check `storage/logs/laravel.log` for dispatch errors.

## Agent registry contract

There is no `agents.json`. The registry is built per request by scanning the per-agent YAMLs in the config dir — the FILENAME (`prod-agent.yml` → `prod-agent`) is the agent's name, and its `identity:` block holds its immutable upstream ids:

```yaml
# prod-agent.yml
identity:
  kanban_user_id: 3
  github_user_id: 12345678
  github_login: prod-agent-bot   # display-only label

# dev-agent.yml
identity:
  kanban_user_id: 4
```

| `identity` field | Required? | Notes |
|---|---|---|
| (filename) | yes | The `<agent>.yml` filename is the agent name — used for echo suppression by `actor.name` and as the addressing token for ReactionTarget routing |
| `kanban_user_id` | optional | Immutable integer; absent = agent has no kanban identity |
| `github_user_id` | optional | Immutable numeric GitHub account id (`sender.id`); the GitHub **matching key**. absent = agent has no GitHub identity |
| `github_login` | optional | Display-only label (GitHub usernames rename, so they are never a matching key — DL-002). A stale label fires a one-line drift warning |

An optional `shared-identities.json` in the config dir declares a GitHub account shared by multiple agents **once** (instead of repeating the id in every agent's `identity` block):

```json
{
  "shared_identities": [
    {"github_user_id": 12000042, "github_login": "shared-bot",
     "agents": ["pm", "device", "backend", "inventory"]}
  ]
}
```

Events from a shared account can't be attributed to a single agent, so `actor.name` resolves to `null` on purpose — a custom classifier re-attributes from a secondary signal (repo scope, a `FROM:` line). The file is omitted entirely when no account is shared. See [`multi-agent.md § Shared identity across agents`](multi-agent.md).

Source of truth for "what agents exist + how to address them." Read-on-startup is fine; the YAMLs change only on operator action.

## Echo-suppression semantics

`EchoSuppression` filters events authored by the agent's own identity before classification. Consumers reading `inbox.jsonl` will never see the agent's own writes — this is intentional and load-bearing for loop-avoidance.

`SignalAllowlist` further filters when `treat_as_signal` is set to a non-empty list in the operator config — only events from named agents reach the inbox.

Both filters happen **before** `inbox.jsonl` writes. Consumers do not need to re-implement them.

## Schema versioning contract

| Surface | Version | Bump policy |
|---|---|---|
| `webhook_events` DB schema | `EXPECTED_SCHEMA_VERSION` in the migration set | Bumps on column add/remove/rename or constraint change. Internal; consumers tail JSONL, not the DB. |
| Event-schema (Intent) | `v1` (per `$id` URI path) | Bumps on non-additive change. v2 would live at `docs/v2/event-schema.json`; v1 stays at current path. |
| ReactionTarget schema | `v1` (per `$id` URI path) | Same policy. |
| Agent registry | per-agent YAML `identity` blocks + optional `shared-identities.json` | Not a versioned wire surface — operator config. Recognition keys on immutable numeric ids (`kanban_user_id` / `github_user_id`); `github_login` is a display-only label (DL-002). Consumers tail `inbox.jsonl`, not the registry. |

Pin to the schema version you built against. Fail-soft (warn + skip) on intents whose `schema_version` doesn't match.

## Multi-agent topology

Each agent install has its own bridge with its own classifier and its own `inbox.jsonl`. Two options for waking multiple Claude Code sessions:

1. **One `bridge:inbox` hook per agent.** Each session hooks into its own artisan install. N agents = N independent hook configurations. Simple, debuggable, no fan-out logic.
2. **One tail consumer subscribing to multiple inboxes.** Pattern B tails N files and routes each intent to the matching session. Requires routing logic the consumer owns; useful when cross-agent state (rate limiting, deduplication) is easier centralized.

## Cross-references

- [`CLAUDE_ARCHITECTURE.md`](../CLAUDE_ARCHITECTURE.md) — synchronous request lifecycle, inbox staging, at-least-once model
- [`CLAUDE_DEPLOYMENT.md`](../CLAUDE_DEPLOYMENT.md) — runtime ops, log/state locations, `bridge:*` commands, replay
- [`docs/multi-agent.md`](multi-agent.md) — running multiple agents on one host
- [`docs/customization.md`](customization.md) — writing custom classifiers and handlers
- [`docs/event-schema.json`](event-schema.json) — Intent JSON Schema
- [`docs/reaction-target-schema.json`](reaction-target-schema.json) — ReactionTarget JSON Schema
- [`CLAUDE_DECISIONS.md`](../CLAUDE_DECISIONS.md) DL-001 (v0.12 synchronous rewrite)
