#!/usr/bin/env node
// The seat's client updater (card#10568). Two ways in, one verify-and-install path:
//
//   runLaunchUpdate({root, budgetMs, signal, launchId, installed})
//       what entry.mjs runs at every NEW launch, under its deadline (design §3.3): ask the
//       bridge's client-update door what it publishes, and install a newer release before the
//       channel server starts. Never mid-session — a running session's tool list never changes.
//   node client-update.mjs bootstrap --root <root>
//       the bootstrap (design §3.5): ask the bridge over the board-tools transport in the
//       environment — the seat's `.mcp.json` env, which the provisioner's `--bootstrap-client`
//       passes — and install what it offers. Nothing fetched is run: the installing code is this
//       file, the copy in the provisioner's own checkout.
//   node client-update.mjs install --pack <file> --manifest <file> --root <root>
//       the same bootstrap, from a pack and manifest already on disk.
//
// ⛔ THE SEAT TRUSTS ITS OWN BRIDGE, AND CHECKS WHAT IT RECEIVED (operator ruling, card#10567
// comment 6744: no signing — sha256 plus tagged releases). The manifest must hash to the digest
// the door named; the pack to the manifest's sha256 and size; FILES.json to the manifest's
// `files_json_sha256`; every file to its FILES.json line; and the archive may hold nothing but
// regular files under `client/`, `seat-tools/bin/` and `FILES.json`. The release must be newer
// than the installed one — a lower one is a downgrade, the same one with other bytes a tamper
// signal — and both are refused, logged, and leave the installed release running.
//
// ⛔ A LAUNCH'S FAILED UPDATE LEAVES THE INSTALLED RELEASE UNTOUCHED AND RUNNING. A launch writes
// nothing under `versions/<installed>/`. A new release is extracted to
// `staging/<release>.partial`, renamed whole into `versions/`, and only then does `current.json`
// point at it (temp file + fsync + rename) — so a process killed at any point leaves either the old
// pointer or the new one, each naming a complete, verified release.
//   ⚠ A POWER CUT IS WEAKER (DL-434 bound): the staged files are not fsynced one by one (a real
//   pack is thousands of files, and fsyncing each was measured at seconds), while the rename and
//   the pointer are, so on a filesystem that reorders them the pointer can survive naming files
//   that were never written. That is DETECTED, not prevented: entry.mjs starts a release only when
//   `classifyRelease` says `ok` (the one judge of a release's files — design review, non-convergence
//   re-derivation after review rounds r2 through r4 each fixed a read fault differently in one
//   reader and the next found a sibling reader deciding it another way); a release that is not
//   `ok` is simply not started, and this updater is what removes one, on its own policy.
//   `classifyRelease`'s cheap scope (REQUIRED_CLIENT_FILES only) is what a launch pays; its full
//   scope (every FILES.json-listed file) is what an install/bootstrap pays once, so a bootstrap
//   repairs a file outside REQUIRED_CLIENT_FILES too.
//
// ⛔ THE BUDGET. `signal` is aborted by entry.mjs when its deadline wins. The pack's
// verify-and-stage (the longest synchronous step) and every irreversible step — the rename into
// `versions/`, the `current.json` switch (with its install-log line), the
// `entry.mjs` replace, the shims, the prune — checks the signal AND the wall-clock
// deadline immediately before it, and none of them awaits: each runs to completion or not at all,
// so the deadline timer cannot fire half-way through one. After an abort the ONLY writes are
// removing the staging directory, one `fail` install-log line and releasing the lock, and no
// network call is started or continued (design review r3-m2).
//
// ⛔ THE INSTALL LOG (`install-log.jsonl`) IS APPEND-ONLY AND IS WRITTEN ONLY UNDER THE LOCK. Its
// lines chain by `seq` and `prev_sha256`; the bridge stores them verbatim and treats a re-sent seq
// with different bytes as a permanent break (DL-432 Decision 3), so no line is ever rewritten, and
// two launches racing for the next seq would be exactly that break — hence the lock. A launch that
// cannot take the lock logs nothing; its state.json says why.

import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import zlib from 'node:zlib';
import { fileURLToPath } from 'node:url';
import {
  STRICT_RELEASE,
  SERVER_FILE,
  UPDATER_FILE,
  compareReleases,
  readJsonFile,
  writeJsonAtomic,
  fsyncDirectory,
  classifyRelease,
  resolveInstalled,
  REQUIRED_CLIENT_FILES,
} from './entry.mjs';
import { sshRoundTrip, httpRoundTrip, boardToolsTransport, redactUrl, scrubSnippet, errorDetail, deriveClientDoorUrl, SERVED_TOOLS_FILE } from './channel-lib.mjs';

// ⚑ PINNED PROTOCOL CONSTANTS, checked against the bridge by
// tests/Unit/ClientUpdate/ClientReportLimitsLockstepTest.php: `client_report` refuses a report
// over EITHER bound (SeatClientLedger::MAX_REPORT_ENTRIES / MAX_REPORT_BYTES), and a line over
// InstallLogEntry::MAX_LINE_BYTES; the reason and source caps are that class's field widths.
export const MAX_REPORT_ENTRIES = 200;
export const MAX_REPORT_BYTES = 31956;
export const MAX_LINE_BYTES = 4096;
export const REASON_MAX_BYTES = 500;
export const SOURCE_MAX_BYTES = 255;

/** Per-leg deadlines inside the budget (design §3.3): the manifest and the report; the pack gets the rest. */
export const MANIFEST_LEG_MS = 5000;
export const REPORT_LEG_MS = 5000;

const SCHEMA = 1;
const KIND = 'agent-webhook-bridge-client-pack';
const SHA256 = /^[0-9a-f]{64}$/;
const COMMIT = /^[0-9a-f]{40}$/;
const INSTALL_ID = /^[0-9a-z-]{1,64}$/;
/** The bridge's agent-name grammar (provision-board-tools.py `_AGENT_RE`). */
const AGENT_NAME = /^[a-z0-9_-]+$/;
const FILES_JSON = 'FILES.json';
const REQUIRED_ENTRIES = REQUIRED_CLIENT_FILES.map((name) => `client/${name}`);
const MAX_UNPACKED_BYTES = 256 * 1024 * 1024;
/** A bootstrap's budget, and so how long it holds the lock before a launch may take it over. */
const INSTALL_LOCK_MS = 10 * 60 * 1000;
const SHIM_MARKER = 'agent-webhook-bridge client updater: seat-tool shim';

/** The pack or the offer is refused: logged `refuse` / `refused`. */
export class Refusal extends Error {}
/** The update could not be carried out: logged `fail` / `failed`. */
export class Failure extends Error {}
/** The budget ran out (the signal, or the wall clock): logged `fail`, nothing further happens. */
export class Aborted extends Error {}
/**
 * The bridge ANSWERED, with its own well-formed `{ok: false}` refusal, and offered nothing:
 *   - to `client_manifest`, whatever the status — a bridge that publishes no pack or cannot
 *     serve it (a 5xx), and a bridge older than the client-update door, which refuses the `op`
 *     body as a malformed board-tools call (a 4xx: `request must carry a non-empty tool`);
 *   - to `client_pack`, with a server-side status (HTTP >= 500, or the ssh door's exit 2) — its
 *     store could not serve the pack it had just named.
 * A 4xx to `client_pack` stays a plain Failure: the publication moved between the two calls.
 * A Failure like any other everywhere but the bootstrap, which reads it as "nothing is offered
 * right now" rather than as a broken install (DL-445 Decision 2). An answer that is not a JSON
 * object with `ok: false` — unreachable, a timeout, a PHP fatal, a framework error page — is
 * never this.
 */
export class ServerDeclined extends Failure {}

/** `client-update.mjs bootstrap`'s exit when the bridge offers nothing to install right now. */
export const EXIT_NOTHING_OFFERED = 3;

export function sha256(bytes) {
  return crypto.createHash('sha256').update(bytes).digest('hex');
}

/** `text` cut to at most `max` UTF-8 bytes, on a character boundary, marked with `…` when cut. */
export function clipBytes(text, max) {
  const s = String(text);
  if (Buffer.byteLength(s, 'utf8') <= max) {
    return s;
  }
  let cut = Buffer.from(s, 'utf8').subarray(0, max - 3).toString('utf8');
  if (cut.endsWith('�')) {
    cut = cut.slice(0, -1);
  }
  return `${cut}…`;
}

function crashAt(ctx, point) {
  // Test hooks named by design §3.6: kill this process at a named point. A kill, not a power cut —
  // the kernel still writes out what the process wrote; `classifyRelease` covers a power cut.
  if (ctx.env.AWB_CLIENT_CRASH_AT === point) {
    process.kill(process.pid, 'SIGKILL');
  }
}

function failAt(ctx, point) {
  // Test hook: a non-transient OS failure at a named point, so the code that must survive one runs.
  if (ctx.env.AWB_CLIENT_FAIL_AT === point) {
    throw Object.assign(new Error(`${point} refused by the test hook`), { code: 'EIO' });
  }
}

function checkBudget(ctx, what) {
  if (ctx.signal.aborted || Date.now() >= ctx.deadline) {
    throw new Aborted(`the update budget ran out before ${what}`);
  }
}

// ---------------------------------------------------------------------------------------------
// The install log

