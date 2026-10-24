#!/usr/bin/env python3
"""advisory-bump.py — open the channel-server lockfile bump PR the dependency audit's findings call for.

The bump job of `.github/workflows/dependency-audit.yml` runs this on the integration-branch leg
of the daily "Dependency advisory ref matrix" (card#12198, DL-493). It reads the audit's exported
findings of BOTH sources (the `affected` output: one object per AFFECTED row, each naming its
`source`; card#12309):

  * source "2", a repository advisory: the rows the audit prints as
    `SOURCE2 <pkg>@<ver> <GHSA> <sev> [range] patched in "<ver>" (repo-level on ...)`.
  * source "1", the OSV scan of the global advisory database: the rows the audit prints as
    `SOURCE1 <eco>:<pkg>@<ver> <id>`, exported with `patched in "<v>, ..."` listing every `fixed`
    version of the OSV record's ranges for that package, or a text naming none.

Either way the fix is the lowest listed version above the flagged one (`fix_for`), and a row with
no such version is left for a human. One advisory both sources report (same package, version and
GHSA ID) is one row: the one that names a fix, the higher fix when both do. A package flagged by
several rows, of either source, is one bump. Then, per npm package, it does what the hand-cut
bumps did (PRs #834, #966, #968):

  * `npm update <pkg> --package-lock-only --ignore-scripts` in examples/channel-servers. npm's own
    resolver is the in-range oracle: it moves the package to the newest version every declaring
    range admits, and nothing further. If afterwards a lockfile entry of the package is still at or
    above a flagged version and below that row's fix, the fix is OUT OF RANGE for a declaring
    parent: the finding is reported for a human, and no PR is opened.
  * `npm version patch` there (the DL-038 snapshot bump the version-bump guard requires; it moves
    package.json and both lockfile version sites together, DL-268), then
    `node bin/gen-client-capabilities.mjs`, then a `[Unreleased]` `### Fixed` bullet in
    docs/CHANGELOG.md and the client-pack upgrade line's snapshot version.

Two subcommands, so the token lives in exactly one workflow step:

  plan  (no token)  build one local branch + commit per in-range package from --base, and write the
                    plan JSON (bumps to open, findings left for a human) and a job summary.
  open  (token)     for each planned bump: skip it when a PR for that package is already open, or
                    when this exact bump was already opened before (open, merged, or closed — a
                    closed one is not reopened); otherwise push the branch (never forced) and open
                    the PR against --base. The token is read from ADVISORY_BUMP_TOKEN and handed to
                    `gh` as GH_TOKEN in the child environment only, with GITHUB_TOKEN stripped, so
                    there is no fallback to the workflow token: a PR opened with GITHUB_TOKEN runs
                    no CI. With bumps to open and no token, `open` FAILS naming the secret and every
                    bump it would have opened.

Exit codes: 0 done (skips and manual findings are reported, not failures) · 1 a bump could not be
built or opened, the token is missing with a bump to open, or the findings input is unusable ·
2 usage.

Run locally (no PR is opened without `open`):
  AFFECTED="$(cat findings.json)" python3 bin/advisory-bump.py plan --findings-env AFFECTED \\
      --base dev --plan-out /tmp/plan.json
"""

import argparse
import json
import os
import re
import subprocess
import sys
import tempfile
from pathlib import Path

CHANNEL_DIR = "examples/channel-servers"
LOCKFILE = f"{CHANNEL_DIR}/package-lock.json"
MANIFEST = f"{CHANNEL_DIR}/package.json"
CAPABILITIES = "resources/client-capabilities.json"
CHANGELOG = "docs/CHANGELOG.md"
BRANCH_PREFIX = "advisory-bump/npm-"
TOKEN_ENV = "ADVISORY_BUMP_TOKEN"
COMMIT_NAME = "github-actions[bot]"
COMMIT_EMAIL = "41898282+github-actions[bot]@users.noreply.github.com"

PATCHED_RE = re.compile(r'^patched in "(?P<text>.*)"$')
VERSION_RE = re.compile(r"^\d+(?:\.\d+)*$")
PATCHED_PIECE_RE = re.compile(r"^(?:>=|=>)?\s*(?P<v>\d+(?:\.\d+)*)$")
SNAPSHOT_LINE_RE = re.compile(r"(publish client snapshot )(\d+\.\d+\.\d+)")
# The shape the audit itself checks before building an API path from an ID (reviewed_records).
GHSA_RE = re.compile(r"^GHSA-[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4}$")
# The audit's two sources, as each `affected` row names its own.
SOURCES = ("1", "2")


