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

### `channel:` (optional) — where `channel_push` / `route_intents` delivers
| Key | Type | Default | Notes |
|---|---|---|---|
| `socket` | abs path | `null` | Local UDS. Absolute, no `..`, no null byte (else throws). **Mutually exclusive** with `url`. **`${XDG_RUNTIME_DIR}` / `${uid}` expand at load (DL-039)** so you can write a uid-agnostic literal — e.g. `${XDG_RUNTIME_DIR}/agent-webhook-bridge-channel-<name>.sock` — instead of pinning `/run/user/<uid>/…` (which silently breaks live-wake when the install is restored on a host where the uid changed). `${XDG_RUNTIME_DIR}` resolves to `$XDG_RUNTIME_DIR`, or `/run/user/<uid>` when unset (PHP-FPM usually doesn't inherit it). An unresolvable token throws. `bridge:check` warns if the resolved parent dir is missing/non-writable. |
| `url` | loopback http URL | `null` | e.g. an SSH-tunnel endpoint. Non-empty, no whitespace (else throws); the loopback/SSRF check is the handler's. |
| `route_intents` | bool | `false` | Dispatcher auto-pushes every staged intent here. **Requires** `socket` or `url` (else throws). Pair with a plain classifier, not `EventDrivenClassifier`, or it double-pushes. |
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
| `<config_dir>/writeback.json` | `0600` | Optional (absent ⇒ writeback off, DL-009/019). `{ "identity_id": <kanban user>, "mappings": { "owner/repo": { "board_id": N, "stages": { "opened"\|"merged"\|"merged_to_main"\|"closed_unmerged": <stage_id> }, "create_dependabot_cards": <bool, default false>, "swimlane_id": <id, optional> } } }`. Per-install policy → config dir, not tracked config. Malformed → `bridge:check` **fails** / move handler 5xx. `identity_id` is auto-seeded into the global echo set so the writeback's own `card_updated` doesn't loop. `swimlane_id` (DL-027) pins **created** cards to a lane (create-only, never moves a card; strict-numeric — a non-numeric value fails closed; `bridge:check` warns if the lane isn't on the board). |
| `<secret_dir>/<provider>/writeback-token` | `0600` | Optional. The **dedicated least-privilege** token the card-move writeback authenticates with — distinct from `/token` (DL-009). Place a kanban token scoped to card-moves on the mapped boards. Absent/insecure → `bridge:check` **warns** + the move fails. |
| `<state_dir>/inbox.jsonl` / `inbox-<agent>.jsonl` | — | Staged intents (the durable pull-backstop). Trimmed by `bridge:prune` (DL-012). |
| `<state_dir>/inbox-seen[-<agent>].json` | — | Per-reader seen cursor. |

---

## Related

- [`../CLAUDE_DECISIONS.md`](../CLAUDE_DECISIONS.md) — the *why* behind every key (DL-NNN).
- [`../.env.example`](../.env.example) + [`../examples/sample-config/agent.yml.example`](../examples/sample-config/agent.yml.example) — copy-paste templates.
- [`./customization.md`](customization.md) — writing custom classifiers/handlers.
- Run `php artisan bridge:check` to validate a live install against this schema (fail-closed on the items marked **fail**, warnings on the rest).
