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
 *
 * EVERY WRITE IS CHECKED, and the guard THROWS rather than exits: `exit()` does not run
 * `finally`, so exiting from the loop would leave a mutant on disk — the poisoning the
 * paragraph above warns about, caused by the guard meant to prevent it. A failed write
 * used to be laundered into a verdict: no mutant reached the file, the golden run aborted
 * for the unrelated reason, and all N predicates scored `observed-via-abort` at rc 0.
 * A run that could not apply its mutation is a DESTROYED run, not a measurement.
 *
 * Artifacts are written only by a FULL run. Under `--only`/`--limit` the verdicts go to
 * stdout instead, because the generated header claims to cover every predicate in
 * `handle()` and a narrowed run would make that claim false.
 */

require __DIR__.'/../vendor/autoload.php';

$options = getopt('', ['repo:', 'limit:', 'only:']);
$repo = realpath($options['repo'] ?? __DIR__.'/..');
if ($repo === false || ! is_dir($repo)) {
    fwrite(STDERR, "no such repo\n");
    exit(2);
}

/**
 * The one write primitive. Throws — never exits — so the `finally` restore still runs.
 *
 * @throws RuntimeException
 */
$writeOrThrow = function (string $path, string $contents): void {
    $written = file_put_contents($path, $contents);
    if ($written !== strlen($contents)) {
        throw new RuntimeException(sprintf(
            'write failed: %s (wrote %s of %d bytes)',
            $path,
            $written === false ? 'nothing' : $written,
            strlen($contents)
        ));
    }
};

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
$failure = null;
try {
    foreach ($predicates as $index => $predicate) {
        $replacement = $predicate['kind'] === 'foreach'
            ? '[]'
            : '(! ('.substr($original, $predicate['start'], $predicate['end'] - $predicate['start'] + 1).'))';
        $mutant = substr_replace($original, $replacement, $predicate['start'], $predicate['end'] - $predicate['start'] + 1);
        $writeOrThrow($target, $mutant);

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
} catch (Throwable $loopFailure) {
    $failure = $loopFailure;
} finally {
    $restoreFailure = null;
    try {
        $writeOrThrow($target, $original);
    } catch (Throwable $caught) {
        $restoreFailure = $caught;
    }
}

// Both are reported, because they normally co-occur: the mutant write and the restore write
// address the SAME file, so whatever stopped one stops the other, and reporting only the
// restore would drop the cause.
if ($failure !== null || $restoreFailure !== null) {
    $lines = ["\nABORTED — no measurement was produced and no artifact was written."];
    if ($failure !== null) {
        $lines[] = sprintf('  cause:   %s (after %d of %d predicates)',
            $failure->getMessage(), count($results), count($predicates));
    }
    if ($restoreFailure === null) {
        $lines[] = '  restore: ok — the source is back to the original.';
    } else {
        // Never guess at the tree's state: read the file back and report what is actually
        // there. A "mutant is live" message over a pristine file is a wrong-but-specific
        // cause, which is worse than a generic one.
        $lines[] = '  restore: FAILED — '.$restoreFailure->getMessage();
        $lines[] = file_get_contents($target) === $original
            ? '  state:   the file still matches the original, so nothing is poisoned — but this'
                ."\n           tree cannot be written to, and no run from it will mean anything."
            : "  state:   A MUTANT IS LIVE at {$target}."
                ."\n           Restore it (git checkout -- <path>) BEFORE running anything from this"
                ."\n           tree, or the next run reads the mutant as its baseline and every"
                ."\n           verdict it prints is void.";
    }
    fwrite(STDERR, implode("\n", $lines)."\n");
    exit(2);
}

$observed = array_values(array_filter($results, fn (array $r) => $r['status'] === 'observed'));
$viaAbort = array_values(array_filter($results, fn (array $r) => $r['status'] === 'observed-via-abort'));
$gaps = array_values(array_filter($results, fn (array $r) => $r['status'] === 'UNOBSERVED'));

// `--only`/`--limit` measure a SUBSET, and the generated header claims to cover every
// predicate in handle(). Writing from a narrowed run would put a false denominator in the
// repo, so the verdicts go to stdout and the artifacts are left alone.
$narrowed = isset($options['only']) || isset($options['limit']);

// An empty result set is a measurement that never happened. It reaches here from a `--only`
// that matched no id (an operator typo, which otherwise exits 0 having printed nothing) or
// from an enumeration that returned no predicates at all — which on a FULL run would write a
// report claiming to cover "the 0 branch predicates in handle()".
if ($results === []) {
    fwrite(STDERR, "\nNO PREDICATES MEASURED: nothing was run, so there is no result to report.\n"
        ."Check the --only id against `php bin/check-golden-predicates.php`. No artifact was written.\n");
    exit(2);
}

// A degenerate corpus is a measurement failure, not a finding. Two signatures, both meaning
// the fixture set produced NO discriminating signal at all:
//   observed-via-abort N — the destroyed-run shape. Every mutant aborted the command
//     identically. Rendered as `observed-via-abort 35 · UNOBSERVED 0` it reads as the
//     STRONGEST possible outcome, which is what makes it worth refusing rather than printing.
//   UNOBSERVED N — the suite provably never went red, so it is not running (a `--filter`
//     that matches no test exits 0), and "every predicate is a disclosed gap" is a statement
//     about the harness, not about the command.
// all-`observed` is NOT refused: every flip being caught is a legitimate strong result.
// Bounded to a full run — under --only one predicate shares one status trivially.
$soleStatus = count(array_unique(array_column($results, 'status'))) === 1 ? $results[0]['status'] : null;
if (! $narrowed && count($results) > 1 && $soleStatus !== null && $soleStatus !== 'observed') {
    fwrite(STDERR, sprintf(
        "\nDEGENERATE RESULT SET: all %d predicates scored %s, so nothing was distinguished.\n"
        ."That is what a harness that is not running looks like, not what a measurement looks\n"
        ."like. No artifact was written — the files still hold the previous run.\n"
        ."Check that the golden suite runs at all in %s before re-running.\n",
        count($results), $soleStatus, $repo
    ));
    exit(2);
}

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

printf("observed %d · observed-via-abort %d · UNOBSERVED %d · total %d\n",
    count($observed), count($viaAbort), count($gaps), $total);

if ($narrowed) {
    foreach ($results as $result) {
        printf("  %-24s %s\n", $result['id'], $result['status']);
    }
    printf("narrowed run (--only/--limit) — artifacts NOT written; they must describe every predicate\n");
    exit(0);
}

// JSON_THROW_ON_ERROR, not the default false-return: `false."\n"` is `"\n"`, so an encode
// failure would write a syntactically fine, entirely empty artifact.
// The two artifacts are written in sequence, so a failure on the second leaves the PAIR
// disagreeing. Which one landed is tracked rather than assumed: "both still hold the previous
// run" is false when the report was rewritten and only the JSON failed.
$wrote = [];
try {
    $writeOrThrow($report, $doc);
    $wrote[] = $report;
    $writeOrThrow(
        $repo.'/docs/check-golden-coverage.json',
        json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n"
    );
} catch (Throwable $artifactFailure) {
    fwrite(STDERR, sprintf(
        "\nARTIFACT WRITE FAILED: %s\nThe measurement completed but is not fully on disk. %s\n",
        $artifactFailure->getMessage(),
        $wrote === []
            ? 'Neither artifact was written; both still hold the previous run.'
            : "{$report} WAS rewritten and the JSON was not, so the pair now disagrees —\n"
                .'re-run before trusting either.'
    ));
    exit(2);
}

printf("wrote %s\n", $report);
