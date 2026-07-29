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

The command already says this about itself — the closing `unvalidated` tally prints a 5-line apology:

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

- **Deriving a model of the install** — `$configs` accumulates in the per-agent config loop,
  `$githubScopeConsumers` in that loop's subscription walk, `$writeback` and `$client` later.
- **Asserting on that model** — consumed as late as `checkEventFollowsConsumer()`, near the end.

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
`GitHubPrCardMoveClassifier::warnCardTokenNearMiss()`. Both are the
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
the per-agent config loop (`agent config ok: {$name}`, `agent {$name}: …`),
interleaved per agent. A check hoisted to run after derivation would **reorder** output and break
the byte-identical contract. Hence `PerAgentCheck`, executed within the iteration at the same
position.

**(c) During stages 1–7 the surviving inline derivation code in `handle()` populates
`CheckContext`.** Migrated checks read it; unmigrated code keeps its local variables.
`CheckContext` becomes a standalone builder only in the final stage. Without this rule the stage
boundaries are ill-defined — `$githubScopeConsumers` is *built* inside the per-agent loop
and *consumed* by `checkEventFollowsConsumer()`, so producer and consumer are migrated in different
stages and must communicate through the context in the interim.

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
| **2 ✅** | Migrate the `retention` cluster into `RetentionPostureCheck` at a new `CheckSlot::Retention`. `receiverSapiFinishesEarly()` moves with it (single caller). | **None** | no |
| **3a ✅** | Migrate the writeback **config plane** — the six units needing no board read — into `CheckSlot::Writeback`. The cluster splits because its probe half needs a constructed `KanbanClient`; see the stage 3a result. | **None** | no |
| **3b ✅** | Migrate the writeback **probe plane** (the legs that read the board) into `CheckSlot::WritebackProbe`. Inherited the throw constraint recorded in the stage 3a result; see the stage 3b result. | **None** | no |
| **4 ✅** | Migrate the `database`/`install-suffix` cluster into `CheckSlot::Database`. Two checks, and the first cluster that is pure assertion — no `CheckContext` field added or read; see the stage 4 result. | **None** | no |
| **5a ✅** | Migrate the per-agent **classifier plane** — the units asserting on the parsed config alone: the classifier-resolution gate (into `CheckSlot::AgentClassifier`, the one **abort** slot) plus the two lazy-`classifier.config` advisories (into `CheckSlot::AgentPolicy`). Stage 5 splits; see the stage 5a result for the measured reason. | **None** | no |
| **5b** | Migrate the per-agent **secret/token + channel-transport** legs: secret + API-token presence, `channel.auth.token_path`, and the socket/HTTP marker + liveness legs. **Five of the eight disclosed gaps are here**, so unit tests carry the proof — a green golden run says nothing about these. | **None** (golden test enforces) | no |
| **5c** | Migrate the **post-loop registry/identity** legs: `AgentRegistry` collisions, `treat_as_signal`, `BRIDGE_DEFAULT_AGENT`, `shared-identities.json`. **The remaining three disclosed gaps are here.** | **None** (golden test enforces) | no |
| **6–7** | Migrate remaining units, ~1 PR per cluster: `inbox surfacing config`, `board_tools`. | **None** (golden test enforces) | no |
| **8** | Turn on the invariant: every registered check emits ≥1 finding; replace the `emitReport()` "floor, not an inventory" disclaimer with an **exact** inventory. Applies the resolved opt-in-probe decision above. | **Yes** — disclaimer text changes; opt-in probes may gain lines | **GATE** |
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
`CheckSlot::Retention`, at the ordinal position the inline code held. `receiverSapiFinishesEarly()`
moved with it — that private method had exactly one caller. `CheckCommand::handle()` lost ~66 lines.

**No stash-and-recapture was needed, and that is a property of this cluster, not a shortcut.** Stage
1 had to capture fixtures from pre-refactor code because none existed. Here the four retention
fixtures and the `minimal`/`minimal-fpm-present` potency pair were **already captured before this
refactor existed**, so a plain green run *is* the byte-identity measurement: **37/37, 79 assertions,
green with `UPDATE_GOLDEN` unset.** The assertion count is the load-bearing half — a regenerating run
drops to 46, so **79 means asserted; 46 would mean nothing was checked.** Six deliberate mutations
each red the suite: registration removed, `enabled` inverted, `isUsable()` inverted,
`receiverSapiFinishesEarly()` inverted, `is_array($lastError)` inverted, and one space dropped from
the `retention: on (` message.

**One leg is covered by neither instrument, and it needed a unit test.** The fail-soft `catch` around
the last-failure marker read is unreachable from a golden fixture (it needs an unreachable cache
backend) *and* invisible to `bin/check-golden-mutate.php`, which walks `if`/`elseif`/`foreach` in
`handle()` and **does not enumerate `catch` blocks at all**. Left alone it would have been justified
by reading. `RetentionPostureCheckTest` covers it with a throwing cache store plus a discriminating
control (reachable backend, no marker ⇒ the line is absent); both directions are mutation-proven.
**Generalize this before stage 3:** every remaining cluster carries fail-soft `catch` arms with the
same double blind spot.

**Coverage-table effect.** The predicate total goes **100 → 97**, and the arithmetic is *not* a plain
subtraction: the four retention predicates (`enabled`, `isUsable()`, `receiverSapiFinishesEarly()`,
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

**What the golden fixtures do and do not reach, enumerated rather than assumed.** The seven writeback
fixtures reach the mapping-count line, the no-identity and missing-token warns, the `Unresolvable`
reconcile arm, orphan, the DL-160 pair, the DL-195 revive, the DL-198 echo, DL-204 in both directions,
and the outer load `catch`. **Four legs no fixture reaches at all:** the whole `alert_channel` check,
`issuePopulationAgreement()`, the #4553 `population=all` warn itself, and the reconcile probe's `Http`
arm. Three more are disclosed as UNOBSERVED by condition text (the `correlation !== 'ref'` leg, the
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

Seven claims from earlier revisions of this analysis — and from the code it describes — were
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
   set could be unregistered silently. Named as stage 8's to close.

The general lesson, and the reason this section exists: every count in this document was
re-measured at the source rather than carried forward between revisions, and four of the first five
errors above survived one or more review passes before being caught. Claims 6 and 7 extend the
pattern past this document's own prose: both were **claims made by the code's docblocks**, believed
across every prior stage, and each was falsified by reading the cited authority rather than the
citation. A justification is not evidence merely because it is adjacent to correct code. Claim 5 adds the sharper
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
