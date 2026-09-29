<?php

namespace Tests\Unit\Support;

use App\Bridge\Support\KanbanInstanceKey;
use Tests\Support\MirrorParityTestCase;

/**
 * THE CHECK BEHIND THE BRIDGE'S PORT OF THE TOOLKIT'S `kb_url_host` (card#10869).
 *
 * `App\Bridge\Support\KanbanInstanceKey` turns the kanban API base into the board-instance key the
 * coord roster's `kanban_user_id` object is keyed by. The rule's home is the agent-board-toolkit's
 * `bin/_kb-board-lib.sh`; a host the two spell differently is a seat looked up under a key nobody
 * wrote. This class holds the port against the published corpus, both ways; the other half — does
 * the TOOLKIT still answer the same — is `bin/kb-owner-parity.sh`, which sources a toolkit checkout
 * and runs the same file. Every leg is owned by {@see MirrorParityTestCase}.
 */
class KanbanInstanceKeyParityTest extends MirrorParityTestCase
{
    protected static function mirrorClass(): string
    {
        return KanbanInstanceKey::class;
    }

    protected static function corpusPath(): string
    {
        return 'docs/kb-instance-key-parity-corpus.json';
    }

    protected static function comparatorControl(): array
    {
        return [
            'method' => 'of',
            'args' => ['https://kanban.example.com.:8443/api'],
            'right' => 'kanban.example.com',
            'wrong' => 'kanban.example.com.',
            'wrong_fragment' => '"kanban.example.com"',
        ];
    }

    public function test_the_corpus_ships_a_runner_the_far_end_can_execute(): void
    {
        $this->assertSame('bin/kb-owner-parity.sh', static::corpus()['mirror']['runner'] ?? null, 'the toolkit authority is BASH, so a prose recipe is not enough — the far end needs a program, and this corpus must name it.');
    }
}
