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

Proven failable (each mutated once, then restored):
  - dropping `env["BRIDGE_CHANNEL_SOCKET"] = ...` from `child_env` reds
    `test_a_live_socket_in_the_parent_env_is_never_bound_or_unlinked` (the sentinel
    was created AND unlinked) and `test_child_env_overrides_every_live_endpoint`.
  - returning EXIT_LAUNCH_OK for a timeout reds `test_a_hanging_entry_is_inconclusive`.
  - `os.path.lexists` → `False` in the refusal reds
    `test_a_pre_existing_socket_path_refuses_and_leaves_it_alone` (the file was gone).
  - treating any non-zero child exit as OK reds the DL-230 case.
"""

import importlib.util
import io
import os
import shutil
import stat
import subprocess
import sys
import tempfile
import unittest

_HERE = os.path.dirname(os.path.abspath(__file__))
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
import path from 'node:path';

const SOCKET_PATH =
  process.env.BRIDGE_CHANNEL_SOCKET ||
  (process.env.XDG_RUNTIME_DIR
    ? path.join(process.env.XDG_RUNTIME_DIR, `channel-${process.env.BRIDGE_CHANNEL_NAME || 'x'}.sock`)
    : null);
if (!SOCKET_PATH) {
  console.error('no socket path could be resolved');
  process.exit(2);
}
let bound = false;
let ended = false;
function shutdown(code) {
  if (bound) {
    try { fs.unlinkSync(SOCKET_PATH); } catch {}
  }
  process.exit(code);
}
const server = net.createServer(() => {});
server.on('error', (err) => {
  console.error(`bind failed: ${err.code}`);
  process.exit(2);
});
server.listen(SOCKET_PATH, () => {
  bound = true;
  // A PERSISTENT witness that this path was bound. The socket itself is unlinked on
  // the way out, so "the socket file is not there afterwards" cannot tell a path that
  // was never touched from one that was bound and then destroyed — which is exactly
  // the damage the throwaway-socket requirement exists to prevent.
  try { fs.writeFileSync(`${SOCKET_PATH}.bound`, 'bound\\n'); } catch {}
  console.error(`[test-channel] listening on unix:${SOCKET_PATH} ${hello()}`);
  if (ended) shutdown(0);
});
process.stdin.on('end', () => {
  ended = true;
  if (bound) shutdown(0);
});
process.stdin.resume();
"""

_LIB_SOURCE = "export const hello = () => 'ok';\n"

# The four files a pruned deployment drops. Every one of them is in the reference set
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

    def test_child_env_overrides_every_live_endpoint(self):
        # Assignments, never setdefault: the seat exports its REAL values, and each
        # of the three is a live endpoint the child would otherwise reach for — the
        # unix socket it binds and unlinks, the loopback port it binds under the
        # `http` transport, and the directory a `.FAILED` marker lands in.
        parent = {
            "BRIDGE_CHANNEL_SOCKET": "/run/user/1000/live.sock",
            "BRIDGE_CHANNEL_PORT": "8788",
            "BRIDGE_CHANNEL_NAME": "seat-agent",
            "XDG_RUNTIME_DIR": "/run/user/1000",
            "PATH": "/usr/bin",
        }

        env = ccs.child_env(parent, "/tmp/throwaway/launch-assert.sock", "/tmp/throwaway")

        self.assertEqual("/tmp/throwaway/launch-assert.sock", env["BRIDGE_CHANNEL_SOCKET"])
        self.assertEqual("0", env["BRIDGE_CHANNEL_PORT"])
        self.assertEqual("/tmp/throwaway", env["XDG_RUNTIME_DIR"])
        # Untouched: the rest of the seat's environment is what makes this a faithful
        # launch (its PATH, its node, its channel name).
        self.assertEqual("seat-agent", env["BRIDGE_CHANNEL_NAME"])
        self.assertEqual("/usr/bin", env["PATH"])
        # …and the parent dict is not mutated.
        self.assertEqual("/run/user/1000/live.sock", parent["BRIDGE_CHANNEL_SOCKET"])

    def test_the_throwaway_socket_lives_inside_the_private_temp_dir(self):
        self.assertEqual(
            os.path.join("/tmp/priv", ccs.SOCKET_BASENAME),
            ccs.throwaway_socket_path("/tmp/priv"),
        )


@unittest.skipIf(_NODE is None, _NO_NODE_REASON)
class SocketGuard(_TreeCase):
    def test_a_live_socket_in_the_parent_env_is_never_bound_or_unlinked(self):
        # THE TEST FOR BUILD REQUIREMENT (a). `BRIDGE_CHANNEL_SOCKET` is exported into
        # every seat's own environment and WINS over the name-derived default, so an
        # assert that only set a throwaway NAME would bind the LIVE socket here and
        # unlink it on exit — a diagnostic that breaks live-wake, run at exactly the
        # moment (pre-session-start) when nothing holds the path to stop it.
        sentinel = os.path.join(self.tmp, "live-channel.sock")
        env = dict(os.environ, BRIDGE_CHANNEL_SOCKET=sentinel, BRIDGE_CHANNEL_NAME="live-agent")
        self.assertFalse(os.path.lexists(sentinel), "premise: nothing holds the live path yet")

        self.assert_run(self.whole_copy(), ccs.EXIT_LAUNCH_OK, env=env)

        # ⚠ DO NOT "SIMPLIFY" THIS TO THE lexists() CHECK ALONE. The obvious form is a
        # DECORATION and was measured to be: the server UNLINKS the socket it bound, so
        # "no socket file afterwards" is equally true of a path that was never touched
        # and one that was bound and then DESTROYED — which is the whole damage. The
        # first version of this test asserted only the first line below and stayed
        # GREEN under the mutation it exists to catch (dropping the explicit
        # BRIDGE_CHANNEL_SOCKET override from child_env), because the child dutifully
        # created the sentinel, bound it, and removed it again. The synthetic entry
        # therefore leaves a PERSISTENT `<socket>.bound` marker on listen, and that
        # second assertion is the one that actually goes red.
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


class ToolShape(unittest.TestCase):
    def test_the_module_docstring_and_help_both_state_the_exit_contract(self):
        # The exit codes ARE the interface; an operator reading `--help` must not have
        # to read the source to learn that a 2 is not a verdict.
        for text in (ccs.__doc__, ccs.build_parser().format_help()):
            self.assertIn("exit 2", text.lower().replace("  2 ", "exit 2 "))
            self.assertIn("COULD NOT CHECK", text)

    def test_the_tool_is_stdlib_only(self):
        # It must survive being copied to a seat with no bridge checkout.
        with open(os.path.join(_HERE, "check-channel-snapshot.py"), encoding="utf-8") as fh:
            source = fh.read()

        for line in source.splitlines():
            if line.startswith(("import ", "from ")) and "__future__" not in line:
                module = line.split()[1].split(".")[0]
                self.assertIn(
                    module,
                    {"argparse", "os", "shutil", "subprocess", "sys", "tempfile"},
                    f"non-stdlib import: {line}",
                )

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
