<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\Support\Finding;
use App\Bridge\Support\InstallGuard;

/**
 * The dev/prod crosstalk guard (DL-001), migrated out of `CheckCommand::handle()`
 * (DL-242 stage 4).
 *
 * A `-dev` install pointed at the prod database is the failure this reports, and it is
 * SILENT at every other layer: both installs are correct programs, the DSN parses, the
 * schema matches, and the first symptom is dev traffic mutating prod rows. The guard is
 * the only thing that distinguishes them, so its verdict belongs at preflight.
 *
 * IT SITS WITH {@see DatabaseConnectivityCheck} rather than with the config-dir legs
 * because both assert on the same subject one after the other — can we reach a database,
 * and is it the RIGHT one. Two ids rather than one grouped check: each emits a single
 * line and they are sequential, so output order does not force the grouping (contrast
 * {@see WritebackMappingConfigCheck}, where it does), and separate ids give stage 8's
 * inventory the finer granularity for free.
 *
 * THE VERDICT TEXT IS `InstallGuard`'s, NOT THIS CLASS'S. It composes the mismatch
 * message from the configured suffix and database name, and duplicating that here would
 * be a second copy to keep in step with the guard that decides.
 *
 * NO GOLDEN FIXTURE REACHES THE MISMATCH BRANCH — every fixture prints the `ok` line, so
 * the coverage table's `observed` verdict for this predicate comes from the negated
 * mutant printing a different (empty) line, never from the real message being rendered.
 * `InstallSuffixDsnCheckTest` is what asserts the failing side.
 */
final class InstallSuffixDsnCheck implements Check
{
    public function id(): string
    {
        return 'database.install_suffix';
    }

    /**
     * @return iterable<Finding>
     */
    public function run(CheckContext $ctx): iterable
    {
        if (($crosstalk = InstallGuard::dsnCrosstalk()) !== null) {
            yield Finding::fail($crosstalk);

            return;
        }

        yield Finding::ok('install-suffix DSN check: ok');
    }
}
