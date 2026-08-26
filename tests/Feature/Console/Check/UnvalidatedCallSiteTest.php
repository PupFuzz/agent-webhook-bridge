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
 *  - **A SHARED constructor collapses its adopters to ONE entry, and card#5698 made that
 *    real.** `PathVisibility` constructs the not-visible finding once for SEVEN call sites
 *    across six files (four checks, the channel probe, the board-tools resolver); a
 *    further adopter — or an existing one DROPPING its guard and going back to asserting
 *    absence — moves no number here. That is the exact regression this class was carded for, and this pin cannot
 *    catch it: the adopting call sites are pinned by their own tests (each check has a
 *    not-visible case) and by `PathVisibilityAdoptionTest`, which is the guard that
 *    actually watches the adopter set. Do not read a green run here as "the guard is still
 *    adopted everywhere".
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
        //      right-hand side.
        // A FIFTH leg lived here until card#5698 — "the configured path or the deployed
        // directory is not visible to this user". It did not go away; it MOVED to
        // `PathVisibility` below, which every stat-bearing check now shares.
        'app/Bridge/Support/ChannelSnapshotProbe.php' => 4,
        // THE shared not-visible guard (card#5698). One construction, seven adopting call
        // sites across six files — see the bound above on what that centralization costs
        // this pin, and `PathVisibilityAdoptionTest` for the set itself.
        'app/Bridge/Support/PathVisibility.php' => 1,
        // The pinned-line legs that read a possibly-relocated authorized_keys (DL-251 (a)/(b)),
        // plus the DL-259 account-lookup site: a host with no `posix_getpwnam` never
        // consulted the account database at all, so "no such account" is not a conclusion
        // available to it — limb (a), a measurement that did not happen. Its SIBLING arm
        // (the database answered, and has no such account) is NOT here and must not be: it
        // stays `fail`, because that is a measured config fault.
        'app/Bridge/Tools/SshTransportProbe.php' => 3,
        // The command's own fail-soft envelope around the writeback board probe.
        'app/Console/Commands/Bridge/CheckCommand.php' => 1,
        // The event-consumer reconciliation could not be computed (DL-236), plus TWO
        // card#5698 legs whose consumer list is short for two different causes: an agent
        // this run never read (DL-255), and a classifier that implements
        // `DeclaresConsumedEvents` and threw when asked (DL-257). Both withhold the
        // dropped-arrival verdict; neither can make an EMPTY unconsumed wrong, which is
        // why the third site is not a fourth.
        // The FOURTH is the action inventory (DL-259) — converted from `Finding::ok`, the
        // second such site in this list after the source-coverage leg above (DL-258): an
        // unreadable declaration means the inventory may list actions that ARE consumed,
        // and a green line disclosing its own blindness is what corollary (A) forbids. Its
        // control is the `undeclared` caveat on the SAME line, which keeps its `ok` — that
        // doubt is about what a measured `instanceof` implies, not about whether it ran.
        'app/Bridge/Check/Checks/EventFollowsConsumerCheck.php' => 4,
        // The cross-config compares whose comparand did not resolve (DL-251 (c)), plus the
        // card#5698 legs below. THE THREE NEW SITES ARE ONE SHAPE, NOT THREE DECISIONS:
        // each reads a `CheckContext` scope map that an aborted agent never reached, so its
        // NEGATIVE is not evidence — limb (c) of the rule, with the unresolved comparand
        // being the run's own agent roster rather than a foreign config.
        //   WritebackBoardStateCheck:  the coord-terminal preflight's family gate.
        //   WritebackMappingConfigCheck: the orphaned mapping, and the terminal-set-but-
        //     family-off mirror. A FOURTH map-fed arm (family-on, terminal missing) reads
        //     the same map and deliberately has NO site here — see its comment for why the
        //     one arm that asserts nothing gets no disclosure.
        // PLUS the card#5698 idList() slice's three sites — again ONE shape, not three
        // decisions, and a DIFFERENT shape from the scope-map ones above. Each reads a
        // kanban collection whose ABSENCE the parser used to render as an empty list, so
        // the leg's negative ("that lane is not on the board", "those keys are not
        // registered") was indistinguishable from this run's failure to parse the response
        // at all — limb (c), the comparand being the board's own lane / custom-field list.
        //   WritebackBoardStateCheck:   the swimlane lookup, and the dependabot-CF lookup.
        //   BoardToolsBoardStateCheck:  the swimlane lookup (one finding for both lanes —
        //     one read failed, so there is one thing the run could not do).
        // The FOURTH member of that slice — the fail-closed `issue_number` leg — is NOT
        // here and must not be: it stays `fail`, because an unverifiable board could
        // silently double-card. Only its message stopped over-claiming.
        'app/Bridge/Check/Checks/WritebackBoardStateCheck.php' => 10,
        'app/Bridge/Check/Checks/WritebackMappingConfigCheck.php' => 5,
        // Board / cache / channel reads that did not complete (DL-251 (a)). The THIRD site on
        // the source-coverage leg is card#5701 / DL-258 and is a different limb from its two
        // neighbours: they are reads that FAILED (a throw, a page ceiling), while this one
        // SUCCEEDED and returned nothing — 0 cards, which kanban answers identically for an
        // empty board and for one this token's user cannot see. The leg had been reporting
        // `ok` there, so it is the first `Finding::ok` site converted by the audit this class
        // bounds, not another failed-read disclosure.
        'app/Bridge/Check/Checks/WritebackSourceCoverageCheck.php' => 3,
        'app/Bridge/Check/Checks/WritebackByRefCheck.php' => 1,
        // TWO legs, and the second is card#5698 sub-shape (3) / DL-261. (1) the fail-soft
        // catch around the last-failure marker read — a cache backend that threw. (2) the
        // early-finish leg: it used to assert "this PHP install has no
        // fastcgi_finish_request()" off `ExecutableFinder`, which only asks whether a
        // php-fpm binary is findable on the PATH THIS process sees. The receiver's SAPI is
        // the subject and `bridge:check` is a console command, so that is limb (b), a
        // measurement of the wrong subject — the same discriminator as
        // `ChannelTokenPathCheck` below. Measured, not reasoned: the reference host keeps
        // php-fpm in /usr/sbin and serves the receiver under FPM, and a login shell whose
        // PATH omits /usr/sbin produced the old definite claim on it.
        //   The SIBLING state — a binary WAS found — is deliberately not a second site and
        // not an `ok`: finding it does not establish the receiver's SAPI either, so the
        // leg stays silent there rather than minting a green line corollary (A) forbids.
        'app/Bridge/Check/Checks/RetentionPostureCheck.php' => 2,
        'app/Bridge/Check/Checks/BoardToolsBoardStateCheck.php' => 3,
        // card#7756 / DL-313 — THREE legs, and the count is the whole design rather than
        // three incidental disclosures, so it is spelled out here where a maintainer will
        // read it when the number moves:
        //   1. the record could not be READ (an unmigrated install) — limb (a), a
        //      measurement that did not happen. Without it the absence below would be this
        //      run's own failure wearing the seat's silence.
        //   2. NO record — the bridge has never been told this seat's client half works.
        //   3. a record OLDER than the freshness window — the same statement about now.
        // (2) AND (3) ARE THE POINT, AND NEITHER IS A `warn`. The bridge may not read the
        // seat's own keypair or `.mcp.json` (an account may only read its own files), so a
        // seat that was NEVER WIRED and a seat that is merely IDLE are one absence here —
        // the leg genuinely did not answer its own question, and a `warn` would assert a
        // fault it cannot establish while a `fail` would exit non-zero over a seat that is
        // quiet by choice. The only positive verdict this leg can reach is `ok`, off a
        // fresh successful call, because reaching the dispatcher AT ALL requires the whole
        // client chain. `BoardToolsClientHalfCheckTest` pins the two-severity set from the
        // source, so a fourth factory appearing here reds there first.
        'app/Bridge/Check/Checks/BoardToolsClientHalfCheck.php' => 3,
        'app/Bridge/Check/Checks/ChannelTransportCheck.php' => 1,
        // The repo probe could not reach GitHub, so the token was never validated — the
        // THIRD silent leg, and the one no warn-keyed sweep could have surfaced.
        'app/Bridge/Check/Checks/ReconcileRepoTokensCheck.php' => 1,
        // DL-259: the optional shared-identities.json was PRESENT and could not be read, so
        // how many accounts it declares was never measured. The loader is fail-soft by
        // design (its other caller is the runtime, which must not 5xx over a bad optional
        // file), so it answers `[]` for that exactly as for a file declaring none — and
        // this leg used to spend that as a green `0 shared account(s)`. The MALFORMED case
        // is deliberately NOT here: those bytes were read, so it is a measured fault and
        // reports `warn`.
        'app/Bridge/Check/Checks/SharedIdentitiesCheck.php' => 1,
        // card#5698 sub-shape (2): the channel token EXISTS and this process cannot read
        // it. `bridge:check` reads that token as the operator while `channel_push` reads it
        // inside the receiver request as another OS user, so the mode that stopped US is no
        // evidence about the push — limb (b), a measurement of the wrong subject.
        // ONE site, not two, and the missing one is the point: the sibling arm (the parent
        // directory denies traversal) constructs nothing here because it routes through
        // `PathVisibility` above — which is precisely the centralization bound this class's
        // docblock warns about, so `PathVisibilityAdoptionTest` is what watches that arm.
        // The THREE uid-INDEPENDENT faults — absent, group/world-readable, blank — are
        // deliberately absent from this list and keep their `warn`: the receiver hits each
        // of them identically, so "channel_push will FAIL" is earned.
        'app/Bridge/Check/Checks/ChannelTokenPathCheck.php' => 1,
        // card#5778: the board-tools bearer EXISTS and this process could not read it —
        // `SecretFile::read`'s third outcome, which used to leave here as an
        // `ErrorException` and reach no severity at all (it 500'd the board-tools door
        // and aborted `bridge:check`). Same limb (b) as ChannelTokenPathCheck above, and
        // for the same reason: this class is built BOTH by `bridge:check` as the operator
        // and by the controller as the web user, so a mode that stopped one says nothing
        // about the other.
        //   ONE site, not two, and the neighbour is the point: the ABSENT-or-blank arm a
        // few lines below already routes through `PathVisibility` for its unseeable half
        // and keeps `fail` for its measured half. This is the third state neither covered.
        'app/Bridge/Tools/BoardToolAgentResolver.php' => 1,
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
