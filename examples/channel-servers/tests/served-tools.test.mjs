// What the channel server ADVERTISES is what the bridge says it serves THIS agent (card#11283):
// the served set ∩ TOOL_DEFINITIONS, resolved once, before the MCP handshake, down a fixed
// ladder — this launch's cache, then one `served_tools` call, then the last good cache, then
// today's env rule. Each rung is driven here through the REAL server over stdio, against a fake
// `ssh` first on PATH (or the fixture bridge, for the http-only answers: a 5xx and a 404).
//
// Run: `node --test examples/channel-servers/tests/`.
import './live-state-guard.mjs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import http from 'node:http';
import { scratch, connectServer } from './mcp-harness.mjs';
import { fixtureBridge } from './client-update-fixture.mjs';
import { classifyServedToolsAnswer, readServedToolsCache, SERVED_TOOLS_FILE } from '../channel-lib.mjs';

const TARGET = 'bridge@testhost';
const opts = { name: 'served-test', runtimePrefix: 'served-rt-' };
const ALL = ['board_my_cards', 'board_create_card', 'board_correct_card', 'board_take_card', 'board_comment_card', 'board_get_cards', 'board_search', 'ci_await', 'ci_await_cancel'];
const CI = ['ci_await', 'ci_await_cancel'];
const THIS_LAUNCH = 'launch-now';

// A fake ssh that answers the `served_tools` op from FAKE_SERVED_* and anything else from
// FAKE_SSH_*, and appends each stdin body to FAKE_SSH_LOG — so a test can tell whether a call
// was made at all.
function fakeSsh(t) {
  const binDir = scratch(t, 'served-bin-');
  fs.writeFileSync(
    path.join(binDir, 'ssh'),
    '#!/usr/bin/env bash\n' +
      'body=$(cat)\n' +
      'printf "%s\\n" "$body" >> "$FAKE_SSH_LOG"\n' +
      'case "$body" in\n' +
      '  *\'"op":"served_tools"\'*) printf "%s" "$FAKE_SERVED_STDOUT"; exit "${FAKE_SERVED_EXIT:-0}";;\n' +
      '  *) printf "%s" "${FAKE_SSH_STDOUT:-{\\"ok\\":true}}"; exit 0;;\n' +
      'esac\n',
    { mode: 0o755 },
  );
  return binDir;
}

function writeCache(root, launchId, served) {
  fs.writeFileSync(path.join(root, SERVED_TOOLS_FILE), JSON.stringify({ launch_id: launchId, agent: 'impl', served, written_at: new Date().toISOString() }));
}

/** The real server over a fake ssh. `served` = [stdout, exit] for the served_tools answer. */
async function overSsh(t, { served = null, cache = null } = {}) {
  const binDir = fakeSsh(t);
  const root = scratch(t, 'served-root-');
  if (cache) {
    writeCache(root, cache.launchId, cache.served);
  }
  const log = path.join(scratch(t, 'served-log-'), 'calls.log');
  const client = await connectServer(t, {
    PATH: `${binDir}${path.delimiter}${process.env.PATH}`,
    BRIDGE_TOOLS_SSH_TARGET: TARGET,
    AWB_CLIENT_ROOT: root,
    AWB_LAUNCH_ID: THIS_LAUNCH,
    FAKE_SSH_LOG: log,
    FAKE_SERVED_STDOUT: served ? served[0] : '',
    FAKE_SERVED_EXIT: served ? String(served[1]) : '255',
  }, opts);
  const calls = fs.existsSync(log) ? fs.readFileSync(log, 'utf8').trim().split('\n').filter(Boolean) : [];
  return { client, calls };
}

async function advertised(client) {
  if (!client.getServerCapabilities()?.tools) {
    return [];
  }
  return (await client.listTools()).tools.map((tool) => tool.name);
}

const ok = (served) => [JSON.stringify({ ok: true, op: 'served_tools', agent: 'impl', served }), 0];

// --- rung 1: this launch's cache ---------------------------------------------------------- //

test('this launch\'s cache is used, and no served_tools call is made', async (t) => {
  const { client, calls } = await overSsh(t, { served: ok(ALL), cache: { launchId: THIS_LAUNCH, served: CI } });

  assert.deepEqual(await advertised(client), CI);
  assert.deepEqual(calls, [], 'a cache this launch wrote answers the question; the door is not asked again');
});

// --- rung 2: one served_tools call ------------------------------------------------------------ //

