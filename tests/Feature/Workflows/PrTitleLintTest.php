<?php

namespace Tests\Feature\Workflows;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Executes the REAL bash out of `.github/workflows/pr-title-lint.yml` — the step's
 * `run:` block is extracted from the YAML and driven under `bash`, so this cannot
 * drift from what CI actually runs the way a re-implementation of the predicate would.
 *
 * WHY THIS FILE EXISTS. The lint's grammar has been edited three times (DL-201 widened
 * `card[-#]`, DL-233 made the separator optional, DL-234 added the near-miss warn) and
 * each round's matrix was driven ad-hoc during review and then thrown away — so every
 * later edit re-derived correctness by reading. The near-miss leg in particular asserts
 * "the accepted grammar did NOT match", which is only correct while it stays in lockstep
 * with `GitHubPrCardMoveClassifier::CARD_TOKEN_PATTERN`; nothing but a test can hold that.
 */
class PrTitleLintTest extends TestCase
{
    /** Extract one step's `run:` script from the workflow by name prefix. */
    private function stepScript(string $namePrefix): string
    {
        $wf = Yaml::parseFile(base_path('.github/workflows/pr-title-lint.yml'));
        foreach ($wf['jobs']['lint-title']['steps'] as $step) {
            if (str_starts_with((string) ($step['name'] ?? ''), $namePrefix)) {
                $this->assertSame('bash', $step['shell'] ?? null, 'the step must pin bash');

                return (string) $step['run'];
            }
        }
        $this->fail("no step named like '{$namePrefix}' in pr-title-lint.yml");
    }

    /** Run the near-miss step with a given title/branch; return its combined output. */
    private function runWarnStep(string $title, string $branch = 'f'): string
    {
        $script = $this->stepScript('Warn on a card token');
        $tmp = tempnam(sys_get_temp_dir(), 'lint').'.sh';
        file_put_contents($tmp, $script);
        $cmd = 'TITLE='.escapeshellarg($title).' BRANCH='.escapeshellarg($branch)
            .' bash '.escapeshellarg($tmp).' 2>&1';
        $out = (string) shell_exec($cmd);
        @unlink($tmp);

        return $out;
    }

    private function warned(string $title, string $branch = 'f'): bool
    {
        return str_contains($this->runWarnStep($title, $branch), '::warning::');
    }

    /**
     * The silent case this leg exists for. DL-231 made a Unicode-digit token correlate
     * to nothing; nothing warned, because the near-miss probe's own `\d` is ASCII too —
     * `card_٣` is invisible even though `_` IS in that probe's separator set.
     */
    public function test_warns_on_unicode_digit_tokens_in_every_separator_spelling(): void
    {
        $three = "\u{0663}";
        foreach (["card#{$three}", "card-{$three}", "card_{$three}"] as $token) {
            $this->assertTrue($this->warned("Fix a thing {$token}"), "'{$token}' must warn");
        }
    }

    public function test_warns_on_ascii_near_misses_that_today_only_warn_post_merge(): void
    {
        // Legal-but-lossy today: they merge, then never correlate. WARNING not error —
        // blocking them is a separate decision from closing the silent case.
        foreach (['card_3054', 'card.3054', 'card:3054', 'card #5082'] as $token) {
            $this->assertTrue($this->warned("Fix a thing {$token}"), "'{$token}' must warn");
        }
    }

    public function test_warns_on_single_digit_glued_which_dl233_did_not_widen_to(): void
    {
        // DL-233 accepts glued at 2+ digits only, so `card4` became a NEW near-miss the
        // moment the separator went optional. Caught because the predicate re-derives
        // "bad" from "not good" rather than enumerating shapes.
        $this->assertTrue($this->warned('Fix a thing card4'));
    }

    public function test_does_not_warn_on_any_accepted_spelling(): void
    {
        foreach (['card#3', 'card-3', 'card#5144', 'card-5147', 'card4524', 'CARD4524'] as $token) {
            $this->assertFalse($this->warned("Fix a thing {$token}"), "'{$token}' must NOT warn");
        }
    }

    public function test_does_not_warn_on_prose_or_embedded_words(): void
    {
        // DL-201 ruled prose stays silent — a bare space is deliberately not a separator.
        // discard/wildcard are what the leading boundary class protects.
        foreach (['supports card 2 in prose', 'Refactor the discard4524 helper',
            'Use a wildcard-2 match', 'a discard-1 path', 'no token at all'] as $text) {
            $this->assertFalse($this->warned($text), "'{$text}' must NOT warn");
        }
    }

    public function test_the_branch_is_examined_not_only_the_title(): void
    {
        // The classifier correlates on title OR head branch, so the lint must read both
        // or it goes blind on exactly the branch-carried tokens it was built for.
        $this->assertTrue($this->warned('a clean title', 'feat/card_3054-fix'));
        $this->assertFalse($this->warned('a clean title', 'card-5150-slug'));
    }

    public function test_the_warn_leg_never_fails_the_job(): void
    {
        // Severity is the decision: warning, not error. If this ever exits non-zero it
        // has silently become a merge gate.
        $script = $this->stepScript('Warn on a card token');
        $tmp = tempnam(sys_get_temp_dir(), 'lint').'.sh';
        file_put_contents($tmp, $script);
        $rc = 0;
        $three = "\u{0663}";
        exec('TITLE='.escapeshellarg("card#{$three}").' BRANCH=f bash '.escapeshellarg($tmp).' 2>&1', $o, $rc);
        @unlink($tmp);
        $this->assertSame(0, $rc, 'the near-miss leg must warn, never fail');
    }

    public function test_the_leg_is_unconditional_not_behind_the_branch_gate(): void
    {
        // The branch gate is WHY the residue exists — it skips `card-<id>-...` and every
        // non `<type>/<id>-slug` shape. A branch the gated step skips must still warn here.
        // `chore/docs` and `card-5150-...` are both branches that step exits early on.
        $this->assertTrue($this->warned('Fix a thing card_3054', 'chore/docs'));
        $this->assertTrue($this->warned('Fix a thing card_3054', 'wip/no-id'));
    }

    /**
     * THE LIMIT, asserted rather than discovered later: the predicate is whole-subject,
     * not per-token. If ANY accepted token is present across title+branch, nothing warns —
     * even if a second, malformed token sits beside it.
     *
     * That is the deliberate no-cry-wolf choice for a WARNING leg: a correlating token
     * means the card does move, so the PR is not in the silent-failure class this exists
     * to catch. The cost is real and bounded — an author who writes `card-5150` in the
     * branch and `card_3054` in the title gets no nudge that the second one is inert, and
     * card 3054 stays put. Making that warn needs per-token analysis (tokenize, then test
     * each), which is a bigger change than a warning leg justifies; the co-present-token
     * case that actually MOVES the wrong card is DL-218's conflict path, not this.
     */
    public function test_whole_subject_predicate_does_not_warn_when_another_token_correlates(): void
    {
        $this->assertFalse($this->warned('Fix a thing card_3054', 'card-5150-some-slug'));
        $this->assertFalse($this->warned('card-5150 and also card_3054'));
    }
}
