#!/usr/bin/env python3
"""Unit tests for the doc-fence BACKSTOP bin/check-doc-secret-fences.py (card#8351).

THE TWO DIRECTIONS THIS SUITE OWES, AND WHY BOTH.

  RED   — `bin/testdata/v0.79.0-live-members.md.fixture` carries all five members of
          this class VERBATIM out of the v0.79.0 tag, where every one was still
          live: DL-322's `openssl -hmac` argv leak and DL-321's four. The tool must
          find 5/5, on the right lines, under the right rule. A check that has never
          been seen to fail is a decoration, and a fixture asserted only at
          `len(findings) > 0` certifies whatever replaces it — so each member is
          pinned to its LINE and its RULE, not to a total.

  GREEN — the sanctioned forms those five were replaced with must not red. That is
          asserted on the REAL surface, this checkout's own markdown, not on a copy
          of it: a fixture of "the good forms" would be a second copy of docs/ that
          drifts away from the docs the moment either moves. `docs/multi-host.md`
          alone contains `printf … | curl --config -`, `export TOKEN="$(cat
          "$TOKEN_FILE")"` and `mkdir -p "$(dirname "$TOKEN_FILE")"` — three shapes a
          naive spelling grep reds on and this one must not.

THE CONTROL. A fixture that reds proves the FIXTURE is bad, not that the DETECTOR
works — the finding could come from anywhere in the program. So each rule that fires
on the fixture is disabled in turn (`enabled=`), and the member it owns must
DISAPPEAR while the others stay. A second control empties the secret-name
vocabulary and requires the whole fixture to go clean: if the fixture still reddened
with nothing recognised as a secret, the red would be coming from something other
than the detection this program claims to do.

⚠ WHAT A GREEN RUN OF THIS SUITE DOES NOT MEAN. The subject is a BACKSTOP under
`docs/config-schema.md` § Handling a secret VALUE, which is stated over the SURFACE.
These cases prove it catches the shapes it knows on the population that has been
measured. They say nothing about the instrument nobody has listed yet, and nothing
at all about a member minted outside a markdown shell fence.
"""

from __future__ import annotations

import importlib.util
import os
import re
import subprocess
import sys
import unittest

_HERE = os.path.dirname(os.path.abspath(__file__))
_REPO = os.path.dirname(_HERE)
_TOOL = os.path.join(_HERE, 'check-doc-secret-fences.py')
_FIXTURE = os.path.join(_HERE, 'testdata', 'v0.79.0-live-members.md.fixture')

_spec = importlib.util.spec_from_file_location('check_doc_secret_fences', _TOOL)
assert _spec and _spec.loader
lint = importlib.util.module_from_spec(_spec)
# Registered BEFORE exec: `@dataclass` resolves its annotations through
# `sys.modules[cls.__module__]`, and a module loaded off a path with a hyphen in it
# is not importable by name, so without this the tool's own dataclasses raise at
# import time. Same load shape as bin/test_check_channel_snapshot.py.
sys.modules[_spec.name] = lint
_spec.loader.exec_module(lint)


def _fixture_text() -> str:
    with open(_FIXTURE, encoding='utf-8') as fh:
        return fh.read()


def _line_of(text: str, needle: str) -> int:
    """1-based line carrying `needle` — asserted, never guessed, so a fixture edit
    moves the expectation with it instead of silently passing on a stale number."""
    hits = [i for i, line in enumerate(text.split('\n'), 1) if needle in line]
    if len(hits) != 1:
        raise AssertionError(f'expected exactly one line containing {needle!r}, got {hits}')
    return hits[0]


def _scan(text: str, enabled=None):
    return lint.scan_text('fixture.md', text, enabled)


class TheFiveKnownMembers(unittest.TestCase):
    """RED. Each member of the measured population, by line and by rule."""

    #: (marker on the offending line, rule id, the surface the rule is about)
    MEMBERS = [
        ('openssl dgst -sha256 -hmac "$SECRET"', 'argv', 'argv'),
        ('echo "Save this token securely:', 'stdout', 'stdout'),
        ('-H "Authorization: Bearer $BRIDGE_CHANNEL_TOKEN"', 'argv', 'argv'),
        ('echo -n "your-hmac-secret" >', 'history', 'shell history'),
        ("install -m 600 /dev/stdin", 'history', 'shell history'),
    ]

    def setUp(self) -> None:
        self.text = _fixture_text()
        self.findings = lint.scan_text(_FIXTURE, self.text)

    def test_the_v0790_recipes_are_caught_five_out_of_five(self) -> None:
        got = {(f.line, f.rule) for f in self.findings}
        want = {(_line_of(self.text, marker), rule) for marker, rule, _ in self.MEMBERS}
        self.assertEqual(want, got, f'\n{[f.render() for f in self.findings]}')

    def test_each_member_names_its_surface(self) -> None:
        by_line = {f.line: f for f in self.findings}
        for marker, rule, surface in self.MEMBERS:
            line = _line_of(self.text, marker)
            with self.subTest(marker=marker):
                self.assertIn(line, by_line)
                self.assertEqual(rule, by_line[line].rule)
                self.assertEqual(surface, by_line[line].surface)

    def test_the_signed_smoke_test_reds_at_the_openssl_line_not_the_cat_line(self) -> None:
        """`SECRET=$(cat …)` is NOT the defect and must not be reported as one.

        The v0.79.0 recipe reads the secret into a variable and THEN hands it to
        `openssl`. Only the second step puts it on a readable surface — and the
        identical read-into-a-variable shape is what `docs/multi-host.md` does on
        purpose today. A tool that reddened on the `cat` would force that doc to
        change to satisfy a rule it already follows.
        """
        cat_line = _line_of(self.text, 'SECRET=$(cat "<secret_dir>')
        self.assertNotIn(cat_line, {f.line for f in self.findings})


class TheControls(unittest.TestCase):
    """Proof the red comes from the detection, not from somewhere else."""

    def setUp(self) -> None:
        self.text = _fixture_text()

    def test_disabling_one_rule_removes_exactly_that_rules_member(self) -> None:
        every = set(lint.RULE_IDS)
        baseline = {(f.line, f.rule) for f in lint.scan_text(_FIXTURE, self.text, every)}
        for rule in ('argv', 'stdout', 'history'):
            with self.subTest(disabled=rule):
                mutant = lint.scan_text(_FIXTURE, self.text, every - {rule})
                got = {(f.line, f.rule) for f in mutant}
                self.assertEqual({m for m in baseline if m[1] != rule}, got)
                self.assertNotEqual(baseline, got,
                                    f'disabling {rule} changed nothing — the fixture '
                                    f'reds for some reason other than that rule')

    def test_emptying_the_secret_vocabulary_makes_the_whole_fixture_clean(self) -> None:
        """The strong control: with nothing recognised as naming a secret, every
        finding must go. A residue here would be a finding produced by something
        that is not the detection this program claims to perform."""
        saved = (lint.SECRET_WORDS, lint.SECRET_PATH_MARKERS, lint.GENERIC_PATH_MARKERS)
        try:
            lint.SECRET_WORDS = set()
            lint.SECRET_PATH_MARKERS = ()
            lint.GENERIC_PATH_MARKERS = ()
            self.assertEqual([], lint.scan_text(_FIXTURE, self.text))
        finally:
            (lint.SECRET_WORDS, lint.SECRET_PATH_MARKERS,
             lint.GENERIC_PATH_MARKERS) = saved

    def test_the_vocabulary_is_restored_and_the_fixture_reds_again(self) -> None:
        """Pins the control's own teardown: a mutation left in place would make
        every later case in this module vacuous."""
        self.assertEqual(5, len(lint.scan_text(_FIXTURE, self.text)))


class TheFixtureIsTheRealArtifact(unittest.TestCase):
    """The fixture is only evidence if it is what v0.79.0 actually said."""

    def _git_show(self, path: str) -> str:
        try:
            out = subprocess.run(['git', '-C', _REPO, 'show', f'v0.79.0:{path}'],
                                 capture_output=True, text=True, timeout=30)
        except (OSError, subprocess.SubprocessError) as exc:  # pragma: no cover
            self.skipTest(f'LOUD SKIP: git unavailable ({exc}); fixture provenance unverified')
        if out.returncode != 0:
            # CI checks out at depth 1, so the tag is usually absent there. The RED
            # direction above does not depend on this case — it reads the committed
            # bytes — so a skip here loses provenance, not coverage.
            self.skipTest('LOUD SKIP: tag v0.79.0 not in this checkout '
                          f'({out.stderr.strip()}); fixture provenance unverified')
        return out.stdout

    def test_every_fence_here_is_verbatim_v0790(self) -> None:
        blocks = {
            'CLAUDE_DEPLOYMENT.md': ("SCOPE='<org/repo>'", '# then: php artisan bridge:stats'),
            'docs/provider-adapters.md': ('mkdir -p "$BRIDGE_SECRET_DIR/your_provider"',
                                          'chmod 600 "$BRIDGE_SECRET_DIR'),
            'docs/writeback.md': ('install -m 600 /dev/stdin', 'install -m 600 /dev/stdin'),
        }
        fixture = _fixture_text()
        for path, (first, last) in blocks.items():
            with self.subTest(path=path):
                source = self._git_show(path)
                lines = source.split('\n')
                a = next(i for i, l in enumerate(lines) if first in l)
                b = next(i for i, l in enumerate(lines) if last in l)
                self.assertIn('\n'.join(lines[a:b + 1]), fixture)

    def test_the_multi_host_fences_are_verbatim_v0790(self) -> None:
        source = self._git_show('docs/multi-host.md').split('\n')
        fixture = _fixture_text()
        for first, last in [
            ('# A port no other service on either host uses',
             'echo "Save this token securely:'),
            ('# From host A:', 'http://127.0.0.1:8788/'),
        ]:
            with self.subTest(first=first):
                a = next(i for i, l in enumerate(source) if first in l)
                b = next(i for i, l in enumerate(source) if last in l)
                self.assertIn('\n'.join(source[a:b + 1]), fixture)


