<?php

namespace Tests\Unit\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Checks\WritebackMappingConfigCheck;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use App\Bridge\Writeback\WritebackConfig;
use App\Bridge\Writeback\WritebackMapping;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The legs of `writeback.mapping_config` the golden suite CANNOT see, and only those.
 *
 * The orphan / DL-160 / DL-195 / DL-198 / DL-204 legs are measured byte-for-byte by the
 * golden suite (`CheckGoldenTest` — named, not `{@see}`-linked, because pint's docblock
 * fixer turns a fully-qualified `{@see}` into a real import). Duplicating them here would
 * not strengthen the measurement, so they are absent on purpose. What is covered here is
 * the residue no fixture reaches:
 *
 *  - the #4553 `issue_population=all` warn itself, and the `correlation !== 'ref'` warn
 *    nested under it (no golden fixture renders either);
 *  - every branch of `issuePopulationAgreement()`, which no fixture enters at all —
 *    reaching it needs both an `all` mapping AND an ambient coordination config, and a
 *    golden fixture pins the ambient host precisely so it does NOT depend on one;
 *  - the two `promote_on_release` legs (same-stage no-op, and the file-token
 *    requirement), whose outcome depends on the host's token resolution.
 *
 * EVERY ABSENCE ASSERTION HERE CARRIES A WITNESS that the check ran and reached the
 * mapping loop — either another expected finding from the same run, or a deliberately
 * ORPHANED mapping whose warn is the witness. Without one, a check that yielded nothing
 * at all would satisfy the absence just as well.
 */
class WritebackMappingConfigCheckTest extends TestCase
{
    private const REPO = 'owner/repo';

    private const BOARD = 8;

    private string $dir;

    private string|false $origGhToken;

    private string|false $origCoordConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/wb-mapping-check-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/github');
        config([
            // No conventional token file and no store helper, so the promote-token leg
            // resolves deterministically from GH_TOKEN alone (source label 'GH_TOKEN').
            'bridge.secret_dir' => $this->dir,
            'bridge.providers.github.token_path' => null,
            'bridge.providers.github.credential_helper' => $this->dir.'/no-store-helper',
            'bridge.writeback.correlation' => 'ref',
            'bridge.writeback.coord_config_path' => null,
        ]);

