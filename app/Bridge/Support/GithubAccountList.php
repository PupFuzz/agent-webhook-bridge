<?php

namespace App\Bridge\Support;

/**
 * Whether a GitHub account (a payload's `sender`, a run's `triggering_actor`, …) is on a configured
 * list. Each entry matches EITHER the login, case-insensitively (`dependabot[bot]`), OR the numeric
 * account id, exactly (`49699333`) — ids are immutable where logins rename, so an operator can pin
 * the durable one; logins are what they actually know.
 */
final class GithubAccountList
{
    /**
     * @param  list<string>  $entries
     * @param  array<mixed>  $account  the account object, carrying `login` and `id`
     */
    public static function matches(array $entries, array $account): bool
    {
        $login = is_scalar($account['login'] ?? null) ? strtolower(trim((string) $account['login'])) : '';
        $id = is_scalar($account['id'] ?? null) ? trim((string) $account['id']) : '';

        foreach ($entries as $entry) {
            $e = trim($entry);
            if ($e === '') {
                continue;
            }
            if (($login !== '' && strtolower($e) === $login) || ($id !== '' && $e === $id)) {
                return true;
            }
        }

        return false;
    }
}
