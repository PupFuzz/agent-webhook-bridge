// End-to-end tests for what a FAILED ssh board-tools leg tells the calling agent
// (card#7709).
//
// The defect these pin: the ssh call site computed `code === 0` and handed it to
// `relayBridgeResponse`, which parsed the body FIRST and returned its non-JSON snippet
// arm before ever reading that leg signal. With the transport down — non-zero exit,
// empty stdout — the agent received `non-JSON response from the bridge (ssh <target>):`
// and NOTHING after the colon, because `scrubSnippet('')` is `''`. The stderr naming
// the cause (`Permission denied (publickey)`, `Connection refused`, `Host key
// verification failed`) went only to `console.error`, i.e. the MCP client's server log,
// which is not a surface an agent reads. One install ran a board tool dead for 10 days
// without being able to name why.
//
// These cases drive the REAL server over stdio with a FAKE `ssh` first on PATH, so the
// whole seam is exercised — exit code and stderr capture at the call site, and the relay
// contract in channel-lib.mjs — not a re-implementation of either half. The fake reads
// its exit code / stdout / stderr from the environment the server inherits.
//
// The pairing is the point, and BOTH halves must hold:
//   - transport failed (exit != 0) ⇒ the result names the exit code and the stderr.
//   - transport fine (exit 0) but the body is garbage ⇒ the ORIGINAL snippet message,
//     with no transport note folded in. `nonJsonMessage()` below is the byte-for-byte
//     expectation, so a "fix" that made both branches say the same new thing — the same
//     defect pointing the other way — fails here.
//
// Run: `node --test examples/channel-servers/tests/`.
import './live-state-guard.mjs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { scratch, connectServer } from './mcp-harness.mjs';

const TARGET = 'bridge@testhost';
const serverOpts = { name: 'sshfail-test', runtimePrefix: 'sshfail-rt-' };

// The message the relay has always produced for a body it could not parse, rebuilt
// here from its two inputs. This is the PRESERVED behaviour: it is what a reachable
// bridge answering with a php-warning page must still say.
function nonJsonMessage(body) {
  return `non-JSON response from the bridge (ssh ${TARGET}): ${body}`;
}

// Install a fake `ssh` that drains the {tool,args} payload on stdin, then emits the
// stdout/stderr/exit code the test asked for. Draining matters: an ssh that exits
// without reading stdin would EPIPE the server's write.
function fakeSsh(t) {
  const binDir = scratch(t, 'sshfail-bin-');
  fs.writeFileSync(
    path.join(binDir, 'ssh'),
    '#!/usr/bin/env bash\n' +
      'cat > /dev/null\n' +
      'printf "%s" "$FAKE_SSH_STDOUT"\n' +
      'printf "%s" "$FAKE_SSH_STDERR" >&2\n' +
      'exit "${FAKE_SSH_EXIT:-0}"\n',
    { mode: 0o755 },
  );
  return binDir;
}

// Call board_my_cards through the real server against the fake ssh leg.
async function callOverFakeSsh(t, { exit = 0, stdout = '', stderr = '' }) {
  const binDir = fakeSsh(t);
  const client = await connectServer(
    t,
    {
      PATH: `${binDir}${path.delimiter}${process.env.PATH}`,
      BRIDGE_TOOLS_SSH_TARGET: TARGET,
      FAKE_SSH_EXIT: String(exit),
      FAKE_SSH_STDOUT: stdout,
      FAKE_SSH_STDERR: stderr,
    },
    serverOpts,
  );
  return await client.callTool({ name: 'board_my_cards', arguments: {} });
}

// (a) THE FIELD CASE — transport down: non-zero exit, nothing on stdout. Before the
// fix this returned the snippet message with an empty snippet.
test('ssh exits non-zero with EMPTY stdout => the result names the exit code and the stderr', async (t) => {
  const res = await callOverFakeSsh(t, {
    exit: 255,
    stderr: `${TARGET}: Permission denied (publickey).\n`,
  });

  assert.equal(res.isError, true);
  const text = res.content[0].text;
  assert.match(text, /ssh exited 255/, `must name the exit code; got: ${JSON.stringify(text)}`);
  assert.match(text, /Permission denied \(publickey\)/, `must name the cause; got: ${JSON.stringify(text)}`);
  // The pre-fix output, exactly: the snippet message with nothing after the colon.
  assert.notEqual(text.trim(), nonJsonMessage('').trim());
});

