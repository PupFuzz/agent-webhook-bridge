#!/usr/bin/env bash
# card-text-parity.sh — run the bridge's published card-text corpus against the TOOLKIT AUTHORITY.
#
# THE FAR-END HALF OF THE CONTRACT FOR App\Bridge\Support\CardTextCheck (card#11987). That class reads a
# VERBATIM copy of agent-board-toolkit's bin/_card-text-rules.json (titles T1-T6, the description's
# filing bar and D1) through the bridge's own PHP port of kbcard's matching; the bridge's suite holds
# it to docs/card-text-parity-corpus.json on every run (tests/Unit/Support/CardTextParityTest.php).
# THIS program holds the AUTHORITY to the same file: every vector goes through the named checkout's
# own `kbcard title-check` / `kbcard description-check`, never a re-implementation — a local copy of
# the rule under test would agree with the port by construction and measure nothing.
#
# It also checks the vendored COPY: resources/card-text-rules.json must be byte-identical to the
# toolkit's bin/_card-text-rules.json at the pinned SOURCE_COMMIT, and to the checkout's working file
# (a toolkit that has moved its rules since the pin is DRIFT: re-vendor and re-measure).
#
# It is READ-ONLY: it never writes an expectation back into the corpus.
#
# EXIT CODES — three, because "could not ask" is not "asked and agreed":
#   0  every vector, known divergence and pinned constant AGREED.
#   1  DRIFTED: at least one disagreed.
#   2  COULD NOT MEASURE: no kbcard / rule file / git history at the checkout, no jq, an unreadable
#      corpus, an unknown method, or a vector whose verb or tail is not the one kbcard's dry run uses.
#
# USAGE
#   bin/card-text-parity.sh --corpus docs/card-text-parity-corpus.json --toolkit <toolkit git checkout>
#   … --control    perturb every expectation and pinned constant; exit 0 only when EVERY one is
#                  reported as a disagreement (the falsifier: a checker that cannot fail is a decoration).
set -uo pipefail

corpus="" toolkit="" control=0
while [[ $# -gt 0 ]]; do
    case "$1" in
        --corpus) corpus="${2:-}"; shift 2 ;;
        --toolkit) toolkit="${2:-}"; shift 2 ;;
        --control) control=1; shift ;;
        *) printf 'card-text-parity: unknown argument %q\n' "$1" >&2; exit 2 ;;
    esac
done

command -v jq >/dev/null || { echo "card-text-parity: COULD NOT MEASURE — jq is not on PATH (kbcard needs it too)" >&2; exit 2; }
if [[ -z "$corpus" || ! -r "$corpus" ]] || ! jq -e 'type == "object" and (.vectors | type) == "object" and (.constants | type) == "object" and (.known_divergences | type) == "object"' "$corpus" >/dev/null 2>&1; then
    echo "card-text-parity: COULD NOT MEASURE — no readable corpus with vectors, constants and known_divergences at '${corpus}'" >&2; exit 2
fi
kbcard="$toolkit/bin/kbcard"
rules="$toolkit/bin/_card-text-rules.json"
if [[ -z "$toolkit" || ! -r "$kbcard" || ! -r "$rules" ]]; then
    echo "card-text-parity: COULD NOT MEASURE — no readable bin/kbcard and bin/_card-text-rules.json under '${toolkit}'" >&2; exit 2
fi
# The bridge checkout the corpus belongs to: the vendored copy is read from there, not from the toolkit.
bridge="$(cd "$(dirname "$0")/.." && pwd)"
vendored="$bridge/resources/card-text-rules.json"
echo "executed: $(sha256sum "$kbcard" | cut -d' ' -f1) $kbcard"
echo "executed: $(sha256sum "$rules" | cut -d' ' -f1) $rules"

