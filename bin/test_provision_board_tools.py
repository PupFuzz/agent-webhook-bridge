#!/usr/bin/env python3
"""Unit tests for the two pure functions in provision-board-tools.py (FR #5010).

Each must-fix has at least one case that goes RED if the guard is reverted:
  - M1  full-line pubkey validation (multi-line / CRLF paste rejected)
  - M3  HTTP->SSH re-provision deletes the sibling transport keys
  - merge collision guard + refuse-on-unparseable + create-if-absent
"""

import contextlib
import importlib.util
import io
import json
import os
import shutil
import subprocess
import sys
import tempfile
import unittest
from unittest import mock

_HERE = os.path.dirname(os.path.abspath(__file__))
_spec = importlib.util.spec_from_file_location(
    "provision_board_tools", os.path.join(_HERE, "provision-board-tools.py")
)
pbt = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(pbt)

# The same-box wrapper, loaded the same way: `RoleBHostBLeg` asserts that ITS parser
# survives this leg's real stdout. Loaded by path rather than imported from the wrapper's
# own test module, because bin/ is not a package — `python3 -m unittest
# bin.test_provision_board_tools` (a documented way to run this file) puts the repo root
# on sys.path, not bin/, so a sibling `import` works only under `unittest discover -s bin`.
_sb_spec = importlib.util.spec_from_file_location(
    "provision_board_tools_samebox", os.path.join(_HERE, "provision-board-tools-samebox.py")
)
sbx = importlib.util.module_from_spec(_sb_spec)
# Register before exec so the wrapper's @dataclass can resolve its own module (string
# annotations under `from __future__ import annotations` look the module up in sys.modules).
sys.modules.setdefault("provision_board_tools_samebox", sbx)
_sb_spec.loader.exec_module(sbx)

# A real single-line ECDSA P-256 public key (blob is valid base64, arbitrary content).
_REAL_ECDSA = (
    "ecdsa-sha2-nistp256 AAAAE2VjZHNhLXNoYTItbmlzdHAyNTYAAAAIbmlzdHAyNTYAAABBBABC"
    "def0123456789+/ABCdef0123456789+/ABCdef0123456789+/ABCdef0123456789= agent-board-tools"
)
_IDENTITY = lambda p: p  # noqa: E731 — avoid FS realpath in pure-function tests

# REAL key material, minted once. `_assert_key_pair_corresponds` shells out to
# `ssh-keygen -y`, so a fabricated "PRIVATE KEY BYTES" fixture would only ever exercise
# the refusal arm — the pass arm has to be a genuine pair or it certifies nothing. The
# skip is LOUD: a silently skipped case reads as coverage.
_SSH_KEYGEN = shutil.which("ssh-keygen")
if _SSH_KEYGEN is None:  # pragma: no cover — CI runners all ship openssh-client
    print(
        "\n*** bin/test_provision_board_tools.py: ssh-keygen NOT on PATH — the whole "
        "RoleBHostBLeg class SKIPS. The --ssh-key pair-correspondence arms are NOT "
        "covered by this run. ***\n",
        file=sys.stderr,
    )


def _mint_keypair(stem: str, passphrase: str = "") -> None:
    subprocess.run(
        ["ssh-keygen", "-t", "ecdsa", "-b", "256", "-N", passphrase, "-f", stem,
         "-C", os.path.basename(stem), "-q"],
        check=True, capture_output=True,
    )


class _KeyFixtures:
    """One temp dir of real keys shared by the whole class (keygen is a subprocess)."""

    dir = None
    plain = encrypted = other = None

    @classmethod
    def build(cls):
        if cls.dir is not None:
            return
        cls.dir = tempfile.mkdtemp(prefix="pbt-keys-")
        cls.plain = os.path.join(cls.dir, "plain")
        cls.encrypted = os.path.join(cls.dir, "encrypted")
        cls.other = os.path.join(cls.dir, "other")
        _mint_keypair(cls.plain)
        _mint_keypair(cls.encrypted, passphrase="a-real-passphrase")
        _mint_keypair(cls.other)


class AuthorizedKeyShape(unittest.TestCase):
    def test_real_single_line_ecdsa_accepted(self):
        self.assertTrue(pbt.is_authorized_key_shape(_REAL_ECDSA))

    def test_no_comment_accepted(self):
        keytype, blob, _ = _REAL_ECDSA.split(" ", 2)
        self.assertTrue(pbt.is_authorized_key_shape(f"{keytype} {blob}"))

    def test_ed25519_accepted(self):
        self.assertTrue(pbt.is_authorized_key_shape("ssh-ed25519 AAAAC3NzaC1lZDI1NTE5 comment"))

    # --- M1 red-when-reverted cases --------------------------------------- #
    def test_multiline_paste_rejected(self):
        # The prefix-only bash guard accepts this (first line matches); the full-line
        # validator MUST reject it — the second line would land as an unrestricted key.
        keytype, blob, _ = _REAL_ECDSA.split(" ", 2)
        two_line = f"{keytype} {blob}\nssh-rsa AAAAB3Nz unrestricted-second-key"
        self.assertFalse(pbt.is_authorized_key_shape(two_line))

    def test_crlf_tainted_rejected(self):
        self.assertFalse(pbt.is_authorized_key_shape(_REAL_ECDSA + "\r"))

    def test_trailing_newline_rejected(self):
        self.assertFalse(pbt.is_authorized_key_shape(_REAL_ECDSA + "\n"))

    def test_paste_placeholder_rejected(self):
        self.assertFalse(pbt.is_authorized_key_shape("PASTE HOST-B PUBLIC KEY HERE"))

    def test_unknown_keytype_rejected(self):
        self.assertFalse(pbt.is_authorized_key_shape("ssh-dss AAAAB3Nz comment"))

    def test_non_base64_blob_rejected(self):
        self.assertFalse(pbt.is_authorized_key_shape("ssh-ed25519 not*base64! comment"))

    def test_non_string_rejected(self):
        self.assertFalse(pbt.is_authorized_key_shape(None))
        self.assertFalse(pbt.is_authorized_key_shape(b"ssh-ed25519 AAAA"))


class MergeMcpJson(unittest.TestCase):
    # Mirrors run_role_b's split: tools transport keys are force-set (overwrite);
    # channel-governing keys are create-if-absent (setdefault).
    def _ssh_env(self):
        return {"BRIDGE_TOOLS_SSH_TARGET": "bridge-user@host-A"}

    def _channel_defaults(self):
        return {
            "BRIDGE_CHANNEL_TRANSPORT": "unix",
            "BRIDGE_CHANNEL_NAME": "kanbanboard-agent",
        }

    def test_create_if_absent_writes_full_entry(self):
        out = pbt.merge_mcp_json(
            None, "kanbanboard-agent", "/deploy/agent-webhook-bridge-channel.mjs",
            self._ssh_env(), self._channel_defaults(), resolve=_IDENTITY,
        )
        entry = out["mcpServers"]["kanbanboard-agent"]
        self.assertEqual(entry["command"], "node")
        self.assertEqual(entry["args"], ["/deploy/agent-webhook-bridge-channel.mjs"])
        self.assertEqual(entry["env"]["BRIDGE_TOOLS_SSH_TARGET"], "bridge-user@host-A")
        # Fresh seat: the channel defaults bootstrap a complete, working entry.
        self.assertEqual(entry["env"]["BRIDGE_CHANNEL_TRANSPORT"], "unix")
        self.assertEqual(entry["env"]["BRIDGE_CHANNEL_NAME"], "kanbanboard-agent")

    def test_existing_channel_transport_preserved(self):
        # An existing seat already running the documented _alternative_http_setup HTTP
        # live-wake fallback. A board-tools re-provision must NOT rewrite the channel
        # transport to "unix" (that would kill live-wake), but MUST set the tools target.
        existing = json.dumps({
            "mcpServers": {
                "kanbanboard-agent": {
                    "command": "node",
                    "args": ["/deploy/agent-webhook-bridge-channel.mjs"],
                    "env": {
                        "BRIDGE_CHANNEL_TRANSPORT": "http",
                        "BRIDGE_CHANNEL_NAME": "kanbanboard-agent",
                    },
                }
            }
        })
        out = pbt.merge_mcp_json(
            existing, "kanbanboard-agent", "/deploy/agent-webhook-bridge-channel.mjs",
            self._ssh_env(), self._channel_defaults(), resolve=_IDENTITY,
        )
        env = out["mcpServers"]["kanbanboard-agent"]["env"]
        self.assertEqual(env["BRIDGE_CHANNEL_TRANSPORT"], "http")
        self.assertEqual(env["BRIDGE_TOOLS_SSH_TARGET"], "bridge-user@host-A")

    def test_unparseable_raises(self):
        with self.assertRaises(ValueError):
            pbt.merge_mcp_json(
                "{ not json", "kanbanboard-agent", "/d/agent-webhook-bridge-channel.mjs",
                self._ssh_env(), self._channel_defaults(), resolve=_IDENTITY,
            )

    def test_foreign_server_name_refuses(self):
        existing = json.dumps({
            "mcpServers": {
                "kanbanboard-agent": {
                    "command": "node",
                    "args": ["/some/other/foreign-server.mjs"],
                    "env": {},
                }
            }
        })
        with self.assertRaises(ValueError):
            pbt.merge_mcp_json(
                existing, "kanbanboard-agent", "/d/agent-webhook-bridge-channel.mjs",
                self._ssh_env(), self._channel_defaults(), resolve=_IDENTITY,
            )

    def test_own_prior_entry_after_deploy_move_not_foreign(self):
        # Prior entry points at the OLD deploy path; basename still matches, so it is
        # recognized as our own (not foreign) and updated to the new path.
        existing = json.dumps({
            "mcpServers": {
                "kanbanboard-agent": {
                    "command": "node",
                    "args": ["/old/prod/checkout/agent-webhook-bridge-channel.mjs"],
                    "env": {"BRIDGE_CHANNEL_NAME": "kanbanboard-agent"},
                }
            }
        })
        out = pbt.merge_mcp_json(
            existing, "kanbanboard-agent", "/new/checkout/agent-webhook-bridge-channel.mjs",
            self._ssh_env(), self._channel_defaults(), resolve=_IDENTITY,
        )
        self.assertEqual(
            out["mcpServers"]["kanbanboard-agent"]["args"],
            ["/new/checkout/agent-webhook-bridge-channel.mjs"],
        )

    def test_preserves_unrelated_keys_and_servers(self):
        existing = json.dumps({
            "_comment": "keep me",
            "mcpServers": {
                "other-server": {"command": "node", "args": ["/x/other.mjs"]},
            },
        })
        out = pbt.merge_mcp_json(
            existing, "kanbanboard-agent", "/d/agent-webhook-bridge-channel.mjs",
            self._ssh_env(), self._channel_defaults(), resolve=_IDENTITY,
        )
        self.assertEqual(out["_comment"], "keep me")
        self.assertIn("other-server", out["mcpServers"])
        self.assertIn("kanbanboard-agent", out["mcpServers"])

    # --- M3 red-when-reverted case ---------------------------------------- #
    def test_reprovision_without_the_port_flag_removes_a_previously_set_port(self):
        # RED-when-reverted (update-only merge): `_PORT` is OPTIONAL, so it is written
        # only when --ssh-port is passed — and an update()-only merge cannot express "this
        # run has no port". A seat provisioned once with --ssh-port 2222 kept it forever
        # and the channel server spawned `ssh -p 2222` on every real call, against an
        # invocation that never mentioned a port.
        existing = json.dumps({"mcpServers": {"chan": {"command": "node", "args": ["/x/" + pbt.CHANNEL_MJS_BASENAME],
                              "env": {"BRIDGE_TOOLS_SSH_TARGET": "u@h", "BRIDGE_TOOLS_SSH_KEY": "/k",
                                      "BRIDGE_TOOLS_SSH_PORT": "2222"}}}})
        out = pbt.merge_mcp_json(
            existing, "chan", "/x/" + pbt.CHANNEL_MJS_BASENAME,
            {"BRIDGE_TOOLS_SSH_TARGET": "u@h", "BRIDGE_TOOLS_SSH_KEY": "/k"},
            resolve=_IDENTITY,
        )
        self.assertNotIn("BRIDGE_TOOLS_SSH_PORT", out["mcpServers"]["chan"]["env"])

    def test_a_port_the_run_DOES_declare_is_still_written(self):
        # The control for the case above: without it, a merge that deleted the whole
        # owned set unconditionally would pass.
        out = pbt.merge_mcp_json(
            None, "chan", "/x/" + pbt.CHANNEL_MJS_BASENAME,
            {"BRIDGE_TOOLS_SSH_TARGET": "u@h", "BRIDGE_TOOLS_SSH_KEY": "/k",
             "BRIDGE_TOOLS_SSH_PORT": "2222"},
            resolve=_IDENTITY,
        )
        self.assertEqual(out["mcpServers"]["chan"]["env"]["BRIDGE_TOOLS_SSH_PORT"], "2222")

    def test_the_owned_set_reconcile_leaves_seat_owned_channel_keys_alone(self):
        # The removal is scoped to SSH_TOOLS_KEYS: a channel var the seat owns must not be
        # collateral, or the fix re-mints the clobber the two env classes exist to prevent.
        existing = json.dumps({"mcpServers": {"chan": {"command": "node", "args": ["/x/" + pbt.CHANNEL_MJS_BASENAME],
                              "env": {"BRIDGE_TOOLS_SSH_PORT": "2222", "BRIDGE_CHANNEL_TRANSPORT": "http",
                                      "BRIDGE_CHANNEL_TOKEN": "keep-me"}}}})
        out = pbt.merge_mcp_json(
            existing, "chan", "/x/" + pbt.CHANNEL_MJS_BASENAME,
            {"BRIDGE_TOOLS_SSH_TARGET": "u@h", "BRIDGE_TOOLS_SSH_KEY": "/k"},
            {"BRIDGE_CHANNEL_TRANSPORT": "unix"},
            resolve=_IDENTITY,
        )
        env = out["mcpServers"]["chan"]["env"]
        self.assertNotIn("BRIDGE_TOOLS_SSH_PORT", env)
        self.assertEqual(env["BRIDGE_CHANNEL_TRANSPORT"], "http")
        self.assertEqual(env["BRIDGE_CHANNEL_TOKEN"], "keep-me")

    def test_http_to_ssh_reprovision_deletes_sibling_transport_keys(self):
        existing = json.dumps({
            "mcpServers": {
                "kanbanboard-agent": {
                    "command": "node",
                    "args": ["/deploy/agent-webhook-bridge-channel.mjs"],
                    "env": {
                        "BRIDGE_CHANNEL_NAME": "kanbanboard-agent",
                        "BRIDGE_CHANNEL_TOKEN": "keep-this-channel-bearer",
                        "BRIDGE_TOOLS_ENDPOINT": "http://127.0.0.1:8790/agent-tools/call",
                        "BRIDGE_TOOLS_TOKEN": "old-http-bearer",
                        "BRIDGE_TOOLS_TOKEN_FILE": "/secrets/old-tools-token",
                    },
                }
            }
        })
        out = pbt.merge_mcp_json(
            existing, "kanbanboard-agent", "/deploy/agent-webhook-bridge-channel.mjs",
            self._ssh_env(), self._channel_defaults(), resolve=_IDENTITY,
        )
        env = out["mcpServers"]["kanbanboard-agent"]["env"]
        self.assertEqual(env["BRIDGE_TOOLS_SSH_TARGET"], "bridge-user@host-A")
        self.assertEqual(env["BRIDGE_CHANNEL_TOKEN"], "keep-this-channel-bearer")
        self.assertNotIn("BRIDGE_TOOLS_ENDPOINT", env)
        self.assertNotIn("BRIDGE_TOOLS_TOKEN", env)
        self.assertNotIn("BRIDGE_TOOLS_TOKEN_FILE", env)


