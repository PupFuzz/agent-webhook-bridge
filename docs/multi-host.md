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

## Setup

### 1. On host B — pick a port and generate a token

```bash
# A port no other service on either host uses. 8788 is the channels reference default.
export BRIDGE_CHANNEL_PORT=8788

# A long-random shared secret. Both .mcp.json (server side) and the bridge's
# classifier (client side) need this exact value.
export BRIDGE_CHANNEL_TOKEN="$(openssl rand -base64 48 | tr -d /=+ | head -c 64)"
echo "Save this token securely: $BRIDGE_CHANNEL_TOKEN"
```

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
use App\Bridge\Dispatch\Actor;
use App\Bridge\Dispatch\ClassifyResult;
use App\Bridge\Dispatch\Intent;
use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Support\AgentConfig;

class MyClassifier implements Classifier
{
    public function classify(
        string $eventType,
        array $payload,
        Actor $actor,
        string $provider,
        string $scopeId,
        AgentConfig $agent,
    ): ClassifyResult {
        if ($eventType !== 'card.updated') {
            return new ClassifyResult;
        }

        // Intent: the durable inbox-feeding backstop. ALWAYS emit one for
        // events that must reach the agent — channel_push is only a
        // live-push optimization for active sessions. The silent-drop guard
        // catches this misconfig.
        $intent = new Intent(
            kind: 'card_updated',
            subjectId: "card:{$payload['id']}",
            provider: 'kanban',
            actor: $actor,
            summary: "card {$payload['id']} updated",
            payload: $payload,
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
# From host A:
curl -X POST -H "Content-Type: application/json" \
  -H "Authorization: Bearer $BRIDGE_CHANNEL_TOKEN" \
  -d '{"intent": {"kind": "smoke_test", "target_id": "manual_curl"}}' \
  http://127.0.0.1:8788/
```

Expected: `forwarded` (HTTP 202). The Claude Code session on host B receives `<channel source="agent-webhook-bridge" kind="smoke_test" target_id="manual_curl">...</channel>` within seconds.

## Operator action by failure mode

| Symptom | Likely cause | Action |
| --- | --- | --- |
| `curl: connection refused` from host A | SSH tunnel down (autossh restarting, network partition, host B asleep) | Check `systemctl --user status agent-webhook-bridge-tunnel` on B; verify autossh process; restart unit if needed |
| `curl` returns `401 unauthorized` | `BRIDGE_CHANNEL_TOKEN` mismatch between `.mcp.json` (server) and classifier (client) | Compare both values; regenerate + redeploy if either rotated |
| `curl` returns 200 but Claude Code shows nothing | Channel server isn't bound (Claude Code session closed) OR `--dangerously-load-development-channels` flag missing | Run `/mcp` in the Claude Code session; check `~/.claude/debug/<session-id>.txt` for spawn errors |
| Bridge logs `process_error` constantly | Tunnel is up but channel server crashed | Restart the Claude Code session on B (the server dies and respawns with the session) |
| `connection refused` only sometimes | Tunnel flapping during autossh reconnect | Standard. The silent-drop guard ensures the Intent emission still feeds `php artisan bridge:inbox` for next-session catch-up |

## Board tools (two-way) forward leg

The channel-push wake path drawn above is A→B (the bridge pushes; the channel
server surfaces). The two-way board tools (DL-217) reverse the direction for the
call itself: an agent invokes `board_my_cards` / `board_create_card`, the channel
server on B forwards `{tool, args}` to the bridge on A over HTTP, and the bridge
replies. That B→A call does **not** ride the existing `-R` reverse tunnel (which
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
