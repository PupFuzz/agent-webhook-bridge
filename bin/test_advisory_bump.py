"""Unit tests for bin/advisory-bump.py — the dependency audit's lockfile bump PR opener (card#12198).

Red-when-reverted: each case names one decision the tool makes and asserts the outcome that
distinguishes a right answer from the plausible wrong one — an out-of-range fix that must NOT
become a PR, an empty audit output that must NOT read as "nothing to bump", a re-run that must
NOT open a second PR, a missing token that must FAIL rather than skip or fall back.

The plan cases run against a REAL temporary git repository (git is the boundary the branches are
built on); `npm update`, `npm version` and the capabilities generator are replaced by `FakeTools`,
whose edits are the test's input, not npm's. The open cases replace `gh` and `git push`, since those
are the network.
"""

import importlib.util
import io
import json
import os
import subprocess
import tempfile
import unittest
from contextlib import redirect_stdout
from pathlib import Path
from unittest import mock

_SPEC = importlib.util.spec_from_file_location("advisory_bump", Path(__file__).with_name("advisory-bump.py"))
ab = importlib.util.module_from_spec(_SPEC)
assert _SPEC.loader is not None
_SPEC.loader.exec_module(ab)


def row(pkg="body-parser", ver="2.3.0", ghsa="GHSA-27r3-jxmh-p828", patched='patched in "2.4.0"',
        eco="npm", rng=">= 2.0.0, < 2.4.0", repo="expressjs/body-parser"):
    return {"ecosystem": eco, "package": pkg, "version": ver, "ghsa": ghsa, "severity": "high",
            "range": rng, "patched": patched, "repo": repo}


def lockfile(bp_version="2.3.0", snapshot="0.9.72", express_range="^2.2.1"):
    return {
        "name": "agent-webhook-bridge-channel-server",
        "version": snapshot,
        "lockfileVersion": 3,
        "packages": {
            "": {"name": "agent-webhook-bridge-channel-server", "version": snapshot,
                 "dependencies": {"@modelcontextprotocol/sdk": "^1.32.1"}},
            "node_modules/@modelcontextprotocol/sdk": {"version": "1.32.1", "dependencies": {"express": "^5.0.1"}},
            "node_modules/express": {"version": "5.2.1", "dependencies": {"body-parser": express_range}},
            "node_modules/body-parser": {"version": bp_version, "dependencies": {"iconv-lite": "^0.7.2"}},
            "node_modules/iconv-lite": {"version": "0.7.2"},
            "node_modules/left-pad": {"version": "1.0.0", "dev": True},
        },
    }


CHANGELOG = """# Changelog

## [Unreleased]

### Fixed

- **card#1** — something else.

### Upgrade warnings

- Run `php artisan bridge:client-pack:install` as the receiver's user to publish client snapshot 0.9.72. Until you do, seats stay on the client they have.

## [0.1.0] - 2026-01-01

### Fixed

- old.
"""


class Findings(unittest.TestCase):
    def test_an_empty_output_is_refused_not_read_as_no_findings(self):
        for raw in (None, "", "   "):
            with self.assertRaises(ab.BumpError) as cm:
                ab.parse_findings(raw)
            self.assertIn("exported no source2_affected", str(cm.exception))

    def test_an_empty_array_is_the_audit_saying_nothing(self):
        self.assertEqual(ab.parse_findings("[]"), [])

    def test_a_non_array_or_a_row_missing_a_field_is_refused(self):
        for raw in ('{"a": 1}', "[1]", json.dumps([{"package": "x"}]), "not json"):
            with self.assertRaises(ab.BumpError):
                ab.parse_findings(raw)

    def test_the_finding_line_is_the_audit_s_printed_shape(self):
        self.assertEqual(
            ab.finding_line(row()),
            'SOURCE2 body-parser@2.3.0 GHSA-27r3-jxmh-p828 high [>= 2.0.0, < 2.4.0] patched in "2.4.0" '
            "(repo-level on expressjs/body-parser)")


