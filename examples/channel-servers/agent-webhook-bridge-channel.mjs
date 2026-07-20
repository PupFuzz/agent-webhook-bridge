#!/usr/bin/env node
// Reference channel MCP server for the agent-webhook-bridge's channel_push handler.
//
// Unix domain socket is the recommended default for the same-host case; HTTP is
// available behind BRIDGE_CHANNEL_TRANSPORT=http for SSH-tunneled multi-host setups.
//
// Topology:
//
//   agent-webhook-bridge (Laravel)
//        │  ChannelPushHandler (app/Bridge/Handlers/ChannelPushHandler.php) runs
//        │  synchronously in the webhook request and POSTs over UDS (default)
//        │  or http://127.0.0.1:8788
//        ▼
//   THIS SERVER (Node MCP child of Claude Code, spawned over stdio)
//        │  forwards as notifications/claude/channel
//        ▼
//   Claude Code surfaces in conversation as
//        <channel source="agent-webhook-bridge" kind="..." target_id="...">
//          {"intent": {...}}
//        </channel>
//
// Per the Claude Code channels reference
// (https://code.claude.com/docs/en/channels-reference), a channel server:
//   1. declares `capabilities.experimental['claude/channel'] = {}`
//   2. emits `notifications/claude/channel` events with
//      `{ content: string, meta?: Record<string, string> }`
//   3. connects over stdio (Claude Code spawns the process)
//
// This server is INTENTIONALLY MINIMAL. Treat it as a worked example to copy
// into your own deployment, not a production daemon. The bridge ships the
// handler; the server lifecycle is the operator's concern.

import { Server } from '@modelcontextprotocol/sdk/server/index.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import {
  ListToolsRequestSchema,
  CallToolRequestSchema,
} from '@modelcontextprotocol/sdk/types.js';
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';

const SERVER_NAME = process.env.BRIDGE_CHANNEL_NAME || 'agent-webhook-bridge';
const TRANSPORT = (process.env.BRIDGE_CHANNEL_TRANSPORT || 'unix').toLowerCase();
const SHARED_TOKEN = process.env.BRIDGE_CHANNEL_TOKEN || '';

// Two-way board tools (DL-217), OFF by default. When BRIDGE_CHANNEL_TOOLS=1
// (set by provision when the install enables the feature) this server ALSO
// advertises the `tools` MCP capability and PROXIES tools/call to the bridge's
// loopback POST /agent-tools/call with the per-agent bearer. It stays a DUMB
// PIPE: no board logic, no kanban token, no retry. A non-adopting install leaves
// this unset and advertises nothing dead. The bridge endpoint + bearer:
//   BRIDGE_TOOLS_ENDPOINT    — the bridge's loopback URL for the call ingress,
//                              e.g. http://127.0.0.1:8787/agent-tools/call
//   BRIDGE_TOOLS_TOKEN       — the bearer value, OR
//   BRIDGE_TOOLS_TOKEN_FILE  — a path (chmod 600) to read it from (an HTTP install
//                              may alias this to the channel token file).
const TOOLS_ENABLED = process.env.BRIDGE_CHANNEL_TOOLS === '1';
const TOOLS_ENDPOINT = process.env.BRIDGE_TOOLS_ENDPOINT || '';

function resolveToolsToken() {
  if (process.env.BRIDGE_TOOLS_TOKEN) {
    return process.env.BRIDGE_TOOLS_TOKEN;
  }
  const file = process.env.BRIDGE_TOOLS_TOKEN_FILE;
  if (file) {
    try {
      return fs.readFileSync(file, 'utf8').trim();
    } catch {
      return '';
    }
  }
  return '';
}

