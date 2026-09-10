<?php

namespace Tests\Feature\AgentTools;

use App\Bridge\Tools\BoardTakeCardTool;
use App\Bridge\Tools\BoardToolDispatcher;
use App\Bridge\Tools\BoardToolsRegistry;
use App\Bridge\Tools\SeatKanbanUser;
use App\Bridge\Tools\Tool;
use Tests\Support\SourceScan;
use Tests\TestCase;

/**
 * THE STRUCTURAL ENFORCER FOR THE ONE PROPERTY card#9170's OPERATOR APPROVAL RESTS ON:
 * a seat may set the assignee to ITS OWN kanban user and no other.
 *
 * ⭐ WHY IT EXISTS, and the adversarial review that found the gap is the argument.
 * DL-372 argued the property was a CONSTRUCTION rather than a validation — it rejected a
 * validated `assigned_user_id` argument for putting the property *"one forgotten branch
 * away from being false"*. As first shipped, {@see SeatKanbanUser} carried a docblock
 * asserting, twice, that nothing there could look up another agent. **That was false of the
 * class.** {@see SeatKanbanUser::forCallingAgent} iterates the whole roster and matches on
 * the name it is handed, so it answers about ANY agent — a three-agent roster returns three
 * different ids for three different names. The self-only property was, and is, a property of
 * the CALL GRAPH: it holds because every call site passes the `$agentName` the DOOR derived.
 * There was exactly one such site and nothing that would red if a second appeared — which is
 * the same distance the DL rejected, wearing a different hat.
 *
 * ⛔ AND THE TWO ALTERNATIVES DL-372 PARKS ARE BOTH NATURAL SECOND CALLERS — a
 * `board_release_card` sibling, and rendering a seat NAME beside `assigned_user_id` in
 * `board_my_cards`. Neither is hypothetical; both are written down as things somebody may
 * come back and build.
 *
 * ⭐ WHY A GUARD AND NOT A TYPE. The reviewer's preferred fix was a `DerivedAgentName` value
 * object *"only the two front doors can mint"*, so that the signature carries the guarantee.
 * That was measured against the language and DECLINED, on mechanism rather than cost:
 *  - **PHP has no friend or package visibility.** A private constructor forces minting
 *    through a factory, and the factory's argument types (`ResolvedBoardToolAgent`,
 *    `AgentConfig`) are both constructible or loadable by any code in `app/`. "Only the doors
 *    can mint it" is not expressible; what would ship is a type that LOOKS like a guarantee.
 *  - **The value object has to reach the tool**, which means `Tool::call`'s signature — a
 *    documented extension point operators register their own tools against
 *    ({@see BoardToolsRegistry::register}). Breaking it buys a soft
 *    guarantee.
 *  - **This guard is FALSIFIABLE and has been watched fail** (both directions, below); the
 *    type would not have been. Canon #9: a pass is evidence only if failure was possible.
 * If a future change does thread a derived-name type through the door, this class is what
 * says the call graph still holds while that lands — it is not an alternative to it.
 *
 * POPULATION, re-derived every run: every `SeatKanbanUser::<method>(` call in every `*.php`
 * under `app/`, on PHP's own tokenizer ({@see SourceScan::sitesInApp}), so a mention in a
 * docblock or a `{@see}` is excluded by CONSTRUCTION rather than by a regex the next spelling
 * walks past. Sites are keyed `<path under app/>::<enclosing function>#<ordinal>`.
 *
 * THE RULE, over that whole population: a site's FIRST argument must be the bare variable
 * `$agentName` — the parameter `Tool::call` receives from
 * {@see BoardToolDispatcher}, which receives it from a door — and that
 * variable must not be REBOUND anywhere in the enclosing body before the call.
 *
 * ⛔ STATED BOUNDS — what a green run says, and it is narrower than the class name.
 *  - **It checks the SPELLING of the argument and one way of corrupting it.** `$agentName`
 *    is the name every board tool receives the door-derived value under, and the rebind leg
 *    closes the obvious defeat (`$agentName = $args['agent'];` earlier in the body). It does
 *    NOT prove the dispatcher still derives that value from a door — that is
 *    `AgentToolsCallTest` / `ToolsCallCommandTest`'s, and those drive real doors.
 *  - **Control flow is not modelled**: the rebind test is textual and body-scoped, exactly as
 *    `PinnedFieldWriteCoverageTest`'s consult test is. A rebind in a nested closure counts
 *    (it is in the same body); one in a called method does not.
 *  - **`tests/` is not in the population.** A test double is not a door.
 *  - **A site the walk cannot attribute to a method lands as `(file scope)`** rather than
 *    being skipped — loud, in the safe direction.
 */
