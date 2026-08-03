<?php

namespace Tests\Support;

/**
 * Any test whose subject is "this process CANNOT see that path" must call this first.
 *
 * Root reads through mode 0000, so under a root runner the state under test is
 * unreachable and every assertion passes without the guard they exist to prove — a green
 * that witnesses nothing. That is the vacuous-pass shape, not a portability nicety.
 *
 * A TRAIT RATHER THAN A METHOD ON `Tests\TestCase` (NAMED, never `{@see}`-linked: pint
 * rewrites a docblock FQCN into a real `use`, and this trait's consumer must not become
 * its import), because the population that needs it does not share one base:
 * `ChannelSnapshotProbeTest` extends PHPUnit's TestCase
 * directly (it boots no framework), so a method on the Laravel base would have left that
 * one file holding a private copy — which is the state this replaced. SIX classes had each
 * re-declared it, in two wordings ("directory" vs "file" permission checks) that had begun
 * to diverge for no reason either copy could state.
 */
trait SkipsAsRoot
{
    protected function skipAsRoot(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $this->markTestSkipped('root bypasses permission checks');
        }
    }
}
