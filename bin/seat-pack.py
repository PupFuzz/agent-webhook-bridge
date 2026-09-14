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
`bridge_describe` carries `--dirty` so a pack staged from an edited tree says so.

COPY SHAPE writes, under --out:
  seat-tools/bin/<tool>      each declared tool, mode 0755
  seat-tools/VERSION         this checkout's VERSION
  seat-tools/seat-pack.json  {schema, bridge_version, bridge_describe, tools: [{name, sha256}]}
  channel-setup/<file>       the channel-server files, 0755 where the index says 100755

LINK SHAPE writes ONLY seat-tools/bin/<tool>, each a symlink to this checkout's file. It
writes no VERSION and no seat-pack.json: a link resolves into the checkout, so the checkout
IS the version, and a metadata file beside it would be a second copy that `git pull` leaves
stale.

WHAT A RE-RUN REPLACES. `seat-tools/` belongs to the pack and is replaced whole, so a tool
dropped from the manifest, or metadata from an earlier copy-shape run, does not survive a
re-stage. `channel-setup/` is written OVER, the way `cp -a examples/channel-servers/.` writes
over a snapshot, and nothing in it is deleted: a deployment that runs straight out of the
staged directory keeps its `node_modules/`, and the cost is that a file the reference stops
shipping is not removed.

DETERMINISTIC: no timestamps, sorted entries, fixed modes. Two runs over one tree write
byte-identical output, so "is the staged pack current?" is "regenerate to a temp dir and
`diff -r`".

EXIT: 0 written · 1 refused (a manifest entry that is not a tracked 100755 `bin/` file, or a
git failure), with the reason on stderr · 2 usage.
"""

import argparse
import hashlib
import json
import os
import shutil
import subprocess
import sys
import tempfile

SCHEMA = 1
MANIFEST = "seat-tools.json"
CHANNEL_DIR = "examples/channel-servers/"
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


def stage(out: str, shape: str) -> str:
    tools = declared_tools()
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
                data = read_bytes(tool)
                write_file(target, data, 0o755)
                records.append({"name": name, "sha256": hashlib.sha256(data).hexdigest()})

        if shape == "copy":
            version = read_bytes("VERSION")
            write_file(os.path.join(seat_tools, "VERSION"), version, 0o644)
            meta = {
                "schema": SCHEMA,
                "bridge_version": version.decode("utf-8").strip(),
                "bridge_describe": git("describe", "--tags", "--always", "--dirty").strip(),
                "tools": records,
            }
            write_file(
                os.path.join(seat_tools, "seat-pack.json"),
                (json.dumps(meta, indent=2) + "\n").encode("utf-8"),
                0o644,
            )

            channel = index_modes(CHANNEL_DIR)
            shipped = sorted(p for p in channel if "/node_modules/" not in p)
            for path in shipped:
                write_file(
                    os.path.join(out, "channel-setup", path[len(CHANNEL_DIR):]),
                    read_bytes(path),
                    0o755 if channel[path] == "100755" else 0o644,
                )
            summary = f"seat-tools/ ({len(tools)} tool(s)) and channel-setup/ ({len(shipped)} file(s))"
        else:
            summary = f"seat-tools/bin/ ({len(tools)} link(s) into {REPO})"

        replace_dir(seat_tools, os.path.join(out, "seat-tools"))
        return summary
    finally:
        shutil.rmtree(build, ignore_errors=True)


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(
        prog="seat-pack.py",
        description="Stage the tools declared in seat-tools.json (and, in the copy shape, the "
        "channel-server files) under --out. docs/seat-tools.md owns how a seat installs them.",
        epilog="exit 0 = written; 1 = refused, reason on stderr; 2 = usage.",
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
        summary = stage(out, args.shape)
    except Refused as exc:
        print(f"seat-pack.py: refused: {exc}", file=sys.stderr)
        return 1
    print(f"seat-pack.py: {args.shape} shape staged at {out}: {summary}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
