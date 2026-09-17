<?php

namespace Tests\Unit\Docs;

/**
 * The first bridge release carrying `bin/seat-pack.py`, wherever this tree STATES it for an
 * operator, held against the release history that decides it.
 *
 * The floor is written as a figure because the reader who needs it is choosing a tag, and
 * a pointer would send them to the history to work it out. The figure is written before
 * the release exists, so it is a prediction until the fold. The fold is what can make it
 * false, by giving the release a different number.
 *
 * ⭐ THE "CONSTANT" IS DERIVED, not a pin: {@see expectedFloor()} is the oldest `## [x.y.z]`
 * section of `docs/CHANGELOG.md` that mentions `seat-pack.py`. Before any section does, the only
 * release that can carry it is the next minor after `VERSION` (VERSIONING.md § Bump sizing: a
 * new user-visible tool is a minor).
 *
 * ⭐ THE MECHANISM IS {@see DocFigureLockstepTestCase}'s — the tracked-file population re-derived
 * on every run, the HISTORY and excluded-prefix bounds, and the presence witness. Its docblock
 * owns that reasoning; only the subject-specific parts are here.
 */
class SeatPackFloorTest extends DocFigureLockstepTestCase
{
    protected function marker(): string
    {
        return '/`v([0-9]+\.[0-9]+\.[0-9]+)` is the first release carrying `bin\/seat-pack\.py`/';
    }

    protected function houseSpelling(): string
    {
        return '"`v<version>` is the first release carrying `bin/seat-pack.py`"';
    }

    protected function constantName(): string
    {
        return 'the oldest docs/CHANGELOG.md release section mentioning seat-pack.py (the next minor after VERSION while none does)';
    }

    protected function constantValue(): string
    {
        return self::expectedFloor(
            (string) file_get_contents(base_path('docs/CHANGELOG.md')),
            (string) file_get_contents(base_path('VERSION')),
        );
    }

    protected function subject(): string
    {
        return 'the first release carrying bin/seat-pack.py';
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

    public function test_the_predicate_discriminates(): void
    {
        $this->assertSame(['0.86.0'], $this->figuresIn('`v0.86.0` is the first release carrying `bin/seat-pack.py`.'));
        // The sentence naming the threshold with no figure is invisible to the census (bound (a)).
        $this->assertSame([], $this->figuresIn('the first release carrying `bin/seat-pack.py`'));
        // An unbackticked version is not the house spelling.
        $this->assertSame([], $this->figuresIn('v0.86.0 is the first release carrying bin/seat-pack.py'));
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
    }
}
