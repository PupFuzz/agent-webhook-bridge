<?php

namespace App\Bridge\Support;

/**
 * Parses a comment body's `TO:` line for **comment-level recipient filtering**
 * (#2173). The bridge keeps recipient-addressing *policy* in the operator's
 * classifier (DL-022) — this is just the shared parse so every classifier
 * doesn't re-implement it. See docs/customization.md § Comment-level recipient
 * filtering for the usage pattern.
 *
 * A `TO:` line is a line whose trimmed text begins with `to:` (case-insensitive),
 * e.g. `TO: agentB, agentC` or `TO: all`. The first such line wins. Matching is
 * case-insensitive; the `all` keyword addresses every agent.
 */
final class RecipientAddressing
{
    /**
     * Whether a comment body's `TO:` line addresses $agentName:
     *  - `true`  — a `TO:` line names $agentName (or `all`)
     *  - `false` — a `TO:` line names recipients, but not this agent
     *  - `null`  — no usable `TO:` line; the caller should FALL BACK to its
     *              existing behavior (e.g. issue/card labels), NOT suppress
     *
     * The three-state return is deliberate: a classifier does
     * `match (RecipientAddressing::addresses($body, $agent)) { true => wake,
     * false => skip, null => labelFallback() }`.
     */
    public static function addresses(string $commentBody, string $agentName): ?bool
    {
        $recipients = self::recipients($commentBody);
        if ($recipients === null) {
            return null;
        }

        $agent = strtolower(trim($agentName));

        return in_array('all', $recipients, true) || in_array($agent, $recipients, true);
    }

    /**
     * The recipient names from the first `TO:` line, lowercased + trimmed, or
     * `null` when there is no `TO:` line or it names nobody (a bare/empty `TO:`
     * is treated as absent — it must not silently suppress everyone). `TODO:`
     * does not match (the `:` must follow `to`).
     *
     * @return list<string>|null
     */
    public static function recipients(string $commentBody): ?array
    {
        foreach (preg_split('/\R/', $commentBody) ?: [] as $line) {
            $trimmed = strtolower(trim($line));
            if (! str_starts_with($trimmed, 'to:')) {
                continue;
            }
            $names = array_values(array_filter(array_map(
                static fn (string $n): string => trim($n),
                explode(',', substr($trimmed, 3))
            ), static fn (string $n): bool => $n !== ''));

            return $names === [] ? null : $names;
        }

        return null;
    }

    /**
     * The author named on the first `FROM:` line of a comment body, lowercased +
     * trimmed, or `null` when there is no `FROM:` line (a bare/empty `FROM:` is
     * treated as absent). Symmetric with {@see recipients} — together they let a
     * classifier route a comment by the **body's own** `TO:`/`FROM:` lines, which
     * is the authoritative direction, rather than a parent issue's labels (those
     * freeze at thread-open and silently drop a reply that reverses direction —
     * the single most common shared-identity routing footgun). `FROMAGE:` and
     * other words do not match (the `:` must immediately follow `from`).
     */
    public static function author(string $commentBody): ?string
    {
        foreach (preg_split('/\R/', $commentBody) ?: [] as $line) {
            $trimmed = strtolower(trim($line));
            if (! str_starts_with($trimmed, 'from:')) {
                continue;
            }
            $name = trim(substr($trimmed, 5));

            return $name === '' ? null : $name;
        }

        return null;
    }
}
