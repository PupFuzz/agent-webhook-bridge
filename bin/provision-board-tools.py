#!/usr/bin/env python3
"""End-to-end SSH board-tools enablement (FR #5010).

One program, `--role a|b`. `--role a` runs on the bridge box (Linux, root): it pins
the host-B public key behind an SSH forced command and writes the sshd drop-in.
`--role b` runs on the calling seat (cross-platform): it generates the FIPS key,
deploys the bundled channel-server snapshot, and merges the seat's `.mcp.json`.

The two legs share one key-shape validator and one `.mcp.json` merge contract
(the pure functions below) so the two contracts are defined exactly once and
cannot drift between legs.
"""

from __future__ import annotations

import argparse
import base64
import hashlib
import hmac
import json
import os
import re
import shutil
import subprocess
import sys

CHANNEL_MJS_BASENAME = "agent-webhook-bridge-channel.mjs"

# The three env vars that carry the mutually-exclusive HTTP transport. Setting the
# SSH transport must remove them: the channel server `process.exit(2)`s
# unconditionally (outside its tools guard) when both transports are configured, so
# leaving these alongside BRIDGE_TOOLS_SSH_TARGET kills live-wake, not just tools.
HTTP_SIBLING_TOOLS_KEYS = (
    "BRIDGE_TOOLS_ENDPOINT",
    "BRIDGE_TOOLS_TOKEN",
    "BRIDGE_TOOLS_TOKEN_FILE",
)

WINDOWS_GATED_MSG = (
    "Windows host-B leg gated on aimla AC-1..5 review (roundtable #141) — not yet certified"
)

# Complete positive allowlist of authorized_keys key types (never a prefix test —
# a prefix test is defeated by a multi-line paste whose first line matches).
_KEY_TYPES = (
    "ecdsa-sha2-nistp256",
    "ecdsa-sha2-nistp384",
    "ecdsa-sha2-nistp521",
    "ssh-ed25519",
    "ssh-rsa",
    "sk-ecdsa-sha2-nistp256@openssh.com",
    "sk-ssh-ed25519@openssh.com",
)
_KEY_LINE_RE = re.compile(
    r"(?:" + "|".join(re.escape(t) for t in _KEY_TYPES) + r") [A-Za-z0-9+/]+={0,2}(?: .*)?"
)

_AGENT_RE = re.compile(r"^[a-z0-9_-]+$")
_ARTISAN_RE = re.compile(r"^[A-Za-z0-9_./-]+$")
_SSH_ACCOUNT_RE = re.compile(r"^[a-z_][a-z0-9_-]*$")


# --------------------------------------------------------------------------- #
# Pure functions — the anti-drift core, unit-tested (see test_provision_board_tools.py)
# --------------------------------------------------------------------------- #
def is_authorized_key_shape(s: object) -> bool:
    """True iff `s` is exactly ONE well-formed authorized_keys public-key line.

    Rejects any string containing CR or LF (a multi-line paste would otherwise
    land its second line as an unrestricted key), validates the whole line as
    `<keytype> <base64blob> [comment]` against the positive key-type allowlist,
    and requires the blob to be pure base64.
    """
    if not isinstance(s, str):
        return False
    # `.` matches CR (only LF is excluded by default), so the regex alone would let a
    # CR ride into a comment — this reject is load-bearing, not redundant.
    if "\r" in s or "\n" in s:
        return False
    return _KEY_LINE_RE.fullmatch(s) is not None


def _args_hold_channel_mjs(args: object, resolve) -> bool:
    """True iff any element of `args` resolves to the channel server .mjs by BASENAME.

    Basename (not full-path) equality is deliberate: the seat's own prior entry uses
    an absolute path that changes when the deploy dir moves (prod → new checkout) or
    a symlink resolves differently; a full-path compare would misclassify it as
    foreign and break the idempotent re-run.
    """
    if not isinstance(args, list):
        return False
    for a in args:
        if not isinstance(a, str):
            continue
        try:
            resolved = resolve(a)
        except (OSError, ValueError):
            resolved = a
        if os.path.basename(resolved) == CHANNEL_MJS_BASENAME:
            return True
    return False


