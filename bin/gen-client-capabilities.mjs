#!/usr/bin/env node
// Generate resources/client-capabilities.json: for every board tool the reference channel
// server advertises, and every argument of each, the first client version that declared it
// (`since`) and the first that stopped (`removed_in`), derived from git history (card#10566).
//
//   node bin/gen-client-capabilities.mjs            rewrite the table
//   node bin/gen-client-capabilities.mjs --check    exit 1 if the committed table is not what
//                                                   history derives (CI runs this)
//   node bin/gen-client-capabilities.mjs --current  print the working tree's tool/argument set
//                                                   as JSON (no git; PHPUnit reads it)
//   --repo <dir>                                    repository root (default: this script's)
//
// Exit: 0 ok · 1 the committed table is stale (--check) · 2 COULD NOT MEASURE — never read a 2
// as agreement. Every refusal says which commit and why on stderr.
//
// ⛔ WHICH STATES ARE WALKED. A version's tool set is read from the commit(s) that INTRODUCED it
// on dev's FIRST-PARENT line — each commit there whose package.json version differs from its
// first parent's — limited to ancestors of HEAD, plus the working tree for the current version.
// Every commit on that line is one LANDING (a squash, or the merge commit of a merge-committed
// PR or a main -> dev sync), whose tree passed CI as that PR's working tree. So:
//   - a PR branch's own commits are never read, before or after it lands: a squash drops them
//     and a merge commit hides them behind its first parent. A bump commit that did not
//     evaluate, a bump later lowered, or a number another PR took first cannot wedge the
//     PR's check, and the PR's table is what dev derives once it lands;
//   - main (a release PR, a push to main) reads the same line, cut at the release point, so it
//     dates every argument as dev does — main's own first-parent line is one state per release.
// The line is read from refs/remotes/origin/dev, which must be present (CI's full-history
// checkout fetches it). DL-425 owns the rest of the reasoning and the bounds.
//
// ⛔ A version introduced more than once (two branches bumping to the same number) declares only
// what EVERY introducing tree declared: when the table cannot tell which copy a seat runs it
// says "not declared", which costs a seat an update hint and never tells it an argument works.

import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const SUBJECT_DIR = 'examples/channel-servers';
const TABLE_PATH = 'resources/client-capabilities.json';
const LITERAL_OPEN = 'const TOOL_DEFINITIONS = [';
const LITERAL_CLOSE = '\n];\n';
// Bare X.Y.Z only. The PHP comparator this table is read with (ChannelSnapshotManifest::
// compareVersions) reads a chunk with no leading digit as 0, so `v1.0.0` would order as 0.0.0.
const INTEGRATION_REF = 'refs/remotes/origin/dev';

// Behaviour the bridge keys on that is not a tool argument, each dated like an argument: the
// first client version whose sources carry ANY of its markers. A marker must stay in the
// working tree; when the code it names is rewritten, ADD the new form here and keep the old
// one, or the feature re-dates to the rewrite.
const FEATURE_MARKERS = {
  client_version_report: ['client_version: CLIENT_VERSION'],
  truthful_handshake_version: ['const HANDSHAKE_VERSION = CLIENT_VERSION ??'],
};

const BARE_VERSION = /^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/;

class CannotMeasure extends Error {}

