#!/usr/bin/env python3
"""BACKSTOP — not the rule. Shell fences in *.md that resolve a secret VALUE onto a
readable surface (card#8351, DL-321/DL-322).

⚠ READ THIS BEFORE YOU TRUST A GREEN RUN.

    THE RULE IS `docs/config-schema.md` § "Handling a secret VALUE (not just its
    file)". It is stated over the SURFACE — stdout, an argv, a log, shell history —
    and deliberately NOT over an enumeration of instruments, because the next thing
    that resolves a secret has not been enumerated yet.

    THIS PROGRAM IS A SPELLING-BASED BACKSTOP UNDERNEATH THAT RULE. It greps a
    finite list of shapes. A clean run therefore says "none of the shapes this
    program knows appears in a shell fence" — it is NOT evidence that the class is
    absent, and it never can be. DL-321 declined a lint on exactly this ground and
    the objection stands; the operator's ruling that reversed it (DL-324, card#8351)
    was that a backstop with a measured 100% catch rate on the KNOWN population
    beats a rule with no enforcement at all, not that a grep can decide the
    question. If you are here to conclude a doc is clean, read the rule and the
    diff — this exit code cannot answer that.

WHY MARKDOWN SHELL FENCES, AND WHY THAT IS A MEASUREMENT AND NOT AN ASSUMPTION.
All five known members of this class — the four DL-321 fixed and the one DL-322
did — were shell recipes inside markdown fences, and zero were in code. That is the
whole basis for the scope. A member minted in a `.php`, a `.sh`, a workflow `run:`
block or a hook script is OUT OF REACH of this program by construction.

WHAT IT READS, AND WHAT IT DELIBERATELY DOES NOT.
Only FENCED blocks whose info string names a shell are parsed as executable. Inline
backticks in prose are never read — and that is load-bearing rather than a shortcut:
this repo's docs QUOTE the bad forms in order to teach why they were wrong
(`CLAUDE_DEPLOYMENT.md`'s smoke-test preamble quotes the argv leak DL-322 removed;
DL-321, DL-322 and the CHANGELOG all quote several more). A lint that reddened on
the documentation of the defect would be worse than no lint: it would be removed,
or the explanations would be, and either way the rule loses.

RULES (each names the SURFACE it is about, never the instrument):
  argv     a secret VALUE expansion reaches the argument list of a non-builtin.
           `/proc/<pid>/cmdline` is world-readable for the life of the process.
  stdout   a printing command emits a secret VALUE, or a reader emits a secret
           FILE, with nothing capturing it — a scrollback, a CI log, an agent's
           session transcript.
  probe    `${SECRET:-...}` / `${SECRET=...}` — the shapes that substitute the
           VALUE they were meant to test for. The sanctioned form is `${VAR:+set}`.
  log      a secret VALUE is redirected into a log file.
  history  a secret placed as a command-line LITERAL, written verbatim into the
           operator's shell history. The sanctioned form is `read -rs` + a builtin
           `printf`.

WAIVER. A doc that must show a bad form INSIDE an executable fence writes, on the
line IMMEDIATELY before it — a blank line drops it, so a waiver at the top of a
fence cannot silently claim a command further down (an intervening COMMENT line
does not drop it: comments attach to the command they precede):

    # doc-fence-lint: allow <reason, at least 8 characters>

A waiver with no reason is itself reported — an unauditable off-switch is the one
thing this program must not ship. Prefer inline backticks in prose: every quotation
of a bad form in this repo today is prose, and none needed a waiver.

BOUNDS, named rather than left to be discovered. Out of reach by construction:
markdown INDENTED code blocks (four-space, no fence) and any fence whose info
string is not in SHELL_INFO; a secret in bare `env` output, which prints the whole
environment with nothing in argv to read; and every file that is not a `*.md`. The
shell reader is a heuristic, not a parser: it understands quoting, pipelines,
command substitution, redirection and here-strings, and it does not understand
functions, loops, `eval`, aliases, or a value routed through several variables.

FOUR MORE BOUNDS, each MEASURED on this checkout rather than reasoned about. None
fires on any doc in this repo today, and closing any of them changes what CI
rejects — so each is disclosed here rather than quietly widened:
  · A fence inside a BLOCKQUOTE (`> ` before the backticks) is not recognised as a
    fence at all — an opener is matched after leading WHITESPACE only — so the
    whole body is invisible, not merely unparsed.
  · A Pandoc / Quarto info string — braces around the language — is not in
    SHELL_INFO, so such a fence reads as non-executable.
  · There is no fd table. `exec 3<` a secret file followed by `cat <&3` is green:
    the reading command's input path is `&3`, and nothing carries the opener's
    path forward to it.
  · A `sh -c` / `bash -c` STRING OPERAND is not recursed into, exactly as `eval`'s
    is not — it is read as an ordinary argument. So a single-quoted body expands
    nothing here and is GREEN, while a double-quoted one reds under the `argv`
    rule, which is correct: the PARENT shell expands that one into the child's
    argv before the child ever starts.

A HEREDOC is not tracked AT ALL, so its body is read as though it were commands: a
secret expansion inside one reports an `argv` finding against what is really a
config line. That holds for the QUOTED `<<'EOF'` spelling exactly as for the
unquoted one — and on the quoted form the message is not just a false positive but
a false STATEMENT, because a quoted heredoc expands nothing and no value ever
reaches an argv. Wording, not the parser: teaching it heredocs would change what CI
rejects, and the shape fires on no doc in this repo at any tag. The waiver is the
answer if one appears.

A NESTED command substitution is read one level deep on the secret-FILE leg:
`$(cat <secret file>)` marks its enclosing command, `$(echo "$(cat <secret file>)")`
does not and is GREEN. A nested secret VALUE expansion is unaffected — the
expansion walk is not depth-limited — so `$(echo "$SECRET")` still reds. WITHIN
that one level every spelling of the read is decided by ONE predicate,
`_secret_files_on_stdout`, shared with the pipeline reader: `$(cat f)`,
`$(cat < f)` and bash's `$(<f)` are the same act. They were two divergent copies of
the question, and the two redirect spellings answered GREEN while the first
reddened — a redirection character deciding a security verdict.

ONE LEG IS NOT AN ENUMERATION, AND THE DIFFERENCE IS DELIBERATE. Where a pipeline
carrying a secret VALUE ENDS, the question is decided deny-by-default: the tail
leaks unless it is a known stdin SINK (STDIN_SINKS — a digest, a file write, a
network send, a program that resolves the value itself). The list this replaced ran
the other way, naming the LEAKING tails, and its gaps were therefore SILENT: adding
one pipe stage turned a leak the tool already caught into a clean run
(`base64 <secret file>` red, `cat <secret file> | base64` green, and the same for
`| sed`, `| awk`, `| cut`, `| grep`, `| jq`, `| xxd`, `| fold`) — this list is the
one enumeration of that population; DL-324 and `docs/config-schema.md` point here
rather than keeping copies of it. Deny-by-default moves that leg's gaps to the loud
side — an unlisted tail reds and the author waives it with a reason — which is the
only direction a backstop may be wrong in. It is scoped to the tail on purpose: the
rest of this program stays a spelling grep, and the top of this file still holds.
STDIN_SINKS' own comment owns what may JOIN that list: an instrument the rule
prescribes, or one this repo's runbooks use — nothing admitted on plausibility,
because an invented sink is a silent green.

EXIT CODES
  0  no known shape found. NOT a clean bill of health — see the top of this file.
  1  at least one finding, printed as `path:line: [rule] surface — message`.
  2  usage error (a path that does not exist).

Stdlib only, no repo imports: `python3 bin/check-doc-secret-fences.py [PATH ...]`
(default: the current directory, walked).
"""

