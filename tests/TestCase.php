<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use PDO;
use Psr\Http\Message\RequestInterface;
use Tests\Support\SkipsAsRoot;

abstract class TestCase extends BaseTestCase
{
    use SkipsAsRoot;

    /**
     * The tables an un-isolated test can reach without meaning to, because the write is a
     * side effect of a primitive rather than something the test asks for. Not every table:
     * a census would fail on the ones a test creates on purpose, and this guard's whole
     * value is that its message names a cause.
     */
    private const COMMITTABLE_TABLES = ['writeback_board_divergences'];

    /**
     * A path that must not exist, so an install-reading check finds nothing rather than
     * finding the OPERATOR'S install. Deliberately not a temp dir the test creates: no test
     * may depend on this default resolving to a real directory, and a path that cannot be
     * created by accident is the cheapest way to keep that true.
     */
    private const NO_INSTALL_DIR = '/nonexistent/bridge-tests-no-install-dir';

    /**
     * Every url the stray guard refused during this test, in order.
     *
     * @var list<string>
     */
    private array $strayRequests = [];

    /**
     * The urls this test declared it drives a stray to ON PURPOSE (`expectStrayRequest()`).
     *
     * @var list<string>
     */
    private array $expectedStrayRequests = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->preventStrayHttpRequests();
        $this->pointConfigDirAwayFromTheRealInstall();

