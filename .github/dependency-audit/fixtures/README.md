# fixtures/ — vendored inputs for `dependency-audit.selftest.sh`

**Every control leg of that selftest runs with ZERO network.** These files are the reason: they
are frozen copies of the inputs the audit reads at run time, so a leg's verdict cannot change
because a remote endpoint had a bad minute. (A check whose verdict changes without its subject
changing is not a check — and the habit an intermittently-red selftest teaches is `re-run`.)

| File | What it is | Provenance |
|---|---|---|
| `advisories/thephpleague@commonmark.json` | The repository-level advisories of a real dependency — the known-positive this whole gate exists for. | Fetched **2026-08-20** with `gh api repos/thephpleague/commonmark/security-advisories --paginate` and **field-projected** to the keys the audit reads: `jq '[.[] \| {ghsa_id, severity, state, withdrawn_at, html_url, summary, vulnerabilities: [.vulnerabilities[] \| {package: {ecosystem: .package.ecosystem, name: .package.name}, vulnerable_version_range, patched_versions}]}]'`. 16 advisories, ranges unmodified. |
| `advisories/axios@axios.json` | The repository-level advisories of axios, the population card#11106 was measured on: open ranges whose fix is stated only in `patched_versions`, fixes on two release lines, and bare-version ranges, which the grammar does not read: a version equal to the bare version or at its one fix is decided by what every reading agrees on (card#11604), and the reviewed records in `reviewed/` decide the rest. The selftest asserts axios 1.20.0 is GREEN against it with no reviewed record looked up, and 1.19.0 is RED on findings. | Fetched **2026-10-06** with `gh api repos/axios/axios/security-advisories --paginate` and field-projected with the same `jq` as the commonmark file. Ranges and patched versions unmodified. |
| `advisories/laravel@framework.json` | The repository-level advisories of laravel/framework, whose free-text ranges (`<6.20.45,>=7,<7.30.7,...`) the range grammar does not read, so the reviewed global records below decide them (card#11106). The selftest asserts v13.30.1 is GREEN against it and v11.30.0 is RED. | Fetched **2026-10-06** with `gh api repos/laravel/framework/security-advisories --paginate` and field-projected with the same `jq` as the commonmark file. Ranges and patched versions unmodified. |
| `advisories/follow-redirects@follow-redirects.json`, `advisories/expressjs@express.json`, `advisories/honojs@node-server.json` | The repository-level advisories card#11604 was measured on: bare-version ranges (`1.16.0`), a pair of bounds whose pre-release tags are not ordered (`>=5.0.0-alpha.1, <5.0.0-beta.3`) and a spaced operator (`> = 1.19.10, < 2.1.3`), none of which the grammar reads, and whose global records answered 404 or decide nothing. The selftest asserts follow-redirects 1.16.1, express 5.2.1 and @hono/node-server 2.1.3 are GREEN against them with every global lookup failing, and follow-redirects 1.15.12 is RED. | Fetched **2026-10-09** with `gh api repos/<owner>/<repo>/security-advisories --paginate` and field-projected with the same `jq` as the commonmark file. Ranges and patched versions unmodified. |
| `reviewed/<GHSA>.json` | The REVIEWED global record of each advisory the selftest needs one for, one file per GHSA ID: each entry of the laravel/framework file above whose package field is exactly `laravel/framework` and whose range the grammar does not read (entries naming `laravel/framework, illuminate/database` match no package, card#11368), and the advisories behind the real rows of the selftest's §G table. | Fetched **2026-10-06** with `gh api /advisories/<GHSA>` and field-projected: `jq '{ghsa_id, type, withdrawn_at, html_url, vulnerabilities: [.vulnerabilities[] \| {package: {ecosystem: .package.ecosystem, name: .package.name}, vulnerable_version_range, first_patched_version}]}'`. Ranges unmodified. |
| `advisories/fixture-org@control-repo.json` | SYNTHETIC. Stands in for source 2's control repo so the "control read succeeded, a *dependency* read failed" leg is distinguishable from "the control itself failed". | Hand-written; it names no real package. |
| `composer.lock.affected` | A structurally faithful composer lockfile pinning `league/commonmark` **2.9.0**. | Hand-written around the real package coordinates. |
| `composer.lock.clean` | The same lockfile at **2.10.0**. | Hand-written. |
| `composer.lock.empty` | A lockfile that PARSES and declares zero production packages — the state that must not read as "this repo has no dependencies". | Hand-written. |
| `composer.lock.corrupt` | Not JSON. Proves a lockfile that cannot be read reds instead of resolving to an empty population. | Hand-written. |
| `package-lock.json.npm` | An npm lockfile with two production packages and one dev-only package. | Hand-written. |

## Why the pair is a PAIR

`2.9.0` and `2.10.0` differ by one dependency version and nothing else, and the frozen advisory
set says the first is affected by four HIGH advisories (one of them an XSS) while the second is
affected by none. So the selftest asserts the check **DISTINGUISHES**, not merely that it
refuses: a check that reds on everything passes a "must RED" leg for the wrong reason, and the
"must GREEN" leg is what catches it.

Re-running the matcher over these files reproduces the fleet's own independently measured
numbers — 2.9.0 → 4 HIGH, 2.9.1 → 1 (the DoS only), 2.10.0 → 0.

**Refreshing the advisory freeze is a deliberate act, not maintenance.** If you re-fetch, expect
the counts to move (upstream publishes more advisories over time) and update the selftest's
expected GHSA ids in the same change — the ids are asserted by name so a silent corpus swap
cannot quietly empty the must-RED leg.
