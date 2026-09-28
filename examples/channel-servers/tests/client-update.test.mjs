// The seat's launch-time client update (card#10568, design §3.6), driven end to end: a real
// seat root, the REAL entry.mjs / client-update.mjs / channel-lib.mjs, and a local fixture
// bridge speaking the client-update door. Every failure case asserts the same outcome — the
// installed release still starts, the tree under versions/ and current.json is unchanged, and
// the failure is loud (state.json, the install log, and the report to the bridge).
//
// Run: `node --test examples/channel-servers/tests/`.
import './live-state-guard.mjs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { spawn, spawnSync } from 'node:child_process';
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { StdioClientTransport } from '@modelcontextprotocol/sdk/client/stdio.js';
import { scratch } from './mcp-harness.mjs';
import { SOURCE_DIR, sha256, buildPack, clientFiles, goodPack, fixtureBridge } from './client-update-fixture.mjs';
import {
  compareReleases,
  decideLaunch,
  failureMarkerPath,
  composeState,
  STRICT_RELEASE,
} from '../entry.mjs';
import {
  MAX_REPORT_ENTRIES,
  MAX_REPORT_BYTES,
  InstallLog,
  reportBatches,
  satisfiesEngines,
  checkPackPath,
  clientDoorUrl,
  renameWithRetry,
  shimFor,
  clipBytes,
  Refusal,
} from '../client-update.mjs';
import { launchIdentity, clientUpdateInstruction } from '../channel-lib.mjs';

const UPDATER = path.join(SOURCE_DIR, 'client-update.mjs');
const VECTORS = path.join(SOURCE_DIR, '..', '..', 'tests', 'Fixtures', 'version-comparator-vectors.json');

// ---------------------------------------------------------------------------------------------
// Harness

/** An allowlisted environment: nothing of the seat running this suite reaches the child. */
function seatEnv(t, bridge, extra = {}) {
  const dir = scratch(t, 'cu-env-');
  const env = {
    PATH: process.env.PATH,
    SystemRoot: process.env.SystemRoot,
    TEMP: process.env.TEMP,
    TMP: process.env.TMP,
    TMPDIR: process.env.TMPDIR,
    HOME: process.env.HOME,
    USERPROFILE: process.env.USERPROFILE,
    BRIDGE_CHANNEL_NAME: 'cu-test',
    BRIDGE_CHANNEL_TRANSPORT: 'http',
    BRIDGE_CHANNEL_PORT: '0',
    XDG_RUNTIME_DIR: dir,
    BRIDGE_TOOLS_ENDPOINT: bridge ? bridge.endpoint : 'http://127.0.0.1:9/agent-tools/call',
    BRIDGE_TOOLS_TOKEN: bridge ? bridge.state.token : 'unused',
    TEST_RECORD: path.join(dir, 'record.jsonl'),
    ...extra,
  };
  for (const key of Object.keys(env)) {
    if (env[key] === undefined) {
      delete env[key];
    }
  }
  return env;
}

function writePack(t, built) {
  const dir = scratch(t, 'cu-pack-');
  const pack = path.join(dir, built.manifest.pack.file);
  const manifest = path.join(dir, 'manifest.json');
  fs.writeFileSync(pack, built.pack);
  fs.writeFileSync(manifest, built.manifestBytes);
  return { pack, manifest };
}

/** The bootstrap (design §3.5): `client-update.mjs install` into `root`. */
function bootstrap(t, root, built) {
  const files = writePack(t, built);
  return spawnSync(process.execPath, [UPDATER, 'install', '--pack', files.pack, '--manifest', files.manifest, '--root', root], { encoding: 'utf8' });
}

function newRoot(t, prefix = 'cu-root-') {
  return scratch(t, prefix);
}

async function seatWith(t, release, opts = {}) {
  const root = newRoot(t, opts.prefix);
  const r = bootstrap(t, root, opts.pack ?? goodPack(release));
  assert.equal(r.status, 0, `bootstrap failed: ${r.stderr}`);
  return root;
}

/**
 * One launch of `<root>/entry.mjs`, as Claude Code would spawn it at the start of a NEW session.
 * Every launch here is spawned by this one test process, which the session guard would rightly
 * read as the same live parent — a reconnect — so the previous launch's record is removed first:
 * a new session is a different parent, which is what no record amounts to. The guard itself is
 * tested with explicit records below (`reconnect: true` keeps the record).
 */
function launch(root, env, { reconnect = false } = {}) {
  if (!reconnect) {
    fs.rmSync(path.join(root, 'launch.json'), { force: true });
  }
  return new Promise((resolve) => {
    const child = spawn(process.execPath, [path.join(root, 'entry.mjs')], { env, stdio: ['pipe', 'pipe', 'pipe'] });
    let stdout = '';
    let stderr = '';
    child.stdout.on('data', (c) => (stdout += c));
    child.stderr.on('data', (c) => (stderr += c));
    child.stdin.end();
    child.on('close', (code, signal) => {
      let records = [];
      try {
        records = fs.readFileSync(env.TEST_RECORD, 'utf8').trim().split('\n').filter(Boolean).map((l) => JSON.parse(l));
        fs.rmSync(env.TEST_RECORD);
      } catch {
        records = [];
      }
      assert.equal(stdout, '', `nothing may reach stdout before the MCP handshake; got ${JSON.stringify(stdout)}`);
      resolve({ code, signal, stderr, records, started: records.length > 0 ? records[records.length - 1] : null });
    });
  });
}

const read = (root, name) => JSON.parse(fs.readFileSync(path.join(root, name), 'utf8'));
const logLines = (root) => {
  try {
    return fs.readFileSync(path.join(root, 'install-log.jsonl'), 'utf8').split('\n').filter(Boolean);
  } catch {
    return [];
  }
};
const logObjs = (root) => logLines(root).map((l) => JSON.parse(l));

