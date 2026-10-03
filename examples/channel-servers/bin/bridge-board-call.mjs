#!/usr/bin/env node
// bridge-board-call — call ONE board tool from a script or a hook, as this seat (card#11151, DL-451).
//
//   bridge-board-call [--channel <name>] [--project-dir <dir>] <tool> ['<json-args>']
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
// board tool from a script: 0 ok · 1 the tool refused · 2 the call reached no tool (transport, auth,
// install) · 3 unmeasured · 4 usage.

import fs from 'node:fs';
import path from 'node:path';
import { sshRoundTrip, httpRoundTrip, resolveToolsToken, scrubSnippet, errorDetail, readClientVersion } from '../channel-lib.mjs';

const EXIT_OK = 0;
const EXIT_REFUSED = 1;
const EXIT_NO_TOOL = 2;
const EXIT_UNMEASURED = 3;
const EXIT_USAGE = 4;

const CALLER = 'script';
// The whole call, either door: the channel server's own ssh deadline. A start's read-back is part
// of the call, so this is a hang guard, not a latency budget.
const DEADLINE_MS = 60000;
const SSH_STDERR_CAPTURE_LIMIT = 2000;

const USAGE = 'usage: bridge-board-call [--channel <name>] [--project-dir <dir>] <tool> [\'<json-args>\']';

class Stop extends Error {
  constructor(code, message) {
    super(message);
    this.code = code;
  }
}

function parseArgv(argv) {
  const opts = { channel: null, projectDir: null };
  const rest = [];
  for (let i = 0; i < argv.length; i++) {
    const a = argv[i];
    if (a === '--channel' || a === '--project-dir') {
      const value = argv[++i];
      if (value === undefined || value === '') {
        throw new Stop(EXIT_USAGE, `${a} needs a value\n${USAGE}`);
      }
      opts[a === '--channel' ? 'channel' : 'projectDir'] = value;
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
    } catch (err) {
      throw new Stop(EXIT_USAGE, `the arguments are not JSON (${err.message})\n${USAGE}`);
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
  } catch (err) {
    throw new Stop(EXIT_NO_TOOL, `${file} is not JSON (${err.message})`);
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

/** The seat's board-tools transport, under the channel server's own rules. */
function transportOf(env, source) {
  if (env.BRIDGE_CHANNEL_TOOLS === '0' || env.BRIDGE_CHANNEL_TOOLS === '') {
    throw new Stop(EXIT_NO_TOOL, `board tools are switched off for this channel (BRIDGE_CHANNEL_TOOLS=${JSON.stringify(env.BRIDGE_CHANNEL_TOOLS)}, read from ${source}), so its channel server offers none and this call was not made`);
  }
  const target = env.BRIDGE_TOOLS_SSH_TARGET || '';
  const endpoint = env.BRIDGE_TOOLS_ENDPOINT || '';
  if (target && endpoint) {
    throw new Stop(EXIT_NO_TOOL, `BRIDGE_TOOLS_SSH_TARGET and BRIDGE_TOOLS_ENDPOINT are both set (read from ${source}) — a seat has exactly one board-tools transport`);
  }
  if (target) {
    return { kind: 'ssh', target, key: env.BRIDGE_TOOLS_SSH_KEY || '', port: env.BRIDGE_TOOLS_SSH_PORT || '' };
  }
  if (endpoint) {
    const token = resolveToolsToken(env);
    if (!token) {
      throw new Stop(EXIT_NO_TOOL, `BRIDGE_TOOLS_ENDPOINT is set but no bearer resolves (BRIDGE_TOOLS_TOKEN, BRIDGE_TOOLS_TOKEN_FILE or BRIDGE_CHANNEL_TOKEN; read from ${source})`);
    }
    return { kind: 'http', url: endpoint, token };
  }
  throw new Stop(EXIT_NO_TOOL, `no board-tools transport is configured: neither BRIDGE_TOOLS_SSH_TARGET nor BRIDGE_TOOLS_ENDPOINT is set in ${source}`);
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

async function overSsh(t, payload) {
  const r = await sshRoundTrip({ target: t.target, key: t.key, port: t.port, input: payload, deadlineMs: DEADLINE_MS, stderrLimit: SSH_STDERR_CAPTURE_LIMIT });
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
  // 255 is ssh's own failure (no connection, no authentication). A bridge process that died with
  // 255 before writing its envelope looks the same from here; that bound is in the README.
  if (r.code === 255 && body === null) {
    return { code: EXIT_NO_TOOL, stderr: `${how}${stderr ? `: ${stderr}` : ' and wrote nothing to stderr'}` };
  }
  return unmeasured(`${how}${stderr ? ` (stderr: ${stderr})` : ''}`, r.stdout);
}

async function overHttp(t, payload) {
  const signal = AbortSignal.timeout(DEADLINE_MS);
  let res;
  try {
    res = await httpRoundTrip({ url: t.url, token: t.token, body: payload, signal });
  } catch (err) {
    return signal.aborted
      ? unmeasured(`${t.url} did not answer within ${DEADLINE_MS}ms`, '')
      : { code: EXIT_NO_TOOL, stderr: `could not reach ${t.url}: ${errorDetail(err)}` };
  }
  const body = objectOf(res.text);
  if (body !== null && ((res.status === 200 && body.ok === true) || (res.status === 422 && body.ok === false))) {
    return answered(res.text, body);
  }
  const how = `${t.url} answered HTTP ${res.status}`;
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
  return transport.kind === 'ssh' ? overSsh(transport, payload) : overHttp(transport, payload);
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
