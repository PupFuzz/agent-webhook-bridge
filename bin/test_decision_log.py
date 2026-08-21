"""Unit tests for bin/decision-log.py — the DL allocator and its collision guard.

Both halves of card#7157 are VACUOUS on a healthy tree: no duplicate DL exists
today, so a guard that never fires still exits 0 and a `next` that returns
whatever it is handed still prints a number. Every case here therefore plants
the defect and asserts the REFUSAL, naming the number — a check seen to red is
the only kind that witnesses anything.
"""

import importlib.util
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

_SPEC = importlib.util.spec_from_file_location("decision_log", TOOL)
dl = importlib.util.module_from_spec(_SPEC)
assert _SPEC.loader is not None
_SPEC.loader.exec_module(dl)


def log(*entries: str) -> str:
    """A decision log carrying one H2 entry per number, plus realistic noise."""
    body = "# Decision log\n\n> Append-only.\n\n"
    for entry in entries:
        body += f"## DL-{entry} — an entry\n\nMirrors kanban-board DL-999.\n\n"
    return body


class Grammar(unittest.TestCase):
    def test_only_h2_dl_headers_count(self):
        text = (
            "# Decision log\n"
            "## DL-100 — real\n"
            "### DL-101 — an H3 is not an entry\n"
            "Prose citing DL-102 and `DL-103`.\n"
            "## DL-104 — real\n"
        )
        self.assertEqual([(100, 2), (104, 5)], dl.header_numbers(text))

    def test_leading_zeros_are_the_same_number(self):
        self.assertEqual([(31, 1)], dl.header_numbers("## DL-031 — padded\n"))

    def test_a_header_inside_a_fence_is_an_example_not_an_entry(self):
        text = "## DL-200 — real\n\n```markdown\n## DL-200 — what a header looks like\n```\n"
        self.assertEqual([(200, 1)], dl.header_numbers(text))

    def test_a_tilde_fence_closes_only_on_tildes(self):
        text = "~~~\n## DL-300 — inside\n```\n## DL-301 — still inside\n~~~\n## DL-302 — out\n"
        self.assertEqual([(302, 6)], dl.header_numbers(text))


class CheckHarness(unittest.TestCase):
    def setUp(self):
        self.dir = Path(tempfile.mkdtemp(prefix="dl-check-"))
        self.addCleanup(shutil.rmtree, self.dir, ignore_errors=True)

    def write(self, name: str, text: str) -> str:
        path = self.dir / name
        path.write_text(text, encoding="utf-8")
        return str(path)

    def check(self, *args: str):
        proc = subprocess.run(
            [sys.executable, str(TOOL), "check", *args],
            capture_output=True,
            text=True,
        )
        return proc


class CheckRefusals(CheckHarness):
    def test_planted_positive_number_already_on_the_target_branch_is_refused(self):
        # The measured shape: this change branched at DL-293, minted DL-294, and
        # the target branch gained its OWN DL-294 in the meantime. The file at
        # head does not contain the target's entry, so nothing local looks wrong.
        base = self.write("base.md", log("293"))
        head = self.write("head.md", log("293", "294"))
        target = self.write("target.md", log("293", "294"))

        proc = self.check("--head", head, "--base", base, "--target", target)

        self.assertEqual(6, proc.returncode, proc.stdout + proc.stderr)
        self.assertIn("DL-294", proc.stderr)
        self.assertIn("ALREADY IN USE", proc.stderr)

    def test_planted_positive_number_repeated_at_head_is_refused(self):
        # A number the change's OWN base already carries: because the log is
        # append-only the collision is visible in one file.
        head = self.write("head.md", log("293", "294", "294"))

        proc = self.check("--head", head)

        self.assertEqual(5, proc.returncode, proc.stdout + proc.stderr)
        self.assertIn("DL-294 is used TWICE", proc.stderr)

    def test_the_refusal_names_every_colliding_number_not_just_the_first(self):
        base = self.write("base.md", log("293"))
        head = self.write("head.md", log("293", "294", "295"))
        target = self.write("target.md", log("293", "294", "295"))

        proc = self.check("--head", head, "--base", base, "--target", target)

        self.assertEqual(6, proc.returncode)
        self.assertIn("DL-294", proc.stderr)
        self.assertIn("DL-295", proc.stderr)