/** What must not change on a failed update: the version directories and the pointer, byte for byte. */
function treeOf(root) {
  const versions = fs.existsSync(path.join(root, 'versions')) ? fs.readdirSync(path.join(root, 'versions')).sort() : [];
  const verified = versions.map((v) => {
    try {
      return fs.readFileSync(path.join(root, 'versions', v, '.verified'), 'utf8');
    } catch {
      return null;
    }
  });
  let pointer = null;
  try {
    pointer = fs.readFileSync(path.join(root, 'current.json'), 'utf8');
  } catch {
    pointer = null;
  }
  return { versions, verified, pointer };
}

/** The log is one chain: seq 1…n, each prev_sha256 the sha256 of the line before, one install id. */
function assertChain(root) {
  const lines = logLines(root);
  assert.ok(lines.length > 0, 'the install log is empty');
  const ids = new Set();
  lines.forEach((line, i) => {
    const obj = JSON.parse(line);
    ids.add(obj.install_id);
    assert.equal(obj.seq, i + 1, `line ${i + 1} has seq ${obj.seq}`);
    assert.equal(obj.prev_sha256, i === 0 ? null : sha256(Buffer.from(lines[i - 1])), `line ${i + 1} does not chain`);
    assert.ok(Buffer.byteLength(line) <= 4096, `line ${i + 1} is over the bridge's line cap`);
    if (obj.actor === 'launch') {
      assert.match(obj.launch_id, /^[0-9A-Za-z-]{1,64}$/, `launch line ${i + 1} carries no launch id`);
    }
  });
  assert.equal(ids.size, 1, `one install id across the log, got ${[...ids]}`);
  assert.match([...ids][0], /^[0-9a-z-]{1,64}$/, 'the install id is one the bridge accepts (lower case)');
  return lines.map((l) => JSON.parse(l));
}

// ---------------------------------------------------------------------------------------------
// Bootstrap and the normal path

test('bootstrap installs a pack into an empty root: pointer, verified release, entry.mjs, shim, a bootstrap line', async (t) => {
  const root = newRoot(t);
  const built = goodPack('1.0.0');
  const r = bootstrap(t, root, built);

  assert.equal(r.status, 0, r.stderr);
  assert.match(r.stdout, /release 1\.0\.0 installed/);
  assert.equal(read(root, 'current.json').bridge_release, '1.0.0');
  assert.equal(read(root, 'current.json').pack_sha256, built.manifest.pack.sha256);
  assert.equal(fs.readFileSync(path.join(root, 'versions', '1.0.0', '.verified'), 'utf8').trim(), built.manifest.pack.sha256);
  assert.deepEqual(fs.readFileSync(path.join(root, 'entry.mjs')), fs.readFileSync(path.join(SOURCE_DIR, 'entry.mjs')));
  const shim = process.platform === 'win32' ? 'check-channel-snapshot.py.cmd' : 'check-channel-snapshot.py';
  assert.ok(fs.existsSync(path.join(root, 'bin', shim)), 'the seat-tool shim is written');
  const [line] = assertChain(root).filter((l) => l.action === 'bootstrap');
  assert.equal(line.actor, 'provision');
  assert.equal(line.result, 'ok');
  assert.equal(line.to_bridge_release, '1.0.0');
  assert.equal(line.files_json_sha256, built.manifest.files_json_sha256);
  assert.equal(line.manifest_sha256, sha256(built.manifestBytes));
  assert.ok(!fs.existsSync(path.join(root, 'staging')), 'no staging left behind');
  assert.ok(!fs.existsSync(path.join(root, '.lock')), 'the lock is released');
});

test('the seat-tool shim runs the tool of the release current.json names', { skip: process.platform === 'win32' && 'the .cmd shim is validated on Windows by its device agent' }, async (t) => {
  const root = await seatWith(t, '1.0.0');
  const out = spawnSync(path.join(root, 'bin', 'check-channel-snapshot.py'), [], { encoding: 'utf8' });

  assert.equal(out.status, 0, out.stderr);
  assert.equal(out.stdout.trim(), 'seat tool of 1.0.0');
});

test('a launch on the published release starts it, writes state current, and reports the log', async (t) => {
  const root = await seatWith(t, '1.0.0');
  const bridge = await fixtureBridge(t, { published: goodPack('1.0.0') });
  const run = await launch(root, seatEnv(t, bridge));

  assert.equal(run.code, 0, run.stderr);
  assert.equal(run.started.release, '1.0.0');
  const state = read(root, 'state.json');
  assert.equal(state.state, 'current');
  assert.equal(state.running, '1.0.0');
  assert.equal(run.started.launch, state.launch_id, 'the server runs under the launch state.json describes');
  assert.equal(run.started.bridge_release, '1.0.0');
  assert.equal(run.started.root, fs.realpathSync(root) === root ? root : run.started.root);
  assert.equal(read(root, 'launch.json').launch_id, state.launch_id);
  assert.deepEqual(bridge.requests.map((r) => r.body.op), ['client_manifest', 'client_report'], 'no pack is fetched when current');
  assert.deepEqual(bridge.reports[0].entries, logLines(root), 'the whole log goes to a bridge that holds none of it');
});

test('a newer published release is installed at launch and runs in that same launch', async (t) => {
  const root = await seatWith(t, '1.0.0');
  const two = goodPack('2.0.0');
  const bridge = await fixtureBridge(t, { published: two });
  const run = await launch(root, seatEnv(t, bridge));

  assert.equal(run.code, 0, run.stderr);
  assert.equal(run.started.release, '2.0.0');
  assert.equal(run.started.bridge_release, '2.0.0', 'calls will name the release actually running');
  const state = read(root, 'state.json');
  assert.equal(state.state, 'current');
  assert.equal(state.running, '2.0.0');
  assert.equal(read(root, 'current.json').bridge_release, '2.0.0');
  const install = assertChain(root).find((l) => l.action === 'install');
  assert.equal(install.from_bridge_release, '1.0.0');
  assert.equal(install.to_bridge_release, '2.0.0');
  assert.equal(install.actor, 'launch');
  assert.equal(install.launch_id, state.launch_id);
  assert.equal(install.pack_sha256, two.manifest.pack.sha256);
  assert.equal(install.source, `bridge-http:http://127.0.0.1:${bridge.port}/agent-tools/client`);
  assert.deepEqual(fs.readdirSync(path.join(root, 'versions')).sort(), ['1.0.0', '2.0.0'], 'the previous release is kept, never modified');
  assert.ok(bridge.reports.flatMap((r) => r.entries).some((l) => JSON.parse(l).action === 'install'), 'the install is reported in the same launch');
});

