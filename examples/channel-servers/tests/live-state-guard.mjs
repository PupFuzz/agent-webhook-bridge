// Harness-level live-state sandbox for the channel-server suites (card#5233).
//
// These suites spawn the REAL channel server, and the server writes `.FAILED` deafness
// markers to a path that FALLS BACK to the ambient environment:
// `markerPath()` resolves `${SOCKET_PATH}.FAILED` under `unix`, else
// `${XDG_RUNTIME_DIR || os.tmpdir()}/…FAILED`. A seat exports both
// `BRIDGE_CHANNEL_SOCKET` (its LIVE socket) and `XDG_RUNTIME_DIR`, so a child spawned
// with a plain `{...process.env}` reaches the seat's real endpoint: bind → EADDRINUSE →
// a `.FAILED` marker beside a HEALTHY socket. That is not hypothetical — it happened
// (DL-237(e)): a false "this session is deaf to live-wake" marker was written on a live
// seat while the socket was bound and accepting throughout.
//
// Per-test scrubbing does not close it. `marker-refusal.test.mjs` uses a DENYLIST
// (`{...process.env}` then delete two keys) and `clear-context.test.mjs` an ALLOWLIST;
// both are correct today, and neither notices a new test that forgets to use them. So
// the addressing inputs are neutralised HERE, at import, before any case runs — and the
// real runtime dir is snapshotted and re-checked after the file's tests finish.
//
// The assertion is the part that matters: without it this is a precaution nobody would
// notice failing. `live-state-guard.test.mjs` is its positive control.
//
// Import for effect as the FIRST import of every test file in this directory:
//     import './live-state-guard.mjs';
import { after } from 'node:test';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

/**
 * Name → (mode, size, mtimeNs) for every entry directly in `dir`, or null if unreadable.
 *
 * Not a name-set: the case that actually happens is a false marker written OVER an
 * existing one, and a seat that has previously been deaf is exactly where the stale
 * marker already sits. `lstatSync` so a symlink is not followed; sockets are covered
 * like anything else.
 */
export function snapshot(dir) {
  if (!dir) return null;
  let names;
  try {
    names = fs.readdirSync(dir).sort();
  } catch {
    return null;
  }
  const state = {};
  for (const name of names) {
    try {
      const st = fs.lstatSync(path.join(dir, name), { bigint: true });
      state[name] = `${st.mode}:${st.size}:${st.mtimeNs}`;
    } catch {
      state[name] = 'unstattable';
    }
  }
  return state;
}

/** {added, removed, changed} between two snapshots; all empty ⇒ no delta. */
export function diff(before, after_) {
  const b = before || {};
  const a = after_ || {};
  return {
    added: Object.keys(a).filter((k) => !(k in b)).sort(),
    removed: Object.keys(b).filter((k) => !(k in a)).sort(),
    changed: Object.keys(a).filter((k) => k in b && a[k] !== b[k]).sort(),
  };
}

const realRuntimeDir = process.env.XDG_RUNTIME_DIR || null;
const realBefore = snapshot(realRuntimeDir);

const sandbox = fs.mkdtempSync(path.join(os.tmpdir(), 'channel-suite-sandbox-'));

// All four channel-addressing inputs, matching the python harness's contract — not the
// socket and the runtime dir alone. Assignments, not defaults: the seat exports the real
// values and they must lose.
process.env.BRIDGE_CHANNEL_TRANSPORT = 'http';
process.env.BRIDGE_CHANNEL_PORT = '0';
process.env.XDG_RUNTIME_DIR = sandbox;
process.env.BRIDGE_CHANNEL_SOCKET = path.join(sandbox, 'sandbox-channel.sock');

after(() => {
  try {
    if (realRuntimeDir !== null) {
      const delta = diff(realBefore, snapshot(realRuntimeDir));
      if (delta.added.length || delta.removed.length || delta.changed.length) {
        throw new Error(
          `THIS SUITE TOUCHED LIVE STATE: ${realRuntimeDir} changed during the run ` +
            `(added=${JSON.stringify(delta.added)}, removed=${JSON.stringify(delta.removed)}, ` +
            `changed=${JSON.stringify(delta.changed)}). The sandbox in live-state-guard.mjs is ` +
            'not holding — a spawned channel server reached a real endpoint.',
        );
      }
    }
  } finally {
    fs.rmSync(sandbox, { recursive: true, force: true });
  }
});
