// End-to-end tests for the LOCAL-EXEC `clear_context` MCP tool (card 5089).
//
// clear_context is a self-management tool: it spawns the local `clear-agent.sh`
// helper DETACHED to clear THIS agent's context, and — unlike board_my_cards /
// board_create_card — it is NEVER proxied to the bridge. Its advertise gate ($STY
// set AND clear-agent.sh resolvable on PATH) is ORTHOGONAL to the board-tools gate.
//
// Like marker-refusal.test.mjs, this suite spawns the REAL channel server and drives
// it over stdio — here through the MCP SDK client — so it exercises the true surface
// (tools/list advertisement + tools/call dispatch), not a re-implementation. To prove
// the tool ran WITHOUT a spy inside the child, the fake clear-agent.sh writes a sentinel
// file recording its own pid/pgid; a detached spawn is a new session leader, so pgid ==
// pid — an observable proof of `detached: true`. "Did not hit the bridge proxy" is proven
// by pointing BRIDGE_TOOLS_ENDPOINT at a local recording server and asserting zero hits.
//
// PROVE-IT-CAN-FAIL: the "absent when $STY unset" test is red-when-reverted. If the
// advertise gate is removed (clear_context added to tools/list unconditionally), that
// test goes RED. Verified during development by temporarily editing the tools/list
// handler to always include CLEAR_CONTEXT_TOOL and re-running this suite.
//
// Run: `node --test examples/channel-servers/tests/`.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { StdioClientTransport } from '@modelcontextprotocol/sdk/client/stdio.js';

const SERVER = path.join(
  path.dirname(fileURLToPath(import.meta.url)),
  '..',
  'agent-webhook-bridge-channel.mjs',
);

// A temp scratch dir that is cleaned up when the test ends.
function scratch(t, prefix) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), prefix));
  t.after(() => fs.rmSync(dir, { recursive: true, force: true }));
  return dir;
}

// Drop an executable fake `clear-agent.sh` into `binDir` that records its own
// pid/ppid/pgid to $SENTINEL_FILE (inherited from the server's env). A detached child
// is its own session/process-group leader, so pgid == pid — the observable that proves
// `detached: true` without a spy inside the server process.
function installFakeHelper(binDir) {
  const helper = path.join(binDir, 'clear-agent.sh');
  fs.writeFileSync(
    helper,
    '#!/usr/bin/env bash\n' +
      'pgid=$(ps -o pgid= -p $$ | tr -d " ")\n' +
      'printf "pid=%s ppid=%s pgid=%s\\n" "$$" "$PPID" "$pgid" > "$SENTINEL_FILE"\n',
    { mode: 0o755 },
  );
  return helper;
}

// A loopback HTTP server standing in for the bridge board-tools endpoint. It records
// every request so a test can assert the proxy was NOT hit. Returns { url, hits(), close }.
function recordingBridge(t) {
  let hits = 0;
  const server = http.createServer((req, res) => {
    hits += 1;
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end('{"ok":true}');
  });
  return new Promise((resolve) => {
    server.listen(0, '127.0.0.1', () => {
      t.after(() => server.close());
      const { port } = server.address();
      resolve({
        url: `http://127.0.0.1:${port}/agent-tools/call`,
        hits: () => hits,
      });
    });
  });
}

// Spawn the real channel server and connect an MCP client to it over stdio. `env` is
// merged over a minimal base that gives the server a resolvable unix channel socket
// (so it does not refuse-and-exit before the MCP handshake). The client + server are
// torn down when the test ends.
async function connectServer(t, env) {
  const runtime = scratch(t, 'clearctx-rt-');
  const transport = new StdioClientTransport({
    command: process.execPath,
    args: [SERVER],
    stderr: 'ignore',
    env: {
      PATH: process.env.PATH,
      BRIDGE_CHANNEL_TRANSPORT: 'unix',
      BRIDGE_CHANNEL_SOCKET: path.join(runtime, 'chan.sock'),
      BRIDGE_CHANNEL_NAME: 'clearctx-test',
      ...env,
    },
  });
  const client = new Client({ name: 'clearctx-test-client', version: '1.0.0' }, { capabilities: {} });
  await client.connect(transport);
  t.after(() => client.close());
  return client;
}

// Build the PATH-and-$STY armed environment: a fresh bin dir holding the fake helper,
// prepended to PATH, with $STY set and a sentinel path the helper writes to.
function armedEnv(t, extra = {}) {
  const binDir = scratch(t, 'clearctx-bin-');
  installFakeHelper(binDir);
  const sentinel = path.join(scratch(t, 'clearctx-sentinel-'), 'ran');
  return {
    sentinel,
    env: {
      PATH: `${binDir}${path.delimiter}${process.env.PATH}`,
      STY: '12345.pts-0.testhost',
      SENTINEL_FILE: sentinel,
      ...extra,
    },
  };
}

