// The channel launcher's HTTP stale-listener guard (card#11328): the REAL bin/start-claude.sh,
// driven against real listeners on loopback with a fake `claude` first on PATH. A held port whose
// holder does not answer HTTP and is an agent-webhook-bridge channel server (a copied server, or a
// client root's entry.mjs) is a crashed session's orphan: it is reclaimed and the launch goes on.
// A holder that answers, or one that is not ours, is refused and left running.
//
// Run: `node --test examples/channel-servers/tests/`.
import './live-state-guard.mjs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { spawn, spawnSync } from 'node:child_process';
import { once } from 'node:events';
import { fileURLToPath } from 'node:url';
import { scratch } from './mcp-harness.mjs';

const LAUNCHER = path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'bin', 'start-claude.sh');

const portTool = ['ss', 'lsof'].some((tool) => spawnSync('sh', ['-c', `command -v ${tool}`]).status === 0);
const skip = process.platform === 'win32' ? 'the bash launcher; start-claude.ps1 is validated on Windows by its device agent' : !portTool && 'neither ss nor lsof on this host';

// A listener: `http` answers every request 405 (a live channel server), `mute` accepts and drops
// the connection with no HTTP answer (a wedged orphan).
const LISTENER = `
import http from 'node:http';
import net from 'node:net';
const server = process.argv[2] === 'http'
  ? http.createServer((q, r) => { r.writeHead(405); r.end(); })
  : net.createServer((s) => s.destroy());
server.listen(0, '127.0.0.1', () => process.stdout.write(server.address().port + '\\n'));
`;

async function listener(t, file, mode) {
  fs.mkdirSync(path.dirname(file), { recursive: true });
  fs.writeFileSync(file, LISTENER);
  const child = spawn(process.execPath, [file, mode], { stdio: ['ignore', 'pipe', 'inherit'] });
  t.after(() => {
    if (child.exitCode === null && child.signalCode === null) {
      child.kill('SIGKILL');
    }
  });
  const [chunk] = await once(child.stdout, 'data');
  return { child, port: Number(String(chunk).trim()) };
}

function runLauncher(t, port) {
  const dir = scratch(t, 'launcher-');
  const bin = path.join(dir, 'bin');
  fs.mkdirSync(bin);
  const record = path.join(dir, 'claude-args');
  fs.writeFileSync(path.join(bin, 'claude'), `#!/bin/sh\nprintf '%s\\n' "$@" > '${record}'\n`, { mode: 0o755 });
  const out = spawnSync('bash', [LAUNCHER], {
    encoding: 'utf8',
    timeout: 30000,
    env: {
      PATH: `${bin}:${process.env.PATH}`,
      HOME: dir,
      XDG_RUNTIME_DIR: dir,
      CLAUDE_SETTINGS_LOCAL: path.join(dir, 'absent.json'),
      BRIDGE_CHANNEL_NAME: `launcher-test-${process.pid}-${port}`,
      BRIDGE_CHANNEL_TRANSPORT: 'http',
      BRIDGE_CHANNEL_PORT: String(port),
    },
  });
  return { ...out, launched: fs.existsSync(record) ? fs.readFileSync(record, 'utf8').trim().split('\n') : null };
}

function alive(child) {
  return child.exitCode === null && child.signalCode === null;
}

async function exited(child, ms = 5000) {
  if (!alive(child)) return true;
  const timer = new Promise((resolve) => setTimeout(() => resolve(false), ms));
  return Promise.race([once(child, 'exit').then(() => true), timer]);
}

test('an unresponsive copied channel server holding the port is reclaimed and the launch goes on', { skip }, async (t) => {
  const dir = scratch(t, 'orphan-');
  const orphan = await listener(t, path.join(dir, 'agent-webhook-bridge-channel.mjs'), 'mute');
  const r = runLauncher(t, orphan.port);

  assert.equal(r.status, 0, r.stderr);
  assert.match(r.stderr, /stale orphan from a prior session\. Reclaiming\./);
  assert.ok(await exited(orphan.child), 'the orphan was killed');
  assert.deepEqual(r.launched?.slice(0, 2), ['--dangerously-load-development-channels', `server:launcher-test-${process.pid}-${orphan.port}`]);
});

test('an unresponsive client-root channel server (…/agent-webhook-bridge/client/<channel>/entry.mjs) is reclaimed too', { skip }, async (t) => {
  const dir = scratch(t, 'orphan-root-');
  const orphan = await listener(t, path.join(dir, 'agent-webhook-bridge', 'client', 'some-channel', 'entry.mjs'), 'mute');
  const r = runLauncher(t, orphan.port);

  assert.equal(r.status, 0, r.stderr);
  assert.ok(await exited(orphan.child), 'the orphan was killed');
  assert.ok(r.launched, 'claude was launched');
});

test('a holder that answers HTTP is refused and left running — it may be a live session', { skip }, async (t) => {
  const dir = scratch(t, 'live-');
  const live = await listener(t, path.join(dir, 'agent-webhook-bridge-channel.mjs'), 'http');
  const r = runLauncher(t, live.port);

  assert.equal(r.status, 1);
  assert.match(r.stderr, /The channel port is already held by a process on 127\.0\.0\.1:\d+ \(a running session, or a channel server left behind by one\) — refusing to start a second\./);
  assert.equal(r.launched, null, 'claude was not launched');
  assert.equal(await exited(live.child, 300), false, 'the live holder is still running');
});

test('an unresponsive holder that is NOT a channel server is refused and never killed', { skip }, async (t) => {
  const dir = scratch(t, 'foreign-');
  const foreign = await listener(t, path.join(dir, 'some-other-service.mjs'), 'mute');
  const r = runLauncher(t, foreign.port);

  assert.equal(r.status, 1);
  assert.match(r.stderr, /NOT an agent-webhook-bridge channel server; refusing to kill it/);
  assert.equal(r.launched, null, 'claude was not launched');
  assert.equal(await exited(foreign.child, 300), false, 'the foreign holder is still running');
});
