// bridge-board-call (card#11151, DL-451): one board-tools call from a script, over the seat's
// configured transport, answered with the exit-code contract the README states.
//
// Every case runs the REAL CLI as a child process, against a fake `ssh` first on PATH or a local
// HTTP server standing in for the door — the whole seam is exercised (config resolution, the
// shared channel-lib transport, and the mapping to an exit code), not a re-implementation of it.
// The child's environment is an ALLOWLIST and its project dir a scratch dir, so nothing of the
// seat running this suite — its `.mcp.json`, its BRIDGE_* variables — reaches it.
//
// Run: `node --test examples/channel-servers/tests/`.
import './live-state-guard.mjs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { scratch } from './mcp-harness.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const CLI = path.join(HERE, '..', 'bin', 'bridge-board-call.mjs');
const MANIFEST_VERSION = JSON.parse(fs.readFileSync(path.join(HERE, '..', 'package.json'), 'utf8')).version;
const TARGET = 'bridge@testhost';

const REFUSAL = {
  ok: false,
  error: "board_take_card: this bridge's config for agent `x` declares no `identity.kanban_user_id` … NOTHING WAS WRITTEN. This is an INSTALL fault",
  reason: 'install_fault.no_kanban_user',
};
const SUCCESS = { ok: true, tool: 'board_take_card', result: { card_id: 123, moved: true, assigned: true } };

/** Run the CLI; resolves {code, stdout, stderr}. `env` is merged over a minimal allowlist. */
function run(t, argv, { env = {}, cwd } = {}) {
  const dir = cwd ?? scratch(t, 'bbc-cwd-');
  return new Promise((resolve) => {
    const child = spawn(process.execPath, [CLI, ...argv], {
      cwd: dir,
      env: { PATH: process.env.PATH, HOME: dir, CLAUDE_PROJECT_DIR: dir, ...env },
      stdio: ['ignore', 'pipe', 'pipe'],
    });
    let stdout = '';
    let stderr = '';
    child.stdout.on('data', (c) => (stdout += c));
    child.stderr.on('data', (c) => (stderr += c));
    child.on('close', (code) => resolve({ code, stdout, stderr }));
  });
}

/**
 * A fake `ssh` that records its argv and stdin, then answers with the stdout / stderr / exit code
 * the test set in its environment (the CLI passes its environment to ssh unchanged).
 */
function fakeSsh(t, { exit = 0, stdout = '', stderr = '' } = {}) {
  const dir = scratch(t, 'bbc-ssh-');
  const record = path.join(dir, 'record');
  fs.writeFileSync(
    path.join(dir, 'ssh'),
    '#!/usr/bin/env bash\n' +
      `printf '%s\\n' "$@" > "${record}.argv"\n` +
      `cat > "${record}.stdin"\n` +
      'printf "%s" "$FAKE_SSH_STDOUT"\n' +
      'printf "%s" "$FAKE_SSH_STDERR" >&2\n' +
      'exit "$FAKE_SSH_EXIT"\n',
    { mode: 0o755 },
  );
  return {
    env: { PATH: `${dir}${path.delimiter}${process.env.PATH}`, FAKE_SSH_EXIT: String(exit), FAKE_SSH_STDOUT: stdout, FAKE_SSH_STDERR: stderr },
    argv: () => fs.readFileSync(`${record}.argv`, 'utf8').trim().split('\n'),
    sent: () => JSON.parse(fs.readFileSync(`${record}.stdin`, 'utf8')),
  };
}

/** A local stand-in for POST /agent-tools/call answering `status` with `body` (a string, verbatim). */
async function fakeDoor(t, { status = 200, body = JSON.stringify(SUCCESS) } = {}) {
  const seen = [];
  const server = http.createServer((req, res) => {
    const chunks = [];
    req.on('data', (c) => chunks.push(c));
    req.on('end', () => {
      seen.push({ url: req.url, authorization: req.headers.authorization, body: JSON.parse(Buffer.concat(chunks).toString('utf8')) });
      res.writeHead(status, { 'Content-Type': 'application/json' });
      res.end(body);
    });
  });
  await new Promise((r) => server.listen(0, '127.0.0.1', r));
  t.after(() => server.close());
  return { url: `http://127.0.0.1:${server.address().port}/agent-tools/call`, seen };
}

const START = ['board_take_card', '{"card_id":123,"start":true}'];

// ---------------------------------------------------------------------------------------------
// The ssh door