class BumpError(Exception):
    """A state this tool refuses to guess past; the message names it."""


# ── versions ────────────────────────────────────────────────────────────────────────────────


def vkey(text):
    """Bare dotted numbers -> a zero-padded comparable tuple, or None when not that shape.

    A pre-release or build tag is NOT ordered here (npm and the audit's matcher order tags
    differently), so a version carrying one is refused by the caller rather than guessed at.
    """
    if not isinstance(text, str) or not VERSION_RE.match(text):
        return None
    parts = [int(p) for p in text.split(".")]
    return tuple(parts + [0] * (3 - len(parts))) if len(parts) < 3 else tuple(parts)


def fix_for(flagged, patched_text):
    """The fix that applies to `flagged`: the smallest listed patched version above it.

    Returns (fix, None) or (None, why). Only a list of bare versions (each optionally `>=`) is
    read; anything else is left for a human.
    """
    pieces = [p.strip() for p in re.split(r"\s*(?:,|;|\|\|)\s*", patched_text.strip()) if p.strip()]
    versions = []
    for piece in pieces:
        m = PATCHED_PIECE_RE.match(piece)
        if not m:
            return None, f'patched text "{patched_text}" is not a list of versions'
        versions.append(m.group("v"))
    if not versions:
        return None, "no patched version"
    fk = vkey(flagged)
    if fk is None:
        return None, f"flagged version {flagged} is not bare dotted numbers"
    above = sorted((v for v in versions if vkey(v) > fk), key=vkey)
    if not above:
        return None, f'no version in "{patched_text}" is above {flagged}'
    return above[0], None


# ── findings ────────────────────────────────────────────────────────────────────────────────


def parse_findings(raw):
    """The audit's `affected` output -> list of row dicts.

    EMPTY is not "no findings": an audit that exported nothing did not reach its verdict, or the
    output wiring broke, and reading it as zero bumps would be the green-over-nothing this audit
    exists to prevent. `[]` is the audit saying it found nothing to act on.
    """
    if raw is None or raw.strip() == "":
        raise BumpError("the audit exported no affected output — it did not reach its verdict, "
                        "or the output is not wired; nothing was decided")
    try:
        rows = json.loads(raw)
    except json.JSONDecodeError as exc:
        raise BumpError(f"affected is not JSON: {exc}") from None
    if not isinstance(rows, list) or not all(isinstance(r, dict) for r in rows):
        raise BumpError("affected is not a JSON array of objects")
    for r in rows:
        if r.get("source") not in SOURCES:
            raise BumpError(f"an affected row names no source \"1\" or \"2\": {json.dumps(r)[:200]}")
        for key in ("ecosystem", "package", "version", "ghsa", "patched"):
            if not isinstance(r.get(key), str):
                raise BumpError(f"an affected row has no string '{key}': {json.dumps(r)[:200]}")
        # `range` and `severity` are the advisory maintainer's free text and land in a commit, a
        # CHANGELOG line and a PR body: one line, no backticks to break the markdown they sit in.
        for key in ("range", "severity", "repo"):
            r[key] = re.sub(r"[`\r\n]+", " ", str(r.get(key) or ""))[:200]
    return rows


def finding_line(row):
    """The row on one line, led as the audit leads that source's line in its FINDINGS block.

    A SOURCE2 line is the audit's own. The audit prints a SOURCE1 line only up to the ID; the
    severity, range and fixes after it come from the exported row.
    """
    if row["source"] == "1":
        return (f'SOURCE1 {row["ecosystem"]}:{row["package"]}@{row["version"]} {row["ghsa"]} {row.get("severity", "")} '
                f'[{row.get("range", "")}] {row["patched"]} (global advisory database)')
    return (f'SOURCE2 {row["package"]}@{row["version"]} {row["ghsa"]} {row.get("severity", "")} '
            f'[{row.get("range", "")}] {row["patched"]} (repo-level on {row.get("repo", "?")})')


# ── the lockfile ────────────────────────────────────────────────────────────────────────────


def entry_name(key, value):
    return value.get("name") or key.rsplit("node_modules/", 1)[-1]


def instances(lock, package):
    """[(key, version)] of every lockfile entry of `package` (dev entries included)."""
    out = []
    for key, value in (lock.get("packages") or {}).items():
        if key and entry_name(key, value).lower() == package.lower():
            out.append((key, value.get("version", "")))
    return out


