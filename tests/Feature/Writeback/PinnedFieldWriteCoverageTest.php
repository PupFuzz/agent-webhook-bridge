<?php

namespace Tests\Feature\Writeback;

use App\Bridge\Writeback\PinGuard;
use Tests\Feature\AgentTools\AgentToolsCallTest;
use Tests\Feature\Handlers\KanbanBlockReasonHandlerTest;
use Tests\Feature\Handlers\KanbanCoordCardHandlerTest;
use Tests\Feature\Handlers\KanbanDependabotCardHandlerTest;
use Tests\Feature\Handlers\KanbanMoveCardHandlerTest;
use Tests\Support\SourceScan;
use Tests\TestCase;

/**
 * THE STRUCTURAL ENFORCER FOR THE PIN'S FIELD-WRITE HALF (card#8557).
 *
 * DL-178's pin governs a card's STAGE, its LIFECYCLE (DL-335's archive) and — since
 * card#8557 — the FIELDS {@see PinGuard::PINNED_FIELDS} names. The stage-and-lifecycle half
 * has a census a reader is told to run by hand (`PinGuard`'s docblock owns the
 * `moveCard(` / `archiveCard(` recipe). This class is the FIELD half, and it runs itself.
 *
 * ⭐ WHY IT EXISTS, and the incident is the argument. The field-write ruling was justified by
 * a PROSE ROSTER of the producers that write a card's name. The roster said two. The code had
 * three: `board_correct_card` wrote `$fields['name']` from a file containing ZERO `PinGuard`
 * references, and implementing the ruling against the roster would have shipped a pin that one
 * live path walked straight through — the exact defect the ruling exists to remove, re-minted
 * by the fix for it. A roster is a snapshot; the population is a property of the tree. So the
 * population is DERIVED here, every run, and the rule is applied to whatever the derivation
 * returns rather than to a list somebody wrote down.
 *
 * POPULATION: every `->patchCard(` call in every `*.php` under `app/` — the same recipe
 * `PinGuard`'s docblock and the `PATCH /api/v3/tasks/{id}.json` row of
 * `docs/kanban-integration-contract.md` state, asked of PHP's own tokenizer so that a docblock
 * mention, a `{@see}` and the declaration itself are excluded by CONSTRUCTION rather than by a
 * regex the next spelling walks past. {@see KanbanClient::patchCard} is the single primitive a
 * field write is expressed in ({@see KanbanClient::archiveCard} deliberately is not — it sends
 * a top-level `_action` CONTROL key), so a producer absent from this census is absent from the
 * bridge — a PREMISE, and one this class no longer merely asserts: the raw verb is derived too
 * ({@see test_the_only_raw_patch_calls_in_app_are_the_two_client_primitives}), so a field write
 * spelled straight onto `->patch(` reds instead of arriving as a silence. Sites are keyed
 * `<path under app/>::<enclosing function>#<ordinal>` — never by line
 * number, whose only remediation ("re-derive the offsets") is the same action that absorbs a
 * real new producer without a second thought.
 *
 * ⭐ THE RULE IS DERIVED, NOT DECLARED, and that is the whole difference from a roster. A site
 * is clean when EITHER its field set is an array LITERAL naming no governed field, OR a
 * `PinGuard::` consult precedes it in the same function body. A field set the scanner cannot
 * read — a variable, a caller-built array — is treated as POSSIBLY GOVERNED and needs the
 * consult, which is the safe direction: the site that shipped the defect is exactly that shape.
 * There is no exemption list to add a new producer to, so a new producer cannot be exempted
 * quietly.
 *
 * ⛔ STATED BOUNDS — WHAT A GREEN RUN SAYS, AND IT IS LESS THAN THE CLASS NAME SUGGESTS.
 * It says exactly two things: no `patchCard` site writes a governed field without a
 * `PinGuard::` CALL somewhere textually before it in the same body, and every derived
 * producer CITES at least one test method that EXISTS. It does NOT say "no pinned card can
 * be written", and it does NOT say the cited tests assert what their names claim.
 *  - **The consult is verified to be PRESENT, not to be HONOURED, and that gap is wider than
 *    "somebody forgot to act on it".** A DEFEATED consult reads identically to a live one
 *    here: `false && PinGuard::isPinnedAgainst(...)`, or a call whose result is assigned and
 *    never read, satisfies this leg. That is DEMONSTRATED rather than reasoned about — at
 *    PR #651's R1 a live `name` producer that computed the verdict and discarded it, cited
 *    against an existing-but-unrelated test method, passed BOTH legs of this class, phpstan
 *    and pint.
 *  - **THE TWO LEGS DO NOT COMPOSE INTO THE GUARANTEE.** The scanner half cannot see a
 *    defeated consult, and the witness half only asks `method_exists()` — so the exploit
 *    above is not a hole in one leg that the other covers. It is a hole in both, at one site.
 *  - **What the witness leg IS worth, stated so nobody has to infer it:** it stops a producer
 *    from having NO evidence at all, which is the state `board_correct_card` was in and is a
 *    state that really happened. That is the whole of it. The evidence is read by a REVIEWER,
 *    and the behavioural tests {@see PIN_WITNESSES} cites — not this class — are what say a
 *    refusal fires.
 *  - **The honest fix was DECLINED ON COST, not overlooked:** parsing the honoured
 *    `if (…) { return; }` shape means either copying `GetCardTenantCheckCoverageTest`'s
 *    private scanner or hoisting a security-critical predicate inside an unrelated change.
 *    If this class is ever asked to carry the guarantee its name implies, that parse is the
 *    work, and it belongs in its own change.
 *  - **Control flow between the consult and the write is not modelled** — same body, textually
 *    earlier. A write reachable AROUND the consult is not caught here.
 *  - **`tests/` is not in the population.** A test double is not a writeback.
 *  - **A literal key that is not a plain quoted string** (an interpolation, a spread, a
 *    constant) is not read as a key, so the site's field set comes back UNREADABLE and the
 *    site is required to consult — loud, in the safe direction, never a silent pass.
 *  - **This says nothing about the STAGE half of the pin.** `moveCard(` / `archiveCard(` are a
 *    different population with a different rule; `PinGuard`'s docblock owns that recipe.
 */
