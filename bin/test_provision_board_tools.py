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


if __name__ == "__main__":
    unittest.main()
