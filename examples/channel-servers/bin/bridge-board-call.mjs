#!/usr/bin/env node
// bridge-board-call — call ONE board tool from a script or a hook, as this seat (card#11151, DL-451).
//
//   bridge-board-call [--channel <name>] [--project-dir <dir>] [--deadline-ms <n>] <tool> ['<json-args>']
//
// It sends the same request the channel server sends for an MCP tools/call, over the same
// transport and credential, and prints the bridge's answer. It takes NO identity argument: the
// bridge resolves the seat from the credential the transport carries (the pinned forced command's
// `--agent`, or the bearer), exactly as it does for the channel server.
//
// ⭐ WHERE THE TRANSPORT COMES FROM — the channel server's own environment, rebuilt the way Claude
// Code builds it: this environment overlaid by the channel's `.mcp.json` `env` block, `${VAR}`
// expanded. Which `.mcp.json`, which channel, and what is not read are README.md § Calling a board
// tool from a script's to state; `seatEnvironment` below is the rule.
//
// ⭐ ONE-SHOT ON BOTH DOORS, no MCP. The ssh door's forced command (`bridge:tools-call`) reads one
// `{tool, args, …}` JSON body on stdin and writes one JSON envelope on stdout, and the HTTP door is
// one POST; both are the bridge's own doors, not MCP servers, so there is no JSON-RPC to speak.
// The round trips are channel-lib's `sshRoundTrip` / `httpRoundTrip`, the ones the channel server
// and the updater use.
//
// The request declares `caller: "script"` (ExemptCaller::Script on the bridge, card#10567 B4): a
// call from here is not the channel server, and so must not overwrite what the seat's channel
// server reports to the fleet ledger.
//
// EXIT — the contract, and why each answer lands where it does, is owned by README.md § Calling a
// board tool from a script: 0 ok · 1 the tool refused · 2 the call provably reached no tool
// (configuration, transport, auth, install) · 3 unmeasured · 4 usage.

import fs from 'node:fs';
import path from 'node:path';
import { sshRoundTrip, httpRoundTrip, boardToolsTransport, redactUrl, scrubSnippet, errorDetail, readClientVersion } from '../channel-lib.mjs';

const EXIT_OK = 0;
const EXIT_REFUSED = 1;
const EXIT_NO_TOOL = 2;
const EXIT_UNMEASURED = 3;
const EXIT_USAGE = 4;

const CALLER = 'script';
// The whole call, either door. Below the 60 s a Claude Code hook gets by default, so a hook that
// keeps its default timeout sees this program's own exit 3 rather than a kill; `--deadline-ms`
// overrides it. A start's read-back is part of the call, so this is a hang guard, not a budget.
const DEFAULT_DEADLINE_MS = 45000;
const MAX_DEADLINE_MS = 600000;
const SSH_STDERR_CAPTURE_LIMIT = 2000;

const USAGE = 'usage: bridge-board-call [--channel <name>] [--project-dir <dir>] [--deadline-ms <1-600000>] <tool> [\'<json-args>\']';

class Stop extends Error {
  constructor(code, message) {
    super(message);
    this.code = code;
  }
}

function parseArgv(argv) {
  const opts = { channel: null, projectDir: null, deadlineMs: DEFAULT_DEADLINE_MS };
  const rest = [];
  for (let i = 0; i < argv.length; i++) {
    const a = argv[i];
    if (a === '--channel' || a === '--project-dir' || a === '--deadline-ms') {
      const value = argv[++i];
      if (value === undefined || value === '') {
        throw new Stop(EXIT_USAGE, `${a} needs a value\n${USAGE}`);
      }
      if (a === '--deadline-ms') {
        if (!/^[0-9]{1,6}$/.test(value) || Number(value) < 1 || Number(value) > MAX_DEADLINE_MS) {
          throw new Stop(EXIT_USAGE, `--deadline-ms must be a whole number of milliseconds from 1 to ${MAX_DEADLINE_MS}\n${USAGE}`);
        }
        opts.deadlineMs = Number(value);
      } else {
        opts[a === '--channel' ? 'channel' : 'projectDir'] = value;
      }
    } else if (a === '-h' || a === '--help') {
      throw new Stop(EXIT_USAGE, USAGE);
    } else if (a.startsWith('-')) {
      throw new Stop(EXIT_USAGE, `unknown option ${a}\n${USAGE}`);
    } else {
      rest.push(a);
    }
  }
  if (rest.length < 1 || rest.length > 2 || rest[0] === '') {
    throw new Stop(EXIT_USAGE, USAGE);
  }
  let args = {};
  if (rest.length === 2) {
    try {
      args = JSON.parse(rest[1]);
    } catch {
      throw new Stop(EXIT_USAGE, `the arguments are not valid JSON\n${USAGE}`);
    }
    if (args === null || typeof args !== 'object' || Array.isArray(args)) {
      throw new Stop(EXIT_USAGE, `the arguments must be a JSON object\n${USAGE}`);
    }
  }
  return { ...opts, tool: rest[0], args };
}

