// Positive control for the harness live-state guard (card#5233).
//
// The guard's whole value is its teardown assertion, and an assertion nobody has seen
// fail is a decoration. These cases drive the comparator the assertion is built on:
// a delta must be DETECTED (added / overwritten-in-place), and an untouched directory
// must report clean — a comparator that always fires is as blind as one that never does.
//
// The overwrite case is the sharp one: the incident wrote a `.FAILED` marker OVER an
// existing path, so a name-set compare would have reported the run clean.
import * as guard from './live-state-guard.mjs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

function scratch(t) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'guard-control-'));
  t.after(() => fs.rmSync(dir, { recursive: true, force: true }));
  return dir;
}

test('an untouched directory reports no delta', (t) => {
  const dir = scratch(t);
  fs.writeFileSync(path.join(dir, 'agent-webhook-bridge-channel-x.sock'), '');
  const { snapshot, diff } = guard;
  const delta = diff(snapshot(dir), snapshot(dir));
  assert.deepEqual(delta, { added: [], removed: [], changed: [] });
});

test('an added marker is detected', (t) => {
  const dir = scratch(t);
  const { snapshot, diff } = guard;
  const before = snapshot(dir);
  fs.writeFileSync(path.join(dir, 'agent-webhook-bridge-channel-x.sock.FAILED'), 'deaf\n');
  const delta = diff(before, snapshot(dir));
  assert.deepEqual(delta.added, ['agent-webhook-bridge-channel-x.sock.FAILED']);
  assert.deepEqual(delta.removed, []);
});

test('a marker overwritten IN PLACE is detected — a name-set compare would miss it', (t) => {
  const dir = scratch(t);
  const marker = path.join(dir, 'agent-webhook-bridge-channel-x.sock.FAILED');
  fs.writeFileSync(marker, 'stale marker from a previous deafness\n');
  const { snapshot, diff } = guard;
  const before = snapshot(dir);
  fs.writeFileSync(marker, 'a FALSE marker written over the stale one\n');
  const delta = diff(before, snapshot(dir));
  assert.deepEqual(delta.added, [], 'no name changed — this is the case a name-set compare misses');
  assert.deepEqual(delta.changed, ['agent-webhook-bridge-channel-x.sock.FAILED']);
});

test('a removed entry is detected', (t) => {
  const dir = scratch(t);
  const sock = path.join(dir, 'agent-webhook-bridge-channel-x.sock');
  fs.writeFileSync(sock, '');
  const { snapshot, diff } = guard;
  const before = snapshot(dir);
  fs.rmSync(sock);
  const delta = diff(before, snapshot(dir));
  assert.deepEqual(delta.removed, ['agent-webhook-bridge-channel-x.sock']);
});

test('an unreadable directory snapshots to null rather than throwing', () => {
  const { snapshot } = guard;
  assert.equal(snapshot(path.join(os.tmpdir(), 'guard-control-does-not-exist')), null);
  assert.equal(snapshot(null), null);
});

test('the guard neutralised every channel-addressing input', () => {
  // The seat exports BRIDGE_CHANNEL_SOCKET pointing at its LIVE socket; a child spawned
  // with a plain {...process.env} would reach it. After the guard, all four addressing
  // inputs point at the per-run sandbox.
  assert.equal(process.env.BRIDGE_CHANNEL_TRANSPORT, 'http');
  assert.equal(process.env.BRIDGE_CHANNEL_PORT, '0');
  assert.match(process.env.XDG_RUNTIME_DIR, /channel-suite-sandbox-/);
  assert.match(process.env.BRIDGE_CHANNEL_SOCKET, /channel-suite-sandbox-.*sandbox-channel\.sock$/);
});