// The v1 tool surface, hard-coded to mirror the bridge contract (DL-217). Kept
// here because tools/list must advertise a schema; the bridge remains the single
// authority on validation/scoping — this is the MCP surface, not board logic. If
// the bridge contract changes, update both (a reference example server, by design).
const TOOL_DEFINITIONS = [
  {
    name: 'board_my_cards',
    description:
      'Return YOUR OWN cards on the board (your product swimlane grouped by stage, ' +
      'plus any shared/coordination cards your bridge identity is scoped to). Read-only; ' +
      'the kanban token never leaves the bridge. No arguments.',
    inputSchema: { type: 'object', properties: {}, additionalProperties: false },
  },
  {
    name: 'board_create_card',
    description:
      'Create a card in YOUR OWN swimlane (the swimlane is forced from your bridge ' +
      'identity — you cannot target another lane). The card is born untriaged and ' +
      'surfaces to the triage pass. Pass an idempotency_key to make retries safe.',
    inputSchema: {
      type: 'object',
      properties: {
        title: { type: 'string', description: 'Card title (required).' },
        description: { type: 'string', description: 'Card body (optional).' },
        tags: {
          type: 'array',
          items: { type: 'string' },
          description:
            'Optional caller tags. Reserved prefixes (created-by:, idem:, id:, type:) ' +
            'and the bare tag "triaged" are refused.',
        },
        idempotency_key: {
          type: 'string',
          description:
            'Optional but recommended: [A-Za-z0-9.-]{1,64}. Re-using it returns the ' +
            'same card instead of creating a duplicate.',
        },
      },
      required: ['title'],
      additionalProperties: false,
    },
  },
];

function defaultSocketPath() {
  // Per-uid + per-server-name default. Multi-agent operators get distinct
  // paths automatically when they set distinct BRIDGE_CHANNEL_NAME per agent
  // (the same string that's used as the .mcp.json key AND the
  // <channel source="..."> attribute the model routes on).
  const xdg = process.env.XDG_RUNTIME_DIR;
  if (!xdg) {
    return null;
  }
  return path.join(xdg, `agent-webhook-bridge-channel-${SERVER_NAME}.sock`);
}

const SERVER_PORT = Number(process.env.BRIDGE_CHANNEL_PORT || 8788);
const SERVER_HOST = '127.0.0.1';
const SOCKET_PATH = process.env.BRIDGE_CHANNEL_SOCKET || defaultSocketPath();

// A bind failure exits the process with a stderr message Claude Code SWALLOWS
// (it does not surface MCP-server startup stderr), so a session whose connector
// loses the bind race comes up DEAF to live-wake invisibly. Leave a VISIBLE
// marker file in addition to stderr (FR #2444). The connector that SUCCESSFULLY
// binds owns the channel and clears any stale marker, so the signal reflects the
// current holder, not a week-old failure.
//
// Transport-aware path: the UNIX marker is the socket's sibling `<socket>.FAILED`
// — the path `bridge:check` derives from the agent's `channel.socket`. The HTTP
// marker is keyed by name+port (never masquerading as a socket failure); the
// launcher surfaces it on the agent host, and `bridge:check` does too when run
// there (best-effort) while its cross-host signal is the liveness probe of the
// loopback/tunnel port. Base dir: $XDG_RUNTIME_DIR when set (Linux), else
// os.tmpdir() — $TMPDIR or /tmp on Linux/macOS, %TEMP% on Windows — so the
// Windows launcher's $env:TEMP lookup and this path agree (a literal '/tmp'
// would resolve to C:\tmp under Node on Windows and never match).
function markerPath() {
  if (TRANSPORT === 'unix' && SOCKET_PATH) {
    return `${SOCKET_PATH}.FAILED`;
  }
  const xdg = process.env.XDG_RUNTIME_DIR || os.tmpdir();
  return path.join(xdg, `agent-webhook-bridge-channel-${SERVER_NAME}.http-${SERVER_PORT}.FAILED`);
}

function writeFailureMarker(reason) {
  try {
    fs.writeFileSync(
      markerPath(),
      `${new Date().toISOString()} pid=${process.pid} ${SERVER_NAME}: ${reason}\n`,
      { mode: 0o600 },
    );
  } catch {
    // Best-effort — the stderr message is still emitted regardless.
  }
}

function clearFailureMarker() {
  try {
    fs.rmSync(markerPath(), { force: true });
  } catch {
    // Best-effort.
  }
}

if (TRANSPORT === 'unix' && !SOCKET_PATH) {
  console.error(
    `[${SERVER_NAME}] BRIDGE_CHANNEL_TRANSPORT=unix but BRIDGE_CHANNEL_SOCKET is unset and ` +
      `XDG_RUNTIME_DIR is also unset (typical on macOS / containers). ` +
      `Set BRIDGE_CHANNEL_SOCKET to an absolute path under a directory you own (mode 0700 preferred), ` +
      `or set BRIDGE_CHANNEL_TRANSPORT=http to use the HTTP listener instead.`,
  );
  process.exit(2);
}

