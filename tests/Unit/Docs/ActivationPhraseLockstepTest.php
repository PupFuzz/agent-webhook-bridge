<?php

namespace Tests\Unit\Docs;

use Tests\TestCase;

/**
 * The activation sentence is restated in program-emitted text on five surfaces, and a
 * restatement with no guard is the defect (canon #16).
 *
 * `docs/board-tools-enablement.md § Activating on a running seat` OWNS the explanation.
 * Everywhere else carries ONE load-bearing phrase plus a pointer to that section — and
 * "everywhere else" is inline text a program prints (a python block, a Node refusal, a
 * PHP packet line, a shell refusal), which a reader cannot follow a pointer out of at the
 * moment they read it. DELETE-and-point is therefore not available for those copies; GUARD
 * is what is left.
 *
 * ⭐ THE PREDICATE IS A CENSUS, NOT A LIST. A test that named the five files would go
 * quiet the moment a sixth surface started saying this — which is exactly how the first
 * two extra copies (the same-box wrapper's banner, the launcher's refusal) were found,
 * late, by hand. So the population is DERIVED on every run: every git-tracked file that
 * mentions `/mcp reconnect` at all must carry the phrase verbatim. A new copy joins the
 * denominator by existing.
 *
 * ⚠ WHAT IT CANNOT CATCH, stated so nobody reads a green run as more than it is: a copy
 * can keep the phrase verbatim while the sentence around it turns false. This guard
 * catches REWORDING, not MEANING. It is a lockstep check on a string, and the only thing
 * it establishes is that no copy drifted away from the owner's wording.
 *
 * ⚠ AND THE SECOND BOUND, WHICH IS THE ONE THE COPIES' OWN COMMENTS POINT HERE FOR: a
 * copy that splits the TOKEN across two string literals leaves the census SILENTLY —
 * `str_contains` over the joined FILE stops matching, the file is never a carrier, and
 * nothing reds. A copy that splits the rest of the PHRASE is the safe direction: the file
 * still matches the token, so it is still in the denominator, and it reds as an offender.
 * ⛔ No fixture can cover the split-token direction at this level and none is written:
 * {@see self::isInLockstep()} takes an ALREADY-JOINED string, which is precisely why the
 * hazard exists at the literal boundary and not in the predicate. The mitigation is a
 * comment beside the copy, not a test — and it is on all four program-emitted copies
 * (`provision-board-tools.py`, `BoardToolsSetupPacket.php`, the connector's
 * `unbindableReason()`, `provision-board-tools-samebox.py`), because the first wording of
 * this paragraph claimed a mitigation that sat on only two of them. ⭐ ADDING THOSE TWO
 * COMMENTS CHANGED THE MEASUREMENT, because each one QUOTES the token in order to warn
 * about it: all four program-emitted copies now hold the token twice, so a split of the
 * printed literal leaves the other occurrence behind, the file stays in the denominator,
 * and it reds as an offender. Measured both directions after the change: splitting the
 * phrase in `provision-board-tools.py` names that file as an offender, and splitting the
 * TOKEN in the connector's printed literal — which passed GREEN before the comment was
 * added — now names the connector as an offender too. ⚠ That is a property of those
 * comments, not of this guard: any FUTURE copy whose surrounding text never spells the
 * token out still leaves silently, which is why the comment goes on the copy at the same
 * time the copy does.
 *
 * ⚠ AND THE THIRD BOUND: THE PREDICATE IS PER-FILE, NOT PER-OCCURRENCE. `str_contains`
 * asks only whether the phrase appears SOMEWHERE, so in a file holding it TWICE, rewording
 * ONE copy is invisible here. Live as of this writing in three carriers —
 * `provision-board-tools-samebox.py` (module docstring + the root-terminal banner),
 * `docs/board-tools-enablement.md`, and `tests/Feature/Console/BridgeCommandsTest.php` —
 * and nothing else pins the samebox banner, so drift there is caught by nothing.
 * ⛔ A per-occurrence predicate (every TOKEN offset must begin a PHRASE occurrence) was
 * MEASURED AND REJECTED, not overlooked: six carriers legitimately mention the token
 * without quoting the phrase at that spot — prose in `CLAUDE_DEPLOYMENT.md`, the pointer
 * sentences, the warning comments that quote the token in order to warn about it, and this
 * file, which mentions it nine times and quotes it once. Tightening would red all six.
 * What closes this bound is a second copy in one file EARNING its own assertion, not a
 * stricter census.
 *
 * ⚠ IT LIVES IN THE PHP SUITE ON PURPOSE. `provision-tools-python.yml` path-filters to
 * `bin/** app/** examples/channel-servers/**`, so a docs-only edit of the owner doc never
 * fires it — and a docs-only edit is precisely how the phrase would be reworded in the one
 * place that owns it. `laravel-tests.yml` carries no `paths:` filter (the same reason
 * `PythonToolsPathFilterTest` lives there), so this runs on every PR.
 *
 * This file is itself in the denominator: it mentions the token in its fixtures and
 * carries the phrase in {@see self::PHRASE}. That is not an exemption — it is the check
 * passing its own predicate.
 */
