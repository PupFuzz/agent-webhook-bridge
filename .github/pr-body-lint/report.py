#!/usr/bin/env python3
"""report.py — run the vendored `pr-body-lint.py` REPORTING-ONLY.

Reporting-only is an operator ruling (2026-09-18): the lint runs on every pull request and shows
what it finds, and it never fails a job over a finding, pending an evidence-based decision on
making it blocking. So this wrapper has exactly two outcomes:

  exit 0 — the lint returned a VERDICT. Clean or not, it is published: each finding as a
           `::warning::` annotation, and all of them in the job summary.
  exit 1 — the lint returned NO verdict, announced by `::error::`. That is its own input fault
           (rc 2: the body could not be read), or a crash.

The verdict is read from the program's `--json` document, not from its exit code alone: the lint
answers 1 for "findings", and an uncaught Python exception ALSO exits 1. Trusting the code would
report a crash as "has findings" and publish nothing, which is a broken check reading as a
working one. So rc 1 counts as findings only when stdout is the lint's own JSON document and
that document agrees with the code; anything else is a crash.

Every argument is passed to `pr-body-lint.py` unchanged (it owns `--env` / `--body-file` /
`--label`); `--json` is added here.
"""
import html
import json
import os
import subprocess
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
LINT = os.path.join(HERE, "pr-body-lint.py")
RULING = "Reporting-only by operator ruling (2026-09-18): a finding never fails this job."


def cmd_data(s):
    """Escape a workflow-command DATA value, so body text cannot end the command or start one."""
    return s.replace("%", "%25").replace("\r", "%0D").replace("\n", "%0A")


def cmd_prop(s):
    return cmd_data(s).replace(":", "%3A").replace(",", "%2C")


def summary(lines):
    path = os.environ.get("GITHUB_STEP_SUMMARY")
    if path:
        with open(path, "a", encoding="utf-8") as fh:
            fh.write("\n".join(lines) + "\n")
    else:
        sys.stdout.write("\n".join(lines) + "\n")


def unmeasured(why, proc):
    print("::error title=%s::%s" % (cmd_prop("pr-body-lint did not measure this body"),
                                    cmd_data(why)))
    for line in (proc.stderr or "").splitlines():
        print("  | " + line)
    summary(["## PR body lint: NOT MEASURED", "",
             html.escape(why, quote=False), "",
             "This is a fault in the check, not a finding about the body. " + RULING])
    return 1


def main(argv):
    proc = subprocess.run([sys.executable, LINT, "--json"] + argv,
                          capture_output=True, text=True, encoding="utf-8")
    try:
        doc = json.loads(proc.stdout)
    except ValueError:
        doc = None
    if proc.returncode not in (0, 1):
        return unmeasured("pr-body-lint exited %d (0 = clean, 1 = findings; anything else is a "
                          "fault, 2 = the body could not be read)." % proc.returncode, proc)
    if not (isinstance(doc, dict) and doc.get("tool") == "pr-body-lint"
            and isinstance(doc.get("clean"), bool) and isinstance(doc.get("findings"), list)):
        return unmeasured("pr-body-lint exited %d without its JSON verdict on stdout, so it "
                          "crashed rather than judged the body." % proc.returncode, proc)
    if doc["clean"] != (proc.returncode == 0) or doc["clean"] != (not doc["findings"]):
        return unmeasured("pr-body-lint's exit code (%d) and its JSON verdict disagree."
                          % proc.returncode, proc)

    label = doc["label"]
    if doc["clean"]:
        print("pr-body-lint: %s meets the standard." % label)
        summary(["## PR body lint: no findings", "", RULING])
        return 0

    out = ["## PR body lint: findings (reporting-only)", "",
           "The standard is `skills/release-pr/SKILL.md § PR body` in the coord plugin. " + RULING,
           ""]
    for f in doc["findings"]:
        where = "line %d" % f["line"] if f["line"] else "the body"
        print("::warning title=%s::%s" % (
            cmd_prop("pr-body-lint · %s" % f["rule"]),
            cmd_data("%s: %s%s" % (where, f["message"], "\n> " + f["text"] if f["text"] else ""))))
        out.append("- **%s** · `%s` — %s"
                   % (where, f["rule"], html.escape(f["message"], quote=False)))
        if f["text"]:
            out.append("  <br><code>%s</code>" % html.escape(f["text"], quote=False))
    summary(out)
    print("pr-body-lint: %s has findings; exiting 0 (reporting-only)." % label)
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
