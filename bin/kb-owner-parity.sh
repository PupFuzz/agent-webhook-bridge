#!/usr/bin/env bash
# kb-owner-parity.sh — run a published bridge parity corpus against the TOOLKIT AUTHORITY.
#
# THE FAR-END HALF OF THE CONTRACT FOR THE BRIDGE'S TWO PORTS OF THE TOOLKIT'S OWNER RULES
# (card#10869). `App\Bridge\Support\KanbanInstanceKey` ports `kb_url_host`, and
# `App\Bridge\Support\RosterKanbanUser` ports the roster read inside `kb_owner_resolve`; both live
# in agent-board-toolkit's bin/_kb-board-lib.sh, which the bridge cannot import. The bridge's own
# suite holds each port against its published corpus (tests/Unit/Support/*ParityTest.php); THIS
# program holds the AUTHORITY to the same file, by sourcing the named checkout's lib and calling
# its own functions — never a re-implementation, because a local copy of the rule under test
# would agree with the port by construction and measure nothing.
#
# It is READ-ONLY: it never writes an expectation back into a corpus.
#
# EXIT CODES — three, because "could not ask" is not "asked and agreed":
#   0  every vector and every pinned constant AGREED.
#   1  DRIFTED: at least one vector or constant disagreed.
#   2  COULD NOT MEASURE: no readable lib at the checkout, an unreadable corpus, an unknown method,
#      or an authority answer this runner cannot classify. Nothing is claimed.
#
# USAGE
#   bin/kb-owner-parity.sh --corpus docs/kb-instance-key-parity-corpus.json --toolkit <checkout>
#   bin/kb-owner-parity.sh --corpus docs/kb-roster-uid-parity-corpus.json --toolkit <checkout>
#   … --control    perturb every expectation and pinned constant; exit 0 only when EVERY one is
#                  reported as a disagreement (the falsifier: a checker that cannot fail is a
#                  decoration).
set -uo pipefail

corpus="" toolkit="" control=0
while [[ $# -gt 0 ]]; do
    case "$1" in
        --corpus) corpus="${2:-}"; shift 2 ;;
        --toolkit) toolkit="${2:-}"; shift 2 ;;
        --control) control=1; shift ;;
        *) printf 'kb-owner-parity: unknown argument %q\n' "$1" >&2; exit 2 ;;
    esac
done

lib="$toolkit/bin/_kb-board-lib.sh"
if [[ -z "$corpus" || ! -r "$corpus" ]] || ! jq -e 'type == "object" and (.vectors | type) == "object"' "$corpus" >/dev/null 2>&1; then
    echo "kb-owner-parity: COULD NOT MEASURE — no readable corpus with a vectors object at '${corpus}'" >&2; exit 2
fi
if [[ -z "$toolkit" || ! -r "$lib" ]]; then
    echo "kb-owner-parity: COULD NOT MEASURE — no readable toolkit lib at '${lib}'" >&2; exit 2
fi

# shellcheck source=/dev/null
source "$lib" || { echo "kb-owner-parity: COULD NOT MEASURE — sourcing $lib failed" >&2; exit 2; }
for fn in kb_url_host kb_owner_resolve; do
    declare -F "$fn" >/dev/null || { echo "kb-owner-parity: COULD NOT MEASURE — $lib defines no $fn" >&2; exit 2; }
done
echo "executed: $(sha256sum "$lib" | cut -d' ' -f1) $lib"

tmp="$(mktemp -d)"; trap 'rm -rf "$tmp"' EXIT
agree=0 disagree=0 unmeasured=0

# The authority's verdict for one roster vector, as {"uid":N|null,"why":S|null} — read off the
# toolkit's own kb_owner_resolve. Its WHY is prose; each verdict is classified by the fragment the
# function writes for it, and anything else is UNCLASSIFIED (exit 2), never a guess.
roster_verdict() {
    local cfg="$1" seat="$2" host="$3"
    printf '%s' "$cfg" > "$tmp/coord.json"
    if COORD_CONFIG="$tmp/coord.json" COORD_AGENT="$seat" KB_API="https://$host/api/v3" kb_owner_resolve; then
        jq -cn --arg u "$KB_OWNER_USER_ID" '{uid: ($u | tonumber? // $u), why: null}'
        return
    fi
    local why="$KB_OWNER_WHY" v
    case "$why" in
        *"is not a roster[].name"*) v=absent ;;
        *'has no `kanban_user_id` object'*) v=nofield ;;
        *'has no `kanban_user_id` entry for this board instance'*) v=nohost ;;
        *"not a positive integer kanban user id"*) v=bad ;;
        *) jq -cn --arg w "$why" '{unclassified: $w}'; return ;;
    esac
    jq -cn --arg v "$v" '{uid: null, why: $v}'
}

