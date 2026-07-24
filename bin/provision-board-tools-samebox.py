#!/usr/bin/env python3
"""Same-box one-shot board-tools SSH enablement (card 5090, FR #5010 follow-on).

Collapses the 6-step same-box cross-user SSH board-tools runbook into ~one root-run
command. On a single box where the agent's Claude seat and the bridge (host A) share the
machine, this wrapper:

  1. Preflights everything, fail-closed (root, both OS users exist, both checkouts'
     `provision-board-tools.py` present, host-A `artisan` present, the agent's `.mcp.json`
     resolvable + unambiguous) — no mutation happens until every gate passes.
  2. Runs `--role b` AS THE AGENT USER from the AGENT's OWN checkout
     (`sudo -H -u <agent> python3 <agent-checkout>/bin/provision-board-tools.py`), captures
     the printed public-key path, and validates it.
  3. Runs `--role a` AS ROOT from the HOST-A checkout, pinning that captured key.
  4. Prints the one unavoidable manual step (restart the agent's Claude session).
  5. Certifies with `php <host-A artisan> bridge:check`.
  6. `chown`s host-A `storage` back to the ssh-account (root-run artisan can leave root logs).

NO GLOBAL BINARY STAGING (card 5090 hard requirement 1). Each leg runs *its own checkout's*
`provision-board-tools.py`: the agent user runs the agent's version, root runs the host-A
version. Two agents on one host can be pinned to different bridge versions; a shared global
`/usr/local/bin` copy would let them clobber each other. If the agent's own copy is missing or
unreadable by the agent user, this FAILS with an actionable message — it never falls back to a
shared/global copy.

The two legs' idempotency (append-or-verify `authorized_keys`, create-if-absent `.mcp.json`
merge, skip-if-present keygen) is owned by `provision-board-tools.py`; this wrapper adds no
non-idempotent state, so re-running it is safe.
"""

from __future__ import annotations

import argparse
import glob as _glob
import importlib.util
import json
import os
import pwd
import shlex
import shutil
import subprocess
import sys
from dataclasses import dataclass

# --------------------------------------------------------------------------- #
# Load the sibling provisioner for its validation primitives — canon #5: the
# agent/account name contracts are single-sourced there and must not be re-derived.
# Co-location is deliberate: the wrapper ships in the same checkout as the tool.
# --------------------------------------------------------------------------- #
_HERE = os.path.dirname(os.path.abspath(__file__))


def _load_tool():
    path = os.path.join(_HERE, "provision-board-tools.py")
    spec = importlib.util.spec_from_file_location("provision_board_tools", path)
    if spec is None or spec.loader is None:  # pragma: no cover - defensive import
        raise SystemExit(f"provision-board-tools-samebox: cannot load sibling tool at {path}")
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


pbt = _load_tool()

LOOPBACK = "127.0.0.1"


class PreflightError(Exception):
    """A fail-closed preflight gate tripped. Message names the operator fix."""


# --------------------------------------------------------------------------- #
# Pure decision + assembly helpers (unit-tested, red-when-reverted)
# --------------------------------------------------------------------------- #
def derive_channel_name(mcp_text: str) -> str:
    """The `.mcp.json` top-level `mcpServers` key = the channel name. Pure given text.

    Raises PreflightError on unparseable JSON, or when the key count is not exactly one
    (0 or >1 is ambiguous — the operator must pass --channel-name). Never guesses.
    """
    try:
        cfg = json.loads(mcp_text)
    except ValueError as e:
        raise PreflightError(f"the agent's .mcp.json is not parseable JSON ({e}) — fix it and re-run") from e
    if not isinstance(cfg, dict):
        raise PreflightError("the agent's .mcp.json is not a JSON object")
    servers = cfg.get("mcpServers")
    if not isinstance(servers, dict) or not servers:
        raise PreflightError(
            "the agent's .mcp.json has no mcpServers entry — pass --channel-name explicitly "
            "(the channel/mcpServers key to enable board tools under)"
        )
    keys = list(servers)
    if len(keys) > 1:
        raise PreflightError(
            f"the agent's .mcp.json has multiple mcpServers keys ({', '.join(keys)}) — "
            f"pass --channel-name to pick which one gets board tools"
        )
    return keys[0]


def build_role_b_argv(python, agent_bin, agent, ssh_account, project_dir, channel_name):
    """The exact `--role b` argv (no --self-cert: the key is not pinned on host A yet)."""
    return [
        python, agent_bin,
        "--role", "b",
        "--agent", agent,
        "--ssh-target", f"{ssh_account}@{LOOPBACK}",
        "--project-dir", project_dir,
        "--channel-name", channel_name,
    ]


