#!/usr/bin/env php
<?php

/**
 * Enumerate the branch predicates in `CheckCommand::handle()` (DL-242 stage 0).
 *
 * The plan's rule is that the golden fixture set is DERIVED from these predicates
 * rather than invented — enumerate the `if`/`elseif`/`foreach` conditions, and any one
 * whose two branches the fixture set cannot distinguish is a NAMED, DISCLOSED gap
 * rather than a silent one. This script is the enumeration half; `check-golden-mutate.php`
 * is the half that decides, by experiment, which ones are observed.
 *
 * It reads the source rather than trusting a count carried between revisions: every
 * measured figure in this program has been re-measured at the source for exactly the
 * reason recorded in the plan's "Disproved claims" section.
 *
 * Usage:  php bin/check-golden-predicates.php [--json]
 * Output: a table (default) or JSON, one row per predicate, with the byte range of the
 *         condition expression so a mutator can rewrite it precisely — a regex over
 *         source would mis-split nested parens and string literals.
 */

require __DIR__.'/../vendor/autoload.php';

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

const TARGET = __DIR__.'/../app/Console/Commands/Bridge/CheckCommand.php';
const METHOD = 'handle';

$code = file_get_contents(TARGET);
if ($code === false) {
    fwrite(STDERR, 'cannot read '.TARGET."\n");
    exit(2);
}

$ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($code);
if ($ast === null) {
    fwrite(STDERR, "parse failed\n");
    exit(2);
}

$method = (new NodeFinder)->findFirst(
    $ast,
    fn (Node $n) => $n instanceof Node\Stmt\ClassMethod && $n->name->toString() === METHOD,
);
if (! $method instanceof Node\Stmt\ClassMethod) {
    fwrite(STDERR, 'no '.METHOD."() found\n");
    exit(2);
}

/**
 * `if`/`elseif` carry a boolean condition; `foreach` carries an iterable whose branch
 * is "the body ran at least once" vs "it did not". Both are mutable, in different ways
 * — the mutator negates the first and empties the second.
 */
$predicates = [];
foreach ((new NodeFinder)->find($method->stmts ?? [], fn (Node $n) => $n instanceof Node\Stmt\If_
    || $n instanceof Node\Stmt\ElseIf_
    || $n instanceof Node\Stmt\Foreach_) as $node) {
    $expr = $node instanceof Node\Stmt\Foreach_ ? $node->expr : $node->cond;
    $start = $expr->getStartFilePos();
    $end = $expr->getEndFilePos();
    if ($start < 0 || $end < 0) {
        fwrite(STDERR, "missing file positions — php-parser is not recording them\n");
        exit(2);
    }
    $kind = match (true) {
        $node instanceof Node\Stmt\Foreach_ => 'foreach',
        $node instanceof Node\Stmt\ElseIf_ => 'elseif',
        default => 'if',
    };
    $predicates[] = [
        'id' => sprintf('%s-L%d', $kind, $expr->getStartLine()),
        'kind' => $kind,
        'line' => $expr->getStartLine(),
        'start' => $start,
        'end' => $end,
        'source' => preg_replace('/\s+/', ' ', substr($code, $start, $end - $start + 1)),
    ];
}

// Source order, so ids are stable across runs and a diff of two reports is readable.
usort($predicates, fn (array $a, array $b) => $a['start'] <=> $b['start']);
// A line can hold two predicates of the same kind; disambiguate so ids stay unique.
$seen = [];
foreach ($predicates as $index => $predicate) {
    $n = $seen[$predicate['id']] = ($seen[$predicate['id']] ?? 0) + 1;
    if ($n > 1) {
        $predicates[$index]['id'] .= "#{$n}";
    }
}

if (in_array('--json', $argv, true)) {
    echo json_encode($predicates, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

printf("%-22s %-8s %s\n", 'id', 'kind', 'condition');
foreach ($predicates as $predicate) {
    printf("%-22s %-8s %s\n", $predicate['id'], $predicate['kind'], mb_strimwidth($predicate['source'], 0, 96, '…'));
}
printf("\n%d predicates in %s::%s() (%s)\n", count($predicates), basename(TARGET), METHOD,
    implode(', ', array_map(
        fn (string $k) => $k.': '.count(array_filter($predicates, fn (array $p) => $p['kind'] === $k)),
        ['if', 'elseif', 'foreach'],
    )));
