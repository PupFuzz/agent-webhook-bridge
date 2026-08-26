// Direct unit tests for the pure helpers extracted into ../channel-lib.mjs.
//
// These functions could not be unit-tested before: they lived in
// agent-webhook-bridge-channel.mjs, which self-executes on import (it binds a real
// transport and calls process.exit on refuse paths), so importing it to reach the
// helpers is impossible. The extraction (card #5072) makes them importable here.
// The child-process marker-refusal suite (marker-refusal.test.mjs) still exercises
// the real server end-to-end, proving no runtime-behavior regression.
//
// Run: `node --test examples/channel-servers/tests/`.
import './live-state-guard.mjs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { scrubSnippet, relayBridgeResponse, deriveMeta } from '../channel-lib.mjs';

// ---------------------------------------------------------------------------
// scrubSnippet — credential redaction + truncation
// ---------------------------------------------------------------------------

test('scrubSnippet redacts an Authorization line', () => {
  const out = scrubSnippet('oops\nAuthorization: Bearer sk-secret-value\nmore');
  assert.doesNotMatch(out, /sk-secret-value/, 'the bearer value must not survive');
  assert.match(out, /\[redacted\]/);
  // Redaction stops at the newline — text before/after the credential line is kept.
  assert.match(out, /^oops\n/);
  assert.match(out, /\nmore$/);
});

test('scrubSnippet redacts a bare "bearer <token>" substring case-insensitively', () => {
  const out = scrubSnippet('BEARER abc123def');
  assert.doesNotMatch(out, /abc123def/);
  assert.match(out, /\[redacted\]/);
});

test('scrubSnippet redacts every credential line, not just the first', () => {
  const out = scrubSnippet('authorization: one\nkeep\nBearer two');
  assert.doesNotMatch(out, /one/);
  assert.doesNotMatch(out, /two/);
  assert.match(out, /keep/);
});

test('scrubSnippet truncates to 500 characters', () => {
  const out = scrubSnippet('x'.repeat(1000));
  assert.equal(out.length, 500);
});

test('scrubSnippet coerces a non-string argument via String()', () => {
  assert.equal(scrubSnippet(12345), '12345');
  assert.equal(scrubSnippet(null), 'null');
});

test('scrubSnippet leaves a clean snippet untouched', () => {
  assert.equal(scrubSnippet('nothing sensitive here'), 'nothing sensitive here');
});

// ---------------------------------------------------------------------------
// relayBridgeResponse — leg-supplied success signal, never body-inferred
// ---------------------------------------------------------------------------

test('relayBridgeResponse: valid JSON + successful leg => isError false, body passed through verbatim', () => {
  const body = '{"cards":[1,2,3]}';
  const r = relayBridgeResponse(body, true, 'http://endpoint');
  assert.equal(r.isError, false);
  assert.deepEqual(r.content, [{ type: 'text', text: body }]);
});

test('relayBridgeResponse: valid JSON but FAILED leg => isError true (success is leg-supplied, not body-inferred)', () => {
  // A well-formed JSON body with a non-ok leg (e.g. HTTP 4xx/5xx that still
  // returned JSON) must be flagged as an error — the body parsing alone is not
  // permission to call it a success.
  const body = '{"error":"forbidden"}';
  const r = relayBridgeResponse(body, false, 'http://endpoint');
  assert.equal(r.isError, true);
  assert.deepEqual(r.content, [{ type: 'text', text: body }]);
});

test('relayBridgeResponse: non-JSON body => isError true regardless of a "successful" leg', () => {
  // A 200 (legSuccess=true) with a php-warning-prepended, non-JSON body is a
  // CORRUPT result, not a silent success.
  const r = relayBridgeResponse('<b>Warning</b> not json', true, 'ssh user@host');
  assert.equal(r.isError, true);
  assert.equal(r.content[0].type, 'text');
  assert.match(r.content[0].text, /non-JSON response from the bridge \(ssh user@host\)/);
});

test('relayBridgeResponse: non-JSON body scrubs credentials in the diagnostic snippet', () => {
  const r = relayBridgeResponse('trace\nAuthorization: Bearer leaked-token\n', true, 'src');
  assert.equal(r.isError, true);
  assert.doesNotMatch(r.content[0].text, /leaked-token/);
  assert.match(r.content[0].text, /\[redacted\]/);
});

test('relayBridgeResponse: non-JSON snippet is truncated to the scrubSnippet 500-char bound', () => {
  const r = relayBridgeResponse('y'.repeat(2000), true, 'src');
  assert.equal(r.isError, true);
  // The label prefix + the 500-char (max) snippet; the raw body must not appear whole.
  assert.ok(r.content[0].text.includes('y'.repeat(500)));
  assert.ok(!r.content[0].text.includes('y'.repeat(501)));
});

// ---------------------------------------------------------------------------
// relayBridgeResponse — the leg diagnostic on the parse-failure branch (card#7709).
// The end-to-end pairing with the real ssh call site (exit code + stderr capture) is
// ssh-transport-failure.test.mjs; these pin the relay contract itself.
// ---------------------------------------------------------------------------

