// Helpers for the reference channel MCP server (agent-webhook-bridge-channel.mjs) and its
// launch-time updater (client-update.mjs).
//
// Two kinds of export, and one rule for both: every input is an ARGUMENT. Nothing here reads
// process.env, closes over a startup constant, or calls process.exit — so each is testable
// directly, and the updater and the server share ONE implementation of each (canon #5):
//   - PURE helpers (scrubSnippet, relayBridgeResponse, deriveMeta, launchIdentity,
//     clientUpdateInstruction, resolveToolsToken's precedence) — no I/O beyond what an
//     argument names;
//   - the bridge TRANSPORT primitives (sshRoundTrip, httpRoundTrip) — they do I/O (a child
//     process, a fetch), but only to the target their caller passes, and they return what
//     happened rather than deciding what it means. The board-tools proxy relays that as a
//     tool result; the updater reads it as a client-update door answer.
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

// One HTTP POST to a bridge door with the agent's bearer. Resolves {status, ok, text}; REJECTS
// when no response arrived (connection refused, DNS, an abort through `signal`) and when the
// answer is a REDIRECT — a bearer call is never re-sent elsewhere (DL-217: the doors are
// loopback, and a redirect off them would carry the bearer to wherever it points). The caller
// words that failure — the two callers say different things about it.
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
  return { status: res.status, ok: res.ok, text: await res.text() };
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
    const damage = `CLIENT RELEASE DAMAGED ON THIS SEAT: ${state.recovered}. Tell your operator; the damaged release is fetched again from the bridge.`;
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
        'The update is tried again at the next launch; an updater that fails at every launch is replaced when the bridge publishes a newer release.'
      );
    default:
      return (
        `CLIENT UPDATE STATE UNKNOWN: ${root}/state.json names state ${JSON.stringify(state.state)}, ` +
        'which this server does not know, so whether this seat runs its bridge\'s published channel-server release was not established. ' +
        `Tell your operator; the install log is ${log}.`
      );
  }
}
