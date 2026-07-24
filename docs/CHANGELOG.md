# Changelog

All notable changes to the agent-webhook-bridge are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The changelog is **release-event only** — entries land in the release-tag commit, not in feature PRs. See [`../VERSIONING.md`](../VERSIONING.md) for the full policy.

> This repository's git history begins at **v0.12.0**. The bridge existed earlier (v0.1–v0.11, a Python-consumer + PHP-receiver implementation), but that history was not carried into this repository. The design rationale that is still load-bearing for the current code is preserved in [`../CLAUDE_DECISIONS.md`](../CLAUDE_DECISIONS.md); v0.12.0 itself is recorded in **DL-001**.

## [Unreleased]

## [0.68.1] - 2026-07-23

**Patch — `bridge:provision-tools`'s generated ssh setup-script pubkey guard now survives a naive `sed` injection (DL-226 follow-on).** 1 PR since v0.68.0 (#375). **No migration, no new required `.env`, no receiver accept/reject change, no token-scope change; the change is confined to the ssh-path generated-script template.**

### Fixed
- **#375 (card#5009, roundtable #141)** — **the generated root-run ssh setup script's `HOST_B_PUBKEY` guard now survives a naive `sed` injection.** The DL-226 script carried the paste placeholder (`PASTE HOST-B PUBLIC KEY HERE`) on **both** the value-assignment line and an equality guard; a first-match-per-line `sed 's|PASTE...|<key>|'` rewrote both, so the guard compared the injected key against itself and always passed — the script died with `ERROR: set HOST_B_PUBKEY` even after a correct paste (cost aimla's operator a cycle during the first live same-box ssh enablement). The placeholder now appears exactly once (value line only); the guard is replaced with a positive key-shape test (`case … in ecdsa-*|ssh-*|sk-*`) that greens only once a real key is in place, regardless of how the value line was edited — covers FIPS ECDSA P-256, ed25519/rsa, and `sk-*` security keys. Considered-and-rejected: reading the pubkey from a keyfile at generation time (`provision-tools` is topology-agnostic — host B may be a different machine, so the path isn't config-derivable without baking in a same-box assumption; tracked separately as the one-command end-to-end enablement, FR #5010). Regression test extracts the real generated guard string and executes it under bash, red-when-reverted (exit 1 on the untouched placeholder / exit 0 after a naive sed injection). 1342/1342 phpunit, phpstan L7 0, pint clean.

## [0.68.0] - 2026-07-23

