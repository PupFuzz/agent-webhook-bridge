#!/usr/bin/env python3
"""Unit tests for bin/coord-mirror-parity.py (card#10273).

⛔ THESE RUN WHERE THE AUTHORITY DOES NOT EXIST, and that is the whole design constraint. CI has
no copy of the coord plugin — the bridge does not vendor it — so every test here either drives the
comparison logic against a STUB authority or asserts something about the published corpora and this
program that needs no authority at all.

⭐ The load-bearing one is `TheShippedCorporaAreDrivable`: it holds each published corpus against
this program's adapter table. A corpus that grows a method nobody taught the runner to drive would
otherwise publish vectors the far end silently never runs — a contract that looks complete and is
not, which is the exact defect card#10273 exists to remove, re-minted one layer out.
"""
from __future__ import annotations

import contextlib
import copy
import hashlib
import importlib.util
import io
import json
import pathlib
import sys
import tempfile
import textwrap
import types
import unittest

_HERE = pathlib.Path(__file__).resolve().parent
_ROOT = _HERE.parent


def _load_runner():
    spec = importlib.util.spec_from_file_location("coord_mirror_parity", _HERE / "coord-mirror-parity.py")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


M = _load_runner()

CORPORA = ["docs/coord-terminals-parity-corpus.json", "docs/coord-lane-parity-corpus.json"]


def _corpus(name):
    return json.loads((_ROOT / name).read_text())


class StubAuthority:
    """An authority that answers from a table, so the comparison logic can be driven with no coord
    plugin present. `answers` maps a JSON-encoded call to the value to return."""

    def __init__(self, answers=None):
        self.answers = answers or {}
        self.calls = []

    def _answer(self, key, default):
        self.calls.append(key)
        return self.answers.get(key, default)

    def terminals_for_board(self, board_cfg):
        return frozenset(self._answer("terminals_for_board:" + json.dumps(board_cfg, sort_keys=True), []))

    def terminals_by_board(self, config, board_id):
        return frozenset(self._answer(f"terminals_by_board:{board_id}:" + json.dumps(config, sort_keys=True), []))

    def lane_model_governs(self, title):
        return self._answer("governs:" + json.dumps(title), False)

    def is_lane_label(self, label):
        return self._answer("is_lane_label:" + json.dumps(label), False)

    def task_lane(self, labels, mapped):
        return self._answer("task_lane:" + json.dumps([labels, mapped], sort_keys=True), "Later")


def _minimal_corpus(mirror_class, vectors, known=None, constants=None):
    return {
        "mirror": {"class": mirror_class, "path": "x.php", "runner": "bin/coord-mirror-parity.py"},
        "authority": {"symbol": "stub"},
        "constants": constants or {},
        "vectors": vectors,
        "known_divergences": known or {},
    }


class ThePerturbationMovesTheValue(unittest.TestCase):
    """The --control's falsifier is only as good as its perturbation: a `perturb` that returned its
    input for some shape would make the control pass while proving nothing about that shape."""

    def test_every_shape_the_corpora_carry_is_moved(self):
        for value in [True, False, [], ["a"], ["a", "b"], "s", "", 0, 5, None,
                      {"lane": "now", "unmapped": []}, {"lane": None, "unmapped": ["now"]}, {"k": 1}]:
            with self.subTest(value=value):
                self.assertNotEqual(M.perturb(value), value)

    def test_a_lane_answer_is_moved_on_its_lane_and_not_only_on_its_shape(self):
        # The mirror's resolveLane answer is a dict whose only behavioural field is `lane`; a
        # perturbation that rewrote the whole dict would be caught by a comparator that never
        # looked at `lane` at all.
        moved = M.perturb({"lane": "now", "unmapped": []})
        self.assertIn("lane", moved)
        self.assertNotEqual(moved["lane"], "now")
        self.assertNotEqual(M.perturb({"lane": "maybe", "unmapped": []})["lane"], "maybe")

    def test_perturbing_does_not_mutate_the_published_case(self):
        original = {"lane": "now", "unmapped": []}
        snapshot = copy.deepcopy(original)
        M.perturb(original)
        self.assertEqual(original, snapshot, "perturb() edited the corpus case in place, so a control run would corrupt every later comparison")