class PinnedFieldWriteCoverageTest extends TestCase
{
    /** The one field-write primitive; its call sites are the population. */
    private const WRITE_METHOD = 'patchCard';

    /** The HTTP verb that primitive is expressed in; every other use of it is the premise check's subject. */
    private const RAW_WRITE_VERB = 'patch';

    /**
     * The only two bodies in `app/` that may reach the raw verb — the premise the population
     * rests on.
     *
     * ⛔ THIS IS NOT THE EXEMPTION LIST THIS CLASS FORBIDS, and the difference is which way it
     * fails. An exemption list lets a new PRODUCER pass by being added to it. This one lets
     * nothing pass: a new `->patch(` site reds until somebody decides, in that change, whether
     * the pin governs the primitive they just wrote. Adding a line here to silence that red is
     * the misuse, and the assertion message says so.
     *
     * @var list<string>
     */
    private const RAW_PATCH_SITES = [
        'Bridge/Writeback/KanbanClient.php::archiveCard#1',
        'Bridge/Writeback/KanbanClient.php::patchCard#1',
    ];

    /** The guard a governed write must consult. A call through anything else is not the consult. */
    private const GUARD_CLASS = 'PinGuard';

    /** What {@see fieldSetAt} answers for a field set the tokenizer cannot read off the call. */
    private const UNREADABLE_FIELD_SET = '(not an array literal at the call site)';

    /** What {@see consultBefore} answers when no consult precedes the write. */
    private const NO_CONSULT = '(no PinGuard consult in this body)';