class KnownHostsAction(unittest.TestCase):
    # ssh-keyscan -H output lines carry HASHED hostspecs; the decision correlates by
    # KEY material, so plaintext existing entries are enough to exercise the logic.
    _SCAN_ECDSA_NEW = "|1|c2FsdA==|aGFzaA== ecdsa-sha2-nistp256 AAAAnewECDSAkey"
    _SCAN_ED25519 = "|1|c2FsdA==|aGFzaA== ssh-ed25519 AAAAed25519key"

    def test_fresh_seat_appends(self):
        self.assertEqual(
            pbt.resolve_known_hosts_action("", "host-A", None, [self._SCAN_ECDSA_NEW]),
            "append",
        )

    def test_new_keytype_appends(self):
        existing = "host-A ecdsa-sha2-nistp256 AAAAnewECDSAkey\n"
        self.assertEqual(
            pbt.resolve_known_hosts_action(existing, "host-A", None, [self._SCAN_ED25519]),
            "append",
        )

    def test_same_key_skips(self):
        existing = "host-A ecdsa-sha2-nistp256 AAAAnewECDSAkey\n"
        self.assertEqual(
            pbt.resolve_known_hosts_action(existing, "host-A", None, [self._SCAN_ECDSA_NEW]),
            "skip",
        )

    def test_loopback_same_key_skips(self):
        existing = "127.0.0.1 ecdsa-sha2-nistp256 AAAAnewECDSAkey\n"
        self.assertEqual(
            pbt.resolve_known_hosts_action(existing, "127.0.0.1", None, [self._SCAN_ECDSA_NEW]),
            "skip",
        )

    def test_nonstandard_port_matches_bracketed_host(self):
        existing = "[host-A]:2222 ecdsa-sha2-nistp256 AAAAnewECDSAkey\n"
        self.assertEqual(
            pbt.resolve_known_hosts_action(existing, "host-A", 2222, [self._SCAN_ECDSA_NEW]),
            "skip",
        )

    def test_empty_scan_raises(self):
        with self.assertRaises(ValueError):
            pbt.resolve_known_hosts_action("", "host-A", None, [])

    # --- AC-6 red-when-reverted case -------------------------------------- #
    def test_changed_host_key_refuses(self):
        # Same key type (ecdsa), DIFFERENT key — host identity changed / possible MITM.
        # Reverting the decision to blind-append makes this return "append" → RED.
        existing = "host-A ecdsa-sha2-nistp256 AAAAoldECDSAkey\n"
        self.assertEqual(
            pbt.resolve_known_hosts_action(existing, "host-A", None, [self._SCAN_ECDSA_NEW]),
            "refuse",
        )

    def test_hashed_existing_entry_matched(self):
        # A real hashed known_hosts line for "host-A" (salt+HMAC computed here) must be
        # recognized as the same host, so a matching scan skips.
        import base64 as _b64
        import hashlib as _h
        import hmac as _hm

        salt = b"sixteen-byte-slt"
        digest = _hm.new(salt, b"host-A", _h.sha1).digest()
        hostspec = "|1|" + _b64.b64encode(salt).decode() + "|" + _b64.b64encode(digest).decode()
        existing = f"{hostspec} ecdsa-sha2-nistp256 AAAAnewECDSAkey\n"
        self.assertEqual(
            pbt.resolve_known_hosts_action(existing, "host-A", None, [self._SCAN_ECDSA_NEW]),
            "skip",
        )


class AllowlistRegexes(unittest.TestCase):
    """Test the three allowlist regexes using .fullmatch() (not .match()).

    The trailing-newline reject assertions are load-bearing: if the code is
    reverted to .match(), Python's $ matches before a final newline and these
    tests FAIL.
    """

    # --- _AGENT_RE accept cases ------------------------------------------- #
    def test_agent_re_accepts_kanban_solo(self):
        self.assertIsNotNone(pbt._AGENT_RE.fullmatch("kanban-solo"))

    def test_agent_re_accepts_abc_123(self):
        self.assertIsNotNone(pbt._AGENT_RE.fullmatch("abc_123"))

    def test_agent_re_accepts_single_char(self):
        self.assertIsNotNone(pbt._AGENT_RE.fullmatch("x"))

    # --- _AGENT_RE reject cases ------------------------------------------- #
    def test_agent_re_rejects_semicolon(self):
        self.assertIsNone(pbt._AGENT_RE.fullmatch("foo;bar"))

    def test_agent_re_rejects_space(self):
        self.assertIsNone(pbt._AGENT_RE.fullmatch("a b"))

    def test_agent_re_rejects_empty(self):
        self.assertIsNone(pbt._AGENT_RE.fullmatch(""))

    def test_agent_re_rejects_trailing_newline(self):
        """RED-when-reverted: .match() accepts this."""
        self.assertIsNone(pbt._AGENT_RE.fullmatch("kanban-solo\n"))

    # --- _ARTISAN_RE accept cases ----------------------------------------- #
    def test_artisan_re_accepts_absolute_path(self):
        self.assertIsNotNone(pbt._ARTISAN_RE.fullmatch("/opt/bridge/artisan"))

    def test_artisan_re_accepts_relative_name(self):
        self.assertIsNotNone(pbt._ARTISAN_RE.fullmatch("artisan"))

    def test_artisan_re_accepts_complex_path(self):
        self.assertIsNotNone(pbt._ARTISAN_RE.fullmatch("a-b_c./d"))

    # --- _ARTISAN_RE reject cases ----------------------------------------- #
    def test_artisan_re_rejects_semicolon(self):
        self.assertIsNone(pbt._ARTISAN_RE.fullmatch("/fo;o/artisan"))

    def test_artisan_re_rejects_space(self):
        self.assertIsNone(pbt._ARTISAN_RE.fullmatch("a b"))

    def test_artisan_re_rejects_trailing_newline(self):
        """RED-when-reverted: .match() accepts this."""
        self.assertIsNone(pbt._ARTISAN_RE.fullmatch("/opt/bridge/artisan\n"))

    # --- _SSH_ACCOUNT_RE accept cases -------------------------------------- #
    def test_ssh_account_re_accepts_bridge_user(self):
        self.assertIsNotNone(pbt._SSH_ACCOUNT_RE.fullmatch("bridge-user"))

    def test_ssh_account_re_accepts_underscore_prefix(self):
        self.assertIsNotNone(pbt._SSH_ACCOUNT_RE.fullmatch("_svc"))

    def test_ssh_account_re_accepts_single_char(self):
        self.assertIsNotNone(pbt._SSH_ACCOUNT_RE.fullmatch("a"))

    # --- _SSH_ACCOUNT_RE reject cases -------------------------------------- #
    def test_ssh_account_re_rejects_parent_dir(self):
        self.assertIsNone(pbt._SSH_ACCOUNT_RE.fullmatch("../etc"))

    def test_ssh_account_re_rejects_path_slash(self):
        self.assertIsNone(pbt._SSH_ACCOUNT_RE.fullmatch("a/b"))

    def test_ssh_account_re_rejects_leading_digit(self):
        self.assertIsNone(pbt._SSH_ACCOUNT_RE.fullmatch("1abc"))

    def test_ssh_account_re_rejects_empty(self):
        self.assertIsNone(pbt._SSH_ACCOUNT_RE.fullmatch(""))

    def test_ssh_account_re_rejects_trailing_newline(self):
        """RED-when-reverted: .match() accepts this."""
        self.assertIsNone(pbt._SSH_ACCOUNT_RE.fullmatch("bridge-user\n"))


class ChannelTransportDefault(unittest.TestCase):
    # RED-when-reverted: a bare `return "unix"` fails the "nt" case; a flip fails "posix".
    def test_windows_defaults_to_http(self):
        self.assertEqual(pbt.channel_transport_default("nt"), "http")

    def test_posix_defaults_to_unix(self):
        self.assertEqual(pbt.channel_transport_default("posix"), "unix")


