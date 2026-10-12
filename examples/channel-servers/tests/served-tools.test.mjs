// What the channel server ADVERTISES is what the bridge says it serves THIS agent (card#11283):
// the served set ∩ TOOL_DEFINITIONS, resolved once, before the MCP handshake, from the served-tools
// cache a background update check wrote (this launch's or an earlier one's), else today's env
// rule. The bridge is NOT asked at start-up (card#11760, DL-476): a door slow under a saturated
// host must never hold the MCP connect window open. Each case is driven here through the REAL
// server over stdio, against a fake `ssh` first on PATH that records every call (or an http door
// that never answers), so a test can tell that no call was made.
//
// Run: `node --test examples/channel-servers/tests/`.
import './live-state-guard.mjs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import http from 'node:http';
import { scratch, connectServer } from './mcp-harness.mjs';
import { readServedToolsCache, SERVED_TOOLS_FILE } from '../channel-lib.mjs';

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
async function overSsh(t, { served = null, cache = null, extra = {} } = {}) {
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
    ...extra,
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

// --- the cache ------------------------------------------------------------------------------- //

test('this launch\'s cache is used, and the door is not asked', async (t) => {
  const { client, calls } = await overSsh(t, { served: ok(ALL), cache: { launchId: THIS_LAUNCH, served: CI } });

  assert.deepEqual(await advertised(client), CI);
  assert.deepEqual(calls, [], 'a cache answers the question; the door is not asked');
});

test('an earlier launch\'s cache is used, and the door is not asked even though it would answer otherwise (card#11760)', async (t) => {
  const { client, calls } = await overSsh(t, { served: ok(ALL), cache: { launchId: 'an-earlier-launch', served: CI } });

  assert.deepEqual(await advertised(client), CI);
  assert.deepEqual(calls, [], 'no served_tools call at start-up: the next check refreshes the cache for the next launch');
});

test('an impl (CI-only) agent: only ci_* is listed, and the instructions name no board tool', async (t) => {
  const { client } = await overSsh(t, { cache: { launchId: 'an-earlier-launch', served: CI } });

  assert.deepEqual(await advertised(client), CI);
  const instructions = client.getInstructions();
  assert.match(instructions, /ci_await/);
  assert.doesNotMatch(instructions, /board tools/i, instructions);
  assert.doesNotMatch(instructions, /board_/, instructions);
});

test('a scoped agent: every served tool is listed, and the instructions describe the board tools', async (t) => {
  const { client } = await overSsh(t, { cache: { launchId: 'an-earlier-launch', served: ALL } });

  assert.deepEqual(await advertised(client), ALL);
  assert.match(client.getInstructions(), /board tools scoped to YOUR channel identity/);
});

test('a served name this server has no definition for is not advertised (served ∩ TOOL_DEFINITIONS)', async (t) => {
  const { client } = await overSsh(t, { cache: { launchId: 'an-earlier-launch', served: ['ci_await', 'board_future_tool'] } });

  assert.deepEqual(await advertised(client), ['ci_await']);
});

// --- no cache: the env rule ------------------------------------------------------------------- //

test('no cache at all: today\'s env rule, every tool, and the door is not asked (card#11760)', async (t) => {
  const { client, calls } = await overSsh(t, { served: ok(CI) });

  assert.deepEqual(await advertised(client), ALL);
  assert.deepEqual(calls, []);
});

test('an http door that accepts the connection and never answers does not delay the start-up: it is never asked (card#11760)', { timeout: 30000 }, async (t) => {
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
  assert.equal(held.length, 0, 'the door was never reached');
  assert.ok(elapsed < 4500, `the start-up did not wait on the door (${elapsed} ms)`);
});

test('BRIDGE_CHANNEL_TOOLS=0 still turns the bridge tools off, and the door is not asked', async (t) => {
  const { client, calls } = await overSsh(t, { served: ok(CI), extra: { BRIDGE_CHANNEL_TOOLS: '0' } });

  assert.deepEqual(await advertised(client), []);
  assert.deepEqual(calls, []);
});

// --- the cache reader, directly ----------------------------------------------------------------- //

test('readServedToolsCache: a missing or malformed file is no cache', (t) => {
  const root = scratch(t, 'served-root-');
  assert.equal(readServedToolsCache(root), null);
  fs.writeFileSync(path.join(root, SERVED_TOOLS_FILE), '{"launch_id": "x", "served": "ci_await"}');
  assert.equal(readServedToolsCache(root), null);
  writeCache(root, 'x', CI);
  assert.deepEqual(readServedToolsCache(root), { launchId: 'x', served: CI });
});