class ThisCheckoutIsGreen(unittest.TestCase):
    """GREEN, on the real surface — not on a copy of it."""

    def test_every_markdown_file_in_this_checkout_is_clean(self) -> None:
        findings = lint.scan_paths([_REPO])
        self.assertEqual([], [f.render() for f in findings])

    def test_the_walk_actually_reached_the_docs_it_claims_to_have_read(self) -> None:
        """An empty result is a measurement that never happened until shown
        otherwise. The clean run above means nothing if the walk found no files."""
        files = {os.path.relpath(p, _REPO) for p in lint.markdown_files([_REPO])}
        for must in ('docs/multi-host.md', 'docs/provider-adapters.md',
                     'docs/writeback.md', 'CLAUDE_DEPLOYMENT.md',
                     'docs/config-schema.md', 'docs/CHANGELOG.md'):
            self.assertIn(must, files)

    def test_the_fixture_is_out_of_the_default_walk(self) -> None:
        """Not by an exclusion list — by not being a `.md` file. An exclusion list
        is the enumeration this whole card is about."""
        walked = lint.markdown_files([_REPO])
        self.assertNotIn(_FIXTURE, walked)
        self.assertEqual(5, len(lint.scan_paths([_FIXTURE])),
                         'the fixture must still be scannable when named explicitly')


class ProseIsNeverRead(unittest.TestCase):
    """The constraint that decides whether this tool can exist at all.

    This repo DOCUMENTS the defect: `CLAUDE_DEPLOYMENT.md` quotes the argv leak in
    order to teach why it was wrong, and DL-321/DL-322/the CHANGELOG quote several
    more. A lint that reddened on the documentation of a defect is worse than no
    lint, because what gets deleted is the explanation.
    """

    def test_the_deployment_preamble_that_quotes_the_argv_leak_is_clean(self) -> None:
        # Verbatim from CLAUDE_DEPLOYMENT.md's smoke-test preamble (DL-322).
        prose = ('⛔ **`bridge:sign` produces the signature, and NOTHING here reads '
                 'the secret into a shell variable** (DL-322). ... a `openssl dgst '
                 '-hmac "$SECRET"` — which this block used to say — hands that value '
                 'to every local account through `/proc/<pid>/cmdline`.\n')
        self.assertEqual([], _scan(prose))

    def test_the_same_bytes_inside_a_shell_fence_do_red(self) -> None:
        """The discriminator, pinned in both directions on ONE payload: prose vs
        fence is the only difference between these two cases."""
        payload = 'openssl dgst -sha256 -hmac "$SECRET" -hex\n'
        self.assertEqual([], _scan(f'Never write `{payload.strip()}` in a runbook.\n'))
        self.assertEqual(['argv'], [f.rule for f in _scan(f'```bash\n{payload}```\n')])

    def test_a_non_shell_fence_is_not_executable(self) -> None:
        for info in ('text', 'json', 'php', 'yaml', ''):
            with self.subTest(info=info):
                self.assertEqual([], _scan(f'```{info}\necho "$SECRET"\n```\n'))

    def test_a_comment_inside_a_shell_fence_is_not_a_command(self) -> None:
        block = ('```bash\n'
                 '# NOT: openssl dgst -hmac "$SECRET"; NOT: echo "$TOKEN"\n'
                 'php artisan bridge:sign --scope=x\n'
                 '```\n')
        self.assertEqual([], _scan(block))


class TheSanctionedFormsStayGreen(unittest.TestCase):
    """The forms `docs/config-schema.md` § Handling a secret VALUE PRESCRIBES.

    Every one of these is a shape a naive grep-for-secret-words reds on. Reddening
    here would push an author back to the leaking spelling, which is the failure
    mode that matters most for a backstop.
    """

    CASES = {
        'place with read -rs and a builtin printf':
            'read -rsp \'secret: \' SECRET\nprintf \'%s\' "$SECRET" > "$D/webhook-secret-scope-x"\nunset SECRET\n',
        'pass on stdin via curl --config':
            'printf \'header = "Authorization: Bearer %s"\\n\' "$BRIDGE_CHANNEL_TOKEN" |\ncurl --config - http://127.0.0.1:8788/\n',
        'pipe a builtin printf into install':
            'printf \'%s\' "$TOKEN" | install -m 600 /dev/stdin "$BRIDGE_DIR/kanban/writeback-token"\n',
        'read a secret file into a variable, never onto stdout':
            'export BRIDGE_CHANNEL_TOKEN="$(cat "$TOKEN_FILE")"\n',
        'a path derived from a secret file path is not a value':
            'mkdir -p "$(dirname "$TOKEN_FILE")"\nchmod 600 "$TOKEN_FILE"\n',
        'generate straight into a 0600 file':
            '( umask 077\n  openssl rand -base64 48 | tr -d /=+ | head -c 64 > "$TOKEN_FILE" )\n',
        'test for a value with the alternative expansion':
            'echo "${BRIDGE_CHANNEL_TOKEN:+set}"\n[ -n "$BRIDGE_CHANNEL_TOKEN" ] && echo ok\n',
        'compare digests, never values':
            'sha256sum < "$TOKEN_FILE"\n',
        'test for the FILE with test -r, which resolves no value':
            'test -r "$TOKEN_FILE" && echo present\n',
        'a LENGTH is not the value':
            'echo "${#BRIDGE_CHANNEL_TOKEN}"\n',
        'sign through the command that resolves the secret itself':
            'SIG=$(printf \'%s\' "$BODY" | php artisan bridge:sign --provider=github --scope="$SCOPE")\n',
        'a disposable container credential is a literal, not a placed secret':
            'docker run -e MARIADB_ROOT_PASSWORD=root -e MARIADB_PASSWORD=ci_user_password mariadb:10.6\n',
        'an env-prefixed test command':
            'DB_PASSWORD=ci_user_password vendor/bin/phpunit\n',
    }

    def test_none_of_the_prescribed_forms_reds(self) -> None:
        for name, body in self.CASES.items():
            with self.subTest(form=name):
                findings = _scan(f'```bash\n{body}```\n')
                self.assertEqual([], [f.render() for f in findings])


class TheOtherSurfacesTheRuleNames(unittest.TestCase):
    """`stdout`, `argv`, a log, shell history — the rule names four, so the tool
    carries a case for each. `log` and the probe have no member in the measured
    population; they are here because the rule names the surface and a tool silent
    on a named surface reads as coverage of it."""

    def _rules(self, body: str) -> list[str]:
        return sorted(f.rule for f in _scan(f'```bash\n{body}```\n'))

    def test_a_default_expansion_probe_prints_what_it_was_testing_for(self) -> None:
        self.assertEqual(['probe'], self._rules('echo "token=${BRIDGE_CHANNEL_TOKEN:-unset}"\n'))
        self.assertEqual(['probe'], self._rules('X="${SECRET-none}"\n'))
        self.assertEqual(['probe'], self._rules('X="${SECRET:=fallback}"\n'))

    def test_the_alternative_expansion_is_the_prescribed_form_and_stays_green(self) -> None:
        self.assertEqual([], self._rules('echo "${SECRET:+set}"\n'))
        self.assertEqual([], self._rules('echo "${SECRET+set}"\n'))

    def test_a_secret_redirected_into_a_log_is_a_log_leak(self) -> None:
        self.assertIn('log', self._rules('printf \'%s\' "$SECRET" >> /var/log/provision.log\n'))

    def test_a_pipeline_carrying_a_secret_that_ends_at_a_pager_is_a_stdout_leak(self) -> None:
        self.assertIn('stdout', self._rules('printf \'%s\' "$SECRET" | tee\n'))

    def test_reading_a_secret_file_back_onto_stdout(self) -> None:
        self.assertEqual(['stdout'], self._rules('cat "$BRIDGE_DIR/kanban/writeback-token"\n'))

    def test_a_here_string_literal_into_a_secret_store_is_a_history_leak(self) -> None:
        self.assertEqual(
            ['history'],
            self._rules('install -m 600 /dev/stdin "$D/kanban/writeback-token" <<<\'<the-token>\'\n'))

    def test_a_stderr_redirect_does_not_capture_stdout(self) -> None:
        """`2>` moves stderr. Reading it as "redirected, therefore captured" made
        `echo "$SECRET" 2>/dev/null` look safe — found by review, not by a report."""
        self.assertEqual(['stdout'], self._rules('echo "$SECRET" 2>/dev/null\n'))

    def test_a_redirect_to_stderr_is_still_a_readable_surface(self) -> None:
        self.assertEqual(['stdout'], self._rules('echo "$SECRET" >&2\n'))

    def test_a_redirect_to_a_real_file_is_the_prescribed_form_and_stays_green(self) -> None:
        self.assertEqual([], self._rules('printf \'%s\' "$SECRET" > /run/secrets/x\n'))
        self.assertEqual([], self._rules('echo "$SECRET" > /dev/null\n'))

    def test_reading_a_secret_file_through_an_input_redirect(self) -> None:
        self.assertEqual(['stdout'], self._rules('cat < "$TOKEN_FILE"\n'))
        self.assertEqual([], self._rules('sha256sum < "$TOKEN_FILE"\n'))