from __future__ import annotations

import argparse
import os
import re
import sys
from dataclasses import dataclass, field

# --------------------------------------------------------------------------------
# Vocabulary. Derived from docs/config-schema.md § Handling a secret VALUE and from
# the five measured members (DL-321's four, DL-322's one) — not invented here.
# --------------------------------------------------------------------------------

#: Info strings that make a fence EXECUTABLE for our purposes.
SHELL_INFO = {
    'bash', 'sh', 'shell', 'zsh', 'ksh',
    'console', 'shell-session', 'bash-session', 'sh-session', 'shellsession',
}

#: A word in a variable NAME that says the variable holds a secret VALUE.
SECRET_WORDS = {
    'secret', 'secrets', 'token', 'tokens', 'passwd', 'password', 'passwords',
    'passphrase', 'bearer', 'hmac', 'credential', 'credentials', 'apikey', 'pat',
    'privkey', 'signingkey',
}

#: `key` is too common a word to be a secret marker alone (`KEY`, `SORT_KEY`,
#: `CACHE_KEY`). It counts only next to one of these.
KEY_QUALIFIERS = {
    'api', 'private', 'secret', 'signing', 'ssh', 'deploy', 'encryption',
    'master', 'session', 'auth',
}

#: A trailing word that turns a secret NAME into a secret PATH — `TOKEN_FILE` names
#: where the value lives, not the value. Getting this wrong is the difference
#: between reading `docs/multi-host.md` as clean and reddening `mkdir -p
#: "$(dirname "$TOKEN_FILE")"`.
PATHY_TAIL_WORDS = {
    'file', 'files', 'path', 'paths', 'dir', 'dirs', 'directory', 'filename',
    'url', 'uri', 'base', 'name', 'prefix', 'suffix', 'root', 'home', 'id',
}

#: Substrings that identify a secret store on their own — nothing else is spelled
#: like this.
SECRET_PATH_MARKERS = (
    'webhook-secret-scope', 'writeback-token', 'id_rsa', 'id_ed25519', '.pem', '.p12',
)

#: Substrings that identify a secret store only in something that is actually a
#: PATH. `grep token README.md` must not read as opening a secret file, and
#: `/etc/passwd` is not one — which is why `passwd` is absent from both tuples.
GENERIC_PATH_MARKERS = ('secret', 'token', 'password', 'credential', 'keyfile')

#: No fork, so no `/proc/<pid>/cmdline` entry. A secret in one of these commands'
#: arguments is not an argv leak — `printf '%s' "$SECRET" > file` is the form the
#: rule PRESCRIBES, and a lint that reddened on it would push authors back to the
#: leaking spelling.
SHELL_BUILTINS = {
    ':', '.', '[', '[[', 'alias', 'bg', 'bind', 'break', 'builtin', 'caller',
    'case', 'cd', 'command', 'compgen', 'complete', 'compopt', 'continue',
    'declare', 'dirs', 'disown', 'do', 'done', 'echo', 'elif', 'else', 'enable',
    'esac', 'eval', 'exit', 'export', 'false', 'fc', 'fg', 'fi', 'for', 'getopts',
    'hash', 'help', 'history', 'if', 'in', 'jobs', 'let', 'local', 'logout',
    'mapfile', 'popd', 'printf', 'pushd', 'pwd', 'read', 'readarray', 'readonly',
    'return', 'select', 'set', 'shift', 'shopt', 'source', 'suspend', 'test',
    'then', 'time', 'times', 'trap', 'true', 'type', 'typeset', 'ulimit', 'umask',
    'unalias', 'unset', 'until', 'wait', 'while',
}

#: Prefixes that stand in front of the real command. `sudo openssl …` still forks
#: openssl; `env FOO=x cmd` forks env. `env` is deliberately NOT here — its own
#: argv carries whatever you hand it, and `xargs` is absent for exactly the same
#: reason, only worse: it turns its STDIN into an argv, which is the DL-322 shape.
#: Stripped as a prefix, `printf '%s' "$SECRET" | xargs curl -H` read as ending at
#: `curl` — a known stdin sink — and went green while placing the value in
#: /proc/<pid>/cmdline.
#:
#: ⚠ A prefix that takes an OPERAND leaves it behind: `timeout 5 sha256sum` reads
#: as the command `5`. Named rather than left to be discovered; it fires on no doc
#: in this repo at any tag.
COMMAND_PREFIXES = {'sudo', 'doas', 'command', 'exec', 'nohup', 'nice', 'ionice',
                    'timeout', 'stdbuf', 'builtin', 'then', 'do', 'else',
                    'elif', 'time'}

#: Commands whose stdout is (some function of) their arguments.
PRINTING_COMMANDS = {'echo', 'printf', 'env', 'printenv', 'set', 'declare',
                     'typeset'}

#: The printers whose argument is a variable NAME rather than a value. The value
#: they resolve appears NOWHERE in the source text, so the expansion walk every
#: other rule is built on has nothing to find — see `_printed_secret_names`. A
#: member outside PRINTING_COMMANDS would be a leg that cannot fire, so the subset
#: is asserted by a test rather than left to this comment.
NAME_TAKING_PRINTERS = {'printenv', 'declare', 'typeset'}

