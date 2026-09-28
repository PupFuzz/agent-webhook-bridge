#!/usr/bin/env node
// The seat's entry point for a channel server installed by the client updater (card#10568).
// `.mcp.json` args point here: `<root>/entry.mjs`. Claude Code spawns it as the channel's MCP
// server; before any MCP I/O it decides whether this is a new launch, runs the launch-time
// update under a hard deadline, and then imports the installed release's channel server.
//
// ⛔ A FROZEN CONTRACT (DL-434). The copy at `<root>/entry.mjs` is replaced only by the updater,
// only when a newly installed release carries different bytes for it, and it must keep working
// with every release that installer can put under `versions/` — so what it does, and the names it
// reads and writes, change only with a new DL:
//   0. SESSION GUARD. `<root>/launch.json` naming this process's parent pid, with that parent
//      alive, is Claude Code re-spawning the server inside the same session: no network, no disk
//      write, the same launch id, straight to step 3 on the installed release. Anything else is a
//      new launch: a fresh launch id is written to launch.json.
//   1. RESOLVE the installed release: `current.json`, or, when that is unreadable, the newest
//      `versions/<release>/` holding a `.verified` file (a swap writes the version directory
//      before the pointer, so the newest verified one is never older than the last good pointer).
//      None ⇒ not started (step 5).
//   2. UPDATE, on a new launch only: the INSTALLED release's `client/client-update.mjs`
//      `runLaunchUpdate({root, budgetMs, signal, launchId, installed})`, raced against
//      `budgetMs` (AWB_CLIENT_UPDATE_BUDGET_MS, default 20 s). When the budget wins, the signal
//      is aborted; the updater checks it before every irreversible step and never does network
//      work after it. A throw, a rejection or a budget overrun costs nothing but the update: the
//      installed release still starts.
//   3. IMPORT the release `current.json` names NOW — re-read after step 2 — with AWB_CLIENT_ROOT,
//      AWB_LAUNCH_ID and AWB_BRIDGE_RELEASE set to what is actually imported.
//   4. `<root>/state.json` is written by THIS file only, once per new launch, and its `running`
//      is the release step 3 imports — never the one step 1 resolved (design review r3-M8). A
//      budget overrun is `update_failed` only when the pointer did not move; when it did, the
//      release was installed before the overrun and the state is `current` with `late: true`.
//   5. NOT STARTED: nothing installed, or step 0/1/3 threw. The channel's `.FAILED` marker is
//      written (the path the launcher and `bridge:check` read), state.json says `not_started`,
//      stderr says why, exit 2 — Claude Code still starts the session, without the channel.
//   Nothing is ever written to stdout: it is the MCP frame channel.
//
// ⭐ THE PURE PIECES LIVE HERE, AND THE REST OF THE CLIENT IMPORTS THEM FROM HERE. This file must
// work with no sibling module (at `<root>` it has none), so the release comparator, the version
// and launch-id grammars, the atomic JSON write and the `.FAILED` marker path are defined here,
// once, and `client-update.mjs`, `channel-lib.mjs` and the channel server import them from their
// own release's copy of this file. Importing it has no effect: `main()` runs only when node was
// started on this file.

import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import crypto from 'node:crypto';
import { fileURLToPath, pathToFileURL } from 'node:url';

/** Bare `X.Y.Z`, each part at most 9 digits: the grammar the bridge whitelists a `launch.bridge_release` with (CallerReport). */
export const STRICT_RELEASE = /^[0-9]{1,9}\.[0-9]{1,9}\.[0-9]{1,9}$/;

/** A launch id as the bridge whitelists it (CallerReport, InstallLogEntry). A UUID fits. */
export const LAUNCH_ID = /^[0-9A-Za-z-]{1,64}$/;

export const SERVER_FILE = 'agent-webhook-bridge-channel.mjs';
export const UPDATER_FILE = 'client-update.mjs';
export const DEFAULT_BUDGET_MS = 20000;

/**
 * The per-chunk leading-digit tuple, in lockstep with the bridge's
 * `ChannelSnapshotManifest::versionTuple` and the provisioner's `_version_tuple`: the shared
 * vectors in `tests/Fixtures/version-comparator-vectors.json` are asserted against all three. A
 * chunk with no leading digit reads as 0, which is why every release this client compares is
 * checked against {@see STRICT_RELEASE} first — `v1.0.0` would otherwise order below `0.91.0`.
 */
