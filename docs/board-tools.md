# Two-way board tools (DL-217)

The bridge is push-only no longer. When an install enables **board tools**, an
agent gets a small, channel-identity-scoped **request/response** surface over the
same channel that already delivers wake events — so an impl seat with **no kanban
token and no toolkit** can see and capture its own board work directly.

The tools that ship today — the table is held against the bridge's own registry by
`ChannelServerToolSurfaceRestatementTest`, so it is the live set and not a snapshot of it
(two since DL-217; the correction tool since DL-326; the take tool since DL-372; the comment tool since DL-381; the by-id read since DL-435; the search since DL-437; the CI-await pair since DL-452):

| Tool | Direction | What it does |
| --- | --- | --- |
| `board_my_cards` | read | Return YOUR own cards (your product swimlane grouped by stage, the shared cross-system swimlane when configured, and coordination cards addressed to you when the coord leg is configured). Read-proxied — the kanban token never leaves the bridge. |
| `board_create_card` | write | Create a card in YOUR OWN swimlane. The swimlane is forced from your bridge identity; you cannot target another lane. The card is born **untriaged** and surfaces to the triage pass. |
| `board_correct_card` | write | **Correct a card that is YOURS** — its `name`, `description` or `tags`. Scoped to cards on your own board that carry your own bridge-stamped `created-by:<you>` **or** are assigned to your own kanban user (DL-376); the response says which of the two authorized it; anything else is **refused, loudly**. A `name` correction is refused on a **pinned** card (DL-342). |
| `board_take_card` | write | **Claim a card for YOURSELF** — write your own kanban user into the board's `assigned_user_id`, so a card you are working is visibly taken even when its column never moved. ⭐ **`start: true` STARTS the card (card#11150 / DL-449):** ONE write moves it into the board's In Progress column AND assigns it to you, both read back — only from a `started_from_stages` column (a card already In Progress is assigned without a move); anything else is refused by name, with a `reason` code, and nothing is written. ⛔ **No argument names a user** (`card_id` and `start` are the whole accepted set): the assignee is resolved server-side — your seat's kanban user id in the coord roster (DL-450) — never from the payload, so a seat can claim a card for itself and for **nobody else**. A card a **different** user holds is **taken over with a warning** and a card comment naming them (card#10869) — except that replacing the **assignee** of a card in a **finished** column is **refused by name** and nothing is written. |
| `board_comment_card` | write | **Append a comment to a live card on YOUR board.** Nothing on the card is read or replaced, so it is the safe way to add a note, including to a card outside your `board_my_cards` window. Any live card on your own board qualifies: no mint, assignment or lane requirement. The bridge writes `FROM: <your seat>` as the first line, from your bridge identity. **Append-only**: no edit, no delete. |
| `board_get_cards` | read | **Read cards you already know the ids of**, in one call, whatever lane, column or archive state they are in. **Every id comes back exactly once**, in request order, with an explicit `status` — `found`, `archived`, `other_board` or `not_found` — never a silent omission. A `fields` projection selects what each card carries; `description` is opt-in per call. |
| `board_search` | read | **Search YOUR board by filter** — tags (all / any), columns, PR number, name text, updated-since date, archived, lane (`mine` / `any` / `none`) — and get **the matches only**: no lane list, no column list. `summary: true` returns counts per column (and per named tag) instead of cards. Every filter is applied by the board and **confirmed applied**, or the call is refused; the window says `total`, `truncated` and `total_is_lower_bound`. |
| `ci_await` | write | **Tell the bridge you are waiting for CI on one commit, instead of polling GitHub** (card#11200 / DL-452). When every workflow run GitHub lists for that head SHA is terminal, you get ONE `ci_settled` event on your channel; if that does not happen before the wait expires, ONE `ci_await_expired`. **Not a verdict** — run `ci-read` once on the head for green/red. Reads and writes no board. Self-scoped: no argument names a seat. |
| `ci_await_cancel` | write | **Remove your own `ci_await`** on one head, so no event is sent for it. Never touches another seat's. |

> ⛔ **EVERY STRING YOU SEND IS TRIMMED, AND A VALUE MADE ONLY OF INVISIBLE CHARACTERS
> COUNTS AS EMPTY** (card#9155). The tools are reached through two front doors and only
> the HTTP one has Laravel's global `TrimStrings` + `ConvertEmptyStringsToNull` in front
> of it, so until this the same call could mean two things: a `title` of one non-breaking
> space was **refused** over HTTP and **created a card with a visually blank title** over
> ssh. Both doors now normalise through one primitive that delegates to the framework's
> own `Str::trim`, whose invisible set includes `\u00A0` (NBSP), `\u200B` (zero-width
> space) and `\uFEFF` (BOM) — so:
>
> - a value that is blank once trimmed is treated as **empty**: refused where the field is
>   required (`title`, `name`, a `tags` entry), and a **clear** where the field has a clear
>   form (`board_correct_card`'s `description`);
> - a value that is not blank is **stored trimmed**;
> - ⚠ but a field's **length cap and charset guard still read the value you SENT**, padding
>   included — they are deliberately not moved, because loosening them would make the ssh
>   door accept input it refuses today. So a value whose *padding* is what trips a cap is
>   still refused over ssh and still accepted over HTTP; see the note at the end of this
>   section;
> - characters **inside** a value are never touched, and non-ASCII text (accents, CJK) is
>   unaffected.
>
> ⚠ **This is a behaviour change on the ssh door:** input it used to accept — a visually
> blank title, a body of invisible characters, a whitespace-only tag — is now refused or
> cleared, exactly as the HTTP door has always done.
>
> ⚠ **SOME DIVERGENCES REMAIN, and in each of them the ssh door is the STRICTER one.**
> `idempotency_key`'s charset, a tag's charset and 64-character cap, and
> `title`/`name`'s 255-character cap all read the value **as sent**. So a value whose
> *padding* is what trips one — `" abc "` as an `idempotency_key`, a tag padded with a
> non-breaking space, a title at the cap with spaces around it — is refused over ssh and
> accepted (as its trimmed self) over HTTP. **The same holds one level out, in the request
> envelope rather than in `args`:** `TrimStrings` cleans the whole HTTP body, so a `tool`
> key padded with a non-breaking space resolves over HTTP and is refused over ssh, and a
> padded `client_version` is recorded over HTTP and dropped over ssh (so a DL-426 client-update
> sentence names the version over HTTP and treats it as *no version* over ssh). Closing any of these
> means making the ssh door accept input it refuses today, which is a separate change to
> what the system accepts and is not made here. Nothing wrong is written in the meantime:
> the strict door refuses. **The list is deliberately not presented as complete** — an
> exhaustive prose list is a count in longer form; the checked-in denominator is
> `BoardToolsBlankArgumentCrossDoorTest`'s divergence arms.

## Discovering them

If your channel server advertises tools, your MCP client lists `board_my_cards`,
`board_create_card`, `board_correct_card`, `board_take_card`, `board_comment_card`,
`board_get_cards`, `board_search`, `ci_await` and `ci_await_cancel`, and the
server's own `instructions` string names them (it derives the names from the same tool list it
advertises). ⚠ **A tool your seat's copy of the channel server
predates is invisible to you and reports as missing** — the tool set is restated in that
server's inline MCP schema (there is no pointer a model can follow), so a seat on an older
snapshot lists fewer tools than the bridge serves. `bridge:check` compares the version your
seat reports against the one this checkout bundles; `Tests\Feature\AgentTools\ChannelServerToolSurfaceRestatementTest`
is what keeps the bundled copy from falling behind the registry in the first place.
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

⛔ **LISTED IS NOT CALLABLE: a harness may advertise a tool's NAME and hold its SCHEMA back
until you ask for it.** Where it does, calling the tool straight off fails **inside your own
seat** — neither the channel server nor this bridge ever sees the call — and **the message it
hands you is about YOUR input**. The spelling this page gives for it,
`InputValidationError: could not be parsed as JSON`, is attributed to a Claude Code harness's
own instructions and is **not measured here: this project has not observed an unloaded-schema
failure**, and roundtable #538 (below) was not one. **The remedy is to load the schema first and
then call**, and your harness's own instructions own that mechanism — the ones that attribute
that message name `ToolSearch` with the query `select:<tool name>`.

⚠ **That same spelling also means exactly what it says — your JSON really was malformed — so do not
read it as proof of an unloaded schema.** Measured on roundtable #538: a seat hit it repeatedly with
its schema **already loaded 11 hours earlier**, and the payload genuinely was invalid (`"tags":
security,documentation,...` — unquoted bare tokens). ⛔ **What misled that seat was not the sentence
but the EXCERPT beneath it:** the error quoted the **first 200 of 1214 bytes** while the invalid byte
sat near **1100**, so the excerpt was structurally incapable of showing the cause it was printed to
explain. **If the excerpt looks fine, the failure may simply be outside it — check the tail of what
you sent, and prefer the reported offset over the quoted head.**

⭐ **The tell for an unloaded schema is that the failure does not vary with what you sent.**
If a call with **no arguments at all** fails with the same complaint about your INPUT as a
multi-kilobyte one does, neither was sent — and *my payload is malformed*, the reading the message invites, cannot explain the
no-argument failure, so shrinking or simplifying the payload buys nothing there. **Until you
have seen that, check your payload first:** it is what the message says, and the case measured
above was exactly that.

⚠ **This behaviour and that wording are the HARNESS's, not this bridge's, and nothing here
can change either** — the bridge never saw the call, so no refusal of ours could have
reached you. What this page can give you is the way to tell such a call apart from one the
bridge answered: [§ Did the call reach the bridge?](#did-the-call-reach-the-bridge).

## `board_my_cards`

**Arguments:**

| Arg | Required | Notes |
| --- | --- | --- |
| `include_description` | no | Boolean (default `false`). Adds `description` + `description_truncated` to **every** projected card — your own lane, the shared lane, and the coord cards alike. A non-boolean is **refused** (422) rather than coerced. See § Reading a card's scope below. |
| `stage` | no | Return only the cards in **one column of your product board**. The **numeric stage id** is the primary form. A **string** is a stage **NAME**, matched case-insensitively and whitespace-trimmed — `"50"` is looked up as a stage *called* `50`, never as id 50. A name that resolves to **no** stage, or to **more than one**, is **refused** (422): the bridge does not guess which column you meant. A numeric id that is not a stage on your board is refused too. ⛔ **An EMPTY value is refused, not ignored** — `""`, whitespace, an invisible character, or an explicit `null`. Omit the argument entirely to read every column; a silently-dropped filter would hand you *more* cards than you asked for, and the two doors disagreed about it. ⛔ **It does not reach the coord cards** — they are on a different board, whose stage ids are unrelated to yours. See § The default is capped below. |
| `limit` | no | How many cards **each list** is cut to (default **52 cards per list** — see § The default is capped). A positive integer; anything else (a float, a numeric string such as `"20"`, a boolean, `0`, a negative) is **refused** (422) before any board read, never coerced. |
| `tag` | no | **ONE tag, matched exactly** (for example `lane:A`). Adds a `tag_cards` block: every live card on **your board** carrying it, in **any lane or in none**, each with its own `swimlane_id`. See § [Cards carrying a tag, in any lane](#cards-carrying-a-tag-in-any-lane-tag-include_terminal). Trimmed as the HTTP door trims. **Refused** (422, before any board read): a non-string, an EMPTY value (`""`, whitespace, an invisible character, an explicit `null`), a value containing `"`, `*` or `%`, a value containing a character kanban stores escaped (a control character, `/`, `\` or any non-ASCII character — no exact tag match can find it), and one longer than kanban's tag cap. ⛔ Omit it and the response is exactly the default. |
| `include_terminal` | no | Boolean (default `false`). Keeps cards in **terminal columns** — the columns **the board itself declares terminal**, see § [Cards carrying a tag, in any lane](#cards-carrying-a-tag-in-any-lane-tag-include_terminal) for which declaration answers — in the `tag_cards` read. **Refused** without `tag` (it would change nothing), and when not a boolean, an explicit `null` included. |

Any other key — `status` for `stage`, say — is **refused** (422) before any board read, naming the key and the accepted set: see § [An argument the tool does not declare is refused](#an-argument-the-tool-does-not-declare-is-refused-on-every-tool-dl-379).

**Returns:**

```jsonc
{
  "board_id": 10,            // the board the returned ROWS are on (null when none was read)
  "board_observed": true,
  "configured_board_id": 10, // the board this agent is configured to read
  "swimlane_id": 4,
  "board_stages": [          // EVERY column of your board, in the board's own order —
    { "id": 50, "name": "Backlog" },      // present whether or not a card in it survived
    { "id": 51, "name": "In Review" }     // the cut, so `stage` is always reachable
  ],                       // ordered by kanban's own `position`, not the payload's order
  "cards_by_stage": {
    "Backlog":  [ { "id": 1, "name": "...", "stage": "Backlog", "tags": ["..."],
                    "assigned_user_id": 42,   // who holds it, or null — see below
                    "dl_number": "DL-1", "pr_number": null,
                    "pr_url": null,           // the card's PR url, or null — see below
                    "source": "owner/repo",   // the card's by-ref repo, or null — see below
                    "updated_at": "...",
                    // the next two keys ONLY when include_description was passed:
                    "description": "...", "description_truncated": false } ],
    "In Review": [ /* ... */ ]
  },
  "cards_window": {          // describes cards_by_stage above — ALWAYS present
    "total": 390,            // how many cards matched, BEFORE the cut
    "returned": 52,          // how many you were given
    "limit": 52,             // the cap in effect for this call
    "truncated": true,       // true ⇒ there is more behind this list
    "stage_filter": null,    // the numeric stage id this list was narrowed to, or null
    "remedy": "…"            // ONLY when truncated is true: a sentence naming the argument
                             // that gets the rest — see § The default is capped
  },
  "shared_swimlane": {                                     // when configured
    "swimlane_id": 9, "cards_by_stage": { /* ... */ },
    "cards_window": { /* the same keys, for the shared lane's OWN population */ }
  },
  // the coord block, all five keys together, when the coord leg is configured:
  "coord_board_id": 12,             // the board the coord ROWS are on (null when none was read)
  "coord_board_observed": true,
  "configured_coord_board_id": 12,
  "coord_cards": [ /* cards on the coord board carrying one of your address_tags */ ],
  "coord_cards_window": { /* total / returned / limit / truncated (+ remedy when truncated) — NO stage_filter */ },
  // ONLY when `tag` was passed:
  "tag_cards": {
    "tag": "lane:A",
    "include_terminal": false,
    "terminal_basis": "lane_type",         // WHICH declaration answered: "is_terminal", "lane_type", or
                                           // "stages_unreadable" (no columns were read — NOT "flags none")
    "excluded_terminal_stage_ids": [53],   // the columns left out ([] when none, or when include_terminal)
    "cards": [ { /* the card shape above */ "swimlane_id": null } ],  // null ⇒ in NO lane
    "cards_window": { /* the same keys, over this block's population, plus: */ "total_is_lower_bound": false },
    "other_swimlanes": 2,            // cards in a lane other than yours, or null …
    "other_swimlanes_unmeasured": null,   // … and then WHY, by name
    "no_swimlane": 3,                // cards in no lane, or null …
    "no_swimlane_unmeasured": null        // … and then WHY, by name
  }
}
```

> **`source` and `pr_url` are on EVERY projected card (card#9837).** `source` is the repo
> qualifier kanban's own rule assigns to this card's refs — computed HERE, by the bridge's
> mirror of that rule, because `tasks/search.json` does not return the stored value — an `owner/repo`, lower-cased, or `null` when
> nothing on the card names one. On a **shared** board, a by-ref correlation (DL, PR number or
> issue number) only matches events from this repo; a `card#` token is **not** filtered by it,
> and on a 1:1 board no qualifier is applied at all. It is only meaningful when the card carries
> a `dl_number`, `pr_number` or `issue_number` — kanban indexes no refs for a card without one,
> so there is nothing for `source` to qualify. The bridge derives it with its mirror of kanban's
> own rule, in this order:
> `payload.repo` (only when it contains a `/`), then a GitHub `payload.pr_url`, `issue_url`,
> `html_url`, then the card's top-level `external_link`. All of those come from the same
> `tasks/search.json` rows the tool already reads, `external_link` included, so `source`
> costs no extra request. `pr_url` is the stored `payload.pr_url` as a string, or `null`;
> it is card text returned as stored, like `name`, and it is one input to `source`, not the
> answer — a `payload.repo` outranks it. A `pr_url` ending in `/pull/0` is a
> repo-attribution placeholder (what `bridge:check` tells an operator to stamp on a card
> with no PR yet), not a pull request.
>
> ⚠ **THAT MIRROR IS THE BRIDGE'S COPY OF A RULE KANBAN OWNS, and what holds the two together is
> published rather than assumed (card#9936):** [`external-reference-parity-corpus.json`](external-reference-parity-corpus.json)
> pins what the copy answers, a test runs it against the copy on every build, and the seam row in
> [`kanban-integration-contract.md`](kanban-integration-contract.md) § 3 carries the bound — **no
> check in the bridge reds when kanban changes its own class**, so `source` is the bridge's
> computation of kanban's rule and never the server's stored answer.

> ⭐ **`assigned_user_id` is on EVERY projected card (DL-372), and it is the RAW board
> field.** It is what makes a claimed-but-unmoved card legible: a card whose column never
> moved is otherwise indistinguishable from an unclaimed one, so two seats pull the same
> work and neither finds out. `null` means **nobody holds it** — claim it with
> [`board_take_card`](#board_take_card). A number you do not recognise means somebody
> else does.
>
> ⚠ **It rides the `coord_cards` block too, and those cards are NOT takeable.** They are on
> the separately configured coordination board, addressed to you by TAG rather than held in a
> lane, and `board_take_card` is scoped to your own board and lanes (DL-372 Decision 3). The
> attempt is refused write-free, and on an install with a coord leg the refusal names that as
> the likely cause rather than sending your operator to audit the product board's membership.
>
> ⛔ **The bridge resolves NO NAME for that id, deliberately and permanently.** Doing so
> would need a fleet-wide seat→kanban-user map — a table two products would both key on,
> with nothing to red when they drift — and the whole design of this feature is that
> neither side needs one: the bridge writes only its OWN seat's id, and a consumer that
> knows a name for an id renders one from its own configuration (`kbcard` does, from its
> board env). An id nothing maps is **reported**, never an error.
>
> ⚠ **Additive envelope change.** Every key this tool has ever emitted is still emitted
> with the same meaning; this is a new key on each card. A consumer that enumerates card
> keys strictly will see it.

⚠ **A board fault this tool cannot read past is a REFUSAL, never an empty window** —
see [§ A PERMANENT board 4xx is a refusal, on every tool](#a-permanent-board-4xx-is-a-refusal-on-every-tool-dl-339).
The whole call refuses, including when only the **coord** leg failed: a response silently
missing its coordination cards reads exactly like a board with none.

⛔ **"No cards" is answered only of a board the bridge can read (card#10856).** kanban's search
answers a token whose user is not a **member** of the board zero rows, at 200 — the same answer as
a lane with no cards. This tool does not learn that from the search: it reads your **own** board's
structure (`boards/{id}/preload.json`) **unconditionally, before any search** — and, whenever a
coordination board is configured, its structure too, though there only AFTER that board's own tag
searches, still before any answer about it is formed. Both reads are `view`-authorized on the board
itself, so a **non-member gets a 403 there and the call is refused** (422, naming membership)
before an answer is ever formed (§ A PERMANENT board 4xx). A member is answered as before, empty
windows included — a new board, or one whose every card is archived. (card#10856 was investigated as
a `board_get_cards` / `board_search`-shaped membership control on this tool too; that control was
**declined** here, because it can never fire — the structure read already refuses first, on every
path this tool takes, so it would only cost an extra request. See the class docblock on
`BoardMembershipControl`.)

### The default is capped (`cards_window`, `stage`, `limit`)

⚠ **Every card list in this response is cut to a fixed number of CARDS, and the response
says so.** Before card#8985 nothing bounded the count: the DL-245 cap bounds one
*description*, and the **titles-only** response — the cheapest call this tool offers — was
measured at **121,032 chars / 390 cards** on one seat and **81,067 chars / 292 cards** on
another (2026-09-07). That overflows the context window of the very seat the tool exists
for, and the old response gave no hint it was oversized or partial.

- **The cap is 52 cards per list, and that figure is derived, not chosen.** The two measurements
  above are 310.3 and 277.6 chars per card; the cap has to hold at the **larger** rate, or
  the fatter of the two measured seats is still over budget. The budget is **16,384 chars
  for one list** — this install's *existing* ceiling for a single opted-in card body
  (`board_tools.description_max_bytes`), so a whole titles-only window costs no more than
  one description already does. 16,384 / 310.3 = 52.8 ⇒ **52 cards per list**.
- **It bounds ONE list.** An install with a shared lane *and* a coord leg has three lists
  and can return three capped ones, and a call passing `tag` adds a fourth. That is a bound,
  not a promise of the budget.
- **`cards_window` is how a capped read says it is capped.** `total` is the population
  **before** the cut — that is what makes `truncated` worth reading. ⛔ **Never treat a
  `truncated: true` list as the whole board**, exactly as you must never treat a truncated
  description as the whole scope.
- **A truncated window names its own remedy — `remedy`, present only when `truncated` is
  `true` (card#10150).** It is a sentence naming the argument that gets the rest, so a caller
  whose channel-server tool schema predates `stage` and `limit` can still act on a capped read
  the same turn: the bridge accepts those arguments from any snapshot, and only the tool
  description that advertises them is per-seat and versioned. Whether a client on an older
  snapshot sends a key its own schema lacks is not measured (DL-426). What it names depends on
  the list: your own lane, the shared lane and `tag_cards`
  name `stage` and `limit`; a list already narrowed by `stage` names `limit` alone;
  `coord_cards_window` names `limit` alone, because `stage` does not reach the coordination
  board. An untruncated window carries **no** `remedy` key — not a null one. **When your reported
  `client_version` does not declare an argument the remedy names, the remedy still names it and
  ends with the client-update sentence for it** (operator ruling on card#10566; see
  [§ A refusal or a truncated window can tell you to update your channel client](#a-refusal-or-a-truncated-window-can-tell-you-to-update-your-channel-client-dl-426),
  whose rules for no usable version and for an unreadable table apply unchanged). ⚠ The sentence is
  for a reader; branch on `truncated`, never on the wording.
- **Narrow with `stage` before you raise `limit`.** `stage` answers about one column, and
  `total` then reports **that column's** size. Raising `limit` grows the response in
  proportion to the cards it lets through; it is the deliberate escape hatch for a caller
  that genuinely needs a whole lane, not the routine path.
- **Which cards you get is deterministic: the NEWEST — the highest card ids — emitted in
  the board's own answer order.** Card ids are allocated globally and monotonically, so
  the highest ids are your most recent work. ⛔ **The first cut of this kept the OLDEST
  and was wrong in a way worth stating**, because a bounded response that answers the
  wrong question is still an unusable tool: on any board with a terminal column the
  default read came back as 52 finished cards, with the live column absent from
  `cards_by_stage` *entirely* — the seat could see neither its work nor the fact that the
  column existed. Descending keeps every property that mattered: a total order over a
  monotonic key, so two identical polls answer the same set and merely touching a card
  never reshuffles it. A row carrying no readable id sorts **last** (it is still counted
  in `total`). A list that was *not* cut is byte-identical to what this tool returned
  before the cap existed.
- **`board_stages` names every column of your board, on every response, in the board's own
  column order** (kanban's `position` — not the order the board's workflows happen to be
  assembled in). The escape hatch has to be reachable from the response that advertises
  it: `cards_by_stage` carries only the columns the *returned* cards sit in, so a cut can
  hide the very column name `stage` needs. Without this list, enumerating your own board
  meant sending a deliberately invalid `stage` and reading the refusal. A column whose
  `position` the bridge could not read is listed **last**, never dropped.
- ⚠ **An EMPTY `board_stages` does not mean your board has no columns.** It also happens
  when the bridge's board-structure read degraded, and the tool cannot tell the two apart
  — the same read that fills this list is the one Decision 9's *stage id taken unverified,
  stage NAME refused* rule fires on, and the bridge logs the degradation on its own side
  (a `Log::warning` naming the read and the board). ⛔ **So an empty list is not evidence
  about your board**: if you see one, pass `stage` as a numeric id if you know it, and tell
  your operator the structure read came back empty. There is deliberately no
  `board_stages_observed` flag, because nothing this tool can see would distinguish the two
  states — it would be the list's own emptiness restated as a measurement.
- **The cap bounds the RESPONSE, not the bridge's reads.** The bridge still pages the whole
  lane out of kanban — `total` has to be the real size for `truncated` to mean anything.
- **`stage` is refused rather than guessed.** An ambiguous name (two columns whose names
  differ only in case, say) is a 422 naming the candidates; so is a name that matches
  nothing, and so is a numeric id your board does not carry. A guessed column would answer
  about a *different* column and the answer would look exactly like a correct one. ⚠ On a
  board whose structure this bridge could not read at all (an empty stage map — logged
  upstream), a **numeric id is taken unverified** and a **name is refused**, saying so:
  validating an id against an empty map would turn a degraded read into a dead argument.

### Cards carrying a tag, in any lane (`tag`, `include_terminal`)

⛔ **Your lane read cannot tell you that none of your cards carry a tag.** It searches your
lane, so a card in another lane or in **no lane** was never in what it read. Measured
(card#9260): a seat read its lane, found no `lane:A` cards and reported its sprint empty while
three `lane:A` cards sat at `swimlane_id: null`. Nothing in that response could have said so.

`tag` reads your board by tag instead (card#9260, DL-383). What `tag_cards` holds:

- **The population** — every live card on your configured board carrying the tag, **minus
  terminal columns** unless `include_terminal: true`, **narrowed by `stage`** when you pass it.
  `cards_window.total`, `other_swimlanes` and `no_swimlane` all count that one population.
  - **Terminal** is the board's own declaration, and **which declaration answers depends on the
    board**: kanban ships a per-stage `is_terminal` flag (kanban DL-281, v0.47.0) that is
    deliberately *not* `lane_type: done`. A board that flags **any** stage has opted in and
    answers with **exactly its flagged stages** — a flagged column that is not typed `done` is
    terminal, and a `done` column it declined to flag is **not**. A board that flags **none** has
    opted into nothing (nothing backfills the flag, and that is the ordinary state), so its
    `lane_type: done` columns stand in, exactly as before the flag existed. `terminal_basis` says
    which answered — `"is_terminal"` or `"lane_type"` — and `excluded_terminal_stage_ids` lists what
    was left out. ⛔ **A third value, `"stages_unreadable"`, means NO declaration answered**: the
    board read came back carrying no columns at all, so nothing could be excluded. **It is not a
    board that flags nothing** — a board that genuinely has no columns still reads `"lane_type"` —
    so read it as *unknown* and never as *unflagged*. It is not the writeback's terminal
    rule, and a board that declares nothing terminal by either route has none.
  - A `stage` that names a terminal column is **refused** without `include_terminal: true`,
    rather than answering an empty block. ⛔ The refusal is the exclusion set read back, so it
    follows the same declaration — it names which one in its message. **`terminal_basis` is
    reported even under `include_terminal: true`**, where the exclusion is empty: it describes
    the board, not the call's narrowing.
- **`cards`** — capped by `limit` like every list here, newest kept, with `cards_window`.
  **Each card carries `swimlane_id`**: a lane id, or **`null` when the card is in no lane**.
  A card whose row carried no readable lane field has **no** `swimlane_id` key, never a null
  that would call it laneless. `cards_window.total_is_lower_bound` is `true` when the tag read
  stopped at the bridge's page ceiling (`KanbanClient::MAX_PAGES` × `SEARCH_LIMIT` rows): `total`
  then counts only the rows read, and both counts are `tag_read_incomplete`.
- **`other_swimlanes` / `no_swimlane`** — how many of the population sit in a lane other than
  your configured `swimlane_id` (your shared lane counts as another lane), and in none.
  ⛔ **A `null` is not zero.** Each count is kanban's own answer to its `swimlane_id=` filter,
  and it is reported only when the bridge can stand behind it. Otherwise it is `null` and
  `<key>_unmeasured` names why:

  | `*_unmeasured` | Meaning |
  | --- | --- |
  | `server_filter_unconfirmed` | (`no_swimlane` only) your kanban's search does not say when a term falls back to free text — it is **older than kanban v0.43.0**, and so also too old to know `swimlane_id=none` — so no laneless count was asked: its answer could not be told from a word search. |
  | `server_filter_not_honoured` | kanban searched a term of the count as free text. On `no_swimlane` this is **kanban v0.43.0–v0.44.x**, which does not know `swimlane_id=none`: it searches the word instead, and its `0` is not your answer. |
  | `server_total_absent` | the count response carried no `meta.total`. |
  | `disagrees_with_rows` | kanban's count and the lane fields on the rows this call read do not agree. |
  | `board_swimlanes_unreadable` | (`other_swimlanes` only) the board structure read carried no lane list to count against. |
  | `tag_read_incomplete` | the tag read stopped at the page ceiling, so its rows are not the whole population and no count over them could be checked. No count search was sent. ⚠ **No argument recovers it:** `stage` narrows the tag rows *after* they are read, so a narrowed call hits the same ceiling — a tag carried by that many live cards is an operator question, not a caller one. |

  Before the laneless count, the bridge sends your board one bare-word search and reads only
  whether kanban says it ran as free text: that is what makes kanban's silence on the count
  itself mean `none` was applied. And a count reaches you only when it also equals the tally of
  the same lane over the tag rows. ⚠ That check is not a second reading of the TAG: kanban's
  count and the rows both come from its own `tags:"…"` match and differ only on the lane, so
  the check stands behind the lane count, never behind the tag match. A board with no lane but
  yours answers `other_swimlanes: 0` without a count search, still checked against the rows.
- ⚠ **This read crosses the lane boundary on purpose.** The bridge-enforced read isolation
  described below holds for your lane lists; `tag_cards` lists cards in other agents' lanes
  that carry the tag you name, on your own board. It is one exact tag: `*` (a wildcard to kanban),
  `"`, and `%` (a wildcard to a kanban older than v0.36.0) are refused. `_` is accepted — agent
  names carry it — and kanban v0.36.0 and later match it literally; an older kanban reads it as
  any one character.
- ⛔ **A tag an older kanban stores escaped is refused, not answered as empty.** Kanban stores
  tags as JSON, and a kanban **before v0.46.0** compares its exact tag match against that stored
  text, so there a tag containing a control character, `/`, `\` or any non-ASCII character (any
  byte ≥ 0x80) matches no card, even one that carries it. The read would answer `cards: []`
  beside counts of `0`, so the tool refuses the tag (422, before any board read) instead (kanban
  card#9522). From kanban v0.46.0 such a tag matches element-wise, but this read does not know
  which kanban answers it, so it keeps the refusal. `board_search`, which only answers kanban
  v0.47.0 or later, accepts these tags.
- **Cost:** a call with `tag` adds the tag read (paged) and one-row searches — the
  `other_swimlanes` count, the free-text disclosure check, and the `no_swimlane` count. The
  board structure read is the one the default call already makes.
- ⛔ **Without `tag`, nothing changes:** the same keys and values, from the same requests (a coord
  leg's tag search now also sends `page=1`), and no `swimlane_id` on the lane cards.

### Where these cards are (`board_id` vs `configured_board_id`)

**⚠ `board_id` is WHERE THE ROWS ARE, read off the rows themselves — not where you
are configured to read (card#7295, DL-302).** Before this it was the configured
value restated, for a row set whose own board nothing had checked, so a window of
foreign rows would have reported this board's id as fact. What a caller gets now:

- **`board_id` + `board_observed`** are the reading, taken over **every row this call
  read** — before the `stage` filter and before the cap. That is deliberate and it is the
  population this axis had before either existed: it is a defence-in-depth report against
  a window of foreign rows being reported as your board, so narrowing it to the rows that
  survived would assert your board as *fact* over a window whose hidden rows disagree,
  and would silence the multi-board warning for everything past the cut. ⚠ A consequence
  worth knowing: a foreign row you never see can still unobserve your window. `true` ⇒
  every row read (your lane **and** the shared lane) reported that same board. `false` ⇒ **`board_id`
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

- **Default (no argument): the two keys are ABSENT**, not null — they are the only
  conditional part of the card shape. ⛔ **This bullet used to claim the projected CARD was
  byte-identical to what it was before the argument existed, and that claim is RETIRED
  (DL-372), not re-scoped:** `assigned_user_id` joined every projected card unconditionally,
  so the card is no longer the DL-217 one. What holds is the weaker, true statement — every
  key this tool has ever emitted is still emitted, with the same meaning. The **response**
  was never covered by the old claim either: it had already grown the DL-302 board keys and
  card#8985's window blocks.
- **Opt in when you are STARTING a card, not when polling.** A body runs ~2 KB and
  *every* card in your lane is returned, so a large lane multiplies the response
  many times over. The bridge pays nothing extra to fetch it (the kanban search row
  already carries `description`; the projection used to discard it) — the whole cost
  is response size, and it is yours to spend deliberately.
- **A cut body is flagged, never silently short.** Each body is cut at the
  per-agent `board_tools.description_max_bytes` (default 16384) and a card that was
  cut carries `"description_truncated": true`. **Never treat a truncated body as
  the whole scope** — re-read the card on the board instead.
- **The cap is per CARD, not per response.** It bounds one pathological body; it does not
  bound the total, which is `cards returned × their bodies`. Lower the key on a large
  board, raise it if your scope statements are longer than 16 KB. ⚠ Since card#8985 the
  **count** of cards returned is bounded separately (§ The default is capped) — so the two
  caps multiply to a real ceiling, which neither did alone.

Read isolation is **100% bridge-enforced**. All agents on an install share one
kanban read/write user, and kanban scopes reads by that user's *board*
membership, never by swimlane — so the boundary keeping you out of another
agent's lane is your `board_tools.swimlane_id` config plus a fail-closed row
filter: every returned row is re-checked against your configured swimlane and any
non-matching row is **dropped and logged**. The upstream `swimlane_id=` search
term is efficiency + defense-in-depth, not the boundary. ⚠ **The `tag` read is the
deliberate exception** (DL-383): it lists the cards on your board carrying the one
tag you name, in any lane — see § Cards carrying a tag, in any lane.

### An empty window is not always an empty lane

An empty answer is only given once the board is shown readable (§ `board_my_cards` above), so the
membership gap is no longer one of its causes; the two below still are.

⚠ **Check the bridge log before you believe an empty answer.** When kanban
answers `200` with a body carrying no card collection at all, the read degrades
to "no cards" and the tool answers exactly as it does for a lane that genuinely
holds none. Since card#8586 that degradation is **not silent**:
the bridge writes a `Log::warning` naming the read, the board and the page (*"the
swimlane-search … read returned a 200 whose body carried no card collection"*), which
says kanban's response shape may have changed or that something other than kanban — a
proxy, an auth portal — answered the URL. ⛔ **The converse is not covered and is not
claimed:** a kanban that dropped or renamed the `swimlane_id=` filter still answers a
well-formed empty collection, which the bridge cannot tell from a genuinely empty lane
(`docs/kanban-integration-contract.md` §2 owns that hazard, on the far end).

⚠ **The `tag` read pages through the same walk.** Your lane lists, the coord cards and the tag
read all page through `KanbanClient::pagedSearch()`. A page answered `200` with no card collection
in its body adds no rows, logs the warning above for that page (*"the tag-row-search lane:A page 2
read returned a 200 whose body carried no card collection"*), and ends the walk. What happens next
depends on the page:
- **A LATER page.** The first page declared how many cards the search matches (`meta.total`; every
  kanban answer does), and the walk now holds fewer. It reads the whole search once more, and if
  that falls short too the call fails with the `502` `upstream board error` a board 5xx gets
  (card#10653). It is never answered as a complete but shorter window.
- **The FIRST page.** Nothing was read and there is no total to check against, so the window is
  empty, exactly as a lane or tag with no cards is. The bridge log is where this shows.

Kanban's own search endpoint answers every page as a paginated collection carrying both `data` and
`links`. A page with neither means something in front of kanban answered.

## `board_create_card`

**Arguments:**

| Arg | Required | Notes |
| --- | --- | --- |
| `title` | yes | Non-empty string, **stored trimmed**, **≤ 255 characters** (kanban's `name => string\|max:255`; an over-long title is **refused** (422) before any request is sent — card#8486, the same bound `board_correct_card` puts on `name`, through the same primitive). A title that is blank once trimmed — including one made only of invisible characters — is **refused**. ⚠ The cap reads the value **as sent**, padding included (see the normalisation rule at the top). |
| `description` | no | String, **trimmed**. A description that is blank once trimmed is treated as **absent**: no `description` is written at all (a card being born has nothing to clear). |
| `tags` | no | List of strings, each **trimmed** and **≤ 64 characters** (kanban's `tags.* => string\|max:64`; an over-long tag is **refused** (422) before any request is sent). An entry that is blank once trimmed is **refused**. Reserved prefixes (`created-by:`, `idem:`, `id:`, `type:`) and the bare tag `triaged` are **refused** (422), matched **case-insensitively** — `IDEM:`/`Triaged` are rejected too: whether the kanban tag search folds case is a per-driver collation fact, so the guard refuses every case variant rather than betting on the deployed collation. Every tag must also be **printable ASCII with no tag-search metacharacter** (`"`, `*`, `_`, `%`); non-ASCII or metachar tags are refused. Provenance/correlation/adoption tags are bridge-stamped, and `triaged` would defeat born-untriaged. ⛔ **The retired seat owner tag `owner:` is refused too** (card#10869, operator ruling B; case-insensitive), with a reason naming `board_take_card`: card ownership is the kanban assignee, and a caller-written tag would name a holder nobody is. (A non-reserved colon such as `priority:high` is fine.) |
| `idempotency_key` | no (recommended) | `[A-Za-z0-9.-]{1,64}`, **and at most `64 − length("idem:<you>:")` characters**: the key is stored in the tag `idem:<you>:<key>` and kanban caps every tag at 64 (`tags.* => string\|max:64`), so the prefix your agent name makes comes out of the key's length (card#9588, DL-394). A longer key is **refused** (422) before any request is sent, naming your cap and the key's length; an agent name so long that `idem:<you>:` alone fills the cap is refused as an install fault. Other characters are refused (they are kanban tag-search metacharacters that could correlate the wrong card). The key is **lowercased** before use, so it correlates case-insensitively (`Report` and `report` are the same key). |

Any other key — a `swimlane_id`, an `assignee` — is **refused** (422) before any request, and **no card is created**: see § [An argument the tool does not declare is refused](#an-argument-the-tool-does-not-declare-is-refused-on-every-tool-dl-379).

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
  ⚠ **A raced duplicate someone has PINNED is left alone (DL-340, card#8523).** The
  collapse is the writeback's shared kernel, and since card#8523 it refuses to archive
  a card carrying a non-empty `block_reason` or a `no-automove` tag — a human hold
  outranks a tidy-up, even one this tool's own call created. The surviving (lowest-id)
  card is still what the response names; you are simply left with two live cards for
  the key until someone resolves the hold.
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
    [§ A PERMANENT board 4xx is a refusal, on every tool](#a-permanent-board-4xx-is-a-refusal-on-every-tool-dl-339).

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

**Correct a card that is YOURS — one you filed, or one assigned to you** (DL-326, card#8378;
widened by DL-376, card#9201 / card#9202). Before this the impl seat's whole
board surface was **create + read**, so the only available response to a wrong card
was to mint a second one — and duplicates then defeat every downstream instrument
that keys on one card per subject.

**Arguments:**

| Arg | Required | Notes |
| --- | --- | --- |
| `card_id` | yes | A positive **integer** — the `id` `board_my_cards` reports. A decorated string (`"42"`) or a float is refused, never coerced: a coerced id names a different card, and this id selects the row the write lands on. |
| `name` | no | Non-empty string, **stored trimmed**, **≤ 255 characters** (kanban's own `name => string\|max:255`; ⚠ the cap reads the value **as sent**, padding included). There is **no clear form** — a card cannot be left without a name, so a `name` that is blank once trimmed, including one made only of invisible characters, is refused (omit it to leave it alone). |
| `description` | no | String, **trimmed**. **Present-and-empty CLEARS it**, and so does whitespace-only or invisible-characters-only (see the present/absent rule below). |
| `tags` | no | List of strings — **your** tags, each **trimmed** and **≤ 64 characters** (kanban's `tags.* => string\|max:64`); an entry that is blank once trimmed is refused. The same reserved prefixes (`created-by:`, `idem:`, `id:`, `type:`), the retired `owner:` tag and bare `triaged` `board_create_card` refuses are refused here too, case-insensitively, with the same printable-ASCII / no-metacharacter (`"`, `*`, `_`, `%`) charset rule. |

> ⚠ **The two length caps are a MIRROR of rules that live in the kanban repo** (`App\Support\TaskWriteRules`), held in one place here (`KanbanFieldLimits`) and stated as a mirror: they are a **diagnostic**, not the safety. The safety is kanban's own 422 — which the tool maps to a named refusal rather than the retryable 502 — so a cap that goes stale degrades the *message*, never the outcome. `board_create_card` shares both caps (one policy, both tools: `name`/`title` at 255 through `BoardCallRefusal::overLongName()`, every tag at 64 through `CallerTagPolicy`).

**One rule for every argument: a key that is PRESENT is a correction; a key that is
ABSENT leaves its field alone.** So `tags: []` means *drop my tags* (it is not the
same as omitting `tags`), and `description: ""` clears the body.

> ⛔ **`null` and `""` are ONE VALUE here, and that is a measured property of the door
> rather than a style choice.** Laravel's global `ConvertEmptyStringsToNull` (with
> `TrimStrings` ahead of it) rewrites `"description": ""` to `null` before the HTTP
> controller ever reads `args`, while the **ssh** door (`bridge:tools-call`) decodes
> the body itself and preserves it. Treating them as one value is what keeps this
> tool's contract identical on both transports — as does trimming with the framework's
> own primitive, so that a body of invisible characters CLEARS on both doors rather than
> being written on one of them (card#9155; the normalisation rule at the top of this doc). ⚠ This is why `board_create_card`'s
> rule (a present `null` reads as *absent*) is deliberately **not** copied — there,
> `null` cannot mean "clear", because a card being born has nothing to clear.

**⭐ Whose card — the scoping rule.**

A card is yours when **either** of two relations holds — and **both widen nothing a caller
can forge** (DL-376, operator-approved 2026-09-13; before it, only the first existed):

1. **You MINTED it** — it carries the tag the bridge stamps at create, **`created-by:<you>`**.
   It is caller-unforgeable (`created-by:` is a reserved prefix on create and the guard
   casefolds, so no caller can plant any case variant of another agent's stamp), and it is
   the **only per-seat provenance a card carries** — kanban's `actor_type: service` covers
   the bridge and every CLI writer, and `actor_id` names the shared writeback **user**, so
   neither can answer *which seat filed this*.
2. **It is ASSIGNED to you** — the card's own `assigned_user_id` is **identical, as an
   integer**, to your own kanban user, which the bridge resolves from **your bridge
   identity** — the coord roster's id for the seat of the agent the door authenticated
   (DL-450) — exactly as [`board_take_card`](#board_take_card) does. **No argument can influence which user is
   compared** — this tool accepts no user id, and the resolver takes none.

**Which relation authorized the write is recorded** — `authorized_by` in the response and in
the bridge's `board_correct_card: corrected` log line — because *who filed a card* and *who
holds it now* are different facts and an audit must be able to tell them apart. ⚠ **When
both hold, it records `minted`**: the stamp is checked first, and the assignee is not
consulted at all for a card you minted, so a correction you could make before DL-376 does
not start depending on the coord roster being readable. `minted` therefore
says nothing about who the card is assigned to.

- ⛔ **A row that says nothing readable about its assignee is never yours by assignment.**
  `assigned_user_id: null` means unassigned; an **absent** key, or a value that is not an
  integer (a digit string, a float, `""`), is a degraded read — it does not authorize, and
  the call gets the ordinary *"not one of yours"* refusal (it may still pass on the mint
  stamp).
- **If the coord roster gives your seat no kanban user id** (the seat is absent from it, or has
  no id for this kanban host), **the assignee relation is simply off** — no card can be assigned
  to a user you do not have — and the tool behaves as it did
  before DL-376 **except** that the *"not one of yours"* wording now names both relations and
  the tag-list rule below applies: cards you minted are corrected, everything else gets
  *"not one of yours"*.
- ⛔ **If the bridge cannot establish WHICH kanban user you are** — the roster gives your id to
  another seat this install serves, or the roster cannot be read — every correction that is not
  authorized by your mint stamp is refused with that **install fault**, including a call naming
  a card that does not exist, so the refusal says nothing about whether the card exists.
  Corrections of cards you minted are unaffected.
- ⚠ **A card you TAKE becomes a card you can correct.** [`board_take_card`](#board_take_card)
  assigns you any unheld card in a lane you work, so the assignee relation reaches every such
  card, not only work somebody else assigned you.

On top of the relation, the card must be established **on your board**, and two independent
narrowings are checked for that — **both** are required:

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
and the relation above is what says the card is yours; a lane test would make a re-laned
card permanently uncorrectable by the seat that filed or holds it. The response therefore
reports no lane — it reports only what was checked.

**⛔ A PINNED card refuses a `name` correction (DL-342, card#8557).** If the card carries
the DL-178 hold — a non-empty `block_reason` **or** a `no-automove` tag — a correction that
writes `name` is refused (422), by name, and **nothing at all is written**. The pin means a
human has frozen the card, and the bridge's own restamps are refused the same write on the
same card; a seat renaming it would defeat that hold exactly as a stage move would.

- **It is scoped to `name`, not to the call.** `description` and `tags` corrections still
  land on a held card, because the ruling narrowed the pin by one field rather than freezing
  every field — the same reading that keeps the writeback's correlation stamps working on a
  card it refuses to move.
- **⚠ A call that sends `name` ALONGSIDE `description`/`tags` writes NEITHER.** The
  correction is one `PATCH` and there is no half-applied form of it, so the refusal says so
  rather than leaving you to work out which half landed. Send the other fields on their own
  if you want them.
- **What to do:** ask whoever pinned the card to lift the hold, or correct the fields the
  hold does not cover. Retrying is pointless — the refusal is deterministic.

**⚠ `tags` is REPLACED WHOLESALE by kanban, so the write re-sends every tag on the card
that is somebody ELSE'S — and that set is deliberately WIDER than the set you are not
allowed to send.** Two halves, and the second is the one that is easy to get wrong:

1. **Tags you may not supply**, so you could not restore them either: `created-by:`
   (your mint stamp — dropping it locks you out of your own card), `idem:` (your
   correlation key — dropping it re-opens duplicate minting under it), `type:` and
   `triaged` (the triage pass's work), and the retired seat owner tag `owner:` (card#10869,
   operator ruling B) — on a card with no assignee it may be the only record of who holds the
   card, until `kbcard owner-migrate` turns it into an assignee.
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

⛔ **A `tags` correction on a card whose tag list the bridge cannot read IN FULL is REFUSED** —
the key absent, not a list, or a list holding any entry that is not a string. The preserved half
of the write is built from the string entries only, so a wholesale replace would delete every
entry the bridge could not read, holds and other agents' stamps included. A card assigned to you
is authorized without reading its tags, which is where this matters most, but a minted card whose tag list
is not a plain list, or holds any non-string entry, is refused the same way — including a keyed
object of strings, which was not destructive but is not the shape the preserve logic reads. `tags: null` (an untagged card)
is a real, empty list and is written normally; a `name`/`description` correction on the refused
card still lands.

`tags_written` in the response is what the PATCH **sent**, which is the only channel you
have to what was preserved.

**Returns:**

```jsonc
{ "corrected": true, "card_id": 42, "board_id": 10,
  "fields": ["name", "tags"],
  "authorized_by": "minted",        // or "assigned" — which relation made the card yours
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
| `priority` / `due_date` | not part of this tool's contract |
| `assigned_user_id` / `assignee` | **`board_take_card` claims a card for you, and it resolves WHICH user you are from your bridge identity — no tool on this door takes a user id as an argument** (DL-372). Named rather than left to the catch-all row below: a seat reaching for this key is reaching for the one value the take door will never accept from a payload, and *"unknown argument"* would read as a spelling mistake. |
| anything else | `unknown argument …`, naming the accepted set — **nothing is silently ignored** (§ [An argument the tool does not declare is refused](#an-argument-the-tool-does-not-declare-is-refused-on-every-tool-dl-379) owns the rule; the rows above only choose the sentence) |

⛔ **The offered set is deliberately NARROWER than `kbcard patch`'s corrective
setters** (`--type`, `--external-id`, `--origin` are refused here): **this tool never
writes a field `board_create_card` would refuse at birth.** A correction authority
wider than the create authority is a laundering route — mint a clean card, then
"correct" in the reserved `type:` key, `triaged`, or a payload key the create tool
rejects outright.

**Card-state refusals** (all 422, all writing **nothing**):

| State | Refusal |
| --- | --- |
| The card is not on your board, or is on it but neither carries your stamp nor is assigned to you (including an assignee the board did not return readably) | *"card N is not one of yours"* — **one message for every one of those**: you are never told whether a card you do not own exists. The message names both relations that would have made it yours. ⚠ It names a **further** cause too, because kanban's search FLOORS a caller to the boards its token is a member of and answers **200 with zero rows** for the rest: an unreadable board and an empty one are one answer here (DL-323's `mapped_board_unreadable_to_this_token`), so the message tells you to have the token's board membership checked if you believe you filed or hold the card. |
| Your own kanban user cannot be established, and the card is not one you minted | The resolver's **install fault** (an id the roster gives another seat this install serves, or a roster that cannot be read) — the same sentence whether or not the card exists, so it discloses nothing. A seat the roster gives **no** id is not in this row: it gets the ordinary *"not one of yours"*. See the scoping rule above. |
| The card is yours and **ARCHIVED** | Named as the retire it is (*"unarchive it first"*) — the stamp or the assignment proves the card is yours, so naming it discloses nothing, and the alternative is a guard telling you a card you demonstrably filed or hold is not yours. The archive side is read **only when the live lookup misses**, so a successful call never pays for it. |
| You are correcting `tags` and the board's tag list for the card cannot be read in full | *"no readable tag list"* — **install fault**; a wholesale replace would delete tags the bridge cannot read (above). `name`/`description` are unaffected. |
| The lookup answered a row that is not that card on your board | *"a BROKEN READ, not a verdict"* (DL-323 Decision 2) — report it; it is not a statement about the card. |
| `writeback.json` will not parse | The install's hold vocabulary is unknown, so a **`tags`** correction is refused (see above) — **install fault**. `name`/`description` are unaffected. |
| The card is **PINNED** and the correction writes `name` | *"card N is PINNED"* — a human froze it with a `block_reason` or a `no-automove` tag, and a `name` write is one of the writes that hold covers (DL-342; the bridge's own restamps are refused the same write on the same card). **Nothing at all is written**, including any `description`/`tags` sent in the same call, because the correction is one `PATCH` with no half-applied form. Not an install fault: ask whoever pinned it, or correct the fields the hold does not cover. |
| kanban answered **403** on the lookup | The bridge could not read your board to establish the card is yours — an **install fault**, and specifically the writeback token's **abilities** (kanban gates the v3 API per token: a GET needs `read`). ⛔ Deliberately **not** board membership — **because this lookup is a card SEARCH**, which kanban floors to the caller's own boards: an unreadable board answers 200-with-zero-rows, never 403, so it surfaces as the not-yours refusal above. (A **board-scoped** read *does* 403 on membership — see the owner section below; this tool makes none.) |
| kanban answered **403** on the write | The card is yours but the writeback user may not write it — **install fault**, and **several independent gates answer 403 on this route, so every one must be audited** (`BoardCallRefusal::writeGatesClause()` enumerates them — including kanban's board write gate, which refuses every write to an archived or trashed board): the token's per-token **abilities** (`EnforceTokenAbilities` — a PATCH needs `write`), and the writeback user's **board role**, which needs **`task.update`** — kanban authorizes a PATCH by the fields it carries, so anything other than `workflow_stage_id` alone is an `update`, not a `move` (kanban DL-204 → `TaskPolicy::update` → `BoardPermissions::TASK_UPDATE`, an independently grantable `board_custom` slot in `CUSTOM_TASK_SLOT_MAP`). ⚠ **`task.update` is NEW for the board-tools door** — `board_my_cards` needs only `board.view` and `board_create_card` only `task.create` — so an install granting exactly those 403s here with a perfectly valid token. A **Member**-role writeback user already holds it. See [`writeback.md` § 1](writeback.md#1-a-least-privilege-writeback-token) for the full grant list. |
| kanban answered **401** on either call | The token was not accepted at all — revoked, rotated, or replaced with a value the board does not know. **Install fault**; retrying cannot help. |
| kanban answered **404** on the write | The card stopped existing between the ownership check and the write. **Nothing was written.** |
| kanban answered **422** on the write | The board refused a value in the write. Deterministic, so it is a refusal and not the retryable 502. The refusal says the bridge's own length checks passed, so it never tells you to shorten a field they cover, and it ends with **the board's own reason**, redacted and bounded — see [§ What a board 422 relays](#what-a-board-422-relays-dl-384) (DL-384). |

⚠ **Those board-caused 4xx (401/403/404/422) are reported as 422 refusals, not as the
retryable 502**, because they fail identically however many times you send them; a 5xx
still answers **502**, and so does a call kanban never answered (a timeout or a failed
connection) — that is the one you may retry. Since card#8486 that
is the rule for **every** tool on this door, not this one's alone —
[§ A PERMANENT board 4xx is a refusal, on every tool](#a-permanent-board-4xx-is-a-refusal-on-every-tool-dl-339)
owns it, and the rows above are what it means for a *correction* specifically.

**Cost:** two requests on a successful call (one board-scoped lookup, one PATCH) —
no card read-back, because the row that authorized the write already carried what the
response reports. A not-found refusal costs two reads and no write. A call that is not
authorized by the mint stamp also reads this bridge's own agent roster (a local file read,
not a board request) to resolve your kanban user.

## `board_take_card`

**Claim a card for yourself** (DL-372, card#9170). A card whose **column never moved** is
indistinguishable from an unclaimed one, so two seats pull the same work and neither finds
out. The board's native `assigned_user_id` is the answer to that, and until this tool the
only writer of it was a human at a terminal — so every impl seat's claim had to route
through the one privileged seat, which is the serial hub this door exists to remove.

**Arguments:**

| Arg | Required | Notes |
| --- | --- | --- |
| `card_id` | yes | A positive **integer** — the `id` `board_my_cards` reports. A decorated string (`"42"`) or a float is refused, never coerced. |
| `start` | no | A **boolean**. `true` STARTS the card — moves it to In Progress and assigns it to you in one write (§ [The start form](#the-start-form-start-true-card11150--dl-449) below). `false` or omitted is the plain claim, which never moves the card. A string, number or `null` is refused, never coerced. |

> ⛔⭐ **THERE IS NO ARGUMENT FOR THE USER, AND THERE NEVER WILL BE.** The assignee is
> resolved **server-side** from the coord roster — the kanban user id of YOUR seat
> (DL-450), for the agent name the DOOR derived from your bearer (HTTP) or from the pinned
> forced command (ssh). Nothing that travelled in your request can influence it.
>
> That is a **construction**, not a validation, and the difference is the point: this door
> is driven by a lower-trust principal, so a tool that accepted an `assigned_user_id` —
> even a validated one — would put *"a seat may claim only for itself"* one forgotten branch
> away from false, and one seat could assign work to another or impersonate a take. Here
> there is no expressible call that writes another seat's id.
>
> `card_id` and `start` are the whole accepted set, so **every** other key is refused (422) **before any
> board request is made** — never silently ignored, which would leave you believing you had
> assigned somebody. The user-naming spellings the tool enumerates (`assigned_user_id`,
> `assignee`, `user_id`, `kanban_user_id`, `agent`, and the rest of `USER_NAMING_ARGS`) are
> refused in a sentence that **names the key** and says why it will never exist; anything else
> — `owner`, `assigned_to`, a padded spelling — is refused as an unknown argument, with the
> reminder that the assignee is resolved from your bridge identity, never from your arguments,
> and the accepted set named. The list changes the message, not the
> outcome (§ [An argument the tool does not declare is refused](#an-argument-the-tool-does-not-declare-is-refused-on-every-tool-dl-379)).
>
> **Assigning work to a DIFFERENT seat is not something any board tool can do.** That is
> your operator's, with `kbcard patch --assign <seat>` on a box that holds the seat map.

**⭐ Which cards you can take — the scoping rule, and it is NOT the correction tool's.**

`board_correct_card` scopes on a card being ALREADY yours — minted by you (`created-by:<you>`)
or, since DL-376, assigned to you. A take is the opposite case: **the work somebody else
queued for you, and that nobody holds yet, is exactly what you are claiming**, so neither
relation is consulted. Two independent narrowings are checked instead, and **both** are required:

1. **The card is on your configured board.** Established through a **board-scoped** search
   (`q=board_id=<yours> id=<n>`), with the verdict read off the returned **rows** — never
   off the fact that a scoped query was sent, because kanban drops a term it does not
   recognise and still answers 200 (DL-323). The unscoped `GET /tasks/{id}.json` is never
   used: your `card_id` is caller-supplied against an id space that is **global across every
   board on the instance**.
2. **The card is in a lane you work** — your own `swimlane_id`, or the configured
   `shared_swimlane_id`. ⚠ That is the same **lane scope** `board_my_cards` reads, but it is
   **not the same set of cards**, in either direction: `board_my_cards` **caps** its response
   by card count (card#8985), so a card it did not list can still be takeable; and a card it
   **does** list can be **refused** here because another user holds it and it is finished. What
   makes the scope legible is the lane you work, not the listing you got.

> ⛔ **Coordination cards are OUT of scope.** They live on a separately configured board and
> are addressed by TAG rather than by lane, and reaching them would put a write on a second
> board this door has never written to. Narrowing later is not available in the way widening
> later is.

**⚠ A card another user holds is WARNED about, then TAKEN — never a finished one (card#10869 / DL-439; operator rulings on card#10868, Q3 and 7624).**

Until card#10869 this tool refused a card held by a different user. The operator ruled that an
agent claiming a card another user holds warns, then takes it, and posts a card comment naming
the holder it replaced — the toolkit's card-start claim does the same. So:

- **The holder is the card's assignee** — another seat's kanban user or a person. With **no**
  assignee, another seat's legacy `owner:<project>/<seat>` tag counts as the holder (the
  migration fallback, read until `kbcard owner-migrate` reports no tag-only card; a tag whose
  seat part is **your** seat name — `identity.coord_seat`, else your agent name — may be your
  own and is not treated as another holder).
- **Named first, then written, then read back.** The bridge writes a durable log line naming the
  holder **before** the assignment PATCH (a call cut off after the write still leaves the record),
  sends the one-field PATCH, and re-reads the row. Only a re-read naming **you** gets the card
  comment (`<agent> (kanban user N) took this card over from …`), posted as the writeback user —
  a comment naming a replacement that did not happen would be the one false record here.
- **The response says so:** `replaced` (`{assigned_user_id, owner_tags}`), `warning` (read it —
  that holder may still be working the card; talk to them), `takeover_confirmed`, and
  `takeover_comment` (`posted` / `failed` / `not_attempted`). A lost race answers
  `takeover_confirmed: false` with `board_now_names`, and no comment. `taken` stays `true`: the
  write was accepted.
- ⛔ **Replacing the ASSIGNEE of a card in a FINISHED column is refused, and nothing is written.**
  Done, Won't Do, Shipped to dev and Shipped to main: the assignee of a finished card is the record
  of who did the work,
  and replacing it needs an explicit steal, which this door does not have —
  `kbcard patch --assign <seat> --steal` is where it lives. The bridge knows those columns by the
  **union** of your install's `writeback.json` mapping on this board (its `merged` /
  `merged_to_main` stages and every column the board places at or past them) and the board's
  own declaration (`is_terminal`, else `lane_type: done`) — the reference board does not
  declare Shipped to dev done, which is why the mapping is needed. ⛔ **A held card whose column
  cannot be SHOWN to be unfinished is refused too**: `writeback.json` will not parse, no mapping
  on this board maps `merged`, the board's columns cannot be read, or the card's column is not in
  the board's order. An install with no writeback mapping on the board-tools board therefore
  still refuses every takeover of an ASSIGNEE, as it refused every held card before. ⚠ **A
  tag-held takeover is NOT column-gated**, because it replaces no record: the take writes
  `assigned_user_id` alone and the `owner:` tag stays on the card. (Before card#10869 such a card
  was taken silently; now it is taken with the warning and the comment.) ⚠ `board_my_cards`' own terminal exclusion uses the
  board's declaration alone, so a Shipped-to-dev card can be "current" there and "finished" here.
- The takeover costs two extra board reads (the board's columns, once for the structure and once
  for the order) and one card comment; `comment.create` is needed for the comment (without it the
  takeover lands and answers `takeover_comment: failed`).

**⭐ Re-taking a card you already hold SUCCEEDS and writes nothing.** The board already says
what the call is asking it to say, so refusing would make a retry-safe operation fail on its
own success. The response carries `already_held: true`, and no PATCH is sent.

**⛔ A row that says nothing about who holds it is REFUSED.** `assigned_user_id: null` is a
real value meaning *unassigned* and is the ordinary case. An **absent** or unreadable field
means this call cannot tell an unclaimed card from one another seat is working, so it
refuses rather than risk overwriting a claim — an install fault, named as one.

**⭐ Your kanban user id has ONE source: the coord roster** (card#11172 / DL-450) — the
`roster[].kanban_user_id` of your seat, for this install's kanban host, in the file
`BRIDGE_COORD_CONFIG_PATH` names. `identity.kanban_user_id` in an agent YAML is retired and is
never read, not even when the roster cannot answer. Every state in which the roster cannot name
your id is refused (422) **before any board request**, as a named install fault with its own
`reason` (the codes table under [the start form](#the-start-form-start-true-card11150--dl-449)):
the setting unset or relative, no file there or one the receiver may not read, a path that is not
a file the bridge will read (a symlink, a directory, past the size bound), a file that is not
JSON, your seat absent from it, or no id for this host. ⛔ `identity.peer_kanban_user_id` — the
attribution-only id of an agent that is no seat of this roster — is NEVER take, start or
correction authority. **⛔ The id must also be YOUR SEAT'S ALONE, and this tool is where a shared
one stops.** If the roster gives your id to another seat this install serves, every call from
either seat is refused (`install_fault.shared_kanban_user`) — because an id that names two
seats does not say WHICH seat holds the card, and a claim recorded under it tells every other
seat that *somebody* holds the work without saying who, which is the one question this tool
exists to answer. Two bridge agents serving ONE seat are not that fault while only one of them
has board tools; when MORE than one board-tools agent serves the same seat (a copied
`identity.coord_seat`, typically) every take from each of them is refused the same way, because
the id then cannot say which agent holds the card. ⚠ **Sharing a kanban
user between seats is an install fault with no supported form** — unlike `github_user_id`, it
cannot be declared deliberate. [`config-schema.md` § `identity:`](config-schema.md#identity-optional-mapping--the-agents-own-immutable-github-ids-and-its-coord-seat)
owns the roster shape, the seat rule and the reasoning; it is not restated here. `bridge:check`'s
`agent.kanban_user_roster` leg reports every one of these ahead of time.

**⚠ A PINNED card still takes a claim, and that is a ruling.** The DL-178 hold governs a
card's stage, its lifecycle and the fields `PinGuard::PINNED_FIELDS` names — which is
`name` alone. The hold exists so a card stops changing **under** the operator through
automation with no human in the loop; a take is a deliberate claim made by the caller, and
knowing who is looking at a frozen card is useful rather than harmful.

**Returns:**

```jsonc
{
  "taken": true,
  "card_id": 42,
  "board_id": 10,             // observed: the row was accepted only because it carried this
  "swimlane_id": 4,           // observed: the lane the card was accepted in
  "assigned_user_id": 815,    // YOUR id, from this bridge's config for your agent
  "already_held": false       // true ⇒ you already held it and NOTHING was written
}
```

### The start form (`start: true`, card#11150 / DL-449)

**Start a card: one write moves it into In Progress AND assigns it to you.** A card your branch
push moves is moved by the writeback, which names no seat (the push comes from one shared GitHub
account), so it lands In Progress with nobody on it and the bridge can only alert
(`owner.moved_without_owner`, [`writeback.md`](writeback.md)). You know you are starting, so the
start is where the owner is recorded: kanban v0.49.0 and later applies a column change and the
assignee in **one transaction**, so this never leaves a card moved but unowned. The later push
finds the card already In Progress and assigned, which is the writeback's existing no-op.

**Which columns.** Both come from this install's `writeback.json` mapping(s) on your board — no
new configuration:

- **In Progress** is the mapping's `stages.started`. No mapping on your board maps `started` (or
  `writeback.json` is missing or will not parse, or two mappings name different `started`
  columns) ⇒ **refused, nothing written**, as an INSTALL fault.
- A card is **start-eligible** in a `started_from_stages` column — exactly the columns the
  writeback's own `started` move promotes a card from (DL-160). One write: `workflow_stage_id` +
  `assigned_user_id`.
- A card **already In Progress** is assigned with no move (`moved: false`); one you already hold
  there writes nothing.
- **Every other column is refused, nothing written** — a finished one by name (the same
  finished set the takeover uses, below), and any other (Backlog when it is not a
  `started_from_stages` column, In Review, …) because the writeback refuses to drag a card
  there too. ⛔ `unpark_from_stages` is **not** start-eligible: the writeback moves a parked card
  only by overriding a human hold and alerting (DL-194), and this door does not override.
- A row naming **no readable column** is refused, nothing written.
- ⛔ **A PINNED card is not moved.** A `block_reason` or `no-automove` holds the card's column,
  and the writeback's `started` move is refused on it, so a start from a `started_from_stages`
  column is refused. A take **without** `start` still claims it where it is, and a pinned card
  already In Progress is still assigned (the pin holds the column, not the claim).
- ⛔ **A `program` parent is not moved either** (`program_parent`): the writeback writes nothing to
  a parent card (DL-403), its `started` move included.

**Another holder** is handled exactly as the plain take handles one (the takeover rules above):
named in the log before the write, an assignee replaced only outside a finished column, and a
card comment naming them once the read-back confirms the start.

**⛔ A 2xx is not a start.** After the write the bridge reads the card back and answers success
only when the board now says **In Progress AND you**. Anything else is **refused** (`not_stored`)
naming what the board stored. A read-back that does not answer — the read failed, answered a row
that is not this card, or the card is no longer live — is **refused** too (`not_confirmed`): whether
the start landed is unknown, and a start is never answered `ok` on an unverified write. Calling
again is safe, because a start that landed answers `already_held: true` with nothing written. On a
takeover both refusals name the holder the write was sent over, so it is never lost.

**Returns** (beside the plain take's keys):

```jsonc
{
  "moved": true,          // THIS call moved the card into In Progress (false: it was already there)
  "assigned": true,       // THIS call wrote you as assignee (false: you already held it)
  "replaced": null,       // or {assigned_user_id, owner_tags}: whom this call took the card from
  "from_stage_id": 47,    // the column the card was read in
  "stage_id": 49          // In Progress: the column the read-back confirmed (or, when nothing
                          // was written, the column the card was already in)
  // on a takeover, also: warning, takeover_confirmed (always true on a start — an unconfirmed
  // start is refused), takeover_comment
}
```

**Refusal codes.** A start's refusals — and every `ci_await` / `ci_await_cancel` refusal — carry a machine-readable `reason` beside `error` in the
`{ok: false, error, reason}` body — branch on it, never on the wording. Which refusal sites a test
holds to carrying a code, and that check's bounds, are stated in ONE place:
`BoardTakeCardRefusalReasonCoverageTest`'s class docblock — read it there; it is not restated here.
The table below is the source of the codes' VALUES: the same test reads it and fails on a code no
`app/` literal spells.

| `reason` | Where | Means |
| --- | --- | --- |
| `bad_request` | the door, any tool | the request body is not a JSON object, is not labelled JSON (HTTP), names no `tool`, or (ssh) could not be read from stdin |
| `unknown_tool` | the door, any tool | no such tool |
| `bad_arguments` | the door and every tool | an undeclared argument, a malformed one (`card_id` not a positive integer, `start` not a boolean, a `ci_await` `repo` not `owner/name`, a `head_sha` not a full 40-hex SHA, `pr` not a positive integer), or a value over kanban's own bound |
| `out_of_scope` | `board_take_card` | not a card on your board in a lane you work (or a board the writeback token cannot see — one answer) |
| `archived` | `board_take_card` | the card is archived |
| `holder_unreadable` | `board_take_card` | the row says nothing readable about who holds the card |
| `broken_read` | every card-id tool | the board-scoped lookup answered a row that is not this card |
| `board_read_failed` | every tool | the board refused a read permanently (401/403/404); an INSTALL fault |
| `column_unknown` | `board_take_card` | the card's column cannot be read, or cannot be shown not to be finished |
| `finished_column` | `board_take_card` | the card is in a finished column |
| `not_start_eligible` | start | the column is neither a `started_from_stages` column nor In Progress |
| `pinned` | start | a pinned card would have to move |
| `program_parent` | start | a `program` parent card would have to move |
| `not_stored` | start | the board answered 2xx and the read-back shows something else |
| `not_confirmed` | start | the board answered 2xx and the read-back did not answer (it failed, was a broken read, or the card is no longer live): whether it landed is unknown (calling again is safe); a takeover's names the displaced holder |
| `card_gone` | `board_take_card` write | the card was removed between the check and the write |
| `board_rejected` | `board_take_card` write | a board 422 (an enforced WIP limit on In Progress is one) |
| `install_fault.write_forbidden` | `board_take_card` write | 403 on the write |
| `install_fault.token_rejected` | `board_take_card` write | 401 on the write |
| `install_fault.start_unmapped` / `install_fault.start_ambiguous` | start | no mapping on the board maps `started`, or mappings name different columns |
| `install_fault.writeback_config_unreadable` | `board_take_card` | writeback.json will not parse (a start, or a takeover of an assignee) |
| `install_fault.coord_config_unset` / `install_fault.coord_config_not_absolute` | `board_take_card` and `board_correct_card` | `BRIDGE_COORD_CONFIG_PATH` — where the bridge reads every seat's kanban user id (DL-450) — is not set, or is not an absolute path |
| `install_fault.coord_config_unreadable` / `install_fault.coord_config_malformed` | `board_take_card` and `board_correct_card` | there is no coord roster at that path, or the receiver's OS user may not read it, or it is not a JSON object; the message names the path |
| `install_fault.coord_config_not_a_file` | `board_take_card` and `board_correct_card` | the path names something no reader will read: a symlink (point the setting at the file itself), a directory, FIFO, socket or device, or a file past the reader's size bound |
| `install_fault.roster_seat_absent` | `board_take_card` | the roster has no seat named your agent's seat (`identity.coord_seat`, else the agent name) |
| `install_fault.no_kanban_user` | `board_take_card` | your seat carries no kanban user id for this kanban host (or the install's kanban API base names no host), so a seat with no id is refused by name, never moved unassigned |
| `install_fault.no_agent` | the ssh door (exit 1) | the forced command passed no `--agent` |
| `repo_not_received` | `ci_await` | no agent on this install subscribes to that GitHub repo, so no `workflow_run` delivery would ever settle the await; nothing was stored |
| `install_fault.ci_await_ttl_invalid` | `ci_await` | `BRIDGE_CI_AWAIT_TTL` is not a whole number of seconds from 60 to 604800 |
| `install_fault.ci_await_store_unavailable` | `ci_await`, `ci_await_cancel` | the `ci_awaits` table is missing (`php artisan migrate`) or the database did not answer |
| `install_fault.shared_kanban_user`, `install_fault.not_in_roster`, `install_fault.agent_config_unreadable` | `board_take_card` and `board_correct_card` (and `ci_await`, `install_fault.agent_config_unreadable` only: an agent config that will not load, so whether the repo is received cannot be told) | the bridge cannot say which kanban user you are (an id the roster gives two seats this install serves, a seat more than one board-tools agent here serves, an agent no longer configured, an unreadable agent config) — `board_correct_card` reaches these and the `coord_config_*` codes only, because a seat with no id simply has no assignee there |

A failure whose STATUS is the answer carries no code: the 502 `upstream board error` stays one
body byte for byte for every cause (DL-387); the HTTP door's 401 (bearer) and 503 (install) answers
are told apart by status; and the ssh door's answers that exit **2** carry no code — an agent
config that will not load, an unknown `--agent`, an agent that is not a live ssh board-tools agent,
the dispatcher's 503 when the writeback token is unusable, and the 502 itself, which over ssh shares
that exit code with the install answers and so is not told apart from them (`DispatchOutcome::exitCodeFor` maps
every status from 500 up to exit 2). ⚠ **Exit 1 is not "your own fault"**: every status below 500
maps to it, so the `install_fault.*` 422s above exit 1 as well — branch on `reason`, not on the exit
code. A missing `--agent` (set by the pinned forced command) is the ssh door's own exit-1 install
answer, coded `install_fault.no_agent`. Other tools' own refusals (`board_create_card`, `board_correct_card`,
`board_comment_card`, …) are not all coded; a refusal with no code carries no `reason` key. Every
refusal `ci_await` and `ci_await_cancel` build carries one — `CiAwaitRefusalReasonCoverageTest`
holds that, and states what it scans.

**Permissions.** The combined PATCH carries more than `workflow_stage_id`, so kanban authorizes it
as **`task.update`**, the same as the plain take; a start on a card you already hold sends the
column alone and needs `task.move`. `bridge:check`'s `board_tools.board_state` leg reads whether
the writeback user's role on your board grants `task.update` and **warns** when it does not; when
the board's answer carries no permissions list it says **UNMEASURED**, never a pass. ⚠ It reads
the ROLE only: the token's own `write` ability and an archived board's write gate are the other
two sources of the same 403, and no read shows them.

**Cost:** a start from an eligible column is the lookup, the PATCH and the read-back (three
requests), plus the takeover's two column reads and comment when another user holds the card; a
refusal for the column costs the lookup and two column reads, and writes nothing.

**⚠ It needs `task.update` on your board, and a narrowed role gets a permanent 403.**
`assigned_user_id` is not `workflow_stage_id`, so kanban authorizes this PATCH as
`task.update` rather than `task.move` (kanban DL-204) — the same ability
`board_correct_card` takes. A writeback user on a custom board role without it is refused on
**every** take, forever. That is reported as an **INSTALL FAULT by name**, enumerating the
gates, never as a bare 403 you would retry.

**Cost:** two requests on a successful take (one board-scoped lookup, one PATCH); **one**
request when you already hold the card; two reads and no write on a not-found refusal.

## `board_comment_card`

**Append a comment to a card on your own board** (DL-381, card#9459). The one way to add a
note to a card without touching what is already on it. `board_correct_card`'s `description`
**replaces** the whole body, so appending with it means reading the card's current text first,
and `board_my_cards` windows that read: for a card outside the window, the only write available
used to delete the card's contents (rt#485). A comment is a new row under the card. It needs no
read of the body and cannot overwrite anything.

**Arguments:**

| Arg | Required | Notes |
| --- | --- | --- |
| `card_id` | yes | A positive **integer**: the `id` `board_my_cards` reports. A decorated string (`"42"`) or a float is refused, never coerced. |
| `content` | yes | The comment text (kanban renders it as markdown). **Stored trimmed**; a value that is blank once trimmed, including one made only of invisible characters, is **refused**. Bounded at kanban's own `content => max:65535` **characters**, measured over the whole body the bridge sends, **attribution line included**, so the room left for your text is 65535 minus the length of `FROM: <your seat>` and the blank line after it. An over-long value is **refused** (422) before any request, and the refusal says how much room you have. ⚠ The bound reads the value **as sent**, padding included (see the normalisation rule at the top). |

That is the whole accepted set. Any other key is **refused** (422) before any request: see
§ [An argument the tool does not declare is refused](#an-argument-the-tool-does-not-declare-is-refused-on-every-tool-dl-379).
Two kinds of key get a reason of their own. A key that tries to name the author (`from`,
`author`, `seat`, …) is told the attribution comes from your bridge identity. A key that assumes
a comment can be changed (`comment_id`, `edit`, `delete`, …) is told this tool only appends.

**⭐ Which cards you can comment on: any LIVE card on your own board.**

The card is established on your configured board through the same **board-scoped** search the
correction and take tools use (`q=board_id=<yours> id=<n>`, the verdict read off the returned
rows). **Nothing else is required.** You do not need to have filed the card, hold it, or work its
lane. A comment changes nothing on the card and is attributed, so the relations those tools rest
on have nothing to protect here. Refused, with nothing written:

- **a card on any other board**, the coordination board included (`coord_cards` ids are a
  different board, and this door has never written there);
- **an ARCHIVED card** on your board, named as a retire. ⚠ kanban itself would accept that
  comment; the bridge refuses it, as `board_correct_card` and `board_take_card` refuse archived
  cards;
- **an id that is not on your board**, including a card in kanban's trash, which the search does
  not return. ⚠ A board the bridge's writeback token is not a **member** of answers the same way
  (zero rows, not an error), and the refusal says so.

**⛔ Attribution is the bridge's.** Every seat writes through the one writeback user, so the
comment's kanban author does not say which seat wrote it. The bridge therefore writes the body as:

```text
FROM: <your seat>

<your content, trimmed>
```

`<your seat>` is the agent name your call authenticated as: the bearer's agent over HTTP, the
pinned forced command's `--agent` over ssh. It is the same name `board_create_card` stamps as
`created-by:`. No argument can set it. If your own text starts with a `FROM:` line, that line
lands **after** the bridge's and does not replace it.

**⛔ Append-only.** Nothing on this door edits or deletes a comment.

**⚠ Not idempotent.** Only a refusal (`422`) tells you nothing was written. Any other answer —
a `502`, a `500`, a non-JSON answer, a failed ssh leg, or a timeout — may follow a POST that
landed, so re-sending can post the comment twice. A timeout between the bridge and kanban answers
the same `502` a kanban 5xx does ([§ A PERMANENT board 4xx](#a-permanent-board-4xx-is-a-refusal-on-every-tool-dl-339),
the *no answer* row, DL-387), and it cannot say whether kanban acted before the answer was lost.
The bridge's HTTP client does not retry on its own.

**⚠ A PINNED card still takes a comment.** The DL-178 hold governs a card's stage, its lifecycle
and the fields `PinGuard::PINNED_FIELDS` names. A comment writes no field.

**⚠ A comment EMITS a kanban webhook.** kanban records `comment.created` for it, as it does for
the writeback's card notes. The actor is the writeback user, so on an install that declares
`writeback.json`'s `identity_id` the global echo set suppresses it for every agent
([`writeback.md`](writeback.md) owns that rule). **Do not rely on a comment to wake another
seat.** Use the coordination surface for anything that needs an answer.

**Returns:**

```jsonc
{
  "commented": true,
  "card_id": 42,
  "board_id": 10,            // observed: the row was accepted only because it carried this
  "attributed_to": "me"      // the name the bridge wrote on the FROM: line
}
```

**⚠ It needs `comment.create` on your board.** kanban authorizes a comment through
`CommentPolicy::createFor`: the board's write gate, then the `comment.create` permission. Member
and admin roles carry it, and a custom role inherits it; a viewer does not. A 403 is reported as
an **INSTALL FAULT by name** that lists every gate to audit. See
[`writeback.md` § A least-privilege writeback token](writeback.md#1-a-least-privilege-writeback-token).

**Cost:** two requests on a successful comment (one board-scoped lookup, one POST); two reads and
no write on a not-on-board refusal.

## `board_get_cards`

**Read N cards you already know the ids of, in one call** (DL-435, card#10832; rt#572). A seat
asking "what state are these ten cards in" used to read its lane, then every column one at a time,
and still find cards in **no** read it could make — `board_my_cards` sees your own lane, a capped
window and live cards only, and it cannot say whether a card it omits is archived, in another lane,
on another board or gone. This tool answers **every id you name, with a status**.

**Arguments:**

| Arg | Required | Notes |
| --- | --- | --- |
| `ids` | yes | A non-empty **list of positive integers** — the ids `board_my_cards` (or anything else) reports. At most `BoardGetCardsTool::MAX_IDS` per call (the schema's `maxItems` states the number and a test holds the two equal) — `board_my_cards`' own per-list **card-count** cap, but **not the same response budget**: this tool's `{id,status,card}` wrapper plus `swimlane_id`/`position` cost about 66 bytes/card more than `board_my_cards`' card (measured; stable across card-name length — see the `MAX_IDS` docblock), so a full default answer here runs roughly a fifth over the 16,384-char budget that cap was sized to (§ *The default is capped* above owns that budget's own derivation). Soft either way — nothing here truncates for it. A decorated string (`"42"`), a float, zero, a repeated id or an over-long list is **refused** (422) before any request. |
| `fields` | no | Which card fields to return, from `BoardCardProjection::FIELDS` (the schema's `enum` restates it and a test holds the two equal). **Omitted, or `null` ⇒ every field except `description`.** Naming `description` returns each card's body **and** `description_truncated`, cut to the same per-card `description_max_bytes` `board_my_cards` uses. `[]` returns statuses only — `card` is **omitted** on a `found`/`archived` entry rather than sent empty, so it is never present as an empty value of either JSON type. An unknown name is **refused**. |

That is the whole accepted set; any other key is refused (and `include_description` is told to name
`description` in `fields` instead): see
§ [An argument the tool does not declare is refused](#an-argument-the-tool-does-not-declare-is-refused-on-every-tool-dl-379).

**⛔ N in, N out.** The answer's `cards` list has **exactly one entry per requested id, in the order
you sent them**. Where the bridge cannot establish a status for an id, the **whole call is refused**
(422, "NO cards were returned") — it never answers with a hole.

**The statuses, and how each is established:**

| `status` | Means | How the bridge knows |
| --- | --- | --- |
| `found` | a **live** card on your board, in **any** lane (or none) | the board-scoped search every card-id tool on this door uses (`q=board_id=<yours> id=<n>`, the verdict read off the returned row — DL-323) |
| `archived` | an **archived** card on your board | the same search, on kanban's archived side, asked only when the live side missed (DL-296) |
| `other_board` | the id is a card on a **different** board — the coordination board included | after a miss on both sides, the unscoped `GET /tasks/{id}/preload.json` answered with another board's id, **or** answered 403 (a board the writeback user may not view). **Nothing of that card is returned — not its content and not its board id.** |
| `not_found` | no card carries the id, **or it is in kanban's trash** | that same by-id read answered 404. kanban answers a missing id and a trashed one the same way, before any authorization, so the two are one status here. |

**⚠ A 403 is `other_board` only once the token is shown to read your own board.** kanban's search answers a
writeback user that is not a **member** of your board with zero rows, not an error, so such a user
would miss every card on your board and then 403 on each by id — every one of your own cards would
come back `other_board`. Any id **in the same call** that came back `found` or `archived` already
proves the token reads your board (the search that returned it answers members only), and every
board-scoped lookup runs before any by-id read, so that proof counts whatever order you sent the ids
in. Only when **no** id resolved on your board does the first 403 make the bridge ask kanban once
whether the token may read your board (`GET /boards/{id}/status.json`, authorized on the board
itself): 200 — an empty board included — makes the 403 `other_board`; a 403 there (the token's user
is not a member) **refuses** the call. Likewise, a by-id answer that names **your** board after the
board-scoped search missed it is refused as a **BROKEN READ**, never reported as a status. (For an
API token kanban's `view` is owner-or-member, the same set its search answers, so this is a
disagreement kanban's current authorization does not produce; it is refused rather than trusted.)

**Returns:**

```jsonc
{
  "configured_board_id": 10,
  "fields": ["id", "name", "stage", "swimlane_id", "..."],   // the projection this call applied
  "cards": [
    { "id": 101, "status": "found",       "card": { "id": 101, "name": "…", "stage": "In Review", "swimlane_id": 4, "position": 1024, "…": "…" } },
    { "id": 102, "status": "archived",    "card": { "…": "…" } },
    { "id": 103, "status": "other_board" },   // no card key
    { "id": 104, "status": "not_found" }      // no card key
  ]
}
```

`card` is the same card shape `board_my_cards` returns (`BoardCardProjection`, one owner for both),
plus two keys `board_my_cards`' lane lists do not carry:

- `swimlane_id` — present-and-`null` for a card in no lane, and **absent** (never `null`) when the
  row carried no readable lane field;
- `position` — kanban's in-column ordering value, as a number; **absent** when kanban sent none.
  ⭐ **Card order within a column IS the priority order** (operator ruling, rt#552): the top card has
  the lowest `position`, and the total order within a stage is **`(position, id)` ascending** — `id`
  breaks a tie. kanban indexes and locks cards in that same order (its `tasks` index is
  `(workflow_stage_id, position)`).

A key you did not select is absent.

**⚠ No window.** Nothing is cut — the request is bounded instead — so there is no `cards_window`,
`truncated` or `total_is_lower_bound` here. N in, N out is this tool's whole honesty contract.

**⚠ It crosses lanes, deliberately** — the second read on this door that does, after `board_my_cards`'
`tag` read (DL-383). You name each id, on your own board; a card anywhere else is a status with no
content.

**Errors.** A permanent 4xx on the board-scoped search, the membership control (other than a not-readable answer — a 403, or a 200 it reads as unreadable — the membership refusal above) or the stage read is a
named **INSTALL-fault** refusal: § [A PERMANENT board 4xx](#a-permanent-board-4xx-is-a-refusal-on-every-tool-dl-339).
On the by-id read, 403 and 404 are **statuses** (above), not refusals; any other failure there keeps
the retryable `502`.

**Cost:** per id, one search for a live card, two for an archived one, three reads for an id not on
your board; plus at most one stage read (only when `stage` is selected and a card was found) and at
most one membership control per call (none when any id resolved on your board). **That worst case is a large slice of a budget every
board-tools call and the writeback SHARE.** kanban's default API limit is 300 req/min **per
authenticated user** (kanban `origin/dev` 4688b543, `app/Providers/AppServiceProvider.php:40`), and
every call on this door authenticates as the ONE writeback user (§ *A least-privilege writeback
token* in [`writeback.md`](writeback.md)) — so one call naming ids that all miss both archive sides
can cost up to `3 × MAX_IDS + 1` kanban requests: 3 per missing id (the live search, the archived
search and the by-id read) at the cap, plus 1 shared membership-control request per call. The stage
read never adds to that ceiling — it is paid only when some id resolves as `found` or `archived`,
which costs fewer than 3 reads, so a call where every id misses never pays it — against the same
per-minute budget the writeback's own card moves draw on. A 429 that lands on a writeback move
while that budget is exhausted is currently handled as a PERMANENT failure rather than retried, not
something this tool fixes — tracked separately as card#10849.

## `board_search`

**Search your own board by filter and get the matches only** (DL-437, card#10832; rt#572 asks 3–5).
`board_my_cards` answers "what is in my lane", with a lane list and a column list attached;
`board_get_cards` answers "what state are these ids in". This answers "which cards on my board match
these filters" — and, with `summary: true`, "how many, per column".

**Arguments** (all optional; they combine with AND):

| Arg | Notes |
| --- | --- |
| `tags_all` | Non-empty list of tags. Cards carrying **every** one (each matched exactly — kanban v0.46.0+ matches each tag element-wise, so `/`, `%`, `\` and non-ASCII characters match literally). A tag containing `"` or `*` (term syntax) or longer than kanban's tag limit is refused; this applies to `tags_any` and `summary_tags` too. |
| `tags_any` | Non-empty list of tags. Cards carrying **at least one**. kanban's search has no OR, so this costs one search per tag (see *Cost*). |
| `stage` | Non-empty **list** of columns, each a numeric stage id or a stage name — resolved exactly as `board_my_cards`' `stage` is (case-insensitive, trimmed, an ambiguous name refused; `BoardStageArgument`). |
| `pr_number` | A positive integer. Cards tracking that pull-request number, **in any repo** — each card's `source` and `pr_url` tell them apart. **Live cards only**: refused with `include_archived: true` (below). |
| `name_contains` | Non-empty text. Cards whose **name** contains it — kanban's own match (a `LIKE`, so case follows kanban's database collation). A `"` or a control character is refused: kanban's term carries the text inside quotes. |
| `updated_since` | A calendar **date**, `YYYY-MM-DD`. Cards updated on or after it. kanban compares the **date part** of `updated_at` (its `whereDate`, in kanban's database time zone), so a timestamp is refused rather than rounded. |
| `include_archived` | Boolean, default `false`. Also search the archived side; each card then carries `archived: true` or `false`. |
| `lane` | `mine` (your own swimlane), `none` (cards in no lane) or `any` (every lane — **the default**). |
| `summary` | Boolean, default `false`. Counts instead of cards — below. |
| `summary_tags` | With `summary: true` only: a list of tags to count the matches of, each on its own. |
| `fields` | As `board_get_cards`' `fields` (`BoardCardProjection::FIELDS`, `description` opt-in). `[]` is refused (it would return empty cards); not with `summary`. |
| `limit` | 1 to `BoardSearchTool::MAX_LIMIT` — kanban's own page size (`KanbanClient::SEARCH_LIMIT`), so one request per search is the whole window. Default `BoardSearchTool::DEFAULT_LIMIT` (`board_my_cards`' card-count cap). Not with `summary`. |

Any other key is refused (§ [An argument the tool does not declare is refused](#an-argument-the-tool-does-not-declare-is-refused-on-every-tool-dl-379)).
An argument that would change nothing on this call (`summary_tags` without `summary`, `fields` or
`limit` with it) is refused rather than ignored, as is every malformed value — all before any read.

**Returns** (a search):

```jsonc
{
  "configured_board_id": 10,
  "filters": { "tags_all": ["lane:A"], "stage": [51], "include_archived": false, "lane": "any" },  // as applied: stage names resolved to ids
  "fields": ["id", "name", "stage", "..."],
  "cards": [ { "id": 104, "name": "…", "stage": "In Review", "swimlane_id": null, "position": 1024, "…": "…" } ],
  "window": { "total": 3, "returned": 3, "limit": 52, "truncated": false, "total_is_lower_bound": false }
}
```

**Matches only**: no `board_stages`, no `cards_by_stage`, no lane block. `cards` are the **newest**
matches first (highest id — kanban orders its search that way, and ids are allocated monotonically),
each the `board_get_cards` card shape (`swimlane_id` and `position` included), plus `archived` under
`include_archived`.

**`summary: true`** returns no `cards`, `fields` or `window`, and instead:

```jsonc
"summary": {
  "total": 3, "total_is_lower_bound": false, "truncated": false,
  "by_stage": [ { "id": 50, "name": "Backlog", "count": 1 }, { "id": 51, "name": "In Review", "count": 2 } ],
  "stage_counts_sum_to_total": true,
  "by_tag": [ { "tag": "bug", "count": 2 } ]      // only when summary_tags is sent
}
```

`by_stage` covers the `stage` columns you named, or every column of your board, in column order.
⛔ Without `stage`, that needs your board's column list: if kanban's read of the board answers
without one (a 200 carrying no stage collection), the summary is **refused** (422, an INSTALL fault,
naming that cause) rather than answered with an empty `by_stage`. Naming the columns in `stage` still
counts them.
Every count is **kanban's own** (`meta.total` of a one-row search), so nothing is cut: `truncated` and
`total_is_lower_bound` are always `false` here. ⚠ Each count is a **separate** read, so a card moving
between two of them can make the columns disagree with `total` by the cards that moved —
`stage_counts_sum_to_total` says whether they agreed on this call, rather than hiding it.

### The honesty contract, filter by filter

⭐ **Every filter is applied by kanban, never by reading more of the board and filtering in the
bridge.** Source-read at kanban `origin/dev` 54a63399 (`QueryParser::applyStructuredFilter`,
`TasksController::search` / `byRef`), not measured against a live instance:

| Filter | How kanban applies it | Window |
| --- | --- | --- |
| `tags_all` | one `tags:"<tag>"` term per tag, ANDed | exact |
| `stage` | `workflow_stage_id=<ids>` | exact |
| `name_contains` | `name:"<text>"` (a `LIKE %text%`) | exact |
| `updated_since` | `updated_at>=:<date>` (a date compare) | exact |
| `lane` | `swimlane_id=<your lane>` / `swimlane_id=none` / no term | exact |
| `include_archived` | the `archived` switch — kanban has no both-sides mode, so one more search per archive side; the two sides are disjoint, so their totals add | exact |
| `tags_any` | one search per tag, merged by id in the bridge | the **window** is exact (a card among the union's newest `limit` is among the newest `limit` of every tag it carries); the **total** is exact when every per-tag search was complete, and otherwise a **lower bound** — the larger of the distinct cards read and the largest single-tag count — with `total_is_lower_bound: true` **and** `truncated: true`, because a union kanban was not asked to size cannot be sized from per-tag counts without counting a card that carries two tags twice |
| `pr_number` | kanban's by-ref index (`boards/{id}/tasks/by-ref.json`, the index the writeback correlates on — canonicalized, board-scoped, **live only**, unpaginated); with any other filter, each card it names is **re-asked through the search** with `id=<n>` and every other filter, so kanban still decides each one | exact (the index answers every live card carrying the PR) |

⛔ **A filter kanban did not apply is a refusal, never an answer.** kanban's search answers a term it
does not recognise as **free text**, at 200 — a count of cards whose TEXT matches, which looks exactly
like a count of the thing asked (`swimlane_id=none` on a kanban before v0.45.0 is the live instance).
So every search this tool sends is checked against kanban's own parse disclosure
(`meta.free_text_terms`, kanban DL-282, **first released in kanban v0.47.0**): a term listed there, a
response without the disclosure at all, or one without `meta.total`, refuses the whole call (422,
"NO cards were returned", an INSTALL fault) — **so `board_search` needs kanban v0.47.0 or later.** A
row naming another board refuses the call too, without that row's content; a 200 carrying no card
collection is the retryable `502`.

**Two combinations are refused, each naming why:**

- **`pr_number` with `include_archived: true`** — ⚠ **a kanban-side gap, not a bridge choice.** kanban
  finds a card by PR number only through its by-ref index, which hard-excludes archived cards with no
  parameter to include them, and its search has no PR-number term (a `custom_field_pr_number` term
  compares the raw stored value, whose type is each board's own, and would disagree with the
  canonicalized index the writeback correlates on). `board_get_cards` reads known ids on either side.
- **`summary: true` with a `tags_any` of more than one tag** — kanban's search has no OR, and a sum of
  per-tag counts counts a card carrying two of them twice. Use `summary_tags` to count each tag.

**⚠ It crosses lanes by default** (`lane: any`) — the third read on this door that does, after
`board_my_cards`' `tag` read (DL-383) and `board_get_cards` (DL-435), and the first whose population
you **filter** rather than name. It stays on your own configured board: every search carries
`board_id=<yours>`, and kanban's disclosure confirms it applied.

⛔ **"No matches" is said only of a board the bridge can read.** kanban's search answers a token
whose user is not a **member** of your board zero rows, at 200 — the same answer as "nothing
matched". So when every search of a call answered nothing, kanban is asked once whether the token may
read your board (the membership control `board_get_cards` uses, `BoardMembershipControl`:
`GET /boards/{id}/status.json`, authorized on the board itself). A member's 200 is answered "no
matches" — on an empty board too, or one whose every card is archived — and a non-member's 403 is
refused (422), naming membership. Anything a search returned in the same call is the proof, and then
nothing more is asked. `pr_number` alone sends no search, so its by-ref answer stands without the control.

**Errors.** A permanent 4xx on a search, the membership control (other than a not-readable answer — a 403, or a 200 it reads as unreadable — the membership refusal above), the stage read or the by-ref read is
a named **INSTALL-fault** refusal: § [A PERMANENT board 4xx](#a-permanent-board-4xx-is-a-refusal-on-every-tool-dl-339). Any other
board failure keeps the retryable `502`.

**Cost**, in kanban requests — every one against the per-user budget the writeback shares (§
`board_get_cards` *Cost*):

- one stage read (the board structure read) when `stage` is sent, `stage` is a selected field (it is by default), or `summary` is
  set;
- a search: **(number of `tags_any` tags, or 1) × (2 with `include_archived`, else 1)**;
- a summary: **(2 with `include_archived`, else 1) × (1 + columns counted + `summary_tags` tags)**;
- `pr_number`: **1** by-ref read, plus, when any other filter is sent, **(cards the index names) × (number
  of `tags_any` tags, or 1)** searches, plus **(cards the index names) × (`summary_tags` tags)** in a
  summary (the matches, of which those cards are the upper bound);
- **1** membership control whenever the call sends any search (asked only if every search answered
  nothing, above).

⛔ **Bounded, every request counted.** The call's **whole** total — every line above that applies —
is held to `BoardSearchTool::REQUEST_CEILING`, and no accepted call sends more. A call its arguments
alone put over the ceiling (a summary's integer `stage` ids counted) is refused, with its count per
phase, before its first request. Where the total depends on what a read returns — the columns a
summary counts, the cards carrying a PR — it is refused once the **sizing** reads have run: the stage
read, plus the by-ref read on the `pr_number` path. A refused call has sent at most those, never a
search. The ceiling is **borrowed**, not chosen:
it is `board_get_cards`' worst case, `3 × MAX_IDS + 1`, the per-call ceiling this door already accepted
against that shared budget (DL-435 bound (d)). Nothing is ever walked page by page: `limit` never
exceeds one page, and every count is kanban's.

## `ci_await` and `ci_await_cancel`

**Wait for CI on one commit without polling GitHub** (card#11200 / DL-452; rt#590). A seat that
needs "CI is finished on head X" used to poll — a Monitor loop or repeated `ci-read` — and every
tick spent the one shared GitHub REST quota. The bridge already receives `workflow_run.completed`
for the repos it serves, so it can tell the seat instead: register the head once with `ci_await`,
and the bridge sends **one** event when it is done. A hook reaches it through any board-tools
door, including the `bridge-board-call` CLI card#11151 adds.

**`ci_await` arguments:**

| Arg | Required | Notes |
| --- | --- | --- |
| `repo` | yes | The GitHub repository as `owner/name`. Matched case-insensitively against this install's GitHub subscriptions; the answer and the events carry the configured spelling. |
| `head_sha` | yes | The **full** 40-hex commit SHA (`git rev-parse <ref>`), case-insensitive, stored lower-case. ⛔ An **abbreviated** SHA is refused (`bad_arguments`): GitHub's run list filters on the exact SHA and answers an abbreviation with an empty list, so the wait would never settle. |
| `pr` | no | The pull-request number, a positive integer (or `null`), carried back in the events. A re-registration that omits it keeps the one already recorded. |

**`ci_await_cancel` arguments:** `repo` and `head_sha`, with the same rules. It removes **your own**
await on that head and answers `cancelled: true`, or `cancelled: false` when you had none there —
never registered, already settled or expired, or only another seat awaits it — so a hook may call it
unconditionally.

That is the whole accepted set for each; any other key is refused, and a key that tries to name a
seat (`agent`, `seat`, `user`, …) is told the await is always yours. ⛔ **No argument names a seat**:
the await belongs to the agent your call authenticated as, resolved by the front door like every
tool here — so it works on a solo install with no coordination repo, and one seat can neither
register nor cancel another's.

**What `ci_await` does, in order:**

1. Refuses a repo **this install receives no GitHub events for** — no agent on this install
   subscribes to it — as `repo_not_received`, because nothing would ever arrive to settle it. Poll
   with `ci-read` there.
2. Stores the await, or **refreshes** yours on the same head (its expiry restarts; `refreshed: true`).
   One await per seat per head.
3. **Reads the head's runs once**, so CI that already finished settles now.

The answer:

```jsonc
{
  "repo": "octo-org/widgets",
  "head_sha": "<40 hex>",
  "pr": 12,                  // or null
  "state": "waiting",        // waiting | settled | unmeasured
  "refreshed": false,
  "expires_at": "2026-10-03T16:00:00.000Z",   // null once settled
  "runs_total": 3,           // null when the read failed
  "runs_completed": 1,
  "read_error": "…",         // only on state: unmeasured
  "warning": "…"             // only when this bridge holds no stored workflow_run delivery from the repo
}
```

- **`settled`** — every run was already terminal: `ci_settled` has been sent to you and nothing is
  stored.
- **`waiting`** — stored; at least one run is not finished, **or there are no runs yet** (CI not
  queued yet looks exactly like that, so an empty list is never treated as settled).
- **`unmeasured`** — stored, but the read failed (`read_error` says why). Nothing is sent on a failed
  read; see *Read failures* below.
- **`warning`** — this bridge has no stored `workflow_run` delivery from that repo. If the repo's
  webhook does not send **Workflow runs** to this bridge, nothing settles the await and it ends in
  `ci_await_expired`. None stored is not proof — retention prunes old deliveries and a new webhook has
  sent none yet. `bridge:check`'s `ci_await.awaits` leg reports the same per awaited repo.

**How it settles.** On each `workflow_run.completed` delivery whose repo and `head_sha` match at
least one await, the bridge makes **one** read — `GET /repos/{repo}/actions/runs?head_sha=<sha>`,
walked page by page to the end of the list — after the delivery has been answered. When every run on
the list has `status: completed`, every seat awaiting that head gets **one** `ci_settled` and its
await is deleted. ⭐ **No await, no read:** a run completing on a head nobody awaits costs one
indexed query and no GitHub request, so a green push to `dev` wakes nobody. ⭐ **Once per await,
under concurrency:** two deliveries for a head's last two runs can both read "all terminal"; each
emit first deletes its await row in a transaction and only the one whose delete removed it emits.
The same claim decides between a settle and an expiry, so an await gets one or the other, never both.

**The verdict is `ci-read`'s, never the bridge's.** `ci_settled` means only *every listed run has
finished*. It carries each run's conclusion as data, and **it does not say green or red**: a verdict
needs the base branch's required contexts and the latest run per workflow, which is `ci-read`'s
definition, and the bridge does not restate it. On `ci_settled`, run `ci-read` **once** on the head.

**Read failures.** A read that fails — a 403 or 429 rate limit, a 5xx, no answer, no GitHub read
token, a 200 whose body is not a run list, or a list that does not end within the read's page bound
— **sends nothing**. The await is kept with the error recorded, a `bridge ci_await:` warning is
logged naming it, and the head is read again on its next completed run and by the `ci-await-sweep`
job. If no read ever answers, the await ends in `ci_await_expired` carrying the last error.

**Expiry.** An await lives `BRIDGE_CI_AWAIT_TTL` seconds (default 21600, 6 h; 60 to 604800 accepted —
anything else refuses every `ci_await` as `install_fault.ci_await_ttl_invalid` and fails `bridge:check`).
The `ci-await-sweep` periodic job, declared at the first registration, emits `ci_await_expired` once
per await past its expiry and re-reads heads whose last read failed — never a head whose read
answered, which is re-read only when its next run completes. It runs on the job registry's two
ingresses ([`periodic-jobs.md`](periodic-jobs.md)), so expiry lands at the first job pass after
`expires_at`: on a busy install with the next webhook, on a silent one only with `bridge:tick`.

**The events.** Both are bridge-authored intents (`provider: "bridge"`, null actor), **staged to the
inbox and pushed live** — the await is gone once emitted, so the inbox line is what reaches a seat
whose channel was down. `subject_id` is `ci:<repo>@<head_sha>`. The payloads and the inbox shape are
[`consumer-guide.md`](consumer-guide.md) § *Bridge-authored intents*'s to state.

**Limits, named:**

- ⚠ **Late runs.** A workflow that starts only after others finish (`on: workflow_run`) may not be
  on the list when the others complete, so `ci_settled` can arrive before it exists; `ci-read` then
  reports the head pending, and the seat re-registers. The payload carries no `late_runs_possible`
  hint: telling whether a repo has such a workflow would need a read of its workflow files on every
  settle, which is not cheap, so it is omitted rather than guessed. Whether such a run reports the
  awaited head's SHA at all is **not measured here**.
- ⚠ **Runs not yet created.** A registration made before GitHub has created every run for the push
  can see some runs finished and others absent; it settles only when what is listed is all terminal.
- ⚠ **A lost final delivery.** If the delivery for the last run to finish never reaches the bridge,
  nothing re-reads a head whose read answered, and the await ends in `ci_await_expired`.
  Re-registering reads the head again.
- ⚠ **Installs with several bridges.** An await lives on the bridge the seat called; only that
  bridge's deliveries settle it.

**Cost:** one read of the head at registration, and one per completed run on an awaited head — each
one request per 100 runs. Nothing for heads nobody awaits.

## Errors

| Status | Meaning |
| --- | --- |
| 403 | The request did not come from loopback (network gate). |
| 401 | Missing or unrecognized bearer token. A bearer file that exists but the bridge cannot read, and one belonging to a collided pair, are **deliberately indistinguishable** from an unknown token here — the door never tells an unauthenticated caller that another agent's bearer exists (card#5778; it 500'd on the unreadable case until then). |
| 422 | A caller-fixable bad request (a request body that is not a JSON object — empty, not valid JSON, or valid JSON of another type — which is refused **for the body, in the same words on both doors**, and never as a missing `tool` (card#10106); over HTTP, a body sent without a JSON `Content-Type`; an argument key the tool does not declare, missing/over-long `title`, reserved tag — matched case-insensitively, out-of-charset tag/key, an `idempotency_key` longer than `idem:<you>:` leaves of the tag cap, non-boolean `include_description`, unknown tool) — **or a `board_create_card` whose `idempotency_key` correlates only to an ARCHIVED card** (DL-297: a retire suppresses the create; the message names the card ids to unarchive) — **or any refusal a tool makes**, including the ones the BOARD causes on **every tool on this door** (DL-339, extending DL-326 and inherited by DL-372's take: a permanent 4xx from kanban is reported here rather than as a 502, because it fails identically however many times you send it; the message says when the cause is an install fault rather than your arguments — see the section below). A refusal that carries a machine-readable code adds `reason` beside `error` (`{ok: false, error, reason}`); the codes are listed in [`board_take_card`'s start form](#the-start-form-start-true-card11150--dl-449). |
| 502 | Upstream kanban error (may be retryable) — a kanban 5xx or another non-permanent status, **or a call kanban never answered** (a timeout or a failed connection, DL-387), **or a paged board read kanban answered `2xx` that the bridge could not report complete** (card#10653; see the **2xx, read refused** row of the mapping table below). The body is the same for all of them. ⚠ On a WRITE a 502 may follow a write that landed: read the tool's own section before re-sending. |
| 503 | Board tools are not fully configured on this bridge (e.g. no writeback token). |

### Did the call reach the bridge?

**Every row above describes an answer this door BUILT.** A call that failed in your own seat —
a harness refusing a tool whose schema it has not loaded, or refusing arguments that are not
valid JSON ([§ Discovering them](#discovering-them)) — reached no door, so none of those rows
applies to it and no wording of ours was involved. Telling the two apart tells you **where** to
look, not that what you sent was fine: a call that never arrived may never have arrived
precisely because its payload was malformed.

**It DID reach the bridge if the answer carries this door's own WORDING** — not merely its
shape. Two sentences settle it on their own:

```text
board_create_card: unknown argument `body`. This tool accepts: `title`, `description`, `tags`, `idempotency_key`. Nothing was sent to the board — no card was read or written.
board_create_card: `title` is required and must be a non-empty string
```

The parts to match are the `This tool accepts: … Nothing was sent to the board — no card was
read or written.` tail and `` `title` is required and must be a non-empty string ``. **Both are
COMPOSED HERE** — the first by the rule below, out of the tool's own declared argument set; the
second is `board_create_card`'s — and neither is restated in the reference channel server.

⛔ **The SHAPE proves nothing.** A message that names the tool and an argument it requires or
does not declare is not this door's to own: the advertised `inputSchema` your seat loads
carries the property list, `required` and `additionalProperties: false`, so a seat-side
validator working from it has everything it needs to name `board_create_card` and a missing
`title` or an undeclared key — and its complaint never left your seat. How any harness words
such a rejection has not been measured here; a complaint that names the tool and an argument
but carries neither sentence above does not, on that alone, show the call arrived.

Either sentence is therefore proof that the call arrived, was matched to a tool, and was
refused on its merits. ⚠ **It claims nothing about your seat's schema** — a caller holding no
schema at all (raw input to the ssh door, a direct HTTP POST) is answered in the same words —
and nothing about any OTHER call, earlier or later: the bridge can only report the calls it saw.

**It did NOT reach the bridge if the answer is a complaint about your input in wording no
door of ours composes** — the harness's `InputValidationError: could not be parsed as JSON` is
one: neither door, the dispatcher behind them nor the reference channel server composes it.
That is most conclusive when the call carried no arguments at all, because an ABSENT `title`
is refused by this door with the same named sentence a blank one gets, so a no-argument
`board_create_card` that arrives is answered in those words — unless an access or install
refusal answers it first.

A call can also fail **between** your seat and the bridge — in the channel server or on its
transport leg. Those answers are neither of the above; see
[§ What the CALLER sees when the leg itself fails (DL-312)](#what-the-caller-sees-when-the-leg-itself-fails-dl-312).

⚠ **Do not run that test backwards.** Several genuine answers of ours name no tool either —
among them the bearer refusals (deliberately non-discriminating, per the table above), the
loopback-only gate, the doors' own refusals of a malformed request (`` `args` must be an
object `` among them), the 503 install fault and the `upstream board error`. Each of those means the
call arrived, and apart from the malformed-request refusals none is anything your arguments can
fix. The full set is whatever the doors
compose, and their source owns it: `AgentToolsController` and `LoopbackOnly` on the HTTP door,
`bridge:tools-call` on the ssh door, and `ToolCallBody` (the one parse of the request body) and
`BoardToolDispatcher` behind both.

⚠ **What none of this can tell you is WHICH seat-side failure you hit** — an unloaded schema
or a payload that really is malformed; [§ Discovering them](#discovering-them) owns telling
those apart. **Nor can it tell you WHY a harness held a schema back, or make its message
say so.** That message is not ours to change and we do not claim to have fixed it; recognising
it is what this section offers.

### An argument the tool does not declare is refused, on every tool (DL-379)

**This section OWNS the rule; the tool sections above point at it.** Each tool declares the
whole set of top-level argument keys it accepts (`Tool::acceptedArguments()`), and the
dispatcher both front doors share refuses a call carrying **any other key** — 422, before the
tool runs and before any board request, so a refused call reads nothing and writes nothing.
An ignored key is the failure this exists to stop: `board_my_cards {status: "Blocked"}` used
to answer `ok: true` with the unfiltered window, which looks exactly like a correct answer to
a question you did not ask.

- **The message names every undeclared key in the call and the accepted set**, in one refusal:

  ```text
  board_my_cards: unknown argument `status`. This tool accepts: `include_description`, `stage`, `limit`, `tag`, `include_terminal`. Nothing was sent to the board — no card was read or written.
  ```

  ⚠ **It can carry one more sentence** — see [§ A refusal or a truncated window can tell you to
  update your channel client](#a-refusal-or-a-truncated-window-can-tell-you-to-update-your-channel-client-dl-426).

- **A tool may give a key a reason** (`Tool::refusedArgumentReason()`), which replaces only the
  generic *unknown argument* clause for that key: `board_correct_card` names the authority that
  owns a field it will not write, and `board_take_card` gives every key a reason — why a
  user-naming key will never exist, and for any other key that the assignee comes from your
  bridge identity. The outcome is the same refusal either way.
- **Keys match exactly.** `Stage` is not `stage`.
- **Enforced in the bridge, not the channel server.** The ssh door never passes through a
  channel server, so a schema-side check would leave it open. The reference channel server's
  `inputSchema` for each tool advertises exactly the declared set with
  `additionalProperties: false`, and `ChannelServerToolSurfaceRestatementTest` fails when the
  two differ.
- ⚠ **A call to an install with no writeback token still answers 503**, even when its keys
  are also wrong: the install fault is reported first, as it was before this refusal moved into
  the dispatcher.

### A refusal or a truncated window can tell you to update your channel client (DL-426)

When a call is refused (`422`) and it sent an accepted argument that its reported
`client_version` does not declare, the refusal ends with one more sentence naming each such
argument and the client version that first declared it:

```text
board_my_cards: `limit` must be an integer of at least 1 when provided — … narrow with `stage` instead where you can. Your channel client, version 0.9.12, does not declare `limit` (first declared by client 0.9.16); update your channel client so its tool schema describes it.
```

- It is built from the keys the call **sent**, never from the refusal's wording, and it is the
  same on the unknown-argument refusal and on a tool's own refusals.
- **No usable version** (none sent — an old client, a hand-run `bridge:tools-call` — or one
  the door refused): an argument is named only when a client too old to report a version can
  lack it, and the sentence says so in those terms. `bridge:check --probe-tools` sends no
  arguments, so its calls never carry the sentence.
- **No sentence** when the client declares every argument it sent, when the capability table
  cannot order the reported version, on an install-fault read refusal (*"This is an INSTALL
  fault"*), for a tool or argument the table does not carry, or when the table cannot be read
  (logged as a warning).
- ⛔ **Text only.** No status, exit code or accepted value moves with the version. Branch on the
  status, never on the wording.
- **A truncated `board_my_cards` window's `remedy` carries the same sentence** for the arguments
  the remedy ADVISES rather than the ones sent — a success, not a refusal (see § The default is
  capped). For example, a `0.9.12` client's own-lane remedy ends
  `` … or raise `limit` (the response grows in proportion). Your channel client, version 0.9.12, does not declare `stage` (first declared by client 0.9.16) and `limit` (first declared by client 0.9.16); update your channel client so its tool schema describes them. ``

### A PERMANENT board 4xx is a refusal, on every tool (DL-339)

**This section OWNS the rule; the tool sections above point at it.** When kanban itself
refuses a request the bridge made on your behalf, the answer you get depends on whether the
cause can CLEAR — not on which tool you called. **A 4xx outside the set below is not
permanent as far as this door is concerned and keeps the retryable 502** (the *any other 4xx* row). And for
a 403 or a 404 the CAUSE depends on which kanban route the bridge was reading, because the
two route classes are authorized differently:

| kanban answered | You get | Why |
| --- | --- | --- |
| **401** on any call | **422 refusal**, "revoked, rotated or replaced" | kanban's v3 API is `auth:sanctum`: a token it no longer knows is refused at the door on every subsequent call. **Install fault** — retrying is the one thing that cannot help. |
| **403** on a card **SEARCH** | **422 refusal**, naming the token's **abilities** | kanban gates the API per token and a GET needs `read`. ⛔ Deliberately **not** board membership *on this route*: `tasks/search.json` floors the query to the caller's own boards and answers **200 with zero rows** for the rest. |
| **403** on a **board-scoped** read (`boards/{id}/preload.json`) | **422 refusal**, naming the abilities **and** board **membership** | this route authorizes the BOARD itself, so a writeback user that is not a member of it is refused here — the one cause the search row above rules out. `board_my_cards` is the only tool that reads this route (its stage names, on your board and on the coord board). |
| **403** on a WRITE | **422 refusal**, naming **every gate that can answer it** | the token's abilities, the writeback user's board role (`task.create` for a create, `task.update` for a correction **or a take**, `comment.create` for a comment), **and kanban's board write gate** — an archived or trashed board refuses every write whatever the token and role allow. `BoardCallRefusal::writeGatesClause()` is the ONE place they are enumerated (count them there, not here); a 403 cannot say which refused. |
| **404** on a card **SEARCH** | **422 refusal**, "API-surface fault" | the ROUTE answered 404, which is a statement about the API surface rather than about a card (a card that is simply not yours is a different refusal, with its own message). |
| **404** on a **board-scoped** read | **422 refusal**, "the BOARD itself" | the configured board id does not resolve on that route: no board carries it, or it is in the trash (that route does not resolve trashed boards). A missing API surface is the other, less likely candidate. |
| **422** on a WRITE | **422 refusal**, ending with **the board's own reason** | the board refused a value in the write. Deterministic — and this is what keeps the mirrored length caps safe to go stale. The bridge's sentence says only what its own checks established, and the board's field errors follow it, redacted and bounded: [§ What a board 422 relays](#what-a-board-422-relays-dl-384) (DL-384). |
| **422** on a READ | **502** (retryable) | a read sends no value for a validator to reject, so a 422 there is a malformed-query/API-surface fault the bridge has no cause to name. Deliberately NOT in the set above. Nothing of the board's body is relayed. |
| **any other 4xx** — **400**, 408, 429 … | **502** (retryable) | outside the permanent sets on purpose: the bridge has no diagnosis to offer for them, and a rate limit really does clear. |
| **5xx** | **502** (retryable) | it may clear. This is the one you may retry — ⚠ **except a write with no key to correlate on**: `board_comment_card`'s POST has no idempotency key, so a 502 there may follow a comment that landed and a retry can post a duplicate; a `board_create_card` sent **without an `idempotency_key`** may follow a card that landed the same way, and a retry can create a second one. |
| **2xx, read refused** — a paged search (`board_my_cards`' lane, coord and `tag` reads; `board_create_card`'s archived-side idempotency read and its post-create duplicate read) that the bridge could not report complete | **502** (retryable), the same body a 5xx gets — over ssh exit **2** (card#10653) | kanban answered, but the page walk could not use the answer. Which answers do that is owned by `KanbanClient::pagedSearch`'s docblock (*THE REFUSALS*), not restated here. One of them — two walks in a row falling short of the total kanban declared — is also what a card leaving the board mid-read looks like; the bridge re-reads once to absorb that, and a retry absorbs a second. The bridge log's `kanban_client.board_read_refused` line names which cause fired. On `board_create_card` it depends on which read was refused. The archived-side read runs before the create, so a refusal there created nothing. The duplicate read runs AFTER the card exists, so a 502 there follows a create that landed. Retrying with the same `idempotency_key` returns that card rather than making a second. |
| **no answer** — the connection failed or timed out | **502** (retryable), the same body a 5xx gets — over ssh the same envelope and exit **2** (DL-387) | the bridge's HTTP client raises one exception class for every request that got no response, and the dispatcher maps it beside the 5xx. The board may or may not have acted before the answer was lost — for a write, assume it may have landed. The `5xx` row's warnings hold here too: a retried `board_comment_card` can post twice, and a retried `board_create_card` without an `idempotency_key` can create twice. ⚠ One call keeps its own answer: `board_create_card`'s placement read-back runs after the card exists and reports `placement_observed: false` instead. |

⚠ **Every 422 above writes and creates NOTHING** — the refusal is the whole outcome.

⭐ **Why this matters more than the status code:** a `502 upstream board error` is an
instruction to RETRY. Handing it to a seat for a rotated token or a too-narrow token scope
puts that seat in a retry loop against a cause no retry can change, with no diagnosis — and
the operator, who is the only party who *can* fix it, never hears about it. Every refusal
above names what to go and audit, and says when the cause is an INSTALL fault rather than
something your arguments can fix. The mapping is `App\Bridge\Tools\BoardCallRefusal`, one
classifier for the whole door (DL-326 built it inside `board_correct_card`; DL-339 hoisted it
and migrated `board_my_cards` and `board_create_card`).

⛔ **One deliberate exception to this mapping, on `board_create_card` only** (`board_comment_card`
follows the mapping, but a retry of it is not safe — see the `5xx` row). The post-create re-read
(DL-198 leg 2, the duplicate collapse) runs only when you passed an `idempotency_key` — i.e.
exactly when a retry is idempotent by construction — and the card has **already been
created** by then, so "permanent, do not retry" would be the wrong instruction. That leg
keeps the retryable 502: your retry re-enters the correlate-before-create read, which hands
back the card if the fault cleared and names the install fault if it did not.

### What a board 422 relays (DL-384)

**This section OWNS what a write's 422 refusal carries; the rows above point at it.** When kanban
answers **422** to a write — `board_create_card`'s create, the PATCH of `board_correct_card` or
`board_take_card`, `board_comment_card`'s POST — the refusal has two parts, in this order:

1. **What the bridge's own checks established, and nothing more.** A write that reached the board
   passed them, so the refusal says they passed and never tells you to shorten a field they cover:
   for a create or a correction, any `title`/`name` you sent is within kanban's `name` cap and each
   tag you passed within its tag cap; for a comment, the body is within kanban's `content` cap.
   ⚠ A tag the bridge writes itself (`created-by:<you>`, and on a correction every tag kept from
   the card) is not a tag you passed, and no check bounds it — so a pass does not say what the
   board refused. A long agent name alone can make the `created-by:` tag longer than kanban's tag
   cap; the board's reason then names that `tags.N`. The `idem:<you>:<key>` stamp is bounded: a key
   that would push it past the cap is refused before any request (the `idempotency_key` row above).
2. **The board's own reason**, last, read from the 422 body:

| The 422 body | The refusal ends with |
| --- | --- |
| Laravel's validation shape, `{message, errors: {<field>: [<message>, …]}}` | ``The board's own reason (its text, redacted and bounded by the bridge): `<field>`: <message> \| …`` — every message of every field, in body order; a nested object becomes a dotted path (`payload.origin`); a bare-string `errors` is relayed with no field. Laravel's `message` is dropped beside field errors: it summarises the first of them. |
| JSON with no usable `errors` and a string `message` | `The board named no field; its own message (redacted and bounded by the bridge) is: <message>` |
| JSON with neither | `The board's 422 body named no field and carried no message, so it gave no reason to relay.` |
| Not JSON (a proxy's HTML page, say) | `The board's 422 body is not JSON the bridge can read (<N> bytes), so none of it is relayed.` |
| Empty | `The board's 422 carried no body, so it gave no reason to relay.` |
| Over the byte bound | `The board's 422 body is <N> bytes, over the <bound>-byte bound the bridge relays from, so none of it is shown.` |

**Bounded and redacted.** `App\Bridge\Tools\BoardCallRefusal::boardReason()` is the one primitive,
and its constants own the figures (the body byte bound, the entry count, the total size) — read
them there. Each field name is redacted by `SecretScrubber` as bare text. Each message is redacted
twice: as the bare message (so JSON embedded in it is recognised), then with its own key in view (so
a value under a nested credential-named key is redacted). Both are then rendered by
`UntrustedText::forOperator()`: whitespace collapsed to one line, control and bidi characters escaped,
and the span cut with a `[TRUNCATED, <N> SOURCE CHARS]` marker. Entries past the count or size bound
are counted, `[<N> MORE NOT SHOWN]`, and not shown.

- ⚠ **The relayed text is the board's, not the bridge's.** It is bounded in size, not in meaning.
  The redaction is at least `SecretScrubber::text()`'s on each field name and message, with every bound that class states.
- **The shape did not change:** the reason is inside the `error` string, and `{ok: false, error}`,
  the 422 status and the ssh exit `1` are as before.
- **A 422 on a READ relays nothing** and stays the retryable 502 (the row above says why).

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

⚠ **A bridge whose own call to kanban got no answer is not a row here**: since DL-387 it answers
the `502` envelope (the *no answer* row above), exit 2 over ssh, and the channel server relays that
body as an error. A bridge from before DL-387 exits 1 over ssh instead, and a seat sees the first
row with the uncaught exception (`ConnectionException`, `cURL error …`) as its partial output —
read that before suspecting the key or sshd.

A seat on a snapshot older than 0.9.8 gets the second message for **both** of the first two
rows — see § Staying in sync in [`examples/channel-servers/README.md`](../examples/channel-servers/README.md)
for reading the deployed version.

## Calling a tool from a script

A hook or a script cannot call these tools through the channel server — they are MCP tools, and only the session speaks MCP to it. From client **0.9.41** the client pack carries **`bridge-board-call`**, which makes ONE call as the seat, over the seat's own configured transport and credential (card#11151, DL-451):

```bash
bridge-board-call board_take_card '{"card_id":123,"start":true}'
```

- **No identity argument, and no new credential.** It reads the transport the seat's channel server is configured with — its `.mcp.json` `env` block over the calling environment — and the bridge resolves the seat from that transport's credential exactly as for the channel server: the pinned forced command's `--agent`, or the bearer. A script can therefore act only as its own seat.
- **One-shot on both doors.** The ssh door's forced command (`bridge:tools-call`) takes one request body on stdin and writes one JSON envelope; the HTTP door is one `POST /agent-tools/call`. Neither speaks MCP, so the CLI sends the channel server's request body — `{tool, args, client_version}`, plus `caller` (below) — over the channel server's own round trip.
- **It prints the door's answer and exits on a contract** that tells a tool's answer (ok, or a refusal whose `reason` — [tabled above](#the-start-form-start-true-card11150--dl-449) — is what a caller branches on) from a call that reached no tool and from one whose outcome is unmeasured. [`examples/channel-servers/README.md`](../examples/channel-servers/README.md) § *Calling a board tool from a script* owns the exit codes, where the CLI reads its configuration from, and its bounds; they are not restated here.
- ⭐ **A coded install fault is a refusal, never a success.** Every `install_fault.*` code above arrives as a 422 (exit 1 over ssh) — the door's refusal shape — so the CLI reports it as the tool's refusal, code and text intact: a seat with no kanban user gets `install_fault.no_kanban_user`, printed and non-zero, never an unassigned move. The door's UNCODED install answers are not refusals; the README section above owns how the CLI reports them.
- ⚠ **What the CLI cannot tell apart over ssh:** the ssh door renders the `502` (which may follow a write that landed) and its own install answers alike as exit 2 with no code (the paragraph under the codes table), so the CLI reports either as unmeasured, never as "reached no tool".
- **It is not the channel server, and says so:** it sends `caller: "script"` and no `launch`, so the call does not overwrite what the seat's channel server last reported to the fleet ledger (below).

## The client-update door (DL-430)

A seat's channel server updates itself from its own bridge at launch (card#10568, DL-434) — the seat half is `examples/channel-servers/entry.mjs` and `client-update.mjs`, described in that directory's README § *Installed and updated by the bridge*. The bridge half is a separate door, **not a board tool**: `POST /agent-tools/client` behind the same loopback gate and bearer as `/agent-tools/call`, and, on the ssh transport, the same pinned `bridge:tools-call` forced command given a body carrying `op` instead of `tool`. Both transports answer the same bytes. It never goes through the board-tools dispatcher, so no tool refusal, board outage or client-version rule can stand between a seat and the pack that fixes it.

It serves `client_manifest` (what this bridge publishes, the release a seat should install, whether an approval is owed, and the last install-log entry this bridge holds for the seat), `client_pack` (that release's pack, base64), `client_report` (the seat's install-log lines, chain-checked) and `client_fleet` (every seat's reported client and state — only to an agent with `board_tools.fleet_view: true`). The request and response shapes, and every refusal, are owned by `App\Bridge\ClientUpdate\ClientUpdateDoor`'s class docblock; this section deliberately does not restate them. What it serves is whatever `php artisan bridge:client-pack:install` last published (CLAUDE_DEPLOYMENT.md § Commands); until that has run, `client_manifest` and `client_pack` answer `503` and a seat keeps its installed client. Each release's pack is attached to its GitHub release by the release workflow (DL-442), and `bridge:check`'s `board_tools.client_pack_source` leg warns until this checkout's release is the published one.

**The fleet ledger (DL-432) and approval (DL-433).** What each seat reports — through `client_report`, and through two optional keys on every board-tools call, `caller` (a caller that is not the seat's channel server declares one of `App\Bridge\ClientUpdate\ExemptCaller`'s cases — the enum is the list — and then never overwrites the seat's own report) and `launch` (`{id, bridge_release}`, sent by a client the updater started) — lands in one row per agent. `php artisan bridge:client-fleet` prints each seat's state, and `bridge:check`'s `board_tools.client_fleet` leg warns on the seats that need you. For an agent with `board_tools.client_update.approval_required: true`, `client_manifest` offers nothing until `php artisan bridge:client-approve` has approved the published pack's content for it; a seat that installs without that approval is reported, never blocked. **Client 0.9.29 sends both** (card#10568, DL-434), but only once it is started through its updater's entry point, `<root>/entry.mjs` — getting a seat there is the bootstrap, `provision-board-tools.py --role b --bootstrap-client` (DL-444; below). Until a seat is bootstrapped it sends neither, and a calling seat reads `off_update_path`.

## How it is wired (operator view)

There are **two front doors** into the same dispatch machinery, selected per agent
by `board_tools.transport` (`http` | `ssh`, the default **since v0.68.0 / DL-225**;
before v0.68.0 the default was `http`). Both resolve the caller's
identity, then run the identical `BoardToolDispatcher` onto the shared least-privilege
writeback client — so a TOOL CALL's response body is byte-identical whichever door served it.
⚠ **Except a body carrying `op` (DL-430):** the ssh door answers it as a client-update request
(see *The client-update door* above), while `POST /agent-tools/call` ignores the key and
dispatches the body as a tool call. The client-update door has its own HTTP route; send `op`
there, never to `/agent-tools/call`.

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
  **ssh** agent it mints no secret (the private key is the seat's) — it **prints that
  agent's BOARD-TOOLS SETUP PACKET** (card#8971 / DL-357): the five-step, three-actor
  enablement exchange, with this install's own params filled in (`--agent` from the
  config, `--artisan`/script/storage paths from the install, the forced-command account
  from `board_tools.ssh_account`, and the git ref this box runs). ⛔ **Who runs which
  step, why STEP 3 is a process control a HUMAN performs, and how the key line and
  fingerprint are handed over are owned by
  [`docs/board-tools-enablement.md`](board-tools-enablement.md)** — not restated here.
  `--host-a=`, `--ssh-port=` and `--pubkey-from=` fill the packet in as those values
  become known; each is refused without `--agent`, and a `--pubkey-from` file that is not
  exactly one well-formed public-key line is refused before any pin command is printed.
  The static `bin/provision-board-tools.py` program owns both legs from a single
  source that cannot drift: `--role a` (root, Linux, on the bridge box) pins the
  forced-command `authorized_keys` line — the **sole** security boundary — and makes
  **no** `sshd_config` change (card 5091 retired the account-level `Match User`
  hardening; see `docs/multi-host.md § 3`); `--role b` (the calling seat, cross-platform
  python) generates
  the FIPS ECDSA P-256 key, deploys the bundled channel-server snapshot, and merges
  `.mcp.json`.
  **The key is derived ONCE (card#8972):** without `--ssh-key`, `--role b` derives
  `~/.ssh/<agent>-board-tools` from `--agent`, generates it if absent, and records it.
  **`--ssh-key <path>` means "use THIS existing key"** — the flag never generates one, and
  that path is then what is printed for the host-A handoff, what `--self-cert` probes, and
  what is recorded. Either way **`BRIDGE_TOOLS_SSH_KEY` is always the key the run actually
  used** — it is no longer possible to pin one key on host A and record another in
  `.mcp.json`. What the flag asserts, and what is therefore **checked before anything is
  handed off**:
  - **both halves exist.** A missing half refuses, naming the given path and the default
    it would otherwise use; when only the `.pub` is missing the refusal prints the
    `ssh-keygen -y -f <key> > <key>.pub` that regenerates it.
  - ⭐ **they are two halves of ONE pair.** Existence is not the contract — the public
    half pinned on host A has to be the one this seat can present. `ssh-keygen -y` derives
    the public half from the private one and the **type + blob** fields are compared (the
    comment is not: `-y` prints the comment stored in the *private* key, which legitimately
    differs). A mismatch refuses; without this check the pin succeeds and every later board
    -tools call fails `Permission denied (publickey)`.
  - **the private half is passphraseless.** The channel server spawns ssh in **BatchMode
    with no agent**, so an encrypted key can never be unlocked at call time whatever the
    pin says. The same `ssh-keygen -y -P ''` answers this, and the refusal names BatchMode
    as the reason.
  - ⚠ **its permissions are VERIFIED, not rewritten.** A key the tool generated is
    hardened by the tool (`chmod 600`; on Windows `icacls /inheritance:r` + an owner-SID
    grant). A key you *named* is only judged: the same refuse-if-broader decision runs and
    a too-open key **refuses with the `chmod 600` / `icacls` command to run**, because
    provisioning must not silently re-permission a file it does not own — an
    `/inheritance:r` in particular drops every inherited ACE and is not undoable from what
    this tool knows. On Windows the **`.ssh` directory decision runs before any file ACL is
    touched**, so a refusal never leaves a rewritten ACL behind.
  The merge treats the SSH tools transport keys it owns
  (`BRIDGE_TOOLS_SSH_TARGET`/`_KEY`/`_PORT`) as **ONE SET, reconciled** — every member the
  run declared is force-set and **every member it did not declare is REMOVED**. ⚠ So
  omitting `--ssh-port` on a re-provision **drops** a port an earlier run set, rather than
  leaving it in place: pass `--ssh-port` every time you want one. (Before card#8972 the
  merge only ever `update()`d, so `_PORT` survived every later run that omitted it and the
  channel server kept spawning `ssh -p <old port>`.) The reconcile is scoped to that set,
  so `BRIDGE_CHANNEL_TOKEN` and the channel vars below are never collateral. It only
  **creates the live-wake channel
  vars (`BRIDGE_CHANNEL_TRANSPORT`/`_NAME`) if absent** — a re-provision never
  overwrites an existing seat's channel transport (e.g. an HTTP live-wake fallback),
  only bootstrapping the platform default on a fresh `.mcp.json`: **`unix` on POSIX,
  `http` on Windows** (Node on Win32 rejects filesystem socket paths, so `unix` is
  unusable on a fresh Windows seat — `http` is the only working channel transport there).
  Its pubkey validator is
  a **full-line shape check** (rejects multi-line /
  CRLF pastes), superseding the prefix-only guard the old generated bash carried.
  **`--role a` needs root only to write ANOTHER account's `authorized_keys`** (card#8971):
  where the forced-command account is the one running the command it pins with **no
  `sudo`** — that account can already write its own file — and every other non-root
  combination is refused by name. Both arms print the path they wrote and note that
  **sshd's `AuthorizedKeysFile` is not resolved by this tool**. `--expect-fingerprint`
  (optional, both roles; bare `SHA256:…` or a whole `ssh-keygen -lf` line) refuses on a
  mismatch printing both values — ⛔ a **transcription** guard, never a checkpoint, since
  any holder of the `.pub` can compute it. A hand-edited `authorized_keys` line naming the
  same agent with different options is **refused rather than appended beside**, and a
  tool-shaped line for that agent whose forced command differs (another checkout's
  `artisan`, another timeout, extra options) is **refused rather than reported as
  *already present (same key)*** — sshd runs what that line says. **TWO tool-shaped lines
  for one agent are refused too**, naming each by its position in the file: only the first
  was ever examined, so a duplicate carrying a SECOND key stayed authorized behind an
  *already present* that was reading line one. ⚠ The same-agent scan behind the first of
  those three is a **heuristic over the bare `--agent=<name>` spelling**, and a hand line
  written `--agent="<name>"` is **not seen** — a declared bound, whose miss direction is
  the behaviour that was already there.
  **`--role b --certify-only`** fires the ssh round-trip using the target and key the
  seat already recorded in its own `.mcp.json` — no keygen, no snapshot deploy; it needs
  `--agent --project-dir --channel-name` and refuses `--ssh-target`/`--ssh-key`, because the
  recorded values are the ones the channel server will actually use. **Once that round-trip
  succeeds it bootstraps the client** (DL-445) exactly as `--bootstrap-client` below does —
  its one `.mcp.json` write — with two differences: a seat whose `.mcp.json` already starts
  it from a client root is left alone (re-certifying is not a request to reinstall; a recorded
  root `entry.mjs` that is gone is bootstrapped again, without the fallback), and
  when the bridge answers and offers nothing to install right now (nothing published, a 5xx,
  a bridge older than the client-update door, approval owed) the seat **keeps the legacy snapshot** `--role b` deployed, a `CLIENT NOT
  BOOTSTRAPPED` line says it will not update itself, and the command still succeeds — the
  LEGACY FALLBACK. Any other bootstrap failure fails the command. **`--role b --self-cert`**
  does the same after its round-trip succeeds; a failed round-trip never bootstraps.
  **`--role b --bootstrap-client`** (card#10568, DL-444) moves an already-provisioned seat
  onto the self-updating client: it asks the bridge over the transport the channel's
  `.mcp.json` env records — ssh **or HTTP**, in the environment a launch would build (this
  shell's, overlaid by that env, with Claude Code's `${VAR}` / `${VAR:-default}` expansion;
  a door key this shell sets and the channel does not record — a `BRIDGE_TOOLS_*` key, or
  `BRIDGE_CHANNEL_TOKEN`, the fallback bearer — is named on a `note:` line, never its value)
  — installs the pack the bridge OFFERS into the seat's client root
  (`${XDG_DATA_HOME:-~/.local/share}/agent-webhook-bridge/client/<channel>`, or
  `%LOCALAPPDATA%\agent-webhook-bridge\client\<channel>`), and only then sets the
  channel's `command`/`args` to `node <root>/entry.mjs`, through the same merge `--role b`
  uses (the env block is unchanged, except that an ssh entry's HTTP sibling keys are dropped
  as that merge always does). The fetch,
  checks and install are `client-update.mjs bootstrap` from the provisioner's own checkout,
  so nothing fetched is run. It needs `--agent --project-dir --channel-name`, refuses the
  transport flags. It is **refused before anything is installed — `.mcp.json` and the
  installed client as they were — when approval is owed** (it names the release and
  `bridge:client-approve`), when the bridge publishes no pack, or when a check on what it
  sent fails; any failure leaves `.mcp.json` unchanged, and one after the switch (DL-444
  bound 9) leaves the new release installed for a re-run to point at. Run again, it installs
  what the bridge offers now, repairing the root when that is the installed release; after a
  killed run, only once that run's lock has expired. For an ssh seat the key must already be
  pinned. A later `--role b` on a bootstrapped seat refreshes the transport and **keeps**
  `<root>/entry.mjs` (no snapshot is deployed); when that file is established gone (an
  absolute recorded path with no `${…}`) it deploys the legacy
  snapshot, points the channel at it and says the seat will not update itself.
  For a bootstrapped seat, point `channel.server_path` at its client root (or leave it
  unset across hosts, as for any seat): `bridge:check`'s snapshot legs check the release its
  `current.json` names (DL-445; `docs/config-schema.md` owns the verdicts).
  **`.mcp.json` is never written in place:** the merged config is serialised to a sibling
  `.tmp`, compared against what is there, and `os.replace`d in — an unchanged re-run
  writes nothing (it prints `unchanged`), a changed one first copies the previous file to
  `.mcp.json.bak-<UTC>` (0600) and prints that path, and a failure mid-write leaves the
  seat's live `.mcp.json` byte-identical with no temp file behind.
  A **stale channel-server snapshot is renamed aside, never deleted** — `.channel-server`
  becomes `.channel-server.stale-<deployed version>` (suffixed with a UTC stamp if that
  name is taken), and the printed message names **both** dispositions: how to roll back
  (move that path back over `.channel-server`) and that it can be discarded *once the new
  snapshot is confirmed working*. ⚠ **What is left behind on a mid-deploy failure is the
  retained tree, not a running channel server:** if the copy or the `npm ci` fails after
  the rename, `.channel-server` is absent or half-populated and `.mcp.json` still points at
  it — the seat is **down until you roll back**, which is exactly why nothing is deleted
  and why the rollback is printed. The Node ≥ 20 precheck runs **before** the rename, so
  the most likely refusal on a fresh seat happens with the deployed tree still in place and
  nothing to undo.
  **`known_hosts` is seeded unconditionally** (an `ssh-keyscan` of the `--ssh-target`
  host), with or without `--self-cert` — so **a successful keyscan is NOT evidence the
  board-tools door is live**: it only proves the host answers on the ssh port. Only
  `--self-cert`, run *after* host A has pinned the key, certifies that door.
  Which line runs where, and in what order, is the packet's job to say — a same-box Linux
  run hands the `.pub` path to `--role a --pubkey-from` (no paste) and collapses further
  still into the wrapper below.
  Windows host B is supported: the host-B leg is cross-platform python and the Windows
  path (`%USERPROFILE%\.ssh`, icacls-based key hardening in lieu of `chmod 600`, and a
  Win32-OpenSSH precheck that fails closed if `ssh.exe`/`ssh-keygen.exe`/`ssh-keyscan`
  are absent) was validated on a real en-US Windows 11 seat. The `ssh -i` round-trip
  (`--self-cert`) is the authoritative permission check; the icacls SID-based ACL
  assertion (refuse if the private key is readable, or its `.ssh` dir writable, by any
  principal beyond `{owner, SYSTEM, Administrators}`) is defense-in-depth. The seat
  certifies itself with the packet's STEP 4 (`--role b --certify-only`); ⛔ a
  `--probe-tools-ssh` run from the BRIDGE box stamps the same ledger row and is not
  evidence about the seat (DL-229).
  **Locale-independent (card#5053).** The icacls decision is pinned by **well-known SID**,
  not by the localized account name icacls prints: principals are resolved to their SIDs
  through the OS (a `LookupAccountName`-equivalent), which returns the same fixed SIDs on
  a localized Windows as on en-US. The en-US name table survives only as an offline
  fallback when that lookup is unavailable, and an unresolvable principal is kept raw so
  the decision still fails **closed** (a spurious refuse, never an unsafe accept).
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
- **⭐ There are TWO REPORTED lines since DL-316, and the difference is what they CLAIM.**
  (Both were `ok` until DL-364, which added the version clause below — either line reads
  `warn` when the seat's reported snapshot version is older than the one it is compared with
  (the published client, else the bundled one — the version clause below), and the
  distinction drawn here is unaffected by that: it is about the CLAIM, not the severity.) The ssh door records how the serving process was started, so a
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
- **⭐ The REPORTED line also carries the seat's own channel-server VERSION, and WARNS when
  it is behind (card#8974 / DL-364).** Everything else `bridge:check` knows about the door
  is the bridge's half; which snapshot the seat actually runs is a fact only the seat can
  supply, so the channel server sends its own `package.json` version on every call and the
  bridge records it beside the call. **Measured, and the reason this exists:** a seat on
  **0.4.4** called a bridge bundling **0.9.12**, `board_correct_card` — a tool the older
  snapshot never advertised — was reported *"absent from my surface"*, and it was
  attributed to the **BRIDGE**, because nothing compared the two numbers. The line now
  prints both.
  - ⭐ **Which version it is compared with (card#10568 comment 7177, DL-445).** Once this
    bridge PUBLISHES a client pack, the reported version is compared with the **published
    client**, never this checkout's bundled copy — seats take their client from the pack now.
    Behind it ⇒ **`warn`**, and the remedy is a **restart** for a seat on its client root (it
    updates itself at launch) or the **bootstrap** (`provision-board-tools.py --role b
    --bootstrap-client` on the seat) for one still on a copy — never a re-copy, which would take
    a seat off the update path. For an agent with `board_tools.client_update.approval_required`
    whose published content is not approved, the remedy is `bridge:client-approve` (the bridge
    offers that seat nothing until then). A published record that cannot be read ⇒ **not
    compared**, never a fallback to the bundled copy (`board_tools.client_pack_source` names its
    recovery). **With nothing published**, the arms below are exactly as they were, against the
    bundled snapshot:
  - reported and **older** than the bundled snapshot ⇒ **`warn`**, naming both versions and
    the remedy: re-copy this checkout's `examples/channel-servers` over the seat's deployed
    directory, `npm ci`, and **restart that session** — the version is read when the channel
    server starts, so a re-deploy alone does not change what the line reports.
  - reported and **at or ahead of** the bundled snapshot ⇒ `ok`, both versions printed. A
    seat AHEAD is not a fault: that is a rollout in progress, and the warn's remedy would be
    a downgrade.
  - **not reported** ⇒ `ok`, and the line says so — a client older than the first reporting
    snapshot (**`0.9.15` is the first reporting snapshot**: the first `examples/channel-servers/`
    release that sends the field at all, frozen as `App\Bridge\Tools\ClientVersion`'s
    `FIRST_REPORTING_SNAPSHOT` and held in lockstep with this sentence by
    `tests/Unit/Docs/ClientVersionFloorLockstepTest.php`), or a caller that is not a channel
    server at all (`--probe-tools`, `--self-cert`, a hand-run `bridge:tools-call`), sends no
    version. ⛔ **An absent report is NOT a stale seat** and is never warned as one.
  - ⚠ **The exit code does not move on any of these** (DL-037 #2 / DL-039: only `fail`
    flips it), and **nothing about this field can refuse a call** — a call carrying no
    version, or a value the bridge will not take, is accepted exactly as it was before the
    field existed and records *no report*.
  - ⚠ **A later call that reports NO version CLEARS the recorded one**, because it is the
    last call that is being described. `--self-cert` and a hand-run `bridge:tools-call` are
    the routine way that happens; the line then reads *not reported* until the seat calls
    again.
  - ⛔ **A SEAT ON A COPY BELOW THAT FLOOR LANDS ON THE *not reported* ARM** — `ok`, *not
    reported*, nothing compared. The surface that reports staleness is distributed BY the
    artifact whose staleness was the problem, so the range it can never speak about is exactly
    the range that predates it — a seat below the floor cannot be told by this leg that it is
    below the floor. The bootstrap (`--role b --bootstrap-client`, then a session restart) takes
    it past the floor once; [`CLAUDE_DEPLOYMENT.md`](../CLAUDE_DEPLOYMENT.md) § *Multi-agent
    channel-server distribution* owns moving a copied seat.

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

> **Wiring an SSH-transport agent, or a seat on another box?** This section is the HTTP
> door's runbook. Who does what for the ssh door — and why one of its steps is a human's —
> is [`docs/board-tools-enablement.md`](board-tools-enablement.md); the steps themselves
> come from `bridge:provision-tools --agent=<name>`.

> **⭐ You do not have to remember to come here — `bridge:check` sends you.** Since DL-352
> the command a fresh install already runs ends with a **NEXT STEPS** block naming what is not
> wired end to end, the state it stopped in, and the ONE command to run next; `--format=json`
> carries the same entries as `next_steps[]`. ⛔ **The entry shape and the `state` vocabulary
> are owned by [`docs/check-json-contract.md` § 7a](check-json-contract.md#7a-next_steps--what-to-run-next)
> and are deliberately not restated here** — the key set stated in this paragraph was already
> false one release later (card#9150 / DL-368 added `scope`), which is the drift the same
> paragraph's own rule about state meanings exists to prevent.
>
> ⚠ **The block is no longer board-tools-only, and it no longer implies the run passed.** Since
> DL-368 it also carries a `github_webhook_missing` entry, whose fault IS a `fail` — so an
> install printing that entry exits non-zero — and since DL-382 a `github_delivery_silent`
> entry, whose fault is a `warn` and moves nothing. The block itself still yields no finding and
> moves no exit code of its own; what changed is that a fault it points at can. An install with
> nothing outstanding prints no block at all. **That block is this section's entry point**, so
> the normal way in is to run `php artisan bridge:check` and follow the line for your agent
> rather than to read all seven steps first.
>
> What each `state` means is defined ONCE, in
> [`docs/check-json-contract.md § 7a`](check-json-contract.md#7a-next_steps--what-to-run-next)
> (owner: `NextStepState`'s docblock) — not restated here. How they map onto the steps
> below: `no_block` → steps 3–4; `bridge_side_incomplete` → the `bridge:provision-tools`
> line the entry prints, then re-run; `bridge_side_unverified` → **re-run as the account
> that can read** (`sudo`), and do **not** re-provision on that line alone — nothing was
> measured; `seat_side_unreported` → steps 5 and 7, on the seat. ⛔ **The last one cannot
> be cleared with `--probe-tools`** — step 6 explains why: that probe stamps the very
> ledger row the state is read from, *from this box*, so it would silence the line without
> the seat ever having called. **An agent that does not want board tools declares
> `board_tools:` with `enabled: false` — while the block is present**; a declined capability
> is a decision and the line stops printing. ⚠ **Deleting that YAML is a different act.** An
> `enabled: false` block is a decision only while something states it, so deleting the file
> re-opens the question — and if this install ever recorded an enabled block for that agent,
> it re-opens as a **LOST** failure whose remedy is an explicit retirement. See
> **[A restored install](#a-restored-install)** and **[Retiring a seat](#retiring-a-seat)**.

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
⚑ **It also stamps the version half as *not reported* (DL-364)** — `--probe-tools` is not a
channel server and sends no `client_version` — so the line reads `CLIENT VERSION NOT
REPORTED` until the SEAT calls. That is the honest reading of this step, not a fault, and it
is the same reason a later `--self-cert` or hand-run `bridge:tools-call` CLEARS a version an
earlier seat call recorded.
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

⚠ **On a seat whose session is already running, that means restarting the SESSION** —
/mcp reconnect does not stop the previous channel server — restart the session. See
[`docs/board-tools-enablement.md` § Activating on a running seat](board-tools-enablement.md#activating-on-a-running-seat),
which owns the mechanism, the causes of the bind failure, and who does the restart.

### A restored install

**If `bridge:check` prints `board_tools: agent <name>: block LOST`, this install once had a
working `board_tools` block for that seat and now has none.** The line is a **fail** and it
flips the exit code — the command is refusing to certify the install (card#8973 / DL-360).
It is not derived from the current config, which no longer holds the evidence: it is derived
from a row in the bridge's own database recording that a previous run PARSED an enabled block
for that agent. That is why a home-dir restore, which brings the config tree back without the
block, cannot make the line go away by itself.

**⚠ The witness survives what killed the config only under a stated condition: the bridge
DATABASE must not live inside the restored tree.** That holds for MariaDB and for a SQLite
file outside the restored path. It does **not** hold for a SQLite file under it — there the
row dies with the config and the leg has nothing to say, which is a gap rather than a
guarantee.

**There are exactly two remedies, and only you know which applies:**

1. **The block should still be there** — a restore or a hand edit dropped it. Re-add it from
   the deploy's source of truth and re-run `bridge:check`. The line goes away because the
   block is back, not because anything was silenced.
2. **The seat is genuinely gone** — decommissioned, renamed, moved to another host. Say so:
   [Retiring a seat](#retiring-a-seat).

**⛔ `--probe-tools` cannot clear it, and neither can any other probe.** Those stamp the
CLIENT-CALL ledger, which this leg never reads as a trigger — it quotes a client call inside
the line as evidence and nothing more. The only things that move this verdict are re-adding
the block and retiring the seat.

**⚑ The `no_block` NEXT STEP is deliberately NOT printed for a lost agent.** That question
("should this agent be able to read, file and correct its own cards?") offers `enabled: false`
as one valid answer, which would MUTE the failure instead of answering it. One voice per
agent: the FAIL above carries the remedy.

## Retiring a seat

**Deleting a seat is a decommission, and this bridge asks for the decommission to be stated**
(card#8973 / DL-360). An `enabled: false` block is a decision while the block is present and
nothing at all once the YAML is deleted, so a declining seat whose file is removed re-opens as
a LOST failure. The statement that closes it is one key:

```yaml
board_tools:
  retired: "2026-09-08 — seat decommissioned, host retired"
```

The value is yours, stored verbatim and printed back to you; the date rides inside the
sentence rather than being parsed out of it.

**The sequence, in this order:**

1. Add the `retired:` key to `<name>.yml`.
2. Run `php artisan bridge:check` and **read the line**. You are waiting for
   `board_tools: agent <name>: RETIRED — <your reason> (tombstone on record)`.
3. **Only then** delete the YAML, if you want it gone.

**⛔ Step 2 is not a formality, and the line confirms the ROW rather than your config.** The
tombstone write is best-effort — it is deliberately allowed to fail rather than break a check
run — so a run can print
`retired in config but the tombstone could NOT be recorded (see the log)` instead. That is a
WARNING line, not a green one — the row WAS read and the tombstone is not there — so delete the
YAML on the strength of it and the only statement of your decision goes with it, and the seat
comes back as a LOST failure with nothing left to retire it with. ⚠ It does not flip the exit
code: `bridge:check` can exit 0 with this line printed, so read the line rather than the code.

**What clears a tombstone: re-adding an enabled block.** Putting a working `board_tools` block
back for that agent clears the retirement on the next run — re-adding the seat re-opens the
question the retirement closed, and the leg starts watching it again.

**A renamed or removed agent whose YAML is already gone** cannot be given a `retired:` key,
because there is no file to put it in. The cure is in the FAIL line itself: recreate
`<name>.yml` holding only the `board_tools: {retired: "…"}` block, run `bridge:check` once so
it prints `RETIRED`, then delete the file.

**⚠ A bridge OLDER than the release that added this key does not understand it.** It parses
`retired:` as an unrecognised key on a default-on block, SUPPRESSES the agent, and
`bridge:check` FAILs on the suppression. Roll every install that reads this config forward
before adding the key.

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
3b. **`--role b --certify-only` as the agent user**, from the **agent's own checkout**
   (DL-445): one real round-trip through the key just pinned, then the client bootstrap —
   installed under the AGENT's home, never root's (design review r3-B1). The legacy fallback
   applies (see `--certify-only` above). An agent checkout whose `--help` does not say its
   `--certify-only` bootstraps predates it (DL-445 bound 3 — not the `--bootstrap-client` flag,
   which DL-444 shipped earlier), and a `--help` that could not be asked is said as such; either
   way the step is skipped with a `CLIENT NOT BOOTSTRAPPED` block naming the command to run. A
   failure here is held
   until the banner, `bridge:check` and the `chown` below have run, then fails the wrapper.
4. Prints the one unavoidable **manual step**: restart the agent's Claude session so the
   channel re-spawns and reads the merged `.mcp.json`.
5. Certifies with `php <host-A artisan> bridge:check`.
6. `chown -R <ssh-account>:<ssh-account>` on the host-A `storage/` (a root-run `artisan`
   can leave root-owned logs).

`--dry-run` runs the read-only preflight and prints the exact argv for every leg without
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
