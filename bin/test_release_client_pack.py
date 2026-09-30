#!/usr/bin/env python3
"""Tests for bin/release-client-pack.py (card#10567 B2).

A fake `gh` on PATH holds one release as a JSON state file (its asset names and body) and answers
`release view --json assets,body`, `release upload` and `release edit --notes-file`, recording each
call. A fake builder (RELEASE_CLIENT_PACK_BUILDER) writes the two files the real builder names, or
exits 1. So these cases pin what the script DECIDES from what the release carries; the real
builder has its own suite, and the real `gh` is not exercised here.

(a) none attached + a build -> all three uploaded, SHA256SUMS in `sha256sum` format over the pack
    and manifest (what `bridge:client-pack:install` parses), exit 0.
(s) FAIL-SOFT: a build that fails, or an upload that attaches nothing -> exit 0, an ::error::
    line, nothing attached, and the notes open with NO_PACK_NOTE exactly once.
(r) a re-run: all three present -> nothing built or uploaded; a NO_PACK_NOTE left by an earlier
    run is removed once the pack is attached.
(p) PARTIAL -> exit 1 with the remedy, before AND after an upload, and nothing built on the
    before-side.
(u) an unreadable release -> exit 2; a tag that is not v<X.Y.Z> -> usage error.
(w) RELEASE_WORKFLOW is the `name:` of auto-tag-version.yml, so the note names a real workflow.
"""

import hashlib
import importlib.util
import json
import os
import re
import stat
import subprocess
import sys
import tempfile
import unittest

_HERE = os.path.dirname(os.path.abspath(__file__))
_SCRIPT = os.path.join(_HERE, "release-client-pack.py")
_spec = importlib.util.spec_from_file_location("release_client_pack", _SCRIPT)
rcp = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(rcp)

FAKE_GH = r'''#!/usr/bin/env python3
import json, os, sys
state_path = os.environ["FAKE_GH_STATE"]
with open(state_path) as f:
    state = json.load(f)
state.setdefault("calls", []).append(sys.argv[1:])
args = sys.argv[1:]
rc = 0
if args[:2] == ["release", "view"]:
    if state.get("view_fails"):
        sys.stderr.write("release not found\n"); rc = 1
    else:
        print(json.dumps({"assets": [{"name": n} for n in state["assets"]], "body": state["body"]}))
elif args[:2] == ["release", "upload"]:
    mode = state.get("upload", "ok")
    files = args[3:]
    if mode == "ok":
        state["assets"] += [os.path.basename(p) for p in files]
        state["uploaded"] = {os.path.basename(p): open(p, "rb").read().decode("latin-1") for p in files}
    elif mode == "partial":
        state["assets"] += [os.path.basename(files[0])]; rc = 1
    else:
        rc = 1
elif args[:2] == ["release", "edit"]:
    with open(args[args.index("--notes-file") + 1]) as f:
        state["body"] = f.read()
else:
    rc = 99
with open(state_path, "w") as f:
    json.dump(state, f)
sys.exit(rc)
'''

FAKE_BUILDER = r'''#!/usr/bin/env python3
import os, sys
args = sys.argv[1:]
tag, out = args[args.index("--ref") + 1], args[args.index("--out") + 1]
if os.environ.get("FAKE_BUILD_FAILS"):
    sys.exit(1)
os.makedirs(out)
release = tag[1:]
open(os.path.join(out, f"client-pack-v{release}.tar.gz"), "wb").write(b"pack bytes " + release.encode())
open(os.path.join(out, f"client-pack-v{release}.manifest.json"), "w").write('{"bridge_release": "%s"}\n' % release)
'''

TAG = "v0.94.0"
NAMES = ["client-pack-v0.94.0.tar.gz", "client-pack-v0.94.0.manifest.json", "SHA256SUMS"]


def _exe(path: str, body: str) -> None:
    with open(path, "w") as f:
        f.write(body)
    os.chmod(path, os.stat(path).st_mode | stat.S_IXUSR)


class ReleaseClientPackTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        d = self.tmp.name
        os.makedirs(os.path.join(d, "path"))
        _exe(os.path.join(d, "path", "gh"), FAKE_GH)
        _exe(os.path.join(d, "builder.py"), FAKE_BUILDER)
        self.state_path = os.path.join(d, "state.json")
        self.env = dict(os.environ)
        self.env.update({
            "PATH": os.path.join(d, "path") + os.pathsep + os.environ["PATH"],
            "FAKE_GH_STATE": self.state_path,
            "RELEASE_CLIENT_PACK_BUILDER": os.path.join(d, "builder.py"),
        })
        self.env.pop("FAKE_BUILD_FAILS", None)
        self.release(assets=[], body="Release notes.\n")

    def tearDown(self):
        self.tmp.cleanup()

    def release(self, **state):
        with open(self.state_path, "w") as f:
            json.dump(state, f)

    def state(self) -> dict:
        with open(self.state_path) as f:
            return json.load(f)

    def run_script(self, *args):
        argv = list(args) or ["--tag", TAG, "--work", os.path.join(self.tmp.name, "work")]
        return subprocess.run([sys.executable, _SCRIPT, *argv], env=self.env, capture_output=True, text=True)

    def verbs(self) -> list:
        return [c[1] for c in self.state()["calls"]]

    # (a)
    def test_a_release_without_a_pack_gets_all_three_assets(self):
        r = self.run_script()

        self.assertEqual(0, r.returncode, r.stdout + r.stderr)
        s = self.state()
        self.assertEqual(NAMES, s["assets"])
        up = s["uploaded"]
        sums = up["SHA256SUMS"]
        self.assertEqual(
            f"{hashlib.sha256(up[NAMES[0]].encode('latin-1')).hexdigest()}  {NAMES[0]}\n"
            f"{hashlib.sha256(up[NAMES[1]].encode('latin-1')).hexdigest()}  {NAMES[1]}\n",
            sums,
        )
        # The line grammar ClientPackInstallCommand::checkSums() accepts.
        for line in sums.splitlines():
            self.assertRegex(line, r"\A[0-9a-f]{64} [ *]\S.*\Z")
        self.assertEqual("Release notes.\n", s["body"])
        self.assertNotIn("::error::", r.stdout)

    # (s)
    def test_a_failed_build_ships_the_release_without_a_pack_and_says_so(self):
        self.env["FAKE_BUILD_FAILS"] = "1"

        r = self.run_script()

        self.assertEqual(0, r.returncode, r.stdout + r.stderr)
        s = self.state()
        self.assertEqual([], s["assets"])
        self.assertNotIn("upload", self.verbs())
        self.assertEqual(f"{rcp.NO_PACK_NOTE}\n\nRelease notes.\n", s["body"])
        self.assertIn("::error::", r.stdout)
        self.assertIn("did not build", r.stdout)
        self.assertIn("ships WITHOUT a client pack", r.stdout)

    def test_an_upload_that_attaches_nothing_is_the_same_fail_soft_outcome(self):
        self.release(assets=[], body="Release notes.\n", upload="fails")

        r = self.run_script()

        self.assertEqual(0, r.returncode, r.stdout + r.stderr)
        self.assertEqual([], self.state()["assets"])
        self.assertTrue(self.state()["body"].startswith(rcp.NO_PACK_NOTE))
        self.assertIn("uploading the client pack", r.stdout)

    def test_the_note_is_added_once_across_failed_re_runs(self):
        self.env["FAKE_BUILD_FAILS"] = "1"

        self.run_script()
        r = self.run_script()

        self.assertEqual(0, r.returncode)
        self.assertEqual(1, self.state()["body"].count(rcp.NO_PACK_NOTE))
        self.assertEqual(1, self.verbs().count("edit"), "the second run found the note and left the notes alone")

    # (r)
    def test_a_re_run_that_finds_the_pack_attached_does_nothing(self):
        self.release(assets=NAMES + ["other.txt"], body="Release notes.\n")

        r = self.run_script()

        self.assertEqual(0, r.returncode, r.stdout + r.stderr)
        self.assertEqual(["view", "view"], self.verbs())
        self.assertIn("already carries its client pack", r.stdout)

    def test_a_re_run_that_attaches_the_pack_removes_the_earlier_note(self):
        self.release(assets=[], body=f"{rcp.NO_PACK_NOTE}\n\nRelease notes.\n")

        r = self.run_script()

        self.assertEqual(0, r.returncode, r.stdout + r.stderr)
        self.assertEqual(NAMES, self.state()["assets"])
        self.assertEqual("Release notes.\n", self.state()["body"])

    # (p)
    def test_a_partial_set_already_on_the_release_is_refused_and_nothing_is_built(self):
        self.release(assets=[NAMES[0]], body="Release notes.\n")

        r = self.run_script()

        self.assertEqual(1, r.returncode, r.stdout + r.stderr)
        self.assertEqual(["view"], self.verbs())
        self.assertFalse(os.path.exists(os.path.join(self.tmp.name, "work", "pack")))
        self.assertIn(f"missing: {NAMES[1]}, {NAMES[2]}", r.stdout)
        self.assertIn(f"gh release delete-asset {TAG}", r.stdout)

    def test_an_upload_that_attaches_part_of_the_set_is_refused(self):
        self.release(assets=[], body="Release notes.\n", upload="partial")

        r = self.run_script()

        self.assertEqual(1, r.returncode, r.stdout + r.stderr)
        self.assertEqual([NAMES[0]], self.state()["assets"])
        self.assertFalse(self.state()["body"].startswith(rcp.NO_PACK_NOTE))
        self.assertIn("carries only part of its client pack", r.stdout)

    # (u)
    def test_a_release_that_cannot_be_read_exits_2(self):
        self.release(assets=[], body="", view_fails=True)

        r = self.run_script()

        self.assertEqual(2, r.returncode)
        self.assertIn("could not read release", r.stdout)

    def test_a_tag_that_is_not_a_release_tag_is_a_usage_error(self):
        for tag in ["0.94.0", "v0.94", "v0.94.0-rc1", "refs/tags/v0.94.0"]:
            with self.subTest(tag=tag):
                r = self.run_script("--tag", tag, "--work", os.path.join(self.tmp.name, "work"))
                self.assertEqual(2, r.returncode)
                self.assertIn("is not v<X.Y.Z>", r.stderr)

    # (w)
    def test_the_note_names_the_workflow_that_runs_this(self):
        path = os.path.join(_HERE, "..", ".github", "workflows", "auto-tag-version.yml")
        with open(path) as f:
            name = re.search(r"^name: (.+)$", f.read(), re.M).group(1).strip()

        self.assertEqual(name, rcp.RELEASE_WORKFLOW)
        self.assertIn(f"`{name}`", rcp.NO_PACK_NOTE)


if __name__ == "__main__":
    unittest.main()
