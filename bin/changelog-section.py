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

Exit codes: 0 ok · 2 usage · 3 no such section · 4 over limit.

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


def extract(text: str, label: str) -> str | None:
    """Return the `## [<label>]` section, header line included, or None.

    A section runs from its own header to the next `## [` header or EOF —
    the same boundary rule the awk one-liner this replaced used, so the
    published notes do not change shape for a section that already fits.
    """
    lines = text.splitlines()
    header = f"## [{label}]"
    start = None
    for i, line in enumerate(lines):
        if line.startswith(header):
            start = i
            break
    if start is None:
        return None
    end = len(lines)
    for i in range(start + 1, len(lines)):
        if lines[i].startswith("## ["):
            end = i
            break
    return "\n".join(lines[start:end]) + "\n"


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
    ap.add_argument("--section", required=True, help="the label inside the brackets, e.g. 0.72.0 or Unreleased")
    ap.add_argument("--max-bytes", type=int, default=RELEASE_BODY_LIMIT_BYTES)
    ap.add_argument("--enforce-limit", action="store_true")
    ap.add_argument("--fallback-url")
    args = ap.parse_args(argv)

    # Refusing the combination is not defensive padding: the two modes disagree
    # about what an over-limit section MEANS (reject it vs. publish it anyway),
    # and silently letting one win would make the gate and the publisher read
    # the same file to opposite conclusions.
    if args.enforce_limit and args.fallback_url:
        print("--enforce-limit and --fallback-url are mutually exclusive", file=sys.stderr)
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
