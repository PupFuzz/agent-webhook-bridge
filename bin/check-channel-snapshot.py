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
  0  LAUNCH OK. The module graph resolved AND a listener bound — both, never one.
     Bounded claim: it says nothing about STEADY STATE (whether the server stays
     healthy under traffic), and nothing about whether the snapshot is STALE —
     staleness is `bridge:check`'s version leg, and a seat whose deployment the
     bridge cannot traverse gets no staleness verdict from anywhere today. The bind
     proven is an ephemeral loopback one this run owns, not your configured
     endpoint.
  1  LAUNCH FAILED. Conclusive for the environment this ran in: the deployment will
     not come up at the next session start as invoked here. The child's stderr is
     printed verbatim. BOUND: a startup refusal driven by an env var this tool does
     not override still fires (today: `BRIDGE_TOOLS_SSH_TARGET` and
     `BRIDGE_TOOLS_ENDPOINT` both set ⇒ `refuseDeaf`), and conversely a refusal
     configured only in `.mcp.json`'s `env` block is invisible here and can fire at
     session start without firing now. Pinning the transport (see (a)) MOVED the
     transport into that class rather than out of it — read (a), the trade is
     deliberate.
  2  COULD NOT CHECK. NOT a verdict in either direction. Covers: no `node` on PATH
     (an environment problem, not a snapshot problem); the path is missing, or not
     traversable by this user; the throwaway-socket guard refused; the backstop
     timeout fired; and the child exited 0 WITHOUT ever reporting a bind (an empty or
     truncated entry does exactly that); and the child was KILLED BY A SIGNAL (an OOM-kill or a
     cgroup reap is a fact about the machine, not about the deployment) — an unfinished measurement, deliberately not
     a failure, because a failure verdict there would rest on matching a log string.

TWO BUILD REQUIREMENTS, both about not BREAKING the thing being diagnosed.

(a) EVERY CHANNEL-ADDRESSING INPUT THE ENTRY READS IS NEUTRALISED — not the socket
    specifically. The general rule, because the specific one was under-specified once
    already: enumerate the entry's addressing inputs AT SOURCE and assign a throwaway
    for each, before shipping a new transport. Today that is four —
    `BRIDGE_CHANNEL_TRANSPORT` (pinned to `http`), `BRIDGE_CHANNEL_SOCKET`,
    `BRIDGE_CHANNEL_PORT` (0, ephemeral) and `XDG_RUNTIME_DIR` — all assigned in
    `child_env()`, which is the single place it happens. Pinning the transport is the
    load-bearing one twice over: it stops an unpinned Windows child defaulting to
    `unix` and false-FAILing a healthy deployment, and an ephemeral loopback bind
    eliminates the live-unix-socket hazard class outright rather than guarding against
    it. See `child_env()` for both arguments in full.

    THE SOCKET IS STILL A THROWAWAY, AND EXPLICITLY SO — belt-and-braces behind that
    pin, and the layer that holds if the pin is ever changed. In the entry,
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
import signal
import subprocess
import sys
import tempfile

ENTRY_FILE = "agent-webhook-bridge-channel.mjs"

# The basename of the throwaway socket, inside the private temp dir this run mints.
SOCKET_BASENAME = "launch-assert.sock"

# Backstop only — see (b). Generous: a cold box loading the MCP SDK is still well
# under this, and the number is not a measurement of anything.
DEFAULT_TIMEOUT_SECONDS = 15

# The child's own listening line, and the ONE place this tool couples to the entry's
# prose — so the coupling is deliberately ASYMMETRIC. Seeing it upgrades an exit-0 to
# a PASS; NOT seeing it withholds the pass (exit 2, unfinished measurement) and is
# never, ever allowed to produce a FAILURE. So the worst a reworded line upstream can
# do is make healthy deployments report "could not check" — annoying and visible —
# rather than declaring the fleet broken, which is what keying a FAIL on it would do.
LISTENING_MARKER = " listening on "

EXIT_LAUNCH_OK = 0
EXIT_LAUNCH_FAILED = 1
EXIT_COULD_NOT_CHECK = 2


def throwaway_socket_path(tmpdir: str) -> str:
    return os.path.join(tmpdir, SOCKET_BASENAME)


