# Multi-host channel_push: bridge on one machine, Claude Code on another

When the bridge and your Claude Code session run on different machines — bridge on a public webhook-receiving host (A), Claude Code on a firewalled workstation (B) — wire `channel_push` via SSH reverse tunnel.

If both run on the same host, use the [Unix domain socket transport](../examples/channel-servers/README.md) instead — simpler, more secure, no tunnel-lifecycle complexity. This runbook is only for the cross-machine case.

## Topology

```
┌──────────────────────────────────────┐                  ┌───────────────────────────────────────┐
│  Host A — bridge (Laravel)           │                  │  Host B — your workstation            │
│  (public webhook receiver;           │                  │  (Claude Code interactive session +   │
│   synchronous in-request dispatch)   │                  │   channel MCP server child)           │
│                                      │                  │                                       │
│   webhook arrives → classify →       │                  │   B initiates outbound SSH:           │
│   channel_push fires in-request;     │                  │   autossh -M 0 -N \                   │
│   payload={"url":                    │   ◄──────────────│     -R 127.0.0.1:8788:127.0.0.1:8788\ │
│      "http://127.0.0.1:8788/"}       │  reverse tunnel  │     bridge-user@host-A                │
│                                      │                  │                                       │
│   POST hits A's loopback :8788       │                  │   Tunnel terminates on B's loopback  │
│   → SSH tunnel forwards to B's       │                  │   :8788 → channel MCP server         │
│   loopback :8788                     │                  │   receives → mcp.notification()      │
└──────────────────────────────────────┘                  └───────────────────────────────────────┘
```

## Threat model

