# `bridge:check` — the Check-registry plan

> **Build state lives in ONE place: the § Staged execution table below.** It is the only
> statement of which stages are built, and this header deliberately does not restate it —
> an enumeration here drifted through six merged PRs, each of which updated the table and
> left this paragraph behind (card#5687). A pointer cannot drift; a second copy always does.
> **Every remaining stage marked HARD GATE in that table stays gated — an earlier stage's
> approval never rolls on to a later one.** That is policy, not a moving measurement, which
> is why it belongs here and the stage list does not.
>
> This document owns the *reasoning* for the `bridge:check` consolidation program — the
> measurements behind it, the target shape, the constraints that would break the refactor
> mid-flight, and what is deliberately **not** in scope. Read it before prescribing any
> `bridge:check` hoist or dedupe.
>
> It also records what earlier revisions of this analysis got **wrong**, so those errors are not
> re-made (§ Disproved claims). Decision record: **DL-242**.

## Why this exists

The motivating observation was that bug cards on this repo were being filed faster than they
were resolved, which suggested the core design was accumulating symptom-fixes and special cases.

Measured against the repo, that is **half right — and the wrong half is the core.**

### The receiver core is not generating cards

Across **448 commits since 2026-06-01**:

| File | Size | Commits |
|---|---|---|
| `app/Http/Controllers/Webhook/WebhookController.php` | 77 L | 2 |
| `app/Http/Middleware/VerifyHmacSignature.php` | 130 L | 2 |
| `app/Bridge/Adapters/KanbanAdapter.php` | 42 L | 1 |
| `app/Bridge/Adapters/GitHubAdapter.php` | 89 L | 2 |
| `app/Bridge/Dispatch/DispatchService.php` | 421 L | 7 |

DL-001's collapse of the v0.1–v0.11 five-layer async pipeline into one synchronous app was
correct and is holding. **This program does not touch it.**

Card intake needs the same correction: of 183 board-8 cards, **157 released / 10 declined /
5 shipped / 11 open**. Roughly 13 of July's cards are the board-tools SSH + Windows provisioning
build-out — the cost of a *new subsystem*, not rot.

### The actual defect: `bridge:check` has no check abstraction

`app/Console/Commands/Bridge/CheckCommand.php`:

```
74 → 228 → 343 → 484 → 629 → 826 → 1109 → 1501 → 1753 → 1767 lines
2026-05-29 ────────────────────────────────────────────► 2026-07-26
```

- **24× growth in two months.** 50 commits — 2× the next-highest file in the repo. *(Weakest
  property: this shows the file grew fast and is edited constantly. It does **not** by itself
  show bad design — a command that checks 30 things is legitimately longer than one checking 3.
  The bullets below carry the argument; this one only says where to look.)*
- `handle()` was **891 lines** at stage 0, nesting to 11 levels.
- **133** `$this->warn/error/info/line(...)` calls; 4 of those are the renderer's own `match`
  arms, so **129 are check-site emissions**. `$ok = false` is assigned at 20+ sites.
- Only **~22 of 129** messages carry a structured prefix — there is no stable check identity.

The correct primitive **already exists**: `app/Bridge/Support/Finding.php` +
`Severity{Fail,Warn,Unvalidated,Ok}`. `Severity::Unvalidated` exists precisely to express *"this
did not run."* It is reached from **4** call sites out of 129. **Three** of those render findings
the two probes (`ChannelSnapshotProbe`, `SshTransportProbe`) already produce; the fourth is the
`event-consumer` fail-soft `catch`, which constructs a `Finding::unvalidated` inline and belongs to
no probe. **The other ~125 emit raw.** The primitive was built for the probes and never migrated
outward.

### The generator, stated mechanically

1. A card reports "X fails silently."
2. It is closed by adding a leg to `bridge:check`.
3. The new leg is a raw `if` + `warn()` inside `handle()` — there is nothing else for it to be.
4. Raw `warn()` **cannot represent "did not run"**, so the new leg's not-run state is
   indistinguishable from its pass state.
5. That mints the next card.

The command already said this about itself — **as of the pre-stage-8 code this section describes**,
the closing `unvalidated` tally printed a 5-line apology:

> *"This is a floor, not an inventory: only checks reporting `unvalidated` are counted, and a
> check that could not run usually reports `warn` instead — so no tally line does NOT mean every
> leg ran."*

That paragraph was the command apologizing for not having a registry. **Both halves of the apology
have since been retired, in different stages, and the quote is kept as the diagnosis it was rather
than as current output:** stage 8 replaced *"a floor, not an inventory"* with an exact per-check
account derived from the registration list, and stage 10 removed the *"reports `warn` instead"*
clause by sweeping the sites that did. What survives is a narrower disclosure — the tally counts
the legs that REPORTED being unable to measure, and a leg that never noticed is still invisible.

This is the canon-#5 shape with the diagnosis inverted from the usual case: the primitive was
fixed, the N call-sites were not migrated. Fixing call-sites one at a time leaves the N+1th to
re-mint the bug.

### The structural finding underneath it

`handle()` conflates two different jobs:

- **Deriving a model of the install** — `$configs` accumulates in the per-agent config loop,
  `$githubScopeConsumers` in that loop's subscription walk, `$writeback` and `$client` later.
- **Asserting on that model** — consumed as late as the event-follows-consumer advisory, near the end.

This is why the method resists ordinary extract-method refactoring: the checks are not
independent, because the model they assert on is being built *between* them.

## What this closes — and what it does not

Board state verified live (stage 48 = backlog, 52 = shipped, 53 = released).

**Closed by this work — 3 open cards.** **card#5229** (JSON output, unbuildable today *because*
findings exist only as printed prose), **card#5291** (the warn ↔ unvalidated boundary),
**card#5292** (a third hand-rolled vocabulary: a `type` key written at 3 sites, read at 0).

**The precedent, NOT the payload — already fixed; do not claim these.** **card#5170** is
**released** (*"green-because-checked is indistinguishable from green-because-never-looked"*);
its fix created `Severity::Unvalidated`. **card#5178** is **shipped** (*"the {severity,message}
vocabulary is implemented twice"*); its fix created `Finding` + `Severity`. These two are why the
primitive exists at all. **They are the argument, not the return:** the correct diagnosis was
reached twice and applied locally both times, and the migration was never finished.

**NOT closed — same shape, different address.** **card#5312**'s unalerted permanent
`Log::warning` branches are in the writeback HANDLERS (`KanbanMoveCardHandler`,
`KanbanPromoteReleasedHandler`, `KanbanBlockReasonHandler`) — not in `bridge:check`, and not, as
an earlier revision of this paragraph asserted, in `WritebackAlertNotifier` itself (those line
references were the notifier's own internal push/dedup failure logs, a different instance of the
shape). That address is now **CLOSED (DL-274, then DL-285)**: every permanent refusal arm in the
writeback handlers pairs its log with an alert through one primitive — with the exceptions
that doc names — and a coverage test re-derives that population each run. What remains log-only there is `docs/writeback.md`'s *Still
log-only* to state — **that doc owns it and this one does not restate it**, so there is no second
copy here to drift. What is still open at THIS address is the notifier's own three internal
`Log::warning`s (push failure, dedup-dir, dedup-marker), a separate instance of the same shape.
**card#5310** has since SHIPPED (DL-273) and its probe is now
`GitHubPrCardMoveClassifier::warnTokenNearMiss()`, covering both correlation tokens — but it
shipped as a `Log::warning`, which is the very channel this paragraph is about, so it moved from
"an instance with no signal" to "an instance whose signal is a log line nothing aggregates". Both
are the silent-degrade shape and both deserve a shared "this degraded silently" reporting
primitive, but that is a **separate consolidation** over a different call-site set. This program
gives the shape a vocabulary to be reported in; it does not detect it.

### The honest trade-off

**This does not pay for itself on card count.** It closes **3** open cards at the cost of a
~10-stage refactor of a 1767-line operator-facing command — multi-session work.

The justification is **recurrence prevention**, and it has to be argued on that basis or not at
all: `CheckCommand` absorbed 50 commits in two months, and the two cards that diagnosed this
exact problem correctly each fixed it *locally* and left ~125 sites unmigrated — so the next
silent-failure fix lands as another raw `warn()` and mints the next card. If that mechanism is
not accepted, **this should not be funded** — the three cards can be patched individually for far
less, and that is a legitimate call.

## Target design

Under `app/Bridge/Check/`, reusing `Finding` + `Severity` unchanged:

```php
// The derived install model — built ONCE, before any check runs.
final class CheckContext { /* configs, githubScopeConsumers, writeback, client, … */ }

interface Check {
    public function id(): string;                 // stable machine id: 'writeback.source_coverage'
    /** @return iterable<Finding>  unvalidated when it cannot run. Yielding NOTHING is legal
     *   and is RECORDED, not lost — this sketch originally demanded >=1 finding; stage 8
     *   measured that false before building it. See the stage 8 result. */
    public function run(CheckContext $ctx): iterable;
}

// Per-agent checks keep their output interleaved inside the config iteration (see (b) below).
interface PerAgentCheck { public function runFor(AgentConfig $c, CheckContext $ctx): iterable; }

final class CheckRunner { public function run(CheckContext $ctx): CheckReport; }
```

