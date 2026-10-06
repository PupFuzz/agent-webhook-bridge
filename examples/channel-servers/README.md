# Reference channel MCP server for the agent-webhook-bridge `channel_push` handler

A minimal Node + `@modelcontextprotocol/sdk` server that bridges the bridge's `channel_push` handler to a Claude Code session as a [channel](https://code.claude.com/docs/en/channels-reference).

This is a worked example, not a production daemon. Copy it into your own deployment, adjust the env vars and gating to fit your trust boundaries, and own the lifecycle (Claude Code spawns the server on session start and reaps it on close). **Carry the license too**: `cp LICENSE <deploy-dir>/LICENSE.agent-webhook-bridge` (a name that won't collide with your own deployment's LICENSE) — it carries the `Required Notice:` line — or skip the copy and keep that line plus the license URL (<https://polyformproject.org/licenses/noncommercial/1.0.0>) in your own deployment's NOTICE instead. Either satisfies what the license requires you to pass on (the terms or their URL, plus the notice) — carrying `LICENSE` just does both in one file.

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

The server is its entry point `agent-webhook-bridge-channel.mjs` plus the sibling modules it imports relatively: `channel-lib.mjs` (the relay contract and the bridge transport) and `entry.mjs` (the channel's socket and `.FAILED` marker paths, which the server shares with the seat updater — see *Installed and updated by the bridge* below). Copy or symlink the **whole directory** (as above) so they travel together — cherry-picking only the entry file breaks the import, and **nothing in `bridge:check` will tell you** — the cherry-picked `package.json` carries the *current* version stamp with it, so the version check reads "up to date" and every other leg is satisfied. The check that catches it is a **launch**: run `check-channel-snapshot.py <this directory>` **on this seat, as the OS user whose Claude Code session starts the server** (DL-237). It is a bridge seat tool, installed onto this seat's PATH as the bridge's [`docs/seat-tools.md`](../../docs/seat-tools.md) describes — a bootstrapped seat has it in its client root's `bin/`; a seat not yet bootstrapped needs a bridge checkout once, for the bootstrap (see *Staying in sync* below). It exits **1** on exactly this shape, printing node's own `ERR_MODULE_NOT_FOUND`.

### Staying in sync with the canonical reference

If you **copied** this directory (rather than symlinked it), it's a snapshot that can drift when the bridge updates these files (e.g. a lockfile re-pin or an `npm ci`/Node-version change). The **`version` in `package.json` is the drift signal**: it's bumped on every change to the shipped channel-server files (a CI gate enforces it).

**A copy is not kept in sync by hand any more — move the seat onto the self-updating client once** (see *Installed and updated by the bridge* below): its bridge publishes a client pack per release and the seat updates itself at each new launch, so there is nothing to re-copy. The bridge's `CLAUDE_DEPLOYMENT.md` § *Multi-agent channel-server distribution* owns the one-time move. Until then:

- **The bridge can say whether this copy is behind.** This server sends its own version on every board-tools call, and the bridge operator's `php artisan bridge:check` prints it beside the client the bridge publishes (or, while it publishes none, the snapshot it bundles), warning when yours is behind (DL-364, DL-445). Make one board-tools call, then ask the operator to read that line. ⚠ A snapshot too old to send a version reads *not reported*, which is the absence of a verdict, not a clean one.
- **The move needs a bridge checkout once**, on this host, at the release your bridge runs: the bootstrap runs that checkout's updater. After it, the seat never reads the checkout again.

`bin/check-channel-snapshot.py` (below) answers a different question, *will it launch*, and says nothing about staleness.

A **symlink** never drifts — but it still needs a **dangling** check. It *trades* the drift failure mode for the dangling one rather than removing a failure mode: under a copied snapshot, relocating or renaming the bridge checkout is harmless (the copy is self-contained); under a symlink it is **fatal and silent** — the link goes dangling, the MCP server fails to launch at the next session start, and nothing tells you until live-wake simply never comes back. A **relative** symlink (one whose target is expressed relative to the link) narrows the window — it survives moving the link and its target together — but does not close it: it still breaks when the target alone moves.

Both failure modes are checkable, and `php artisan bridge:check` checks them for you once you declare the deployed path — set [`channel.server_path`](../../docs/config-schema.md) in your `<agent>.yml` to this directory (or to its entry `.mjs`). It also catches two of the ways a deployment ends up unlaunchable: a `server_path` with **no entry `.mjs`** in it (nothing for the MCP client to start), and a copy that landed but on which **`npm ci` never ran**, so there is no `node_modules` and node dies on `ERR_MODULE_NOT_FOUND` for the MCP SDK at the next session start. **It does NOT tell you whether the deployment will LAUNCH** — it never executes node, deliberately: the bridge commonly runs as a different OS user than you, so a launch from there would prove the entry loads for *its* PATH and *its* node, not yours (DL-237). It says so outright on every deployment it looks at — including a **symlinked** one, the topology recommended above — at severity `unvalidated`, and points you at `check-channel-snapshot.py`; see § Staying in sync above. When the deployed copy is stale, the WARN also points at the **symlink** as the way to stop it recurring — a symlinked deployment resolves into the checkout, so there is no snapshot left to drift. (Only for a deployment on the bridge's own filesystem: cross-host and separate-OS-user topologies need a real copy, and [`multi-host.md`](../../docs/multi-host.md) says to leave `server_path` unset there.) The bridge cannot infer the path (it may run as a different OS user and cannot read your `.mcp.json`), so an undeclared one is reported as *not validated*, never as healthy — and for the same reason, a deployment the bridge's user cannot traverse into is reported *not visible to this user*, also never as healthy.

`bridge:check` never reads a file's **content** and never enumerates your deployment: your own **extra** files (local modules, scratch files) are invisible to it by design, and so is any **edit** you make inside a file that keeps its name. Keep diffing against this reference (above) for those — a hand-edited copy that never touched `version` is still yours to notice. **And a file you are MISSING is invisible to it too, as of DL-237.** A version-gated file-set comparison shipped in v0.71.0 (DL-230) and was retired one release later, because it was measured to be less precise than launching in *both* directions: a deliberately pruned copy missing 6 of the 10 reference files (`README.md`, `.gitignore`, `.mcp.json.example`, the three `tests/*.mjs`) FAILed it while `node` bound and exited **0**, and the copy missing `channel-lib.mjs` — the one it existed for — is caught by a launch anyway. **So: `check-channel-snapshot.py` is the check for "will it launch", the `version` compare is the check for "is it stale", and the diff is the check for "is it edited".** DL-229 (f) records why no content or import-text check exists.

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

This flag is **CLI-only every session** — there is no `settings.json`/`.mcp.json` way to auto-load a development channel (it deliberately bypasses the allowlist). The launcher [`bin/start-claude.sh`](bin/start-claude.sh) wraps this command with the deaf-session guards — and, run from a copied directory, runs `npm ci` (pinned install) on first use; a bootstrapped seat runs it through its `~/start-claude.sh` shim ([§ *The seat's launcher*](#the-seats-launcher-start-claudesh-client-0947-and-later)).

Claude Code spawns `agent-webhook-bridge-channel.mjs` as a subprocess, the server binds the UDS, and you'll see:

```
[kanbanboard-agent] listening on unix:/run/user/1000/agent-webhook-bridge-channel-kanbanboard-agent.sock (umask 0077 at bind; chmod 0600 defense-in-depth)
```

Verify with `/mcp` inside the Claude Code session — the `kanbanboard-agent` server should appear as connected.

### 4. Smoke-test the transport hop independently of the Claude Code session

You can verify the bridge ↔ channel server hop with `curl --unix-socket`, without touching the model:

```bash
# -i so the response HEAD is printed: the status and the delivery-receipt
# declaration below are both in it, and a bare curl shows only the body.
curl -i -X POST --unix-socket /run/user/1000/agent-webhook-bridge-channel-kanbanboard-agent.sock \
  -H "Content-Type: application/json" \
  -d '{"intent": {"kind": "smoke_test", "subject_id": "manual_curl"}}' \
  http://localhost/
```

Expected (the `-i` is what prints the first two): HTTP **202**, an `X-Channel-Delivery-Receipt: none` header, and a body reading `forwarded — accepted by transport (unconfirmed): …`. **The 202 means the notification was written to the stdio transport and nothing more** — this transport returns no receipt that the session received it, which is why the header says so on the wire and why the bridge reports a push as *accepted by transport*, never as *delivered*. The Claude Code session receives `<channel source="kanbanboard-agent" kind="smoke_test" target_id="manual_curl">{"intent": {"kind": "smoke_test", "subject_id": "manual_curl"}}</channel>` and Claude responds in the next turn.

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

Verify after switching: trigger a test event (or `php artisan bridge:replay <N>`), then `php artisan bridge:inspect <N>` — look for `errored=0` in the dispatch ledger and no `has no paired Intent` warnings in the application log (grep that literal — the offending `target_id` rides in the log entry's context array, not in the message text). A `done-with-note` with `error_message` containing `connection refused` means the channel server was not up at dispatch time, which is expected and not a delivery failure (the Intent in `inbox.jsonl` is the backstop).

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
| `BRIDGE_CHANNEL_TOKEN` | unset | Optional bearer token; required for `TRANSPORT=http` on multi-user hosts. Also the **fallback tools bearer** (see `BRIDGE_TOOLS_TOKEN`). |
| `BRIDGE_CHANNEL_TOOLS` | unset | Tri-state advertise of the two-way board tools (DL-217). `1` ⇒ force ON. `0` or `` (empty) ⇒ OFF. **Unset** ⇒ advertise **iff** `BRIDGE_TOOLS_ENDPOINT` is set AND a bearer resolves — so wiring the endpoint line turns the tools on for free, and a bare channel agent advertises nothing. |
| `BRIDGE_TOOLS_ENDPOINT` | unset | The bridge's loopback URL for the tool-call ingress, e.g. `http://127.0.0.1:8787/agent-tools/call`. Required to advertise (force-on or default). |
| `BRIDGE_TOOLS_TOKEN` | unset | The per-agent Bearer the server presents to the bridge. Precedence: this (non-empty), else `BRIDGE_TOOLS_TOKEN_FILE`, else the `BRIDGE_CHANNEL_TOKEN` fallback. An empty value does not "configure" the source. |
| `BRIDGE_TOOLS_TOKEN_FILE` | unset | A `0600` file path to read the bearer from (an HTTP install may alias this to the channel token file). A **configured-but-unreadable** file short-circuits to no bearer (it does NOT fall through to `BRIDGE_CHANNEL_TOKEN`). |
| `AWB_CLIENT_UPDATE_BUDGET_MS` | `20000` | Only for a server started through `<root>/entry.mjs`: the hard deadline, in milliseconds (1–600000), of the launch-time update. When it runs out the installed release starts and the update is reported failed. |
| `AWB_CLIENT_ROOT`, `AWB_LAUNCH_ID`, `AWB_BRIDGE_RELEASE` | set by `entry.mjs` | Not settings: `entry.mjs` sets them for the server it starts — the seat root, this launch's id, and the release running. The server sends the last two as `launch` on every board-tools call and reads `<root>/state.json` for its INSTRUCTIONS line. |
| `STY` | (set by GNU `screen`) | Not a bridge setting — read only to gate the local `clear_context` tool (see below). `clear_context` is advertised **iff** `STY` is set AND `clear-agent.sh` is on `PATH`. |

---

## Installed and updated by the bridge (client 0.9.29 and later)

From client 0.9.29 a seat need not copy this directory at all: its bridge publishes a **client pack** per bridge release (DL-428/DL-430) and the seat runs it from a **seat root** it updates at every new launch (card#10568, DL-434). Getting a seat onto that path — fetching the first pack and pointing `.mcp.json` at the root — is the bootstrap. Every onboarding entry point runs it once the seat's ssh round-trip succeeds (`--certify-only`, `--self-cert`, the same-box wrapper; DL-445), and `provision-board-tools.py --role b --bootstrap-client` runs it on its own (DL-444). `docs/board-tools.md` owns their flags and refusals. A seat whose bridge offers nothing to install keeps its copied directory as described above. This section describes what a bootstrapped seat runs.

- **The root** holds `entry.mjs` (what `.mcp.json` points at), `current.json` (the installed release), `versions/<release>/` (each verified release, immutable once installed), `bin/<tool>` (shims that run the current release's copy of each seat tool, of each client program under `client/bin/` — `bridge-board-call`, below — and of the launcher, `bin/start-claude`: [§ *The seat's launcher*](#the-seats-launcher-start-claudesh-client-0947-and-later)), `state.json` (what THIS launch's update did), `install-log.jsonl` (append-only, hash-chained), `launch.json` (the session guard's record) and, from client 0.9.45, `served-tools.json` (the bridge's served-tools answer for this launch, written by the updater; see [§ *Which tools are listed*](#which-tools-are-listed-what-the-bridge-serves-this-agent-client-0945-and-later)).
- **At a new launch, and only then**, `entry.mjs` asks its own bridge's client-update door what it publishes, over the board-tools transport this server already uses (`BRIDGE_TOOLS_SSH_TARGET`, or `BRIDGE_TOOLS_ENDPOINT` + bearer — the door is `…/agent-tools/client` beside `…/agent-tools/call`). A newer release is fetched, checked against the sha256 values its manifest names, extracted, and switched in before the server starts, so the session runs it from its first message. A reconnect inside a running session (`/mcp reconnect`) changes nothing and asks nothing: **a running session's tools never change under it**.
- **A release that is not intact is passed over — one primitive, `classifyRelease`, judges it.** (A test pins a direct fs read under `versions/` from a function not on its allowlist, on a path the test exercises in-process; reads via `fs.open`/`readSync`, inside an allowlisted function, or in the child process (`importFailure`, `main`) are not pinned.) At a launch it checks the files the client cannot run without (cheap, `REQUIRED_CLIENT_FILES`); not-started or a bootstrap pays the full tree once. It returns `ok`, or `bad` for EVERY OTHER outcome alike — missing, a hash mismatch, or a plain read fault (a permission or I/O error) — there is no third state: a read fault has the same status as a confirmed mismatch, and only the not-started messages word it apart (design review, card#10568 non-convergence re-derivation — an earlier split that spared a read fault from deletion protected nothing reachable and blocked the one repair that mattered). The newest OTHER release that classifies `ok` starts instead, loudly, and `current.json` is repointed to it once the update reaches that step — **repointing deletes nothing**; an install (below) and the retention prune are what remove a release, each on its own terms. When no release classifies `ok`, the seat is not started and the message says to bootstrap — or, for a release passed over for a read fault, to fix the file it names. A file outside the cheap scope that the server imports can still fail only when the release is imported (a `seat-tools/bin/` file is not checked at launch at all: it fails when that tool runs, and the seat still starts), and then the seat is **not started** (no other release is tried): `entry.mjs` classifies that release's whole tree once to word the message — not intact on this seat (re-bootstrap: a bootstrap re-verifies the whole tree and replaces what is not intact), intact and failing anyway (a defect in the published release, or this seat's own Node runtime no longer matching its `node_engines`), or a read fault (named, with the file to fix; a bootstrap replaces a release with a file-level read fault too).
- **Any failure keeps the installed release running**, bounded by `AWB_CLIENT_UPDATE_BUDGET_MS`: the bridge unreachable, a pack that does not check out, a downgrade offered, the same release offered with other bytes, a Node this seat does not run. It is loud: `state.json`, one line at the top of this server's INSTRUCTIONS (below), the install log, and the report to the bridge, where `php artisan bridge:client-fleet` shows the seat's state to the PM.
- **Approval**: for an agent whose bridge YAML sets `board_tools.client_update.approval_required: true`, the door offers nothing until the PM approves the published pack's content (`php artisan bridge:client-approve`); the seat stays where it is and says so.
- **The bootstrap primitive** is `node client-update.mjs bootstrap --root <root> [--agent <name>]`, run with the channel's board-tools transport in its environment (what `--bootstrap-client` does); it exits 3 when the bridge answers and offers nothing to install right now (a refusal to the manifest — nothing published, a 5xx, a bridge older than the door — a 5xx to the pack, or approval owed), which is what lets an onboarding entry point keep the copied directory instead of failing: it asks the bridge's door as a launch would, installs only the release the bridge OFFERS — with approval owed it fetches nothing and says which release and `bridge:client-approve` — and applies the same checks and the same switch as a launch. `node client-update.mjs install --pack <file> --manifest <file> --root <root>` is the same install from a pack and manifest already on disk. A copy being replaced because it is not intact is renamed aside until the verified one is in, and put back if that rename fails. Running it again with the installed release repairs the root's pointer, `entry.mjs` and seat-tool shims, and re-extracts that release whenever it is not `ok` at the full check — read fault included, now that a read fault is `bad` like any other outcome. Re-running it with the same intact release does not replace that release's updater, so an updater that throws or cannot even load is fixed only by a release with a working one — and it cannot run to fetch that itself: once the bridge publishes a fixed release, re-bootstrap from it. A release refused for its own content (a downgrade, a tamper signal, an unsupported Node) is resolved once the bridge publishes one this seat accepts; an install log that cannot be read is a local fault, named as such.
- **Retention keeps the running release and the newest OLDER `ok` release, and removes the rest** — including a `bad`, read-fault-included release — because retention is a keep-two POLICY, not a promise that an unrepaired release survives; a removal that fails is logged (`skipped`) and does not fail the update. The shim list is read from the FILES.json that `classifyRelease` checks at the launch's own `required` scope, not from a directory listing.

When this launch's update did not leave the seat current, the server's INSTRUCTIONS open with one line that begins `CLIENT UPDATE FAILED (<reason>)`, `CLIENT RELEASE <release> IS PUBLISHED` (approval owed) or `CLIENT UPDATE STATE UNKNOWN` (this launch's `state.json` could not be read, or names another launch), preceded by `CLIENT RELEASE DAMAGED ON THIS SEAT` when a release that was not intact was passed over and is still not what runs. `clientUpdateInstruction` in `channel-lib.mjs` owns the wording.

`entry.mjs`'s header owns its contract step by step; it changes only with a new DL.

---

## The seat's launcher: `~/start-claude.sh` (client 0.9.47 and later)

The launcher is the script that starts a seat's Claude Code session with its channel: it resolves the channel identity, refuses a second session on the same channel, surfaces a prior deaf-session marker, reclaims an orphaned channel server left on an HTTP port by a crashed session, exports what it guarded and runs `claude --dangerously-load-development-channels server:<channel>`. **There is one launcher, and this directory owns it:** [`bin/start-claude.sh`](bin/start-claude.sh) (Linux, UDS and HTTP) and [`bin/start-claude.ps1`](bin/start-claude.ps1) + [`bin/start-claude.bat`](bin/start-claude.bat) (Windows, HTTP over an SSH reverse tunnel). It ships in the client pack, so it moves with the channel server it guards (card#11328, DL-463). **Never copy it onto a seat**: a copy is frozen at the release it was copied from.

**⭐ THE CROSS-REPO CONTRACT** — what a seat's tooling (the coord framework's `/coord:setup-bridge` and `/coord:update` among them) may build against, at a bridge release that carries client 0.9.47 or later:

| | POSIX | Windows |
| --- | --- | --- |
| **The seat's shim** (written by the provisioner) | `$HOME/start-claude.sh` | `%USERPROFILE%\start-claude.bat` and `%USERPROFILE%\start-claude.ps1` |
| **A checkout's old launcher path** (a compatibility stub that runs the moved launcher, for hand-written launchers that exec it) | `<checkout>/examples/start-channel-session.sh` | `<checkout>\examples\start-claude.ps1` / `.bat` |
| **What it runs** | `<root>/bin/start-claude` | `<root>\bin\start-claude.cmd` |
| **What that runs** (written by the client updater at every pack install, resolving `<root>/current.json` each time it runs) | `bash <root>/versions/<release>/client/bin/start-claude.sh` | `powershell -ExecutionPolicy Bypass -File <root>\versions\<release>\client\bin\start-claude.ps1` |

`<root>` is the channel's client root: `${XDG_DATA_HOME:-~/.local/share}/agent-webhook-bridge/client/<channel>`, or `%LOCALAPPDATA%\agent-webhook-bridge\client\<channel>` on Windows.

- **The writer:** `provision-board-tools.py --role b --write-launcher-shim --agent <agent> --channel-name <channel> [--claude-extra-args=<args>]`, run on the seat as its own OS user from a bridge checkout. It writes the shim and nothing else — no certification, no bootstrap, no `.mcp.json` read. `--bootstrap-client`, `--certify-only` and `--self-cert` write it too, after their own work, when the root can serve it (otherwise they print why it was not written and carry on).
- **The marker line.** Every shim file carries one comment line holding exactly `agent-webhook-bridge launcher shim (provision-board-tools.py --write-launcher-shim)` (`# …` in `.sh` / `.ps1`, `rem …` in `.bat`). A file at a shim path with that line is the provisioner's and is rewritten in place; an up-to-date one is left untouched, so a re-run changes nothing. A file without it is the operator's: it is **renamed to `<name>.pre-shim-<UTC stamp>`** beside it (nothing deleted), the run says so, and the shim is written. Edits to a shim are lost at the next run — keep settings in the environment. One home holds one shim: a shim that sets ANOTHER channel is refused rather than retargeted, so a seat running two channels as one OS user starts the second with `~/start-claude.sh --channel <other>` (`start-claude.bat -Channel <other>` on Windows — the PowerShell launcher's parameter is `-Channel`).
- **The env it sets:** `BRIDGE_CHANNEL_NAME=<channel>`, the launcher's identity input; it wins over `settings.local.json` and `COORD_CONFIG` + `COORD_AGENT`. And, when the seat has any, **`BRIDGE_CLAUDE_EXTRA_ARGS`**: extra `claude` arguments (e.g. `--dangerously-skip-permissions`) that the launcher passes after `server:<channel>` and before the shim's own arguments. Whitespace-separated plain tokens (`[A-Za-z0-9_./:=@,+-]`), no quoting. A re-run keeps the value a shim already has; `--claude-extra-args=<args>` replaces it (`--claude-extra-args=` clears it). When the shim replaces a hand-written POSIX launcher, the arguments its `exec` line passed after the launcher path (or after `server:<channel>`) are carried over and the run says so; arguments it cannot carry (a `$VAR`, anything not a plain token), and anything in a replaced Windows file, get a ⚠ line naming the backup and the flag. Everything else the launcher reads (`BRIDGE_CHANNEL_TRANSPORT`, `BRIDGE_CHANNEL_PORT`, `BRIDGE_CHANNEL_SOCKET`; on Windows `TUNNEL_HOST` — required, the launcher exits 1 without it — `SSH_KEY`, `REMOTE_PORT`, `LOCAL_PORT`) comes from the environment or `settings.local.json` `.env`, as the header of each launcher says. Arguments pass through to the launcher (`--channel <name>` on POSIX, `-Channel <name>` on Windows, then `claude`'s own). The root's shim sets `AWB_LAUNCHER_CLIENT_ROOT=<root>` for the launcher, which unsets it before it runs `claude`.
- **What a run reports.** Every `--write-launcher-shim` run ends with ONE line on stdout, `launcher-shim-status: <token>`, and the matching exit code; a refusal writes nothing and puts its reason on stderr. **A shim is never written over a target that does not exist.**

  | token | exit | meaning | what to do |
  | --- | --- | --- | --- |
  | `written` | 0 | a shim file was written or rewritten | — |
  | `up_to_date` | 0 | the shim was already exactly this | — |
  | `retry_after_launch` | 3 | the client root exists but has no `bin/start-claude` (`.cmd`) yet — its release is older than 0.9.47, or no updater that knows the launcher has settled it | retry after the seat's next launch (two when updating from an older client), or run `--role b --bootstrap-client`, which settles it at once |
  | `needs_bootstrap` | 4 | the channel has no client root (no `<root>/entry.mjs`) | an operator runs `--role b --bootstrap-client` |
  | `conflict` | 5 | a shim path is a directory, or holds another channel's shim | an operator decides; nothing was touched |

  Exit 1 is a usage refusal (a bad `--agent`, a missing `--channel-name`, a transport flag, `--claude-extra-args` that is not plain tokens) and 2 is argparse's own usage error; neither prints a status line. A shim whose target disappears later exits 2 naming `--bootstrap-client`, rather than starting a session with no guards.
- ⚠ **The launcher that runs is the one the PREVIOUS launch installed.** The launcher runs before `claude`, and the update runs inside the channel server `claude` starts, so a release that changes the launcher installs at one launch and runs at the next. The root's `bin/start-claude` itself is written by the updater of the release that carries it, so a seat updating to 0.9.47 from an older release gets it one launch later still — or at once, from `--bootstrap-client`.
- **`BRIDGE_CHANNEL_SERVER_DIR` is not read on this path.** The pack carries the channel server's `node_modules`, so the launcher run from a client root installs nothing. The variable (`SERVER_DIR` on Windows) is read only when the launcher runs from anywhere else — a seat still on a copied channel server, which runs `npm ci` there once — and a seat on its client root can drop it. A check that reads the version from that directory reports a copy, not what the seat runs: read `<root>/current.json`.

---

## Two-way board tools (DL-217)

By default this server is one-way: the bridge pushes wake events, the server
surfaces them as `notifications/claude/channel`, no reply. When board tools are
advertised (tri-state — see `BRIDGE_CHANNEL_TOOLS` in the env table: `=1` force-on,
or **unset with `BRIDGE_TOOLS_ENDPOINT` + a resolvable bearer**), the server ALSO
advertises the bridge's request/response MCP tools — `board_my_cards`,
`board_create_card`, `board_correct_card` (DL-326, widened by DL-376: correct a card
you filed or that is assigned to you, instead of minting a second card to say the first
one is wrong) and
`board_take_card` (DL-372: claim a card for YOURSELF — the assignee is resolved from
your bridge identity, never from the payload, so there is no argument for a user id
and a seat can claim only for itself; since card#10869 a card another user holds is taken
over with a warning and a card comment naming them, and only a card in a finished column
is refused; since card#11150 / DL-449, `start: true` also moves the card to In Progress in
the same write) and `board_comment_card` (DL-381: append a comment to
a live card on your own board; the bridge writes the `FROM: <seat>` attribution line, and
nothing is edited or deleted) and `board_get_cards` (DL-435: read known card ids in one call,
each answered with an explicit status — never silently omitted) and `board_search` (DL-437: the
cards on your board matching filters, matches only, or `summary: true` for counts) and `ci_await` /
`ci_await_cancel` (DL-452: wait for CI on one commit without polling — the bridge sends one
`ci_settled` event when every workflow run on that head is terminal) — and acts as a
**dumb proxy** for them: on a `tools/call` it
forwards `{tool, args, client_version}` to `BRIDGE_TOOLS_ENDPOINT` with the resolved
`Authorization: Bearer <token>` and returns the bridge's response verbatim.
`client_version` is this server's own `package.json` version, read at start-up and
sent so the bridge can tell a tool missing from a STALE copy of THIS directory from
one the bridge never shipped (DL-364) — ⛔ it is an **optional observation the bridge
must never refuse a call over**, it is **omitted** when the manifest cannot be read,
and it carries nothing about the seat but that version. It
carries **no board logic, no kanban token, and no retry** — all validation,
scoping, and idempotency live in the bridge. A bare channel agent with no tools
wiring advertises no `tools` capability (nothing dead).

If the tools are advertised but the endpoint or bearer is unset, a `tools/call`
returns a **structured refusal naming the missing config** (no call is made).

### Which tools are listed: what the bridge serves this agent (client 0.9.45 and later)

The env rule above decides whether the bridge tools are ON. WHICH of them are listed is the
bridge's own answer for this agent (card#11283 / DL-462): the bridge's `served_tools` set ∩
the tools this server defines. An implementation seat with a scope-less `board_tools` block is
served `ci_await` and `ci_await_cancel` only, so that is all it lists, and its instructions
describe CI tools and no board tool. The set is resolved ONCE, at start-up, before the MCP
handshake, from the first of:

1. **This launch's cache**, `<client root>/served-tools.json`, written by the launch-time
   updater from this launch's `client_manifest` answer (only when that answer carries
   `served_tools`). No call is made.
2. **One `{"op": "served_tools"}` call** to the update door over this seat's transport (5 s):
   - the bridge's list ⇒ that list;
   - `door_closed` (the ssh door will not serve this agent) ⇒ **no bridge tool**;
   - a bridge that does not know the op (ssh exit 1 or HTTP 422 with no `reason` and an
     `unknown client-update` error; the pre-door empty-`tool` refusal; HTTP 404) ⇒ the env
     rule, every tool, and any cache is ignored.
3. **The last good cache**, from any earlier launch, when the call says nothing (no answer, a
   reason-less exit 2, a 5xx, a 401, a body that is not the op's answer).
4. **The env rule**: every tool, as before 0.9.45.

The bridge refuses an unserved tool (`not_served`) whatever this server lists, so a fallback can
only ever list a tool that refuses — it never grants one. `served-tools.json` is an add-only
cross-release contract: `{launch_id, agent, served: [...], written_at}`; readers use
`launch_id` and `served`.

The bridge side is loopback-gated: same-box installs point `BRIDGE_TOOLS_ENDPOINT`
at a loopback peer (`https://<public-host>/...` FAILS the gate: the kernel
source-selects the box's public IP). An Apache/TLS-fronted bridge has two
recipes — a dedicated loopback-port vhost (the `:8787` shape below) or the
`/etc/hosts` loopback-pin — see
[`docs/board-tools.md § Same-box enablement (Apache/FPM)`](../../docs/board-tools.md#same-box-enablement-apachefpm).
Multi-host needs a forward SSH tunnel — see
[`docs/multi-host.md § Board tools (two-way) forward leg`](../../docs/multi-host.md#board-tools-two-way-forward-leg).
Full agent-facing reference: [`docs/board-tools.md`](../../docs/board-tools.md).
Enable the matching per-agent `board_tools:` config block —
[`docs/config-schema.md § board_tools`](../../docs/config-schema.md).

Example `.mcp.json` env for a same-box tools-enabled install:

```jsonc
"env": {
  "BRIDGE_CHANNEL_TRANSPORT": "unix",
  "BRIDGE_CHANNEL_NAME": "kanbanboard-agent",
  "BRIDGE_CHANNEL_TOOLS": "1",
  "BRIDGE_TOOLS_ENDPOINT": "http://127.0.0.1:8787/agent-tools/call",
  "BRIDGE_TOOLS_TOKEN_FILE": "/home/you/.config/agent-webhook-bridge/kanbanboard-agent-tools-token"
}
```

---

## Calling a board tool from a script: `bridge-board-call` (client 0.9.41 and later)

A hook or a script cannot reach the board tools through this server — they are MCP tools, and only the Claude Code session speaks MCP to it. `bridge-board-call` makes ONE board-tools call as this seat, from a shell (card#11151, DL-451):

```bash
bridge-board-call board_take_card '{"card_id":123,"start":true}'
```

- **Usage:** `bridge-board-call [--channel <name>] [--project-dir <dir>] [--deadline-ms <1-600000>] <tool> ['<json-args>']`. The arguments are one JSON object, `{}` when omitted. **There is no identity argument:** the bridge resolves the seat from the credential the transport carries, exactly as for this server, so a script can act only as this seat.
- **Where it runs from.** A bootstrapped seat gets `<root>/bin/bridge-board-call`, a shim that runs the current release's `client/bin/bridge-board-call.mjs` with `node`; put it on `PATH` as [`docs/seat-tools.md`](../../docs/seat-tools.md) § *Installing onto PATH* says — a seat that linked its shims before this release re-runs that link step, because a new shim reaches `PATH` only then (§ *Upgrades* there). ⚠ **The shim is written by the updater of the release that carries it**, so it appears at the first launch after this seat installs 0.9.41 or later (the launch that installs it runs the previous release's updater), or at once on a bootstrap run from a bridge checkout that carries it. A seat on a copied directory runs `node <this directory>/bin/bridge-board-call.mjs`.
- **Which transport and credential.** The same ones this server uses, read the way Claude Code builds this server's environment: the calling environment, overlaid by the channel's `env` block in `<project>/.mcp.json`, with `${VAR}` / `${VAR:-default}` expanded from that environment. `<project>` is `--project-dir`, else `$CLAUDE_PROJECT_DIR` (set for Claude Code hooks), else the working directory. The channel is `--channel`, else the ONE `mcpServers` entry whose `env` records `BRIDGE_TOOLS_SSH_TARGET` or `BRIDGE_TOOLS_ENDPOINT` (two such entries: name one with `--channel`); with none, the environment alone. Then the keys in the configuration table above, with this server's rules: one transport, the bearer precedence of `BRIDGE_TOOLS_TOKEN`, and `BRIDGE_CHANNEL_TOOLS=0` meaning no board tools for this channel. ⚠ Only the project `.mcp.json` is read; a server Claude Code holds in the user or local scope (`~/.claude.json`) is not, so give such a seat's transport in the environment.
- **One-shot on both doors.** The ssh door's forced command, `bridge:tools-call`, reads one `{tool, args, …}` JSON object on stdin and writes one JSON envelope on stdout; the HTTP door is one POST. Neither is an MCP server, so the CLI sends this server's request body — `{tool, args, client_version}`, plus `caller` (next) — over this server's own round trip (`channel-lib.mjs`), and nothing speaks JSON-RPC.
- **It declares itself.** The request carries `caller: "script"` and the client's `client_version`, never a `launch`: the bridge records the call without overwriting what this seat's channel server last reported, so `bridge:client-fleet` keeps describing the server, not the script.

**The exit-code contract.** stdout carries the bridge's answer only when a tool answered (0 and 1); stderr carries everything else.

| Exit | Meaning | stdout | stderr |
| --- | --- | --- | --- |
| `0` | The tool answered `ok: true`. | the response, verbatim | nothing |
| `1` | **The tool refused.** Branch on the refusal's `reason`; `docs/board-tools.md` tables the codes. | the refusal JSON, verbatim | its `error` text, verbatim |
| `2` | **The call reached no tool** — only where that is proven: a seat configuration it cannot read or that names no usable transport (each refusal says what it read and where); ssh exit 255 whose stderr says the session never opened (connection refused, an unresolvable or unreachable host, a connect timeout, host-key verification, authentication, no agreed algorithm); an HTTP connection that never opened (`ECONNREFUSED`, `ENOTFOUND`, `EAI_AGAIN`, `EHOSTUNREACH`, `ENETUNREACH`), a redirect (refused, never followed); an endpoint that does not parse as a URL or carries a credential in its userinfo (refused before anything is sent); an HTTP 4xx other than 422 (401 bearer, 403 loopback gate) or 503 (the bridge's own board-tools config). | nothing | what failed |
| `3` | **Unmeasured** — no answer that establishes what happened: an answer that is not the door's JSON; an HTTP 5xx other than 503 — the bridge's `502` among them, which may follow a write that landed; any other HTTP failure (a reset, a socket closed mid-answer, a body cut off after its status), because the request may have reached the bridge; any other ssh exit 255 (a session the far end closed, a broken pipe, the far end's own stderr, or no diagnosis at all); a call past its deadline; and **every exit 2 from the ssh door**. Read the card before re-sending a write. | nothing | what was seen |
| `4` | Usage. Nothing was sent. | nothing | the usage line |

- ⭐ **A coded install fault is a refusal: exit `1`, never `0`.** Every answer carrying an `install_fault.*` `reason` (`docs/board-tools.md` tables them — `install_fault.no_kanban_user` for a seat with no kanban user among them) arrives as a 422, or ssh exit 1, so the script gets the code on stdout and a non-zero exit. The bridge's UNCODED install answers (its 503, an unknown ssh agent, …) are not refusals: they are exit `2` over HTTP and `3` over ssh (below). None of them is ever exit `0`.
- ⚠ **Why every ssh-door exit 2 is `3` and not `2`.** That door renders every 5xx-class answer as exit 2 (`DispatchOutcome::exitCodeFor`) — the `502` that may follow a landed write and its own install faults (unknown agent, agent config error, an agent that is not a live ssh board-tools agent) alike — and its body carries no status or `reason` to tell them apart. Reporting it as "reached no tool" would be false for the `502`; "not established" is true for both. stderr carries the bridge's text, so a person can still read which.
- ⚠ **ssh exit 255 is `2` only when ssh says the session never opened** — matched on whole lines in OpenSSH's own shapes, so the far end's stderr mentioning "Connection refused" does not count. ssh exits 255 for its own failures, but a session that drops after the forced command started (`closed by remote host`, `Broken pipe`) is the same may-have-landed case as an HTTP reset, and a bridge process that dies with 255 (a PHP fatal) before its envelope is indistinguishable from it — so those, and a 255 that says nothing, are `3`.
- 🔒 **An endpoint is printed only as origin + path** (`redactUrl` in `channel-lib.mjs`), never its userinfo, query or fragment, and an endpoint that does not parse is never printed at all; the same holds for this server's tool results and start-up log. A malformed `.mcp.json` is reported without any excerpt of it.
- ⏱ **The deadline is 45 s by default** (`--deadline-ms` sets it), below a 60 s hook timeout (the default Claude Code's hooks reference states — not measured here; check the version you run), so a hook keeping that default sees this program's own exit `3` rather than a kill. A hook that raises the deadline must give itself a longer timeout than the deadline.

---

## Local self-management tool: `clear_context`

Separately from the bridge-proxied board tools, the server can advertise one
**local-exec** MCP tool, `clear_context`. It is **not** a board tool and is
**never** proxied to the bridge — calling it spawns the local `clear-agent.sh`
helper **detached** to clear THIS agent's own context (to save tokens) and returns
immediately; the clear terminates the session.

Its advertise gate is **orthogonal** to `BRIDGE_CHANNEL_TOOLS`: `clear_context` is
listed **iff** `STY` is set (i.e. the session runs inside GNU `screen`) **and**
`clear-agent.sh` resolves on `PATH`. A seat can advertise `clear_context` with the
board tools off, and vice versa. If it is called when not armed (`STY` unset or the
helper absent), the call returns a **structured MCP error** — never a silent no-op.

The tool description carries the usage guardrails the model should honor: run it
as the **final action of a turn**, only after committing work and posting any
handoff, and never mid-task or while a human message is unanswered.

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
- **Channel notifications are not acknowledged.** Per the spec, `mcp.notification()` resolves when the message is written to the transport, not when Claude has processed it. If the session is closed or the org policy blocks channels, **nothing on this path reports what became of the event** — whether an unsurfaced notification is dropped outright or deferred to the session's next turn boundary is **not established** (card#9172/DL-370), which is why neither this server's 202 body nor the bridge's log lines claim either. The `Intent` in `inbox.jsonl` is the backstop, and it is the right answer under both.
- **One server per UDS path.** Two Claude Code sessions trying to bind the same socket collide. The new session refuses to start with an operator-actionable error message (no auto-unlink — the existing server might be alive). Set distinct `BRIDGE_CHANNEL_NAME` per session. **A second session is only one of the three things that can hold the address** — the commonest, measured on live seats, is *this* session's own previous server after its `.mcp.json` was re-provisioned: /mcp reconnect does not stop the previous channel server — restart the session. The refusal message enumerates all three; [`docs/board-tools-enablement.md` § Activating on a running seat](../../docs/board-tools-enablement.md#activating-on-a-running-seat) owns the explanation and the remedies.
- **Deaf/duplicate sessions are made visible (FR #2444).** Claude Code swallows MCP-server startup stderr, so a session whose connector loses the bind race used to come up *deaf to live-wake* invisibly — the bridge kept pushing `HTTP 202` at the other session's connector and recording the dispatch as done (it logged `delivered` until card#9172/DL-370; it now logs `bridge dispatch: channel_push unconfirmed`, which is narrower but still not a signal that THIS session heard anything). Three guards now surface it: (1) on `EADDRINUSE` the connector writes a visible **`<socket>.FAILED` marker** (timestamp + reason) in addition to the swallowed stderr, and the connector that *successfully* binds clears any stale marker; (2) the launcher (`bin/start-claude.sh`) refuses to launch if a `claude … server:<channel>` process is already running this channel (a guardrail — the connector's refusal is the backstop), and **surfaces** a prior `<socket>.FAILED` marker loudly at launch (it does **not** clear it — the connector owns the marker lifecycle and clears it on the next successful bind, so the deaf-session signal survives until acknowledged); (3) `php artisan bridge:check` pings the socket for **liveness** (tells a socket some process is listening on from a stale one — a listener is not evidence a session is attached; a wake seen in the session, per `CLAUDE_DEPLOYMENT.md` § *Live-event path*, step 2, is) and reports any `.FAILED` marker — and, once the agent declares `channel.server_path`, also checks the **deployed snapshot** the next session would respawn from: its version, and whether it has an entry file and `node_modules` to launch with (DL-229; see § Staying in sync above, which owns what each check does and does not cover).
- **The socket is cleaned up on every ordinary quit (DL-159).** The server unlinks its UNIX socket on `SIGTERM`, `SIGINT` (Ctrl-C), `SIGHUP` (terminal close), and stdin EOF (Claude Code closing the stdio pipe on session teardown), via one idempotent handler that unlinks synchronously — `server.close()` alone unlinks asynchronously and could lose the race with process exit. This stops a leftover socket from making the *next* bind trip an `EADDRINUSE` marker — whose cause (3), "a leaked socket file — or any other file — occupying the path, with no listener", is exactly this state — when no session is actually running. `SIGKILL` and hard crashes can't run cleanup and still leak a socket — that's the case the launcher's stale-socket guard + the `.FAILED` marker backstop above are for. The server only unlinks a socket it actually bound, so a failed bind never removes another holder's socket.
- **No retry on the bridge side.** A failed channel push records a `done-with-note` on the `agent_dispatches` row, but the `Intent` emitted in the same `ClassifyResult` lands in `inbox.jsonl` regardless, so `php artisan bridge:inbox` catches up on the next agent-side session. Don't omit your Intent emission when adding a `channel_push` ReactionTarget — `channel_push` is a live-push optimization, NOT a replacement for Intent.

---

## References

- Bridge handler: [`app/Bridge/Handlers/ChannelPushHandler.php`](../../app/Bridge/Handlers/ChannelPushHandler.php) (`handle()` method — UDS and HTTP dispatch, cfg-derived socket fallback)
- Shipped event-driven classifier: [`app/Bridge/Classifiers/EventDrivenClassifier.php`](../../app/Bridge/Classifiers/EventDrivenClassifier.php)
- Customization guide (channel section): [`docs/customization.md`](../../docs/customization.md) § Going event-driven
- Decisions: [`CLAUDE_DECISIONS.md`](../../CLAUDE_DECISIONS.md) DL-001 (synchronous Laravel architecture, HTTP transport, UDS transport)
- Channel spec: https://code.claude.com/docs/en/channels-reference
- MCP SDK: https://www.npmjs.com/package/@modelcontextprotocol/sdk