def merge_mcp_json(existing_text, channel_name, mjs_path, env, *, resolve=os.path.realpath):
    """Return the merged `.mcp.json` config dict. Pure — no file I/O.

    `existing_text` is the current file contents, or None when the file is absent.
    Raises ValueError on unparseable existing JSON (refuse, change nothing) or when
    `mcpServers.<channel_name>` is held by a foreign server. Sets the SSH transport
    and actively deletes the HTTP sibling transport keys (never BRIDGE_CHANNEL_TOKEN).
    """
    if existing_text is None:
        config: dict = {}
    else:
        try:
            config = json.loads(existing_text)
        except ValueError as e:
            raise ValueError(f"refuse: existing .mcp.json is not parseable JSON ({e})") from e
        if not isinstance(config, dict):
            raise ValueError("refuse: existing .mcp.json is not a JSON object")

    servers = config.get("mcpServers")
    if servers is None:
        servers = {}
    elif not isinstance(servers, dict):
        raise ValueError("refuse: .mcp.json mcpServers is not a JSON object")

    existing_entry = servers.get(channel_name)
    if existing_entry is not None:
        if not isinstance(existing_entry, dict):
            raise ValueError(
                f"refuse: mcpServers.{channel_name} exists but is not a JSON object"
            )
        if not _args_hold_channel_mjs(existing_entry.get("args"), resolve):
            raise ValueError(
                f"refuse: mcpServers.{channel_name} is held by a foreign server "
                f"(its args do not reference {CHANNEL_MJS_BASENAME})"
            )
        entry = existing_entry
    else:
        entry = {}

    entry["command"] = "node"
    entry["args"] = [mjs_path]

    env_block = entry.get("env")
    if not isinstance(env_block, dict):
        env_block = {}
    env_block.update(env)
    if "BRIDGE_TOOLS_SSH_TARGET" in env_block:
        for key in HTTP_SIBLING_TOOLS_KEYS:
            env_block.pop(key, None)
    entry["env"] = env_block

    servers[channel_name] = entry
    config["mcpServers"] = servers
    return config


def _parse_known_hosts_line(line: str):
    """(hostspec, keytype, blob) for one known_hosts line, or None if not a key line."""
    line = line.strip()
    if not line or line.startswith("#"):
        return None
    toks = line.split()
    idx = 1 if toks[0].startswith("@") else 0  # skip an optional @cert-authority/@revoked marker
    if len(toks) < idx + 3:
        return None
    return toks[idx], toks[idx + 1], toks[idx + 2]


def _known_host_name(host: str, port) -> str:
    if port and int(port) != 22:
        return f"[{host}]:{port}"
    return host


def _hostspec_matches(hostspec: str, name: str) -> bool:
    """True iff a known_hosts hostspec (plaintext list OR a hashed |1|salt|hash) covers `name`."""
    if hostspec.startswith("|1|"):
        parts = hostspec.split("|")
        if len(parts) != 4:
            return False
        try:
            salt = base64.b64decode(parts[2])
            expected = base64.b64decode(parts[3])
        except ValueError:
            return False
        got = hmac.new(salt, name.encode(), hashlib.sha1).digest()
        return hmac.compare_digest(got, expected)
    return name in hostspec.split(",")


def _existing_host_keys(content: str, name: str) -> set:
    keys = set()
    for line in content.splitlines():
        parsed = _parse_known_hosts_line(line)
        if parsed is not None and _hostspec_matches(parsed[0], name):
            keys.add((parsed[1], parsed[2]))
    return keys


def _scanned_host_keys(scanned_lines) -> set:
    keys = set()
    for line in scanned_lines:
        parsed = _parse_known_hosts_line(line)
        if parsed is not None:
            keys.add((parsed[1], parsed[2]))
    return keys


