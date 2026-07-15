# Architecture

> AI-audience fast-orientation map of the bridge's structure. The bridge is a **single Laravel 13 app**: a webhook arrives, and receive → classify → stage → dispatch all happen **synchronously in that one request**. There is no queue worker, no consumer cron, no daemon. For *why* the v0.1–v0.11 5-layer async pipeline was collapsed into this, see [`CLAUDE_DECISIONS.md`](CLAUDE_DECISIONS.md) DL-001.

## The synchronous request lifecycle

```
Upstream system (kanban-board, GitHub, ...)
   │  POST /webhooks/<provider>?b=<scope>   (HMAC-signed body)
   ▼
 routes/webhooks.php
   ├─ EnvelopeSizeLimit            reject oversized bodies (413)
   ├─ VerifyHmacSignature          constant-time HMAC over the RAW body; loads the
   │                               per-(provider,scope) secret; sets bridge.{provider,scope_id,body}
   ▼
 WebhookController::receive
   ├─ WebhookAdapterFactory::for(provider)->parse()   envelope → EventDto  (malformed → 400)
   ├─ isPing? → 200 "pong"
   ├─ payload-scope == URL-scope?  (else 401 scope_mismatch)
   ▼
 DispatchService::dispatch(provider, scopeId, event, payload)      ← the synchronous core
   ├─ record   dedupCreate(webhook_events)   UNIQUE(delivery_id) dedup + audit/replay store
   ├─ for each subscribed agent on this (provider, scope):
   │    ├─ build Actor via AgentRegistry → EchoSuppression: is this the agent's own write? skip
   │    │      (github writeback classifier: classify + strip to machine targets instead, DL-203)
   │    ├─ classify()   → ClassifyResult(intents, targets)        (A) throws → record + ack 200
   │    ├─ stage intents → inbox.jsonl                            (B) throws → propagate → 5xx (redelivered)
   │    └─ run each target's Handler                              (C) throws → dispatch done-with-note, continue
   └─ return
   ▼
 200 "ok"   (only after every subscribed agent is processed)
```

`webhook_events` is **not** a work-queue — nothing drains it. It is the dedup gate (`UNIQUE(delivery_id)`, so kanban-board retries land idempotently; for GitHub the key is sha256 of the SIGNED body, not the unsigned `X-GitHub-Delivery` header — DL-176, so a replayed signed body dedups too) plus the durable audit/replay store. `agent_dispatches` is the per-agent, per-event outcome ledger (one row per agent that processed an event), enabling per-agent replay + isolation.

### The three-way failure treatment (load-bearing)

The whole reliability story lives in how `DispatchService` treats the three failure points (`WebhookController` docstring + `DispatchService`):

| Where it throws | Treatment | Why |
|---|---|---|
| **(A) classify** | record the error note, leave that agent's dispatch `processed_at` **null** (errored), ack **200** | A classifier bug must not wedge delivery. The raw event is stored and the dispatch stays unfinished; fix the classifier and `bridge:replay <id>` to complete it. |
| **(B) inbox staging** | propagate → **5xx** → kanban-board redelivers | `inbox.jsonl` is the durable pull-backstop. Silently losing a staged intent is the one unacceptable outcome, so we'd rather be re-delivered. |
| **(C) handler** | mark that agent's dispatch *done-with-note*, continue | Per-agent isolation. One agent's channel server being down must not fail the delivery or the other agents. |

At-least-once is **borrowed**, not built: any uncaught/durability failure → 5xx → kanban-board's webhook retry redelivers (see [[feedback-verify-borrowed-guarantees]] — confirmed against kanban-board's retry source, ~11-day envelope). The local `inbox.jsonl` is the pull-side backstop the agent reads even if a push never reached it. There is deliberately **no** `DB::transaction` around the dispatch loop — a handler does network I/O (channel_push), and a rollback can't un-send a POST; each dispatch records its own outcome independently (see DL-001).

## Package map (`app/Bridge` + HTTP layer)

### Receiver (HTTP boundary — security-critical)

| File | Role |
|---|---|
| `routes/webhooks.php` | The `/webhooks/{provider}` route + middleware stack |
| `app/Http/Middleware/VerifyHmacSignature.php` | Loads the per-`(provider,scope)` secret, computes HMAC over the **raw** request body, constant-time compare. Stashes `bridge.{provider,scope_id,body}` request attributes. |
| `app/Http/Middleware/EnvelopeSizeLimit.php` | Rejects bodies over the configured cap before HMAC work |
| `app/Http/Controllers/Webhook/WebhookController.php` | Parse envelope → ping short-circuit → scope double-check → hand off to `DispatchService` |

> **No `TrustProxies` (deliberate, DL-016).** The app does not register Laravel's `TrustProxies` middleware, so it never trusts `X-Forwarded-*` headers. Nothing security-relevant reads them: HMAC is computed over the **raw body** (not headers), the scope comes from the URL path and is re-checked against the body, and `channel_push`'s loopback gate uses the **configured** URL, not the request host. So there is no client-IP / scheme / host decision a forwarded header could spoof — adding `TrustProxies` would only widen the trust surface for no gain.