**Minor — SSH becomes the DEFAULT board-tools transport (DL-225) + a guided, idempotent ssh-setup script in `bridge:provision-tools` (DL-226).** The two-front-door work (DL-223/DL-224) shipped ssh as an opt-in; the fleet direction (roundtable #133/#138) is ssh-first, so an unset `board_tools.transport` now reads as `ssh` instead of `http`. Plus `bridge:provision-tools` graduates from print-only scaffolding to GENERATING a root-run setup script whose output PASSES the `bridge:check` sshd backstop.

**UPGRADING (DL-225) — read before upgrading:** the pre-0.68.0 default for `board_tools.transport` was **`http`**; it is now **`ssh`**. **Any `board_tools` block relying on the implicit `http` default must set `transport: http` explicitly BEFORE upgrading to keep the loopback path** — post-upgrade an unset `transport:` is read as `ssh`, so the bearer stops resolving over the HTTP loopback door and `POST /agent-tools/call` fails closed (401). The runtime break is loud/fail-closed (no silent permissive change), and `bridge:check` emits a **pre-upgrade advisory** for any agent that landed on ssh via the flipped default with no completed ssh setup (naming the `transport: http` pin as the fix). Explicit `transport: http` or `transport: ssh` blocks are unaffected. The caller-facing 401 is deliberately indistinguishable from an unknown-token 401 (naming a re-class would leak that a presented bearer is valid).

### Changed
- **DL-225 — SSH is the default board-tools transport.** `BoardToolsConfig` now defaults `transport` to `ssh` at both the parse site (absent `transport:` key) and the constructor default; a new readonly `transportExplicit` flag records whether the key was present. `bridge:check`'s ssh leg gains a pre-upgrade **advisory** (warn, never a hard fail): an agent on ssh by the flipped default (`transportExplicit === false`) whose ssh setup is incomplete (the pinned-line / sshd-posture probe already run in that leg reports any non-ok finding) is flagged to pin `transport: http` or complete ssh setup. The caller-facing 401 in `AgentToolsController` is unchanged (security property: it must not distinguish unknown-token from re-classed-to-ssh). Runtime fail-closed path is unchanged — an implicit-http block now reads as ssh, so `BoardToolAgentResolver`'s `transport !== 'http'` exclusion already drops it from the HTTP index.
- **DL-226 — guided ssh setup script in `bridge:provision-tools`.** The ssh path graduates from print-only scaffolding (which omitted the three DL-224 backstop directives, so following it then running `sudo bridge:check` FAILED the backstop leg) to GENERATING a root-run setup script (still operator-run — "guided" = generate + verify, not silent automation; root surfaces stay operator-run). The script wires idempotently (append-or-verify, never clobbers existing config): (a) the forced-command `authorized_keys` line (enumerated `no-pty,no-agent-forwarding,no-X11-forwarding,no-port-forwarding` FIPS-safe flags, artisan + agent substituted, a clear PASTE placeholder for host B's public key), (b) an sshd `Match User <account>` drop-in with `PasswordAuthentication no` AND `ClientAliveInterval 300` / `ClientAliveCountMax 2` / `MaxSessions 10` (all `>0` — matching what `probeSshdPosture` hard-asserts, so the generated config PASSES `bridge:check`), (c) a validate-then-reload (`sshd -t` before reload), and it documents (d) the FIPS ECDSA P-256 keygen to run first on host B (`ssh-keygen -t ecdsa -b 256`, never ed25519). The operator runs `sudo bash <script>`, then certifies with `bridge:check --probe-tools-ssh=<user@host>` (a real `board_my_cards` round-trip). Docs: `docs/config-schema.md` transport row + `docs/board-tools.md` updated for the flipped default and the guided script.

## [0.67.0] - 2026-07-23

**Minor — the board-tools SSH-forced-command transport (DL-223): a no-root, firewall-uniform alternative to the loopback HTTP endpoint, plus the DL-224 follow-on that certifies the forced-command account and verifies the sshd idle/concurrency backstop, and a release-CI promote-guard pin.** 3 PRs since v0.66.0 (#368, #369, #367). **No migration, no new required `.env`, no receiver accept/reject change, no token-scope change. The SSH transport is opt-in (`board_tools.transport: ssh`, default `http`) and fail-closed end to end ⇒ absent it, behavior is byte-identical — see UPGRADING.**

### Added
- **#368 (DL-223, card#4952, roundtable #133)** — **board-tools SSH-forced-command transport: a no-root, firewall-uniform alternative to the loopback HTTP endpoint.** New per-agent **`board_tools.transport: http|ssh`** key (default `http` — absent-config byte-identical); `ssh` lifts the bearer requirement — identity comes from the pinned key's forced command, not a token (a contradictory `auth` block under `ssh` fails closed). **`bridge:tools-call --agent=X`** is the SSH front door: identity is un-spoofable (the pinned `command=` line; `SSH_ORIGINAL_COMMAND` is never read), stdout is strictly the result body (all diagnostics to stderr/log), exit codes `0` ok · `1` caller-fixable input · `2` bridge-side misconfiguration. A shared **`BoardToolDispatcher` + `DispatchOutcome`** core is extracted from the HTTP controller so both front doors serialize one body shape (HTTP maps `status`, ssh maps `exitCode()`). **`bridge:check`** gains an offline `authorized_keys` capability-model verification (`restrict`-based, last-writer-wins option parsing, quoted-field tokenizer, port-forwarding fail-closed) plus a `--probe-tools-ssh` live round-trip (non-root ⇒ UNVERIFIED warn, never a false OK); `bridge:provision-tools` scaffolds ssh agents by print (incl. FIPS keygen guidance — ed25519 rejected on a FIPS host ⇒ ecdsa). A `.mjs` channel-server ssh-spawn leg shares one relay helper with the HTTP leg (`ConnectTimeout=10` + a 60s overall deadline). Design ran 4 fresh-adversarial rounds to zero must-fix. **One shipped-surface error-reporting change (hard-gate class, standing dev authorization):** via the shared relay helper the existing HTTP leg now returns `isError: true` for a corrupt/unparseable bridge response body (was `isError: false` with raw text). Docs: `docs/multi-host.md` SSH section, `docs/board-tools.md` two-front-door model, `docs/config-schema.md` transport row, `CLAUDE_ARCHITECTURE.md`, `CLAUDE_DECISIONS.md` DL-223. Live-fire debt (`.mjs` leg, `--probe-tools-ssh`, `sshd -T` root path, FIPS `restrict`, stdin `timed_out` on a real ssh pipe) is recorded in DL-223 and lands with aimla's + sola's enablement.
- **#369 (DL-224, card#4977, roundtable #133)** — **`bridge:check` now asserts the sshd idle/concurrency backstop** DL-223 only recommended in an operator note. With the forced command as the sole entry point, sshd's `ClientAliveInterval`/`ClientAliveCountMax`/`MaxSessions` are the only real bound on a key-holder that holds stdin open (the in-process stdin deadline is best-effort + unverified on the pipe). The posture leg reads the SAME Match-resolved `sshd -T -C user=<forced-command-account>` output and **fails** if `ClientAliveInterval` is non-positive/unset (no idle timeout), if `ClientAliveCountMax` is non-positive/unset (`0` disables the client-alive disconnect; the `interval × countmax` idle window is otherwise unbounded), or if `MaxSessions` is non-positive/unset — naming the exact directive + a `Match User <account>` remedy; only an all-positive config reports the computed idle bound. Docs: `docs/multi-host.md § 3` gains the `ssh_account` sudo callout + the now-required backstop directives (the old "unverified" operator note corrected), `docs/config-schema.md` + `docs/board-tools.md` updated.

### Changed
- **#367** — **PupFuzz/agent-board-toolkit/promote 0.20.0 → 0.21.0** (release CI, `release-promote-cards.yml`; SHA-pinned `ddb7330158f6b98b0b62c3e32ab38bbc53f2b405`), opting in to the new optional `shipped-stage-ids` input with board 8's Shipped-to-Dev stage id (`52`). At release-promote time a DL/PR-matched card whose CURRENT stage is not Shipped-to-Dev is now skipped (with a log line) instead of moved — a stale or recycled DL/PR stamp can no longer resurrect a declined/backlog card. Blank input = prior behavior, so the guard is strictly opt-in; the pin bump alone is behavior-identical. **Runs only on the merge-to-`main` release event ⇒ takes effect only on `main`; this release activates it.**

### Fixed
- **#369 (DL-224, card#4977)** — **the SSH-transport `bridge:check` probe now certifies the FORCED-COMMAND account, not the invoking one.** DL-223's probe resolved the account running `bridge:check` (`posix_geteuid`) at two sites where it means the forced-command account, so under the documented `sudo bridge:check` (euid=0) it certified **root's** `sshd -T -C user=root` posture and read **`/root/.ssh/authorized_keys`** — false-negativing every seat whose forced command runs as a non-root account (the common case). New optional **`board_tools.ssh_account`** (string; SSH transport only, absent ⇒ the invoking run-user, byte-identical to pre-4977) names the account; the probe then resolves `sshd -T -C user=<account>`, expands `%h`/`%u` and the default `authorized_keys` path against that account's home (`posix_getpwnam`), and the posture leg is deduped per resolved account. Parsed into `BoardToolsConfig::$sshAccount` (new `optionalString` helper; present-but-empty/non-string fails closed at load, inheriting the DL-222 throw/suppress classification).
- **#369 (DL-224 follow-on, card#4977)** — two security-correctness gaps in the DL-224 backstop closed: (1) the idle/concurrency backstop guarded `ClientAliveCountMax`/`MaxSessions` on `=== null` only, but `sshd -T` always emits both (defaults 3/10) so the `null` branch was dead — a `ClientAliveCountMax 0` (which DISABLES sshd's client-alive idle disconnect) computed a `0s` idle bound and was certified **ok**; both are now guarded `=== null || <= 0` like the interval sibling, distinguishing unset from non-positive. (2) A **configured** `board_tools.ssh_account` that does not resolve to an OS account (`posix_getpwnam` ⇒ `''` home) flowed unchecked into the `authorized_keys` path, certifying against a phantom `/.ssh/authorized_keys`; the account-dependent legs (`probeSshdPosture`, `probePinnedLine`) now **fail** honestly via a single `configuredAccountUnresolved()` guard, gated strictly on a non-null `ssh_account` so the unset fallback (`runUserHome` may also be `''`) keeps its pre-4977 non-authoritative warn. RED-when-reverted tests for `clientalivecountmax 0`, `maxsessions 0`, and the unresolvable-configured-account case; the unset-empty-home fallback pinned green.

**UPGRADING (DL-223):** The SSH transport is entirely opt-in — absent `board_tools.transport: ssh` (default `http`) and absent a configured `board_tools.ssh_account`, this release is **byte-identical** to v0.66.0 for existing installs. Adopting `transport: ssh` requires a pinned forced-command key; under the documented `sudo bridge:check`, set `board_tools.ssh_account` to the forced-command OS account (unset ⇒ the invoking run-user, byte-identical to pre-DL-224), and sshd must set positive `ClientAliveInterval`/`ClientAliveCountMax`/`MaxSessions` or `bridge:check` FAILs the posture leg. One shipped-surface change applies regardless of transport: a corrupt/unparseable bridge response body now surfaces to the channel client as `isError: true` (was raw text with `isError: false`).

## [0.66.0] - 2026-07-23

**Minor — board-tools default-ON (DL-222): the channel token becomes the default bearer and a complete `board_tools` block turns on without an `enabled` key, with structural default-suppression instead of load throws. Plus the DL-move correlation-stamp fix (DL-221), two runbook additions from aimla's live enablement, and a solo-orientation doc sync.** 4 PRs since v0.65.0 (#362, #360, #361, #363). **No migration, no new required `.env`, no receiver accept/reject change. ⚠ One config shape flips behavior and the board-tools bearer's authority widens — see UPGRADING.**

### Added
- **#362 (DL-222, card#4907, roundtable #102)** — **board-tools default-ON.** The operator-ratified successor to DL-217's opt-in shape (supersedes-pointers into DL-217/DL-220): (1) **Bearer = the channel token.** `board_tools.auth.token_path` becomes a deprecation alias — honored first, so a pinned `BRIDGE_TOOLS_TOKEN_FILE` / `.mcp.json` never silently flips source — and the default path needs no separate credential and no mint. DL-222 names the blast-radius widening explicitly: the push-auth channel token gains own-lane board authority (own-lane reads + untriaged own-lane creates), bounded by the DL-217 isolation set (own-lane / forced scope / untriaged / loopback-gated), all preserved. (2) **Structural default-suppression — classification precedes parsing.** Throws exist only behind a strict-bool `enabled: true`; every present default-class block that cannot be satisfied (bare `board_tools:`, non-bool `enabled` incl. YAML `yes`, partial scope, no bearer, malformed optional) **suppresses** — `enabled=false` + a `suppressedReason` that `bridge:check` renders as **FAIL** — never a load throw, because a config throw would propagate through `SubscriptionRegistry`→`DispatchService` into an install-wide webhook 5xx storm (6-path fleet-outage regression pinned red-when-reverted). (3) **Channel side:** `BRIDGE_CHANNEL_TOOLS` is tri-state — `1` force-on · `0`/empty off · unset ⇒ advertise iff `BRIDGE_TOOLS_ENDPOINT` is set and a token resolves (with the unreadable-explicit-source short-circuit); snapshot 0.5.3 → 0.6.0 (DL-038). (4) **Stays explicit permanently:** the write-scope block `{board_id, swimlane_id, create_stage_id}` (the swimlane is the agent-keyed read-isolation boundary — deriving it from the repo-keyed DL-027 create-lane is a category error, per the roundtable #102 design arc) and the one `BRIDGE_TOOLS_ENDPOINT` line (deriving it re-opens the DL-220 default-vhost trap or the push-handshake bearer-exfil edge). UDS seats are inert by construction. Bearer **collision, suppression, and unreadable-bearer now FAIL `bridge:check`** (were WARN-class); board-STATE checks stay WARN. Design ran a 7-round fresh-adversarial loop to 0 must-fix, operator-gated twice; the v7 truth table (12 rows) ships as the test spec verbatim. The `.mjs` tri-state advertise + channel-token fallback have no in-repo harness — aimla's first live enablement is the committed live-fire witness (canon #9, on the record in roundtable #102).

### Changed
- **#361 (card#4902, roundtable #102)** — **runbook: multi-user actor callout + loopback-port vhost as a first-class same-box recipe** (docs-only), from aimla's live enablement of the v0.65.0 package: § Same-box enablement now maps steps to actors (root owns the endpoint step; the agent's OS user owns channel env + restart + bearer placement; the bridge operator runs config/mint/check), and a `Listen 127.0.0.1:<port>` loopback-port vhost is documented alongside the `/etc/hosts` pin — no box-global DNS side-effect, no default-vhost Host ambiguity on a multi-vhost box, loopback-only. The channel-server README's over-narrow "needs the /etc/hosts recipe" claim reworded to present both; snapshot 0.5.2 → 0.5.3 (DL-038).
- **docs: solo orientation synced to coord v0.20.0** (#363; `CLAUDE_AGENTBOARD.md` — subagent dispatch REQUIRED + STOP-and-retry on API/limit termination; context-backstop CLEAR auto-fire nudge; Compliance section compressed to owner-pointer).
- **docs: `VERSIONING.md` § Release flow reconciled with the retired PR-open checkpoint** (release-prep commit; the "ask before opening every PR" hybrid-policy line predated v0.62.1's CLAUDE.md rule #5 — PR-opening is pre-authorized; the sole hard gate remains user-merged `main`).

### Fixed
- **#360 (DL-221, card#4852)** — **the DL-resolution move path stamps `pr_number`+`pr_url`, sole-DL-gated.** A DL-only feature PR moved its card but stranded it without PR correlation refs (DL-187's deliberate stance, now superseded with guards), and `pr_url` — a registered payload key driving kanban's multi-repo by-ref `source` derivation — was stamped by **no** path. Now: `stampRefs` emits `stamp_pr_url` (from `pull_request.html_url`); the handler writes `pr_url` add-if-missing exactly like `pr_number` (flat body, DL-219); the DL-win branch threads stamp refs into every per-card target — resolved DL **excluded** (`dl_number` is never re-stamped; identity-poison protection stands) and **sole-DL-gated** (a 2+-DL title is release-shaped; its PR refs are foreign to the cards). The explicit-`card#` path stamps regardless; the `started` push path is byte-identical. Sibling audit: `GitHubPrCardMoveClassifier` is the only `kanban_move_card` emitter; no missed emitter, no double-stamp.

**UPGRADING (DL-222):** a present `board_tools` block with **no `enabled` key** previously no-op'd silently; it now **turns ON** (if its scope is complete and a bearer resolves — the channel token now qualifies by default) **or suppresses + FAILs `bridge:check`**. To stage a block silently, set `enabled: false` explicitly. Explicit installs (`enabled: true` + `auth.token_path` alias + `BRIDGE_CHANNEL_TOOLS=1`, aimla's live shape) upgrade **byte-identically** (test-pinned); to converge on the default path, drop the alias line + token file and let the channel token carry it. Channel servers: `BRIDGE_CHANNEL_TOOLS` unset now advertises tools when `BRIDGE_TOOLS_ENDPOINT` is set and a token resolves (`=1`/`=0` keep their explicit meanings). Also note the bearer-authority widening and the WARN→FAIL check promotions described in #362 above.

## [0.65.0] - 2026-07-22

**Minor — the board-tools same-box enablement package (DL-220): `bridge:provision-tools` mints the per-agent bearer, `bridge:check --probe-tools` live-certifies the loopback+bearer path, the Apache/FPM endpoint runbook lands, and the no-`TrustProxies` gate posture is test-pinned. Plus channel-server dependency bumps and a solo-orientation doc sync.** 3 PRs since v0.64.1 (#357, #356, #355). **No migration, no new required `.env`, no receiver accept/reject change, no token-scope change. Both new commands/flags are additive; absent them, behavior is byte-identical.**

### Added
- **#357 (DL-220, card#4846, roundtable #102)** — **board-tools same-box enablement package**, closing the delivery gaps aimla-pm's first-validation pass found on the v0.63.0 surface. (1) **`bridge:provision-tools {--agent=} {--dry-run}`** mints each enabled agent's `board_tools` bearer at its configured `auth.token_path` — the bearer DL-217 specified as *provision-minted* but `bridge:provision` never delivered: 0600 umask-safe (perms pinned before the secret bytes land), idempotent (an existing secure file is a no-op), fail-loud on insecure perms (DL-010 posture), cross-agent collision detection at mint time (a collision fail-closes both agents at request time — surfaced at provision instead of on the first live call); never edits agent YAML (an explicitly-named agent without the block gets a paste-ready skeleton + non-zero exit). (2) **`bridge:check --probe-tools=<endpoint>`** — opt-in live probe: a real `board_my_cards` per enabled agent over the actual network path (TLS verify on), certifying reachability, bearer→agent resolution, and the swimlane-isolation scope header; failure modes name their cause (403 → the endpoint-recipe trap; 401 → bearer path/collision; connection refused → vhost); a missing/unreadable bearer FAILS the probe (an explicit certification run must not exit 0 over a broken enablement). (3) **`docs/board-tools.md` § Same-box enablement (Apache/FPM)** — the end-to-end runbook, leading with the endpoint trap: `https://<public-host>/…` on-box FAILS the loopback gate (the kernel's local-route source selection makes the TCP peer the box's public IP); the recipe is an `/etc/hosts` loopback pin of the bridge hostname + the real-hostname https endpoint (TLS verification stays ON). (4) **XFF posture pin** — the `LoopbackOnly` gate is sound only while no `TrustProxies` middleware exists (mod_proxy_fcgi forwards the true TCP peer as `REMOTE_ADDR`; a forged `X-Forwarded-For` is never consulted); that posture was documented but unpinned — two new feature tests (external peer + spoofed loopback XFF → 403 with no outbound call; loopback peer + external XFF → admitted) go red the moment a `trustProxies` registration lands, mutation-proven.

### Security / dependencies
- **#356 (card#4838)** — **channel-server transitive dep bumps** (`examples/channel-servers`, lockfile-only): body-parser 2.2.2 → 2.3.0, fast-uri 3.1.2 → 3.1.4, hono 4.12.26 → 4.12.31 — consolidating dependabot #343/#353/#354, which each failed the DL-038 version-bump-guard (dependabot cannot bump the snapshot version, and three separate bumps would conflict). Snapshot version 0.5.0 → 0.5.1; `npm audit --audit-level=high` clean (2 pre-existing moderates on `@hono/node-server` via the MCP SDK, below the gate). The #357 README correction bumps the snapshot again, 0.5.1 → 0.5.2.

### Changed
- **docs: solo orientation synced to coord v0.17.0** (#355; `CLAUDE_AGENTBOARD.md` — human-prompt bookends; repo-agnostic subagent dispatch).

## [0.64.1] - 2026-07-21

**Patch — release-CI dependency pin + a solo-orientation doc sync; zero runtime code change.** 2 PRs since v0.64.0 (#350, #348). **No migration, no new required `.env`, no receiver accept/reject change, no token-scope change; no `app/` file touched.**

### Security / dependencies
- **#350** — **PupFuzz/agent-board-toolkit/promote 0.19.0 → 0.20.0** (release CI, `release-promote-cards.yml`). The old pin 422s the wrapped `{"task":{…}}` promote body against a flat kanban (the DL-219 cutover — v0.64.0); v0.20.0 sends flat write bodies matching the flat v3 write API. **This pin runs only on the merge-to-`main` release event, so it takes effect only on `main` — this release activates it.**

### Changed
- **docs: solo orientation synced to coord v0.15.1** (`CLAUDE_AGENTBOARD.md`; internal agent-framework orientation stamp).

## [0.64.0] - 2026-07-21

**Minor — the bridge's `KanbanClient` sends FLAT v3 write bodies (the missed kanban DL-219 companion), plus a PR-title correlation-conflict fix.** 2 PRs since v0.63.0 (#346, #345). **⚠ LOCKSTEP: deploy this simultaneously with the kanban-flat upgrade (v0.36.0) and toolkit v0.20.0 — a wrapped bridge 422s against a flat kanban (and vice-versa); there is no shim.**

### Fixed
- **#346 (DL-219, roundtable #118)** — **`KanbanClient` now sends task-write bodies FLAT (top-level), dropping the `{"task":{…}}` wrapper.** kanban v0.36.0 (its DL-219) unified the v3 write API to flat top-level fields and now STRICT-REJECTS an unknown top-level `task` key (422, no shim) — and the bridge writeback was a **missed consumer** of that cutover (kanban's DL-219 note said "no other known consumers"). All four write sites drop the wrapper: `moveCard` (`{workflow_stage_id}`), `stampCorrelationRefs` (`{payload}`), `setBlockReason` (`{block_reason}`), `createCard` (posts the assembled fields flat). `archiveCard`'s `{"_action":"archive"}` is a top-level control key, unaffected. Unconditional cutover (no version probe) matching the no-shim server change. Exhaustive `app/` audit confirmed these were the only wrapped task-writes (every other `/tasks` call is a GET; `KanbanProvisionClient` posts only to `/webhooks`). Tests updated across 9 files asserting the flat body shape; red-when-reverted (reverting to wrapped reds 56 assertions).
- **#345 (DL-218, card#4815, roundtable-triggered)** — **PR-title / branch card-correlation no longer hijacked by a foreign `DL-NNN`.** When a PR title or branch ref carried a `DL-NNN` that resolved to a card DIFFERENT from a co-present explicit `card#<id>` (e.g. "guard against DL-219 (card#4811)"), the DL silently won and the intended `card#` was dropped. `GitHubPrCardMoveClassifier` now detects the conflict via a shared `cardConflicts()` predicate at all THREE resolution sites (PR move, branch-create `started`, draft overlay), warns loudly, prefers the explicit `card#`, and excludes the foreign DL from being stamped onto it. 4 conflict tests (incl. the bundled-DL edge), all red-when-reverted.

## [0.63.0] - 2026-07-21

**Minor — two-way board tools (DL-217): an agent-initiated request/response surface over the existing channel, plus a release-CI dependency bump and a solo-orientation doc sync.** 2 PRs since v0.62.1 (#341 + dependabot #340). **Opt-in, fail-closed end to end ⇒ absent the new per-agent `board_tools` config AND the channel server's `BRIDGE_CHANNEL_TOOLS=1`, behavior is byte-identical. No migration, no new required `.env`, no receiver accept/reject change, no token-scope change.**

### Added
- **DL-217 (card #4719, roundtable #102) — two-way board tools.** The bridge gains an agent-initiated request/response surface over the existing channel, exposing two channel-identity-scoped MCP tools so an impl seat with **no kanban token and no toolkit** gets a board window: `board_my_cards` (read-proxy — your own product swimlane grouped by stage + a shared swimlane + coordination cards addressed to you; the kanban token never leaves the bridge) and `board_create_card` (create a card in **your own** swimlane — forced from config, args cannot name a lane; born untriaged). **Opt-in, fail-closed end to end: absent a per-agent `board_tools` block AND absent the channel server's `BRIDGE_CHANNEL_TOOLS=1`, behavior is byte-identical.** New pieces: the Node channel server advertises a `tools` capability + proxies `tools/call` to the bridge over HTTP loopback with a per-agent bearer (dumb pipe — no board logic, no token, no retry); a new loopback-gated `POST /agent-tools/call` (the `LoopbackOnly` middleware gates the TCP peer `127.0.0.0/8` + `::1`, sound under the no-`TrustProxies` posture; the per-agent bearer is defense-in-depth on top, resolved iterate-and-`hash_equals`, fail-closed on unreadable token per-agent and on token collision for both); own-swimlane forced-scope create with reserved-tag (`created-by:`/`idem:`/`id:`/`type:`/bare `triaged`) + idempotency-key charset sanitization and the full DL-198 correlate-before-create + `CardCollapse` idempotency; a fail-closed swimlane row filter on reads. `bridge:check` gains board-tools probes (token readability, token collision, swimlane/stage existence, service-user board membership). **Functional prerequisite:** kanban's `tasks/search.json` needs the `swimlane_id=` q-term (a sibling of `workflow_stage_id=`); an un-upgraded kanban returns empty (broken-but-safe). Docs: new `docs/board-tools.md`, the channel-server README section, the MCP `instructions` string (reworded — channel events stay one-way notifications, the tools are the new request/response surface), and corrections to standing one-way claims in `docs/writeback.md`, `docs/multi-host.md` (incl. the pre-existing FALSE `no-port-forwarding` "still permits `-R`" claim — `no-port-forwarding` forbids both `-L` and `-R`), and `CLAUDE_ARCHITECTURE.md`.

### Changed
- **docs: solo orientation synced to coord v0.10.0** (`CLAUDE_AGENTBOARD.md`; operator-responsiveness rules + the managed orientation block).

### Security / dependencies
- **#340** — **PupFuzz/agent-board-toolkit/promote 0.17.0 → 0.19.0** (release CI, `release-promote-cards.yml`). Interface verified compatible against `promote/action.yml` at the new pin (`1965ee3`) — all five inputs (`writeback-token`, `expected-host`, `api-base`, `dls`, `dry-run`) unchanged; the action runs only on the merge-to-main release event.

## [0.62.1] - 2026-07-19

**Patch — governance-docs + dependency bumps only; zero runtime code change.** 7 substantive commits since v0.62.0 (PRs #336, #337, four dependabot merges #331–#334, plus a docs-only solo-orientation sync). **No migration, no new required `.env`, no receiver accept/reject change, no token-scope change; no `app/` file touched.**

### Changed
- **#336** — **docs: the ask-before-every-PR checkpoint is retired** (CLAUDE.md rule #5). The dev-PR lifecycle (open → wait-for-green → self-merge) is documented as fully pre-authorized; the hard gate remains solely "only the user merges to `main`".
- **#337** — **docs: the custom security-surface extra-approval gate is dropped** (CLAUDE.md rule #5), folding that surface into the standard fully-autonomous dev flow + user-gated release.
- **docs: solo orientation synced to coord v0.8.0** (`CLAUDE_AGENTBOARD.md`; coder/mechanic subagent model + USER-GATING pointer).

### Security / dependencies
- **#331** — **actions/setup-node 6.4.0 → 7.0.0** (CI-only, `channel-server-supply-chain.yml`). The v7 default-node change is inert here: the step pins `node-version: '20'` explicitly.
- **#332** — **PupFuzz/agent-board-toolkit/promote 0.12.1 → 0.17.0** (release CI, `release-promote-cards.yml`). Interface verified compatible against `promote/action.yml` at the new pin — all five inputs (`writeback-token`, `expected-host`, `api-base`, `dls`, `dry-run`) unchanged; the action runs only on merge-to-main release events.
- **#333** — **laravel/framework 13.19.0 → 13.20.0** (framework minor; full SQLite + MariaDB matrix green on the PR).
- **#334** — **nunomaduro/collision 8.9.4 → 8.9.5** (dev-dependency patch).

## [0.62.0] - 2026-07-19

**Minor — a new opt-in `coord_non_addressed_disposition` inbox-stage seam (DL-215) + a seen-cursor read-modify-write race fix (DL-216), plus a dedup/refactor batch (audit findings F7, F8, F10–F14) and two CI-hygiene fixes.** 11 PRs since v0.61.0 (#319–#329). **No migration, no new required `.env`, no receiver accept/reject change, no token-scope change; the one new feature is opt-in ⇒ absent-config byte-identical.**

### Added
- **#319** — **`coord_non_addressed_disposition` (DL-215, card #4650, roundtable #90):** the `coord-message` family gains the durable-inbox sibling the `impl-ci-wake` family already had (`impl_non_wake_disposition`). A coordination event that fails the recipient gate was unconditionally gate-dropped; the new key (`drop` default | `inbox_stage`) lets it be staged to the durable inbox instead. Under `inbox_stage` the normal coord `Intent` (`coord_issue`/`coord_pr`/`coord_comment`) is built with **no** `channel_push` — a durable record, never a live wake. It softens **only** the recipient gate (`$forMe`): the `subject === null` (unhandled action) and `drop_title_all_of` (noise-title) drops still fire, an addressed event still live-wakes, and `route_intents:true` routes the staged intent with no hand-emit (no wake widening). Any value other than `inbox_stage` falls back to `drop`. Absent key ⇒ `drop` ⇒ byte-identical to pre-DL-215.

### Fixed
- **#320** — **seen-cursor locked read-modify-write closes the #4630 clobber race (DL-216, card #4630):** the DL-199 review deferred a race in the inbox seen-cursor. `BridgePaths::writeSeen` locked only the write, not the read→modify→write around it, and two callers open-coded an unlocked RMW on the same cursor file (`InboxCommand` advance; `RetentionService::pruneSeen`, the DL-212 sweep). A prune landing between inbox's read and its write could write an intersection computed from the pre-advance cursor, dropping a just-marked id back to unseen (consequence bounded — one re-delivered wake, never data loss). New `BridgePaths::updateSeenLocked(path, transform)` — the JSON-array sibling of `filterJsonlLocked` — holds one `flock(LOCK_EX)` across read→transform→write; both callers route through it. `writeSeen` is **retired** (the unlocked-write primitive that let the race exist is gone), leaving `updateSeenLocked` the sole cursor writer. Byte-identical on-disk format; the change is purely the concurrency guarantee.

### Changed
- **#321** — **fold the writeback config-load resolve into `WritebackConfig::loadDefault()`** (card #4500, dedup audit F7). The `config('bridge.config_dir')` → `WritebackConfig::load()` resolve was copied byte-for-byte across 13 sites (6 handlers, `GitHubPrCardMoveClassifier` ×3, `CoordinationClassifier` ×2, plus `ReconcileCommand` and `WritebackAlertNotifier` — the two the audit under-counted, surfaced by canon #7 + review). One shallow `loadDefault(): ?self` folds **only** the resolve; every caller keeps its own fail branch. Zero behavior change.
- **#323** — **consolidate `KanbanClient::idList` (×3) + `preloadStages` (×2) within-file** (card #4503, F10). One `idList` numeric-`id` projection shared by `findCardsByRef`/`cardsByTag`/`boardSwimlaneIds`, and one `preloadStages` fetch+descent shared by `boardStageOrder`/`boardStageIdsByName`; each caller keeps its own fetch/field-guard. The `correlate*` scan loops are left inline (different predicate). No behavior change.
- **#324** — **consolidate the tolerant string-list coercion into `StringList::coerce`** (card #4501, F8). The *non-array → `[]`; scalar → `(string)`, else `''`; never-throws* idiom collapses three copies (`EchoSuppressionConfig`, `SubscriptionConfig`, `AgentRegistry`) into one primitive; the strict `ClassifierConfig` string-list family stays separate by design. No behavior change.
- **#326** — **reuse `SecretFile::permsMessage` for the reconcile insecure-secret refusal** (card #4506, F13). `WebhookProvisioner`'s reconcile-refusal carried the most-drifted copy of the file-perms message (no `mode`); it now reuses the canonical mode-bearing form. Operator-facing diagnostic enrichment only — no accept/reject or control-flow change.
- **#327** — **collapse three plain-text `Response` constructions into `PlainTextResponse::make`** (card #4504, F11). `WebhookController::plain()`, `VerifyHmacSignature::fail()`, and `EnvelopeSizeLimit::tooLarge()` shared a byte-identical `text/plain; charset=utf-8` Response build; one static primitive reproduces it exactly (status, content-type, body all byte-identical). No behavior change.
- **#328** — **consolidate the strict require-mapping config helper into `ConfigMapping::require`** (card #4505, F12). `AgentConfig::requireMapping` and `ClassifierConfig::section` differed only by their `ConfigException` label; one label-parameterized primitive folds both. The deliberately-tolerant `AgentConfig::section` twin is left as-is (a guard test proves it isn't routed through the strict helper). Byte-identical messages and accept/reject.
- **#329** — **fold three same-behavior dedups from the F14 grab-bag** (card #4507). Per-item judgment: 3 of 9 items confirmed clean and folded — `AgentRegistry::numericUid()` (the non-numeric-id-must-not-`(int)`-coerce guard, 4 copies), `CsvEnv::parse()` (the comma-separated `BRIDGE_*` env idiom), and `DispatchService::exceptionNote()` (the `class: message` no-trace note format). The remaining items were left inline as genuinely-different behavior; the `256*1024` body-cap default (item 6) touches the receiver accept/reject gate and was surfaced for human decision, not changed. No behavior change.
- **#322** — **ci:** align the `setup-app` composite's `shivammathur/setup-php` pin to `2.37.2` (`f3e473d`) (card #4502), matching the pin `security.yml` already runs — an already-trusted SHA. Dependabot PR #145 bumped setup-php in `security.yml` but left the composite `action.yml` behind because dependabot's github-actions ecosystem doesn't scan composite actions.
- **#325** — **ci:** switch the github-actions dependabot entry to a `directories` glob (`/` + `/.github/actions/*`) so composite-action pins are maintained too (card #4651) — the recurring-drift root cause behind #4502. Scoped disjointly so no duplicate PRs. No code change.

## [0.61.0] - 2026-07-18

**Minor — the `issue_population` writeback axis for general (non-prefixed) coordination-issue cards (DL-214) + DL-199 event-gate retention now actually runs on the receiver, plus the `comment_to` wake fleet-default (DL-213) and a batch of dedup/diagnostic hardening (DL-208–212).** 9 PRs since v0.60.0 (#308–#316). **⚠ Two upgrade-affecting behavior changes — retention starts pruning (DL-199) and `comment_to` wakes by default (DL-213); see UPGRADING. No migration, no receiver accept/reject change, no token-scope change.**

### Added
- **#316** — **`issue_population` writeback axis (DL-214, card #4553):** a new per-mapping `issue_population` (`prefixed` default = byte-identical DL-198/200 | `all`) gates which coordination issues get a tracking card. Under `all`, non-prefixed issues (null `sid`) are carded/moved and the handler correlates the null-`sid` target by the **`github_issue` by-ref system** (ref from the payload `issue_number` key, verified live). Adds `KanbanClient::correlateIssue` (mirroring `correlatePr`), completing the bridge normalizer's mirror of kanban's closed system set (`github_issue` was the missing one). Fail-closed on an unrecognized value; prefixed issues and the prefixed default stay byte-identical.
- **#313** — **DL-199 event-gate retention runs off the inbound webhook (card #4322):** DL-012 shipped `bridge:prune` and scheduled it **nowhere** (three installs, ~45 days, zero prunes ever). Retention now runs from a terminating callback **after** the response is sent — no cron, no daemon, no queue — so the append-only stores stay bounded. `RetentionGate` is interval-gated (default 24h once drained), bounded-batch (default 500 rows/leg, oldest-first), uses a non-blocking `Cache::lock` (off the DL-001 hot path), and never throws (a 5xx would trigger provider redelivery under load); a full batch clears the marker so a backlog drains over successive deliveries, each pass individually bounded. `RetentionConfig` is the single home for `bridge.retention.*` (the gate acts on it, `bridge:check` reports it); an unparseable window prunes **nothing** (the one direction a destructive path must not fail) and windows are uncast so a bare `true` is a type error, not a 1-day window. `BridgePaths::filterJsonlLocked` holds one `flock(LOCK_EX)` across read→filter→truncate→write, closing a pre-existing lost-update race (an append landing mid-rewrite was acked 200 then truncated away) and streaming one line at a time so a large inbox can't OOM the FPM worker.

### Changed
- **#315** — **`comment_to` wake becomes a fleet DEFAULT (DL-213, card #4632, roundtable #87, converged 3/3):** the post-a-reply-and-wait coordination flow was dark out of the box — `comment_to` (DL-192, the body-`TO:<self>` cross-thread grant) was opt-in, absent from the inherited `[to_me, to_all]` default. `wake_membership` now defaults to `[to_me, to_all, comment_to]`. A straight default-flip (not a DL-204 guarded default — `comment_to` has no required sibling key and is inert outside the `coord-message` family, so there is no partial-config throw to guard); additive-only, echo-safe (keys on the comment's directed `TO:` line, not authorship), `from_me` stays opt-in. `bridge:check` warns for the one population the flip can't reach (an install that set `wake_membership` explicitly and omitted `comment_to` — the flip must not silently rewrite a deliberate config); absent-key installs get no warn. New `ClassifierConfig::has()` explicit-key presence test.
- **#314** — **consolidate 6 byte-identical `isPermanent()` 4xx-classifier copies into one `RefusalContext::isPermanent()`** (card #4499, dedup audit F6). Pure extract, no behavior change: a 4xx is a permanent refusal (swallow + log the scrubbed body), a 5xx/transport error rethrows so the receiver 5xxs and kanban re-delivers. The two `ReconcileCommand` per-status sites are deliberately left inline (their 403-as-fatal is a different decision). New boundary unit test on the primitive, mutation-verified.
- **#311** — **consolidate the two divergent path-segment sanitizers into one `PathHelper::sanitizeSegment`** (DL-211, card #4497, dedup audit F4). One primitive with the stricter, traversal-safe semantics; `BridgePaths::sanitizeAgent` becomes a byte-identical thin delegate. Migration proven unnecessary (the only live instance's state dir holds only shared-layout files that bypass both sanitizers — zero sanitizer-derived files to rename/orphan).
- **#310** — **share one GitHub token resolve→probe→classify primitive across `bridge:check` + `bridge:reconcile`** (DL-210, card #4496). The probe+classify logic had drifted (canon #5/#7): `reconcile` branched HTTP status into an operator hint and errored loudly on network failure; `check` did no status branching and silently swallowed network failures. Extracted `GitHubRepoProbe` (+ result value object + kind enum) as the single authority, owning the canonical status→hint table once. **Intended behavior change:** `bridge:check` now emits reconcile's status hint (was a bare HTTP code); `bridge:reconcile` is behavior-identical.

### Fixed
- **#312** — **`bridge:prune` sweeps seen-cursors independently, closing the shared-layout retention gap** (DL-212, card #4498). `pruneInboxFiles` bounded seen-cursors by pairing each to an `inbox*.jsonl` file, but under shared layout `bridge:inbox` reads the shared `inbox.jsonl` while advancing `inbox-seen-<agent>.json`, which has no paired inbox file — so the old sweep never bounded it and it grew unbounded, defeating the DL-012 guarantee. Prune now sweeps cursors directly (`glob inbox-seen*.json`), bounding each against the union of surviving ids (an intent id embeds its agent, so the union-intersect equals the exact per-agent bound). Also hoists the cursor primitives to `BridgePaths`, killing the `InboxCommand`↔`PruneCommand` duplication.
- **#309** — **`bridge:check` defers the `alert_channel.url` gate to the runtime sender (`LocalhostUrl`)** (DL-209, card #4495). `checkAlertChannel` hand-rolled a loopback/SSRF gate that had drifted from the sender — it dropped the userinfo rejection `LocalhostUrl::assertValid` enforces, so `http://user:pass@127.0.0.1/hook` **passed** `bridge:check` but was **rejected** at send time. `check` now defers to `assertValid` (the inline copy deleted, not preserved), so check and sender can never disagree again. The shared loopback-host membership predicate is extracted to `LoopbackHost::matches()`; the two validators are NOT merged (opposite scheme policies) — only the sub-predicate is shared.
- **#308** — **`EventDrivenClassifier` inherits the `route_intents` wake-guard** (DL-208, card #4494). It appended a `channel_push` per intent unconditionally, lacking the DL-191 `route_intents` guard `CoordinationClassifier::wakePush` has — so on a `route_intents:true` channel the dispatcher's synthesized push and this hand-emitted one both survived coalescing (distinct handler|debounceKey) and the push handler fired **twice**. The guarded `wakePush()` is lifted to the shared `InboxOnlyClassifier` base; `route_intents:false` is byte-identical, `route_intents:true` now wakes once (was twice). Reverses the "don't pair EventDriven with route_intents" footgun.

### Upgrading
- **DL-199 retention now runs (default ON).** `bridge.retention.enabled` defaults to `true` with `older_than` = `30d`, and retention now executes off every inbound webhook. An install that accumulated more than 30 days of `webhook_events` will **begin pruning events older than 30 days** on the first deliveries after deploy (previously `bridge:prune` was scheduled nowhere, so nothing was ever pruned). This is the intended bounded-store behavior. To keep the prior never-pruned behavior set `BRIDGE_RETENTION_ENABLED=false`; to widen/narrow the window set `BRIDGE_RETENTION_OLDER_THAN` (e.g. `90d`). `null_payloads_older_than` defaults to off (`''`). `bridge:check` reports the resolved retention posture.
- **DL-213 `comment_to` wakes by default.** `wake_membership` now defaults to `[to_me, to_all, comment_to]`, so a comment whose body carries `TO:<self>` on a thread you don't otherwise own now triggers a live-wake. An install that set `wake_membership` **explicitly** is unchanged (the default never overrides an explicit config); `bridge:check` warns if you set it explicitly and omitted `comment_to`. To opt out, set `wake_membership` explicitly without `comment_to`.

## [0.60.0] - 2026-07-17

**Minor — the opt-in `promote_on_release` writeback leg (DL-207) + the dependabot `id:` provenance tag (DL-206), plus a live-defect refusal-logging fix (DL-205) and the DL-204 live-witness note.** 4 PRs since v0.59.0 (#302–#305). **No migration, no new required `.env`, no receiver accept/reject change, no token-scope change; both new features are opt-in ⇒ absent-config byte-identical.**

### Added
- **#305** — **`promote_on_release` (DL-207, card #4483, roundtable #73):** a card-first board with a Shipped-to-dev / Released-to-main split had **no steady-state promotion** — a base=dev feature card reaches Shipped but fires no `merged_to_main` event of its own (its commits reach `main` folded into a later release PR), so it stranded at Shipped until a manual `bridge:reconcile` (which deliberately defers Shipped→Released to "the promote workflow"). This opt-in per-mapping leg **is** that workflow: on the release merge to `main` the classifier emits a `kanban_promote_released` target (single choke-point, independent of the release PR's own token) and `KanbanPromoteReleasedHandler` (`DurableReaction`, always-on) scans the mapped board and promotes every Shipped card whose merged PR's commit is now on `main` to Released. Promotion keys on **POSITIVE reachability** — `compare(merge_commit_sha...main).status ∈ {ahead, identical}`, gated on the PR being `merged` — not a dev-only proxy (which admits open-PR test-merge shas / third-base merges / cross-repo shas into the terminal Released stage; caught in design review). Reuses `stages.merged`/`stages.merged_to_main` (both **required**, fail-closed at load). **Precondition:** the release→`main` merge must preserve dev's shas (merge-commit/fast-forward — this fleet's method); a squash/rebase release-merge promotes nothing (unguardable — a doc precondition). **First runtime GitHub-read dependency:** the leg reads GitHub from the FPM receiver where `GH_TOKEN` is absent and the store helper is CLI-only (DL-184), so it needs a **placed `<secret_dir>/github/token`** (or `providers.github.token_path`); unresolved token / same-column stages are inert and `bridge:check` **warns** (no reconcile backstop for this transition). NOT gated on #4391 (runtime writeback token, not the CI promote secret). The card→PR precedence is extracted into a shared `TrackedCardRef` helper single-sourced with `bridge:reconcile` (canon #5). New `GitHubReadClient::compareStatus` + `getPull` widened for `merge_commit_sha`.
- **#304** — **`card_id_tag_template` (DL-206, card #4485, roundtable #75, converged 3-way):** `KanbanDependabotCardHandler` minted dependabot cards with **no `id:` provenance tag** (unlike their impl-created siblings), so tag-keyed Shipped→Released promoters stranded them (27 confirmed on sola board 3). An opt-in per-mapping free-form template renders an `id:` tag stamped on each created dependabot card (`{n}`/`{pr_number}` = PR number, `{repo}` = repo name); absent ⇒ no tag (byte-identical). Decouples from #4483, which keys on the bridge-native `payload.pr_number` every card already carries — this template serves the **external** `id:`-keyed readers (sola's reconcile).

### Fixed
- **#303** — **refusal logging carries kanban's response body, scrubbed (DL-205, card #4409):** every writeback-handler 4xx `RequestException` catch logged **status only** and discarded the body, so a permission refusal was indistinguishable from a config typo (why DL-204 hid for 12h); and some log lines asserted an unchecked cause. Now the scrubbed response body is logged at each 4xx site and the lines state what was OBSERVED instead of guessing.

### Docs
- **#302** — **DL-204 live-witness note (card #4357):** append-only dated record that aimla live-validated the `absent → on` `move_coord_cards` fleet-default path on board 10 (roundtable #18) — the one path no fleet seat had exercised pre-adoption; reversible exercise, window co-check clean. Supersedes the "no live witness" posture. Doc-only.

## [0.59.0] - 2026-07-16

**Minor — the coord-issue → card MOVE writeback (`move_coord_cards`) becomes a guarded fleet default (DL-204): absent ⇒ on where the terminal stage is configured, inert where it is not.** 2 PRs since v0.58.0 (#298 feature + #299 doc-note). **No migration, no new config key, no change to what the receiver accepts/rejects, no token-scope change.** One config shape changes behavior — see **UPGRADING** below.

**DL-204 (#298)** — DL-200 shipped `move_coord_cards` as a per-mapping opt-in (default false), a second flag every install had to set on top of already configuring the per-board stage ids. Roundtable #18(b), approved 3/3: make the real-time close→terminal / reopen→revive MOVE a **fleet default**, so an install whose coordination board already carries the stage ids gets the leg without a separate flag — guarded so it can't detonate on adoption (the naive `default: true` would throw at load on every coord install missing the two stage ids).

- **Gate 2 — the handler-side default (`WritebackConfig::load`):** when `move_coord_cards` is **absent** it resolves **ON iff `coord_card_terminal_stage_id` is present**, inert otherwise. The terminal key has no other consumer, so its presence is the exact "operator configured the move leg" signal — "byte-identical for unconfigured" is precise and "active where configured" is precise. An **explicit** `move_coord_cards` (true or false) is honored exactly (opt-in still fail-closed on an incomplete config; opt-out still silent-off). The existing DL-200 fail-closed guards are **unchanged** and now deliver "inert when unconfigured" + "loud when partial" for free. Only one config shape flips behavior: **terminal present + `move_coord_cards` absent** goes inert → active.
- **Scope — GATE 2 ONLY; `DEFAULT_FAMILIES` (gate 1) is untouched.** The leg fires only where BOTH the `coord-card-move` classifier family (gate 1, in the agent YAML) AND `move_coord_cards` (gate 2) are on. `coordCardMoveFamily()` self-gates on `move_coord_cards`, so flipping gate 2 alone is strictly **inert** where gate 1 is off — never a wrong move. Adding `coord-card-move` to `DEFAULT_FAMILIES` would change classification for installs that never opted into coord-card handling (out of the approved scope) and was **not** done. Consequently the flip is a **no-op for installs already running the family with explicit `move_coord_cards: true`** (e.g. AIMLA board 10, live since v0.58.0); its beneficiaries are family-enabled installs whose terminal is set but the flag omitted.
- **`bridge:check` — two discovery nudges + a family-gated compare.** The check now keys on the **`coord-card-move` family being enabled for the scope** (not on `move_coord_cards`, which post-flip reads true from terminal-presence alone, decoupled from whether the leg can fire): **(a)** two config-only discovery WARNs, one per silent-inert direction — a family-enabled scope whose `coord_card_terminal_stage_id` is **absent** (classified but the handler won't move), and its mirror, a scope whose move leg resolved on (terminal present) but which runs **no `coord-card-move` family** (the handler would move but nothing classifies a move — the adoption-path death of "set the terminal, no flag needed"); **(b)** the DL-200 terminal-agreement cross-config compare is **gated on the family** so it never verifies a terminal for a leg that cannot fire and reads as though it were live. Scoped to family-enabled installs so a pure PR-writeback install gets **no** coord-move noise.
- **Live-validation posture (honest).** **No fleet seat live-witnesses the `absent → on` path** — confirmed on all three configs post-approval, not assumed: AIMLA is explicit-true (the flip never runs there; it is instead the **byte-identical regression witness**, cards 4388/4392/4416/4421/4423/4428/4435 → stage 74 on 07-15), kanban-solo is Layer-B (inert), and sola carries **neither** coord key on any mapping (terminal absent everywhere ⇒ resolves inert — the terminal is the blocker, not merely the omitted flag). So the `absent → on` path ships **phpunit-matrix + mutation + fresh-adversarial design/impl-review verified only**, the one path no live board exercises; the sole live check is AIMLA's post-adoption re-confirm of the explicit-true regression leg (#299 corrects the DL-204 note to state this plainly rather than over-conditioning on "sola if the flag is omitted").

**UPGRADING:** an install with `coord_card_terminal_stage_id` configured but `move_coord_cards` unset **will start moving cards** on coord-issue close/reopen **where the `coord-card-move` family is enabled**. To keep it off, set `move_coord_cards: false`. Installs with the terminal unset, or with an explicit `move_coord_cards`, upgrade **byte-identically**.

887/887 phpunit (+9 over v0.58.0), phpstan L7 0, pint clean. Fresh-adversarial design review before code (caught the family-gating gap) + an adversarial impl round after (added the mirror silent-inert nudge); every default-resolution and nudge decision is mutation-verified. Roundtable #18(b); card #4357.


## [0.58.0] - 2026-07-15

**Minor — machine writeback now survives the echo/signal gates (DL-203, a live defect fix), plus action-level consumer declarations for `bridge:check` (DL-202).** 4 PRs since v0.57.0 (#291–#294). **No migration, no new config, no change to what the receiver accepts/rejects, no token-scope change.**

**DL-203 (#294) — the writeback no longer dies with the wake.** The agent-level echo gates dropped the **entire** `ClassifyResult`, so on a seat that authors coordination issues — the placement `docs/writeback.md` itself prescribed — DL-198's real-time coord-card **create** and the reopen-revive were **dead for the dominant event class**, silently, with the consumer's periodic reconcile masking it (reported by an integrator who validated their adoption end-to-end rather than trusting a green `bridge:check`; their config was correct and every check passed).

- **The ruling:** echo/signal are **agent-surface** concerns; the writeback is machine-only ("no agent in the loop"). All three gates — pre-classify echo, `isSignal`, and the DL-005 reattributed-author completion gate — now **classify-then-strip** for a github classifier implementing `EmitsWritebackReactions`: **intents removed** (so inbox staging *and* `route_intents` pushes derive nothing) and **targets filtered to those resolving to a `DurableReaction` handler**. Machine targets survive → `outcome=delivered` with `reason='echo: agent surface suppressed'` (the one delivered-row reason; `error_message` stays handler-failure-only). Strip-to-empty → `dropped` under the **original** gate reason.
- **Fail-closed:** an unmarked or unregistered handler is agent-facing and gets stripped — a classifier bug can never leak an own-write wake or spawn. The partition is `instanceof DurableReaction` on the resolved handler (the same primitive the dispatcher already partitions on), not a second name list that could drift.
- **Provider-gated to `github`**, so the kanban global-echo stream (the writeback identity's own `card_updated`) keeps its cheap pre-classify drop and no write loop is possible.
- **The one measured behavior change on the non-qualifying path** (probe-verified against the prior release, both providers): a dispatch whose classifier FQCN is **unresolvable** and whose actor is an echo now records **`errored`** (replayable) instead of a gate-drop that masked the config error — the marker cannot be read without loading the class, so the resolver runs ahead of the gates *and* the provider gate. Ruled better: a broken classifier config is a real error that must surface. (A DL-025 stale-signature classifier remains an *uncatchable* fatal, as it already is on every ungated event; `bridge:check`'s out-of-process probe is the pre-deploy gate.)
- **Seat guidance corrected** (`docs/writeback.md`): the **identity-less dedicated writeback seat** is now the *preferred* shape (its gates never fire at all); the same-seat placement is supported and now safe. The `github_user_id` footgun is retired **for writeback targets only** — a wake-purposed seat that seeds it still loses its own-push *wakes*, by design.
- `bridge:replay --force` on historical `echo: own write (reattributed author)` rows is the backfill path for cards that were never created.

**DL-202 (#293) — a declared type's unhandled action is no longer invisible.** DL-196's event-follows-consumer check compares at the **top level**, so once any family declared `issues`, an `issues.<action>` that arrived and was dropped by *every* family was invisible — exactly the arrived-and-dropped drift the check exists to surface, one granularity down. `DeclaresConsumedEvents::consumedEventTypes()` may now return **qualified** entries (`issues.opened`) alongside bare types (bare = the type is owned, every action covered). The WARN compare projects to the top level — **unchanged semantics and false-clean-impossibility for every conforming install**. New: one aggregated **INFO** line per scope+type naming observed-but-undeclared actions with occurrence count + last-seen — **never a WARN**, because GitHub has no per-action unsubscribe, so an action-level alarm would have no remedy and would train operators to ignore the check that shares its output. `coord-message` declares `HANDLED` ∪ the install's `coord_extra_actions`; the coord-card families declare via consts shared with their dispatch guards; `impl-ci-wake` and `GitHubPrCardMoveClassifier` stay deliberately bare (reasons in DL-202). *Honest note:* a previously-**invalid** qualified return used to false-WARN its type and now reads as coverage; all shipped implementors were bare-only, so conforming installs are byte-identical.

**Also:** the `bridge:check` event-consumer WARN carries `(Nx, last <timestamp>)` (#292) — its design premise that the observed set is prune-bounded was **false** (retention is event-gated or manual), so one remediated five-week-old stray warned forever, indistinguishable from live drift, and nearly caused a change to a correct production webhook subscription. Deliberately **not** a recency window: bounding the set would let rare-but-real drift read CLEAN and invert the check's own invariant. And the PR-title lint is case-folded to match the classifier's `/i` (#291) — it rejected `Card-4382`/`dl-201` titles the writeback correlates.

878/878 phpunit (+17 over v0.57.0), phpstan L7 0, pint clean. Both features ran a fresh-adversarial design review **before** implementation and an adversarial impl round after; every guard decision is mutation-verified.


## [0.57.0] - 2026-07-15

**Minor — the card correlation token goes DL-shaped: `card-<id>` dash alias, no trailing `\b`, and a loud FR-7 near-miss warn (DL-201).** 1 PR since v0.56.0 (#287). **No migration, no new config, no change to what the receiver accepts/rejects, no token-scope change.** The regex is strictly wider — every previously-correlating token correlates identically.

**DL-201 (#287)** — roundtable #48's ratified fleet card-first standard, bridge side. Both peer operators ratified `card-<id>` as the day-one primary branch/PR token, and both consumers measured the boundary: the shipped `/\bcard#(\d+)\b/i` trailing `\b` made `card#3054_fix` a **silent no-op** (`_` is a word char) while the DL regex — `/\bDL-(\d+)/i`, no trailing `\b` — was immune to the identical input. The asymmetry was the bug.

- **The token** is now one shared `CARD_TOKEN_PATTERN = '/\bcard[-#](\d+)/i'` at BOTH parse surfaces — `classifyPush` (branch ref) and `cardToken()` (PR title + head; the DL-193 draft-overlay correlation reuses it). `card-<id>` and `card#<id>`, case-insensitive, leading `\b` only. Measured 9/9 hook↔bridge agreement by both consumers (a trailing `\b` is 8/9). Greedy-and-loud beats strict-and-silent: a wrong-but-parsed id fails at the card lookup / board-membership guard with a warn; an unparsed token failed silently.
- **FR-7 near-miss warn:** the two un-linked early returns (push path + PR move path) now warn when the text *appears* to name a card in a shape the token doesn't accept (`card_123`, `card123`, `card:123`, `card #123`) — probe `/\bcard(?:[_:.]|\s#)?\d/i`. Token-less refs (`sync/…`, `release/…`, embedded `scorecard_2`, bare `card 2` prose) stay silent: it is a near-miss detector, not an any-unlinked warn.
- **PR-title lint** (`pr-title-lint.yml`) accepts `card[-#]<id>`, and its leading boundary class gains `_` to mirror the classifier's `\b` — previously a `foo_card-<id>` title passed CI but never correlated (the silent direction of a parser split).
- Also: `DL_TOKEN_PATTERN` constant unifies the three DL parse sites; `docs/writeback.md` FR-7 + DL-160 paragraphs synced.

**Consumer adoption:** upgrade to this tag, then pin any local hook filter (publish-at-start `post-checkout`) to the pattern's boundary semantics — under this shape aimla's v1 artifact filter is already correct with nothing to pin. 855/855 phpunit, phpstan L7 0, pint clean; two fresh-adversarial review rounds to zero code findings; regex + probe decisions mutation-verified (revert reds exactly 4 / 2 / 1 tests). Roundtable #48; card #4382.

## [0.56.0] - 2026-07-15

**Minor — one opt-in addition: real-time coordination issue close/reopen → card move (DL-200), the MOVE sibling of v0.55.0's create leg.** 1 PR since v0.55.0 (#284). **No migration, no new required `.env`, no change to what the receiver accepts/rejects, no token-scope change.** Opt-in ⇒ absent-config is v0.55.0 byte-identical.

**DL-200 (#284)** — completes roundtable #18(b). A coordination issue **closing** moves its tracking card to a terminal column in real time, and a **reopen** revives it; previously that waited for each consumer's next periodic reconcile pass. The bridge becomes the real-time **primary** and the reconcile **defers** to it as the backstop — deliberately widening v0.55.0's create-only coord-card scope (archival remains the reconcile's alone). Correlated by the **same `id:<sid>` tag** the create leg writes, so the two legs need no registry.

- **Config (per `writeback.json` mapping):** `move_coord_cards` (bool, default false) + `coord_card_terminal_stage_id`. `coord_card_stage_id` doubles as the **revive target** and is required here too. Four **fail-closed-at-load** guards: required terminal, required revive target, strict-numeric, and terminal **must differ from** the create stage. **Separately opt-in — it does NOT ride `create_coord_cards`** (roundtable #18 "opt-in first"; the fleet-default flip is its own later DL).
- **Classifier:** a new opt-in `coord-card-move` `CoordinationClassifier` family (`issues.closed` → terminal, `issues.reopened` → revive), reusing the create leg's `stableId` so the move-set is byte-identically the create-set. On `issues.reopened` **both** families fire — create-if-absent vs revive-if-present resolve on the tag lookup, so exactly one acts.
- **The revive actor-gate (fail-closed):** a card is revived **only if its terminal was service-set** — `last_stage_move.actor_type === "service"`, an **allow-list**, not a deny-list of the human value (kanban emits `human` / `service` / `null`; a deny-list would silently revive on `null`). Human, null, absent, malformed, or unknown provenance ⇒ **no revive**. Revive also requires the card to still *be* in that terminal, so live work is never dragged backward (DL-163). A **close**, by contrast, overrides a human's user-lane placement — closure wins.
- **`bridge:check` cross-config compare (the mandatory preflight):** the bridge owns a terminal **stage id** while the consumer's reconcile derives its terminal from **column names** in `coordination.config.json`. If they disagree the two movers fight every cycle with each side individually "working" — only comparing the two configs catches it. Reports **agree / DISAGREE / CANNOT-VERIFY**, with **CANNOT-VERIFY distinct from agreement** (a missing input is not evidence of agreement) and **warn-never-fail** (exit 0 on every path). Resolved through the framework's own rule — explicit `terminal_columns`, else the `user_lanes` → `"Done"` lane-model fallback — joined by `board_id` and unioned across entries sharing it.
- **New `BRIDGE_COORD_CONFIG_PATH`** (optional): the per-install override for the coordination config path, for two installs on one host. The ambient `$COORD_CONFIG` remains the fallback and is read **live via `getenv()` at the check** — deliberately not through cached config, which `php artisan optimize` would freeze at deploy time. The read is **CLI-only**: nothing on the FPM request path touches it.
- Also: `KanbanClient::boardStageIdsByName()` (stage name→id off `preload.json`), `kanban_coord_card_move` handler (always-on, inert without config), and the `coord_card_terminal_stage_id` stage-exists probe.
- **New kanban API dependencies** (documented in `docs/kanban-integration-contract.md` §2/§3): `last_stage_move.actor_type` on `GET /tasks/{id}.json` (the actor-gate's sole input; fails closed if kanban ever drops it) and `data.workflows[].stages[].name` on `preload.json`.

848/848 phpunit (+72 over v0.55.0's 776), phpstan L7 0, pint clean. Three adversarial review rounds to zero findings; every guard mutation-verified. The PHP mirror of the framework's `terminals_for_board` rule was differentially tested against the real Python primitive — identical on all 10 board shapes. Roundtable #18(b); card #4352.

## [0.55.0] - 2026-07-14

**Minor — two opt-in classifier/writeback additions + a docs sharpening: the `impl-ci-wake` CI-failure workflow-name filter (DL-197) and real-time coordination issue → card create (DL-198).** 3 PRs since v0.54.0 (#279–#281). **No migration, no new `.env`, no change to what the receiver accepts/rejects, no token-scope change.** Both features are **opt-in** and absent-config ⇒ v0.54.0 byte-identical.

### Added
- **#280** — **`impl-ci-wake` optional `ci_failure_workflow_patterns` CI-failure workflow-name filter (DL-197).** The CI-failure wake can be narrowed to a workflow-NAME allow-list (`ci_failure_workflow_patterns`, case-insensitive substring). Empty (default) ⇒ any workflow's failure wakes — **byte-identical**; non-empty ⇒ only a name-matched workflow's failure wakes. It narrows **which workflows**, never **which conclusions** — the fail-LOUD gate (wake on any non-`benign_conclusions` terminal conclusion) is untouched for a matched workflow, so a new/unknown conclusion type is still surfaced. A run with **no name** is never filtered (wakes fail-loud, preserving pre-filter behavior on a malformed payload); a filtered-out failure becomes a non-wake run (drop / inbox_stage per `impl_non_wake_disposition`). `bridge:check` warns when the filter is set (config-visibility) and guards a malformed pattern list. Roundtable #24 (fleet-converged on scoping the wake to the coordination protocol-integrity workflow). 738/738 phpunit, phpstan L7 0, pint clean; fresh design + impl review.
- **#281** — **Real-time coordination issue → kanban card create (DL-198).** A new opt-in `coord-card-create` family on `CoordinationClassifier` creates a tracking card the moment a recognized-prefix (`[BRIEF|ANNOUNCE|QUERY|REVIEW|TASK]`) coordination issue opens, instead of waiting for the consumer's periodic reconcile. Gated per-mapping (`create_coord_cards` + `coord_card_stage_id`) in `writeback.json`; absent-config ⇒ **byte-identical**. The bridge is the real-time PRIMARY mover and stays **registry-free**; the consumer's periodic `reconcile_simple_board` remains the backstop (its orphan-adoption pass adopts the card by the `id:<sid>` tag). Create-only (never moves/archives a coord card), idempotent by the `id:<sid>` tag (covers redelivery, opened+reopened, and the primary-vs-backstop race — both movers key on the same tag), with a post-create collapse via a shared `CardCollapse` primitive. Byte-exact to the reconcile: anchored `sid` (`^\[(…)\]` on `trim(title)`, no trailing boundary), `type:` mirrors the reconcile's unanchored `_itype` priority-substring scan, `external_id` deliberately omitted (kanban `(board_id, external_id)` uniqueness would 422 a colliding issue number). New `KanbanCoordCardHandler` + `CardCollapse`; `createCard` gains 3 additive nullable params (dependabot caller byte-identical); `bridge:check` warns on a missing coord-card stage / a `create_coord_cards` with no `identity_id`. Roundtable #18(b) (contract locked 3-way in #18/#23). 776/776 phpunit, phpstan L7 0, pint clean; fresh design-review (6 findings resolved) + impl-review APPROVE (3 LOW findings fixed).

### Changed
- **#279** — docs(customization): a custom classifier extending `InboxOnlyClassifier` must implement `DeclaresConsumedEvents` (or the DL-196 event-follows-consumer check false-WARNs its scope). Documentation only; no code change.

## [0.54.0] - 2026-07-14

**Minor — `bridge:check` event-follows-consumer WARN: flags a GitHub event that arrives for a scope no enabled classifier consumes (DL-196).** 1 PR since v0.53.0 (#276). **Diagnostics only — no migration, no new `.env`, no change to what the receiver accepts/rejects, no token-scope change.** The check is always-on and warn-never-fail; it changes only `bridge:check`'s output.

### Added
- **#276** — **`bridge:check` warns when a received GitHub event has no enabled consumer on its scope (DL-196).** Roundtable-#6 follow-up, converged 3-way in roundtable #22 (sola offered the hardening, aimla adopts). Makes "event follows consumer" an enforceable check-time invariant: a webhook subscribed to an event **no enabled classifier consumes** delivers events that are silently dropped, reading as a capability that isn't there. The check reads the bridge's **own `webhook_events` history** (distinct received event types per `github:<scope>`) — **not** the GitHub hooks API. *(Seam correction from the FR's original framing, forced by the design review: `GET /repos/{}/hooks` needs a repo-admin/`Webhooks`-scope token, and the bridge deliberately holds only the DL-185 least-privilege read-only PAT — so a hooks-API check would 403/no-op on every documented install. Observed-event history needs no new token and targets the actionable **arrived-and-dropped** drift, not the harmless subscribed-but-never-fires case; bounded by `bridge:prune` retention → self-heals on next occurrence.)* Per scope it unions `consumedEventTypes()` across **every enabled classifier of every agent** subscribing that scope (multi-agent-on-one-scope is unioned, not one-per-scope) and warns on `observed − consumed`. New `DeclaresConsumedEvents` interface (`consumedEventTypes(ClassifierConfig): array` → top-level event types; **hard docblock contract**: a pure `$cfg`→map with no lazy class-loading / side effects) implemented on `CoordinationClassifier` (union over its enabled families, single-sourced from `HANDLED`) and `GitHubPrCardMoveClassifier` (`{pull_request, push}`, via its already-enumerated writeback classifier — no separate mapping clause, so an orphaned `writeback.json` mapping's inert `pull_request` isn't masked). DL-025-safe: called only on a `probeLoadable`-passed instance out-of-process, wrapped in `catch(Throwable)` → an undeclared/throwing classifier contributes nothing (conservative — a false WARN is possible, a false "clean" is not). Co-emits an "undeclared classifier on this scope" disambiguation line so a false positive is distinguishable from a real orphan. Complements the existing orphaned-`writeback.json`-mapping warn (the same test applied to a subscribed *event* instead of a *mapping*). 726/726 phpunit, phpstan L7 0, pint clean; fresh-adversarial design-review (which caught + corrected the hooks-API seam) + impl-review APPROVE. Coordinated in PupFuzz/agent-roundtable#22.

## [0.53.0] - 2026-07-14

**Minor — a batch of opt-in writeback + classifier additions: comment-`TO:` wake grant (DL-192), PR-draft → block_reason overlay (DL-193), auto-unpark on branch-cut (DL-194), and Won't-Do-revival on reopen (DL-195).** 4 PRs since v0.52.0 (#269–#272) + a doc sharpening (#268). **No migration, no new `.env`, no change to what the receiver accepts/rejects, no token-scope change.** Every feature is **opt-in** and absent-config ⇒ v0.52.0 byte-identical. The three writeback additions are gated per-mapping in `writeback.json`; the classifier addition is gated in `classifier.config`.

### Added
- **#269** — **comment `TO:<self>` GRANTS coord-message live-wake (DL-192).** `CoordinationClassifier`'s `wake_membership` gains an opt-in `comment_to` three-state: a comment whose body `TO:` line names the serving seat grants live-wake even on a thread the seat neither opened nor was labeled on — closing the cross-thread pull-in gap `from_me` doesn't cover (roundtable #17). Off by default (membership unchanged); enable per channel via `classifier.config.wake_membership`. Coordinated in PupFuzz/agent-roundtable#17.
- **#270** — **PR draft → `block_reason` overlay (DL-193).** Opt-in per-mapping **`draft_overlay`** mirrors a PR's **draft** state onto the correlated card's `block_reason` field — overlay ONLY, no stage/column move. `converted_to_draft` / opened-as-draft **SET** the marker `"PR is in draft"` add-if-missing (a human `block_reason` is never stomped); `ready_for_review` **CLEARS** it clear-if-ours. Setting it pins the card (DL-178). Absent/false ⇒ those actions are ignored (byte-identical); not a stage-mapped outcome.
- **#271** — **auto-unpark a pinned card on branch-cut (DL-194).** Opt-in per-mapping **`unpark_from_stages`**: a branch-create `started` event promotes a card from these stages **even if it is pinned** (`block_reason` / `no-automove`) — the DL-178 reversal, scoped to those stages only — and emits a compensating **`writeback_auto_unparked`** override alert (marker-gated by new `hold_marker_tags` / `draft_block_reason`) whenever it overrode a *deliberate* hold. `unpark_from_stages` must be disjoint from `started_from_stages` (fail-closed). Absent ⇒ DL-178 byte-identical. Roundtable #19.
- **#272** — **revive a Won't-Do card when its PR is reopened (DL-195).** Opt-in per-mapping **`revive_on_reopen`**: when a PR the writeback parked in the mapped `closed_unmerged` (Won't-Do) stage is **reopened**, revive its card back to the `opened` (In-Review) stage — the backward move the DL-163 no-regression guard otherwise refuses. Scoped to the abandon stage (terminal-safe: a Shipped/Released card is never there); reuses `stages.opened` as the target (no `stages.reopened` key); overrides a pin with a marker-gated **`writeback_revived_on_reopen`** alert; redelivery-safe. Absent/false ⇒ a `reopened` action stays the `opened` outcome (byte-identical). `bridge:check` warns when enabled without `stages.opened`/`stages.closed_unmerged`. `bridge:reconcile` deliberately does not back-stop revival (no reopen signal in static state). Roundtable #19 sibling.

### Changed
- **#268** — doc sharpening of the v0.52.0 `wake_membership` upgrade note for the PM-seat brief-reply hazard (docs only).

## [0.52.0] - 2026-07-13

**Minor — roundtable-#8 Phase-2: the shared best-of-both impl-agent classifier folds into the tracked `CoordinationClassifier`, so no install forks the bridge on the impl side either (DL-190) + the F-A double-wake replay fix (DL-191).** 5 PRs since v0.51.0 (#261 Phase-2 core + #265 F-A fix + 3 dependency bumps #262/#263/#264). **No migration, no new `.env`, no change to what the receiver accepts/rejects.** Defaults reproduce v0.51.0 byte-for-byte **except `wake_membership`** (a deliberate wide→narrow default flip — see Upgrading). Coordinated in PupFuzz/agent-roundtable#8 (three-way: kanban-solo reference owner, sola + aimla sign-off).

### Added
- **#261** — the Phase-2 impl-agent classifier surface on `CoordinationClassifier`, folding `SolaImplClassifier`'s remaining features as config so no install forks the bridge (DL-190). Three config-gated `classifier.config` knobs (every default reproducing v0.51.0 except the `wake_membership` flip): **`impl_non_wake_disposition`** (`drop` default | `inbox_stage`) — a non-wake terminal impl event either gate-drops (lean inbox) or builds a no-`channel_push` inbox `Intent` (`impl_push`/`impl_ci`) for a broad SessionStart history; **`coord_extra_actions`** (`{prefix:[actions]}`) — extends the fail-safe `subject()` action **allow-list** per event prefix (e.g. `{pull_request:[synchronize]}`), never a deny-list (a new GitHub action never auto-surfaces); **`wake_membership`** (`[to_me, to_all]` default | `+from_me` opt-in) — config-drives which label classes grant `coord-message` live-wake. Plus the **`github_user_id`-never-on-wake-identity invariant** (docblock + regression test): a wake identity is the author agent by NAME via `scope_author_map`, never the raw pusher github id, so the pre-classify echo gate can't drop an agent's own-repo landing. New typed `ClassifierConfig::stringListMap()`; shared `makeImplIntent()` + `pushInboxSignal()`/`workflowRunInboxSignal()`.

### Changed
- **#265** — channel-aware wake-emit: every `CoordinationClassifier` family emits its `channel_push` only when the serving channel is `route_intents:false`; on a `route_intents:true` channel the dispatcher already routes every staged intent (DL-006), so a hand-emit double-wakes (DL-191). One `wakePush()` helper, all three families (coord-message, impl-ci-wake, kanban-triage). Fixes the double-wake sola's Phase-2 replay surfaced on her `route_intents:true` profile (two `channel_push` per addressed coord message → one). **Supersedes the interim `impl_wake_emit` knob** introduced by #261 — the suppression is now derived structurally from `route_intents` (all families, no knob), so `impl_wake_emit` is removed (it was never released; a lingering value in a config is ignored, no `ConfigException`). `route_intents:false` installs are byte-identical; only `route_intents:true` behavior changes (one wake, was two). Verified against the full `DispatchService` route_intents merge (independent second-consumer replay + an in-repo composed dispatch guard).
- **#261** — `wake_membership` narrow default (DL-190): the `coord-message` live-wake gate defaults to `[to_me, to_all]` (was effectively wide, including `from_me`). Because a comment's direction lives in its body `TO:` line — which only *narrows* within membership, never grants — dropping `from_me` shifts even a **directed** reply on a thread you opened from live-wake to SessionStart pickup, backstopped by the `from:<self>`/`protocol:invalid` SessionStart scan. Over-wake is the guarded failure mode. See Upgrading to restore the prior wide behavior.

### Dependencies
- **#262** — `laravel/framework` 13.18.1 → 13.19.0.
- **#263** — `PupFuzz/agent-board-toolkit/promote` composite-action SHA pin (v0.11.0 → v0.12.1).
- **#264** — `phpunit/phpunit` (dev) 13.2.2 → 13.2.4.

### Behavior notes (roundtable #8, F-B/F-C — no config change)
- **Code-repo `pull_request` / `issues` / `issue_comment` are dropped** under the unified model (they route through `coord-message`'s recipient gate, and a code-repo object carries no `to:`/`from:` addressing labels → dropped; `impl-ci-wake` only handles `push`/`workflow_run`). The unified framing is *"code repos = push/CI signals; coordination = the coordination repo."* The only real loss is non-self / dependabot code-repo activity, which has its own kanban-card path. An install that needs code-repo PR/issue/comment passthrough should scope that as a separate reference feature.
- **A landless branch-delete `push`** (`deleted:true`, no release-branch ref) is dropped — benign noise reduction.

### Upgrading
- **`wake_membership` default flipped wide→narrow (DL-190) — a PM/coordinator seat must opt back into `from_me`.** Pre-v0.52.0 the coord-message wake gate hardcoded a `from:<self>` clause, so any zero-config seat woke on every reply to a thread it opened; the v0.52.0 default `[to_me, to_all]` drops it. **If your seat opens briefs addressed `to:<someone-else>` (the classic PM shape — briefs labeled `to:<impl>`, never `to:<self>`) and must live-wake on the replies to them, restoring `from_me` is not optional** — set `classifier.config.wake_membership: [to_me, to_all, from_me]` per channel, or those brief-reply wakes silently downgrade to **SessionStart-only** pickup (still backstopped by the `from:<self>`/`protocol:invalid` scan, but no longer live). This is a general upgrade hazard for **any** pre-v0.52.0 install that leaned on the implicit `from:<self>` wake, not just multi-agent PMs. A seat that only needs to wake when explicitly addressed (`to:<self>` / `to:all`) needs no action. (Cross-thread pull-ins — a comment adding `TO: <self>` on a thread you neither opened nor were labeled on — are a **separate** gap `from_me` does not close; tracked as the `comment_to` grant in PupFuzz/agent-roundtable#17.)
- **`impl_wake_emit` removed (DL-191).** It was introduced on `dev` by DL-190 and retired in the same release cycle before shipping — no released version ever exposed it. If a config carries `impl_wake_emit`, it is silently ignored (no error); remove it as cleanup. The equivalent single-wake behavior on a `route_intents:true` channel is now automatic.

## [0.51.0] - 2026-07-12

**Minor — roundtable-#8 R1: two config-gated `CoordinationClassifier` knobs (`impl_repos` wake gate + `drop_title_all_of` coord noise filter), plus the v0.50.0 upgrade note reaches `main` (DL-189).** 2 PRs since v0.50.0 (#258 R1 knobs + #257 upgrade-note docs). **No migration, no new `.env`, no change to what the receiver accepts/rejects.** Both knobs default-empty ⇒ v0.50.0 behavior byte-for-byte; adopt per-install via `classifier.config`.

### Added
- **#258** — two optional, config-gated knobs on `CoordinationClassifier` (DL-189). **`impl_repos`** (list<string>, `impl-ci-wake` family): gate the impl CI/push wake to a repo subset — the family fires only when the event's `scope_id` (lowercased) is in the list; empty/absent ⇒ every subscribed repo (back-compat). Lets a PM subscribed to both a coord repo and impl repos scope the wake to its impl subset so a coord-repo push/CI event doesn't self-wake; gates **before** `pushSignal`/`workflowRunSignal`, so the push-landing predicate + fail-loud conclusion logic are unchanged. **`drop_title_all_of`** (list<list<string>>, `coord-message` family): drop a coordination subject whose **title** contains every (case-insensitive) substring of any group — AND within a group, OR across groups — before the recipient gate, for bookkeeping-title noise (e.g. `[["Rule E back-merge sync","paper-trail anchor"]]`); for an `issue_comment` the title is the parent issue's, so a match also suppresses comments on that issue (intended for a pure paper-trail anchor — keep groups specific). New typed `ClassifierConfig::stringGroups()` accessor (fail-closed). Defaults reproduce v0.50.0; DL-007 shared-account echo posture untouched. 622/622 phpunit, phpstan L7 0, pint clean; fresh-adversarial impl-review APPROVE. Coordinated in PupFuzz/agent-roundtable#8.

### Changed
- **#258** — corrected the `implCiWakeFamily` doc-comments (DL-189): a non-wake impl event is **gate-dropped** (no intent), not "staged to inbox by the dispatcher" — the dispatcher inbox-stages classifier *intents* only, and a null family + empty InboxOnly base yields no intent.
- **#257** — the **v0.50.0 upgrade note** reaches `main` (DL-188): retire the untracked `CoordinationClassifier` overlay **before** `git pull` (v0.50.0 ships that file tracked, so the pull otherwise refuses on the untracked working-tree file). The note landed on `dev` only after the v0.50.0 tag was cut, so this release brings it into the release branch.

## [0.50.0] - 2026-07-11

**Minor — roundtable-#8 classifier unification: one config-driven `CoordinationClassifier` with config-gated event families, so no install forks the bridge (DL-188).** 2 PRs since v0.49.0 (#252 core + #253 kanban-triage fold). **No migration, no new `.env`, no change to what the receiver accepts/rejects.** Requires kanban **v0.22.0+** for the `card` snapshot the `kanban-triage` family reads (degrades to over-wake on older).

### Added
- **#252** — a unified, config-driven `CoordinationClassifier` + a typed `ClassifierConfig` view over the `classifier.config` YAML block (DL-188, core). One reference classifier runs a **config-gated event-family pipeline** (`classifier.config.families`) so a coordination repo, a cross-project channel, and an impl code repo all route through the same code parameterized by config — no install forks the bridge PHP. Families: `coord-message` (GitHub issues/issue_comment/pull_request coordination messages, DL-022 addressing + DL-002 shared-identity re-attribution) and `impl-ci-wake` (push→release-branch = release-landed with `subjectId = head_sha`; `workflow_run` **fail-LOUD** CI gate — wakes on ANY terminal conclusion NOT in `benign_conclusions` [`success`/`cancelled`/`skipped`/`neutral`], so a new/unknown GitHub conclusion surfaces instead of silently escaping CI oversight; a name-matched provenance-success also wakes). Default families `[coord-message]` ⇒ an install that doesn't opt in behaves **exactly** as the pre-#8 reference (back-compat). `ClassifierConfig` exposes `scope_author_map`, `families`, and generic typed accessors (`strings()`/`string()`/`section()`) so a family adds knobs without another contract change.

### Changed
- **#253** — folded the DL-168 triage-wake into the unified classifier as the config-gated **`kanban-triage`** family (DL-188). `CoordinationClassifier` now **`extends InboxOnlyClassifier`** (was `implements Classifier`) so `parent::classify()` provides base inbox-staging for kanban `task.*` events; the `kanban-triage` family pairs the `new_card` Intent for a **human-filed, untriaged** `task.created` (no `triaged`/`id:pr:*` tag, no `dl` ref — read off the DL-164 `card` snapshot, **no API call, no read token**) with a `channel_push` to the triage owner. `KanbanTriageClassifier` is now a thin **back-compat shim** (`extends CoordinationClassifier`, defaults its family set to `[kanban-triage]`) — the v0.42.0 `classifier.class: …\KanbanTriageClassifier` target keeps working, with a single implementation of the untriaged check. The two GitHub families self-guard `provider === 'github'` (the pre-#8 top-level provider guard is removed). 609/609 phpunit, phpstan L7 0, pint clean; one fresh-adversarial impl-review pass (APPROVE). `KanbanTriageClassifierTest` unchanged and green proves shim behavior-equivalence.

### Upgrading — retire an untracked `CoordinationClassifier` overlay BEFORE pulling
If your install runs an **untracked `app/Bridge/Classifiers/CoordinationClassifier.php` overlay** (the pre-#8 roundtable classifier that installs vendored by hand), **retire it before pulling v0.50.0** — this release ships `CoordinationClassifier.php` **tracked**, so `git pull`/`checkout` refuses with `error: The following untracked working tree files would be overwritten by checkout` (a *safe* fail — it never silently clobbers your overlay — but it **blocks the pull**). Steps:
```
cp app/Bridge/Classifiers/CoordinationClassifier.php ~/coord-classifier.overlay.bak   # rollback copy
rm app/Bridge/Classifiers/CoordinationClassifier.php
git pull            # v0.50.0 lands the tracked CoordinationClassifier + ClassifierConfig
composer dump-autoload -o                                                             # pick up the new ClassifierConfig class
php artisan optimize:clear   # (+ optimize on a serving install)
php artisan bridge:check     # confirms every agent's classifier still loads
```
The tracked `CoordinationClassifier` with its **default families `[coord-message]`** reproduces a vanilla pre-#8 overlay **unchanged** — no `classifier.config` needed for coord-only behavior. Opt into the other families with `classifier.config.families: [impl-ci-wake]` / `[kanban-triage]`. If your overlay carried project-specifics (a `scope_author_map`, a custom handler), port them into `classifier.config` (map/families) or a thin subclass — **not** back into a re-vendored overlay. `KanbanTriageClassifier` remains a valid `classifier.class` target (now a thin shim). No migration, no `.env` change.

## [0.49.0] - 2026-07-10

**Minor — the writeback stamps a `card#` fallback card's `dl_number`/`pr_number` add-if-missing, so it correlates for release-promote instead of stranding (DL-187).** 2 PRs since v0.48.1 (#248 app code + #246 docs). **No migration, no new `.env`, no change to what the receiver accepts/rejects, no writeback-token scope bump.**

### Added
- **#248** — the GitHub-PR/branch writeback now **stamps a moved card's correlation refs** (`payload.dl_number`/`pr_number`) **add-if-missing** (DL-187), as a step distinct from the column-only move. A card moved via the `card#<id>` native-id fallback (DL-177/179) carried neither ref — the exact keys `promote-released-cards` correlates released cards by — so it stranded in Shipped-to-Dev at release time (seen live on cards 3792/3841/3858; the pre-fix workaround was `kbcard --dl` at decision-time). Scoped tightly per a fresh-adversarial design review: **only the `card#` fallback path stamps** (a DL-resolved card already carries the `dl_number` that resolved it, and stamping `pr_number` there could poison a feature card from a release PR naming its DL); the `dl_number` is stamped **only when exactly one `DL-NNN`** appears in the title+branch (a bundled/release-shaped PR with 2+ DLs, or a foreign DL, stamps `pr_number` only — never a wrong DL), stored canonical zero-padded (`DL-%04d`); **never overwrites** an existing/human value; placed **after** the reject-guards and in the already-in-stage self-heal branch (a stale/regressive event never stamps); best-effort with the move's transient/permanent split (a 4xx like "board has no such field" is logged + skipped; a 5xx **propagates** so redelivery re-stamps — closing the window where a swallowed transient failure would strand the card forever). Relies on the kanban per-key `task.payload` merge (kanban #2180) — a delta PATCH, no read-merge-write; `KanbanClient::moveCard` stays column-only, the new `stampCorrelationRefs` is the distinct write verb. The backstop (a `promote-released-cards` WARN on a released DL with no card) was already present. New `titleAndHead` primitive collapses the duplicated title+branch extraction (`dlToken`/`cardToken` refactored onto it). 13 new tests; one design-review + one impl-review pass. Card #3866.

### Changed
- **#246** — synced the solo-agent orientation doc (`CLAUDE_AGENTBOARD.md`) across the repo to coord v0.2.253 (finish-to-next self-drive guidance). Docs only.

## [0.48.1] - 2026-07-10

**Patch — `bridge:reconcile` token diagnosability (DL-186): name the resolved leg + preflight validity probe + upgrade-shadow docs.** 1 PR since v0.48.0 (#244), from two peer integrators (AIMLA + Sola PMs) who both hit the same upgrade-shadow. **No precedence behavior change; no migration, no new `.env`, no change to what the receiver accepts/rejects.**

### Added
- **#3858** — `bridge:reconcile` token diagnosability (DL-186), from two peer integrators (AIMLA + Sola PMs) who both hit the same upgrade-shadow. **No precedence behavior change.** The per-repo auth-probe failure now **names the resolved leg** (`… HTTP 401 (token expired/revoked) (token from token file /path)`) so a multi-leg resolver failure points at *which* source won; `bridge:reconcile -v` prints the leg per repo on success too. `bridge:check` now probes the resolved reconcile token's **validity** against each mapped repo (warn-never-fail, DL-026) — a resolved-but-expired token (classically a stale `<secret_dir>/github/token` from the single-token era **shadowing** the store map) surfaces at preflight naming the leg, instead of a silent 401 on the first run. Docs: an **UPGRADING** callout in `docs/writeback.md` (a pre-existing token file short-circuits the `[git-credential-map]` store — remove it to adopt per-repo tokens) + a **Running reconcile unattended** worked example (cron/systemd `HOME`/`PATH`/`COORD_CREDENTIALS` for the helper, `--max-moves` circuit-breaker).

## [0.48.0] - 2026-07-10

**Minor — `bridge:reconcile` GitHub token: configurable path + `GH_TOKEN`, then store-native per-repo resolution.** 2 PRs since v0.47.0 (#240, #241). **App code — no migration, no new required `.env`, no change to what the receiver accepts/rejects.** Extends the DL-183 reconcile command's token resolution so an install can reuse a centralized credential and, by default, resolve a per-repo least-privilege PAT from the coordination store — with no second token copy to rotate.

### Added
- **#240** — `bridge:reconcile` GitHub token resolves from `bridge.providers.github.token_path` (env `BRIDGE_GITHUB_TOKEN_PATH`, authoritative when set) → `<secret_dir>/github/token` → an ambient `GH_TOKEN` fallback (DL-184). Lets an install reuse a centralized credential (e.g. `~/.config/coord/github-pat`) without a per-install symlink; the env fallback self-scopes to the CLI (absent in the webhook-spawned receiver). File sources keep the DL-010 perms gate; an explicit-but-missing `token_path` fails loud rather than silently using a different credential.
- **#241** — **store-native per-repo token resolution** (DL-185): when no explicit token file is placed, `bridge:reconcile` resolves a **per-repo** least-privilege PAT from the coordination store via `git-credential-coord` + `[git-credential-map]` (host/owner/repo, most-specific-first), inserted as the default leg **before** `GH_TOKEN`. New `GitHubTokenResolver` (the single home of the token precedence) + `TokenResolution`; `GitHubReadClient` is now token-agnostic and built **per repo**; `bridge:check` routes its token check through the same resolver (warn-never-fail, DL-026). New config `bridge.providers.github.credential_helper` (env `BRIDGE_GITHUB_CREDENTIAL_HELPER`, default `git-credential-coord`; empty disables the leg). **Guardrails** (framework contract, coord `docs/CREDENTIALS.md § Non-git consumers`): `GH_TOKEN` is consulted only *after* the store returns nothing → never shadows a store-mapped token; a `REPLACE_ME` placeholder, an unreadable `*_file` (helper writes stderr, exits 0, emits no `password=`), or a helper crash **fail loud** — never a silent fall-through to a wrong-scoped token. A missing helper falls through to `GH_TOKEN` (pre-resolved via `ExecutableFinder` — a missing binary returns exit 127, not a start-exception, so a `GH_TOKEN`-only install keeps working). Back-compat: an install without a `[git-credential-map]`/helper resolves exactly as DL-184. **Owed to framework dev post-release:** the final DL-184/185 token/selector shape for `docs/TOKENS.md #5` (reconcile makes the GitHub token a *runtime* dependency) + the installed helper's missing `REPLACE_ME` guard (doc/helper discrepancy).

## [0.47.0] - 2026-07-10

**Minor — `bridge:reconcile`: board-vs-GitHub drift reconciler (closes RC-B).** 1 PR since v0.46.1 (#237, DL-183). **App code — no migration, no new `.env`, no change to what the receiver accepts/rejects.** A new operator command that re-derives each tracked card's correct column from GitHub PR truth — **report-only by default; `--fix` applies the forward moves** (cap-guarded by `--max-moves`). The systematic, re-runnable fix for the out-of-band / bundled-release stranding class (RC-B) that per-PR writeback can't catch.

### Added
- **#237** — `bridge:reconcile` (DL-183): reconciles board card state against GitHub PR outcomes. **Report-only by default; `--fix` applies forward moves.** New `ReconcileCommand` + `GitHubReadClient`, with `PrOutcome`/`PinGuard` primitives extracted pure (behavior-identical) from the classifier/handler. Guards: a startup per-repo auth probe (loud + non-zero exit on a token that can't read a repo); never-backward (DL-163); pinned-card respect (DL-178); `released_to_main` terminal (keyed on the `merged_to_main` outcome); truncated-read abort; `--max-moves` cap; unorderable→non-zero exit; per-card 404 benign / 401-403 systemic. `bridge:check` now warns on a missing GitHub read token.

### Notes
- v1 scope (documented, deliberate): dl-only cards, bare-`pr_number`-on-shared-board, the branch-create `started` outcome (no PR to GET), and the `closed_unmerged` abandoned-PR *backward* regression are out of scope (the last is report-only, not auto-applied).

**Patch — card-movement writeback reliability: promote via a SHA-pinned toolkit action + a PR-title correlation lint.** 2 net PRs since v0.46.0 (#232, #233). **CI/tooling only — no app code, no migration, no new `.env`, no change to what the receiver accepts/rejects.** Bridge mirror of kanban-board v0.29.1.

### Added
- **#233** — `.github/workflows/pr-title-lint.yml` (DL-182). A PR whose head branch follows the `<type>/<card-id>-slug` convention must carry a `card#<id>` or `DL-NNNN` token in its title — the only correlation key the GitHub-PR→card-move writeback has. Converts a silently-unmoved card into a loud red check. Injection-safe (event payload reaches the script via `env:` only, never inline `${{ }}`), token bounded on both sides, `shell: bash` pinned, `ubuntu-latest` runner. Not a required status check (teeth = the all-workflows-green merge rule). Mirrored in kanban-board (DL-197).

### Changed
- **#232** — `.github/workflows/release-promote-cards.yml` now consumes `promote-released-cards` as the SHA-pinned `agent-board-toolkit` composite action (`uses: PupFuzz/agent-board-toolkit/promote@<sha>  # v0.11.0`) instead of a vendored copy (DL-181, correcting DL-180). Drift becomes structurally impossible, presence is guaranteed, and dependabot's github-actions ecosystem bumps the pin. Retires the vendored `bin/promote-released-cards`, the `.agent-board-toolkit-version` stamp, and the `toolkit-drift.yml` gate that #231 (DL-180) had added earlier in this cycle — net effect on `main` is the composite-action consumption with no vendored script. Mirrored in kanban-board (DL-196).

## [0.46.0] - 2026-07-09

**Card-automation batch — the classifier now correlates a `card#` token when a DL is unresolved, plus the Held-automove pinned opt-out and a promote-tooling resilience sync.** PRs #226–#228. **App code — no migration, no new `.env`, no change to what the receiver accepts/rejects.** ⚠ **Operator: this release must be redeployed to the prod bridge (git pull → pip install → migrate → restart) for the card# fallthrough to take effect** — a tag alone does not deploy a running server.

### Changed
- **#227** — classifier resolves card-first tokens **try-in-order-with-fallback** (DL-179, framework #112). `GitHubPrCardMoveClassifier` previously committed to the `DL-NNN` path on a token's *presence*: `correlateDl()` returning `[]` was a silent no-op even with a co-present `card#<id>`, so a decision-logged-but-unstamped card could never be rescued. Now resolves on *outcome*, on both the `pull_request` and branch-create `push` paths: a resolving DL wins (co-present `card#` logged as ignored); a DL that resolves to no card **falls through** to a present `card#`; a token present but resolving to nothing is warned loudly (never a silent no-op). The `card#` fallback stays board-scoped via `KanbanMoveCardHandler`'s existing board-membership guard. Board card #3727.
- **#226** — `KanbanMoveCardHandler` refuses a `started` promotion for a **pinned** card (DL-178, framework #113 Held-automove contract). A card with a non-empty `block_reason` or a `no-automove` tag is never auto-promoted to In Progress on a branch-create `started` event. Board card #3744.

### Fixed
- **#228** — `promote-released-cards` retries transient 5xx to ride the deploy maintenance window (re-sync to the toolkit canonical, card #3700). The merge-to-main promote job races the operator deploy's maintenance mode (HTTP 503); `curl --retry` now backs off on transient errors only (5xx/timeout/conn-refused, never 4xx), bounded by `--retry-max-time`. Byte-identical to the toolkit + kanban copies.

### Changed (dependencies)
- **#210** — `laravel/framework` 13.16.1 → 13.18.1. **#207** — `symfony/yaml` 8.1.0 → 8.1.1. **#211** — `phpunit/phpunit` 13.2.1 → 13.2.2 (dev). **#205** — `laravel/pail` 1.1.1 → 1.1.2 (dev).

## [0.45.0] - 2026-07-08

### Fixed
- **#220** — repo-qualified correlation only on SHARED boards (DL-174). The `ref`-mode `source` qualifier was passed unconditionally; kanban's strict source filter therefore excluded every card whose derived refs carry `source=null` (any operator-stamped `dl_number`/`pr_number` card), silently killing all organic DL-correlated moves on 1:1 boards since DL-167. Qualify only when 2+ repo mappings share the board; the #3399 `bridge:check` null-source warn is now shared-board-scoped (it is a false alarm on 1:1 boards post-fix). Shared-board (multi-repo adopter) installs byte-identical. Board card #3692.

### Security
- **#218** — infra hostname moved out of the repo into the `KANBAN_API_BASE` repo variable + docs scrubbed (pre-session batch).
- **#219** — `promote-released-cards` re-synced to the guarded toolkit canonical (closes the #3570 exposure; pre-session batch).
- **#221** — secret-bearing kanban `api_base_url` requires https (DL-175). New `UrlValidator::secureHttpUrl` (cleartext http only to loopback hosts) applied fail-closed at `WritebackClientFactory`, `bridge:provision`, and `bridge:check`. Board card #3574.
- **#222** — GitHub dedup key derives from the SIGNED body, not the unsigned `X-GitHub-Delivery` header (DL-176). A captured validly-signed delivery resent with a fresh header no longer re-dispatches; `delivery_id = sha256(raw body)`. Operator "Redeliver" now dedups — `bridge:replay <id>` is the sanctioned reprocess path. Board card #3573.

### Added
- **#223** — `card#<task-id>` correlation channel (DL-177, framework FR-7 v0.2.229). PRs whose title/head branch carries `card#<id>` move that card by native task-id — the channel for cards with no DL number. `DL-NNN` wins when both tokens appear (ignored `card#` logged). Board card #3652.

### Changed
- **#217** — `VERSIONING.md` reconciled with actual bump-sizing + auto-tag practice (docs only).

## [0.44.1] - 2026-07-06

**Shared-code consolidation: three drifting kanban/bridge duplications collapsed to single sources (DUP audit cards #3442–#3445, #3576), a multi-card writeback stage-order memoization (#3575), and a latent dependabot repo-attribution case-sensitivity fix.** PRs #208, #209, #212, #213, #214. **App code — no migration, no new `.env`, no change to what the receiver accepts/rejects.**

### Changed

- **Vendored kanban's `ExternalReferenceNormalizer` as the single external-ref canonicalization authority (#209, card #3442).** Replaces the bridge's three drifting hand-written copies with one mirror at `app/Bridge/Support/ExternalReferenceNormalizer.php`; `KanbanDependabotCardHandler::cardRepo()` derives the card's `owner/repo` via the normalizer and `cardsForRepo()` compares against the canonicalized event repo. Behavior-preserving except the fix below.
- **Extracted shared `ChannelPushTransport` (#212, card #3576).** `ChannelPushHandler` and `WritebackAlertNotifier` each independently reimplemented the UNIX-socket / loopback-URL `Http` push; the second real caller now exists, so per canon #5 the primitive is extracted. Completes the shared-transport extract the v0.43.0 changelog flagged. Behavior-preserving.
- **Shared loopback-endpoint validator + kanban HTTP client builder (#208, cards #3443/#3444)**, plus a webhook event-vocabulary drift/contract test (#3445). Behavior-preserving.
- **Writeback: memoized the board stage-order preload across a multi-card move (#213, card #3575).** A bundled PR/DL correlating to N cards on one board previously issued up to N serial `/boards/{id}/preload.json` reads inside a single synchronous webhook request (the no-regression `isRegressiveMove` guard fetched per card). Now memoized per board via `$this->stageOrderMemo[$boardId] ??= $client->boardStageOrder($boardId)` — **order array only**; the client/token (`WritebackClientFactory::make`) and `getCard` stay per-card. Per-card fail-open preserved: `??=` + `->throw()` leaves the key unset on a transient preload failure so a later card retries; a valid empty order is cached (fail-open allow, matching pre-change).

### Fixed

- **Dependabot repo attribution is now case-insensitive (#209).** Surfaced by the #209 consolidation: the previous exact-string match in `cardRepo()` dropped a card whose stored `pr_url` differed only in case from the event repo — GitHub `owner/repo` is case-insensitive, matching the kanban `source` semantics. Pinned by `test_repo_attribution_is_case_insensitive`.

### Docs

- Agent Board Framework solo orientation (#214).

## [0.44.0] - 2026-06-29

**`bridge:check` warns on a `dl_number` card with `source=null` on a ref-mode writeback board (#3399, DL-173).** PR #202. **App code — no migration, no new `.env`, no change to what the receiver accepts/rejects.**

### Added

- A new `bridge:check` diagnostic (warn-never-fail) closing the one remaining *silent* writeback failure. In `ref` correlation mode the by-ref lookup is repo-qualified (`source`, which the kanban derives from a card's `payload.repo`/`pr_url`/`issue_url`/`html_url`/`external_link`), so a `dl_number` card with no source-yielding field is excluded by the lookup and **silently never self-moves** — indistinguishable from a legitimate no-match in the dispatch ledger. The check (when `correlation=ref`) enumerates each mapped board's `dl_number` cards and warns on any whose derived source is null or matches no repo mapped to that board, naming the card id + DL. Applies to single-repo boards too (correlation passes the repo unconditionally). Complements the tool-side fix (`kbcard --pr-url`, toolkit v0.8.0). New `KanbanClient::readBoardCards` (public diagnostic paged read, surfaces a `truncated` flag so an incomplete read can't give a false all-clear); `derivedSource` faithfully mirrors the kanban's `ExternalReferenceNormalizer::sourceFor()` (kept in sync). From AIMLA PM.

## [0.43.3] - 2026-06-29

**`alert_channel.socket` now gets the DL-039 `${XDG_RUNTIME_DIR}`/`${uid}` expansion `channel.socket` has (FR-A).** PR #199. **App code — no migration, no new `.env`, no change to what the receiver accepts/rejects.**

### Fixed

- FR-4's `alert_channel.socket` (DL-171, v0.43.0) did not inherit the DL-039 uid-agnostic expansion, so a `${XDG_RUNTIME_DIR}/…` value was rejected by `SocketPath::isValid` and the alert silently degraded to log-only on a host/uid move — the exact brittleness DL-039 removed, re-present on the alert path. `WritebackConfig::parseAlertChannel` now applies `PathHelper::expandRuntimeTokens()` at load (single-point: the resolved path flows to both the runtime push and `bridge:check`). **Fail-open preserved (DL-171):** the `ConfigException` the expansion throws on an unresolvable/typo'd token is caught and the unexpanded value kept — `bridge:check` warns, the push is caught (log-only) — rather than failing the whole writeback closed like `channel.socket` does. `${uid}` supported for free. Existing literal-absolute-path configs are byte-identical. From AIMLA PM.

## [0.43.2] - 2026-06-28

**`bin/promote-released-cards`: fix a `jq` argv-overflow on large boards (#3091 class).** PR #196. **Release-tooling/CI only — no app code, schema, migration, `.env`, or receiver change.**

### Fixed

- The paged-board accumulation passed the page JSON to `jq` via `--argjson` (a command-line argument). When a board's API page exceeds `MAX_ARG_STRLEN` (~128 KB) the job died `jq: Argument list too long` (exit 126). Board 8 (57 cards) is under the cap today, but board 5 (98 cards / 142 KB) hit it — the same argv-overflow class fixed in `kbcard` (#3091). Accumulation now goes through **stdin** (`printf | jq -s 'add'`). Re-syncs the shared script to the toolkit canonical. Validated on the live board.

## [0.43.1] - 2026-06-28

**`bin/promote-released-cards`: the "0 cards promoted" exit is now CAUSE-AWARE (squash-fail vs. cardless-warn).** PR #193, DL-172 (a correction to DL-165). **Release-tooling/CI only — no app code, schema, migration, `.env`, or receiver accept/reject change.**

### Changed

- The post-merge `release-promote-cards` job no longer blanket-fails when it derives shipped refs but promotes 0 cards (DL-165). It distinguishes the two root causes by the **release tip's parent count**: a MERGE-commit tip (≥2 parents) — or explicit `--dls` — means ref-derivation was complete, so 0-promoted is a **legitimately cardless** release → **WARN, exit 0**; a non-merge tip means the release PR was squash/rebase-merged and may have dropped the per-PR refs (the v0.32.0 silent-green class) → **FAIL, exit 2**. New `merge_commit_tip` helper. `skipped` is intentionally out of the fail guard so a squash with pre-promoted siblings still fails (the idempotent merge-commit re-run is exempted by the tip check).
- Re-syncs `bin/promote-released-cards` to the shared canonical (`agent-board-toolkit`): all three consumers (toolkit, bridge, kanban-board) are now byte-identical, also picking up the `PROMOTE_PAGE_CAP` loud-on-cap pagination. GitHub can't enforce a per-PR merge method, so detection stays the guard (paired with the merge-commit-for-release-PRs convention). Adversarial review caught + fixed a `skipped>0` hole; validated on the live board across the full matrix.

## [0.43.0] - 2026-06-28

**Optional `writeback.alert_channel` — a loud, deduped, best-effort live signal on a permanent writeback move-failure (FR-4, DL-171).** PR #190. App code — **no migration, no new `.env`, no change to what the receiver accepts/rejects**. Opt-in (absent `alert_channel` ⇒ unchanged log-only behavior).

### Added

- **`writeback.alert_channel` in `writeback.json`** — when set, the 5 `Log::warning` *permanent* move-failure branches of `KanbanMoveCardHandler` (`card_id_not_int`, `repo_or_outcome_invalid`, `writeback_not_configured`, `getcard_4xx`, `card_not_on_mapped_board`) *also* push a one-line `{"type":"writeback_move_failed", repo, outcome, card_id, reason}` to the channel — **in addition to** the log (log = durable record, push = live wake). Closes the gap where a real misconfiguration (rotated/blind token → `getcard_4xx`, drifted board_id → `card_not_on_mapped_board`) was only visible by tailing logs. Shape mirrors the agent `channel`: `{"socket": "/abs"}` XOR `{"url": "http://127.0.0.1:PORT/", "auth": {"token_path": "/abs"}}`.
- `bridge:check` now validates `alert_channel` — **warns, never fails** (both/neither socket+url, missing socket parent dir, non-loopback url).

### Notes

- **Quiet by design:** the 2 `Log::info` "not tracked" branches (no mapping for repo / no stage for outcome — the *normal* unmapped-event case) and the fail-open no-regression guard stay silent; the loud/quiet line is the existing warning-vs-info split. The permanent path is **not** converted to throw — no retry-storm.
- **Best-effort, structurally non-throwing:** the entire notify body (config load, dedup claim, push) is wrapped in one `try/catch (\Throwable)` — a 5xx storm of an unmovable card is the one thing FR-4 forbids. Dedup is an atomic `O_EXCL` marker per `sha1(repo, outcome, reason)`; a failed push releases the marker so a redelivery re-attempts (a forever-silenced signature is worse than a rare double-alert). Reuses the existing channel-push transport — **no new socket/listener/timer/poll**.
- **Branch `writeback_not_configured` degrades to log-only by construction** (no `writeback.json` ⇒ no `alert_channel` to load). `ChannelPushHandler` (security-critical) was deliberately **not** modified — the notifier does its own validated send; the socket/url validation is intentionally duplicated, flagged for a future shared extract.
- Two adversarial review passes (caught + fixed a critical `mkdir` `E_WARNING`→`ErrorException` rethrow that could 5xx-storm, and a failed-first-push permanent-silence). pint clean; phpstan level 7 zero errors; phpunit 486/486 (16 new).

## [0.42.3] - 2026-06-27

**Dependency bumps.** PRs #180, #181. **No app code, schema, migration, or `.env`; no behavior change.**

### Changed

- `actions/checkout` 6.0.3 → 7.0.0 across the CI workflows (#180). CI runs on github-hosted `ubuntu-latest`, so the v7 Node-24 runtime requirement is satisfied with no runner change.
- `laravel/pint` (dev) 1.29.1 → 1.29.3 (#181). Code style unchanged (CI `pint --test` green).

## [0.42.2] - 2026-06-27

**Docs: MCP-channel + upstream-reconcile is the recommended PM consumption model; `bridge:inbox` is the fallback (DL-170).** PR #185. **Docs only — no app code, classifier, schema, migration, or `.env`; no behavior change.**

### Changed

- **`docs/consumer-guide.md` § Consumption patterns — canonized the PM consumption standard (DL-170).** For a live, upstream-anchored agent (a PM): **MCP channel push** for the event-based live wake + **reconcile-from-source-of-truth** (GitHub via `gh`, kanban via API) for recovery — `SessionStart` = full-dump, mid-session = a **light delta** (only new-since-watermark, silent-when-empty, throttled, sub-second; the full reconciler is ~40s and would stall an inline tool call). The bridge `inbox` is repositioned as the **fallback** — for consumers with bridge-only intents or that can't reconcile from an upstream. Validated by two independent PM implementations (Sola + AIMLA), both of which confirmed zero bridge-only intents (so the inbox is redundant for them) and built the same SessionStart-full + mid-session-delta design. DL-169's `bridge:inbox` PreToolUse guidance stays valid for the fallback path; DL-170 sets the primary recommendation above it. The open convergence item — upstreaming the GitHub+kanban delta reconcile into the shared consumer-side framework — is consumer-side, recorded for the framework maintainer.

## [0.42.1] - 2026-06-27

**Docs: stress `PreToolUse` as the required mid-session `bridge:inbox` trigger (DL-169).** PR #182. **Docs + example only — no app code, classifier, schema, migration, or `.env`; no behavior change.** Prompted by a peer-integrator (Sola PM) "silently lost events" report that diagnosed to **consume-side hook wiring, not bridge code**.

### Changed

- **Hook-wiring guidance made complete (DL-169).** The live `channel_push` is best-effort by design (DL-001); the durable inbox is the recovery road, but it only surfaces when a hook fires `bridge:inbox`, and `SessionStart` fires once per session — so a long-lived session wired on `SessionStart` only never re-checks the inbox mid-session, and an intent that arrives during a long turn sits unseen until restart (tell-tale: a `inbox-seen-<agent>.json` cursor stale for days). `docs/consumer-guide.md § Wiring` now carries a "both legs are load-bearing" callout (`SessionStart` = boundary recovery; `PreToolUse` = the recommended per-tool-call mid-session trigger, `PostToolUse` equivalent) and recommends `"matcher": ""` over `"Bash"` (a narrow matcher reopens the gap on non-matching work), with the subprocess-per-tool tradeoff stated; plus a troubleshooting entry keyed on the observable symptom. `examples/claude-code/settings.json.example`: `PreToolUse` matcher `"Bash"`→`""` + a `_wiring_note`.
- **Fixed a pre-existing doc bug** in the same section: it claimed wiring on `Stop` *advances* the seen cursor — it does **not** (`Stop` ∉ `ADDITIONAL_CONTEXT_EVENTS` ⇒ `InboxCommand` leaves the intents unseen), contradicting the code and the correct statement earlier in the same doc.

## [0.42.0] - 2026-06-20

**Triage-wake classifier — a human-filed, untriaged card wakes the triage-owner session in near-real-time (DL-168).** PR #176. App code — **no migration, no config schema change, no change to what the receiver accepts/rejects.** Opt-in (OFF until an agent sets `classifier.class`). Requires kanban **v0.22.0+** for the `card` snapshot (degrades to over-wake on older). Closes peer-integrator (AIMLA PM) FR #3010.

### Added

- **`KanbanTriageClassifier` (DL-168, #3010).** In the DL-driven board model a work item a human files **directly on the board** is untriaged (no `DL-NNN`, no `triaged` tag) and needs the PM to triage it — but today the PM discovers these only at SessionStart (session-cadence; a card filed mid-session waits for the next session). The new opt-in classifier (extends `InboxOnlyClassifier`) pairs the `new_card` Intent for a **human-filed** (`!isKnownAgent`), **untriaged** (no `triaged`/`id:pr:*` tag, no `dl` external reference) `task.created` with a `channel_push` to the triage-owner's cfg-default channel — the same live-wake transport the bridge already uses. Everything else stays inbox-only. **The filter runs entirely at classify time with NO API call and NO read token** off the kanban DL-164 `card` state snapshot the `task.created` webhook now carries — this is why the upstream snapshot was built first (it avoids the per-consumer `GET /tasks/{id}` + read-token workarounds). **No self-wake** (each automated creator is suppressed by a *different* mechanism): registered agents by `isKnownAgent`; the bridge's own dependabot-card creations carry `triaged` (DL-024) → dropped by the untriaged filter; the writeback identity is dropped pre-classify by the global-echo gate. **Degrade:** a pre-v0.22.0 kanban omits the `card` key → reads as untriaged → over-wake (never a miss; the SessionStart untriaged-snapshot is the durable backstop). **Opt-in:** set `classifier.class: App\Bridge\Classifiers\KanbanTriageClassifier` on the triage-owner agent + subscribe it to `task.created`; other agents keep `InboxOnly` and never wake.

## [0.41.0] - 2026-06-20

**Source-aware correlation — the writeback passes the repo as the kanban `source` qualifier (DL-167).** PR #172. App code — **no migration, no new config.** Requires kanban **v0.21.0+** for the `source` param (degrades to any-source on older). Closes the cross-repo collision reported by both peer integrators.

### Fixed

- **Repo-qualified by-ref correlation (DL-167).** A bare PR/DL number collides across repos on a board aggregating multiple repos (live: AIMLA board-9 `by-ref?system=github_pr&ref=33` → 4 rows across platform/magento/moodle; Sola board-3 shares 296 PR numbers across 3 repos). kanban added a `source` (repo) dimension on `by-ref` (kanban DL-163, v0.21.0); the bridge now uses it. `KanbanClient::correlatePr`/`correlateDl` take the event's repo and, in **`ref` mode** (the default, the only mode the multi-repo adopters run), pass it as the canonicalized `source` query param so the server returns only **this repo's** card(s) — for **both** the dependabot path (`KanbanDependabotCardHandler`) and the DL move path (`GitHubPrCardMoveClassifier`). Omitted repo / single-repo board ⇒ no `source` key ⇒ byte-identical to before. `scan` mode (legacy) keeps its existing `cardsForRepo` client-side guard. Closes the collision AIMLA + Sola reported — they pull this update to get repo-precise correlation.

## [0.40.0] - 2026-06-20

**Dependabot writeback card-create made idempotent on `(repo, PR)` — collapses concurrent-delivery duplicates (DL-166).** PR #168. App code — **no DB migration, no new config, no change to what the receiver accepts/rejects.** Plus the `closed_unmerged → Won't Do` operator-doc option (#167, docs only). Behavior change scoped to the dependabot-card path (`create_dependabot_cards`); the DL-tracked move path is byte-identical.

### Fixed

- **Dependabot card create is idempotent on `(repo, PR)` (#2982, DL-166).** Closes peer-integrator (Sola PM) report #2982 — the DL-024 dependabot writeback **double-created** a card for one PR (live: board-3 cards 2965+2968 for `actions/checkout` PR #289) and **orphaned the duplicate** in In-Review on merge. Root cause is a **check-then-create race**, not a correlation miss: `correlatePr`→`createCard` isn't atomic across concurrent deliveries (`opened`+`reopened`, or a fresh-`delivery_id` re-emit), so two parallel workers both correlate empty and both create. `KanbanDependabotCardHandler` now **collapses duplicates on the `(repo, PR)` key** — keep the lowest-id survivor (deterministic ⇒ racing workers converge), archive the rest (idempotent) — applied after create (closes the race) and on the move path (self-heals duplicates minted before this shipped). **Cross-repo guard (load-bearing):** correlation keys on the bare PR number (kanban's `github_pr` by-ref isn't repo-qualified), so on a board **shared across repos** (DL-027) a same-numbered PR in another repo collides; the handler attributes each correlated card by its `pr_url` and only ever moves/archives **this repo's** cards — a co-hosted repo's identically-numbered card is never touched (this also fixes a pre-existing cross-repo mis-*move*).

### Docs

- **`closed_unmerged → Won't Do` documented as a per-deployment operator option (#167, AIMLA FR Part B1).** `docs/writeback.md` now documents mapping `closed_unmerged` to a terminal "Won't Do" stage as an abandon-disposition (the default stays In Progress; the DL-163 guard already permits the terminal move; the dependabot path always archives regardless). Docs only — no app code.

## [0.39.0] - 2026-06-20

**Writeback no-regression guard generalized to all four PR outcomes (DL-163) + a `bridge:check` guard for silently-misconfigured dependabot cards (DL-162).** PRs #159–#160 + #163 (#2652, DL-164: bridge:check started/stage-id guards) + #164 (#2446, DL-165: promote-released-cards loud-fail) since v0.38.0, plus dependency bumps. No DB migration, no new config. The writeback change is the load-bearing one: it stops released/shipped cards being dragged backward by stale or redelivered `pull_request` events — **deploy this to halt the recurring board drift** that the prior writeback left unguarded.

### Fixed

- **No-regression guard on the four PR-move outcomes (DL-163, #2935).** DL-160 added a backward-move guard only to the `started` outcome; the original four — `opened` / `merged` / `merged_to_main` / `closed_unmerged` — still moved the card **unconditionally**, so a stale or redelivered `pull_request` event, or a **release PR whose title carries a card's `DL-NNN`**, could drag an already-Released card back to In-Review (seen live: cards #2650/#2659 drifted Released→In-Review repeatedly). `KanbanMoveCardHandler` now refuses any PR-move that would regress a card to an earlier stage, using the board's workflow-stage **order** (read from the lightweight preload via `KanbanClient::boardStageOrder`). `closed_unmerged` — the one legitimately-backward outcome (an abandoned PR returns its In-Review card to In-Progress) — is allowed to regress **unless** the card has already reached a terminal (Shipped/Released) stage, so a stale close can't resurrect a shipped card. **Fail-open:** when the order can't be read, the move proceeds as before, so the guard never breaks the writeback. One extra lightweight preload GET per PR-move event.

### Added

- **`bridge:check` flags a `create_dependabot_cards` mapping whose board lacks the create-payload custom fields (DL-162, #2949).** A mapping with `create_dependabot_cards: true` POSTs a card with the payload keys `pr_number` / `pr_url` / `origin`; kanban 422s any unregistered payload key and the handler treats the 4xx as permanent (logs + no-ops), so a board missing even one field drops **every** dependabot-card create silently (200 delivery, no card — found live on board 8 with no `pr_url`). `bridge:check` now reads the board's registered custom-field keys (`GET /boards/{id}/custom_fields.json`) and warns loudly, naming the missing field(s), when the flag is on but the board lacks them — warn-never-fail, the create-path twin of the DL-027 swimlane check. The required-key list is `KanbanDependabotCardHandler::CREATE_PAYLOAD_KEYS`, now the authoritative single source (the create payload is built from it, so the check can't drift). Diagnostics only — no delivery-path change.

### Dependencies

- Bump `laravel/framework` 13.12.0 → 13.16.1 (#149), `phpunit/phpunit` 12.5.28 → 13.2.1 (#148), `symfony/yaml` 7.4.13 → 8.1.0 (#147), `shivammathur/setup-php` 2.37.1 → 2.37.2 (#145), `laravel/pao` 1.1.0 → 1.1.1 (#146), `actions/checkout` 6.0.2 → 6.0.3 (#113).
- **Security:** bump `hono` 4.12.23 → 4.12.26 in `examples/channel-servers` (#165) — fixes the high-severity path-traversal advisory GHSA-wwfh-h76j-fc44 (`serve-static` on Windows via encoded backslash). Channel-server example → **0.4.4**.

## [0.38.0] - 2026-06-19

**Dependabot writeback archives the card when its PR closes unmerged (DL-160-sibling, DL-161).** PR #155 since v0.37.1. App code — no DB migration, no new config. **Behavior change, scoped to the dependabot-card path** (`create_dependabot_cards: true`); the DL-tracked move path is byte-identical. Closes a peer-integrator (Sola PM) FR (#2659).

### Changed

- **A closed-unmerged dependabot PR now ARCHIVES its card instead of moving it (DL-161).** Dependabot routinely closes its own PRs (a newer bump supersedes an older one, or a maintainer closes it), so the old behavior — `moveCard` to the `closed_unmerged` stage — left dead cards accumulating on the board (the reporter had 7 stale cards sitting in Backlog 8–11 days after their PRs closed). `KanbanDependabotCardHandler` now archives every correlated card on `closed_unmerged`, which needs **no `closed_unmerged` stage mapping** for the dependabot path. "No card on close → skip" is unchanged.

### Added

- **`KanbanClient::archiveCard()`** — issues the kanban lifecycle verb `PATCH {"_action":"archive"}` (a `{"task":{"archived_at":…}}` *field* PATCH returns 200 but silently no-ops) and returns whether the response confirms the archive (`data.archived_at` set). An unconfirmed archive is **deterministic**, so the handler logs an `error` and no-ops rather than 5xx-ing into a ~11-day redelivery storm (the DL-020 anti-pattern); a genuine HTTP error still throws (transient 5xx → retry, 4xx → permanent). Idempotent for free: kanban excludes archived cards from by-ref/search correlation, so a redelivered close finds nothing.

## [0.37.1] - 2026-06-19

**Docs: warn that the DL-160 `started` outcome must be added to `writeback.json` AFTER deploying v0.37.0+ (#2658).** Docs only — no app code, classifier, schema, migration, or `.env` change; no behavior change. Follow-up to v0.37.0 (DL-160), surfaced during the prod activation.

### Docs

- **`docs/writeback.md` § Branch-create → In Progress: upgrade-ordering warning.** Adding `started` to `writeback.json` while a pre-v0.37.0 bridge is still serving fails **closed for every mapping in the file** — a pre-v0.37.0 `WritebackConfig` rejects the unknown `started` outcome as a *malformed config*, which disables the whole writeback (all repos), not just the edited mapping. Documented the required sequence: deploy + reload → `bridge:check` green → *then* edit config → `bridge:check` again.
- **`CLAUDE_DEPLOYMENT.md` § Update an existing install: same ordering callout, generalized** to any newer-version writeback outcome (deploy code first, edit `writeback.json` second), cross-linked to the `started` config + required `push` webhook event.

**Branch-create push → card "In Progress": derive work-begun from the artifact (DL-160).** PR #150 since v0.36.0. **App code — no DB migration; opt-in and OFF until configured.** Closes the gap where a card sat in Backlog/Prioritized through the whole first stretch of work and only advanced at PR-open. Adds a fifth writeback outcome, `started`, driven by the GitHub `push` that **creates** a feature branch — "work has begun" derived from the branch, no agent in the loop, consistent with the writeback's machine-only posture. **Requires the operator to (a) map a `started` stage + set `started_from_stages` in `writeback.json`, and (b) subscribe the repo webhook to `push` events** — an upgraded install with neither is inert. See [`docs/writeback.md`](writeback.md) § *Branch-create → In Progress*.

### Added

- **`started` writeback outcome — branch-create push promotes the correlated card to In Progress (DL-160, #2650).** `GitHubPrCardMoveClassifier` now classifies the already-parsed `push` event (previously ignored): a push that **created a branch** (`payload.created === true`) whose `refs/heads/…` ref carries a `DL-NNN` emits a `kanban_move_card` target with `outcome: 'started'`, correlating DL→card exactly as the PR path. Fires **once at branch birth** (not on subsequent pushes to the same branch); a `dependabot/*` branch, a tag ref, or a DL-less ref is a no-op. Which board+stage `started` maps to is operator config (`writeback.json` `stages.started`), never hard-coded. The four existing PR outcomes and their tests are byte-identical.
- **No-stage-regression guard via `started_from_stages` (the load-bearing safety decision).** A `started` move must only ever **promote** a card — never drag an already-In-Review/Shipped/Released card backward (re-creating or force-pushing an old branch re-fires `push`+`created`). `KanbanMoveCardHandler` reads the card's current `workflow_stage_id` and applies the `started` move **only** when that stage is in the mapping's new optional **`started_from_stages`** (the board's Backlog/Prioritized stage ids), parsed strictly like `board_id`/`stages` (a non-list or non-numeric element fails the config closed). **Absent `started_from_stages` ⇒ the `started` move is refused** (fail-closed — the guard can't know what's safe to promote from; logged + no-op), so the trigger can't silently regress a card. Idempotent: the already-in-target-stage short-circuit still applies first.

### Operator action required

- **Subscribe the repo webhook to `push` events** (in addition to *Pull requests*). `bridge:provision` does **not** manage GitHub webhooks (no repo-admin token, by design) — set this by hand in the repo's **Settings → Webhooks**. A webhook left on *Pull requests* only will silently never fire the `started` move.
- **Map a `started` stage and set `started_from_stages`** in `writeback.json`. Both are required to enable the trigger; neither set ⇒ inert (no behavior change on upgrade).

## [0.36.0] - 2026-06-14

**Channel-server example cleans up its UNIX socket on every ordinary quit (DL-159).** PR #141 since v0.35.0. **Example + docs only — no app code, classifier, schema, migration, or `.env` change.** Channel-server example → **0.4.3**. Server-side counterpart to DL-154/155/157; closes a peer integrator's (Sola PM) report (#2533).

### Fixed

- **`examples/channel-servers/agent-webhook-bridge-channel.mjs` unlinks its socket on `SIGINT`/`SIGHUP`/stdin-EOF, not just `SIGTERM` (DL-159).** The server had one signal handler (`SIGTERM`) and no explicit `unlink`, so terminal-close (`SIGHUP`), Ctrl-C (`SIGINT`), and parent-pipe close (stdin EOF) leaked the pathname AF_UNIX socket — and a leftover pathname socket makes the next direct `bind()` fail `EADDRINUSE` on Linux **regardless of any listener**, so the server wrote a `<socket>.FAILED` "another session already holds the channel" deaf marker when zero sessions ran. Now one idempotent `shutdown()` handles `SIGTERM`/`SIGINT`/`SIGHUP` and unlinks **synchronously** (`server.close()` unlinks asynchronously while `process.exit()` is synchronous, so an in-flight connection at `SIGTERM` could leak even on the "clean" path). The unlink is gated on a `bound` flag set at listen-success, so a signal during the `EADDRINUSE` failure window never removes a live peer's socket. Parent-death self-exit uses `process.stdin.on('end')` — the MCP SDK's `StdioServerTransport` registers stdin `'data'`/`'error'` but no `'end'`/`'close'`, so it doesn't surface EOF via `onclose`; a bare `'end'` listener is the correct hook (no `resume()` needed, the SDK already flows stdin), with `mcp.onclose` wired as defense-in-depth. `SIGKILL` and hard crashes still leak by design — that's what the launcher's stale-socket guard + the `.FAILED` marker backstop are for. Example → **0.4.3** (DL-038 drift signal).

## [0.35.0] - 2026-06-14

**Canonical GitHub-issue-comment classifier reference + custom-classifier reconcile step (DL-158).** PR #137 since v0.34.0. **Docs only — no app code, classifier, schema, migration, or `.env` change; no behavior change to any shipped class.** Closes the canonicalization follow-on to a peer integrator's (Sola PM) FR (#2514); the consumer-side issue was already resolved in the integrator's own classifier.

### Docs

- **`docs/customization.md`: "Surfacing GitHub issue comments to a channel (forward the comment identity)" (DL-158).** A minimal worked-example custom classifier that turns `issue_comment.created` into a `github_issue_comment` Intent forwarding the **comment identity** — `comment_id` + `comment_created_at` + `comment_html_url` — paired with a `channel_push` (`target_id == subject_id`). It lets a consumer **exact-fetch** the triggering comment (`GET /repos/<repo>/issues/comments/<comment_id>`) and de-dup replays **by id**, instead of re-reading the whole thread and positionally guessing the newest comment — GitHub's issue-comments endpoint paginates 30/page oldest-first with no `sort`/`direction` param, so a naive `.[-1]` returns the 30th comment, not the newest. The shared-identity/recipient machinery is cross-referenced as an **optional** layer, kept out of the base; the shape generalizes to PR review comments.
- **`CLAUDE_DEPLOYMENT.md`: "Reconcile out-of-repo copies" now covers the custom classifier (DL-158).** A custom classifier lives in the install's `app/Bridge/Classifiers/` and survives `git pull` untouched — so it freezes at the reference it was copied from. New installs start from the `customization.md` reference; each update **diff-merges** improvements (e.g. the `comment_id` forwarding) while preserving deployment-specific extensions, never blind-replacing. `bridge:check` confirms the classifier *loads*, not that it's *current*.

## [0.34.0] - 2026-06-13

**Multi-topology channel live-wake: HTTP-aware `bridge:check` (DL-156) + a canonical self-resolving cross-platform launcher (DL-157).** PRs #132–#133 since v0.33.0. Examples + docs + one diagnostic command; **no receiver/handler/schema/migration/`.env` change.** Channel-server example → **0.4.2**. Closes a peer integrator's (Sola PM) FR-1/2/3 for the multi-agent, multi-host (HTTP-over-SSH-tunnel) + Windows topology.

### Added

- **Canonical self-resolving, transport-aware channel launcher (DL-157, FR-1).** `examples/start-channel-session.sh` is rewritten and `examples/start-claude.ps1` + `examples/start-claude.bat` are added, so **one launcher per OS** serves any agent with no per-agent hardcoding (killing the hand-rolled-copy drift). Identity self-resolves `--channel` → `$BRIDGE_CHANNEL_NAME` → `settings.local.json` `.env` → `<namespace>-<agent>` from `$COORD_CONFIG` + `$COORD_AGENT` (the launcher runs in the login shell, which can't see Claude Code's session-injected env — the #1 "can't resolve channel" cause). Transport-aware guards for **UDS and HTTP** (socket-curl/`pgrep` vs TCP-port probe), DL-154/155 marker surfacing rendered for both, and the resolved identity is **exported before `exec`** so the channel server binds exactly the endpoint the launcher guarded. Windows half (PowerShell + a `.bat` ExecutionPolicy shim) owns the SSH-reverse-tunnel lifecycle (hidden side process, PID-tree teardown).
- **`bridge:check` HTTP-transport awareness (DL-156, FR-2).** For an HTTP-transport agent (`channel.url`, no `channel.socket` — the SSH-tunnel topology), `bridge:check` now **TCP-probes** the loopback/tunnel `host:port` for liveness (reaches the remote connector through the tunnel) and surfaces an HTTP `.FAILED` marker best-effort when run on the agent host. DL-154/155's deaf-session surfacing was UDS-only before. Warn/info only, never fails the check.

### Changed

- **Channel server `markerPath()` HTTP base `'/tmp'` → `os.tmpdir()` (DL-156).** A literal `/tmp` resolved to `C:\tmp` under Node on Windows and never matched the Windows launcher's `%TEMP%` lookup, so the HTTP marker was unfindable there; `os.tmpdir()` is `%TEMP%` on Windows and `/tmp`/`$TMPDIR` on Linux. Example → **0.4.2** (DL-038 drift signal).

### Docs

- **`CLAUDE_DEPLOYMENT.md`: "The canonical channel launcher" + "Multi-agent channel-server distribution" (FR-3).** The launcher's resolution chain, `COORD_CONFIG` shape, transport selection, and Windows tunnel lifecycle; plus the canonical per-snapshot reconcile — pin the **release tag via `git` (not `gh` — auth mismatch misleads)**, copy `examples/channel-servers/`, `npm ci`, **at a session boundary**, uniform provenance = the same tag on every agent. `docs/config-schema.md` `channel.url` row notes the new probe.

## [0.33.0] - 2026-06-13

**Channel launcher fail-loud fix (DL-155) + update-runbook & doc-citation hardening.** Commits since v0.32.0. No app code, no DB migration, no new `.env` keys. Channel-server example → **0.4.1**.

### Fixed

- **`start-channel-session.sh` surfaces the `<socket>.FAILED` marker instead of silently clearing it (DL-155, correction to DL-154).** The launcher `rm -f`'d the deaf-session marker *before* binding — wiping the durable fail-loud signal FR #2444 created, at the exact moment (a relaunch) an operator should see it. The connector already clears the marker unconditionally on a successful bind (which the launch triggers moments later), so the launcher clear was both redundant and silent. The launcher now prints the prior deaf-session's marker (timestamp + pid + reason) to stderr and leaves the connector as the **sole** clearer; surfacing is best-effort (`|| true`) so a stray unreadable marker can never block a session start. The deployed `~/start-claude.sh` gets the same change (operational sync, outside the repo).
- **Channel-server example bumped 0.4.0 → 0.4.1** for the README lifecycle-note doc-sync (DL-038 snapshot-drift signal). Also corrects the `package-lock.json` `version` field left at 0.3.0 when v0.32.0 bumped `package.json` to 0.4.0 (`npm ci` doesn't validate the top-level version field).

### Changed (docs only)

- **Update runbook: reconcile out-of-repo copies after a pull (`CLAUDE_DEPLOYMENT.md`).** Explicit instructions for a Claude Code agent to reconcile the files copied/hand-derived out of `examples/` at install time — the session launcher and, depending on topology, the channel-server `.mjs` (loaded directly from the repo on single-agent hosts vs. snapshotted into a per-agent `*-coordination/OUTBOUND/<agent>/channel-setup/` dir on multi-agent hosts) — which `git pull` can't update and which never trip `bridge:check`. Includes the `~/.mcp.json` topology check, the `package.json` `version` drift signal (DL-038), and a `find` recipe so no per-agent copy is missed.
- **Corrected the v0.32.0 PHP 8.5 DL citation: DL-040, not DL-153.** The v0.32.0 release notes cited the *kanban* lockstep decision (DL-153) where the bridge's own decision is **DL-040** (the registry in `CLAUDE_DECISIONS.md`); fixed in `CLAUDE.md` + `docs/CHANGELOG.md`. The "kanban DL-153" cross-reference in DL-040's context line is correct and left intact.

## [0.32.0] - 2026-06-12

**Make a deaf/duplicate channel connector visible (DL-154) + addressing/contract polish (#2202) + PHP 8.5 standardization (DL-040).** PRs #119, #120, #121 since v0.31.0. No DB migration, no new `.env` keys.

### Added

- **Visible bind-failure marker + single-session guardrail + `bridge:check` liveness ping for deaf/duplicate channel sessions (FR #2444, DL-154).** When an agent runs two Claude Code sessions, the active one's channel connector can lose the socket-bind race (`EADDRINUSE` → `exit(2)`) — and because Claude Code swallows MCP-server startup stderr, that session came up **deaf to live-wake invisibly** while the bridge kept delivering `HTTP 202` to the other session's connector and logging `delivered`. Three composing guards now surface it (no cron, no daemon): (1) the reference connector writes a visible **`<socket>.FAILED` marker** (timestamp + pid + reason) on `EADDRINUSE` and the connector that *successfully* binds clears it — surfaced by `bridge:check` for the UDS transport; (2) **`start-channel-session.sh`** refuses to launch if a `claude … server:<channel>` process already holds the channel (a `pgrep` guardrail — the connector's refusal is the backstop) and clears a stale marker on start; (3) **`bridge:check`** adds an on-demand **socket liveness ping** (distinguishes a live, listening session from a stale socket) and reports any `.FAILED` marker. The reference channel server example is bumped to **0.4.0** (re-sync copied snapshots per DL-038).

### Changed

- **`RecipientAddressing::author()` returns the first `FROM:` token, not the verbatim tail (#2202).** A decorated/multi-name FROM line (`FROM: alice (pls review)`, `FROM: alice, bob`) used to return the whole tail verbatim, so a classifier doing `author($body) === $agentName` silently failed to match. It now tokenizes to the first whitespace/comma-delimited token (symmetric with `recipients()`), so both yield `alice`. Behavior change to a helper for operator classifiers; `author()` is new (v0.29.0 / DL-035) and no internal consumer depended on the verbatim tail. The kanban integration contract now also pins the by-ref `system` enum (`{dl, github_pr}`).
- **Standardize on PHP 8.5 (DL-040).** `composer.json` requires `^8.5`; CI runners pinned to 8.5 (the validated surface now matches the deployed runtime). An install on PHP <8.5 fails the `composer install` platform check — intentional. No app-logic change.

### Operator notes

- **No DB migration, no new `.env` keys.** Re-sync any copied channel-server snapshot to example `0.4.0` (DL-038). The launcher single-session guardrail also applies to a hand-rolled `~/start-claude.sh` (operational sync, outside the repo). PHP <8.5 installs must upgrade PHP before `composer install`.

## [0.31.0] - 2026-06-09

**Uid-agnostic `channel.socket` + loud uid-mismatch errors (DL-039).** PR #115 since v0.30.0. No DB migration, no new `.env` keys.

### Added

- **`${XDG_RUNTIME_DIR}` / `${uid}` placeholder expansion in `channel.socket` (DL-039).** An agent's `channel.socket` may now be written uid-agnostically — `${XDG_RUNTIME_DIR}/agent-webhook-bridge-channel-<name>.sock` — instead of pinning a literal `/run/user/<uid>/…`. Expanded at config-load (before validation): `${XDG_RUNTIME_DIR}` → `$XDG_RUNTIME_DIR`, or `/run/user/<uid>` when the env is unset (PHP-FPM usually doesn't inherit it, so the bridge derives it from the running uid); `${uid}` → the running uid. So restoring an install on a host where the OS uid changed just works — previously the literal path silently broke live-wake. An unrecognized/typo'd token fails closed at load. Mirrors the channel server's existing `$XDG_RUNTIME_DIR` derivation; the `0600` UDS trust model and the macOS/container explicit-path escape hatch are unchanged.

### Changed

- **`channel_push` no longer misdiagnoses a uid mismatch as a stopped server (DL-039).** A stale socket whose parent dir is gone now reports *"socket parent dir … does not exist — likely a uid mismatch after a host restore … repoint channel.socket or derive it with `${XDG_RUNTIME_DIR}`"* instead of the misleading *"start the channel server first"* (the server may be fine). The uid-restore wording is used only for the operator's agent socket, not a classifier-supplied one.
- **`bridge:check` now validates `channel.socket` reachability (DL-039).** Warns (doesn't fail — the socket is the channel server's to create) when a configured `channel.socket`'s parent dir is missing or non-writable, surfacing the uid mismatch at preflight instead of a silent runtime no-op.

### Operator notes

- **No DB migration, no new `.env` keys.** Optional adoption: on systemd Linux, rewrite `channel.socket` from `/run/user/<uid>/…` to `${XDG_RUNTIME_DIR}/…` so a future host/uid restore needs no edit. Existing literal paths keep working. The reference channel server example bumped to `0.3.0` (README guidance; copied snapshots should re-sync per DL-038).

## [0.30.0] - 2026-06-08

**Adversarial bug-hunt sweep (DL-037) + channel-server snapshot drift signal (DL-038).** PRs #108, #109 since v0.29.0. No DB migration. No app code in DL-038 (example + CI + docs).

### Security

- **`spawn_detached` resolves `setsid` to an absolute path (DL-037).** `setsid` was exec'd by bare name, so a classifier-payload `env.PATH` could redirect which `setsid` binary ran — sidestepping the `cmd[0]` absolute-path allowlist (the launcher execs `cmd`). Now resolved to an absolute path (`BRIDGE_SPAWN_SETSID_PATH`, auto-detected, fail-closed). Opt-in surface (`BRIDGE_SPAWN_ENABLED`), but a real allowlist bypass.

### Changed

- **Receiver rejects an over-length envelope field with a deterministic 400, not a 5xx (DL-037).** Only `delivery_id` length was asserted; an over-length `scope_id`/`event_type`/`actor_id` hit the DB column as a `QueryException` → 5xx → an upstream retry-storm of a deterministically-bad body. `assertFieldLengths` now guards every field written to `webhook_events` (fix the primitive). **The one receiver accept/reject change in this release** — realistic kanban/GitHub values are well under the column limits.
- **Same-event target coalescing keys on `(handler, debounceKey)`, not `debounceKey` alone (DL-037).** Two targets for one subject routed to different handlers (default `debounceKey` is the `targetId`) no longer collide last-wins and silently drop one. No shipped classifier triggers it; a custom-classifier footgun.
- **`KanbanClient::readBoard` scan stops on `links.next === null` per the documented board-read contract (DL-037).** Was a short-page heuristic while the contract specified `links.next`; the in-house consumer is now consistent with the rule the bridge wrote down (short-page fallback retained for a pre-DL-146 kanban that serves no `links`). Not a data-loss fix — contract alignment + no wasted extra request at an exact page multiple.

### Fixed

- **`bridge:replay --force` resets the whole terminal tuple (DL-037).** `--force` nulled only `processed_at`; a re-run exiting via a non-terminal path (durable/config throw → 5xx) left the prior `outcome`/`error_message` next to a now-null `processed_at` — the inconsistency DL-036 exists to prevent. Now nulls `outcome`/`reason`/`error_message` too.
- **`bridge:check` surfaces id-collisions to the operator console (DL-037).** A duplicate `kanban_user_id`/`github_user_id` (silent attribution bypass) was `Log::warning`-only despite a comment claiming preflight surfaces it; now rendered warn-level (exit unchanged).

### Added

- **Channel-server snapshot drift signal + CI bump-gate (DL-038).** Consumers copy `examples/channel-servers/` per deployment, so a snapshot drifts silently on a bridge update (DL-033's package.json was never bumped — it sat at `0.1.0` since the first commit). `package.json` `version` is now the drift signal (bumped to `0.2.0`); a PR-only `version-bump-guard` job in `channel-server-supply-chain.yml` **fails the build** when a shipped file under `examples/channel-servers/**` changes without a version bump. README § Staying in sync added.

### Operator notes

- **No DB migration.** New optional env **`BRIDGE_SPAWN_SETSID_PATH`** (absolute path to `setsid`; auto-detected when unset — most installs need nothing). The receiver now returns **400** (was 5xx) for a hostile/malformed over-length envelope field. Adopters of the reference channel server: compare your copy's `package.json` `version` against canonical to detect drift (a symlink never drifts).

## [0.29.0] - 2026-06-08

**Dispatch outcome ledger + operator-diagnostics polish — three peer-integrator FRs.** PRs #103, #104 since v0.28.0. ⚠ One non-destructive DB migration (FR-2).

### Added

- **Dispatch outcome ledger (DL-036, #104 / FR-2).** `agent_dispatches` gains a nullable `outcome` (`delivered` | `dropped` | `errored`) + `reason`, recorded at each terminal in `DispatchService`. A deliberate **gate-drop** (echo of the agent's own write / actor-not-a-signal / classifier-emitted-no-reactions) and a real **delivery** were previously byte-identical in the ledger (both `processed_at` set, `error_message` null), so `bridge:inspect` couldn't tell them apart and `bridge:replay` without `--force` silently no-op'd the gate-dropped rows it should re-run after a gate fix. `bridge:inspect` now shows the `outcome` + `reason / error`; `bridge:replay` (no `--force`) reports how many skipped rows were gate-DROPPED and that `--force` re-runs them. Each terminal write nulls the inapplicable satellite field, so a `--force` replay outcome *transition* can't leave a stale reason/error.
- **`RecipientAddressing::author()` — symmetric `FROM:` parser (DL-035, #103 / FR-3).** Mirrors `recipients()`: the first `FROM:` line of a comment body, lowercased + trimmed, or `null` (bare/empty `FROM:` is absent; `FROMAGE:` doesn't match). For custom classifiers routing shared-identity threads; recipient/author *policy* still lives in the operator's classifier (DL-022/DL-032).

### Changed

- **`bridge:check` 0-card writeback warning no longer asserts non-membership (DL-034, #103 / FR-1).** 0 cards on a 200 board read is ambiguous (empty board vs membership gap); the warning now presents **both** possibilities instead of claiming the token's user is "likely not a member." True inaccessibility is already caught separately by the `ref`-mode by-ref reachability probe (DL-031). Message-only; still warn-level, per mapped board.

### Documentation

- **Reply-direction footgun callout + role-reversal example in `docs/customization.md` (DL-035).** Route a comment by the comment's OWN `TO:`/`FROM:`, never the parent issue's frozen labels (those silently drop a reversed-direction reply); use labels only as the `null` fallback. The shared-identity echo example now dogfoods `author()` instead of a hand-rolled `preg_match`.

### Operator notes

- **⚠ Run `php artisan migrate`** — FR-2 adds nullable `outcome` + `reason` columns to `agent_dispatches`. Non-destructive, no backfill: pre-migration rows read `outcome=null` and every reader falls back to the legacy `processed_at`/`error_message` inference (`bridge:inspect` shows `done (pre-DL036)`). No config change.

## [0.28.0] - 2026-06-07

**Supply-chain + integration-docs hardening — no app code, no DB migration.** PRs #98, #99 since v0.27.0; both prompted by peer integrators (AIMLA PM).

### Added

- **Pinned dependency tree for `examples/channel-servers/` (DL-033, #99).** Commits a `package-lock.json` (lockfileVersion 3) for the reference channel MCP server — it reads a bearer token and accepts loopback POSTs as the agent's OS user, a real trust boundary — and switches the README + `start-channel-session.sh` launcher from `npm install` to **`npm ci`** (installs the exact pinned tree; fails on `package.json`/lock drift). **Node ≥ 20** is now stated up front. A cost-scoped CI gate (`channel-server-supply-chain.yml`) keeps the pin *watched*: `npm ci --ignore-scripts` (drift) + `npm audit --audit-level=high` (fails loud on a high/critical CVE), path-filtered to `examples/channel-servers/**` plus a weekly cron. Chose the audit-flag over dependabot auto-bumps for a single-dep example — loud-on-CVE + deliberate manual bump, lowest CI cost.

### Documentation

- **Canonical board-read pattern in the kanban↔consumer integration contract (#98).** Documents the scale-safe full-board read — **structure** from `GET /boards/{id}/preload.json`, the **card list** from paged `GET /tasks/search.json` (`board_id=N`, stop on `links.next`), **fail loud** on a non-200 mid-page (a partial read must never look like a shorter board) — so consumers stop re-deriving it per integration. Also corrects a misconception: the kanban board GET is **complete-but-heavy**, not silently truncated.

### Operator notes

- No DB migration, no config change. Adopters of the reference channel server should re-install with `npm ci` (the committed lockfile is now the source of truth); existing installs are unaffected.

## [0.27.0] - 2026-06-07

**Writeback correlation defaults to `ref` (DL-031) + comment-level recipient helper (DL-032).** PRs #92, #95 since v0.26.0. No DB migration.

### Changed

- **`BRIDGE_WRITEBACK_CORRELATION` now defaults to `ref`** (correction to DL-029's `scan` default). An undefined env uses the indexed `by-ref` lookup; set **`BRIDGE_WRITEBACK_CORRELATION=scan`** for backwards compatibility or a kanban that predates `by-ref`. Flipped across all three layers (config env default, `WritebackClientFactory` fallback, `KanbanClient` constructor default).

### Added

- **`bridge:check` by-ref reachability probe (DL-031).** Safety net for the `ref` default: in `ref` mode, `bridge:check` actively verifies the kanban exposes `by-ref` (`KanbanClient::byRefAvailable`) and warns loudly — naming a pre-DL-147 kanban *or* an inaccessible board — instead of letting every correlation 404 silently.
- **`RecipientAddressing` helper for comment-level `TO:` filtering (DL-032, #95 / #2173).** A reusable parser, `App\Bridge\Support\RecipientAddressing::addresses($commentBody, $agentName): ?bool`, for custom classifiers that filter channel pushes by a comment body's `TO:` line (so a multi-recipient thread doesn't wake every recipient on every comment). Three-state: `true` (names the agent or `all`), `false` (names others → drop), `null` (no/empty `TO:` line → caller falls back to issue/card labels). Case-insensitive; first `TO:` line wins. Recipient *policy* stays in the operator's classifier (DL-022) — this is just the shared parse; nothing wired into the runtime. New `docs/customization.md` § Comment-level recipient filtering recipe.

### Operator notes

- **⚠ Upgrading:** a `ref`-default bridge requires its kanban to be **v0.17.2+ and backfilled** (`php artisan kanban:backfill-external-references`). If yours isn't, set `BRIDGE_WRITEBACK_CORRELATION=scan` before/at upgrade. `bridge:check` will name the problem before traffic. No DB migration; no other config change.

## [0.26.0] - 2026-06-06

**Writeback correlation cutover to the kanban `by-ref` lookup + orphaned-mapping guard.** PRs #85, #86, #87, #88 since v0.25.0. No DB migration.

### Added

- **Indexed `by-ref` correlation + move-ALL-matching cards (DL-029, #87 / #2160).** Correlation now dispatches on **`BRIDGE_WRITEBACK_CORRELATION`** (default `scan`): `ref` does one indexed `GET /boards/{b}/tasks/by-ref.json` per key (kanban DL-147/148 — server-canonicalized, O(1), no paging/ceiling); `scan` is the existing board-scan fallback. A PR/DL is **one-to-many** (kanban DL-148), so both modes return **all** matching card ids — the classifier emits one `kanban_move_card` target per card (distinct `targetId`, no coalesce) and the dependabot handler moves every match. Default `scan` ⇒ upgrading the bridge is **inert**; flip an install to `ref` after its kanban is on v0.17.2+ and `task_external_references` is backfilled (`bridge:check` confirms). The blind/degraded-token probe is decoupled into a cheap `KanbanClient::visibility()` (`limit=1` read of the kanban DL-146 `meta.total`, row-count fallback for a pre-DL-146 kanban).
- **`bridge:check` flags an orphaned writeback mapping (DL-030, #88 / #2162).** A `writeback.json` mapping is inert unless some agent runs a writeback-emitting classifier subscribed to its github scope; `bridge:check` now warns when none does. Detection uses a marker interface `App\Bridge\Contracts\EmitsWritebackReactions` (implemented by `GitHubPrCardMoveClassifier`) checked **out of process** (`ClassifierResolver::probeImplements`, DL-025) and runs independently of the board-visibility probe.
- **Writeback correlation paging stopgap (DL-028, #85 / #2151).** The scan-mode board read pages past 200 cards (superseded as the primary path by DL-029's `ref` mode, retained as the `scan` fallback).

### Documentation

- **The kanban-board ↔ bridge integration contract (#86).** `docs/kanban-integration-contract.md` pins the seam (inbound webhook envelope/HMAC, outbound v3 surface, correlation keys, load-bearing invariants, change protocol); updated for `by-ref` correlation. `docs/customization.md` notes that a custom writeback-emitting classifier must implement the `EmitsWritebackReactions` marker.

### Operator notes

- **No DB migration, no required config change.** `BRIDGE_WRITEBACK_CORRELATION` is additive (absent ⇒ `scan`, today's behavior). To activate the indexed path on an install whose kanban is v0.17.2+ **and** backfilled: set `BRIDGE_WRITEBACK_CORRELATION=ref` and run `bridge:check`.

## [0.25.0] - 2026-06-06

**Per-mapping writeback swimlane + test/doc hardening.** One opt-in runtime addition (swimlane on created cards); the rest is test-hermeticity and operator docs. PRs #79, #80, #81 since v0.24.0. No DB migration.

### Added

- **Per-mapping `swimlane_id` for writeback-created cards (DL-027, #81 / #2148).** A writeback `mappings` entry may declare an optional `swimlane_id`; cards the bridge **creates** (today, the `create_dependabot_cards` path) land in that lane — the lane-per-repo-on-a-shared-board case. Applied at **create only, never on a move** (a move stays a column-only `workflow_stage_id` PATCH, so a human re-laning a card survives and a redelivery can't yank it back). Strict-numeric and fail-closed (a non-numeric value throws `ConfigException`, never silently drops to the default lane — the DL-026 posture); absent ⇒ the POST omits the key ⇒ byte-identical to prior behaviour. `bridge:check` validates a pinned lane against the board's actual swimlanes (via the lightweight `GET /boards/{id}/preload.json`) and **warns** (never fails) when it's missing. Opt-in and backward-compatible; existing `writeback.json` is unaffected. See `docs/writeback.md` § Optional: pin created cards to a swimlane.

### Fixed

- **Test suite is hermetic against an operator's ambient `BRIDGE_*` env (G-017, #79).** A shell with `BRIDGE_INBOX_LAYOUT=per-agent` (or `BRIDGE_STATE_DIR`) exported leaked into the suite and reproduced as ~26 failures on the operator's host while CI stayed green — `env()` reads the `getenv()` layer the shell export lives in, which a phpunit `<env force="true">` does **not** override. The base `TestCase` now pins these via a runtime `config()` call in `setUp`, so the suite resolves the same config regardless of the host shell.

### Documentation

- **Operator-update doc gaps closed (#80).** `CLAUDE_DEPLOYMENT.md` gains the custom-classifier migration step, a `bridge:check`-before-serving note, and a signed smoke-test recipe; `CLAUDE_GOTCHAS.md` adds G-018 (401 `scope_mismatch`); `docs/customization.md` + `docs/config-schema.md` cross-links and the writeback-token warning are clarified.

## [0.24.0] - 2026-06-06

**Release-automation robustness (sibling to kanban-board DL-143).** Release-tooling only — no change to the bridge runtime, receiver, or any app code. PR #75 since v0.23.0.

### Changed

- **`bin/promote-released-cards` correlates by PR-number in addition to DL (sibling to kanban-board DL-143).** A tracking card is promoted to "released" if its `payload.dl_number` matches a shipped `DL-NNN` **or** its `payload.pr_number` matches a shipped PR number (from each commit's trailing `(#NNN)`). Previously DL-only, so PR-only cards (bug/chore cards with no DL) were **silently** left in "shipped-to-dev" each release — observed live on v0.23.0 (five PR-only cards + one missed-DL card sat un-promoted until reconciled by hand). Also: the script now **pages the whole board** (no silent 200-card truncation), **refuses an empty base range** (no full-history sweep), warns on an unstamped shipped DL, and numeric-validates the board/stage config. Same "a degraded-but-not-erroring read must be loud" rule as the writeback (DL-026). The script is shared byte-identical with kanban-board.

## [0.23.0] - 2026-06-06

**BREAKING classifier-interface change + writeback robustness + opt-in dependabot cards.** PRs #60, #61, #66–#71 since v0.22.0.

### Added

- **Dependabot cards, opt-in per repo (DL-024, #66/#67).** Set `create_dependabot_cards: true` on a writeback mapping and a dependabot PR (head `dependabot/*`, no `DL-NNN`) gets a card **created on open** and carried through the same lifecycle on close — correlated by **PR number** (no DL needed). New cards are tagged `dependencies`/`triaged` and carry `payload.pr_number`/`pr_url`/`origin`. Builds on the existing writeback setup; default `false` (no behaviour change). See `docs/writeback.md` § Optional: dependabot cards.

### Changed

- **BREAKING — `Classifier::classify()` now takes a single `ClassifyContext $ctx` (DL-025, #70).** Replaces the prior positional parameter list (`eventType`/`payload`/`actor`/`provider`/`scopeId`/`agent`) with one readonly DTO. **Adding future context is now non-breaking — this is the LAST breaking change to `classify()`.** Every custom classifier must migrate to `classify(ClassifyContext $ctx): ClassifyResult` (read inputs from `$ctx->*`, thread `$ctx` through any `parent::classify()`). Also adds an **out-of-process `bridge:check` pre-flight** that loads each classifier in a child php process, so an incompatible-signature `E_COMPILE_ERROR` surfaces as a named check failure instead of crashing the command/request. The 3 in-tree classifiers + `docs/customization.md` are updated.

### Fixed

- **Writeback fails loudly on a blind/degraded token + page-cap truncation (DL-026, #71).** A writeback token that returns **0 cards** (its user lost board membership, or a wrong `board_id`/instance — kanban answers `200` + empty data, so no HTTP error) no longer **silently no-ops every move** (or, for `create_dependabot_cards` mappings, **creates a duplicate card** each redelivery). A runtime `warning` on the shared board read **and** a `bridge:check` board-visibility probe surface it; a read hitting the **200-card cap** is warned too. Non-transient (never a 5xx retry-storm); a genuine no-match stays quiet.
- **Durable write-or-throw + boot-safe replay (#69 / #2055, #2054).** The durable-reaction write path propagates failures (write-or-throw) so a lost write becomes a retryable 5xx rather than a silent drop; `bridge:replay` is hardened to run boot-safe.
- **Backlog hygiene (#68 / #2057, #2056, #2058).** Stored exception text is redacted, DB errors are surfaced cleanly, and the 413 envelope-size-limit response is documented.

### Dependencies

- Bump `gitleaks/gitleaks-action` 2.3.9 → 3.0.0 (#60).
- Bump `laravel/pao` (dev) 1.0.6 → 1.1.0 (#61).

### Operator notes

- **BREAKING — migrate custom classifiers** to the `ClassifyContext` signature before updating (in-tree usage is already migrated). After updating, run **`php artisan bridge:check`** — it now validates each classifier's signature out-of-process and names a stale one instead of fataling. **No DB migration.**
- `bridge:check` now also **probes that the writeback token can see each mapped board** (0 cards / 200-cap ⇒ a loud warning, never a check failure). Opt-in posture unchanged: no `writeback.json` ⇒ writeback off.

## [0.22.0] - 2026-06-05

**Release card-promotion for board 8 + the auto-tag workflow now publishes a GitHub Release.** PRs #59, #62 since v0.21.0.

### Added

- **Release card-promotion to "released" for board 8 (DL-023, #62).** On merge to `main`, a new isolated workflow (`release-promote-cards.yml`) derives the shipped `DL-NNN` set deterministically from `git log <prev-tag>..HEAD` and moves each tracking card to the "released to main" stage (53) via `bin/promote-released-cards` — a generic, framework-free script (bash + curl + jq) shared with kanban-board. Closes the gap where a bundled release PR carries no single DL token, so the bridge's own webhook writeback couldn't advance these cards. Idempotent, best-effort per card, with a loud empty-board guard (refuses if the token can't see the board). Per-repo config in `.release-pr.json` `.promote`.

### Changed

- **The auto-tag workflow also publishes a GitHub Release (#59).** On merge to `main` it now creates a GitHub Release from the version's `docs/CHANGELOG.md` section, not just the tag — so the repo's Releases page matches the tags.

### Operator notes

- **Board-8 promotion requires a `KANBAN_WRITEBACK_TOKEN` Actions secret** whose kanban user is a **member of board 8**; without it the step refuses loudly (never a silent no-op). No migration, no new runtime env keys. The promote job needs no PHP/composer (`jq`/`curl`/`git` only).

## [0.21.0] - 2026-06-01

### Changed

- **BREAKING — `Classifier::classify()` gains a required final `AgentConfig $agent` parameter (DL-022).** The dispatcher already invokes `classify()` once per subscribed agent; now it passes that serving agent, so a classifier can make **per-agent (recipient-aware)** decisions — e.g. drop an event not addressed to the serving agent (keyed on `$agent->agentName` / `$agent->identity`). **Every custom classifier must add the parameter** to its `classify()` signature (and thread it through any `parent::classify()` call) or it fatals on load (`Declaration … must be compatible`) — a default cannot avoid the break (PHP rejects a narrower implementor signature). The three in-tree classifiers + all docs are updated. Keeps recipient-addressing *policy* in the operator's classifier rather than the bridge core (option 1 over a dispatcher-side label filter). See `docs/customization.md` § Per-agent (recipient-aware) classification.

## [0.20.0] - 2026-06-01

**The GitHub-PR → kanban card-move writeback (FR #2016) — the bridge's first writeback, otherwise still surface-only/one-way.** Opt-in; **off by default** (absent `writeback.json` ⇒ no-op, no behaviour change).

### Added

- **The card-move writeback (DL-009 design → DL-018/019/020/021).** A GitHub `pull_request` webhook deterministically moves a kanban card to a stage — no agent in the loop:
  - **`DurableReaction` contract + durable-first dispatch (DL-018).** A handler whose side effect must not be silently dropped runs before the best-effort handlers, and its failure propagates (→ 5xx → redelivery) instead of becoming a note. Plus a global-echo seam (`BRIDGE_GLOBAL_ECHO_IDS`) so the bridge's own machine writes never loop back.
  - **`writeback.json` policy + `KanbanClient` + a dedicated least-privilege writeback token (DL-019).** Per-install repo→board+stage mapping in the config dir (not tracked config); the move authenticates with a `0600` `writeback-token` distinct from the broad provisioning token.
  - **`KanbanMoveCardHandler` (DL-020)** — durable, **idempotent** (no-op if already in stage), with a **belongs-to-mapped-board** security guard and a transient-vs-permanent failure split (a kanban 5xx retries; a 4xx / refusal / malformed payload logs + no-ops, never 5xx-storms).
  - **`GitHubPrCardMoveClassifier` (DL-021)** — derives the move outcome from **GitHub-controlled fields only** (`action` + `pull_request.merged` + `base.ref`, never the title) and correlates the card by the `DL-NNN` token in the PR title/branch.
  - **`docs/writeback.md`** — the operator runbook (token, `writeback.json`, the classifier agent, the repo webhook).
- `bridge:check` validates `writeback.json` + the writeback token. New: `BRIDGE_GLOBAL_ECHO_IDS` env; `<config_dir>/writeback.json`; `<secret_dir>/<provider>/writeback-token`.

### Operator notes

- **Writeback is OFF until configured** — no migration, no change for existing installs. To enable, see `docs/writeback.md`: place `writeback.json` + a least-privilege token (whose kanban user is a member of the mapped board), run a github-subscribed agent with `classifier.class: …\GitHubPrCardMoveClassifier`, and add the repo webhook. **First outward-facing write — a real-install soak is recommended before relying on it.**

### Verification

- PHPUnit **310/310** (SQLite + MariaDB 10.6/11) · Pint clean · PHPStan level 7 0 errors · doc-refs + `composer audit --locked` green. Every phase passed an adversarial security review (ground-truthed against the kanban-board source) before merge.

## [0.19.0] - 2026-05-31

**The architecture-review hardening tail (B-13…B-19 + the B-9/B-10 partials) — security tightenings, CI gates, a config reference, and cleanups.** No migration; the only operator-facing change is one new opt-in env (see Security).

### Security

- **DL-014.** Three fail-closed tightenings: `ProviderName`/`ScopeId` gain the `D` regex anchor (a trailing-newline can't slip a second line past `$`); a **classifier-supplied** `channel_push` socket must sit under `BRIDGE_CHANNEL_ALLOWED_SOCKET_DIR` (refused when unset — an agent's own `channel.socket` is exempt); and `bridge:check` warns when the config / secret dir is group/world-accessible. ⚠ A custom classifier that hand-emits a `channel_push` with its own `socket:` now needs `BRIDGE_CHANNEL_ALLOWED_SOCKET_DIR` set — the no-classifier `route_intents` path and the reference installs are unaffected. (#39)

### Added

- **DL-015.** `bridge:check` fails if a configured provider has no adapter (`config.providers ⊆ WebhookAdapterFactory::SUPPORTED`); a `composer audit --locked` CI job (every PR + nightly) reds the build on a known dependency CVE. (#40)
- **`docs/config-schema.md` (B-11)** — a current-state config reference: every per-agent YAML key + every `BRIDGE_*` env, with type, default, and fail-closed-vs-warn behaviour. The *what*, to the decision log's *why*. (#43)

### Changed

- **DL-017.** `AgentConfig`'s identity triple (`kanban_user_id`/`github_user_id`/`github_login`) is grouped into an `IdentityConfig` DTO (the DTO idiom of `EchoSuppressionConfig`/`ChannelConfig`); the constructor drops 11→9 args. No runtime change. (#42)
- **DL-016.** One `BridgePaths::ensureDir` (0700) replaces four inline `mkdir` sites so the mode can't drift; `CheckCommand` extends `BridgeCommand` (completing the base-class consolidation); trimmed dead Python-provenance docstrings; documented the deliberate no-`TrustProxies` posture. No runtime change. (#41)

### Docs

- Architecture review marked up end-to-end (every B-item ✅ Addressed / ⚠ Partial / Declined) with a **Deferred / declined (with justification)** section for the speculative/churn items not taken (B-7, B-8, B-12, B-20, and the B-9 file-splits / B-10 validator-extraction). (#44, #42)

### Verification

- PHPUnit **272/272** (SQLite + MariaDB 10.6/11) · Pint clean · PHPStan level 7 0 errors · doc-refs guard + `composer audit --locked` green. Every item passed an adversarial review loop before merge.

## [0.18.0] - 2026-05-31

**The architecture-review hardening pass (B-1…B-6) + config-level channel auth.** ⚠ **Breaking — fail-closed tightening on secrets + `spawn_detached`, and a DB migration; see Security/Changed below.**

### Added

- **Config-level channel auth — `channel.auth.token_path` (DL-008).** The no-code `route_intents` push can now carry an `Authorization: Bearer <token>` (a file path, chmod 600, enforced + read at point-of-use, never placed in a payload) so a cross-user / loopback-TCP channel server is authenticated — not just a 0600-UDS whose filesystem perms were the only boundary. Rejected at config load unless `channel.url` is set (the token surface stays on the TCP transport). (#27)
- **`bridge:prune` retention command (DL-012).** `--older-than=Nd` deletes old `webhook_events` (cascading `agent_dispatches`) and trims old inbox lines + bounds their seen-cursor; `--null-payloads-older-than=Md` sheds 50–100 KB payload bodies past the replay window while keeping the row's dedup-gate + audit metadata; `--dry-run`. The one (optional) periodic job in the otherwise daemonless design — nothing on the dispatch path depends on it. (#32)
- **Doc-sync CI guard — `bin/check-doc-refs.php` (DL-013).** A CI step that fails the build if a `CLAUDE_*.md` doc names a PHP file path / `App\` FQCN that no longer exists (with a `(removed in …)` / `~~strikethrough~~` escape hatch). Converts the soft "doc-sync in every PR" rule into an enforced one. (#34)
- **DL-009 — durable-reaction + writeback-authz seam designed (design-only, no runtime change).** The typed contract a future GitHub-PR→card-move writeback builds against: a durable-reaction handler class (failure → 5xx/retry, not a swallowed note), a dedicated least-privilege writeback token, operator-config-only repo→board mapping, and global echo-suppression of the writeback identity. (#29)

### Security

- ⚠ **Unified 0600 secret-perms enforcement across every secret reader (DL-010).** DL-008's SSH-style `mode & 0o077` gate now also covers the two higher-value secrets — the per-`(provider, scope)` HMAC secret and the kanban API token — plus the provisioner's reconcile re-read, via a shared `SecretFile` (live-perms, fail-closed). **A group/world-readable HMAC secret now returns `500 secret_perms_insecure` (kanban-board holds + redelivers); a readable API token fails `bridge:provision`.** Safe direction — the provisioner already writes `0600`, so a correctly-provisioned install is unaffected; `bridge:check` warns on all three at preflight (G-016). (#30)
- ⚠ **`spawn_detached` is opt-in + executable-allowlisted + shell-free (DL-011).** The highest-blast-radius handler is **no longer registered unless `BRIDGE_SPAWN_ENABLED=true`**, and the program (`cmd[0]`) **must be in `BRIDGE_SPAWN_ALLOWLIST`** (absolute paths). Execution moved from an `exec()` shell string to `proc_open` with an argv array + `setsid -f` — no `/bin/sh`, so no shell-metacharacter surface. Allowlist fixed-purpose wrapper scripts, never an interpreter (`php`, `bash`, `git`, …), which would reopen RCE via `cmd[1..]`. (#31)

### Changed

- ⚠ **Inbox dedup moved off the synchronous hot path + `webhook_events.payload` is now nullable (DL-012).** `IntentLog` no longer scans the whole inbox file per intent (an O(file) cost that grew on calendar time and inflated webhook latency); idempotency is the upstream `agent_dispatches.processed_at` gate plus a read-side id-collapse in `bridge:inbox`. **Run `php artisan migrate` on deploy** (the payload-nullable migration). `BridgePaths::jsonlContainsId` removed (dead). (#32)

### Removed

- **Dead `ChannelName` validator deleted (B-6).** `channel.name` was removed in DL-007 but `app/Bridge/Validation/ChannelName.php` survived — referenced nowhere in app code, kept green only by its own tests, so it looked load-bearing. Deleted with its tests + the four `CLAUDE_*` doc references. (#33)

### Docs

- **Architecture review** (`docs/reviews/2026-05-31-architecture-review.md`) across scalability / maintainability / security, with every backlog item (B-1…B-6) now marked addressed. (#28)
- **Doc drift fixed (DL-013 / B-5):** the `CLAUDE_*.md` onboarding map no longer describes the deleted `ProviderApiConfig` or the removed `agents.json` / `identity.self` as current; `CLAUDE_GOTCHAS.md` G-015 rewritten to the post-DL-007 reality. (#34)

### Verification

- PHPUnit **262/262** (SQLite + MariaDB 10.6/11) · Pint clean · PHPStan level 7 (`app/Bridge`) 0 errors · doc-refs guard green. Each item passed an adversarial review loop before merge.

## [0.17.0] - 2026-05-30

**Install-easing docs + a CI fix, surfaced while wiring live channel push on a real install.**

### Changed

- ⚠ **Channel-server example renamed** `examples/channel-servers/kanban-bridge-channel.mjs` → `agent-webhook-bridge-channel.mjs`, to match `package.json` (`bin`/`start`), the README, and `.mcp.json.example` — all of which already referenced the new name — plus the bound socket prefix and the project name. **Breaking for anyone whose `.mcp.json` points at the old filename:** update the `args` path to `agent-webhook-bridge-channel.mjs`, or the channel server won't launch (and `npm start` was already broken before the rename). (#22)

### Fixed

- **CI: docs-only PRs are no longer permanently blocked.** The three Laravel Tests jobs are required status checks but were `paths-ignore`'d on docs/examples PRs, so on those PRs the required check never reported and the PR was un-mergeable without an admin override. Removed the filter so the (fast) checks always run + report on every PR. (#23)

### Docs

- Cross-install peer-YAML note (an agent naming a peer in `treat_as_signal`/`treat_as_echo` that runs in a *separate* install needs a local author-only `<peer>.yml`, since the v2 registry is per-install); an explicit "channels are CLI-only — no config auto-load" statement + a launcher script (`examples/start-channel-session.sh`); an "Upgrading to v0.16 (config schema v2)" checklist; an FPM-reload-needs-sudo note. (#22)

### Verification

- PHPUnit **229/229** (SQLite + MariaDB 10.6/11) · Pint clean · PHPStan level 7 (`app/Bridge`) 0 errors.

## [0.16.0] - 2026-05-30

**Per-agent inbox surfacing for a single multi-agent install, then a config-schema cleanup that kills the duplication it exposed.** ⚠ **Breaking — operators must migrate their per-agent config (see below).**

### Added

- **Per-agent inbox surfacing (DL-006).** A single install fanning out to N agents can now give each agent a clean view. Every staged inbox line carries the serving `agent`; `BRIDGE_INBOX_LAYOUT=shared|per-agent|both` (default `shared`); `bridge:inbox --agent <name>` reads that agent's file (or the shared file filtered by tag) with an isolated per-agent seen cursor; `BRIDGE_DEFAULT_AGENT` for a bare invocation. Cross-user read via a group convention (`BRIDGE_STATE_DIR` outside the secret dir + `BRIDGE_INBOX_GROUP`/`BRIDGE_INBOX_FILE_MODE`, requires `per-agent` layout). `channel.route_intents` (+ `channel.url`) routes each staged intent to an agent's channel without a hand-coded `channel_push`. `--agent` added to `bridge:stats` / `bridge:inspect`. (#16)
- **`bridge:inbox` cursor-advance reliability fix (DL-006).** Advances the seen cursor only when output can reach a consumer — wiring it on a `Stop`/`Notification` hook no longer silently eats intents. `--no-cursor-advance` for a non-advancing peek. (#16)

### Changed

- ⚠ **BREAKING — config schema v2 (DL-007).** Kills config duplication the surfacing work exposed.
  - The `<agent>.yml` **filename is the agent name** — `identity.self` removed.
  - Per-**install** settings moved to `.env`/`config/bridge.php`: `BRIDGE_RECEIVER_BASE_URL` and provider API base URLs (`BRIDGE_KANBAN_API_BASE_URL`); the per-agent YAML keeps only an optional `api.<provider>.token_path` override.
  - Per-agent identity folded into the YAML (`identity: {kanban_user_id, github_user_id, github_login}`); the registry is built by scanning the YAMLs. **`agents.json` → optional `shared-identities.json`** (shared accounts only).
  - **`BRIDGE_DIR`** collapses `BRIDGE_CONFIG_DIR`+`BRIDGE_SECRET_DIR` (both still overridable). API **token by convention** `<secret_dir>/<provider>/token` (per-agent override allowed). **`channel.name` removed** (dead field).
  - An agent's own echo-suppression ids are **auto-seeded** from its `identity` (`echo_suppression: {}` is the common case). Fail-closed: a malformed YAML 5xx's; an unknown `treat_as_signal` name throws. `bridge:check` validates the whole config surface (classifier FQCN, endpoint URLs, token/secret presence, signal names, default agent) with actionable messages.

  **Migration:** move ids into each `<agent>.yml`'s `identity` block; drop `identity.self` / `receiver` / `api.<provider>.base_url` / `channel.name`; set `BRIDGE_DIR` + `BRIDGE_RECEIVER_BASE_URL` + `BRIDGE_KANBAN_API_BASE_URL`; move the API token to `<secret_dir>/<provider>/token`; rename `agents.json` → `shared-identities.json` keeping only the `shared_identities` block. See `CLAUDE_DECISIONS.md` DL-007 and the rewritten `examples/sample-config/*`.

### Internal

- A bad `classifier.class` FQCN is locked as treatment-A (record + ack 200, not a 5xx) by a regression test, and surfaced early by `bridge:check`. (#17)
- Consolidation: one canonical JSONL reader (`BridgePaths::readJsonl`/`jsonlContainsId`/`agentInboxLines`); new `UrlValidator` + `TokenPath` + a `BridgeCommand` base (`strOption`); `ProviderApiConfig` removed. (#16, #18)

### Verification

- PHPUnit **229/229** (SQLite + MariaDB 10.6/11) · Pint clean · PHPStan level 7 (`app/Bridge`) 0 errors.

## [0.15.0] - 2026-05-30

**Custom-handler registration now works as documented, and per-agent echo suppression is restored for a shared upstream identity.** Both reported by a peer integrator.

### Added

- **Per-agent echo suppression for a *shared* upstream identity (DL-005).** New optional `ClassifyResult::$reattributedActor`. When several agents share one upstream account (`shared_identities`), the registry resolves `Actor.name = null` on purpose, so the pre-classify echo gate can only match the raw id (`treat_as_echo_ids`) — all-or-nothing across every agent. A classifier that recovers the true author (FROM:-line / repo-scope) now returns it on the result; **after** classify, the dispatcher re-runs the **same** per-agent echo check on it, suppressing only the serving agent's own write while a different shared-id agent's write still surfaces. The classifier reports *who* authored the event; the dispatcher decides *is that me?* per agent — so the `Classifier` contract and the "classifiers don't filter" invariant are both unchanged, and `null` (every shipped classifier) is a no-op. Completes the `shared_identities` design (DL-002). (#12)

### Fixed

- **The documented custom-handler extension point is functional again (DL-004).** `HandlerRegistry` is now bound as a container **singleton** in `BridgeServiceProvider`, and `DispatchService` resolves it from the container instead of constructing its own. So `afterResolving(HandlerRegistry::class, fn ($r) => $r->register('x', new XHandler))` in a `ServiceProvider` — the path `docs/customization.md` always advertised — registers onto the **exact** instance the dispatcher uses, with no provider-ordering requirement. Previously the only working path was re-binding `DispatchService` wholesale and duplicating its constructor wiring (fragile across upgrades). (#11)

### Verification

- PHPUnit **205/205** (SQLite + MariaDB 10.6/11) · Pint clean · PHPStan level 7 (`app/Bridge`) 0 errors.

## [0.14.0] - 2026-05-30

**Same-event ReactionTarget coalescing restored (DL-003), plus a post-v0.13 divergent-duplication cleanup.**

### Changed

- **Same-event ReactionTarget coalescing by `debounce_key` is enforced again (DL-003).** Within one `ClassifyResult`, targets sharing a `debounce_key` collapse last-wins at dispatch time, so a classifier emitting several targets to one bucket fires that handler once. The v0.12 rewrite kept the field, docblocks, schema, and handler-log key but dropped the implementation; this restores it. Built-in classifiers emit ≤1 target so are unaffected — the fix protects the custom-classifier extension point. **`debounce_seconds` is advisory metadata only** (carried to the handler/handler-log); the synchronous model does not enforce a cross-delivery time window — redelivery dedup is the `webhook_events` `UNIQUE(delivery_id)` gate + upstream retry. (#7)
- **Docs/comments de-staled after the v0.12 rewrite.** Removed references to the deleted Python tree (`lib/*.py`, `receiver/webhook.php`, `examples/classifiers/*.py`, `DebounceTracker`, drain-pass, `frozenset`, `payload_dict()`) from validator/middleware/classifier/provision docblocks and the `event-schema.json` / `reaction-target-schema.json` docs; corrected `event-schema.json`'s actor.id (GitHub is `sender.id` since v0.13.0, not `sender.login`). Reworded dangling `DL-074` citations (the repo's decision log holds DL-001…DL-003). (#5)

### Internal

- **CI: shared PHP setup extracted into a local composite action** (`.github/actions/setup-app`) so the SQLite and MariaDB test jobs can't drift; dropped the dead scaffold-era PHPStan guard. No change to test coverage. (#6)

### Verification

- PHPUnit **200/200** (SQLite + MariaDB 10.6/11) · Pint clean · PHPStan level 7 (`app/Bridge`) 0 errors.

## [0.13.0] - 2026-05-30

**Agent recognition keys on the immutable GitHub account id, not the renameable username (DL-002).** A username rename is now a non-event.

### Changed

- **GitHub actor identity is now `sender.id` (immutable numeric), not `sender.login`.** `GitHubAdapter` extracts the numeric account id into `actor_id`; `AgentRegistry::actorFromEvent($provider, …)` is **provider-aware** (kanban events match `kanban_user_id`, GitHub events match `github_user_id`), so the same integer on different axes never cross-matches. Keying on the immutable id means a GitHub username rename no longer breaks recognition or echo-suppression. (DL-002, #1)
- **`agents.json` → `schema_version: 2`.** Per-agent identity is `kanban_user_id` + `github_user_id` (both immutable ints). A GitHub account shared by multiple agents is declared **once** under a top-level `shared_identities[]` block (`{github_user_id, github_login?, agents[]}`) → resolves to `Actor.name = null` (custom-classifier re-attribution, preserving the shared-login collision-bypass behavior byte-for-byte). `github_login` is now a **display-only label** with a one-line stale-login drift warning.

### Breaking

- **`agents.json` must be migrated to `schema_version: 2`.** A v1 file is not parsed — `AgentRegistry::load` warns with a migration note and degrades to an empty registry. Replace any `github_login` matching key with the immutable `github_user_id`; declare shared accounts under `shared_identities`. Kanban-only registries migrate by bumping the version number alone. No in-code compatibility shim (single-operator project).

### Verification

- PHPUnit **199/199** (SQLite + MariaDB 10.6/11 matrix) · Pint clean · PHPStan level 7 (`app/Bridge`) 0 errors · two adversarial review passes, zero must-fix.

## [0.12.0] - 2026-05-29

**The Laravel rewrite — a single synchronous app, shipped as a fresh repository.** See **DL-001** for the full rationale.

### Changed

- **Architecture: one Laravel 13 app, synchronous in-request dispatch.** The v0.1–v0.11 design was a five-layer asynchronous pipeline (PHP HTTP receiver → MariaDB event queue → Python consumer drained by a per-minute cron → classifier → inbox surfacing). v0.12 collapses that into a single Laravel app: a webhook is HMAC-verified in middleware, the adapter parses the envelope, and `DispatchService` runs classify → stage to `inbox.jsonl` → run handlers **synchronously in the same request**, returning `200` only when every subscribed agent is processed. **No queue worker, no consumer cron, no daemon.**
- **At-least-once is borrowed, not built.** Any internal/durability failure throws → Laravel returns `5xx` → kanban-board's webhook retry redelivers; `inbox.jsonl` is the durable pull-backstop. The three-way failure treatment (classify-throws → record + `200`; inbox-staging-throws → `5xx`; handler-throws → per-agent done-with-note) lives in `DispatchService` / `WebhookController`.
- **Stack:** PHP 8.3 / Laravel 13 / Eloquent over MariaDB 10.6+ (SQLite for tests). The Python tree (`lib/`, `bin/`, the pytest suite) and the standalone PHP receiver are gone; the per-agent YAML loader, adapters, classifiers, handlers, and HMAC verification are ported to `app/Bridge/*`. CLIs are now `php artisan bridge:*` (`check`, `provision`, `inbox`, `inspect`, `replay`, `stats`).
- **Config:** the DB connection and HMAC-secret directory move to Laravel's `.env` / `config/bridge.php` (one install per agent); per-agent YAML keeps `identity` / `api` / `receiver` / `subscriptions` / `echo_suppression` / optional `classifier.class` (FQCN) / `channel` / `surface`. Migrated v0.11 YAMLs load unchanged (leftover `db` / `secrets` keys are tolerated and ignored).

### Verification

- Pint clean · PHPStan level 7 (`app/Bridge`) 0 errors · PHPUnit **188/188** (SQLite + MariaDB 10.6/11 matrix in CI).
