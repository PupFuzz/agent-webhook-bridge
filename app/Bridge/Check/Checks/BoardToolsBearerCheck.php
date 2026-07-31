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
 * BOTH PROBLEMS FAIL, under default-ON: a dead or ambiguous bearer is a BROKEN enablement,
 * not the transient board-state condition the legs below it warn about (DL-220's split).
 * The resolver used to TYPE each entry (`bearer_unreadable` | `collision`) so this check
 * could split severity on it; the split was decided the other way and the type was never
 * read, so DL-251 removed it (card#5292) — `problems()` is a list of messages.
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

        foreach ($resolver->problems() as $problem) {
            yield Finding::fail($problem);
        }
    }
}