class ANameTakingPrinterResolvesTheValueFromABareNAME(unittest.TestCase):
    """`printenv NAME` / `declare -p NAME` print a VALUE the source text never
    spells.

    Both sat in `PRINTING_COMMANDS`, but the `stdout` arm asked only whether an
    argument carried a `$`-expansion — and these two take a bare NAME, so the arm
    could not fire for them at all while `_is_secret_value_name` already answered
    True for the very argument being passed. A rule that cannot fire is the shape
    this whole card is about.

    The NAME is not the VALUE, which is why this feeds the `stdout` arm and NOT the
    `argv` one: `printenv` does fork, but what reaches `/proc/<pid>/cmdline` is the
    name it was asked for.
    """

    def _rules(self, body: str) -> list[str]:
        return sorted(f.rule for f in _scan(f'```bash\n{body}```\n'))

    def test_printenv_and_declare_on_a_secret_name_are_stdout_leaks(self) -> None:
        self.assertTrue(lint._is_secret_value_name('BRIDGE_WEBHOOK_SECRET'))
        for body in (
            'printenv BRIDGE_WEBHOOK_SECRET\n',
            'declare -p BRIDGE_WEBHOOK_SECRET\n',
            'typeset -p BRIDGE_CHANNEL_TOKEN\n',
        ):
            with self.subTest(body=body.strip()):
                self.assertEqual(['stdout'], self._rules(body))

    def test_the_name_is_not_reported_as_an_argv_leak(self) -> None:
        """`printenv` is not a shell builtin, so widening `arg_secrets` instead of
        the printer leg would have produced an `argv` finding whose message — "…
        becomes a token in /proc/<pid>/cmdline" — is FALSE: what is in the argv is
        the name."""
        findings = _scan('```bash\nprintenv BRIDGE_WEBHOOK_SECRET\n```\n')
        self.assertEqual(['stdout'], [f.rule for f in findings])
        self.assertNotIn('printenv', lint.SHELL_BUILTINS)

    def test_an_ordinary_variable_name_stays_green(self) -> None:
        """The discriminator: the NAME is the only variable between this and the
        case above. A printer that reddened on `printenv PATH` would be switched
        off within a day."""
        for body in ('printenv PATH\n', 'printenv HOME\n', 'declare -p BRIDGE_DIR\n'):
            with self.subTest(body=body.strip()):
                self.assertEqual([], self._rules(body))

    def test_a_name_ending_in_a_path_word_is_still_a_path(self) -> None:
        """The negative half of the vocabulary is not bypassed by this leg:
        `TOKEN_FILE` names where the value lives, not the value."""
        self.assertEqual([], self._rules('printenv TOKEN_FILE\n'))
        self.assertEqual([], self._rules('printenv BRIDGE_SECRET_DIR\n'))

    def test_a_pipe_stage_does_not_silence_it_either(self) -> None:
        """The same property the tail rule exists for: marking the pipeline from
        this leg is what keeps `printenv <secret> | base64` from being green while
        `printenv <secret>` reds."""
        self.assertEqual(['stdout'], self._rules('printenv BRIDGE_WEBHOOK_SECRET | base64\n'))
        self.assertEqual([], self._rules('printenv BRIDGE_WEBHOOK_SECRET | sha256sum\n'))

    def test_capturing_it_does_not_silence_it_either(self) -> None:
        """And nor does a command substitution: the pipeline reader and the
        substitution reader must answer this question the same way, which is the
        property `ASecretFileReachesStdoutHoweverItIsSPELLED` pins for the other
        half of the same predicate."""
        self.assertEqual(
            ['argv'],
            self._rules('curl -H "Authorization: Bearer $(printenv BRIDGE_CHANNEL_TOKEN)" '
                        'http://x/\n'))

    def test_every_name_taking_printer_is_reachable_from_the_stdout_arm(self) -> None:
        """The leg lives inside `cmd.name in PRINTING_COMMANDS`, so a member of
        `NAME_TAKING_PRINTERS` that is not also a printing command would be a rule
        that cannot fire — the shape this card exists to remove."""
        self.assertTrue(lint.NAME_TAKING_PRINTERS <= lint.PRINTING_COMMANDS)

    def test_the_message_says_it_prints_the_VALUE_not_the_name(self) -> None:
        """A remediation is read by a person: `printenv` writes the VALUE of the
        name it is handed, and the name itself is not the secret."""
        findings = _scan('```bash\nprintenv BRIDGE_WEBHOOK_SECRET\n```\n')
        self.assertIn('the VALUE of BRIDGE_WEBHOOK_SECRET', findings[0].message)
        expansion = _scan('```bash\necho "$BRIDGE_WEBHOOK_SECRET"\n```\n')
        self.assertNotIn('the VALUE of', expansion[0].message)


class ASecretFileReachesStdoutHoweverItIsSPELLED(unittest.TestCase):
    """ONE predicate decides "does this command put a secret FILE on stdout".

    It had two divergent copies — `_analyse_pipeline` read `cmd.args +
    cmd.input_paths()` and `_sub_is_secret_bearing` read `cmd.args` alone — so the
    verdict on the SAME act depended on which caller asked. Measured on the pre-fix
    tool: `$(cat "$TOKEN_FILE")` marked its enclosing command and `$(cat <
    "$TOKEN_FILE")` did not, so `cat < "$TOKEN_FILE"` reddened on its own line and
    went GREEN the moment it was captured — a redirection character deciding a
    security verdict, which is the shape of this whole card.

    bash's `$(<file)` is the third spelling of the same act and was never handled at
    all: the parsed command has an empty NAME and the path only in `input_paths()`.
    """

    def _rules(self, body: str) -> list[str]:
        return sorted(f.rule for f in _scan(f'```bash\n{body}```\n'))

    def test_all_three_spellings_of_a_captured_secret_read_are_an_argv_leak(self) -> None:
        """The twin of `TheFiveKnownMembers`' DL-321 bearer member, once per
        spelling. The first is the form that already reddened — it is the control:
        the redirect is the only variable between it and the two that did not."""
        for body in (
            'curl -H "Authorization: Bearer $(cat "$TOKEN_FILE")" http://x/\n',
            'curl -H "Authorization: Bearer $(cat < "$TOKEN_FILE")" http://x/\n',
            'curl -H "Authorization: Bearer $(<"$TOKEN_FILE")" http://x/\n',
        ):
            with self.subTest(body=body.strip()):
                self.assertEqual(['argv'], self._rules(body))

    def test_a_captured_secret_read_printed_back_is_a_stdout_leak(self) -> None:
        """`echo "$(cat < f)"` — the same divergence seen through the printing arm
        rather than the argv one."""
        for body in (
            'echo "$(cat "$TOKEN_FILE")"\n',
            'echo "$(cat < "$TOKEN_FILE")"\n',
            'echo "$(<"$TOKEN_FILE")"\n',
        ):
            with self.subTest(body=body.strip()):
                self.assertEqual(['stdout'], self._rules(body))

    def test_the_finding_names_the_substitution_the_doc_actually_contains(self) -> None:
        findings = _scan('```bash\ncurl -H "Authorization: Bearer $(<"$TOKEN_FILE")" '
                         'http://x/\n```\n')
        self.assertEqual(['argv'], [f.rule for f in findings])
        self.assertIn('$(<"$TOKEN_FILE")', findings[0].message)

    def test_reading_a_secret_file_INTO_A_VARIABLE_is_still_the_prescribed_form(self) -> None:
        """The green control, in all three spellings: `docs/multi-host.md` reads a
        secret file into a variable ON PURPOSE, and a tool that reddened on that
        would force a doc to change to satisfy a rule it already follows."""
        for body in (
            'export BRIDGE_CHANNEL_TOKEN="$(cat "$TOKEN_FILE")"\n',
            'export BRIDGE_CHANNEL_TOKEN="$(cat < "$TOKEN_FILE")"\n',
            'export BRIDGE_CHANNEL_TOKEN="$(<"$TOKEN_FILE")"\n',
        ):
            with self.subTest(body=body.strip()):
                self.assertEqual([], self._rules(body))

    def test_a_captured_DIGEST_of_a_secret_file_stays_green(self) -> None:
        """The other green control, and it is the one that proves the predicate is
        about READERS and not about the redirect: `sha256sum` is not a reading
        command, so the same `< "$TOKEN_FILE"` is the form the rule PRESCRIBES —
        `docs/config-schema.md` says compare digests, never values."""
        self.assertEqual([], self._rules('echo "$(sha256sum < "$TOKEN_FILE")"\n'))

    def test_a_bare_input_redirect_outside_a_substitution_prints_nothing(self) -> None:
        """`$(<f)` reads the file; a bare `< f` as a whole command opens it and
        prints nothing, so the empty-NAME leg must stay inside the substitution
        predicate. This is the case that says the leg is not a blanket widening."""
        self.assertEqual([], self._rules('< "$TOKEN_FILE"\n'))


class RedirectionCannotBeFakedFromInsideAQuote(unittest.TestCase):
    """A `>` inside a quoted argument must not read as a redirection.

    Found by review, and the direction matters: an invented redirect makes a
    PRINTED secret look CAPTURED, so the finding disappears. A lint whose findings
    can be silenced by an unrelated character in a JSON payload is worse than one
    that over-reports, because nothing announces the loss. Redirections are
    therefore read off quote-aware TOKENS, never off the raw line.
    """

    def _rules(self, body: str) -> list[str]:
        return sorted(f.rule for f in _scan(f'```bash\n{body}```\n'))

    def test_a_gt_inside_a_single_quoted_payload_is_not_a_redirect(self) -> None:
        self.assertEqual(
            ['stdout'],
            self._rules('echo "$SECRET" \'{"note":">not a redirect"}\'\n'))

    def test_a_gt_inside_a_double_quoted_payload_is_not_a_redirect(self) -> None:
        self.assertEqual(['stdout'], self._rules('echo "$SECRET is > nothing"\n'))

    def test_a_curl_payload_containing_a_gt_still_reds_on_its_bearer(self) -> None:
        self.assertEqual(
            ['argv'],
            self._rules('curl -H "Authorization: Bearer $TOKEN" -d \'{"x":">"}\' http://x/\n'))

    def test_a_stderr_dup_is_not_split_in_half(self) -> None:
        """`2>&1` was cut at the `&` by the segment splitter, dropping the redirect
        with it. Both of these are green ONLY if the `> f` survived the split."""
        self.assertEqual([], self._rules('printf \'%s\' "$SECRET" > f 2>&1\n'))
        self.assertEqual([], self._rules('printf \'%s\' "$SECRET" >> f 2>&1\n'))


