<?php

namespace App\Bridge\Tools;

use UnexpectedValueException;

/**
 * Remedy text that accounts for the caller's channel client (card#10566 / DL-426): when a
 * sentence tells a caller to pass an argument its client is too old to have DECLARED, the
 * sentence is kept whole and a clause is appended saying so — the running version, the version
 * that first declared the argument, and the argument's type.
 *
 * ⛔ THE CLAUSE NEVER REMOVES THE ESCAPE. The channel server forwards arguments verbatim, so an
 * argument missing from an old client's schema still reaches the bridge when the caller passes it;
 * the clause exists because such a caller has no schema to tell it the argument exists or what
 * type it takes (a caller told only "raise `limit`" sends `"50"`). ⚠ That Claude Code forwards an
 * argument its schema does not list is INFERRED from the channel server's forwarding, not
 * measured — DL-426 records it as the residual.
 *
 * WHEN THERE IS NO CLAUSE: the client declares every argument named; or the table cannot answer
 * for a REPORTED version (one newer than any this checkout records, or not bare `X.Y.Z`) — it
 * cannot be shown to lack anything, so nothing is claimed; or the tool is one the table does not
 * record (an operator-registered tool); or the table is unreadable ({@see CallerClient}). The
 * declared case is byte-identical to the text before this existed.
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
     * cannot forget to account for it — a backticked name is exactly what a caller would try to
     * pass. A backticked span opening with an argument name counts (`include_terminal: true`).
     */
    public static function advise(CallerClient $caller, Tool $tool, string $how): string
    {
        preg_match_all('/`([a-z_][a-z0-9_]*)(?=[`:])/', $how, $m);
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
        if ($caps === null || $arguments === []) {
            return '';
        }

        $name = $tool->name();
        $gap = [];
        foreach ($arguments as $argument) {
            if (! $caps->knows($name, $argument)) {
                continue;   // an operator-registered tool: no client history to speak from
            }
            $lacks = $caller->version === null
                // No version: the table cannot say what this client declares, only whether every
                // client able to call the tool does. Those that do need no clause.
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
        [$it, $they, $them, $works] = $one ? ['it', 'it is', 'it', 'works'] : ['they', 'they are', 'them', 'work'];
        // A statement of fact, not an instruction to pass: a remedy may name an argument only to
        // say it does not apply here (the coord window's `stage`), and the clause must not
        // contradict it.
        $forwarded = "still {$works} when passed as typed here — the channel server forwards arguments verbatim — and updating the channel client adds {$them} to the schema.";

        if ($caller->version === null) {
            return ' ⚠ Your channel client reported no version (it is older than '.$caps->feature('client_version_report')
                .", or it cannot read its own package.json), so this bridge cannot tell whether your tool schema shows {$list}."
                ." If {$they} missing there, {$it} {$forwarded}";
        }

        return " ⚠ Your channel client is version {$caller->version}, which does not declare {$list}, so your tool schema does not show {$them}. "
            .ucfirst($it)." {$forwarded}";
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
