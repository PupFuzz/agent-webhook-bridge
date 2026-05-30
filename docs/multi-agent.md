# Multi-agent support

Run two or more agents (e.g. `prod-agent` + `dev-agent`) on the same bridge host. Each gets its own config file, classifier, and surface formatter.

## How fan-out works

1. One HTTP POST arrives at `/webhooks/<provider>?b=<scope>`.
2. `DispatchService` calls `SubscriptionRegistry::subscribedTo(provider, scope)` — iterates every `*.yml` in the config dir, returns those subscribed to that `(provider, scope)`. Each matching agent is processed **synchronously in the same request**: classify → stage intents to `inbox.jsonl` → run handlers.
3. Each agent gets its own `agent_dispatches` row (`webhook_event_id`, `agent_name`, `processed_at`, `error_message`). Per-agent error isolation: treatment-A (classify throws) records the error on that agent's row and continues; the other agents and the 200 ack are unaffected. See `CLAUDE_ARCHITECTURE.md § The three-way failure treatment`.
4. 200 returns only after every subscribed agent is processed.

No consumer cron, no drain loop, no daemon. Drop a new `<agent>.yml` in the config dir and the next delivery to that scope fans out to it automatically.

## Shared agent registry

`agents.json` (schema v2) in the config dir maps **immutable numeric ids** — kanban `user_id` and GitHub `sender.id` — to friendly agent names so intents read "edited by prod-agent" rather than a raw integer. Matching is provider-aware: a kanban event consults `kanban_user_id`, a GitHub event consults `github_user_id`, so the same integer on different axes never cross-matches.

```json
{
  "schema_version": 2,
  "agents": [
    {"name": "prod-agent", "kanban_user_id": 3,  "scope": "ops + maintenance"},
    {"name": "dev-agent",  "kanban_user_id": 4,  "scope": "bridge dev / test workspace"},
    {"name": "device",     "kanban_user_id": 53, "scope": "device-coord cards",
     "github_user_id": 41000123, "github_login": "device-bot"}
  ]
}
```

`AgentRegistry::load` is called once per request. `EchoSuppression` checks the agent's own name and `treat_as_echo` names against the resolved `Actor.name`; `SignalAllowlist` does the same for `treat_as_signal`.

Without `agents.json`: attribution falls back to the raw provider id. Classifiers still work; echo suppression by raw id (`treat_as_echo_ids`) still works; only friendly names are missing.

`github_user_id` is optional — it's the immutable GitHub account id (`sender.id`) and the GitHub matching key; agents that don't act on GitHub can omit it. `github_login` is a **display-only label** (GitHub usernames are renameable, so they are never a matching key — see DL-002); if it goes stale the registry logs a one-line drift warning naming the current login. An outdated `schema_version` (e.g. `1`) is not parsed — the registry warns with a migration note and degrades to empty.

## Per-agent config

```yaml
# ~/.config/agent-webhook-bridge/prod-agent.yml
identity:
  self: prod-agent                # name as it appears in agents.json

api:
  kanban:
    base_url: https://kanban.example.com/api/v3
    token_path: ~/.kanban-dev-token

receiver:
  base_url: https://bridge.example.com/webhooks

subscriptions:
  - provider: kanban
    scopes: [5]
    event_filter: ["task.*", "comment.*", "board.created"]

classifier:
  class: App\Bridge\Classifiers\InboxOnlyClassifier
    # PHP FQCN implementing App\Bridge\Contracts\Classifier.
    # Defaults to InboxOnlyClassifier when absent.

channel:
  name: prod-channel              # lowercase letters/digits/underscore/hyphen
    # Drives the UDS socket path on systemd Linux.
    # Falls back to identity.self (unvalidated) when absent.

echo_suppression:
  treat_as_echo:   [prod-agent]
  treat_as_echo_ids: ["3"]        # prod-agent's kanban_user_id; safety net when agents.json is missing
  treat_as_signal: [dev-agent, device]
    # Positive allowlist matched against actor.name (registry-resolved).
    # Non-empty list: only named agents reach classify; others are filtered.
    # Empty list (default): all non-echo events pass through.
```

`db`, `secrets.base_dir`, and `classifier.module` (the Python path from v0.11) are tolerated but ignored — the DB and HMAC secret dir come from Laravel's `.env` / `config/bridge.php`. This lets you drop a v0.11 config into the config dir without editing it first.

## Canonical reference install (two disjoint agents)

```
~/.config/agent-webhook-bridge/
├── prod-agent.yml
├── dev-agent.yml
└── agents.json
```

No cron. No consumer. Webhooks fan out to both agents in-request. `php artisan bridge:check` validates that both YAMLs parse cleanly before live traffic arrives.

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
~/.config/agent-webhook-bridge-prod/             ← config_dir (from config/bridge.php)
├── pm.yml
├── device.yml
├── backend.yml
├── inventory.yml
└── agents.json                                 ← shared across all 4
```

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

`inbox.jsonl` lives in the state dir (`BRIDGE_STATE_DIR` in `.env`). By default one shared file; per-agent inboxes are possible via a custom surface formatter — see [`customization.md`](customization.md).

#### Per-agent YAML differences

```yaml
# pm.yml — cross-cutting; subscribes to everything
identity: { self: pm }
subscriptions:
  - { provider: kanban, scopes: [2, 3, 4], event_filter: [] }
  - { provider: github, scopes: [myorg/acme-coordination, myorg/acme-device, myorg/acme-backend, myorg/acme-inventory], event_filter: [pull_request.*, issues.*] }