class AnApostropheInsideDoubleQUOTESIsNotAQuote(unittest.TestCase):
    """ONE quote-state walk, because eight copies of it did not agree.

    Six of the eight guarded the single-quote toggle with `not in_double`. The two
    that decide a VERDICT — `_secret_expansions`, which every value-bearing rule is
    built on, and `_mask_substitutions` — tracked `in_single` alone and toggled on
    any `\'`, including one inside `"…"` where bash holds it literal. An apostrophe
    therefore opened a single-quoted region the shell does not have, and everything
    after it in that token (or, for the masker, in that LOGICAL LINE) went silently
    green.

    Measured on the pre-fix tool, on the very shape DL-321 removed from
    `docs/multi-host.md`: `echo "Save this token securely: $BRIDGE_CHANNEL_TOKEN"`
    reddened and `echo "Don\'t lose it — save this token: $BRIDGE_CHANNEL_TOKEN"`
    did not. The same silent green covered the argv, log, probe and pipeline-tail
    rules, and through the masker it reached ACROSS commands: an apostrophe in an
    earlier command on the line turned off substitution masking for the rest of it.
    """

    def _rules(self, body: str) -> list[str]:
        return sorted(f.rule for f in _scan(f'```bash\n{body}```\n'))

    def test_the_apostrophe_is_the_only_variable_between_a_red_and_a_red(self) -> None:
        """The discriminator, pinned in BOTH directions on one payload: adding an
        apostrophe inside the double quotes must not change the verdict, and a
        genuinely single-quoted payload must still be green because the shell
        expands nothing there."""
        self.assertEqual(['stdout'], self._rules('echo "it\'s $SECRET"\n'))
        self.assertEqual(['stdout'], self._rules('echo "its $SECRET"\n'))
        self.assertEqual([], self._rules('echo \'it"s $SECRET\'\n'))

    def test_the_live_member_reds_with_an_apostrophe_in_the_message(self) -> None:
        self.assertEqual(['stdout'], self._rules(
            'echo "Save this token securely: $BRIDGE_CHANNEL_TOKEN"\n'))
        self.assertEqual(['stdout'], self._rules(
            'echo "Don\'t lose it — save this token: $BRIDGE_CHANNEL_TOKEN"\n'))

    def test_every_value_bearing_rule_survives_an_apostrophe(self) -> None:
        """Each of these was measured GREEN before the walk was hoisted, and each
        is the SAME rule the suite already pins on an apostrophe-free payload."""
        for rule, body in (
            ('argv', 'curl -H "it\'s: $BRIDGE_CHANNEL_TOKEN" http://x/\n'),
            ('log', 'printf \'%s\' "it\'s: $SECRET" >> /var/log/x.log\n'),
            ('probe', 'echo "it\'s me: ${SECRET:-unset}"\n'),
            ('stdout', 'printf \'%s\' "don\'t: $SECRET" | base64\n'),
        ):
            with self.subTest(rule=rule, body=body.strip()):
                self.assertEqual([rule], self._rules(body))

    def test_an_apostrophe_in_an_EARLIER_command_does_not_disarm_the_line(self) -> None:
        """The masker\'s copy ran over the whole logical line, so the damage was not
        confined to the token that spelled the apostrophe: with masking off, a
        `$( … )` is never masked, `_sub_is_secret_bearing` is never asked, and a
        captured secret READ stops being a secret anywhere on that line."""
        leak = 'curl -H "Bearer $(cat "$TOKEN_FILE")" http://x/\n'
        self.assertEqual(['argv'], self._rules('echo "its hi" ; ' + leak))
        self.assertEqual(['argv'], self._rules('echo "it\'s hi" ; ' + leak))

    def test_single_quoting_still_suppresses_what_the_shell_suppresses(self) -> None:
        """The negative half, which is the reason the walk tracks quoting at all:
        inside `\'…\'` the shell expands nothing, and a tool that reddened there
        would red on `awk \'{print $NF}\'`."""
        self.assertEqual([], self._rules("awk \'{print $NF}\' /etc/hosts\n"))
        self.assertEqual([], self._rules("echo \'nothing $SECRET here\'\n"))

    def test_the_quote_state_toggle_exists_in_exactly_one_place(self) -> None:
        """The consolidation, asserted rather than described. There are no
        hand-rolled copies left; a fresh one would be free to disagree with the one
        walk exactly as the two that decided a VERDICT disagreed with the six that
        did not, and nothing but this would notice. Counting CONSUMERS is the thing
        this deliberately does not do — that number moved from eight to nine the
        moment a here-string reader joined, and three prose surfaces went stale with
        it while this test stayed true."""
        with open(_TOOL, encoding='utf-8') as fh:
            source = fh.read()
        toggles = [ln.strip() for ln in source.split('\n')
                   if '= not ' in ln and ('in_single' in ln or 'in_double' in ln)]
        self.assertEqual(['self.in_single = not self.in_single',
                          'self.in_double = not self.in_double'], toggles)


class TheNegativeHalfOfTheVocabulary(unittest.TestCase):
    """The predicates that decide what is NOT a secret. Each of these reddened at
    some point during construction; a lint that reds on ordinary text is one that
    gets switched off, which is the only failure mode worse than a silent one."""

    def _rules(self, body: str) -> list[str]:
        return sorted(f.rule for f in _scan(f'```bash\n{body}```\n'))

    def test_a_word_that_merely_mentions_a_secret_is_not_a_path(self) -> None:
        self.assertEqual([], self._rules('grep -rn token docs/\n'))
        self.assertEqual([], self._rules('grep secret README.md\n'))

    def test_the_system_password_database_is_not_a_secret_store(self) -> None:
        self.assertEqual([], self._rules('cat /etc/passwd\n'))

    def test_a_name_ending_in_a_path_word_holds_a_path_not_a_value(self) -> None:
        for name in ('TOKEN_FILE', 'SECRET_PATH', 'BRIDGE_SECRET_DIR', 'TOKEN_URL'):
            with self.subTest(name=name):
                self.assertEqual([], self._rules(f'dirname "${name}"\n'))

    def test_key_alone_is_too_common_a_word_to_mean_a_secret(self) -> None:
        self.assertEqual([], self._rules('jq ".[$SORT_KEY]" <<<"$JSON"\n'))
        self.assertEqual([], self._rules('echo "$CACHE_KEY"\n'))
        # …but a QUALIFIED one is a secret, so the exclusion is narrow, not blanket.
        self.assertEqual(['stdout'], self._rules('echo "$SSH_KEY"\n'))
        self.assertEqual(['argv'], self._rules('openssl pkey -passin "pass:$API_KEY"\n'))


class TheWaiver(unittest.TestCase):
    """The escape hatch, and the one property that keeps it honest."""

    def test_a_waiver_with_a_reason_suppresses_the_next_command(self) -> None:
        body = ('```bash\n'
                '# doc-fence-lint: allow the pre-DL-322 form, shown to explain the fix\n'
                'openssl dgst -sha256 -hmac "$SECRET" -hex\n'
                '```\n')
        self.assertEqual([], _scan(body))

    def test_the_waiver_covers_one_command_and_not_the_rest_of_the_fence(self) -> None:
        body = ('```bash\n'
                '# doc-fence-lint: allow the pre-DL-322 form, shown to explain the fix\n'
                'openssl dgst -sha256 -hmac "$SECRET" -hex\n'
                'echo "$BRIDGE_CHANNEL_TOKEN"\n'
                '```\n')
        self.assertEqual(['stdout'], [f.rule for f in _scan(body)])

    def test_a_waiver_with_no_reason_is_itself_reported(self) -> None:
        body = ('```bash\n'
                '# doc-fence-lint: allow\n'
                'echo "$SECRET"\n'
                '```\n')
        rules = sorted(f.rule for f in _scan(body))
        self.assertIn('waiver-no-reason', rules)

    def test_a_blank_line_drops_the_pending_waiver(self) -> None:
        """The docstring says the waiver goes on the line BEFORE the command. It
        survived any number of blank lines, so a waiver at the top of a fence
        covered the first command however far below it — an off-switch whose scope
        the reader cannot see from the line it is written on."""
        body = ('```bash\n'
                '# doc-fence-lint: allow the pre-DL-322 form, shown to explain the fix\n'
                '\n'
                'echo "$SECRET"\n'
                '```\n')
        self.assertEqual(['stdout'], [f.rule for f in _scan(body)])

    def test_the_waiver_still_covers_the_line_immediately_below_it(self) -> None:
        """The control for the line above: the drop must be caused by the BLANK
        line, not by the waiver having stopped working."""
        body = ('```bash\n'
                '# doc-fence-lint: allow the pre-DL-322 form, shown to explain the fix\n'
                'echo "$SECRET"\n'
                '```\n')
        self.assertEqual([], _scan(body))

    def test_a_reasonless_waiver_does_not_silently_suppress(self) -> None:
        """The property that matters: an off-switch nobody justified must not
        also WORK. Without this the reasonless case would report a nit and still
        hide the leak."""
        body = ('```bash\n'
                '# doc-fence-lint: allow\n'
                'echo "$SECRET"\n'
                '```\n')
        self.assertIn('stdout', sorted(f.rule for f in _scan(body)))


