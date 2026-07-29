<?php

namespace Tests\Unit\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Checks\DatabaseConnectivityCheck;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use Tests\TestCase;

/**
 * The FAILURE arm, which nothing else measures (DL-242 stage 4).
 *
 * Every golden fixture install connects, so the golden suite (`CheckGoldenTest` — named,
 * not `{@see}`-linked, because pint's docblock fixer turns a fully-qualified `{@see}` into
 * a real `use`, and an import minted by a comment is one an unused-import gate can never
 * retire) pins only the `database: connected` line. The failing side is also invisible to
 * `bin/check-golden-mutate.php`: that instrument enumerates `if`/`elseif`/`foreach`
 * predicates and this leg is a bare `try`/`catch`, so it contributes NO row to
 * `docs/check-golden-coverage.md` — its absence from the disclosed-gap list is not
 * protection. Without this file the arm is justified by reading alone.
 *
 * BOTH THROW SITES ARE COVERED SEPARATELY, because the check's `try` deliberately spans
 * two calls and a narrower one would still pass a single-route test: resolving the
 * connection throws for an unsupported driver, and only a resolvable connection reaches
 * `getPdo()`. Neither route touches the network.
 */
class DatabaseConnectivityCheckTest extends TestCase
{
    public function test_an_unresolvable_connection_is_reported_rather_than_aborting_the_check(): void
    {
        config([
            'database.default' => 'checktest',
            'database.connections.checktest' => ['driver' => 'no-such-driver', 'database' => 'x'],
        ]);

        $findings = $this->findingsFrom();

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Fail, $findings[0]->severity);
        // The prefix is this class's own literal and is pinned exactly; the rest is the
        // framework's prose, so the route is pinned by an input echoed back into it —
        // only the connection RESOLVER names the driver. Asserting the framework's exact
        // wording would red on a dependency bump without telling us anything more.
        $this->assertStringStartsWith('database: ', $findings[0]->message);
        $this->assertStringContainsString('no-such-driver', $findings[0]->message);
    }

    public function test_a_connection_that_cannot_be_opened_is_reported_rather_than_aborting_the_check(): void
    {
        config([
            'database.default' => 'checktest',
            'database.connections.checktest' => [
                'driver' => 'sqlite',
                'database' => '/nonexistent-directory-for-a-bridge-check-test/bridge.sqlite',
            ],
        ]);

        $findings = $this->findingsFrom();

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Fail, $findings[0]->severity);
        // The complementary route, pinned the same way: this connection RESOLVES and
        // fails when PDO is asked for, and only that failure names the database path.
        $this->assertStringStartsWith('database: ', $findings[0]->message);
        $this->assertStringContainsString('/nonexistent-directory-for-a-bridge-check-test/bridge.sqlite', $findings[0]->message);
    }

    /**
     * The discriminating control. Without it both assertions above are satisfied by a
     * check that reports `fail` unconditionally — the message prefix is this class's own
     * literal, so it proves nothing about which branch ran.
     */
    public function test_a_reachable_database_reports_connected(): void
    {
        $findings = $this->findingsFrom();

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Ok, $findings[0]->severity);
        $this->assertSame('database: connected', $findings[0]->message);
    }

    /** @return list<Finding> */
    private function findingsFrom(): array
    {
        return iterator_to_array((new DatabaseConnectivityCheck)->run(new CheckContext), false);
    }
}
