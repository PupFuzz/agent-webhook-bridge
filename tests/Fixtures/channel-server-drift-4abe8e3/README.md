# `examples/channel-servers/` as it stood at `4abe8e3`

The last commit at which card#5232's defect was live on `dev`: `package.json` version
`0.8.3` against a `package-lock.json` whose root version was still `0.7.1`. It is the
positive control for the lock↔manifest agreement assertion (DL-268) — the guard is
required to reject a state the repo actually shipped, not one invented to fit it.

Regenerate (byte-identical) with:

    git show 4abe8e3:examples/channel-servers/package.json      > package.json.fixture
    git show 4abe8e3:examples/channel-servers/package-lock.json > package-lock.json.fixture

**The `.fixture` suffix is load-bearing, not cosmetic.** Dependabot *security* updates
detect manifests by filename anywhere in the repo, independently of `.github/dependabot.yml`
(which configures only the github-actions and composer ecosystems here — the npm PRs against
`examples/channel-servers/` arrive through that separate path). A file named
`package-lock.json` under `tests/` would therefore be eligible for an automated bump, and
bumping this one would quietly repair the very drift it exists to reproduce, leaving the
control green over a guard that had stopped being tested. The test writes these bytes into a
throwaway tree under the real filenames, so the guard still reads what it reads in CI.

These files are **history, not a dependency tree to maintain**: nothing installs from them,
and they must never be "updated".