/** Every whole line of the install log, those of its current (last) install id only. */
export function readLog(root) {
  const file = path.join(root, 'install-log.jsonl');
  let text;
  try {
    text = fs.readFileSync(file, 'utf8');
  } catch (err) {
    if (err.code === 'ENOENT') {
      return { file, lines: [], installId: null, torn: false };
    }
    throw err;
  }
  const parsed = [];
  for (const raw of text.split('\n')) {
    if (raw === '') {
      continue;
    }
    let obj;
    try {
      obj = JSON.parse(raw);
    } catch {
      continue;
    }
    if (obj && typeof obj === 'object' && typeof obj.install_id === 'string' && Number.isInteger(obj.seq)) {
      parsed.push({ text: raw, obj });
    }
  }
  const installId = parsed.length > 0 ? parsed[parsed.length - 1].obj.install_id : null;
  return { file, lines: parsed.filter((l) => l.obj.install_id === installId), installId, torn: text.length > 0 && !text.endsWith('\n') };
}

export class InstallLog {
  constructor({ file, lines, installId, torn }, say) {
    this.io = { writeSync: fs.writeSync };
    this.file = file;
    this.lines = lines;
    this.torn = torn;
    if (installId !== null && INSTALL_ID.test(installId)) {
      this.installId = installId;
    } else {
      // A lower-case UUID: the bridge refuses any other install id (DL-432 Decision 3).
      this.installId = crypto.randomUUID();
      say(installId === null ? `no install log yet; this install is ${this.installId}` : `the install log's install id ${JSON.stringify(installId)} is not one the bridge accepts; starting install ${this.installId}`);
    }
    if (torn) {
      say(`the install log's last line is incomplete (an interrupted write); it is left in place and skipped`);
    }
  }

  static open(root, say) {
    return new InstallLog(readLog(root), say);
  }

  last() {
    return this.lines.length > 0 ? this.lines[this.lines.length - 1] : null;
  }

  /** Append one line. `fields` holds action, result, actor and whatever else is known. */
  append(fields) {
    const last = this.last();
    const line = {
      install_id: this.installId,
      seq: last ? last.obj.seq + 1 : 1,
      time: new Date().toISOString(),
      action: fields.action,
      result: fields.result,
      actor: fields.actor,
    };
    for (const key of ['launch_id', 'from_bridge_release', 'to_bridge_release', 'client_version', 'pack_sha256', 'files_json_sha256', 'manifest_sha256']) {
      if (fields[key] !== undefined && fields[key] !== null) {
        line[key] = fields[key];
      }
    }
    const prev = last ? sha256(Buffer.from(last.text, 'utf8')) : null;
    // Non-ASCII stays raw: the bridge's report byte bound assumes it (DL-432 Decision 3). The line
    // must also fit the bridge's MAX_LINE_BYTES once JSON-escaped (a quote or a control character
    // grows as it is escaped), so the free text is cut further — `reason` first, then `source` —
    // until it does; every other field is fixed-width.
    const render = (reasonMax, sourceMax) => {
      const out = { ...line };
      if (fields.source) {
        out.source = clipBytes(fields.source, sourceMax);
      }
      if (fields.reason) {
        out.reason = clipBytes(fields.reason, reasonMax);
      }
      out.prev_sha256 = prev;
      return out;
    };
    let reasonMax = REASON_MAX_BYTES;
    let sourceMax = SOURCE_MAX_BYTES;
    let obj = render(reasonMax, sourceMax);
    let text = JSON.stringify(obj);
    while (Buffer.byteLength(text, 'utf8') > MAX_LINE_BYTES) {
      if (fields.reason && reasonMax > 16) {
        reasonMax = Math.max(16, Math.floor(reasonMax / 2));
      } else if (fields.source && sourceMax > 16) {
        sourceMax = Math.max(16, Math.floor(sourceMax / 2));
      } else {
        throw new Error(`an install-log line cannot be brought under ${MAX_LINE_BYTES} bytes`);
      }
      obj = render(reasonMax, sourceMax);
      text = JSON.stringify(obj);
    }
    this.write(`${this.torn ? '\n' : ''}${text}\n`);
    // An interrupted write earlier left a fragment; the newline above ended it.
    this.torn = false;
    this.lines.push({ text, obj });
    return obj;
  }

  /**
   * Append `data` whole, or leave the file as it was: a write that throws or comes up short is cut
   * back to the size before it, so no later line is written onto a fragment. If even that fails
   * the log is marked torn, and the next line starts with a newline that ends the fragment.
   */
  write(data) {
    const bytes = Buffer.from(data, 'utf8');
    const fd = fs.openSync(this.file, 'a', 0o600);
    try {
      const before = fs.fstatSync(fd).size;
      try {
        const written = this.io.writeSync(fd, bytes);
        if (written !== bytes.length) {
          throw new Error(`a short write (${written} of ${bytes.length} bytes)`);
        }
        fs.fsyncSync(fd);
      } catch (err) {
        try {
          // By PATH, not through `fd`: on Windows an append-mode handle is opened with append
          // access only (no FILE_WRITE_DATA), and cannot set the file's end — measured on the
          // windows-latest job, where an `ftruncateSync(fd)` here left the fragment in place.
          fs.truncateSync(this.file, before);
        } catch {
          this.torn = true;
        }
        throw err;
      }
    } finally {
      fs.closeSync(fd);
    }
  }

  /** The lines the bridge does not hold yet, given the `log_head` its manifest answered. */
  after(head) {
    if (!head || typeof head !== 'object' || head.install_id !== this.installId || !Number.isInteger(head.seq)) {
      return this.lines;
    }
    return this.lines.filter((l) => l.obj.seq > head.seq);
  }
}

/** Split lines into reports bounded by BOTH limits the bridge enforces, oldest first. */
export function reportBatches(lines) {
  const batches = [];
  let current = [];
  let bytes = 0;
  for (const line of lines) {
    const size = Buffer.byteLength(line.text, 'utf8');
    if (current.length > 0 && (current.length >= MAX_REPORT_ENTRIES || bytes + size > MAX_REPORT_BYTES)) {
      batches.push(current);
      current = [];
      bytes = 0;
    }
    current.push(line);
    bytes += size;
  }
  if (current.length > 0) {
    batches.push(current);
  }
  return batches;
}

// ---------------------------------------------------------------------------------------------
// The lock

/**
 * Take `<root>/.lock` by exclusive create. The lock records its holder's own DEADLINE — when a
 * launch's budget ends, or when a bootstrap gives up — and a later launch takes it over only once
 * that deadline has passed (plus a grace for the few writes a holder makes after its deadline:
 * dropping staging, one log line, the release). A lock whose record cannot be read (a holder killed
 * mid-create) falls back to its age against `staleAfterMs`. The takeover renames the lock aside and
 * re-reads what it moved: anything but the record it judged means another launch took it first, so
 * it is put back and this launch gives way.
 */
export const LOCK_GRACE_MS = 5000;

export function takeLock(root, { deadline, staleAfterMs, now = Date.now }) {
  const lock = path.join(root, '.lock');
  const token = `${process.pid}-${crypto.randomBytes(6).toString('hex')}`;
  const record = JSON.stringify({ token, pid: process.pid, deadline_ms: Number.isFinite(deadline) ? deadline : null });
  const create = () => {
    const fd = fs.openSync(lock, 'wx', 0o600);
    try {
      fs.writeSync(fd, record);
    } finally {
      fs.closeSync(fd);
    }
  };
  const held = (why) => new Failure(`lock held: another launch of this channel is updating it (${lock}${why})`);
  try {
    create();
    return { lock, token };
  } catch (err) {
    if (err.code !== 'EEXIST') {
      throw err;
    }
  }
  const judge = (file) => {
    let text;
    let mtime;
    try {
      text = fs.readFileSync(file, 'utf8');
      mtime = fs.statSync(file).mtimeMs;
    } catch {
      return { text: null, stale: true, why: '' };
    }
    let holderDeadline;
    try {
      holderDeadline = JSON.parse(text).deadline_ms;
    } catch {
      holderDeadline = undefined;
    }
    if (holderDeadline === null) {
      return { text, stale: false, why: ', held with no deadline' };
    }
    if (Number.isFinite(holderDeadline)) {
      return { text, stale: now() > holderDeadline + LOCK_GRACE_MS, why: `, its holder's deadline ${new Date(holderDeadline).toISOString()}` };
    }
    const age = now() - mtime;
    return { text, stale: age > staleAfterMs, why: `, ${Math.round(age / 1000)} s old, no readable deadline` };
  };
  const seen = judge(lock);
  if (!seen.stale) {
    throw held(seen.why);
  }
  const aside = `${lock}.stale-${token}`;
  try {
    fs.renameSync(lock, aside);
    if (fs.readFileSync(aside, 'utf8') !== seen.text) {
      fs.renameSync(aside, lock);
      throw held(', just taken over by another launch');
    }
    fs.rmSync(aside, { force: true });
  } catch (err) {
    if (err instanceof Failure) {
      throw err;
    }
    if (err.code !== 'ENOENT') {
      throw err;
    }
  }
  try {
    create();
  } catch (err) {
    if (err.code === 'EEXIST') {
      throw held(', just taken over by another launch');
    }
    throw err;
  }
  return { lock, token };
}

export function releaseLock(handle) {
  if (!handle) {
    return;
  }
  try {
    if (JSON.parse(fs.readFileSync(handle.lock, 'utf8')).token === handle.token) {
      fs.rmSync(handle.lock, { force: true });
    }
  } catch {
    // Already gone, or taken over as stale.
  }
}

// ---------------------------------------------------------------------------------------------
// The door