    /**
     * ⭐ EVERY DERIVED SITE, MAPPED TO THE TESTS THAT DRIVE IT AGAINST A PINNED CARD — the
     * BEHAVIOURAL half of this check, and the half the ruling on card#8557 owes: one test
     * that sees the refusal fire LOUDLY, and one that proves an ordinary field write still
     * succeeds on the same pinned row. Both are required over EVERY producer the derivation
     * returns, not over a hand-listed two, which is why the keys are asserted SET-EQUAL to
     * the derived population rather than merely checked for membership: a NEW producer reds
     * until it has evidence, and evidence for a producer that has gone reds as a stale
     * exemption.
     *
     * ⛔ THIS IS NOT THE PRODUCER ROSTER, and reading it as one is the mistake this whole
     * class exists to make impossible. A site is here BECAUSE the scanner found it. The
     * roster is a property of the tree.
     *
     * ⚠ WHAT IT CHECKS AND WHAT IT DOES NOT: the method is verified to EXIST, not to assert
     * what its name claims. A reviewer reads the tests; this leg stops a producer from
     * having none at all — which is the state `board_correct_card` was in. It does NOT
     * close the defeated-consult hole in the leg above, and reading the two as though they
     * compose is the specific mistake this class's STATED BOUNDS warns about — an entry
     * here citing a real-but-unrelated method is green, and was built to prove it.
     *
     * @var array<string, list<string>>
     */
    private const PIN_WITNESSES = [
        // GOVERNED — writes `name`. Refusal + its other pin spelling; there is no ungoverned
        // write on this leg to prove still lands, because it writes `{name}` and nothing else.
        'Bridge/Handlers/KanbanCoordCardHandler.php::restampNames#1' => [
            KanbanCoordCardHandlerTest::class.'::test_a_pinned_card_is_not_restamped_and_the_refusal_is_loud',
            KanbanCoordCardHandlerTest::class.'::test_a_card_tagged_no_automove_is_not_restamped_either',
        ],
        // GOVERNED — the DL-328 twin of the leg above, same shape.
        'Bridge/Handlers/KanbanDependabotCardHandler.php::restampNames#1' => [
            KanbanDependabotCardHandlerTest::class.'::test_a_pinned_card_is_not_restamped_and_the_refusal_is_loud',
            KanbanDependabotCardHandlerTest::class.'::test_a_card_tagged_no_automove_is_not_restamped_either',
        ],
        // GOVERNED, and the only site that carries BOTH halves of the ruling on one row: the
        // `name` correction is refused, and the `description` correction on the SAME pinned
        // card still lands — which is what says the pin was NARROWED rather than turned into
        // a blanket field freeze.
        'Bridge/Tools/BoardCorrectCardTool.php::call#1' => [
            AgentToolsCallTest::class.'::test_a_name_correction_on_a_pinned_card_is_refused_by_name',
            AgentToolsCallTest::class.'::test_a_name_correction_is_refused_on_a_no_automove_tag_too',
            AgentToolsCallTest::class.'::test_a_pinned_card_still_takes_a_description_correction',
            AgentToolsCallTest::class.'::test_a_correction_carrying_a_name_beside_a_description_writes_neither',
        ],
        // UNGOVERNED BY THE FIELD RULE, and refused anyway — by the STAGE rule, at this
        // primitive's CALL SITES rather than here (`PinGuard`'s docblock owns that census).
        // The witness pins both facts in one delivery: no stage PATCH, and the payload PATCH
        // beside it lands.
        'Bridge/Writeback/KanbanClient.php::moveCard#1' => [
            KanbanMoveCardHandlerTest::class.'::test_pinned_merge_still_stamps_the_correlation_refs_it_refuses_to_move_on',
        ],
        // UNGOVERNED — the correlation stamp, and the reason a blanket freeze was rejected:
        // dropping it would strand a held card OUTSIDE `bridge:reconcile`'s population, so
        // the backstop could never complete the move once the pin was lifted.
        'Bridge/Writeback/KanbanClient.php::stampCorrelationRefs#1' => [
            KanbanMoveCardHandlerTest::class.'::test_pinned_merge_still_stamps_the_correlation_refs_it_refuses_to_move_on',
        ],
        // UNGOVERNED — the DL-193 draft overlay, and an explicit card#8557 ruling rather than
        // an oversight: its add-if-missing guard reads `block_reason` only, so a TAG-only pin
        // still takes the write. The witness carries the three reasons that is the designed
        // outcome, and reds if any of them stops holding.
        'Bridge/Writeback/KanbanClient.php::setBlockReason#1' => [
            KanbanBlockReasonHandlerTest::class.'::test_a_tag_pinned_card_still_takes_the_draft_marker_and_that_is_the_ruling',
        ],
    ];

