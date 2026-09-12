<?php

namespace Tests\Feature\AgentTools;

use App\Bridge\Exceptions\ToolRefusalException;
use App\Bridge\Tools\BoardTakeCardTool;
use App\Bridge\Tools\BoardToolDispatcher;
use App\Bridge\Tools\SeatKanbanUser;
use App\Bridge\Tools\Tool;
use PHPUnit\Framework\Assert;
use Tests\Support\CallingSeatSeal;
use Tests\Support\SourceScan;
use Tests\TestCase;

/**
 * THE STRUCTURAL ENFORCER FOR THE ONE PROPERTY card#9170's OPERATOR APPROVAL RESTS ON:
 * a seat may set the assignee to ITS OWN kanban user and no other.
 *
 * ⛔⭐ READ THIS FIRST: THIS CLASS IS NO LONGER THE BOUNDARY, AND IT IS MUCH SMALLER THAN IT
 * WAS. Until round 5 the property was the CALL GRAPH's and this census WAS the whole
 * guarantee: it derived every `SeatKanbanUser::` call site and asserted that each passed the
 * door-derived `$agentName`, unrebound. **That leg is RETIRED because it was measured
 * UNCLOSEABLE**, and the measurement is the useful part, not the conclusion. A reference
 * alias — `$ref = &$agentName; $ref = $args['agent'];` — defeats the rebind leg while the
 * first argument still reads `$agentName`, and it EXECUTES: it returns another seat's id with
 * this class green. Eleven further shapes do the same (`extract()`, a variable variable,
 * by-reference out-params such as `preg_match` and `sscanf`, `foreach` binding,
 * list-destructuring, `??=`, a closure parameter of the same name), several of which put no
 * `$agentName =` token in the body at all. Closing that textually is data-flow analysis, and
 * unlike PHP 8's class-name token set — which is CLOSED at four, which is why THAT widening
 * was allowed to terminate — there is no grammar that bounds this one. A census whose next
 * hole is always unwritten is the defect it was built to prevent, wearing the guard's hat.
 *
 * ⭐ SO THE ARGUMENT IS GONE. {@see SeatKanbanUser::forCallingSeat} takes `$tool` and NOTHING
 * ELSE; the seat comes from {@see CallingSeat}, a WRITE-ONCE process-local state the front
 * door seals at {@see BoardToolDispatcher::dispatch}'s first statement. Every one of the
 * twelve shapes above is a way of poisoning an argument, and none of them has a subject any
 * more. ⛔ This REVERSES DL-372 Decision 7, with the operator's explicit approval, and the
 * reversal is narrow: that decision declined a `DerivedAgentName` VALUE OBJECT, and its leg
 * (a) — PHP has no friend visibility, so the mint regress does not bottom out on a value —
 * was re-measured TRUE and is not what changed. A one-shot STATE is not a value: a second
 * write is a THROW, so the regress bottoms out on ORDERING, and both orderings fail closed
 * (a rogue that arrives second loses; a rogue that arrives FIRST makes the DOOR's own
 * establish throw, which 500s the request and writes nothing). Legs (b) and (c) were measured
 * FALSE — the name already travels through `Tool::call`, and *"documented extension point"*
 * is documented in 0 of the 5 docs consulted — and, load-bearing here, **`Tool::call`'s
 * signature is untouched by this design**, so the contract-breakage cost is zero.
 *
 * ⛔ WHAT THIS CLASS IS NOW: BELT-AND-BRACES, AND THAT IS THE HONEST WORD FOR IT. Nothing
 * below is load-bearing for the self-only property — the state is — and each leg is kept for
 * a stated second reason:
 *  1. **THE `CallingSeat` CENSUS** ({@see CALLING_SEAT_SITES}) — set equality, both
 *     directions, over every `CallingSeat::<method>(` call site in the population. A NEW
 *     `establish` site is a REVIEW EVENT: it cannot silently win (whichever of the two runs
 *     second throws), but a process that establishes twice is a process whose board-tools
 *     call dies at a 500, so it is a liveness defect worth seeing before it ships. A new
 *     `name()` reader resolves the SAME seat and is reviewable for a different reason: the id
 *     it returns is an identity, and a second consumer of an identity is a design question.
 *  2. **THE `SeatKanbanUser` CENSUS** ({@see DISPOSITIONED}) — set equality only; there is no
 *     argument left to check. A second resolver call site is no longer a security event, and
 *     the assertion is kept because a second CONSUMER of a seat's kanban id still deserves to
 *     be read by somebody.
 *  3. **NO ALIASED IMPORT** of the resolver anywhere in the population — the one spelling
 *     legs 1 and 2 cannot see by DESIGN, refused rather than resolved.
 *  4. **WHICH FILES MAY NAME THE RESOLVER AT ALL** ({@see NAMING_FILES}) — kept, because it
 *     is the only leg that sees an INDIRECT invocation (a `::class` callable array, a class
 *     name spelled as a string), and because a new file naming the class is still the
 *     cheapest possible review trigger.
 *
 * ⛔ ONE POPULATION FOR EVERY LEG, AND IT IS NOT `app/` — measured, not assumed: a
 * fully-qualified call appended to `routes/console.php` left this whole class GREEN when the
 * population was `app/` alone, and an `Artisan::command` that resolves a seat id is exactly
 * where somebody writes one. The population is {@see populationFiles} — **every `*.php` file
 * this repository TRACKS, except `tests/` and `vendor/`** — derived from `git ls-files` on
 * every run rather than from a list of roots, so a PHP root that does not exist yet is in it
 * the day it is added. ⚠ The legs MUST share it: leg 4 alone would red on a new `routes/`
 * caller, but dispositioning that file would then exempt it from legs 1–3.
 *
 * The predicate for legs 1 and 2 is ONE primitive ({@see staticCallSiteAt}) applied to two
 * class names: every `<class>::<method>(` call in the population, on PHP's own tokenizer
 * ({@see SourceScan::sites}), where `<class>` is any of the FOUR class-name-reference tokens
 * PHP 8 tokenises (`T_STRING`, `T_NAME_QUALIFIED`, `T_NAME_FULLY_QUALIFIED`,
 * `T_NAME_RELATIVE`) whose final `\`-segment EQUALS the subject class — so a mention in a
 * docblock or a `{@see}` is excluded by CONSTRUCTION and a qualified spelling is INCLUDED by
 * it. That set is CLOSED: PHP 8 has no fifth class-name token, so this enumeration terminates
 * rather than chasing a spelling ({@see namesClass}). Sites are keyed
 * `<repo-relative path>::<enclosing function>#<ordinal>` and carry the METHOD called.
 *
 * ⛔ STATED BOUNDS — what a green run says, and it is narrower than the class name.
 *  - **It says nothing about whether the DISPATCHER still derives the name from a door.**
 *    That is `AgentToolsCallTest` / `ToolsCallCommandTest`'s, and those drive real doors.
 *    ⚠ THOSE TESTS PROVE THE TWO DOORS DERIVE THE NAME; THEY DO NOT PROVE
 *    {@see BoardToolDispatcher::dispatch} HAS NO OTHER CALLER. That population is the
 *    dispatcher's own call sites, not this class's, and no leg here walks it — read at source
 *    instead, presently exactly two (`app/Http/Controllers/AgentTools/AgentToolsController.php`
 *    and `app/Console/Commands/Bridge/ToolsCallCommand.php`, re-derived this round with
 *    `command grep -rn -- "->dispatch(" app/ routes/`; the other hits are the unrelated event
 *    dispatcher). ⭐ Since round 5 that gap is bounded rather than open: a second dispatcher
 *    caller inside one process does not get to establish a second seat — it throws.
 *  - **Reflection can still write {@see CallingSeat}'s private static.** Disclosed in that
 *    class's own docblock and NOT claimed closed here; the suite itself takes that break, from
 *    `tests/`, through `Tests\Support\CallingSeatSeal`.
 *  - ⛔⭐ **WHAT A CENSUS RESIDUAL NOW COSTS, WHICH IS THE WHOLE REASON THE DESIGN MOVED.**
 *    The spellings below are still invisible to these legs — that has not changed — but what
 *    an invisible call could ACHIEVE has. Under the retired argument rule an unseen call site
 *    silently returned another seat's id. Under the state, an unseen `CallingSeat::establish`
 *    cannot install a name the door did not derive in ANY ordering, and an unseen
 *    `SeatKanbanUser::forCallingSeat` can only ask about the seat the door sealed. The
 *    residual is therefore a REVIEW gap, not a boundary gap. Measured, one row per spelling:
 *     1. **A name NO SINGLE STRING TOKEN SPELLS CONTIGUOUSLY** — `'App\Bridge\Tools\Seat'
 *        .'KanbanUser'`, a name built from variables, a name read from `config()` at runtime,
 *        and `"App\Bridge\Tools\SeatKanban\x55ser"` were each planted and each stayed green.
 *     2. **A RUNTIME ALIAS REGISTERED BY A FILE THAT MAY ALREADY NAME THE CLASS** — a
 *        `class_alias()` in {@see SeatKanbanUser} or `BoardTakeCardTool`, then invoked under
 *        the alias, stays green; from ANY other file the argument is a string and leg 4 reds.
 *     3. **A FILE THIS REPOSITORY DOES NOT TRACK** — {@see populationFiles} is `git ls-files`,
 *        so an uncommitted working-copy file is outside the population and was measured green.
 *        That is the RIGHT direction, not merely the measured one: a filesystem walk needs a
 *        hand-maintained exclusion list whose next member is always the one nobody wrote down.
 *    ⚠ Written as three shapes and not as "and nothing else": the matrix is what this list
 *    reports, so a spelling nobody has planted is unmeasured rather than closed.
 *  - **`tests/` and `vendor/` are not in the population.** A test double is not a door, and
 *    this very file's fixtures spell the class in strings — a population that included them
 *    would red on its own controls.
 *  - **A site the walk cannot attribute to a method lands as `(file scope)`** rather than
 *    being skipped — loud, in the safe direction.
 */
