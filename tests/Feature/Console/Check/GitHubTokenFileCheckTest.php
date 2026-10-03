<?php

namespace Tests\Feature\Console\Check;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Support\CheckGolden\BootsGoldenInstall;
use Tests\Support\CheckGolden\GoldenInstall;
use Tests\TestCase;

/**
 * `bridge:check`'s `github.token_file` leg at the command surface (card#11201) — the positive
 * controls the card names, driven through the real command so that what is asserted is what an
 * operator's run prints and what its exit code does.
 *
 * ⭐ THE CASE THE CARD EXISTS FOR IS THE FIRST TEST: a 0-byte `<secret_dir>/github/token`, a
 * writeback mapping (so the DL-390 correlation comment is live) and no `promote_on_release`. The
 * only leg that probed the file before this card sat inside `promote_on_release`, so that install
 * was told nothing while every correlation comment it decided was dropped.
 *
 * ⛔ EVERY ABSENCE HERE CARRIES A WITNESS. The leg's own inventory entry is read out of the
 * `--format=json` document, so "no finding" is asserted as the `silent` disposition of a check
 * that RAN, never as a string that failed to print.
 */
class GitHubTokenFileCheckTest extends TestCase
{
    use BootsGoldenInstall;
    use RefreshDatabase;

    /** The leg's inventory id — pinned as a literal: an id must not change once shipped. */
    private const LEG = 'github.token_file';

    protected function tearDown(): void
    {
        $this->tearDownGoldenInstall();
        parent::tearDown();
    }

    public function test_an_empty_token_file_with_the_correlation_comment_live_and_no_promote_fails_naming_the_inert_leg(): void
    {
        $this->boot(tokenFile: '', promote: false);

        [$exit, $doc] = $this->runJson();

        $this->assertSame(1, $exit, 'a leg proven inert must move the exit code');
        $findings = $this->findingsOfTheLeg($doc);
        $this->assertCount(1, $findings);
        $this->assertSame('fail', $findings[0]['severity']);
        $message = $findings[0]['message'];
        $this->assertStringContainsString('is empty', $message);
        $this->assertStringContainsString($this->install->path('github/token'), $message);
        $this->assertStringContainsString('PR correlation comments (DL-390) on owner/repo', $message);
        // Named only where enabled: neither of the other two legs is on in this install.
        $this->assertStringNotContainsString('promote-on-release', $message);
        $this->assertStringNotContainsString('protocol:invalid', $message);
        // The remedy is the payload of the line.
        $this->assertStringContainsString('chmod 600', $message);
        $this->assertStringContainsString('bridge:github-owed --fix', $message);
    }

    public function test_the_same_install_with_a_classic_repo_scoped_token_file_reports_no_problem(): void
    {
        $this->boot(tokenFile: 'ghp_classic', promote: false, scopes: 'repo, read:org');

        [$exit, $doc] = $this->runJson();

        $findings = $this->findingsOfTheLeg($doc);
        $this->assertCount(1, $findings);
        $this->assertSame('ok', $findings[0]['severity']);
        $this->assertStringContainsString('`repo` scope', $findings[0]['message']);
        $this->assertStringContainsString('PR correlation comments (DL-390)', $findings[0]['message']);
        $this->assertNotContains('fail', array_column($this->allFindings($doc), 'severity'), 'nothing else in this install may fail either, or the exit code below says nothing about this leg');
        $this->assertSame(0, $exit);
    }

    public function test_an_empty_token_file_with_every_consumer_disabled_is_silent(): void
    {
        $this->boot(tokenFile: '', promote: false, writeback: false);

        [, $doc] = $this->runJson();

        $entry = $this->entryOfTheLeg($doc);
        $this->assertSame('silent', $entry['disposition'], 'the leg must have RUN and had nothing to say');
        $this->assertSame([], $entry['findings']);
    }