def build_role_a_argv(python, hostA_bin, agent, ssh_account, artisan, pubkey_from):
    """The exact `--role a` argv, pinning the captured host-B public key by path (no paste)."""
    return [
        python, hostA_bin,
        "--role", "a",
        "--agent", agent,
        "--ssh-account", ssh_account,
        "--artisan", artisan,
        "--pubkey-from", pubkey_from,
    ]


_PUBKEY_MARKER = "hand this path to `--role a --pubkey-from`"


def parse_pubkey_path(role_b_stdout: str) -> str:
    """Extract the `.pub` path `--role b` printed for the same-box handoff. Pure.

    role_b prints `Same-box: hand this path to \\`--role a --pubkey-from\\`:` immediately
    followed by an indented line with the path. Raises PreflightError if the marker is
    absent (a role-b that changed its handoff contract must not be silently mis-parsed).
    """
    lines = role_b_stdout.splitlines()
    for i, line in enumerate(lines):
        if _PUBKEY_MARKER in line:
            for nxt in lines[i + 1:]:
                if nxt.strip():
                    return nxt.strip()
    raise PreflightError(
        "could not find the printed public-key path in the role-b output — the role-b "
        "handoff contract may have changed; refusing to guess the key path"
    )


def expected_pubkey_path(agent_home: str, agent: str) -> str:
    """The `.pub` path role-b WILL write (advisory only — used for --dry-run display)."""
    return os.path.join(agent_home, ".ssh", f"{agent}-board-tools.pub")


def default_hostA_checkout() -> str:
    """Host-A checkout = the checkout this wrapper ships in (its repo root)."""
    return os.path.dirname(_HERE)


# --------------------------------------------------------------------------- #
# Filesystem seam — real impl for production, faked in tests (mirrors the tool's
# injectable-callable style, e.g. `resolve` / `os_lookup` in provision-board-tools.py).
# --------------------------------------------------------------------------- #
class RealFs:
    def geteuid(self):
        return os.geteuid()

    def getpwnam(self, name):
        return pwd.getpwnam(name)

    def isfile(self, path):
        return os.path.isfile(path)

    def which(self, name):
        return shutil.which(name)

    def read_text(self, path):
        with open(path, encoding="utf-8") as fh:
            return fh.read()

    def readable_by(self, user, path):
        """True iff `user` can read `path` — probed AS that user (root's own access is
        useless here: root reads everything). This is the no-global-fallback gate."""
        try:
            return subprocess.run(
                ["sudo", "-n", "-u", user, "test", "-r", path]
            ).returncode == 0
        except OSError:
            return False

    def readable(self, path):
        return os.access(path, os.R_OK)

    def find_agent_provisioners(self, agent_home):
        """The agent's OWN bin/provision-board-tools.py candidates under its home."""
        hits = []
        for pat in (
            os.path.join(agent_home, "bin", "provision-board-tools.py"),
            os.path.join(agent_home, "*", "bin", "provision-board-tools.py"),
        ):
            hits += _glob.glob(pat)
        return sorted(set(hits))

    def find_mcp_files(self, agent_home):
        hits = []
        for pat in (
            os.path.join(agent_home, ".mcp.json"),
            os.path.join(agent_home, "*", ".mcp.json"),
            os.path.join(agent_home, "*", "*", ".mcp.json"),
        ):
            hits += _glob.glob(pat)
        return sorted(set(hits))


@dataclass
class Plan:
    agent: str
    ssh_account: str
    agent_home: str
    agent_bin: str
    hostA_checkout: str
    hostA_bin: str
    artisan: str
    storage: str
    php: str
    project_dir: str
    channel_name: str


