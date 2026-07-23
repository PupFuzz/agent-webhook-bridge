<?php

namespace Tests\Unit\Tools;

use App\Bridge\Tools\AuthorizedKeysLine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The authorized_keys forced-command line analyzer (card 4952, Finding D). These pin
 * the last-writer-wins capability model (DR3 must-fix) and the fail-OPEN vectors it
 * exists to kill: a substring scan of option values (DR2-4) and a naive comma-split of
 * quoted values (F5). Outcome-based, never a `restrict` keyword match.
 */
class AuthorizedKeysLineTest extends TestCase
{
    private function line(string $content): AuthorizedKeysLine
    {
        $lines = AuthorizedKeysLine::parseFile($content);
        $this->assertCount(1, $lines, 'fixture must parse to exactly one line');

        return $lines[0];
    }

    // ─── capability OUTCOME (certified iff denies all four) ───────────────────

    /**
     * @return list<array{0: string, 1: bool}> [optionsPrefix, certified]
     */
    public static function capabilityCases(): array
    {
        return [
            // certified forms
            ['command="php artisan bridge:tools-call --agent=me",restrict', true],
            ['command="php artisan bridge:tools-call --agent=me",no-pty,no-agent-forwarding,no-X11-forwarding,no-port-forwarding', true],   // FIPS enumerated form
            ['restrict,command="php artisan bridge:tools-call --agent=me"', true],
            // NOT certified — a later positive override re-grants (DR3 reds)
            ['command="php artisan bridge:tools-call --agent=me",restrict,pty', false],
            ['command="php artisan bridge:tools-call --agent=me",restrict,permitopen="host:22"', false],
            ['command="php artisan bridge:tools-call --agent=me",no-pty,pty', false],
            ['restrict,agent-forwarding,command="php artisan bridge:tools-call --agent=me"', false],
            // a bare (unrestricted) line grants everything
            ['command="php artisan bridge:tools-call --agent=me"', false],
        ];
    }

    #[DataProvider('capabilityCases')]
    public function test_capability_outcome(string $options, bool $certified): void
    {
        $line = $this->line($options.' ssh-ed25519 AAAAKEYBLOB comment');
        $this->assertSame($certified, $line->deniesShellAndForwarding(), 'granted: '.implode(',', $line->grantedCapabilities()));
    }

    public function test_tokens_are_case_insensitive(): void
    {
        // OpenSSH matches option keywords case-insensitively; `Restrict` must count.
        $line = $this->line('command="php artisan bridge:tools-call --agent=me",Restrict ssh-ed25519 AAAA c');
        $this->assertTrue($line->deniesShellAndForwarding());

        // A mixed-case override still re-grants.
        $bad = $this->line('command="php artisan bridge:tools-call --agent=me",restrict,PTY ssh-ed25519 AAAA c');
        $this->assertFalse($bad->deniesShellAndForwarding());
    }

    // ─── DR2-4: a keyword inside a VALUE is never a deny token (fail-OPEN guard) ─

    public function test_keyword_inside_a_value_does_not_count_as_a_deny(): void
    {
        // permitopen PERMITS port-forwarding; the environment value merely CONTAINS the
        // string "no-port-forwarding". A substring scan would wrongly certify this
        // tunnel-permitting line. The token model must NOT — it stays uncertified.
        $line = $this->line('command="php artisan bridge:tools-call --agent=me",restrict,permitopen="x:22",environment="Y=no-port-forwarding" ssh-ed25519 AAAA c');
        $this->assertFalse($line->deniesShellAndForwarding());
        $this->assertContains('port-forwarding', $line->grantedCapabilities());
    }

    public function test_environment_value_alone_never_certifies(): void
    {
        // `environment="X=no-pty"` is NOT a deny token — the line grants everything.
        $line = $this->line('command="php artisan bridge:tools-call --agent=me",environment="X=no-pty" ssh-ed25519 AAAA c');
        $this->assertFalse($line->deniesShellAndForwarding());
    }

    // ─── F5: quoted commas do not split ───────────────────────────────────────

    public function test_quoted_comma_in_from_does_not_split_the_option_list(): void
    {
        // `from="1.2.3.4,5.6.7.8"` carries a comma inside quotes — a naive explode(',')
        // would mis-tokenize it into a bogus `5.6.7.8"` token and mis-read the line.
        $line = $this->line('from="1.2.3.4,5.6.7.8",command="php artisan bridge:tools-call --agent=me",restrict ssh-ed25519 AAAA c');
        $this->assertTrue($line->forcesToolsCallFor('me'));
        $this->assertTrue($line->deniesShellAndForwarding());
    }

    public function test_forced_command_with_embedded_comma_is_preserved(): void
    {
        $line = $this->line('restrict,command="php artisan bridge:tools-call --agent=me # a,b,c" ssh-ed25519 AAAA c');
        $this->assertTrue($line->forcesToolsCallFor('me'));
    }

    // ─── forcesToolsCallFor: bounded agent match ──────────────────────────────

    public function test_agent_match_is_bounded(): void
    {
        $line = $this->line('command="php artisan bridge:tools-call --agent=meta",restrict ssh-ed25519 AAAA c');
        $this->assertFalse($line->forcesToolsCallFor('me'), '--agent=meta must not match agent me');
        $this->assertTrue($line->forcesToolsCallFor('meta'));
    }

    // ─── FIPS key algorithm ───────────────────────────────────────────────────

    public function test_ed25519_is_not_fips_approved(): void
    {
        $line = $this->line('command="php artisan bridge:tools-call --agent=me",restrict ssh-ed25519 AAAA c');
        $this->assertFalse($line->keyAlgorithmIsFipsApproved());
    }

    public function test_ecdsa_p256_is_fips_approved(): void
    {
        $line = $this->line('command="php artisan bridge:tools-call --agent=me",restrict ecdsa-sha2-nistp256 AAAA c');
        $this->assertTrue($line->keyAlgorithmIsFipsApproved());
    }

    // ─── parse hygiene: comments + blanks skipped ─────────────────────────────

    public function test_comments_and_blanks_are_skipped(): void
    {
        $lines = AuthorizedKeysLine::parseFile("# a comment\n\n   \ncommand=\"php artisan bridge:tools-call --agent=me\",restrict ssh-ed25519 AAAA c\n");
        $this->assertCount(1, $lines);
    }
}
