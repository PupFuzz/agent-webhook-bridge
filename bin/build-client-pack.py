#!/usr/bin/env python3
"""Build the channel-server client pack for one bridge release, from that release's tag.

    python3 bin/build-client-pack.py --ref v<X.Y.Z> --out <dir>     # a publishable pack
    python3 bin/build-client-pack.py --verify-commit <rev>          # does it build? writes nothing

A pack is what a seat installs as its channel server: the reference client with its
dependencies already in place, so a seat never fetches from a package registry. This program
only BUILDS it; publishing, serving and installing are other programs' jobs, and no seat reads
a pack today.

BUILT ONLY FROM A RELEASE TAG. `--ref` must be a tag name of the form `v<X.Y.Z>` that exists
under `refs/tags/`, and the root `VERSION` file at that tag must read exactly `<X.Y.Z>`. A
branch, a commit id, `HEAD`, a full refname, or a branch that merely LOOKS like a tag is
refused. Every input is read from git objects at the tagged commit, never from the working
tree or the index, so uncommitted edits and later commits cannot reach a pack.

`--verify-commit` IS NOT A SECOND WAY TO MAKE A PACK. It runs every step, and every refusal
that applies to an untagged commit, against any commit, then discards the result: it takes no `--out` and writes nothing. It
exists so a pull request can prove its tree still builds before it merges.

INPUTS, at the one commit: the tracked files under `examples/channel-servers/` except its
`tests/`, and the seat tools `seat-tools.json` declares. Then `npm ci --ignore-scripts
--omit=dev` runs against the committed lockfile, which reaches the registry (or npm's cache)
from the machine that BUILDS the pack, never from a seat.

REFUSED, before anything is written: a channel-server tree that does not track the files a seat
needs to run AND update itself (REQUIRED_CLIENT_FILES: the entry point, the updater and the
server; design review r3-M6), so no pack can reach a seat and strand it on a client that cannot
update; a tracked file that is not a regular file (a symlink, a submodule); a lockfile entry carrying `hasInstallScript`, `os` or `cpu`; a `client_version`
(the channel server's `package.json` `version`) that is not bare `X.Y.Z`; a `package.json`
with no `engines.node` string; any `*.node` file or `binding.gyp`, and any symlink or special
file, in the installed tree; a path the ustar format cannot hold.

THE PACK, `client-pack-v<X.Y.Z>.tar.gz`: a ustar archive, gzip-compressed, holding

    client/<file>                  the channel server and its node_modules
    seat-tools/bin/<tool>          each declared seat tool
    FILES.json                     [{path, mode, sha256, size}] for every other entry, sorted

Regular files only; entries sorted; uid/gid 0 with no owner names; mtime
1980-01-01T00:00:00Z; gzip header mtime 0 and no file name. A file is 0755 where git records
100755, where it is a seat tool, or where the lockfile declares it a package `bin`; every
other file is 0644. Excluded: `node_modules/.bin/` (symlinks npm makes) and
`node_modules/.package-lock.json` (npm's own record of the install).

NOTHING IN THE PACK NAMES THE RELEASE, so FILES.json's sha256 identifies the client CONTENT:
two releases that ship the same client bytes produce the same digest. That digest is what client
approval keys on (card#10567), which is why the release is named only in the manifest beside
the pack and never inside it.

THE MANIFEST, `client-pack-v<X.Y.Z>.manifest.json`:

    schema, kind            1, "agent-webhook-bridge-client-pack"
    bridge_release          <X.Y.Z>, bare; the tag is "v" + this
    minted_from_commit      the 40-hex commit the tag points at
    client_version          the channel server's package.json version
    node_engines            its engines.node range
    pack                    {file, sha256, size}
    files_json_sha256       sha256 of the FILES.json inside the pack

Nothing here is signed (operator ruling, card#10567): integrity is the sha256 values and the
tag the pack was built from.

DETERMINISTIC on one toolchain: two builds of one tag on one host produce identical bytes. That
is an audit property. A different Python zlib, or a different npm, may produce different bytes
for the same inputs, and no consumer may depend on the pack's bytes being reproducible
elsewhere.

`--out` must be absent or an empty directory; both files are written only after the whole
build has succeeded.

EXIT: 0 built (or verified) · 1 refused, or the build failed; the reason on stderr · 2 usage.
"""

