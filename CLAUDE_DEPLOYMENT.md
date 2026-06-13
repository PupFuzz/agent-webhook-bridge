# Deployment & ops

How to install, update, operate, and diagnose a v0.12 bridge install. The model is deliberately small: **a single Laravel app on Apache + PHP-FPM 8.5 that does all its work synchronously in the webhook request** — no queue, no consumer cron, no scheduler, no daemon, no systemd unit. Operating it is operating a normal PHP-FPM web app. There is no "queue not draining" failure mode because there is no queue.

One install per agent: its own webroot, `.env`, DB, base dir, and (ideally) PHP-FPM pool. The canonical reference host runs `prod-agent` + `dev-agent` side by side.

## Install layout

| Piece | Where |
|---|---|
| App (served root) | `~/agent-webhook-bridge-<agent>/public` (Apache vhost DocumentRoot → routes `/webhooks/*`) |
| Base dir (`BRIDGE_DIR`) | `~/.config/agent-webhook-bridge-<agent>/` — per-agent `<agent>.yml` + optional `shared-identities.json` + HMAC secrets + API tokens + `state/` |
| Config dir / Secret dir | both default to `BRIDGE_DIR`; override with `BRIDGE_CONFIG_DIR` / `BRIDGE_SECRET_DIR` only if they live elsewhere |
| Secrets | `<secret_dir>/<provider>/webhook-secret-scope-<scope>` (chmod 600); API token by convention `<secret_dir>/<provider>/token` |
| State | `<state_dir>/` (defaults to `<config_dir>/state/`) — `inbox.jsonl`, `inbox-seen.json`, handler logs |
| DB | MariaDB `agent_webhook_bridge_<agent>` (creds in `.env`) |

### Required `.env`

```env
APP_ENV=production
APP_KEY=                              # set by `php artisan key:generate`
APP_DEBUG=false

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=agent_webhook_bridge_prod
DB_USERNAME=kanban
DB_PASSWORD=...

# One base dir for per-agent YAMLs + shared-identities.json + HMAC secrets + API tokens.
BRIDGE_DIR=/home/kanban/.config/agent-webhook-bridge-prod
# BRIDGE_CONFIG_DIR=...               # optional override (defaults to BRIDGE_DIR)
# BRIDGE_SECRET_DIR=...               # optional override (defaults to BRIDGE_DIR)

# Per-install endpoints (same for every agent on this install).
BRIDGE_RECEIVER_BASE_URL=https://bridge.example.com/webhooks   # this bridge's public webhook URL
BRIDGE_KANBAN_API_BASE_URL=https://kanban.example.com/api/v3   # upstream API base (bridge:provision)
# BRIDGE_GITHUB_API_BASE_URL=https://api.github.com            # defaults to api.github.com
# BRIDGE_MAX_BODY_BYTES=262144        # optional; default 256K. Keep ≤ the FPM pool's post_max_size.
# BRIDGE_INSTALL_SUFFIX=-prod         # -prod/-dev cross-DSN safety marker
```

The API token is read by convention from `<secret_dir>/<provider>/token` (e.g. `$BRIDGE_DIR/kanban/token`, chmod 600); set a per-agent `api.<provider>.token_path` override in the YAML only when an agent authenticates as a distinct account.

There is **no** queue worker, scheduler, or systemd unit to install. The **one** optional periodic job is `bridge:prune` (retention — see Commands); nothing on the dispatch path depends on it, and skipping it only lets the stores grow.

## Pre-flight (per host)

`sudo` access needed for: `systemctl reload apache2 php8.5-fpm` (post-deploy reload) and a DB superuser (create the database). The bridge runs no services of its own.

```bash
sudo apache2ctl -M | grep proxy_fcgi          # expect proxy_fcgi_module (PHP-FPM, NOT mod_php)
sudo apache2ctl -M | grep php                  # expect NOTHING
php -v && composer --version                    # PHP 8.5.x, Composer 2.x
sudo apache2ctl -S | grep <host>                # vhost routes /webhooks/* to the install's public/ dir
# FPM pool php.ini: post_max_size ≳ BRIDGE_MAX_BODY_BYTES (e.g. 512K) — defense-in-depth bound on EnvelopeSizeLimit
```

Verify each agent's vhost has its **own PHP-FPM pool**, so recycling one pool never disrupts the other's in-flight request.

## Fresh install

