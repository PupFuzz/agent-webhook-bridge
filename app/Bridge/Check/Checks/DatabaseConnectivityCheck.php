<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\Support\Finding;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Can the install reach its database at all, migrated out of `CheckCommand::handle()`
 * (DL-242 stage 4).
 *
 * The receiver is synchronous and writes on every delivery (`dedupCreate` is the
 * at-least-once gate), so an unreachable database is not a degraded mode — it 5xxes
 * every webhook. Reporting it at preflight is the difference between finding out here
 * and finding out from an upstream retry storm.
 *
 * THE `catch` IS THE CHECK'S OWN, and that is the narrow form the plan allows to
 * migrate: it wraps ONE probing call and no derivation, exactly as
 * {@see RetentionPostureCheck}'s marker read does. The whole-cluster fail-soft
 * envelopes stay in `handle()` for the opposite reason — they wrap derivation too,
 * so keeping them there makes semantic preservation a property of the method rather
 * than an assumption about its callees. `CheckRunner` deliberately does not catch.
 *
 * IT SPANS BOTH CALLS DELIBERATELY. Resolving the connection can throw on its own (an
 * unknown driver, an unparseable DSN) before `getPdo()` is ever reached, so a `try`
 * around the connect alone would abort `bridge:check` on the misconfiguration it exists
 * to report.
 *
 * NEITHER BRANCH IS REACHED BY A GOLDEN FIXTURE'S FAILING SIDE: every fixture install
 * connects, and this leg contributes no predicate to `bin/check-golden-mutate.php` at
 * all (it walks `if`/`elseif`/`foreach`, never `catch`). Mutating the failure arm
 * reds `DatabaseConnectivityCheckTest` and nothing else in the suite — measured by
 * mutation, not inferred from a grep (CLAUDE_TESTING.md).
 */
final class DatabaseConnectivityCheck implements Check
{
    public function id(): string
    {
        return 'database.connectivity';
    }

    /**
     * @return iterable<Finding>
     */
    public function run(CheckContext $ctx): iterable
    {
        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            yield Finding::fail('database: '.$e->getMessage());

            return;
        }

        yield Finding::ok('database: connected');
    }
}