/** The client-update door beside a BRIDGE_TOOLS_ENDPOINT that ends in `/agent-tools/call`. */
export function clientDoorUrl(endpoint) {
  const door = deriveClientDoorUrl(endpoint);
  if (door.why) {
    throw new Failure(door.why);
  }
  return door.url;
}

function interpret(op, text, legOk, legWhy, serverSide) {
  let body;
  try {
    body = JSON.parse(text);
  } catch {
    const detail = legWhy();
    throw new Failure(text.trim() === '' && !legOk
      ? `bridge unreachable (${detail})`
      : `the bridge's answer to ${op} is not JSON (${detail}): ${scrubSnippet(text).slice(0, 200)}`);
  }
  if (!body || typeof body !== 'object' || Array.isArray(body)) {
    throw new Failure(`the bridge's answer to ${op} is not a JSON object (${legWhy()})`);
  }
  if (body.ok !== true || !legOk) {
    const Kind = body.ok === false && (op === 'client_manifest' || serverSide) ? ServerDeclined : Failure;
    throw new Kind(`the bridge answered ${op} with ${legWhy()}: ${typeof body.error === 'string' ? scrubSnippet(body.error) : 'no error text'}`);
  }
  return body;
}

/**
 * The update door on this seat's board-tools transport — the one the channel server already
 * uses, from the same environment (operator ruling 4, card#10567: the board-tools door is the
 * single update path).
 */
export function doorFromEnv(env) {
  // channel-lib's `boardToolsTransport` is the one statement of the transport rules; the refusals
  // below are this door's own words for its outcomes.
  const transport = boardToolsTransport(env);
  if (transport.kind === 'conflict') {
    throw new Failure('BRIDGE_TOOLS_SSH_TARGET and BRIDGE_TOOLS_ENDPOINT are both set; the update door is reached through exactly one board-tools transport');
  }
  if (transport.kind === 'invalid') {
    throw new Failure(`${transport.why}, so the update door cannot be asked`);
  }
  if (transport.kind === 'ssh') {
    const { target, key, port } = transport;
    return {
      source: `bridge-ssh:${target}`,
      async call(body, signal, deadlineMs) {
        const r = await sshRoundTrip({
          target,
          key,
          port,
          input: JSON.stringify(body),
          deadlineMs,
          signal,
        });
        if (r.failure) {
          throw new Failure(`bridge unreachable (${r.failure.message})`);
        }
        const how = r.code === null ? `ssh killed by ${r.killSignal}` : `ssh exit ${r.code}`;
        const stderr = r.stderrHead.trim();
        // Exit 2 is the ssh door's rendering of a server-side (>= 500) answer (DispatchOutcome::exitCodeFor),
        // and of its own three pre-dispatch refusals (agent config error, unknown agent, not a live
        // ssh agent) — which the certifying call through the same door would already have hit.
        return interpret(body.op, r.stdout, r.code === 0, () => scrubSnippet(stderr ? `${how}: ${stderr}` : how), r.code === 2);
      },
    };
  }
  if (transport.kind === 'http') {
    const url = clientDoorUrl(transport.url);
    const token = transport.token;
    // The source label lands in the install log, which is reported to the bridge: redacted, like
    // every printed endpoint (canon #20).
    return {
      source: `bridge-http:${redactUrl(url)}`,
      async call(body, signal) {
        let r;
        try {
          r = await httpRoundTrip({ url, token, body: JSON.stringify(body), signal });
        } catch (err) {
          throw new Failure(`bridge unreachable (${redactUrl(url)}: ${errorDetail(err)})`);
        }
        return interpret(body.op, r.text, r.ok, () => `HTTP ${r.status}`, r.status >= 500);
      },
    };
  }
  if (env.BRIDGE_TOOLS_ENDPOINT) {
    throw new Failure('BRIDGE_TOOLS_ENDPOINT is set but no bearer resolves (BRIDGE_TOOLS_TOKEN, BRIDGE_TOOLS_TOKEN_FILE or BRIDGE_CHANNEL_TOKEN), so the update door cannot be asked');
  }
  throw new Failure('no board-tools transport is configured (BRIDGE_TOOLS_SSH_TARGET or BRIDGE_TOOLS_ENDPOINT), so this seat cannot reach its bridge\'s update door');
}

/** One door call inside the budget: at most `legMs`, and never past the deadline or an abort. */
async function ask(ctx, body, legMs) {
  checkBudget(ctx, `asking the bridge for ${body.op}`);
  const ms = Math.max(1, Math.min(legMs, ctx.deadline - Date.now()));
  const leg = new AbortController();
  let timedOut = false;
  const timer = setTimeout(() => {
    timedOut = true;
    leg.abort();
  }, ms);
  const onAbort = () => leg.abort();
  ctx.signal.addEventListener('abort', onAbort, { once: true });
  try {
    return await ctx.door.call(body, leg.signal, ms);
  } catch (err) {
    if (ctx.signal.aborted) {
      throw new Aborted(`the update budget ran out while asking the bridge for ${body.op}`);
    }
    if (timedOut) {
      throw new Failure(`bridge unreachable (no answer to ${body.op} within ${ms} ms)`);
    }
    throw err;
  } finally {
    clearTimeout(timer);
    ctx.signal.removeEventListener('abort', onAbort);
  }
}

// ---------------------------------------------------------------------------------------------
// Verifying what arrived

/** The pack manifest (bin/build-client-pack.py's format, DL-428), read strictly. */
export function parseManifest(bytes) {
  let m;
  try {
    m = JSON.parse(Buffer.from(bytes).toString('utf8'));
  } catch {
    throw new Refusal('the client pack manifest is not JSON');
  }
  if (!m || typeof m !== 'object' || Array.isArray(m)) {
    throw new Refusal('the client pack manifest is not a JSON object');
  }
  if (m.schema !== SCHEMA || m.kind !== KIND) {
    throw new Refusal(`the client pack manifest is not schema ${SCHEMA} \`${KIND}\``);
  }
  const want = (value, pattern, what) => {
    if (typeof value !== 'string' || !pattern.test(value)) {
      throw new Refusal(`the client pack manifest's \`${what}\` is ${JSON.stringify(value)}, which this seat does not accept`);
    }
  };
  want(m.bridge_release, STRICT_RELEASE, 'bridge_release');
  want(m.client_version, STRICT_RELEASE, 'client_version');
  want(m.minted_from_commit, COMMIT, 'minted_from_commit');
  want(m.files_json_sha256, SHA256, 'files_json_sha256');
  if (typeof m.node_engines !== 'string' || m.node_engines.trim() === '') {
    throw new Refusal('the client pack manifest has no `node_engines` range');
  }
  const pack = m.pack;
  if (!pack || typeof pack !== 'object' || pack.file !== `client-pack-v${m.bridge_release}.tar.gz`) {
    throw new Refusal(`the client pack manifest does not name pack file client-pack-v${m.bridge_release}.tar.gz`);
  }
  want(pack.sha256, SHA256, 'pack.sha256');
  if (!Number.isInteger(pack.size) || pack.size < 1) {
    throw new Refusal('the client pack manifest\'s `pack.size` is not a positive integer');
  }
  return m;
}

/** The manifest the door sent, checked against the digests the same answer named. */
export function manifestFromAnswer(answer) {
  const p = answer.published;
  if (!p || typeof p !== 'object' || typeof p.manifest_b64 !== 'string' || typeof p.manifest_sha256 !== 'string') {
    throw new Failure('the bridge\'s client_manifest answer carries no `published` manifest');
  }
  const bytes = Buffer.from(p.manifest_b64, 'base64');
  if (sha256(bytes) !== p.manifest_sha256) {
    throw new Refusal('the manifest the bridge sent does not hash to the manifest_sha256 it named');
  }
  const m = parseManifest(bytes);
  if (m.bridge_release !== p.bridge_release || m.client_version !== p.client_version || m.files_json_sha256 !== p.files_json_sha256) {
    throw new Refusal('the manifest the bridge sent disagrees with the publication its answer names');
  }
  return { manifest: m, manifestSha256: p.manifest_sha256 };
}

/**
 * What a client_manifest answer offers this seat: `{offer, owed: null}`, or `{offer: null, owed}`
 * when its agent requires approval and the published content is not approved (DL-433). One reading
 * for the launch and the bootstrap, so the two can never disagree about what was offered.
 */
export function offerFromAnswer(answer, manifest) {
  const offer = answer.offer;
  if (offer === null || offer === undefined) {
    const owed = answer.approval && typeof answer.approval === 'object' ? answer.approval.owed : null;
    if (typeof owed !== 'string' || !STRICT_RELEASE.test(owed)) {
      throw new Failure('the bridge offered no release and named no approval owed');
    }
    return { offer: null, owed };
  }
  if (typeof offer !== 'string' || !STRICT_RELEASE.test(offer)) {
    throw new Refusal(`the bridge offered ${JSON.stringify(offer)}, not a bare X.Y.Z release`);
  }
  if (offer !== manifest.bridge_release) {
    throw new Refusal(`the bridge offered release ${offer} but publishes ${manifest.bridge_release}`);
  }
  return { offer, owed: null };
}

/** The offered release's pack bytes from the door, checked against the manifest's release and sha256. */
async function fetchPack(ctx, manifest, legMs) {
  const release = manifest.bridge_release;
  const pack = await ask(ctx, { op: 'client_pack', bridge_release: release }, legMs);
  if (pack.bridge_release !== release || pack.encoding !== 'base64' || typeof pack.data !== 'string') {
    throw new Refusal(`the bridge's client_pack answer is not release ${release}'s pack in base64`);
  }
  if (pack.sha256 !== manifest.pack.sha256) {
    throw new Refusal('the bridge\'s client_pack answer names a different sha256 than its manifest');
  }
  return Buffer.from(pack.data, 'base64');
}

