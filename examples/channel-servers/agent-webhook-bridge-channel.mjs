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
import { spawn } from 'node:child_process';
// Pure, side-effect-free helpers, split into a sibling module ONLY so they can be
// unit-tested directly (this file self-executes on import). Plain ESM, no build
// step; consumers copy the whole directory so the sibling travels with this entry.
import { deriveMeta, relayBridgeResponse } from './channel-lib.mjs';

const SERVER_NAME = process.env.BRIDGE_CHANNEL_NAME || 'agent-webhook-bridge';
const TRANSPORT = (process.env.BRIDGE_CHANNEL_TRANSPORT || 'unix').toLowerCase();
const SHARED_TOKEN = process.env.BRIDGE_CHANNEL_TOKEN || '';

// Two-way board tools (DL-217), advertised on a TRI-STATE. When on, this server
// ALSO advertises the `tools` MCP capability and PROXIES tools/call to the bridge's
// loopback POST /agent-tools/call with the per-agent bearer. It stays a DUMB PIPE:
// no board logic, no kanban token, no retry. The advertise decision:
//   BRIDGE_CHANNEL_TOOLS === '1'  → force ON.
//   BRIDGE_CHANNEL_TOOLS === '0' or ''  → OFF (explicit opt-out; an empty string
//                                          does NOT enable).
//   BRIDGE_CHANNEL_TOOLS unset  → advertise IFF BRIDGE_TOOLS_ENDPOINT is set AND a
//                                 bearer resolves (wire the one endpoint line and
//                                 the tools come on for free; a bare channel agent
//                                 with no tools wiring advertises nothing).
// The transport is EXCLUSIVE (single-valued per seat, v1): either the HTTP loopback
// endpoint OR the SSH-forced-command target — never both (a startup refuse enforces it).
//
// HTTP transport (BRIDGE_TOOLS_ENDPOINT + a bearer):
//   BRIDGE_TOOLS_ENDPOINT    — the bridge's loopback URL for the call ingress,
//                              e.g. http://127.0.0.1:8787/agent-tools/call
//   BRIDGE_TOOLS_TOKEN       — the bearer value, OR
//   BRIDGE_TOOLS_TOKEN_FILE  — a path (chmod 600) to read it from, OR
//   (fallback) BRIDGE_CHANNEL_TOKEN — reused when neither explicit tools token is
//                              configured (the default-ON model: the impl↔PM shared
//                              channel token IS the bearer, no new credential).
//
// SSH-forced-command transport (card 4952 — no bearer, no forwarding, works on an
// AllowTcpForwarding-remote seat where the HTTP loopback tunnel cannot):
//   BRIDGE_TOOLS_SSH_TARGET  — user@host of the bridge box; the client passes NO
//                              command (sshd substitutes the pinned bridge:tools-call).
//   BRIDGE_TOOLS_SSH_KEY     — optional path to the identity key (-i).
//   BRIDGE_TOOLS_SSH_PORT    — optional ssh port (-p).
const CHANNEL_TOOLS_ENV = process.env.BRIDGE_CHANNEL_TOOLS;
const TOOLS_ENDPOINT = process.env.BRIDGE_TOOLS_ENDPOINT || '';
const TOOLS_SSH_TARGET = process.env.BRIDGE_TOOLS_SSH_TARGET || '';
const TOOLS_SSH_KEY = process.env.BRIDGE_TOOLS_SSH_KEY || '';
const TOOLS_SSH_PORT = process.env.BRIDGE_TOOLS_SSH_PORT || '';
// Overall client-side deadline for one ssh board-tools round-trip. `-o ConnectTimeout`
// (below) bounds only the TCP/handshake; this caps the WHOLE call so a host that
// connects then hangs — or a wedged forced command — cannot pin the tools/call
// indefinitely or leak the child. Mirrors the PHP probe posture
// (SystemSshProbeEnvironment::sshRoundTrip: ConnectTimeout=10 + a 30s process timeout).
const TOOLS_SSH_DEADLINE_MS = 60000;