```bash
cd ~/agent-webhook-bridge-<agent>
git clone <repo> . && git checkout main          # or: git pull on an existing checkout
composer install --no-dev --optimize-autoloader
cp .env.example .env && php artisan key:generate
$EDITOR .env                                      # see "Required .env"
mysql -u kanban -p -e "CREATE DATABASE agent_webhook_bridge_<agent> CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
php artisan bridge:check                          # validate .env, dirs, DB connectivity, agent YAMLs — STOP if non-zero
php artisan migrate --force
php artisan optimize                              # config/route cache
php artisan bridge:provision                      # register kanban webhook subscriptions (idempotent)
sudo systemctl reload apache2 php8.5-fpm
```

## Update an existing install

```bash
cd ~/agent-webhook-bridge-<agent>
git pull --ff-only
# ⚠ Running a CUSTOM classifier/handler under app/Bridge/? Migrate it IN THIS STEP
# if you're crossing a contract change — see the callout below.
# ⚠ Files COPIED/hand-derived out of examples/ at install (the session launcher,
# and on some hosts the channel-server .mjs) live OUTSIDE the repo — git pull
# CANNOT update them. Reconcile each: see "Reconcile out-of-repo copies" below.
composer install --no-dev --optimize-autoloader
php artisan migrate --force                       # no-op if no new migrations
php artisan optimize:clear && php artisan optimize
php artisan bridge:check                           # VALIDATE BEFORE serving — names a stale custom classifier / config drift; STOP if non-zero
sudo systemctl reload php8.5-fpm                  # recycle workers so they re-read config + agent YAMLs
```

> **⚠ Running a custom classifier or handler?** A custom class under `app/Bridge/Classifiers/` (per [`docs/customization.md`](docs/customization.md) § Loading your classifier) is **untracked but not gitignored**, so `git pull` preserves your *old* file unchanged into the new release. **Check [`docs/CHANGELOG.md`](docs/CHANGELOG.md) for a `classify()`/contract change in the versions you're crossing and migrate your class in the SAME step as the pull.** `classify()` has had two breaking changes (DL-022 added `AgentConfig $agent`; DL-025 collapsed to a single `classify(ClassifyContext $ctx)` — the **last** such break). An old signature is an uncatchable `E_COMPILE_ERROR` that fatals the receiver on the next live delivery — and with `opcache.validate_timestamps=On` the new contract is picked up within `revalidate_freq` of the pull, **before** the FPM reload, so the failure window opens at pull time. This is why `bridge:check` is ordered **before** the reload above: its out-of-process classifier load (DL-025) names a stale class instead of letting it fatal a request — but that only helps if you migrate-and-check, not pull-and-serve.

> **No `sudo`?** The FPM reload is for a clean worker recycle; it's not strictly required. With PHP's default `opcache.validate_timestamps=On`, FPM workers pick up changed `.php` / cached-config files within `revalidate_freq` (a couple of seconds) on their own. After a code/`.env` change, `optimize:clear && optimize` + a healthy `bridge:check` and `/up` 200 confirm the new state is live; reload when you can for a deterministic recycle.

### Reconcile out-of-repo copies (session launcher + channel server)

`git pull` only touches the repo. Two things the **live-wake channel** depends on are **copied or hand-derived out of `examples/` at install time** and live OUTSIDE the repo, so a pull can't update them and they drift silently from the refreshed samples. After every update, reconcile each against its sample — **diff-and-port; these are operator-customized, never blind-overwrite.** None of this trips `bridge:check` (the launcher and the `.mjs` run inside the Claude Code *session*, not the Laravel app), so the diff is the **only** drift signal — and a stale connector is exactly how a session comes up **deaf to live-wake** ([`CLAUDE_DECISIONS.md`](CLAUDE_DECISIONS.md) DL-154/DL-155).

1. **The session launcher** — always a hand-rolled copy (the operator names it, e.g. `~/start-claude.sh`), derived from [`examples/start-channel-session.sh`](examples/start-channel-session.sh). `diff` them and port any new guardrails/fixes by hand; the copy hardcodes its channel name + paths, so it won't merge cleanly.

