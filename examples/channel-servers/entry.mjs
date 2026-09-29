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
//      alive, and this machine's boot time, is Claude Code re-spawning the server inside the same
//      session: no network, no disk write, the same launch id, straight to step 3 on the installed
//      release. Anything else is a new launch: a fresh launch id is written to launch.json.
//   1. RESOLVE the installed release: `classifyRelease` (the one judge of a release's files —
//      design review, card#10568 non-convergence re-derivation) says `ok` for the one `current.json`
//      names, or, failing that, for the newest other release under `versions/` — said on stderr
//      and in state.json's `recovered`. None ⇒ not started (step 5). Nothing is deleted here: a
//      release that does not classify `ok` is merely passed over for this launch; `client-update.mjs`,
//      under its lock, is what removes one, on ITS OWN policy (a `bad` release it is about to
//      replace; a release the retention prune does not keep).
//   2. UPDATE, on a new launch only: the INSTALLED release's `client/client-update.mjs`
//      `runLaunchUpdate({root, budgetMs, signal, launchId, installed})`, raced against
//      `budgetMs` (AWB_CLIENT_UPDATE_BUDGET_MS, default 20 s). When the budget wins, the signal
//      is aborted; the updater checks it before every irreversible step and never does network
//      work after it. A throw, a rejection or a budget overrun costs nothing but the update: the
//      installed release still starts. Under its lock the updater re-resolves step 1 itself and
//      repoints `current.json` to what THAT resolve selects whenever it names anything else.
//   3. IMPORT the release step 1's resolution picks (re-run after step 2); current.json names it
//      once the update reaches recoverRoot — anything that stops the update short of it (e.g. a reconnect, a held lock, an invalid budget or a
//      failed pointer write) leaves current.json naming another release, so a seat-tool shim can run
//      from a different release than the server until the next update reaches recoverRoot.
//      AWB_CLIENT_ROOT, AWB_LAUNCH_ID and AWB_BRIDGE_RELEASE are set to what is imported. An import
//      that throws does not try another release (DL-434 bound 4): it is not started (step 5),
//      worded from one `classifyRelease('full')` pass over the release that failed.
//   4. `<root>/state.json` is written by THIS file only, once per new launch, and its `running`
//      is the release step 3 imports (design review r3-M8). A
//      budget overrun is `update_failed` only when the pointer did not move; when it did, the
//      release was installed before the overrun and the state is `current` with `late: true`.
//      The launch.json (step 0) and state.json writes are each best-effort: a failure is said on
//      stderr and the launch goes on — an unwritable record never keeps a startable release from
//      starting.
//      ⭐ state.json's FIELDS are a CROSS-RELEASE CONTRACT: this file writes them, but they are read
//      by `clientUpdateInstruction` in the RUNNING release's OWN `channel-lib.mjs` — a release that
//      may have shipped before or after the entry.mjs that wrote this particular file, since entry.mjs
//      itself only changes with a new DL. A field is therefore never renamed or repurposed, only
//      added: `launch_id` (channel-lib binds state.json to this launch by it), `written_at`,
//      `state`, `running`, `installed`, `published`, `offer`, `approval_owed`, `error`,
//      `recovered` (still-bad releases named, `recoverRoot`'s own release started instead),
//      `late` (a budget overrun after the pointer already moved), `report_error`, `updater_broken`
//      (the installed updater's own code threw — only a bootstrap onto a working updater repairs
//      it) and `log_unreadable` (`install-log.jsonl` itself could not be read — a local file fault,
//      not the updater's or the release's).
//   5. NOT STARTED: no release classifies `ok`, or it cannot be resolved or imported. The channel's
//      `.FAILED` marker is written (the path the launcher and `bridge:check` read), state.json says
//      `not_started`, stderr says why, exit 2 — Claude Code still starts the session, without the
//      channel.
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

/**
 * The files under a release's `client/` it cannot run or update itself without. The updater
 * refuses a pack lacking one; step 1 hash-checks them before starting a release; and
 * `bin/build-client-pack.py` refuses to build a tree without them (bin/test_build_client_pack.py
 * holds its list equal to this one).
 */
