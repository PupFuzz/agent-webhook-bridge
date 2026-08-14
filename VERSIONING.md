# Versioning policy

How the bridge is versioned, released, and tagged. Mirrors the kanban-board project's process; see [`feedback-git-workflow`](../.claude/projects/-home-kanban/memory/feedback-git-workflow.md) for the operating rule.

## The core rules

1. **Single source of truth for the version:** the [`VERSION`](VERSION) file at the repo root, containing one semver string and a trailing newline. Read it in PHP via `trim(file_get_contents(base_path('VERSION')))`.
2. **Version bumps happen on `dev` in a release-prep feature PR**, NOT on each feature PR. The bump + CHANGELOG update is the dedicated release act.
3. **Every release tag `v<version>` corresponds to a `docs/CHANGELOG.md` entry** describing what bundle of merged PRs is in the release. **Enforced at PR time** by [`changelog-gate.yml`](.github/workflows/changelog-gate.yml) since DL-276 — before that it was enforced only by whoever cut the release noticing, and a release cut without its entry published a generated placeholder over the gap.
4. **Tags are created on `main`, not `dev`, by CI.** After the user merges the release PR (`dev` → `main`), the [`auto-tag-version.yml`](.github/workflows/auto-tag-version.yml) workflow fires on the push to `main`, reads `VERSION`, tags the merge commit `v<VERSION>`, and publishes a GitHub Release from that version's `docs/CHANGELOG.md` section. **Claude does not hand-tag** — the workflow owns it (idempotent; a tag already at a *different* SHA fails the workflow loud, meaning the release PR forgot to bump `VERSION`). The tag SHA equals the merge commit's SHA on `main`.
5. **Back-merge `main` → `dev` after every release** so the branches don't diverge (per [`feedback-auto-backmerge-after-release`](../.claude/projects/-home-kanban/memory/feedback-auto-backmerge-after-release.md)). The user's confirmation that the release PR merged to `main` IS the authorization for the back-merge sync PR — Claude opens it autonomously (no separate ask) and auto-merges on green.

## Branching model

Two long-lived branches: **`main`** (releases only) and **`dev`** (integration). All feature work branches off `dev` and PRs back to `dev`. Only the user merges to `main` (release PRs).

This is the same shape as kanban-board, adopted wholesale.

## Bump sizing

The bridge is pre-1.0, so semver's stability guarantees don't formally apply until v1.0.0 and breaking changes are allowed within a minor (call them out in the changelog). The **effective cadence** — matching the actual tag history (e.g. v0.44.0 feature → v0.43.1/.2/.3 and v0.44.1 fix/refactor patches) — is:

- **Patch** (`x.y.Z+1`) — bug fixes, dep bumps, refactors, docs, internal-only changes with no new user-visible surface.
- **Minor** (`x.Y+1.0`) — new user-visible additions (a new provider adapter, a new CLI flag, a new writeback outcome).
- **Major** (`X+1.0.0`) — reserved for post-1.0 breaking changes to the public surface (classifier interface, config schema, CLI flag removal, a migration needing operator action).

When a release mixes a feature with fixes, lean minor; a fixes/refactors/docs-only release is a patch.

## Release flow