#: Commands whose stdout is (some function of) a file they were given.
READING_COMMANDS = {'cat', 'head', 'tail', 'less', 'more', 'nl', 'tac', 'rev',
                    'xxd', 'od', 'strings', 'base64', 'tee'}

#: Commands that CONSUME a pipeline's stdin without handing it back. A pipeline
#: carrying a secret VALUE that ENDS anywhere else is a stdout leak, even though
#: every stage was individually innocent.
#:
#: DENY BY DEFAULT, and the inversion is the point (card#8351). This list used to
#: run the other way — it enumerated the LEAKING tails — so every command nobody
#: had thought to list read as safe, and adding one pipe stage silenced a leak the
#: tool already caught: `base64 <secret file>` reddened while `cat <secret file> |
#: base64` did not, and neither did `| sed`, `| awk`, `| cut`, `| grep`, `| jq`,
#: `| xxd`. An enumeration whose gaps are SILENT is the shape this whole check
#: exists to argue against, so the gaps now fall on the loud side: an unlisted tail
#: reds, and a doc that must show one waives the line with a reason.
#:
#: Membership means "this command's stdout is not a function of its stdin" — a
#: digest, a file write, a network send, a program that resolves the value itself.
#: `tee` is deliberately absent: it writes the file AND prints. Shell builtins are
#: not listed and are handled in `_tail_hands_stdin_back` — `read` consumes stdin,
#: `echo`/`printf` ignore it.
#:
#: ⛔ EVERY MEMBER IS ADMITTED BY A STATED REASON, and there are only two: the rule
#: PRESCRIBES the instrument, or this repo's own runbooks use it. A member admitted
#: by neither is an invention, and an invented sink is a SILENT green — the one
#: direction a backstop may not be wrong in. That test removed 22 of the 37 names
#: an earlier draft listed on plausibility alone, three of them measurably wrong:
#: `dd` with no `of=` copies stdin to stdout, `gpg -d` writes the plaintext there, and
#: `ssh host cat` brings it back over the wire, so all three were green while
#: printing the value. The removals cost false positives, which are loud and
#: waivable; keeping them cost silent greens, which are neither.
#: `test_every_sink_is_admitted_by_the_rule_or_by_this_repo_s_own_docs` asserts the
#: set rather than leaving this paragraph to drift away from it.
#:
#: ⚠ The bound this list buys, named rather than left to be discovered: a member
#: whose stdout IS a function of its stdin in some other mode is green as a tail.
#: `openssl` is the worked case — it is here because `dgst` is a form the rule
#: prescribes, and `| openssl base64` therefore does not red (its argv-borne twin,
#: the DL-322 member, is caught by the `argv` rule and does not depend on this).
STDIN_SINKS = {
    # PRESCRIBED — "Comparing two copies. Compare digests … never the values."
    # The rule names the class and `sha256sum` as its instance; each name here
    # prints a digest OF its stdin and never stdin, which is decidable by
    # inspection rather than by having been enumerated.
    'sha1sum', 'sha224sum', 'sha256sum', 'sha384sum', 'sha512sum', 'shasum',
    'md5sum', 'b2sum', 'cksum', 'sum',
    # PRESCRIBED — "Passing a value to a program … feed it on stdin", worked in the
    # rule itself as `printf … | curl --config -`.
    'curl',
    # USED BY THIS REPO — `docs/provider-adapters.md` and `docs/writeback.md` place
    # a value with `install` (DL-321), `CLAUDE_DEPLOYMENT.md` signs with
    # `php artisan bridge:sign`, which reads the body on stdin (DL-322), and the
    # `openssl dgst` it replaced is a digest.
    'install', 'php', 'artisan', 'openssl',
}

#: Redirect targets that are still a readable surface, not a file.
TERMINAL_TARGETS = {'/dev/stdout', '/dev/stderr', '/dev/tty', '/dev/console',
                    '&1', '&2', '/proc/self/fd/1', '/proc/self/fd/2'}

WAIVER_RE = re.compile(r'#\s*doc-fence-lint:\s*allow\b[ \t]*(?P<reason>.*)$')
MIN_WAIVER_REASON = 8

RULE_IDS = ('argv', 'stdout', 'probe', 'log', 'history', 'waiver-no-reason')

BACKSTOP_NOTE = (
    'BACKSTOP ONLY — this program greps a finite list of spellings. '
    'The rule is docs/config-schema.md § Handling a secret VALUE, stated over the '
    'SURFACE; a clean run here is NOT evidence the class is absent, because the '
    'next instrument that resolves a secret is never on the list.'
)


# --------------------------------------------------------------------------------
# Findings
# --------------------------------------------------------------------------------

@dataclass(frozen=True)
class Finding:
    path: str
    line: int
    rule: str
    surface: str
    message: str

    def render(self) -> str:
        return f'{self.path}:{self.line}: [{self.rule}] {self.surface} — {self.message}'


# --------------------------------------------------------------------------------
# A logical shell line: text plus a per-character source-line map, so a finding can
# name the physical line even after continuations were joined and command
# substitutions masked.
# --------------------------------------------------------------------------------

@dataclass
class Chunk:
    text: str = ''
    lines: list[int] = field(default_factory=list)

    def append(self, text: str, line: int) -> None:
        self.text += text
        self.lines.extend([line] * len(text))

    def slice(self, start: int, end: int) -> 'Chunk':
        c = Chunk()
        c.text = self.text[start:end]
        c.lines = self.lines[start:end]
        return c

    def line_at(self, offset: int) -> int:
        if not self.lines:
            return 0
        return self.lines[min(offset, len(self.lines) - 1)]


def _is_secret_value_name(name: str) -> bool:
    """Does this variable NAME hold a secret VALUE (as opposed to a path to one)?"""
    words = [w.lower() for w in re.split(r'[_\-]|(?<=[a-z0-9])(?=[A-Z])', name) if w]
    if not words:
        return False
    if words[-1] in PATHY_TAIL_WORDS:
        return False
    if any(w in SECRET_WORDS for w in words):
        return True
    return 'key' in words and any(w in KEY_QUALIFIERS for w in words)


def _looks_like_secret_path(token: str) -> bool:
    """Does this token name a FILE that holds a secret?

    A generic marker counts only in something that IS a path — a `/`, or a variable
    expansion. Without that guard `grep token README.md` reads as opening a secret
    file, which is how a lint earns its way to being switched off.
    """
    low = token.strip('"\'').lower()
    if any(m in low for m in SECRET_PATH_MARKERS):
        return True
    pathish = '/' in low or low.startswith('$') or '${' in low
    if pathish and any(m in low for m in GENERIC_PATH_MARKERS):
        return True
    for name in re.findall(r'\$\{?([A-Za-z_][A-Za-z0-9_]*)', token):
        words = [w.lower() for w in re.split(r'[_\-]', name) if w]
        if words and words[-1] in PATHY_TAIL_WORDS and any(w in SECRET_WORDS for w in words):
            return True
    return False


