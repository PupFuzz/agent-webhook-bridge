<?php

namespace Tests\Unit\Tools;

use App\Bridge\Tools\BoardCardProjection;
use Tests\TestCase;

/**
 * {@see BoardCardProjection::FIELDS} is the vocabulary a read's `fields` argument selects from, and
 * it is a restatement of the keys the projection emits — so it is held EQUAL to them, derived from
 * a projection run rather than listed here. A key added to the projection and not to FIELDS would be
 * unselectable (and silently dropped from every `fields`-bearing answer); a FIELDS entry the
 * projection no longer emits would be accepted and answer nothing.
 */
class BoardCardProjectionTest extends TestCase
{
    /** @return list<string> every key the projection and its augmenters can emit, with the lane, the position and the body all present */
    private static function emittedKeys(): array
    {
        $row = ['id' => 1, 'swimlane_id' => 4, 'position' => 2.5, 'description' => 'x'];
        $keys = array_keys(BoardCardProjection::withPosition(BoardCardProjection::withSwimlane(BoardCardProjection::project($row, [], 100), $row), $row));

        // `description_truncated` rides with `description` and is not selectable on its own.
        return array_values(array_diff($keys, ['description_truncated']));
    }

    public function test_the_selectable_fields_are_exactly_the_keys_the_projection_emits(): void
    {
        $emitted = self::emittedKeys();
        $fields = BoardCardProjection::FIELDS;
        sort($emitted);
        sort($fields);

        $this->assertSame($emitted, $fields);
    }

    public function test_the_default_leaves_out_only_the_opt_in_body(): void
    {
        $this->assertSame(
            array_values(array_diff(BoardCardProjection::FIELDS, ['description'])),
            BoardCardProjection::defaultFields(),
        );
        $this->assertNotContains(BoardCardProjection::OPT_IN_FIELD, BoardCardProjection::defaultFields());
    }

    public function test_selecting_the_body_keeps_its_truncation_flag_and_nothing_unselected(): void
    {
        $card = ['id' => 1, 'name' => 'n', 'description' => 'd', 'description_truncated' => false];

        $this->assertSame(['description' => 'd', 'description_truncated' => false], BoardCardProjection::select($card, ['description']));
        $this->assertSame(['id' => 1], BoardCardProjection::select($card, ['id']));
    }

    public function test_a_key_the_card_does_not_carry_stays_absent_rather_than_null(): void
    {
        // A row with no readable lane field carries no `swimlane_id` — selecting it must not mint a
        // null, which would call the card laneless.
        $card = BoardCardProjection::withSwimlane(BoardCardProjection::project(['id' => 1], [], null), ['id' => 1]);

        $this->assertArrayNotHasKey('swimlane_id', BoardCardProjection::select($card, ['id', 'swimlane_id']));
    }

    public function test_position_is_a_float_when_kanban_sends_one_and_absent_when_it_does_not(): void
    {
        $this->assertSame(['position' => 1024.0], BoardCardProjection::withPosition([], ['position' => 1024]));
        $this->assertSame(['position' => 1.5], BoardCardProjection::withPosition([], ['position' => 1.5]));
        $this->assertSame([], BoardCardProjection::withPosition([], ['position' => null]));
        $this->assertSame([], BoardCardProjection::withPosition([], ['position' => '3']), 'a decorated value is not a position');
        $this->assertSame([], BoardCardProjection::withPosition([], []));
    }
}
