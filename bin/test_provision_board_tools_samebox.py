#!/usr/bin/env python3
"""Unit tests for the same-box one-shot wrapper (card 5090, FR #5010 follow-on).

Coverage the design rests on:
  - preflight fail-closed modes: not-root, missing agent user, missing ssh-account user,
    missing agent .mcp.json, agent's OWN provisioner absent (the "own checkout" message,
    NEVER a global-staging fallback), agent provisioner unreadable-by-agent, missing host-A
    provisioner/artisan.
  - happy-path arg assembly: the EXACT role-b / role-a argv the wrapper would run.
  - prove-it-can-fail: reverting the readable-by-agent guard or the ambiguity guard reds a
    named test below (see the comments on those tests).
"""

import importlib.util
import json
import os
import sys
import types
import unittest

_HERE = os.path.dirname(os.path.abspath(__file__))
_spec = importlib.util.spec_from_file_location(
    "provision_board_tools_samebox",
    os.path.join(_HERE, "provision-board-tools-samebox.py"),
)
sb = importlib.util.module_from_spec(_spec)
# Register before exec so the @dataclass can resolve its own module (string annotations
# under `from __future__ import annotations` look the module up in sys.modules).
sys.modules["provision_board_tools_samebox"] = sb
_spec.loader.exec_module(sb)

_AGENT = "kanban-solo"
_ACCOUNT = "bridge-user"
_AGENT_HOME = "/home/kanban-solo"
_AGENT_BIN = "/home/kanban-solo/agent-webhook-bridge-dev/bin/provision-board-tools.py"
_MCP = "/home/kanban-solo/agent-webhook-bridge-dev/.mcp.json"
_HOSTA = "/home/bridge-user/agent-webhook-bridge-prod"
_HOSTA_BIN = _HOSTA + "/bin/provision-board-tools.py"
_ARTISAN = _HOSTA + "/artisan"

_MCP_TEXT = json.dumps({
    "mcpServers": {
        "kanbanboard-agent": {
            "command": "node",
            "args": ["/deploy/agent-webhook-bridge-channel.mjs"],
            "env": {"BRIDGE_CHANNEL_NAME": "kanbanboard-agent"},
        }
    }
})


class FakeFs:
    """A fully-injected filesystem seam mirroring RealFs's surface."""

    def __init__(self, *, euid=0, users=None, files=None, provisioners=None,
                 mcp_files=None, mcp_text=_MCP_TEXT, php="/usr/bin/php",
                 readable_by=None, readable=True):
        self._euid = euid
        self._users = users if users is not None else {_AGENT: _AGENT_HOME, _ACCOUNT: "/home/bridge-user"}
        self._files = set(files if files is not None else {_AGENT_BIN, _MCP, _HOSTA_BIN, _ARTISAN})
        self._provisioners = provisioners if provisioners is not None else [_AGENT_BIN]
        self._mcp_files = mcp_files if mcp_files is not None else [_MCP]
        self._mcp_text = mcp_text
        self._php = php
        # default: the agent can read its own bin
        self._readable_by = readable_by if readable_by is not None else {(_AGENT, _AGENT_BIN)}
        self._readable = readable

    def geteuid(self):
        return self._euid

    def getpwnam(self, name):
        if name not in self._users:
            raise KeyError(name)
        return types.SimpleNamespace(pw_dir=self._users[name], pw_uid=1000, pw_gid=1000)

    def isfile(self, path):
        return path in self._files

    def which(self, name):
        return self._php if name == "php" else "/usr/bin/" + name

    def read_text(self, path):
        return self._mcp_text

    def readable_by(self, user, path):
        return (user, path) in self._readable_by

    def readable(self, path):
        return self._readable

    def find_agent_provisioners(self, agent_home):
        return list(self._provisioners)

    def find_mcp_files(self, agent_home):
        return list(self._mcp_files)


def _args(**over):
    argv = ["--agent", over.pop("agent", _AGENT),
            "--ssh-account", over.pop("ssh_account", _ACCOUNT),
            "--hostA-checkout", over.pop("hostA_checkout", _HOSTA)]
    for k, v in over.items():
        argv += ["--" + k.replace("_", "-"), v]
    return sb.build_parser().parse_args(argv)


