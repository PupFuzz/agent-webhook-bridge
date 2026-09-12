<?php

namespace Tests\Unit\Docs;

use App\Bridge\Provision\WritebackIdentityOffer;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Assert;
use ReflectionMethod;
use Symfony\Component\Console\Application;
use Tests\TestCase;

/**
 * ⭐ THE FLAGS THAT SUPPRESS THE IDENTITY OFFER ARE ENUMERATED IN EXACTLY TWO PLACES, AND
 * THIS IS WHAT KEEPS IT TRUE (card#9141 / DL-369). The predicate is
 * `App\Console\Commands\Bridge\BridgeCommand::canPromptToConfirm()`, which owns the rule and
 * the measurements behind it. The second copy is the operator-facing fallback message in
 * {@see WritebackIdentityOffer} — DELETE-and-point is not available there, because an
 * operator reading a console line at the moment setup declines to ask cannot follow a
 * pointer. Canon #16's remaining option for a copy like that is GUARD, and this is the guard.
 *
 * ⛔ THE CLAIM THIS FILE EXISTS FOR WAS FALSE WHEN IT WAS WRITTEN, WHICH IS WHY A COMMENT
 * WOULD NOT HAVE DONE. The shipped docblock said the flags were "named nowhere else" and the
 * PR body said "enumerated in exactly one place"; at that same commit FOUR surfaces named
 * them, and TWO of those — `docs/CHANGELOG.md` and `docs/writeback.md` — were already
 * NARROWER than the predicate on the day they were written, each omitting `SHELL_VERBOSITY`
 * inside the same sentence that said the conditions were not re-enumerated here. An operator
 * whose offer silently did not appear under an inherited `SHELL_VERBOSITY=-1` would not have
 * been told so by either doc. Those two copies are now deleted and point at the property.
 *
 * ⭐ THE FLAG SET IS DERIVED FROM `Application::configureIO` ITSELF, NOT LISTED HERE. A list
 * in this file would be a third copy of the thing it is guarding — the defect, wearing a
 * test's clothes. {@see self::suppressingClasses()} reflects the vendor method and reads back
 * the option groups that actually clear `isInteractive()`: the `match` arms that select a
 * NEGATIVE shell verbosity, the environment variable the default arm inherits one from, and
 * the `hasParameterOption` group in the branch that calls `setInteractive(false)`. A Symfony
 * upgrade that adds, renames or drops a quieting flag therefore REDS this test rather than
 * leaving two copies quietly stale together — which is the failure no lockstep-between-copies
 * check can see, because both copies stay in lockstep with each other while both go wrong.
 *
 * ⭐ THE POPULATION IS A CENSUS, RE-DERIVED ON EVERY RUN — every git-tracked file, asked
 * whether any TWO-LINE WINDOW in it names two or more distinct flag GROUPS. That is the
 * corrected predicate, and the correction matters: round 3's derivation matched files that
 * MENTIONED the gate (`isInteractive`, `hasScreen`, `canPromptToConfirm`, …), which is a
 * census one layer up from the subject — every doc that points at the predicate is a hit, so
 * the two drifted restatements sat inside the result and were read as pointers. This asks
 * the question the claim actually makes: who RESTATES the flag list. Two groups rather than
 * one is what separates a restatement from a legitimate single mention (`--no-interaction`
 * passed to a test run, `git commit -q` in a fixture); the two-line window is what stops a
 * wrapped docblock or a concatenated PHP string from hiding one.
 *
 * ⚠ BOUNDS, so a green run is not read as more than it is:
 *   (a) A copy naming exactly ONE group is invisible here — that is the deliberate cost of
 *       excluding every `-q` in every shell fixture in the repo. The failure this catches is
 *       a LIST, because a list is what drifted, twice.
 *   (b) It asks about spelling, never meaning. A copy can name every group verbatim in a
 *       sentence that has since become false. `Tests\Feature\Provision\WritebackIdentityOfferTest`
 *       is where the BEHAVIOUR is pinned, one arm per gate term.
 *   (c) A copy that splits a flag across two string literals more than one line apart leaves
 *       the census silently. The window is two lines because that is what the live copies
 *       need; a wider one buys noise, not coverage.
 *
 * ⚠ `CLAUDE_DECISIONS.md` is HISTORY, and the exclusion is narrow and deliberate: DL-369
 * QUOTES the wording that was falsified ("is false only for `--no-interaction`/`-n`/`-q`") in
 * order to record that it was wrong. Rewriting that quote to match the predicate would
 * destroy the record. The entry's own present-tense CLAIM about how many copies exist is not
 * covered by that exemption and was corrected in the same round as this file.
 *
 * This file is in its own denominator — it names the groups in {@see self::CONTROL_COPY} —
 * and is listed as a carrier rather than exempted, which is the check passing its own
 * predicate.
 */