class WindowsKeyAclDecision(unittest.TestCase):
    """AC-1: the pure SID-based refuse-if-broader decision over a parsed icacls ACL.

    Cannot run the icacls wiring on Linux (canon #9), so the DECISION is factored out
    and tested directly. The refuse cases are RED-when-reverted: a decision reverted to
    blind-"ok" turns every refuse case green.
    """

    _OWNER = "S-1-5-21-1111111111-2222222222-3333333333-1001"

    def test_owner_only_ok(self):
        aces = [(self._OWNER, {"R"})]
        self.assertEqual(pbt.evaluate_key_acl_decision(aces, self._OWNER), "ok")

    def test_owner_plus_system_and_administrators_ok(self):
        # Win32-OpenSSH tolerates SYSTEM + Administrators — compatibility, not a hole.
        aces = [
            (self._OWNER, {"R"}),
            (pbt.SYSTEM_SID, {"F"}),
            (pbt.ADMINISTRATORS_SID, {"F"}),
        ]
        self.assertEqual(pbt.evaluate_key_acl_decision(aces, self._OWNER), "ok")

    def test_owner_full_control_ok(self):
        aces = [(self._OWNER, {"F"})]
        self.assertEqual(pbt.evaluate_key_acl_decision(aces, self._OWNER), "ok")

    # --- AC-1 refuse (RED-when-reverted) cases ---------------------------- #
    def test_users_readable_refuses(self):
        aces = [(self._OWNER, {"R"}), (pbt.USERS_SID, {"RX"})]
        self.assertEqual(pbt.evaluate_key_acl_decision(aces, self._OWNER), "refuse")

    def test_everyone_readable_refuses(self):
        aces = [(self._OWNER, {"F"}), (pbt.EVERYONE_SID, {"R"})]
        self.assertEqual(pbt.evaluate_key_acl_decision(aces, self._OWNER), "refuse")

    def test_broader_after_grant_refuses(self):
        # A foreign domain user with read survived the grant — the exact broader-after-grant
        # case AC-1's refuse-if-broader exists to close.
        foreign = "S-1-5-21-1111111111-2222222222-3333333333-1105"
        aces = [(self._OWNER, {"R"}), (foreign, {"R"})]
        self.assertEqual(pbt.evaluate_key_acl_decision(aces, self._OWNER), "refuse")

    def test_authenticated_users_readable_refuses(self):
        aces = [(self._OWNER, {"R"}), (pbt.AUTHENTICATED_USERS_SID, {"M"})]
        self.assertEqual(pbt.evaluate_key_acl_decision(aces, self._OWNER), "refuse")

    def test_write_only_non_owner_does_not_trip_read_gate(self):
        # A non-owner with WRITE-only (no read right) does not make the key READABLE;
        # the read gate is about disclosure. (Dir-writability is a separate decision.)
        other = "S-1-5-21-1111111111-2222222222-3333333333-1106"
        aces = [(self._OWNER, {"R"}), (other, {"W"})]
        self.assertEqual(pbt.evaluate_key_acl_decision(aces, self._OWNER), "ok")

    # --- fail-closed-on-ambiguity refuse cases (RED-when-reverted) -------- #
    def test_empty_ace_list_refuses(self):
        # A vacuous accept over an empty/unparsed ACL cannot be certified safe.
        self.assertEqual(pbt.evaluate_key_acl_decision([], self._OWNER), "refuse")

    def test_non_allowed_hex_mask_token_refuses(self):
        # A non-allowed principal holding a rights token icacls rendered as a hex mask
        # (unknown to our classifier) might confer read — fail closed.
        other = "S-1-5-21-1111111111-2222222222-3333333333-1107"
        aces = [(self._OWNER, {"R"}), (other, {"0X1200A9"})]
        self.assertEqual(pbt.evaluate_key_acl_decision(aces, self._OWNER), "refuse")

    def test_full_allowed_set_still_ok(self):
        # The exact ACL the hardening (/inheritance:r + /grant:r *SID:R) produces —
        # proves the fail-closed additions did not flip a legit accept.
        aces = [
            (self._OWNER, {"R"}),
            (pbt.SYSTEM_SID, {"F"}),
            (pbt.ADMINISTRATORS_SID, {"F"}),
        ]
        self.assertEqual(pbt.evaluate_key_acl_decision(aces, self._OWNER), "ok")


class WindowsKeyDirDecision(unittest.TestCase):
    """aimla Minor: refuse a world/Users-WRITABLE .ssh directory."""

    _OWNER = "S-1-5-21-1111111111-2222222222-3333333333-1001"

    def test_owner_only_dir_ok(self):
        aces = [(self._OWNER, {"F"}), (pbt.SYSTEM_SID, {"F"})]
        self.assertEqual(pbt.evaluate_key_dir_decision(aces, self._OWNER), "ok")

    def test_users_read_only_dir_ok(self):
        # Users with READ (no write) on the dir is not the swap-the-key exposure.
        aces = [(self._OWNER, {"F"}), (pbt.USERS_SID, {"RX"})]
        self.assertEqual(pbt.evaluate_key_dir_decision(aces, self._OWNER), "ok")

    # --- RED-when-reverted refuse cases ----------------------------------- #
    def test_users_writable_dir_refuses(self):
        aces = [(self._OWNER, {"F"}), (pbt.USERS_SID, {"W"})]
        self.assertEqual(pbt.evaluate_key_dir_decision(aces, self._OWNER), "refuse")

    def test_everyone_writable_dir_refuses(self):
        aces = [(self._OWNER, {"F"}), (pbt.EVERYONE_SID, {"M"})]
        self.assertEqual(pbt.evaluate_key_dir_decision(aces, self._OWNER), "refuse")

    def test_untrusted_writer_hex_mask_token_refuses(self):
        # An untrusted writer holding an unknown hex-mask token might confer write —
        # fail closed (write-side sibling of the key-decision unknown-token hardening).
        aces = [(self._OWNER, {"F"}), (pbt.USERS_SID, {"0X1301BF"})]
        self.assertEqual(pbt.evaluate_key_dir_decision(aces, self._OWNER), "refuse")


class ParseIcaclsAces(unittest.TestCase):
    """Parse real-shaped `icacls <path>` output into (sid, rights) — the impure/pure seam.

    Uses a name->SID resolver that mirrors _make_name_to_sid (well-known + owner; unknown
    keeps the raw name so the decision fails closed).
    """

    _OWNER = "S-1-5-21-1111111111-2222222222-3333333333-1001"
    _PATH = r"C:\Users\me\.ssh\kanban-solo-board-tools"

    def _resolver(self):
        return pbt._make_name_to_sid(r"DESKTOP-ABC\me", self._OWNER)

    def test_parses_owner_only_grant(self):
        out = (
            self._PATH + r" DESKTOP-ABC\me:(R)" + "\n"
            "\n"
            "Successfully processed 1 files; Failed processing 0 files.\n"
        )
        aces = pbt.parse_icacls_aces(out, self._PATH, self._resolver())
        self.assertEqual(aces, [(self._OWNER, {"R"})])
        self.assertEqual(pbt.evaluate_key_acl_decision(aces, self._OWNER), "ok")

    def test_parses_multi_ace_with_inheritance_flags(self):
        # SYSTEM/Administrators carry (I)(F) inherited groups; the flag tokens (I) must
        # NOT be read as a right. A stray Users:(RX) must make the whole ACL refuse.
        out = (
            self._PATH + r" DESKTOP-ABC\me:(R)" + "\n"
            r"                     NT AUTHORITY\SYSTEM:(I)(F)" + "\n"
            r"                     BUILTIN\Administrators:(I)(F)" + "\n"
            r"                     BUILTIN\Users:(I)(RX)" + "\n"
            "\n"
            "Successfully processed 1 files; Failed processing 0 files.\n"
        )
        aces = pbt.parse_icacls_aces(out, self._PATH, self._resolver())
        by_sid = dict(aces)
        self.assertEqual(by_sid[self._OWNER], {"R"})
        self.assertEqual(by_sid[pbt.SYSTEM_SID], {"F"})  # (I) dropped
        self.assertEqual(by_sid[pbt.USERS_SID], {"RX"})
        self.assertEqual(pbt.evaluate_key_acl_decision(aces, self._OWNER), "refuse")

    def test_unresolvable_principal_kept_raw_and_fails_closed(self):
        out = (
            self._PATH + r" DESKTOP-ABC\me:(R)" + "\n"
            r"                     DESKTOP-ABC\attacker:(R)" + "\n"
        )
        aces = pbt.parse_icacls_aces(out, self._PATH, self._resolver())
        # The unknown principal keeps its raw name (not a tolerated SID) -> refuse.
        self.assertIn((r"DESKTOP-ABC\attacker", {"R"}), aces)
        self.assertEqual(pbt.evaluate_key_acl_decision(aces, self._OWNER), "refuse")

    def test_raw_sid_principal_passed_through(self):
        # icacls prints an unresolvable principal as its bare SID string; parse it as one.
        out = self._PATH + " " + pbt.SYSTEM_SID + ":(F)\n"
        aces = pbt.parse_icacls_aces(out, self._PATH, self._resolver())
        self.assertEqual(aces, [(pbt.SYSTEM_SID, {"F"})])


class NameToSidResolver(unittest.TestCase):
    _OWNER = "S-1-5-21-1111111111-2222222222-3333333333-1001"

    def test_owner_name_maps_to_owner_sid(self):
        r = pbt._make_name_to_sid(r"DESKTOP-ABC\me", self._OWNER)
        self.assertEqual(r(r"DESKTOP-ABC\me"), self._OWNER)

    def test_well_known_names_map_to_fixed_sids(self):
        r = pbt._make_name_to_sid(r"DESKTOP-ABC\me", self._OWNER)
        self.assertEqual(r(r"NT AUTHORITY\SYSTEM"), pbt.SYSTEM_SID)
        self.assertEqual(r(r"BUILTIN\Administrators"), pbt.ADMINISTRATORS_SID)
        self.assertEqual(r("Everyone"), pbt.EVERYONE_SID)

    def test_unknown_name_kept_raw(self):
        r = pbt._make_name_to_sid(r"DESKTOP-ABC\me", self._OWNER)
        self.assertEqual(r(r"DESKTOP-ABC\someone-else"), r"DESKTOP-ABC\someone-else")

    def test_raw_sid_passed_through(self):
        r = pbt._make_name_to_sid(None, self._OWNER)
        self.assertEqual(r("S-1-5-32-544"), "S-1-5-32-544")

    def test_owner_case_asymmetry_whoami_lower_icacls_upper(self):
        # whoami prints the account LOWERCASE (`pc\user`); icacls prints it UPPERCASE
        # (`PC\user`). _make_name_to_sid folds BOTH sides with .upper() so the owner
        # substitution survives that asymmetry. RED-when-reverted: drop either .upper()
        # and the icacls-cased owner no longer maps to owner_sid — it is kept raw, so the
        # ACL decision sees an "untrusted reader" holding R and refuses the real owner.
        r = pbt._make_name_to_sid(r"desktop-abc\me", self._OWNER)  # whoami-cased (lower)
        self.assertEqual(r(r"DESKTOP-ABC\me"), self._OWNER)        # icacls-cased (upper)
        aces = [(r(r"DESKTOP-ABC\me"), {"R"})]
        self.assertEqual(pbt.evaluate_key_acl_decision(aces, self._OWNER), "ok")


class SelfCert(unittest.TestCase):
    """--self-cert must certify the ROUND-TRIP, not just that stdout is JSON.

    An error envelope returned at a non-zero exit code is a FAILED probe; the
    pre-fix code parsed it as JSON and printed OK, so the check could not fail.
    RED-when-reverted: drop the returncode / ok / error gate and the error-envelope
    cases below stop raising SystemExit.
    """

    def _run(self, stdout, returncode):
        completed = mock.Mock(stdout=stdout, stderr="", returncode=returncode)
        with mock.patch.object(pbt.subprocess, "run", return_value=completed):
            return pbt._self_cert("agent@host", None, None)

    def test_error_envelope_at_nonzero_exit_fails(self):
        with self.assertRaises(SystemExit):
            self._run(json.dumps({"ok": False, "error": "unknown agent"}), 2)

    def test_ok_false_at_exit_zero_fails(self):
        with self.assertRaises(SystemExit):
            self._run(json.dumps({"ok": False}), 0)

    def test_nonempty_error_at_exit_zero_fails(self):
        with self.assertRaises(SystemExit):
            self._run(json.dumps({"error": "boom"}), 0)

    def test_parseable_json_at_nonzero_exit_fails(self):
        # A well-formed envelope with no ok/error keys but a non-zero exit is still
        # a failed round-trip — the returncode gate alone must catch the exit-2 case.
        with self.assertRaises(SystemExit):
            self._run(json.dumps({"cards": []}), 2)

    def test_unparseable_stdout_fails(self):
        with self.assertRaises(SystemExit):
            self._run("not json", 0)

    def test_bare_list_at_exit_zero_fails(self):
        # A real board_my_cards envelope is always a dict; a bare list is parseable
        # JSON but not a valid envelope, so it must not certify.
        with self.assertRaises(SystemExit):
            self._run(json.dumps([]), 0)

    def test_json_string_at_exit_zero_fails(self):
        with self.assertRaises(SystemExit):
            self._run(json.dumps("ok"), 0)

    def test_json_number_at_exit_zero_fails(self):
        with self.assertRaises(SystemExit):
            self._run(json.dumps(0), 0)

    def test_success_envelope_passes(self):
        self.assertEqual(
            self._run(json.dumps({"ok": True, "cards": []}), 0), 0
        )

    def test_ok_absent_error_empty_at_exit_zero_passes(self):
        # No ok/error keys, clean exit -> the pre-fix "parseable envelope" pass holds.
        self.assertEqual(self._run(json.dumps({"cards": []}), 0), 0)