# --------------------------------------------------------------------------- #
# Pure helpers
# --------------------------------------------------------------------------- #
class DeriveChannelName(unittest.TestCase):
    def test_single_key_returned(self):
        self.assertEqual(sb.derive_channel_name(_MCP_TEXT), "kanbanboard-agent")

    def test_unparseable_raises(self):
        with self.assertRaises(sb.PreflightError):
            sb.derive_channel_name("{ not json")

    def test_no_servers_raises(self):
        with self.assertRaises(sb.PreflightError):
            sb.derive_channel_name(json.dumps({"mcpServers": {}}))

    def test_multiple_keys_raises(self):
        with self.assertRaises(sb.PreflightError):
            sb.derive_channel_name(json.dumps({"mcpServers": {"a": {}, "b": {}}}))


class ParsePubkeyPath(unittest.TestCase):
    _REAL_ROLE_B_TAIL = (
        "known_hosts: seeded 1 host key(s) for 127.0.0.1.\n"
        "\n"
        "Public key for the host-A handoff (paste into `--role a --pubkey-stdin`):\n"
        "  ecdsa-sha2-nistp256 AAAAdummy kanban-solo-board-tools\n"
        "Same-box: hand this path to `--role a --pubkey-from`:\n"
        "  /home/kanban-solo/.ssh/kanban-solo-board-tools.pub\n"
    )

    def test_extracts_the_pub_path(self):
        self.assertEqual(
            sb.parse_pubkey_path(self._REAL_ROLE_B_TAIL),
            "/home/kanban-solo/.ssh/kanban-solo-board-tools.pub",
        )

    def test_missing_marker_raises(self):
        # RED-when-reverted: a wrapper that blindly took the last line instead of the
        # marker-anchored path would not raise here.
        with self.assertRaises(sb.PreflightError):
            sb.parse_pubkey_path("some unrelated output\nwith no handoff line\n")


class BuildArgv(unittest.TestCase):
    def test_role_b_argv_exact(self):
        self.assertEqual(
            sb.build_role_b_argv("python3", _AGENT_BIN, _AGENT, _ACCOUNT, "/proj", "chan"),
            ["python3", _AGENT_BIN, "--role", "b", "--agent", _AGENT,
             "--ssh-target", "bridge-user@127.0.0.1",
             "--project-dir", "/proj", "--channel-name", "chan"],
        )

    def test_role_b_argv_never_self_certs(self):
        # The key is not pinned on host A until role-a runs; --self-cert here would fail closed.
        self.assertNotIn("--self-cert", sb.build_role_b_argv("py", "b", _AGENT, _ACCOUNT, "/p", "c"))

    def test_role_a_argv_exact(self):
        self.assertEqual(
            sb.build_role_a_argv("python3", _HOSTA_BIN, _AGENT, _ACCOUNT, _ARTISAN, "/k.pub"),
            ["python3", _HOSTA_BIN, "--role", "a", "--agent", _AGENT,
             "--ssh-account", _ACCOUNT, "--artisan", _ARTISAN,
             "--pubkey-from", "/k.pub"],
        )


# --------------------------------------------------------------------------- #
# Preflight — happy path assembles the exact plan
# --------------------------------------------------------------------------- #
class PreflightHappyPath(unittest.TestCase):
    def test_plan_fields_resolved(self):
        plan = sb.preflight(_args(), FakeFs())
        self.assertEqual(plan.agent, _AGENT)
        self.assertEqual(plan.ssh_account, _ACCOUNT)
        self.assertEqual(plan.agent_bin, _AGENT_BIN)
        self.assertEqual(plan.hostA_bin, _HOSTA_BIN)
        self.assertEqual(plan.artisan, _ARTISAN)
        self.assertEqual(plan.storage, _HOSTA + "/storage")
        self.assertEqual(plan.project_dir, os.path.dirname(_MCP))
        self.assertEqual(plan.channel_name, "kanbanboard-agent")

    def test_assembled_role_b_argv_from_plan(self):
        plan = sb.preflight(_args(), FakeFs())
        self.assertEqual(
            sb.build_role_b_argv("python3", plan.agent_bin, plan.agent, plan.ssh_account,
                                 plan.project_dir, plan.channel_name),
            ["python3", _AGENT_BIN, "--role", "b", "--agent", _AGENT,
             "--ssh-target", "bridge-user@127.0.0.1",
             "--project-dir", os.path.dirname(_MCP), "--channel-name", "kanbanboard-agent"],
        )

    def test_channel_name_override_skips_mcp_read(self):
        plan = sb.preflight(_args(channel_name="explicit-chan"), FakeFs())
        self.assertEqual(plan.channel_name, "explicit-chan")


