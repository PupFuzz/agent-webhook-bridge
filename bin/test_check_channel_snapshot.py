#!/usr/bin/env python3
"""Unit tests for the seat-side launch-assert bin/check-channel-snapshot.py (card 5143).

Every case here is a MEASUREMENT against a real `node`, not a mock: the tool's whole
claim is that launching is more precise than enumerating files, and a mocked launch
would assert the mock. Three of them are the evidence DL-237 rests on:

  - the DL-230 shape (`channel-lib.mjs` removed from an otherwise-good copy) ⇒ exit 1
    with `ERR_MODULE_NOT_FOUND`. This is the regression test for the incident the
    retired completeness leg existed to catch: it goes RED the moment the tool stops
    actually launching.
  - the PRUNED shape (README / tests/ / dotfiles gone, load-bearing files present)
    ⇒ exit 0. The retired completeness leg FAILs this tree; the launch is right.
  - the live-socket protection, asserted directly against a sentinel in the PARENT
    environment — the guard that keeps a diagnostic from unlinking the live socket.

Proven failable — each mutated once and restored, and each entry NAMES THE GUARD that
reds rather than a total. Collateral counts were tried and abandoned: dropping the port
override reds 14 or 15 depending on whether port 8788 happens to be free on the box, so
a number there is not a fact about the code. A stale or environment-dependent count
reads as coverage, which is worse than no count.

  - drop `env["BRIDGE_CHANNEL_TRANSPORT"]` → `test_the_parents_transport_never_reaches_the_child`
    (+ the dict guard).
  - drop `env["BRIDGE_CHANNEL_PORT"]`      → `test_the_seats_configured_port_is_never_reached`
    (+ the dict guard). EXACTLY those two, deterministically: `setUpModule()` pins the
    port, so the broad collateral an earlier revision of this list recorded is a
    PRE-SANDBOX observation and no longer true.
  - drop `env["XDG_RUNTIME_DIR"]`          → `test_nothing_is_written_into_the_seats_runtime_dir`
    (+ the dict guard).
  - drop `env["BRIDGE_CHANNEL_SOCKET"]`    → `test_child_env_overrides_every_channel_addressing_input`
    ALONE. The sentinel case stays GREEN, because with the transport pinned the entry
    never reads the socket path — that is what belt-and-braces means, and the earlier
    claim that this reds the sentinel too was measured against a DOUBLE mutation that
    also removed the pin.
  - disable the bind gate                  → the two exit-0-without-bind cases + the
    customized-entry message case.
  - let a signal-kill fall through         → `test_a_signal_killed_child_is_an_environment_fact_not_a_verdict`.
  - report a timeout as OK                 → `test_a_hanging_entry_is_inconclusive`.
  - `os.path.lexists` → `False`            → `test_a_pre_existing_socket_path_refuses_and_leaves_it_alone`.
  - treat any child exit as OK             → the DL-230 case, in all three of its forms.
  - give the probe a collaborator, or a static call outside `self::`
                                           → `test_the_snapshot_probe_path_cannot_execute_a_process`.
  - revert any of the three scanner fixes  → `test_the_php_scanner_stays_in_sync_on_the_shapes_that_broke_it`.
  - collapse the dangling-symlink branch   → `test_a_dangling_symlink_is_named_as_one_not_as_a_missing_path`.
  - let an OSError escape either exec site → the two "not a deployment verdict" cases
    (they ERROR rather than FAIL — the exception propagates, which is the defect).
  - stop the fixture writing its witness   → `test_the_bind_witness_is_actually_produced_under_both_transports`
    AND `test_the_http_witness_is_observed_inside_the_throwaway_dir`. Those two exist
    because the witness was previously asserted ABSENT in two places and never once
    observed PRESENT — and `witness()` swallows its own write errors, so if it had ever
    stopped writing, both absence assertions would have passed forever.

METHODOLOGY, because it changed a result: the env mutations must be an ASSIGNMENT
OMISSION — delete the `env[...] = ...` line — never `del env[...]`. The latter is a
DIFFERENT mutation (it removes a key the parent may not have set at all) and produces a
false negative. Every entry above was produced by omission, under the module sandbox.
"""

import importlib.util
import io
import os
import pathlib
import re
import shutil
import stat
import subprocess
import sys
import tempfile
import unittest
from unittest import mock

_HERE = os.path.dirname(os.path.abspath(__file__))

# The static-call prefixes `ChannelSnapshotProbe` may use DIRECTLY. `self::` is its
# own helpers; `Finding::` is the same-namespace value object it returns (which is why
# it needs no `use` import — the probe's no-import assertion still holds). Compared by
# EXACT equality in both directions, so an entry nothing uses fails too: a permission
# outlives its reason otherwise, and this list is a trust boundary.
_ALLOWED_PROBE_STATICS = {"self::", "Finding::"}

# Every class REACHABLE from the probe through the allow-list, scanned for exec
# primitives on the same terms as the probe itself. Wider than the set above by
# design: `Severity` is never named in the probe, only in `Finding`'s constructors,
# and a hop the scan skips is a hop nothing guards.
_PROBE_COLLABORATORS = (
    "app/Bridge/Support/Finding.php",
    "app/Bridge/Support/Severity.php",
)

# What may be passed INTO the probe: a variable, a property read chain, a quoted
# string, or a class constant — nothing that evaluates. Deliberately a grammar and
# not a pinned argument list: the previous revision pinned the literal argument TEXT
# of one call site, which is why a refactor that moved the call left it asserting
# about a file with no call in it. Anything containing `(` fails by construction, so
# a smuggled call cannot match however it is spelled.
_SAFE_ARGUMENT = re.compile(
    r"""^(
          \$[A-Za-z_][A-Za-z0-9_]*(->[A-Za-z_][A-Za-z0-9_]*)*   # $var, $a->b, $this->c->d
        | '[^']*'                                                # 'single-quoted'
        | "[^"$]*"                                               # "double-quoted", no interpolation
        | [A-Za-z_][A-Za-z0-9_]*::[A-Za-z_][A-Za-z0-9_]*         # ClassName::CONST
        )$""",
    re.VERBOSE,
)
_spec = importlib.util.spec_from_file_location(
    "check_channel_snapshot", os.path.join(_HERE, "check-channel-snapshot.py")
)
ccs = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(ccs)

_NODE = shutil.which("node")
_NO_NODE_REASON = (
    "LOUD SKIP: `node` is not on PATH, so every launch-assert case below measured NOTHING. "
    "These are not passing tests — they did not run. CI must provide Node 20+."
)
if _NODE is None:
    # A silent skip on the tool's own subject matter is how a suite becomes decorative.
    print("=" * 78, file=sys.stderr)
    print(_NO_NODE_REASON, file=sys.stderr)
    print("=" * 78, file=sys.stderr)

_REFERENCE = os.path.join(os.path.dirname(_HERE), "examples", "channel-servers")


# ─── LIVE-STATE SANDBOX ──────────────────────────────────────────────────────────
# This suite spawns a REAL channel server, and its mutation testing deliberately
# removes the very overrides that keep that server off the seat's live endpoints. Run
# unsandboxed, that is not hypothetical: proving these guards once left a synthetic
# witness AND a real `<live-socket>.FAILED` marker in this seat's actual
# $XDG_RUNTIME_DIR — the FR #2444 "this session is deaf to live-wake" signal, FALSE at
# the time (the socket was bound and accepting throughout). A test suite for a tool
# whose entire purpose is "never touch live state" must not touch live state.
#
# So the two env vars that address a live endpoint are repointed at a module-scoped
# temp dir BEFORE any case runs, and the real runtime dir is snapshotted and re-checked
# at teardown. The teardown assertion is the part that matters: without it this is a
# precaution nobody would notice failing.
_MODULE_TMP = None
_REAL_RUNTIME_DIR = None
_REAL_RUNTIME_BEFORE = None


def _runtime_state(path):
    """Name → (type, size, mtime_ns) for every entry, NOT just the name set.

    A name-set compare misses the case that actually happens: a false `.FAILED` marker
    written OVER an existing one. No name changes, and a seat that has previously been
    deaf is exactly the state where the stale marker is already sitting there — which is
    the incident DL-237 (e) records. `lstat` so a symlink is not followed, and sockets
    are covered like anything else.
    """
    try:
        entries = sorted(os.listdir(path))
    except OSError:
        return None
    state = {}
    for name in entries:
        try:
            st = os.lstat(os.path.join(path, name))
            state[name] = (st.st_mode, st.st_size, st.st_mtime_ns)
        except OSError:
            state[name] = ("unstattable",)
    return state


