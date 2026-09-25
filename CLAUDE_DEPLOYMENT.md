# Deployment & ops

How to install, update, operate, and diagnose a v0.12 bridge install. The model is deliberately small: **a single Laravel app on Apache + PHP-FPM 8.5 that does all its work synchronously in the webhook request** — no queue, no consumer cron, no daemon, no systemd unit. Operating it is operating a normal PHP-FPM web app. There is no "queue not draining" failure mode because there is no queue. ⚠ **One OPT-IN exception since DL-325**, and it is the only one: an install may add ONE crontab line running `php artisan bridge:tick` over the periodic-job registry. It is opt-in and adds no daemon — an install that adds nothing behaves exactly as described above, because the registry also runs off the inbound webhook's after-response gate. See `docs/periodic-jobs.md`.

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
# DB_TIMEZONE=+00:00                  # MySQL session time_zone; defaults to +00:00 and must match app.timezone (DL-346)
```

⚠ **ACCEPTANCE CHANGE since card#9528 / DL-395 — a credential inside either endpoint URL must be PERCENT-ENCODED, or `bridge:check` and `bridge:provision` refuse it.** If `BRIDGE_RECEIVER_BASE_URL` or `BRIDGE_KANBAN_API_BASE_URL` carries a userinfo — the `user:password@` part in front of the host, e.g. for a reverse proxy's basic auth — and that credential contains any character RFC 3986 does not allow there unencoded, the value is now **refused** by the two commands that JUDGE it, where it used to be accepted: `bridge:check` **fails** on it, and `bridge:provision` refuses — the receiver base for the whole run in every mode except `--list`, the API base per subscription (nothing sent upstream, no secret written). ⛔ **The refusal is scoped to those config doors and nothing else.** The writeback (every card move, board tool, standup and reconcile) and the idle nudge keep accepting exactly what they accept today — such an install works, because the HTTP client percent-encodes the value itself — so this change cannot stop a running install's board from moving. Fix the value at your convenience; it is the two commands above that will keep refusing until you do. The characters allowed unencoded are the unreserved set `A-Za-z0-9-._~`, the sub-delims `!$&'()*+,;=`, `:` and `%`; a **space**, a **`"`**, a backslash, a `|`, a bracket or a control byte is not among them. **Such a value was never a valid URL** — the change is that the bridge now says so at validation time instead of carrying the credential on into error text that echoes it back to a terminal, a CI log or an agent transcript. **What to do:** percent-encode the credential in the URL (a space becomes `%20`, a `"` becomes `%22`), or — better — take the credential out of the URL entirely and give it to the proxy another way. The refusal names the field and quotes the value with the userinfo already redacted, so it is safe to paste into a ticket.

**There are TWO kanban token paths, and they belong to TWO DIFFERENT kanban ACCOUNTS.** They are not two paths for one credential. Placing the same broad token at both collapses the separation DL-009 exists to keep — and nothing checks it, so the install looks finished:

| Path | Whose kanban account | Who reads it |
|---|---|---|
| `<secret_dir>/<provider>/token` (e.g. `$BRIDGE_DIR/kanban/token`, chmod 600) | **this agent's own** upstream identity — the account it acts as when it provisions, normally the one its `identity:` block declares | `bridge:provision` resolves it, and a `bridge:check` leg reports the file's presence. Set a per-agent `api.<provider>.token_path` override in the YAML only when an agent authenticates as a distinct account. |
| `<secret_dir>/<provider>/writeback-token` (chmod 600) | a **separate board SERVICE USER** — never your login, and never an account a board CLI on this host already authenticates as | the card-move writeback and the two-way board tools. Which board permissions that user needs, and how to place the value without it reaching your shell history or an argv, are owned by [`docs/writeback.md` § 1](docs/writeback.md#1-a-least-privilege-writeback-token) — read the grants there rather than inferring a scope from the name. |

⚠ **A missing `/token` is not by itself a fault, and an install can be in that state without anyone deciding it.** A per-agent API token is read when that agent PROVISIONS and at no other time, so an install that provisioned once through a single agent's `api.<provider>.token_path` override leaves every other agent resolving a `<secret_dir>/<provider>/token` that does not exist (measured on the reference host: four agents did) — inert until one of them runs `bridge:provision`, which then SKIPS that provider. That is why `bridge:check` **warns** here instead of failing; [`docs/config-schema.md`](docs/config-schema.md) § 3's row for the path owns when the warning is actionable.

There is **no** queue worker, scheduler, systemd unit or cron **required** to install, and that is still true of a default install — ⚠ **DL-325 narrowed this sentence rather than deleting it.** The periodic-job registry ships with an **OPT-IN** second ingress: ONE crontab line running `php artisan bridge:tick` (see *Periodic jobs* below and `docs/periodic-jobs.md`). It is opt-in because the registry ALSO runs off the inbound webhook's after-response gate, so an install that adds nothing behaves exactly as it did; the line buys the one thing an arrival-gated pass cannot — periodic work on an install receiving no webhooks (DL-306's documented dead end). Retention runs off the inbound webhook itself since **DL-199** (`bridge.retention.*`, on by default) — the receiver prunes its own stores after the response has been sent, so the append-only tables and `inbox*.jsonl` stay bounded with no periodic job at all. `bridge:prune` remains as the manual/one-off command (see Commands). Set `BRIDGE_RETENTION_ENABLED=false` to opt out — but then nothing prunes unless you schedule `bridge:prune` yourself. The opt-in **PM standup digest** (DL-306, `bridge.standup.*`, **off by default**) rides that same gate for the same reason — so it still installs no cron, at the cost that it fires on the first delivery after its interval rather than on a wall clock. `bridge:standup` is its manual entry point (see Commands).

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
php artisan bridge:provision                      # register kanban webhook subscriptions (idempotent). Where
                                                  # writeback.json exists and declares no identity_id, this also
                                                  # OFFERS the value resolved from the writeback token (DL-369) —
                                                  # confirm it against the display name it prints. It writes nothing
                                                  # unasked, and it only ASKS where a human will SEE the question and
                                                  # can ANSWER it — BridgeCommand::canPromptToConfirm() owns that
                                                  # predicate and the flags are not re-spelled here. Anywhere else (a
                                                  # pipe, a redirect, cron, a script, or a run told to skip prompts) it
                                                  # makes NO call at all and prints the by-hand recipe instead.
                                                  # docs/writeback.md § 2
                                                  # It REFUSES (non-zero; nothing sent upstream, no secret written)
                                                  # every subscription whose composed receiver URL reaches no receiver
                                                  # route in this app — a mis-set BRIDGE_RECEIVER_BASE_URL (DL-377).
                                                  # --allow-unreachable-receiver provisions it anyway, e.g. behind a
                                                  # proxy that rewrites the request path, and prints that it did.
                                                  # A BRIDGE_RECEIVER_BASE_URL bridge:check rejects as a URL (e.g.
                                                  # ftp://…) is REFUSED for the whole run in every mode except
                                                  # --list, and the override does not apply (card#9510).
php artisan bridge:provision-tools --agent=<name>  # PER AGENT, AND IT IS A QUESTION, NOT AN OPTIONAL EXTRA: should
                                                  # this agent read, file and correct its own cards from inside its
                                                  # session? YES -> run this; it prints a paste-ready board_tools:
                                                  # block, and for an ssh-transport agent the whole SETUP PACKET
                                                  # (five steps, three actors — one of them a human).
                                                  # NO -> declare `board_tools:` with `enabled: false` in its YAML;
                                                  # a declined capability is a decision WHILE THE BLOCK IS PRESENT.
                                                  # Either answer finishes it. Deleting that YAML later is a
                                                  # decommission, not a decline: docs/board-tools.md § Retiring a seat.
                                                  # `bridge:check` above prints a NEXT STEPS line for every agent
                                                  # that has answered neither way, and that block is the entry point.
                                                  # Roles/handoff (ssh door): docs/board-tools-enablement.md
                                                  # HTTP-door runbook: docs/board-tools.md § Same-box enablement (Apache/FPM).
sudo systemctl reload apache2 php8.5-fpm
# NOT DONE YET: configure AND verify the live-event path — § "Live-event path" right below.
# GitHub answering 200 is not evidence that any agent will ever be woken.
```

## Live-event path — configure it, then SEE a wake

⛔ **An install is done when an event has been SEEN arriving in an agent session — not when GitHub gets a `200`.** Every cheaper signal is true of its own stage and blind to the stages after it, so each one can read healthy over a dead wake path. Measured on a peer install (roundtable #449): every recent hook delivery answered `200`, `bridge:stats` showed nothing errored, `bridge:check` reported the channel socket live, the merge push read `delivered` in the ledger — and no event reached either agent session. The same signals were reproduced for this section on a local install whose channel endpoint was a listener answering `202` with no session behind it — over the HTTP transport, so `bridge:check` said `channel HTTP endpoint live` where the peer install's socket line said live. This section owns the configure step, the verify step and how to read a drop; what each `classifier.config` key does stays in [`docs/config-schema.md`](docs/config-schema.md) § *`classifier.config:` keys*.

### 1. Configure — what GitHub sends is not what wakes

**What reaches the bridge** is chosen in GitHub, on the repo webhook's event list — [`docs/writeback.md`](docs/writeback.md) § *4. The repo webhook* owns creating that hook. ⚠ A github entry's `subscriptions[].event_filter` does **not** narrow it: dispatch matches a subscription on provider and scope only (`App\Bridge\Support\SubscriptionRegistry::subscribedTo()`), and `event_filter` is read only when `bridge:provision` registers **kanban** subscriptions.

**What wakes a seat** is chosen by its classifier's families. The merge-wake case on an impl repo:

```yaml
# $BRIDGE_DIR/<agent>.yml — only the parts this path needs
subscriptions:
  - provider: github
    scopes: ["your-org/your-repo"]
classifier:
  class: App\Bridge\Classifiers\CoordinationClassifier
  config:
    families: [coord-message, impl-ci-wake]  # a non-empty list REPLACES the default [coord-message]: keep it listed if this seat also takes coordination messages
    release_branch: main                     # the push that wakes is a push to THIS branch
    impl_non_wake_disposition: inbox_stage   # while establishing the path — see below
channel:
  socket: ${XDG_RUNTIME_DIR}/agent-webhook-bridge-channel-<channel-name>.sock   # or `url:` — docs/config-schema.md § channel
