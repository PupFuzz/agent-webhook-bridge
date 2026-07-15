# Customization

## Two extension points

| Extension | What it does | Loaded via | Default |
|---|---|---|---|
| **Classifier** | Maps webhook events to `Intent` (inbox) and/or `ReactionTarget` (handler) instances. | `classifier.class` in agent YAML (FQCN). | `App\Bridge\Classifiers\InboxOnlyClassifier` |
| **Handler** | Implements `Handler` contract; dispatched by name synchronously in the same request. | `afterResolving(HandlerRegistry::class, fn ($r) => $r->register(name, instance))` in a `ServiceProvider`. | Eight always ship: `log_intent`, `registry_append`, `channel_push`, and the five kanban writeback handlers `kanban_move_card` (DL-020), `kanban_dependabot_card` (DL-024), `kanban_block_reason` (DL-193), `kanban_coord_card` (DL-198), `kanban_coord_card_move` (DL-200) — all inert without `writeback.json`. `spawn_detached` is opt-in (`BRIDGE_SPAWN_ENABLED`, DL-011). |

The Python-era surface formatter (a callable swapped into `bin/inbox`) does not exist in v0.12. `bridge:inbox` ships one built-in Markdown renderer; the output format is not operator-swappable. To reshape output, post-process `bridge:inbox` stdout or read `inbox.jsonl` directly (see [`consumer-guide.md`](consumer-guide.md)).