def setUpModule():
    global _MODULE_TMP, _REAL_RUNTIME_DIR, _REAL_RUNTIME_BEFORE
    _REAL_RUNTIME_DIR = os.environ.get("XDG_RUNTIME_DIR")
    _REAL_RUNTIME_BEFORE = _runtime_state(_REAL_RUNTIME_DIR) if _REAL_RUNTIME_DIR else None

    _MODULE_TMP = tempfile.mkdtemp(prefix="channel-assert-suite-sandbox-")
    # ALL FOUR channel-addressing inputs, matching build requirement (a)'s own wording —
    # not the socket and the runtime dir alone. The first version neutralised two, and
    # under the DL-mandated port mutation the children then reached for
    # `127.0.0.1:8788`, which is live on a seat running the HTTP transport. Damage was
    # contained by the XDG override, but the sandbox's whole purpose is to survive
    # exactly those mutations, so containment-by-luck is not the bar.
    #
    # Assignments, not setdefault: the seat exports the real values and they must lose —
    # the same rule the tool itself follows in child_env().
    os.environ["BRIDGE_CHANNEL_TRANSPORT"] = "http"
    os.environ["BRIDGE_CHANNEL_PORT"] = "0"
    os.environ["XDG_RUNTIME_DIR"] = _MODULE_TMP
    os.environ["BRIDGE_CHANNEL_SOCKET"] = os.path.join(_MODULE_TMP, "sandbox-channel.sock")


def tearDownModule():
    try:
        if _REAL_RUNTIME_DIR is not None:
            after = _runtime_state(_REAL_RUNTIME_DIR)
            if after != _REAL_RUNTIME_BEFORE:
                before_keys, after_keys = set(_REAL_RUNTIME_BEFORE or {}), set(after or {})
                added = sorted(after_keys - before_keys)
                removed = sorted(before_keys - after_keys)
                changed = sorted(
                    k for k in before_keys & after_keys
                    if (_REAL_RUNTIME_BEFORE or {})[k] != (after or {})[k]
                )
                raise AssertionError(
                    f"THIS SUITE TOUCHED LIVE STATE: {_REAL_RUNTIME_DIR} changed during the run "
                    f"(added={added}, removed={removed}, changed={changed}). The sandbox in "
                    "setUpModule() is not holding — a spawned channel server reached a real "
                    "endpoint."
                )
    finally:
        if _MODULE_TMP:
            shutil.rmtree(_MODULE_TMP, ignore_errors=True)



# A stand-in for the shipped entry that reproduces the two properties this tool is
# built around: it imports a SIBLING MODULE (so removing that module reproduces the
# DL-230 incident exactly), and it binds `BRIDGE_CHANNEL_SOCKET` and UNLINKS it on the
# way out (so a dropped env override really would destroy the seat's live socket).
# Bind and stdin-EOF are joined rather than raced, so "exited 0" here always means
# the bind happened — a race would make the sentinel test flaky in the SAFE direction,
# which is the direction that hides a regression.
_ENTRY_SOURCE = """\
import { hello } from './channel-lib.mjs';
import net from 'node:net';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

// Mirrors the shipped entry's addressing inputs exactly (agent-webhook-bridge-channel.mjs
// lines 50 / 236-250 / 268-274): transport defaults to unix, the socket env wins over the
// name-derived default, the port defaults to 8788, and XDG_RUNTIME_DIR is where a marker
// lands. If any of those stop matching, these tests stop standing for the real thing.
const TRANSPORT = (process.env.BRIDGE_CHANNEL_TRANSPORT || 'unix').toLowerCase();
const PORT = Number(process.env.BRIDGE_CHANNEL_PORT || 8788);
const XDG = process.env.XDG_RUNTIME_DIR || os.tmpdir();
const SOCKET_PATH =
  process.env.BRIDGE_CHANNEL_SOCKET ||
  (process.env.XDG_RUNTIME_DIR
    ? path.join(process.env.XDG_RUNTIME_DIR, `channel-${process.env.BRIDGE_CHANNEL_NAME || 'x'}.sock`)
    : null);

if (TRANSPORT !== 'unix' && TRANSPORT !== 'http') {
  // The shipped entry refuses an unknown transport and exits non-zero (refuseDeaf).
  console.error(`refusing: BRIDGE_CHANNEL_TRANSPORT must be unix or http (got '${TRANSPORT}')`);
  process.exit(2);
}
if (TRANSPORT === 'unix' && !SOCKET_PATH) {
  console.error('refusing: no socket path could be resolved');
  process.exit(2);
}

let bound = false;
let ended = false;
function shutdown(code) {
  if (bound && TRANSPORT === 'unix') {
    try { fs.unlinkSync(SOCKET_PATH); } catch {}
  }
  process.exit(code);
}
// A PERSISTENT witness that an endpoint was bound. The unix socket is unlinked on the
// way out and a TCP port simply closes, so "nothing is there afterwards" cannot tell a
// path that was never touched from one that was bound and then destroyed — which is the
// whole damage the throwaway overrides exist to prevent.
function witness() {
  const at =
    TRANSPORT === 'unix'
      ? `${SOCKET_PATH}.bound`
      : path.join(XDG, `bound-http-${PORT}`);
  try { fs.writeFileSync(at, 'bound\\n'); } catch {}
}
const server = net.createServer(() => {});
server.on('error', (err) => {
  console.error(`bind failed: ${err.code}`);
  process.exit(2);
});
function onListening() {
  bound = true;
  witness();
  const where = TRANSPORT === 'unix' ? `unix:${SOCKET_PATH}` : `http://127.0.0.1:${PORT}`;
  console.error(`[test-channel] listening on ${where} ${hello()}`);
  if (ended) shutdown(0);
}
if (TRANSPORT === 'unix') {
  server.listen(SOCKET_PATH, onListening);
} else {
  server.listen(PORT, '127.0.0.1', onListening);
}
process.stdin.on('end', () => {
  ended = true;
  if (bound) shutdown(0);
});
process.stdin.resume();
"""

_LIB_SOURCE = "export const hello = () => 'ok';\n"

# The SIX files a pruned deployment drops (of the 10 the reference ships). Every one of them is in the reference set
# the retired completeness leg enumerated, and NONE of them is load-bearing for a
# launch — which is the whole measurement.
_NON_LOAD_BEARING = {
    "README.md": "# reference\n",
    ".gitignore": "node_modules/\n",
    ".mcp.json.example": "{}\n",
    "tests/a.test.mjs": "import 'node:test';\n",
    "tests/b.test.mjs": "import 'node:test';\n",
    "tests/c.test.mjs": "import 'node:test';\n",
}


def _php_code_only(source: str) -> str:
    """PHP source with comments and string LITERALS blanked out.

    Both have to go for a token scan to mean anything here: every docblock in these
    files says `bridge:check` in backticks (and the backtick IS `shell_exec`), and the
    message strings talk about running `bin/check-channel-snapshot.py`.

    HONEST BOUND — read this before trusting it. This is a hand-rolled scanner, not a
    PHP parser, and an earlier version of this docstring claimed it could only fail
    into a false NEGATIVE. That was wrong in both directions, because a mis-handled
    escape does not widen the blanking, it FLIPS the in-string state and desyncs
    everything after it: PHP's single-quoted `\'` was treated as a literal quote, so
    `'don\'t `back` tick'` was read as code and CAUGHT (false positive on idiomatic
    source), while a real `\'` in the scanned file opened a blind window in which an
    injected `shell_exec()` was NOT caught. Both were reachable, in the very file the
    guard protects.

    Backslash now consumes the next character in BOTH quote styles, which is what keeps
    the state machine in sync (PHP only escapes `\'` and `\\` inside single quotes, but
    consuming two is correct for SYNC either way). `#[` is an attribute, not a comment.
    What is still NOT handled: heredoc/nowdoc bodies, and `?>`/`<?php` interleaving.
    Neither appears in the two scanned files, and `_php_code_only` is self-tested below
    on the exact shapes that broke it. Treat what it backs as a TRIPWIRE, not a proof.
    """
    out = []
    i, n = 0, len(source)
    quote = None
    while i < n:
        c = source[i]
        if quote:
            if c == "\\" and i + 1 < n:
                # Consume the escaped character. In single quotes PHP only escapes `\'`
                # and `\\`, but consuming two is what keeps the scanner in SYNC — the
                # failure mode that matters is losing track of where the string ends.
                i += 2
                continue
            if c == quote:
                quote = None
                out.append(c)
            else:
                out.append(" ")
            i += 1
            continue
        if c in "'\"":
            quote = c
            out.append(c)
            i += 1
            continue
        if source.startswith("/*", i):
            end = source.find("*/", i + 2)
            i = n if end == -1 else end + 2
            out.append(" ")
            continue
        # `#[` opens an ATTRIBUTE, not a comment — blanking the line would erase real
        # code (and did).
        if source.startswith("//", i) or (c == "#" and not source.startswith("#[", i)):
            end = source.find("\n", i)
            i = n if end == -1 else end
            out.append(" ")
            continue
        out.append(c)
        i += 1
    return "".join(out)


# Process-launch primitives, as whole IDENTIFIERS. The boundary is load-bearing: a bare
# `system(` substring matches `getFilesystem(`, and a bare `exec(` matches any
# `…exec(` method. `$` and `>` are in the lookbehind so `$system(` and `->system(` are
# method/variable calls, not the builtin.
# `\\` is NOT in the lookbehind: the fully-qualified `\\shell_exec(...)` is the idiomatic
# form in a namespaced file, and excluding it made exactly that spelling invisible. `$`
# and `>` stay, so `$system(` and `->system(` read as variable/method calls.
_EXEC_PRIMITIVES = tuple(
    re.compile(r"(?<![A-Za-z0-9_$>])" + name + r"\s*\(")
    for name in ("proc_open", "shell_exec", "passthru", "popen", "system", "exec", "pcntl_exec", "eval", "call_user_func", "call_user_func_array")
)