function octal(header, offset, length, what, name) {
  const text = header.subarray(offset, offset + length).toString('latin1').replace(/[\0 ]+$/, '').replace(/^ +/, '');
  if (!/^[0-7]+$/.test(text)) {
    throw new Refusal(`the pack's tar header for ${name} has a malformed ${what}`);
  }
  return parseInt(text, 8);
}

function cString(header, offset, length) {
  const field = header.subarray(offset, offset + length);
  const end = field.indexOf(0);
  return field.subarray(0, end === -1 ? length : end).toString('utf8');
}

/**
 * A pack path, refused unless it is a plain relative path under `client/` or `seat-tools/bin/`
 * (or FILES.json itself): no absolute path, no `..` or `.` or empty part, no backslash, colon or
 * control character — each of which names a different file, or none, on some platform.
 */
export function checkPackPath(name) {
  if (name === FILES_JSON) {
    return;
  }
  // eslint-disable-next-line no-control-regex
  if (name === '' || name.startsWith('/') || /[\\:\u0000-\u001f]/.test(name)) {
    throw new Refusal(`the pack holds an entry at ${JSON.stringify(name)}, which is not a plain relative path`);
  }
  const parts = name.split('/');
  if (parts.some((part) => part === '' || part === '.' || part === '..')) {
    throw new Refusal(`the pack holds an entry at ${JSON.stringify(name)}, which leaves or re-enters its directory`);
  }
  if (!(parts[0] === 'client' && parts.length > 1) && !(parts[0] === 'seat-tools' && parts[1] === 'bin' && parts.length === 3)) {
    throw new Refusal(`the pack holds an entry at ${JSON.stringify(name)}, outside client/ and seat-tools/bin/`);
  }
}

/** A minimal ustar reader: regular files only, every header checksummed, every path checked. */
export function readUstar(tar) {
  const entries = [];
  const seen = new Set();
  const seenFolded = new Set();
  const foldCase = process.platform === 'win32' || process.platform === 'darwin';
  let offset = 0;
  while (offset + 512 <= tar.length) {
    const header = tar.subarray(offset, offset + 512);
    if (header.every((b) => b === 0)) {
      return entries;
    }
    let sum = 0;
    for (let i = 0; i < 512; i++) {
      sum += i >= 148 && i < 156 ? 0x20 : header[i];
    }
    const rawName = cString(header, 0, 100);
    if (octal(header, 148, 8, 'checksum', JSON.stringify(rawName)) !== sum) {
      throw new Refusal(`the pack's tar header for ${JSON.stringify(rawName)} fails its checksum`);
    }
    if (!header.subarray(257, 262).toString('latin1').startsWith('ustar')) {
      throw new Refusal('the pack is not a ustar archive');
    }
    const prefix = cString(header, 345, 155);
    const name = prefix ? `${prefix}/${rawName}` : rawName;
    const type = header[156];
    if (type !== 0x30 && type !== 0) {
      const kinds = { 0x31: 'hard link', 0x32: 'symbolic link', 0x35: 'directory' };
      throw new Refusal(`the pack holds ${kinds[type] ?? `a tar entry of type ${JSON.stringify(String.fromCharCode(type))}`} ${JSON.stringify(name)}; a pack holds regular files only`);
    }
    checkPackPath(name);
    if (seen.has(name) || (foldCase && seenFolded.has(name.toLowerCase()))) {
      throw new Refusal(`the pack holds ${JSON.stringify(name)} twice${foldCase ? ' (compared without case, as this filesystem does)' : ''}`);
    }
    seen.add(name);
    seenFolded.add(name.toLowerCase());
    const size = octal(header, 124, 12, 'size', JSON.stringify(name));
    const mode = octal(header, 100, 8, 'mode', JSON.stringify(name)) & 0o777;
    const start = offset + 512;
    if (start + size > tar.length) {
      throw new Refusal(`the pack ends inside ${JSON.stringify(name)}`);
    }
    entries.push({ path: name, mode, data: tar.subarray(start, start + size) });
    offset = start + Math.ceil(size / 512) * 512;
  }
  throw new Refusal('the pack ends without the tar end-of-archive block');
}

/** Every entry declared by FILES.json with its sha256, size and mode, and nothing undeclared. */
export function checkAgainstFilesJson(entries, manifest) {
  const listed = entries.find((e) => e.path === FILES_JSON);
  if (!listed) {
    throw new Refusal('the pack holds no FILES.json');
  }
  if (sha256(listed.data) !== manifest.files_json_sha256) {
    throw new Refusal('the pack\'s FILES.json does not hash to its manifest\'s files_json_sha256');
  }
  let listing;
  try {
    listing = JSON.parse(Buffer.from(listed.data).toString('utf8'));
  } catch {
    throw new Refusal('the pack\'s FILES.json is not JSON');
  }
  if (!Array.isArray(listing)) {
    throw new Refusal('the pack\'s FILES.json is not a list');
  }
  const declared = new Map();
  for (const item of listing) {
    if (!item || typeof item.path !== 'string' || typeof item.sha256 !== 'string' || !Number.isInteger(item.size) || typeof item.mode !== 'string' || !/^0[0-7]{3}$/.test(item.mode)) {
      throw new Refusal(`the pack's FILES.json has a malformed line ${JSON.stringify(item).slice(0, 200)}`);
    }
    if (declared.has(item.path)) {
      throw new Refusal(`the pack's FILES.json lists ${JSON.stringify(item.path)} twice`);
    }
    declared.set(item.path, item);
  }
  const files = entries.filter((e) => e.path !== FILES_JSON);
  for (const e of files) {
    const d = declared.get(e.path);
    if (!d) {
      throw new Refusal(`the pack holds ${JSON.stringify(e.path)}, which its FILES.json does not declare`);
    }
    if (d.size !== e.data.length || d.sha256 !== sha256(e.data)) {
      throw new Refusal(`the pack's ${JSON.stringify(e.path)} does not match its FILES.json line (size or sha256)`);
    }
    if (parseInt(d.mode, 8) !== e.mode) {
      throw new Refusal(`the pack's ${JSON.stringify(e.path)} has mode ${e.mode.toString(8)}, not the ${d.mode} its FILES.json declares`);
    }
    declared.delete(e.path);
  }
  if (declared.size > 0) {
    throw new Refusal(`the pack's FILES.json declares ${JSON.stringify([...declared.keys()][0])}, which the pack does not hold`);
  }
  for (const required of REQUIRED_ENTRIES) {
    if (!files.some((e) => e.path === required)) {
      throw new Refusal(`the pack holds no ${required}, so it cannot run or update itself`);
    }
  }
  let version;
  try {
    version = JSON.parse(Buffer.from(files.find((e) => e.path === 'client/package.json').data).toString('utf8')).version;
  } catch {
    version = undefined;
  }
  if (version !== manifest.client_version) {
    throw new Refusal(`the pack's client/package.json version is ${JSON.stringify(version)}, not the client_version ${manifest.client_version} its manifest names`);
  }
  return files;
}

/** `{files, filesJson}`: the pack's files and its FILES.json entry, after every check the pack itself can answer. */
export function readPack(packBytes, manifest) {
  if (packBytes.length !== manifest.pack.size) {
    throw new Refusal(`the pack is ${packBytes.length} bytes; its manifest says ${manifest.pack.size}`);
  }
  if (sha256(packBytes) !== manifest.pack.sha256) {
    throw new Refusal('the pack does not hash to its manifest\'s pack.sha256');
  }
  let tar;
  try {
    tar = zlib.gunzipSync(packBytes, { maxOutputLength: MAX_UNPACKED_BYTES });
  } catch (err) {
    throw new Refusal(`the pack does not decompress: ${err && err.message ? err.message : err}`);
  }
  const entries = readUstar(tar);
  return { files: checkAgainstFilesJson(entries, manifest), filesJson: entries.find((e) => e.path === FILES_JSON) };
}

/**
 * Whether `version` (process.versions.node) satisfies `range`. Only the forms whose meaning needs
 * no semver x-range rules are evaluated — `>=`/`<` with 1–3 parts, and `>`/`<=`/`=`/bare with all
 * three, space-joined, `||`-alternated. Anything else is REFUSED, never guessed.
 */
export function satisfiesEngines(range, version) {
  const have = versionTuplePadded(version);
  const alternatives = range.split('||').map((alt) => alt.trim());
  return alternatives.some((alt) => {
    const comparators = alt.split(/\s+/).filter(Boolean);
    if (comparators.length === 0) {
      throw new Refusal(`node_engines ${JSON.stringify(range)} has an empty alternative`);
    }
    return comparators.every((c) => {
      const m = /^(>=|<=|>|<|=)?v?([0-9]+)(?:\.([0-9]+))?(?:\.([0-9]+))?$/.exec(c);
      const full = m && m[3] !== undefined && m[4] !== undefined;
      if (!m || (!full && m[1] !== '>=' && m[1] !== '<')) {
        throw new Refusal(`node_engines ${JSON.stringify(range)} uses a form this updater does not evaluate (${c})`);
      }
      const want = [Number(m[2]), Number(m[3] ?? 0), Number(m[4] ?? 0)];
      const cmp = compareTuples(have, want);
      switch (m[1] ?? '=') {
        case '>=': return cmp >= 0;
        case '<=': return cmp <= 0;
        case '>': return cmp > 0;
        case '<': return cmp < 0;
        default: return cmp === 0;
      }
    });
  });
}

