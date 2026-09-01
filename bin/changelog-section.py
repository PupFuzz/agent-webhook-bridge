#!/usr/bin/env python3
"""Extract one `## [<label>]` section from a Keep-a-Changelog file.

The single extractor behind every automation that reads `docs/CHANGELOG.md`:
the release publisher in `.github/workflows/auto-tag-version.yml` and both
assertions in `.github/workflows/changelog-gate.yml`. It exists as one file
because a second, divergent extractor is the defect class card#5910 names — a
guard that reads one of N artifacts which must move together cannot fail on the
field it never reads, and two extractors that disagree about where a section
ends are that same shape one level down. The gate measures the publisher's
actual output because both call this.

Modes (exit codes are the contract; stdout is the body):
  plain            print the section verbatim
  --enforce-limit  exit 4 when the section is over the release-body limit
  --fallback-url   over the limit, print a line-truncated body ending in a
                   pointer to URL, so a release is never left tagged-but-
                   unpublished (card#5972)
  --locate-added   print the LABEL of the released section that a branch's own
                   new lines have landed in — one INPUT to the pre-fold
                   diagnosis, not the diagnosis (card#8339, DL-329)

Exit codes: 0 ok · 2 usage · 3 no such section / no released section carries a
branch-introduced line · 4 over limit.

THE LIMIT IS MEASURED IN BYTES, and that is deliberate. GitHub rejected
v0.72.0's release body with `body is too long (maximum is 125000 characters)`
without documenting what it counts; that section was 133,906 code points and
134,904 UTF-8 bytes, so the observation cannot discriminate between the two.
For UTF-8, byte length is >= both the code-point count and the UTF-16 code-unit
count, so enforcing on bytes is conservative under every plausible reading. The
consequence is that a section of mostly non-ASCII prose is refused slightly
early; that is the safe direction, and the failure message prints both numbers
so the operator can see the margin.
"""

import argparse
import sys

# GitHub's documented release-body ceiling, from the 422 that v0.72.0's publish
# step returned. Both callers read it from here so the gate and the publisher
# cannot disagree about what fits.
RELEASE_BODY_LIMIT_BYTES = 125_000

EXIT_USAGE = 2
EXIT_NO_SECTION = 3
EXIT_OVER_LIMIT = 4

# The one label that is not a release. Compared exactly, because every caller
# already spells it exactly (`--section Unreleased`) and the file's own headers
# are canonical — a case-insensitive match here would accept a spelling the
# extractor itself could not then find.
UNRELEASED_LABEL = "Unreleased"


def _sections(lines: list[str]) -> list[tuple[str | None, int, int]]:
    """`(label, header index, end index)` for every `## [` header, in file order.

    A section runs from its own header to the next `## [` header or EOF —
    the same boundary rule the awk one-liner this replaced used, so the
    published notes do not change shape for a section that already fits. Both
    modes below carve sections through this one function: two carvings that
    disagreed about where a section ends would be card#5910's defect class one
    level further down, which is the whole reason this file is a single
    extractor.

    `label` is None for a header whose bracket is never closed; such a line is
    still a BOUNDARY, because that is what the plain mode has always treated it
    as.
    """
    starts = [i for i, line in enumerate(lines) if line.startswith("## [")]
    out: list[tuple[str | None, int, int]] = []
    for n, start in enumerate(starts):
        end = starts[n + 1] if n + 1 < len(starts) else len(lines)
        rest = lines[start][len("## [") :]
        close = rest.find("]")
        out.append((rest[:close] if close != -1 else None, start, end))
    return out


def extract(text: str, label: str) -> str | None:
    """Return the `## [<label>]` section, header line included, or None."""
    lines = text.splitlines()
    header = f"## [{label}]"
    for _label, start, end in _sections(lines):
        if lines[start].startswith(header):
            return "\n".join(lines[start:end]) + "\n"
    return None


def locate_added(text: str, added: list[str], baseline: str) -> str | None:
    """Label of the first RELEASED section of `text` carrying a line the branch introduced.

    `text` is the changelog AS MERGED, `added` the lines the branch adds to it,
    and `baseline` the changelog on the base branch. This answers one question:
    did this branch's own new changelog lines end up under a version heading
    instead of under `[Unreleased]`?

    ⚠ THAT IS A LABEL, NOT THE PRE-FOLD DIAGNOSIS, and the two are not the same
    claim: a branch that corrects a line inside a long-released section, or that
    cuts the section itself, also puts a line it introduced under a version
    heading. Whether a FOLD happened while the branch was open is a fact about
    the branch's history, not about this file, so the caller establishes it —
    `changelog-gate.yml` requires the label to exist on the base and NOT at the
    merge-base before it prints the fold remedy. DL-329 owns the predicate.

    A needle already present in the baseline is DROPPED, and that subtraction is
    load-bearing rather than tidiness: every released section here carries
    `### Added` / `### Fixed` heads, so without it a branch adding one of those
    under `[Unreleased]` would match a released section and return a label its
    caller would then have to defend. A branch line duplicating an existing one
    is dropped with them, which yields no label rather than a wrong one.
    """
    known = set(baseline.splitlines())
    needles = {line for line in added if line.strip() and line not in known}
    lines = text.splitlines()
    for label, start, end in _sections(lines):
        if label is None or label == UNRELEASED_LABEL:
            continue
        # `start + 1`: the BODY, not the header line. A branch that adds the
        # version heading itself has not thereby filed anything under it.
        if not needles.isdisjoint(lines[start + 1 : end]):
            return label
    return None