def production_instance(lock, package, version):
    for key, value in (lock.get("packages") or {}).items():
        if (key and not value.get("dev") and entry_name(key, value).lower() == package.lower()
                and value.get("version") == version):
            return True
    return False


def declaring_parents(lock, package):
    """["<parent>@<ver>'s `<range>`"] for every entry that declares `package`."""
    out = []
    for key, value in sorted((lock.get("packages") or {}).items()):
        for field in ("dependencies", "optionalDependencies", "peerDependencies"):
            rng = (value.get(field) or {}).get(package)
            if rng is None:
                continue
            who = "package.json" if key == "" else f'{entry_name(key, value)}@{value.get("version", "?")}'
            out.append(f"{who}'s `{rng}`")
    return out


def dependency_path(lock, package):
    """The shortest declared chain from the root to `package`, by name ('a → b'), or None."""
    packages = lock.get("packages") or {}
    by_name = {}
    for key, value in packages.items():
        if key:
            by_name.setdefault(entry_name(key, value).lower(), []).append(value)

    def deps(value):
        names = []
        for field in ("dependencies", "optionalDependencies", "peerDependencies"):
            names.extend((value.get(field) or {}).keys())
        return names

    root = packages.get("", {})
    queue = [[n] for n in deps(root)]
    seen = set()
    while queue:
        chain = queue.pop(0)
        name = chain[-1]
        if name.lower() == package.lower():
            return chain
        if name.lower() in seen:
            continue
        seen.add(name.lower())
        for value in by_name.get(name.lower(), []):
            queue.extend(chain + [d] for d in deps(value))
    return None


def still_affected(lock, package, rows):
    """After the update: ['<key> at <v>, flagged <F>, fix <P>'] for every entry still in [F, P).

    Raises BumpError when a version cannot be compared — guessing there could open a PR that does
    not fix the advisory, or call an in-range fix out of range.
    """
    left = []
    for key, version in instances(lock, package):
        vk = vkey(version)
        if vk is None:
            raise BumpError(f"{package} at {key} is version '{version}', which this tool does not order")
        for row in rows:
            if vkey(row["version"]) <= vk < vkey(row["fix"]):
                left.append(f'{key} at {version} (flagged {row["version"]}, fix {row["fix"]})')
    return left


def lock_changes(before, after):
    """['<name> <old> → <new>'] for every lockfile entry the update moved, added or removed."""
    b, a = before.get("packages") or {}, after.get("packages") or {}
    out = []
    for key in sorted(set(b) | set(a)):
        if key == "":
            continue
        old, new = (b.get(key) or {}).get("version"), (a.get(key) or {}).get("version")
        if old != new:
            name = entry_name(key, a.get(key) or b.get(key))
            out.append(f'{name} {old or "(absent)"} → {new or "(removed)"}')
    return out


# ── grouping ────────────────────────────────────────────────────────────────────────────────


def slug(package):
    """npm package name -> the name's part of its branch. Injective: two packages never share a branch.

    `@scope/name` -> `scope__name`. Kept as written: a-z, 0-9, `-`, and a `.` that does not follow
    another (`..` is not allowed in a git ref). Every other byte becomes `_` and two hex digits, so
    `__` can only be the scope mark. `--` would not do: `a--b` is itself a valid npm name.
    """
    scope, _, name = package[1:].partition("/") if package.startswith("@") else ("", "", package)

    def esc(text):
        out = []
        for ch in text:
            keep = re.fullmatch(r"[a-z0-9-]", ch) or (ch == "." and not (out and out[-1] == "."))
            out.append(ch if keep else "".join(f"_{b:02x}" for b in ch.encode("utf-8")))
        return "".join(out)

    return f"{esc(scope)}__{esc(name)}" if scope else esc(name)


def classify(row, lock):
    """One row -> ("candidate", row + fix) or ("manual", {line, reason}).

    A row is a candidate only when it is npm, its patched text is the audit's
    `patched in "<versions>"` form, a fix above the flagged version is named, and the
    channel-server lockfile carries that production package at that version.
    """
    line = finding_line(row)
    if not GHSA_RE.match(row["ghsa"]):
        return "manual", {"line": line, "reason": f'"{row["ghsa"]}" is not a GHSA ID'}
    if row["ecosystem"].lower() != "npm":
        return "manual", {"line": line, "reason": f'{row["ecosystem"]} findings are not automated; only the npm channel-server lockfile is'}
    m = PATCHED_RE.match(row["patched"])
    if not m:
        return "manual", {"line": line, "reason": "the audit did not clear this to one fix (no `patched in \"<ver>\"`)"}
    fix, why = fix_for(row["version"], m.group("text"))
    if fix is None:
        return "manual", {"line": line, "reason": why}
    if not production_instance(lock, row["package"], row["version"]):
        return "manual", {"line": line, "reason": f'{LOCKFILE} has no production entry {row["package"]}@{row["version"]}'}
    return "candidate", dict(row, fix=fix)