test('ssh: an ok answer exits 0 and prints the response verbatim on stdout', async (t) => {
  const ssh = fakeSsh(t, { stdout: JSON.stringify(SUCCESS) });
  const r = await run(t, START, { env: { ...ssh.env, BRIDGE_TOOLS_SSH_TARGET: TARGET } });

  assert.equal(r.code, 0, r.stderr);
  assert.equal(r.stdout, `${JSON.stringify(SUCCESS)}\n`);
  assert.equal(r.stderr, '');
  assert.deepEqual(ssh.sent(), { tool: 'board_take_card', args: { card_id: 123, start: true }, caller: 'script', client_version: MANIFEST_VERSION });
  assert.equal(ssh.argv().at(-1), TARGET, 'ssh is given the target and no command (sshd forces bridge:tools-call)');
});

test('ssh: a tool refusal exits 1 — the refusal JSON on stdout, its error verbatim on stderr, its reason kept', async (t) => {
  const ssh = fakeSsh(t, { exit: 1, stdout: JSON.stringify(REFUSAL) });
  const r = await run(t, START, { env: { ...ssh.env, BRIDGE_TOOLS_SSH_TARGET: TARGET } });

  assert.equal(r.code, 1);
  assert.equal(JSON.parse(r.stdout).reason, 'install_fault.no_kanban_user');
  assert.equal(r.stderr, `${REFUSAL.error}\n`);
});

test('ssh: ssh itself failing (exit 255) exits 2, names the cause, and prints nothing on stdout', async (t) => {
  const ssh = fakeSsh(t, { exit: 255, stderr: `${TARGET}: Permission denied (publickey).\n` });
  const r = await run(t, START, { env: { ...ssh.env, BRIDGE_TOOLS_SSH_TARGET: TARGET } });

  assert.equal(r.code, 2);
  assert.equal(r.stdout, '');
  assert.match(r.stderr, /exited 255/);
  assert.match(r.stderr, /Permission denied \(publickey\)/);
});

test('ssh: a clean exit with an answer that is not JSON exits 3 (unmeasured)', async (t) => {
  const ssh = fakeSsh(t, { stdout: '<b>Warning</b>: mysqli connect failed' });
  const r = await run(t, START, { env: { ...ssh.env, BRIDGE_TOOLS_SSH_TARGET: TARGET } });

  assert.equal(r.code, 3);
  assert.equal(r.stdout, '');
  assert.match(r.stderr, /mysqli connect failed/);
});

test("ssh: the door's exit 2 (a 5xx-class answer, which includes the 502 that may follow a landed write) exits 3, never 2", async (t) => {
  const ssh = fakeSsh(t, { exit: 2, stdout: JSON.stringify({ ok: false, error: 'upstream board error' }) });
  const r = await run(t, START, { env: { ...ssh.env, BRIDGE_TOOLS_SSH_TARGET: TARGET } });

  assert.equal(r.code, 3);
  assert.equal(r.stdout, '');
  assert.match(r.stderr, /upstream board error/);
});

test('ssh: an ok:true body on a non-zero exit is never reported as success', async (t) => {
  const ssh = fakeSsh(t, { exit: 1, stdout: JSON.stringify(SUCCESS) });
  const r = await run(t, START, { env: { ...ssh.env, BRIDGE_TOOLS_SSH_TARGET: TARGET } });

  assert.equal(r.code, 3);
  assert.equal(r.stdout, '');
});

test('ssh: the recorded key and port reach ssh', async (t) => {
  const ssh = fakeSsh(t, { stdout: JSON.stringify(SUCCESS) });
  const r = await run(t, START, { env: { ...ssh.env, BRIDGE_TOOLS_SSH_TARGET: TARGET, BRIDGE_TOOLS_SSH_KEY: '/k/id', BRIDGE_TOOLS_SSH_PORT: '2222' } });

  assert.equal(r.code, 0, r.stderr);
  const argv = ssh.argv();
  assert.deepEqual(argv.slice(argv.indexOf('-i'), argv.indexOf('-i') + 2), ['-i', '/k/id']);
  assert.deepEqual(argv.slice(argv.indexOf('-p'), argv.indexOf('-p') + 2), ['-p', '2222']);
});

// ---------------------------------------------------------------------------------------------
// The HTTP door

