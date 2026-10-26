#!/usr/bin/env python3
"""The bridge's DL-NNN allocator — `next`, with a veto over every local decision log.

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

THE CI BACKSTOP AND THE HEADER GRAMMAR ARE THE TOOLKIT'S (card#12338).
`.github/workflows/dl-collision-gate.yml` runs agent-board-toolkit's
`dl-collision-gate` action, and the veto here reads headers through that
toolkit's `bin/_decision-log.py`, path-loaded from beside the `next-dl` this
command allocates with. One grammar, owned by one file: a second spelling of it
is how the gate and the allocator would come to disagree about what an entry is.
The import contract (`header_scan`'s return shape) is declared in that file's
docstring; nothing here restates it.

Exit codes are the contract:
  0 ok
  2 usage, or the allocator failed or printed no number
  3 no allocator on PATH, or no `_decision-log.py` beside it — REFUSED, see `cmd_next`
  4 the allocated number is already used in a local checkout
"""

import argparse
import glob
import importlib.util
import os
import re
import shutil
import subprocess
import sys
from pathlib import Path
from types import ModuleType

# Path-loading the grammar caches bytecode beside its TARGET — the toolkit's
# bin/, which hosts glob onto PATH (toolkit card#6871). The flag only governs
# loads made after it is set, so the entry point sets it.
sys.dont_write_bytecode = True

EXIT_USAGE = 2
EXIT_NO_ALLOCATOR = 3
EXIT_LOCAL_COLLISION = 4

DECISION_LOG = "CLAUDE_DECISIONS.md"

# The bridge's DL sequence is PER-BOARD (board 8). `next-dl` is told which
# project's counter to allocate from; hardcoding it here is the point of this
# wrapper — a caller who has to remember the argument can forget it and mint
# out of the kanban-board sequence.
ALLOCATOR = "next-dl"
ALLOCATOR_PROJECT = "bridge"

# The toolkit file that owns the `## DL-NNN` grammar, a sibling of `next-dl` in
# the toolkit's bin/ since v0.51.0.
GRAMMAR = "_decision-log.py"

# Checkouts to veto an allocation against, beyond this clone's own worktrees.
# Colon-separated globs; the default is the layout CLAUDE_DEPLOYMENT.md installs.
CHECKOUT_GLOBS_ENV = "BRIDGE_DL_CHECKOUT_GLOBS"
DEFAULT_CHECKOUT_GLOBS = "~/agent-webhook-bridge-*"


def load_grammar(allocator: str) -> tuple[ModuleType | None, Path]:
    """The toolkit grammar beside `allocator`, resolved through its symlink, and where it was looked for.

    A PATH install links each toolkit tool into a directory such as
    `~/.local/bin`, so the sibling is looked for in the directory the link
    RESOLVES to: the toolkit checkout the allocator itself runs from, so the
    grammar and the allocator come from the same checkout.
    """
    path = Path(allocator).resolve().parent / GRAMMAR
    if not path.is_file():
        return None, path
    spec = importlib.util.spec_from_file_location("abtk_decision_log", path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module, path


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


def local_dl_headers(repo_root: Path, grammar: ModuleType) -> tuple[dict[int, list[str]], list[str]]:
    """Every DL number written into a decision log this host can reach, read by the toolkit `grammar`.

    Returns (number -> the logs carrying it, warnings). The board counter cannot
    see any of these until they are pushed AND stamped, which is the hole this
    veto exists to cover. A log left inside an unclosed code fence hides every
    heading after the fence from the grammar, so it is named in a warning rather
    than vetoed over silently.
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
        entries, _, _, unclosed_fence = grammar.header_scan(text)
        if unclosed_fence is not None:
            warnings.append(
                f"{log} opens a code fence at line {unclosed_fence} that is never closed — a DL "
                "heading after that line is INVISIBLE to this veto"
            )
        for number, _ in entries:
            found.setdefault(number, []).append(str(log))
    return found, warnings


def cmd_next(args: argparse.Namespace) -> int:
    """Allocate the next bridge DL number, then veto it against local checkouts.

    The number comes from the board's authoritative counter, reached through the
    toolkit's `next-dl` — the only source that knows a number CLAIMED but not yet
    written anywhere. When that allocator is absent this command REFUSES rather
    than falling back to a `max + 1` scan of the log: the offline scan is the
    method card#7157 indicts, and performing it under a tool's name would launder
    the same hazard, not fix it. It refuses the same way when the toolkit's
    `_decision-log.py` is not beside the allocator (a toolkit older than v0.51.0),
    and BEFORE the allocator runs: a claim reserves a number, so refusing after it
    would burn one for nothing.

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

    grammar, grammar_path = load_grammar(allocator)
    if grammar is None:
        print(
            f"decision-log: REFUSING to mint — `{ALLOCATOR}` resolves to {Path(allocator).resolve()},\n"
            f"but there is no {grammar_path}. The veto reads `## DL-NNN` headers through the\n"
            "agent-board toolkit's own grammar (v0.51.0 or later), so that the CI gate and this\n"
            "allocator agree on what an entry is. Upgrade the toolkit, re-run its PATH symlink\n"
            "loop (the toolkit's docs/INSTALL.md section 2), then re-run.",
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

    local, warnings = local_dl_headers(Path(args.repo_root), grammar)
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
        description="Allocate a bridge DL number and veto it against local decision logs.",
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

    args = parser.parse_args(argv)
    return args.func(args)


if __name__ == "__main__":
    sys.exit(main())
