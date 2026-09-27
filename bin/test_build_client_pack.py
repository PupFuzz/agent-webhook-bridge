#!/usr/bin/env python3
r"""Tests for bin/build-client-pack.py, against a throwaway git repository built per test class.

The fixture's one dependency is a local tarball (`file:vendor/tiny-1.0.0.tgz`), so `npm ci`
runs with no registry. Cases that reach `npm ci` skip LOUDLY where npm is not on PATH; every
refusal that happens before `npm ci`, including the release-tag gate, runs regardless.

(t) ONLY A RELEASE TAG BUILDS A PACK: a branch, a commit id, HEAD, a full refname, a missing
    tag, and a BRANCH named like a tag are all refused and write nothing; a tag whose VERSION
    disagrees with its name is refused.
(g) THE PACK COMES FROM GIT OBJECTS AT THE TAG: an uncommitted edit and a later commit do not
    reach it.
(p) THE PACK IS WHAT THE DESIGN SAYS: layout, modes, exclusions, FILES.json, the manifest, and
    identical bytes from two builds.
(d) FILES.json IDENTIFIES CLIENT CONTENT, NOT A RELEASE: two tags with the same client bytes
    give the same digest; a client change moves it.
(r) EVERY REFUSAL PATH IN THE BUILDER HAS A CASE HERE THAT GOES RED WHEN THAT PATH IS REMOVED.
    The population is derived, never listed: it is every line

        grep -nE 'raise Refused|parser\.error|return 1' bin/build-client-pack.py

    prints, plus the refusals argparse makes for the builder's mutually exclusive, required
    source group (`grep -n 'add_mutually_exclusive_group' bin/build-client-pack.py`): no source
    given, and both sources given. Each grep site was neutralized in turn (`raise Refused(` ->
    `Refused(`, `parser.error(` -> `print(`, `return 1` -> `return 0`), the group was replaced
    by the parser itself, and this whole file was run against each result. A refusal added to
    the builder joins that population and owes a case here.
    NOT in the population: an exception the builder does not catch (for example a tracked path
    that is not UTF-8). It fails closed, with a traceback and exit 1, and writes nothing, but it
    is a crash, not a refusal, and nothing here counts it as one.
(v) --verify-commit builds any commit, writes nothing, and cannot be given --out.
"""

import base64
import gzip
import hashlib
import importlib.util
import io
import json
import os
import shutil
import socket
import subprocess
import sys
import tarfile
import tempfile
import unittest

_HERE = os.path.dirname(os.path.abspath(__file__))
_SCRIPT = os.path.join(_HERE, "build-client-pack.py")
_spec = importlib.util.spec_from_file_location("build_client_pack", _SCRIPT)
bcp = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(bcp)

_NPM = shutil.which("npm")
_NO_NPM = "npm is not on PATH, so no case that runs `npm ci` can be exercised"
if _NPM is None:
    print(f"\n*** test_build_client_pack: {_NO_NPM}; those cases SKIP ***\n", file=sys.stderr)


def _run(*args, cwd=None):
    return subprocess.run([sys.executable, _SCRIPT, *args], capture_output=True, text=True, cwd=cwd, timeout=300)


def _git(repo, *args):
    return subprocess.run(
        ["git", "-C", repo, *args],
        capture_output=True,
        text=True,
        check=True,
        env={**os.environ, "GIT_CONFIG_GLOBAL": os.devnull, "GIT_CONFIG_NOSYSTEM": "1"},
    ).stdout.strip()


def _tiny_tarball() -> bytes:
    """A one-package npm tarball declaring one bin, built deterministically."""
    files = {
        "package/package.json": json.dumps({"name": "tiny", "version": "1.0.0", "bin": {"tiny": "bin/tiny.js"}}).encode(),
        "package/bin/tiny.js": b"#!/usr/bin/env node\n",
        "package/lib.js": b"module.exports = 1;\n",
    }
    raw = io.BytesIO()
    with tarfile.open(fileobj=raw, mode="w", format=tarfile.USTAR_FORMAT) as archive:
        for name, data in sorted(files.items()):
            info = tarfile.TarInfo(name)
            info.size, info.mode, info.mtime = len(data), 0o644, 0
            archive.addfile(info, io.BytesIO(data))
    out = io.BytesIO()
    with gzip.GzipFile(filename="", fileobj=out, mode="wb", mtime=0) as gz:
        gz.write(raw.getvalue())
    return out.getvalue()


