#!/usr/bin/env python3
"""Stage the bridge's seat tools, and the channel-server files, as a pack a seat installs from.

Run from a bridge checkout, ideally one pinned to the release the fleet should run:

    python3 bin/seat-pack.py --out <dir>                # copy shape (what a PM stages)
    python3 bin/seat-pack.py --out <dir> --shape link   # link shape (a seat with this checkout)

`docs/seat-tools.md` owns the install and upgrade procedure and the trust boundary; this
docstring owns only what the program writes.

THE POPULATION IS DECLARED, NEVER INFERRED. The seat tools are the entries of
`seat-tools.json` at the repo root, and nothing else under `bin/` is a seat tool. The
channel-server files are every TRACKED file under `examples/channel-servers/` outside
`node_modules/` — the shipped-file set `channel-server-supply-chain.yml`'s version-bump
guard reads — so `channel-lib.mjs` travels with the entry and cannot be cherry-picked away.
File CONTENT is read from the working tree, the same bytes a `cp -a` of the checkout copies;
`bridge_describe` carries `--dirty` so a pack staged from an edited tree says so. A
channel-server file must be tracked as a regular file (100644 or 100755): a tracked symlink
would stage its target's content under the link's name.

COPY SHAPE writes, under --out:
  seat-tools/bin/<tool>      each declared tool, mode 0755
  seat-tools/VERSION         this checkout's VERSION
  seat-tools/seat-pack.json  {schema, bridge_version, bridge_describe, tools: [{name, sha256}]}
  channel-setup/<file>       the channel-server files, 0755 where the index says 100755

LINK SHAPE writes ONLY seat-tools/bin/<tool>, each a symlink to this checkout's file. It
writes no VERSION and no seat-pack.json: a link resolves into the checkout, so the checkout
IS the version, and a metadata file beside it would be a second copy that `git pull` leaves
stale. It does not touch channel-setup/.

WHAT A RE-RUN REPLACES. Both directories are generated output. `seat-tools/` is replaced
whole, so a tool dropped from the manifest, or metadata from an earlier copy-shape run, does
not survive. A copy-shape run removes everything in `channel-setup/` except `node_modules/`
before writing, so a file the reference stops shipping does not survive either, while a
deployment running straight out of the staged directory keeps its installed dependencies.

NOTHING IS WRITTEN THROUGH A SYMLINK AT OR BELOW --out's `seat-tools/` OR `channel-setup/`.
Either directory being a symlink is refused, and so is a symlink on the path of a file the
pack writes under `channel-setup/`. A symlink inside `seat-tools/` goes with the directory it
is in; any other symlink the `channel-setup/` prune meets is unlinked, never followed. --out
itself may be a symlink: it names where the pack goes.

EVERY INPUT IS READ, AND EVERY DESTINATION CHECKED, BEFORE THE FIRST WRITE, so a REFUSAL
leaves --out as it found it. An error after the first write is not a refusal: it is reported
as FAILED while writing, and --out may then be partially updated until a re-run succeeds.

DETERMINISTIC: no timestamps, sorted entries, fixed modes. Two runs over one tree write
byte-identical output, so "is the staged pack current?" is "regenerate to a temp dir and
`diff -r -x node_modules`".

EXIT: 0 written · 1 refused before writing, or failed while writing; the reason on stderr · 2 usage.
"""

import argparse
import hashlib
import json
import os
import shutil
import stat
import subprocess
import sys
import tempfile

SCHEMA = 1
MANIFEST = "seat-tools.json"
CHANNEL_DIR = "examples/channel-servers/"
REGULAR_MODES = {"100644": 0o644, "100755": 0o755}
KEPT_IN_CHANNEL_SETUP = "node_modules"
REPO = os.path.dirname(os.path.dirname(os.path.realpath(__file__)))


class Refused(Exception):
    pass