# --------------------------------------------------------------------------- #
# Preflight — fail-closed modes (card 5090 hard requirement 2)
# --------------------------------------------------------------------------- #
class PreflightFailClosed(unittest.TestCase):
    def test_not_root_fails(self):
        with self.assertRaises(sb.PreflightError) as cm:
            sb.preflight(_args(), FakeFs(euid=1000))
        self.assertIn("root", str(cm.exception).lower())

    def test_missing_agent_user_fails(self):
        with self.assertRaises(sb.PreflightError) as cm:
            sb.preflight(_args(), FakeFs(users={_ACCOUNT: "/home/bridge-user"}))
        self.assertIn("does not exist", str(cm.exception))

    def test_missing_ssh_account_user_fails(self):
        with self.assertRaises(sb.PreflightError) as cm:
            sb.preflight(_args(), FakeFs(users={_AGENT: _AGENT_HOME}))
        self.assertIn(_ACCOUNT, str(cm.exception))

    def test_missing_agent_mcp_json_fails(self):
        with self.assertRaises(sb.PreflightError) as cm:
            sb.preflight(_args(), FakeFs(mcp_files=[]))
        self.assertIn(".mcp.json", str(cm.exception))

    def test_ambiguous_mcp_json_fails(self):
        # RED-when-reverted: revert the >1 ambiguity guard to silently take cands[0] and
        # this stops raising.
        with self.assertRaises(sb.PreflightError) as cm:
            sb.preflight(_args(), FakeFs(mcp_files=[_MCP, "/home/kanban-solo/other/.mcp.json"]))
        self.assertIn("multiple", str(cm.exception).lower())

    def test_agent_provisioner_absent_fails_with_own_checkout_message_never_global(self):
        with self.assertRaises(sb.PreflightError) as cm:
            sb.preflight(_args(), FakeFs(provisioners=[]))
        msg = str(cm.exception)
        self.assertIn("own", msg.lower())
        self.assertIn("checkout", msg.lower())
        # Must NEVER suggest / perform a shared or global staging fallback.
        self.assertNotIn("/usr/local/bin", msg)
        self.assertNotIn("install -m", msg)

    def test_agent_provisioner_ambiguous_fails(self):
        with self.assertRaises(sb.PreflightError) as cm:
            sb.preflight(_args(), FakeFs(provisioners=[_AGENT_BIN, "/home/kanban-solo/other/bin/provision-board-tools.py"]))
        self.assertIn("--agent-bin", str(cm.exception))

    def test_agent_provisioner_unreadable_by_agent_fails(self):
        # RED-when-reverted: drop the readable_by(agent, agent_bin) gate and this stops
        # raising — a role-b that would then fail as the agent user is caught in preflight.
        with self.assertRaises(sb.PreflightError) as cm:
            sb.preflight(_args(), FakeFs(readable_by=set()))
        msg = str(cm.exception)
        self.assertIn("readable", msg.lower())
        self.assertNotIn("/usr/local/bin", msg)  # still no global fallback

    def test_missing_hostA_provisioner_fails(self):
        with self.assertRaises(sb.PreflightError) as cm:
            sb.preflight(_args(), FakeFs(files={_AGENT_BIN, _MCP, _ARTISAN}))
        self.assertIn("host-A provisioner", str(cm.exception))

    def test_missing_hostA_artisan_fails(self):
        with self.assertRaises(sb.PreflightError) as cm:
            sb.preflight(_args(), FakeFs(files={_AGENT_BIN, _MCP, _HOSTA_BIN}))
        self.assertIn("artisan", str(cm.exception))

    def test_missing_php_fails(self):
        with self.assertRaises(sb.PreflightError) as cm:
            sb.preflight(_args(), FakeFs(php=None))
        self.assertIn("php", str(cm.exception).lower())

    def test_bad_agent_name_rejected(self):
        with self.assertRaises(sb.PreflightError):
            sb.preflight(_args(agent="foo;rm"), FakeFs())

    def test_bad_ssh_account_rejected(self):
        with self.assertRaises(sb.PreflightError):
            sb.preflight(_args(ssh_account="../etc"), FakeFs())


if __name__ == "__main__":
    unittest.main()
