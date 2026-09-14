#!/usr/bin/env python3
"""Tests for the seat-tool declaration (`seat-tools.json`) and its stager, bin/seat-pack.py (DL-385).

(a) A DECLARED TOOL RUNS WHERE A SEAT HAS IT: copied ALONE into an empty directory, run
    through its own shebang under `env -i` from a second empty working directory, `--help`
    exits 0; and it is 100755 in the git INDEX, which is the mode a clone or a staged copy
    inherits (the working-tree bit is not what ships). This is a measurement by running, so
    it sees a checkout dependency no import scan would, and is bounded to what `--help`
    reaches: a sibling read on a path only a real run takes is not exercised.
(b) THE PACK IS EXACTLY THE DECLARATION: `seat-tools/bin/` holds the declared tools and
    nothing else, at 0755, with `seat-pack.json` hashes matching the bytes; `channel-setup/`
    is the shipped channel-server file set, `channel-lib.mjs` included; two runs over one
    tree are byte-identical; the link shape writes links and no metadata.
(r) A RE-STAGE IS SAFE TO RUN OVER WHAT IS ALREADY THERE: nothing is written through a
    symlink, at either end; `channel-setup/` is regenerated, keeping only `node_modules/`;
    a refusal of any kind is a named `refused:` line that wrote nothing, never a traceback;
    and an error after writing has begun says `FAILED while writing`, never `refused:`.

The remedy half, (c), is PHP: `Tests\\Support\\AssertsSeatToolRemedy`.

Each check is paired with a control that feeds it the defect it exists for, so a pass is a
pass of a check that can fail.
"""

import hashlib
import json
import os
import shutil
import stat
import subprocess
import sys
import tempfile
import unittest

_HERE = os.path.dirname(os.path.abspath(__file__))
_REPO = os.path.dirname(_HERE)
_CHANNEL_DIR = "examples/channel-servers/"


def _git(*args, cwd=_REPO):
    return subprocess.run(["git", "-C", cwd, *args], capture_output=True, text=True, check=True).stdout


def _declared():
    with open(os.path.join(_REPO, "seat-tools.json"), encoding="utf-8") as fh:
        return json.load(fh)["tools"]


def _index_mode(path, cwd=_REPO):
    out = _git("ls-files", "-s", "--", path, cwd=cwd)
    return out.split(" ", 1)[0] if out else None


def _run_alone(source, scratch):
    """Copy `source` alone into an empty dir and run `<copy> --help` via its shebang, env -i."""
    tool_dir = os.path.join(scratch, "tool")
    cwd = os.path.join(scratch, "cwd")
    shim = os.path.join(scratch, "shim")
    for d in (tool_dir, cwd, shim):
        os.makedirs(d)
    copy = os.path.join(tool_dir, os.path.basename(source))
    shutil.copyfile(source, copy)
    os.chmod(copy, 0o755)
    # The shebang is `#!/usr/bin/env python3`; the only thing on PATH is this interpreter.
    os.symlink(sys.executable, os.path.join(shim, "python3"))
    return subprocess.run(
        ["env", "-i", f"PATH={shim}", copy, "--help"],
        cwd=cwd,
        capture_output=True,
        text=True,
        timeout=60,
    )


def _tree(root):
    """relative path -> (kind, mode, bytes-or-link-target), for a byte-level compare."""
    found = {}
    for base, dirs, files in os.walk(root):
        for name in dirs + files:
            full = os.path.join(base, name)
            rel = os.path.relpath(full, root)
            st = os.lstat(full)
            if stat.S_ISLNK(st.st_mode):
                found[rel] = ("link", None, os.readlink(full))
            elif stat.S_ISDIR(st.st_mode):
                found[rel] = ("dir", None, None)
            else:
                with open(full, "rb") as fh:
                    found[rel] = ("file", stat.S_IMODE(st.st_mode), fh.read())
    return found


def _pack(out, *extra, seat_pack=os.path.join(_HERE, "seat-pack.py")):
    return subprocess.run(
        [sys.executable, seat_pack, "--out", out, *extra], capture_output=True, text=True, timeout=120
    )