test('a later release prunes all but the current and one previous release, and logs the prune', async (t) => {
  const root = await seatWith(t, '1.0.0');
  const bridge = await fixtureBridge(t, { published: goodPack('2.0.0') });
  await launch(root, seatEnv(t, bridge));
  bridge.state.published = goodPack('3.0.0');
  const run = await launch(root, seatEnv(t, bridge));

  assert.equal(run.started.release, '3.0.0');
  assert.deepEqual(fs.readdirSync(path.join(root, 'versions')).sort(), ['2.0.0', '3.0.0']);
  const prune = assertChain(root).find((l) => l.action === 'prune');
  assert.match(prune.reason, /removed 1\.0\.0/);
});

test('entry.mjs is replaced only when the new release carries different bytes for it', async (t) => {
  const root = await seatWith(t, '1.0.0');
  const bridge = await fixtureBridge(t, { published: goodPack('2.0.0') });
  await launch(root, seatEnv(t, bridge));
  assert.ok(!logObjs(root).some((l) => l.action === 'entry_replace'), 'same bytes: not replaced');

  const three = buildPack({ release: '3.0.0', files: clientFiles('3.0.0', { entryMarker: '\n// release 3\n' }) });
  bridge.state.published = three;
  await launch(root, seatEnv(t, bridge));

  const replace = logObjs(root).filter((l) => l.action === 'entry_replace');
  assert.equal(replace.length, 1);
  assert.match(fs.readFileSync(path.join(root, 'entry.mjs'), 'utf8'), /\/\/ release 3\n$/);
});

// ---------------------------------------------------------------------------------------------
// Refusals: logged, reported, and the installed release keeps running

