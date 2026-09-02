#!/usr/bin/env python3
"""The bridge's DL-NNN allocator and its collision guard — one header grammar, two jobs.

Until card#7157 this repo had NO way to mint a DL number. The only in-repo
method was `max(^## DL-NNN) + 1` over this checkout's CLAUDE_DECISIONS.md, and
that method cannot see a mint that has not been pushed yet: two agents branching
off the same integration tip both read the same max and both write the same
number. Measured near-miss, 2026-08-21: two branches both read 290; the
collision was avoided because one agent's in-flight string happened to surface
in an unrelated test failure, not because anything checked.

    next   allocate a number from the board's authoritative counter, then VETO
           it against every local checkout this host can enumerate. What the
           counter does NOT cover — and it is not nothing — is stated on
           `cmd_next` and in DL-295's bounds; read it before trusting the word
           atomic.
    check  refuse a decision log that uses one number twice — the CI backstop.

Both read `## DL-NNN` headers through `header_numbers()`. A second spelling of
that grammar is how the guard and the allocator would come to disagree about
what an entry is, so there is exactly one.

Exit codes are the contract:
  0 ok
  2 usage, or an input that could not be read
  3 (next)  no allocator on PATH — REFUSED, see the `next` docstring
  4 (next)  the allocated number is already used in a local checkout
  5 (check) the log at head uses one number twice
  6 (check) a number this change adds is already in use on the target branch
"""

import argparse
import glob
import os
import re
import shutil
import subprocess
import sys
from pathlib import Path

EXIT_USAGE = 2
EXIT_NO_ALLOCATOR = 3
EXIT_LOCAL_COLLISION = 4
EXIT_DUPLICATE_AT_HEAD = 5
EXIT_ALREADY_ON_TARGET = 6

DECISION_LOG = "CLAUDE_DECISIONS.md"

# The bridge's DL sequence is PER-BOARD (board 8). `next-dl` is told which
# project's counter to allocate from; hardcoding it here is the point of this
# wrapper — a caller who has to remember the argument can forget it and mint
# out of the kanban-board sequence.
ALLOCATOR = "next-dl"
ALLOCATOR_PROJECT = "bridge"

# Checkouts to veto an allocation against, beyond this clone's own worktrees.
# Colon-separated globs; the default is the layout CLAUDE_DEPLOYMENT.md installs.
CHECKOUT_GLOBS_ENV = "BRIDGE_DL_CHECKOUT_GLOBS"
DEFAULT_CHECKOUT_GLOBS = "~/agent-webhook-bridge-*"

# A DL ENTRY is an H2 whose text starts `DL-` followed by digits. Deliberately
# not a search for `DL-\d+` anywhere: this log embeds kanban-board
# cross-references ("Mirrors kanban-board DL-196") and correlation-token prose,
# and a bare grep counts those as bridge entries — measured returning 197 when
# the real max was 188. Leading zeros are stripped by `int()` so the log's own
# two spellings (`## DL-031`, `## DL-291`) name the same number.
_HEADER = re.compile(r"^##[ \t]+DL-0*(\d+)\b")
_FENCE = re.compile(r"^\s*(```|~~~)")


def header_scan(text: str) -> tuple[list[tuple[int, int]], int]:
    """(every DL entry header outside a code fence, how many were SKIPPED inside one).

    Lines inside a fenced code block are skipped: a decision entry that shows
    what a header looks like is documentation, not a second entry, and treating
    it as one would refuse the very change that documents this file.

    The second element is the population statement, and it exists because the
    skip is unbounded in one direction: ONE unbalanced ``` or ~~~ swallows every
    later `## DL-` header in the file, and a `check` over the remainder still
    prints OK — a clean result over a population nobody named. It is reported
    rather than assumed away. Control on this repo's own log, 2026-08-21:
    fence-aware 181 == fence-blind 181, so nothing is skipped today; what makes
    the day it moves visible is that the run prints the delta, not that a number
    was written down here.
    """
    found: list[tuple[int, int]] = []
    skipped = 0
    fence: str | None = None
    for lineno, line in enumerate(text.splitlines(), start=1):
        m = _FENCE.match(line)
        if m:
            if fence is None:
                fence = m.group(1)
            elif line.strip().startswith(fence):
                fence = None
            continue
        m = _HEADER.match(line)
        if m is None:
            continue
        if fence is not None:
            skipped += 1
            continue
        found.append((int(m.group(1)), lineno))
    return found, skipped


def header_numbers(text: str) -> list[tuple[int, int]]:
    """`header_scan`'s entries alone, for the callers that do not report a population."""
    return header_scan(text)[0]


