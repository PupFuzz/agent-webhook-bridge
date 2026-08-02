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
        if ($ctx->configDir === null || ! is_file($path = rtrim($ctx->configDir, '/').'/shared-identities.json')) {
            return;
        }

        // THE COUNT ALONE CANNOT CARRY THE SIGNAL (DL-259, card#5698). `loadSharedIdentities()`
        // is fail-soft BY DESIGN — the receiver must not 5xx over a bad optional file — so it
        // answers `[]` for a file it could not read, for one that is not JSON, and for one that
        // genuinely declares nothing. Reporting `0 shared account(s)` in GREEN for all three
        // rendered the two FAULTS identical to the benign case, at the one preflight whose
        // stated purpose is to surface them before they show up as missing attribution at
        // runtime. The primitive keeps its fail-soft contract (its other caller is the runtime);
        // the discrimination belongs here, where a verdict is being pronounced.
        $raw = @file_get_contents($path);
        if ($raw === false) {
            yield Finding::unvalidated("shared-identities.json at {$path} could NOT be read by this process (it is present — a perms or I/O fault, not an absent file), so how many shared accounts it declares is unknown and this run is not evidence that attribution is wired; re-run as the owning user, or fix its perms.");

            return;
        }
        // MEASURED, so it is a `warn` and not `unvalidated`: the bytes were read and they are
        // not a JSON object. Warn rather than fail because the runtime degrades rather than
        // breaks — attribution silently goes missing, which is precisely what must be loud.
        if (! is_array(json_decode($raw, true))) {
            yield Finding::warn("shared-identities.json at {$path} is not a valid JSON object — it is IGNORED at runtime (the loader is fail-soft), so every agent sharing an account loses its attribution silently. Fix the JSON, or remove the file if it is not needed.");

            return;
        }

        // Parsed: the count is a real answer, and the shared parser is the one that produces
        // it — this leg deliberately does not grow a second reading of the same file's shape.
        $shared = AgentRegistry::loadSharedIdentities($ctx->configDir);

        yield Finding::ok('shared-identities.json: '.count($shared).' shared account(s)');
    }
}
