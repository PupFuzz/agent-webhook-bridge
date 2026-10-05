// Helpers for the reference channel MCP server (agent-webhook-bridge-channel.mjs), its
// launch-time updater (client-update.mjs) and the seat CLI (bin/bridge-board-call.mjs).
//
// Two kinds of export, and one rule for both: every input is an ARGUMENT. Nothing here reads
// process.env, closes over a startup constant, or calls process.exit — so each is testable
// directly, and the programs share ONE implementation of each (canon #5). The one exception is
// `readClientVersion`, which reads this directory's own package.json — the manifest every
// copy of this file travels with:
//   - PURE helpers (scrubSnippet, relayBridgeResponse, deriveMeta, launchIdentity,
//     clientUpdateInstruction, resolveToolsToken's precedence) — no I/O beyond what an
//     argument names;
//   - the bridge TRANSPORT primitives (sshRoundTrip, httpRoundTrip, and boardToolsTransport, which
//     chooses between them) — they do I/O (a child
//     process, a fetch), but only to the target their caller passes, and they return what
//     happened rather than deciding what it means. The board-tools proxy relays that as a
//     tool result; the updater reads it as a client-update door answer; bridge-board-call
//     maps it to its exit-code contract.
// The main server self-executes on import (it binds a real transport and calls process.exit
// on refuse paths), so importing IT to reach these is not an option.
//
// Consumers copy the WHOLE examples/channel-servers/ directory (see README), and the client
// pack carries it whole, so this file travels with the entry point; it is imported with a
// relative `./channel-lib.mjs` specifier — no build step, plain ESM.

import fs from 'node:fs';
import { spawn } from 'node:child_process';
import { STRICT_RELEASE, LAUNCH_ID } from './entry.mjs';

// Strip obvious credential substrings from a raw-body snippet before it is relayed
// into a tool result (a PHP trace could echo an Authorization/Bearer line).
export function scrubSnippet(body) {
  return String(body)
    .replace(/(authorization|bearer)[^\n]*/gi, '[redacted]')
    .slice(0, 500);
}

// The ONE relay contract for BOTH transports (DR2-2, canon #5 — fixed at the second
// caller). Accumulate the FULL body (DR2-9), then JSON.parse-or-isError. The success
// signal is LEG-SUPPLIED (res.ok / clean ssh exit), NEVER inferred from the body — a
// 200 (or exit 0) with a php-warning-prepended body is a CORRUPT result, so it is
// isError:true, not a silently-broken isError:false. On parse failure a truncated,
// credential-scrubbed snippet keeps a non-JSON 502 page diagnosable.
//
// `legDiagnostic` (card#7709) is the leg's own account of WHY it failed — the ssh exit
// code plus its stderr — and it is what makes the leg signal reachable on the
// parse-failure branch, which until now returned the snippet before ever reading
// `legSuccess`. That collapsed two different failures into one string, and for the
// case that actually happens (transport down: non-zero exit, empty stdout) the string
// was `non-JSON response from the bridge (ssh <target>):` with nothing after the colon,
// because `scrubSnippet('')` is `''`. The agent holding the failure could not name it;
// measured cost at one install was a board tool dead for 10 days.
//
// The two states stay SEPARATE, deliberately:
//   - failed leg WITH a diagnostic  ⇒ name the diagnostic (+ any partial output).
//   - clean leg, or a failed leg with no diagnostic to give (an HTTP non-ok that DID
//     return a body) ⇒ the original snippet message, unchanged. There the body IS the
//     diagnosis, so folding a transport note into it would only re-mint this defect
//     pointing the other way.
// Both arms scrub: a leg diagnostic is unvetted operator-facing text, same as a body.
export function relayBridgeResponse(rawBody, legSuccess, sourceLabel, legDiagnostic = '') {
  try {
    JSON.parse(rawBody);
  } catch {
    const transportFailed = !legSuccess && Boolean(legDiagnostic);
    // A leg that died before writing anything usually leaves an empty or
    // whitespace-only body; appending it as "partial output" would be noise.
    const partial = String(rawBody).trim();
    const text = transportFailed
      ? `the ${sourceLabel} leg FAILED: ${scrubSnippet(legDiagnostic)}` +
        (partial ? ` | partial output: ${scrubSnippet(partial)}` : '')
      : `non-JSON response from the bridge (${sourceLabel}): ${scrubSnippet(rawBody)}`;
    return { isError: true, content: [{ type: 'text', text }] };
  }
  return {
    isError: !legSuccess,
    content: [{ type: 'text', text: rawBody }],
  };
}

