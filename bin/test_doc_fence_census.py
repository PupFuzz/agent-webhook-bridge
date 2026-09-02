#!/usr/bin/env python3
"""Unit tests for bin/doc-fence-census.py (card#8351, DL-324).

WHAT IS WORTH ASSERTING HERE, AND WHAT IS NOT. The census is an operator
instrument, not a gate — its output is a set of FIGURES that three documents quote,
so what has to hold is that the figures mean what the documents say they mean:

  - a tag-file PAIR is per (tag, path), so a document present at N tags counts N
    times. That is the denominator DL-324 quotes and the one property a reader is
    most likely to assume the other way;
  - the walk skips exactly the directories the CHECKER skips, read off the checker
    rather than restated, or the census would measure a population the gate does
    not;
  - no tags reachable is REPORTED, never counted as a clean history — the shape
    "an empty result is a measurement that never happened". CI's own checkout is
    depth-1 with no tags, so this arm is the one that fires there;
  - findings are not an exit code. A census that failed on finding something would
    be unrunnable, because finding the five known members is the whole point.

Each case builds a REAL git repository in a temp directory rather than reading this
one: the assertion is about the counting, and a fixture whose tag count moves with
this repo's history would assert nothing stable. The end-to-end leg plants ONE
known leak at ONE tag and requires the census to find exactly it — a control, since
a census that reported the same figures over a clean corpus would be measuring
something other than the checker.
"""

from __future__ import annotations

import importlib.util
import os
import subprocess
import sys
import tempfile
import unittest

_HERE = os.path.dirname(os.path.abspath(__file__))
_TOOL = os.path.join(_HERE, 'doc-fence-census.py')

_spec = importlib.util.spec_from_file_location('doc_fence_census', _TOOL)
assert _spec and _spec.loader
census = importlib.util.module_from_spec(_spec)
sys.modules[_spec.name] = census
_spec.loader.exec_module(census)

_LEAK = '```bash\necho "$BRIDGE_CHANNEL_TOKEN"\n```\n'
_CLEAN = '```bash\nprintf \'%s\' "$TOKEN" > /run/secrets/t\n```\n'


def _git(repo: str, *args: str) -> None:
    subprocess.run(['git', '-C', repo, *args], check=True,
                   capture_output=True, text=True)


def _write(repo: str, path: str, text: str) -> None:
    full = os.path.join(repo, path)
    os.makedirs(os.path.dirname(full), exist_ok=True)
    with open(full, 'w', encoding='utf-8') as fh:
        fh.write(text)


class ACensusOverARealRepository(unittest.TestCase):
    """One temp repository, three tags, one planted leak."""

    @classmethod
    def setUpClass(cls) -> None:
        cls._tmp = tempfile.TemporaryDirectory()
        repo = cls.repo = cls._tmp.name
        _git(repo, 'init', '-q', '-b', 'main')
        _git(repo, 'config', 'user.email', 'test@example.invalid')
        _git(repo, 'config', 'user.name', 'test')
        _git(repo, 'config', 'commit.gpgsign', 'false')

        # t1: two clean docs.
        _write(repo, 'README.md', _CLEAN)
        _write(repo, 'docs/a.md', _CLEAN)
        _write(repo, 'notes.txt', 'not markdown\n')
        _git(repo, 'add', '-A')
        _git(repo, 'commit', '-qm', 'one')
        _git(repo, 'tag', 't1')

        # t2: the same two, plus one carrying a known member, plus a vendored doc
        # the checker's own SKIP_DIRS excludes.
        _write(repo, 'docs/b.md', _LEAK)
        _write(repo, 'vendor/pkg/README.md', _LEAK)
        _git(repo, 'add', '-A')
        _git(repo, 'commit', '-qm', 'two')
        _git(repo, 'tag', 't2')

        # t3: the leak removed again, so the finding belongs to t2 alone.
        _write(repo, 'docs/b.md', _CLEAN)
        _git(repo, 'add', '-A')
        _git(repo, 'commit', '-qm', 'three')
        _git(repo, 'tag', 't3')

        cls.checker = census._load_checker()

    @classmethod
    def tearDownClass(cls) -> None:
        cls._tmp.cleanup()

    def test_a_pair_is_per_tag_and_per_path_not_per_distinct_document(self) -> None:
        """The denominator DL-324 quotes. Three tags carrying 2, 3 and 3 in-scope
        markdown files is EIGHT pairs over four distinct paths — asserting the
        distinct-document count instead would quietly redefine every figure the
        three surfaces cite."""
        tag_count, pairs, _ = census.census(self.repo, self.checker)
        self.assertEqual(3, tag_count)
        self.assertEqual(8, pairs)

    def test_the_walk_skips_exactly_what_the_CHECKER_skips(self) -> None:
        """Read off the checker, never restated: a census over a population the
        gate does not read is a figure about a different program."""
        self.assertIn('vendor', self.checker.SKIP_DIRS)
        paths = census.markdown_paths_at(self.repo, 't2', self.checker.SKIP_DIRS)
        self.assertEqual(['README.md', 'docs/a.md', 'docs/b.md'], sorted(paths))

    def test_the_planted_member_is_found_at_the_tag_that_carries_it(self) -> None:
        """The control. A census reporting the same figures over a clean corpus
        would be measuring something other than the checker, so one known leak is
        planted at exactly one tag and must be attributed there."""
        _, _, findings = census.census(self.repo, self.checker)
        self.assertEqual(['t2:docs/b.md'], [f.path for f in findings])
        self.assertEqual(['stdout'], [f.rule for f in findings])
        self.assertEqual(1, len({f.message for f in findings}))

    def test_finding_something_is_not_a_failure_exit(self) -> None:
        """It is a MEASUREMENT. A census that exited non-zero on the five known
        members would be unrunnable for its own purpose."""
        self.assertEqual(0, census.main(['--repo', self.repo]))


class NoTagsIsReportedNeverCountedAsClean(unittest.TestCase):
    """The arm CI's own depth-1 checkout would take."""

    def test_an_untagged_repository_exits_one_and_says_why(self) -> None:
        with tempfile.TemporaryDirectory() as repo:
            _git(repo, 'init', '-q', '-b', 'main')
            _git(repo, 'config', 'user.email', 'test@example.invalid')
            _git(repo, 'config', 'user.name', 'test')
            _git(repo, 'config', 'commit.gpgsign', 'false')
            _write(repo, 'README.md', _CLEAN)
            _git(repo, 'add', '-A')
            _git(repo, 'commit', '-qm', 'one')
            self.assertEqual([], census.tags(repo))
            self.assertEqual(1, census.main(['--repo', repo]))


if __name__ == '__main__':
    unittest.main()
