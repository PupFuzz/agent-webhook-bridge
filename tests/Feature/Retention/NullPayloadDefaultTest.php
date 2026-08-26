<?php

namespace Tests\Feature\Retention;

use Tests\Support\CheckGolden\GoldenInstall;
use Tests\TestCase;

/**
 * The SHIPPED default of the payload-nulling leg (DL-315, rt#380).
 *
 * ⛔ THIS READS THE REPO'S OWN config/bridge.php, NOT a test-overridden value —
 * {@see GoldenInstall} pins its own retention keys for
 * determinism, so every golden fixture would keep passing if this default silently
 * reverted to ''. The whole point of rt#380 is that the leg was OFF on every install
 * that never tuned it; a test that cannot see the default cannot catch that coming back.
 */
class NullPayloadDefaultTest extends TestCase
{
    public function test_the_payload_nulling_leg_ships_on_at_seven_days(): void
    {
        $default = require base_path('config/bridge.php');

        $this->assertSame(
            '7d',
            $default['retention']['null_payloads_older_than'],
            'the payload-nulling leg must ship ON: two installs measured 894 MB and 369 MB of '
            .'payload under a retention that was working correctly, and neither could discover it '
            .'without running SUM(LENGTH(payload)) unprompted (rt#380)',
        );
    }

    public function test_the_row_window_is_not_shortened_to_match(): void
    {
        $default = require base_path('config/bridge.php');

        $this->assertSame(
            '30d',
            $default['retention']['older_than'],
            'payload window short, ROW window long — shortening the row window to match saves ~16 MB '
            .'and loses 14% of distinct event types, including gaps bridge:check REPORTS, which would '
            .'then read as fixed rather than lost (rt#380, measured)',
        );
    }
}
