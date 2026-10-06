#!/usr/bin/env python3
"""The compatibility stub at the launcher's old checkout path (card#11328, review M1).

A seat whose hand-written `~/start-claude.sh` execs `<checkout>/examples/start-channel-session.sh`
must keep launching after the pull that moved the launcher to
`examples/channel-servers/bin/start-claude.sh`. This runs the REAL stub, from the checkout, with a
fake `claude` first on PATH, and asserts the canonical launcher ran: the channel it resolved and
the arguments it passed through. Here, not in the channel-server suite, because CI runs that suite
in a copy of `examples/channel-servers/` alone, where the stub does not exist.
"""

import os
import shutil
import subprocess
import tempfile
import unittest

_REPO = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
_STUB = os.path.join(_REPO, "examples", "start-channel-session.sh")


@unittest.skipIf(os.name == "nt" or shutil.which("bash") is None, "runs the bash stub")
class CompatStub(unittest.TestCase):
    def test_the_stub_runs_the_canonical_launcher_with_its_arguments(self):
        with tempfile.TemporaryDirectory() as tmp:
            bin_dir = os.path.join(tmp, "bin")
            os.makedirs(bin_dir)
            record = os.path.join(tmp, "claude-args")
            claude = os.path.join(bin_dir, "claude")
            with open(claude, "w", encoding="utf-8") as fh:
                fh.write(f"#!/bin/sh\nprintf '%s\\n' \"$@\" > '{record}'\n")
            os.chmod(claude, 0o755)
            env = {
                "PATH": f"{bin_dir}:{os.environ['PATH']}",
                "HOME": tmp,
                "XDG_RUNTIME_DIR": tmp,
                "CLAUDE_SETTINGS_LOCAL": os.path.join(tmp, "absent.json"),
                "BRIDGE_CHANNEL_SERVER_DIR": os.path.join(tmp, "no-copied-server"),
            }
            # Executed as a hand shim does: by path, through its own shebang and mode.
            run = subprocess.run([_STUB, "--channel", "stub-channel", "--dangerously-skip-permissions"],
                                 env=env, capture_output=True, text=True, timeout=60)
            self.assertEqual(run.returncode, 0, run.stderr)
            with open(record, encoding="utf-8") as fh:
                self.assertEqual(fh.read().split(), ["--dangerously-load-development-channels", "server:stub-channel",
                                                     "--dangerously-skip-permissions"])

    def test_the_stub_delegates_rather_than_carrying_a_copy(self):
        with open(_STUB, encoding="utf-8") as fh:
            text = fh.read()
        self.assertIn('exec bash "$here/channel-servers/bin/start-claude.sh" "$@"', text)
        self.assertLess(len(text.splitlines()), 15, "a stub, not a second launcher")


if __name__ == "__main__":
    unittest.main()