    /**
     * The RULE, over the whole derived population: a `patchCard` site whose field set is a
     * literal naming no governed field needs nothing; every other site must consult the pin
     * first.
     */
    public function test_no_patch_card_site_writes_a_governed_field_without_consulting_the_pin(): void
    {
        $unguarded = [];
        foreach (self::sites() as $key => $site) {
            if ($site['consult'] !== self::NO_CONSULT) {
                continue;
            }
            if ($site['fields'] !== self::UNREADABLE_FIELD_SET && ! self::governs($site['fields'])) {
                continue;
            }
            $unguarded[$key] = $site['fields'];
        }

        $this->assertSame(
            [],
            $unguarded,
            'these `->patchCard(` sites in app/ can write a field the DL-178 pin governs and never ask '
            .'PinGuard whether the card is held. Each value is the field set the scanner could read off '
            .'the call — `'.self::UNREADABLE_FIELD_SET.'` means it could not read one, which is treated as '
            .'possibly-governed on purpose (the producer that shipped this defect was exactly that shape). '
            .'A site that genuinely writes only ungoverned fields fixes this by spelling its field set as a '
            .'literal AT the call; anything else asks PinGuard::refusesFieldWrite() (which alerts, for a '
            .'writeback arm with a repo and an outcome) or PinGuard::isPinnedAgainst() (for a caller that '
            .'reports its own refusal), and then adds a witness to PIN_WITNESSES. ⛔ Do NOT answer this '
            .'red by adding the site to a list: there is no exemption list here, because the prose roster '
            .'this class replaced missed a producer that was live and pin-blind.',
        );
    }

    /**
     * The other leg: EVERY derived producer has behavioural evidence about what it does to a
     * PINNED card, and every citation still names a test that exists. Without it the leg
     * above would be satisfied by a consult whose result is dropped on the floor — a call is
     * not a guard.
     */
    public function test_every_derived_producer_has_behavioural_evidence_on_a_pinned_card(): void
    {
        $derived = array_keys(self::sites());
        sort($derived);
        $declared = array_keys(self::PIN_WITNESSES);
        sort($declared);

        $this->assertSame(
            $declared,
            $derived,
            'the set of `->patchCard(` producers in app/ is not the set this class has evidence for. A NEW '
            .'producer needs a test that drives it against a PINNED card and asserts what happens — the '
            .'refusal fired LOUDLY if it writes a field the pin governs, or the write still landing if it '
            .'does not. Both are the acceptance criterion the card#8557 ruling shipped with, and both are '
            .'owed over EVERY producer this derivation returns rather than over a hand-listed two: the '
            .'roster that preceded this class named two and the code had three. A MISSING producer is a '
            .'stale citation — delete the entry.',
        );

        foreach (self::PIN_WITNESSES as $site => $witnesses) {
            $this->assertNotSame([], $witnesses, "no behavioural evidence is cited for {$site}.");
            foreach ($witnesses as $witness) {
                [$class, $method] = explode('::', $witness, 2);
                $this->assertTrue(
                    method_exists($class, $method),
                    "the witness cited for {$site} does not exist: {$witness}. A citation that names no test "
                    .'method proves nothing about the producer it is cited for.',
                );
            }
        }
    }

    /**
     * The RULING itself, pinned verbatim — because this class enforces a rule whose CONTENT
     * lives in one const, and a silent edit to that const would quietly un-govern every
     * producer above while every assertion here stayed green.
     */
    public function test_the_governed_field_set_is_the_one_the_ruling_names(): void
    {
        $this->assertSame(
            ['name'],
            PinGuard::PINNED_FIELDS,
            'PinGuard::PINNED_FIELDS has moved. That is the card#8557 ruling itself — a pinned card is '
            .'immune to a STAGE move and to a NAME write, and other field writes stay in policy — so '
            .'changing it changes what the system REFUSES and is an operator decision, not a refactor. '
            .'Widening it silently would also strand every producer of the new field on the unguarded side '
            .'of the leg above until someone re-ran it. Move the decision log entry and docs/writeback.md '
            .'in the same commit, then this assertion.',
        );
    }