- **Outbound from B only**: firewall permits no inbound. At the *network* layer B is always the active party (it opens the SSH connection). Note the application-layer exception introduced by the two-way board tools (DL-217): a `tools/call` travels B→A (the channel server on B calls the bridge on A), so that leg needs its OWN forward tunnel — see [§ Board tools (two-way) forward leg](#board-tools-two-way-forward-leg) below. The channel-push wake path remains A→B as drawn.
- **SSH key pair**: wire is encrypted; auth is public-key. The bridge-user account on A is the SSH endpoint.
- **Bearer token (defense-in-depth)**: the bridge POSTs `Authorization: Bearer <token>`. The channel server on B validates it. SSH protects the wire; the token protects against same-host compromise on A.
- **No durable-delivery guarantee**: when the tunnel is down, the bridge gets `connection refused` and records the dispatch as done-with-note. **Always pair `channel_push` with `Intent` emission** in your classifier so `php artisan bridge:inbox` surfaces the event next session — see [`docs/customization.md § channel_push`](customization.md) and [`CLAUDE_DECISIONS.md`](../CLAUDE_DECISIONS.md).

## Prerequisites

- Bridge installed and running on host A (see [`CLAUDE_DEPLOYMENT.md`](../CLAUDE_DEPLOYMENT.md)).
- Claude Code running on host B with the [reference channel server](../examples/channel-servers/README.md) configured for **HTTP** transport (not UDS — the cross-host case requires TCP for the SSH tunnel terminus).
- SSH access from B to A via public-key auth (no password prompts).
- `autossh` installed on host B (`apt install autossh` or `brew install autossh`).
- **Node ≥ 20 + npm on host B, with a reachable registry or a warm cache.** The channel
  server runs on Node 20; `provision-board-tools.py --role b` deploys the bundled snapshot
  and runs `npm ci` in it, and it refuses BEFORE mutating anything if Node 20 is absent.

> **Leave `channel.server_path` unset in this topology.** That key (DL-229) lets `bridge:check` validate the deployed channel-server snapshot, but it `stat`s the directory from the bridge process — which here runs on host A while the channel server lives on host B. A host-B path declared on host A resolves to nothing and reports the dangling FAIL ("repoint the symlink") for a deployment that is perfectly healthy, just on the other machine; the probe cannot tell the two apart. Unset, `bridge:check` reports the snapshot legs at severity **`unvalidated`** and counts them in the run's closing tally (card 5170) — that is an accurate statement about *this* host, not a complaint: it is **not a failure and not a warning**, it exits 0, and there is nothing here to fix. It exists so a green `bridge:check` on host A is never mistaken for a validated host-B snapshot. Snapshot drift on host B stays a host-B concern — reconcile it there by diffing against the reference (see [`../examples/channel-servers/README.md`](../examples/channel-servers/README.md) § Staying in sync). **What you CAN certify on host B: that the deployment will launch.** Copy `bin/check-channel-snapshot.py` there (it is stdlib-only, self-contained, and reads no bridge config or checkout) and run `python3 check-channel-snapshot.py <deployed dir>` **as the OS user whose Claude Code session starts the server**, before a session starts — exit 0 launch OK, 1 launch FAILED with node's own stderr, 2 could not check. This is the topology that makes the seat-side design obvious rather than merely correct (DL-237): host A cannot even `stat` the deployment, let alone run its node. It does **not** close the staleness gap — host A still has no way to compare host B's version, and this tool deliberately makes no claim about it.

## Setup

### 1. On host B — pick a port and generate a token

```bash
# A port no other service on either host uses. 8788 is the channels reference default.
export BRIDGE_CHANNEL_PORT=8788

# A long-random shared secret. Both .mcp.json (server side) and the bridge's
# classifier (client side) need this exact value. Generate it straight into a
# 0600 file you own — never onto stdout. (Same shape as host A's
# channel.auth.token_path.)
TOKEN_FILE="$HOME/.config/agent-webhook-bridge/secrets/channel/<agent>-token"
( umask 077
  mkdir -p "$(dirname "$TOKEN_FILE")"
  openssl rand -base64 48 | tr -d /=+ | head -c 64 > "$TOKEN_FILE" )

# For the later steps in THIS shell only (the value never reaches a terminal).
export BRIDGE_CHANNEL_TOKEN="$(cat "$TOKEN_FILE")"
```

> ⛔ **Do not print this token, and do not let an AI agent run this step.** `cat`-ing it — or generating it onto stdout — puts a live token into a scrollback, a shell log, or an agent's session transcript, and the only repair for that is regenerating it. You need the value twice: step 2 pastes it into `.mcp.json` here on B, and host A holds it in a `0600` file of its own (step 5's `channel.auth.token_path`). Read `$TOKEN_FILE` yourself, in an editor at your own terminal, and carry it to A on the channel you already trust for keys. The general rule is [`docs/config-schema.md § Handling a secret VALUE`](config-schema.md#handling-a-secret-value-not-just-its-file).

> **Distinct port per seat on a shared box.** `BRIDGE_CHANNEL_PORT` defaults to `8788`
> fleet-wide, so a **second** `http`-transport channel server on the same host collides on
> that port. The collision is loud and fail-closed — the second server refuses to start,
> writes an `EADDRINUSE` `.FAILED` marker, and exits — but a multi-seat box (e.g. two agents
> on one Windows machine) must give each seat a distinct `BRIDGE_CHANNEL_PORT` to run both.

### 2. On host B — configure Claude Code for HTTP transport

Drop `.mcp.json` in your Claude Code project root:

```json
{
  "_comment": "Multi-host channel_push: HTTP transport on loopback; SSH tunnel from host B to host A makes A's loopback:8788 reachable. Token gating defense-in-depth.",
  "mcpServers": {
    "agent-webhook-bridge": {
      "command": "node",
      "args": ["/abs/path/to/examples/channel-servers/agent-webhook-bridge-channel.mjs"],
      "env": {
        "BRIDGE_CHANNEL_TRANSPORT": "http",
        "BRIDGE_CHANNEL_PORT": "8788",
        "BRIDGE_CHANNEL_NAME": "agent-webhook-bridge",
        "BRIDGE_CHANNEL_TOKEN": "REPLACE_WITH_TOKEN_FROM_STEP_1"
      }
    }
  }
}
```

Start Claude Code with the channels research-preview flag:

```bash
claude --dangerously-load-development-channels server:agent-webhook-bridge
```

Expected startup log:

```
[agent-webhook-bridge] listening on http://127.0.0.1:8788
[agent-webhook-bridge] bearer-token gating active
```

### 3. On host B — start the SSH reverse tunnel

Single-shot for testing:

```bash
ssh -N -R 127.0.0.1:8788:127.0.0.1:8788 bridge-user@host-A
```

For persistent setup, use `autossh` with a systemd user unit (`~/.config/systemd/user/agent-webhook-bridge-tunnel.service`):

```ini
[Unit]
Description=SSH reverse tunnel: agent-webhook-bridge channel_push from host A → host B
After=network-online.target
Wants=network-online.target

[Service]
Type=exec
Environment=AUTOSSH_GATETIME=0
Environment=AUTOSSH_PORT=0
ExecStart=/usr/bin/autossh -M 0 -N \
  -o ServerAliveInterval=30 \
  -o ServerAliveCountMax=3 \
  -o ExitOnForwardFailure=yes \
  -R 127.0.0.1:8788:127.0.0.1:8788 \
  bridge-user@host-A
Restart=always
RestartSec=10

[Install]
WantedBy=default.target
```

Enable + start:

```bash
systemctl --user daemon-reload
systemctl --user enable --now agent-webhook-bridge-tunnel.service
systemctl --user status agent-webhook-bridge-tunnel.service
```

### 4. On host A — restrict the bridge-user SSH key

Add the public key from host B to the bridge-user `~/.ssh/authorized_keys` on A. **Restrict it to ONLY the reverse-forward**:

```
command="echo 'tunnel-only key; no shell access'",no-pty,no-X11-forwarding,no-agent-forwarding,no-port-forwarding,permitopen="127.0.0.1:8788" ssh-ed25519 AAAA... bridge-tunnel@host-B
```

`command=...` prevents shell access. **⚠ Correction (DL-217 review):** the `permitopen`/`no-port-forwarding` line above is WRONG as written. Per the OpenSSH `sshd` man page, `no-port-forwarding` forbids **all** client-requested forwarding — both `-L` **and** `-R` — and `permitopen` cannot re-grant what `no-port-forwarding` denies. As written, the reverse tunnel this page depends on likely **cannot establish at all** (prod is same-host, so the multi-host page has plausibly never been field-exercised). The correct restriction is to **drop `no-port-forwarding`** and scope with the positive allow-lists instead:

```
command="echo 'tunnel-only key; no shell access'",no-pty,no-X11-forwarding,no-agent-forwarding,permitlisten="127.0.0.1:8788",permitopen="127.0.0.1:8787" ssh-ed25519 AAAA... bridge-tunnel@host-B
```

- `permitlisten="127.0.0.1:8788"` scopes the **reverse** (`-R`) tunnel that carries A→B channel pushes.
- `permitopen="127.0.0.1:8787"` scopes the **forward** (`-L`) tunnel the two-way board tools (DL-217) need for the B→A `tools/call` (point it at A's bridge HTTP port). Omit it if you are not enabling board tools.
- **⚠ FIPS key-algorithm caveat (card 4952):** the `ssh-ed25519 AAAA...` above is illustrative for a **non-FIPS** host. A FIPS-mode sshd **rejects any ed25519 auth key** (ed25519 is not a FIPS-approved algorithm), so on a FIPS seat this wake key must also be **ECDSA P-256** — generate it with `ssh-keygen -t ecdsa -b 256` and paste that public key instead. The restriction tokens are unchanged; only the key algorithm differs.

Verify the tunnels actually come up (a `no-port-forwarding` key silently refuses them); this correction's prescriptions are exercised for real when the multi-host leg is first built (prod today is same-host).

Verify:

```bash
# Should print the override message and exit, NOT open a shell:
ssh bridge-user@host-A
# tunnel-only key; no shell access
```

### 5. On host A — configure the bridge classifier

The classifier POSTs to `127.0.0.1:8788` (the local tunnel endpoint) with the same `BRIDGE_CHANNEL_TOKEN` as the channel server on B.

See [`docs/customization.md`](customization.md) for the full classifier API. The load-bearing piece is the `channel_push` target's `url` and `headers` keys:

```php
<?php
// app/Bridge/Classifiers/MyClassifier.php (placed in the bridge install)

namespace App\Bridge\Classifiers;

use App\Bridge\Contracts\Classifier;
use App\Bridge\Dispatch\ClassifyContext;
use App\Bridge\Dispatch\ClassifyResult;
use App\Bridge\Dispatch\Intent;
use App\Bridge\Dispatch\ReactionTarget;

class MyClassifier implements Classifier
{
    public function classify(ClassifyContext $ctx): ClassifyResult
    {
        if ($ctx->eventType !== 'card.updated') {
            return new ClassifyResult;
        }

        // Intent: the durable inbox-feeding backstop. ALWAYS emit one for
        // events that must reach the agent — channel_push is only a
        // live-push optimization for active sessions. The silent-drop guard
        // catches this misconfig.
        $intent = new Intent(
            kind: 'card_updated',
            subjectId: "card:{$ctx->payload['id']}",
            provider: 'kanban',
            actor: $ctx->actor,
            summary: "card {$ctx->payload['id']} updated",
            payload: $ctx->payload,
        );

        // Read the token from an env var or file; never hardcode.
        $token = env('BRIDGE_CHANNEL_TOKEN');

        return new ClassifyResult(
            intents: [$intent],
            targets: [
                // Live-push to the remote Claude Code session via SSH tunnel.
                ReactionTarget::make(
                    handler: 'channel_push',
                    targetId: $intent->subjectId,
                    debounceSeconds: 0,
                    payload: array_merge($intent->toArray(), [
                        'url' => 'http://127.0.0.1:8788/',
                        'headers' => ['Authorization' => "Bearer {$token}"],
                        'timeout_seconds' => 2.0,
                    ]),
                ),
            ],
        );
    }
}
```

Register in `<agent>.yml` (the filename is the agent name — no `identity.self`):

```yaml
classifier:
  class: App\Bridge\Classifiers\MyClassifier
# ... rest of your agent config
```

### Simpler alternative — `channel.route_intents` (no classifier code)

Skip the custom classifier entirely and let the dispatcher route every staged intent to the tunnel. Add `channel.auth.token_path` and the routed push carries the same `Authorization: Bearer <token>` the classifier form sets by hand — so the no-code path works even when the Bearer token is a hard requirement (a cross-user or multi-tenant host where loopback-bind is **not** the trust boundary), not only when it's defense-in-depth:

```yaml
# <agent>.yml — route intents to the local tunnel endpoint automatically
classifier:
  class: App\Bridge\Classifiers\InboxOnlyClassifier   # or EventDriven — its hand-emit self-suppresses under route_intents (DL-208)
channel:
  url: http://127.0.0.1:8788/   # local end of the reverse tunnel to host B
  auth:
    token_path: ~/.config/agent-webhook-bridge/secrets/channel/<agent>-token   # chmod 600
  route_intents: true
```

The dispatcher then pushes each intent (best-effort; a down tunnel is a recorded note, and the inbox backstop still holds it) — see [`multi-agent.md` § Per-agent surfacing](multi-agent.md#per-agent-surfacing-one-install-n-agents).

`token_path` is a file (never an inline secret), holding exactly the `BRIDGE_CHANNEL_TOKEN` the channel server validates. The bridge reads it **fail-closed at push time**: the file must exist, be non-empty, and be `chmod 600` (not group/world-readable) — on a multi-user host the token *is* the trust boundary, so a readable token file is no boundary at all. `bridge:check` warns at preflight; a bad token file makes the routed `channel_push` error (recorded note; the inbox backstop still holds the intent) rather than push unauthenticated. The token rides the `Authorization` header only — it is never written to `inbox.jsonl` or the dispatch ledger. It is applied only when the endpoint comes from this agent's `channel` config; a classifier that emits its own `url` must attach its own `headers` (the agent's token is not injected onto an endpoint it wasn't minted for).

> Use the **classifier form** above instead only when you need a non-Bearer scheme or a per-delivery header the config path doesn't model.

## Smoke test

1. On host B, run `/mcp` in the Claude Code session. Verify `agent-webhook-bridge` shows as connected.
2. On host B, watch the channel server's stderr (Claude Code debug log at `~/.claude/debug/<session-id>.txt`).
3. On host A, trigger a webhook (real or simulated). The bridge classifies synchronously in-request and `channel_push` fires immediately — POSTing to `127.0.0.1:8788`. The tunnel forwards to B's loopback; the channel server emits `notifications/claude/channel` to Claude Code. **The tunnel must be up when the webhook arrives** — there is no deferred drain step.

Direct tunnel test (bypasses the bridge):

```bash
# From host A. The bearer rides curl's stdin config, NOT its argv: an argument
# is visible in /proc/<pid>/cmdline to every local account while curl runs.
# -i prints the response HEAD; a bare curl shows only the body.
printf 'header = "Authorization: Bearer %s"\n' "$BRIDGE_CHANNEL_TOKEN" |
curl -i -X POST -H "Content-Type: application/json" \
  -d '{"intent": {"kind": "smoke_test", "target_id": "manual_curl"}}' \
  --config - http://127.0.0.1:8788/
```

Expected (the `-i` is what prints the first two): HTTP **202**, an `X-Channel-Delivery-Receipt: none` header and a body reading `forwarded — accepted by transport (unconfirmed): …` — the write reached the stdio transport on host B, which returns no receipt that the session received it. The Claude Code session on host B receives `<channel source="agent-webhook-bridge" kind="smoke_test" target_id="manual_curl">...</channel>` within seconds.

## Operator action by failure mode

| Symptom | Likely cause | Action |
| --- | --- | --- |
| `curl: connection refused` from host A | SSH tunnel down (autossh restarting, network partition, host B asleep) | Check `systemctl --user status agent-webhook-bridge-tunnel` on B; verify autossh process; restart unit if needed |
| `curl` returns `401 unauthorized` | `BRIDGE_CHANNEL_TOKEN` mismatch between `.mcp.json` (server) and classifier (client) | Compare the two **digests** (`sha256sum`), never the values — see [`docs/config-schema.md § Handling a secret VALUE`](config-schema.md#handling-a-secret-value-not-just-its-file); regenerate + redeploy if either rotated |
| `curl` returns 200 but Claude Code shows nothing | Channel server isn't bound (Claude Code session closed) OR `--dangerously-load-development-channels` flag missing | Run `/mcp` in the Claude Code session; check `~/.claude/debug/<session-id>.txt` for spawn errors |
| Bridge logs `process_error` constantly | Tunnel is up but channel server crashed | Restart the Claude Code session on B (the server dies and respawns with the session) |
| `connection refused` only sometimes | Tunnel flapping during autossh reconnect | Standard. The Intent emission still feeds `php artisan bridge:inbox` for next-session catch-up — and the silent-drop guard warns if the classifier ever emits the push WITHOUT that paired Intent |

## Board tools (two-way) forward leg

The channel-push wake path drawn above is A→B (the bridge pushes; the channel
server surfaces). The two-way board tools (DL-217) reverse the direction for the
call itself: an agent invokes one of the board tools (`board_my_cards` /
`board_create_card` / `board_correct_card` / `board_take_card`), the channel
server on B forwards `{tool, args, client_version}` to the bridge on A over HTTP,
and the bridge replies. That B→A call does **not** ride the existing `-R` reverse tunnel (which
only carries A→B pushes) — it needs its OWN **forward** (`-L`) tunnel that
terminates on A's already-open sshd, so there are still zero inbound firewall
holes:

```bash
# On host B, alongside the -R wake tunnel: forward B's loopback :8787 to A's bridge port.
ssh -N -L 127.0.0.1:8787:127.0.0.1:8787 bridge-user@host-A
```

Then point the channel server's `BRIDGE_TOOLS_ENDPOINT` at the local end
(`http://127.0.0.1:8787/agent-tools/call`) and set `BRIDGE_TOOLS_TOKEN` (or
`BRIDGE_TOOLS_TOKEN_FILE`). On A, the board-tools ingress is loopback-gated: the
`-L` tunnel terminates on A's loopback, so the bridge sees the peer as
`127.0.0.1` and admits it, then the per-agent bearer authenticates the call. The
`authorized_keys` restriction that permits this leg is the `permitopen=` line in
[§ 4](#4-on-host-a--restrict-the-bridge-user-ssh-key) above — and remember
`no-port-forwarding` must be **dropped** for either tunnel to establish.

> Same-box installs (the prod topology today) need none of this — the channel
> server calls `http://127.0.0.1:<bridge-port>/agent-tools/call` directly. This
> forward-tunnel leg is exercised for real only when the first genuinely
> multi-host board-tools seat is built.

## Board tools (two-way) SSH-forced-command transport (card 4952)

The forward-leg above carries the B→A `tools/call` over an HTTP loopback that a
`-L` **forward** tunnel terminates on A. That requires the bridge-user key to
permit forwarding (`permitopen=`). **On a seat locked to `AllowTcpForwarding
remote`** (a common hardening — a `Match User <bridge-user>` drop-in that permits
only the `-R` wake tunnel), `-L` forward tunnels are **blocked**, so the
HTTP-loopback board-tools path literally cannot run. The **SSH-forced-command
transport** is the alternative: the `tools/call` rides the ssh channel's own
stdin/stdout — **no forwarding at all** — so it is the only cross-host board-tools
transport that works on an as-hardened `AllowTcpForwarding remote` seat. (Same-box
installs also gain a no-root, no-vhost path.) It is a **distinct** key from the `-R`
wake key: a forced-command key and a `permitlisten`-tunnel key are mutually
exclusive on one key.

The bridge exposes it as the `bridge:tools-call` console command; the channel
server on B spawns `ssh` (with **no** command — sshd substitutes the pinned one),
writes `{tool, args, client_version}` to its stdin, and reads the single JSON
envelope from its stdout.

### Setup — run the setup packet, then hand out its steps

Do not follow a hand-written step list for this transport. `php artisan
bridge:provision-tools --agent=<agent>` (with the agent's `board_tools.transport: ssh`
block present) prints the **BOARD-TOOLS SETUP PACKET** for that agent — five numbered
steps, each marked with the actor who runs it, with this install's own account, artisan
path, script path, storage path and git ref already filled in:

| step | actor | what it does |
| --- | --- | --- |
| 1 | impl agent, on its seat | `provision-board-tools.py --role b` — generates the FIPS ECDSA P-256 key, deploys the channel snapshot, merges `.mcp.json`; posts its PUBLIC key line and keeps the printed `Fingerprint:` line visible |
| 2 | PM agent, on host A | saves that key line to a file (quoted heredoc or a file-write tool — never a shell one-liner), re-runs the command with `--host-a` + `--pubkey-from`, then **STOPS** |
| 3 | **operator (a human)** | pins the forced-command line with `--role a` (§ 3 below is what that line IS and why it is the only boundary) |
| 4 | impl agent, on its seat | `--role b --certify-only` — one real ssh round-trip using the target and key its own `.mcp.json` recorded; then starts its session |
| 5 | PM agent, on host A | `bridge:check` — the agent's NEXT STEPS line clears once step 4's call is on the ledger |

Who each actor is, why step 3 is a **process** control rather than a mechanism, and how
the key line and fingerprint are handed over, are owned by
[`docs/board-tools-enablement.md`](board-tools-enablement.md) — read it once, then let the
packet supply the steps. The packet mutates nothing, so re-running it as values become
known is free.

**Where the seat's key comes from.** `--role b` generates it: **ECDSA P-256, never
ed25519** — a FIPS sshd rejects ed25519, so a pinned ed25519 key would never authenticate
and `bridge:check` FAILs one on a FIPS seat. `--ssh-key` names an EXISTING pair to use
instead (both halves must already be on disk; the flag never generates one).

**Certifying, and what a green `bridge:check` here does and does not mean.** Step 4's
`--certify-only` is the seat's own proof. On host A:

```bash
# Reading a forced-command account's 0600 authorized_keys needs root when it is not the
# invoking account; run once as root (with board_tools.ssh_account set) to certify offline.
sudo bridge:check                                   # offline: pinned line + FIPS key
bridge:check --probe-tools-ssh=<bridge-user>@host-A # live round-trip (from a host that can reach A)
```

`bridge:check` fails if the pinned line grants a pty/forwarding or if a FIPS seat's key is
ed25519; it asserts **no** sshd posture (card 5091 retired the account-level hardening —
see § 3). Run where it cannot read the forced-command account's `authorized_keys`
(unprivileged, distinct account) it emits an explicit **UNVERIFIED** finding for that leg
(never a false OK) — at severity `unvalidated` since DL-251, so it renders plain and joins
the run's closing tally: an insufficient euid means the leg could not measure, not that the
pinned line is wrong. Under `sudo` with a distinct forced-command account, set
`board_tools.ssh_account` (§ 3) so the pinned-line check certifies that account, not root.
⛔ **`--probe-tools-ssh` run from host A is not evidence about the SEAT** — it stamps the
same ledger row the seat's own call would (DL-229; `docs/board-tools-enablement.md`
§ *Not automated, and why*).

> Live-fire rides the witnesses (aimla same-box + sola cross-host+FIPS). A FIPS sshd's
> `restrict` behavior is reasoned from the OpenSSH man page, confirmed on a real FIPS
> seat when sola's seat fires.

### 3. The security boundary — the forced-command key (no sshd drop-in)

The **sole** security boundary for the board-tools SSH transport is the pinned
forced-command `authorized_keys` line the packet's STEP 3 pins: sshd substitutes
`bridge:tools-call --agent=<agent>` for whatever the key-holder sends, and the
enumerated `no-pty,no-agent-forwarding,no-X11-forwarding,no-port-forwarding` flags
deny an interactive shell and every forwarding channel regardless of how sshd is
otherwise configured. `provision-board-tools.py --role a` writes **only** that line;
it makes **no** change to `sshd_config`.

The pinned command is wrapped in `timeout -k 10 <n>` (default `n`=300s), so each
invocation is bounded at the **command level** — `command="timeout -k 10 300 php
<artisan> bridge:tools-call --agent=<agent>"`. This is the **key-scoped** replacement
for the retired account-level idle backstop (card 5092): a hung or deliberately-stalling
key-holder is reaped after `n` seconds (SIGTERM, then SIGKILL 10s later), and — unlike an
sshd `ClientAlive`/`Match` directive — it binds only THIS key, never the operator's own
interactive logins on a shared account. Tune with `--forced-command-timeout <seconds>`
(`0` disables it). Existing pinned keys keep their current command until re-provisioned —
re-run `--role a` for the same agent after removing the old `authorized_keys` line to
upgrade an already-pinned key to the bounded form.

**Who runs the pin, and whether it needs `sudo` (card#8971).** `--role a` writes an
`authorized_keys` file, and root is required only to write **another account's**. When the
forced-command account IS the account running the command — the common case, where the
bridge's own user is the ssh account — it runs with **no `sudo`**: the account can already
write its own `authorized_keys` with a text editor, so demanding root buys no boundary and
spends a privileged window. ⛔ **Every other non-root combination is still refused by
name.** The packet works this out for you (it compares the account's uid against this
process's euid, and takes the `sudo` form whenever either is unknown) and prints the exact
line to run.

**`--expect-fingerprint` is a transcription guard, not a checkpoint.** `--role b` prints a
`Fingerprint: SHA256:…` line beside the public key; the operator reads it **on the seat**
and passes it to `--role a --expect-fingerprint`, which refuses on a mismatch and prints
BOTH values (they are public). ⛔ **It does not make the pin safe** — anyone holding the
`.pub` can compute it. What makes the pin safe is a person deciding the key is that seat's;
[`docs/board-tools-enablement.md`](board-tools-enablement.md) states that plainly.

**Symlinks and ownership.** `--role a` opens **two** directories — `~<account>` and the
`.ssh` inside it — each **once**, each `O_NOFOLLOW`, and does every later
`chmod`/`chown`/`mkdir`/`open` through those descriptors: `.ssh` is opened or created
*relative to* the home fd, and `authorized_keys` relative to the `.ssh` fd. So **no syscall
after those two opens re-resolves a name** whoever controls `~<account>` could move
underneath it. A symlinked `~/.ssh` fails its open, and the two arms answer differently on
purpose: the **root arm refuses** it by name (root acting through a link a lower-trust
account controls is the hazard — pin into the real directory, or make `~/.ssh` a real
directory owned by the account), while the **self-account arm resolves the link first** and
keeps working, because there the process IS the account and following its own link is its
own choice. Dotfiles topologies stay legal on the self-account arm. A symlinked
`authorized_keys` is **refused in both arms** — the open is `O_NOFOLLOW`, because writing
through it would put an ssh key line into whatever the link points at, **and a hardlinked
`authorized_keys` is refused on the root arm**: `O_NOFOLLOW` has no link to decline to
follow there, so the root arm `fstat`s the opened file and refuses a link count above one
rather than chmodding, chowning and appending onto an inode that carries another name.
⚑ That refusal does **not** read `fs.protected_hardlinks` — it holds whether or not the
sysctl is on — and the self-account arm does not take it, since the file is the account's
own.

⛔ **The two descriptors are asked DIFFERENT ownership questions, and the home's is the
stricter one.** On the root arm the **home** must be the account's **own real directory**:
`fstat` on the home fd, refuse unless it is owned by the account, and a symlinked, missing
or non-directory home is one named refusal. **Root-owned is not accepted for the home** —
unlike `.ssh`, where the root arm `fstat`s the fd and allows **the account or root** (sshd's
own StrictModes rule), because a `~/.ssh` created once under `sudo` is ordinary and, with
the home already pinned to the account's own directory, a root-owned `.ssh` can only be one
that lives there. ⭐ **This is one rule over both branches** — `.ssh` absent (created with
`mkdir` inside the home fd, never `makedirs`) and `.ssh` already present, the ordinary one.
It was previously two: a create-time `lstat` of the home that the existing-`.ssh` branch
never reached, and an owner check on `.ssh` that allows root — so a `~<account>` symlinked
at `/root` opened cleanly, reported uid 0, and took the whole write. ⚑ The self-account arm
takes no ownership refusal, but a `~/.ssh` it cannot chmod (one created once under `sudo`,
so root owns it) is **named** — fix the directory's ownership by hand and re-run.

**An `authorized_keys` line for this agent that is not the line this run would write is
refused, never appended beside or reported as *already present*.** The three states it
refuses on, and the heuristic's declared blind spot, are in
[`docs/board-tools.md`](board-tools.md) § *How it is wired (operator view)*, in the
**Provisioning** bullet — that paragraph owns them, and a second copy here is a copy that
drifts.

> **No account-level sshd hardening (card 5091).** Earlier releases had `--role a`
> write a `Match User <bridge-user>` sshd drop-in (`PasswordAuthentication no` +
> `ClientAliveInterval`/`ClientAliveCountMax`/`MaxSessions`) and had `bridge:check`
> hard-assert that posture. That is **retired**: the drop-in is account-scoped, so it
> locked out an operator whose own interactive login shares the ssh account — the
> deployment reality. The forced command already denies pty and all forwarding on its
> own key regardless of sshd config, so it stands alone as the boundary. `bridge:check`
> no longer asserts any account sshd posture (no `PasswordAuthentication`,
> `ClientAlive*`, or `MaxSessions` check). Operators remain free to apply box-wide sshd
> hardening themselves, but the bridge neither writes nor requires it.

> **`sudo bridge:check` + a distinct forced-command account (card 4977).** When you
> certify as root (`sudo bridge:check`, needed to read a non-invoking account's `0600`
> `authorized_keys`) but the forced command runs as a **different** OS account than
> `root`, set **`board_tools.ssh_account: <bridge-user>`** in the agent config. Absent
> it, the probe resolves the *invoking* account (root under sudo) and would read
> **`/root/.ssh/authorized_keys`** — false-negativing the very seat it targets. With it
> set, the pinned-line check resolves `<bridge-user>`. It resolves that account's
> `AuthorizedKeysFile` as sshd does, with the one documented exception named below:
> **every** file the directive names (it is a
> whitespace-separated LIST, and the OpenSSH default is the two-file
> `.ssh/authorized_keys .ssh/authorized_keys2`), with all four tokens `man 5 sshd_config`
> documents for it expanded — `%%` → a literal `%`, `%h` → the home, `%u` → the account
> name, `%U` → its numeric uid — and a path that is not absolute after expansion taken
> relative to the home (`~` is not a token). The pinned line may sit in **any** of those
> files, so a line in `authorized_keys2` certifies exactly like one in `authorized_keys`,
> and the finding names which file carried it. The **authoritative** "not wired" FAIL is
> only reached when every one of those files was actually consulted: an entry this run
> could not OPEN (another 0600 file) or could not resolve (`%U` with no uid lookup on a
> host without `posix_getpwnam`) is reported `unvalidated` and **named**, because the line
> may be in exactly the file that was not read. A file that simply is **not there** is
> consulted, not withheld — sshd takes no keys from it, so it counts toward the FAIL, which
> is what makes the FAIL reachable at all on the two-file default (`authorized_keys2` does
> not exist on most hosts). ⚠ **The exception: `AuthorizedKeysFile none`**, which
> `man 5 sshd_config` defines as *"skip checking for user keys in files"*, is **not**
> special-cased — the probe resolves it as the relative filename `none` and reports the
> authoritative "not wired" FAIL naming `<home>/none`. **The verdict is correct** (with
> `none` set, no pinned key can authenticate at all, so board-tools over ssh is impossible
> by construction) **but the path in it does not exist**: the remedy there is to stop
> setting `none` for this account, not to go and edit that file. If a
> **configured** `ssh_account` does not resolve to an OS account on the host, the
> account-dependent legs **fail** honestly (*"…does not resolve to an OS account…"*)
> rather than certify against a phantom `/.ssh/authorized_keys` built from an empty home.
> Leave it unset when the forced command runs as the invoking account (byte-identical to
> before).

### 4. The seat-side env keys `--role b` writes (reference)

STEP 1 of the packet writes these into the seat's own `.mcp.json`; nobody has to export
them by hand. They are listed here so a reader can tell what a correctly-provisioned seat
looks like, and so an operator wiring one manually knows what the provisioner would have
written:

```bash
export BRIDGE_TOOLS_SSH_TARGET=<bridge-user>@host-A   # NOT with BRIDGE_TOOLS_ENDPOINT — the two are mutually exclusive
export BRIDGE_TOOLS_SSH_KEY=~/.ssh/<agent>-board-tools   # optional (-i)
# export BRIDGE_TOOLS_SSH_PORT=22                        # optional (-p)
```

> **If you later run `provision-board-tools.py --role b` on this seat, it FORCE-WRITES
> `BRIDGE_TOOLS_SSH_KEY` into `.mcp.json`** (card#8972) — always to the key that run
> actually used, overwriting whatever is there. That is deliberate: the recorded key and
> the key pinned on host A must be the same file. This manual recipe and the provisioner
> are two ways to reach the same state, not two states; see
> [`docs/board-tools.md § Provisioning`](board-tools.md).

The ssh transport carries **no bearer** — identity is the pinned `--agent`, so no
`BRIDGE_TOOLS_TOKEN` is set. It **coexists** with the `-R` wake tunnel and the
existing HTTP forward-leg seats (each seat picks exactly one board-tools
transport).

## What this runbook does NOT cover

- **NAT traversal without SSH**: alternative tunnel mechanisms (Tailscale, Cloudflare Tunnel, WireGuard) work the same way at the bridge handler level — the URL points at `http://127.0.0.1:<port>/` regardless of how the loopback gets to host B. SSH is the canonical choice for single-tenant trust.
- **Multiple Claude Code sessions on the same host B**: requires distinct ports per session and distinct `BRIDGE_CHANNEL_NAME` per server. See [`docs/multi-agent.md § Multi-agent channel_push`](multi-agent.md) for the per-agent alignment story; the multi-host case adds the per-session-tunnel layer on top.
- **High-availability** failover from host A → host A'. The bridge is single-host by design.

## References

- Bridge handler: [`app/Bridge/Handlers/ChannelPushHandler.php`](../app/Bridge/Handlers/ChannelPushHandler.php)
- Channel reference server: [`examples/channel-servers/README.md`](../examples/channel-servers/README.md)
- Local-host UDS topology: [`examples/channel-servers/README.md § Register with Claude Code (UDS)`](../examples/channel-servers/README.md)
- Multi-agent topology: [`docs/multi-agent.md`](multi-agent.md)
- Decisions: [`CLAUDE_DECISIONS.md`](../CLAUDE_DECISIONS.md) DL-001 (synchronous Laravel architecture)
- Channels spec: https://code.claude.com/docs/en/channels-reference
