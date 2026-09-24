#!/usr/bin/env bash
#
# Re-derives the population of sites in this repo that DECIDE whether a workflow STAGE is
# terminal on a board (card#10274 / DL-418). Run it; do not trust a count written anywhere.
#
#   bin/derive-terminal-sites.sh [repo-root]        # the population
#   bin/derive-terminal-sites.sh --control          # prove the predicate discriminates
#
# ⭐ THE PREDICATE IS ON THE SUBJECT, NOT ON A KNOWN IMPLEMENTATION TOKEN. A sweep built from
# the copies you already found can only re-find them: card#10274 named ONE bridge site,
# `KanbanClient::boardStructure()`'s `lane_type === 'done'`, and a `grep lane_type` would have
# returned exactly that site and reported the population complete. Pass 1 casts a wide net over
# terminality VOCABULARY; pass 2 keeps the lines whose subject is a stage or column. A site that
# spells the decision in words the seed never used is still reached.
#
# ⛔ `command grep`, NOT a bare `grep`: an interactive shell here may alias `grep` to a wrapper
# that honours `.gitignore`, which would silently skip untracked files. Run over the whole tree
# rather than `git grep` for the same reason — an untracked decider is still a decider.
#
# ⚠ THIS PRODUCES CANDIDATES, NOT A VERDICT. The output is read and classified by hand: most hits
# are prose, and three of the four deciders it finds are deliberately NOT derivations of the
# board's declaration (DL-418 names them and why). What the script owns is the DENOMINATOR.
set -euo pipefail

vocabulary() {
    command grep -rIn --include='*.php' --exclude-dir=vendor --exclude-dir=node_modules \
        -iE 'terminal|is_done|is_terminal|lane_type|finished|wont.?do|concluded' app bin
}

population() {
    vocabulary | command grep -iE 'stage|column|lane_type'
}

control=0
if [ "${1:-}" = '--control' ]; then
    control=1
    shift
fi
root="${1:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
cd "$root"

if [ "$control" = 1 ]; then
    # ⭐ WHAT THE CONTROL HAS TO PROVE: that this predicate reaches a member the SEED SET DOES NOT
    # CONTAIN. The seed is `KanbanClient` alone. `WritebackMapping::isTerminalStage()` (position
    # arithmetic) and `CoordConfigTerminals` (column NAMES out of the coordination config) are two
    # other answers to the same question that share no token with the seed, so a run that reaches
    # them would have reached a third spelled differently again. A control that only re-found the
    # seed would prove nothing, which is why the seed is not in this list.
    out=$(population)
    rc=0
    for must in WritebackMapping.php CoordConfigTerminals.php; do
        # A HERESTRING, NOT A PIPE: `grep -q` exits at the first match, and under `pipefail`
        # the SIGPIPE that kills the writer makes the whole pipeline rc 141 — so a control
        # asserting a MATCH would report "not found" for exactly the members that match early
        # enough to win the race. Measured here: this leg reported FAIL on a member 19 lines of
        # the output name.
        if command grep -q "^app/Bridge/Writeback/${must}:" <<<"$out"; then
            echo "control OK   — reached ${must}, which the seed set does not contain"
        else
            echo "control FAIL — did NOT reach ${must}: the predicate has narrowed onto the seed"
            rc=1
        fi
    done
    # The other direction: a predicate that matches everything discriminates nothing.
    total=$(command grep -rIl --include='*.php' --exclude-dir=vendor --exclude-dir=node_modules '' app bin | wc -l)
    hit=$(cut -d: -f1 <<<"$out" | sort -u | wc -l)
    echo "control      — ${hit} of ${total} php files under app/ + bin/ carry a candidate line"
    [ "$hit" -lt "$total" ] || { echo "control FAIL — every file matches; the predicate selects nothing"; rc=1; }
    exit "$rc"
fi

population