class SeatIdentityCallSiteGuardTest extends TestCase
{
    /** The class whose call sites are the population. */
    private const IDENTITY_CLASS = 'SeatKanbanUser';

    /**
     * The ONE argument spelling a call site may pass: the parameter name every {@see Tool}
     * receives the door-derived agent name under.
     */
    private const DOOR_DERIVED_ARGUMENT = '$agentName';

    /** What {@see identitySiteAt} reports for a first argument that is not a bare variable. */
    private const NOT_A_BARE_VARIABLE = '(not a bare variable at the call site)';

    /**
     * Every `SeatKanbanUser::` call site in `app/`, each with the reason it is legitimate.
     *
     * ⛔ THIS IS NOT AN EXEMPTION LIST. A site listed here still has to satisfy the rule; the
     * list exists so that a site APPEARING or GOING is a red in its own right (set equality,
     * both directions). Adding an entry does not make a violating site pass.
     *
     * @var array<string, string>
     */
    private const DISPOSITIONED = [
        'Bridge/Tools/BoardTakeCardTool.php::call#1' => 'THE call site the self-only property rests on: `$agentName` is `Tool::call`\'s own parameter, which BoardToolDispatcher passes from the door that derived it (the bearer on http, the pinned forced command on ssh), and it is never rebound in this body. The id it returns is the ONLY value the tool writes to `assigned_user_id`.',
    ];

    /**
     * SET EQUALITY, both directions. A NEW call site is an unanswered question — does it pass
     * a door-derived name? — and a MISSING one is a stale disposition.
     */
    public function test_every_seat_identity_call_site_in_app_is_dispositioned(): void
    {
        $derived = array_keys(self::sites());
        sort($derived);
        $declared = array_keys(self::DISPOSITIONED);
        sort($declared);

        $this->assertSame(
            $declared,
            $derived,
            'the set of `'.self::IDENTITY_CLASS.'::` call sites in app/ is not the set this class dispositions. '
            .'A NEW site is the shape card#9170\'s operator approval rests on: that class answers about WHATEVER '
            .'agent name it is handed, so "a seat may claim only for itself" is a property of the CALL GRAPH and '
            .'nothing else. Establish that the new site passes the door-derived `'.self::DOOR_DERIVED_ARGUMENT.'` '
            .'and add it here WITH that reason — and if it passes anything else, that is a change to what the '
            .'board-tools door lets one seat do to another, which is an operator decision and not a refactor. '
            .'A MISSING site is a stale disposition: delete the entry.',
        );
    }

    /**
     * THE RULE ITSELF, applied to whatever the derivation returns rather than to the list
     * above — so a site cannot be made legitimate by being written down.
     */
    public function test_no_seat_identity_call_site_passes_anything_but_the_door_derived_agent_name(): void
    {
        $violations = [];
        foreach (self::sites() as $key => $site) {
            if ($site['first_arg'] !== self::DOOR_DERIVED_ARGUMENT) {
                $violations[$key] = 'first argument is '.$site['first_arg'];

                continue;
            }
            if ($site['rebound']) {
                $violations[$key] = 'passes '.self::DOOR_DERIVED_ARGUMENT.', but that variable is REBOUND earlier in the same body';
            }
        }

        $this->assertSame(
            [],
            $violations,
            'these `'.self::IDENTITY_CLASS.'::` call sites do not pass the agent name the DOOR derived. '
            .'That class resolves whatever name it is given — hand it another seat\'s and it returns that '
            .'seat\'s kanban user id — so a site passing anything else can write one seat\'s claim under '
            .'another seat\'s identity, which is exactly what card#9170 was approved on the absence of. '
            .'Pass `Tool::call`\'s own '.self::DOOR_DERIVED_ARGUMENT.' parameter, unmodified.',
        );
    }