export function versionTuple(version) {
  return String(version)
    .split('.')
    .map((chunk) => {
      const m = /^[0-9]+/.exec(chunk);
      return m ? Number(m[0]) : 0;
    });
}

/** Negative when `a` is older, 0 when equal, positive when newer. */
export function compareReleases(a, b) {
  const ta = versionTuple(a);
  const tb = versionTuple(b);
  const shared = Math.min(ta.length, tb.length);
  for (let i = 0; i < shared; i++) {
    if (ta[i] !== tb[i]) {
      return ta[i] < tb[i] ? -1 : 1;
    }
  }
  return Math.sign(ta.length - tb.length);
}

/** The channel's unix socket path: BRIDGE_CHANNEL_SOCKET, else the per-name default under XDG_RUNTIME_DIR, else null. */
export function channelSocketPath(env) {
  if (env.BRIDGE_CHANNEL_SOCKET) {
    return env.BRIDGE_CHANNEL_SOCKET;
  }
  if (!env.XDG_RUNTIME_DIR) {
    return null;
  }
  return path.join(env.XDG_RUNTIME_DIR, `agent-webhook-bridge-channel-${env.BRIDGE_CHANNEL_NAME || 'agent-webhook-bridge'}.sock`);
}

/**
 * Where a channel that could not start leaves its `.FAILED` marker (FR #2444) — the one path, for
 * the channel server's refusals and for this file's. UNIX: the socket's sibling `<socket>.FAILED`,
 * the path `bridge:check` derives from the agent's `channel.socket`. HTTP (or no resolvable
 * socket): keyed by name + port under $XDG_RUNTIME_DIR, else os.tmpdir() — %TEMP% on Windows,
 * where the launcher looks (a literal '/tmp' would resolve to C:\tmp under Node on Windows).
 */
export function failureMarkerPath(env) {
  const name = env.BRIDGE_CHANNEL_NAME || 'agent-webhook-bridge';
  const transport = (env.BRIDGE_CHANNEL_TRANSPORT || 'unix').toLowerCase();
  const socket = channelSocketPath(env);
  if (transport === 'unix' && socket) {
    return `${socket}.FAILED`;
  }
  const port = Number(env.BRIDGE_CHANNEL_PORT || 8788);
  return path.join(env.XDG_RUNTIME_DIR || os.tmpdir(), `agent-webhook-bridge-channel-${name}.http-${port}.FAILED`);
}

export function readJsonFile(file) {
  return JSON.parse(fs.readFileSync(file, 'utf8'));
}

/**
 * Replace `file` with `value` as JSON so that a reader sees the old bytes or the new ones, never
 * part of either: a temp file in the same directory, fsync, rename over, then fsync the directory
 * so the rename itself survives a power cut. A directory cannot be opened for fsync on Windows;
 * there the rename is as durable as the filesystem makes it.
 */
export function writeJsonAtomic(file, value) {
  const temp = `${file}.tmp-${process.pid}-${crypto.randomBytes(4).toString('hex')}`;
  const fd = fs.openSync(temp, 'w', 0o600);
  try {
    fs.writeSync(fd, `${JSON.stringify(value, null, 2)}\n`);
    fs.fsyncSync(fd);
  } finally {
    fs.closeSync(fd);
  }
  try {
    fs.renameSync(temp, file);
  } catch (err) {
    fs.rmSync(temp, { force: true });
    throw err;
  }
  fsyncDirectory(path.dirname(file));
}

export function fsyncDirectory(dir) {
  if (process.platform === 'win32') {
    return;
  }
  let fd;
  try {
    fd = fs.openSync(dir, 'r');
    fs.fsyncSync(fd);
  } catch {
    // A filesystem that refuses a directory fsync makes the rename as durable as it allows.
  } finally {
    if (fd !== undefined) {
      fs.closeSync(fd);
    }
  }
}

/** The sha256 in `versions/<release>/.verified`, or null when that release is not a verified install. */
export function verifiedPackSha(root, release) {
  if (!STRICT_RELEASE.test(String(release))) {
    return null;
  }
  try {
    const text = fs.readFileSync(path.join(root, 'versions', release, '.verified'), 'utf8').trim();
    return /^[0-9a-f]{64}$/.test(text) ? text : null;
  } catch {
    return null;
  }
}