class LocaleIndependentSidResolution(unittest.TestCase):
    """card#5053: the ACL decision is locale-independent — a LOCALIZED Windows prints its
    well-known accounts under localized names (de-DE `Benutzer`/`Administratoren`), which the
    en-US name table never matched, so a legitimate ACL was spuriously REFUSED. The OS lookup
    (a LookupAccountName-equivalent, mocked here) resolves those localized names to their fixed
    well-known SIDs, so the SID-pinned decision accepts the legit ACL while still failing closed.
    """

    _OWNER = "S-1-5-21-1111111111-2222222222-3333333333-1001"
    _PATH = r"C:\Users\me\.ssh\kanban-solo-board-tools"

    # A de-DE Windows: icacls prints these localized names; the LSA (mocked) still maps them
    # to the invariant well-known SIDs. `SYSTEM` happens to be un-localized; the BUILTIN group
    # and the domain qualifier are localized (`VORDEFINIERT` = "BUILTIN", `NT-AUTORITÄT`).
    _DE = {
        r"VORDEFINIERT\Administratoren": pbt.ADMINISTRATORS_SID,
        r"NT-AUTORITÄT\SYSTEM": pbt.SYSTEM_SID,
        r"VORDEFINIERT\Benutzer": pbt.USERS_SID,
    }

    def _de_lookup(self, calls=None):
        def lookup(principal):
            if calls is not None:
                calls.append(principal)
            return self._DE.get(principal)
        return lookup

    def _resolver(self, os_lookup):
        return pbt._make_name_to_sid(r"PC\me", self._OWNER, os_lookup=os_lookup)

    def test_localized_legit_acl_accepted(self):
        # owner + localized Administrators + localized SYSTEM — the exact tolerated set, but
        # under de-DE names. RED-when-reverted: revert _make_name_to_sid to the en-US table
        # only and the localized names stay raw (untrusted readers) -> the decision REFUSES.
        out = (
            self._PATH + r" PC\me:(R)" + "\n"
            r"                     VORDEFINIERT\Administratoren:(I)(F)" + "\n"
            r"                     NT-AUTORITÄT\SYSTEM:(I)(F)" + "\n"
            "\n"
            "Successfully processed 1 files; Failed processing 0 files.\n"
        )
        aces = pbt.parse_icacls_aces(out, self._PATH, self._resolver(self._de_lookup()))
        by_sid = dict(aces)
        self.assertEqual(by_sid[pbt.ADMINISTRATORS_SID], {"F"})
        self.assertEqual(by_sid[pbt.SYSTEM_SID], {"F"})
        self.assertEqual(pbt.evaluate_key_acl_decision(aces, self._OWNER), "ok")

    def test_localized_users_reader_still_refuses(self):
        # Fail-closed preserved: a genuinely-broad ACL (localized `Benutzer` = Users, readable)
        # resolves to USERS_SID and is NOT in the tolerated set -> refuse, even though we could
        # resolve the localized name. Locale independence does not soften the decision.
        out = (
            self._PATH + r" PC\me:(R)" + "\n"
            r"                     VORDEFINIERT\Benutzer:(I)(RX)" + "\n"
        )
        aces = pbt.parse_icacls_aces(out, self._PATH, self._resolver(self._de_lookup()))
        self.assertIn((pbt.USERS_SID, {"RX"}), aces)
        self.assertEqual(pbt.evaluate_key_acl_decision(aces, self._OWNER), "refuse")

    def test_unresolvable_localized_principal_refuses(self):
        # The OS lookup cannot resolve the name and it is absent from the en-US fallback ->
        # kept raw -> untrusted reader -> refuse (fail closed on an unknown principal).
        out = (
            self._PATH + r" PC\me:(R)" + "\n"
            r"                     PC\Angreifer:(R)" + "\n"
        )
        aces = pbt.parse_icacls_aces(out, self._PATH, self._resolver(lambda _p: None))
        self.assertIn((r"PC\Angreifer", {"R"}), aces)
        self.assertEqual(pbt.evaluate_key_acl_decision(aces, self._OWNER), "refuse")

    def test_os_lookup_is_authoritative_over_en_us_table(self):
        # A resolver whose OS lookup returns a SID resolves via that lookup — the authoritative,
        # locale-independent path — for a name the en-US table does not carry.
        r = self._resolver(self._de_lookup())
        self.assertEqual(r(r"VORDEFINIERT\Administratoren"), pbt.ADMINISTRATORS_SID)

    def test_en_us_table_is_harmless_offline_fallback(self):
        # OS lookup unavailable (returns None) -> the invariant en-US name table still resolves
        # well-known accounts, preserving the pre-existing en-US behavior with no OS dependency.
        r = self._resolver(lambda _p: None)
        self.assertEqual(r(r"NT AUTHORITY\SYSTEM"), pbt.SYSTEM_SID)
        self.assertEqual(r(r"BUILTIN\Administrators"), pbt.ADMINISTRATORS_SID)

    def test_resolution_is_cached_per_principal(self):
        # A repeated principal costs exactly one OS lookup (a subprocess on Windows).
        calls = []
        r = self._resolver(self._de_lookup(calls))
        r(r"VORDEFINIERT\Benutzer")
        r(r"VORDEFINIERT\Benutzer")
        self.assertEqual(calls, [r"VORDEFINIERT\Benutzer"])

    def test_owner_short_circuits_before_os_lookup(self):
        # The owner (authoritative from whoami) never hits the OS lookup.
        calls = []
        r = self._resolver(self._de_lookup(calls))
        self.assertEqual(r(r"PC\me"), self._OWNER)
        self.assertEqual(calls, [])

    def test_lookup_account_sid_is_noop_off_windows(self):
        # On this (non-nt) host the real lookup must not shell out and returns None
        # deterministically, so the decision path is exercisable without Windows.
        self.assertIsNone(pbt._lookup_account_sid(r"BUILTIN\Users"))


class NpmInvocation(unittest.TestCase):
    def test_windows_routes_npm_through_cmd(self):
        # npm is npm.cmd on Windows; CreateProcess can't launch it by bare name — must go via cmd.exe.
        self.assertEqual(pbt._npm_argv(["ci"], os_name="nt"), ["cmd", "/c", "npm", "ci"])

    def test_posix_invokes_npm_directly(self):
        self.assertEqual(pbt._npm_argv(["ci"], os_name="posix"), ["npm", "ci"])

    def test_missing_npm_reported_as_invocation_not_connectivity(self):
        with mock.patch.object(pbt, "_require_node_20", lambda: None), \
             mock.patch.object(pbt.subprocess, "run", side_effect=FileNotFoundError()):
            with self.assertRaises(SystemExit) as cm:
                pbt._npm_ci("/tmp/does-not-matter")
        msg = str(cm.exception).lower()
        self.assertIn("not found", msg)
        # RED against the old mislabel, which told the operator to "Fix connectivity/proxy".
        # The new message names connectivity only to negate it ("not a connectivity failure"),
        # so match the actual mislabel directive rather than the bare word.
        self.assertNotIn("fix connectivity", msg)

    def test_npm_ci_nonzero_exit_reports_ci_failure(self):
        with mock.patch.object(pbt, "_require_node_20", lambda: None), \
             mock.patch.object(pbt.subprocess, "run",
                               side_effect=pbt.subprocess.CalledProcessError(1, "npm ci")):
            with self.assertRaises(SystemExit) as cm:
                pbt._npm_ci("/tmp/does-not-matter")
        self.assertIn("npm ci", str(cm.exception))


class NoAccountLevelSshdHardening(unittest.TestCase):
    """Card 5091 (USER-FOUND, security): `--role a` must NOT disable PasswordAuthentication
    (or otherwise harden sshd) at the ACCOUNT level. The ssh-account routinely doubles as the
    operator's interactive login, so a `Match User <acct> { PasswordAuthentication no }` drop-in
    plus a forced-command-only authorized_keys leaves no interactive path — operator lockout.
    The forced-command authorized_keys entry is the sole board-tools security boundary; the
    account-level drop-in added no boundary it does not already impose. Reverting (re-adding the
    drop-in) turns these RED.
    """

    _SRC = os.path.join(_HERE, "provision-board-tools.py")

    def test_no_write_sshd_dropin_symbol(self):
        self.assertFalse(
            hasattr(pbt, "_write_sshd_dropin"),
            "the account-level sshd drop-in (operator-lockout, card 5091) must not return",
        )

    def test_source_never_writes_the_sshd_dropin(self):
        # The lockout mechanism was a drop-in written to /etc/ssh/sshd_config.d/<acct>-board-tools.conf.
        # Guarding the write PATH (not the concept) keeps the test precise: it stays green while the
        # fix is documented in prose, and goes red only if the drop-in write actually returns.
        with open(self._SRC, encoding="utf-8") as fh:
            src = fh.read()
        self.assertNotIn(
            "sshd_config.d",
            src,
            "provisioning must not write an sshd drop-in — that is the card-5091 lockout path",
        )


class BuildForcedCommand(unittest.TestCase):
    """Card 5092: the forced command carries a KEY-scoped hard timeout so a hung key-holder
    is bounded at the command level (the one bound that survives on a shared operator login,
    after card 5091 retired the account-level sshd idle backstop)."""

    _ARTISAN = "/opt/bridge/artisan"

    def test_default_timeout_wraps_the_command(self):
        line = pbt.build_forced_command("me", self._ARTISAN, pbt.DEFAULT_FORCED_COMMAND_TIMEOUT)
        # red-when-reverted: dropping the wrapper removes `timeout` from the pinned command.
        self.assertIn(
            f'command="timeout -k 10 {pbt.DEFAULT_FORCED_COMMAND_TIMEOUT} php {self._ARTISAN} '
            'bridge:tools-call --agent=me"',
            line,
        )

    def test_options_preserved(self):
        line = pbt.build_forced_command("me", self._ARTISAN, 300)
        self.assertTrue(line.endswith(",no-pty,no-agent-forwarding,no-X11-forwarding,no-port-forwarding"))

    def test_guard_substring_intact_for_idempotency(self):
        # run_role_a's idempotency guard matches on this substring — the timeout prefix must
        # not break it, or a re-run would fail to detect an existing pinned line.
        line = pbt.build_forced_command("me", self._ARTISAN, 300)
        self.assertIn('bridge:tools-call --agent=me"', line)

    def test_zero_disables_the_wrapper(self):
        line = pbt.build_forced_command("me", self._ARTISAN, 0)
        self.assertNotIn("timeout", line)
        self.assertIn(f'command="php {self._ARTISAN} bridge:tools-call --agent=me"', line)

    def test_custom_timeout_value(self):
        line = pbt.build_forced_command("agent-1", self._ARTISAN, 45)
        self.assertIn("timeout -k 10 45 php", line)