2. **The channel MCP server (`agent-webhook-bridge-channel.mjs`)** — find where it actually loads from: the `args` path of the channel server in **`~/.mcp.json`** (the entry keyed by the channel name, e.g. `kanbanboard-agent`). Two topologies:
   - **Loaded directly from a checked-out repo's `examples/channel-servers/`** (e.g. `…/agent-webhook-bridge-<agent>/examples/channel-servers/…`) — the pull already updated it; just run `npm ci` in that dir if its `package-lock.json` changed (DL-033).
   - **A standalone COPY** (multi-agent hosts commonly snapshot it per agent, e.g. `*-coordination/OUTBOUND/<agent>/channel-setup/agent-webhook-bridge-channel.mjs`) — it drifts. Compare the copy's sibling `package.json` `version` to [`examples/channel-servers/package.json`](examples/channel-servers/package.json) `version`: that field is the **drift signal**, bumped on every shipped change to the server (DL-038). If the sample is newer, re-copy the dir and `npm ci`.

3. **Multi-agent hosts** — every agent has its own launcher and its own `~/.mcp.json` pointing at its own `.mjs`. Reconcile **each** agent's copies, not just the one whose session you're in.

Locate every copy so none is missed (run from the updated repo):

```bash
find ~ -name 'agent-webhook-bridge-channel.mjs' -not -path '*/node_modules/*'              # all channel-server copies
find ~ -maxdepth 4 \( -name 'start-claude.sh' -o -name 'start-channel-session.sh' \) -not -path '*/node_modules/*'
# per .mjs copy, the one-field drift check (non-empty output ⇒ re-sync that copy + npm ci):
diff <(jq -r .version <copy-dir>/package.json) <(jq -r .version examples/channel-servers/package.json)
```

### Smoke-test the receiver with a signed delivery

The real-surface post-update check: fire **one signed synthetic delivery** at the live receiver, exercising the actual HMAC → adapt → classify → dispatch path without polluting the upstream board/repo. The signature is over the **raw body** (G-011); the body's scope source **must equal** the `?b=<scope>` query param or the receiver returns `401 scope_mismatch` (G-018) — for GitHub that source is `repository.full_name`, for kanban it's `board_id`.

```bash
SCOPE='<org/repo>'                                 # GitHub: equals repository.full_name AND ?b=
SECRET=$(cat "<secret_dir>/github/webhook-secret-scope-${SCOPE}")
BODY='{"action":"created",
       "repository":{"full_name":"'"$SCOPE"'"},
       "issue":{"number":1,"title":"smoke","labels":[]},
       "comment":{"body":"smoke","html_url":"https://example.invalid/x"},
       "sender":{"id":<sender-id>,"login":"x"}}'
SIG=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac "$SECRET" -hex | awk '{print $NF}')
curl -X POST \
  -H "X-Hub-Signature-256: sha256=$SIG" \
  -H "X-GitHub-Event: issue_comment" \
  -H "X-GitHub-Delivery: smoke-$(date +%s)" \
  --data-binary "$BODY" \
  "$BRIDGE_RECEIVER_BASE_URL/github?b=${SCOPE}"     # BRIDGE_RECEIVER_BASE_URL ends in /webhooks → POST /webhooks/github
# then: php artisan bridge:stats   (expect errored=0) ; php artisan bridge:inspect <N>
```

A `401 scope_mismatch` almost always means the body omitted (or mismatched) `repository.full_name` vs `?b=` — not an HMAC problem (G-018).

## Upgrading an existing install to v0.16 (config schema v2)

v0.16.0 is a **breaking config change** (DL-007). After `git pull` to v0.16+, migrate each install's config once — the v1 YAML still *loads* (old keys ignored/warned) but identity/echo/endpoints won't resolve until migrated. Checklist:

1. **`.env`** — add `BRIDGE_DIR=<the path>` (it supersedes `BRIDGE_CONFIG_DIR`+`BRIDGE_SECRET_DIR`; keep those only as overrides if they differ), and add the per-install endpoints hoisted out of the YAMLs:
   ```
   BRIDGE_RECEIVER_BASE_URL=https://<your-bridge-host>/webhooks
   BRIDGE_KANBAN_API_BASE_URL=https://<your-kanban>/api/v3
   ```
