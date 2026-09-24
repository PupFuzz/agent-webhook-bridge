#!/usr/bin/env python3
"""coord-mirror-parity.py — run a published bridge parity corpus against the COORD AUTHORITY.

THE HALF OF THE CONTRACT THIS END CANNOT OTHERWISE HOLD (card#10273).

`app/Bridge/Writeback/CoordConfigTerminals.php` and `app/Bridge/Writeback/CoordLaneStages.php`
are deliberate PHP mirrors of rules whose home is Python — the coordination framework's
`kanban_common.terminals_for_board`, `kanban-inbox-check._terminal_columns_by_board`, and
`kanban-issues-sync`'s `_STAGE_LANE` / `_task_lane` / `classify_coord`. The bridge is a PHP
runtime and cannot import any of them, which is why the copies exist at all.

The bridge's own suite (`tests/Support/MirrorParityTestCase.php`) holds each mirror against its
published corpus, both ways. That proves the MIRROR still answers what the corpus says — it
proves nothing about whether the AUTHORITY still answers the same thing. This program is the
other half: it executes the authority's own callables over the same published vectors and reports
whether the two ends still agree.

⛔ IT COMPARES BEHAVIOUR, NEVER TEXT. The two ends are different languages, so a textual diff of
the two implementations is not weak evidence, it is undefined. What is compared is the ANSWER to
an argument vector — and where the two ends return different SHAPES for one answer (a PHP ordered
list against a Python frozenset; a PHP `{lane, unmapped}` pair against a Python lane name), the
adapter below states the projection explicitly rather than letting a shape mismatch read as a
behavioural one.

⛔ IT IS READ-ONLY. It never writes an expectation back into a corpus. A "--derive" mode that
filled `expect` from the authority would launder a genuine drift into the published file and make
every later run agree with it.

EXIT CODES — three, because "could not ask" is not "asked and agreed":
  0  every vector and every constant AGREED.
  1  at least one DISAGREEMENT — the mirror and the authority have drifted.
  2  COULD NOT MEASURE — the authority was not importable at the path given. Nothing is claimed.

USAGE
  bin/coord-mirror-parity.py --corpus docs/coord-lane-parity-corpus.json
  bin/coord-mirror-parity.py --corpus <file> --coord-examples <dir>   # authority checkout/cache
  bin/coord-mirror-parity.py --corpus <file> --control                # see it FAIL on purpose

--control is the falsifier (canon #9): it perturbs every published expectation and requires the
run to report a disagreement for each one. A checker that cannot be shown to fail is a decoration,
and this one's whole value is its red.
"""
from __future__ import annotations

import argparse
import copy
import importlib.util
import json
import os
import sys
import tempfile
from pathlib import Path

# The authority's example scripts, in the framework's own layout. A path given on the command
# line wins; then $COORD_EXAMPLES; then the newest plugin cache on this box.
_CACHE_GLOB = ".claude/plugins/cache/agent-board-framework/coord"
_EXAMPLES_REL = "templates/kanban/examples"

_MISSING = object()


def _version_key(name: str) -> tuple:
    parts = []
    for chunk in name.split("."):
        parts.append(int(chunk) if chunk.isdigit() else -1)
    return tuple(parts)


def default_examples_dir() -> Path | None:
    env = os.environ.get("COORD_EXAMPLES")
    if env:
        return Path(env)
    cache = Path.home() / _CACHE_GLOB
    if not cache.is_dir():
        return None
    versions = sorted((d for d in cache.iterdir() if d.is_dir()), key=lambda d: _version_key(d.name))
    for d in reversed(versions):
        if (d / _EXAMPLES_REL).is_dir():
            return d / _EXAMPLES_REL
    return None