class _Scratch(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.mkdtemp(prefix="seat-tools-test-")
        self.addCleanup(shutil.rmtree, self.tmp, True)

    def scratch(self, name):
        path = os.path.join(self.tmp, name)
        os.makedirs(path)
        return path

    def fixture_repo(self, tool_mode="+x"):
        """A throwaway repo carrying this seat-pack.py, so its inputs can be broken without
        touching the real ones. Returns (repo, its seat-pack.py)."""
        repo = self.scratch("repo")
        os.makedirs(os.path.join(repo, "bin"))
        os.makedirs(os.path.join(repo, _CHANNEL_DIR))
        shutil.copy2(os.path.join(_HERE, "seat-pack.py"), os.path.join(repo, "bin", "seat-pack.py"))
        manifest = json.dumps({"schema": 1, "tools": ["bin/tool.py"]})
        for rel, text in (
            ("VERSION", "1.0.0\n"),
            ("seat-tools.json", manifest),
            ("bin/tool.py", "#!/usr/bin/env python3\n"),
            (_CHANNEL_DIR + "x.mjs", ""),
        ):
            with open(os.path.join(repo, rel), "w") as fh:
                fh.write(text)
        _git("init", "-q", cwd=repo)
        _git("add", f"--chmod={tool_mode}", "bin/tool.py", cwd=repo)
        _git("add", _CHANNEL_DIR + "x.mjs", "VERSION", "seat-tools.json", cwd=repo)
        _git("-c", "user.name=t", "-c", "user.email=t@example.invalid", "commit", "-qm", "fixture", cwd=repo)
        return repo, os.path.join(repo, "bin", "seat-pack.py")

    def assertRefused(self, proc, *reason):
        """A refusal is rc 1 and ONE named `refused:` line — a traceback is not a refusal."""
        self.assertEqual(1, proc.returncode, proc.stdout + proc.stderr)
        self.assertNotIn("Traceback", proc.stderr)
        self.assertIn("seat-pack.py: refused: ", proc.stderr)
        for fragment in reason:
            self.assertIn(fragment, proc.stderr)


class DeclaredToolsRunAlone(_Scratch):
    """(a)"""

    def test_the_declaration_is_not_empty(self):
        # A presence witness: every loop below passes vacuously over an empty list.
        self.assertTrue(_declared(), "seat-tools.json declares no tools")

    def test_each_declared_tool_runs_copied_alone_under_env_i(self):
        for tool in _declared():
            with self.subTest(tool=tool):
                proc = _run_alone(os.path.join(_REPO, tool), self.scratch(os.path.basename(tool)))
                self.assertEqual(
                    0,
                    proc.returncode,
                    f"{tool} --help, copied alone and run under env -i, exited {proc.returncode}: "
                    f"it depends on something beside its own file\n{proc.stdout}{proc.stderr}",
                )
                self.assertIn("usage:", proc.stdout.lower())

    def assert_tracked_100755(self, tool, cwd=_REPO):
        self.assertEqual(
            "100755",
            _index_mode(tool, cwd=cwd),
            f"{tool} is not tracked at 100755; installed on PATH it exits 126",
        )

    def test_each_declared_tool_is_100755_in_the_git_index(self):
        for tool in _declared():
            with self.subTest(tool=tool):
                self.assert_tracked_100755(tool)

    def test_control_a_tool_reading_a_sibling_file_fails_the_isolation_run(self):
        # The defect shape (a) exists for: the tool works IN the tree and dies copied out of it.
        tree = self.scratch("tree")
        os.makedirs(os.path.join(tree, "bin"))
        with open(os.path.join(tree, "VERSION"), "w") as fh:
            fh.write("1.0.0\n")
        fixture = os.path.join(tree, "bin", "reads-version.py")
        with open(fixture, "w") as fh:
            fh.write(
                "#!/usr/bin/env python3\n"
                "import os, sys\n"
                "open(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'VERSION')).read()\n"
                "print('usage: reads-version.py')\n"
            )
        os.chmod(fixture, 0o755)

        in_tree = subprocess.run([sys.executable, fixture, "--help"], capture_output=True, text=True)
        self.assertEqual(0, in_tree.returncode, "the control fixture must pass in its own tree")
        alone = _run_alone(fixture, self.scratch("alone"))
        self.assertNotEqual(0, alone.returncode, "the isolation run did not catch a ../VERSION read")
        self.assertIn("VERSION", alone.stderr)

    def test_control_a_100644_entry_fails_the_index_mode_check(self):
        repo = self.scratch("repo")
        _git("init", "-q", cwd=repo)
        open(os.path.join(repo, "tool.py"), "w").close()
        _git("add", "--chmod=-x", "tool.py", cwd=repo)
        with self.assertRaises(self.failureException):
            self.assert_tracked_100755("tool.py", cwd=repo)


class PackIsExactlyTheDeclaration(_Scratch):
    """(b)"""

    def pack(self, name, *extra):
        out = os.path.join(self.tmp, name)
        proc = _pack(out, *extra)
        self.assertEqual(0, proc.returncode, proc.stdout + proc.stderr)
        return out

    def test_copy_shape_holds_exactly_the_declared_tools_with_modes_and_hashes(self):
        out = self.pack("copy")
        declared = sorted(os.path.basename(t) for t in _declared())
        bin_dir = os.path.join(out, "seat-tools", "bin")
        self.assertEqual(declared, sorted(os.listdir(bin_dir)))

        with open(os.path.join(out, "seat-tools", "seat-pack.json"), encoding="utf-8") as fh:
            meta = json.load(fh)
        self.assertEqual({"schema", "bridge_version", "bridge_describe", "tools"}, set(meta))
        self.assertEqual(declared, [t["name"] for t in meta["tools"]])

        for tool in _declared():
            name = os.path.basename(tool)
            with self.subTest(tool=tool):
                staged = os.path.join(bin_dir, name)
                self.assertTrue(os.path.isfile(staged) and not os.path.islink(staged))
                self.assertEqual(0o755, stat.S_IMODE(os.stat(staged).st_mode))
                with open(staged, "rb") as fh:
                    staged_bytes = fh.read()
                with open(os.path.join(_REPO, tool), "rb") as fh:
                    self.assertEqual(fh.read(), staged_bytes)
                recorded = next(t["sha256"] for t in meta["tools"] if t["name"] == name)
                self.assertEqual(hashlib.sha256(staged_bytes).hexdigest(), recorded)

        with open(os.path.join(_REPO, "VERSION"), "rb") as fh:
            version = fh.read()
        with open(os.path.join(out, "seat-tools", "VERSION"), "rb") as fh:
            self.assertEqual(version, fh.read())
        self.assertEqual(version.decode().strip(), meta["bridge_version"])
        self.assertEqual({"bin", "VERSION", "seat-pack.json"}, set(os.listdir(os.path.join(out, "seat-tools"))))

    def test_channel_setup_is_the_shipped_channel_server_file_set(self):
        out = self.pack("copy")
        shipped = sorted(
            p[len(_CHANNEL_DIR):]
            for p in _git("ls-files", "--", _CHANNEL_DIR).splitlines()
            if "/node_modules/" not in p
        )
        staged = sorted(
            rel for rel, (kind, _, _) in _tree(os.path.join(out, "channel-setup")).items() if kind == "file"
        )
        self.assertIn("channel-lib.mjs", staged)
        self.assertIn("agent-webhook-bridge-channel.mjs", staged)
        self.assertEqual(shipped, staged)

    def test_two_runs_are_byte_identical(self):
        self.assertEqual(_tree(self.pack("first")), _tree(self.pack("second")))

    def test_link_shape_writes_only_links_into_this_checkout(self):
        out = self.pack("link", "--shape", "link")
        tree = _tree(out)
        expected = {"seat-tools", os.path.join("seat-tools", "bin")} | {
            os.path.join("seat-tools", "bin", os.path.basename(t)) for t in _declared()
        }
        self.assertEqual(expected, set(tree))
        for tool in _declared():
            link = os.path.join(out, "seat-tools", "bin", os.path.basename(tool))
            self.assertTrue(os.path.islink(link))
            self.assertEqual(os.path.realpath(os.path.join(_REPO, tool)), os.path.realpath(link))

    def test_a_restage_replaces_seat_tools_and_keeps_what_channel_setup_holds(self):
        out = self.pack("restage")
        stray_tool = os.path.join(out, "seat-tools", "bin", "no-longer-declared.py")
        open(stray_tool, "w").close()
        node_modules = os.path.join(out, "channel-setup", "node_modules", "kept")
        os.makedirs(os.path.dirname(node_modules))
        open(node_modules, "w").close()

        self.assertEqual(0, _pack(out, "--shape", "link").returncode)
        self.assertFalse(os.path.lexists(stray_tool))
        self.assertFalse(os.path.lexists(os.path.join(out, "seat-tools", "seat-pack.json")))
        self.assertTrue(os.path.exists(node_modules))

    def test_control_an_undeclared_or_non_executable_entry_is_refused(self):
        repo, seat_pack = self.fixture_repo(tool_mode="-x")

        def manifest(entries):
            with open(os.path.join(repo, "seat-tools.json"), "w") as fh:
                json.dump({"schema": 1, "tools": entries}, fh)

        proc = _pack(os.path.join(self.tmp, "o1"), seat_pack=seat_pack)
        self.assertRefused(proc, "mode 100644")

        manifest(["bin/untracked.py"])
        proc = _pack(os.path.join(self.tmp, "o2"), seat_pack=seat_pack)
        self.assertRefused(proc, "not tracked")

        _git("add", "--chmod=+x", "bin/tool.py", cwd=repo)
        _git("-c", "user.name=t", "-c", "user.email=t@example.invalid", "commit", "-qm", "fixture", cwd=repo)
        manifest(["bin/tool.py"])
        proc = _pack(os.path.join(self.tmp, "o3"), seat_pack=seat_pack)
        self.assertEqual(0, proc.returncode, proc.stdout + proc.stderr)
        self.assertEqual(["tool.py"], os.listdir(os.path.join(self.tmp, "o3", "seat-tools", "bin")))


class RestageIsSafeOverWhatIsThere(_Scratch):
    """(r)"""

    def victim_file(self):
        victim = os.path.join(self.scratch("outside"), "precious")
        with open(victim, "w") as fh:
            fh.write("precious\n")
        os.chmod(victim, 0o600)
        return victim

    def test_a_tracked_symlink_under_channel_servers_is_refused_not_dereferenced(self):
        repo, seat_pack = self.fixture_repo()
        victim = self.victim_file()
        os.symlink(victim, os.path.join(repo, _CHANNEL_DIR, "leak"))
        _git("add", _CHANNEL_DIR + "leak", cwd=repo)
        out = os.path.join(self.tmp, "out")

        proc = _pack(out, seat_pack=seat_pack)

        self.assertFalse(
            os.path.lexists(os.path.join(out, "channel-setup", "leak")),
            "a tracked symlink was staged (its target's content, copied in as a regular file)",
        )
        self.assertRefused(proc, _CHANNEL_DIR + "leak", "120000")
        self.assertFalse(os.path.lexists(os.path.join(out, "channel-setup")), "a refusal wrote channel-setup/")

    def test_a_symlinked_channel_setup_dir_is_refused_and_its_target_untouched(self):
        out = self.scratch("out")
        elsewhere = self.scratch("elsewhere")
        os.symlink(elsewhere, os.path.join(out, "channel-setup"))

        proc = _pack(out)

        self.assertEqual([], os.listdir(elsewhere), "files were written through the channel-setup symlink")
        self.assertRefused(proc, "channel-setup", "symlink")
        self.assertFalse(os.path.lexists(os.path.join(out, "seat-tools")), "a refusal wrote seat-tools/")

    def test_a_symlinked_destination_file_is_refused_and_its_target_untouched(self):
        out = self.scratch("out")
        os.makedirs(os.path.join(out, "channel-setup"))
        victim = self.victim_file()
        os.symlink(victim, os.path.join(out, "channel-setup", "README.md"))

        proc = _pack(out)

        with open(victim) as fh:
            self.assertEqual("precious\n", fh.read(), "the symlink's target was overwritten")
        self.assertEqual(0o600, stat.S_IMODE(os.stat(victim).st_mode), "the symlink's target was chmodded")
        self.assertRefused(proc, "README.md", "symlink")

    def test_a_symlinked_seat_tools_dir_is_refused_and_its_target_untouched(self):
        for shape in ("copy", "link"):
            with self.subTest(shape=shape):
                out = self.scratch("out-" + shape)
                elsewhere = self.scratch("elsewhere-" + shape)
                with open(os.path.join(elsewhere, "keep"), "w") as fh:
                    fh.write("keep\n")
                os.symlink(elsewhere, os.path.join(out, "seat-tools"))

                proc = _pack(out, "--shape", shape)

                self.assertEqual(["keep"], os.listdir(elsewhere), "the seat-tools symlink's target was changed")
                self.assertFalse(os.path.lexists(os.path.join(out, "channel-setup")), "a refusal wrote channel-setup/")
                self.assertRefused(proc, "seat-tools", "symlink")

    def test_an_unreadable_input_is_a_named_refusal_not_a_traceback(self):
        repo, seat_pack = self.fixture_repo()
        os.unlink(os.path.join(repo, "VERSION"))
        out = os.path.join(self.tmp, "out")

        proc = _pack(out, seat_pack=seat_pack)

        self.assertRefused(proc, "VERSION")
        self.assertFalse(os.path.lexists(os.path.join(out, "seat-tools")), "a refusal wrote seat-tools/")
        self.assertFalse(os.path.lexists(os.path.join(out, "channel-setup")), "a refusal wrote channel-setup/")

    def test_an_empty_tool_list_is_refused_before_anything_is_written(self):
        repo, seat_pack = self.fixture_repo()
        out = os.path.join(self.tmp, "out")
        self.assertEqual(0, _pack(out, seat_pack=seat_pack).returncode)
        before = _tree(out)
        with open(os.path.join(repo, "seat-tools.json"), "w") as fh:
            json.dump({"schema": 1, "tools": []}, fh)

        for shape in ("link", "copy"):
            with self.subTest(shape=shape):
                proc = _pack(out, "--shape", shape, seat_pack=seat_pack)

                self.assertEqual(before, _tree(out), "an empty declaration changed --out")
                self.assertRefused(proc, "seat-tools.json", "no tools")

    def test_a_failure_after_the_first_write_says_so_and_is_not_called_a_refusal(self):
        repo, seat_pack = self.fixture_repo()
        out = os.path.join(self.tmp, "out")
        self.assertEqual(0, _pack(out, seat_pack=seat_pack).returncode)
        channel = os.path.join(out, "channel-setup")
        # The prune walks names in sorted order: `a-stale.mjs` goes, then `locked/` cannot.
        stale = os.path.join(channel, "a-stale.mjs")
        with open(stale, "w") as fh:
            fh.write("stale\n")
        locked = os.path.join(channel, "locked")
        os.makedirs(locked)
        with open(os.path.join(locked, "extra"), "w") as fh:
            fh.write("extra\n")
        os.chmod(locked, 0o555)
        self.addCleanup(os.chmod, locked, 0o755)
        if os.access(locked, os.W_OK):
            self.skipTest("running as a user a 0555 directory does not stop (root)")

        proc = _pack(out, seat_pack=seat_pack)

        self.assertFalse(os.path.lexists(stale), "the fixture did not reach a write before failing")
        self.assertEqual(1, proc.returncode, proc.stdout + proc.stderr)
        self.assertNotIn("Traceback", proc.stderr)
        self.assertNotIn("refused:", proc.stderr, "a failure after writing began was reported as a refusal")
        self.assertIn("seat-pack.py: FAILED while writing (--out may be partially updated; re-run): ", proc.stderr)
        self.assertIn("Permission denied", proc.stderr)

    def test_a_copy_restage_prunes_channel_setup_except_node_modules(self):
        out = os.path.join(self.tmp, "out")
        self.assertEqual(0, _pack(out).returncode)
        channel = os.path.join(out, "channel-setup")
        stale = os.path.join(channel, "no-longer-shipped.mjs")
        stale_nested = os.path.join(channel, "tests", "no-longer-shipped.test.mjs")
        for path in (stale, stale_nested):
            with open(path, "w") as fh:
                fh.write("stale\n")
        kept = os.path.join(channel, "node_modules", "dep", "index.js")
        os.makedirs(os.path.dirname(kept))
        with open(kept, "w") as fh:
            fh.write("dep\n")
        # A symlink the prune meets must be unlinked, never recursed through.
        elsewhere = self.scratch("elsewhere")
        with open(os.path.join(elsewhere, "keep"), "w") as fh:
            fh.write("keep\n")
        stray_link = os.path.join(channel, "stray-link")
        os.symlink(elsewhere, stray_link)

        proc = _pack(out)

        self.assertEqual(0, proc.returncode, proc.stdout + proc.stderr)
        self.assertFalse(os.path.lexists(stale), "a file the reference no longer ships survived a re-stage")
        self.assertFalse(os.path.lexists(stale_nested), "a nested stale file survived a re-stage")
        self.assertTrue(os.path.isfile(os.path.join(elsewhere, "keep")), "the prune followed a symlink")
        self.assertFalse(os.path.lexists(stray_link))
        self.assertTrue(os.path.isfile(kept), "the re-stage removed node_modules/")

        fresh = os.path.join(self.tmp, "fresh")
        self.assertEqual(0, _pack(fresh).returncode)
        diff = subprocess.run(["diff", "-r", "-x", "node_modules", out, fresh], capture_output=True, text=True)
        self.assertEqual((0, ""), (diff.returncode, diff.stdout), "the documented currency check is not clean after a re-stage")


if __name__ == "__main__":
    unittest.main()