function versionTuplePadded(version) {
  const parts = String(version).split('.').map((p) => Number.parseInt(p, 10) || 0);
  return [parts[0] ?? 0, parts[1] ?? 0, parts[2] ?? 0];
}

function compareTuples(a, b) {
  for (let i = 0; i < 3; i++) {
    if (a[i] !== b[i]) {
      return a[i] < b[i] ? -1 : 1;
    }
  }
  return 0;
}

// ---------------------------------------------------------------------------------------------
// Installing

function stage(root, release, files, packSha, filesJson) {
  const staging = path.join(root, 'staging', `${release}.partial`);
  fs.rmSync(staging, { recursive: true, force: true });
  fs.mkdirSync(staging, { recursive: true });
  for (const f of files) {
    const dest = path.join(staging, ...f.path.split('/'));
    fs.mkdirSync(path.dirname(dest), { recursive: true });
    fs.writeFileSync(dest, f.data, { mode: f.mode });
    if (process.platform !== 'win32') {
      fs.chmodSync(dest, f.mode);
    }
  }
  // FILES.json stays with the release, and .verified records its digest beside the pack's, so
  // entry.mjs can tell an intact release from one a power cut left half-written.
  fs.writeFileSync(path.join(staging, 'FILES.json'), filesJson.data);
  fs.writeFileSync(path.join(staging, '.verified'), `${packSha}\n${sha256(filesJson.data)}\n`);
  return staging;
}

function sleepSync(ms) {
  Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, ms);
}

/**
 * Rename `from` to `to`. On Windows an antivirus or indexer holding a file open fails a directory
 * rename with EPERM/EACCES/EBUSY for a moment; that is retried with backoff until the deadline.
 * Synchronous on purpose: the step completes or fails whole, and the deadline timer cannot fire
 * inside it.
 */
export function renameWithRetry(from, to, { deadline, rename = fs.renameSync, platform = process.platform, sleep = sleepSync }) {
  let delay = 25;
  for (;;) {
    try {
      rename(from, to);
      return;
    } catch (err) {
      const transient = platform === 'win32' && ['EPERM', 'EACCES', 'EBUSY'].includes(err.code);
      if (!transient || Date.now() + delay >= deadline) {
        throw err;
      }
      sleep(delay);
      delay = Math.min(delay * 2, 1000);
    }
  }
}

function clientVersionOf(root, release) {
  try {
    const v = readJsonFile(path.join(root, 'versions', release, 'client', 'package.json')).version;
    return typeof v === 'string' && STRICT_RELEASE.test(v) ? v : null;
  } catch {
    return null;
  }
}

function logLine(ctx, fields) {
  return ctx.log.append({ actor: ctx.actor, launch_id: ctx.launchId ?? undefined, source: ctx.source ?? undefined, ...fields });
}

/** `{clientVersion, packSha, filesJsonSha}` — every field explicit, never re-derived by a fallback read. */
function writePointer(root, release, fields) {
  writeJsonAtomic(path.join(root, 'current.json'), {
    bridge_release: release,
    client_version: fields.clientVersion,
    installed_at: new Date().toISOString(),
    pack_sha256: fields.packSha,
    files_json_sha256: fields.filesJsonSha,
  });
}

/**
 * Move a verified, staged release into `versions/` and point `current.json` at it, logging the
 * install on the same step. Synchronous from end to end (see renameWithRetry).
 */
function commitInstall(ctx, { release, manifest, manifestSha256, staging, from, action }) {
  const { root } = ctx;
  const packSha = manifest.pack.sha256;
  const target = path.join(root, 'versions', release);
  checkBudget(ctx, `moving release ${release} into versions/`);
  crashAt(ctx, 'before-rename');
  fs.mkdirSync(path.join(root, 'versions'), { recursive: true });
  const exists = fs.existsSync(target);
  // The FULL scope (every file, not only REQUIRED_CLIENT_FILES) — this is the reuse check an
  // install/bootstrap pays for once, never a per-launch cost, so "repaired by a bootstrap"
  // (DL-434 Decision 9) is true of a file outside REQUIRED_CLIENT_FILES too. `bad` here — read
  // fault included — is always replaced, never left in place: only a CONFIRMED same-pack match is
  // reused, and only a confirmed DIFFERENT pack under the same release is refused (design review,
  // reversing the earlier never-touch-a-read-fault rule, which protected nothing reachable and
  // blocked the one repair that mattered).
  const c = exists ? classifyRelease(root, release, 'full') : null;
  if (exists && c.status === 'ok') {
    if (c.packSha !== packSha) {
      throw new Refusal(`versions/${release} already holds pack ${c.packSha}, not the verified pack ${packSha}; it is left as it is`);
    }
    // The same verified bytes are already there, intact: reuse them.
    fs.rmSync(staging, { recursive: true, force: true });
  } else {
    // A copy being replaced is RENAMED ASIDE, not removed, until the verified one is in place: it
    // may be the release the seat runs (not intact in full, still startable), and a rename into
    // `versions/` that fails must leave it where it was (card#10568 comment 7236). A kill BETWEEN
    // the two renames is not recovered from the aside copy — nothing reads it back, and the next
    // staging cleanup removes it — so the pointer names a missing release until a bootstrap (or a
    // launch with another intact release) installs what the bridge offers.
    let aside = null;
    if (exists) {
      const why = c.status === 'bad' ? c.message : `${release} is not a valid X.Y.Z`;
      ctx.say(`versions/${release} is not intact (${why}); replacing it with the verified pack`);
      aside = path.join(root, 'staging', `${release}.replaced`);
      fs.rmSync(aside, { recursive: true, force: true });
      renameWithRetry(target, aside, { deadline: ctx.deadline });
      crashAt(ctx, 'after-aside');
    }
    try {
      failAt(ctx, 'rename-in');
      renameWithRetry(staging, target, { deadline: ctx.deadline });
    } catch (err) {
      if (aside !== null) {
        try {
          // Retried like the rename in (a Windows EPERM/EBUSY is what most likely failed it), for
          // at most half the lock's grace, so the restore stays inside the lock this process holds.
          renameWithRetry(aside, target, { deadline: Date.now() + LOCK_GRACE_MS / 2 });
        } catch (restoreErr) {
          throw new Failure(`versions/${release} could not be replaced (${err && err.code ? err.code : err && err.message ? err.message : err}), and the previous copy could not be moved back from ${aside} (${restoreErr && restoreErr.code ? restoreErr.code : restoreErr && restoreErr.message ? restoreErr.message : restoreErr}); bootstrap again`);
        }
      }
      throw err;
    }
    fsyncDirectory(path.join(root, 'versions'));
    if (aside !== null) {
      try {
        fs.rmSync(aside, { recursive: true, force: true });
      } catch (err) {
        // The verified copy is in place; the old one is only clutter, and the staging cleanup
        // after the switch tries again.
        ctx.say(`the replaced copy of release ${release} could not be removed from ${aside} (${err && err.code ? err.code : err && err.message ? err.message : err}); left in place`);
      }
    }
  }
  crashAt(ctx, 'after-rename');
  checkBudget(ctx, `switching current.json to release ${release}`);
  writePointer(root, release, { clientVersion: manifest.client_version, packSha, filesJsonSha: manifest.files_json_sha256 });
  crashAt(ctx, 'after-pointer');
  logLine(ctx, {
    action,
    result: 'ok',
    from_bridge_release: from ?? undefined,
    to_bridge_release: release,
    client_version: manifest.client_version,
    pack_sha256: packSha,
    files_json_sha256: manifest.files_json_sha256,
    manifest_sha256: manifestSha256 ?? undefined,
  });
}

function writeFileAtomic(file, bytes, mode) {
  const temp = `${file}.tmp-${process.pid}-${crypto.randomBytes(4).toString('hex')}`;
  const fd = fs.openSync(temp, 'w', mode);
  try {
    fs.writeSync(fd, bytes);
    fs.fsyncSync(fd);
  } finally {
    fs.closeSync(fd);
  }
  if (process.platform !== 'win32') {
    fs.chmodSync(temp, mode);
  }
  fs.renameSync(temp, file);
  fsyncDirectory(path.dirname(file));
}

/**
 * The channel launcher a release carries (card#11328): `client/bin/<file>`, shimmed at the FIXED
 * path `<root>/bin/<name>` for this platform. The seat's `~/start-claude.sh` (or its Windows pair),
 * written by the provisioner, execs that shim, so every pack install moves the launcher with the
 * channel server it guards. `examples/channel-servers/README.md` § The seat's launcher declares it.
 */
export const LAUNCHER = {
  posix: { file: 'start-claude.sh', name: 'start-claude' },
  win32: { file: 'start-claude.ps1', name: 'start-claude.cmd' },
};

export function launcherFor(platform = process.platform) {
  return platform === 'win32' ? LAUNCHER.win32 : LAUNCHER.posix;
}

/** node -e program printing the release `current.json` names (exit 3 when it names none) — every shim's run-time resolve. */
const RELEASE_RESOLVER =
  'const c=JSON.parse(require("fs").readFileSync(process.argv[1],"utf8"));' +
  'if(!/^[0-9]{1,9}\\.[0-9]{1,9}\\.[0-9]{1,9}$/.test(c.bridge_release))process.exit(3);' +
  'process.stdout.write(c.bridge_release)';

