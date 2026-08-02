# `bridge:check --format=json` — the machine-readable contract

`php artisan bridge:check --format=json` renders the same run the operator report renders, as **one JSON document on stdout**. It exists so a consumer can answer *"is this install healthy, and what exactly was and was not measured"* **without parsing operator prose** — the coupling that breaks the moment a sentence is reworded, and breaks toward a **false clean** (a parser that matches nothing reports nothing).

> **This document is a write contract.** Once a machine consumer parses it, it cannot be un-shipped. That is why the flag was gated (DL-249, `docs/CHECK-REGISTRY-PLAN.md` stage 9), why the document carries a **`schema`** version, and why `tests/Feature/Console/Check/CheckJsonContractTest.php` asserts the exact key set at every level rather than a *contains* subset. **This file is the contract's owning doc** — the shape is written down here and nowhere else, so there is no second copy to drift.

**Read this before writing a consumer, and before changing the renderer.**

---

## 1. Invocation and the exit contract

```bash
php artisan bridge:check --format=json      # the document on stdout
php artisan bridge:check --format=text      # the operator report (the default)
php artisan bridge:check                    # identical to --format=text
```

| Aspect | Contract |
| --- | --- |
| **What differs between the formats** | **Only which bytes reach stdout.** The same checks run, in the same order, producing the same findings and the same verdict. `--format` gates the *render* arm alone; the tally and the return value run identically either way. |
| **Exit code** | `0` healthy, `1` unhealthy — **unchanged from the text run**, and `document.ok === (exit === 0)` by construction: the document's verdict and the exit code are literally the same variable. Asserted per install shape in `CheckGoldenTest` (the JSON run's exit is compared against the committed text capture's), so a renderer that reached the verdict reds. |
| **An unknown `--format`** | **Fails closed**: exit `1`, a one-line diagnostic, **no report of either kind**. It does not fall back to text — a typo'd flag silently emitting unparseable prose with a zero exit is the same false-clean shape one layer up. **⚠ That diagnostic goes to *stdout*, not stderr** (it is written before any format is in effect, by the same emitter the operator report uses), so **a consumer must treat unparseable stdout as a failure** rather than assuming stdout is either a document or empty. Stated rather than fixed: moving it to stderr changes how this command reports an error, which is a gated change. |
| **stdout purity** | On a run that *accepted* `--format=json`, the document is the **only** thing written to stdout. Every other emitter is format-gated — including the four fail-soft envelopes that print findings from outside the registry — so `json_decode` over the whole stream is safe. Asserted on the shape most likely to break it (a malformed agent YAML, whose diagnosis comes from outside the registry) and, across all 33 install shapes, by requiring the stream to parse. |
| **⚠ `set -e` and `$?`** | A consumer piping this under `set -e` still gets a **non-zero exit on an unhealthy install** and will abort before reading the document. **The document carries `ok`, so `$?` is never needed** — read the verdict out of the document (`jq .ok`) and neutralize the exit (`bridge:check --format=json \|\| true`) if a non-zero status would kill your pipeline. This is a stated bound of one exit code serving two audiences, not an oversight. |

## 2. Versioning — what a consumer may rely on

| Change | `schema` bump? | What a consumer should do |
| --- | --- | --- |
| A key is **added** | **No** | **Ignore unknown keys.** This is required of consumers, and it is why additive growth is deliberately not versioned — a number that churns on every addition stops meaning anything. |
| A key is **removed or renamed** | **Yes** | Key on `schema`; refuse or degrade explicitly on a version you do not know. |
| A key's **type or meaning** changes | **Yes** | As above. |
| A `message` string is reworded | **No — and it is not a contract change at all** | See below. |
| The contract has **never been carried by a tagged release** | **No — the change IS the contract** | Nothing to key on: there is no consumer, because there was no version to write one against. The condition is checkable, not a matter of opinion — `git ls-tree <latest tag> app/Bridge/Check/CheckJsonRenderer.php` returns nothing, so no tag ships the renderer. Stated as that command rather than as "it's the same release", which is a judgement about intent. Once one tag carries it, this row is spent forever. |
| A **pre-disclosed precision correction** to *which findings carry which severity* | **No** | Not a shape change and not a meaning change: §4's `severity` row **pre-disclosed** that the vocabulary was imprecise and named the landing that would fix it, so a consumer written against `schema: 1` was told in the contract not to read the absence of `unvalidated` as "measured". Delivering exactly the disclosed correction is the contract doing its job. This row does **not** cover an undisclosed re-assignment, which changes a key's meaning and bumps. |