def resolve_known_hosts_action(existing_content, host, port, scanned_lines) -> str:
    """Decide how to seed known_hosts for `host` — pure, no I/O. One of:
      "refuse"  — an entry of the SAME key type exists with a DIFFERENT key (rotation/MITM);
      "skip"    — every scanned key is already pinned (idempotent no-op);
      "append"  — at least one scanned key is new and none conflicts.
    Mirrors ssh's own host-key semantics (same type + different key ⇒ identity changed).
    Raises ValueError on an empty scan — that is a failed keyscan, never a silent skip.
    """
    name = _known_host_name(host, port)
    existing = _existing_host_keys(existing_content or "", name)
    scanned = _scanned_host_keys(scanned_lines)
    if not scanned:
        raise ValueError(f"no host keys were scanned for {name}")

    existing_by_type: dict = {}
    for keytype, blob in existing:
        existing_by_type.setdefault(keytype, set()).add(blob)
    for keytype, blob in scanned:
        if keytype in existing_by_type and blob not in existing_by_type[keytype]:
            return "refuse"
    if scanned <= existing:
        return "skip"
    return "append"


# --------------------------------------------------------------------------- #
# Shared helpers
# --------------------------------------------------------------------------- #
def _fail(message: str) -> "NoReturn":  # type: ignore[name-defined]
    raise SystemExit(f"provision-board-tools: {message}")


def _read_pubkey(args) -> str:
    if args.pubkey_stdin and args.pubkey_from:
        _fail("give exactly one of --pubkey-stdin / --pubkey-from")
    if args.pubkey_stdin:
        raw = sys.stdin.read()
    elif args.pubkey_from:
        with open(args.pubkey_from, encoding="utf-8") as fh:
            raw = fh.read()
    else:
        _fail("--role a needs --pubkey-stdin or --pubkey-from <path>")
    key = raw.strip("\n")
    if not is_authorized_key_shape(key):
        _fail(
            "the supplied public key is not a single well-formed authorized_keys line "
            "(rejected: multi-line / CR-LF / unknown key type / non-base64 blob)"
        )
    return key


def _bundled_snapshot_dir() -> str:
    here = os.path.dirname(os.path.abspath(__file__))
    for candidate in (
        os.path.join(here, "channel-servers"),
        os.path.join(here, "..", "examples", "channel-servers"),
    ):
        if os.path.isfile(os.path.join(candidate, "package.json")):
            return os.path.abspath(candidate)
    _fail("bundled channel-server snapshot not found alongside the script")


def _package_version(package_json_path: str) -> str:
    with open(package_json_path, encoding="utf-8") as fh:
        return str(json.load(fh).get("version", ""))


def _version_tuple(v: str) -> tuple:
    parts = []
    for chunk in v.split("."):
        m = re.match(r"\d+", chunk)
        parts.append(int(m.group()) if m else 0)
    return tuple(parts)