function shellQuote(text) {
  return `'${String(text).replace(/'/g, `'\\''`)}'`;
}

/**
 * The shim for one program a release carries: resolve current.json at RUN time, then run that
 * release's copy. A `seat-tool` is `seat-tools/bin/<tool>`, run as itself (python on Windows); a
 * `client-bin` is `client/bin/<tool>`, a node program beside the client it imports from, run with
 * node and shimmed under its name without `.mjs` (bridge-board-call, card#11151 / DL-451); the
 * `launcher` is `client/bin/<LAUNCHER file>`, run with bash (powershell on Windows) under the fixed
 * name {@link launcherFor} gives, with AWB_LAUNCHER_CLIENT_ROOT set — its pack already carries the
 * channel server's node_modules, so the launcher installs nothing and reads no copied server dir
 * (card#11328).
 */
export function shimFor(root, tool, platform = process.platform, kind = 'seat-tool') {
  const win = platform === 'win32';
  let name;
  let run;
  if (kind === 'launcher') {
    name = launcherFor(platform).name;
    run = win
      ? `set "AWB_LAUNCHER_CLIENT_ROOT=${root}"\r\npowershell -NoProfile -ExecutionPolicy Bypass -File "${root}\\versions\\%AWB_RELEASE%\\client\\bin\\${tool}" %*\r\n`
      : `AWB_LAUNCHER_CLIENT_ROOT="$root"; export AWB_LAUNCHER_CLIENT_ROOT\nexec bash "$root/versions/$release/client/bin/${tool}" "$@"\n`;
  } else if (kind === 'client-bin') {
    const bare = tool.replace(/\.mjs$/, '');
    name = win ? `${bare}.cmd` : bare;
    run = win ? `node "${root}\\versions\\%AWB_RELEASE%\\client\\bin\\${tool}" %*\r\n` : `exec node "$root/versions/$release/client/bin/${tool}" "$@"\n`;
  } else {
    name = win ? `${tool}.cmd` : tool;
    run = win ? `python "${root}\\versions\\%AWB_RELEASE%\\seat-tools\\bin\\${tool}" %*\r\n` : `exec "$root/versions/$release/seat-tools/bin/${tool}" "$@"\n`;
  }
  if (win) {
    return {
      name,
      body:
        `@echo off\r\nrem ${SHIM_MARKER}\r\n` +
        `for /f "usebackq delims=" %%r in (\`node -e "${RELEASE_RESOLVER.replace(/"/g, '\\"')}" "${root}\\current.json"\`) do set "AWB_RELEASE=%%r"\r\n` +
        'if not defined AWB_RELEASE (echo the agent-webhook-bridge client root has no readable current.json 1>&2 & exit /b 2)\r\n' +
        run,
    };
  }
  return {
    name,
    body:
      `#!/bin/sh\n# ${SHIM_MARKER} — runs ${tool} from the release ${root}/current.json names.\n` +
      `root=${shellQuote(root)}\n` +
      `release=$(node -e ${shellQuote(RELEASE_RESOLVER)} "$root/current.json") || { echo "$root/current.json is unreadable or names no release; this seat's client needs re-bootstrapping" >&2; exit 2; }\n` +
      run,
  };
}

/**
 * Bring the root's release-independent files in line with the installed release — `entry.mjs`,
 * the shims (seat tools, client programs and the launcher), and the prune — each budget-checked, each logged when it changed anything.
 * Run after an install AND on a current launch, so a launch the budget cut off between the pointer
 * switch and these steps is completed by the next one.
 */
function settleRoot(ctx, release) {
  const { root } = ctx;
  const client = path.join(root, 'versions', release, 'client');

  checkBudget(ctx, 'replacing entry.mjs');
  const want = fs.readFileSync(path.join(client, 'entry.mjs'));
  let have = null;
  try {
    have = fs.readFileSync(path.join(root, 'entry.mjs'));
  } catch {
    have = null;
  }
  if (have === null || sha256(have) !== sha256(want)) {
    writeFileAtomic(path.join(root, 'entry.mjs'), want, 0o755);
    // Writing it into a root that has none is part of the bootstrap its own line records.
    if (have !== null) {
      logLine(ctx, { action: 'entry_replace', result: 'ok', to_bridge_release: release, reason: `entry.mjs replaced by release ${release}'s copy` });
    }
  }

  checkBudget(ctx, 'updating the shims');
  const bin = path.join(root, 'bin');
  fs.mkdirSync(bin, { recursive: true });
  // The tool list comes from the verified FILES.json listing, never a directory read (design
  // review rule 6), at the REQUIRED scope a launch already pays — the listing is checked against
  // `.verified` at that scope; the full scope is for commitInstall and importFailure only. When
  // the release does not classify `ok` (only if it was damaged after the launch's own resolve), no
  // shim is touched — untested, since a launch reaches here only with a release it just found `ok`.
  const classified = classifyRelease(root, release, 'required');
  if (classified.status !== 'ok') {
    ctx.say(`shims not updated: release ${release} is not intact (${classified.status === 'bad' ? classified.message : `${release} is not a valid X.Y.Z`})`);
  } else {
    const listed = (prefix, suffix = '') =>
      classified.listing
        .filter((l) => l && typeof l.path === 'string' && l.path.startsWith(prefix) && l.path.endsWith(suffix) && l.path.length > prefix.length + suffix.length && !l.path.slice(prefix.length).includes('/'))
        .map((l) => l.path.slice(prefix.length));
    const programs = [
      ...listed('seat-tools/bin/').map((tool) => [tool, 'seat-tool']),
      ...listed('client/bin/', '.mjs').map((tool) => [tool, 'client-bin']),
      ...listed('client/bin/').filter((file) => file === launcherFor().file).map((file) => [file, 'launcher']),
    ];
    const wanted = new Set();
    for (const [tool, kind] of programs) {
      const shim = shimFor(root, tool, process.platform, kind);
      wanted.add(shim.name);
      const file = path.join(bin, shim.name);
      let current = null;
      try {
        current = fs.readFileSync(file, 'utf8');
      } catch {
        current = null;
      }
      if (current !== shim.body) {
        writeFileAtomic(file, shim.body, 0o755);
      }
    }
    for (const name of fs.readdirSync(bin)) {
      if (!wanted.has(name)) {
        try {
          if (fs.readFileSync(path.join(bin, name), 'utf8').includes(SHIM_MARKER)) {
            fs.rmSync(path.join(bin, name), { force: true });
          }
        } catch {
          // Not ours, or already gone.
        }
      }
    }
  }

  checkBudget(ctx, 'pruning old releases');
  const kept = new Set([release]);
  let names = [];
  try {
    names = fs.readdirSync(path.join(root, 'versions'));
  } catch {
    names = [];
  }
  const older = names.filter((n) => n !== release && classifyRelease(root, n, 'required').status === 'ok' && compareReleases(n, release) < 0).sort(compareReleases);
  if (older.length > 0) {
    kept.add(older[older.length - 1]);
  }
  // A failed removal is LOGGED as `skipped` (the bridge's ledger does not read `skipped` as a failed
  // launch), never fatal to the update (design review rule 5): what could not be removed is left.
  const removed = [];
  const rmFailed = [];
  for (const name of names) {
    if (kept.has(name)) {
      continue;
    }
    try {
      fs.rmSync(path.join(root, 'versions', name), { recursive: true, force: true });
      removed.push(name);
    } catch (err) {
      rmFailed.push(`versions/${name} (${err && err.code ? err.code : err && err.message ? err.message : err})`);
    }
  }
  fs.rmSync(path.join(root, 'staging'), { recursive: true, force: true });
  if (removed.length > 0) {
    logLine(ctx, { action: 'prune', result: 'ok', to_bridge_release: release, reason: `removed ${removed.sort(compareReleases).join(', ')}; kept ${[...kept].sort(compareReleases).join(', ')}` });
  }
  if (rmFailed.length > 0) {
    ctx.say(`prune could not remove ${rmFailed.join(', ')}; left in place`);
    logLine(ctx, { action: 'prune', result: 'skipped', to_bridge_release: release, reason: `could not remove ${rmFailed.join(', ')}` });
  }
}

/**
 * The install line for a release the pointer names but the log never recorded: a launch killed
 * between the `current.json` switch and its log line (the one window the two are not one write).
 * Recorded at the next current launch, so the bridge's installed release catches up with the seat.
 */
function recordUnloggedInstall(ctx, release, manifest, manifestSha256) {
  const installs = ctx.log.lines.filter((l) => (l.obj.action === 'install' || l.obj.action === 'bootstrap') && l.obj.result === 'ok');
  const last = installs.length > 0 ? installs[installs.length - 1].obj : null;
  if (last && last.to_bridge_release === release && last.pack_sha256 === manifest.pack.sha256) {
    return;
  }
  checkBudget(ctx, `recording the install of release ${release}`);
  logLine(ctx, {
    action: 'install',
    result: 'ok',
    from_bridge_release: last ? last.to_bridge_release : undefined,
    to_bridge_release: release,
    client_version: manifest.client_version,
    pack_sha256: manifest.pack.sha256,
    files_json_sha256: manifest.files_json_sha256,
    manifest_sha256: manifestSha256,
    reason: `current.json names release ${release}, and no install of it was logged — the launch that switched to it was interrupted before its log line; recorded now`,
  });
}