#: `${VAR:+set}` and `${VAR+set}` substitute the ALTERNATIVE, never the value —
#: they are the forms the rule PRESCRIBES for testing whether a secret is set, and
#: a tool that reddened on them would push an author back to `echo "$VAR"`.
#: `${#VAR}` (a length) never matches the expansion regex at all.
_ALTERNATIVE_EXPANSION_RE = re.compile(r'^\$\{[A-Za-z_][A-Za-z0-9_]*:?\+')


def _secret_expansions(text: str) -> list[tuple[int, str]]:
    """`(offset, name)` for every `$NAME` / `${NAME...}` naming a secret VALUE.

    Single-quoted regions are skipped: `awk '{print $NF}'` expands nothing, and
    neither does a `'...'` prose fragment that happens to spell a variable.
    """
    out = []
    i, n = 0, len(text)
    in_single = False
    while i < n:
        ch = text[i]
        if ch == "'" and not in_single:
            in_single = True
            i += 1
            continue
        if ch == "'" and in_single:
            in_single = False
            i += 1
            continue
        if ch == '\\' and not in_single:
            i += 2
            continue
        if ch == '$' and not in_single:
            m = re.match(r'\$\{?([A-Za-z_][A-Za-z0-9_]*)', text[i:])
            if m and _is_secret_value_name(m.group(1)) \
                    and not _ALTERNATIVE_EXPANSION_RE.match(text[i:]):
                out.append((i, m.group(1)))
            if m:
                i += m.end()
                continue
        i += 1
    return out


_PROBE_RE = re.compile(r'\$\{[A-Za-z_][A-Za-z0-9_]*:?[-=]')


def _probe_expansions(chunk: Chunk) -> list[tuple[int, str]]:
    """`${SECRET:-x}` / `${SECRET-x}` / `${SECRET:=x}` / `${SECRET=x}` — the four
    shapes that substitute the value. `${VAR:+set}` and `${VAR+set}` substitute the
    ALTERNATIVE and are the forms the rule prescribes, so they are not read here.

    Built on the same quote-aware walk as every other expansion read, so a probe
    written inside single quotes — where the shell expands nothing — is not
    reported as one.
    """
    return [(off, name) for off, name in _secret_expansions(chunk.text)
            if _PROBE_RE.match(chunk.text[off:])]


# --------------------------------------------------------------------------------
# Splitting: substitutions first (recursively), then top-level operators.
# --------------------------------------------------------------------------------

SUB_OPEN = '\x01'
SUB_CLOSE = '\x02'
_SUB_REF_RE = re.compile(SUB_OPEN + r'(\d+)' + SUB_CLOSE)


def _unmask(token: str, subs: list[Chunk]) -> str:
    """Render a masked token back as the source text an author can search for.

    A finding is read in a CI log, so a message must never carry the control-
    character placeholders `_mask_substitutions` leaves behind. Rendered raw, a
    bearer header reads as `Bearer` followed by two unprintable bytes: it names
    nothing that appears in the doc, and a remediation nobody can locate is noise.
    """
    def render(m):
        i = int(m.group(1))
        return f'$({subs[i].text})' if i < len(subs) else m.group(0)
    return _SUB_REF_RE.sub(render, token)


def _mask_substitutions(chunk: Chunk) -> tuple[Chunk, list[Chunk]]:
    """Replace every `$( … )` / backtick substitution with a one-character
    placeholder, returning the masked chunk and the inner chunks in order."""
    masked = Chunk()
    subs: list[Chunk] = []
    i, n = 0, len(chunk.text)
    in_single = False
    while i < n:
        ch = chunk.text[i]
        if ch == '\\' and not in_single and i + 1 < n:
            masked.append(chunk.text[i:i + 2], chunk.line_at(i))
            i += 2
            continue
        if ch == "'":
            in_single = not in_single
            masked.append(ch, chunk.line_at(i))
            i += 1
            continue
        if not in_single and chunk.text.startswith('$(', i):
            end = _match_paren(chunk.text, i + 1)
            if end is not None:
                inner = chunk.slice(i + 2, end)
                inner_masked, inner_subs = _mask_substitutions(inner)
                subs.append(inner)
                subs.extend(inner_subs)
                masked.append(SUB_OPEN + str(len(subs) - len(inner_subs) - 1) + SUB_CLOSE,
                              chunk.line_at(i))
                i = end + 1
                continue
        if not in_single and ch == '`':
            end = chunk.text.find('`', i + 1)
            if end != -1:
                inner = chunk.slice(i + 1, end)
                inner_masked, inner_subs = _mask_substitutions(inner)
                subs.append(inner)
                subs.extend(inner_subs)
                masked.append(SUB_OPEN + str(len(subs) - len(inner_subs) - 1) + SUB_CLOSE,
                              chunk.line_at(i))
                i = end + 1
                continue
        masked.append(ch, chunk.line_at(i))
        i += 1
    return masked, subs


def _match_paren(text: str, open_idx: int) -> int | None:
    """Index of the `)` closing the `(` at `open_idx`, quote-aware, or None."""
    depth = 0
    i, n = open_idx, len(text)
    in_single = False
    in_double = False
    while i < n:
        ch = text[i]
        if ch == '\\' and not in_single:
            i += 2
            continue
        if ch == "'" and not in_double:
            in_single = not in_single
        elif ch == '"' and not in_single:
            in_double = not in_double
        elif not in_single and not in_double:
            if ch == '(':
                depth += 1
            elif ch == ')':
                depth -= 1
                if depth == 0:
                    return i
        i += 1
    return None


@dataclass
class Segment:
    """One simple command in a pipeline."""
    chunk: Chunk
    piped_out: bool = False        # its stdout goes to another command
    captured: bool = False         # it sits inside a command substitution
    pipeline_head: bool = True


