# Config schema (current-state reference)

The **what**: every configuration key the bridge reads, its type, default, and whether a bad value **fails closed** (throws/5xx/non-zero) or **warns**. The **why** of each choice lives in [`../CLAUDE_DECISIONS.md`](../CLAUDE_DECISIONS.md) (the `DL-NNN` log); this file is the flat reference so you don't have to reconstruct the schema by reading DL-002 + 005 + 006 + 007 + 008 + 010 + 011 + 014 as deltas. (B-11.)

There are three configuration surfaces:

1. **Per-install** — Laravel `.env` → `config/bridge.php`. Same for every agent on one install.
2. **Per-agent** — `<config_dir>/<agent>.yml`. One file per agent; the **filename is the agent name**.
3. **Secrets / state** — files under `<secret_dir>` / `<state_dir>`, not committed.

---

## 1. Per-install (`.env` / `config/bridge.php`)

| Env var | Type | Default | Notes / fail mode |
|---|---|---|---|
| `BRIDGE_DIR` | abs path | — | Base dir for per-agent YAMLs, `shared-identities.json`, secrets, tokens, and `state/`. `config_dir` + `secret_dir` default to it. |
| `BRIDGE_CONFIG_DIR` | abs path | `BRIDGE_DIR` | Override only when config lives elsewhere. Missing/not-a-dir → `bridge:check` **fails**; dispatch reads it per request. Group/world-accessible → **warn** (DL-014). |
| `BRIDGE_SECRET_DIR` | abs path | `BRIDGE_DIR` | Holds HMAC secrets + tokens. Not set / not absolute → `bridge:check` **fails**. Group/world-accessible → **warn** (DL-014). |
| `BRIDGE_INSTALL_SUFFIX` | string | `''` | `-prod` / `-dev` crosstalk marker. `InstallGuard` requires the DB name to **contain** the marker with `-`→`_` (i.e. `_prod` / `_dev`, as a substring) — else the receiver **5xx**s and `bridge:check` **fails** (DL-001). |
| `BRIDGE_RECEIVER_BASE_URL` | http(s) URL | — | This bridge's public webhook URL (used by `bridge:provision`). Malformed → `bridge:check` **fails**. |
| `BRIDGE_KANBAN_API_BASE_URL` | http(s) URL | — | kanban API base. Malformed → `bridge:check` **fails**. |
| `BRIDGE_GITHUB_API_BASE_URL` | http(s) URL | `https://api.github.com` | Only relevant if a github adapter calls the API. |
| `BRIDGE_GITHUB_TOKEN_PATH` | abs path | — | `bridge:reconcile` GitHub read token (DL-184). Authoritative when set: a missing/blank/insecure file **fails loud** with no store/env fallback. Absent → the conventional `<secret_dir>/github/token`, then store-native, then `GH_TOKEN`. |
| `BRIDGE_GITHUB_CREDENTIAL_HELPER` | helper name \| abs path \| `''` | `git-credential-coord` | `bridge:reconcile` store-native token leg (DL-185): resolves a **per-repo** PAT from the coordination store's `[git-credential-map]` via this helper (git wire-format). Bare name → PATH-resolved; a `/`-path used as-is; empty **disables** the store leg. Absent helper → falls through to `GH_TOKEN`. Needs `HOME`/`COORD_CREDENTIALS` in the reconcile env to locate the store. |
| `BRIDGE_STATE_DIR` | abs path | `<config_dir>/state` | `inbox*.jsonl` / seen cursors / handler logs. Point OUTSIDE the 0700 config dir for cross-user inbox reads. |
| `BRIDGE_INBOX_LAYOUT` | `shared` \| `per-agent` \| `both` | `shared` | Where staged lines land. Invalid → `bridge:check` + dispatch **fail closed**. |
| `BRIDGE_DEFAULT_AGENT` | agent name | — | Bare `bridge:inbox` surfaces this agent. Unknown name → `bridge:check` **warns**. |
| `BRIDGE_INBOX_FILE_MODE` | octal string | `0640` | Mode applied to per-agent **inbox** files (cross-user read). Seen-cursor files stay install-user-owned (default umask). |
| `BRIDGE_INBOX_GROUP` | group name | — | Group on per-agent inbox files. **Requires `per-agent` layout** (else `bridge:check` + dispatch **fail closed** — a group-readable shared inbox would leak every agent's intents, DL-006). |
| `BRIDGE_MAX_BODY_BYTES` | int | `262144` (256 KB) | Envelope size cap; oversize → `413` before HMAC work. |
| `BRIDGE_SPAWN_ENABLED` | bool | `false` | Registers the `spawn_detached` handler. Off ⇒ a `spawn_detached` target is a best-effort note, never an execution (DL-011). |
| `BRIDGE_SPAWN_ALLOWLIST` | comma-sep abs paths | `''` | `cmd[0]` must be in it. Empty ⇒ nothing runs. **Allowlist fixed-purpose wrappers, never interpreters** (DL-011). |
| `BRIDGE_SPAWN_SETSID_PATH` | abs path | _(auto)_ | Absolute path to the `setsid` launcher. Unset ⇒ auto-detect (`/usr/bin/setsid`, `/bin/setsid`). Pinned absolute so a payload `env` PATH can't redirect which `setsid` runs (DL-037); fail-closed if none is found. |
| `BRIDGE_CHANNEL_ALLOWED_SOCKET_DIR` | abs path | — | Prefix a **classifier-supplied** `channel_push` socket must sit under; unset ⇒ classifier sockets **refused** (DL-014). Agent-config sockets exempt. |

DB connection (`DB_CONNECTION` / `DB_*`) and `APP_*` are standard Laravel. The DB password lives in `.env` (`DB_PASSWORD`), never in the YAMLs.

---

## 2. Per-agent YAML (`<config_dir>/<agent>.yml`)

The **filename** (minus `.yml`) is the agent's canonical name and its echo "self" — there is **no `identity.self`** (DL-007). Malformed YAML throws at load → **5xx** (fail-closed; kanban-board holds + redelivers). `chmod 600` the file.

### `identity:` (optional mapping) — the agent's own immutable upstream ids
| Key | Type | Default | Notes |
|---|---|---|---|
| `kanban_user_id` | int | `null` | The agent's kanban `user_id`. Auto-seeded into self echo-suppression. |
| `github_user_id` | int | `null` | The agent's immutable GitHub `sender.id`. Auto-seeded into self echo. |
| `github_login` | string | `null` | **Display-only label** — never a matching key (renameable; DL-002). A stale value logs a one-line drift warning. |

Recognition keys on the numeric ids only; the agent never hand-lists its own id for echo (auto-seeded, DL-007). Modelled by `IdentityConfig` (DL-017).

### `subscriptions:` (expected list — an agent with none receives nothing) — which provider scopes this agent receives
Each entry:
| Key | Type | Default | Notes |
|---|---|---|---|
| `provider` | `kanban` \| `github` | — | Must have an adapter (`bridge:check` **fails** on an unknown provider, DL-015). |
| `scopes` | list | — | kanban board ids (`[5]`) / GitHub `org/repo` slugs. A scope failing `ScopeId` validation is rejected (it's the path-traversal boundary). |
| `event_filter` | list | `[]` (all) | e.g. `["task.*","comment.*"]`. |

Non-array `subscriptions`, or an entry that isn't a mapping, throws at load. Only `kanban` is API-provisionable (`bridge:provision` skips github with a non-zero exit).

### `echo_suppression:` (optional) — OTHER agents only (self is auto-seeded)
| Key | Type | Default | Notes |
|---|---|---|---|
| `treat_as_echo` | list of agent names | `[]` | Other agents' writes to silently skip (by name). |
| `treat_as_echo_ids` | list of raw ids | `[]` | Extra raw provider ids to skip (rarely needed; self ids are auto-seeded). |
| `treat_as_signal` | list of agent names | `[]` | If non-empty, **only** these agents' writes are signal. **Fail-closed:** a name with no matching `<name>.yml` throws at **preflight (`bridge:check`) / first dispatch** (5xx), not at YAML load (DL-007 — `SignalAllowlist`). |

### `classifier:` (optional)
| Key | Type | Default | Notes |
|---|---|---|---|
| `class` | FQCN | `App\Bridge\Classifiers\InboxOnlyClassifier` | Must be resolvable + implement `Classifier`; `bridge:check` resolves it so a typo **fails** at preflight instead of a dispatch-time 5xx. |
| `config` | mapping | `{}` | Typed parameters for a **config-driven classifier** (`ClassifierConfig`), so a shared reference classifier is parameterized without hardcoding per-project specifics. Absent ⇒ all-defaults (back-compat). A present non-mapping — or a malformed key below — **throws** (fail-closed, like the rest of the config). Read by a classifier via `$ctx->agent->classifierConfig`. |

#### `classifier.config:` keys
| Key | Type | Default | Notes |
|---|---|---|---|
| `scope_author_map` | mapping | `{}` | `scope_id` (repo) ⇒ the sole author-agent on that repo — the primary attribution path for a **label-less** impl event (falls back to the `from:`/`FROM:` line). Keys lowercased for case-insensitive scope matching; a non-string key/value **throws**. |
| `families` | list<string> | `[]` (classifier default) | The event families a config-driven classifier runs (its config-gated pipeline). Lowercased; a blank entry **throws**. Recognized by `CoordinationClassifier`: `coord-message` (GitHub coordination messages), `impl-ci-wake` (push→release-branch + CI wake), `kanban-triage` (wake the triage owner on a human-filed untriaged card — DL-168). An unknown family is ignored (forward-compat). Empty ⇒ the classifier's own default (`CoordinationClassifier` → `[coord-message]`; the `KanbanTriageClassifier` shim → `[kanban-triage]`). |
| `impl_repos` | list<string> | `[]` (all subscribed) | **`impl-ci-wake` family.** Gate the impl wake (push→release-branch / `workflow_run` CI) to a repo subset — the family fires only when the event's `scope_id` is in this list. Lowercased for case-insensitive matching. Empty/absent ⇒ every subscribed repo wakes (back-compat). A PM subscribed to both a coord repo and impl repos sets this to its impl repos so a coord-repo push/CI event doesn't self-wake. |
| `drop_title_all_of` | list<list<string>> | `[]` | **`coord-message` family.** Drop (no intent, no wake — before the recipient gate) a coordination subject whose **title** contains **every** substring of any one group (AND within a group, OR across groups) — for bookkeeping-title noise (e.g. `[["Rule E back-merge sync","paper-trail anchor"]]`). Substrings lowercased for case-insensitive matching; a non-list group or an empty substring **throws** (an empty group is ignored). **Blast radius:** for an `issue_comment` the title is the *parent issue's* title, so a matched group also suppresses every comment on that issue — intended for a pure paper-trail anchor (all its activity is noise), but keep groups specific enough not to swallow a live-discussion thread. Empty/absent ⇒ no subject dropped. |
| `wake_membership` | list<string> | `[to_me, to_all]` | **`coord-message` family (DL-190).** Which classes grant coord-message **live-wake** (channel_push): `to_me` (a `to:<me>` label), `to_all` (`to:all`), `from_me` (`from:<me>` — activity on a thread you opened), and `comment_to` (DL-192 — an opt-in body-`TO:<self>` grant, see below). **DEFAULT is narrow `[to_me, to_all]`** — over-wake is the guarded failure mode; a coordinator opening many threads would else wake on every reply to them. Add `from_me` to also live-wake on **all** activity on threads you opened (a `from:<me>` issue label) — this is **operationally required, not optional, for a PM/coordinator seat** that opens briefs labeled `to:<impl>` (never `to:<self>`) and must live-wake on the impl replies to them; without it those brief-reply wakes downgrade to SessionStart-only pickup. Note: a comment's direction lives in its **body `TO:` line**, not as a `to:<me>` issue label. The body-`TO:` check is three-state (`RecipientAddressing::addresses`): a comment addressed to **someone else** always *narrows* (denies membership, unconditional); a comment with **no** `TO:` line falls back to label membership; and a comment addressed **TO you** grants membership **only when `comment_to` is set** (default OFF). So without `from_me` **and** without `comment_to`, even a directed reply on your own thread shifts from live-wake to SessionStart (`from:<self>` / `protocol:invalid`) boundary pickup. **`comment_to` (DL-192)** adds precisely the missing grant: it live-wakes on a comment whose body says `TO: <self>` **even when the thread labels don't address you** — closing the cross-thread pull-in gap (a loop-in on a thread you neither opened nor were labelled on) that no label-class can express, since the `to:` label freezes at thread-open. It grants only; the narrow (`TO:` someone-else) and the no-`TO:` label-fallback are unchanged, so `comment_to` is purely additive over the default. This gates membership only; inbox-staging is unchanged. Values lowercased. |
| `impl_non_wake_disposition` | string | `drop` | **`impl-ci-wake` family (DL-190).** Disposition of a **non-wake** terminal impl event (a benign completed `workflow_run`, a non-release-branch push). `drop` (default) = gate-drop, no intent (lean inbox — the pre-DL-190 behavior). `inbox_stage` = build a normal inbox `Intent` (`impl_push` / `impl_ci`) with **no** `channel_push`, keeping a broad CI/push SessionStart history; a channel whose `route_intents` owns waking then wakes on it. A non-terminal `workflow_run` / a branch-delete push is never staged. Any other value falls back to `drop`. |
| `coord_extra_actions` | mapping<string,list<string>> | `{}` | **`coord-message` family (DL-190).** Extends the fail-safe `subject()` action allow-list per event prefix — `{ pull_request: [synchronize] }` surfaces `pull_request.synchronize` (a PR update) beyond the default (`issues`: opened/reopened; `issue_comment`: created; `pull_request`: opened/reopened/ready_for_review). Keys are the event prefix without the trailing dot; keys+values lowercased; a non-list value or an empty string **throws**. An **unlisted** action still returns null (allow-list, not deny-list — a new GitHub action never auto-surfaces). |

> Family-specific config (e.g. an `impl-ci-wake` family's `benign_conclusions` / CI-name patterns / `release_branch` / `impl_repos`) is read through `ClassifierConfig`'s generic typed accessors (`strings()` / `string()` / `section()` / `stringGroups()`), so a new family adds keys here **without** a schema/contract change.

### `channel:` (optional) — where `channel_push` / `route_intents` delivers
| Key | Type | Default | Notes |
|---|---|---|---|
| `socket` | abs path | `null` | Local UDS. Absolute, no `..`, no null byte (else throws). **Mutually exclusive** with `url`. **`${XDG_RUNTIME_DIR}` / `${uid}` expand at load (DL-039)** so you can write a uid-agnostic literal — e.g. `${XDG_RUNTIME_DIR}/agent-webhook-bridge-channel-<name>.sock` — instead of pinning `/run/user/<uid>/…` (which silently breaks live-wake when the install is restored on a host where the uid changed). `${XDG_RUNTIME_DIR}` resolves to `$XDG_RUNTIME_DIR`, or `/run/user/<uid>` when unset (PHP-FPM usually doesn't inherit it). An unresolvable token throws. `bridge:check` warns if the resolved parent dir is missing/non-writable. |
| `url` | loopback http URL | `null` | e.g. an SSH-tunnel endpoint. Non-empty, no whitespace (else throws); the loopback/SSRF check is the handler's. `bridge:check` **liveness-probes** the `host:port` (a TCP connect — reaches the remote connector through the reverse tunnel) and surfaces an HTTP `.FAILED` marker best-effort when run on the agent host (DL-156). |
| `route_intents` | bool | `false` | Dispatcher auto-pushes every staged intent here. **Requires** `socket` or `url` (else throws). A classifier that *hand-emits* its own `channel_push` (an `EventDrivenClassifier`, or a raw custom one) double-pushes here — pair those with `route_intents:false`. `CoordinationClassifier` is safe on either setting: it suppresses every family's hand-emit when `route_intents:true` (DL-191), so the routed push is the single wake. |
| `auth.token_path` | abs path to a file | `null` | Bearer token for the **HTTP transport** (`url`). **Rejected at load unless `url` is set** (DL-008). Read fail-closed at push: must be `0600`, non-empty (else the push errors, inbox backstops it). |

### `surface:` (optional)
| Key | Type | Default | Notes |
|---|---|---|---|
| `silent_drop_warnings` | bool | `true` | Warn when a `channel_push` target has no paired Intent. Non-bool throws. |

### `api:` (optional) — per-agent token-path override
| Key | Type | Default | Notes |
|---|---|---|---|
| `<provider>.token_path` | abs path | convention `<secret_dir>/<provider>/token` | Override only when the agent authenticates as a distinct account. |

Unknown top-level keys (outside `identity`/`subscriptions`/`echo_suppression`/`surface`/`classifier`/`channel`/`api`) are warned, not fatal.

---

## 3. Secrets, tokens & state files

| Path | Mode | Notes |
|---|---|---|
| `<secret_dir>/<provider>/webhook-secret-scope-<scope>` | `0600` | Per-(provider,scope) HMAC secret. Group/world-readable → receiver **500 `secret_perms_insecure`** (DL-010). `<scope>`'s `/` is `%2F`-encoded. |
| `<secret_dir>/<provider>/token` | `0600` | Per-provider API token used **only** by `bridge:provision`. Insecure perms → provision **fails** (DL-010). `bridge:check` *warns* (not fails) if it's absent/unreadable ("will SKIP `<provider>` scopes") — **that warning is only actionable if you (re-)run `bridge:provision`.** On a finished install where provisioning was a one-time admin action and the token was intentionally removed, the warning is expected and safe to ignore (the running receiver never reads this token). |
| `<config_dir>/shared-identities.json` | `0600` | Optional. `{ "shared_identities": [ {github_user_id, github_login?, agents: [...]} ] }` — one declaration for a GitHub account shared by multiple agents (DL-002). Resolves to `Actor.name = null` so a classifier re-attributes. |
| `<config_dir>/writeback.json` | `0600` | Optional (absent ⇒ writeback off, DL-009/019). `{ "identity_id": <kanban user>, "alert_channel": {<socket\|url>}, "mappings": { "owner/repo": { "board_id": N, "stages": { "opened"\|"merged"\|"merged_to_main"\|"closed_unmerged": <stage_id> }, "create_dependabot_cards": <bool, default false>, "swimlane_id": <id, optional>, "draft_overlay": <bool, default false> } } }`. Per-install policy → config dir, not tracked config. Malformed mapping → `bridge:check` **fails** / move handler 5xx. `identity_id` is auto-seeded into the global echo set so the writeback's own `card_updated` doesn't loop. `swimlane_id` (DL-027) pins **created** cards to a lane (create-only, never moves a card; strict-numeric — a non-numeric value fails closed; `bridge:check` warns if the lane isn't on the board). **`draft_overlay`** (DL-193, optional bool, default false) mirrors a PR's **draft** state onto the correlated card's **`block_reason`** field — overlay ONLY, no stage/column move. `converted_to_draft` / opened-as-draft **SET** the marker `"PR is in draft"` add-if-missing (only when `block_reason` is empty — a human reason is never stomped); `ready_for_review` **CLEARS** it clear-if-ours (only when the current value is exactly that marker). Setting it pins the card (DL-178). Absent/false ⇒ those actions are ignored (byte-identical). Not a stage-mapped outcome — `stages` is untouched. See `docs/writeback.md`. **`alert_channel`** (FR-4, optional) emits a loud per-event signal on a permanent move-failure, in addition to the log: `{ "socket": "/abs/path" }` OR `{ "url": "http://127.0.0.1:PORT/", "auth": { "token_path": "/abs/path" } }` (mutually-exclusive socket/url, loopback-only url). Opt-in, best-effort, deduped per `(repo, outcome, reason)`; a malformed alert_channel **warns** (never fails the config) — see `docs/writeback.md`. |
| `<secret_dir>/<provider>/writeback-token` | `0600` | Optional. The **dedicated least-privilege** token the card-move writeback authenticates with — distinct from `/token` (DL-009). Place a kanban token scoped to card-moves on the mapped boards. Absent/insecure → `bridge:check` **warns** + the move fails. |
| `<state_dir>/inbox.jsonl` / `inbox-<agent>.jsonl` | — | Staged intents (the durable pull-backstop). Trimmed by `bridge:prune` (DL-012). |
| `<state_dir>/inbox-seen[-<agent>].json` | — | Per-reader seen cursor. |

---

## Related

- [`../CLAUDE_DECISIONS.md`](../CLAUDE_DECISIONS.md) — the *why* behind every key (DL-NNN).
- [`../.env.example`](../.env.example) + [`../examples/sample-config/agent.yml.example`](../examples/sample-config/agent.yml.example) — copy-paste templates.
- [`./customization.md`](customization.md) — writing custom classifiers/handlers.
- Run `php artisan bridge:check` to validate a live install against this schema (fail-closed on the items marked **fail**, warnings on the rest).
