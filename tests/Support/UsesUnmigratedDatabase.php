<?php

namespace Tests\Support;

use App\Models\BoardToolsClientCall;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\DatabaseManager;

/**
 * Run a body with the client-half row's model pointed at a REAL SQLite connection that has
 * never been migrated (card#7756 / DL-313).
 *
 * WHY A REAL UNMIGRATED CONNECTION RATHER THAN A MOCK THAT THROWS. The failure under test is
 * an install that pulled the code and has not run `php artisan migrate`, and what a caller
 * must survive is the DRIVER's own `no such table` — a hand-thrown exception would assert
 * against a throw the test invented, and would stay green if the real query failed some
 * other way (or stopped failing at all).
 *
 * A TRAIT AT THE SECOND CALLER, not a third copy: the writer's fail-soft envelope
 * (`tests/Feature/AgentTools/BoardToolDispatcherTest.php`) and the reader's limb-(a) arm
 * (`tests/Unit/Bridge/Check/Checks/BoardToolsClientHalfCheckTest.php`) need the same
 * sabotage, and the two had it byte-identical in two places, in two wordings. Named rather
 * than `{@see}`-linked, as {@see SkipsAsRoot} records: pint rewrites a docblock FQCN into a
 * real `use`, and a consumer of this trait must not become its import.
 *
 * ⛔ IT REDIRECTS EVERY ELOQUENT MODEL, NOT ONE — and this docblock claimed the opposite
 * until card#8973 measured it. `Model::$resolver` is a single `protected static` slot
 * declared on the base `Illuminate\Database\Eloquent\Model`, and no model here re-declares
 * it, so `BoardToolsClientCall::setConnectionResolver()` writes THE slot: measured, an
 * unrelated `WebhookEvent` query resolves `unmigrated` inside the body and `sqlite` again
 * after it. The one model named below is therefore the HANDLE on a global swap, not its
 * scope — which is what makes the trait usable for a check that reads two tables, and what
 * makes an unrelated model query inside the body a `no such table` rather than a puzzle.
 *
 * ⚑ WHAT DOES STAY ISOLATED IS THE SURROUNDING TEST'S TRANSACTION: `RefreshDatabase`
 * resolves its connection through the CONTAINER, not through this static slot, so its
 * rollback is unaffected. The swap is undone in a `finally` whatever the body does — so the
 * redirection lasts exactly as long as the body, which is what keeps a global swap safe.
 */
trait UsesUnmigratedDatabase
{
    protected function withUnmigratedDatabase(callable $body): mixed
    {
        config(['database.connections.unmigrated' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);

        /** @var DatabaseManager $manager */
        $manager = $this->app->make('db');
        $original = BoardToolsClientCall::getConnectionResolver();

        BoardToolsClientCall::setConnectionResolver(new class($manager) implements ConnectionResolverInterface
        {
            public function __construct(private DatabaseManager $manager) {}

            public function connection($name = null): ConnectionInterface
            {
                return $this->manager->connection('unmigrated');
            }

            public function getDefaultConnection(): string
            {
                return 'unmigrated';
            }

            public function setDefaultConnection($name): void {}
        });

        try {
            return $body();
        } finally {
            BoardToolsClientCall::setConnectionResolver($original);
        }
    }
}
