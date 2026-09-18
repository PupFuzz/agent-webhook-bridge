#!/usr/bin/env python3
r"""pr-body-lint.selftest.py — the PR-body lint's own controls (card#9073).

THE FAILURE THIS GUARDS IS SILENT, WHICH IS WHY EVERY ROW IS RED-THEN-GREEN. A lint that has
stopped reding is byte-indistinguishable from a body that complies: both print one OK line and
exit 0. So no rule here is trusted because it passed — each is driven against a body built to
break it, and each is additionally driven through a MUTANT of the shipped file with that one rule
disabled, which must flip the answer. A rule whose mutant still reds was never what produced the
red.

THERE IS ONE SUCH PAIR PER RULING, each keyed in `FIXTURES` below. Each is a REAL BODY OF A REAL PR
BEFORE AND AFTER IT, except where its negative is HAND-BUILT and says so below (`pr-body-lint-
fixtures/README.md` owns their provenance and the sha256 pins are in `FIXTURES`). The fw#847 pair —
its v0.50.0 body — is the commentary ruling:

  * the KNOWN POSITIVE — fw#847's v0.50.0 body as the operator read it, which must RED, and must
    red on the two rows the operator named BY NAME, not merely somewhere;
  * the KNOWN NEGATIVE — the same body after the rewrite, which must PASS.

The fw#891 pair — its v0.51.0 body — is the ATTRIBUTION-LINE ruling (operator, 2026-09-11,
card#9229 → card#9073 leg E: a PR body carries no `FROM:` / `TO:` line, on any repo). Its positive
is the body at `2026-09-10T14:08:53Z`, which opened on `FROM: pm`; its negative is the same body
after the line was struck. `arm_f` drives it, and it drives the same pair through a mutant with the
rule unwired, because the positive ALSO reds on `heading-not-allowed` — "it reds" would therefore
have been true of that body before this rule existed at all.

The fw#873 pair — its body — is the LIVE-STATE READING rule (card#9073 comment 4102, leg B-2).
Its positive is the body as first published (`2026-09-08T20:59:57Z`), whose `CI has not run on this
head. The branch is not pushed` and `2 commits behind origin/dev` lines are the instance the rule
was minted on. ⛔ ITS NEGATIVE IS HAND-BUILT, not a later revision: fw#873 never rewrote those lines
into derivations (its later revisions restated readings), so no real negative exists. The warning
below applies to it, and what answers that warning is an assertion rather than trust: `arm_g`
requires the negative to be BYTE-IDENTICAL to the real positive on every line but exactly the lines
the rule reds on, each replaced by the command that re-derives it and its pass conditions. And
`arm_g` drives the pair through a mutant with the rule unwired, because the positive ALSO reds on
older rules.

A rule that reds on both is measuring length, not the standard. The pair is what tells them apart,
and it is why a hand-written "compliant body" fixture is present as well but is not the whole of
the negative side: a body someone wrote to pass the lint they just wrote proves nothing about a
body someone wrote to ship a release.

⛔ THE MUTANTS ARE ASSERTED TO HAVE APPLIED. A substitution that matched nothing yields an
unmutated copy that passes every assertion and reads exactly like a control that ran (this repo's
own measured lesson — `coord-review.selftest.py`'s `MUTANTS` table states it). Every mutation here
is checked for having changed the bytes before its result is believed.

⛔ AND THE SHIPPED FILE IS NEVER TOUCHED. Mutants are written into a temp directory; the original's
sha256 is taken before the first row and re-taken after the last, and a difference is a FAILURE of
this suite rather than a note — a guard that edits the thing it guards has no way to say so.

HERMETIC: no network, no `$HOME`, no coordination config. Everything it reads is in this
directory.
"""
from __future__ import annotations

import hashlib
import importlib.util
import os
import re
import subprocess
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
TOOL = os.path.join(HERE, "pr-body-lint.py")
FIXTURE_DIR = os.path.join(HERE, "pr-body-lint-fixtures")

# ⛔ THE PINS ARE LOAD-BEARING, NOT DECORATION, AND THEY ARE THE ONE PLACE A FIGURE IS WRITTEN
# DOWN IN THIS FILE. "The known positive" is a CLAIM about WHICH revision of a real PR body this
# suite is run against — the live body at #847 is the rewritten one and passes — so the claim
# needs a check a second reader can re-run (`sha256sum`), not a filename that could name anything.
# Every other quantity here is derived at run time.
# ⛔ AND THEY ARE `.md.txt`, NOT `.md`, WHICH IS A CLAIM ABOUT WHAT THEY ARE. A captured PR body
# is DATA — another document's history, preserved verbatim — not a document of this tree, and this
# repo's markdown guards walk `*.md` over the whole tree and grade what they find. The positive
# fixture's own prose NAMES this repo's doc-ownership tag, in the elliptical form that repo's
# convention does not accept — so `doc-crossref-drift` read the release body as making a
# malformed ownership claim and reported it by name. The finding was true about a file nobody
# may edit: fixing it would mean editing the evidence. The extension keeps the fixtures out of
# every `*.md` population at once, and that guard's whole-tree arm carries a declared skip for
# the one file, beside the one it already carries for itself and for the same stated reason.
FIXTURES = {
    "positive": ("fw847-v0.50.0-body-2026-09-08T220229Z.md.txt",
                 "31cfc5649c34f09601c76aaed5817c62d413cd5a5a1e6b8ae2456b3c751a8be4"),
    "negative": ("fw847-v0.50.0-body-rewritten.md.txt",
                 "85c244b7d3fbfa4bf79d2718c28ed902cb490e1ab16b3d4cd4196d98cb0fb21a"),
    # The attribution-line pair — fw#891, the same PR either side of the 2026-09-11 ruling.
    "attr_positive": ("fw891-v0.51.0-body-2026-09-10T140853Z.md.txt",
                      "7f269aeb45243018829929c80c9c76a460bad26e1e237f229fca19707a225331"),
    "attr_negative": ("fw891-v0.51.0-body-rewritten.md.txt",
                      "63ca4812628c7055faa5d2c8ca5e922b3cfa28904e2c1869deaa48e9c7c4c8eb"),
    # The live-state-reading pair — fw#873 as first published, and a HAND-BUILT negative.
    "live_positive": ("fw873-body-2026-09-08T205957Z.md.txt",
                      "bfb44759b25a3cbea31a63ecddadea35c9065fae9d5c0e4c6a1c56414783d3f9"),
    "live_negative": ("fw873-body-hand-built-derivations.md.txt",
                      "4ec27d315998a93c41658a0f08437561ae009d01574e0d75c7051cac6a132656"),
}

