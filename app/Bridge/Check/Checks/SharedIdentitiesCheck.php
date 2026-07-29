<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\Support\AgentRegistry;
use App\Bridge\Support\Finding;

/**
 * Report the optional `shared-identities.json` when it is present, migrated out of
 * `CheckCommand::handle()` (DL-242 stage 5c).
 *
 * It exists so a v0.13 schema-v1 migration — or a file that silently parsed to nothing —
 * surfaces at preflight instead of as missing attribution at runtime. The COUNT is the
 * signal: `AgentRegistry::loadSharedIdentities()` is fail-soft (it logs and returns an
 * empty list for unreadable or wrongly-shaped JSON), so a file that is present and
 * reports `0 shared account(s)` is exactly the state an operator needs to see.
 *
 * IT RE-READS THE FILE THAT THE REGISTRY DERIVATION ALREADY READ, deliberately: the
 * inline code this replaces read it twice, and on a wrongly-shaped file that is two log
 * lines. Collapsing the two reads changes no command output but does change logging, and
 * stages 0-7 hold a byte-identical migration contract — so the duplication is preserved
 * here and carded for its own change rather than folded into a migration PR.
 *
 * ONLY THE FILE-PRESENT BRANCH IS GOLDEN-COVERED, AND ONLY AT ZERO. The one fixture that
 * writes the file omits the `shared_identities` wrapper key, so it parses to an empty
 * list and pins the `0 shared account(s)` rendering; nothing in the fixture set renders a
 * non-zero count. `SharedIdentitiesCheckTest` covers that. (Named, never `{@see}`-linked:
 * pint would turn the FQCN into a real `use`.)
 */
final class SharedIdentitiesCheck implements Check
{
    public function id(): string
    {
        return 'agent.shared_identities';
    }

    /**
     * @return iterable<Finding>
     */
    public function run(CheckContext $ctx): iterable
    {
        if ($ctx->configDir === null || ! is_file(rtrim($ctx->configDir, '/').'/shared-identities.json')) {
            return;
        }

        $shared = AgentRegistry::loadSharedIdentities($ctx->configDir);

        yield Finding::ok('shared-identities.json: '.count($shared).' shared account(s)');
    }
}