class AddingAPipeStageMustNotSilenceALeak(unittest.TestCase):
    """The pipeline-TAIL predicate — its members, and (mostly) its DEFAULT.

    Measured on the pre-fix tool: `base64 "$TOKEN_FILE"` reddened while
    `cat "$TOKEN_FILE" | base64` did not, and nor did `| sed`, `| awk`, `| cut`,
    `| grep`, `| jq`, `| xxd`, `| fold`. The list enumerated the LEAKING tails, so
    every command nobody had thought to list read as safe and ONE pipe stage was
    the whole evasion. `STDIN_SINKS` inverted it: a tail leaks unless it is a known
    sink. So the cases that matter most here are the ones that name no member at
    all — an unlisted tail must red, because the gap in an enumeration is what the
    next author will spell.
    """

    def _rules(self, body: str) -> list[str]:
        return sorted(f.rule for f in _scan(f'```bash\n{body}```\n'))

    #: Every one of these was GREEN before the inversion.
    SILENCED_BY_ONE_PIPE = [
        'cat "$TOKEN_FILE" | base64\n',
        'cat "$BRIDGE_DIR/kanban/writeback-token" | grep .\n',
        'echo "$BRIDGE_SECRET" | base64\n',
        'printf \'%s\' "$BRIDGE_SECRET" | sed \'s/a/b/\'\n',
        'printf \'%s\' "$SECRET" | awk \'{print}\'\n',
        'printf \'%s\' "$SECRET" | cut -c1-8\n',
        'printf \'%s\' "$SECRET" | jq -R .\n',
        'printf \'%s\' "$SECRET" | xxd\n',
        'printf \'%s\' "$SECRET" | fold -w8\n',
        'cat "$TOKEN_FILE" | head -c 8\n',
    ]

    def test_every_tail_that_a_pipe_used_to_silence_now_reds(self) -> None:
        for body in self.SILENCED_BY_ONE_PIPE:
            with self.subTest(body=body.strip()):
                self.assertEqual(['stdout'], self._rules(body))

    def test_the_pipe_is_the_only_variable_between_a_red_and_a_green(self) -> None:
        """The discriminator, pinned on one payload: piping `base64` instead of
        handing it the path must not change the verdict."""
        self.assertEqual(['stdout'], self._rules('base64 "$TOKEN_FILE"\n'))
        self.assertEqual(['stdout'], self._rules('cat "$TOKEN_FILE" | base64\n'))

    def test_a_tail_nobody_enumerated_reds_because_the_default_is_deny(self) -> None:
        """The PROPERTY, not the members. This name is on no list in the program —
        under the old positive enumeration that is exactly what made it safe."""
        self.assertNotIn('zzunlisted-filter', lint.STDIN_SINKS)
        self.assertNotIn('zzunlisted-filter', lint.SHELL_BUILTINS)
        self.assertEqual(['stdout'], self._rules('echo "$SECRET" | zzunlisted-filter\n'))

    def test_reading_a_secret_FILE_into_a_pipe_marks_the_pipeline(self) -> None:
        """`cat "$TOKEN_FILE"` carries no secret VALUE in its argv — what it is
        handed is a PATH — so nothing marked the pipeline and the tail rule read a
        secret-bearing pipeline as clean."""
        self.assertEqual(['stdout'], self._rules('cat "$TOKEN_FILE" | tr -d "\\n"\n'))

    def test_a_trailing_pipe_at_end_of_line_does_not_end_the_pipeline(self) -> None:
        """A pipeline continues on the next line. The splitter ended it there,
        handing the tail a pipeline of its own in which `idx > 0` is false — so the
        one-line form reddened and the wrapped form did not.

        The SEPARATOR is the second half of the same defect. The continuation test
        was applied to the CURRENT physical line rather than to the accumulated
        buffer, so a blank line or a comment line after the trailing `|` matched
        nothing, flushed the buffer, and handed the tail its own pipeline again —
        every body below was measured GREEN, and every one of them is real bash
        that leaks.
        """
        for body in (
            'printf \'%s\' "$SECRET" |\nbase64\n',
            'printf \'%s\' "$SECRET" \\\n  | base64\n',
            'printf \'%s\' "$SECRET" |\n\nbase64\n',
            'printf \'%s\' "$SECRET" |\n# why this pipeline wraps\nbase64\n',
            'echo "$SECRET" |\n\nbase64\n',
            'echo "$SECRET" |\n# a comment between the stages\nbase64\n',
            'cat "$TOKEN_FILE" |\n\nbase64\n',
            'printf \'%s\' "$SECRET" |\n\nsed \'s/a/b/\'\n',
            'printf \'%s\' "$SECRET" | tr -d "\\n" |\n\nbase64\n',
            'printf \'%s\' "$SECRET" \\\n\n  | base64\n',
        ):
            with self.subTest(body=body):
                self.assertEqual(['stdout'], self._rules(body))

    def test_the_continuation_test_reads_the_BUFFER_so_it_covers_and_and(self) -> None:
        """`_CONTINUES_RE` matches `&&` as well as `|` and `\\`, so the same
        buffer-vs-line defect spans an `&&` too. `&&` ends the pipeline either way,
        so no FINDING moves on it — what moves is the extent of the logical line,
        and the waiver is where that is observable: a waiver claims one logical
        line, and the flush made the continuation a second one the waiver never
        covered.
        """
        self.assertRegex('cmd &&', lint._CONTINUES_RE)
        body = ('```bash\n'
                '# doc-fence-lint: allow the pre-DL-321 form, shown to explain it\n'
                'umask 077 &&\n'
                '\n'
                'echo "$SECRET"\n'
                '```\n')
        self.assertEqual([], _scan(body))

    def test_an_and_continuation_still_reds_at_the_line_that_leaked(self) -> None:
        """The other direction of the same widening: joining more lines into one
        logical line must not lose the finding, nor misreport WHERE it is — the
        per-character source map is what makes a joined buffer still name line 4.
        """
        findings = _scan('```bash\numask 077 &&\n\necho "$SECRET"\n```\n')
        self.assertEqual(['stdout'], [f.rule for f in findings])
        self.assertEqual(4, findings[0].line)

    def test_xargs_is_not_a_command_PREFIX_because_it_builds_an_ARGV(self) -> None:
        """`xargs` was in `COMMAND_PREFIXES` beside `sudo`/`timeout`, so it was
        STRIPPED — the tail's name became the wrapped command and the value it
        carries left the picture. But `xargs` is the DL-322 shape itself: it turns
        stdin INTO an argv, which is the one surface `/proc/<pid>/cmdline` makes
        world-readable. All three bodies below were measured GREEN.
        """
        self.assertNotIn('xargs', lint.COMMAND_PREFIXES)
        for body in (
            'printf \'%s\' "$SECRET" | xargs curl -H\n',
            'printf \'%s\' "$SECRET" | xargs sha256sum\n',
            'printf \'%s\' "$SECRET" | xargs php artisan bridge:sign --scope=x\n',
            'cat "$TOKEN_FILE" | xargs sha256sum\n',
        ):
            with self.subTest(body=body.strip()):
                self.assertEqual(['stdout'], self._rules(body))

    def test_a_real_command_prefix_is_still_stripped(self) -> None:
        """The control for the line above: removing `xargs` must not have removed
        the behaviour the set exists for. `sudo sha256sum` still forks sha256sum,
        and the tail is the digest, not the wrapper.

        ⚠ A prefix that takes an OPERAND is a separate, pre-existing bound, pinned
        as BOUND(prefix-operand) by
        `TheDisclosedBoundsAreCHECKEDNotJustStated.test_a_command_PREFIX_that_takes_an_operand_leaves_it_behind`
        rather than restated here — it was disclosed and unexercised until the
        fifth review round, which is the gap that class now guards against as a
        SET rather than as a sentence."""
        for body in (
            'printf \'%s\' "$SECRET" | sudo sha256sum\n',
            'printf \'%s\' "$SECRET" | command sha256sum\n',
            'printf \'%s\' "$SECRET" | nohup sha256sum\n',
        ):
            with self.subTest(body=body.strip()):
                self.assertEqual([], self._rules(body))

    def test_the_finding_names_xargs_and_not_a_brace_placeholder(self) -> None:
        """`-I{}` is not a command group. The splitter treated every `{` and `}` as
        a separator, so `xargs -I{} curl …` reported against a command called
        `-I` — a remediation naming text the doc does not contain."""
        findings = _scan('```bash\nprintf \'%s\' "$SECRET" | '
                         'xargs -I{} curl -H "Authorization: Bearer {}" http://x/\n```\n')
        self.assertEqual(['stdout'], [f.rule for f in findings])
        self.assertIn('`xargs`', findings[0].message)

    def test_an_unquoted_brace_expansion_no_longer_orphans_the_dollar(self) -> None:
        """A second, unlooked-for silent green the same narrowing closed. Splitting
        on every `{` cut `echo ${SECRET}` into `echo $` + `SECRET`, so the segment
        the argv/stdout rules read carried no expansion at all and the line was
        GREEN — while its quoted twin `echo "${SECRET}"` reddened, the two spellings
        disagreeing for no reason a reader could see."""
        self.assertEqual(['stdout'], self._rules('echo ${SECRET}\n'))
        self.assertEqual(['stdout'], self._rules('echo "${SECRET}"\n'))

    def test_the_prescribed_alternative_expansion_survives_the_narrowing(self) -> None:
        """The control in the other direction: `${VAR:+set}` is the form the rule
        PRESCRIBES for testing whether a secret is set, and reading its braces
        differently must not have made it a finding."""
        self.assertEqual([], self._rules('if [ -n "${SECRET:+x}" ]; then echo ok; fi\n'))
        self.assertEqual([], self._rules('cp "$D"/f{,.bak}\n'))

    def test_a_real_brace_group_is_still_a_separator(self) -> None:
        """The control for the brace narrowing: a STANDALONE `{`/`}` is the shell's
        group-command syntax and must keep splitting, or `{ echo "$SECRET"; }`
        reports against a command called `{`."""
        findings = _scan('```bash\n{ echo "$SECRET"; }\n```\n')
        self.assertEqual(['stdout'], [f.rule for f in findings])
        self.assertIn('`echo`', findings[0].message)

    def test_a_tail_that_COMPARES_against_a_secret_file_is_not_exempt(self) -> None:
        """`and not secret_files` disabled the tail rule for ANY tail whose argv
        looked like a secret path — so the "Compare both values" class DL-321 fixed
        came straight back through a pipe. Every body below prints secret-derived
        bytes and every one was measured GREEN. The clause exists only to dedupe a
        tail that is ALSO a reader (`tee`), and it is now scoped to that.
        """
        for body in (
            'printf \'%s\' "$SECRET" | diff - /etc/bridge/webhook-secret-scope\n',
            'printf \'%s\' "$SECRET" | cmp - "$TOKEN_FILE"\n',
            'printf \'%s\' "$SECRET" | diff - "$TOKEN_FILE"\n',
            'cat "$TOKEN_FILE" | grep -f /etc/bridge/webhook-secret-scope\n',
        ):
            with self.subTest(body=body.strip()):
                self.assertEqual(['stdout'], self._rules(body))

    def test_a_reader_tail_holding_a_secret_file_still_reports_once(self) -> None:
        """ONE finding, and the one that is TRUE. `tee` is in READING_COMMANDS and
        is also a passthrough tail, so both legs fire on one command — but its file
        argument is a WRITE target, and the reader diagnosis therefore named the
        wrong direction outright: it said `tee` PUTS the contents of the file it is
        WRITING on stdout, and told the operator to open that file themselves. This
        assertion pinned that sentence; a wrong test is fixed, not preserved. The
        line stays red, on the message that describes what actually leaks."""
        findings = _scan('```bash\nprintf \'%s\' "$SECRET" | '
                         'tee /etc/bridge/webhook-secret-scope\n```\n')
        self.assertEqual(1, len(findings), [f.render() for f in findings])
        self.assertIn('ends at `tee`', findings[0].message)
        self.assertIn('not a known stdin SINK', findings[0].message)
        self.assertNotIn('puts the contents of', findings[0].message)

    def test_a_tail_that_really_READS_a_secret_file_keeps_that_message(self) -> None:
        """The other side of the same discriminator, so the narrowing cannot swing
        the whole way: a tail handed a secret file by REDIRECT does read it onto
        stdout, and that is still the specific diagnosis — still once."""
        findings = _scan('```bash\nprintf \'%s\' "$SECRET" | '
                         'cat < "$TOKEN_FILE"\n```\n')
        self.assertEqual(1, len(findings), [f.render() for f in findings])
        self.assertIn('puts the contents of', findings[0].message)

    def test_a_sink_whose_stdout_IS_its_stdin_is_not_a_sink(self) -> None:
        """The membership test is "this command's stdout is not a function of its
        stdin", and these three failed it while being listed. `dd` with no `of=`
        copies stdin to stdout; `gpg -d` writes the plaintext there; `ssh host cat`
        brings it back over the wire. All three were measured GREEN.
        """
        for name in ('dd', 'gpg', 'gpg2', 'ssh', 'sqlite3', 'psql', 'sponge',
                     'socat', 'age', 'systemd-ask-password'):
            with self.subTest(name=name):
                self.assertNotIn(name, lint.STDIN_SINKS)
        for body in (
            'printf \'%s\' "$SECRET" | dd\n',
            'cat "$TOKEN_FILE" | gpg -d\n',
            'printf \'%s\' "$SECRET" | ssh host cat\n',
        ):
            with self.subTest(body=body.strip()):
                self.assertEqual(['stdout'], self._rules(body))

    def test_every_sink_is_admitted_by_the_rule_or_by_this_repo_s_own_docs(self) -> None:
        """The claim DL-324 makes about this list, asserted rather than restated:
        every member is either an instrument `docs/config-schema.md` § Handling a
        secret VALUE prescribes (a digest; a value fed on stdin) or a spelling this
        repo's own runbooks use. A member admitted by neither is an invention, and
        an invented sink is a SILENT green — the one direction a backstop may not
        be wrong in."""
        digests = {'sha1sum', 'sha224sum', 'sha256sum', 'sha384sum', 'sha512sum',
                   'shasum', 'md5sum', 'b2sum', 'cksum', 'sum'}
        prescribed = {'curl'}
        used_by_this_repo = {'install', 'php', 'artisan', 'openssl'}
        self.assertEqual(digests | prescribed | used_by_this_repo, lint.STDIN_SINKS)

    def test_a_known_stdin_sink_tail_stays_green(self) -> None:
        """The other direction, and the reason this is not blanket default-deny:
        each of these is a form `docs/config-schema.md` PRESCRIBES."""
        for body in (
            'printf \'%s\' "$SECRET" | sha256sum\n',
            'printf \'%s\' "$TOKEN" | install -m 600 /dev/stdin "$D/writeback-token"\n',
            'printf \'header = "Authorization: Bearer %s"\\n\' "$TOKEN" |\n'
            'curl --config - http://127.0.0.1:8788/\n',
            'printf \'%s\' "$SECRET" | php artisan bridge:sign --scope=x\n',
        ):
            with self.subTest(body=body.strip()):
                self.assertEqual([], self._rules(body))

    def test_a_capturing_redirect_on_the_tail_is_still_green(self) -> None:
        """Deny-by-default is about the tail's IDENTITY; a tail whose stdout goes
        to a file captures the value however unknown the command is."""
        self.assertEqual([], self._rules('cat "$TOKEN_FILE" | base64 > /run/secrets/x\n'))
        self.assertEqual(
            [], self._rules('echo "$SECRET" | zzunlisted-filter > /run/secrets/x\n'))

    def test_the_finding_names_the_tail_that_leaked(self) -> None:
        findings = _scan('```bash\ncat "$TOKEN_FILE" | base64\n```\n')
        self.assertEqual(1, len(findings), [f.render() for f in findings])
        self.assertIn('`base64`', findings[0].message)
        self.assertIn('SINK', findings[0].message)

    def test_a_secret_FILE_redirected_into_a_log_is_now_caught_too(self) -> None:
        """A consequence of the same leg, pinned because it changed: `cat` into a
        log file is captured (so no `stdout` finding) and named no secret VALUE in
        its argv (so no `log` finding either) — it was green in both directions."""
        self.assertEqual(
            ['log'], self._rules('cat "$TOKEN_FILE" >> /var/log/provision.log\n'))

    def test_the_control_disabling_stdout_removes_exactly_this_finding(self) -> None:
        """Proof the red comes from the stdout rule and not from somewhere else:
        a case that reds for an unrelated reason would survive this."""
        body = '```bash\ncat "$TOKEN_FILE" | base64\n```\n'
        self.assertEqual(['stdout'], [f.rule for f in _scan(body)])
        self.assertEqual([], _scan(body, set(lint.RULE_IDS) - {'stdout'}))