class Candidates(unittest.TestCase):
    def test_an_npm_row_in_the_lockfile_with_one_fix_is_a_candidate(self):
        cands, manual = ab.group_candidates([row()], lockfile())
        self.assertEqual(manual, [])
        self.assertEqual(list(cands), ["body-parser"])
        self.assertEqual(cands["body-parser"][0]["fix"], "2.4.0")

    def test_rows_the_tool_does_not_automate_are_reported_with_a_reason(self):
        rows = [row(eco="composer", pkg="league/commonmark"),
                row(patched='patched "2.4.0" overruled by an upper bound of the range, so not cleared'),
                row(pkg="not-here"),
                row(ghsa="GHSA-aaaa-bbbb-cccc", patched='patched in "2.4.0-beta"')]
        cands, manual = ab.group_candidates(rows, lockfile())
        self.assertEqual(cands, {})
        reasons = [m["reason"] for m in manual]
        self.assertIn("composer findings are not automated", reasons[0])
        self.assertIn("did not clear this to one fix", reasons[1])
        self.assertIn("no production entry not-here@2.3.0", reasons[2])
        self.assertIn("not a list of versions", reasons[3])

    def test_an_id_that_is_not_a_ghsa_is_not_built_into_a_title(self):
        _, manual = ab.group_candidates([row(ghsa="GHSA-1; rm -rf")], lockfile())
        self.assertIn("is not a GHSA ID", manual[0]["reason"])

    def test_free_text_fields_are_flattened_to_one_line_without_backticks(self):
        r = ab.parse_findings(json.dumps([row(rng="< 2.4.0`\n@everyone")]))[0]
        self.assertEqual(r["range"], "< 2.4.0 @everyone")

    def test_a_dev_only_entry_is_not_a_channel_server_production_dependency(self):
        _, manual = ab.group_candidates([row(pkg="left-pad", ver="1.0.0", patched='patched in "1.0.1"')], lockfile())
        self.assertEqual(len(manual), 1)

    def test_the_same_advisory_on_two_lockfile_copies_is_one_row(self):
        cands, _ = ab.group_candidates([row(), row()], lockfile())
        self.assertEqual(len(cands["body-parser"]), 1)

    def test_a_patched_list_takes_the_smallest_fix_above_the_flagged_version(self):
        self.assertEqual(ab.fix_for("2.0.1", "1.2.3, 2.0.5"), ("2.0.5", None))
        self.assertEqual(ab.fix_for("1.0.0", ">= 1.2.3, 2.0.5"), ("1.2.3", None))
        self.assertIsNone(ab.fix_for("3.0.0", "1.2.3, 2.0.5")[0])


class InRange(unittest.TestCase):
    def rows(self):
        return [dict(row(), fix="2.4.0")]

    def test_an_update_that_reaches_the_fix_leaves_nothing_affected(self):
        self.assertEqual(ab.still_affected(lockfile(bp_version="2.4.1"), "body-parser", self.rows()), [])

    def test_an_update_the_parent_range_held_below_the_fix_is_out_of_range(self):
        left = ab.still_affected(lockfile(bp_version="2.3.5"), "body-parser", self.rows())
        self.assertEqual(left, ["node_modules/body-parser at 2.3.5 (flagged 2.3.0, fix 2.4.0)"])

    def test_a_copy_below_the_flagged_version_is_not_this_finding(self):
        lock = lockfile(bp_version="2.4.0")
        lock["packages"]["node_modules/x/node_modules/body-parser"] = {"version": "1.20.0"}
        self.assertEqual(ab.still_affected(lock, "body-parser", self.rows()), [])

    def test_a_version_with_a_tag_is_refused_not_guessed(self):
        with self.assertRaises(ab.BumpError):
            ab.still_affected(lockfile(bp_version="2.4.0-rc.1"), "body-parser", self.rows())

    def test_parents_and_path_are_read_from_the_lockfile(self):
        lock = lockfile()
        self.assertEqual(ab.declaring_parents(lock, "body-parser"), ["express@5.2.1's `^2.2.1`"])
        self.assertEqual(ab.dependency_path(lock, "body-parser"), ["@modelcontextprotocol/sdk", "express", "body-parser"])


