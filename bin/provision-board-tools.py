#!/usr/bin/env python3
"""End-to-end SSH board-tools enablement (FR #5010).

One program, `--role a|b`. `--role a` runs on the bridge box (Linux, root): it pins
the host-B public key behind an SSH forced command (that forced command is the sole
board-tools security boundary — no account-level sshd hardening, card 5091).
`--role b` runs on the calling seat (cross-platform): it generates the FIPS key,
deploys the bundled channel-server snapshot, and merges the seat's `.mcp.json`.

The two legs share one key-shape validator and one `.mcp.json` merge contract
(the pure functions below) so the two contracts are defined exactly once and
cannot drift between legs.

Windows host-B leg status (canon #9 — honest boundary): the `os.name == "nt"`
branches (`_host_b_home`, `_harden_private_key_perms_windows`, `_seed_known_hosts`,
`_require_win_openssh`, and the icacls ACL enumeration/decision) were validated on a
real en-US Windows 11 seat, and the `--self-cert` ssh -i round-trip (spec §5.6) is the
authoritative perm check; the icacls SID-based ACL assertion is defense-in-depth, not
the authoritative check. Locale independence: icacls prints LOCALIZED account names, but
the ACL decision is pinned by well-known SID; principals are resolved to their SIDs via
the OS (a LookupAccountName-equivalent through PowerShell's `NTAccount.Translate`), which
returns the same fixed SIDs regardless of UI language. The en-US name table survives only
as an offline fallback when that lookup is unavailable; an unresolvable principal is kept
raw so the decision fails CLOSED (a spurious refuse, never an unsafe accept).
"""

from __future__ import annotations

import argparse
import base64
import datetime
import hashlib
import hmac
import json
import os
import re
import shutil
import stat
import subprocess
import sys
import tempfile

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

# The SSH transport keys this provisioner OWNS, as ONE SET rather than three
# independently-written keys. Ownership is only meaningful at set granularity: a run that
# writes two of them and leaves the third at whatever a PREVIOUS run happened to set does
# not own the transport, it inherits half of it. `--ssh-port 2222` once, re-provisioned
# without the flag, left `_PORT: 2222` in place forever and the channel server kept
# spawning `ssh -p 2222` — a stale port nothing in the new invocation asked for and
# nothing printed. Membership here is what makes "set it or remove it" enforceable in one
# place, so a fourth key added later inherits the invariant instead of the defect.
SSH_TOOLS_KEYS = (
    "BRIDGE_TOOLS_SSH_TARGET",
    "BRIDGE_TOOLS_SSH_KEY",
    "BRIDGE_TOOLS_SSH_PORT",
)

# Well-known Windows SIDs (locale-independent) — the icacls ACL decision is pinned by
# SID, not by the localized account name icacls prints.
SYSTEM_SID = "S-1-5-18"
ADMINISTRATORS_SID = "S-1-5-32-544"
USERS_SID = "S-1-5-32-545"
AUTHENTICATED_USERS_SID = "S-1-5-11"
EVERYONE_SID = "S-1-1-0"

# icacls simple-rights tokens that confer READ of the private-key bytes / WRITE of a dir.
# (F=full, M=modify, RX=read&execute, R=read, W=write, D=delete; G*=generic; *D=specific.)
_ICACLS_READ_RIGHTS = frozenset({"F", "M", "RX", "R", "RD", "RC", "GR", "GA"})
_ICACLS_WRITE_RIGHTS = frozenset({"F", "M", "W", "WD", "AD", "GW", "GA"})
# The tokens we can classify. A token outside this universe (a hex mask like 0x1200a9,
# or an icacls-output-format-drift token) is UNKNOWN: we cannot prove it does not confer
# read/write, so — this being a private-key ACL — we fail closed and treat it as if it
# does both (see _rights_confer_read / _rights_confer_write).
_ICACLS_RECOGNIZED_RIGHTS = _ICACLS_READ_RIGHTS | _ICACLS_WRITE_RIGHTS
# Inheritance / propagation flags icacls prints in the same parenthesised groups as
# rights — these are NOT access rights and must not be read as such.
_ICACLS_FLAG_TOKENS = frozenset({"OI", "CI", "IO", "NP", "I"})

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


DEFAULT_FORCED_COMMAND_TIMEOUT = 300


def build_forced_command(agent: str, artisan: str, timeout_secs: int) -> str:
    """The `authorized_keys` forced-command line (options included) for one agent.

    The forced command is the SOLE board-tools security boundary (card 5091). Card 5092
    adds a KEY-scoped hard bound on a hung key-holder: `timeout -k 10 <n> php <artisan>
    bridge:tools-call …` caps each invocation's wall-clock at the COMMAND level — the one
    place a bound survives on an ssh-account that doubles as the operator's interactive
    login (an account-level sshd ClientAlive/Match drop-in would disrupt that operator, so
    it was retired — see multi-host.md § 3). `-k 10` escalates to SIGKILL 10s after SIGTERM
    so a holder that traps SIGTERM is still reaped. `timeout_secs <= 0` disables the wrapper
    (operator opt-out). Pure — the single source of the pinned command string.
    """
    inner = f"php {artisan} bridge:tools-call --agent={agent}"
    if timeout_secs > 0:
        inner = f"timeout -k 10 {timeout_secs} {inner}"
    return (
        f'command="{inner}"'
        ",no-pty,no-agent-forwarding,no-X11-forwarding,no-port-forwarding"
    )


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