class AHereStringIsSTDINAndCarriesAValue(unittest.TestCase):
    """`<<<` was deleted before any value rule could look at it.

    `_read_command` cuts the here-string out of the text before tokenizing — right
    for the `argv` rule, because a here-string is stdin and never a token in
    /proc/<pid>/cmdline — and the piece it cut out was then handed to the `history`
    rule ALONE. So every spelling below was measured GREEN while resolving a secret
    VALUE onto stdout or into a log, and the module docstring meanwhile claimed the
    reader "understands … here-strings" (card#8351).

    It is asked through `token_carries_secret`, the same predicate an ARGUMENT goes
    through — not a second parser, which would be a second place for the answer to
    drift.
    """

    def _rules(self, body: str) -> list[str]:
        return sorted(f.rule for f in _scan(f'```bash\n{body}```\n'))

    def test_a_here_string_onto_stdout_is_a_stdout_leak(self) -> None:
        for body in (
            'cat <<<"$BRIDGE_WEBHOOK_SECRET"\n',
            'base64 <<<"$SECRET"\n',
            'tr -d "\\n" <<<"$SECRET"\n',
        ):
            with self.subTest(body=body.strip()):
                self.assertEqual(['stdout'], self._rules(body))

    def test_a_here_string_into_a_log_is_a_log_leak(self) -> None:
        self.assertEqual(['log'], self._rules('cat <<<"$SECRET" >> /var/log/x.log\n'))

    def test_a_captured_secret_READ_in_a_here_string_counts_too(self) -> None:
        """The value need not be spelled `$SECRET`: a substitution that puts a
        secret FILE on its stdout is the same act, and the pipeline mark already
        knew it — the here-string was simply never asked."""
        self.assertEqual(['stdout'], self._rules('cat <<<"$(cat "$TOKEN_FILE")"\n'))

    def test_a_here_string_marks_the_pipeline_for_the_tail_rule(self) -> None:
        findings = _scan('```bash\ncat <<<"$SECRET" | base64\n```\n')
        self.assertEqual(['stdout'], [f.rule for f in findings])
        self.assertIn('`base64`', findings[0].message)

    def test_a_here_string_into_a_known_SINK_stays_green(self) -> None:
        """Deny-by-default is about the command's identity, and it is the same
        list here as for a pipeline tail. `sha256sum <<<"$(cat "$TOKEN_FILE")"` was
        green before this leg existed and is green after it — measured, not
        assumed, because a leg that reddens a form the rule PRESCRIBES is how a
        lint gets switched off."""
        self.assertEqual([], self._rules('sha256sum <<<"$SECRET"\n'))
        self.assertEqual([], self._rules('sha256sum <<<"$(cat "$TOKEN_FILE")"\n'))

    def test_a_here_string_holding_a_PATH_is_not_a_value(self) -> None:
        self.assertEqual([], self._rules('cat <<<"$TOKEN_FILE"\n'))

    def test_it_is_never_reported_as_an_argv_leak(self) -> None:
        """The reason the here-string is cut out before tokenizing, kept: the
        parent shell writes it to the child's STDIN, so /proc/<pid>/cmdline carries
        nothing. A message saying otherwise would be false."""
        self.assertNotIn('argv', self._rules('cat <<<"$BRIDGE_WEBHOOK_SECRET"\n'))
        self.assertEqual(['stdout'], self._rules('cat <<<"$BRIDGE_WEBHOOK_SECRET"\n'))
        # The same value handed over as an ARGUMENT does reach the argument list.
        self.assertIn('argv', self._rules('cat "$BRIDGE_WEBHOOK_SECRET"\n'))

    def test_a_quoted_LITERAL_that_merely_SPELLS_one_is_not_a_here_string(self) -> None:
        """`<<<` was located by a regex over the raw text, which cannot tell a
        here-string from a quoted string that spells one — the class
        `_REDIRECT_TOKEN_RE` avoids by reading redirects off TOKENS. Harmless while
        the operand fed only the `history` rule, and a FINDING the moment it fed
        the value rules: both of these reddened on the intermediate build, against
        text the shell never expands."""
        self.assertEqual([], self._rules(
            'echo \'a <<<"$SECRET" b\' >> /var/log/x.log\n'))
        self.assertEqual([], self._rules('grep \'<<<"$SECRET"\' f\n'))
        # The same operator UNQUOTED is a here-string, and does red.
        self.assertEqual(['log'], self._rules('cat <<<"$SECRET" >> /var/log/x.log\n'))

    def test_the_operand_runs_to_an_unquoted_space_and_the_LAST_one_wins(self) -> None:
        """Two bounds of the operand read. `<<<"one two $SECRET"` is ONE operand,
        not the first word of one — cut at the first space, the rest would have
        been read as ARGUMENTS and reported under `argv`, a surface a here-string
        never reaches. And where a command carries more than one, the shell feeds
        it the LAST, so that is the one read."""
        self.assertEqual(['stdout'], self._rules('cat <<<"one two $SECRET"\n'))
        self.assertEqual(['stdout'], self._rules('cat <<<"$A" <<<"$SECRET"\n'))
        self.assertEqual([], self._rules('cat <<<"$SECRET" <<<"$A"\n'))

    def test_the_history_leg_still_owns_the_LITERAL_spelling(self) -> None:
        """The one leg that already read here-strings, unchanged: a LITERAL payload
        is a shell-history leak, not a stdout one, and it must not now report as
        both."""
        self.assertEqual(
            ['history'],
            self._rules('install -m 600 /dev/stdin "$D/kanban/writeback-token" '
                        "<<<'<the-token>'\n"))