import argparse
import gzip
import hashlib
import io
import json
import os
import re
import shutil
import stat
import subprocess
import sys
import tarfile
import tempfile

SCHEMA = 1
KIND = "agent-webhook-bridge-client-pack"
TAG = re.compile(r"v([0-9]+\.[0-9]+\.[0-9]+)")
STRICT_VERSION = re.compile(r"[0-9]+\.[0-9]+\.[0-9]+")
CHANNEL_DIR = "examples/channel-servers/"
EXCLUDED_CHANNEL_SUBTREES = ("tests/",)
# The files a seat refuses a pack without and will not start a release without
# (`REQUIRED_CLIENT_FILES` in examples/channel-servers/entry.mjs, minus package.json, which
# client_metadata already requires). bin/test_build_client_pack.py holds the two lists equal.
REQUIRED_CLIENT_FILES = ("entry.mjs", "client-update.mjs", "agent-webhook-bridge-channel.mjs", "channel-lib.mjs")
SEAT_TOOLS_MANIFEST = "seat-tools.json"
REGULAR_MODES = {"100644": 0o644, "100755": 0o755}
LOCKFILE_REFUSED_KEYS = ("hasInstallScript", "os", "cpu")
FIXED_MTIME = 315532800
FILES_JSON = "FILES.json"
REPO = os.path.dirname(os.path.dirname(os.path.realpath(__file__)))


class Refused(Exception):
    pass


def git(repo: str, *args: str) -> bytes:
    proc = subprocess.run(["git", "-C", repo, *args], capture_output=True)
    if proc.returncode != 0:
        detail = proc.stderr.decode("utf-8", "replace").strip()
        raise Refused(f"git {' '.join(args)} exited {proc.returncode}: {detail}")
    return proc.stdout


def commit_of(repo: str, revision: str):
    proc = subprocess.run(
        ["git", "-C", repo, "rev-parse", "--verify", "--quiet", "--end-of-options", f"{revision}^{{commit}}"],
        capture_output=True,
        text=True,
    )
    return proc.stdout.strip() if proc.returncode == 0 else None


def read_version_file(repo: str, commit: str) -> str:
    try:
        text = git(repo, "cat-file", "blob", f"{commit}:VERSION").decode("utf-8").strip()
    except (Refused, UnicodeDecodeError) as exc:
        raise Refused(f"VERSION is unreadable at {commit}: {exc}")
    if not STRICT_VERSION.fullmatch(text):
        raise Refused(f"VERSION at {commit} is {text!r}, not bare X.Y.Z")
    return text


def resolve_release_tag(repo: str, ref: str):
    """(commit, bridge_release) for a release tag, or Refused for anything that is not one."""
    match = TAG.fullmatch(ref)
    if not match:
        raise Refused(
            f"--ref {ref!r} is not a release tag name (v<X.Y.Z>); a pack is built only from a tagged "
            "release, never from a branch, a commit or a working tree"
        )
    commit = commit_of(repo, f"refs/tags/{ref}")
    if commit is None:
        raise Refused(f"--ref {ref!r} is not a tag in {repo}; a branch or a commit of that name is not a release")
    version = read_version_file(repo, commit)
    if version != match.group(1):
        raise Refused(f"tag {ref} points at {commit}, whose VERSION is {version}; the tag and the release disagree")
    return commit, version


def tracked(repo: str, commit: str, path: str) -> list:
    """(git mode, blob id, repo path) for every tracked entry at or under `path` at `commit`."""
    entries = []
    for record in git(repo, "ls-tree", "-r", "-z", "--full-tree", commit, "--", path).split(b"\0"):
        if not record:
            continue
        meta, name = record.split(b"\t", 1)
        mode, _kind, blob = meta.decode("ascii").split(" ")
        entries.append((mode, blob, name.decode("utf-8")))
    return entries


def blob(repo: str, blob_id: str) -> bytes:
    return git(repo, "cat-file", "blob", blob_id)