// Claude Code's `.mcp.json` expansion, as bin/provision-board-tools.py `bootstrap_env` reads it
// from Claude Code's own binary: `${VAR}` or `${VAR:-default}` from the launching environment; the
// default applies only when VAR is UNSET (an empty VAR stays empty). Claude Code passes an
// unresolved reference through as literal text, which no transport can use, so it is refused here.
const MCP_ENV_REF = /\$\{([A-Za-z_][A-Za-z0-9_]*)(?::-([^}]*))?\}/g;

function expand(key, value, base, where) {
  if (typeof value !== 'string') {
    throw new Stop(EXIT_NO_TOOL, `${where} records ${key} as a ${value === null ? 'null' : typeof value}, not a string`);
  }
  const missing = [];
  const out = value.replace(MCP_ENV_REF, (whole, name, fallback) => {
    if (Object.prototype.hasOwnProperty.call(base, name)) {
      return base[name];
    }
    if (fallback !== undefined) {
      return fallback;
    }
    missing.push(name);
    return whole;
  });
  if (missing.length > 0) {
    throw new Stop(EXIT_NO_TOOL, `${where} records ${key} as a reference to ${missing.join(', ')}, which is not set here and has no default — set it as your sessions have it, or record the value`);
  }
  return out;
}

const TRANSPORT_KEYS = ['BRIDGE_TOOLS_SSH_TARGET', 'BRIDGE_TOOLS_ENDPOINT'];

/** The environment the seat's channel server runs with, and where its transport was read from. */
function seatEnvironment(base, { channel, projectDir }) {
  const dir = path.resolve(projectDir || base.CLAUDE_PROJECT_DIR || process.cwd());
  const file = path.join(dir, '.mcp.json');
  let text = null;
  try {
    text = fs.readFileSync(file, 'utf8');
  } catch (err) {
    if (err.code !== 'ENOENT' || channel) {
      throw new Stop(EXIT_NO_TOOL, `${file} could not be read (${err.code || err.message})${channel ? `, so channel ${channel} cannot be found` : ''}`);
    }
  }
  if (text === null) {
    return { env: base, source: `this environment (${file} does not exist)` };
  }
  let servers;
  try {
    servers = JSON.parse(text).mcpServers;
  } catch {
    // ⛔ Never `err.message`: V8 quotes the input around the error, and the input here is a file
    // that holds bearer tokens (canon #20).
    throw new Stop(EXIT_NO_TOOL, `${file} is not valid JSON`);
  }
  if (servers === null || typeof servers !== 'object' || Array.isArray(servers)) {
    throw new Stop(EXIT_NO_TOOL, `${file} has no \`mcpServers\` object`);
  }
  const envOf = (name) => {
    const entry = servers[name];
    return entry !== null && typeof entry === 'object' && entry.env !== null && typeof entry.env === 'object' && !Array.isArray(entry.env) ? entry.env : null;
  };
  let chosen = channel;
  if (chosen !== null) {
    if (!Object.prototype.hasOwnProperty.call(servers, chosen)) {
      throw new Stop(EXIT_NO_TOOL, `${file} has no mcpServers.${chosen} (it has: ${Object.keys(servers).join(', ') || 'none'})`);
    }
  } else {
    const recording = Object.keys(servers).filter((name) => {
      const env = envOf(name);
      return env !== null && TRANSPORT_KEYS.some((k) => Object.prototype.hasOwnProperty.call(env, k));
    });
    if (recording.length > 1) {
      throw new Stop(EXIT_NO_TOOL, `${file} records a board-tools transport for more than one channel (${recording.join(', ')}) — name one with --channel`);
    }
    if (recording.length === 0) {
      return { env: base, source: `this environment (no channel in ${file} records ${TRANSPORT_KEYS.join(' or ')})` };
    }
    chosen = recording[0];
  }
  const env = { ...base };
  const where = `${file} mcpServers.${chosen}.env`;
  for (const [key, value] of Object.entries(envOf(chosen) ?? {})) {
    env[key] = expand(key, value, base, where);
  }
  return { env, source: `${where} over this environment` };
}

