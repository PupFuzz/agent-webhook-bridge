#!/usr/bin/env python3
"""Unit tests for the two pure functions in provision-board-tools.py (FR #5010).

Each must-fix has at least one case that goes RED if the guard is reverted:
  - M1  full-line pubkey validation (multi-line / CRLF paste rejected)
  - M3  HTTP->SSH re-provision deletes the sibling transport keys
  - merge collision guard + refuse-on-unparseable + create-if-absent
"""

import importlib.util
import json
import os
import unittest
from unittest import mock

_HERE = os.path.dirname(os.path.abspath(__file__))
_spec = importlib.util.spec_from_file_location(
    "provision_board_tools", os.path.join(_HERE, "provision-board-tools.py")
)
pbt = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(pbt)

# A real single-line ECDSA P-256 public key (blob is valid base64, arbitrary content).
_REAL_ECDSA = (
    "ecdsa-sha2-nistp256 AAAAE2VjZHNhLXNoYTItbmlzdHAyNTYAAAAIbmlzdHAyNTYAAABBBABC"
    "def0123456789+/ABCdef0123456789+/ABCdef0123456789+/ABCdef0123456789= agent-board-tools"
)
_IDENTITY = lambda p: p  # noqa: E731 — avoid FS realpath in pure-function tests


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


if __name__ == "__main__":
    unittest.main()
