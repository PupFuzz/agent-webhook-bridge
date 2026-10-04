<?php

namespace Tests\Unit\Support;

use App\Bridge\Support\PastedSecretShape;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The one display rule for a configured value that may be a pasted credential — a store name or a
 * path setting (card#11208, card#11261) — against the framework's `looks_like_pasted_secret`.
 */
class PastedSecretShapeTest extends TestCase
{
    /**
     * The framework's `looks_like_pasted_secret` examples, plus each prefix and the length edge.
     * Agreement with `coord_credentials.py` was measured once by feeding these same values to it;
     * nothing here re-measures the far end.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function pastedSecretShapes(): array
    {
        return [
            'a classic PAT' => ['ghp_abc', true],
            'a fine-grained PAT' => ['github_pat_abc', true],
            'an upper-cased prefix' => ['GHP_abc', true],
            'a GitLab token' => ['glpat-abc', true],
            'a Slack bot token' => ['xoxb-abc', true],
            'a 24-character blob' => [str_repeat('a', 24), true],
            'a 23-character blob' => [str_repeat('a', 23), false],
            'a 24-character name with a dot' => [str_repeat('a', 20).'.txt', false],
            'the placeholder' => ['REPLACE_ME', false],
            'a home-relative path' => ['~/.config/coord/tok', false],
            'a Windows path' => ['C:\\creds\\tok', false],
            'a file name' => ['tok.txt', false],
            'a prefixed path' => ['/ghp_abc', false],
            'a short key name' => ['coordination', false],
            'blank' => ['  ', false],
        ];
    }

    #[DataProvider('pastedSecretShapes')]
    public function test_a_credential_shaped_value_is_recognised_as_the_framework_recognises_it(string $value, bool $flagged): void
    {
        $this->assertSame($flagged, PastedSecretShape::looksLikePastedSecret($value));
        $this->assertSame($flagged ? '<a credential-shaped name, '.PastedSecretShape::fingerprint($value).'>' : $value, PastedSecretShape::displayName($value));
        $this->assertSame($flagged ? '<a credential-shaped value, '.PastedSecretShape::fingerprint($value).'>' : $value, PastedSecretShape::displayPathSetting($value));
    }

    public function test_the_fingerprint_is_the_frameworks(): void
    {
        // pointer_fingerprint('ghp_abc') in coord_credentials.py
        $this->assertSame('sha256:'.substr(hash('sha256', 'ghp_abc'), 0, 8), PastedSecretShape::fingerprint('ghp_abc'));
    }
}