```

On the repo webhook, subscribe the **`push`** and **`workflow_run`** events (*Pushes* and *Workflow runs* in GitHub's individual-event picker). Under `impl-ci-wake` (`App\Bridge\Classifiers\CoordinationClassifier::implCiWakeFamily()`) these wake:

- a `push` to `release_branch` that is not a branch delete — intent kind `impl_release_landed`;
- a completed `workflow_run` whose conclusion is not in `benign_conclusions` — `impl_ci_failed`, narrowable with `ci_failure_workflow_patterns` — or a successful one whose name matches `provenance_patterns` — `impl_provenance_ok`.

Everything else that family receives is a **non-wake**, and follows `impl_non_wake_disposition`.

⛔ **`pull_request.closed` is NOT a wake — merged or not.** `impl-ci-wake` consumes only `push` and `workflow_run` (`App\Bridge\Classifiers\CoordinationClassifier::IMPL_CI_WAKE_EVENT_TYPES`) however it is configured, and `coord-message` surfaces a pull request only as a coordination message addressed to a seat — `closed` is not among its default actions (`coord_extra_actions` is how an install would add one), and a release PR on an impl repo carries no addressing. **A merge wakes a seat through the push to the release branch it produces.** Subscribing the hook to *Pull requests* changes none of this.

**Why `inbox_stage` while establishing the path.** Under the default `drop`, a push to any other branch reads `dropped` · `classifier emitted no reactions` — the same row as a family that is not enabled or a repo `impl_repos` excludes, so the ledger cannot tell you the family is live. Under `inbox_stage` that push is staged instead: a `delivered` row with no `channel_push`, and an `impl_push` intent in `inbox.jsonl` — evidence that GitHub, the receiver and this family work for this repo, without waiting for a release. **It is not a wake and does not satisfy § 2.** ⚠ It is a quiet digest only on a `route_intents: false` channel; the `impl_non_wake_disposition` row in `docs/config-schema.md` owns what it does under `route_intents: true`. Set it back to `drop` once § 2 has passed if you do not want that inbox history.

Apply the YAML edit per § *The #1 Laravel trap* below, then `php artisan bridge:check`.

### 2. Verify — a wake SEEN in the session, and nothing less

1. **Start the seat's session and leave it idle at a prompt.** The reference channel server is a child of that session (§ *Update an existing install* owns what that means for restarting it). Whether a notification that reaches a session mid-turn is surfaced then, later, or not at all is not established — § *Per-agent dispatch: done vs errored*.
2. **Cause a real event, delivered by GitHub, that an enabled family wakes on.** For the merge-wake case, a push to `release_branch` — a real merge is fine, provided the account that pushes it is not one this seat declares as its own (§ 3's `echo: own write` row). For `coord-message`, a message addressed to the seat per `wake_membership` (`docs/config-schema.md`).
3. **Look in the SESSION** for a `<channel source="…">` block whose JSON body carries an `intent.kind` naming what you caused (`impl_release_landed`) and an `intent.subject_id` that matches it (for a release landing, the landed commit SHA). **That block is the only evidence that passes this step.**
4. **No block ⇒ the step FAILED**, whatever else is green. Go to § 3 below and read the ledger row before naming a stage.

**None of these passes it.** Each was true in the local reproduction while no session received anything (the socket line is the peer install's; the reproduction ran the HTTP transport):

| Signal | What it establishes — and no more |
|---|---|
| GitHub shows `200` for the delivery | the receiver accepted it. A gate-dropped event is answered `200` too. |
| `bridge:check` exits `0`, including `channel HTTP endpoint live` / `channel socket live` | something accepted a connection at the endpoint. A listener is not a session. |
| `bridge:stats` shows `errored (replayable)` = `0` | no classifier threw. `bridge:stats` has no delivered-vs-dropped split: a dropped event and a pushed one both count as `processed`. |
| `delivered` in `bridge:inspect` | every handler for that dispatch returned — the legend printed under that table says what it is not. |
| `bridge dispatch: channel_push unconfirmed` beside `bridge channel_push: accepted by transport (unconfirmed)` with `"status":202` | the endpoint wrote the notification to its own transport. |
| an intent line in `inbox.jsonl` | staged, not woken. |

⛔ **Do not verify with a re-send.** Delivery dedup keys on a hash of the signed body (`App\Bridge\Adapters\GitHubAdapter`), so a body byte-identical to one this install already processed is answered `200` and does nothing — no new event row, no log line, no push (measured). A signed synthetic delivery (§ *Smoke-test the receiver with a signed delivery*) exercises the receiver, not GitHub's hook, and does not close this step either.

### 3. No wake? Read the ledger row FIRST

Do not name a stage from the symptom. The install in roundtable #449 was first told *"the classifier dropped it"* from the symptom alone, and its row read `delivered`. The ledger row is written whatever the log level; the log lines are not. `bridge dispatch: dropped at gate`, `bridge dispatch: delivered` and `bridge dispatch: channel_push unconfirmed` (`App\Bridge\Dispatch\DispatchService::markDropped()` / `markDelivered()`) and `bridge channel_push: accepted by transport (unconfirmed)` (`App\Bridge\Handlers\ChannelPushHandler::reportAcceptance()`) are logged at `info`, so an install whose `LOG_LEVEL` is above `info` writes none of those. `bridge dispatch: classifier failed` (`DispatchService::recordError()`) and the DL-373 line (`DispatchService::warnAccountEchoUnderReattribution()`) are logged at `warning`.

1. **Find the event id** — the `event` key on that dispatch's `bridge dispatch:` line in `storage/logs/laravel.log`, or the query below. `bridge dispatch: classifier failed` carries no `event` key, so an errored dispatch's event id comes from the query, not from its log line:
   ```sql
   SELECT id, event_type, received_at FROM webhook_events ORDER BY id DESC LIMIT 5;
   ```
2. **`php artisan bridge:inspect <id>`** (`--agent=<name>` shows one seat's row). Read `outcome` and `reason / error`, then find the row:

| `bridge:inspect` shows | Log line | What it means | Look next at |
|---|---|---|---|
| no event row for the delivery | — | this install never recorded it: GitHub got an error, the hook points somewhere else, or the body was identical to one already processed (the ⛔ above) | the hook's recent deliveries in GitHub; `bridge:check`'s `github webhook:` line |
| the event, and **no dispatch row** for your agent | — | this agent's YAML does not subscribe this provider + scope (other agents can still have rows for the same event) | that agent's `subscriptions:` |
| `errored` | `bridge dispatch: classifier failed` | the classifier threw | § *Diagnose* |
| `dropped` · `classifier emitted no reactions` | `bridge dispatch: dropped at gate` | it reached the classifier and no enabled family acted on it. Examples, not a complete list: an event type no enabled family consumes (`pull_request.closed` under `impl-ci-wake`), a family missing from `families`, a repo outside `impl_repos`, a coordination message not addressed to this seat (default `coord_non_addressed_disposition: drop`, `App\Bridge\Classifiers\CoordinationClassifier::coordMessageFamily()`), a non-release push or a benign completed `workflow_run` under `impl_non_wake_disposition: drop` (under `inbox_stage` both are staged and the row reads `delivered`), an unfinished `workflow_run` | `classifier.config`; `bridge:check`'s `event-consumer:` line names received event types that no enabled classifier consumes |
| `dropped` · `echo: own write` | `bridge dispatch: dropped at gate` | ⛔ **the echo gate — the event is this agent's OWN write.** Its actor (`sender.id`) resolved to this agent, whose `identity` ids are auto-seeded into echo suppression, or matched `treat_as_echo` / `treat_as_echo_ids`. On this path the common way to reach it is a merge pushed by the account the seat declares as `identity.github_user_id`: the seat's own release landing is dropped before anything could wake it. When the match was this agent's own github id under `CoordinationClassifier` (or a subclass), a `warning` beginning `bridge dispatch: the account-keyed echo gate named the serving agent` is logged beside it, with a remedy (DL-373). | the `identity:` and `echo_suppression:` rows in `docs/config-schema.md`; the impl-seat invariant on `App\Bridge\Classifiers\CoordinationClassifier::makeImplIntent()` |
| `dropped` · `echo: own write (reattributed author)` | `bridge dispatch: dropped at gate` | the classifier recovered the author from the event's content, and it is this agent | the event's `FROM:` line; `scope_author_map` for that repo (`docs/config-schema.md`) |
| `dropped` · `actor is not a signal` | `bridge dispatch: dropped at gate` | `treat_as_signal` is set and the actor is not on it | `echo_suppression.treat_as_signal` |
| `delivered` · `echo: agent surface suppressed` | `bridge dispatch: delivered`, with `reason` | a gate hit on a writeback classifier: the agent-facing surface was stripped and only machine writeback ran — no wake, by design | § *Per-agent dispatch: done vs errored* |
| `delivered`, blank | `bridge dispatch: delivered` | handlers ran and **no `channel_push` was attempted** — e.g. an `inbox_stage` non-wake event on a `route_intents: false` channel: staged, not woken | `inbox.jsonl`; whether the event was wake-worthy (§ 1) |
| `delivered`, with an error naming the push failure (e.g. a connection exception) | `bridge dispatch: channel_push unconfirmed` with `handler_note`, and **no** `bridge channel_push:` line | the push failed at or before the endpoint — nothing listening is the normal state of an idle seat; the intent is in `inbox.jsonl` | the seat's session; `bridge:check`'s channel lines |
| `delivered`, blank | `bridge dispatch: channel_push unconfirmed` **and** `bridge channel_push: accepted by transport (unconfirmed)` with `"status":202` | ⛔ **the bridge's half is complete — and this is the row a dead wake path leaves.** The endpoint wrote the notification to its transport, and nothing the bridge records sees past that. No block in the session ⇒ the fault is past the bridge: at the endpoint, or in the session that should be behind it. | restart the session that owns the channel server (§ *Update an existing install* owns how, and what not to do instead); the deployed channel-server version (§ *Multi-agent channel-server distribution*); `check-channel-snapshot.py`, run on the seat ([`docs/seat-tools.md`](docs/seat-tools.md)) |

## Update an existing install

```bash
cd ~/agent-webhook-bridge-<agent>
git pull --ff-only
# ⚠ Running a CUSTOM classifier/handler under app/Bridge/? Migrate it IN THIS STEP
# if you're crossing a contract change — see the callout below.
# ⚠ When the pull above moves examples/channel-servers/, THAT PULL is what skews the
# host: every per-agent SNAPSHOT taken from that directory is behind the reference
# from here on, and nothing on the host announces it. Files COPIED/hand-derived out
# of examples/ at install (the session launcher, and on some hosts the channel-server
# .mjs) live OUTSIDE the repo — git pull CANNOT update them. Reconcile each: see
# "Reconcile out-of-repo copies" below.
composer install --no-dev --optimize-autoloader
# ⚠ Channel server loading from THIS checkout? Reconcile its installed tree too —
# node_modules is gitignored, so the pull moved package-lock.json and left the
# INSTALLED tree untouched. Read the ⚠/⛔ callout right below this block: it says
# why nothing else catches that, and why the RESTART afterwards is an ASK.
( cd examples/channel-servers && npm ci --no-audit --no-fund )
php artisan migrate --force                       # no-op if no new migrations
php artisan optimize:clear && php artisan optimize
php artisan bridge:check                           # VALIDATE BEFORE serving — names a stale custom classifier / config drift; STOP if non-zero
sudo systemctl reload php8.5-fpm                  # recycle workers so they re-read config + agent YAMLs
```

> **⚠ The channel server's `node_modules` — the pull does NOT update it, and nothing else notices.** `examples/channel-servers/node_modules/` is **gitignored** (DL-033), so a `git pull` / `git checkout <tag>` rewrites `package-lock.json` and leaves the **installed tree exactly as it was**. The install then runs whatever versions it resolved on its *first* `npm ci`, against a lockfile that says otherwise — including transitive packages a later lockfile bumped **to fix a CVE**. **Nothing in the toolchain reports this state:** GitHub's dependabot reads the **repo's** lockfile and correctly calls the alert fixed; `bridge:check`'s snapshot legs `stat` for `node_modules`' **presence** and never read a version out of it (that leg says so itself); and the supply-chain workflow audits the **committed** lockfile, not your disk. So the only thing standing between an install and a known-vulnerable module tree is the `npm ci` above — **run it on every update, not only when you noticed the lockfile move.** Measured twice on the reference host: 2026-08-11 (`ip-address`, `fast-uri`) and 2026-09-07, where two consecutive tag deploys left `fast-uri 3.1.5` / `qs 6.15.2` installed under a lockfile pinning `3.1.7` / `6.16.0`. `npm ci` is the whole fix *and* the whole verification — it **deletes** `node_modules` and reinstalls the lockfile exactly, so *installed == lockfile* is its postcondition, not something to re-check afterwards (this is precisely what `npm ci` does and `npm install` does not, which is why DL-033 chose it).
>
> **Where it sits, and why:** beside `composer install`, because it is the same operation on the **sibling manifest** — the pull moved both lockfiles, and this is the one nothing downstream reconciles. Keep it **before `bridge:check`** so the check's snapshot legs `stat` a settled tree rather than one that is about to be deleted and rebuilt under them. ⚠ **That ordering is hygiene, not a check that would catch this** — the presence leg passes on a stale tree exactly as it does on a fresh one, which is the whole point of this callout.
>
> ⚠ **Skip it only if this install's channel server does not load from this checkout.** A **standalone snapshot** (a per-agent `cp -R` elsewhere on the box) is reconciled in its own directory instead — see *"Reconcile out-of-repo copies"* below, whose `npm ci` is the same step one directory over. Read the `args` path of the channel entry in the seat's `~/.mcp.json` to find out which topology you are in.
>
> ⛔ **Then RESTART the channel server — and that is an ASK, not a step you automate.** `npm ci` changes files on disk; a channel server that is already running holds the **old** modules in memory and keeps doing so indefinitely. The reference channel server is an **MCP stdio child of the seat's `claude` process** (`StdioServerTransport`, spawned from that seat's `.mcp.json`), so "restart it" means **restarting the SESSION that owns it** — ⛔ **and NOT `/mcp reconnect`, which was written here and is wrong: /mcp reconnect does not stop the previous channel server — restart the session.** Reconnect spawns a second child while the first still holds the channel's address; the new one cannot bind it and exits 2 (measured on three seats on the port transport, roundtable #420 — [`docs/board-tools-enablement.md` § Activating on a running seat](docs/board-tools-enablement.md#activating-on-a-running-seat) owns the mechanism and the causes). Restarting the session **disconnects the live channel of the agent whose seat you are on**, dropping the transport the bridge pushes into until it comes back, which is why it is an ask. Do it at a session boundary, with the agent's agreement. ⛔ **Never wire a kill/restart of the channel server into an unattended deploy step:** the process you would be killing is the operator's own live session, and the deploy has no way to know what it is in the middle of. Until the restart happens, the `npm ci` is on disk and not in the running process — the deploy is not finished, and the install is not the version the lockfile says.

> **⚠ Running a custom classifier or handler?** A custom class under `app/Bridge/Classifiers/` (per [`docs/customization.md`](docs/customization.md) § Loading your classifier) is **untracked but not gitignored**, so `git pull` preserves your *old* file unchanged into the new release — **unless a release starts *tracking* a class at that same filename.** That happened at **v0.50.0**, which ships `CoordinationClassifier.php` **tracked** (roundtable #8) where installs previously vendored it as an *untracked overlay*: `git pull`/`checkout` then **refuses with a collision** (`error: The following untracked working tree files would be overwritten by checkout`) rather than preserving it. The refusal is a *safe* fail (it never silently clobbers your overlay) but it **blocks the pull** — back up + `rm` your overlay first, then pull. The tracked `CoordinationClassifier` with its default families `[coord-message]` reproduces the vanilla pre-#8 overlay; opt into `impl-ci-wake`/`kanban-triage` via `classifier.config`. See the **v0.50.0 Upgrading** note in [`docs/CHANGELOG.md`](docs/CHANGELOG.md). **Check [`docs/CHANGELOG.md`](docs/CHANGELOG.md) for a `classify()`/contract change in the versions you're crossing and migrate your class in the SAME step as the pull.** `classify()` has had two breaking changes (DL-022 added `AgentConfig $agent`; DL-025 collapsed to a single `classify(ClassifyContext $ctx)` — the **last** such break). An old signature is an uncatchable `E_COMPILE_ERROR` that fatals the receiver on the next live delivery — and with `opcache.validate_timestamps=On` the new contract is picked up within `revalidate_freq` of the pull, **before** the FPM reload, so the failure window opens at pull time. This is why `bridge:check` is ordered **before** the reload above: its out-of-process classifier load (DL-025) names a stale class instead of letting it fatal a request — but that only helps if you migrate-and-check, not pull-and-serve.

> **No `sudo`?** The FPM reload is for a clean worker recycle; it's not strictly required. With PHP's default `opcache.validate_timestamps=On`, FPM workers pick up changed `.php` / cached-config files within `revalidate_freq` (a couple of seconds) on their own. After a code/`.env` change, `optimize:clear && optimize` + a healthy `bridge:check` and `/up` 200 confirm the new state is live; reload when you can for a deterministic recycle.

> **⚠ Adding a writeback outcome that a newer version introduced (e.g. `started`, DL-160 / v0.37.0) — deploy the code FIRST, edit `writeback.json` SECOND.** A new outcome key is *unknown* to the older `WritebackConfig`, which rejects it as a **malformed config — and a malformed `writeback.json` fails closed for EVERY mapping in the file**, silently disabling your whole writeback (all repos), not just the one you edited. So if you edit config before the new code is actually serving (e.g. between the pull and the FPM reload, or you touched config first), every writeback goes dark until the new code runs. Order it: pull → `optimize` → reload/recycle → `bridge:check` green → **then** edit `writeback.json` → `bridge:check` again. See [`docs/writeback.md`](docs/writeback.md) § *Branch-create → In Progress* for the `started` config + the required `push` webhook event.

> **⚠ ONE-TIME, ON THE UPGRADE THAT INTRODUCES `config/database.php`'s `timezone` KEY (card#8825 / DL-346) — `php artisan migrate --force` REWRITES STORED TIMESTAMPS.** Before that key existed, the MySQL session kept the server's `SYSTEM` zone while PHP serialised every Eloquent timestamp as a bare UTC literal, so MySQL read each one as local time and stored `instant + host_offset`. The key stops that happening; the migration `2026_09_05_000001_correct_php_written_timestamps_to_utc.php` repairs the rows already written that way — every PHP-written column in `webhook_events`, `agent_dispatches`, `writeback_board_divergences`, `board_tools_client_calls` and `scheduled_jobs`. **Back the database up first** (`mysqldump` of this install's DB); it is a bulk `UPDATE` over your whole event history and the migration prints the row count it touched per table.
>
> ⛔ **`webhook_events.received_at` is NOT touched, and that is deliberate** — it is DB-written (`->useCurrent()`), so it always held the right instant and only *displayed* wrong. On this upgrade it starts reading four hours later than you are used to seeing; that reading is the correct one and nothing about the stored value changed.
>
> ⛔ **IT IS NOT REVERSIBLE.** The shift is read out of the disagreement between a DB-written and a PHP-written timestamp in the same row, and repairing the rows is what removes that disagreement — so `migrate:rollback` **refuses** rather than silently doing nothing. The pre-upgrade backup is the only way back; take it.
>
> ⚑ **Run the steps in the order above** (`migrate` BEFORE `optimize` and the FPM reload). Workers are still serving under the cached OLD config while the migration runs, so an event arriving in that window is written with the old skew and is not corrected — a handful of audit timestamps four hours out. The reverse order has the opposite residue, which is a correctly-written row the correction would shift again, so this order is the safe one. Quiesce the receiver if even that is unwanted. Re-running `migrate` is safe: the correction measures the skew before it writes anything and does nothing when there is none.
>
> ⚠ **It can REFUSE, and a refusal is not a failure to work around.** If `webhook_events` discloses more than one clock offset — a history that spans a DST boundary or a host whose zone was changed — no single shift is correct for all of it, so the pass throws and names the offsets with their row counts rather than corrupting the rows it would get wrong. Prune below the transition and re-run, or ask for a per-row repair; do not edit the migration to pick one.

### Reconcile out-of-repo copies (session launcher + channel server + custom classifier + seat-tools pack)

`git pull` only touches the repo. A few things the install depends on are **copied or hand-derived from the shipped samples (`examples/` + `docs/customization.md`) at install time** and live OUTSIDE what the repo ships, so a pull can't update them and they drift silently from the refreshed references. After every update, reconcile each against its reference — **diff-and-port; these are operator-customized, never blind-overwrite.** The **launcher** doesn't trip `bridge:check` at all (it runs inside the Claude Code *session*, not the Laravel app), so the diff is its **only** drift signal — and a stale connector is exactly how a session comes up **deaf to live-wake** ([`CLAUDE_DECISIONS.md`](CLAUDE_DECISIONS.md) DL-154/DL-155). The **`.mjs` snapshot** is **partially** checked **once you declare `channel.server_path`** in the agent YAML (DL-229/DL-230): `bridge:check` then WARNs when the deployed `package.json` version is behind this checkout's, reports **`unvalidated`** when that manifest cannot be read at all (DL-251 — the compare never happened), and FAILs when the path is dangling, when it names a file rather than the directory, when the directory holds no entry `.mjs` (nothing to launch), when it has no `node_modules` (the `cp -R` whose `npm ci` never happened — node dies on `ERR_MODULE_NOT_FOUND` at the next session start), and — since it never executes node — **nothing about whether the deployment will actually LAUNCH**. Every run that reaches the snapshot legs — stale, current, newer, or the repo-direct symlink — gets one `unvalidated` line saying exactly that (DL-237). The completeness leg that used to stand in for the launch question (DL-230, v0.71.0) is **retired**: measured on a real artifact, a pruned-but-working copy missing 6 of 10 reference files FAILed it while `node` bound and exited 0, and the same copy with `channel-lib.mjs` removed exited 1 on `ERR_MODULE_NOT_FOUND` — the launch is more precise in both directions. Every one of those legs `stat`s the deployment, so when the bridge's OS user cannot traverse into the deployed directory they are all replaced by a single *not visible to this user* line naming that directory — reported at severity **`unvalidated`** since DL-251 (a warn before it), because in that state nothing was measured at all — run it as the agent's user if you need them to conclude. Undeclared, it reports *not validated* at severity **`unvalidated`** — counted in the run's closing tally, never `ok` (card 5170), so a green `bridge:check` is not evidence about the snapshot — and the diff is again your only signal — the bridge cannot infer the path (it may run as a different OS user and cannot read the agent's `.mcp.json`). **Answer the launch question ON THE SEAT** — `check-channel-snapshot.py <deployed dir>`, run as the OS user whose Claude Code session launches the server (exit 0 launch OK · 1 launch FAILED, with node's own stderr · 2 could not check). It is a declared seat tool that runs with no bridge checkout; [`docs/seat-tools.md`](docs/seat-tools.md) owns how it reaches the seat's PATH (item 5 below keeps a staged pack current), and from a checkout `python3 bin/check-channel-snapshot.py` is the same program. Do **not** ask `bridge:check` to launch it: the bridge commonly runs as a different OS user, and a launch from there certifies the entry loads for the *bridge's* PATH and node — a proxy again (DL-237). **The diff still matters even when declared:** the check never reads a file's **content** and never enumerates the deployment, so a hand-edited copy is invisible to it, as is any extra file of your own; a **stale** copy gets the STALE warn and nothing more. The version WARN catches a stale **snapshot**, not a hand-edited one (a local modification that never touched `version` still needs the diff). A custom classifier is the partial exception: `bridge:check` confirms it *loads* (FQCN resolves + implements `Classifier`) but **not** that it's *current* with the reference, so the diff is still the only signal that it's behind on an improvement (DL-158).

1. **The session launcher.** The canonical [`examples/start-channel-session.sh`](examples/start-channel-session.sh) (bash, Linux UDS+HTTP) and [`examples/start-claude.ps1`](examples/start-claude.ps1) + [`examples/start-claude.bat`](examples/start-claude.bat) (Windows HTTP-tunnel) are **self-resolving** (see "The canonical channel launcher" below) — one copy serves any agent with no per-agent hardcoding, so a verbatim copy doesn't drift the way a hand-edited one did. After a pull, `diff` each deployed launcher against its sample and port any new guardrails. (A launcher predating DL-157 is hand-rolled + channel-pinned — replace it with the self-resolving one rather than re-porting.)

2. **The channel MCP server (`agent-webhook-bridge-channel.mjs`)** — find where it actually loads from: the `args` path of the channel server in **`~/.mcp.json`** (the entry keyed by the channel name, e.g. `kanbanboard-agent`). Two topologies:
   - **Loaded directly from a checked-out repo's `examples/channel-servers/`** (e.g. `…/agent-webhook-bridge-<agent>/examples/channel-servers/…`) — the pull already updated the **tracked** files, but **not** the gitignored `node_modules/`, which is why the update block above runs `npm ci` there **unconditionally**. It is not conditional on the lockfile having visibly changed — that condition is the one an operator silently fails. The ⚠/⛔ pair under *"Update an existing install"* owns this, including the restart; don't re-derive it here.
   - **A standalone COPY** (multi-agent hosts commonly snapshot it per agent, e.g. `*-coordination/OUTBOUND/<agent>/channel-setup/agent-webhook-bridge-channel.mjs`) — it drifts. See **"Multi-agent channel-server distribution"** below for the canonical git-tag reconcile.

   Either way, put that same directory in the agent's `channel.server_path` (DL-229) so `bridge:check` reports on it — undeclared, it says *not validated* (severity `unvalidated`, tallied at the end of the run) rather than healthy. **Co-located installs only:** the check `stat`s the directory from the bridge process, so declare it only when the deployed copy is on the bridge's own filesystem. In the cross-host topology of [`docs/multi-host.md`](docs/multi-host.md) the channel server lives on host B and the bridge on host A cannot see it — leave `server_path` unset there, or `bridge:check` reports a dangling path (it cannot distinguish "on another host" from "removed").

3. **Multi-agent hosts** — every agent has its own launcher and its own `~/.mcp.json` pointing at its own `.mjs`. Reconcile **each** agent's copies, not just the one whose session you're in.

4. **The custom classifier** (only if the install runs one — `classifier.class` in an agent YAML points at an operator-authored `App\Bridge\Classifiers\*` the bridge doesn't ship, e.g. a GitHub-issue-comment surfacer). It lives in the install's `app/Bridge/Classifiers/` so it *survives* a pull untouched — which is exactly the drift: it freezes at the reference it was copied from. **Exception — a filename the bridge later ships tracked:** if your overlay's class name becomes one the bridge *tracks* (e.g. `CoordinationClassifier` since **v0.50.0**), the pull **collides** instead of preserving (see the "Running a custom classifier?" callout above + the v0.50.0 Upgrading note in [`docs/CHANGELOG.md`](docs/CHANGELOG.md)) — retire the overlay first, then adopt the tracked class via `classifier.config`. A **new** install starts from the worked example in [`docs/customization.md`](docs/customization.md); on each update, **diff your classifier against that reference and adopt improvements** (e.g. the `comment_id`/`comment_created_at` forwarding, DL-158) — a reconcile-**merge** that preserves your deployment-specific extensions (extra event kinds, addressing/recipient logic), not a blind replace. `bridge:check` will tell you it loads; only the diff tells you it's current.

5. **The seat-tools pack** (only where one is staged — a PM's `OUTBOUND/<agent>/`, or a checkout seat's link-shape pack). Unlike every item above, this one is regenerated rather than diff-and-ported: the pack is generated output, not an operator-customized copy. A **copy-shape** pack drifts like the channel-server copy, so after the update re-stage it from the release-pinned checkout and commit it with its exec bits. A **link-shape** pack follows the checkout, except when `seat-tools.json` gains or drops a tool. [`docs/seat-tools.md`](docs/seat-tools.md) owns the staging command, the `100755` requirement, the currency check (`diff -r -x node_modules` against a fresh stage; empty is current), what a changed declaration needs on a seat, and what the PM can and cannot see.

Locate every copy so none is missed (run from the updated repo):

```bash
find ~ -name 'agent-webhook-bridge-channel.mjs' -not -path '*/node_modules/*'              # all channel-server copies
find ~ -name seat-pack.json -path '*seat-tools*'                                           # copy-shape seat-tools packs (a link-shape pack writes none)
find ~ -maxdepth 4 \( -name 'start-claude.sh' -o -name 'start-channel-session.sh' \
   -o -name 'start-claude.ps1' -o -name 'start-claude.bat' \) -not -path '*/node_modules/*'
