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

        $this->assertSame(137, $id->kanbanUserId);
        $this->assertSame(9001, $id->githubUserId);
        $this->assertSame('pm-bot', $id->githubLogin);
    }

    public function test_from_array_nulls_missing_or_non_numeric(): void
    {
        $id = IdentityConfig::fromArray(['github_user_id' => 'not-a-number']);

        $this->assertNull($id->kanbanUserId);
        $this->assertNull($id->githubUserId);   // non-numeric → null
        $this->assertNull($id->githubLogin);
    }

    public function test_self_ids_are_the_present_numeric_ids_as_strings(): void
    {
        $this->assertSame(['137', '9001'], (new IdentityConfig(137, 9001))->selfIds());
        $this->assertSame(['137'], (new IdentityConfig(kanbanUserId: 137))->selfIds());
        $this->assertSame([], (new IdentityConfig)->selfIds());   // github_login is NOT a self-echo id
    }
}