        $this->origGhToken = getenv('GH_TOKEN');
        $this->origCoordConfig = getenv('COORD_CONFIG');
        putenv('GH_TOKEN');
        putenv('COORD_CONFIG');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        $this->restoreEnv('GH_TOKEN', $this->origGhToken);
        $this->restoreEnv('COORD_CONFIG', $this->origCoordConfig);
        parent::tearDown();
    }

    // ---- #4553: the issue_population=all warn, and the correlation warn under it ----

    public function test_issue_population_all_warns_that_the_bridge_is_the_sole_real_time_mover(): void
    {
        config(['bridge.writeback.coord_config_path' => $this->coordConfig(['all'])]);

        $findings = $this->findings($this->coordMapping(WritebackMapping::POPULATION_ALL));

        $this->assertCount(2, $findings, 'expected the #4553 warn plus the agreement line, nothing else');
        $this->assertSame(Severity::Warn, $findings[0]['severity']);
        $this->assertStringContainsString(
            'writeback: issue_population=all for '.self::REPO.' — the bridge is the SOLE real-time mover',
            $findings[0]['message'],
        );
        // The control for the correlation leg below: on the default `ref` it stays silent.
        $this->assertStringNotContainsString('BRIDGE_WRITEBACK_CORRELATION', $this->joined($findings));
    }

    public function test_the_prefixed_population_reports_nothing_about_a_missing_backstop(): void
    {
        config(['bridge.writeback.coord_config_path' => $this->coordConfig(['all'])]);

        // Orphaned on purpose: its warn witnesses that the check reached the mapping loop,
        // so the absence below is evidence rather than a check that never ran.
        $findings = $this->findings($this->coordMapping(WritebackMapping::POPULATION_PREFIXED), emitting: false);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('is ORPHANED', $findings[0]['message']);
        $this->assertStringNotContainsString('issue_population', $this->joined($findings));
    }

    public function test_a_non_ref_correlation_warns_that_by_ref_degrades_to_a_bare_number_scan(): void
    {
        config([
            'bridge.writeback.correlation' => 'scan',
            'bridge.writeback.coord_config_path' => $this->coordConfig(['all']),
        ]);

        $findings = $this->findings($this->coordMapping(WritebackMapping::POPULATION_ALL));

        $this->assertCount(3, $findings);
        $this->assertSame(Severity::Warn, $findings[1]['severity']);
        $this->assertStringContainsString(
            'but BRIDGE_WRITEBACK_CORRELATION is not `ref`',
            $findings[1]['message'],
        );
        $this->assertStringContainsString("correlate the wrong repo's issue #N", $findings[1]['message']);
    }

    // ---- issuePopulationAgreement(): the cross-config three-state compare ----

    public function test_an_agreeing_coord_config_reports_the_non_prefixed_set_as_backstopped(): void
    {
        config(['bridge.writeback.coord_config_path' => $this->coordConfig(['all'])]);

        $findings = $this->findings($this->coordMapping(WritebackMapping::POPULATION_ALL));

        $this->assertSame(Severity::Ok, $findings[1]['severity']);
        $this->assertStringContainsString(
            'coord config agrees — reconcile issue_population is \'all\'',
            $findings[1]['message'],
        );
    }

    public function test_a_reconcile_on_prefixed_is_reported_as_a_disagreement_not_as_silence(): void
    {
        config(['bridge.writeback.coord_config_path' => $this->coordConfig(['prefixed'])]);

        $findings = $this->findings($this->coordMapping(WritebackMapping::POPULATION_ALL));

        $this->assertSame(Severity::Warn, $findings[1]['severity']);
        $this->assertStringContainsString('the two movers DISAGREE on issue_population', $findings[1]['message']);
        $this->assertStringContainsString("is 'prefixed'", $findings[1]['message']);
    }

    public function test_an_unset_coord_config_is_cannot_verify_not_agreement(): void
    {
        // Both channels dark: no configured path, no ambient $COORD_CONFIG.
        $findings = $this->findings($this->coordMapping(WritebackMapping::POPULATION_ALL));

        $this->assertSame(Severity::Warn, $findings[1]['severity']);
        $this->assertStringContainsString('CANNOT VERIFY', $findings[1]['message']);
        $this->assertStringContainsString('$COORD_CONFIG is not set', $findings[1]['message']);
    }

    public function test_an_absent_coord_config_file_is_cannot_verify_and_names_the_path(): void
    {
        $missing = $this->dir.'/no-such-coordination.config.json';
        config(['bridge.writeback.coord_config_path' => $missing]);

        $findings = $this->findings($this->coordMapping(WritebackMapping::POPULATION_ALL));

        $this->assertStringContainsString(
            "the coordination config at {$missing} is absent, unreadable, or malformed",
            $findings[1]['message'],
        );
    }

    public function test_a_malformed_coord_config_is_cannot_verify_rather_than_a_crash(): void
    {
        $path = $this->dir.'/malformed.json';
        File::put($path, 'not json at all');
        config(['bridge.writeback.coord_config_path' => $path]);

        $findings = $this->findings($this->coordMapping(WritebackMapping::POPULATION_ALL));

        $this->assertStringContainsString('is absent, unreadable, or malformed', $findings[1]['message']);
    }

    /**
     * The `getenv('COORD_CONFIG')` fallback is the leg that actually fires on a real
     * install (almost nobody sets `bridge.writeback.coord_config_path`), and it is read
     * live so `php artisan optimize` cannot freeze a deploy-time value.
     */
    public function test_the_ambient_coord_config_env_var_is_used_when_no_path_is_configured(): void
    {
        putenv('COORD_CONFIG='.$this->coordConfig(['all']));

        $findings = $this->findings($this->coordMapping(WritebackMapping::POPULATION_ALL));

        $this->assertSame(Severity::Ok, $findings[1]['severity']);
        $this->assertStringContainsString('coord config agrees', $findings[1]['message']);
    }

    public function test_a_coord_config_with_no_entry_for_this_board_is_cannot_verify(): void
    {
        $path = $this->dir.'/other-board.json';
        File::put($path, (string) json_encode(['kanban' => ['boards' => [['board_id' => 999, 'issue_population' => 'all']]]]));
        config(['bridge.writeback.coord_config_path' => $path]);

        $findings = $this->findings($this->coordMapping(WritebackMapping::POPULATION_ALL));

        $this->assertStringContainsString(
            'the coordination config has no kanban.boards[] entry for board '.self::BOARD,
            $findings[1]['message'],
        );
    }

    public function test_two_disagreeing_entries_for_one_board_is_cannot_verify_not_a_coin_flip(): void
    {
        config(['bridge.writeback.coord_config_path' => $this->coordConfig(['all', 'prefixed'])]);

        $findings = $this->findings($this->coordMapping(WritebackMapping::POPULATION_ALL));

        $this->assertStringContainsString(
            'resolves multiple issue_population values for board '.self::BOARD,
            $findings[1]['message'],
        );
        $this->assertStringContainsString('(all, prefixed)', $findings[1]['message']);
    }

    // ---- DL-207: the two promote_on_release legs ----

    public function test_promote_on_release_with_both_stages_on_one_column_reports_the_no_op(): void
    {
        $this->placeTokenFile();   // isolates the no-op leg from the file-token leg below

        $findings = $this->findings($this->promoteMapping(merged: 52, mergedToMain: 52));

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Warn, $findings[0]['severity']);
        $this->assertStringContainsString(
            'stages.merged and stages.merged_to_main are the same stage — the Shipped→Released promote is a no-op',
            $findings[0]['message'],
        );
    }

    public function test_promote_on_release_with_distinct_stages_reports_no_no_op(): void
    {
        $this->placeTokenFile();

        // Orphaned on purpose — the witness for the two absences below.
        $findings = $this->findings($this->promoteMapping(merged: 52, mergedToMain: 53), emitting: false);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('is ORPHANED', $findings[0]['message']);
        $this->assertStringNotContainsString('the same stage', $this->joined($findings));
        $this->assertStringNotContainsString('no GitHub read token resolves from a FILE', $this->joined($findings));
    }

    public function test_a_gh_token_only_promote_leg_is_reported_inert_in_the_fpm_runtime(): void
    {
        // The sharp case: the token RESOLVES (bridge:reconcile works fine) but not from a
        // file, so the FPM webhook runtime — no GH_TOKEN, CLI-only credential helper —
        // resolves nothing and the promote leg is inert with no reconcile backstop.
        putenv('GH_TOKEN=ghp_ambient');

        $findings = $this->findings($this->promoteMapping(merged: 52, mergedToMain: 53));

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Warn, $findings[0]['severity']);
        $this->assertStringContainsString('no GitHub read token resolves from a FILE', $findings[0]['message']);
        $this->assertStringContainsString('the credential-store helper is CLI-only', $findings[0]['message']);
    }

    public function test_a_promote_leg_with_no_token_at_all_is_reported_inert_too(): void
    {
        // GH_TOKEN unset in setUp and no file placed: resolution FAILS outright, a
        // different upstream state than the resolves-but-not-from-a-file case above.
        $findings = $this->findings($this->promoteMapping(merged: 52, mergedToMain: 53));

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('no GitHub read token resolves from a FILE', $findings[0]['message']);
    }

    /**
     * The check recognizes a file leg by the `source` LABEL the resolver stamps, so the
     * override label is part of that contract: were it to drift, this check would start
     * warning about a healthy install whose token is a file.
     */
    public function test_a_token_path_override_counts_as_a_file_token(): void
    {
        $override = $this->dir.'/override-token';
        File::put($override, 'ghp_override');
        chmod($override, 0o600);
        config(['bridge.providers.github.token_path' => $override]);

        $findings = $this->findings($this->promoteMapping(merged: 52, mergedToMain: 53), emitting: false);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('is ORPHANED', $findings[0]['message']);
    }

    // ---- helpers ----

    /**
     * A coord-card mapping in the requested population. `move_coord_cards` stays off so
     * the DL-204 mirror leg (which the golden suite already measures) adds no noise.
     */
    private function coordMapping(string $population): WritebackMapping
    {
        return new WritebackMapping(
            boardId: self::BOARD,
            stages: [],
            createCoordCards: true,
            coordCardStageId: 21,
            issuePopulation: $population,
        );
    }

    private function promoteMapping(int $merged, int $mergedToMain): WritebackMapping
    {
        return new WritebackMapping(
            boardId: self::BOARD,
            stages: ['merged' => $merged, 'merged_to_main' => $mergedToMain],
            promoteOnRelease: true,
        );
    }

    /** @param  list<string>  $populations  one kanban.boards[] entry per value, all on self::BOARD */
    private function coordConfig(array $populations): string
    {
        $path = $this->dir.'/coordination.config.json';
        File::put($path, (string) json_encode(['kanban' => ['boards' => array_map(
            fn (string $p) => ['board_id' => self::BOARD, 'issue_population' => $p],
            $populations,
        )]]));

        return $path;
    }

    private function placeTokenFile(): void
    {
        $path = $this->dir.'/github/token';
        File::put($path, 'ghp_from_a_file');
        chmod($path, 0o600);
    }

    /** @return list<array{severity: Severity, message: string}> */
    private function findings(WritebackMapping $mapping, bool $emitting = true): array
    {
        $ctx = new CheckContext;
        $ctx->writeback = new WritebackConfig(7, [self::REPO => $mapping]);
        if ($emitting) {
            $ctx->writebackEmittingScopes = [self::REPO => true];
        }

        return array_map(
            fn (Finding $f) => ['severity' => $f->severity, 'message' => $f->message],
            iterator_to_array((new WritebackMappingConfigCheck)->run($ctx), false),
        );
    }

    /** @param  list<array{severity: Severity, message: string}>  $findings */
    private function joined(array $findings): string
    {
        return implode("\n", array_column($findings, 'message'));
    }

    private function restoreEnv(string $name, string|false $original): void
    {
        $original === false ? putenv($name) : putenv($name.'='.$original);
    }
}
