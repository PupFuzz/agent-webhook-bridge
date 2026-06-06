# Kanban-board ↔ bridge integration contract

The bridge is a **consumer** of the kanban-board app: it receives kanban's webhooks and writes back to kanban's REST API. The two are separate projects with separate release cadences, so the **seam between them is the load-bearing surface** — a change on either side that silently breaks an assumption here breaks the integration in production, usually quietly.

This doc pins that seam down: the kanban surface the bridge depends on, and the invariants that must hold for it to work. It is **not** a re-spec of the kanban API (the live Scribe docs at kanban's `/docs` are authoritative for shapes) — it records *what the bridge relies on* and *what breaks if it changes*. **Read this before changing either side of the boundary.**

> Sibling projects, one seam. If you're touching kanban's task-search, custom fields, webhook delivery, or `/api/v3/tasks*` — or the bridge's `KanbanAdapter` / `KanbanClient` / `GitHubAdapter` — this contract is the thing that drift here invalidates.

---

## 1. Inbound — kanban → bridge (webhooks)

kanban POSTs events to the bridge receiver; the bridge classifies → stages → dispatches synchronously.

| Aspect | Contract | Source |
| --- | --- | --- |
| **Transport / auth** | `POST` to the receiver; **HMAC-SHA256 over the RAW request body**, per-`(provider, scope)` secret. A tampered/mis-signed body is rejected at the gate. | `KanbanAdapter::signatureHeader`, `VerifyHmacSignature` |
| **Envelope** | `{ "event", "board_id", "delivery_id", "user_id"?, "payload" }`. Mapping: `board_id → scope_id`, `event → event_type`, `user_id → actor_id` (**null = system event**). **No ping event.** | `KanbanAdapter::parse` |
| **Dedup gate** | `delivery_id` is the at-least-once dedup key (`UNIQUE(delivery_id)` on `webhook_events`). | bridge migrations |
| **Delivery guarantee** | **At-least-once, borrowed from kanban's webhook retry.** The bridge has no queue: any internal failure → `5xx` → kanban **re-delivers**. The bridge's correctness depends on kanban actually retrying. Do not assume exactly-once. | kanban webhook-retry config (cite the live curve — do **not** hard-code a number here; verify against kanban source per [`feedback-verify-borrowed-guarantees`]) |
| **Actor identity** | Keys on the **immutable kanban `user_id`**, never a renameable display handle. | DL-002 / DL-013 |
| **Event vocabulary** | The bridge may receive any kanban changelog event. Families: `task.*` (created/updated/moved), card lifecycle (delete/archive/restore), `timer.*`, `comment.*`, `subtask.*`, `workflow.*`, `lane.*`, `swimlane.*`, `card_type.*`, `custom_field.*`, `board.imported`, `card_updated`. **Authoritative list = kanban's `WebhookEvents`** (don't hard-code an enumeration here — it drifts). Subscribers key on `event_type`; some events carry an `imported: true` flag (key on the flag, not the event name) — DL-114. |

## 2. Outbound — bridge → kanban (v3 REST API)

Auth: **Sanctum bearer token**. The card-move writeback uses a **dedicated least-privilege token** (DL-019), distinct from any provisioning token.

| Endpoint | The bridge uses it to | Load-bearing assumption |
| --- | --- | --- |
| `GET /api/v3/tasks/search.json?q=board_id=N&limit=200&page=P` | Read a board's cards for correlation | **Caps at 200 rows per page; page-based (`?page`, ignores `offset`); returns a bare `{data:[...]}` with NO `total`/`meta`/`links`.** This is *the* fragile assumption — see §3 + the open FRs. |
| `GET /api/v3/tasks/{id}.json` | Read a card's `board_id` + `workflow_stage_id` | Belongs-to-mapped-board guard + idempotent already-in-stage check |
| `POST /api/v3/tasks.json` | Create a card (dependabot path) | Body `{board_id, workflow_stage_id, name, payload, tags, swimlane_id?}`. **Unknown `payload` keys 422** — payload keys must be registered custom fields on the board. (DL-024 / DL-027) |
| `PATCH /api/v3/tasks/{id}.json` | Move a card | Body `{task:{workflow_stage_id}}` **only** — column-only, never touches `payload`/lane, so a human re-laning survives (DL-020). (`_action: delete\|archive\|undelete\|unarchive` exists but the bridge uses move only.) |
| `GET /api/v3/boards/{id}/preload.json` | Read a board's swimlanes (`bridge:check` lane validation) | Returns `data.swimlanes` as `[{id, …}]`; lightweight (no tasks) — DL-027 |