export const REQUIRED_CLIENT_FILES = ['entry.mjs', 'client-update.mjs', 'agent-webhook-bridge-channel.mjs', 'channel-lib.mjs', 'package.json'];
export const DEFAULT_BUDGET_MS = 20000;

/**
 * The per-chunk leading-digit tuple, in lockstep with the bridge's
 * `ChannelSnapshotManifest::versionTuple` and the provisioner's `_version_tuple`: the shared
 * vectors in this directory's `tests/fixtures/version-comparator-vectors.json` are asserted
 * against all three. A chunk with no leading digit reads as 0, which is why every release this
 * client compares is checked against {@see STRICT_RELEASE} first — `v1.0.0` would otherwise order
 * below `0.91.0`.
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

function sha256Of(bytes) {
  return crypto.createHash('sha256').update(bytes).digest('hex');
}

const SHA256_HEX = /^[0-9a-f]{64}$/;

/**
 * ⭐ THE ONE JUDGE OF A RELEASE'S FILES (design review, non-convergence re-derivation after r2→r4:
 * three rounds each fixed a read fault — EACCES, EIO, EISDIR, anything that is not ENOENT or a
 * hash mismatch — differently in one reader, and the next round found a sibling reader deciding it
 * another way). A test pins a direct fs read under versions/ from a function not on its allowlist,
 * on a path the test exercises in-process; reads via fs.open/readSync, inside an allowlisted
 * function, or in the child process (importFailure, main) are not pinned.
 *
 * Returns one of:
 *   `{status: 'not-a-release'}` — `release` is not a bare X.Y.Z (current.json naming one; a
 *     `versions/` entry with such a name is not classified at all — resolveInstalled skips it).
 *   `{status: 'ok', packSha, filesJsonSha, listing}` — every file in `scope` matches its FILES.json
 *     line; `listing` is FILES.json's parsed array, for a caller that needs more of it (settleRoot's
 *     seat-tool list).
 *   `{status: 'bad', file, why, message}` — `file` is the path that failed (`.verified` or
 *     `FILES.json` for those two, else a FILES.json-listed path); `why` is `missing` (ENOENT),
 *     `mismatch` (a hash or size disagreement), `malformed` (unreadable as what it must be — JSON,
 *     two sha256 lines, a list) or `read-fault(<code>)` for anything else (a permission or I/O
 *     error — this release is NOT thereby proven bad, only unreadable; callers decide on `bad` the
 *     same way whatever its `why` (only the not-started messages word a read fault apart), per
 *     the design review that reversed the earlier
 *     never-delete-on-a-read-fault rule: the split protected nothing reachable and blocked the one
 *     repair that mattered).
 *
 * `scope` is `'required'` — REQUIRED_CLIENT_FILES only, cheap enough for every launch — or `'full'`
 * — every FILES.json-listed file, paid once by an install/bootstrap (`commitInstall`'s reuse
 * check, so "repaired by a bootstrap" holds for a file outside REQUIRED_CLIENT_FILES too) or by a
 * launch already not starting because its import threw (`importFailure`, DL-434 Decision 9).
 */