class Authority:
    """The coord authority's own callables, imported from source and never re-implemented here.

    ⛔ THE POINT OF IMPORTING RATHER THAN RESTATING. The obvious shortcut for
    `_terminal_columns_by_board` is to loop `config["kanban"]["boards"]` here and call
    `terminals_for_board` per entry. That loop IS the rule under test — the board-id join, the
    placeholder skip, the union of two entries sharing one id — so re-writing it here would make
    this program agree with the mirror by construction and measure nothing. It is driven instead
    through the authority's own function, with $COORD_CONFIG pointed at the vector's config.
    """

    def __init__(self, examples: Path):
        self.examples = examples
        self.common = self._load("kanban_common", "kanban_common.py")
        self.inbox = self._load("coord_parity_inbox_check", "kanban-inbox-check.py")
        self.issues = self._load("coord_parity_issues_sync", "kanban-issues-sync.py")

    def _load(self, name: str, filename: str):
        path = self.examples / filename
        spec = importlib.util.spec_from_file_location(name, path)
        if spec is None or spec.loader is None:
            raise ImportError(f"no module spec for {path}")
        module = importlib.util.module_from_spec(spec)
        sys.modules[name] = module
        spec.loader.exec_module(module)
        return module

    def terminals_for_board(self, board_cfg: dict) -> frozenset:
        return self.common.terminals_for_board(board_cfg)

    def terminals_by_board(self, config: dict, board_id: int) -> frozenset:
        """`_terminal_columns_by_board()` reads $COORD_CONFIG itself, so the vector's config is
        written to a real file and the variable pointed at it for the duration of the call."""
        with tempfile.NamedTemporaryFile("w", suffix=".json", delete=False) as handle:
            json.dump(config, handle)
            temp = handle.name
        previous = os.environ.get("COORD_CONFIG")
        os.environ["COORD_CONFIG"] = temp
        try:
            return self.inbox._terminal_columns_by_board().get(board_id, frozenset())
        finally:
            if previous is None:
                os.environ.pop("COORD_CONFIG", None)
            else:
                os.environ["COORD_CONFIG"] = previous
            os.unlink(temp)

    def columns_for(self, lanes) -> dict:
        """The authority's `columns` argument for a board mapping exactly `lanes`.

        The mirror's `mappedLanes` are lane KEYS (`now`); the authority tests
        `LANE_TO_COLUMN[lane] in columns`, whose keys are stage external ids (`stage-now`). The
        translation is read off the authority's own `LANE_TO_COLUMN`, never spelled here.
        """
        lane_to_column = self.issues.LANE_TO_COLUMN
        by_key = {name.lower(): column for name, column in lane_to_column.items()}
        return {by_key[str(lane).lower()]: index for index, lane in enumerate(lanes)}

    def task_lane(self, labels, mapped_lanes) -> str:
        issue = {"labels": [{"name": label} for label in labels]}
        return self.issues._task_lane(issue, self.columns_for(mapped_lanes))

    def lane_model_governs(self, title: str) -> bool:
        """Is the authority's lane model the thing that placed this issue?

        `classify_coord`'s `[TASK]` gate is not a callable of its own, so it is observed through
        the column the authority actually chooses. With NO `stage:*` label, the lane branch lands
        on `Later` and every other open branch lands on `Now` or `Awaiting ACK` — a clean
        discriminator that reads the real gate rather than a copy of its condition.
        """
        columns = self.columns_for(["now", "next", "later", "maybe"])
        columns[self.issues.LANE_TO_COLUMN["Awaiting ACK"]] = -1
        columns[self.issues.LANE_TO_COLUMN["Done"]] = -2
        chosen = self.issues.classify_coord(({"title": title, "labels": []}, "open"), columns)
        return chosen == columns[self.issues.LANE_TO_COLUMN["Later"]]

    def is_lane_label(self, label: str) -> bool:
        return str(label).lower() in self.issues._STAGE_LANE


# ── adapters: one published vector → the authority's answer, and the mirror's expectation
#    projected onto the same observable ───────────────────────────────────────────────────────
#
# Each adapter returns (authority_answer, mirror_expectation_projected, observable_description).
# The PROJECTION is where a shape difference is declared rather than mistaken for a drift.

def _terminals_for_board(a: Authority, args, expect):
    return set(a.terminals_for_board(args[0])), set(expect), "the SET of terminal column strings (the mirror returns them ordered; the authority returns a frozenset — order is not part of this rule)"


def _terminal_names_for_board_id(a: Authority, args, expect):
    return set(a.terminals_by_board(args[0], args[1])), set(expect), "the SET of terminal column strings resolved for one board id"


