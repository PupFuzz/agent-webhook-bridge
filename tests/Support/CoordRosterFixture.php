<?php

namespace Tests\Support;

use Illuminate\Support\Facades\File;

/**
 * A coord roster (`coordination.config.json`) written into a test's own temp dir, and the bridge
 * pointed at it through `bridge.coord_config_path` — the one place the bridge reads each agent's
 * kanban user id from (card#11172).
 *
 * The base `TestCase` already points that setting at an EMPTY roster, so a test that does not care
 * about kanban ids runs as a configured install with no seats; a test that does calls this.
 */
final class CoordRosterFixture
{
    /** The kanban host every fixture keys ids under: the host of the API base the suite uses. */
    public const HOST = 'kanban.example.com';

    /**
     * @param  array<string, int|array<string, mixed>|null>  $seats  seat name => its id on {@see HOST},
     *                                                               or the whole `kanban_user_id`
     *                                                               object, or null for a seat
     *                                                               entry carrying no such object
     * @return string the absolute path written
     */
    public static function configure(string $dir, array $seats): string
    {
        $roster = [];
        foreach ($seats as $name => $ids) {
            $entry = ['name' => $name];
            if ($ids !== null) {
                $entry['kanban_user_id'] = is_int($ids) ? [self::HOST => $ids] : $ids;
            }
            $roster[] = $entry;
        }

        return self::configureRaw($dir, (string) json_encode(['roster' => $roster], JSON_PRETTY_PRINT));
    }

    /**
     * Write $contents verbatim as the roster file and point the bridge at it. Written through a new
     * file and a rename, as the coord tooling replaces it, so a rewrite inside one test is always a
     * new inode to the reader's per-process cache (`CoordConfigFile` states the bound that avoids).
     */
    public static function configureRaw(string $dir, string $contents): string
    {
        File::ensureDirectoryExists($dir);
        $path = rtrim($dir, '/').'/coordination.config.json';
        File::put($path.'.tmp', $contents);
        rename($path.'.tmp', $path);
        config(['bridge.coord_config_path' => $path]);

        return $path;
    }
}