    /**
     * The instrument's own control, both directions on one fixture whose answer is known.
     * Without it a scanner that matched nothing would report a clean repo, and one that matched
     * comment prose would report noise as defects — both wearing the same green.
     */
    public function test_the_scanner_discriminates_a_real_write_from_a_comment(): void
    {
        $source = <<<'PHP'
        <?php
        class Fixture
        {
            /** A docblock naming ->patchCard( and PinGuard::refusesFieldWrite(. */
            public function ungovernedLiteral(): void
            {
                // A comment mentioning $client->patchCard($id, ['name' => $x]).
                $client->patchCard($id, ['workflow_stage_id' => $stage]);
            }

            public function governedLiteralUnguarded(): void
            {
                $client->patchCard($id, ['name' => $title, 'description' => $body]);
            }

            public function governedLiteralGuarded(): void
            {
                if (PinGuard::refusesFieldWrite($alerts, $card, $fields, 'arm', 'w', $id, $r, $o)) {
                    return;
                }
                $client->patchCard($id, ['name' => $title]);
            }

            public function dynamicUnguarded(): void
            {
                $client->patchCard($id, $fields);
            }

            public function dynamicGuardedByPredicate(): void
            {
                if (PinGuard::isPinnedAgainst($row, $fields)) {
                    throw new ToolRefusalException(PinGuard::REASON);
                }
                $client->patchCard($id, $fields);
            }

            public function patchCard(int $id, array $fields): void
            {
                $this->http()->patch("/tasks/{$id}.json", $fields);
            }

            public function constantReadIsNotAConsult(): void
            {
                $reason = PinGuard::REASON;
                $client->patchCard($id, ['name' => $title]);
            }
        }
        PHP;

        $this->assertSame(
            [
                'Fixture.php::ungovernedLiteral#1' => ['fields' => 'workflow_stage_id', 'consult' => self::NO_CONSULT],
                'Fixture.php::governedLiteralUnguarded#1' => ['fields' => 'description, name', 'consult' => self::NO_CONSULT],
                'Fixture.php::governedLiteralGuarded#1' => ['fields' => 'name', 'consult' => 'PinGuard::refusesFieldWrite'],
                'Fixture.php::dynamicUnguarded#1' => ['fields' => self::UNREADABLE_FIELD_SET, 'consult' => self::NO_CONSULT],
                'Fixture.php::dynamicGuardedByPredicate#1' => ['fields' => self::UNREADABLE_FIELD_SET, 'consult' => 'PinGuard::isPinnedAgainst'],
                'Fixture.php::constantReadIsNotAConsult#1' => ['fields' => 'name', 'consult' => self::NO_CONSULT],
            ],
            SourceScan::sites($source, 'Fixture.php', self::writeSiteAt(...)),
            'the scanner no longer reads a call the way this fixture states. Every arm here is a way the '
            .'population would otherwise fill with noise or lose a real producer: the primitive\'s own '
            .'DECLARATION names `patchCard` and is not reached through `->`, so it contributes no site (the '
            .'absence of a `patchCard#…` key is that assertion); a docblock and a line comment name both the '
            .'write and the guard and are neither; a `PinGuard::REASON` read is not a consult; and a field '
            .'set that is not a literal is UNREADABLE rather than empty.',
        );
    }