def channel_files(repo: str, commit: str) -> list:
    """(path under client/, mode, bytes) for each shipped channel-server file at `commit`."""
    shipped = []
    for mode, blob_id, path in tracked(repo, commit, CHANNEL_DIR):
        relative = path[len(CHANNEL_DIR):]
        if relative.startswith(EXCLUDED_CHANNEL_SUBTREES):
            continue
        if mode not in REGULAR_MODES:
            raise Refused(f"{path} is git mode {mode} at {commit}; a pack holds regular files only")
        shipped.append((relative, REGULAR_MODES[mode], blob(repo, blob_id)))
    if not shipped:
        raise Refused(f"{CHANNEL_DIR} holds no tracked files at {commit}")
    names = {relative for relative, _, _ in shipped}
    for required in REQUIRED_CLIENT_FILES:
        if required not in names:
            raise Refused(
                f"{CHANNEL_DIR}{required} is not tracked at {commit}; a pack must carry the seat's entry point, "
                "updater and server, or the seats it reaches could never update again"
            )
    return shipped


def seat_tools(repo: str, commit: str) -> list:
    """(install name, bytes) for each tool seat-tools.json declares at `commit`."""
    try:
        declared = json.loads(git(repo, "cat-file", "blob", f"{commit}:{SEAT_TOOLS_MANIFEST}"))["tools"]
    except (Refused, ValueError, KeyError, TypeError) as exc:
        raise Refused(f"{SEAT_TOOLS_MANIFEST} at {commit} is unreadable or has no `tools` list: {exc}")
    if not isinstance(declared, list) or not declared:
        raise Refused(f"{SEAT_TOOLS_MANIFEST} at {commit} declares no tools")
    tools = []
    for entry in declared:
        if not isinstance(entry, str) or os.path.dirname(entry) != "bin" or not os.path.basename(entry):
            raise Refused(f"{SEAT_TOOLS_MANIFEST} entry {entry!r} is not a file directly under bin/")
        found = [(mode, blob_id) for mode, blob_id, path in tracked(repo, commit, entry) if path == entry]
        if not found or found[0][0] != "100755":
            state = "not tracked" if not found else f"git mode {found[0][0]}"
            raise Refused(f"seat tool {entry} is {state} at {commit}; a seat tool must be tracked at 100755")
        tools.append((os.path.basename(entry), blob(repo, found[0][1])))
    names = [name for name, _ in tools]
    if len(set(names)) != len(names):
        raise Refused(f"{SEAT_TOOLS_MANIFEST} declares two tools with one install name: {sorted(names)}")
    return tools


def read_json(files: dict, name: str):
    if name not in files:
        raise Refused(f"{CHANNEL_DIR}{name} is not tracked")
    try:
        return json.loads(files[name])
    except ValueError as exc:
        raise Refused(f"{CHANNEL_DIR}{name} is not JSON: {exc}")


def client_metadata(files: dict):
    """(client_version, node_engines, lockfile package map), refusing what the pack may not carry."""
    package = read_json(files, "package.json")
    version = package.get("version") if isinstance(package, dict) else None
    if not isinstance(version, str) or not STRICT_VERSION.fullmatch(version):
        raise Refused(f"{CHANNEL_DIR}package.json version is {version!r}, not bare X.Y.Z")
    engines = package.get("engines")
    node_engines = engines.get("node") if isinstance(engines, dict) else None
    if not isinstance(node_engines, str) or not node_engines:
        raise Refused(f"{CHANNEL_DIR}package.json declares no engines.node string")

    lock = read_json(files, "package-lock.json")
    packages = lock.get("packages") if isinstance(lock, dict) else None
    if not isinstance(packages, dict):
        raise Refused(f"{CHANNEL_DIR}package-lock.json has no `packages` map (lockfileVersion 2 or later)")
    for location, entry in sorted(packages.items()):
        present = [key for key in LOCKFILE_REFUSED_KEYS if isinstance(entry, dict) and key in entry]
        if present:
            raise Refused(
                f"package-lock.json entry {location or '(root)'} carries {', '.join(present)}; a pack ships no "
                "install scripts and no platform-specific packages"
            )
    return version, node_engines, packages