def preflight(args, fs) -> Plan:
    """Validate + resolve everything before any mutation. Fail-closed; every raise names
    its fix (card 5090 hard requirement 2). Read-only — safe to run under --dry-run."""
    if fs.geteuid() != 0:
        raise PreflightError(
            "run as root — this drops to the agent user (role-b) and writes /etc/ssh + "
            "another account's authorized_keys (role-a). Re-run with sudo."
        )

    agent = args.agent
    account = args.ssh_account
    if not pbt._AGENT_RE.fullmatch(agent):
        raise PreflightError(f"--agent {agent!r} must match {pbt._AGENT_RE.pattern}")
    if not pbt._SSH_ACCOUNT_RE.fullmatch(account):
        raise PreflightError(f"--ssh-account {account!r} must match {pbt._SSH_ACCOUNT_RE.pattern}")

    try:
        apw = fs.getpwnam(agent)
    except KeyError:
        raise PreflightError(
            f"agent OS user {agent!r} does not exist (getent passwd {agent} returns nothing) "
            f"— create the user or fix --agent"
        )
    agent_home = args.agent_home or apw.pw_dir

    try:
        fs.getpwnam(account)
    except KeyError:
        raise PreflightError(
            f"ssh-account OS user {account!r} does not exist (getent passwd {account} returns "
            f"nothing) — create the user or fix --ssh-account"
        )

    # --- host-A checkout (role-a runs as root from HERE, not a global bin) ------------- #
    hostA_checkout = args.hostA_checkout or default_hostA_checkout()
    hostA_bin = os.path.join(hostA_checkout, "bin", "provision-board-tools.py")
    if not fs.isfile(hostA_bin):
        raise PreflightError(
            f"host-A provisioner not found at {hostA_bin} — pass --hostA-checkout <bridge checkout> "
            f"(refusing to fall back to a shared/global copy; each install runs its own version)"
        )
    artisan = os.path.join(hostA_checkout, "artisan")
    if not fs.isfile(artisan):
        raise PreflightError(
            f"host-A artisan not found at {artisan} — pass --hostA-checkout pointing at the bridge "
            f"install whose artisan serves `bridge:tools-call`"
        )
    storage = os.path.join(hostA_checkout, "storage")
    php = fs.which("php")
    if not php:
        raise PreflightError("`php` not found on PATH — it is required for the bridge:check certify step")

    # --- agent's OWN provisioner (per-agent version isolation; NO global staging) ------ #
    if args.agent_bin:
        agent_bin = args.agent_bin
        if not fs.isfile(agent_bin):
            raise PreflightError(
                f"--agent-bin {agent_bin} not found — point it at the agent's OWN "
                f"bin/provision-board-tools.py (do NOT stage a shared/global copy)"
            )
    else:
        cands = fs.find_agent_provisioners(agent_home)
        if not cands:
            raise PreflightError(
                f"the agent {agent!r}'s OWN bin/provision-board-tools.py was not found under "
                f"{agent_home} — ensure the agent has its own bridge checkout (per-agent version "
                f"isolation is required; a shared/global copy is refused). Pass --agent-bin to "
                f"point at it explicitly."
            )
        if len(cands) > 1:
            raise PreflightError(
                f"multiple provision-board-tools.py under {agent_home} ({', '.join(cands)}) — "
                f"pass --agent-bin to disambiguate which checkout is the agent's"
            )
        agent_bin = cands[0]

    if not fs.readable_by(agent, agent_bin):
        raise PreflightError(
            f"{agent_bin} is NOT readable by user {agent!r} — role-b runs as that user and would "
            f"fail. Ensure the agent owns/can read its checkout; refusing to copy the tool into a "
            f"shared/global path as a workaround (version-collision hazard)."
        )

    # --- agent .mcp.json -> project-dir + channel-name --------------------------------- #
    if args.project_dir:
        project_dir = args.project_dir
        mcp = os.path.join(project_dir, ".mcp.json")
        if not fs.isfile(mcp):
            raise PreflightError(f"--project-dir {project_dir} has no .mcp.json — check the path")
    else:
        cands = fs.find_mcp_files(agent_home)
        if not cands:
            raise PreflightError(
                f"no .mcp.json found under the agent's home {agent_home} — pass --project-dir "
                f"pointing at the Claude project dir that holds it"
            )
        if len(cands) > 1:
            raise PreflightError(
                f"multiple .mcp.json under {agent_home} ({', '.join(cands)}) — pass --project-dir "
                f"to pick the agent's project"
            )
        mcp = cands[0]
        project_dir = os.path.dirname(mcp)

    channel_name = args.channel_name or derive_channel_name(fs.read_text(mcp))

    return Plan(
        agent=agent,
        ssh_account=account,
        agent_home=agent_home,
        agent_bin=agent_bin,
        hostA_checkout=hostA_checkout,
        hostA_bin=hostA_bin,
        artisan=artisan,
        storage=storage,
        php=php,
        project_dir=project_dir,
        channel_name=channel_name,
    )


# --------------------------------------------------------------------------- #
# Execution (impure)
# --------------------------------------------------------------------------- #
def _fail(message: str) -> "NoReturn":  # type: ignore[name-defined]
    raise SystemExit(f"provision-board-tools-samebox: {message}")


def _run(cmd, err, *, capture=False):
    print("+ " + " ".join(shlex.quote(c) for c in cmd), flush=True)
    proc = subprocess.run(cmd, text=True, capture_output=capture)
    if capture:
        if proc.stdout:
            sys.stdout.write(proc.stdout)
        if proc.stderr:
            sys.stderr.write(proc.stderr)
    if proc.returncode != 0:
        _fail(f"{err} (exit {proc.returncode})")
    return proc