test('an impl (CI-only) agent: only ci_* is listed, and the instructions name no board tool', async (t) => {
  const { client, calls } = await overSsh(t, { served: ok(CI) });

  assert.deepEqual(await advertised(client), CI);
  assert.equal(calls.length, 1);
  const instructions = client.getInstructions();
  assert.match(instructions, /ci_await/);
  assert.doesNotMatch(instructions, /board tools/i, instructions);
  assert.doesNotMatch(instructions, /board_/, instructions);
});

test('a scoped agent: every served tool is listed, and the instructions describe the board tools', async (t) => {
  const { client } = await overSsh(t, { served: ok(ALL) });

  assert.deepEqual(await advertised(client), ALL);
  assert.match(client.getInstructions(), /board tools scoped to YOUR channel identity/);
});

test('a served name this server has no definition for is not advertised (served ∩ TOOL_DEFINITIONS)', async (t) => {
  const { client } = await overSsh(t, { served: ok(['ci_await', 'board_future_tool']) });

  assert.deepEqual(await advertised(client), ['ci_await']);
});

test('the door is closed to this agent (door_closed): no bridge tool is advertised, whatever the last cache says', async (t) => {
  const closed = [JSON.stringify({ ok: false, error: 'agent `impl` is not a live ssh board-tools agent (transport must be ssh, enabled)', reason: 'door_closed' }), 2];
  const { client } = await overSsh(t, { served: closed, cache: { launchId: 'an-earlier-launch', served: ALL } });

  assert.deepEqual(await advertised(client), []);
  assert.doesNotMatch(client.getInstructions(), /ci_await|board tools/);
});

// --- rung 3: the last good cache ------------------------------------------------------------ //

test('a reason-less exit 2 falls back to the last good cache from an earlier launch', async (t) => {
  const { client } = await overSsh(t, { served: [JSON.stringify({ ok: false, error: 'agent config error' }), 2], cache: { launchId: 'an-earlier-launch', served: CI } });

  assert.deepEqual(await advertised(client), CI);
});

test('an unreachable bridge (ssh 255, nothing on stdout) falls back to the last good cache', async (t) => {
  const { client } = await overSsh(t, { served: ['', 255], cache: { launchId: 'an-earlier-launch', served: CI } });

  assert.deepEqual(await advertised(client), CI);
});

test('a 5xx falls back to the last good cache (http)', async (t) => {
  const bridge = await fixtureBridge(t, { fail: { served_tools: { status: 503, error: 'down for maintenance' } } });
  const root = scratch(t, 'served-root-');
  writeCache(root, 'an-earlier-launch', CI);
  const client = await connectServer(t, { BRIDGE_TOOLS_ENDPOINT: bridge.endpoint, BRIDGE_TOOLS_TOKEN: bridge.state.token, AWB_CLIENT_ROOT: root, AWB_LAUNCH_ID: THIS_LAUNCH }, opts);

  assert.deepEqual(await advertised(client), CI);
});

// --- rung 4: the env rule --------------------------------------------------------------------- //

test('a reason-less exit 2 with no cache at all: today\'s env rule, every tool', async (t) => {
  const { client } = await overSsh(t, { served: [JSON.stringify({ ok: false, error: 'agent config error' }), 2] });

  assert.deepEqual(await advertised(client), ALL);
});

test('a bridge with the door but not the op: the env rule, and an earlier launch\'s cache is NOT believed', async (t) => {
  const old = [JSON.stringify({ ok: false, error: 'unknown client-update `op` "served_tools" — this bridge serves client_manifest, client_pack, client_report, client_fleet' }), 1];
  const { client } = await overSsh(t, { served: old, cache: { launchId: 'an-earlier-launch', served: CI } });

  assert.deepEqual(await advertised(client), ALL);
});

test('a bridge older than the door (the empty-`tool` refusal, no reason): the env rule', async (t) => {
  const { client } = await overSsh(t, { served: [JSON.stringify({ ok: false, error: 'request must carry a non-empty `tool`' }), 1], cache: { launchId: 'an-earlier-launch', served: CI } });

  assert.deepEqual(await advertised(client), ALL);
});

test('an http bridge with no door route (404): the env rule', async (t) => {
  const bridge = await fixtureBridge(t, { fail: { served_tools: { status: 404, raw: '<html>Not Found</html>' } } });
  const root = scratch(t, 'served-root-');
  writeCache(root, 'an-earlier-launch', CI);
  const client = await connectServer(t, { BRIDGE_TOOLS_ENDPOINT: bridge.endpoint, BRIDGE_TOOLS_TOKEN: bridge.state.token, AWB_CLIENT_ROOT: root, AWB_LAUNCH_ID: THIS_LAUNCH }, opts);

  assert.deepEqual(await advertised(client), ALL);
});

