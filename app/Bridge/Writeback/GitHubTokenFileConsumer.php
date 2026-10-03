<?php

namespace App\Bridge\Writeback;

/**
 * A runtime leg that reaches GitHub with a token FILE and nothing else — the file
 * `GitHubTokenResolver::resolveFor()` gives each repo without `GH_TOKEN`: the repo's
 * `write_token_path`, else the file the coord credential store names for it, else the single
 * `<secret_dir>/github/token` / `providers.github.token_path` (card#11201, DL-456).
 *
 * WHY A LEG DECLARES ITSELF. With no readable file such a leg drops every GitHub request it
 * decides on, and logs it, and that is all: nothing retries on its own and nothing in the
 * delivery's answer moves. `bridge:check`'s `github.token_file` leg is the one surface that
 * says so before the drops start, and it can name only the legs it knows about. Each consumer
 * therefore states, beside its own code, its name and when this install has it switched on;
 * the check reads the declarations and keeps no list of its own reasons.
 *
 * ⛔ WHICH CLASSES ARE CONSUMERS IS NOT DECIDED HERE. The registry is
 * `App\Bridge\Check\Checks\GitHubTokenFileCheck::CONSUMERS` (NAMED, not `{@see}`-linked: pint
 * turns a docblock FQCN into a real `use`, and this namespace depends on no check), and
 * `GitHubTokenFileConsumerRegistryTest` reds when a class under `app/` calls `->resolveFromFile(`
 * or `->resolveFor(` without being registered there or ruled CLI-only in that test.
 *
 * The methods are STATIC because each answers from config alone: the check must not construct a
 * handler, with its clients and its alert channel, to ask whether it is switched on.
 */
interface GitHubTokenFileConsumer
{
    /** The leg as an operator reads it in a finding, with the decision that introduced it. */
    public static function fileTokenLeg(): string;

    /**
     * The repos this install has the leg switched on for, in their configured spelling; an empty
     * list when it is off. $writeback is null when this install has no `writeback.json`.
     *
     * @return list<string>
     */
    public static function fileTokenRepos(?WritebackConfig $writeback): array;

    /**
     * Whether the leg WRITES to GitHub (a comment, a label) rather than only reading it. A token
     * that can read and not write leaves a writing leg as inert as no token at all.
     */
    public static function fileTokenWrites(): bool;
}