def _lockfile(client_version: str, tarball: bytes, extra: dict = None) -> dict:
    integrity = "sha512-" + base64.b64encode(hashlib.sha512(tarball).digest()).decode()
    packages = {
        "": {
            "name": "fixture-channel",
            "version": client_version,
            "dependencies": {"tiny": "file:vendor/tiny-1.0.0.tgz"},
            "engines": {"node": ">=20"},
        },
        "node_modules/tiny": {
            "version": "1.0.0",
            "resolved": "file:vendor/tiny-1.0.0.tgz",
            "integrity": integrity,
            "bin": {"tiny": "bin/tiny.js"},
        },
    }
    packages.update(extra or {})
    return {"name": "fixture-channel", "version": client_version, "lockfileVersion": 3, "requires": True, "packages": packages}


class Fixture:
    """A git repository shaped like the bridge: VERSION, seat-tools.json, the channel server."""

    def __init__(self, root: str):
        self.repo = root
        self.tarball = _tiny_tarball()
        os.makedirs(root)
        _git(root, "init", "-q", "-b", "main")
        _git(root, "config", "user.email", "fixture@example.invalid")
        _git(root, "config", "user.name", "fixture")
        _git(root, "config", "commit.gpgsign", "false")
        _git(root, "config", "tag.gpgsign", "false")

    def write(self, path: str, data, mode: int = 0o644) -> None:
        full = os.path.join(self.repo, path)
        os.makedirs(os.path.dirname(full), exist_ok=True)
        with open(full, "wb") as fh:
            fh.write(data if isinstance(data, bytes) else data.encode())
        os.chmod(full, mode)

    def channel(self, client_version="0.9.40", lock_extra=None, engines=True) -> None:
        package = {
            "name": "fixture-channel",
            "version": client_version,
            "type": "module",
            "dependencies": {"tiny": "file:vendor/tiny-1.0.0.tgz"},
        }
        if engines:
            package["engines"] = {"node": ">=20"}
        base = "examples/channel-servers/"
        self.write(base + "package.json", json.dumps(package, indent=2) + "\n")
        self.write(base + "package-lock.json", json.dumps(_lockfile(client_version, self.tarball, lock_extra), indent=2) + "\n")
        self.write(base + "vendor/tiny-1.0.0.tgz", self.tarball)

    def baseline(self, version="1.2.3") -> None:
        self.write("VERSION", version + "\n")
        self.write("seat-tools.json", json.dumps({"schema": 1, "tools": ["bin/check-channel-snapshot.py"]}))
        self.write("bin/check-channel-snapshot.py", "#!/usr/bin/env python3\nprint('ok')\n", 0o755)
        self.write("bin/not-a-seat-tool.py", "#!/usr/bin/env python3\n", 0o755)
        self.write("examples/channel-servers/agent-webhook-bridge-channel.mjs", "#!/usr/bin/env node\n", 0o755)
        self.write("examples/channel-servers/channel-lib.mjs", "export const x = 1;\n")
        self.write("examples/channel-servers/tests/some.test.mjs", "// never shipped\n")
        self.channel()

    def commit(self, message="fixture") -> str:
        _git(self.repo, "add", "-A")
        _git(self.repo, "commit", "-q", "-m", message)
        return _git(self.repo, "rev-parse", "HEAD")

    def tag(self, name: str) -> None:
        _git(self.repo, "tag", name)


def _members(pack_path: str) -> dict:
    with tarfile.open(pack_path) as archive:
        return {m.name: (m, archive.extractfile(m).read()) for m in archive.getmembers()}