// (b) Transport down with the far end having written something non-JSON first. The
// exit code and stderr still lead; the partial output is kept, not thrown away.
test('ssh exits non-zero with NON-JSON stdout => names the exit code, the stderr, and the partial output', async (t) => {
  const res = await callOverFakeSsh(t, {
    exit: 1,
    stdout: 'bash: bridge: command not found',
    stderr: 'forced command failed\n',
  });

  assert.equal(res.isError, true);
  const text = res.content[0].text;
  assert.match(text, /ssh exited 1/);
  assert.match(text, /forced command failed/);
  assert.match(text, /bash: bridge: command not found/, 'the partial output is diagnostic too');
});

// (c) PRESERVED BEHAVIOUR — a healthy transport (exit 0) and a bridge that answered
// with garbage is a DIFFERENT failure, and its message must not change. stderr is
// non-empty here on purpose: on this branch it is noise, and folding it in would
// re-mint the collapse this card fixed, pointing the other way.
test('ssh exits 0 with NON-JSON stdout => the original snippet message, byte for byte', async (t) => {
  const body = '<b>Warning</b>: mysqli connect failed';
  const res = await callOverFakeSsh(t, {
    exit: 0,
    stdout: body,
    stderr: 'Warning: Permanently added a host key\n',
  });

  assert.equal(res.isError, true);
  assert.equal(res.content[0].text, nonJsonMessage(body));
});

// (d) The healthy path stays healthy: exit 0 + valid JSON is relayed verbatim.
test('ssh exits 0 with valid JSON => isError false and the body is relayed verbatim', async (t) => {
  const body = '{"cards":[{"id":7709}]}';
  const res = await callOverFakeSsh(t, { exit: 0, stdout: body });

  assert.notEqual(res.isError, true);
  assert.equal(res.content[0].text, body);
});

// A failed leg whose body IS valid JSON keeps relaying that body — the leg signal
// alone makes it isError, as it always has.
test('ssh exits non-zero with valid JSON => isError true with the body relayed verbatim', async (t) => {
  const body = '{"error":"forbidden"}';
  const res = await callOverFakeSsh(t, { exit: 3, stdout: body, stderr: 'noise\n' });

  assert.equal(res.isError, true);
  assert.equal(res.content[0].text, body);
});

// SECRET HYGIENE (canon #20): stderr is unvetted text that now reaches a tool result,
// so a credential in it must not survive the trip.
test('a token-shaped credential in ssh stderr does NOT survive into the tool result', async (t) => {
  const res = await callOverFakeSsh(t, {
    exit: 255,
    stderr: 'debug1: sending\nAuthorization: Bearer sk-live-DO-NOT-LEAK-7709\nPermission denied.\n',
  });

  const text = res.content[0].text;
  assert.doesNotMatch(text, /sk-live-DO-NOT-LEAK-7709/, 'the bearer value must not reach the caller');
  assert.match(text, /\[redacted\]/);
  assert.match(text, /ssh exited 255/, 'the scrub must not cost the diagnosis');
});

// The relayed stderr is length-bounded, so a chatty failing leg cannot flood the
// caller's context with the whole transcript.
test('a flooding ssh stderr is truncated in the tool result', async (t) => {
  const res = await callOverFakeSsh(t, { exit: 255, stderr: 'z'.repeat(9000) });

  const text = res.content[0].text;
  assert.ok(text.includes('z'.repeat(400)), 'enough of the stderr is kept to be diagnostic');
  // The whole diagnostic — the `ssh exited N: ` prefix included — goes through
  // scrubSnippet's 500-char bound, so the kept run is shorter than 500 by that prefix.
  assert.ok(!text.includes('z'.repeat(500)), `stderr must be bounded; length was ${text.length}`);
  assert.ok(text.length < 600, `the whole result stays bounded; length was ${text.length}`);
});