test('relayBridgeResponse: FAILED leg + a diagnostic + EMPTY body => the diagnostic, not an empty snippet', () => {
  // The field case (card#7709): before the leg signal was reachable here this returned
  // `non-JSON response from the bridge (ssh h): ` with nothing after the colon.
  const r = relayBridgeResponse('', false, 'ssh h', 'ssh exited 255: Permission denied (publickey).');
  assert.equal(r.isError, true);
  assert.equal(r.content[0].text, 'the ssh h leg FAILED: ssh exited 255: Permission denied (publickey).');
});

test('relayBridgeResponse: FAILED leg + a diagnostic + non-JSON body => both the diagnostic and the partial output', () => {
  const r = relayBridgeResponse('half a line', false, 'ssh h', 'ssh exited 1');
  assert.equal(r.isError, true);
  assert.equal(r.content[0].text, 'the ssh h leg FAILED: ssh exited 1 | partial output: half a line');
});

test('relayBridgeResponse: CLEAN leg + non-JSON body keeps the snippet message even when a diagnostic is passed', () => {
  // The preserved half of the distinction: a transport that worked and a bridge that
  // answered with garbage must keep saying what it always said. Only `legSuccess`
  // opens the diagnostic arm, so a caller that supplies one anyway cannot mislabel a
  // healthy leg as a dead transport.
  const r = relayBridgeResponse('<b>Warning</b> not json', true, 'ssh h', 'ssh exited 0');
  assert.equal(r.content[0].text, 'non-JSON response from the bridge (ssh h): <b>Warning</b> not json');
});

test('relayBridgeResponse: FAILED leg with NO diagnostic keeps the snippet message (the HTTP non-ok body IS the diagnosis)', () => {
  const r = relayBridgeResponse('<html>502 Bad Gateway</html>', false, 'http://endpoint');
  assert.equal(r.isError, true);
  assert.equal(
    r.content[0].text,
    'non-JSON response from the bridge (http://endpoint): <html>502 Bad Gateway</html>',
  );
});

test('relayBridgeResponse: a credential in the leg diagnostic is scrubbed', () => {
  // stderr from a failing transport is unvetted text; it goes through the same scrub
  // as a body (canon #20).
  const r = relayBridgeResponse('', false, 'ssh h', 'ssh exited 255: Authorization: Bearer sk-leaked-7709');
  assert.doesNotMatch(r.content[0].text, /sk-leaked-7709/);
  assert.match(r.content[0].text, /\[redacted\]/);
});

test('relayBridgeResponse: the leg diagnostic is truncated to the scrubSnippet 500-char bound', () => {
  const r = relayBridgeResponse('', false, 'ssh h', 'q'.repeat(2000));
  assert.ok(r.content[0].text.includes('q'.repeat(500)));
  assert.ok(!r.content[0].text.includes('q'.repeat(501)));
});

test('relayBridgeResponse: a diagnostic never reaches a body that PARSED — the leg signal alone decides', () => {
  const body = '{"error":"forbidden"}';
  const r = relayBridgeResponse(body, false, 'ssh h', 'ssh exited 3');
  assert.equal(r.isError, true);
  assert.deepEqual(r.content, [{ type: 'text', text: body }]);
});

// ---------------------------------------------------------------------------
// deriveMeta — envelope parsing, best-effort (never throws)
// ---------------------------------------------------------------------------

test('deriveMeta extracts kind + target_id from a well-formed intent envelope', () => {
  const body = JSON.stringify({ intent: { kind: 'card_updated', target_id: '4719' } });
  assert.deepEqual(deriveMeta(body), { kind: 'card_updated', target_id: '4719' });
});

test('deriveMeta includes only the string-typed keys present', () => {
  assert.deepEqual(
    deriveMeta(JSON.stringify({ intent: { kind: 'card_assigned' } })),
    { kind: 'card_assigned' },
  );
  assert.deepEqual(
    deriveMeta(JSON.stringify({ intent: { target_id: 'abc' } })),
    { target_id: 'abc' },
  );
});

test('deriveMeta ignores non-string kind/target_id (type-guarded)', () => {
  const body = JSON.stringify({ intent: { kind: 42, target_id: { nested: true } } });
  assert.deepEqual(deriveMeta(body), {});
});

test('deriveMeta returns an empty object when there is no intent object', () => {
  assert.deepEqual(deriveMeta(JSON.stringify({ intent: null })), {});
  assert.deepEqual(deriveMeta(JSON.stringify({ intent: 'not-an-object' })), {});
  assert.deepEqual(deriveMeta(JSON.stringify({ other: 1 })), {});
});

test('deriveMeta returns an empty object for non-JSON input (never throws)', () => {
  assert.deepEqual(deriveMeta('this is not json'), {});
  assert.deepEqual(deriveMeta(''), {});
});

test('deriveMeta returns an empty object for a JSON scalar (no intent property)', () => {
  assert.deepEqual(deriveMeta('42'), {});
  assert.deepEqual(deriveMeta('"a string"'), {});
  assert.deepEqual(deriveMeta('null'), {});
});
