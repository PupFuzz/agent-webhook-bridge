# Multi-agent support

Run two or more agents (e.g. `prod-agent` + `dev-agent`) on the same bridge host. Each gets its own config file, classifier, and surface formatter.

## How fan-out works

1. One HTTP POST arrives at `/webhooks/<provider>?b=<scope>`.
2. `DispatchService` calls `SubscriptionRegistry::subscribedTo(provider, scope)` — iterates every `*.yml` in the config dir, returns those subscribed to that `(provider, scope)`. Each matching agent is processed **synchronously in the same request**: classify → stage intents to `inbox.jsonl` → run handlers.
3. Each agent gets its own `agent_dispatches` row (`webhook_event_id`, `agent_name`, `processed_at`, `error_message`). Per-agent error isolation: treatment-A (classify throws) records the error on that agent's row and continues; the other agents and the 200 ack are unaffected. See `CLAUDE_ARCHITECTURE.md § The three-way failure treatment`.
4. 200 returns only after every subscribed agent is processed.

No consumer cron, no drain loop, no daemon. Drop a new `<agent>.yml` in the config dir and the next delivery to that scope fans out to it automatically.

## Agent registry (built from the YAMLs)

The agent registry maps **immutable numeric ids** — kanban `user_id` and GitHub `sender.id` — to friendly agent names so intents read "edited by prod-agent" rather than a raw integer. There is no `agents.json`: the registry is built by scanning each `<agent>.yml`'s `identity:` block (the filename is the agent's name). Matching is provider-aware: a kanban event consults `kanban_user_id`, a GitHub event consults `github_user_id`, so the same integer on different axes never cross-matches.

```yaml
# prod-agent.yml — the filename IS the name; ids live in identity:
identity:
  kanban_user_id: 3

# dev-agent.yml
identity:
  kanban_user_id: 4

# device.yml
identity:
  kanban_user_id: 53
  github_user_id: 41000123
  github_login: device-bot        # display-only label
```

`AgentRegistry::fromAgentConfigs` is built once per request from the same scanned YAMLs the `SubscriptionRegistry` already reads. `EchoSuppression` checks the agent's own name and `treat_as_echo` names against the resolved `Actor.name`; `SignalAllowlist` does the same for `treat_as_signal`.

An agent's own ids in `identity:` are auto-seeded into its echo suppression — you never hand-list your own id. If an agent has no `identity` ids: attribution for its events falls back to the raw provider id; classifiers still work; only the friendly name is missing.

`github_user_id` is optional — it's the immutable GitHub account id (`sender.id`) and the GitHub matching key; agents that don't act on GitHub can omit it. `github_login` is a **display-only label** (GitHub usernames are renameable, so they are never a matching key — see DL-002); if it goes stale the registry logs a one-line drift warning naming the current login.