    /**
     * THE POPULATION'S OWN PREMISE, DERIVED RATHER THAN ASSERTED. Everything above polices
     * `->patchCard(` sites, and the SCOPE of that rests on one claim: `patchCard` is the
     * single primitive a card field write is expressed in, so a producer absent from that
     * census is absent from the bridge. The claim is true at this head, and nothing was
     * keeping it true — while the shape that breaks it is already in-tree, because
     * {@see \App\Bridge\Writeback\KanbanClient::archiveCard} is a SECOND raw `->patch(` in
     * the same class. A future `renameCard()` spelled
     * `$this->http()->patch("/tasks/{$id}.json", ['name' => $n])` would be a governed field
     * write the census structurally cannot see, and it would arrive as a SILENCE rather than
     * as a red.
     *
     * So the raw verb is derived too and pinned to the two bodies that may legitimately reach
     * it. A third reds HERE, naming the site, instead of passing unnoticed THERE. It does not
     * forbid a new primitive — it forbids one arriving without anyone deciding whether the pin
     * reaches it.
     */
    public function test_the_only_raw_patch_calls_in_app_are_the_two_client_primitives(): void
    {
        $control = <<<'PHP'
        <?php
        class Fixture
        {
            /** A docblock naming ->patch( and $this->http()->patch(. */
            public function rawPatch(): void
            {
                // A comment mentioning $this->http()->patch("/tasks/1.json", []).
                $this->http()->patch("/tasks/{$id}.json", $fields);
            }

            public function namedPrimitiveIsNotTheRawVerb(): void
            {
                $client->patchCard($id, ['name' => $t]);
                $client->patchCardIfHeld($id, $fields);
            }

            public function otherVerbsAreNotTheSubject(): void
            {
                $this->http()->post('/tasks.json', $task);
                $this->http()->delete('/webhooks/1.json');
            }

            public function twoInOneBody(): void
            {
                $this->http()->patch('/a', $x);
                $client?->patch('/b', $y);
            }
        }
        PHP;

        $this->assertSame(
            [
                'Fixture.php::rawPatch#1' => true,
                'Fixture.php::twoInOneBody#1' => true,
                'Fixture.php::twoInOneBody#2' => true,
            ],
            SourceScan::sites($control, 'Fixture.php', self::rawPatchSiteAt(...)),
            'the raw-verb predicate no longer reads a call the way this fixture states, and the '
            .'population below is only as good as it. Every arm is a way it would otherwise mislead: '
            .'a docblock and a line comment name the verb and are not sites; `patchCard`/'
            .'`patchCardIfHeld` are DIFFERENT tokens and must not match a prefix; `post`/`delete` are '
            .'not the subject; and a second site in one body gets its own ordinal rather than '
            .'inheriting the first one\'s.',
        );

        $derived = array_keys(SourceScan::sitesInApp(self::rawPatchSiteAt(...)));
        sort($derived);

        $this->assertSame(
            self::RAW_PATCH_SITES,
            $derived,
            'a `->patch(` call in app/ has appeared outside the two client primitives that own the '
            .'verb. That matters because the pin census above polices `->patchCard(` sites ONLY, on '
            .'the premise that a card field write can be expressed no other way — so a field write '
            .'spelled straight onto the raw verb is invisible to it and reaches a pinned card '
            .'unasked. Express the write through KanbanClient::patchCard (which the census reads), '
            .'or, if a genuinely new primitive is right, decide in that change whether the pin '
            .'governs it and move this list with the decision — never to make a red go away.',
        );
    }

    /**
     * Every `->patchCard(` site under `app/`, keyed as the class docblock states, each carrying
     * the field set the scanner could read off the call and the `PinGuard::` consult that
     * precedes it in the same body.
     *
     * @return array<string, array{fields: string, consult: string}>
     */
    private static function sites(): array
    {
        /** @var array<string, array{fields: string, consult: string}> $sites */
        $sites = SourceScan::sitesInApp(self::writeSiteAt(...));

        return $sites;
    }

    /**
     * The PREDICATE this class owns: is the token at $index a call to the field-write
     * primitive, and if so what does it write and what did it ask first?
     *
     * ⛔ `null` is the ONLY absence ({@see SourceScan::sites}); a site that writes nothing
     * governed and asks nothing still lands in the population, keyed with both answers, because
     * a census that dropped the clean sites could not tell "no producers" from "no scanner".
     *
     * @param  list<array{0: int|string, 1: string}>  $tokens
     * @param  int  $scopeStart  the index at which the enclosing body began
     * @return array{fields: string, consult: string}|null
     */
    private static function writeSiteAt(array $tokens, int $index, int $scopeStart): ?array
    {
        if ($tokens[$index][0] !== T_STRING || $tokens[$index][1] !== self::WRITE_METHOD) {
            return null;
        }

        $arrow = $tokens[$index - 1][0] ?? null;
        $isCall = ($arrow === T_OBJECT_OPERATOR || $arrow === T_NULLSAFE_OBJECT_OPERATOR)
            && ($tokens[$index + 1][1] ?? null) === '(';

        if (! $isCall) {
            return null;
        }

        return [
            'fields' => self::fieldSetAt($tokens, $index + 1),
            'consult' => self::consultBefore($tokens, $scopeStart, $index),
        ];
    }