# A body written to the IN table, by hand — the second negative. It carries the machine-read rows
# in BOTH spellings the audit-row grammar accepts (bold and plain), a fenced example QUOTING four
# things the lint reds on — including the `FROM:` / `TO:` lines card#9073 leg E now refuses — and
# an H3 inside `## Highlights`, so a green here is also a statement that none of those is a false
# accusation. ⛔ IT OPENS ON ITS SCOPE LINE. It used to open on `FROM: impl` / `TO: pm`, which was
# the standard then and is a finding now; a fixture kept in the old shape would have made this
# suite's own "compliant" body red on the rule it ships.
COMPLIANT_BODY = """**`dev → main` release PR — v1.4.0.** Bundles the commits since `v1.3.0`, enumerated in `## Bundled`.

**Built:** dispatched (coder ×1 / mechanic ×0)
**Coordinated in:** acme/coordination#412

## Highlights

- `acme-sync` refuses a config whose `retry.window` is unset, where it used to start and stall.

### For anyone running the daemon

- The unit file now reads `/etc/acme/sync.env`; an install that kept its keys in the unit itself
  moves them before the upgrade.

## Upgrade warnings

1. Run `acme-sync migrate` BEFORE restarting the daemon — the new schema is not read by v1.3.0.

## Bundled (generated — do not hand-edit)

- #310 — refuse an unset retry window
- #311 — read the env file

<!-- release-manifest:shipped-refs=310,311 -->

The lint's own rules, quoted rather than claimed — this block must not red:

```
## Correlation gaps
### Why MINOR (1.3.0 → 1.4.0)
Built: dispatched (coder ×9 / mechanic ×9)
Coordinated in: evil/repo#1
FROM: evil
TO: all
```
"""

CHECKS = 0
FAILURES: list[str] = []
SEEN_RED: list[str] = []


def check(name, cond, detail=""):
    global CHECKS
    CHECKS += 1
    if not cond:
        FAILURES.append("%s: %s" % (name, detail))
    return bool(cond)


def seen_red(name):
    SEEN_RED.append(name)


def sha256_file(path):
    with open(path, "rb") as fh:
        return hashlib.sha256(fh.read()).hexdigest()


def load_tool(path=TOOL):
    """Load a copy of the lint as a module. NEVER under the name `__main__` — the file is
    script-shaped and would run its CLI on import."""
    spec = importlib.util.spec_from_file_location(
        "pr_body_lint_under_test_%s" % re.sub(r"\W", "_", path), path)
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


def rules_on(mod, body):
    """The set of RULE NAMES `mod` reports on `body`."""
    return sorted({f.rule for f in mod.findings(body)})


def read_fixture(key):
    name, want = FIXTURES[key]
    path = os.path.join(FIXTURE_DIR, name)
    got = sha256_file(path)
    check("fixture `%s` is the revision this suite claims (sha256 pinned; "
          "`pr-body-lint-fixtures/README.md` owns which revision that is)" % name,
          got == want, "sha256 %s, pinned %s" % (got, want))
    with open(path, encoding="utf-8") as fh:
        return fh.read()


# ══════════════════════════════════════════════════════════════════════════════════════════════
# A — THE TWO CONTROLS
# ══════════════════════════════════════════════════════════════════════════════════════════════
def arm_a_the_controls(mod):
    positive = read_fixture("positive")
    negative = read_fixture("negative")

    got = rules_on(mod, positive)
    # The operator named two things. Both must be among the findings BY RULE — "it reds" is not
    # the claim; "it reds on the commentary the operator pointed at" is.
    check("A the KNOWN POSITIVE reds", got, "no finding on fw#847's v0.50.0 body")
    check("A ...on `## Correlation gaps` (heading-not-allowed)",
          "heading-not-allowed" in got, got)
    check("A ...and on `### Why MINOR (0.49.0 → 0.50.0)` (banned-opener)",
          "banned-opener" in got, got)
    lines = {f.rule: f.text for f in mod.findings(positive)}
    check("A ...and each finding quotes the offending line rather than a tally",
          "Correlation gaps" in lines.get("heading-not-allowed", "")
          and "Why MINOR" in lines.get("banned-opener", ""), lines)

    check("A the KNOWN NEGATIVE — the SAME body, rewritten to the standard — passes",
          rules_on(mod, negative) == [], rules_on(mod, negative))
    check("A the hand-written compliant body passes, fenced counter-examples and all",
          rules_on(mod, COMPLIANT_BODY) == [], rules_on(mod, COMPLIANT_BODY))


