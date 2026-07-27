#!/usr/bin/env python3
"""Will the deployed channel server LAUNCH at the next session start? (card 5143)

Run this ON THE SEAT, AS THE OS USER whose Claude Code session launches the channel
server, pointed at that seat's deployed channel-server directory:

    python3 check-channel-snapshot.py ~/agent-webhook-bridge-channel

It answers ONE question, by MEASUREMENT rather than by inspection: it spawns
`node <deployed>/agent-webhook-bridge-channel.mjs` with stdin CLOSED and reads the
child's EXIT CODE. A missing sibling module, an unresolvable bare import, a config
refusal — every one of them is a non-zero exit, and the child's own stderr (e.g.
`ERR_MODULE_NOT_FOUND: .../channel-lib.mjs`) IS the operator's diagnosis.

WHY THIS IS A SEAT-SIDE TOOL AND NOT A `bridge:check` LEG (DL-237). The bridge
commonly runs as a DIFFERENT OS user than the agent, with the deployment `0700` to
that user. Launching the entry as the BRIDGE's user would prove the entry loads for
the bridge user — a different PATH, a different `node`, different read access — which
is a proxy for the question again, and "assert the thing, not a proxy" is the entire
argument for launching instead of enumerating files. So `bridge:check` deliberately
never executes node; this program is what the seat runs on its own box.

ZERO REFERENCE ACCESS. No version, no file list, no bridge config, no bridge
checkout — copy this one file to a seat that has none of those and it still works.

EXIT CODES
  0  LAUNCH OK. The module graph resolved and the process came up and exited
     cleanly. Bounded claim: it says nothing about STEADY STATE (whether the server
     stays healthy under traffic), and nothing about whether the snapshot is STALE
     — staleness is `bridge:check`'s version leg, and a seat whose deployment the
     bridge cannot traverse gets no staleness verdict from anywhere today.
  1  LAUNCH FAILED. Conclusive: this deployment will not come up at the next
     session start. The child's stderr is printed verbatim.
  2  COULD NOT CHECK. NOT a verdict in either direction. Covers: no `node` on PATH
     (an environment problem, not a snapshot problem); the path is missing, or not
     traversable by this user; the throwaway-socket guard refused; the backstop
     timeout fired.

TWO BUILD REQUIREMENTS, both about not BREAKING the thing being diagnosed.

(a) THE SOCKET IS A THROWAWAY, AND EXPLICITLY SO. In the entry,
    `SOCKET_PATH = process.env.BRIDGE_CHANNEL_SOCKET || defaultSocketPath()`, and
    `BRIDGE_CHANNEL_NAME` / `XDG_RUNTIME_DIR` feed ONLY `defaultSocketPath()`. Every
    seat exports `BRIDGE_CHANNEL_SOCKET` into its own environment, so it WINS:
    setting a throwaway NAME leaves the server reaching for the LIVE socket, and it
    declines to bind only while the live socket file happens to exist. This assert
    runs BEFORE a session starts — precisely when nothing holds that path — so a
    naive version would bind the live socket and `unlinkSync` it on the way out: a
    diagnostic that breaks live-wake. Therefore the socket path is SET EXPLICITLY in
    the child env, generated inside a private `mkdtemp()` this run creates (so it
    cannot collide with any configured socket by construction), and the run REFUSES
    rather than launching if that path somehow already exists — a path this run did
    not create is never unlinked. `BRIDGE_CHANNEL_PORT=0` gets the same treatment for
    the `http` transport (an ephemeral port, never the seat's configured one), and
    `XDG_RUNTIME_DIR` is pointed at the same temp dir so a `.FAILED` marker the child
    writes lands there instead of beside the live one.

(b) THE VERDICT IS THE EXIT CODE, NEVER "SURVIVED THE TIMEOUT". The lifecycle is
    stdin-driven: `process.stdin.on('end', () => shutdown(0))`. With stdin CLOSED the
    server binds, prints its listening line and exits 0 IMMEDIATELY; with stdin held
    OPEN it is still running at 5s and a survival-based assert would call a HEALTHY
    snapshot a failure (or, worse, call a killed process a pass). So stdin is closed
    and the exit code is read. The timeout is a BACKSTOP only, and a timeout is
    INCONCLUSIVE (exit 2) — never a pass.
"""

from __future__ import annotations

import argparse
import os
import shutil
import subprocess
import sys
import tempfile

ENTRY_FILE = "agent-webhook-bridge-channel.mjs"

# The basename of the throwaway socket, inside the private temp dir this run mints.
SOCKET_BASENAME = "launch-assert.sock"

# Backstop only — see (b). Generous: a cold box loading the MCP SDK is still well
# under this, and the number is not a measurement of anything.
DEFAULT_TIMEOUT_SECONDS = 15

# The child's own listening line. Used ONLY to sharpen the wording of a PASS (was
# the bind observed, or did stdin EOF close the process first?) — never to decide
# the verdict, so a wording change upstream can soften this message but can never
# make it report the wrong result.
LISTENING_MARKER = " listening on "

EXIT_LAUNCH_OK = 0
EXIT_LAUNCH_FAILED = 1
EXIT_COULD_NOT_CHECK = 2