def _governs(a: Authority, args, expect):
    return a.lane_model_governs(args[0]), bool(expect), "whether the lane model governs this issue at all"


def _is_lane_label(a: Authority, args, expect):
    return a.is_lane_label(args[0]), bool(expect), "whether this label states a lane of the model"


def _resolve_lane(a: Authority, args, expect, default_lane: str):
    answer = a.task_lane(args[0], args[1]).lower()
    # The mirror answers `{lane, unmapped}`: `lane` is null where nothing MAPPED was declared and
    # the caller then uses DEFAULT_LANE, which is the lane the authority returns directly.
    # `unmapped` is the mirror's own warn material and has no far-end counterpart (it is named in
    # the corpus's `not_checked_by_this_repo`).
    projected = (expect.get("lane") or default_lane).lower()
    return answer, projected, "the LANE the issue resolves to, with the mirror's null folded onto its DEFAULT_LANE"


ADAPTERS = {
    "App\\Bridge\\Writeback\\CoordConfigTerminals": {
        "terminalsForBoard": _terminals_for_board,
        "terminalNamesForBoardId": _terminal_names_for_board_id,
    },
    "App\\Bridge\\Writeback\\CoordLaneStages": {
        "governs": _governs,
        "isLaneLabel": _is_lane_label,
        "resolveLane": _resolve_lane,
    },
}


def check_constants(a: Authority, corpus: dict) -> list[str]:
    """Hold the mirror's pinned constants against the authority's OWN data structures.

    These are not vectors — they are the parameters the mirrored rules are built out of, and a
    corpus that pinned them only against the mirror would pin the mirror to itself.
    """
    mirror_class = corpus["mirror"]["class"]
    constants = corpus["constants"]
    problems: list[str] = []

    def compare(name, authority_value, note):
        declared = constants.get(name, _MISSING)
        if declared is _MISSING:
            problems.append(f"constant {name}: the corpus pins no value, so the authority's {authority_value!r} is held against nothing")
        elif declared != authority_value:
            problems.append(f"constant {name}: corpus pins {declared!r}, authority says {authority_value!r} ({note})")

    if mirror_class.endswith("CoordConfigTerminals"):
        import inspect
        default_terminal = inspect.signature(a.common.terminals_for_board).parameters["default_terminal"].default
        compare("DEFAULT_TERMINAL", default_terminal, "terminals_for_board's default_terminal")
        if a.inbox.DONE_COLUMN != default_terminal:
            problems.append(
                f"the authority's own two sites disagree: terminals_for_board defaults to {default_terminal!r} "
                f"while kanban-inbox-check passes DONE_COLUMN={a.inbox.DONE_COLUMN!r}. The mirror carries ONE "
                "constant and can only match one of them."
            )
    elif mirror_class.endswith("CoordLaneStages"):
        stage_lane = a.issues._STAGE_LANE
        compare("LANES", [lane.lower() for lane in stage_lane.values()], "_STAGE_LANE's values, in its insertion order — the order a multi-labelled issue is resolved in")
        compare("DEFAULT_LANE", a.task_lane([], ["now", "next", "later", "maybe"]).lower(), "_task_lane's answer for an issue declaring no stage label")
        prefix = constants.get("LABEL_PREFIX")
        if prefix is not None:
            bad = [key for key, lane in stage_lane.items() if key != f"{prefix}{lane.lower()}"]
            if bad:
                problems.append(f"constant LABEL_PREFIX={prefix!r} does not compose the authority's own label keys: {bad}")
        title_prefix = constants.get("LANE_MODEL_TITLE_PREFIX")
        if title_prefix is not None:
            if not a.lane_model_governs(f"{title_prefix} x"):
                problems.append(f"constant LANE_MODEL_TITLE_PREFIX={title_prefix!r}: the authority does NOT lane-derive a title carrying it")
            if a.lane_model_governs("x"):
                problems.append("the authority lane-derives a title carrying no prefix at all, so LANE_MODEL_TITLE_PREFIX gates nothing")
    else:
        problems.append(f"no constant adapter for {mirror_class} — this program does not know how to hold its constants against the authority")

    return problems


