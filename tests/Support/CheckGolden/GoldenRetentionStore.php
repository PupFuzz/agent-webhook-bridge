<?php

namespace Tests\Support\CheckGolden;

use App\Bridge\Retention\RetentionFootprint;
use App\Bridge\Retention\RetentionStoreProbe;
use RuntimeException;

/**
 * The pinned answer to *what is the retention store holding* that the golden fixtures run
 * against (card#8374).
 *
 * PINNED, NOT NORMALIZED, for the reason {@see PinnedHost} states for its four env reads:
 * the subject is the storage ENGINE, and the suite runs against SQLite in-memory AND each
 * MariaDB in the CI matrix. A live read prints a different database size on every job, so
 * a golden capture would answer for the runner rather than for the fixture — and unlike a
 * path or a uid, a size ENCODES THE VERDICT this line exists to deliver, so normalizing it
 * away is forbidden by the same rule.
 *
 * PARAMETERISED, like {@see GoldenSshEnvironment} and unlike {@see GoldenChannelEnvironment}:
 * the cost line has four operator-distinguishable shapes (a loaded store, an empty one, one
 * whose oldest row has outlived its delete window, and a store that could not be read at
 * all), and each is a fixture rather than a variation the corpus can do without.
 *
 * {@see loaded()} IS THE DEFAULT ON EVERY FIXTURE, and its numbers are the ones from the
 * incident that produced the card — 894 MiB of payload inside a 1.2 GiB store — so the
 * corpus carries the line that install never got, on every shape that prints a posture.
 */
final class GoldenRetentionStore implements RetentionStoreProbe
{
    private function __construct(
        private readonly ?RetentionFootprint $footprint,
        private readonly ?string $failure,
    ) {}

    /** The measured incident shape: a big store whose payloads are most of it. */
    public static function loaded(): self
    {
        return self::holding(new RetentionFootprint(
            rows: 12345,
            rowsWithPayload: 11987,
            payloadBytes: 937426944,
            storeBytes: 1288490188,
            oldestRowAgeDays: 12.4,
        ));
    }

    /**
     * The control the card asks for: a store with nothing in it. The database still has a
     * size — its own schema — which is exactly why the empty arm may not be inferred from
     * a size of zero.
     */
    public static function drained(): self
    {
        return self::holding(new RetentionFootprint(
            rows: 0,
            rowsWithPayload: 0,
            payloadBytes: 0,
            storeBytes: 4194304,
            oldestRowAgeDays: null,
        ));
    }

    public static function holding(RetentionFootprint $footprint): self
    {
        return new self($footprint, null);
    }

    /**
     * A store that could not be measured. The message is deliberately not a plausible
     * driver error: if it ever surfaces outside the fixture that asks for it, it should
     * read as a pin rather than as a real database's diagnosis.
     */
    public static function unreadable(): self
    {
        return new self(null, 'pinned by the golden harness: the store was not measurable');
    }

    public function measure(): RetentionFootprint
    {
        if ($this->footprint === null) {
            throw new RuntimeException((string) $this->failure);
        }

        return $this->footprint;
    }
}
