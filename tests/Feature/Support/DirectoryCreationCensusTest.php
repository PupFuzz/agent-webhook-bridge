<?php

namespace Tests\Feature\Support;

use Tests\Support\SourceScan;
use Tests\TestCase;

/**
 * Where does `app/` CREATE a directory? DL-016 says one place — `BridgePaths` — so the 0700
 * mode of dirs that sit next to HMAC secrets and tokens cannot drift per call site, and so the
 * race-safe idiom behind it is written once.
 *
 * ⚑ WHY AN INSTRUMENT AND NOT A REVIEW NOTE. The rule held in the docblock and nowhere else,
 * and two sites had already been written past it: `ProvisionToolsCommand::writeSecret()` (an
 * unchecked `mkdir(0700)` on a SECRET directory) and `WritebackAlertNotifier::claimSignature()`
 * (a second inline copy of the idiom). Fixing the primitive reaches only the callers that call
 * it, so the N+1th site is the one this reds on.
 *
 * ⭐ A TOKEN SCAN, not a grep: `app/` carries `mkdir` in shell lines rendered into operator
 * packets (`command grep -rln 'mkdir -p' app/`) and in prose about this very rule, and neither
 * is a site. Only a PHP CALL is, which the tokenized walk decides structurally rather than by
 * spelling. ⚠ No figure here on purpose: this sentence used to say "three files" and that
 * derivation had already fallen to two — a count in prose is a claim with a maintenance
 * schedule (`CLAUDE_CONVENTIONS.md` § *Derived figures*, DL-350).
 *
 * ⛔ WHAT THIS ANSWERS FOR — AND WHAT IT DOES NOT. The population is CALL NAMES: {@see CREATORS}
 * is the whole of it, and it is what every claim citing this class may claim. It is not "every
 * directory this app creates". Outside it, and unmeasured by anything:
 *  - a name not in that list — a shell-out (`proc_open`/`exec` of `mkdir -p`), a vendored helper
 *    that creates parents as a side effect of writing, an extension's own call;
 *  - `mkdir` reached through a RECEIVER (`$fs->mkdir()`, `Fs::mkdir()`), pinned as a non-site by
 *    {@see CREATORS} and by the control below. That pin is a claim about this install's
 *    dependency set, not about the spelling: `symfony/filesystem` — whose `Filesystem::mkdir()`
 *    WOULD create a directory — is not INSTALLED here; `composer.lock` names it only as a
 *    `suggest` of `laravel/framework` and a `require-dev` of two symfony packages, neither of
 *    which composer installs, and there is no `vendor/symfony/filesystem`. Installing it makes
 *    that pin false; re-derive it there rather than reading the control's green as cover;
 *  - anything outside `app/` — `bin/`, `routes/`, `database/`, `config/`, `tests/`.
 * The first is why the claim sites say "in any spelling this census knows" and never "anywhere".
 */
class DirectoryCreationCensusTest extends TestCase
{
    /**
     * The directory-creating call NAMES this census answers for (lower-cased), each mapped to
     * whether a RECEIVER (`->`, `?->`, `::`) in front of it keeps it a site.
     *
     * `mkdir` is the LANGUAGE's function, so only a bare call is one — see the pin in the class
     * docblock for what that assumes. The two Laravel `Filesystem` names are the opposite case:
     * the receiver is exactly what a token scan cannot resolve (`File::`, `Storage::`,
     * `Storage::disk('x')->`, `$this->files->`, an injected `Filesystem`), and no class in `app/`
     * declares a method of either name, so the NAME alone decides. Both default to mode **0755**
     * (`Illuminate\Filesystem\Filesystem::ensureDirectoryExists()`), which is the drift DL-016
     * exists to stop — a 0755 directory beside HMAC secrets — and `makeDirectory()` without
     * `$force` re-mints the concurrent-create race `tryEnsureDir()` closed.
     *
     * @var array<string, bool>
     */
    private const CREATORS = [
        'mkdir' => false,
        'ensuredirectoryexists' => true,
        'makedirectory' => true,
    ];

    /**
     * site => why it is allowed to create a directory.
     *
     * @var array<string, string>
     */
    private const RULINGS = [
        'Bridge/Support/BridgePaths.php::tryEnsureDir#1' => 'the ONE place (DL-016) — ensureDir() is this plus the throw, and every other caller reaches one of the two',
    ];

