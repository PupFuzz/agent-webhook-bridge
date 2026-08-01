<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\Support\Finding;
use App\Bridge\Tools\BoardToolAgentResolver;

/**
 * The board-tools BEARER problems (DL-217) — an unreadable token file, or one token value
 * shared by two agents — migrated out of `CheckCommand::checkBoardTools()` (DL-242 stage
 * 7b).
 *
 * IT ASSERTS ON A BUILD IT DOES NOT PERFORM. {@see BoardToolAgentResolver} finds both
 * problem classes while INDEXING (it reads each enabled HTTP agent's token file and logs
 * every collision at construction); `problems()` only returns what the build accumulated.
 * So the build stays in `CheckCommand` as derivation and this check reads
 * {@see CheckContext::$boardToolsResolver} — the same rule that keeps the `AgentRegistry`
 * build out of the roster checks (stage 5c).
 *
 * BOTH MEASURED PROBLEMS FAIL, under default-ON: a dead or ambiguous bearer is a BROKEN
 * enablement, not the transient board-state condition the legs below it report on without
 * failing (DL-220's split — those legs are `warn` or, where the read never resolved,
 * `unvalidated`). The resolver used to TYPE each entry (`bearer_unreadable` | `collision`)
 * so this check could split severity on it; the split was decided the other way and the
 * type was never read, so DL-251 removed it (card#5292).
 *
 * THIS CHECK NO LONGER PICKS THE SEVERITY AT ALL (card#5698). It used to wrap every entry
 * in `Finding::fail()`, which made a THIRD case indistinguishable from the two above: a
 * token file the build could not SEE. `is_file()` is false for EACCES exactly as for
 * ENOENT, so on an untraversable path that `fail` accused the operator of a missing token
 * — and flipped the exit — for a file that may exist and be perfectly readable by the WEB
 * user whose resolver actually serves the runtime. `problems()` now carries `Finding`s and
 * this check renders what the build decided. DL-251's ruling is untouched: it settled two
 * measured faults, and both still FAIL.
 */
final class BoardToolsBearerCheck implements Check
{
    public function id(): string
    {
        return 'board_tools.bearer';
    }

    /**
     * @return iterable<Finding>
     */
    public function run(CheckContext $ctx): iterable
    {
        $resolver = $ctx->boardToolsResolver;
        if ($resolver === null) {
            return;   // no agent enabled ⇒ no index was built; recorded in the run inventory (DL-242 stage 8)
        }

        // The resolver decides each severity, because only the build knows which problems
        // it MEASURED — re-severing them here would be this check asserting a fault it
        // never observed (card#5698).
        yield from $resolver->problems();
    }
}
