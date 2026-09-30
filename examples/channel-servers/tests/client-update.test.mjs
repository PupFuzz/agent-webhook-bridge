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
import { SOURCE_DIR, sha256, buildPack, clientFiles, goodPack, fixtureBridge, stubServer } from './client-update-fixture.mjs';
import {
  compareReleases,
  decideLaunch,
  bootTimeMs,
  failureMarkerPath,
  composeState,
  classifyRelease,
  resolveInstalled,
  STRICT_RELEASE,
  REQUIRED_CLIENT_FILES,
} from '../entry.mjs';
import {
  MAX_REPORT_ENTRIES,
  MAX_REPORT_BYTES,
  MAX_LINE_BYTES,
  InstallLog,
  takeLock,
  reportBatches,
  satisfiesEngines,
  checkPackPath,
  clientDoorUrl,
  renameWithRetry,
  shimFor,
  clipBytes,
  Refusal,
  runLaunchUpdate,
  installFromFiles,
} from '../client-update.mjs';
import { launchIdentity, clientUpdateInstruction, httpRoundTrip } from '../channel-lib.mjs';
import http from 'node:http';

const UPDATER = path.join(SOURCE_DIR, 'client-update.mjs');
const VECTORS = new URL('./fixtures/version-comparator-vectors.json', import.meta.url);

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
  assert.equal(fs.readFileSync(path.join(root, 'versions', '1.0.0', '.verified'), 'utf8'), `${built.manifest.pack.sha256}\n${built.manifest.files_json_sha256}\n`);
  assert.deepEqual(fs.readFileSync(path.join(root, 'versions', '1.0.0', 'FILES.json')), built.filesJsonBytes, 'FILES.json is kept with the release');
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
  const launchJson = { launch_id: 'L-existing-1', ppid: process.pid, pid: 1, boot_ms: bootTimeMs(), started_at: 'x' };
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
  fs.writeFileSync(path.join(root, 'launch.json'), JSON.stringify({ launch_id: 'L-old', ppid: Number(dead.stdout), pid: 1, boot_ms: bootTimeMs(), started_at: 'x' }));

  const run = await launch(root, seatEnv(t, bridge), { reconnect: true });

  assert.notEqual(run.started.launch, 'L-old');
  assert.equal(run.started.release, '2.0.0');
});