When several agents share **one** upstream account, declare it once in an optional `shared-identities.json` — see [§ Shared identity across agents](#shared-identity-across-agents).

> **Cross-install peers need a local author-only YAML.** The registry is built from **this install's** config dir — there is no shared `agents.json` anymore. So if an agent names a peer that runs in a *separate* install — e.g. `treat_as_signal: [prod-agent]` or `treat_as_echo: [prod-agent]` where `prod-agent` is its own install — that peer must still have an `<peer>.yml` here so the registry knows the name and can attribute its events. Make it **author-only** (identity ids + no subscriptions), so it's never dispatched to locally:
> ```yaml
> # prod-agent.yml in the dev install — peer the dev-agent references; not run here
> identity:
>   kanban_user_id: 3
> subscriptions: []
> ```
> This matters most for `treat_as_signal`, which is **fail-closed**: a name with no matching local `<name>.yml` throws at config load (`bridge:check` catches it). Under the old shared `agents.json`, peers were globally known; per-install registries make this explicit.

## Per-agent config

```yaml
# ~/.config/agent-webhook-bridge/prod-agent.yml
# The FILENAME (prod-agent) is the agent's name and its echo "self" — no identity.self.
identity:
  kanban_user_id: 3               # this agent's own immutable upstream ids

subscriptions:
  - provider: kanban
    scopes: [5]
    event_filter: ["task.*", "comment.*", "board.created"]

classifier:
  class: App\Bridge\Classifiers\InboxOnlyClassifier
    # PHP FQCN implementing App\Bridge\Contracts\Classifier.
    # Defaults to InboxOnlyClassifier when absent.

# api (optional): per-agent token-path OVERRIDE. The token is read by convention
# from <secret_dir>/<provider>/token; the API base URLs are per-install (.env).
# api:
#   kanban:
#     token_path: ~/.kanban-dev-token

channel:
  socket: /run/user/1000/agent-webhook-bridge-channel-prod-agent.sock
    # socket (local UDS) OR url (loopback http) — mutually exclusive.

echo_suppression:
  treat_as_echo:   [dev-agent]    # OTHER agents only — your own id is auto-seeded
  treat_as_echo_ids: ["999"]      # extra raw ids to skip (rarely needed)
  treat_as_signal: [dev-agent, device]
    # Positive allowlist matched against actor.name (registry-resolved).
    # Non-empty list: only named agents reach classify; others are filtered.
    # Empty list (default): all non-echo events pass through.
    # A name with no matching <name>.yml is a hard config error (fail-closed).
```

Per-install settings are NOT in the YAML — they're the same for every agent on an install, so they live in Laravel's `.env` / `config/bridge.php`: the receiver public URL (`BRIDGE_RECEIVER_BASE_URL`), each provider's API base URL (`BRIDGE_KANBAN_API_BASE_URL`, …), and the one base dir (`BRIDGE_DIR`). The API token is read by convention from `<secret_dir>/<provider>/token`.

## Canonical reference install (two disjoint agents)

```
~/.config/agent-webhook-bridge/
├── prod-agent.yml
└── dev-agent.yml
```

No cron. No consumer. Webhooks fan out to both agents in-request. The registry is built by scanning these YAMLs' `identity` blocks — there's no separate `agents.json`. `php artisan bridge:check` validates that both YAMLs parse cleanly before live traffic arrives.

## Overlapping-subscription topologies

### When to use this

- A cross-cutting agent (PM, audit, dashboard) needs visibility into every event, while N implementation agents need scope-bounded visibility.
- A handoff pattern where a primary classifier triages and a secondary agent picks up flagged events independently.
- Any case where "event on board/repo X" must trigger reactions in more than one independently-configured agent.

If your agents subscribe to disjoint scopes (the reference install), this section adds no complexity — a single webhook just fans out to fewer agents per delivery.

### Worked example: 4-agent install (Acme PM topology)

A `pull_request.opened` on `acme-corp/acme-device` lands in **both** `pm`'s inbox (cross-cutting) **and** `device`'s inbox (scope-bounded). One HTTP POST → one `webhook_events` row → both agents processed in-request → each gets its own `agent_dispatches` row.

| Agent | Subscribes to | Dispatch outcome |
|---|---|---|
| `pm` | All 4 acme GitHub repos + kanban boards 2/3/4 | `agent_dispatches (event_id, 'pm')` |
| `device` | acme-device + acme-coordination | `agent_dispatches (event_id, 'device')` |
| `backend` | acme-backend + acme-coordination | `agent_dispatches (event_id, 'backend')` |
| `inventory` | acme-inventory + acme-coordination | `agent_dispatches (event_id, 'inventory')` |

#### File layout

```
~/.config/agent-webhook-bridge-prod/             ← BRIDGE_DIR (from config/bridge.php)
├── pm.yml                                       ← each YAML's identity block builds the registry
├── device.yml
├── backend.yml
└── inventory.yml
```

(No `agents.json` — the roster is built by scanning these YAMLs' `identity` blocks. Add a `shared-identities.json` here only when several agents share ONE upstream account — see [§ Shared identity across agents](#shared-identity-across-agents).)

HMAC secrets are keyed by `(provider, scope)` — one per upstream scope, not per agent:

```
<secret_dir>/
├── kanban/
│   ├── webhook-secret-scope-2
│   ├── webhook-secret-scope-3
│   └── webhook-secret-scope-4
└── github/
    ├── webhook-secret-scope-myorg-acme-coordination
    ├── webhook-secret-scope-myorg-acme-device
    ├── webhook-secret-scope-myorg-acme-backend
    └── webhook-secret-scope-myorg-acme-inventory
```

`inbox.jsonl` lives in the state dir (`BRIDGE_STATE_DIR`, defaulting to `config_dir/state`). By default one shared file; set `BRIDGE_INBOX_LAYOUT=per-agent` for one `inbox-<agent>.jsonl` per serving agent so each session gets a clean, independently-cursored view — see [§ Per-agent surfacing](#per-agent-surfacing-one-install-n-agents) below.

#### Per-agent YAML differences

```yaml
# pm.yml — cross-cutting; subscribes to everything. The filename IS the name.
identity: { kanban_user_id: 100, github_user_id: 41000101, github_login: pm-bot }
subscriptions:
  - { provider: kanban, scopes: [2, 3, 4], event_filter: [] }
  - { provider: github, scopes: [myorg/acme-coordination, myorg/acme-device, myorg/acme-backend, myorg/acme-inventory], event_filter: [pull_request.*, issues.*] }
echo_suppression:
  treat_as_echo: [device, backend, inventory]   # OTHER agents — pm's own ids auto-seed

# device.yml — scope-bounded; subscribes to its own repo + cross-team coord
identity: { kanban_user_id: 101, github_user_id: 41000102, github_login: device-bot }
subscriptions:
  - { provider: github, scopes: [myorg/acme-coordination, myorg/acme-device], event_filter: [pull_request.*, issues.*] }
echo_suppression:
  treat_as_echo: [pm, backend, inventory]
```

`backend.yml` and `inventory.yml` mirror `device.yml` with their own repos and `identity` ids.

#### Shared agent registry

The registry is built by scanning all 4 YAMLs' `identity` blocks. Each agent's own ids auto-seed its echo suppression; `treat_as_echo` then names the **other** agents so a `pm` write doesn't re-trigger `device`'s classifier. The `identity` ids above are what cross-attribute each agent's writes:

```yaml
# pm.yml        identity: { kanban_user_id: 100, github_user_id: 41000101, github_login: pm-bot }
# device.yml    identity: { kanban_user_id: 101, github_user_id: 41000102, github_login: device-bot }
# backend.yml   identity: { kanban_user_id: 102, github_user_id: 41000103, github_login: backend-bot }
# inventory.yml identity: { kanban_user_id: 103, github_user_id: 41000104, github_login: inventory-bot }
```

(This is the *distinct-account-per-agent* case — each agent has its own GitHub account, so attribution resolves to a name. When all agents share **one** account, declare it once in `shared-identities.json` instead — see [§ Shared identity across agents](#shared-identity-across-agents).)

#### Webhook provisioning: one subscription per scope, NOT N

**Provision one upstream webhook subscription per scope, not N subscriptions per (scope × agent).** The bridge receives one delivery and fans out in-request; duplicating the subscription multiplies upstream cost with no benefit.

Designate one agent's `bridge:provision` run as authoritative per scope. The umbrella agent (`pm`) is the natural choice because its subscription list is a superset. Running `bridge:provision` for a second agent overlapping the same scope is idempotent (the upstream deduplicates on the receiver URL), but cleaner to skip. If you accidentally double-provision, `bridge:provision --reconcile` detects and cleans drift.

#### Trade-off vs disjoint-scopes model

| Aspect | Disjoint (`prod`/`dev`) | Overlapping (this example) |
|---|---|---|
| Echo suppression | Per-agent only — agents don't see each other's writes | Per-agent + cross-agent registry — agents must know each other to avoid recursive triggers |
| Webhook subscriptions | One per scope; fewer agents process each delivery | One per scope; ALL subscribed agents process each delivery synchronously |
| `bridge:stats` queue_depth | Single value | Per-agent (each agent has its own backlog in `agent_dispatches`) |
| Operational complexity | Lower | Higher — N-1 extra agents to monitor; cross-registration discipline |

If you can decompose work into disjoint scopes, do that. The overlapping model is for cases where decomposition isn't natural.

### Per-agent error isolation

When `pm`'s classifier throws on event N, `pm`'s `agent_dispatches` row records the error (`error_message` set, `processed_at` null). The other agents' rows for the same event are unaffected. Recovery: `php artisan bridge:replay N --agent pm` re-runs the dispatch loop scoped to that agent only.

### Per-agent observability

`php artisan bridge:stats` reports per-agent counts from `agent_dispatches`. `php artisan bridge:inspect <N>` shows the full dispatch ledger for event N across all agents.

### Retention

`webhook_events` rows are the audit/replay store; there is no retention command in v0.12 — add a scheduled DB job for age-based cleanup. `agent_dispatches` rows cascade-delete with their parent `webhook_event`.

## Per-agent surfacing (one install, N agents)

A single install fans out to all subscribed agents and, by default, stages every intent to **one shared** `inbox.jsonl`. Each staged line carries the serving `agent` (distinct from `actor`, the event's author), so the same event fanned out to `pm` + `backend` + `inventory` produces three lines — and a plain `bridge:inbox` would surface that one event three times. `BRIDGE_INBOX_LAYOUT` + `bridge:inbox --agent` give each agent a clean view.

### Layout + per-agent files

```bash
# .env (the single install)
BRIDGE_INBOX_LAYOUT=per-agent     # one inbox-<agent>.jsonl per serving agent
#   shared (default) — one inbox.jsonl for all agents
#   per-agent        — state/inbox-pm.jsonl, state/inbox-backend.jsonl, …
#   both             — shared file (global tail) + per-agent files
```

```bash
# Surface only pm's unseen intents (its own file + its own seen cursor).
php artisan bridge:inbox --agent pm
php artisan bridge:inbox --agent backend
```

Each agent has its **own** seen cursor (`state/inbox-seen-<agent>.json`), so `pm` marking-seen never hides `backend`'s intents. `--agent` works under every layout — with `per-agent`/`both` it reads `inbox-<agent>.jsonl`; under `shared` it reads `inbox.jsonl` filtered by the `agent` tag.

For an install with **one primary** agent that still wants the per-agent file/cursor, set a default so a bare `bridge:inbox` targets it:

```bash
BRIDGE_DEFAULT_AGENT=pm
```

> Switching an existing install from a bare `bridge:inbox` to `BRIDGE_DEFAULT_AGENT` (or `--agent`) moves the cursor from `inbox-seen.json` to `inbox-seen-<agent>.json`, which starts empty — so already-consumed intents re-surface **once**. Harmless (no data loss), but expect one catch-up burst on the switch.

Per-agent visibility on the other commands:

```bash
php artisan bridge:stats --agent pm        # dispatch metrics + inbox line count for pm
php artisan bridge:inspect 1234 --agent pm # just pm's dispatch row for event 1234
```

### Cross-user read (co-located different-OS-user agents)

When a co-located agent runs as a **different OS user** (e.g. `backend`/`inventory` are separate users on the same box with no web server of their own), it reads its own `inbox-<agent>.jsonl` directly — no need to run the install's artisan as that user. Make the per-agent files group-readable:

```bash
# .env (the install)
BRIDGE_INBOX_LAYOUT=per-agent              # REQUIRED for cross-user (see note below)
BRIDGE_STATE_DIR=/srv/agent-bridge/state   # MUST be outside the 0700 secret config_dir
BRIDGE_INBOX_GROUP=agent-bridge            # chgrp applied to per-agent inbox files
BRIDGE_INBOX_FILE_MODE=0640                # group-readable
```

> **`per-agent` layout is mandatory with `BRIDGE_INBOX_GROUP`** — `bridge:check` refuses `shared`/`both` + a group. Reason: a group-traversable state dir under `shared`/`both` also exposes the shared `inbox.jsonl` (every agent's intents) to the group. Under `per-agent` there's no shared file. **Note** the state dir also holds `handler-log.jsonl` / `registry-*.jsonl` / `spawn-*.log` (written by handlers regardless of layout) — they're group-reachable too once the dir is traversable, so point a handler's `log_path` elsewhere if its output is sensitive. The bridge sets perms on the per-agent inbox *files* only; it never widens the directory — that's the operator's `install -d` step below (so the bridge can't silently expose a dir holding other agents' state).

```bash
# one-time operator setup (the convention the bridge relies on)
sudo groupadd agent-bridge
sudo usermod -aG agent-bridge "$INSTALL_USER"   # the PHP-FPM / artisan user
sudo usermod -aG agent-bridge backend           # each co-located reader
sudo usermod -aG agent-bridge inventory
sudo install -d -m 0750 -g agent-bridge /srv/agent-bridge/state
```

The backend agent then just tails its file:

```bash
tail -F /srv/agent-bridge/state/inbox-backend.jsonl
```

> **Why a separate `BRIDGE_STATE_DIR`?** `config_dir` is `0700` because it holds HMAC secrets and API tokens — it can't be made group-traversable without exposing those. Point `BRIDGE_STATE_DIR` at a dedicated group-readable directory. The bridge sets the file mode + group on each per-agent file (best-effort: a `chgrp` the install user isn't permitted to make is the operator's group-setup to fix). Seen-cursors stay install-user-owned; a co-located reader that wants its own cursor tracks consumption on its side (e.g. a `tail` offset).

### Remote agent → route intents over its channel

When an agent is on a **remote, firewalled host** (`device`), its intents need to reach it, not sit in a file on the install's box. Set up the SSH-tunneled channel (see [`multi-host.md`](multi-host.md)) and let the dispatcher route `device`'s intents to it automatically — no classifier code, no hand-emitted `channel_push`:

```yaml
# device.yml (on the install) — route every staged intent to device's channel
channel:
  url: http://127.0.0.1:8930/   # local end of the SSH tunnel to device's host
  route_intents: true
```

```yaml
# A co-located agent instead uses a UDS socket:
channel:
  socket: /run/user/1000/agent-webhook-bridge-channel-device.sock
  route_intents: true
```

`route_intents: true` makes the dispatcher push every staged intent to the agent's `channel.socket`/`channel.url` (best-effort — a connection-refused is recorded as a note, the durable inbox backstop still holds the intent). It's the config-driven form of `EventDrivenClassifier`; **use it OR an `EventDrivenClassifier`, not both**, or each event pushes twice. `channel.socket` and `channel.url` are mutually exclusive, and `route_intents` requires one of them.

## Multi-agent `channel_push`

Each agent needs a distinct `channel.socket` so each gets its own UDS socket:

```yaml
# pm.yml
channel: { socket: /run/user/1000/agent-webhook-bridge-channel-pm-channel.sock }
# device.yml
channel: { socket: /run/user/1000/agent-webhook-bridge-channel-device-channel.sock }
# backend.yml
channel: { socket: /run/user/1000/agent-webhook-bridge-channel-backend-channel.sock }
# inventory.yml
channel: { socket: /run/user/1000/agent-webhook-bridge-channel-inventory-channel.sock }
```

Distinct socket paths → distinct channel-server bindings. The bridge POSTs to exactly the `channel.socket` you set — it does **not** derive the path from any name.

Each agent's `.mcp.json` sets `env.BRIDGE_CHANNEL_NAME` to the name the channel server uses to derive its bind path; set `channel.socket` in the YAML to exactly that same path. Pick one name per agent matching `^[a-z0-9_-]+$` and use it for the `mcpServers.<key>` label, `BRIDGE_CHANNEL_NAME`, and the socket path.

## Shared identity across agents

Sometimes the platform forces multiple agents to authenticate under **one** account (e.g. four Claude Code agents sharing a single GitHub login because that's the only credential available). Events from that account can't be attributed to a single agent by identity alone.

### Path A — Distinct accounts per agent (preferred)

Give each agent its own account, with a distinct `kanban_user_id` / `github_user_id` in its YAML's `identity:` block. The registry resolves cleanly, friendly names work, no special handling. Only blocked when your platform genuinely requires shared credentials.

### Path B — Declare the shared account once, accept the null name

Declare the shared GitHub account **once** in an optional `shared-identities.json` in the config dir (don't repeat the id in every agent's `identity:` block):

```json
{
  "shared_identities": [
    {"github_user_id": 41000042, "github_login": "team-bot",
     "agents": ["pm", "device", "backend", "inventory"]}
  ]
}
```

The agents themselves still exist as their own `<agent>.yml` files; this file only records that they share one upstream account. Events from `github_user_id 41000042` resolve to `Actor.name = null` on purpose — the bridge can't pick one of the four agents. **Echo suppression by raw id (`treat_as_echo_ids: ["41000042"]`) still works** — and because it keys on the immutable id, it survives a username rename (`github_login` is just a display label; a stale one logs a one-line drift warning naming the current login). Recognition keys on the id, so renaming the account is a non-event — no config edit required. Acceptable when friendly-name attribution isn't load-bearing.

> The file is OPTIONAL — omit it entirely when no account is shared. Declaring the shared account is the explicit form of what the registry would otherwise infer from an *accidental* duplicate id across two agents' `identity` blocks (which it bypasses + warns about, pointing you here). Prefer the explicit `shared-identities.json` declaration — one source of truth, no N-place denormalization.

### Path C — Custom-classifier sub-resolution

When you have a recovery signal (`scope_id` in repo-distinct topologies, a `FROM:` line in the event body, or any operator-controlled disambiguator), implement resolution in a custom classifier:

```php
// MyClassifier.php — alongside <agent>.yml in your config dir
namespace YourOrg\KanbanBridge;

use App\Bridge\Contracts\Classifier;
use App\Bridge\Dispatch\Actor;
use App\Bridge\Dispatch\ClassifyResult;

class MyClassifier implements Classifier
{
    private const REPO_TO_AGENT = [
        'myorg/acme-device'    => 'device',
        'myorg/acme-backend'   => 'backend',
        'myorg/acme-inventory' => 'inventory',
    ];

    public function classify(string $eventType, array $payload, Actor $actor, string $provider, string $scopeId): ClassifyResult
    {
        $reattributed = null;
        if ($provider === 'github' && $actor->name === null && isset(self::REPO_TO_AGENT[$scopeId])) {
            // scope_id is the repo full_name for github
            $actor = $reattributed = new Actor(
                id: $actor->id,
                name: self::REPO_TO_AGENT[$scopeId],
                isKnownAgent: true,
                rawEnvelope: $actor->rawEnvelope,
            );
        }

        // ... build your intents/targets using the resolved $actor ...

        // Return the recovered author as reattributedActor so the dispatcher
        // can apply per-agent echo on it (see below). Leave it null when you
        // didn't recover one — the result is then dispatched unchanged.
        return new ClassifyResult(intents: $intents, reattributedActor: $reattributed);
    }
}
```

This composes cleanly with the shared-identity declaration (Path B): the registry deliberately yields `Actor.name = null` for the shared account, and your classifier's recovery layer adds the friendly name back from a secondary signal (repo scope here, or a `FROM:` line in the event body). The same handoff catches the accidental-duplicate-id safety net.

#### Per-agent echo on the recovered author (DL-005)

The pre-classify echo gate can only match a shared account by raw id (`treat_as_echo_ids`), which suppresses **every** agent's view of that account — there is no per-agent middle ground, because the account's `Actor.name` is null before classify runs. Returning the recovered author as `ClassifyResult::reattributedActor` closes that gap: after `classify`, the dispatcher re-runs the **same** echo check (the agent's own name + `treat_as_echo`) on the reattributed author. So:

- the event the agent **itself** authored (recovered name == its own name) is suppressed — no inbox noise, no self-react loop;
- an event a **different** shared-id agent authored (recovered name != its own name) still surfaces.

You report *who* authored the event; the dispatcher decides *is that me?* per agent — so the one classifier, running for all four agents, yields per-agent self-echo from a single shared account. Reporting the author is enough; you do not filter inside `classify` (see [`customization.md`](customization.md) § Per-agent echo for a shared upstream identity).
