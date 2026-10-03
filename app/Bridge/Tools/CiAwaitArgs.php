<?php

namespace App\Bridge\Tools;

use App\Bridge\Exceptions\ToolRefusalException;

/**
 * The argument rules `ci_await` and `ci_await_cancel` share (card#11200 / DL-452), so the two
 * tools cannot come to disagree about which head an argument names.
 */
final class CiAwaitArgs
{
    /** `owner/name`, each segment GitHub-shaped; `.` and `..` are not repo names. */
    private const REPO = '#\A(?!\.\.?/)[A-Za-z0-9_.-]{1,100}/(?!\.\.?\z)[A-Za-z0-9_.-]{1,100}\z#';

    private const FULL_SHA = '/\A[0-9a-fA-F]{40}\z/';

    /**
     * Arguments that try to say WHOSE await this is. Message quality only — the accept set is the
     * boundary, and the await is always the calling seat's.
     *
     * @var list<string>
     */
    public const IDENTITY_ARGS = ['agent', 'agent_name', 'seat', 'seat_id', 'identity', 'user', 'user_id', 'kanban_user_id', 'as', 'for', 'owner'];

    public static function identityReason(string $key): ?string
    {
        return in_array(strtolower($key), self::IDENTITY_ARGS, true)
            ? "`{$key}` is not an argument here — an await always belongs to YOU, the seat your call authenticated as, and no argument can name another."
            : null;
    }

    /** @param  array<string, mixed>  $args */
    public static function repo(array $args, string $tool): string
    {
        $repo = $args['repo'] ?? null;
        if (! is_string($repo) || preg_match(self::REPO, $repo) !== 1) {
            throw new ToolRefusalException("{$tool}: `repo` is required and must be a GitHub repository as `owner/name` (e.g. `octo-org/widgets`). Nothing was stored.", reason: 'bad_arguments');
        }

        return $repo;
    }

    /**
     * The full 40-hex SHA, lower-cased. ⛔ An ABBREVIATED SHA is refused, never expanded: GitHub's
     * run list filters on the exact `head_sha` and answers an abbreviation with an empty list, which
     * would leave the await waiting until it expired.
     *
     * @param  array<string, mixed>  $args
     */
    public static function headSha(array $args, string $tool): string
    {
        $sha = $args['head_sha'] ?? null;
        if (! is_string($sha) || preg_match(self::FULL_SHA, $sha) !== 1) {
            $abbreviated = is_string($sha) && preg_match('/\A[0-9a-fA-F]{4,39}\z/', $sha) === 1;

            throw new ToolRefusalException("{$tool}: `head_sha` is required and must be the FULL 40-character commit SHA".($abbreviated ? ' — `'.$sha.'` is abbreviated, and GitHub matches workflow runs on the full SHA only, so an abbreviated one would never settle. Use `git rev-parse <ref>`.' : '.').' Nothing was stored.', reason: 'bad_arguments');
        }

        return strtolower($sha);
    }
}