if (TRANSPORT !== 'unix' && TRANSPORT !== 'http') {
  console.error(
    `[${SERVER_NAME}] BRIDGE_CHANNEL_TRANSPORT must be 'unix' (default) or 'http' (got '${TRANSPORT}').`,
  );
  process.exit(2);
}

const INSTRUCTIONS = [
  `Events from the agent-webhook-bridge arrive as <channel source="${SERVER_NAME}" kind="..." target_id="...">.`,
  'The body is JSON: {"intent": {kind, target_id, payload, ...}}.',
  'These channel EVENTS are one-way notifications: read them and act — no reply is sent back through the event.',
  'kind identifies what happened upstream (e.g. card_updated, card_assigned); target_id names the resource; payload carries handler-specific data.',
  ...(TOOLS_ENABLED
    ? [
        'This server ALSO exposes request/response board tools scoped to YOUR channel identity:',
        'board_my_cards (read your own cards) and board_create_card (create a card in your own swimlane) —',
        'call them to see or capture board work without a kanban token; the write scope is your own swimlane, forced by the bridge.',
      ]
    : []),
].join(' ');

const capabilities = { experimental: { 'claude/channel': {} } };
if (TOOLS_ENABLED) {
  capabilities.tools = {};
}

const mcp = new Server(
  { name: SERVER_NAME, version: '0.1.0' },
  {
    capabilities,
    instructions: INSTRUCTIONS,
  },
);

// Register the tools surface BEFORE connect so the capability and its handlers
// are live from the first request. A structured refusal (not a thrown error)
// names the activating config when the bridge endpoint/bearer is half-set, so a
// caller reaching a partially-configured install gets an actionable message.
if (TOOLS_ENABLED) {
  mcp.setRequestHandler(ListToolsRequestSchema, async () => ({ tools: TOOL_DEFINITIONS }));

  mcp.setRequestHandler(CallToolRequestSchema, async (request) => {
    const toolName = request.params.name;
    const args = request.params.arguments || {};
    const token = resolveToolsToken();
    if (!TOOLS_ENDPOINT || !token) {
      const missing = [
        TOOLS_ENDPOINT ? null : 'BRIDGE_TOOLS_ENDPOINT',
        token ? null : 'BRIDGE_TOOLS_TOKEN (or BRIDGE_TOOLS_TOKEN_FILE)',
      ].filter(Boolean);
      return {
        isError: true,
        content: [
          {
            type: 'text',
            text:
              `board tools are advertised (BRIDGE_CHANNEL_TOOLS=1) but not fully configured on this ` +
              `channel server: set ${missing.join(' and ')}. No call was made to the bridge.`,
          },
        ],
      };
    }

    // Dumb pipe: forward {tool, args} verbatim, no retry, no board logic.
    try {
      const res = await fetch(TOOLS_ENDPOINT, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify({ tool: toolName, args }),
      });
      const text = await res.text();
      return {
        isError: !res.ok,
        content: [{ type: 'text', text }],
      };
    } catch (err) {
      return {
        isError: true,
        content: [
          {
            type: 'text',
            text: `could not reach the bridge tool endpoint ${TOOLS_ENDPOINT}: ${err && err.message ? err.message : err}`,
          },
        ],
      };
    }
  });
}

await mcp.connect(new StdioServerTransport());

if (TOOLS_ENABLED) {
  console.error(
    `[${SERVER_NAME}] board tools ENABLED (BRIDGE_CHANNEL_TOOLS=1) — proxying tools/call to ` +
      `${TOOLS_ENDPOINT || '(BRIDGE_TOOLS_ENDPOINT unset)'}`,
  );
}

// Per the channels spec, the `meta` keys we send must match this regex —
// Claude Code silently drops any key containing other characters. Values
// can be arbitrary strings. We hard-code the two keys we set ('kind' and
// 'target_id', both valid identifiers), so the regex isn't load-bearing
// here, but we keep it exported as a reference for operators adding more
// keys downstream.
const VALID_META_KEY = /^[A-Za-z0-9_]+$/;

