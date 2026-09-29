<?php

namespace Tests\Unit\Support;

use App\Bridge\Support\RosterKanbanUser;
use Tests\Support\MirrorParityTestCase;

/**
 * THE CHECK BEHIND THE BRIDGE'S PORT OF THE TOOLKIT'S ROSTER READ (card#10869).
 *
 * `App\Bridge\Support\RosterKanbanUser` answers which kanban user the coord roster — the single
 * store (card#10868 Q1) — says one seat is on one board instance. The rule's home is the
 * agent-board-toolkit's `kb_owner_resolve` / `KB_JQ_ROSTER`. This class holds the port against the
 * published corpus, both ways; `bin/kb-owner-parity.sh` holds the toolkit to the same file.
 *
 * The port answers an object; the published observable is its `{uid, why}` pair, which is what
 * {@see invoke} projects. `found` is bridge-local message detail and is outside the contract (the
 * corpus's `not_checked_by_this_repo` says so where the far end reads it).
 */
class RosterKanbanUserParityTest extends MirrorParityTestCase
{
    protected static function mirrorClass(): string
    {
        return RosterKanbanUser::class;
    }

    protected static function corpusPath(): string
    {
        return 'docs/kb-roster-uid-parity-corpus.json';
    }

    /**
     * A vector whose config is a STRING carries the coord config file's exact TEXT: a JSON number
     * literal such as `7.00` or `7e0` has no other faithful spelling, because every decoder in
     * the chain — PHP's here, jq's in the far-end runner — normalises it. Decoding it here is the
     * same `json_decode` the check's own read performs on the file.
     */
    protected static function invoke(string $method, array $args): mixed
    {
        if (is_string($args[0] ?? null)) {
            $args[0] = json_decode($args[0], true);
        }
        $answer = parent::invoke($method, $args);

        return $answer instanceof RosterKanbanUser ? ['uid' => $answer->userId, 'why' => $answer->why] : $answer;
    }

    protected static function comparatorControl(): array
    {
        return [
            'method' => 'lookUp',
            'args' => [['roster' => [['name' => 'impl', 'kanban_user_id' => ['kanban.example.com' => '7']]]], 'impl', 'kanban.example.com'],
            'right' => ['uid' => null, 'why' => 'bad'],
            'wrong' => ['uid' => 7, 'why' => null],
            'wrong_fragment' => '"why":"bad"',
        ];
    }

    public function test_the_corpus_ships_a_runner_the_far_end_can_execute(): void
    {
        $this->assertSame('bin/kb-owner-parity.sh', static::corpus()['mirror']['runner'] ?? null, 'the toolkit authority is BASH, so a prose recipe is not enough — the far end needs a program, and this corpus must name it.');
    }
}