# Language constructs that pull in arbitrary code and take no parentheses, plus the
# bare dynamic invoke `$f(...)` that `call_user_func` coverage alone misses.
_CODE_PULLERS = (
    re.compile(r"(?<![A-Za-z0-9_$>])(include|require)(_once)?\b"),
    re.compile(r"(?<![A-Za-z0-9_>])\$[A-Za-z_]\w*\s*\("),
)


def _exec_primitives_in(php_source: str):
    """Every process-launch/indirect-call primitive in already-comment-stripped code,
    plus the backtick operator (which IS `shell_exec`)."""
    found = [p.pattern for p in _EXEC_PRIMITIVES + _CODE_PULLERS if p.search(php_source)]
    if "`" in php_source:
        found.append("backtick operator")
    return found


class _TreeCase(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.mkdtemp(prefix="check-channel-snapshot-test-")
        self.addCleanup(shutil.rmtree, self.tmp, ignore_errors=True)

    def tree(self, name, files):
        root = os.path.join(self.tmp, name)
        for relative, contents in files.items():
            path = os.path.join(root, relative)
            os.makedirs(os.path.dirname(path), exist_ok=True)
            with open(path, "w", encoding="utf-8") as fh:
                fh.write(contents)
        os.makedirs(root, exist_ok=True)
        return root

    def whole_copy(self, name="deployed", omit=()):
        files = {
            ccs.ENTRY_FILE: _ENTRY_SOURCE,
            "channel-lib.mjs": _LIB_SOURCE,
            "package.json": '{"name":"snap","version":"1.2.3"}\n',
            **_NON_LOAD_BEARING,
        }
        for relative in omit:
            files.pop(relative, None)
        return self.tree(name, files)

    def assert_run(self, path, expected, **kwargs):
        out = io.StringIO()
        code = ccs.run_launch_assert(path, out=out, **kwargs)
        text = out.getvalue()
        self.assertEqual(expected, code, f"unexpected exit code; output was:\n{text}")
        return text

    def skip_as_root(self):
        if hasattr(os, "geteuid") and os.geteuid() == 0:
            self.skipTest("root bypasses directory permission checks")


@unittest.skipIf(_NODE is None, _NO_NODE_REASON)
class LaunchVerdict(_TreeCase):
    def test_a_whole_copy_launches_clean(self):
        # The positive control every other verdict is read against: without it a FAIL
        # below could be the fixture rather than the omission.
        text = self.assert_run(self.whole_copy(), ccs.EXIT_LAUNCH_OK)
        self.assertIn("LAUNCH OK", text)
        self.assertIn("the module graph resolved and the listener bound", text)

    def test_the_pass_message_does_not_claim_steady_state_or_freshness(self):
        # A launch is not a soak and not a staleness verdict. The tool's precision is
        # its whole argument; a message that over-claims spends it.
        text = self.assert_run(self.whole_copy(), ccs.EXIT_LAUNCH_OK)
        self.assertIn("STEADY STATE", text)
        self.assertIn("STALE", text)
        self.assertIn("bridge:check", text)

    def test_a_missing_sibling_module_fails_with_err_module_not_found(self):
        # THE DL-230 INCIDENT, exactly: entry + manifest carry the current version
        # stamp, node_modules is fine, and one imported sibling module is absent.
        # `bridge:check`'s version and presence legs read this deployment as healthy.
        text = self.assert_run(
            self.whole_copy(omit=["channel-lib.mjs"]), ccs.EXIT_LAUNCH_FAILED
        )
        self.assertIn("LAUNCH FAILED", text)
        self.assertIn("ERR_MODULE_NOT_FOUND", text)   # the child's own diagnosis, surfaced
        self.assertIn("channel-lib.mjs", text)

    def test_a_pruned_copy_still_launches(self):
        # THE MEASUREMENT THAT RETIRES THE COMPLETENESS LEG (DL-237). Six of the ten
        # reference files are gone — README, the dotfiles, the whole tests/ directory
        # — so the completeness leg calls this deployment broken. It launches. The
        # enumeration was less precise than the thing itself, in this direction and
        # (see the case above) in the other one too.
        pruned = self.whole_copy(omit=list(_NON_LOAD_BEARING))
        self.assertFalse(os.path.exists(os.path.join(pruned, "README.md")))
        self.assertFalse(os.path.isdir(os.path.join(pruned, "tests")))

        self.assertIn("LAUNCH OK", self.assert_run(pruned, ccs.EXIT_LAUNCH_OK))

    def test_an_absent_entry_in_a_traversable_directory_is_conclusive(self):
        # The directory is readable and traversable, so "there is no entry" is a
        # conclusion we ARE entitled to draw — and there is nothing to launch.
        empty = self.tree("empty", {"package.json": "{}\n"})

        text = self.assert_run(empty, ccs.EXIT_LAUNCH_FAILED)
        self.assertIn("LAUNCH FAILED", text)
        self.assertIn(ccs.ENTRY_FILE, text)

    def test_a_dangling_symlink_is_named_as_one_not_as_a_missing_path(self):
        # The verdict was already right; the MESSAGE sent the operator after the wrong
        # thing. "does not exist" for a dangling link points at re-deploying a directory
        # when the action is repointing a link — and the PHP sibling in this same change
        # keeps exactly this distinction (`resolveNonStrict()` + the branch-1 split), so
        # collapsing it here is a divergence between two copies of one behaviour.
        target = os.path.join(self.tmp, "moved-away")
        link = os.path.join(self.tmp, "channel-server")
        os.makedirs(target)
        os.symlink(target, link)
        os.rmdir(target)

        # EXIT 1, not 2 — the absence is as PROVEN as the entry-file one (this branch is
        # reached only after the ancestor's `+x` passed), so a "not a verdict either way"
        # exit would contradict the message's own conclusion. It also has to agree with
        # the two things that already rule on this condition: the sibling entry-absent
        # case here, and `ChannelSnapshotProbe`, which calls a dangling path a `fail`.
        # DL-237 alternative (a) names "two authorities on one question that silently
        # disagree" as the defect this whole card removes.
        text = self.assert_run(link, ccs.EXIT_LAUNCH_FAILED)

        self.assertIn("symlink whose target does not exist", text)
        self.assertIn("repoint the symlink", text)
        self.assertNotIn("does not exist. Nothing was measured", text)
        self.assertNotIn("nothing was measured", text)

    def test_a_symlink_whose_target_is_invisible_is_not_called_dangling(self):
        # `lexists() and not exists()` does NOT entail "the target is gone": `exists()`
        # is false for EACCES exactly as for ENOENT, and the `+x` proven at the top of
        # resolve_entry() is the LINK's ancestor, which says nothing about the chain
        # above what the link POINTS AT. Without the target-side gate this reported
        # "the link went dangling, repoint the symlink" about an intact deployment
        # behind a 0700 parent — a confident wrong verdict, and one that contradicted
        # the non-symlink sibling case (which correctly says "not visible to this
        # user") purely on whether the operator's path happened to be a symlink.
        self.skip_as_root()
        private = os.path.join(self.tmp, "private")
        os.makedirs(private)
        deployment = self.whole_copy("intact")
        moved = os.path.join(private, "deployment")
        shutil.move(deployment, moved)
        link = os.path.join(self.tmp, "channel-server")
        os.symlink(moved, link)

        # PREMISE + POSITIVE CONTROL: traversable, the same link launches clean.
        self.assertIn("LAUNCH OK", self.assert_run(link, ccs.EXIT_LAUNCH_OK))

        os.chmod(private, 0o000)
        self.addCleanup(os.chmod, private, 0o755)
        text = self.assert_run(link, ccs.EXIT_COULD_NOT_CHECK)

        self.assertIn("COULD NOT CHECK", text)
        self.assertIn("denies this user traversal", text)
        self.assertNotIn("went dangling", text)
        self.assertNotIn("LAUNCH FAILED", text)

    def test_a_missing_path_could_not_be_checked(self):
        # Not exit 1: nothing was measured, and the caller's path may simply be wrong.
        text = self.assert_run(os.path.join(self.tmp, "nowhere"), ccs.EXIT_COULD_NOT_CHECK)
        self.assertIn("COULD NOT CHECK", text)

    def test_an_untraversable_directory_could_not_be_checked(self):
        # `os.path.isfile` is false for EACCES exactly as for ENOENT, so an absence
        # read through a directory we cannot enter is not a verdict. This is the
        # cross-user shape the whole seat-side design exists for, one level in.
        self.skip_as_root()
        deployed = self.whole_copy()
        os.chmod(deployed, 0o000)
        self.addCleanup(os.chmod, deployed, 0o755)

        text = self.assert_run(deployed, ccs.EXIT_COULD_NOT_CHECK)
        self.assertIn("COULD NOT CHECK", text)
        self.assertIn("not traversable by this user", text)
        self.assertNotIn("LAUNCH FAILED", text)

    def test_a_path_naming_some_other_file_could_not_be_checked(self):
        wrapper = os.path.join(self.tree("wrap", {"launch.js": "//\n"}), "launch.js")

        text = self.assert_run(wrapper, ccs.EXIT_COULD_NOT_CHECK)
        self.assertIn("COULD NOT CHECK", text)
        self.assertIn("is not a channel-server directory", text)

    def test_the_entry_mjs_form_is_accepted(self):
        entry = os.path.join(self.whole_copy(), ccs.ENTRY_FILE)

        self.assertIn("LAUNCH OK", self.assert_run(entry, ccs.EXIT_LAUNCH_OK))

    def test_an_entry_that_exits_0_without_binding_is_inconclusive(self):
        # EXIT 0 IS NECESSARY BUT NOT SUFFICIENT. A zero-length entry — the shape a
        # truncated copy or an interrupted write leaves — parses, runs, starts nothing
        # and exits 0. Reporting LAUNCH OK there would make the tool's exit code say the
        # opposite of what it measured, which is the one thing a diagnostic must never
        # do.
        #
        # Exit 2, NOT exit 1, and the reason is a constraint on the whole tool: deciding
        # FAILED on the ABSENCE of a listening line would rest the verdict on matching a
        # log string, so rewording that line upstream would false-fail every healthy
        # deployment on the fleet. The marker may WITHHOLD a pass; it may never produce
        # a failure.
        empty = self.tree("empty-entry", {ccs.ENTRY_FILE: ""})

        text = self.assert_run(empty, ccs.EXIT_COULD_NOT_CHECK)

        self.assertIn("COULD NOT CHECK", text)
        self.assertIn("never reported a listening endpoint", text)
        self.assertIn("NOT a pass", text)
        self.assertNotIn("LAUNCH OK", text)
        self.assertNotIn("LAUNCH FAILED", text)

    def test_an_entry_truncated_at_a_valid_parse_point_is_inconclusive(self):
        # The realistic sibling of the case above: the file is not empty, it is
        # syntactically fine, it imports its sibling module successfully — and it stops
        # before the listener. Every stat-derived check in `bridge:check` reads this
        # deployment as healthy, and so would an exit-code-only launch assert.
        truncated = self.tree(
            "truncated",
            {
                ccs.ENTRY_FILE: "import { hello } from './channel-lib.mjs';\nhello();\n",
                "channel-lib.mjs": _LIB_SOURCE,
            },
        )

        text = self.assert_run(truncated, ccs.EXIT_COULD_NOT_CHECK)

        self.assertIn("COULD NOT CHECK", text)
        self.assertNotIn("LAUNCH OK", text)

    def test_a_signal_killed_child_is_an_environment_fact_not_a_verdict(self):
        # An OOM-kill or a cgroup/container reap yields returncode -9 and NO stderr at
        # all. Reported as LAUNCH FAILED that reads "conclusive … will not come up", and
        # sends an operator to re-copy a deployment that was never given the chance to
        # start. Same class as "no node on PATH", which is already a 2 for exactly this
        # reason.
        suicide = self.tree(
            "sigkill",
            {
                ccs.ENTRY_FILE: "import './channel-lib.mjs';\n"
                "process.kill(process.pid, 'SIGKILL');\nsetInterval(() => {}, 1000);\n",
                "channel-lib.mjs": _LIB_SOURCE,
            },
        )

        text = self.assert_run(suicide, ccs.EXIT_COULD_NOT_CHECK)

        self.assertIn("COULD NOT CHECK", text)
        # Named readably: `-9` is a POSIX wait-status detail, not an operator-facing fact.
        self.assertIn("SIGKILL (9)", text)
        # ⚠ ANCHORED TO THE MESSAGE TAIL, NOT A BARE "-9". The asserted text embeds the
        # entry PATH, rooted at mkdtemp(prefix="check-channel-snapshot-test-"), and
        # tempfile's name alphabet is `a-z0-9_` — so 1 name in 37 begins with `9` and the
        # path itself contains the substring `-9`. A bare assertNotIn("-9") is therefore a
        # ~2.7% flake (measured 80/3000, and reproducible on demand by forcing the name):
        # a test that fails for a reason unrelated to what it checks. Widening the prefix
        # would only move the collision, not remove it.
        self.assertNotIn("killed by -9", text)
        self.assertNotIn("LAUNCH FAILED", text)
        self.assertNotIn("will not come up", text)

    def test_the_pass_message_discloses_the_pinned_transport(self):
        # The pin's cost, disclosed where the operator reads the verdict rather than
        # left in a DL: `.mcp.json`'s env block carries BRIDGE_CHANNEL_TRANSPORT on
        # every deployment, so the transport the session will actually use is precisely
        # the one this assert guarantees not to exercise.
        text = self.assert_run(self.whole_copy(), ccs.EXIT_LAUNCH_OK)

        self.assertIn("NOR your configured transport", text)

    def test_the_unobserved_bind_message_does_not_assume_corruption(self):
        # A CUSTOMIZED entry that reworded its listening line lands here too, and the
        # entry's own header invites customization — so "your file is corrupt" is a
        # misdiagnosis for that operator.
        empty = self.tree("empty-entry-msg", {ccs.ENTRY_FILE: ""})

        text = self.assert_run(empty, ccs.EXIT_COULD_NOT_CHECK)

        self.assertIn("CUSTOMIZED", text)
        self.assertIn("different listening line", text)

    def test_a_hanging_entry_is_inconclusive(self):
        # BUILD REQUIREMENT (b), from the failing side: a survival-based assert would
        # call this a PASS. Surviving the timeout is an unfinished measurement, and
        # the tool must never report it as a verdict in either direction.
        hung = self.tree(
            "hung",
            {
                ccs.ENTRY_FILE: "import './channel-lib.mjs';\nsetInterval(() => {}, 1000);\n",
                "channel-lib.mjs": _LIB_SOURCE,
            },
        )

        text = self.assert_run(hung, ccs.EXIT_COULD_NOT_CHECK, timeout=2)
        self.assertIn("still running", text)
        self.assertIn("NOT a pass", text)

    def test_a_node_that_cannot_be_executed_is_not_a_deployment_verdict(self):
        # THE BLOCKER THIS CASE EXISTS FOR. Only TimeoutExpired was caught around
        # subprocess.run, so every OSError from the exec escaped — and CPython's exit
        # status for an uncaught exception is 1, which is EXIT_LAUNCH_FAILED EXACTLY. A
        # healthy deployment was therefore reported "conclusive … will not come up"
        # because of a fault in the machine, which the tool's own comments call the worst
        # thing a diagnostic can produce.
        #
        # Reproduced with a `node` shim that is not a valid executable (ENOEXEC). The
        # same OSError family covers `node` on a `noexec` mount — shutil.which uses
        # os.access(X_OK), which does not model mount flags, so the path resolves
        # happily — and a fork refused under RLIMIT_NPROC/ENOMEM, which is the SAME
        # physical condition as the signal-kill already routed to exit 2.
        fake_bin = self.tree("fake-bin", {"node": "this is not an executable format\n"})
        os.chmod(os.path.join(fake_bin, "node"), 0o755)
        env = dict(os.environ, PATH=fake_bin)

        text = self.assert_run(self.whole_copy(), ccs.EXIT_COULD_NOT_CHECK, env=env)

        self.assertIn("COULD NOT CHECK", text)
        self.assertIn("could not be executed", text)
        self.assertNotIn("LAUNCH FAILED", text)
        self.assertNotIn("will not come up", text)

    def test_a_temp_dir_that_cannot_be_created_is_not_a_deployment_verdict(self):
        # The sibling site: mkdtemp raises the same OSError family (a full or read-only
        # $TMPDIR), and it sat outside any handler for the same reason.
        def refusing_mkdtemp(**kwargs):
            raise OSError(28, "No space left on device")

        text = self.assert_run(
            self.whole_copy(), ccs.EXIT_COULD_NOT_CHECK, mkdtemp=refusing_mkdtemp
        )

        self.assertIn("COULD NOT CHECK", text)
        self.assertIn("temp directory", text)
        self.assertNotIn("LAUNCH FAILED", text)

    def test_no_node_on_path_is_an_environment_problem_not_a_verdict(self):
        # Named distinctly because the deployment may be perfect: this is a fact
        # about the box, and reporting it as a launch failure sends the operator to
        # re-copy a healthy snapshot.
        empty_path = self.tree("no-bin", {"placeholder": "\n"})
        env = dict(os.environ, PATH=empty_path)

        text = self.assert_run(self.whole_copy(), ccs.EXIT_COULD_NOT_CHECK, env=env)
        self.assertIn("node", text)
        self.assertIn("COULD NOT CHECK", text)
        self.assertNotIn("LAUNCH FAILED", text)

    def test_main_returns_the_verdict_through_the_argv_seam(self):
        deployed = self.whole_copy(omit=["channel-lib.mjs"])

        self.assertEqual(ccs.EXIT_LAUNCH_FAILED, ccs.main([deployed]))
        self.assertEqual(ccs.EXIT_LAUNCH_OK, ccs.main([self.whole_copy("healthy")]))


class ChildEnvironment(unittest.TestCase):
    """BUILD REQUIREMENT (a), at the pure-function level."""

    def test_child_env_overrides_every_channel_addressing_input(self):
        # Assignments, never setdefault: the seat exports its REAL values, and each of
        # the four is an input that steers the child at a live endpoint — the transport
        # that decides WHICH endpoint is used at all, the unix socket it binds and
        # unlinks, the loopback port it binds under `http`, and the directory a
        # `.FAILED` marker lands in. This dict assertion is the PRIMARY guard: each
        # line here reds on its own single mutation, which the behavioural witnesses
        # below cannot all give (see the layering note in SocketGuard).
        parent = {
            "BRIDGE_CHANNEL_TRANSPORT": "unix",
            "BRIDGE_CHANNEL_SOCKET": "/run/user/1000/live.sock",
            "BRIDGE_CHANNEL_PORT": "8788",
            "BRIDGE_CHANNEL_NAME": "seat-agent",
            "XDG_RUNTIME_DIR": "/run/user/1000",
            "PATH": "/usr/bin",
        }

        env = ccs.child_env(parent, "/tmp/throwaway/launch-assert.sock", "/tmp/throwaway")

        self.assertEqual("http", env["BRIDGE_CHANNEL_TRANSPORT"])
        self.assertEqual("/tmp/throwaway/launch-assert.sock", env["BRIDGE_CHANNEL_SOCKET"])
        self.assertEqual("0", env["BRIDGE_CHANNEL_PORT"])
        self.assertEqual("/tmp/throwaway", env["XDG_RUNTIME_DIR"])
        # Untouched: the rest of the seat's environment is what makes this a faithful
        # launch (its PATH, its node, its channel name).
        self.assertEqual("seat-agent", env["BRIDGE_CHANNEL_NAME"])
        self.assertEqual("/usr/bin", env["PATH"])
        # …and the parent dict is not mutated.
        self.assertEqual("/run/user/1000/live.sock", parent["BRIDGE_CHANNEL_SOCKET"])
        self.assertEqual("unix", parent["BRIDGE_CHANNEL_TRANSPORT"])

    def test_the_throwaway_socket_lives_inside_the_private_temp_dir(self):
        self.assertEqual(
            os.path.join("/tmp/priv", ccs.SOCKET_BASENAME),
            ccs.throwaway_socket_path("/tmp/priv"),
        )


@unittest.skipIf(_NODE is None, _NO_NODE_REASON)
class SocketGuard(_TreeCase):
    def test_the_parents_transport_never_reaches_the_child(self):
        # THE WINDOWS FALSE-FAIL, at its mechanism. The entry defaults TRANSPORT to
        # `unix` when unset, and on a Windows seat the real value is exported by the
        # LAUNCHER process (examples/start-claude.ps1) and/or .mcp.json's `env` block —
        # neither of which a freshly-opened PowerShell inherits. Unpinned, the child
        # there binds a filesystem socket, Win32 rejects it with EACCES, and this tool
        # prints LAUNCH FAILED about a healthy deployment.
        #
        # That exact platform failure is not reproducible on Linux, so the MECHANISM is
        # what is asserted: a parent transport value must not reach the child. The
        # synthetic entry refuses an unknown transport with a non-zero exit (as the
        # shipped one does via refuseDeaf), so an unpinned run reports LAUNCH FAILED
        # here — pure exit-code evidence, no log-string coupling. Reds on dropping the
        # BRIDGE_CHANNEL_TRANSPORT pin, alone.
        env = dict(os.environ, BRIDGE_CHANNEL_TRANSPORT="bogus-transport")

        text = self.assert_run(self.whole_copy(), ccs.EXIT_LAUNCH_OK, env=env)

        self.assertIn("LAUNCH OK", text)
        self.assertNotIn("refusing", text)

    def test_the_seats_configured_port_is_never_reached(self):
        # The `http` leg's behavioural witness, and `http` is the FRESH-WINDOWS DEFAULT
        # (v0.69.0) as well as what this tool now pins — so it gets the same standard of
        # evidence as the unix leg, not a dict assertion alone.
        #
        # The sentinel port is HELD OPEN for the duration of the run. Without the
        # BRIDGE_CHANNEL_PORT=0 override the child reaches for it, hits EADDRINUSE and
        # exits non-zero, so this asserts exit 0 while the seat's configured port is
        # occupied: proof the child never went near it. Reds on dropping that override,
        # alone.
        import socket

        holder = socket.socket()
        holder.bind(("127.0.0.1", 0))
        holder.listen(1)
        self.addCleanup(holder.close)
        occupied = holder.getsockname()[1]
        env = dict(os.environ, BRIDGE_CHANNEL_PORT=str(occupied))

        text = self.assert_run(self.whole_copy(), ccs.EXIT_LAUNCH_OK, env=env)

        self.assertIn("LAUNCH OK", text)

    def test_nothing_is_written_into_the_seats_runtime_dir(self):
        # `XDG_RUNTIME_DIR` is where the shipped entry drops its `.FAILED` marker under
        # either transport. Without the override the child writes into the seat's REAL
        # runtime dir, beside the live channel's own marker — a diagnostic leaving
        # debris in the directory an operator reads to diagnose a deaf seat. The
        # synthetic entry's http bind witness lands there by the same rule, which is
        # what makes this observable. Reds on dropping the XDG override, alone.
        seat_runtime = os.path.join(self.tmp, "seat-runtime")
        os.makedirs(seat_runtime, mode=0o700)
        env = dict(os.environ, XDG_RUNTIME_DIR=seat_runtime)

        self.assert_run(self.whole_copy(), ccs.EXIT_LAUNCH_OK, env=env)

        self.assertEqual([], os.listdir(seat_runtime))

    def test_the_bind_witness_is_actually_produced_under_both_transports(self):
        # ⚠ THE POSITIVE CONTROL FOR EVERY WITNESS ASSERTION IN THIS FILE, and it was
        # missing. `${SOCKET_PATH}.bound` and `bound-http-${PORT}` appeared only in the
        # fixture and in two ABSENCE assertions — never once observed PRESENT. The
        # fixture's `witness()` is wrapped in `try { … } catch {}`, so if it ever stopped
        # writing, both of those tests would pass forever and the guard would be a
        # decoration. That is exactly what DL-237 (e) records the FIRST version of the
        # sentinel test as being, so the fix for the decoration was itself unwitnessed.
        #
        # Driven directly rather than through the tool, because the tool PINS the
        # transport to `http` — so the unix witness is unreachable through it, and a
        # mechanism proven for only one transport is half a control.
        for transport, env_extra, witness_of in (
            ("unix", {"BRIDGE_CHANNEL_SOCKET": os.path.join(self.tmp, "w.sock")},
             lambda d: os.path.join(self.tmp, "w.sock.bound")),
            ("http", {"BRIDGE_CHANNEL_PORT": "0"}, lambda d: os.path.join(d, "bound-http-0")),
        ):
            with self.subTest(transport=transport):
                runtime = tempfile.mkdtemp(prefix=f"witness-{transport}-")
                self.addCleanup(shutil.rmtree, runtime, ignore_errors=True)
                env = dict(
                    os.environ,
                    BRIDGE_CHANNEL_TRANSPORT=transport,
                    XDG_RUNTIME_DIR=runtime,
                    **env_extra,
                )
                proc = subprocess.run(
                    [_NODE, os.path.join(self.whole_copy(f"witness-tree-{transport}"), ccs.ENTRY_FILE)],
                    stdin=subprocess.DEVNULL, capture_output=True, text=True, env=env, timeout=15,
                )

                self.assertEqual(0, proc.returncode, proc.stderr)
                self.assertTrue(
                    os.path.exists(witness_of(runtime)),
                    f"the {transport} bind witness was NOT produced — every absence "
                    "assertion that relies on it is vacuous",
                )

    def test_the_http_witness_is_observed_inside_the_throwaway_dir(self):
        # The other half: the witness is produced DURING a real tool run, in the
        # throwaway directory, before the `finally: rmtree` erases it. Without this, the
        # runtime-dir absence assertion could be green because nothing is ever written
        # anywhere, rather than because it was written in the right place.
        seen = {}
        real_rmtree = shutil.rmtree

        def snapshotting_rmtree(path, **kwargs):
            seen["contents"] = sorted(os.listdir(path))
            return real_rmtree(path, **kwargs)

        with mock.patch.object(ccs.shutil, "rmtree", snapshotting_rmtree):
            self.assert_run(self.whole_copy(), ccs.EXIT_LAUNCH_OK)

        self.assertIn("bound-http-0", seen.get("contents", []))

    def test_a_live_socket_in_the_parent_env_is_never_bound_or_unlinked(self):
        # The unix leg, kept as DEFENCE IN DEPTH — and labelled honestly rather than
        # banked as single-mutation coverage. Pinning the transport to `http` means the
        # entry never reads BRIDGE_CHANNEL_SOCKET at all, so this case now reds only if
        # BOTH the transport pin and the socket override are dropped. That is exactly
        # what "belt and braces" means; each override's own single-mutation guard is the
        # dict assertion in ChildEnvironment.
        #
        # ⚠ DO NOT "SIMPLIFY" THIS TO THE lexists() CHECK ALONE. The obvious form is a
        # DECORATION and was measured to be: the server UNLINKS the socket it bound, so
        # "no socket file afterwards" is equally true of a path that was never touched
        # and one that was bound and then DESTROYED — which is the whole damage. The
        # first version of this test asserted only the first line below and stayed GREEN
        # under the mutation it exists to catch, because the child dutifully created the
        # sentinel, bound it, and removed it again. The persistent `.bound` witness is
        # the assertion that actually goes red.
        sentinel = os.path.join(self.tmp, "live-channel.sock")
        env = dict(
            os.environ,
            BRIDGE_CHANNEL_TRANSPORT="unix",
            BRIDGE_CHANNEL_SOCKET=sentinel,
            BRIDGE_CHANNEL_NAME="live-agent",
        )
        self.assertFalse(os.path.lexists(sentinel), "premise: nothing holds the live path yet")

        self.assert_run(self.whole_copy(), ccs.EXIT_LAUNCH_OK, env=env)

        self.assertFalse(os.path.lexists(sentinel), "the live socket must not be left behind")
        self.assertFalse(
            os.path.lexists(sentinel + ".bound"),
            "the live socket path was BOUND — this run would have unlinked the seat's socket",
        )

    def test_the_throwaway_socket_is_cleaned_up_with_its_temp_dir(self):
        minted = []

        def recording_mkdtemp(**kwargs):
            path = tempfile.mkdtemp(**kwargs)
            minted.append(path)
            return path

        self.assert_run(self.whole_copy(), ccs.EXIT_LAUNCH_OK, mkdtemp=recording_mkdtemp)

        self.assertEqual(1, len(minted))
        self.assertFalse(os.path.exists(minted[0]))

    def test_a_pre_existing_socket_path_refuses_and_leaves_it_alone(self):
        # Unreachable while the mkdtemp construction holds — which is why it is
        # asserted rather than assumed. The child UNLINKS the socket it bound, so a
        # path this run did not create must never be launched onto, and a refusal
        # must not tidy up either: if the construction broke, this run does not know
        # what it is looking at.
        occupied = os.path.join(self.tmp, "occupied")
        os.makedirs(occupied, mode=0o700)
        squatter = ccs.throwaway_socket_path(occupied)
        with open(squatter, "w", encoding="utf-8") as fh:
            fh.write("not ours\n")

        text = self.assert_run(
            self.whole_copy(), ccs.EXIT_COULD_NOT_CHECK, mkdtemp=lambda **kw: occupied
        )

        self.assertIn("refusing to launch", text)
        self.assertIn("Nothing was measured and nothing was removed", text)
        self.assertTrue(os.path.exists(squatter), "the refusal must not unlink it")
        self.assertTrue(os.path.isdir(occupied), "…nor remove the directory around it")

    def test_a_declared_live_socket_equal_to_the_throwaway_refuses(self):
        # The operator's own escape hatch: they name their configured channel.socket
        # and this run refuses rather than launching onto it.
        private = os.path.join(self.tmp, "private")
        os.makedirs(private, mode=0o700)
        collides = ccs.throwaway_socket_path(private)

        text = self.assert_run(
            self.whole_copy(),
            ccs.EXIT_COULD_NOT_CHECK,
            live_socket=collides,
            mkdtemp=lambda **kw: private,
        )

        self.assertIn("refusing to launch", text)
        self.assertIn("live socket you declared", text)

    def test_a_declared_live_socket_elsewhere_does_not_block_the_assert(self):
        # The positive control for the refusal above: a real, DIFFERENT live socket
        # path must not turn every run into a COULD NOT CHECK.
        text = self.assert_run(
            self.whole_copy(),
            ccs.EXIT_LAUNCH_OK,
            live_socket=os.path.join(self.tmp, "somewhere-else.sock"),
        )
        self.assertIn("LAUNCH OK", text)


@unittest.skipIf(_NODE is None, _NO_NODE_REASON)
class ShippedReference(_TreeCase):
    """The real surface (canon #9) — the synthetic entry above is a stand-in, and a
    stand-in cannot certify the shipped one."""

    def test_this_checkout_s_own_channel_server_launches(self):
        modules = os.path.join(_REFERENCE, "node_modules")
        if not os.path.isdir(modules):
            # LOUD, never silent: this is the only case that exercises the entry the
            # fleet actually deploys.
            reason = (
                f"LOUD SKIP: {modules} is absent, so the SHIPPED channel-server entry was NOT "
                "launched — run `npm ci` in examples/channel-servers to make this case real."
            )
            print(reason, file=sys.stderr)
            self.skipTest(reason)

        text = self.assert_run(_REFERENCE, ccs.EXIT_LAUNCH_OK)
        self.assertIn("LAUNCH OK", text)

    def test_the_shipped_entry_imports_the_sibling_module_the_dl230_case_removes(self):
        # The premise the DL-230 regression case rests on, read at SOURCE rather than
        # recalled: if the shipped entry ever stops importing `channel-lib.mjs`, the
        # incident shape changes and the synthetic case above stops standing for it.
        with open(os.path.join(_REFERENCE, ccs.ENTRY_FILE), encoding="utf-8") as fh:
            source = fh.read()

        self.assertIn("from './channel-lib.mjs'", source)


@unittest.skipIf(_NODE is None, _NO_NODE_REASON)
class PipedConsumption(_TreeCase):
    """The tool's whole product is a machine-readable exit code, so `| head`, `| tee`
    and `| grep -q` are the DOCUMENTED usage — not an edge case.

    ⚠ WHICH CASE DISCRIMINATES, stated correctly — because getting this wrong is what
    produced the retracted ">64 KiB, precautionary" claim, and an inherited wrong
    attribution sends the next reader to measure the same wrong regime.

    THE DISCRIMINATING CASE IS A READER THAT CLOSES WITHOUT READING (`read_a_line=False`),
    at ANY size. It breaks the pipe every time, and it is what reds `no-flush`,
    `no-dup2` and `delivery-overwrites`.

    THE REGIME AXIS IS A BREADTH CHECK, not the discriminator — and it is weaker than it
    was, because the fix itself changed the timing. Output is now a single
    `sys.stdout.write()` issued AFTER the child has exited, so a reader's `readline()`
    cannot return until that write has begun, and for payloads within the pipe's
    capacity the write completes before the reader's `close()` is visible: no pipe
    breaks at all. Instrumented post-fix, 4 of the 6 `read_a_line=True` cells never
    break one. It is kept because a future change that streams output again would make
    the regimes diverge, and because the sizes bracket the real boundaries.

    The size table that motivated all this describes the PRE-FIX file, where the write
    happened incrementally. It is history, not a current property, and it is NOT copied
    here: it lived in three places, the third went unsynced and became false in the
    present tense, and one owner beside the code that makes it true is the fix for that.
    See `_deliver`'s docstring in check-channel-snapshot.py.

    The predecessor of this class asserted "the verdict survives a closed pipe on every
    exit code" using 695 B and 1,512 B fixtures that never broke a pipe either, and left
    the mutation deleting the whole output handler GREEN.
    """

    # Sized to land either side of the 8 KiB pipe-buffer / 64 KiB pipe-capacity
    # boundaries. Named rather than numbered so a future reader knows WHY.
    REGIMES = {"under_8KiB": 100, "between_8_and_64KiB": 800, "over_64KiB": 4000}

    def _noisy_tree(self, lines, name, exit_code=None):
        tail = f"process.exit({exit_code});\n" if exit_code is not None else ""
        return self.tree(
            name,
            {
                "channel-lib.mjs": _LIB_SOURCE,
                ccs.ENTRY_FILE: "import { hello } from './channel-lib.mjs';\n"
                f"for (let i = 0; i < {lines}; i++) console.error(`line ${{i}} ${{hello()}} ${{'x'.repeat(60)}}`);\n"
                + tail,
            },
        )

    def _piped_exit(self, path, read_a_line=True):
        proc = subprocess.Popen(
            [sys.executable, os.path.join(_HERE, "check-channel-snapshot.py"), path],
            stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
        )
        if read_a_line:
            proc.stdout.readline()
        proc.stdout.close()   # the reader goes away — exactly what `head -1` does
        return proc.wait()

    def _unpiped_exit(self, path):
        return subprocess.run(
            [sys.executable, os.path.join(_HERE, "check-channel-snapshot.py"), path],
            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
        ).returncode

    def test_a_closed_pipe_never_changes_a_computed_verdict_in_any_regime(self):
        # Both directions of the same category error. Rounds 4-6 fixed it pointed one
        # way (an environment fault reported as a conclusive FAILED); a conclusive
        # FAILED downgraded to COULD NOT CHECK because prose could not be delivered is
        # equally wrong — the measurement completed, and delivery is not the
        # measurement.
        for regime, lines in self.REGIMES.items():
            for verdict, exit_code in (("failed", 3), ("could-not-check", None)):
                tree = self._noisy_tree(lines, f"noisy-{regime}-{verdict}", exit_code)
                unpiped = self._unpiped_exit(tree)
                # BOTH reader behaviours. `read_a_line=True` is the breadth check and
                # mostly does NOT break the pipe post-fix (see the class docstring);
                # `read_a_line=False` breaks it at every size and is what actually
                # exercises the delivery path.
                for read_a_line in (True, False):
                    with self.subTest(regime=regime, verdict=verdict, read_a_line=read_a_line):
                        self.assertEqual(
                            unpiped,
                            self._piped_exit(tree, read_a_line=read_a_line),
                            "piping changed the verdict",
                        )

    def test_a_short_clean_run_survives_a_closed_pipe(self):
        # The LAUNCH OK path can only ever be short (it prints two lines), so it lives
        # in the <8 KiB regime by construction — asserted rather than assumed, and with
        # the pipe closed before a single byte is read as well as after.
        tree = self.whole_copy("piped-ok")

        self.assertEqual(ccs.EXIT_LAUNCH_OK, self._piped_exit(tree))
        self.assertEqual(ccs.EXIT_LAUNCH_OK, self._piped_exit(tree, read_a_line=False))

    def test_an_unanticipated_fault_lands_on_could_not_check_not_on_failed(self):
        # THE GUARD FOR THE GENERATOR ITSELF, and it was missing until the mutation that
        # deletes the catch-all came back GREEN. Four rounds each enumerated one more
        # exception type (OSError at the exec, at mkdtemp, BrokenPipeError at print) and
        # left the aliasing intact: `main()` had no default, so CPython's
        # exit-1-on-uncaught-exception IS EXIT_LAUNCH_FAILED.
        #
        # The probe is deliberately a type NOTHING in this file enumerates. If it were
        # an OSError the specific handlers would catch it and this would test them
        # instead — the point is precisely the fault nobody anticipated.
        tree = self.whole_copy("unanticipated")
        minted = []

        def recording_mkdtemp(**kwargs):
            path = tempfile.mkdtemp(**kwargs)
            minted.append(path)
            return path

        # `throwaway_socket_path` is patched at a RUNTIME call site, not `mkdtemp` —
        # `run_launch_assert` binds that as a DEFAULT ARGUMENT at definition time, so
        # patching the module attribute silently does nothing. That blindness has now
        # cost two attempts in this file, which is why the injection point is named here
        # rather than left to be rediscovered. It is also exactly the call the ownership
        # `try` wraps, so one fixture drives both halves below.
        def exploding(*_args, **_kwargs):
            raise RuntimeError("nobody enumerated this")

        # HALF 1 — the VERDICT, through `main()`, where the catch-all lives.
        with mock.patch.object(ccs, "throwaway_socket_path", exploding):
            code = ccs.main([tree])

        self.assertEqual(ccs.EXIT_COULD_NOT_CHECK, code)
        self.assertNotEqual(ccs.EXIT_LAUNCH_FAILED, code)

        # HALF 2 — the CLEANUP, driven directly because `main()` has no seam for
        # `mkdtemp` (see above). Without this assertion the ownership guard is the one
        # layer of six with no suite guard: removing it leaks exactly one
        # /tmp/channel-launch-assert-* per run while every test stays GREEN, so the fix
        # would regress in silence — the failure mode nine rounds of this branch have
        # been about.
        with mock.patch.object(ccs, "throwaway_socket_path", exploding):
            with self.assertRaises(RuntimeError):
                ccs.run_launch_assert(tree, out=io.StringIO(), mkdtemp=recording_mkdtemp)

        self.assertEqual(1, len(minted), "the run did not reach mkdtemp")
        self.assertFalse(os.path.exists(minted[0]), "a fault before the launch leaked the temp dir")

    def test_a_closed_stdout_at_startup_does_not_invert_any_verdict(self):
        # `>&-`: fd 1 is CLOSED before the process starts, so CPython sets
        # `sys.stdout = None`. The delivery fallback then raised `AttributeError:
        # 'NoneType' object has no attribute 'fileno'` — a type its `except OSError`
        # did not list — which escaped to CPython's exit-1 and reported a HEALTHY
        # deployment as *LAUNCH FAILED, conclusive, will not come up*. The same
        # signature as rounds 4, 5 and 6, produced by the enumeration that was left
        # sitting INSIDE the fix meant to retire enumerations.
        #
        # Also reachable on Windows under `pythonw.exe` — the population the transport
        # pin exists to serve. No other case in this suite closes fd 1, which is why
        # 46/46 stayed green with it live.
        cases = {
            "healthy": (self.whole_copy("closed-fd-ok"), ccs.EXIT_LAUNCH_OK),
            "missing-module": (self.whole_copy("closed-fd-fail", omit=["channel-lib.mjs"]), ccs.EXIT_LAUNCH_FAILED),
            "no-entry": (self.tree("closed-fd-empty", {"package.json": "{}\n"}), ccs.EXIT_LAUNCH_FAILED),
            "unbindable": (self.tree("closed-fd-nobind", {ccs.ENTRY_FILE: ""}), ccs.EXIT_COULD_NOT_CHECK),
        }

        for label, (path, expected) in cases.items():
            with self.subTest(case=label):
                with open(os.devnull) as devnull:
                    proc = subprocess.Popen(
                        [sys.executable, os.path.join(_HERE, "check-channel-snapshot.py"), path],
                        stdout=devnull.fileno(), stderr=subprocess.PIPE, close_fds=True,
                        preexec_fn=lambda: os.close(1),
                    )
                    err = proc.stderr.read()
                    code = proc.wait()

                self.assertEqual(expected, code, f"{label}: {err.decode('utf-8', 'replace')[:400]}")

    def test_the_process_exit_is_always_a_valid_verdict(self):
        # THE PROPERTY, guarded once instead of restated as prose in four places. The
        # generator behind four rounds of patches was that CPython's exit-1-on-uncaught
        # -exception ALIASES EXIT_LAUNCH_FAILED, so any unanticipated fault became a
        # confident "your deployment is broken" — and 120 (the shutdown-flush path) is
        # not even in the contract. An enumeration of exception types cannot guard that;
        # this can.
        valid = {ccs.EXIT_LAUNCH_OK, ccs.EXIT_LAUNCH_FAILED, ccs.EXIT_COULD_NOT_CHECK}
        empty = self.tree("prop-empty", {"package.json": "{}\n"})
        cases = {
            "healthy": (self.whole_copy("prop-ok"), False),
            "missing-module": (self.whole_copy("prop-fail", omit=["channel-lib.mjs"]), False),
            "no-entry": (empty, False),
            "absent-path": (os.path.join(self.tmp, "nowhere"), False),
            "noisy-mid-regime": (self._noisy_tree(800, "prop-noisy"), True),
            "noisy-mid-regime-failed": (self._noisy_tree(800, "prop-noisy-fail", 3), True),
        }

        for label, (path, pipe) in cases.items():
            with self.subTest(case=label):
                code = self._piped_exit(path) if pipe else self._unpiped_exit(path)

                self.assertIn(code, valid, f"{label} produced {code}, outside the exit contract")


class ToolShape(unittest.TestCase):
    def test_help_and_docstring_state_the_exit_contract_the_CODE_defines(self):
        # ⚠ COMPARED AGAINST THE CODE, NOT AGAINST EACH OTHER. The previous version
        # cross-checked the two TEXTS, so it was structurally blind to a cause missing
        # from BOTH — which is exactly what happened (a broken pipe was a seventh cause
        # while three places said "SIX", and 120 was outside the contract entirely).
        #
        # The cause LIST is deliberately no longer asserted at all. It was an
        # enumeration in a test guarding an enumeration in prose, and it drifted three
        # times. What is asserted is what the code actually defines: the three exit
        # VALUES, and the definitional claim that 2 means no verdict was reached. The
        # property test above guards the rest, by measurement.
        help_text = ccs.build_parser().format_help().lower()
        doc_text = ccs.__doc__.lower()

        for value in (ccs.EXIT_LAUNCH_OK, ccs.EXIT_LAUNCH_FAILED, ccs.EXIT_COULD_NOT_CHECK):
            self.assertIn(f"exit {value}", help_text, f"--help does not document exit {value}")
            self.assertIn(str(value), doc_text)

        for text, label in ((help_text, "--help"), (doc_text, "the module docstring")):
            self.assertIn("could not check", text, label)
            self.assertIn("no verdict", text, f"{label} does not state what a 2 MEANS")

        # And the values are distinct — the whole generator was exit 1 aliasing.
        self.assertEqual(
            3, len({ccs.EXIT_LAUNCH_OK, ccs.EXIT_LAUNCH_FAILED, ccs.EXIT_COULD_NOT_CHECK})
        )

    def test_the_tool_is_stdlib_only(self):
        # It must survive being copied to a seat with no bridge checkout.
        with open(os.path.join(_HERE, "check-channel-snapshot.py"), encoding="utf-8") as fh:
            source = fh.read()

        for line in source.splitlines():
            if line.startswith(("import ", "from ")) and "__future__" not in line:
                module = line.split()[1].split(".")[0]
                self.assertIn(
                    module,
                    {"argparse", "io", "os", "shutil", "signal", "subprocess", "sys", "tempfile"},
                    f"non-stdlib import: {line}",
                )

    def test_the_snapshot_probe_path_has_no_reachable_way_to_execute(self):
        # A TRIPWIRE, NOT A PROOF — and the prose says so because the previous two
        # revisions of it did not. This asserted four things and then claimed they meant
        # the class "cannot delegate to a collaborator that would" exec. Review ran five
        # evasions against it and five passed GREEN, two of them literally delegation:
        # an INDENTED `use LaunchesNode;` (a same-namespace trait — and `    use X;` is
        # this codebase's own PSR-12 style, while the assertion only caught column-0
        # imports), and `extends NodeLauncher` with an inherited method still spelled
        # `self::`. Those are closed below. `include`, bare dynamic invoke `$f(...)` and
        # the fully-qualified `\shell_exec(` are closed too.
        #
        # WHAT IS NOT CLOSED, stated because an enumerated blocklist is never complete:
        #   - variable-variables, a computed class name, a callable through an array;
        #   - a NAMESPACED FREE FUNCTION (`\App\Bridge\Support\launchNode(...)`) — the
        #     one shape that survives both this tripwire AND the formatter below;
        #   - IDENTIFIER-CASE VARIANCE (`SHELL_EXEC(`, `USE `, `INCLUDE`). PHP is
        #     case-insensitive for these and this scan is not — but `pint --preset
        #     laravel` normalises every one of them, so the COMPOSITE gate (pint + this
        #     test) holds where this test alone does not. That was chased to ground
        #     rather than assumed, and it is recorded so nobody later reads this test as
        #     covering it on its own.
        # So the claim is the weakest one the assertions actually support: this catches
        # the SHAPES BELOW, and the design rests on review, not on this test.
        #
        # Why it is worth keeping anyway: the realistic regression is not an adversary,
        # it is a future maintainer adding a launch leg the honest way — `use`, `new`,
        # `extends`, or a plain `proc_open` — and every one of those trips this.
        probe = os.path.join(os.path.dirname(_HERE), "app/Bridge/Support/ChannelSnapshotProbe.php")
        with open(probe, encoding="utf-8") as fh:
            raw = fh.read()
        code = _php_code_only(raw)

        self.assertEqual([], _exec_primitives_in(code), "the snapshot probe gained a process-launch primitive")
        self.assertNotIn("new ", code, "the snapshot probe instantiates something — it could delegate an exec")
        # Any indentation, not just column 0 — `    use SomeTrait;` is the shape that
        # got through, and it is how traits are actually written here.
        self.assertNotIn("use ", code, "the snapshot probe imported a class or used a trait")
        # No parent to inherit an exec from. Pinned as the exact declaration, so
        # `extends NodeLauncher` cannot slip in behind a `self::` call.
        self.assertIn("final class ChannelSnapshotProbe\n{", raw, "the probe gained a parent class")

        statics = set(re.findall(r"[A-Za-z_\\][A-Za-z0-9_\\]*::", code))
        # POSITIVE CONTROL first: an `or {"self::"}` fallback used to sit here, which
        # made the assertion pass on an EMPTY set — indistinguishable from the regex
        # having misfired. Prove it matched something before trusting what it did not.
        self.assertIn("self::", statics, "the static-call regex matched nothing — it misfired")
        self.assertEqual(
            _ALLOWED_PROBE_STATICS,
            statics,
            "the snapshot probe makes a static call outside its allow-list — if the new "
            "collaborator genuinely cannot execute, add it to _ALLOWED_PROBE_STATICS *and* "
            "to _PROBE_COLLABORATORS so the no-exec scan below covers it too",
        )

        # ONE HOP, and only one — stated because the assertion above alone would have
        # widened the trust boundary to a file this test does not read. Every
        # allow-listed collaborator gets the SAME exec-primitive scan as the probe, so
        # "no reachable way to execute" stays true THROUGH them. It is not a call-graph
        # proof: a collaborator's own collaborators are covered only if they are
        # themselves on the list (today `Finding` reaches `Severity`, and both are).
        for relative in _PROBE_COLLABORATORS:
            with open(os.path.join(os.path.dirname(_HERE), relative), encoding="utf-8") as fh:
                collaborator = _php_code_only(fh.read())
            self.assertEqual(
                [],
                _exec_primitives_in(collaborator),
                f"{relative} is reachable from the snapshot probe and gained a process-launch primitive",
            )

    def test_the_probe_call_site_hands_the_probe_only_strings(self):
        # The other half: a CALLER may exec (see above), but the SNAPSHOT leg's call
        # into the probe must stay a pure string-in/findings-out call, so nothing
        # executable is smuggled in through it.
        #
        # ⚠ THIS SCANS ALL OF `app/`, AND THAT IS THE WHOLE POINT. It used to name
        # `CheckCommand.php` alone. The DL-242 stage-7 migration moved the call into
        # `app/Bridge/Check/Checks/ChannelSnapshotCheck.php` and the guard was left
        # scanning a file that no longer held its subject — red only by luck of an
        # exact-equality assertion, and GREEN-while-guarding-nothing under any softer
        # one. A tripwire pinned to WHERE code lives dies at the next refactor; this one
        # is pinned to the CALL, wherever it lives.
        call_sites = []
        for php in sorted(pathlib.Path(os.path.dirname(_HERE), "app").rglob("*.php")):
            code = _php_code_only(php.read_text(encoding="utf-8"))
            for method, args in re.findall(r"ChannelSnapshotProbe::(\w+)\(([^)]*)\)", code):
                call_sites.append((php.name, method, args))

        # An empty result is a measurement that never happened — the exact failure this
        # test was rewritten for. Assert the subject EXISTS before judging it.
        self.assertNotEqual(
            [], call_sites, "no ChannelSnapshotProbe call site found anywhere in app/ — this guard has lost its subject"
        )

        for where, method, args in call_sites:
            self.assertEqual("probe", method, f"{where}: the probe gained an entry point other than probe()")
            for arg in [a.strip() for a in args.split(",")]:
                self.assertRegex(
                    arg,
                    _SAFE_ARGUMENT,
                    f"{where}: `{arg}` is not a plain variable / property read / string / class constant — "
                    "the snapshot call must not evaluate anything on the way in",
                )

    def test_the_php_scanner_stays_in_sync_on_the_shapes_that_broke_it(self):
        # POSITIVE CONTROLS for `_php_code_only`. Every line here is a shape that
        # previously desynced it, in one direction or the other — asserted rather than
        # asserted-about, because a scanner nobody probes is how the guard above becomes
        # decorative.
        #
        # 1. PHP's single-quoted `\'`: read as a closing quote, it flipped the state and
        #    made the following LITERAL text read as code (false POSITIVE on idiomatic
        #    source), and a real `\'` in the scanned file opened a blind window where an
        #    injected exec was NOT caught (false NEGATIVE). Both directions, one bug.
        escaped = _php_code_only(r"""$s = 'don\'t `back` tick'; $ok = 1;""")
        self.assertEqual([], _exec_primitives_in(escaped), "a `\\'` inside a string must not leak a backtick")
        self.assertIn("$ok = 1;", escaped, "…and the scanner must still be in sync afterwards")

        blind = _php_code_only(r"""$s = 'it\'s'; shell_exec('node x');""")
        self.assertNotEqual([], _exec_primitives_in(blind), "an exec AFTER an escaped quote must still be seen")

        # 2. `#[Attr]` is an attribute, not a comment — blanking the line erased code.
        self.assertIn("#[DataProvider]", _php_code_only("#[DataProvider]\npublic function f() {}"))
        # …while a real `#` comment still goes.
        self.assertNotIn("secret", _php_code_only("$a = 1; # secret\n$b = 2;"))

        # 3. Whole-identifier boundaries: `getFilesystem(` is not `system(`.
        self.assertEqual([], _exec_primitives_in("$fs = $this->getFilesystem(); $x->exec_log();"))
        self.assertNotEqual([], _exec_primitives_in("system('id');"))

        # 4. A string containing the token is not a call.
        self.assertEqual([], _exec_primitives_in(_php_code_only("""$msg = 'run shell_exec() never';""")))

    def test_the_tool_is_executable(self):
        mode = os.stat(os.path.join(_HERE, "check-channel-snapshot.py")).st_mode
        self.assertTrue(mode & stat.S_IXUSR)


class EndToEndCli(_TreeCase):
    @unittest.skipIf(_NODE is None, _NO_NODE_REASON)
    def test_the_program_runs_as_a_program_and_exits_1_on_the_dl230_shape(self):
        # The tool is invoked as `python3 check-channel-snapshot.py <path>` by an
        # operator, so the exit code that matters is the PROCESS's, not the return
        # value of a function the operator never calls.
        deployed = self.whole_copy(omit=["channel-lib.mjs"])

        proc = subprocess.run(
            [sys.executable, os.path.join(_HERE, "check-channel-snapshot.py"), deployed],
            capture_output=True,
            text=True,
        )

        self.assertEqual(1, proc.returncode, proc.stdout + proc.stderr)
        self.assertIn("ERR_MODULE_NOT_FOUND", proc.stdout)


if __name__ == "__main__":
    unittest.main()