def _render(value):
    """One answer, printed. A set is sorted by its STRING form rather than natively: a corpus
    carrying a divergence over a MIXED-TYPE set (the authority keeps an integer where the mirror
    drops it) makes `sorted()` raise, and a reporter that crashes on the very case it exists to
    report is worse than one that says nothing."""
    if isinstance(value, (set, frozenset)):
        return json.dumps(sorted(value, key=repr), default=str)
    return json.dumps(value, default=str)


def _ask(adapter, authority, args, expect, default_lane, method):
    """One comparison, with the authority's RAISE captured as its own outcome.

    ⛔ MEASURED, NOT HYPOTHETICAL: three malformed-config shapes make the coord authority raise
    where the mirror answers (`int()` on a non-numeric `board_id`, and `.get` on a `boards[]`
    member that is not an object — see each corpus's `not_checked_by_this_repo`). Letting that
    escape would kill this program with a traceback and Python's default exit 1, which THIS
    program's own contract reads as `disagreed`. A raise is not a disagreement about an answer;
    it is the absence of one, and it says so in its own words.
    """
    try:
        if method == "resolveLane":
            return adapter(authority, args, expect, default_lane), None
        return adapter(authority, args, expect), None
    except Exception as exc:  # noqa: BLE001 — every failure to ANSWER is the same outcome here
        return None, f"{type(exc).__name__}: {exc}"


def perturb(value):
    """One value, changed. The --control's whole job: make each published expectation WRONG and
    require the run to notice. It must move the VALUE, never merely its spelling."""
    if isinstance(value, bool):
        return not value
    if isinstance(value, list):
        return value[1:] if value else ["coord-mirror-parity-control"]
    if isinstance(value, dict):
        perturbed = copy.deepcopy(value)
        if "lane" in perturbed:
            perturbed["lane"] = "maybe" if perturbed["lane"] != "maybe" else "now"
            return perturbed
        return {"coord-mirror-parity-control": True}
    if isinstance(value, str):
        return value + "-coord-mirror-parity-control"
    return "coord-mirror-parity-control"


