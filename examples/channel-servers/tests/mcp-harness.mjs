// Shared harness for the suites that drive the REAL channel server over stdio.
//
// Extracted at the second caller (canon #5): clear-context.test.mjs had the only copy
// until ssh-transport-failure.test.mjs (card#7709) needed the same two pieces — a
// self-cleaning scratch dir, and "spawn the shipped entry point and hand back a
// connected MCP client". A second divergent copy of the spawn env is exactly how one
// suite ends up testing a server configuration the other one no longer uses.
//
// This module is a HELPER, not a test file: it registers no tests and has no
// import-time side effects, so it does not disturb the rule that
// `import './live-state-guard.mjs'` is the FIRST import of every *.test.mjs here.
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { StdioClientTransport } from '@modelcontextprotocol/sdk/client/stdio.js';

export const SERVER = path.join(
  path.dirname(fileURLToPath(import.meta.url)),
  '..',
  'agent-webhook-bridge-channel.mjs',
);

/** A temp scratch dir that is cleaned up when the test ends. */
export function scratch(t, prefix) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), prefix));
  t.after(() => fs.rmSync(dir, { recursive: true, force: true }));
  return dir;
}

// Spawn the real channel server and connect an MCP client to it over stdio. `env` is
// merged over a minimal base that gives the server a resolvable unix channel socket
// (so it does not refuse-and-exit before the MCP handshake). The client + server are
// torn down when the test ends.
export async function connectServer(t, env, opts = {}) {
  const runtime = scratch(t, opts.runtimePrefix || 'chan-rt-');
  const name = opts.name || 'harness-test';
  const transport = new StdioClientTransport({
    command: process.execPath,
    args: [SERVER],
    stderr: 'ignore',
    env: {
      PATH: process.env.PATH,
      BRIDGE_CHANNEL_TRANSPORT: 'unix',
      BRIDGE_CHANNEL_SOCKET: path.join(runtime, 'chan.sock'),
      BRIDGE_CHANNEL_NAME: name,
      ...env,
    },
  });
  const client = new Client({ name: `${name}-client`, version: '1.0.0' }, { capabilities: {} });
  await client.connect(transport);
  t.after(() => client.close());
  return client;
}
