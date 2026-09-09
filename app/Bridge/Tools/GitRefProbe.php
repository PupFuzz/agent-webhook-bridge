<?php

namespace App\Bridge\Tools;

/**
 * The one git fact the board-tools setup packet needs: WHICH REF THIS BOX RUNS, stated
 * only when a seat could actually clone it.
 *
 * Behind a seam for the same reason {@see SshProbeEnvironment} is: the answer is a
 * property of the operator's checkout, so a test that inherited it would capture the
 * runner's git state rather than an install shape — and the packet's whole value is that
 * the seat ends up on the SAME code as the bridge.
 */
interface GitRefProbe
{
    /**
     * `[sha, branch]` for a HEAD that is an ANCESTOR of its upstream, or null.
     *
     * ⭐ THE ANCESTOR TEST IS WHAT MAKES THE ANSWER USABLE, and it is why this is not
     * simply `rev-parse HEAD`. The packet tells a seat to clone a branch and check a sha
     * out of it; a commit that exists only in this working copy — an unpushed local
     * commit, a detached bisect, a dirty rebase — is a sha the seat's clone does not
     * contain, so printing it sends the impl agent to `fatal: reference is not a tree`.
     * ⚠ It is answered against THIS BOX'S LAST-FETCHED view of the upstream and nothing
     * here fetches: a `@{upstream}` that has not been updated in a week still answers,
     * which is why the caller's sentence names the view rather than claiming currency.
     *
     * @return ?array{sha: string, branch: string}
     */
    public function headOnPushedBranch(string $dir): ?array;
}
