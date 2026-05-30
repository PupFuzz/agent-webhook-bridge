# Customization

## Two extension points

| Extension | What it does | Loaded via | Default |
|---|---|---|---|
| **Classifier** | Maps webhook events to `Intent` (inbox) and/or `ReactionTarget` (handler) instances. | `classifier.class` in agent YAML (FQCN). | `App\Bridge\Classifiers\InboxOnlyClassifier` |
| **Handler** | Implements `Handler` contract; dispatched by name synchronously in the same request. | `afterResolving(HandlerRegistry::class, fn ($r) => $r->register(name, instance))` in a `ServiceProvider`. | Four ship: `log_intent`, `registry_append`, `spawn_detached`, `channel_push`. |

The Python-era surface formatter (a callable swapped into `bin/inbox`) does not exist in v0.12. `bridge:inbox` ships one built-in Markdown renderer; the output format is not operator-swappable. To reshape output, post-process `bridge:inbox` stdout or read `inbox.jsonl` directly (see [`consumer-guide.md`](consumer-guide.md)).

Reference implementations (read these first — they're short):

- `app/Bridge/Classifiers/InboxOnlyClassifier.php` — canonical classifier (~160 LOC)
- `app/Bridge/Classifiers/EventDrivenClassifier.php` — event-driven subclass
- `app/Bridge/Handlers/` — four shipped handlers
- `app/Bridge/Contracts/Classifier.php` + `Handler.php` — contracts your class must implement

---

## Writing a classifier

Implement `App\Bridge\Contracts\Classifier`:

```php
public function classify(
    string $eventType,
    array $payload,
    Actor $actor,
    string $provider,
    string $scopeId,
): ClassifyResult;
```

**Inputs:**

- `$eventType` — wire-format event name (`"task.created"`, `"comment.created"`, etc.).
- `$payload` — parsed envelope as a plain PHP array. The kanban adapter normalizes to `['event', 'board_id', 'subject_id', 'subject_type', 'action', 'payload', 'user_id', 'timestamp', 'delivery_id', 'attempt', ...]`. The nested `payload['payload']` is the event-specific data. See [`provider-adapters.md`](provider-adapters.md) for other providers' shapes.
- `$actor` — `Actor` resolved against `agents.json`. `$actor->name` is the friendly name (null if unknown); `$actor->isKnownAgent` is true only for registry entries; `$actor->id` is the raw provider id; `$actor->rawEnvelope` holds raw actor fields from the adapter. **Echo suppression has already happened** — do not re-filter inside `classify`.
- `$provider` — upstream system id (`"kanban"`, `"github"`, etc.). Pass through to every `Intent` you construct.
- `$scopeId` — receiver-extracted scope id (kanban `board_id` stringified, GitHub `repository.full_name`, etc.).

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
use App\Bridge\Dispatch\Actor;
use App\Bridge\Dispatch\ClassifyResult;
use App\Bridge\Dispatch\Intent;
use App\Bridge\Dispatch\ReactionTarget;

final class MyClassifier implements Classifier
{
    public function classify(
        string $eventType,
        array $payload,
        Actor $actor,
        string $provider,
        string $scopeId,
    ): ClassifyResult {
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

### Extending a shipped classifier (subclass pattern)

For agents wanting `InboxOnlyClassifier` behavior plus extra targets, subclass rather than copy. This is exactly what `EventDrivenClassifier` does:

```php
<?php

namespace App\Bridge\Classifiers;

use App\Bridge\Dispatch\Actor;
use App\Bridge\Dispatch\ClassifyResult;
use App\Bridge\Dispatch\Intent;
use App\Bridge\Dispatch\ReactionTarget;

final class MyEventDrivenClassifier extends InboxOnlyClassifier
{
    public function classify(
        string $eventType,
        array $payload,
        Actor $actor,
        string $provider,
        string $scopeId,
    ): ClassifyResult {
        $result = parent::classify($eventType, $payload, $actor, $provider, $scopeId);

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

### Per-agent echo for a shared upstream identity

Echo suppression runs **before** `classify()`, matching the resolved `Actor` against the agent's `identity.self` + `treat_as_echo` (by name) and `treat_as_echo_ids` (by raw id). That works when each agent has its own upstream account. But when several agents **share one** upstream account (declared once under `shared_identities` in `agents.json` — see [`multi-agent.md`](multi-agent.md)), the registry deliberately resolves `Actor.name = null` (it can't pick one agent), so the only echo lever left is `treat_as_echo_ids` on the raw id — and that is **all-or-nothing**: it either suppresses the shared account for *every* agent (killing the whole inbox) or for none (so each agent sees its own writes echoed back). There is no per-agent middle ground pre-classify, because the true author isn't known yet.

`ClassifyResult::$reattributedActor` closes that gap. A classifier that recovers the true author from a secondary signal (a `FROM:` line in the event body, repo scope → agent mapping, etc.) returns it on the result; **after** `classify`, the dispatcher re-runs the **same** per-agent echo check on the reattributed actor and drops the event only when it is the serving agent's own write:

```php
public function classify(string $eventType, array $payload, Actor $actor, string $provider, string $scopeId): ClassifyResult
{
    // Shared account → Actor.name is null. Recover the author from your own
    // convention (here: a "FROM: <agent>" first line in a comment body).
    $reattributed = null;
    if ($actor->name === null) {
        $body = (string) ($payload['payload']['body'] ?? '');
        if (preg_match('/^FROM:\s*(\S+)/', $body, $m) === 1) {
            $reattributed = new Actor(id: $actor->id, name: $m[1], isKnownAgent: true);
        }
    }

    // Build intents/targets as normal, using $reattributed ?? $actor for display.
    $intents = [/* ... */];

    return new ClassifyResult(intents: $intents, reattributedActor: $reattributed);
}
```

- **You report *who*; the dispatcher decides *is that me?*.** The classifier doesn't know which agent it's serving (one cached instance serves all of them) and **must not filter** — it just names the author. The dispatcher applies each agent's own `identity.self` / `treat_as_echo`, so the same classifier yields per-agent self-echo across all the agents sharing the account.
- **A different shared-id agent's write still surfaces** — its recovered name isn't the serving agent's `identity.self`, so it isn't an echo for that agent.
- **Leave it `null` when you didn't recover an author** (or there's nothing to recover) — the result dispatches unchanged. Every shipped classifier leaves it null, so this is a no-op unless you opt in.

This is the completion of the `shared_identities` design (DL-002): the registry preserves the null name on purpose so this recovery layer can re-attribute. See [`multi-agent.md`](multi-agent.md) § Path C for the full shared-identity walkthrough.

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

it('emits new_card intent for task.created', function () {
    $actor = new Actor(id: '99', name: 'alice', isKnownAgent: false);

    $result = (new MyClassifier)->classify(
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
    );

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

### Default shipped handlers

- **`log_intent`** — appends the target (JSON line) to `<state_dir>/handler-log.jsonl`. Forensic log only; not read by `bridge:inbox`.

- **`registry_append`** — appends the target to `<state_dir>/registry-<sanitized_target_id>.jsonl`. Use for per-resource activity ledgers.

- **`spawn_detached`** — fires a detached child process for `payload['cmd']` (argv list). Use for slow external reactions that must not block the synchronous request. Uses `setsid` + shell backgrounding; every dynamic value is `escapeshellarg`'d.

  ```php
  ReactionTarget::make(
      handler: 'spawn_detached',
      targetId: 'board:5',
      debounceSeconds: 30,
      payload: [
          'cmd' => ['php', '/home/agent/scripts/sync.php', '--board', '5'],  // required: list<string>
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

2. **Pick one name** matching `[a-z0-9_-]+` (e.g. `kanbanboard-agent`). This name appears in three places:

   | Where | Whose responsibility |
   | --- | --- |
   | `<agent>.yml` `channel.name` field | bridge YAML (the `<channel source="...">` label) |
   | `mcpServers.<KEY>` key in `.mcp.json` | Claude Code (matches `--dangerously-load-development-channels server:<KEY>`) |
   | `BRIDGE_CHANNEL_NAME` env in `.mcp.json` | channel server (derives its bind path + `<channel source="...">` tag) |

   The reference channel server binds `$XDG_RUNTIME_DIR/agent-webhook-bridge-channel-${BRIDGE_CHANNEL_NAME}.sock`. **The bridge does NOT compute that path in v0.12** — set it explicitly in `<agent>.yml` as `channel.socket`, pointing at the same path the channel server binds. `channel.name` is only the source label.

   **If `channel.name` is omitted** in YAML it defaults to `identity.self` (label only).

3. **Add the channel block to `<agent>.yml`** — `socket` is REQUIRED:

       channel:
         name: kanbanboard-agent                                              # source label
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

- **Handlers run synchronously in the webhook request.** If your handler makes an external HTTP call or shells out to a command taking more than ~1 second, use `spawn_detached` instead — it absorbs `setsid` / log-rotation / env-merge in one place.
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
        // inline (there is no global config-fixture helper).
        $agent = AgentConfig::fromArray('test-agent', [
            'identity' => ['self' => 'test-agent'],
            'api' => ['kanban' => [
                'base_url' => 'https://kanban.example.com/api/v3',
                'token_path' => '/tmp/token',
            ]],
            'receiver' => ['base_url' => 'https://bridge.example.com/webhooks'],
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