class TheReporterSurvivesTheAnswersItReports(unittest.TestCase):
    def test_a_mixed_type_set_renders_instead_of_raising(self):
        # Measured: the authority keeps a non-string member of `terminal_columns` where the mirror
        # drops it, so a published divergence really does carry {"Done", 5}. `sorted()` on that
        # raises TypeError, and a reporter that crashes on the one case it exists to report is
        # worse than one that says nothing.
        self.assertIn("Done", M._render({"Done", 5}))
        self.assertIn("5", M._render({"Done", 5}))

    def test_a_plain_value_renders_as_json(self):
        self.assertEqual(M._render(True), "true")
        self.assertEqual(M._render(["a"]), '["a"]')


class TheComparisonTellsAgreementFromDisagreement(unittest.TestCase):
    CLASS = "App\\Bridge\\Writeback\\CoordLaneStages"

    def _corpus(self):
        return _minimal_corpus(self.CLASS, {
            "governs": [{"args": ["[TASK] x"], "expect": True}],
            "isLaneLabel": [{"args": ["stage:now"], "expect": True}],
            "resolveLane": [{"args": [["stage:now"], ["now"]], "expect": {"lane": "now", "unmapped": []}}],
        }, constants={"DEFAULT_LANE": "later"})

    def _agreeing_authority(self):
        return StubAuthority({
            'governs:"[TASK] x"': True,
            'is_lane_label:"stage:now"': True,
            'task_lane:[["stage:now"], ["now"]]': "Now",
        })

    def test_an_agreeing_authority_yields_no_disagreements(self):
        disagreements, missed, population, raised = M.run(self._corpus(), self._agreeing_authority(), control=False)
        self.assertEqual(disagreements, [])
        self.assertEqual(missed, [])
        self.assertEqual(raised, [])
        self.assertIn("3 vectors over 3 methods", population)
        self.assertIn("0 known divergences", population)

    def test_a_drifted_authority_is_reported_by_method_and_index(self):
        authority = self._agreeing_authority()
        authority.answers['task_lane:[["stage:now"], ["now"]]'] = "Maybe"
        disagreements, _, _, _ = M.run(self._corpus(), authority, control=False)
        self.assertEqual(len(disagreements), 1)
        self.assertIn("resolveLane#0", disagreements[0])
        self.assertIn("maybe", disagreements[0], "a disagreement must report what the AUTHORITY actually answered")
        self.assertIn("now", disagreements[0], "a disagreement must report what the corpus claims")

    def test_the_mirrors_null_lane_is_folded_onto_the_default_rather_than_read_as_a_drift(self):
        # The shape difference the corpora declare: the mirror answers null where nothing mapped
        # was declared and the caller lands on DEFAULT_LANE, which is what the authority returns.
        corpus = _minimal_corpus(self.CLASS, {
            "resolveLane": [{"args": [[], ["now"]], "expect": {"lane": None, "unmapped": []}}],
        }, constants={"DEFAULT_LANE": "later"})
        authority = StubAuthority({'task_lane:[[], ["now"]]': "Later"})
        self.assertEqual(M.run(corpus, authority, control=False)[0], [])

    def test_control_mode_requires_a_disagreement_for_every_vector(self):
        disagreements, _, _, _ = M.run(self._corpus(), self._agreeing_authority(), control=True)
        self.assertEqual(len(disagreements), 3, "the control perturbed 3 expectations and the comparator saw fewer")

    def test_a_set_valued_answer_is_compared_as_a_set_and_not_by_order(self):
        corpus = _minimal_corpus("App\\Bridge\\Writeback\\CoordConfigTerminals", {
            "terminalsForBoard": [{"args": [{"terminal_columns": ["Done", "Released"]}], "expect": ["Released", "Done"]}],
        })
        authority = StubAuthority({'terminals_for_board:{"terminal_columns": ["Done", "Released"]}': ["Done", "Released"]})
        self.assertEqual(M.run(corpus, authority, control=False)[0], [])