def _split_segments(chunk: Chunk, captured: bool) -> list[list[Segment]]:
    """Split a masked logical line into pipelines, each a list of Segments."""
    pipelines: list[list[Segment]] = []
    current: list[Segment] = []
    start = 0
    i, n = 0, len(chunk.text)
    in_single = in_double = False

    def flush(end: int, piped: bool) -> None:
        nonlocal start, current
        seg = chunk.slice(start, end)
        if seg.text.strip():
            current.append(Segment(seg, piped_out=piped, captured=captured,
                                   pipeline_head=not current))
        start = end

    while i < n:
        ch = chunk.text[i]
        if ch == '\\' and not in_single:
            i += 2
            continue
        if ch == "'" and not in_double:
            in_single = not in_single
            i += 1
            continue
        if ch == '"' and not in_single:
            in_double = not in_double
            i += 1
            continue
        if in_single or in_double:
            i += 1
            continue
        if chunk.text.startswith('||', i):
            flush(i, False)
            pipelines.append(current)
            current = []
            i += 2
            start = i
            continue
        if chunk.text.startswith('&&', i):
            flush(i, False)
            pipelines.append(current)
            current = []
            i += 2
            start = i
            continue
        if ch == '|':
            flush(i, True)
            i += 1
            start = i
            continue
        if ch == '\n' and current and current[-1].piped_out \
                and not chunk.text[start:i].strip():
            # `cmd |` at end of line: the pipeline CONTINUES on the next line.
            # Ending it here handed the tail its own pipeline, where `idx > 0` is
            # false and the tail rule never runs — so `printf '%s' "$SECRET" |`
            # with `base64` on the following line was green for a spelling reason
            # while the one-line form reddened (card#8351).
            i += 1
            start = i
            continue
        # NOT `&`: a bare `&` backgrounds a job, but splitting on it would cut
        # `2>&1` and `>&2` in half and silently drop the redirect — and dropping a
        # redirect is the direction that makes a leak look captured.
        #
        # `{`/`}` are the shell's group-command syntax only as STANDALONE words.
        # Split on unconditionally, `xargs -I{} curl …` reported against a command
        # called `-I` — a remediation naming text that appears in no doc.
        if ch in '{}' and not (
                (i == 0 or chunk.text[i - 1].isspace())
                and (i + 1 == n or chunk.text[i + 1].isspace())):
            i += 1
            continue
        if ch in ';\n(){}':
            flush(i, False)
            if current:
                pipelines.append(current)
                current = []
            i += 1
            start = i
            continue
        i += 1
    flush(n, False)
    if current:
        pipelines.append(current)
    return [p for p in pipelines if p]


# --------------------------------------------------------------------------------
# Reading a simple command
# --------------------------------------------------------------------------------

#: A redirection operator at the START of a token, with the fd captured so `2>` can
#: be told from `>`. Reading them off TOKENS rather than off the raw text is what
#: makes it quote-safe: a `>` inside `-d '{"a":">"}'` is not a redirect, and a regex
#: over the raw line cannot tell — it would invent a redirect and thereby make a
#: printed secret look captured, which is the direction that loses a finding.
_REDIRECT_TOKEN_RE = re.compile(r'^(?P<op>&>>?|[0-9]?>>?&?|[0-9]?<)(?P<rest>.*)$')
_HERESTRING_RE = re.compile(r'<<<\s*(\S+)')


def _tokenize(text: str) -> list[str]:
    """Whitespace split that keeps quoted runs together."""
    tokens: list[str] = []
    cur = ''
    i, n = 0, len(text)
    in_single = in_double = False
    while i < n:
        ch = text[i]
        if ch == '\\' and not in_single and i + 1 < n:
            cur += text[i:i + 2]
            i += 2
            continue
        if ch == "'" and not in_double:
            in_single = not in_single
            cur += ch
            i += 1
            continue
        if ch == '"' and not in_single:
            in_double = not in_double
            cur += ch
            i += 1
            continue
        if ch.isspace() and not in_single and not in_double:
            if cur:
                tokens.append(cur)
                cur = ''
            i += 1
            continue
        cur += ch
        i += 1
    if cur:
        tokens.append(cur)
    return tokens


@dataclass
class Command:
    name: str
    args: list[str]
    #: `(operator, target)` in source order: `('>', 'f')`, `('2>', '/dev/null')`,
    #: `('>&', '2')`, `('<', 'f')`.
    redirects: list[tuple[str, str]]
    herestring: str | None
    text: str

    def stdout_targets(self) -> list[str]:
        """Where this command's STDOUT goes. A `2>` moves stderr and leaves stdout
        exactly where it was, so it must never read as capturing anything."""
        out = []
        for op, target in self.redirects:
            if op.endswith('<'):
                continue
            fd = op[0] if op[0].isdigit() else ('&' if op.startswith('&') else '1')
            if fd in ('1', '&'):
                out.append('&' + target if op.endswith('&') else target)
        return out

    def input_paths(self) -> list[str]:
        """`cat < <secret path>` reads a secret file exactly as `cat <secret path>`
        does; only the spelling differs."""
        return [t for op, t in self.redirects if op.endswith('<')]


_ASSIGN_RE = re.compile(r'^[A-Za-z_][A-Za-z0-9_]*(\[[^\]]*\])?\+?=')


def _read_command(text: str) -> Command:
    hs = _HERESTRING_RE.search(text)
    tokens = _tokenize(_HERESTRING_RE.sub(' ', text))

    redirects: list[tuple[str, str]] = []
    words: list[str] = []
    i = 0
    while i < len(tokens):
        m = _REDIRECT_TOKEN_RE.match(tokens[i])
        if m:
            target = m.group('rest')
            if not target and i + 1 < len(tokens):
                i += 1
                target = tokens[i]
            redirects.append((m.group('op'), target.strip('"\'')))
        else:
            words.append(tokens[i])
        i += 1

    # Drop leading `VAR=value` assignment prefixes and command wrappers.
    while words and (_ASSIGN_RE.match(words[0]) or words[0] in COMMAND_PREFIXES):
        words.pop(0)
    return Command(name=words[0] if words else '', args=words[1:], redirects=redirects,
                   herestring=hs.group(1) if hs else None, text=text)


def _tail_hands_stdin_back(cmd: Command) -> bool:
    """Does a pipeline TAIL put (some function of) its stdin back on stdout?

    Deny by default: yes, unless the command is a known stdin sink (STDIN_SINKS,
    which states why the default is this way round) or a shell builtin — `read`
    consumes stdin and `echo`/`printf` ignore it, so neither hands it back.
    """
    if not cmd.name:
        return False
    return cmd.name not in STDIN_SINKS and cmd.name not in SHELL_BUILTINS


def _writes_to_a_readable_surface(cmd: Command) -> bool:
    """True when this command's stdout is NOT captured into a file."""
    targets = cmd.stdout_targets()
    if not targets:
        return True
    return any(t in TERMINAL_TARGETS for t in targets)