// Derive the channel `meta` keys from a raw request body. Best-effort JSON parse:
// a non-JSON body (or a body without an object `intent`) yields an empty meta,
// never a throw — the caller always gets a usable object.
//
// The `target_id` ATTRIBUTE is filled from the intent's `subject_id` FIELD (DL-388): that
// is the field the bridge's Intent::toArray() carries. The attribute keeps its name because
// every running session was already told to read `target_id`. An `intent.target_id` is not
// read — no producer sends one.
export function deriveMeta(body) {
  const meta = {};
  try {
    const parsed = JSON.parse(body);
    const intent = parsed && typeof parsed === 'object' ? parsed.intent : null;
    if (intent && typeof intent === 'object') {
      if (typeof intent.kind === 'string') {
        meta.kind = intent.kind;
      }
      if (typeof intent.subject_id === 'string') {
        meta.target_id = intent.subject_id;
      }
    }
  } catch {
    // body is not JSON; meta stays empty
  }
  return meta;
}

// Bearer precedence (pinned): explicit BRIDGE_TOOLS_TOKEN (non-empty), else the
// explicit BRIDGE_TOOLS_TOKEN_FILE (non-empty path) — and a configured-but-unreadable
// FILE SHORT-CIRCUITS to '' (never silently falling through to the channel token),
// else the BRIDGE_CHANNEL_TOKEN fallback. An empty-string env var does not
// "configure" a source (it is treated as unset for this chain). `env` is the process
// environment the caller reads; the board-tools proxy and the updater pass the same one.
export function resolveToolsToken(env) {
  if (env.BRIDGE_TOOLS_TOKEN) {
    return env.BRIDGE_TOOLS_TOKEN;
  }
  const file = env.BRIDGE_TOOLS_TOKEN_FILE;
  if (file) {
    try {
      return fs.readFileSync(file, 'utf8').trim();
    } catch {
      return '';   // configured-but-unreadable file short-circuits; no fallthrough
    }
  }
  if (env.BRIDGE_CHANNEL_TOKEN) {
    return env.BRIDGE_CHANNEL_TOKEN;
  }
  return '';
}

// This client's OWN package version, sent on every board-tools call as `client_version`
// (card#8974 / DL-364) by the channel server and by bridge-board-call. WHY: `bridge:check`
// could see the version of the snapshot the BRIDGE bundles and nothing whatever about the copy
// the seat actually runs, so a tool missing from a stale seat copy was attributed to the bridge.
// Measured: a seat on 0.4.4 against a bridge bundling 0.9.12 reported `board_correct_card`
// "absent from my surface", and nothing compared the two numbers because nothing carried the
// first one.
//
// READ FROM THE SIBLING MANIFEST, never written as a literal. Consumers copy the WHOLE
// directory, so `package.json` travels with this file — and a literal would be a second copy
// of the one field the DL-038 bump guard already maintains, free to drift the moment somebody
// bumps one and not the other.
//
// ⛔ FAIL-SOFT AND OPTIONAL, AT BOTH ENDS. An unreadable, absent or malformed manifest yields
// null, the key is then OMITTED, and the call goes out exactly as it did before this field
// existed. The bridge reads a missing key as "not reported" and MUST NOT refuse a call over
// it — adding this field changed nothing about what the door accepts.
export function readClientVersion() {
  try {
    const version = JSON.parse(fs.readFileSync(new URL('./package.json', import.meta.url), 'utf8')).version;

    return typeof version === 'string' && version !== '' ? version : null;
  } catch {
    return null;
  }
}