# ══════════════════════════════════════════════════════════════════════════════════════════════
# B — EVERY RULE, DRIVEN ON A BODY BUILT TO BREAK IT
# ══════════════════════════════════════════════════════════════════════════════════════════════
def arm_b_each_rule(mod):
    def without(line_filter):
        return "\n".join(l for l in COMPLIANT_BODY.split("\n") if not line_filter(l))

    rows = [
        ("scope-line", "## Highlights\n\nBuilt: dispatched (coder ×1 / mechanic ×0)\n"
                       "Coordinated in: acme/coordination#412\n",
         "a body that opens on a heading has no scope line"),
        ("heading-not-allowed", COMPLIANT_BODY + "\n## Review round 2\n",
         "a section the OUT table homes elsewhere"),
        ("banned-opener", COMPLIANT_BODY + "\nVerification: the suite is green at this head.\n",
         "self-review narration, at the start of a line"),
        ("built-missing", without(lambda l: l.startswith("**Built:")),
         "the machine-read row the IN table names"),
        ("coordinated-missing", without(lambda l: l.startswith("**Coordinated in:")),
         "the PR ↔ thread audit anchor"),
    ]
    for rule, body, why in rows:
        got = rules_on(mod, body)
        check("B `%s` reds on %s" % (rule, why), rule in got, got)

    # ⛔ AND THE DECORATION ARM, because `### Why` and `- **Why**` are the shapes that actually
    # ship — a rule that only matches a bare word at column 0 would have passed the very body
    # this card was minted against.
    # ⛔ `__Why__` AND `_Why_` ARE IN THIS LIST BECAUSE THEY PASSED. The decoration stripper
    # strips the LEADING `__`, so the phrase arrived at the opener pattern as `Why__ MINOR`, and a
    # `(?![\w-])` lookahead refused it — `_` is a `\w` character. The rule's coverage turned on
    # which of two equivalent markdown bold spellings the author typed (fw#898 r1, M2). The row
    # below is the one this list could not have been written from reading the code.
    for lead in ("Why", "### Why", "- Why", "  - **Why**", "> Why", "1. Why", "#### **Why**",
                 "__Why__", "_Why_", "> __Why__"):
        body = COMPLIANT_BODY + "\n%s this release is MINOR.\n" % lead
        check("B `banned-opener` sees through markdown decoration (%r)" % lead,
              "banned-opener" in rules_on(mod, body), rules_on(mod, body))

    # …and does NOT fire mid-line, which is the other half of a rule that discriminates.
    body = COMPLIANT_BODY + "\n- The daemon explains why it refused, in its own log.\n"
    check("B `banned-opener` does not fire on the word mid-line",
          "banned-opener" not in rules_on(mod, body), rules_on(mod, body))

    # …and the hyphen half of the lookahead is UNCHANGED by the class narrowing above: a rule that
    # gained `__Why__` by also convicting `Why-not` would be a nuisance, not a fix.
    for lead in ("Why-not this release is MINOR", "Whyever it happened", "Controls-Plane moved"):
        body = COMPLIANT_BODY + "\n%s.\n" % lead
        check("B `banned-opener` still does NOT fire on %r" % lead,
              "banned-opener" not in rules_on(mod, body), rules_on(mod, body))

    # ⛔ `Release artifacts` IS IN THE ALLOWLIST AND THE GENERATOR'S OTHER TWO H2s ARE NOT — the
    # operator's ruling on fw#898 r1, driven rather than asserted in prose. Arm D plants the
    # removal and requires this green to flip.
    arts = COMPLIANT_BODY + "\n## Release artifacts\n\n- [ ] SBOM regenerated for v1.4.0\n"
    check("B `## Release artifacts` — the IN table's release-artifacts checklist row, emitted by "
          "`release-pr-body` — PASSES", rules_on(mod, arts) == [], rules_on(mod, arts))
    for heading in ("## Card coverage", "## Correlation gaps"):
        body = COMPLIANT_BODY + "\n%s\n" % heading
        check("B `%s` still reds — leg A's defect is the GENERATOR's, not a widened allowlist"
              % heading, "heading-not-allowed" in rules_on(mod, body), rules_on(mod, body))

    # ⛔ `re.IGNORECASE` IS A SHIPPED PROPERTY AND NOTHING BOUND IT (fw#898 r1, m1): the reviewer
    # dropped the flag from `_BANNED_RE` and this whole suite stayed rc 0. The row asserts the
    # lower-case spelling reds; arm D's mutant is what makes it a control rather than a row that
    # happens to be true.
    lower = COMPLIANT_BODY + "\n## why minor\n"
    check("B `banned-opener` is CASE-INSENSITIVE — `## why minor` reds on the opener, not only on "
          "the heading rule", "banned-opener" in rules_on(mod, lower), rules_on(mod, lower))


# ══════════════════════════════════════════════════════════════════════════════════════════════
# C — THE FENCE RULE, WHICH IS WHAT KEEPS THE LINT FROM MANUFACTURING FINDINGS
# ══════════════════════════════════════════════════════════════════════════════════════════════
def arm_c_fences(mod, td):
    fenced_only = ("**`dev → main` release PR — v1.4.0.** Bundles the commits since `v1.3.0`.\n\n"
                   "Built: dispatched (coder ×1 / mechanic ×0)\n"
                   "Coordinated in: acme/coordination#412\n\n"
                   "```\n## Correlation gaps\n### Why MINOR\n```\n")
    check("C a heading and an opener that exist only INSIDE A FENCE are payload, not findings",
          rules_on(mod, fenced_only) == [], rules_on(mod, fenced_only))

    # THE CONTROL: fence-blind, the same body must red on both. Without this the row above is
    # equally consistent with a lint that has stopped seeing headings at all.
    blind = mutant(td, "fence-blind",
                   ("    flags, fence_char, fence_len = [], None, 0",
                    "    flags, fence_char, fence_len = [], None, 0\n"
                    "    return [False] * len(text.split(chr(10)))"))
    got = rules_on(blind, fenced_only)
    if check("C CONTROL: a FENCE-BLIND lint reds on the very same body — the fence rule is what "
             "withheld those findings, not their absence",
             "heading-not-allowed" in got and "banned-opener" in got, got):
        seen_red("C the fence rule, driven red by a fence-blind mutant that reads a quoted "
                 "example as the body's own claim")

    # The audit rows are read through the same tracker: a body whose ONLY `Built:` line is fenced
    # reads as ABSENT — the fail-safe direction, and the card#9047 r2 defect in this file's copy.
    check("C an audit row that exists only inside a fence reads as ABSENT, never as present",
          "built-missing" in rules_on(mod, fenced_only + "\n```\nBuilt: forged\n```\n")
          or mod.audit_fields("```\nBuilt: forged\n```\n")["Built"] is None,
          mod.audit_fields("```\nBuilt: forged\n```\n"))