test('http: a 200 ok answer exits 0, with the bearer sent and the response on stdout', async (t) => {
  const door = await fakeDoor(t);
  const r = await run(t, START, { env: { BRIDGE_TOOLS_ENDPOINT: door.url, BRIDGE_TOOLS_TOKEN: 'tkn' } });

  assert.equal(r.code, 0, r.stderr);
  assert.equal(r.stdout, `${JSON.stringify(SUCCESS)}\n`);
  assert.equal(door.seen[0].authorization, 'Bearer tkn');
  assert.deepEqual(door.seen[0].body, { tool: 'board_take_card', args: { card_id: 123, start: true }, caller: 'script', client_version: MANIFEST_VERSION });
});

test('http: a 422 refusal exits 1 with its reason preserved', async (t) => {
  const door = await fakeDoor(t, { status: 422, body: JSON.stringify(REFUSAL) });
  const r = await run(t, START, { env: { BRIDGE_TOOLS_ENDPOINT: door.url, BRIDGE_TOOLS_TOKEN: 'tkn' } });

  assert.equal(r.code, 1);
  assert.equal(JSON.parse(r.stdout).reason, 'install_fault.no_kanban_user');
  assert.equal(r.stderr, `${REFUSAL.error}\n`);
});

for (const [status, error] of [[401, 'unrecognized bearer token'], [403, 'this endpoint is reachable from loopback only'], [503, 'board tools are not fully configured on this bridge (writeback token)']]) {
  test(`http: a ${status} exits 2 (the call reached no tool) and names it`, async (t) => {
    const door = await fakeDoor(t, { status, body: JSON.stringify({ ok: false, error }) });
    const r = await run(t, START, { env: { BRIDGE_TOOLS_ENDPOINT: door.url, BRIDGE_TOOLS_TOKEN: 'tkn' } });

    assert.equal(r.code, 2);
    assert.equal(r.stdout, '');
    assert.match(r.stderr, new RegExp(`HTTP ${status}`));
    assert.ok(r.stderr.includes(error), r.stderr);
  });
}

test('http: an unreachable endpoint exits 2', async (t) => {
  const closed = http.createServer();
  await new Promise((r) => closed.listen(0, '127.0.0.1', r));
  const port = closed.address().port;
  await new Promise((r) => closed.close(r));
  const r = await run(t, START, { env: { BRIDGE_TOOLS_ENDPOINT: `http://127.0.0.1:${port}/agent-tools/call`, BRIDGE_TOOLS_TOKEN: 'tkn' } });

  assert.equal(r.code, 2, r.stderr);
  assert.match(r.stderr, /could not reach/);
});

test('http: a 502 exits 3 (the outcome is not established)', async (t) => {
  const door = await fakeDoor(t, { status: 502, body: JSON.stringify({ ok: false, error: 'upstream board error' }) });
  const r = await run(t, START, { env: { BRIDGE_TOOLS_ENDPOINT: door.url, BRIDGE_TOOLS_TOKEN: 'tkn' } });

  assert.equal(r.code, 3);
  assert.equal(r.stdout, '');
  assert.match(r.stderr, /HTTP 502/);
});

test('http: a 200 whose body is not JSON exits 3', async (t) => {
  const door = await fakeDoor(t, { status: 200, body: '<html>proxy page</html>' });
  const r = await run(t, START, { env: { BRIDGE_TOOLS_ENDPOINT: door.url, BRIDGE_TOOLS_TOKEN: 'tkn' } });

  assert.equal(r.code, 3);
  assert.equal(r.stdout, '');
});

// ---------------------------------------------------------------------------------------------
// Where the transport comes from

test('no transport configured anywhere exits 2 and names what it looked for and where', async (t) => {
  const dir = scratch(t, 'bbc-proj-');
  const r = await run(t, START, { cwd: dir, env: { CLAUDE_PROJECT_DIR: dir } });

  assert.equal(r.code, 2);
  assert.equal(r.stdout, '');
  assert.match(r.stderr, /BRIDGE_TOOLS_SSH_TARGET/);
  assert.match(r.stderr, /BRIDGE_TOOLS_ENDPOINT/);
  assert.ok(r.stderr.includes(path.join(dir, '.mcp.json')), r.stderr);
});

function writeMcp(dir, servers) {
  fs.writeFileSync(path.join(dir, '.mcp.json'), JSON.stringify({ mcpServers: servers }));
}