// One ssh round trip to the bridge's forced command: spawn `ssh [-i key] [-p port] <target>`
// with NO command (sshd substitutes the pinned bridge:tools-call), write `input` to its stdin,
// and CAPTURE (never inherit) its stdout — the channel server's OWN stdout is the MCP JSON-RPC
// frame channel, so nothing from the child may reach it.
//
// Resolves, never rejects, with exactly one of:
//   { failure: {kind: 'spawn'|'error'|'deadline'|'aborted', message} }
//   { code, killSignal, stdout, stderrHead }        the child closed
// `stdout` is decoded ONCE from the whole byte stream: decoding per chunk splits a multi-byte
// UTF-8 character that straddles a pipe read (a non-ASCII card title at a 64 KiB boundary came
// out as U+FFFD). `stderrHead` is the HEAD of stderr, bounded by `stderrLimit`: a tail slice can
// cut an `Authorization:` line in half and leave the token past the anchor `scrubSnippet`
// redacts from, and ssh states its diagnosis first. `onStderr(text)` sees the raw stream, for a
// diagnostics log only — it is never part of a result.
//
// `deadlineMs` bounds the WHOLE call (`ConnectTimeout` bounds only the connect, so a host that
// connects then hangs would otherwise pin the call and leak the child); `signal` aborts it from
// outside. Either kills the child.
export function sshRoundTrip({ target, key = '', port = '', input, deadlineMs, signal, stderrLimit = 2000, onStderr = () => {} }) {
  const args = ['-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10'];
  if (key) {
    args.push('-i', key);
  }
  if (port) {
    args.push('-p', String(port));
  }
  args.push(target);

  return new Promise((resolve) => {
    let settled = false;
    const settle = (value) => {
      if (!settled) {
        settled = true;
        resolve(value);
      }
    };
    if (signal?.aborted) {
      settle({ failure: { kind: 'aborted', message: `ssh to ${target} was not started: the caller gave up first` } });
      return;
    }
    let child;
    try {
      child = spawn('ssh', args, { stdio: ['pipe', 'pipe', 'pipe'] });
    } catch (err) {
      settle({ failure: { kind: 'spawn', message: `could not spawn ssh to ${target}: ${err && err.message ? err.message : err}` } });
      return;
    }
    const kill = () => {
      try {
        child.kill('SIGKILL');
      } catch {
        // already gone
      }
    };
    const deadline = setTimeout(() => {
      kill();
      settle({ failure: { kind: 'deadline', message: `ssh to ${target} exceeded the ${deadlineMs}ms deadline` } });
    }, deadlineMs);
    const onAbort = () => {
      kill();
      settle({ failure: { kind: 'aborted', message: `ssh to ${target} was stopped: the caller gave up` } });
    };
    signal?.addEventListener('abort', onAbort, { once: true });
    const done = () => {
      clearTimeout(deadline);
      signal?.removeEventListener('abort', onAbort);
    };
    const out = [];
    let stderrHead = '';
    child.stdout.on('data', (chunk) => {
      out.push(chunk);
    });
    child.stderr.on('data', (chunk) => {
      const text = chunk.toString();
      if (stderrHead.length < stderrLimit) {
        stderrHead = (stderrHead + text).slice(0, stderrLimit);
      }
      onStderr(text);
    });
    child.on('error', (err) => {
      done();
      settle({ failure: { kind: 'error', message: `ssh to ${target} failed: ${err && err.message ? err.message : err}` } });
    });
    child.on('close', (code, killSignal) => {
      done();
      settle({ code, killSignal, stdout: Buffer.concat(out).toString('utf8'), stderrHead });
    });
    // A child that dies before reading its stdin makes this write EPIPE; the close above is
    // what reports it, so the write error itself carries nothing to add.
    child.stdin.on('error', () => {});
    child.stdin.write(input);
    child.stdin.end();
  });
}