class RoleBHostBLeg(unittest.TestCase):
    """card#8972: `--role b` mutates the seat's own state. Three of those mutations were
    unsafe, and each of the cases below goes RED when its fix is reverted:

      1. `--ssh-key` DIVERGED from the key the leg used: the key path was derived from
         `--agent` and the FLAG's value was recorded as BRIDGE_TOOLS_SSH_KEY with no
         compare, so the seat could be handed a config pointing at a key nothing pinned.
      2. `.mcp.json` was written in place (`open(mcp_path, "w")` + `json.dump`), so any
         failure mid-serialise left the seat's live channel config TRUNCATED.
      3. see StaleSnapshotRetention below.
    """

    @classmethod
    def setUpClass(cls):
        if _SSH_KEYGEN is None:  # pragma: no cover
            raise unittest.SkipTest("ssh-keygen not on PATH (banner printed at import)")
        _KeyFixtures.build()

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.home = os.path.join(self.tmp.name, "home")
        self.project = os.path.join(self.tmp.name, "project")
        os.makedirs(os.path.join(self.home, ".ssh"))
        os.makedirs(self.project)
        self.mcp_path = os.path.join(self.project, ".mcp.json")

    def _write_pair(self, key_path, source=None, pub_from=None):
        """A REAL key pair already on disk (the shape of what `--ssh-key` may name).

        `pub_from` installs a DIFFERENT key's public half beside this private one — two
        files that both exist and are both well-formed, and are not one pair.
        """
        source = source or _KeyFixtures.plain
        shutil.copyfile(source, key_path)
        os.chmod(key_path, 0o600)
        shutil.copyfile((pub_from or source) + ".pub", key_path + ".pub")
        return key_path

    def _keygen_stub(self):
        """Stands in for ssh-keygen: materialises the pair it would have generated."""
        def _keygen(agent, key_path):
            self._write_pair(key_path)
        return _keygen

    def _run(self, extra_argv=(), keygen=None, deploy_prints=()):
        argv = [
            "--role", "b", "--agent", "kanban-solo",
            "--ssh-target", "bridge@127.0.0.1",
            "--project-dir", self.project,
            "--channel-name", "kanbanboard-agent",
            *extra_argv,
        ]
        args = pbt.build_parser().parse_args(argv)
        buf = io.StringIO()
        with mock.patch.object(pbt, "_host_b_home", return_value=self.home), \
             mock.patch.object(pbt, "_keygen", side_effect=keygen or self._keygen_stub()), \
             mock.patch.object(pbt, "_deploy_snapshot",
                               side_effect=lambda _d: [print(line) for line in deploy_prints]), \
             mock.patch.object(pbt, "_seed_known_hosts"), \
             contextlib.redirect_stdout(buf):
            rc = pbt.run_role_b(args)
        return rc, buf.getvalue()

    def _recorded_env(self):
        with open(self.mcp_path, encoding="utf-8") as fh:
            return json.load(fh)["mcpServers"]["kanbanboard-agent"]["env"]

    def _backups(self):
        return sorted(n for n in os.listdir(self.project) if n.startswith(".mcp.json.bak-"))

    # --- 1. --ssh-key names the key that is actually used ------------------- #

    def test_ssh_key_pointing_at_a_missing_key_refuses_naming_both_paths(self):
        # RED-when-reverted: pre-fix this ran to completion, generating + pinning
        # ~/.ssh/kanban-solo-board-tools while recording the flag's path.
        ghost = os.path.join(self.tmp.name, "not-there")
        with self.assertRaises(SystemExit) as cm:
            self._run(["--ssh-key", ghost])
        msg = str(cm.exception)
        self.assertIn(ghost, msg)                                              # the given path
        self.assertIn(os.path.join(self.home, ".ssh", "kanban-solo-board-tools"), msg)  # the default
        self.assertIn("EXISTING", msg)
        self.assertFalse(os.path.exists(self.mcp_path), ".mcp.json must not be written on refusal")

    def test_ssh_key_naming_an_existing_pair_is_used_verbatim_and_never_regenerated(self):
        given = self._write_pair(os.path.join(self.tmp.name, "operator-key"))
        keygen = mock.Mock()
        rc, out = self._run(["--ssh-key", given], keygen=keygen)
        self.assertEqual(rc, 0)
        keygen.assert_not_called()
        self.assertEqual(self._recorded_env()["BRIDGE_TOOLS_SSH_KEY"], given)
        # The same path reaches the same-box handoff marker the samebox wrapper parses.
        self.assertIn(
            "Same-box: hand this path to `--role a --pubkey-from`:\n  " + given + ".pub", out
        )
        self.assertFalse(
            os.path.exists(os.path.join(self.home, ".ssh", "kanban-solo-board-tools")),
            "no key may be generated when --ssh-key names one",
        )

    def test_without_the_flag_the_recorded_key_is_the_derived_one(self):
        rc, out = self._run()
        self.assertEqual(rc, 0)
        derived = os.path.join(self.home, ".ssh", "kanban-solo-board-tools")
        self.assertEqual(self._recorded_env()["BRIDGE_TOOLS_SSH_KEY"], derived)
        self.assertIn("Same-box: hand this path to `--role a --pubkey-from`:\n  " + derived + ".pub", out)

    def test_self_cert_probes_the_key_that_was_recorded(self):
        # The recorded key is only meaningful if it is the one certified.
        given = self._write_pair(os.path.join(self.tmp.name, "operator-key"))
        with mock.patch.object(pbt, "_self_cert", return_value=0) as sc:
            self._run(["--ssh-key", given, "--self-cert"], keygen=mock.Mock())
        self.assertEqual(sc.call_args.args[1], given)

    def test_self_cert_passes_i_derived_key_when_no_flag_was_given(self):
        # RED-when-reverted: `-i` used to be passed only `if ssh_key`, so with no flag
        # --self-cert probed the seat's DEFAULT ssh identity — a `Permission denied
        # (publickey)` on a seat whose default identity is not the key role b just
        # generated and host A just pinned, i.e. the check failing on the one arrangement
        # it exists to certify.
        derived = os.path.join(self.home, ".ssh", "kanban-solo-board-tools")
        completed = mock.Mock(stdout=json.dumps({"ok": True}), stderr="", returncode=0)
        real_run = pbt.subprocess.run

        # Only the `ssh` call is stubbed: the `ssh-keygen -y` pair check stays REAL, so
        # this case is also a witness that the two legs do not fight over one key.
        def dispatch(cmd, *a, **kw):
            return completed if cmd[0] == "ssh" else real_run(cmd, *a, **kw)

        with mock.patch.object(pbt.subprocess, "run", side_effect=dispatch) as run:
            rc, _ = self._run(["--self-cert"])
        self.assertEqual(rc, 0)
        ssh_calls = [c.args[0] for c in run.call_args_list if c.args[0][0] == "ssh"]
        self.assertEqual(len(ssh_calls), 1)
        argv = ssh_calls[0]
        self.assertIn("-i", argv)
        self.assertEqual(argv[argv.index("-i") + 1], derived)
        self.assertEqual(self._recorded_env()["BRIDGE_TOOLS_SSH_KEY"], derived)

    def test_the_samebox_wrapper_parses_the_pub_path_out_of_this_legs_real_stdout(self):
        # The same-box wrapper reads this leg's stdout for the handoff path: it finds the
        # marker line and takes the NEXT NON-BLANK line. Every line this leg prints — the
        # .mcp.json backup path, the retained .stale- snapshot path, `unchanged` — is
        # printed at the merge/deploy step, BEFORE the handoff block, and this asserts
        # that over the REAL captured stdout rather than a static fixture, so a line
        # printed into the gap is caught here and not on a live same-box run.
        original = json.dumps({"mcpServers": {}, "seatOwned": True}, indent=2) + "\n"
        with open(self.mcp_path, "w", encoding="utf-8") as fh:
            fh.write(original)  # forces a .bak- line into the captured output
        _, out = self._run()
        self.assertIn(".mcp.json.bak-", out)
        self.assertEqual(
            sbx.parse_pubkey_path(out),
            os.path.join(self.home, ".ssh", "kanban-solo-board-tools.pub"),
        )

    def test_the_wrapper_still_parses_on_the_unchanged_and_retained_snapshot_runs(self):
        # The other two output shapes that gained lines: the idempotent re-run (which
        # prints `unchanged` instead of the merge line) and a run whose snapshot deploy
        # retained a stale tree (three lines, printed even earlier). Both are asserted
        # over REAL captured stdout, for the same reason as the case above.
        expected = os.path.join(self.home, ".ssh", "kanban-solo-board-tools.pub")

        self._run()
        _, unchanged_out = self._run()
        self.assertIn("unchanged", unchanged_out)
        self.assertEqual(sbx.parse_pubkey_path(unchanged_out), expected)

        _, retained_out = self._run(deploy_prints=[
            "replacing stale snapshot (deployed 0.1.0 < bundled 0.9.0).",
            f"previous snapshot retained at {self.project}/.channel-server.stale-0.1.0 — nothing was deleted.",
            "  to ROLL BACK, move it back over the deploy dir (POSIX: mv a b)",
            "  to DISCARD it, delete that path — but only once the new snapshot is confirmed working.",
        ])
        self.assertIn("retained at", retained_out)
        self.assertEqual(sbx.parse_pubkey_path(retained_out), expected)

    # --- 1c. the pair must BE a pair, and be usable in BatchMode ------------ #

    def test_a_pub_that_is_not_this_keys_public_half_refuses(self):
        # RED-when-reverted: both files EXIST, both are well-formed, and neither an
        # existence check nor a shape check can tell they are two different keys. Pinning
        # the wrong half authorizes a key this seat cannot present — `Permission denied
        # (publickey)` at every later call, against a pin the operator watched succeed.
        given = self._write_pair(
            os.path.join(self.tmp.name, "operator-key"), pub_from=_KeyFixtures.other
        )
        with self.assertRaises(SystemExit) as cm:
            self._run(["--ssh-key", given], keygen=mock.Mock())
        msg = str(cm.exception)
        self.assertIn("NOT the public half", msg)
        self.assertIn(given, msg)
        self.assertIn("ssh-keygen -y -f", msg)  # the remedy, not just the verdict
        self.assertFalse(os.path.exists(self.mcp_path))

    def test_a_passphrase_protected_key_refuses_naming_batchmode(self):
        # The channel server spawns ssh in BatchMode with no agent, so an encrypted key
        # can never be unlocked at call time — however correct the pin is.
        given = self._write_pair(
            os.path.join(self.tmp.name, "operator-key"), source=_KeyFixtures.encrypted
        )
        with self.assertRaises(SystemExit) as cm:
            self._run(["--ssh-key", given], keygen=mock.Mock())
        msg = str(cm.exception)
        self.assertIn("PASSPHRASE-PROTECTED", msg)
        self.assertIn("BatchMode", msg)
        self.assertFalse(os.path.exists(self.mcp_path))

    def test_a_real_matching_pair_passes_the_correspondence_check(self):
        # The control for the two refusals above: without it they would pass over a
        # check that refuses everything.
        given = self._write_pair(os.path.join(self.tmp.name, "operator-key"))
        rc, _ = self._run(["--ssh-key", given], keygen=mock.Mock())
        self.assertEqual(rc, 0)
        self.assertEqual(self._recorded_env()["BRIDGE_TOOLS_SSH_KEY"], given)

    def test_an_operator_supplied_key_is_verified_not_re_permissioned(self):
        # RED-when-reverted: a `chmod 600` here would make this pass silently, having
        # rewritten the mode of a file the operator owns and never asked us to change.
        given = self._write_pair(os.path.join(self.tmp.name, "operator-key"))
        os.chmod(given, 0o644)
        with self.assertRaises(SystemExit) as cm:
            self._run(["--ssh-key", given], keygen=mock.Mock())
        self.assertIn("--ssh-key", str(cm.exception))
        self.assertIn("chmod 600", str(cm.exception))
        self.assertEqual(os.stat(given).st_mode & 0o777, 0o644, "the tool must not have touched it")

    def test_a_generated_key_is_still_re_permissioned(self):
        # The other half of the same decision: the tool DOES harden the key it owns.
        def keygen(agent, key_path):
            self._write_pair(key_path)
            os.chmod(key_path, 0o644)

        rc, _ = self._run(keygen=keygen)
        self.assertEqual(rc, 0)
        derived = os.path.join(self.home, ".ssh", "kanban-solo-board-tools")
        self.assertEqual(os.stat(derived).st_mode & 0o777, 0o600)

    # --- 2. .mcp.json is installed atomically, with a backup ---------------- #

    def test_serialise_failure_leaves_the_original_intact_and_no_tmp_behind(self):
        # RED-when-reverted: `open(mcp_path, "w")` truncates BEFORE json.dump runs, so
        # the original bytes are gone by the time the fault fires.
        original = json.dumps({"mcpServers": {}, "seatOwned": True}, indent=2) + "\n"
        with open(self.mcp_path, "w", encoding="utf-8") as fh:
            fh.write(original)
        with mock.patch.object(pbt.json, "dump", side_effect=RuntimeError("disk full")):
            with self.assertRaises(RuntimeError):
                self._run()
        with open(self.mcp_path, encoding="utf-8") as fh:
            self.assertEqual(fh.read(), original)
        self.assertFalse(os.path.exists(self.mcp_path + ".tmp"))
        self.assertEqual(self._backups(), [])

    def test_changed_merge_backs_up_the_previous_file_before_replacing_it(self):
        original = json.dumps({"mcpServers": {}, "seatOwned": True}, indent=2) + "\n"
        with open(self.mcp_path, "w", encoding="utf-8") as fh:
            fh.write(original)
        rc, out = self._run()
        self.assertEqual(rc, 0)
        backups = self._backups()
        self.assertEqual(len(backups), 1, f"expected exactly one backup, got {backups}")
        backup_path = os.path.join(self.project, backups[0])
        with open(backup_path, encoding="utf-8") as fh:
            self.assertEqual(fh.read(), original, "the backup must hold the PREVIOUS bytes")
        self.assertIn(backup_path, out)
        self.assertEqual(os.stat(backup_path).st_mode & 0o777, 0o600)
        merged = self._recorded_env()
        self.assertEqual(merged["BRIDGE_TOOLS_SSH_TARGET"], "bridge@127.0.0.1")
        with open(self.mcp_path, encoding="utf-8") as fh:
            self.assertTrue(json.load(fh)["seatOwned"], "unrelated seat keys survive the replace")

    def test_idempotent_reprovision_writes_no_second_backup(self):
        self._run()
        self.assertEqual(self._backups(), [])
        rc, out = self._run()
        self.assertEqual(rc, 0)
        self.assertEqual(self._backups(), [], "an unchanged re-run must not churn backups")
        self.assertIn("unchanged", out)

    def test_a_reprovision_without_ssh_port_drops_the_port_the_last_run_set(self):
        # RED-when-reverted: the seat keeps `ssh -p 2222` on every board-tools call, from
        # an invocation that never mentioned a port and printed nothing about one.
        rc, _ = self._run(["--ssh-port", "2222"])
        self.assertEqual(rc, 0)
        self.assertEqual(self._recorded_env()["BRIDGE_TOOLS_SSH_PORT"], "2222")

        rc, _ = self._run()
        self.assertEqual(rc, 0)
        env = self._recorded_env()
        self.assertNotIn("BRIDGE_TOOLS_SSH_PORT", env)
        # The rest of the owned set is still fully written, not collaterally dropped.
        self.assertEqual(env["BRIDGE_TOOLS_SSH_TARGET"], "bridge@127.0.0.1")
        self.assertIn("BRIDGE_TOOLS_SSH_KEY", env)

    def test_a_fresh_mcp_json_is_created_0600(self):
        # It can carry BRIDGE_CHANNEL_TOKEN, so it must not inherit a permissive umask.
        self._run()
        self.assertEqual(os.stat(self.mcp_path).st_mode & 0o777, 0o600)

    def test_a_replaced_mcp_json_keeps_the_mode_the_seat_gave_it(self):
        # The inverse: the install is not an excuse to re-permission a file that exists.
        with open(self.mcp_path, "w", encoding="utf-8") as fh:
            fh.write(json.dumps({"mcpServers": {}, "seatOwned": True}, indent=2) + "\n")
        os.chmod(self.mcp_path, 0o640)
        self._run()
        self.assertEqual(os.stat(self.mcp_path).st_mode & 0o777, 0o640)

    def test_a_symlinked_mcp_json_is_written_THROUGH_the_link(self):
        # RED-when-reverted: os.replace onto a symlink replaces the LINK with a regular
        # file, silently detaching a seat that keeps .mcp.json in a dotfiles repo.
        real_dir = os.path.join(self.tmp.name, "dotfiles")
        os.makedirs(real_dir)
        target = os.path.join(real_dir, "mcp.json")
        with open(target, "w", encoding="utf-8") as fh:
            fh.write(json.dumps({"mcpServers": {}, "seatOwned": True}, indent=2) + "\n")
        os.symlink(target, self.mcp_path)

        rc, _ = self._run()
        self.assertEqual(rc, 0)
        self.assertTrue(os.path.islink(self.mcp_path), "the link itself must survive")
        self.assertEqual(os.path.realpath(self.mcp_path), target)
        with open(target, encoding="utf-8") as fh:
            self.assertIn("BRIDGE_TOOLS_SSH_KEY", json.load(fh)["mcpServers"]["kanbanboard-agent"]["env"])
        # The backup and the temp file follow the link to the target's own directory —
        # the same filesystem, which is what makes the replace atomic.
        self.assertTrue(any(n.startswith("mcp.json.bak-") for n in os.listdir(real_dir)))
        self.assertEqual(self._backups(), [])


