#!/usr/bin/env php
<?php

/**
 * Decide, BY EXPERIMENT, which of `handle()`'s branch predicates the golden fixture set
 * can actually see (DL-242 stage 0). Writes `docs/check-golden-coverage.md`.
 *
 * WHY MUTATION AND NOT COVERAGE. The property stages 1-7 depend on is not "this line
 * executed" — it is "if this branch flips, a golden file changes." A line-coverage
 * driver answers the first question; only the second one tells you whether the harness
 * would CATCH a regression there. A predicate whose two branches print identical bytes
 * is fully covered and completely unprotected, and coverage would report it green.
 *
 * (It is also the only instrument available: this host and CI have neither xdebug nor
 * pcov. That is a reason the choice was easy, not the reason it is right.)
 *
 * METHOD. For each predicate: rewrite it in place — an `if`/`elseif` condition to its
 * negation, a `foreach` iterable to `[]` (its "body never ran" branch) — run the golden
 * suite, and record whether it went red. Red ⇒ the fixture set distinguishes that
 * predicate's branches. Green ⇒ it does not, and the predicate is a DISCLOSED GAP: a
 * stage 1-7 refactor could flip it and the harness would say nothing.
 *
 * THE MEASUREMENT'S OWN BOUND, since this script exists to stop unstated ones: a red
 * run proves the fixture set REACTS to the predicate, not that it reacts for the right
 * reason. Negating a null-guard can abort the command, which also reds the suite. Those
 * are reported separately (`observed-via-abort`) rather than counted as protection.
 *
 * Usage:  php bin/check-golden-mutate.php [--repo <path>] [--limit N] [--only <id>]
 *
 * RUN IT ON A COPY. It rewrites CheckCommand.php in place between runs and restores it
 * in a `finally`, but a crashed interpreter would leave a mutant on disk — and the whole
 * run takes roughly an hour, during which a commit from the same tree would capture one.
 */

require __DIR__.'/../vendor/autoload.php';

$options = getopt('', ['repo:', 'limit:', 'only:']);
$repo = realpath($options['repo'] ?? __DIR__.'/..');
if ($repo === false || ! is_dir($repo)) {
    fwrite(STDERR, "no such repo\n");
    exit(2);
}

$target = $repo.'/app/Console/Commands/Bridge/CheckCommand.php';
$report = $repo.'/docs/check-golden-coverage.md';
$original = file_get_contents($target);
if ($original === false) {
    fwrite(STDERR, "cannot read {$target}\n");
    exit(2);
}

exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($repo.'/bin/check-golden-predicates.php').' --json', $lines, $code);
if ($code !== 0) {
    fwrite(STDERR, "predicate enumeration failed\n");
    exit(2);
}
/** @var list<array{id: string, kind: string, line: int, start: int, end: int, source: string}> $predicates */
$predicates = json_decode(implode("\n", $lines), true);

if (isset($options['only'])) {
    $predicates = array_values(array_filter($predicates, fn (array $p) => $p['id'] === $options['only']));
}
if (isset($options['limit'])) {
    $predicates = array_slice($predicates, 0, (int) $options['limit']);
}

/** Run the golden suite; return [red?, failing fixture names, aborted?]. */
$runGolden = function () use ($repo): array {
    $cmd = 'cd '.escapeshellarg($repo).' && vendor/bin/phpunit --filter test_golden_output '
        .'tests/Feature/Console/Check/CheckGoldenTest.php 2>&1';
    exec($cmd, $out, $code);
    $raw = implode("\n", $out);
    $decoded = json_decode($raw, true);
    $failing = [];
    $aborted = false;
    if (is_array($decoded)) {
        foreach ($decoded['failures'] ?? [] as $failure) {
            $message = (string) ($failure['message'] ?? '');
            if (preg_match("/fixture '([^']+)'/", $message, $m) === 1) {
                $failing[] = $m[1];
            }
            // A mutant that makes the command THROW reds the suite without the fixture
            // set having distinguished anything — a different result, reported as one.
            if (preg_match('/\b(Fatal error|Uncaught|TypeError|ArgumentCountError|Error:)/', $message) === 1) {
                $aborted = true;
            }
        }
        if (($decoded['errors'] ?? 0) > 0 || ($decoded['result'] ?? '') === 'errored') {
            $aborted = true;
        }
    } elseif ($code !== 0) {
        // phpunit could not even produce its report (a parse error in the mutant).
        $aborted = true;
    }

    return [$code !== 0, $failing, $aborted];
};