def group_candidates(rows, lock):
    """Rows -> ({package: [row + fix]}, [manual findings]). Pure: decides what to TRY, not what fits.

    Each manual finding is {line, reason}. Rows naming one advisory on one package version (the
    same GHSA ID, from either source or from two lockfile copies) are one row: a candidate over a
    manual one, the higher fix between two candidates — a bump that reaches the higher fix clears
    the lower one too, and still_affected holds every row kept to its fix.
    """
    kept = {}
    for row in rows:
        ident = (row["package"].lower(), row["version"], row["ghsa"])
        kind, value = classify(row, lock)
        prev = kept.get(ident)
        if prev is None or (kind == "candidate" and (prev[0] == "manual" or vkey(value["fix"]) > vkey(prev[1]["fix"]))):
            kept[ident] = (kind, value)
    candidates, manual = {}, []
    for kind, value in kept.values():
        if kind == "candidate":
            candidates.setdefault(value["package"], []).append(value)
        else:
            manual.append(value)
    return candidates, manual


# ── CHANGELOG ───────────────────────────────────────────────────────────────────────────────


def edit_changelog(text, bullet, snapshot_to, pkg, old):
    """Insert `bullet` first under `[Unreleased]` `### Fixed`, and name `snapshot_to` in the
    `### Upgrade warnings` client-pack line (adding that line when the section has none)."""
    lines = text.split("\n")
    try:
        start = lines.index("## [Unreleased]")
    except ValueError:
        raise BumpError(f"{CHANGELOG} has no `## [Unreleased]` heading") from None
    end = next((i for i in range(start + 1, len(lines)) if lines[i].startswith("## [")), len(lines))
    section = lines[start + 1:end]

    def find(heading):
        return next((i for i, l in enumerate(section) if l.strip() == heading), None)

    fixed = find("### Fixed")
    if fixed is None:
        at = next((i for i, l in enumerate(section) if l.startswith("### ") and l.strip() in
                   ("### Security", "### Upgrade warnings")), None)
        block = ["### Fixed", "", bullet, ""]
        if at is None:
            while section and section[-1] == "":
                section.pop()
            section += [""] + block
        else:
            section[at:at] = block
    else:
        first = fixed + 1
        while first < len(section) and section[first] == "":
            first += 1
        section.insert(first, bullet)

    warn = find("### Upgrade warnings")
    upgrade = (f"- Run `php artisan bridge:client-pack:install` as the receiver's user to publish client "
               f"snapshot {snapshot_to}. Until you do, seats stay on the client they have, whose lockfile "
               f"pins {pkg} {old}, and `bridge:check` fails its `board_tools.client_pack_source` leg, "
               f"naming that command.")
    if warn is None:
        while section and section[-1] == "":
            section.pop()
        section += ["", "### Upgrade warnings", "", upgrade]
    else:
        stop = next((i for i in range(warn + 1, len(section)) if section[i].startswith("### ")), len(section))
        hit = next((i for i in range(warn + 1, stop) if SNAPSHOT_LINE_RE.search(section[i])), None)
        if hit is None:
            last = stop
            while last > warn + 1 and section[last - 1] == "":
                last -= 1
            section.insert(last, upgrade)
        else:
            section[hit] = SNAPSHOT_LINE_RE.sub(lambda mm: mm.group(1) + snapshot_to, section[hit], count=1)
    if section and section[-1] != "" and end < len(lines):
        section.append("")
    return "\n".join(lines[:start + 1] + section + lines[end:])


# ── texts ───────────────────────────────────────────────────────────────────────────────────


