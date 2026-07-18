# FR / Design spec — #4553: the general `github_issue` by-ref issue→card writeback (roundtable #18(b), full vision)

**Status:** DRAFT — pre-design-review. Author: kanban-solo (bridge + kanban SME).
**Card:** bridge board 8 #4553. **Roundtable:** PupFuzz/agent-roundtable#18(b).
**Operator directive (2026-07-18):** build the *full* (b) vision — the kanban-side `github_issue` by-ref
correlation primitive + a bridge sibling classifier for general issue→card with a 3-consumer card-shape config.
(kanban-solo recommended closing #4553 as substantially-delivered; operator overruled → full build. Executing.)

---

## 0. Scope delimitation (the card's mandated first job — grounded, not assumed)

Grounded at source (bridge dev @ 96adf66; kanban dev @ DL-211; full #18 thread). **#18's owned surface is
substantially shipped**; this spec builds the one genuinely-uncovered-and-now-directed leg:

| #18 leg | Status | Source |
| --- | --- | --- |
| (a) draft→overlay (`block_reason` set/clear) | ✅ shipped | DL-193 |
| (a) draft→**stage move** (`drafted`/`ready` outcome) | ❌ unbuilt, **explicitly deferred** — no consumer wants it (3-seat + both-operator ruling: is_draft→overlay fleet-wide) | `WritebackConfig::OUTCOMES` has no `drafted`/`ready` |
| parked auto-unpark on work-begins | ✅ opt-in shipped (`unpark_from_stages`) + a fleet round parked on #14 | DL-194 |
| (b) CREATE **coord-prefixed** issue→card | ✅ shipped (tag-correlated, prefix set `{BRIEF,ANNOUNCE,QUERY,REVIEW,TASK}`) | DL-198 |
| (b) MOVE **coord-prefixed** issue→card | ✅ shipped (guarded fleet default, live-witnessed) | DL-204 |
| kanban `github_issue` by-ref **primitive** (enum value + derive map + endpoint) | ✅ **ALREADY SHIPPED** — kanban DL-203, card #4161, v0.32.0 (2026-07-13); `system` is an OPEN free-string (shape-regex only), so additive, no hard-gate | kanban `ExternalReferenceNormalizer` L26/L32/L48 |
| (b) **general** (non-recognized-prefix) issue→card **bridge classifier** keyed on `github_issue` by-ref + 3-consumer card-shape config | ❌ **unbuilt — THIS SPEC (bridge-only)** | bridge has no `github_issue` caller yet |
| swimlane-on-move | routed to #14 A4 (not #18) | — |

**In scope (this spec) — BRIDGE-ONLY:** a general issue→card writeback classifier family (bridge) that cards
**any** issue per a per-install card-shape config, correlated by the **already-shipped** kanban `github_issue`
by-ref, composing with the shared reconcile as backstop. The kanban half is done; the only kanban-side
prerequisite is per-board custom-field registration (`issue_number`/`issue_url`), which each consumer does at
adopt-time (board config, not code).

**Out of scope (named, so nobody adopts by omission):** the draft→stage-move leg (deferred); the parked
auto-unpark fleet round (#14); swimlane-on-move (#14 A4).

---

## 1. Ground truth (verified at source)

### 1.1 Kanban by-ref correlation (the primitive being extended)
- `external_references[].system` is a cross-system **contract** but an **OPEN free-string** at runtime:
  shape-validated only (`SYSTEM_REGEX = ^[a-z0-9_]{1,32}$`), backed by a code-const registry — no `Rule::in`,
  no DB enum/CHECK. Per `docs/api-stability-contract.md`, **adding** a system is *additive* (only
  renaming/repurposing an existing slug is breaking). The set is now `{dl, github_pr, github_issue}` — the
  `github_issue` value **already shipped** (kanban DL-203). The bridge integration-contract copy
  (`docs/kanban-integration-contract.md:31`) still lists `{dl, github_pr}` → **stale, fix in the build PR**
  (add `github_issue`).
- By-ref endpoint: `GET /api/v3/boards/{b}/tasks/by-ref.json?system=&ref=&source=` — server-canonicalizes
  the ref, returns a **collection** (N:1), indexed/O(1). **The `source` qualifier already exists** (kanban
  DL-163) for multi-repo boards → **issue-number-across-repos collision is already solved on the read side.**
- `task.created` carries a bounded `payload.card` snapshot with `external_references[{system,source,ref}]`
  (kanban DL-164 / bridge DL-168), `system` ∈ the same enum — a classify-time state read with no token.
- Write side: correlation custom fields (`dl_number`/`pr_number`) are **registered queryable custom fields**
  that kanban derives into the first-class `task_external_references` table; POST 422s on unknown payload keys.
- `ExternalReferenceNormalizer` canonicalizes per-system (`DL-42`/`42`/`042` → `42`), server-side.

### 1.2 Bridge caller side (already primed — reuse, don't rebuild — canon #5)
- `KanbanClient::findCardsByRef(boardId, system, ref, source)` — **already generic over `system` + already
  passes `source`.** Adding `correlateIssue()` = a thin mirror of `correlatePr()` (`KanbanClient.php:154`).
- `KanbanClient::createCard(...)` already accepts name/payload/tags/swimlane/description/priority/external_link.
- `CardCollapse` (DL-198) — shared raced-duplicate collapse kernel; reuse for the by-ref create.
- `CoordinationClassifier` families `coord-card-create` (DL-198) / `coord-card-move` (DL-204) — the tag-keyed
  siblings this new by-ref family sits beside. Emit `kanban_coord_card` / `kanban_coord_card_move`.

---

## 2. Kanban half — ALREADY SHIPPED (kanban DL-203); verify-only

Verified at source (kanban `origin/dev`): the `github_issue` by-ref primitive is complete —
- `ExternalReferenceNormalizer::SYSTEM_GITHUB_ISSUE`, `PAYLOAD_KEY_TO_SYSTEM['issue_number' => github_issue]`,
  `SOURCE_URL_KEYS` includes `issue_url`, `NUMERIC_SYSTEMS` includes it (canonicalizes like `github_pr`);
- the by-ref endpoint accepts it (`system` is shape-regex-validated only — an OPEN free-string, **not** a
  closed enum / `Rule::in` / DB CHECK; adding a system was additive per `docs/api-stability-contract.md`);
- write side derives `(system=github_issue, source=<owner/repo>, ref=<num>)` from the `issue_number` payload
  custom field at the `TaskMutator` chokepoint, `source` from `issue_url`/`html_url` (DL-163);
- `task.created` snapshot enum admits it; docs + tests all landed.

**So there is NO kanban code build.** The only kanban-side item is a **per-board prerequisite**: the target
board must register `issue_number` (+ `issue_url` for `source`) as queryable custom fields, or a create POST
422s / by-ref never derives. Each consumer does this at adopt-time (aimla board-10 already carries the
by-ref keys per the #18 thread; sola completes board-2/3 at build-round open; I do mine). Not a hard-gate,
not code — board config, verified at validation.

---

## 3. Design — bridge half: the general issue→card classifier family

**Consolidate, don't sibling (R1 finding 4, canon #5/#7).** NOT a new pair of families. Extend the existing
`coord-card-create`/`coord-card-move` families (+ their `KanbanCoordCardHandler`/`KanbanCoordCardMoveHandler`
kernel) with **ONE config axis** and a **per-ISSUE correlation key** (R2 finding 1/2 collapsed the two-axis
model — see below):

- **`issue_population`** = `prefixed` (default, today's `stableId` recognized-prefix set) | `all` (also cards
  non-prefixed issues). There is **no `correlation_mode` axis** — the correlation key is *derived per issue*,
  not chosen per mapping (R2 finding 1 showed a per-mapping by-ref mode re-opens the DL-198 race; R2 finding 2
  showed `{by-ref, prefixed}` is the incoherent mirror of `{tag, all}`). Both collapse into:

**Per-issue correlation key (the R2 blocker fix + one edge R2 didn't raise).** For each issue, key selection
is by whether the title is prefixed, NEVER by mapping config:
- **Prefixed issue** (`stableId` non-null) → the `id:<sid>` **tag** is the idempotency + collapse key, exactly
  as DL-198 — the SAME key the tag-keyed reconcile uses, so no divergence, no double-card (this is why R2's
  finding 1 race cannot occur: the prefixed pre-check stays on the shared tag key).
- **Non-prefixed issue** (`stableId` null; only under `all`) → the `github_issue` **by-ref** key. The reconcile
  ignores these (prefix-blind), so the bridge is sole mover — by-ref idempotency + collapse, no other carder.
- **Unified pre-check (closes the prefix-change edge):** a create pre-checks **both derivable keys** —
  `cardsByTag(sid)` when prefixed AND `correlateIssue(num, source)` when `issue_number` is registered — and
  skips if **either** returns a card. On create, stamp **every eligible key**: the tag when prefixed AND
  `issue_number` in payload when `population: all`. So under `all` a prefixed card is **dual-keyed** (tag +
  ref) → discoverable by the reconcile (tag) AND by by-ref; and an issue whose title gains/loses a prefix
  between `opened` and `reopened` is still found by the other key (an edge a per-mapping mode would double-card).

**Default byte-identical:** `population: prefixed` (default) ⇒ prefixed-only, tag key, `issue_number` NOT
stamped, empty payload + `external_link` — i.e. **exactly DL-198**. Only `population: all` adds the by-ref
complement and the `issue_number` stamp.

### 3.1 Create + move
- Create (`issues.opened`/`reopened`): unified pre-check (above) → skip if a card exists; else `createCard`
  stamping every eligible key + `external_link` = issue URL. **`source` derives from `external_link`**
  (verified: kanban `ExternalReferenceNormalizer::sourceFor` uses payload url keys OR `external_link`;
  `repoFromGitHubUrl` matches `/issues/` URLs) — no separate `issue_url` payload key needed for `source` (R2
  confirmed no source-null double-create on this axis). Post-create `CardCollapse` on whichever key(s) apply.
- Move (`issues.closed`→terminal / `reopened`→revive): correlate by the same per-issue key, move all matches.
  Reuse the DL-204 revive actor-gate + DL-163 terminal-safety **verbatim** (population-agnostic; R1 finding 3).
- **Handler fork made explicit (R2 finding 5) — each gets a RED-on-revert test:** (a)
  `KanbanCoordCardHandler`'s sid-required guard (`:50-54`) becomes **conditional** — sid required only on the
  tag/prefixed path; the by-ref/non-prefixed target carries an empty sid legitimately; (b) the create payload
  carries `issue_number` on the by-ref path (`:98` is `[]` today); (c) idempotency/collapse key per-issue
  (above); (d) `coordCardMoveFamily` (`:822-825`) + `KanbanCoordCardMoveHandler` (`:88-91`) correlate by-ref
  and do NOT early-return on a null sid for the non-prefixed population. This is real consolidation only if the
  fork is designed + tested, not hand-waved — hence the explicit enumeration.

### 3.2 Card-shape config
- **Reuse the mapping's existing `board_id`** (R1 finding 8), `coord_card_stage_id` (create stage), and
  `coord_card_terminal_stage_id` (move terminal, which carries the DL-200 mandatory cross-config compare — R1
  finding 3; a new terminal key would re-open the DISAGREE-forever thrash un-guarded).
- **Label→lane is DEFERRED to the reconcile for v1 (R2 finding 4).** A single `coord_card_stage_id` cannot
  express four `stage:*` columns, and a full `coord_card_stage_by_label` map with fail-closed validation is
  scope the real-time-existence goal doesn't need: the bridge creates the card **at the fixed
  `coord_card_stage_id`** (real-time *existence*), and the reconcile — which already maps
  `stage:now|next|later|maybe` → lane via `reconcile_simple_board` — refines the *lane* on its next pass
  (backstop for *placement*). Existence is real-time; lane placement lags to the reconcile (acceptable —
  {Next,Later,Maybe} are human-curated anyway). Whether any consumer needs **real-time** lane-at-create (the
  full `stage_by_label` config) is roundtable Q2; recommend deferring to a fast-follow.
- `title` = issue title verbatim; `priority` per-config; fail-closed at load (create with no stage can't POST).

### 3.3 The overlap is the RECONCILE, not the bridge (R1 finding 1 / R2 finding 1)
The "structurally impossible" claim is **withdrawn**. The `coord.kanban_common` reconcile is a third carder,
tag-keyed. The per-issue key model (§3 head) resolves the **prefixed** overlap completely: prefixed cards are
tag-keyed (dual-keyed under `all`), so bridge and reconcile share the key — neither dups the other (this is
DL-198's shipped, live-witnessed guarantee, unchanged). The remaining hazard is only the **non-prefixed**
population under `all`: the prefix/tag reconcile ignores it, so there is **no existing backstop** — the bridge
is sole mover unless each consumer extends its reconcile to correlate by-ref AND card all issues. That is
**unbuilt consumer-side work the bridge cannot verify** (DL-200: `bridge:check` reads only the terminal via
`$COORD_CONFIG`, not the reconcile's correlation mode). **Load-bearing cross-seat gate → roundtable Q1**; and
`bridge:check` emits a **distinct warn** under `population: all` that the non-prefixed set has no reconcile
backstop, so the DL-200 terminal-"agree" line is never misread as backstop coverage (R2 finding 7).

### 3.4 Echo / self-wake + preflight
- `implements EmitsWritebackReactions` (DL-203 classify-then-strip covers a self-authoring seat);
  `consumedEventTypes()` contributes `issues.{opened,reopened,closed}`.
- **`bridge:check` warns when `population: all` is set but `identity_id` is null** (sibling of the DL-198
  `createCoordCards && identityId===null` warn, `CheckCommand.php:616`, keyed on the new axis).
- **DROP the `isAlreadyClassified(github_issue)` content-sniff (R2 finding 3).** It would be a fleet-wide triage
  behavior change that silently suppresses a legitimate human-filed issue-tracking card's triage wake on ANY
  install with `issue_number` registered — including the shipped `{prefixed}` default that never uses by-ref.
  The correct guard for the bridge's own by-ref echo is `identity_id` (the root cause — already warned). No
  content-sniff.
- **`issue_number`-registration preflight is FAIL-CLOSED (R2 finding 6):** when `population: all`, `bridge:check`
  **exits non-zero** (refuses to certify) if the board lacks `issue_number` — reusing `boardCustomFieldKeys`
  (`KanbanClient.php:445`), as the dependabot custom-field check does. Absent the field, kanban 422s every
  create as a *permanent* (un-retried) no-op → zero cards, silent except logs; a warn-only preflight leaves
  that live, so it must be a hard failure.

---

## 4. Partition contract (bilateral, #18) — with the honest dependency
Real-time primary (this bridge path) + shared `coord.kanban_common` reconcile backstop. **Prefixed** issues
share the `id:<sid>` tag key with the reconcile (§3 head) → covered, exactly as shipped DL-198. **Honest caveat
(R1/R2 finding 1):** for the **non-prefixed** `all` population the "reconcile also cards it / defers via
by-ref" leg is **unbuilt** on the consumer side and **`bridge:check` cannot verify it** — a consumer commitment
that must be pinned in the roundtable before `population: all` go-live (§6 Q1).

---

## 5. Build sequence (bridge-only)
1. Bridge path (§3) — extend the coord-card families with the `issue_population` axis + per-issue correlation
   key (tag if prefixed / by-ref if not) + the explicit handler fork (conditional sid-guard, `issue_number`
   payload on the by-ref path, per-issue idempotency/collapse, by-ref move leg) + reused stage/terminal keys +
   the two `bridge:check` preflights (`issue_number` **fail-closed** registration check, `identity_id` warn) +
   the non-prefixed no-backstop warn. **No `isAlreadyClassified` change.** Each forked step gets a RED-on-revert
   test. PR to bridge `dev`. (Kanban half already shipped — §2.) Doc-sync: fix the stale `{dl, github_pr}` enum
   at `docs/kanban-integration-contract.md:23,31` → add `github_issue` (canon #16).
2. Per-board prerequisite: confirm each validating board registers `issue_number` (§2).
3. Two-sided validation: aimla board-10 (first) + sola board-2/3 (second). Stamp DL on #4553 at PR time.

## 6. Open questions for the ROUNDTABLE design-review (cross-seat; drive to zero before build)
1. **[LOAD-BEARING] Non-prefixed backstop.** For `population: all`, will aimla/sola extend
   `coord.kanban_common`/`issues-sync` to correlate by-ref AND card all issues (so non-prefixed cards are
   backstopped), or is the accepted posture "bridge is sole real-time mover, no reconcile backstop for
   non-prefixed"? The bridge cannot verify this — it must be an explicit commitment. (Prefixed issues are
   backstopped regardless via the shared tag key.)
2. **Real-time lane-at-create vs reconcile-refines-lane.** v1 creates at the fixed `coord_card_stage_id` and
   lets the reconcile place the `stage:*` lane (existence real-time, lane lags one reconcile pass). Confirm the
   3 consumers accept that, or does any need real-time lane-at-create (the full `coord_card_stage_by_label`
   config, a fast-follow)? Also confirm "relabel not tracked (human/reconcile owns curation)."
3. **`[PROPOSAL]`/`[FR]` threads (my consumer case).** Covered by `population: all` (they're non-prefixed w.r.t.
   the recognized set) → by-ref cards, no `id:` tag, and rely on Q1's backstop decision.
4. **Migration vs coexistence.** The single-axis design keeps the shipped `prefixed` default byte-identical (no
   migration). Confirm no consumer wants a hard unify-on-by-ref now (option A) that would re-validate the live
   DL-198/204 path.

## 7. Design-review R1 dispositions (coord:design-reviewer, fresh-adversarial)
| # | Finding | Severity | Disposition |
| --- | --- | --- | --- |
| 1 | Bridge XOR ≠ structural double-card prevention; the reconcile is a third, tag-keyed carder | MUST-FIX | **Accepted.** Withdrew the "structurally impossible" claim. Dual-key tag-stamp for the prefixed overlap (§3.3); non-prefixed backstop elevated to roundtable Q1 as the load-bearing cross-seat gate. |
| 2 | Create must stamp the ref in payload or `source` never derives → shared-board double-create | MUST-FIX | **Partially accepted (corrected).** `issue_number` in payload is required (ref derivation) + a `bridge:check` preflight. But `source` DOES derive from `external_link` (verified `sourceFor`/`repoFromGitHubUrl` at kanban source) — the reviewer's "source never derives" over-stated; the shared-board double-create via source-null does not occur. |
| 3 | By-ref move re-introduces the DL-200 terminal-DISAGREE hazard un-guarded | SHOULD-FIX | **Accepted.** Reuse `coord_card_terminal_stage_id` + its existing mandatory cross-config compare (§3.2); no new terminal key. |
| 4 | Two sibling families for one behavior (canon #5/#7); XOR is the overlap tell | SHOULD-FIX | **Accepted — reshaped** (then simplified further by R2, below): the two-axis model collapsed to ONE axis + per-issue key. No new family, no XOR. |
| 5 | Self-wake surface widens to every issue; only guard is `identity_id` | SHOULD-FIX | **Accepted** (revised by R2-3): `identity_id` warn kept; the `isAlreadyClassified` content-sniff was DROPPED (R2 showed it a fleet-wide triage regression). |
| 6 | `stage_by_label` + create-only incoherent | SHOULD-FIX | **Accepted** (revised by R2-4): label→lane DEFERRED to the reconcile for v1 (create at fixed stage); real-time lane-at-create is a fast-follow (Q2). |
| 7 | By-ref derivation must be synchronous | CONSIDER | **Resolved (verified).** `TaskMutator::create` → `refIndexer->rebuildForTask` synchronously; race-closure holds. |
| 8 | `card_issue_board_id` multiplies the guard surface | CONSIDER | **Accepted.** Drop it; reuse `board_id` (§3.2). |

## 7a. Design-review R2 dispositions (fresh eyes, attacking the R1 fixes)
| # | Finding | Severity | Disposition |
| --- | --- | --- | --- |
| 1 | The by-ref idempotency switch re-opens the DL-198 reconcile double-card race for the prefixed set (bridge key diverged from the tag-keyed reconcile); the dual-key stamp closes only one direction | **BLOCKER** | **Accepted — redesigned.** Key is now derived PER ISSUE: prefixed → the shared `id:<sid>` tag (never diverges from the reconcile); non-prefixed → by-ref (bridge sole mover). Unified pre-check tests **both** derivable keys. Closes the race AND (my addition) the title-prefix-change-between-events edge R2 didn't raise (§3 head). |
| 2 | `{by-ref, prefixed}` is the un-audited incoherent mirror of `{tag, all}` | MAJOR | **Accepted — dissolved.** No `correlation_mode` axis exists anymore; a prefixed issue always uses the tag. The incoherent corner cannot be configured. |
| 3 | `isAlreadyClassified(github_issue)` is a fleet-wide triage regression that silently drops human triage cards | MAJOR | **Accepted.** DROPPED the content-sniff; rely on `identity_id` (root cause) (§3.4). |
| 4 | label→column has no config surface; one `coord_card_stage_id` can't express 4 columns; "absent→Later" presumes an unconfigured stage | MAJOR | **Accepted.** v1 defers label→lane to the reconcile; bridge creates at the fixed `coord_card_stage_id` (§3.2). Full `stage_by_label` is a fast-follow (Q2). |
| 5 | "one family, two handlers" is nominal; the by-ref path forks idempotency/sid/payload/collapse and the handler REJECTS the non-prefixed population | MAJOR | **Accepted.** Each forked step enumerated explicitly with a RED-on-revert test (§3.1): conditional sid-guard, `issue_number` payload, per-issue key, by-ref move leg. |
| 6 | `issue_number` preflight must be fail-closed or every by-ref create 422s as a permanent no-op | MAJOR | **Accepted.** `bridge:check` **exits non-zero** when `population: all` + `issue_number` unregistered (§3.4). |
| 7 | DL-200 terminal compare returns a vacuous "agree" for the non-prefixed population | MINOR | **Accepted.** A distinct `bridge:check` warn states the non-prefixed set has no reconcile backstop under `all` (§3.3). |

**R2-verified correct (not findings):** labels are on `issues.opened/reopened` payloads (create-time snapshot
readable); source canonicalization is consistent (no source-null double-card); create is exempt from
DL-163/178 (those guard *moves*).

R2 → 0 open MUST-FIX/BLOCKER after the redesign. The load-bearing R2 blocker (finding 1) was attacked directly
and its fix traced through the concrete race + one extra edge. The design is now **simpler** than post-R1
(one axis, per-issue key, no content-sniff, label-lane deferred). Remaining opens are the cross-seat roundtable
questions (§6) — the shared-lib reconcile + the 3-consumer card-shape policy, not bridge-internal.
**Next: post the FR to roundtable #18 for sola design-review + aimla card-shape co-design.**

## Appendix A — kanban implementation sites (shipped, for reference)
Kanban `github_issue` landed in one code file — `app/Services/ExternalReferenceNormalizer.php` (const +
`PAYLOAD_KEY_TO_SYSTEM` + `NUMERIC_SYSTEMS`) — plus docs/tests (kanban DL-203). `system` open free-string
(`SYSTEM_REGEX = ^[a-z0-9_]{1,32}$`); no migration, no `Rule::in`, no DB CHECK. By-ref endpoint
`GET /boards/{b}/tasks/by-ref.json?system=github_issue&ref=&source=`, N:1 collection, board-view authz.
Write-side: register `issue_number`/`issue_url` as board custom fields → derived at `TaskMutator`.
