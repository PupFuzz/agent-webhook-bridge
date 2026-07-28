# `bridge:check` — the Check-registry plan

> **Status: stages 0–1 are BUILT (card#5464, card#5468). Stages 2–7 are unstarted; stages 8–10
> are hard-gate.**
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
- `handle()` is **891 lines** (L90–L981), nesting to 11 levels.
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

The command already says this about itself. `CheckCommand.php:961` prints a 5-line apology:

> *"This is a floor, not an inventory: only checks reporting `unvalidated` are counted, and a
> check that could not run usually reports `warn` instead — so no tally line does NOT mean every
> leg ran."*

That paragraph is the command apologizing for not having a registry. A registry makes the
inventory **exact** and deletes the apology.

This is the canon-#5 shape with the diagnosis inverted from the usual case: the primitive was
fixed, the N call-sites were not migrated. Fixing call-sites one at a time leaves the N+1th to
re-mint the bug.

### The structural finding underneath it

`handle()` conflates two different jobs:

- **Deriving a model of the install** — `$configs` accumulates at L251, `$githubScopeConsumers`
  at L376 (inside the config loop), `$writeback` at L572, `$client` at L746.
- **Asserting on that model** — consumed as late as L915 (`checkEventFollowsConsumer`).

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
`Log::warning` branches are in `app/Bridge/Writeback/WritebackAlertNotifier.php:131,168,190` and
`KanbanPromoteReleasedHandler`. **card#5310**'s near-miss probe is
`GitHubPrCardMoveClassifier::warnCardTokenNearMiss()` (L597, called at L210/L476). Both are the
silent-degrade shape and both deserve a shared "this degraded silently" reporting primitive, but
that is a **separate consolidation** over a different call-site set. This program gives the shape
a vocabulary to be reported in; it does not detect it.

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
    /** @return iterable<Finding>  MUST yield >= 1 finding; unvalidated when it cannot run. */
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

**(a) Registration is unconditional. "Not applicable" is a returned `Finding`, never an absent
registration.** If checks registered conditionally (e.g. board-tools checks only when some config
declares the block), a check that never registered is invisible to the inventory — which
re-mints *"green because never looked"* one level up, at the registry. Every check registers
always; applicability is a verdict it returns.

**(b) The registry needs a per-agent scope, not just a global one.** Output is emitted *inside*
the per-agent config loop (`agent config ok: {$name}`, `agent {$name}: …`, from L240),
interleaved per agent. A check hoisted to run after derivation would **reorder** output and break
the byte-identical contract. Hence `PerAgentCheck`, executed within the iteration at the same
position.

**(c) During stages 1–7 the surviving inline derivation code in `handle()` populates
`CheckContext`.** Migrated checks read it; unmigrated code keeps its local variables.
`CheckContext` becomes a standalone builder only in the final stage. Without this rule the stage
boundaries are ill-defined — `$githubScopeConsumers` is *built* inside the per-agent loop (L376)
and *consumed* by a check at L915, so producer and consumer are necessarily migrated in different
stages and must communicate through the context in the interim.

### Why a strangler migration, not a rewrite

Each stage moves **one check unit** out of `handle()` into a `Check`/`PerAgentCheck`, **registered
at the same ordinal position**. Output order is preserved, so the golden-output test (Stage 0)
stays green throughout. Every stage is independently reviewable and revertible.

## Resolved design decision — the opt-in probes (settled; do not re-open)

**Question as originally posed:** do the opt-in probes report `unvalidated` when their flag is
absent? Today `--probe-tools` / `--probe-tools-ssh` print **nothing** when not passed (guarded at
L932, L940), but under constraint (a) they must return *something*.

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
- The **text renderer stays silent** on not-requested: today's default output is preserved
  byte-identical and the common path gains no noise.
- The **JSON renderer** (Stage 9 / card#5229) **emits it**, so machine consumers get the full
  inventory *including what was never asked for* — strictly more than they can get today.

Net effect: `unvalidated` keeps exactly one meaning — *"I should have measured this and the
install stopped me."* **No new `Severity` case**, so the exhaustive-`match` property and the exit
contract (only `fail` flips the exit) are both untouched by construction.

**Scope bound:** this settles the **opt-in-probe axis only**. The re-assignment sweep card#5291
owns — the ~dozen couldn't-probe sites that currently report `warn` — remains open and remains a
**hard gate**. The reasoning above is also recorded on card#5291 itself, which stays the SoT for
that sweep.

## Staged execution

Every stage ends with a fresh-adversarial review pass and must reach **zero must-fix** before the
next stage starts.

| Stage | Work | Operator-visible change | Gate |
|---|---|---|---|
| **0 ✅** | Golden-output harness + `Check`/`PerAgentCheck`/`CheckContext`/`CheckRunner` scaffolding. Registry empty; nothing migrated. | **None** | no |
| **1 ✅** | Migrate the two already-`Finding`-shaped probes — **3** of the 4 existing `emitFinding` call sites (the 4th is not a probe; see below). First wiring of `CheckRunner` into `handle()`. | **None** | no |
| **2–7** | Migrate remaining units, ~1 PR per cluster: `retention`, `writeback` (+`writeback.json`, `alert_channel`), `database`/`install-suffix`, per-agent config/identity, `inbox surfacing config`, `board_tools`. | **None** (golden test enforces) | no |
| **8** | Turn on the invariant: every registered check emits ≥1 finding; replace the L961 "floor, not an inventory" disclaimer with an **exact** inventory. Applies the resolved opt-in-probe decision above. | **Yes** — disclaimer text changes; opt-in probes may gain lines | **GATE** |
| **9** | `--format=json` renderer. Closes **card#5229**. | Additive surface | **GATE** |
| **10** | Re-assign the sites that disagree on warn ↔ unvalidated. Closes **card#5291**, **card#5292**. | **Yes** — severities change | **GATE** |

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

Four ambient inputs are pinned (`PATH`, which decides the `php-fpm` probe at L160 and therefore
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
the 102 `if`/`elseif`/`foreach` predicates in `handle()` (re-measured at the source by
`bin/check-golden-predicates.php`; the figure agrees with the independent count on card#5464).
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
iterations** (the main config loop, and the ssh-agent loop inside `checkBoardTools()`), and its
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

**What stage 1 did NOT migrate, and why.** The 4th `emitFinding` site is the `Finding::unvalidated`
inside `checkEventFollowsConsumer()`'s fail-soft `catch`. It cannot move alone: the `catch` wraps
that method's whole body, so migrating it necessarily migrates the ~10 raw emissions above it and
the `$githubScopeConsumers` read at its heart. That is a **stage 2–7 cluster**, and merging it into
stage 1 would have merged two stages. It stays inline, now calling the one-argument `emitFinding()`.

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
   the runner flag it, *before* trusting the exact-inventory invariant.

## Disproved claims — do not restate

Five claims from earlier revisions of this analysis were falsified while it was being built:

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

The general lesson, and the reason this section exists: every count in this document was
re-measured at the source rather than carried forward between revisions, and four of the five
errors above survived one or more review passes before being caught. Claim 5 adds the sharper
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
  `.github/workflows/pr-title-lint.yml`'s **gate leg** hand-rolls `card[-#]?${card_id}` (no
  2-digit floor ⇒ glued `card4` passes CI but never correlates — a silent green) and
  `dl-[0-9]{1,4}` (bounded where `DlTokenGrammar` is `\bDL-(\d+)`, unbounded ⇒ `DL-12345`
  false-reds). The *warn* leg already has an answer-set comparison guard; the gate leg does not.
  That workflow has **no checkout and no PHP setup**, so it cannot call the PHP authority without
  adding both — extending the existing answer-set guard is the correct fix under that constraint,
  not a restructure. Tracked as **card#5300** (hard gate).
- **Adding any new `bridge:check` leg** until Stage 8 lands — each one added first is another
  site to migrate and another chance to re-mint the same card.