/** The seat's board-tools transport — channel-lib's `boardToolsTransport`, the server's own rules. */
function transportOf(env, source) {
  if (env.BRIDGE_CHANNEL_TOOLS === '0' || env.BRIDGE_CHANNEL_TOOLS === '') {
    throw new Stop(EXIT_NO_TOOL, `board tools are switched off for this channel (BRIDGE_CHANNEL_TOOLS=${JSON.stringify(env.BRIDGE_CHANNEL_TOOLS)}, read from ${source}), so its channel server offers none and this call was not made`);
  }
  const t = boardToolsTransport(env);
  if (t.kind === 'conflict') {
    throw new Stop(EXIT_NO_TOOL, `BRIDGE_TOOLS_SSH_TARGET and BRIDGE_TOOLS_ENDPOINT are both set (read from ${source}) — a seat has exactly one board-tools transport`);
  }
  if (t.kind === 'invalid') {
    throw new Stop(EXIT_NO_TOOL, `${t.why} (read from ${source}); nothing was sent`);
  }
  if (t.kind === 'incomplete') {
    throw new Stop(
      EXIT_NO_TOOL,
      env.BRIDGE_TOOLS_ENDPOINT
        ? `BRIDGE_TOOLS_ENDPOINT is set but no bearer resolves (BRIDGE_TOOLS_TOKEN, BRIDGE_TOOLS_TOKEN_FILE or BRIDGE_CHANNEL_TOKEN; read from ${source})`
        : `no board-tools transport is configured: neither BRIDGE_TOOLS_SSH_TARGET nor BRIDGE_TOOLS_ENDPOINT is set in ${source}`,
    );
  }
  return t;
}

/** The body as a JSON object, or null. */
function objectOf(text) {
  try {
    const value = JSON.parse(text);
    return value !== null && typeof value === 'object' && !Array.isArray(value) ? value : null;
  } catch {
    return null;
  }
}

function answered(raw, body) {
  if (body.ok === true) {
    return { code: EXIT_OK, stdout: raw };
  }
  return { code: EXIT_REFUSED, stdout: raw, stderr: typeof body.error === 'string' ? body.error : '(the refusal carries no error text)' };
}

// What an answer said, for stderr: the door's own `error` text verbatim (the bridge composed it),
// else the raw body scrubbed and bounded (a proxy page or a PHP trace may echo a credential).
function saidBy(raw) {
  const body = objectOf(raw);
  return body !== null && typeof body.error === 'string' ? body.error : scrubSnippet(String(raw).trim());
}

function unmeasured(what, raw) {
  const said = saidBy(raw);
  return { code: EXIT_UNMEASURED, stderr: `the outcome is NOT established — ${what}${said ? `: ${said}` : ''}` };
}

// ssh's own exit 255 proves nothing reached the bridge only when ssh says the session never
// opened: it could not connect, resolve, trust the host or authenticate. 255 is also a session
// that dropped after the forced command started ("closed by remote host", "Broken pipe") — the
// same may-have-landed case as a reset after an HTTP request — and a bridge process that died
// with 255 before its envelope. Those, and a 255 that says nothing, are unmeasured.
// Each pattern is anchored on a whole line in the shape OpenSSH's client writes, so the far end's
// own stderr — a forced command mentioning "Connection refused" about its database — does not
// match. A remote process that prints one of these exact lines can still; that bound is DL-451's.
const SSH_BEFORE_SESSION = [
  /^ssh: connect to host \S+ port \d+: (Connection refused|No route to host|Network is unreachable|Connection timed out)\r?$/m,
  /^ssh: Could not resolve hostname \S+: .+$/m,
  /^Host key verification failed\.\r?$/m,
  /^\S+: Permission denied \([^)]*\)\.\r?$/m,
  /^Received disconnect from \S+ port \d+:\d+: Too many authentication failures/m,
  /^Unable to negotiate with \S+ port \d+: /m,
];