# --------------------------------------------------------------------------- #
# Host-A leg (`--role a`) — Linux, root
# --------------------------------------------------------------------------- #
def run_role_a(args) -> int:
    import pwd  # POSIX-only; host A is Linux by definition (forced-command + sshd)

    # os.geteuid() is absent on Windows — reference it strictly inside the a-path.
    if os.geteuid() != 0:
        _fail("--role a writes /etc/ssh and another account's authorized_keys — run as root")

    agent = args.agent
    artisan = args.artisan
    account = args.ssh_account
    if not _AGENT_RE.fullmatch(agent):
        _fail(f"--agent {agent!r} must match ^[a-z0-9_-]+$")
    if not artisan or not _ARTISAN_RE.fullmatch(artisan):
        _fail(f"--artisan {artisan!r} must match ^[A-Za-z0-9_./-]+$")
    if not account or not _SSH_ACCOUNT_RE.fullmatch(account):
        _fail(f"--ssh-account {account!r} must match ^[a-z_][a-z0-9_-]*$")

    pubkey = _read_pubkey(args)

    try:
        pw = pwd.getpwnam(account)
    except KeyError:
        _fail(f"account {account!r} does not exist on this host")

    forced = (
        f'command="php {artisan} bridge:tools-call --agent={agent}"'
        ",no-pty,no-agent-forwarding,no-X11-forwarding,no-port-forwarding"
    )
    guard = f'bridge:tools-call --agent={agent}"'
    supplied_core = " ".join(pubkey.split()[:2])

    ssh_dir = os.path.join(pw.pw_dir, ".ssh")
    os.makedirs(ssh_dir, exist_ok=True)
    os.chmod(ssh_dir, 0o700)
    os.chown(ssh_dir, pw.pw_uid, pw.pw_gid)
    authz = os.path.join(ssh_dir, "authorized_keys")

    existing_lines = []
    if os.path.isfile(authz):
        with open(authz, encoding="utf-8") as fh:
            existing_lines = fh.read().splitlines()

    guard_line = next((ln for ln in existing_lines if guard in ln), None)
    if guard_line is not None:
        if supplied_core in guard_line:
            print(f"authorized_keys: forced-command line for agent {agent} already present (same key) — no change.")
        else:
            # Rotation / compromise signal: the guard anchors on the agent, not the key,
            # so a re-run with a NEW key would leave the OLD one authorized. Refuse loudly.
            _fail(
                f"authorized_keys already pins a DIFFERENT key for agent {agent}. "
                f"This is a key rotation/compromise signal — remove the old line + old key "
                f"from {authz}, then re-run. Refusing to silently leave the old key authorized."
            )
    else:
        with open(authz, "a", encoding="utf-8") as fh:
            fh.write(f"{forced} {pubkey}\n")
        print(f"authorized_keys: appended the forced-command line for agent {agent}.")

    os.chmod(authz, 0o600)
    os.chown(authz, pw.pw_uid, pw.pw_gid)

    _write_sshd_dropin(account)

    _run_checked(["sshd", "-t"], "sshd -t rejected the resulting config")
    if not any(
        _run_ok(cmd)
        for cmd in (
            ["systemctl", "reload", "sshd"],
            ["systemctl", "reload", "ssh"],
            ["service", "ssh", "reload"],
        )
    ):
        _fail("could not reload sshd (tried systemctl reload sshd/ssh and service ssh reload)")

    print(f"Done. Certify from host B: bridge:check --probe-tools-ssh=<{account}@host-A>")
    return 0


def _write_sshd_dropin(account: str) -> None:
    directives = {
        "PasswordAuthentication": "no",
        "ClientAliveInterval": "300",
        "ClientAliveCountMax": "2",
        "MaxSessions": "10",
    }
    path = f"/etc/ssh/sshd_config.d/{account}-board-tools.conf"
    if os.path.isfile(path):
        with open(path, encoding="utf-8") as fh:
            body = fh.read()
        missing = [d for d in directives if not re.search(rf"^\s*{d}\b", body, re.MULTILINE)]
        if missing:
            print(
                f"WARNING: sshd drop-in {path} exists but is missing directive(s): "
                f"{', '.join(missing)} — leaving it (not clobbering); fix by hand or remove and re-run."
            )
        else:
            print(f"sshd drop-in {path} already present and complete — leaving it.")
        return
    lines = [f"Match User {account}"] + [f"    {k} {v}" for k, v in directives.items()]
    with open(path, "w", encoding="utf-8") as fh:
        fh.write("\n".join(lines) + "\n")
    os.chmod(path, 0o644)
    print(f"sshd drop-in written: {path}")


