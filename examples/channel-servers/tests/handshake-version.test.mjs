// The version the channel server announces in its MCP `initialize` handshake.
//
// It is the SAME once-read manifest version the server sends as `client_version` on every
// board-tools call (card#8974 / DL-364) — one source of truth, the sibling package.json the
// DL-038 bump guard maintains; a literal here would be a second copy free to drift, and
// serverInfo would then disagree with what the bridge is sent.
//
// Run: `node --test examples/channel-servers/tests/`.
import './live-state-guard.mjs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { SERVER, connectServer } from './mcp-harness.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const MANIFEST_VERSION = JSON.parse(
  fs.readFileSync(path.join(HERE, '..', 'package.json'), 'utf8'),
).version;

test('the handshake announces the package.json version', async (t) => {
  const client = await connectServer(t, {});

  assert.equal(client.getServerVersion().version, MANIFEST_VERSION);
});

// A copy of the server with NO manifest beside it. The copy lives inside this package so
// its bare `@modelcontextprotocol/sdk` import still resolves through ../node_modules; only
// the manifest read is taken away.
test('an unreadable manifest announces the explicit sentinel, never a plausible version', async (t) => {
  const dir = fs.mkdtempSync(path.join(HERE, '.handshake-no-manifest-'));
  t.after(() => fs.rmSync(dir, { recursive: true, force: true }));
  const server = path.join(dir, path.basename(SERVER));
  fs.copyFileSync(SERVER, server);
  fs.copyFileSync(path.join(path.dirname(SERVER), 'channel-lib.mjs'), path.join(dir, 'channel-lib.mjs'));

  const client = await connectServer(t, {}, { server });

  assert.equal(client.getServerVersion().version, '0.0.0-unreadable-manifest');
});