export function classifyRelease(root, release, scope) {
  if (!STRICT_RELEASE.test(String(release))) {
    return { status: 'not-a-release' };
  }
  const dir = path.join(root, 'versions', release);
  const bad = (file, why, message) => ({ status: 'bad', file, why, message });
  const readFault = (file, err) => (err && err.code === 'ENOENT' ? bad(file, 'missing', `${file} is missing`) : bad(file, `read-fault(${err && err.code ? err.code : err})`, `${file} could not be read (${err && err.code ? err.code : err})`));
  let verifiedText;
  try {
    verifiedText = fs.readFileSync(path.join(dir, '.verified'), 'utf8');
  } catch (err) {
    return readFault('.verified', err);
  }
  const [packSha, filesJsonSha] = verifiedText.trim().split('\n');
  if (!SHA256_HEX.test(packSha) || !SHA256_HEX.test(filesJsonSha)) {
    return bad('.verified', 'malformed', '.verified does not hold two sha256 lines');
  }
  let filesBytes;
  try {
    filesBytes = fs.readFileSync(path.join(dir, 'FILES.json'));
  } catch (err) {
    return readFault('FILES.json', err);
  }
  if (sha256Of(filesBytes) !== filesJsonSha) {
    return bad('FILES.json', 'mismatch', 'FILES.json does not hash to the digest it was installed with');
  }
  let listing;
  try {
    listing = JSON.parse(filesBytes.toString('utf8'));
  } catch {
    listing = null;
  }
  if (!Array.isArray(listing)) {
    return bad('FILES.json', 'malformed', 'FILES.json is not a JSON list');
  }
  const lines = new Map(listing.map((l) => [l && l.path, l]));
  const paths = scope === 'full' ? [...lines.keys()] : REQUIRED_CLIENT_FILES.map((name) => `client/${name}`);
  for (const filePath of paths) {
    let bytes;
    try {
      bytes = fs.readFileSync(path.join(dir, filePath));
    } catch (err) {
      return readFault(filePath, err);
    }
    const line = lines.get(filePath);
    if (!line || line.size !== bytes.length || line.sha256 !== sha256Of(bytes)) {
      return bad(filePath, 'mismatch', `${filePath} does not match its FILES.json line`);
    }
  }
  return { status: 'ok', packSha, filesJsonSha, listing };
}

/**
 * `current.json`'s content, and — only when it does not name the eventual selection — the reason
 * `recoverRoot` repoints it, told apart the way {@see classifyRelease} tells a release's own faults
 * apart: `value` is the parsed object or null; `cause` is null on a clean read, else `missing`
 * (ENOENT), `malformed` (unreadable JSON) or `read-fault(<code>)` (anything else, including a
 * directory in its place — EISDIR).
 */
function readCurrentJson(root) {
  let text;
  try {
    text = fs.readFileSync(path.join(root, 'current.json'), 'utf8');
  } catch (err) {
    return { value: null, cause: err && err.code === 'ENOENT' ? 'missing' : `read-fault(${err && err.code ? err.code : err})` };
  }
  try {
    return { value: JSON.parse(text), cause: null };
  } catch {
    return { value: null, cause: 'malformed' };
  }
}

/**
 * Step 1: the installed release — `{release, packSha, filesJsonSha, current, currentCause,
 * recovered, bad}`, with `release`, `packSha` and `filesJsonSha` null when nothing under
 * `versions/` classifies `ok`. `current` is current.json's parsed content, or null when
 * {@see readCurrentJson}'s `cause` says why (carried as `currentCause`, used only when `current`
 * names nothing — `recoverRoot`'s message). `bad` lists `{release, why, file, message}` for every release
 * this launch classified and did not select — a release this launch never looked at (because the
 * selection was current.json's own, first try) is not in it.
 */
