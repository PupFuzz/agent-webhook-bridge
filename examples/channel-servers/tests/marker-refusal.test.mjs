// Regression tests for the Windows-reachable refusal paths that must leave a
// `.FAILED` marker (FR #2444 / roundtable #145 D1). Claude Code swallows an MCP
// server's startup stderr, so a seat that refuses to start is invisibly deaf to
// live-wake unless a marker file is written. These tests spawn the real channel
// server as a child process and assert the marker appears.
//
// Run: `node --test examples/channel-servers/tests/` (there is no CI job wiring
// yet — the "Channel-server supply chain" workflow only runs `npm ci` + `npm
// audit`; this file is the first unit-level harness for the server).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const SERVER = path.join(
  path.dirname(fileURLToPath(import.meta.url)),
  '..',
  'agent-webhook-bridge-channel.mjs',
);

// A clean env that reproduces a Windows-shaped seat: no unix socket path and no
// XDG_RUNTIME_DIR, with the OS temp dir redirected into a scratch dir so the
// marker (which resolves to os.tmpdir()/…FAILED in this state) lands where we
// can observe it. TMP/TEMP cover Windows; TMPDIR covers POSIX.
function windowsShapedEnv(tmp, extra = {}) {
  const env = { ...process.env };
  delete env.BRIDGE_CHANNEL_SOCKET;
  delete env.XDG_RUNTIME_DIR;
  env.TMPDIR = tmp;
  env.TMP = tmp;
  env.TEMP = tmp;
  return { ...env, ...extra };
}

// D1.1 — TRANSPORT=unix with BRIDGE_CHANNEL_SOCKET and XDG_RUNTIME_DIR both
// unset. The server must write a marker BEFORE exit(2). markerPath() resolves to
// os.tmpdir()/agent-webhook-bridge-channel-<name>.http-<port>.FAILED here.
test('unix transport with no resolvable socket path writes a FAILED marker before exiting', () => {
  const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'marker-d1-'));
  const name = 'test-agent-d1';
  const port = '8799';
  const res = spawnSync(process.execPath, [SERVER], {
    env: windowsShapedEnv(tmp, {
      BRIDGE_CHANNEL_TRANSPORT: 'unix',
      BRIDGE_CHANNEL_NAME: name,
      BRIDGE_CHANNEL_PORT: port,
    }),
    encoding: 'utf8',
    timeout: 15000,
  });

  assert.equal(res.status, 2, `expected exit 2, got ${res.status} (stderr: ${res.stderr})`);
  const marker = path.join(tmp, `agent-webhook-bridge-channel-${name}.http-${port}.FAILED`);
  assert.ok(fs.existsSync(marker), `expected marker at ${marker}; stderr was:\n${res.stderr}`);
  const body = fs.readFileSync(marker, 'utf8');
  assert.match(body, /both unset/, 'marker should describe the unset-socket-path misconfiguration');
});

// D1.2 support — confirm markerPath() resolves to a usable, writable path in the
// SET-SOCKET_PATH state that the non-EADDRINUSE fall-through relies on. The
// fall-through's own trigger is the Win32 EACCES that Node throws for a
// filesystem AF_UNIX bind; that is NOT reproducible on POSIX (every path
// collision yields EADDRINUSE, and every genuinely-broken path also makes the
// co-located <socket>.FAILED sibling unwritable). We drive the EADDRINUSE branch
// — which shares the identical markerPath()/writeFailureMarker() call the
// fall-through uses — to prove the unix marker mechanism writes to
// <socket>.FAILED. (Not a red-when-reverted test for the D1.2 line; see the PR
// notes on the Win32-only reproducibility of the fall-through trigger.)
test('unix transport bind collision writes the marker as <socket>.FAILED', () => {
  const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'marker-d2-'));
  const sock = path.join(tmp, 'chan.sock');
  fs.writeFileSync(sock, ''); // occupy the path -> EADDRINUSE on bind
  const res = spawnSync(process.execPath, [SERVER], {
    env: windowsShapedEnv(tmp, {
      BRIDGE_CHANNEL_TRANSPORT: 'unix',
      BRIDGE_CHANNEL_SOCKET: sock,
      BRIDGE_CHANNEL_NAME: 'test-agent-d2',
    }),
    encoding: 'utf8',
    timeout: 15000,
  });

  assert.equal(res.status, 2, `expected exit 2, got ${res.status} (stderr: ${res.stderr})`);
  const marker = `${sock}.FAILED`;
  assert.ok(fs.existsSync(marker), `expected marker at ${marker}; stderr was:\n${res.stderr}`);
});