def _redirect_log_target(cmd: Command) -> str | None:
    for target in cmd.stdout_targets():
        low = target.lower()
        if low.endswith('.log') or '/log/' in low or '/logs/' in low or low.startswith('/var/log'):
            return target
    return None


def _redirect_secret_store(cmd: Command) -> str | None:
    for target in cmd.stdout_targets():
        if target in TERMINAL_TARGETS:
            continue
        if _looks_like_secret_path(target):
            return target
    return None


def _literal_payload(cmd: Command) -> str | None:
    """A quoted literal this command would WRITE — the shell-history shape.

    `printf` skips its format argument: `printf '%s' "$SECRET"` places a value the
    right way and must never be read as placing a literal.
    """
    args = [a for a in cmd.args if not a.startswith('-')]
    if cmd.name == 'printf':
        args = args[1:]
    for a in args:
        stripped = a.strip('"\'')
        if not stripped or len(stripped) < 3:
            continue
        if '$' in a or SUB_OPEN in a:
            continue
        return stripped
    return None


# --------------------------------------------------------------------------------
# The rules
# --------------------------------------------------------------------------------

def _secret_files_on_stdout(cmd: Command) -> list[str]:
    """Every secret FILE whose contents this command puts on ITS OWN stdout.

    ONE predicate, because there is one act. `cat <secret file>`, `cat < <secret
    file>` and bash's `$(< <secret file>)` differ only in spelling, and this
    question had two divergent copies of the answer: the pipeline reader asked over
    `args + input_paths()` while the substitution reader asked over `args` alone.
    So the verdict on identical bytes depended on which caller asked —
    `cat < "$TOKEN_FILE"` reddened on its own line and went GREEN the moment it was
    captured, a redirection character deciding a security verdict.

    An empty NAME is bash's `$(<file)`, and its only real caller is the
    substitution one: as a whole command at top level, `< file` opens the file and
    prints nothing, which is why `_analyse_pipeline` skips a nameless command
    before it ever asks this.
    """
    if cmd.name and cmd.name not in READING_COMMANDS:
        return []
    return [t for t in cmd.args + cmd.input_paths() if _looks_like_secret_path(t)]


def _printed_secret_names(cmd: Command) -> list[str]:
    """Bare variable NAMES whose VALUE this command would print.

    `printenv BRIDGE_WEBHOOK_SECRET` and `declare -p BRIDGE_WEBHOOK_SECRET` resolve
    a secret onto stdout while carrying no `$` anywhere for the expansion walk to
    find — so the `stdout` arm, which asks for an expansion-bearing argument, could
    not fire for either, though `_is_secret_value_name` already answered True about
    the very argument being passed.

    Deliberately NOT fed to the `argv` rule. `printenv` does fork, but what lands
    in /proc/<pid>/cmdline is the NAME it was asked for; reporting that as an argv
    leak would print a message that is false.
    """
    if cmd.name not in NAME_TAKING_PRINTERS:
        return []
    return [a for a in cmd.args
            if not a.startswith('-') and _is_secret_value_name(a.strip('"\''))]


def _sub_is_secret_bearing(sub: Chunk) -> bool:
    """Does this command substitution's stdout carry a secret VALUE?"""
    if _secret_expansions(sub.text):
        return True
    for pipeline in _split_segments(_mask_substitutions(sub)[0], captured=True):
        for seg in pipeline:
            cmd = _read_command(seg.chunk.text)
            if _secret_files_on_stdout(cmd) or _printed_secret_names(cmd):
                return True
    return False


