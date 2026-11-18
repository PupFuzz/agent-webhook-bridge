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
     * The framework's `looks_like_pasted_secret` examples, plus each prefix, the length edge, and key
     * names that hold a token only past their first character. The bridge matches the framework's
     * `VENDOR_TOKENS` rows at the start of the value with a body of at least one character, and
     * `AWS_KEY_ID` at the start; the framework matches the bare prefix, so the one known difference
     * is a bare `ghp_` (flagged there, not here). Nothing here re-measures the far end.
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
            'a bare prefix, no body' => ['ghp_', false],
            'an AWS access key id' => [self::awsKeyId(), true],
            'an AWS key id with a longer upper-case run' => [self::awsKeyId().'X', true],
            'an AWS key id followed by a lower-case character' => [self::awsKeyId().'x', true],
            'an AWS key id one character short' => [substr(self::awsKeyId(), 0, -1), false],
            'a lower-cased AWS key id' => [strtolower(self::awsKeyId()), false],
            'an A3T AWS key id' => ['A3TX'.substr(self::awsKeyId(), 4), true],
            'an sk- key' => ['sk-'.str_repeat('a', 16), true],
            'an sk- key not at the start of the value' => ['%2Fsk-'.str_repeat('a', 16), false],
            'an sk- key with a 15-character body' => ['sk-'.str_repeat('a', 15), false],
            'sk- inside the word risk-' => ['risk-'.str_repeat('a', 16), false],
            'sk- inside the word task-' => ['task-'.str_repeat('a', 16), false],
            'a key name holding a GitHub prefix' => ['deploy_'.'ghp'.'_reader', false],
            'a key name holding ghs_ inside a word' => ['laugh'.'s_bot', false],
            'a key name holding an sk- key' => ['my_'.'sk-'.str_repeat('a', 16), false],
            'an AWS key id not at the start of the value' => ['x'.self::awsKeyId(), false],
        ];
    }

    /**
     * One representative per `PastedSecretShape::VENDOR_TOKENS` row, plus every prefix the list
     * before it held (`ghp_`, `gho_`, `ghu_`, `ghs_`, `ghr_`, `github_pat_`, `glpat-`, `xoxb-`,
     * `xoxp-`), which the rows must still cover.
     *
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function vendorTokenRows(): array
    {
        return [
            'gh-token-family' => ['gh-token-family', ['ghp_abc', 'gho_abc', 'ghu_abc', 'ghs_abc', 'ghr_abc']],
            'gh-fine-grained-pat' => ['gh-fine-grained-pat', ['github_pat_abc']],
            'gitlab-pat' => ['gitlab-pat', ['glpat-abc']],
            'slack-token' => ['slack-token', ['xoxb-abc', 'xoxp-abc']],
            'sk-api-key' => ['sk-api-key', ['sk-'.str_repeat('a', 16)]],
        ];
    }

    /** @param list<string> $tokens */
    #[DataProvider('vendorTokenRows')]
    public function test_each_vendor_token_row_recognises_its_tokens(string $row, array $tokens): void
    {
        foreach ($tokens as $token) {
            $this->assertTrue(PastedSecretShape::looksLikePastedSecret($token), "{$row}: {$token}");
            $this->assertMatchesRegularExpression(PastedSecretShape::vendorTokenPattern($row), $token, $row);
        }
    }

    public function test_every_vendor_token_row_has_a_representative(): void
    {
        $this->assertSame(array_keys(PastedSecretShape::VENDOR_TOKENS), array_keys(self::vendorTokenRows()));
    }

    /** AWS's documented example key id, assembled so no committed line reads as a credential. */
    private static function awsKeyId(): string
    {
        return 'AKIA'.'IOSFODNN7'.'EXAMPLE';
    }

    #[DataProvider('pastedSecretShapes')]
    public function test_a_credential_shaped_value_is_recognised_as_the_framework_does_except_a_bare_prefix(string $value, bool $flagged): void
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