class ReleaseTagGate(unittest.TestCase):
    """(t) Resolution runs before npm, so these hold with or without npm on PATH."""

    @classmethod
    def setUpClass(cls):
        cls.tmp = tempfile.mkdtemp(prefix="bcp-tag-")
        cls.fx = Fixture(os.path.join(cls.tmp, "repo"))
        cls.fx.baseline("1.2.3")
        cls.commit = cls.fx.commit()
        cls.fx.tag("v1.2.3")
        cls.fx.tag("v1.2.4")
        _git(cls.fx.repo, "branch", "dev")
        _git(cls.fx.repo, "branch", "v9.9.9")
        _git(cls.fx.repo, "checkout", "-q", "-b", "v5.5.5")
        cls.fx.write("VERSION", "5.5.5\n")
        cls.fx.commit("a branch whose name and VERSION both look like a release")
        _git(cls.fx.repo, "checkout", "-q", "main")

    @classmethod
    def tearDownClass(cls):
        shutil.rmtree(cls.tmp, ignore_errors=True)

    def assert_refused(self, ref, needle):
        out = os.path.join(self.tmp, "out-" + ref.replace("/", "_"))
        result = _run("--ref", ref, "--out", out, "--repo", self.fx.repo)
        self.assertEqual(result.returncode, 1, result.stderr)
        self.assertIn("refused:", result.stderr)
        self.assertIn(needle, result.stderr)
        self.assertFalse(os.path.exists(out), "a refusal wrote --out")

    def test_a_branch_is_refused(self):
        self.assert_refused("dev", "not a release tag name")

    def test_a_commit_id_is_refused(self):
        self.assert_refused(self.commit, "not a release tag name")

    def test_head_is_refused(self):
        self.assert_refused("HEAD", "not a release tag name")

    def test_a_full_refname_is_refused(self):
        self.assert_refused("refs/tags/v1.2.3", "not a release tag name")

    def test_a_branch_shaped_like_a_tag_is_refused(self):
        self.assert_refused("v9.9.9", "is not a tag")

    def test_a_branch_whose_name_and_version_both_look_like_a_release_is_refused(self):
        self.assert_refused("v5.5.5", "is not a tag")

    def test_a_tag_name_in_non_ascii_digits_is_refused(self):
        self.assert_refused("v\u0661.\u0662.\u0663", "not a release tag name")

    def test_a_missing_tag_is_refused(self):
        self.assert_refused("v7.7.7", "is not a tag")

    def test_a_tag_whose_version_file_disagrees_is_refused(self):
        self.assert_refused("v1.2.4", "the tag and the release disagree")

    def test_the_resolver_returns_the_tagged_commit_and_bare_release(self):
        self.assertEqual(bcp.resolve_release_tag(self.fx.repo, "v1.2.3"), (self.commit, "1.2.3"))


