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

> **Not the only tool the channel server can list.** The reference channel server
> also carries a **local-exec** self-management tool, `clear_context`, on a gate
> that is **orthogonal** to `BRIDGE_CHANNEL_TOOLS` — it is advertised iff `STY` is
> set and `clear-agent.sh` is on `PATH`, and it is **never** proxied to the bridge
> (it spawns the local helper detached to clear the agent's own context). It is not
> a board tool; see the channel-server README's "Local self-management tool"
> section. The board-tool contract below is unaffected by it.

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
by `board_tools.transport` (`http` | `ssh`, the default **since v0.68.0 / DL-225**;
before v0.68.0 the default was `http`). Both resolve the caller's
identity, then run the identical `BoardToolDispatcher` onto the shared least-privilege
writeback client — so the response body is byte-identical whichever door served it.

> **⚠ Upgrading to v0.68.0:** the unset-`transport` default flipped `http` → `ssh`.
> A block relying on the old implicit `http` default must set `transport: http`
> explicitly before upgrading to keep the loopback path — otherwise it reads as `ssh`,
> the bearer stops resolving over the HTTP door, and the call fails closed (401).
> `bridge:check` warns pre-upgrade for an agent on `ssh` by the default with no
> completed ssh setup.

```
# HTTP transport:
agent session ──MCP tools/call──▶ channel server ──HTTP loopback + bearer──▶ bridge ──kanban token──▶ board
                                  (dumb proxy,                              (loopback gate + per-agent
                                   no board token)                          bearer + ToolRegistry)

# SSH-forced-command transport (card 4952 — no bearer, no forwarding; the default since v0.68.0):
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
  **ssh** agent it mints no secret (the private key is host B's) — it **prints the
  ready-to-run `provision-board-tools.py --role a|b` invocation** for each leg
  (FR #5010 §2), with this agent's params filled in (`--agent` from the config,
  `--artisan` from the install path, `--ssh-account` from `board_tools.ssh_account`).
  The static `bin/provision-board-tools.py` program owns both legs from a single
  source that cannot drift: `--role a` (root, Linux, on the bridge box) pins the
  forced-command `authorized_keys` line — the **sole** security boundary — and makes
  **no** `sshd_config` change (card 5091 retired the account-level `Match User`
  hardening; see `docs/multi-host.md § 3`); `--role b` (the calling seat, cross-platform
  python) generates
  the FIPS ECDSA P-256 key, deploys the bundled channel-server snapshot, and merges
  `.mcp.json`. The merge **force-sets the SSH tools transport keys** it owns
  (`BRIDGE_TOOLS_SSH_TARGET`/`_KEY`/`_PORT`) but only **creates the live-wake channel
  vars (`BRIDGE_CHANNEL_TRANSPORT`/`_NAME`) if absent** — a re-provision never
  overwrites an existing seat's channel transport (e.g. an HTTP live-wake fallback),
  only bootstrapping the platform default on a fresh `.mcp.json`: **`unix` on POSIX,
  `http` on Windows** (Node on Win32 rejects filesystem socket paths, so `unix` is
  unusable on a fresh Windows seat — `http` is the only working channel transport there).
  Its pubkey validator is
  a **full-line shape check** (rejects multi-line /
  CRLF pastes), superseding the prefix-only guard the old generated bash carried.
  Run the host-A line as root on the bridge box and the host-B line on the calling seat;
  a same-box Linux run hands the `.pub` path to `--role a --pubkey-from` (no paste).
  Windows host B is supported: the host-B leg is cross-platform python and the Windows
  path (`%USERPROFILE%\.ssh`, icacls-based key hardening in lieu of `chmod 600`, and a
  Win32-OpenSSH precheck that fails closed if `ssh.exe`/`ssh-keygen.exe`/`ssh-keyscan`
  are absent) was validated on a real en-US Windows 11 seat. The `ssh -i` round-trip
  (`--self-cert`) is the authoritative permission check; the icacls SID-based ACL
  assertion (refuse if the private key is readable, or its `.ssh` dir writable, by any
  principal beyond `{owner, SYSTEM, Administrators}`) is defense-in-depth. Certify
  afterward with `bridge:check --probe-tools-ssh=<user@host>`.
  **Known limitation (en-US only):** the icacls hardening matches Windows built-in
  principals (`BUILTIN\Users`, `NT AUTHORITY\SYSTEM`, …) by their **en-US account
  names**. On a **localized** Windows those print under localized names and do not
  match, so the icacls decision **refuses** (fail-closed — a spurious refuse, never an
  unsafe accept). A durable fix — resolving principals to their well-known SIDs directly
  (`LookupAccountName` / `icacls /save`) rather than through the localized-name table —
  is tracked separately.
- **Preflight:** `bridge:check` probes each enabled agent's token readability,
  token collisions, swimlane/stage existence, and the service user's board
  membership. For an **ssh** agent it also probes (offline) the pinned
  `authorized_keys` line — that it forces `bridge:tools-call --agent=X`, denies
  pty + all forwarding (outcome-based, not a `restrict` keyword match), and carries a
  FIPS-approved key on a FIPS seat. That pinned forced-command line is the **sole**
  security boundary; `bridge:check` asserts **no** sshd posture (card 5091 retired the
  account-level `Match User` hardening — see `docs/multi-host.md § 3`).
  The pinned-line check certifies the **forced-command account** — when `bridge:check`
  runs under `sudo` but that account is not `root`, set `board_tools.ssh_account` so the
  probe reads its `authorized_keys`, not the invoking root's (a configured account that
  does not resolve to an OS account **fails** rather than certify a phantom path; see
  `docs/multi-host.md § 3`).
  `bridge:check --probe-tools=<endpoint>` exercises
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

## Same-box SSH enablement — the one-shot wrapper (card 5090)

The SSH transport (`board_tools.transport: ssh`, the default since v0.68.0) is the
no-root-per-call, forwarding-uniform door. Its two legs — `--role b` on the agent's
seat, `--role a` as root on the bridge box — are documented above under **Provisioning**
and, for the cross-device topology, in
[`docs/multi-host.md`](multi-host.md). When both legs land on **one box** (the agent's
Claude seat and the bridge share the machine, each as its own OS user), the two-leg dance
plus the interstitial "make the tool readable / resolve the project dir / capture the
pubkey path / chown storage" chores collapse into a single root-run wrapper:

```bash
sudo bin/provision-board-tools-samebox.py --agent <name> --ssh-account <host-A user>
```

It orchestrates, on `127.0.0.1`:

1. **Preflight (fail-closed, before any mutation).** Validates: running as root; both OS
   users exist (`getent passwd` on the agent user and the ssh-account); the agent's
   `.mcp.json` resolves **unambiguously** under its home (→ `--project-dir` + the
   `mcpServers` key → `--channel-name`); both checkouts' `provision-board-tools.py` and
   the host-A `artisan` are present; and `php` is on PATH. Every failure names its fix; no
   step is silently skipped.
2. **`--role b` as the agent user**, from the **agent's own checkout**
   (`sudo -H -u <agent> python3 <agent-checkout>/bin/provision-board-tools.py --role b …`),
   with `--ssh-target <ssh-account>@127.0.0.1`. It captures the printed public-key path and
   validates it exists + is readable (no `--self-cert` yet — the key is not pinned on host
   A until step 3).
3. **`--role a` as root**, from the **host-A checkout**, pinning that captured key by path
   (`--pubkey-from`, no paste).
4. Prints the one unavoidable **manual step**: restart the agent's Claude session so the
   channel re-spawns and reads the merged `.mcp.json`.
5. Certifies with `php <host-A artisan> bridge:check`.
6. `chown -R <ssh-account>:<ssh-account>` on the host-A `storage/` (a root-run `artisan`
   can leave root-owned logs).

`--dry-run` runs the read-only preflight and prints the exact argv for both legs without
changing anything. Overrides — `--agent-home`, `--agent-bin`, `--hostA-checkout`,
`--project-dir`, `--channel-name` — pin any value discovery can't (or shouldn't) infer,
e.g. an agent with several `.mcp.json` under its home. Re-running is safe: the underlying
`--role a`/`--role b` are idempotent (append-or-verify `authorized_keys`, create-if-absent
`.mcp.json` merge, skip-if-present keygen) and the wrapper adds no non-idempotent state.

**Why no global `bin/` staging (version isolation).** The wrapper runs **each agent's own
checkout's** `provision-board-tools.py` for its leg — the agent user runs the agent's
version, root runs the host-A install's version. It deliberately does **not** copy the tool
into a shared path such as `/usr/local/bin`: two agents on one host can be pinned to
**different bridge versions**, and a single shared global path would let a redeploy of one
clobber the other. If the agent's own copy is missing, or not readable by the agent user,
the wrapper **fails with an actionable message** telling the operator to give the agent its
own checkout — it never falls back to a shared/global copy. (Contrast the cross-device
flow, where each host trivially has its own checkout; on a shared box that separation must
be asserted, which is what the preflight does.)