    /**
     * The field set the call at $open writes, as a sorted comma-joined key list — or
     * {@see UNREADABLE_FIELD_SET} when the second argument is not an array literal the scanner
     * can read keys off.
     *
     * ⚠ UNREADABLE IS NOT EMPTY, and conflating them is how this leg would fail OPEN: an
     * unreadable field set may contain any field, so it is treated as possibly-governed.
     *
     * @param  list<array{0: int|string, 1: string}>  $tokens
     * @param  int  $open  the index of the call's opening paren
     */
    private static function fieldSetAt(array $tokens, int $open): string
    {
        $comma = self::topLevelComma($tokens, $open);
        if ($comma === null || ($tokens[$comma + 1][1] ?? null) !== '[') {
            return self::UNREADABLE_FIELD_SET;
        }

        $keys = [];
        $unreadable = false;
        for ($i = $comma + 2, $depth = 1; $i < count($tokens) && $depth > 0; $i++) {
            $depth += match ($tokens[$i][1]) {
                '[', '(' => 1,
                ']', ')' => -1,
                default => 0,
            };
            if ($depth !== 1 || ($tokens[$i + 1][1] ?? null) !== '=>') {
                continue;
            }
            // A key that is not a plain quoted string (an interpolation, a constant, a
            // spread) is a field name this scanner cannot resolve — the site's whole field
            // set is then unreadable, never silently short by one key.
            if ($tokens[$i][0] !== T_CONSTANT_ENCAPSED_STRING) {
                $unreadable = true;

                continue;
            }
            $keys[] = trim($tokens[$i][1], '\'"');
        }

        if ($unreadable || $keys === []) {
            return self::UNREADABLE_FIELD_SET;
        }
        sort($keys);

        return implode(', ', $keys);
    }

    /**
     * The index of the call's first TOP-LEVEL comma, or null when it has none.
     *
     * @param  list<array{0: int|string, 1: string}>  $tokens
     */
    private static function topLevelComma(array $tokens, int $open): ?int
    {
        for ($i = $open, $depth = 0; $i < count($tokens); $i++) {
            $depth += match ($tokens[$i][1]) {
                '(', '[' => 1,
                ')', ']' => -1,
                default => 0,
            };
            if ($depth === 0) {
                return null;
            }
            if ($depth === 1 && $tokens[$i][1] === ',') {
                return $i;
            }
        }

        return null;
    }

    /**
     * The first `PinGuard::<method>(` CALL between $from (where the enclosing body began) and
     * $before (the write), as `PinGuard::<method>` — or {@see NO_CONSULT}.
     *
     * ⚠ A CALL, not a `::` read: `PinGuard::REASON` is the reason code a caller stamps into its
     * own log line, and a site that only names the constant has asked the guard nothing.
     *
     * @param  list<array{0: int|string, 1: string}>  $tokens
     */
    private static function consultBefore(array $tokens, int $from, int $before): string
    {
        for ($i = $from; $i < $before; $i++) {
            if (($tokens[$i][1] ?? null) !== self::GUARD_CLASS
                || ($tokens[$i + 1][0] ?? null) !== T_DOUBLE_COLON
                || ($tokens[$i + 2][0] ?? null) !== T_STRING
                || ($tokens[$i + 3][1] ?? null) !== '(') {
                continue;
            }

            return self::GUARD_CLASS.'::'.$tokens[$i + 2][1];
        }

        return self::NO_CONSULT;
    }

    /**
     * The premise check's predicate: is the token at $index a call to the RAW HTTP verb the
     * field-write primitive is expressed in? `true` for a site, `null` for anything else —
     * {@see SourceScan::sites} treats `null` as the only absence, so a site carries a value
     * rather than a shape.
     *
     * ⚠ It matches the TOKEN, not a prefix: `patchCard` and `patchCardIfHeld` are different
     * T_STRINGs, so the named primitive's own callers are not raw-verb sites.
     *
     * @param  list<array{0: int|string, 1: string}>  $tokens
     * @param  int  $scopeStart  the index at which the enclosing body began; unused — this
     *                           predicate's subject is the call alone, not what precedes it
     */
    private static function rawPatchSiteAt(array $tokens, int $index, int $scopeStart): ?bool
    {
        if ($tokens[$index][0] !== T_STRING || $tokens[$index][1] !== self::RAW_WRITE_VERB) {
            return null;
        }

        $arrow = $tokens[$index - 1][0] ?? null;
        $isCall = ($arrow === T_OBJECT_OPERATOR || $arrow === T_NULLSAFE_OBJECT_OPERATOR)
            && ($tokens[$index + 1][1] ?? null) === '(';

        return $isCall ? true : null;
    }

    /** Whether a readable field-set string names a field {@see PinGuard::PINNED_FIELDS} governs. */
    private static function governs(string $fields): bool
    {
        return array_intersect(explode(', ', $fields), PinGuard::PINNED_FIELDS) !== [];
    }
}
