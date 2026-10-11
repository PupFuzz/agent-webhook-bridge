#!/usr/bin/env python3
"""Extract one `## [<label>]` section from a Keep-a-Changelog file.

The release publisher's extractor, called by `.github/workflows/auto-tag-version.yml`.
The PR-time size check is the toolkit's `changelog-gate` (card#11684), which
carves the section on the same boundary rule — the `## [<V>]` header through the
line before the next `## [` header, or EOF, measured in UTF-8 bytes — for
`\n`-terminated files, which is every changelog this repo has.

Modes (exit codes are the contract; stdout is the body):
  plain            print the section verbatim
  --fallback-url   over the limit, print a line-truncated body ending in a
                   pointer to URL, so a release is never left tagged-but-
                   unpublished (card#5972)

Exit codes: 0 ok · 2 usage · 3 no such section.

THE LIMIT IS MEASURED IN BYTES, and that is deliberate. GitHub rejected
v0.72.0's release body with `body is too long (maximum is 125000 characters)`
without documenting what it counts; that section was 133,906 code points and
134,904 UTF-8 bytes, so the observation cannot discriminate between the two.
For UTF-8, byte length is >= both the code-point count and the UTF-16 code-unit
count, so measuring bytes is conservative under every plausible reading. The
consequence is that a section of mostly non-ASCII prose is truncated slightly
early; that is the safe direction.
"""

import argparse
import sys

# GitHub's release-body ceiling, from the 422 that v0.72.0's publish step
# returned. Only `--max-bytes`'s default: the publisher passes
# `.release-pr.json`'s `changelog.release_body_limit_bytes` when it is set,
# which is the figure the toolkit's changelog gate enforces at release-PR time.
RELEASE_BODY_LIMIT_BYTES = 125_000

EXIT_USAGE = 2
EXIT_NO_SECTION = 3


def _sections(lines: list[str]) -> list[tuple[str | None, int, int]]:
    """`(label, header index, end index)` for every `## [` header, in file order.

    A section runs from its own header to the next `## [` header or EOF —
    the same boundary rule the awk one-liner this replaced used, so the
    published notes do not change shape for a section that already fits.

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
    ap.add_argument("--fallback-url")
    args = ap.parse_args(argv)

    if args.changelog == "-":
        text = sys.stdin.read()
    else:
        try:
            with open(args.changelog, encoding="utf-8") as fh:
                text = fh.read()
        # UnicodeDecodeError is caught WITH the OSErrors, and it is not an
        # OSError: a non-UTF-8 file OPENS, then fails on read, and letting that
        # escape ends the process on the interpreter's own exit 1 — a code the
        # caller has to guess at. The publisher reads rc 3 as "section absent"
        # and publishes a placeholder, so an unreadable input must not land
        # there: it leaves on the usage code instead.
        except (OSError, UnicodeDecodeError) as exc:
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
    if size > args.max_bytes and args.fallback_url:
        sys.stdout.write(truncate(section, args.max_bytes, args.fallback_url))
        print(
            f"section [{args.section}] is {size} bytes (limit {args.max_bytes}); truncated with a pointer",
            file=sys.stderr,
        )
        return 0

    sys.stdout.write(section)
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