class SeatIdentityCallSiteGuardTest extends TestCase
{
    /** The resolver whose call sites legs 2–4 are about. */
    private const IDENTITY_CLASS = 'SeatKanbanUser';

    /** The write-once seat the front door seals — leg 1's subject. */
    private const CALLING_SEAT_CLASS = 'CallingSeat';

    /**
     * A FLOOR on {@see populationFiles}, never a count — so a `git ls-files` that answered
     * about the wrong tree reports the derivation broken rather than the repository clean.
     */
    private const MIN_POPULATION_FILES = 100;

    /**
     * ⭐ LEG 1 — EVERY `CallingSeat::` CALL SITE IN THE POPULATION, with the method it calls
     * and the reason that site exists.
     *
     * ⛔ THIS IS NOT AN EXEMPTION LIST AND IT IS NOT THE BOUNDARY. The boundary is the
     * write-once state itself: whichever `establish` runs second THROWS, so a call site this
     * census never saw still cannot install a name the door did not derive. What the census
     * buys is that a second `establish` is READ BY SOMEBODY before it ships — because the way
     * it fails is a 500 on a live door, which is loud but late.
     *
     * @var array<string, array{method: string, why: string}>
     */
    private const CALLING_SEAT_SITES = [
        'app/Bridge/Tools/BoardToolDispatcher.php::dispatch#1' => [
            'method' => 'establish',
            'why' => 'THE SEAL, at the first statement of the one body BOTH front doors funnel into. `$agentName` here is the name the door derived — the bearer\'s on http, the pinned forced command\'s on ssh — and this is the last point at which it is still an argument.',
        ],
        'app/Bridge/Tools/SeatKanbanUser.php::forCallingSeat#1' => [
            'method' => 'name',
            'why' => 'THE ONLY READER: the resolver asks which seat this process is serving instead of taking a name from its caller. That question has exactly one answer per process and no parameter.',
        ],
    ];

