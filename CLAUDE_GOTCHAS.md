# Gotchas

Surprising behaviors and "looked like X but actually Y" notes. When you hit an unexpected error in the bridge, check here before assuming a regression.

Entries are added when a bug surfaces during development that took >5 minutes to root-cause. Pre-empting future-you from spending the same 5 minutes is worth one paragraph.

> **v0.12 note (DL-001):** v0.12 collapsed the 5-layer async pipeline (PHP receiver + Python consumer + cron drain + freeze/thaw payloads + SQLite queue) into a single synchronous Laravel 13 app. Pre-v0.12 gotchas about the Python consumer drain loop, `lib/db.py` connect dispatch, cursor float precision, `importlib` extension-less loading, and `freeze_payload` shape are historical — the code is gone.

---

## G-002 — MariaDB rejects ISO-8601 `T...Z` timestamps; SQLite-only tests don't catch it

**Symptom:** An operation succeeds against the SQLite in-memory test DB but fails against the live MariaDB install with a datetime value error, or a MariaDB-specific behavior difference surfaces only in CI.

**Cause:** MariaDB's `TIMESTAMP(3)` column accepts `YYYY-MM-DD HH:MM:SS.fff` (space separator, no UTC marker). SQLite stores timestamps as text and accepts ISO-8601. `phpunit.xml` defaults to `DB_CONNECTION=sqlite / DB_DATABASE=:memory:` so the full test suite passes locally even when a MariaDB-only bug exists. The CI `phpunit-mariadb` job (`.github/workflows/laravel-tests.yml`) overrides these via real environment variables and catches the divergence — but only after a push.

**Fix:** Run the MariaDB CI job locally when touching any datetime or schema path:
```bash
# quick smoke against the local MariaDB — mirrors the CI env override pattern
DB_CONNECTION=mysql DB_DATABASE=agent_webhook_bridge_dev vendor/bin/phpunit
```
Laravel's Eloquent datetime serialization handles the format for model attributes, but raw `DB::statement` / `DB::insert` calls that hand-craft datetime strings must use `Y-m-d H:i:s.v` (space, milliseconds), not ISO-8601.

