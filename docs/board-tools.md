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

If your channel server advertises tools, your MCP client lists `board_my_cards`
and `board_create_card`, and the server's own `instructions` string names them.
The channel server advertises on a **tri-state** (`BRIDGE_CHANNEL_TOOLS`):

- `=1` → force ON.
- `=0` or `` (empty) → OFF (explicit opt-out).
- **unset** → advertise **iff** `BRIDGE_TOOLS_ENDPOINT` is set **and** a bearer
  resolves (`BRIDGE_TOOLS_TOKEN` / `BRIDGE_TOOLS_TOKEN_FILE`, or the
  `BRIDGE_CHANNEL_TOKEN` fallback). Wire the one endpoint line and the tools come
  on for free; a bare channel agent with no tools wiring advertises nothing.

If the tools are advertised but the channel server is only half-configured
(missing `BRIDGE_TOOLS_ENDPOINT` or the bearer — reachable under the `=1`
force-on), a call returns a **structured refusal naming the missing config** — it
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
| `tags` | no | List of strings. Reserved prefixes (`created-by:`, `idem:`, `id:`, `type:`) and the bare tag `triaged` are **refused** (422), matched **case-insensitively** — `IDEM:`/`Triaged` are rejected too, because the kanban tag search this guards is case-insensitive. Every tag must also be **printable ASCII with no tag-search metacharacter** (`"`, `*`, `_`, `%`); non-ASCII or metachar tags are refused. Provenance/correlation/adoption tags are bridge-stamped, and `triaged` would defeat born-untriaged. (A non-reserved colon such as `priority:high` is fine.) |
| `idempotency_key` | no (recommended) | `[A-Za-z0-9.-]{1,64}`. Other characters are refused (they are kanban tag-search metacharacters that could correlate the wrong card). The key is **lowercased** before use, so it correlates case-insensitively (`Report` and `report` are the same key). |

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
| 422 | A caller-fixable bad request (missing `title`, reserved tag — matched case-insensitively, out-of-charset tag/key, unknown tool). |
| 502 | Upstream kanban error (may be retryable). |
| 503 | Board tools are not fully configured on this bridge (e.g. no writeback token). |

## How it is wired (operator view)

There are **two front doors** into the same dispatch machinery, selected per agent
by `board_tools.transport` (`http`, the default | `ssh`). Both resolve the caller's
identity, then run the identical `BoardToolDispatcher` onto the shared least-privilege
writeback client — so the response body is byte-identical whichever door served it.

```
# HTTP transport (default):
agent session ──MCP tools/call──▶ channel server ──HTTP loopback + bearer──▶ bridge ──kanban token──▶ board
                                  (dumb proxy,                              (loopback gate + per-agent
                                   no board token)                          bearer + ToolRegistry)

# SSH-forced-command transport (card 4952 — no bearer, no forwarding):
agent session ──MCP tools/call──▶ channel server ──ssh stdin/stdout──▶ bridge:tools-call --agent=X ──kanban token──▶ board
                                  (spawns ssh, no command;             (identity = pinned --agent;
                                   sshd forces the command)             ToolRegistry)
```