def child_env(parent_env, socket_path: str, runtime_dir: str) -> dict:
    """The child's environment: the parent's, with EVERY channel-addressing input the
    entry reads OVERRIDDEN to something this run owns.

    All four are assignments, never `setdefault` — the seat exports the real values and
    they must LOSE. Dropping any one puts a live endpoint, or the wrong platform
    default, back in reach.

    `BRIDGE_CHANNEL_TRANSPORT` is pinned to `http`, and it is the LOAD-BEARING one, for
    two separate reasons.

    CORRECTNESS. The entry defaults it to `unix` when unset. On a Windows seat the value
    is set by the LAUNCHER's process (`examples/start-claude.ps1` exports it) and/or by
    the `env` block of `.mcp.json` — neither of which a freshly-opened PowerShell
    inherits. An unpinned child there therefore sees it unset, defaults to `unix`, binds
    a filesystem socket, and Node on Win32 rejects that with EACCES: this tool would
    print LAUNCH FAILED — *conclusive*, *will not come up at the next session start* —
    about a perfectly healthy deployment, on exactly the seat population it exists to
    serve. A confidently-wrong verdict is the worst thing a diagnostic can produce.

    SAFETY, and this is why the pin is a better fix than another guard. An ephemeral
    loopback bind is platform-uniform and ELIMINATES the live-unix-socket hazard class
    outright rather than defending against it: the entry never reads the socket path at
    all under `http`, so it can never bind — and therefore never `unlinkSync` — a socket
    the seat is using. The `BRIDGE_CHANNEL_SOCKET` override and both refusals in
    `run_launch_assert()` become belt-and-braces behind it. They are KEPT deliberately:
    they are the layer that still holds if this pin is ever changed, and the cost of
    keeping them is three lines.

    The trade, stated rather than buried: the assert then proves an ephemeral LOOPBACK
    bind, not that the seat's own configured endpoint is bindable. Module-graph
    resolution — what the DL-230 incident breaks, and the reason this tool exists —
    happens before either transport branch and is identical under both.

    `BRIDGE_CHANNEL_PORT=0` is an ephemeral port, never the seat's configured one.
    `XDG_RUNTIME_DIR` is where a `.FAILED` marker lands under either transport (and the
    only input to the default socket path, which the pin makes moot anyway).
    """
    env = dict(parent_env)
    env["BRIDGE_CHANNEL_TRANSPORT"] = "http"
    env["BRIDGE_CHANNEL_SOCKET"] = socket_path
    env["BRIDGE_CHANNEL_PORT"] = "0"
    env["XDG_RUNTIME_DIR"] = runtime_dir
    return env


