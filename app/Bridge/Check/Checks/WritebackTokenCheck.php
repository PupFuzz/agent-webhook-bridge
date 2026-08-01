<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\Support\Finding;
use App\Bridge\Support\PathVisibility;
use App\Bridge\Support\SecretFile;
use App\Bridge\Support\TokenPath;

/**
 * The dedicated kanban writeback token's presence + perms, migrated out of
 * `CheckCommand::handle()` (DL-242 stage 3a).
 *
 * The move runs in the FPM webhook runtime with no interactive credential path, so an
 * absent or group/world-readable token file is a move that fails at event time — after
 * the operator has already been told the install is healthy.
 *
 * ITS THREE GUARDS MOVED IN WITH IT (plan constraint (a)): no secret dir (nowhere to
 * form a path), no writeback config, and no mappings (nothing to move) each mean the
 * token is not yet a requirement, and each is the check's verdict to own rather than an
 * `if` at the call site. {@see SecretFile::isInsecure} is used rather than the throwing
 * `assertSecure` because this is a report, not a gate — the fail-closed 0600 enforcement
 * happens at point-of-use.
 */
final class WritebackTokenCheck implements Check
{
    public function id(): string
    {
        return 'writeback.token';
    }

    /**
     * @return iterable<Finding>
     */
    public function run(CheckContext $ctx): iterable
    {
        if ($ctx->secretDir === null || $ctx->writeback === null || $ctx->writeback->mappings === []) {
            return;   // nothing to report; the run inventory records the disposition (DL-242 stage 8)
        }

        $tokenPath = TokenPath::forWriteback($ctx->secretDir, 'kanban');
        if (! is_file($tokenPath)) {
            yield PathVisibility::unverifiedUnlessVisible($tokenPath, "writeback: kanban writeback token at {$tokenPath}")
                ?? Finding::warn("writeback: no kanban writeback token at {$tokenPath} — the move will fail until you place a least-privilege token (chmod 600)");
        } elseif (SecretFile::isInsecure($tokenPath)) {
            yield Finding::warn('writeback: '.SecretFile::permsMessage($tokenPath).' — the move will fail until fixed');
        }
    }
}