# ══════════════════════════════════════════════════════════════════════════════════════════════
# D — THE MUTANTS: each rule disabled in a COPY, and the answer must flip
# ══════════════════════════════════════════════════════════════════════════════════════════════
def mutant(td, name, substitution, count=1):
    """A copy of the shipped lint with ONE property removed, asserted to have APPLIED. `count=-1`
    replaces EVERY occurrence — for a property spelled once per shape it applies to."""
    old, new = substitution
    with open(TOOL, encoding="utf-8") as fh:
        src = fh.read()
    if old not in src:
        FAILURES.append("MUTANT `%s`: its anchor is no longer in %s, so the copy is UNMUTATED and "
                        "every assertion over it would be a green over the shipped code."
                        % (name, TOOL))
        return load_tool()
    path = os.path.join(td, "pr-body-lint.%s.py" % name)
    with open(path, "w", encoding="utf-8", newline="") as fh:
        fh.write(src.replace(old, new, count))
    if sha256_file(path) == sha256_file(TOOL):
        FAILURES.append("MUTANT `%s`: the copy is byte-identical to the shipped file." % name)
    return load_tool(path)


def arm_d_mutants(td):
    positive = read_fixture("positive")

    opened = mutant(td, "allowlist-opened",
                    ('ALLOWED_H2 = ("Highlights", "Upgrade warnings", "Bundled", "Release artifacts")',
                     'ALLOWED_H2 = ("",)'))
    if check("D CONTROL: with the H2 allowlist OPENED, the known positive stops reding on its "
             "`## Correlation gaps` — that rule is what produced the red",
             "heading-not-allowed" not in rules_on(opened, positive), rules_on(opened, positive)):
        seen_red("D the heading allowlist, driven red by an opened allowlist that lets the "
                 "operator's own example through")

    emptied = mutant(td, "openers-emptied",
                     ('BANNED_OPENERS = ("Why", "Worth reading", "Not included", "Still to do", '
                      '"Seen to fail",\n                  "Verification", "Controls", '
                      '"Review round")',
                      'BANNED_OPENERS = ("zzzz-no-such-opener",)'))
    if check("D CONTROL: with the banned-opener table EMPTIED, the known positive stops reding on "
             "`### Why MINOR`", "banned-opener" not in rules_on(emptied, positive),
             rules_on(emptied, positive)):
        seen_red("D the banned-opener table, driven red by an emptied table that lets `Why MINOR` "
                 "through")

    narrowed = mutant(td, "allowlist-narrowed",
                      ('ALLOWED_H2 = ("Highlights", "Upgrade warnings", "Bundled", "Release artifacts")',
                       'ALLOWED_H2 = ()'))
    if check("D CONTROL: with the allowlist NARROWED TO NOTHING, the COMPLIANT body reds — the "
             "rule reads the set, not the shape of a heading",
             "heading-not-allowed" in rules_on(narrowed, COMPLIANT_BODY),
             rules_on(narrowed, COMPLIANT_BODY)):
        seen_red("D the allowlist's membership test, driven red on a compliant body by an empty "
                 "allowlist")

    dropped = mutant(td, "artifacts-dropped",
                     ('ALLOWED_H2 = ("Highlights", "Upgrade warnings", "Bundled", '
                      '"Release artifacts")',
                      'ALLOWED_H2 = ("Highlights", "Upgrade warnings", "Bundled")'))
    arts = COMPLIANT_BODY + "\n## Release artifacts\n\n- [ ] SBOM regenerated for v1.4.0\n"
    if check("D CONTROL: with `Release artifacts` DROPPED from the allowlist, a body carrying the "
             "generator's artifacts checklist REDS — the tuple's membership is what admitted it",
             "heading-not-allowed" in rules_on(dropped, arts)
             and rules_on(mod_main, arts) == [], (rules_on(dropped, arts),
                                                  rules_on(mod_main, arts))):
        seen_red("D the `Release artifacts` row of the allowlist, driven red by a copy that drops "
                 "it and then reds on the section `release-pr-body` generates")

    cased = mutant(td, "ignorecase-dropped",
                   ('|".join(re.escape(p) for p in BANNED_OPENERS),\n'
                    '                        re.IGNORECASE)',
                    '|".join(re.escape(p) for p in BANNED_OPENERS))'))
    lower = COMPLIANT_BODY + "\n## why minor\n"
    upper = COMPLIANT_BODY + "\nWhy MINOR (1.3.0 -> 1.4.0)\n"
    if check("D CONTROL: with `re.IGNORECASE` DROPPED, `## why minor` stops reding on "
             "`banned-opener` while `Why MINOR` still does — the flag is what produced the red, "
             "and the mutant disabled one property rather than the rule",
             "banned-opener" not in rules_on(cased, lower)
             and "banned-opener" in rules_on(cased, upper),
             (rules_on(cased, lower), rules_on(cased, upper))):
        seen_red("D `re.IGNORECASE` on the opener pattern, driven red by a case-SENSITIVE copy "
                 "that lets a lower-cased narration heading through")

    underscored = mutant(td, "underscore-lookahead",
                         ('_BANNED_RE = re.compile(r"(?:%s)(?![A-Za-z0-9-])"',
                          '_BANNED_RE = re.compile(r"(?:%s)(?![\\w-])"'))
    und_body = COMPLIANT_BODY + "\n__Why__ this release is MINOR.\n"
    if check("D CONTROL: with the PRE-FIX `(?![\\w-])` lookahead back, `__Why__` stops reding — "
             "`_` is a `\\w` character, and that is the whole of the M2 defect",
             "banned-opener" not in rules_on(underscored, und_body)
             and "banned-opener" in rules_on(mod_main, und_body),
             (rules_on(underscored, und_body), rules_on(mod_main, und_body))):
        seen_red("D the opener lookahead's character class, driven red by the shipped `(?![\\w-])` "
                 "spelling that let an underscore-emphasised `__Why__` through")

    spanning = mutant(td, "audit-row-spanning",
                      (r'pattern = r"^(?:\*\*%(n)s:\*\*|%(n)s:)[ \t]*(?P<v>\S.*)$"',
                       r'pattern = r"^(?:\*\*%(n)s:\*\*|%(n)s:)\s*(?P<v>\S.*)$"'))
    spanning_body = "Scope line.\n\nBuilt:\ndispatched (coder ×1 / mechanic ×0)\n"
    if check("D CONTROL: with `\\s` back in the audit-row separator, a `Built:` line with NOTHING "
             "after it takes the NEXT line as its value — the `[ \\t]` narrowing is what refuses "
             "that", spanning.audit_fields(spanning_body)["Built"] is not None
             and mod_main.audit_fields(spanning_body)["Built"] is None,
             (spanning.audit_fields(spanning_body), mod_main.audit_fields(spanning_body))):
        seen_red("D the `[ \\t]` separator, driven red by the pre-narrowing `\\s` spelling, which "
                 "reads the line BELOW an empty field as its value")