# --------------------------------------------------------------------------- #
# Host-B leg (`--role b`) — cross-platform (POSIX path implemented; Windows gated)
# --------------------------------------------------------------------------- #
def run_role_b(args) -> int:
    if not _AGENT_RE.fullmatch(args.agent):
        _fail(f"--agent {args.agent!r} must match ^[a-z0-9_-]+$")

    home = _host_b_home()
    key_dir = os.path.join(home, ".ssh")
    os.makedirs(key_dir, exist_ok=True)
    key_path = os.path.join(key_dir, f"{args.agent}-board-tools")
    pub_path = key_path + ".pub"

    _keygen(args.agent, key_path)
    _harden_private_key_perms(key_path)

    with open(pub_path, encoding="utf-8") as fh:
        pubkey = fh.read().strip("\n")
    if not is_authorized_key_shape(pubkey):
        _fail(f"generated public key at {pub_path} failed the shape check — refusing to hand it off")

    deploy_dir = os.path.join(os.path.abspath(args.project_dir), ".channel-server")
    mjs_path = os.path.join(deploy_dir, CHANNEL_MJS_BASENAME)
    _deploy_snapshot(deploy_dir)

    env = {
        "BRIDGE_CHANNEL_TRANSPORT": "unix",
        "BRIDGE_CHANNEL_NAME": args.channel_name,
        "BRIDGE_TOOLS_SSH_TARGET": args.ssh_target,
    }
    if args.ssh_key:
        env["BRIDGE_TOOLS_SSH_KEY"] = args.ssh_key
    if args.ssh_port:
        env["BRIDGE_TOOLS_SSH_PORT"] = str(args.ssh_port)

    mcp_path = os.path.join(os.path.abspath(args.project_dir), ".mcp.json")
    existing_text = None
    if os.path.isfile(mcp_path):
        with open(mcp_path, encoding="utf-8") as fh:
            existing_text = fh.read()
    try:
        merged = merge_mcp_json(existing_text, args.channel_name, mjs_path, env)
    except ValueError as e:
        _fail(str(e))
    with open(mcp_path, "w", encoding="utf-8") as fh:
        json.dump(merged, fh, indent=2)
        fh.write("\n")
    print(f".mcp.json merged: {mcp_path}")

    # Seed known_hosts BEFORE any --self-cert so --self-cert is a real host-key
    # exercise. The .mjs ssh spawn is BatchMode with no StrictHostKeyChecking, so an
    # unseeded host (incl. the same-box 127.0.0.1) fails closed on the first call.
    _seed_known_hosts(args.ssh_target.rsplit("@", 1)[-1], args.ssh_port)

    print()
    print("Public key for the host-A handoff (paste into `--role a --pubkey-stdin`):")
    print(f"  {pubkey}")
    print(f"Same-box: hand this path to `--role a --pubkey-from`:\n  {pub_path}")
    print()
    print("Launch Claude Code with the mandatory per-session dev-channel flag:")
    print(f"  claude --dangerously-load-development-channels server:{args.channel_name}")
    print("(This flag is CLI-only every session — no .mcp.json/settings.json equivalent.)")

    if args.self_cert:
        return _self_cert(args.ssh_target, args.ssh_key, args.ssh_port)
    return 0


def _host_b_home() -> str:
    if os.name == "nt":
        raise NotImplementedError(WINDOWS_GATED_MSG)
    return os.path.expanduser("~")


def _keygen(agent: str, key_path: str) -> None:
    if os.path.exists(key_path):
        print(f"ssh key {key_path} already present — leaving it (see rotation guidance in the spec).")
        return
    # FIPS ECDSA P-256, passphraseless (the .mjs ssh spawn is BatchMode with no
    # agent). argv list, never shell=True — POSIX/Windows comment quoting differs.
    _run_checked(
        ["ssh-keygen", "-t", "ecdsa", "-b", "256", "-N", "", "-f", key_path, "-C", f"{agent}-board-tools"],
        "ssh-keygen failed",
    )
    print(f"generated FIPS ECDSA P-256 key: {key_path}")


def _harden_private_key_perms(key_path: str) -> None:
    if os.name == "nt":
        raise NotImplementedError(WINDOWS_GATED_MSG)
    import stat

    os.chmod(key_path, 0o600)
    mode = stat.S_IMODE(os.stat(key_path).st_mode)
    if mode & 0o077:
        _fail(
            f"private key {key_path} is group/world-accessible (mode {oct(mode)}) after chmod 600 — refusing"
        )


_LOOPBACK_HOSTS = frozenset({"127.0.0.1", "::1", "localhost"})