@unittest.skipIf(_NPM is None, _NO_NPM)
class PackFromTag(unittest.TestCase):
    """(g) (p) (d)"""

    @classmethod
    def setUpClass(cls):
        cls.tmp = tempfile.mkdtemp(prefix="bcp-pack-")
        cls.fx = Fixture(os.path.join(cls.tmp, "repo"))
        cls.fx.baseline("1.2.3")
        cls.commit = cls.fx.commit()
        cls.fx.tag("v1.2.3")

        cls.fx.write("VERSION", "1.2.4\n")
        cls.fx.commit("release 1.2.4, client untouched")
        cls.fx.tag("v1.2.4")

        cls.fx.write("VERSION", "1.2.5\n")
        cls.fx.write("examples/channel-servers/channel-lib.mjs", "export const x = 2;\n")
        cls.fx.channel(client_version="0.9.41")
        cls.fx.commit("release 1.2.5, client changed")
        cls.fx.tag("v1.2.5")

        cls.fx.write("examples/channel-servers/channel-lib.mjs", "export const uncommitted = true;\n")

        cls.out = {}
        for tag in ("v1.2.3", "v1.2.4", "v1.2.5"):
            cls.out[tag] = os.path.join(cls.tmp, "out-" + tag)
            result = _run("--ref", tag, "--out", cls.out[tag], "--repo", cls.fx.repo)
            if result.returncode != 0:
                raise AssertionError(f"building {tag} failed: {result.stderr}")

    @classmethod
    def tearDownClass(cls):
        shutil.rmtree(cls.tmp, ignore_errors=True)

    def manifest(self, tag):
        with open(os.path.join(self.out[tag], f"client-pack-{tag}.manifest.json"), encoding="utf-8") as fh:
            return json.load(fh)

    def pack_path(self, tag):
        return os.path.join(self.out[tag], f"client-pack-{tag}.tar.gz")

    def test_out_holds_exactly_the_pack_and_manifest(self):
        self.assertEqual(
            sorted(os.listdir(self.out["v1.2.3"])),
            ["client-pack-v1.2.3.manifest.json", "client-pack-v1.2.3.tar.gz"],
        )

    def test_the_manifest_describes_the_pack(self):
        manifest = self.manifest("v1.2.3")
        with open(self.pack_path("v1.2.3"), "rb") as fh:
            pack = fh.read()
        members = _members(self.pack_path("v1.2.3"))
        self.assertEqual(
            manifest,
            {
                "schema": 1,
                "kind": "agent-webhook-bridge-client-pack",
                "bridge_release": "1.2.3",
                "minted_from_commit": self.commit,
                "client_version": "0.9.40",
                "node_engines": ">=20",
                "pack": {"file": "client-pack-v1.2.3.tar.gz", "sha256": hashlib.sha256(pack).hexdigest(), "size": len(pack)},
                "files_json_sha256": hashlib.sha256(members["FILES.json"][1]).hexdigest(),
            },
        )

    def test_the_pack_holds_the_declared_layout_and_nothing_else(self):
        self.assertEqual(
            sorted(_members(self.pack_path("v1.2.3"))),
            [
                "FILES.json",
                "client/agent-webhook-bridge-channel.mjs",
                "client/channel-lib.mjs",
                "client/node_modules/tiny/bin/tiny.js",
                "client/node_modules/tiny/lib.js",
                "client/node_modules/tiny/package.json",
                "client/package-lock.json",
                "client/package.json",
                "client/vendor/tiny-1.0.0.tgz",
                "seat-tools/bin/check-channel-snapshot.py",
            ],
        )

    def test_entries_are_regular_files_with_fixed_metadata_and_declared_modes(self):
        executable = {
            "client/agent-webhook-bridge-channel.mjs",
            "client/node_modules/tiny/bin/tiny.js",
            "seat-tools/bin/check-channel-snapshot.py",
        }
        for name, (member, _data) in _members(self.pack_path("v1.2.3")).items():
            with self.subTest(name=name):
                self.assertTrue(member.isreg())
                self.assertEqual((member.uid, member.gid, member.uname, member.gname), (0, 0, "", ""))
                self.assertEqual(member.mtime, 315532800)
                self.assertEqual(member.mode, 0o755 if name in executable else 0o644)

    def test_files_json_lists_every_other_entry_with_its_hash(self):
        members = _members(self.pack_path("v1.2.3"))
        listing = json.loads(members["FILES.json"][1])
        self.assertEqual([entry["path"] for entry in listing], sorted(n for n in members if n != "FILES.json"))
        for entry in listing:
            member, data = members[entry["path"]]
            self.assertEqual(entry["sha256"], hashlib.sha256(data).hexdigest())
            self.assertEqual(entry["size"], len(data))
            self.assertEqual(entry["mode"], f"{member.mode:04o}")

    def test_the_pack_comes_from_the_tag_not_the_working_tree_or_a_later_commit(self):
        data = _members(self.pack_path("v1.2.3"))["client/channel-lib.mjs"][1]
        self.assertEqual(data, b"export const x = 1;\n")

    def test_two_builds_of_one_tag_are_byte_identical(self):
        again = os.path.join(self.tmp, "again")
        result = _run("--ref", "v1.2.3", "--out", again, "--repo", self.fx.repo)
        self.assertEqual(result.returncode, 0, result.stderr)
        for name in os.listdir(self.out["v1.2.3"]):
            with open(os.path.join(self.out["v1.2.3"], name), "rb") as a, open(os.path.join(again, name), "rb") as b:
                self.assertEqual(a.read(), b.read(), name)

    def test_the_client_digest_is_the_same_for_two_releases_with_the_same_client(self):
        first, second = self.manifest("v1.2.3"), self.manifest("v1.2.4")
        self.assertEqual(first["files_json_sha256"], second["files_json_sha256"])
        self.assertEqual(first["pack"]["sha256"], second["pack"]["sha256"])
        self.assertNotEqual(first["bridge_release"], second["bridge_release"])

    def test_the_client_digest_moves_when_the_client_changes(self):
        self.assertNotEqual(self.manifest("v1.2.4")["files_json_sha256"], self.manifest("v1.2.5")["files_json_sha256"])

    def test_a_write_failure_after_the_build_is_reported_as_failed(self):
        blocker = os.path.join(self.tmp, "a-file")
        with open(blocker, "w") as fh:
            fh.write("x")
        result = _run("--ref", "v1.2.3", "--out", os.path.join(blocker, "out"), "--repo", self.fx.repo)
        self.assertEqual(result.returncode, 1)
        self.assertIn("FAILED while writing", result.stderr)

    def test_a_non_empty_out_is_refused(self):
        result = _run("--ref", "v1.2.3", "--out", self.out["v1.2.3"], "--repo", self.fx.repo)
        self.assertEqual(result.returncode, 1)
        self.assertIn("is not an empty directory", result.stderr)


