"""Unit tests for bin/changelog-section.py — the single CHANGELOG extractor.

Red-when-reverted: every case here names a specific way the extractor could be
wrong and asserts the behaviour that distinguishes it, rather than asserting
that it "works". The boundary cases (a section that runs to EOF, a header that
is a PREFIX of another label) are the ones an awk one-liner gets wrong quietly,
and quiet is the whole defect class this file's subject belongs to (card#5910).
"""

import importlib.util
import io
import unittest
from contextlib import redirect_stderr, redirect_stdout
from pathlib import Path

_SPEC = importlib.util.spec_from_file_location(
    "changelog_section", Path(__file__).with_name("changelog-section.py")
)
cs = importlib.util.module_from_spec(_SPEC)
assert _SPEC.loader is not None
_SPEC.loader.exec_module(cs)


CHANGELOG = """# Changelog

Preamble prose that must never appear in any section.

## [Unreleased]

### Fixed
- something (card#1234)

## [1.2.0] - 2026-01-02

body of 1.2.0

## [1.2] - 2026-01-01

body of 1.2, whose label is a PREFIX of 1.2.0's

## [0.1.0] - 2025-12-31

last section, runs to EOF
"""


class Extract(unittest.TestCase):
    def test_section_stops_at_the_next_header(self):
        got = cs.extract(CHANGELOG, "1.2.0")
        self.assertEqual(got, "## [1.2.0] - 2026-01-02\n\nbody of 1.2.0\n\n")
        self.assertNotIn("body of 1.2,", got)

    def test_last_section_runs_to_eof(self):
        self.assertEqual(cs.extract(CHANGELOG, "0.1.0"), "## [0.1.0] - 2025-12-31\n\nlast section, runs to EOF\n")

    def test_unreleased_is_just_another_label(self):
        self.assertIn("something (card#1234)", cs.extract(CHANGELOG, "Unreleased"))

    def test_a_label_that_prefixes_another_gets_its_own_section(self):
        # `## [1.2]` must not be answered with `## [1.2.0]`'s body. The bracket
        # in the header the extractor matches is what makes the two distinct;
        # a prefix match on the bare version would return the wrong release's
        # notes, and the publisher would ship them under the wrong tag.
        self.assertIn("body of 1.2,", cs.extract(CHANGELOG, "1.2"))
        self.assertNotIn("body of 1.2.0", cs.extract(CHANGELOG, "1.2"))

    def test_absent_label_is_none_not_an_empty_string(self):
        self.assertIsNone(cs.extract(CHANGELOG, "9.9.9"))

    def test_the_preamble_is_never_part_of_a_section(self):
        for label in ("Unreleased", "1.2.0", "0.1.0"):
            self.assertNotIn("Preamble prose", cs.extract(CHANGELOG, label))


class Truncate(unittest.TestCase):
    URL = "https://example.invalid/CHANGELOG.md"

    def test_result_fits_the_limit_and_carries_the_pointer(self):
        section = "## [1.0.0]\n" + "".join(f"line {i} padding padding padding\n" for i in range(500))
        out = cs.truncate(section, 2_000, self.URL)
        self.assertLessEqual(len(out.encode("utf-8")), 2_000)
        self.assertIn(self.URL, out)
        self.assertTrue(out.startswith("## [1.0.0]\n"))

    def test_truncation_is_line_granular(self):
        section = "## [1.0.0]\n" + "".join(f"line {i}\n" for i in range(500))
        out = cs.truncate(section, 500, self.URL)
        body = out.split("\n---\n", 1)[0]
        # Every retained line is a WHOLE line of the input: no partial line can
        # be produced, so no markdown construct is ever cut in half.
        self.assertTrue(all(line in section.splitlines() for line in body.splitlines()))

    def test_a_budget_too_small_for_any_line_still_yields_the_pointer(self):
        out = cs.truncate("## [1.0.0]\nbody\n", 10, self.URL)
        self.assertIn(self.URL, out)

    def test_the_reported_size_is_the_untruncated_sections_size(self):
        section = "## [1.0.0]\n" + "x" * 5_000 + "\n"
        out = cs.truncate(section, 1_000, self.URL)
        self.assertIn(str(len(section.encode("utf-8"))), out)


