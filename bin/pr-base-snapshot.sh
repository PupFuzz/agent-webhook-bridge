#!/usr/bin/env bash
#
# Prints THE BASE SNAPSHOT a `pull_request` workflow step must pair its work
# tree with: the FIRST PARENT of the merge commit `actions/checkout` leaves at
# HEAD. Nothing else is printed on stdout, so callers use it as
# `BASE=$(bin/pr-base-snapshot.sh "$HEAD_SHA")`.
#
# WHY NOT `github.event.pull_request.base.sha`, which four steps in this repo
# passed until card#8527. GitHub does not refresh that field on every
# `synchronize`, so it can LAG the base tip the merge commit was actually
# computed against. Measured 2026-09-02 on PR #640, run 33598959720: the event
# carried `base.sha` 549c894 while the merge commit 642decf the SAME event
# checked out has first parent 7a11085 — `dev`'s tip after #639 merged.
# `dl-collision-gate.yml` paired those two, read `dev`'s own DL-335 as a number
# the PR had minted, and refused an innocent PR (predicate rc 6) — the exact
# false refusal its comment said the pairing existed to prevent.
#
# ⚠ THIS IS NOT THE `git merge-base "$BASE" HEAD` "correction" that
# dl-collision-gate.yml warns against, and that warning still stands. That
# rewrite RE-DERIVES a base from whatever head it is handed, so the first caller
# whose head is not the PR merge silently gets the FORK POINT — and then every
# entry the base branch gained after the fork sits in the merge tree, is absent
# at the fork point, and reads as this PR's. This script derives nothing: HEAD^1
# is the base commit the merge RECORDS. It reads the input; it does not
# recompute it, and where the recorded shape is absent it refuses instead of
# guessing.
#
# THE CONTRACT IT CHECKS, rather than assumes:
#   * HEAD is a commit with exactly TWO parents, and
#   * HEAD^2 is the PR head sha the event carried (argument 1),
# which together say "this work tree IS the merge of THIS PR's head into its
# base". The second leg is what makes the first non-vacuous: a merge is only the
# right one if it is a merge OF THIS PR. Either leg failing exits non-zero with
# the reason on stderr and prints no sha — an unreadable pairing is not a clean
# one, and a step that cannot say what its base IS must not report a verdict.
#
# ⚑ THE PRECONDITION IS MEASURED, NOT ASSUMED: GitHub NEVER FAST-FORWARDS
# `refs/pull/<n>/merge`. The obvious worry about requiring two parents is a PR
# whose branch has already merged the base — a fast-forward would leave a
# one-parent commit and this script would refuse every such PR. Checked
# 2026-09-02 against the two open PRs on this repo in exactly that state: #635
# (merge 570d1dc, parents e30d34a/bd46c3b) and #630 (merge 6286dc9, parents
# 7a11085/936a98d) are both TWO-parent merges while `git merge-base
# --is-ancestor <base> <head>` is true for each. A plain `git merge` in a
# fixture DOES fast-forward there, which is why the harnesses pass `--no-ff`.
#
# EXIT CODES (distinct so a caller — and the test harness — can tell the shapes
# apart; every one of them means "no sha was printed"):
#   2  no PR head sha argument
#   3  HEAD could not be read as a commit
#   4  HEAD is not a two-parent merge commit
#   5  HEAD's second parent is not the PR head sha
set -euo pipefail

expected_head="${1:-}"
if [ -z "$expected_head" ]; then
    echo "::error::bin/pr-base-snapshot.sh needs the PR head sha (github.event.pull_request.head.sha) as its one argument." >&2
    echo "Without it the merge at HEAD cannot be shown to be a merge OF THIS PR, and an unverified pairing is what card#8527 removed." >&2
    exit 2
fi

if ! parents="$(git rev-list --parents -n 1 HEAD 2>&1)"; then
    echo "::error::bin/pr-base-snapshot.sh could not read HEAD as a commit: ${parents}" >&2
    exit 3
fi

# `rev-list --parents -n 1` prints "<commit> <parent>..." — a merge is 3 fields.
read -r -a fields <<<"$parents"
if [ "${#fields[@]}" -ne 3 ]; then
    echo "::error::HEAD (${fields[0]:-?}) is not a two-parent merge commit, so this run is not on the merge GitHub built for a pull_request event." >&2
    echo "This step reads its base snapshot from HEAD^1 and has no other source: github.event.pull_request.base.sha is the field card#8527 removed for lagging, and a merge-base would re-derive the FORK POINT and refuse innocent PRs." >&2
    echo "Fix: run this step on a pull_request event with the default actions/checkout ref (the refs/pull/N/merge commit), or re-run the job so GitHub recomputes that merge." >&2
    exit 4
fi

base_parent="${fields[1]}"
head_parent="${fields[2]}"

if [ "$head_parent" != "$expected_head" ]; then
    echo "::error::HEAD's second parent (${head_parent}) is not this PR's head sha (${expected_head}), so the checked-out merge is not a merge of this PR's current head." >&2
    echo "GitHub recomputes refs/pull/N/merge per event; a mismatch means this job is running against a stale or foreign merge, and pairing it with any base would report a verdict about a tree nobody pushed." >&2
    echo "Fix: re-run the job (a re-run recomputes the merge), or push again if the PR has merge conflicts — GitHub cannot build the merge commit while it does." >&2
    exit 5
fi

printf '%s\n' "$base_parent"