def _read(path: str, label: str) -> str:
    try:
        return Path(path).read_text(encoding="utf-8")
    except OSError as exc:
        print(f"decision-log: cannot read the {label} decision log {path}: {exc}", file=sys.stderr)
        raise SystemExit(EXIT_USAGE) from exc


def _scan(path: str, label: str) -> tuple[list[tuple[int, int]], int]:
    """`header_scan` over a file, stating on stderr what the fences hid from it."""
    entries, skipped = header_scan(_read(path, label))
    if skipped:
        print(
            f"decision-log: WARNING: {skipped} `## DL-` line(s) in the {label} log {path} sit "
            "inside a code fence and were NOT counted. That is correct for an entry quoted as an "
            "example, and WRONG if a fence is unbalanced — one unclosed ``` or ~~~ hides every "
            "later header, and this run's verdict would then cover a truncated population.",
            file=sys.stderr,
        )
    return entries, skipped


def _population(entries: list[tuple[int, int]], skipped: int) -> str:
    """The head log's denominator, printed beside every OK so it is never implicit."""
    return (
        f"population: {len(entries)} of {len(entries) + skipped} `## DL-` header lines counted, "
        f"{skipped} skipped inside code fences"
    )


def cmd_check(args: argparse.Namespace) -> int:
    """Refuse a decision log that uses one DL number twice.

    TWO assertions, and the SECOND is why `--target` exists:

      1. The log AT HEAD must not repeat a number. Because the log is
         append-only, a change that mints a number its own base already carries
         shows up here — the entry it collides with is still in the file.

      2. A number this change ADDS (present at head, absent at `--base`) must not
         already be in use at `--target`, the LIVE tip of the branch being merged
         into. Assertion 1 is blind to this: if the target branch gained the
         colliding entry after the `--base` snapshot was taken, the file at head
         does not contain it either.

    WHAT `--base` MUST BE — a PAIRING with `--head`, not the fork point. "Present
    at head, absent at base" means "this change minted it" only while head is a
    snapshot containing base plus this change and nothing else. The CI caller
    satisfies that by passing the FIRST PARENT of the merge commit it is running
    on — `HEAD^1`, read by `bin/pr-base-snapshot.sh` — while `--head` is the
    working tree at `github.sha`, that same merge. The two are ONE snapshot of the
    base branch by construction, because the base end is the commit the head end
    RECORDS as its parent; they must not be sourced independently.
    `.github/workflows/dl-collision-gate.yml` owns that invariant and the measured
    consequence of breaking it. Hand this command a base its head does not
    contain — the merge base, say — and `added` silently gains the base branch's
    OWN post-fork entries, which then read as this change's mints.

    ⛔ NOT `github.event.pull_request.base.sha`, which the CI caller passed until
    card#8527. GitHub does not refresh that field on every `synchronize`, so it
    can LAG the base tip the merge commit was built on: measured 2026-09-02 on
    PR #640 (run 33598959720), `base.sha` 549c894 against a merge commit whose
    first parent was 7a11085. Paired that way this command returned 6 and the gate
    refused an innocent PR, naming a number the base branch had minted itself.

    STATED BOUND — this is the whole of what the guard claims, and it is
    deliberately narrower than "no duplicate DL can be minted":

      * It does NOT catch two OPEN changes that each mint the same NEW number.
        Neither number is on the target branch yet and neither file contains the
        other's entry, so both pass. The first to merge stays green; the second
        reds only when it is re-checked AFTER that merge — which is a re-run, not
        an automatic consequence of the merge. A change last checked before the
        merge carries a stale green.
      * It reads three files. It knows nothing about a number claimed from the
        board counter but not yet written into any log, and nothing about a
        checkout on another host. `next` is what covers those; this is the
        backstop for when `next` was bypassed.
    """
    head_headers, head_skipped = _scan(args.head, "head")

    seen: dict[int, int] = {}
    for number, lineno in head_headers:
        if number in seen:
            print(
                f"decision-log: DL-{number} is used TWICE in {args.head} "
                f"(line {seen[number]} and line {lineno}).",
                file=sys.stderr,
            )
            return EXIT_DUPLICATE_AT_HEAD
        seen[number] = lineno

    if (args.base is None) != (args.target is None):
        # Half the inputs would run half the assertion and still print OK, which
        # is the "clean result over an unnamed population" shape this guard is
        # here to stop. Neither, or both.
        print(
            "decision-log: --base and --target are given together or not at all "
            "(one alone would report OK having skipped assertion 2).",
            file=sys.stderr,
        )
        return EXIT_USAGE

    if args.base is None:
        print(f"OK: {args.head} carries {len(head_headers)} DL entries, no number twice.")
        print(f"({_population(head_headers, head_skipped)})")
        print("(no --base/--target given — the target-branch assertion did not run)")
        return 0

    base_numbers = {n for n, _ in _scan(args.base, "base")[0]}
    target_numbers = {n for n, _ in _scan(args.target, "target")[0]}

    added = [(n, lineno) for n, lineno in head_headers if n not in base_numbers]
    collisions = [(n, lineno) for n, lineno in added if n in target_numbers]
    if collisions:
        for number, lineno in collisions:
            print(
                f"decision-log: DL-{number} (added at {args.head} line {lineno}) is "
                f"ALREADY IN USE on the target branch.",
                file=sys.stderr,
            )
        return EXIT_ALREADY_ON_TARGET

    added_desc = ", ".join(f"DL-{n}" for n, _ in added) if added else "none"
    print(
        f"OK: this change adds {len(added)} DL entries ({added_desc}); "
        "no number it adds is already in use on the target branch, and the log "
        "at head uses no number twice."
    )
    print(f"({_population(head_headers, head_skipped)})")
    return 0