class TheDisclosedBoundsAreCHECKEDNotJustStated(unittest.TestCase):
    """A bound named in prose and asserted nowhere is a claim, not a bound.

    ONE PIN PER BOUND, AND THE MAPPING IS CHECKED RATHER THAN COUNTED. The module
    docstring tags every bound it discloses `BOUND(<slug>)`; `PINS` below maps each
    slug to the test that pins it, and
    `test_the_bounds_NAMED_upstairs_are_exactly_the_bounds_pinned_here` asserts the
    two sets are equal in BOTH directions. That guard is the repair, and the thing
    it repairs is a COUNT: this docstring, DL-324 and `docs/CHANGELOG.md` each said
    "the five disclosed bounds ... now have one" while ELEVEN of nineteen had no
    pin at all — and before that this same docstring said "the two" while asserting
    one. A count is a second thing to keep true, and every bound added falsifies it
    without touching a line of it. A set that is checked cannot go stale quietly.

    Each pin fixes the CURRENT behaviour, so closing a bound reds this class and
    forces the disclosure to be rewritten instead of being left saying the bound is
    still open. A green that only ever passes proves nothing, so each case also
    carries the payload that DOES red — the same bytes with the blockquote marker
    removed, the same command without the function around it — which is what makes
    the green a measurement of the bound rather than of the fixture.

    WHAT EACH PIN'S GREEN IS WORTH. Where a one-line mutation can CLOSE a bound,
    the pin was watched failing under it (`COMMAND_PREFIXES` losing `timeout`,
    `STDIN_SINKS` losing `openssl`, `READING_COMMANDS` losing `tee`, the splitter's
    separator set losing `(){}`, and either half of the tag↔pin mapping above).
    Where no cheap mutation exists — an indented-block parser, function / loop /
    alias / `eval` resolution, following a value across an assignment, a
    whole-environment printer arm — the RED TWIN in the same test is what the green
    is measured against, and it is the only thing standing between these cases and
    a suite of assertions that pass because nothing was scanned.

    ⚠ TWO OF THESE ARE FALSE POSITIVES, NOT SILENT GREENS, and are pinned in that
    direction on purpose: `prefix-operand` reds naming a command `5`, and
    `tee-outside-a-pipeline` renders a message that names the wrong direction. Each
    is loud and waivable, which is why it is disclosed rather than fixed; the pin
    exists so that fixing it forces the disclosure to move with it.
    """

    #: slug in the module docstring's `BOUND(<slug>)` tag → the test that pins it.
    #: A dotted name is a pin that already lived in another class, because the bound
    #: is about what is READ rather than about how shell is parsed; it is referenced
    #: here rather than copied, so there is still exactly one pin per bound.
    PINS = {
        'not-markdown': 'ThisCheckoutIsGreen.test_the_fixture_is_out_of_the_default_walk',
        'indented-block': 'test_a_four_space_INDENTED_block_is_not_a_fence',
        'non-shell-info': 'ProseIsNeverRead.test_a_non_shell_fence_is_not_executable',
        'pandoc-info': 'test_a_pandoc_or_quarto_INFO_STRING_reads_as_a_non_shell_fence',
        'blockquote-fence': 'test_a_fence_inside_a_BLOCKQUOTE_is_not_seen_as_a_fence_at_all',
        'variable-routing': 'test_a_value_routed_through_several_VARIABLES_is_not_followed',
        'function': 'test_a_value_routed_through_a_FUNCTION_parameter_is_not_followed',
        'loop': 'test_a_value_routed_through_a_LOOP_variable_is_not_followed',
        'alias': 'test_an_ALIAS_body_is_never_resolved',
        'eval': 'test_an_eval_STRING_OPERAND_is_not_recursed_into',
        'sh-c-operand': 'test_a_single_quoted_sh_c_OPERAND_is_not_recursed_into',
        'heredoc': 'test_a_heredoc_body_is_read_as_commands_in_BOTH_quoting_forms',
        'no-fd-table': 'test_there_is_no_fd_TABLE_so_an_opened_secret_file_is_lost',
        'nested-substitution':
            'test_a_NESTED_substitution_is_read_one_level_deep_on_the_FILE_leg',
        'group-tail': 'test_a_pipeline_TAIL_that_is_a_GROUP_or_SUBSHELL_loses_the_mark',
        'prefix-operand': 'test_a_command_PREFIX_that_takes_an_operand_leaves_it_behind',
        'bare-env-dump': 'test_a_WHOLE_ENVIRONMENT_dump_names_nothing_the_walk_can_find',
        'bare-printer-dump': 'test_a_bare_PRINTER_with_no_operand_is_the_same_bound',
        'sink-in-another-mode': 'test_a_SINK_whose_stdout_is_its_stdin_in_another_MODE',
        'tee-outside-a-pipeline':
            'test_tee_OUTSIDE_a_pipeline_still_names_the_wrong_direction',
    }

    def _rules(self, body: str) -> list[str]:
        return sorted(f.rule for f in _scan(f'```bash\n{body}```\n'))

    def test_the_bounds_NAMED_upstairs_are_exactly_the_bounds_pinned_here(self) -> None:
        """The drift guard the three counts should have been.

        Both failures this class has already had ran the SAME way — a bound
        disclosed with no pin — so `disclosed <= PINS` alone would have caught
        them. The other direction is here for the failure that has not happened
        yet: a bound CLOSED, its tag deleted, and a pin left behind asserting
        behaviour the tool no longer has, which is a green that means nothing.
        """
        with open(_TOOL, encoding='utf-8') as fh:
            disclosed = set(re.findall(r'BOUND\(([a-z-]+)\)', fh.read()))
        self.assertEqual(disclosed, set(self.PINS),
                         'the module docstring and this class disagree about which '
                         'bounds exist')
        for slug, ref in sorted(self.PINS.items()):
            owner_name, _, method = ref.rpartition('.')
            owner = globals()[owner_name] if owner_name else type(self)
            with self.subTest(bound=slug):
                self.assertTrue(hasattr(owner, method), f'{ref} does not exist')

    def test_a_four_space_INDENTED_block_is_not_a_fence(self) -> None:
        """Only fences are found, so an indented block is invisible rather than
        merely unparsed. The same bytes inside a fence red."""
        self.assertEqual([], [f.rule for f in _scan(
            'Read it back yourself:\n\n    echo "$BRIDGE_WEBHOOK_SECRET"\n\ndone.\n')])
        self.assertEqual(['stdout'], self._rules('echo "$BRIDGE_WEBHOOK_SECRET"\n'))

    def test_a_value_routed_through_several_VARIABLES_is_not_followed(self) -> None:
        """Only a NAME the vocabulary recognises is read as holding a secret, so one
        assignment is enough to lose the value."""
        self.assertEqual([], self._rules('X="$SECRET"\necho "$X"\n'))
        self.assertEqual([], self._rules('X="$SECRET"\nY="$X"\necho "$Y"\n'))
        self.assertEqual(['stdout'], self._rules('echo "$SECRET"\n'))

    def test_a_value_routed_through_a_FUNCTION_parameter_is_not_followed(self) -> None:
        """A function BODY's commands are read as ordinary top-level commands — so a
        body that NAMES a secret still reds, and this bound is not "functions are
        invisible". What is lost is the value handed in as a PARAMETER."""
        self.assertEqual([], self._rules('show() { cat "$1"; }\nshow "$TOKEN_FILE"\n'))
        self.assertEqual(['stdout'], self._rules('cat "$TOKEN_FILE"\n'))
        self.assertEqual(['stdout'], self._rules('show() { cat "$TOKEN_FILE"; }\nshow\n'))

    def test_a_value_routed_through_a_LOOP_variable_is_not_followed(self) -> None:
        """The same act through a loop variable rather than a parameter."""
        self.assertEqual([], self._rules(
            'for f in "$TOKEN_FILE"; do cat "$f"; done\n'))
        self.assertEqual(['stdout'], self._rules('cat "$TOKEN_FILE"\n'))

    def test_an_ALIAS_body_is_never_resolved(self) -> None:
        """`alias` is a shell builtin, so the `argv` rule skips it, and it is not a
        printing command, so the `stdout` rule has nothing to read. The call site
        carries no expansion at all."""
        for body in ('alias leak=\'echo "$SECRET"\'\nleak\n',
                     'alias leak="echo $SECRET"\nleak\n'):
            with self.subTest(body=body.strip()):
                self.assertEqual([], self._rules(body))
        self.assertEqual(['stdout'], self._rules('echo "$SECRET"\n'))

    def test_an_eval_STRING_OPERAND_is_not_recursed_into(self) -> None:
        """Read as an ordinary argument, exactly as `sh -c`'s is — and because
        `eval` is a builtin the `argv` rule does not report it either, so all three
        spellings are green."""
        for body in ('eval "echo $SECRET"\n',
                     'eval "echo \\$SECRET"\n',
                     "eval 'echo \"$SECRET\"'\n"):
            with self.subTest(body=body.strip()):
                self.assertEqual([], self._rules(body))
        self.assertEqual(['stdout'], self._rules('echo "$SECRET"\n'))

    def test_a_pipeline_TAIL_that_is_a_GROUP_or_SUBSHELL_loses_the_mark(self) -> None:
        """`_split_segments` ends the pipeline at the standalone `{` / `(`, so the
        tail begins a pipeline of its own where `idx > 0` is false and the tail rule
        never runs. `|&` is a third spelling: it reads as `|` followed by a command
        named `&`, so the line reds naming text no doc contains."""
        self.assertEqual([], self._rules('echo "$SECRET" | { base64; }\n'))
        self.assertEqual([], self._rules('echo "$SECRET" | (base64)\n'))
        self.assertEqual(['stdout'], self._rules('echo "$SECRET" | base64\n'))
        amp = _scan('```bash\necho "$SECRET" |& base64\n```\n')
        self.assertEqual(['stdout'], [f.rule for f in amp])
        self.assertIn('ends at `&`', amp[0].message)

    def test_a_command_PREFIX_that_takes_an_operand_leaves_it_behind(self) -> None:
        """A FALSE POSITIVE, pinned in the loud direction. The wrapper is dropped and
        its operand is not, so the tail's name becomes `5` — the line still reds,
        which is why this is disclosed rather than fixed, and the message names a
        command the doc does not contain. The control is a prefix that takes NO
        operand (`sudo`), which is stripped correctly and stays green — so the red
        above is the operand's doing and not the stripping's."""
        findings = _scan('```bash\nprintf \'%s\' "$SECRET" | timeout 5 sha256sum\n```\n')
        self.assertEqual(['stdout'], [f.rule for f in findings])
        self.assertIn('ends at `5`', findings[0].message)
        self.assertEqual([], self._rules('printf \'%s\' "$SECRET" | sudo sha256sum\n'))

    def test_a_WHOLE_ENVIRONMENT_dump_names_nothing_the_walk_can_find(self) -> None:
        """Bare `env` / `printenv` resolves every value while naming none, so there
        is no `$`-expansion and no secret NAME for any rule to read. Given an
        OPERAND `printenv` reds — the control that says this green is the bound and
        not a broken vocabulary."""
        for body in ('env\n', 'printenv\n', 'env | grep SECRET\n'):
            with self.subTest(body=body.strip()):
                self.assertEqual([], self._rules(body))
        self.assertEqual(['stdout'], self._rules('printenv BRIDGE_WEBHOOK_SECRET\n'))

    def test_a_bare_PRINTER_with_no_operand_is_the_same_bound(self) -> None:
        """`declare -p` and friends with no operand dump the whole set."""
        for body in ('declare -p\n', 'typeset -p\n', 'export -p\n', 'set\n'):
            with self.subTest(body=body.strip()):
                self.assertEqual([], self._rules(body))
        self.assertEqual(['stdout'],
                         self._rules('declare -p BRIDGE_WEBHOOK_SECRET\n'))

    def test_a_SINK_whose_stdout_is_its_stdin_in_another_MODE(self) -> None:
        """Membership in STDIN_SINKS is per-COMMAND, not per-mode. `openssl` is
        listed because `dgst` is a form the rule prescribes, so `| openssl base64`
        prints the value and is green. Its argv-borne twin — the DL-322 member — is
        caught by the `argv` rule and does not depend on this leg, which is what
        keeps the bound narrow."""
        self.assertEqual([], self._rules('printf \'%s\' "$SECRET" | openssl base64\n'))
        self.assertEqual([], self._rules(
            'printf \'%s\' "$SECRET" | openssl dgst -sha256\n'))
        self.assertEqual(['stdout'], self._rules('printf \'%s\' "$SECRET" | base64\n'))
        self.assertEqual(['argv'],
                         self._rules('openssl dgst -sha256 -hmac "$SECRET" -hex\n'))

    def test_tee_OUTSIDE_a_pipeline_still_names_the_wrong_direction(self) -> None:
        """The second false positive, and the one whose MESSAGE is false: `tee <path>`
        on its own WRITES that path, and the reader message says it puts the file's
        contents on stdout. Inside a secret-carrying pipeline the tail message — the
        true one — fires instead, which is the fix DL-324's fourth round made and
        the reason this remainder is worth pinning rather than assuming closed."""
        alone = _scan('```bash\ntee /etc/bridge/webhook-secret-scope-kanban\n```\n')
        self.assertEqual(['stdout'], [f.rule for f in alone])
        self.assertIn('puts the contents of', alone[0].message)
        piped = _scan('```bash\nprintf \'%s\' "$SECRET" | '
                      'tee /etc/bridge/webhook-secret-scope-kanban\n```\n')
        self.assertEqual(['stdout'], [f.rule for f in piped])
        self.assertIn('ends at `tee`', piped[0].message)

    def test_a_heredoc_body_is_read_as_commands_in_BOTH_quoting_forms(self) -> None:
        """The docstring used to scope this to an UNQUOTED `<<EOF`. The tool tracks
        no heredocs at all, so a `<<'EOF'` body — where the shell expands NOTHING —
        reports the same `argv` finding, and that finding's text is false for the
        quoted form. Wording, not the parser: fixing the parser changes what CI
        rejects, and it fires on no doc in this repo at any tag."""
        for body in (
            'cat <<EOF > /etc/bridge/app.conf\nsecret = "$BRIDGE_SECRET"\nEOF\n',
            "cat <<'EOF' > /etc/bridge/app.conf\nsecret = \"$BRIDGE_SECRET\"\nEOF\n",
        ):
            with self.subTest(body=body.strip()):
                self.assertEqual(['argv'], self._rules(body))

    def test_a_fence_inside_a_BLOCKQUOTE_is_not_seen_as_a_fence_at_all(self) -> None:
        """An opener is matched after leading WHITESPACE only, so `> ` in front of
        the backticks makes the whole body invisible rather than merely unparsed."""
        self.assertEqual([], [f.rule for f in _scan(
            '> ```bash\n> echo "$BRIDGE_WEBHOOK_SECRET"\n> ```\n')])
        self.assertEqual(['stdout'], self._rules('echo "$BRIDGE_WEBHOOK_SECRET"\n'))

    def test_a_pandoc_or_quarto_INFO_STRING_reads_as_a_non_shell_fence(self) -> None:
        """Braces around the language are not in SHELL_INFO."""
        for info in ('{bash}', '{.bash}', '{sh}'):
            with self.subTest(info=info):
                self.assertEqual([], [f.rule for f in _scan(
                    f'```{info}\necho "$BRIDGE_WEBHOOK_SECRET"\n```\n')])
        self.assertEqual(['stdout'], self._rules('echo "$BRIDGE_WEBHOOK_SECRET"\n'))

    def test_there_is_no_fd_TABLE_so_an_opened_secret_file_is_lost(self) -> None:
        """`exec 3<` a secret file and the reading command's input path is `&3`;
        nothing carries the opener's path forward to it. Spelled without the fd —
        the same act — it reds."""
        self.assertEqual([], self._rules(
            'exec 3< /etc/bridge/webhook-secret-scope\ncat <&3\n'))
        self.assertEqual(['stdout'], self._rules(
            'cat < /etc/bridge/webhook-secret-scope\n'))

    def test_a_single_quoted_sh_c_OPERAND_is_not_recursed_into(self) -> None:
        """A `-c` string is read as an ordinary argument, exactly as `eval`'s is.
        The double-quoted twin reds under `argv`, and that is not the bound but the
        correct answer: the PARENT shell expands it into the child's argv before
        the child starts."""
        for body in (
            'sh -c \'echo "$BRIDGE_WEBHOOK_SECRET"\'\n',
            'bash -c \'echo "$BRIDGE_WEBHOOK_SECRET"\'\n',
        ):
            with self.subTest(body=body.strip()):
                self.assertEqual([], self._rules(body))
        self.assertEqual(['argv'], self._rules('sh -c "echo $BRIDGE_WEBHOOK_SECRET"\n'))

    def test_a_NESTED_substitution_is_read_one_level_deep_on_the_FILE_leg(self) -> None:
        """`$(cat <secret file>)` marks its enclosing command; wrapped one level
        further it does not. A nested secret VALUE expansion is unaffected — the
        expansion walk is not depth-limited — which is what makes this a bound on
        the FILE leg specifically."""
        self.assertEqual(['stdout'], self._rules('echo "$(cat "$TOKEN_FILE")"\n'))
        self.assertEqual([], self._rules('echo "$(echo "$(cat "$TOKEN_FILE")")"\n'))
        self.assertEqual(['stdout'], self._rules('echo "$(echo "$SECRET")"\n'))


