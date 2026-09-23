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
 * ⭐ A TOKEN SCAN, not a grep for `mkdir`: three files carry the word in a `"mkdir -p …"` shell
 * line rendered into an operator packet, and several more in prose. Only a PHP CALL is a site,
 * which the tokenized walk decides structurally rather than by spelling.
 */
class DirectoryCreationCensusTest extends TestCase
{
    /**
     * site => why it is allowed to create a directory.
     *
     * @var array<string, string>
     */
    private const RULINGS = [
        'Bridge/Support/BridgePaths.php::tryEnsureDir#1' => 'the ONE place (DL-016) — ensureDir() is this plus the throw, and every other caller reaches one of the two',
    ];

    public function test_only_the_primitive_creates_a_directory(): void
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

    /** ⭐ THE CONTROL: the planted call is a site, and each near miss is not. */
    public function test_the_scan_finds_a_planted_call_and_skips_each_near_miss(): void
    {
        $plant = <<<'PHP'
        <?php
        function planted($fs, $dir) {
            @mkdir($dir, 0700, true);              // a site — the @ is its own token
            $fs->mkdir($dir);                      // a method on some filesystem object
            Fs::mkdir($dir);                       // a static call on another class
            $label = 'mkdir($dir)';                // a literal, not a call
            run("mkdir -p {$dir}");                // a shell line rendered for an operator
            // mkdir($dir) in a comment
            return $label;
        }
        function mkdir($x) { return $x; }          // a declaration, not a call
        PHP;

        $this->assertSame(
            ['Planted.php::planted#1' => 'mkdir'],
            SourceScan::sites($plant, 'Planted.php', self::siteAt(...)),
        );
    }

    /** @param  list<array{0: int|string, 1: string}>  $tokens */
    private static function siteAt(array $tokens, int $i, int $scopeStart): ?string
    {
        [$type, $text] = $tokens[$i];

        if ($type !== T_STRING || strtolower($text) !== 'mkdir' || ($tokens[$i + 1][1] ?? null) !== '(') {
            return null;
        }

        // A method/static call is a call on something else entirely, and the name after
        // `function` is a declaration. Neither creates a directory here.
        return in_array($tokens[$i - 1][0] ?? null, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)
            ? null
            : 'mkdir';
    }
}