    public function test_only_the_primitive_creates_a_directory_in_any_spelling_this_census_knows(): void
    {
        $found = SourceScan::sitesInApp(self::siteAt(...));

        ksort($found);
        $ruled = self::RULINGS;
        ksort($ruled);

        $this->assertSame(
            array_keys($ruled),
            array_keys($found),
            'a directory is created outside BridgePaths — route it through ensureDir() (throws) or tryEnsureDir() (returns false) rather than re-spelling the mode and the race (DL-016, DL-409)',
        );
    }

    /**
     * ⭐ THE CONTROL, BOTH DIRECTIONS: one planted call per ACCEPTED spelling is found, and each
     * near miss — including a near miss of each accepted name — is skipped. Widening
     * {@see CREATORS} without adding its arm here is what makes this class a declaration with no
     * check (`CLAUDE_TESTING.md` § *Structural coverage classes*), so the arms are the widening.
     */
    public function test_the_scan_finds_a_planted_call_per_accepted_spelling_and_skips_each_near_miss(): void
    {
        $plant = <<<'PHP'
        <?php
        function planted($fs, $dir) {
            @mkdir($dir, 0700, true);                        // the language's own call — the @ is its own token
            File::ensureDirectoryExists($dir.'/a');          // the idiom this app already reaches for (mode 0755)
            File::makeDirectory($dir.'/b');
            Storage::makeDirectory($dir.'/c');
            Storage::disk('local')->makeDirectory($dir.'/d');// a receiver a token scan cannot resolve
            $fs->makeDirectory($dir.'/e', 0755, true);       // an injected Filesystem
            $fs?->ensureDirectoryExists($dir.'/f');
            Filesystem::makeDirectory($dir.'/g');
            return $fs;
        }

        function nearMisses($fs, $dir) {
            $fs->mkdir($dir);                                // a method on some other class
            Fs::mkdir($dir);                                 // a static call on another class
            $label = 'mkdir($dir)';                          // a literal, not a call
            $wider = 'File::ensureDirectoryExists($dir)';    // …and a literal of the widened spelling
            run("mkdir -p {$dir}");                          // a shell line rendered for an operator
            // mkdir($dir) and File::makeDirectory($dir) in a comment
            $fs->makeDirectoryLabel($dir);                   // a LONGER name, not this one
            return [$label, $wider, $fs->makeDirectory];     // a property read, not a call
        }

        function mkdir($x) { return $x; }                    // a declaration, not a call
        function makeDirectory($x) { return $x; }
        function ensureDirectoryExists($x) { return $x; }
        PHP;

        $this->assertSame(
            [
                'Planted.php::planted#1' => 'mkdir',
                'Planted.php::planted#2' => 'File::ensureDirectoryExists',
                'Planted.php::planted#3' => 'File::makeDirectory',
                'Planted.php::planted#4' => 'Storage::makeDirectory',
                'Planted.php::planted#5' => '->makeDirectory',
                'Planted.php::planted#6' => '$fs->makeDirectory',
                'Planted.php::planted#7' => '$fs?->ensureDirectoryExists',
                'Planted.php::planted#8' => 'Filesystem::makeDirectory',
            ],
            SourceScan::sites($plant, 'Planted.php', self::siteAt(...)),
        );
    }

    /** @param  list<array{0: int|string, 1: string}>  $tokens */
    private static function siteAt(array $tokens, int $i, int $scopeStart): ?string
    {
        [$type, $text] = $tokens[$i];

        if ($type !== T_STRING || ($tokens[$i + 1][1] ?? null) !== '(') {
            return null;
        }
        $receiverIsASite = self::CREATORS[strtolower($text)] ?? null;
        if ($receiverIsASite === null) {
            return null;
        }

        // The name after `function` is a DECLARATION of the name, never a call of it.
        $previous = $tokens[$i - 1][0] ?? null;
        if ($previous === T_FUNCTION) {
            return null;
        }

        $onReceiver = in_array($previous, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true);

        return match (true) {
            ! $onReceiver => $text,
            $receiverIsASite => self::receiver($tokens, $i).$tokens[$i - 1][1].$text,
            default => null,
        };
    }

    /**
     * The receiver as WRITTEN, so the control's arms name which spelling fired. Empty for a
     * receiver that is not one token (`Storage::disk('x')->`, `(new Filesystem)->`): what the
     * site IS does not depend on resolving it, which is the point of keying on the name.
     *
     * @param  list<array{0: int|string, 1: string}>  $tokens
     */
    private static function receiver(array $tokens, int $i): string
    {
        $token = $tokens[$i - 2] ?? null;

        return in_array($token[0] ?? null, [T_STRING, T_VARIABLE, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
            ? $token[1]
            : '';
    }
}
