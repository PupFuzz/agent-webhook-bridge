<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Silence;
use App\Bridge\Support\Finding;
use App\Bridge\Support\SharedIdentitiesFileState;

/**
 * Report the optional `shared-identities.json` when it is present, migrated out of
 * `CheckCommand::handle()` (DL-242 stage 5c).
 *
 * It exists so a v0.13 schema-v1 migration — or a file that silently parsed to nothing —
 * surfaces at preflight instead of as missing attribution at runtime. The four verdicts
 * are the four states one read can end in (DL-259): a file that PARSED is reported by its
 * COUNT, including at zero, because a present file declaring nothing is exactly the state
 * an operator needs to see; a file that could not be READ is `unvalidated`, since nothing
 * about it was measured; one whose bytes are not a JSON object is a `warn`, since the
 * loader ignores it and attribution silently goes missing; and an absent file is silence,
 * because the file is optional.
 *
 * IT READS NOTHING. The derivation performs the ONE read per run and publishes its state
 * on the context (card#5546); this check pronounces the verdicts on that state. Reading
 * again would re-emit every fail-soft warning the read logs — which stdout cannot show,
 * so the two reads this replaces made a wrongly-shaped file warn twice per run with the
 * output contract none the wiser.
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
     * @return iterable<Finding|Silence>
     */
    public function run(CheckContext $ctx): iterable
    {
        $file = $ctx->sharedIdentities;
        // Null is the derivation never having run — no config dir, so no path could be
        // formed — which is the same nothing-to-report as an absent file.
        if ($file === null || $file->state === SharedIdentitiesFileState::Absent) {
            yield Silence::because('there is no shared-identities.json to read — the file is optional, and an install where no two agents share an account correctly has none');

            return;
        }

        if ($file->state === SharedIdentitiesFileState::Unreadable) {
            yield Finding::unvalidated("shared-identities.json at {$file->path} could NOT be read by this process (it is present — a perms or I/O fault, not an absent file), so how many shared accounts it declares is unknown and this run is not evidence that attribution is wired; re-run as the owning user, or fix its perms.");

            return;
        }

        // MEASURED, so it is a `warn` and not `unvalidated`: the bytes were read and they are
        // not a JSON object. Warn rather than fail because the runtime degrades rather than
        // breaks — attribution silently goes missing, which is precisely what must be loud.
        if ($file->state === SharedIdentitiesFileState::Malformed) {
            yield Finding::warn("shared-identities.json at {$file->path} is not a valid JSON object — it is IGNORED at runtime (the loader is fail-soft), so every agent sharing an account loses its attribution silently. Fix the JSON, or remove the file if it is not needed.");

            return;
        }

        yield Finding::ok('shared-identities.json: '.count($file->identities).' shared account(s)');
    }
}