def _worktree_dirs(repo_root: Path) -> tuple[list[Path], str | None]:
    """This clone's worktrees, via git. Returns (dirs, warning-if-unenumerable).

    The measured near-miss was two WORKTREES of one clone, which is exactly what
    a glob over sibling clone directories does not see.
    """
    try:
        out = subprocess.run(
            ["git", "-C", str(repo_root), "worktree", "list", "--porcelain"],
            capture_output=True,
            text=True,
            check=True,
        ).stdout
    except (OSError, subprocess.CalledProcessError) as exc:
        return [], f"could not enumerate this clone's worktrees ({exc}) — the veto below is NARROWER than it looks"
    return [Path(line[len("worktree ") :]) for line in out.splitlines() if line.startswith("worktree ")], None


def local_dl_headers(repo_root: Path) -> tuple[dict[int, list[str]], list[str]]:
    """Every DL number written into a decision log this host can reach.

    Returns (number -> the logs carrying it, warnings). The board counter cannot
    see any of these until they are pushed AND stamped, which is the hole this
    veto exists to cover.
    """
    dirs, warning = _worktree_dirs(repo_root)
    warnings = [warning] if warning else []

    raw = os.environ.get(CHECKOUT_GLOBS_ENV, DEFAULT_CHECKOUT_GLOBS)
    for pattern in raw.split(":"):
        pattern = pattern.strip()
        if pattern:
            dirs.extend(Path(p) for p in glob.glob(os.path.expanduser(pattern)))

    found: dict[int, list[str]] = {}
    seen_logs: set[str] = set()
    for directory in dirs:
        log = directory / DECISION_LOG
        try:
            resolved = str(log.resolve())
        except OSError:
            continue
        if resolved in seen_logs or not log.is_file():
            continue
        seen_logs.add(resolved)
        try:
            text = log.read_text(encoding="utf-8")
        except OSError as exc:
            warnings.append(f"could not read {log} ({exc}) — an in-flight mint there is INVISIBLE to this veto")
            continue
        for number, _ in header_numbers(text):
            found.setdefault(number, []).append(str(log))
    return found, warnings