def git(*args: str) -> str:
    proc = subprocess.run(["git", "-C", REPO, *args], capture_output=True, text=True)
    if proc.returncode != 0:
        raise Refused(f"git {' '.join(args)} exited {proc.returncode}: {proc.stderr.strip()}")
    return proc.stdout


def index_modes(pathspec: str) -> dict:
    """Tracked path -> index mode (e.g. '100755') for everything under pathspec."""
    modes = {}
    for record in git("ls-files", "-s", "-z", "--", pathspec).split("\0"):
        if record:
            meta, path = record.split("\t", 1)
            modes[path] = meta.split(" ", 1)[0]
    return modes


def declared_tools() -> list:
    try:
        with open(os.path.join(REPO, MANIFEST), encoding="utf-8") as fh:
            entries = json.load(fh)["tools"]
    except (OSError, ValueError, KeyError, TypeError) as exc:
        raise Refused(f"{MANIFEST} is unreadable or has no `tools` list: {exc}")
    if not isinstance(entries, list):
        raise Refused(f"{MANIFEST}: `tools` is not a list")
    if not entries:
        raise Refused(f"{MANIFEST} declares no tools; staging it would empty seat-tools/")

    tools = []
    for entry in entries:
        if not isinstance(entry, str) or os.path.dirname(entry) != "bin" or not os.path.basename(entry):
            raise Refused(f"{MANIFEST} entry {entry!r} is not a file directly under bin/")
        mode = index_modes(entry).get(entry)
        if mode != "100755":
            raise Refused(
                f"{MANIFEST} entry {entry} is {'not tracked' if mode is None else 'mode ' + mode + ' in the git index'}; "
                "a seat tool must be tracked at 100755, or its install exits 126"
            )
        tools.append(entry)

    names = [os.path.basename(t) for t in tools]
    if len(set(names)) != len(names):
        raise Refused(f"{MANIFEST} declares two tools with one install name: {sorted(names)}")
    return sorted(tools, key=os.path.basename)


def channel_files() -> list:
    """(tracked path, path under channel-setup/, staged mode) for each shipped channel-server file."""
    shipped = []
    for path, mode in sorted(index_modes(CHANNEL_DIR).items()):
        if "/node_modules/" in path:
            continue
        if mode not in REGULAR_MODES:
            raise Refused(
                f"{path} is mode {mode} in the git index; a channel-server file must be tracked as a "
                "regular file (100644 or 100755), or the pack would stage something other than it"
            )
        shipped.append((path, path[len(CHANNEL_DIR):], REGULAR_MODES[mode]))
    return shipped


def refuse_unless_directory_or_absent(path: str) -> None:
    try:
        mode = os.lstat(path).st_mode
    except FileNotFoundError:
        return
    if stat.S_ISLNK(mode):
        raise Refused(f"{path} is a symlink; the pack is never written through one")
    if not stat.S_ISDIR(mode):
        raise Refused(f"{path} exists and is not a directory")


def refuse_symlinks_on_the_way_to(root: str, relatives: list) -> None:
    for relative in relatives:
        parts = relative.split("/")
        for depth in range(1, len(parts) + 1):
            candidate = os.path.join(root, *parts[:depth])
            if os.path.islink(candidate):
                raise Refused(f"{candidate} is a symlink; the pack is never written through one")


def prune_except_node_modules(directory: str) -> None:
    if not os.path.isdir(directory):
        return
    for name in sorted(os.listdir(directory)):
        if name == KEPT_IN_CHANNEL_SETUP:
            continue
        path = os.path.join(directory, name)
        if stat.S_ISDIR(os.lstat(path).st_mode):
            shutil.rmtree(path)
        else:
            os.unlink(path)


def read_bytes(relative: str) -> bytes:
    with open(os.path.join(REPO, relative), "rb") as fh:
        return fh.read()


def write_file(path: str, data: bytes, mode: int) -> None:
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "wb") as fh:
        fh.write(data)
    os.chmod(path, mode)


def replace_dir(built: str, destination: str) -> None:
    if os.path.lexists(destination):
        shutil.rmtree(destination)
    os.rename(built, destination)