# ══════════════════════════════════════════════════════════════════════════════════════════════
# F — `attribution-line`: THE RULE THAT REFUSES A LINE THE STANDARD USED TO REQUIRE
# ══════════════════════════════════════════════════════════════════════════════════════════════
def arm_f_attribution(mod, td):
    positive = read_fixture("attr_positive")
    negative = read_fixture("attr_negative")

    got = rules_on(mod, positive)
    check("F the ATTRIBUTION POSITIVE — fw#891's body as the operator read it — reds on "
          "`attribution-line`", "attribution-line" in got, got)
    hit = [f for f in mod.findings(positive) if f.rule == "attribution-line"]
    check("F ...on its FIRST line, quoting the offending line rather than a tally",
          len(hit) == 1 and hit[0].line == 1 and hit[0].text.strip() == "FROM: pm",
          [(f.line, f.text) for f in hit])
    check("F the ATTRIBUTION NEGATIVE — the same body after the line was struck — passes EVERY "
          "rule", rules_on(mod, negative) == [], rules_on(mod, negative))

    # Both rows of the family, and the indent the protocol's own parser tolerates. `parse_body` /
    # the Action's `parseBody` TRIM each line before `startswith`, so an indented attribution line
    # is an attribution line to every consumer that reads one — this rule reads the same population.
    for lead in ("FROM: pm", "TO: pm", "  FROM: pm", "\tTO: pm, impl"):
        body = COMPLIANT_BODY + "\n%s\n" % lead
        check("F `attribution-line` reds on %r" % lead,
              "attribution-line" in rules_on(mod, body), rules_on(mod, body))

    # …and NOT on a quotation of somebody else's attribution, which is the other half of a rule
    # that discriminates (the approval-marker fragment owns that distinction and was measured on
    # it: an indented/quoted `FROM:` is what a QUOTED post looks like).
    quoted = COMPLIANT_BODY + "\n> FROM: pm\n"
    check("F `attribution-line` does not fire on a BLOCK-QUOTED attribution line",
          "attribution-line" not in rules_on(quoted and mod, quoted), rules_on(mod, quoted))

    # …and not on a fenced one: the compliant body above carries `FROM: evil` / `TO: all` inside
    # its fence, so `arm_a`'s green already says this — driven here explicitly, on the smallest
    # body that can say it, because THIS rule is the newest consumer of the fence scan.
    fenced_only = ("**`dev → main` release PR — v1.4.0.** Bundles the commits since `v1.3.0`.\n\n"
                   "Built: dispatched (coder ×1 / mechanic ×0)\n"
                   "Coordinated in: acme/coordination#412\n\n"
                   "```\nFROM: pm\nTO: impl\n```\n")
    check("F an attribution line that exists only INSIDE A FENCE is payload, not a finding",
          rules_on(mod, fenced_only) == [], rules_on(mod, fenced_only))

    # ⛔ THE CONTROL. The positive reds on `heading-not-allowed` as well, so "the positive reds"
    # was TRUE OF THAT BODY BEFORE THIS RULE EXISTED. Unwire the rule from the one entry point and
    # the attribution finding must be the thing that disappears.
    unwired = mutant(td, "attribution-unwired",
                     ("           + rule_attribution_line(rows) + rule_audit_rows(body or \"\")",
                      "           + rule_audit_rows(body or \"\")"))
    got_u = rules_on(unwired, positive)
    if check("F CONTROL: with `rule_attribution_line` UNWIRED, the attribution positive stops "
             "reding on `attribution-line` — and still reds on its other row, so the mutant "
             "disabled one rule rather than the program",
             "attribution-line" not in got_u and "heading-not-allowed" in got_u, got_u):
        seen_red("F the attribution-line rule, driven red by an unwired copy that lets fw#891's "
                 "own `FROM: pm` line through")

    # ⛔ AND THE MACHINE-LINE FAMILY NO LONGER CONTAINS `FROM`/`TO`, WHICH IS A SEPARATE CLAIM
    # FROM "the new rule reds". `_MACHINE_LINE_RE` is what `rule_scope_line` SKIPS, and while it
    # carried the attribution rows this body — an attribution line plus the two audit rows, and no
    # prose at all — reported `scope-line` (every content row was skipped, so the rule fell through
    # to its no-scope-line finding). Now the attribution line is ordinary content: the body reds on
    # `attribution-line` ALONE, and deleting the line is what re-exposes the scope-line question.
    # Pinned on the pattern as well as the behaviour, because a regexp that quietly regained the
    # two names would restore the skip while every rule above stayed green.
    only_attr = "FROM: pm\n\nBuilt: x\nCoordinated in: acme/coordination#1\n"
    check("F a body of an attribution line + the audit rows reds on `attribution-line` and nothing "
          "else — the line is CONTENT now, not a skipped machine row",
          rules_on(mod, only_attr) == ["attribution-line"], rules_on(mod, only_attr))
    check("F ...and `_MACHINE_LINE_RE` — the set `rule_scope_line` skips — no longer matches "
          "`FROM:` / `TO:`, while it still matches the audit rows it does own",
          mod._MACHINE_LINE_RE.match("FROM: pm") is None
          and mod._MACHINE_LINE_RE.match("TO: pm") is None
          and mod._MACHINE_LINE_RE.match("Built: x") is not None
          and mod._MACHINE_LINE_RE.match("**Coordinated in:** acme/coordination#1") is not None,
          "the machine-line family is wrong")