tmp="$(mktemp -d)"; trap 'rm -rf "$tmp"' EXIT
# A sandbox HOME holding exactly what the two dry-run verbs need: a host env naming an api and its
# expected host, and a board env with a board id and NEITHER opt-out key. `env -i` keeps an operator's
# own exported board keys (KB_TITLE_BAR, KB_MINT_BAR, KBCARD_API …) out of the measurement.
printf 'export KBCARD_API=https://kanban.parity.invalid/api/v3\nexport KANBAN_EXPECTED_HOST=kanban.parity.invalid\n' > "$tmp/.kanban-host.env"
printf 'export KB_BOARD_ID=1\n' > "$tmp/.kanban-dev-board.env"

agree=0 disagree=0 unmeasured=0

# The authority's answer for one vector, as JSON: null when kbcard passes the text (rc 0), else its
# stderr less the `kbcard: ` prefix and the final newline — the shape the port returns. A vector whose
# verb or tail is not kbcard's own dry-run spelling cannot be asked, and says so.
authority() {  # $1 = method, $2 = the vector's args JSON [text, verb, tail]
    local method="$1" args="$2" verb flag tail text err rc
    case "$method" in
        title) verb=title-check; flag=--name; tail='this was a dry run; no request is ever sent.' ;;
        description) verb=description-check; flag=--description; tail='This was a dry run; no request is ever sent.' ;;
        *) jq -cn --arg w "unknown method $method" '{unclassified: $w}'; return ;;
    esac
    if [[ "$(jq -r '.[1]' <<<"$args")" != "$verb" || "$(jq -r '.[2]' <<<"$args")" != "$tail" ]]; then
        jq -cn --arg w "the vector's verb/tail is not kbcard's dry run ('$verb', '$tail')" '{unclassified: $w}'; return
    fi
    # The x sentinel keeps a trailing newline in the text, which `$(…)` would otherwise strip.
    text="$(jq -j '.[0]' <<<"$args"; printf x)"; text="${text%x}"
    err="$(cd "$tmp" && env -i HOME="$tmp" PATH="$PATH" bash "$kbcard" "$verb" "$flag" "$text" 2>&1 >/dev/null)"; rc=$?
    case "$rc" in
        0) echo null ;;
        2) jq -cn --arg e "$err" '$e | ltrimstr("kbcard: ")' ;;
        *) jq -cn --arg w "kbcard exited $rc: $err" '{unclassified: $w}' ;;
    esac
}

perturb() {  # $1 = an expectation JSON → a value the authority must NOT answer
    jq -c 'if . == null then "perturbed" else . + "\u0000perturbed" end' <<<"$1"
}

compare() {  # $1 = label, $2 = what the corpus says, $3 = what the authority answered
    if jq -e 'type == "object" and has("unclassified")' <<<"$3" >/dev/null 2>&1; then
        echo "UNMEASURED $1: $(jq -r '.unclassified' <<<"$3")"; unmeasured=$((unmeasured + 1))
    elif jq -e --argjson a "$3" --argjson b "$2" -n '$a == $b' >/dev/null 2>&1; then
        agree=$((agree + 1))
    else
        echo "DISAGREE $1: the corpus says $2, the authority answered $3"; disagree=$((disagree + 1))
    fi
}

while IFS= read -r row; do
    method="$(jq -r '.m' <<<"$row")"; i="$(jq -r '.i' <<<"$row")"
    expect="$(jq -c '.v.expect' <<<"$row")"
    [[ $control -eq 1 ]] && expect="$(perturb "$expect")"
    compare "$method#$i" "$expect" "$(authority "$method" "$(jq -c '.v.args' <<<"$row")")"
done < <(jq -c '.vectors | to_entries[] | .key as $m | .value | to_entries[] | {m: $m, i: .key, v: .value}' "$corpus")