/**
 * Step 1: the installed release — `{release, current, recovered}` — or null when nothing is.
 * `current` is current.json's content when it named a verified release, else null.
 */
export function resolveInstalled(root) {
  let current = null;
  try {
    current = readJsonFile(path.join(root, 'current.json'));
  } catch {
    current = null;
  }
  if (current && typeof current === 'object' && verifiedPackSha(root, current.bridge_release) !== null) {
    return { release: current.bridge_release, current, recovered: false };
  }
  let names = [];
  try {
    names = fs.readdirSync(path.join(root, 'versions'));
  } catch {
    names = [];
  }
  const verified = names.filter((name) => verifiedPackSha(root, name) !== null).sort(compareReleases);
  if (verified.length === 0) {
    return null;
  }
  return { release: verified[verified.length - 1], current: null, recovered: true };
}

function pidAlive(pid) {
  try {
    process.kill(pid, 0);
    return true;
  } catch (err) {
    // EPERM: the process exists and belongs to someone else.
    return Boolean(err && err.code === 'EPERM');
  }
}

/**
 * Step 0. `launch` is launch.json's content (or null). A reconnect is a launch record naming THIS
 * process's parent, alive — Claude Code re-spawning its channel server inside one session. The
 * parent is the session only while Claude Code spawns `node` directly (it does for the `command:
 * "node"` entry the provisioner writes); a platform or wrapper that interposes a per-spawn process
 * would make every reconnect look new (named residual, design review r3 §4).
 */
export function decideLaunch(launch, { ppid = process.ppid, alive = pidAlive } = {}) {
  const reconnect =
    launch !== null &&
    typeof launch === 'object' &&
    Number.isInteger(launch.ppid) &&
    launch.ppid > 1 &&
    launch.ppid === ppid &&
    typeof launch.launch_id === 'string' &&
    LAUNCH_ID.test(launch.launch_id) &&
    alive(launch.ppid);
  return reconnect ? { newLaunch: false, launchId: launch.launch_id } : { newLaunch: true, launchId: crypto.randomUUID() };
}

export function budgetMs(env) {
  const raw = env.AWB_CLIENT_UPDATE_BUDGET_MS;
  if (raw === undefined || raw === '') {
    return DEFAULT_BUDGET_MS;
  }
  const n = Number(raw);
  return Number.isInteger(n) && n > 0 && n <= 600000 ? n : null;
}

const EXPIRED = Symbol('update budget expired');

/**
 * Step 2. Resolves `{result}` (what the updater returned), `{expired: true}` or `{error}` —
 * never rejects, never waits past `budget` ms.
 */
export async function runUpdateStep(root, installed, launchId, budget, env) {
  const controller = new AbortController();
  let timer;
  const expired = new Promise((resolve) => {
    timer = setTimeout(() => resolve(EXPIRED), budget);
  });
  const attempt = (async () => {
    const updater = path.join(root, 'versions', installed.release, 'client', UPDATER_FILE);
    const mod = await import(pathToFileURL(updater).href);
    return await mod.runLaunchUpdate({ root, budgetMs: budget, signal: controller.signal, launchId, installed, env });
  })();
  // The attempt keeps running after the budget wins (it releases its lock and logs once). Its
  // late rejection is not an unhandled one — Promise.race below has already subscribed to it —
  // so it cannot take the channel server down.
  try {
    const winner = await Promise.race([attempt, expired]);
    if (winner === EXPIRED) {
      controller.abort();
      return { expired: true };
    }
    return { result: winner };
  } catch (error) {
    controller.abort();
    return { error };
  } finally {
    clearTimeout(timer);
  }
}

/** Step 4: this launch's state.json, from what step 2 said and what step 3 imports. */
export function composeState({ launchId, outcome, before, after, budget }) {
  const base = { launch_id: launchId, running: after.release, installed: after.release, written_at: new Date().toISOString() };
  if (outcome.result && typeof outcome.result === 'object') {
    const r = outcome.result;
    return {
      ...base,
      state: typeof r.state === 'string' ? r.state : 'update_failed',
      published: r.published ?? null,
      offer: r.offer ?? null,
      approval_owed: r.approval_owed ?? null,
      error: typeof r.state === 'string' ? (r.error ?? null) : 'the updater returned no state',
      ...(r.report_error ? { report_error: r.report_error } : {}),
    };
  }
  if (outcome.expired) {
    if (after.release !== before.release) {
      return { ...base, state: 'current', late: true, published: after.release, offer: after.release, approval_owed: null, error: `the ${budget} ms update budget ran out after release ${after.release} was installed; its report is sent at the next launch` };
    }
    return { ...base, state: 'update_failed', published: null, offer: null, approval_owed: null, error: `the ${budget} ms update budget ran out` };
  }
  const message = outcome.error && outcome.error.message ? outcome.error.message : String(outcome.error);
  return { ...base, state: 'update_failed', published: null, offer: null, approval_owed: null, error: `the installed updater failed: ${message}` };
}