def throwaway_socket_path(tmpdir: str) -> str:
    return os.path.join(tmpdir, SOCKET_BASENAME)


def child_env(parent_env, socket_path: str, runtime_dir: str) -> dict:
    """The child's environment: the parent's, with every endpoint the server could
    reach for OVERRIDDEN to something this run owns.

    All three are assignments, never `setdefault` — the seat exports its real values
    and they must LOSE. Dropping any one of them puts a live endpoint back in reach:
    `BRIDGE_CHANNEL_SOCKET` is the unix path the server binds and unlinks,
    `BRIDGE_CHANNEL_PORT` is the loopback port it binds under
    `BRIDGE_CHANNEL_TRANSPORT=http`, and `XDG_RUNTIME_DIR` is where a `.FAILED`
    marker lands (and the only input to the default socket path, which is then moot).
    """
    env = dict(parent_env)
    env["BRIDGE_CHANNEL_SOCKET"] = socket_path
    env["BRIDGE_CHANNEL_PORT"] = "0"
    env["XDG_RUNTIME_DIR"] = runtime_dir
    return env


def bind_observed(stderr: str) -> bool:
    return LISTENING_MARKER in stderr


def _nearest_existing_ancestor(path: str) -> str:
    d = os.path.dirname(path)
    while not os.path.isdir(d):
        parent = os.path.dirname(d)
        if parent == d:
            return d
        d = parent
    return d


def resolve_entry(path: str):
    """Where is the entry to launch — and are we entitled to say it is not there?

    Returns `(entry_path, None, None)` on success, else `(None, exit_code, message)`.

    `os.path.isfile()` is false for a permission denial exactly as it is for a
    removed file, so "absent" is only a conclusion once `+x` on the containing
    directory is PROVEN. `+r` on the directory is deliberately not required: a 0711
    deployment answers stat on its children fine, and node opens the entry by path.
    """
    path = os.path.abspath(os.path.expanduser(path))

    if os.path.isdir(path):
        directory = path
    elif os.path.isfile(path) and os.path.basename(path) == ENTRY_FILE:
        # The `channel.server_path` ergonomic: the entry `.mjs` names its directory.
        directory = os.path.dirname(path)
    elif os.path.exists(path):
        return (
            None,
            EXIT_COULD_NOT_CHECK,
            f"COULD NOT CHECK: {path} is not a channel-server directory (it names a file) — "
            f"point this at the DEPLOYED DIRECTORY, or at its {ENTRY_FILE} entry. Nothing "
            f"was measured.",
        )
    elif not os.access(_nearest_existing_ancestor(path), os.X_OK):
        return (
            None,
            EXIT_COULD_NOT_CHECK,
            f"COULD NOT CHECK: {path} is not visible to this user — a directory above it "
            f"denies traversal, so whether it exists is a question this user cannot answer. "
            f"Nothing was measured. Re-run as the OS user whose session launches the "
            f"channel server.",
        )
    else:
        return (
            None,
            EXIT_COULD_NOT_CHECK,
            f"COULD NOT CHECK: {path} does not exist. Nothing was measured — point this at "
            f"the deployed channel-server directory.",
        )

    if not os.access(directory, os.X_OK):
        return (
            None,
            EXIT_COULD_NOT_CHECK,
            f"COULD NOT CHECK: {directory} is not traversable by this user, so nothing inside "
            f"it can be stat'ed or launched. Nothing was measured. Re-run as the OS user whose "
            f"session launches the channel server, or grant that user traversal.",
        )

    entry = os.path.join(directory, ENTRY_FILE)
    if not os.path.isfile(entry):
        # Conclusive: the directory was just PROVEN traversable, so this absence is
        # real, and there is nothing here for the MCP client to launch.
        return (
            None,
            EXIT_LAUNCH_FAILED,
            f"LAUNCH FAILED: {entry} does not exist — this is not a channel-server deployment, "
            f"so there is nothing to launch at the next session start.",
        )

    return (entry, None, None)


def _pass_message(entry: str, stderr: str) -> str:
    what = (
        "the module graph resolved and the listener bound"
        if bind_observed(stderr)
        else "the module graph resolved and the process exited cleanly (stdin EOF closed it "
        "before a listening line was seen, so the bind itself was not observed)"
    )

    return (
        f"LAUNCH OK: {entry} came up under this user's node and exited 0 — {what}.\n"
        "  Bounded claim: this is a launch, not a soak. It says nothing about STEADY STATE, "
        "and nothing about whether this snapshot is STALE — staleness is `bridge:check`'s "
        "version leg, and a deployment the bridge process cannot traverse gets no staleness "
        "verdict from anywhere today."
    )


