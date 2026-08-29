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
 *
 * IT NEEDS A SHELL THE TEST RUNNER TREATS AS AN AI AGENT'S. Every verdict here is read out
 * of a JSON report on the golden suite's stdout, and `vendor/bin/phpunit` emits that report
 * only under `laravel/pao`, which activates when `laravel/agent-detector` finds an agent
 * environment variable (`CLAUDECODE`, `AI_AGENT`, `CURSOR_AGENT`, …) or when `PAO_FORCE` is
 * set. In an ordinary operator shell phpunit prints its classic text output instead,
 * `json_decode` returns null, and every red predicate scores `observed-via-abort` naming no
 * fixture while every green one scores UNOBSERVED — two distinct statuses, so the
 * degenerate-result-set arm does not fire and a full run publishes an artifact of pure noise
 * at exit 0. That dependency was undeclared until card#7994. Run it as
 * `PAO_FORCE=1 php bin/check-golden-mutate.php`, or read the BASELINE CONTROL's refusal.
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

/**
 * Run the golden suite once.
 *
 * `decodable` and `errors` are returned rather than folded into `aborted` because they are
 * what tells the three causes of an abort apart: no report at all (the environment — see the
 * BASELINE CONTROL below), a report whose `errors` count is above zero (the command threw
 * past every fail-soft envelope), or a failure message that tripped the fatal-error regex.
 * Collapsed into one boolean, all three read as the same verdict.
 *
 * @return array{red: bool, failing: list<string>, aborted: bool, decodable: bool, errors: int}
 */
$runGolden = function () use ($repo): array {
    $cmd = 'cd '.escapeshellarg($repo).' && vendor/bin/phpunit --filter test_golden_output '
        .'tests/Feature/Console/Check/CheckGoldenTest.php 2>&1';
    exec($cmd, $out, $code);
    $raw = implode("\n", $out);
    $decoded = json_decode($raw, true);
    $failing = [];
    $aborted = false;
    $errors = 0;
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
        $errors = (int) ($decoded['errors'] ?? 0);
        if ($errors > 0 || ($decoded['result'] ?? '') === 'errored') {
            $aborted = true;
        }
    } elseif ($code !== 0) {
        // phpunit could not even produce its report (a parse error in the mutant).
        $aborted = true;
    }

    return [
        'red' => $code !== 0,
        'failing' => $failing,
        'aborted' => $aborted,
        'decodable' => is_array($decoded),
        'errors' => $errors,
    ];
};

