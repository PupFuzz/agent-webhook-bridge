<?php

namespace Tests\Unit\Support;

use App\Bridge\Support\ExternalReferenceNormalizer;
use Tests\Support\MirrorParityTestCase;

/**
 * THE CHECK BEHIND THE BRIDGE'S MIRROR OF KANBAN'S NORMALIZATION RULE (card#9936).
 *
 * `App\Bridge\Support\ExternalReferenceNormalizer` is a VENDORED copy of kanban-board's
 * `App\Services\ExternalReferenceNormalizer`. The bridge is a separate repo and runtime and cannot
 * import that class, so it mirrors it — and every consumer of the mirror's answers is relying on a
 * rule the bridge does not own. `board_my_cards` is the sharpest case: its `source` field is
 * computed HERE, not read from the server (`tasks/search.json` does not return the stored qualifier
 * at all), so a seat on a shared board reads `source: owner/a`, concludes a merge in `a` will move
 * its card, and is wrong if the mirror has drifted.
 *
 * ⛔ WHY THE DOCBLOCK WAS NOT ENOUGH is the argument of the card, and it is stated ONCE for every
 * mirror in {@see MirrorParityTestCase}, which owns every leg below. These two authorities have
 * already drifted in production (kanban DL-251 / bridge DL-309) and nothing reported it.
 *
 * THE CONSTANTS this corpus pins are what the mirrored methods are parametrised by — a system slug,
 * the payload-key map, the URL key preference order, the ref cap.
 *
 * ⚠ THE FAR END HERE RUNS PHP, so there is no runner to ship: `how_the_far_end_runs_this` tells a
 * kanban maintainer to construct their own class and loop the vectors, which is a handful of lines
 * in their own suite. The two COORD mirrors are the other shape — their authority is Python, so
 * they publish `bin/coord-mirror-parity.py` and the corpus names it in `mirror.runner`.
 */
class ExternalReferenceNormalizerTest extends MirrorParityTestCase
{
    protected static function mirrorClass(): string
    {
        return ExternalReferenceNormalizer::class;
    }

    protected static function corpusPath(): string
    {
        return 'docs/external-reference-parity-corpus.json';
    }

    protected static function comparatorControl(): array
    {
        return [
            'method' => 'canonicalizeSource',
            'args' => ['Octo/Web'],
            'right' => 'octo/web',
            'wrong' => 'Octo/Web',
            'wrong_fragment' => 'octo/web',
        ];
    }

    /**
     * A TYPE difference is a disagreement. `null` and `''` are different answers about whether a
     * card is qualified, and a comparator using `==` would call them equal — a leg the shared
     * control cannot carry, because it is a property of THIS mirror's return type.
     */
    public function test_a_type_difference_is_a_disagreement(): void
    {
        $this->assertIsString(static::disagreement('canonicalizeSource', ['   '], ''));
    }
}