test("the transport is read from the channel's .mcp.json env, as Claude Code expands it", async (t) => {
  const ssh = fakeSsh(t, { stdout: JSON.stringify(SUCCESS) });
  const dir = scratch(t, 'bbc-proj-');
  writeMcp(dir, {
    other: { command: 'php', args: ['artisan'] },
    seat: { command: 'node', args: ['/x/entry.mjs'], env: { BRIDGE_CHANNEL_NAME: 'seat', BRIDGE_TOOLS_SSH_TARGET: '${TARGET_USER}@bridgehost', BRIDGE_TOOLS_SSH_PORT: '${NO_SUCH_VAR:-2200}' } },
  });
  const r = await run(t, START, { cwd: dir, env: { ...ssh.env, CLAUDE_PROJECT_DIR: dir, TARGET_USER: 'tools' } });

  assert.equal(r.code, 0, r.stderr);
  const argv = ssh.argv();
  assert.equal(argv.at(-1), 'tools@bridgehost');
  assert.deepEqual(argv.slice(argv.indexOf('-p'), argv.indexOf('-p') + 2), ['-p', '2200']);
});

test('--project-dir names the directory whose .mcp.json is read', async (t) => {
  const ssh = fakeSsh(t, { stdout: JSON.stringify(SUCCESS) });
  const dir = scratch(t, 'bbc-proj-');
  writeMcp(dir, { seat: { env: { BRIDGE_TOOLS_SSH_TARGET: TARGET } } });
  const r = await run(t, ['--project-dir', dir, ...START], { env: ssh.env });

  assert.equal(r.code, 0, r.stderr);
  assert.equal(ssh.argv().at(-1), TARGET);
});

test('a reference to an unset variable with no default exits 2, naming it', async (t) => {
  const dir = scratch(t, 'bbc-proj-');
  writeMcp(dir, { seat: { env: { BRIDGE_TOOLS_SSH_TARGET: '${UNSET_IN_THIS_TEST}' } } });
  const r = await run(t, START, { cwd: dir, env: { CLAUDE_PROJECT_DIR: dir } });

  assert.equal(r.code, 2);
  assert.match(r.stderr, /UNSET_IN_THIS_TEST/);
});

test('two channels recording a transport exit 2 naming both; --channel picks one', async (t) => {
  const ssh = fakeSsh(t, { stdout: JSON.stringify(SUCCESS) });
  const dir = scratch(t, 'bbc-proj-');
  writeMcp(dir, { alpha: { env: { BRIDGE_TOOLS_SSH_TARGET: 'a@host' } }, beta: { env: { BRIDGE_TOOLS_SSH_TARGET: 'b@host' } } });

  const ambiguous = await run(t, START, { cwd: dir, env: { ...ssh.env, CLAUDE_PROJECT_DIR: dir } });
  assert.equal(ambiguous.code, 2);
  assert.match(ambiguous.stderr, /alpha/);
  assert.match(ambiguous.stderr, /beta/);
  assert.match(ambiguous.stderr, /--channel/);

  const picked = await run(t, ['--channel', 'beta', ...START], { cwd: dir, env: { ...ssh.env, CLAUDE_PROJECT_DIR: dir } });
  assert.equal(picked.code, 0, picked.stderr);
  assert.equal(ssh.argv().at(-1), 'b@host');
});

test('--channel naming no channel in .mcp.json exits 2', async (t) => {
  const dir = scratch(t, 'bbc-proj-');
  writeMcp(dir, { alpha: { env: { BRIDGE_TOOLS_SSH_TARGET: 'a@host' } } });
  const r = await run(t, ['--channel', 'gamma', ...START], { cwd: dir, env: { CLAUDE_PROJECT_DIR: dir } });

  assert.equal(r.code, 2);
  assert.match(r.stderr, /gamma/);
});

test('both transports set exits 2 and sends nothing', async (t) => {
  const ssh = fakeSsh(t, { stdout: JSON.stringify(SUCCESS) });
  const r = await run(t, START, { env: { ...ssh.env, BRIDGE_TOOLS_SSH_TARGET: TARGET, BRIDGE_TOOLS_ENDPOINT: 'http://127.0.0.1:1/agent-tools/call' } });

  assert.equal(r.code, 2);
  assert.throws(() => ssh.argv(), 'ssh was never run');
});

test('an HTTP endpoint with no bearer exits 2, naming the bearer settings', async (t) => {
  const r = await run(t, START, { env: { BRIDGE_TOOLS_ENDPOINT: 'http://127.0.0.1:1/agent-tools/call' } });

  assert.equal(r.code, 2);
  assert.match(r.stderr, /BRIDGE_TOOLS_TOKEN/);
});

