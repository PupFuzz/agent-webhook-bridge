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
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';

const SERVER_NAME = process.env.BRIDGE_CHANNEL_NAME || 'agent-webhook-bridge';
const TRANSPORT = (process.env.BRIDGE_CHANNEL_TRANSPORT || 'unix').toLowerCase();
const SHARED_TOKEN = process.env.BRIDGE_CHANNEL_TOKEN || '';

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
  'These are one-way: read them and act, no reply expected.',
  'kind identifies what happened upstream (e.g. card_updated, card_assigned); target_id names the resource; payload carries handler-specific data.',
].join(' ');

const mcp = new Server(
  { name: SERVER_NAME, version: '0.1.0' },
  {
    capabilities: { experimental: { 'claude/channel': {} } },
    instructions: INSTRUCTIONS,
  },
);

await mcp.connect(new StdioServerTransport());

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

if (TRANSPORT === 'unix') {
  // Bind directly. On EADDRINUSE, refuse to start with an operator-actionable
  // message — no auto-unlink, no liveness-probe race.
  // The "two concurrent Claude Code sessions on the same path" case is
  // operator error per the README "One server per session" note.
  server.on('error', (err) => {
    if (err && err.code === 'EADDRINUSE') {
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
  server.listen(SERVER_PORT, SERVER_HOST, () => {
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

process.on('SIGTERM', () => {
  server.close();
  process.exit(0);
});
