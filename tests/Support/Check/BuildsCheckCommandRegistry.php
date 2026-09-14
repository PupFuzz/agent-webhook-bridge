<?php

namespace Tests\Support\Check;

use App\Bridge\Check\CheckRunner;
use App\Bridge\Tools\SshProbeEnvironment;
use App\Console\Commands\Bridge\CheckCommand;
use ReflectionMethod;

/**
 * `CheckCommand`'s OWN registration, reached by reflection on its private builder — the honest cost of pinning an
 * internal without putting a test-shaped seam on the command's public surface (DL-242 stage 8).
 *
 * HOISTED AT ITS SECOND CALLER (canon #5, DL-382 R1 finding 4): `CheckCommandRegistrationTest` pins the exact id
 * list this returns, and `CheckGoldenTest` derives its registered-TOTAL assertion from `count()` of that same
 * list — a literal total had already drifted from the registered set once (DL-368 / DL-373 landing on separate
 * branches, each moving it to the same wrong value by different routes), which a derived count cannot do.
 */
trait BuildsCheckCommandRegistry
{
    protected function checkCommandRegistry(): CheckRunner
    {
        $command = $this->app->make(CheckCommand::class);
        $command->setLaravel($this->app);
        $method = new ReflectionMethod($command, 'registry');

        return $method->invoke($command, $this->app->make(SshProbeEnvironment::class), null, null);
    }
}