# per .mjs copy, the one-field drift check (non-empty output ⇒ re-sync that copy + npm ci):
diff <(jq -r .version <copy-dir>/package.json) <(jq -r .version examples/channel-servers/package.json)
```

#### Multi-agent channel-server distribution (uniform provenance)

> ⚠ **This procedure REQUIRES a bridge checkout on the host that holds the snapshot.** Every step below reads `examples/channel-servers/` out of a working tree pinned to a release tag, so a seat that consumes the **channel** rather than the **bridge** — running from a snapshot directory with no `agent-webhook-bridge` clone — has nothing to run step 1 against. That is a requirement of the procedure, not a property of your fleet: check it before you start, and if it does not hold, read *"No checkout on the host holding the snapshot"* below instead.

A multi-agent host snapshots `examples/channel-servers/` once **per agent**, and those snapshots freeze at install version and drift silently — we've found copies several minor versions stale. **On a host that HAS the checkout, repo ACCESS is not what stops the reconcile**: the tree is already on disk and `git fetch` reaches the remote — the only catch is that **`gh` CLI auth ≠ git-credential auth**, so a `gh`-based reachability test can mislead, use plain `git`. ⛔ That is a claim about **permissions on a host that has a checkout**, and it is not the claim that every seat has one — those are different facts, and only the first one is established here. The canonical reconcile, run per snapshot:

```bash
# in the repo checkout, pin to the release the fleet should run (NOT a moving branch):
git fetch --tags && git checkout v<version>     # git, not gh — uniform provenance = every agent on the SAME tag
cp -a examples/channel-servers/. <snapshot-dir>/ # overwrite the snapshot from the pinned tree
( cd <snapshot-dir> && npm ci )                  # pinned lockfile (DL-033)
```

Do this **at a session boundary** (Claude Code not running for that agent): a live connector holds the old `.mjs` in memory, and swapping it mid-session risks live-wake. `package.json` `version` is the drift signal (DL-038) — if a snapshot's version is behind the tag's, it's stale.

> ⛔ **WHICH TAG YOU PIN DECIDES WHETHER ANY OF THIS BECOMES MEASURABLE, AND THERE IS A FLOOR: `0.9.15` is the first reporting snapshot** — the first `examples/channel-servers/` release that sends its own `client_version` on a board-tools call **at all**. (The pin is `App\Bridge\Tools\ClientVersion`'s `FIRST_REPORTING_SNAPSHOT`, and `tests/Unit/Docs/ClientVersionFloorLockstepTest.php` reds if this sentence and that constant ever disagree — it is a frozen historical fact, not the version this checkout bundles, so it does not move when the snapshot does.) Pin the fleet **below** that floor and the reconcile above still runs correctly on every seat — right tag, right `cp -a`, right `npm ci`, right session restart — while `bridge:check` keeps printing *CLIENT VERSION NOT REPORTED* at **`ok`**, never `warn`, for every one of them. The line is not silent — it names both causes it cannot tell apart (*an older copy, or a caller that is not a channel server at all*) and says to re-deploy — but **it is printed on the bridge, hours later, to whoever runs `bridge:check`, and nothing at the point where the tag is CHOSEN says a floor exists at all.** From the bridge's side those two causes are the same absence, so the line cannot say which seat is actually below the floor. Measured on a peer fleet (2026-09-13): three live seats calling board tools, `client_version` NULL on all three — and one of them had been reconciled that same day, correctly and to completion, onto a snapshot below the floor. **If the reported-version leg is part of why you are reconciling, pin at or above the floor** — `jq -r .version examples/channel-servers/package.json` in the pinned tree tells you what the tag you chose actually carries.
>
> ⚠ **BOOTSTRAP — the floor is crossed ONCE per seat, by hand, and never again.** The surface that reports staleness is distributed BY the artifact whose staleness was the problem, so the one range it can never speak about is exactly the range that predates it: a seat below the floor cannot be told *by this leg* that it is below the floor. (The `channel.server_path` legs still catch a stale deployment by `stat`ing it — but they need the deployment to be on the bridge's own filesystem and readable by its OS user, which is exactly what the reported version exists to do without.) Re-running the reconcile at the same tag re-reads the same `ok`. **Re-deploy that seat once onto a snapshot at or above the floor and restart its session** — the version is read when the channel server starts — and from then on the leg answers on its own, for every future drift.

**No checkout on the host holding the snapshot.** The seat can read its own `package.json` `version`; what it has no local way to learn is what that value SHOULD be, because the comparison target — the checkout's `examples/channel-servers/package.json` — is the thing it does not have. So *"am I stale?"* is not answerable on that host, and the drift check and the reconcile above both read out of a directory that host does not have. What DOES work from there:

- **The bridge can answer the staleness question on the seat's behalf.** The reference channel server sends its own snapshot version on **every board-tools call**, and `bridge:check`'s `client half REPORTED …` line prints that version beside the one this checkout bundles and WARNs when the seat is behind (DL-364). It compares a **reported** value rather than `stat`ing a directory, so it crosses OS users and hosts, unlike the `channel.server_path` legs. Have the seat make one board-tools call, then read the line on the bridge — [`docs/board-tools.md`](docs/board-tools.md) § *How it is wired (operator view)* owns that leg, including the states in which it reports no version at all (an absent report is **not** a stale seat). ⛔ **It answers for a seat AT OR ABOVE the reporting floor only, and that is the one fact the checkout-less host cannot derive locally either: `0.9.15` is the first reporting snapshot.** A seat on anything older sends no version, the line reads *not reported* at `ok`, and that is the ABSENCE of a staleness verdict rather than a clean one — read the floor callout above before concluding that a quiet fleet is a current one.
- **`check-channel-snapshot.py` answers a DIFFERENT question** — *will this deployment launch* — and deliberately makes no claim about staleness (DL-237). It is a declared seat tool, so it runs on a seat with no checkout once installed there ([`docs/seat-tools.md`](docs/seat-tools.md)), but it is not a substitute for the version compare.

**Re-syncing still needs the pinned tree on that host**, since the snapshot is a copy OF it: either give the host a checkout (`git clone` the repo, then `git checkout v<version>`) and run the reconcile above there, or have a host that already has one at that tag copy the tree across. The reconcile itself cannot be done from the snapshot alone.

### Smoke-test the receiver with a signed delivery

The real-surface post-update check: fire **one signed synthetic delivery** at the live receiver, exercising the actual HMAC → adapt → classify → dispatch path without polluting the upstream board/repo. The signature is over the **raw body** (G-011); the body's scope source **must equal** the `?b=<scope>` query param or the receiver returns `401 scope_mismatch` (G-018) — for GitHub that source is `repository.full_name`, for kanban it's `board_id`.

⛔ **`bridge:sign` produces the signature, and NOTHING here reads the secret into a shell variable** (DL-322). The secret file is `chmod 600` because a co-tenant who can read it forges signed deliveries; a `openssl dgst -hmac "$SECRET"` — which this block used to say — hands that value to every local account through `/proc/<pid>/cmdline` for as long as the process lives, routing around the file's own protection. `bridge:sign` takes the `(provider, scope)` KEY and resolves the secret itself, through the same code the receiver verifies with: same `%2F`-encoded filename, same trimming of the file's bytes, same `sha256=` scheme. A hand-rolled signer that gets any of those three wrong produces a `401 sig_mismatch` that reads as a receiver fault. The general rule this block is one instance of — a secret value never reaches stdout, an argv, a log, or shell history — is [`docs/config-schema.md § Handling a secret VALUE`](docs/config-schema.md#handling-a-secret-value-not-just-its-file)'s (DL-321).

```bash
SCOPE='<org/repo>'                                 # GitHub: equals repository.full_name AND ?b=
BODY='{"action":"created",
       "repository":{"full_name":"'"$SCOPE"'"},
       "issue":{"number":1,"title":"smoke","labels":[]},
       "comment":{"body":"smoke","html_url":"https://example.invalid/x"},
       "sender":{"id":<sender-id>,"login":"x"}}'