class ActivationPhraseLockstepTest extends TestCase
{
    /**
     * The load-bearing phrase, verbatim. Changing it here without changing every copy
     * reds this test — which is the point.
     */
    private const PHRASE = '/mcp reconnect does not stop the previous channel server — restart the session';

    /** Any mention of the subject at all. A file carrying this must carry PHRASE. */
    private const TOKEN = '/mcp reconnect';

    /** The section that owns the explanation every copy points at. */
    private const OWNER = 'docs/board-tools-enablement.md';

    /**
     * Paths whose mention of the token is HISTORY rather than a copy: an append-only
     * decision log and a released-changelog file record what the wording WAS at a point
     * in time, and rewriting them to match a later phrase would falsify the record.
     */
    private const HISTORY = [
        'CLAUDE_DECISIONS.md',
        'docs/CHANGELOG.md',
    ];

    /** THE PREDICATE. A file's contents are in lockstep iff mentioning implies quoting. */
    private static function isInLockstep(string $contents): bool
    {
        if (! str_contains($contents, self::TOKEN)) {
            return true;
        }

        return str_contains($contents, self::PHRASE);
    }

    /**
     * Every git-tracked file, repo-relative. `git ls-files` IS the exclusion of `vendor/`,
     * `node_modules/` and `.phpstan-cache*` — they are gitignored, so no separate list of
     * them is kept here to drift.
     *
     * @return list<string>
     */
    private function trackedFiles(): array
    {
        $cmd = 'git -C '.escapeshellarg(base_path()).' ls-files -z 2>/dev/null';
        $out = (string) shell_exec($cmd);
        $files = array_values(array_filter(explode("\0", $out), static fn ($p) => $p !== ''));

        // An empty census is a measurement that did not happen (no git, no checkout,
        // a shell_exec that is disabled), never a clean result.
        $this->assertNotEmpty($files, 'git ls-files returned nothing for '.base_path().' — this guard did not run');

        return $files;
    }

    public function test_every_tracked_file_that_mentions_the_reconnect_token_quotes_the_phrase_verbatim(): void
    {
        $offenders = [];
        $carriers = [];

        foreach ($this->trackedFiles() as $rel) {
            if (in_array($rel, self::HISTORY, true)) {
                continue;
            }
            $path = base_path($rel);
            if (! is_file($path) || filesize($path) > 2_000_000) {
                continue;
            }
            $contents = (string) file_get_contents($path);
            if (! str_contains($contents, self::TOKEN)) {
                continue;
            }
            $carriers[] = $rel;
            if (! self::isInLockstep($contents)) {
                $offenders[] = $rel;
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'These files mention "'.self::TOKEN.'" without carrying the load-bearing phrase verbatim:',
            '  '.implode("\n  ", $offenders),
            'The phrase is: '.self::PHRASE,
            'It is owned by '.self::OWNER.' § Activating on a running seat. Either quote it exactly,',
            'or stop mentioning the subject and point at that section instead.',
        ]));

        // ⭐ THE DENOMINATOR IS REPORTED, not just the verdict: a run that found no
        // carriers at all would pass the loop above vacuously.
        $this->assertNotEmpty($carriers, 'no tracked file mentions the subject — the guard covered nothing');
    }

    /**
     * The owner section is the one named file in this test, and it is named as the OWNER,
     * not as a copy: without it the census can drain to empty and the guard passes over
     * nothing (canon #9 — a check that cannot fail is a decoration).
     */
    public function test_the_owner_doc_still_carries_the_phrase_and_the_section_that_owns_it(): void
    {
        $doc = (string) file_get_contents(base_path(self::OWNER));

        $this->assertStringContainsString(self::PHRASE, $doc);
        $this->assertStringContainsString('## Activating on a running seat', $doc);
    }

    /**
     * THE CONTROL (canon #9). The predicate above is only evidence if it can say no —
     * and the failure that actually happens is a copy REWORDED by someone who did not
     * know it was load-bearing, not a copy deleted. Each fixture is a plausible edit.
     */
    public function test_the_predicate_rejects_near_miss_rewordings(): void
    {
        $nearMisses = [
            'hyphen for the em dash' => '/mcp reconnect does not stop the previous channel server - restart the session',
            'indefinite article' => '/mcp reconnect does not stop a previous channel server — restart the session',
            'tense' => '/mcp reconnect will not stop the previous channel server — restart the session',
            'sentence case' => '/mcp reconnect does not stop the previous channel server — Restart the session',
            'noun swap' => '/mcp reconnect does not stop the previous MCP server — restart the session',
            'mentions but never quotes' => 'run /mcp reconnect and see whether it comes back',
        ];

        foreach ($nearMisses as $why => $fixture) {
            $this->assertFalse(self::isInLockstep($fixture), "the predicate accepted a near miss ({$why}): {$fixture}");
        }

        // Pinned positives, so the control is not passing by rejecting everything.
        $this->assertTrue(self::isInLockstep('… '.self::PHRASE.'. Details: '.self::OWNER));
        $this->assertTrue(self::isInLockstep('a file that never mentions the subject at all'));
    }
}