def _analyse_pipeline(path: str, pipeline: list[Segment], subs: list[Chunk],
                      enabled: set[str]) -> list[Finding]:
    findings: list[Finding] = []
    secret_subs = {i for i, s in enumerate(subs) if _sub_is_secret_bearing(s)}

    def token_carries_secret(token: str) -> bool:
        if _secret_expansions(token):
            return True
        return any(int(m.group(1)) in secret_subs
                   for m in _SUB_REF_RE.finditer(token))

    pipeline_carries_secret = False

    for idx, seg in enumerate(pipeline):
        text = seg.chunk.text
        cmd = _read_command(text)
        if not cmd.name:
            continue
        arg_secrets = [a for a in cmd.args if token_carries_secret(a)]
        secret_files = _secret_files_on_stdout(cmd)
        printed_names = _printed_secret_names(cmd)
        # A stage that puts a secret on ITS stdout makes the whole downstream
        # pipeline carry one. The reader leg is load-bearing and was missing:
        # `cat "$TOKEN_FILE"` names no secret VALUE in its argv — what it is given
        # is a PATH — so `cat "$TOKEN_FILE" | base64` reached the tail rule with
        # the pipeline marked clean and every stage individually innocent. The
        # name-taking printers are here for the same reason and no other: leaving
        # them out would make `printenv <secret> | base64` green while
        # `printenv <secret>` reds — one pipe stage silencing a leak, which is the
        # defect this whole leg exists to close.
        if arg_secrets or secret_files or printed_names \
                or (idx > 0 and pipeline_carries_secret):
            pipeline_carries_secret = True

        line = seg.chunk.line_at(0)
        for off, _name in _secret_expansions(text):
            line = seg.chunk.line_at(off)
            break

        # --- argv -------------------------------------------------------------
        if 'argv' in enabled and arg_secrets and cmd.name not in SHELL_BUILTINS:
            findings.append(Finding(
                path, line, 'argv', 'argv',
                f'`{cmd.name}` is not a shell builtin, so '
                f'{_unmask(arg_secrets[0], subs)} becomes a '
                f'token in /proc/<pid>/cmdline, readable by every local account for '
                f'the life of the process. Pass a PATH, or the value on stdin.'))

        # --- stdout -----------------------------------------------------------
        if 'stdout' in enabled and not seg.captured and not seg.piped_out \
                and _writes_to_a_readable_surface(cmd):
            # Three independent ways one command reaches stdout. They are NOT an
            # if/elif chain: `tee` is both a reader and a passthrough tail, and
            # chaining silently swallowed the tail case behind the reader's empty
            # result — a branch that could not fire, which is the shape this whole
            # card is about.
            printed = arg_secrets + printed_names
            if cmd.name in PRINTING_COMMANDS and printed:
                # A name-taking printer was handed a NAME, so the message has to
                # say whose VALUE it prints: the token it names is not the secret.
                subject = _unmask(printed[0], subs)
                if not arg_secrets:
                    subject = f'the VALUE of {subject}'
                findings.append(Finding(
                    path, line, 'stdout', 'stdout',
                    f'`{cmd.name}` writes {subject} to '
                    f'stdout — a terminal '
                    f'scrollback, a CI log, or an AI agent\'s session transcript. '
                    f'Test with ${{VAR:+set}}; compare with sha256sum.'))
            if secret_files:
                findings.append(Finding(
                    path, line, 'stdout', 'stdout',
                    f'`{cmd.name}` puts the contents of '
                    f'{_unmask(secret_files[0], subs)} on '
                    f'stdout. Open the file yourself, at your own terminal — a '
                    f'value that reaches a transcript is leaked, and the only '
                    f'repair is rotation.'))
            # `and not secret_files` dedupes ONE case: `tee` is a reader AND a
            # passthrough tail, so both legs fire on one command and the reader's
            # diagnosis is the specific one. What makes the bare form safe is the
            # PREDICATE, not a second condition here: `_secret_files_on_stdout`
            # answers non-empty only for a command that actually reads. Computed
            # over any command's argv it disabled the tail rule for any tail whose
            # arguments merely LOOKED like a secret path, which brought back the
            # "Compare both values" class DL-321 fixed —
            # `printf '%s' "$SECRET" | diff - <secret file>` was green.
            if idx == len(pipeline) - 1 and idx > 0 and pipeline_carries_secret \
                    and _tail_hands_stdin_back(cmd) \
                    and not secret_files:
                findings.append(Finding(
                    path, line, 'stdout', 'stdout',
                    f'this pipeline carries a secret VALUE and ends at `{cmd.name}`, '
                    f'which is not a known stdin SINK — so it hands (some function '
                    f'of) the value straight back to the terminal. Send it to a '
                    f'file, a digest, or a program that consumes it.'))

        # --- log --------------------------------------------------------------
        if 'log' in enabled and (arg_secrets or pipeline_carries_secret):
            target = _redirect_log_target(cmd)
            if target:
                findings.append(Finding(
                    path, line, 'log', 'a log file',
                    f'a secret VALUE is redirected into {target}. A log outlives the '
                    f'session and is read by people who never ran the command.'))

        # --- history ----------------------------------------------------------
        if 'history' in enabled:
            store = _redirect_secret_store(cmd)
            # Parenthesised as Python already groups it — `A or (B and C)`. Read as
            # `(A or B) and C` it would be a different rule, and nothing in the
            # source said which one was meant.
            target_is_secret_store = store is not None or (
                any(_looks_like_secret_path(a) for a in cmd.args)
                and cmd.name in {'install', 'tee', 'dd'}
            )
            payload = None
            if cmd.herestring and '$' not in cmd.herestring and SUB_OPEN not in cmd.herestring:
                payload = cmd.herestring.strip('"\'')
            elif cmd.name in {'echo', 'printf'}:
                payload = _literal_payload(cmd)
            if target_is_secret_store and payload and not arg_secrets:
                where = store or 'a secret file'
                findings.append(Finding(
                    path, line, 'history', 'shell history',
                    f'the value written to {where} is a command-line literal '
                    f'(`{payload}`), so your shell writes it verbatim into the '
                    f'history file. Take it through `read -rs` and place it with a '
                    f'builtin `printf`.'))

    return findings


# --------------------------------------------------------------------------------
# Markdown → logical shell lines
# --------------------------------------------------------------------------------

_FENCE_RE = re.compile(r'^(?P<indent>[ \t]*)(?P<marker>`{3,}|~{3,})[ \t]*(?P<info>[^`]*)$')


def _strip_comment(line: str) -> tuple[str, str | None]:
    """Return `(code, comment)`, splitting at a `#` that starts a word outside
    quotes. `${VAR#pat}` and a `#` inside quotes are not comments."""
    i, n = 0, len(line)
    in_single = in_double = False
    while i < n:
        ch = line[i]
        if ch == '\\' and not in_single:
            i += 2
            continue
        if ch == "'" and not in_double:
            in_single = not in_single
        elif ch == '"' and not in_single:
            in_double = not in_double
        elif ch == '#' and not in_single and not in_double:
            if i == 0 or line[i - 1].isspace():
                return line[:i], line[i:]
        i += 1
    return line, None


def _quote_state(text: str, in_single: bool, in_double: bool) -> tuple[bool, bool]:
    i, n = 0, len(text)
    while i < n:
        ch = text[i]
        if ch == '\\' and not in_single:
            i += 2
            continue
        if ch == "'" and not in_double:
            in_single = not in_single
        elif ch == '"' and not in_single:
            in_double = not in_double
        i += 1
    return in_single, in_double


def _open_paren_depth(text: str, depth: int) -> int:
    i, n = 0, len(text)
    in_single = in_double = False
    while i < n:
        ch = text[i]
        if ch == '\\' and not in_single:
            i += 2
            continue
        if ch == "'" and not in_double:
            in_single = not in_single
        elif ch == '"' and not in_single:
            in_double = not in_double
        elif not in_single and not in_double:
            if ch == '(':
                depth += 1
            elif ch == ')':
                depth = max(0, depth - 1)
        i += 1
    return depth


_CONTINUES_RE = re.compile(r'(\\|\||\|\||&&)\s*$')


def _shell_fences(text: str) -> list[tuple[int, list[str]]]:
    """`(first_body_line_number, body_lines)` for every fence naming a shell."""
    out = []
    lines = text.split('\n')
    i = 0
    while i < len(lines):
        m = _FENCE_RE.match(lines[i])
        if not m:
            i += 1
            continue
        marker = m.group('marker')
        info = m.group('info').strip().split()
        lang = info[0].lower() if info else ''
        body_start = i + 1
        j = body_start
        close = re.compile(r'^[ \t]*' + re.escape(marker[0]) + '{' + str(len(marker)) + ',}[ \t]*$')
        while j < len(lines) and not close.match(lines[j]):
            j += 1
        if lang in SHELL_INFO:
            out.append((body_start + 1, lines[body_start:j]))
        i = j + 1
    return out