class AMethodWithNoAdapterIsNeverSilentlySkipped(unittest.TestCase):
    """The failure this closes: a corpus grows a method, the runner has no adapter for it, and the
    run reports AGREED over vectors it never executed — a green that certifies less than it says."""

    def test_it_is_reported_as_not_run(self):
        corpus = _minimal_corpus("App\\Bridge\\Writeback\\CoordLaneStages", {
            "governs": [{"args": ["[TASK] x"], "expect": True}],
            "somethingNew": [{"args": [1], "expect": 2}],
        }, constants={"DEFAULT_LANE": "later"})
        authority = StubAuthority({'governs:"[TASK] x"': True})
        disagreements, missed, _, _ = M.run(corpus, authority, control=False)
        self.assertEqual(disagreements, [])
        self.assertEqual(len(missed), 1)
        self.assertIn("somethingNew", missed[0])
        self.assertIn("NOT run", missed[0])

    def test_an_unknown_mirror_class_is_reported_rather_than_agreed(self):
        corpus = _minimal_corpus("App\\Some\\Other\\Class", {"x": [{"args": [], "expect": 1}]})
        disagreements, _, _, _ = M.run(corpus, StubAuthority(), control=False)
        self.assertEqual(len(disagreements), 1)
        self.assertIn("no adapter table", disagreements[0])


class AnAuthorityThatRAISESIsNamedRatherThanEscaping(unittest.TestCase):
    """⛔ MEASURED, not hypothetical: three malformed-config shapes make the real coord authority
    raise where the mirror answers. An escaping exception would end the run with a traceback in
    place of a report. A raise is reported under its OWN outcome (`raised`, printed `RAISED:`) with
    the authority's error — kept apart from `disagreements`, which are different ANSWERS — and
    still exits 1, because the corpus records an answer the authority no longer gives."""

    CLASS = "App\\Bridge\\Writeback\\CoordConfigTerminals"

    class Exploding:
        def terminals_for_board(self, board_cfg):
            raise ValueError("invalid literal for int() with base 10: 'abc'")

    def _corpus(self):
        return _minimal_corpus(self.CLASS, {
            "terminalsForBoard": [{"args": [{"terminal_columns": ["Done"]}], "expect": ["Done"]}],
        })

    def test_the_raise_is_captured_and_named_rather_than_escaping(self):
        disagreements, _, _, raised = M.run(self._corpus(), self.Exploding(), control=False)
        self.assertEqual(disagreements, [], "a raise is not a different answer and must not be filed as one")
        self.assertEqual(len(raised), 1)
        self.assertIn("RAISED instead of answering", raised[0])
        self.assertIn("ValueError", raised[0], "the report must carry the authority's own error, or it says only that something went wrong")
        self.assertIn("terminalsForBoard#0", raised[0])

    def test_the_run_still_reaches_a_verdict_rather_than_dying(self):
        # The control for the test above: without the capture this call raises out of run() and
        # there is no verdict at all. Reaching this assertion IS the behaviour under test.
        _, missed, population, _ = M.run(self._corpus(), self.Exploding(), control=False)
        self.assertIn("1 vectors over 1 methods", population)
        self.assertEqual(missed, [])

    def test_a_known_divergence_whose_authority_raises_is_named_too(self):
        corpus = _minimal_corpus(self.CLASS, {
            "terminalsForBoard": [{"args": [{"terminal_columns": ["Done"]}], "expect": ["Done"]}],
        }, known={
            "terminalsForBoard": [{"args": [{"terminal_columns": "Done"}], "mirror": [], "authority": ["D"], "why": "measured"}],
        })
        _, _, _, raised = M.run(corpus, self.Exploding(), control=False)
        self.assertEqual(len(raised), 2)
        self.assertIn("known_divergences.terminalsForBoard#0", raised[1])
        self.assertIn("no second answer to pin", raised[1])

    def test_a_raise_in_the_constant_arm_is_named_rather_than_escaping(self):
        # Measured: renaming `terminals_for_board` in an authority copy killed the program
        # with an AttributeError traceback out of the unwrapped constant arm.
        problems, raised = M.ask_constants(types.SimpleNamespace(), _minimal_corpus(self.CLASS, {}, constants={"DEFAULT_TERMINAL": "Done"}))
        self.assertEqual(problems, [])
        self.assertIn("AttributeError", raised)


