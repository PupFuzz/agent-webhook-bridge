<?php

namespace Tests\Feature\Config;

use Tests\Support\SourceScan;
use Tests\TestCase;

/**
 * `identity.kanban_user_id` is RETIRED (card#11172 / DL-450): the coord roster is the one source
 * of an agent's kanban user id, and nothing at runtime reads the YAML key — not even as a fallback.
 * These pins hold the reader set, so a new reader has to come here and say why.
 *
 * WHAT THEY SEE: non-comment lines in `app/` naming the parsed property, or reading the key out
 * of an array (`['kanban_user_id']`). WHAT THEY DO NOT: a reader reaching the YAML through
 * `AgentConfig::$raw` with a key built at runtime, or one reading the file itself — neither shape
 * exists today, and a dynamic one is the evasion every source pin in this suite names. Each pin
 * was seen to red on a probe class in `app/` reading the property, and then the key.
 */
class RetiredKanbanUserIdReaderTest extends TestCase
{
    public function test_only_the_bridge_check_migration_leg_reads_the_retired_property(): void
    {
        $this->assertSame(
            ['Bridge/Check/Checks/AgentKanbanUserRosterCheck.php', 'Bridge/Support/IdentityConfig.php'],
            $this->filesWhereCode(static fn (string $line): bool => str_contains($line, 'retiredKanbanUserId')),
            'a new reader of the retired identity.kanban_user_id — the runtime reads the coord roster (AgentKanbanUsers), never the YAML copy',
        );
    }

    /**
     * The key READ out of a decoded mapping: the identity parse, and the ROSTER's own field (a
     * different file's key of the same name). A label or a refused-argument name spelling the key
     * is not a read and is not matched.
     */
    public function test_the_key_is_read_out_of_a_mapping_only_where_ruled(): void
    {
        $this->assertSame(
            ['Bridge/Support/IdentityConfig.php', 'Bridge/Support/RosterKanbanUser.php'],
            $this->filesWhereCode(static fn (string $line): bool => str_contains($line, "['kanban_user_id']")),
        );
    }

    /**
     * @param  callable(string): bool  $matches
     * @return list<string>
     */
    private function filesWhereCode(callable $matches): array
    {
        $files = [];
        foreach (SourceScan::appFiles() as $path) {
            foreach (SourceScan::codeLines((string) file_get_contents($path)) as $line) {
                if ($matches($line)) {
                    $files[] = SourceScan::relativeToApp($path);
                    break;
                }
            }
        }
        sort($files);

        return $files;
    }
}