class InteractivityFlagListGuardTest extends TestCase
{
    /** Owns the rule. Every other surface points HERE and states the PROPERTY. */
    private const OWNER = 'app/Console/Commands/Bridge/BridgeCommand.php';

    /** The one restatement the repo keeps, because its reader cannot follow a pointer. */
    private const GUARDED_COPY = 'app/Bridge/Provision/WritebackIdentityOffer.php';

    /**
     * Append-only record that quotes the falsified wording ON PURPOSE. See the class
     * docblock — this is the only exemption, and it is not an invitation to add another.
     */
    private const HISTORY = 'CLAUDE_DECISIONS.md';

    /** This file. It is a carrier because the control below spells a list out. */
    private const SELF = 'tests/Unit/Docs/InteractivityFlagListGuardTest.php';

    /**
     * A plausible restatement, used ONLY by the control. It is deliberately a DRIFTED one —
     * the shape both doc copies actually had — so the control proves the predicate catches
     * the failure that happened, not only a complete list.
     */
    private const CONTROL_COPY = 'Anywhere else — a pipe, cron, `-n`/`-q`/`--silent` — it makes no request at all.';

    /**
     * THE FLAG SET, READ BACK FROM THE VENDOR METHOD THAT IMPLEMENTS IT.
     *
     * Each entry is one OPTION GROUP — a set of spellings that mean the same condition, so a
     * surface naming `-q` has named the `--quiet` condition and owes nothing further. Groups,
     * not flags, are the unit: the operator-facing message names short forms, the predicate's
     * docblock names both, and neither is wrong.
     *
     * @return list<list<non-empty-string>>
     */
    private static function suppressingClasses(): array
    {
        $method = new ReflectionMethod(Application::class, 'configureIO');
        $file = $method->getFileName();
        $fileLines = $file === false ? [] : (array) file($file);
        $src = implode('', array_slice(
            $fileLines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        $groups = [];
        $lines = explode("\n", $src);

        foreach ($lines as $i => $line) {
            // (1) `match` arms that select a NEGATIVE shell verbosity. The verbose arms select
            //     positive ones and do not clear interactivity, so the sign IS the filter.
            if (preg_match('/=>\s*-\d+\s*,\s*$/', $line)) {
                $groups = array_merge($groups, self::optionGroupsIn($line));
            }

            // (2) the branch that clears interactivity outright, whatever the verbosity.
            if (str_contains($line, 'setInteractive(false)')) {
                $groups = array_merge($groups, self::optionGroupsIn($lines[$i - 1] ?? ''));
            }
        }

        // (3) the environment variable the default arm inherits a verbosity FROM — the
        //     condition that reaches a run whose own argv names no flag at all. Matched at the
        //     READ (`getenv`, `$_ENV[`, `$_SERVER[`) rather than on any upper-case literal in
        //     the method: the looser spelling would conscript an unrelated constant a future
        //     Symfony adds here into the set the operator message has to name.
        if (preg_match_all('/(?:getenv\(|\$_ENV\[|\$_SERVER\[)[\'"]([A-Z][A-Z0-9_]{3,})[\'"]/', $src, $env)) {
            foreach (array_unique($env[1]) as $name) {
                $groups[] = [$name];
            }
        }

        $groups = array_values(array_unique($groups, SORT_REGULAR));

        // ⭐ EVERY CONSUMER BELOW IS VACUOUS OVER AN EMPTY DERIVATION — a text restates
        // nothing when there is nothing to restate, and a census over zero groups returns
        // zero carriers and reads as clean. So the floor is asserted HERE, once, rather than
        // in the one test that happens to check it.
        Assert::assertNotEmpty($groups, 'no option groups were read out of Application::configureIO — the derivation broke, and every assertion resting on it would pass vacuously');

        return $groups;
    }

    /**
     * The quoted option literals of every `hasParameterOption([...])` call on one line, one
     * list per call — the call's own array IS the equivalence group.
     *
     * @return list<list<non-empty-string>>
     */
    private static function optionGroupsIn(string $line): array
    {
        if (! preg_match_all('/hasParameterOption\(\[([^\]]*)\]/', $line, $calls)) {
            return [];
        }

        $groups = [];
        foreach ($calls[1] as $arr) {
            if (preg_match_all('/[\'"](-{1,2}[a-zA-Z][-a-zA-Z0-9]*)[\'"]/', $arr, $opts)) {
                /** @var list<non-empty-string> $spellings */
                $spellings = $opts[1];
                $groups[] = $spellings;
            }
        }

        return $groups;
    }

    /**
     * Does this text name that group? A short flag is matched on token boundaries, so
     * `--no-interaction` is not a hit for `-n` and `--verbose` is not a hit for `-v`.
     */
    private static function names(string $text, string $spelling): bool
    {
        $q = preg_quote($spelling, '/');

        $pattern = str_starts_with($spelling, '--')
            ? '/'.$q.'(?![-\w])/'
            : (str_starts_with($spelling, '-')
                ? '/(?<![-\w])'.$q.'(?![-\w])/'
                : '/(?<![\w])'.$q.'(?![\w])/');

        return preg_match($pattern, $text) === 1;
    }

    /**
     * THE PREDICATE. A text RESTATES the flag list iff some two-line window in it names two
     * or more distinct option groups.
     *
     * @param  list<list<non-empty-string>>  $groups
     */
    private static function restatesTheList(string $text, array $groups): bool
    {
        $lines = explode("\n", $text);

        for ($i = 0, $n = count($lines); $i < $n; $i++) {
            $window = $lines[$i].' '.($lines[$i + 1] ?? '');
            $named = 0;

            foreach ($groups as $group) {
                foreach ($group as $spelling) {
                    if (self::names($window, $spelling)) {
                        $named++;
                        break;
                    }
                }
            }

            if ($named >= 2) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every git-tracked file, repo-relative. `git ls-files` IS the exclusion of `vendor/`
     * and the caches — they are gitignored, so no second list of them is kept here to drift.
     *
     * @return list<string>
     */
    private function trackedFiles(): array
    {
        $out = (string) shell_exec('git -C '.escapeshellarg(base_path()).' ls-files -z 2>/dev/null');
        $files = array_values(array_filter(explode("\0", $out), static fn ($p) => $p !== ''));

        // An empty census is a measurement that did not happen, never a clean result.
        $this->assertNotEmpty($files, 'git ls-files returned nothing for '.base_path().' — this guard did not run');

        return $files;
    }

    public function test_the_flag_set_is_derived_from_the_vendor_method_that_implements_it(): void
    {
        $groups = self::suppressingClasses();

        // ⭐ A DERIVATION THAT SILENTLY MATCHED NOTHING WOULD MAKE EVERY ASSERTION BELOW
        // VACUOUSLY TRUE — an empty group list restates nothing and is named by nothing. The
        // floor is what `configureIO` has always had: two quieting option groups, the
        // interactivity group, and the inherited environment variable.
        $this->assertGreaterThanOrEqual(4, count($groups), implode("\n", [
            'the derivation read '.count($groups).' option group(s) out of Symfony\'s Application::configureIO.',
            'Either the vendor method changed shape or the extraction broke — READ it before',
            'touching this test: vendor/symfony/console/Application.php, configureIO().',
        ]));

        $flat = array_merge(...$groups);
        $this->assertContains('--no-interaction', $flat, 'configureIO no longer clears interactivity for --no-interaction — the predicate needs re-reading, not this test relaxing');
        $this->assertContains('SHELL_VERBOSITY', $flat, 'configureIO no longer inherits SHELL_VERBOSITY — same');
    }

    public function test_the_operator_facing_message_names_every_condition_that_suppresses_the_offer(): void
    {
        $groups = self::suppressingClasses();
        $message = $this->cannotAskMessage();

        $missing = [];
        foreach ($groups as $group) {
            foreach ($group as $spelling) {
                if (self::names($message, $spelling)) {
                    continue 2;
                }
            }
            $missing[] = implode('/', $group);
        }

        $this->assertSame([], $missing, implode("\n", [
            'The operator-facing "cannot ask" message names no spelling of: '.implode(', ', $missing),
            'It is the ONE restatement of the flag list this repo keeps, and it is kept because the',
            'operator reading it cannot follow a pointer to the predicate. A condition missing from it',
            'is an operator whose offer silently did not appear and who is not told why.',
            'The message lives in '.self::GUARDED_COPY.'; the predicate is '.self::OWNER.'.',
            'What it said: '.$message,
        ]));
    }

    public function test_the_predicate_docblock_names_every_condition_too(): void
    {
        $groups = self::suppressingClasses();
        $owner = (string) file_get_contents(base_path(self::OWNER));

        $missing = [];
        foreach ($groups as $group) {
            foreach ($group as $spelling) {
                if (self::names($owner, $spelling)) {
                    continue 2;
                }
            }
            $missing[] = implode('/', $group);
        }

        $this->assertSame([], $missing, self::OWNER.' owns the rule but names no spelling of: '.implode(', ', $missing));
    }

    public function test_no_third_surface_restates_the_flag_list(): void
    {
        $groups = self::suppressingClasses();
        $carriers = [];

        foreach ($this->trackedFiles() as $rel) {
            $path = base_path($rel);
            if (! is_file($path)) {
                continue;
            }
            $contents = (string) file_get_contents($path);

            // ⛔ NO SIZE CAP. The first cut skipped files over 2 MB, and the ONE tracked file
            // that crossed it was `CLAUDE_DECISIONS.md` — the largest restatement surface in
            // the repo, dropped silently from its own census. A skip that scales with how much
            // a surface has to say is the wrong skip. The whole tracked tree is ~13 MB.
            if (! mb_check_encoding($contents, 'UTF-8')) {
                continue;                       // binary; it carries no prose to restate
            }
            if (self::restatesTheList($contents, $groups)) {
                $carriers[] = $rel;
            }
        }

        sort($carriers);
        $allowed = [self::GUARDED_COPY, self::HISTORY, self::OWNER, self::SELF];
        sort($allowed);

        $this->assertSame($allowed, $carriers, implode("\n", [
            'The census of surfaces that RESTATE the interactivity flag list changed.',
            'Found:   '.implode(', ', $carriers),
            'Allowed: '.implode(', ', $allowed),
            'A NEW carrier is the defect this file exists for: the list has drifted every time this',
            'repo has restated it. State the PROPERTY and point at '.self::OWNER.' instead.',
            'A MISSING carrier means a copy that is supposed to be here stopped naming the conditions.',
        ]));
    }

    /**
     * THE CONTROL (canon #9). Each fixture is a copy this repo actually wrote or plausibly
     * would; the pinned negatives are the single mentions the census must keep letting past,
     * because excluding them is the whole reason the threshold is two groups and not one.
     */
    public function test_the_predicate_discriminates_a_restatement_from_a_mention(): void
    {
        $groups = self::suppressingClasses();

        $restatements = [
            'the drifted doc copy, verbatim in shape' => self::CONTROL_COPY,
            'the complete operator message' => 'a run that was not told to skip prompts (-n / -q / --silent / a negative SHELL_VERBOSITY)',
            'long spellings' => 'cleared for --no-interaction and for --silent',
            'wrapped across two lines' => "for `--no-interaction`/`-n` AND for any negative shell\nverbosity — `-q`, `--silent`, or an inherited `SHELL_VERBOSITY<0`",
            'split PHP string literals' => "'… skip prompts (-n / -q / --silent / a negative '\n    .'SHELL_VERBOSITY) — and this value is never written'",
        ];

        foreach ($restatements as $why => $fixture) {
            $this->assertTrue(self::restatesTheList($fixture, $groups), "the census MISSED a restatement ({$why}): {$fixture}");
        }

        $mentions = [
            'a single flag driving a test' => "\$this->runConsole(true, ['--quiet' => true]);",
            'a single flag in prose' => 'Anywhere else — a pipe, cron, a script, or a run told to skip prompts — it makes no request.',
            'shell fixtures' => "exec(\$git.'add -A && '.\$git.'commit -q -m base 2>&1');",
            'a long flag that CONTAINS a short one' => 'run it with --no-interaction to be sure',
            'nothing at all' => 'this text is about something else entirely',
        ];

        foreach ($mentions as $why => $fixture) {
            $this->assertFalse(self::restatesTheList($fixture, $groups), "the census counted a MENTION as a restatement ({$why}): {$fixture}");
        }
    }

    /**
     * The message as an operator gets it — driven through the real refusal path rather than
     * read out of the source file, so a copy that stops being REACHED cannot pass this.
     */
    private function cannotAskMessage(): string
    {
        $dir = sys_get_temp_dir().'/wb-flag-guard-'.uniqid();
        File::ensureDirectoryExists($dir);
        File::put($dir.'/writeback.json', json_encode([
            'mappings' => ['your-org/your-repo' => ['board_id' => 8, 'stages' => ['opened' => 50]]],
        ]));

        try {
            $plan = (new WritebackIdentityOffer)->prepare(
                $dir,
                $dir.'/kanban/writeback-token',
                'https://kanban.example.test/api/v3',
                [],
                false,
            );

            $this->assertNull($plan->offered, 'a run that cannot ask must offer nothing');

            // Both channels, because WHICH one carries the refusal is a rendering decision
            // the command owns — the guard's subject is the text, not the severity it prints at.
            return implode("\n", array_merge($plan->notes, $plan->warnings));
        } finally {
            File::deleteDirectory($dir);
        }
    }
}
