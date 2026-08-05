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
 * ITS ONLY GOLDEN COVERAGE IS INCIDENTAL, AND THE DISTINCTION MATTERS. `GoldenInstall`
 * boots every fixture with `secret_dir` and `config_dir` at the same path, and exactly one
 * of the 33 diverges afterwards: `config-dir-missing` repoints `config_dir` at a path that
 * does not exist and leaves `secret_dir` at the install root, so the guard is TRUE there
 * and this warn renders as a side effect of a fixture built to exercise the OTHER
 * directory's failure. Measured over the corpus: 31 fixtures reach the guard and it is
 * false, that one reaches it true, and `secret-dir-unset` never reaches it at all — the
 * branch above returns first. Nothing exercises a DELIBERATE split layout, and nothing
 * distinguishes the warn firing on one from firing on that accident.
 * `InstallSecretDirCheckTest` is what asserts the guard itself: the warn present when the
 * two dirs differ, and ABSENT when they are the same path and that path is insecure.
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
            && ($verdict = DirectoryPermissions::verdictFor('secret dir', $secretDir)) !== null) {
            yield $verdict;
        }
    }
}