class AKnownDivergenceIsHeldFromThisSideToo(unittest.TestCase):
    CLASS = "App\\Bridge\\Writeback\\CoordLaneStages"

    def _corpus(self, mirror, authority_value):
        return _minimal_corpus(self.CLASS, {
            "governs": [{"args": ["[TASK] x"], "expect": True}],
        }, known={
            "governs": [{"args": ["[X] y"], "mirror": mirror, "authority": authority_value, "why": "measured"}],
        }, constants={"DEFAULT_LANE": "later"})

    def test_the_authority_half_is_checked(self):
        authority = StubAuthority({'governs:"[TASK] x"': True, 'governs:"[X] y"': True})
        self.assertEqual(M.run(self._corpus(False, True), authority, control=False)[0], [])

        authority.answers['governs:"[X] y"'] = False
        disagreements, _, _, _ = M.run(self._corpus(False, True), authority, control=False)
        self.assertEqual(len(disagreements), 1)
        self.assertIn("known_divergences.governs#0", disagreements[0])

    def test_a_converged_pair_is_reported_so_a_stale_entry_cannot_sit_there(self):
        authority = StubAuthority({'governs:"[TASK] x"': True, 'governs:"[X] y"': True})
        disagreements, _, _, _ = M.run(self._corpus(True, True), authority, control=False)
        self.assertEqual(len(disagreements), 1)
        self.assertIn("documents no divergence", disagreements[0])

    def test_known_divergences_join_the_population_line(self):
        authority = StubAuthority({'governs:"[TASK] x"': True, 'governs:"[X] y"': True})
        self.assertIn("1 known divergences", M.run(self._corpus(False, True), authority, control=False)[2])


class TheShippedCorporaAreDrivable(unittest.TestCase):
    """The drift guard between the PUBLISHED files and this program. No authority needed."""

    def test_every_published_method_has_an_adapter(self):
        for name in CORPORA:
            corpus = _corpus(name)
            adapters = M.ADAPTERS.get(corpus["mirror"]["class"])
            self.assertIsNotNone(adapters, f"{name} names a mirror this program cannot drive")
            for method in list(corpus["vectors"]) + list(corpus.get("known_divergences", {})):
                with self.subTest(corpus=name, method=method):
                    self.assertIn(method, adapters, f"{name} publishes vectors for `{method}` and this program has no adapter, so the far end would never run them")

    def test_every_published_corpus_names_this_program_as_its_runner(self):
        for name in CORPORA:
            self.assertEqual(_corpus(name)["mirror"]["runner"], "bin/coord-mirror-parity.py", name)

    def test_the_constant_checker_knows_every_published_mirror(self):
        # `check_constants` dispatches on the mirror class and falls through to a complaint. A
        # published corpus reaching that fall-through would report a disagreement forever.
        for name in CORPORA:
            corpus = _corpus(name)
            with self.subTest(corpus=name):
                self.assertRegex(corpus["mirror"]["class"], r"(CoordConfigTerminals|CoordLaneStages)$")

    def test_no_published_member_has_more_than_one_home(self):
        blocks = ("vectors", "mirror_local", "mirrored_but_not_driven")
        for name in CORPORA:
            corpus = _corpus(name)
            for i, a in enumerate(blocks):
                for b in blocks[i + 1:]:
                    with self.subTest(corpus=name, blocks=(a, b)):
                        self.assertEqual(set(corpus[a]) & set(corpus[b]), set())


_STUB_COMMON = """
STUB_TERMINAL = "StubTerminal-coord-mirror-parity"


def load_config():
    import json, os
    with open(os.environ["COORD_CONFIG"]) as handle:
        return json.load(handle)


def terminals_for_board(board_cfg, default_terminal="Done"):
    return frozenset({STUB_TERMINAL})
"""

