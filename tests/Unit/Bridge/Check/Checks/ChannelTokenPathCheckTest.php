<?php

namespace Tests\Unit\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Checks\ChannelTokenPathCheck;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The `channel.auth.token_path` leg (DL-008), migrated in DL-242 stage 5b.
 *
 * ⚠ A `catch` IS INVISIBLE TO THE COVERAGE MEASUREMENT, not merely unobserved by it:
 * `bin/check-golden-mutate.php` enumerates `if`/`elseif`/`foreach`, so this check's only
 * reporting arm is absent from `docs/check-golden-coverage.md`'s disclosed-gap list
 * ENTIRELY rather than listed as UNOBSERVED — and every golden fixture that declares a
 * `token_path` writes a readable 0600 file, so no fixture reaches the throw. Absence from
 * that list is therefore not protection. Mutating that arm reds this file and nothing else
 * in the suite — measured by mutation, not inferred from a grep (CLAUDE_TESTING.md).
 *
 * THE THREE FAILURES ARE ASSERTED SEPARATELY BECAUSE THE CHECK DOES NOT OWN THEM.
 * `ChannelToken::read` owns the token contract and this check reports whatever it threw;
 * asserting only that "something warned" would pass equally against a check that restated
 * one rule of its own — which is exactly the second copy the migration was careful not to
 * create.
 */
class ChannelTokenPathCheckTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/channel-token-check-'.uniqid();
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        @chmod($this->dir.'/locked', 0o755);
        @chmod($this->dir.'/channel-token', 0o600);
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_a_missing_token_file_surfaces_the_unreadable_message(): void
    {
        $path = $this->dir.'/no-such-token';

        $findings = $this->findingsFor($path);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Warn, $findings[0]->severity);
        $this->assertSame(
            "agent prod-agent: channel auth token not readable at {$path} — channel_push will FAIL until fixed",
            $findings[0]->message,
        );
    }

    public function test_a_group_readable_token_surfaces_the_perms_message_with_its_mode(): void
    {
        $path = $this->token('tok', 0o644);

        $findings = $this->findingsFor($path);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Warn, $findings[0]->severity);
        $this->assertSame(
            "agent prod-agent: channel auth token at {$path} is group/world-readable (mode 0644) — chmod 600 — channel_push will FAIL until fixed",
            $findings[0]->message,
        );
    }

    /**
     * The arm a perms-and-existence preflight would miss entirely: correct mode, present
     * file, no token. It is the clearest evidence for doing the real read rather than
     * re-deriving the rules — a re-derived preflight would report this install green and
     * `channel_push` would still fail closed.
     */
    public function test_an_empty_token_file_surfaces_the_empty_message(): void
    {
        $path = $this->token("  \n", 0o600);

        $findings = $this->findingsFor($path);

        $this->assertCount(1, $findings);
        $this->assertSame(
            "agent prod-agent: channel auth token at {$path} is empty — channel_push will FAIL until fixed",
            $findings[0]->message,
        );
    }

    /**
     * THE PROXY-READER BOUND (card#5698). Same `false` from `is_readable()` as the absent
     * case above, and the opposite verdict — because `bridge:check` reads as the operator
     * while `channel_push` reads as the OS user the receiver runs as, so an unreadable-to-US
     * file is no evidence at all about the push. The pair of tests is the assertion: either
     * one alone passes against a check hard-wired to one severity.
     */
    public function test_a_present_but_unreadable_token_does_not_convict_the_push(): void
    {
        $this->skipAsRoot();
        $path = $this->token('tok', 0o000);

        $findings = $this->findingsFor($path);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Unvalidated, $findings[0]->severity);
        $this->assertStringContainsString('exists but is not readable by THIS process', $findings[0]->message);
        $this->assertStringNotContainsString('will FAIL', $findings[0]->message);
    }

    /**
     * The other half of the same bound, and it routes through the SHARED not-visible guard
     * rather than paraphrasing it — the remedy differs (grant traversal, not chmod the file).
     */
    public function test_a_token_under_an_untraversable_dir_reports_the_shared_not_visible_line(): void
    {
        $this->skipAsRoot();
        $locked = $this->dir.'/locked';
        mkdir($locked, 0o755);
        file_put_contents($locked.'/channel-token', 'tok');
        chmod($locked.'/channel-token', 0o600);
        chmod($locked, 0o000);

        try {
            $findings = $this->findingsFor($locked.'/channel-token');
        } finally {
            chmod($locked, 0o755);
        }

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Unvalidated, $findings[0]->severity);
        $this->assertStringContainsString('is not visible to this user', $findings[0]->message);
        $this->assertStringNotContainsString('will FAIL', $findings[0]->message);
    }

    /** The discriminating control: without it a check hard-wired to warn passes all three above. */
    public function test_a_readable_owner_only_token_is_silent(): void
    {
        $this->assertSame([], $this->findingsFor($this->token('tok', 0o600)));
    }

    /**
     * The early return. Asserted on an agent whose channel IS configured, so this is the
     * "no token declared" contract rather than "no channel to check" — and reverting the
     * guard reaches `ChannelToken::read(null)`, which reds here rather than warning.
     */
    public function test_a_channel_with_no_token_path_is_silent(): void
    {
        $this->assertSame([], $this->findingsFor(null));
    }

    private function token(string $contents, int $mode): string
    {
        $path = $this->dir.'/channel-token';
        File::put($path, $contents);
        chmod($path, $mode);

        return $path;
    }

    /** @return list<Finding> */
    private function findingsFor(?string $tokenPath): array
    {
        // channel.url is not incidental — AgentConfig rejects a token_path without the
        // HTTP transport (a UDS channel's boundary is its filesystem perms), so this is
        // the only shape in which the leg exists at all.
        $channel = ['url' => 'http://127.0.0.1:8765/push'];
        if ($tokenPath !== null) {
            $channel['auth'] = ['token_path' => $tokenPath];
        }

        $config = AgentConfig::fromArray('prod-agent', [
            'identity' => ['kanban_user_id' => 1],
            'subscriptions' => [],
            'channel' => $channel,
        ]);

        return iterator_to_array((new ChannelTokenPathCheck)->runFor($config, new CheckContext), false);
    }
}