class CheckPinnedNegatives(CheckHarness):
    def test_a_genuinely_new_number_passes(self):
        base = self.write("base.md", log("293"))
        head = self.write("head.md", log("293", "295"))
        target = self.write("target.md", log("293", "294"))

        proc = self.check("--head", head, "--base", base, "--target", target)

        self.assertEqual(0, proc.returncode, proc.stdout + proc.stderr)
        self.assertIn("DL-295", proc.stdout)

    def test_a_change_that_mints_nothing_passes(self):
        base = self.write("base.md", log("293"))
        head = self.write("head.md", log("293"))
        target = self.write("target.md", log("293", "294"))

        proc = self.check("--head", head, "--base", base, "--target", target)

        self.assertEqual(0, proc.returncode, proc.stdout + proc.stderr)
        self.assertIn("adds 0 DL entries", proc.stdout)

    def test_an_entry_carried_over_from_base_is_not_a_collision_with_the_target(self):
        # DL-293 is on base, head and target. It is the SAME entry in all three,
        # not a mint — refusing it would red every PR opened after it landed.
        base = self.write("base.md", log("293"))
        head = self.write("head.md", log("293", "296"))
        target = self.write("target.md", log("293"))

        proc = self.check("--head", head, "--base", base, "--target", target)

        self.assertEqual(0, proc.returncode, proc.stdout + proc.stderr)

    def test_a_number_below_the_target_max_is_not_refused_for_being_low(self):
        # The operator hand-assigns blocks (294/295/296 during the card#7157
        # dispatch), so the change carrying 295 can land AFTER the one carrying
        # 296. The predicate is "already in use", never "must exceed the max".
        base = self.write("base.md", log("293"))
        head = self.write("head.md", log("293", "295"))
        target = self.write("target.md", log("293", "296"))

        proc = self.check("--head", head, "--base", base, "--target", target)

        self.assertEqual(0, proc.returncode, proc.stdout + proc.stderr)


class CheckUsage(CheckHarness):
    def test_base_without_target_is_a_usage_error_not_a_half_check(self):
        base = self.write("base.md", log("293"))
        head = self.write("head.md", log("293", "294"))

        proc = self.check("--head", head, "--base", base)

        self.assertEqual(2, proc.returncode)
        self.assertIn("given together or not at all", proc.stderr)

    def test_an_unreadable_log_is_not_reported_as_an_empty_one(self):
        head = self.write("head.md", log("293"))

        proc = self.check("--head", head, "--base", str(self.dir / "absent.md"), "--target", head)

        self.assertEqual(2, proc.returncode)
        self.assertIn("cannot read", proc.stderr)


class NextHarness(unittest.TestCase):
    """`next` is exercised against a FAKE allocator on PATH.

    The real one talks to the board's counter over the network; a test that
    called it would assert the board's current state, which changes under it.
    What is under test here is what this tool does with the number it is handed —
    the veto, the warning, and the refusal to invent a number when the allocator
    is missing.
    """

    def setUp(self):
        self.dir = Path(tempfile.mkdtemp(prefix="dl-next-"))
        self.addCleanup(shutil.rmtree, self.dir, ignore_errors=True)
        self.bindir = self.dir / "fakebin"
        self.bindir.mkdir()
        self.checkout = self.dir / "checkout"
        self.checkout.mkdir()

    def plant_allocator(self, stdout: str, rc: int = 0) -> None:
        script = self.bindir / "next-dl"
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

    def plant_local_log(self, *entries: str) -> None:
        (self.checkout / "CLAUDE_DECISIONS.md").write_text(log(*entries), encoding="utf-8")

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

        self.assertEqual("bridge", (self.dir / "allocator-argv").read_text().strip())

    def test_peek_is_passed_through(self):
        self.plant_allocator("DL-0294\n")

        self.run_next("--peek")

        self.assertEqual("bridge --peek", (self.dir / "allocator-argv").read_text().strip())

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

    def test_an_allocator_printing_no_number_is_not_read_as_zero(self):
        self.plant_allocator("nothing useful\n")

        proc = self.run_next()

        self.assertEqual(2, proc.returncode)
        self.assertIn("printed no DL number", proc.stderr)


class ShippedDecisionLog(unittest.TestCase):
    def test_this_repo_s_own_decision_log_uses_no_number_twice(self):
        repo_log = TOOL.resolve().parent.parent / "CLAUDE_DECISIONS.md"
        numbers = [n for n, _ in dl.header_numbers(repo_log.read_text(encoding="utf-8"))]

        duplicates = {n for n in numbers if numbers.count(n) > 1}

        self.assertEqual(set(), duplicates)
        # A control on the assertion above: an empty or near-empty parse would
        # satisfy "no duplicates" while reading nothing. The sequence is sparse
        # (numbers minted in kanban-board and only mirrored here leave gaps), so
        # the floor is a count plus two entries that are certainly present —
        # never `max == len`.
        self.assertGreater(len(numbers), 150, "the grammar matched almost nothing — it is not reading the log")
        self.assertIn(1, numbers)
        self.assertIn(293, numbers)


if __name__ == "__main__":
    unittest.main()