function parseArgs(argv) {
  const opts = { mode: 'write', repo: path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..') };
  for (let i = 0; i < argv.length; i++) {
    const a = argv[i];
    if (a === '--check' || a === '--current') {
      if (opts.mode !== 'write') {
        throw new CannotMeasure('--check and --current are exclusive');
      }
      opts.mode = a.slice(2);
    } else if (a === '--repo') {
      opts.repo = path.resolve(argv[++i] ?? '');
    } else {
      throw new CannotMeasure(`unknown argument ${a}`);
    }
  }
  return opts;
}

function git(repo, args, input) {
  try {
    return execFileSync('git', ['-C', repo, ...args], {
      encoding: 'utf8',
      input,
      maxBuffer: 256 * 1024 * 1024,
      stdio: ['pipe', 'pipe', 'pipe'],
    });
  } catch (e) {
    throw new CannotMeasure(`git ${args.join(' ')} failed: ${(e.stderr || e.message).trim()}`);
  }
}

function compareBare(a, b) {
  const ta = a.split('.').map(Number);
  const tb = b.split('.').map(Number);
  for (let i = 0; i < 3; i++) {
    if (ta[i] !== tb[i]) {
      return ta[i] - tb[i];
    }
  }
  return 0;
}

function bareVersion(raw, where) {
  if (typeof raw !== 'string' || !BARE_VERSION.test(raw)) {
    throw new CannotMeasure(
      `${where}: package.json version ${JSON.stringify(raw)} is not bare X.Y.Z, so it cannot be ` +
        'stored in the capability table (a v-prefix or pre-release tag would be misordered by the ' +
        'comparator the bridge reads it with)',
    );
  }
  return raw;
}

// Evaluate the TOOL_DEFINITIONS literal. It is a plain data literal; anything that makes its
// value depend on WHEN or WHERE it is evaluated is refused, because --check compares two
// evaluations of the same history and must never flap. Each evaluation is a fresh context with
// no host globals and no clock, randomness, locale or string code generation, so nothing a
// literal can reach differs between runs.
function evaluateLiteral(literal, where) {
  const run = () => {
    const context = vm.createContext(Object.create(null), {
      codeGeneration: { strings: false, wasm: false },
    });
    vm.runInContext(
      "'use strict';" +
        "for (const k of ['Date', 'WeakRef', 'FinalizationRegistry', 'SharedArrayBuffer', 'Atomics', 'Intl']) delete globalThis[k];" +
        "Math.random = () => { throw new Error('Math.random is nondeterministic'); };",
      context,
    );
    const value = vm.runInContext(`'use strict'; (${literal});`, context, { timeout: 2000, filename: where });
    return JSON.stringify(value);
  };
  let first;
  try {
    first = run();
  } catch (e) {
    throw new CannotMeasure(`${where}: TOOL_DEFINITIONS could not be evaluated deterministically: ${e.message}`);
  }
  const defs = JSON.parse(first);
  if (!Array.isArray(defs)) {
    throw new CannotMeasure(`${where}: TOOL_DEFINITIONS did not evaluate to an array`);
  }
  const tools = {};
  for (const def of defs) {
    const props = def?.inputSchema?.properties;
    if (typeof def?.name !== 'string' || def.name === '' || props === null || typeof props !== 'object' || Array.isArray(props)) {
      throw new CannotMeasure(`${where}: a TOOL_DEFINITIONS entry has no string name or no inputSchema.properties object`);
    }
    if (Object.hasOwn(tools, def.name)) {
      throw new CannotMeasure(`${where}: TOOL_DEFINITIONS declares ${def.name} twice`);
    }
    tools[def.name] = Object.keys(props).sort();
  }
  return tools;
}

// One state of the subject directory: its tool -> arguments map and which features it carries.
// `files` is the directory's top-level .mjs sources.
function stateOf(files, where) {
  const sources = Object.values(files);
  const features = Object.fromEntries(
    Object.entries(FEATURE_MARKERS).map(([name, markers]) => [name, markers.some((m) => sources.some((src) => src.includes(m)))]),
  );
  return { tools: toolsOf(files, where), features };
}

// No literal at all is a state with no tools (the server predates them); a literal that cannot
// be cut out or evaluated is a refusal.
function toolsOf(files, where) {
  const holders = Object.entries(files).filter(([, src]) => src.includes(LITERAL_OPEN));
  if (holders.length === 0) {
    return {};
  }
  if (holders.length > 1) {
    throw new CannotMeasure(`${where}: TOOL_DEFINITIONS is defined in more than one file (${holders.map(([f]) => f).join(', ')})`);
  }
  const [file, src] = holders[0];
  const start = src.indexOf(LITERAL_OPEN) + LITERAL_OPEN.length - 1;
  const end = src.indexOf(LITERAL_CLOSE, start);
  if (end === -1 || src.indexOf(LITERAL_OPEN, start) !== -1) {
    throw new CannotMeasure(`${where}: the TOOL_DEFINITIONS literal in ${file} does not close at a line reading "];"`);
  }
  return evaluateLiteral(src.slice(start, end + 2), `${where}:${file}`);
}

function readBlobs(repo, specs) {
  if (specs.length === 0) {
    return [];
  }
  let out;
  try {
    out = execFileSync('git', ['-C', repo, 'cat-file', '--batch'], {
      input: specs.join('\n') + '\n',
      maxBuffer: 256 * 1024 * 1024,
      stdio: ['pipe', 'pipe', 'pipe'],
    });
  } catch (e) {
    throw new CannotMeasure(`git cat-file --batch failed: ${String(e.stderr || e.message).trim()}`);
  }
  const blobs = [];
  let pos = 0;
  for (const spec of specs) {
    const nl = out.indexOf(10, pos);
    const header = out.subarray(pos, nl).toString('utf8');
    pos = nl + 1;
    if (header.endsWith(' missing')) {
      blobs.push(null);
      continue;
    }
    const size = Number(header.split(' ')[2]);
    if (!header.includes(' blob ') || !Number.isInteger(size)) {
      throw new CannotMeasure(`git cat-file answered ${JSON.stringify(header)} for ${spec}`);
    }
    blobs.push(out.subarray(pos, pos + size).toString('utf8'));
    pos += size + 1;
  }
  return blobs;
}

// null only when the manifest is absent. A manifest that does not parse, or declares no
// version, reads as undefined and is refused by name where a commit introduces it.
function versionAt(blob) {
  if (blob === null) {
    return null;
  }
  try {
    return JSON.parse(blob).version;
  } catch {
    return undefined;
  }
}

function historicalStates(repo) {
  if (git(repo, ['rev-parse', '--is-shallow-repository']).trim() !== 'false') {
    throw new CannotMeasure(
      'this is a SHALLOW clone: history is missing, so every tool would date to the oldest commit ' +
        'present. Fetch the whole history (actions/checkout fetch-depth: 0, or git fetch --unshallow).',
    );
  }
  if (git(repo, ['for-each-ref', '--format=%(refname)', INTEGRATION_REF]).trim() !== INTEGRATION_REF) {
    throw new CannotMeasure(
      `${INTEGRATION_REF} is missing: the versions are read from dev's first-parent line. ` +
        'Fetch it (git fetch origin dev; in CI, actions/checkout with fetch-depth: 0).',
    );
  }
  const reachable = new Set(git(repo, ['rev-list', 'HEAD']).split('\n').filter(Boolean));
  // Every commit on dev's line whose manifest differs from its first parent — a superset of the
  // commits that introduce a version, which is what is asked of each below. Commits on dev
  // that HEAD does not carry yet (a stale branch, main behind dev) are not HEAD's history.
  const commits = git(repo, ['log', '--first-parent', '--full-history', '--format=%H %P', INTEGRATION_REF, '--', `${SUBJECT_DIR}/package.json`])
    .split('\n')
    .filter(Boolean)
    .map((l) => {
      const [sha, parent] = l.split(' ');
      return { sha, parents: parent === undefined ? [] : [parent] };
    })
    .filter((c) => reachable.has(c.sha));
  const shas = [...new Set(commits.flatMap((c) => [c.sha, ...c.parents]))];
  const versions = new Map();
  readBlobs(repo, shas.map((s) => `${s}:${SUBJECT_DIR}/package.json`)).forEach((blob, i) => versions.set(shas[i], versionAt(blob)));

  const introducing = [];
  for (const c of commits) {
    const v = versions.get(c.sha);
    if (v === null) {
      continue; // the manifest is absent here (deleted, or not yet created): no version to introduce
    }
    if (c.parents.some((p) => versions.get(p) === v)) {
      continue;
    }
    introducing.push({ sha: c.sha, version: bareVersion(v, `commit ${c.sha}`) });
  }

  return introducing.map(({ sha, version }) => {
    const names = git(repo, ['ls-tree', '--name-only', `${sha}:${SUBJECT_DIR}`])
      .split('\n')
      .filter((n) => n.endsWith('.mjs'));
    const blobs = readBlobs(repo, names.map((n) => `${sha}:${SUBJECT_DIR}/${n}`));
    const files = Object.fromEntries(names.map((n, i) => [n, blobs[i]]));
    return { version, ...stateOf(files, `commit ${sha}`) };
  });
}

function workingTreeState(repo) {
  const dir = path.join(repo, SUBJECT_DIR);
  let version;
  try {
    version = JSON.parse(fs.readFileSync(path.join(dir, 'package.json'), 'utf8')).version;
  } catch (e) {
    throw new CannotMeasure(`working tree: ${SUBJECT_DIR}/package.json did not read: ${e.message}`);
  }
  const files = Object.fromEntries(
    fs
      .readdirSync(dir)
      .filter((n) => n.endsWith('.mjs'))
      .map((n) => [n, fs.readFileSync(path.join(dir, n), 'utf8')]),
  );
  return { version: bareVersion(version, 'working tree'), ...stateOf(files, 'working tree') };
}

// The table: per tool and per argument, the one contiguous run of versions that declared it.
function derive(historical, current) {
  const byVersion = new Map();
  for (const s of historical) {
    const prev = byVersion.get(s.version);
    byVersion.set(s.version, prev === undefined ? s : { tools: intersect(prev.tools, s.tools), features: both(prev.features, s.features) });
  }
  for (const v of byVersion.keys()) {
    if (compareBare(v, current.version) > 0) {
      throw new CannotMeasure(`history introduced ${v}, which is NEWER than the working tree's ${current.version}`);
    }
  }
  // The version being worked on is what the working tree says: a branch that bumps first and
  // adds an argument in a later commit introduced its version without that argument.
  byVersion.set(current.version, current);
  const versions = [...byVersion.keys()].sort(compareBare);

  const argsOf = new Map();
  for (const { tools } of byVersion.values()) {
    for (const [tool, args] of Object.entries(tools)) {
      argsOf.set(tool, new Set([...(argsOf.get(tool) ?? []), ...args]));
    }
  }

  const span = (label, declaredAt) => {
    const present = versions.map(declaredAt);
    const since = present.indexOf(true);
    const gone = present.indexOf(false, since);
    if (gone !== -1 && present.indexOf(true, gone) !== -1) {
      throw new CannotMeasure(
        `${label} is declared, dropped at ${versions[gone]} and declared again at ` +
          `${versions[present.indexOf(true, gone)]}: one since/removed_in pair cannot state that, and the table refuses to guess`,
      );
    }
    return { since: versions[since], removed_in: gone === -1 ? null : versions[gone] };
  };

  const tools = {};
  for (const [tool, args] of argsOf) {
    const argSpans = {};
    for (const arg of args) {
      argSpans[arg] = span(`${tool}.${arg}`, (v) => byVersion.get(v).tools[tool]?.includes(arg) === true);
    }
    tools[tool] = { ...span(tool, (v) => byVersion.get(v).tools[tool] !== undefined), arguments: argSpans };
  }

  const features = {};
  for (const name of Object.keys(FEATURE_MARKERS)) {
    if (!current.features[name]) {
      throw new CannotMeasure(
        `features.${name}: no marker (${FEATURE_MARKERS[name].map((m) => JSON.stringify(m)).join(', ')}) is in the working ` +
          `tree's ${SUBJECT_DIR}/*.mjs. If the code was rewritten, add its new form to FEATURE_MARKERS and keep the old one.`,
      );
    }
    const { since, removed_in } = span(`features.${name}`, (v) => byVersion.get(v).features[name]);
    if (removed_in !== null) {
      throw new CannotMeasure(`features.${name}: its marker is absent at ${removed_in} although the working tree carries it`);
    }
    features[name] = since;
  }
  return { tools, features };
}

function both(a, b) {
  return Object.fromEntries(Object.keys(a).map((k) => [k, a[k] && b[k]]));
}

function intersect(a, b) {
  const out = {};
  for (const [tool, args] of Object.entries(a)) {
    if (b[tool] !== undefined) {
      out[tool] = args.filter((x) => b[tool].includes(x));
    }
  }
  return out;
}

function canonical(value) {
  if (Array.isArray(value)) {
    return value.map(canonical);
  }
  if (value !== null && typeof value === 'object') {
    return Object.fromEntries(Object.keys(value).sort().map((k) => [k, canonical(value[k])]));
  }
  return value;
}

function render(table) {
  return JSON.stringify(canonical(table), null, 2) + '\n';
}

// The changed lines only, each with its line number in the file it comes from.
function lineDiff(before, after) {
  const a = before.split('\n');
  const b = after.split('\n');
  const lcs = Array.from({ length: a.length + 1 }, () => new Array(b.length + 1).fill(0));
  for (let i = a.length - 1; i >= 0; i--) {
    for (let j = b.length - 1; j >= 0; j--) {
      lcs[i][j] = a[i] === b[j] ? lcs[i + 1][j + 1] + 1 : Math.max(lcs[i + 1][j], lcs[i][j + 1]);
    }
  }
  const out = [];
  let i = 0;
  let j = 0;
  while (i < a.length || j < b.length) {
    if (i < a.length && j < b.length && a[i] === b[j]) {
      i++;
      j++;
    } else if (j < b.length && (i === a.length || lcs[i][j + 1] >= lcs[i + 1][j])) {
      out.push(`+${j + 1}: ${b[j++]}`);
    } else {
      out.push(`-${i + 1}: ${a[i++]}`);
    }
  }
  return out.join('\n') + '\n';
}

function main() {
  const opts = parseArgs(process.argv.slice(2));
  const current = workingTreeState(opts.repo);
  if (opts.mode === 'current') {
    process.stdout.write(render({ client_version: current.version, tools: current.tools }));
    return 0;
  }

  const tablePath = path.join(opts.repo, TABLE_PATH);
  let committed = null;
  try {
    committed = fs.readFileSync(tablePath, 'utf8');
  } catch (e) {
    if (e.code !== 'ENOENT' || opts.mode === 'check') {
      throw new CannotMeasure(`${TABLE_PATH} did not read: ${e.message}`);
    }
  }
  const { tools, features } = derive(historicalStates(opts.repo), current);
  const generated = render({
    $comment:
      'GENERATED by bin/gen-client-capabilities.mjs from the git history of examples/channel-servers/ — ' +
      'regenerate, never hand-edit. A feature is dated by the markers the generator\'s FEATURE_MARKERS names. DL-425.',
    current_client_version: current.version,
    features,
    tools,
  });

  if (opts.mode === 'check') {
    if (committed === generated) {
      process.stdout.write(`${TABLE_PATH} is what history derives (client ${current.version}).\n`);
      return 0;
    }
    process.stderr.write(
      `${TABLE_PATH} is NOT what the history of ${SUBJECT_DIR}/ derives. Regenerate it with ` +
        '`node bin/gen-client-capabilities.mjs` and commit the result; hand edits to it are overwritten.\n' +
        `--- committed\n+++ derived\n${lineDiff(committed, generated)}`,
    );
    return 1;
  }
  fs.writeFileSync(tablePath, generated);
  process.stdout.write(`wrote ${TABLE_PATH} (client ${current.version}).\n`);
  return 0;
}

// Every failure that is not a verdict exits 2, an unexpected one included: exit 1 means "the
// committed table is stale", and a crash must not be read as that.
try {
  process.exitCode = main();
} catch (e) {
  process.stderr.write(`gen-client-capabilities: COULD NOT MEASURE — ${e instanceof CannotMeasure ? e.message : e.stack}\n`);
  process.exitCode = 2;
}