class Refusals(unittest.TestCase):
    """(r) Each case is a fresh tag on a fixture carrying exactly one defect."""

    def setUp(self):
        self.tmp = tempfile.mkdtemp(prefix="bcp-refuse-")
        self.fx = Fixture(os.path.join(self.tmp, "repo"))
        self.fx.baseline("1.2.3")

    def tearDown(self):
        shutil.rmtree(self.tmp, ignore_errors=True)

    def assert_refused(self, needle, commit=True):
        if commit:
            self.fx.commit()
        self.fx.tag("v1.2.3")
        out = os.path.join(self.tmp, "out")
        result = _run("--ref", "v1.2.3", "--out", out, "--repo", self.fx.repo)
        self.assertEqual(result.returncode, 1, result.stdout + result.stderr)
        self.assertIn("refused:", result.stderr)
        self.assertIn(needle, result.stderr)
        self.assertFalse(os.path.exists(out))

    def test_an_install_script_in_the_lockfile(self):
        self.fx.channel(lock_extra={"node_modules/evil": {"version": "1.0.0", "hasInstallScript": True}})
        self.assert_refused("carries hasInstallScript")

    def test_an_os_restricted_lockfile_entry(self):
        self.fx.channel(lock_extra={"node_modules/plat": {"version": "1.0.0", "os": ["linux"]}})
        self.assert_refused("carries os")

    def test_a_cpu_restricted_lockfile_entry(self):
        self.fx.channel(lock_extra={"node_modules/plat": {"version": "1.0.0", "cpu": ["x64"]}})
        self.assert_refused("carries cpu")

    def test_a_client_version_that_is_not_bare(self):
        self.fx.channel(client_version="0.9.40-beta.1")
        self.assert_refused("not bare X.Y.Z")

    def test_a_client_version_in_non_ascii_digits(self):
        self.fx.channel(client_version="0.9.\u0664\u0660")
        self.assert_refused("not bare X.Y.Z")

    def test_no_engines_node(self):
        self.fx.channel(engines=False)
        self.assert_refused("declares no engines.node")

    def test_a_tracked_symlink(self):
        os.symlink("channel-lib.mjs", os.path.join(self.fx.repo, "examples/channel-servers/alias.mjs"))
        self.assert_refused("a pack holds regular files only")

    def test_a_seat_tool_that_is_not_executable_in_git(self):
        self.fx.write("bin/check-channel-snapshot.py", "#!/usr/bin/env python3\n", 0o644)
        self.assert_refused("must be tracked at 100755")

    def test_a_version_file_that_is_not_bare(self):
        self.fx.write("VERSION", "1.2.3-rc\n")
        self.assert_refused("not bare X.Y.Z")

    def test_a_missing_version_file(self):
        os.unlink(os.path.join(self.fx.repo, "VERSION"))
        self.assert_refused("VERSION is unreadable")

    def test_a_submodule_in_the_channel_dir(self):
        self.fx.commit()
        head = _git(self.fx.repo, "rev-parse", "HEAD")
        _git(self.fx.repo, "update-index", "--add", "--cacheinfo", f"160000,{head},examples/channel-servers/vendored")
        _git(self.fx.repo, "commit", "-q", "-m", "a gitlink under the channel dir")
        self.assert_refused("is git mode 160000", commit=False)

    def test_no_tracked_channel_server_files(self):
        shutil.rmtree(os.path.join(self.fx.repo, "examples"))
        self.assert_refused("holds no tracked files")

    def test_a_missing_seat_tools_declaration(self):
        os.unlink(os.path.join(self.fx.repo, "seat-tools.json"))
        self.assert_refused("unreadable or has no `tools` list")

    def test_a_seat_tools_declaration_with_no_tools(self):
        self.fx.write("seat-tools.json", json.dumps({"schema": 1, "tools": []}))
        self.assert_refused("declares no tools")

    def test_a_seat_tool_outside_bin(self):
        self.fx.write("tools/check-channel-snapshot.py", "#!/usr/bin/env python3\n", 0o755)
        self.fx.write("seat-tools.json", json.dumps({"schema": 1, "tools": ["tools/check-channel-snapshot.py"]}))
        self.assert_refused("is not a file directly under bin/")

    def test_two_seat_tools_with_one_install_name(self):
        tool = "bin/check-channel-snapshot.py"
        self.fx.write("seat-tools.json", json.dumps({"schema": 1, "tools": [tool, tool]}))
        self.assert_refused("two tools with one install name")

    def test_a_missing_lockfile(self):
        os.unlink(os.path.join(self.fx.repo, "examples/channel-servers/package-lock.json"))
        self.assert_refused("package-lock.json is not tracked")

    def test_a_package_json_that_is_not_json(self):
        self.fx.write("examples/channel-servers/package.json", "{not json")
        self.assert_refused("package.json is not JSON")

    def test_a_lockfile_with_no_packages_map(self):
        self.fx.write("examples/channel-servers/package-lock.json", json.dumps({"lockfileVersion": 1, "dependencies": {}}))
        self.assert_refused("has no `packages` map")

    def test_npm_not_on_path(self):
        shim = os.path.join(self.tmp, "shim")
        os.makedirs(shim)
        os.symlink(shutil.which("git"), os.path.join(shim, "git"))
        self.fx.commit()
        self.fx.tag("v1.2.3")
        out = os.path.join(self.tmp, "out")
        result = subprocess.run(
            [sys.executable, _SCRIPT, "--ref", "v1.2.3", "--out", out, "--repo", self.fx.repo],
            capture_output=True,
            text=True,
            timeout=300,
            env={**os.environ, "PATH": shim},
        )
        self.assertEqual(result.returncode, 1, result.stdout + result.stderr)
        self.assertIn("npm is not on PATH", result.stderr)
        self.assertFalse(os.path.exists(out))

    @unittest.skipIf(_NPM is None, _NO_NPM)
    def test_npm_ci_failing(self):
        lock = _lockfile("0.9.40", self.fx.tarball)
        lock["packages"]["node_modules/tiny"]["integrity"] = "sha512-" + base64.b64encode(b"\0" * 64).decode()
        self.fx.write("examples/channel-servers/package-lock.json", json.dumps(lock))
        self.assert_refused("npm ci exited")

    @unittest.skipIf(_NPM is None, _NO_NPM)
    def test_a_symlink_npm_ci_creates(self):
        base = "examples/channel-servers/"
        self.fx.write(base + "localdep/package.json", json.dumps({"name": "localdep", "version": "1.0.0"}))
        package = json.loads(open(os.path.join(self.fx.repo, base + "package.json")).read())
        package["dependencies"]["localdep"] = "file:localdep"
        self.fx.write(base + "package.json", json.dumps(package))
        lock = _lockfile("0.9.40", self.fx.tarball)
        lock["packages"][""]["dependencies"]["localdep"] = "file:localdep"
        lock["packages"]["localdep"] = {"version": "1.0.0"}
        lock["packages"]["node_modules/localdep"] = {"resolved": "localdep", "link": True}
        self.fx.write(base + "package-lock.json", json.dumps(lock))
        self.assert_refused("client/node_modules/localdep is a symlink after npm ci")

    @unittest.skipIf(_NPM is None, _NO_NPM)
    def test_a_path_ustar_cannot_hold(self):
        self.fx.write("examples/channel-servers/" + "n" * 120 + ".mjs", "export {};\n")
        self.assert_refused("cannot be stored in a ustar archive")

    @unittest.skipIf(_NPM is None, _NO_NPM)
    def test_a_tracked_node_modules_file_does_not_reach_the_pack(self):
        self.fx.write("examples/channel-servers/node_modules/vendored/stale.js", "stale\n")
        self.fx.commit()
        self.fx.tag("v1.2.3")
        out = os.path.join(self.tmp, "out")
        result = _run("--ref", "v1.2.3", "--out", out, "--repo", self.fx.repo)
        self.assertEqual(result.returncode, 0, result.stderr)
        members = _members(os.path.join(out, "client-pack-v1.2.3.tar.gz"))
        self.assertNotIn("client/node_modules/vendored/stale.js", members)
        self.assertIn("client/node_modules/tiny/lib.js", members)

    def test_an_out_that_is_a_symlink(self):
        self.fx.commit()
        self.fx.tag("v1.2.3")
        target = os.path.join(self.tmp, "target")
        os.makedirs(target)
        out = os.path.join(self.tmp, "out-link")
        os.symlink(target, out)
        result = _run("--ref", "v1.2.3", "--out", out, "--repo", self.fx.repo)
        self.assertEqual(result.returncode, 1)
        self.assertIn("is a symlink", result.stderr)
        self.assertEqual(os.listdir(target), [])

    @unittest.skipIf(_NPM is None, _NO_NPM)
    def test_a_native_addon(self):
        self.fx.write("examples/channel-servers/addon.node", b"\x7fELF")
        self.assert_refused("is native code")

    @unittest.skipIf(_NPM is None, _NO_NPM)
    def test_a_binding_gyp(self):
        self.fx.write("examples/channel-servers/binding.gyp", "{}")
        self.assert_refused("is native code")