Renderers over `CheckReport`: `TextRenderer` (byte-identical to today) and `JsonRenderer`
(closes card#5229). `handle()` shrinks to: build context → run registry → render → exit.

**This is not a new abstraction.** It promotes the shape `ChannelSnapshotProbe` and
`SshTransportProbe` already have (`… → Finding[]`) into the contract the other ~125 sites should
have been using — extending the existing primitive rather than siblings it.

### Three load-bearing constraints

**(a) Registration is unconditional. "Not applicable" is never an absent registration.** If checks
registered conditionally (e.g. board-tools checks only when some config declares the block), a check
that never registered is invisible to the inventory — which re-mints *"green because never looked"*
one level up, at the registry. Every check registers always.

**How inapplicability is communicated was RESTATED by stage 8**, and the original wording — *"is a
returned `Finding`"* — is now false in the majority case. It presumed the check always runs and
answers. Stage 8 measured that 13 of 37 checks are never invoked at all on the baseline install:
their slot sits behind a conditional envelope, so no `Finding` object exists to inspect. The two
mechanisms, neither of which is an absent registration:
- the check **runs and answers** — a `Finding` (including `unvalidated` when it could not measure),
  or an empty yield, recorded as `Reported` / `Silent` / `NotRequested`. **AMENDED BY DL-266
  (card#5596): an empty yield is no longer the whole story.** A check declares a deliberate silence
  by also yielding a `Silence`; the disposition set is unchanged, but an execution that yields
  neither a finding nor a declaration is additionally named in `CheckInventory::undeclaredSilent()`,
  which is what separates *"looked and correctly had nothing to say"* from *"fell off the end of the
  generator"*. See § Follow-on to stage 8;
- the check's **slot never opens**, and the runner DERIVES `NotRun` from the registration list, with
  the envelope's reason attached by `CheckRunner::noteNotRun()`. The reason is the envelope's claim
  about itself, not the check's.

**(b) The registry needs a per-agent scope, not just a global one.** Output is emitted *inside*
the per-agent config loop (`agent config ok: {$name}`, `agent {$name}: …`),
interleaved per agent. A check hoisted to run after derivation would **reorder** output and break
the byte-identical contract. Hence `PerAgentCheck`, executed within the iteration at the same
position.

**(c) During stages 1–7 the surviving inline derivation code in `handle()` populates
`CheckContext`.** Migrated checks read it; unmigrated code keeps its local variables.
`CheckContext` becomes a standalone builder only in the final stage. Without this rule the stage
boundaries are ill-defined — `$githubScopeConsumers` is *built* inside the per-agent loop
and *consumed* by the event-follows-consumer advisory, so producer and consumer are migrated in
different stages and must communicate through the context in the interim. Stage 7a is where that
became concrete: the loop now writes `$ctx->githubScopeConsumers` directly and the local is gone.

### Why a strangler migration, not a rewrite

Each stage moves **one check unit** out of `handle()` into a `Check`/`PerAgentCheck`, **registered
at the same ordinal position**. Output order is preserved, so the golden-output test (Stage 0)
stays green throughout. Every stage is independently reviewable and revertible.

## Resolved design decision — the opt-in probes (settled; do not re-open)

**Question as originally posed:** do the opt-in probes report `unvalidated` when their flag is
absent? Today `--probe-tools` / `--probe-tools-ssh` print **nothing** when not passed (guarded at
the two probe-summary legs), but under constraint (a) they must return *something*.

**Ruled: neither `unvalidated` nor `ok`.** Both answers accepted a false premise — that the
choice is a severity. It is not.

**The discriminator is the failure mode `Severity::Unvalidated` exists to prevent** (per its own
docblock): an operator reads a green run and believes something was verified when it wasn't. So
the test is *not* "did it run," it is **whose fact the not-running is**:

- Not-run is a fact about the **install**, which the operator cannot know — `channel.server_path`
  undeclared, a directory the bridge user cannot traverse, no `node` on PATH. **→ `unvalidated`.**
  This is card#5170's case and is precisely what the severity is for.
- Not-run is a fact about the **invocation**, which the operator necessarily knows because they
  chose not to type the flag. **→ not `unvalidated`.** There is no false belief to correct, and
  using it here dilutes the one signal with a precise job.

`ok` is worse than either: it asserts a pass for something never executed — the exact bug this
whole program exists to kill.

**The ruling: "not requested" is a third *disposition*, and it lives in the inventory/renderer
split rather than in the `Severity` enum.**

- The runner records **every registered check and its disposition**, so the inventory stays
  complete and **constraint (a) holds** — nothing is invisible to the registry.
- The **text renderer emits it too**, as one segment of the stage-8 inventory line
  (`2 opt-in probes not requested`), which 30 of the 33 golden fixtures now carry (the other 3
  pass a flag and carry `1`). **AMENDED BY STAGE 8 — this bullet originally read "the text
  renderer stays silent on not-requested: today's default output is preserved byte-identical and
  the common path gains no noise", and stage 8 is the row that shipped a deliberate
  output change, so the byte-identical premise no longer applies to it.** Silence would also have
  contradicted the always-print decision the stage reached for the line as a whole: a coverage
  statement the operator gets only sometimes is one they cannot rely on, and a disposition
  omitted from the line breaks the arithmetic that is the line's own control. The row-8 gate
  covers this; nothing about the ruling itself changed.
- The **JSON renderer** (card#5229) **emits it** in machine-readable form, per check rather than as
  a count — which is what "the full inventory" buys a machine consumer once the text line already
  carries the total. **AMENDED BY STAGE 8:** this bullet originally justified itself as *"strictly
  more than they can get today"*, which was true only while the text renderer stayed silent.
  **SHIPPED in stage 9** as `checks[].disposition: "not-requested"`, keyed by check id; the shape
  is owned by [`check-json-contract.md`](check-json-contract.md).

Net effect: `unvalidated` keeps exactly one meaning — *"I should have measured this and the
install stopped me."* **No new `Severity` case**, so the exhaustive-`match` property and the exit
contract (only `fail` flips the exit) are both untouched by construction.

**Scope bound — DISCHARGED BY STAGE 10, and the ruling above is unchanged by it.** This section
settled the **opt-in-probe axis only** and left the re-assignment sweep card#5291 owns open and
gated. **DL-251 landed that sweep** (21 sites `warn` → `unvalidated`, plus three legs that emitted
nothing at all), and the general rule it wrote — *`unvalidated` ⟺ the leg did not answer its own
question* — is now owned by `App\Bridge\Support\Severity`'s docblock rather than by this doc.
**Read the two together:** limb 3 of that rule IS this ruling (an absent opt-in flag is not a
severity), and the one-meaning sentence above is the property the rule was built to preserve. The
reasoning is also recorded on card#5291, still the SoT for the sweep's per-site reasoning.

## Staged execution

Every stage ends with a fresh-adversarial review pass and must reach **zero must-fix** before the
next stage starts.

| Stage | Work | Operator-visible change | Gate |
|---|---|---|---|
| **0 ✅** | Golden-output harness + `Check`/`PerAgentCheck`/`CheckContext`/`CheckRunner` scaffolding. Registry empty; nothing migrated. | **None** | no |
| **1 ✅** | Migrate the two already-`Finding`-shaped probes — **3** of the 4 existing `emitFinding` call sites (the 4th is not a probe; see below). First wiring of `CheckRunner` into `handle()`. | **None** | no |
| **2 ✅** | Migrate the `retention` cluster into `RetentionPostureCheck` at a new `CheckSlot::Retention`. `earlyFinishIndicated()` moves with it (single caller). | **None** | no |
| **3a ✅** | Migrate the writeback **config plane** — the six units needing no board read — into `CheckSlot::Writeback`. The cluster splits because its probe half needs a constructed `KanbanClient`; see the stage 3a result. | **None** | no |
| **3b ✅** | Migrate the writeback **probe plane** (the legs that read the board) into `CheckSlot::WritebackProbe`. Inherited the throw constraint recorded in the stage 3a result; see the stage 3b result. | **None** | no |
| **4 ✅** | Migrate the `database`/`install-suffix` cluster into `CheckSlot::Database`. Two checks, and the first cluster that is pure assertion — no `CheckContext` field added or read; see the stage 4 result. | **None** | no |
| **5a ✅** | Migrate the per-agent **classifier plane** — the units asserting on the parsed config alone: the classifier-resolution gate (into `CheckSlot::AgentClassifier`, the one **abort** slot) plus the two lazy-`classifier.config` advisories (into `CheckSlot::AgentPolicy`). Stage 5 splits; see the stage 5a result for the measured reason. | **None** | no |
| **5b ✅** | Migrate the per-agent **secret/token + channel-transport** legs: secret + API-token presence, `channel.auth.token_path`, and the socket/HTTP marker + liveness legs. **Five of the eight disclosed gaps were here**, so unit tests carried the proof — a green golden run says nothing about these. First stage to add **no** slot, and the one seam (`ChannelProbeEnvironment`) is the connect alone; see the stage 5b result. | **None** | no |
| **5c ✅** | Migrate the **post-loop registry/identity** legs: `AgentRegistry` collisions, `treat_as_signal`, `BRIDGE_DEFAULT_AGENT`, `shared-identities.json`, into a new `CheckSlot::AgentRoster`. The registry BUILD stays inline as derivation — it logs collisions at construction, so a second builder would re-log them behind the output contract's back; see the stage 5c result. | **None** | no |
| **6 ✅** | Migrate the **pre-loop install plane** — both install directories, the inbox-surfacing config, the endpoint URLs, and the provider/adapter coverage leg — into **three** new slots (`CheckSlot::Install`, `::Inbox`, `::Providers`). Three because the region is not contiguous: the already-migrated `Database` and `Retention` slots run inside it and slot ordinal fixes output order. `warnIfDirInsecure()` moves with it (both callers migrate here). **This row replaces an earlier one that under-enumerated the remaining work; see the stage 6 result.** | **None** | no |
| **7a ✅** | Migrate the **event-follows-consumer plane** — the whole DL-196 advisory — into a new `CheckSlot::EventConsumer`. One check, one slot. **Row 7 splits on SIZE, not on a measured seam**, and the stage 7a result says so rather than manufacturing a discriminator; it is also the first stage whose predicate total goes UP, because it migrates a HELPER method rather than code inside `handle()`. | **None** (golden test enforces) | no |
| **7b ✅** | Migrate the **board-tools plane** — the `suppressedReason` scan, the resolver's `problems()`, the per-agent board-STATE legs, the DL-225 flipped-default advisory (which reads its input back off another slot's REPORT — a first for this program) and the `--probe-tools` live probe — into **five** new slots. `handle()` now holds derivation and the runner calls alone; see the stage 7b result. | **None** (golden test enforces) | no |
| **8 ✅** | Turn on the accounting invariant: every registered check is **accounted for** on every run that completes (the runner does not catch, so a throwing check aborts before anything renders the account), and replace the `emitReport()` "floor, not an inventory" disclaimer with an **exact** inventory. Applies the resolved opt-in-probe decision above — **and amends two of its bullets in place: the text renderer does NOT stay silent on `not requested`, because this is the gated output-change stage.** **This row originally read "every registered check emits ≥1 finding" — measurement falsified that before any code was written, and the row is corrected rather than quietly built around; see the stage 8 result.** | **Yes** — every run that completes gains one inventory line; the disclaimer is narrowed | **GATE** (granted) |
| **9 ✅** | `--format=json` renderer. Closes **card#5229**. **This row was INCOMPLETE as written, and the card said so before the stage started: a renderer over the existing report does not close card#5229, because the event-consumer reconciliation was computed as locals inside a check and thrown away — emitting its prose as JSON strings is still prose-parsing. The stage is therefore TWO deliverables — the renderer split AND the reconciliation extracted as data; see the stage 9 result.** | Additive surface | **GATE** (granted) — and the reason is NOT the operator-visible column, which is the weakest of the three gated rows. A JSON shape is a **write contract**: once a machine consumer parses it you cannot un-ship it, so per the fleet write-contract rule it must be versioned and carry its own guard. Recorded here because it was previously asserted with no rationale anywhere in this doc or the decision log, which makes a gate indistinguishable from a habit. |
| **10 ✅** | Re-assign the sites that disagree on warn ↔ unvalidated, and write the RULE that decides — into `Severity`, replacing the paragraph that said the boundary was not settled. Closes **card#5291**, **card#5292**. **The row named a re-assignment and the stage owed one more thing: THREE legs that answered nothing and emitted NOTHING, which a warn-keyed sweep is blind to by construction. That set was found by READING, not derived — no command enumerates it — so three is a FLOOR; see the stage 10 result.** | **Yes** — 21 findings change severity and 3 new ones appear | **GATE** (granted) |

Stages 0–7 are pure refactor under a byte-identical output contract — **contingent on Stage 0
proving that contract is achievable.**

**Off-ramps.** Stop after Stage 7: refactor cost paid, zero operator risk. Stop after Stage 8:
the inventory is exact — **the minimum viable slice that stops the recurrence.** The off-ramps
are real, not rhetorical.

### Stage 0 result — the falsifier answered YES, with a precondition

Stage 0 was this plan's own falsifier: stages 0–7 are contingent on byte-identical capture being
achievable at all. **It is — but not for a naive capture. The harness must PIN the ambient host,
because `bridge:check` reads it directly and at least one of those reads is verdict-bearing.**

The proof is empirical, not asserted. With `$COORD_CONFIG` unset the terminal-agreement leg prints
*"… `$COORD_CONFIG` is not set …"*; with it set to a path that does not parse it prints *"… the
coordination config at `<path>` is absent, unreadable, or malformed …"*. Same severity class,
**different diagnosis and different operator instruction** — so the "never normalize a verdict"
rule forbids normalizing it away. It is pinned instead, and both texts are fixtures.

Four ambient inputs are pinned (`PATH`, which decides the `php-fpm` probe and therefore
moves nearly every install's output; `XDG_RUNTIME_DIR`; `COORD_CONFIG`; `GH_TOKEN`), two host
values are normalized (absolute paths, the numeric uid — neither carries a verdict), and the
retention last-failure marker is cleared from the cache so a fixture that wants it sets it.

**Two things the stage-0 build found that the source-level enumeration had not:**

- `GH_TOKEN` is a host input reached *transitively*, through `GitHubTokenResolver` — an
  enumeration bounded by `CheckCommand.php` is bounded by that file.
- **`config/bridge.php` is itself a host input.** Its keys resolve through `env()`, and this repo's
  checkout carries a deployed `.env`, so `bridge.default_agent` and `bridge.receiver_base_url` are
  that install's values on an operator box and null on CI — and both reach output. Fixtures
  therefore declare the full `bridge.*` set rather than inheriting it. This is the class most
  easily mistaken for a fixture property: it looks declared because it is config.

**The named gap the stage carried is closed:** whether CI's runner has `php-fpm` is no longer a
question, because PATH is pinned to a fixture bin dir holding (or not holding) a stub.

**What the harness does NOT protect is enumerated, not implied.** The fixture set is derived from
the `if`/`elseif`/`foreach` predicates in `handle()`, enumerated at the source by
`bin/check-golden-predicates.php`, which prints the live count and its per-kind split on every run.
**The figure is deliberately not restated here.** It moves whenever `handle()` changes, and a pinned
number drifts silently — this sentence read `102` long enough to outlive the refactors that took it
to 48, while the generated coverage doc beside it was reporting 46. Read it from the script.
Which of them the fixtures can actually *see* is decided by experiment —
`bin/check-golden-mutate.php` flips each predicate and records whether a golden file changes — and
the survivors are named individually in **[`check-golden-coverage.md`](check-golden-coverage.md)**.
Mutation is used rather than a coverage driver on purpose: coverage answers *"did this line run"*,
and the property stages 1–7 depend on is *"would a change here be caught"*. A predicate whose two
branches print identical bytes is fully covered and entirely unprotected.

The scaffolding shipped with an **empty registry and no wiring into `handle()`** — its call sites
arrived in stage 1 with the first migrated checks, which is what made the wiring observable rather
than dead. `tests/Unit/Bridge/Check/CheckRunnerTest.php` still pins the runner's own properties,
which output cannot distinguish (two checks in one slot and one check yielding twice print the
same bytes).

### Stage 1 result — the scaffolding needed one amendment, and the harness had a blind half

**What migrated:** `ChannelSnapshotCheck` (per-agent), `SshPinnedLineCheck` (per-agent), and
`SshLiveProbeCheck` (global, opt-in). `emitFinding()` lost its `$prefix` parameter and
`emitSshFinding()` is gone; a check now yields **display-ready messages**, because a Finding has no
scope field and `SshLiveProbeCheck`'s two message shapes (`board_tools ssh: …` and
`board_tools ssh probe: …`) cannot share one render-time prefix. The structured identity a stage-9
renderer keys on is `id()` + `CheckResult::$agent`, not that string.

**The amendment — `CheckSlot`.** Stage 0 gave each scope ONE call site, assuming each is invoked
from one place. Stage 1 falsified that on both: `CheckCommand` runs **two distinct per-agent
iterations** (the main config loop, and the ssh-agent loop inside the then-inline
`checkBoardTools()` — a method stage 7b retired, along with the unmigrated code the rest of this
paragraph describes; the finding stands, the addresses moved), and its
global units sit at positions **separated by unmigrated inline code** (the `--probe-tools` leg
renders between the two board-tools legs). A slot names *where* a group runs. It changes nothing
about constraint (a) — registration is still unconditional, and the opt-in flag is a constructor
argument rather than an `if` around `register()` — and it collapses to one ordered registry when
the last unit migrates.

**The harness was green on two of the three sites for free.** No fixture reached
`checkSshTransport()` or `--probe-tools-ssh` at all, so the golden suite could not have caught a
botched migration of either — a pass where failure was not possible. Stage 1 adds four ssh fixtures
(`board-tools-ssh-pinned-line`, `board-tools-ssh-default-transport-advisory`,
`board-tools-ssh-live-probe`, `probe-tools-ssh-with-no-ssh-agent`) behind a pinned
`GoldenSshEnvironment`, and the byte-identical claim is **measured, not asserted**: those four
golden files were captured from **pre-refactor** code and the post-refactor command reproduces them
byte-for-byte. Three deliberate mutations (opt-in probe never runs; per-agent check yields nothing;
the ssh prefix loses a space) each red the expected fixtures.

**Those four fixtures closed ZERO entries in the coverage table, and that is the expected result, not
a disappointment.** The regenerated measurement is `78 observed · 2 observed-via-abort · 20
UNOBSERVED / 100` — the same 20 conditions as before, renumbered, with **no predicate changing
status**. The mutation instrument enumerates `handle()` **only**, and both ssh legs live in helper
methods (`checkSshTransport()`, the former `probeBoardToolsSsh()`), so it never measured them in
either direction. The ssh fixtures add protection this instrument cannot see. Read the counts
accordingly: **absence from `check-golden-coverage.md` is not evidence of protection** — it is
evidence of not being in `handle()`. (The 102 → 100 drop is just the two predicates stage 1 moved
out of `handle()`; both were `observed`.)

**What stage 1 did NOT migrate, and why.** The 4th `emitFinding` site is the `Finding::unvalidated`
inside `checkEventFollowsConsumer()`'s fail-soft `catch`. It cannot move alone: the `catch` wraps
that method's whole body, so migrating it necessarily migrates the ~10 raw emissions above it and
the `$githubScopeConsumers` read at its heart. That is a **stage 2–7 cluster**, and merging it into
stage 1 would have merged two stages. It stays inline, now calling the one-argument `emitFinding()`.

### Stage 2 result — the first cluster where a plain green run WAS the proof

**What migrated:** the retention posture leg (DL-199) into `RetentionPostureCheck` at a new
`CheckSlot::Retention`, at the ordinal position the inline code held. `earlyFinishIndicated()`
moved with it — that private method had exactly one caller. `CheckCommand::handle()` lost ~66 lines.
(That method was named `receiverSapiFinishesEarly()` until DL-261, which renamed it because the name
asserted an answer it has never produced. Every mention of it in this doc — including the mutation
lists below — uses the current name; the predicate itself is unchanged by the rename.)

**No stash-and-recapture was needed, and that is a property of this cluster, not a shortcut.** Stage
1 had to capture fixtures from pre-refactor code because none existed. Here the four retention
fixtures and the `minimal`/`minimal-fpm-present` potency pair were **already captured before this
refactor existed**, so a plain green run *is* the byte-identity measurement: **37/37, 79 assertions,
green with `UPDATE_GOLDEN` unset.** The assertion count is the load-bearing half — a regenerating run
drops to 46, so **79 means asserted; 46 would mean nothing was checked.** Six deliberate mutations
each red the suite: registration removed, `enabled` inverted, `isUsable()` inverted,
`earlyFinishIndicated()` inverted, `is_array($lastError)` inverted, and one space dropped from
the `retention: on (` message. **⚠ The `is_array($lastError)` arm evidences less than it reads as —
no fixture reached the marker-present branch until DL-247. See § Disproved claims, claim 8; do not
restate this list without it.**

**One leg is covered by neither instrument, and it needed a unit test.** The fail-soft `catch` around
the last-failure marker read is unreachable from a golden fixture (it needs an unreachable cache
backend) *and* invisible to `bin/check-golden-mutate.php`, which walks `if`/`elseif`/`foreach` in
`handle()` and **does not enumerate `catch` blocks at all**. Left alone it would have been justified
by reading. `RetentionPostureCheckTest` covers it with a throwing cache store plus a discriminating
control (reachable backend, no marker ⇒ the line is absent); both directions are mutation-proven.
**Generalize this before stage 3:** every remaining cluster carries fail-soft `catch` arms with the
same double blind spot.

**Coverage-table effect.** The predicate total goes **100 → 97**, and the arithmetic is *not* a plain
subtraction: the four retention predicates (`enabled`, `isUsable()`, `earlyFinishIndicated()`,
`is_array($lastError)`) leave `handle()`, and the one-line `if (! $this->emitReport(...))` guard that
replaces them **adds one back** — 100 − 4 + 1 = 97. Every migrated cluster will net −(n−1), not −n,
for the same reason. The gap ids also renumber; that restaling is what `card#5475` exists to fix —
a property of the instrument, not a defect of this stage.
**The regeneration measures that arithmetic rather than assuming it: `75 observed · 2
observed-via-abort · 20 UNOBSERVED / 97`.** All four departing retention predicates were `observed`,
and the guard replacing them is `observed` too, so the observed count moves exactly as the total does
— 78 − 4 + 1 = 75. **No surviving predicate changed status**, and the disclosed gaps are the same 20
conditions as stage 1, compared by condition text rather than by id precisely because the ids
renumber. Stage 2 closed no gap and opened none.

**The four predicates did not lose protection by leaving the table — they left `handle()`, which is
the only thing this instrument enumerates.** That is the same reading stage 1's ssh fixtures
required, and it cuts the other way here: their protection now lives in the four retention fixtures
(byte-identical output) and, for the fail-soft `catch` arm no fixture can reach, in
`RetentionPostureCheckTest`. As the migration proceeds this table measures a steadily smaller
fraction of `bridge:check`, so a shrinking gap count is **not** evidence of improving coverage.

**A finding worth more than the stage: line numbers into `CheckCommand.php` are a doc defect, not a
convenience.** Docblocks and this plan cited `handle()` offsets in ~20 places. Stage 2 moved ~66
lines and invalidated **every one of them** — verified individually; not one still landed on its
construct. Stages 3–7 would each re-mint the same breakage. All such citations are now replaced by
construct names, which are stable and greppable. **The rule for stages 3–7: never cite a
`CheckCommand` line number.** Name the method, the loop, or the message. A line number into a
*stable* file is still fine — the pin on `GitHubTokenResolver` L204 was re-verified and left alone.

**The rule is now a gate, because stating it in prose was measurably not enough.** The first pass at
this stage wrote the rule here and fixed the citations it could find — and still missed **five**
sites, because it grepped for `CheckCommand` and the worst citations never name their file:
`GoldenInstall` cited two bare offsets with no filename anywhere on the line, and this plan itself
cited a `CheckCommand` offset that had already drifted by 45 lines. A stale comment is valid PHP
that passes phpstan, pint, and the golden suite alike. `bin/check-doc-refs.php` — already the doc-sync
gate under DL-013, already a CI step — therefore grew a second check: a citation naming
`CheckCommand` is an error anywhere, and a *bare* `L<n>` is an error anywhere in the check-registry
surface, where an unqualified offset can only mean `CheckCommand`. It is deliberately **not** a
repo-wide ban on offsets: the receiver core is static by DL-001 and `CLAUDE_GOTCHAS.md`'s cites into
it were verified still accurate, so banning them would trade a real defect for churn. Both rules were
proven able to fail (injected citation ⇒ red) and both exemptions proven not to (bare offset outside
the surface, and a `GitHubTokenResolver` line, stay green).

**Three holes in that gate were then measured and closed, and the third is the instructive one.**
A gate is a claim about what cannot pass, so the claim was tested rather than read.

1. **The stable-anchor exemption swallowed its own rule.** As first written it skipped any line
   mentioning an anchor, so a line that mentioned `GitHubTokenResolver` *and also* carried a
   line-number citation of the migrating file passed — one anchor word anywhere on the line
   whitelisting the exact citation the rule exists to catch. The exemption now applies only when
   the line does not name the migrating file. An exemption wide enough to swallow its rule is worse
   than none, because the gate reads green.
2. **The scan reached only `CLAUDE*.md` at the repo root**, so a citation in `README.md` or
   `VERSIONING.md` was never examined at all. The root glob is now `*.md`.
3. **The rule missed this repo's own house style.** The connector between the file name and the
   offset was `[\s:]*`, which matches an unquoted `CheckCommand L<n>` but *not* the quoted form — and
   backtick-quoting class names is exactly how this codebase writes them (the `GitHubTokenResolver`
   pin two paragraphs above is written that way). The single most likely spelling of the defect was
   the one spelling the gate could not see; `::handle()` between the two was invisible for the same
   reason. The connector is now a bounded any-character window. **Enumerating the ways a citation
   can be punctuated is the losing half of that trade — bounding the distance is the winning one.**

Every hole was measured green-then-red across the fix, against a positive control (the unquoted
form, which the gate always caught) and a negative one (the legitimate bare-offset exemption, which
stayed green throughout). **The first pass at this gate proved each rule could fire and stopped
there; two of these three holes sat in the exemptions, which that proof never exercised.**

### Stage 3a result — the cluster that had to be split, and the instrument's second blind half

**Stage 3 is two PRs, and the split is a property of the cluster.** The writeback cluster divides on
a hard line: the units that need only *config* (they must fire on a half-configured install, which is
exactly where a silently-inert writeback leg is most likely) and the units that need a constructed
`KanbanClient` to *read the board*. Migrating both at once would have put a throwing callee and a
non-throwing one behind the same envelope decision, with no way to state a single rule about either.
**3a is the config plane; 3b is the probe plane.**

⚠ **One card, two PRs: merging 3a auto-moves `card#5500` to shipped_to_dev while 3b is still owed.**
Move it back to in_progress; that column does not mean stage 3 is done.

**What migrated:** six checks under a new `CheckSlot::Writeback`, at the ordinal position the inline
code held — `writeback.config`, `.identity`, `.alert_channel`, `.token`, `reconcile.repo_tokens`,
`writeback.mapping_config`. `CheckContext` gains `writebackEmittingScopes`, `coordCardMoveScopes`, and
a validated `secretDir` (**one nullable field, not the `bool` + `string` pair `handle()` carried** —
two fields that can disagree is a state no check should have to reason about).

**`writeback.mapping_config` is one check, not one per leg, because output ORDER is the contract.**
The inline code iterates mappings on the outside and legs on the inside; a per-leg decomposition
would emit every repo's orphan warning before any repo's DL-160 warning. On a single-mapping install
the two orders coincide — on a multi-mapping one, the install this check exists for, they do not.
Stage 8's inventory keys on the check id, so the grouping costs granularity there. Accepted
deliberately, over reordering operator-visible output.

**The fail-soft envelope stays inline — and the justification originally recorded for that ruling is
false.** It was kept inline because `GitHubRepoProbe::probe` was "the realistic thrower". It is not:
that class is documented and verified *total* (the resolver never throws; the probe's own exceptions
are mapped to result kinds). **The ruling stands on a different mechanism, and this is the one to
carry forward:** the real thrower is `WritebackConfig::load()`, which is derivation and inline
anyway — and wrapping `emitReport()` makes semantic preservation a property of `handle()` rather than
an assumption about six checks' callees. A correct decision resting on a false premise is one
refactor away from being reversed for the right-sounding reason.

**A live constraint 3b must design around, discovered here and unreachable here.** `CheckRunner`
*materializes* a check's findings before the caller renders any of them, whereas the inline code had
already **printed** the earlier lines. So a check that throws part way **loses the findings it
already yielded**. Every leg in 3a is total — verified per leg, which is why the difference is
unreachable in this stage — but **3b's legs call the board client and DO throw**, so 3b must not put
multi-mapping yields behind one throwing call.

**The instrument has a second blind half, and it generalizes stage 2's `catch` finding.**
`bin/check-golden-mutate.php` walks `if`/`elseif`/`foreach` — it **does not enumerate `switch` arms**
any more than it enumerates `catch` blocks. The `reconcile.repo_tokens` probe switch has four arms
that are therefore not merely *unobserved* in `check-golden-coverage.md`; they are **absent from the
disclosed-gap list entirely**. Stage 2 established that absence from that list is not protection for
code outside `handle()`; stage 3a sharpens it: **absence is not protection for a `switch` inside it
either.** Do not let a regenerated count imply those arms were measured.
**[STAGE 10 TURNED THIS DISCLOSURE INTO A DEMONSTRATION THAT A DISCLOSURE IS NOT A GUARD. This
paragraph named THIS `switch`, three stages early, as code no instrument can see — and stage 10's
sweep still missed one of its arms: `Network` shared `Ok`'s `break`, so "the token is valid" and "we
never reached GitHub to ask" were the same output. The sweep keyed on `Finding::warn` and that arm
emitted nothing to key on. A gap that is disclosed but unenumerated is closed by whoever happens to
re-read the file. See the stage 10 result's METHOD DEFECT paragraph.]**

**What the golden fixtures do and do not reach, enumerated rather than assumed.** The seven writeback
fixtures reach the mapping-count line, the no-identity and missing-token warns, the `Unresolvable`
reconcile arm, orphan, the DL-160 pair, the DL-195 revive, the DL-198 echo, DL-204 in both directions,
and the outer load `catch`. **Four legs no fixture reaches at all:** the whole `alert_channel` check,
`issuePopulationAgreement()`, the #4553 `population=all` warn itself, and the reconcile probe's `Http`
arm. **[card#7124 (DL-293) correction: FIVE as it ships. `WritebackMappingConfigCheck` gained a
`SPELLING SPLIT` leg — a mapping key and an agent `scope_id` that canonicalize equal but differ in
spelling — and no golden fixture reaches it, because every fixture spells the two the same way,
which is the state the leg exists to distinguish FROM. It is covered by three
`WritebackMappingConfigCheckTest` cases (split reported / spellings agree ⇒ silent / no agent ⇒
ORPHANED-not-split) plus one end-to-end `bridge:check` case, all mutation-proven against the
pre-DL-293 tree. The enumerated count above is the stage-9 measurement, not the shipped one.]**
**[card#7348 (DL-305) correction, to the SECOND of those three cases only: the spellings-agree case
no longer asserts a totally empty result. `WritebackMappingConfigCheck` gained a mention-vs-closure
`ok` leg — the first non-warning leg on it, and the first that speaks about a mapping which is
entirely correct — so that case now asserts *no WARNING*, with the green line standing as the
WITNESS this file's own discipline asks of every absence assertion: before it, a check that returned
at its first line satisfied that test exactly as well as one that walked the mapping loop. The leg is
fixture-REACHED (nine golden captures gained the line, and the inventory's reported-count moved with
it), so it is not a new member of the four-unreached set enumerated above, and the `family-on,
terminal-missing` no-disclosure ruling below is untouched — the closure leg is not map-fed and reads
no scope map at all.]** Three more are disclosed as UNOBSERVED by condition text (the `correlation !== 'ref'` leg, the
promote same-stage no-op, and the promote file-token requirement). **All seven are covered by three
new unit tests** — `WritebackMappingConfigCheckTest`, `WritebackAlertChannelCheckTest`,
`ReconcileRepoTokensCheckTest` — deliberately scoped to the residue: golden-covered legs are *absent*
from them, because duplicating the stronger measurement does not strengthen it.

**Those tests are mutation-proven, not merely green.** Fifteen mutations — one per guarded leg plus
the guards and source-labels they depend on — each red the expected tests, and the tree was verified
green again after each revert. Two of the fifteen are worth naming: a mutation that made the
agreement compare's "no board entry" guard unreachable red only via a PHP *error*, so it was re-run
as a valid-code mutant to prove the assertion itself discriminates; and the network-blip arm cannot
be witnessed by `Http::assertSentCount` at all (a **throwing** fake is never recorded, so the count
reads 0 whether the probe ran or not) — the fake's own side effect is the witness. **Every absence
assertion in these tests carries a witness that the check ran**, because a check that yielded nothing
would satisfy a bare absence just as well.

**Coverage-table effect — and the part the generated file cannot carry.** The predicate total goes
**97 → 77**: `58 observed · 2 observed-via-abort · 17 UNOBSERVED`. The arithmetic is the stage-2 rule
again, but it only closes once the comparison stops trusting its own first answer. Compared by
condition text, **22 predicates depart and 2 arrive**. One of those pairs is not a migration at all:
the surviving coord-card leg `isset($coordCardMoveScopes[$repo])` is re-expressed as
`isset($ctx->coordCardMoveScopes[$repo])` when the scopes move onto `CheckContext`, so a by-text
compare reads one unchanged leg as a departure plus an arrival. **Net of that rename, 21 predicates
leave `handle()` and exactly one arrives — the `emitReport(CheckSlot::Writeback)` guard** —
so 97 − 21 + 1 = 77, which is −(n−1) as stage 2 predicted.

**No surviving predicate changed status.** Same result stage 2 recorded, and the one that would have
mattered most had it gone the other way.

**No gap closed, and none opened.** The disclosed-gap count falls **20 → 17**, and a reader who stops
at that number will conclude the golden suite got stronger. It did not. **All three gaps left the
instrument's scope rather than becoming observed:** the `correlation !== 'ref'` leg, the promote
same-stage no-op, and the promote file-token requirement each departed `handle()` *while still
UNOBSERVED*, into the three unit tests above. Gaps closed in place: **zero**. Gaps newly opened:
**zero**. The count fell because the measured region shrank, not because the measurement improved —
and that distinction is invisible in `check-golden-coverage.md`, which is why it is recorded here.

⚠ **Two method notes for stages 3b–7, because the first run of this comparison was wrong.**
(1) `(kind, source)` is **not a unique key**: the 97-entry table holds only **86 distinct** pairs
(`foreach $cfg->subscriptions` appears 4×, `if $sub->provider === 'github'` and
`foreach $writeback->mappings` 3× each). A set-keyed diff silently collapses the repeats and
under-reported the 22 departures as 18. **Compare as a multiset.** (2) What caught it was asserting
`old − departed + added == new` and watching it yield **81 ≠ 77**. Keep that assertion: it is the
positive control for the comparison itself, and without it a collapsed count reads exactly like a
correct one.

### Stage 3b result — the probe plane, and the last writeback gap leaves the instrument

**What migrated:** three checks under a new `CheckSlot::WritebackProbe`, registered in the output
order the inline code held — `writeback.by_ref`, `writeback.board_state`, `writeback.source_coverage`.
The `$writeback`/`$client` locals and the two private helpers (`checkWritebackSourceCoverage`,
`checkCoordTerminalAgreement`) are gone. `handle()` keeps the client construction — derivation, not
assertion, per constraint (c) — inside a **second** fail-soft envelope wrapping the new
`emitReport()`, for the same reason the outer one is inline: `CheckRunner` deliberately does not
catch, so *"a probe failure degrades to one warn"* stays a property of `handle()` rather than an
assumption about three checks' callees.

**The three constraints 3a handed forward are discharged, and each is asserted rather than assumed.**
(1) The throw constraint — every throwing call sits inside a per-iteration `catch`, and two direct
tests prove a mapping-N failure erases neither the mappings before it nor the ones after it, and that
a throw part way through one mapping keeps what that mapping had already yielded. (2) The `catch`
arms carry their own tests, per stage 2's finding. (3) The exit contract — the #4553 legs are the
only `fail`, the tests assert `Severity::Fail` rather than a message, and
`CheckCommandSeverityContractTest` pins fail → non-zero exit.

**Mutation-proven, and the proof paid for itself.** Forty mutants — one reverted guard each — every
named test verified red, then restored. It found **three real gaps**: tests claiming more than they
witnessed, closed by strengthening the assertions rather than by adding cases (36 tests / 115
assertions → **38 / 120**). **One survivor is justified and deliberately left untested:** the
terminal-agreement gate's `coordCardTerminalStageId === null` arm cannot fire alone for any config
that loads, because `WritebackConfig` throws on a move-on-with-no-terminal mapping. A test for it
would have to fabricate a system-impossible state; the guard documents why instead.

**Coverage-table effect — the predicate total goes 77 → 58**: `48 observed · 2 observed-via-abort ·
8 UNOBSERVED`. Compared as a multiset, **21 predicates depart and 2 arrive**, and the conservation
assertion `77 − 21 + 2 = 58` passed on the first run. The method notes above earned their keep
immediately: the departed set contains `config('bridge.writeback.correlation', 'ref') === 'ref'`
**twice**, which a set-keyed diff would have collapsed exactly as it did in 3a. One arrival is a
rename rather than a migration — the `$writeback !== null && $writeback->mappings !== []` guard is
re-expressed as `$ctx->writeback !== null && …` once the config lives on `CheckContext` — the same
shape 3a recorded, and 3a's own rename-arrival (`isset($ctx->coordCardMoveScopes[$repo])`) departs
here for real. **Net of the rename, 20 predicates leave `handle()` and exactly one arrives — the
`emitReport(CheckSlot::WritebackProbe)` guard** — so 77 − 20 + 1 = 58, which is −(n−1) again.

**No surviving predicate changed status.** Zero, as in stages 2 and 3a.

**No gap closed, and none opened — and this stage is where that distinction matters most.** The
disclosed-gap count falls **17 → 8**, its largest drop yet, and a reader who stops at that number
will conclude the golden suite got substantially stronger. It did not. **All nine reductions are
departures:** each left `handle()` *while still UNOBSERVED* — the `startedFromStages` and
`unparkFromStages` loops, the `byRefAvailable` reachability probe, the swimlane-membership guard, the
`issue_number` registration leg, both coord-card stage guards, the missing-custom-field guard, and
the correlation-mode ceiling leg. **Gaps closed in place: zero. Gaps newly opened: zero.** The count
fell because the measured region shrank, not because the measurement improved. What changed is where
those nine are measured: all nine land in the three new unit tests, which are mutation-proven — a
strictly stronger instrument than the golden files for legs the fixtures could never distinguish.

**Every disclosed gap now sits outside the writeback cluster**, and six of the eight sit in the
per-agent region the remaining stages still have to migrate: the two channel-transport liveness probes (unix
socket and TCP), the channel bind-failure marker, and the three agent-registry/per-agent-config legs.
The remaining two are secret-file permissions and directory writability. **So the next stages inherit
a gap-dense region rather than a well-measured one** — like 3b, they must carry unit tests for those
legs from the start, because a green golden run says nothing about any of them.

### Stage 4 result — pure assertion, and an `observed` verdict that does not mean what it looks like

**What migrated:** two checks under a new `CheckSlot::Database`, registered in the output order
the inline code held — `database.connectivity` then `database.install_suffix`. The slot sits
between the secret-dir leg and the inbox-surfacing one.

**No `CheckContext` field was added, and neither check reads one — the first cluster that is pure
assertion.** Neither unit consumes derived state, and nothing downstream consumes the PDO the
connectivity leg opens (the inline code discarded the return value). So plan constraint (c), which
shaped stages 2, 3a and 3b, is simply not engaged here. Worth stating rather than leaving as an
absence: a migration PR that adds no context field otherwise reads as an omission.

**Two checks rather than one, under 3a's rule: group only when output ORDER forces it.** Each of
these emits exactly one line and they are sequential, so the granularity that
`writeback.mapping_config` had to give up costs nothing here — and two ids give stage 8's inventory
a finer split for free.

**The `try`/`catch` moved INTO `database.connectivity`, and no inline envelope was added.** That is
the narrow form of the rule stage 2 set and 3a sharpened: a catch that wraps ONE probing call and
no derivation travels with the check, exactly as `RetentionPostureCheck`'s marker read does. The
whole-cluster fail-soft envelopes stayed inline for the opposite reason — they wrap derivation too.
**It spans both calls deliberately:** resolving the connection can throw on its own (an unsupported
driver, an unparseable DSN) before `getPdo()` is reached, so a `try` around the connect alone would
abort `bridge:check` on a misconfiguration it exists to report. Both routes are covered separately,
because a single-route test passes against the narrower `try` — and one mutant proves it does.

**The fixture set reaches only the healthy branch of both units, and the coverage table does not
say so.** Every golden fixture prints `database: connected` and `install-suffix DSN check: ok`. The
crosstalk predicate nevertheless reads **`observed`**, and the reason is worth recording because it
generalizes: the negated mutant enters the failure branch with a null diagnosis and prints an
**empty** error line — `Illuminate\Console\Command::error()` takes an untyped parameter, so null
renders as a blank rather than throwing. That is a different golden file, hence `observed`. So
`observed` means *"a change to this branch would be caught"*; it does **not** mean *"the failing
message is asserted anywhere"*. Nothing in the suite rendered that diagnosis before this stage.
Generalized: **a predicate's status is about DISTINGUISHABILITY, never about coverage of what the
branch actually says** — the same trap as stage 3b's disclosed-gap drop, one column over.

**The DB leg contributes no predicate at all**, being a bare `try`/`catch` that
`bin/check-golden-mutate.php` never walks (stage 3a's second-blind-half finding). Its failure arm is
therefore measured *only* by `DatabaseConnectivityCheckTest`.

**Mutation-proven — seven mutants, each reverting one guard, every one red on the named test and
the sources restored byte-identical after each.** Two are worth naming. Narrowing the connectivity
`try` to `getPdo()` alone reds the unresolvable-connection test and *leaves the unopenable-file test
green*, which is the evidence that the two routes are distinct and the span is load-bearing rather
than incidental. And inverting the crosstalk guard reds its test via a **TypeError** — the migrated
leg passes the diagnosis to `Finding::fail(string)`, where the inline code passed it to the untyped
`error()`. The blank-line state is unreachable in correct code (the guard returns a string or null,
and null takes the ok branch), so this is not an operator-visible change; it is a silent-blank
failure mode that a future edit can no longer reintroduce.

**No new golden fixture, and the reason is method rather than cost.** A `-prod` suffix with a
non-matching database name IS fixture-reachable, so this leg could have been closed by a fixture
instead of a unit test. Rejected: adding a fixture mid-migration moves the measured baseline, and a
later reader cannot then separate *"the measurement improved"* from *"the measured region moved"* —
the exact distinction stages 3a and 3b spent their result sections protecting. What a fixture would
add beyond the unit test is the renderer half, and that is already pinned by `emitFinding()`'s
exhaustive `match` plus `CheckCommandSeverityContractTest`'s fail → non-zero-exit property, with
`InstallGuardTest` already driving `bridge:check` to exit 1 on a crosstalking install.

### Stage 5a result — the stage that split on a measured line, and the registry's only abort slot

**Why stage 5 split, and where the line falls.** The split is **measured, not structural**: seven of
the cluster's units carry zero disclosed gaps, while all eight of the program's remaining gaps sit
in the other four. Those halves have different *proofs* — a green golden run is the evidence for the
first, only unit tests can be for the second — so a single PR would have been exactly as legible as
its weakest half. **5a** takes the units asserting on the parsed config alone; **5b** the per-agent
secret/token and channel-transport legs (five of the eight gaps; the two liveness probes need a
probe-environment injection, the `SshProbeEnvironment` shape); **5c** the post-loop registry and
identity legs (the other three).

**What migrated:** three checks — `agent.classifier_resolvable` (the out-of-process `probeLoadable`
gate, the in-process `for()` resolution, and the `agent config ok` line), `agent.ci_failure_filter`
(the DL-197 CI-failure-name advisory) and `agent.wake_membership` (the DL-213 `comment_to` advisory).

**Two slots, and the reason is the `continue`, not output order.** `CheckSlot::AgentClassifier` is
the registry's **only abort slot**: `CheckCommand` reads its report and `continue`s the agent
iteration on a `fail`, so every remaining leg for that agent is skipped — the semantics the inline
code held, where an unresolvable classifier meant there was no point asserting anything else about
the agent. That makes registration there different in kind from every other slot, and the constraint
is stated on the enum case rather than left to be rediscovered: **a check whose `fail` should not
skip the agent's remaining legs must not be registered in `AgentClassifier`.** Today the slot holds
one check whose only `fail` *is* the resolution failure, so the coupling is exact; a second one would
widen the abort silently. `CheckSlot::AgentPolicy` exists precisely so the two advisories — which
must **not** abort — have somewhere else to go, and it runs after the silent classifier-derivation
block, which prints nothing.

**No `CheckContext` field was added; all three read only the `AgentConfig` handed to `runFor()`.**
That is the second consecutive stage with no context field, and for the same reason worth stating
rather than leaving as an absence: plan constraint (c) simply is not engaged by a unit that consumes
no derived state.

**Each lazy-config-key `catch` travels with its check — the narrow form, one read and no
derivation.** `ci_failure_workflow_patterns` and `wake_membership` are lazily parsed (unlike
`families` / `scope_author_map`, which `AgentConfig::load` parses eagerly), so a malformed value
first throws at the read site. `CheckRunner` **deliberately does not catch**; keeping each `catch`
with its own check is therefore what makes *"one bad value fails one agent"* a property of the
design rather than an assumption about the runner. Had the runner caught centrally, the same
observable behaviour would have been an accident of where the boundary happened to sit.

**The `agent config ok` line's MESSAGE and its SEVERITY are pinned by different things, and only one
of them existed before this stage.** The golden suite pins the message — it is byte-compared in
every passing fixture — but nothing in the golden corpus can distinguish an `ok` from an `info`,
because both render the same text. The severity is pinned *only* by the new
`AgentClassifierResolvableCheckTest`. This is the same distinction stage 4 recorded one column over:
a green golden run witnesses **what is printed**, never **at what severity it was classified**.

**The abort itself is fixture-pinned.** Golden fixture `agent-classifier-missing` reds when the
`continue` is dropped, so the one coupling this migration carries is not resting on the enum
docblock alone — the behaviour has a test that fails when it changes. That mattered more here than
in earlier stages, because the abort is the only semantic in the cluster that a reader could
plausibly "clean up" without noticing what it does.

**A stale source comment was corrected in passing, and the class of error is the point.** The
post-loop `consumedEventTypes()` block cited `line ~172` for where `for()` had already resolved the
instance; that reference had been wrong for several stages. It is now described by condition — *"in
`AgentClassifierResolvableCheck`, after its `probeLoadable` passed — a cache hit here, never a fresh
load"* — because `bin/check-doc-refs.php` gates line-number citations in `CLAUDE_*.md` and the same
reasoning applies to source comments, which nothing gates. A line number is a claim about state; it
goes stale silently.

**Operator-visible change: none.** Golden output byte-identical at 37 tests / 79 assertions with
`UPDATE_GOLDEN` unset — the assertion count is the load-bearing half of that claim, since a run with
the variable set regenerates rather than asserts and still reports green. Full suite 1593/1593
(3791 assertions), phpstan L7 zero, pint clean, `check-doc-refs` clean.

**Mutation-proven — twelve mutants, each reverting one guard, every one red on its named test, and
all twelve sources restored byte-identical afterwards.** The driver carries its own **positive
control**: an inert edit that must come back GREEN, so a run in which every proof "passes" because
the driver silently stopped testing anything is distinguishable from a real one.

**Coverage-table effect — the predicate total goes 58 → 56**: `46 observed · 2 observed-via-abort ·
8 UNOBSERVED`. Compared as a multiset, **four predicates depart and two arrive**, and the
conservation assertion `58 − 4 + 2 = 56` passed on the first run. Neither number is one-per-check.
Three checks migrated, but `agent.wake_membership` alone contributed **two** inline predicates — the
`$coordMessageOn && …has('wake_membership')` guard and the `! in_array('comment_to', …)` test —
alongside the `probeLoadable` gate and the CI-failure-name guard. The arrivals, meanwhile, are one
per **slot** rather than one per check: `emitReport(CheckSlot::AgentClassifier)` and
`emitReport(CheckSlot::AgentPolicy)`. So the familiar −(n−1) reads **−(n−2)** here, and the extra
arrival is the visible price of the two-slot split the abort semantics forced — folding all three
checks into one slot would have read −3 and quietly widened the abort.

**No surviving predicate changed status.** Zero — as stages 2, 3a and 3b each recorded. (Stage 4
did not record this measurement either way, so this is not a claim about an unbroken run.)

**The disclosed-gap count holds at eight — and this time the SET is identical, not merely the
count.** Zero gaps departed, zero arrived, zero closed in place. Stage 3b had to warn that a falling
count meant a shrinking measured region rather than a better measurement; the inverse warning applies
just as much to a *stable* one, and only the multiset comparison distinguishes them — a stage that
moved one gap out of the measured region while introducing another would also report `8`. The zeroes
are what make this stage's claim exact, and they are the measured confirmation of the split recorded
above: the classifier plane carried **no** disclosed gap into 5a, which is precisely why a green
golden run is sufficient evidence here and will not be for 5b or 5c.

### Stage 5b result — the first stage that added no slot, and a pin that had to be given a way to fail

**What migrated:** four checks — `agent.webhook_secrets` (per-subscription secret presence + perms),
`agent.api_tokens` (per-provider API-token presence + perms), `channel.token_path` (the DL-008
`ChannelToken::read`) and `channel.transport` (the socket parent-dir / `.FAILED` marker / liveness
legs and their HTTP counterparts).

**Why four and not two.** The secret and token legs share a secret-dir gate but iterate different
things — every subscription vs each distinct provider — and fail with different consequences (the
receiver 401s vs `bridge:provision` skips a provider). Folding them would hand stage 8 one inventory
row that means two things. The transport legs go the other way and stay **one** check, because the
inline code selected socket-vs-url with an `elseif`: two checks would each have to re-derive that
exclusivity, which is the duplication canon #5 names.

**NO SLOT WAS ADDED — the first stage of the migration where that is true. The enum did not shrink.**
Both halves matter and only one of them was obvious in advance. The migrated legs were contiguous
with `ChannelSnapshotCheck`, so all four register into the existing `CheckSlot::AgentConfig` and that
slot's single call site simply moves up to where the legs started; **arrivals: zero.** But the enum
still holds the same nine cases it held at 5a — a slot BOUNDARY inside `handle()` disappeared, not a
slot. The enum collapses when the *last* unit migrates, which is what its own docblock says and what
this stage does not do. (An earlier draft of this stage's commit message claimed "the enum shrinks
toward the target design"; it was measured false — nine cases before, nine after — and the claim is
recorded here rather than left in circulation. It is the same shape stage 2 recorded three times: a
claim stated at a strength its evidence does not support.)

**One seam, and only the connect is behind it.** `ChannelProbeEnvironment::probe(dsn)` answers
whether anything is listening; `SystemChannelProbeEnvironment` is the real-host implementation, bound
in `BridgeServiceProvider` — the `SshProbeEnvironment` shape. **Everything else those legs read is
constructible in a test and is therefore NOT seamed:** filesystem state from a temp dir, and
`XDG_RUNTIME_DIR` from `putenv` — both already how the golden harness works. A live-vs-dead endpoint
is not constructible in-process, and the transport's error text reaches the operator's message while
being platform-dependent, which is the class of host input the golden harness's pinning exists to
eliminate. Widening the interface to wrap `is_dir()` would have bought no measurement.

**`$errno` was written and never read at both probe sites and does not survive the migration.** Only
`$errstr` reaches a message, and only on the HTTP arm.

**The golden harness now pins the probe for every fixture — and the pin needed a way to fail.** No
fixture reaches either connect (the unix arm needs a real socket file none creates; the one
`channel.url` fixture has no port), so binding `GoldenChannelEnvironment` changed **no golden file**
— verified empirically rather than asserted. That is also precisely what makes the binding
untestable on the golden set alone: an unchanged corpus is equally consistent with a binding that
never took effect. So the stage adds `channel-probe-pin-potency`, an install shape whose
`channel.url` HAS a port, deliberately **absent from `fixtures()`** — a golden fixture would move the
measured baseline mid-migration, and a later reader could not separate *"the measurement improved"*
from *"the measured region moved."* Its test reds when the binding is removed (proven), and without
it the pin would have been a decoration. The landmine it closes is real: the next fixture author to
write an explicit port would otherwise have captured whether the operator's box happened to have
something listening on it.

**The unit tests are the deliverable, and one of them cannot run everywhere.** Five of the eight
disclosed gaps were in this cluster, plus the `ChannelToken` `catch` arm — which is **invisible** to
the coverage instrument rather than merely unobserved by it, since the instrument walks
`if`/`elseif`/`foreach` only. `! is_writable($dir)` **cannot be measured as root**: PHP returns true
for any directory, so that test SKIPS with that reason stated rather than asserting vacuously. It
runs here and on the CI runner, neither of which is root; a root runner reports a skip, never a false
pass.

**Mutation testing exposed an over-determined clause, recorded rather than fixed (card#5538).** The
socket-liveness conjunction carries both `! is_link($socket)` and `filetype($socket) === 'socket'`.
PHP's `filetype()` is lstat-based — a symlink reads as `link` — so the type test already excludes
every path the link test would, and **neither clause can be mutation-tested alone: each masks the
other.** Deleting either leaves the symlink test green; only deleting both reds it, which is why that
test asserts the outcome rather than a clause. The pattern is load-bearing in
`SocketEndpoint::assertValid`, where the two clauses throw **different** messages — the migration
inherited the shape from a context where it discriminated into one where it cannot. Left untouched
here: stages 0–7 hold a byte-identical migration contract, and an output-neutral simplification can
land on its own afterward. Sibling audit done and bounded: three `is_link` sites in `app/`, and the
third (`ChannelSnapshotProbe::resolveNonStrict`) is unrelated path resolution.

> **RESOLVED after the migration stages (card#5538).** `! is_link($socket)` is gone; the conjunction
> now carries the type test alone, and the adjacent comment states *why* one clause covers both. The
> paragraph above is kept as the stage-5b record of how the clause was found, not as current code
> state. Verified on removal: golden corpus byte-identical, and the two exclusion tests
> (`…regular_file_at_the_socket_path_is_never_probed`, `…symlink_to_a_live_socket_is_never_probed`)
> **watched go red** with the remaining type test deleted — so the surviving clause is the one
> actually carrying the behavior, not a second unmeasured passenger.

**Operator-visible change: none.** Golden output byte-identical with `UPDATE_GOLDEN` unset — the
assertion count is the load-bearing half of that claim, since a run with the variable set regenerates
rather than asserts and still reports green. The golden suite goes **37 tests / 79 assertions → 38 /
80**, the single added test being the pin-potency one; the corpus itself is unchanged. Full suite
**1593 + 31 = 1624** (**3791 + 85 = 3876** assertions), phpstan L7 zero, pint clean, `check-doc-refs`
clean.

**Mutation-proven — fourteen mutants**, each reverting one guard, every one red on its named test and
green again after restore, with every source restored byte-identical afterward. Two rows had to be
rewritten before they proved anything, and both failures are worth keeping: replacing the
`catch` arm's `yield` with `return` stopped `runFor()` being a generator at all, so the suite died
with a fatal — red, but for the wrong reason and therefore not evidence; and the symlink clause
mutation came back GREEN, which is what surfaced the over-determination above. **A driver that
classified any non-zero exit as "proven" would have recorded both as passes.**

**Coverage-table effect — the predicate total goes 56 → 38**: `33 observed · 2 observed-via-abort ·
3 UNOBSERVED`, measured in 27 minutes. Compared as a multiset, **eighteen predicates depart and
ZERO arrive**, and the conservation assertion `56 − 18 + 0 = 38` passed on the first run.

**The zero is the measurement that confirms "no slot was added."** Every previous stage bought its
migration with at least one arrival — an `emitReport(CheckSlot::…)` call site, one per *slot* rather
than per check, which is why stage 5a read `−(n−2)` instead of the familiar `−(n−1)`. Here the
`AgentConfig` call site already existed and only moved, so the arrival column is empty for the first
time. The stage was *predicted* to depart ~19 and land at ~37; it departed **18** and landed at
**38**, so the prediction was off by one on departures — recorded because a prediction that is only
checked when it is wrong is not a check.

**No surviving predicate changed status, and the one line that looks like an exception is not one.**
The multiset diff lists `foreach $cfg->subscriptions` as changed, but the change is its REPEAT COUNT
— four occurrences in the old table, three in the new, all `observed` in both — because one copy
departed with the migrated secret loop. The tool keys on the sorted status *list*, so a count change
surfaces in the same column a status change would. That is the multiset semantics working as
intended (a `(kind, source)` pair is not unique), not a status regression, and it is worth stating
because the two are indistinguishable at a glance.

**The disclosed-gap count falls 8 → 3, and that closes NOTHING.** Five gaps LEFT the table entirely,
zero arrived, and — the number that matters — **zero were closed in place.** The instrument is
bounded to `handle()`, so a predicate that migrates into a check simply stops being measured by it;
nothing about those five branches got safer, and what stands behind them now is
`AgentWebhookSecretCheckTest` and `ChannelTransportCheckTest`, not the golden suite. This is stage
3b's warning firing a second time, and it is the reason the count is never quoted here without the
multiset accounting beside it: a stage that closed five gaps and a stage that moved five out of
scope both report `3`.

**The three survivors are exactly stage 5c's cluster** — the post-loop `$configs !== [] &&
is_string($configDir)` guard, the `AgentRegistry` collisions walk, and the `$configs` walk behind
`treat_as_signal` / `BRIDGE_DEFAULT_AGENT`. The gap-dense region 3b predicted has now been reduced to
one stage's worth of work, and 5c inherits the same rule 5b just executed: unit tests are the proof
there, because a green golden run cannot be.

### Stage 5c result — the first slot whose position is forced by what its checks read, and the context field that is deliberately not the obvious one

**What migrated:** four checks — `agent.identity_collisions` (the roster id collisions),
`agent.treat_as_signal` (every agent's echo-suppression allowlist resolved against the roster),
`agent.default_agent` (`BRIDGE_DEFAULT_AGENT` names a scanned config) and `agent.shared_identities`
(the optional `shared-identities.json` report) — into **one new slot, `CheckSlot::AgentRoster`.**

**A slot was added, and its position is forced rather than chosen.** Every unit here asserts against
the roster *as a whole* — collisions ACROSS agents, `treat_as_signal` resolved against every known
name, a default agent naming one of them — and that roster does not exist until the last YAML has
been read. This is the first slot in the migration whose ordinal is fixed by what its checks READ
rather than by where unmigrated inline code happens to print. **The slot costs exactly one
arrival** — its single `emitReport` call site. (The multiset diff reports two arrivals; the second is
a survivor whose condition text changed, and the paragraph on the split below is why that
distinction matters.) Stage 5b's arrivals were zero, which is a property of that stage's contiguity,
not a trend the migration is on — recorded here so the zero is not read as one.

**Why four checks and not two.** The collision and `treat_as_signal` legs share a registry, a guard
and a post-loop position, which is most of an argument for folding them. They stay separate because
they differ where it counts: a collision is a `warn` an operator may knowingly accept, an
unresolvable signal name is a `fail` that flips `bridge:check`'s exit code, and the repairs differ
(rename an identity vs. correct a name). Folded, they would hand stage 8's inventory one row that
means two things — the same reason stage 5b kept its secret and token legs apart.

**The registry build stays inline as DERIVATION, not assertion (constraint (c)) — and this one is
not a judgement call.** `AgentRegistry` finds and LOGS identity collisions AT CONSTRUCTION;
`collisions()` only returns what the build already accumulated. Two checks each constructing their
own registry would therefore re-log every collision on a colliding install. That is a behavior
change **the byte-identical output contract cannot see**, because it lands in the log rather than on
stdout — the migration's own guard rail is blind to it. So the build stays in `handle()` and both
readers share `$ctx->registry`.

**`CheckContext` gains three fields, and one of them is deliberately not the obvious one.**
`configDir` answers only whether a path can be FORMED (a non-existent or insecure dir is still a
string here and is reported by its own leg); `registry` is the shared roster; and `agentNames` is
**every `<name>.yml` the scan SAW, which is NOT the names of `configs`.** The scan records a name
before the load is attempted, so an agent whose YAML is malformed is in `agentNames` and absent from
`configs`. Resolving `BRIDGE_DEFAULT_AGENT` against `configs` — the field that already existed, and
the reuse a reviewer would wave through — makes the check tell the operator to create a file that is
sitting right there, while a separate line has already reported the real fault.

**One bounded output divergence, found by the second review pass and accepted rather than fixed.**
The default-agent warning renders `$ctx->configDir`, a `?string`, where the inline code rendered
`handle()`'s raw `config('bridge.config_dir')`. Those differ on exactly one value: `BRIDGE_CONFIG_DIR`
set to the literal `true`, which Laravel's `env()` coerces to boolean `true` — verified against the
running app rather than reasoned about. The old line then read `… no matching config 1/<name>.yml`
and the new one reads `… /<name>.yml`, on an install that has already printed `bridge.config_dir
(BRIDGE_CONFIG_DIR) is not set` and will exit non-zero. It is not repaired here, because reproducing
it means carrying the un-narrowed value beside the narrowed one — the bool-plus-string pair
`CheckContext` exists to refuse. A sibling audit across all 26 check classes found **one** of 91
`Finding` sites rendering a nullable context path into a message, so there is no second copy and
nothing to consolidate.

**No golden fixture pairs a malformed YAML with a default agent naming it, so the byte-identical
contract is blind to that swap.** `AgentDefaultAgentCheckTest` is what is not: it pins the
malformed-config case directly, and is the highest-value mutation proof in this stage's set. The
same shape recurs across the stage — no fixture reaches a collision, and none reaches the
`treat_as_signal` throw — so for three of these four checks a green golden run is not evidence.
What the unit tests are is the direct assertion of those arms, **not** the whole suite's
measurement of them: the later mutation run recorded on card#5551 showed `BridgeCommandsTest`
reaches both the collision walk and the `treat_as_signal` throw through the command. Corrected in
place rather than left standing — it is the fixture-scope-stated-at-whole-suite-strength shape this
program has now hit repeatedly.

**Coverage-table effect — the predicate total goes 38 → 35**: `32 observed · 2 observed-via-abort ·
1 UNOBSERVED`, measured in 25 minutes on a detached copy. Compared as a multiset, **five predicates
depart and two arrive**, and the conservation assertion `38 − 5 + 2 = 35` passed on the first run.
The per-agent `catch` appears on neither side of that sum — the instrument walks
`if`/`elseif`/`foreach` only.

**The prediction was right on the total and wrong on the split, and the way it was wrong is a method
note.** `38 − 4 + 1 = 35` was checked against the diff *before* the run — four departures (the
collisions `foreach`, the `treat_as_signal` `foreach`, the `BRIDGE_DEFAULT_AGENT` `if`, the
shared-identities `if`) and one arrival. The run returned `38 − 5 + 2 = 35`. The extra departure and
the extra arrival are the SAME predicate: the derivation guard, whose condition changed from
`$configs !== [] && is_string($configDir)` to `$configs !== [] && $ctx->configDir !== null` when
`CheckContext` gained the field (the two are equivalent — the field is assigned
`is_string($configDir) ? $configDir : null`). **The total was right by cancellation, which is exactly
what the conservation assertion cannot catch: it checks the sum, not the split.**

**Third method note for the multiset diff: the key is the condition's TEXT.** Stage 3a recorded the
first two — compare as a multiset, and assert `old − departed + added == new`. This stage adds the
third. `(kind, source)` keys on the source *text*, so a predicate that SURVIVES a stage with its
condition rewritten is indistinguishable from one that left plus a different one that arrived. The
consequence is specific: **"gaps closed in place" is structurally blind to any predicate whose text
the stage rewrote.** It can only ever report closure for predicates the refactor left textually
untouched, so a zero there means *no closure among the textually-stable predicates*, never *no
closure*. A migration whose whole method is publishing `handle()`'s locals onto `CheckContext`
rewrites condition text as a matter of course, so this will recur in every remaining stage — and it
is the reason the split, not just the total, has to be read off the tool rather than predicted.

**The disclosed-gap count falls 3 → 1, and once again nothing was closed.** Three UNOBSERVED entries
left the table, one arrived, and **zero were closed in place** — with the caveat the note above
attaches to that zero. Two of the three departures are real: the collisions walk and the `$configs`
walk behind `treat_as_signal` moved into checks whose unit tests measure them, which is the
migration working, not the golden harness learning to see anything it could not see before. The
third departure and the sole UNOBSERVED arrival are one predicate changing text. What stands behind
the migrated branches now is `AgentIdentityCollisionsCheckTest` and `AgentTreatAsSignalCheckTest`,
not the golden suite. This is stage 3b's warning firing a third time; the remaining region stays
gap-dense.

**The surviving gap is the derivation guard** (`$configs !== [] && $ctx->configDir !== null`), and it
stays measured BY DESIGN: the registry build is derivation and stays inline, so its guard stays in
`handle()`. It leaves only when the final stage makes `CheckContext` a builder — not before, and a
stage that "closes" it earlier has moved the build somewhere it re-logs.
**[card#5546 SPLIT THAT GUARD'S TEXT WITHOUT MOVING THE BUILD.** The conjunction is now an outer
`$ctx->configDir !== null` — under which the file is read once and published — wrapping the
unchanged `$configs !== []` the build sits behind, so the build still happens exactly once, inline,
under the same effective condition. The predicate this entry names is therefore gone as TEXT, and
the regen re-measured the two that replace it rather than inheriting its verdict: the outer
`$ctx->configDir !== null` came back observed — it now gates the file READ, whose absent arm the
corpus can see — while the inner `$configs !== []` is still a gap under a new id, so nothing was
closed here either. That is this stage's own third method note firing again, one program-stage
later. A feature test asserts the gate directly instead of waiting for a fixture that could see it,
and its negative leg was watched to red against a deliberately widened gate.**]**

**An `observed` verdict said nothing about the message again (the trap fires a second time).** The
`shared-identities-present` fixture is `observed`, yet it pins only `0 shared account(s)`: its JSON
omits the `shared_identities` wrapper key, so the file parses to an empty list and **nothing in the
entire fixture set has ever rendered a non-zero count.** `SharedIdentitiesCheckTest` covers both the
zero and the non-zero rendering. `observed` is a statement about DISTINGUISHABILITY, never about
whether a branch's message is asserted anywhere.

**Filed, not fixed here** — three root-cause items deliberately left out of a migration PR, all
output-neutral: **card#5546** (`shared-identities.json` is read twice per run, so a malformed file
logs the same warning twice — preserved here byte-for-byte because collapsing the reads changes
logging, and stages 0–7 hold the byte-identical contract) **[SINCE LANDED. One read per run,
published on `CheckContext` as a state object the check consumes; the two log lines become one,
and stdout is byte-identical, which is exactly why the acceptance evidence is a log-count
assertion watched to red on the pre-fix code and not a green golden suite.]**, **card#5547** (Check-package source
comments restate program state that drifts; two were already false and were corrected in this
stage), and **card#5548** (the coverage instrument ignores the return of every `file_put_contents`,
so a failed mutation-apply is laundered into an `observed-via-abort` verdict and a destroyed run
reports as a complete measurement — found while reading this stage's regen).

### Stage 6 result — the stage whose slot count is dictated by what migrated FIRST, and the measurement that was stated at the wrong strength

**What migrated:** five checks — `install.config_dir` and `install.secret_dir` (the two directories
the install is built on, each resolvable-then-secure), `install.inbox_config` (the surfacing
layout/mode config), `install.endpoint_urls` (the receiver base URL and the kanban API base URL,
under their two different floors) and `install.provider_adapters` (the B-15 provider ↔ adapter
coverage leg) — into **three** new slots: `CheckSlot::Install`, `CheckSlot::Inbox` and
`CheckSlot::Providers`.

**Three slots for one contiguous-looking region, and the count is forced, not chosen.** The
pre-loop plane reads as one block in the source and is not one: `CheckSlot::Database` (stage 4)
already runs between the secret-dir leg and the inbox one, and `CheckSlot::Retention` (stage 2)
between the inbox leg and the endpoint URLs. Constraint (b) fixes output order by slot ordinal, so
a single slot cannot span a slot that already sits inside it — **the two earliest migrations decided
this stage's shape.** The cost is **three arrivals**, one `emitReport` call site per slot and the
largest arrival count of the migration so far. A reader who has now seen stage 5b add zero slots,
5c add one and 6 add three must not read a trend into it: each number is a property of its region's
interleave, and the three collapse into one the moment the enum's own docblock rule allows.

**Two checks for the two directories, not four — and 5c's splitting rule is what says so.** Stage 5c
split four legs sharing a registry because their operator actions and consequences differed. Each
directory here also has two verdicts of different severity (a `fail` when it will not resolve, a
`warn` when its mode is too open), which looks like the same argument for splitting each into a
resolution check and a permissions check. It is not, and the discriminator is a **guard dependency**:
the permissions warn is not independently reachable — it is defined only once the directory
resolved, so a separate perms check would have to re-derive the resolution verdict its sibling
already owns, and two checks that can disagree about whether the directory exists is a state one
check cannot reach. 5c's legs shared a *data source*; these share a *guard*. A check yielding an
info line and then a conditional warn is what `iterable<Finding>` is for.

**Both dir checks read `config()` RAW rather than `CheckContext`, and that is deliberate.** The
context field is narrowed for its consumers (`is_string($v) ? $v : null`) and is wrong for a leg
that reports on the SETTING in two ways: the empty string is a string, so the field cannot
distinguish the unset case the first branch reports; and a `BRIDGE_CONFIG_DIR` of the literal `true`
reaches `env()` as a bool and arrives as null. That second divergence is the one stage 5c FOUND,
verified against the running app and accepted — accepted on the default-agent *warning*, where the
install has already failed. Reading the field here would move that accepted edge onto the line that
reports the setting itself, which is a changed verdict rather than a changed adjective.

**The resulting double `config()` read is safe, and the reason it is safe is exactly why stage 5c
ruled the opposite way on a read that looked identical.** `handle()` keeps its own
`config('bridge.config_dir')` read as derivation (constraint (c)) — the scan loop needs it — so the
key is read twice per run. Stage 5c met the same shape in `AgentRegistry::loadSharedIdentities()`
and preserved it under protest, filing card#5546, because that read is fail-soft and **logs** on a
malformed file: two reads mean two log lines, a behavior change stdout cannot see. `config()` is a
side-effect-free lookup on an already-built repository — no I/O, no logging, no second anything.
Both rulings are recorded side by side here on purpose, so a later reader does not "fix" one by
citing the other.
**[card#5546 LATER COLLAPSED THE SHARED-IDENTITIES SIDE, and the pairing is why that changes
nothing here.** That read was carded precisely because it logs; the `config()` double read stands on
the opposite ground — nothing to duplicate — so it is untouched and stays untouched. The two were
never one rule, which is what this paragraph exists to say, and one of them landing is not an
argument for the other.**]**

**`warnIfDirInsecure()` was extracted, not copied, because this is the stage its second caller
arrives in.** It was a private method on `CheckCommand` with exactly two call sites — units 2 and 4
of this very stage — so migrating them meant either one shared primitive or two copies of a
permission verdict. It became `DirectoryPermissions::warnIfInsecure()`, a name that no longer exists (renamed `verdictFor()` in DL-265, when it
gained a `fail` arm the old name denied), returning `?Finding` rather
than yielding, because each caller decides where the warn sits in ITS output: the config-dir check
emits it straight after its own ok line; the secret-dir check emits it only on a split layout. This
is the single-caller-set move stage 2 made with `earlyFinishIndicated()`, one stage later than
that rule's threshold and exactly at it.

**No `CheckContext` field was added — the second pure-assertion stage after stage 4.** Every value
these five checks read is `config()`, and migrated checks already read `config()` directly where the
value IS config rather than a `handle()` local. A speculative field would violate `CheckContext`'s
own docblock rule that fields arrive as the checks reading them migrate.

**Two of this stage's own design measurements were false, and the shape they share is now a filed
root cause.** The card's fixture accounting was written from `GoldenInstall`'s setup rather than
from the rendered corpus. It claimed the **secret**-dir permissions warn is rendered by zero
fixtures (`config-dir-missing` renders it: that fixture repoints `config_dir` at a path that does
not exist and leaves `secret_dir` at the install root, so the split-layout guard is true there) and
that the **config**-dir warn is rendered by all 33 (it is 32, for the same reason). Neither error
touched the design — the three-slot split, the five checks, the raw-`config()` rule and the
departure/arrival prediction all stand — but both changed what the unit tests must prove, which is
the only reason they were caught. The accurate and still load-bearing statement is narrower and more
useful than the false one: **no fixture exercises a DELIBERATE split layout**, and the one that
renders the warn does so incidentally, so the corpus cannot distinguish a check that consults the
DL-014 guard from one that ignores it. `InstallSecretDirCheckTest` is what can.

**That miss is a claim stated at a strength its evidence did not support — the shape this program has
now met in prose, in an instrument, and in merged code.** A measurement taken at fixture scope was
restated at whole-suite scope. A sibling audit found two further instances, one of them in a MERGED
stage's docblock, and the fix is a rule about how coverage claims are worded rather than another
careful pass — filed as **card#5551**, which also corrects the fix wording card#5547 prescribes,
because that wording propagates the same defect.

**What the golden corpus does not protect here, stated at fixture scope.** Of the 33 fixtures: none
renders the `config_dir … is not set` message; one reaches `config dir is not usable` (the message
card#5698 rewrote to name BOTH causes — absent, or present but untraversable); one reaches
the secret-dir `not set or not absolute` branch; one reaches the receiver-URL leg's refusal — with a
value that is not a URL at all — and **none** reaches the https-floor refusal that guards the
token-bearing kanban URL; one reaches `has no adapter`; and all 33 print the inbox ok line, so **no
fixture reaches the inbox failure path** — which is a `catch` arm, absent from the disclosed-gap list rather than listed there,
because the instrument walks `if`/`elseif`/`foreach` only. Four branches therefore have no golden
coverage at all, and for those the unit tests are the whole proof: 24 tests / 66 assertions across
five test classes, each load-bearing predicate mutation-proved (14 proofs, RED on revert and green
after restore, source byte-identical afterward). Four of the fourteen prove by making the guarded
call unreachable-safe — the mutant raises a `TypeError` or a stat error rather than failing an
assertion — which is the demonstration, not a weaker version of it.

**Coverage-table effect — the predicate total goes 35 → 29**: `28 observed · 0 observed-via-abort ·
1 UNOBSERVED`, measured in 20 minutes on a detached copy. Compared as a multiset, **nine predicates
depart and three arrive**, and the conservation assertion `35 − 9 + 3 = 29` passed on the first run.

**This is the first stage whose prediction held on the SPLIT as well as the total, and that is a
property of the stage, not an improvement in method.** The card predicted the nine departures
individually and three arrivals — one `emitReport` call site per new slot — and the run returned
exactly that set. Stage 5c's third method note explains why, read in the other direction: the split
diverges from a prediction wherever a stage rewrites a surviving predicate's condition TEXT, because
the diff keys on that text and cannot tell a rewrite from a departure plus an arrival. **Stage 6
rewrote none.** It lifted nine whole predicates out of `handle()` and added three new call sites,
leaving every survivor textually untouched — which the tool confirms independently: zero surviving
predicates changed status. A later stage that publishes locals onto `CheckContext` will rewrite text
again and the split will diverge again; nothing here makes the prediction more trustworthy next time.

**`observed-via-abort` reaches zero for the first time in the program, by departure rather than by
promotion.** That column has read `2` in every measurement since stage 0, and both of its entries —
the `! is_dir($configDir)` elseif and the secret-dir `not set or not absolute` guard — are departures
of this stage. The golden harness did not learn to tell their branches apart; they left the region it
measures. What stands behind them now is `InstallConfigDirCheckTest` and `InstallSecretDirCheckTest`
with a mutation proof per load-bearing predicate, which is a strictly stronger statement than
*reached, but not shown to be distinguishable* — the column emptying is the migration working, and a
reader who takes it as the corpus improving has it backwards.

**Five checks migrated but only four contributed predicates, and the missing one is the point.**
`install.inbox_config` is a `try`/`catch` in its entirety, and the instrument walks
`if`/`elseif`/`foreach` only — so it appears on neither side of the sum. The nine departures divide
two, two, zero, two, three across the five checks. A reader reconciling check count against predicate
count will come up exactly one check short, and the shortfall is the check whose failure path the
coverage table has never been able to see at all.

**The disclosed-gap count holds at 1, and for once the zero carries no caveat.** Zero UNOBSERVED
entries departed, zero arrived, zero closed in place — and unlike stage 5c's, this stage's
`closed in place` zero is not blind, because the surviving gap's condition text
(`$configs !== [] && $ctx->configDir !== null`) is unchanged across the stage. It remains the
derivation guard, which stays measured by design until the final stage makes `CheckContext` a
builder.

**The stage table's own `6–7` row was corrected in this PR, and the correction is load-bearing.**
The row read *"Migrate remaining units, ~1 PR per cluster: `inbox surfacing config`, `board_tools`"* —
written at stage 0, before the region was measured. Taken literally it migrates two units and leaves
**five** inline forever: both directory legs, both URL legs and the provider/adapter leg. That is
not a tidiness problem. **Stage 8's whole premise is an EXACT inventory**, and a unit that never
registered is invisible to the registry — so an under-enumerated row would hand stage 8 a green
inventory that had simply never looked at five legs, which is the precise defect this program exists
to remove, re-minted one level up. The row is now split by position: 6 is the pre-loop plane, 7 the
post-loop one.

**Filed, not fixed here** — output-neutral root-cause items deliberately kept out of a migration PR:
**card#5549** (`CLAUDE_ARCHITECTURE.md`'s `app/Bridge` package map omits the entire `Check` package —
the package this program has been building for six stages) and **card#5551** above. They join
card#5546, card#5547 and card#5548 from stage 5c; 5547, 5549 and 5551 are one doc-work batch to be
done together, 5551's rule first, after stage 7.

### Stage 7a result — the split that is not a seam, and the first predicate total that goes UP

**What migrated:** one check — `event.follows_consumer`, the DL-196 advisory in its entirety
(the card#4354 action inventory, sola's #22 undeclared-classifier warn, the card#4183 unconsumed-type
warn with its #4321 count + last-seen, and the fail-soft `catch`'s card-5170 `unvalidated` line) —
into one new slot, `CheckSlot::EventConsumer`, between the writeback envelope and the board-tools
plane. It is the 4th `emitFinding` call site — the one stage 1 recorded as unable to move alone,
because the `catch` wrapped that method's whole body. **With it gone, `emitReport()` is the ONLY
caller of `emitFinding()` left in the command**: every `Finding` this command renders now arrives
through the registry. (Raw `warn()`/`error()`/`info()` emissions remain in the unmigrated board-tools
plane — that is 7b's, and this claim is about the `Finding` path alone.)

**Row 7 splits on SIZE, and saying so is the point.** Stages 3a/3b and 5a/5b/5c each split on a
MEASURED discriminator — where the disclosed gaps sat, which legs needed a constructed client. This
one does not. Measured at `dev` 8ddea57: this stage's advisory was **169 lines**, and 7b's two
surviving methods (`checkBoardTools`, `probeBoardToolsEndpoint`) are **86 + 93 = 179** before their
call sites and helpers — so the undivided row is ~350 lines of migrated code carrying **6 checks
across 5 new slots** (7b's share is the plan on card#5554, not yet built), against stage 6's 5 checks
and 3 slots, the largest so far. The halves share no seam: 7a reads `$ctx->githubScopeConsumers`
and `webhook_events`; 7b reads configs, a kanban client and the ssh probe environment. Nothing
orders them but output position. The honest reason for splitting is that one PR would be twice the
review surface of the largest stage yet, and **the defect this program keeps meeting is a claim made
at a strength its evidence did not support (card#5551) — which is what more surface at the same
attention produces.** A manufactured discriminator would have read better and been false.

**The fail-soft `catch` moved INSIDE the check's generator, and that is the stage-3b constraint, not
a style choice.** `CheckRunner` MATERIALIZES a check's findings before the caller renders any of
them, whereas the inline code had already PRINTED every line above the throw. A `catch` left in the
caller — an envelope, as stage 3a ruled for `writeback.json` — would therefore DISCARD the findings
this check had already yielded: the scopes it got through before the DB hiccup. **No fixture reaches
that catch, so the corpus cannot tell the two placements apart**, and neither can a test that only
asserts the `unvalidated` line — it passes under the wrong placement too. The discriminating test is
the one asserting the pre-throw warn AND the `unvalidated` line TOGETHER, and it exists.

**The predicate total goes UP for the first time in the program, and it is not a regression.** Every
stage so far migrated code living IN `handle()`, so the table fell by (n−1) each time. This stage
migrates a HELPER METHOD: nothing leaves the measured region because none of it was ever in it —
`$this->checkEventFollowsConsumer(...)` was a bare statement, not a predicate, and the method's own
branches lived where the instrument has never walked. What arrives is one genuine new predicate, the
`if (! $this->emitReport($runner->run(CheckSlot::EventConsumer, $ctx)))` call site. **A reader
tracking the total downward will read the rise as a regression unless this says otherwise. 7b will
do it twice more** (it deletes two `if (! $this->checkX(...))` predicates and adds one call site per
new slot), so the rule is stated once, here.

**No `CheckContext` field was added — this stage WIRED one that had waited seven stages for a user.**
`githubScopeConsumers` was declared at stage 0 (c4344fa) with its full docblock and, measured at
`origin/dev`, had **no reader and no writer** ever since: every occurrence was the declaration, its
docblock, or `handle()`'s identically-named LOCAL. Of the four fields stage 0 declared, the other
three gained users at stages 1, 3a and 3b. So `CheckContext`'s own rule — fields arrive as the checks
reading them migrate, never as a guess at what checks will want — has exactly one exception in the
program's history, and this stage closes it: the loop now writes `$ctx->githubScopeConsumers[...]`
and the local is gone.

**Writing one field's docblock falsified two of its neighbours', and that is the more useful
finding.** The new paragraph on `githubScopeConsumers` says its trap is PARTIAL rather than EMPTY —
it is accumulated DURING the per-agent loop, so a check reading it from inside that loop sees the
agents processed so far. Checking whether that made it unique found it does not:
`writebackEmittingScopes` and `coordCardMoveScopes` are written inside the same loop and both
docblocks claimed *"POPULATED AFTER THE PER-AGENT LOOP FINISHES"*. **Both claims were false from the
day the fields were added** (stage 3a), and invisible because every consumer runs in the `Writeback`
/ `WritebackProbe` slots — after the loop — so no reader was ever misled. They are corrected here
rather than carded: a docblock correction beside a paragraph this stage wrote is not a behavior
change, and leaving a false claim next to a new true one is how the next reader learns the wrong
rule. A grep for the same wording across `app/`, `docs/` and `CLAUDE_*.md` found no other instance.

**phpstan's purity inference is DIRECT-CALL-SCOPED, and removing one inline call is what exposed
it.** With the advisory gone, `if ($this->unvalidatedCount > 0)` — the card-5170 disclaimer — became
`greater.alwaysFalse` at level 7. It is not dead: **2 of the 33 rendered fixtures print that line.**
Bisected in a detached copy, one run per arm: the base file is clean; base plus an extra `emitReport`
call site is clean; **base minus the advisory method and its call, nothing else, reds it**; and a
DIRECT `emitFinding(Finding::unvalidated(…))` inserted above the tally does NOT clear it, so the
analyser never propagates the write at all. Annotating the actual mutator (`emitUnvalidated`) or the
renderer (`emitFinding`) leaves the error standing; annotating **`emitReport`** — the method
`handle()` actually calls — clears it. So the gap is latent since stage 1: `emitReport` was always
inferred pure, and the advisory was simply the one call between it and the tally that phpstan did
infer impure. `emitReport` now carries `@phpstan-impure`, which states a true fact rather than
suppressing a finding. **7b removes `checkBoardTools()` and `probeBoardToolsEndpoint()` — two more
direct calls in the same region — so expect the same shape to surface again there; it is the
analyser's model, not a defect the stage introduced.**

**What the golden corpus protects here, measured from the RENDERED files.** Exactly **one** of the 33
fixtures prints an `event-consumer:` line — `event-consumer-unconsumed-type`, rendering ONE of the
four message shapes (the unconsumed-type warn, one type, one agent, pinned last-seen). The action
inventory, the undeclared-classifier warn and the `unvalidated` skip line have **zero** golden
coverage, so for those the unit tests are the whole proof. `event-consumer-nothing-arrived` prints no
line BY DESIGN: it is an ABSENCE assertion — a scope with consumers but no arrived events stays
silent — and it is invisible to a grep for the prefix. **It is the fixture NAMED for that path, not
the only one taking it**, and the distinction is worth the sentence because the first draft of this
section claimed otherwise: measured from the BUILDERS (a rendered file cannot witness a silent path,
which is the whole difficulty), 8 fixtures boot a github-subscribed agent and 7 of them have no
arrived events. What the named fixture adds is a deliberate, labelled witness — not unique coverage.
Neither this
check's predicates nor the method's before it appear in `docs/check-golden-coverage.md` in either
direction: that instrument walks `handle()` only. **One thing the corpus does pin, unusually:
registration.** `CheckRunner`'s docblock records that nothing pins WHICH checks the command registers
— true in general, and not true for this one: dropping it from the registration list deletes a line a
fixture asserts.

**Unit tests + mutation proofs.** 18 tests / 41 assertions in `EventFollowsConsumerCheckTest`, and
**16 mutation proofs**, each RED when the guard is reverted, green after restore, source byte-identical
afterward: the empty-map early return, the empty-`event_type` skip, the 19-char seconds trim, the
per-type count accumulation, the actionless-type guard, the top-level projection, the bare-vs-qualified
split, both clauses of the inventory's skip condition proved separately, the unlisted-empty guard, the
descending `uasort`, the undeclared caveat, the undeclared collection, the unconsumed-empty guard, and
two on the catch (rethrowing ERRORS the paired test; yielding nothing FAILS it). Beyond the golden run,
the migrated body was compared to the original **mechanically** — every code line normalized through
the documented `$this->info/warn` → `yield Finding::ok/warn` transformations, diffed, and the
comparator positive-controlled on a known-different pair: identical.

**Coverage-table effect — the predicate total goes 29 → 30**: `29 observed · 0 observed-via-abort ·
1 UNOBSERVED`, measured in 21 minutes on a detached copy. Compared as a multiset, **zero predicates
depart and one arrives** — the `if (! $this->emitReport($runner->run(CheckSlot::EventConsumer,
$ctx)))` call site, `observed` — and the conservation assertion `29 − 0 + 1 = 30` passed on the first
run. **The prediction held on the split as well as the total for the second stage running**, and for
the same narrow reason stage 6's did: this stage rewrote no surviving predicate's condition TEXT, and
the diff keys on that text (stage 5c's third method note). Zero surviving predicates changed status.
The disclosed-gap count holds at **1** — no gap departed, arrived, or closed in place — and it is
still the derivation guard `$configs !== [] && $ctx->configDir !== null`, whose text this stage does
not touch. It stays measured by design until the final stage makes `CheckContext` a builder.

**Two format arms are unreachable rather than untested, and they are carded, not deleted** **[since
closed — card#5555's own PR deleted both arms once the stage had landed]**. Both
`'unknown'` last-seen renderings need `MAX(received_at)` to arrive non-scalar or empty;
`received_at` is `timestamp(3)` NOT NULL with a DB-side default, so over a non-empty group it is
always a scalar timestamp string. Deleting them changes no output only BECAUSE they never fire —
exactly the edit a byte-identical stage must not smuggle in. Filed as **card#5555**, with the sibling
audit that found the other `'unknown'` renderings in `app/Bridge` guard genuinely-absent sources and
are a different shape.

**Real-surface verification, stated at the strength the evidence supports.** `bridge:check` at this
commit and at `origin/dev`, run in the SAME copied directory with the commit switched between runs:
stdout and stderr **byte-identical**, exit 0 both sides, the diff positive-controlled on an injected
line. The live install prints no `event-consumer` line — **but not because the plane was skipped**:
two agent YAMLs carry github subscriptions and `webhook_events` holds 495 github rows, so the loop,
the grouped query, the projection, the consumed-union and every skip guard ran on real data and
correctly emitted nothing. The four MESSAGE shapes were not exercised live, and no run of this
command on this install would exercise them.

### Stage 7b result — the first context field that is not a fact about the install

**What migrated:** five units into five new slots — the all-configs `suppressedReason` scan
(`BoardToolsSuppressedCheck`), the bearer index's `problems()` (typed at the time; stage 10
dropped the unread `type` key — card#5292)
(`BoardToolsBearerCheck`), the per-agent board-STATE legs (`BoardToolsBoardStateCheck`,
per-agent), the DL-225 flipped-default advisory (`BoardToolsSshDefaultAdvisoryCheck`,
per-agent) and the `--probe-tools` HTTP live probe (`BoardToolsHttpProbeCheck`).
`checkBoardTools()`, `checkSshTransport()`, `probeBoardToolsEndpoint()` and
`probeErrorDetail()` are gone. **`handle()` now holds derivation and runner calls only** —
the last unmigrated assertion in the command is retired, which is what the stage table's
row 7b promised and the off-ramp after stage 7 rests on.

**One slot per unit, and the count is forced, not chosen.** Each boundary is a place where
something OTHER than a check decides what happens next: the suppression scan runs before the
enabled-subset gate (a suppressed block is `enabled === false`, so on a fleet whose only
board-tools agent is suppressed every later slot sees an empty subset — that scan is the only
place its failure surfaces); the bearer problems run after the resolver is BUILT; the board-state
legs run inside the client envelope; the advisory runs in a SECOND pass over the ssh agents,
after the pinned-line slot has reported for every one of them; and `--probe-tools` runs
outside the enabled gate entirely, because "no agent has an enabled block" is a thing it must
SAY rather than skip.

**The first `CheckContext` field that is not a fact about the install.** `sshSetupIncomplete`
is derived from another check's FINDINGS: `CheckCommand` reads
`CheckSlot::BoardToolsSsh`'s report, selects `SshPinnedLineCheck`'s results BY ID, and records
the agents whose severities mean "setup incomplete" — `severityMeansSetupIncomplete()` stays
beside that readback as derivation. Every other field on the context is derived from config,
the filesystem or a board read, and is therefore true independently of the registry. **What it
costs is a real coupling, and it is worth naming rather than hiding:** the advisory is now
downstream of another check's SEVERITY VOCABULARY, so stage 10's warn ↔ unvalidated
re-assignment (cards#5291/#5292) can change what this advisory says with nothing in its own
file to show for it. **[STAGE 10 CONFIRMED THIS, in the sharpest possible form: both of the
pinned-line leg's `warn`s became `unvalidated`, and `severityMeansSetupIncomplete()` had to
widen in the same commit or the advisory would have gone silent on the one golden fixture
that carries it. See the stage 10 result.]** The narrowing that keeps it honest is the by-id selection — a second check
registered into that slot cannot silently start feeding the advisory — and **the property that
selection exists for is unprovable by mutation today, which is not the same as the predicate being
unmeasured.** This stage's coverage run lists the selection as `observed`: NEGATING it stops the
readback entirely and the golden suite reds. What no mutation reaches is the direction the guard is
actually for — DELETING the filter, which is what a second check in the slot would amount to,
changes no output at all while the slot holds exactly one check. It is recorded here rather than
papered over with a test that would prove the observable direction and be read as proving the other.

**The client construction stays inline, under a NARROW envelope, and widening it would have
changed a diagnosis.** The board-tools kanban client is a SECOND construction — the writeback
envelope already built `$ctx->client` under a different guard — and the two are deliberately
not collapsed onto one field: each guard's failure prints its own diagnosis and skips its own
legs, and an install with writeback off and board tools on would otherwise construct a client
where it constructs none today. The `try` wraps the factory call ALONE. Wrapping the loops
below it would make any inner throw print *"the kanban writeback client is unavailable"* —
a changed diagnosis, not a refactor.

**The one predicate the corpus could not prove, and what closing it took.** That envelope's
failure arm skips the board-state legs AND the ssh legs (the inline code did it with a
`return`). Reverting the guard **left the whole suite green**: the only install that
distinguishes "skip the state legs" from "skip the state AND ssh legs" is one with an ssh agent
and NO writeback client, and no golden fixture is one — all three ssh installs construct a
client fine. A feature test now supplies that install, and the mutation reds it. **The general
shape is the one this program keeps meeting:** a restructure that is output-equivalent on every
shape the corpus contains is not thereby a no-op; it is unmeasured.

> **⚠ SUPERSEDED BY DL-275 (card#5474) — the skip set above is no longer current.** Stage 7b
> faithfully preserved the inline `return`, and preserving it was right for a strangler
> migration; what the preservation carried forward was a defect. **The envelope's failure arm
> now skips the board-state legs ONLY.** The ssh legs read nothing from that client — the
> pinned-line probe is offline and the advisory reads a map the ssh loop itself fills — so
> gating them on it let an absent writeback secret silently disarm the ssh security
> certification: the same `pty`-granting forced-command line exits 1 with the token present and
> exited 0 without it. **The paragraph above stays because its lesson is the durable part** (an
> output-equivalent restructure is unmeasured, not a no-op) and because the install it names is
> still the only one that distinguishes the two behaviors — that feature test is now the pair
> that pins the CORRECTED behavior, with the token-present case as its control. Stage 8 had
> already made the skip visible (`N did not run (…)`); what it could not do was notice that the
> recorded reason named a dependency the check does not have.

**Unit tests + mutation proofs.** 48 tests / 125 assertions across the five new check tests
(6/20 suppression, 6/24 bearer, 14/23 board state, 6/18 advisory, 16/40 HTTP probe) plus the
one feature test above, and **27 mutation proofs**, each RED when the guard is reverted, green
after restore, source byte-identical afterward: the suppression guard; the bearer's no-resolver
guard and its `fail`-not-`warn` severity (the exit contract); the board-state client guard, the
0-cards ambiguity predicate, the swimlane membership test, the shared-swimlane operand, the
unread-stages guard, the coord-board predicate, and BOTH catch arms (rethrowing errors the
paired test, yielding nothing fails it); the advisory's explicit-transport guard, its
incomplete-setup guard and its agent-keying; the probe's not-requested guard, nothing-to-probe
guard, the ssh skip's `continue`, the 403/401/non-2xx/missing-result arms, BOTH halves of the
isolation compare, and `probeErrorDetail`'s error-field preference; and three command-side
proofs (the readback severity predicate, the enabled-subset gate, the client guard).

**Beyond the golden run, the migrated bodies were compared to the originals MECHANICALLY** —
every line normalized through the documented `$this->info/warn/error` → `yield
Finding::ok/warn/fail` map plus each unit's declared transformations (the loop-variable renames,
the population the loop walks, the guard INVERSIONS a generator wants, and the exit bookkeeping
that moves to the renderer's `fail` arm), diffed, and the comparator positive-controlled on a
known-different pair: **all five units identical**. That covers the messages no fixture renders,
which for this plane is most of them.

**Coverage-table effect — the predicate total goes 30 → 43, and the table grew because the measured
REGION did, not because the command did.** `42 observed · 0 observed-via-abort · 1 UNOBSERVED`,
measured in 31 minutes on a detached copy. Compared as a multiset, **two predicates depart and
fifteen arrive**, and the conservation assertion `30 − 2 + 15 = 43` passed on the first run. The two
departures are the pair stage 7a's result named in advance — the `checkBoardTools()` call site and
the `--probe-tools` endpoint guard. The fifteen arrivals are six `emitReport` call sites (one per new
slot, plus the ssh pinned-line pass's own) and **nine loop-and-guard predicates that were already
executing at `origin/dev` and were never measured**, because they lived inside `checkBoardTools()`
and `checkSshTransport()`: the enabled-subset gate and the loop over that subset, the client guard,
the two passes over the ssh subset, and the four-predicate pinned-line readback, which moved into
`handle()` verbatim with `$agentIncomplete` becoming `$ctx->sshSetupIncomplete`. **7a's forecast of
this stage — "one call site per new slot" — counted only that first group**, which is five of
fifteen; it was a forecast of what the migration ADDS, and it read as a forecast of the arrival
count. The enumeration run before this stage's mutation pass is what caught the difference. **The
inverse of this file's standing warning about a shrinking gap count applies here:** the table growing
by 13 is not new branching and is not worse protection, it is previously-invisible code entering the
measured region — which is what "`handle()` now holds derivation and runner calls only" means when
the instrument only walks `handle()`.

**Every arrival is `observed`, and for the call sites the mechanism is the exit code, not a printed
line.** Each `if (! $this->emitReport(...))` body sets `$ok = false` and nothing else, and the golden
harness captures stdout AND the exit code — so negating one of those is caught even on a fixture
where the check prints nothing. Zero surviving predicates changed status, and the disclosed gap is
still the single derivation guard `$configs !== [] && $ctx->configDir !== null`, whose text this
stage does not touch: no gap departed, arrived, or closed in place, so **the disclosed-gap count
holds at 1 while the table grows by 13**.

**`observed` for the client guard is NOT the proof the paragraph above said the corpus could not
supply, and the table has to be read with that in hand.** The instrument NEGATES the condition, and
negating this one skips the board-state legs for installs that have a client — the three ssh
fixtures lose their lines, so it reds. What left the suite green was a different edit entirely: the
guard rewritten back to the inline `return`, which changes behavior only for an install with an ssh
agent and NO client. Two mutations of the same predicate, one visible and one not, and only the
invisible one was the open question. The instrument also runs `--filter test_golden_output` alone, so
the feature test written to close that question **is not in the suite this table was measured
against** — the `observed` verdict here is the golden fixtures' alone.

**The split prediction held; the STATUS prediction did not, and the miss is the half worth
recording.** Departures and arrivals were enumerated from the source and written down before the run
— that held exactly, for the third stage running. Written down beside them was a predicted NEW gap
cluster of one to three predicates in the `sshSetupIncomplete` readback, reasoned from this section's
own claim that the by-id selection is unprovable. All three came back `observed`, and none via
abort. **The reasoning error was treating one direction of a guard as the whole guard** — the
paragraph above has been corrected in place rather than left standing next to a table that says
otherwise, which is the card#5551 shape caught on its own author.

**phpstan predicted a red and did not produce one — because stage 7a had already fixed it.**
This stage deletes two more direct calls from the region above the card-5170 tally, the exact
edit that made the analyser call that guard dead in 7a. It stayed clean at level 7, and the
reason is not luck: `emitReport()` now carries `@phpstan-impure`, so the tally's liveness no
longer depends on which OTHER direct calls happen to sit between it and the mutation. The 7a
annotation was the general fix, not a local one, and this stage is the evidence.

**What the golden corpus protects here, measured from the RENDERED files.** **Five** of the 33
fixtures print a `board_tools`-prefixed line, and only **four** of those lines belong to code
this stage migrated: three ssh installs reach the board-state check's 0-cards arm and the
pinned-line slot (one of them the advisory as well), and `probe-tools-with-no-enabled-agent`
reaches the HTTP probe's *nothing to probe* warn. The fifth,
`probe-tools-ssh-with-no-ssh-agent`, is `SshLiveProbeCheck`'s — stage 1's, not this stage's.
(The first draft of this paragraph said "three fixtures, all ssh"; it was written from the
three fixture NAMES rather than from a grep of the corpus, and undercounted by two.)
**Everything past those four lines has ZERO golden coverage** — the suppression scan, both
bearer problems, the board-state check's other five legs and its catch, and every
`--probe-tools` CERTIFY arm — which is card#5552's measurement (the corpus's one HTTP
board-tools install, `board-tools-http-enabled`, dies at agent-config load and never reaches
the plane). For all of those the unit tests are the whole proof. **The corpus was NOT repaired here on purpose:**
cards#5552/#5553 change the fixture set, and a repaired fixture moves the measured baseline
mid-migration — which stages 4/5a/5b/5c/6/7a all refused for the same reason.

**[Post-stage update — DL-247 (cards#5552/#5553) landed that repair, after stage 7 and before
stage 8.** Re-measured from the RENDERED corpus, not from the fixture names: **six** fixtures now
print a `board_tools`-prefixed line, and **five** of those lines belong to code this stage
migrated — `board-tools-http-enabled` reaches the board-state check's 0-cards arm now that its
agent config loads. **The ZERO-coverage list above is unchanged and still accurate:** the repaired
fixture carries a working bearer and a satisfied block, so the suppression scan, both bearer
problems, the board-state check's other five legs and its catch, and every `--probe-tools` CERTIFY
arm remain golden-uncovered, and for those the unit tests are still the whole proof. What the
repair bought is that the HTTP transport reaches the plane **at all** — not coverage of any leg
past it.]**

**Real-surface verification, stated at the strength the evidence supports.** `bridge:check` at
this commit and at `origin/dev`, run in the SAME copied directory with the commit switched
between runs: stdout and stderr **byte-identical**, 22 lines, exit 0 both sides, the diff
positive-controlled on an injected line. **What that run actually exercised is small, and saying
so is the point:** the live install's four agent configs carry NO `board_tools` block at all, so
the run covered the enabled-subset derivation, the suppression scan over four blockless configs,
the empty-subset gate's false branch, and the probe check's not-requested return. The client
envelope, the board-state legs, the ssh legs, the advisory and every probe arm were NOT
exercised live, and no run of this command on this install would exercise them.

### Stage 8 result — the row's own invariant was false, and the hole was one level up from where it was looked for

**What shipped:** `CheckDisposition`, `OptInCheck`, `CheckInventory`, `CheckRunner::inventory()` /
`registeredIds()` / `noteNotRun()`, an always-printed inventory line, a narrowed `unvalidated`
tally, `CheckCommand::registry()` extracted so the registered set is reachable, and two test
classes — `CheckInventoryTest` (the accounting contract + both positive controls) and
`CheckCommandRegistrationTest` (the pinned id set). All 33 golden files gain one line.

**THE ROW'S INVARIANT WAS FALSE, AND MEASURING IT FIRST IS THE ONLY REASON THAT WAS KNOWN.** The row
read *"every registered check emits ≥1 finding"*. Instrumenting the runner and re-running the whole
suite plus all 33 fixtures, before writing any stage-8 code, gave this for `minimal` — the healthy
baseline install that exits 0 in 11 output lines:

| | count | disposition | what it is |
|---|---|---|---|
| registered | 37 | — | the whole registry |
| **never invoked** | **13** | `NotRun` | the writeback plane (9) + the board-tools plane (4) — their slots sit behind conditional envelopes and were never reached |
| invoked but silent | 13 | `Silent` | ran, yielded nothing — "no identity collisions" is correctly reported by saying nothing |
| never asked | 2 | `NotRequested` | the two opt-in probes, whose flags this invocation did not pass |
| spoke | 9 | `Reported` | |

**CORRECTED IN PLACE — this table first read `invoked but silent | 15`, and that 15 was a
PRE-TAXONOMY count**: it was measured before the stage separated `NotRequested` from `Silent`, so it
folded the 2 un-requested opt-in probes into the silent population and labelled both *invoked*, which
neither is for the probes. It summed to 37 and was wrong anyway — the arithmetic was never the check.
Nothing reconciled it with the line the stage went on to ship (`13 with nothing to report · 2 opt-in
probes not requested`), which is exactly the reconciliation an operator would attempt. The four rows
now ARE the four `CheckDisposition` cases, so the table cannot drift from the enum again without the
sum breaking. (Re-stated only for the baseline split; the corpus statistic below it is phrased in
taxonomy-neutral terms — *yield nothing* is true of all three non-`Reported` cases — and was not
re-measured for this correction.)

Across the corpus, **26 of 37 checks yield nothing on at least one run**, and **no fixture invokes
all 37** (max 33, min 16, median 24). Enforcing the row literally would have required dissolving the
conditional envelopes stages 3a and 7b preserved as *behavior* — running board-tools checks against
an install with no `board_tools` block — and would have taken `minimal` from 11 output lines to ≥37,
which contradicts the row's own operator-visible column (*"disclaimer text changes; opt-in probes
may gain lines"*). **A diagnostic that prints 37 lines of mostly-`ok` prints nothing.**

**This is the same defect the `6–7` row had, with the same cause: a row written at stage 0, before
the region was measured.** Stage 6 corrected its row and recorded the correction as load-bearing;
this row gets the same treatment. The general rule this program keeps re-learning is that **a stage
row is a hypothesis until the stage measures it**, and the measurement belongs before the code.

**THE HOLE WAS NOT WHERE THE ROW LOOKED FOR IT.** The row was aimed at checks that say nothing. The
larger population is checks that were *never asked* — and `CheckRunner`'s own docblock had named it
before anyone measured it: *"A SLOT THAT IS NEVER RUN IS THE SAME HOLE ONE LEVEL DOWN, and this
class does not close it."* It is 13 of 37 on the baseline install. So the un-invoked slots are not a
hole to plug; **they are an undocumented conditional structure in `handle()`, and stage 8's job is
to make that structure declare itself.** The enforceable invariant that follows is:

> **Every registered check is accounted for on every run that COMPLETES — it either ran (findings
> recorded, possibly none) or was deliberately skipped with a recorded reason.**

The qualifier is load-bearing, not hedging: `CheckRunner` deliberately does not catch (that is
stage 3a's preserved behavior), so a check that throws aborts `bridge:check` before the inventory
renders and the operator gets **no** accounting rather than a partial one. Dropping it would state
the property at a strength the code does not implement.

**THE DIRECTION OF DERIVATION IS THE LOAD-BEARING DESIGN CHOICE, and it is deliberate.** `NotRun` is
derived from the *registration list*: anything the run recorded no disposition for did not run.
`noteNotRun()` attaches only the human-readable REASON. The rejected alternative — the caller
declaring each skip — would have made the mechanism's correctness depend on remembering to call it,
**which is the same shape as the bug it closes.** A forgotten reason therefore degrades a message
(and is disclosed at runtime, and listed by `CheckInventory::unexplainedNotRun()`); it cannot open a
hole. This is also what lets `CheckCommand` note the three per-agent slots' reasons *after* the loop,
unconditionally: a reason for a check that DID run is inert, and that inertness is asserted.

**`not requested` is DECLARED, never inferred from an empty yield** — `OptInCheck::wasRequested()`.
The runner cannot infer it, and inferring it is exactly the collapse the resolved opt-in-probe
decision refuses: a check that looked and found nothing and a check nobody asked to look are
different facts, and only one of them says anything about the install. No new `Severity` case, so
the exhaustive-`match` property and the exit contract are untouched by construction, as that
decision required.

**THE OPT-IN AXIS IS NARROWER THAN TWO FIXTURE COMMENTS CLAIMED, and they were corrected here.**
`probe-tools-with-no-enabled-agent` and `probe-tools-ssh-with-no-ssh-agent` carried comments saying
stage 8 would turn their state into a `not requested`/`not applicable` disposition. That **conflated
two axes**: those fixtures pass the flag, and the resolved decision bounds itself to the flag's
ABSENCE. A requested probe with nothing to certify still owes an answer, so both keep their `warn`;
re-assigning it was card#5291's separately-gated sweep, **which adjudicated them and KEPT the
`warn` for exactly this reason — the leg answered its own question (stage 10).** The control pair got *sharper* instead —
passing `--probe-tools` makes that probe run, so the fixture reports **one** opt-in probe not
requested where its control reports **two**, which is now asserted.

**AND THE TEXT RENDERER DOES NOT STAY SILENT ON `not requested`, WHICH THE RESOLVED DECISION SAID IT
WOULD — the § Resolved design decision bullets are corrected in place rather than left to contradict
what shipped.** That ruling reserved not-requested for the JSON renderer *"strictly more than they
can get today"*, on the premise that the text output stays byte-identical. Stage 8 is the row where
that premise expires: it is the gated output-change stage, and `2 opt-in probes not requested` now
prints in **30 of the 33** golden fixtures (`1` in the other 3, which pass a flag). The divergence is
CORRECT and the code is not reverted — omitting the disposition would break the arithmetic that is
the line's own control, and a coverage line the operator gets only part of is the defect the stage
exists to remove — but row 8 says *"applies the resolved opt-in-probe decision above"*, so a reader
consulting that section for exactly this question was being handed a false statement. **The ruling
itself is untouched:** `not requested` is still a disposition and not a `Severity`, which is the part
that was actually resolved.

**WHAT THE INVENTORY LINE DOES NOT CLAIM.** Stated explicitly because a coverage claim pitched above
its evidence is this program's own recurring defect:

- It accounts for the **registered set**. It cannot see a leg nobody ever wrote as a check — the
  same bound `check-golden-coverage.md` carries, one level up.
- It does **not** make the severity vocabulary precise. A leg that could not measure may still
  report `warn` rather than `unvalidated`. That is card#5291's, still open and still gated — which is
  why the `unvalidated` tally SURVIVES, narrowed to that one question, instead of being deleted as
  redundant. Two different claims; conflating them is what the old wording did.
  **[LANDED IN STAGE 10. The tally survives a SECOND narrowing rather than being deleted: with the
  `warn` sites swept, what it now discloses is that the rule is keyed on what a leg CONCLUDED, so
  undisclosed blindness is the residual.]**
- A not-run **reason** is the **envelope's** claim about itself. If an envelope's condition is wrong,
  the reason is confidently wrong with it.
- The not-run population is labelled **`N did not run`** and deliberately not `N not applicable
  here`. **CORRECTED IN PLACE — the line shipped saying `not applicable here` whenever it had
  reasons to print, and five of the recorded reasons are COULD-NOT-LOOK rather than
  does-not-apply:** the committed `agent-yaml-malformed` fixture said *"21 not applicable here (no
  agent config parsed …)"* on an exit-1 install where the agent plane is entirely applicable and the
  YAML is merely broken, and `writeback-malformed` said the same of a present-but-unloadable
  `writeback.json`. One label has to cover the union of *does not apply here* and *could not be
  reached*, and only the weaker one is true of both; the parenthetical already carries the cause, so
  nothing is lost by weakening it.
- The counts are keyed by **check id**, so a per-agent check that ran for two of three agents (the
  third aborted at the classifier gate) counts once, as `ran`, and **nothing on the line scopes it
  to the agents it reached**. This is the granularity cost the plan accepted for the id-keyed
  inventory; it was stated only on `PerAgentCheck::runFor()` and belongs in this list too.
- A `Silent` disposition is **not yet distinguishable from a check falling off the end of its
  generator by accident.** Making a check *declare* its silence is a strictly-additive strengthening,
  deliberately deferred — and deferring it is legitimate only because stage 8 makes the silence
  visible and counted rather than absent. Tracked as **card#5596**, which also records that
  reasoning so the deferral does not later read as arbitrary.
  **[CLOSED — DL-266, card#5596. A check now DECLARES a deliberate silence by yielding
  `App\Bridge\Check\Silence`, and an execution that yields neither a finding nor a declaration is
  named in `CheckInventory::undeclaredSilent()`. See § Follow-on to stage 8 below. The disposition
  itself is unchanged: declaredness rides ALONGSIDE it, so the four `disposition` values, the
  exhaustive `match` and the exit contract are untouched.]**

**THE ARITHMETIC IS THE LINE'S OWN CONTROL.** The dispositions sum to the registered total, so a
reader can see nothing fell out of the inventory without trusting the renderer — asserted across all
33 committed golden files, not spot-checked, because a disposition that leaks on one install shape
only would survive a per-fixture check. The corpus-wide assertion was itself positive-controlled by
corrupting one golden file's arithmetic and observing the named failure.

**MEASUREMENT NOTES worth carrying forward.**

- **`UPDATE_GOLDEN=1` and an asserting run report DIFFERENT assertion counts, and the gap is the
  proof the regen skipped the compare.** Both figures are **OBSERVED**, on
  `vendor/bin/phpunit tests/Feature/Console/Check/CheckGoldenTest.php`, 43 tests either way:
  **asserting 466** (run on the branch with `UPDATE_GOLDEN` unset) and **regen 433** (run with
  `UPDATE_GOLDEN=1` on a throwaway copy of the repo outside the branch, because a regen rewrites
  tracked fixtures; the branch's fixtures were confirmed untouched by `git status` afterwards). The
  gap is **33** — exactly one fixture's `assertFileExists` + `assertSame` pair collapsing to the
  regen path's single `assertTrue(true)` sentinel, over 33 fixtures. A regen reporting 466 would
  mean the compare had run; one reporting 400 would mean the sentinel was gone.
  **CORRECTED IN PLACE — this note first claimed regen 107 / asserting 140, "derived from the source
  rather than observed", and both numbers were FALSE.** The derivation
  (`33×1 + 33 + 38 + 3` / `33×2 + …`) omitted `assertFixtureReachesItsSubject()`, which DL-247 added
  and which runs under `UPDATE_GOLDEN=1` too, and this stage's own
  `test_every_golden_file_carries_a_self_conserving_inventory_line`. The DEVICE is sound and is kept;
  a source-derived assertion count is not, which is the same lesson as the disproved-claims section
  one level down: **every count in this document is re-measured at the source, and an arithmetic
  derivation is not a measurement.**
- **A SEAM TESTED ONLY AT THE CALLEE LEAVES THE CALL SITE UNPROVEN, and re-running the stage's
  mutations against the WHOLE suite found two that survive.** Both are **observed, at 1798/1798 and
  4688 assertions — identical to the unmutated baseline**, with the guarded code deleted:
  - Replacing `emitInventory()`'s `$internal = $this->inventoryInternalDefectLine($inventory)` with
    `$internal = null`. The `bridge:check internal:` disclosure stops reaching the operator and
    nothing notices, because `CheckInventoryLineTest` tested that renderer **directly**: extracting a
    pure method and testing it exhaustively proves the COMPOSITION and says nothing about whether
    anything still CALLS it. **Every one of the eleven recorded proofs mutates one of the two
    renderers; none mutates the call site** — so the set was exhaustive over the thing that was
    already covered and silent about the one thing that was not. **A seam closes a gap only if the
    DECISION TO EMIT lives inside the tested surface**, so the two renderers are now one
    `inventoryOutput()` returning `[channel, message]` pairs and `emitInventory()` is a dispatch loop
    over it; every case ported, plus two asserting the disclosure IS included on the `warn` channel
    when a not-run check is unexplained and absent otherwise.
  - Deleting the `! is_readable($configDir)` arm this same pass ADDED. The confidently-false *"this
    install has no agent config files"* claim comes straight back, green: the defect was
    **reproduced before fixing and never asserted after**, which re-mints the class one layer down —
    **a check that cannot fail is a decoration**. Closed by a feature test that CONSTRUCTS the state
    (`mkdir`/`touch`/`chmod 0000`) and pins the three facts the arm rests on before running the
    command: `is_dir()` true and the YAML scan empty are ASSERTED, and `is_readable()` false is
    GUARDED rather than assumed — a root runner reads a 0000 directory, which would silently invert
    the test into a decoration for the arm below it, so it skips with a reason instead of asserting
    against a state it is not in. The mode is restored in `tearDown` so a failure cannot leave an
    undeletable fixture behind.

  Each fix's own proof was then run the same way — mutation, FULL suite, named failing test, restore,
  `cmp` — and each RED: the collapsed seam reds
  `test_an_unexplained_not_run_check_adds_the_disclosure_on_the_warn_channel` (and the ported
  `test_a_not_run_check_with_no_reason_is_disclosed_by_id`, which now routes through the emit
  decision), the arm reds `test_check_names_an_unreadable_config_dir_as_why_the_agent_plane_did_not_run`.
  **THE RESIDUAL AS FIRST STATED WAS ITSELF A CLAIM PITCHED ABOVE ITS EVIDENCE — the same defect
  one layer down, and the third time in this stage.** It read: *"the golden corpus exercises the
  dispatch loop's `line` channel on all 33 fixtures and its `warn` channel on none."* **The first
  half is false.** `GoldenCapture` reads `Artisan::output()`, an **undecorated** `BufferedOutput`:
  the formatter strips `<warning>`/`<error>`/`<info>` to bare text, so `line()`, `warn()`, `error()`
  and `info()` are BYTE-IDENTICAL there. The corpus does not exercise the `line` channel — it is
  blind to the channel axis entirely, and 33 fixtures buy nothing on it. **Observed:** mutating
  `'line' => $this->line($message)` to `$this->warn($message)` leaves all 33 golden files unchanged
  while rendering the inventory head as a yellow warning on every healthy operator run.
  **That first reading was taken over two named files, which is a down-payment and not a proof**
  (the whole-suite requirement is argued at the end of this bullet), so the claim was re-measured
  as SIX mutations, each a FULL `vendor/bin/phpunit` against a **1807/1807 @ 4741-assertion**
  baseline, restoring `CheckCommand.php` and asserting the restore between each. (The
  re-verification pass above records 1798/4688 — a dated figure from before this pass's own
  instrument landed, not a stale one to reconcile against this line.)

  | mutation | what reds, whole suite |
  |---|---|
  | **M1** inventory head `line`→`warn` | `CheckOutputChannelTest` ×2 |
  | **M2** disclosure `warn`→`line` | `CheckOutputChannelTest` ×2 |
  | **M3 · M4 · M5** the three `Severity` render arms | `CheckOutputChannelTest` ×1 each |
  | **M6** delete the `$agentNames === []` arm | `BridgeCommandsTest` ×1 |

  `CheckGoldenTest` stays green through all six and no mutation survives unwitnessed, so the
  blindness is a property of the corpus at whole-suite scope rather than an artifact of which two
  files happened to be run.
  **A residual stated NARROWER than the truth is worse than one left unstated**, because it reads as
  a measured bound; the sentence had been copied to four sites before the read that falsified it.
  **AND THE GAP HAD A SIBLING ONE METHOD AWAY** (canon #7, audited on the shape rather than on the
  string): `emitFinding()`'s severity→channel `match` sits behind the same undecorated capture, so
  `fail`, `warn` and `ok` were equally invisible — its RETURN arm is witnessed by exit codes and its
  `unvalidated` arm by the tally line two fixtures render, but the render arm was not. Closed for
  both sites by ONE instrument rather than per-site: `tests/Unit/Console/CheckOutputChannelTest.php`
  drives each dispatch through a **decorated** `BufferedOutput`, where every channel carries its own
  ANSI attribute. It asserts the attribute is present/absent and that the channels are DISTINCT from
  each other — never the colour name — so an upstream scheme change moves a colour while a channel
  collapsing into another still reds. The genuine residual that remains is narrow and is a fact about
  the INSTALL, not about the instrument: no install shape reaches the `warn` arm of the inventory
  dispatch, because every conditional slot in `handle()` records a not-run reason by design, so
  `unexplainedNotRun()` is empty on every real run.
  **Both survivors were found by RE-RUNNING the mutation set against the full suite rather than by
  reading the test file** — the same device as the `ran()` locus gap below, now twice in one stage.
  A mutation that survives is the only thing that distinguishes an asserted contract from a restated
  one, and **the run has to be the WHOLE suite for that to mean anything**: a run scoped to the tests
  written for the mutated code answers a question nobody asked, since those are the tests that
  cannot be absent. Neither of these two is detectable that way.
- **`CheckInventory::ran()` was proven at the GOLDEN corpus, not at the unit that STATES the
  contract, and that locus gap is worth naming.** Mutating it to absorb `NotRequested` reds 34 of
  the 43 golden tests — the operator line moves `22 ran` → `24 ran` — so the predicate was never
  unproven. But `CheckInventoryTest` stayed green, because the conservation property sums all four
  dispositions and therefore **cannot see a `ran()` that absorbs one of them**: the sum is still the
  registered total. A predicate whose only proof is a corpus that a later fixture-scope change could
  shrink is proven at the wrong altitude, so the assertion now also lives beside the contract it
  belongs to. Found by re-running the stage's mutation set rather than by reading the test file —
  a mutation that survives is the only thing that distinguishes an asserted contract from a
  restated one.
- **The coverage doc regenerated to 44 predicates / 43 observed / 1 unobserved** (was 43 / 42 / 1),
  measured in 34 minutes on a detached copy proven byte-identical to the branch before the run. **The
  arriving predicate is THIS stage's own `$sshAgents === []` guard**, added when the board-tools ssh
  slots gained their not-run reason — and it arrives OBSERVED, so the disclosed-gap count holds at 1.
  **NOTHING HAD DRIFTED.** Running the enumerator against `origin/dev` returns 43, exactly what that
  revision's header said; the total moved because this stage added a predicate. **An earlier revision
  of this bullet attributed the predicate to stage 7b and blamed a prior stage for skipping a regen.
  Both were false and neither was measured** — recorded rather than quietly corrected, because this
  doc's own rule is that an arithmetic derivation is not a measurement, and a wrong-but-specific
  cause is worse than an honest generic one.
- **The single disclosed gap's generated id moved, with its condition text UNCHANGED** — the third
  method note (stage 3a, restated 5c and 6) is why that is a rename and not a departure-plus-arrival:
  **the diff keys on the condition's TEXT, never on the generated offset label.** The condition is
  `$configs !== [] && $ctx->configDir !== null`; cite it that way, never by the generated id, which
  `bin/check-doc-refs.php` bans in this file for exactly this reason.
- **`phpstan`'s green was positive-controlled on the new files specifically** (a deliberate
  `strlen(array)` in `CheckInventory`, observed red and restored byte-identical) rather than assumed
  from `app/Bridge` being in `paths` — the DL-246 trap.
- The golden diff is **35 insertions, 2 deletions across 33 files** (re-measured against `origin/dev`
  after the third pass, not carried forward), and it fully accounts: 33 new inventory lines plus the
  2 fixtures whose `unvalidated` line was rewritten — its disclaimer in the first pass and its count
  NOUN in the third (`check(s)` → `finding(s)`). The totals did not move for the second edit because
  both passes rewrote the same single line in the same two files.

**Filed, not fixed here** — output-neutral follow-ons deliberately kept out of a gated stage's PR:
the declare-your-silence strengthening above (**LANDED — DL-266, card#5596; see the follow-on
section immediately below**), and the fuller `CheckContext`-as-builder / slot-collapse restructure
the target design describes, which belongs to the final stage making it deliberately rather than to
stage 8 as a side effect. `registry()` is a private method on the command for exactly that reason.

### Follow-on to stage 8 — a silence is now DECLARED rather than inferred, and the card's own recommended shape was rejected (DL-266, card#5596)

Stage 8 made a check's silence **visible and counted**; it could not say what that silence MEANT. A
check that looked and correctly had nothing to report and a check falling off the end of its
generator through a path its author did not intend produced the identical `Silent` row. That bound
was disclosed above as open and is now closed: **a deliberate silence is a `Silence` yielded on the
path that produced it**, `CheckRunner::materialize()` strips it before any `CheckResult` is built,
and an execution yielding neither a finding nor a declaration lands in
`CheckInventory::undeclaredSilent()`. Same direction as the not-run accounting — the runner trusts a
DECLARATION and never an inference from an empty yield — for the same reason `OptInCheck` exists.

**THE CARD'S OWN RECOMMENDED OPTION WAS REJECTED, WITH THE REASONS WRITTEN ON THE CLASS.** The card
proposed a second contract method, by analogy with `OptInCheck::wasRequested()`. The analogy breaks
exactly where it matters: `wasRequested()` answers about the INVOCATION and is a constructor-time
fact, correct however many times `run()` is called, while *"was this silence deliberate"* is a fact
about the EXIT PATH of ONE execution. A method consulted after the fact would need the check to
record which path it took — **per-execution mutable state on an object `PerAgentCheck::runFor()`
re-invokes once per agent** — making correctness depend on every check resetting that state per
call. **That is the shape stage 8 rejected for the not-run accounting, in its own words.** The
card's third option, a `return;`-keyed static analyser, is structurally blind: the commonest silent
path in this registry has no `return` at all — a `foreach` over a collection that is empty — which
is the same blindness DL-251's sweep had when it keyed on the sites that REPORT.

**THE IDIOM IS A TRAILING UNCONDITIONAL YIELD, AND THAT IS WHAT MAKES THE TRIPWIRE SURVIVE.** A
declaration does not assert *"I am silent"* — the check cannot know that while it is still running —
it states what the silence WOULD mean, so the runner IGNORES it when findings were also yielded. Two
placement rules: one declaration immediately before each explicit early `return;` (the reasons
differ per path and a single trailing one would launder them into one sentence), plus one for the
fall-through. **An early `return;` added later sits BEFORE the trailing declaration, so it skips it
and the execution is recorded undeclared** — a new silent path cannot inherit an old path's
judgement. A check with no zero-finding path at all needs neither, and adding one would claim a
state it cannot reach.

**`undeclaredSilent()` IS RECORDED PER EXECUTION, AND THAT CLOSES THE PER-AGENT BOUND RATHER THAN
DISCLOSING IT.** It is the one accessor on `CheckInventory` that is not a fold over `$dispositions`.
A per-agent check that reports for agent A and is undeclared-silent for agent B ends the run
`Reported`, so the `unexplainedNotRun()` shape — filter `disposition === Silent` — would have hidden
exactly the execution that went unjudged. **Mutation-proven:** re-deriving the list that way reds
`test_a_per_agent_check_undeclared_silent_for_one_agent_is_recorded_even_though_it_reported_for_another`
and nothing else.

**THE ONE DEVIATION FROM THE CARD'S BYTE-NEUTRALITY CLAUSE, TAKEN DELIBERATELY.**
`inventory.undeclared_silent` is added to `--format=json`. `unexplained_not_run` is already there,
and **a machine consumer blind to an internal defect the text consumer can see is this program's own
recurring defect re-minted at the new surface** — the same argument stage 9 made for putting the
fail-soft envelopes' findings in the document. Per `docs/check-json-contract.md` §2 an ADDED key does
not bump `schema`, which stays `1`.

**THE SECOND `bridge:check internal:` DISCLOSURE IS REACHABLE ON A REAL INSTALL, UNLIKE THE FIRST —
and the stale docblock claiming otherwise was corrected in the same change.** `emitInventory()` said
*"NO INSTALL SHAPE CAN REACH IT"* of the `warn` channel, true of the not-run half (every conditional
slot in `handle()` records a reason by design, so `unexplainedNotRun()` is empty on every real run)
and now false of the undeclared-silence half: **whether a given silent path executes is a fact about
the OPERATOR'S install**, which is precisely why it is worth printing at runtime rather than only
asserting in CI. No fixture reaches it because every path the corpus exercises is declared — and
that is **asserted** (`CheckJsonContractTest` pins `undeclared_silent` empty), not assumed.

**WHAT A DECLARED SILENCE DOES NOT ESTABLISH**, stated because an unstated bound reads as a
guarantee: it says the author INTENDED this silence, **not that the silence is CORRECT**. The author
who wrongly returns early will equally wrongly declare that path. What the mechanism buys is a forced
review moment over the existing population, once, and an un-bypassable tripwire on every zero-finding
path added after it — not a detector for a wrong judgement. Written on `Silence`,
`CheckDisposition::Silent`, `Check::run()` and `CheckInventory::undeclaredSilent()`.

**THE FORCED REVIEW MOMENT FOUND NO WRONG PATH, AND THAT IS REPORTED RATHER THAN ROUNDED UP.** The
card predicted *"expect to find ≥1 path that should have yielded something"*. Reading all 37 check
bodies, **no path was judged wrong.** What the reading DID produce is the more useful result:

- **Verifying beat assuming, concretely.** A trailing declaration was written on
  `ChannelSnapshotCheck` claiming `ChannelSnapshotProbe::probe()` can return nothing. It CANNOT —
  every path returns ≥1 finding (an undeclared `server_path` is answered with `unvalidated`). It was
  removed and a comment now says why there is no trailing declaration. The same check was applied to
  `SshTransportProbe::probePinnedLine()`/`probeLive()` (both always ≥1) and to
  `WritebackSourceCoverage` / `WritebackByRef` / `BoardToolsBoardState` (every post-guard path
  yields). **A declaration on a path that cannot be silent is a false statement about the code**, and
  it is the failure mode a bulk sweep produces.
- **`BoardToolsHttpProbeCheck`'s defensive `continue` is DELIBERATELY LEFT UNDECLARED.** It is
  unreachable by construction, so if every enabled agent took it the run SHOULD disclose an
  undeclared silence rather than have a declaration vouch for an impossible state.
- **A scripted docblock widen over-reached onto FIVE private helpers that never yield a `Silence`**,
  caught and reverted per-method. **A bulk regex over docblocks needs a per-method control** — the
  same lesson as the `return;`-keyed analyser, one layer up.

**THE POSITIVE CONTROL, AND IT IS THE ONLY REASON THE GREEN MEANS ANYTHING.** Removing the trailing
declaration from `AgentIdentityCollisionsCheck` reds **35 of 84 `CheckGoldenTest` cases**, the diff
naming `agent.identity_collisions` verbatim; restored, `cmp` byte-identical, 84/84 green. Three
further single-arm mutations, each with one named test, restore + `cmp` between each: dropping
`materialize()`'s `continue` (the sentinel leaks into the result) reds
`test_a_check_yielding_only_a_declared_silence_produces_a_result_with_no_findings`; recording
unconditionally instead of behind `! $declaredSilence` reds
`test_a_silent_check_that_declared_its_silence_is_not_reported_as_undeclared`; and the
per-disposition re-derivation above reds its named per-agent test. Pristine negative control green.

### Stage 9 result — the row named one deliverable and the stage owed two, and the gate's real subject was never the flag

**What the row said and what the card said, and why the card was right.** The row read
*"`--format=json` renderer"*. card#5229 argued the row was incomplete, and it was — for a mechanical
reason rather than a matter of taste. **A generic JSON renderer over the existing `CheckReport` does
not close the card.** The event-consumer reconciliation — observed types vs. what any enabled
classifier declares it consumes — was computed as **local variables inside
`EventFollowsConsumerCheck` and thrown away**; the only thing that survived was the sentence. A
renderer over that emits the same prose as a JSON string, and a consumer still parses prose. So
stage 9 is **two deliverables**: the renderer split *and* the reconciliation extracted as data. The
row is corrected in place rather than quietly built around, which is the third time in this program
a stage row turned out to be a hypothesis (stages 6 and 8 were the others).

**The design fork the card left open, and the mechanism that settled it.** The card asked whether
this should be `bridge:check --json` or a standalone `bridge:consumed-events` query command, and
argued they are not equivalent. **Ruled: `--format=json`, one front door.** The deciding facts, not
the labels:

- **A standalone query command cannot carry the other half.** The resolved opt-in-probe decision
  above owes `NotRequested` **per check** — an *inventory* fact. A query command has no inventory,
  so shipping it would close the card and leave this row open, at the cost of a second write
  contract and a second guard for one known consumer.
- **The card's binding constraint — *do NOT ship a second derivation* — is honoured either way**,
  and it is precisely what makes a second front door cheap to add later if the exit-code ergonomics
  ever bite. `EventConsumerReconciler` is the one derivation; a query command would be a third
  renderer over it, not a fork of it. The DL-223 `BoardToolDispatcher` split is the precedent.
- **Stated bound, recorded rather than hidden:** a consumer piping this under `set -e` still gets a
  non-zero exit on an unhealthy install and aborts before reading the document. The document carries
  `ok`, so `$?` is never needed — and [`check-json-contract.md`](check-json-contract.md) says so, in
  the contract itself, rather than leaving a consumer to discover it in production.

**The gate's real subject was the write contract, not the flag** — which is what the row's own gate
rationale says, and building it confirmed the rationale rather than the intuition. The additive-flag
framing would have justified skipping the gate; the write-contract framing is what forces the
version constant, the exact-key-set guard, and an owning doc.

**What shipped.**

- `EventConsumerReconciler` / `EventConsumerReconciliation` / `EventConsumerScope` — the derivation
  extracted **whole** out of the check, run by `handle()` as derivation (constraint (c), the same
  treatment the `AgentRegistry` build got in stage 5c) and published on `CheckContext`.
  `EventFollowsConsumerCheck` is now a **renderer only** — no query, no set arithmetic.
- `CheckJsonRenderer`, schema **v1**: `schema · ok · checks[] · findings_outside_registry[] ·
  inventory · event_consumers{error, scopes[]}`.
- `CheckRunner::results()` — the text renderer emits incrementally and discards each report at its
  call site; a JSON document is one value emitted once, after the last check, so something has to
  retain them. The asymmetry is why the method exists at all.
- **The four fail-soft envelopes in `handle()` now emit `Finding`s and are captured.** Without this
  the document would show every check clean with `ok: false` and nothing naming the cause — a
  **machine-readable false clean**, the exact defect this program exists to remove, re-minted at the
  new surface. Two of the four set the exit code.
- **`error` is a field on the reconciliation, not an exception channel.** A fail-soft producer that
  returns only its successes hands every consumer an empty list reading as *"nothing to report"*.
  An empty `scopes` with a null error is a clean install; with a non-null error it is a measurement
  that did not happen.
- The exit contract is untouched **by construction, not by agreement**: only the RENDER arm is
  format-gated. The document's `ok` and the exit code are literally one variable, so no renderer can
  compute one of them differently.

**Evidence.**

- **Text output byte-identical: 33/33 goldens green, no regeneration.** Stage 9 is the first gated
  row whose operator-visible column is *additive*, and a needed regen would have been a signal, not
  a chore.
- **Whole suite 1861/1861, 5316 assertions** — named deliberately, because the two defects above
  are the reason a per-file list is not an evidence set. Within it: `CheckJsonContractTest` 13/13 ·
  `EventConsumerReconcilerTest` 7/7 · `EventFollowsConsumerCheckTest` 19/19 · `CheckGoldenTest`
  76/76. phpstan L7 0 · pint clean · `check-doc-refs` clean.
- **10/10 stage mutations witnessed** (one mutation, one named test, a restore and a compare
  between each) — the tenth being the `not_run_reason` gate below, added in the doc half.
  **M9 came back GREEN on the first attempt: dropping per-agent result retention left the
  whole suite passing**, i.e. the document's `agent` attribution was asserted nowhere. Closed by
  `test_a_per_agent_check_is_one_entry_whose_findings_name_their_agent`, re-run RED. That is the
  stage's own instance of the program's recurring lesson — a green suite is evidence only where
  something could have gone red.
- **Real surface, and it is what found the `not_run_reason` defect rather than merely confirming
  the build:** `php artisan bridge:check --format=json` against a live install — exit 0, one
  parseable document, 37 registered / 31 ran / 15 reported / 16 silent / 2 not-requested / 4
  not-run, three reconciled scopes. **Re-run after the fix**: zero rows carrying a reason without
  the matching disposition (there was one before), `channel.server_snapshot`'s reason now null, and
  `inventory.not_run_reasons` — the already-filtered list — unchanged, so the fix moved exactly the
  field it was aimed at.

**The code half shipped RED, and only a whole-suite run says so — the stage's sharpest finding.**
The stage-9 commit's own message claimed *"code + tests complete and green"*. It was not. Two tests
were failing in it, and **both are invisible to every per-file run the build used as evidence**:
each passes in isolation and reds only in a process where other tests run after it. Measured, not
inferred — a detached worktree at that exact commit, with its own `composer install`, reproduces
them.

1. **`CheckJsonContractTest` leaked a pinned `PATH` into every later test in the process**, which
   reds 15 tests across three unrelated suites (`PrTitleLintTest`, `GitHubTokenResolverTest`,
   `ReconcileCommandTest` — everything that shells out; the symptom is `sh: 1: bash: not found` and
   exit `127`, in a file that has nothing to do with `bridge:check`). Mechanism:
   `PinnedHost` saves the ambient environment **in its constructor**, and several tests in
   that class walk a list of install shapes, so the second `build()` constructs a `PinnedHost`
   while the first is still applied, **saves the PINNED `PATH` as if it were ambient**, and
   "restores" the process to it at teardown. `CheckGoldenTest` had already met this and handles it
   by tearing down between captures — the new class reproduced the pattern without the guard.
   **Fixed structurally rather than by discipline:** `build()` now tears down first, which makes
   nesting unrepresentable instead of asking each test to remember. That is the same reasoning
   stage 8 applied to `NotRun` — a mechanism whose correctness depends on remembering to call it
   has the shape of the bug it closes.
2. **`CheckCommandSeverityContractTest`'s skipped-advisory test had a stale premise.** It reached
   the fail-soft state by letting the check's own `WebhookEvent` query throw (no app booted ⇒ null
   connection resolver). Stage 9 moved that query into `EventConsumerReconciler`, so the throw can
   no longer happen there and the check correctly yields nothing — leaving the test asserting an
   advisory the check had no way to emit. **The behaviour is intact** (the check still renders a
   reconciliation `error` as `unvalidated`, verified at the source before the test was touched),
   and the catch arm is covered at its new address with a discriminating control. The test now
   hands the failure in as a value; **every assertion is unchanged** — only the way the state is
   reached moved with the code.

**The generalisable lesson, and it is one this program has already written down twice:** a
`--filter` or per-file run is not evidence about the suite. Both defects are *cross-test* — one is
process state leaking forward, the other is a premise the rest of the suite never exercised — and
no amount of running the stage's own tests could surface either. A stage's evidence list must
include a whole-suite run, and the claim *"tests are green"* must name which run produced it.

**Three findings from the doc half — and the first is a CODE defect that only writing the contract
down exposed, which is the argument for an owning doc rather than a docblock.**

1. **`not_run_reason` was emitted for checks that RAN.** Writing the field's row in the contract doc
   meant stating when it is non-null, and the live document answered differently from the sentence:
   `channel.server_snapshot` came back as `disposition: "reported"`, with a finding, **carrying
   *"every parsed agent aborted before this leg"***. `CheckRunner::noteNotRun()` attaches a reason to
   every check in a **slot**, before the run knows whether that slot will execute — so a per-agent
   check noted for the agents that aborted and then run for one that did not holds both. The
   document contradicted itself, and a consumer keying on a non-null reason to mean *did not run*
   would have been precisely wrong on it: **a false signal in the write contract, shipped in the
   stage whose entire subject is not shipping false signals.** Fixed at the renderer — the reason is
   gated on the disposition, which is what `CheckInventory::notRunReasons()` and
   `unexplainedNotRun()` already do; **the JSON renderer was the one reader of the raw map that did
   not filter** (audited: no other field in the document reads an unfiltered map). The existing
   assertion that a ran check carries no reason **passed under the defect**, because its subject had
   no reason recorded at all — null for the wrong cause. The new test drives the renderer directly
   with an inventory whose every id carries a reason, so the gate is the only thing that can make
   three of the four null; mutation-proven, and it reds that test alone.
2. The `JSON_PRETTY_PRINT` docblock justified the flag by *"the fixtures under
   `tests/Fixtures/check-json` are read in a PR diff"*. **There is no such directory and no
   committed document fixture** — the guard builds its documents by running the command. A true
   decision resting on a false premise invites a later reversal that sounds correct (claim 6 below
   is the same shape), so the rationale is restated on the reason that survives inspection:
   diffability of two real documents.
3. **The fail-closed unknown-`--format` diagnostic goes to stdout, not stderr** — it is written
   before any format is in effect, by the same emitter the operator report uses. Harmless for a
   consumer keying on the exit code or on a parse failure, but it means *unparseable stdout* is a
   state a consumer must handle. **Stated in the contract doc rather than fixed here:** moving it
   would change how this command reports an error, which is a gated change and not this stage's.

**What the stage deliberately did not do.** It did not touch the `warn` ↔ `unvalidated`
assignment — a check reporting `warn` because it could not run still reads as a finding in the
document. That is stage 10's, it is disclosed in the contract doc as a bound on the surface rather
than left for a consumer to trip over, and it is the reason `checks[].disposition` is a separate
axis from `findings[].severity` in the first place. **[STAGE 10 LANDED IT, and the pre-disclosure
is what let `schema` stay at 1: the contract had told consumers not to read the absence of
`unvalidated` as "measured", so delivering exactly that correction is not a meaning change. Both
bump-table rows are written down in `check-json-contract.md` §2.]**

### Stage 10 result — a warn-keyed sweep is blind to the legs that say NOTHING, and the pairing was not optional

**What changed:** 21 `Finding::warn` sites became `Finding::unvalidated`, THREE legs that emitted
**nothing at all** started emitting one, `Severity`'s "not settled" paragraph was replaced by the
rule that decides, `severityMeansSetupIncomplete()` widened to include `Unvalidated`, and card#5292's
`type` key — written at three sites, read at zero — is gone.

**THE ROW ASKED FOR A RE-ASSIGNMENT AND THE STAGE OWED ONE MORE THING.** Every way of finding the
sweep's population starts from the sites that *report* `warn` (60 across `app/` before the sweep,
counted at write time), so the search is structurally blind to
a leg that fails to answer and prints nothing. Three exist.
Two are comparison legs: `WritebackBoardStateCheck`'s stage-id compare and
`BoardToolsBoardStateCheck`'s `create_stage_id`
compare both guarded on a non-empty board-stage read and fell silent otherwise — and both legs are
*already* silent when they succeed, so silence meant "every id exists" **and** "the ids were never
compared to anything." That is green-because-never-looked at the two legs whose whole job is to catch
a silent 422, inside the stage that exists to remove exactly that. **The third is a `switch` arm:**
`ReconcileRepoTokensCheck` shared one `break` between `GitHubRepoProbeKind::Ok` and `::Network`, and
`Network` is documented as *"could not reach GitHub (timeout/connection)"* — so "this token is valid"
and "we never found out" were byte-identical output, at the leg whose entire subject is whether a
token will work. All three now emit `unvalidated`. It is
an ADDED line rather than a re-assignment, which is why it needed the row's gate and is called out in
the release notes. **Cost, measured at write time rather than asserted: 42 added lines across THREE production files — 24 for the two comparison legs (13 + 11, re-counted at write time from the diff hunks rather than inherited) and 18 for the `switch`-arm split plus the docblock paragraph that explains it** (comment +
code, including the `if`/`else` restructure and the `switch`-arm split; the severity flips in those
same files are excluded, they belong to the sweep) **plus 3 rewritten test methods — it did not
materially widen the diff, so the escape hatch the build spec left open was not taken.**

**⚠ THE METHOD DEFECT IS THE ROOT CAUSE HERE, AND IT IS NOT DISCHARGED — SO THREE IS A FLOOR, NOT A
SET.** The first pass of this stage found TWO and shipped the count as though it were the population;
the third was found afterwards, by re-reading, in a file the stage had never opened. That is not a
missed entry, it is the same failure the stage's own headline describes, one level up: **a warn-keyed
sweep is blind to a leg that concludes nothing and prints nothing, and so is every other DERIVATION
this repo has.** The §6 evidence commands all key on something a leg *emitted* — `Finding::warn`,
`Severity::Warn`, a message signature, a fixture line — and a silent leg emits none of them. **There
is no command that enumerates this category**, and rev 4 of the build spec explicitly forbade
transcribed lists precisely because reading produces floors; the silent-leg set is the one list in
this stage that had to be produced by reading anyway. The honest statement of what was measured is
therefore: **the silent-leg set was found by READING the check subsystem, not derived, so the count
is a floor.** **And the third leg's file had already been called out for this, in this document, at
stage 3a** — the § stage-3a result says in as many words that `bin/check-golden-mutate.php` does not
enumerate `switch` arms and that `reconcile.repo_tokens`'s four arms are *absent from the
disclosed-gap list entirely*. The disclosure was three stages old and correct, and the sweep walked
past it anyway, because a disclosure is prose and the sweep was a grep. **That is the finding: this
program has been converting silent gaps into DISCLOSED gaps for ten stages, and a disclosed gap with
no instrument behind it is still closed only by someone re-reading the file.** The nearest thing to a derivation is structural rather than textual — enumerate every
`switch` arm, `else`-less `if`, and early `return` inside a `Check::run()`/`runFor()` generator and
ask what each one CONCLUDED — and that is a static-analysis job nobody has built. Naming it here is
the filing; it is not claimed as done.

**A FOURTH CANDIDATE WAS FILED AGAINST THIS SET AND WAS NOT A MEMBER — the misfiling is the more
useful finding (DL-264, card#5774).** The DL-259 audit found `DirectoryPermissions::warnIfInsecure()`, a name that no longer exists (now `verdictFor()`, DL-265)
returning `null` on a failed `fileperms()`, filed it here as the silent-leg shape, and wrote the
mechanism down: a green `secret dir: …` line followed by silence, "both halves read as certified."
Every reader of that card — including the one who implemented it — found the reading obvious, because
the method returns `?Finding` and `null` is its clean answer. **The real surface disagreed.**
`fileperms()` on an unstattable path RAISES; under Laravel's error handler the warning becomes an
uncaught `ErrorException`, so the leg was not silently certifying anything — it was **aborting
`bridge:check` entirely**, exit 1 with no report rendered for any subsystem. The arm the card
specified (`$perms === false`) is unreachable: control never returns from that line. **Written as
filed, the fix would have passed review, passed CI, changed nothing, and left the abort in place.**

Three things this costs the paragraph above, and none of them is "the floor was wrong":
1. **The floor claim stands and is untouched** — this candidate leaves the silent-leg set at three,
   because it was never in it. What moved is a member of DL-262/DL-263's *raise* class (the read
   family, now found in the stat family), which is a different class with a different instrument.
2. **A `?Finding`-returning helper is the shape that makes the two classes look identical from the
   call site.** The caller reads `null` and cannot see whether the callee concluded "clean" or never
   returned at all. That is worth more than the entry: it says where to look for the next one.
3. **The disclosed-gap discipline did not catch this either.** The card WAS the disclosure, it was
   specific, and it was wrong about the mechanism — so a disclosure is only as good as the one
   measurement nobody made. The rule that would have caught it is canon #9, not a better grep: run
   the command against the state before writing the fix for it.
4. **Follow-on — the discipline was applied, and the second card held up (DL-265, card#5796).** The
   card DL-264 filed on its way out — the same primitive reporting a NON-DIRECTORY's mode as the
   secret dir's — was re-verified against the running command before any code was written, and that
   card's filed mechanism was accurate. Both outcomes cost the same one command; running it is the
   only thing that tells them apart BEFORE the fix is written rather than after.

**THE PAIRING WAS LOAD-BEARING, AND THE GOLDEN CORPUS PROVED IT.** `severityMeansSetupIncomplete()`
is read at exactly one place — the DL-225 flipped-default advisory — and its only input is
`SshPinnedLineCheck`, whose two `warn`s are both in the sweep. Left at `Unvalidated => false`, the
predicate would have gone from flagging both to flagging neither, and the advisory would have
vanished from the one install shape it was written for. **Witnessed, not reasoned:** reverting the
widening on a detached copy while keeping the sweep drops the advisory line out of
`board-tools-ssh-default-transport-advisory.txt` and reds the golden suite.

**AND THE WIDENING MAKES THE PREDICATE EXTENSIONALLY `!== Severity::Ok` — the very proxy DL-236 (f)
replaced. That is said out loud rather than left to be found.** Exactly one thing separates them, and
it is the thing the proxy was rejected for: an exhaustive `match` turns a fifth severity into a
phpstan error instead of sweeping it in. So **DL-238 (g) is NARROWED, NOT CLOSED**, and the
replacement guard is described as what it is — a tripwire that reds when `SshPinnedLineCheck`'s
emitted severity set MOVES and that cannot classify what moved into it. Post-sweep, `unvalidated` on
that path means "could not read `authorized_keys`" (setup IS incomplete) and would mean the opposite
for any future structurally-unmeasurable finding, with the same enum value; no severity assertion can
tell them apart. The root cause is that the advisory infers an install FACT from a SEVERITY —
`probePinnedLine()` returning the fact directly removes the coupling — and that is **named and not
built**, deliberately.

**THE GOLDEN CORPUS CANNOT SEE A SEVERITY, AND THE MEASUREMENT SAYS SO.** All 33 fixtures are
captured from an undecorated buffer, so `line()`, `warn()`, `error()` and `info()` write identical
bytes (DL-248). **Not one message line changed in the regeneration.** What moved is the closing tally
— 6 fixtures gain a tally line where none existed (0→1), one goes 1→2, and one changes only the
tally's own wording — **8 changed files, every one accounted for.** For those 7 the golden is a real
site-discriminating witness *through the count*; it is still not a severity witness. **A SECOND
regeneration followed the tally's re-wording** (the residual is "not counted here", not "silent"):
the same 8 files, one line each, **every delta the tally's tail and no count moved** — and the fact
that a corpus of 33 install shapes can absorb a correctness fix to an operator-facing sentence with
`8 files changed, 8 insertions(+), 8 deletions(-)` and nothing else is itself the measurement that
the wording is isolated from every other line. The `switch`-arm split reaches **no** fixture: the
`Network` arm needs a real connection failure to `api.github.com`, which a `Http::fake` corpus
cannot produce, so its only witness is `ReconcileRepoTokensCheckTest`. **The primary
evidence is therefore per-site unit assertions, and 7 of the 21 sites had NO severity assertion at
all** before this stage — the two `CANNOT VERIFY against the reconcile's issue_population` arms that
resolve no board entry or several; the terminal compare's unresolvable-config, plural-terminal and
column-not-a-stage arms; the deployed-`package.json`-unreadable arm of the snapshot version leg; and
the *skipped board-visibility probe* envelope finding. Each got one pinning `warn` FIRST, was watched
go red under the sweep, then was flipped — so every changed assertion in this stage was seen failing for the
right reason.

**THE JSON CONSEQUENCE WAS WITNESSED BY NOTHING**, which is its own finding about stage 9's guard:
`CheckJsonContractTest` asserted only that `severity` is one of four values — true before the sweep
and after it — no JSON document is committed, and no doc pinned a site. A document-level assertion
now pins one swept site on each side of the registry boundary (a registered check's finding, and one
of the command's fail-soft envelope findings, which reaches the document only through
`findings_outside_registry`).

**`schema` STAYS AT 1, on two independent grounds, both written into the contract's bump table as
checkable conditions.** (i) No tagged release has ever carried the renderer — `git ls-tree v0.71.0
app/Bridge/Check/` is empty, so there is no consumer and the change IS the contract; the condition is
worded as that command rather than as "the same release", which is a judgement about intent. (ii) §4
of the contract **pre-disclosed** this imprecision and named the landing that would fix it, so
delivering exactly the disclosed correction is the contract doing its job — that row explicitly does
not cover an undisclosed re-assignment.

**THE DISCLOSURE NARROWS; IT DOES NOT DIE.** The tally line survives a SECOND narrowing rather than
being deleted (stage 8 narrowed it once). Deleting it would have read as *"everything unmeasured is
now counted"*, which is a stronger claim than this stage earns: the rule is keyed on **what a leg
concluded**, so it can only make DISCLOSED blindness precise, and a leg that never notices it measured
nothing falls outside it. **The residual is NOT "silence", and the first wording of this line said it
was.** Such a leg may emit nothing — or emit the conclusion it would have drawn had the measurement
happened, at that conclusion's severity; `EventFollowsConsumerCheck`'s undeclared-classifier advisory
`warn`s off `CheckCommand`'s swallowed classifier throw and was the live instance at this stage
(card#5698 — **that one instance has since been closed by DL-257, which does not narrow this line:
the residual is a CLASS, and it was never enumerable**). Telling
an operator the residual is silent implies everything they DO see is precise, which is the same
overclaim one narrowing up, so the tally line now says the residual **is not counted here** and names
both shapes. The `check-json-contract.md` bound moved in step: `unvalidated` is now sound
evidence that a leg could not measure; its ABSENCE is still not evidence that everything was.

**SALIENCE LOSS, ACCEPTED AND STATED — AND ITS FIRST STATEMENT WAS SCOPED TOO NARROWLY.** Five swept
sites name an actionable coord-config defect —
the terminal compare's *declares no terminal* / *resolves N terminals* / *not a stage on that board*
arms, and both `CANNOT VERIFY against the reconcile's issue_population` arms — and go from yellow to
plain. **Scoping the disclosure to "coord-config" implied that cluster was the whole cost. It is
not.** Re-read against all 21 messages, **seven spell an explicit operator ACTION** and lose their
yellow, and they are DISJOINT from those five (the five diagnose without naming a command — their
tails say only what will go wrong): the two *"Point `bridge.writeback.coord_config_path` (or
`$COORD_CONFIG`) at coordination.config.json"* arms, `SshTransportProbe`'s two *"re-run as root"*
pinned-line legs, and three `ChannelSnapshotProbe` arms — the deployed `package.json`
absent/unreadable/malformed (one named action per cause), the BUNDLED `package.json` unreadable
(*restore or repair a tracked file, and check this process can read it*), and a path this user cannot
traverse (*re-run as the agent's user, or grant it traversal*). Twelve of 21 therefore carry a named
defect or a named action into plain text.
The argument for accepting it is the same for all of them: what those legs report is that the
MEASUREMENT could not be made,
not that anything is proven wrong — presenting an unmade measurement in the colour reserved for a
measured
anomaly is the miscalibration, not the fix for it. The line still prints, and it now enters the
closing tally, which is a coverage statement the yellow never made.

**THE TRIPWIRE PINS CALL SITES, AND ITS EXHAUSTIVENESS IS STRUCTURAL RATHER THAN GREPPED.**
`Finding`'s constructor was PUBLIC and three checks used it to re-scope another probe's finding, so
`Finding::unvalidated(` was never the only door. The constructor is now **private** and those three
sites go through a new severity-preserving `Finding::scoped()` — canon #5 applied to three
near-identical edits through one primitive. `UnvalidatedCallSiteTest` pins the exact per-file
`unvalidated` construction set AND asserts the constructor is private, because the second is what
makes the first complete. Four mutations, on a detached copy: adding a site reds the pin; removing one
reds it; **re-publicising the constructor plus a `new Finding(Severity::Unvalidated, …)` leaves the
pin GREEN and reds only the reachability guard** — which is the whole reason that second assertion
exists; and the same bypass against the private constructor is a fatal `Error`, i.e. unrepresentable.

**THE COVERAGE INSTRUMENT WAS RE-RUN AND FOUND ITS OWN FILE STALE — an incidental finding, fixed
here.** `docs/check-golden-coverage.{md,json}` was last regenerated at stage 8. **Stage 9 added two
predicates to `handle()`** (the `--format` guard and the render gate) **and did not re-run the
instrument**, so the committed file claimed *44 branch predicates* against a `handle()` that has had
46 since that merge — verified by running `bin/check-golden-predicates.php` against an unmodified
`dev` checkout, which also reports 46, so this is stage 9's omission and not stage 10's doing.
Re-measured on a detached copy: **observed 45 · observed-via-abort 0 · UNOBSERVED 1 · total 46**.
**The disclosed-gap count did not move, and it is the SAME predicate** (`$configs !== [] &&
$ctx->configDir !== null`, at a shifted line) — this stage neither closed a gap nor opened one, which
is what a stage that changes no control flow in `handle()` should show.

**⚠ THE STAGE SHIPS A VISIBLE SELF-CONTRADICTION, AND IT IS DISCLOSED RATHER THAN LEFT TO BE FOUND
(card#5698).** On a `preload.json` response carrying neither swimlanes nor stages, the new §2b leg
prints `unvalidated` — *"the board returned no workflow stages, so there was nothing to compare
against"* — while the **swimlane leg one line above it** convicts the operator's config off the same
unresolved read: *"swimlane_id X is not on board Y — created cards will 422"*. Two adjacent lines,
one board read, opposite verdicts. It is real in both board-state checks
(`BoardToolsBoardStateCheck`'s swimlane leg beside its `create_stage_id` leg, and
`WritebackBoardStateCheck`'s swimlane leg beside its mapped-stage-id leg).
**It is deliberately NOT fixed here, because the fix does not belong at these call sites.**
`KanbanClient::idList()` (and `preloadStages()` with it) collapses three distinct outcomes into one
`[]` — a genuinely empty collection, an ABSENT `data.swimlanes` / `data.workflows` key, and rows that
were present but malformed — so no caller can tell "the board has no swimlanes" from "the read did
not resolve". Guarding each call site would be N downstream patches over one upstream defect (canon
#2/#5): the client owes its callers a result that distinguishes those cases, and until it does, every
leg reading it has to guess. Filed on **card#5698** with that analysis. The §2b legs were still worth
adding — they replace silence with a disclosure, which is strictly better than the swimlane leg's
current state — but the asymmetry between the two is a defect this stage created visibility for and
did not close.

**Out of scope, deliberately** — card#5698's overclaim class (which now also carries the
`idList()` collapse above), card#5701, and the `fail`/`ok`
populations, which were **not** audited against the rule. Nothing here claims the vocabulary is
precise everywhere.

> **[card#5698 UPDATE — its FIRST sub-shape has landed; the class is still open.]** The
> **stat-conflation** sub-shape (a leg reading a bare `is_dir()`/`is_file()` false as ABSENCE) is
> closed: the guard `ChannelSnapshotProbe` had kept private since DL-230 is hoisted to
> `App\Bridge\Support\PathVisibility` and adopted at every site whose consequence actually differs
> between the two worlds. **Two of the card's listed members were re-derived away rather than
> fixed, and both corrections belong to the card, not to this doc:** `AgentApiTokenCheck` was
> never a member (its message already says *"not readable"*, which is true under EACCES **and**
> ENOENT — a claim its evidence supports), and `InstallConfigDirCheck` keeps its `fail` because
> `CheckCommand` gates the whole agent loop on the same `is_dir()`, so THIS RUN is certainly
> degraded either way — only its message was wrong. **The one member that moved an exit code** is
> the board-tools bearer: `bridge:check` runs as the operator while the resolver serving the
> runtime is built by the receiver as the web user, so a `fail` there convicted a healthy install.
> **What remains open on the class lives on card#5698, which owns it** — this doc deliberately
> keeps no copy (see the retirement note under UPDATE 3). **A green `PathVisibilityAdoptionTest`
> does not mean a NEW stat is guarded** — that pin counts adopters, and cannot see a leg that
> never adopted.

> **[card#5698 UPDATE 2 — the three-`CheckContext`-maps root cause has landed; the class is still
> open.]** `writebackEmittingScopes`, `coordCardMoveScopes` and `githubScopeConsumers` are all
> accumulated *inside* `handle()`'s per-agent loop and *after* both of its aborts, so an agent whose
> YAML did not parse — or whose classifier did not resolve — contributes to none of them, and every
> consumer read "this scope is absent" as "the operator did not enable it". Fixed as ONE fact, not
> four call-site guards: `CheckContext::$agentScopeCoverage` records which agents were not read to
> completion, **with the github scopes each is known to subscribe to** where the config parsed far
> enough to say. Three legs consult it (the orphaned-mapping accusation, the coord-card-move family
> gate in the direction that ACCUSES, and the event-consumer dropped-arrival warn) and one more
> stops silently skipping a preflight its own docblock calls MANDATORY.
>
> **Three things this stage settled that a later reader should not re-derive:**
>
> 1. **The ledger stores a SCOPE SET, not a count.** A classifier-gate abort happens with the config
>    already parsed, so its subscriptions are known and an unrelated mapping's accusation must
>    survive it. A count would have softened every finding on the install for one broken agent, and
>    the `writeback-orphan-survives-unrelated-unread-agent` fixture exists to hold that line.
> 2. **One map-fed arm deliberately gets NO disclosure** — the `family-on, terminal-missing` warn in
>    `WritebackMappingConfigCheck`. It is the only one of the four that asserts *nothing* when the
>    scope is absent (it is scoped to family-enabled scopes so a pure PR-writeback mapping stays
>    quiet), so an unread agent costs it no false claim — only the silence it already keeps for
>    every install that does not use coord cards. Disclosing there would print on EVERY mapping of
>    every run with an unreadable agent config. That is the silent-LEG class stage 10 handled, whose
>    members had answering siblings; this arm has none.
> 3. **The disclosure cannot move an exit code, by construction.** Both abort sites set `$ok = false`
>    *before* recording, so a run with an incomplete roster has already failed — the new findings
>    are `unvalidated`, which never flips the exit anyway, and there is no install shape where the
>    disclosure is the difference between 0 and non-zero.
>
> **The corpus could not witness any of this before the stage**: no fixture combined an aborted
> agent with a writeback mapping, so both new arms and the silent gate were invisible to all 33
> golden files, which is why two fixtures were added rather than an existing one extended.
>
> **One member's root cause was found MIS-FILED on the card** (the open list itself lives there,
> not here — see the retirement note under UPDATE 3). The
> **swallowed-throw** half of the partial-map pair (`EventFollowsConsumerCheck`'s
> undeclared-classifier advisory) is **NOT** closed by this stage — the card grouped it under the
> maps root cause, but its mechanism is a `catch` in the consumed-events derivation that turns
> *"the classifier was never asked"* into `declared: false`, and no coverage ledger reaches it. Two
> of the card's four "round-3" members are likewise not map-fed at all: the
> `boardCustomFieldKeys()` legs belong to the `idList()`/`KanbanClient` primitive shape.

> **[card#5698 UPDATE 3 — the swallowed-throw half is closed; the class is still open.]** The
> `catch` in `CheckCommand`'s consumed-events derivation wrote `declared: false`, which is the same
> value a classifier that does not implement `DeclaresConsumedEvents` gets — so *"it declares, and
> this run could not ask it"* and *"it does not declare"* were one state. Two false claims followed:
> `EventFollowsConsumerCheck` said the classifier **does not declare its consumed events** (false —
> it implements the interface), and the emptied `consumed` made `unconsumed()` over-inclusive, so the
> **silently dropped on arrival** warn could accuse a scope whose arrivals that classifier consumes.
> Fixed by a THIRD STATE, the same shape as DL-255's null scope set and DL-256's null id list:
> `declared` is `?bool`, `null` written only in the `catch`, routed to a new
> `EventConsumerScope::$unreadable` bucket keyed like `undeclared`, disclosed truthfully as a `warn`
> (the throw IS measured), with the unconsumed verdict downgraded to `unvalidated` — mirroring the
> `agentScopeCoverage->mayCover()` arm directly above it, which is the same treatment for the same
> reason. `--format=json` gains `event_consumers.scopes[].unreadable`; `SCHEMA_VERSION` stays **1**
> (additive).
>
> **Three things this slice settled that a later reader should not re-derive:**
>
> 1. **The DOMINANT reachable throw is `consumedEventTypes()`, not `for()`.** `for()`'s three throws
>    are pre-validated by `probeLoadable` in `AgentClassifierResolvableCheck` (same three failure
>    modes), so the arm that survives is the call INTO classifier code — which is why the witness
>    fixture had to violate the interface's pure-map contract deliberately. `ThrowingClassifier` was
>    the wrong fixture: it throws from `classify()`, a leg `bridge:check` never calls.
> 2. **Path (C) is NOT part of this and stays `false`.** `probeImplements` true, `for()` OK, and the
>    instance nonetheless not `instanceof` is a MEASURED in-process fact (the out-of-process probe
>    and the booted process disagreed), not a could-not-measure. Only the `catch` arm moved.
> 3. **The action-inventory caveat had to be EXTENDED or it would have been silently deleted.** It
>    was gated on `undeclared !== []`, and a throwing classifier used to be IN `undeclared` — so
>    moving it out drops a line that used to print, invisible to every test (the finding is `ok`, so
>    no severity assertion sees it) and to the golden corpus (no fixture reached it). The caveat now
>    carries a SECOND clause of its own wording rather than widening the first: calling a classifier
>    that does declare "undeclared" would re-mint this card's defect at the caveat. **That preserves
>    reach and adjudicates nothing** — whether an `ok` finding may carry a could-not-measure
>    disclosure at all is the card's own 41-fail / 32-ok severity audit, which must not be shipped
>    one site at a time.
>
> **The corpus could not witness this before the slice**: no fixture ran a classifier that throws
> from `consumedEventTypes()`, so one was added — and it is the discriminating PAIR for
> `event-consumer-unconsumed-type` (same scope, same arrival, same unconsumed type; only the
> readability of the declaration differs). **No existing golden file changed**, which is the
> evidence that the tri-state is inert on every install shape the corpus already covers.
>
> **[THE `Still open on the class` ENUMERATIONS ARE RETIRED HERE — DL-259.]** Each slice's UPDATE
> block used to close by restating the class's remaining members. Three copies of one list, none
> of them its owner, and the drift ran in BOTH directions: card#5698 was itself missing DL-257,
> while THIS line was **wrong when written** — it also named "the two `WritebackBoardStateCheck` /
> `idList()`-shaped round-3 members", which DL-256 had closed in the commit *immediately
> preceding* the one that added it. A reader cannot tell an honest historical snapshot from a
> current claim, which is what makes the copies the defect rather than the sync discipline
> (canon #16: delete the restatement and point at the doc that owns it). **card#5698 owns the open
> list.** UPDATE 1's and UPDATE 2's enumerations are retired with this one; what each slice
> *closed* stays, because that is this doc's own record. The `CLAUDE_DECISIONS.md` copies are left
> alone — that log is append-only, so a later DL corrects an earlier one in place of an edit.

> **[card#5701 UPDATE — the FIRST `Finding::ok` site the stage-10 bound reserved, and it was a
> live defect.]** Stage 10 disclosed a population it did not sweep: its 32 `Finding::ok`
> construction sites were never audited against the severity rule. `WritebackSourceCoverageCheck`
> is the first one read, and it was not merely untidy — on a board whose cards the writeback
> token's user cannot see, `readBoardCards()` returns no rows on a `200`, the per-card loop never
> runs, `flagged` stays `0`, and the leg certified **green**: *"dl_number cards on board N all
> have a mapped source"*. It saw nothing and reported a pass, which is the exact invariant
> `Severity::Ok` states it must never carry.
>
> **The run contradicted itself, and that is the tell** — `WritebackBoardStateCheck`'s DL-029
> probe prints *"token sees 0 cards … EITHER the board is empty … OR the token's user isn't a
> member"* in the **same output**. One leg disclosed that the population was unknown while its
> neighbour certified it clean.
>
> **The fix WITHHOLDS rather than re-measures, because nothing here can disambiguate.**
> `visibility()` reports `total === 0` for both worlds and documents that ambiguity itself;
> `byRefAvailable()` folds "board not accessible" together with "kanban predates by-ref" and
> probes only the FIRST mapped board. A second read would buy no discrimination, so the leg
> yields `unvalidated` on an empty read. **The discriminator is the EMPTY READ, never "no DL
> cards found"** — a fully-read board that simply holds no DL card was measured, and keeps its
> `ok`; keying on the DL count would print a cannot-verify line on every healthy board.
>
> **Not in tension with DL-256, though the two point opposite ways on the word "empty".** There an
> empty `data.swimlanes` was a REAL answer and only an ABSENT collection was could-not-see — the
> payload carried the distinction. Here it carries none: both worlds produce identical rows. Empty
> is an answer only when the response can still say *which* empty it is.
>
> **The golden corpus cannot witness this leg at all** — every fixture holding a writeback client
> runs `scan` correlation and the check is a no-op outside `ref`, verified by a positive-controlled
> grep (the leg's messages appear in zero fixtures while `writeback` appears in many). So the proof
> is a unit test carrying its control in the same run (two boards, one stub, one loop — one read
> empty, one not), mutation-proven: reverting the branch reds exactly that test and nothing else.
> **No golden file changed**, and no exit code moved (`unvalidated` is not `fail`).
>
> **THE OPEN LIST FOR THIS CLASS LIVES ON card#5698, NOT HERE.** The three `Still open on the
> class` enumerations above are a second copy of the card's own list, and it has now drifted in
> both directions — the card was missing DL-257 entirely, and UPDATE 3's copy was wrong the day it
> was written. Restating a tracked item's state in a doc that does not own it is the defect;
> retiring those three lines in favour of this pointer is filed on card#5698. **[Done — DL-259
> retired all three; see the note under UPDATE 3.]**

> **[card#5698 UPDATE — the reserved fail/ok severity audit, RUN. DL-259.]** Stage 10 settled the
> `warn` ↔ `unvalidated` boundary and disclosed that it had swept neither the `fail` nor the `ok`
> population against its own rule. Both were re-derived live (**45 `fail` · 33 `ok`**, up from the
> 41/32 the card recorded — DL-254…258 added sites) and audited whole, which the card required:
> the population must not be shipped one site at a time, because a rule applied per-site is how a
> class re-mints itself.
>
> **The rule needed no amendment — it needed two corollaries written down**, because both were
> being decided ad hoc in local prose, in two directions. They now live in `Severity`'s docblock,
> which owns the vocabulary: **(A)** an `ok` may disclose ambiguity about what a measured fact
> IMPLIES, but never its own blindness — so a disclosure splits by its CAUSE, not its presence;
> **(B)** a vacuous universal claim is SILENT when its emptiness is the operator's own config, and
> `ok` when only this run's measurement could establish it.
>
> **Corollary (B) closed a real divergence and changed no behaviour.** The mapped-stage-ids leg
> falls silent on an empty target set while `WritebackSourceCoverageCheck` emits `ok` on a read
> that found nothing to flag; each site argued a general principle contradicting the other's. Both
> are right, and the discriminator was simply never written: who else holds the fact. Recording it
> was the fix — changing either site to match the other would have destroyed a correct output.
>
> **Four sites disagreed with the rule.** `EventFollowsConsumerCheck`'s action inventory rendered
> GREEN while disclosing that a declaration this run could not obtain may have made it wrong — and
> it is gated on BOTH shorteners (a classifier asked that threw, an agent never read), because
> closing one would have left the same site still asserting past its evidence. Its `undeclared`
> twin is the control and KEEPS its `ok`; this is the first `ok` converted by corollary (A), and
> it discharges a bound the check's own docblock had deferred to this audit.
> `SharedIdentitiesCheck` certified `0 shared account(s)` for a file it could not read AND for one
> that is not JSON — the loader is fail-soft by design, so the count alone could never carry the
> signal, and the two faults were identical to the benign case at the one preflight meant to
> surface them. `SshTransportProbe` hard-failed *"does not resolve to an OS account on this host"*
> on any PHP build without `posix_getpwnam`, where no account database was ever consulted.
> `BoardToolsHttpProbeCheck` accused *"no bearer at <path>"* off an `is_file()` that is false for
> EACCES exactly as for ENOENT.
>
> **Two of the four kept their severity, and that is the audit's main result, not a shortfall.**
> The board-tools probe stays `fail` and the ssh no-such-account arm stays `fail`: the
> `InstallConfigDirCheck` precedent governs both — when THIS RUN certainly certified nothing, the
> exit code is earned and only the CLAIM was wrong. Downgrading them would have been canon #3.
> The audit moved a severity only where the run had genuinely measured nothing.
>
> **The golden corpus witnessed none of it**: no fixture carries an unreadable shared-identities
> file, a posix-less host, or an unreadable declaration alongside an unlisted action. All 33
> fixtures are unchanged, which is the evidence the re-assignments are inert on every install
> shape the corpus covers — and equally the reason each new arm carries a unit test that was
> watched go RED against the un-fixed code before it was trusted.
>
> **`SshProbeEnvironment::homeForUser()` returns `?string` now** — a primitive change, not four
> call-site guards: `''` (the database answered: no such account) and `null` (this process cannot
> look accounts up at all) were one value, and the consumer spent the second as the first.

## Verification

The existing net goes through the command boundary, so an internal refactor keeps it honest:
**103** `artisan('bridge:check')` invocations and **176** output assertions, concentrated in
`tests/Feature/Console/BridgeCommandsTest.php` and
`tests/Unit/Console/CheckCommandSeverityContractTest.php`.

1. **Stage 0 first, and it is this plan's own falsifier.** Build the golden harness against
   fixture installs (`Http::fake()`, no network) and capture stdout + exit code. **If
   byte-identical capture proves impossible** (non-deterministic paths, timings, ordering), that
   is discovered for the cost of one stage and the strangler approach is re-planned rather than
   half-executed. Normalize absolute paths; never normalize anything that encodes a verdict.

   **The bound, stated rather than oversold:** "no operator-visible change" in stages 0–7 holds
   *only over the install shapes the fixtures cover*. A shape absent from the fixture set can
   change silently — the same empty-result trap the golden test exists to prevent. So the fixture
   set is **derived from the branch predicates in `handle()`**, not invented: enumerate its
   `if`/`foreach` conditions and ensure each has a fixture taking the true branch and one the
   false. Any predicate without both is a **named, disclosed gap**, not a silent one.
2. **Per stage:** golden output byte-identical + full suite green (`vendor/bin/phpunit`),
   `vendor/bin/phpstan analyse -c phpstan-laravel.neon` (L7, 0 errors), `vendor/bin/pint --test`.
3. **Prove each migrated check can fail:** revert its condition once and observe the golden test
   go red. A check that cannot fail is a decoration.
4. **Real surface:** run `php artisan bridge:check` against a live install after each stage and
   diff against the pre-stage capture.
5. **Stage 8 needs a positive control:** register a deliberately non-emitting check and observe
   the runner flag it, *before* trusting the exact-inventory invariant. **Done, and it turned out
   to need TWO** — measurement showed the un-invoked SLOT is the larger population (13 of 37 on
   the baseline install) and a non-emitting check the smaller (**13 more**; an earlier revision of
   this line said 15, a PRE-taxonomy count that folded in the 2 un-requested opt-in probes the
   stage went on to break out as `NotRequested`, so nothing reconciled it with the `13 with nothing
   to report` the shipped line prints), so both shapes carry a
   control in `CheckInventoryTest`. Five single-arm mutations (one mutation, one named test, a
   restore and a `cmp` between each) confirmed each proof reds; the arms are individually
   attributable, not merely attributable as a batch.
6. **Stage 9's write contract needs a guard that reds on a SHAPE change, not on a behavior change**,
   which is a different instrument from every stage before it. `CheckJsonContractTest` asserts the
   exact key set at every level with `assertSame` against literals — a *contains* assertion greens
   on a renamed key, and a renamed key is precisely the change that breaks a consumer silently.
   The golden corpus supplies the breadth (all 33 install shapes must produce a parseable document
   whose verdict, exit and inventory counts agree with the committed **text** capture), so the two
   instruments compose: shape here, agreement there, and neither rebuilds the other's corpus.
7. **Stage 10's instrument is the per-site assertion, because the golden corpus structurally
   cannot witness it.** A severity change is invisible to the goldens: `GoldenCapture` reads an
   undecorated `BufferedOutput`, so all 33 fixtures carry zero ANSI escapes and a `warn` and an
   `unvalidated` line are byte-identical. The corpus sees the sweep only through the CLOSING
   TALLY, and only where a fixture crosses 0→1. So the evidence is a per-site unit assertion on
   each swept finding's severity, each watched RED before green — **7 sites had no severity
   witness at all and had to be given one first**, which is why the order (assert `warn`, see it
   pass, sweep, see it fail) is part of the method rather than bookkeeping. The call-site
   tripwire is a second, weaker instrument: it pins the set of `unvalidated` construction sites
   so an addition reds, and it required making `Finding`'s constructor private to be exhaustive
   at all. **It detects that the set changed and forces a human decision; it cannot say which
   severity is right, and it constrains no `warn` site.**
8. **The instrument behind items 1 and 3 can now fail out loud, which it could not before**
   (DL-267, card#5548). Every figure this plan quotes as measured comes from
   `bin/check-golden-mutate.php`, and a failed `file_put_contents` there used to be laundered
   into a verdict: no mutant reached the file, the golden run aborted for the unrelated reason,
   and the run printed `observed-via-abort N · UNOBSERVED 0` at **exit 0** — the
   strongest-looking outcome this document can carry, produced by a run that measured nothing.
   Writes are now checked at one primitive that THROWS (not exits — `exit()` skips the `finally`
   restore and would leave a mutant on disk), a whole-run result set that is uniformly
   `observed-via-abort` or uniformly `UNOBSERVED` is refused as degenerate rather than rendered,
   **a BASELINE CONTROL runs the golden suite ONCE against the unmutated tree before the loop
   and refuses the whole run unless that run is green AND emitted a decodable report**
   (card#7994 — every verdict here is read out of a JSON report `vendor/bin/phpunit` emits only
   under `laravel/pao`, which activates for a detected AI-agent shell or under `PAO_FORCE`; in
   an ordinary operator shell every red predicate scored `observed-via-abort` naming no fixture
   and every green one `UNOBSERVED` — two statuses, so the degenerate arm stayed quiet and a
   full run published pure noise at exit 0, a dependency neither the script's header nor any doc
   had ever stated), **an `observed-via-abort` verdict that names no failing fixture refuses the
   whole run, at the first such predicate** (card#7994 — and the first wording of this clause
   was WRONG about why. An abort a fail-soft envelope RENDERED does carry a non-empty failing
   list; an abort that ESCAPES `handle()` propagates out of the test, phpunit records an ERROR
   rather than a failure, and `laravel/pao` writes the `failures` key only when something FAILED
   — so that abort is real, provoked by the fixture set, and names nothing. It is refused as a
   RULING, not as an impossibility: the row it would render asserts *reached, and the guard is
   load-bearing* with nothing the fixture set produced behind either half, so the chosen failure
   mode is to refuse loudly and let the operator read the error and decide by hand), every
   result record now carries the run's `errors` count so the MIXED shape the refusal
   deliberately does not catch — some fixtures errored while others reached a golden diff — is
   auditable in the artifact rather than invisible, and a narrowed (`--only`/`--limit`) run no
   longer overwrites the artifacts with a partial population — their header claims to cover
   every predicate in `handle()`. **This changes no measurement already recorded here**; it
   changes whether a future one can be fabricated. The standing bound is unchanged and is the
   reason this item is worth stating: a figure in this document is only as good as the run that
   produced it, and until now nothing in the run could tell you it had died.

## Disproved claims — do not restate

Nine claims from earlier revisions of this analysis — and from the code it describes — were
falsified while it was being built:

1. **"The registry closes card#5310 and card#5312."** False — they live in
   `GitHubPrCardMoveClassifier` and `WritebackAlertNotifier`. Same shape, different address.
2. **"It closes card#5170 and card#5178."** False — both are already fixed (released and shipped
   respectively). They are the precedent that created the primitive.
3. **"564 test methods / 44% of the suite cover this surface."** Mismeasured — that counted every
   method in any file merely *mentioning* `bridge:check`. The measured figures are 103
   invocations / 176 assertions.
4. **"9 of ~131 emission sites use the `Finding` primitive (~7%)."** Mismeasured — that grep
   counted the method *definitions* and an internal delegation as call sites. The real figure is
   **4 of 129 (~3%)**, which makes the case stronger, not weaker.
5. **"The 4 `emitFinding` call sites ARE the two `Finding`-shaped probes"** — the equation this
   document's own stage-1 row asserted. False: only **3** render probe findings. The 4th builds a
   `Finding::unvalidated` inline inside the `event-consumer` fail-soft `catch`, and is entangled
   with a stage 2–7 unit. Found by *building* stage 1, not by re-reading — the grep that produced
   the "4" was correct about the count and wrong about what the four were, which no re-count would
   have surfaced.
6. **"The fail-soft envelope must stay inline because `GitHubRepoProbe::probe` is the realistic
   thrower."** False — that class is documented and verified *total*. The ruling is right for a
   different reason (`WritebackConfig::load()`, plus making semantic preservation a property of
   `handle()`); see the stage 3a result. Recorded because a decision resting on a false premise
   invites a later reversal that sounds correct.
7. **"`CheckRunnerTest` pins the registered set."** False — asserted by `CheckRunner`'s own docblock,
   and corrected there in stage 3a. It pins the runner's *properties* using synthetic checks.
   **Nothing pins which checks `CheckCommand` registers**, so a check that is silent on the fixture
   set could be unregistered silently. Named as stage 8's to close — **and CLOSED there**, by
   `CheckCommandRegistrationTest`, which asserts the exact id set in registration order against the
   command's own builder rather than against a list it rebuilds. Stage 8 also measured how bad the
   exposure was: 26 of the 37 registered checks yield nothing on at least one install shape, so for
   most of them "caught only if its absence changes golden output" meant not caught.
8. **"Stage 2's `is_array($lastError)` mutation is evidence that the retention marker-PRESENT
   branch is covered."** False — the six stage-2 mutations each did red the suite, and that stands;
   what the `is_array` arm evidences is narrower than a reader would take it for. **No golden
   fixture reached the marker-present branch at all.** `retention-last-pass-failed` set the cache
   marker in its builder and the harness cleared it one step later, so that fixture captured
   `minimal`'s bytes and was byte-identical to it **from the single commit that created it** —
   verified across the whole history of both files, not inferred. The inverted guard therefore red
   on the marker-ABSENT rendering. **The coverage table's `observed` verdict is NOT affected:**
   `observed` is a distinguishability claim under the instrument's own negation and was never a
   claim that a branch was reached (the same distinction the stage-4 result draws). Repaired, and
   the branch genuinely reached, in DL-247 (card#5552) — which is also why this one was invisible
   to every earlier pass: a fixture is only ever compared against itself, so a fixture that
   measures nothing looks exactly like one that works.

9. **"`emitReport()`'s `@phpstan-impure` is what keeps the `unvalidated` tally's `> 0` guard from
   being called dead."** False, and **already false before stage 9 touched the method** — which is
   the part that matters. The claim was made by that method's own docblock in stage 7a, stated as a
   measurement (*"measured, by annotating the two deeper methods instead and watching the error
   survive both"*), and believed through stage 8. Re-measured at stage 9 **on an unmodified `dev`
   checkout** — a detached worktree with its own `composer install`, never a symlinked `vendor/`,
   because composer autoloads `app/` from the target and a symlink silently makes the experiment
   answer for the wrong checkout — **removing the annotation leaves phpstan at 0 errors**, and it
   still does on this branch with the tally moved a hop deeper. So something else covers for it now
   and **the stage did not isolate what**; the honest statement is *"the annotation is not
   load-bearing and we do not know what replaced it"*, not *"it was never needed"*.
   **The annotation was KEPT**: it states a true fact about the method, and deleting it on the
   strength of an unidentified substitute is exactly how the guard comes back dead on an unrelated
   edit later. Its docblock now says all of this instead of the falsified claim. Same shape as
   claims 6 and 7 — a justification adjacent to correct code, believed because the code was right.

The general lesson, and the reason this section exists: every count in this document was
re-measured at the source rather than carried forward between revisions, and four of the first five
errors above survived one or more review passes before being caught. Claims 6, 7 and 9 extend the
pattern past this document's own prose: each was a **claim made by the code's docblocks**, believed
across every prior stage, and each was falsified by reading — or re-running — the cited authority
rather than the citation. A justification is not evidence merely because it is adjacent to correct
code, and claim 9 sharpens that: a docblock claim can cite a *measurement* and still be stale, because
the measurement was true once and nothing re-runs it. Claim 5 adds the sharper
version — a count can be right while the *category* it is attached to is wrong, and only executing
the work distinguishes those.

## Explicitly out of scope

- **The receiver / dispatch / adapter core.** Not touched. The churn data says it is fine.
- **The classifiers.** `CoordinationClassifier` (1225 L) and `GitHubPrCardMoveClassifier` (690 L)
  look alarming but are properly decomposed (25 and 19 methods, ~50 L each, one job apiece).
  Their churn is *feature accretion* — 5 policy families added — not fix-on-fix. The cards filed
  against them were policy-semantics decisions, not structural failures, and all three are
  already closed (card#5153 released, card#5156 released, card#5198 declined) — the subsystem
  currently carries no open work. Extracting the 5 `*Family()` methods to family classes would
  help a 6th, but closes no card; **deferred deliberately**.
- **The correlation-token grammars.** `CardTokenGrammar` / `DlTokenGrammar` are well-built — the
  operator-facing accept-set is *derived by running the pattern* (`describe()`), which is the
  right answer. One real gap remains and is **separate from this program**:
  `.github/workflows/pr-title-lint.yml`'s **gate leg** hand-rolls both token grammars: the card
  arm has no 2-digit floor (⇒ glued `card4` passes CI but never correlates — a silent green) and
  the DL arm is four-digit-bounded where `DlTokenGrammar` is unbounded (⇒ `DL-12345` false-reds).
  **Both arms have since been given the answer-set comparison guard the *warn* leg had (DL-240),
  so the divergences are re-measured every run rather than remembered — what remains open is
  repairing them, not observing them.** That workflow has **no checkout and no PHP setup**, so it
  cannot call the PHP authority without adding both; extending the answer-set guard was the
  correct fix under that constraint, not a restructure. Tracked as **card#5300** (hard gate); one
  row — the locale-dependent Unicode-digit false green — was approved and fixed under DL-272, the
  rest are still pinned.
- **Adding any new `bridge:check` leg** until Stage 8 lands — each one added first is another
  site to migrate and another chance to re-mint the same card. **LIFTED: Stage 8 has landed.** A
  new leg is now added as a registered `Check`, and `CheckCommandRegistrationTest`'s pinned id list
  must be updated in the same commit — which is the point, because that list is what makes the new
  leg's presence a measured fact rather than an assumption.
  **Worked example — `board_tools.client_half` (card#7756 / DL-313), the first leg added under the
  lifted rule.** What the process actually cost, recorded so the next author can price it: one new
  `PerAgentCheck`, one new `CheckSlot` (ordered between the board-STATE and ssh planes, and — like
  the ssh plane since DL-275 — deliberately OUTSIDE the board-tools kanban-client envelope, because
  it reads this bridge's own database and nothing that client produces), one `runForAgent` loop and
  one `noteNotRun` arm in `handle()`, the pinned id list, **and every golden fixture** — the
  registered total moves from 37 to 38, so all 37 committed captures change their inventory line
  whether or not the new leg speaks on that shape. ⚑ **Two things a green suite did NOT catch and a
  reader should expect:** `UnvalidatedCallSiteTest`'s source-scanning pin reds (correctly — it is
  the tripwire, and the new entry has to be argued, not pasted), and a **`checks: 37 registered`
  literal inside a NEGATIVE assertion** in `CheckJsonContractTest` went silently vacuous rather than
  red, which is why it now matches the line's SHAPE. ⚑ **Two new predicates in `handle()`** (the new
  loop and its `emitReport` arm), which `docs/check-golden-coverage.md` did not cover at the time.
  ⛔ **That file was ALREADY stale before this leg, and by more than this leg adds** — re-deriving
  with `php bin/check-golden-predicates.php --json` against the pre-change command returned **54**
  predicates where the generated header claimed **49**, so its `46 observed / 3 UNOBSERVED` split
  described a `handle()` five predicates ago. It is generated by a ~40-minute mutation run that
  must execute on a COPY of the repo (it rewrites `CheckCommand.php` in place), so regenerating it
  was its own piece of work — the point recorded here is that a reviewer must not read that file's
  totals as current, and that re-deriving the DENOMINATOR is cheap even when re-deriving the
  verdicts is not. ⭐ **Since the card#7756 review that gap is GUARDED rather than merely known:**
  `tests/Feature/Console/Check/CheckGoldenCoverageCurrencyTest.php` compares the header's number
  against `bin/check-golden-predicates.php` — the same enumerator the generator drives — and reds
  in EITHER direction, so a STALE banner cannot outlive the staleness it announces and the number
  cannot drift again in silence.
  ⭐ **THE SUBJECT NOW HAS A SECOND TERM, AND THE VERDICTS ARE DECLINED ON THE RECORD
  (card#7992).** The count guard is a DENOMINATOR: it cannot see a predicate set that changed
  IDENTITY while keeping its SIZE. That is not hypothetical — between two regenerations of this
  artifact one predicate DEPARTED while two arrived, so until the next run the published gap table
  named a condition (`$configs !== [] && $ctx->configDir !== null`) that existed nowhere in the
  source; the count moved by one that time and would have caught it by luck, and one departure
  against one arrival is the same defect with the count standing still.
  `CheckGoldenCoverageSubjectTest` closes it by comparing the artifact's measured `(kind, source)`
  MULTISET against the same enumerator — a SEPARATE term with its own banner, not a widened one,
  because the two must be independently satisfiable: the case it exists for is exactly the one
  where the count agrees and the currency guard therefore requires ITS banner ABSENT.
  ⛔ **A cheap guard on the VERDICTS was considered and DECLINED.** The candidate was a fingerprint
  over the measurement's inputs, stored beside the artifact. The input closure of a verdict is the
  whole `bridge:check` plane plus its config defaults and the golden corpus, so a fingerprint over
  any nameable SUBSET carries false negatives it cannot state — the same stated-scope-exceeds-
  predicate defect one level down — while a fingerprint over the whole closure reds on essentially
  every check-plane change and, with a ~57-minute run as the only remedy, would leave the banner
  permanently up: furniture, which is the failure the two-directional design above exists to avoid.
  ⚑ **The corpus IS a real input, and the measured evidence bounds how it bites.** Between the two
  runs recorded below, **30 of the 56** predicates changed the SET of fixtures their mutant
  reddened, and in every one of those 30 the delta was exactly the three captures that window
  ADDED, with **nothing ever leaving** a failing set. No verdict moved as a consequence — a fixture
  is an independent data-provider case, so adding one can only ADD red. (The single verdict that
  DID move between those runs is not a corpus effect at all: run 1 recorded that predicate with an
  EMPTY failing list under the aborted arm, the signature of a golden run whose report could not be
  parsed rather than of a mutation semantics. That is card#7994's subject, not this one's.) Corpus
  GROWTH can therefore turn `UNOBSERVED` into `observed` but never the reverse, which makes the
  published gap list CONSERVATIVE under it — over-listing a gap costs a reader extra reading, never
  false reassurance. Capture MODIFICATION and DELETION carry no such argument and are the
  directions to distrust. What ships in place of the declined instrument is the disclosure now in
  the artifact's own header.
  **[REGENERATED — card#7835. The deferred run has been done, on a throwaway worktree, and the
  banner is gone because the generator rewrites the whole file.** `handle()` now holds **56**
  predicates and the measured split is **49 observed · 0 observed-via-abort · 7 UNOBSERVED**, against
  the previous **46 · 0 · 3 over 49**. Compared as a MULTISET on `(kind, source)` and never as a set
  — the stage 3b–7 method note above owns the reason — **7 predicates were added and 0 departed**,
  with `49 − 0 + 7 == 56` as that comparison's own positive control. ⚑ **The gap count's 3 → 7 rise
  is BOUNDED, not attributed.** Six of the seven gaps are `foreach $cfg->subscriptions` /
  `$sub->provider === 'github'` pairs whose `(kind, source)` is identical across every instance, so
  the diff cannot say which instance is which. What it does establish about that family: the
  `observed` count is unchanged at 3 of each, and the `UNOBSERVED` count rose by exactly the 4
  predicates added there. **Reading that as "the four new gaps ARE the four new predicates" is an
  inference this instrument cannot make** — the same multiset bound the stage 3b–7 note records,
  reached from the other side. The seventh gap, `if $configs !== []`, is a unique source and carries
  over UNOBSERVED unchanged. **Both of this leg's own predicates scored `observed`**: the
  `CheckSlot::BoardToolsClientHalf` `emitReport` arm is a unique source, and its
  `foreach $ctx->boardToolsEnabled` loop is covered by both instances of that source being
  `observed`. ⭐ **THE SEVENTH ADDED PREDICATE IS THE DL-290 RELANE-SCOPE ACCUMULATION** — named here
  because the walk above otherwise reaches 7 only by arithmetic. Its condition text — the key the
  coverage diff compares on — is
  `in_array('coord-card-relane', $cfg->classifierConfig->strings('families'), true)`; it is absent
  from the previous artifact's 49, and **`observed`** in this one, reddening five captures:
  `writeback-move-leg-coord-config-unset`, `writeback-move-leg-coord-config-unreadable`,
  `writeback-move-leg-coord-config-agrees`, `writeback-board-unreadable` and
  `writeback-swimlane-collection-absent`. ⛔ **DL-290 predicted an
  UNOBSERVED row**, on the strength of flipping the predicate to `if (false)` and watching the golden
  suite stay green. That is a different mutant from the one this instrument applies, and it turns on
  the distinction disproved-claim 8 above already draws — `observed` is a distinguishability claim
  under **the instrument's own negation**, never a claim about what the fixtures reach. `if (false)`
  DISABLES the accumulation, a no-op on a corpus whose agents enable no relane family, while the
  NEGATION ENABLES it for every agent WITHOUT the family — which is exactly why five captures move.
  The correction is recorded on DL-290 itself.
  ⚑ **THE RUN WAS REPEATED ON THE MERGED TREE, AND ONE VERDICT MOVED.** The counts recorded above
  are the SECOND run's. The predicate SITES are identical across the two runs (ids, kinds, lines,
  byte offsets, source), and `CheckCommand.php` is **byte-identical** between the two trees measured.
  **The UNOBSERVED ids are the same in both runs** — the disclosed-gap list this document exists to
  publish did not move. **Exactly one verdict differs**: the `CheckSlot::ProbeTools` `emitReport`
  arm, `observed-via-abort` in run 1 and `observed` in run 2. ⛔ **Run 1's record for that predicate
  cannot have come from the mutation's semantics, and no cause is named here because the instrument
  retained nothing that could name one** — the argument, its evidence and the resulting instrument
  gap are recorded on **card#7994**. ⚑ **The instrument now REFUSES that shape** rather than
  publishing it (see the refusal arms in the item above): a run in which any predicate scores
  `observed-via-abort` while naming no failing fixture writes no artifact at all, and a BASELINE
  CONTROL refuses the run outright when the unmutated tree does not answer green with a decodable
  report. ⛔ **Neither guard explains run 1, and the tempting explanation is measurably WRONG:**
  run 1 scored **48 `observed`** rows carrying real fixture lists, so the report emitter was
  working for the rest of that run and a whole-run environment failure cannot be the cause. What
  the baseline control closes is a DIFFERENT and larger shape — the run where nothing decodes from
  the start — while run 1's single iteration is the residue the in-loop refusal now refuses rather
  than renders. That closes the publication path, not the diagnosis: what killed run 1's iteration
  remains unrecoverable, and the general "was this iteration real" term stays open on the card.]