### Adapters (per-provider envelope + signature shape)

| File | Role |
|---|---|
| `app/Bridge/Contracts/WebhookAdapter.php` | Adapter contract: `parse(Request, body) → EventDto`, `isPing(EventDto) → bool`, signature header name + scheme |
| `app/Bridge/Adapters/AbstractWebhookAdapter.php` | Shared HMAC + parse scaffolding |
| `app/Bridge/Adapters/KanbanAdapter.php` | `X-Kanban-Signature`; event_type from `event`; scope from `board_id`; actor from `user_id` |
| `app/Bridge/Adapters/GitHubAdapter.php` | `X-Hub-Signature-256`; event_type = `X-GitHub-Event` + body `action`; scope from `repository.full_name`; actor from `sender.id` (immutable numeric — usernames rename, DL-002); `ping` no-op |
| `app/Bridge/Adapters/EventDto.php` | Normalized envelope: `deliveryId`, `provider`, `scopeId`, `eventType`, `actorId`, … |
| `app/Bridge/Adapters/WebhookAdapterFactory.php` | `for(provider)` → the right adapter (unknown → `UnknownProviderException`) |

### Storage

| File | Role |
|---|---|
| `database/migrations/..._create_webhook_events_table.php` | `webhook_events`: `UNIQUE(delivery_id)` dedup gate + audit/replay store; indexed by `(scope_id, event_type)` + `(actor_id)` |
| `database/migrations/..._create_agent_dispatches_table.php` | `agent_dispatches`: per-agent, per-event outcome ledger (`processed_at` + `error_message`) |
| `app/Models/WebhookEvent.php` | Plain Eloquent model over `webhook_events` (the `UNIQUE(delivery_id)` constraint is the dedup gate) |
| `app/Models/AgentDispatch.php` | Plain Eloquent model for the per-agent dispatch ledger |

### Classification + dispatch (the synchronous core)