def scan_text(path: str, text: str, enabled: set[str] | None = None) -> list[Finding]:
    """Every finding in one markdown document."""
    enabled = set(RULE_IDS) if enabled is None else set(enabled)
    findings: list[Finding] = []

    for first_line, body in _shell_fences(text):
        buf = Chunk()
        in_single = in_double = False
        depth = 0
        waiver_for_next = False
        pending_waiver = False

        for offset, raw in enumerate(body):
            lineno = first_line + offset
            code, comment = _strip_comment(raw)
            if comment is not None and not code.strip() and not buf.text.strip():
                wm = WAIVER_RE.search(comment)
                if wm:
                    reason = wm.group('reason').strip().lstrip('-—:').strip()
                    if len(reason) < MIN_WAIVER_REASON:
                        if 'waiver-no-reason' in enabled:
                            findings.append(Finding(
                                path, lineno, 'waiver-no-reason', 'the waiver itself',
                                f'a doc-fence-lint waiver needs a reason of at least '
                                f'{MIN_WAIVER_REASON} characters. An off-switch nobody '
                                f'has to justify is not a waiver.'))
                    else:
                        pending_waiver = True
                continue

            # A prompt-style fence (```console) shows the prompt, not the command.
            code = re.sub(r'^\s*[$#]\s+', '', code)

            if not buf.text.strip() and not code.strip():
                # A waiver claims the line BELOW it, which is what its docstring
                # promises. Carried across a blank line it claimed the first
                # command however far down the fence — an off-switch whose scope a
                # reader cannot see from the line it is written on.
                pending_waiver = False
                continue
            if buf.text:
                buf.append('\n', lineno)
            if not buf.text.strip():
                waiver_for_next = pending_waiver
                pending_waiver = False
            buf.append(code, lineno)

            in_single, in_double = _quote_state(code, in_single, in_double)
            depth = _open_paren_depth(code, depth)
            # The continuation test reads the accumulated BUFFER, not the physical
            # line just appended. Read off `code`, a blank or comment-only line
            # after a trailing `|` matched nothing, flushed the buffer, and handed
            # the tail its own pipeline where `idx > 0` is false — so
            # `echo "$SECRET" |`, a blank line, `base64` was green while the
            # one-line form reddened (card#8351, the same defect the splitter's own
            # `cmd |` newline case fixed for the adjacent-line spelling).
            if in_single or in_double or depth > 0 \
                    or _CONTINUES_RE.search(buf.text.rstrip()):
                continue

            findings.extend(_analyse_chunk(path, buf, enabled, waiver_for_next))
            buf = Chunk()
            waiver_for_next = False

        if buf.text.strip():
            findings.extend(_analyse_chunk(path, buf, enabled, waiver_for_next))

    return findings


def _analyse_chunk(path: str, buf: Chunk, enabled: set[str], waived: bool) -> list[Finding]:
    if waived:
        return []
    found: list[Finding] = []
    masked, subs = _mask_substitutions(buf)

    if 'probe' in enabled:
        for off, name in _probe_expansions(buf):
            found.append(Finding(
                path, buf.line_at(off), 'probe', 'stdout',
                f'${{{name}:-…}} substitutes the VALUE it was meant to test for. '
                f'Use ${{{name}:+set}} or [ -n "${name}" ].'))

    for pipeline in _split_segments(masked, captured=False):
        found.extend(_analyse_pipeline(path, pipeline, subs, enabled))
    for sub in subs:
        inner_masked, inner_subs = _mask_substitutions(sub)
        for pipeline in _split_segments(inner_masked, captured=True):
            found.extend(_analyse_pipeline(path, pipeline, inner_subs, enabled))

    # A substitution's contents are analysed twice when it nests; keep one copy.
    seen = set()
    unique = []
    for f in found:
        if f in seen:
            continue
        seen.add(f)
        unique.append(f)

    # `echo "${SECRET:-unset}"` is true under both `probe` and `stdout`. Report the
    # more specific diagnosis only: two findings for one line is noise, and noise is
    # how a lint gets switched off. The line stays red either way.
    probed = {f.line for f in unique if f.rule == 'probe'}
    return [f for f in unique if not (f.rule == 'stdout' and f.line in probed)]


# --------------------------------------------------------------------------------
# Walking
# --------------------------------------------------------------------------------

SKIP_DIRS = {'.git', 'vendor', 'node_modules', '.venv', 'venv', '__pycache__'}


def markdown_files(roots: list[str]) -> list[str]:
    out: list[str] = []
    for root in roots:
        if os.path.isfile(root):
            out.append(root)
            continue
        for dirpath, dirnames, filenames in os.walk(root):
            dirnames[:] = sorted(d for d in dirnames if d not in SKIP_DIRS)
            for fn in sorted(filenames):
                if fn.endswith('.md'):
                    out.append(os.path.join(dirpath, fn))
    return sorted(out)


def scan_paths(roots: list[str], enabled: set[str] | None = None) -> list[Finding]:
    findings: list[Finding] = []
    for path in markdown_files(roots):
        with open(path, encoding='utf-8', errors='replace') as fh:
            findings.extend(scan_text(path, fh.read(), enabled))
    return findings


def main(argv: list[str]) -> int:
    parser = argparse.ArgumentParser(
        prog='check-doc-secret-fences.py',
        description=('BACKSTOP, NOT THE RULE. Flags shell code fences in *.md that '
                     'resolve a secret VALUE onto a readable surface. The rule lives '
                     'in docs/config-schema.md § Handling a secret VALUE and is '
                     'stated over the SURFACE; this program greps a finite list of '
                     'spellings underneath it.'),
        epilog=BACKSTOP_NOTE,
    )
    parser.add_argument('paths', nargs='*', default=['.'],
                        help='files or directories to scan (default: .)')
    args = parser.parse_args(argv)
    roots = args.paths or ['.']

    for root in roots:
        if not os.path.exists(root):
            print(f'check-doc-secret-fences: no such path: {root}', file=sys.stderr)
            return 2

    findings = scan_paths(roots)
    scanned = len(markdown_files(roots))

    if not findings:
        print(f'check-doc-secret-fences: 0 findings over {scanned} markdown file(s).')
        print(f'⚠ {BACKSTOP_NOTE}')
        return 0

    for f in sorted(findings, key=lambda f: (f.path, f.line, f.rule)):
        print(f.render())
    print()
    print(f'{len(findings)} finding(s) over {scanned} markdown file(s). The rule is '
          f'docs/config-schema.md § Handling a secret VALUE — read it rather than '
          f'reverse-engineering this program\'s patterns.')
    print(f'⚠ {BACKSTOP_NOTE}')
    print('If a fence must SHOW a bad form, put it in inline backticks in prose '
          '(which this program never reads), or waive the line above it with '
          '`# doc-fence-lint: allow <reason>`.')
    return 1


if __name__ == '__main__':
    raise SystemExit(main(sys.argv[1:]))
