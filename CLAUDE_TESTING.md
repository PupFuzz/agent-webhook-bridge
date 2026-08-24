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
| `tests/Unit/Bridge/Check/` | The DL-242 check registry: `CheckRunner`'s own contract, plus **per-check tests under `Checks/`** | **Extends `Tests\TestCase`, not `PHPUnit\Framework\TestCase`** — a `Check` reads Laravel `config()`/env and some fake `Http`/`Cache`. Named Unit because the subject is one class in isolation, driven by a hand-built `CheckContext` |
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

#### Isolation — "touches the DB" includes what a primitive does behind your back

⛔ **The SQLite suite cannot see a missing isolation trait, and CI's MariaDB legs can.** On SQLite `:memory:` a class with no `RefreshDatabase` gets a database with **no tables**, so a write it did not mean to make throws, and any caller that swallows the error leaves the run green. On the MariaDB matrix the same class writes into the **shared** database and every later test in the run sees the rows. That is not hypothetical: `DL-300` made `MappedBoardGuard::boardContext()` persist an audit row, six writeback test classes that had never needed a database began writing one, and the branch was green on SQLite and red on both MariaDB legs — in a test class two directories away from the cause.

So the rule is not *"does my test call the DB?"* but *"can anything my test calls write?"*. A handler, a console command, or a shared writeback primitive can, and its write is not visible in the test body. **`Tests\TestCase` fails any test that leaves rows behind with no isolation trait**, naming the class that did it — but only on a driver where the table exists, so a green SQLite run is not evidence. When you add a test class that exercises a writeback path, add `use RefreshDatabase;`.

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

**Outbound HTTP — `Http::fake()`.** The bridge makes outbound HTTP for provisioning (`KanbanProvisionClient`) and for `ChannelPushHandler` when a URL transport is used. Tests use Laravel's `Http::fake()` facade — a request a stub ANSWERS never leaves the process, and since DL-303 one that no stub answers does not leave it either (see the guard below):

```php
Http::fake(fn (Request $r) => $r->method() === 'GET'
    ? Http::response(['data' => []])
    : Http::response(['data' => ['id' => 7]]));

$this->artisan('bridge:provision')->assertExitCode(0);

Http::assertSent(fn (Request $r) => $r->method() === 'POST'
    && str_contains($r->url(), '/boards/5/webhooks.json')
    && $r['url'] === $this->receiverUrl);
```

**⛔ An unstubbed request is blocked — by `Tests\TestCase`, not by `Http::fake()`.** The distinction is the whole entry. `Http::fake()` does NOT block a request no stub answers: `PendingRequest::buildStubHandler()` invokes every registered callback, and when none of them answers it falls through to the **live** handler (a bare `Http::fake()` or a `'*'` closure answers everything and hides this; the URL-pattern array form, the majority form in this suite, does not). `tests/TestCase.php` closes that by calling `Http::preventStrayRequests()` in `setUp()` (**DL-303**), so an unmatched request raises `StrayRequestException` naming the exact URL instead of going to the network — **and records it, so a caller that swallows the refusal fails the test in `tearDown()` anyway** (next paragraph but one). `tests/Feature/Support/StrayRequestGuardTest.php` is that guard's own witness — delete either call and it reds.