Reference implementations (read these first — they're short):

- `app/Bridge/Classifiers/InboxOnlyClassifier.php` — canonical classifier (~160 LOC)
- `app/Bridge/Classifiers/EventDrivenClassifier.php` — event-driven subclass
- `app/Bridge/Classifiers/CoordinationClassifier.php` — the config-driven, config-gated superset (roundtable #8): `extends InboxOnlyClassifier` (so it inbox-stages kanban `task.*` events) and runs the `classifier.config.families` you enable — `coord-message` (GitHub coordination messages), `impl-ci-wake` (push→release-branch + CI wake), `kanban-triage` (below), `coord-card-create` (real-time coord issue → card, DL-198), `coord-card-move` (coord issue close→terminal / reopen→revive, DL-200). Default `[coord-message]` = the pre-#8 behavior; the two coord-card families are opt-in and additionally gated on the repo's `writeback.json` mapping (`create_coord_cards` / `move_coord_cards`). See [`config-schema.md`](config-schema.md) § `classifier.config`.
- `app/Bridge/Classifiers/KanbanTriageClassifier.php` — **back-compat shim** for the triage-wake (DL-168): a thin subclass of `CoordinationClassifier` that defaults its family set to `[kanban-triage]`. Set `classifier.class: App\Bridge\Classifiers\KanbanTriageClassifier` on the **triage-owner agent** and subscribe it to `task.created`, and a **human-filed, untriaged** card (no `triaged`/`id:pr:*` tag, no `dl` ref — read from the kanban DL-164 `card` snapshot, no API call) wakes that agent's session via `channel_push`. Agent/bridge/dependabot-created and already-classified cards don't wake. Requires kanban **v0.22.0+**; other agents keep `InboxOnly` and never wake. Equivalent to `classifier.class: …\CoordinationClassifier` with `config.families: [kanban-triage]` — prefer the latter directly for new installs.
- `app/Bridge/Handlers/` — shipped handlers (`log_intent`, `registry_append`, `channel_push`, `kanban_move_card`, `kanban_dependabot_card`, `kanban_block_reason`, `kanban_coord_card`, `kanban_coord_card_move`; `spawn_detached` opt-in)
- `app/Bridge/Contracts/Classifier.php` + `Handler.php` — contracts your class must implement

---

## Writing a classifier

Implement `App\Bridge\Contracts\Classifier`:

```php
public function classify(ClassifyContext $ctx): ClassifyResult;
```

The single `ClassifyContext` parameter (DL-025) replaced a positional signature, because a parameter object is **extensible without breaking implementors** — adding a field to `ClassifyContext` never changes this method's signature, so it can never again cause the uncatchable `E_COMPILE_ERROR` that earlier positional additions did. It is the **last breaking change** to `classify()`. **Operators updating an existing install with a custom classifier:** an old signature fatals the receiver on the next live delivery — migrate your class *in the same step as `git pull`* and run `bridge:check` before serving. See [`CLAUDE_DEPLOYMENT.md`](../CLAUDE_DEPLOYMENT.md) § Update an existing install (the "Running a custom classifier?" callout).

**`ClassifyContext` fields:**

- `$ctx->eventType` — wire-format event name (`"task.created"`, `"comment.created"`, etc.).
- `$ctx->payload` — parsed envelope as a plain PHP array. The kanban adapter normalizes to `['event', 'board_id', 'subject_id', 'subject_type', 'action', 'payload', 'user_id', 'timestamp', 'delivery_id', 'attempt', ...]`. The nested `payload['payload']` is the event-specific data. See [`provider-adapters.md`](provider-adapters.md) for other providers' shapes.
- `$ctx->actor` — `Actor` resolved against the registry (built by scanning the per-agent YAMLs' `identity` blocks). `$actor->name` is the friendly name (null if unknown); `$actor->isKnownAgent` is true only for registry entries; `$actor->id` is the raw provider id; `$actor->rawEnvelope` holds raw actor fields from the adapter. **Echo suppression has already happened** — do not re-filter inside `classify`.
- `$ctx->provider` — upstream system id (`"kanban"`, `"github"`, etc.). Pass through to every `Intent` you construct.
- `$ctx->scopeId` — receiver-extracted scope id (kanban `board_id` stringified, GitHub `repository.full_name`, etc.).
- `$ctx->agent` — the **serving agent's** `AgentConfig` (the dispatcher calls `classify()` once per subscribed agent). Use `$ctx->agent->agentName` / `$ctx->agent->identity` to make **per-agent (recipient-aware)** decisions — e.g. drop an event not addressed to this agent. See [Per-agent (recipient-aware) classification](#per-agent-recipient-aware-classification). One classifier instance is cached + shared across agents, so per-event state lives on `$ctx`, never on the instance.

**Output:** a `ClassifyResult` with either or both lists (both may be empty):

- `$intents` — `list<Intent>` staged to `inbox.jsonl`
- `$targets` — `list<ReactionTarget>` dispatched against the handler registry
- `$reattributedActor` — *optional* `?Actor`; only for a shared upstream identity (see [Per-agent echo for a shared upstream identity](#per-agent-echo-for-a-shared-upstream-identity)). Null in the common case.

### Minimal worked example

```php
<?php
// app/Bridge/Classifiers/MyClassifier.php
// (or any autoloaded location — see "Loading your classifier" below)

namespace App\Bridge\Classifiers;

use App\Bridge\Contracts\Classifier;
use App\Bridge\Dispatch\ClassifyContext;
use App\Bridge\Dispatch\ClassifyResult;
use App\Bridge\Dispatch\Intent;
use App\Bridge\Dispatch\ReactionTarget;

final class MyClassifier implements Classifier
{
    public function classify(ClassifyContext $ctx): ClassifyResult
    {
        $eventType = $ctx->eventType;
        $payload = $ctx->payload;
        $actor = $ctx->actor;
        $provider = $ctx->provider;

        if ($eventType === 'task.created') {
            $name = ($payload['payload']['name'] ?? null) ?: '<unnamed>';
            $subjectId = (string) ($payload['subject_id'] ?? '');
            $who = $actor->name ?? $actor->id ?? '?';

            return new ClassifyResult(
                intents: [
                    new Intent(
                        kind: 'new_card',
                        subjectId: $subjectId,
                        provider: $provider,
                        actor: $actor,
                        summary: "new card by {$who}: {$name}",
                        payload: ['board_id' => $payload['board_id'] ?? null],
                    ),
                ],
            );
        }

        if ($eventType === 'task.moved') {
            // Dispatch a sync_board reaction instead of (or in addition to)
            // an inbox surface.
            return new ClassifyResult(
                targets: [
                    ReactionTarget::make(
                        handler: 'sync_board',
                        targetId: (string) ($payload['board_id'] ?? ''),
                        debounceSeconds: 30,  // collapse same-board burst events
                        payload: ['board_id' => $payload['board_id'] ?? null],
                    ),
                ],
            );
        }

        return new ClassifyResult;  // noise — silently skip
    }
}
```

Agent config YAML:

```yaml
classifier:
  class: App\Bridge\Classifiers\MyClassifier   # FQCN; backslash prefix is stripped automatically
```

The class is resolved by `ClassifierResolver::for(AgentConfig)` via `new $class`, checked `instanceof Classifier`, and cached per FQCN for the FPM worker's process lifetime. **`classify()` must be stateless** — per-event mutable state in method-local variables, not instance fields. A missing or non-implementing class throws `ConfigException` at the first request to that agent.

> **Verify after deploy:** `php artisan bridge:check` parses all YAMLs and reports bad FQCNs before any webhook lands. Then trigger or replay a test event and check `php artisan bridge:stats` for `errored=0`.

### Loading your classifier

The class needs to be autoloaded by Composer:

1. **`app/Bridge/Classifiers/`** (or any namespace under `app/`) — autoloaded by Laravel's `App\` PSR-4 map.
2. **A separate package/directory** added to `composer.json`'s `autoload.psr-4` block.

After adding or moving a class, run `composer dump-autoload`.

### Emitting writeback reactions from a custom classifier (#2162)

If your classifier emits writeback `ReactionTarget`s (`kanban_move_card` / `kanban_dependabot_card`) to drive a `writeback.json` mapping, **also implement the marker interface `App\Bridge\Contracts\EmitsWritebackReactions`** (it has no methods). `bridge:check` uses it to detect an *orphaned* mapping — one whose repo scope no agent's classifier drives — and warn that the mapping is inert. A subclass of `GitHubPrCardMoveClassifier` inherits the marker automatically; a from-scratch classifier must add it, or `bridge:check` will (falsely) report its mappings as orphaned.

### Declaring which GitHub events your classifier consumes (card#4183 / DL-196)

`bridge:check` runs an **event-follows-consumer** check: for each `github:<scope>`, it unions the top-level event types every enabled classifier subscribed to that scope consumes, compares that against the event types **actually received** for the scope (from the bridge's own `webhook_events` history), and **warns** (never fails) about any received event type that nothing consumes — a subscription that arrives and is silently dropped. To participate, **implement `App\Bridge\Contracts\DeclaresConsumedEvents`**:

```php
public function consumedEventTypes(ClassifierConfig $cfg): array
{
    return ['pull_request', 'push'];   // TOP-LEVEL types, no `.action` suffix
}
```

Return the event types as **bare** top-level entries (`pull_request`, `push` — the type is OWNED: every action covered, unlisted actions are deliberate no-ops) and/or **qualified** entries (`issues.opened` — exactly these actions; other observed actions of the type surface in `bridge:check`'s informational action inventory, card #4354; the inventory is INFO, never a WARN — GitHub has no per-action unsubscribe, so there is no remedy to alarm about). Gate on `$cfg` when your consumed set is config-driven (e.g. `CoordinationClassifier` unions only its *enabled* families). **HARD CONTRACT:** the method must be a pure `$cfg` → event-types map — no lazy class-loading, no side effects; it runs inside `bridge:check` and an impl that loads un-probed code can fatal past the guard (DL-025). A classifier that does *not* implement the interface contributes nothing to the consumed set (conservative — at worst a false warn, which `bridge:check` flags as *possibly* a false positive by naming the undeclared classifier, never a false clean). Note: the observed set is the full un-pruned `webhook_events` history (retention is event-gated or manual), so a long-remediated stray keeps warning until pruned — the WARN carries its **occurrence count + last-seen timestamp** so an old last-seen reads as remediated history rather than live drift (deliberately NOT a recency window, which would let old-but-real drift read clean).

### Extending a shipped classifier (subclass pattern)

For agents wanting `InboxOnlyClassifier` behavior plus extra targets, subclass rather than copy. This is exactly what `EventDrivenClassifier` does:

```php
<?php

namespace App\Bridge\Classifiers;

use App\Bridge\Dispatch\ClassifyContext;
use App\Bridge\Dispatch\ClassifyResult;
use App\Bridge\Dispatch\Intent;
use App\Bridge\Dispatch\ReactionTarget;

final class MyEventDrivenClassifier extends InboxOnlyClassifier
{
    public function classify(ClassifyContext $ctx): ClassifyResult
    {
        $result = parent::classify($ctx);

        if ($result->intents === []) {
            return $result;
        }

        // Pair every Intent with a channel_push target by subject_id.
        // The silent-drop guard checks for this invariant (subject_id match).
        $channelTargets = array_map(
            fn (Intent $intent): ReactionTarget => ReactionTarget::make(
                handler: 'channel_push',
                targetId: $intent->subjectId,
                debounceSeconds: 0,
                payload: $intent->toArray(),   // canonical wire shape
            ),
            $result->intents,
        );

        return new ClassifyResult(
            targets: array_merge($result->targets, $channelTargets),
            intents: $result->intents,
        );
    }
}
```

Point `classifier.class` at `EventDrivenClassifier` directly if it fits, or subclass it further.

**If your subclass consumes GitHub events, also implement `DeclaresConsumedEvents`** (§ *Declaring which GitHub events your classifier consumes*, above). `InboxOnlyClassifier` and `EventDrivenClassifier` do **not** declare consumed event types — they inbox-stage kanban `task.*`, not GitHub events — so a subclass that adds GitHub-event handling inherits an *empty* consumed set. `bridge:check`'s event-follows-consumer probe then reads your classifier as consuming nothing and **false-WARNs** on every event type only your subclass consumes (e.g. `issues` / `issue_comment` / `workflow_run`) for its scopes, since no *other* classifier covers them. Declare the top-level types your subclass consumes so the probe counts it (the WARN is fail-safe — it names the undeclared classifier and can never false-*clean* — but declaring upfront avoids the noise).

### Surfacing GitHub issue comments to a channel (forward the comment identity)

The shipped classifiers surface **kanban** events; the only GitHub handling in the box is the PR→card-move **writeback** (`GitHubPrCardMoveClassifier`), which emits no agent-facing Intent. To wake an agent live on a new GitHub **issue comment**, write a custom classifier. The one rule worth getting right is **what you put in the payload**: forward the *comment identity*, not just the thread.

> **Why `comment_id` matters (the footgun this avoids).** If the Intent carries only the issue number, a consumer that wants to see *what was just posted* must re-read the whole thread and **guess which comment is new**. GitHub's REST issue-comments endpoint paginates **30/page, oldest-first, with no `sort`/`direction` param**, so a naive `--jq '.[-1]'` on an un-paginated fetch returns the **30th** comment — not the newest — on any thread with >30 comments (new replies then look like stale replays and get dropped). The webhook body already carries the full `comment` object, so forwarding `comment.id` lets the consumer do **one exact fetch** — `GET /repos/<repo>/issues/comments/<comment_id>` — and de-dup replays **by id**. Purely additive: a consumer that ignores the fields can still fall back to a (fully paginated) thread read.

```php
<?php
// app/Bridge/Classifiers/GitHubIssueCommentClassifier.php
//
// OPERATOR-AUTHORED reference — NOT shipped or autoloaded by the bridge. Copy it
// into your install; after each bridge update, reconcile it against THIS section
// (CLAUDE_DEPLOYMENT.md -> "Reconcile out-of-repo copies") — a git pull never
// touches a class the bridge doesn't ship.

namespace App\Bridge\Classifiers;

use App\Bridge\Contracts\Classifier;
use App\Bridge\Dispatch\ClassifyContext;
use App\Bridge\Dispatch\ClassifyResult;
use App\Bridge\Dispatch\Intent;
use App\Bridge\Dispatch\ReactionTarget;

final class GitHubIssueCommentClassifier implements Classifier
{
    public function classify(ClassifyContext $ctx): ClassifyResult
    {
        if ($ctx->provider !== 'github' || $ctx->eventType !== 'issue_comment.created') {
            return new ClassifyResult;   // not a new issue comment — skip
        }

        $payload = $ctx->payload;
        $issue = is_array($payload['issue'] ?? null) ? $payload['issue'] : [];
        $comment = is_array($payload['comment'] ?? null) ? $payload['comment'] : [];

        $number = $issue['number'] ?? null;
        if (! is_scalar($number)) {
            return new ClassifyResult;   // malformed — no thread to address
        }

        // subject_id = the THREAD (owner/repo#N); the comment identity rides in
        // the payload. The channel_push target_id must equal this subject_id
        // (the silent-drop guard pairs them).
        $subjectId = $ctx->scopeId.'#'.$number;
        $who = $ctx->actor->name ?? $ctx->actor->id ?? '?';

        $intent = new Intent(
            kind: 'github_issue_comment',
            subjectId: $subjectId,
            provider: $ctx->provider,
            actor: $ctx->actor,
            summary: "issue comment on {$subjectId} by {$who}",
            payload: [
                'repo' => $ctx->scopeId,
                'number' => $number,
                // Comment identity — already in the webhook body; forward it so the
                // consumer can exact-fetch + de-dup by id. Absent fields (older or
                // synthesized events without a source comment) fall back to null.
                'comment_id' => $comment['id'] ?? null,
                'comment_created_at' => isset($comment['created_at']) && is_scalar($comment['created_at'])
                    ? (string) $comment['created_at']
                    : null,
                'comment_html_url' => isset($comment['html_url']) && is_scalar($comment['html_url'])
                    ? (string) $comment['html_url']
                    : null,
            ],
        );

        // Pair the Intent with a live channel_push (no socket/url in the payload
        // ⇒ the handler uses this agent's configured channel endpoint).
        // debounceSeconds: 0 — each comment is its own webhook/request under the
        // synchronous dispatch model, so per-comment pushes never coalesce.
        return new ClassifyResult(
            intents: [$intent],
            targets: [ReactionTarget::make(
                handler: 'channel_push',
                targetId: $intent->subjectId,
                debounceSeconds: 0,
                payload: $intent->toArray(),
            )],
        );
    }
}
```

The same shape generalizes to the other comment-bearing events — PR review comments (`pull_request_review_comment.created`) and review threads — by reading the triggering entity's own `id` + `created_at` from its payload object and forwarding them identically.

**Optional addressing layer.** The example above wakes *every* subscribed agent on *every* new comment. If several agents share one upstream GitHub account (so `sender.id` can't disambiguate them), or you want a comment's `TO:` line to narrow recipients, layer the recipient-aware and shared-identity patterns on top — see [Per-agent echo for a shared upstream identity](#per-agent-echo-for-a-shared-upstream-identity), [Per-agent (recipient-aware) classification](#per-agent-recipient-aware-classification), and the [`TO:`-line helper](#comment-level-recipient-filtering-the-to-line-dl-032). Keep them out of the base classifier unless your deployment actually needs them.

### Per-agent echo for a shared upstream identity

Echo suppression runs **before** `classify()`, matching the resolved `Actor` against the agent's own name (auto-seeded from its `identity` ids) + `treat_as_echo` (by name) and `treat_as_echo_ids` (by raw id). That works when each agent has its own upstream account. But when several agents **share one** upstream account (declared once in `shared-identities.json` — see [`multi-agent.md`](multi-agent.md)), the registry deliberately resolves `Actor.name = null` (it can't pick one agent), so the only echo lever left is `treat_as_echo_ids` on the raw id — and that is **all-or-nothing**: it either suppresses the shared account for *every* agent (killing the whole inbox) or for none (so each agent sees its own writes echoed back). There is no per-agent middle ground pre-classify, because the true author isn't known yet.

`ClassifyResult::$reattributedActor` closes that gap. A classifier that recovers the true author from a secondary signal (a `FROM:` line in the event body, repo scope → agent mapping, etc.) returns it on the result; **after** `classify`, the dispatcher re-runs the **same** per-agent echo check on the reattributed actor and drops the event only when it is the serving agent's own write:

```php
public function classify(ClassifyContext $ctx): ClassifyResult
{
    $actor = $ctx->actor;

    // Shared account → Actor.name is null. Recover the author from your own
    // convention (here: a "FROM: <agent>" line in a comment body) via the shipped
    // RecipientAddressing::author() helper — no hand-rolled regex.
    $reattributed = null;
    if ($actor->name === null) {
        $body = (string) ($ctx->payload['payload']['body'] ?? '');
        $author = RecipientAddressing::author($body);   // first FROM: line, or null
        if ($author !== null) {
            $reattributed = new Actor(id: $actor->id, name: $author, isKnownAgent: true);
        }
    }

    // Build intents/targets as normal, using $reattributed ?? $actor for display.
    $intents = [/* ... */];

    return new ClassifyResult(intents: $intents, reattributedActor: $reattributed);
}
```

- **You report *who*; the dispatcher decides *is that me?*.** Although `classify()` receives the serving agent via `$ctx->agent`, for shared-identity *echo* you still just **name the author** and let the dispatcher apply that agent's own name / `treat_as_echo` — reusing the canonical echo logic rather than reimplementing it per classifier. The same classifier then yields per-agent self-echo across all the agents sharing the account. (One cached instance serves all agents — read `$ctx->agent` as a local, never stash it.)
- **A different shared-id agent's write still surfaces** — its recovered name isn't the serving agent's own name, so it isn't an echo for that agent.
- **Leave it `null` when you didn't recover an author** (or there's nothing to recover) — the result dispatches unchanged. Every shipped classifier leaves it null, so this is a no-op unless you opt in.

This is the completion of the `shared_identities` design (DL-002): the registry preserves the null name on purpose so this recovery layer can re-attribute. See [`multi-agent.md`](multi-agent.md) § Path C for the full shared-identity walkthrough.

### Per-agent (recipient-aware) classification

`classify()` receives the **serving agent** as `$ctx->agent` (an `AgentConfig`). On a single install fanning out to several agents, the dispatcher invokes `classify()` **once per subscribed agent** — so the classifier can make decisions that differ per recipient: most usefully, **dropping an event that isn't addressed to this agent** (DL-022).

The classic case: several agents share a coordination scope (one repo / board) where messages are addressed with `to:<agent>` / `to:all` labels, and each agent should act only on what's addressed to it. Before `$ctx->agent` existed, a shared classifier couldn't tell *which* agent it was serving, so every agent saw every coordination message. Now it can filter:

```php
public function classify(ClassifyContext $ctx): ClassifyResult
{
    // Recipient filter ONLY on the shared coordination repo; own-repo events
    // and broadcasts pass through untouched.
    if ($ctx->scopeId === 'your-org/coordination') {
        $labels = $this->labels($ctx->payload);            // e.g. ['to:backend', 'from:pm']
        $me = $ctx->agent->agentName;                       // 'backend'
        $forMe = in_array("to:{$me}", $labels, true)
            || in_array('to:all', $labels, true)
            || in_array("from:{$me}", $labels, true);
        if (! $forMe) {
            return new ClassifyResult;                      // not addressed to me → drop
        }
    }

    // ... normal surfacing / reactions ...
    return new ClassifyResult(intents: [/* ... */]);
}
```

- **`classify()` runs once per serving agent** — returning an empty `ClassifyResult` for one agent doesn't affect the others; each agent's dispatch is independent.
- **The instance is cached + shared across agents** (`ClassifierResolver` keys by class). Read `$ctx->agent` as a method-local; **never** stash it on an instance field — the next agent in the same dispatch loop would see stale state. (`$ctx` itself is a fresh per-event object, so reading from it is always safe.)
- **`$ctx->agent` carries the agent's own config** — `agentName` (the YAML filename), `identity` (`kanban_user_id` / `github_user_id`), `subscriptions`, etc. — so recipient logic can key on whatever the addressing convention uses.
- **Addressing is operator policy, not bridge policy.** The bridge hands you the serving agent; what `to:`/`from:` labels mean is yours to define in the classifier. (This is why the seam is a classify param, not a built-in label filter.)

#### Comment-level recipient filtering (the `TO:` line, DL-032)

Issue/card **labels** address a whole thread, so on a multi-recipient thread (`to:agentA, to:agentB, to:agentC`) *every* comment wakes *all three* — including comments whose **body** is addressed to just one (`TO: agentB`). To filter at the **comment** level, parse the comment body's `TO:` line with the shipped `App\Bridge\Support\RecipientAddressing` helper (so you don't re-implement the parse — the *policy* still lives here in your classifier, per DL-022):

```php
use App\Bridge\Support\RecipientAddressing;

public function classify(ClassifyContext $ctx): ClassifyResult
{
    if (str_starts_with($ctx->eventType, 'comment.')) {
        $body = (string) ($ctx->payload['comment']['body'] ?? '');   // your provider's body path
        $addressed = RecipientAddressing::addresses($body, $ctx->agent->agentName);
        // true  → a TO: line names me (or `all`)
        // false → a TO: line names others, not me  → drop
        // null  → no TO: line → fall back to the issue/card label behavior above
        if ($addressed === false) {
            return new ClassifyResult;                 // addressed to someone else → don't wake me
        }
        // $addressed === true  → addressed to me, proceed.
        // $addressed === null  → no TO: line; fall through to your existing
        //                        issue/card label check (don't suppress here).
    }
    // ... normal surfacing / reactions ...
    return new ClassifyResult(intents: [/* ... */]);
}
```

The three-state return is the contract: `true` wake, `false` skip, **`null` = no `TO:` line → fall back to labels** (a bare/empty `TO:` is treated as absent, so a typo can't silently suppress everyone). Matching is case-insensitive; the first `TO:` line wins; `all` addresses every agent. **Issue/card** events (opened/closed/labeled) keep using labels — only comment bodies carry a `TO:` line. Net: zero false-negatives (every addressed comment still delivered), no more cross-agent wake-noise on a shared thread.

> **⚠ Footgun — route a comment by the comment's OWN `TO:`/`FROM:`, never the parent issue's labels (DL-034).** A thread's issue/card labels freeze at thread-open. If you route *comments* by those labels, a reply that **reverses direction** (B answering A on a thread originally A→B) is silently dropped — the labels still say "A→B" while the reply is "B→A". This is the single most common shared-identity routing bug. Use the body's lines as authoritative: `RecipientAddressing::addresses($body, $me)` for the recipient (above) and **`RecipientAddressing::author($body)`** for the sender, with the label behavior only as the `null` fallback. Worked role-reversal case:
>
> ```php
> use App\Bridge\Support\RecipientAddressing;
>
> // Thread opened A→B (issue labels: to:B, from:A). A later comment reverses it:
> //   FROM: agentB
> //   TO: agentA
> //   ...reply body...
> $author    = RecipientAddressing::author($body);                  // 'agentb' (the reply's real sender)
> $addressed = RecipientAddressing::addresses($body, $ctx->agent->agentName);
> // For agentA serving: $addressed === true  → wake A (the reply IS for A),
> //   even though the issue's frozen labels say the thread is "to:B". Routing by
> //   labels here would skip A and the reply would vanish.
> // $author lets you attribute the reply to agentB (e.g. as ClassifyResult
> // ::$reattributedActor for the shared-identity echo check — see below), instead
> // of mis-crediting the thread opener.
> ```
>
> `author()` is symmetric with `recipients()`: the first `FROM:` line, lowercased + trimmed, or `null` (a bare/empty `FROM:` is absent; `FROMAGE:` doesn't match). It replaces the per-operator hand-rolled `preg_match('/^FROM:.../')` parse.

### Constructing the data shapes

**`Intent`** — a signal surfaced to the agent's conversation context:

```php
use App\Bridge\Dispatch\Intent;

new Intent(
    kind: 'new_card',          // string — groups by kind in bridge:inbox output
    subjectId: '1877',         // string — the resource being acted on
    provider: $provider,       // string — pass through from classify() arg
    actor: $actor,             // Actor — pass through from classify() arg
    summary: 'alice created card: My Task',  // string — the one-liner the agent reads
    payload: ['board_id' => 5],              // array — handler-scoped extras; plain PHP array
);
```

No `make()` factory, no freeze/thaw, no tuple-of-pairs. `toArray()` returns the canonical JSONL/channel-wire shape.

**`ReactionTarget`** — one automated reaction dispatched to a named handler:

```php
use App\Bridge\Dispatch\ReactionTarget;

// Named constructor (debounceKey defaults to targetId):
ReactionTarget::make(
    handler: 'sync_board',     // registered handler name
    targetId: '5',             // opaque to the bridge; meaningful to the handler
    debounceKey: null,         // string|null — coalescing bucket; defaults to targetId
    debounceSeconds: 30,       // int|null — null uses the dispatcher default
    payload: ['board_id' => 5],
);

// Or the full constructor:
new ReactionTarget(
    handler: 'sync_board',
    targetId: '5',
    debounceKey: 'board:5',
    debounceSeconds: 30,
    payload: ['board_id' => 5],
);
```

Same-event dedup is by `debounceKey` (last-wins): targets in one `ClassifyResult` sharing a `debounceKey` fire the handler once.

### Conventions + gotchas

- **`Intent::payload` and `ReactionTarget::payload` are plain PHP arrays.** No freeze/thaw, no tuple-of-pairs, no `payload_dict()`. The Python-era freeze machinery is absent in v0.12.
- **Distinct intent `kind` per lifecycle / shape.** `bridge:inbox` groups by `kind`. See `InboxOnlyClassifier::LIFECYCLE` for precedent (`card_removed` / `card_archived` / `card_restored` / `card_unarchived` as four distinct kinds).
- **Don't emit `Intent` for events the agent already authored.** Echo suppression handles this upstream. Use `treat_as_echo_ids` in config to mark your bot user's id as echo. (The one exception is a *shared* upstream identity, where the author isn't known until you recover it — see [Per-agent echo for a shared upstream identity](#per-agent-echo-for-a-shared-upstream-identity); even then you report the author, you don't filter.)
- **Lifecycle events are invalidation signals, not noise.** When kanban-board is your source of truth, `task.deleted` / `task.archived` / `task.restored` / `task.unarchived` indicate the agent may hold a phantom reference keyed on `subject_id`. Surface them.
- **No network calls inside `classify`.** `classify` runs synchronously in the webhook request — emit a `ReactionTarget` whose handler makes the call.
- **Classifier throws → event recorded + acked 200 (treatment A).** A deterministic bug must not wedge delivery into an 11-day retry storm. The raw event is stored; fix the classifier and `bridge:replay`. The classify pass does not get a second chance on the same delivery.

### Testing your classifier

Mirror `tests/Feature/Classifiers/InboxOnlyClassifierTest.php`:

```php
<?php

use App\Bridge\Classifiers\MyClassifier;
use App\Bridge\Dispatch\Actor;
use App\Bridge\Dispatch\ClassifyContext;
use App\Bridge\Support\AgentConfig;

it('emits new_card intent for task.created', function () {
    $actor = new Actor(id: '99', name: 'alice', isKnownAgent: false);

    $result = (new MyClassifier)->classify(new ClassifyContext(
        eventType: 'task.created',
        payload: [
            'event' => 'task.created',
            'board_id' => 5,
            'subject_id' => 1877,
            'subject_type' => 'App\\Models\\Task',
            'payload' => ['name' => 'hello'],
            'user_id' => 99,
            'delivery_id' => 'test-task.created-1877',
            'attempt' => 1,
        ],
        actor: $actor,
        provider: 'kanban',
        scopeId: '5',
        agent: AgentConfig::fromArray('my-agent', ['identity' => ['kanban_user_id' => 1], 'subscriptions' => []]),
    ));

    expect($result->intents)->toHaveCount(1);
    expect($result->intents[0]->kind)->toBe('new_card');
    expect($result->targets)->toBeEmpty();
});
```

Run with `vendor/bin/phpunit` (SQLite in-memory, no live DB needed).

---

## Registering a handler

Implement `App\Bridge\Contracts\Handler`:

```php
public function handle(ReactionTarget $target, AgentConfig $agent): void;
```

The classifier emits `ReactionTarget::make(handler: 'my_handler', ...)` and the dispatcher looks it up by name in `HandlerRegistry`. A handler throw is recorded as a best-effort note on that agent's dispatch row but does **not** fail the webhook or affect other agents (treatment C — the intent is already durable in the inbox).

> **Durable handlers (DL-009).** If your handler performs a side effect that must **not** be silently dropped (a writeback, an external state change), also implement the marker interface `App\Bridge\Contracts\DurableReaction`. Such a handler runs **before** the best-effort handlers, and its throw **propagates** (→ 5xx → upstream redelivers) instead of becoming a note — so the side effect is retried, not lost. **Contract: a `DurableReaction` handler must be idempotent** (redelivery re-runs the whole dispatch). Durability is a property of the handler, never of the `ReactionTarget`, so the classify path can't spoof it. **Durable ≡ machine writeback ≡ survives echo (DL-203):** on a github writeback-emitting classifier, an echo/signal gate hit strips every intent and every non-`DurableReaction` target before dispatch — an unmarked custom handler is agent-facing and suppressed on the agent's own writes; implement the marker only for a machine writeback.

### Default shipped handlers

- **`log_intent`** — appends the target (JSON line) to `<state_dir>/handler-log.jsonl`. Forensic log only; not read by `bridge:inbox`.

- **`registry_append`** — appends the target to `<state_dir>/registry-<sanitized_target_id>.jsonl`. Use for per-resource activity ledgers.

- **`spawn_detached`** — fires a detached child process for `payload['cmd']` (argv list). Use for slow external reactions that must not block the synchronous request. **Opt-in + allowlisted (DL-011):** it is the highest-blast-radius handler (RCE as the install user), so it is *not registered* unless `BRIDGE_SPAWN_ENABLED=true`, and the program (`cmd[0]`) must be one of `BRIDGE_SPAWN_ALLOWLIST` (absolute paths). Execution is **shell-free** — `proc_open` with the argv array execs the program directly (no `/bin/sh -c`, so no shell-metacharacter surface), and `setsid -f` detaches it. `cwd`/`env` are passed as `proc_open` parameters, not a shell prefix. A `spawn_detached` target on an install where it is disabled (or whose `cmd[0]` is not allowlisted) is a recorded best-effort note, not an execution.

  > ⚠ **Allowlist a fixed-purpose wrapper, never an interpreter or flag-flexible tool** (`php`, `bash`, `env`, `git`, `find`, `awk`, `ssh`, …). The allowlist only constrains `cmd[0]`; the classifier still supplies `cmd[1..]`, so a single allowlisted `/usr/bin/php` or `/usr/bin/git` lets attacker-influenced arguments run arbitrary code (`php -r …`, `git -c core.sshCommand=…`) — reopening the exact RCE this guards. Point the allowlist at a script that does one thing and takes no code-bearing arguments.

  ```php
  // Requires BRIDGE_SPAWN_ENABLED=true and cmd[0] in BRIDGE_SPAWN_ALLOWLIST.
  // cmd[0] is a fixed-purpose wrapper (e.g. sync-board.sh runs the board sync) —
  // NOT an interpreter; see the warning above.
  ReactionTarget::make(
      handler: 'spawn_detached',
      targetId: 'board:5',
      debounceSeconds: 30,
      payload: [
          'cmd' => ['/usr/local/bin/sync-board.sh', '5'],  // required: list<string>; cmd[0] must be an allowlisted ABSOLUTE path
          'log_path' => '/var/log/agent/sync-board5.log',  // optional; defaults to <state_dir>/spawn-<sanitized-target>.log
          'env' => ['KANBAN_BOARD_ID' => '5'],             // optional: merged over inherited env
          'cwd' => '/home/agent/scripts',                  // optional
      ],
  );
  ```

- **`channel_push`** — POSTs the intent payload to a localhost channel endpoint (Claude Code channel MCP server). Closes the agent-idle gap by delivering events as `<channel>` tags within seconds instead of waiting for the next `bridge:inbox` poll.

  **UDS transport (recommended for same-host setups):**

  ```php
  ReactionTarget::make(
      handler: 'channel_push',
      targetId: $intent->subjectId,
      debounceSeconds: 0,
      payload: $intent->toArray(),  // no socket/url key → falls back to agent's
                                    // configured channel.socket
  );
  ```

  With no `socket`/`url` key in the payload, the handler uses `<agent>.yml`'s `channel.socket`. **v0.12 does NOT auto-derive a socket path from `channel.name`** (the v0.11 `/run/user/<uid>/...` derivation was not ported); if `channel.socket` is unset and the payload carries neither key, the handler throws. To set transport explicitly per-target:

  ```php
  payload: [
      ...$intent->toArray(),
      'socket' => '/run/user/1000/agent-webhook-bridge-channel-kanbanboard-agent.sock',
      'method' => 'POST',           // optional: POST | PUT | PATCH; default POST
      'timeout_seconds' => 5.0,     // optional: per-request timeout; default 2
      'headers' => ['X-Source' => 'bridge'],  // optional: merged on top of defaults
      // 'body' => [...],           // optional: explicit body object skips the default envelope
  ],
  ```

  **HTTP transport (SSH-tunneled multi-host setups):**

  ```php
  payload: [
      ...$intent->toArray(),
      'url' => 'http://127.0.0.1:8788/',  // localhost-only: 127.0.0.1, localhost, or [::1]
      'headers' => ['Authorization' => 'Bearer <token>'],
  ],
  ```

  **Validation:** `socket` and `url` are mutually exclusive. Neither set AND no cfg-derived default → `HandlerException`. UDS paths must be absolute, exist, and be a real Unix socket (symlinks rejected). HTTP URLs must be `http://` with a loopback host; userinfo rejected.

  **Stripped from the default envelope** (never leak to the channel server): `url`, `socket`, `method`, `timeout_seconds`, `headers`, `body`.

  A working reference channel server is at [`examples/channel-servers/`](../examples/channel-servers/README.md) (single-file Node + `@modelcontextprotocol/sdk`).

  **`channel_push` is a live-push optimization, not a replacement for `Intent` emission.** Emit an `Intent` for every event that must reach the agent — Intents land in `inbox.jsonl` as the durable backstop. The `channel_push` `ReactionTarget` is an additional "deliver now if a session is up" hop layered on top.

#### Going event-driven — migrating off polling hooks

The earlier setup ran `bridge:inbox` from Claude Code hooks (`SessionStart` for catch-up, `PreToolUse` / `Stop` for mid-session events). `channel_push` replaces those with live delivery:

1. **Install channel server deps** (one-time, on the host running Claude Code):

       cd <bridge install>/examples/channel-servers && npm install

2. **Pick one name** matching `[a-z0-9_-]+` (e.g. `kanbanboard-agent`). This name appears in two places:

   | Where | Whose responsibility |
   | --- | --- |
   | `mcpServers.<KEY>` key in `.mcp.json` | Claude Code (matches `--dangerously-load-development-channels server:<KEY>`) |
   | `BRIDGE_CHANNEL_NAME` env in `.mcp.json` | channel server (derives its bind path + `<channel source="...">` tag) |

   The reference channel server binds `$XDG_RUNTIME_DIR/agent-webhook-bridge-channel-${BRIDGE_CHANNEL_NAME}.sock`. **The bridge does NOT compute that path** — set it explicitly in `<agent>.yml` as `channel.socket`, pointing at the same path the channel server binds. There is no `channel.name` field (it was dead and is removed); the `<channel source="...">` label comes from `BRIDGE_CHANNEL_NAME`.

3. **Add the channel block to `<agent>.yml`** — set `socket` (or `url`); they are mutually exclusive:

       channel:
         socket: /run/user/1000/agent-webhook-bridge-channel-kanbanboard-agent.sock  # must equal the channel server's bind path

4. **Drop `.mcp.json`** in the project root where you'll run `claude`. Start from [`examples/channel-servers/.mcp.json.example`](../examples/channel-servers/.mcp.json.example). Paste the name into `mcpServers.<KEY>` AND `env.BRIDGE_CHANNEL_NAME`. On systemd Linux the channel server derives its bind path from `$XDG_RUNTIME_DIR` + `BRIDGE_CHANNEL_NAME`; make the YAML `channel.socket` from step 3 equal that path.

5. **Wire your classifier.** Point `classifier.class` at `EventDrivenClassifier` for the standard inbox + push pattern:

       classifier:
         class: App\Bridge\Classifiers\EventDrivenClassifier

   Or use your own subclass (see "Extending a shipped classifier" above).

6. **Remove the polling hooks** from `~/.claude/settings.json`. Strip `PreToolUse` and `Stop` entries that run `bridge:inbox`. **Keep `SessionStart`** — that's the catch-up path for events queued in `inbox.jsonl` while no session was up.

7. **Verify:** `php artisan bridge:check`, then trigger a test event. `php artisan bridge:inspect <N>` shows the dispatch ledger including whether `channel_push` succeeded or recorded `done-with-note` (connection refused = no session up, which is normal).

8. **End-to-end smoke test:** from the dir with `.mcp.json`, start `claude --dangerously-load-development-channels server:kanbanboard-agent`, then in a separate terminal:

       SOCK="/run/user/$(id -u)/agent-webhook-bridge-channel-kanbanboard-agent.sock"
       curl -X POST --unix-socket "$SOCK" \
         -H 'Content-Type: application/json' \
         -d '{"intent":{"kind":"smoke_test","subject_id":"manual"}}' \
         http://localhost/

   Expected: `forwarded` (HTTP 202) from curl, and a `<channel source="kanbanboard-agent" ...>` tag in your Claude Code session within seconds.

> **Channels are CLI-only — there is no config auto-load.** `--dangerously-load-development-channels server:<KEY>` must be passed on **every** `claude` invocation; it cannot live in `settings.json`, `.mcp.json`, or any config file (the flag deliberately bypasses the channel allowlist, so loading a development channel requires an explicit per-session opt-in). Wrap it in a launcher so you don't retype it — see [`examples/start-channel-session.sh`](../examples/start-channel-session.sh), which also clears a stale socket and installs the channel-server deps on first run. **Live push only delivers while that session is up**; otherwise `channel_push` is best-effort (`done-with-note`) and `inbox.jsonl` is the backstop.

> **uid caveat.** The bridge pushes to exactly the `channel.socket` you configure — it never derives a path. The channel server computes its bind path from `$XDG_RUNTIME_DIR` (the interactive user's `/run/user/<uid>`), while the bridge runs as the PHP-FPM worker user. Setting `channel.socket` explicitly to the server's bind path makes the two agree regardless of uid.

### Writing a custom handler

```php
<?php

namespace App\Bridge\Handlers;

use App\Bridge\Contracts\Handler;
use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Support\AgentConfig;

final class SyncBoardHandler implements Handler
{
    public function handle(ReactionTarget $target, AgentConfig $agent): void
    {
        $boardId = $target->payload['board_id'] ?? null;
        // ... your sync logic here ...
        // $agent->agentName disambiguates if multiple agents share a host.
        // Throw any exception to record a done-with-note and continue.
    }
}
```

**Registering it** — in a service provider:

```php
<?php
// app/Providers/AppServiceProvider.php  (or a dedicated provider)

use App\Bridge\Handlers\SyncBoardHandler;
use App\Bridge\Support\HandlerRegistry;

public function register(): void
{
    // HandlerRegistry is a container singleton (bound by BridgeServiceProvider),
    // so this callback fires when the dispatcher first resolves it and registers
    // onto the exact instance the dispatch loop uses.
    $this->app->afterResolving(
        HandlerRegistry::class,
        fn (HandlerRegistry $registry) => $registry->register('sync_board', new SyncBoardHandler),
    );
}
```

Provider order does not matter — `afterResolving` is a resolution-time hook registered at boot, well before the first webhook resolves the registry. If your handler needs constructor dependencies, resolve them inside the callback (`new SyncBoardHandler($app->make(...))`, where `$app` is the second argument the callback receives).

### Handler discipline

- **Handlers run synchronously in the webhook request.** If your handler makes an external HTTP call or shells out to a command taking more than ~1 second, use `spawn_detached` instead (enable it + allowlist the program first — see above) — it absorbs detachment (`setsid -f`) / log redirection / env-merge in one place, shell-free.
- **Failures are recorded, not retried automatically.** A handler throw writes `done-with-note` to `agent_dispatches`. The intent is already durable in `inbox.jsonl`. To retry: `php artisan bridge:replay <N>`. Treatment C: one agent's handler failing does not fail the delivery or affect other agents.
- **Same-event coalescing is handled by the dispatcher, not the handler.** `ReactionTarget::$debounceKey` (defaults to `targetId`) collapses targets sharing a key within one `ClassifyResult` (last-wins) so the handler fires once. `$debounceSeconds` is **advisory metadata only** — the synchronous bridge carries it to the handler/handler-log but does NOT enforce a cross-delivery time window (no drain pass; redelivery dedup is the `webhook_events` UNIQUE gate, see DL-003).
- **`$agent` is for context, not mutation.** `AgentConfig` is the parsed YAML for the agent being dispatched. Read from it; do not write or cache mutable state on it.

### Testing your handler

```php
<?php

namespace Tests\Feature\Handlers;

use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Handlers\SyncBoardHandler;
use App\Bridge\Support\AgentConfig;
use Tests\TestCase;

class SyncBoardHandlerTest extends TestCase
{
    public function test_syncs_the_board_when_dispatched(): void
    {
        $target = ReactionTarget::make(
            handler: 'sync_board',
            targetId: '5',
            payload: ['board_id' => 5],
        );

        // AgentConfig::fromArray needs the minimum required sections; build it
        // inline (there is no global config-fixture helper). The agent name is
        // the first arg — there is no identity.self; ids live in identity.
        $agent = AgentConfig::fromArray('test-agent', [
            'identity' => ['kanban_user_id' => 137],
            'subscriptions' => [['provider' => 'kanban', 'scopes' => [5]]],
        ]);

        (new SyncBoardHandler)->handle($target, $agent);

        // Assert your handler's side effects: state files written, HTTP calls made, etc.
    }
}
```

---

## Reference points

- Classifier contract: [`app/Bridge/Contracts/Classifier.php`](../app/Bridge/Contracts/Classifier.php)
- Handler contract: [`app/Bridge/Contracts/Handler.php`](../app/Bridge/Contracts/Handler.php)
- Reference classifier: [`app/Bridge/Classifiers/InboxOnlyClassifier.php`](../app/Bridge/Classifiers/InboxOnlyClassifier.php)
- Event-driven subclass: [`app/Bridge/Classifiers/EventDrivenClassifier.php`](../app/Bridge/Classifiers/EventDrivenClassifier.php)
- Shipped handlers: [`app/Bridge/Handlers/`](../app/Bridge/Handlers/)
- Handler registry: [`app/Bridge/Support/HandlerRegistry.php`](../app/Bridge/Support/HandlerRegistry.php)
- Classifier resolver: [`app/Bridge/Support/ClassifierResolver.php`](../app/Bridge/Support/ClassifierResolver.php)
- Data shapes: [`app/Bridge/Dispatch/`](../app/Bridge/Dispatch/) (`Intent`, `ReactionTarget`, `Actor`, `ClassifyResult`)
- Three-way failure treatment: [`CLAUDE_ARCHITECTURE.md`](../CLAUDE_ARCHITECTURE.md) § The three-way failure treatment
- Consumer-side contract: [`consumer-guide.md`](consumer-guide.md)
- Adding a new upstream provider: [`provider-adapters.md`](provider-adapters.md)
