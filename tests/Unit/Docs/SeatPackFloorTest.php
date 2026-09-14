<?php

namespace Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;

/**
 * The first bridge release carrying `bin/seat-pack.py`, as `docs/seat-tools.md` states it, held
 * against the release history that decides it.
 *
 * The floor is written as a figure because the reader who needs it is choosing a tag, and
 * a pointer would send them to the history to work it out. The figure is written before
 * the release exists, so it is a prediction until the fold. The fold is what can make it
 * false, by giving the release a different number. So the figure is checked against the
 * one fact that settles it: the oldest `## [x.y.z]` section of `docs/CHANGELOG.md` that
 * mentions `seat-pack.py`. Before any section does, the only release that can carry it is
 * the next minor after `VERSION` (VERSIONING.md § Bump sizing: a new user-visible tool is
 * a minor).
 */
class SeatPackFloorTest extends TestCase
{
    private const MARKER = '/`v([0-9]+\.[0-9]+\.[0-9]+)` is the first release carrying `bin\/seat-pack\.py`/';

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return list<string> */
    public static function floorsIn(string $text): array
    {
        preg_match_all(self::MARKER, $text, $m);

        return $m[1];
    }

    /** The oldest released section mentioning seat-pack.py, or null while none does. */
    public static function firstReleaseCarrying(string $changelog): ?string
    {
        $parts = preg_split('/^## \[([0-9]+\.[0-9]+\.[0-9]+)\][^\n]*$/m', $changelog, -1, PREG_SPLIT_DELIM_CAPTURE);
        $first = null;
        for ($i = 1; $i + 1 < count($parts); $i += 2) {
            if (str_contains($parts[$i + 1], 'seat-pack.py')) {
                $first = $parts[$i];
            }
        }

        return $first;
    }

    public static function expectedFloor(string $changelog, string $version): string
    {
        $released = self::firstReleaseCarrying($changelog);
        if ($released !== null) {
            return $released;
        }
        [$major, $minor] = array_map('intval', explode('.', trim($version)));

        return $major.'.'.($minor + 1).'.0';
    }

    public function test_the_stated_floor_is_the_release_that_carries_seat_pack(): void
    {
        $floors = self::floorsIn((string) file_get_contents(self::root().'/docs/seat-tools.md'));
        $this->assertCount(1, $floors, 'docs/seat-tools.md must state the floor exactly once');

        $expected = self::expectedFloor(
            (string) file_get_contents(self::root().'/docs/CHANGELOG.md'),
            (string) file_get_contents(self::root().'/VERSION'),
        );
        $this->assertSame($expected, $floors[0], 'docs/seat-tools.md names the wrong first release carrying bin/seat-pack.py');
    }

    public function test_the_derivation_discriminates(): void
    {
        $history = "# Changelog\n\n## [Unreleased]\n\n- adds seat-pack.py\n\n## [0.85.0] - 2026-09-12\n\n- other\n";
        $this->assertNull(self::firstReleaseCarrying($history));
        $this->assertSame('0.86.0', self::expectedFloor($history, "0.85.0\n"));

        // Folded as a PATCH: the floor is the patch, and a doc still saying v0.86.0 reds.
        $folded = "# Changelog\n\n## [Unreleased]\n\n## [0.85.1] - 2026-09-20\n\n- adds seat-pack.py\n\n## [0.85.0] - 2026-09-12\n\n- other\n";
        $this->assertSame('0.85.1', self::expectedFloor($folded, "0.85.1\n"));

        // A later section mentioning it again does not move the floor.
        $later = "## [0.87.0] - x\n\n- seat-pack.py fix\n\n## [0.86.0] - y\n\n- adds seat-pack.py\n\n## [0.85.0] - z\n";
        $this->assertSame('0.86.0', self::firstReleaseCarrying($later));

        $this->assertSame(['0.86.0'], self::floorsIn('`v0.86.0` is the first release carrying `bin/seat-pack.py`.'));
        $this->assertSame([], self::floorsIn('the first release carrying `bin/seat-pack.py`'));
    }
}