def _seed_known_hosts(host: str, port) -> None:
    if os.name == "nt":
        raise NotImplementedError(WINDOWS_GATED_MSG)  # %USERPROFILE%\.ssh\known_hosts

    known_hosts = os.path.join(os.path.expanduser("~"), ".ssh", "known_hosts")
    scan_cmd = ["ssh-keyscan", "-H"]
    if port and int(port) != 22:
        scan_cmd += ["-p", str(port)]
    scan_cmd.append(host)
    try:
        proc = subprocess.run(scan_cmd, capture_output=True, text=True, timeout=30)
    except (OSError, subprocess.TimeoutExpired) as e:
        _fail(f"ssh-keyscan of {host} failed: {e}")
    scanned = [ln for ln in proc.stdout.splitlines() if ln.strip() and not ln.startswith("#")]
    if not scanned:
        _fail(
            f"ssh-keyscan returned no host keys for {host} (unreachable / no sshd?) — cannot seed "
            f"known_hosts; the first board-tools call would fail closed on host-key verification"
        )

    existing = ""
    if os.path.isfile(known_hosts):
        with open(known_hosts, encoding="utf-8") as fh:
            existing = fh.read()

    action = resolve_known_hosts_action(existing, host, port, scanned)
    if action == "refuse":
        _fail(
            f"known_hosts already pins a DIFFERENT host key for {host} — the host key changed. "
            f"If host-A legitimately rotated its key, remove the stale {host} line(s) from "
            f"{known_hosts} and re-run; otherwise treat this as a possible MITM. Refusing to overwrite."
        )

    _print_scanned_fingerprints(scanned, host)
    if action == "skip":
        print(f"known_hosts: host key for {host} already present — no change.")
        return

    present = _existing_host_keys(existing, _known_host_name(host, port))
    new_lines = []
    for ln in scanned:
        parsed = _parse_known_hosts_line(ln)
        if parsed is not None and (parsed[1], parsed[2]) not in present:
            new_lines.append(ln)
    os.makedirs(os.path.dirname(known_hosts), exist_ok=True)
    with open(known_hosts, "a", encoding="utf-8") as fh:
        for ln in new_lines:
            fh.write(ln + "\n")
    os.chmod(known_hosts, 0o600)
    print(f"known_hosts: seeded {len(new_lines)} host key(s) for {host}.")


def _print_scanned_fingerprints(scanned, host: str) -> None:
    import tempfile

    with tempfile.NamedTemporaryFile("w", suffix=".khscan", delete=False, encoding="utf-8") as tf:
        tf.write("\n".join(scanned) + "\n")
        tmp = tf.name
    try:
        out = subprocess.run(["ssh-keygen", "-lf", tmp], capture_output=True, text=True)
    finally:
        os.unlink(tmp)
    if out.returncode != 0 or not out.stdout.strip():
        return
    if host in _LOOPBACK_HOSTS:
        print(f"Scanned host-key fingerprint(s) for {host} (same-box loopback — advisory only):")
    else:
        print(
            f"Scanned host-key fingerprint(s) for {host} — VERIFY out-of-band against host-A's "
            f"/etc/ssh/ssh_host_*_key.pub:"
        )
    for line in out.stdout.strip().splitlines():
        print(f"  {line}")


def _deploy_snapshot(deploy_dir: str) -> None:
    source = _bundled_snapshot_dir()
    bundled_version = _package_version(os.path.join(source, "package.json"))

    deployed_pkg = os.path.join(deploy_dir, "package.json")
    if os.path.isfile(deployed_pkg):
        deployed_version = _package_version(deployed_pkg)
        if _version_tuple(deployed_version) >= _version_tuple(bundled_version):
            print(f"channel-server snapshot up to date (deployed {deployed_version} >= bundled {bundled_version}).")
            _npm_ci(deploy_dir)
            return
        print(f"replacing stale snapshot (deployed {deployed_version} < bundled {bundled_version}).")
        shutil.rmtree(deploy_dir)

    _require_node_20()
    shutil.copytree(
        source,
        deploy_dir,
        ignore=shutil.ignore_patterns("node_modules"),
        dirs_exist_ok=True,
    )
    print(f"deployed channel-server snapshot {bundled_version} to {deploy_dir}.")
    _npm_ci(deploy_dir)