# Run from the install root (the command needs this install's config). It prints the
# complete header value, `sha256=<hex>`, and nothing else; a failure prints WHY on stderr
# and exits non-zero, which leaves $SIG empty rather than substituting a diagnostic.
SIG=$(printf '%s' "$BODY" | php artisan bridge:sign --provider=github --scope="$SCOPE")
curl -X POST \
  -H "X-Hub-Signature-256: $SIG" \
  -H "X-GitHub-Event: issue_comment" \
  -H "X-GitHub-Delivery: smoke-$(date +%s)" \
  --data-binary "$BODY" \
  "$BRIDGE_RECEIVER_BASE_URL/github?b=${SCOPE}"     # BRIDGE_RECEIVER_BASE_URL ends in /webhooks → POST /webhooks/github
# then: php artisan bridge:stats   (expect errored=0) ; php artisan bridge:inspect <N>
```

⚠ **This proves the receiver, not a wake** — `errored=0` and a `delivered` row are both true over a dead wake path. § *Live-event path — configure it, then SEE a wake* owns that verification.

A `401 scope_mismatch` almost always means the body omitted (or mismatched) `repository.full_name` vs `?b=` — not an HMAC problem (G-018). A `401 unknown_scope` from **`bridge:sign` itself** (it names the path it looked at) means this install has no secret for that scope — the receiver would answer the same way, so fix it before reading anything into the `curl`.

## The canonical channel launcher (UDS / HTTP / Windows)

The shipped launchers are **self-resolving and transport-aware** — one copy serves any agent across the whole fleet with no per-agent hardcoding (DL-157), so they no longer drift the way hand-rolled per-agent copies did:

- [`examples/start-channel-session.sh`](examples/start-channel-session.sh) — **Linux**, both **UDS** (same-host, topology A) and **HTTP** over an SSH reverse tunnel (separate Linux hosts, topology B).
- [`examples/start-claude.ps1`](examples/start-claude.ps1) + [`examples/start-claude.bat`](examples/start-claude.bat) — **Windows** (topology C): HTTP-tunnel transport, and the launcher owns the tunnel lifecycle. The `.bat` is a one-line `ExecutionPolicy Bypass` shim; the `.ps1` is native end-to-end (`ConvertFrom-Json`, `Get-NetTCPConnection`, PID-tree teardown) — do **not** hand-port the bash to cmd (a wrong edit silently breaks live-wake).

**Identity resolution (first hit wins), shared by both launchers:**

1. `--channel <name>` (bash) / `$Channel` (ps1)
2. `$BRIDGE_CHANNEL_NAME` (exported env)
3. `settings.local.json` `.env.BRIDGE_CHANNEL_NAME`
4. `"<namespace>-<agent>"` from `$COORD_CONFIG`'s `.bridge.channel_namespace` + `$COORD_AGENT`

> **Why `settings.local.json` is in the chain (the #1 "can't resolve channel" cause):** the launcher runs in the **login shell**, which does **not** inherit the env Claude Code injects into a *session* (the `.env` block of `~/.claude/settings.local.json`). A launcher that trusts exported env alone silently fails to resolve. So the launchers read the keys straight from that file (jq on Linux, `ConvertFrom-Json` on Windows). On Windows a `COORD_CONFIG` stored Git-Bash-style (`/c/Users/…`) is converted to `<drive>:/…` before reading.

`COORD_CONFIG` is a JSON file with at least:

```json
{ "bridge": { "channel_namespace": "<your-namespace>" } }
```

so agent `foo` resolves to channel `<your-namespace>-foo`.

**Transport** is UDS unless `BRIDGE_CHANNEL_TRANSPORT=http` (then `BRIDGE_CHANNEL_PORT` is required); `uds` is accepted as an alias for the server's `unix`. The launcher **exports the resolved `BRIDGE_CHANNEL_NAME` / `BRIDGE_CHANNEL_TRANSPORT` / `BRIDGE_CHANNEL_PORT` (and `BRIDGE_CHANNEL_SOCKET` for UDS) before `exec`** — so the channel server binds **exactly** the endpoint the launcher just guarded, instead of re-deriving its own from `~/.mcp.json` (a divergence there silently guards one path while the server binds another — a deaf-session footgun).

**Deaf-session guards (FR #2444 / DL-154/155/156), rendered for both transports:**

- **Single-session refusal** — `pgrep` (bash) / `Get-NetTCPConnection` (ps1) on the channel argv / listening port; a second session would come up deaf, so it's refused.
- **Stale-listener guard** — UDS: a socket-curl probe + stale-socket removal; HTTP: a TCP-port listener probe.
- **Marker surfacing (never clear)** — a prior `<socket>.FAILED` / `…http-<port>.FAILED` marker is printed loudly; the channel server owns clearing it on the next successful bind. Both launchers derive the HTTP marker base as the server does — `XDG_RUNTIME_DIR` else `os.tmpdir()` (`$TMPDIR`/`/tmp` on Linux, `%TEMP%` on Windows) — so launcher and server look at the same path even when `XDG_RUNTIME_DIR` is unset (DL-156).

> **Windows tunnel lifecycle (don't regress):** the `.ps1` spawns the reverse tunnel as a **`-WindowStyle Hidden`** side process and tears it down **by PID tree** on exit. `Minimized` is *not* equivalent — `SW_SHOWMINIMIZED` activates the window, and a delayed child then steals focus seconds after launch (the user's first keystroke restores it). Hidden has no taskbar window to steal.

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

A malformed per-agent YAML is intentionally a `5xx` — the loader fails closed and kanban-board holds everything until the config is fixed and FPM reloaded. **`shared-identities.json` is the deliberate exception and never 5xxes:** it is an optional policy file, so an unreadable or non-JSON one degrades to "no shared accounts" with a logged warning, and attribution for a shared account silently goes missing instead. That is what `bridge:check` exists to surface before it happens — it reports the file's state (unreadable ⇒ `unvalidated`, not-JSON ⇒ `warn`, DL-259), which the receiver's silence cannot.

## Per-agent dispatch: done vs errored

Each `(event, agent)` is one `agent_dispatches` row:

- **done** — `processed_at` set. Intents were staged and handlers ran. If a *handler/push* failed (e.g. channel push to an idle agent — connection refused, which is NORMAL), the row is still **done** with `error_message` recording the note; the intent is already durable in `inbox.jsonl`, read via `bridge:inbox` when the agent returns. The webhook still 200s.
- **errored** — `processed_at` null, `error_message` set. The classifier threw (a deterministic bug). The webhook still 200s (a 5xx would retry-storm an event that fails identically every time). Fix the classifier, reload FPM, then `bridge:replay <id>` — **while the event still has its payload** (default 7d; see § *Retention config (DL-199)* below).

⛔ **`delivered` IS NOT A READ RECEIPT, and since card#9172/DL-370 the bridge stops implying it is.** The stored `outcome` is unchanged — every completed dispatch still writes `delivered`, because `bridge:replay`, its `--force` transitions and `bridge:standup` all key on that value. What changed is what the bridge SAYS: a dispatch that **attempted** a `channel_push` leg logs **`bridge dispatch: channel_push unconfirmed`** instead of `bridge dispatch: delivered` — including one whose push FAILED, since the push raises on any non-2xx (the endpoint reached and answering) and the leg is unconfirmed either way; the row's `error_message` still names which failure it was, and `bridge:inspect` prints a legend under the ledger table saying what the column does and does not evidence. ⚠ **`attempted` is wider than "reached a transport"**, and the wording is chosen for the wider set: the handler also raises ABOVE the send (no endpoint configured, a method outside the allow-list, an unreadable `channel.token_path`, a classifier socket outside the allowed dir), and on those arms nothing was written anywhere — which is why the dispatch line does not say *accepted by transport*. A **separate** `bridge channel_push: accepted by transport (unconfirmed)` line reports what the endpoint itself declared about delivery receipts (the shipped channel server declares it has none) and is written **only for a push that reached the transport**, so its absence beside a `channel_push unconfirmed` line means the leg failed before any write — read `error_message` for which. The reason is that `channel_push` treats a 2xx as its sole success condition and the channel endpoint answers as soon as the notification is **written to its transport**. ⚠ Whether a notification a mid-turn session never surfaces is dropped or merely deferred to its next turn boundary is **not established**, and nothing on this path claims either. ⚠ **Anything grepping the literal `bridge dispatch: delivered` on a live-wake install needs updating.**

A **delivered** row's `reason` is non-null in exactly one case: **`echo: agent surface suppressed`** (DL-203) — a github event whose actor tripped an echo/signal gate, classified by a writeback-emitting classifier, had its agent-facing surface (inbox intent + channel push) stripped and only the machine writeback handlers ran. `error_message` stays handler-failure-only, and `bridge:replay`'s gate-DROPPED skip count is unaffected (the row is delivered, not dropped).

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
| Webhook 5xx record (DL-409) — the current run of consecutive 5xx, and the last recovery | `…/state/webhook-5xx.json` (+ `webhook-5xx.json.lock`), written by the receiver |
| `protocol:invalid` label writes this install still owes (DL-419) | `…/state/protocol-invalid-labels-owed.json` (+ `protocol-invalid-labels-owed.json.lock`), written by the receiver, and rewritten by `bridge:relabel --fix`. ⛔ **Operating rule: run every bridge command that writes state as the receiver's user; never with sudo.** This file is why: it is mode **`0600`** and owned by whoever wrote it (the `tempnam()` in `writeFileAtomic()`), so a write as anyone else takes it off the receiver, which then records no further refused writes (each is logged as `owed_record_unwritable`) while the new owner's report says nothing is owed. Where PHP's posix extension is loaded, `bridge:relabel` REFUSES, non-zero in both modes and before reading, sending or writing anything, **as root** and **as any user other than the owner of the record or its `.lock`**, and names the user to run as; every writer of the record refuses the same way (a `bridge:replay --force` as root logs `owed_record_unwritable` instead of writing). ⚠ **That refusal is enforced ONLY where PHP's posix extension is loaded** — it reads the process's uid with `posix_geteuid()`, and `composer.json` does not require `ext-posix`. Without it nothing is refused, and the operating rule above is the only guard. ⚠ **Not refused either:** the FIRST write of an absent record by a non-root user other than the receiver's — nothing the bridge can read says which user the receiver runs as. The file is then that user's, the receiver cannot open it and **records no further refused writes** (each is logged as `owed_record_unwritable`) — give the file, and its `.lock`, back to the receiver's user. A record that cannot be read or parsed is never rewritten or moved (`docs/writeback.md`) |
| Which `bridge:inbox` consumers have been shown that recovery | `…/state/webhook-5xx-notice-seen.json`, written by `bridge:inbox` |
| Handler forensic log (`log_intent`) | `…/state/handler-log.jsonl` |
| Per-target registry (`registry_append`) | `…/state/registry-<target>.jsonl` |
| Detached-command logs (`spawn_detached`) | `…/state/spawn-<target>.log` |
| Event / dispatch ledger | the DB (`webhook_events`, `agent_dispatches`) |
| Writeback board divergences (DL-300) | the DB (`writeback_board_divergences`) — expected EMPTY; `bridge:stats` prints the counts on every run, and when there is anything to show, each divergence's first-seen / last-seen / observation count and its write site (DL-347) |

## Commands

```bash
php artisan bridge:check [--probe-tools=<endpoint>]   # validate .env, dirs, DB, agent YAMLs; --probe-tools live-probes the board-tools path (DL-220).
                                                      # Ends with a NEXT STEPS block naming what is not wired end to end and the ONE
                                                      # command to run next (DL-352) — silent when nothing is outstanding, and carried in
                                                      # --format=json as next_steps[] (shape + state vocabulary: docs/check-json-contract.md
                                                      # § 7a). ⚠ Since DL-368 it also covers github subscriptions whose repo webhook is
                                                      # gone, and THAT fault is a `fail` — so an install printing that entry exits
                                                      # non-zero. The block still emits no finding of its own; the leg above it does.
                                                      # Since DL-382 it also names a github subscription whose OWN delivery record
                                                      # has gone quiet — a `warn`, needing no token, and blind to deliveries that
                                                      # arrive and are then dropped (docs/writeback.md § A declared github scope that
                                                      # has gone quiet).
                                                      # ⛔ --probe-tools does NOT verify a seat's half: it stamps the same ledger row from
                                                      # this box (docs/board-tools.md step 6), so it clears the line without the seat calling.