function deriveMeta(body) {
  const meta = {};
  try {
    const parsed = JSON.parse(body);
    const intent = parsed && typeof parsed === 'object' ? parsed.intent : null;
    if (intent && typeof intent === 'object') {
      if (typeof intent.kind === 'string') {
        meta.kind = intent.kind;
      }
      if (typeof intent.target_id === 'string') {
        meta.target_id = intent.target_id;
      }
    }
  } catch {
    // body is not JSON; meta stays empty
  }
  return meta;
}

// Exported for operators copying this server as a starting point — when
// they add new meta keys, the regex says what's safe to use without being
// silently dropped on the Claude Code side.
export { VALID_META_KEY };

const server = http.createServer((req, res) => {
  if (req.method !== 'POST' && req.method !== 'PUT' && req.method !== 'PATCH') {
    res.writeHead(405, { 'Content-Type': 'text/plain' });
    res.end('method not allowed');
    return;
  }

  if (SHARED_TOKEN) {
    const auth = req.headers['authorization'] || '';
    if (auth !== `Bearer ${SHARED_TOKEN}`) {
      res.writeHead(401, { 'Content-Type': 'text/plain' });
      res.end('unauthorized');
      return;
    }
  }

  const chunks = [];
  req.on('data', (c) => chunks.push(c));
  req.on('end', async () => {
    const body = Buffer.concat(chunks).toString('utf8');
    const meta = deriveMeta(body);
    try {
      await mcp.notification({
        method: 'notifications/claude/channel',
        params: { content: body, meta },
      });
      res.writeHead(202, { 'Content-Type': 'text/plain' });
      res.end('forwarded');
    } catch (err) {
      // notification() resolves when written to transport; failure here
      // typically means the stdio transport is gone (Claude Code session
      // closed). 503 prompts the bridge consumer to retry on next drain.
      res.writeHead(503, { 'Content-Type': 'text/plain' });
      res.end(`channel transport closed: ${err && err.message ? err.message : err}`);
    }
  });
});

// Lifecycle cleanup. The UNIX socket is a filesystem-pathname AF_UNIX socket, so
// a leftover file makes the NEXT direct bind() fail EADDRINUSE on Linux even with
// no listener behind it — which the launcher's stale-socket guard recovers from,
// but only after a <socket>.FAILED marker that misreads as a live concurrency
// collision. Node only runs the SIGTERM handler's cleanup by default, so terminal
// close (SIGHUP), Ctrl-C (SIGINT), and parent-pipe close (stdin EOF) leak the
// socket. Route every ordinary-quit path through one idempotent shutdown that
// unlinks explicitly. SIGKILL / hard crash can't run this — that's exactly what
// the launcher's stale-socket guard + the marker backstop are for (DL-154/155/157).
let cleanedUp = false;
let bound = false; // true once WE own the socket (set in the unix listen callback)
function shutdown(code) {
  if (cleanedUp) {
    return;
  }
  cleanedUp = true;
  try {
    server.close();
  } catch {
    // never listened / already closed
  }
  // Explicit synchronous unlink: server.close() drains open connections and
  // unlinks ASYNCHRONOUSLY, but process.exit() below is synchronous — so on a
  // SIGTERM with an in-flight bridge connection the process can exit before
  // close()'s unlink runs (the FR's "clean path" race). unlinkSync removes it
  // deterministically. Gated on `bound` so a signal during the EADDRINUSE
  // failure window never removes a stale socket we didn't create.
  if (TRANSPORT === 'unix' && SOCKET_PATH && bound) {
    try {
      fs.unlinkSync(SOCKET_PATH);
    } catch {
      // best-effort: already gone
    }
  }
  process.exit(code);
}

