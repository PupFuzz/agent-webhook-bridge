<?php

namespace Tests\Feature\ClientUpdate;

use App\Bridge\Check\Checks\ClientPackSourceCheck;
use Tests\TestCase;

/**
 * `board_tools.client_pack_source` tells an operator to re-run a workflow BY NAME (DL-442
 * Decision 3). The name is a copy of `auto-tag-version.yml`'s `name:`, so this holds the two equal —
 * and holds that the workflow still runs the step a re-run exists to repeat.
 */
class ClientPackReleaseWorkflowTest extends TestCase
{
    private function workflow(): string
    {
        return (string) file_get_contents(base_path('.github/workflows/auto-tag-version.yml'));
    }

    public function test_the_leg_names_the_release_workflow_by_its_real_name(): void
    {
        $this->assertSame(1, preg_match('/^name: (.+)$/m', $this->workflow(), $m));
        $this->assertSame(trim($m[1]), ClientPackSourceCheck::RELEASE_WORKFLOW);
    }

    public function test_the_release_workflow_attaches_the_pack_for_its_own_tag(): void
    {
        $this->assertStringContainsString('python3 bin/release-client-pack.py --tag "v${VERSION}"', $this->workflow());
    }
}