    /**
     * Every `SeatKanbanUser::` call site in {@see populationFiles}, each with the reason it is
     * legitimate.
     *
     * ⛔ THIS IS NOT AN EXEMPTION LIST. The list exists so that a site APPEARING or GOING is a
     * red in its own right (set equality, both directions). ⚠ Since round 5 a new site is a
     * DESIGN question rather than a security one — the resolver answers only about the sealed
     * seat, so a second caller cannot learn another seat's id by asking differently.
     *
     * ⛔⭐ AND THE OBVIOUS ASSUMPTION ABOUT A POISONED CALL IS WRONG — MEASURED, PHP 8.5.9 ON
     * THIS HOST. `SeatKanbanUser::forCallingSeat($args['agent'], 'zz')` does NOT raise
     * `ArgumentCountError`: PHP raises that for too FEW arguments to a userland function and
     * silently IGNORES extra ones (`A::f('PAYLOAD', 'zz')` binds `'PAYLOAD'` to the single
     * parameter and drops `'zz'`). So a planted poison binds to `$tool` — a refusal-message
     * prefix — and the IDENTITY is untouched, because the seat was never a parameter. What
     * rejects such a call site is phpstan at level 7 (`arguments.count`, watched red) and this
     * set-equality census; nothing at runtime does, and saying otherwise would credit the
     * language with a check it does not perform.
     *
     * @var array<string, string>
     */
    private const DISPOSITIONED = [
        'app/Bridge/Tools/BoardTakeCardTool.php::call#1' => 'THE call site the feature rests on. It passes only the TOOL NAME (for the refusal message); the identity comes from `CallingSeat` inside the resolver. The id it returns is the ONLY value the tool writes to `assigned_user_id`.',
    ];

    /**
     * LEG 4'S DISPOSITION: the files in the population that may NAME the resolver in CODE at
     * all (prose is dropped by the tokenizer, so a `{@see SeatKanbanUser}` in another file's
     * docblock is not a member — `BoardMyCardsTool` carries one and is deliberately absent).
     *
     * ⛔ IT IS NOT A STYLE RULE. A file that names the class can INVOKE it in a way the
     * call-site predicate cannot read — a `::class` callable array, an alias, a string — so
     * the answer to "which files may name it" is the answer to "where could a second caller
     * hide".
     *
     * @var array<string, string>
     */
    private const NAMING_FILES = [
        'app/Bridge/Tools/BoardTakeCardTool.php' => 'THE one caller — its single call site is dispositioned above.',
        'app/Bridge/Tools/SeatKanbanUser.php' => 'the class\'s own declaration.',
    ];

    /**
     * ⭐ LEG 1 — SET EQUALITY, BOTH DIRECTIONS, OVER EVERY `CallingSeat::` CALL SITE, AND THE
     * METHOD EACH ONE CALLS.
     *
     * The method is part of the assertion and not decoration: a THIRD site that calls
     * `establish` is a different event from a third site that calls `name`, and comparing keys
     * alone would report them identically.
     */
    public function test_every_calling_seat_call_site_is_dispositioned(): void
    {
        /** @var array<string, string> $derived */
        $derived = self::sitesInPopulation(self::callingSeatSiteAt(...));
        ksort($derived);

        $declared = array_map(
            static fn (array $site): string => $site['method'],
            self::CALLING_SEAT_SITES,
        );
        ksort($declared);

        $this->assertSame(
            $declared,
            $derived,
            'the set of `'.self::CALLING_SEAT_CLASS.'::` call sites in this repository is not the set this '
            .'class dispositions. A NEW `establish` site is the one worth stopping for: the seat is WRITE-ONCE '
            .'per process, so a second establish does not quietly win — whichever runs second THROWS and the '
            .'call dies with nothing written — but that is a 500 on a live door, which is loud and LATE. '
            .'Establish why a second front door exists (there are two today, and both reach the one dispatcher) '
            .'and disposition it here, or route it through `BoardToolDispatcher::dispatch` like the others. '
            .'A new `name` site resolves the SAME seat and is safe, but it is a second consumer of a seat '
            .'IDENTITY, which is a design question somebody should answer out loud. A MISSING site is a stale '
            .'disposition: delete the entry.',
        );
    }

    /**
     * LEG 1'S OWN CONTROL — the seat-call predicate, both directions on a fixture whose answer
     * is known. Without it a scanner matching nothing reports a clean repo and one matching
     * prose reports noise as defects; both wear the same green (canon #9).
     */
    public function test_the_calling_seat_scanner_reads_a_real_call_and_ignores_prose_and_other_classes(): void
    {
        $source = <<<'PHP'
        <?php
        class Fixture
        {
            /** A docblock naming CallingSeat::establish( in prose. */
            public function theDoor(): void
            {
                // A comment naming CallingSeat::establish($rogue).
                CallingSeat::establish($agentName);
            }

            public function theReader(): void
            {
                $seat = CallingSeat::name();
            }

            public function fullyQualified(): void
            {
                \App\Bridge\Tools\CallingSeat::establish($args['agent']);
            }

            public function caseVariant(): void
            {
                \App\Bridge\Tools\callingseat::establish($args['agent']);
            }

            public function aLongerNameIsADifferentClass(): void
            {
                MyCallingSeat::establish($args['agent']);
            }

            public function aClassConstantFetchIsNotACall(): void
            {
                $x = CallingSeat::class;
            }
        }
        PHP;

        $this->assertSame(
            [
                // Prose naming the call is NOT a site; the call below it is.
                'Fixture.php::theDoor#1' => 'establish',
                // The reader is a site too, and its METHOD is what tells the two apart.
                'Fixture.php::theReader#1' => 'name',
                // A qualified spelling is ONE token in PHP 8 and is read; the case-folded
                // spelling matches PHP's own class resolution.
                'Fixture.php::fullyQualified#1' => 'establish',
                'Fixture.php::caseVariant#1' => 'establish',
                // ⛔ NO KEY for `MyCallingSeat` (final-segment EQUALITY, not str_ends_with) or
                // for a `::class` fetch, which is a reference and not a call.
            ],
            SourceScan::sites($source, 'Fixture.php', self::callingSeatSiteAt(...)),
            'the seat-call scanner no longer reads a `'.self::CALLING_SEAT_CLASS.'::` call the way this '
            .'fixture states, and leg 1 is only as good as it.',
        );
    }

    /**
     * LEG 2 — SET EQUALITY, both directions, over the resolver's own call sites.
     *
     * ⚠ BELT-AND-BRACES SINCE ROUND 5, and the message says so rather than overclaiming: the
     * resolver takes no name, so a new site cannot ask about another seat.
     */
    public function test_every_seat_identity_call_site_is_dispositioned(): void
    {
        $derived = array_keys(self::sites());
        sort($derived);
        $declared = array_keys(self::DISPOSITIONED);
        sort($declared);

        $this->assertSame(
            $declared,
            $derived,
            'the set of `'.self::IDENTITY_CLASS.'::` call sites in this repository is not the set this class '
            .'dispositions. A NEW site is no longer a way to write one seat\'s claim under another\'s — that '
            .'resolver has no name parameter and answers only about the seat `CallingSeat` holds — so this is a '
            .'DESIGN review rather than a security one: a second consumer of a seat\'s kanban user id should be '
            .'read by somebody before it ships. Add it here WITH its reason. A MISSING site is a stale '
            .'disposition: delete the entry.',
        );
    }