def _signal_name(number: int) -> str:
    """`SIGKILL (9)` rather than `-9`. `Signals()` raises on a value this platform does
    not know, which is a legitimate state on an unfamiliar kernel — fall back to the
    number rather than dying inside a diagnostic."""
    try:
        return f"{signal.Signals(number).name} ({number})"
    except ValueError:
        return f"signal {number}"


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
    elif os.path.lexists(path):
        # DANGLING SYMLINK, kept apart from REMOVED — one `lexists()` call, and the
        # distinction is not cosmetic: it names a different operator action (repoint the
        # link vs. re-deploy the directory), and the PHP sibling in this same change goes
        # to real lengths to preserve it (`resolveNonStrict()` plus the branch-1 split).
        # Collapsing the two here would be a divergence between two copies of one
        # behaviour, which is a defect rather than a wording choice.
        return (
            None,
            EXIT_COULD_NOT_CHECK,
            f"COULD NOT CHECK: {path} is a symlink whose target does not exist — the link "
            f"went dangling, so there is nothing here to launch and nothing was measured. "
            f"The target moved: repoint the symlink (or re-deploy the directory).",
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


def _pass_message(entry: str) -> str:
    return (
        f"LAUNCH OK: {entry} came up under this user's node and exited 0 — the module graph "
        "resolved and the listener bound.\n"
        "  Bounded claim: this is a launch, not a soak. It says nothing about STEADY STATE, "
        "and nothing about whether this snapshot is STALE — staleness is `bridge:check`'s "
        "version leg, and a deployment the bridge process cannot traverse gets no staleness "
        "verdict from anywhere today. The bind it proves is an ephemeral loopback one this "
        "run owns — neither your configured endpoint NOR your configured transport, which "
        "this run pins to `http` so it can never reach a live one."
    )


def _exited_zero_without_binding_message(entry: str) -> str:
    return (
        f"COULD NOT CHECK: {entry} exited 0 but never reported a listening endpoint, so "
        "nothing was proven in either direction. A healthy channel server binds and prints a "
        "listening line before stdin EOF closes it; an entry that is EMPTY, truncated at a "
        "valid parse point, or that returns before starting a listener also exits 0, and this "
        "run cannot tell those apart. This is an unfinished measurement, NOT a pass. Check that "
        "the entry file is intact — its size, and that it still ends in the listener setup — "
        "or, if you have CUSTOMIZED the entry (its own header invites that), whether it now "
        f"prints a different listening line: this run looks for '{LISTENING_MARKER.strip()}'. "
        "Then re-run."
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
            # EXIT 0 IS NECESSARY BUT NOT SUFFICIENT. A zero-length entry — or one
            # truncated at a valid parse point — exits 0 having started nothing, so
            # reporting LAUNCH OK there would be the tool's own message contradicting
            # its own measurement: the exit code is the whole contract and it would
            # say the opposite of what happened.
            #
            # WHY THIS IS EXIT 2 AND NOT EXIT 1 — read before "upgrading" it. Deciding
            # FAILED on the ABSENCE of a listening line would make the verdict rest on
            # matching a log string: reword that line upstream and every healthy
            # deployment on the fleet false-fails. The verdict must never couple to the
            # entry's startup prose. So the marker is allowed to WITHHOLD a pass — an
            # unfinished measurement — and is never allowed to produce a failure. That
            # asymmetry is the whole design, and it is why the failure of the coupling
            # is bounded to "you get told to look again" instead of "your working
            # deployment is declared broken".
            #
            # It cannot false-withhold on a healthy snapshot: the shipped entry emits
            # its listening line deterministically before stdin EOF can close it
            # (measured 15/15, and 60/60 in the earlier ordering probe).
            if not bind_observed(proc.stderr):
                print(_exited_zero_without_binding_message(entry), file=out)
                if (proc.stderr or "").strip():
                    print("--- child stderr ---", file=out)
                    print(proc.stderr.rstrip(), file=out)
                return EXIT_COULD_NOT_CHECK

            print(_pass_message(entry), file=out)
            return EXIT_LAUNCH_OK

        if proc.returncode < 0:
            # KILLED BY A SIGNAL — an ENVIRONMENT fact, not a verdict about the
            # deployment, and the same class as "no node on PATH", which is already a 2
            # for exactly this reason. An OOM-kill or a cgroup reap produces
            # `returncode == -9` and NO stderr at all, so reporting LAUNCH FAILED there
            # sends an operator to re-copy a snapshot that was never given the chance to
            # start. The signal is named readably: `-9` is an implementation detail of
            # POSIX wait status, not something an operator should have to decode.
            signal_name = _signal_name(-proc.returncode)
            print(
                f"COULD NOT CHECK: {entry} was killed by {signal_name} before it could finish "
                "starting, so nothing was measured. That is a fact about this machine — an "
                "out-of-memory kill or a cgroup/container reap looks exactly like this — not "
                "about the deployment. Re-run, ideally somewhere with more headroom.",
                file=out,
            )
            if (proc.stderr or "").strip():
                print("--- child stderr ---", file=out)
                print(proc.stderr.rstrip(), file=out)
            return EXIT_COULD_NOT_CHECK

        # "will not come up" is conclusive for what this run controls, and the bound is
        # named rather than left implied: a startup refusal driven by an env var this
        # tool does NOT override still fires here (e.g. BRIDGE_TOOLS_SSH_TARGET and
        # BRIDGE_TOOLS_ENDPOINT both set ⇒ refuseDeaf). That is a real config defect
        # worth surfacing, but it belongs to the ENVIRONMENT this ran under — and a
        # refusal configured only in .mcp.json's `env` block is invisible to us, so it
        # can fire at session start without firing here.
        #
        # ⚠ AND PINNING THE TRANSPORT MOVED A REFUSAL *INTO* THAT CLASS RATHER THAN OUT
        # OF IT — the opposite of what an earlier version of this comment said. An
        # invalid BRIDGE_CHANNEL_TRANSPORT used to reach the child and refuse loudly;
        # now it cannot reach the child at all, so it is invisible here and still fires
        # at session start. The population is ours and it is every deployment:
        # `.mcp.json`'s env block carries BRIDGE_CHANNEL_TRANSPORT (the shipped
        # `.mcp.json.example` sets it, and `bin/provision-board-tools.py` writes it), so
        # the transport the session will ACTUALLY use is the one value this assert
        # guarantees not to exercise. Accepted anyway — a false LAUNCH FAILED on every
        # Windows seat is the worse failure — and disclosed in the pass message rather
        # than left for a reader to discover.
        print(
            f"LAUNCH FAILED: {entry} exited {proc.returncode} under this user's node, in this "
            "environment — this deployment will not come up at the next session start as "
            "invoked here. The child's own stderr below is the diagnosis; if it names an env "
            "var, check whether your .mcp.json sets it differently.",
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
