<?php

namespace Tests\Feature\Support;

use App\Bridge\Support\BridgePaths;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * `BridgePaths::unseenInboxLines()` — the ONE definition of "unseen" (card#7332), extracted
 * from `bridge:inbox` at its second real caller (the DL-306 standup digest).
 *
 * It gets its own tests because the caller-level ones cannot state the rule. Measured by
 * mutation: with the duplicate-id collapse removed, `bridge:inbox`'s own
 * `test_inbox_collapses_duplicate_ids_on_read` stayed GREEN — the cursor advance
 * `array_unique()`s its ids, so it records one id whether the line was rendered once or
 * twice, and an `expectsOutputToContain()` is satisfied by a doubled line. That test is a
 * NAME for the rule; these are the guard, and they assert on the COUNT, which is the only
 * quantity that discriminates.
 */
class BridgePathsUnseenInboxLinesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/unseen-lines-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/state');
        config(['bridge.config_dir' => $this->dir, 'bridge.state_dir' => $this->dir.'/state']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /** @param list<array<string, mixed>> $lines */
    private function writeShared(array $lines): void
    {
        File::put($this->dir.'/state/inbox.jsonl', implode('', array_map(
            fn (array $l): string => json_encode($l)."\n",
            $lines,
        )));
    }

    public function test_a_duplicate_id_is_one_unseen_line(): void
    {
        $this->writeShared([
            ['id' => 'e:pm:0', 'agent' => 'pm', 'kind' => 'new_card'],
            ['id' => 'e:pm:0', 'agent' => 'pm', 'kind' => 'new_card'],
        ]);

        $this->assertCount(1, BridgePaths::unseenInboxLines('pm'));
    }

    public function test_the_first_of_a_duplicate_pair_is_the_one_kept(): void
    {
        // Not cosmetic: the pair is one intent staged twice by a partial-staging
        // redelivery, and the FIRST carries the ts derived from the event's received_at.
        $this->writeShared([
            ['id' => 'e:pm:0', 'agent' => 'pm', 'kind' => 'new_card', 'summary' => 'first'],
            ['id' => 'e:pm:0', 'agent' => 'pm', 'kind' => 'new_card', 'summary' => 'second'],
        ]);

        $this->assertSame('first', BridgePaths::unseenInboxLines('pm')[0]['summary']);
    }

    public function test_a_line_the_cursor_has_consumed_is_not_unseen(): void
    {
        $this->writeShared([
            ['id' => 'a:pm:0', 'agent' => 'pm', 'kind' => 'new_card'],
            ['id' => 'b:pm:0', 'agent' => 'pm', 'kind' => 'new_card'],
        ]);
        File::put($this->dir.'/state/inbox-seen-pm.json', (string) json_encode(['a:pm:0']));

        $this->assertSame(['b:pm:0'], array_column(BridgePaths::unseenInboxLines('pm'), 'id'));
    }

    public function test_another_agents_line_is_not_this_agents_unseen(): void
    {
        $this->writeShared([
            ['id' => 'a:pm:0', 'agent' => 'pm', 'kind' => 'new_card'],
            ['id' => 'a:other:0', 'agent' => 'other', 'kind' => 'new_card'],
        ]);

        $this->assertSame(['a:pm:0'], array_column(BridgePaths::unseenInboxLines('pm'), 'id'));
    }

    public function test_a_line_with_no_string_id_is_dropped_rather_than_unseen_forever(): void
    {
        // Nothing could ever mark it seen, so counting it would make every later digest
        // and every later bridge:inbox report a backlog that cannot be worked off.
        $this->writeShared([
            ['agent' => 'pm', 'kind' => 'new_card'],
            ['id' => 42, 'agent' => 'pm', 'kind' => 'new_card'],
            ['id' => 'ok:pm:0', 'agent' => 'pm', 'kind' => 'new_card'],
        ]);

        $this->assertSame(['ok:pm:0'], array_column(BridgePaths::unseenInboxLines('pm'), 'id'));
    }

    public function test_a_missing_inbox_is_no_unseen_lines_rather_than_an_error(): void
    {
        $this->assertSame([], BridgePaths::unseenInboxLines('pm'));
    }

    public function test_the_shared_inbox_is_read_whole_when_no_agent_is_named(): void
    {
        // agent === null is bridge:inbox's no---agent case: the whole shared file, not one
        // agent's slice.
        $this->writeShared([
            ['id' => 'a:pm:0', 'agent' => 'pm', 'kind' => 'new_card'],
            ['id' => 'a:other:0', 'agent' => 'other', 'kind' => 'new_card'],
        ]);

        $this->assertCount(2, BridgePaths::unseenInboxLines(null));
    }
}