def declared_bin_files(packages: dict) -> set:
    """Paths under client/ that the lockfile declares as a package `bin`."""
    executables = set()
    for location, entry in packages.items():
        if not location or not isinstance(entry, dict):
            continue
        bins = entry.get("bin")
        if isinstance(bins, dict):
            for target in bins.values():
                if isinstance(target, str):
                    executables.add(os.path.normpath(os.path.join(location, target)))
    return executables


def npm_ci(client: str) -> None:
    if shutil.which("npm") is None:
        raise Refused("npm is not on PATH; the pack's dependencies are installed with `npm ci`")
    proc = subprocess.run(
        ["npm", "ci", "--ignore-scripts", "--omit=dev", "--no-audit", "--no-fund"],
        cwd=client,
        capture_output=True,
        text=True,
    )
    if proc.returncode != 0:
        raise Refused(f"npm ci exited {proc.returncode}: {proc.stderr.strip()[-2000:]}")


def installed_files(client: str, git_modes: dict, bin_files: set) -> list:
    """(path under client/, mode, bytes) for the whole installed tree, refusing what it may not hold."""
    found = []
    for base, dirs, files in os.walk(client):
        relative_base = os.path.relpath(base, client)
        if os.path.basename(base) == "node_modules":
            if ".bin" in dirs:
                dirs.remove(".bin")
            if relative_base == "node_modules" and ".package-lock.json" in files:
                files.remove(".package-lock.json")
        for name in sorted(dirs + files):
            full = os.path.join(base, name)
            relative = os.path.normpath(os.path.join(relative_base, name))
            kind = os.lstat(full).st_mode
            if stat.S_ISLNK(kind):
                raise Refused(f"client/{relative} is a symlink after npm ci; a pack holds regular files only")
            if stat.S_ISDIR(kind):
                continue
            if not stat.S_ISREG(kind):
                raise Refused(f"client/{relative} is not a regular file")
            if name.endswith(".node") or name == "binding.gyp":
                raise Refused(f"client/{relative} is native code; a pack ships no compiled or compilable addons")
            if relative in git_modes:
                mode = git_modes[relative]
            else:
                mode = 0o755 if relative in bin_files else 0o644
            with open(full, "rb") as fh:
                found.append((relative, mode, fh.read()))
    return found


def assemble(repo: str, commit: str):
    """([(pack path, mode, bytes)] for every entry except FILES.json, client_version, node_engines)."""
    shipped = channel_files(repo, commit)
    tools = seat_tools(repo, commit)
    by_name = {relative: data for relative, _, data in shipped}
    client_version, node_engines, packages = client_metadata(by_name)

    with tempfile.TemporaryDirectory(prefix="client-pack-") as scratch:
        client = os.path.join(scratch, "client")
        for relative, mode, data in shipped:
            target = os.path.join(client, relative)
            os.makedirs(os.path.dirname(target), exist_ok=True)
            with open(target, "wb") as fh:
                fh.write(data)
        npm_ci(client)
        installed = installed_files(
            client,
            {relative: mode for relative, mode, _ in shipped},
            declared_bin_files(packages),
        )

    entries = [(f"client/{relative}", mode, data) for relative, mode, data in installed]
    entries += [(f"seat-tools/bin/{name}", 0o755, data) for name, data in tools]
    return sorted(entries), client_version, node_engines


def files_json(entries: list) -> bytes:
    listing = [
        {"path": path, "mode": f"{mode:04o}", "sha256": hashlib.sha256(data).hexdigest(), "size": len(data)}
        for path, mode, data in entries
    ]
    return (json.dumps(listing, indent=2, sort_keys=True) + "\n").encode("utf-8")


def tar_gz(entries: list) -> bytes:
    raw = io.BytesIO()
    with tarfile.open(fileobj=raw, mode="w", format=tarfile.USTAR_FORMAT) as archive:
        for path, mode, data in sorted(entries):
            info = tarfile.TarInfo(path)
            info.size = len(data)
            info.mode = mode
            info.mtime = FIXED_MTIME
            info.uid = info.gid = 0
            info.uname = info.gname = ""
            info.type = tarfile.REGTYPE
            try:
                archive.addfile(info, io.BytesIO(data))
            except ValueError as exc:
                raise Refused(f"{path} cannot be stored in a ustar archive: {exc}")
    compressed = io.BytesIO()
    with gzip.GzipFile(filename="", fileobj=compressed, mode="wb", compresslevel=9, mtime=0) as gz:
        gz.write(raw.getvalue())
    return compressed.getvalue()