    /**
     * LEG 2'S OWN CONTROL — both directions on one fixture whose answer is known. Without it
     * a scanner matching nothing reports a clean repo, and one matching prose reports noise as
     * defects; both wear the same green (canon #9).
     *
     * ⚠ The first-argument and rebind arms this fixture used to carry are GONE with the leg
     * they pinned: there is no first argument to read once the resolver takes no name. What
     * survives is every arm about WHICH CALLS ARE SEEN, which is the half legs 2 and 4 still
     * depend on.
     */
    public function test_the_scanner_discriminates_a_real_call_from_prose_and_reads_the_method_called(): void
    {
        $source = <<<'PHP'
        <?php
        class Fixture
        {
            /** A docblock naming SeatKanbanUser::forCallingSeat( and $agentName. */
            public function proseIsNotASite(): void
            {
                // A comment mentioning SeatKanbanUser::forCallingSeat('x').
                $id = SeatKanbanUser::forCallingSeat($this->name());
            }

            public function everyMethodOnTheClassIsASite(): void
            {
                SeatKanbanUser::forCallingSeat('a');
                SeatKanbanUser::somethingElse($nope, 'b');
            }

            public function anotherClassIsNotTheSubject(): void
            {
                $x = SomeOtherClass::forCallingSeat('tool');
            }

            public function fullyQualified(): void
            {
                $id = \App\Bridge\Tools\SeatKanbanUser::forCallingSeat('tool');
            }

            public function namespaceQualified(): void
            {
                $id = Tools\SeatKanbanUser::forCallingSeat('tool');
            }

            public function namespaceRelative(): void
            {
                $id = namespace\SeatKanbanUser::forCallingSeat('tool');
            }

            public function caseVariant(): void
            {
                $id = \App\Bridge\Tools\seatkanbanuser::forCallingSeat('tool');
            }

            public function aLongerNameIsADifferentClass(): void
            {
                $id = \App\Other\MySeatKanbanUser::forCallingSeat('tool');
            }

            public function caseFoldedLongerNameIsStillADifferentClass(): void
            {
                $id = \App\Other\myseatkanbanuser::forCallingSeat('tool');
                $other = \App\Bridge\Tools\seatkanbanuserfactory::forCallingSeat('tool');
            }

            public function anAliasIsNotVisibleToThisLeg(): void
            {
                $id = Roster::forCallingSeat('tool');
            }

            public function aClassConstantFetchIsNotACall(): void
            {
                $x = SeatKanbanUser::class;
            }
        }
        PHP;

        $this->assertSame(
            [
                // A docblock and a line comment name the call and are NOT sites.
                'Fixture.php::proseIsNotASite#1' => 'forCallingSeat',
                // A second site in one body gets its own ordinal rather than inheriting the
                // first's, and EVERY method on the class is a site — a future second resolver
                // must not be able to arrive outside the census. ⛔ `anotherClassIsNotTheSubject`
                // has no key at all: a DIFFERENT class with the same method name is not a site.
                'Fixture.php::everyMethodOnTheClassIsASite#1' => 'forCallingSeat',
                'Fixture.php::everyMethodOnTheClassIsASite#2' => 'somethingElse',
                // ⭐ THE THREE SPELLINGS THE FIRST CUT WAS BLIND TO, each measured GREEN as a
                // planted second caller before the predicate was widened to cover it. PHP 8
                // emits each as ONE token, so a predicate reading `T_STRING` alone saw none of
                // them. `namespaceRelative` is `T_NAME_RELATIVE` — the fourth and LAST
                // class-name-reference token PHP 8 defines; this `in_array` is now exhaustive.
                'Fixture.php::fullyQualified#1' => 'forCallingSeat',
                'Fixture.php::namespaceQualified#1' => 'forCallingSeat',
                'Fixture.php::namespaceRelative#1' => 'forCallingSeat',
                // ⭐ CASE-INSENSITIVE, matching PHP's own class-name resolution — measured to
                // execute WARM (something else in the same process already loaded the real
                // class) and throw COLD (PSR-4 is case-sensitive on this filesystem); neither
                // direction makes the call unreachable, only conditional.
                'Fixture.php::caseVariant#1' => 'forCallingSeat',
                // ⛔ AND THE FOUR NON-MEMBERS THAT KEEP THE WIDENING HONEST, none with a key:
                // `MySeatKanbanUser` and its casefolded twin are DIFFERENT classes even under
                // `strcasecmp` (final-segment EQUALITY, not `str_ends_with` — casefolding
                // widens which BYTES match, never where the segment boundary falls); an
                // ALIASED call is invisible here BY DESIGN — leg 3 refuses the import outright
                // rather than resolving it, which is the only reason that arm is allowed to be
                // absent; and a `::class` fetch is a reference, not a call.
            ],
            SourceScan::sites($source, 'Fixture.php', self::identitySiteAt(...)),
            'the scanner no longer reads a `'.self::IDENTITY_CLASS.'::` call the way this fixture states, and '
            .'the population above is only as good as it. Every arm is a way it would otherwise mislead.',
        );
    }

    /**
     * ⭐ LEG 3 — THE ALIASED SPELLING, REFUSED RATHER THAN RESOLVED.
     *
     * A `use App\Bridge\Tools\SeatKanbanUser as Roster;` makes the class token at every call
     * site in that file `Roster`, and NO final-segment predicate can see it — measured: a
     * planted aliased caller passing `$args['agent']` left this whole class GREEN. Teaching the
     * walk to resolve import tables would buy the same guarantee for a per-file symbol table
     * that has to be right about group imports, trait aliases and `class_alias`; refusing the
     * alias buys it for four lines, and costs a spelling nothing in this tree uses.
     */
    public function test_no_file_in_the_population_imports_the_identity_class_under_an_alias(): void
    {
        /** @var array<string, string> $aliases */
        $aliases = self::sitesInPopulation(self::aliasImportAt(...));

        $this->assertSame(
            [],
            $aliases,
            'a file in this repository imports `'.self::IDENTITY_CLASS.'` UNDER AN ALIAS, and that makes every '
            .'call site in it invisible to this class\'s call-site census — the class token there is the '
            .'alias, so the site is not in the population and its first argument is never read. That is '
            .'the exact hole card#9170\'s operator approval cannot have: the self-only property is the '
            .'call graph\'s, and this is a call the graph does not show. Spell the class instead; if the '
            .'alias is genuinely needed, the census has to learn import tables first and that is a change '
            .'to this guard, not a refactor of the file.',
        );
    }