def advisories_phrase(rows):
    """The rows as the CHANGELOG bullet names them: a global advisory (source 1) by its ID, a
    repository advisory (source 2) by its ID and the repository it is published on."""
    global_rows = [r for r in rows if r["source"] == "1"]
    by_repo = {}
    for r in rows:
        if r["source"] == "2":
            by_repo.setdefault(r.get("repo", "?"), []).append(r)

    def each(group):
        return "; ".join(f'{r["ghsa"]} ({r.get("severity", "")}, range `{r.get("range", "")}`, patched in {r["fix"]})'
                         for r in group)

    parts = []
    if global_rows:
        parts.append(f'{"global advisory" if len(global_rows) == 1 else "global advisories"} {each(global_rows)}')
    for repo, group in by_repo.items():
        parts.append(f'{"repository advisory" if len(group) == 1 else "repository advisories"} {each(group)} on {repo}')
    return "; ".join(parts)


def bump_texts(pkg, rows, old, new, parents, chain, moved, snap_from, snap_to, base, run_url):
    via = "direct dependency" if chain is None or len(chain) <= 1 else "transitive via " + " → ".join(chain[:-1])
    others = [m for m in moved if not m.lower().startswith(pkg.lower() + " ")]
    also = f'; it also moved {", ".join(others)}, which the new version\'s ranges require' if others else ""
    bullet = (f"- **npm/{pkg} {old} → {new} in examples/channel-servers** ({via}; {advisories_phrase(rows)}). "
              f"Opened by the daily dependency advisory ref matrix: {new} is in range for "
              f'{"; ".join(parents) or "every declaring range"}, so `npm update {pkg}` sufficed and no override '
              f"was added{also}. Channel-server snapshot {snap_from} → {snap_to}.")
    ghsas = sorted({r["ghsa"] for r in rows})
    title = f"deps(channel-servers): bump {pkg} to {new} ({ghsas[0]}{'' if len(ghsas) == 1 else f' +{len(ghsas) - 1}'})"
    findings = "\n".join(f"  - `{finding_line(r)}`" for r in rows)
    moved_line = (f"  - The update also moved: {', '.join(others)}. No other lockfile entry changed."
                  if others else "  - No other lockfile entry changed.")
    run = f" (run {run_url})" if run_url else ""
    body = f"""## Summary

Bumps {pkg} {old} -> {new} in the channel-servers lockfile. Opened automatically by the bump job of the daily "Dependency advisory ref matrix" (`.github/workflows/dependency-audit.yml`, `bin/advisory-bump.py`){run}, for the findings its audit reports on `{base}`:

{findings}

- `{LOCKFILE}`: {pkg} **{old} -> {new}**, done with `npm update {pkg} --package-lock-only --ignore-scripts`. The declaring range admits it, so no override was needed: {"; ".join(parents) or "(no declaring entry found)"}. Dependency path: {via}.
{moved_line}
- Reference channel-server snapshot **{snap_from} -> {snap_to}** (`package.json` + both lockfile version sites, `npm version patch`), for the version-bump guard (DL-038). `{CAPABILITIES}` regenerated with `node bin/gen-client-capabilities.mjs`.
- `{CHANGELOG}` `[Unreleased]`: a `### Fixed` entry, and the `### Upgrade warnings` client-pack line names snapshot {snap_to}.

Reachability of the advisory in this tree was not analysed by the automation. The bump is in range, so it is made either way.

## Scope

- **Closes / references:** {", ".join(ghsas)}
- **Migration impact:** n/a
- **Operator action required after merge:** yes — at the next release, run `php artisan bridge:client-pack:install` to publish client snapshot {snap_to} (see `{CHANGELOG}` § Upgrade warnings).

## Test plan

- Checked by the job before opening this PR: after the update no lockfile entry of {pkg} is at or above a flagged version and below that finding's fix; only `package-lock.json` changed under `npm update`; the snapshot bump moved `package.json`, the lockfile's `.version` and `.packages[""].version` together.
- Not run by the job: `npm ci`, `node --test tests/`, `npm audit`, PHPUnit, or a re-run of the Audit step. This PR's own CI runs them (it was opened with `{TOKEN_ENV}`, not `GITHUB_TOKEN`, so its workflows fire).
"""
    return title, body, bullet


# ── git / npm / gh seams ────────────────────────────────────────────────────────────────────


class Shell:
    """Every subprocess goes through here, so the tests replace it."""

    def run(self, cmd, cwd=None, env=None, check=True):
        proc = subprocess.run(cmd, cwd=cwd, env=env, text=True, capture_output=True)
        if check and proc.returncode != 0:
            raise BumpError(f"`{' '.join(cmd)}` exited {proc.returncode}: {(proc.stderr or proc.stdout).strip()[:400]}")
        return proc


