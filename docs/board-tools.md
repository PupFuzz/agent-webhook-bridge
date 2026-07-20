# Two-way board tools (DL-217)

The bridge is push-only no longer. When an install enables **board tools**, an
agent gets a small, channel-identity-scoped **request/response** surface over the
same channel that already delivers wake events — so an impl seat with **no kanban
token and no toolkit** can see and capture its own board work directly.

Two tools ship in v1:

| Tool | Direction | What it does |
| --- | --- | --- |
| `board_my_cards` | read | Return YOUR own cards (your product swimlane grouped by stage, the shared cross-system swimlane when configured, and coordination cards addressed to you when the coord leg is configured). Read-proxied — the kanban token never leaves the bridge. |
| `board_create_card` | write | Create a card in YOUR OWN swimlane. The swimlane is forced from your bridge identity; you cannot target another lane. The card is born **untriaged** and surfaces to the triage pass. |

## Discovering them

If your channel server advertises tools (`BRIDGE_CHANNEL_TOOLS=1`), your MCP
client lists `board_my_cards` and `board_create_card`. The channel server's own
`instructions` string also names them. If the tools are advertised but the
channel server is only half-configured (missing `BRIDGE_TOOLS_ENDPOINT` or the
bearer), a call returns a **structured refusal naming the missing config** — it
never silently no-ops.

## `board_my_cards`

**Arguments:** none.

**Returns:**

```jsonc
{
  "board_id": 10,
  "swimlane_id": 4,
  "cards_by_stage": {
    "Backlog":  [ { "id": 1, "name": "...", "stage": "Backlog", "tags": ["..."],
                    "dl_number": "DL-1", "pr_number": null, "updated_at": "..." } ],
    "In Review": [ /* ... */ ]
  },
  "shared_swimlane": { "swimlane_id": 9, "cards_by_stage": { /* ... */ } }, // when configured
  "coord_cards": [ /* cards on the coord board carrying one of your address_tags */ ] // when configured
}
```

Read isolation is **100% bridge-enforced**. All agents on an install share one
kanban read/write user, and kanban scopes reads by that user's *board*
membership, never by swimlane — so the boundary keeping you out of another
agent's lane is your `board_tools.swimlane_id` config plus a fail-closed row
filter: every returned row is re-checked against your configured swimlane and any
non-matching row is **dropped and logged**. The upstream `swimlane_id=` search
term is efficiency + defense-in-depth, not the boundary.

## `board_create_card`

**Arguments:**

| Arg | Required | Notes |
| --- | --- | --- |
| `title` | yes | Non-empty string. |
| `description` | no | String. |
| `tags` | no | List of strings. Reserved prefixes (`created-by:`, `idem:`, `id:`, `type:`) and the bare tag `triaged` are **refused** (422) — provenance/correlation/adoption tags are bridge-stamped, and `triaged` would defeat born-untriaged. |
| `idempotency_key` | no (recommended) | `[A-Za-z0-9.-]{1,64}`. Other characters are refused (they are kanban tag-search metacharacters that could correlate the wrong card). |

**Behaviour:**

- The card is created at your configured `create_stage_id`, in your configured
  `swimlane_id` (forced — args cannot name a lane or stage), with payload `{}`.
- The bridge stamps `created-by:<you>` as the audit tag.
- **Pass an `idempotency_key`.** With one, the bridge runs the full duplicate-safe
  pattern: it correlates on `idem:<you>:<key>` *before* creating (a repeat returns
  the same card, `"idempotent_hit": true`, no second card), and after creating it
  re-reads and collapses any card a concurrent call raced in. Without a key, a
  retry (including any invisible MCP-client-layer retry) can double-create — the
  duplicate is visible via `board_my_cards` and bounded, but the key is why it
  exists.

**Returns:**

```jsonc
{ "created": true, "idempotent_hit": false, "card_id": 123,
  "board_id": 10, "swimlane_id": 4 }
```

## Errors

| Status | Meaning |
| --- | --- |
| 403 | The request did not come from loopback (network gate). |
| 401 | Missing or unrecognized bearer token. |
| 422 | A caller-fixable bad request (missing `title`, reserved tag, out-of-charset key, unknown tool). |
| 502 | Upstream kanban error (may be retryable). |
| 503 | Board tools are not fully configured on this bridge (e.g. no writeback token). |

## How it is wired (operator view)

```
agent session ──MCP tools/call──▶ channel server ──HTTP loopback + bearer──▶ bridge ──kanban token──▶ board
                                  (dumb proxy,                              (loopback gate + per-agent
                                   no board token)                          bearer + ToolRegistry)
```

- **Config:** each participating agent's YAML carries a `board_tools:` block —
  see [`docs/config-schema.md § board_tools`](config-schema.md). Absent ⇒
  byte-identical no-op.
- **Auth:** the channel server presents a per-agent bearer (`board_tools.auth.token_path`).
  The bridge resolves it to the agent (iterate-and-`hash_equals` over the roster);
  the agent name is derived from the token, never from the request. A shared/colliding
  token fails closed for *both* agents.
- **Network:** the `/agent-tools/call` route is **loopback-gated** — the TCP peer
  must be `127.0.0.0/8` or `::1`. Same-box installs call `127.0.0.1` directly;
  multi-host needs a forward SSH tunnel — see
  [`docs/multi-host.md § Board tools (two-way) forward leg`](multi-host.md#board-tools-two-way-forward-leg).
- **Preflight:** `bridge:check` probes each enabled agent's token readability,
  token collisions, swimlane/stage existence, and the service user's board
  membership.

Audit trail: one structured log line per call (agent, tool, outcome). A queryable
`tool_calls` ledger table is the named v2 upgrade if operators want it.