    public function test_a_missing_token_file_names_every_enabled_leg_and_only_those(): void
    {
        $this->boot(tokenFile: null, promote: true, labelRepos: ['owner/other']);

        [$exit, $doc] = $this->runJson();

        $this->assertSame(1, $exit);
        $message = $this->findingsOfTheLeg($doc)[0]['message'];
        $this->assertStringContainsString('absent', $message);
        $this->assertStringContainsString('PR correlation comments (DL-390) on owner/repo', $message);
        $this->assertStringContainsString('protocol:invalid labels (DL-408) on owner/other', $message);
        $this->assertStringContainsString('promote-on-release (DL-207) on owner/repo', $message);
    }

    public function test_the_promote_leg_is_no_longer_probed_a_second_time_by_the_mapping_check(): void
    {
        // card#11201 moved the promote-only probe here (canon #5): one probe, one line.
        $this->boot(tokenFile: null, promote: true);

        [, $doc] = $this->runJson();

        $mapping = '';
        foreach ($doc['checks'] as $check) {
            if ($check['id'] === 'writeback.mapping_config') {
                $mapping = implode("\n", array_column($check['findings'], 'message'));
            }
        }
        $this->assertStringContainsString('is ORPHANED', $mapping, 'the witness that the mapping check ran over this mapping');
        $this->assertStringNotContainsString('GitHub read token', $mapping);
        $this->assertStringContainsString('promote-on-release (DL-207)', $this->findingsOfTheLeg($doc)[0]['message']);
    }

    /**
     * @param  ?string  $tokenFile  the token file's contents; null leaves it absent
     * @param  list<string>  $labelRepos
     */
    private function boot(?string $tokenFile, bool $promote, bool $writeback = true, array $labelRepos = [], ?string $scopes = null): void
    {
        $this->bootGoldenInstall('github-token-file', function (GoldenInstall $i) use ($tokenFile, $promote, $writeback, $labelRepos, $scopes) {
            $i->boot()->agent('prod-agent', "subscriptions:\n  - provider: kanban\n    scopes: [5]\n");
            config(['bridge.protocol_invalid_label.repos' => $labelRepos]);
            if ($writeback) {
                $mapping = ['board_id' => 8, 'stages' => ['merged' => 52, 'merged_to_main' => 53]];
                if ($promote) {
                    $mapping['promote_on_release'] = true;
                }
                $i->json('writeback.json', ['identity_id' => 4242, 'mappings' => ['owner/repo' => $mapping]]);
            }
            if ($tokenFile !== null) {
                $i->secret('github/token', $tokenFile);
            }
            $rateLimit = $scopes === null
                ? Http::response(['resources' => []])
                : Http::response(['resources' => []], 200, ['X-OAuth-Scopes' => $scopes]);
            Http::fake([
                'https://api.github.com/rate_limit' => $rateLimit,
                'https://api.github.com/repos/owner/repo' => Http::response(['full_name' => 'owner/repo']),
                '*' => Http::response(['data' => []]),
            ]);
        });
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function runJson(): array
    {
        $exit = Artisan::call('bridge:check', ['--format' => 'json']);
        $decoded = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($decoded, 'bridge:check --format=json did not emit a JSON object');

        return [$exit, $decoded];
    }

    /**
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    private function entryOfTheLeg(array $doc): array
    {
        foreach ($doc['checks'] as $check) {
            if ($check['id'] === self::LEG) {
                return $check;
            }
        }
        $this->fail('the github token-file leg is not in the check inventory at all');
    }

    /**
     * @param  array<string, mixed>  $doc
     * @return list<array<string, mixed>>
     */
    private function findingsOfTheLeg(array $doc): array
    {
        $entry = $this->entryOfTheLeg($doc);
        $this->assertSame('reported', $entry['disposition']);

        return $entry['findings'];
    }

    /**
     * @param  array<string, mixed>  $doc
     * @return list<array<string, mixed>>
     */
    private function allFindings(array $doc): array
    {
        $all = [];
        foreach ($doc['checks'] as $check) {
            foreach ($check['findings'] as $finding) {
                $all[] = $finding;
            }
        }

        return $all;
    }
}