def read_json(path):
    return json.loads(Path(path).read_text(encoding="utf-8"))


def build_bump(sh, repo, workroot, base_sha, base, pkg, rows, run_url):
    """One package -> {'bump': {...}} or {'manual': {...}}. Leaves no worktree behind."""
    wt = Path(workroot) / f"bump-{slug(pkg)}"
    sh.run(["git", "-C", repo, "worktree", "add", "--detach", str(wt), base_sha])
    try:
        before = read_json(wt / LOCKFILE)
        sh.run(["npm", "update", pkg, "--package-lock-only", "--ignore-scripts", "--no-audit", "--no-fund"],
               cwd=str(wt / CHANNEL_DIR))
        changed = [l[3:] for l in sh.run(["git", "-C", str(wt), "status", "--porcelain"]).stdout.splitlines() if l.strip()]
        if changed and changed != [LOCKFILE]:
            raise BumpError(f"`npm update {pkg}` changed {', '.join(changed)}, not only {LOCKFILE}")
        after = read_json(wt / LOCKFILE)
        left = still_affected(after, pkg, rows)
        parents = declaring_parents(after, pkg)
        if left:
            return {"manual": {"package": pkg, "lines": [finding_line(r) for r in rows],
                               "reason": (f"out of range: `npm update {pkg}` leaves {'; '.join(left)}. "
                                          f"Declaring ranges: {'; '.join(parents) or 'none found'}. "
                                          "The fix needs a parent bump or an override — a human decision.")}}
        old = ", ".join(sorted({r["version"] for r in rows}, key=vkey))
        moved_to = {v for _, v in instances(after, pkg)} - {v for _, v in instances(before, pkg)}
        if not moved_to:
            # Every flagged copy now resolves to a version the lockfile already carried.
            moved_to = {v for _, v in instances(after, pkg) if any(vkey(v) >= vkey(r["fix"]) for r in rows)}
        new = ", ".join(sorted(moved_to, key=vkey))
        moved = lock_changes(before, after)
        snap_from = read_json(wt / MANIFEST)["version"]
        sh.run(["npm", "version", "patch", "--no-git-tag-version", "--ignore-scripts"], cwd=str(wt / CHANNEL_DIR))
        snap_to = read_json(wt / MANIFEST)["version"]
        lock = read_json(wt / LOCKFILE)
        if lock.get("version") != snap_to or lock.get("packages", {}).get("", {}).get("version") != snap_to:
            raise BumpError(f"`npm version patch` left the lockfile's version sites off package.json's {snap_to}")
        sh.run(["node", "bin/gen-client-capabilities.mjs"], cwd=str(wt))
        title, body, bullet = bump_texts(pkg, rows, old, new, parents, dependency_path(after, pkg), moved,
                                         snap_from, snap_to, base, run_url)
        cl = wt / CHANGELOG
        cl.write_text(edit_changelog(cl.read_text(encoding="utf-8"), bullet, snap_to, pkg, old), encoding="utf-8")
        branch = f"{BRANCH_PREFIX}{slug(pkg)}-{new.split(', ')[-1]}"
        sh.run(["git", "-C", str(wt), "switch", "-c", branch])
        sh.run(["git", "-C", str(wt), "add", "--", LOCKFILE, MANIFEST, CAPABILITIES, CHANGELOG])
        sh.run(["git", "-C", str(wt), "-c", f"user.name={COMMIT_NAME}", "-c", f"user.email={COMMIT_EMAIL}",
                "commit", "-q", "-m", title])
        head = sh.run(["git", "-C", str(wt), "rev-parse", "HEAD"]).stdout.strip()
        return {"bump": {"package": pkg, "branch": branch, "head": head, "title": title, "body": body,
                         "old": old, "new": new, "ghsas": sorted({r["ghsa"] for r in rows})}}
    finally:
        sh.run(["git", "-C", repo, "worktree", "remove", "--force", str(wt)], check=False)