    /**
     * LEG 3'S OWN CONTROL — the alias reader, both directions on a fixture whose answer is
     * known. A `foreach (… as …)` and a trait `… as …` are the two `T_AS` shapes that would
     * make this leg cry wolf; an import of a DIFFERENT class is the one that would make it
     * vacuous if the class check were dropped.
     */
    public function test_the_alias_scanner_reads_an_import_of_this_class_and_ignores_every_other_as(): void
    {
        $source = <<<'PHP'
        <?php

        namespace App\Bridge\Check;

        use App\Bridge\Tools\SeatKanbanUser as Roster;
        use App\Bridge\Tools\BoardScopedRow as Row;
        use App\Bridge\Tools\SeatKanbanUser;

        class Fixture
        {
            use SomeTrait, OtherTrait { OtherTrait::run as runOther; }

            public function f(): void
            {
                foreach ($rows as $row) {
                    $id = SeatKanbanUser::forCallingAgent($agentName, 'tool');
                }
            }
        }
        PHP;

        $this->assertSame(
            ['Fixture.php::'.SourceScan::FILE_SCOPE.'#1' => 'Roster'],
            SourceScan::sites($source, 'Fixture.php', self::aliasImportAt(...)),
            'the alias reader no longer reads an import of this class the way this fixture states — and '
            .'leg 3 is only as good as it: a reader that matched nothing would report every tree clean.',
        );
    }

    /**
     * ⭐ LEG 4 — WHICH FILES MAY NAME THE CLASS AT ALL, which is what closes the invocation
     * shapes a call-site predicate cannot express. `call_user_func([SeatKanbanUser::class,
     * 'forCallingSeat'], 'tool')` is a call leg 2 does not see, because the
     * tokens are a `::class` fetch inside an array literal and never a `<class>::<method>(`.
     * Rather than grow the predicate a case per invocation shape — an enumeration whose next
     * member is always unwritten — the census asks the question one level up.
     *
     * ⛔ AND THE QUESTION IT ASKS IS "DOES THIS FILE SPELL THE NAME", NOT "DOES IT HOLD A NAME
     * TOKEN" — a distinction this leg's first cut got wrong, and the correction is the useful
     * part. It read {@see namesClass} alone, so a new file calling
     * `call_user_func('App\Bridge\Tools\SeatKanbanUser::forCallingAgent', $args['agent'], …)`
     * — a `T_CONSTANT_ENCAPSED_STRING`, the plainest spelling of an indirect call, and the very
     * shape this leg's own failure message claimed to close — was invisible to every leg
     * and left the class green. The predicate now also reads STRING TEXT
     * ({@see stringSpellsIdentityClass}), and what is left after that is MEASURED rather than
     * asserted: see § STATED BOUNDS.
     */
    public function test_only_the_dispositioned_files_name_the_identity_class(): void
    {
        $files = [];
        foreach (array_keys(self::sitesInPopulation(self::classMentionAt(...))) as $key) {
            $files[explode('::', $key)[0]] = true;
        }
        $derived = array_keys($files);
        sort($derived);
        $declared = array_keys(self::NAMING_FILES);
        sort($declared);

        $this->assertSame(
            $declared,
            $derived,
            'a file in this repository NAMES `'.self::IDENTITY_CLASS.'` in code and is not dispositioned. '
            .'Naming it is not the defect; what the naming says is that this file could INVOKE the resolver in '
            .'a shape the call-site census cannot read — a `::class` callable, a string class name, an alias — '
            .'and the self-only '
            .'property card#9170 was approved on is the call graph\'s alone. Establish what this file does '
            .'with the class and disposition it here, or delete the reference. (A `{@see}` in a docblock is '
            .'NOT a member: prose is dropped by the tokenizer before this census sees it.)',
        );
    }

    /**
     * ⭐ THE PROPERTY ITSELF, ASSERTED DIRECTLY — AND THIS IS THE INVERSION OF WHAT THIS CLASS
     * USED TO ASSERT HERE.
     *
     * The retired test was `test_the_identity_resolver_answers_about_whatever_name_it_is_handed`,
     * and it was TRUE of the old signature: handed `other` on a three-agent roster it returned
     * 222. That was a confession, not a feature — it was why the self-only property had to be
     * the call graph's. There is no name to hand any more, so the assertion turns over: the
     * resolver answers about the ESTABLISHED SEAT and about nothing else.
     *
     * ⚠ BOTH LEGS ARE NEEDED AND NEITHER IS THE OTHER'S CONTROL. The BEHAVIOURAL leg (same
     * roster, two different established seats, two different answers) says the seat is what
     * decides — without it a resolver that always returned the first roster entry would pass.
     * The STRUCTURAL leg (the method's parameter list) says there is no channel through which
     * another name could arrive at all — without it, a resolver that took a name and merely
     * happened not to be handed one in this test would pass.
     */
    public function test_the_identity_resolver_answers_only_about_the_established_seat(): void
    {
        $dir = sys_get_temp_dir().'/seat-identity-'.uniqid();
        mkdir($dir, 0o700, true);
        foreach (['me' => 111, 'other' => 222, 'pm' => 333] as $name => $id) {
            file_put_contents($dir."/{$name}.yml", "identity:\n  kanban_user_id: {$id}\nsubscriptions: []\n");
        }
        config(['bridge.config_dir' => $dir]);

        try {
            CallingSeatSeal::establishedAs('other');
            $this->assertSame(
                222,
                SeatKanbanUser::forCallingSeat('board_take_card'),
                'the resolver did not answer about the seat the door established.'
            );
            $this->assertSame(
                222,
                SeatKanbanUser::forCallingSeat('board_my_cards'),
                'the answer moved between two calls in one process — the seat is WRITE-ONCE, so it must not.'
            );

            // The discriminator: the SAME roster, a different established seat, a different
            // answer. Without this a resolver that always returned one fixed entry would pass
            // the assertions above.
            CallingSeatSeal::establishedAs('pm');
            $this->assertSame(333, SeatKanbanUser::forCallingSeat('board_take_card'));

            $this->assertSame(
                ['tool'],
                array_map(
                    static fn (\ReflectionParameter $p): string => $p->getName(),
                    (new \ReflectionMethod(SeatKanbanUser::class, 'forCallingSeat'))->getParameters(),
                ),
                'the resolver has grown a parameter beside `$tool`. The whole of card#9170\'s self-only '
                .'property now rests on there being NO channel by which a caller supplies a seat — a name '
                .'parameter, whatever it is called and however it is validated, restores the twelve poisoning '
                .'shapes DL-372 Decision 7 was reversed over. That is an operator decision, not a refactor.'
            );
        } finally {
            array_map('unlink', (array) glob($dir.'/*.yml'));
            rmdir($dir);
        }
    }