// `err.message`, with `err.cause`'s own message appended when present. `fetch` with
// `redirect: 'error'` rejects a redirect with a generic `TypeError: fetch failed` — the actual
// reason ("unexpected redirect") is on `.cause`, one level down, and is lost if a caller reads
// only `.message` (review r2 minor 5).
//
// It quotes the error as raised and does no URL surgery: the endpoint a caller hands `fetch` has
// already been parsed and refused when it carries a credential (`boardToolsTransport`), so
// `fetch`'s own messages that quote a URL — "Failed to parse URL from …", "…includes
// credentials: …" — cannot occur for it. A caller printing an endpoint itself uses `redactUrl`.
export function errorDetail(err) {
  const message = err && err.message ? err.message : String(err);
  const cause = err && err.cause && err.cause.message ? err.cause.message : null;
  return cause ? `${message}: ${cause}` : message;
}

// The ONE way an endpoint reaches an output stream (canon #20): origin + path, parsed by the
// WHATWG parser `fetch` itself uses — never userinfo, query or fragment, any of which can carry a
// credential — and a fixed placeholder for a value that does not parse, never the value.
export const UNPARSEABLE_ENDPOINT = '<unparseable endpoint>';

export function redactUrl(raw) {
  try {
    const u = new URL(raw);
    return `${u.origin}${u.pathname}`;
  } catch {
    return UNPARSEABLE_ENDPOINT;
  }
}

// One HTTP POST to a bridge door with the agent's bearer. Resolves {status, ok, text}; REJECTS
// when no response arrived (connection refused, DNS, an abort through `signal`) and when the
// answer is a REDIRECT — a bearer call is never re-sent elsewhere (DL-217: the doors are
// loopback, and a redirect off them would carry the bearer to wherever it points). The caller
// words that failure with {@see errorDetail} — the two callers say different things around it.
//
// A rejection AFTER the status arrived (the body cut off, a reset mid-body) carries that status as
// `err.status`: the far end answered, so the request reached it — which a caller deciding whether
// a write may have landed must be able to tell from a call that never got that far. The error
// itself, and so its message, is the one the body read raised.
export async function httpRoundTrip({ url, token, body, signal }) {
  const res = await fetch(url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Authorization: `Bearer ${token}`,
    },
    body,
    signal,
    redirect: 'error',
  });
  let text;
  try {
    text = await res.text();
  } catch (err) {
    if (err && typeof err === 'object') {
      err.status = res.status;
    }
    throw err;
  }
  return { status: res.status, ok: res.ok, text };
}

// A seat's board-tools transport, from the environment its channel server runs with — the ONE
// statement of the rules, read by the channel server and by bin/bridge-board-call.mjs:
//   { kind: 'ssh', target, key, port }   BRIDGE_TOOLS_SSH_TARGET (bearer-free, DR2-5)
//   { kind: 'http', url, token }         BRIDGE_TOOLS_ENDPOINT, a URL with no credential in it, and
//                                        a bearer `resolveToolsToken` finds
//   { kind: 'invalid', why }             an endpoint and a bearer, but an endpoint `fetch` would
//                                        refuse: it does not parse, or it carries userinfo. `why`
//                                        never quotes the value, which may hold a credential.
//   { kind: 'conflict' }                 both set: a seat has exactly one transport
//   { kind: 'incomplete', missing }      neither usable; `missing` names the settings to set
// Pure but for `resolveToolsToken`'s read of a configured token FILE, so a rotated file is read at
// each call.
export function boardToolsTransport(env) {
  const target = env.BRIDGE_TOOLS_SSH_TARGET || '';
  const endpoint = env.BRIDGE_TOOLS_ENDPOINT || '';
  if (target && endpoint) {
    return { kind: 'conflict' };
  }
  if (target) {
    return { kind: 'ssh', target, key: env.BRIDGE_TOOLS_SSH_KEY || '', port: env.BRIDGE_TOOLS_SSH_PORT || '' };
  }
  const token = resolveToolsToken(env);
  if (endpoint && token) {
    let url;
    try {
      url = new URL(endpoint);
    } catch {
      return { kind: 'invalid', why: 'BRIDGE_TOOLS_ENDPOINT is not a URL that parses (its value is not shown: it may carry a credential)' };
    }
    if (url.username || url.password) {
      return { kind: 'invalid', why: `BRIDGE_TOOLS_ENDPOINT carries a credential in its userinfo, which fetch refuses to send; the bearer belongs in BRIDGE_TOOLS_TOKEN or BRIDGE_TOOLS_TOKEN_FILE (endpoint: ${redactUrl(endpoint)})` };
    }
    return { kind: 'http', url: endpoint, token };
  }
  return {
    kind: 'incomplete',
    missing: [endpoint ? null : 'BRIDGE_TOOLS_ENDPOINT', token ? null : 'BRIDGE_TOOLS_TOKEN (or BRIDGE_TOOLS_TOKEN_FILE)'].filter(Boolean),
  };
}