# Known divergences: the AUTHORITY half of each pinned pair must still hold; a pair whose two values
# have converged is stale and is reported, so the entry gets deleted rather than re-worded.
while IFS= read -r row; do
    method="$(jq -r '.m' <<<"$row")"; i="$(jq -r '.i' <<<"$row")"
    want="$(jq -c '.v.authority' <<<"$row")"
    [[ $control -eq 1 ]] && want="$(perturb "$want")"
    got="$(authority "$method" "$(jq -c '.v.args' <<<"$row")")"
    compare "divergence $method#$i" "$want" "$got"
    if jq -e --argjson a "$got" --argjson m "$(jq -c '.v.mirror' <<<"$row")" -n '$a == $m' >/dev/null 2>&1; then
        echo "DISAGREE divergence $method#$i: the authority now answers what the port answers — the divergence closed; delete the entry and publish an ordinary vector"
        disagree=$((disagree + 1))
    fi
done < <(jq -c '.known_divergences | to_entries[] | .key as $m | .value | to_entries[] | {m: $m, i: .key, v: .value}' "$corpus")

# The pinned constants, each checked against what it claims about the toolkit and the vendored copy.
check_constant() {  # $1 = name, $2 = the value the corpus pins, $3 = the value measured
    local want="$2"
    [[ $control -eq 1 ]] && want="${want}-perturbed"
    if [[ "$3" == "$want" ]]; then agree=$((agree + 1)); else echo "DISAGREE constant $1: the corpus pins '$want', measured '$3'"; disagree=$((disagree + 1)); fi
}
commit="$(jq -r '.constants.SOURCE_COMMIT // empty' "$corpus")"
if [[ -z "$commit" ]] || ! git -C "$toolkit" cat-file -e "$commit:bin/_card-text-rules.json" 2>/dev/null; then
    echo "UNMEASURED constants SOURCE_COMMIT/SOURCE_SHA256: the checkout has no bin/_card-text-rules.json at '${commit}' (a shallow clone or an export carries no history — fetch it)"; unmeasured=$((unmeasured + 1))
else
    pinned_sha="$(git -C "$toolkit" show "$commit:bin/_card-text-rules.json" | sha256sum | cut -d' ' -f1)"
    vendored_sha="$(sha256sum < "$vendored" 2>/dev/null | cut -d' ' -f1)"
    checkout_sha="$(sha256sum < "$rules" | cut -d' ' -f1)"
    check_constant SOURCE_SHA256 "$(jq -r '.constants.SOURCE_SHA256' "$corpus")" "$pinned_sha"
    # SOURCE_COMMIT holds when the vendored bytes ARE the bytes at that commit and the checkout's
    # working file has not moved since — a toolkit that changed its rules is drift to re-vendor.
    if [[ "$vendored_sha" == "$pinned_sha" && "$checkout_sha" == "$pinned_sha" ]]; then measured="$commit"
    else measured="a rule file other than the one at $commit (pinned $pinned_sha, vendored ${vendored_sha:-unreadable}, checkout $checkout_sha)"; fi
    check_constant SOURCE_COMMIT "$commit" "$measured"
fi
# Every constant the corpus pins must be one this runner checked, or the check is narrower than it reads.
for k in $(jq -r '.constants | keys[]' "$corpus"); do
    case "$k" in SOURCE_COMMIT|SOURCE_SHA256) ;;
        *) echo "UNMEASURED constant $k: this runner does not know how to check it"; unmeasured=$((unmeasured + 1)) ;; esac
done

echo "agreed $agree · disagreed $disagree · unmeasured $unmeasured"
if [[ $unmeasured -gt 0 ]]; then exit 2; fi
if [[ $control -eq 1 ]]; then
    # Under --control every expectation was perturbed, so every one must have disagreed.
    if [[ $agree -eq 0 && $disagree -gt 0 ]]; then echo "control: every perturbed expectation was reported"; exit 0; fi
    echo "control: $agree perturbed expectation(s) were NOT reported — this checker cannot fail on them"; exit 1
fi
[[ $disagree -eq 0 ]] && exit 0 || exit 1