async function pollExists(file, timeoutMs = 3000) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    if (fs.existsSync(file)) {
      return true;
    }
    await new Promise((r) => setTimeout(r, 25));
  }
  return false;
}

// (a) Advertised in tools/list when armed ($STY set AND clear-agent.sh on PATH).
test('clear_context is advertised in tools/list when armed', async (t) => {
  const { env } = armedEnv(t);
  const client = await connectServer(t, env);
  const { tools } = await client.listTools();
  const names = tools.map((x) => x.name);
  assert.ok(names.includes('clear_context'), `expected clear_context in ${JSON.stringify(names)}`);
  const def = tools.find((x) => x.name === 'clear_context');
  assert.deepEqual(def.inputSchema, { type: 'object', properties: {}, additionalProperties: false });
});

// (b) ABSENT from tools/list when $STY is unset. Board tools stay ON (endpoint+token) so
// the tools capability exists and the list is returnable — proving the gate is orthogonal
// AND that clear_context alone is withheld. RED-WHEN-REVERTED: advertising clear_context
// unconditionally makes this assertion fail (see the file header note).
test('clear_context is ABSENT from tools/list when $STY is unset', async (t) => {
  const bridge = await recordingBridge(t);
  const { env } = armedEnv(t, {
    BRIDGE_TOOLS_ENDPOINT: bridge.url,
    BRIDGE_TOOLS_TOKEN: 'test-bearer',
  });
  delete env.STY; // disarm clear_context; board tools remain on
  const client = await connectServer(t, env);
  const { tools } = await client.listTools();
  const names = tools.map((x) => x.name);
  assert.ok(!names.includes('clear_context'), `clear_context must be absent, got ${JSON.stringify(names)}`);
  assert.ok(names.includes('board_my_cards'), 'board tools stay advertised — the gates are orthogonal');
});

// Orthogonality the other way: clear_context is advertised even with board tools OFF.
test('clear_context is advertised even when board tools are OFF', async (t) => {
  const { env } = armedEnv(t); // no BRIDGE_TOOLS_* → board tools off
  const client = await connectServer(t, env);
  const { tools } = await client.listTools();
  const names = tools.map((x) => x.name);
  assert.deepEqual(names, ['clear_context'], `only clear_context should be advertised, got ${JSON.stringify(names)}`);
});

// (c) tools/call SPAWNS the detached helper and does NOT hit the bridge proxy — even
// with board tools ON (the proxy is a live recording server, asserted at zero hits).
test('calling clear_context spawns the detached helper and does NOT proxy to the bridge', async (t) => {
  const bridge = await recordingBridge(t);
  const { env, sentinel } = armedEnv(t, {
    BRIDGE_TOOLS_ENDPOINT: bridge.url,
    BRIDGE_TOOLS_TOKEN: 'test-bearer',
  });
  const client = await connectServer(t, env);

  const res = await client.callTool({ name: 'clear_context', arguments: {} });
  assert.notEqual(res.isError, true, `clear_context should succeed: ${JSON.stringify(res)}`);
  assert.match(res.content[0].text, /spawned/);

  assert.ok(await pollExists(sentinel), 'the fake clear-agent.sh helper must have been spawned');
  const record = fs.readFileSync(sentinel, 'utf8');
  const pid = record.match(/pid=(\d+)/)[1];
  const pgid = record.match(/pgid=(\d+)/)[1];
  assert.equal(pgid, pid, `detached child is a session leader (pgid==pid); got ${record.trim()}`);

  assert.equal(bridge.hits(), 0, 'clear_context must NOT reach the bridge board-tools endpoint');
});

// Requirement 3: a not-armed clear_context call returns a STRUCTURED error, never a
// silent no-op. Board tools are ON so the tools/call handler is registered; $STY is
// unset so clear_context is disarmed.
test('calling clear_context when not armed returns a structured MCP error', async (t) => {
  const bridge = await recordingBridge(t);
  const { env } = armedEnv(t, {
    BRIDGE_TOOLS_ENDPOINT: bridge.url,
    BRIDGE_TOOLS_TOKEN: 'test-bearer',
  });
  delete env.STY;
  const client = await connectServer(t, env);
  const res = await client.callTool({ name: 'clear_context', arguments: {} });
  assert.equal(res.isError, true, `expected an error result, got ${JSON.stringify(res)}`);
  assert.match(res.content[0].text, /not armed/);
  assert.equal(bridge.hits(), 0, 'a disarmed clear_context must not be proxied to the bridge either');
});