php artisan bridge:stats                              # event/dispatch counts; errored split replayable vs NOT (payload nulled); writeback board divergences + per-divergence history
php artisan bridge:inspect {id}                       # one webhook event + its dispatch ledger
php artisan bridge:replay {id} [--agent=] [--force]   # re-run dispatch for an event
php artisan bridge:inbox [--hook-format=auto|claude-code|plain]              # surface unseen inbox intents, and the webhook 5xx record (DL-409)
php artisan bridge:provision [--dry-run] [--list] [--agent=] [--reconcile] [--allow-unreachable-receiver]
                                                                            # ensure kanban subscriptions (--reconcile fixes drift);
                                                                            #   offers a missing writeback identity_id (DL-369);
                                                                            #   refuses a receiver URL this app would not route unless
                                                                            #   --allow-unreachable-receiver (DL-377); refuses outright a
                                                                            #   base bridge:check rejects as a URL (card#9510)
php artisan bridge:provision-tools [--dry-run] [--agent=] [--host-a=] [--ssh-port=] [--pubkey-from=]
                                                      # mint per-agent board-tools bearers (DL-217/DL-220; idempotent, collision-checked).
                                                      # For an ssh-transport agent it mints nothing and prints that agent's SETUP PACKET
                                                      # instead (DL-357) — five steps, three actors; STEP 3 is the OPERATOR's pin and
                                                      # is emitted inside a USER ACTION REQUIRED banner. The three packet options are
                                                      # ssh-only and each is refused without --agent. docs/board-tools-enablement.md
