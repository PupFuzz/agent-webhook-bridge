#!/usr/bin/env python3
"""Tests for the seat-tool declaration (`seat-tools.json`, DL-385).

A DECLARED TOOL RUNS WHERE A SEAT HAS IT: copied ALONE into an empty directory, run through
its own shebang under `env -i` from a second empty working directory, `--help` exits 0; and it
is 100755 in the git INDEX, which is the mode a clone or a client pack inherits (the
working-tree bit is not what ships). This is a measurement by running, so it sees a checkout
dependency no import scan would, and is bounded to what `--help` reaches: a sibling read on a
path only a real run takes is not exercised.

What a pack holds is `bin/build-client-pack.py`'s, tested in `bin/test_build_client_pack.py`.
The remedy half is PHP: `Tests\\Support\\AssertsSeatToolRemedy`.

Each check is paired with a control that feeds it the defect it exists for, so a pass is a
pass of a check that can fail.
"""

import json
import os
import shutil
import subprocess
import sys
import tempfile
import unittest

_HERE = os.path.dirname(os.path.abspath(__file__))
_REPO = os.path.dirname(_HERE)


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


class _Scratch(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.mkdtemp(prefix="seat-tools-test-")
        self.addCleanup(shutil.rmtree, self.tmp, True)

    def scratch(self, name):
        path = os.path.join(self.tmp, name)
        os.makedirs(path)
        return path


class DeclaredToolsRunAlone(_Scratch):

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


if __name__ == "__main__":
    unittest.main()