// Bearer precedence (pinned): explicit BRIDGE_TOOLS_TOKEN (non-empty), else the
// explicit BRIDGE_TOOLS_TOKEN_FILE (non-empty path) — and a configured-but-unreadable
// FILE SHORT-CIRCUITS to '' (never silently falling through to the channel token),
// else the BRIDGE_CHANNEL_TOKEN fallback. An empty-string env var does not
// "configure" a source (it is treated as unset for this chain).
function resolveToolsToken() {
  if (process.env.BRIDGE_TOOLS_TOKEN) {
    return process.env.BRIDGE_TOOLS_TOKEN;
  }
  const file = process.env.BRIDGE_TOOLS_TOKEN_FILE;
  if (file) {
    try {
      return fs.readFileSync(file, 'utf8').trim();
    } catch {
      return '';   // configured-but-unreadable file short-circuits; no fallthrough
    }
  }
  if (process.env.BRIDGE_CHANNEL_TOKEN) {
    return process.env.BRIDGE_CHANNEL_TOKEN;
  }
  return '';
}

function shouldAdvertiseTools() {
  if (CHANNEL_TOOLS_ENV === '1') {
    return true;
  }
  if (CHANNEL_TOOLS_ENV === '0' || CHANNEL_TOOLS_ENV === '') {
    return false;
  }
  // Unset: observable-intent default. The ssh branch is BEARER-FREE (DR2-5) — an ssh
  // target alone enables it, with NO token term (an `&& token` here would leave an
  // ssh-only seat dark). The HTTP branch still needs the endpoint line AND a bearer.
  if (TOOLS_SSH_TARGET !== '') {
    return true;
  }
  return TOOLS_ENDPOINT !== '' && resolveToolsToken() !== '';
}

const TOOLS_ENABLED = shouldAdvertiseTools();

// clear_context is a LOCAL-EXEC self-management tool (card 5089), advertised on a gate
// that is ORTHOGONAL to the board tools above: it NEVER proxies to the bridge, so it has
// no endpoint/bearer/transport term. It can be advertised when the board tools are not,
// and vice versa. The helper it spawns clears THIS agent's own screen/tmux window.
const CLEAR_AGENT_HELPER = 'clear-agent.sh';

// Idiomatic PATH resolution: search $PATH left-to-right for an executable `name`, exactly
// as a shell would, and return the first hit's resolved path (or null). No hardcoded dir.
function resolveOnPath(name) {
  const raw = process.env.PATH || '';
  if (!raw) {
    return null;
  }
  for (const dir of raw.split(path.delimiter)) {
    if (!dir) {
      continue;
    }
    const candidate = path.join(dir, name);
    try {
      fs.accessSync(candidate, fs.constants.X_OK);
      return candidate;
    } catch {
      // not in this dir, or present but not executable — keep searching
    }
  }
  return null;
}

// Advertise clear_context IFF this seat is inside a screen/tmux session ($STY set) AND the
// clear-agent.sh helper is resolvable on PATH. Mirrors shouldAdvertiseTools()'s env-reading
// style but shares NONE of its terms — a bare-channel seat with tools off can still arm
// clear_context, and a fully board-tools-wired seat with no $STY/helper does not.
function shouldAdvertiseClearContext() {
  return Boolean(process.env.STY) && resolveOnPath(CLEAR_AGENT_HELPER) !== null;
}

const CLEAR_CONTEXT_ENABLED = shouldAdvertiseClearContext();

// The tools MCP capability + the tools/list and tools/call handlers come on when EITHER
// tool family is advertised — the two gates are independent.
const ADVERTISE_ANY_TOOL = TOOLS_ENABLED || CLEAR_CONTEXT_ENABLED;

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

// LOCAL-EXEC self-management tool (card 5089). NOT part of TOOL_DEFINITIONS — those are
// proxied to the bridge; this one is spawned locally and never leaves the host. The
// description carries the operational guardrails as usage guidance for the model.
const CLEAR_CONTEXT_TOOL = {
  name: 'clear_context',
  description:
    "Clear THIS agent's context to save tokens. Run it as the FINAL ACTION of a turn, " +
    'AFTER you have committed your work and posted any handoff/status. NEVER call it ' +
    'mid-task, with uncommitted work, or while a human message is unanswered or queued. ' +
    'It EXECUTES a clear you have ALREADY DECIDED on — it does not decide for you. ' +
    'Local-exec only (never proxied to the bridge); it returns immediately and the clear ' +
    'terminates this session. No arguments.',
  inputSchema: { type: 'object', properties: {}, additionalProperties: false },
};

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