php artisan bridge:prune --older-than=30d [--null-payloads-older-than=7d] [--dry-run]   # retention, manual/unbounded (the receiver self-prunes — DL-199)
php artisan bridge:reconcile [--fix] [--repo=owner/repo] [--max-moves=20]     # board-vs-GitHub drift reconciler (report-only unless --fix)
php artisan bridge:relabel [--fix] [--repo=owner/repo] [--limit=50]           # finish the protocol:invalid label writes this install
                                                      #   decided on and could not land (DL-419; report-only unless --fix).
                                                      #   No timer, gate or job runs it — the bridge re-attempts an outward
                                                      #   write only when a person asks. --fix exits NON-ZERO while anything
                                                      #   in scope is still owed; either mode exits NON-ZERO on a record it
                                                      #   cannot read. Run as the receiver's user (the record is 0600). docs/writeback.md
php artisan bridge:standup [--dry-run]                # PM standup digest (DL-306); --dry-run prints it as JSON and pushes nothing
php artisan bridge:jobs [list|add|remove|enable|disable|run] [name] [--json] [--assert-tick]   # the periodic-job registry (DL-325)
php artisan bridge:tick                               # one bounded pass over that registry — the opt-in crontab ingress (DL-325)
php artisan bridge:sign --scope=<scope> [--provider=github] [--body-file=]   # print `sha256=<hex>` for a raw body read from stdin (DL-322)
```

**Console output is plain text: no colour, and no terminal control sequence at all (DL-393, operator decision 2026-09-15, Option 1).** Every Artisan command's output passes an output choke that strips control, C1, bidi and zero-width characters before they reach your terminal, and `--ansi` does not turn colour back on. Two vendor-drawn interactive renderers are switched to their plain-text form for the same reason: `php artisan migrate` (and any other command's) yes/no or pick-one prompt renders as an ordinary typed question rather than an arrow-key box, and Symfony's autocomplete no longer redraws the line as you type. `bridge:check` shows a finding's severity as a leading word — `FAIL: `/`WARN: `/`UNVALIDATED: `/`OK: ` — ahead of its message, as well as through the exit code, the `unvalidated` tally and NEXT STEPS. ⚠ **If you scripted against `bridge:check`'s TEXT output (not `--format=json`, which carries no marker — `docs/check-json-contract.md` §2 records what the choke can still change in that document), the new marker is a leading token your parser did not expect.** ⚠ **One route around the choke is yours to choose:** selecting Laravel's `stderr` log channel (`LOG_CHANNEL` / `LOG_STACK`) writes every log record of an interactive run straight to the terminal, unstripped. The default `stack`/`single` channels write to `storage/logs/laravel.log`.

`bridge:prune` is the **manual** entry point to retention; since **DL-199** the receiver runs the same shared service automatically after each response, so scheduling this is no longer required. ⚠ **Retention itself still has no cron** — the one crontab line DL-325 allows drives the periodic-job REGISTRY, and retention is not a row in it (`docs/periodic-jobs.md`); adopting the tick does not schedule this command and never will. `--older-than=Nd` deletes `webhook_events` (cascading `agent_dispatches`) and trims `inbox*.jsonl` lines older than the cutoff; `--null-payloads-older-than=Md` (use `M < N`) nulls the stored payload past the replay window while keeping the row's dedup-gate + audit metadata; `--dry-run` reports counts only. Idempotent — safe to re-run alongside the automatic gate. **`writeback_board_divergences` is deliberately outside retention entirely** (DL-300): it exists to outlive the log, so a window on it would be the defect it closes with a longer fuse.

**When you still want it:** draining a large backlog in ONE unbounded pass (the gate is deliberately bounded to `retention.batch` rows per delivery), a window different from the configured one, or any install running with `BRIDGE_RETENTION_ENABLED=false`. See `CLAUDE_DECISIONS.md` DL-012 (the command) and DL-199 (the gate).

### Retention config (DL-199)

| Key | Env | Default | Meaning |
| --- | --- | --- | --- |
| `retention.enabled` | `BRIDGE_RETENTION_ENABLED` | `true` | Prune after each delivery. **Defaults ON** — an upgrade starts pruning without operator action. |
| `retention.interval` | `BRIDGE_RETENTION_INTERVAL` | `86400` | Seconds between passes once the store is drained. |
| `retention.older_than` | `BRIDGE_RETENTION_OLDER_THAN` | `30d` | Delete events/dispatches + trim inbox lines older than this. Same vocabulary as `--older-than`. Empty ⇒ the ROW leg is off, and `bridge:check` **warns** that rows are never deleted (card#8374) — **only on a run that could MEASURE the store**; see the § below. ⚠ **DL-315 CHANGES WHAT AN EMPTY VALUE DOES.** With both windows empty, retention had no usable window at all: `bridge:check` printed `retention: enabled but MISCONFIGURED`, nothing was pruned, and an operator could be relying on that as an inert state. The payload default below now resolves to `7d`, so the **same `.env`** becomes usable — the preflight flips to `retention: on (null payloads >7d, …)` and **the payload leg starts running on the first inbound webhook after the upgrade**. If an empty row window was how you kept retention inert, set `BRIDGE_RETENTION_ENABLED=false` (the supported way, and the one `bridge:check` warns about) **before** upgrading. |
| `retention.null_payloads_older_than` | `BRIDGE_RETENTION_NULL_PAYLOADS_OLDER_THAN` | **`7d`** | Null payloads past the replay window, keeping the row. Empty ⇒ the leg is off, and `bridge:check` **warns naming the payload bytes retained** (card#8374) — **only on a run that could MEASURE the store**; see the § below. **Defaults ON** — an upgrade starts nulling payloads without operator action (DL-315). ⚠ **It IS the replay window:** `bridge:replay` REFUSES a payload-nulled event. **No grace period** — the gate's marker is a cache key, so the first inbound webhook after deploy runs a pass. To opt out (`''`) or widen it, set the value **before** the upgrade and re-run `php artisan config:cache`. ⚠ **This default moving also changes what an EMPTY `BRIDGE_RETENTION_OLDER_THAN` does** — see that row above; an install with both windows empty was reported MISCONFIGURED and pruned nothing, and now runs this leg. |
| `retention.batch` | `BRIDGE_RETENTION_BATCH` | `500` | Max rows one pass touches per leg. While a backlog remains the gate keeps draining on successive deliveries rather than waiting out `interval`. |

An unparseable window (or a non-positive `interval`/`batch`) prunes **nothing** and logs a warning once per day — it never falls back to a default cutoff, because that would delete on a typo.

#### What `bridge:check` reports about retention (card#8374 / DL-331)

The preflight reports the resolved posture **and what the store is actually holding**, because the posture line alone is a restatement of the config: it reads identically on an install with four rows and on the one that produced this leg — 894 MB of a 1.2 GB store being 30 days of full payloads, under a retention that was working correctly the whole time.

```
OK: retention: on (delete >30d + null payloads >7d, every 86400s, 500 rows/pass)
OK: retention: database 1.2 GiB · webhook_events 12345 rows, 11987 still carry a payload holding 894.0 MiB (~73% of the database) · oldest row 12.4d old, inside the 30d delete window.
```

⛔ **On MariaDB the `(~73% of the database)` clause is NOT printed** — the line withholds the share in words and names what to size the store by instead:

```
OK: retention: database 1.2 GiB · webhook_events 12345 rows, 11987 still carry a payload holding 894.0 MiB (share of the database NOT shown: …) · oldest row 12.4d old, inside the 30d delete window.
```

⚠ **The elision is deliberate — the withheld-share clause is quoted NOWHERE in this repo's prose.** It is printed verbatim by `App\Bridge\Check\Checks\RetentionPostureCheck::payloadShare()`, read it there; hand copies of it are what let a correction to this section leave the executable copy saying the opposite for a whole review round (card#8374). The bullet below owns the operator procedure the clause points at.

- **The store line** is `ok` while the oldest retained row is inside `older_than`, and **`warn`** once it is past it — naming `batch`, because a backlog draining at that many rows per delivery is the benign reading and has to be distinguishable from a delete leg that is not running. An **empty** store says so (`webhook_events is EMPTY (0 rows)`) rather than printing zeroes.
- **Either leg being OFF is its own `warn`**, carrying its cost: an empty `older_than` says rows are never deleted; an empty `null_payloads_older_than` names the bytes of payload the install is holding and the row window they are held until. Neither moves the exit code.
- ⛔ **BOTH OFF-leg warnings are CONDITIONAL ON THE STORE HAVING BEEN MEASURED, and a run that could not measure it emits NEITHER.** The store measurement is the first cost leg, and nothing below it runs when it throws: the run reports `retention: could NOT measure what the store is holding (…)` as `unvalidated` and stops there. That is deliberate rather than an omission — the counts and bytes ARE those warnings (*"11987 of 12345 retained rows still carry a full webhook payload, holding 894.0 MiB"*), so a warning printed without them would be the bare config restatement this leg exists to replace. ⚠ **The practical consequence for an operator: a `bridge:check` whose store read failed is NOT evidence that the retention legs are on.** The `retention: on (…)` posture line above it is evidence about the CONFIG only, and it says so.
- ⛔ **A figure this install's database driver cannot source is ABSENT from the line, never inferred and never printed as a zero.** The database size and the payload byte count need engine-specific SQL (SQLite counts its own pages and its `length()` counts CHARACTERS; MariaDB reads `information_schema` and counts bytes); on any other driver the line says so in words and reports the portable half. A store that could not be read at all reports `unvalidated` — *not* an empty store.
- ⛔ **THE PAYLOAD SHARE IS WITHHELD ON MariaDB, and the two BYTE figures are what you compare instead.** They are not one measurement: the payload sum is a live scan of the rows, and the size is `sum(data_length + index_length)` over `information_schema` — which, on `ROW_FORMAT=Dynamic`, **excludes every payload byte InnoDB stores off-page**. Measured on **MariaDB 10.6.28 and 11.8.9** (card#8374): 200 rows carrying 13107200 bytes left `data_length` at 16384 — one page — while `table_rows` refreshed to 200, so it is the accounting and not stale statistics. A share computed over that denominator is not merely approximate; on an install whose payloads straddle the inline-row limit it is **believable and wrong**, which is why it is withheld rather than clamped. ⭐ **To size the store on MariaDB, `du -h` the TABLE's own tablespace file:** under `innodb_file_per_table=ON` (the default) that is `<datadir>/<schema>/webhook_events.ibd`; with it OFF the table's bytes are inside the shared `<datadir>/ibdata1` and cannot be separated from every other table's. A whole-datadir `du -sh` may be quoted only as an **UPPER BOUND, labelled as one** — it also counts redo/undo, binlogs and every other schema, which is the cross-basis denominator this clause exists to avoid. Compare that figure against the payload figure this line prints — the engine has no privilege-free source that includes off-page data (`information_schema.innodb_tablespaces` does not exist on either version, and `innodb_sys_tablespaces` needs a global `PROCESS` grant this install is never asked for).
- ⚠ **The payload figure is a full scan of `webhook_events`**, so `bridge:check` takes seconds on a multi-GB store. That scan is the point; nothing cheaper measures the payload share rather than inferring it. The receiver's own retention pass is untouched and never runs it.

### Standup digest config (DL-306)

`bridge:standup` is the **manual** entry point; when `standup.enabled` the receiver pushes the same digest automatically, after the response, at most once per `interval`. **Off by default** — unlike retention, a pass makes outbound calls (one board read per mapped board, then the channel push).

| Key | Env | Default | Meaning |
| --- | --- | --- | --- |
| `standup.enabled` | `BRIDGE_STANDUP_ENABLED` | **`false`** | Push the digest after a delivery. Disabled ⇒ no terminating callback is registered at all. |
| `standup.agent` | `BRIDGE_STANDUP_AGENT` | *(none)* | The recipient seat's `<agent>.yml` name; its own `channel` block is the endpoint and its `channel.auth.token_path` the bearer. **No default recipient.** A missing, non-string, or non-filename-shaped value (`../x`, `.hidden`) is REFUSED — the name is concatenated into a `<config_dir>/<agent>.yml` path. |
| `standup.interval` | `BRIDGE_STANDUP_INTERVAL` | `86400` | Seconds between passes. ⚠ A **delivery** cadence: the pass runs on the first inbound webhook after this elapses, so a silent install pushes nothing. |

⛔ **The digest carries only what the bridge measures.** Per seat: `last_delivery_at` (a DELIVERY time — the bridge has no per-seat activity or liveness signal, so there is no `last_activity` and no context-%) and `unseen_inbox_intents`. Per board: `now_depth`, and only for a board whose `writeback.json` mapping declares `coord_card_lane_stage_ids`. **A field it cannot source is ABSENT** — a seat with no delivered dispatch carries no `last_delivery_at` key, a board with no Now-lane model produces no row, and a failed or truncated board read produces a row whose depth is absent and whose `now_depth_unavailable` names the cause. Run `bridge:standup --dry-run` to see exactly what this install can answer for.

A misconfigured posture pushes **nothing** and warns once per day, never per delivery; there is no partial digest and no fallback recipient.

⭐ **`bridge:check`'s `standup.posture` leg (card#8683 / DL-345) is where a WEDGED digest surfaces.** It is **silent** on an install that left the digest off, and on an armed one it prints the posture (`standup: on (push to <agent>, every Ns …)`) or the misconfiguration. Its third line is the one that matters: the gate arms its interval marker BEFORE the push, so a pass that throws — a channel server that is down on the recipient seat is the ordinary case — backs off a full `interval` and the seat simply **stops receiving digests with nothing saying so**. The gate records that throw, and this leg is what reports it; it clears itself on the next clean pass. ⚠ It never `fail`s, so it does not move `bridge:check`'s exit code — an opt-in report being down must not red a deploy.

### Periodic jobs (DL-325)

⛔ **Read `docs/periodic-jobs.md` before adding a job. A periodic job is the LAST resort here** — the event gate is the first answer, and the registry refuses an instance that does not say, in one sentence, why the work cannot be event-driven.

Jobs are **data**: one row per instance in `scheduled_jobs`, carrying `{name, handler, interval, owner, docs-ref, justification, enabled}` — where `justification` is a required **documentation slot**, not a gate: the insert refuses an empty answer on length and judges nothing about the one it accepts. Handlers are **code** — a job may only reference a handler that exists in this build, so what a job *can do* is fixed at code-review time; a row naming an unknown handler is a **loud refusal**, never a silent skip. Any code path may insert or remove an instance at runtime; `bridge:jobs` enumerates the whole periodic population.

**Two ingresses, and the second is opt-in per install:**

- **Default, no operator action:** the registry runs off the inbound webhook's after-response gate (DL-199's shape) — bounded, non-blocking, never on a client-visible path.
- **Opt-in:** ONE crontab line, under **the seat-owner account, never root** — and ONE **per install**, never per agent. ⛔ **The line is not restated here.** `php artisan bridge:provision-tools` prints it for THIS install (absolute interpreter, absolute paths, already filled in) and stops printing it once a tick is adopted; [`docs/periodic-jobs.md`](docs/periodic-jobs.md) § *Adopting the tick* owns the template and the two things that are easy to get wrong about it — the **absolute** interpreter (cron's `PATH` is minimal) and the **truncating** redirect (an appended `tick.log` grows without bound and nothing rotates it). Then declare the interval that line runs at so a dead line goes loud — ⛔ **the figure is not restated here either**, because it is DERIVED from the offered line's cadence and a copy here is a second number free to disagree with it; `bridge:provision-tools` prints the value beside the line, and the owner section carries it beside the template. ⚠ A `.env` edit is inert under `config:cache` — rebuild it.

**Death is the alarm.** The bridge records the last tick it received and reports its freshness against **this install's own declaration**, never a fleet constant. `php artisan bridge:jobs --assert-tick` exits non-zero **only** when a DECLARED tick is not fresh, which is what a session-start hook should run; `bridge:check`'s `jobs.posture` leg discloses the same fact at preflight. An **absent** record is reported as `unmeasured`, never as death, and an install that declared nothing is never reported as failing. ⛔ **And declaring the horizon is only half of it — wire the assert.** A declared horizon nothing ever asserts reads as coverage while reporting to nobody, so `--assert-tick` records that it ran and the `jobs.posture` leg **warns** until it has run here at least once (an install that declared no horizon stays silent). The `stale` message prints the jitter grace and the resulting threshold, both derived from the constant the verdict itself uses.

| Key | Env | Default | Meaning |
| --- | --- | --- | --- |
| `jobs.enabled` | `BRIDGE_JOBS_ENABLED` | `true` | The registry as a whole. With no rows it costs one indexed query per `min_pass_interval` on delivery. `false` ⇒ no callback is registered at all. |
| `jobs.min_pass_interval` | `BRIDGE_JOBS_MIN_PASS_INTERVAL` | `60` | Floor between passes, **shared by both ingresses** (the event gate is evaluated on every delivery). A 5/10/15-minute tick is never affected. |
| `jobs.max_per_pass` | `BRIDGE_JOBS_MAX_PER_PASS` | `3` | The bound. Oldest-due first; a backlog drains across passes. |
| `jobs.armed_mutators` | `BRIDGE_JOBS_ARMED_MUTATORS` | *(empty)* | ⭐ The governance gate. A handler declaring the state-mutating capability is INERT until named here — refused at insert **and** at run. Read-and-alert handlers need no entry. |
| `jobs.tick_expected_every` | `BRIDGE_JOBS_TICK_EXPECTED_EVERY` | *(unset)* | The tick adoption knob **and** the freshness horizon, in seconds. Unset ⇒ the tick was not adopted and its absence is never reported as a fault. |

⚠ **The jobs rule is NOT retention's rule.** A `min_pass_interval` / `max_per_pass` value outside its bound is **REFUSED, never clamped**: no pass runs on either ingress, `bridge:check`'s `jobs.posture` leg **FAILS** naming the key, and `bridge:tick` exits **non-zero** — where a misconfigured retention window prunes nothing, warns once a day and leaves the preflight reporting a posture. Same direction (a typo runs nothing), louder surface, because a crontab line has only an exit code to read.
`bridge:replay` re-runs the `processed_at`-guarded dispatch loop: errored rows (`processed_at` null) re-run; **already-succeeded rows are skipped** so a sibling's already-SENT push / `spawn_detached` is never re-fired (sent, not delivered — a channel push carries no receipt, see the `delivered`-row note above). `--agent` scopes to one agent. `--force` clears `processed_at` first so done rows (incl. handler-note rows) re-run too — use it to re-attempt a missed channel push once the agent is back.

⛔ **An event whose payload retention has NULLED is REFUSED (exit 1), not replayed** (DL-315). Replay cannot reconstruct a payload, and dispatching the empty one in its place is not a degraded replay — it stages a **fabricated** intent (`new card by <who>: <unnamed>`) to the agent's durable `inbox.jsonl`, wakes an event-driven seat with it, and stamps the errored dispatch `delivered`, erasing the record of the original failure. The refusal happens **before** any ledger write, `--force` included, so a refused run leaves every dispatch row exactly as it found it. `bridge:inspect <id>` still shows the row and names the cause; `bridge:stats` reports those rows as `errored (NOT replayable — event payload nulled by retention)` rather than counting them as replayable. **`retention.null_payloads_older_than` is therefore the replay window** — see the retention table above.

`bridge:reconcile` is the **rerunnable backstop for the event-driven writeback** (DL-183): GitHub delivers each webhook once with no retry, so a bridge outage during a PR event strands that card. Since DL-409 an outage the routing pipeline answered with 5xx is no longer silent: `bridge:inbox` reports it while it lasts and, after it, names this command as the remedy (one answered before a route is bound is still silent — see § Diagnose). It recomputes each tracked card's expected stage from GitHub PR ground truth and reports the drift (report-only by default; `--fix` applies the *forward* moves). Its **safety posture** — every guard it reuses, what it refuses, what aborts a board and what caps a run — is enumerated ONCE in [`docs/writeback.md`](docs/writeback.md) § *Reconciliation* and deliberately not restated here: the list stood in three hand-synced copies and the DL-301 refusal reached only one of them. Needs a github read token (the kanban repo is private) — resolved **per repo** from `bridge.providers.github.token_path` (`BRIDGE_GITHUB_TOKEN_PATH`, authoritative when set — point it at a centralized credential like `~/.config/coord/github-pat`), else `<secret_dir>/github/token`, else **store-native** (DL-185: `git-credential-coord` + the store's `[git-credential-map]` → a per-repo least-privilege PAT; `bridge.providers.github.credential_helper` / `BRIDGE_GITHUB_CREDENTIAL_HELPER`, default `git-credential-coord`, empty to disable — needs `HOME`/`COORD_CREDENTIALS` in the reconcile env to find the store), else an ambient `GH_TOKEN`. No new cron — schedule it from host cron or the session-close ritual (start report-only). See [`docs/writeback.md`](docs/writeback.md) § *Reconciliation*.

## Smoke test

1. Create a test card on the board.
2. Within ~1 s, a `<channel source="...">` tag appears in the connected Claude Code session carrying the intent JSON.
3. `SELECT id, delivery_id, event_type FROM webhook_events ORDER BY id DESC LIMIT 1;`
4. `SELECT agent_name, processed_at, error_message FROM agent_dispatches ORDER BY id DESC LIMIT 1;` — `processed_at` set, `error_message` null.
5. `tail -1 <BRIDGE_CONFIG_DIR>/state/inbox.jsonl | jq .`
6. **Negative check:** with no channel server running, create a card → expect `200`, the `agent_dispatches` row **done** with a connection-refused note, **and the intent still in `inbox.jsonl`** (the backstop). NORMAL for an idle agent.

## Diagnose

- **GitHub gets `200`, every diagnostic is green, and no agent wakes.** Read the ledger row before naming a stage — § *Live-event path — configure it, then SEE a wake* § 3.
- **`bridge:stats` shows errored dispatches, `NOT replayable`.** Those events are past `retention.null_payloads_older_than` (default 7d) and their payloads are gone; `bridge:replay` refuses them and no command can recover them. Fix the classifier so the class stops recurring, and widen the window (then `php artisan config:cache`) if your detection latency needs it.
- **`bridge:stats` shows errored dispatches.** A classifier threw. `bridge:inspect <id>` (or `storage/logs/laravel.log`) for detail → fix → `optimize:clear && reload php8.5-fpm` → `bridge:replay <id>`.
- **Idle agent — channel pushes "failing".** Connection-refused with no Claude Code session up is NORMAL: row is **done with a note**, intent is in `inbox.jsonl` for the next `bridge:inbox`. Not an incident; `--force` re-attempts the push.
- **A config edit "didn't take".** The optimize trap above — `optimize:clear && optimize && reload php8.5-fpm`.
- **kanban-board webhook auto-deactivated.** A short reinstall won't trip it (transient 5xx are mid-curve, not fully-failed). `curl …/api/v3/webhooks | jq '.data[] | select(.board_id==5) | .active'`; if `false`, re-run `bridge:provision`.
- **A github-subscribed agent gets no wakes.** Run `bridge:check` and read its `github delivery history` line for that scope first: it says whether this install has RECORDED deliveries for the scope and whether the silence is past what the scope's own record calls routine. A silent record points at the repo's webhook ([`docs/writeback.md` § A declared github scope that has gone quiet](docs/writeback.md#a-declared-github-scope-that-has-gone-quiet)). ⛔ **A healthy record does NOT clear the agent** — that leg witnesses the delivery side only, so deliveries recorded and then dropped before any wake (the echo gate, DL-373) read as healthy there; look at `agent.coordination_identity` and `bridge:inspect <id>` for a recorded event's dispatch outcome.
- **`bridge:inbox` prints `WARNING: N consecutive webhook 5xx since T`.** Every webhook request since `T` has ended in a 5xx, so no event from that window was processed (DL-409). The record holds status codes and times only, never the cause. Run `php artisan bridge:check`: its `database` line is the usual culprit (a rotated DB password on a login the bridge shares). An uncaught exception's detail is in `storage/logs/laravel.log`, and a 500 from the signature gate names itself in its response body. The warning repeats at most once per `WebhookOutageRecord::WARNING_REPEAT_SECONDS` per consumer (always on `SessionStart` and on a hand-run command), so a multi-day outage does not land in every tool call's context. The line clears on the next 2xx delivery, which then prints the recovery, once per `bridge:inbox` consumer and only within `WebhookOutageRecord::NOTICE_WINDOW_SECONDS` of it, with the `bridge:reconcile` remedy and what that remedy does not recover. ⚠ **It sees a 5xx the ROUTING PIPELINE answered, so its silence is not an all-clear.** The recorder is route middleware, and no route is bound for a request answered before routing: **maintenance mode** (`php artisan down` — the deploy window, or a deploy that failed and left the app down) and a **bootstrap / service-provider / config failure** (a stale config cache, a provider throwing) both answer 5xx from this app's address and record nothing, as do PHP-FPM, the vhost or TLS being down in front of it and a PHP fatal inside it. For all of those, check the repo's or the board's recent deliveries.
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
