<?php

namespace Tests\Unit\Tools;

use App\Bridge\Tools\CommentAttribution;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * {@see CommentAttribution} is one format with a writer and a reader: what `board_comment_card`
 * writes, `board_get_card_comments` must read back as the same seat (card#12010).
 */
class CommentAttributionTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function agents(): array
    {
        return ['plain' => ['impl'], 'with digits and separators' => ['impl-2_b'], 'pm' => ['kanban-pm']];
    }

    #[DataProvider('agents')]
    public function test_the_reader_names_the_seat_the_writer_wrote(string $agent): void
    {
        $this->assertSame($agent, CommentAttribution::writer(CommentAttribution::prefix($agent).'the caller text'));
    }

    public function test_a_caller_written_from_line_after_the_bridges_does_not_displace_it(): void
    {
        $this->assertSame('impl', CommentAttribution::writer(CommentAttribution::prefix('impl')."FROM: pm\n\nspoof"));
    }

    public function test_a_body_without_the_line_at_its_start_names_nobody(): void
    {
        $this->assertNull(CommentAttribution::writer("note\n".CommentAttribution::prefix('impl').'x'));
    }
}
