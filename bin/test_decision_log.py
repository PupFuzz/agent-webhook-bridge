"""Unit tests for bin/decision-log.py — the DL allocator's `next`.

`next` is VACUOUS on a healthy tree: no duplicate DL exists today, so a `next`
that returns whatever it is handed still prints a number. Every case here
therefore plants the defect and asserts the REFUSAL, naming the number — a check
seen to red is the only kind that witnesses anything.

The collision gate and the `## DL-NNN` grammar are agent-board-toolkit's since
card#12338, and their cases live in that repository's selftests. What is tested
here is this tool's use of that grammar: it is path-loaded from beside `next-dl`,
so every case runs against the REAL toolkit `_decision-log.py`, copied into a
fake toolkit `bin/`. Its source is `$AGENT_BOARD_TOOLKIT_DIR/bin/` when set (CI's
toolkit checkout), else the directory `next-dl` on PATH resolves to.
"""

import os
import shutil
import stat
import subprocess
import sys
import tempfile
import textwrap
import unittest
from pathlib import Path

TOOL = Path(__file__).with_name("decision-log.py")
GRAMMAR = "_decision-log.py"


def toolkit_grammar() -> Path:
    """The real toolkit grammar these cases run against. Absent is an ERROR, not a skip."""
    root = os.environ.get("AGENT_BOARD_TOOLKIT_DIR")
    if root:
        path = Path(root) / "bin" / GRAMMAR
    else:
        allocator = shutil.which("next-dl")
        if allocator is None:
            raise RuntimeError(
                "no agent-board toolkit to test against: set AGENT_BOARD_TOOLKIT_DIR to a toolkit "
                "checkout (v0.51.0 or later), or put the toolkit's next-dl on PATH"
            )
        path = Path(allocator).resolve().parent / GRAMMAR
    if not path.is_file():
        raise RuntimeError(f"{path} is missing — the toolkit is older than v0.51.0")
    return path


def log(*entries: str) -> str:
    """A decision log carrying one H2 entry per number, plus realistic noise."""
    body = "# Decision log\n\n> Append-only.\n\n"
    for entry in entries:
        body += f"## DL-{entry} — an entry\n\nMirrors kanban-board DL-999.\n\n"
    return body


class NextHarness(unittest.TestCase):
    """`next` is exercised against a FAKE allocator, reached through a PATH SYMLINK.

    The real one talks to the board's counter over the network; a test that
    called it would assert the board's current state, which changes under it.
    What is under test here is what this tool does with the number it is handed —
    the veto, the warning, and the refusal to invent a number when the allocator
    or its grammar is missing.

    The layout is a host PATH install: the fake `next-dl` and a copy of the real
    toolkit grammar sit in `toolkit/bin/`, and the directory on PATH holds only a
    symlink to `next-dl`. The grammar is found only if it is looked for where the
    link RESOLVES.
    """

    def setUp(self):
        self.dir = Path(tempfile.mkdtemp(prefix="dl-next-"))
        self.addCleanup(shutil.rmtree, self.dir, ignore_errors=True)
        self.bindir = self.dir / "fakebin"
        self.bindir.mkdir()
        self.toolkit_bin = self.dir / "toolkit" / "bin"
        self.toolkit_bin.mkdir(parents=True)
        shutil.copy(toolkit_grammar(), self.toolkit_bin / GRAMMAR)
        self.checkout = self.dir / "checkout"
        self.checkout.mkdir()

    def plant_allocator(self, stdout: str, rc: int = 0) -> None:
        script = self.toolkit_bin / "next-dl"
        script.write_text(
            textwrap.dedent(
                f"""\
                #!/bin/sh
                printf '%s\\n' "$*" > "{self.dir}/allocator-argv"
                printf '%s' {stdout!r}
                exit {rc}
                """
            ),
            encoding="utf-8",
        )
        script.chmod(script.stat().st_mode | stat.S_IEXEC)
        link = self.bindir / "next-dl"
        if not link.is_symlink():
            link.symlink_to(script)

    def plant_local_log(self, *entries: str) -> None:
        self.plant_local_text(log(*entries))

    def plant_local_text(self, text: str) -> None:
        (self.checkout / "CLAUDE_DECISIONS.md").write_text(text, encoding="utf-8")

    def run_next(self, *args: str, with_allocator: bool = True):
        env = dict(os.environ)
        env["PATH"] = str(self.bindir) if with_allocator else str(self.dir / "empty")
        env["BRIDGE_DL_CHECKOUT_GLOBS"] = str(self.checkout)
        return subprocess.run(
            [sys.executable, str(TOOL), "next", "--repo-root", str(self.dir), *args],
            capture_output=True,
            text=True,
            env=env,
        )