echo_suppression:
  treat_as_echo: [pm]
  treat_as_echo_ids: ["100"]       # pm's kanban user_id

# device.yml — scope-bounded; subscribes to its own repo + cross-team coord
identity: { self: device }
subscriptions:
  - { provider: github, scopes: [myorg/acme-coordination, myorg/acme-device], event_filter: [pull_request.*, issues.*] }
echo_suppression:
  treat_as_echo: [device]
  treat_as_echo_ids: ["101"]
```

`backend.yml` and `inventory.yml` mirror `device.yml` with their own repos and user_ids.

#### Shared agent registry

All 4 agents read the same `agents.json`. Cross-registration is what lets each agent's echo suppression recognize the other agents' bot identities — without it, a `pm` write re-triggers `device`'s classifier.

```json
{
  "schema_version": 2,
  "agents": [
    {"name": "pm",        "kanban_user_id": 100, "github_user_id": 41000101, "github_login": "pm-bot"},
    {"name": "device",    "kanban_user_id": 101, "github_user_id": 41000102, "github_login": "device-bot"},
    {"name": "backend",   "kanban_user_id": 102, "github_user_id": 41000103, "github_login": "backend-bot"},
    {"name": "inventory", "kanban_user_id": 103, "github_user_id": 41000104, "github_login": "inventory-bot"}
  ]
}
```

(This is the *distinct-account-per-agent* case — each agent has its own GitHub account, so attribution resolves to a name. When all agents share **one** account, declare it once under `shared_identities` instead — see [§ Shared identity across agents](#shared-identity-across-agents).)

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

## Multi-agent `channel_push`

Each agent needs a distinct `channel.name` so each gets its own UDS socket:

```yaml
# pm.yml
channel: { name: pm-channel }
# device.yml
channel: { name: device-channel }
# backend.yml
channel: { name: backend-channel }
# inventory.yml
channel: { name: inventory-channel }
```

Socket path on systemd Linux: `/run/user/<uid>/agent-webhook-bridge-channel-<NAME>.sock`. Distinct names → distinct paths → distinct channel-server bindings.

Each agent's `.mcp.json` uses the matching name as both the `mcpServers.<key>` label and `env.BRIDGE_CHANNEL_NAME`. Keep bridge-side `channel.name` (enforces `^[a-z0-9_-]+$`) and `BRIDGE_CHANNEL_NAME` in sync — pick a conforming name once and paste it everywhere.

## Shared identity across agents

Sometimes the platform forces multiple agents to authenticate under **one** account (e.g. four Claude Code agents sharing a single GitHub login because that's the only credential available). Events from that account can't be attributed to a single agent by identity alone.

### Path A — Distinct accounts per agent (preferred)

Give each agent its own account, with a distinct `kanban_user_id` / `github_user_id`. The registry resolves cleanly, friendly names work, no special handling. Only blocked when your platform genuinely requires shared credentials.

### Path B — Declare the shared account once, accept the null name

Declare the shared GitHub account **once** under `shared_identities` (don't repeat the id on every agent entry):

```json
{
  "schema_version": 2,
  "agents": [
    {"name": "pm"}, {"name": "device"}, {"name": "backend"}, {"name": "inventory"}
  ],
  "shared_identities": [
    {"github_user_id": 41000042, "github_login": "team-bot",
     "agents": ["pm", "device", "backend", "inventory"]}
  ]
}
```

Events from `github_user_id 41000042` resolve to `Actor.name = null` on purpose — the bridge can't pick one of the four agents. **Echo suppression by raw id (`treat_as_echo_ids: ["41000042"]`) still works** — and because it keys on the immutable id, it survives a username rename (`github_login` is just a display label; a stale one logs a one-line drift warning naming the current login). Recognition keys on the id, so renaming the account is a non-event — no config edit required. Acceptable when friendly-name attribution isn't load-bearing.

> Declaring the shared account is the explicit form of what the registry would otherwise infer from an *accidental* duplicate id across two `agents[]` entries (which it bypasses + warns about, pointing you here). Prefer the explicit `shared_identities` declaration — one source of truth, no N-place denormalization.

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

The pre-classify echo gate can only match a shared account by raw id (`treat_as_echo_ids`), which suppresses **every** agent's view of that account — there is no per-agent middle ground, because the account's `Actor.name` is null before classify runs. Returning the recovered author as `ClassifyResult::reattributedActor` closes that gap: after `classify`, the dispatcher re-runs the **same** echo check (`identity.self` + `treat_as_echo`) on the reattributed author. So:

- the event the agent **itself** authored (recovered name == its `identity.self`) is suppressed — no inbox noise, no self-react loop;
- an event a **different** shared-id agent authored (recovered name != its `identity.self`) still surfaces.

You report *who* authored the event; the dispatcher decides *is that me?* per agent — so the one classifier, running for all four agents, yields per-agent self-echo from a single shared account. Reporting the author is enough; you do not filter inside `classify` (see [`customization.md`](customization.md) § Per-agent echo for a shared upstream identity).