_STUB_INBOX = """
from coord.kanban_common import load_config, terminals_for_board

DONE_COLUMN = "Done"


def _terminal_columns_by_board():
    out = {}
    for b in ((load_config().get("kanban") or {}).get("boards") or []):
        out.setdefault(int(b["board_id"]), set()).update(terminals_for_board(b, default_terminal=DONE_COLUMN))
    return {k: frozenset(v) for k, v in out.items()}
"""

_STUB_ISSUES = """
from coord.kanban_common import terminals_for_board

LANE_TO_COLUMN = {"Now": "stage-now", "Next": "stage-next", "Later": "stage-later", "Maybe": "stage-maybe",
                  "Awaiting ACK": "stage-awaiting-ack", "Done": "stage-done"}
_STAGE_LANE = {"stage:now": "Now", "stage:next": "Next", "stage:later": "Later", "stage:maybe": "Maybe"}


def _task_lane(issue, columns):
    names = {(l.get("name") or "").lower() for l in (issue.get("labels") or [])}
    for label, lane in _STAGE_LANE.items():
        if label in names and LANE_TO_COLUMN[lane] in columns:
            return lane
    return "Later"


def classify_coord(item, columns):
    issue, state = item
    lane = _task_lane(issue, columns) if issue.get("title", "").upper().startswith("[TASK]") else "Now"
    return columns[LANE_TO_COLUMN[lane]]
"""


def _write_stub_examples(root, inbox=_STUB_INBOX):
    root = pathlib.Path(root)
    (root / "kanban_common.py").write_text(textwrap.dedent(_STUB_COMMON))
    (root / "kanban-inbox-check.py").write_text(textwrap.dedent(inbox))
    (root / "kanban-issues-sync.py").write_text(textwrap.dedent(_STUB_ISSUES))
    return root


class _RestoresTheCoordPackage(unittest.TestCase):
    """Binding the authority rebinds the process's `coord` package; put back whatever was there,
    so one test's stub never answers for the next test."""

    def setUp(self):
        self._saved = {n: m for n, m in sys.modules.items() if n == "coord" or n.startswith("coord.")}

    def tearDown(self):
        for name in [n for n in sys.modules if n == "coord" or n.startswith("coord.")]:
            del sys.modules[name]
        sys.modules.update(self._saved)


class TheAuthorityRunIsTheOneNamedOnTheCommandLine(_RestoresTheCoordPackage):
    """⛔ MEASURED: the two example scripts import `from coord.kanban_common`, which
    Python resolves on sys.path — on a box with an installed `coord` package, that is NOT the copy in
    --coord-examples. The join method then ran against an authority nobody named. The stub below
    answers a terminal no real authority answers, so it differs from any installed copy by
    construction, and a runner that reaches any other `kanban_common` cannot produce it."""

    def test_the_join_method_runs_the_named_kanban_common(self):
        with tempfile.TemporaryDirectory() as tmp:
            authority = M.Authority(_write_stub_examples(tmp))
            answer = authority.terminals_by_board({"kanban": {"boards": [{"board_id": 8}]}}, 8)
        self.assertEqual(answer, frozenset({"StubTerminal-coord-mirror-parity"}),
                         "_terminal_columns_by_board reached a kanban_common other than the one in --coord-examples")

    def test_the_scripts_share_the_named_modules_function_objects(self):
        with tempfile.TemporaryDirectory() as tmp:
            authority = M.Authority(_write_stub_examples(tmp))
            self.assertIs(authority.inbox.terminals_for_board, authority.common.terminals_for_board)
            self.assertIs(authority.inbox.load_config, authority.common.load_config)
            self.assertIs(authority.issues.terminals_for_board, authority.common.terminals_for_board)

    def test_every_executed_file_is_printed_with_its_sha256(self):
        with tempfile.TemporaryDirectory() as tmp:
            examples = _write_stub_examples(tmp)
            corpus = pathlib.Path(tmp) / "corpus.json"
            corpus.write_text(json.dumps(_minimal_corpus("App\\Bridge\\Writeback\\CoordConfigTerminals", {
                "terminalNamesForBoardId": [{"args": [{"kanban": {"boards": [{"board_id": 8}]}}, 8],
                                             "expect": ["StubTerminal-coord-mirror-parity"]}],
            }, constants={"DEFAULT_TERMINAL": "Done"})))
            out = io.StringIO()
            with contextlib.redirect_stdout(out):
                code = M.main(["--corpus", str(corpus), "--coord-examples", str(examples)])
            printed = out.getvalue()
            for name in ["kanban_common.py", "kanban-inbox-check.py", "kanban-issues-sync.py"]:
                with self.subTest(file=name):
                    digest = hashlib.sha256((examples / name).read_bytes()).hexdigest()
                    self.assertIn(f"sha256:{digest}", printed)
        self.assertEqual(code, 0, printed)

    def test_a_script_bound_to_another_copy_is_could_not_measure(self):
        # The guard's own control: a script that reaches a kanban_common OUTSIDE the named
        # directory must stop the run at exit 2 rather than answer for an authority nobody named.
        with tempfile.TemporaryDirectory() as tmp, tempfile.TemporaryDirectory() as elsewhere:
            (pathlib.Path(elsewhere) / "kanban_common.py").write_text(textwrap.dedent(_STUB_COMMON))
            rebound = textwrap.dedent(_STUB_INBOX) + textwrap.dedent(f"""
                import importlib.util as _u
                _spec = _u.spec_from_file_location("coord.kanban_common", {str(pathlib.Path(elsewhere) / "kanban_common.py")!r})
                _other = _u.module_from_spec(_spec)
                _spec.loader.exec_module(_other)
                terminals_for_board = _other.terminals_for_board
            """)
            examples = _write_stub_examples(tmp, inbox=rebound)
            err = io.StringIO()
            with contextlib.redirect_stderr(err), contextlib.redirect_stdout(io.StringIO()):
                code = M.main(["--corpus", str(_ROOT / CORPORA[0]), "--coord-examples", str(examples)])
        self.assertEqual(code, 2, err.getvalue())
        self.assertIn("terminals_for_board", err.getvalue())