def truncate(section: str, limit: int, url: str) -> str:
    """Line-truncate `section` so the body plus its pointer fits in `limit`.

    Whole lines only: a mid-word cut would land inside a markdown construct and
    render as garbage on the Releases page, and line granularity is what makes
    the result deterministic enough to assert on.

    The pointer is emitted unconditionally, so a `limit` smaller than the
    pointer itself returns a body over `limit`. That is reachable only from an
    absurd `--max-bytes`; publishing a truncated body that does not say where
    the rest is would be the worse failure.
    """
    pointer = (
        "\n---\n\n"
        f"⚠ This section is {len(section.encode('utf-8'))} bytes, over GitHub's "
        f"{limit}-byte release-body limit, so these notes are truncated.\n"
        f"The full entry is in `docs/CHANGELOG.md` at this tag: {url}\n"
    )
    budget = limit - len(pointer.encode("utf-8"))
    kept: list[str] = []
    used = 0
    for line in section.splitlines():
        cost = len(line.encode("utf-8")) + 1
        if used + cost > budget:
            break
        kept.append(line)
        used += cost
    return ("\n".join(kept) + "\n" if kept else "") + pointer


def main(argv: list[str]) -> int:
    ap = argparse.ArgumentParser(allow_abbrev=False)
    ap.add_argument("--changelog", required=True, help="path to the changelog, or - for stdin")
    ap.add_argument("--section", help="the label inside the brackets, e.g. 0.72.0 or Unreleased")
    ap.add_argument("--max-bytes", type=int, default=RELEASE_BODY_LIMIT_BYTES)
    ap.add_argument("--enforce-limit", action="store_true")
    ap.add_argument("--fallback-url")
    ap.add_argument(
        "--locate-added",
        metavar="FILE",
        help="file of the lines the PR branch adds to the changelog, one per line; "
        "prints the label of the released section they landed in",
    )
    ap.add_argument("--baseline", metavar="FILE", help="the changelog as it stands on the base branch")
    args = ap.parse_args(argv)

    # Refusing the combination is not defensive padding: the two modes disagree
    # about what an over-limit section MEANS (reject it vs. publish it anyway),
    # and silently letting one win would make the gate and the publisher read
    # the same file to opposite conclusions.
    if args.enforce_limit and args.fallback_url:
        print("--enforce-limit and --fallback-url are mutually exclusive", file=sys.stderr)
        return EXIT_USAGE

    if (args.section is None) == (args.locate_added is None):
        print("exactly one of --section and --locate-added is required", file=sys.stderr)
        return EXIT_USAGE
    if args.locate_added is not None and (args.enforce_limit or args.fallback_url):
        print("--locate-added answers about a label, not a body: no limit mode applies", file=sys.stderr)
        return EXIT_USAGE

    if args.changelog == "-":
        text = sys.stdin.read()
    else:
        try:
            with open(args.changelog, encoding="utf-8") as fh:
                text = fh.read()
        except OSError as exc:
            print(f"cannot read {args.changelog}: {exc}", file=sys.stderr)
            return EXIT_USAGE

    if args.locate_added is not None:
        if args.baseline is None:
            print("--locate-added requires --baseline", file=sys.stderr)
            return EXIT_USAGE
        try:
            with open(args.locate_added, encoding="utf-8") as fh:
                added = fh.read().splitlines()
            with open(args.baseline, encoding="utf-8") as fh:
                baseline = fh.read()
        except OSError as exc:
            print(f"cannot read the locate inputs: {exc}", file=sys.stderr)
            return EXIT_USAGE
        # An EMPTY baseline is refused rather than treated as "the base had no
        # lines". It is not a reachable state for a real changelog, and reading
        # it as one inverts the safe direction: every added line would become a
        # needle, so a failed `git show` upstream would turn into a CONFIDENT
        # wrong diagnosis instead of silence.
        if not baseline.strip():
            print(f"the baseline {args.baseline} is empty — refusing to locate against it", file=sys.stderr)
            return EXIT_USAGE
        label = locate_added(text, added, baseline)
        if label is None:
            print(
                "no released section carries a line this branch introduced "
                "— the pre-fold diagnosis does not apply here",
                file=sys.stderr,
            )
            return EXIT_NO_SECTION
        print(label)
        return 0

    section = extract(text, args.section)
    if section is None:
        print(
            f"no '## [{args.section}]' section in {args.changelog}",
            file=sys.stderr,
        )
        return EXIT_NO_SECTION

    size = len(section.encode("utf-8"))
    if size > args.max_bytes:
        if args.fallback_url:
            sys.stdout.write(truncate(section, args.max_bytes, args.fallback_url))
            print(
                f"section [{args.section}] is {size} bytes (limit {args.max_bytes}); truncated with a pointer",
                file=sys.stderr,
            )
            return 0
        if args.enforce_limit:
            print(
                f"section [{args.section}] is {size} bytes ({len(section)} characters), over the "
                f"{args.max_bytes}-byte release-body limit by {size - args.max_bytes} bytes",
                file=sys.stderr,
            )
            return EXIT_OVER_LIMIT

    sys.stdout.write(section)
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