# ══════════════════════════════════════════════════════════════════════════════════════════════
# G — `live-state-reading`: A CLOSED TABLE OF LIVE CI / PUSH / BASE READINGS (card#9073 leg B-2)
# ══════════════════════════════════════════════════════════════════════════════════════════════
# One example line per shape ALTERNATIVE, and every row pins ONE shape only — which is what lets
# each per-shape mutant below be read: when that shape is disabled, exactly its rows go quiet.
LIVE_ROWS = (
    ("ci-not-run", "- CI has not run on this head."),
    ("ci-not-run", "- CI has not run at `69dc8992ee78`."),
    ("ci-state-at", "- CI is green at `0a3536fea5cc208992daebe3206a777dd0c05927`."),
    ("ci-state-at", "- CI queued at this head."),
    # fw#901's live body carries this cell (upper-case, which is what the IGNORECASE mutant reads).
    ("ci-state-at", "| fix-4 | coder | **CI RED at `9f5fe2365476f893ae2c0f797aa5ee0496456af4`** |"),
    ("branch-push", "- The branch is not pushed."),
    ("branch-push", "- 4 commits, `dev` merged, all four pushed."),
    ("branch-push", "- `69dc8992ee78` is not pushed."),
    ("behind-base", "- The branch is 2 commits behind `origin/dev`."),
    ("behind-base", "- 3 commits ahead of main."),
    ("behind-base", "- 1 commit behind origin/main"),
    ("behind-base", "- 4 commits ahead of `dev`."),
    # fw#820's body, verbatim.
    ("runs-success", "* **CI at this head: 5 runs, all `conclusion == success`**"),
)

# Lines the rule must NOT red on: the header's NAMED residues — not judged by design, pinned here so
# the header's list is a measured claim — and the near misses a closed table exists to leave alone,
# a derivation (what the finding asks for) among them. Each row says which it is.
LIVE_NOT_JUDGED = (
    ("a base other than `dev` / `main` (pm ruling)", "- The branch is 3 commits behind `release`."),
    ("emphasis between a shape's words", "- CI is **green** at `0a3536fe`."),
    ("a reading split across table cells", "| CI at `69dc899` | **CLEAN**, rc 0 |"),
    ("a derivation with its pass conditions",
     "- CI at this head: `ci-read --sha <full 40-character head>`; a pass is a non-empty run set, "
     "every run terminal, every conclusion `success`."),
    ("the word CI with no head or sha", "- The daemon logs when CI is green but the cache is cold."),
    ("an installer-facing push", "- `acme-sync` now pushes the image to your registry on restart."),
    ("an all-letter word where a sha would be", "- CI is green at `defaced`."),
    ("a reading whose opening token follows a word — the clause-opening narrowing's residue",
     "- Note CI is green at `0a3536fe`."),
    # fw#910 r1 M1. More than one narrowing below withholds this line (a digit-only token AND a
    # token that follows a word), so no single mutant flips it; it is pinned here instead.
    ("a digit-only tag in words (fw#910 r1 M1)", "- Tag 2026091 pushed"),
)

# ⛔ THE NARROWINGS fw#910 r1 (M1, m1) FORCED, EACH AS A CONTROL. The shapes used to red on lines
# that are not readings: the pass conditions the finding tells the author to write, descriptions
# of what a tool does, a decimal number taken for a sha, and a base that merely BEGINS with `dev` or
# `main`. Each row is `(what the narrowing refuses, (its shipped spelling, the pre-fix spelling),
# the lines it ALONE withholds)`. Every line must PASS on the shipped lint and RED on a copy with
# that one narrowing reverted at EVERY occurrence — so each narrowing is shown to be what keeps its
# lines quiet, rather than some other shape happening not to match them.
LIVE_NARROWINGS = (
    ("a count of ZERO is not judged — a pass condition and a reading share the shape, so the "
     "zero-count reading is a named residue",
     (r"\b[1-9]\d* commits?", r"\b\d+ commits?"),
     ("- A pass is 0 commits behind `dev`.",
      "- the release script refuses unless the branch is 0 commits ahead of `main`",
      # fw#627's merged body, verbatim: a REAL base reading, withheld all the same — the residue.
      "`37d1c367` → exit 1. 0 commits behind `origin/dev` (`bcdc12bb`).")),
    ("a run count of ZERO is not judged, the same residue on `runs-success`",
     (r"\b[1-9]\d* runs?", r"\b\d+ runs?"),
     ("- A pass is never `0 runs, all success`.",)),
    ("a base is `dev` / `main` exactly, never a base whose name begins with one (m1)",
     (r"(?:dev|main)(?![\w/-]|\.\w)", r"(?:dev|main)\b"),
     ("- The branch is 3 commits behind `dev-next`.", "- 1 commit ahead of main-v2.",
      "- 2 commits behind origin/main-lts.", "- 2 commits behind dev/feature.")),
    ("a sha carries a hex LETTER, so a decimal number is not a sha",
     (r"(?=[0-9]*[a-f])", ""),
     ("- `2026091` is pushed as the release tag.",)),
    ("a shape OPENS ITS CLAUSE: the token it opens on does not follow a word",
     (r"(?<!\w )", ""),
     ("- A pass means CI is green at this head for every required workflow.",
      "- `coord-release` waits until all tags are pushed, then publishes.",
      "- after 12 runs all jobs report success",
      "- `coord-release` refuses when the branch is not pushed.",
      "- `ci-read` exits 3 while CI has not run on this head.",
      "- `coord-verify` waits until `69dc8992ee78` is pushed.",
      # fw#871's live body, verbatim: illustrative shas in a description of a tool's behaviour.
      "(`` `a1b2c3d` and `d4e5f6a` NOT pushed ``) the farther sha is skipped and its claim stops "
      "being",
      "* `` head `8673e93` PUSHED (s698) = fix-pass-3 on `c04de15` `` — pre-fix **both** shas "
      "were claimed")),
)