class StaleSnapshotRetention(unittest.TestCase):
    """card#8972 (3): a stale channel-server snapshot was `shutil.rmtree`d before the
    replacement was copied — if the copy or the `npm ci` that follows fails, the seat is
    left with NO channel server and nothing to roll back to. It is renamed aside instead.
    """

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.source = os.path.join(self.tmp.name, "bundled")
        os.makedirs(self.source)
        self._pkg(self.source, "0.9.0")
        with open(os.path.join(self.source, "new-file.mjs"), "w", encoding="utf-8") as fh:
            fh.write("// bundled\n")
        self.deploy = os.path.join(self.tmp.name, "project", ".channel-server")
        os.makedirs(self.deploy)
        self._pkg(self.deploy, "0.1.0")
        with open(os.path.join(self.deploy, "operator-note.txt"), "w", encoding="utf-8") as fh:
            fh.write("deployed content\n")

    @staticmethod
    def _pkg(d, version):
        with open(os.path.join(d, "package.json"), "w", encoding="utf-8") as fh:
            json.dump({"version": version}, fh)

    def _deploy(self):
        buf = io.StringIO()
        with mock.patch.object(pbt, "_bundled_snapshot_dir", return_value=self.source), \
             mock.patch.object(pbt, "_require_node_20"), \
             mock.patch.object(pbt, "_npm_ci"), \
             contextlib.redirect_stdout(buf):
            pbt._deploy_snapshot(self.deploy)
        return buf.getvalue()

    def test_stale_snapshot_is_retained_not_deleted(self):
        # RED-when-reverted: shutil.rmtree leaves no .stale- path at all.
        out = self._deploy()
        stale = self.deploy + ".stale-0.1.0"
        self.assertTrue(os.path.isdir(stale), "the stale snapshot must be retained")
        with open(os.path.join(stale, "operator-note.txt"), encoding="utf-8") as fh:
            self.assertEqual(fh.read(), "deployed content\n")
        self.assertIn(stale, out)
        # The message must name BOTH dispositions: an operator told only "you can delete
        # this" has been handed a one-way door out of a rollback they may need.
        self.assertIn("ROLL BACK", out)
        self.assertIn(self.deploy, out)
        self.assertIn("DISCARD", out)
        self.assertTrue(os.path.isfile(os.path.join(self.deploy, "new-file.mjs")))
        self.assertEqual(pbt._package_version(os.path.join(self.deploy, "package.json")), "0.9.0")

    def test_a_failing_node_precheck_leaves_the_deployed_tree_exactly_where_it_was(self):
        """RED-when-reverted: with `_require_node_20()` back below the rename, a seat
        without Node 20 gets its channel server renamed aside and THEN refused —
        `.mcp.json` still points at `.channel-server`, nothing is there, and the retention
        message tells the operator that tree can be removed. The precheck cannot mutate
        anything, so it must run before the first thing that can."""
        before = sorted(os.listdir(self.deploy))
        with mock.patch.object(pbt, "_bundled_snapshot_dir", return_value=self.source), \
             mock.patch.object(pbt, "_require_node_20",
                               side_effect=SystemExit("provision-board-tools: Node >= 20 is required")), \
             mock.patch.object(pbt, "_npm_ci"), \
             contextlib.redirect_stdout(io.StringIO()):
            with self.assertRaises(SystemExit):
                pbt._deploy_snapshot(self.deploy)

        self.assertTrue(os.path.isdir(self.deploy), "the deployed tree must still be there")
        self.assertEqual(sorted(os.listdir(self.deploy)), before)
        self.assertEqual(
            pbt._package_version(os.path.join(self.deploy, "package.json")), "0.1.0",
            "the stale-but-working snapshot is still the one deployed",
        )
        parent = os.path.dirname(self.deploy)
        self.assertEqual(
            [n for n in os.listdir(parent) if ".stale-" in n], [],
            "nothing may be renamed aside before the precheck has passed",
        )

    def test_collision_on_the_stale_path_suffixes_and_deletes_nothing(self):
        stale = self.deploy + ".stale-0.1.0"
        os.makedirs(stale)
        with open(os.path.join(stale, "earlier.txt"), "w", encoding="utf-8") as fh:
            fh.write("from an earlier replace\n")
        self._deploy()
        with open(os.path.join(stale, "earlier.txt"), encoding="utf-8") as fh:
            self.assertEqual(fh.read(), "from an earlier replace\n")
        parent = os.path.dirname(self.deploy)
        suffixed = [
            n for n in os.listdir(parent)
            if n.startswith(".channel-server.stale-0.1.0-")
        ]
        self.assertEqual(len(suffixed), 1, f"expected one suffixed retention, got {suffixed}")
        with open(os.path.join(parent, suffixed[0], "operator-note.txt"), encoding="utf-8") as fh:
            self.assertEqual(fh.read(), "deployed content\n")


class VersionComparatorLockstep(unittest.TestCase):
    """Card 5108 / DL-229: `_version_tuple` here is the DECLARED AUTHORITY for
    channel-server snapshot comparison semantics. `bridge:check` re-implements it in
    PHP (`App\\Bridge\\Support\\ChannelSnapshotProbe::compareVersions`) so it can tell a
    stale deployed snapshot from a current one WITHOUT shelling out to this script.

    These vectors are the LOCKSTEP CONTRACT: the same pairs and the same verdicts are
    asserted in `tests/Unit/Support/ChannelSnapshotProbeTest.php`
    (`ChannelSnapshotProbeTest::versionVectors`). Change one side without the other and
    the provisioner and `bridge:check` silently disagree about which snapshots are
    stale — the operator re-syncs forever, or never.

    The starred rows are where PHP's `version_compare()` DIVERGES from this authority
    (it honors the pre-release/build tags `_version_tuple` deliberately drops), which
    is why the PHP side may not use it.

    CONFORMANCE BOUND (measured): the two implementations agree for ASCII-digit
    versions whose chunks fit PHP's integer range. Outside that they cannot — this
    `\\d` is Unicode-aware and python ints are arbitrary-precision, while PHP casts
    non-ASCII digits to 0 and saturates at PHP_INT_MAX. Neither class is reachable
    through an npm `version` field, so it is a documented bound, not a defect.
    """

    VECTORS = [
        ("0.8.0", "0.8.0", 0),
        ("0.8.0-rc1", "0.8.0", 0),      # * php version_compare says -1
        ("0.8", "0.8.0", -1),
        ("0.10.0", "0.9.0", 1),
        ("0.8.0", "0.8.0+build5", 0),   # * php version_compare says +1
        ("1.0.0-alpha", "1.0.0", 0),    # * php version_compare says -1
        ("", "0.8.0", -1),
    ]

    @staticmethod
    def _cmp(a: str, b: str) -> int:
        ta, tb = pbt._version_tuple(a), pbt._version_tuple(b)
        return (ta > tb) - (ta < tb)

    def test_shared_vectors(self):
        for a, b, expected in self.VECTORS:
            with self.subTest(a=a, b=b):
                self.assertEqual(self._cmp(a, b), expected)

    def test_shared_vectors_antisymmetric(self):
        for a, b, expected in self.VECTORS:
            with self.subTest(a=b, b=a):
                self.assertEqual(self._cmp(b, a), -expected)

    def test_leading_digits_only_per_chunk(self):
        # RED-when-reverted: a `int(chunk)` implementation raises on these instead.
        self.assertEqual(pbt._version_tuple("0.8.0-rc1"), (0, 8, 0))
        self.assertEqual(pbt._version_tuple("1.0.0+build5"), (1, 0, 0))
        self.assertEqual(pbt._version_tuple(""), (0,))
        self.assertEqual(pbt._version_tuple("v1.x"), (0, 0))

    def test_deploy_snapshot_uses_this_comparator(self):
        # The authority claim is only true while _deploy_snapshot actually decides on
        # _version_tuple — a rewrite to a plain string/float compare would silently
        # unbind the two implementations while every vector above still passed.
        with open(os.path.join(_HERE, "provision-board-tools.py"), encoding="utf-8") as fh:
            src = fh.read()
        self.assertIn("if _version_tuple(deployed_version) >= _version_tuple(bundled_version):", src)


if __name__ == "__main__":
    unittest.main()


class ExpectFingerprintParsing(unittest.TestCase):
    """`--expect-fingerprint` accepts two shapes an operator plausibly has in hand."""

    def test_a_bare_fingerprint_is_taken_whole(self):
        # ⭐ THE ONE-FIELD ARM IS NOT A CONVENIENCE. Slicing field 2 out of a one-field
        # input yields nothing, and a comparison against nothing is a guard that passes.
        self.assertEqual(pbt.parse_expected_fingerprint("SHA256:abc+def/123="), "SHA256:abc+def/123=")

    def test_a_whole_ssh_keygen_line_yields_its_second_field(self):
        self.assertEqual(
            pbt.parse_expected_fingerprint("256 SHA256:abc+def/123= agent-board-tools (ECDSA)"),
            "SHA256:abc+def/123=",
        )

    def test_surrounding_whitespace_is_not_a_second_field(self):
        self.assertEqual(pbt.parse_expected_fingerprint("  SHA256:xyz=\n"), "SHA256:xyz=")