def build(repo: str, commit: str, bridge_release: str) -> dict:
    entries, client_version, node_engines = assemble(repo, commit)
    listing = files_json(entries)
    pack = tar_gz(entries + [(FILES_JSON, 0o644, listing)])
    pack_name = f"client-pack-v{bridge_release}.tar.gz"
    manifest = {
        "schema": SCHEMA,
        "kind": KIND,
        "bridge_release": bridge_release,
        "minted_from_commit": commit,
        "client_version": client_version,
        "node_engines": node_engines,
        "pack": {"file": pack_name, "sha256": hashlib.sha256(pack).hexdigest(), "size": len(pack)},
        "files_json_sha256": hashlib.sha256(listing).hexdigest(),
    }
    return {
        "pack_name": pack_name,
        "pack": pack,
        "manifest_name": f"client-pack-v{bridge_release}.manifest.json",
        "manifest": (json.dumps(manifest, indent=2) + "\n").encode("utf-8"),
        "summary": manifest,
        "file_count": len(entries),
    }


def refuse_unusable_out(out: str) -> None:
    if os.path.islink(out):
        raise Refused(f"--out {out} is a symlink")
    if os.path.exists(out) and (not os.path.isdir(out) or os.listdir(out)):
        raise Refused(f"--out {out} exists and is not an empty directory")


def write_atomically(path: str, data: bytes) -> None:
    temp = f"{path}.partial"
    with open(temp, "wb") as fh:
        fh.write(data)
        fh.flush()
        os.fsync(fh.fileno())
    os.chmod(temp, 0o644)
    os.rename(temp, path)


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(
        prog="build-client-pack.py",
        description=__doc__,
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )
    source = parser.add_mutually_exclusive_group(required=True)
    source.add_argument("--ref", help="the release tag to build from, v<X.Y.Z>; requires --out")
    source.add_argument("--verify-commit", metavar="REV", help="build any commit and discard the result")
    parser.add_argument("--out", help="directory to write the pack and manifest into (absent or empty)")
    parser.add_argument("--repo", default=REPO, help="the git repository to read (default: this checkout)")
    args = parser.parse_args(argv)
    if args.ref is not None and args.out is None:
        parser.error("--ref requires --out")
    if args.verify_commit is not None and args.out is not None:
        parser.error("--verify-commit writes nothing and takes no --out")

    try:
        if args.ref is not None:
            out = os.path.abspath(args.out)
            refuse_unusable_out(out)
            commit, bridge_release = resolve_release_tag(args.repo, args.ref)
        else:
            commit = commit_of(args.repo, args.verify_commit)
            if commit is None:
                raise Refused(f"--verify-commit {args.verify_commit!r} names no commit in {args.repo}")
            bridge_release = read_version_file(args.repo, commit)
        built = build(args.repo, commit, bridge_release)
    except (Refused, OSError) as exc:
        print(f"build-client-pack.py: refused: {exc}", file=sys.stderr)
        return 1

    summary = built["summary"]
    facts = (
        f"{built['file_count']} files, {summary['pack']['size']} bytes packed, "
        f"client {summary['client_version']}, files_json_sha256 {summary['files_json_sha256']}"
    )
    if args.ref is None:
        print(f"build-client-pack.py: {commit} builds ({facts}); verify-only, nothing written")
        return 0
    try:
        os.makedirs(out, exist_ok=True)
        write_atomically(os.path.join(out, built["pack_name"]), built["pack"])
        write_atomically(os.path.join(out, built["manifest_name"]), built["manifest"])
    except OSError as exc:
        print(f"build-client-pack.py: FAILED while writing to {out}: {exc}", file=sys.stderr)
        return 1
    print(f"build-client-pack.py: {built['pack_name']} built from {args.ref} ({commit}; {facts}) at {out}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
