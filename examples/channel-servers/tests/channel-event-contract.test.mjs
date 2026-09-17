// The channel-event contract, fed the body the BRIDGE actually sends (DL-388, card#9479).
//
// The defect these pin: the instructions string promised every event a `target_id`
// attribute, `deriveMeta()` filled it from `intent.target_id`, and the bridge's
// `Intent::toArray()` carries `subject_id` — so on a real push the attribute was never set.
// Nothing went red because every envelope in this suite was HAND-BUILT with the field the
// server read. So the body here is not written in this file: it is
// `fixtures/bridge-channel-push-body.json`, which the bridge's own
// `tests/Feature/Handlers/ChannelEventContractTest.php` asserts is exactly what
// `ChannelPushHandler` sends for an `Intent::toArray()` payload.
//
// Run: `node --test examples/channel-servers/tests/`.
import './live-state-guard.mjs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import { deriveMeta } from '../channel-lib.mjs';
import { scratch, connectServer } from './mcp-harness.mjs';

const BODY = fs.readFileSync(new URL('./fixtures/bridge-channel-push-body.json', import.meta.url), 'utf8');
const INTENT = JSON.parse(BODY).intent;

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
            res.resume();
            res.on('end', () => resolve(res.statusCode));
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

test('the fixture is a bridge intent body with the fields this contract maps', () => {
  assert.equal(typeof INTENT.kind, 'string');
  assert.equal(typeof INTENT.subject_id, 'string');
  assert.notEqual(INTENT.subject_id, '', 'an empty subject_id would make the target_id assertions vacuous');
});

test('deriveMeta on the bridge body sets target_id from intent.subject_id', () => {
  assert.deepEqual(deriveMeta(BODY), { kind: INTENT.kind, target_id: INTENT.subject_id });
});

test('the real server emits target_id on a bridge push, and its instructions promise only what the event carries', { timeout: 30000 }, async (t) => {
  const runtime = scratch(t, 'contract-rt-');
  const socketPath = path.join(runtime, 'chan.sock');
  const client = await connectServer(
    t,
    { BRIDGE_CHANNEL_SOCKET: socketPath },
    { name: 'contract-test', runtimePrefix: 'contract-unused-' },
  );

  const received = new Promise((resolve) => {
    client.fallbackNotificationHandler = async (notification) => {
      if (notification.method === 'notifications/claude/channel') resolve(notification.params);
    };
  });
  assert.equal(await push(socketPath, BODY), 202);
  const params = await received;

  assert.equal(params.content, BODY, 'the body is forwarded verbatim as the event content');
  assert.deepEqual(params.meta, { kind: INTENT.kind, target_id: INTENT.subject_id });

  const instructions = client.getInstructions();
  const tag = instructions.match(/<channel ([^>]*)>/);
  assert.ok(tag, 'the instructions no longer show the event tag in the shape this test reads');
  // `source` is not a meta key this server sets, so it is outside this check.
  const promisedAttributes = [...tag[1].matchAll(/(\w+)="/g)].map((m) => m[1]).filter((a) => a !== 'source');
  assert.ok(promisedAttributes.length > 0, 'no attributes parsed, so the check below would pass on anything');
  for (const attribute of promisedAttributes) {
    assert.ok(
      Object.hasOwn(params.meta, attribute),
      `the instructions promise a ${attribute} attribute that a bridge push does not carry`,
    );
  }

  const body = instructions.match(/\{"intent": \{([^}]*)\}\}/);
  assert.ok(body, 'the instructions no longer show the body shape in the form this test reads');
  const promisedFields = body[1].split(',').map((f) => f.trim()).filter((f) => f !== '...');
  assert.ok(promisedFields.length > 0, 'no body fields parsed, so the check below would pass on anything');
  for (const field of promisedFields) {
    assert.ok(Object.hasOwn(INTENT, field), `the instructions promise an intent.${field} that the bridge body does not carry`);
  }
});