test('board tools switched off for the channel (BRIDGE_CHANNEL_TOOLS=0) exits 2 and sends nothing', async (t) => {
  const ssh = fakeSsh(t, { stdout: JSON.stringify(SUCCESS) });
  const r = await run(t, START, { env: { ...ssh.env, BRIDGE_TOOLS_SSH_TARGET: TARGET, BRIDGE_CHANNEL_TOOLS: '0' } });

  assert.equal(r.code, 2);
  assert.match(r.stderr, /BRIDGE_CHANNEL_TOOLS/);
  assert.throws(() => ssh.argv(), 'ssh was never run');
});

// ---------------------------------------------------------------------------------------------
// Usage

for (const [label, argv] of [
  ['no tool', []],
  ['arguments that are not JSON', ['board_take_card', '{card_id:123}']],
  ['arguments that are a JSON array', ['board_take_card', '[1]']],
  ['an unknown option', ['--agent', 'x', 'board_take_card']],
]) {
  test(`usage: ${label} exits 4 and sends nothing`, async (t) => {
    const ssh = fakeSsh(t, { stdout: JSON.stringify(SUCCESS) });
    const r = await run(t, argv, { env: { ...ssh.env, BRIDGE_TOOLS_SSH_TARGET: TARGET } });

    assert.equal(r.code, 4);
    assert.match(r.stderr, /usage/);
    assert.throws(() => ssh.argv(), 'ssh was never run');
  });
}

test('omitted arguments are sent as {}', async (t) => {
  const ssh = fakeSsh(t, { stdout: JSON.stringify({ ok: true, tool: 'board_my_cards', result: {} }) });
  const r = await run(t, ['board_my_cards'], { env: { ...ssh.env, BRIDGE_TOOLS_SSH_TARGET: TARGET } });

  assert.equal(r.code, 0, r.stderr);
  assert.deepEqual(ssh.sent().args, {});
});

// ---------------------------------------------------------------------------------------------
// The two declarations of a client program: package.json `bin` (what npm links) and the file under
// bin/ (what the client updater shims, from the release's FILES.json). They must name the same set.

