# Two-way board tools (DL-217)

The bridge is push-only no longer. When an install enables **board tools**, an
agent gets a small, channel-identity-scoped **request/response** surface over the
same channel that already delivers wake events — so an impl seat with **no kanban
token and no toolkit** can see and capture its own board work directly.

Three tools ship today (two since DL-217; the correction tool since DL-326):

| Tool | Direction | What it does |
| --- | --- | --- |
| `board_my_cards` | read | Return YOUR own cards (your product swimlane grouped by stage, the shared cross-system swimlane when configured, and coordination cards addressed to you when the coord leg is configured). Read-proxied — the kanban token never leaves the bridge. |
| `board_create_card` | write | Create a card in YOUR OWN swimlane. The swimlane is forced from your bridge identity; you cannot target another lane. The card is born **untriaged** and surfaces to the triage pass. |
| `board_correct_card` | write | **Correct a card YOU filed** — its `name`, `description` or `tags`. Scoped to cards carrying your own bridge-stamped `created-by:<you>`, on your own board; anything else is **refused, loudly**. |

## Discovering them

If your channel server advertises tools, your MCP client lists `board_my_cards`,
`board_create_card` and `board_correct_card`, and the server's own `instructions`
string names them.
The channel server advertises on a **tri-state** (`BRIDGE_CHANNEL_TOOLS`):

- `=1` → force ON.
- `=0` or `` (empty) → OFF (explicit opt-out).
- **unset** → advertise **iff** `BRIDGE_TOOLS_ENDPOINT` is set **and** a bearer
  resolves (`BRIDGE_TOOLS_TOKEN` / `BRIDGE_TOOLS_TOKEN_FILE`, or the
  `BRIDGE_CHANNEL_TOKEN` fallback). Wire the one endpoint line and the tools come
  on for free; a bare channel agent with no tools wiring advertises nothing.