// Startup config-validation refusals all share the same failure contract: leave
// a `.FAILED` marker (Claude Code swallows this server's startup stderr, so a
// bare exit is invisibly deaf to live-wake — FR #2444), emit the operator-facing
// stderr line, then exit non-zero. Route every refuse-and-exit site through here
// so no site can drift back to a marker-less exit. `advice` is the optional,
// site-specific remedy appended to the stderr line.
function refuseDeaf(reason, { advice } = {}) {
  writeFailureMarker(reason);
  console.error(`[${SERVER_NAME}] ${reason}${advice ? ` ${advice}` : ''}`);
  process.exit(2);
}

if (TRANSPORT === 'unix' && !SOCKET_PATH) {
  // markerPath() falls to its non-unix branch here (SOCKET_PATH is falsy), and
  // XDG_RUNTIME_DIR is necessarily unset in this state (it's the only reason
  // defaultSocketPath() returned null), so the marker resolves to
  // os.tmpdir()/…http-<port>.FAILED — %TEMP% on Windows, where the launcher
  // looks. Write it so a misconfigured Windows seat isn't silently deaf (FR #2444).
  const remedy =
    process.platform === 'win32'
      ? `On Windows, Node rejects a filesystem socket path (EACCES on bind), so BRIDGE_CHANNEL_SOCKET ` +
        `is not a usable remedy here — set BRIDGE_CHANNEL_TRANSPORT=http to use the loopback HTTP listener instead.`
      : `Set BRIDGE_CHANNEL_SOCKET to an absolute path under a directory you own (mode 0700 preferred), ` +
        `or export XDG_RUNTIME_DIR, or set BRIDGE_CHANNEL_TRANSPORT=http to use the HTTP listener instead.`;
  refuseDeaf(
    `BRIDGE_CHANNEL_TRANSPORT=unix but BRIDGE_CHANNEL_SOCKET and XDG_RUNTIME_DIR are both unset — ` +
      `no socket path could be resolved, so THIS Claude Code session is deaf to live-wake`,
    { advice: remedy },
  );
}

if (TRANSPORT !== 'unix' && TRANSPORT !== 'http') {
  refuseDeaf(
    `BRIDGE_CHANNEL_TRANSPORT must be 'unix' (default) or 'http' (got '${TRANSPORT}') — ` +
      `THIS Claude Code session is deaf to live-wake`,
  );
}

// The board-tools transport is single-valued per seat (v1). This refuse runs
// UNCONDITIONALLY — OUTSIDE the TOOLS_ENABLED guard (DR2-5) — so a
// BRIDGE_CHANNEL_TOOLS=0 seat with both env vars set is still caught, not silently
// skipped past the advertise gate.
if (TOOLS_SSH_TARGET !== '' && TOOLS_ENDPOINT !== '') {
  refuseDeaf(
    `BRIDGE_TOOLS_SSH_TARGET and BRIDGE_TOOLS_ENDPOINT are both set — ` +
      `choose exactly ONE board-tools transport (single-valued per seat) — ` +
      `THIS Claude Code session is deaf to live-wake`,
  );
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
  ...(CLEAR_CONTEXT_ENABLED
    ? [
        'This server ALSO exposes clear_context — a LOCAL self-management tool that clears THIS',
        "agent's own context to save tokens. Call it ONLY as the final action of a turn, after you",
        'have committed work and posted any handoff; never mid-task or with a human message unanswered.',
      ]
    : []),
].join(' ');