test('package.json bin and the programs under bin/ name the same set', () => {
  const pkg = JSON.parse(fs.readFileSync(path.join(HERE, '..', 'package.json'), 'utf8'));
  const declared = Object.entries(pkg.bin)
    .filter(([, file]) => file.replace(/^\.\//, '').startsWith('bin/'))
    .map(([name, file]) => [name, file.replace(/^\.\//, '')])
    .sort();
  const present = fs
    .readdirSync(path.join(HERE, '..', 'bin'))
    .filter((f) => f.endsWith('.mjs'))
    .map((f) => [f.replace(/\.mjs$/, ''), `bin/${f}`])
    .sort();

  assert.ok(present.length > 0, 'no program under bin/ — this check has stopped measuring');
  assert.deepEqual(declared, present, 'a bin/ program is shimmed on a seat under its name without .mjs; package.json must declare that same name');
});

// ---------------------------------------------------------------------------------------------
// Review round 1 (PR #852): exit 2 only where nothing can have reached a tool; nothing secret on
// an output stream; a deadline a hook outlives.

/** A local door whose handler the test writes; resolves its URL. */
async function rawDoor(t, handler) {
  const server = http.createServer(handler);
  await new Promise((r) => server.listen(0, '127.0.0.1', r));
  t.after(() => server.close());
  return `http://127.0.0.1:${server.address().port}/agent-tools/call`;
}

test('http: a connection reset AFTER the bridge received the request exits 3, never 2', async (t) => {
  const url = await rawDoor(t, (req) => {
    req.on('data', () => {});
    req.on('end', () => req.socket.destroy());
  });
  const r = await run(t, START, { env: { BRIDGE_TOOLS_ENDPOINT: url, BRIDGE_TOOLS_TOKEN: 'tkn' } });

  assert.equal(r.code, 3, r.stderr);
  assert.equal(r.stdout, '');
});

test('http: a 200 whose body is cut off exits 3, never 2', async (t) => {
  const url = await rawDoor(t, (req, res) => {
    req.on('data', () => {});
    req.on('end', () => {
      res.writeHead(200, { 'Content-Type': 'application/json', 'Content-Length': '200' });
      res.write('{"ok":tr');
      setTimeout(() => req.socket.destroy(), 50);
    });
  });
  const r = await run(t, START, { env: { BRIDGE_TOOLS_ENDPOINT: url, BRIDGE_TOOLS_TOKEN: 'tkn' } });

  assert.equal(r.code, 3, r.stderr);
  assert.equal(r.stdout, '');
  assert.match(r.stderr, /HTTP 200/);
});

test('http: a redirect is refused before any tool, so it exits 2', async (t) => {
  const url = await rawDoor(t, (req, res) => {
    res.writeHead(307, { Location: 'http://127.0.0.1:1/elsewhere' });
    res.end();
  });
  const r = await run(t, START, { env: { BRIDGE_TOOLS_ENDPOINT: url, BRIDGE_TOOLS_TOKEN: 'tkn' } });

  assert.equal(r.code, 2, r.stderr);
  assert.match(r.stderr, /redirect/);
});

test('http: an answer slower than --deadline-ms exits 3 at the deadline', async (t) => {
  const url = await rawDoor(t, () => {});
  const started = Date.now();
  const r = await run(t, ['--deadline-ms', '300', ...START], { env: { BRIDGE_TOOLS_ENDPOINT: url, BRIDGE_TOOLS_TOKEN: 'tkn' } });

  assert.equal(r.code, 3, r.stderr);
  assert.match(r.stderr, /300ms/);
  assert.ok(Date.now() - started < 10000, 'the deadline, not the default, ended the call');
});

for (const bad of ['0', '-5', 'abc', '600001']) {
  test(`usage: --deadline-ms ${bad} exits 4`, async (t) => {
    const r = await run(t, ['--deadline-ms', bad, ...START], { env: { BRIDGE_TOOLS_SSH_TARGET: TARGET } });

    assert.equal(r.code, 4);
  });
}

// A canary, not a credential: low-entropy on purpose, so the secret scanner has nothing to flag.
const CANARY = 'leakcanary-leakcanary';

test('a malformed .mcp.json never echoes its content (a token in it must not reach stdout or stderr)', async (t) => {
  const dir = scratch(t, 'bbc-proj-');
  // Unquoted and smart-quoted values: V8's JSON.parse message quotes the input around the error.
  fs.writeFileSync(path.join(dir, '.mcp.json'), `{"mcpServers":{"seat":{"env":{"BRIDGE_TOOLS_TOKEN": ${CANARY}, "X": “${CANARY}”}}}}`);
  const r = await run(t, START, { cwd: dir, env: { CLAUDE_PROJECT_DIR: dir } });

  assert.equal(r.code, 2);
  assert.match(r.stderr, /not valid JSON/);
  assert.ok(!(r.stdout + r.stderr).includes('leakcanary'), r.stderr);
});

test('a credential in the endpoint URL is never printed', async (t) => {
  const closed = http.createServer();
  await new Promise((r) => closed.listen(0, '127.0.0.1', r));
  const port = closed.address().port;
  await new Promise((r) => closed.close(r));
  const r = await run(t, START, { env: { BRIDGE_TOOLS_ENDPOINT: `http://user:${CANARY}@127.0.0.1:${port}/agent-tools/call`, BRIDGE_TOOLS_TOKEN: 'tkn' } });

  assert.equal(r.code, 2, r.stderr);
  assert.ok(!(r.stdout + r.stderr).includes('leakcanary'), r.stderr);
});

for (const [stderr, code, label] of [
  ['ssh: connect to host bridgehost port 22: Connection refused\n', 2, 'connection refused'],
  ['ssh: Could not resolve hostname bridgehost: Name or service not known\n', 2, 'no such host'],
  ['Host key verification failed.\n', 2, 'host key'],
  ['Connection to bridgehost closed by remote host.\n', 3, 'a session the far end closed'],
  ['client_loop: send disconnect: Broken pipe\n', 3, 'a broken pipe mid-session'],
  ['', 3, 'no stderr at all'],
]) {
  test(`ssh: exit 255 with ${label} exits ${code}`, async (t) => {
    const ssh = fakeSsh(t, { exit: 255, stderr });
    const r = await run(t, START, { env: { ...ssh.env, BRIDGE_TOOLS_SSH_TARGET: TARGET } });

    assert.equal(r.code, code, r.stderr);
    assert.equal(r.stdout, '');
  });
}
