// What the 202 on the push path DECLARES about itself (card#9172).
//
// The defect these pin: the push handler answered a bare `forwarded` with HTTP 202, and
// the bridge's only success condition IS that 202 — so the bridge logged `delivered`, a
// claim about the SEAT, from a fact about the TRANSPORT. `mcp.notification()` resolves
// when the notification is written to stdio; nothing on this path reports whether the
// session received it. That was written down in a COMMENT in this repo, which is not a
// surface the consuming end can read.
//
// So the assertions are on the WIRE, not on the source: the header the bridge parses and
// the body an operator sees at the README's curl smoke test, both driven out of the REAL
// server over a REAL unix socket with a live stdio transport behind it (the MCP client
// from the shared harness), because a 202 is only reachable when that transport is up.
//
// ⚠ The body must claim NEITHER outcome for a notification the session has not shown up
// for: `dropped`/`lost` is not established, and neither is `deferred`. The two negative
// assertions are pinned beside the positive ones for that reason, never alone.
//
// Run: `node --test examples/channel-servers/tests/`.
import './live-state-guard.mjs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import { scratch, connectServer } from './mcp-harness.mjs';

/** POST a body to the server's unix socket, retrying while the bind is still in flight. */
async function push(socketPath, body) {
  for (let attempt = 0; ; attempt++) {
    try {
      return await new Promise((resolve, reject) => {
        const req = http.request(
          {
            socketPath,
            path: '/',
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(body) },
          },
          (res) => {
            const chunks = [];
            res.on('data', (c) => chunks.push(c));
            res.on('end', () =>
              resolve({
                status: res.statusCode,
                headers: res.headers,
                body: Buffer.concat(chunks).toString('utf8'),
              }),
            );
          },
        );
        req.on('error', reject);
        req.end(body);
      });
    } catch (err) {
      if (attempt >= 50 || !['ENOENT', 'ECONNREFUSED'].includes(err.code)) throw err;
      await new Promise((r) => setTimeout(r, 100));
    }
  }
}

test('the push 202 declares on the wire that this transport carries no delivery receipt', async (t) => {
  const runtime = scratch(t, 'receipt-rt-');
  const socketPath = path.join(runtime, 'chan.sock');
  // The stdio transport must be LIVE or the handler answers 503 instead: connecting an
  // MCP client is what makes the 202 branch the one under test.
  await connectServer(t, { BRIDGE_CHANNEL_SOCKET: socketPath }, { name: 'receipt-test', runtimePrefix: 'receipt-unused-' });

  const res = await push(socketPath, JSON.stringify({ intent: { kind: 'smoke_test', target_id: 'receipt' } }));

  assert.equal(res.status, 202, 'a live stdio transport still answers 202 — the write happened');
  assert.equal(
    res.headers['x-channel-delivery-receipt'],
    'none',
    'the machine-readable declaration the bridge parses',
  );
  assert.match(res.body, /accepted by transport \(unconfirmed\)/);
  assert.match(res.body, /no receipt that the session received it/);
  // The two claims this end is NOT entitled to make, pinned beside the ones it is.
  assert.doesNotMatch(res.body, /\bdelivered\b/, 'a 202 is not a delivery claim');
  assert.doesNotMatch(res.body, /\blost\b|\bdropped\b|\bdeferred\b/, 'dropped-vs-deferred is not established here');
  assert.ok(fs.existsSync(socketPath), 'the socket under test is the one the server bound');
});
