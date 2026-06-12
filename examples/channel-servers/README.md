# Reference channel MCP server for the agent-webhook-bridge `channel_push` handler

A minimal Node + `@modelcontextprotocol/sdk` server that bridges the bridge's `channel_push` handler to a Claude Code session as a [channel](https://code.claude.com/docs/en/channels-reference).

This is a worked example, not a production daemon. Copy it into your own deployment, adjust the env vars and gating to fit your trust boundaries, and own the lifecycle (Claude Code spawns the server on session start and reaps it on close).

## Topology

```
┌────────────────────────────┐   POST /              ┌─────────────────────────────┐    stdio    ┌──────────────────┐
│ agent-webhook-bridge        ├───────────────────────► THIS server (MCP child of   ├─────────────► Claude Code      │
│ (Laravel, in-request       │  UDS (default) or HTTP│ Claude Code)                │  channel    │ session sees     │
│  dispatch via              │  {"intent":{...}}     │ listens on UDS or 127.0.0.1 │ notification│ <channel ...>    │
│  ChannelPushHandler.php)   │                       └─────────────────────────────┘             └──────────────────┘
└────────────────────────────┘
```

The bridge's `channel_push` handler ([`app/Bridge/Handlers/ChannelPushHandler.php`](../../app/Bridge/Handlers/ChannelPushHandler.php), `handle()` method) POSTs the dispatched intent to either a Unix domain socket (default) or a localhost HTTP endpoint. This reference server answers that POST and forwards the body into the running Claude Code session as a `notifications/claude/channel` event.

**Dispatch is synchronous.** There is no consumer cron and no drain delay. The `channel_push` fires in-request when the webhook arrives. The tunnel or channel server must be up when the webhook arrives — there is no per-minute drain to buffer the call for later.