- **Transport (`http` | `ssh`):** `http` resolves the agent by **bearer** over the
  loopback POST; `ssh` resolves it by the **pinned forced-command `--agent`** and
  carries **no bearer**. Pick one per agent (single-valued, v1). The ssh door is the
  only cross-host transport that works on a seat locked to `AllowTcpForwarding remote`
  (where the HTTP forward tunnel is blocked) — see
  [`docs/multi-host.md § Board tools (two-way) SSH-forced-command transport`](multi-host.md#board-tools-two-way-ssh-forced-command-transport-card-4952).
- **Config:** each participating agent's YAML carries a `board_tools:` block —
  see [`docs/config-schema.md § board_tools`](config-schema.md). Absent ⇒
  byte-identical no-op. A present block **defaults ON** where it can be satisfied
  (complete scope + a resolvable bearer); an unsatisfiable default block suppresses
  itself and `bridge:check` FAILs naming it (use `enabled: false` to stage silently).
- **Auth:** the channel server presents a per-agent bearer. By default that bearer
  is the agent's **channel token** (`channel.auth.token_path`) — no new credential;
  an explicit `board_tools.auth.token_path` is honored first as a deprecation alias.
  The bridge resolves the bearer to the agent (iterate-and-`hash_equals` over the
  roster); the agent name is derived from the token, never from the request. A
  shared/colliding token fails closed for *both* agents.
- **Network:** the `/agent-tools/call` route is **loopback-gated** — the TCP peer
  must be `127.0.0.0/8` or `::1`. For the same-box endpoint value (NOT simply
  "use the public hostname" — see the trap below) follow
  [§ Same-box enablement (Apache/FPM)](#same-box-enablement-apachefpm);
  multi-host needs a forward SSH tunnel — see
  [`docs/multi-host.md § Board tools (two-way) forward leg`](multi-host.md#board-tools-two-way-forward-leg).
- **Provisioning:** `bridge:provision-tools` mints each enabled **http** agent's
  bearer (0600, idempotent, collision-checked). It never edits agent YAML — for an
  agent without a `board_tools:` block it prints a paste-ready skeleton. For an
  **ssh** agent it mints no secret (the private key is host B's) — it scaffolds by
  print (the pinned forced-command line, the FIPS-approved keygen recipe, the
  `Match User` sshd drop-in, and the `sudo bridge:check` certification step).
- **Preflight:** `bridge:check` probes each enabled agent's token readability,
  token collisions, swimlane/stage existence, and the service user's board
  membership. For an **ssh** agent it also probes (offline) the pinned
  `authorized_keys` line — that it forces `bridge:tools-call --agent=X`, denies
  pty + all forwarding (outcome-based, not a `restrict` keyword match), carries a
  FIPS-approved key on a FIPS seat, and (root-gated) that `PasswordAuthentication`
  is disabled for the bridge user. `bridge:check --probe-tools=<endpoint>` exercises
  the REAL HTTP loopback+bearer path; `bridge:check --probe-tools-ssh=<user@host>`
  the REAL ssh round-trip (see the runbook below).

Audit trail: one structured log line per call (agent, tool, outcome). A queryable
`tool_calls` ledger table is the named v2 upgrade if operators want it.

## Same-box enablement (Apache/FPM)

The end-to-end runbook for the common topology: the bridge served by an Apache
vhost (`*:443`/`*:80`) proxying to PHP-FPM, with the agent's channel server on
the **same box**.

> **Multi-user box? This is a two-party runbook.** The steps below assume one
> actor owns the whole box. On a multi-user install (each agent its own OS
> user), the steps split by privilege: **step 1** (`/etc/hosts` pin or the
> loopback-port vhost) is **root's**; **steps 5 and 7** (the channel server's
> env + restart) and placing the bearer belong to the **agent's own OS user**;
> the config/mint/check steps (3, 4, 6) run as the bridge's operator user.
> Hand the sequence to the right actors up front rather than discovering the
> boundary step by step.

### 1. Pick the endpoint — the obvious value is the wrong one

`BRIDGE_TOOLS_ENDPOINT=https://<your-public-bridge-host>/agent-tools/call`
**fails the loopback gate**: DNS resolves the name to the box's public IP, and
when the kernel connects to its own public address it source-selects that
public IP — so the TCP peer the gate tests is **not** loopback, and the call is
(correctly) refused with 403. The recipe that keeps TLS verification ON:

1. Loopback-pin the bridge's own vhost name in `/etc/hosts`:

   ```
   127.0.0.1 <bridge-hostname>
   ```

2. Point the channel server at it:

   ```
   BRIDGE_TOOLS_ENDPOINT=https://<bridge-hostname>/agent-tools/call
   ```

The connection now goes to `127.0.0.1` (the gate passes), SNI/Host still name
the real vhost (Apache routes it correctly), and the certificate still matches
the hostname (no verify-off hack anywhere).

Plain `http://127.0.0.1/agent-tools/call` also works, but **only when the
bridge vhost is what answers a bare-IP Host on `:80`** — on a box with several
vhosts, a request whose Host is `127.0.0.1` lands in the *default* vhost, which
may not be the bridge.

**The loopback-port vhost (first-class alternative).** The `/etc/hosts` pin is
a box-global DNS side-effect some operators refuse, and the bare-IP form dies
on a multi-vhost box. A dedicated loopback listener sidesteps both:

```apache
Listen 127.0.0.1:8787
<VirtualHost 127.0.0.1:8787>
    DocumentRoot /path/to/bridge/public
    # same FPM proxy config as the main bridge vhost
</VirtualHost>
```

```
BRIDGE_TOOLS_ENDPOINT=http://127.0.0.1:8787/agent-tools/call
```

The port is bound to loopback only (never exposed), no DNS is touched, Host
ambiguity is impossible (the vhost is selected by the listener, not by name),
and TLS is unnecessary on a same-box loopback hop. One-time root step, same
class as the `/etc/hosts` line — pick whichever your box's policy prefers.
This is the shape the channel-server README's example env already uses.

### 2. Why the gate is proxy-safe on this topology

mod_proxy_fcgi forwards Apache's **own TCP connection peer** as `REMOTE_ADDR`
(this is not the separate-reverse-proxy-hop pattern where the app sees the proxy
as the peer). The app registers **no TrustProxies middleware**, so
`$request->ip()` returns that raw peer — a forged `X-Forwarded-For` is never
consulted, in either direction. This posture is **test-pinned**: the XFF-spoof
tests in `AgentToolsCallTest` go red the moment a `trustProxies` registration
lands.

### 3. Mint the bearer (only for a DEDICATED tools bearer)

**Default path — skip this step.** Under the default-ON model the tools bearer
reuses the agent's **channel token** (`channel.auth.token_path`), so there is
nothing to mint; point `BRIDGE_TOOLS_TOKEN_FILE` at that same channel-token file
(or omit it and let the `BRIDGE_CHANNEL_TOKEN` fallback resolve it). Run
`bridge:provision-tools` only when you want a **dedicated** tools bearer, declared
as an explicit `board_tools.auth.token_path` (the alias):

```bash
php artisan bridge:provision-tools                # all agents with an explicit board_tools.auth.token_path
php artisan bridge:provision-tools --agent=<name> # one agent; without a block, prints the paste-ready skeleton
```

Idempotent: an existing secure (0600) bearer is left alone; an insecure one is a
hard failure; a token value shared by two agents fails both by name. Agents that
reuse the channel token are skipped (nothing to mint). The token value is never
printed.

### 4. Declare the `board_tools:` block

Per agent YAML — see [`docs/config-schema.md § board_tools`](config-schema.md).
`bridge:provision-tools --agent=<name>` prints the skeleton if the block is
absent.

### 5. Configure the channel server

```
BRIDGE_CHANNEL_TOOLS=1
BRIDGE_TOOLS_ENDPOINT=<the value from step 1>
BRIDGE_TOOLS_TOKEN_FILE=<the bearer path from step 3>
```

### 6. Verify BEFORE flipping traffic

```bash
php artisan bridge:check --probe-tools=<the endpoint from step 1>
```

This exercises the real network path per enabled agent: a live `board_my_cards`
call proving the endpoint is reachable, the loopback gate admits it, the bearer
resolves to the right agent, and the returned window is scoped to that agent's
configured board/swimlane. Each failure mode names its likely cause (403 → the
step-1 trap; 401 → bearer mismatch/collision; connection refused → wrong
vhost/endpoint). Non-2xx or a scope mismatch exits non-zero.

### 7. Restart the channel server

Restart the agent's channel MCP server so it re-reads its env; the tools are now
advertised and live.