⚑ **What the escape cost, measured on card#7225 and re-measured suite-wide on card#7300.** Adding one outbound read to `board_create_card` left every board-tools test that reaches a success arm issuing a real call to the fixture host, whose connection failure was swallowed by the caller's own fail-soft `catch` — **all 74 tests in the three board-tools suites — `AgentToolsCallTest` + `BoardToolDispatcherTest` + `ToolsCallCommandTest`, counted at `35785c9`, the tip the change branched from; the fourth file in `tests/Feature/AgentTools` is `BoardToolAgentResolverSshTest`, and the directory stands at 96 cases at `ddab33d` — passed while the new read was never once exercised as intended**. The fall-through itself is measured, not inferred: with the guard removed, an unmatched request under the array form raises `ConnectionException: cURL error 6: Could not resolve host: kanban.example.com` from the live handler. Read the number as *the request left the process*, not as a fixed code: it is whatever the live handler returns for the host in hand, and 6 is what a non-resolving fixture host yields. ⛔ **A resolving one is the dangerous case, and this repo had one:** the crosstalk leg of `tests/Feature/Config/InstallGuardTest.php` read `bridge.config_dir` off the `BRIDGE_DIR` a deployed checkout's `.env` sets — `phpunit.xml` overrides neither — so on an operator host it ran `bridge:check` against the REAL install and issued four token-bearing requests to that install's live board and to `api.github.com` on every suite run, while on CI (a fixture `BRIDGE_DIR` that does not exist) the same test exercised nothing of the sort. Same test, two subjects, decided by the host.

