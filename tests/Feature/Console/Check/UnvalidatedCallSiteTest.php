<?php

namespace Tests\Feature\Console\Check;

use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;
use Tests\TestCase;

/**
 * A TRIPWIRE ON THE `unvalidated` CALL-SITE SET (DL-251 / card#5291), and deliberately
 * nothing more.
 *
 * WHAT IT DOES: pins the exact set of places in `app/` that CONSTRUCT a finding at
 * `Severity::Unvalidated`. Adding one, removing one, or moving one to another file reds
 * this test, so the `warn` ↔ `unvalidated` boundary the stage-10 sweep drew cannot drift by
 * accident — someone has to come here and change the list on purpose.
 *
 * WHAT IT DOES NOT DO, stated plainly because a guard trusted for more than it does is
 * worse than no guard:
 *  - **It cannot tell anyone the RIGHT severity for a new leg.** It detects that the set
 *    moved and forces a human decision; the rule that decides is prose, in
 *    {@see Severity}, and no test can apply it.
 *  - **It constrains no `warn` site.** A leg that should report `unvalidated` and reports
 *    `warn` instead is invisible here — which is exactly the population card#5291 swept by
 *    hand, and exactly why the sweep needed a human.
 *  - **It says nothing about whether a site is REACHED.** Several of the files below sit
 *    behind envelopes a baseline install never opens, and this pin cannot tell you which.
 *  - **Two evasions get past it, and both are LEXICAL rather than exotic.** (1) A DYNAMIC
 *    construction — `Finding::{$severity}(…)`, `call_user_func([Finding::class, $m], …)`,
 *    or anything else that names the factory at runtime — never contains the literal
 *    `Finding::unvalidated(` and is counted nowhere. No such call exists in `app/` today
 *    (checked at write time), which is what makes the pin exhaustive NOW rather than by
 *    construction. (2) A MOVE **WITHIN** ONE FILE is invisible: the pin is a per-file
 *    COUNT, so relocating a site from one leg to another inside the same class leaves the
 *    number unchanged. Only cross-file moves and net additions/removals red.
 *
 * IT IS BIDIRECTIONAL AND WORDING-INDEPENDENT. An earlier shape of this guard banned a
 * phrase in the message text; that shape was red on landing (the messages do not share a
 * phrase) and blind to four of the swept sites regardless. Message strings are operator
 * prose and get reworded — the CALL SITE is the durable thing.
 *
 * IT IS EXHAUSTIVE BECAUSE THE CONSTRUCTOR IS PRIVATE, and the test below asserts that
 * rather than assuming it. `Finding::__construct` was public until DL-251 and three checks
 * used it, so a fourth site could have written `new Finding(Severity::Unvalidated, …)` and
 * passed a grep for the factory name. With the door closed, `Finding::unvalidated(` is the
 * only way to mint the severity, and `Finding::scoped()` — which preserves whatever a
 * factory already decided — is the only way to carry it onward.
 */
