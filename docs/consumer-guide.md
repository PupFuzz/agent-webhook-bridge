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

## Where to read

Each agent's inbox:

```
<BRIDGE_CONFIG_DIR>/state/inbox.jsonl
```

`BRIDGE_CONFIG_DIR` = `config('bridge.config_dir')` (set via `BRIDGE_CONFIG_DIR` in the Laravel `.env`). Default: `~/.config/agent-webhook-bridge-<suffix>/state/inbox.jsonl`. See `CLAUDE_DEPLOYMENT.md § Where things land` for the full path table.

One JSON document per line. Each document is an `Intent` (schema below).

## Schemas

| Surface | Schema |
|---|---|
| Intent (line in `inbox.jsonl`) | [`docs/event-schema.json`](event-schema.json) |
| ReactionTarget (in handler-log + custom-handler args) | [`docs/reaction-target-schema.json`](reaction-target-schema.json) |
| Agent registry | [`docs/multi-agent.md § agents.json`](multi-agent.md) — `schema_version: 1` |

Both schemas are JSON Schema 2020-12 draft. Non-additive changes (renames, removals, type tightening) bump the version; additive fields do not. Pin your parser to the schema version you built against; fail-soft on bump.

## Consumption patterns

### A. `bridge:inbox` (recommended — Claude Code hooks)

Reads `inbox.jsonl`, deduplicates on the stable per-line `id` field (NOT a wall-clock cursor), and prints only unseen intents. **Silent when there is nothing new.**

```bash
php artisan bridge:inbox
php artisan bridge:inbox --hook-format=claude-code
php artisan bridge:inbox --hook-format=plain
```

**The only flag is `--hook-format={auto|claude-code|plain}`** (default `auto`).

- `auto` — reads stdin for a `hook_event_name` key. If the detected event supports `additionalContext` injection, wraps output in the hook envelope; otherwise emits plain markdown.
- `claude-code` — forces the hook envelope regardless of stdin shape. Use in wrapper scripts that can't pipe stdin through.
- `plain` — forces plain markdown. Useful for ad-hoc inspection or piping.

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

Events that do NOT support `additionalContext` (`Stop`, `Notification`, etc.) receive plain markdown. `bridge:inbox` still fires — cursor-dedup state (`inbox-seen.json`) is updated — but the text cannot reach model context regardless of envelope shape.

**Dedup state** is stored in `<BRIDGE_CONFIG_DIR>/state/inbox-seen.json` as a JSON array of seen `id` strings. A redelivered or re-staged line with the same `id` never re-surfaces.

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

Pick A for Claude Code agents. Pick B for cross-language consumers (TypeScript MCP servers, Bun adapters, Slack notifiers). Pick C for PHP-native services that must act synchronously in the webhook request.

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
        "matcher": "Bash",
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

On events that don't support `additionalContext` (e.g. `Stop`): wiring `bridge:inbox` there advances the cursor (marks intents seen) but text output cannot reach model context.

If nothing surfaces to the model after wiring: verify the hook event type is in the `ADDITIONAL_CONTEXT_EVENTS` list above, confirm the artisan command runs successfully from the shell, and check `storage/logs/laravel.log` for dispatch errors.

## Agent registry contract

```
<BRIDGE_CONFIG_DIR>/agents.json
```

Shape (current `schema_version: 1`):

```json
{
  "schema_version": 1,
  "agents": [
    {"name": "prod-agent", "kanban_user_id": 3,  "scope": "ops + maintenance",
     "github_login": "prod-agent-bot"},
    {"name": "dev-agent",  "kanban_user_id": 4,  "scope": "bridge dev / test workspace"}
  ]
}
```

| Field | Required? | Notes |
|---|---|---|
| `name` | yes | Used for echo suppression by `actor.name` and as the addressing token for ReactionTarget routing |
| `kanban_user_id` | optional | Integer; null = agent has no kanban identity |
| `github_login` | optional | String username; null = agent has no GitHub identity |
| `scope` | optional | Free-text description. Not load-bearing for the bridge |

Source of truth for "what agents exist + how to address them." Read-on-startup is fine; file changes only on operator action.

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
| `agents.json` | `schema_version: 1` (in file body) | Bumps on non-additive change. |

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
