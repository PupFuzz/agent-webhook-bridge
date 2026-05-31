# Testing

How tests are organized, what they cover, what the bridge's testing philosophy is.

## Quick reference

```bash
vendor/bin/phpunit                              # full suite (SQLite in-memory)
vendor/bin/phpunit --filter ProvisionTest       # one test class
vendor/bin/phpunit --filter test_happy_path     # one test method by name
vendor/bin/phpunit tests/Feature/Webhook/       # one directory
php artisan test                                # same as phpunit, with pretty output
```

Static analysis and style:

```bash
vendor/bin/phpstan analyse -c phpstan-laravel.neon   # level 7 — app/Bridge only
vendor/bin/pint --test                               # style check (Laravel preset; --test = dry-run)
vendor/bin/pint                                      # fix in place
```

## Test taxonomy

| Directory | What it covers | Style |
|---|---|---|
| `tests/Unit/Adapters/` | `KanbanAdapter`, `GitHubAdapter` — envelope parsing + HMAC shape | Unit; pure PHP, no DB, no HTTP, extends `PHPUnit\Framework\TestCase` |
| `tests/Unit/Classifiers/` | `InboxOnlyClassifier`, `EventDrivenClassifier` — classify output + intent shapes | Unit; pure PHP |
| `tests/Unit/Dispatch/` | Plain value objects — `Intent`, `ReactionTarget`, `Actor`, `ClassifyResult` | Unit; pure PHP |
| `tests/Unit/Validation/` | Format validators (`ProviderName`, `ScopeId`, `SocketPath`) | Unit; pure PHP |
| `tests/Feature/Webhook/` | End-to-end HTTP status contract through the real middleware + adapter stack | Feature; `RefreshDatabase`; uses `$this->call()` |
| `tests/Feature/Dispatch/` | `DispatchService` + `AgentRegistry` + echo/signal logic | Feature; `RefreshDatabase`; tmp config dir |
| `tests/Feature/Config/` | `SubscriptionRegistry`, `AgentConfig`, `InstallGuard`, `ClassifierResolver` | Feature; tmp filesystem or env override |
| `tests/Feature/Models/` | `WebhookEvent::dedupCreate`, `AgentDispatch` ledger | Feature; `RefreshDatabase` |
| `tests/Feature/Provision/` | `bridge:provision` Artisan command end-to-end | Feature; `Http::fake`; tmp config + secret dir |
| `tests/Feature/Handlers/` | `ChannelPushHandler`, `SpawnDetachedHandler`, `LogIntentHandler`, `RegistryAppendHandler` | Feature; `Http::fake` for HTTP-backed handlers |
| `tests/Feature/Console/` | `bridge:check`, `bridge:inbox`, `bridge:inspect`, `bridge:replay`, `bridge:stats` | Feature; `RefreshDatabase` + tmp dirs |

Run `vendor/bin/phpunit --list-tests 2>/dev/null | wc -l` for a live count. The number isn't quoted in any markdown file in this repo because it drifts every PR.

## Two test categories by purpose

### 1. Unit tests (extends `PHPUnit\Framework\TestCase`)

No database, no HTTP, no Laravel service container. Assert on pure logic:
- Public function return values and side effects
- Edge cases (null input, missing keys, boundary conditions)
- Format validation (valid vs. rejected shapes)

Pattern from `tests/Unit/Adapters/KanbanAdapterTest.php`:

```php
public function test_parses_required_fields(): void
{
    $event = (new KanbanAdapter)->parse($this->request($this->body()), $this->body());

    $this->assertSame('5', $event->scopeId);       // board_id stringified
    $this->assertSame('137', $event->actorId);     // user_id stringified
}

public function test_missing_required_field_throws(): void
{
    $body = (string) json_encode(['event' => 'x', 'delivery_id' => 'd1']);  // no board_id

    $this->expectException(InvalidEnvelopeException::class);
    (new KanbanAdapter)->parse($this->request($body), $body);
}
```

### 2. Feature tests (extends `Tests\TestCase`)

Full Laravel application context. Routes are real, middleware runs, the database is real (SQLite `:memory:` by default). Use `RefreshDatabase` when the test touches the DB.

**HTTP testing — no separate PHP server.** The old Python suite booted `php -S` in the background and posted HTTP requests from outside the process. Feature tests hit the real route in-process via `$this->postJson()` or `$this->call()`:

```php
use Illuminate\Foundation\Testing\RefreshDatabase;

class WebhookReceiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_kanban_delivery_is_accepted(): void
    {
        $body = $this->kanbanBody();

        $response = $this->postWebhook('/webhooks/kanban?b=5', $body, [
            'X-Kanban-Signature' => $this->sign($body, $this->kanbanSecret),
        ]);

        $response->assertStatus(200)->assertSee('ok');
    }
}
```

`$this->call()` is used rather than `$this->postJson()` when the raw body must be an exact byte string (the HMAC middleware signs the raw body, not a re-serialized array).

**Outbound HTTP — `Http::fake()`.** The bridge makes outbound HTTP for provisioning (`KanbanProvisionClient`) and for `ChannelPushHandler` when a URL transport is used. Tests use Laravel's `Http::fake()` facade — no real network call is made:

```php
Http::fake(fn (Request $r) => $r->method() === 'GET'
    ? Http::response(['data' => []])
    : Http::response(['data' => ['id' => 7]]));

$this->artisan('bridge:provision')->assertExitCode(0);

Http::assertSent(fn (Request $r) => $r->method() === 'POST'
    && str_contains($r->url(), '/boards/5/webhooks.json')
    && $r['url'] === $this->receiverUrl);
```

**Temporary config dirs.** Tests that exercise config loading or filesystem writes create a `sys_get_temp_dir().'/prefix-'.uniqid()` directory in `setUp()` and delete it in `tearDown()`. They point the bridge at it via `config(['bridge.config_dir' => $this->dir, 'bridge.secret_dir' => $this->dir])`. This keeps tests hermetic and avoids leaking into the real install's config paths.

**Fake secrets in test fixtures.** Any test file that writes a literal HMAC secret, token, or password value in source adds a `// gitleaks:allow — test fixture` annotation on that line to suppress scanner false positives:

```php
File::put($this->dir.'/kanban.token', 'secret-token'); // gitleaks:allow — test fixture
File::put(SecretPath::for($this->dir, 'kanban', '5'), 'existing-secret-value'); // gitleaks:allow — test fixture
```

## Fixtures (test helper classes)

### `tests/Fixtures/LogIntentClassifier.php`

Minimal classifier that emits one `log_intent` target. Used to test the happy dispatch path (event stored → intent staged → handler ran).

### `tests/Fixtures/ThrowingClassifier.php`

Classifier that always throws. Used to verify case-A failure treatment: classify exception records the event + leaves the dispatch row errored but does not propagate (no 5xx).

### `tests/Fixtures/UnknownHandlerClassifier.php`

Classifier that emits a target naming a handler that doesn't exist. Used to verify case-C failure treatment: handler resolution failure marks the dispatch done-with-note (intent was staged first, per B-before-C ordering).

## Database configuration

`phpunit.xml` sets the test environment unconditionally:

```xml
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
<!-- Neutralize the install-suffix crosstalk guard in tests, so a
     deployed worktree's .env (BRIDGE_INSTALL_SUFFIX=-dev/-prod) can't
     fire it against the sqlite :memory: test DB. InstallGuardTest sets
     the suffix explicitly per-test to exercise the guard. -->
<env name="BRIDGE_INSTALL_SUFFIX" value=""/>
```

`BRIDGE_INSTALL_SUFFIX=""` is not decoration. The install-suffix crosstalk guard fires when the suffix in the environment doesn't match the DB name. A deployed worktree's `.env` may have `BRIDGE_INSTALL_SUFFIX=-dev`, which would cause the guard to throw against the `:memory:` test database. The empty override neutralizes it. `InstallGuardTest` sets the suffix explicitly per-test to exercise the guard logic directly.

## CI

`.github/workflows/laravel-tests.yml` — the `Laravel Tests` workflow — runs **three checks in two jobs**:

**Job 1 — `PHPUnit + Pint + PHPStan (PHP 8.3, SQLite)`:**
- Push to `main`/`dev`; pull requests to `main`/`dev`
- PHP 8.3, `pdo_sqlite` + `pdo_mysql` extensions installed
- Pint style check, PHPStan level 7 on `app/Bridge`, then PHPUnit against SQLite `:memory:`