**Discovery:** Original Python-era bug (v0.11.x PR #5); the SQLite-vs-MariaDB test-vs-CI split persists in v0.12. CI job comment in `laravel-tests.yml` calls the divergence explicitly.

**Related:** `database/migrations/*` (`TIMESTAMP(3)` columns: `received_at`, `processed_at`), `.github/workflows/laravel-tests.yml` (`phpunit-mariadb` job), `phpunit.xml` (`DB_CONNECTION=sqlite` default).

---

## G-004 — `scope_id` segment-shape regex is load-bearing in two places; they must stay in sync

**Symptom:** A `scope_id` accepted at the receiver is rejected at provisioning (or vice versa), producing confusing 400s on valid-looking scope values.

**Cause:** The `scope_id` format validator (`app/Bridge/Validation/ScopeId.php`) enforces the segment-shape regex (`^[a-zA-Z0-9_-]+(\.[a-zA-Z0-9_-]+)*(/[a-zA-Z0-9_-]+(\.[a-zA-Z0-9_-]+)*)*$`) that rejects `..`, `//`, leading/trailing `/`. This is also the path-traversal defense: the scope becomes a filename component when loading the per-scope secret from disk (`SecretPath::for()`). If the provisioner's copy of the pattern ever drifts from the receiver's copy, provisioning can write a secret file the receiver will never load (because the receiver rejects the scope before even looking up the file).

**Fix:** `ScopeId::PATTERN` is the single source of truth for the PHP side. `ScopeId.php` docstring notes the pattern must stay character-for-character identical to the Python provisioner's `SCOPE_ID_PATTERN` (if that side is still active). Any regex change must update both halves.

**Discovery:** PR #8 senior-dev review (path-traversal variant; original G-001 regex-delimiter bug was a pre-cursor).

**Related:** `app/Bridge/Validation/ScopeId.php`, `app/Bridge/Support/SecretPath.php`, `app/Http/Middleware/VerifyHmacSignature.php`.

---

## G-007 — GitHub `events: ["*"]` wildcard is undocumented

**Symptom:** Bridge subscribes via the GitHub API with `events: ["*"]` and it works — for now.

**Cause:** GitHub's REST API documentation for "Create a repository webhook" doesn't list `"*"` as a valid value in `events`. The default (when omitted) is `["push"]`. The wildcard works in practice but isn't in the spec; GitHub could break it without notice.

**Fix:** The provisioner (`app/Bridge/Provision/KanbanProvisionClient.php` sends `event_filter` to the kanban-board API; GitHub webhooks are configured in repo settings rather than via the bridge's `bridge:provision` command, so operators must enumerate events explicitly in repo settings when GitHub subscriptions go live. `SubscriptionConfig` parses `event_filter` from the per-agent YAML and treats an empty list as "no filter" — do not leave it empty for GitHub scopes.

**Discovery:** PR #8 senior-dev review.

**Related:** `app/Bridge/Support/SubscriptionConfig.php` (`event_filter`), `app/Bridge/Provision/WebhookProvisioner.php`.

---

## G-008 — Filesystem collision when one scope is a prefix of another

**Latent flaw:** The on-disk secret-file path is derived from `scope_id` via `SecretPath::for()`. For scope `foo`, the path is `<secret_dir>/<provider>/webhook-secret-scope-foo`. For scope `foo/bar`, without encoding, the path would be `<secret_dir>/<provider>/webhook-secret-scope-foo/bar` — requiring `webhook-secret-scope-foo` to be both a file and a parent directory simultaneously. The second `mkdir` would fail, and the first file would block the nested scope.

**Fix:** `SecretPath::for()` URL-encodes `/` to `%2F` in the scope component:
```php
str_replace('/', '%2F', $scopeId)
```
Scope `foo` → `...-foo`; scope `foo/bar` → `...-foo%2Fbar`. Independent files, no collision.

**Related:** `app/Bridge/Support/SecretPath.php`, `app/Bridge/Validation/ScopeId.php`.

---

## ~~G-010~~ — Receiver test rig leaves a stale `receiver/config.php` if interrupted mid-test

**Resolved in the pre-v0.12 era (Tier 3c); the Python receiver is gone in v0.12.** Historical only.

---

## G-011 — HMAC must be computed over `getContent()`, not re-encoded request data

**Symptom:** HMAC verification passes in isolation but fails (or vice versa) when the body passes through `$request->json()` or `$request->all()` before the signature check. Manifests as a constant-time-compare mismatch producing `sig_mismatch` 401s on valid webhooks.

**Cause:** The HMAC is computed by the upstream (kanban-board / GitHub) over the **raw bytes** of the HTTP body. In Laravel, `$request->getContent()` returns those raw bytes. `$request->json()` decodes then re-encodes: key ordering, whitespace, and Unicode escaping can all differ from the original. `$request->all()` merges query string and parsed body — definitely not the raw body. Any re-encoding breaks the HMAC.

**Fix:** `VerifyHmacSignature.php` captures the body as `$body = $request->getContent()` (line 51) and passes it both to `verifySignature()` and stashes it as `bridge.body` on the request for downstream use. The controller and adapters receive the raw body from `$request->attributes->get('bridge.body')`, not by re-reading the request. Never call `$request->getContent()` a second time downstream (it should still work because Laravel buffers it, but `bridge.body` is the canonical post-verification source).

**Related:** `app/Http/Middleware/VerifyHmacSignature.php` (`$body = $request->getContent()` at line 51; stashed as `bridge.body` at line 60), `app/Bridge/Adapters/AbstractWebhookAdapter.php` `verifySignature()`.

---

## G-012 — `dedupCreate`: catch `UniqueConstraintViolationException`, then refetch — not re-insert

**Symptom:** Under concurrent redelivery of the same `delivery_id` (kanban-board retries fire while the first request is still in-flight), a naïve `firstOrCreate` or `updateOrCreate` can race: both requests check "exists?" → both see "no" → both attempt INSERT → one succeeds, one gets a unique-violation exception → the loser throws unhandled.

**Cause:** Laravel's `firstOrCreate()` is not atomic. It issues a SELECT then an INSERT. Between the two, a concurrent request can INSERT the same unique row — causing the second INSERT to raise `UniqueConstraintViolationException`. This is the standard INSERT-then-catch pattern for at-least-once dedup gates.

**Fix:** `DispatchService::dedupCreate()` (line 186–198) does:
```php
try {
    return $class::create($create);
} catch (UniqueConstraintViolationException) {
    return $class::query()->where($find)->firstOrFail();
}
```
The unique key on `webhook_events.delivery_id` (and composite key on `agent_dispatches.(webhook_event_id, agent_name)`) makes this safe. The loser in the race refetches the winner's row and continues processing — idempotent.

**Do not** wrap this in a DB transaction: dispatch involves network I/O (channel_push, etc.) and a transaction rollback can't un-send a POST (DL-001).

**Related:** `app/Bridge/Dispatch/DispatchService.php` `dedupCreate()`, `database/migrations/..._create_webhook_events_table.php` (UNIQUE on `delivery_id`), `database/migrations/..._create_agent_dispatches_table.php`.

---

## G-013 — `phpunit.xml` sets `BRIDGE_INSTALL_SUFFIX=""` to neutralize the install-suffix crosstalk guard in tests

**Symptom:** Running the test suite from a deployed worktree (one with `BRIDGE_INSTALL_SUFFIX=-dev` or `-prod` in `.env`) fires the InstallGuard against the SQLite `:memory:` test DB, because the DB name `:memory:` doesn't contain `_dev` or `_prod`. Every test that triggers `DispatchService::dispatch()` or `bridge:check` fails with a DSN-safety error unrelated to the test's intent.

**Cause:** `phpunit.xml`'s `<env>` stanzas use the non-forced form, so a real environment variable wins over the XML value. A `.env` file in the project root is loaded by Laravel's bootstrap, setting `BRIDGE_INSTALL_SUFFIX=-dev` before `phpunit.xml`'s override runs. The `InstallGuard::dsnCrosstalk()` check fires on `_dev`/`_prod` suffixes.

**Fix:** `phpunit.xml` includes:
```xml
<!-- Neutralize the install-suffix crosstalk guard in tests, so a
     deployed worktree's .env (BRIDGE_INSTALL_SUFFIX=-dev/-prod) can't
     fire it against the sqlite :memory: test DB. InstallGuardTest sets
     the suffix explicitly per-test to exercise the guard. -->
<env name="BRIDGE_INSTALL_SUFFIX" value=""/>
```
`InstallGuardTest` sets the suffix explicitly per test case via `Config::set('bridge.install_suffix', ...)` and cleans up after itself — this is the only test that exercises the guard, not every test that writes to the DB.

If the guard fires unexpectedly in a test: verify `phpunit.xml` has this stanza and hasn't been edited away. If running tests via a wrapper that forces `.env` before phpunit starts, add `BRIDGE_INSTALL_SUFFIX=` to the wrapper invocation.

**Related:** `phpunit.xml` (line 33), `app/Bridge/Support/InstallGuard.php`, `tests/Feature/Config/InstallGuardTest.php`.

---

## G-014 — `delivery_id` over 64 chars silently truncates in MariaDB, creating a false dedup collision

**Symptom:** Two distinct webhook deliveries with `delivery_id` values that share the same first 64 characters are deduplicated as if they were the same event. The second delivery is treated as a retry and re-uses the first event's record.

**Cause:** `webhook_events.delivery_id` is `VARCHAR(64)`. MariaDB in strict mode rejects values that exceed the column width, but in non-strict mode it silently truncates. Two `delivery_id` values that differ only past position 64 — a real possibility for UUID-like ids with a shared prefix format — would hash to the same 64-char prefix and collide on the UNIQUE constraint, causing the dedup gate to fire when it shouldn't.

**Fix:** `AbstractWebhookAdapter::assertDeliveryIdLength()` (line 38–42) rejects any `delivery_id` longer than 64 chars with `InvalidEnvelopeException('delivery_id_too_long')`, which maps to a deterministic 400 at the controller level. This fires before the DB write, making the over-length case an explicit parse error rather than a silent data-corruption.

**Related:** `app/Bridge/Adapters/AbstractWebhookAdapter.php` `assertDeliveryIdLength()`, `database/migrations/..._create_webhook_events_table.php` (the column comment explains the rationale).

---

## G-015 — GitHub actor is `sender.id` (numeric), not `sender.login` — and `github_login` is display-only

**Symptom:** A GitHub agent configured with only `github_login` (no `github_user_id`) in its `<agent>.yml` `identity` block isn't recognized (`Actor.name` is null, echoes leak) — most visibly after the account's username is renamed.

**Cause:** GitHub usernames are renameable; the numeric account id (`sender.id`) is immutable. v0.12.0 (DL-002) keyed GitHub recognition on `sender.login`, so a rename staled every config that named the login. The fix keys recognition on the immutable id: `GitHubAdapter` puts `sender.id` in `actor_id`, the registry matches on `github_user_id`, and `github_login` is a **display-only label** — never a matching key. Matching is provider-aware, so a kanban `user_id` and a github `sender.id` that are the same integer never cross-match. (Since DL-007 there is no `agents.json` / `schema_version`: identity lives in each agent's YAML `identity` block, and a shared account is declared once in `shared-identities.json`.)

**Fix:** Put `github_user_id` (the numeric account id) in the agent's `<agent>.yml` `identity` block, not just `github_login`. For an account shared by several agents, declare it once in `shared-identities.json`. Self ids are auto-seeded into echo suppression (DL-007), so a rename never breaks self-echo. A stale `github_login` label logs a one-line drift warning naming the current login — update the label, recognition is unaffected.

**Discovery:** FR (immutable-identity hardening), 2026-05-30 — same problem class as the earlier shared-login collision-bypass work, but the identifier-durability dimension.

**Related:** `app/Bridge/Adapters/GitHubAdapter.php` (`sender.id`), `app/Bridge/Support/AgentRegistry.php` (`actorFromEvent(string $provider, …)`, `shared_identities`, drift warning) + `RegisteredAgent.php` + `SharedIdentity.php`, `CLAUDE_DECISIONS.md` DL-002.

---

## G-016 — A group/world-readable secret fails closed: receiver 500 `secret_perms_insecure`, `bridge:provision` FAIL

**Symptom:** The receiver returns **500 `secret_perms_insecure`** for a scope whose HMAC secret exists and is otherwise correct; or `bridge:provision` prints `FAIL — … group/world-readable` and exits non-zero for a provider whose token file is present. Looks like a broken secret/token; the file content is fine — the **perms** are the problem.

**Cause:** DL-010 enforces the SSH-style `mode & 0o077 == 0` gate at point-of-use on all three secret readers (HMAC secret, API token, channel token) via `SecretFile::isInsecure`. A secret left `0644`/`0640` (a `cp`/`umask` accident — the provisioner writes `0600`, but a hand-placed file may not be) is treated as no boundary at all and refused fail-closed: the receiver 500s (kanban-board holds + redelivers), provisioning fails the command rather than using a co-tenant-readable token.

**Fix:** `chmod 600` the secret/token file. `php artisan bridge:check` warns on insecure perms for all three secret kinds at preflight, naming the runtime consequence — run it after placing any secret by hand. The check reads live perms (`clearstatcache`), so it reflects the current mode.

**Discovery:** 2026-05-31 architecture review item B-2 (DL-010) — extends DL-008's channel-token perms posture to the two higher-value secrets.

**Related:** `app/Bridge/Support/SecretFile.php` (`isInsecure`/`read`), `app/Http/Middleware/VerifyHmacSignature.php` (`loadSecret`), `app/Bridge/Support/ChannelToken.php`, `app/Console/Commands/Bridge/{Provision,Check}Command.php`, `CLAUDE_DECISIONS.md` DL-010 / DL-008.

---

## G-017 — An operator's `BRIDGE_INBOX_LAYOUT=per-agent` shell export turns the suite red on their host while CI stays green

**Symptom:** A clean `v0.24.0` checkout reports ~26 failures (22 fail + 4 error), all in `tests/Feature/Dispatch/*` + the inbox console tests, sharing one symptom — `IntentLog::stage()` persists 0 readable lines (`Failed asserting that actual size 0 matches expected size 1`, `Undefined array key 0`). CI is green; the running bridge is fine. **Not reproducible** on a maintainer host with the same code/deps/OS/locale — until you export `BRIDGE_INBOX_LAYOUT=per-agent`.

**Cause:** A real per-agent deployment exports `BRIDGE_INBOX_LAYOUT=per-agent` (and may export `BRIDGE_STATE_DIR`) in its shell. Laravel's Dotenv does **not** override an already-set shell var, so the export reaches `config('bridge.inbox_layout')`. Under `per-agent`, `IntentLog::stage()` writes to the per-agent file (`inbox-<agent>.jsonl`), but the tests read the **shared** `inbox.jsonl` — so they see 0 lines. The tests isolate `bridge.config_dir` in `setUp` but not the layout, so the operator's env bleeds in. Same class as G-013 (ambient env wins over the suite).

**Fix:** A `phpunit.xml` `<env … force="true">` does **NOT** fix it — `env()` reads the `getenv()` layer where the shell export lives, which phpunit's `<env>` doesn't reach (verified: forcing `BRIDGE_INBOX_LAYOUT=shared` in `phpunit.xml` still failed). The authoritative fix is a **runtime `config()` pin in the base `Tests\TestCase::setUp()`**:
```php
config(['bridge.inbox_layout' => 'shared', 'bridge.state_dir' => null]);
```
Runtime `config()` overrides the env-derived value, and tests that exercise a non-shared layout set `bridge.inbox_layout` *after* `parent::setUp()`, so they still win. Validated: 340/340 with `BRIDGE_INBOX_LAYOUT=per-agent BRIDGE_STATE_DIR=… BRIDGE_DIR=…` all exported.

**Discovery:** Integrator bug report on a clean `v0.24.0` install; root-caused via a probe (`probe-intentlog.php`) that printed `inbox_layout = per-agent` on their host vs `shared` on the maintainer's, then reproduced by exporting the var (card #2145).

**Related:** `tests/TestCase.php`, `app/Bridge/Dispatch/IntentLog.php`, `app/Bridge/Support/BridgePaths.php` (`stateDir`/`inboxLayout`/`targetPaths`), G-013 (sibling: `phpunit.xml` `BRIDGE_INSTALL_SUFFIX`).

---

## G-018 — `401 scope_mismatch` means the event body's scope source ≠ the `?b=<scope>` query param (per-adapter)

**Symptom:** A hand-crafted / smoke-test signed delivery returns `401 scope_mismatch`. The HMAC verified fine; the body is rejected afterwards on the payload-scope vs URL-scope double-check.

**Cause:** The receiver re-derives the scope **from the body** via the provider adapter and requires it to equal `?b=<scope>` (`WebhookController.php:16,50` — the payload-scope vs URL-scope check returns 401). The body-scope source is **per-adapter**: GitHub = `repository.full_name` (`GitHubAdapter.php:48` — `nestedScalar(..., 'repository', 'full_name') ?? ''`), kanban = `board_id`. A test payload that omits that source yields scope `''` → mismatch → 401. This is intentional — it stops a delivery signed for scope A from being processed as scope B.

**Fix:** make the body's scope source equal `?b=`. For a GitHub smoke test, include `"repository":{"full_name":"<org/repo>"}` and POST to `?b=<org/repo>`. Full signed recipe in [`CLAUDE_DEPLOYMENT.md`](CLAUDE_DEPLOYMENT.md) § Smoke-test the receiver with a signed delivery.

**Discovery:** operator doc feedback (v0.21→v0.24 update) — a reverse-engineered smoke test that omitted `repository` hit `scope_mismatch` with no troubleshooting entry mapping the code to a cause.

**Related:** `app/Http/Controllers/Webhook/WebhookController.php:50`, `app/Bridge/Adapters/GitHubAdapter.php:48`, `app/Http/Middleware/VerifyHmacSignature.php:49` (`?b=`), G-004 (scope_id *format* — distinct from this scope *match*).

---

## G-019 — `bridge:prune` PARTIALLY APPLIES on an invalid second window: it deletes, then fails

**Symptom:** `bridge:prune --older-than=30d --null-payloads-older-than=bogus` prints the range error and exits `FAILURE` — but 30 days of `webhook_events` (and their cascading `agent_dispatches`) are **already gone**. The non-zero exit reads like "nothing happened". It isn't.

**Cause:** the two legs are parsed and executed **interleaved**, not validated up front: parse leg 1 → **DELETE** → parse leg 2 → fail (`PruneCommand::handleGuarded()`). So an invalid *second* window cannot prevent the *first* leg's destructive work — it has already committed.

**Fix:** validate both windows yourself before invoking, or run `--dry-run` first. Each leg is independently valid, so the safe habit is one leg per invocation.

**Deliberately not "fixed":** this is pre-existing behavior, **preserved on purpose** by the DL-199 service extraction (which rewrote it once to parse-both-first and thereby *silently changed the destructive side effect* — same exit code, different data outcome, and no test caught it; the interleaving is now pinned by `test_prune_runs_the_first_leg_before_parsing_the_second`). Changing it is a behavior change to a destructive command and needs its own decision (canon #6 / the always-ask gate), not a refactor's side effect. It is a **wart, still open**.

**Note:** the DL-199 retention *gate* is not affected — it validates the whole config through `RetentionConfig` **before** touching anything, and an invalid window there prunes nothing at all.

**Discovery:** caught during the DL-199 extraction (2026-07-15) by re-reading the original before trusting the rewrite; the combination had no test, so both versions went green.

**Related:** `app/Console/Commands/Bridge/PruneCommand.php` (the interleaved legs), `app/Bridge/Retention/RetentionConfig.php` (the gate's validate-first posture), `CLAUDE_DECISIONS.md` DL-012 + DL-199.

---

## How to add an entry

1. New `G-NNN` (next available number).
2. Title format: `G-NNN — short symptom or condition`
3. Four sections: **Symptom**, **Cause**, **Fix**, **Discovery** (PR or live-verification context), **Related** (files + DL entries).
4. Cite line numbers when the relevant code is one specific spot.
5. Note whether the issue is fixed or still pending. If pending, link to the follow-up tracking PR / issue.