if (TRANSPORT === 'unix') {
  // Bind directly. On EADDRINUSE, refuse to start with an operator-actionable
  // message — no auto-unlink, no liveness-probe race.
  // The "two concurrent Claude Code sessions on the same path" case is
  // operator error per the README "One server per session" note.
  server.on('error', (err) => {
    if (err && err.code === 'EADDRINUSE') {
      writeFailureMarker(
        `EADDRINUSE binding unix:${SOCKET_PATH} — another session already holds ` +
          `the channel, so THIS Claude Code session is deaf to live-wake`,
      );
      console.error(
        `[${SERVER_NAME}] socket file already exists at ${SOCKET_PATH}; ` +
          `another Claude Code session may have it bound. Close the other ` +
          `session, or rm the stale socket file if you're sure no server is ` +
          `running, or set BRIDGE_CHANNEL_SOCKET to a different path.`,
      );
      process.exit(2);
    }
    throw err;
  });
  // Set umask BEFORE listen() so the socket file is created with the
  // restricted perms atomically — closes the chmod race window where
  // the socket would briefly be world-readable/connectable between
  // listen() succeeding and a follow-up chmod call. Restored on both
  // success and error paths so subsequent runtime file ops aren't
  // affected by the temporarily-restrictive umask.
  const previousUmask = process.umask(0o077);
  server.on('error', () => {
    // Best-effort restore on the EADDRINUSE / other-error path. The
    // upstream 'error' handler will exit the process, but restoring
    // here keeps the contract clean if future code branches off
    // without exiting.
    process.umask(previousUmask);
  });
  server.listen(SOCKET_PATH, () => {
    // We own the channel now — clear any stale FAILED marker a previous deaf
    // session left, so the marker reflects the current holder (FR #2444).
    bound = true; // shutdown() may now unlink this socket — it's ours
    clearFailureMarker();
    // Restore umask for any subsequent file ops the runtime might do.
    process.umask(previousUmask);
    // Defense-in-depth: belt-and-suspenders chmod even though umask
    // should have done the right thing at bind time. On some kernels
    // umask doesn't apply to AF_UNIX socket creation.
    try {
      fs.chmodSync(SOCKET_PATH, 0o600);
    } catch (e) {
      console.error(`[${SERVER_NAME}] could not chmod socket: ${e.message}`);
    }
    console.error(
      `[${SERVER_NAME}] listening on unix:${SOCKET_PATH} ` +
        `(umask 0077 at bind; chmod 0600 defense-in-depth)`,
    );
    if (SHARED_TOKEN) {
      console.error(`[${SERVER_NAME}] bearer-token gating active (defense in depth)`);
    }
  });
} else {
  server.on('error', (err) => {
    if (err && err.code === 'EADDRINUSE') {
      writeFailureMarker(
        `EADDRINUSE binding http://${SERVER_HOST}:${SERVER_PORT} — another process ` +
          `holds the port, so THIS Claude Code session is deaf to live-wake`,
      );
      console.error(
        `[${SERVER_NAME}] port ${SERVER_PORT} already in use; another Claude Code ` +
          `session may have it bound. Close it, or set BRIDGE_CHANNEL_PORT to a different port.`,
      );
      process.exit(2);
    }
    throw err;
  });
  server.listen(SERVER_PORT, SERVER_HOST, () => {
    clearFailureMarker();
    console.error(`[${SERVER_NAME}] listening on http://${SERVER_HOST}:${SERVER_PORT}`);
    if (SHARED_TOKEN) {
      console.error(`[${SERVER_NAME}] bearer-token gating active`);
    } else {
      console.error(
        `[${SERVER_NAME}] no token gating — relying on localhost-bind for the trust boundary. ` +
          `For multi-user hosts, set BRIDGE_CHANNEL_TOKEN or switch to BRIDGE_CHANNEL_TRANSPORT=unix.`,
      );
    }
  });
}

for (const sig of ['SIGTERM', 'SIGINT', 'SIGHUP']) {
  process.on(sig, () => shutdown(0));
}

// Parent-death without a signal: when Claude Code closes the stdio pipe on
// session teardown, this child's stdin reaches EOF. The MCP SDK keeps stdin
// flowing (it has a 'data' listener) but does NOT surface EOF via onclose, so
// listen for 'end' directly — a separate event from 'data', so no conflict with
// the SDK's reader, and no resume() needed (the SDK already put stdin in flowing
// mode). Without this an orphaned server lingers holding the socket, and the
// launcher's liveness probe then gets a live response and aborts.
process.stdin.on('end', () => shutdown(0));
// Defense-in-depth: if the MCP layer itself closes the transport, clean up too.
mcp.onclose = () => shutdown(0);