/** Stage and commit a pack whose bytes are in hand. The caller has decided it may be installed. */
async function installPack(ctx, { manifest, manifestSha256, packBytes, from, action }) {
  // The verify-and-stage below is one synchronous stretch (the deadline timer cannot interrupt
  // it), so it is not begun once the budget has run out.
  checkBudget(ctx, `verifying and staging release ${manifest.bridge_release}`);
  const { files, filesJson } = readPack(packBytes, manifest);
  if (!satisfiesEngines(manifest.node_engines, process.versions.node)) {
    throw new Refusal(`release ${manifest.bridge_release} needs node ${manifest.node_engines}; this seat runs ${process.versions.node}`);
  }
  const staging = stage(ctx.root, manifest.bridge_release, files, manifest.pack.sha256, filesJson);
  crashAt(ctx, 'after-extract');
  if (ctx.env.AWB_CLIENT_CRASH_AT === 'budget-exceeded') {
    // A step that hangs past the budget and ignores the signal: what must stop it is the check
    // in front of the next irreversible step.
    await new Promise((resolve) => setTimeout(resolve, Math.max(0, ctx.deadline - Date.now()) + 1000));
  }
  commitInstall(ctx, { release: manifest.bridge_release, manifest, manifestSha256, staging, from, action });
}

/**
 * Refuse what the installed release rules out: a lower release, or the same one with other bytes.
 * `installedPackSha` is the caller's already-known pack sha of `installedRelease` (resolveInstalled
 * classified it `ok` to select it) — never re-derived here.
 */
function refuseAgainstInstalled(installedRelease, installedPackSha, manifest) {
  const cmp = compareReleases(manifest.bridge_release, installedRelease);
  if (cmp < 0) {
    throw new Refusal(`downgrade offered: release ${manifest.bridge_release} is below the installed ${installedRelease}`);
  }
  if (cmp === 0 && installedPackSha !== manifest.pack.sha256) {
    throw new Refusal(`same release, different bytes: release ${installedRelease} is installed as pack ${installedPackSha}, and this is pack ${manifest.pack.sha256} under the same release`);
  }
  return cmp;
}

function logFailure(ctx, err, known) {
  const refused = err instanceof Refusal;
  try {
    logLine(ctx, {
      action: refused ? 'refuse' : 'fail',
      result: refused ? 'refused' : 'failed',
      from_bridge_release: known.from ?? undefined,
      to_bridge_release: known.to ?? undefined,
      client_version: known.manifest?.client_version,
      pack_sha256: known.manifest?.pack?.sha256,
      files_json_sha256: known.manifest?.files_json_sha256,
      manifest_sha256: known.manifestSha256 ?? undefined,
      reason: err.message,
    });
  } catch (logErr) {
    ctx.say(`could not write the install log: ${logErr && logErr.message ? logErr.message : logErr}`);
  }
}

async function report(ctx, head) {
  const pending = ctx.log.after(head);
  if (pending.length === 0) {
    return null;
  }
  const deadline = Math.min(ctx.deadline, Date.now() + REPORT_LEG_MS);
  for (const batch of reportBatches(pending)) {
    if (ctx.signal.aborted) {
      return 'the update budget ran out before the install log was reported';
    }
    try {
      const answer = await ask(ctx, { op: 'client_report', install_id: ctx.log.installId, entries: batch.map((l) => l.text) }, Math.max(1, deadline - Date.now()));
      if (answer.discontinuity === true) {
        ctx.say('the bridge reports a break in this install\'s log (bridge:client-fleet names it)');
      }
    } catch (err) {
      return `the install log was not reported (${err.message}); it is sent again at the next launch`;
    }
  }
  return null;
}

/**
 * Repair current.json: point it at the release step 1 selected, whenever it names anything else
 * (design review rule 3, reversing the earlier "keepPointer" behavior, which exempted an
 * unconfirmable release). The shims resolve current.json at run time, so this is what
 * brings them back to the release the server runs. DELETES NOTHING — removing a release is `commitInstall`'s reuse check and `settleRoot`'s prune,
 * each on its own policy; this function only ever repoints. One `pointer_recovered` line says what
 * was found and what current.json names now.
 */
function recoverRoot(ctx, installed) {
  const { root } = ctx;
  checkBudget(ctx, 'repairing current.json');
  const named = installed.current && typeof installed.current === 'object' && typeof installed.current.bridge_release === 'string' ? installed.current.bridge_release : undefined;
  if (named === installed.release) {
    return;
  }
  // current.json's own read fault is in scope here (including a directory in its place —
  // EISDIR), the same as a release's: named by cause, never swallowed to one generic message.
  let cause;
  if (named !== undefined) {
    const badEntry = (installed.bad ?? []).find((b) => b.release === named);
    cause = badEntry ? `current.json named release ${named}, which is not intact (${badEntry.message})` : `current.json named release ${named}, which is not installed`;
  } else if (installed.currentCause === 'missing') {
    cause = 'current.json is missing';
  } else if (installed.currentCause === 'malformed') {
    cause = 'current.json is not valid JSON';
  } else if (installed.currentCause && installed.currentCause.startsWith('read-fault(')) {
    cause = `current.json could not be read (${installed.currentCause.slice('read-fault('.length, -1)})`;
  } else {
    cause = 'current.json did not name a valid release';
  }
  try {
    writePointer(root, installed.release, { clientVersion: clientVersionOf(root, installed.release), packSha: installed.packSha, filesJsonSha: installed.filesJsonSha });
  } catch (err) {
    throw new Failure(`current.json could not be repointed to release ${installed.release} (${err && err.code ? err.code : err && err.message ? err.message : err})`);
  }
  const reason = `${cause}; current.json now names ${installed.release}, the selected release`;
  logLine(ctx, { action: 'pointer_recovered', result: 'ok', to_bridge_release: installed.release, reason });
}

/**
 * What entry.mjs runs at a new launch. Resolves with this launch's outcome —
 * `{state, installed, published, offer, approval_owed, error, report_error}` where `state` is
 * `current`, `approval_owed` or `update_failed` — and does not reject for anything it anticipated.
 */
export async function runLaunchUpdate({ root, budgetMs, signal, launchId, installed, env = process.env }) {
  const channel = env.BRIDGE_CHANNEL_NAME || 'agent-webhook-bridge';
  const say = (text) => process.stderr.write(`[${channel}] client update: ${text}\n`);
  const ctx = { root, signal, deadline: Date.now() + budgetMs, launchId, actor: 'launch', env, source: null, door: null, log: null, say };
  const result = { state: null, installed: installed.release, published: null, offer: null, approval_owed: null, error: null, report_error: null };

  let lock;
  try {
    lock = takeLock(root, { deadline: ctx.deadline, staleAfterMs: 2 * budgetMs });
  } catch (err) {
    return { ...result, state: 'update_failed', error: err.message };
  }
  // Re-resolve under the lock (review r2 minor 1): `installed` is entry.mjs's step 1, read BEFORE
  // this lock, and another launch could have repaired — or further damaged — the tree in the gap.
  // Every delete and write below acts on this fresh read, never the stale argument.
  installed = resolveInstalled(root);
  result.installed = installed.release;
  const known = { from: installed.release, to: null, manifest: null, manifestSha256: null };
  let head;
  let reachable = false;
  try {
    try {
      ctx.log = InstallLog.open(root, say);
    } catch (err) {
      // Not the updater failing to run: its own record cannot be read, and nothing can be logged
      // or reported until it can.
      return { ...result, state: 'update_failed', log_unreadable: true, error: `the install log ${path.join(root, 'install-log.jsonl')} cannot be read (${err && err.code ? err.code : err && err.message ? err.message : err})` };
    }
    try {
      if (installed.release === null) {
        // Not a state entry.mjs's own pre-lock read can rule out (that is the race this re-resolve
        // closes) — every release this launch knew about was dropped or damaged between step 1 and
        // the lock.
        throw new Failure(`no verified client release is installed under ${path.join(root, 'versions')} any more`);
      }
      // recoverRoot no-ops when current.json already names the selected release: always safe to
      // call, and the single source of truth for whether a repoint is owed (design review rule 3).
      recoverRoot(ctx, installed);
      ctx.door = doorFromEnv(env);
      ctx.source = ctx.door.source;
      const answer = await ask(ctx, { op: 'client_manifest' }, MANIFEST_LEG_MS);
      reachable = true;
      head = answer.log_head ?? null;
      writeServedTools(ctx, answer);
      const { manifest, manifestSha256 } = manifestFromAnswer(answer);
      known.manifest = manifest;
      known.manifestSha256 = manifestSha256;
      result.published = manifest.bridge_release;

      const { offer, owed } = offerFromAnswer(answer, manifest);
      if (offer === null) {
        result.state = 'approval_owed';
        result.approval_owed = owed;
        // Once per owed content: the latest approval_owed line, wherever it sits in the log.
        const owedLines = ctx.log.lines.filter((l) => l.obj.action === 'approval_owed');
        const last = owedLines.length > 0 ? owedLines[owedLines.length - 1] : null;
        if (!(last && last.obj.to_bridge_release === owed && last.obj.files_json_sha256 === manifest.files_json_sha256)) {
          logLine(ctx, { action: 'approval_owed', result: 'skipped', from_bridge_release: installed.release, to_bridge_release: owed, client_version: manifest.client_version, files_json_sha256: manifest.files_json_sha256, reason: 'this seat requires approval, and the published release is not approved for it' });
        }
        settleRoot(ctx, installed.release);
      } else {
        result.offer = offer;
        known.to = offer;
        if (refuseAgainstInstalled(installed.release, installed.packSha, manifest) === 0) {
          result.state = 'current';
          recordUnloggedInstall(ctx, installed.release, manifest, manifestSha256);
          settleRoot(ctx, installed.release);
        } else {
          const packBytes = await fetchPack(ctx, manifest, ctx.deadline - Date.now());
          await installPack(ctx, { manifest, manifestSha256, packBytes, from: installed.release, action: 'install' });
          result.state = 'current';
          result.installed = offer;
          say(`installed release ${offer} (was ${installed.release})`);
          settleRoot(ctx, offer);
        }
      }
    } catch (err) {
      if (!(err instanceof Refusal) && !(err instanceof Failure) && !(err instanceof Aborted)) {
        // An unanticipated fault (a filesystem error): the same outcome, worded from the error.
        err = new Failure(`${err && err.message ? err.message : err}`);
      }
      if (err instanceof Aborted) {
        fs.rmSync(path.join(root, 'staging'), { recursive: true, force: true });
      }
      logFailure(ctx, err, known);
      result.state = 'update_failed';
      result.error = err instanceof Refusal ? `refused: ${err.message}` : err.message;
    }
    if (reachable) {
      // report() stops at an abort itself: it starts no call once the signal is set.
      result.report_error = await report(ctx, head);
      if (result.report_error) {
        say(result.report_error);
      }
    }
    return result;
  } finally {
    releaseLock(lock);
  }
}