class AMessageMustNameTextTHEDOCACTUALLYCONTAINS(unittest.TestCase):
    """Findings are read in a CI log, so a message may not carry the private-use
    placeholders `_mask_substitutions` leaves behind. `Bearer \x010\x02` names
    nothing an author can search their doc for, and a remediation nobody can locate
    is noise.

    ⛔ THE OTHER HALF OF THE SAME REQUIREMENT, AND IT CUTS THE OTHER WAY: the message
    may name the COMMAND and WHERE a literal sits, both of which the doc contains,
    and it may NOT quote the literal itself. The two are not in tension — "text the
    doc contains" is what makes a finding actionable, and the `history` rule's
    subject is a SECRET VALUE, so quoting it would republish into an Actions log the
    very thing the rule exists to keep off a readable surface. Position and length
    locate the token for an author who has the doc open, and tell a log reader
    nothing usable.
    """

    def test_the_argv_message_shows_the_substitution_not_its_placeholder(self) -> None:
        findings = _scan('```bash\ncurl -H "Authorization: Bearer $(cat "$TOKEN_FILE")" '
                         'http://x/\n```\n')
        self.assertEqual(['argv'], [f.rule for f in findings])
        self.assertIn('$(cat "$TOKEN_FILE")', findings[0].message)

    def test_the_reader_message_unmasks_too(self) -> None:
        """The sibling: the same raw token reached the `stdout` reader message."""
        findings = _scan('```bash\ncat "$(dirname "$TOKEN_FILE")/writeback-token"\n```\n')
        self.assertEqual(['stdout'], [f.rule for f in findings])
        self.assertIn('$(dirname "$TOKEN_FILE")', findings[0].message)

    def test_the_history_message_names_the_literal_by_POSITION_and_LENGTH(self) -> None:
        """Measured on the fixture, whose two `history` members are the two the
        census reports across the whole tag history. The message quoted the payload
        verbatim until this change, inside backticks in the finding text, so a CI log
        that
        outlives a force-pushed-away branch carried the value the rule exists to
        keep off a readable surface. The VERDICT is unchanged: same lines, same
        rule; only the wording moves.
        """
        findings = [f for f in lint.scan_text(_FIXTURE, _fixture_text())
                    if f.rule == 'history']
        self.assertEqual(2, len(findings))
        messages = ' '.join(f.message for f in findings)
        for literal in ('your-hmac-secret', '<the-token>'):
            with self.subTest(literal=literal):
                self.assertNotIn(literal, messages)
        self.assertIn('argument 2, a literal 16 characters long', messages)
        self.assertIn('the here-string operand, a literal 11 characters long',
                      messages)

    def test_no_finding_this_suite_can_produce_carries_a_placeholder(self) -> None:
        """Over a population rather than over two messages: every payload this
        module reds on, plus the fixture, plus this checkout's own docs."""
        corpus = [_fixture_text()]
        corpus += [f'```bash\n{b}```\n' for b in
                   AddingAPipeStageMustNotSilenceALeak.SILENCED_BY_ONE_PIPE]
        corpus.append('```bash\ncurl -H "Authorization: Bearer $(cat "$TOKEN_FILE")" '
                      'http://x/\ncat "$(dirname "$TOKEN_FILE")/token"\n```\n')
        rendered = [f.render() for text in corpus for f in _scan(text)]
        self.assertGreater(len(rendered), 10, 'the corpus reddened on nothing')
        for line in rendered:
            with self.subTest(line=line):
                self.assertNotIn(lint.SUB_OPEN, line)
                self.assertNotIn(lint.SUB_CLOSE, line)


class TheCommandLineContract(unittest.TestCase):
    """Exit codes and the one thing every run must say."""

    def _run(self, *args: str):
        return subprocess.run([sys.executable, _TOOL, *args],
                              capture_output=True, text=True, timeout=120)

    def test_a_clean_run_exits_zero_and_still_disclaims(self) -> None:
        out = self._run(os.path.join(_REPO, 'docs'))
        self.assertEqual(0, out.returncode, out.stdout + out.stderr)
        self.assertIn('BACKSTOP ONLY', out.stdout)
        self.assertIn('is NOT evidence the class is absent', out.stdout)

    def test_a_dirty_run_exits_one_and_points_at_the_rule_not_at_itself(self) -> None:
        out = self._run(_FIXTURE)
        self.assertEqual(1, out.returncode)
        self.assertIn('docs/config-schema.md', out.stdout)
        self.assertIn('BACKSTOP ONLY', out.stdout)
        self.assertEqual(5, sum(1 for line in out.stdout.split('\n')
                                if line.startswith(_FIXTURE)))

    def test_a_missing_path_is_a_usage_error_not_an_empty_clean_run(self) -> None:
        out = self._run(os.path.join(_REPO, 'no-such-directory-9f3a'))
        self.assertEqual(2, out.returncode)
        self.assertIn('no such path', out.stderr)

    def test_the_rule_owner_named_in_every_message_exists(self) -> None:
        """A remediation string is a doc surface: a pointer to a section that has
        moved sends the reader nowhere and the lint becomes noise."""
        with open(os.path.join(_REPO, 'docs', 'config-schema.md'), encoding='utf-8') as fh:
            schema = fh.read()
        self.assertIn('### Handling a secret VALUE (not just its file)', schema)


if __name__ == '__main__':
    unittest.main()