    /**
     * THE INSTRUMENT'S OWN CONTROL — both directions on one fixture whose answer is known.
     * Without it a scanner matching nothing reports a clean repo, and one matching prose
     * reports noise as defects; both wear the same green (canon #9).
     */
    public function test_the_scanner_discriminates_a_real_call_from_prose_and_reads_its_first_argument(): void
    {
        $source = <<<'PHP'
        <?php
        class Fixture
        {
            /** A docblock naming SeatKanbanUser::forCallingAgent( and $agentName. */
            public function doorDerived(): void
            {
                // A comment mentioning SeatKanbanUser::forCallingAgent($somethingElse, 'x').
                $id = SeatKanbanUser::forCallingAgent($agentName, $this->name());
            }

            public function payloadDerived(): void
            {
                $id = SeatKanbanUser::forCallingAgent($args['agent'], 'tool');
            }

            public function literalName(): void
            {
                $id = SeatKanbanUser::forCallingAgent('other-seat', 'tool');
            }

            public function reboundFirst(): void
            {
                $agentName = $args['agent'];
                $id = SeatKanbanUser::forCallingAgent($agentName, 'tool');
            }

            public function anotherClassIsNotTheSubject(): void
            {
                $x = SomeOtherClass::forCallingAgent($whatever, 'tool');
            }

            public function twoInOneBody(): void
            {
                SeatKanbanUser::forCallingAgent($agentName, 'a');
                SeatKanbanUser::somethingElse($nope, 'b');
            }
        }
        PHP;

        $this->assertSame(
            [
                // A docblock and a line comment name the call and are NOT sites.
                'Fixture.php::doorDerived#1' => ['first_arg' => '$agentName', 'rebound' => false],
                // A payload-derived argument is read, and read as itself.
                'Fixture.php::payloadDerived#1' => ['first_arg' => self::NOT_A_BARE_VARIABLE, 'rebound' => false],
                'Fixture.php::literalName#1' => ['first_arg' => self::NOT_A_BARE_VARIABLE, 'rebound' => false],
                // The right spelling, defeated — the leg that stops the name being the check.
                'Fixture.php::reboundFirst#1' => ['first_arg' => '$agentName', 'rebound' => true],
                // A second site in one body gets its own ordinal rather than inheriting the
                // first's, and EVERY method on the class is a site — a future second resolver
                // must not be able to arrive outside the census. ⛔ `anotherClassIsNotTheSubject`
                // has no key at all: a DIFFERENT class with the same method name is not a site.
                'Fixture.php::twoInOneBody#1' => ['first_arg' => '$agentName', 'rebound' => false],
                'Fixture.php::twoInOneBody#2' => ['first_arg' => '$nope', 'rebound' => false],
            ],
            SourceScan::sites($source, 'Fixture.php', self::identitySiteAt(...)),
            'the scanner no longer reads a `'.self::IDENTITY_CLASS.'::` call the way this fixture states, and '
            .'the population above is only as good as it. Every arm is a way it would otherwise mislead.',
        );
    }

    /**
     * The property the whole guard exists for, asserted DIRECTLY rather than left to be
     * inferred from the call graph — and it is the assertion that makes the corrected
     * docblock's confession checkable: this class DOES answer about another agent.
     *
     * ⚠ It is a statement about the CLASS, not a defect. If this ever stops holding — if
     * `forCallingAgent` grows a way to refuse a name that is not the caller's — the call-site
     * guard becomes belt-and-braces rather than the whole boundary, and THAT is the change
     * worth reviewing.
     */
    public function test_the_identity_resolver_answers_about_whatever_name_it_is_handed(): void
    {
        $dir = sys_get_temp_dir().'/seat-identity-'.uniqid();
        mkdir($dir, 0o700, true);
        foreach (['me' => 111, 'other' => 222, 'pm' => 333] as $name => $id) {
            file_put_contents($dir."/{$name}.yml", "identity:\n  kanban_user_id: {$id}\nsubscriptions: []\n");
        }
        config(['bridge.config_dir' => $dir]);

        try {
            $this->assertSame(111, SeatKanbanUser::forCallingAgent('me', 'board_take_card'));
            $this->assertSame(
                222,
                SeatKanbanUser::forCallingAgent('other', 'board_take_card'),
                'this is the measured fact the class docblock now states: handed another seat\'s name it '
                .'returns that seat\'s id. The self-only guarantee is the call graph\'s, which is why the '
                .'other tests in this class exist.'
            );
            $this->assertSame(333, SeatKanbanUser::forCallingAgent('pm', 'board_take_card'));
        } finally {
            array_map('unlink', (array) glob($dir.'/*.yml'));
            rmdir($dir);
        }
    }

