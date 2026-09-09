// What this server actually PUTS ON THE WIRE for a board tool (card#8974 / DL-364).
//
// The defect these pin: the envelope carried `{tool, args}` and nothing about the copy of
// this server the seat is running, so `bridge:check` could compare nothing and a tool
// missing from a STALE seat snapshot was reported as absent from the BRIDGE. Measured on
// one install: a 0.4.4 seat against a bridge bundling 0.9.12.
//
// The envelope is asserted from the REAL server over a REAL ssh leg (a fake `ssh` first on
// PATH that captures its stdin), not from a re-implementation of the call site — the whole
// value of the field is that it reaches the far end, and only the wire says whether it did.
//
// ⛔ The version is compared against `package.json` READ AT RUN TIME, never a literal. A
// literal here is a second copy of the field the DL-038 bump guard maintains, so it would
// red on every snapshot bump and teach the next reader to update it without looking.
//
// Run: `node --test examples/channel-servers/tests/`.
import './live-state-guard.mjs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { scratch, connectServer } from './mcp-harness.mjs';

const TARGET = 'bridge@testhost';
const serverOpts = { name: 'envelope-test', runtimePrefix: 'envelope-rt-' };

const PACKAGE_JSON = path.join(
  path.dirname(fileURLToPath(import.meta.url)),
  '..',
  'package.json',
);

/** The version the shipped manifest declares — the value the server must send. */
function manifestVersion() {
  return JSON.parse(fs.readFileSync(PACKAGE_JSON, 'utf8')).version;
}

// A fake `ssh` that CAPTURES the payload written to its stdin (rather than draining it to
// /dev/null, as the failure suite's fake does) and answers with a well-formed envelope, so
// the call completes normally and the captured bytes are what a real bridge would have
// parsed.
function capturingSsh(t) {
  const binDir = scratch(t, 'envelope-bin-');
  const capture = path.join(binDir, 'payload.json');
  fs.writeFileSync(
    path.join(binDir, 'ssh'),
    '#!/usr/bin/env bash\n' +
      `cat > "${capture}"\n` +
      'printf \'{"ok":true,"tool":"board_my_cards","result":{"cards":[]}}\'\n',
    { mode: 0o755 },
  );
  return { binDir, capture };
}

/** Drive one real tools/call through the real server and return the envelope it wrote. */
async function envelopeOf(t, toolArgs = {}) {
  const { binDir, capture } = capturingSsh(t);
  const client = await connectServer(
    t,
    {
      PATH: `${binDir}${path.delimiter}${process.env.PATH}`,
      BRIDGE_TOOLS_SSH_TARGET: TARGET,
    },
    serverOpts,
  );
  const res = await client.callTool({ name: 'board_my_cards', arguments: toolArgs });
  assert.notEqual(res.isError, true, `the call itself failed: ${JSON.stringify(res)}`);

  return JSON.parse(fs.readFileSync(capture, 'utf8'));
}

test('the tools-call envelope carries this server\'s own package version', async (t) => {
  const sent = await envelopeOf(t);

  assert.equal(
    sent.client_version,
    manifestVersion(),
    `client_version must be the manifest's version; got ${JSON.stringify(sent)}`,
  );
});

// The pairing that makes the assertion above mean something: the field is ADDITIVE. A
// change that carried the version by rewriting or dropping either of the two keys the
// bridge dispatches on would satisfy the version assertion alone.
test('the version is ADDITIVE — tool and args reach the bridge unchanged', async (t) => {
  const sent = await envelopeOf(t, { include_description: true });

  assert.equal(sent.tool, 'board_my_cards');
  assert.deepEqual(sent.args, { include_description: true });
  assert.deepEqual(
    Object.keys(sent).sort(),
    ['args', 'client_version', 'tool'],
    `the envelope grew or lost a key: ${JSON.stringify(sent)}`,
  );
});

// The control for the manifest read itself. `readClientVersion()` returns null on ANY
// manifest fault and the key is then omitted — a shape the bridge must accept, because it
// is also the shape every client older than this release sends. Driving the null branch
// through the real server would take a deployment with no `package.json`, which cannot
// resolve its bare imports; what is asserted here instead is that the value being sent is
// the manifest's and not a literal baked into the entry point, so a manifest that moves
// moves the wire with it.
test('the version SENT tracks the manifest rather than a literal in the entry point', async (t) => {
  const entry = fs.readFileSync(
    path.join(path.dirname(PACKAGE_JSON), 'agent-webhook-bridge-channel.mjs'),
    'utf8',
  );
  const version = manifestVersion();

  assert.ok(
    !entry.includes(`'${version}'`) && !entry.includes(`"${version}"`),
    'the entry point contains the snapshot version as a literal — the manifest read is not the only source',
  );
  const sent = await envelopeOf(t);
  assert.equal(sent.client_version, version);
});