# The coord config TEXT a roster vector hands the authority. A vector may carry it as a STRING —
# the file's exact bytes — because a number literal like `7.00` or `7e0` has no other faithful
# spelling: jq re-serialising an object normalises it (`7e0` prints `7`), which would feed the
# authority a different file from the one the vector describes. An object is serialised as before.
config_text() {
    jq -r 'if (.v.args[0] | type) == "string" then .v.args[0] else (.v.args[0] | tojson) end' <<<"$1"
}

perturb() {  # $1 = method, $2 = expect JSON → a value the authority must NOT answer
    case "$1" in
        of) jq -c '. + "\u0000perturbed"' <<<"$2" ;;
        lookUp) jq -c 'if .uid == null then {uid: 424242, why: null} else {uid: null, why: "absent"} end' <<<"$2" ;;
    esac
}

while IFS= read -r row; do
    method="$(jq -r '.m' <<<"$row")"; i="$(jq -r '.i' <<<"$row")"
    expect="$(jq -c '.v.expect' <<<"$row")"
    [[ $control -eq 1 ]] && expect="$(perturb "$method" "$expect")"
    case "$method" in
        of)
            got="$(jq -cn --arg h "$(kb_url_host "$(jq -r '.v.args[0]' <<<"$row")")" '$h')" ;;
        lookUp)
            got="$(roster_verdict "$(config_text "$row")" "$(jq -r '.v.args[1]' <<<"$row")" "$(jq -r '.v.args[2]' <<<"$row")")" ;;
        *)
            echo "UNMEASURED $method#$i: this runner does not know method '$method'"; unmeasured=$((unmeasured + 1)); continue ;;
    esac
    if jq -e 'type == "object" and has("unclassified")' <<<"$got" >/dev/null 2>&1; then
        echo "UNMEASURED $method#$i: the authority answered a reason this runner cannot classify: $(jq -r '.unclassified' <<<"$got")"
        unmeasured=$((unmeasured + 1))
    elif jq -e --argjson a "$got" --argjson b "$expect" -n '$a == $b' >/dev/null 2>&1; then
        agree=$((agree + 1))
    else
        echo "DISAGREE $method#$i: args=$(jq -c '.v.args' <<<"$row") expected $expect, authority answered $got"
        disagree=$((disagree + 1))
    fi
done < <(jq -c '.vectors | to_entries[] | .key as $m | .value | to_entries[] | {m: $m, i: .key, v: .value}' "$corpus")

# Known divergences: the AUTHORITY half of each pinned pair must still hold; a pair whose two
# values have converged is stale and is reported, so the entry gets deleted rather than re-worded.
while IFS= read -r row; do
    method="$(jq -r '.m' <<<"$row")"; i="$(jq -r '.i' <<<"$row")"
    want="$(jq -c '.v.authority' <<<"$row")"
    [[ $control -eq 1 ]] && want="$(perturb "$method" "$want")"
    case "$method" in
        of) got="$(jq -cn --arg h "$(kb_url_host "$(jq -r '.v.args[0]' <<<"$row")")" '$h')" ;;
        lookUp) got="$(roster_verdict "$(config_text "$row")" "$(jq -r '.v.args[1]' <<<"$row")" "$(jq -r '.v.args[2]' <<<"$row")")" ;;
        *) echo "UNMEASURED divergence $method#$i"; unmeasured=$((unmeasured + 1)); continue ;;
    esac
    if jq -e --argjson a "$got" --argjson b "$want" -n '$a == $b' >/dev/null 2>&1; then
        agree=$((agree + 1))
    else
        echo "DISAGREE divergence $method#$i: the corpus says the authority answers $want, it answered $got"
        disagree=$((disagree + 1))
    fi
done < <(jq -c '.known_divergences | to_entries[] | .key as $m | .value | to_entries[] | {m: $m, i: .key, v: .value}' "$corpus")

# Pinned constants: the roster verdict NAMES. Each must be a verdict the authority's own code
# emits — `absent` from kb_owner_resolve's join, the other three from KB_JQ_ROSTER.
while IFS= read -r row; do
    name="$(jq -r '.key' <<<"$row")"; value="$(jq -r '.value' <<<"$row")"
    [[ $control -eq 1 ]] && value="${value}-perturbed"
    if [[ "$KB_JQ_ROSTER" == *"why: \"$value\""* ]] || declare -f kb_owner_resolve | grep -qF "why: \"$value\""; then
        agree=$((agree + 1))
    else
        echo "DISAGREE constant $name: the authority emits no verdict \"$value\""
        disagree=$((disagree + 1))
    fi
done < <(jq -c '.constants | to_entries[]' "$corpus")

echo "agreed $agree · disagreed $disagree · unmeasured $unmeasured"
if [[ $unmeasured -gt 0 ]]; then exit 2; fi
if [[ $control -eq 1 ]]; then
    # Under --control every row was perturbed, so every row must have disagreed.
    if [[ $agree -eq 0 && $disagree -gt 0 ]]; then echo "control: every perturbed expectation was reported"; exit 0; fi
    echo "control: $agree perturbed expectation(s) were NOT reported — this checker cannot fail on them"; exit 1
fi
[[ $disagree -eq 0 ]] && exit 0 || exit 1
