<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Hermetic intent-staging baseline. A real per-agent bridge deployment
        // exports BRIDGE_INBOX_LAYOUT=per-agent (and may export BRIDGE_STATE_DIR);
        // Dotenv won't override an already-set shell var, and phpunit's <env
        // force> doesn't reach the getenv() layer env() reads — so the export
        // leaks in, IntentLog::stage() writes to a per-agent file the tests don't
        // read back, and the suite goes red on a standard operator host while CI
        // (no such export) stays green. A runtime config() override is
        // authoritative. Tests that exercise a non-shared layout set
        // bridge.inbox_layout AFTER parent::setUp(), so they override this default.
        config([
            'bridge.inbox_layout' => 'shared',
            'bridge.state_dir' => null,
        ]);
    }
}