async function overSsh(t, payload, deadlineMs) {
  const r = await sshRoundTrip({ target: t.target, key: t.key, port: t.port, input: payload, deadlineMs, stderrLimit: SSH_STDERR_CAPTURE_LIMIT });
  if (r.failure) {
    return r.failure.kind === 'spawn' || r.failure.kind === 'error'
      ? { code: EXIT_NO_TOOL, stderr: r.failure.message }
      : unmeasured(r.failure.message, '');
  }
  const stderr = scrubSnippet(r.stderrHead.trim());
  const how = r.code === null ? `ssh ${t.target} was killed by ${r.killSignal}` : `ssh ${t.target} exited ${r.code}`;
  const body = objectOf(r.stdout);
  if (body !== null && ((r.code === 0 && body.ok === true) || (r.code === 1 && body.ok === false))) {
    return answered(r.stdout, body);
  }
  if (r.code === 255 && body === null && SSH_BEFORE_SESSION.some((re) => re.test(r.stderrHead))) {
    return { code: EXIT_NO_TOOL, stderr: `${how} before a session opened: ${stderr}` };
  }
  return unmeasured(`${how}${stderr ? ` (stderr: ${stderr})` : ''}`, r.stdout);
}

// A rejection proves nothing reached the bridge only for these causes; any other — a reset, a
// socket closed mid-answer, a body cut off after its status — may follow a request the bridge
// received and acted on, so it is unmeasured.
const HTTP_NOT_SENT = new Set(['ECONNREFUSED', 'ENOTFOUND', 'EAI_AGAIN', 'EHOSTUNREACH', 'ENETUNREACH']);

function neverSent(err) {
  if (err && err.status !== undefined) {
    return false;
  }
  const cause = err && err.cause;
  if (!cause) {
    return false;
  }
  // `redirect: 'error'` refuses a 3xx before following it: the bridge's door never redirects, so
  // whatever answered is not the door and no tool ran.
  if (/redirect/i.test(String(cause.message))) {
    return true;
  }
  const codes = Array.isArray(cause.errors) && cause.errors.length > 0 ? cause.errors.map((e) => e && e.code) : [cause.code];
  return codes.every((code) => HTTP_NOT_SENT.has(code));
}

async function overHttp(t, payload, deadlineMs) {
  const url = redactUrl(t.url);
  const signal = AbortSignal.timeout(deadlineMs);
  let res;
  try {
    res = await httpRoundTrip({ url: t.url, token: t.token, body: payload, signal });
  } catch (err) {
    if (signal.aborted) {
      return unmeasured(`${url} did not answer within ${deadlineMs}ms`, '');
    }
    if (err && err.status !== undefined) {
      return unmeasured(`${url} answered HTTP ${err.status} and its body could not be read (${errorDetail(err)})`, '');
    }
    return neverSent(err)
      ? { code: EXIT_NO_TOOL, stderr: `could not reach ${url}: ${errorDetail(err)}` }
      : unmeasured(`the connection to ${url} failed after the request may have been sent (${errorDetail(err)})`, '');
  }
  const body = objectOf(res.text);
  if (body !== null && ((res.status === 200 && body.ok === true) || (res.status === 422 && body.ok === false))) {
    return answered(res.text, body);
  }
  const how = `${url} answered HTTP ${res.status}`;
  if ((res.status >= 400 && res.status < 500 && res.status !== 422) || res.status === 503) {
    const said = saidBy(res.text);
    return { code: EXIT_NO_TOOL, stderr: `${how} — the call reached no tool${said ? `: ${said}` : ''}` };
  }
  return unmeasured(how, res.text);
}

async function main(argv, env) {
  const call = parseArgv(argv);
  const { env: seatEnv, source } = seatEnvironment(env, call);
  const transport = transportOf(seatEnv, source);
  const version = readClientVersion();
  const payload = JSON.stringify({ tool: call.tool, args: call.args, caller: CALLER, ...(version === null ? {} : { client_version: version }) });
  return transport.kind === 'ssh' ? overSsh(transport, payload, call.deadlineMs) : overHttp(transport, payload, call.deadlineMs);
}

// `stderr` is printed with this program's name in front, except a refusal's `error`, which is
// printed verbatim, and usage text.
let outcome;
try {
  outcome = await main(process.argv.slice(2), process.env);
} catch (err) {
  outcome = err instanceof Stop
    ? { code: err.code, stderr: err.message }
    : { code: EXIT_UNMEASURED, stderr: `unexpected failure: ${err && err.stack ? err.stack : err}` };
}
if (outcome.stdout !== undefined) {
  process.stdout.write(`${outcome.stdout}\n`);
}
if (outcome.stderr !== undefined) {
  const verbatim = outcome.code === EXIT_REFUSED || outcome.code === EXIT_USAGE;
  process.stderr.write(`${verbatim ? '' : 'bridge-board-call: '}${outcome.stderr}\n`);
}
process.exitCode = outcome.code;
