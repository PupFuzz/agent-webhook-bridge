<?php

/*
 * Re-derives how many real merged PRs moved their card ONLY through DL-308's retired
 * structural route (card#10850 / DL-436): a merge into the integration branch whose head ref
 * names the card, with no closing form in the title naming it. Those are the PRs whose cards
 * stop moving once the structural route is gone, until their authors write `closes card#N`.
 *
 *   php bin/measure-branch-only-closures.php <owner/repo> [<owner/repo> ...]
 *   php bin/measure-branch-only-closures.php --control
 *
 * POPULATION. Per repo, `gh pr list --state merged --limit 200` (the newest 200 merged PRs by
 * creation order), kept only where `mergedAt` is inside the last 60 days — the SMALLER of the two
 * windows. The repos are whatever you pass: read `writeback.json` for the mapped set rather than
 * trusting a list written here.
 *
 * THE PREDICATE IS THE RETIRED ROUTE, SPELLED FROM THE AUTHORITIES THAT SURVIVE IT. After DL-436
 * `PrOutcome::mergeClosesCard()` no longer exists, so its conjuncts are composed here from the
 * grammars it was composed from: base is not `PrOutcome::RELEASE_BASE`, the title does not carry
 * `[no-close]`, the PR is not a revert, and `CardTokenGrammar::parse()` of the head ref is a card.
 * Where the class still has the method (a checkout from before DL-436), every PR is also asked
 * through it and any disagreement is printed and fails the run.
 *
 * A PR COUNTS when that structural term is true and the title closes neither that card
 * (`ClosureGrammar::closesCard()`) nor any DL (`ClosureGrammar::closedDls()`). A title that
 * closes SOME DL is reported apart: it may close the card through the DL path, which needs a
 * board read this script does not make.
 *
 * ⚠ NOT MEASURED: whether the card named actually sat on the mapped board, or whether a PIN held
 * it. The count is of merges the grammar would have closed, not of moves that landed.
 */

use App\Bridge\Support\CardTokenGrammar;
use App\Bridge\Support\ClosureGrammar;
use App\Bridge\Support\NoCloseGrammar;
use App\Bridge\Support\RevertGrammar;
use App\Bridge\Writeback\PrOutcome;

require __DIR__.'/../vendor/autoload.php';

/**
 * @return array{kind: string, card: ?int}
 *                                         kind: branch_only | lexical | closes_dl | not_structural
 */
function classify(string $title, string $head, string $base): array
{
    $card = CardTokenGrammar::parse($head);
    $structural = $card !== null
        && $base !== PrOutcome::RELEASE_BASE
        && ! NoCloseGrammar::marks($title)
        && ! RevertGrammar::isRevert($title, $head);

    if (method_exists(PrOutcome::class, 'mergeClosesCard') && $card !== null) {
        $old = PrOutcome::mergeClosesCard(PrOutcome::forMergedBase($base), $head, $card, $title);
        if ($old !== $structural) {
            fwrite(STDERR, "PREDICATE DRIFT: head '{$head}' base '{$base}' — composed={$structural} mergeClosesCard={$old}\n");
            exit(2);
        }
    }

    return match (true) {
        ! $structural => ['kind' => 'not_structural', 'card' => $card],
        ClosureGrammar::closesCard($title, $card) => ['kind' => 'lexical', 'card' => $card],
        ClosureGrammar::closedDls($title) !== [] => ['kind' => 'closes_dl', 'card' => $card],
        default => ['kind' => 'branch_only', 'card' => $card],
    };
}

if (($argv[1] ?? null) === '--control') {
    // A known positive and a known negative, both REAL merged PRs of PupFuzz/agent-webhook-bridge,
    // plus the synthetic pair that isolates the one variable the count turns on.
    $cases = [
        ['#791 (real): card branch, bare (card#N) title', "docs(writeback): ProgramCardGuard's far end now implements the program withhold (card#10068)", 'card10068-guard-far-end-docs', 'dev', 'branch_only'],
        ['#810 (real): card branch, closes card#N title', 'fix(writeback): a pr_url names a pull request only through its first GitHub URL (closes card#10735)', 'card-10735-pr-url-anchor', 'dev', 'lexical'],
        ['synthetic: bare mention', 'feat: x (card#4811)', 'card-4811-x', 'dev', 'branch_only'],
        ['synthetic: closing form', 'feat: x (closes card#4811)', 'card-4811-x', 'dev', 'lexical'],
        ['synthetic: [no-close]', 'feat: x [no-close] (card#4811)', 'card-4811-x', 'dev', 'not_structural'],
        ['synthetic: release base', 'feat: x (card#4811)', 'card-4811-x', 'main', 'not_structural'],
        ['synthetic: type/id branch', 'feat: x (card#4811)', 'fix/4811-x', 'dev', 'not_structural'],
    ];
    $failed = 0;
    foreach ($cases as [$label, $title, $head, $base, $want]) {
        $got = classify($title, $head, $base)['kind'];
        $ok = $got === $want;
        $failed += $ok ? 0 : 1;
        printf("%s  %-52s want=%-14s got=%s\n", $ok ? 'PASS' : 'FAIL', $label, $want, $got);
    }
    exit($failed === 0 ? 0 : 1);
}

$repos = array_slice($argv, 1);
if ($repos === []) {
    fwrite(STDERR, "usage: php bin/measure-branch-only-closures.php <owner/repo> [...] | --control\n");
    exit(2);
}

$cutoff = time() - 60 * 86400;
$total = ['population' => 0, 'branch_only' => 0, 'lexical' => 0, 'closes_dl' => 0, 'not_structural' => 0];
foreach ($repos as $repo) {
    $json = shell_exec('gh pr list --repo '.escapeshellarg($repo).' --state merged --limit 200 --json number,title,headRefName,baseRefName,mergedAt');
    $prs = is_string($json) ? json_decode($json, true) : null;
    if (! is_array($prs)) {
        fwrite(STDERR, "{$repo}: could not read merged PRs — no measurement\n");
        exit(2);
    }
    $row = ['population' => 0, 'branch_only' => 0, 'lexical' => 0, 'closes_dl' => 0, 'not_structural' => 0];
    foreach ($prs as $pr) {
        if (strtotime($pr['mergedAt']) < $cutoff) {
            continue;
        }
        $row['population']++;
        $c = classify($pr['title'], $pr['headRefName'], $pr['baseRefName']);
        $row[$c['kind']]++;
        if ($c['kind'] === 'branch_only') {
            printf("  branch-only  %s#%d  card#%d  %s\n", $repo, $pr['number'], $c['card'], $pr['headRefName']);
        }
    }
    printf("%s: population %d · branch-only %d · structural+lexical %d · structural+closes-a-DL %d · not structural %d\n",
        $repo, $row['population'], $row['branch_only'], $row['lexical'], $row['closes_dl'], $row['not_structural']);
    foreach ($row as $k => $v) {
        $total[$k] += $v;
    }
}
printf("TOTAL: population %d · branch-only %d · structural+lexical %d · structural+closes-a-DL %d · not structural %d\n",
    $total['population'], $total['branch_only'], $total['lexical'], $total['closes_dl'], $total['not_structural']);