export function resolveInstalled(root) {
  const { value: current, cause: currentCause } = readCurrentJson(root);
  const bad = [];

  const judged = new Map();
  const classify = (release) => {
    if (judged.has(release)) {
      return judged.get(release);
    }
    const c = classifyRelease(root, release, 'required');
    judged.set(release, c);
    if (c.status !== 'ok') {
      bad.push({ release, why: c.status === 'not-a-release' ? 'not-a-release' : c.why, file: c.file, message: c.status === 'not-a-release' ? `${release} is not a valid X.Y.Z release` : c.message });
    }
    return c;
  };
  const namedRelease = current && typeof current === 'object' && typeof current.bridge_release === 'string' ? current.bridge_release : null;
  if (namedRelease !== null) {
    const c = classify(namedRelease);
    if (c.status === 'ok') {
      return { release: namedRelease, packSha: c.packSha, filesJsonSha: c.filesJsonSha, current, currentCause, recovered: false, bad };
    }
  }
  let names = [];
  try {
    names = fs.readdirSync(path.join(root, 'versions'));
  } catch {
    names = [];
  }
  // A name that is not a bare X.Y.Z is not a release: ignored here, not listed in `bad`.
  const ok = names.filter((name) => STRICT_RELEASE.test(name)).map((name) => [name, classify(name)]).filter(([, c]) => c.status === 'ok').sort((a, b) => compareReleases(a[0], b[0]));
  if (ok.length === 0) {
    return { release: null, packSha: null, filesJsonSha: null, current, currentCause, recovered: true, bad };
  }
  const [release, c] = ok[ok.length - 1];
  return { release, packSha: c.packSha, filesJsonSha: c.filesJsonSha, current, currentCause, recovered: true, bad };
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
 * When this machine booted, in ms since the epoch. Two readings drift by clock adjustment, so they
 * are compared with {@see BOOT_TOLERANCE_MS} of slack, never for equality.
 */
export function bootTimeMs() {
  return Date.now() - Math.round(os.uptime() * 1000);
}

/** Two boot-time readings within this are one boot; a reboot inside it is not told apart. */
export const BOOT_TOLERANCE_MS = 60000;

/**
 * Step 0. `launch` is launch.json's content (or null). A reconnect is a launch record naming THIS
 * process's parent, alive, recorded during THIS boot — Claude Code re-spawning its channel server
 * inside one session; the boot time is what stops a reboot that hands the new session the old
 * session's pid from reading as a reconnect. The
 * parent is the session only while Claude Code spawns `node` directly (it does for the `command:
 * "node"` entry the provisioner writes); a platform or wrapper that interposes a per-spawn process
 * would make every reconnect look new (named residual, design review r3 §4).
 */
export function decideLaunch(launch, { ppid = process.ppid, alive = pidAlive, bootMs = bootTimeMs() } = {}) {
  const reconnect =
    launch !== null &&
    typeof launch === 'object' &&
    Number.isInteger(launch.ppid) &&
    launch.ppid > 1 &&
    launch.ppid === ppid &&
    Number.isFinite(launch.boot_ms) &&
    Math.abs(launch.boot_ms - bootMs) <= BOOT_TOLERANCE_MS &&
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

/** `{recovered}` when step 1 passed over a release that did not classify `ok` and it is still not what runs. */
export function recoveredNote(before, after) {
  const still = (before.bad ?? []).filter((d) => d.release !== after.release);
  if (still.length === 0) {
    return {};
  }
  return { recovered: still.map((d) => `release ${d.release} is not intact (${d.message})`).join('; ') + `; release ${after.release} started instead` };
}

function tryWrite(channel, file, value) {
  try {
    writeJsonAtomic(file, value);
    return true;
  } catch (err) {
    say(channel, `could not write ${file} (${err && err.message ? err.message : err}); starting the channel server anyway`);
    return false;
  }
}

/**
 * Step 2. Resolves `{result}` (what the updater returned), `{expired: true}` or `{error}` — never
 * rejects, and waits at most `budget` ms plus one synchronous step the updater was already inside
 * when the budget ran out: the timer cannot fire during a synchronous step, and the longest is the
 * pack's verify-and-stage (DL-434 bound 1 gives the measured figure).
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
  const base = { launch_id: launchId, running: after.release, installed: after.release, written_at: new Date().toISOString(), ...recoveredNote(before, after) };
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
      ...(r.log_unreadable === true ? { log_unreadable: true } : {}),
    };
  }
  if (outcome.expired) {
    if (after.release !== before.release) {
      return { ...base, state: 'current', late: true, published: after.release, offer: after.release, approval_owed: null, error: `the ${budget} ms update budget ran out after release ${after.release} was installed; its report is sent at the next launch` };
    }
    return { ...base, state: 'update_failed', published: null, offer: null, approval_owed: null, error: `the ${budget} ms update budget ran out` };
  }
  const message = outcome.error && outcome.error.message ? outcome.error.message : String(outcome.error);
  // The ONE update_failed shape where the installed updater itself could not even run (its import
  // threw, or `runLaunchUpdate` rejected instead of resolving) — every other shape means the
  // updater ran fine and refused or deferred for its own reasons. `clientUpdateInstruction` reads
  // this to say what actually repairs it: a release with a working updater, bootstrapped by hand,
  // because this one cannot run to fetch it.
  return { ...base, state: 'update_failed', published: null, offer: null, approval_owed: null, updater_broken: true, error: `the installed updater failed: ${message}` };
}

function say(channel, text) {
  process.stderr.write(`[${channel}] client update: ${text}\n`);
}

/**
 * What an import failure of `release` means, worded for the operator. The seat is not started
 * either way (no other release is tried: DL-434 bound 4) — nothing is ever deleted here, only
 * `client-update.mjs`, under the lock, removes a release. One `classifyRelease` full-scope pass
 * over the failed release picks the remedy (rule 7 — one remedy per cause): its whole tree
 * verifying anyway means the published release itself has a defect the bridge must fix, or this
 * seat's Node runtime no longer satisfies its `node_engines`; a confirmed bad file means a
 * bootstrap repairs it; a read fault means neither is established, and names the file to fix.
 */
function importFailure(root, release, err) {
  const why = err && err.message ? err.message : String(err);
  const c = classifyRelease(root, release, 'full');
  if (c.status === 'ok') {
    return `release ${release} verifies but failed to start (${why}): a defect in the published release, or this seat's Node runtime no longer matches its node_engines — the bridge must publish a fixed release`;
  }
  if (c.status === 'bad' && c.why.startsWith('read-fault')) {
    return `release ${release} failed to start (${why}), and whether it is intact on this seat was not established: ${c.message} — fix ${c.file} (its owner, permissions or disk) and the next launch retries`;
  }
  const cause = c.status === 'bad' ? c.message : `release ${release} is not a valid X.Y.Z`;
  return `release ${release} is not intact on this seat (${cause}): re-bootstrap from the bridge's pack — a bootstrap re-verifies the whole tree and replaces what is not intact`;
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
      tryWrite(channel, path.join(root, 'launch.json'), { launch_id: launchId, ppid: process.ppid, pid: process.pid, boot_ms: bootTimeMs(), started_at: new Date().toISOString() });
    } else {
      say(channel, `skipped: session running (launch ${launchId} of parent ${process.ppid}); starting the installed release without checking for an update`);
    }

    step = 'resolve the installed release';
    const before = resolveInstalled(root);
    if (before.release === null) {
      const bad = before.bad.map((d) => `; release ${d.release} is not intact (${d.message})`).join('');
      const fixes = before.bad.filter((d) => d.why.startsWith('read-fault(')).map((d) => `, or fix versions/${d.release}/${d.file} (${d.why.slice('read-fault('.length, -1)})`).join('');
      return notStarted(root, channel, env, launchId, `no verified client release is installed under ${path.join(root, 'versions')}${bad} — bootstrap this seat's client from its bridge's published pack${fixes}; THIS Claude Code session is deaf to live-wake until then`);
    }
    for (const d of before.bad) {
      say(channel, `release ${d.release} is not intact (${d.message}); it is passed over`);
    }
    if (before.recovered) {
      say(channel, `current.json does not name the selected release; running ${before.release}`);
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
      const resolved = resolveInstalled(root);
      after = resolved.release === null ? before : resolved;
      const state = composeState({ launchId, outcome, before, after, budget });
      tryWrite(channel, path.join(root, 'state.json'), state);
      if (state.state !== 'current') {
        say(channel, `${state.state}${state.error ? ` — ${state.error}` : ''}; starting release ${after.release}`);
      }
    }

    step = `start release ${after.release}`;
    env.AWB_CLIENT_ROOT = root;
    env.AWB_LAUNCH_ID = launchId;
    env.AWB_BRIDGE_RELEASE = after.release;
    try {
      await import(pathToFileURL(path.join(root, 'versions', after.release, 'client', SERVER_FILE)).href);
    } catch (importErr) {
      return notStarted(root, channel, env, launchId, `${importFailure(root, after.release, importErr)} — THIS Claude Code session is deaf to live-wake`);
    }
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