// THE BASELINE CONTROL (card#7994). One suite run against the UNMUTATED tree, before any
// mutation is written, whose only job is to establish that the instrument every verdict
// below is read from can produce a verdict at all. A pass is evidence only if failure was
// possible, and until this existed nothing in the run could tell a mutant's red from a
// harness that was never going to answer.
//
// It buys the separation of three causes that the loop's own refusal used to conflate into
// one wrong-but-specific message about "the golden harness failing":
//   NO DECODABLE REPORT — `laravel/pao` is not emitting, because it activates only for a
//     detected AI-agent shell (see the header). This is the ORDINARY state of an operator's
//     own terminal, and it is the one that publishes silently: two statuses come out, so the
//     degenerate arm stays quiet and a full run writes both artifacts at exit 0.
//   BASELINE ALREADY RED — the golden corpus is broken before any mutation. Every verdict
//     the run would produce is then contaminated: `red` is `exit != 0`, so every predicate
//     scores `observed` or `observed-via-abort` off a failure that was already there and
//     UNOBSERVED becomes unreachable.
//   NEITHER — the loop may run, and an abort inside it is a fact about a mutant.
//
// It runs under `--only`/`--limit` too. A narrowed run prints verdicts a human reads, and
// they are exactly as false; one extra ~70s suite run is the accepted cost there.
//
// It EXITS rather than throws, unlike every guard inside the loop. That is not a style
// difference: nothing has been written to the mutation target at this point — the `try` has
// not been entered and there is no `finally` restore to skip — so this sits with the three
// other pre-loop refusals above (unreadable repo, unreadable target, failed enumeration),
// all of which exit. Any guard placed after the first `$writeOrThrow($target, $mutant)` must
// throw instead, or it strands a mutant on disk.
$baseline = $runGolden();
if (! $baseline['decodable'] || $baseline['red']) {
    $banner = ["\nBASELINE CONTROL FAILED — no mutation was applied and no artifact was written."];
    if (! $baseline['decodable']) {
        $banner[] = '  cause:   the golden suite produced NO DECODABLE REPORT on the unmutated tree.';
        $banner[] = '           Every verdict this script scores is read out of a JSON report on the';
        $banner[] = "           suite's stdout, and vendor/bin/phpunit emits that report only under";
        $banner[] = '           laravel/pao, which activates for a detected AI-agent shell (the';
        $banner[] = '           laravel/agent-detector probes CLAUDECODE / AI_AGENT / CURSOR_AGENT / …)';
        $banner[] = '           or when PAO_FORCE is set. In an ordinary shell phpunit prints its';
        $banner[] = '           classic text output instead, so this run would have scored every red';
        $banner[] = '           predicate observed-via-abort naming no fixture and every green one';
        $banner[] = '           UNOBSERVED — a full artifact of noise, at exit 0.';
        $banner[] = '  remedy:  re-run with PAO_FORCE=1 in the environment, e.g.';
        $banner[] = '               PAO_FORCE=1 php bin/check-golden-mutate.php';
    } else {
        $banner[] = sprintf(
            '  cause:   the golden suite is ALREADY RED on the unmutated tree (%d failing fixture(s), %d errored).',
            count($baseline['failing']),
            $baseline['errors']
        );
        $banner[] = '           Every verdict this run would produce is scored by re-running that same';
        $banner[] = '           suite, so all of them are contaminated: a predicate scores observed off';
        $banner[] = '           a failure that predates its mutation, and UNOBSERVED is unreachable.';
        if ($baseline['failing'] !== []) {
            $banner[] = '           failing: '.implode(', ', $baseline['failing']);
        }
        $banner[] = '  remedy:  get `vendor/bin/phpunit tests/Feature/Console/Check/CheckGoldenTest.php`';
        $banner[] = '           green — repair or regenerate the corpus — and re-run.';
    }
    fwrite(STDERR, implode("\n", $banner)."\n");
    exit(2);
}

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

        $golden = $runGolden();
        $failing = $golden['failing'];
        $status = match (true) {
            $golden['red'] && $golden['aborted'] => 'observed-via-abort',
            $golden['red'] => 'observed',
            default => 'UNOBSERVED',
        };

        // An `observed-via-abort` naming NO fixture carries no fixture-level evidence, so
        // there is nothing about this predicate for the artifact to publish. It is refused
        // (card#7994).
        //
        // THE TWO ABORT SHAPES ARE NOT THE SAME, and the first cut of this guard asserted a
        // claim that is FALSE for one of them — that a genuine abort names a fixture "by
        // construction", so an empty list means the corpus reacted to nothing:
        //   RENDERED — the mutant threw INSIDE one of the fail-soft envelopes in `handle()`.
        //     The command still prints, the golden capture still differs, phpunit records a
        //     FAILURE, and that failure's message carries both the `fixture '<name>'` the
        //     scrape reads and a `Fatal error|Uncaught|…` token. Non-empty `$failing`; this
        //     is the shape that publishes as a verdict.
        //   ESCAPED — the mutant threw OUTSIDE every envelope. `handle()` has no top-level
        //     try/catch, `GoldenCapture::capture()` calls `Artisan::call` with no catch, and
        //     `Illuminate\Console\Application::call()` runs with `setCatchExceptions(false)`,
        //     so the throwable propagates out of the test and phpunit records an ERROR rather
        //     than a failure. `laravel/pao` writes the `failures` key only when its failed
        //     count is above zero, so an all-errors report has no `failures` at all and
        //     `$failing` is necessarily `[]`. That is a REAL abort the fixture set genuinely
        //     provoked — exactly what this script's header says `observed-via-abort` is for.
        //     Not reachable on the tree last measured, which is all that can be said: the
        //     committed artifact holds no abort record of EITHER shape, so no predicate in it
        //     aborts at all. That is a fact about that corpus and that `handle()`, not a
        //     property of the code.
        //
        // REFUSING THE ESCAPED SHAPE IS A DELIBERATE RULING, not an accident of the predicate.
        // The row it would render says "reached, and the guard is load-bearing" — and with no
        // fixture named, nothing the fixture set produced backs either half. An abort with
        // zero fixture-level evidence gives the artifact nothing to publish, so the chosen
        // failure mode is to refuse loudly and let the operator read the error and decide by
        // hand, not to render a row that asserts more than the run measured.
        //
        // The whole run is refused, not the one row, for the same reason every other
        // measurement-integrity failure here is (ABORTED / NO PREDICATES MEASURED /
        // DEGENERATE RESULT SET, all of which write nothing): the generated header makes a
        // whole-population claim — "over the N branch predicates in handle()" — so one
        // unmeasured predicate falsifies the document, and a rendered row is indistinguishable
        // from the real ones. It fires IN the loop because the remaining predicates would be
        // discarded anyway, and failing here saves up to ~55 minutes of a run whose artifact
        // is already refused.
        //
        // Deliberately NOT restricted by `$predicate['kind']`. The signature is "red with no
        // fixture-level evidence", which says nothing about whether the mutation was an `if`
        // negation or a `foreach` emptied to `[]`; exempting one kind would leave the
        // identical false verdict publishable with no mechanism justifying the exemption.
        // It fires under `--only`/`--limit` too — those print verdicts to stdout for a human
        // to read, and a false verdict is exactly as false there. (Unlike DEGENERATE RESULT
        // SET, this has no n=1 triviality, so it needs no narrow-gate.)
        //
        // And deliberately NOT closed by scraping `error_details` for fixture names: that
        // would turn this exact case back into a rendered verdict. The bound is that an
        // errored phpunit run is refused loudly rather than laundered into a measurement.
        //
        // THE RESIDUE, stated because this script exists to stop unstated bounds: this catches
        // an abort with NO fixture. The mixed shape — some fixtures errored and others reached
        // a golden diff, so `errors` is above zero AND `$failing` is non-empty — renders as an
        // ordinary `observed-via-abort` whose evidence is partial, and this guard says nothing
        // about it. The cheapest honest response is the `errors` field persisted below: the
        // count is in the artifact, so the gap is auditable after the fact rather than
        // invisible. A general "was this iteration real" term stays open on card#7994.
        if ($status === 'observed-via-abort' && $failing === []) {
            throw new RuntimeException(sprintf(
                '%s scored observed-via-abort naming NO failing fixture. The baseline control passed '
                .'before this loop started — the unmutated suite ran green AND emitted a decodable '
                .'report — so this is not the missing-report environment that control screens for, and '
                .'it is anomalous rather than routine. This iteration: report %s, errors %d. Candidate '
                .'causes, none asserted: (a) the mutant threw past every fail-soft envelope in handle(), '
                .'so phpunit recorded an ERROR and the report carries no `failures` key to scrape a '
                .'fixture name out of; (b) a failure message tripped the fatal-error regex but did not '
                ."match the `fixture '<name>'` scrape; (c) the suite stopped producing a decodable "
                .'report part-way through this run. No golden file was shown to react either way, so '
                .'there is nothing to publish for this predicate: apply the mutation by hand, read the '
                .'suite output, and record the verdict rather than letting a rendered row assert it.',
                $predicate['id'],
                $golden['decodable'] ? 'decodable' : 'NOT decodable',
                $golden['errors']
            ));
        }

        // `errors` is persisted so the residue above is auditable in the artifact: a record
        // with a non-zero count reached this line only because SOME fixture still named
        // itself, and a reader can find those rows instead of having to trust that every
        // published `observed-via-abort` rests on complete evidence.
        $results[] = $predicate + [
            'status' => $status,
            'failing' => $failing,
            'errors' => $golden['errors'],
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
