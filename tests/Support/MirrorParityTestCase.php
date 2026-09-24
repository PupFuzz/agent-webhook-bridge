<?php

namespace Tests\Support;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * THE CHECK BEHIND A VENDORED MIRROR OF A RULE THIS REPO DOES NOT OWN (card#9936, card#10273).
 *
 * The bridge carries several classes that are DELIBERATE COPIES of a rule whose home is another
 * repo and, in two cases, another LANGUAGE. A copy cannot be imported, so it is re-implemented —
 * and every consumer of the copy's answers is relying on a rule this repo does not own.
 *
 * ⛔ WHY A DOCBLOCK IS NOT ENOUGH, which is the whole of both cards. The lockstep obligation used
 * to live only in a "KEEP IN SYNC" / "re-port on a rule change" note in the mirrored file — a
 * DECLARE with no CHECK, on a surface no far-end maintainer can read. That is worse than silence:
 * an auditor at either end reads the note, believes the copies are held together, and gets
 * CONFIDENCE where they should have got a question.
 *
 * ⛔ AND THE INSTRUMENT COMPARES BEHAVIOUR, NEVER TEXT. Two implementations of one rule can be
 * textually unalike and semantically identical, or textually alike and divergent — and where the
 * two ends are different LANGUAGES a textual diff is not merely weak, it is undefined. What is
 * published is a corpus of ARGUMENT → ANSWER vectors, executed against the mirror here and
 * against the authority at the far end.
 *
 * THE REVERSE ARM'S POPULATION, and it is DERIVED rather than listed in any corpus: every PUBLIC
 * METHOD the mirror declares and every CONSTANT it carries, read off the class by reflection on
 * every run. The DECLARATION is the published corpus JSON — a published artifact rather than a
 * fixture in a test file, because the far end has to be able to READ it and run it, and a check
 * that reads a local restatement of what was published can only certify the restatement. A
 * subclass of this case reads the very file a far-end maintainer downloads.
 *
 * ⭐ IT FAILS IN BOTH DIRECTIONS, which is the point. `declared ⇒ real` is half a check: it
 * passes forever on a mirror that grew a member nobody published a vector for.
 *  - **declared ⇒ real:** every vector's expectation is asserted against the mirror.
 *  - **real ⇒ declared:** a public method that is in neither `vectors` nor `mirror_local`, or a
 *    constant the corpus does not pin — or a corpus key naming a member the class no longer has —
 *    reds.
 *  - **the VECTOR POPULATION**, which neither of those two reaches: both are MEMBER-granular and
 *    blind to how many vectors a member carries, so a corpus cut to one vector per method passes
 *    both BYTE-IDENTICALLY. The population is held against the identity of the one the cross-repo
 *    measurement was taken over
 *    ({@see test_the_published_vectors_are_the_population_the_measurement_was_taken_over}).
 *
 * ⛔ WHAT A GREEN RUN DOES NOT SAY is stated ONCE per corpus, in its `not_checked_by_this_repo`,
 * where the far end reads it — including that both arms are MEMBER-granular, so a branch or an
 * inline literal edited inside an existing public method is outside both. This case asserts that
 * block is PRESENT ({@see test_the_corpus_declares_its_authority_and_names_what_this_repo_cannot_check})
 * rather than carrying a second copy of it here.
 *
 * ⚠ `mirror_local` IS NOT AN ESCAPE HATCH, and the reverse arm is why. A member listed there is
 * excluded from the VECTOR arm and stays inside the POPULATION arm, so it still has to be named,
 * in the published file, with the reason it carries no parity vector. What it buys is honesty: a
 * mirror class may hold a member with no shared observable at the far end (a bridge-local reader,
 * a bridge-local half of a return value), and forcing a "parity" vector onto it would publish a
 * local expectation dressed as a cross-repo agreement.
 */
abstract class MirrorParityTestCase extends TestCase
{
    /** The FQCN of the vendored mirror this corpus is about. */
    abstract protected static function mirrorClass(): string;

    /** The repo-relative path of the PUBLISHED corpus — the same string its `mirror.corpus` carries. */
    abstract protected static function corpusPath(): string;

    /**
     * TWO EXPECTATIONS OFF ONE CALL — one the mirror meets and one it does not — so
     * {@see disagreement} is shown to discriminate rather than to answer null unconditionally.
     * Abstract on purpose: every corpus's whole green rests on that comparator, so no subclass
     * gets to inherit someone else's control.
     *
     * `wrong_fragment` is a substring the disagreement text must contain — what the mirror
     * ACTUALLY answered — because a red run that does not say that says only that something is
     * wrong.
     *
     * @return array{method: string, args: list<mixed>, right: mixed, wrong: mixed, wrong_fragment: string}
     */
    abstract protected static function comparatorControl(): array;

    public function test_every_published_vector_holds_against_the_mirror(): void
    {
        $vectors = static::corpus()['vectors'];

        // ⚠ THE PRESENCE WITNESS for a `vectors` block that is PRESENT and EMPTY, which satisfies
        // every loop below. The unreadable, unparseable and missing-block shapes are witnessed in
        // {@see corpus} instead, so each reds by name rather than by a TypeError.
        $this->assertNotSame([], $vectors, static::corpusPath().' carries no vectors at all — the corpus, not the mirror, is what changed.');

        $disagreements = [];
        $run = 0;
        foreach ($vectors as $method => $cases) {
            $this->assertNotSame([], $cases, "the `{$method}` entry of ".static::corpusPath().' is empty, so it certifies nothing.');
            foreach ($cases as $i => $case) {
                $run++;
                $said = static::disagreement($method, $case['args'], $case['expect']);
                if ($said !== null) {
                    $disagreements[] = "{$method}#{$i}: {$said}";
                }
            }
        }

        $this->assertSame([], $disagreements, 'the bridge\'s mirror does not answer as '.static::corpusPath().' says it does. That file is PUBLISHED — the authority\'s maintainers are told to run it against their own implementation — so a disagreement here means the bridge is telling the far end something false about itself. Re-mirror from the authority, or correct the corpus and re-measure. Ran '.$run.' vectors.');
    }

    /**
     * The REVERSE arm: the corpus is held against the mirror's own surface, so a member that grows
     * here without a published vector reds instead of riding along uncovered.
     */
    public function test_the_corpus_covers_exactly_the_mirror_public_surface(): void
    {
        $corpus = static::corpus();

        $declared = array_merge(array_keys($corpus['vectors']), array_keys($corpus['mirror_local']));
        $this->assertSame(
            static::publicMethods(),
            static::sorted($declared),
            'the published corpus does not account for exactly the public methods of '.static::mirrorClass().'. A NEW public method on the mirror is a new piece of the authority\'s rule that this repo has copied, and a copy nobody published a vector for is a copy nothing holds against the authority. Add its vectors to '.static::corpusPath().' (and re-measure against the authority), or — where the member has no shared observable at the far end — name it in `mirror_local` with the reason. Delete a corpus entry whose method is gone.',
        );

        $this->assertSame(
            [],
            array_values(array_intersect(array_keys($corpus['vectors']), array_keys($corpus['mirror_local']))),
            'a member of '.static::corpusPath().' is in BOTH `vectors` and `mirror_local`, which claims it is and is not held against the authority. One or the other.',
        );

        foreach ($corpus['mirror_local'] as $member => $reason) {
            $this->assertNotEmpty($reason, "`mirror_local.{$member}` of ".static::corpusPath().' carries no reason. An unexplained exclusion is how a member that SHOULD be parity-checked gets parked there and stays.');
        }

        $this->assertSame(
            static::sorted(static::classConstants()),
            static::sorted($corpus['constants']),
            'the published corpus does not pin exactly the constants of '.static::mirrorClass().'. These are what the mirrored methods are parametrised by, so an unpinned one is a rule the far end cannot check its own copy against.',
        );
    }

    /**
     * ⭐ THE MEASURED DISAGREEMENTS, PINNED ON BOTH ENDS RATHER THAN WRITTEN DOWN.
     *
     * A measurement that finds the two ends disagreeing somewhere has three possible endings, and
     * only one of them is a contract. Fixing the mirror is out of scope where the mirror's answer
     * is the RIGHT one (a config-shape guard the authority simply does not have); publishing the
     * authority's answer as an `expect` would ship a red suite and a false claim about this repo;
     * and writing the divergence down in prose re-mints, inside the fix, the exact defect these
     * cards exist to remove — a DECLARE with no CHECK.
     *
     * So a known divergence is a PAIR, and both halves are held. This leg holds the `mirror` half:
     * the mirror still answers what the published file says it answers, so a bridge-side change
     * that silently CLOSED or WIDENED the gap reds here. The `authority` half is held by the
     * runner at the far end, which also reds when the two values have CONVERGED — at which point
     * the entry is stale and gets deleted rather than re-worded.
     *
     * ⚠ It can never substitute for coverage: a method may appear here only if it also carries
     * ordinary vectors, which the reverse arm counts.
     */
    public function test_every_known_divergence_still_holds_on_this_end(): void
    {
        $corpus = static::corpus();

        $this->assertSame(
            [],
            array_values(array_diff(array_keys($corpus['known_divergences']), array_keys($corpus['vectors']))),
            'a method of '.static::corpusPath().' carries known divergences and no ordinary vectors. A member documented only by where it disagrees is a member nothing holds to where it agrees.',
        );

        $wrong = [];
        foreach ($corpus['known_divergences'] as $method => $cases) {
            $this->assertNotSame([], $cases, "the `{$method}` entry of ".static::corpusPath().'\'s `known_divergences` is empty — delete the key rather than publishing an empty claim.');
            foreach ($cases as $i => $case) {
                $this->assertNotEmpty($case['why'] ?? null, "known_divergences.{$method}#{$i} carries no `why`. An unexplained divergence is indistinguishable from an unnoticed one.");
                $this->assertNotSame(
                    $case['authority'],
                    $case['mirror'],
                    "known_divergences.{$method}#{$i} publishes the SAME value for both ends, so it documents no divergence at all. If the two ends have converged, DELETE the entry and promote the case to an ordinary vector.",
                );
                $said = static::disagreement($method, $case['args'], $case['mirror']);
                if ($said !== null) {
                    $wrong[] = "{$method}#{$i}: {$said}";
                }
            }
        }

        $this->assertSame([], $wrong, static::corpusPath().' publishes what the mirror answers where it is KNOWN to disagree with the authority, and the mirror no longer answers that. Either the gap moved or it closed — re-measure both ends, and if they now agree, delete the entry and publish an ordinary vector instead.');
    }

    /**
     * ⚠ THE POPULATION LEG, and it exists because the two arms above HAVE none. Measured on
     * card#9936: cut a corpus to one vector per method — losing every negative one — and both arms
     * pass BYTE-IDENTICALLY, same tests, same assertion count. The presence witness is one vector
     * deep, the reverse arm compares member NAMES and constant VALUES, and the vector population is
     * in neither, so a green run certifies a PUBLISHED contract without ever stating how much of it
     * was there.
     *
     * ⛔ NOT A PINNED COUNT. A count here would be a restatement with a maintenance schedule
     * (canon #16). What is pinned is the IDENTITY of the population the cross-repo measurement was
     * taken over — the way `last_measured.authority_blob` already pins the identity of the code that
     * answered — and this leg RE-DERIVES it from the vectors on every run. That makes it the CHECK
     * for a declaration the corpus already published and nothing held: `last_measured.result` says
     * in as many words that whoever adds a vector re-takes the measurement over the whole corpus,
     * *because nothing reds when they do not*. Now something does, in this repo, in both directions
     * — an added vector and a DELETED one red alike.
     *
     * What it does NOT reach: the far end, which reds on nothing here (each corpus's
     * `not_checked_by_this_repo` owns that bound), and the truth of the measurement itself.
     */
    public function test_the_published_vectors_are_the_population_the_measurement_was_taken_over(): void
    {
        $corpus = static::corpus();

        $this->assertSame(
            $corpus['last_measured']['vectors_digest'] ?? null,
            static::vectorsDigest($corpus['vectors'], $corpus['known_divergences']),
            'the vectors of '.static::corpusPath().' are not the population its `last_measured` block was taken over ('.static::population($corpus['vectors'], $corpus['known_divergences']).'). A vector was added, changed or DELETED since that measurement — and deletion is the case the other arms cannot see at all, because a corpus cut to one vector per method passes both of them unchanged. Re-take the measurement over the WHOLE corpus against the authority (`last_measured.method` says how), re-date the block, and write the actual digest below into `last_measured.vectors_digest`. A vector\'s `note` and the ORDER of its keys are deliberately outside this digest: rewording prose and re-ordering keys are not behavioural claims and must not demand a re-measurement. Its argument TYPES are INSIDE it, because `85` and `85.0` are different inputs — so a PHP round-trip of this file, which collapses the second spelling to the first, IS a change.',
        );
    }

    /**
     * ⚠ THE CONTROL FOR THE COMPARATOR ITSELF. Every assertion above is built on
     * {@see disagreement} answering null, so a comparator that answered null unconditionally would
     * make the whole class green and vacuous. This feeds it one expectation known to be wrong and
     * one known to be right, off the SAME call.
     */
    public function test_the_comparator_tells_agreement_from_disagreement(): void
    {
        $control = static::comparatorControl();

        $this->assertNull(static::disagreement($control['method'], $control['args'], $control['right']));

        $said = static::disagreement($control['method'], $control['args'], $control['wrong']);
        $this->assertIsString($said, 'the comparator answered AGREEMENT for an expectation the control declares wrong, so every green above is vacuous.');
        $this->assertStringContainsString($control['wrong_fragment'], $said, 'a disagreement must report what the mirror actually answered, or a red run says only that something is wrong.');
    }

    /**
     * The corpus is also the DECLARATION the far end reads, so the legs that make it one are
     * asserted present. A published artifact that quietly lost the paragraph naming what this repo
     * cannot verify would read as a complete contract.
     */
    public function test_the_corpus_declares_its_authority_and_names_what_this_repo_cannot_check(): void
    {
        $corpus = static::corpus();

        // `symbol` rather than `class`: one of these authorities is a PHP class and two are Python
        // module-level functions, and a key that only a class can fill would have forced the Python
        // corpora to either lie or leave the far end unnamed.
        foreach (['repo', 'symbol', 'path', 'relationship'] as $key) {
            $this->assertNotEmpty($corpus['authority'][$key] ?? null, "the corpus does not say what it mirrors ({$key}) — the far end cannot act on a contract that does not name them.");
        }

        $this->assertNotEmpty($corpus['how_the_far_end_runs_this'] ?? null);
        $this->assertNotSame([], $corpus['not_checked_by_this_repo'] ?? [], 'the corpus must NAME what this end cannot establish. Nothing here reds when the authority changes its own code, and a contract that does not say so hands the far end confidence instead of a question (canon #7).');

        foreach (['date', 'authority_ref', 'method', 'result', 'vectors_digest'] as $key) {
            $this->assertNotEmpty($corpus['last_measured'][$key] ?? null, "the corpus's cross-repo measurement is missing `{$key}`. An undated, unattributed agreement claim is the thing these cards exist to remove.");
        }

        // ⚠ THE RUNNER IS AN ARTIFACT, SO ITS EXISTENCE IS CHECKABLE AND IS CHECKED. A corpus that
        // names a program the far end should run against its own code is making a claim about this
        // repo's tree; a renamed or deleted runner would leave the published recipe pointing at
        // nothing, and the far end would find that out instead of us.
        $runner = $corpus['mirror']['runner'] ?? null;
        if ($runner !== null) {
            $this->assertFileExists(static::repoRoot().'/'.$runner, 'the corpus names `mirror.runner` as the program the far end runs, and this repo does not ship it at that path.');
        }
    }

    /** The mirror's answer for one vector, or null when it agrees. */
    protected static function disagreement(string $method, array $args, mixed $expect): ?string
    {
        $got = static::invoke($method, $args);
        if ($got === $expect) {
            return null;
        }

        return 'args='.static::readable($args).' expected '.static::readable($expect).', mirror answered '.static::readable($got);
    }

    /**
     * Call one mirror member. STATIC vs INSTANCE is read off the method rather than declared by the
     * subclass: the mirrors this case serves are a mix, and a hand-declared calling convention is
     * one more thing that can be wrong in a direction nothing reds on.
     */
    protected static function invoke(string $method, array $args): mixed
    {
        $class = static::mirrorClass();

        return (new ReflectionMethod($class, $method))->isStatic()
            ? $class::$method(...$args)
            : (new $class)->{$method}(...$args);
    }

    protected static function readable(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES);
    }

    /**
     * The identity of the vector population: every vector's method, its position in that method's
     * list, and everything the corpus spells about it EXCEPT its `note` and the ORDER of its keys,
     * one line each, byte-sorted so the digest is a property of the vectors and not of the order the
     * blocks happen to sit in.
     *
     * ⚠ KNOWN DIVERGENCES ARE INSIDE IT. They are published measurements like any other, and a
     * DELETED one is exactly the case the other arms cannot see. They fold in under their own
     * prefix, so an empty block leaves the digest of a corpus that carries none untouched.
     *
     * @param  array<string, array<int, array<string, mixed>>>  $vectors
     * @param  array<string, array<int, array<string, mixed>>>  $knownDivergences
     */
    protected static function vectorsDigest(array $vectors, array $knownDivergences = []): string
    {
        $lines = [];
        foreach ($vectors as $method => $cases) {
            foreach ($cases as $i => $case) {
                unset($case['note']);
                $lines[] = $method.'#'.$i.' '.static::canonical($case);
            }
        }
        foreach ($knownDivergences as $method => $cases) {
            foreach ($cases as $i => $case) {
                unset($case['why']);
                $lines[] = 'divergence '.$method.'#'.$i.' '.static::canonical($case);
            }
        }
        sort($lines);

        return 'sha256:'.hash('sha256', implode("\n", $lines));
    }

    /**
     * One value rendered so the digest is a property of the VECTOR and not of the host's
     * `serialize_precision` ini, which is what `json_encode` renders a float through — a digest
     * taken that way would red on a differently configured box, over a corpus carrying any float at
     * all, and say "the corpus moved".
     *
     * ⛔ The TYPE is tagged because it is part of the vector: `85` and `85.0` are different INPUTS —
     * a mirror may branch on one and not the other — and PHP's own `json_decode`/`json_encode`
     * round-trip collapses the second spelling to the first, so an untagged digest would let a
     * reformat of the published file silently retype a measured vector and stay byte-identical.
     */
    protected static function canonical(mixed $value): string
    {
        if (is_array($value)) {
            // Sorted, so a key REORDER — which no formatter treats as a change and which moves no
            // value — does not demand a fresh cross-repo measurement.
            ksort($value);

            $parts = [];
            foreach ($value as $key => $item) {
                $parts[] = static::canonical((string) $key).':'.static::canonical($item);
            }

            return '['.implode(',', $parts).']';
        }

        return match (true) {
            is_float($value) => 'F'.sprintf('%.17G', $value),
            is_int($value) => 'I'.$value,
            is_bool($value) => $value ? 'true' : 'false',
            is_string($value) => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            default => 'null',
        };
    }

    /**
     * The denominator, derived from the corpus in hand rather than written down anywhere.
     *
     * @param  array<string, array<int, mixed>>  $vectors
     * @param  array<string, array<int, mixed>>  $knownDivergences
     */
    protected static function population(array $vectors, array $knownDivergences = []): string
    {
        $per = [];
        $total = 0;
        foreach ($vectors as $method => $cases) {
            $per[] = $method.' '.count($cases);
            $total += count($cases);
        }
        sort($per);
        $divergences = array_sum(array_map('count', $knownDivergences));

        return "the corpus in hand carries {$total} vectors over ".count($vectors).' methods: '.implode(', ', $per).", plus {$divergences} known divergences";
    }

    /** @return array<string, mixed> */
    protected static function corpus(): array
    {
        $raw = file_get_contents(static::repoRoot().'/'.static::corpusPath());
        self::assertIsString($raw, static::corpusPath().' is unreadable.');

        $doc = json_decode($raw, true);
        self::assertIsArray($doc, static::corpusPath().' is not parseable JSON — it is a PUBLISHED artifact, so a consumer at the far end reads exactly this file.');

        // ⚠ THE PRESENCE WITNESS, AT THE SHAPE RATHER THAN AT ONE VALUE. A corpus missing these
        // blocks used to leave `$doc['vectors']` as null, which satisfies every loop below
        // `assertNotSame([], …)` and reds only by an incidental TypeError nobody wrote.
        foreach (['vectors', 'constants', 'mirror_local', 'known_divergences'] as $block) {
            self::assertIsArray($doc[$block] ?? null, static::corpusPath()." carries no `{$block}` block at all — the corpus, not the mirror, is what changed. An EMPTY block is a legitimate answer and must be spelled; an ABSENT one is not.");
        }

        self::assertSame(static::corpusPath(), $doc['mirror']['corpus'] ?? null, 'the corpus\'s `mirror.corpus` is not this file\'s own repo-relative path, so the far end that downloaded it is told nothing about where it came from, or is told the wrong place.');
        self::assertSame(static::mirrorClass(), $doc['mirror']['class'] ?? null, 'the corpus\'s `mirror.class` does not name the class this check drives, so the published file and the check have different subjects.');

        return $doc;
    }

    protected static function repoRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return list<string> */
    protected static function publicMethods(): array
    {
        $names = [];
        foreach ((new ReflectionClass(static::mirrorClass()))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() === static::mirrorClass()) {
                $names[] = $method->getName();
            }
        }

        return static::sorted($names);
    }

    /** @return array<string, mixed> */
    protected static function classConstants(): array
    {
        return (new ReflectionClass(static::mirrorClass()))->getConstants();
    }

    /**
     * @template T of array
     *
     * @param  T  $values
     * @return T
     */
    protected static function sorted(array $values): array
    {
        if (array_is_list($values)) {
            sort($values);

            return $values;
        }

        ksort($values);

        return $values;
    }
}
