<?php

namespace Tests\Unit\Validation;

use App\Bridge\Validation\ProviderName;
use App\Bridge\Validation\ScopeId;
use App\Bridge\Validation\SocketPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ValidatorsTest extends TestCase
{
    /**
     * Patterns must stay character-for-character identical to the Python
     * provisioner side (lib/validators.py), or a value the provisioner
     * accepts could be rejected by the receiver (or vice versa).
     */
    public function test_patterns_match_the_python_provisioner(): void
    {
        $this->assertSame('^[a-zA-Z0-9_-]+(\.[a-zA-Z0-9_-]+)*(/[a-zA-Z0-9_-]+(\.[a-zA-Z0-9_-]+)*)*$', ScopeId::PATTERN);
        $this->assertSame('^[a-z0-9_]+$', ProviderName::PATTERN);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function scopeCases(): array
    {
        return [
            'kanban numeric' => ['5', true],
            'github org/repo' => ['acme-corp/widget', true],
            'dotted slug' => ['a.b.c', true],
            'mixed case allowed' => ['Org/Repo', true],
            'traversal rejected' => ['../etc/passwd', false],
            'double slash rejected' => ['a//b', false],
            'leading slash rejected' => ['/lead', false],
            'trailing slash rejected' => ['trail/', false],
            'space rejected' => ['a b', false],
            'empty rejected' => ['', false],
        ];
    }

    #[DataProvider('scopeCases')]
    public function test_scope_id_matching(string $value, bool $expected): void
    {
        $this->assertSame($expected, ScopeId::matches($value));
    }

    public function test_provider_name_matching(): void
    {
        $this->assertTrue(ProviderName::matches('kanban'));
        $this->assertTrue(ProviderName::matches('git_hub'));
        $this->assertFalse(ProviderName::matches('Kanban'));   // uppercase
        $this->assertFalse(ProviderName::matches('git-hub'));  // hyphen
        $this->assertFalse(ProviderName::matches(''));
    }

    public function test_trailing_newline_rejected_dollar_endonly(): void
    {
        // DL-014 (`D` modifier): `$` must not match before a trailing "\n", or a
        // second line could slip past the anchor.
        $this->assertFalse(ProviderName::matches("github\n"));
        $this->assertFalse(ProviderName::matches("github\nevil"));
        $this->assertFalse(ScopeId::matches("5\n"));
        $this->assertFalse(ScopeId::matches("org/repo\n"));
        $this->assertFalse(ScopeId::matches("5\n../etc/passwd"));
    }

    public function test_socket_path_validation(): void
    {
        $this->assertTrue(SocketPath::isValid('/run/user/1000/agent-webhook-bridge.sock'));
        $this->assertFalse(SocketPath::isValid(''));               // empty
        $this->assertFalse(SocketPath::isValid('relative/path'));  // not absolute
        $this->assertFalse(SocketPath::isValid('/a/../b'));        // traversal segment
        $this->assertFalse(SocketPath::isValid("/a/\x00/b"));      // null byte
    }
}