const capabilities = { experimental: { 'claude/channel': {} } };
if (ADVERTISE_ANY_TOOL) {
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
// `scrubSnippet` + `relayBridgeResponse` (the credential-scrubbing relay contract)
// are pure — they live in ./channel-lib.mjs and are imported at the top of this file.

// SSH-forced-command transport: spawn `ssh [-i key] [-p port] <target>` with NO
// command (sshd substitutes the pinned bridge:tools-call), write {tool, args} to the
// child's stdin, and CAPTURE (never inherit) its stdout — so this server's OWN stdout
// stays the MCP JSON-RPC frame channel. Accumulate the full child stdout, then relay.
async function callToolOverSsh(payload) {
  const args = ['-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10'];
  if (TOOLS_SSH_KEY) {
    args.push('-i', TOOLS_SSH_KEY);
  }
  if (TOOLS_SSH_PORT) {
    args.push('-p', TOOLS_SSH_PORT);
  }
  args.push(TOOLS_SSH_TARGET);

  return await new Promise((resolve) => {
    // One error shape for every ssh failure (spawn, child error, deadline) — a single
    // formatter, matching the isError result the parse-failure/relay path yields.
    const fail = (text) =>
      resolve({ isError: true, content: [{ type: 'text', text }] });

    let child;
    try {
      child = spawn('ssh', args, { stdio: ['pipe', 'pipe', 'pipe'] });
    } catch (err) {
      fail(
        `could not spawn ssh to ${TOOLS_SSH_TARGET}: ${err && err.message ? err.message : err}`,
      );
      return;
    }
    // Overall deadline: ConnectTimeout bounds only the connect, so a host that
    // connects then hangs would pin this call forever and leak the child. Kill it and
    // fail; cleared on any resolution so no dangling timer keeps the event loop alive.
    const deadline = setTimeout(() => {
      child.kill('SIGKILL');
      fail(
        `ssh to ${TOOLS_SSH_TARGET} exceeded the ${TOOLS_SSH_DEADLINE_MS}ms deadline`,
      );
    }, TOOLS_SSH_DEADLINE_MS);
    let stdout = '';
    child.stdout.on('data', (chunk) => {
      stdout += chunk.toString();
    });
    child.stderr.on('data', (chunk) => {
      // Diagnostics only — never mixed into the tool result.
      console.error(
        `[${SERVER_NAME}] ssh ${TOOLS_SSH_TARGET} stderr: ${chunk.toString().trimEnd()}`,
      );
    });
    child.on('error', (err) => {
      clearTimeout(deadline);
      fail(
        `ssh to ${TOOLS_SSH_TARGET} failed: ${err && err.message ? err.message : err}`,
      );
    });
    child.on('close', (code) => {
      clearTimeout(deadline);
      resolve(relayBridgeResponse(stdout, code === 0, `ssh ${TOOLS_SSH_TARGET}`));
    });
    child.stdin.write(payload);
    child.stdin.end();
  });
}

// HTTP loopback transport: POST {tool, args} with the per-agent bearer.
async function callToolOverHttp(payload, token) {
  try {
    const res = await fetch(TOOLS_ENDPOINT, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Authorization: `Bearer ${token}`,
      },
      body: payload,
    });
    const text = await res.text();
    return relayBridgeResponse(text, res.ok, TOOLS_ENDPOINT);
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
}

// LOCAL-EXEC self-management (card 5089): spawn the clear-agent.sh helper DETACHED and
// return immediately — this NEVER proxies to the bridge (orthogonal to the board tools).
// The clear terminates THIS session, so we do not await the child; detached + unref +
// ignored stdio let it outlive this process's stdio pipe. Called-but-not-armed ($STY unset
// or the helper absent) returns a STRUCTURED MCP error, never a silent no-op.
function handleClearContext() {
  const helper = resolveOnPath(CLEAR_AGENT_HELPER);
  if (!process.env.STY || !helper) {
    const missing = [
      process.env.STY ? null : '$STY is unset (no screen/tmux session detected)',
      helper ? null : `${CLEAR_AGENT_HELPER} is not on PATH`,
    ].filter(Boolean);
    return {
      isError: true,
      content: [
        {
          type: 'text',
          text: `clear_context is not armed on this seat: ${missing.join(' and ')}. No clear was run.`,
        },
      ],
    };
  }
  try {
    const child = spawn(helper, [], { detached: true, stdio: 'ignore' });
    child.unref();
  } catch (err) {
    return {
      isError: true,
      content: [
        {
          type: 'text',
          text: `clear_context could not spawn ${helper}: ${err && err.message ? err.message : err}`,
        },
      ],
    };
  }
  return {
    content: [
      {
        type: 'text',
        text: `clear_context: spawned ${helper} (detached) — this session will be cleared momentarily.`,
      },
    ],
  };
}

