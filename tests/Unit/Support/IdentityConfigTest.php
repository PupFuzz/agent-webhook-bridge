<?php

namespace Tests\Unit\Support;

use App\Bridge\Support\IdentityConfig;
use PHPUnit\Framework\TestCase;

class IdentityConfigTest extends TestCase
{
    public function test_from_array_coerces_ids_and_login(): void
    {
        $id = IdentityConfig::fromArray([
            'kanban_user_id' => '137',   // numeric string → int
            'github_user_id' => 9001,
            'github_login' => 'pm-bot',
        ]);

        $this->assertSame(137, $id->retiredKanbanUserId, 'still parsed, for bridge:check\'s migration rows alone (DL-450)');
        $this->assertSame(9001, $id->githubUserId);
        $this->assertSame('pm-bot', $id->githubLogin);
    }

    public function test_from_array_nulls_missing_or_non_numeric(): void
    {
        $id = IdentityConfig::fromArray(['github_user_id' => 'not-a-number']);

        $this->assertNull($id->retiredKanbanUserId);
        $this->assertNull($id->githubUserId);   // non-numeric → null
        $this->assertNull($id->githubLogin);
    }

    /**
     * Only the GITHUB id is seeded from the YAML: the kanban id is the coord roster's, seeded at
     * dispatch for kanban events (DL-450) — a retired YAML kanban id is NOT a self-echo id.
     */
    public function test_self_github_ids_are_the_github_id_alone(): void
    {
        $this->assertSame(['9001'], (new IdentityConfig(137, 9001))->selfGithubIds());
        $this->assertSame([], (new IdentityConfig(retiredKanbanUserId: 137))->selfGithubIds());
        $this->assertSame([], (new IdentityConfig)->selfGithubIds());   // github_login is NOT a self-echo id
    }
}