def merge_mcp_json(existing_text, channel_name, mjs_path, env, env_defaults=None, *, resolve=os.path.realpath):
    """Return the merged `.mcp.json` config dict. Pure — no file I/O.

    `existing_text` is the current file contents, or None when the file is absent.
    Raises ValueError on unparseable existing JSON (refuse, change nothing) or when
    `mcpServers.<channel_name>` is held by a foreign server.

    Two env classes, kept explicit so this one merge site never re-clobbers a live
    channel config:
      - `env` (force-set): keys this provisioner OWNS — the SSH tools transport —
        written UNCONDITIONALLY (overwrite). ⛔ OWNERSHIP IS AT SET GRANULARITY, NOT
        PER KEY: when `env` declares the ssh transport (it carries
        BRIDGE_TOOLS_SSH_TARGET), every member of SSH_TOOLS_KEYS the caller did NOT
        supply is REMOVED from the merged env. An `update()`-only merge cannot express
        "this run has no port", so an optional key survived every later run that
        omitted it. Setting BRIDGE_TOOLS_SSH_TARGET also actively deletes the HTTP
        sibling tools keys (never BRIDGE_CHANNEL_TOKEN).
      - `env_defaults` (create-if-absent): keys the SEAT owns — the live-wake channel
        config (BRIDGE_CHANNEL_TRANSPORT / _NAME) — written with setdefault, so a
        fresh seat is bootstrapped but an existing seat's channel transport is never
        overwritten out from under it.
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
    for key, value in (env_defaults or {}).items():
        env_block.setdefault(key, value)
    if "BRIDGE_TOOLS_SSH_TARGET" in env:
        # Reconcile the OWNED SET against what THIS run declared — `env`, not `env_block`:
        # the question is what this invocation asked for, and `env_block` already carries
        # the previous run's answer by the time we get here.
        for key in SSH_TOOLS_KEYS:
            if key not in env:
                env_block.pop(key, None)
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


def _rights_confer_read(rights) -> bool:
    toks = {r.upper() for r in rights}
    return bool(toks & _ICACLS_READ_RIGHTS) or bool(toks - _ICACLS_RECOGNIZED_RIGHTS)


def _rights_confer_write(rights) -> bool:
    toks = {r.upper() for r in rights}
    return bool(toks & _ICACLS_WRITE_RIGHTS) or bool(toks - _ICACLS_RECOGNIZED_RIGHTS)


def evaluate_key_acl_decision(aces, owner_sid) -> str:
    """Decide whether a Windows private-key ACL is `chmod 600`-equivalent — pure, no I/O.

    `aces` is the parsed icacls ACL: a list of `(sid, rights_tokens)` where rights_tokens
    is an iterable of icacls right letters (R, RX, F, M, ...). Returns:
      "refuse" — some principal BEYOND {owner, SYSTEM, Administrators} can read the key
                 (a world/Users-readable private key is the banned silent failure);
      "ok"     — only the owner (plus Win32-OpenSSH's tolerated SYSTEM + Administrators,
                 which is compatibility, not a hole) can read it.
    Refuse-if-BROADER only: a narrower ACL (e.g. owner-only) is "ok"; owner readability
    itself is proven by the authoritative `ssh -i` round-trip (--self-cert), not here.
    Pinned by SID so it is locale-independent (icacls prints localized names).

    Fail-closed on ambiguity (defense-in-depth for a private key): an EMPTY `aces` cannot
    be certified safe (an unparsed / format-drifted ACL) ⇒ "refuse"; and a non-allowed
    principal holding an unrecognized/unparseable rights token (a hex mask, a drift token)
    is treated as read-conferring by `_rights_confer_read` ⇒ "refuse".
    """
    if not aces:
        return "refuse"
    allowed_readers = {owner_sid, SYSTEM_SID, ADMINISTRATORS_SID}
    for sid, rights in aces:
        if _rights_confer_read(rights) and sid not in allowed_readers:
            return "refuse"
    return "ok"


def evaluate_key_dir_decision(aces, owner_sid) -> str:
    """Decide whether the `.ssh` directory ACL is safe — pure, no I/O. (aimla Minor.)

    A world/Users-writable key directory lets a local attacker swap the key regardless
    of the file ACL, so refuse if Everyone / Users / Authenticated Users can WRITE it.
    Returns "refuse" or "ok". `owner_sid` is accepted for signature symmetry with the
    key decision (the untrusted-writer set is fixed and never includes the owner).
    """
    untrusted_writers = {EVERYONE_SID, USERS_SID, AUTHENTICATED_USERS_SID}
    for sid, rights in aces:
        if sid in untrusted_writers and _rights_confer_write(rights):
            return "refuse"
    return "ok"


def parse_icacls_aces(text, path, name_to_sid) -> list:
    """Parse `icacls <path>` stdout into `[(sid, {rights tokens})]` — pure given `name_to_sid`.

    `name_to_sid(principal)` maps an icacls-printed account name (or raw SID) to a SID;
    an unresolvable principal keeps its raw name so the decision treats it as untrusted
    (fail closed). The first data line is prefixed with `path`; that prefix is stripped.
    Each ACE is `PRINCIPAL:(GRP)(GRP)...`; PRINCIPAL may contain spaces/backslashes
    (`NT AUTHORITY\\SYSTEM`) so the split anchors on the `:(...)` rights suffix, not `:`.
    """
    aces = []
    for raw in text.splitlines():
        line = raw.strip()
        if not line:
            continue
        low = line.lower()
        if low.startswith("successfully processed") or "failed processing" in low:
            continue
        if line.startswith(path):
            line = line[len(path):].strip()
        mo = re.match(r"^(.*?):((?:\([^)]*\))+)$", line)
        if not mo:
            continue
        principal = mo.group(1).strip()
        if not principal:
            continue
        rights = set()
        for grp in re.findall(r"\(([^)]*)\)", mo.group(2)):
            for tok in grp.split(","):
                tok = tok.strip().upper()
                if tok and tok not in _ICACLS_FLAG_TOKENS:
                    rights.add(tok)
        aces.append((name_to_sid(principal), rights))
    return aces


# --------------------------------------------------------------------------- #
# Shared helpers
# --------------------------------------------------------------------------- #
def _fail(message: str) -> "NoReturn":  # type: ignore[name-defined]
    raise SystemExit(f"provision-board-tools: {message}")


def _utc_stamp() -> str:
    """UTC ISO-basic stamp for retained-artifact names (`.bak-`/`.stale-` suffixes)."""
    return datetime.datetime.now(datetime.timezone.utc).strftime("%Y%m%dT%H%M%SZ")


def _path_safe(component: str) -> str:
    """Make a filename component out of a value read from a file we do not own.

    `deployed_version` comes from a `package.json` inside the seat's project dir; it
    reaches a rename TARGET, so a separator or `..` in it would move the retained tree
    somewhere other than beside the snapshot.
    """
    return re.sub(r"[^A-Za-z0-9._-]", "_", component) or "unknown"


def significant_authorized_keys_lines(text: str) -> list:
    """The lines of an `authorized_keys` file that can AUTHORIZE anything.

    Blank lines and `#` comments cannot, and both scans below (the strict
    forced-command guard and the weak `--agent=` heuristic) run over this ONE
    derivation — a comment that mentions the agent must not be reported as a
    hand-pinned line, and a commented-out old entry must not read as present.
    """
    return [ln for ln in text.splitlines() if ln.strip() and not ln.lstrip().startswith("#")]


def weak_agent_pattern(agent: str):
    """A HEURISTIC for "some line here already mentions this agent".

    ⚠ IT IS NOT A PARSER AND DOES NOT CLAIM TO BE. It matches the bare
    `--agent=<name>` spelling only; a hand-written line using `--agent="x"` or
    `--agent='x'` is NOT covered, and such a line stays invisible to it. That is
    acceptable because of which direction the miss falls in: a match causes a
    REFUSAL naming the line, and a miss leaves today's behaviour. It exists to catch
    the common hand-edited entry whose OPTIONS differ from what this tool writes —
    the case where the strict guard says "absent", the append branch adds a second
    line, and the account ends up with two lines for one agent (`bridge:check` then
    FAILs on the ambiguity, one step too late).
    """
    return re.compile(r"--agent=" + re.escape(agent) + r'(?=["\s]|$)')


def parse_expected_fingerprint(value: str) -> str:
    """The `SHA256:<b64>` out of `--expect-fingerprint`, whichever form was given.

    An operator copying from the seat has two plausible things on their clipboard:
    the bare fingerprint, or the whole `ssh-keygen -lf` line
    (`256 SHA256:… comment (ECDSA)`), whose SECOND field is the fingerprint. A
    single-field input is taken WHOLE — it is the bare form, and slicing field 2 out
    of it would silently compare against nothing.
    """
    fields = value.strip().split()
    if not fields:
        _fail("--expect-fingerprint was empty")
    return fields[0] if len(fields) == 1 else fields[1]


def fingerprint_of_pubkey_file(pub_path: str) -> str:
    """`ssh-keygen -E sha256 -lf <pub>` field 2, or a refusal naming the cause."""
    try:
        proc = subprocess.run(
            ["ssh-keygen", "-E", "sha256", "-lf", pub_path],
            capture_output=True, text=True, timeout=30,
        )
    except (OSError, subprocess.TimeoutExpired) as e:
        _fail(f"could not fingerprint {pub_path} with `ssh-keygen -lf`: {e}")
    if proc.returncode != 0:
        _fail(
            f"`ssh-keygen -E sha256 -lf {pub_path}` exited {proc.returncode} — cannot "
            f"fingerprint this key.\nssh-keygen: {proc.stderr.strip() or '(no stderr)'}"
        )
    fields = proc.stdout.split()
    if len(fields) < 2:
        _fail(f"`ssh-keygen -lf {pub_path}` printed no fingerprint field: {proc.stdout.strip()!r}")
    return fields[1]


def _assert_expected_fingerprint(expected_raw, pub_path: str) -> str:
    """Compare `--expect-fingerprint` against the key at `pub_path`; return the actual.

    ⛔ THIS IS A TRANSCRIPTION GUARD, NOT A CHECKPOINT, and the docs say so in as many
    words. It answers *is this the file I meant, for the seat I meant* — nothing more.
    Anyone holding the `.pub` can compute the value, so it establishes no authorship
    and stops no attacker; what stops one is the person deciding which key gets pinned.
    ⭐ EQUALITY IS EXACT. Both values are public, so a mismatch prints BOTH — a guard
    that says only "mismatch" sends the operator to compare two things they cannot see.
    """
    actual = fingerprint_of_pubkey_file(pub_path)
    if expected_raw is None:
        return actual
    expected = parse_expected_fingerprint(expected_raw)
    if expected != actual:
        _fail(
            f"--expect-fingerprint MISMATCH for {pub_path}\n"
            f"  expected: {expected}\n"
            f"  actual:   {actual}\n"
            f"These are public values, so both are printed. Either this is the wrong file, "
            f"or the seat regenerated its key after printing the fingerprint you were given. "
            f"Nothing has been changed."
        )
    return actual


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


def _read_pubkey_and_path(args) -> tuple:
    """`(key, a path holding it, a temp path to unlink or None)`.

    `ssh-keygen -lf` needs a FILE, and `--pubkey-stdin` has none — so the stdin arm
    writes one, 0600, and the caller unlinks it. Doing it here rather than in the
    fingerprint helper keeps ONE reader of the two pubkey flags: a second `if
    args.pubkey_stdin` somewhere else is a second answer able to disagree with this one
    about which key is being pinned.
    """
    key = _read_pubkey(args)
    if args.pubkey_from:
        return key, args.pubkey_from, None
    fd, tmp = tempfile.mkstemp(prefix="provision-board-tools-", suffix=".pub")
    with os.fdopen(fd, "w", encoding="utf-8") as fh:
        fh.write(key + "\n")
    return key, tmp, tmp


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

    agent = args.agent
    artisan = args.artisan
    account = args.ssh_account
    if not _AGENT_RE.fullmatch(agent):
        _fail(f"--agent {agent!r} must match ^[a-z0-9_-]+$")
    if not artisan or not _ARTISAN_RE.fullmatch(artisan):
        _fail(f"--artisan {artisan!r} must match ^[A-Za-z0-9_./-]+$")
    if not account or not _SSH_ACCOUNT_RE.fullmatch(account):
        _fail(f"--ssh-account {account!r} must match ^[a-z_][a-z0-9_-]*$")
    timeout_secs = args.forced_command_timeout
    if timeout_secs < 0:
        _fail(f"--forced-command-timeout {timeout_secs} must be >= 0 (0 disables the bound)")

    pubkey, pubkey_path, pubkey_tmp = _read_pubkey_and_path(args)
    try:
        fingerprint = _assert_expected_fingerprint(args.expect_fingerprint, pubkey_path)
    finally:
        if pubkey_tmp is not None:
            os.unlink(pubkey_tmp)

    try:
        pw = pwd.getpwnam(account)
    except KeyError:
        _fail(f"account {account!r} does not exist on this host")

    # ⭐ TWO ARMS, AND THE SECOND ONE IS NOT A PRIVILEGE GRANT (card#8971). Root is
    # required to write ANOTHER account's authorized_keys. It is not required to write
    # YOUR OWN — the account can already do that with a text editor, and demanding
    # `sudo` for it buys no boundary while spending a privileged window on the most
    # common topology there is (the bridge's own user is the forced-command account).
    # ⛔ Every other non-root combination is still refused, by name.
    euid = os.geteuid()   # absent on Windows — referenced strictly inside the a-path
    self_account = euid != 0
    if self_account and pw.pw_uid != euid:
        _fail(
            f"--role a as a non-root account may only pin into ITS OWN authorized_keys. "
            f"This process runs as uid {euid} and --ssh-account {account!r} is uid "
            f"{pw.pw_uid}. Re-run as {account}, as `sudo -u {account} python3 …`, or as "
            f"root with `sudo python3 …`."
        )

    forced = build_forced_command(agent, artisan, timeout_secs)
    guard = f'bridge:tools-call --agent={agent}"'
    supplied_core = " ".join(pubkey.split()[:2])

    # ⚠ THE DEFAULT PATH, NOT A RESOLVED ONE. sshd's AuthorizedKeysFile can point
    # somewhere else entirely and this tool does not read sshd's config, so the path is
    # PRINTED rather than assumed to be the one sshd will read.
    ssh_dir = os.path.join(pw.pw_dir, ".ssh")
    os.makedirs(ssh_dir, exist_ok=True)
    os.chmod(ssh_dir, 0o700)
    if not self_account:
        # ⛔ follow_symlinks=False IS THE DEFENCE, and it is why a symlinked `~/.ssh`
        # stays legal here (dotfiles repos do this routinely). A path-following chown
        # run by ROOT hands ownership of whatever the link points at — /etc/shadow, say
        # — to the account. Chowning the LINK ITSELF gives the same result for every
        # honest topology and nothing at all for the dishonest one.
        os.chown(ssh_dir, pw.pw_uid, pw.pw_gid, follow_symlinks=False)
    authz = os.path.join(ssh_dir, "authorized_keys")

    existing_lines = []
    if os.path.isfile(authz):
        with open(authz, encoding="utf-8") as fh:
            existing_lines = significant_authorized_keys_lines(fh.read())

    weak = weak_agent_pattern(agent)
    strict_lines = [ln for ln in existing_lines if guard in ln]
    hand_lines = [ln for ln in existing_lines if weak.search(ln) and ln not in strict_lines]
    if hand_lines:
        # ⛔ REPORTED, NEVER SILENTLY APPENDED TO. This fires on the MIXED state too (a
        # line this tool wrote AND a hand-edited one): "already present" would be a true
        # sentence about the wrong thing, and appending would leave the account with two
        # lines for one agent — which `bridge:check` FAILs on, one step too late and from
        # the other side of the box.
        _fail(
            f"a hand-pinned line for agent {agent} exists in {authz} with different "
            f"options; remove it or make it match, then re-run. Refusing to add a second "
            f"line for one agent — sshd would honour whichever matched first, and "
            f"`bridge:check` FAILs on the ambiguity.\n  " + "\n  ".join(hand_lines)
        )

    guard_line = strict_lines[0] if strict_lines else None
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
        _append_authorized_key_line(authz, f"{forced} {pubkey}\n")
        print(f"authorized_keys: appended the forced-command line for agent {agent}.")

    _pin_authorized_keys_perms(authz, pw.pw_uid, pw.pw_gid, chown=not self_account)
    print(f"authorized_keys: {authz} (default path; sshd's AuthorizedKeysFile is not resolved by this tool)")
    print(f"Pinned fingerprint: {fingerprint}")

    # No account-level sshd hardening (card 5091): the forced-command authorized_keys
    # entry IS the board-tools security boundary. Disabling password auth for the account
    # (the removed drop-in) locks out an operator whose interactive login shares this
    # account — the deployment reality — while adding no boundary the forced-command line
    # does not already impose. authorized_keys is read per connection, so there is nothing
    # to validate or reload here.
    print(
        "Done. Certify from the seat: python3 <its-checkout>/bin/provision-board-tools.py "
        f"--role b --certify-only --agent {agent} --project-dir <its-claude-project-dir> "
        "--channel-name <its-mcp-servers-key>"
    )
    return 0


def _append_authorized_key_line(authz: str, line: str) -> None:
    """Append ONE line to `authorized_keys` through an O_NOFOLLOW open.

    ⛔ THE WRITE IS THE HAZARD THIS CLOSES. A path-following append run by root through
    an `authorized_keys` that is a SYMLINK writes the line into whatever it points at —
    an arbitrary root-owned file gaining an ssh key line. There is no way to both refuse
    that and write through the link, so a symlinked `authorized_keys` is the one
    topology this refuses (a symlinked `~/.ssh` is not; see the chown above).
    """
    try:
        fd = os.open(authz, os.O_WRONLY | os.O_CREAT | os.O_APPEND | os.O_NOFOLLOW, 0o600)
    except OSError as e:
        _fail(
            f"could not open {authz} to append the forced-command line: {e}. If it is a "
            f"SYMLINK this is deliberate — writing through it would put an ssh key line "
            f"into the link's target. Replace it with a regular file and re-run."
        )
    with os.fdopen(fd, "a", encoding="utf-8") as fh:
        fh.write(line)


def _pin_authorized_keys_perms(authz: str, uid: int, gid: int, *, chown: bool) -> None:
    """0600 + (root arm only) ownership, both through one O_NOFOLLOW descriptor.

    `fchmod`/`fchown` on a descriptor opened O_NOFOLLOW cannot reach a link's target,
    which is the same defence the append uses. `chown=False` is the self-account arm:
    the file is already the account's, so there is nothing to give it, and a chmod that
    fails there is a real fault rather than something to shrug at — it is named.
    """
    try:
        fd = os.open(authz, os.O_RDONLY | os.O_NOFOLLOW)
    except OSError as e:
        _fail(f"could not open {authz} to set its permissions: {e}")
    try:
        try:
            os.fchmod(fd, 0o600)
        except OSError as e:
            _fail(f"could not chmod 600 {authz}: {e}. Fix it by hand — an authorized_keys sshd rejects for permissions authorizes nothing.")
        if chown:
            os.fchown(fd, uid, gid)
    finally:
        os.close(fd)


# --------------------------------------------------------------------------- #
# Host-B leg (`--role b`) — cross-platform (POSIX + Windows paths implemented)
# --------------------------------------------------------------------------- #
def channel_transport_default(os_name=os.name):
    """The fresh-seat live-wake channel transport, by platform.

    Windows returns "http": Node on Win32 rejects filesystem socket paths (EACCES)
    and the channel server refuses a `unix` transport with no socket (process.exit(2)),
    so `unix` is unusable on a fresh Windows seat — `http` is the only working transport
    (empirically certified, roundtable #145). POSIX returns "unix" (unchanged default).

    Pure and os_name-parameterized so both branches are unit-testable without a real
    Windows host.
    """
    return "http" if os_name == "nt" else "unix"


def read_recorded_ssh_transport(existing_text, channel_name: str) -> dict:
    """The `BRIDGE_TOOLS_SSH_*` env this seat's `.mcp.json` already records.

    Pure over the FILE TEXT so the refusals are unit-testable without a seat. Returns
    the env block; every caller-visible refusal is the caller's, because "provision
    first" is advice about a workflow and this function only knows about a file.
    """
    try:
        doc = json.loads(existing_text)
    except ValueError as e:
        raise ValueError(f"could not be parsed as JSON ({e})")
    if not isinstance(doc, dict) or not isinstance(doc.get("mcpServers"), dict):
        raise ValueError("has no `mcpServers` object")
    entry = doc["mcpServers"].get(channel_name)
    if not isinstance(entry, dict):
        raise ValueError(f"has no `mcpServers.{channel_name}` entry")
    env = entry.get("env")
    if not isinstance(env, dict):
        raise ValueError(f"`mcpServers.{channel_name}` has no `env` block")
    return env


def run_certify_only(args) -> int:
    """`--role b --certify-only`: one real ssh round-trip, using what THIS SEAT recorded.

    ⭐ IT RE-DERIVES NOTHING AND RE-DEPLOYS NOTHING. The seat has already been
    provisioned; what is still unknown at this point is whether the pin on host A
    actually works, and that question is answered by making the call. Reading the target
    and key back out of the seat's own `.mcp.json` — rather than taking them as flags
    again — is what makes the certification a statement about the CONFIGURED transport
    instead of about whatever was typed on this command line.
    ⛔ So `--ssh-target` / `--ssh-key` are REFUSED here rather than honoured: a certify
    run that used a target the channel server does not use would certify the wrong door
    and print a green line for it.
    """
    if not _AGENT_RE.fullmatch(args.agent):
        _fail(f"--agent {args.agent!r} must match ^[a-z0-9_-]+$")
    missing = [n for n in ("project_dir", "channel_name") if getattr(args, n) is None]
    if missing:
        _fail("--role b --certify-only requires " + ", ".join("--" + n.replace("_", "-") for n in missing))
    supplied = [f"--{n.replace('_', '-')}" for n in ("ssh_target", "ssh_key") if getattr(args, n)]
    if supplied:
        _fail(
            f"{' and '.join(supplied)} cannot be given with --certify-only: this mode certifies "
            f"the transport THIS SEAT RECORDED, and the recorded values win. Drop the flag, or "
            f"run a full `--role b` to change what is recorded."
        )

    mcp_path = os.path.join(os.path.abspath(args.project_dir), ".mcp.json")
    if not os.path.isfile(mcp_path):
        _fail(f"{mcp_path} does not exist — provision first (`--role b` without --certify-only).")
    with open(mcp_path, encoding="utf-8") as fh:
        existing_text = fh.read()
    try:
        env = read_recorded_ssh_transport(existing_text, args.channel_name)
    except ValueError as e:
        _fail(f"{mcp_path} {e} — provision first (`--role b` without --certify-only).")

    target = env.get("BRIDGE_TOOLS_SSH_TARGET")
    key = env.get("BRIDGE_TOOLS_SSH_KEY")
    absent = [k for k, v in (("BRIDGE_TOOLS_SSH_TARGET", target), ("BRIDGE_TOOLS_SSH_KEY", key)) if not v]
    if absent:
        # Both are required, and neither is derivable from the other: with no target
        # there is nothing to call, and with no key the round-trip would silently use
        # the seat's DEFAULT ssh identity — a green line for a door this key never opened.
        _fail(
            f"{mcp_path} `mcpServers.{args.channel_name}.env` records no "
            f"{' or '.join(absent)} — provision first (`--role b` without --certify-only)."
        )
    port = env.get("BRIDGE_TOOLS_SSH_PORT")

    key_path, _pub_path, _supplied = _resolve_host_b_key(args, os.path.dirname(str(key)), key_path=str(key))
    _seed_known_hosts(str(target).rsplit("@", 1)[-1], port)
    return _self_cert(str(target), key_path, port)


def run_role_b(args) -> int:
    if not _AGENT_RE.fullmatch(args.agent):
        _fail(f"--agent {args.agent!r} must match ^[a-z0-9_-]+$")

    if os.name == "nt":
        _require_win_openssh()

    home = _host_b_home()
    key_dir = os.path.join(home, ".ssh")
    os.makedirs(key_dir, exist_ok=True)
    key_path, pub_path, operator_supplied = _resolve_host_b_key(args, key_dir)
    # ORDER IS LOAD-BEARING. The permission check runs BEFORE the pair check because
    # `ssh-keygen -y` refuses a group/world-readable key with `bad permissions` — leaving
    # it second would report a correspondence failure for what is really a mode problem.
    _harden_private_key_perms(key_path, rewrite=not operator_supplied)
    _assert_key_pair_corresponds(key_path, pub_path)

    with open(pub_path, encoding="utf-8") as fh:
        pubkey = fh.read().strip("\n")
    if not is_authorized_key_shape(pubkey):
        _fail(f"public key at {pub_path} failed the shape check — refusing to hand it off")

    deploy_dir = os.path.join(os.path.abspath(args.project_dir), ".channel-server")
    mjs_path = os.path.join(deploy_dir, CHANNEL_MJS_BASENAME)
    _deploy_snapshot(deploy_dir)

    # Tools transport keys this provisioner OWNS — force-set (overwrite) on every re-run.
    force_env = {
        "BRIDGE_TOOLS_SSH_TARGET": args.ssh_target,
        # ALWAYS the key this run actually used — never the raw flag value. Recording an
        # unrelated flag path while the leg keyed off a different file is what made the
        # printed key, the pinned key and the recorded key three different things.
        "BRIDGE_TOOLS_SSH_KEY": key_path,
    }
    if args.ssh_port:
        force_env["BRIDGE_TOOLS_SSH_PORT"] = str(args.ssh_port)
    # No `else` branch, and that is not an omission: `force_env` IS the whole declaration
    # of the owned set for this run, and `merge_mcp_json` removes the members it does not
    # carry. Omitting --ssh-port therefore means "no port", which is what the operator
    # said, rather than "keep whatever the last run set".

    # Channel-governing keys the SEAT owns — create-if-absent only, so a board-tools
    # re-provision never rewrites a live-wake channel that already runs the HTTP
    # transport (which would switch the listener transport out from under the seat).
    channel_defaults = {
        "BRIDGE_CHANNEL_TRANSPORT": channel_transport_default(),
        "BRIDGE_CHANNEL_NAME": args.channel_name,
    }

    mcp_path = os.path.join(os.path.abspath(args.project_dir), ".mcp.json")
    existing_text = None
    if os.path.isfile(mcp_path):
        with open(mcp_path, encoding="utf-8") as fh:
            existing_text = fh.read()
    try:
        merged = merge_mcp_json(existing_text, args.channel_name, mjs_path, force_env, channel_defaults)
    except ValueError as e:
        _fail(str(e))
    _install_mcp_json(mcp_path, merged, existing_text)

    # Seed known_hosts BEFORE any --self-cert so --self-cert is a real host-key
    # exercise. The .mjs ssh spawn is BatchMode with no StrictHostKeyChecking, so an
    # unseeded host (incl. the same-box 127.0.0.1) fails closed on the first call.
    _seed_known_hosts(args.ssh_target.rsplit("@", 1)[-1], args.ssh_port)

    print()
    print("Public key for the host-A handoff (paste into `--role a --pubkey-stdin`):")
    print(f"  {pubkey}")
    # ⭐ ON ITS OWN LINE, WITH NO COMMENT AND NO KEY TYPE, and BEFORE the same-box
    # marker the wrapper anchors on. The operator running the pin reads this value off
    # the SEAT and types it into `--expect-fingerprint`, so it has to be the whole line
    # and nothing else — a fingerprint buried in `ssh-keygen -lf` output next to a size
    # and a comment is a value somebody re-types wrong.
    print(f"Fingerprint: {_assert_expected_fingerprint(args.expect_fingerprint, pub_path)}")
    print(f"Same-box: hand this path to `--role a --pubkey-from`:\n  {pub_path}")
    print()
    print("Launch Claude Code with the mandatory per-session dev-channel flag:")
    print(f"  claude --dangerously-load-development-channels server:{args.channel_name}")
    print("(This flag is CLI-only every session — no .mcp.json/settings.json equivalent.)")

    if args.self_cert:
        return _self_cert(args.ssh_target, key_path, args.ssh_port)
    return 0


def _resolve_host_b_key(args, key_dir: str, key_path=None) -> tuple:
    """The ONE derivation of the host-B key pair — every downstream consumer reads it.

    Returns `(key_path, pub_path, operator_supplied)`. The third member is what the
    permission leg keys off: the tool REWRITES the permissions of a key it generated
    and only VERIFIES those of a key the operator named, so provisioning never silently
    re-permissions a file it does not own. It is returned rather than re-derived from
    `args.ssh_key` at the call site, because a second derivation of this condition is
    the exact defect this function exists to remove.

    Without `--ssh-key` the pair is derived from `--agent` and generated if absent.
    With `--ssh-key` the flag NAMES AN EXISTING KEY: both halves must already be on
    disk (no keygen), and that path is what is printed, pinned, self-certified and
    recorded as BRIDGE_TOOLS_SSH_KEY.

    ⭐ `key_path=` IS AN EXPLICIT THIRD CALLER, NOT A SIBLING (card#8971). `--certify-only`
    knows the key from the seat's own recorded `BRIDGE_TOOLS_SSH_KEY`, not from a flag,
    and it needs exactly this function's contract — existing pair, no keygen. Passing it
    through here rather than re-deriving it there is the whole point: the recorded key,
    the printed key and the self-certified key stay ONE value. Only the wording of the
    print and the refusal is conditional on the call site, because "you passed --ssh-key"
    is a false thing to tell someone who did not.
    """
    default_key_path = os.path.join(key_dir, f"{args.agent}-board-tools")
    named = key_path if key_path is not None else args.ssh_key
    from_record = key_path is not None
    if not named:
        _keygen(args.agent, default_key_path)
        return default_key_path, default_key_path + ".pub", False

    resolved = os.path.abspath(os.path.expanduser(named))
    pub_path = resolved + ".pub"
    missing = [p for p in (resolved, pub_path) if not os.path.isfile(p)]
    if missing:
        hint = ""
        if pub_path in missing and os.path.isfile(resolved):
            # The common case: the private half is there and the public half was never
            # kept. It is derivable from the private key, so say how rather than only
            # that it is absent.
            hint = f" The public half can be regenerated: `ssh-keygen -y -f {resolved} > {pub_path}`."
        if from_record:
            _fail(
                f"this seat's .mcp.json records BRIDGE_TOOLS_SSH_KEY={named}, and "
                f"{' and '.join(missing)} {'do' if len(missing) > 1 else 'does'} not exist. "
                f"--certify-only never generates a key.{hint} Re-run a full `--role b` to "
                f"provision this seat, or point BRIDGE_TOOLS_SSH_KEY at the pair that is "
                f"actually on disk."
            )
        _fail(
            f"--ssh-key {named} names an EXISTING key pair to use, and "
            f"{' and '.join(missing)} {'do' if len(missing) > 1 else 'does'} not exist. "
            f"This flag never generates a key.{hint} Drop it to generate + use the default "
            f"pair at {default_key_path} (+ .pub), or point it at a key pair that is already "
            f"on disk."
        )
    source = "recorded in this seat's .mcp.json" if from_record else "named by --ssh-key"
    print(f"using the existing key {source}: {resolved}")
    return resolved, pub_path, True


def _assert_key_pair_corresponds(key_path: str, pub_path: str) -> None:
    """Refuse a `<key>` / `<key>.pub` that are not two halves of ONE key pair.

    Both halves EXISTING is not the contract — the contract is that the public half
    handed to host A unlocks with the private half the channel server presents. A
    mismatched pair pins a key nothing can authenticate with, and the failure surfaces
    later as `Permission denied (publickey)` on a pin the operator watched succeed.
    This is reachable on both paths: `--ssh-key` invites naming arbitrary paths, and on
    the derived path `_keygen` leaves an ALREADY-PRESENT pair untouched.

    The private half must also be passphraseless: the `.mjs` ssh spawn is BatchMode with
    no agent, so an encrypted key cannot be used at call time no matter what is pinned.
    `ssh-keygen -y -P ''` answers both questions at once — it derives the public half
    from the private one, and it fails on a key whose passphrase is not empty.
    """
    try:
        proc = subprocess.run(
            ["ssh-keygen", "-y", "-P", "", "-f", key_path],
            capture_output=True, text=True, timeout=30,
        )
    except (OSError, subprocess.TimeoutExpired) as e:
        _fail(f"could not derive the public half of {key_path} with `ssh-keygen -y`: {e}")

    if proc.returncode != 0:
        stderr = proc.stderr.strip() or "(no stderr)"
        if "passphrase" in stderr.lower():
            _fail(
                f"private key {key_path} is PASSPHRASE-PROTECTED. The channel server spawns "
                f"ssh in BatchMode with no agent, so it can never unlock this key — board "
                f"tools would fail at call time however the pin is set up. Use a "
                f"passphraseless key (drop --ssh-key to have one generated), or strip the "
                f"passphrase with `ssh-keygen -p -f {key_path}`.\nssh-keygen: {stderr}"
            )
        _fail(
            f"`ssh-keygen -y` could not read {key_path} as a private key (exit "
            f"{proc.returncode}) — refusing to hand off a public half it cannot "
            f"vouch for.\nssh-keygen: {stderr}"
        )

    derived = proc.stdout.split()
    with open(pub_path, encoding="utf-8") as fh:
        recorded = fh.read().split()
    # Fields are `<type> <blob> [comment]`. The COMMENT is deliberately not compared:
    # `-y` prints the comment stored in the PRIVATE key, which legitimately differs from
    # the one in the .pub file; only the type and the blob are the key's identity.
    if len(derived) < 2 or len(recorded) < 2 or derived[:2] != recorded[:2]:
        _fail(
            f"{pub_path} is NOT the public half of {key_path} — they are two different "
            f"keys. Pinning it on host A would authorize a key this seat cannot present. "
            f"Regenerate the public half from the private one: "
            f"`ssh-keygen -y -f {key_path} > {pub_path}`."
        )


def _install_mcp_json(mcp_path: str, merged: dict, existing_text) -> None:
    """Serialise + install `.mcp.json` without ever truncating the live file.

    The merged config is serialised into a sibling `.tmp` and compared against what is
    already there: identical content installs nothing (no backup churn on a re-run), a
    difference is backed up to `.bak-<UTC>` before `os.replace` swaps the new file in.
    A failure anywhere in serialise/write leaves the original untouched and removes the
    temp file — the previous in-place `open(mcp_path, "w")` truncated the seat's live
    `.mcp.json` before the first byte of the replacement was serialised.
    """
    # Resolve the link BEFORE anything is written: `os.replace` onto a SYMLINK replaces
    # the link itself with a regular file, silently detaching a seat that keeps its
    # `.mcp.json` in a dotfiles repo and links it into place. Writing through to the
    # target keeps the link, and puts the temp file on the target's own filesystem,
    # which is what makes the replace atomic.
    mcp_path = os.path.realpath(mcp_path)

    tmp_path = mcp_path + ".tmp"
    try:
        fd = os.open(tmp_path, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
        with os.fdopen(fd, "w", encoding="utf-8") as fh:
            json.dump(merged, fh, indent=2)
            fh.write("\n")
            fh.flush()
            os.fsync(fh.fileno())

        with open(tmp_path, encoding="utf-8") as fh:
            new_text = fh.read()
        if existing_text is not None and new_text == existing_text:
            print(f".mcp.json unchanged: {mcp_path}")
            return

        if existing_text is not None:
            backup_path = f"{mcp_path}.bak-{_utc_stamp()}"
            # O_EXCL|O_CREAT with the mode on the OPEN, not a chmod after it: these bytes
            # can carry BRIDGE_CHANNEL_TOKEN, and a create-then-chmod is readable at the
            # umask's mode for the window in between. O_EXCL also makes the same-second
            # name collision a refusal rather than an overwrite of the earlier backup.
            try:
                fd = os.open(backup_path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
            except FileExistsError:
                _fail(
                    f"a backup already exists at {backup_path} — refusing to overwrite it. "
                    f"Two changed writes landed inside the same second; move or remove that "
                    f"file and re-run. Nothing has been changed."
                )
            with os.fdopen(fd, "w", encoding="utf-8") as fh:
                fh.write(existing_text)
            print(f".mcp.json backup of the previous file: {backup_path}")
            # Keep the seat's own mode rather than imposing the temp file's 0600.
            os.chmod(tmp_path, stat.S_IMODE(os.stat(mcp_path).st_mode))
        os.replace(tmp_path, mcp_path)
        print(f".mcp.json merged: {mcp_path}")
    finally:
        if os.path.exists(tmp_path):
            os.unlink(tmp_path)


def _host_b_home() -> str:
    if os.name == "nt":
        # AC-2/AC-3: %USERPROFILE% (not $HOME); no elevation — all writes live here.
        home = os.environ.get("USERPROFILE") or os.path.expanduser("~")
        if not home:
            _fail("%USERPROFILE% is not set — cannot locate the Windows host-B home directory")
        return home
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


def _harden_private_key_perms(key_path: str, rewrite: bool = True) -> None:
    """Enforce chmod-600 semantics on the private key. `rewrite` decides HOW.

    `rewrite=True` (a key this tool generated, at the path it owns): set the mode, then
    assert it. `rewrite=False` (a key the operator named with `--ssh-key`): assert only.
    Provisioning must not silently re-permission a file it does not own — that is the
    same class of unasked mutation as the three this leg was just repaired for — and a
    refusal that says `chmod 600` costs the operator one command while leaving the
    decision theirs. The GUARD is identical in both modes: a group/world-accessible key
    is refused either way, so this is a narrower blast radius, never a weaker check.
    """
    if os.name == "nt":
        _harden_private_key_perms_windows(key_path, rewrite=rewrite)
        return
    if rewrite:
        os.chmod(key_path, 0o600)
    mode = stat.S_IMODE(os.stat(key_path).st_mode)
    if mode & 0o077:
        if not rewrite:
            _fail(
                f"private key {key_path} is group/world-accessible (mode {oct(mode)}) — refusing. "
                f"It was named with --ssh-key, so this tool does not re-permission it: run "
                f"`chmod 600 {key_path}` and re-run. (ssh itself also refuses a key with these "
                f"permissions, so this is the failure you would hit at call time.)"
            )
        _fail(
            f"private key {key_path} is group/world-accessible (mode {oct(mode)}) after chmod 600 — refusing"
        )


def _whoami_user() -> tuple:
    """(account_name, SID) of the invoking Windows user via `whoami /user /fo list`."""
    try:
        proc = subprocess.run(
            ["whoami", "/user", "/fo", "list"], capture_output=True, text=True, timeout=30
        )
    except (OSError, subprocess.TimeoutExpired) as e:
        _fail(f"could not resolve the invoking user's SID (`whoami /user`): {e}")
    if proc.returncode != 0:
        _fail(f"`whoami /user` failed (exit {proc.returncode}): {proc.stderr.strip()}")
    name = sid = None
    for line in proc.stdout.splitlines():
        if ":" not in line:
            continue
        key, val = line.split(":", 1)
        key = key.strip().lower()
        val = val.strip()
        if key == "user name":
            name = val
        elif key == "sid":
            sid = val
    if not sid or not sid.upper().startswith("S-1-"):
        _fail("could not parse a SID from `whoami /user /fo list` output")
    return name, sid


def _lookup_account_sid(principal: str) -> "str | None":
    """Resolve an account name to its SID via the OS — locale-independent, no-op off Windows.

    A LookupAccountName-equivalent: PowerShell's `NTAccount.Translate` asks the LSA, which
    knows the LOCALIZED display name of every account, so `VORDEFINIERT\\Administratoren`
    (de-DE) and `BUILTIN\\Administrators` (en-US) both translate to S-1-5-32-544. Returns the
    SID string, or None when the lookup is unavailable / the name does not resolve (⇒ the
    caller falls back / keeps the name raw ⇒ fail closed). Injection-safe: the principal is
    passed through the environment, never interpolated into the command.
    """
    if os.name != "nt":
        return None
    try:
        proc = subprocess.run(
            [
                "powershell", "-NoProfile", "-NonInteractive", "-Command",
                "([System.Security.Principal.NTAccount]$env:BRIDGE_ACL_PRINCIPAL)"
                ".Translate([System.Security.Principal.SecurityIdentifier]).Value",
            ],
            capture_output=True, text=True, timeout=30,
            env={**os.environ, "BRIDGE_ACL_PRINCIPAL": principal},
        )
    except (OSError, subprocess.SubprocessError):
        return None
    if proc.returncode != 0:
        return None
    out = proc.stdout.strip()
    return out if out.upper().startswith("S-1-") else None


def _make_name_to_sid(owner_name, owner_sid, os_lookup=_lookup_account_sid):
    """A resolver mapping icacls-printed principals to SIDs — locale-independent.

    Resolution order per principal: (1) a raw SID passes through; (2) the owner (known from
    `whoami /user`, the authoritative source of the owner's real SID); (3) the OS lookup
    (`os_lookup`, a LookupAccountName-equivalent) — this is the authoritative, LOCALE-
    INDEPENDENT path, so a localized well-known name (de-DE `Benutzer`, fr-FR `Utilisateurs`)
    still resolves to its fixed well-known SID; (4) the en-US name table as a harmless offline
    fallback for when the OS lookup is unavailable (its well-known en-US name→SID mappings are
    invariant, so they can never disagree with the OS); (5) otherwise the raw name is kept, so
    the SID-pinned decision treats the principal as untrusted (fail closed). Names are folded
    with `.upper()` because `whoami` prints the account lowercase (`pc\\user`) while `icacls`
    prints it uppercase (`PC\\user`). Resolutions are cached so repeat principals cost one lookup.
    """
    en_us_fallback = {
        r"NT AUTHORITY\SYSTEM": SYSTEM_SID,
        r"BUILTIN\ADMINISTRATORS": ADMINISTRATORS_SID,
        r"BUILTIN\USERS": USERS_SID,
        r"NT AUTHORITY\AUTHENTICATED USERS": AUTHENTICATED_USERS_SID,
        "EVERYONE": EVERYONE_SID,
    }
    cache: dict = {}

    def resolve(principal: str) -> str:
        if principal.upper().startswith("S-1-"):
            return principal
        up = principal.upper()
        if owner_name and up == owner_name.upper():
            return owner_sid
        if up in cache:
            return cache[up]
        resolved = os_lookup(principal) or en_us_fallback.get(up, principal)
        cache[up] = resolved
        return resolved

    return resolve


def _enumerate_icacls_aces(path: str, owner_name, owner_sid) -> list:
    try:
        proc = subprocess.run(["icacls", path], capture_output=True, text=True, timeout=30)
    except (OSError, subprocess.TimeoutExpired) as e:
        _fail(f"icacls enumeration of {path} failed: {e}")
    if proc.returncode != 0:
        _fail(f"icacls enumeration of {path} failed (exit {proc.returncode}): {proc.stderr.strip()}")
    return parse_icacls_aces(proc.stdout, path, _make_name_to_sid(owner_name, owner_sid))


def _harden_private_key_perms_windows(key_path: str, rewrite: bool = True) -> None:
    """AC-1: chmod-600 semantics on Windows via icacls, granting the owner by SID.

    `rewrite=True` (a key this tool generated): grant read to the invoking user's SID
    (never `"%USERNAME%":R`, a cmd.exe-ism a non-shell python passes literally), then
    enforce refuse-if-broader over the SID-based ACL. `rewrite=False` (a key named with
    `--ssh-key`): run the SAME refuse-if-broader decisions and change NO ACL. An
    `/inheritance:r` on a file the operator owns is destructive and not undoable from
    what this tool knows — it drops every inherited ACE, including ones the operator's
    own tooling depends on — so a key we were merely POINTED AT is judged, not rewritten.

    ⚠ THE DIRECTORY DECISION RUNS FIRST, before any file ACL is touched. A world/Users
    -writable `.ssh` lets a local attacker swap the key regardless of the file ACL, so
    refusing after having already rewritten the file's ACL would leave the operator with
    a mutated file AND a refusal — the worst of both, and the rewrite is what would have
    to be undone by hand.

    The `ssh.exe -i` round-trip (--self-cert) stays the authoritative perm check; this
    icacls assertion is defense-in-depth. No elevation — all under %USERPROFILE%.
    """
    owner_name, owner_sid = _whoami_user()

    key_dir = os.path.dirname(key_path)
    dir_aces = _enumerate_icacls_aces(key_dir, owner_name, owner_sid)
    if evaluate_key_dir_decision(dir_aces, owner_sid) == "refuse":
        _fail(
            f"the key directory {key_dir} is world/Users-writable — refusing "
            f"(a writable key dir lets a local attacker swap the key regardless of the file ACL)."
        )

    if rewrite:
        # Break inheritance, then grant read to ONLY the invoking user, by SID.
        _run_checked(["icacls", key_path, "/inheritance:r"], "icacls /inheritance:r failed")
        _run_checked(["icacls", key_path, "/grant:r", f"*{owner_sid}:R"], "icacls /grant failed")

    aces = _enumerate_icacls_aces(key_path, owner_name, owner_sid)
    if evaluate_key_acl_decision(aces, owner_sid) == "refuse":
        if not rewrite:
            _fail(
                f"private key {key_path} is readable by a principal beyond "
                f"{{owner, SYSTEM, Administrators}} — refusing. It was named with --ssh-key, "
                f"so this tool does not rewrite its ACL: restrict it yourself (e.g. "
                f"`icacls \"{key_path}\" /inheritance:r /grant:r \"%USERNAME%\":R`) and re-run."
            )
        _fail(
            f"private key {key_path} is readable by a principal beyond "
            f"{{owner, SYSTEM, Administrators}} after the icacls grant — refusing "
            f"(a world/Users-readable private key fails closed; the ssh -i round-trip is authoritative)."
        )

    verb = "restricted to" if rewrite else "verified as readable only by"
    print(
        f"icacls: {key_path} {verb} owner {owner_name} (SID {owner_sid}); "
        f"SYSTEM/Administrators tolerated. The ssh -i round-trip (--self-cert) is the authoritative check."
    )


def _require_win_openssh() -> None:
    """AC-1/Minor: fail closed if the Win32-OpenSSH client isn't installed (parallel to
    the Node precheck) — the whole leg is `-i <key>` over ssh.exe/ssh-keygen.exe, and the
    known_hosts seed shells out to ssh-keyscan bare."""
    for exe in ("ssh.exe", "ssh-keygen.exe", "ssh-keyscan"):
        if shutil.which(exe) is None:
            _fail(
                f"{exe} not found on PATH — install the Windows OpenSSH Client feature "
                f"(Settings > Optional features > OpenSSH Client, or PowerShell "
                f"`Add-WindowsCapability -Online -Name OpenSSH.Client~~~~0.0.1.0`) and re-run"
            )


_LOOPBACK_HOSTS = frozenset({"127.0.0.1", "::1", "localhost"})


def _seed_known_hosts(host: str, port) -> None:
    # AC-6: same append/skip/refuse decision logic as POSIX (resolve_known_hosts_action);
    # only the home path differs — %USERPROFILE%\.ssh\known_hosts on Windows. ssh-keyscan
    # / ssh-keygen resolve to their .exe via CreateProcess on Windows.
    known_hosts = os.path.join(_host_b_home(), ".ssh", "known_hosts")
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
        # Report the true cause (canon #10): an empty scan is not necessarily a network
        # problem — a Win32-OpenSSH PQ-KEX mismatch also presents as empty-scan — so
        # surface ssh-keyscan's own exit code and stderr rather than guessing "unreachable".
        _fail(
            f"ssh-keyscan returned no host keys for {host} (exit {proc.returncode}) — cannot seed "
            f"known_hosts; the first board-tools call would fail closed on host-key verification. "
            f"ssh-keyscan stderr: {proc.stderr.strip() or '(empty)'}"
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

    # ⛔ BEFORE ANY MUTATION, and that ordering is the whole point — not tidiness.
    # This ran after the rename below, so a seat without Node 20 had its channel server
    # renamed aside and THEN hit the precheck: `.mcp.json` still pointed at the original
    # path, nothing was there any more, and the retention message told the operator the
    # tree could be removed. The precheck cannot mutate, so running it first costs a
    # `node --version` and buys a refusal that leaves the seat exactly as it was.
    # `_npm_ci` calls it again on both paths out of here; it is idempotent.
    _require_node_20()

    deployed_pkg = os.path.join(deploy_dir, "package.json")
    if os.path.isfile(deployed_pkg):
        deployed_version = _package_version(deployed_pkg)
        if _version_tuple(deployed_version) >= _version_tuple(bundled_version):
            print(f"channel-server snapshot up to date (deployed {deployed_version} >= bundled {bundled_version}).")
            _npm_ci(deploy_dir)
            return
        print(f"replacing stale snapshot (deployed {deployed_version} < bundled {bundled_version}).")
        # RENAME, never rmtree: this tree is the seat's live channel server. If the
        # copytree/npm-ci that follows fails, a deleted snapshot leaves the seat with no
        # channel server at all and nothing to roll back to.
        stale_dir = f"{deploy_dir}.stale-{_path_safe(deployed_version)}"
        if os.path.exists(stale_dir):
            stale_dir = f"{stale_dir}-{_utc_stamp()}"
        os.rename(deploy_dir, stale_dir)
        print(f"previous snapshot retained at {stale_dir} — nothing was deleted.")
        print(f"  to ROLL BACK, move it back over the deploy dir (POSIX: mv {stale_dir} {deploy_dir})")
        print("  to DISCARD it, delete that path — but only once the new snapshot is confirmed working.")

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


def _npm_argv(args, os_name=os.name):
    """npm on Windows is npm.cmd — a batch script CreateProcess cannot launch by bare name
    (PATHEXT is a shell concept) nor by full path (CreateProcess execs PE binaries only). Route
    through cmd.exe (a real .exe) so it resolves npm.cmd via its own PATHEXT; POSIX runs npm
    directly. Pure + os_name-parameterized so both branches unit-test without a real Windows box."""
    if os_name == "nt":
        return ["cmd", "/c", "npm", *args]
    return ["npm", *args]


def _npm_ci(deploy_dir: str) -> None:
    _require_node_20()
    try:
        subprocess.run(_npm_argv(["ci"]), cwd=deploy_dir, check=True)
    except FileNotFoundError:
        _fail(
            "could not launch npm — the npm executable was not found on PATH. Install Node 20+ "
            "(it bundles npm) and ensure it is on PATH, then re-run. This is an invocation "
            "problem, not a connectivity failure."
        )
    except (OSError, subprocess.CalledProcessError):
        _fail(
            "`npm ci` failed in the deployed snapshot — a missing node_modules is a channel "
            "server that will not start. Fix the underlying npm error reported above (a bad "
            "lockfile, or a network/proxy problem) and re-run (never reported success)."
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
        envelope = json.loads(proc.stdout)
    except ValueError:
        _fail(
            f"--self-cert: ssh {target} returned no parseable JSON envelope "
            f"(exit {proc.returncode}; stderr: {proc.stderr.strip()})"
        )
    is_bad_envelope = not isinstance(envelope, dict) or (
        ("ok" in envelope and not envelope["ok"]) or bool(envelope.get("error"))
    )
    if proc.returncode != 0 or is_bad_envelope:
        _fail(
            f"--self-cert: ssh {target} returned an error envelope "
            f"(exit {proc.returncode}; envelope: {json.dumps(envelope)[:200]})"
        )
    print(f"--self-cert: OK — {target} certified a healthy board_my_cards round-trip.")
    return 0


def _run_checked(cmd, err: str) -> None:
    try:
        subprocess.run(cmd, check=True)
    except (OSError, subprocess.CalledProcessError) as e:
        _fail(f"{err}: {e}")


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
    p.add_argument(
        "--expect-fingerprint",
        help="[both roles] refuse unless the key is this SHA256 fingerprint. Accepts the bare "
        "`SHA256:<b64>` or a whole `ssh-keygen -lf` line. A TRANSCRIPTION guard (right file, "
        "right seat) — it is not what makes the pin safe; the person choosing the key is",
    )
    p.add_argument(
        "--forced-command-timeout",
        type=int,
        default=DEFAULT_FORCED_COMMAND_TIMEOUT,
        help="[role a] hard wall-clock cap (seconds) on each forced-command invocation, "
        "key-scoped; 0 disables (card 5092)",
    )

    # host B
    p.add_argument("--ssh-target", help="[role b] user@host of the bridge box")
    p.add_argument("--ssh-port", type=int, help="[role b] optional ssh port")
    p.add_argument(
        "--ssh-key",
        help="[role b] use this EXISTING key pair instead of generating one from --agent; "
        "both <path> and <path>.pub must already exist (this flag never generates a key). "
        "BRIDGE_TOOLS_SSH_KEY always records the key the run actually used, flag or not",
    )
    p.add_argument("--project-dir", help="[role b] the Claude project dir holding .mcp.json")
    p.add_argument("--channel-name", help="[role b] the mcpServers key / BRIDGE_CHANNEL_NAME")
    # MUTUALLY EXCLUSIVE AT THE PARSER, so the conflict is rc 2 and one message rather
    # than a hand-rolled check that has to be kept in step with the flags.
    cert = p.add_mutually_exclusive_group()
    cert.add_argument("--self-cert", action="store_true", help="[role b] fire one real ssh board_my_cards round-trip")
    cert.add_argument(
        "--certify-only",
        action="store_true",
        help="[role b] ONLY fire that round-trip, using the target and key this seat already "
        "recorded in its .mcp.json — no keygen, no snapshot deploy, no .mcp.json write. Needs "
        "--agent --project-dir --channel-name; --ssh-target/--ssh-key are refused",
    )
    return p


def main(argv=None) -> int:
    args = build_parser().parse_args(argv)
    if args.role == "a":
        return run_role_a(args)
    if args.certify_only:
        # ⭐ THE REQUIRED-ARG SET IS DELIBERATELY NARROWER HERE (card#8971): --ssh-target
        # is where the seat is CONFIGURED to call, and this mode reads that back rather
        # than being told it. run_certify_only() owns its own arg checks for that reason.
        return run_certify_only(args)
    missing = [n for n in ("ssh_target", "project_dir", "channel_name") if getattr(args, n) is None]
    if missing:
        _fail("--role b requires " + ", ".join("--" + n.replace("_", "-") for n in missing))
    return run_role_b(args)


if __name__ == "__main__":
    sys.exit(main())
