// Fixtures for client-update.test.mjs: client packs built in memory, and a local fixture
// bridge serving the client-update door (card#10568).
//
// This module is a HELPER, not a test file: it registers no tests and has no import-time side
// effects, so `import './live-state-guard.mjs'` stays the first import of the test file.
//
// ⚑ THE PACK IS BUILT HERE, NOT BY bin/build-client-pack.py, because the refusal cases need packs
// that builder will never produce (a symlink, `../x`, a FILES.json that lies). The GOOD pack has
// the builder's layout and manifest format — `client/…`, `seat-tools/bin/…`, `FILES.json`,
// ustar, `{schema, kind, bridge_release, minted_from_commit, client_version, node_engines, pack,
// files_json_sha256}` — and bin/test_build_client_pack.py is where the builder's own output is
// held to that format. The client files are the REAL entry.mjs, client-update.mjs and
// channel-lib.mjs from this directory; the channel server is a stub that records which release
// ran (or, with `realServer`, the real one).
import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import crypto from 'node:crypto';
import zlib from 'node:zlib';
import { fileURLToPath } from 'node:url';

export const SOURCE_DIR = path.join(path.dirname(fileURLToPath(import.meta.url)), '..');

export function sha256(bytes) {
  return crypto.createHash('sha256').update(bytes).digest('hex');
}

/** A stub channel server: writes what it was started with to $TEST_RECORD, then exits. */
export function stubServer(release) {
  return (
    "import fs from 'node:fs';\n" +
    'fs.appendFileSync(process.env.TEST_RECORD, JSON.stringify({' +
    `release: ${JSON.stringify(release)}, ` +
    'root: process.env.AWB_CLIENT_ROOT, launch: process.env.AWB_LAUNCH_ID, bridge_release: process.env.AWB_BRIDGE_RELEASE' +
    "}) + '\\n');\n"
  );
}

function octalField(value, length) {
  return `${value.toString(8).padStart(length - 1, '0')}\0`;
}

/** One ustar header + data. `type` '0' file, '2' symlink, '5' dir, etc.; `name` is written as given. */
function tarEntry({ name, data = Buffer.alloc(0), mode = 0o644, type = '0', linkname = '' }) {
  const header = Buffer.alloc(512, 0);
  let base = name;
  let prefix = '';
  if (Buffer.byteLength(name) > 100) {
    const cut = name.lastIndexOf('/', 155);
    prefix = name.slice(0, cut);
    base = name.slice(cut + 1);
  }
  header.write(base, 0, 100, 'utf8');
  header.write(octalField(mode, 8), 100, 8, 'latin1');
  header.write(octalField(0, 8), 108, 8, 'latin1');
  header.write(octalField(0, 8), 116, 8, 'latin1');
  header.write(octalField(data.length, 12), 124, 12, 'latin1');
  header.write(octalField(315532800, 12), 136, 12, 'latin1');
  header.write('        ', 148, 8, 'latin1');
  header.write(type, 156, 1, 'latin1');
  header.write(linkname, 157, 100, 'utf8');
  header.write('ustar\0', 257, 6, 'latin1');
  header.write('00', 263, 2, 'latin1');
  header.write(prefix, 345, 155, 'utf8');
  let sum = 0;
  for (const b of header) {
    sum += b;
  }
  header.write(`${sum.toString(8).padStart(6, '0')}\0 `, 148, 8, 'latin1');
  const pad = Buffer.alloc((512 - (data.length % 512)) % 512, 0);
  return Buffer.concat([header, data, pad]);
}

/**
 * Build a pack. `files` is [{path, data, mode?}] (FILES.json is derived from it unless
 * `filesJson` is given); `extraEntries` are raw tar entries NOT declared in FILES.json.
 * Returns {pack, manifest, manifestBytes, filesJsonBytes}.
 */