    /**
     * ⛔ AN ID THAT NAMES TWO SEATS DOES NOT IDENTIFY THE CALLER, so it is an INSTALL-fault
     * REFUSAL rather than an answer (PR #706 review). `assigned_user_id` is a kanban USER and
     * not a seat, and nothing downstream can tell two seats sharing one apart: without this,
     * seat `a` calling `board_take_card` on a card seat `b` holds is answered `taken: true,
     * already_held: true` — it believes it holds work another seat is on, which is precisely
     * the state the tool was filed to make visible. The install state is REACHABLE: the
     * registry WARNS on a shared id and `bridge:check` reports it at exit 0.
     *
     * ⚠ THE FIRST ASSERTION IS THE CONTROL. A roster of three whose third seat still resolves
     * is what says the refusal is about the COLLISION and not about the roster having grown —
     * without it a resolver that refused every multi-agent install would pass this test.
     */
    public function test_the_identity_resolver_refuses_a_kanban_user_id_two_agents_declare(): void
    {
        $dir = sys_get_temp_dir().'/seat-identity-'.uniqid();
        mkdir($dir, 0o700, true);
        foreach (['a' => 500, 'b' => 500, 'solo' => 777] as $name => $id) {
            file_put_contents($dir."/{$name}.yml", "identity:\n  kanban_user_id: {$id}\nsubscriptions: []\n");
        }
        config(['bridge.config_dir' => $dir]);

        try {
            CallingSeatSeal::establishedAs('solo');
            $this->assertSame(777, SeatKanbanUser::forCallingSeat('board_take_card'));

            CallingSeatSeal::establishedAs('a');
            $this->expectException(ToolRefusalException::class);
            $this->expectExceptionMessageMatches('/MORE THAN ONE agent \(a, b\).*NOTHING WAS WRITTEN.*INSTALL fault/s');
            SeatKanbanUser::forCallingSeat('board_take_card');
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
     * ⭐ LEG 4'S OWN CONTROL FOR THE SPELLING IT WAS BLIND TO — both directions on one fixture
     * whose answer is known. Every MEMBER here is a shape that left the whole class green
     * before this leg read string text; every NON-MEMBER is a way the widening would cry wolf
     * or would answer about a different class.
     *
     * ⛔ The two absent keys are the BOUND, and they are absent on purpose: `fragments`
     * assembles the name from two literals neither of which contains it, and `escaped` spells
     * one character of it as `\x55`. Neither is a plain call; both are in § STATED BOUNDS.
     */
    public function test_the_file_census_reads_a_class_name_spelled_as_a_string(): void
    {
        $source = <<<'PHP'
        <?php
        class Fixture
        {
            /** A docblock naming 'SeatKanbanUser' inside quotes. */
            public function plainCallable(): void
            {
                call_user_func('App\Bridge\Tools\SeatKanbanUser::forCallingAgent', $args['agent'], 'tool');
            }

            public function doubleQuoted(): void
            {
                $cls = "App\\Bridge\\Tools\\SeatKanbanUser";
            }

            public function interpolated(): void
            {
                $cls = "App\\Bridge\\Tools\\SeatKanbanUser::{$method}";
            }

            public function heredoc(): void
            {
                $cls = <<<TXT
                App\Bridge\Tools\SeatKanbanUser
                TXT;
            }

            public function aliasedAtRuntime(): void
            {
                class_alias('App\Bridge\Tools\SeatKanbanUser', 'Roster');
            }

            public function reflected(): void
            {
                new \ReflectionMethod('App\Bridge\Tools\SeatKanbanUser', 'forCallingAgent');
            }

            public function longerNameIsADifferentClass(): void
            {
                $cls = 'App\Other\MySeatKanbanUser';
                $other = 'SeatKanbanUserFactory';
            }

            public function fragments(): void
            {
                $cls = 'App\Bridge\Tools\Seat'.'KanbanUser';
            }

            public function escaped(): void
            {
                $cls = "App\\Bridge\\Tools\\SeatKanban\x55ser";
            }

            public function caseVariant(): void
            {
                $cls = 'app\bridge\tools\seatkanbanuser';
            }

            public function caseFoldedLongerNameIsStillADifferentClass(): void
            {
                $cls = 'app\other\myseatkanbanuser';
                $other = 'seatkanbanuserfactory';
            }
        }
        PHP;

        $this->assertSame(
            [
                // The plainest indirect call there is — and the shape leg 4's own failure
                // message already claimed to close while the predicate could not see it.
                'Fixture.php::plainCallable#1' => "'App\\Bridge\\Tools\\SeatKanbanUser::forCallingAgent'",
                'Fixture.php::doubleQuoted#1' => '"App\\\\Bridge\\\\Tools\\\\SeatKanbanUser"',
                // An interpolated string is T_ENCAPSED_AND_WHITESPACE, a different token type.
                'Fixture.php::interpolated#1' => 'App\\\\Bridge\\\\Tools\\\\SeatKanbanUser::',
                // ⚠ The leading whitespace is REAL: this fixture is itself a nowdoc, so the
                // inner heredoc's body carries the outer indentation. Pinned as measured.
                'Fixture.php::heredoc#1' => "        App\\Bridge\\Tools\\SeatKanbanUser\n",
                'Fixture.php::aliasedAtRuntime#1' => "'App\\Bridge\\Tools\\SeatKanbanUser'",
                'Fixture.php::reflected#1' => "'App\\Bridge\\Tools\\SeatKanbanUser'",
                // ⭐ CASE-INSENSITIVE, matching PHP's own class-name resolution — measured to
                // execute (warm) rather than reasoned about; § STATED BOUNDS now names it.
                'Fixture.php::caseVariant#1' => "'app\\bridge\\tools\\seatkanbanuser'",
                // ⛔ NO KEY for longerNameIsADifferentClass, caseFoldedLongerNameIsStillADifferentClass,
                // fragments or escaped. The first two are the widening kept honest even under
                // casefolding (final-segment EQUALITY, not str_contains); the last two are the
                // measured residual, stated in § STATED BOUNDS.
            ],
            SourceScan::sites($source, 'Fixture.php', self::classMentionAt(...)),
            'leg 4 no longer reads a class name spelled as a STRING the way this fixture states. '
            .'A file can invoke this resolver through a string class name with no name token in it '
            .'at all, which is how a new caller went green through every leg once already — '
            .'and the absent arms are the bound that replaced the false one, unmoved by casefolding.',
        );
    }

    /**
     * THE POPULATION'S OWN CONTROL. A derivation that quietly went back to `app/` would leave
     * every leg green while `routes/` was open again, which is the state this round found.
     */
    public function test_the_population_is_every_tracked_php_file_outside_tests_and_vendor(): void
    {
        $files = self::populationFiles();

        $this->assertGreaterThanOrEqual(
            self::MIN_POPULATION_FILES,
            count($files),
            'the PHP file population came back short — the derivation, not the repo, is what changed. '
            .'(It is a FLOOR, not a count: files are expected to be added.)',
        );

        $outsideApp = array_values(array_filter($files, fn (string $p) => ! str_starts_with($p, 'app/')));
        $this->assertNotEmpty(
            $outsideApp,
            'the population holds nothing outside `app/`, so it has silently narrowed back to the root '
            .'this round widened it from. A fully-qualified call appended to `routes/console.php` was '
            .'MEASURED green against the `app/`-only population: every leg of this class would report a '
            .'clean call graph while a second caller sat one directory away.',
        );

        $this->assertSame(
            [],
            array_values(array_filter($files, fn (string $p) => str_starts_with($p, 'tests/') || str_starts_with($p, 'vendor/'))),
            'this file\'s own fixtures spell the class in strings; a population that included `tests/` '
            .'would red on its own controls.',
        );
    }

    /**
     * Every resolver call site in the population, keyed as {@see SourceScan::sites} keys them,
     * valued with the METHOD called.
     *
     * @return array<string, string>
     */
    private static function sites(): array
    {
        /** @var array<string, string> $sites */
        $sites = self::sitesInPopulation(self::identitySiteAt(...));

        return $sites;
    }

    /**
     * THE POPULATION EVERY LEG WALKS: every `*.php` file this repository TRACKS, except
     * `tests/` and `vendor/`, repo-relative and sorted.
     *
     * ⛔ DERIVED FROM `git ls-files`, NOT FROM A LIST OF ROOTS, and that is the point rather
     * than a convenience. `app/` alone was the first cut and it left `routes/` open (measured:
     * a fully-qualified call appended to `routes/console.php` left this whole class green); a
     * hand-listed set of roots would have the same shape one root further out, and its next
     * member is always the one nobody wrote down. `git ls-files` IS the exclusion of `vendor/`
     * and of every generated tree (`bootstrap/cache`, compiled views), so the population does
     * not move with the state of the working directory.
     *
     * @return list<string>
     */
    private static function populationFiles(): array
    {
        $output = [];
        $status = 1;
        exec('cd '.escapeshellarg(base_path()).' && git ls-files -- \'*.php\'', $output, $status);
        Assert::assertSame(0, $status, 'git ls-files failed — the population could not be derived, which is not the same as an empty one.');

        $files = [];
        foreach ($output as $path) {
            if (str_starts_with($path, 'tests/') || str_starts_with($path, 'vendor/')) {
                continue;
            }
            $files[] = $path;
        }
        sort($files);

        return $files;
    }

    /**
     * Every site $siteAt recognises across {@see populationFiles}, keyed
     * `<repo-relative path>::<enclosing function>#<ordinal>`.
     *
     * The WALK is {@see SourceScan::sites} — this method chooses the file list and nothing
     * else, which is why it is here and not a second copy of the tokenizer loop.
     *
     * @param  callable(list<array{0: int|string, 1: string}>, int, int): mixed  $siteAt
     * @return array<string, mixed>
     */
    private static function sitesInPopulation(callable $siteAt): array
    {
        $sites = [];
        foreach (self::populationFiles() as $path) {
            $sites += SourceScan::sites((string) file_get_contents(base_path($path)), $path, $siteAt);
        }

        return $sites;
    }

    /**
     * LEG 2'S PREDICATE: a static call on {@see IDENTITY_CLASS}.
     *
     * @param  list<array{0: int|string, 1: string}>  $tokens
     */
    private static function identitySiteAt(array $tokens, int $index, int $scopeStart): ?string
    {
        return self::staticCallSiteAt($tokens, $index, self::IDENTITY_CLASS);
    }

    /**
     * LEG 1'S PREDICATE: a static call on {@see CALLING_SEAT_CLASS}.
     *
     * @param  list<array{0: int|string, 1: string}>  $tokens
     */
    private static function callingSeatSiteAt(array $tokens, int $index, int $scopeStart): ?string
    {
        return self::staticCallSiteAt($tokens, $index, self::CALLING_SEAT_CLASS);
    }

    /**
     * THE ONE CALL-SITE PREDICATE BOTH CENSUSES USE: is the token at $index a static call on
     * $class, and if so, which METHOD does it call?
     *
     * ⛔ ONE PRIMITIVE AND NOT TWO (canon #5). Two censuses over two classes differing only in
     * a class name is exactly the shape where one of them silently learns a spelling the other
     * does not — which is the defect the FOUR-token enumeration below exists to close, so
     * minting a second copy of it would be re-minting the bug inside the fix.
     *
     * ⛔ The class is matched on its FINAL `\`-SEGMENT ({@see namesClass}), so
     * `SomeOtherClass::forCallingSeat(` is not a site — the subject is the class, not a method
     * name — and neither is `MySeatKanbanUser::`, because the comparison is EQUALITY of that
     * segment and not `str_ends_with` (both pinned in the scanner controls).
     *
     * @param  list<array{0: int|string, 1: string}>  $tokens
     */
    private static function staticCallSiteAt(array $tokens, int $index, string $class): ?string
    {
        if (! self::namesClass($tokens[$index] ?? null, $class)) {
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

        return $tokens[$index + 2][1];
    }

    /**
     * Does $token NAME $class, under any of the FOUR spellings PHP 8 tokenises a class
     * reference as — bare (`T_STRING`), namespace-qualified (`T_NAME_QUALIFIED`),
     * fully-qualified (`T_NAME_FULLY_QUALIFIED`), or namespace-relative (`T_NAME_RELATIVE`,
     * `namespace\Foo`) — each ONE token since PHP 8?
     *
     * ⛔ THIS SET IS CLOSED BY THE LANGUAGE. PHP 8 defines exactly these four class-name-
     * reference token constants — there is no fifth — so completing this `in_array` is a
     * finite, terminating fix rather than another instance of an open-ended spelling chase.
     * A `T_NAME_RELATIVE` call (`namespace\SeatKanbanUser::forCallingSeat(...)`) executes
     * COLD — no autoload trick, no warm class table — and was invisible to every leg
     * before this arm was added, including a plant INSIDE the one file the call-site census
     * watches most closely ({@see BoardTakeCardTool}), where it did not move.
     *
     * ⛔ The FINAL `\`-segment must EQUAL the class name. `str_ends_with` on the token text
     * would make `MySeatKanbanUser` this class, which is a guard reporting a defect in code
     * that has nothing to do with it — and a guard that cries wolf is one somebody widens.
     *
     * ⭐ THE COMPARISON IS CASE-INSENSITIVE, matching PHP's own class-name resolution rather
     * than a stricter rule this guard invents. Measured: `seatkanbanuser::forCallingSeat(…)`
     * — a name token, not a string — was invisible to a strict `===` and DOES execute when
     * something else in the same process already loaded the real class (PSR-4 autoloading is
     * case-SENSITIVE on a case-sensitive filesystem, so it throws cold; nothing about that
     * makes the call unreachable, only conditional). `strcasecmp` keeps the identifier-
     * BOUNDARY property that makes this safe: it compares the WHOLE final segment, so
     * `myseatkanbanuser` folds to a match while `seatkanbanuserfactory` and
     * `myseatkanbanuser2` do not — casefolding widens WHICH BYTES match, never WHERE the
     * segment boundary falls (pinned in the fixtures below).
     *
     * @param  array{0: int|string, 1: string}|null  $token
     */
    private static function namesClass(?array $token, string $class): bool
    {
        if ($token === null || ! in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
            return false;
        }

        $segments = explode('\\', $token[1]);

        return strcasecmp((string) end($segments), $class) === 0;
    }

    /**
     * LEG 3'S PREDICATE: the alias, when the token at $index is the `as` of an import of THIS
     * class. `use App\Bridge\Tools\SeatKanbanUser as Roster;` tokenises as the qualified name
     * and then `T_AS`, so the class is read from the token BEFORE the `as` — which also covers
     * the group form `use App\Bridge\Tools\{SeatKanbanUser as Roster};`. A `foreach (… as …)`
     * and a trait `OtherTrait::run as runOther;` are not imports of this class and answer null
     * (both pinned in this leg's own control).
     *
     * @param  list<array{0: int|string, 1: string}>  $tokens
     */
    private static function aliasImportAt(array $tokens, int $index, int $scopeStart): ?string
    {
        if (($tokens[$index][0] ?? null) !== T_AS || ! self::namesClass($tokens[$index - 1] ?? null, self::IDENTITY_CLASS)) {
            return null;
        }

        return $tokens[$index + 1][1] ?? '(no alias token)';
    }

    /**
     * LEG 4'S PREDICATE: this class's name, wherever it is NAMED in code — a call, a `::class`
     * fetch, an import, the declaration itself, OR the text of a string. Deliberately the
     * widest of the three.
     *
     * @param  list<array{0: int|string, 1: string}>  $tokens
     */
    private static function classMentionAt(array $tokens, int $index, int $scopeStart): ?string
    {
        $token = $tokens[$index] ?? null;

        return self::namesClass($token, self::IDENTITY_CLASS) || self::stringSpellsIdentityClass($token)
            ? $tokens[$index][1]
            : null;
    }

    /**
     * Does $token's SOURCE TEXT spell this class's name as a WHOLE IDENTIFIER?
     *
     * ⛔ THIS IS LEG 4'S ONLY, AND IT IS DELIBERATELY NOT IN {@see namesClass}. A
     * string is never a class token at a `<class>::<method>(` call site and never the name
     * before a `use … as`, so widening the shared name predicate would buy legs 1–3
     * nothing and would make their fixture controls answer about a shape they cannot reach.
     *
     * ⛔ IT MATCHES THE RAW TOKEN TEXT, ESCAPES UNRESOLVED, and that is a bound rather than an
     * oversight: `"SeatKanban\x55ser"` spells the class to PHP and not to this predicate. It
     * sits with the other assembled-at-runtime spellings in § STATED BOUNDS, each of which was
     * planted and measured rather than reasoned about.
     *
     * ⭐ WHOLE IDENTIFIER, not `str_contains`: `'App\Other\MySeatKanbanUser'` and
     * `'SeatKanbanUserFactory'` are DIFFERENT classes, and the call-site census already refuses to treat them
     * as this one. A leg that cried wolf on them is a leg somebody widens. ⭐ **CASE-
     * INSENSITIVE for the same reason {@see namesClass} is**: PHP resolves a class
     * name case-insensitively, so `'app\bridge\tools\seatkanbanuser::forcallingagent'`
     * spells this class to PHP and must spell it to this predicate too. The `i` modifier
     * folds ONLY the byte comparison — it does not touch the identifier-boundary lookaround,
     * so `seatkanbanuserfactory` still fails the match (pinned in the fixture below).
     *
     * @param  array{0: int|string, 1: string}|null  $token
     */
    private static function stringSpellsIdentityClass(?array $token): bool
    {
        if ($token === null || ! in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
            return false;
        }

        $identifier = '[A-Za-z0-9_\x80-\xff]';

        return preg_match(
            '/(?<!'.$identifier.')'.preg_quote(self::IDENTITY_CLASS, '/').'(?!'.$identifier.')/i',
            $token[1],
        ) === 1;
    }
}