class WeakAgentPattern(unittest.TestCase):
    """The duplicate-line heuristic, over its OWN bounds rather than over a hope."""

    def test_it_matches_the_bare_spelling_before_a_quote_a_space_or_the_line_end(self):
        p = pbt.weak_agent_pattern("impl")
        self.assertTrue(p.search('command="php artisan bridge:tools-call --agent=impl",no-pty k b'))
        self.assertTrue(p.search('command="wrapper --agent=impl -v",no-pty k b'))
        self.assertTrue(p.search("# note: --agent=impl"))

    def test_it_does_not_match_a_longer_agent_name_that_starts_the_same(self):
        # `--agent=impl2` is another agent's line and refusing on it would be a refusal
        # nobody can act on.
        self.assertIsNone(pbt.weak_agent_pattern("impl").search('command="x --agent=impl2",no-pty k b'))

    def test_the_quoted_forms_are_a_DECLARED_blind_spot_not_an_accident(self):
        # ⚠ Asserted so the bound is visible rather than folded into prose: a hand line
        # spelled --agent="impl" is NOT seen. The miss direction is safe (today's
        # behaviour), and a reader who widens the heuristic will find this case.
        self.assertIsNone(pbt.weak_agent_pattern("impl").search('command="x --agent=\\"impl\\"",no-pty k b'))


class SignificantAuthorizedKeysLines(unittest.TestCase):
    def test_blank_and_comment_lines_are_dropped_by_the_one_helper_both_scans_use(self):
        text = "\n# an old commented-out line for --agent=impl\n   \nssh-rsa AAAA= me\n"
        self.assertEqual(pbt.significant_authorized_keys_lines(text), ["ssh-rsa AAAA= me"])


class _PwEntry:
    def __init__(self, home, uid, gid=None):
        self.pw_dir = home
        self.pw_uid = uid
        self.pw_gid = uid if gid is None else gid


class RoleASelfAccountArm(unittest.TestCase):
    """`--role a` (card#8971): the non-root self-account arm, the symlink defence, the
    duplicate-line detector and `--expect-fingerprint`.

    ⛔ NOTHING HERE TOUCHES THE RUNNER'S REAL `~/.ssh`. `pwd.getpwnam` is patched to a
    temp home whose uid IS this process's euid — that is what makes the self-account arm
    reachable at all without root — and `os.chown` is patched to a recorder, so a test
    that started chowning would be caught rather than silently changing a file's owner.
    """

    @classmethod
    def setUpClass(cls):
        if _SSH_KEYGEN is None:  # pragma: no cover
            raise unittest.SkipTest("ssh-keygen not on PATH (banner printed at import)")
        _KeyFixtures.build()

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.home = os.path.join(self.tmp.name, "home")
        os.makedirs(self.home)
        self.authz = os.path.join(self.home, ".ssh", "authorized_keys")
        with open(_KeyFixtures.plain + ".pub", encoding="utf-8") as fh:
            self.pubkey = fh.read().strip("\n")
        self.pub_path = os.path.join(self.tmp.name, "posted.pub")
        with open(self.pub_path, "w", encoding="utf-8") as fh:
            fh.write(self.pubkey + "\n")
        self.chowns = []

    def _run(self, extra_argv=(), uid_delta=0, pubkey_from=None):
        argv = [
            "--role", "a", "--agent", "impl",
            "--artisan", "/opt/bridge/artisan",
            "--ssh-account", "bridge",
            "--pubkey-from", pubkey_from or self.pub_path,
            *extra_argv,
        ]
        args = pbt.build_parser().parse_args(argv)
        pw = _PwEntry(self.home, os.geteuid() + uid_delta)
        buf = io.StringIO()
        with mock.patch("pwd.getpwnam", return_value=pw), \
             mock.patch.object(os, "chown", side_effect=lambda *a, **k: self.chowns.append((a, k))), \
             contextlib.redirect_stdout(buf):
            rc = pbt.run_role_a(args)
        return rc, buf.getvalue()

    def _authz_lines(self):
        with open(self.authz, encoding="utf-8") as fh:
            return fh.read().splitlines()

    # --- the self-account arm ---------------------------------------------- #

    def test_the_self_account_arm_writes_the_line_and_chowns_nothing(self):
        # ⭐ RED-WHEN-REVERTED: before card#8971 this path exited with "run as root"
        # having written nothing. The arm is not a privilege grant — the account can
        # already write its own authorized_keys — it removes a sudo nobody needed.
        rc, out = self._run()

        self.assertEqual(rc, 0)
        self.assertEqual(len(self._authz_lines()), 1)
        self.assertIn("bridge:tools-call --agent=impl", self._authz_lines()[0])
        self.assertIn(self.pubkey, self._authz_lines()[0])
        self.assertEqual(self.chowns, [], "the self-account arm must not chown anything")
        self.assertEqual(oct(os.stat(self.authz).st_mode & 0o777), "0o600")
        self.assertIn("default path; sshd's AuthorizedKeysFile is not resolved by this tool", out)

    def test_a_non_root_run_against_another_account_is_refused_by_name(self):
        with self.assertRaises(SystemExit) as cm:
            self._run(uid_delta=1)
        msg = str(cm.exception)
        self.assertIn("may only pin into ITS OWN authorized_keys", msg)
        self.assertIn("sudo -u bridge python3", msg)
        self.assertFalse(os.path.exists(self.authz), "a refused run must write nothing")

    def test_the_run_is_idempotent_for_the_same_key(self):
        self._run()
        rc, out = self._run()
        self.assertEqual(rc, 0)
        self.assertEqual(len(self._authz_lines()), 1)
        self.assertIn("already present (same key)", out)

    def test_a_different_key_for_the_same_agent_is_still_refused(self):
        self._run()
        other = os.path.join(self.tmp.name, "other.pub")
        shutil.copyfile(_KeyFixtures.other + ".pub", other)
        with self.assertRaises(SystemExit) as cm:
            self._run(pubkey_from=other)
        self.assertIn("already pins a DIFFERENT key", str(cm.exception))

    # --- the symlink defence (F16) ------------------------------------------ #

    def test_a_symlinked_authorized_keys_is_refused_and_its_target_is_untouched(self):
        # ⭐ CONTROL: drop `os.O_NOFOLLOW` from `_append_authorized_key_line` and this
        # reds — the append then writes an ssh key line straight into the link's TARGET,
        # which under the root arm is an arbitrary root-owned file gaining a key.
        target = os.path.join(self.tmp.name, "victim")
        with open(target, "w", encoding="utf-8") as fh:
            fh.write("root-owned content\n")
        os.makedirs(os.path.dirname(self.authz))
        os.symlink(target, self.authz)

        with self.assertRaises(SystemExit) as cm:
            self._run()

        self.assertIn("SYMLINK", str(cm.exception))
        with open(target, encoding="utf-8") as fh:
            self.assertEqual(fh.read(), "root-owned content\n")

    def test_a_symlinked_ssh_directory_stays_legal(self):
        # ⛔ THE OTHER HALF OF F16, AND THE REASON THE DEFENCE IS NOT A BLANKET REFUSAL:
        # a dotfiles repo symlinking ~/.ssh is ordinary, and refusing it would break
        # working installs to defend against a hazard that lives in the chown, which
        # runs follow_symlinks=False.
        real = os.path.join(self.tmp.name, "real-ssh")
        os.makedirs(real)
        os.symlink(real, os.path.join(self.home, ".ssh"))

        rc, _ = self._run()

        self.assertEqual(rc, 0)
        self.assertEqual(len(self._authz_lines()), 1)

    # --- the duplicate-line detector (§1.3 / F9) ---------------------------- #

    def test_a_hand_pinned_line_with_different_options_is_refused_not_appended_to(self):
        # ⭐ CONTROL: delete the `hand_lines` refusal and this reds twice — the run
        # reaches the append branch, prints "appended", and the account ends up with TWO
        # lines for one agent. sshd honours whichever matches first and `bridge:check`
        # FAILs on the ambiguity, from the other side of the box and one step too late.
        hand = 'command="/usr/local/bin/wrapper --agent=impl",no-pty ssh-ed25519 AAAAC3NzaC1lZDI1NTE5 impl'
        os.makedirs(os.path.dirname(self.authz))
        with open(self.authz, "w", encoding="utf-8") as fh:
            fh.write(hand + "\n")

        with self.assertRaises(SystemExit) as cm:
            self._run()

        msg = str(cm.exception)
        self.assertIn("a hand-pinned line for agent impl exists", msg)
        self.assertIn(hand, msg, "the offending line is named, not merely counted")
        self.assertEqual(self._authz_lines(), [hand], "nothing may be appended")

    def test_the_mixed_state_is_reported_rather_than_read_as_already_present(self):
        # A line this tool wrote AND a hand-edited one. "already present (same key)" is a
        # true sentence about the wrong thing, and it would leave the extra line standing.
        self._run()
        hand = 'command="/usr/local/bin/wrapper --agent=impl",no-pty ssh-ed25519 AAAAC3NzaC1lZDI1NTE5 impl'
        with open(self.authz, "a", encoding="utf-8") as fh:
            fh.write(hand + "\n")

        with self.assertRaises(SystemExit) as cm:
            self._run()

        self.assertIn("a hand-pinned line for agent impl exists", str(cm.exception))

    def test_a_commented_out_line_naming_the_agent_is_not_a_hand_pinned_line(self):
        os.makedirs(os.path.dirname(self.authz))
        with open(self.authz, "w", encoding="utf-8") as fh:
            fh.write("# retired: command=\"old --agent=impl\"\n\n")

        rc, out = self._run()

        self.assertEqual(rc, 0)
        self.assertIn("appended the forced-command line", out)

    # --- --expect-fingerprint ------------------------------------------------ #

    def test_a_matching_fingerprint_pins_and_prints_it(self):
        fp = pbt.fingerprint_of_pubkey_file(self.pub_path)
        rc, out = self._run(["--expect-fingerprint", fp])
        self.assertEqual(rc, 0)
        self.assertIn(f"Pinned fingerprint: {fp}", out)

    def test_a_whole_ssh_keygen_line_is_accepted_as_the_expected_value(self):
        line = subprocess.run(
            ["ssh-keygen", "-E", "sha256", "-lf", self.pub_path],
            capture_output=True, text=True, check=True,
        ).stdout.strip()
        self.assertGreater(len(line.split()), 1, "fixture must be the multi-field form")
        self.assertEqual(self._run(["--expect-fingerprint", line])[0], 0)

    def test_a_mismatched_fingerprint_refuses_and_prints_BOTH_values(self):
        # ⭐ CONTROL: weaken the equality in `_assert_expected_fingerprint` to
        # `actual.startswith(expected)` and this reds — the near-miss below is the true
        # fingerprint with its last four characters removed, which is exactly what a
        # truncated copy-paste looks like and exactly what a prefix compare accepts.
        actual = pbt.fingerprint_of_pubkey_file(self.pub_path)
        near_miss = actual[:-4]

        with self.assertRaises(SystemExit) as cm:
            self._run(["--expect-fingerprint", near_miss])

        msg = str(cm.exception)
        self.assertIn("MISMATCH", msg)
        self.assertIn(near_miss, msg)
        self.assertIn(actual, msg)
        self.assertFalse(os.path.exists(self.authz), "a mismatched run must write nothing")

    def test_expect_fingerprint_works_against_a_key_read_from_stdin(self):
        # F11: `ssh-keygen -lf` needs a file and --pubkey-stdin has none, so the stdin
        # arm temp-files the key. The temp file must not survive the run.
        fp = pbt.fingerprint_of_pubkey_file(self.pub_path)
        before = set(os.listdir(tempfile.gettempdir()))
        args = pbt.build_parser().parse_args([
            "--role", "a", "--agent", "impl", "--artisan", "/opt/bridge/artisan",
            "--ssh-account", "bridge", "--pubkey-stdin", "--expect-fingerprint", fp,
        ])
        pw = _PwEntry(self.home, os.geteuid())
        buf = io.StringIO()
        with mock.patch("pwd.getpwnam", return_value=pw), \
             mock.patch.object(sys, "stdin", io.StringIO(self.pubkey + "\n")), \
             mock.patch.object(os, "chown", side_effect=lambda *a, **k: self.chowns.append((a, k))), \
             contextlib.redirect_stdout(buf):
            rc = pbt.run_role_a(args)

        self.assertEqual(rc, 0)
        self.assertIn(f"Pinned fingerprint: {fp}", buf.getvalue())
        leaked = [n for n in set(os.listdir(tempfile.gettempdir())) - before
                  if n.startswith("provision-board-tools-")]
        self.assertEqual(leaked, [], "the stdin temp key file must be unlinked")

    def test_the_closing_line_sends_the_seat_to_certify_only(self):
        # §1.5 — the old line sent the operator to `bridge:check --probe-tools-ssh` FROM
        # HOST A, which stamps the client-half ledger row from the wrong box (DL-229).
        _rc, out = self._run()
        self.assertIn("--role b --certify-only --agent impl", out)
        self.assertNotIn("--probe-tools-ssh", out)


