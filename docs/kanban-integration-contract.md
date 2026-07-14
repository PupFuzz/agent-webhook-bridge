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
| **`task.created` `card` snapshot (event-carried state)** | `task.created` carries a **bounded** `payload.card` snapshot of the new card's classification-relevant state: `{ tags: string[], card_type_id: int\|null, card_type: string\|null (the type's `external_id`), workflow_stage_id: int, external_references: [{ system, source, ref }] }`. `external_references[].system` ∈ {`dl`, `github_pr`} — the **same cross-system enum** as the by-ref API (kanban DL-147/148); `source` is the canonicalized repo (`owner/repo`, lowercased) or `null`. It is a **scoped snapshot, not the full card** — a consumer needing more must still `GET /tasks/{id}`. Lets a classifier read triaged/ref state at classify time with **no callback + no read token** (`KanbanTriageClassifier`/DL-168). **Absent on a pre-v0.22.0 kanban ⇒ consumers must degrade safely** (e.g. read as untriaged ⇒ over-wake, never a miss). | kanban DL-164 / `CardEventSnapshot`; bridge DL-168 |

## 2. Outbound — bridge → kanban (v3 REST API)

Auth: **Sanctum bearer token**. The card-move writeback uses a **dedicated least-privilege token** (DL-019), distinct from any provisioning token.

| Endpoint | The bridge uses it to | Load-bearing assumption |
| --- | --- | --- |
| `GET /api/v3/boards/{b}/tasks/by-ref.json?system=&ref=` | **Correlate a PR → card(s)** in `ref` mode (DL-029, the default-soon path) | `system` ∈ {`dl`, `github_pr`} — a **hard cross-system enum** (kanban DL-147/148): `KanbanClient` sends `system=dl`/`system=github_pr` and `byRefAvailable` probes `system=dl`, so a rename/addition on the kanban side is drift that breaks correlation silently. Server-canonicalizes the ref; returns a **collection** `{data:[…]}` (one-to-many — kanban DL-148); indexed/O(1). Requires kanban v0.17.2+ with `task_external_references` backfilled. |
| `GET /api/v3/tasks/search.json?q=board_id=N&limit=200&page=P` | Correlate in `scan` mode (fallback) + the `bridge:check` `limit=1` visibility probe | Page-based (`?page`); returns the DL-146 `{data, meta, links}` envelope (`meta.total` powers the probe). Scan walks pages to `MAX_PAGES`. |
| `GET /api/v3/tasks/search.json?q=board_id=N tags:"<tag>"` | **Correlate a coord issue → card(s)** by tag (DL-198 coord-card adoption key) | The `q` free-text search supports an **exact `tags:"<tag>"` term** (`TasksController`): `KanbanClient::cardsByTag` sends `q=board_id=N tags:"id:<sid>"` for the coord-card idempotency + post-create collapse. Board-scoped by the `board_id=` clause (the `id:<sid>` tag is globally unique, so no `source` qualifier). Shares the DL-026 blind-token degraded-read caveat (a blind/wrong-board token returns `{data:[]}` → reads "no card"); `bridge:check`'s visibility probe catches that at preflight, and the reconcile's orphan-adoption keys on the same tag so any duplicate collapses. |
| `GET /api/v3/tasks/{id}.json` | Read a card's `board_id`, `workflow_stage_id`, `block_reason`, `tags` | Belongs-to-mapped-board guard + idempotent already-in-stage check; on a `started` move also the pinned-card opt-out (DL-178) — a non-empty `block_reason` or a `no-automove` tag refuses the promotion. **Cross-system dependency:** kanban `TaskResource` must keep both fields at the top level of `data` (`block_reason` nullable string, `tags` a string array) or the opt-out silently stops firing. |
| `POST /api/v3/tasks.json` | Create a card (dependabot + coord-card paths) | Body `{board_id, workflow_stage_id, name, payload, tags, swimlane_id?, description?, priority?, external_id?, external_link?}`. **Unknown `payload` keys 422** — `payload` keys must be registered custom fields on the board; the trailing `description`/`priority`/`external_id`/`external_link` are **top-level Task fillable fields** (not custom fields), each omitted from the POST when null so the dependabot caller is byte-identical (DL-198). `external_id` is an **integer**. (DL-024 / DL-027 / DL-198) |
| `PATCH /api/v3/tasks/{id}.json` | Move a card | Body `{task:{workflow_stage_id}}` **only** — column-only, never touches `payload`/lane, so a human re-laning survives (DL-020). (`_action: delete\|archive\|undelete\|unarchive` exists but the bridge uses move only.) |
| `GET /api/v3/boards/{id}/preload.json` | Read a board's swimlanes (`bridge:check` lane validation) | Returns `data.swimlanes` as `[{id, …}]`; lightweight (no tasks) — DL-027 |

**Correlation keys (how a PR finds its card):**
- `payload.dl_number` — a **registered numeric custom field**; correlates a `DL-NNN`-tagged PR to its card (DL-021). The bridge normalizes `DL-42` / `42` / `042` to the same numeric value.
- `payload.pr_number` — the **dependabot idempotency key** (DL-024).
- **Both must be registered custom fields on the tracking board** for the dependabot create path (`POST` 422s on unknown payload keys). For *correlation*, kanban derives them into the first-class `task_external_references` table and the bridge looks them up via `by-ref` (`ref` mode, DL-029/kanban DL-147/148) — server-canonicalized, indexed, returns all matching cards. `scan` mode (fallback) still reads the board and digit-matches client-side. The digit-normalization (`DL-42`/`42`/`042`) now lives once, server-side, in kanban's `ExternalReferenceNormalizer`.

### Reading a board's full card list — use `preload` + paged `search`, not the board GET

`GET /api/v3/boards/{id}.json` returns the board **with all its non-archived tasks**, and that list is **complete** — kanban does **not** cap or silently truncate it (`BoardsController::show` loads tasks with no `limit()`; `BoardResource` renders the whole collection). But it eager-loads every task plus its subtasks and attachments, so it is **heavy at scale** and is the wrong tool for a full-board sync. Any consumer enumerating a board's cards (the bridge's `scan` mode, **and any external sync client**) should instead read:

1. **Structure** from `GET /api/v3/boards/{id}/preload.json` — workflows / stages / swimlanes / card-types, **tasks excluded by design** (kanban DL-040). Bounded and cheap.
2. **Cards** from `GET /api/v3/tasks/search.json?q=board_id=N&limit=200&page=P`, paging the `{data, meta, links}` envelope (kanban DL-146) **until `links.next === null`**. `limit` caps at the server max (200).

Rules — the "degraded states must be loud" posture (same as the bridge's blind-token guard, DL-026):
- **A non-200 on any page must raise, never return a partial list.** A truncated read must never silently look like a shorter board: on an apply/reconcile pass an invisible-but-still-existing card reads as "missing" → a destructive create / duplicate.
- Include a **runaway page guard** (refuse past N pages).
- Stop on **`links.next === null`** (authoritative). A short/empty last page is a weaker heuristic that costs one extra request when the total is an exact multiple of `limit`.

> **Don't read structure from the full board GET and then re-page the cards** — that loads the entire (heavy) task set once and discards it. Use `preload` for structure; it's purpose-built (no tasks). The board GET's `tasks[]` is complete today but unbounded: a large board makes it slow/memory-heavy and it can fail *loudly* (5xx/timeout), but it never returns fewer valid tasks with a 200. `preload` + paged `search` is the bounded, scale-safe read every growing board should rely on.

## 3. Load-bearing invariants (break these → break the bridge)

| Invariant | Owner | What breaks if it changes | Tracked |
| --- | --- | --- | --- |
| Task-search returns a `{data, meta, links}` envelope (`meta.total`, `links.next`); page-based | kanban | The `bridge:check` visibility probe reads `meta.total` (a pre-DL-146 kanban without it → row-count fallback). Scan mode still pages. | kanban DL-146 (shipped, v0.17.0) |
| Correlation `by-ref` returns ALL cards for a `(system, ref)` — one-to-many | kanban | Writeback must move EVERY matched card (a bundled PR tracks several); moving only the first under-delivers | kanban DL-148 / bridge DL-029 |
| `payload.dl_number` / `pr_number` are **registered queryable custom fields** | kanban (board config) + bridge | Correlation can't find cards; `POST` 422s | bridge **#2160** (targeted query), **#2162** (orphaned-mapping guard) |
| Move = **`workflow_stage_id`-only** PATCH | bridge | A move that touched lane/payload would clobber human edits / break idempotency | DL-020 / DL-027 |
| HMAC over **raw body**; envelope `board_id` (or GitHub `repository.full_name`) must match the `?b=` scope | kanban + bridge | `401 scope_mismatch` | G-018 |
| kanban **retries** failed deliveries | kanban | The bridge's *only* delivery guarantee evaporates → silent intent loss | — |
| Writeback `identity_id` is echo-suppressed | bridge | The writeback's own `card_updated` loops back | DL-018 |
| `task.created` carries a `payload.card` snapshot (`tags`, `external_references[{system,source,ref}]`, `card_type`, `workflow_stage_id`) | kanban | Classify-time triage filtering (DL-168) loses its no-token state read → must degrade to over-wake, never silently miss | kanban DL-164 / bridge DL-168 |

## 4. The GitHub provider (writeback trigger)

The card-move writeback is **triggered by GitHub PR webhooks**, not kanban events — a second inbound provider. `GitHubAdapter` (HMAC; scope = `repository.full_name`, must match `?b=`); `GitHubPrCardMoveClassifier` derives the outcome from GitHub-controlled fields and emits `kanban_move_card` / `kanban_dependabot_card`. The writeback *policy* (`writeback.json`) and the *trigger* (a github-subscribed agent running that classifier) are validated independently — a mapping with no driving classifier is silently inert (bridge **#2162**). Full setup: [`writeback.md`](writeback.md).

## 5. Change protocol at the seam

- **Prefer additive, backward-compatible changes.** Example done right: kanban FR #2161 adds `meta`/`links` *alongside* the unchanged `data` array — no consumer breaks.
- **When you change a §2/§3 row, update this doc in the same change** and check the downstream side. A kanban API change isn't "done" until the bridge's assumption here is re-verified.
- **Seam evolution:** #2161 (kanban pagination `meta`/`links`, DL-146) ✅; the correlation primitive — kanban `task_external_references` + `by-ref` (DL-147/148) ✅ and the bridge cutover (DL-029) ✅; #2162 (bridge: orphaned-writeback-mapping guard) — remaining.
- **Authoritative sources:** kanban Scribe `/docs` (live API shapes) · bridge `KanbanClient` / `KanbanAdapter` / `GitHubAdapter` · `docs/writeback.md` · kanban `WebhookEvents` (event vocabulary).