# ⛔ THE NAMED FALSE REDS (fw#910 r2 m-B, r3 m-2): lines that are NOT readings and red all the same,
# pinned RED so the header's claim is measured — a false red the header names with no row here is
# unchecked. `behind-base` does not open its clause (its own reading puts
# a word before the count), so no shape separates a tool description with a non-zero count from a
# reading. Each row is `(the shape it reds on, the line)`.
LIVE_KNOWN_FALSE_REDS = (
    ("behind-base", "- `coord-release` refuses when the branch is 5 commits behind `dev`."),
    ("behind-base", "- `coord-release` warns when the branch is 3 commits ahead of `main`."),
)


def _shapes_named(mod, finding):
    return [s for s, _ in mod.LIVE_STATE_READINGS if "`%s`" % s in finding.message]


def _live_hits(mod, line):
    """The shapes `mod` names on `line` appended to the compliant body — `[]` when it does not red."""
    body = COMPLIANT_BODY + "\n%s\n" % line
    hits = [f for f in mod.findings(body) if f.rule == "live-state-reading"]
    return _shapes_named(mod, hits[0]) if hits else []


def arm_g_live_state(mod, td):
    positive = read_fixture("live_positive")
    negative = read_fixture("live_negative")

    # THE POSITIVE, ON THE LINES COMMENT 4102 NAMED. The expected lines are DERIVED from the
    # fixture by their text, never written down as numbers.
    claimed = ("CI has not run on this head", "commits behind `origin/dev`")
    want = [n for n, t, fenced in mod.scan(positive)
            if not fenced and any(c in t for c in claimed)]
    hits = [f for f in mod.findings(positive) if f.rule == "live-state-reading"]
    check("G the LIVE-STATE POSITIVE — fw#873's body as first published — reds on "
          "`live-state-reading`", hits, rules_on(mod, positive))
    check("G ...on exactly the lines carrying comment 4102's claims, and no other line",
          want and [f.line for f in hits] == want, ([f.line for f in hits], want))
    named = {f.line: _shapes_named(mod, f) for f in hits}
    check("G ...naming `ci-not-run` + `branch-push` on the CI line and `behind-base` on the base line",
          sorted(named.values()) == [["behind-base"], ["ci-not-run", "branch-push"]], named)

    # THE HAND-BUILT NEGATIVE IS ASSERTED TO BE WHAT IT CLAIMS: the real positive, byte for byte,
    # except exactly the lines the rule reds on.
    pl, nl = positive.split("\n"), negative.split("\n")
    changed = [i + 1 for i, (a, b) in enumerate(zip(pl, nl)) if a != b]
    check("G the HAND-BUILT negative differs from the real positive on exactly those lines",
          len(pl) == len(nl) and changed == want, (len(pl), len(nl), changed, want))
    check("G ...and does not red on `live-state-reading`",
          "live-state-reading" not in rules_on(mod, negative), rules_on(mod, negative))
    check("G ...while every OTHER rule the positive reds on still reds — the pair differs by this "
          "rule and nothing else", set(rules_on(mod, positive)) - set(rules_on(mod, negative))
          == {"live-state-reading"}, (rules_on(mod, positive), rules_on(mod, negative)))

    for shape, line in LIVE_ROWS:
        check("G `%s` reds on %r, naming that shape alone" % (shape, line),
              _live_hits(mod, line) == [shape], _live_hits(mod, line))
    for why, line in LIVE_NOT_JUDGED:
        body = COMPLIANT_BODY + "\n%s\n" % line
        check("G NOT judged — %s: %r" % (why, line), rules_on(mod, body) == [], rules_on(mod, body))
    for why, (shipped, pre_fix), lines in LIVE_NARROWINGS:
        for line in lines:
            body = COMPLIANT_BODY + "\n%s\n" % line
            check("G NOT judged — %s: %r" % (why, line), rules_on(mod, body) == [],
                  rules_on(mod, body))
    for shape, line in LIVE_KNOWN_FALSE_REDS:
        check("G a FALSE RED the header names — a tool description — reds on `%s` alone: %r"
              % (shape, line), _live_hits(mod, line) == [shape], _live_hits(mod, line))

    # A QUOTED READING COUNTS (pm ruling): fw#873's final body quotes its first revision this way.
    quoted = ('Revision 1 asserted "CI has not run on this head; the branch is not pushed" and '
              '"2 commits behind `dev`" — both false at the head they described.')
    check("G a QUOTED reading reds — the ruling's case, fw#873's final body",
          _live_hits(mod, quoted) == ["ci-not-run", "branch-push", "behind-base"],
          _live_hits(mod, quoted))
    fenced = COMPLIANT_BODY + "\n```\nCI has not run on this head.\n```\n"
    check("G a reading that exists only INSIDE A FENCE is payload, not a finding",
          rules_on(mod, fenced) == [], rules_on(mod, fenced))

    # ⛔ THE CONTROLS. The positive reds on older rules too, so "the positive reds" was true
    # before this rule existed: unwire it, and the live-state finding must be what disappears.
    unwired = mutant(td, "live-state-unwired",
                     ("           + rule_live_state_reading(rows)\n", ""))
    got_u = rules_on(unwired, positive)
    if check("G CONTROL: with `rule_live_state_reading` UNWIRED, fw#873's reading lines stop reding on "
             "`live-state-reading` — and every other rule still reds, so the mutant disabled one "
             "rule rather than the program",
             "live-state-reading" not in got_u
             and set(got_u) == set(rules_on(mod, positive)) - {"live-state-reading"}, got_u):
        seen_red("G the live-state-reading rule, driven red by an unwired copy that lets fw#873's "
                 "`CI has not run on this head` and `2 commits behind origin/dev` through")

    for shape, _ in mod.LIVE_STATE_READINGS:
        off = mutant(td, "shape-%s-disabled" % shape,
                     ('("%s",\n     r"(?:' % shape, '("%s",\n     r"(?!)(?:' % shape))
        own = [line for s, line in LIVE_ROWS if s == shape]
        others = [line for s, line in LIVE_ROWS if s != shape]
        if check("G CONTROL: with shape `%s` DISABLED, its own rows stop reding while every other "
                 "shape's rows still red" % shape,
                 own and all(_live_hits(off, l) == [] for l in own)
                 and all(_live_hits(off, l) for l in others),
                 ([_live_hits(off, l) for l in own], [_live_hits(off, l) for l in others])):
            seen_red("G shape `%s`, driven red by a copy whose pattern can never match" % shape)

    cased = mutant(td, "live-state-ignorecase-dropped",
                   ("re.compile(pattern, re.IGNORECASE)", "re.compile(pattern)"))
    upper = next(line for _, line in LIVE_ROWS if "CI RED at" in line)
    lower = next(line for _, line in LIVE_ROWS if "CI is green at" in line)
    if check("G CONTROL: with `re.IGNORECASE` DROPPED from the shape table, fw#901's `CI RED at "
             "<sha>` stops reding while the lower-case `CI is green at <sha>` still does",
             _live_hits(cased, upper) == [] and _live_hits(cased, lower) == ["ci-state-at"]
             and _live_hits(mod, upper) == ["ci-state-at"],
             (_live_hits(cased, upper), _live_hits(cased, lower))):
        seen_red("G `re.IGNORECASE` on the shape table, driven red by a case-sensitive copy that "
                 "lets an upper-case `CI RED at <sha>` through")

    # ⛔ AND EACH NARROWING, REVERTED IN A COPY, MUST RE-RED ITS OWN LINES — while every shape's
    # true-positive rows still red on that shape alone, so the copy reverted one narrowing rather
    # than the rule.
    for i, (why, (shipped, pre_fix), lines) in enumerate(LIVE_NARROWINGS):
        rev = mutant(td, "live-narrowing-%d-reverted" % i, (shipped, pre_fix), count=-1)
        if check("G CONTROL: with the narrowing REVERTED (%s), its lines red again while every "
                 "true-positive row still reds" % why,
                 all(_live_hits(rev, l) for l in lines)
                 and all(_live_hits(rev, l) == [s] for s, l in LIVE_ROWS),
                 ([_live_hits(rev, l) for l in lines],
                  [(s, _live_hits(rev, l)) for s, l in LIVE_ROWS if _live_hits(rev, l) != [s]])):
            seen_red("G narrowing — %s — driven red by a copy with its pre-fix spelling back" % why)

    proc = run_cli(["--body-file", os.path.join(FIXTURE_DIR, FIXTURES["live_positive"][0])])
    check("G the CLI exits 1 on the live-state positive and names the rule",
          proc.returncode == 1 and "live-state-reading" in proc.stdout, proc.stdout[-400:])