        // Hermetic intent-staging baseline. A real per-agent bridge deployment
        // exports BRIDGE_INBOX_LAYOUT=per-agent (and may export BRIDGE_STATE_DIR);
        // Dotenv won't override an already-set shell var, and phpunit's <env
        // force> doesn't reach the getenv() layer env() reads — so the export
        // leaks in, IntentLog::stage() writes to a per-agent file the tests don't
        // read back, and the suite goes red on a standard operator host while CI
        // (no such export) stays green. A runtime config() override is
        // authoritative. Tests that exercise a non-shared layout set
        // bridge.inbox_layout AFTER parent::setUp(), so they override this default.
        config([
            'bridge.inbox_layout' => 'shared',
            'bridge.state_dir' => null,
        ]);
    }

    protected function tearDown(): void
    {
        $this->assertNothingWasCommittedWithoutIsolation();
        $this->assertNoStrayRequestWasSwallowed();

        parent::tearDown();
    }

    /**
     * Declare that this test drives a stray to `$url` DELIBERATELY, so `tearDown()` does not
     * report it.
     *
     * ⛔ The bar is the same one `DL-303` Decision 4 sets for `allowStrayRequests()`: the
     * stray IS this test's subject. `tests/Feature/Support/StrayRequestGuardTest.php` — the
     * guard's own witness, which asserts the throw — is the only caller that clears it. "This
     * class is noisy" is not the bar, and this is not an opt-out: the request is still refused
     * and still never leaves the process; all this says is which test is the one asserting it.
     * Scope it to the exact url, never to a pattern or a class.
     */
    protected function expectStrayRequest(string $url): void
    {
        $this->expectedStrayRequests[] = $url;
    }

    /**
     * Every url the guard refused during this test that no `expectStrayRequest()` accounts
     * for — i.e. exactly what `tearDown()` reports.
     *
     * Readable by a test so the reporting predicate itself has a witness that can fail; the
     * only caller is `tests/Feature/Support/StrayRequestGuardTest.php`.
     *
     * @return list<string>
     */
    protected function undeclaredStrayRequests(): array
    {
        return array_values(array_diff($this->strayRequests, $this->expectedStrayRequests));
    }

    /**
     * ⛔ `Http::fake()` does NOT block a request no stub answers — it goes to the network.
     *
     * `PendingRequest::buildStubHandler()` invokes every registered stub callback and, when
     * none of them answers, hands the request to the LIVE handler. A bare `Http::fake()` or a
     * `'*'` closure answers everything and hides that; the URL-pattern ARRAY form — the
     * majority form in this suite — does not. Until `DL-303` nothing in this repo called
     * `preventStrayRequests()`, so an outbound call on an already-covered path left the
     * process on every run carrying whatever the caller attached to it (the writeback bearer,
     * via `KanbanHttpClient`), and the suite stayed GREEN: the caller's fail-soft `catch`
     * swallowed the connection failure and the test's own assertions passed through the
     * degradation arm while naming the happy path.
     *
     * ⛔ Those legs passed because DNS FAILED. "A stub answered" and "the host did not
     * resolve" are different causes with the same result, and nothing distinguished them — so
     * on a runner where the host DOES resolve, the same test asserts against whatever answers.
     * One of them resolved: the crosstalk leg of `tests/Feature/Config/InstallGuardTest.php`
     * read the operator install's real config and probed that install's live board.
     *
     * ⚑ WHY THE REFUSAL IS NOT ENOUGH ON ITS OWN, and what the middleware below is for.
     * `StrayRequestException` extends `RuntimeException`, and `makePromise()` re-throws it
     * ahead of the `ConnectionException` wrapping — so it reaches the caller, and a caller
     * that degrades on `catch (Throwable)` swallows it exactly as it swallowed the connection
     * error. Refusing the request therefore does not make it VISIBLE: when the guard was first
     * switched on suite-wide it refused 57 requests and only 2 of them reached a red test. A
     * guard whose alarm the caller swallows measures nothing, so the refusal is paired with a
     * recorder: `Http::globalMiddleware()` sits OUTSIDE the stub handler (Guzzle resolves its
     * stack in reverse push order, and `PendingRequest::pushHandlers()` pushes the global
     * middleware first), so it sees the `StrayRequestException` on its way out, records the
     * url, and re-throws it unchanged. `tearDown()` then fails the test. Nothing about the
     * loud path changes — a caller that propagates still propagates.
     *
     * ⛔ WHAT IS STILL OUTSIDE ITS REACH. A test that registers a catch-all — `Http::fake()`
     * with no arguments, or a `'*'` pattern — has ANSWERED every request it will ever make, so
     * no stray is ever refused and nothing here can say a word about it. That is a different
     * class from this one and this guard does not bound it.
     *
     * The guard's own witness is `tests/Feature/Support/StrayRequestGuardTest.php`: nothing
     * else in the suite reds if these calls are deleted.
     */
    private function preventStrayHttpRequests(): void
    {
        Http::preventStrayRequests();

        Http::globalMiddleware(function (callable $handler): callable {
            return function (RequestInterface $request, array $options) use ($handler) {
                try {
                    return $handler($request, $options);
                } catch (StrayRequestException $e) {
                    // The exception carries no accessor for the url, and the request the stub
                    // handler saw is the one in hand here: this repo registers no
                    // `beforeSending` callback, the only layer between the two that could
                    // rewrite it.
                    $this->strayRequests[] = (string) $request->getUri();

                    throw $e;
                }
            };
        });
    }

    /**
     * ⛔ A refused request the caller swallowed is a finding, not a quiet leg.
     *
     * This is the half of `DL-303` that makes a stub load-bearing where the caller degrades
     * softly. Before it, 47 of the 48 stubs card#7300 added were witnessed by nothing — drop
     * one and the suite stayed green, because the fail-soft `catch` that hid the original
     * escape hid the guard's refusal too. The rule that they must not be dropped was written
     * in prose, which is the complaint the card was filed on.
     */
    private function assertNoStrayRequestWasSwallowed(): void
    {
        $unexpected = $this->undeclaredStrayRequests();

        if ($unexpected === []) {
            return;
        }

        // `fail()` rather than an assertion, for the reason spelled out on the isolation guard
        // below: this reports on the class, not on the subject under test.
        $urls = array_unique($unexpected);

        $this->fail(
            static::class.' made '.count($unexpected).' outbound request(s) no stub answered, to '
            .count($urls).' distinct url(s): '.implode(', ', $urls).'. The guard refused each one, so nothing '
            .'left the process — but the caller SWALLOWED the refusal, so the assertions above '
            .'passed through a degradation arm while naming the happy path. Stub the url in '
            .'every test that reaches it and assert it on the wire (CLAUDE_TESTING.md § Outbound '
            .'HTTP). A test whose SUBJECT is the stray declares it with expectStrayRequest().',
        );
    }

    /**
     * ⛔ `bridge.config_dir` must never resolve to the operator's real install.
     *
     * `config/bridge.php` reads it from `BRIDGE_DIR`, and `phpunit.xml` cannot override that:
     * `<env>` does not reach the `getenv()` layer `env()` reads, so on a deployed checkout the
     * default was the LIVE install's config + secret dir. `InstallGuardTest`'s crosstalk leg
     * ran `bridge:check` against it and issued four token-bearing requests to that install's
     * board on every suite run, while CI — where `BRIDGE_DIR` names a path that does not exist
     * — exercised none of it. Same test, two subjects, decided by the host (card#7300).
     *
     * That leg now sets its own temp dir, as the five other artisan-invoking classes already
     * did. This is the primitive rather than the sixth copy of the workaround: the DEFAULT is
     * the CI shape, so a new class that forgets to pin one reads an empty install on every
     * host instead of the operator's. A test that needs a populated config dir overrides both
     * keys AFTER `parent::setUp()`, exactly as before.
     */
    private function pointConfigDirAwayFromTheRealInstall(): void
    {
        config([
            'bridge.config_dir' => self::NO_INSTALL_DIR,
            'bridge.secret_dir' => self::NO_INSTALL_DIR,
        ]);
    }

    /**
     * ⛔ A test class with no isolation trait must leave the database as it found it.
     *
     * This exists because six classes did not, and the SQLite suite was structurally
     * incapable of noticing (card#7212). `DL-300` made `MappedBoardGuard::boardContext()`
     * write an audit row, so every writeback test began touching the database
     * transitively — while the classes exercising those paths still declared no
     * `RefreshDatabase`, because before that change they genuinely did not need one. On
     * SQLite `:memory:` an un-isolated test gets a database with NO tables, so each of
     * those inserts threw `no such table`, was swallowed by the ledger's never-throw
     * envelope, and left the run green; on a real shared MariaDB the same inserts
     * COMMITTED, and 26 rows were waiting for the first test that asserted on the table.
     * The red landed two directories away from its cause.
     *
     * So the check runs after EVERY test, on the driver that can see it: a class ordering
     * after its victim is caught just the same, and the message names the class that did
     * it rather than the one that noticed.
     *
     * ⚑ It never OPENS a connection — only connections the test itself resolved are
     * inspected (`getRawPdo()` is a `Closure` until the first query). A test that never
     * touched the database therefore pays nothing, and cannot be made to fail here by a
     * lazily-connecting check.
     */
    private function assertNothingWasCommittedWithoutIsolation(): void
    {
        if ($this->app === null || in_array(RefreshDatabase::class, class_uses_recursive(static::class), true)) {
            return;
        }

        foreach ($this->app->make('db')->getConnections() as $name => $connection) {
            if (! $connection->getRawPdo() instanceof PDO) {
                continue;
            }
            foreach (self::COMMITTABLE_TABLES as $table) {
                if (! $connection->getSchemaBuilder()->hasTable($table)) {
                    continue;
                }
                if ($connection->table($table)->count() > 0) {
                    // `fail()` rather than an assertion, deliberately: an assertion here would
                    // be counted against whichever test happened to run, so the MariaDB legs
                    // would report a different assertion total from the SQLite one and a test
                    // with no assertions of its own would stop being reported as risky — this
                    // guard is about the class, not about the subject under test.
                    $this->fail(
                        static::class." wrote rows to `{$table}` on the `{$name}` connection and has no "
                        .'isolation trait, so they OUTLIVE it — every later test in the run sees them, and '
                        .'on SQLite `:memory:` you will never reproduce that. Add `use RefreshDatabase;` to '
                        .'this class (CLAUDE_TESTING.md § Isolation).',
                    );
                }
            }
        }
    }
}