const refusals = {
  'a pack whose bytes do not hash to the manifest': { serve: (p) => Buffer.concat([Buffer.from([p.pack[0] ^ 0xff]), p.pack.subarray(1)]), reason: /does not hash to its manifest's pack\.sha256/ },
  'a pack of the wrong size': { serve: (p) => p.pack.subarray(0, p.pack.length - 1), reason: /bytes; its manifest says/ },
  'a manifest that does not hash to the digest the door named': { manifestSha256: 'f'.repeat(64), reason: /does not hash to the manifest_sha256 it named/ },
  'a file that does not match its FILES.json line': {
    pack: () => {
      const files = clientFiles('2.0.0');
      const listing = files.map((f) => ({ path: f.path, mode: (f.mode ?? 0o644).toString(8).padStart(4, '0'), sha256: sha256(f.data), size: f.data.length }));
      listing[2].sha256 = '0'.repeat(64);
      return buildPack({ release: '2.0.0', files, filesJson: listing });
    },
    reason: /does not match its FILES\.json line/,
  },
  'an entry that climbs out with ..': { pack: () => buildPack({ release: '2.0.0', files: clientFiles('2.0.0'), extraEntries: [{ name: 'client/../../x', data: Buffer.from('x') }] }), reason: /leaves or re-enters its directory/ },
  'an absolute entry': { pack: () => buildPack({ release: '2.0.0', files: clientFiles('2.0.0'), extraEntries: [{ name: '/tmp/x', data: Buffer.from('x') }] }), reason: /not a plain relative path/ },
  'a symbolic link': { pack: () => buildPack({ release: '2.0.0', files: clientFiles('2.0.0'), extraEntries: [{ name: 'client/link', type: '2', linkname: '/etc/passwd' }] }), reason: /symbolic link/ },
  'an entry FILES.json does not declare': { pack: () => buildPack({ release: '2.0.0', files: clientFiles('2.0.0'), extraEntries: [{ name: 'client/extra.mjs', data: Buffer.from('x') }] }), reason: /does not declare/ },
  'a pack with no updater': { pack: () => buildPack({ release: '2.0.0', files: clientFiles('2.0.0', { omit: ['client/client-update.mjs'] }) }), reason: /holds no client\/client-update\.mjs/ },
  'a pack needing a node this seat does not run': { pack: () => goodPack('2.0.0', { nodeEngines: '>=99' }), reason: /needs node >=99/ },
  'a node range this updater does not evaluate': { pack: () => goodPack('2.0.0', { nodeEngines: '^20' }), reason: /does not evaluate/ },
  'a downgrade': { installed: '3.0.0', pack: () => goodPack('2.0.0'), reason: /downgrade offered: release 2\.0\.0 is below the installed 3\.0\.0/ },
  'the same release with other bytes': { installed: '2.0.0', pack: () => buildPack({ release: '2.0.0', files: clientFiles('2.0.0', { marker: '// other bytes\n' }) }), reason: /same release, different bytes/ },
};

for (const [name, c] of Object.entries(refusals)) {
  test(`refused, logged, reported, tree unchanged: ${name}`, async (t) => {
    const installed = c.installed ?? '1.0.0';
    const root = await seatWith(t, installed);
    const published = c.pack ? c.pack() : goodPack('2.0.0');
    const bridge = await fixtureBridge(t, { published, packBytes: c.serve ? c.serve(published) : null, manifestSha256: c.manifestSha256 });
    const before = treeOf(root);

    const run = await launch(root, seatEnv(t, bridge));

    assert.equal(run.code, 0, run.stderr);
    assert.equal(run.started.release, installed, 'the installed release still starts');
    assert.deepEqual(treeOf(root), before, 'versions/ and current.json are unchanged');
    assert.ok(!fs.existsSync(path.join(root, 'staging')) || fs.readdirSync(path.join(root, 'staging')).length === 0, 'no staging left behind');
    const state = read(root, 'state.json');
    assert.equal(state.state, 'update_failed');
    assert.match(state.error, /^refused: /);
    assert.match(state.error, c.reason);
    const last = assertChain(root).at(-1);
    assert.equal(last.action, 'refuse');
    assert.equal(last.result, 'refused');
    assert.match(last.reason, c.reason);
    assert.ok(bridge.reports.flatMap((r) => r.entries).includes(logLines(root).at(-1)), 'the refusal reaches the bridge in the same launch');
  });
}

// ---------------------------------------------------------------------------------------------
// Failures that are not refusals

test('bridge unreachable: the installed release starts, the failure is logged, and reported at the next launch', async (t) => {
  const root = await seatWith(t, '1.0.0');
  const run = await launch(root, seatEnv(t, null));

  assert.equal(run.started.release, '1.0.0');
  const state = read(root, 'state.json');
  assert.equal(state.state, 'update_failed');
  assert.match(state.error, /^bridge unreachable/);
  const fail = logObjs(root).at(-1);
  assert.equal(fail.action, 'fail');
  assert.equal(fail.launch_id, state.launch_id);

  const bridge = await fixtureBridge(t, { published: goodPack('1.0.0') });
  await launch(root, seatEnv(t, bridge));
  assert.ok(bridge.reports.flatMap((r) => r.entries).some((l) => JSON.parse(l).launch_id === state.launch_id && JSON.parse(l).action === 'fail'), 'the backlog carries the earlier launch\'s failure');
  assertChain(root);
});

test('a bridge that publishes nothing: update_failed with the bridge\'s own words, installed release starts', async (t) => {
  const root = await seatWith(t, '1.0.0');
  const bridge = await fixtureBridge(t, { published: null });
  const run = await launch(root, seatEnv(t, bridge));

  assert.equal(run.started.release, '1.0.0');
  assert.match(read(root, 'state.json').error, /answered client_manifest with HTTP 503: this bridge publishes no client pack yet/);
});

test('approval owed: nothing is fetched or swapped, and the approval_owed line is not repeated launch after launch', async (t) => {
  const root = await seatWith(t, '1.0.0');
  const bridge = await fixtureBridge(t, { published: goodPack('2.0.0'), offer: null, owed: '2.0.0' });
  const before = treeOf(root);
  const run = await launch(root, seatEnv(t, bridge));

  assert.equal(run.started.release, '1.0.0');
  assert.deepEqual(treeOf(root), before);
  const state = read(root, 'state.json');
  assert.equal(state.state, 'approval_owed');
  assert.equal(state.approval_owed, '2.0.0');
  assert.ok(!bridge.requests.some((r) => r.body.op === 'client_pack'), 'an unoffered pack is never fetched');

  await launch(root, seatEnv(t, bridge));
  assert.equal(logObjs(root).filter((l) => l.action === 'approval_owed').length, 1);
});

test('a lock held by another launch: no update, no log line, the installed release starts', async (t) => {
  const root = await seatWith(t, '1.0.0');
  const bridge = await fixtureBridge(t, { published: goodPack('2.0.0') });
  fs.writeFileSync(path.join(root, '.lock'), 'someone-else');
  const lines = logLines(root).length;
  const run = await launch(root, seatEnv(t, bridge));

  assert.equal(run.started.release, '1.0.0');
  assert.match(read(root, 'state.json').error, /^lock held/);
  assert.equal(logLines(root).length, lines, 'a launch without the lock writes nothing to the log');
  assert.equal(bridge.requests.length, 0);
});

test('a stale lock (older than twice the budget) is taken over', async (t) => {
  const root = await seatWith(t, '1.0.0');
  const bridge = await fixtureBridge(t, { published: goodPack('2.0.0') });
  fs.writeFileSync(path.join(root, '.lock'), 'a-dead-launch');
  const old = (Date.now() - 60000) / 1000;
  fs.utimesSync(path.join(root, '.lock'), old, old);
  const run = await launch(root, seatEnv(t, bridge, { AWB_CLIENT_UPDATE_BUDGET_MS: '5000' }));

  assert.equal(run.started.release, '2.0.0');
  assert.ok(!fs.existsSync(path.join(root, '.lock')));
});

test('an installed updater that cannot even load: update_failed, and the installed release still starts', async (t) => {
  const broken = buildPack({ release: '1.0.0', files: clientFiles('1.0.0', { updaterData: Buffer.from('export const x = ;\n') }) });
  const root = await seatWith(t, '1.0.0', { pack: broken });
  const bridge = await fixtureBridge(t, { published: goodPack('2.0.0') });
  const run = await launch(root, seatEnv(t, bridge));

  assert.equal(run.started.release, '1.0.0');
  const state = read(root, 'state.json');
  assert.equal(state.state, 'update_failed');
  assert.match(state.error, /^the installed updater failed: /);
});

test('an updater that rejects AFTER the budget ran out does not take the running server down with it', async (t) => {
  // The late rejection lands while the server is already serving; unhandled, node would exit.
  const late = Buffer.from(
    'export async function runLaunchUpdate({ budgetMs }) {\n' +
      '  await new Promise((resolve) => setTimeout(resolve, budgetMs + 300));\n' +
      "  throw new Error('late failure');\n" +
      '}\n',
  );
  const root = await seatWith(t, '1.0.0', { pack: buildPack({ release: '1.0.0', files: clientFiles('1.0.0', { updaterData: late }) }) });
  const bridge = await fixtureBridge(t, { published: goodPack('2.0.0') });
  const run = await launch(root, seatEnv(t, bridge, { AWB_CLIENT_UPDATE_BUDGET_MS: '500' }));

  assert.equal(run.started.release, '1.0.0');
  assert.equal(run.code, 0, `the process survived the late rejection: ${run.stderr}`);
  assert.match(read(root, 'state.json').error, /500 ms update budget ran out/);
});

test('an invalid AWB_CLIENT_UPDATE_BUDGET_MS: no update attempted, said so, installed release starts', async (t) => {
  const root = await seatWith(t, '1.0.0');
  const bridge = await fixtureBridge(t, { published: goodPack('2.0.0') });
  const run = await launch(root, seatEnv(t, bridge, { AWB_CLIENT_UPDATE_BUDGET_MS: 'soon' }));

  assert.equal(run.started.release, '1.0.0');
  assert.match(read(root, 'state.json').error, /AWB_CLIENT_UPDATE_BUDGET_MS is "soon"/);
  assert.equal(bridge.requests.length, 0);
});

// ---------------------------------------------------------------------------------------------
// The deadline and cancellation (design review r2 M-2, r3-m2, r3-M8)

test('an updater that hangs past the budget never writes past its next signal check', async (t) => {
  const root = await seatWith(t, '1.0.0');
  const bridge = await fixtureBridge(t, { published: goodPack('2.0.0') });
  const before = treeOf(root);
  const started = Date.now();
  const run = await launch(root, seatEnv(t, bridge, { AWB_CLIENT_UPDATE_BUDGET_MS: '1500', AWB_CLIENT_CRASH_AT: 'budget-exceeded' }));

  assert.equal(run.started.release, '1.0.0', 'the installed release starts when the budget runs out');
  assert.ok(Date.now() - started >= 1500);
  // The process has exited, so the hung step has woken and met its signal check: nothing after it.
  assert.deepEqual(treeOf(root), before, 'no file under versions/ and no current.json change after the deadline');
  assert.ok(!fs.existsSync(path.join(root, 'staging', '2.0.0.partial')), 'staging is discarded');
  assert.ok(!fs.existsSync(path.join(root, '.lock')), 'the lock is released');
  const state = read(root, 'state.json');
  assert.equal(state.state, 'update_failed');
  assert.match(state.error, /1500 ms update budget ran out/);
  assert.equal(state.running, '1.0.0');
  const last = logObjs(root).at(-1);
  assert.equal(last.action, 'fail');
  assert.match(last.reason, /budget ran out before moving release 2\.0\.0/);
  assert.ok(!bridge.requests.some((r) => r.body.op === 'client_report'), 'no network work after the abort');
});

test('a bridge that holds the pack past the budget: the fetch is cancelled, nothing is reported after it', async (t) => {
  const root = await seatWith(t, '1.0.0');
  const bridge = await fixtureBridge(t, { published: goodPack('2.0.0'), delayMs: { client_pack: 8000 } });
  const started = Date.now();
  const run = await launch(root, seatEnv(t, bridge, { AWB_CLIENT_UPDATE_BUDGET_MS: '1500' }));

  assert.equal(run.started.release, '1.0.0');
  assert.ok(Date.now() - started < 7000, 'the process did not wait for the held answer');
  assert.deepEqual(bridge.requests.map((r) => r.body.op), ['client_manifest', 'client_pack']);
  assert.match(logObjs(root).at(-1).reason, /budget ran out while asking the bridge for client_pack/);
});

test('state.json names the release that was imported, never the one resolved before the update', () => {
  const before = { release: '1.0.0' };
  const moved = composeState({ launchId: 'L', outcome: { expired: true }, before, after: { release: '2.0.0' }, budget: 20 });
  assert.equal(moved.state, 'current');
  assert.equal(moved.late, true);
  assert.equal(moved.running, '2.0.0');

  const stayed = composeState({ launchId: 'L', outcome: { expired: true }, before, after: before, budget: 20 });
  assert.equal(stayed.state, 'update_failed');
  assert.equal(stayed.running, '1.0.0');

  const thrown = composeState({ launchId: 'L', outcome: { error: new Error('boom') }, before, after: before, budget: 20 });
  assert.equal(thrown.error, 'the installed updater failed: boom');
});

// ---------------------------------------------------------------------------------------------
// The session guard (design review r2 M-1)

test('a reconnect inside a live session does no network and no disk step, and keeps its launch id', async (t) => {
  const root = await seatWith(t, '1.0.0');
  const bridge = await fixtureBridge(t, { published: goodPack('2.0.0') });
  const launchJson = { launch_id: 'L-existing-1', ppid: process.pid, pid: 1, started_at: 'x' };
  fs.writeFileSync(path.join(root, 'launch.json'), JSON.stringify(launchJson));
  const before = treeOf(root);
  const lines = logLines(root).length;

  const run = await launch(root, seatEnv(t, bridge), { reconnect: true });

  assert.equal(run.started.release, '1.0.0');
  assert.equal(run.started.launch, 'L-existing-1');
  assert.equal(bridge.requests.length, 0, 'no network');
  assert.deepEqual(treeOf(root), before);
  assert.equal(logLines(root).length, lines);
  assert.ok(!fs.existsSync(path.join(root, 'state.json')), 'state.json is the launch\'s, not rewritten on a reconnect');
  assert.deepEqual(read(root, 'launch.json'), launchJson);
  assert.match(run.stderr, /skipped: session running/);
});

test('a launch record naming a dead parent is a new launch: a fresh id, and the update runs', async (t) => {
  const root = await seatWith(t, '1.0.0');
  const bridge = await fixtureBridge(t, { published: goodPack('2.0.0') });
  const dead = spawnSync(process.execPath, ['-e', 'process.stdout.write(String(process.pid))'], { encoding: 'utf8' });
  fs.writeFileSync(path.join(root, 'launch.json'), JSON.stringify({ launch_id: 'L-old', ppid: Number(dead.stdout), pid: 1, started_at: 'x' }));

  const run = await launch(root, seatEnv(t, bridge), { reconnect: true });

  assert.notEqual(run.started.launch, 'L-old');
  assert.equal(run.started.release, '2.0.0');
});

test('decideLaunch: only a live, matching parent is a reconnect', () => {
  const rec = { launch_id: 'L1', ppid: 4242 };
  assert.deepEqual(decideLaunch(rec, { ppid: 4242, alive: () => true }), { newLaunch: false, launchId: 'L1' });
  assert.equal(decideLaunch(rec, { ppid: 4242, alive: () => false }).newLaunch, true);
  assert.equal(decideLaunch(rec, { ppid: 99, alive: () => true }).newLaunch, true);
  assert.equal(decideLaunch({ launch_id: 'L1', ppid: 1 }, { ppid: 1, alive: () => true }).newLaunch, true, 'init is never a session');
  assert.equal(decideLaunch({ launch_id: 'bad id!', ppid: 4242 }, { ppid: 4242, alive: () => true }).newLaunch, true);
  assert.equal(decideLaunch(null).newLaunch, true);
  assert.match(decideLaunch(null).launchId, /^[0-9a-f-]{36}$/);
  // The real liveness probe: this process is alive; process.kill(pid, 0) is portable.
  assert.equal(decideLaunch({ launch_id: 'L2', ppid: process.pid }, { ppid: process.pid }).newLaunch, false);
});

// ---------------------------------------------------------------------------------------------
// Interrupted installs (design §3.6 crash hooks)

for (const [point, startsNew] of [['after-extract', false], ['before-rename', false], ['after-rename', false], ['after-pointer', true]]) {
  test(`killed ${point}: the next launch starts the ${startsNew ? 'new' : 'old'} release, and the one after converges`, async (t) => {
    const root = await seatWith(t, '1.0.0');
    const bridge = await fixtureBridge(t, { published: goodPack('2.0.0') });

    const killed = await launch(root, seatEnv(t, bridge, { AWB_CLIENT_CRASH_AT: point }));
    assert.equal(killed.started, null, 'the kill happens before any server starts');
    assert.ok(killed.signal === 'SIGKILL' || killed.code !== 0, `the launch was killed (${killed.code}/${killed.signal})`);
    // The dead launch's lock is stale by now as far as this test is concerned.
    if (fs.existsSync(path.join(root, '.lock'))) {
      const old = (Date.now() - 600000) / 1000;
      fs.utimesSync(path.join(root, '.lock'), old, old);
    }

    bridge.state.fail = { client_manifest: { status: 503, error: 'down for the test' } };
    const next = await launch(root, seatEnv(t, bridge));
    assert.equal(next.started.release, startsNew ? '2.0.0' : '1.0.0');

    bridge.state.fail = {};
    const third = await launch(root, seatEnv(t, bridge));
    assert.equal(third.started.release, '2.0.0');
    assert.equal(read(root, 'state.json').state, 'current');
    const log = assertChain(root);
    assert.equal(log.filter((l) => l.action === 'install' && l.to_bridge_release === '2.0.0').length, 1, 'exactly one install of 2.0.0 is logged');
  });
}

test('a zero-length current.json recovers to the newest verified release, and is repaired', async (t) => {
  const root = await seatWith(t, '1.0.0');
  const bridge = await fixtureBridge(t, { published: goodPack('2.0.0') });
  await launch(root, seatEnv(t, bridge));
  fs.writeFileSync(path.join(root, 'current.json'), '');

  const run = await launch(root, seatEnv(t, bridge));

  assert.equal(run.started.release, '2.0.0');
  assert.equal(read(root, 'current.json').bridge_release, '2.0.0');
  assert.equal(logObjs(root).at(-1).action === 'pointer_recovered' || logObjs(root).some((l) => l.action === 'pointer_recovered'), true);
  assertChain(root);
});

test('nothing installed: not started — exit 2, the .FAILED marker, and state.json say why', async (t) => {
  const root = newRoot(t);
  fs.copyFileSync(path.join(SOURCE_DIR, 'entry.mjs'), path.join(root, 'entry.mjs'));
  const env = seatEnv(t, null);
  const run = await launch(root, env);

  assert.equal(run.code, 2);
  assert.equal(run.started, null);
  assert.equal(read(root, 'state.json').state, 'not_started');
  assert.match(fs.readFileSync(failureMarkerPath(env), 'utf8'), /no verified client release is installed/);
});

test('a release whose server does not load: not started, loudly', async (t) => {
  const root = await seatWith(t, '1.0.0', { pack: buildPack({ release: '1.0.0', files: clientFiles('1.0.0', { serverData: Buffer.from('this is not javascript\n') }) }) });
  const env = seatEnv(t, null);
  const run = await launch(root, env);

  assert.equal(run.code, 2);
  assert.equal(read(root, 'state.json').state, 'not_started');
  assert.match(fs.readFileSync(failureMarkerPath(env), 'utf8'), /could not start release 1\.0\.0/);
});

// ---------------------------------------------------------------------------------------------
// The log and the report

test('the hash chain and the install id hold across successes and failures', async (t) => {
  const root = await seatWith(t, '1.0.0');
  const bridge = await fixtureBridge(t, { published: goodPack('2.0.0') });
  await launch(root, seatEnv(t, null));
  await launch(root, seatEnv(t, bridge));
  bridge.state.published = buildPack({ release: '2.0.0', files: clientFiles('2.0.0', { marker: '// tampered\n' }) });
  await launch(root, seatEnv(t, bridge));
  bridge.state.published = goodPack('3.0.0');
  await launch(root, seatEnv(t, bridge));

  const log = assertChain(root);
  assert.deepEqual(log.map((l) => l.action), ['bootstrap', 'fail', 'install', 'refuse', 'install', 'prune']);
  // The fixture bridge holds exactly the seat's log, in order.
  assert.deepEqual([...bridge.held.keys()].sort((a, b) => a - b).map((s) => bridge.held.get(s)), logLines(root));
});

test('a backlog is reported in batches bounded by BOTH the entry count and the byte size', async (t) => {
  const root = await seatWith(t, '1.0.0');
  const log = InstallLog.open(root, () => {});
  for (let i = 0; i < 260; i++) {
    log.append({ action: 'fail', result: 'failed', actor: 'provision', reason: `padding ${i} ${'é'.repeat(200)}` });
  }
  const bridge = await fixtureBridge(t, { published: goodPack('1.0.0') });
  await launch(root, seatEnv(t, bridge));

  assert.ok(bridge.reports.length >= 2);
  for (const report of bridge.reports) {
    assert.ok(report.entries.length <= MAX_REPORT_ENTRIES);
    assert.ok(report.entries.reduce((n, l) => n + Buffer.byteLength(l), 0) <= MAX_REPORT_BYTES);
  }
  assert.deepEqual(bridge.reports.flatMap((r) => r.entries), logLines(root));
  const raw = bridge.requests.find((r) => r.body.op === 'client_report');
  assert.ok(raw.bytes < 65536, 'a report fits the ssh door\'s stdin cap');
  assert.ok(JSON.stringify(raw.body).includes('é'), 'non-ASCII rides raw, never \\u-escaped');
});

test('reportBatches splits at whichever limit comes first', () => {
  const small = Array.from({ length: MAX_REPORT_ENTRIES + 5 }, (_, i) => ({ text: `{"seq":${i}}` }));
  assert.deepEqual(reportBatches(small).map((b) => b.length), [MAX_REPORT_ENTRIES, 5]);
  const big = Array.from({ length: 20 }, () => ({ text: 'x'.repeat(4000) }));
  const batches = reportBatches(big);
  assert.ok(batches.every((b) => b.reduce((n, l) => n + l.text.length, 0) <= MAX_REPORT_BYTES));
  assert.equal(batches.flat().length, 20);
});

// ---------------------------------------------------------------------------------------------
// The loud surface and the wire, through the REAL channel server

test('through the real server: the handshake, the INSTRUCTIONS line, and a call carrying the launch', async (t) => {
  // Inside this package, so the real server's bare `@modelcontextprotocol/sdk` import resolves
  // through ../node_modules — the pack itself carries none in this fixture.
  const root = fs.mkdtempSync(path.join(SOURCE_DIR, 'tests', '.client-root-'));
  t.after(() => fs.rmSync(root, { recursive: true, force: true }));
  assert.equal(bootstrap(t, root, goodPack('1.0.0', { realServer: true })).status, 0);
  const bridge = await fixtureBridge(t, { published: goodPack('2.0.0'), offer: null, owed: '2.0.0' });
  const env = seatEnv(t, bridge);
  const transport = new StdioClientTransport({ command: process.execPath, args: [path.join(root, 'entry.mjs')], env, stderr: 'ignore' });
  const client = new Client({ name: 'cu-e2e', version: '1.0.0' }, { capabilities: {} });
  await client.connect(transport);
  t.after(() => client.close());

  assert.equal(client.getServerVersion().version, '0.9.29');
  assert.match(client.getInstructions(), /^CLIENT RELEASE 2\.0\.0 IS PUBLISHED by your bridge, and this seat requires PM approval/);
  await client.callTool({ name: 'board_my_cards', arguments: {} });
  const state = read(root, 'state.json');
  assert.deepEqual(bridge.calls[0].launch, { id: state.launch_id, bridge_release: '1.0.0' });
});

// ---------------------------------------------------------------------------------------------
// Units: the pure pieces, and what the Windows job exercises

test('compareReleases holds the shared comparator vectors (PHP, Python and Node read one file)', () => {
  const { vectors } = JSON.parse(fs.readFileSync(VECTORS, 'utf8'));
  assert.ok(vectors.length > 0);
  for (const [a, b, expected] of vectors) {
    assert.equal(compareReleases(a, b), expected, `${a} vs ${b}`);
    assert.equal(compareReleases(b, a), 0 - expected, `${b} vs ${a}`);
  }
});

test('a release outside bare X.Y.Z is never compared: the grammar refuses it first', () => {
  for (const bad of ['v1.0.0', '1.0', '1.0.0-rc1', '1.0.0\n', '1234567890.0.0', '']) {
    assert.equal(STRICT_RELEASE.test(bad), false, JSON.stringify(bad));
  }
  assert.equal(STRICT_RELEASE.test('0.91.0'), true);
});

test('satisfiesEngines evaluates only the forms it can answer exactly', () => {
  assert.equal(satisfiesEngines('>=20', '20.0.0'), true);
  assert.equal(satisfiesEngines('>=20', '19.9.9'), false);
  assert.equal(satisfiesEngines('>=20.3 <23', '22.1.0'), true);
  assert.equal(satisfiesEngines('>=20.3 <23', '23.0.0'), false);
  assert.equal(satisfiesEngines('<18 || >=20', '20.1.0'), true);
  assert.equal(satisfiesEngines('=20.1.0', '20.1.0'), true);
  for (const unsupported of ['^20', '~20.1', '20.x', '>20', '20']) {
    assert.throws(() => satisfiesEngines(unsupported, '20.0.0'), Refusal, unsupported);
  }
});

test('checkPackPath admits only plain relative paths under client/ and seat-tools/bin/', () => {
  for (const ok of ['client/a.mjs', 'client/node_modules/x/y.js', 'seat-tools/bin/tool', 'FILES.json']) {
    assert.doesNotThrow(() => checkPackPath(ok), ok);
  }
  for (const bad of ['../x', 'client/../../x', '/etc/x', 'client//x', 'client/./x', 'client\\..\\x', 'C:/x', 'client/a:b', 'other/x', 'seat-tools/bin/a/b', 'client']) {
    assert.throws(() => checkPackPath(bad), Refusal, bad);
  }
});

test('clientDoorUrl sits beside /agent-tools/call, prefix kept, and refuses anything else', () => {
  assert.equal(clientDoorUrl('http://127.0.0.1:8787/agent-tools/call'), 'http://127.0.0.1:8787/agent-tools/client');
  assert.equal(clientDoorUrl('http://127.0.0.1:8787/bridge/agent-tools/call?x=1'), 'http://127.0.0.1:8787/bridge/agent-tools/client');
  assert.throws(() => clientDoorUrl('http://127.0.0.1:8787/other'));
  assert.throws(() => clientDoorUrl('not a url'));
});

test('launchIdentity sends a launch only when both halves pass the bridge\'s whitelist', () => {
  assert.deepEqual(launchIdentity({ AWB_LAUNCH_ID: 'abc-1', AWB_BRIDGE_RELEASE: '0.91.0' }), { id: 'abc-1', bridge_release: '0.91.0' });
  assert.equal(launchIdentity({}), null);
  assert.equal(launchIdentity({ AWB_LAUNCH_ID: 'abc-1' }), null);
  assert.equal(launchIdentity({ AWB_LAUNCH_ID: 'abc 1', AWB_BRIDGE_RELEASE: '0.91.0' }), null);
  assert.equal(launchIdentity({ AWB_LAUNCH_ID: 'abc-1', AWB_BRIDGE_RELEASE: 'v0.91.0' }), null);
});

test('clientUpdateInstruction: silent when current, loud otherwise, and never believes another launch\'s state', () => {
  const root = '/r';
  const base = { launch_id: 'L', running: '1.0.0' };
  assert.equal(clientUpdateInstruction({ ...base, state: 'current' }, { launchId: 'L', root }), null);
  assert.equal(
    clientUpdateInstruction({ ...base, state: 'update_failed', error: 'bridge unreachable (x)', published: '2.0.0' }, { launchId: 'L', root }),
    'CLIENT UPDATE FAILED (bridge unreachable (x)): this seat runs channel-server release 1.0.0; published release 2.0.0 was not applied. ' +
      'Tell your operator; the install log is /r/install-log.jsonl. The update is tried again at the next launch; if the installed updater itself is broken, ' +
      "bootstrapping this seat's client again from the bridge's published pack repairs it.",
  );
  assert.doesNotMatch(clientUpdateInstruction({ ...base, state: 'update_failed', error: 'x', published: '1.0.0' }, { launchId: 'L', root }), /was not applied/);
  assert.match(clientUpdateInstruction({ ...base, state: 'approval_owed', approval_owed: '2.0.0' }, { launchId: 'L', root }), /^CLIENT RELEASE 2\.0\.0 IS PUBLISHED/);
  assert.match(clientUpdateInstruction({ ...base, state: 'current' }, { launchId: 'OTHER', root }), /^CLIENT UPDATE STATE UNKNOWN: .*names launch L, not this one/);
  assert.match(clientUpdateInstruction({ unreadable: 'ENOENT' }, { launchId: 'L', root }), /could not be read: ENOENT/);
  assert.match(clientUpdateInstruction({ ...base, state: 'weird' }, { launchId: 'L', root }), /names state "weird"/);
});

test('clipBytes cuts on a character boundary within the byte cap', () => {
  const cut = clipBytes('é'.repeat(400), 500);
  assert.ok(Buffer.byteLength(cut) <= 500);
  assert.ok(cut.endsWith('…'));
  assert.ok(!cut.includes('\uFFFD'));
  assert.equal(clipBytes('short', 500), 'short');
});

test('Windows: a rename refused with EPERM is retried until it succeeds, and gives up at the deadline', () => {
  let calls = 0;
  const flaky = () => {
    calls += 1;
    if (calls < 3) {
      throw Object.assign(new Error('busy'), { code: 'EPERM' });
    }
  };
  renameWithRetry('a', 'b', { deadline: Date.now() + 5000, rename: flaky, platform: 'win32', sleep: () => {} });
  assert.equal(calls, 3);

  const never = () => {
    throw Object.assign(new Error('busy'), { code: 'EPERM' });
  };
  assert.throws(() => renameWithRetry('a', 'b', { deadline: Date.now() + 50, rename: never, platform: 'win32', sleep: () => {} }), /busy/);
  calls = 0;
  assert.throws(() => renameWithRetry('a', 'b', { deadline: Date.now() + 5000, rename: flaky, platform: 'linux', sleep: () => {} }), /busy/);
  assert.equal(calls, 1, 'only Windows retries: elsewhere EPERM is a real refusal');
});

test('Windows: with no XDG_RUNTIME_DIR the marker lands in os.tmpdir(), where the launcher looks', () => {
  const env = { BRIDGE_CHANNEL_NAME: 'w', BRIDGE_CHANNEL_TRANSPORT: 'http', BRIDGE_CHANNEL_PORT: '8790' };
  assert.equal(failureMarkerPath(env), path.join(os.tmpdir(), 'agent-webhook-bridge-channel-w.http-8790.FAILED'));
  assert.equal(failureMarkerPath({ BRIDGE_CHANNEL_SOCKET: '/s/c.sock' }), '/s/c.sock.FAILED');
});

test('Windows: the shim is a .cmd that resolves current.json at run time', () => {
  const shim = shimFor('C:\\Users\\a\\AppData\\Local\\agent-webhook-bridge\\client\\x', 'check-channel-snapshot.py', 'win32');
  assert.equal(shim.name, 'check-channel-snapshot.py.cmd');
  assert.match(shim.body, /current\.json/);
  assert.match(shim.body, /versions\\%AWB_RELEASE%\\seat-tools\\bin\\check-channel-snapshot\.py/);
  const posix = shimFor("/home/o'brien/root", 'tool', 'linux');
  assert.match(posix.body, /root='\/home\/o'\\''brien\/root'/, 'a quote in the root path is shell-escaped');
});

test('Windows: a deep node_modules path installs (long paths)', async (t) => {
  // As deep as ustar holds (a 155-byte prefix and a 100-byte name) — past Windows' 260-character
  // MAX_PATH once it sits under the root.
  const deep = `client/node_modules/${Array.from({ length: 8 }, (_, i) => `package-number-${i}`).join('/')}/${'n'.repeat(90)}.js`;
  const files = [...clientFiles('2.0.0'), { path: deep, data: Buffer.from('module.exports = 1;\n') }];
  const root = await seatWith(t, '1.0.0');
  assert.ok(path.join(root, 'versions', '2.0.0', deep).length > 260, 'the installed path is past MAX_PATH');
  const bridge = await fixtureBridge(t, { published: buildPack({ release: '2.0.0', files }) });
  const run = await launch(root, seatEnv(t, bridge));

  assert.equal(run.started.release, '2.0.0', run.stderr);
  assert.ok(fs.existsSync(path.join(root, 'versions', '2.0.0', ...deep.split('/'))));
});