def execute(plan: Plan, fs) -> int:
    # 1. role-b AS THE AGENT USER, from the agent's OWN checkout.
    role_b = build_role_b_argv(
        "python3", plan.agent_bin, plan.agent, plan.ssh_account, plan.project_dir, plan.channel_name
    )
    proc = _run(["sudo", "-H", "-n", "-u", plan.agent] + role_b, "role-b (agent leg) failed", capture=True)

    pubkey_path = parse_pubkey_path(proc.stdout)
    if not fs.isfile(pubkey_path):
        _fail(f"role-b reported pubkey path {pubkey_path} but no file is there")
    if not fs.readable(pubkey_path):
        _fail(f"role-b pubkey {pubkey_path} is not readable — role-a (root) cannot pin it")

    # 2. role-a AS ROOT, from the host-A checkout, pinning the captured key.
    role_a = build_role_a_argv(
        sys.executable, plan.hostA_bin, plan.agent, plan.ssh_account, plan.artisan, pubkey_path
    )
    _run(role_a, "role-a (root leg) failed")

    # 3. the one unavoidable manual step.
    print()
    print("━━━ MANUAL STEP REQUIRED ━━━")
    print(f"  Restart agent {plan.agent!r}'s Claude session so the '{plan.channel_name}' channel")
    print("  re-spawns and picks up the merged .mcp.json (the SSH tools transport).")
    print("━━━━━━━━━━━━━━━━━━━━━━━━━━━━")
    print()

    # 4. certify (offline validation of the host-A forced-command path).
    check_err = None
    try:
        _run([plan.php, plan.artisan, "bridge:check"], "bridge:check certify step failed")
    except SystemExit as e:
        check_err = e

    # 5. chown host-A storage back to the ssh-account (root-run artisan can leave root logs).
    _run(
        ["chown", "-R", f"{plan.ssh_account}:{plan.ssh_account}", plan.storage],
        "chown of host-A storage failed",
    )

    if check_err is not None:
        raise check_err
    print("Same-box board-tools enablement complete.")
    return 0


def _print_dry_run(plan: Plan) -> None:
    role_b = build_role_b_argv(
        "python3", plan.agent_bin, plan.agent, plan.ssh_account, plan.project_dir, plan.channel_name
    )
    expected_pub = expected_pubkey_path(plan.agent_home, plan.agent)
    role_a = build_role_a_argv(
        sys.executable, plan.hostA_bin, plan.agent, plan.ssh_account, plan.artisan, expected_pub
    )
    print("DRY RUN — no changes made. Resolved plan:")
    print(f"  agent            : {plan.agent}")
    print(f"  ssh-account      : {plan.ssh_account}")
    print(f"  agent checkout   : {plan.agent_bin}")
    print(f"  host-A checkout  : {plan.hostA_bin}")
    print(f"  artisan          : {plan.artisan}")
    print(f"  project-dir      : {plan.project_dir}")
    print(f"  channel-name     : {plan.channel_name}")
    print()
    print("Would run (role-b, as the agent user):")
    print("  " + " ".join(shlex.quote(c) for c in (["sudo", "-H", "-n", "-u", plan.agent] + role_b)))
    print(f"Then capture the printed pubkey path (expected: {expected_pub}) and run (role-a, as root):")
    print("  " + " ".join(shlex.quote(c) for c in role_a))
    print(f"Then: bridge:check via `{plan.php} {plan.artisan}`, and chown -R "
          f"{plan.ssh_account}:{plan.ssh_account} {plan.storage}")


def build_parser() -> argparse.ArgumentParser:
    p = argparse.ArgumentParser(
        prog="provision-board-tools-samebox.py",
        description="Same-box one-shot board-tools SSH enablement (card 5090, FR #5010).",
    )
    p.add_argument("--agent", required=True, help="board-tools agent name")
    p.add_argument("--ssh-account", required=True, help="the host-A OS account the forced command runs as")
    p.add_argument("--agent-home", help="override the agent user's home (default: getent passwd)")
    p.add_argument("--agent-bin", help="override the agent's own provision-board-tools.py path")
    p.add_argument("--hostA-checkout", help="override the host-A bridge checkout (default: this wrapper's checkout)")
    p.add_argument("--project-dir", help="override the agent's Claude project dir holding .mcp.json")
    p.add_argument("--channel-name", help="override the mcpServers key / BRIDGE_CHANNEL_NAME")
    p.add_argument("--dry-run", action="store_true", help="preflight + print the plan; make no changes")
    return p


def main(argv=None, fs=None) -> int:
    args = build_parser().parse_args(argv)
    fs = fs or RealFs()
    try:
        plan = preflight(args, fs)
    except PreflightError as e:
        _fail(str(e))
    if args.dry_run:
        _print_dry_run(plan)
        return 0
    return execute(plan, fs)


if __name__ == "__main__":
    sys.exit(main())