⛔ **REFUSING a request is not the same as SURFACING it — which is why there are two mechanisms and not one.** `StrayRequestException` extends `RuntimeException` and `PendingRequest::makePromise()` re-throws it ahead of the `ConnectionException` wrapping — so it reaches the caller, and a caller that degrades softly on `catch (Throwable)` (the correct production posture, e.g. `KanbanMoveCardHandler`'s no-regression guard) swallows it exactly as it swallowed the connection error. **The test then stays GREEN on the degradation arm.** The number is the argument: when the guard was first switched on suite-wide it refused **57 requests across 53 legs, and exactly 2 tests went red.** So `Tests\TestCase` pairs the refusal with a recorder — an `Http::globalMiddleware()` closure that sits OUTSIDE the stub handler, records the refused URL and re-throws it unchanged — and `tearDown()` **fails the test, naming every refused URL it did not declare**. Nothing about the loud path changes: a caller that propagates still propagates. What changes is that a stub is load-bearing again — **drop one and the test reds**, whether or not anything asserts on that request.

The rule below is therefore no longer the *only* thing standing between a stub and silent removal — but it is not replaced, because it answers a question the guard cannot:

- **When a change adds an API call, extend the stub set of every test that reaches it AND assert the new request on the wire (`Http::assertSent`).** The guard says only that *some* stub answered. It cannot say the answer was the right one, that the URL was the one you meant, or that the call happened at all — a wildcard pattern that quietly widens still greens. The wire assertion is what pins the exact request.
- **Do not read a green suite as evidence a call was faked** — and be especially wary where the caller degrades softly. A test registering a catch-all (`Http::fake()` with no arguments, or a `'*'` pattern) has answered every request it will ever make, so **neither the guard nor the recorder can say anything at all about it.** That is a different class and neither mechanism bounds it.

**Deriving the population, if you ever have to.** Never by `grep`: `grep`ping for `Http::fake` cannot bound it, because a class that calls `Http::fake()` and stubs some of its URLs escapes on the others. With the recorder in place, **turn the guard on, run the whole suite, and read the reds** — every refused request fails its OWN test and the failure message names the URL. ⚑ *Its OWN* is what the `finally` in `Tests\TestCase::tearDown()` buys: the report is a THROW, and one that skipped `parent::tearDown()` skipped `RefreshDatabase`'s rollback with it, so the FIRST stray erred every later test in the process before any of them could name a URL — the reds a sample again, sized by run order. `tests/Feature/Support/StrayRequestGuardCascadeTest.php` pins it closed; **DL-303** Decision 1c holds the measurement. ⛔ **That was FALSE before the recorder, and by 96%**: a run reported 2 where the throw site reported 57. If you are ever measuring a tree where the recorder is absent or disabled, use the method **DL-303 Decision 2** records instead — record `<test>\t<uri>` at the point `StrayRequestException` is *constructed*, run the whole suite, and `sort -u` the file — and **run a control first** (drive one request you know is unstubbed and see it appear), because an instrument that records nothing and a clean suite produce the same empty file. Either way the count excludes whatever a test declared with `expectStrayRequest()` and the one allowance below — deliberately stated as a property and not a number, because a number here is the next thing to go stale — and says nothing at all about a catch-all test. ⛔ **A third under-report is narrow and real:** `parent::tearDown()` can itself throw — `Mockery::close()` on an unmet expectation — and the `finally` lets that replace the guard's. Measured: the test is still red, the URL is not in it.

**The one place the guard is stood down, and the bar for a second.** `tests/Feature/Handlers/ChannelPushUdsTest.php` calls `Http::allowStrayRequests(['http://localhost/'])`, because that test's SUBJECT is the live curl/Guzzle Unix-domain-socket transport — a stub would replace the thing under test — and nothing escapes the process on that path: the destination is a socket the test binds and deletes itself, and `http://localhost/` is curl's placeholder authority for a UDS transfer, never a TCP connection. ⛔ **That is the bar: the real transport IS the subject, and the destination is one the test owns.** "This class is noisy" is not the bar — a per-class opt-out re-mints the defect with a note attached, which is why the guard went in suite-wide with the strays fixed rather than exempted. Scope any allowance to the exact URL, never to the class.

**Its weaker sibling, `expectStrayRequest($url)`.** A test whose subject is the refusal ITSELF — currently only the guard's own witness — declares the URL it drives a stray to, so `tearDown()` does not report it. It is not an opt-out: the request is still refused and still never leaves the process, and everything else the test touches is still reported. Same bar, same URL scoping. ⚑ **A declaration expires with the stray it declares** — `tearDown()` fails an otherwise-passing test that declared a URL no refusal matched, because a stale declaration is a live exemption over whatever strays to that URL next. Scoped per-URL, not per-request: it excludes EVERY refusal to that URL, which is why the report counts UNDECLARED refusals and says so.

**Register the whole stub set once.** A second `Http::fake()` does not replace the first — it appends to it, the *first* matching stub wins, and it resets the recorded log that `Http::assertSent()` reads. So calling a setup helper and then "overriding" one URL with a later `Http::fake()` silently leaves the helper's answer in place, and the test stays green. Read [`CLAUDE_GOTCHAS.md`](CLAUDE_GOTCHAS.md) G-020 **before** layering fakes.

**Temporary config dirs.** Tests that exercise config loading or filesystem writes create a `sys_get_temp_dir().'/prefix-'.uniqid()` directory in `setUp()` and delete it in `tearDown()`. They point the bridge at it via `config(['bridge.config_dir' => $this->dir, 'bridge.secret_dir' => $this->dir])`. This keeps tests hermetic and avoids leaking into the real install's config paths.

⛔ **Forgetting that override used to hand the test the OPERATOR'S install.** `config/bridge.php` resolves both keys from `BRIDGE_DIR`, and `phpunit.xml` cannot override it — `<env>` does not reach the `getenv()` layer `env()` reads — so on a deployed checkout the default was the live install's config + secret dir, while on CI (where `BRIDGE_DIR` names a path that does not exist) the same test read nothing. One test was in that state and issued four token-bearing requests to a live board on every suite run (card#7300, DL-303). `Tests\TestCase::setUp()` now defaults both keys to a path that does not exist, so **the default is the CI shape on every host** and a class that forgets the override reads an empty install rather than a real one. Set your own dir AFTER `parent::setUp()`, exactly as before — the default is a floor, not a substitute.

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

## The channel-server version-agreement control (DL-268)

`tests/Feature/Workflows/ChannelServerVersionAgreementTest.php` extracts the
`version-bump-guard` steps' real `run:` blocks out of
`.github/workflows/channel-server-supply-chain.yml` and executes them under `bash` against a
throwaway tree — the same shape as `PrTitleLintTest`, and for the same reason: a
re-implementation of the predicate in PHP would drift from what CI actually runs.

- **Its positive control is real history, not invented JSON.**
  `tests/Fixtures/channel-server-drift-4abe8e3/` holds `examples/channel-servers/`'s manifest +
  lockfile as they stood at `4abe8e3`, the last commit at which card#5232's drift was live
  (manifest `0.8.3`, lock `0.7.1`). The guard is required to reject a state the repo actually
  shipped.
- **The fixtures carry a `.fixture` suffix on purpose.** Dependabot *security* updates detect
  manifests by filename anywhere in the repo, independently of `.github/dependabot.yml`; a file
  named `package-lock.json` under `tests/` would be eligible for an automated bump, and bumping
  it would repair the very drift the control reproduces. The test writes the bytes into a temp
  tree under the real filenames, so the guard reads what it reads in CI.
- **The test asserts the fixture still carries the drift** before using it, so a resynced or
  regenerated fixture reds instead of going green over a guard nothing is testing.
- **Do not reach for `git show` here.** CI checks out at the default depth of 1 and
  `git show 4abe8e3:` returns 128 in a shallow clone — a control read that way passes locally
  and reds in CI.

## The DL collision gate's fixture — a real origin, not one repo (DL-295)

`tests/Feature/Workflows/DlCollisionGateTest.php` extracts the real `run:` block out of
`.github/workflows/dl-collision-gate.yml` and drives it under `bash`, the same shape as
`ChangelogGateTest`. What is different is the fixture: the step's second assertion reads
`CLAUDE_DECISIONS.md` at the **live tip of the target branch**, which it fetches, so the fixture is
an upstream repository the work tree is *cloned* from and the "the target branch moved under this
PR" cases advance that upstream for real. A single repo with no `origin` would not exercise the
fetch at all, and a pre-computed file would assert the fixture.

- **Every case plants a defect or pins a negative.** The gate is vacuous on this repo's actual
  tree — no duplicate DL exists — so a case that merely ran it would witness nothing.
- **Two cases pin the gate's stated BOUND, not its catch:** two concurrently-open PRs minting one
  number both pass, and the second reds only after the first has merged *and* it re-runs. They
  exist so the claim cannot quietly widen into "no duplicate DL can be minted".
- **One case builds the CI PAIRING for real, because every other case only approximates it.**
  `BASE` is the PR's `base.sha` — a snapshot of the base-branch **tip**, NOT the merge base — while
  the tree the step reads as head is `github.sha`, the merge of the PR head into that same
  `base.sha`. "Present at head, absent at `BASE`" means "this PR minted it" only because those two
  are one snapshot; the other cases pair a branch tip with the commit it was cut from, which equals
  CI only while the branch is not behind the target. That case therefore builds a real merge commit
  (two parents, asserted) and runs the step twice: with `BASE` at the base tip it passes, and with
  `BASE` at the **fork point** — the control, and what a `merge-base` against the PR branch returns
  — it reds, naming the target branch's own post-fork entry as if this PR had minted it. The
  invariant itself is owned by the workflow comment, which also records why rewriting the step to
  derive its base with `git merge-base` would leave every case green while breaking the contract.
- The step calls the real `bin/decision-log.py`, copied into the work tree at the repo-relative
  path the step names, so a change to the predicate reds here as well as in
  `bin/test_decision_log.py`.

## The channel-server live-state sandbox (DL-269)

`examples/channel-servers/tests/` spawns the REAL channel server, and the server's
`markerPath()` falls back to the ambient environment
(`${SOCKET_PATH}.FAILED` under `unix`, else `${XDG_RUNTIME_DIR || os.tmpdir()}/…FAILED`).
A seat exports `BRIDGE_CHANNEL_SOCKET` pointing at its LIVE socket, so a child spawned with a
plain `{...process.env}` binds it, gets `EADDRINUSE`, and writes a `.FAILED` deafness marker
beside a HEALTHY socket — the exact false signal the marker mechanism exists to make
trustworthy. That is measured history, not a hypothetical (DL-237(e)).

- **Every test file in that directory imports `./live-state-guard.mjs` first.** The guard
  neutralises all four channel-addressing inputs at import — before any case runs — and
  registers an `after()` hook that snapshots the seat's real `$XDG_RUNTIME_DIR` and FAILS the
  run if it moved. Add the import to any new file in that directory; per-test scrubbing does
  not close the gap, because nothing notices a test that forgets it.
- **The assertion is the point, not the redirect.** The two pre-existing suites already
  scrubbed their child env — one by denylist, one by allowlist — and both were clean when
  measured. Neither could tell you when it stopped being clean.
- **`live-state-guard.test.mjs` is its positive control**, including the overwrite-in-place
  case: a name-set compare reports a run clean when a false marker is written OVER a stale
  one, which is exactly the state a previously-deaf seat is in.
- **Snapshots record `(mode, size, mtimeNs)`, via `lstat`** — not the name set, and not
  following symlinks.
- The equivalent python-side contract lives in `bin/test_check_channel_snapshot.py`'s
  `setUpModule`/`tearDownModule`. The two are separate per-language authorities; they must
  move together when the contract changes.

## Database configuration

`phpunit.xml` pins the test environment — but *how strongly* differs per key, and the
difference is deliberate:

```xml
<!-- Overridable from the environment on purpose: the MariaDB matrix. -->
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
<!-- Pinned against an ambient value, which takes BOTH entries. -->
<env name="BRIDGE_INSTALL_SUFFIX" value="" force="true"/>
<server name="BRIDGE_INSTALL_SUFFIX" value=""/>
```

⛔ **`force="true"` alone does not make a pin win against an exported variable** (card#7474).
PHPUnit's `PhpHandler::handleEnvVariables()` honors `force` by writing `putenv()` and `$_ENV`,
and never touches `$_SERVER` — where an exported variable lands under this build's
`variables_order=GPCS`. Laravel resolves `env()` through Dotenv's repository, whose
`DEFAULT_ADAPTERS` are `ServerConstAdapter` **then** `EnvConstAdapter`, so `$_SERVER` is read
first and the forced pin loses. Measured, with `REDIS_DB=9` exported against a forced pin of
`13`: `getenv()='13'`, `$_ENV='13'`, `$_SERVER='9'`, `env()='9'`, `config()='9'`. A `<server>`
entry — written unconditionally by `handleServerVariables()` — is what closes it, and with the
pair in place the same run reads `13` at every layer. This is the mechanism behind G-017's
older finding that forcing a value here did not fix an operator's export.

So the two entry kinds buy different things: `<env … force="true">` is what **child processes**
and `getenv()` readers inherit; `<server>` is what `env()`/`config()` resolve **in this
process**. A `.env` defeats neither — Laravel's Dotenv repository is immutable and phpunit has
already written by the time bootstrap loads the file, so a `.env` value loses to an unforced
pin too (measured; it is *not* the vector G-013 attributes it to).

`Tests\Unit\PhpunitConfigPinTest` guards the pairing: two copies of one value, with `$_SERVER`
read first, means a value edited on the `<env>` line alone would be silently ignored.

`BRIDGE_INSTALL_SUFFIX=""` is not decoration. The install-suffix crosstalk guard fires when the suffix in the environment doesn't match the DB name. A deployed worktree's `.env` may have `BRIDGE_INSTALL_SUFFIX=-dev`, which would cause the guard to throw against the `:memory:` test database. The empty override neutralizes it. `InstallGuardTest` sets the suffix explicitly per-test to exercise the guard logic directly.

## CI

`.github/workflows/laravel-tests.yml` — the `Laravel Tests` workflow — runs **four checks in two jobs**:

**Job 1 — `PHPUnit + Pint + PHPStan (SQLite)`:**
- Push to `main`/`dev`; pull requests to `main`/`dev`
- Runs on **PHP 8.5** (via the `setup-app` composite action), `pdo_sqlite` + `pdo_mysql` extensions installed. The job **label** still reads "PHP 8.3" — it's a required-status-check identifier pinned in `dev`/`main` branch protection, so renaming it must be done together with a `gh api` branch-protection contexts update (see DL-040), not in a routine PR.
- Pint style check, PHPStan level 7 on `app/Bridge` **plus `app/Console/Commands/Bridge/CheckCommand.php`** (the finding renderer — added by DL-238 so an unhandled `Severity` case is a CI error, not a runtime `UnhandledMatchError`; the rest of `app/Console` is still unanalysed), the doc-sync gate (`bin/check-doc-refs.php` — references resolve, no line-number citations, no unbounded coverage claims), then PHPUnit against SQLite `:memory:`

**Job 2 — `PHPUnit (MariaDB ${{ matrix.mariadb }})` (matrix: `["10.6", "11"]`):**
- Spins up a `mariadb:<version>` service container matching the production driver versions
- Overrides `phpunit.xml`'s SQLite defaults via real environment variables (`DB_CONNECTION=mysql`, `DB_HOST`, etc.)
- Runs the full PHPUnit suite against the live MariaDB — no subset, the same suite

The wait-for-CI discipline before self-merge is owned by [`CLAUDE.md`](CLAUDE.md) standing rule 5 (card#5575; deliberately not restated here). What this section owns is the driver lesson from the Python-era `test_db_mariadb.py` incident: a local SQLite-only `vendor/bin/phpunit` run passing does not guarantee CI green when a MariaDB job exists. Driver-specific behavior (transaction semantics, `UNIQUE` constraint timing, `JSON_VALID` enforcement, timestamp precision) only surfaces under the real engine.

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
| A change to `bridge:check`'s output, or a refactor of it | see § The `bridge:check` golden harness below — a behavior-preserving refactor is checked by the golden files, an intended change regenerates them |
| A `Check` migrated into the DL-242 registry | `tests/Unit/Bridge/Check/Checks/<Name>CheckTest.php`, scoped to **the legs the golden suite cannot reach** — `catch` arms, `switch` arms, and any leg needing an unreachable backend or a controlled HTTP outcome. Do NOT re-cover golden-measured legs: the golden files pin the operator-visible line, which is the stronger measurement. Every absence assertion needs a witness that the check ran |

## The `bridge:check` golden harness (DL-242)

`tests/Feature/Console/Check/CheckGoldenTest.php` captures `bridge:check`'s exact stdout + exit
code into `tests/Fixtures/check-golden/*.txt`, one file per install shape (the harness owns how
many; a count restated here is a second copy that drifts on the next fixture). It exists because
the DL-242 Check-registry migration holds stages 0–7 to a byte-identical output contract; it is
what turns that from an intention into a measurement.

- **A refactor that reds a golden file changed behavior.** Fix the code — do not regenerate.
- **A change that is MEANT to alter output** regenerates with `UPDATE_GOLDEN=1 vendor/bin/phpunit
  --filter test_golden_output tests/Feature/Console/Check/CheckGoldenTest.php`, and the regenerated
  files are then **read in the PR diff**: they are the operator-visible change, and reviewing them
  is the point.
- **A fixture must pin every host input it touches.** `bridge:check` reads the ambient host
  directly, and one of those reads is verdict-bearing (`$COORD_CONFIG` produces two *different
  diagnoses*, so it is pinned, never normalized). `Tests\Support\CheckGolden\PinnedHost` owns the
  env pins and `GoldenInstall` owns the `bridge.*` config — including keys that look declared but
  resolve from the deployed `.env` on an operator box (`default_agent`, `receiver_base_url`).
  Anything unpinned makes the golden file a property of the machine that wrote it.
- **A fixture must PROVE it reaches its subject, and that is mechanical, not a review habit.** A
  golden file is captured once and thereafter only ever compared against ITSELF, so a fixture that
  never reached the install shape it is named for is indistinguishable from one that works — it
  just pins some other, healthier output forever. Three were in exactly that state with three
  unrelated causes (card#5552). Every fixture therefore declares at least one substring its render
  must CONTAIN, in `CheckGoldenTest::subjects()`, asserted against the capture in the same
  data-provider run and deliberately AFTER the golden compare — so a `UPDATE_GOLDEN=1` regen cannot
  mint a fixture that measures nothing. A fixture whose subject is an ABSENCE declares that in
  `absentSubjects()` as well, never instead: a notContains alone is satisfied by an empty capture,
  so every fixture carries a positive subject too. `test_every_fixture_declares_a_subject` is what
  makes the declaration mandatory rather than opt-in — the next fixture added cannot skip it.
- **Two golden files with the same bytes are one measurement, not two.** Byte-identical fixtures
  red unless the pairing is declared in `CheckGoldenTest::CONTROL_PAIRS`, and a declared pair must
  STILL be identical — a pairing that has quietly stopped holding is a defect in the other
  direction, so the allow-list is asserted both ways rather than being an escape hatch. The one
  legitimate pair exists because its value is the DIFF against a third fixture, not its own bytes.
- **A green golden suite is not full protection.** `docs/check-golden-coverage.md` names, by
  mutation, the predicates in `handle()` that flipping changes no golden file. Read it before
  concluding a `bridge:check` refactor is covered.
- **A coverage claim names the scope it was MEASURED at, and the two scopes have two different
  instruments.** *Fixture scope* — "no golden fixture renders X" — comes from a grep of
  `tests/Fixtures/check-golden/`, because that corpus is text; it licenses nothing about the rest
  of the suite. *Whole-suite scope* — "nothing else asserts X" — comes only from a MUTATION:
  change the arm, run the whole suite, and the set that goes red **is** the measurement set. A
  grep cannot answer it in either direction, because a command-level test reaches the same arm
  through a config key that names no symbol — so a symbol-keyed selector reads clean while the
  behavior is covered. Claims measured at fixture scope and stated at whole-suite strength are
  the defect this rule exists to stop (card#5551); the rule is mechanical on purpose, because
  awareness of it was measured to be insufficient.
- **A BATCH mutation measures ATTRIBUTABLY, not exclusively — say which you ran.** Mutating many
  arms in one suite run is the affordable form (one run instead of N), each to its own
  severity-preserving sentinel so exit codes cannot move and mask a result. Attribute each arm's
  red set by the failing test's EXPECTED STRING, never by grepping the sentinel — that token both
  over-counts (a token inside a dumped-output diff is not an assertion) and under-counts
  (truncated diffs). What a batch run cannot distinguish is a test observing TWO mutated arms: it
  reds once and is attributed to one of them. So "nothing else observes this arm" out of a batch
  run means *no other test asserts that arm's message* — confirm no cross-arm message overlap in
  the same pass, or re-run the arm alone before stating it any more strongly.
- **`docs/check-golden-coverage.md` enumerates `handle()`'s predicates and nothing else.** A
  migrated check's predicates are not in it and cannot become so, so a comment claiming
  membership in its disclosed-gap list is false by construction rather than merely stale — state
  the fixture-scope fact instead. Absence from that file was never protection either way.
  **CI enforces this** (`bin/check-doc-refs.php`, third rule): under `app/Bridge/Check/`,
  `tests/Unit/Bridge/Check/`, `tests/Support/CheckGolden/` and `tests/Feature/Console/Check/`, a
  comment block naming that file — or the bare phrase "disclosed gap" — must also say what naming
  it does not buy. Satisfy it by stating the bound in the SAME SENTENCE as the mention (that the
  file is generated from `CheckCommand::handle()`), or by drawing the consequence anywhere in the
  block: that absence from it is not protection, that it does not speak in either direction, or
  that the leg was never a disclosed gap. The rule is a whitelist on purpose — an unrecognised
  wrong sentence would pass silently, where an unrecognised right one merely fails loudly. It
  gates the copy-a-neighbour path that minted fifteen of these at once, **not** the truth of any
  individual claim: a false claim carrying a sanctioned sentence still passes.

## Anti-patterns to avoid

- **Tests that mock `DispatchService` from `WebhookDispatchTest`.** The dispatch loop's interaction with the DB and handlers IS what's being tested. Use `RefreshDatabase` + real tmp config dirs.
- **Tests that don't fail before a fix.** A regression test that passes at commit time doesn't catch the regression at merge time. Verify the fix-then-revert cycle locally.
- **Tests that depend on test ordering.** Each test gets a fresh in-memory SQLite and a fresh `uniqid()` tmp dir. Don't write to `~/.config/...` from tests (would leak across runs and into real installs).
- **Handing `sys_get_temp_dir()` itself to code that enumerates a directory.** The temp root is shared and world-writable, so `bridge.config_dir`, a `SubscriptionRegistry`, or any `glob('*.yml')` sink pointed at it parses whatever a co-tenant OS user left in `/tmp` — the suite then reds (or, worse, goes green for the wrong reason) as a function of the box rather than the branch. Always give such code a dir the test owns: `sys_get_temp_dir().'/<prefix>-'.uniqid()`, created in `setUp()` and `File::deleteDirectory`d in `tearDown()`. The same applies to a test asserting a file is ABSENT: assert it over a dir you created, so absence is by construction rather than by luck.
- **Stating a COUNT / ABSENCE / ORDERING / EXCLUSIVITY claim in a test's name and asserting it with `expectsOutputToContain()`.** That matcher answers exactly one question — *does this substring appear at least once?* — so it is satisfied by a doubled line, by a caption printed beside a wrong number, and by a row the command emits unconditionally. A test whose NAME says *collapses* / *deduped* / *once* / *only* / *scoped to* / *counts* while asserting only with it is a **false-clean instrument**: it passes when the behaviour it names is mutated away, and the suite reports the behaviour covered (card#7332 found the first, card#7471 audited the class). Assert the property instead — `substr_count()` for an occurrence count, `doesntExpectOutputToContain()` for an absence, `Tests\Support\ConsoleTable::assertRow()` for a metric/count table CELL (never an unanchored substring, which reads a caption). ⚑ **A different defect of the same matcher, and they are easy to conflate:** chaining several matchers against ONE rendered line is unreliable in both directions. `CLAUDE_DECISIONS.md` DL-263 owns the mechanism and the remedy (capture the output instead); this entry is about what a single matcher can express, not about how several interact.
- **An absence assertion with no presence witness beside it, or one whose predicate cannot fire.** Both make the check a decoration. A lone `Http::assertNotSent(…)` / `doesntExpectOutputToContain(…)` certifies whatever replaces the code, including code that stopped doing anything at all — pair it with the positive leg that proves the run reached the behaviour. And a predicate that can never be true is worse than no assertion: the board scope travels as the query TERM `q=board_id=<b>`, which the client percent-encodes, so `str_contains($r->url(), 'board_id=9')` is false even for a request that DID read board 9 — **`urldecode($r->url())` first** (card#7471).
- **Tests for "trivial" code.** A 3-line getter with type hints doesn't need a test. Test substantive behavior; trust the type system for boilerplate.
- **Using `$this->postJson()` when raw-body integrity matters.** `postJson()` re-encodes the array; the HMAC middleware signs the original byte string. Use `$this->call('POST', $uri, [], [], [], $server, $rawBody)` for the receiver suite.
- **Claiming MariaDB behavior from SQLite-only runs.** Driver-specific paths (UNIQUE constraint violation class, timestamp formats, transaction CM behavior) only surface under MariaDB. Run Job 2 locally before declaring a DB-touching fix done.

## Adding a test file

1. Match the source structure: `app/Bridge/<Area>/<Class>.php` → `tests/Unit/<Area>/<Class>Test.php` (pure) or `tests/Feature/<Area>/<Class>Test.php` (needs DB or HTTP).
2. Use `PHPUnit\Framework\TestCase` for unit tests; `Tests\TestCase` for feature tests.
3. Add `use Illuminate\Foundation\Testing\RefreshDatabase;` only when the test touches the database.
4. Verify locally with the full suite (`vendor/bin/phpunit`) before pushing — catches cross-test interactions that focused runs miss.