if (ADVERTISE_ANY_TOOL) {
  mcp.setRequestHandler(ListToolsRequestSchema, async () => ({
    tools: [
      ...(TOOLS_ENABLED ? TOOL_DEFINITIONS : []),
      ...(CLEAR_CONTEXT_ENABLED ? [CLEAR_CONTEXT_TOOL] : []),
    ],
  }));

  mcp.setRequestHandler(CallToolRequestSchema, async (request) => {
    const toolName = request.params.name;

    // clear_context is LOCAL-EXEC and orthogonal to the board tools — branch BEFORE the
    // bridge proxy (ssh/http) so it never leaves this host, even when board tools are on.
    // Handled unconditionally (not gated on CLEAR_CONTEXT_ENABLED) so a not-armed call
    // gets the structured "not armed" error instead of being proxied as a board tool.
    if (toolName === 'clear_context') {
      return handleClearContext();
    }

    // Past here it's a board tool. If board tools are not enabled on this seat, only
    // clear_context was advertised, so any other name is unknown — never proxy it.
    if (!TOOLS_ENABLED) {
      return {
        isError: true,
        content: [{ type: 'text', text: `unknown tool '${toolName}'` }],
      };
    }

    const args = request.params.arguments || {};
    const payload = JSON.stringify({ tool: toolName, args });

    // Guard branches on the TRANSPORT (DR2-5), not on a bearer: the ssh transport
    // carries no bearer, so `!token` must not gate it.
    if (TOOLS_SSH_TARGET) {
      return await callToolOverSsh(payload);
    }

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
    return await callToolOverHttp(payload, token);
  });
}

await mcp.connect(new StdioServerTransport());

if (TOOLS_ENABLED) {
  const target = TOOLS_SSH_TARGET
    ? `ssh:${TOOLS_SSH_TARGET}`
    : TOOLS_ENDPOINT || '(BRIDGE_TOOLS_ENDPOINT unset)';
  const why =
    CHANNEL_TOOLS_ENV === '1'
      ? 'BRIDGE_CHANNEL_TOOLS=1'
      : TOOLS_SSH_TARGET
        ? 'ssh target present (default-on)'
        : 'endpoint+bearer present (default-on)';
  console.error(
    `[${SERVER_NAME}] board tools ENABLED (${why}) — proxying tools/call to ${target}`,
  );
}

if (CLEAR_CONTEXT_ENABLED) {
  console.error(
    `[${SERVER_NAME}] clear_context ENABLED (local-exec; $STY set + ${CLEAR_AGENT_HELPER} on PATH)`,
  );
}

// Per the channels spec, the `meta` keys we send must match this regex —
// Claude Code silently drops any key containing other characters. Values
// can be arbitrary strings. We hard-code the two keys we set ('kind' and
// 'target_id', both valid identifiers), so the regex isn't load-bearing
// here, but we keep it exported as a reference for operators adding more
// keys downstream.
const VALID_META_KEY = /^[A-Za-z0-9_]+$/;

// `deriveMeta` (envelope → meta parsing) is pure — it lives in ./channel-lib.mjs
// and is imported at the top of this file.

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
    // Any other bind error (notably the EACCES Win32 throws for a filesystem
    // socket path) would otherwise die on the bare throw below with no marker,
    // leaving a Windows seat silently deaf to live-wake. Capture err.code +
    // err.message so the real cause is diagnosable (FR #2444). markerPath()
    // resolves to <socket>.FAILED, a sibling of the path we failed to bind.
    writeFailureMarker(
      `failed to bind unix:${SOCKET_PATH} (${err && err.code ? err.code : 'unknown'}: ` +
        `${err && err.message ? err.message : err}) — THIS Claude Code session is deaf to live-wake`,
    );
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
    // Any other bind error would otherwise die on the bare throw below with no
    // marker, leaving the seat silently deaf to live-wake. Capture err.code +
    // err.message so the real cause is diagnosable (FR #2444), mirroring the unix
    // fall-through. markerPath() resolves to the http-<port>.FAILED sibling.
    writeFailureMarker(
      `failed to bind http://${SERVER_HOST}:${SERVER_PORT} (${err && err.code ? err.code : 'unknown'}: ` +
        `${err && err.message ? err.message : err}) — THIS Claude Code session is deaf to live-wake`,
    );
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