class Cli(unittest.TestCase):
    def _run(self, *argv):
        out, err = io.StringIO(), io.StringIO()
        with redirect_stdout(out), redirect_stderr(err):
            rc = cs.main(list(argv))
        return rc, out.getvalue(), err.getvalue()

    def setUp(self):
        self.path = Path(self.id().replace(".", "_") + ".changelog.tmp")
        self.path.write_text(CHANGELOG, encoding="utf-8")
        self.addCleanup(self.path.unlink)

    def test_plain_mode_prints_the_section_and_exits_zero(self):
        rc, out, _ = self._run("--changelog", str(self.path), "--section", "1.2.0")
        self.assertEqual(rc, 0)
        self.assertTrue(out.startswith("## [1.2.0]"))

    def test_absent_section_exits_3(self):
        rc, out, err = self._run("--changelog", str(self.path), "--section", "9.9.9")
        self.assertEqual(rc, cs.EXIT_NO_SECTION)
        self.assertEqual(out, "")
        self.assertIn("9.9.9", err)

    def test_enforce_limit_exits_4_over_and_0_under(self):
        rc, _, err = self._run("--changelog", str(self.path), "--section", "1.2.0", "--enforce-limit", "--max-bytes", "10")
        self.assertEqual(rc, cs.EXIT_OVER_LIMIT)
        self.assertIn("over the", err)
        rc, out, _ = self._run("--changelog", str(self.path), "--section", "1.2.0", "--enforce-limit")
        self.assertEqual(rc, 0)
        self.assertTrue(out.startswith("## [1.2.0]"))

    def test_enforce_limit_reports_both_bytes_and_characters(self):
        # The unit GitHub counts is not documented; the message must let an
        # operator see the margin under either reading rather than assert one.
        multibyte = Path(str(self.path) + ".mb")
        multibyte.write_text("## [1.0.0]\n" + "⚠" * 100 + "\n", encoding="utf-8")
        self.addCleanup(multibyte.unlink)
        rc, _, err = self._run("--changelog", str(multibyte), "--section", "1.0.0", "--enforce-limit", "--max-bytes", "50")
        self.assertEqual(rc, cs.EXIT_OVER_LIMIT)
        self.assertIn("bytes", err)
        self.assertIn("characters", err)

    def test_fallback_url_publishes_a_truncated_body_at_exit_zero(self):
        big = Path(str(self.path) + ".big")
        big.write_text("## [1.0.0]\n" + "".join(f"padded line {i}\n" for i in range(400)), encoding="utf-8")
        self.addCleanup(big.unlink)
        rc, out, err = self._run(
            "--changelog", str(big), "--section", "1.0.0", "--max-bytes", "1500", "--fallback-url", Truncate.URL
        )
        self.assertEqual(rc, 0)
        self.assertIn(Truncate.URL, out)
        self.assertLessEqual(len(out.encode("utf-8")), 1500)
        self.assertTrue(out.startswith("## [1.0.0]\npadded line 0\n"))
        self.assertIn("truncated", err)

    def test_fallback_url_leaves_a_fitting_section_untouched(self):
        rc, out, _ = self._run("--changelog", str(self.path), "--section", "1.2.0", "--fallback-url", Truncate.URL)
        self.assertEqual(rc, 0)
        self.assertNotIn(Truncate.URL, out)
        self.assertEqual(out, cs.extract(CHANGELOG, "1.2.0"))

    def test_the_two_modes_refuse_to_run_together(self):
        rc, _, err = self._run("--changelog", str(self.path), "--section", "1.2.0", "--enforce-limit", "--fallback-url", "x")
        self.assertEqual(rc, cs.EXIT_USAGE)
        self.assertIn("mutually exclusive", err)

    def test_unreadable_changelog_is_a_usage_error_not_a_missing_section(self):
        # A path typo must not read as "this release has no entry" — that would
        # send the publisher down the generated-placeholder arm and silently
        # ship an empty release note.
        rc, _, err = self._run("--changelog", "no/such/file.md", "--section", "1.2.0")
        self.assertEqual(rc, cs.EXIT_USAGE)
        self.assertIn("cannot read", err)

    def test_stdin_source(self):
        import sys

        stdin = sys.stdin
        sys.stdin = io.StringIO(CHANGELOG)
        try:
            rc, out, _ = self._run("--changelog", "-", "--section", "Unreleased")
        finally:
            sys.stdin = stdin
        self.assertEqual(rc, 0)
        self.assertIn("card#1234", out)


if __name__ == "__main__":
    unittest.main()