// The `launch` object a board-tools call carries (card#10568; the bridge reads it through
// CallerReport, DL-432 Decision 2): which launch this server belongs to and which bridge release
// that launch runs. entry.mjs sets both in the environment before it imports the server; a
// server started any other way has neither, and the key is then OMITTED — a call without it is
// what every client before the updater sends, and "no launch identity" is exactly what the
// bridge must be told for such a seat (it reads it as off the update path). A value outside the
// bridge's whitelist would be dropped there whole, so it is not sent at all.
export function launchIdentity(env) {
  const id = env.AWB_LAUNCH_ID;
  const release = env.AWB_BRIDGE_RELEASE;
  if (typeof id !== 'string' || !LAUNCH_ID.test(id) || typeof release !== 'string' || !STRICT_RELEASE.test(release)) {
    return null;
  }
  return { id, bridge_release: release };
}

// The one INSTRUCTIONS line that says what this launch's client update did, or null when there
// is nothing to say (design §3.4). `state` is <root>/state.json as entry.mjs wrote it for THIS
// launch, or `{unreadable: <why>}`; `launchId` is this launch's id. Fixed at session start: the
// server reads it once, and nothing changes mid-session.
//
// A state.json naming another launch is not this launch's answer (entry.mjs failed to write it,
// and the file is the previous launch's), so it is reported as unknown rather than believed —
// "reported current, actually isn't" is the failure this line exists to prevent.
export function clientUpdateInstruction(state, { launchId, root }) {
  const base = updateStateLine(state, { launchId, root });
  if (state && typeof state.recovered === 'string' && state.launch_id === launchId) {
    const damage = `CLIENT RELEASE DAMAGED ON THIS SEAT: ${state.recovered}. Tell your operator; a later update either replaces it with a verified copy or removes it during retention.`;
    return base ? `${damage} ${base}` : damage;
  }
  return base;
}

function updateStateLine(state, { launchId, root }) {
  const log = `${root}/install-log.jsonl`;
  if (!state || state.unreadable !== undefined || state.launch_id !== launchId) {
    const why = !state || state.unreadable !== undefined
      ? `it could not be read: ${state && state.unreadable ? state.unreadable : 'absent'}`
      : `it names launch ${String(state.launch_id)}, not this one`;
    return (
      `CLIENT UPDATE STATE UNKNOWN: ${root}/state.json does not describe this launch (${why}), ` +
      'so whether this seat runs its bridge\'s published channel-server release was not established. ' +
      `Tell your operator; the install log is ${log}.`
    );
  }
  const running = state.running || 'unknown';
  switch (state.state) {
    case 'current':
      return null;
    case 'approval_owed':
      return (
        `CLIENT RELEASE ${state.approval_owed || 'unknown'} IS PUBLISHED by your bridge, and this seat requires PM approval ` +
        `before it installs it, so it keeps running channel-server release ${running}. ` +
        `The PM approves it on the bridge with \`php artisan bridge:client-approve <this agent> ${state.approval_owed || '<release>'} --reason="…"\`; ` +
        'it is installed at the next launch after that.'
      );
    case 'update_failed':
      return (
        `CLIENT UPDATE FAILED (${state.error || 'no reason recorded'}): this seat runs channel-server release ${running}` +
        (state.published && state.published !== state.running ? `; published release ${state.published} was not applied` : '') +
        `. Tell your operator; the install log is ${log}. ` +
        'The update is tried again at the next launch' +
        // An installed updater that cannot run never fetches the fix itself, and re-bootstrapping
        // the same release reuses the same updater; an unreadable install log is a local fault no
        // release fixes. Every other cause is named by the reason at the start of the line.
        (state.updater_broken
          ? "; the installed updater itself cannot run, so it cannot fetch a fix: once the bridge publishes a fixed release, re-bootstrap this seat's client from it."
          : state.log_unreadable
            ? '; the install log itself cannot be read — fix that file (its owner and permissions) and the next launch retries.'
            : '; the reason at the start of this line names the cause.')
      );
    default:
      return (
        `CLIENT UPDATE STATE UNKNOWN: ${root}/state.json names state ${JSON.stringify(state.state)}, ` +
        'which this server does not know, so whether this seat runs its bridge\'s published channel-server release was not established. ' +
        `Tell your operator; the install log is ${log}.`
      );
  }
}