def _constants_authority(stage_lane=None, done_column="Done", default_terminal="Done"):
    """A stub carrying the three authority data structures `check_constants` reads."""
    stage_lane = stage_lane if stage_lane is not None else {
        "stage:now": "Now", "stage:next": "Next", "stage:later": "Later", "stage:maybe": "Maybe"}

    def terminals_for_board(board_cfg, default_terminal=default_terminal):
        return frozenset()

    authority = StubAuthority()
    # The real gate's shape (a title PREFIX test), not a lookup table: the control perturbs the
    # pinned prefix, and a table would answer a perturbed title by default rather than by the rule.
    authority.lane_model_governs = lambda title: title.upper().startswith("[TASK]")
    authority.common = types.SimpleNamespace(terminals_for_board=terminals_for_board)
    authority.inbox = types.SimpleNamespace(DONE_COLUMN=done_column)
    authority.issues = types.SimpleNamespace(_STAGE_LANE=stage_lane)
    return authority


class TheConstantArmReportsAMovedConstant(unittest.TestCase):
    """The success line claims every pinned CONSTANT agreed. Each case below moves ONE authority
    structure the pins are held against and requires a problem; the first proves the stub agrees
    when nothing moved, so the others cannot pass on a checker that complains about everything."""

    TERMINALS = "App\\Bridge\\Writeback\\CoordConfigTerminals"
    LANES = "App\\Bridge\\Writeback\\CoordLaneStages"

    def _terminals(self):
        return _minimal_corpus(self.TERMINALS, {}, constants={"DEFAULT_TERMINAL": "Done"})

    def _lanes(self):
        return _minimal_corpus(self.LANES, {}, constants={
            "LANES": ["now", "next", "later", "maybe"], "DEFAULT_LANE": "later",
            "LABEL_PREFIX": "stage:", "LANE_MODEL_TITLE_PREFIX": "[TASK]"})

    def test_an_unmoved_authority_reports_nothing(self):
        self.assertEqual(M.check_constants(_constants_authority(), self._terminals()), [])
        self.assertEqual(M.check_constants(_constants_authority(), self._lanes()), [])

    def test_a_reordered_stage_lane_is_reported(self):
        moved = {"stage:next": "Next", "stage:now": "Now", "stage:later": "Later", "stage:maybe": "Maybe"}
        problems = M.check_constants(_constants_authority(stage_lane=moved), self._lanes())
        self.assertTrue(any("constant LANES" in p for p in problems), problems)

    def test_a_stage_lane_that_gained_a_lane_is_reported(self):
        # The REVERSE direction at the far end: the authority grows a lane the corpus never pinned.
        grown = {"stage:now": "Now", "stage:next": "Next", "stage:later": "Later", "stage:maybe": "Maybe",
                 "stage:parked": "Parked"}
        problems = M.check_constants(_constants_authority(stage_lane=grown), self._lanes())
        self.assertTrue(any("constant LANES" in p for p in problems), problems)

    def test_a_moved_done_column_is_reported(self):
        problems = M.check_constants(_constants_authority(done_column="Closed"), self._terminals())
        self.assertTrue(any("DONE_COLUMN" in p for p in problems), problems)

    def test_a_moved_default_terminal_is_reported(self):
        problems = M.check_constants(_constants_authority(default_terminal="Closed", done_column="Closed"), self._terminals())
        self.assertTrue(any("constant DEFAULT_TERMINAL" in p for p in problems), problems)