def cmd_next(args: argparse.Namespace) -> int:
    """Allocate the next bridge DL number, then veto it against local checkouts.

    The number comes from the board's authoritative counter, reached through the
    toolkit's `next-dl` — the only source that knows a number CLAIMED but not yet
    written anywhere. When that allocator is absent this command REFUSES rather
    than falling back to a `max + 1` scan of the log: the offline scan is the
    method card#7157 indicts, and performing it under a tool's name would launder
    the same hazard, not fix it.

    ⛔ THE ATOMICITY IS THE ALLOCATOR'S AND IT IS CONDITIONAL — nothing here can
    see which arm answered. `next-dl` claims atomically from the board's
    DL-sequence endpoint when that endpoint answers, and falls back SILENTLY to
    its own offline `max + 1` scan when the claim route is absent (404) or
    unreachable: rc 0, a plausible number on stdout, an EMPTY stderr. Measured
    2026-08-21 against a stub board answering 404 on the claim route — `next-dl
    bridge` printed `DL-0294` at rc 0 with nothing on stderr, and this command
    printed `DL-294` as an allocation. Two agents on that path get one number,
    which is card#7157's defect wearing a tool's name. It is RECORDED, not
    cross-checked here: the toolkit owns the distinction (card#7214 — a strict
    mode that refuses when the counter did not answer), and a claim-vs-peek
    compare in this file would agree exactly when the hazard is present, because
    both routes degrade to the SAME offline scan. DL-295 bound (e) is the whole
    statement, with the measurements.

    The veto is the half the counter cannot do. A number written into a decision
    log on a branch that has not been pushed is invisible server-side, so an
    allocation is checked against every worktree of this clone and every checkout
    matched by BRIDGE_DL_CHECKOUT_GLOBS before it is handed back.
    """
    allocator = shutil.which(ALLOCATOR)
    if allocator is None:
        print(
            f"decision-log: REFUSING to mint — `{ALLOCATOR}` is not on PATH.\n"
            "The next bridge DL comes from the board's DL counter, which is the only\n"
            "source that can see a number claimed by another agent moments ago. THIS\n"
            "COMMAND has deliberately no offline fallback: `max(^## DL-NNN) + 1` over this\n"
            "checkout cannot see an unpushed parallel mint, which is the defect card#7157\n"
            f"filed. (`{ALLOCATOR}` itself HAS one and takes it silently when the board's\n"
            "claim endpoint does not answer — DL-295 bound (e), toolkit card#7214.)\n"
            f"Install the agent-board toolkit so `{ALLOCATOR}` resolves, then re-run.",
            file=sys.stderr,
        )
        return EXIT_NO_ALLOCATOR

    cmd = [allocator, ALLOCATOR_PROJECT] + (["--peek"] if args.peek else [])
    proc = subprocess.run(cmd, capture_output=True, text=True)
    if proc.stderr.strip():
        print(proc.stderr.rstrip(), file=sys.stderr)
    if proc.returncode != 0:
        print(
            f"decision-log: `{' '.join(cmd)}` exited {proc.returncode} — no number was allocated.",
            file=sys.stderr,
        )
        return EXIT_USAGE

    m = re.search(r"DL-0*(\d+)", proc.stdout)
    if m is None:
        print(
            f"decision-log: `{' '.join(cmd)}` printed no DL number: {proc.stdout.strip()!r}",
            file=sys.stderr,
        )
        return EXIT_USAGE
    number = int(m.group(1))

    local, warnings = local_dl_headers(Path(args.repo_root))
    for warning in warnings:
        print(f"decision-log: WARNING: {warning}", file=sys.stderr)

    if number in local:
        print(
            f"decision-log: REFUSING DL-{number} — it is already written into "
            + ", ".join(local[number])
            + ".\nThe board counter cannot see a log entry that has not been pushed and stamped.\n"
            "Push or stamp that entry, then re-run to allocate past it.",
            file=sys.stderr,
        )
        return EXIT_LOCAL_COLLISION

    if local:
        local_max = max(local)
        if local_max >= number:
            print(
                f"decision-log: WARNING: DL-{local_max} exists locally but the counter "
                f"allocated DL-{number} — the counter is BEHIND this host's logs, so a "
                "later allocation will hand back a number already in use here. DL-"
                f"{number} itself is free; this warns about the NEXT one.",
                file=sys.stderr,
            )

    print(f"DL-{number}")
    return 0


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(
        prog="decision-log.py",
        description="Allocate a bridge DL number, or refuse a decision log that reuses one.",
    )
    sub = parser.add_subparsers(dest="command", required=True)

    p_next = sub.add_parser("next", help="allocate the next bridge DL number")
    p_next.add_argument(
        "--peek",
        action="store_true",
        help="report the next number without reserving it (nothing is claimed)",
    )
    p_next.add_argument(
        "--repo-root",
        default=str(Path(__file__).resolve().parent.parent),
        help="the checkout whose worktrees are scanned (default: this script's repo)",
    )
    p_next.set_defaults(func=cmd_next)

    p_check = sub.add_parser("check", help="refuse a decision log that uses one number twice")
    p_check.add_argument("--head", required=True, help="the decision log as this change leaves it")
    p_check.add_argument(
        "--base",
        help="the decision log at the snapshot --head is the merge of; in CI the FIRST PARENT "
        "of that merge commit (bin/pr-base-snapshot.sh), NOT the merge base and NOT the "
        "pull_request event's base.sha (see the cmd_check docstring)",
    )
    p_check.add_argument("--target", help="the decision log at the live tip of the target branch")
    p_check.set_defaults(func=cmd_check)

    args = parser.parse_args(argv)
    return args.func(args)


if __name__ == "__main__":
    sys.exit(main())