def _require_node_20() -> None:
    try:
        out = subprocess.run(
            ["node", "--version"], capture_output=True, text=True, check=True
        ).stdout.strip()
    except (OSError, subprocess.CalledProcessError):
        _fail("Node >= 20 is required on host B but `node` is not runnable — install Node 20+ and re-run")
    m = re.match(r"v(\d+)", out)
    if not m or int(m.group(1)) < 20:
        _fail(f"Node >= 20 is required on host B (found {out}) — the channel server needs it to start")


def _npm_ci(deploy_dir: str) -> None:
    _require_node_20()
    try:
        subprocess.run(["npm", "ci"], cwd=deploy_dir, check=True)
    except (OSError, subprocess.CalledProcessError):
        _fail(
            "`npm ci` failed in the deployed snapshot — a missing node_modules is a channel "
            "server that will not start. Fix connectivity/proxy and re-run (never reported success)."
        )


def _self_cert(target: str, ssh_key, ssh_port) -> int:
    cmd = ["ssh", "-o", "BatchMode=yes", "-o", "ConnectTimeout=10"]
    if ssh_key:
        cmd += ["-i", ssh_key]
    if ssh_port:
        cmd += ["-p", str(ssh_port)]
    cmd.append(target)
    payload = json.dumps({"tool": "board_my_cards", "args": {}})
    try:
        proc = subprocess.run(cmd, input=payload, capture_output=True, text=True, timeout=30)
    except (OSError, subprocess.TimeoutExpired) as e:
        _fail(f"--self-cert: ssh to {target} failed: {e}")
    try:
        json.loads(proc.stdout)
    except ValueError:
        _fail(
            f"--self-cert: ssh {target} returned no parseable JSON envelope "
            f"(exit {proc.returncode}; stderr: {proc.stderr.strip()})"
        )
    print(f"--self-cert: OK — {target} returned a parseable board_my_cards envelope.")
    return 0


def _run_checked(cmd, err: str) -> None:
    try:
        subprocess.run(cmd, check=True)
    except (OSError, subprocess.CalledProcessError) as e:
        _fail(f"{err}: {e}")


def _run_ok(cmd) -> bool:
    try:
        return subprocess.run(cmd, capture_output=True).returncode == 0
    except OSError:
        return False


# --------------------------------------------------------------------------- #
# CLI
# --------------------------------------------------------------------------- #
def build_parser() -> argparse.ArgumentParser:
    p = argparse.ArgumentParser(
        prog="provision-board-tools.py",
        description="End-to-end SSH board-tools enablement (FR #5010).",
    )
    p.add_argument("--role", required=True, choices=("a", "b"), help="which leg runs on THIS box")
    p.add_argument("--agent", required=True, help="board-tools agent name (config-resolved on host A)")

    # host A
    p.add_argument("--artisan", help="[role a] absolute path to the bridge `artisan`")
    p.add_argument("--ssh-account", help="[role a] the OS account the forced command runs as")
    p.add_argument("--pubkey-stdin", action="store_true", help="[role a] read the host-B public key from stdin")
    p.add_argument("--pubkey-from", help="[role a] read the host-B public key from this path (same-box)")

    # host B
    p.add_argument("--ssh-target", help="[role b] user@host of the bridge box")
    p.add_argument("--ssh-port", type=int, help="[role b] optional ssh port")
    p.add_argument("--ssh-key", help="[role b] optional identity key path recorded as BRIDGE_TOOLS_SSH_KEY")
    p.add_argument("--project-dir", help="[role b] the Claude project dir holding .mcp.json")
    p.add_argument("--channel-name", help="[role b] the mcpServers key / BRIDGE_CHANNEL_NAME")
    p.add_argument("--self-cert", action="store_true", help="[role b] fire one real ssh board_my_cards round-trip")
    return p


def main(argv=None) -> int:
    args = build_parser().parse_args(argv)
    if args.role == "a":
        return run_role_a(args)
    missing = [n for n in ("ssh_target", "project_dir", "channel_name") if getattr(args, n) is None]
    if missing:
        _fail("--role b requires " + ", ".join("--" + n.replace("_", "-") for n in missing))
    return run_role_b(args)


if __name__ == "__main__":
    sys.exit(main())