**Correlation keys (how a PR finds its card):**
- `payload.dl_number` — a **registered numeric custom field**; correlates a `DL-NNN`-tagged PR to its card (DL-021). The bridge normalizes `DL-42` / `42` / `042` to the same numeric value.
- `payload.pr_number` — the **dependabot idempotency key** (DL-024).
- **Both must be registered, queryable custom fields on the tracking board** (else `POST` 422s and they can't be queried). kanban exposes them via the search DSL `custom_field_<key>` against an indexed side table (DL-029). The bridge **currently reads the whole board and filters client-side** (paged, DL-028) rather than querying directly — #2160 proposes switching to the targeted query.

## 3. Load-bearing invariants (break these → break the bridge)

| Invariant | Owner | What breaks if it changes | Tracked |
| --- | --- | --- | --- |
| Task-search caps at 200/page, page-based, **no `total`/`has_more`** | kanban | Bridge must page and **guess** whether more exist; can't size the board | kanban FR **#2161** (add `meta`/`links`) |
| `payload.dl_number` / `pr_number` are **registered queryable custom fields** | kanban (board config) + bridge | Correlation can't find cards; `POST` 422s | bridge **#2160** (targeted query), **#2162** (orphaned-mapping guard) |
| Move = **`workflow_stage_id`-only** PATCH | bridge | A move that touched lane/payload would clobber human edits / break idempotency | DL-020 / DL-027 |
| HMAC over **raw body**; envelope `board_id` (or GitHub `repository.full_name`) must match the `?b=` scope | kanban + bridge | `401 scope_mismatch` | G-018 |
| kanban **retries** failed deliveries | kanban | The bridge's *only* delivery guarantee evaporates → silent intent loss | — |
| Writeback `identity_id` is echo-suppressed | bridge | The writeback's own `card_updated` loops back | DL-018 |

## 4. The GitHub provider (writeback trigger)

The card-move writeback is **triggered by GitHub PR webhooks**, not kanban events — a second inbound provider. `GitHubAdapter` (HMAC; scope = `repository.full_name`, must match `?b=`); `GitHubPrCardMoveClassifier` derives the outcome from GitHub-controlled fields and emits `kanban_move_card` / `kanban_dependabot_card`. The writeback *policy* (`writeback.json`) and the *trigger* (a github-subscribed agent running that classifier) are validated independently — a mapping with no driving classifier is silently inert (bridge **#2162**). Full setup: [`writeback.md`](writeback.md).

## 5. Change protocol at the seam

- **Prefer additive, backward-compatible changes.** Example done right: kanban FR #2161 adds `meta`/`links` *alongside* the unchanged `data` array — no consumer breaks.
- **When you change a §2/§3 row, update this doc in the same change** and check the downstream side. A kanban API change isn't "done" until the bridge's assumption here is re-verified.
- **Open seam FRs:** #2160 (bridge: targeted indexed correlation query), #2161 (kanban: pagination metadata — the upstream root that simplifies the bridge), #2162 (bridge: orphaned-writeback-mapping guard). These are the live evolution of this contract.
- **Authoritative sources:** kanban Scribe `/docs` (live API shapes) · bridge `KanbanClient` / `KanbanAdapter` / `GitHubAdapter` · `docs/writeback.md` · kanban `WebhookEvents` (event vocabulary).