class NextAllocation(NextHarness):
    def test_the_allocated_number_is_returned_on_a_tree_with_a_known_max(self):
        self.plant_allocator("DL-0294\n")
        self.plant_local_log("291", "292", "293")

        proc = self.run_next()

        self.assertEqual(0, proc.returncode, proc.stdout + proc.stderr)
        self.assertEqual("DL-294", proc.stdout.strip())

    def test_the_bridge_board_is_pinned_so_a_caller_cannot_mint_from_another_sequence(self):
        self.plant_allocator("DL-0294\n")

        self.run_next()

        self.assertEqual("bridge --require-counter", (self.dir / "allocator-argv").read_text().strip())

    def test_peek_is_passed_through_and_still_requires_the_counter(self):
        self.plant_allocator("DL-0294\n")

        self.run_next("--peek")

        self.assertEqual("bridge --require-counter --peek", (self.dir / "allocator-argv").read_text().strip())

    def test_planted_positive_an_unpushed_local_mint_vetoes_the_allocation(self):
        # THE CARD'S INCIDENT. The counter cannot see a header on a branch that
        # was never pushed, so it hands back a number another agent is already
        # writing into an entry.
        self.plant_allocator("DL-0294\n")
        self.plant_local_log("293", "294")

        proc = self.run_next()

        self.assertEqual(4, proc.returncode, proc.stdout + proc.stderr)
        self.assertIn("REFUSING DL-294", proc.stderr)
        self.assertIn("CLAUDE_DECISIONS.md", proc.stderr)
        self.assertEqual("", proc.stdout)

    def test_a_counter_behind_the_local_logs_warns_without_refusing(self):
        # DL-294 itself is free, so the allocation stands; the NEXT one would
        # collide with the unpushed DL-295. A refusal here would be wider than
        # the predicate.
        self.plant_allocator("DL-0294\n")
        self.plant_local_log("293", "295")

        proc = self.run_next()

        self.assertEqual(0, proc.returncode, proc.stdout + proc.stderr)
        self.assertEqual("DL-294", proc.stdout.strip())
        self.assertIn("counter is BEHIND", proc.stderr)

    def test_no_warning_when_the_counter_leads_the_local_logs(self):
        self.plant_allocator("DL-0294\n")
        self.plant_local_log("292", "293")

        proc = self.run_next()

        self.assertEqual(0, proc.returncode)
        self.assertNotIn("counter is BEHIND", proc.stderr)

    def test_an_unenumerable_clone_warns_that_the_veto_is_narrower(self):
        # --repo-root is not a git repository here, so this clone's own
        # worktrees cannot be listed. Degrading silently would leave the veto
        # reporting where it stopped rather than what it covered.
        self.plant_allocator("DL-0294\n")

        proc = self.run_next()

        self.assertEqual(0, proc.returncode)
        self.assertIn("NARROWER", proc.stderr)