// ---------------------------------------------------------------------------------------------
// What the bridge serves this agent (card#11283 / DL-462)

// The cache of the bridge's `served_tools` answer, in the client root (beside state.json).
// ⛔ AN ADD-ONLY CROSS-RELEASE CONTRACT: the updater of one release writes it and the server of
// another reads it, so a field is never renamed, retyped or removed — only added. Shape:
//   { launch_id: <the launch that wrote it>, agent: <the bridge's identity echo, or null when the
//     answer carried none — client_manifest does not>, served: [<tool name>, …], written_at }
// Readers rely on `launch_id` and `served` only.
export const SERVED_TOOLS_FILE = 'served-tools.json';

// The client-update door beside a board-tools endpoint that ends in `/agent-tools/call`:
// `{url}`, or `{why}` naming what is wrong without quoting the value (it may carry a credential).
export function deriveClientDoorUrl(endpoint) {
  let url;
  try {
    url = new URL(endpoint);
  } catch {
    return { why: 'BRIDGE_TOOLS_ENDPOINT is not a URL (its value is not shown: it may carry a credential)' };
  }
  if (!url.pathname.endsWith('/agent-tools/call')) {
    return { why: `BRIDGE_TOOLS_ENDPOINT ${redactUrl(endpoint)} does not end in /agent-tools/call, so the update door's address (…/agent-tools/client beside it) cannot be derived from it` };
  }
  url.pathname = `${url.pathname.slice(0, -'call'.length)}client`;
  url.search = '';
  url.hash = '';
  return { url: url.toString() };
}

function isToolList(value) {
  return Array.isArray(value) && value.every((name) => typeof name === 'string');
}

const PRE_DOOR_REFUSAL = 'request must carry a non-empty `tool`';

// What one `{"op":"served_tools"}` answer says. Input: `{via: 'ssh', code, stdout}`,
// `{via: 'http', status, text}`, or `{failure: <why>}` when no answer arrived. Output:
//   { kind: 'served', served }   the bridge's list for this agent
//   { kind: 'door_closed' }      the ssh door will not serve this agent at all (DL-461)
//   { kind: 'old_bridge' }       a bridge that does not know the op: one with the update door but
//                                not this op (`unknown client-update …`, no reason), one older than
//                                the door (ssh: the empty-`tool` refusal; http: 404, no route)
//   { kind: 'unknown', why }     anything else — no answer, a reason-less exit 2, a 5xx, a 401, a
//                                body that is not the op's answer. It says nothing about what is
//                                served, so the caller falls back (last cache, then the env rule).
export function classifyServedToolsAnswer(answer) {
  if (answer.failure) {
    return { kind: 'unknown', why: answer.failure };
  }
  const status = answer.via === 'ssh' ? `ssh exit ${answer.code}` : `HTTP ${answer.status}`;
  if (answer.via === 'http' && answer.status === 404) {
    return { kind: 'old_bridge' };
  }
  let body = null;
  try {
    body = JSON.parse(answer.via === 'ssh' ? answer.stdout : answer.text);
  } catch {
    body = null;
  }
  if (!body || typeof body !== 'object' || Array.isArray(body)) {
    return { kind: 'unknown', why: `${status}, no JSON envelope` };
  }
  const clean = answer.via === 'ssh' ? answer.code === 0 : answer.status === 200;
  if (clean && body.ok === true && body.op === 'served_tools' && isToolList(body.served)) {
    return { kind: 'served', served: body.served };
  }
  if (answer.via === 'ssh' && answer.code === 2 && body.ok === false && body.reason === 'door_closed') {
    return { kind: 'door_closed' };
  }
  const refused = answer.via === 'ssh' ? answer.code === 1 : answer.status === 422;
  if (refused && body.ok === false && typeof body.error === 'string') {
    if (body.reason === undefined && body.error.startsWith('unknown client-update')) {
      return { kind: 'old_bridge' };
    }
    // The pre-door refusal carries no reason on every real bridge; a later bridge added
    // `bad_request` to it, though such a bridge routes an `op` body to the door instead.
    if ((body.reason === undefined || body.reason === 'bad_request') && body.error === PRE_DOOR_REFUSAL) {
      return { kind: 'old_bridge' };
    }
  }
  return { kind: 'unknown', why: `${status}${typeof body.error === 'string' ? `: ${scrubSnippet(body.error).slice(0, 200)}` : ''}` };
}

