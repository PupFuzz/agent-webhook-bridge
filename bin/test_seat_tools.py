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

    def test_each_declared_tool_is_100755_in_the_git_index(self):
        for tool in _declared():
            with self.subTest(tool=tool):
                self.assertEqual(
                    "100755",
                    _index_mode(tool),
                    f"{tool} is not tracked at 100755; installed on PATH it exits 126",
                )

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

    def test_control_a_100644_entry_fails_the_index_mode_read(self):
        repo = self.scratch("repo")
        _git("init", "-q", cwd=repo)
        open(os.path.join(repo, "tool.py"), "w").close()
        _git("add", "--chmod=-x", "tool.py", cwd=repo)
        self.assertEqual("100644", _index_mode("tool.py", cwd=repo))


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
        # Build a throwaway repo carrying this seat-pack.py, so the manifest can be broken
        # without touching the real one.
        repo = self.scratch("repo")
        os.makedirs(os.path.join(repo, "bin"))
        os.makedirs(os.path.join(repo, _CHANNEL_DIR))
        shutil.copy2(os.path.join(_HERE, "seat-pack.py"), os.path.join(repo, "bin", "seat-pack.py"))
        for rel, text in (("VERSION", "1.0.0\n"), ("bin/tool.py", "#!/usr/bin/env python3\n"), (_CHANNEL_DIR + "x.mjs", "")):
            with open(os.path.join(repo, rel), "w") as fh:
                fh.write(text)
        _git("init", "-q", cwd=repo)
        _git("add", "--chmod=-x", "bin/tool.py", _CHANNEL_DIR + "x.mjs", "VERSION", cwd=repo)
        seat_pack = os.path.join(repo, "bin", "seat-pack.py")

        def manifest(entries):
            with open(os.path.join(repo, "seat-tools.json"), "w") as fh:
                json.dump({"schema": 1, "tools": entries}, fh)

        manifest(["bin/tool.py"])
        proc = _pack(os.path.join(self.tmp, "o1"), seat_pack=seat_pack)
        self.assertEqual(1, proc.returncode, proc.stdout + proc.stderr)
        self.assertIn("mode 100644", proc.stderr)

        manifest(["bin/untracked.py"])
        proc = _pack(os.path.join(self.tmp, "o2"), seat_pack=seat_pack)
        self.assertEqual(1, proc.returncode, proc.stdout + proc.stderr)
        self.assertIn("not tracked", proc.stderr)

        _git("add", "--chmod=+x", "bin/tool.py", cwd=repo)
        _git("-c", "user.name=t", "-c", "user.email=t@example.invalid", "commit", "-qm", "fixture", cwd=repo)
        manifest(["bin/tool.py"])
        proc = _pack(os.path.join(self.tmp, "o3"), seat_pack=seat_pack)
        self.assertEqual(0, proc.returncode, proc.stdout + proc.stderr)
        self.assertEqual(["tool.py"], os.listdir(os.path.join(self.tmp, "o3", "seat-tools", "bin")))


if __name__ == "__main__":
    unittest.main()