Policy (per `CLAUDE.md` rule #5 — the ask-before-opening checkpoint is retired): open PRs autonomously; auto-merge dev-targeted on green; only the user merges to main.

1. **Pick the next version.** Read `VERSION`, pick the next semver per the bump-sizing rule above.
2. **Feature branch off `dev`.** Name: `release/v<version>` or `chore/release-v<version>`.
3. **Bump `VERSION`** to the new semver.
4. **Update `docs/CHANGELOG.md`.** Move `[Unreleased]` content into a `## [X.Y.Z] - YYYY-MM-DD` heading, then re-seed `[Unreleased]` as empty.
5. **Add the release's `CLAUDE.md` § Recent releases row** (trimming the table back to the last 10, per the doc-retention discipline that section states). Not optional bookkeeping: since DL-279 a release PR that leaves any declared artifact behind hard-fails CI. **[`.release-pr.json`](.release-pr.json)'s `artifacts` array is the authority for the full member set** — read it there; this list is not a second copy of it.
6. Run the pre-PR review loop (CLAUDE.md rule #1) on the release surface.
7. Open the release-prep PR `release/...` → `dev` with full release notes in the PR body (no ask — PR-opening is pre-authorized per CLAUDE.md rule #5).
8. Wait for CI to complete + pass per `CLAUDE.md` standing rule #5 — it owns what must be green and how to read the list (card#5575; deliberately not restated here).
9. **Auto-merge** the release-prep PR to `dev` on green (it targets `dev`).
10. Open the release PR `release/v<version>` → `main` (no ask to open; the user-merge gate in step 12 is the control point). **CRITICAL: the PR head must be the `release/v<version>` branch, NOT `dev` directly.** GitHub's "Automatically delete head branches" repo setting auto-deletes whichever branch is the merged PR's head — if you set head=`dev`, `dev` gets deleted when the user merges. Repo settings can't reliably exclude `dev` on the free plan (branch protection rules require Pro for private repos), so the discipline lives in the branch-naming convention.
11. Wait for ALL CI checks on the release → main PR.
12. **Notify user it's ready to merge.** Claude does NOT run `gh pr merge` against a `main`-targeted PR regardless of CI state.
13. **After user merges to `main` and confirms the merge to Claude:** that confirmation is the standing authorization for the back-merge sync PR — Claude does NOT ask again. (The tag + GitHub Release are minted automatically by `auto-tag-version.yml`; Claude does not hand-tag.)
14. `auto-tag-version.yml` (on the push to `main`) tags the merge commit `v<VERSION>` and publishes the GitHub Release from the `docs/CHANGELOG.md` section — automatically, no Claude action. (Fallback only: if the workflow is ever absent, the user's merge confirmation is the standing authorization to tag manually.)
15. Claude opens the back-merge sync PR `main` → `dev` (named `sync/main-to-dev-post-v<version>`) so any commits the user added directly on `main` (e.g., release-PR metadata) get back into `dev`. **No additional ask** — the user's main-merge confirmation covered it.
16. Wait for ALL CI checks on the sync PR.
17. **Auto-merge** the sync PR to `dev` on green (it targets `dev`).

## What CI enforces about the CHANGELOG and the declared release artifacts (DL-276, DL-279)

**Two** workflows read the release docs **before** a merge, on `pull_request`. They compose — neither replaces the other, and they share no code:

- [`changelog-gate.yml`](.github/workflows/changelog-gate.yml) (DL-276) owns `docs/CHANGELOG.md`: that a version bump carries its `## [<VERSION>]` section, that the section fits GitHub's release-body limit (the assertion nothing else makes), and that every PR touching an in-scope path is named in `[Unreleased]` (the scope table below owns the member list).
- [`release-artifacts-gate.yml`](.github/workflows/release-artifacts-gate.yml) (DL-279) owns the must-move-together SET: that **every** member declared in [`.release-pr.json`](.release-pr.json)'s `artifacts` array moved in the release PR's own changes, still exists at head, and agrees with the version being shipped. **That array is the authority for the member list** — the gate reads the declaration instead of naming files, so a new member is guarded by declaring it. It says nothing about the release-body limit.

`changelog-gate.yml`'s two assertions:

| When it fires | What it requires | Why here and not post-merge |
| --- | --- | --- |
| The PR changes `VERSION` | `docs/CHANGELOG.md` carries a `## [<VERSION>]` section, **and** that section is within GitHub's 125,000-byte release-body limit | Post-merge the tag has already landed. A missing section published a placeholder; an oversize one left the release tagged and **unpublished** (v0.72.0, HTTP 422) |
| The PR changes `app/`, `bin/`, `.github/workflows/`, `.github/actions/`, `.release-pr.json`, `phpstan-laravel.neon`, `pint.json`, or `phpunit.xml` (the last four matched as whole root paths, not prefixes — `config/pint.json` and `phpunit.xml.dist` are out) | The `[Unreleased]` section names the PR title's `card#`/`DL-` token — or, for a PR with no such token, `[Unreleased]` changed at all | Nothing else reads the changelog between releases, so an omission is invisible until one is cut. 25 of 56 tokened commits reached v0.72.0 undocumented this way. `.github/workflows/` joined the scope on card#6056 (DL-280): what CI accepts or rejects is shipped behaviour for a contributor, and the DL-279 gate adoption — a change to what CI accepts on every release PR — was itself structurally exempt. **card#6100 (DL-282) then NARROWED — did not close — the gap DL-280 disclosed between the path scope and that criterion**: five more files a CI step reads joined the scope, because widening to two of the five and not the rest would re-mint the *guard reads a proper subset* shape one directory down. The scope is **not** claimed to exhaust the criterion on either half: `composer.json`, `composer.lock` and `.env.example` are disclosed CI-half gaps gated on card#6137, and `config/`, `routes/` and `database/migrations/` sit outside the software half as a bound inherited from DL-276. `phpunit.xml`'s test-fixture `<php><env>` block is inside the scope as an accepted coarse-predicate bound (DL-282) |

Exempt branches — **the `[Unreleased]`-entry assertion only**, same set as `pr-title-lint.yml`: `dependabot/*`, `release/*`, `sync/*`, `revert-*`. The `VERSION` assertion exempts no branch (a `release/*` PR is precisely what it must check; it is classified by the version MOVING, not by the base ref, so the release branch's two PRs are both covered without naming either). A docs-only or test-only PR is exempt from the `[Unreleased]`-entry assertion by the path scope, not by a carve-out. `release-artifacts-gate.yml` likewise exempts nothing: a non-release PR is skipped by version VALUE, so it never needs a branch name.

Both of that gate's assertions and the release publisher extract sections through the single `bin/changelog-section.py`, so the gate measures the body the publisher would actually ship. [`auto-tag-version.yml`](.github/workflows/auto-tag-version.yml) **stays fail-soft on a missing section by design** — failing there would leave a merged release untagged — but an oversize section is now truncated to whole lines with a pointer to the full entry at the tag, rather than failing the publish.

## Anti-patterns

- **Don't tag a release before doc-sync.** The CHANGELOG entry must land in the same commit as the version bump (typically the same release PR).
- **Don't bump the version on a regular feature PR.** Version bumps belong to release events.
- **Don't reuse a tag.** Tags are immutable; if a release is broken, ship `vX.Y.Z+1` not "v0.1.0-fixed".
- **Don't tag `dev`.** Only `main` gets tags. `dev` is a moving integration target.
- **Don't PR `dev` directly to `main`.** Use a disposable `release/v<version>` branch as the PR head. If `dev` is the PR head and the user merges with auto-delete-head-branches enabled, `dev` is deleted. (The discipline lives in the branch-naming convention because of a prior incident where `dev` was deleted this way.)

## Pre-1.0 status

The current version is tracked in [`VERSION`](VERSION); [`docs/CHANGELOG.md`](docs/CHANGELOG.md) is the per-version log. Git history begins at v0.12.0 — the Laravel rewrite, shipped as a fresh repository (see `CLAUDE_DECISIONS.md` DL-001). Pre-1.0 semver applies (no formal stability guarantee until v1.0.0); the per-change bump follows the effective cadence in § Bump sizing.