def plan(sh, repo, rows, base, run_url, workroot):
    base_sha = sh.run(["git", "-C", repo, "rev-parse", "--verify", f"{base}^{{commit}}"]).stdout.strip()
    lock = json.loads(sh.run(["git", "-C", repo, "show", f"{base_sha}:{LOCKFILE}"]).stdout)
    candidates, manual = group_candidates(rows, lock)
    bumps, errors = [], []
    for pkg in sorted(candidates, key=str.lower):
        try:
            result = build_bump(sh, repo, workroot, base_sha, base, pkg, candidates[pkg], run_url)
        except BumpError as exc:
            errors.append(f"{pkg}: {exc}")
            continue
        if "bump" in result:
            bumps.append(result["bump"])
        else:
            m = result["manual"]
            manual.extend({"line": line, "reason": m["reason"]} for line in m["lines"])
    return {"base": base, "base_sha": base_sha, "bumps": bumps, "manual": manual, "errors": errors}


def plan_summary(p):
    out = [f"## Advisory bump plan — base `{p['base']}` ({p['base_sha'][:12]})", ""]
    out.append(f"Bumps built: {len(p['bumps'])}" if p["bumps"] else "Bumps built: none")
    out += [f"- `{b['branch']}`: {b['title']}" for b in p["bumps"]]
    if p["manual"]:
        out += ["", "Left for a human (no PR opened):"]
        out += [f"- `{m['line']}` — {m['reason']}" for m in p["manual"]]
    if p["errors"]:
        out += ["", "ERRORS (the job fails):"] + [f"- {e}" for e in p["errors"]]
    return "\n".join(out) + "\n"


# ── open ────────────────────────────────────────────────────────────────────────────────────


def gh_env(token):
    env = {k: v for k, v in os.environ.items() if k not in ("GITHUB_TOKEN", "GH_TOKEN", "GH_ENTERPRISE_TOKEN")}
    env["GH_TOKEN"] = token
    env["GH_PROMPT_DISABLED"] = "1"
    env["GIT_TERMINAL_PROMPT"] = "0"
    return env


def prior_decision(bump, same_branch_prs, open_prs):
    """None when the bump should be opened, else the reason it is skipped. Pure.

    `same_branch_prs`: every PR (any state) whose head is this bump's branch.
    `open_prs`: every open PR into the base, as {number, head, url}.
    """
    for pr in same_branch_prs:
        if pr.get("state") == "open":
            return f"already open as #{pr['number']} ({pr.get('url', '')})"
        if pr.get("merged_at"):
            return f"this exact bump was merged as #{pr['number']}, yet the base still carries the finding — look at it by hand"
        return f"this exact bump was closed without merging as #{pr['number']}; not reopening it"
    prefix = f"{BRANCH_PREFIX}{slug(bump['package'])}-"
    for pr in open_prs:
        head = pr.get("head", "")
        # The bare-version remainder is what keeps `express-rate-limit`'s branch out of `express`'s.
        # It rests on branch versions being bare dotted numbers, which still_affected's refusal of an
        # unorderable version guarantees; a prerelease version would slip past this check.
        if head.startswith(prefix) and VERSION_RE.fullmatch(head[len(prefix):]):
            return (f"another bump PR for {bump['package']} is open: #{pr['number']} ({pr['head']}) — one open bump "
                    "PR per package; close or update it")
    return None


def open_bumps(sh, repo, p, token, gh_repo):
    """-> (lines for the summary, error count)."""
    lines, errors = [], 0
    if not p["bumps"]:
        return ["No bump to open."], 0
    if not token:
        lines.append(f"FAILED: the repository secret `{TOKEN_ENV}` is not set, so these bumps were NOT opened "
                     "(a PR opened with GITHUB_TOKEN would run no CI, so there is no fallback to it; whether a PR "
                     "for each is already open was not read either, since that read needs the token too):")
        lines += [f"- would have opened `{b['branch']}` -> `{p['base']}`: {b['title']}" for b in p["bumps"]]
        return lines, len(p["bumps"])
    env = gh_env(token)
    owner = gh_repo.split("/")[0]
    open_prs = json.loads(sh.run(["gh", "api", "--paginate", "--slurp",
                                  f"repos/{gh_repo}/pulls?state=open&base={p['base']}&per_page=100"], env=env).stdout)
    open_prs = [{"number": pr["number"], "head": pr["head"]["ref"], "url": pr["html_url"]}
                for page in open_prs for pr in page]
    helper = ["-c", "credential.helper=", "-c", "credential.helper=!gh auth git-credential"]
    for b in p["bumps"]:
        try:
            same = json.loads(sh.run(["gh", "api", f"repos/{gh_repo}/pulls?state=all&head={owner}:{b['branch']}"],
                                     env=env).stdout)
            skip = prior_decision(b, same, open_prs)
            if skip:
                lines.append(f"- skipped {b['package']} `{b['branch']}`: {skip}")
                continue
            remote = sh.run(["git", "-C", repo, *helper, "ls-remote", "--heads", "origin", b["branch"]], env=env).stdout
            if remote.strip():
                raise BumpError(f"branch {b['branch']} already exists on origin with no PR; not overwriting it — "
                                "delete it or open its PR by hand")
            sh.run(["git", "-C", repo, *helper, "push", "origin", f"{b['head']}:refs/heads/{b['branch']}"], env=env)
            with tempfile.NamedTemporaryFile("w", suffix=".md", delete=False, encoding="utf-8") as fh:
                fh.write(b["body"])
                body_file = fh.name
            try:
                url = sh.run(["gh", "pr", "create", "--repo", gh_repo, "--base", p["base"], "--head", b["branch"],
                              "--title", b["title"], "--body-file", body_file], env=env).stdout.strip()
            finally:
                os.unlink(body_file)
            lines.append(f"- opened {b['package']}: {url}")
        except BumpError as exc:
            errors += 1
            lines.append(f"- FAILED {b['package']} `{b['branch']}`: {exc}")
    return lines, errors