test('over http, a current bridge\'s answer is used', async (t) => {
  const bridge = await fixtureBridge(t, { servedTools: CI });
  const client = await connectServer(t, { BRIDGE_TOOLS_ENDPOINT: bridge.endpoint, BRIDGE_TOOLS_TOKEN: bridge.state.token }, opts);

  assert.deepEqual(await advertised(client), CI);
  assert.deepEqual(bridge.requests.map((r) => r.body), [{ op: 'served_tools' }]);
});

test('an http door that accepts the connection and never answers: the call is cut at its 5 s cap and the env rule lists every tool', { timeout: 30000 }, async (t) => {
  const held = [];
  const server = http.createServer((req) => {
    held.push(req);   // read nothing back: the request is never answered
  });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  t.after(() => {
    server.closeAllConnections();
    server.close();
  });
  const endpoint = `http://127.0.0.1:${server.address().port}/agent-tools/call`;

  const started = Date.now();
  const client = await connectServer(t, { BRIDGE_TOOLS_ENDPOINT: endpoint, BRIDGE_TOOLS_TOKEN: 'hang-token' }, opts);
  const elapsed = Date.now() - started;

  assert.deepEqual(await advertised(client), ALL);
  assert.equal(held.length, 1, 'the door was reached, and held');
  assert.ok(elapsed >= 4500, `the server waited for the door before listing (${elapsed} ms)`);
  assert.ok(elapsed < 15000, `the start-up is bounded by the 5 s cap, not by the door (${elapsed} ms)`);
});

test('BRIDGE_CHANNEL_TOOLS=0 still turns the bridge tools off, and the door is not asked', async (t) => {
  const bridge = await fixtureBridge(t, { servedTools: CI });
  const client = await connectServer(t, { BRIDGE_CHANNEL_TOOLS: '0', BRIDGE_TOOLS_ENDPOINT: bridge.endpoint, BRIDGE_TOOLS_TOKEN: bridge.state.token }, opts);

  assert.deepEqual(await advertised(client), []);
  assert.deepEqual(bridge.requests, []);
});

// --- the classifier and the cache reader, directly -------------------------------------------- //

test('classifyServedToolsAnswer: each known shape', () => {
  const ssh = (stdout, code) => classifyServedToolsAnswer({ via: 'ssh', code, stdout });
  const http = (text, status) => classifyServedToolsAnswer({ via: 'http', status, text });

  assert.deepEqual(ssh(ok(CI)[0], 0), { kind: 'served', served: CI });
  assert.equal(ssh(JSON.stringify({ ok: true, served: CI }), 0).kind, 'unknown', 'an ok answer that is not the served_tools op is not believed');
  assert.equal(ssh(JSON.stringify({ ok: false, error: 'x', reason: 'door_closed' }), 2).kind, 'door_closed');
  assert.equal(ssh(JSON.stringify({ ok: false, error: 'agent config error' }), 2).kind, 'unknown');
  assert.equal(ssh(JSON.stringify({ ok: false, error: 'unknown client-update `op` "served_tools"' }), 1).kind, 'old_bridge');
  assert.equal(ssh(JSON.stringify({ ok: false, error: 'request must carry a non-empty `tool`' }), 1).kind, 'old_bridge');
  assert.equal(ssh(JSON.stringify({ ok: false, error: 'request must carry a non-empty `tool`', reason: 'bad_request' }), 1).kind, 'old_bridge');
  assert.equal(ssh(JSON.stringify({ ok: false, error: 'something else' }), 1).kind, 'unknown');
  assert.equal(http('<html>', 404).kind, 'old_bridge');
  assert.equal(http(JSON.stringify({ ok: false, error: 'down' }), 503).kind, 'unknown');
  assert.equal(http(JSON.stringify({ ok: false, error: 'unauthenticated' }), 401).kind, 'unknown');
  assert.equal(classifyServedToolsAnswer({ failure: 'ssh to x exceeded the deadline' }).kind, 'unknown');
});

test('readServedToolsCache: a missing or malformed file is no cache', (t) => {
  const root = scratch(t, 'served-root-');
  assert.equal(readServedToolsCache(root), null);
  fs.writeFileSync(path.join(root, SERVED_TOOLS_FILE), '{"launch_id": "x", "served": "ci_await"}');
  assert.equal(readServedToolsCache(root), null);
  writeCache(root, 'x', CI);
  assert.deepEqual(readServedToolsCache(root), { launchId: 'x', served: CI });
});