// `<root>/served-tools.json` as `{launchId, served}`, or null for an absent or malformed file.
export function readServedToolsCache(root) {
  let raw;
  try {
    raw = JSON.parse(fs.readFileSync(`${root}/${SERVED_TOOLS_FILE}`, 'utf8'));
  } catch {
    return null;
  }
  if (!raw || typeof raw !== 'object' || typeof raw.launch_id !== 'string' || !isToolList(raw.served)) {
    return null;
  }
  return { launchId: raw.launch_id, served: raw.served };
}

// The ONE `served_tools` call, over the seat's board-tools transport (`boardToolsTransport`'s
// ssh or http result). Resolves with the classified answer; never rejects.
export async function askServedTools(transport, { deadlineMs }) {
  const input = JSON.stringify({ op: 'served_tools' });
  if (transport.kind === 'ssh') {
    const r = await sshRoundTrip({ target: transport.target, key: transport.key, port: transport.port, input, deadlineMs });
    return classifyServedToolsAnswer(r.failure ? { failure: r.failure.message } : { via: 'ssh', code: r.code, stdout: r.stdout });
  }
  const door = deriveClientDoorUrl(transport.url);
  if (door.why) {
    return { kind: 'unknown', why: door.why };
  }
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), deadlineMs);
  try {
    const r = await httpRoundTrip({ url: door.url, token: transport.token, body: input, signal: controller.signal });
    return classifyServedToolsAnswer({ via: 'http', status: r.status, text: r.text });
  } catch (err) {
    return classifyServedToolsAnswer({ failure: `${redactUrl(door.url)}: ${errorDetail(err)}` });
  } finally {
    clearTimeout(timer);
  }
}

// The served set this launch advertises, down the ladder (design §5, DL-462):
//   1. this launch's cache (the updater wrote it from this launch's client_manifest answer);
//   2. one served_tools call — `served` is used; `door_closed` advertises no bridge tool;
//      `old_bridge` takes the env rule (a bridge that does not know the op predates scope-less
//      agents, so any cache is from a newer bridge and is not believed);
//   3. on `unknown`, the last good cache, from any launch;
//   4. else the env rule.
// Resolves `{served, source}`; `served` null means the env rule. `cache` is readServedToolsCache's
// result; `ask` makes the call.
export async function resolveServedTools({ launchId, cache, ask }) {
  if (cache && launchId && cache.launchId === launchId) {
    return { served: cache.served, source: 'this launch\'s served-tools cache' };
  }
  const answer = await ask();
  if (answer.kind === 'served') {
    return { served: answer.served, source: 'the bridge\'s served_tools answer' };
  }
  if (answer.kind === 'door_closed') {
    return { served: [], source: 'the bridge\'s door is closed to this agent (door_closed)' };
  }
  if (answer.kind === 'old_bridge') {
    return { served: null, source: 'a bridge that predates served_tools; the env rule' };
  }
  if (cache) {
    return { served: cache.served, source: `the last good served-tools cache (launch ${cache.launchId}), since served_tools did not answer (${answer.why})` };
  }
  return { served: null, source: `the env rule, since served_tools did not answer (${answer.why}) and no cache exists` };
}
