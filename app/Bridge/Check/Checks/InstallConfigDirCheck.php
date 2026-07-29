<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\Check\CheckSlot;
use App\Bridge\Check\DirectoryPermissions;
use App\Bridge\Support\Finding;

/**
 * The config directory the install scans for agent YAMLs — resolvable, then secure —
 * migrated out of `CheckCommand::handle()` (DL-242 stage 6).
 *
 * IT READS `config('bridge.config_dir')` RAW, NOT `CheckContext::$configDir`, and that is
 * the one thing about this check a reviewer should not "simplify". The field is not merely
 * a worse source here — it is EMPTY: `handle()` assigns it while building the per-agent
 * scan, after the `Install` slot has already run, so at this check's runtime it still holds
 * its `null` default and the "simplification" would report every install as unset.
 *
 * It would be the wrong source even if it were populated, which is why the raw read is not
 * merely a happens-to-work ordering accident. The field is narrowed for its consumers
 * (`is_string($v) ? $v : null`): the empty string is a string, so it cannot distinguish the
 * unset case this check's first branch reports, and a `BRIDGE_CONFIG_DIR` of the literal
 * `true` reaches `env()` as a bool and arrives as null. Both branches report on the
 * SETTING, so they must see what was set.
 *
 * THE PERMISSIONS WARN IS PART OF THIS CHECK, not a sibling of it. It is defined only
 * once the directory resolved, so a separate check would have to re-derive this one's
 * verdict — and two checks that can disagree about whether the directory exists is a
 * state one check cannot reach. (Contrast the roster checks of stage 5c, which share a
 * data source but no guard, and are separate for that reason.)
 *
 * NO FIXTURE RENDERS THE UNSET MESSAGE, AND THE REASON IS SET-NESS, NOT RESOLUTION.
 * Measured over the 33 golden fixtures: every one SETS `bridge.config_dir` to a non-empty
 * string, which is what makes the first branch unreachable across the corpus. One of them
 * does NOT resolve — `config-dir-missing` points the key at a path that does not exist, so
 * it renders the SECOND branch's message, and it is the only fixture that does. The
 * coverage table's verdict for the unset predicate therefore comes from the negated mutant
 * changing every fixture, never from that message being rendered anywhere.
 * `InstallConfigDirCheckTest` is what asserts it.
 *
 * @see CheckSlot::Install
 */
final class InstallConfigDirCheck implements Check
{
    public function id(): string
    {
        return 'install.config_dir';
    }

    /**
     * @return iterable<Finding>
     */
    public function run(CheckContext $ctx): iterable
    {
        $configDir = config('bridge.config_dir');

        if (! is_string($configDir) || $configDir === '') {
            yield Finding::fail('bridge.config_dir (BRIDGE_CONFIG_DIR) is not set');

            return;
        }

        if (! is_dir($configDir)) {
            yield Finding::fail("config dir does not exist: {$configDir}");

            return;
        }

        yield Finding::ok("config dir: {$configDir}");

        if (($insecure = DirectoryPermissions::warnIfInsecure('config dir', $configDir)) !== null) {
            yield $insecure;
        }
    }
}