export function buildPack({ release, clientVersion = '0.9.29', files, extraEntries = [], filesJson, nodeEngines = '>=20', mutateManifest = (m) => m, mutateTar }) {
  const sorted = [...files].sort((a, b) => (a.path < b.path ? -1 : 1));
  const listing =
    filesJson ??
    sorted.map((f) => ({ path: f.path, mode: (f.mode ?? 0o644).toString(8).padStart(4, '0'), sha256: sha256(f.data), size: f.data.length }));
  const filesJsonBytes = Buffer.from(`${JSON.stringify(listing, null, 2)}\n`);
  let tar = Buffer.concat([
    ...sorted.map((f) => tarEntry({ name: f.path, data: f.data, mode: f.mode ?? 0o644 })),
    ...extraEntries.map((e) => tarEntry(e)),
    tarEntry({ name: 'FILES.json', data: filesJsonBytes }),
    Buffer.alloc(1024, 0),
  ]);
  if (mutateTar) {
    tar = mutateTar(tar);
  }
  const pack = zlib.gzipSync(tar);
  const manifest = mutateManifest({
    schema: 1,
    kind: 'agent-webhook-bridge-client-pack',
    bridge_release: release,
    minted_from_commit: 'a'.repeat(40),
    client_version: clientVersion,
    node_engines: nodeEngines,
    pack: { file: `client-pack-v${release}.tar.gz`, sha256: sha256(pack), size: pack.length },
    files_json_sha256: sha256(filesJsonBytes),
  });
  const manifestBytes = Buffer.from(`${JSON.stringify(manifest, null, 2)}\n`);
  return { pack, manifest, manifestBytes, filesJsonBytes };
}

/** The client files of a good pack for `release`: the real updater, a stub (or real) server. */
export function clientFiles(release, { clientVersion = '0.9.29', realServer = false, marker = '', entryMarker = '', updaterData, serverData, omit = [] } = {}) {
  const read = (name) => fs.readFileSync(path.join(SOURCE_DIR, name));
  const files = [
    { path: 'client/entry.mjs', data: Buffer.concat([read('entry.mjs'), Buffer.from(entryMarker)]), mode: 0o755 },
    { path: 'client/client-update.mjs', data: updaterData ?? read('client-update.mjs'), mode: 0o755 },
    { path: 'client/channel-lib.mjs', data: read('channel-lib.mjs') },
    { path: 'client/package.json', data: Buffer.from(`${JSON.stringify({ name: 'agent-webhook-bridge-channel-server', version: clientVersion, type: 'module' })}\n`) },
    {
      path: 'client/agent-webhook-bridge-channel.mjs',
      data: serverData ?? (realServer ? read('agent-webhook-bridge-channel.mjs') : Buffer.from(stubServer(release) + marker)),
      mode: 0o755,
    },
    { path: 'seat-tools/bin/check-channel-snapshot.py', data: Buffer.from(`#!/bin/sh\necho "seat tool of ${release}"\n`), mode: 0o755 },
    { path: 'client/bin/bridge-board-call.mjs', data: Buffer.from(`console.log(JSON.stringify({ bin: 'client bin of ${release}', argv: process.argv.slice(2) }));\n`), mode: 0o755 },
  ];
  return files.filter((f) => !omit.includes(f.path));
}

export function goodPack(release, opts = {}) {
  return buildPack({ release, clientVersion: opts.clientVersion, files: clientFiles(release, opts), nodeEngines: opts.nodeEngines });
}

/**
 * A fixture bridge: POST /agent-tools/client (the door) and POST /agent-tools/call (a board
 * tool, capturing its body). `state` is mutable between requests:
 *   published   {manifestBytes, pack, manifest} or null (503)
 *   offer       override of the offered release (default: the published one; null = approval owed)
 *   owed        approval.owed when offer is null
 *   fail        {op: {status, error}}  answer that op with a failure ({op: {status, raw}}: that body, verbatim)
 *   delayMs     {op: ms}  hold that op's answer
 *   packBytes   override of the bytes served by client_pack
 * `requests` records every door request body; `reports` every client_report.
 */