def run_launch_assert(
    path: str,
    *,
    live_socket=None,
    timeout: float = DEFAULT_TIMEOUT_SECONDS,
    env=None,
    mkdtemp=tempfile.mkdtemp,
    out=None,
) -> int:
    out = sys.stdout if out is None else out
    env = dict(os.environ) if env is None else dict(env)

    node = shutil.which("node", path=env.get("PATH", ""))
    if node is None:
        # An environment problem, not a verdict about the deployment — the snapshot
        # may be perfect. Kept distinct from exit 1 for exactly that reason.
        print(
            "COULD NOT CHECK: no `node` on this user's PATH, so nothing was launched and "
            "nothing was measured. Install Node (20+) or fix PATH, then re-run — under an "
            "interactive-only PATH (nvm and friends) run this from the same shell the session "
            "starts from.",
            file=out,
        )
        return EXIT_COULD_NOT_CHECK

    entry, code, message = resolve_entry(path)
    if entry is None:
        print(message, file=out)
        return code

    tmpdir = mkdtemp(prefix="channel-launch-assert-")
    socket_path = throwaway_socket_path(tmpdir)

    # THE TWO REFUSALS. Both are unreachable while the mkdtemp construction above
    # holds — which is the point of asserting them: the child UNLINKS the socket it
    # bound, so the one thing this tool must never get wrong is which path that is.
    # Neither refusal removes anything, including the temp dir: if the construction
    # did not hold, this run does not know what it is looking at and touches nothing.
    if os.path.lexists(socket_path):
        print(
            f"COULD NOT CHECK: refusing to launch — {socket_path} already exists, and this run "
            "did not create it. The channel server UNLINKS the socket it bound when it exits, "
            "so launching here could destroy a path something else owns. Nothing was measured "
            "and nothing was removed.",
            file=out,
        )
        return EXIT_COULD_NOT_CHECK

    if live_socket is not None and os.path.abspath(os.path.expanduser(live_socket)) == socket_path:
        print(
            f"COULD NOT CHECK: refusing to launch — the throwaway socket path resolved to the "
            f"live socket you declared ({socket_path}). Nothing was measured and nothing was "
            "removed.",
            file=out,
        )
        return EXIT_COULD_NOT_CHECK

    try:
        try:
            proc = subprocess.run(
                [node, entry],
                stdin=subprocess.DEVNULL,   # (b): the lifecycle is stdin-driven
                capture_output=True,
                text=True,
                errors="replace",
                env=child_env(env, socket_path, tmpdir),
                timeout=timeout,
            )
        except subprocess.TimeoutExpired as expired:
            partial = expired.stderr or ""
            if isinstance(partial, bytes):
                partial = partial.decode("utf-8", "replace")
            print(
                f"COULD NOT CHECK: {entry} was still running after {timeout:g}s and was killed. "
                "With stdin closed a healthy server binds and exits 0 immediately, so this is "
                "NOT a pass — it is an unfinished measurement. Re-run; if it recurs, the entry "
                "is hanging before or during bind.",
                file=out,
            )
            if partial.strip():
                print("--- child stderr (partial) ---", file=out)
                print(partial.rstrip(), file=out)
            return EXIT_COULD_NOT_CHECK

        if proc.returncode == 0:
            print(_pass_message(entry, proc.stderr), file=out)
            return EXIT_LAUNCH_OK

        print(
            f"LAUNCH FAILED: {entry} exited {proc.returncode} under this user's node — this "
            "deployment will not come up at the next session start. The child's own stderr "
            "below is the diagnosis.",
            file=out,
        )
        print("--- child stderr ---", file=out)
        print((proc.stderr or "(none)").rstrip(), file=out)
        return EXIT_LAUNCH_FAILED
    finally:
        # Everything in here was created by this run or by the child (the socket it
        # unlinks on its way out, a `.FAILED` marker). The refusals above return
        # BEFORE this block precisely so it never reaches a path we did not mint.
        shutil.rmtree(tmpdir, ignore_errors=True)


def build_parser() -> argparse.ArgumentParser:
    p = argparse.ArgumentParser(
        prog="check-channel-snapshot.py",
        description=(
            "Launch-assert a DEPLOYED channel server as the OS user that owns it: spawn its "
            "entry with stdin closed and read the exit code. Run it on the seat, before a "
            "session starts."
        ),
        epilog=(
            "exit 0 = launch OK (the module graph resolved and the server came up; NOT a "
            "steady-state or staleness verdict). "
            "exit 1 = launch FAILED, conclusively — the child's stderr is printed. "
            "exit 2 = COULD NOT CHECK (no node on PATH, path missing or untraversable, the "
            "socket guard refused, or the backstop timeout fired) — not a verdict either way."
        ),
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )
    p.add_argument(
        "server_path",
        help="the deployed channel-server DIRECTORY (or its " + ENTRY_FILE + " entry)",
    )
    p.add_argument(
        "--live-socket",
        help="your configured channel.socket, if you know it — this run refuses rather than "
        "launching if its throwaway socket path resolves to that one",
    )
    p.add_argument(
        "--timeout",
        type=float,
        default=DEFAULT_TIMEOUT_SECONDS,
        help="backstop only, in seconds (default %(default)s); a timeout is INCONCLUSIVE (exit 2), never a pass",
    )
    return p


def main(argv=None) -> int:
    args = build_parser().parse_args(argv)

    return run_launch_assert(
        args.server_path,
        live_socket=args.live_socket,
        timeout=args.timeout,
    )


if __name__ == "__main__":
    sys.exit(main())
