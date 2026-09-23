<?php

namespace Tests\Unit\Support;

use App\Bridge\Support\ExternalReferenceNormalizer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * THE CHECK BEHIND THE BRIDGE'S MIRROR OF KANBAN'S NORMALIZATION RULE (card#9936).
 *
 * `App\Bridge\Support\ExternalReferenceNormalizer` is a VENDORED copy of kanban-board's
 * `App\Services\ExternalReferenceNormalizer`. The bridge is a separate repo and runtime and
 * cannot import that class, so it mirrors it — and every consumer of the mirror's answers is
 * relying on a rule the bridge does not own. `board_my_cards` is the sharpest case: its
 * `source` field is computed HERE, not read from the server (`tasks/search.json` does not
 * return the stored qualifier at all), so a seat on a shared board reads `source: owner/a`,
 * concludes a merge in `a` will move its card, and is wrong if the mirror has drifted.
 *
 * ⛔ WHY THE DOCBLOCK WAS NOT ENOUGH, which is the whole of this card. The lockstep obligation
 * used to live only in a "KEEP IN SYNC" note in the mirrored file — a DECLARE with no CHECK, on
 * a surface no consuming seat and no kanban maintainer can read. That is worse than silence: an
 * auditor at either end reads the note, believes the copies are held together, and gets
 * CONFIDENCE where they should have got a question. These two authorities have already drifted
 * in production (kanban DL-251 / bridge DL-309) and nothing reported it.
 *
 * THE REVERSE ARM'S POPULATION, and it is DERIVED rather than listed here: every PUBLIC
 * METHOD this class declares and every CONSTANT it carries, read off the class by reflection
 * on every run. The DECLARATION is
 * [`docs/external-reference-parity-corpus.json`](../../../docs/external-reference-parity-corpus.json)
 * — a published artifact rather than a fixture in this file, because the far end has to be able
 * to READ it and run it, and a check that reads a local restatement of what was published can
 * only certify the restatement. This class reads the very file a kanban maintainer downloads.
 *
 * ⭐ IT FAILS IN BOTH DIRECTIONS, which is the point. `declared ⇒ real` is half a check: it
 * passes forever on a mirror that grew a member nobody published a vector for.
 *  - **declared ⇒ real:** every vector's expectation is asserted against this class.
 *  - **real ⇒ declared:** a public method with no vectors, or a constant the corpus does not
 *    pin — or a corpus key naming a member this class no longer has — reds.
 *  - **the VECTOR POPULATION**, which neither of those two reaches: both are MEMBER-granular
 *    and blind to how many vectors a member carries, so a corpus cut to one vector per method
 *    passes both BYTE-IDENTICALLY. The population is held against the identity of the one the
 *    cross-repo measurement was taken over
 *    ({@see test_the_published_vectors_are_the_population_the_measurement_was_taken_over}).
 *
 * ⛔ WHAT A GREEN RUN DOES NOT SAY is stated ONCE, in the corpus's `not_checked_by_this_repo`,
 * where the far end reads it — including that both arms are MEMBER-granular, so a branch or an
 * inline literal edited inside an existing public method is outside both. This class asserts that
 * block is PRESENT ({@see test_the_corpus_declares_its_authority_and_names_what_this_repo_cannot_check})
 * rather than carrying a second copy of it here.
 */
class ExternalReferenceNormalizerTest extends TestCase
{
    private const CORPUS = 'docs/external-reference-parity-corpus.json';

    public function test_every_published_vector_holds_against_the_mirror(): void
    {
        $vectors = self::corpus()['vectors'];

        // ⚠ THE PRESENCE WITNESS for a `vectors` block that is PRESENT and EMPTY, which
        // satisfies every loop below. The unreadable, unparseable and missing-block shapes are
        // witnessed in {@see corpus} instead, so each reds by name rather than by a TypeError.
        $this->assertNotSame([], $vectors, self::CORPUS.' carries no vectors at all — the corpus, not the mirror, is what changed.');

        $disagreements = [];
        $run = 0;
        foreach ($vectors as $method => $cases) {
            $this->assertNotSame([], $cases, "the `{$method}` entry of ".self::CORPUS.' is empty, so it certifies nothing.');
            foreach ($cases as $i => $case) {
                $run++;
                $said = self::disagreement($method, $case['args'], $case['expect']);
                if ($said !== null) {
                    $disagreements[] = "{$method}#{$i}: {$said}";
                }
            }
        }

        $this->assertSame([], $disagreements, "the bridge's mirror does not answer as ".self::CORPUS." says it does. That file is PUBLISHED — kanban-board's maintainers are told to run it against their own class — so a disagreement here means the bridge is telling the far end something false about itself. Re-mirror from the kanban authority, or correct the corpus and re-measure. Ran {$run} vectors.");
    }

    /**
     * The REVERSE arm: the corpus is held against the mirror's own surface, so a member that
     * grows here without a published vector reds instead of riding along uncovered.
     */
    public function test_the_corpus_covers_exactly_the_mirror_public_surface(): void
    {
        $corpus = self::corpus();

        $this->assertSame(
            self::publicMethods(),
            self::sorted(array_keys($corpus['vectors'])),
            'the published corpus does not cover exactly the public methods of '.ExternalReferenceNormalizer::class.'. A NEW public method on the mirror is a new piece of kanban rule the bridge has copied, and a copy nobody published a vector for is a copy nothing holds against the authority. Add its vectors to '.self::CORPUS.' (and re-measure against kanban), or delete a corpus entry whose method is gone.',
        );

        $this->assertSame(
            self::sorted(self::classConstants()),
            self::sorted($corpus['constants']),
            'the published corpus does not pin exactly the constants of '.ExternalReferenceNormalizer::class.'. These are what the mirrored methods are parametrised by — a system slug, the URL key preference order, the ref cap — so an unpinned one is a rule the far end cannot check its own copy against.',
        );
    }

    /**
     * ⚠ THE POPULATION LEG, and it exists because the two arms above HAVE none. Measured:
     * cut this corpus to one vector per method — losing every negative one — and both arms
     * pass BYTE-IDENTICALLY, same tests, same assertion count. The presence witness is one
     * vector deep, the reverse arm compares member NAMES and constant VALUES, and the
     * vector population is in neither, so a green run certifies a PUBLISHED contract
     * without ever stating how much of it was there (card#9936 — the same leg
     * `bin/decision-log.py` gained as `_population()` in this change, for the same reason).
     *
     * ⛔ NOT A PINNED COUNT. A count here would be a restatement with a maintenance
     * schedule (canon #16). What is pinned is the IDENTITY of the population the cross-repo
     * measurement was taken over — the way `last_measured.authority_blob` already pins the
     * identity of the class that answered — and this leg RE-DERIVES it from the vectors on
     * every run. That makes it the CHECK for a declaration the corpus already published and
     * nothing held: `last_measured.result` says in as many words that whoever adds a vector
     * re-takes the measurement over the whole corpus, *because nothing reds when they do
     * not*. Now something does, in this repo, in both directions — an added vector and a
     * DELETED one red alike.
     *
     * What it does NOT reach: kanban's end, which reds on nothing here (the corpus's
     * `not_checked_by_this_repo` owns that bound), and the truth of the measurement itself,
     * which stays a dated hand claim. This leg holds the claim to the population it was
     * made over; it cannot hold it to the world.
     */
    public function test_the_published_vectors_are_the_population_the_measurement_was_taken_over(): void
    {
        $corpus = self::corpus();

        $this->assertSame(
            $corpus['last_measured']['vectors_digest'] ?? null,
            self::vectorsDigest($corpus['vectors']),
            'the vectors of '.self::CORPUS.' are not the population its `last_measured` block was taken over ('.self::population($corpus['vectors']).'). A vector was added, changed or DELETED since that measurement — and deletion is the case the other arms cannot see at all, because a corpus cut to one vector per method passes both of them unchanged. Re-take the measurement over the WHOLE corpus against the kanban authority (`last_measured.method` says how), re-date the block, and write the actual digest below into `last_measured.vectors_digest`. A vector `note` is deliberately outside this digest: rewording prose is not a behavioural claim and must not demand a re-measurement.',
        );
    }

    /**
     * ⚠ THE CONTROL FOR THE COMPARATOR ITSELF. Every assertion above is built on
     * {@see disagreement} answering null, so a comparator that answered null unconditionally
     * would make the whole class green and vacuous. This feeds it one expectation known to be
     * wrong and one known to be right, off the SAME call.
     */
    public function test_the_comparator_tells_agreement_from_disagreement(): void
    {
        $this->assertNull(self::disagreement('canonicalizeSource', ['Octo/Web'], 'octo/web'));

        $said = self::disagreement('canonicalizeSource', ['Octo/Web'], 'Octo/Web');
        $this->assertIsString($said);
        $this->assertStringContainsString('octo/web', $said, 'a disagreement must report what the mirror actually answered, or a red run says only that something is wrong.');

        // A type difference is a disagreement: `null` and `''` are different answers about
        // whether a card is qualified, and a comparator using == would call them equal.
        $this->assertIsString(self::disagreement('canonicalizeSource', ['   '], ''));
    }

    /**
     * The corpus is also the DECLARATION the far end reads, so the legs that make it one are
     * asserted present. A published artifact that quietly lost the paragraph naming what the
     * bridge cannot verify would read as a complete contract.
     */
    public function test_the_corpus_declares_its_authority_and_names_what_this_repo_cannot_check(): void
    {
        $corpus = self::corpus();

        foreach (['repo', 'class', 'path', 'relationship'] as $key) {
            $this->assertNotEmpty($corpus['authority'][$key] ?? null, "the corpus does not say which class it mirrors ({$key}) — the far end cannot act on a contract that does not name them.");
        }

        $this->assertNotEmpty($corpus['how_the_far_end_runs_this'] ?? null);
        $this->assertNotSame([], $corpus['not_checked_by_this_repo'] ?? [], 'the corpus must NAME what this end cannot establish. Nothing here reds when kanban changes its own class, and a contract that does not say so hands the far end confidence instead of a question (canon #7).');

        foreach (['date', 'authority_ref', 'authority_commit', 'method', 'result', 'vectors_digest'] as $key) {
            $this->assertNotEmpty($corpus['last_measured'][$key] ?? null, "the corpus's cross-repo measurement is missing `{$key}`. An undated, unattributed agreement claim is the thing this card exists to remove.");
        }
    }

    /** The mirror's answer for one vector, or null when it agrees. */
    private static function disagreement(string $method, array $args, mixed $expect): ?string
    {
        $got = (new ExternalReferenceNormalizer)->{$method}(...$args);
        if ($got === $expect) {
            return null;
        }

        return 'args='.self::readable($args).' expected '.self::readable($expect).', mirror answered '.self::readable($got);
    }

    private static function readable(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES);
    }

    /**
     * The identity of the vector population: every vector's method, its position in that
     * method's list, and everything the corpus spells about it EXCEPT its `note`, one line
     * each, byte-sorted so the digest is a property of the vectors and not of the order the
     * blocks happen to sit in.
     *
     * @param  array<string, array<int, array<string, mixed>>>  $vectors
     */
    private static function vectorsDigest(array $vectors): string
    {
        $lines = [];
        foreach ($vectors as $method => $cases) {
            foreach ($cases as $i => $case) {
                unset($case['note']);
                $lines[] = $method.'#'.$i.' '.self::canonical($case);
            }
        }
        sort($lines);

        return 'sha256:'.hash('sha256', implode("\n", $lines));
    }

    /**
     * One value rendered so the digest is a property of the VECTOR and not of the host's
     * `serialize_precision` ini, which is what `json_encode` renders a float through — this
     * corpus carries four floats, so a digest taken that way would red on a differently
     * configured box and say "the corpus moved".
     */
    private static function canonical(mixed $value): string
    {
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $key => $item) {
                $parts[] = self::canonical((string) $key).':'.self::canonical($item);
            }

            return '['.implode(',', $parts).']';
        }

        return match (true) {
            is_float($value) => sprintf('%.17G', $value),
            is_int($value) => (string) $value,
            is_bool($value) => $value ? 'true' : 'false',
            is_string($value) => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            default => 'null',
        };
    }

    /**
     * The denominator, derived from the corpus in hand rather than written down anywhere.
     *
     * @param  array<string, array<int, mixed>>  $vectors
     */
    private static function population(array $vectors): string
    {
        $per = [];
        $total = 0;
        foreach ($vectors as $method => $cases) {
            $per[] = $method.' '.count($cases);
            $total += count($cases);
        }
        sort($per);

        return "the corpus in hand carries {$total} vectors over ".count($vectors).' methods: '.implode(', ', $per);
    }

    /** @return array<string, mixed> */
    private static function corpus(): array
    {
        $path = dirname(__DIR__, 3).'/'.self::CORPUS;
        $raw = file_get_contents($path);
        self::assertIsString($raw, self::CORPUS.' is unreadable.');

        $doc = json_decode($raw, true);
        self::assertIsArray($doc, self::CORPUS.' is not parseable JSON — it is a PUBLISHED artifact, so a consumer at the far end reads exactly this file.');

        // ⚠ THE PRESENCE WITNESS, AT THE SHAPE RATHER THAN AT ONE VALUE. A corpus missing
        // these blocks used to leave `$doc['vectors']` as null, which satisfies every loop
        // below `assertNotSame([], …)` and reds only by an incidental TypeError nobody wrote.
        foreach (['vectors', 'constants'] as $block) {
            self::assertIsArray($doc[$block] ?? null, self::CORPUS." carries no `{$block}` block at all — the corpus, not the mirror, is what changed.");
        }

        self::assertSame(self::CORPUS, $doc['mirror']['corpus'] ?? null, 'the corpus\'s `mirror.corpus` is not this file\'s own repo-relative path, so the far end that downloaded it is told nothing about where it came from, or is told the wrong place.');

        return $doc;
    }

    /** @return list<string> */
    private static function publicMethods(): array
    {
        $names = [];
        foreach ((new ReflectionClass(ExternalReferenceNormalizer::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() === ExternalReferenceNormalizer::class) {
                $names[] = $method->getName();
            }
        }

        return self::sorted($names);
    }

    /** @return array<string, mixed> */
    private static function classConstants(): array
    {
        return (new ReflectionClass(ExternalReferenceNormalizer::class))->getConstants();
    }

    /**
     * @template T of array
     *
     * @param  T  $values
     * @return T
     */
    private static function sorted(array $values): array
    {
        if (array_is_list($values)) {
            sort($values);

            return $values;
        }

        ksort($values);

        return $values;
    }
}