2. **Each `<agent>.yml`** — move the agent's ids from the old `agents.json` into an `identity:` block (`kanban_user_id` / `github_user_id` / `github_login`); **delete** `identity.self`, the `receiver:` block, `api.<provider>.base_url`, and `channel.name`. Drop self from `treat_as_echo` / `treat_as_echo_ids` (auto-seeded now). Keep `api.<provider>.token_path` only as an override.
3. **Peers** — for any name referenced in `treat_as_signal`/`treat_as_echo` that runs in a *separate* install, add an author-only `<peer>.yml` here (`identity:` + `subscriptions: []`) — the registry is per-install now (see `docs/multi-agent.md`).
4. **Token** — move it to the convention `<secret_dir>/<provider>/token` (e.g. `$BRIDGE_DIR/kanban/token`), or keep its path via the `api.<provider>.token_path` override.
5. **`agents.json`** — delete it. If (and only if) several agents share one upstream account, create `shared-identities.json` with just the `shared_identities` block.
6. `php artisan optimize:clear` (then `optimize` on a pure-serving install; leave a dev/test workspace **uncached**), then **`php artisan bridge:check`** — it validates the whole v2 surface (identity, endpoints, token/secret presence, signal names) with actionable messages. Fix anything it flags, then reload FPM.

The rewritten `examples/sample-config/agent.yml.example` + `shared-identities.json.example` are the canonical templates.

## The #1 Laravel trap — config edits don't take

FPM workers are long-lived and `php artisan optimize` caches `.env`. After editing **`.env` / `shared-identities.json` / any per-agent YAML**:

```bash
php artisan optimize:clear && php artisan optimize   # MANDATORY after .env edits
sudo systemctl reload php8.5-fpm                     # recycle workers so they re-read the agent YAMLs
```

Forget `optimize:clear` and the app silently uses the cached old values — no error surfaces it.

---

## Runtime: the recovery story is the HTTP status code

kanban-board's webhook delivery retries on **5xx / 429 only** (≈11 attempts over ~11 days); a non-429 4xx is a permanent client error (not retried). The receiver leans on this entirely — **fail-closed**: anything transient or misconfigured must surface as `5xx` so kanban-board holds the event and re-delivers once it's fixed.

| Code | Meaning | kanban-board |
|---|---|---|
| `200 ok` / `200 pong` | processed (or a connectivity ping) | done |
| `400` | malformed envelope / invalid provider / invalid scope | **not retried** |
| `401` | bad signature / unknown scope / scope mismatch | not retried |
| `413` | body over the size cap | not retried |
| `500` | transient/internal failure (DB down, **malformed config**, durable inbox-write failure) | **retried** on the ~11-day curve |

A malformed per-agent YAML / `shared-identities.json` is intentionally a `5xx` — the loader fails closed and kanban-board holds everything until the config is fixed and FPM reloaded.

## Per-agent dispatch: done vs errored

Each `(event, agent)` is one `agent_dispatches` row:

- **done** — `processed_at` set. Intents were staged and handlers ran. If a *handler/push* failed (e.g. channel push to an idle agent — connection refused, which is NORMAL), the row is still **done** with `error_message` recording the note; the intent is already durable in `inbox.jsonl`, read via `bridge:inbox` when the agent returns. The webhook still 200s.
- **errored** — `processed_at` null, `error_message` set. The classifier threw (a deterministic bug). The webhook still 200s (a 5xx would retry-storm an event that fails identically every time). Fix the classifier, reload FPM, then `bridge:replay <id>`.

## Where things land

All config/secret/state paths live under `BRIDGE_DIR` unless `BRIDGE_CONFIG_DIR` / `BRIDGE_SECRET_DIR` / `BRIDGE_STATE_DIR` override them.

| What | Path |
|---|---|
| App + dispatch-warning logs | `storage/logs/laravel.log` |
| Per-agent config | `<config_dir>/<agent>.yml` |
| Shared-account declaration (optional) | `<config_dir>/shared-identities.json` |
| Per-`(provider,scope)` HMAC secret | `<secret_dir>/<provider>/webhook-secret-scope-<scope>` (chmod 600) |
| API token (by convention) | `<secret_dir>/<provider>/token` (chmod 600) |
| Inbox (agent surface) | `<state_dir>/inbox.jsonl` (state dir defaults to `<config_dir>/state`) |
| Inbox seen-set (`bridge:inbox` dedup) | `…/state/inbox-seen.json` |
| Handler forensic log (`log_intent`) | `…/state/handler-log.jsonl` |
| Per-target registry (`registry_append`) | `…/state/registry-<target>.jsonl` |
| Detached-command logs (`spawn_detached`) | `…/state/spawn-<target>.log` |
| Event / dispatch ledger | the DB (`webhook_events`, `agent_dispatches`) |

## Commands