| File | Role |
|---|---|
| `app/Bridge/Dispatch/DispatchService.php` | The loop: record → per-agent (echo-suppress → classify → stage → coalesce targets by `debounceKey` (last-wins) → run handlers) with the three-way failure treatment. Its private `dedupCreate` (create + catch `UniqueConstraintViolationException` → refetch) is the at-least-once write primitive (used for both `webhook_events` and `agent_dispatches`) |
| `app/Bridge/Dispatch/{Intent,ReactionTarget,Actor,ClassifyResult,IntentLog}.php` | Core data shapes (plain PHP objects/arrays — no freeze/thaw, no serialization layer) |
| `app/Bridge/Contracts/Classifier.php` | Classifier contract: `classify(string $eventType, array $payload, Actor $actor, string $provider, string $scopeId): ClassifyResult` |
| `app/Bridge/Classifiers/InboxOnlyClassifier.php` | Reference classifier — surfaces lifecycle/activity events as intents; no dispatched targets |
| `app/Bridge/Classifiers/EventDrivenClassifier.php` | Reference event-driven classifier — emits `channel_push` targets paired with intents |
| `app/Bridge/Classifiers/GitHubPrCardMoveClassifier.php` | Correlation classifier for the writeback — a `pull_request` event → a `kanban_move_card` target (FR #2016, `docs/writeback.md`) |
| `app/Bridge/Contracts/Handler.php` + `app/Bridge/Handlers/*` | Reaction handlers: `LogIntentHandler`, `ChannelPushHandler`, `RegistryAppendHandler`, `KanbanMoveCardHandler` (the durable card-move writeback), `KanbanDependabotCardHandler` (DL-024), `KanbanBlockReasonHandler` (DL-193), `KanbanCoordCardHandler` (coord issue → card create, DL-198), `KanbanCoordCardMoveHandler` (coord issue close→terminal / reopen→revive, DL-200), `SpawnDetachedHandler` (opt-in) |
| `app/Bridge/Contracts/DurableReaction.php` + `app/Bridge/Writeback/*` | The writeback: `DurableReaction` marker, `WritebackConfig`/`WritebackMapping` (`writeback.json` policy), `KanbanClient` + `WritebackClientFactory` (the card-move write, DL-009/018-021) |
| `app/Bridge/Support/HandlerRegistry.php` / `ClassifierResolver.php` | Resolve the agent-configured handler/classifier names → instances |

### Config, identity, secrets

| File | Role |
|---|---|
| `app/Bridge/Support/SubscriptionRegistry.php` | Loads every per-agent YAML in the config dir; **fail-closed** (malformed YAML throws → 5xx, never silently skips an agent) |
| `app/Bridge/Support/{AgentConfig,IdentityConfig,SubscriptionConfig,ChannelConfig,EchoSuppressionConfig}.php` | Parsed per-agent config shapes (`identity`, `subscriptions`, optional `echo_suppression`/`channel`/`classifier`/`surface`/`api.<provider>.token_path`) |
| `app/Bridge/Support/{AgentRegistry,RegisteredAgent,SharedIdentity}.php` | Cross-agent discovery substrate — built by scanning the per-agent YAMLs' `identity` blocks plus an optional `shared-identities.json` (there is **no** `agents.json`, removed in DL-007): resolve an immutable `kanban_user_id` / `github_user_id` → friendly agent name (provider-aware). Shared accounts declared once under `shared_identities`; `github_login` is a display-only label with stale-login drift warning (DL-002) |
| `app/Bridge/Support/EchoSuppression.php` + `EchoSuppressionConfig.php` + `SignalAllowlist.php` | Predicate-based echo suppression (skip the agent's own writes); signal-allowlist for explicit treat-as-signal |
| `app/Bridge/Support/SecretPath.php` | The single shared secret-path shape: `<secret_dir>/<provider>/webhook-secret-scope-<scope>` |
| `app/Bridge/Support/InstallGuard.php` | Dev/prod crosstalk guard (`BRIDGE_INSTALL_SUFFIX` ↔ DB-name marker) |
| `app/Bridge/Support/{BridgePaths,PathHelper}.php` | Resolve config dir / secret dir / state dir from `config/bridge.php` |
| `app/Bridge/Validation/{ProviderName,ScopeId,SocketPath}.php` | Format validators reused across config + provisioning |
| `config/bridge.php` | Runtime config: `config_dir`, `secret_dir`, `install_suffix`, `max_body_bytes` (envelope cap). The state dir (inbox.jsonl + inbox-seen.json) is derived as `<config_dir>/state` by `BridgePaths`, not a separate key |

### Provisioning + ops CLIs (`php artisan bridge:*`)

| Command | Role |
|---|---|
| `bridge:provision` (`ProvisionCommand` + `app/Bridge/Provision/*`) | Idempotent `(provider, scope)` subscription create on kanban-board + per-scope HMAC secret write; `--reconcile` fixes inactive/filter drift (delete + recreate reusing the secret); URL-drift orphan cleanup is manual (no local registry — the live API is truth) |
| `bridge:check` (`CheckCommand`) | Validate the install: config dir, DB connectivity, agent YAMLs parse, install-guard |
| `bridge:inbox` (`InboxCommand`) | Read staged `inbox.jsonl`, cursor-dedup, format, write to stdout (Claude Code hook-aware envelope); silent-when-empty |
| `bridge:inspect` (`InspectCommand`) | Pretty-print one `webhook_events` row + its `agent_dispatches` ledger |
| `bridge:replay` (`ReplayCommand`) | Re-run dispatch for a stored event (recovery for errored/missed dispatches) |
| `bridge:stats` (`StatsCommand`) | Event / dispatch counts |

## Multi-agent mental model

- One bridge **codebase** (this repo); each agent runs its **own install** (own webroot, own `.env`, own DB) — per-agent, never shared runtime state.
- The per-agent YAMLs in one config dir are all loaded by `SubscriptionRegistry`; a single webhook for `(provider, scope)` fans out to **every** agent subscribed to that scope (the dispatch loop iterates them), each with independent classify/stage/dispatch + its own `agent_dispatches` row. One agent failing (treatment C) doesn't affect the others.
- The agent registry is the discovery substrate — built by scanning each `<agent>.yml`'s `identity` block (plus an optional `shared-identities.json`; there is no `agents.json`, removed in DL-007) — maps an immutable `kanban_user_id` / `github_user_id` → friendly name so intents read "edited by prod-agent" not a raw id. Matching is provider-aware (a kanban and a github id that are the same integer never cross-match). A GitHub account shared by multiple agents is declared once under `shared_identities` and resolves to a null name on purpose (custom classifier re-attributes); `github_login` is a display-only label, never a matching key (DL-002). Collision-safe: an accidental duplicate id resolves to the raw id rather than a confidently-wrong name.
- Echo suppression is **predicate-based**: default skips events whose actor is the agent itself (its YAML filename / its own `identity` ids, auto-seeded — there is no `identity.self`, removed in DL-007) or appears in `treat_as_echo`.

## Multi-provider mental model

- The receiver core (middleware + controller) is provider-agnostic; everything provider-specific lives behind the `WebhookAdapter` contract.
- Adding a provider = a new `app/Bridge/Adapters/<Provider>Adapter.php` (HMAC header + envelope extraction + ping rule) registered in `WebhookAdapterFactory`, plus — only if it's API-provisionable — a provisioning client like `KanbanProvisionClient`. GitHub webhooks are configured in repo settings, so `bridge:provision` skips them by design.
- See [`docs/provider-adapters.md`](docs/provider-adapters.md) for the integration walkthrough.

## Cross-references

- **Why these specific shapes?** → [`CLAUDE_DECISIONS.md`](CLAUDE_DECISIONS.md) (DL-001 is the v0.12 architecture decision).
- **How to add a feature without breaking conventions?** → [`CLAUDE_CONVENTIONS.md`](CLAUDE_CONVENTIONS.md).
- **How to test it?** → [`CLAUDE_TESTING.md`](CLAUDE_TESTING.md).
- **How to deploy / operate an install?** → [`CLAUDE_DEPLOYMENT.md`](CLAUDE_DEPLOYMENT.md).
- **Common pitfalls?** → [`CLAUDE_GOTCHAS.md`](CLAUDE_GOTCHAS.md).