    /**
     * The tool that owns the one dispositioned site still exists and still names the door's
     * parameter — so a rename that moved the site out of the population reds here rather than
     * emptying the census quietly.
     */
    public function test_the_only_caller_is_the_take_tool(): void
    {
        $this->assertTrue(
            method_exists(BoardTakeCardTool::class, 'call'),
            'the dispositioned call site names BoardTakeCardTool::call; if that method has gone, the '
            .'disposition is describing code that does not exist.'
        );
    }

    /**
     * @return array<string, array{first_arg: string, rebound: bool}>
     */
    private static function sites(): array
    {
        /** @var array<string, array{first_arg: string, rebound: bool}> $sites */
        $sites = SourceScan::sitesInApp(self::identitySiteAt(...));

        return $sites;
    }

    /**
     * THE PREDICATE this class owns: is the token at $index a static call on
     * {@see IDENTITY_CLASS}, and if so what is its first argument and was that variable
     * rebound earlier in the same body?
     *
     * ⛔ The class token is matched EXACTLY, so `SomeOtherClass::forCallingAgent(` is not a
     * site — the subject is this resolver, not a method name.
     *
     * @param  list<array{0: int|string, 1: string}>  $tokens
     * @param  int  $scopeStart  the index at which the enclosing body began
     * @return array{first_arg: string, rebound: bool}|null
     */
    private static function identitySiteAt(array $tokens, int $index, int $scopeStart): ?array
    {
        if (($tokens[$index][0] ?? null) !== T_STRING || $tokens[$index][1] !== self::IDENTITY_CLASS) {
            return null;
        }
        if (($tokens[$index + 1][0] ?? null) !== T_DOUBLE_COLON) {
            return null;
        }
        // A constant fetch or a `::class` reference on this class is not a call; only a
        // method name followed by an opening paren is.
        if (($tokens[$index + 2][0] ?? null) !== T_STRING || ($tokens[$index + 3][1] ?? null) !== '(') {
            return null;
        }

        $first = $tokens[$index + 4] ?? null;
        $firstIsBareVariable = $first !== null
            && $first[0] === T_VARIABLE
            && in_array($tokens[$index + 5][1] ?? null, [',', ')'], true);

        return [
            'first_arg' => $firstIsBareVariable ? $first[1] : self::NOT_A_BARE_VARIABLE,
            'rebound' => $firstIsBareVariable && self::reboundBefore($tokens, $scopeStart, $index, $first[1]),
        ];
    }

    /**
     * Whether $variable is ASSIGNED anywhere in this body before the call — the leg that stops
     * the argument's NAME from being the whole check. Textual and body-scoped, the same reach
     * `PinnedFieldWriteCoverageTest`'s consult test has, and its bound is stated there and in
     * this class's docblock.
     *
     * `=` only, and deliberately not `.=` / `??=`: those are token types of their own, and a
     * compound assignment to this variable would fail the equality read anyway once it landed
     * — what this leg is for is the plain `$agentName = <anything>` rebind.
     *
     * @param  list<array{0: int|string, 1: string}>  $tokens
     */
    private static function reboundBefore(array $tokens, int $scopeStart, int $call, string $variable): bool
    {
        for ($i = $scopeStart; $i < $call; $i++) {
            if ($tokens[$i][0] === T_VARIABLE && $tokens[$i][1] === $variable && ($tokens[$i + 1][1] ?? null) === '=') {
                return true;
            }
        }

        return false;
    }
}