class Slug(unittest.TestCase):
    NAMES = ["body-parser", "@scope/x", "scope-x", "scope--x", "scope__x", "scope_x", "@scope/x-y", "@scope-x/y",
             "@a/b", "a--b", "a__b", "@a_/b", "a_5f__b", "lodash.merge", "a..b", "a._2eb", "JSONStream", "jsonstream",
             "x~y", "x*y", "x(y)", "x!'y", "@hono/node-server", "hono-node-server", "hono"]

    def test_no_two_packages_share_a_branch(self):
        slugs = {}
        for name in self.NAMES:
            self.assertNotIn(ab.slug(name), slugs, f"{name} and {slugs.get(ab.slug(name))} share a branch")
            slugs[ab.slug(name)] = name

    def test_the_scope_stays_readable_and_a_plain_name_is_unchanged(self):
        self.assertEqual(ab.slug("body-parser"), "body-parser")
        self.assertEqual(ab.slug("lodash.merge"), "lodash.merge")
        self.assertEqual(ab.slug("@hono/node-server"), "hono__node-server")

    def test_every_branch_is_a_valid_git_ref(self):
        for name in self.NAMES:
            branch = f"{ab.BRANCH_PREFIX}{ab.slug(name)}-1.2.3"
            rc = subprocess.run(["git", "check-ref-format", "--branch", branch], capture_output=True).returncode
            self.assertEqual(rc, 0, f"{name} -> {branch} is not a valid branch name")


class Changelog(unittest.TestCase):
    def test_the_bullet_goes_first_under_fixed_and_the_snapshot_line_moves(self):
        out = ab.edit_changelog(CHANGELOG, "- **npm/x 1 → 2** — b.", "0.9.73", "x", "1")
        unreleased = out.split("## [0.1.0]")[0]
        self.assertIn("### Fixed\n\n- **npm/x 1 → 2** — b.\n- **card#1**", unreleased)
        self.assertIn("publish client snapshot 0.9.73.", unreleased)
        self.assertNotIn("0.9.72", unreleased)
        self.assertTrue(out.endswith("### Fixed\n\n- old.\n"), "the released section must be untouched")

    def test_missing_sections_are_created_inside_unreleased(self):
        text = "# Changelog\n\n## [Unreleased]\n\n## [0.1.0] - 2026-01-01\n\n- old.\n"
        out = ab.edit_changelog(text, "- **npm/x 1 → 2** — b.", "0.9.73", "x", "1")
        unreleased, released = out.split("## [0.1.0]")
        self.assertIn("### Fixed\n\n- **npm/x 1 → 2** — b.", unreleased)
        self.assertIn("### Upgrade warnings\n\n- Run `php artisan bridge:client-pack:install`", unreleased)
        self.assertIn("snapshot 0.9.73", unreleased)
        self.assertIn("pins x 1", unreleased)
        self.assertEqual(released, " - 2026-01-01\n\n- old.\n")

    def test_no_unreleased_heading_is_refused(self):
        with self.assertRaises(ab.BumpError):
            ab.edit_changelog("# Changelog\n", "- b", "1.0.0", "x", "1")


class FakeTools(ab.Shell):
    """Real git; `npm update`, `npm version` and `node bin/gen-client-capabilities.mjs` are faked.

    The fake `npm update` sets the version the test names (`resolve_to`), so these cases test what
    the tool does with npm's answer, never the answer: the in-range decision rests on npm's resolver,
    which no test here runs.
    """

    def __init__(self, resolve_to):
        self.resolve_to = resolve_to
        self.calls = []

    def run(self, cmd, cwd=None, env=None, check=True):
        self.calls.append(cmd)
        if cmd[0] == "npm" and cmd[1] == "update":
            p = Path(cwd) / "package-lock.json"
            lock = json.loads(p.read_text())
            lock["packages"]["node_modules/body-parser"]["version"] = self.resolve_to
            if self.resolve_to == "2.4.0":
                lock["packages"]["node_modules/iconv-lite"]["version"] = "0.7.3"
            p.write_text(json.dumps(lock, indent=2) + "\n")
            return subprocess.CompletedProcess(cmd, 0, "", "")
        if cmd[0] == "npm" and cmd[1] == "version":
            for name in ("package.json", "package-lock.json"):
                p = Path(cwd) / name
                doc = json.loads(p.read_text())
                doc["version"] = "0.9.73"
                if name == "package-lock.json":
                    doc["packages"][""]["version"] = "0.9.73"
                p.write_text(json.dumps(doc, indent=2) + "\n")
            return subprocess.CompletedProcess(cmd, 0, "v0.9.73\n", "")
        if cmd[0] == "node":
            p = Path(cwd) / ab.CAPABILITIES
            p.write_text(json.dumps({"current_client_version": "0.9.73"}) + "\n")
            return subprocess.CompletedProcess(cmd, 0, "", "")
        return super().run(cmd, cwd=cwd, env=env, check=check)