class UnvalidatedCallSiteTest extends TestCase
{
    /**
     * Every `app/` file constructing an `unvalidated` finding, with its count.
     *
     * WHEN THIS REDS: decide, using the rule in `Severity`'s docblock, whether the leg you
     * added did not answer its own question (⇒ `unvalidated`) or answered it (⇒ the answer's
     * severity) — then update this list in the same commit.
     *
     * @var array<string, int>
     */
    private const SITES = [
        // FIVE distinct legs, not five copies of one — spelled out because this comment is
        // what a maintainer reads when the count reds, and a count with a one-leg gloss
        // cannot tell them which leg they added or lost:
        //   1. `channel.server_path` is not declared, so there is no snapshot to validate
        //      (DL-236 — the finding that created the severity);
        //   2. the launch disclosure, appended once per probe call that reached the legs
        //      (DL-237) — `bridge:check` never executes node;
        //   3. the DEPLOYED `package.json` is absent/unreadable/malformed, so the staleness
        //      compare has no left-hand side;
        //   4. this CHECKOUT's bundled `package.json` is unreadable, so it has no
        //      right-hand side;
        //   5. the configured path or the deployed directory is not visible to this user
        //      (a directory above it denies traversal) — the guard in front of every
        //      stat-derived verdict in that class.
        'app/Bridge/Support/ChannelSnapshotProbe.php' => 5,
        // The pinned-line legs that read a possibly-relocated authorized_keys (DL-251 (a)/(b)).
        'app/Bridge/Tools/SshTransportProbe.php' => 2,
        // The command's own fail-soft envelope around the writeback board probe.
        'app/Console/Commands/Bridge/CheckCommand.php' => 1,
        // The event-consumer reconciliation could not be computed (DL-236).
        'app/Bridge/Check/Checks/EventFollowsConsumerCheck.php' => 1,
        // The cross-config compares whose comparand did not resolve (DL-251 (c)).
        'app/Bridge/Check/Checks/WritebackBoardStateCheck.php' => 7,
        'app/Bridge/Check/Checks/WritebackMappingConfigCheck.php' => 3,
        // Board / cache / channel reads that did not complete (DL-251 (a)).
        'app/Bridge/Check/Checks/WritebackSourceCoverageCheck.php' => 2,
        'app/Bridge/Check/Checks/WritebackByRefCheck.php' => 1,
        'app/Bridge/Check/Checks/RetentionPostureCheck.php' => 1,
        'app/Bridge/Check/Checks/BoardToolsBoardStateCheck.php' => 2,
        'app/Bridge/Check/Checks/ChannelTransportCheck.php' => 1,
        // The repo probe could not reach GitHub, so the token was never validated — the
        // THIRD silent leg, and the one no warn-keyed sweep could have surfaced.
        'app/Bridge/Check/Checks/ReconcileRepoTokensCheck.php' => 1,
    ];

    public function test_the_unvalidated_construction_sites_are_exactly_these(): void
    {
        $found = $this->unvalidatedSitesUnderApp();

        // Non-vacuous: a broken scan returns nothing, and an empty-vs-empty compare would
        // green through a sweep that deleted every site.
        $this->assertNotEmpty($found, 'the source scan found no Finding::unvalidated( calls at all — it has broken');
        $this->assertSame(
            self::SITES,
            $found,
            "the set of `unvalidated` construction sites has MOVED.\n"
            .'Decide per site whether the leg answered its own question (see the rule in '
            ."App\\Bridge\\Support\\Severity's docblock), then update this list deliberately.",
        );
    }

    public function test_the_named_factories_are_the_only_door_to_a_finding(): void
    {
        // THE REACHABILITY GUARD FOR THE PIN ABOVE, and the half that is easy to lose: the
        // list is a complete account of the severity only while nothing else can construct
        // one. Re-publicising the constructor would leave the list above green while a new
        // `new Finding(Severity::Unvalidated, …)` minted the severity beside it.
        $this->assertTrue(
            (new ReflectionClass(Finding::class))->getConstructor()?->isPrivate(),
            'Finding::__construct is no longer private, so Finding::unvalidated( is no longer the only way '
            .'to mint Severity::Unvalidated and the call-site pin above is no longer exhaustive',
        );
    }

    /**
     * `file => count` for every `app/` file that calls `Finding::unvalidated(`, keyed by
     * repo-relative path and ordered to match the constant so a diff reads cleanly.
     *
     * @return array<string, int>
     */
    private function unvalidatedSitesUnderApp(): array
    {
        $counts = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app')));
        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $n = substr_count($this->codeOf($file->getPathname()), 'Finding::unvalidated(');
            if ($n > 0) {
                $counts[str_replace(base_path().'/', '', $file->getPathname())] = $n;
            }
        }

        // Ordered by the pinned list so `assertSame` reports a genuine difference rather
        // than filesystem iteration order.
        $ordered = [];
        foreach (array_keys(self::SITES) as $path) {
            if (isset($counts[$path])) {
                $ordered[$path] = $counts[$path];
                unset($counts[$path]);
            }
        }

        return $ordered + $counts;
    }

    /**
     * One PHP file with its comments removed.
     *
     * A raw `substr_count` over the source counts a docblock that MENTIONS the factory as a
     * call site — `Finding` itself carries one — which would put a file in the list that
     * constructs nothing. Excluding that file by name would have been a hole; dropping
     * comments makes the scan measure code, which is what the pin is about.
     */
    private function codeOf(string $path): string
    {
        $code = '';
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $code .= $token[1];

                continue;
            }
            $code .= $token;
        }

        return $code;
    }
}