def run(corpus: dict, authority: Authority, control: bool) -> tuple[list[str], list[str], str]:
    mirror_class = corpus["mirror"]["class"]
    adapters = ADAPTERS.get(mirror_class)
    if adapters is None:
        return ([f"no adapter table for {mirror_class}"], [], "0 vectors over 0 methods")

    default_lane = corpus["constants"].get("DEFAULT_LANE", "later")
    disagreements: list[str] = []
    missed: list[str] = []
    per_method: list[str] = []
    total = 0

    for method, cases in corpus["vectors"].items():
        adapter = adapters.get(method)
        per_method.append(f"{method} {len(cases)}")
        if adapter is None:
            missed.append(f"{method}: the corpus publishes {len(cases)} vectors and this program has no adapter for it, so they were NOT run")
            continue
        for index, case in enumerate(cases):
            total += 1
            expect = perturb(case["expect"]) if control else case["expect"]
            result, raised = _ask(adapter, authority, case["args"], expect, default_lane, method)
            if raised is not None:
                disagreements.append(
                    f"{method}#{index}: args={json.dumps(case['args'], sort_keys=True)} — the coord authority "
                    f"RAISED instead of answering ({raised}). It has no answer to compare, so this vector "
                    "establishes nothing; either the input is outside the rule's contract (move it to "
                    "`not_checked_by_this_repo`) or the authority has a defect."
                )
                continue
            answer, projected, observable = result
            if answer != projected:
                disagreements.append(
                    f"{method}#{index}: args={json.dumps(case['args'], sort_keys=True)} — "
                    f"the bridge's corpus says {_render(projected)}, "
                    f"the coord authority answers {_render(answer)} "
                    f"[{observable}]"
                )

    # ── the KNOWN DIVERGENCES, held from this side ────────────────────────────────────────────
    #
    # The bridge's own suite pins the MIRROR half of each pair. This pins the AUTHORITY half — and,
    # just as load-bearing, reds when the two have CONVERGED. A divergence that quietly closed
    # leaves a published file telling the far end the two ends disagree somewhere they now agree,
    # which is a false statement about this repo in exactly the direction nobody re-reads.
    divergences = 0
    for method, cases in corpus.get("known_divergences", {}).items():
        adapter = adapters.get(method)
        if adapter is None:
            missed.append(f"{method}: the corpus publishes {len(cases)} known divergences and this program has no adapter for it")
            continue
        for index, case in enumerate(cases):
            divergences += 1
            declared = perturb(case["authority"]) if control else case["authority"]
            result, raised = _ask(adapter, authority, case["args"], declared, default_lane, method)
            if raised is not None:
                disagreements.append(
                    f"known_divergences.{method}#{index}: the coord authority RAISED instead of answering "
                    f"({raised}). A divergence pins a PAIR of answers, and there is no second answer to pin."
                )
                continue
            answer, projected, observable = result
            if answer != projected:
                disagreements.append(
                    f"known_divergences.{method}#{index}: args={json.dumps(case['args'], sort_keys=True)} — "
                    f"the bridge's corpus records the AUTHORITY answering "
                    f"{_render(projected)}, "
                    f"and it answers {_render(answer)} "
                    f"[{observable}]"
                )
            elif not control and case["mirror"] == case["authority"]:
                disagreements.append(
                    f"known_divergences.{method}#{index}: the corpus records the SAME value for both ends, "
                    "so it documents no divergence. Delete the entry and publish an ordinary vector."
                )

    per_method.sort()
    population = (
        f"{total} vectors over {len(corpus['vectors'])} methods: " + ", ".join(per_method)
        + f", plus {divergences} known divergences"
    )
    return disagreements, missed, population


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--corpus", required=True, help="a published parity corpus JSON from this repo's docs/")
    parser.add_argument("--coord-examples", default=None, help="the coord framework's templates/kanban/examples directory")
    parser.add_argument("--control", action="store_true", help="perturb every expectation and require a disagreement for each — the falsifier")
    args = parser.parse_args(argv)

    corpus = json.loads(Path(args.corpus).read_text())

    examples = Path(args.coord_examples) if args.coord_examples else default_examples_dir()
    if examples is None or not examples.is_dir():
        print(
            "COULD NOT MEASURE — the coord authority's examples directory was not found. "
            "Pass --coord-examples <dir> or set $COORD_EXAMPLES. Nothing is claimed about parity.",
            file=sys.stderr,
        )
        return 2
    try:
        authority = Authority(examples)
    except Exception as exc:  # noqa: BLE001 — every import failure is the same verdict: not measured
        print(f"COULD NOT MEASURE — the coord authority at {examples} did not import: {type(exc).__name__}: {exc}", file=sys.stderr)
        return 2

    disagreements, missed, population = run(corpus, authority, args.control)
    constant_problems = [] if args.control else check_constants(authority, corpus)

    print(f"corpus:    {args.corpus}")
    print(f"mirror:    {corpus['mirror']['class']}  ({corpus['mirror']['path']})")
    print(f"authority: {corpus['authority']['symbol']}  ({examples})")
    print(f"ran:       {population}")

    for line in missed:
        print(f"NOT RUN:   {line}")
    for line in disagreements:
        print(f"DISAGREE:  {line}")
    for line in constant_problems:
        print(f"DISAGREE:  {line}")

    if args.control:
        if missed:
            print("CONTROL FAILED — some vectors were not run at all, so the control proves nothing about them.", file=sys.stderr)
            return 1
        expected = sum(len(cases) for cases in corpus["vectors"].values()) + sum(
            len(cases) for cases in corpus.get("known_divergences", {}).values()
        )
        if len(disagreements) != expected:
            print(
                f"CONTROL FAILED — perturbed all {expected} expectations and only {len(disagreements)} were reported. "
                "The comparator cannot see a difference it is being shown.",
                file=sys.stderr,
            )
            return 1
        print(f"CONTROL OK — all {expected} perturbed expectations were reported as disagreements.")
        return 0

    if missed or disagreements or constant_problems:
        return 1
    print("AGREED — every published vector and every pinned constant answered identically on both ends.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
