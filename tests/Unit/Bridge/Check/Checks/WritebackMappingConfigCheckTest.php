<?php

namespace Tests\Unit\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Checks\WritebackMappingConfigCheck;
use App\Bridge\Support\CardTokenGrammar;
use App\Bridge\Support\ClosureGrammar;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use App\Bridge\Writeback\PrOutcome;
use App\Bridge\Writeback\WritebackConfig;
use App\Bridge\Writeback\WritebackMapping;
use Illuminate\Support\Facades\File;
use Tests\Support\MaterializesChecks;
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
 *    requirement), whose outcome depends on the host's token resolution;
 *  - every coord-card FAMILY leg except the DL-204 pair — the create legs (card#8292 and
 *    card#8305) and the relane legs (card#6393 and card#8290). No golden fixture enables
 *    `coord-card-create` or `coord-card-relane` in an agent's `classifier.config.families`,
 *    so the corpus cannot reach either family's gate-1 half in either direction. Stated as
 *    a PROPERTY of the corpus rather than as four named legs: a fifth family leg lands in
 *    the same position, and a list of legs here would go stale the way a list of fixtures
 *    would.
 *
 * EVERY ABSENCE ASSERTION HERE CARRIES A WITNESS that the check ran and reached the
 * mapping loop — either another expected finding from the same run, or a deliberately
 * ORPHANED mapping whose warn is the witness. Without one, a check that yielded nothing
 * at all would satisfy the absence just as well.
 */
class WritebackMappingConfigCheckTest extends TestCase
{
    use MaterializesChecks;

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

        $findings = $this->populationFindings(WritebackMapping::POPULATION_ALL);

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
        $findings = $this->populationFindings(WritebackMapping::POPULATION_PREFIXED, emitting: false);

        $this->assertCount(1, $this->warnings($findings));
        $this->assertStringContainsString('is ORPHANED', $findings[0]['message']);
        $this->assertStringNotContainsString('issue_population', $this->joined($findings));
    }

    public function test_a_non_ref_correlation_warns_that_by_ref_degrades_to_a_bare_number_scan(): void
    {
        config([
            'bridge.writeback.correlation' => 'scan',
            'bridge.writeback.coord_config_path' => $this->coordConfig(['all']),
        ]);

        $findings = $this->populationFindings(WritebackMapping::POPULATION_ALL);

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

        $findings = $this->populationFindings(WritebackMapping::POPULATION_ALL);

        $this->assertSame(Severity::Ok, $findings[1]['severity']);
        $this->assertStringContainsString(
            'coord config agrees — reconcile issue_population is \'all\'',
            $findings[1]['message'],
        );
    }

    public function test_a_reconcile_on_prefixed_is_reported_as_a_disagreement_not_as_silence(): void
    {
        config(['bridge.writeback.coord_config_path' => $this->coordConfig(['prefixed'])]);

        $findings = $this->populationFindings(WritebackMapping::POPULATION_ALL);

        $this->assertSame(Severity::Warn, $findings[1]['severity']);
        $this->assertStringContainsString('the two movers DISAGREE on issue_population', $findings[1]['message']);
        $this->assertStringContainsString("is 'prefixed'", $findings[1]['message']);
    }

    public function test_an_unset_coord_config_is_cannot_verify_not_agreement(): void
    {
        // Both channels dark: no configured path, no ambient $COORD_CONFIG.
        $findings = $this->populationFindings(WritebackMapping::POPULATION_ALL);

        $this->assertSame(Severity::Unvalidated, $findings[1]['severity']);
        $this->assertStringContainsString('CANNOT VERIFY', $findings[1]['message']);
        $this->assertStringContainsString('$COORD_CONFIG is not set', $findings[1]['message']);
    }

    public function test_an_absent_coord_config_file_is_cannot_verify_and_names_the_path(): void
    {
        $missing = $this->dir.'/no-such-coordination.config.json';
        config(['bridge.writeback.coord_config_path' => $missing]);

        $findings = $this->populationFindings(WritebackMapping::POPULATION_ALL);

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

        $findings = $this->populationFindings(WritebackMapping::POPULATION_ALL);

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

        $findings = $this->populationFindings(WritebackMapping::POPULATION_ALL);

        $this->assertSame(Severity::Ok, $findings[1]['severity']);
        $this->assertStringContainsString('coord config agrees', $findings[1]['message']);
    }

    public function test_a_coord_config_with_no_entry_for_this_board_is_cannot_verify(): void
    {
        $path = $this->dir.'/other-board.json';
        File::put($path, (string) json_encode(['kanban' => ['boards' => [['board_id' => 999, 'issue_population' => 'all']]]]));
        config(['bridge.writeback.coord_config_path' => $path]);

        $findings = $this->populationFindings(WritebackMapping::POPULATION_ALL);

        $this->assertSame(Severity::Unvalidated, $findings[1]['severity']);
        $this->assertStringContainsString(
            'the coordination config has no kanban.boards[] entry for board '.self::BOARD,
            $findings[1]['message'],
        );
    }

    public function test_two_disagreeing_entries_for_one_board_is_cannot_verify_not_a_coin_flip(): void
    {
        config(['bridge.writeback.coord_config_path' => $this->coordConfig(['all', 'prefixed'])]);

        $findings = $this->populationFindings(WritebackMapping::POPULATION_ALL);

        $this->assertSame(Severity::Unvalidated, $findings[1]['severity']);
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

        $this->assertCount(1, $this->warnings($findings));
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

        $this->assertCount(1, $this->warnings($findings));
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

        $this->assertCount(1, $this->warnings($findings));
        $this->assertSame(Severity::Warn, $findings[0]['severity']);
        $this->assertStringContainsString('no GitHub read token resolves from a FILE', $findings[0]['message']);
        $this->assertStringContainsString('the credential-store helper is CLI-only', $findings[0]['message']);
    }

    public function test_a_promote_leg_with_no_token_at_all_is_reported_inert_too(): void
    {
        // GH_TOKEN unset in setUp and no file placed: resolution FAILS outright, a
        // different upstream state than the resolves-but-not-from-a-file case above.
        $findings = $this->findings($this->promoteMapping(merged: 52, mergedToMain: 53));

        $this->assertCount(1, $this->warnings($findings));
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

        $this->assertCount(1, $this->warnings($findings));
        $this->assertStringContainsString('is ORPHANED', $findings[0]['message']);
    }

    // ---- card#7348 / DL-305: the mention-vs-closure setup line ----

    public function test_a_mapping_with_merge_stages_states_the_mention_vs_closure_semantics(): void
    {
        // THE ASK THIS LEG EXISTS FOR (roundtable #343): a peer wired a brand-new board
        // into this classifier and nothing in the setup path told them a bare mention would
        // move a card into a terminal stage. This is the sentence, on the surface that runs.
        $findings = $this->findings(new WritebackMapping(self::BOARD, ['merged' => 52, 'merged_to_main' => 53]));

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Ok, $findings[0]['severity']);
        $message = $findings[0]['message'];
        // BOTH gated outcomes are NAMED with their stage ids — an install that maps one of
        // the two must be able to see which.
        $this->assertStringContainsString('stages.merged (52) and stages.merged_to_main (53) are gated', $message);
        // The load-bearing half: a bare mention is a no-op, NOT a demotion.
        $this->assertStringContainsString('NO-OP for the stage', $message);
        $this->assertStringContainsString('never moved back', $message);
        // The accept-set is RENDERED from the authority, never spelled out here (DL-239) —
        // asserted through the authority so this cannot pin a stale copy of it. Since
        // DL-308 that is `PrOutcome::describeClosure()`, which composes BOTH routes: this
        // surface tells an operator what they inherited, so a line still claiming the
        // title is the only thing that moves a card is the DL-239 defect on the worst
        // possible surface. Both halves are asserted, each through its own grammar.
        $this->assertStringContainsString(PrOutcome::describeClosureAccepted(), $message);
        $this->assertStringContainsString(implode(', ', ClosureGrammar::accepted()), $message);
        $this->assertStringContainsString(implode(', ', CardTokenGrammar::accepted()), $message);
        $this->assertStringContainsString('HEAD BRANCH REF', $message);
        // …and the SETUP flavour, not the diagnostic one: DL-305 kept the rejected shapes
        // off this surface deliberately (noise at setup, diagnosis at runtime), and
        // composing two routes must not quietly overturn that. The trailing sentence here
        // PROMISES the rejected side is deferred to the runtime warning, so printing it
        // would make this line contradict itself.
        $this->assertStringNotContainsString('does NOT close', $message);
        $this->assertStringNotContainsString('not accepted:', $message);
    }

    public function test_a_mapping_with_only_one_merge_stage_names_only_that_one(): void
    {
        // The discriminator: the line reports what the mapping ACTUALLY maps. Map both and
        // it names both (above); map one and it must not claim the other is gated.
        $findings = $this->findings(new WritebackMapping(self::BOARD, ['merged' => 52]));

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('stages.merged (52) is gated', $findings[0]['message']);
        $this->assertStringNotContainsString('merged_to_main', $findings[0]['message']);
    }

    public function test_a_mapping_with_no_merge_stage_says_nothing_about_closure(): void
    {
        // SEEN TO FAIL, the other way round: a started/opened-only mapping has no merge leg,
        // so the gate has nothing to describe and the emptiness is the operator's own config
        // (the Severity corollary-(B) ruling). Orphaned on purpose so the run has a witness —
        // without one, a check that never reached the loop would satisfy this absence too.
        $findings = $this->findings(new WritebackMapping(self::BOARD, ['opened' => 50]), emitting: false);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('is ORPHANED', $findings[0]['message']);
        $this->assertStringNotContainsString('closing form', $this->joined($findings));
    }

    // ---- card#8290: the lane model whose post-birth race no family closes ----

    public function test_a_lane_model_with_no_relane_family_names_the_race_nothing_closes(): void
    {
        $findings = $this->findings($this->laneMapping(), families: ['coord-card-create', 'coord-card-move']);

        // ⛔ NOT A WARNING, and that is the whole ruling this leg carries: the install is
        // correctly configured and every leg it configured fires. What it cannot know from
        // its own `writeback.json` is that a SECOND family exists which closes a race the
        // lane model leaves open — the card#7348 / DL-305 shape, hence that shape's severity.
        $this->assertSame([], $this->warnings($findings), 'a lane model without the relane family is a VALID config — no leg may accuse it');
        $this->assertCount(2, $findings, 'the advisory, plus the DL-305 line that witnesses the loop ran to the end');
        $this->assertSame(Severity::Ok, $findings[0]['severity']);
        $message = $findings[0]['message'];
        // Both halves the operator has to reconcile, spelled the way their own files spell them.
        $this->assertStringContainsString('coord_card_lane_stage_ids', $message);
        $this->assertStringContainsString('coord-card-relane', $message);
        $this->assertStringContainsString('classifier.config.families', $message);
        // The MECHANISM, which is what makes this a diagnosis rather than a feature advert.
        $this->assertStringContainsString('never when a `stage:*` label arrives afterwards', $message);
        // The remediation string is a doc surface, so the section it sends the operator to is
        // asserted by CONTENT — a leg that names no section sends them to a 700-line file.
        $this->assertStringContainsString('docs/writeback.md § Following a label added after the card exists', $message);
        // `move_coord_cards` is already on here, so the line must NOT ask for it.
        $this->assertStringNotContainsString('move_coord_cards', $message);
    }

    public function test_a_fully_adopted_lane_model_is_told_nothing_about_the_relane_family(): void
    {
        // THE CONTROL, and the measurement the card#6393 decline turned on: the SAME mapping
        // with the family enabled must draw no line at all. A leg that fired here would be
        // the "warns on every lane-model install" that decline refused.
        $findings = $this->findings($this->laneMapping(), families: ['coord-card-create', 'coord-card-move', 'coord-card-relane']);

        $this->assertCount(1, $findings, 'only the DL-305 witness — the advisory must be silent on a fully adopted install');
        $this->assertStringContainsString('moves a card on MERGE only when', $findings[0]['message']);
        $this->assertStringNotContainsString('coord-card-relane', $this->joined($findings));
    }

    public function test_a_create_only_lane_model_is_told_the_relane_family_needs_move_coord_cards_too(): void
    {
        // DL-294 makes `coord_card_lane_stage_ids` valid with the CREATE family alone, and
        // the relane family additionally requires `move_coord_cards` — so on this shape the
        // advisory is not one families-list entry away, and saying otherwise would send the
        // operator to a config that still classifies nothing.
        $findings = $this->findings($this->laneMapping(move: false), families: ['coord-card-create']);

        $this->assertSame([], $this->warnings($findings));
        $this->assertSame(Severity::Ok, $findings[0]['severity']);
        $this->assertStringContainsString('and set move_coord_cards', $findings[0]['message']);
    }

    public function test_an_unread_agent_makes_the_relane_advisory_a_disclosure_not_a_claim(): void
    {
        // card#5698: the leg asserts that NO agent enables the family, and an agent this run
        // never finished reading is indistinguishable from an absent one — so the advisory
        // would tell an operator to adopt a family they may already run.
        $findings = $this->findings($this->laneMapping(), families: ['coord-card-create', 'coord-card-move'], unreadAgent: 'prod-agent');

        $this->assertSame(Severity::Unvalidated, $findings[0]['severity']);
        $this->assertStringContainsString('could NOT determine whether any agent enables the coord-card-relane family', $findings[0]['message']);
        $this->assertStringContainsString('agent prod-agent was not read to completion this run', $findings[0]['message']);
        // A disclosure, not a remediation: it must not send anyone to edit a families list
        // whose real content this run could not read.
        $this->assertStringNotContainsString('classifier.config.families', $findings[0]['message']);
    }

    // ---- card#8292: create_coord_cards with no agent enabling coord-card-create ----

    public function test_create_coord_cards_with_no_create_family_is_warned_as_an_inert_leg(): void
    {
        $findings = $this->findings($this->createMapping());

        // ⚠ A WARNING, where its lane-model neighbour above is an `ok` — the contrast IS the
        // ruling. There the install is correct and every leg it configured fires; here a leg
        // the operator explicitly turned on is dead, which is the condition this check's
        // WARNING family is defined by.
        $this->assertCount(1, $this->warnings($findings));
        $this->assertSame(Severity::Warn, $findings[0]['severity']);
        $message = $findings[0]['message'];
        // Both gates, spelled the way the operator's own two files spell them.
        $this->assertStringContainsString('create_coord_cards', $message);
        $this->assertStringContainsString('coord-card-create', $message);
        $this->assertStringContainsString('classifier.config.families', $message);
        // The MECHANISM — what is actually dead, not merely that something is.
        $this->assertStringContainsString('nothing is classified and no card is ever created', $message);
        // The remediation string is a doc surface, so the section it sends the operator to is
        // asserted by CONTENT — a leg naming no section sends them to a 950-line file.
        $this->assertStringContainsString('docs/writeback.md § Optional: real-time coordination issue → card (DL-198)', $message);
    }

    public function test_a_mapping_whose_agent_enables_the_create_family_is_told_nothing(): void
    {
        // THE CONTROL, and since card#8305 it discriminates in BOTH directions. The SAME
        // mapping with the family enabled must draw no line at all — a leg that fired here
        // would accuse every correctly configured coord-card install. Until card#8305 only
        // ONE leg could have broken it, so it also passed for every install shape that had
        // no second leg; now it is the fully-configured cell of a two-by-two, and a
        // family-enabled leg that forgot its `create_coord_cards` term reds here.
        $findings = $this->findings($this->createMapping(), families: ['coord-card-create']);

        $this->assertCount(1, $findings, 'only the DL-305 witness — neither create leg may speak on a fully configured install');
        $this->assertStringContainsString('moves a card on MERGE only when', $findings[0]['message']);
        $this->assertStringNotContainsString('coord-card-create', $this->joined($findings));
    }

    public function test_an_unread_agent_makes_the_create_family_warn_a_disclosure_not_a_claim(): void
    {
        // card#5698: the warn's accusation IS that no agent enables the family, and an agent
        // this run never finished reading is indistinguishable from an absent one — so the
        // line would send an operator to edit a families list that may already be correct in
        // the config that failed to load.
        $findings = $this->findings($this->createMapping(), unreadAgent: 'prod-agent');

        $this->assertSame(Severity::Unvalidated, $findings[0]['severity']);
        $this->assertStringContainsString('could NOT determine whether any agent enables the coord-card-create family', $findings[0]['message']);
        $this->assertStringContainsString('agent prod-agent was not read to completion this run', $findings[0]['message']);
        // A disclosure, not a remediation.
        $this->assertStringNotContainsString('classifier.config.families', $findings[0]['message']);
        $this->assertStringNotContainsString('docs/writeback.md', $findings[0]['message']);
    }

    // ---- card#8305: the create family enabled on a mapping that never opted in ----

    public function test_the_create_family_on_a_mapping_that_never_opted_in_is_warned_as_an_inert_leg(): void
    {
        // The cell the gate1/gate2 matrix was missing. `coordCardCreateFamily()` dispatches
        // on the family and then returns null at its own mapping gate, so the family runs
        // and cards nothing — the same deadness the mirror above reports from the other
        // side, and the same deadness the DL-204 pair reports for the move family.
        $findings = $this->findings($this->mapping(), families: ['coord-card-create']);

        $this->assertCount(1, $this->warnings($findings));
        $this->assertSame(Severity::Warn, $findings[0]['severity'], 'a leg the operator turned on is dead — the condition this check\'s WARNING family is defined by');
        $message = $findings[0]['message'];
        // Both gates, spelled the way the operator's own two files spell them.
        $this->assertStringContainsString('coord-card-create', $message);
        $this->assertStringContainsString('create_coord_cards', $message);
        $this->assertStringContainsString('classifier.config.families', $message);
        // The MECHANISM — WHERE it dies, not merely that something is dead. A line saying
        // only "the create leg is off" would describe a mapping with no family enabled too.
        $this->assertStringContainsString('the create family returns at its own mapping gate', $message);
        // The remediation names the SECOND key the opt-in direction needs: `WritebackConfig`
        // refuses to load a mapping with create_coord_cards and no coord_card_stage_id, so a
        // line asking only for the first sends the operator to a config that fails closed.
        $this->assertStringContainsString('coord_card_stage_id', $message);
        // The remediation string is a doc surface, so the section it sends the operator to is
        // asserted by CONTENT — a leg naming no section sends them to a 950-line file.
        $this->assertStringContainsString('docs/writeback.md § Optional: real-time coordination issue → card (DL-198)', $message);
        // ⛔ THE TWO CREATE LEGS MUST BE DISTINGUISHABLE. They accuse opposite configs and
        // carry opposite remediations, so an operator reading one must not be able to act on
        // the other's instruction.
        $this->assertStringNotContainsString('sets create_coord_cards but no agent enables', $message);
    }

    public function test_an_unread_agent_leaves_the_family_enabled_create_leg_silent_rather_than_disclosing(): void
    {
        // ⛔ NOT A DISCLOSURE, AND THAT IS A RULING THIS PINS RATHER THAN A GAP. card#5698
        // applies to a leg whose accusation IS an absence; this leg's map term is a
        // POSITIVE (`isset`), so an unread agent cannot make it speak falsely — it can only
        // leave it silent, which is the same silence it keeps for every install that does
        // not enable the family. A disclosure here would print on every mapping that cards
        // no coord issues — the majority — on any run with one unreadable agent config,
        // which is exactly what the DL-204 arm's own no-disclosure ruling refused.
        $findings = $this->findings($this->mapping(), unreadAgent: 'prod-agent');

        $this->assertSame([], $this->warnings($findings), 'an unread agent must not turn a positive-map leg into a line of any severity');
        $this->assertCount(1, $findings, 'only the DL-305 witness, which is what proves the loop ran to the end rather than yielding nothing');
        $this->assertStringNotContainsString('coord-card-create', $this->joined($findings));
    }

    // ---- helpers ----

    /**
     * A create-leg coord-card mapping (card#8292), with `move_coord_cards` and the lane model
     * both off so the DL-204 and card#8290 legs add no noise. The merge stage is the same
     * DL-305 witness the lane helper below carries.
     */
    private function createMapping(): WritebackMapping
    {
        return new WritebackMapping(
            boardId: self::BOARD,
            stages: ['merged' => 52],
            createCoordCards: true,
            coordCardStageId: 21,
        );
    }

    /**
     * A lane-model coord-card mapping (card#8290). The merge stage is deliberate: the DL-305
     * `ok` line it produces is the WITNESS that the mapping loop ran to the end, which is
     * what the silence assertions above are measured against.
     *
     * It sets `create_coord_cards`, so every caller enables `coord-card-create` (card#8292)
     * — otherwise the create-family-inert warn would fire on a shape whose subject is the
     * relane family, and each of those assertions would be a count of two things with one
     * subject.
     */
    private function laneMapping(bool $move = true): WritebackMapping
    {
        return new WritebackMapping(
            boardId: self::BOARD,
            stages: ['merged' => 52],
            createCoordCards: true,
            coordCardStageId: 21,
            moveCoordCards: $move,
            coordCardTerminalStageId: $move ? 53 : null,
            coordCardLaneStageIds: ['now' => 40, 'next' => 41, 'later' => 42, 'maybe' => 43],
        );
    }

    /**
     * The findings for a coord-card mapping in the requested population, on an install whose
     * serving agent DOES enable `coord-card-create`.
     *
     * THE FAMILY IS ENABLED DELIBERATELY (card#8292). These mappings set
     * `create_coord_cards`, so without it every run here would also carry the
     * create-family-inert warn and each population assertion would be a count of two things
     * with one subject — the same reason `warnings()` drops the DL-305 `ok` below.
     *
     * @return list<array{severity: Severity, message: string}>
     */
    private function populationFindings(string $population, bool $emitting = true): array
    {
        return $this->findings($this->coordMapping($population), emitting: $emitting, families: ['coord-card-create']);
    }

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

    // ---- card#7124 review: the github scope SPELLING SPLIT (the dispatcher does not
    // ---- share the writeback's DL-293 canonicalization).

    public function test_a_scope_spelled_differently_from_the_mapping_key_is_reported_not_silently_matched(): void
    {
        // ⛔ THE REGRESSION THIS LEG EXISTS FOR. DL-293 made the writeback match this pair
        // as one repo, which correctly stopped the ORPHANED accusation — and would have
        // left NOTHING in its place, on an install where `SubscriptionRegistry` still
        // matches a subscription by exact spelling, so every delivery is dispatched to
        // nobody. Silence there is strictly worse than the wrong warn it replaced.
        $ctx = new CheckContext;
        $ctx->writeback = new WritebackConfig(7, ['PupFuzz/kanban-board' => $this->mapping()]);
        $ctx->writebackEmittingScopes = ['pupfuzz/kanban-board' => true];
        $ctx->githubScopeSpellings = ['pupfuzz/kanban-board' => ['pupfuzz/kanban-board']];

        $findings = $this->warnings(array_map(
            fn (Finding $f) => ['severity' => $f->severity, 'message' => $f->message],
            $this->findingsOf((new WritebackMappingConfigCheck), $ctx),
        ));

        $this->assertCount(1, $findings, 'the split must be REPORTED, never silently matched');
        $this->assertSame(Severity::Warn, $findings[0]['severity']);
        // Both spellings, so the operator can find both lines in both files.
        $this->assertStringContainsString('"PupFuzz/kanban-board"', $findings[0]['message']);
        $this->assertStringContainsString('"pupfuzz/kanban-board"', $findings[0]['message']);
        // And the mechanism, which is the half the ORPHANED warn never carried.
        $this->assertStringContainsString('EXACT spelling', $findings[0]['message']);
        $this->assertStringNotContainsString('ORPHANED', $findings[0]['message']);
    }

    public function test_a_scope_spelled_exactly_as_the_mapping_key_reports_no_split(): void
    {
        // The negative: this leg speaks only for a genuine divergence, which is every
        // install that has not hit it.
        $ctx = new CheckContext;
        $ctx->writeback = new WritebackConfig(7, [self::REPO => $this->mapping()]);
        $ctx->writebackEmittingScopes = [self::REPO => true];
        $ctx->githubScopeSpellings = [self::REPO => [self::REPO, self::REPO]];

        $findings = $this->findingsOf((new WritebackMappingConfigCheck), $ctx);

        // ⚑ This absence finally has the WITNESS the class docblock demands of every
        // absence here, and it is the mention-vs-closure `ok` (card#7348 / DL-305): before
        // it existed this test asserted a totally empty result, which a check that returned
        // at the first line would have satisfied just as well. Now the green line proves the
        // mapping loop ran to the end, and the absence of any warning beside it is evidence.
        $this->assertSame([], $this->warnings(array_map(
            fn (Finding $f) => ['severity' => $f->severity, 'message' => $f->message],
            $findings,
        )));
        $this->assertCount(1, $findings);
        $this->assertStringContainsString('moves a card on MERGE only when', $findings[0]->message);
    }

    public function test_an_unsubscribed_mapping_is_orphaned_not_a_spelling_split(): void
    {
        // The two legs answer different questions and must not be confused: no agent at
        // all is ORPHANED, and there is no spelling to name.
        $ctx = new CheckContext;
        $ctx->writeback = new WritebackConfig(7, [self::REPO => $this->mapping()]);

        $messages = array_map(
            fn (Finding $f) => $f->message,
            // The mention-vs-closure `ok` (card#7348 / DL-305) is not this leg's subject.
            array_filter($this->findingsOf((new WritebackMappingConfigCheck), $ctx), fn (Finding $f) => $f->severity !== Severity::Ok),
        );
        $messages = array_values($messages);

        $this->assertCount(1, $messages);
        $this->assertStringContainsString('is ORPHANED', $messages[0]);
        $this->assertStringNotContainsString('SPELLING SPLIT', $messages[0]);
    }

    private function mapping(): WritebackMapping
    {
        return new WritebackMapping(8, ['merged' => 52]);
    }

    /**
     * @param  list<string>  $families  the coord-card families some agent enables on this
     *                                  scope — the three `CheckContext` maps
     *                                  `CheckContext::recordCoordCardFamilies()` fills from
     *                                  `classifier.config.families` (card#8305; the
     *                                  per-agent loop in `CheckCommand::handle()` calls it)
     * @param  ?string  $unreadAgent  an agent whose config never reached those maps, so a
     *                                negative taken from them is not evidence (card#5698)
     * @return list<array{severity: Severity, message: string}>
     */
    private function findings(WritebackMapping $mapping, bool $emitting = true, array $families = [], ?string $unreadAgent = null): array
    {
        $ctx = new CheckContext;
        $ctx->writeback = new WritebackConfig(7, [self::REPO => $mapping]);
        if ($emitting) {
            $ctx->writebackEmittingScopes = [self::REPO => true];
        }
        if (in_array('coord-card-create', $families, true)) {
            $ctx->coordCardCreateScopes = [self::REPO => true];
        }
        if (in_array('coord-card-move', $families, true)) {
            $ctx->coordCardMoveScopes = [self::REPO => true];
        }
        if (in_array('coord-card-relane', $families, true)) {
            $ctx->coordCardRelaneScopes = [self::REPO => true];
        }
        if ($unreadAgent !== null) {
            $ctx->agentScopeCoverage->recordUnread($unreadAgent, [self::REPO]);
        }

        return array_map(
            fn (Finding $f) => ['severity' => $f->severity, 'message' => $f->message],
            $this->findingsOf((new WritebackMappingConfigCheck), $ctx),
        );
    }

    /**
     * The findings this file is about — every WARNING leg, with the one non-warning leg
     * dropped (card#7348 / DL-305).
     *
     * The mention-vs-closure line is an `ok` that fires for every mapping carrying a merge
     * stage, which is every mapping in this file. It is measured by its own test below;
     * here it would turn each count assertion into a count of two things with one subject.
     * Dropping it by SEVERITY rather than by message text is deliberate: a text match would
     * silently start passing through the day the wording moves, and the property these
     * tests want is *nothing else spoke*, which is exactly "no further warning".
     *
     * @param  list<array{severity: Severity, message: string}>  $findings
     * @return list<array{severity: Severity, message: string}>
     */
    private function warnings(array $findings): array
    {
        return array_values(array_filter($findings, fn (array $f) => $f['severity'] !== Severity::Ok));
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
