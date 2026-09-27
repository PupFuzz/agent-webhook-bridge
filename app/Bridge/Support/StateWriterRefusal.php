<?php

namespace App\Bridge\Support;

/**
 * THE ONE RULE for who may write a state file the receiver also reads (card#10567 review r2,
 * hoisted from `GitHubWriteDebt::writerRefusal()` at its second caller, `ClientPackStore`).
 *
 * ⛔ A STATE FILE IS OWNED BY WHOEVER LAST WROTE IT, `0600` (`BridgePaths::writeFileAtomic()`'s
 * `tempnam`), and a state directory `0700` (`BridgePaths::ensureDir()`). A writer running as anyone
 * but the receiver's user does not merely write — it takes the file off the receiver. Two refusals
 * follow:
 *  - ROOT NEVER WRITES: root is never the receiver's user, and what root creates the receiver
 *    cannot open;
 *  - A PRESENT FILE OR DIRECTORY IS WRITTEN ONLY BY ITS OWNER, since the owner is, by the rule
 *    above, the last user that could write it.
 * ⚑ An ABSENT file created by a non-root user other than the receiver's is NOT refused: nothing this
 * process can read says which user the receiver runs as. An effective uid this process cannot read
 * (no posix extension) is unmeasured and refuses nothing.
 */
final class StateWriterRefusal
{
    /**
     * Why THIS process must not write, or null when it may.
     *
     * @param  string  $primary  the file the root arm names — the store's own record
     * @param  list<string>  $owned  every present path the owner rule applies to
     * @param  string  $rootConsequence  what a root write would break, as a clause
     * @param  string  $giveBack  the remedy that hands every owned path back to the receiver's user
     */
    public static function check(string $primary, array $owned, string $rootConsequence, string $giveBack): ?string
    {
        $identity = app(ProcessIdentity::class);
        $euid = $identity->euid();
        if ($euid === null) {
            return null;
        }
        $owner = $identity->ownerOf($primary);
        $ownerName = $owner === null ? null : ($identity->accountName($owner) ?? "uid {$owner}");

        if ($euid === 0) {
            // ⛔ A ROOT-OWNED FILE HAS NO USER TO RUN AS — root is refused here — so the only
            // remedy is to hand it back; "run it as root" would send the operator in a circle.
            return "this process runs as root, and {$rootConsequence}; "
                .match (true) {
                    $owner === null => 'run it as the user the receiver runs as',
                    $owner === 0 => "{$primary} is already owned by root: {$giveBack}",
                    default => "{$primary} is owned by {$ownerName}: run it as {$ownerName}",
                };
        }
        foreach ($owned as $path) {
            $pathOwner = $identity->ownerOf($path);
            if ($pathOwner === null || $pathOwner === $euid) {
                continue;
            }
            $pathOwnerName = $identity->accountName($pathOwner) ?? "uid {$pathOwner}";
            $me = $identity->accountName($euid) ?? "uid {$euid}";

            // The same sentence can reach the operator's terminal and the receiver's log, so it
            // names both remedies: the owner may be the receiver's user, or may be the one write
            // this rule cannot refuse (above).
            return "{$path} is owned by {$pathOwnerName} and this process runs as {$me}; replacing it would hand it to {$me} — "
                .($pathOwner === 0 ? $giveBack : "run it as {$pathOwnerName}, or, if {$pathOwnerName} is not the user the receiver runs as, {$giveBack}");
        }

        return null;
    }
}