**Job 2 — `PHPUnit (MariaDB ${{ matrix.mariadb }})` (matrix: `["10.6", "11"]`):**
- Spins up a `mariadb:<version>` service container matching the production driver versions
- Overrides `phpunit.xml`'s SQLite defaults via real environment variables (`DB_CONNECTION=mysql`, `DB_HOST`, etc.)
- Runs the full PHPUnit suite against the live MariaDB — no subset, the same suite

Tests must pass on SQLite (Job 1) **and** both MariaDB matrix legs (Job 2) before merge. The lesson from the Python-era `test_db_mariadb.py` incident applies here: a local SQLite-only `vendor/bin/phpunit` run passing does not guarantee CI green when a MariaDB job exists. Driver-specific behavior (transaction semantics, `UNIQUE` constraint timing, `JSON_VALID` enforcement, timestamp precision) only surfaces under the real engine.

### Running MariaDB tests locally

```bash
docker run -d --name bridge-test-mariadb \
  -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=agent_webhook_bridge_ci \
  -e MARIADB_USER=ci_user -e MARIADB_PASSWORD=ci_user_password \
  -p 3306:3306 mariadb:10.6
# wait ~10s for the healthcheck
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
  DB_DATABASE=agent_webhook_bridge_ci DB_USERNAME=ci_user DB_PASSWORD=ci_user_password \
  vendor/bin/phpunit
docker rm -f bridge-test-mariadb
```

## What to test where

| Adding... | Test it in... |
|---|---|
| A new `WebhookAdapter` | `tests/Unit/Adapters/<Provider>AdapterTest.php` (parsing + HMAC shape) + `tests/Feature/Webhook/WebhookReceiveTest.php` (end-to-end status codes) |
| A new classifier | `tests/Unit/Classifiers/<Name>ClassifierTest.php` (classify output shapes) |
| A new handler | `tests/Feature/Handlers/<Name>HandlerTest.php`; use `Http::fake` for outbound HTTP |
| A new `bridge:*` Artisan command | `tests/Feature/Console/BridgeCommandsTest.php` (or a dedicated file); use `$this->artisan(...)` |
| A new format validator | `tests/Unit/Validation/ValidatorsTest.php` |
| A new config field | `tests/Feature/Config/AgentConfigTest.php` |
| A bug fix | a regression test that fails before the fix, passes after. Cite the bug in the test docstring. |
| A security-sensitive change | additional regression tests for the attack surface (e.g. `WebhookReceiveTest` covers path-traversal scope, empty secret, relative secret_dir) |

## Anti-patterns to avoid

- **Tests that mock `DispatchService` from `WebhookDispatchTest`.** The dispatch loop's interaction with the DB and handlers IS what's being tested. Use `RefreshDatabase` + real tmp config dirs.
- **Tests that don't fail before a fix.** A regression test that passes at commit time doesn't catch the regression at merge time. Verify the fix-then-revert cycle locally.
- **Tests that depend on test ordering.** Each test gets a fresh in-memory SQLite and a fresh `uniqid()` tmp dir. Don't write to `~/.config/...` from tests (would leak across runs and into real installs).
- **Tests for "trivial" code.** A 3-line getter with type hints doesn't need a test. Test substantive behavior; trust the type system for boilerplate.
- **Using `$this->postJson()` when raw-body integrity matters.** `postJson()` re-encodes the array; the HMAC middleware signs the original byte string. Use `$this->call('POST', $uri, [], [], [], $server, $rawBody)` for the receiver suite.
- **Claiming MariaDB behavior from SQLite-only runs.** Driver-specific paths (UNIQUE constraint violation class, timestamp formats, transaction CM behavior) only surface under MariaDB. Run Job 2 locally before declaring a DB-touching fix done.

## Adding a test file

1. Match the source structure: `app/Bridge/Foo/Bar.php` → `tests/Unit/Foo/BarTest.php` (pure) or `tests/Feature/Foo/BarTest.php` (needs DB or HTTP).
2. Use `PHPUnit\Framework\TestCase` for unit tests; `Tests\TestCase` for feature tests.
3. Add `use Illuminate\Foundation\Testing\RefreshDatabase;` only when the test touches the database.
4. Verify locally with the full suite (`vendor/bin/phpunit`) before pushing — catches cross-test interactions that focused runs miss.