$results = [];
$started = time();
try {
    foreach ($predicates as $index => $predicate) {
        $replacement = $predicate['kind'] === 'foreach'
            ? '[]'
            : '(! ('.substr($original, $predicate['start'], $predicate['end'] - $predicate['start'] + 1).'))';
        $mutant = substr_replace($original, $replacement, $predicate['start'], $predicate['end'] - $predicate['start'] + 1);
        file_put_contents($target, $mutant);

        [$red, $failing, $aborted] = $runGolden();
        $status = match (true) {
            $red && $aborted => 'observed-via-abort',
            $red => 'observed',
            default => 'UNOBSERVED',
        };
        $results[] = $predicate + [
            'status' => $status,
            'failing' => $failing,
        ];
        fprintf(STDERR, "[%3d/%3d] %-24s %s\n", $index + 1, count($predicates), $predicate['id'], $status);
    }
} finally {
    file_put_contents($target, $original);
}

$observed = array_values(array_filter($results, fn (array $r) => $r['status'] === 'observed'));
$viaAbort = array_values(array_filter($results, fn (array $r) => $r['status'] === 'observed-via-abort'));
$gaps = array_values(array_filter($results, fn (array $r) => $r['status'] === 'UNOBSERVED'));

$rows = '';
foreach ($gaps as $gap) {
    $rows .= sprintf("| `%s` | %s | `%s` |\n", $gap['id'], $gap['kind'], str_replace('|', '\|', mb_strimwidth($gap['source'], 0, 110, '…')));
}
$abortRows = '';
foreach ($viaAbort as $row) {
    $abortRows .= sprintf("| `%s` | %s | `%s` |\n", $row['id'], $row['kind'], str_replace('|', '\|', mb_strimwidth($row['source'], 0, 110, '…')));
}

$total = count($results);
$obsCount = count($observed);
$abortCount = count($viaAbort);
$gapCount = count($gaps);
$minutes = (int) round((time() - $started) / 60);
$doc = <<<MD
# `bridge:check` golden harness — what it does and does not protect

> **Generated by `php bin/check-golden-mutate.php`. Do not hand-edit — regenerate it.**
> Measured against `app/Console/Commands/Bridge/CheckCommand.php` over the
> {$total} branch predicates in `handle()`, in {$minutes} minutes. Decision record: **DL-242**.

The DL-242 plan holds stages 0-7 to a byte-identical output contract, enforced by
`tests/Feature/Console/Check/CheckGoldenTest.php`. The plan also requires that the bound on
that contract be stated rather than implied: *"no operator-visible change" holds only over
the install shapes the fixtures cover*, and a predicate the fixture set cannot see can flip
silently during a migration.

This file names those predicates individually, so the gap is disclosed rather than silent.

## Method

Each predicate was flipped in the source — an `if`/`elseif` condition negated, a `foreach`
iterable replaced with `[]` — and the golden suite re-run:

- **observed** — the suite went red. The fixture set distinguishes that predicate's two
  branches, so a stage 1-7 refactor that changed it would be caught.
- **observed-via-abort** — the suite went red because the mutant threw, not because the
  fixture set told the branches apart. The predicate is REACHED and its guard is
  load-bearing; whether its two branches are output-distinguishable is **not** established.
- **UNOBSERVED** — the suite stayed green. Flipping this predicate changes no golden file.
  This is the disclosed gap.

Mutation is used rather than a coverage driver deliberately: coverage answers *"did this
line execute"*, and the property that matters here is *"would a change to this branch be
caught"*. A predicate whose two branches print the same bytes is fully covered and entirely
unprotected.

## Result

| | count |
|---|---|
| observed | {$obsCount} |
| observed-via-abort | {$abortCount} |
| **UNOBSERVED (disclosed gaps)** | **{$gapCount}** |
| total predicates | {$total} |

## Disclosed gaps — flipping these changes no golden file

A stage 1-7 PR touching any of these must justify the change by reading, not by a green
suite. Closing a gap means adding a fixture that reaches the branch AND makes it print
something different; some of these cannot be closed at all, because both branches
legitimately produce identical output.

| predicate | kind | condition |
|---|---|---|
{$rows}
## Reached, but the branches were not shown to be distinguishable

The mutant aborted the command. These predicates ARE exercised by the fixture set, and each
guard is doing real work — but this run does not establish that the harness would catch a
behavior change confined to them.

| predicate | kind | condition |
|---|---|---|
{$abortRows}
MD;

file_put_contents($report, $doc);
file_put_contents($repo.'/docs/check-golden-coverage.json', json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

printf("observed %d · observed-via-abort %d · UNOBSERVED %d · total %d\nwrote %s\n",
    count($observed), count($viaAbort), count($gaps), $total, $report);
