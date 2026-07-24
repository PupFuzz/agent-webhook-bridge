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

// Consolidation (roundtable #145, canon #5/#7): every startup config-validation
// refuse-and-exit routes through refuseDeaf(), so the two sites below — which
// previously exited WITHOUT a marker — now leave one. In the http-shaped state
// markerPath() resolves to os.tmpdir()/…http-<port>.FAILED. Both are
// red-when-reverted: drop the site's refuseDeaf/marker and the marker assertion
// fails.

// An invalid BRIDGE_CHANNEL_TRANSPORT value must refuse with a marker (was a
// marker-less console.error → exit(2) before consolidation).
test('invalid BRIDGE_CHANNEL_TRANSPORT writes a FAILED marker before exiting', () => {
  const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'marker-badtrans-'));
  const name = 'test-agent-badtrans';
  const port = '8797';
  const res = spawnSync(process.execPath, [SERVER], {
    env: windowsShapedEnv(tmp, {
      BRIDGE_CHANNEL_TRANSPORT: 'bogus',
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
  assert.match(body, /BRIDGE_CHANNEL_TRANSPORT must be/, 'marker should describe the invalid-transport misconfiguration');
});

// Both board-tools transports set at once must refuse with a marker (was a
// marker-less console.error → exit(2) before consolidation). Uses a valid http
// transport so the refuse is the tools-conflict check, not the transport check.
test('conflicting BRIDGE_TOOLS_SSH_TARGET + BRIDGE_TOOLS_ENDPOINT writes a FAILED marker before exiting', () => {
  const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'marker-toolsconflict-'));
  const name = 'test-agent-toolsconflict';
  const port = '8796';
  const res = spawnSync(process.execPath, [SERVER], {
    env: windowsShapedEnv(tmp, {
      BRIDGE_CHANNEL_TRANSPORT: 'http',
      BRIDGE_CHANNEL_NAME: name,
      BRIDGE_CHANNEL_PORT: port,
      BRIDGE_TOOLS_SSH_TARGET: 'user@host',
      BRIDGE_TOOLS_ENDPOINT: 'http://127.0.0.1:9/agent-tools/call',
    }),
    encoding: 'utf8',
    timeout: 15000,
  });

  assert.equal(res.status, 2, `expected exit 2, got ${res.status} (stderr: ${res.stderr})`);
  const marker = path.join(tmp, `agent-webhook-bridge-channel-${name}.http-${port}.FAILED`);
  assert.ok(fs.existsSync(marker), `expected marker at ${marker}; stderr was:\n${res.stderr}`);
  const body = fs.readFileSync(marker, 'utf8');
  assert.match(body, /both set/, 'marker should describe the dual-tools-transport misconfiguration');
});

// The http server.on('error') non-EADDRINUSE fall-through now writes a marker
// before the bare `throw err` (mirrors the unix fall-through, D1 path 2). Unlike
// the unix EACCES fall-through — which is Win32-only (POSIX never throws EACCES
// for a socket path collision; it yields EADDRINUSE) — the http fall-through IS
// POSIX-reproducible: a NON-root process binding a privileged port (80) gets
// EACCES, a non-EADDRINUSE error that lands in the fall-through. The bare throw
// escapes the async 'error' handler as an uncaughtException ⇒ exit 1 (not 2), so
// this asserts on the marker, not the exit code. Skipped under root (a root
// process binds :80 successfully, no error to drive). Red-when-reverted: drop the
// writeFailureMarker before `throw err` and the marker assertion fails.
test(
  'http transport non-EADDRINUSE bind error writes a FAILED marker before throwing',
  { skip: process.getuid && process.getuid() === 0 ? 'requires non-root (root binds :80 with no EACCES)' : false },
  () => {
    const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'marker-httpthrow-'));
    const name = 'test-agent-httpthrow';
    const port = '80'; // privileged → EACCES for a non-root bind
    const res = spawnSync(process.execPath, [SERVER], {
      env: windowsShapedEnv(tmp, {
        BRIDGE_CHANNEL_TRANSPORT: 'http',
        BRIDGE_CHANNEL_NAME: name,
        BRIDGE_CHANNEL_PORT: port,
      }),
      encoding: 'utf8',
      timeout: 15000,
    });

    const marker = path.join(tmp, `agent-webhook-bridge-channel-${name}.http-${port}.FAILED`);
    assert.ok(fs.existsSync(marker), `expected marker at ${marker}; stderr was:\n${res.stderr}`);
    const body = fs.readFileSync(marker, 'utf8');
    assert.match(body, /failed to bind http/, 'marker should describe the failed http bind');
    assert.match(body, /EACCES/, 'marker should capture the underlying err.code');
  },
);
