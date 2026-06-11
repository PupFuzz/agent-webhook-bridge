# agent-webhook-bridge

A webhook receiver, event classifier, and CLI for AI agents (or operators) integrating with the [kanban-board](https://github.com/your-org/kanban-board) app — and any other webhook-emitting upstream following the same shape.

**Audience.** You are an AI agent (Claude, acme-pm, etc.) or an operator setting up such an agent. You want real-time visibility into kanban-board state changes reaching your agent's conversation context within seconds — without burning tokens polling.

**Status.** Pre-1.0.

---

## What this is

A single **Laravel 13 app** (PHP 8.5, MariaDB 10.6+ in prod, SQLite for tests). A webhook arrives, and receive → classify → stage → dispatch all happen **synchronously in that one HTTP request**. There is no queue worker, no consumer cron, no daemon.

```
Upstream system (kanban-board, GitHub, ...)
   │  POST /webhooks/<provider>?b=<scope>   (HMAC-signed)
   ▼
VerifyHmacSignature  →  WebhookController::receive
   │  parse envelope → EventDto
   ▼
DispatchService::dispatch
   ├─ record  webhook_events  (UNIQUE(delivery_id) dedup gate + audit store)
   ├─ for each subscribed agent:
   │    ├─ echo-suppress?  skip.
   │    ├─ classify()  → intents + targets
   │    ├─ stage intents  → inbox.jsonl
   │    └─ run handlers   (channel_push, log_intent, …)
   └─ 200 "ok"
```

At-least-once is borrowed, not built: any internal failure returns 5xx → kanban-board's webhook retry (≈11 attempts, ≈11-day envelope) redelivers. `inbox.jsonl` is the durable pull-backstop the agent reads even if no push reached it. See [`CLAUDE_ARCHITECTURE.md`](CLAUDE_ARCHITECTURE.md) for the three-way failure treatment and full package map.

## What this is NOT

- **Not a kanban-board client library.** The bridge receives events FROM kanban; it does not make general-purpose outbound calls TO kanban (except the one-time webhook subscription).
- **Not a replacement for `gh` / GitHub Actions.** The bridge surfaces GitHub webhook events into agent context; it doesn't dispatch GitHub actions.
- **Not a daemon.** Running it is just running a normal PHP-FPM web app.

## When to use this

Use the bridge when:

- The upstream system emits webhooks for the events you care about
- Those events affect state your agent reads (otherwise periodic re-poll at session start suffices)
- Your machine can expose an HTTPS endpoint (direct internet, cloudflared/ngrok, or reverse proxy)
- The reactions you'd dispatch are idempotent or have safe debounce semantics

Don't use the bridge when:

- The upstream doesn't emit webhooks (polling is correct)
- Reactions would be destructive on duplicate dispatch and can't be made idempotent
- You can't keep a public endpoint reachable
- Volume of relevant events is <1/day (operational overhead isn't justified)

## Quick start

> Detailed setup + ops guide: [`CLAUDE_DEPLOYMENT.md`](CLAUDE_DEPLOYMENT.md). What follows is the 30-second overview.

1. **Clone & install deps**:
   ```bash
   git clone https://github.com/your-org/agent-webhook-bridge.git
   cd agent-webhook-bridge && composer install
   ```
2. **Create your DB + run migrations**: configure `.env` (copy `.env.example`), then `php artisan migrate`
3. **Configure your agent identity**: copy `examples/sample-config/agent.yml.example` to `~/.config/agent-webhook-bridge/<your-agent-name>/<agent>.yml` and fill in your kanban API token, user_id, and webhook secret paths.
4. **Validate the install**: `php artisan bridge:check`
5. **Provision subscriptions**: `php artisan bridge:provision`
6. **Deploy the app**: point Apache + PHP-FPM at `public/`; no cron, no worker, no scheduler.
7. **Wire your agent hooks** (Claude Code example): see `examples/claude-code/settings.json`.

After step 7, kanban activity reaches your agent's session-start and mid-session surfaces within seconds of the webhook arriving. Edit a card via the kanban UI, start a new Claude session, see the event in your context.

## Multi-agent support

Two agents (e.g. `prod-agent` + `dev-agent`) each run their own bridge install (own webroot, own `.env`, own DB) on the same host. Per-agent YAML files under one config dir are all loaded by `SubscriptionRegistry`; a single webhook fans out to every subscribed agent independently. The agent registry — built by scanning those YAMLs' `identity` blocks (plus an optional `shared-identities.json`; there is no `agents.json`) — maps immutable user IDs to friendly agent names for echo-suppression and surface formatting. See [`docs/multi-agent.md`](docs/multi-agent.md).

## Provider support

| Provider | Status | Adapter |
|---|---|---|
| kanban-board | shipped | `app/Bridge/Adapters/KanbanAdapter.php` |
| GitHub | shipped | `app/Bridge/Adapters/GitHubAdapter.php` |

Adding a new provider means one new `WebhookAdapter` implementation and registration in `WebhookAdapterFactory`. See [`docs/provider-adapters.md`](docs/provider-adapters.md).

## Operator CLI

```bash
php artisan bridge:check        # validate install: dirs, DB, agent YAMLs
php artisan bridge:provision    # idempotent webhook subscription setup (--reconcile fixes drift)
php artisan bridge:inbox        # surface staged intents (Claude Code hook-aware)
php artisan bridge:replay       # re-dispatch a stored event (recovery for errored/missed dispatches)
php artisan bridge:inspect <N>  # pretty-print one event + its dispatch ledger
php artisan bridge:stats        # event / dispatch counts
php artisan migrate             # run DB migrations
```

## Documentation

- [`CLAUDE_ARCHITECTURE.md`](CLAUDE_ARCHITECTURE.md) — Synchronous request lifecycle, package map, at-least-once model
- [`CLAUDE_DEPLOYMENT.md`](CLAUDE_DEPLOYMENT.md) — Install + update + cutover, runtime ops (status contract, log/state locations, CLI reference, replay), diagnose
- [`docs/customization.md`](docs/customization.md) — Write your own classifier, surface formatter, handlers (PHP)
- [`docs/provider-adapters.md`](docs/provider-adapters.md) — Add support for a new webhook-emitting upstream
- [`docs/multi-agent.md`](docs/multi-agent.md) — Run parallel agents on the same bridge
- [`docs/consumer-guide.md`](docs/consumer-guide.md) — Build a downstream consumer on the bridge's event stream

## License

[MIT](LICENSE).