class ReadRecordedSshTransport(unittest.TestCase):
    """The pure reader behind `--certify-only`, over its own refusals."""

    def _text(self, doc):
        return json.dumps(doc)

    def test_it_returns_the_env_block_of_the_named_channel(self):
        env = pbt.read_recorded_ssh_transport(
            self._text({"mcpServers": {"chan": {"env": {"BRIDGE_TOOLS_SSH_TARGET": "b@h"}}}}), "chan"
        )
        self.assertEqual(env["BRIDGE_TOOLS_SSH_TARGET"], "b@h")

    def test_unparseable_json_names_the_cause(self):
        with self.assertRaises(ValueError) as cm:
            pbt.read_recorded_ssh_transport("{not json", "chan")
        self.assertIn("could not be parsed as JSON", str(cm.exception))

    def test_a_missing_channel_entry_names_the_channel(self):
        with self.assertRaises(ValueError) as cm:
            pbt.read_recorded_ssh_transport(self._text({"mcpServers": {"other": {}}}), "chan")
        self.assertIn("`mcpServers.chan` entry", str(cm.exception))

    def test_an_entry_with_no_env_block_is_not_an_empty_env(self):
        with self.assertRaises(ValueError) as cm:
            pbt.read_recorded_ssh_transport(self._text({"mcpServers": {"chan": {}}}), "chan")
        self.assertIn("no `env` block", str(cm.exception))


class CertifyOnly(unittest.TestCase):
    """`--role b --certify-only` (card#8971 §1.4): certify the transport THIS SEAT
    RECORDED, without re-deploying anything.

    ⭐ THE KEY PATH IN THESE FIXTURES IS DELIBERATELY NOT THE DERIVED DEFAULT
    (`~/.ssh/<agent>-board-tools`). A fixture that recorded the default would pass
    identically whether the code read the record or re-derived the path from `--agent`,
    which is the one thing these tests exist to tell apart.
    """

    @classmethod
    def setUpClass(cls):
        if _SSH_KEYGEN is None:  # pragma: no cover
            raise unittest.SkipTest("ssh-keygen not on PATH (banner printed at import)")
        _KeyFixtures.build()

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.project = os.path.join(self.tmp.name, "project")
        os.makedirs(self.project)
        self.mcp_path = os.path.join(self.project, ".mcp.json")
        # NOT <home>/.ssh/kanban-solo-board-tools — see the class docstring.
        self.key_dir = os.path.join(self.tmp.name, "elsewhere")
        os.makedirs(self.key_dir)
        self.key_path = os.path.join(self.key_dir, "a-key-with-another-name")
        shutil.copyfile(_KeyFixtures.plain, self.key_path)
        os.chmod(self.key_path, 0o600)
        shutil.copyfile(_KeyFixtures.plain + ".pub", self.key_path + ".pub")

    def _write_mcp(self, env=None, channel="kanbanboard-agent", entry_present=True):
        env = {
            "BRIDGE_TOOLS_SSH_TARGET": "bridge@hostA.example",
            "BRIDGE_TOOLS_SSH_KEY": self.key_path,
            "BRIDGE_TOOLS_SSH_PORT": "2222",
        } if env is None else env
        entry = {"command": "node", "env": env} if entry_present else {}
        with open(self.mcp_path, "w", encoding="utf-8") as fh:
            json.dump({"mcpServers": {channel: entry}}, fh)

    def _run(self, extra_argv=()):
        argv = [
            "--role", "b", "--certify-only", "--agent", "kanban-solo",
            "--project-dir", self.project, "--channel-name", "kanbanboard-agent",
            *extra_argv,
        ]
        args = pbt.build_parser().parse_args(argv)
        calls = {"self_cert": [], "known_hosts": [], "keygen": [], "deploy": []}
        buf = io.StringIO()
        with mock.patch.object(pbt, "_self_cert",
                               side_effect=lambda *a: calls["self_cert"].append(a) or 0), \
             mock.patch.object(pbt, "_seed_known_hosts",
                               side_effect=lambda *a: calls["known_hosts"].append(a)), \
             mock.patch.object(pbt, "_keygen", side_effect=lambda *a: calls["keygen"].append(a)), \
             mock.patch.object(pbt, "_deploy_snapshot", side_effect=lambda *a: calls["deploy"].append(a)), \
             contextlib.redirect_stdout(buf):
            rc = pbt.main(argv)
        return rc, calls, buf.getvalue()

    def test_it_certifies_with_the_RECORDED_key_and_target(self):
        self._write_mcp()

        rc, calls, out = self._run()

        self.assertEqual(rc, 0)
        # PRESENCE witness: the exact recorded triple reached _self_cert.
        self.assertEqual(calls["self_cert"], [("bridge@hostA.example", self.key_path, "2222")])
        self.assertEqual(calls["known_hosts"], [("hostA.example", "2222")])
        self.assertIn("recorded in this seat's .mcp.json", out)

    def test_it_neither_generates_a_key_nor_deploys_a_snapshot(self):
        # ABSENCE witnesses, paired with the presence one above so a run that did
        # nothing at all cannot pass this class.
        self._write_mcp()

        _rc, calls, _out = self._run()

        self.assertEqual(calls["keygen"], [])
        self.assertEqual(calls["deploy"], [])

    def test_it_does_not_rewrite_the_seats_mcp_json(self):
        self._write_mcp()
        with open(self.mcp_path, encoding="utf-8") as fh:
            before = fh.read()

        self._run()

        with open(self.mcp_path, encoding="utf-8") as fh:
            self.assertEqual(fh.read(), before)

    def test_an_absent_mcp_json_says_provision_first(self):
        with self.assertRaises(SystemExit) as cm:
            self._run()
        self.assertIn("provision first", str(cm.exception))

    def test_an_absent_channel_entry_says_provision_first(self):
        self._write_mcp(channel="some-other-channel")
        with self.assertRaises(SystemExit) as cm:
            self._run()
        self.assertIn("provision first", str(cm.exception))

    def test_a_recorded_env_missing_the_KEY_refuses(self):
        # ⛔ Without it the round-trip would fall back to the seat's DEFAULT ssh
        # identity and print a green line for a door this key never opened.
        self._write_mcp(env={"BRIDGE_TOOLS_SSH_TARGET": "bridge@hostA.example"})
        with self.assertRaises(SystemExit) as cm:
            self._run()
        self.assertIn("BRIDGE_TOOLS_SSH_KEY", str(cm.exception))

    def test_a_recorded_env_missing_the_TARGET_refuses(self):
        self._write_mcp(env={"BRIDGE_TOOLS_SSH_KEY": self.key_path})
        with self.assertRaises(SystemExit) as cm:
            self._run()
        self.assertIn("BRIDGE_TOOLS_SSH_TARGET", str(cm.exception))

    def test_a_recorded_key_that_is_no_longer_on_disk_refuses_naming_the_record(self):
        self._write_mcp()
        os.unlink(self.key_path)
        with self.assertRaises(SystemExit) as cm:
            self._run()
        msg = str(cm.exception)
        self.assertIn("records BRIDGE_TOOLS_SSH_KEY", msg)
        self.assertNotIn("--ssh-key", msg.split("Re-run")[0])

    def test_ssh_target_together_with_certify_only_is_refused(self):
        self._write_mcp()
        with self.assertRaises(SystemExit) as cm:
            self._run(["--ssh-target", "someone@elsewhere"])
        self.assertIn("cannot be given with --certify-only", str(cm.exception))

    def test_ssh_key_together_with_certify_only_is_refused(self):
        self._write_mcp()
        with self.assertRaises(SystemExit) as cm:
            self._run(["--ssh-key", self.key_path])
        self.assertIn("cannot be given with --certify-only", str(cm.exception))

    def test_self_cert_and_certify_only_together_exit_2(self):
        # argparse owns the exclusion, so the conflict is rc 2 and one message.
        with self.assertRaises(SystemExit) as cm, contextlib.redirect_stderr(io.StringIO()):
            pbt.build_parser().parse_args([
                "--role", "b", "--agent", "a", "--certify-only", "--self-cert",
                "--project-dir", "/p", "--channel-name", "c",
            ])
        self.assertEqual(cm.exception.code, 2)

    def test_certify_only_without_project_dir_or_channel_name_refuses(self):
        with self.assertRaises(SystemExit) as cm:
            pbt.main(["--role", "b", "--certify-only", "--agent", "kanban-solo"])
        msg = str(cm.exception)
        self.assertIn("--project-dir", msg)
        self.assertIn("--channel-name", msg)
        # ⭐ AND NOT --ssh-target: that relaxation is the whole point of the mode.
        self.assertNotIn("--ssh-target", msg)


class RoleBFingerprintLine(unittest.TestCase):
    """The `Fingerprint:` line `--role b` prints for the operator (card#8971 §1.2).

    ⭐ ASSERTED OVER REAL STDOUT, THROUGH THE WRAPPER'S OWN PARSER. The same-box wrapper
    reads the pubkey path out of this leg's output by anchoring on the `Same-box:` marker
    (`parse_pubkey_path`); inserting a line ANYWHERE near it is exactly the change that
    silently breaks that parser. Running the real leg and feeding its real stdout to the
    real parser is the only version of this test that could catch it.
    """

    @classmethod
    def setUpClass(cls):
        if _SSH_KEYGEN is None:  # pragma: no cover
            raise unittest.SkipTest("ssh-keygen not on PATH (banner printed at import)")
        _KeyFixtures.build()

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.home = os.path.join(self.tmp.name, "home")
        self.project = os.path.join(self.tmp.name, "project")
        os.makedirs(os.path.join(self.home, ".ssh"))
        os.makedirs(self.project)

    def _run(self, extra_argv=()):
        argv = [
            "--role", "b", "--agent", "kanban-solo",
            "--ssh-target", "bridge@127.0.0.1",
            "--project-dir", self.project,
            "--channel-name", "kanbanboard-agent",
            *extra_argv,
        ]
        args = pbt.build_parser().parse_args(argv)

        def _keygen(agent, key_path):
            shutil.copyfile(_KeyFixtures.plain, key_path)
            os.chmod(key_path, 0o600)
            shutil.copyfile(_KeyFixtures.plain + ".pub", key_path + ".pub")

        buf = io.StringIO()
        with mock.patch.object(pbt, "_host_b_home", return_value=self.home), \
             mock.patch.object(pbt, "_keygen", side_effect=_keygen), \
             mock.patch.object(pbt, "_deploy_snapshot"), \
             mock.patch.object(pbt, "_seed_known_hosts"), \
             contextlib.redirect_stdout(buf):
            rc = pbt.run_role_b(args)
        return rc, buf.getvalue()

    def test_the_fingerprint_line_is_its_own_line_between_the_key_and_the_marker(self):
        rc, out = self._run()
        self.assertEqual(rc, 0)

        pub_path = os.path.join(self.home, ".ssh", "kanban-solo-board-tools.pub")
        expected = pbt.fingerprint_of_pubkey_file(pub_path)
        lines = out.splitlines()
        fp_line = next(ln for ln in lines if ln.startswith("Fingerprint: "))

        # The whole line is the label and the value: no size, no comment, no key type.
        # The operator retypes this into --expect-fingerprint.
        self.assertEqual(fp_line, f"Fingerprint: {expected}")
        self.assertLess(
            lines.index(fp_line),
            lines.index("Same-box: hand this path to `--role a --pubkey-from`:"),
            "the fingerprint belongs inside the handoff block, before the same-box marker",
        )

    def test_the_same_box_wrappers_parser_still_finds_the_pub_path_in_this_output(self):
        # F18 — the wrapper's fixture and this leg's real output are the same shape, and
        # this is the assertion that keeps them so.
        _rc, out = self._run()
        self.assertEqual(
            sbx.parse_pubkey_path(out),
            os.path.join(self.home, ".ssh", "kanban-solo-board-tools.pub"),
        )

    def test_expect_fingerprint_refuses_before_the_key_is_handed_off(self):
        with self.assertRaises(SystemExit) as cm:
            self._run(["--expect-fingerprint", "SHA256:definitely-not-this-key"])
        self.assertIn("MISMATCH", str(cm.exception))