class NextRefusals(NextHarness):
    def test_a_missing_allocator_refuses_instead_of_falling_back_to_a_scan(self):
        self.plant_local_log("291", "292", "293")

        proc = self.run_next(with_allocator=False)

        self.assertEqual(3, proc.returncode, proc.stdout + proc.stderr)
        self.assertIn("REFUSING to mint", proc.stderr)
        # The whole point: it must not print DL-294 derived from the local max.
        self.assertEqual("", proc.stdout)

    def test_a_failing_allocator_yields_no_number(self):
        self.plant_allocator("", rc=1)

        proc = self.run_next()

        self.assertEqual(2, proc.returncode)
        self.assertEqual("", proc.stdout)

    def test_an_unreachable_counter_is_a_named_refusal_not_a_scanned_number(self):
        # `next-dl --require-counter` exits 4 where it would otherwise mint from its
        # non-atomic offline scan. That rc must not read as this tool's own exit 4
        # (a local collision), and the author must be told to retry, not to look
        # for a duplicate entry.
        self.plant_allocator("", rc=4)

        proc = self.run_next()

        self.assertEqual(5, proc.returncode, proc.stdout + proc.stderr)
        self.assertIn("REFUSING to mint", proc.stderr)
        self.assertIn("DL counter could not be reached", proc.stderr)
        self.assertIn("retry", proc.stderr)
        self.assertEqual("", proc.stdout)

    def test_the_control_another_allocator_failure_is_not_named_as_an_unreachable_counter(self):
        self.plant_allocator("", rc=1)

        proc = self.run_next()

        self.assertEqual(2, proc.returncode)
        self.assertNotIn("DL counter could not be reached", proc.stderr)

    def test_an_allocator_printing_no_number_is_not_read_as_zero(self):
        self.plant_allocator("nothing useful\n")

        proc = self.run_next()

        self.assertEqual(2, proc.returncode)
        self.assertIn("printed no DL number", proc.stderr)


class NextGrammar(NextHarness):
    """The veto reads headers through the TOOLKIT grammar, and says where it could not."""

    def test_the_grammar_is_found_where_the_path_link_resolves_not_beside_the_link(self):
        self.plant_allocator("DL-0294\n")
        self.plant_local_log("293", "294")

        proc = self.run_next()

        self.assertFalse((self.bindir / GRAMMAR).exists(), "the fixture must not put the grammar on PATH")
        self.assertEqual(4, proc.returncode, proc.stdout + proc.stderr)
        self.assertIn("REFUSING DL-294", proc.stderr)

    def test_an_indented_heading_vetoes_because_the_toolkit_grammar_reads_it(self):
        # `   ## DL-294` renders as an H2, and the toolkit grammar counts it. The
        # bridge's own grammar, retired by card#12338, matched `^##` only and
        # handed DL-294 back here at rc 0: this case is the evidence that the
        # veto reads the toolkit's grammar and not a copy of its own.
        self.plant_allocator("DL-0294\n")
        self.plant_local_text(log("293") + "   ## DL-294 — indented\n")

        proc = self.run_next()

        self.assertEqual(4, proc.returncode, proc.stdout + proc.stderr)
        self.assertIn("REFUSING DL-294", proc.stderr)

    def test_a_log_ending_inside_an_unclosed_fence_is_named_in_a_warning(self):
        self.plant_allocator("DL-0294\n")
        self.plant_local_text(log("293") + "```\nnever closed\n\n## DL-294 — hidden\n")

        proc = self.run_next()

        # The heading after the fence is hidden from the grammar, so the number is
        # handed back — and the warning is what says the veto could not see it.
        self.assertEqual(0, proc.returncode, proc.stdout + proc.stderr)
        self.assertEqual("DL-294", proc.stdout.strip())
        self.assertIn("opens a code fence at line", proc.stderr)
        self.assertIn("INVISIBLE to this veto", proc.stderr)

    def test_the_control_a_closed_fence_draws_no_fence_warning(self):
        self.plant_allocator("DL-0294\n")
        self.plant_local_text(log("293") + "```\n## DL-294 — quoted\n```\n")

        proc = self.run_next()

        self.assertEqual(0, proc.returncode, proc.stdout + proc.stderr)
        self.assertNotIn("opens a code fence", proc.stderr)

    def test_an_allocator_with_no_grammar_beside_it_refuses_before_allocating(self):
        # A toolkit older than v0.51.0 ships next-dl without _decision-log.py. A
        # claim RESERVES a number, so the refusal must come before the allocator
        # runs — asserted by the argv file the fake allocator would have written.
        self.plant_allocator("DL-0294\n")
        (self.toolkit_bin / GRAMMAR).unlink()

        proc = self.run_next()

        self.assertEqual(3, proc.returncode, proc.stdout + proc.stderr)
        self.assertIn("REFUSING to mint", proc.stderr)
        self.assertIn(GRAMMAR, proc.stderr)
        self.assertEqual("", proc.stdout)
        self.assertFalse((self.dir / "allocator-argv").exists(), "the allocator ran — a number may have been claimed")


if __name__ == "__main__":
    unittest.main()