class Plan(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.repo = Path(self.tmp.name) / "repo"
        (self.repo / ab.CHANNEL_DIR).mkdir(parents=True)
        (self.repo / "resources").mkdir()
        (self.repo / "docs").mkdir()
        (self.repo / ab.LOCKFILE).write_text(json.dumps(lockfile(), indent=2) + "\n")
        (self.repo / ab.MANIFEST).write_text(json.dumps({"name": "x", "version": "0.9.72"}, indent=2) + "\n")
        (self.repo / ab.CAPABILITIES).write_text(json.dumps({"current_client_version": "0.9.72"}) + "\n")
        (self.repo / ab.CHANGELOG).write_text(CHANGELOG)
        git = ["git", "-C", str(self.repo)]
        subprocess.run(git[:1] + ["init", "-q", "-b", "dev", str(self.repo)], check=True)
        subprocess.run(git + ["add", "-A"], check=True)
        subprocess.run(git + ["-c", "user.name=t", "-c", "user.email=t@example.invalid", "commit", "-qm", "base"],
                       check=True)
        self.work = Path(self.tmp.name) / "work"
        self.work.mkdir()

    def tearDown(self):
        self.tmp.cleanup()

    def branches(self):
        out = subprocess.run(["git", "-C", str(self.repo), "branch", "--list", "advisory-bump/*"],
                             capture_output=True, text=True, check=True).stdout
        return [b.strip(" *+") for b in out.splitlines()]

    def test_an_in_range_fix_builds_one_branch_with_the_four_file_shape(self):
        p = ab.plan(FakeTools("2.4.0"), str(self.repo), [row()], "dev", "", str(self.work))
        self.assertEqual(p["errors"], [])
        self.assertEqual(p["manual"], [])
        self.assertEqual(len(p["bumps"]), 1)
        b = p["bumps"][0]
        self.assertEqual(b["branch"], "advisory-bump/npm-body-parser-2.4.0")
        self.assertEqual(self.branches(), [b["branch"]])
        files = subprocess.run(["git", "-C", str(self.repo), "show", "--name-only", "--format=", b["head"]],
                               capture_output=True, text=True, check=True).stdout.split()
        self.assertEqual(sorted(files), sorted([ab.LOCKFILE, ab.MANIFEST, ab.CAPABILITIES, ab.CHANGELOG]))
        cl = subprocess.run(["git", "-C", str(self.repo), "show", f"{b['head']}:{ab.CHANGELOG}"],
                            capture_output=True, text=True, check=True).stdout
        self.assertIn("- **npm/body-parser 2.3.0 → 2.4.0 in examples/channel-servers**", cl)
        self.assertIn("iconv-lite 0.7.2 → 0.7.3", cl)
        self.assertIn("Channel-server snapshot 0.9.72 → 0.9.73", cl)
        self.assertIn("publish client snapshot 0.9.73", cl)
        self.assertEqual(b["title"], "deps(channel-servers): bump body-parser to 2.4.0 (GHSA-27r3-jxmh-p828)")
        self.assertIn("express@5.2.1's `^2.2.1`", b["body"])
        self.assertNotIn("Co-Authored-By", b["body"])
        self.assertEqual(subprocess.run(["git", "-C", str(self.repo), "worktree", "list"], capture_output=True,
                                        text=True, check=True).stdout.count("\n"), 1, "a worktree was left behind")

    def test_an_unmoved_copy_on_another_line_is_not_named_as_the_new_version(self):
        lock = lockfile()
        lock["packages"]["node_modules/x/node_modules/body-parser"] = {"version": "1.20.0"}
        (self.repo / ab.LOCKFILE).write_text(json.dumps(lock, indent=2) + "\n")
        subprocess.run(["git", "-C", str(self.repo), "-c", "user.name=t", "-c", "user.email=t@example.invalid",
                        "commit", "-qam", "second copy"], check=True)
        p = ab.plan(FakeTools("2.4.0"), str(self.repo), [row()], "dev", "", str(self.work))
        self.assertEqual(p["bumps"][0]["new"], "2.4.0")
        self.assertEqual(p["bumps"][0]["branch"], "advisory-bump/npm-body-parser-2.4.0")

    def test_an_out_of_range_fix_opens_nothing_and_is_reported(self):
        p = ab.plan(FakeTools("2.3.9"), str(self.repo), [row()], "dev", "", str(self.work))
        self.assertEqual(p["bumps"], [])
        self.assertEqual(self.branches(), [])
        self.assertEqual(len(p["manual"]), 1)
        self.assertIn("out of range", p["manual"][0]["reason"])
        self.assertIn("express@5.2.1's `^2.2.1`", p["manual"][0]["reason"])
        self.assertIn("2.3.9", p["manual"][0]["reason"])

    def test_an_out_of_range_package_never_reaches_the_snapshot_bump(self):
        sh = FakeTools("2.3.9")
        ab.plan(sh, str(self.repo), [row()], "dev", "", str(self.work))
        self.assertNotIn(["npm", "version", "patch", "--no-git-tag-version", "--ignore-scripts"], sh.calls)


class FakeGh:
    """Records every call; answers `gh api` from a table. Nothing reaches the network."""

    def __init__(self, open_prs=(), same_branch=()):
        self.open_prs = list(open_prs)
        self.same_branch = list(same_branch)
        self.calls = []
        self.envs = []

    def run(self, cmd, cwd=None, env=None, check=True):
        self.calls.append(cmd)
        self.envs.append(env)
        if cmd[:2] == ["gh", "api"] and "--paginate" in cmd:
            return subprocess.CompletedProcess(cmd, 0, json.dumps([self.open_prs]), "")
        if cmd[:2] == ["gh", "api"]:
            return subprocess.CompletedProcess(cmd, 0, json.dumps(self.same_branch), "")
        if "ls-remote" in cmd:
            return subprocess.CompletedProcess(cmd, 0, "", "")
        if cmd[:3] == ["gh", "pr", "create"]:
            return subprocess.CompletedProcess(cmd, 0, "https://github.com/o/r/pull/9\n", "")
        return subprocess.CompletedProcess(cmd, 0, "", "")


PLAN = {"base": "dev", "base_sha": "a" * 40, "manual": [], "errors": [],
        "bumps": [{"package": "body-parser", "branch": "advisory-bump/npm-body-parser-2.4.0", "head": "b" * 40,
                   "title": "deps(channel-servers): bump body-parser to 2.4.0 (GHSA-27r3-jxmh-p828)",
                   "body": "body", "old": "2.3.0", "new": "2.4.0", "ghsas": ["GHSA-27r3-jxmh-p828"]}]}


class Open(unittest.TestCase):
    def test_a_missing_token_with_a_bump_to_open_fails_naming_the_secret_and_the_bump(self):
        gh = FakeGh()
        lines, errors = ab.open_bumps(gh, ".", PLAN, "", "o/r")
        self.assertEqual(errors, 1)
        text = "\n".join(lines)
        self.assertIn("`ADVISORY_BUMP_TOKEN` is not set", text)
        self.assertIn("advisory-bump/npm-body-parser-2.4.0", text)
        self.assertEqual(gh.calls, [], "nothing may be tried without the token — no GITHUB_TOKEN fallback")

    def test_no_bump_and_no_token_is_not_a_failure(self):
        lines, errors = ab.open_bumps(FakeGh(), ".", dict(PLAN, bumps=[]), "", "o/r")
        self.assertEqual((lines, errors), (["No bump to open."], 0))

    def test_a_bump_already_open_is_skipped_and_nothing_is_pushed(self):
        gh = FakeGh(same_branch=[{"number": 7, "state": "open", "url": "u"}])
        lines, errors = ab.open_bumps(gh, ".", PLAN, "tok", "o/r")
        self.assertEqual(errors, 0)
        self.assertIn("already open as #7", lines[0])
        self.assertFalse(any("push" in c for c in gh.calls))
        self.assertFalse(any(c[:3] == ["gh", "pr", "create"] for c in gh.calls))

    def test_another_open_bump_for_the_same_package_is_one_too_many(self):
        gh = FakeGh(open_prs=[{"number": 5, "head": {"ref": "advisory-bump/npm-body-parser-2.3.5"}, "html_url": "u"}])
        lines, _ = ab.open_bumps(gh, ".", PLAN, "tok", "o/r")
        self.assertIn("another bump PR for body-parser is open: #5", lines[0])
        self.assertFalse(any("push" in c for c in gh.calls))

    def test_an_open_bump_of_a_package_whose_name_extends_this_one_is_not_this_package_s(self):
        for other, pkg in (("express-rate-limit", "express"), ("@hono/node-server", "hono"), ("@hono/x", "hono")):
            open_pr = {"number": 5, "head": f"{ab.BRANCH_PREFIX}{ab.slug(other)}-8.7.1", "url": "u"}
            bump = dict(PLAN["bumps"][0], package=pkg, branch=f"{ab.BRANCH_PREFIX}{ab.slug(pkg)}-5.2.2")
            self.assertIsNone(ab.prior_decision(bump, [], [open_pr]), f"{other}'s open PR blocked {pkg}")
        same = {"number": 6, "head": f"{ab.BRANCH_PREFIX}{ab.slug('express')}-5.2.1", "url": "u"}
        bump = dict(PLAN["bumps"][0], package="express", branch=f"{ab.BRANCH_PREFIX}{ab.slug('express')}-5.2.2")
        self.assertIn("another bump PR for express is open: #6", ab.prior_decision(bump, [], [same]))

    def test_a_closed_bump_is_not_reopened(self):
        self.assertIn("closed without merging as #3",
                      ab.prior_decision(PLAN["bumps"][0], [{"number": 3, "state": "closed", "merged_at": None}], []))

    def test_a_new_bump_is_pushed_and_opened_with_the_token_only(self):
        gh = FakeGh()
        with mock.patch.dict(os.environ, {"GITHUB_TOKEN": "workflow-token"}):
            lines, errors = ab.open_bumps(gh, ".", PLAN, "tok", "o/r")
        self.assertEqual(errors, 0)
        self.assertIn("opened body-parser: https://github.com/o/r/pull/9", lines[0])
        push = next(c for c in gh.calls if "push" in c)
        self.assertNotIn("--force", push)
        self.assertEqual(push[-1], f"{'b' * 40}:refs/heads/advisory-bump/npm-body-parser-2.4.0")
        create = next(c for c in gh.calls if c[:3] == ["gh", "pr", "create"])
        self.assertEqual(create[create.index("--base") + 1], "dev")
        for env in gh.envs:
            self.assertEqual(env["GH_TOKEN"], "tok")
            self.assertNotIn("GITHUB_TOKEN", env)
        self.assertFalse(any("tok" in part for c in gh.calls for part in c), "the token reached an argv")

    def test_main_open_without_the_secret_exits_one(self):
        with tempfile.TemporaryDirectory() as d:
            plan_path = Path(d) / "plan.json"
            plan_path.write_text(json.dumps(PLAN))
            env = {"GITHUB_REPOSITORY": "o/r", "ADVISORY_BUMP_TOKEN": "", "GITHUB_STEP_SUMMARY": str(Path(d) / "s")}
            with mock.patch.dict(os.environ, env), redirect_stdout(io.StringIO()) as out:
                rc = ab.main(["open", "--plan", str(plan_path)], sh=FakeGh())
            self.assertEqual(rc, 1)
            self.assertIn("::error title=advisory bump::FAILED: the repository secret `ADVISORY_BUMP_TOKEN`", out.getvalue())
            self.assertIn("would have opened", (Path(d) / "s").read_text())

    def test_main_plan_refuses_an_unset_findings_output(self):
        with mock.patch.dict(os.environ, {"AFFECTED": ""}), redirect_stdout(io.StringIO()):
            rc = ab.main(["plan", "--findings-env", "AFFECTED", "--base", "dev", "--plan-out", "/dev/null"], sh=FakeGh())
        self.assertEqual(rc, 1)


if __name__ == "__main__":
    unittest.main()