/**
 * `<root>/served-tools.json` from a client_manifest answer that carries `served_tools` (card#11283):
 * the cache the channel server this launch starts reads instead of asking the bridge again. An
 * answer without the key (a bridge that predates it) writes nothing and leaves an earlier cache
 * where it is. Fail-soft: a cache that cannot be written costs one served_tools call, never the
 * update.
 */
function writeServedTools(ctx, answer) {
  const served = answer.served_tools;
  if (!Array.isArray(served) || !served.every((name) => typeof name === 'string')) {
    return;
  }
  try {
    writeJsonAtomic(path.join(ctx.root, SERVED_TOOLS_FILE), {
      launch_id: ctx.launchId,
      agent: typeof answer.agent === 'string' ? answer.agent : null,
      served,
      written_at: new Date().toISOString(),
    });
  } catch (err) {
    ctx.say(`could not write ${SERVED_TOOLS_FILE} (${err && err.code ? err.code : err && err.message ? err.message : err}); the channel server asks the bridge instead`);
  }
}

/**
 * The bootstrap (design §3.5): install one pack into `root` outside any launch — under the lock,
 * logged `bootstrap` with actor `provision`, with the same checks and the same commit as a launch.
 * Re-running it with the installed release repairs the root (pointer, entry.mjs, shims) and logs
 * that too. `obtain` supplies the manifest, then the pack; it is where the two ways in differ.
 * Throws a Refusal or Failure, each logged.
 */
async function bootstrapRoot({ root, source, env, obtain, budgetMs = INSTALL_LOCK_MS }) {
  const say = (text) => process.stderr.write(`client-update bootstrap: ${text}\n`);
  fs.mkdirSync(root, { recursive: true });
  // The lock's deadline IS the budget, as at a launch: every irreversible step checks it, so a
  // bootstrap never writes after a launch could take the lock over as stale.
  const deadline = Date.now() + budgetMs;
  const ctx = { root, signal: new AbortController().signal, deadline, launchId: null, actor: 'provision', env, source, door: null, log: null, say };
  const lock = takeLock(root, { deadline, staleAfterMs: budgetMs });
  const known = { from: null, to: null, manifest: null, manifestSha256: null };
  try {
    ctx.log = InstallLog.open(root, say);
    try {
      const { manifest, manifestSha256 } = await obtain.manifest(ctx, known);
      known.manifest = manifest;
      known.manifestSha256 = manifestSha256;
      known.to = manifest.bridge_release;
      const resolved = resolveInstalled(root);
      const installed = resolved.release === null ? null : resolved;
      known.from = installed ? installed.release : null;
      if (installed) {
        refuseAgainstInstalled(installed.release, installed.packSha, manifest);
      }
      const packBytes = await obtain.pack(ctx, manifest);
      await installPack(ctx, { manifest, manifestSha256, packBytes, from: known.from, action: 'bootstrap' });
      settleRoot(ctx, manifest.bridge_release);
      return { release: manifest.bridge_release, installId: ctx.log.installId };
    } catch (err) {
      if (err instanceof Aborted) {
        // Nothing is aside at an abort: every check sits outside the rename-aside and rename-in pair.
        fs.rmSync(path.join(root, 'staging'), { recursive: true, force: true });
      }
      if (!(err instanceof Refusal) && !(err instanceof Failure)) {
        err = new Failure(`${err && err.message ? err.message : err}`);
      }
      logFailure(ctx, err, known);
      throw err;
    }
  } finally {
    releaseLock(lock);
  }
}

/** Bootstrap from a pack and manifest already on disk. */
export async function installFromFiles({ packFile, manifestFile, root, source = 'provision', env = process.env, budgetMs }) {
  return bootstrapRoot({
    root,
    source,
    env,
    budgetMs,
    obtain: {
      async manifest() {
        const manifestBytes = fs.readFileSync(manifestFile);
        return { manifest: parseManifest(manifestBytes), manifestSha256: sha256(manifestBytes) };
      },
      async pack() {
        return fs.readFileSync(packFile);
      },
    },
  });
}

/** How long a bootstrap waits for the bridge's client_manifest answer (the pack gets what is left of the budget). */
export const BOOTSTRAP_MANIFEST_MS = 30000;

/**
 * Bootstrap from this seat's own bridge, over the board-tools transport in `env` — the one a
 * launch uses (`doorFromEnv`), so a bootstrap proves the door the next launch will ask. It installs
 * only what the bridge OFFERS (DL-433 Decision 2): with approval owed nothing is fetched, and the
 * refusal names the release and the command that clears it.
 */
export async function installFromDoor({ root, env = process.env, budgetMs, agent = null }) {
  return bootstrapRoot({
    root,
    source: null,
    env,
    budgetMs,
    obtain: {
      async manifest(ctx, known) {
        ctx.door = doorFromEnv(env);
        ctx.source = ctx.door.source;
        const answer = await ask(ctx, { op: 'client_manifest' }, BOOTSTRAP_MANIFEST_MS);
        const { manifest, manifestSha256 } = manifestFromAnswer(answer);
        const { offer, owed } = offerFromAnswer(answer, manifest);
        if (offer === null) {
          known.manifest = manifest;
          known.manifestSha256 = manifestSha256;
          known.to = owed;
          const err = new Refusal(`approval owed: this seat's agent requires approval, and release ${owed} is not approved for it — on the bridge, \`php artisan bridge:client-approve ${agent ?? '<agent>'} ${owed} --reason=…\`, then bootstrap again; nothing was fetched or installed`);
          err.nothingOffered = true;
          throw err;
        }
        return { manifest, manifestSha256 };
      },
      async pack(ctx, manifest) {
        return fetchPack(ctx, manifest, ctx.deadline - Date.now());
      },
    },
  });
}

function usage() {
  process.stderr.write(
    'usage: client-update.mjs install --pack <file> --manifest <file> --root <dir> [--actor provision] [--source <text>]\n' +
      '       client-update.mjs bootstrap --root <dir> [--agent <name>]   (the board-tools transport is read from the environment;\n' +
      '       exit 0 installed, 1 refused or failed, 2 usage, 3 the bridge answered and offers nothing to install right now)\n',
  );
  return 2;
}

function parseOptions(argv, allowed) {
  const opts = {};
  for (let i = 1; i < argv.length; i += 2) {
    const key = argv[i];
    const value = argv[i + 1];
    if (!allowed.includes(key) || value === undefined) {
      return null;
    }
    opts[key.slice(2)] = value;
  }
  return opts;
}

export async function cli(argv, env = process.env) {
  let run;
  let root;
  if (argv[0] === 'install') {
    const opts = parseOptions(argv, ['--pack', '--manifest', '--root', '--source', '--actor']);
    // `--actor provision` is what the design's bootstrap passes (§3.5 step 4). A bootstrap's lines
    // are the provisioner's whether or not it says so; `launch` is entry.mjs's and needs a launch id.
    if (!opts || !opts.pack || !opts.manifest || !opts.root || (opts.actor !== undefined && opts.actor !== 'provision')) {
      return usage();
    }
    root = path.resolve(opts.root);
    run = () => installFromFiles({ packFile: opts.pack, manifestFile: opts.manifest, root, source: opts.source });
  } else if (argv[0] === 'bootstrap') {
    const opts = parseOptions(argv, ['--root', '--agent']);
    // `--agent` only names the agent in the approval command a refusal prints; the door already
    // knows who is asking, from the transport's own credential.
    if (!opts || !opts.root || (opts.agent !== undefined && !AGENT_NAME.test(opts.agent))) {
      return usage();
    }
    root = path.resolve(opts.root);
    run = () => installFromDoor({ root, env, agent: opts.agent ?? null });
  } else {
    return usage();
  }
  const verb = argv[0];
  try {
    const done = await run();
    process.stdout.write(`client-update ${verb}: release ${done.release} installed at ${root} (install ${done.installId})\n`);
    return 0;
  } catch (err) {
    process.stderr.write(`client-update ${verb}: ${err instanceof Refusal ? 'refused' : 'failed'}: ${err.message}\n`);
    // Only the bootstrap tells "nothing offered right now" apart: it is what lets an onboarding
    // entry point keep the seat's legacy channel server instead of failing (DL-445).
    return verb === 'bootstrap' && (err instanceof ServerDeclined || err.nothingOffered === true) ? EXIT_NOTHING_OFFERED : 1;
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
  process.exitCode = await cli(process.argv.slice(2));
}