# ── main ────────────────────────────────────────────────────────────────────────────────────


def write_summary(path, text):
    sys.stdout.write(text)
    if path:
        with open(path, "a", encoding="utf-8") as fh:
            fh.write(text)


def main(argv=None, sh=None):
    sh = sh or Shell()
    ap = argparse.ArgumentParser(description=__doc__.split("\n", 1)[0])
    sub = ap.add_subparsers(dest="cmd", required=True)
    p1 = sub.add_parser("plan")
    p1.add_argument("--findings-env", required=True, help="env var holding the audit's affected JSON")
    p1.add_argument("--base", required=True, help="the branch the bump PRs target")
    p1.add_argument("--plan-out", required=True)
    p1.add_argument("--repo", default=".")
    p1.add_argument("--workdir", default=None, help="where per-package worktrees go (default: a temp dir)")
    p2 = sub.add_parser("open")
    p2.add_argument("--plan", required=True)
    p2.add_argument("--repo", default=".")
    args = ap.parse_args(argv)
    summary = os.environ.get("GITHUB_STEP_SUMMARY")

    if args.cmd == "plan":
        try:
            rows = parse_findings(os.environ.get(args.findings_env))
        except BumpError as exc:
            write_summary(summary, f"## Advisory bump plan\n\nFAILED: {exc}\n")
            print(f"::error title=advisory bump::{exc}")
            return 1
        server, repo_slug, run_id = (os.environ.get(k, "") for k in ("GITHUB_SERVER_URL", "GITHUB_REPOSITORY", "GITHUB_RUN_ID"))
        run_url = f"{server}/{repo_slug}/actions/runs/{run_id}" if server and repo_slug and run_id else ""
        with tempfile.TemporaryDirectory(dir=args.workdir) as workroot:
            try:
                result = plan(sh, args.repo, rows, args.base, run_url, workroot)
            except BumpError as exc:
                write_summary(summary, f"## Advisory bump plan\n\nFAILED: {exc}\n")
                print(f"::error title=advisory bump::{exc}")
                return 1
        Path(args.plan_out).write_text(json.dumps(result, indent=2) + "\n", encoding="utf-8")
        write_summary(summary, plan_summary(result))
        for e in result["errors"]:
            print(f"::error title=advisory bump::{e}")
        return 1 if result["errors"] else 0

    if not Path(args.plan).is_file():
        print(f"::error title=advisory bump::no plan at {args.plan} — the plan step stopped before deciding; nothing opened")
        return 1
    p = read_json(args.plan)
    gh_repo = os.environ.get("GITHUB_REPOSITORY", "")
    if p["bumps"] and not gh_repo:
        print("::error title=advisory bump::GITHUB_REPOSITORY is not set; cannot name the repository to open PRs on")
        return 1
    try:
        lines, errors = open_bumps(sh, args.repo, p, os.environ.get(TOKEN_ENV, ""), gh_repo)
    except BumpError as exc:
        lines, errors = [f"FAILED before any bump was tried: {exc}"], 1
    write_summary(summary, "## Advisory bump PRs\n\n" + "\n".join(lines) + "\n")
    if errors:
        first = lines[0] if (errors and len(lines) == 1) or not os.environ.get(TOKEN_ENV) else f"{errors} bump(s) failed to open"
        print(f"::error title=advisory bump::{first}")
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
