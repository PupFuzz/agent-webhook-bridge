// The ADVERTISED `board_my_cards` input schema, checked against what the bridge actually
// accepts — the declaring end owning a check that its own declaration is true.
//
// ⭐ WHY THIS FILE EXISTS. This directory is a SNAPSHOT consumers COPY, and the schema in
// it is the only thing an MCP client reads to decide which arguments a model may send. The
// bridge is the validator, but a schema that omits an argument makes that argument
// undiscoverable, and a schema that advertises one the bridge refuses turns a model's
// correct call into a 422. Nothing in this repo compared the two, so the declaration could
// drift from the code it describes with everything green — and the drift is invisible on
// this side of the copy, which is exactly the seam a guard has to cover rather than an
// intra-repo audit.
//
// ⛔ THE `anyOf` SPELLING IS LOAD-BEARING AND WAS MEASURED. `type: ['integer','string']` —
// the compact JSON Schema union — is REFUSED AT COMPILE TIME by a strict validator ("use
// allowUnionTypes to allow union type keyword"), and a client that cannot compile this
// schema does not lose one argument, it loses the whole tool, for every seat that
// re-copies this directory. The final assertion below is a CLASS guard against
// re-introducing that shape anywhere in the schema, not just on `stage`.
//
// ⚠ WHAT THIS DOES NOT CLOSE: it reads the schema THIS server advertises. It cannot prove
// any particular MCP client accepts it, and it cannot see the bridge's PHP refusals — the
// two ends are held together by the argument names below plus `docs/board-tools.md`, not by
// a shared artifact. What it does buy is that the advertised surface cannot change without
// this file changing with it.
import './live-state-guard.mjs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { connectServer } from './mcp-harness.mjs';

const serverOpts = { name: 'schema-test-client', version: '0.0.0' };

/** The tools the server advertises, over a REAL tools/list through the real SDK client. */
async function advertisedTools(t) {
  const client = await connectServer(
    t,
    { BRIDGE_TOOLS_ENDPOINT: 'http://127.0.0.1:1/agent-tools/call', BRIDGE_TOOLS_TOKEN: 'test-bearer' },
    serverOpts,
  );
  const { tools } = await client.listTools();

  return tools;
}

test('board_my_cards advertises exactly the arguments the bridge accepts', async (t) => {
  const tools = await advertisedTools(t);
  const def = tools.find((x) => x.name === 'board_my_cards');
  assert.ok(def, `board_my_cards must be advertised; got ${JSON.stringify(tools.map((x) => x.name))}`);

  // The ARGUMENT SET, pinned by name. `board_my_cards` takes no required argument: every
  // one of these is optional, which is what keeps the DEFAULT call — the one a seat makes
  // to orient — a bare `{}`.
  assert.deepEqual(
    Object.keys(def.inputSchema.properties).sort(),
    ['include_description', 'limit', 'stage'],
    'the advertised argument set drifted from the bridge tool',
  );
  assert.equal(def.inputSchema.type, 'object');
  assert.equal(def.inputSchema.additionalProperties, false);
  assert.equal(def.inputSchema.required, undefined, 'no argument is required');

  // The TYPES, which are what a client validates a model's call against.
  assert.deepEqual(def.inputSchema.properties.include_description.type, 'boolean');
  assert.deepEqual(def.inputSchema.properties.limit.type, 'integer');
  assert.equal(def.inputSchema.properties.limit.minimum, 1, 'the bridge refuses limit < 1');
  assert.deepEqual(
    def.inputSchema.properties.stage.anyOf,
    [{ type: 'integer' }, { type: 'string' }],
    'stage is a numeric stage id OR a stage name, and must say so in the spelling a strict validator compiles',
  );

  // Every advertised argument carries a description: the description is the only place a
  // model learns that stage is refused rather than guessed, and that limit costs response
  // size. A typed-but-undescribed argument is a schema a model cannot use correctly.
  for (const [name, prop] of Object.entries(def.inputSchema.properties)) {
    assert.equal(typeof prop.description, 'string', `${name} must be described`);
    assert.ok(prop.description.length > 0, `${name} must be described`);
  }
});

test('no advertised schema uses an array-valued type anywhere', async (t) => {
  // The CLASS guard. Measured: a strict validator refuses `type: [...]` at compile time,
  // so one of them anywhere in this file can cost a consumer every tool in it — not only
  // the one that carries it. Scanned over EVERY advertised tool, so the next multi-type
  // argument added to any of them reds here rather than at a seat.
  const tools = await advertisedTools(t);
  assert.ok(tools.length > 0, 'no tools advertised — this test would be vacuous');

  const offenders = [];
  const walk = (node, path) => {
    if (Array.isArray(node) || node === null || typeof node !== 'object') {
      return;
    }
    if (Array.isArray(node.type)) {
      offenders.push(path);
    }
    for (const [k, v] of Object.entries(node)) {
      walk(v, `${path}.${k}`);
    }
  };
  for (const tool of tools) {
    walk(tool.inputSchema, tool.name);
  }

  assert.deepEqual(offenders, [], 'use anyOf: [{type: ...}, ...] — an array-valued `type` is refused at compile time by a strict validator, which drops the whole tool list');
});