def prepare(out: str, shape: str) -> dict:
    """Every read and every refusal. Nothing under --out is written here."""
    plan = {"tools": declared_tools()}
    refuse_unless_directory_or_absent(os.path.join(out, "seat-tools"))

    if shape == "copy":
        channel = channel_files()
        channel_dest = os.path.join(out, "channel-setup")
        refuse_unless_directory_or_absent(channel_dest)
        refuse_symlinks_on_the_way_to(channel_dest, [staged for _, staged, _ in channel])
        version = read_bytes("VERSION")
        try:
            version_text = version.decode("utf-8").strip()
        except UnicodeDecodeError as exc:
            raise Refused(f"VERSION is not UTF-8: {exc}")
        plan.update(
            tool_bytes={tool: read_bytes(tool) for tool in plan["tools"]},
            version=version,
            version_text=version_text,
            describe=git("describe", "--tags", "--always", "--dirty").strip(),
            channel_bytes=[(staged, read_bytes(path), mode) for path, staged, mode in channel],
        )
    return plan


def write(out: str, shape: str, plan: dict) -> str:
    tools = plan["tools"]
    seat_tools_dest = os.path.join(out, "seat-tools")
    channel_dest = os.path.join(out, "channel-setup")
    os.makedirs(out, exist_ok=True)
    build = tempfile.mkdtemp(prefix=".seat-pack-", dir=out)
    try:
        seat_tools = os.path.join(build, "seat-tools")
        records = []
        for tool in tools:
            name = os.path.basename(tool)
            target = os.path.join(seat_tools, "bin", name)
            if shape == "link":
                os.makedirs(os.path.dirname(target), exist_ok=True)
                os.symlink(os.path.join(REPO, tool), target)
            else:
                data = plan["tool_bytes"][tool]
                write_file(target, data, 0o755)
                records.append({"name": name, "sha256": hashlib.sha256(data).hexdigest()})

        if shape == "copy":
            write_file(os.path.join(seat_tools, "VERSION"), plan["version"], 0o644)
            meta = {
                "schema": SCHEMA,
                "bridge_version": plan["version_text"],
                "bridge_describe": plan["describe"],
                "tools": records,
            }
            write_file(
                os.path.join(seat_tools, "seat-pack.json"),
                (json.dumps(meta, indent=2) + "\n").encode("utf-8"),
                0o644,
            )

            prune_except_node_modules(channel_dest)
            for staged, data, mode in plan["channel_bytes"]:
                write_file(os.path.join(channel_dest, staged), data, mode)
            summary = f"seat-tools/ ({len(tools)} tool(s)) and channel-setup/ ({len(plan['channel_bytes'])} file(s))"
        else:
            summary = f"seat-tools/bin/ ({len(tools)} link(s) into {REPO})"

        replace_dir(seat_tools, seat_tools_dest)
        return summary
    finally:
        shutil.rmtree(build, ignore_errors=True)


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(
        prog="seat-pack.py",
        description=__doc__,
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )
    parser.add_argument("--out", required=True, help="directory to stage into (created if absent)")
    parser.add_argument(
        "--shape",
        choices=("copy", "link"),
        default="copy",
        help="copy (default): files + VERSION + seat-pack.json + channel-setup/; "
        "link: seat-tools/bin/ symlinks into this checkout, nothing else",
    )
    args = parser.parse_args(argv)
    out = os.path.abspath(args.out)
    try:
        plan = prepare(out, args.shape)
    except (Refused, OSError) as exc:
        print(f"seat-pack.py: refused: {exc}", file=sys.stderr)
        return 1
    try:
        summary = write(out, args.shape, plan)
    except OSError as exc:
        print(f"seat-pack.py: FAILED while writing (--out may be partially updated; re-run): {exc}", file=sys.stderr)
        return 1
    print(f"seat-pack.py: {args.shape} shape staged at {out}: {summary}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