function say(channel, text) {
  process.stderr.write(`[${channel}] client update: ${text}\n`);
}

function notStarted(root, channel, env, launchId, reason) {
  try {
    fs.writeFileSync(failureMarkerPath(env), `${new Date().toISOString()} pid=${process.pid} ${channel}: ${reason}\n`, { mode: 0o600 });
  } catch {
    // Best-effort: stderr and state.json still say it.
  }
  try {
    writeJsonAtomic(path.join(root, 'state.json'), {
      launch_id: launchId,
      state: 'not_started',
      running: null,
      installed: null,
      published: null,
      offer: null,
      approval_owed: null,
      error: reason,
      written_at: new Date().toISOString(),
    });
  } catch {
    // Best-effort.
  }
  say(channel, reason);
  process.exit(2);
}

export async function main({ env = process.env } = {}) {
  const root = path.dirname(fileURLToPath(import.meta.url));
  const channel = env.BRIDGE_CHANNEL_NAME || 'agent-webhook-bridge';
  let launchId = null;
  let step = 'decide whether this is a new launch';
  try {
    let launch = null;
    try {
      launch = readJsonFile(path.join(root, 'launch.json'));
    } catch {
      launch = null;
    }
    const decided = decideLaunch(launch);
    launchId = decided.launchId;
    if (decided.newLaunch) {
      writeJsonAtomic(path.join(root, 'launch.json'), { launch_id: launchId, ppid: process.ppid, pid: process.pid, started_at: new Date().toISOString() });
    } else {
      say(channel, `skipped: session running (launch ${launchId} of parent ${process.ppid}); starting the installed release without checking for an update`);
    }

    step = 'resolve the installed release';
    const before = resolveInstalled(root);
    if (before === null) {
      return notStarted(root, channel, env, launchId, `no verified client release is installed under ${path.join(root, 'versions')} — bootstrap this seat's client from its bridge's published pack; THIS Claude Code session is deaf to live-wake until then`);
    }
    if (before.recovered) {
      say(channel, `current.json is unreadable; running the newest verified release, ${before.release}`);
    }

    let after = before;
    if (decided.newLaunch) {
      step = 'run the launch-time update';
      const budget = budgetMs(env);
      let outcome;
      if (budget === null) {
        outcome = { result: { state: 'update_failed', error: `AWB_CLIENT_UPDATE_BUDGET_MS is ${JSON.stringify(env.AWB_CLIENT_UPDATE_BUDGET_MS)}, not a whole number of milliseconds from 1 to 600000, so no update was attempted` } };
      } else {
        outcome = await runUpdateStep(root, before, launchId, budget, env);
      }
      after = resolveInstalled(root) ?? before;
      const state = composeState({ launchId, outcome, before, after, budget });
      writeJsonAtomic(path.join(root, 'state.json'), state);
      if (state.state !== 'current') {
        say(channel, `${state.state}${state.error ? ` — ${state.error}` : ''}; starting release ${after.release}`);
      }
    }

    step = `start release ${after.release}`;
    env.AWB_CLIENT_ROOT = root;
    env.AWB_LAUNCH_ID = launchId;
    env.AWB_BRIDGE_RELEASE = after.release;
    await import(pathToFileURL(path.join(root, 'versions', after.release, 'client', SERVER_FILE)).href);
  } catch (err) {
    notStarted(root, channel, env, launchId, `could not ${step}: ${err && err.message ? err.message : err} — THIS Claude Code session is deaf to live-wake`);
  }
}

function invokedAsMain() {
  if (!process.argv[1]) {
    return false;
  }
  try {
    return fs.realpathSync(process.argv[1]) === fs.realpathSync(fileURLToPath(import.meta.url));
  } catch {
    return false;
  }
}

if (invokedAsMain()) {
  await main();
}