export async function fixtureBridge(t, initial = {}) {
  const state = { token: 'fixture-token', published: null, offer: undefined, owed: null, fail: {}, delayMs: {}, packBytes: null, ...initial };
  const requests = [];
  const reports = [];
  const calls = [];
  const held = new Map();
  const server = http.createServer((req, res) => {
    const chunks = [];
    req.on('data', (c) => chunks.push(c));
    req.on('end', async () => {
      const send = (status, body) => {
        res.writeHead(status, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify(body));
      };
      if (state.redirectTo) {
        res.writeHead(307, { Location: state.redirectTo });
        return res.end();
      }
      if (req.headers.authorization !== `Bearer ${state.token}`) {
        return send(401, { ok: false, error: 'unauthenticated' });
      }
      const raw = Buffer.concat(chunks).toString('utf8');
      let body;
      try {
        body = JSON.parse(raw);
      } catch {
        return send(422, { ok: false, error: 'not JSON' });
      }
      if (req.url === '/agent-tools/call') {
        calls.push(body);
        return send(200, { ok: true, tool: body.tool, result: { cards: [] } });
      }
      if (req.url !== '/agent-tools/client') {
        return send(404, { ok: false, error: 'no route' });
      }
      requests.push({ at: Date.now(), body, bytes: Buffer.byteLength(raw) });
      const op = body.op;
      if (state.delayMs[op]) {
        await new Promise((resolve) => setTimeout(resolve, state.delayMs[op]));
      }
      if (state.fail[op]) {
        if (state.fail[op].raw !== undefined) {
          // A body that is not the door's envelope — a framework error page, say.
          res.writeHead(state.fail[op].status, { 'Content-Type': 'application/json' });
          return res.end(state.fail[op].raw);
        }
        return send(state.fail[op].status, { ok: false, error: state.fail[op].error });
      }
      if (op === 'client_report') {
        reports.push(body);
        for (const line of body.entries) {
          const obj = JSON.parse(line);
          held.set(obj.seq, line);
        }
        return send(200, { ok: true, op, log_head: logHead(body.install_id), stored: body.entries.length, discontinuity: false });
      }
      if (!state.published) {
        return send(503, { ok: false, error: 'this bridge publishes no client pack yet — its operator runs `php artisan bridge:client-pack:install`; keep running the installed client' });
      }
      const p = state.published;
      if (op === 'client_manifest') {
        const offer = state.offer === undefined ? p.manifest.bridge_release : state.offer;
        return send(200, {
          ok: true,
          op,
          published: {
            bridge_release: p.manifest.bridge_release,
            client_version: p.manifest.client_version,
            files_json_sha256: p.manifest.files_json_sha256,
            manifest_sha256: state.manifestSha256 ?? sha256(p.manifestBytes),
            manifest_b64: p.manifestBytes.toString('base64'),
          },
          offer,
          approval: { required: offer === null, owed: offer === null ? state.owed ?? p.manifest.bridge_release : null },
          log_head: state.installId ? logHead(state.installId) : null,
        });
      }
      if (op === 'client_pack') {
        if (body.bridge_release !== p.manifest.bridge_release) {
          return send(404, { ok: false, error: `this bridge serves the client pack for release ${p.manifest.bridge_release} only` });
        }
        const bytes = state.packBytes ?? p.pack;
        return send(200, { ok: true, op, bridge_release: p.manifest.bridge_release, sha256: p.manifest.pack.sha256, size: p.manifest.pack.size, encoding: 'base64', data: bytes.toString('base64') });
      }
      return send(422, { ok: false, error: `unknown op ${op}` });
    });
  });
  function logHead(installId) {
    state.installId = installId;
    if (held.size === 0) {
      return null;
    }
    const seq = Math.max(...held.keys());
    return { install_id: installId, seq, sha256: sha256(Buffer.from(held.get(seq))) };
  }
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  t.after(() => new Promise((resolve) => server.close(resolve)));
  const { port } = server.address();
  return { state, requests, reports, calls, held, endpoint: `http://127.0.0.1:${port}/agent-tools/call`, port };
}
