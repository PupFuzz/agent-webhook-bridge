#!/usr/bin/env python3
"""The FALSE-POSITIVE MEASUREMENT behind DL-324, made reproducible from the repo.

WHY THIS EXISTS. DL-324 reversed DL-321's decline of a doc-fence lint on a
measurement: run `bin/check-doc-secret-fences.py` over every `*.md` at every tag in
this repository's history and it reports a small, fixed set of findings, every one
of them a KNOWN member of the class. Three surfaces quote figures from that run —
DL-324 itself, `docs/config-schema.md` § Handling a secret VALUE, and
`docs/CHANGELOG.md`. Until this file existed those figures were reproducible in
PRINCIPLE and from nothing that shipped, and two of the three had already drifted
(a "55 tags" that was really the unit-test COUNT). A figure with no instrument is a
quoted authority; this is the instrument, so the next reader re-derives instead of
re-typing.

⚠ NOT A CI JOB, deliberately. It walks the whole tag history and reads every blob,
so it costs seconds-to-minutes and needs `--tags` fetched — a depth-1 CI checkout
has no tags at all and would measure an empty population while exiting 0, which is
the "empty result is a measurement that never happened" shape. The GATE is
`.github/workflows/doc-secret-fence-lint.yml`, which runs the checker over the
working tree. This is an operator instrument, run by hand when a figure is quoted.

WHAT IT COUNTS, stated because each number is quoted somewhere.
  tags       entries `git tag` returns. The DENOMINATOR's first factor.
  pairs      (tag, markdown path) pairs examined — the population the finding count
             is over. A path present at 40 tags counts 40 times, ON PURPOSE: the
             question is whether the checker stays quiet across the history, not
             how many distinct documents exist.
  findings   total findings over those pairs.
  messages   DISTINCT finding messages. This is the load-bearing one. 399 findings
             collapsing to 5 messages is what makes them auditable one by one; a
             sixth message is a member nobody has classified and the run says so.

  `--verbose` prints each distinct message with its count and one example location,
  which is how a reader checks the "every one is a known member" claim rather than
  taking it.

EXIT CODES. 0 — the census ran. 1 — no tags are reachable (a shallow clone), which
is reported rather than counted as a clean history. This is a MEASUREMENT, not a
gate: findings are the expected output, so it never exits non-zero for having found
some.

Stdlib only, no repo imports beyond loading the checker off its own path:
`python3 bin/doc-fence-census.py [--verbose] [--repo <path>]`.
"""

from __future__ import annotations

import argparse
import collections
import importlib.util
import os
import subprocess
import sys

_HERE = os.path.dirname(os.path.abspath(__file__))
_CHECKER = os.path.join(_HERE, 'check-doc-secret-fences.py')


def _load_checker():
    spec = importlib.util.spec_from_file_location('check_doc_secret_fences', _CHECKER)
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    # Registered before exec so the checker's dataclasses can resolve their own
    # annotations — a module loaded off a hyphenated path is not importable by
    # name. Same load shape as bin/test_check_doc_secret_fences.py.
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    return module


def _git(repo: str, *args: str) -> str:
    out = subprocess.run(['git', '-C', repo, *args],
                         capture_output=True, text=True, check=False)
    if out.returncode != 0:
        raise RuntimeError(f'git {" ".join(args)} failed ({out.returncode}): '
                           f'{out.stderr.strip()}')
    return out.stdout


def tags(repo: str) -> list[str]:
    return [t for t in _git(repo, 'tag').split('\n') if t.strip()]


def markdown_paths_at(repo: str, rev: str, skip_dirs: set[str]) -> list[str]:
    """Every `*.md` blob at `rev`, minus the directories the checker itself skips.

    Read from the TREE rather than from a checkout: the census must not depend on
    what the working directory happens to be at, and it must never move it.
    """
    out = []
    for path in _git(repo, 'ls-tree', '-r', '--name-only', rev).split('\n'):
        if not path.endswith('.md'):
            continue
        if any(part in skip_dirs for part in path.split('/')[:-1]):
            continue
        out.append(path)
    return out


def census(repo: str, checker) -> tuple[int, int, list]:
    """`(tag_count, pair_count, findings)` over every `*.md` at every tag."""
    revs = tags(repo)
    pairs = 0
    findings = []
    for rev in revs:
        for path in markdown_paths_at(repo, rev, checker.SKIP_DIRS):
            pairs += 1
            blob = _git(repo, 'show', f'{rev}:{path}')
            findings.extend(checker.scan_text(f'{rev}:{path}', blob))
    return len(revs), pairs, findings


def main(argv: list[str]) -> int:
    parser = argparse.ArgumentParser(
        prog='doc-fence-census.py',
        description=('Re-derive DL-324\'s false-positive measurement: run '
                     'bin/check-doc-secret-fences.py over every *.md at every git '
                     'tag and report tags / tag-file pairs / findings / distinct '
                     'messages. Not a CI job — see this file\'s docstring.'))
    parser.add_argument('--repo', default=os.path.dirname(_HERE),
                        help='repository to census (default: this checkout)')
    parser.add_argument('--verbose', action='store_true',
                        help='print each distinct message, its count and an example')
    args = parser.parse_args(argv)

    checker = _load_checker()
    tag_count, pairs, findings = census(args.repo, checker)
    if tag_count == 0:
        print('doc-fence-census: no tags are reachable from this checkout, so '
              'there is nothing to census — a shallow clone measures an empty '
              'history, it does not measure a clean one. Run `git fetch --tags`.',
              file=sys.stderr)
        return 1

    by_message = collections.Counter(f.message for f in findings)
    example = {}
    for f in findings:
        example.setdefault(f.message, f'{f.path}:{f.line} [{f.rule}]')

    if args.verbose:
        for message, count in by_message.most_common():
            print(f'{count:5d}  {example[message]}')
            print(f'       {message}')
        print()
    print(f'doc-fence-census: {tag_count} tags / {pairs} tag-file pairs / '
          f'{len(findings)} findings / {len(by_message)} distinct messages.')
    print('⚠ A finding here is EXPECTED — this is the measurement DL-324 quotes, '
          'not a gate. Read the distinct messages (--verbose): the claim is that '
          'every one is a known member of the class, and only reading them checks '
          'it.')
    return 0


if __name__ == '__main__':
    raise SystemExit(main(sys.argv[1:]))