```bash
php artisan bridge:check                              # validate .env, dirs, DB, agent YAMLs
php artisan bridge:stats                              # event/dispatch counts; errored (replayable) count
php artisan bridge:inspect {id}                       # one webhook event + its dispatch ledger
php artisan bridge:replay {id} [--agent=] [--force]   # re-run dispatch for an event
php artisan bridge:inbox [--hook-format=auto|claude-code|plain]              # surface unseen inbox intents
php artisan bridge:provision [--dry-run] [--list] [--agent=] [--reconcile]   # ensure kanban subscriptions (--reconcile fixes drift)
php artisan bridge:prune --older-than=30d [--null-payloads-older-than=7d] [--dry-run]   # retention (the one optional cron)
```

`bridge:prune` is the only periodic maintenance job (the design is otherwise daemonless). `--older-than=Nd` deletes `webhook_events` (cascading `agent_dispatches`) and trims `inbox*.jsonl` lines older than the cutoff; `--null-payloads-older-than=Md` (use `M < N`) nulls the stored payload past the replay window while keeping the row's dedup-gate + audit metadata; `--dry-run` reports counts only. Idempotent — safe to re-run. Schedule it per install (e.g. a daily cron); nothing breaks if it never runs except unbounded growth. See `CLAUDE_DECISIONS.md` DL-012.

`bridge:replay` re-runs the `processed_at`-guarded dispatch loop: errored rows (`processed_at` null) re-run; **already-succeeded rows are skipped** so a sibling's already-delivered push / `spawn_detached` is never re-fired. `--agent` scopes to one agent. `--force` clears `processed_at` first so done rows (incl. handler-note rows) re-run too — use it to re-attempt a missed channel push once the agent is back.

## Smoke test

1. Create a test card on the board.
2. Within ~1 s, a `<channel source="...">` tag appears in the connected Claude Code session carrying the intent JSON.
3. `SELECT id, delivery_id, event_type FROM webhook_events ORDER BY id DESC LIMIT 1;`
4. `SELECT agent_name, processed_at, error_message FROM agent_dispatches ORDER BY id DESC LIMIT 1;` — `processed_at` set, `error_message` null.
5. `tail -1 <BRIDGE_CONFIG_DIR>/state/inbox.jsonl | jq .`
6. **Negative check:** with no channel server running, create a card → expect `200`, the `agent_dispatches` row **done** with a connection-refused note, **and the intent still in `inbox.jsonl`** (the backstop). NORMAL for an idle agent.

## Diagnose

- **`bridge:stats` shows errored dispatches.** A classifier threw. `bridge:inspect <id>` (or `storage/logs/laravel.log`) for detail → fix → `optimize:clear && reload php8.5-fpm` → `bridge:replay <id>`.
- **Idle agent — channel pushes "failing".** Connection-refused with no Claude Code session up is NORMAL: row is **done with a note**, intent is in `inbox.jsonl` for the next `bridge:inbox`. Not an incident; `--force` re-attempts the push.
- **A config edit "didn't take".** The optimize trap above — `optimize:clear && optimize && reload php8.5-fpm`.
- **kanban-board webhook auto-deactivated.** A short reinstall won't trip it (transient 5xx are mid-curve, not fully-failed). `curl …/api/v3/webhooks | jq '.data[] | select(.board_id==5) | .active'`; if `false`, re-run `bridge:provision`.
- **`413` on legitimate payloads.** Raise `BRIDGE_MAX_BODY_BYTES` and the FPM pool's `post_max_size` together.

## Rollback

Take a DB + config backup before a risky deploy (`mysqldump agent_webhook_bridge_<agent> > pre-deploy.sql`; `tar -czf config.tgz <BRIDGE_CONFIG_DIR>`). To roll back: restore the dump, `git checkout <previous-tag>`, `composer install`, `php artisan migrate --force` (or restore-then-skip if the rollback removes a migration), `optimize:clear && optimize`, reload FPM. v0.12.0 is the baseline of this repository, so there is no earlier version to roll back to from it.

## Second agent

Reinstall/upgrade one agent at a time (halves blast radius; the `systemctl reload` touches both pools). Soak `prod-agent` ~24 h before `dev-agent`.

| | prod-agent | dev-agent |
|---|---|---|
| `BRIDGE_DIR` (config + secret base) | `~/.config/agent-webhook-bridge-prod` | `~/.config/agent-webhook-bridge-dev` |
| Working dir | `~/agent-webhook-bridge-prod` | `~/agent-webhook-bridge-dev` |
| DB | `agent_webhook_bridge_prod` | `agent_webhook_bridge_dev` |
| Apache vhost | `bridge.<host>` | `bridge-dev.<host>` |
