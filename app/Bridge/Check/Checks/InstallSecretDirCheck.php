<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\Check\CheckSlot;
use App\Bridge\Check\DirectoryPermissions;
use App\Bridge\Support\Finding;

/**
 * The directory holding the webhook secrets and API tokens — resolvable, then secure —
 * migrated out of `CheckCommand::handle()` (DL-242 stage 6).
 *
 * ITS PERMISSIONS LEG IS CONDITIONAL ON A SPLIT LAYOUT (DL-014): when `secret_dir` is a
 * different path from `config_dir`, IT is the directory holding the secrets and its own
 * mode must be warned on. When the two are the same path, {@see InstallConfigDirCheck}
 * has already reported that mode and a second identical line would be noise.
 *
 * THE COMPARISON IS AGAINST THE RAW `config('bridge.config_dir')`, matching the check
 * above and the inline code before it: a strict `!==` against a non-string config value
 * is true, which is the behaviour a split-layout warn wants (an unusable config_dir does
 * not suppress the secret dir's own verdict).
 *
 * NO GOLDEN FIXTURE HAS EVER RENDERED THIS WARN. All of them run with `secret_dir` equal
 * to `config_dir`, so the split-layout branch is false in every one; the coverage table's
 * verdict for that predicate comes from the negated mutant printing the warn twice. This
 * is the third place in this migration where an `observed` predicate says nothing about
 * whether its message is asserted anywhere — `InstallSecretDirCheckTest` is what asserts
 * it.
 *
 * @see CheckSlot::Install
 */
final class InstallSecretDirCheck implements Check
{
    public function id(): string
    {
        return 'install.secret_dir';
    }

    /**
     * @return iterable<Finding>
     */
    public function run(CheckContext $ctx): iterable
    {
        $secretDir = config('bridge.secret_dir');

        if (! is_string($secretDir) || ! str_starts_with($secretDir, '/')) {
            yield Finding::fail('bridge.secret_dir (BRIDGE_SECRET_DIR) is not set or not absolute');

            return;
        }

        yield Finding::ok("secret dir: {$secretDir}");

        if ($secretDir !== config('bridge.config_dir')
            && ($insecure = DirectoryPermissions::warnIfInsecure('secret dir', $secretDir)) !== null) {
            yield $insecure;
        }
    }
}