> **Not the only tool the channel server can list.** The reference channel server
> also carries a **local-exec** self-management tool, `clear_context`, on a gate
> that is **orthogonal** to `BRIDGE_CHANNEL_TOOLS` — it is advertised iff `STY` is
> set and `clear-agent.sh` is on `PATH`, and it is **never** proxied to the bridge
> (it spawns the local helper detached to clear the agent's own context). It is not
> a board tool; see the channel-server README's "Local self-management tool"
> section. The board-tool contract below is unaffected by it.

If the tools are advertised but the channel server is only half-configured
(missing `BRIDGE_TOOLS_ENDPOINT` or the bearer — reachable under the `=1`
force-on), a call returns a **structured refusal naming the missing config** — it
never silently no-ops.

## `board_my_cards`

**Arguments:**

| Arg | Required | Notes |
| --- | --- | --- |
| `include_description` | no | Boolean (default `false`). Adds `description` + `description_truncated` to **every** projected card — your own lane, the shared lane, and the coord cards alike. A non-boolean is **refused** (422) rather than coerced. See § Reading a card's scope below. |

**Returns:**

```jsonc
{
  "board_id": 10,            // the board the returned ROWS are on (null when none was read)
  "board_observed": true,
  "configured_board_id": 10, // the board this agent is configured to read
  "swimlane_id": 4,
  "cards_by_stage": {
    "Backlog":  [ { "id": 1, "name": "...", "stage": "Backlog", "tags": ["..."],
                    "dl_number": "DL-1", "pr_number": null, "updated_at": "...",
                    // the next two keys ONLY when include_description was passed:
                    "description": "...", "description_truncated": false } ],
    "In Review": [ /* ... */ ]
  },
  "shared_swimlane": { "swimlane_id": 9, "cards_by_stage": { /* ... */ } }, // when configured
  // the coord block, all four keys together, when the coord leg is configured:
  "coord_board_id": 12,             // the board the coord ROWS are on (null when none was read)
  "coord_board_observed": true,
  "configured_coord_board_id": 12,
  "coord_cards": [ /* cards on the coord board carrying one of your address_tags */ ]
}
```

⚠ **A board fault this tool cannot read past is a REFUSAL, never an empty window** —
see [§ A board-caused 4xx is a refusal, on every tool](#a-board-caused-4xx-is-a-refusal-on-every-tool-dl-339).
The whole call refuses, including when only the **coord** leg failed: a response silently
missing its coordination cards reads exactly like a board with none.

### Where these cards are (`board_id` vs `configured_board_id`)

**⚠ `board_id` is WHERE THE ROWS ARE, read off the rows themselves — not where you
are configured to read (card#7295, DL-302).** Before this it was the configured
value restated, for a row set whose own board nothing had checked, so a window of
foreign rows would have reported this board's id as fact. What a caller gets now:

- **`board_id` + `board_observed`** are the reading. `true` ⇒ every returned row
  (your lane **and** the shared lane) reported that same board. `false` ⇒ **`board_id`
  is null and the response claims no board** — it never falls back to config. Three
  things unobserve it: **an empty window** (no rows read ⇒ no board read — the common
  case, and not an error), rows **spread across more than one board**, and a row
  carrying **no readable `board_id`**. A card is always on exactly one board upstream,
  so there is no such thing as a row legitimately reporting "no board": absent, null
  and non-numeric all mean *unread*.
- **`configured_board_id`** is the scope your bridge identity is wired to — what
  `board_id` used to carry, now under a name that says what it is. It is always
  present — unconditionally, on every arm of the response, which is what lets
  `bridge:check`'s two live probes tell a current responder from one predating the rename
  (**Which spelling the probe read**, below). The two being equal is the healthy case, not
  an invariant the bridge enforces here.
- **`swimlane_id` needs no such flag**: every returned row is filtered against it and
  a non-matching row is dropped before you see it (below), so the lane a card lands
  under is verified by construction. The board axis is a report, not a filter — a row
  on another board is **reported, never dropped**, and never refused. What a foreign
  row should make the tool *do* is a separate question from what it *says*, and only
  the saying changed here.
- **The coord block carries the same pair for its own board** — `coord_board_id` /
  `coord_board_observed` / `configured_coord_board_id`. Those cards come from a
  DIFFERENT board than the top-level one, and the block used to say nothing at all
  about that; the top-level `board_id` above it does **not** describe them.
- **It costs no extra request.** Every kanban search row already carries `board_id`;
  the projection simply never read it. (The same correction on `board_create_card` —
  card#7295's sibling card#7225, a **separate** change that may not be in your build
  — has to pay a `GET` for it, because a create hands back an id and nothing else.)

### Reading a card's scope (`include_description`)

A card is a **delivery** surface, not just a tracking one — the scope written on it
is what a cold session needs to implement from. That body is off by default and
opt-in per call:

- **Default (no argument): the two keys are ABSENT**, not null — the response is
  byte-identical to what it was before the argument existed.
- **Opt in when you are STARTING a card, not when polling.** A body runs ~2 KB and
  *every* card in your lane is returned, so a large lane multiplies the response
  many times over. The bridge pays nothing extra to fetch it (the kanban search row
  already carries `description`; the projection used to discard it) — the whole cost
  is response size, and it is yours to spend deliberately.
- **A cut body is flagged, never silently short.** Each body is cut at the
  per-agent `board_tools.description_max_bytes` (default 16384) and a card that was
  cut carries `"description_truncated": true`. **Never treat a truncated body as
  the whole scope** — re-read the card on the board instead.
- **The cap is per CARD, not per response.** It bounds one pathological body; it
  does not bound the total, which is `cards in your lane × their bodies`. Lower the
  key on a large board, raise it if your scope statements are longer than 16 KB.

Read isolation is **100% bridge-enforced**. All agents on an install share one
kanban read/write user, and kanban scopes reads by that user's *board*
membership, never by swimlane — so the boundary keeping you out of another
agent's lane is your `board_tools.swimlane_id` config plus a fail-closed row
filter: every returned row is re-checked against your configured swimlane and any
non-matching row is **dropped and logged**. The upstream `swimlane_id=` search
term is efficiency + defense-in-depth, not the boundary.

## `board_create_card`

**Arguments:**

| Arg | Required | Notes |
| --- | --- | --- |
| `title` | yes | Non-empty string, **≤ 255 characters** (kanban's `name => string\|max:255`; an over-long title is **refused** (422) before any request is sent — card#8486, the same bound `board_correct_card` puts on `name`, through the same primitive). |
| `description` | no | String. |
| `tags` | no | List of strings, each **≤ 64 characters** (kanban's `tags.* => string\|max:64`; an over-long tag is **refused** (422) before any request is sent). Reserved prefixes (`created-by:`, `idem:`, `id:`, `type:`) and the bare tag `triaged` are **refused** (422), matched **case-insensitively** — `IDEM:`/`Triaged` are rejected too: whether the kanban tag search folds case is a per-driver collation fact, so the guard refuses every case variant rather than betting on the deployed collation. Every tag must also be **printable ASCII with no tag-search metacharacter** (`"`, `*`, `_`, `%`); non-ASCII or metachar tags are refused. Provenance/correlation/adoption tags are bridge-stamped, and `triaged` would defeat born-untriaged. (A non-reserved colon such as `priority:high` is fine.) |
| `idempotency_key` | no (recommended) | `[A-Za-z0-9.-]{1,64}`. Other characters are refused (they are kanban tag-search metacharacters that could correlate the wrong card). The key is **lowercased** before use, so it correlates case-insensitively (`Report` and `report` are the same key). |

**Behaviour:**

- The card is created at your configured `create_stage_id`, in your configured
  `swimlane_id` (forced — args cannot name a lane or stage), with payload `{}`.
- The bridge stamps `created-by:<you>` as the audit tag.
- **Pass an `idempotency_key`.** With one, the bridge runs the full duplicate-safe
  pattern: it correlates on `idem:<you>:<key>` *before* creating (a repeat returns
  the same **live** card, `"idempotent_hit": true`, no second card), and after
  creating it re-reads and collapses any card a concurrent call raced in. Without
  a key, a retry (including any invisible MCP-client-layer retry) can
  double-create — the duplicate is visible via `board_my_cards` and bounded, but
  the key is why it exists.
- **⚠ Re-using a key whose card was ARCHIVED is REFUSED (422), not carded again
  (DL-297).** Kanban's search is a *switch*: without an `archived` parameter it
  returns live rows only, so an archived card is invisible to the correlation
  above. The bridge therefore reads the archive side too, on the last branch
  before the create, and an archived twin **suppresses** it: an archived card is a
  deliberate retire, and un-retiring one is not this tool's to do. You get a 422
  naming the card ids to unarchive, and **no card is created**. Your options are
  to unarchive that card (the work is live again) or to pass a **new**
  `idempotency_key` (this is genuinely new work). Before DL-297 that same call
  minted a SECOND card and answered `"created": true`.
  - That is the only BOARD STATE whose answer changed. A **live** twin is
    still an idempotent hit — including a live twin sitting *beside* an archived
    one, since the live read answers first and the archive side is never
    consulted — and a key with no card on either side still creates, at the cost
    of one extra search per card actually minted.
  - If either idempotency read itself fails upstream, **no card is created** rather
    than one the bridge could not check. Fail-closed is deliberate: the alternative
    re-mints over a retire, which is the defect this closes. ⚠ Since card#8486 the
    STATUS depends on the cause — a permanent board 4xx is a **422 refusal naming the
    install fault** and only a fault that may clear is the retryable **502**; see
    [§ A board-caused 4xx is a refusal, on every tool](#a-board-caused-4xx-is-a-refusal-on-every-tool-dl-339).

**Returns:**

```jsonc
{ "created": true, "idempotent_hit": false, "card_id": 123,
  "board_id": 10, "swimlane_id": 4, "placement_observed": true,
  "configured_board_id": 10, "configured_swimlane_id": 4 }
```

**⚠ `board_id` / `swimlane_id` are WHERE THE CARD IS, read back from the card
itself — not where you are configured to write (card#7225, DL-299).** Before this
they were the config values echoed back, on both arms: the create never read its
own result (`POST /tasks.json` hands back an id, not a placement) and the
idempotency hit answered for a card resolved out of a tag *search*. The two
readings are equal until something has gone wrong, so the old answer was silently
correct exactly until you needed it. Consequences for a caller:

- A card kanban did not place where the bridge asked now reports **where it
  actually is**, not the lane the POST requested.
- `placement_observed` is the discrimination that makes the nulls readable.
  **`true`** ⇒ `board_id`/`swimlane_id` are that card's own values (and
  `swimlane_id: null` then means the card really is in no lane — the bridge tells
  a *present* null from a *missing* key, so a body that omits `swimlane_id`
  entirely is never reported as "no lane"). **`false`** ⇒ the read-back failed, or
  answered nothing usable on either axis, and **both ids are null — the response
  claims no placement**; it never falls back to the configured board/lane, which
  is the defect this closes. `created` / `idempotent_hit` / `card_id` are unaffected: the card exists
  and its id is still the answer, so a read-back failure is **not** an error
  response (losing the placement is cheaper than losing the id).
- `configured_board_id` / `configured_swimlane_id` carry the scope this agent is
  **configured to write to**, on **both** arms and whatever `placement_observed`
  says. They are what `board_id` / `swimlane_id` used to hold, under names that
  say what they are — so *"where the card is"* and *"where we were aiming"* are two
  readable values instead of one ambiguous one, and a caller whose read-back
  failed can still see its own scope (it has no other channel to it). The same
  pairing the writeback record has carried since card#7212 — observed and
  intended, both named, neither dressed as the other.
  ⛔ **`board_create_card` carries ONE flag for the pair** (`placement_observed`)
  where the matching correction on `board_my_cards` (card#7295 / DL-302, a
  SEPARATE change) carries one per axis — deliberate, not an inconsistency: this
  tool derives both axes from a single read-back of a single card, so they are
  observed or unobserved together; `board_my_cards` reads two independent row
  sets, either of which can be readable while the other is not. A flag exists per
  unit that can independently fail to be read, and the two tools differ in how
  many such units they have.
- It costs **one extra `GET /tasks/{id}.json`** per successful call, and it is the
  card the tool is about to name — after any duplicate collapse, so a survivor
  minted by another worker reports its own placement. ⚠ **That endpoint is
  kanban's FULL task aggregate, not a two-field read:** it eager-loads subtasks,
  comments, attachments, both link directions (each with the linked card's board),
  external references and the last stage-move changelog, then runs the link
  projector — all to obtain two integers. One request, but the response body is
  the real cost, and on the idempotency-hit arm the card can be old and
  comment-heavy.
- A placement that disagrees with the agent's configured board/lane is a
  `Log::warning` on the bridge; the tool still answers 200 and reports what it
  saw. What a divergence should make the tool *do* is a separate question from
  what it *reports*, and only the report changed here.

## `board_correct_card`

**Correct a card YOU filed** (DL-326, card#8378). Before this the impl seat's whole
board surface was **create + read**, so the only available response to a wrong card
was to mint a second one — and duplicates then defeat every downstream instrument
that keys on one card per subject.

**Arguments:**

| Arg | Required | Notes |
| --- | --- | --- |
| `card_id` | yes | A positive **integer** — the `id` `board_my_cards` reports. A decorated string (`"42"`) or a float is refused, never coerced: a coerced id names a different card, and this id selects the row the write lands on. |
| `name` | no | Non-empty string, **≤ 255 characters** (kanban's own `name => string\|max:255`). There is **no clear form** — a card cannot be left without a name, so a present-but-empty `name` is refused (omit it to leave it alone). |
| `description` | no | String, **trimmed**. **Present-and-empty CLEARS it**, and so does whitespace-only (see the present/absent rule below). |
| `tags` | no | List of strings — **your** tags, each **≤ 64 characters** (kanban's `tags.* => string\|max:64`). The same reserved prefixes (`created-by:`, `idem:`, `id:`, `type:`) and bare `triaged` `board_create_card` refuses are refused here too, case-insensitively, with the same printable-ASCII / no-metacharacter (`"`, `*`, `_`, `%`) charset rule. |

> ⚠ **The two length caps are a MIRROR of rules that live in the kanban repo** (`App\Support\TaskWriteRules`), held in one place here (`KanbanFieldLimits`) and stated as a mirror: they are a **diagnostic**, not the safety. The safety is kanban's own 422 — which the tool maps to a named refusal rather than the retryable 502 — so a cap that goes stale degrades the *message*, never the outcome. `board_create_card` shares both caps (one policy, both tools: `name`/`title` at 255 through `BoardCallRefusal::overLongName()`, every tag at 64 through `CallerTagPolicy`).

**One rule for every argument: a key that is PRESENT is a correction; a key that is
ABSENT leaves its field alone.** So `tags: []` means *drop my tags* (it is not the
same as omitting `tags`), and `description: ""` clears the body.

> ⛔ **`null` and `""` are ONE VALUE here, and that is a measured property of the door
> rather than a style choice.** Laravel's global `ConvertEmptyStringsToNull` (with
> `TrimStrings` ahead of it) rewrites `"description": ""` to `null` before the HTTP
> controller ever reads `args`, while the **ssh** door (`bridge:tools-call`) decodes
> the body itself and preserves it. Treating them as one value is what keeps this
> tool's contract identical on both transports. ⚠ This is why `board_create_card`'s
> rule (a present `null` reads as *absent*) is deliberately **not** copied — there,
> `null` cannot mean "clear", because a card being born has nothing to clear.

**⭐ Whose card — the scoping rule.**

You may correct **only what you minted**, and the discriminator is the tag the
bridge already stamps at create: **`created-by:<you>`**. It is caller-unforgeable
(`created-by:` is a reserved prefix on create and the guard casefolds, so no caller
can plant any case variant of another agent's stamp), and it is the **only per-seat
provenance a card carries** — kanban's `actor_type: service` covers the bridge and
every CLI writer, and `actor_id` names the shared writeback **user**, so neither can
answer *which seat filed this*.

Two independent narrowings are checked and **both** are required:

1. **Board scope, server-side.** The card is resolved with a board-scoped
   `GET /tasks/search.json?q=board_id=<your board> id=<card>` — the card#8375 /
   DL-323 primitive — because your `card_id` is caller-supplied against a kanban id
   space that is **global across every board on the instance**. The unscoped
   `GET /tasks/{id}.json` is never called.
2. **The row's own fields.** A row establishes the card only if its own `id` names
   it **and** its own `board_id` is your configured board. The endpoint drops a term
   it does not recognise and still answers 200, so the *call* never establishes the
   scope — the *rows* do.

**The LANE is deliberately not checked.** A human may re-lane a card legitimately,
and the mint stamp is what says the card is yours; a lane test would make a re-laned
card permanently uncorrectable by the seat that filed it. The response therefore
reports no lane — it reports only what was checked.

**⚠ `tags` is REPLACED WHOLESALE by kanban, so the write re-sends every tag on the card
that is somebody ELSE'S — and that set is deliberately WIDER than the set you are not
allowed to send.** Two halves, and the second is the one that is easy to get wrong:

1. **Tags you may not supply**, so you could not restore them either: `created-by:`
   (your mint stamp — dropping it locks you out of your own card), `idem:` (your
   correlation key — dropping it re-opens duplicate minting under it), `type:` and
   `triaged` (the triage pass's work).
2. **Holds you MAY supply and may not drop**: `no-automove` — the writeback's
   all-outcome pin (`PinGuard`), the tag half of the same pin whose other half
   (`block_reason`) this tool refuses to touch by name — plus **every
   `hold_marker_tags` value your install declares in `writeback.json` for your board**
   (DL-194). A human pins a card with these; a correction that dropped one would
   un-pin it, and the next `merged` event would make the terminal move the pin exists
   to prevent.

⛔ **If `writeback.json` cannot be parsed, a `tags` correction is REFUSED** rather than
falling back to "no holds declared": the bridge cannot say which tags this install
treats as a hold, and a wholesale replace under an unknown hold vocabulary is exactly
the silent deletion the preservation exists to stop. A `name`/`description` correction
is unaffected — it writes no tag list. An install with **no** `writeback.json` is a
different (and fine) answer: it declares no hold tags, and `no-automove` still holds.

`tags_written` in the response is what the PATCH **sent**, which is the only channel you
have to what was preserved.

**Returns:**

```jsonc
{ "corrected": true, "card_id": 42, "board_id": 10,
  "fields": ["name", "tags"],
  "tags_written": ["your-tag", "created-by:you", "triaged"] }
```

`board_id` is read off the row that authorized the write, so it is observed by
construction (a call that got this far proved the card is on that board) — there is
no `*_observed` flag here, because there is no state in which this tool answers with
a board it did not read. `tags_written` is present only when the call corrected tags.

**What it REFUSES, and why each is somebody else's:**

| You passed | Refusal |
| --- | --- |
| `workflow_stage_id` / `stage` / `column` / `move` | a column move is a **different authority** and is deliberately not exposed here |
| `swimlane_id` / `board_id` | your write scope is forced from your bridge identity — an argument never names a lane or a board |
| `payload` / `dl_number` / `pr_number` / `pr_url` / `issue_number` / `issue_url` / `version` / `origin` | correlation refs the **bridge writeback** stamps |
| `external_id` / `external_link` | the board-unique sync id and the by-ref correlation link — bridge-owned (the bridge does not set `external_id` even at create: a colliding id 422s) |
| `type` / `card_type_id` / `triaged` | `type:` is a reserved tag prefix and `triaged` is the triage pass's — both refused at create too |
| `block_reason` | the writeback's pinned-card opt-out (DL-193) |
| `archived` / `archived_at` / `_action` | a retire is a lifecycle act, not a field write |
| `priority` / `due_date` / `assigned_user_id` | not part of this tool's contract |
| anything else | `unknown argument …` — **nothing is silently ignored** |

⛔ **The offered set is deliberately NARROWER than `kbcard patch`'s corrective
setters** (`--type`, `--external-id`, `--origin` are refused here): **this tool never
writes a field `board_create_card` would refuse at birth.** A correction authority
wider than the create authority is a laundering route — mint a clean card, then
"correct" in the reserved `type:` key, `triaged`, or a payload key the create tool
rejects outright.

**Card-state refusals** (all 422, all writing **nothing**):

| State | Refusal |
| --- | --- |
| The card is not on your board, or carries someone else's stamp, or none | *"card N is not one of yours"* — **one message for all three**: you are never told whether a card you do not own exists. ⚠ It names a **fourth** cause too, because kanban's search FLOORS a caller to the boards its token is a member of and answers **200 with zero rows** for the rest: an unreadable board and an empty one are one answer here (DL-323's `mapped_board_unreadable_to_this_token`), so the message tells you to have the token's board membership checked if you believe you filed the card. |
| The card is yours and **ARCHIVED** | Named as the retire it is (*"unarchive it first"*) — the stamp proves the card is yours, so naming it discloses nothing, and the alternative is a guard telling you a card you demonstrably filed is not yours. The archive side is read **only when the live lookup misses**, so a successful call never pays for it. |
| The lookup answered a row that is not that card on your board | *"a BROKEN READ, not a verdict"* (DL-323 Decision 2) — report it; it is not a statement about the card. |
| `writeback.json` will not parse | The install's hold vocabulary is unknown, so a **`tags`** correction is refused (see above) — **install fault**. `name`/`description` are unaffected. |
| kanban answered **403** on the lookup | The bridge could not read your board to establish the card is yours — an **install fault**, and specifically the writeback token's **abilities** (kanban gates the v3 API per token: a GET needs `read`). ⛔ Deliberately **not** board membership: a board the token is not a member of answers 200-with-zero-rows, never 403, so it surfaces as the not-yours refusal above. |
| kanban answered **403** on the write | The card is yours but the writeback user may not write it — **install fault**, and **TWO independent gates answer 403 on this route, so both must be audited**: (a) the token's per-token **abilities** (`EnforceTokenAbilities` — a PATCH needs `write`), and (b) the writeback user's **board role**, which needs **`task.update`** — kanban authorizes a PATCH by the fields it carries, so anything other than `workflow_stage_id` alone is an `update`, not a `move` (kanban DL-204 → `TaskPolicy::update` → `BoardPermissions::TASK_UPDATE`, an independently grantable `board_custom` slot in `CUSTOM_TASK_SLOT_MAP`). ⚠ **`task.update` is NEW for the board-tools door** — `board_my_cards` needs only `board.view` and `board_create_card` only `task.create` — so an install granting exactly those 403s here with a perfectly valid token. A **Member**-role writeback user already holds it. See [`writeback.md` § 1](writeback.md#1-a-least-privilege-writeback-token) for the full grant list. |
| kanban answered **401** on either call | The token was not accepted at all — revoked, rotated, or replaced with a value the board does not know. **Install fault**; retrying cannot help. |
| kanban answered **404** on the write | The card stopped existing between the ownership check and the write. **Nothing was written.** |
| kanban answered **422** on the write | Kanban's own validator rejected the value. Deterministic, so it is a refusal and not the retryable 502 — this is what keeps the two mirrored length caps above safe to go stale. ⛔ The board's response **body is never echoed** into your error; the message is the bridge's own. |

⚠ **Those board-caused 4xx (401/403/404/422) are reported as 422 refusals, not as the
retryable 502**, because they fail identically however many times you send them; a 5xx
or a timeout still answers **502**, which is the one you may retry. Since card#8486 that
is the rule for **every** tool on this door, not this one's alone —
[§ A board-caused 4xx is a refusal, on every tool](#a-board-caused-4xx-is-a-refusal-on-every-tool-dl-339)
owns it, and the rows above are what it means for a *correction* specifically.

**Cost:** two requests on a successful call (one board-scoped lookup, one PATCH) —
no card read-back, because the row that authorized the write already carried what the
response reports. A not-found refusal costs two reads and no write.

## Errors

| Status | Meaning |
| --- | --- |
| 403 | The request did not come from loopback (network gate). |
| 401 | Missing or unrecognized bearer token. A bearer file that exists but the bridge cannot read, and one belonging to a collided pair, are **deliberately indistinguishable** from an unknown token here — the door never tells an unauthenticated caller that another agent's bearer exists (card#5778; it 500'd on the unreadable case until then). |
| 422 | A caller-fixable bad request (missing/over-long `title`, reserved tag — matched case-insensitively, out-of-charset tag/key, non-boolean `include_description`, unknown tool) — **or a `board_create_card` whose `idempotency_key` correlates only to an ARCHIVED card** (DL-297: a retire suppresses the create; the message names the card ids to unarchive) — **or any refusal a tool makes**, including the ones the BOARD causes on **all three tools** (DL-339, extending DL-326: a permanent 4xx from kanban is reported here rather than as a 502, because it fails identically however many times you send it; the message says when the cause is an install fault rather than your arguments — see the section below). |
| 502 | Upstream kanban error (may be retryable). |
| 503 | Board tools are not fully configured on this bridge (e.g. no writeback token). |

### A board-caused 4xx is a refusal, on every tool (DL-339)

**This section OWNS the rule; the tool sections above point at it.** When kanban itself
refuses a request the bridge made on your behalf, the answer you get depends on whether the
cause can CLEAR — not on which tool you called:

| kanban answered | You get | Why |
| --- | --- | --- |
| **401** on any call | **422 refusal**, "revoked, rotated or replaced" | kanban's v3 API is `auth:sanctum`: a token it no longer knows is refused at the door on every subsequent call. **Install fault** — retrying is the one thing that cannot help. |
| **403** on a READ | **422 refusal**, naming the token's **abilities** | kanban gates the API per token and a GET needs `read`. ⛔ Deliberately **not** board membership: a board the token is not a member of answers **200 with zero rows**, never 403. |
| **403** on a WRITE | **422 refusal**, naming **two** gates | the token's abilities *and* the writeback user's board role (`task.create` for a create, `task.update` for a correction). Both need auditing; a 403 cannot say which. |
| **404** on any call | **422 refusal**, "API-surface fault" | the ROUTE answered 404, which is a statement about the API surface rather than about a card (a card that is simply not yours is a different refusal, with its own message). |
| **422** on a WRITE | **422 refusal**, bridge-authored | kanban's own validator rejected a VALUE you sent. Deterministic — and this is what keeps the mirrored length caps safe to go stale. ⛔ The board's response **body is never echoed**; the message is the bridge's own. |
| **422** on a READ | **502** (retryable) | a read sends no value for a validator to reject, so a 422 there is a malformed-query/API-surface fault the bridge has no cause to name. Deliberately NOT in the set above. |
| **5xx**, a timeout, a rate limit | **502** (retryable) | it may clear. This is the one you may retry. |

⚠ **Every 422 above writes and creates NOTHING** — the refusal is the whole outcome.

⭐ **Why this matters more than the status code:** a `502 upstream board error` is an
instruction to RETRY. Handing it to a seat for a rotated token or a too-narrow token scope
puts that seat in a retry loop against a cause no retry can change, with no diagnosis — and
the operator, who is the only party who *can* fix it, never hears about it. Every refusal
above names what to go and audit, and says when the cause is an INSTALL fault rather than
something your arguments can fix. The mapping is `App\Bridge\Tools\BoardCallRefusal`, one
classifier for the whole door (DL-326 built it inside `board_correct_card`; DL-339 hoisted it
and migrated `board_my_cards` and `board_create_card`).

⛔ **One deliberate exception, on `board_create_card` only.** The post-create re-read
(DL-198 leg 2, the duplicate collapse) runs only when you passed an `idempotency_key` — i.e.
exactly when a retry is idempotent by construction — and the card has **already been
created** by then, so "permanent, do not retry" would be the wrong instruction. That leg
keeps the retryable 502: your retry re-enters the correlate-before-create read, which hands
back the card if the fault cleared and names the install fault if it did not.

### What the CALLER sees when the leg itself fails (DL-312)

The statuses above are what the bridge *answers*. A call that never got an answer is a
different failure, and since the channel server's snapshot **0.9.8** the two no longer
read alike (card#7709 — before it, a dead ssh door and a bridge answering with garbage
produced the same string, and for the dead-door case that string was empty):

| What the agent gets back | What happened |
| --- | --- |
| `the ssh <target> leg FAILED: ssh exited <N>: <stderr>` (plus ` \| partial output: …` if the far end wrote any) | **The transport failed.** The stderr is the diagnosis — `Permission denied (publickey)` (the key or the `authorized_keys` line is gone), `Connection refused` (sshd down or the wrong port), `Host key verification failed` (the host was rebuilt). Credential-scrubbed and length-bounded, so it can be pasted. |
| `non-JSON response from the bridge (<label>): <snippet>` | **The transport worked and the bridge answered with something that is not JSON** — typically a PHP warning or an error page prepended to the body. The snippet is the answer; the transport is not the suspect. |
| `could not spawn ssh to <target>: …` / `ssh to <target> exceeded the <N>ms deadline` | No child, or a leg that connected and then hung. |

A seat on a snapshot older than 0.9.8 gets the second message for **both** of the first two
rows — see § Staying in sync in [`examples/channel-servers/README.md`](../examples/channel-servers/README.md)
for reading the deployed version.

## How it is wired (operator view)

There are **two front doors** into the same dispatch machinery, selected per agent
by `board_tools.transport` (`http` | `ssh`, the default **since v0.68.0 / DL-225**;
before v0.68.0 the default was `http`). Both resolve the caller's
identity, then run the identical `BoardToolDispatcher` onto the shared least-privilege
writeback client — so the response body is byte-identical whichever door served it.

> **⚠ Upgrading to v0.68.0:** the unset-`transport` default flipped `http` → `ssh`.
> A block relying on the old implicit `http` default must set `transport: http`
> explicitly before upgrading to keep the loopback path — otherwise it reads as `ssh`,
> the bearer stops resolving over the HTTP door, and the call fails closed (401).
> `bridge:check` warns pre-upgrade for an agent on `ssh` by the default with no
> completed ssh setup.

```
# HTTP transport:
agent session ──MCP tools/call──▶ channel server ──HTTP loopback + bearer──▶ bridge ──kanban token──▶ board
                                  (dumb proxy,                              (loopback gate + per-agent
                                   no board token)                          bearer + ToolRegistry)

# SSH-forced-command transport (card 4952 — no bearer, no forwarding; the default since v0.68.0):
agent session ──MCP tools/call──▶ channel server ──ssh stdin/stdout──▶ bridge:tools-call --agent=X ──kanban token──▶ board
                                  (spawns ssh, no command;             (identity = pinned --agent;
                                   sshd forces the command)             ToolRegistry)
```

- **Transport (`http` | `ssh`):** `http` resolves the agent by **bearer** over the
  loopback POST; `ssh` resolves it by the **pinned forced-command `--agent`** and
  carries **no bearer**. Pick one per agent (single-valued, v1). The ssh door is the
  only cross-host transport that works on a seat locked to `AllowTcpForwarding remote`
  (where the HTTP forward tunnel is blocked) — see
  [`docs/multi-host.md § Board tools (two-way) SSH-forced-command transport`](multi-host.md#board-tools-two-way-ssh-forced-command-transport-card-4952).
- **Config:** each participating agent's YAML carries a `board_tools:` block —
  see [`docs/config-schema.md § board_tools`](config-schema.md). Absent ⇒
  byte-identical no-op. A present block **defaults ON** where it can be satisfied
  (complete scope + a resolvable bearer); an unsatisfiable default block suppresses
  itself and `bridge:check` FAILs naming it (use `enabled: false` to stage silently).
- **Auth:** the channel server presents a per-agent bearer. By default that bearer
  is the agent's **channel token** (`channel.auth.token_path`) — no new credential;
  an explicit `board_tools.auth.token_path` is honored first as a deprecation alias.
  The bridge resolves the bearer to the agent (iterate-and-`hash_equals` over the
  roster); the agent name is derived from the token, never from the request. A
  shared/colliding token fails closed for *both* agents.
- **Network:** the `/agent-tools/call` route is **loopback-gated** — the TCP peer
  must be `127.0.0.0/8` or `::1`. For the same-box endpoint value (NOT simply
  "use the public hostname" — see the trap below) follow
  [§ Same-box enablement (Apache/FPM)](#same-box-enablement-apachefpm);
  multi-host needs a forward SSH tunnel — see
  [`docs/multi-host.md § Board tools (two-way) forward leg`](multi-host.md#board-tools-two-way-forward-leg).
- **Provisioning:** `bridge:provision-tools` mints each enabled **http** agent's
  bearer (0600, idempotent, collision-checked). It never edits agent YAML — for an
  agent without a `board_tools:` block it prints a paste-ready skeleton. For an
  **ssh** agent it mints no secret (the private key is host B's) — it **prints the
  ready-to-run `provision-board-tools.py --role a|b` invocation** for each leg
  (FR #5010 §2), with this agent's params filled in (`--agent` from the config,
  `--artisan` from the install path, `--ssh-account` from `board_tools.ssh_account`).
  The static `bin/provision-board-tools.py` program owns both legs from a single
  source that cannot drift: `--role a` (root, Linux, on the bridge box) pins the
  forced-command `authorized_keys` line — the **sole** security boundary — and makes
  **no** `sshd_config` change (card 5091 retired the account-level `Match User`
  hardening; see `docs/multi-host.md § 3`); `--role b` (the calling seat, cross-platform
  python) generates
  the FIPS ECDSA P-256 key, deploys the bundled channel-server snapshot, and merges
  `.mcp.json`. The merge **force-sets the SSH tools transport keys** it owns
  (`BRIDGE_TOOLS_SSH_TARGET`/`_KEY`/`_PORT`) but only **creates the live-wake channel
  vars (`BRIDGE_CHANNEL_TRANSPORT`/`_NAME`) if absent** — a re-provision never
  overwrites an existing seat's channel transport (e.g. an HTTP live-wake fallback),
  only bootstrapping the platform default on a fresh `.mcp.json`: **`unix` on POSIX,
  `http` on Windows** (Node on Win32 rejects filesystem socket paths, so `unix` is
  unusable on a fresh Windows seat — `http` is the only working channel transport there).
  Its pubkey validator is
  a **full-line shape check** (rejects multi-line /
  CRLF pastes), superseding the prefix-only guard the old generated bash carried.
  Run the host-A line as root on the bridge box and the host-B line on the calling seat;
  a same-box Linux run hands the `.pub` path to `--role a --pubkey-from` (no paste).
  Windows host B is supported: the host-B leg is cross-platform python and the Windows
  path (`%USERPROFILE%\.ssh`, icacls-based key hardening in lieu of `chmod 600`, and a
  Win32-OpenSSH precheck that fails closed if `ssh.exe`/`ssh-keygen.exe`/`ssh-keyscan`
  are absent) was validated on a real en-US Windows 11 seat. The `ssh -i` round-trip
  (`--self-cert`) is the authoritative permission check; the icacls SID-based ACL
  assertion (refuse if the private key is readable, or its `.ssh` dir writable, by any
  principal beyond `{owner, SYSTEM, Administrators}`) is defense-in-depth. Certify
  afterward with `bridge:check --probe-tools-ssh=<user@host>`.
  **Known limitation (en-US only):** the icacls hardening matches Windows built-in
  principals (`BUILTIN\Users`, `NT AUTHORITY\SYSTEM`, …) by their **en-US account
  names**. On a **localized** Windows those print under localized names and do not
  match, so the icacls decision **refuses** (fail-closed — a spurious refuse, never an
  unsafe accept). A durable fix — resolving principals to their well-known SIDs directly
  (`LookupAccountName` / `icacls /save`) rather than through the localized-name table —
  is tracked separately.
- **Preflight:** `bridge:check` probes each enabled agent's token readability,
  token collisions, swimlane/stage existence, and the service user's board
  membership. For an **ssh** agent it also probes (offline) the pinned
  `authorized_keys` line — that it forces `bridge:tools-call --agent=X`, denies
  pty + all forwarding (outcome-based, not a `restrict` keyword match), and carries a
  FIPS-approved key on a FIPS seat. That pinned forced-command line is the **sole**
  security boundary; `bridge:check` asserts **no** sshd posture (card 5091 retired the
  account-level `Match User` hardening — see `docs/multi-host.md § 3`).
  The pinned-line check certifies the **forced-command account** — when `bridge:check`
  runs under `sudo` but that account is not `root`, set `board_tools.ssh_account` so the
  probe reads its `authorized_keys`, not the invoking root's (a configured account that
  does not resolve to an OS account **fails** rather than certify a phantom path; see
  `docs/multi-host.md § 3`).
  `bridge:check --probe-tools=<endpoint>` exercises
  the REAL HTTP loopback+bearer path; `bridge:check --probe-tools-ssh=<user@host>`
  the REAL ssh round-trip (see the runbook below).
- **⭐ The CLIENT half is reported too, and only the seat can report it (DL-313).**
  Everything above observes the **bridge** side of the door. The **calling seat's** half —
  its keypair, its seeded `known_hosts`, the `BRIDGE_TOOLS_*` entries in its own
  `.mcp.json`, its deployed channel server — lives in files the bridge **may not read**
  (an account may only read its own; the same rule that makes `channel.server_path` an
  operator declaration rather than an inference). So the seat **reports by calling**:
  a successful board-tools call stamps one row per agent, and `bridge:check` reports its
  **age** — `board_tools: agent X: client half REPORTED — a successful board-tools call for
  this agent was recorded 3h ago, over ssh`. **No new tool and nothing to run on the seat
  beyond a normal call.**
  ⚠ **The row names the agent the door opened FOR, not the caller — and the green line says
  so.** `bridge:check --probe-tools`, `provision-board-tools.py --self-cert` and a hand-run
  `bridge:tools-call --agent=X` on the bridge host all reach the same success point and
  stamp the same row, with none of the seat's own files involved. **Step 6 of the
  enablement runbook below is one of them, and it runs BEFORE step 7 restarts the channel
  server** — so a `REPORTED` line straight after enablement may be the bridge's own call,
  for a seat that has no `.mcp.json` entry yet. Read it as *the door opened*, and confirm
  the seat by having the seat itself call.
- **⭐ There are TWO green lines since DL-316, and the difference is what they CLAIM — the
  severity is `ok` on both.** The ssh door records how the serving process was started, so a
  call that arrived through the pinned forced command reports the stronger of the two:
  `board_tools: agent X: client half REPORTED **THROUGH THE SSH DOOR** — … the process that
  served it carried sshd's session environment, had NO CONTROLLING TERMINAL, and carried no
  SSH_TTY — the shape of the pinned pty-less forced command`. Everything else — an http call, a hand-run, and **any row written before
  the upgrade** — keeps the `client half REPORTED — …` line above, word for word.
  ⭐ **The predicate — every term, the reason each is there, what a `sshd` stamp RULES OUT and
  the two things it does NOT — is owned by `app/Bridge/Tools/CallProvenance.php`'s class
  docblock. Read it there.** This page states only the operator-facing consequence, and
  deliberately does not restate the rule: the first cut of this feature carried **eleven**
  hand-maintained restatements of it across code comments, docs and the decision log, the rule
  underneath them was then measured **wrong**, and all eleven were wrong together in prose no
  test reads.
  ⚠ **The operator-facing consequence, in one line: the stronger line narrows the caller set,
  it does not close it,** and the line PRINTS its own remainders — a pty-less
  `ssh <host> '<command>'` (which is exactly what `bridge:check --probe-tools-ssh` and
  `provision-board-tools.py --self-cert` drive, indistinguishably from the seat), and a
  hand-run from a context with no controlling terminal that carries `SSH_CONNECTION`.
  **If either has been run since, the stronger line may be that run** — confirming the seat
  still means having the seat call.
  ⚑ **A host that cannot ANSWER the question never prints the stronger line, and that says nothing about the seat.**
  Each fact behind the verdict is three-valued — measured-true, measured-false, or *unestablishable* — and only a
  measurement earns the stronger claim, so a run-user `php.ini` carrying an `open_basedir` (which denies the probe
  both `/dev/tty` and `/proc`) records `not_sshd` and prints the `client half REPORTED — …` line for every call,
  including a genuine one. Confirming the seat still means having the seat call.
  ⛔ **Nothing is stored or printed but a NAME.** `SSH_CONNECTION` is a client IP, a client
  port and this host's own address and port; only its **presence** ever crosses into the row,
  which `bridge:check` prints verbatim.
  ⛔ **There are TWO verdicts, not three, and the missing one is deliberate.** A seat that
  can report is by definition wired, so *"never wired"* is **not observable from the
  bridge** — it is the same absence as *"wired, and quiet"*. No record, or one older than
  `BRIDGE_BOARD_TOOLS_CLIENT_HALF_TTL` (default 7 days), reports **`client half
  UNREPORTED`** as **`unvalidated`** — plain text, **never a warn, never a fail, and the
  exit code does not move**. ⚠ **UNREPORTED is not evidence the seat is unwired.** The
  remedy is to **ask the seat to make one call** (`board_my_cards`) and re-run
  `bridge:check` — **not** to re-provision it. Acting on a bridge-side absence as if it
  were a client-side fault is the incident this leg exists to prevent.

### Which spelling the probe read — and when the version-skew fallback can go

Both live probes read the answering install's scope header under `configured_board_id`,
**falling back to the older `board_id` spelling when it is absent** — neither probe is
guaranteed to be talking to the install it runs inside (`--probe-tools-ssh` round-trips to
another HOST; `--probe-tools` POSTs to a vhost a co-resident install at a different version
can serve). Without that fallback a responder predating DL-302 is reported as an
`IDENTITY MISMATCH`, i.e. a version difference named as an identity fault.

**It is tolerance, and on a current responder the two keys mean different things** — the
first is an identity echo, the second is where the returned ROWS are. So the probe now ends
every finding with the spelling it actually read:

| The finding's last sentence | What it tells you |
| --- | --- |
| *Header spelling: `configured_board_id` …* | This responder is on DL-302 or later. The fallback did not fire. |
| *⚠ Header spelling: LEGACY — …the legacy `board_id` spelling…* | This responder answered no `configured_board_id`, so the board just compared came out of the key a current install uses for an observation. Likeliest cause is an install predating DL-302 — upgrade it and re-probe; a relay, or a responder that emits the header conditionally, reads the same way. |
| *Header spelling: NEITHER …* | This responder answered no header under either name — it always accompanies a failure, and that failure's cause is the ROUTE, not your credential: nothing in the response identifies who answered, so the tail sends you at the endpoint / the forced command rather than at a token that may be doing its job. |

**Dropping the fallback is a measurement, not a judgement call, and card#7325 (DL-304) owns
it.** Two things must hold:

1. **Every install that is a probe target answers under `configured_board_id`.** You read that
   off the probe — run `bridge:check --probe-tools=<endpoint>` and
   `bridge:check --probe-tools-ssh=<user@host>` against each one and look at the last sentence
   of the finding. ⛔ The subject is the **responder**, which on the ssh leg is a different host
   entirely: no reading of this repo answers it, and a target you did not probe is **unmeasured**,
   not clean.
2. **Nothing else answers this envelope.** `board_my_cards` is the only responder today and it
   emits `configured_board_id` in its base result literal, on no condition at all — a property
   guarded by a test, not established by reading the file. A second tool, a channel-server relay,
   or a future responder that emits the header conditionally re-opens the question, because a row
   observation would then be read as an identity claim with nothing red anywhere.
   ⚠ **DL-326 shipped a second tool that emits `board_id` and no `configured_board_id`**
   (`board_correct_card`, where `board_id` is the board the authorizing ROW was on), and the
   condition survives because its subject is **the envelope the PROBE reads**, not the tool
   registry: `BoardToolsScopeHeader::read()` has exactly two callers — `SshTransportProbe` and
   `BoardToolsHttpProbeCheck` — and both send the literal `{"tool": "board_my_cards"}`, so no
   result of the correction tool can reach the fallback. The re-opening case is unchanged and is
   the one those probes already name: **something other than `board_my_cards` answering the
   probe** (a relay, a forced command running the wrong thing).

Both true ⇒ the fallback is dead tolerance and goes, together with the two skew tests that pin it.

⚠ **The version compare this replaced cannot be run.** The condition was first written as
*"the oldest bridge version any probed install runs"* ≥ *"the release that first emitted
`configured_board_id`"*. The board-tools envelope is `{ok, tool, result}` and carries no
version, and a version field added now would be missing on precisely the old responders the
question is about — so the spelling is the predicate that compare was a proxy for, measured
directly, on the round trip the probe already makes. **DL-302 ships in the same release as this
note**, so the earliest install that can satisfy (1) is one running that release or later;
until every probe target is upgraded past it, the fallback stays.

Audit trail: one structured log line per call (agent, tool, outcome). A queryable
`tool_calls` ledger table is the named v2 upgrade if operators want it.

## Same-box enablement (Apache/FPM)

The end-to-end runbook for the common topology: the bridge served by an Apache
vhost (`*:443`/`*:80`) proxying to PHP-FPM, with the agent's channel server on
the **same box**.

> **Multi-user box? This is a two-party runbook.** The steps below assume one
> actor owns the whole box. On a multi-user install (each agent its own OS
> user), the steps split by privilege: **step 1** (`/etc/hosts` pin or the
> loopback-port vhost) is **root's**; **steps 5 and 7** (the channel server's
> env + restart) and placing the bearer belong to the **agent's own OS user**;
> the config/mint/check steps (3, 4, 6) run as the bridge's operator user.
> Hand the sequence to the right actors up front rather than discovering the
> boundary step by step.

### 1. Pick the endpoint — the obvious value is the wrong one

`BRIDGE_TOOLS_ENDPOINT=https://<your-public-bridge-host>/agent-tools/call`
**fails the loopback gate**: DNS resolves the name to the box's public IP, and
when the kernel connects to its own public address it source-selects that
public IP — so the TCP peer the gate tests is **not** loopback, and the call is
(correctly) refused with 403. The recipe that keeps TLS verification ON:

1. Loopback-pin the bridge's own vhost name in `/etc/hosts`:

   ```
   127.0.0.1 <bridge-hostname>
   ```

2. Point the channel server at it:

   ```
   BRIDGE_TOOLS_ENDPOINT=https://<bridge-hostname>/agent-tools/call
   ```

The connection now goes to `127.0.0.1` (the gate passes), SNI/Host still name
the real vhost (Apache routes it correctly), and the certificate still matches
the hostname (no verify-off hack anywhere).

Plain `http://127.0.0.1/agent-tools/call` also works, but **only when the
bridge vhost is what answers a bare-IP Host on `:80`** — on a box with several
vhosts, a request whose Host is `127.0.0.1` lands in the *default* vhost, which
may not be the bridge.

**The loopback-port vhost (first-class alternative).** The `/etc/hosts` pin is
a box-global DNS side-effect some operators refuse, and the bare-IP form dies
on a multi-vhost box. A dedicated loopback listener sidesteps both:

```apache
Listen 127.0.0.1:8787
<VirtualHost 127.0.0.1:8787>
    DocumentRoot /path/to/bridge/public
    # same FPM proxy config as the main bridge vhost
</VirtualHost>
```

```
BRIDGE_TOOLS_ENDPOINT=http://127.0.0.1:8787/agent-tools/call
```

The port is bound to loopback only (never exposed), no DNS is touched, Host
ambiguity is impossible (the vhost is selected by the listener, not by name),
and TLS is unnecessary on a same-box loopback hop. One-time root step, same
class as the `/etc/hosts` line — pick whichever your box's policy prefers.
This is the shape the channel-server README's example env already uses.

### 2. Why the gate is proxy-safe on this topology

mod_proxy_fcgi forwards Apache's **own TCP connection peer** as `REMOTE_ADDR`
(this is not the separate-reverse-proxy-hop pattern where the app sees the proxy
as the peer). The app registers **no TrustProxies middleware**, so
`$request->ip()` returns that raw peer — a forged `X-Forwarded-For` is never
consulted, in either direction. This posture is **test-pinned**: the XFF-spoof
tests in `AgentToolsCallTest` go red the moment a `trustProxies` registration
lands.

### 3. Mint the bearer (only for a DEDICATED tools bearer)

**Default path — skip this step.** Under the default-ON model the tools bearer
reuses the agent's **channel token** (`channel.auth.token_path`), so there is
nothing to mint; point `BRIDGE_TOOLS_TOKEN_FILE` at that same channel-token file
(or omit it and let the `BRIDGE_CHANNEL_TOKEN` fallback resolve it). Run
`bridge:provision-tools` only when you want a **dedicated** tools bearer, declared
as an explicit `board_tools.auth.token_path` (the alias):

```bash
php artisan bridge:provision-tools                # all agents with an explicit board_tools.auth.token_path
php artisan bridge:provision-tools --agent=<name> # one agent; without a block, prints the paste-ready skeleton
```

Idempotent: an existing secure (0600) bearer is left alone; an insecure one is a
hard failure; a token value shared by two agents fails both by name. Agents that
reuse the channel token are skipped (nothing to mint). The token value is never
printed.

### 4. Declare the `board_tools:` block

Per agent YAML — see [`docs/config-schema.md § board_tools`](config-schema.md).
`bridge:provision-tools --agent=<name>` prints the skeleton if the block is
absent.

### 5. Configure the channel server

```
BRIDGE_CHANNEL_TOOLS=1
BRIDGE_TOOLS_ENDPOINT=<the value from step 1>
BRIDGE_TOOLS_TOKEN_FILE=<the bearer path from step 3>
```

### 6. Verify BEFORE flipping traffic

```bash
php artisan bridge:check --probe-tools=<the endpoint from step 1>
```

This exercises the real network path per enabled agent: a live `board_my_cards`
call proving the endpoint is reachable, the loopback gate admits it, the bearer
resolves to the right agent, and the scope header the bridge answers with
(`configured_board_id`/`swimlane_id`) is that agent's. ⚠ That last leg certifies
**which agent the bearer resolved to** — it is config echoed back, so it cannot show
that the bridge-side lane filter ran. Each failure mode names its likely cause (403 → the
step-1 trap; 401 → bearer mismatch/collision; connection refused → wrong
vhost/endpoint). Non-2xx or a scope mismatch exits non-zero. Every finding also names WHICH
spelling the responder answered the header under, which is how a version skew stops reading as
an identity fault — see **Which spelling the probe read** above.

⚠ **This step STAMPS the client-half ledger (DL-313), and step 7 has not run yet.**
`--probe-tools` POSTs a real `board_my_cards` with that agent's own bearer, so it reaches
`BoardToolDispatcher`'s success point exactly as the seat would and writes the same row.
`bridge:check` will therefore print `client half REPORTED` for the agent from here on —
**including for a seat whose channel server is not running and whose `.mcp.json` has no
`BRIDGE_TOOLS_*` entry at all.** Do not read that line as the seat's half being wired until
the seat has made a call of its own; the line states the bound itself.
⚑ **DL-316 does not rescue this step**, and the reason is worth knowing: `--probe-tools` goes
through the **HTTP** door, which cannot discriminate at all, so it stamps the weaker
provenance and gets the weaker line. It is `--probe-tools-**ssh**` that reaches the ssh door
and stamps the **stronger** one — a pty-less ssh round-trip is one of the two remainders
`app/Bridge/Tools/CallProvenance.php` names — so on an ssh install the certify step can leave
the more confident line standing for a seat that has not yet called. Same rule either way:
confirm the seat by having the seat call.

### 7. Restart the channel server

Restart the agent's channel MCP server so it re-reads its env; the tools are now
advertised and live.

## Same-box SSH enablement — the one-shot wrapper (card 5090)

The SSH transport (`board_tools.transport: ssh`, the default since v0.68.0) is the
no-root-per-call, forwarding-uniform door. Its two legs — `--role b` on the agent's
seat, `--role a` as root on the bridge box — are documented above under **Provisioning**
and, for the cross-device topology, in
[`docs/multi-host.md`](multi-host.md). When both legs land on **one box** (the agent's
Claude seat and the bridge share the machine, each as its own OS user), the two-leg dance
plus the interstitial "make the tool readable / resolve the project dir / capture the
pubkey path / chown storage" chores collapse into a single root-run wrapper:

```bash
sudo bin/provision-board-tools-samebox.py --agent <name> --ssh-account <host-A user>
```

It orchestrates, on `127.0.0.1`:

1. **Preflight (fail-closed, before any mutation).** Validates: running as root; both OS
   users exist (`getent passwd` on the agent user and the ssh-account); the agent's
   `.mcp.json` resolves **unambiguously** under its home (→ `--project-dir` + the
   `mcpServers` key → `--channel-name`); both checkouts' `provision-board-tools.py` and
   the host-A `artisan` are present; and `php` is on PATH. Every failure names its fix; no
   step is silently skipped.
2. **`--role b` as the agent user**, from the **agent's own checkout**
   (`sudo -H -u <agent> python3 <agent-checkout>/bin/provision-board-tools.py --role b …`),
   with `--ssh-target <ssh-account>@127.0.0.1`. It captures the printed public-key path and
   validates it exists + is readable (no `--self-cert` yet — the key is not pinned on host
   A until step 3).
3. **`--role a` as root**, from the **host-A checkout**, pinning that captured key by path
   (`--pubkey-from`, no paste).
4. Prints the one unavoidable **manual step**: restart the agent's Claude session so the
   channel re-spawns and reads the merged `.mcp.json`.
5. Certifies with `php <host-A artisan> bridge:check`.
6. `chown -R <ssh-account>:<ssh-account>` on the host-A `storage/` (a root-run `artisan`
   can leave root-owned logs).

`--dry-run` runs the read-only preflight and prints the exact argv for both legs without
changing anything. Overrides — `--agent-home`, `--agent-bin`, `--hostA-checkout`,
`--project-dir`, `--channel-name` — pin any value discovery can't (or shouldn't) infer,
e.g. an agent with several `.mcp.json` under its home. Re-running is safe: the underlying
`--role a`/`--role b` are idempotent (append-or-verify `authorized_keys`, create-if-absent
`.mcp.json` merge, skip-if-present keygen) and the wrapper adds no non-idempotent state.

**Why no global `bin/` staging (version isolation).** The wrapper runs **each agent's own
checkout's** `provision-board-tools.py` for its leg — the agent user runs the agent's
version, root runs the host-A install's version. It deliberately does **not** copy the tool
into a shared path such as `/usr/local/bin`: two agents on one host can be pinned to
**different bridge versions**, and a single shared global path would let a redeploy of one
clobber the other. If the agent's own copy is missing, or not readable by the agent user,
the wrapper **fails with an actionable message** telling the operator to give the agent its
own checkout — it never falls back to a shared/global copy. (Contrast the cross-device
flow, where each host trivially has its own checkout; on a shared box that separation must
be asserted, which is what the preflight does.)