**`schema` is currently `1`.** It stayed `1` through DL-251 (stage 10), which re-assigned 21 findings from `warn` to `unvalidated` and added three: both rows above apply independently, and either alone is sufficient. It stayed `1` again through DL-255 (card#5698), which ADDED the top-level `agent_scope_coverage` key (§3a) and re-assigned three more findings to `unvalidated` — the added-key row and the pre-disclosed-precision row, again independently sufficient. It stays `1` through DL-259 (card#5698), the fail/ok severity audit, on the pre-disclosed-precision row alone: three findings move to `unvalidated` and one `ok` splits into `ok`/`warn`, adding no key and inventing no severity. Note for consumers keying on `severity`: like DL-258 before it, this moves findings OUT of `ok`, so a consumer treating `ok` as "everything this leg covers is fine" gets *fewer* false all-clears and no new shape.

**`message` strings are NOT part of the contract.** They are operator prose, carried so a document is diagnosable by a human reading it. They have been reworded before (DL-236) and will be again. **A consumer keying on message text has re-created the coupling this surface exists to break** — key on `severity`, `disposition`, check `id`, and the structured `event_consumers` fields instead. `CheckJsonContractTest` deliberately does not pin them.

## 3. Top-level shape

```jsonc
{
  "schema": 1,
  "ok": true,
  "agent_scope_coverage": { … },        // did the run finish reading every agent? (see §3a)
  "checks": [ … ],                      // every REGISTERED check, in registration order
  "findings_outside_registry": [ … ],   // findings belonging to no check (see §5)
  "inventory": { … },                   // the run's account (see §6)
  "event_consumers": { … }              // the observed-vs-consumed reconciliation (see §7)
}
```

| Key | Type | Meaning |
| --- | --- | --- |
| `schema` | int | The document's shape version (§2). |
| `ok` | bool | The run's verdict — **the same variable as the exit code**. `false` iff at least one finding carried `severity: "fail"`. |
| `agent_scope_coverage` | object | Whether this run read every agent config, and which it did not — §3a. |
| `checks` | array | One entry per **registered** check, in registration order — §4. |
| `findings_outside_registry` | array of findings | Findings the command's own fail-soft envelopes produced, which belong to no registered check — §5. |
| `inventory` | object | The per-disposition account of the run — §6. |
| `event_consumers` | object | The observed-vs-consumed reconciliation as data — §7. |

### 3a. `agent_scope_coverage` — read this before any scope-keyed negative

```jsonc
{
  "complete": false,
  "unread_agents": [
    { "agent": "prod-agent", "github_scopes": null },
    { "agent": "beta",       "github_scopes": ["owner/repo"] }
  ]
}
```

| Key | Type | Meaning |
| --- | --- | --- |
| `complete` | bool | `true` iff every `*.yml` in the config dir was read far enough for its subscriptions to reach the run's scope maps. |
| `unread_agents` | array | One entry per agent that was not, in abort order. Empty iff `complete`. |
| `unread_agents[].agent` | string | The agent name (its `<name>.yml`). |
| `unread_agents[].github_scopes` | array of string, **or `null`** | The github scopes that agent subscribes to, or `null` when its config did not parse — in which case *which* scopes it covers is itself unknown. **`null` is not `[]`**: an empty array is the real answer for an agent with no github subscription, and it rules that agent out of every scope. |

**Why it is top-level and not nested under `event_consumers`.** The run derives three per-scope maps from the agent loop — which scopes have a writeback-emitting classifier, which enable the coord-card-move family, and which classifiers consume which event types. All three are accumulated only by agents that got past both of the loop's aborts, so on a run with `complete: false` an absence in any of them means *"no agent enables this, OR the agent that does was never read"*. The document exposes one of the three directly (§7), and the other two reach you as `checks[]` findings.

**What a consumer must do.** Treat `complete: false` as poisoning every **negative** derived per scope — most sharply `event_consumers.scopes[].unconsumed`, which can only grow when a consumer is missing. Positives are unaffected: an unread agent can add a consumer, never remove one, so an empty `unconsumed` is sound either way. The corresponding prose findings already carry `severity: "unvalidated"` for exactly the scopes an unread agent could cover; this key is what lets a consumer reach the same conclusion without reading messages.

**It never moves `ok`.** Both abort sites fail the run before recording here, so `complete: false` implies `ok: false` — but not the reverse, and the implication is a property of the command, not a key you may invert.

## 4. `checks[]` — the per-check account

```jsonc
{
  "id": "channel.server_snapshot",
  "disposition": "reported",
  "not_run_reason": null,
  "findings": [
    {
      "severity": "unvalidated",
      "agent": "prod-agent",
      "message": "agent prod-agent: channel.server_path not declared — snapshot not validated"
    }
  ]
}
```

| Key | Type | Meaning |
| --- | --- | --- |
| `id` | string | The check's stable id. **This, not the message, is what a consumer keys on.** |
| `disposition` | string | One of the four below. |
| `not_run_reason` | string \| null | Human-readable cause. **Non-null only when `disposition` is `not-run`** — and it may be `null` even then (§6, `unexplained_not_run`). **The disposition is the authority; this field only ever explains it.** The gate is not cosmetic: internally a reason is attached to a whole *slot* before the run knows whether that slot will execute, so a per-agent check noted for the agents that aborted and then run for one that did not holds a reason **and** reports. Emitting it there would put *"every parsed agent aborted before this leg"* beside `"disposition": "reported"` and a finding — a document contradicting itself, and a consumer reading a non-null reason as *did not run* would be precisely wrong on it. |
| `findings` | array | Possibly empty. Each: `{severity, agent, message}`. |

**The list is driven by the registration list, not by the results** — so a check that never got to look is a **visible row with a disposition**, not an absence. That is the whole point: on a baseline install a large minority of registered checks are never invoked, and a results-driven document would silently omit exactly the ones an operator most needs to know were not measured.

### Dispositions

| `disposition` | Meaning | What it is **not** |
| --- | --- | --- |
| `reported` | Ran, and yielded at least one finding. | — |
| `silent` | **Ran and had nothing to say** — a positive statement about the install (no collisions, no unconsumed types). | Not the same as never running. **⚠ It is also not distinguishable from a bug**: a check that falls off the end of its generator through an unintended path records `silent` too (tracked as card#5596). What this surface buys is that the silence is visible and counted, not that it is certified. |
| `not-requested` | An **opt-in** check whose flag the operator did not pass (`--probe-tools`, `--probe-tools-ssh`). | Carries **no statement about the install** — the check was never asked to look. Deliberately not the `unvalidated` severity: the not-running is a fact about the *invocation*, which the operator knows because they chose not to type the flag. |
| `not-run` | Registered, but its conditional envelope never opened this run (no `writeback.json`, no enabled `board_tools`, no agent configs, or a fail-soft catch fired). | Not a failure. `not_run_reason` names the cause where one was recorded. |

### `findings[]`

| Key | Type | Meaning |
| --- | --- | --- |
| `severity` | `"fail"` \| `"warn"` \| `"unvalidated"` \| `"ok"` | **`fail` is the only one that flips `ok` and the exit code.** `unvalidated` keeps **exactly one meaning** — *"I should have measured this and the install stopped me"* — so a green run is not evidence about it. The rule that decides it (per LEG, not per check) is owned by `App\Bridge\Support\Severity`'s docblock; this row must stay in lockstep with that one sentence and does not restate the rest of it. |
| `agent` | string \| null | The agent a per-agent check ran for; `null` for a global check. **This attribution exists only in this document** — the text report has no per-check framing to put it in. |
| `message` | string | Operator prose. **Not part of the contract** (§2). |

**⚠ What `unvalidated` does and does not let you infer — NARROWED by stage 10, not closed.** Until DL-251 this row disclosed that a check reporting `warn` *because it could not run* read here as a finding rather than as a not-run; that sweep has landed, and every leg that reports being unable to measure now carries `unvalidated`. The residual is smaller and different in kind: the rule the vocabulary follows is keyed on **what a leg concluded**, so a leg that fails to *notice* it measured nothing falls outside it — and such a leg is not reliably silent. It may emit nothing, or it may emit the conclusion it would have drawn had the measurement happened, at that conclusion's severity. **`severity: "unvalidated"` is therefore sound evidence that a leg could not measure; its ABSENCE is still not evidence that everything was measured, and no other severity is evidence that anything was.** The `fail` and `ok` populations were not audited against the rule. **Two members of that residual have since been made explicit and the rest have not:** the legs reading the run's per-scope maps now disclose an incomplete agent roster (§3a) instead of asserting through it, so `agent_scope_coverage.complete` is a *structured* form of the same warning for that cause; and the swallowed throw inside the consumed-events derivation — which used to report a classifier that DOES declare as one that does not, then warn that its events were silently dropped — now lands in `event_consumers.scopes[].unreadable` and withholds the verdict (card#5698, DL-257). **Neither is a general guarantee:** do not read `complete: true` with an empty `unreadable` as "everything was measured" — those two are the causes that have been made structural, not the whole residual.

## 5. `findings_outside_registry[]`

Findings from the four fail-soft envelopes in the command itself — a malformed agent YAML, an unloadable `writeback.json`, and two client-construction failures. Same `{severity, agent, message}` shape; `agent` is always `null`.

**They are in the document because two of them set the exit code.** Omitting them would produce a document whose `checks` are all clean and whose `ok` is `false`, with nothing in it explaining why — a machine-readable false clean, the exact defect this program exists to remove. Usually an empty array.

## 6. `inventory`

```jsonc
"inventory": {
  "registered": 37,
  "ran": 31,
  "reported": 15,
  "silent": 16,
  "not_requested": 2,
  "not_run": 4,
  "not_run_reasons": ["no agent has an enabled board_tools block"],
  "unexplained_not_run": []
}
```

| Key | Type | Meaning |
| --- | --- | --- |
| `registered` | int | Every check in the registry. `reported + silent + not_requested + not_run === registered` on every run that completes — the arithmetic is the line's own control. |
| `ran` | int | `reported + silent`. |
| `reported` / `silent` / `not_requested` / `not_run` | int | Counts per disposition (§4). |
| `not_run_reasons` | array of string | The **distinct** reasons, deduplicated, in the registration order of the first not-run check carrying each. Deduplicated because one envelope closing accounts for several checks and the operator needs the cause once, not once per check. |
| `unexplained_not_run` | array of check id | Not-run checks whose reason was never recorded. **A degraded message, not a hole** — the accounting is derived from the registration list, so a forgotten reason lands a check here rather than losing it. Normally empty; a non-empty array is a bridge bug worth reporting, not an install problem. |

**What the inventory does not establish** — stated because an unstated bound reads as a guarantee:

- **It cannot see a check nobody wrote.** It accounts for the *registered* set. A `bridge:check` leg that never became a `Check` is outside it.
- **Every count is keyed by check id.** A per-agent check is **one** row however many agents it ran for — one that ran for two of three agents counts once, as having run, and nothing here scopes that to the agents it reached. Use `checks[].findings[].agent` for per-agent detail, and note that a check can be `silent` for one agent and `reported` for another while the row says `reported`.
- **`not_run_reason` is the envelope's claim about itself.** If an envelope's condition is wrong, the reason is confidently wrong with it.
- **A run that does not complete produces no document at all.** A check that throws aborts the command before anything renders — the operator gets no accounting rather than a partial one. **An absent document is not an empty one**; a consumer must distinguish "no stdout / non-JSON" from a parsed document.

## 7. `event_consumers`

The observed-vs-consumed reconciliation: for each subscribed github scope, which event types **arrived** and which the enabled classifiers actually **consume**. Before this surface existed, its only machine-readable form was the WARN sentence.

```jsonc
"event_consumers": {
  "error": null,
  "scopes": [
    {
      "scope": "owner/repo",
      "agents": ["writeback-agent"],
      "observed":         { "pull_request": { "count": 436, "last": "2026-07-26 20:01:33" } },
      // trimmed for the example — a real scope lists every action seen
      "observed_actions": { "pull_request": { "closed": { "count": 202, "last": "2026-07-26 20:01:33" } } },
      "consumed":   ["push", "workflow_run", "pull_request"],
      "bare":       ["push", "workflow_run", "pull_request"],
      "qualified":  {},
      "undeclared": [],
      "unreadable": [],
      "unconsumed": [],
      "unlisted_actions": {}
    }
  ]
}
```

| Key | Type | Meaning |
| --- | --- | --- |
| `error` | string \| null | **Read this FIRST.** The reconciler is fail-soft, so a DB fault never breaks `bridge:check`. An empty `scopes` with a **null** error is a clean install; an empty `scopes` with a **non-null** error is **a measurement that did not happen**. A consumer that reads the list without reading this has turned a failed measurement into a clean bill of health. Scopes that completed before the fault are retained. |
| `scopes[]` | array | One entry per subscribed github scope, in the order the run first encountered each scope while reading agent configs; **truncated at the failing scope** when `error` is set (scopes that completed are still owed to you, so they are kept). |

Per scope:

| Key | Type | Meaning |
| --- | --- | --- |
| `scope` | string | The github scope id (a repo `full_name`). |
| `agents` | array of string | The agents subscribed to this scope, deduplicated. |
| `observed` | **object** (map) | Top-level event type ⇒ `{count, last}` of arrivals. |
| `observed_actions` | **object** (map) | Top-level ⇒ action ⇒ `{count, last}`. Actionless types (`push`) never appear. |
| `consumed` | array of string | Every declaration projected to its top level, unioned across the scope's consumers. |
| `bare` | array of string | The subset of `consumed` some consumer declared **without** an action (`issues` — the type is owned, every action covered). |
| `qualified` | **object** (map) | Top-level ⇒ the actions declared for it (`issues.opened`), unioned. |
| `undeclared` | array of `{agent, class}` | Enabled classifiers that do not implement `DeclaresConsumedEvents` — the reconciliation cannot speak for what they consume. |
| `unreadable` | array of `{agent, class}` | Enabled classifiers that **do** implement `DeclaresConsumedEvents` and **threw** when asked what they consume — the run holds none of their declarations. **Disjoint from `undeclared`, and never fold the two together:** these classifiers declare, and what is missing is this run's ability to read the declaration, not the declaration itself. Non-empty ⇒ `unconsumed` is bounded exactly as an incomplete `agent_scope_coverage` bounds it. |
| `unconsumed` | array of string | **Derived, and emitted rather than left to you:** types that arrived and no consumer covers — the WARN population. **⚠ Read `agent_scope_coverage.complete` (§3a) AND this scope's `unreadable` first:** the consumer list behind this is short by an agent the run never read *and* by a classifier whose declaration threw, so on either it can list types that ARE consumed. It can only over-list — an empty `unconsumed` is sound under both. |
| `unlisted_actions` | **object** (map) | **Derived:** actions that arrived on a type consumed *only* via qualified declarations, most-frequent first. A **bare** type is absent here (owned ⇒ every action covered), and so is a type nothing consumes (that is `unconsumed`'s alarm — reporting it at both tiers is the flattening this design avoids). |

**Why `unconsumed` / `unlisted_actions` are in the document rather than left to the consumer:** they *are* what the WARN and INFO lines say, and the point of the surface is that nobody re-implements the projection rule to get them. Both are computed from the operands beside them in the same pass, so they cannot drift from them.

**The type/action tier is load-bearing, not decoration.** A consumer that flattens `bare` and `qualified` opens with hundreds of findings on a healthy install — one fleet seat measured `labeled (468×), edited (14×)` on a type whose bare declaration already covered them. That cry-wolf outcome is what the tier exists to prevent.

**Bounds:**

- **`observed` can only show events that were both SUBSCRIBED and DELIVERED.** It structurally cannot answer *"what could arrive"*, and a type absent from it is not evidence the upstream never sends it.
- **The declaration half is computed even when nothing arrived** — `consumed` / `bare` / `qualified` are meaningful on a scope with zero arrivals, which is the half a config-auditing consumer reads. (The text renderer stays silent for such a scope; the data does not inherit that bound.)
- **Every possibly-empty map is encoded as a JSON object, never `[]`** (`observed`, `observed_actions`, `qualified`, `unlisted_actions`). This is asserted on the encoded bytes, so a consumer indexing them never has to handle two types for one field. The nested maps inside them cannot be empty by construction.

## 8. Where the guard lives

| Surface | File |
| --- | --- |
| The renderer | `app/Bridge/Check/CheckJsonRenderer.php` |
| The shape guard (exact key sets, every level) | `tests/Feature/Console/Check/CheckJsonContractTest.php` |
| Cross-renderer agreement over all install shapes (verdict, exit, inventory counts vs. the committed text capture) | `tests/Feature/Console/Check/CheckGoldenTest.php` |
| The reconciliation derivation | `app/Bridge/Check/EventConsumers/EventConsumerReconciler.php` |
| Why the surface is shaped this way | [`CHECK-REGISTRY-PLAN.md`](CHECK-REGISTRY-PLAN.md) § Stage 9 result; **DL-249** |

**Changing the shape:** update the literal key sets in `CheckJsonContractTest` in the **same commit** as the renderer, and decide deliberately whether the change is additive (no bump) or consumer-visible (bump `SCHEMA_VERSION` — and say so in `docs/CHANGELOG.md`). When that test reds, it is usually right.