class InstalledTreeSpecialFile(unittest.TestCase):
    """The installed-tree walk refuses a special file. No `npm ci` run here was found to produce
    one, so this drives `installed_files` directly over a tree holding a Unix socket. A socket and
    not a FIFO: with the refusal removed, reading a FIFO blocks forever, so the control would hang
    instead of going red. Opening a socket fails at once."""

    def test_a_socket_in_the_installed_tree_is_refused(self):
        with tempfile.TemporaryDirectory(prefix="bcp-sock-") as client:
            os.makedirs(os.path.join(client, "node_modules", "x"))
            server = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
            try:
                server.bind(os.path.join(client, "node_modules", "x", "sock"))
                with self.assertRaisesRegex(bcp.Refused, r"client/node_modules/x/sock is not a regular file"):
                    bcp.installed_files(client, {}, set())
            finally:
                server.close()


class VerifyCommit(unittest.TestCase):
    """(v)"""

    @classmethod
    def setUpClass(cls):
        cls.tmp = tempfile.mkdtemp(prefix="bcp-verify-")
        cls.fx = Fixture(os.path.join(cls.tmp, "repo"))
        cls.fx.baseline("1.2.3")
        cls.commit = cls.fx.commit()
        cls.cwd = os.path.join(cls.tmp, "cwd")
        os.makedirs(cls.cwd)

    @classmethod
    def tearDownClass(cls):
        shutil.rmtree(cls.tmp, ignore_errors=True)

    @unittest.skipIf(_NPM is None, _NO_NPM)
    def test_an_untagged_commit_builds_and_nothing_is_written(self):
        result = _run("--verify-commit", self.commit, "--repo", self.fx.repo, cwd=self.cwd)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn(f"{self.commit} builds", result.stdout)
        self.assertIn("nothing written", result.stdout)
        self.assertEqual(os.listdir(self.cwd), [])
        self.assertEqual(_git(self.fx.repo, "status", "--porcelain"), "")

    def test_it_takes_no_out(self):
        result = _run("--verify-commit", "main", "--out", os.path.join(self.tmp, "x"), "--repo", self.fx.repo)
        self.assertEqual(result.returncode, 2)
        self.assertIn("takes no --out", result.stderr)

    def test_no_source_is_a_usage_error(self):
        result = _run("--repo", self.fx.repo)
        self.assertEqual(result.returncode, 2)
        self.assertIn("one of the arguments --ref --verify-commit is required", result.stderr)

    def test_both_sources_is_a_usage_error(self):
        result = _run("--ref", "v1.2.3", "--verify-commit", "main", "--repo", self.fx.repo)
        self.assertEqual(result.returncode, 2)
        self.assertIn("not allowed with argument", result.stderr)

    def test_a_ref_needs_an_out(self):
        result = _run("--ref", "v1.2.3", "--repo", self.fx.repo)
        self.assertEqual(result.returncode, 2)

    def test_it_runs_the_same_refusals(self):
        self.fx.channel(lock_extra={"node_modules/evil": {"version": "1.0.0", "hasInstallScript": True}})
        self.fx.commit("defect")
        result = _run("--verify-commit", "HEAD", "--repo", self.fx.repo)
        self.assertEqual(result.returncode, 1)
        self.assertIn("carries hasInstallScript", result.stderr)

    def test_a_revision_that_names_no_commit_is_refused(self):
        result = _run("--verify-commit", "no-such-branch", "--repo", self.fx.repo)
        self.assertEqual(result.returncode, 1)
        self.assertIn("names no commit", result.stderr)


if __name__ == "__main__":
    unittest.main()
