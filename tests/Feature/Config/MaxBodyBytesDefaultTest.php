<?php

namespace Tests\Feature\Config;

use Tests\TestCase;

/**
 * The shipped receiver body cap, read from config/bridge.php with BRIDGE_MAX_BODY_BYTES unset —
 * what an install that sets no override runs (card#12576: the former 256 KiB default refused a
 * 300,739 B release push, and the fix must reach installs with no `.env` edit).
 */
class MaxBodyBytesDefaultTest extends TestCase
{
    public function test_an_install_with_no_override_accepts_one_mebibyte(): void
    {
        $saved = [getenv('BRIDGE_MAX_BODY_BYTES'), $_ENV['BRIDGE_MAX_BODY_BYTES'] ?? null, $_SERVER['BRIDGE_MAX_BODY_BYTES'] ?? null];
        putenv('BRIDGE_MAX_BODY_BYTES');
        unset($_ENV['BRIDGE_MAX_BODY_BYTES'], $_SERVER['BRIDGE_MAX_BODY_BYTES']);
        try {
            $config = require config_path('bridge.php');
        } finally {
            if ($saved[0] !== false) {
                putenv('BRIDGE_MAX_BODY_BYTES='.$saved[0]);
            }
            if ($saved[1] !== null) {
                $_ENV['BRIDGE_MAX_BODY_BYTES'] = $saved[1];
            }
            if ($saved[2] !== null) {
                $_SERVER['BRIDGE_MAX_BODY_BYTES'] = $saved[2];
            }
        }

        $this->assertSame(1048576, $config['max_body_bytes']);
    }
}
