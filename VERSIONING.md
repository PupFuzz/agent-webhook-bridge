# Versioning policy

How the bridge is versioned, released, and tagged. Mirrors the kanban-board project's process; see [`feedback-git-workflow`](../.claude/projects/-home-kanban/memory/feedback-git-workflow.md) for the operating rule.

## The core rules

1. **Single source of truth for the version:** the [`VERSION`](VERSION) file at the repo root, containing one semver string and a trailing newline. Read it in PHP via `trim(file_get_contents(base_path('VERSION')))`.
2. **Version bumps happen on `dev` in a release-prep feature PR**, NOT on each feature PR. The bump + CHANGELOG update is the dedicated release act.
3. **Every release tag `v<version>` corresponds to a `docs/CHANGELOG.md` entry** describing what bundle of merged PRs is in the release.
4. **Tags are created on `main`, not `dev`.** After the user merges the release PR (`dev` → `main`), Claude tags the new main commit with `v<VERSION>`. The tag SHA equals the merge commit's SHA on main.
5. **Back-merge `main` → `dev` after every release** so the branches don't diverge (per [`feedback-auto-backmerge-after-release`](../.claude/projects/-home-kanban/memory/feedback-auto-backmerge-after-release.md)). The user's confirmation that the release PR merged to `main` IS the authorization for the back-merge sync PR — Claude opens it autonomously (no separate ask) and auto-merges on green.

## Branching model

Two long-lived branches: **`main`** (releases only) and **`dev`** (integration). All feature work branches off `dev` and PRs back to `dev`. Only the user merges to `main` (release PRs).

This is the same shape as kanban-board, adopted wholesale.

## Bump sizing

Pre-1.0: every change is a minor bump (`0.X.0`) — semver doesn't apply until v1.0.0. Within minor, breaking changes are allowed but call out in the changelog.

Post-1.0 (when stable):
- **Patch** (`x.y.Z+1`) — bug fixes, dep bumps, refactors, docs, internal-only feature work
- **Minor** (`x.Y+1.0`) — new user-visible additions (new provider adapter, new CLI flag)
- **Major** (`X+1.0.0`) — breaking changes to the public surface (classifier interface, config schema, CLI flag removal, db schema migration that needs operator action)

When in doubt pre-1.0: minor-bump.

## Release flow

Hybrid policy: ask before opening every PR; auto-merge dev-targeted on green; only the user merges to main.

1. **Pick the next version.** Read `VERSION`, pick the next semver per the bump-sizing rule above.
2. **Feature branch off `dev`.** Name: `release/v<version>` or `chore/release-v<version>`.
3. **Bump `VERSION`** to the new semver.
4. **Update `docs/CHANGELOG.md`.** Move `[Unreleased]` content into a `## [X.Y.Z] - YYYY-MM-DD` heading, then re-seed `[Unreleased]` as empty.
5. **ASK user** before opening the PR.
6. Open release-prep PR `release/...` → `dev` with full release notes in the PR body.
7. Wait for ALL CI checks (Tests + Security + any future workflow) to complete + pass.
8. **Auto-merge** the release-prep PR to `dev` on green (it targets `dev`).
9. **ASK user** before opening the release PR `release/v<version>` → `main`. **CRITICAL: the PR head must be the `release/v<version>` branch, NOT `dev` directly.** GitHub's "Automatically delete head branches" repo setting auto-deletes whichever branch is the merged PR's head — if you set head=`dev`, `dev` gets deleted when the user merges. Repo settings can't reliably exclude `dev` on the free plan (branch protection rules require Pro for private repos), so the discipline lives in the branch-naming convention.
10. Wait for ALL CI checks on the release → main PR.
11. **Notify user it's ready to merge.** Claude does NOT run `gh pr merge` against a `main`-targeted PR regardless of CI state.
12. **After user merges to `main` and confirms the merge to Claude:** that confirmation is the standing authorization for both the tag AND the back-merge sync PR — Claude does NOT ask again.
13. Claude tags the merge commit with `v<VERSION>` and pushes the tag (standing authorization from the user's merge confirmation).
14. Claude opens the back-merge sync PR `main` → `dev` (named `sync/main-to-dev-post-v<version>`) so any commits the user added directly on `main` (e.g., release-PR metadata) get back into `dev`. **No additional ask** — the user's main-merge confirmation covered it.
15. Wait for ALL CI checks on the sync PR.
16. **Auto-merge** the sync PR to `dev` on green (it targets `dev`).

## Anti-patterns

- **Don't tag a release before doc-sync.** The CHANGELOG entry must land in the same commit as the version bump (typically the same release PR).
- **Don't bump the version on a regular feature PR.** Version bumps belong to release events.
- **Don't reuse a tag.** Tags are immutable; if a release is broken, ship `vX.Y.Z+1` not "v0.1.0-fixed".
- **Don't tag `dev`.** Only `main` gets tags. `dev` is a moving integration target.
- **Don't PR `dev` directly to `main`.** Use a disposable `release/v<version>` branch as the PR head. If `dev` is the PR head and the user merges with auto-delete-head-branches enabled, `dev` is deleted. (The discipline lives in the branch-naming convention because of a prior incident where `dev` was deleted this way.)

## Pre-1.0 status

The current line is **v0.13.0**. Git history begins at v0.12.0 — the Laravel rewrite, shipped as a fresh repository (see `CLAUDE_DECISIONS.md` DL-001). Pre-1.0 semver applies: every change is a minor bump until v1.0.0. See [`docs/CHANGELOG.md`](docs/CHANGELOG.md) for the per-version log.