test('decideLaunch: only a live, matching parent in this boot is a reconnect', () => {
  const boot = 1_700_000_000_000;
  const rec = { launch_id: 'L1', ppid: 4242, boot_ms: boot };
  const same = { ppid: 4242, alive: () => true, bootMs: boot + 800 };
  assert.deepEqual(decideLaunch(rec, same), { newLaunch: false, launchId: 'L1' }, 'clock jitter inside the tolerance is one boot');
  assert.equal(decideLaunch(rec, { ...same, alive: () => false }).newLaunch, true);
  assert.equal(decideLaunch(rec, { ...same, ppid: 99 }).newLaunch, true);
  assert.equal(decideLaunch(rec, { ...same, bootMs: boot + 3_600_000 }).newLaunch, true, 'a reboot that reuses the pid is a new launch');
  assert.equal(decideLaunch({ launch_id: 'L1', ppid: 4242 }, same).newLaunch, true, 'a record with no boot time is not believed');
  assert.equal(decideLaunch({ launch_id: 'L1', ppid: 1, boot_ms: boot }, { ...same, ppid: 1 }).newLaunch, true, 'init is never a session');
  assert.equal(decideLaunch({ launch_id: 'bad id!', ppid: 4242, boot_ms: boot }, same).newLaunch, true);
  assert.equal(decideLaunch(null).newLaunch, true);
  assert.match(decideLaunch(null).launchId, /^[0-9a-f-]{36}$/);
  // The real probes: this process is alive (process.kill(pid, 0) is portable), and this boot is this boot.
  assert.equal(decideLaunch({ launch_id: 'L2', ppid: process.pid, boot_ms: bootTimeMs() }, { ppid: process.pid }).newLaunch, false);
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
    // The dead launch's lock names its own deadline; move it into the past rather than wait.
    if (fs.existsSync(path.join(root, '.lock'))) {
      const held = JSON.parse(fs.readFileSync(path.join(root, '.lock'), 'utf8'));
      assert.ok(Number.isFinite(held.deadline_ms), 'the lock records its holder\'s deadline');
      fs.writeFileSync(path.join(root, '.lock'), JSON.stringify({ ...held, deadline_ms: Date.now() - 60000 }));
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
  // Its whole tree verifies, so the fault is not established as local: a published defect, or a
  // Node runtime this seat's node_engines no longer describes (design review rule 6).
  assert.match(fs.readFileSync(failureMarkerPath(env), 'utf8'), /release 1\.0\.0 verifies but failed to start \(.+\): a defect in the published release, or this seat's Node runtime no longer matches its node_engines — the bridge must publish a fixed release/);
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
      'Tell your operator; the install log is /r/install-log.jsonl. The update is tried again at the next launch; ' +
      'the reason at the start of this line names the cause.',
  );
  // An updater that cannot run cannot fetch its own fix: it needs a fixed release, bootstrapped.
  assert.match(
    clientUpdateInstruction({ ...base, state: 'update_failed', error: 'the installed updater failed: x', updater_broken: true }, { launchId: 'L', root }),
    /cannot fetch a fix: once the bridge publishes a fixed release, re-bootstrap this seat's client from it\.$/,
  );
  assert.match(
    clientUpdateInstruction({ ...base, state: 'update_failed', error: 'the install log /r/install-log.jsonl cannot be read (EACCES)', log_unreadable: true }, { launchId: 'L', root }),
    /the install log itself cannot be read — fix that file \(its owner and permissions\) and the next launch retries\.$/,
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

// ---------------------------------------------------------------------------------------------
// r1 review: records that cannot be written, power-cut damage, the lock's own deadline, the log

for (const record of ['state.json', 'launch.json']) {
  test(`an unwritable ${record} is said on stderr and the verified release still starts`, async (t) => {
    const root = await seatWith(t, '1.0.0');
    const bridge = await fixtureBridge(t, { published: goodPack('1.0.0') });
    fs.rmSync(path.join(root, record), { force: true });
    fs.mkdirSync(path.join(root, record, 'blocker'), { recursive: true });
    const env = seatEnv(t, bridge);
    const run = await launch(root, env, { reconnect: true });

    assert.equal(run.code, 0, run.stderr);
    assert.equal(run.started.release, '1.0.0', 'a record that cannot be written never keeps a release from starting');
    assert.match(run.stderr, new RegExp(`could not write .*${record.replace('.', '\\.')}.*starting the channel server anyway`));
    assert.ok(!fs.existsSync(failureMarkerPath(env)), 'no .FAILED marker: the channel did start');
  });
}

/** Truncate one required file of an installed release to zero bytes — what a power cut can leave. */
function damage(root, release, file = 'agent-webhook-bridge-channel.mjs') {
  fs.writeFileSync(path.join(root, 'versions', release, 'client', file), '');
}

test('power-cut damage: a zero-length required file in the current release starts the previous one, loudly, and current.json is repointed', async (t) => {
  const root = await seatWith(t, '1.0.0');
  const bridge = await fixtureBridge(t, { published: goodPack('2.0.0') });
  await launch(root, seatEnv(t, bridge));
  assert.equal(read(root, 'current.json').bridge_release, '2.0.0');
  damage(root, '2.0.0');

  const offline = await launch(root, seatEnv(t, null));
  assert.equal(offline.started.release, '1.0.0', 'the kept previous release starts');
  assert.match(offline.stderr, /release 2\.0\.0 is not intact \(client\/agent-webhook-bridge-channel\.mjs does not match its FILES\.json line\)/);
  const state = read(root, 'state.json');
  assert.match(state.recovered, /release 2\.0\.0 is not intact .*release 1\.0\.0 started instead/);
  assert.match(clientUpdateInstruction(state, { launchId: state.launch_id, root }), /^CLIENT RELEASE DAMAGED ON THIS SEAT: release 2\.0\.0 is not intact/);
  const recovered = logObjs(root).find((l) => l.action === 'pointer_recovered');
  assert.match(recovered.reason, /current\.json named release 2\.0\.0, which is not intact \(client\/agent-webhook-bridge-channel\.mjs does not match its FILES\.json line\); current\.json now names 1\.0\.0, the selected release/);
  assert.equal(read(root, 'current.json').bridge_release, '1.0.0');
  // recoverRoot (the pointer repair) deletes NOTHING (design review rule 3): the damaged tree is
  // still on disk, and is replaced only when commitInstall next tries to install into it.
  assert.ok(fs.existsSync(path.join(root, 'versions', '2.0.0')), 'the damaged release is left in place, not deleted, by the pointer repair');

  const online = await launch(root, seatEnv(t, bridge));
  assert.equal(online.started.release, '2.0.0', 'the damaged release is replaced by commitInstall and runs');
  assert.equal(read(root, 'state.json').state, 'current');
  assert.equal(read(root, 'state.json').recovered, undefined, 'nothing to say once the release runs again');
  assertChain(root);
});

test('power-cut damage: a release whose .verified record is empty is never started', async (t) => {
  const root = await seatWith(t, '1.0.0');
  const bridge = await fixtureBridge(t, { published: goodPack('2.0.0') });
  await launch(root, seatEnv(t, bridge));
  fs.writeFileSync(path.join(root, 'versions', '2.0.0', '.verified'), '');
  const run = await launch(root, seatEnv(t, bridge));
  assert.equal(run.started.release, '2.0.0', 'the unverified tree is replaced by the verified pack, not reused');
  assert.equal(read(root, 'state.json').state, 'current');
});

test('bootstrap onto a damaged copy of the same release re-extracts it instead of reusing it', async (t) => {
  const root = await seatWith(t, '1.0.0');
  damage(root, '1.0.0', 'client-update.mjs');
  const r = bootstrap(t, root, goodPack('1.0.0'));
  assert.equal(r.status, 0, r.stderr);
  assert.match(r.stderr, /versions\/1\.0\.0 is not intact/);
  assert.deepEqual(fs.readFileSync(path.join(root, 'versions', '1.0.0', 'client', 'client-update.mjs')), fs.readFileSync(UPDATER));
});

// r2 review MAJOR: a file outside REQUIRED_CLIENT_FILES could be damaged and never caught — not
// at step 1 (cheap, required-only), not by a bootstrap re-install (the reuse check was the same
// cheap check). `channel-lib.mjs` moved INTO REQUIRED_CLIENT_FILES to close the literal repro;
// `packWithExtraDep` proves the general fix (`classifyRelease`'s full scope) for a file that
// stays outside it.

/** A pack whose server also imports one file outside REQUIRED_CLIENT_FILES. */
function packWithExtraDep(release, opts = {}) {
  const serverData = Buffer.from(`import { ok } from './extra-dep.mjs';\n${stubServer(release)}`);
  const files = [...clientFiles(release, { ...opts, serverData }), { path: 'client/extra-dep.mjs', data: Buffer.from('export const ok = true;\n') }];
  return buildPack({ release, files, clientVersion: opts.clientVersion, nodeEngines: opts.nodeEngines });
}

test('channel-lib.mjs is a required file: damage is caught at step 1, not silently accepted, and a bootstrap repairs it (r2 MAJOR repro)', async (t) => {
  const root = await seatWith(t, '1.0.0');
  damage(root, '1.0.0', 'channel-lib.mjs');

  // Before the fix this reads as intact (the per-launch check never looked at channel-lib.mjs) and the
  // import throws later with no diagnosis; after the fix step 1 names it and refuses to start it.
  const env = seatEnv(t, null);
  const run = await launch(root, env);
  assert.equal(run.code, 2);
  assert.match(run.stderr, /release 1\.0\.0 is not intact \(client\/channel-lib\.mjs does not match its FILES\.json line\)/);
  assert.match(fs.readFileSync(failureMarkerPath(env), 'utf8'), /no verified client release is installed/);
  assert.equal(read(root, 'current.json').bridge_release, '1.0.0', 'the pointer is untouched: no other release exists');

  // Before the fix, re-installing the SAME pack reused the tree unchanged (commitInstall's reuse
  // check was REQUIRED_CLIENT_FILES-only too): rc 0, channel-lib.mjs still 0 bytes.
  const r = bootstrap(t, root, goodPack('1.0.0'));
  assert.equal(r.status, 0, r.stderr);
  assert.match(r.stderr, /versions\/1\.0\.0 is not intact/);
  assert.deepEqual(fs.readFileSync(path.join(root, 'versions', '1.0.0', 'client', 'channel-lib.mjs')), fs.readFileSync(path.join(SOURCE_DIR, 'channel-lib.mjs')));

  const online = await launch(root, seatEnv(t, null));
  assert.equal(online.code, 0, online.stderr);
  assert.equal(online.started.release, '1.0.0');
});

test('a damaged file outside REQUIRED_CLIENT_FILES: invisible to step 1, but a bootstrap now repairs it too (r2 MAJOR)', async (t) => {
  const root = await seatWith(t, '1.0.0', { pack: packWithExtraDep('1.0.0') });
  damage(root, '1.0.0', 'extra-dep.mjs');

  // Still outside REQUIRED_CLIENT_FILES on purpose: step 1's cheap check does not catch this; the
  // import failure is fatal for this launch, and its message names the fault and the repair.
  const run = await launch(root, seatEnv(t, null));
  assert.equal(run.code, 2, run.stderr);
  assert.doesNotMatch(run.stderr, /release 1\.0\.0 is not intact \(/, 'the cheap per-launch check does not cover this file');
  assert.match(run.stderr, /release 1\.0\.0 is not intact on this seat \(client\/extra-dep\.mjs does not match its FILES\.json line\): re-bootstrap from the bridge's pack — a bootstrap re-verifies the whole tree and replaces what is not intact/);

  // The reuse check on a bootstrap re-install is now the FULL FILES.json verification.
  const r = bootstrap(t, root, packWithExtraDep('1.0.0'));
  assert.equal(r.status, 0, r.stderr);
  assert.match(r.stderr, /versions\/1\.0\.0 is not intact/);
  assert.deepEqual(fs.readFileSync(path.join(root, 'versions', '1.0.0', 'client', 'extra-dep.mjs')), Buffer.from('export const ok = true;\n'));

  const online = await launch(root, seatEnv(t, null));
  assert.equal(online.code, 0, online.stderr);
  assert.equal(online.started.release, '1.0.0');
});

test('an import failure is not started, even with an intact previous release kept — no other release is tried (DL-434 bound 4)', async (t) => {
  const root = await seatWith(t, '1.0.0');
  const bridge = await fixtureBridge(t, { published: packWithExtraDep('2.0.0') });
  await launch(root, seatEnv(t, bridge));
  assert.equal(read(root, 'current.json').bridge_release, '2.0.0');
  damage(root, '2.0.0', 'extra-dep.mjs');

  const env = seatEnv(t, null);
  const run = await launch(root, env);
  assert.equal(run.code, 2, run.stderr);
  assert.equal(run.started, null, 'the kept 1.0.0 is not started in its place');
  assert.equal(read(root, 'state.json').state, 'not_started');
  assert.match(fs.readFileSync(failureMarkerPath(env), 'utf8'), /release 2\.0\.0 is not intact on this seat \(client\/extra-dep\.mjs does not match its FILES\.json line\): re-bootstrap/);
  assert.ok(fs.existsSync(path.join(root, 'versions', '2.0.0')), 'entry.mjs never deletes a release');
});

test('an import failure whose tree cannot be read says so, naming the file and the fault', async (t) => {
  const root = await seatWith(t, '1.0.0', { pack: packWithExtraDep('1.0.0') });
  const file = path.join(root, 'versions', '1.0.0', 'client', 'extra-dep.mjs');
  fs.rmSync(file);
  fs.mkdirSync(file);

  const env = seatEnv(t, null);
  const run = await launch(root, env);
  assert.equal(run.code, 2, run.stderr);
  const marker = fs.readFileSync(failureMarkerPath(env), 'utf8');
  assert.match(marker, /release 1\.0\.0 failed to start \(.+\), and whether it is intact on this seat was not established: client\/extra-dep\.mjs could not be read \(EISDIR\) — fix client\/extra-dep\.mjs \(its owner, permissions or disk\) and the next launch retries/);
  assert.doesNotMatch(marker, /defect in the published release|re-bootstrap/);
});

test('a damaged file outside REQUIRED_CLIENT_FILES never removes an existing seat-tool shim (design review rule 6)', async (t) => {
  const root = await seatWith(t, '1.0.0', { pack: packWithExtraDep('1.0.0') });
  const shimPath = path.join(root, 'bin', process.platform === 'win32' ? 'check-channel-snapshot.py.cmd' : 'check-channel-snapshot.py');
  assert.ok(fs.existsSync(shimPath), 'the bootstrap installed the shim');
  const shimBefore = fs.readFileSync(shimPath, 'utf8');

  // Still importable JS (the server keeps running), but its bytes no longer match FILES.json.
  fs.writeFileSync(path.join(root, 'versions', '1.0.0', 'client', 'extra-dep.mjs'), 'export const ok = true; // tampered\n');
  const bridge = await fixtureBridge(t, { published: packWithExtraDep('1.0.0') });
  const run = await launch(root, seatEnv(t, bridge));

  assert.equal(run.code, 0, run.stderr);
  assert.equal(run.started.release, '1.0.0');
  // settleRoot classifies at the required scope, so this file is not read there at all; the shim
  // list is FILES.json's, and the shim is left as it was.
  assert.equal(fs.readFileSync(shimPath, 'utf8'), shimBefore, 'the shim is untouched, not removed');
});

test('runLaunchUpdate re-resolves the installed release under the lock, not the caller\'s pre-lock argument (review r2 minor 1)', async (t) => {
  const root = await seatWith(t, '1.0.0');
  const r = bootstrap(t, root, goodPack('2.0.0'));
  assert.equal(r.status, 0, r.stderr);
  assert.equal(read(root, 'current.json').bridge_release, '2.0.0');

  const bridge = await fixtureBridge(t, { published: goodPack('2.0.0') });
  // A deliberately STALE argument, as if entry.mjs's step 1 (before the lock) had resolved to
  // 1.0.0 — the true on-disk state (2.0.0, just bootstrapped above) is what must win. Acting on
  // the stale value would treat this as "upgrade 1.0.0 -> 2.0.0" and log a redundant install of a
  // release already current, with the wrong from_bridge_release; acting on the fresh resolve logs
  // nothing new at all, because there is nothing to do.
  const before = logObjs(root).length;
  const stale = { release: '1.0.0' };
  const result = await runLaunchUpdate({ root, budgetMs: 10000, signal: new AbortController().signal, launchId: 'test-launch-stale', installed: stale, env: seatEnv(t, bridge) });

  assert.equal(result.installed, '2.0.0', 'the fresh on-disk resolve wins, not the stale pre-lock argument');
  assert.equal(result.state, 'current');
  assert.equal(logObjs(root).length, before, 'nothing needed doing: no redundant install/bootstrap line from acting on the stale release');
});

// design review (r2→r4 non-convergence re-derivation): classifyRelease is the one judge of a
// release's files, and a read fault (EACCES, EIO, EISDIR — anything that is not ENOENT or a hash
// mismatch) is reported as `bad` with `why: read-fault(<code>)`, the SAME status as a confirmed
// hash mismatch — the earlier established/unconfirmed split is gone (design review: it protected
// nothing reachable and blocked the one repair that mattered).
test('classifyRelease: a non-ENOENT read fault is `bad`, worded as a read fault, not a hash mismatch', (t) => {
  const root = newRoot(t);
  const release = '1.0.0';
  const sorted = [...clientFiles(release)].sort((a, b) => (a.path < b.path ? -1 : 1));
  const listing = sorted.map((f) => ({ path: f.path, mode: (f.mode ?? 0o644).toString(8).padStart(4, '0'), sha256: sha256(f.data), size: f.data.length }));
  const filesJsonBytes = Buffer.from(`${JSON.stringify(listing, null, 2)}\n`);
  const dir = path.join(root, 'versions', release);
  for (const f of sorted) {
    const dest = path.join(dir, f.path);
    fs.mkdirSync(path.dirname(dest), { recursive: true });
    fs.writeFileSync(dest, f.data);
  }
  fs.writeFileSync(path.join(dir, 'FILES.json'), filesJsonBytes);
  fs.writeFileSync(path.join(dir, '.verified'), `${'a'.repeat(64)}\n${sha256(filesJsonBytes)}\n`);
  // A directory in place of one required file: reading it throws EISDIR, not ENOENT.
  fs.rmSync(path.join(dir, 'client', 'agent-webhook-bridge-channel.mjs'));
  fs.mkdirSync(path.join(dir, 'client', 'agent-webhook-bridge-channel.mjs'));

  const c = classifyRelease(root, release, 'required');
  assert.equal(c.status, 'bad');
  assert.equal(c.file, 'client/agent-webhook-bridge-channel.mjs');
  assert.equal(c.why, 'read-fault(EISDIR)');
  assert.match(c.message, /could not be read \(EISDIR\)/);

  // resolveInstalled skips it for this launch (it is not `ok`, so it is not selected) exactly as
  // it would a confirmed hash mismatch — there is no third outcome any more.
  fs.writeFileSync(path.join(root, 'current.json'), JSON.stringify({ bridge_release: release }));
  const resolved = resolveInstalled(root);
  assert.equal(resolved.release, null);
  assert.equal(resolved.bad.length, 1);
  assert.equal(resolved.bad[0].release, release);
  assert.equal(resolved.bad[0].why, 'read-fault(EISDIR)');
});

test('composeState marks updater_broken only when the installed updater itself threw', () => {
  const before = { release: '1.0.0', damaged: [] };
  const after = { release: '1.0.0' };
  const broken = composeState({ launchId: 'L', outcome: { error: new Error('SyntaxError: x') }, before, after, budget: 1 });
  assert.equal(broken.state, 'update_failed');
  assert.equal(broken.updater_broken, true);
  const refused = composeState({ launchId: 'L', outcome: { result: { state: 'update_failed', error: 'refused: x' } }, before, after, budget: 1 });
  assert.equal(refused.updater_broken, undefined);
  const expired = composeState({ launchId: 'L', outcome: { expired: true }, before, after, budget: 1 });
  assert.equal(expired.updater_broken, undefined);
});

test('an unreadable install log names itself, and is not reported as a broken updater', async (t) => {
  const root = await seatWith(t, '1.0.0');
  const log = path.join(root, 'install-log.jsonl');
  fs.rmSync(log, { force: true });
  fs.mkdirSync(log);
  const bridge = await fixtureBridge(t, { published: goodPack('1.0.0') });

  const installed = resolveInstalled(root);
  const result = await runLaunchUpdate({ root, budgetMs: 10000, signal: new AbortController().signal, launchId: 'test-launch-log', installed, env: seatEnv(t, bridge) });
  assert.equal(result.state, 'update_failed');
  assert.equal(result.log_unreadable, true);
  assert.match(result.error, /the install log .*install-log\.jsonl cannot be read \(EISDIR\)/);
  const state = composeState({ launchId: 'L', outcome: { result }, before: installed, after: installed, budget: 1 });
  assert.equal(state.updater_broken, undefined);
  assert.match(clientUpdateInstruction(state, { launchId: 'L', root }), /the install log itself cannot be read/);
  assert.ok(!fs.existsSync(path.join(root, '.lock')), 'the lock is released');
});

test('an install onto a release whose tree cannot be read REPLACES it (design review rule 4: a read fault is `bad`, and `bad` is always replaced)', async (t) => {
  const root = await seatWith(t, '1.0.0');
  const file = path.join(root, 'versions', '1.0.0', 'seat-tools', 'bin', 'check-channel-snapshot.py');
  fs.rmSync(file);
  fs.mkdirSync(file);

  const r = bootstrap(t, root, goodPack('1.0.0'));
  assert.equal(r.status, 0, r.stderr);
  assert.match(r.stderr, /versions\/1\.0\.0 is not intact \(seat-tools\/bin\/check-channel-snapshot\.py could not be read \(EISDIR\)\); replacing it with the verified pack/);
  assert.ok(fs.statSync(file).isFile(), 'the release was replaced: the directory is gone');
});

test('a release current.json names that cannot be confirmed intact is repointed away from immediately (design review rule 3: recoverRoot always repoints, never keeps a pointer on an unconfirmable release)', async (t) => {
  const root = await seatWith(t, '1.0.0');
  const r = bootstrap(t, root, goodPack('2.0.0'));
  assert.equal(r.status, 0, r.stderr);
  const file = path.join(root, 'versions', '2.0.0', 'client', 'package.json');
  fs.rmSync(file);
  fs.mkdirSync(file);

  const run = await launch(root, seatEnv(t, null));
  assert.equal(run.code, 0, run.stderr);
  assert.equal(run.started.release, '1.0.0');
  assert.match(run.stderr, /release 2\.0\.0 is not intact \(client\/package\.json could not be read \(EISDIR\)\); it is passed over/);
  assert.equal(read(root, 'current.json').bridge_release, '1.0.0', 'the pointer is repointed away from it, same as any other non-ok release');
  const recovered = logObjs(root).find((l) => l.action === 'pointer_recovered');
  assert.match(recovered.reason, /current\.json named release 2\.0\.0, which is not intact \(client\/package\.json could not be read \(EISDIR\)\); current\.json now names 1\.0\.0, the selected release/);
  // Deletes nothing — the read-fault release is still on disk, untouched, for a later install to judge.
  assert.ok(fs.existsSync(path.join(root, 'versions', '2.0.0')));

  // current.json now names 1.0.0, so a launch with no bridge to consult keeps running it — step 1
  // trusts what current.json names without re-scanning once that release classifies `ok` (cheap by
  // design). The fault clearing on disk alone is not itself an event; the bridge re-offering 2.0.0
  // is what recovers it, through the ordinary update path.
  fs.rmdirSync(file);
  fs.copyFileSync(path.join(root, 'versions', '1.0.0', 'client', 'package.json'), file);
  const stillOne = await launch(root, seatEnv(t, null));
  assert.equal(stillOne.started.release, '1.0.0', 'current.json still names 1.0.0; nothing re-scanned it');

  const bridge2 = await fixtureBridge(t, { published: goodPack('2.0.0') });
  const again = await launch(root, seatEnv(t, bridge2));
  assert.equal(again.code, 0, again.stderr);
  assert.equal(again.started.release, '2.0.0');
  assert.equal(read(root, 'current.json').bridge_release, '2.0.0');
});

// design review, r4 M5: this refusal is reachable only when the OFFERED release is not the
// currently running one (refuseAgainstInstalled's own same-release check fires first otherwise,
// on both entry points) and `versions/<offer>` already holds a DIFFERENT pack than what is now
// published under that release number — a re-publish under an unchanged release, while the seat
// sits behind it. Removing commitInstall's own check left the suite green before this test existed.
test('commitInstall refuses a non-running release already on disk under a different pack (design review rule 4 — r4 M5, previously untested)', async (t) => {
  const root = await seatWith(t, '1.0.0');
  const b1 = bootstrap(t, root, goodPack('2.0.0'));
  assert.equal(b1.status, 0, b1.stderr);
  const originalBytes = fs.readFileSync(path.join(root, 'versions', '2.0.0', 'client', 'agent-webhook-bridge-channel.mjs'));
  // Roll the pointer back to 1.0.0 by hand (an administrative repoint): versions/2.0.0 is left
  // exactly as the bootstrap wrote it, and the bridge now republishes 2.0.0 under different bytes.
  fs.writeFileSync(path.join(root, 'current.json'), JSON.stringify({ ...read(root, 'current.json'), bridge_release: '1.0.0' }));

  const bridge = await fixtureBridge(t, { published: buildPack({ release: '2.0.0', files: clientFiles('2.0.0', { marker: '// other bytes\n' }) }) });
  const run = await launch(root, seatEnv(t, bridge));
  assert.equal(run.code, 0, run.stderr);
  assert.equal(run.started.release, '1.0.0', 'the refusal leaves 1.0.0 running');
  assert.equal(read(root, 'state.json').state, 'update_failed');
  assert.match(read(root, 'state.json').error, /refused: versions\/2\.0\.0 already holds pack [0-9a-f]{64}, not the verified pack [0-9a-f]{64}; it is left as it is/);
  assert.deepEqual(fs.readFileSync(path.join(root, 'versions', '2.0.0', 'client', 'agent-webhook-bridge-channel.mjs')), originalBytes, 'versions/2.0.0 is untouched');
});

/**
 * Instruments every `fs` read/stat/readdir call touching `<root>/versions` for the duration of
 * `run()`, recording `{fn, path, caller}` — `caller` is the calling function's name, read off a
 * real stack trace, skipping `readJsonFile` frames (entry.mjs's generic parse-a-file helper: its
 * read is attributed to ITS caller, e.g. `clientVersionOf`) (not a filename grep: the design review's own finding was that a
 * same-line grep for "versions" MISSES a read through a variable assigned on an earlier line —
 * `settleRoot`'s `const client = path.join(root, 'versions', release, 'client'); … fs.readFileSync
 * (path.join(client, 'entry.mjs'))` is exactly that shape). Restores `fs` before returning.
 */
async function instrumentedVersionsReads(root, run) {
  const targets = ['readFileSync', 'readdirSync', 'existsSync', 'statSync', 'lstatSync'];
  const originals = {};
  const calls = [];
  const versionsDir = path.join(root, 'versions');
  for (const name of targets) {
    originals[name] = fs[name];
    fs[name] = function patched(p, ...rest) {
      const asStr = typeof p === 'string' ? p : p instanceof Buffer ? p.toString() : '';
      if (asStr === versionsDir || asStr.startsWith(`${versionsDir}${path.sep}`)) {
        const callers = new Error().stack.split('\n').slice(2).map((frame) => {
          const m = /^at (?:Object\.)?(\S+?)(?:\s*\[as [^\]]+\])?\s*\(/.exec(frame.trim());
          return m ? m[1] : frame.trim();
        });
        calls.push({ fn: name, path: asStr, caller: callers.find((c) => c !== 'readJsonFile') ?? callers[0] });
      }
      return originals[name].apply(fs, [p, ...rest]);
    };
  }
  try {
    await run();
  } finally {
    for (const name of targets) {
      fs[name] = originals[name];
    }
  }
  return calls;
}

// design review guard (r2→r4 non-convergence re-derivation). What it pins: a direct fs read under
// versions/ from a function not on this allowlist, on a path the test exercises in-process; reads
// via fs.open/readSync, inside an allowlisted function, or in the child process (importFailure,
// main) are not pinned.
const VERSIONS_READ_ALLOWLIST = new Set([
  'classifyRelease', // the judge itself
  'resolveInstalled', // readdirSync versions/ to enumerate candidate release NAMES, not content
  'commitInstall', // existsSync(target) only, before deciding whether classifyRelease even applies
  'settleRoot', // readFileSync of an already-ok release's entry.mjs bytes, to copy them; readdirSync versions/ for prune's candidate names
  'clientVersionOf', // reads an already-selected release's package.json `version` field (through readJsonFile)
]);

test('guard: a direct fs read under versions/ on the paths exercised here comes only from an allowlisted function (design review, r2→r4 non-convergence re-derivation)', async (t) => {
  const root = newRoot(t);
  const built1 = goodPack('1.0.0');
  const files1 = writePack(t, built1);
  await installFromFiles({ packFile: files1.pack, manifestFile: files1.manifest, root });
  // Reuse (same pack, same release): exercises commitInstall's exists+ok+same-pack path.
  const calls = await instrumentedVersionsReads(root, async () => {
    await installFromFiles({ packFile: files1.pack, manifestFile: files1.manifest, root });
    // A damaged required file, re-installed: exists+bad+replace path.
    fs.writeFileSync(path.join(root, 'versions', '1.0.0', 'client', 'client-update.mjs'), '');
    await installFromFiles({ packFile: files1.pack, manifestFile: files1.manifest, root });
    // Upgrade twice, so settleRoot's prune has something to actually remove (three releases, keeps two).
    const files2 = writePack(t, goodPack('2.0.0'));
    await installFromFiles({ packFile: files2.pack, manifestFile: files2.manifest, root });
    const files3 = writePack(t, goodPack('3.0.0'));
    await installFromFiles({ packFile: files3.pack, manifestFile: files3.manifest, root });
    // resolveInstalled directly, including a current.json read fault (EISDIR).
    resolveInstalled(root);
    const cur = path.join(root, 'current.json');
    const saved = fs.readFileSync(cur);
    fs.rmSync(cur);
    fs.mkdirSync(cur);
    resolveInstalled(root);
    fs.rmdirSync(cur);
    fs.writeFileSync(cur, saved);
    // recoverRoot (writePointer + clientVersionOf) via a real launch-time update: point current.json
    // at a release that is not installed at all, and let the update repoint it back.
    fs.writeFileSync(cur, JSON.stringify({ ...JSON.parse(saved), bridge_release: '9.9.9' }));
    const bridge = await fixtureBridge(t, { published: goodPack('3.0.0') });
    await runLaunchUpdate({ root, budgetMs: 10000, signal: new AbortController().signal, launchId: 'guard-launch', installed: resolveInstalled(root), env: seatEnv(t, bridge) });
    // The approval_owed path (its own settleRoot call).
    const owed = await fixtureBridge(t, { published: goodPack('4.0.0'), offer: null, owed: '4.0.0' });
    const owedResult = await runLaunchUpdate({ root, budgetMs: 10000, signal: new AbortController().signal, launchId: 'guard-owed', installed: resolveInstalled(root), env: seatEnv(t, owed) });
    assert.equal(owedResult.state, 'approval_owed');
  });

  const strays = calls.filter((c) => !VERSIONS_READ_ALLOWLIST.has(c.caller));
  assert.deepEqual(strays, [], `undeclared reader(s) under versions/: ${JSON.stringify(strays, null, 2)}`);
  assert.ok(calls.length > 10, `sanity: the instrumented run should have observed several calls (saw ${calls.length})`);
  assert.ok(calls.some((c) => c.caller === 'clientVersionOf'), 'a read through readJsonFile is attributed to its caller');
});

test('no intact release at all: not started, loudly', async (t) => {
  const root = await seatWith(t, '1.0.0');
  damage(root, '1.0.0');
  const env = seatEnv(t, null);
  const run = await launch(root, env);
  assert.equal(run.code, 2);
  assert.match(run.stderr, /release 1\.0\.0 is not intact/);
  assert.match(fs.readFileSync(failureMarkerPath(env), 'utf8'), /no verified client release is installed/);
});

test('a bootstrap\'s lock is honoured to its own deadline, not taken over at twice a launch budget', async (t) => {
  const root = await seatWith(t, '1.0.0');
  const bridge = await fixtureBridge(t, { published: goodPack('2.0.0') });
  fs.writeFileSync(path.join(root, '.lock'), JSON.stringify({ token: 'boot-1', pid: 1, deadline_ms: Date.now() + 5 * 60000 }));
  const old = (Date.now() - 600000) / 1000;
  fs.utimesSync(path.join(root, '.lock'), old, old);
  const run = await launch(root, seatEnv(t, bridge, { AWB_CLIENT_UPDATE_BUDGET_MS: '1000' }));

  assert.equal(run.started.release, '1.0.0');
  assert.match(read(root, 'state.json').error, /^lock held: .*its holder's deadline/);
  assert.equal(JSON.parse(fs.readFileSync(path.join(root, '.lock'), 'utf8')).token, 'boot-1', 'the holder\'s lock is untouched');
});

test('a lock past its holder\'s own deadline is taken over', async (t) => {
  const root = await seatWith(t, '1.0.0');
  const bridge = await fixtureBridge(t, { published: goodPack('2.0.0') });
  fs.writeFileSync(path.join(root, '.lock'), JSON.stringify({ token: 'dead', pid: 1, deadline_ms: Date.now() - 60000 }));
  const run = await launch(root, seatEnv(t, bridge));
  assert.equal(run.started.release, '2.0.0');
});

test('takeLock writes its deadline, and gives way to a live one', (t) => {
  const root = newRoot(t);
  const first = takeLock(root, { deadline: Date.now() + 60000, staleAfterMs: 1 });
  assert.ok(JSON.parse(fs.readFileSync(first.lock, 'utf8')).deadline_ms > Date.now());
  assert.throws(() => takeLock(root, { deadline: Date.now() + 60000, staleAfterMs: 1 }), /lock held/);
});

test('a write that fails half-way is cut back, so the next line never lands on a fragment', (t) => {
  const root = newRoot(t);
  const log = InstallLog.open(root, () => {});
  log.append({ action: 'fail', result: 'failed', actor: 'provision', reason: 'first' });
  const size = fs.statSync(log.file).size;
  log.io.writeSync = (fd, bytes) => {
    fs.writeSync(fd, bytes.subarray(0, 12));
    throw new Error('ENOSPC: injected');
  };
  assert.throws(() => log.append({ action: 'fail', result: 'failed', actor: 'provision', reason: 'second' }), /injected/);
  assert.equal(fs.statSync(log.file).size, size, 'the partial write was cut back');
  log.io.writeSync = fs.writeSync;
  log.append({ action: 'fail', result: 'failed', actor: 'provision', reason: 'third' });
  const lines = fs.readFileSync(log.file, 'utf8').split('\n').filter(Boolean);
  assert.equal(lines.length, 2);
  assert.equal(JSON.parse(lines[1]).prev_sha256, sha256(Buffer.from(lines[0])));
  assert.equal(JSON.parse(lines[1]).seq, 2);
});

test('a line whose free text grows past MAX_LINE_BYTES as it is escaped is cut to fit', (t) => {
  const root = newRoot(t);
  const log = InstallLog.open(root, () => {});
  // Each control character escapes to six bytes: at the per-field caps the line would be ~4.9 KB.
  const fields = { action: 'fail', result: 'failed', actor: 'provision', reason: '\u0001'.repeat(500), source: '\u0001'.repeat(255), pack_sha256: 'a'.repeat(64), files_json_sha256: 'b'.repeat(64), manifest_sha256: 'c'.repeat(64) };
  const uncut = JSON.stringify({ ...fields, install_id: log.installId, seq: 1, time: new Date().toISOString(), prev_sha256: null });
  assert.ok(Buffer.byteLength(uncut) > MAX_LINE_BYTES, 'the input is over the cap before cutting');
  const line = log.append(fields);
  const text = fs.readFileSync(log.file, 'utf8').trim();
  assert.ok(Buffer.byteLength(text) <= MAX_LINE_BYTES, `line is ${Buffer.byteLength(text)} bytes`);
  assert.equal(JSON.parse(text).reason, line.reason);
});

test('a redirect from the bridge is refused, never followed with the bearer', async (t) => {
  let followed = 0;
  const elsewhere = http.createServer((req, res) => {
    followed += 1;
    res.end('{}');
  });
  await new Promise((resolve) => elsewhere.listen(0, '127.0.0.1', resolve));
  t.after(() => new Promise((resolve) => elsewhere.close(resolve)));
  const target = `http://127.0.0.1:${elsewhere.address().port}/agent-tools/client`;

  await assert.rejects(httpRoundTrip({ url: target.replace(elsewhere.address().port, '9'), token: 't', body: '{}' }));
  const root = await seatWith(t, '1.0.0');
  const bridge = await fixtureBridge(t, { published: goodPack('2.0.0'), redirectTo: target });
  const run = await launch(root, seatEnv(t, bridge));

  assert.equal(run.started.release, '1.0.0');
  // review r2 minor 5: `fetch`'s own message for a refused redirect is the generic "fetch
  // failed" — the actual reason is on `err.cause` and must be named too, or this is
  // indistinguishable from every other unreachable-bridge cause.
  assert.match(read(root, 'state.json').error, /^bridge unreachable \(.*fetch failed: unexpected redirect\)/);
  assert.equal(followed, 0, 'the redirect target never saw a request');
});

test('install accepts --actor provision (the design\'s bootstrap call) and refuses any other actor', async (t) => {
  const files = writePack(t, goodPack('1.0.0'));
  const root = newRoot(t);
  const ok = spawnSync(process.execPath, [UPDATER, 'install', '--pack', files.pack, '--manifest', files.manifest, '--root', root, '--actor', 'provision'], { encoding: 'utf8' });
  assert.equal(ok.status, 0, ok.stderr);
  assert.equal(logObjs(root)[0].actor, 'provision');
  const bad = spawnSync(process.execPath, [UPDATER, 'install', '--pack', files.pack, '--manifest', files.manifest, '--root', newRoot(t), '--actor', 'launch'], { encoding: 'utf8' });
  assert.equal(bad.status, 2);
});

test('approval_owed is logged once per owed content even with other lines in between', async (t) => {
  const root = await seatWith(t, '1.0.0');
  const bridge = await fixtureBridge(t, { published: goodPack('2.0.0'), offer: null, owed: '2.0.0' });
  await launch(root, seatEnv(t, bridge));
  await launch(root, seatEnv(t, null));
  await launch(root, seatEnv(t, bridge));

  assert.deepEqual(logObjs(root).map((l) => l.action), ['bootstrap', 'approval_owed', 'fail']);
});

// ---------------------------------------------------------------------------------------------
// r5 review

test('a healthy current launch reads no file under versions/ outside REQUIRED_CLIENT_FILES, .verified and FILES.json (r5 M1)', async (t) => {
  const root = await seatWith(t, '1.0.0');
  assert.equal(bootstrap(t, root, goodPack('2.0.0')).status, 0);
  const bridge = await fixtureBridge(t, { published: goodPack('2.0.0') });
  let result;
  const calls = await instrumentedVersionsReads(root, async () => {
    result = await runLaunchUpdate({ root, budgetMs: 10000, signal: new AbortController().signal, launchId: 'healthy', installed: resolveInstalled(root), env: seatEnv(t, bridge) });
  });

  assert.equal(result.state, 'current');
  const allowed = new Set([...REQUIRED_CLIENT_FILES.map((name) => `client/${name}`), '.verified', 'FILES.json']);
  const read = calls.filter((c) => c.fn === 'readFileSync').map((c) => path.relative(path.join(root, 'versions'), c.path).split(path.sep).slice(1).join('/'));
  assert.ok(read.includes('.verified'), 'sanity: the launch classified a release');
  assert.deepEqual(read.filter((rel) => !allowed.has(rel)), []);
});

test('a prune removal that fails is logged `skipped`, never `failed`, and the update is still current (r5 M2, design review rule 5)', async (t) => {
  const root = await seatWith(t, '1.0.0');
  assert.equal(bootstrap(t, root, goodPack('2.0.0')).status, 0);
  const bridge = await fixtureBridge(t, { published: goodPack('3.0.0') });
  const target = path.join(root, 'versions', '1.0.0');
  const rmSync = fs.rmSync;
  fs.rmSync = function patched(p, ...rest) {
    if (p === target) {
      throw Object.assign(new Error(`EACCES: permission denied, rm '${p}'`), { code: 'EACCES' });
    }
    return rmSync.apply(fs, [p, ...rest]);
  };
  let result;
  try {
    result = await runLaunchUpdate({ root, budgetMs: 10000, signal: new AbortController().signal, launchId: 'prune-fails', installed: resolveInstalled(root), env: seatEnv(t, bridge) });
  } finally {
    fs.rmSync = rmSync;
  }

  assert.equal(result.state, 'current');
  assert.equal(result.installed, '3.0.0');
  assert.ok(fs.existsSync(target), 'what could not be removed is left');
  const prune = logObjs(root).filter((l) => l.action === 'prune');
  assert.equal(prune.length, 1);
  assert.equal(prune[0].result, 'skipped');
  assert.match(prune[0].reason, /could not remove versions\/1\.0\.0 \(EACCES\)/);
  assert.deepEqual(logObjs(root).filter((l) => l.result === 'failed'), [], 'no line the bridge reads as a failed launch');
});

test('a versions/ entry whose name is not X.Y.Z is ignored by resolveInstalled, and the prune may remove it (r5 m6)', async (t) => {
  const root = await seatWith(t, '1.0.0');
  const stray = path.join(root, 'versions', 'not-a-release');
  fs.mkdirSync(stray);
  // No current.json: resolveInstalled lists versions/ instead of trusting a pointer.
  fs.rmSync(path.join(root, 'current.json'));

  const resolved = resolveInstalled(root);
  assert.equal(resolved.release, '1.0.0');
  assert.deepEqual(resolved.bad, [], 'not a release, so not a bad one');

  const bridge = await fixtureBridge(t, { published: goodPack('1.0.0') });
  const run = await launch(root, seatEnv(t, bridge));
  assert.equal(run.started.release, '1.0.0');
  assert.doesNotMatch(run.stderr, /not-a-release/);
  assert.ok(!fs.existsSync(stray), 'the retention prune removes it');
});

test('a current.json that cannot be repointed is a Failure naming current.json and the OS code (r5 m5, design review rule 3)', async (t) => {
  const root = await seatWith(t, '1.0.0');
  const cur = path.join(root, 'current.json');
  fs.rmSync(cur);
  fs.mkdirSync(path.join(cur, 'blocker'), { recursive: true });
  const bridge = await fixtureBridge(t, { published: goodPack('1.0.0') });
  const run = await launch(root, seatEnv(t, bridge));

  assert.equal(run.code, 0, run.stderr);
  assert.equal(run.started.release, '1.0.0');
  const state = read(root, 'state.json');
  assert.equal(state.state, 'update_failed');
  assert.match(state.error, /^current\.json could not be repointed to release 1\.0\.0 \([A-Z]+\)$/);
  assert.match(logObjs(root).at(-1).reason, /^current\.json could not be repointed to release 1\.0\.0 \([A-Z]+\)$/);
});

test('classifyRelease: a .verified that is a directory is `bad`, a read fault naming .verified (r5 m5)', async (t) => {
  const root = await seatWith(t, '1.0.0');
  const verified = path.join(root, 'versions', '1.0.0', '.verified');
  fs.rmSync(verified);
  fs.mkdirSync(verified);

  const c = classifyRelease(root, '1.0.0', 'required');
  assert.equal(c.status, 'bad');
  assert.equal(c.file, '.verified');
  assert.equal(c.why, 'read-fault(EISDIR)');
});

test('no release classifies ok and one has a read fault: not started, and the remedy also names the file to fix (r5 m1)', async (t) => {
  const root = await seatWith(t, '1.0.0');
  const file = path.join(root, 'versions', '1.0.0', 'client', 'package.json');
  fs.rmSync(file);
  fs.mkdirSync(file);
  const env = seatEnv(t, null);
  const run = await launch(root, env);

  assert.equal(run.code, 2);
  assert.match(fs.readFileSync(failureMarkerPath(env), 'utf8'), /bootstrap this seat's client from its bridge's published pack, or fix versions\/1\.0\.0\/client\/package\.json \(EISDIR\); THIS Claude Code session is deaf/);
});

// ---------------------------------------------------------------------------------------------
// Bootstrap from the door (card#10568 C2): `client-update.mjs bootstrap --root`, over the
// board-tools transport in the environment — what the provisioner's `--bootstrap-client` runs.

/**
 * `client-update.mjs bootstrap --root <root>` with the seat's env, as the provisioner spawns it.
 * Asynchronous: the fixture bridge answers from THIS process's event loop, which spawnSync blocks.
 */
function bootstrapFromDoor(root, env, extra = []) {
  return new Promise((resolve) => {
    const child = spawn(process.execPath, [UPDATER, 'bootstrap', '--root', root, ...extra], { env, stdio: ['ignore', 'pipe', 'pipe'] });
    let stdout = '';
    let stderr = '';
    child.stdout.on('data', (c) => (stdout += c));
    child.stderr.on('data', (c) => (stderr += c));
    child.on('close', (status, signal) => resolve({ status, signal, stdout, stderr }));
  });
}

test('bootstrap from the door installs the offered release, logs it with the door as source, and the seat then launches on it', async (t) => {
  const root = newRoot(t);
  const built = goodPack('1.0.0');
  const bridge = await fixtureBridge(t, { published: built });
  const env = seatEnv(t, bridge);

  const r = await bootstrapFromDoor(root, env);

  assert.equal(r.status, 0, r.stderr);
  assert.match(r.stdout, /client-update bootstrap: release 1\.0\.0 installed at /);
  assert.deepEqual(bridge.requests.map((q) => q.body.op), ['client_manifest', 'client_pack']);
  assert.equal(read(root, 'current.json').bridge_release, '1.0.0');
  const [line] = assertChain(root).filter((l) => l.action === 'bootstrap');
  assert.equal(line.actor, 'provision');
  assert.equal(line.result, 'ok');
  assert.equal(line.source, `bridge-http:${bridge.endpoint.replace(/call$/, 'client')}`);
  assert.equal(line.manifest_sha256, sha256(built.manifestBytes));
  assert.ok(!fs.existsSync(path.join(root, '.lock')), 'the lock is released');

  const run = await launch(root, env);
  assert.equal(run.started.release, '1.0.0');
  assert.equal(read(root, 'state.json').state, 'current');
});

test('bootstrap from the door with approval owed fetches nothing, installs nothing, and names the release and the approve command', async (t) => {
  const root = newRoot(t);
  const bridge = await fixtureBridge(t, { published: goodPack('1.0.0'), offer: null, owed: '1.0.0' });

  const r = await bootstrapFromDoor(root, seatEnv(t, bridge));

  assert.equal(r.status, 3, 'approval owed is "nothing offered right now", exit 3');
  assert.match(r.stderr, /refused: approval owed: .*release 1\.0\.0 is not approved.*bridge:client-approve <agent> 1\.0\.0/);
  assert.deepEqual(bridge.requests.map((q) => q.body.op), ['client_manifest'], 'the pack is never asked for');
  assert.equal(resolveInstalled(root).release, null);
  assert.ok(!fs.existsSync(path.join(root, 'current.json')));
  const lines = assertChain(root);
  assert.deepEqual(lines.map((l) => [l.action, l.result, l.to_bridge_release]), [['refuse', 'refused', '1.0.0']]);
});

test('bootstrap from a door onto an older installed release installs the offered one; a downgrade offer is refused and changes nothing', async (t) => {
  const root = await seatWith(t, '1.0.0');
  const bridge = await fixtureBridge(t, { published: goodPack('2.0.0') });

  const up = await bootstrapFromDoor(root, seatEnv(t, bridge));
  assert.equal(up.status, 0, up.stderr);
  const [line] = logObjs(root).filter((l) => l.action === 'bootstrap' && l.to_bridge_release === '2.0.0');
  assert.equal(line.from_bridge_release, '1.0.0');

  bridge.state.published = goodPack('1.5.0');
  const before = treeOf(root);
  const down = await bootstrapFromDoor(root, seatEnv(t, bridge));
  assert.equal(down.status, 1);
  assert.match(down.stderr, /refused: downgrade offered: release 1\.5\.0 is below the installed 2\.0\.0/);
  assert.deepEqual(treeOf(root), before);
  assert.ok(!bridge.requests.slice(2).some((q) => q.body.op === 'client_pack'), 'a downgrade is refused before the pack is fetched');
  assertChain(root);
});

// The exit is the contract an onboarding entry point branches on: 3 = the bridge offers nothing to
// install right now (it keeps the seat's legacy channel server), 1 = anything else (it fails loudly).
for (const [name, setup, expect, exit] of [
  ['a bridge that publishes nothing', (b) => { b.state.published = null; }, /failed: the bridge answered client_manifest with HTTP 503: this bridge publishes no client pack yet/, 3],
  ['a bridge whose store cannot serve its pack (any 5xx)', (b) => { b.state.fail = { client_manifest: { status: 500, error: 'store fault' } }; }, /failed: the bridge answered client_manifest with HTTP 500: store fault/, 3],
  ['a bridge older than the door, refusing the op body (a 4xx to the manifest)', (b) => { b.state.fail = { client_manifest: { status: 422, error: 'request must carry a non-empty tool' } }; }, /failed: the bridge answered client_manifest with HTTP 422: request must carry a non-empty tool/, 3],
  ['a publication that moved between manifest and pack (a 4xx to the pack)', (b) => { b.state.fail = { client_pack: { status: 404, error: 'this bridge serves the client pack for release 2.0.0 only' } }; }, /failed: the bridge answered client_pack with HTTP 404/, 1],
  ['a manifest answer that is not an {ok:false} envelope (a framework error page)', (b) => { b.state.fail = { client_manifest: { status: 500, raw: '{"message":"Server Error"}' } }; }, /failed: the bridge answered client_manifest with HTTP 500: no error text/, 1],
  ['a pack the bridge cannot serve after offering it', (b) => { b.state.fail = { client_pack: { status: 503, error: 'gone' } }; }, /failed: the bridge answered client_pack with HTTP 503: gone/, 3],
  ['a pack whose bytes are not the manifest\'s', (b) => { b.state.packBytes = Buffer.from('not the pack'); }, /refused: /, 1],
  ['a manifest that does not hash to the digest the door named', (b) => { b.state.manifestSha256 = 'f'.repeat(64); }, /refused: the manifest the bridge sent does not hash/, 1],
  ['a bridge that is not there', null, /failed: bridge unreachable/, 1],
  ['an offer that is not the release the bridge publishes', (b) => { b.state.offer = '9.9.9'; }, /refused: the bridge offered release 9\.9\.9 but publishes 1\.0\.0/, 1],
  ['an offer outside bare X.Y.Z', (b) => { b.state.offer = 'v1.0.0'; }, /refused: the bridge offered "v1\.0\.0", not a bare X\.Y\.Z release/, 1],
]) {
  test(`bootstrap from the door: ${name} — exit ${exit}, logged, nothing installed`, async (t) => {
    const root = newRoot(t);
    const bridge = setup === null ? null : await fixtureBridge(t, { published: goodPack('1.0.0') });
    if (setup !== null) {
      setup(bridge);
    }

    const r = await bootstrapFromDoor(root, seatEnv(t, bridge));

    assert.equal(r.status, exit, r.stdout);
    assert.match(r.stderr, expect);
    assert.equal(resolveInstalled(root).release, null);
    assert.ok(!fs.existsSync(path.join(root, 'current.json')));
    assert.ok(!fs.existsSync(path.join(root, 'entry.mjs')), 'no entry.mjs, so nothing can be pointed at it');
    assert.equal(assertChain(root).length, 1, 'the failure is one log line');
  });
}

test('bootstrap from the door with no board-tools transport in the environment says so', async (t) => {
  const root = newRoot(t);
  const env = seatEnv(t, null);
  delete env.BRIDGE_TOOLS_ENDPOINT;
  delete env.BRIDGE_TOOLS_TOKEN;

  const r = await bootstrapFromDoor(root, env);

  assert.equal(r.status, 1);
  assert.match(r.stderr, /failed: no board-tools transport is configured/);
});

test('bootstrap --agent names that agent in the approval command, and an agent outside the bridge grammar is a usage error', async (t) => {
  const root = newRoot(t);
  const bridge = await fixtureBridge(t, { published: goodPack('1.0.0'), offer: null, owed: '1.0.0' });

  const r = await bootstrapFromDoor(root, seatEnv(t, bridge), ['--agent', 'kb-impl']);
  assert.equal(r.status, 3);
  assert.match(r.stderr, /bridge:client-approve kb-impl 1\.0\.0 --reason=/);
  assert.doesNotMatch(r.stderr, /<agent>/);

  const bad = await bootstrapFromDoor(newRoot(t), seatEnv(t, bridge), ['--agent', 'KB impl']);
  assert.equal(bad.status, 2);
});

test('over ssh, an {ok:false} answer to the manifest is "nothing offered" at exit 2 (a 5xx) and exit 1 (an older bridge); a non-envelope is a failure', { skip: process.platform === 'win32' && 'a POSIX shell stands in for ssh' }, async (t) => {
  const bin = scratch(t, 'cu-fakessh-');
  fs.writeFileSync(path.join(bin, 'ssh'), '#!/bin/sh\ncat > /dev/null\nprintf "%s" "$FAKE_SSH_STDOUT"\nexit "$FAKE_SSH_EXIT"\n', { mode: 0o755 });
  const env = (exit, body) => {
    const e = seatEnv(t, null, { PATH: `${bin}${path.delimiter}${process.env.PATH}`, BRIDGE_TOOLS_SSH_TARGET: 'bridge@testhost', FAKE_SSH_EXIT: String(exit), FAKE_SSH_STDOUT: JSON.stringify(body) });
    delete e.BRIDGE_TOOLS_ENDPOINT;
    delete e.BRIDGE_TOOLS_TOKEN;
    return e;
  };

  const declined = await bootstrapFromDoor(newRoot(t), env(2, { ok: false, error: 'this bridge publishes no client pack yet' }));
  assert.equal(declined.status, 3, declined.stderr);
  assert.match(declined.stderr, /publishes no client pack yet/);

  // A bridge older than the door answers the op body as a malformed board-tools call: exit 1.
  const old = await bootstrapFromDoor(newRoot(t), env(1, { ok: false, error: 'request must carry a non-empty tool' }));
  assert.equal(old.status, 3, old.stderr);

  // Not an {ok:false} envelope — a PHP fatal's output, say — is a failure, whatever the exit.
  const fatal = await bootstrapFromDoor(newRoot(t), env(255, 'PHP Fatal error: …'));
  assert.equal(fatal.status, 1, fatal.stderr);
});

test('the bootstrap CLI refuses anything but --root and --agent', (t) => {
  const r = spawnSync(process.execPath, [UPDATER, 'bootstrap', '--root', newRoot(t), '--pack', 'x'], { encoding: 'utf8' });
  assert.equal(r.status, 2);
  assert.match(r.stderr, /client-update\.mjs bootstrap --root <dir>/);
});

// card#10568 comment 7236: a bootstrap replacing a copy that is not intact must not lose it when the
// verified copy cannot be moved in. The copy is renamed aside, not removed, until then.

test('a bootstrap whose rename into versions/ fails puts the copy it was replacing back, still startable', async (t) => {
  const root = await seatWith(t, '1.0.0', { pack: packWithExtraDep('1.0.0') });
  fs.writeFileSync(path.join(root, 'versions', '1.0.0', 'client', 'extra-dep.mjs'), 'export const ok = true; // damaged\n');
  assert.equal(classifyRelease(root, '1.0.0', 'full').status, 'bad', 'precondition: not intact in full');
  const pointer = fs.readFileSync(path.join(root, 'current.json'));
  const files = writePack(t, packWithExtraDep('1.0.0'));

  const r = spawnSync(process.execPath, [UPDATER, 'install', '--pack', files.pack, '--manifest', files.manifest, '--root', root], {
    env: { ...process.env, AWB_CLIENT_FAIL_AT: 'rename-in' },
    encoding: 'utf8',
  });

  assert.equal(r.status, 1, r.stdout);
  assert.match(r.stderr, /failed: rename-in refused by the test hook/);
  assert.equal(classifyRelease(root, '1.0.0', 'required').status, 'ok', 'the release the seat runs is back in versions/, startable');
  assert.equal(fs.readFileSync(path.join(root, 'versions', '1.0.0', 'client', 'extra-dep.mjs'), 'utf8'), 'export const ok = true; // damaged\n', 'it is the old copy, put back');
  assert.deepEqual(fs.readFileSync(path.join(root, 'current.json')), pointer);
  assert.equal(logObjs(root).at(-1).action, 'fail');

  const again = bootstrap(t, root, packWithExtraDep('1.0.0'));
  assert.equal(again.status, 0, again.stderr);
  assert.equal(classifyRelease(root, '1.0.0', 'full').status, 'ok', 'bootstrapping again repairs it');
});

test('a bootstrap killed between moving the old copy aside and the new one in is recovered by bootstrapping again once its lock expires', async (t) => {
  const root = await seatWith(t, '1.0.0', { pack: packWithExtraDep('1.0.0') });
  fs.writeFileSync(path.join(root, 'versions', '1.0.0', 'client', 'extra-dep.mjs'), 'export const ok = true; // damaged\n');
  const files = writePack(t, packWithExtraDep('1.0.0'));

  const killed = spawnSync(process.execPath, [UPDATER, 'install', '--pack', files.pack, '--manifest', files.manifest, '--root', root], {
    env: { ...process.env, AWB_CLIENT_CRASH_AT: 'after-aside' },
    encoding: 'utf8',
  });
  assert.ok(killed.signal === 'SIGKILL' || killed.status !== 0, `the bootstrap was killed (${killed.status}/${killed.signal})`);
  assert.ok(fs.existsSync(path.join(root, 'staging', '1.0.0.replaced', 'client', 'agent-webhook-bridge-channel.mjs')), 'the old copy is aside, not deleted');
  // The dead bootstrap's lock holds until its deadline plus the grace; move it into the past rather than wait.
  const held = JSON.parse(fs.readFileSync(path.join(root, '.lock'), 'utf8'));
  fs.writeFileSync(path.join(root, '.lock'), JSON.stringify({ ...held, deadline_ms: Date.now() - 60000 }));

  const again = bootstrap(t, root, packWithExtraDep('1.0.0'));
  assert.equal(again.status, 0, again.stderr);
  assert.equal(classifyRelease(root, '1.0.0', 'full').status, 'ok');
  assert.ok(!fs.existsSync(path.join(root, 'staging')), 'nothing is left aside');
  assertChain(root);
});

test('a bootstrap that runs past its budget stops before its next irreversible step, so it never writes under a lock a launch could take over (review r1 MAJOR)', async (t) => {
  const root = await seatWith(t, '1.0.0');
  const before = treeOf(root);
  const files = writePack(t, goodPack('2.0.0'));

  // The hook holds the verified, staged pack until one second past the deadline; the lock's own
  // deadline is that same budget, so any write after it is a write a launch could race.
  await assert.rejects(
    installFromFiles({ packFile: files.pack, manifestFile: files.manifest, root, budgetMs: 1500, env: { ...process.env, AWB_CLIENT_CRASH_AT: 'budget-exceeded' } }),
    /the update budget ran out before moving release 2\.0\.0 into versions\//,
  );

  assert.deepEqual(treeOf(root), before, 'versions/ and current.json are unchanged');
  assert.ok(!fs.existsSync(path.join(root, 'staging')), 'the staged pack is removed');
  assert.ok(!fs.existsSync(path.join(root, '.lock')), 'the lock is released');
  assert.equal(logObjs(root).at(-1).action, 'fail');
});