Per the [channels reference](https://code.claude.com/docs/en/channels-reference), Claude Code spawns the channel server as a subprocess over stdio when the session starts. The server is not a daemon you run yourself — it dies with the session.

## Install

**Requires Node ≥ 20.** Provision the right runtime before installing (an older Node only warns `EBADENGINE`, then can resolve a different tree).

```bash
# from your deployment directory (copy or symlink this directory):
cd examples/channel-servers
npm ci
```

`npm ci` installs the **exact pinned tree** from the committed `package-lock.json` (and fails if it has drifted from `package.json`) — a reproducible install for this copied-and-run reference, instead of `npm install` re-resolving a fresh dependency tree per host. The channel server reads a bearer token and accepts loopback POSTs as the agent's OS user, so a pinned, reviewed tree is the right control at that trust boundary.

### Staying in sync with the canonical reference

If you **copied** this directory (rather than symlinked it), it's a snapshot that can drift when the bridge updates these files (e.g. a lockfile re-pin or an `npm ci`/Node-version change). The **`version` in `package.json` is the drift signal**: it's bumped on every change to the shipped channel-server files (a CI gate enforces it), so compare your copy's version against the bridge's `examples/channel-servers/package.json` at the release you're on. If the canonical is higher, re-sync (re-copy the directory and `npm ci`). A **symlink** never drifts and needs no check.

---

## Register with Claude Code (UDS — recommended default)

Unix domain socket is the recommended default transport. Filesystem permissions are the trust gate: the server creates the socket with mode `0600` so only the current uid can connect.

### 1. Pick ONE channel name

The name propagates everywhere automatically. Match `[a-z0-9_-]+` (lowercase letters, digits, underscore, hyphen). It will appear in:

- `mcpServers.<KEY>` key in `.mcp.json` (Claude Code matches this against `--dangerously-load-development-channels server:<KEY>`)
- `BRIDGE_CHANNEL_NAME` env in the same `.mcp.json` block (the channel server uses this to derive its bind path + the `source="..."` tag)

Two references, ONE name — and the same name appears in the `channel.socket` path you set in `<agent>.yml` (next step).

**Important — socket path alignment.** The Node channel server derives its bind path from `$XDG_RUNTIME_DIR/agent-webhook-bridge-channel-${BRIDGE_CHANNEL_NAME}.sock`. The bridge does not auto-derive the path when `channel.socket` is omitted (it has no channel name — there is no `channel.name` field; the `<channel source="...">` label comes from `BRIDGE_CHANNEL_NAME`), but it **does expand `${XDG_RUNTIME_DIR}` / `${uid}` placeholders** in `channel.socket` (DL-039). So on systemd Linux, set `channel.socket` in YAML to the **uid-agnostic** literal:

```yaml
channel:
  socket: ${XDG_RUNTIME_DIR}/agent-webhook-bridge-channel-<NAME>.sock
```

This is the same path the server binds, with the uid kept out of config — so restoring the install on a host where the OS uid changed just works (a literal `/run/user/<uid>/…` would silently no-op live-wake; `bridge:check` warns if the resolved parent dir is missing). `${XDG_RUNTIME_DIR}` resolves to `$XDG_RUNTIME_DIR` or `/run/user/<uid>` when unset. On macOS / containers without a `/run/user`, set BOTH an explicit `channel.socket` in YAML AND the same `BRIDGE_CHANNEL_SOCKET` in `.mcp.json` env.

### 2. Drop `.mcp.json` in your project root

Start from [`.mcp.json.example`](./.mcp.json.example) and substitute your one name. On systemd Linux, `BRIDGE_CHANNEL_SOCKET` is unnecessary — the channel server derives the same path from `$XDG_RUNTIME_DIR`:

```json
{
  "mcpServers": {
    "kanbanboard-agent": {
      "command": "node",
      "args": ["/home/<you>/agent-webhook-bridge-prod/examples/channel-servers/agent-webhook-bridge-channel.mjs"],
      "env": {
        "BRIDGE_CHANNEL_TRANSPORT": "unix",
        "BRIDGE_CHANNEL_NAME": "kanbanboard-agent"
      }
    }
  }
}
```

The mcpServers key (`kanbanboard-agent`) AND `BRIDGE_CHANNEL_NAME` env value must match — they're the same name twice in adjacent lines for visual clarity. On macOS / containers, add `"BRIDGE_CHANNEL_SOCKET": "/explicit/path.sock"` in `env` AND set `channel.socket` to the same path in your `<agent>.yml`.

### 3. Start Claude Code with the channel-development flag

Custom channels aren't on the [approved allowlist](https://code.claude.com/docs/en/channels#research-preview) during the research preview, so start Claude Code with `--dangerously-load-development-channels server:kanbanboard-agent`:

```bash
claude --dangerously-load-development-channels server:kanbanboard-agent
```

This flag is **CLI-only every session** — there is no `settings.json`/`.mcp.json` way to auto-load a development channel (it deliberately bypasses the allowlist). The convenience launcher [`../start-channel-session.sh`](../start-channel-session.sh) wraps this command and also clears a stale socket and runs `npm ci` (pinned install) on first use.

Claude Code spawns `agent-webhook-bridge-channel.mjs` as a subprocess, the server binds the UDS, and you'll see:

```
[kanbanboard-agent] listening on unix:/run/user/1000/agent-webhook-bridge-channel-kanbanboard-agent.sock (umask 0077 at bind; chmod 0600 defense-in-depth)
```

Verify with `/mcp` inside the Claude Code session — the `kanbanboard-agent` server should appear as connected.

### 4. Smoke-test the transport hop independently of the Claude Code session

You can verify the bridge ↔ channel server hop with `curl --unix-socket`, without touching the model:

```bash
curl -X POST --unix-socket /run/user/1000/agent-webhook-bridge-channel-kanbanboard-agent.sock \
  -H "Content-Type: application/json" \
  -d '{"intent": {"kind": "smoke_test", "target_id": "manual_curl"}}' \
  http://localhost/
```

Expected: `forwarded` (HTTP 202). The Claude Code session receives `<channel source="kanbanboard-agent" kind="smoke_test" target_id="manual_curl">{"intent":{"kind":"smoke_test","target_id":"manual_curl"}}</channel>` and Claude responds in the next turn.

**Important:** this smoke test validates the **transport** (bridge → server → Claude Code), NOT the **event schema** (what your classifier emits, what Claude does with it). A green smoke test doesn't mean your classifier's `channel_push` ReactionTargets are correctly shaped.

### 5. Wire your bridge classifier

The bridge ships `App\Bridge\Classifiers\EventDrivenClassifier` — the canonical inbox + live-push pattern. It extends `InboxOnlyClassifier` and pairs every `Intent` with a `channel_push` ReactionTarget:

```php
// App\Bridge\Classifiers\EventDrivenClassifier (shipped — no copy needed)
// Extends InboxOnlyClassifier: emits Intents to inbox.jsonl PLUS a
// channel_push ReactionTarget per Intent, carrying the canonical wire shape
// Handler default envelope sends {"intent": <toArray()>}.
// Transport (socket/url) is left to the handler's cfg-derived default
// (channel.socket in your <agent>.yml).
class EventDrivenClassifier extends InboxOnlyClassifier
{
    public function classify(...): ClassifyResult
    {
        $result = parent::classify(...);
        if ($result->intents === []) { return $result; }
        $channelTargets = array_map(
            fn (Intent $intent): ReactionTarget => ReactionTarget::make(
                handler: 'channel_push',
                targetId: $intent->subjectId,
                debounceSeconds: 0,
                payload: $intent->toArray(),
            ),
            $result->intents,
        );
        return new ClassifyResult(
            targets: array_merge($result->targets, $channelTargets),
            intents: $result->intents,
        );
    }
}
```

Point `classifier.class` at it in your `<agent>.yml`:

```yaml
classifier:
  class: App\Bridge\Classifiers\EventDrivenClassifier
```

Also add the channel block (the `socket` path must match the channel server's bind path — the bridge does NOT derive it from any name):

```yaml
channel:
  # the path embeds the same name as the mcpServers key + BRIDGE_CHANNEL_NAME above
  socket: /run/user/1000/agent-webhook-bridge-channel-kanbanboard-agent.sock
```

If your agent needs custom behavior beyond the standard inbox + push pattern, subclass `EventDrivenClassifier` or `InboxOnlyClassifier` instead of copying the body. See `docs/customization.md § Extending a shipped classifier`.

**Important:** `channel_push` is an OPTIMIZATION for active sessions. The durable inbox backstop is the `Intent` emitted by `parent::classify(...)` — it writes to `<state_dir>/inbox.jsonl`, and `php artisan bridge:inbox` surfaces it on the next session. The `log_intent` ReactionTarget handler writes a forensic JSON log at `<state_dir>/handler-log.jsonl` and is NOT read by `bridge:inbox`; it's forensic-only, not the inbox-feeding backstop. The silent-drop guard warns when a `channel_push` target lacks a paired Intent with the same `subject_id` — `EventDrivenClassifier` satisfies this invariant by construction.

### Migrating off the polling-style `php artisan bridge:inbox` hooks

If your `~/.claude/settings.json` currently runs `bridge:inbox` on `PreToolUse`, `PostToolUse`, or `Stop`, you can remove those hooks when switching to event-driven — `channel_push` handles the active-session case live. **Keep `SessionStart`**: it's the catch-up path for events queued in `inbox.jsonl` while no session was up. Without it, those events stay queued until you next run `php artisan bridge:inbox --config <agent>` by hand.

Verify after switching: trigger a test event (or `php artisan bridge:replay <N>`), then `php artisan bridge:inspect <N>` — look for `errored=0` in the dispatch ledger and no `channel_push target ... has no paired Intent` warnings in the application log. A `done-with-note` with `error_message` containing `connection refused` means the channel server was not up at dispatch time, which is expected and not a delivery failure (the Intent in `inbox.jsonl` is the backstop).

Register your classifier in `<agent>.yml`:

```yaml
# in prod-agent.yml — the filename is the agent name; no identity.self
classifier:
  class: App\Bridge\Classifiers\EventDrivenClassifier   # FQCN; backslash prefix stripped automatically
channel:
  socket: /run/user/1000/agent-webhook-bridge-channel-kanbanboard-agent.sock
# ... rest of your agent config
```

When the next webhook arrives, the bridge classifies and dispatches `channel_push` synchronously in-request. Claude Code surfaces it as a `<channel>` tag within seconds.

---

## Alternative transport: HTTP for remote / SSH-tunneled setups

If the bridge and Claude Code run on different machines (e.g. the bridge receives webhooks on a public-ish host, and Claude Code runs on a workstation behind a firewall), use HTTP behind an SSH reverse tunnel.

Set `BRIDGE_CHANNEL_TRANSPORT=http` in `.mcp.json`:

```json
{
  "mcpServers": {
    "agent-webhook-bridge": {
      "command": "node",
      "args": ["/path/to/agent-webhook-bridge-channel.mjs"],
      "env": {
        "BRIDGE_CHANNEL_TRANSPORT": "http",
        "BRIDGE_CHANNEL_PORT": "8788",
        "BRIDGE_CHANNEL_NAME": "agent-webhook-bridge",
        "BRIDGE_CHANNEL_TOKEN": "<long-random-string>"
      }
    }
  }
}
```

Then run an `autossh` reverse tunnel from the workstation to the bridge host:

```bash
autossh -M 0 -N -R 127.0.0.1:8788:127.0.0.1:8788 <bridge-user>@<bridge-host>
```

In the classifier's `channel_push` ReactionTarget payload, include the `url` and `headers` keys:

```php
payload: [
    ...$intent->toArray(),
    'url' => 'http://127.0.0.1:8788/',
    'headers' => ['Authorization' => 'Bearer <same-token>'],
],
```

The SSH tunnel encrypts the wire; the bearer token defends against same-host compromise on the bridge side.

See [`docs/multi-host.md`](../../docs/multi-host.md) for the full SSH-tunneled multi-host runbook (autossh setup, restricted authorized_keys, token-gating defense-in-depth, operator-by-failure-mode matrix).

---

## Configuration reference

| Env var | Default | Description |
| --- | --- | --- |
| `BRIDGE_CHANNEL_TRANSPORT` | `unix` | Either `unix` (default) or `http` (for SSH-tunneled setups) |
| `BRIDGE_CHANNEL_SOCKET` | `$XDG_RUNTIME_DIR/agent-webhook-bridge-channel-${BRIDGE_CHANNEL_NAME}.sock` | UDS path; required if `XDG_RUNTIME_DIR` is unset (macOS / containers) |
| `BRIDGE_CHANNEL_PORT` | `8788` | HTTP port (only when `TRANSPORT=http`) |
| `BRIDGE_CHANNEL_NAME` | `agent-webhook-bridge` | MCP server name; the `source="..."` attribute on the `<channel>` tag |
| `BRIDGE_CHANNEL_TOKEN` | unset | Optional bearer token; required for `TRANSPORT=http` on multi-user hosts |

---

## Multi-agent setups (4-agent example)

The default socket path includes `BRIDGE_CHANNEL_NAME`. Running multiple bridge agents on the same uid? Set distinct names per agent and the default paths separate cleanly. Note the **three-way alignment** required per agent:

| Per-agent string | Sets |
| --- | --- |
| `mcpServers.<key>` in `.mcp.json` | Server identifier Claude Code spawns by; must match `--dangerously-load-development-channels server:<key>` |
| `BRIDGE_CHANNEL_NAME` env | `source="..."` attribute on the `<channel>` tag the model sees; also used by the channel server to derive its bind path |
| `channel.socket` in `<agent>.yml` | The explicit UDS path the bridge POSTs to — must equal the channel server's bind path |

All three should use the same name string. For a 4-agent install (`pm`, `device`, `backend`, `inventory`):

```json
{
  "mcpServers": {
    "pm-channel":        { "env": { "BRIDGE_CHANNEL_NAME": "pm-channel" } },
    "device-channel":    { "env": { "BRIDGE_CHANNEL_NAME": "device-channel" } },
    "backend-channel":   { "env": { "BRIDGE_CHANNEL_NAME": "backend-channel" } },
    "inventory-channel": { "env": { "BRIDGE_CHANNEL_NAME": "inventory-channel" } }
  }
}
```

Each gets its own default socket at `$XDG_RUNTIME_DIR/agent-webhook-bridge-channel-<name>.sock`. Set the corresponding `channel.socket` in each agent's YAML to that same path. The classifier for agent `pm` POSTs to its socket, the classifier for `device` POSTs to its socket, etc.

---

## Debugging "connection refused"

If channel_push fails with `connection refused`:

1. **Is the channel server running?** Inside Claude Code, run `/mcp`. If the server isn't listed or shows "Failed to connect," check `~/.claude/debug/<session-id>.txt` for the stderr trace.
2. **Does the path match exactly?** Compare:
   - `channel.socket` in your `<agent>.yml`
   - `BRIDGE_CHANNEL_SOCKET` env in `.mcp.json` (if set explicitly)
   - The stderr line `[kanbanboard-agent] listening on unix:<path>` from the server's startup log
   All must be the same string.
3. **Try the smoke-test curl** (step 4 above). If curl works but the bridge handler fails, the alignment is off between the handler-side path and the curl-side path.
4. **Did Claude Code close the session?** The server dies with the session. The bridge records a `done-with-note` (with `error_message`) on the `agent_dispatches` row whenever the channel server isn't up; that's an expected outcome, not a delivery failure. The `Intent` in `inbox.jsonl` ensures events surface in the next session via `php artisan bridge:inbox`.

---

## Lifecycle notes

- **The server dies with the session.** Claude Code spawns it; Claude Code reaps it. There's no daemon to keep running between sessions.
- **Channel notifications are not acknowledged.** Per the spec, `mcp.notification()` resolves when the message is written to the transport, not when Claude has processed it. If the session is closed or the org policy blocks channels, events are dropped silently.
- **One server per UDS path.** Two Claude Code sessions trying to bind the same socket collide. The new session refuses to start with an operator-actionable error message (no auto-unlink — the existing server might be alive). Set distinct `BRIDGE_CHANNEL_NAME` per session.
- **Deaf/duplicate sessions are made visible (FR #2444).** Claude Code swallows MCP-server startup stderr, so a session whose connector loses the bind race used to come up *deaf to live-wake* invisibly — the bridge kept delivering `HTTP 202` to the other session's connector and logging `delivered`. Three guards now surface it: (1) on `EADDRINUSE` the connector writes a visible **`<socket>.FAILED` marker** (timestamp + reason) in addition to the swallowed stderr, and the connector that *successfully* binds clears any stale marker; (2) `start-channel-session.sh` refuses to launch if a `claude … server:<channel>` process is already running this channel (a guardrail — the connector's refusal is the backstop), and clears a stale marker on start; (3) `php artisan bridge:check` pings the socket for **liveness** (distinguishes a live, listening session from a stale socket) and reports any `.FAILED` marker.
- **No retry on the bridge side.** A failed channel push records a `done-with-note` on the `agent_dispatches` row, but the `Intent` emitted in the same `ClassifyResult` lands in `inbox.jsonl` regardless, so `php artisan bridge:inbox` catches up on the next agent-side session. Don't omit your Intent emission when adding a `channel_push` ReactionTarget — `channel_push` is a live-push optimization, NOT a replacement for Intent.

---

## References

- Bridge handler: [`app/Bridge/Handlers/ChannelPushHandler.php`](../../app/Bridge/Handlers/ChannelPushHandler.php) (`handle()` method — UDS and HTTP dispatch, cfg-derived socket fallback)
- Shipped event-driven classifier: [`app/Bridge/Classifiers/EventDrivenClassifier.php`](../../app/Bridge/Classifiers/EventDrivenClassifier.php)
- Customization guide (channel section): [`docs/customization.md`](../../docs/customization.md) § Going event-driven
- Decisions: [`CLAUDE_DECISIONS.md`](../../CLAUDE_DECISIONS.md) DL-001 (synchronous Laravel architecture, HTTP transport, UDS transport)
- Channel spec: https://code.claude.com/docs/en/channels-reference
- MCP SDK: https://www.npmjs.com/package/@modelcontextprotocol/sdk