class TheControlPerturbsThePinnedConstantsToo(unittest.TestCase):
    """--control must not certify only the vector arm while the success line speaks for constants."""

    def test_every_pinned_constant_perturbed_is_reported(self):
        corpus = _minimal_corpus(TheConstantArmReportsAMovedConstant.LANES, {}, constants={
            "LANES": ["now", "next", "later", "maybe"], "DEFAULT_LANE": "later",
            "LABEL_PREFIX": "stage:", "LANE_MODEL_TITLE_PREFIX": "[TASK]"})
        missed = M.control_constants(_constants_authority(), corpus)
        self.assertEqual(missed, [])

    def test_a_pinned_constant_the_checker_does_not_hold_is_reported_as_unseen(self):
        corpus = _minimal_corpus(TheConstantArmReportsAMovedConstant.TERMINALS, {}, constants={
            "DEFAULT_TERMINAL": "Done", "SOMETHING_NEW": "x"})
        missed = M.control_constants(_constants_authority(), corpus)
        self.assertEqual(len(missed), 1, missed)
        self.assertIn("SOMETHING_NEW", missed[0])


class TheExitContract(_RestoresTheCoordPackage):
    """Three codes, because 'could not ask' is not 'asked and agreed'. This is the arm CI itself
    lands on, and it is the reason CI can run these tests at all."""

    def test_an_absent_authority_is_two_not_zero_and_not_one(self):
        with tempfile.TemporaryDirectory() as empty:
            code = M.main(["--corpus", str(_ROOT / CORPORA[0]), "--coord-examples", str(pathlib.Path(empty) / "nope")])
        self.assertEqual(code, 2)

    def test_a_directory_with_no_authority_sources_is_also_two(self):
        with tempfile.TemporaryDirectory() as empty:
            code = M.main(["--corpus", str(_ROOT / CORPORA[0]), "--coord-examples", empty])
        self.assertEqual(code, 2, "a directory that exists but holds no authority must not be read as agreement")


class TheDefaultAuthorityLookup(unittest.TestCase):
    def test_the_env_override_wins(self):
        import os
        previous = os.environ.get("COORD_EXAMPLES")
        os.environ["COORD_EXAMPLES"] = "/some/where"
        try:
            self.assertEqual(M.default_examples_dir(), pathlib.Path("/some/where"))
        finally:
            if previous is None:
                os.environ.pop("COORD_EXAMPLES", None)
            else:
                os.environ["COORD_EXAMPLES"] = previous

    def test_versions_order_numerically_not_lexically(self):
        # 0.9.0 vs 0.55.0: a lexical sort picks 0.9.0, which is the OLDER plugin.
        self.assertLess(M._version_key("0.9.0"), M._version_key("0.55.0"))
        self.assertLess(M._version_key("0.55.0"), M._version_key("0.55.1"))


if __name__ == "__main__":
    unittest.main()
