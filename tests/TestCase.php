<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use PDO;
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

    protected function setUp(): void
    {
        parent::setUp();

        $this->preventStrayHttpRequests();

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

        parent::tearDown();
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
     * ⚑ WHAT THIS DOES NOT CLOSE, recorded because the number is counter-intuitive.
     * `StrayRequestException` extends `RuntimeException`, and `makePromise()` re-throws it
     * ahead of the `ConnectionException` wrapping — so it reaches the caller, and a caller
     * that degrades on `catch (Throwable)` swallows it exactly as it swallowed the connection
     * error. The test then stays green on the degradation arm and this guard is silent about
     * it: of the strays it surfaced across the suite when it was switched on, only two reached
     * a red test. It is therefore the FLOOR of the rule in `CLAUDE_TESTING.md`, not a
     * replacement for it — a new API call still needs its stub in every test that reaches it
     * AND the request asserted on the wire. A test that registers a catch-all has answered
     * every request it will ever make and is outside this guard's reach entirely.
     *
     * The guard's own witness is `tests/Feature/Support/StrayRequestGuardTest.php`: nothing
     * else in the suite reds if this call is deleted.
     */
    private function preventStrayHttpRequests(): void
    {
        Http::preventStrayRequests();
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
