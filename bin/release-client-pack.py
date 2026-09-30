#!/usr/bin/env python3
"""Attach a bridge release's channel-server client pack to its GitHub release (card#10567 B2).

Run by `.github/workflows/auto-tag-version.yml` AFTER the release exists, for the tag it just
made. It builds the pack with `bin/build-client-pack.py --ref <tag>` and attaches the three assets
`bridge:client-pack:install` reads (DL-430 Decision 2): the pack, its manifest and `SHA256SUMS`.

    release-client-pack.py --tag v<X.Y.Z> --work <empty or absent dir>

THE RELEASE IS JUDGED BY WHAT IT CARRIES, READ FROM GITHUB, before and after:
  * all three assets        -> nothing to do (a re-run of a run that attached them).
  * none of them            -> build, write SHA256SUMS, upload all three.
  * some but not all        -> REFUSED: `bridge:client-pack:install` refuses a partial set, and
                               rebuilding one missing asset could give bytes that do not match
                               the ones attached. The operator removes the partial assets and
                               re-runs (the message says how). Exit 1.

FAIL-SOFT, ON PURPOSE (operator ruling 8, card#10567 comment 6757, the card#5910 doctrine this
workflow already follows): a release whose pack could not be built or attached still ships. It
ends with NONE of the three assets and its notes open with NO_PACK_NOTE, the run prints an
`::error::` annotation, and this exits 0. Installs keep serving the pack they already publish,
and `bridge:check`'s `board_tools.client_pack_source` warns on each of them. Re-running that
workflow run once the build passes attaches the pack and removes the note.

Exit: 0 attached, already attached, or none attached (fail-soft) · 1 a partial set is on the
release · 2 usage, or the release could not be read.
"""

import argparse
import hashlib
import json
import os
import re
import subprocess
import sys

_HERE = os.path.dirname(os.path.abspath(__file__))

TAG = re.compile(r"\Av(\d+\.\d+\.\d+)\Z")

SUMS_ASSET = "SHA256SUMS"

# The `name:` of .github/workflows/auto-tag-version.yml. test_release_client_pack.py holds the two
# equal; the PHP leg's copy (`ClientPackSourceCheck::RELEASE_WORKFLOW`) is held by its own test.
RELEASE_WORKFLOW = "Auto-tag + GitHub Release on merge to main"

NO_PACK_NOTE = (
    "**No client pack for this release.** Its release-time build did not attach one, so every "
    "install keeps serving the client pack it already publishes, and `bridge:client-pack:install` "
    "answers that this release carries none. Re-running this release's "
    f"`{RELEASE_WORKFLOW}` workflow run attaches it once the build passes."
)


def asset_names(release: str) -> list:
    return [f"client-pack-v{release}.tar.gz", f"client-pack-v{release}.manifest.json", SUMS_ASSET]


def annotate(kind: str, message: str) -> None:
    print(f"::{kind}::{message}", flush=True)


def gh(*args, capture: bool = True) -> subprocess.CompletedProcess:
    return subprocess.run(["gh", *args], capture_output=capture, text=True)


def read_release(tag: str):
    """(asset names, body), or None when the release could not be read."""
    r = gh("release", "view", tag, "--json", "assets,body")
    if r.returncode != 0:
        print(r.stderr, file=sys.stderr, end="")
        return None
    try:
        doc = json.loads(r.stdout)
        return {a["name"] for a in doc["assets"]}, doc.get("body") or ""
    except (ValueError, KeyError, TypeError) as e:
        print(f"release-client-pack: `gh release view {tag}` answered something that is not a release ({e})", file=sys.stderr)
        return None


def set_notes(tag: str, body: str, work: str) -> bool:
    path = os.path.join(work, "release-notes.md")
    with open(path, "w", encoding="utf-8") as f:
        f.write(body)
    r = gh("release", "edit", tag, "--notes-file", path)
    if r.returncode != 0:
        print(r.stderr, file=sys.stderr, end="")
        annotate("error", f"could not update the notes of release {tag}; edit them by hand (see the log above)")
        return False
    return True


def with_note(body: str) -> str:
    return body if body.startswith(NO_PACK_NOTE) else f"{NO_PACK_NOTE}\n\n{body}"


def without_note(body: str) -> str:
    return body[len(NO_PACK_NOTE):].lstrip("\n") if body.startswith(NO_PACK_NOTE) else body


def build(tag: str, out: str) -> bool:
    builder = os.environ.get("RELEASE_CLIENT_PACK_BUILDER", os.path.join(_HERE, "build-client-pack.py"))
    r = subprocess.run([sys.executable, builder, "--ref", tag, "--out", out])
    if r.returncode != 0:
        annotate("error", f"the client pack for {tag} did not build (bin/build-client-pack.py exited {r.returncode}; its output is above). The release ships without a client pack.")
        return False
    return True


def write_sums(out: str, names: list) -> None:
    lines = []
    for name in names[:2]:
        with open(os.path.join(out, name), "rb") as f:
            lines.append(f"{hashlib.sha256(f.read()).hexdigest()}  {name}\n")
    with open(os.path.join(out, SUMS_ASSET), "w", encoding="ascii") as f:
        f.writelines(lines)


def partial(tag: str, present: set, names: list) -> int:
    missing = [n for n in names if n not in present]
    annotate(
        "error",
        f"release {tag} carries only part of its client pack (missing: {', '.join(missing)}). "
        "A pack is published from all three assets or none, so bridge:client-pack:install refuses it. "
        f"Remove the ones present (gh release delete-asset {tag} <name>) and re-run this workflow run.",
    )
    return 1


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    parser.add_argument("--tag", required=True, help="the release tag, v<X.Y.Z>")
    parser.add_argument("--work", required=True, help="a directory to build into (absent or empty)")
    args = parser.parse_args(argv)

    m = TAG.match(args.tag)
    if m is None:
        parser.error(f"--tag {args.tag!r} is not v<X.Y.Z>")
    names = asset_names(m.group(1))

    before = read_release(args.tag)
    if before is None:
        annotate("error", f"could not read release {args.tag}, so its client pack was not attached")
        return 2
    present, body = before

    if present.issuperset(names):
        print(f"release-client-pack: release {args.tag} already carries its client pack; nothing to attach.")
    elif present & set(names):
        return partial(args.tag, present, names)
    else:
        os.makedirs(args.work, exist_ok=True)
        out = os.path.join(args.work, "pack")
        if build(args.tag, out):
            write_sums(out, names)
            r = gh("release", "upload", args.tag, *[os.path.join(out, n) for n in names], capture=False)
            if r.returncode != 0:
                annotate("error", f"uploading the client pack to release {args.tag} failed (gh exited {r.returncode}; its output is above).")

    after = read_release(args.tag)
    if after is None:
        annotate("error", f"could not read release {args.tag} back, so whether it carries its client pack is unknown")
        return 2
    present, body = after
    os.makedirs(args.work, exist_ok=True)

    if present.issuperset(names):
        if body.startswith(NO_PACK_NOTE):
            set_notes(args.tag, without_note(body), args.work)
        print(f"release-client-pack: release {args.tag} carries its client pack ({', '.join(names)}).")
        return 0
    if present & set(names):
        return partial(args.tag, present, names)

    if not body.startswith(NO_PACK_NOTE):
        set_notes(args.tag, with_note(body), args.work)
    annotate("error", f"release {args.tag} ships WITHOUT a client pack; installs keep the pack they publish until a re-run of this workflow run attaches it.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
