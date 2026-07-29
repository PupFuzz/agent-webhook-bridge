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
 * BOTH PROBLEM TYPES FAIL, under default-ON: a dead or ambiguous bearer is a BROKEN
 * enablement, not the transient board-state condition the legs below it warn about
 * (DL-220's split). The resolver types them (`bearer_unreadable` | `collision`) so that
 * split can be made without re-parsing a message — this check does not need it yet, and
 * the type is deliberately not consulted rather than mapped through an identity.
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
            return;   // no agent enabled ⇒ no index was built; stage 8 turns this into a returned disposition
        }

        foreach ($resolver->problems() as $problem) {
            yield Finding::fail($problem['message']);
        }
    }
}
