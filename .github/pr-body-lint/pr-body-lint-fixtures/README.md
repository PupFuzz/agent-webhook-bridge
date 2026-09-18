# `pr-body-lint` fixtures — real PR bodies, each pair one PR before and after its ruling

These are not invented inputs, except a negative whose row says HAND-BUILT. Every positive below is
the body of a real `PupFuzz/agent-board-framework` pull request, read from that PR's
`userContentEdits`, and every other negative is the same body after the rewrite a ruling forced.
The fw#873 negative is the HAND-BUILT one, and its row says so. `pr-body-lint.selftest.py` pins
each one's `sha256` and says which revision it is; the pin is what makes "the known positive" a claim a second reader can check
rather than a label.

| file | revision | what it is |
|---|---|---|
| `fw847-v0.50.0-body-2026-09-08T220229Z.md.txt` | #847, `editedAt 2026-09-08T22:02:29Z` | **The COMMENTARY positive.** The body card#9073 was minted against, and the one the operator read: *"It is full of commentary again instead of what someone installing the package needs to know. For example, Why MINOR (0.49.0 → 0.50.0) section and Correlation gaps."* Both of those are here, and the lint reds on exactly them. |
| `fw847-v0.50.0-body-rewritten.md.txt` | #847, `editedAt 2026-09-08T23:37:01Z` (the live body) | **Its negative.** The same release, rewritten to the standard after that ruling. It passes. |
| `fw891-v0.51.0-body-2026-09-10T140853Z.md.txt` | #891, `editedAt 2026-09-10T14:08:53Z` | **The ATTRIBUTION-LINE positive.** The v0.51.0 body as it stood when the operator read it and ruled: *"The top of the PR body says 'From: pm'. remove that line"* (2026-09-11, card#9073 comment 4491 → card#9229 → leg E). Its first line is `FROM: pm`, and the `attribution-line` rule reds on that line. It also reds on `heading-not-allowed` (`## Correlation gaps`), which is why `arm_f`'s control unwires the new rule rather than trusting "the positive reds". |
| `fw891-v0.51.0-body-rewritten.md.txt` | #891, the live body after the 2026-09-11T03:13:30Z edit | **Its negative.** The same release body with the attribution line struck. It passes every rule. |
| `fw873-body-2026-09-08T205957Z.md.txt` | #873, `editedAt 2026-09-08T20:59:57Z` (its `createdAt` — the body as first published) | **The LIVE-STATE READING positive** (card#9073 comment 4102, leg B-2). Its `CI has not run on this head. The branch is not pushed` and `The branch is 2 commits behind origin/dev` lines are the instance the rule was minted on, both false at the head they described; the `live-state-reading` rule reds on exactly those lines. It also reds on older rules, which is why `arm_g`'s control unwires the new rule. |
| `fw873-body-hand-built-derivations.md.txt` | **HAND-BUILT** from the row above — not a revision of #873 | **Its negative.** fw#873 never rewrote those lines into derivations, so no real negative exists. This is the positive byte for byte except those lines, each replaced by the command that re-derives it and its pass conditions; `arm_g` ASSERTS that, rather than trusting this sentence. It passes `live-state-reading` and still reds on the older rules. |

**They are committed because the positives are otherwise unreproducible.** `gh pr view <n> --json
body` returns the REWRITTEN body in both cases — the body that meets the standard — so the defect
each card exists to close survives only in GitHub's edit history and here. A selftest that fetched
them would also stop being hermetic, and one built on a hand-written imitation would be a control
planted in a shape the program already handles.

**The PAIR is the discrimination, not the positive alone.** One release, one author, hours or days
apart: a rule that reds on the first and passes the second is reading the standard, and a rule that
reds on both is reading length. `skills/release-pr/SKILL.md § PR body` § *Worked before/after* is
the prose half of the #847 pair and owns the clause-by-clause reading; `docs/protocol-spec.md`
§ Addressing owns the rule the #891 pair measures. Nothing here restates either.

**The extension is `.md.txt` deliberately.** These are captured DATA, not documents of
this tree, and this repo's markdown guards walk `*.md` tree-wide and grade what they find —
the #847 positive's own prose NAMES this repo's doc-ownership tag, in the elliptical form
the convention does not accept, and `doc-crossref-drift` read the release body as making a
malformed ownership claim. That finding was true about a file nobody may edit: fixing it would
mean editing the evidence. The extension keeps these bytes out of every `*.md` population at
once; that one guard also walks non-markdown files, and carries a declared skip for this file
beside the one it already carries for itself, for the same stated reason.

**A NEW FIXTURE MAY NEED A WRITTEN DISPOSITION, NOT A REWRITE.** A captured body can cite a
framework-repo path an install reader cannot reach, or restate a claim this tree has since
retracted, and the framework repo's own CI reads both — `shipped-path-reachability` and
`workflow-continue-on-error-scan` (repo infrastructure, NOT part of what this plugin installs,
so an adopter has neither). Each already names the fw#873 pair individually, per file rather
than by directory, so a fixture added later reds there on purpose. The remedy is a registered
reason in that guard, never an edit here: the bytes are sha256-pinned by
`pr-body-lint.selftest.py` and one of them is additionally held byte-identical to its positive.