# ══════════════════════════════════════════════════════════════════════════════════════════════
# E — THE CLI, WHICH IS WHAT CI AND THE REVIEW PATH ACTUALLY RUN
# ══════════════════════════════════════════════════════════════════════════════════════════════
def run_cli(args, env=None, cwd=None):
    full = dict(os.environ)
    full.pop("COORD_PR_BODY", None)
    full.update(env or {})
    return subprocess.run([sys.executable, TOOL] + args, capture_output=True, text=True,
                          encoding="utf-8", errors="replace", timeout=60, env=full, cwd=cwd)


def arm_e_cli(td):
    pos_path = os.path.join(FIXTURE_DIR, FIXTURES["positive"][0])
    neg_path = os.path.join(FIXTURE_DIR, FIXTURES["negative"][0])

    proc = run_cli(["--body-file", pos_path])
    check("E the CLI exits 1 on the known positive", proc.returncode == 1, proc.stdout[-400:])
    check("E ...and its output quotes the offending lines and carries no finding TALLY",
          "Correlation gaps" in proc.stdout and "Why MINOR" in proc.stdout
          and not re.search(r"\b\d+ finding", proc.stdout), proc.stdout[-400:])

    proc = run_cli(["--body-file", neg_path])
    check("E the CLI exits 0 on the known negative", proc.returncode == 0, proc.stdout[-400:])

    body_path = os.path.join(td, "body.md")
    with open(body_path, "w", encoding="utf-8", newline="") as fh:
        fh.write(COMPLIANT_BODY)
    proc = run_cli(["--body-file", body_path, "--json"])
    check("E `--json` exits 0 and carries the two audit VALUES the review path transports",
          proc.returncode == 0 and '"clean": true' in proc.stdout
          and "acme/coordination#412" in proc.stdout, proc.stdout[:400])

    # ⛔ THE CI ENTRY POINT IS `--env`, AND AN UNSET VARIABLE IS NOT AN EMPTY BODY. A workflow that
    # mis-spells the variable would otherwise get a clean-looking red on a body nobody read.
    proc = run_cli(["--env", "COORD_PR_BODY"], env={"COORD_PR_BODY": COMPLIANT_BODY})
    check("E `--env` reads the body from the environment (the injection-safe CI route)",
          proc.returncode == 0, proc.stdout[-300:])
    proc = run_cli(["--env", "COORD_PR_BODY"])
    check("E ...and an UNSET variable is a FAULT (exit 2), never an empty body (exit 1)",
          proc.returncode == 2 and "not set" in proc.stderr, (proc.returncode, proc.stderr[:200]))

    proc = run_cli([])
    check("E no source is a usage fault, not a pass", proc.returncode != 0, proc.returncode)


def main():
    global mod_main
    before = sha256_file(TOOL)
    mod_main = load_tool()
    with tempfile.TemporaryDirectory() as td:
        arm_a_the_controls(mod_main)
        arm_b_each_rule(mod_main)
        arm_c_fences(mod_main, td)
        arm_d_mutants(td)
        arm_f_attribution(mod_main, td)
        arm_g_live_state(mod_main, td)
        arm_e_cli(td)
    after = sha256_file(TOOL)
    check("the shipped file was not touched by this suite (sha256 before == after)",
          before == after, "%s -> %s" % (before, after))

    for line in SEEN_RED:
        print("    seen red: %s" % line)
    if FAILURES:
        print("pr-body-lint.selftest: FAIL")
        for f in FAILURES:
            print("  - %s" % f)
        return 1
    print("pr-body-lint.selftest: ok (%d checks; %d controls SEEN RED before their green was "
          "believed)" % (CHECKS, len(SEEN_RED)))
    return 0


if __name__ == "__main__":
    sys.exit(main())
