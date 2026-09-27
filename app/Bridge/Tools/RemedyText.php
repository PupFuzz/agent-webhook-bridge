<?php

namespace App\Bridge\Tools;

use App\Bridge\Exceptions\ToolRefusalException;
use UnexpectedValueException;

/**
 * Remedy text that accounts for the caller's channel client (card#10566 / DL-426): when a
 * sentence names an argument the caller's client does not DECLARE, the sentence is kept whole
 * and a clause is appended — the running version, the version that first declared the argument,
 * and the argument's type.
 *
 * ⛔ THE CLAUSE NEVER REMOVES THE ESCAPE, AND STATES ONLY WHAT THE BRIDGE KNOWS. The bridge
 * accepts the argument if it arrives, and the channel server forwards what it is given. Whether
 * an old client SENDS a key its schema lacks is not known — every pre-0.9.16 `board_my_cards`
 * schema carries `additionalProperties: false` — so the clause says the bridge accepts it and
 * that updating the client is the reliable fix, never that passing it works (DL-426 residual).
 *
 * WHEN THERE IS NO CLAUSE: the client declares every argument named; or the table cannot order
 * a REPORTED version (newer than any this checkout records, or not bare `X.Y.Z`) — it cannot be
 * shown to lack anything; or the tool is not a shipped one (an operator-registered tool, including
 * one registered under a shipped name); or the table is unreadable ({@see CallerClient}); or the
 * refusal is an INSTALL-fault read refusal ({@see ToolRefusalException::$installFault}), which
 * {@see BoardToolDispatcher} never hands to {@see advise()} — such a refusal names an argument
 * only to say which read failed, and "update your client" is the wrong fix for a fault that is
 * the board's or the install's (r3-m3). The declared case is byte-identical to the text before
 * this existed. A call whose version
 * {@see ClientVersion} reduced to null — none sent (an old client, a hand-run `bridge:tools-call`,
 * `bridge:check --probe-tools`) or one it refused (wrong type, over-long, a character outside its
 * whitelist) — gets the "could not read a client version" clause, which names no cause because
 * the bridge cannot tell those apart.
 *
 * WHERE IT IS APPLIED — two places, deliberately not the N sentences that name an argument:
 * {@see BoardToolDispatcher} runs every tool refusal through {@see advise()} and ends its own
 * undeclared-argument refusal with {@see gapClause()}; a tool runs the remedies it returns in a
 * SUCCESSFUL body (today `board_my_cards`' truncated windows) through {@see advise()}.
 */
final class RemedyText
{
    /**
     * `$how`, plus the gap clause for every argument of `$tool` it backticks. The arguments are
     * READ OFF THE TEXT rather than listed by the caller, so a remedy that names an argument
     * cannot forget to account for it. The regex matches a backtick immediately followed by a
     * run of `[a-z0-9_]` characters, up to but not including the first character that cannot
     * continue an identifier — so `` `limit` ``, `` `limit 100` ``, `` `limit=100` `` and
     * `` `include_terminal: true` `` all count, and a longer identifier the name is only a
     * prefix of (`` `limits` ``) does not (r4-M1).
     */
    public static function advise(CallerClient $caller, Tool $tool, string $how): string
    {
        preg_match_all('/`([a-z_][a-z0-9_]*)(?![a-z0-9_])/', $how, $m);
        $named = array_values(array_unique(array_intersect($m[1], $tool->acceptedArguments())));

        return $how.self::gapClause($caller, $tool, $named);
    }

    /**
     * The clause alone — '' when there is nothing to say — for the arguments named, which must be
     * ones `$tool` accepts. Opens with a space, so it appends to a finished sentence.
     *
     * @param  list<string>  $arguments
     */
    public static function gapClause(CallerClient $caller, Tool $tool, array $arguments): string
    {
        $caps = $caller->caps;
        // Shipped is decided by the INSTANCE: an operator tool registered under a shipped name is
        // in no client's history, and its types are nothing this class can phrase (r1-m1).
        if ($caps === null || $arguments === [] || ! BoardToolsRegistry::isShipped($tool)) {
            return '';
        }

        $name = $tool->name();
        $gap = [];
        foreach ($arguments as $argument) {
            $lacks = $caller->version === null
                // No usable version: the table cannot say what this client declares, only whether
                // every client able to call the tool does. Those that do need no clause.
                ? $caps->declares($caps->since($name), $name, $argument) !== ClientDeclaration::Yes
                : $caps->declares($caller->version, $name, $argument) === ClientDeclaration::No;
            if ($lacks) {
                $gap[] = "`{$argument}` (".self::typePhrase($tool->argumentTypes()[$argument]).'; first declared by client '.$caps->since($name, $argument).')';
            }
        }
        if ($gap === []) {
            return '';
        }

        $one = count($gap) === 1;
        $list = $one ? $gap[0] : implode(', ', array_slice($gap, 0, -1)).' and '.$gap[count($gap) - 1];
        $it = $one ? 'it' : 'them';
        // ⛔ TRUE WHETHER OR NOT THE CLIENT SENDS THE ARGUMENT (r1-m3). Every old `board_my_cards`
        // schema declares `additionalProperties: false`, so a client may refuse to send a key its
        // schema lacks; only the bridge's own acceptance is stated as fact.
        $accepts = "This bridge accepts {$it} if your client sends {$it} as typed here, but a client may not send an argument its schema does not list — if passing {$it} has no effect, update your channel client, which is the reliable fix.";

        if ($caller->version === null) {
            return " ⚠ This bridge could not read a client version for this call, so it cannot tell whether your tool schema offers {$list}. {$accepts}";
        }

        return " ⚠ Your channel client is version {$caller->version}, which does not declare {$list}, so your tool schema does not offer {$it}. {$accepts}";
    }

    /** One of the type tokens {@see Tool::argumentTypes()} may carry, as the clause spells it. */
    private static function typePhrase(string $type): string
    {
        return match ($type) {
            'integer' => 'an unquoted integer',
            'boolean' => 'unquoted true or false',
            'string' => 'a string',
            'string[]' => 'a list of strings',
            'integer|string' => 'an unquoted integer or a string',
            default => throw new UnexpectedValueException("argument type token `{$type}` has no phrase"),
        };
    }
}
