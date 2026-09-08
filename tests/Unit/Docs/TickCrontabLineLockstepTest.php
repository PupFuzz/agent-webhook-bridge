<?php

namespace Tests\Unit\Docs;

use App\Bridge\Scheduling\TickAdoptionNotice;
use App\Bridge\Scheduling\TickPosture;
use Tests\TestCase;

/**
 * The `bridge:tick` crontab line has ONE owner, and the copies are guarded (card#9058 / DL-361).
 *
 * ⭐ THE DUPLICATION IS WHAT LET THE DEFECTS SURVIVE, so removing them without a guard would
 * leave the mechanism that minted them. The line stood, by hand, in FIVE places —
 * `docs/periodic-jobs.md`, `CLAUDE_DEPLOYMENT.md`, `.env.example`, `TickCommand`'s docblock and a
 * released `docs/CHANGELOG.md` entry — and BOTH of its defects were in every one of them: a bare
 * `php` that assumes cron's minimal `PATH`, and a `>>` redirect growing a `tick.log` that nothing
 * in this repo rotates. Four copies are now DELETED and point at the owner; the owner's template
 * is held in LOCKSTEP with the renderer that emits the real thing.
 *
 * ⭐ THE PREDICATE IS A CENSUS, NOT A LIST. A test naming the four files it just fixed would go
 * quiet the moment a fifth surface started restating the line — which is exactly how this
 * population grew to five in the first place. So it is derived on every run: NO git-tracked file
 * may carry a crontab-shaped line running `bridge:tick`, except the owner doc. A new copy joins
 * the denominator by existing.
 *
 * ⚠ WHAT IT CANNOT CATCH, stated so a green run is not read as more than it is. The predicate
 * keys on a CRONTAB-SHAPED line — five fields whose last four are `*` — so prose describing the
 * line without writing one ("run `bridge:tick` from cron every ten minutes") passes, and so does
 * a copy that wraps the line across two source lines. It catches a RESTATED LINE, not a restated
 * idea.
 */
class TickCrontabLineLockstepTest extends TestCase
{
    /** The one file allowed to carry the template. */
    private const OWNER = 'docs/periodic-jobs.md';

    /**
     * Paths whose copy is HISTORY rather than a restatement: a released changelog entry records
     * what shipped at a point in time, and rewriting it to match a later line would falsify the
     * record. `CLAUDE_DECISIONS.md` is append-only for the same reason.
     */
    private const HISTORY = [
        'CLAUDE_DECISIONS.md',
        'docs/CHANGELOG.md',
    ];

    /**
     * ⚑ `tests/` IS OUT OF THE CENSUS, and the reason is what the copies there ARE. A test that
     * writes the line writes it as an ASSERTION against the renderer — including this file's own
     * near-miss fixtures — so it is a guard on the line, not a restatement an operator could
     * follow and act on. A drifted assertion reds by failing, which is the mechanism the census
     * is standing in for everywhere else. ⚠ The hole this leaves, stated: a doc-shaped FIXTURE
     * under `tests/` could carry a stale line and pass here.
     */
    private const EXCLUDED_PREFIX = 'tests/';

    /** The placeholders the OWNER's template uses in place of a real install's values. */
    private const TEMPLATE_BASE = '/path/to/bridge';

    private const TEMPLATE_PHP = '/path/to/php';

    /**
     * THE PREDICATE. A crontab-shaped line — anything, then four whitespace-separated `*`
     * fields, then a command — that runs `bridge:tick`.
     *
     * @return list<string>
     */
    private static function crontabLinesFor(string $contents): array
    {
        $hits = [];
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            if (preg_match('/\*\s+\*\s+\*\s+\*\s/', $line) === 1 && str_contains($line, 'bridge:tick')) {
                $hits[] = trim($line);
            }
        }

        return $hits;
    }

    /** The template the OWNER doc must carry, rendered by the class that owns the real one. */
    private static function template(): string
    {
        return (string) (new TickAdoptionNotice(
            posture: TickPosture::resolve(null, null),
            basePath: self::TEMPLATE_BASE,
            phpBinary: self::TEMPLATE_PHP,
        ))->crontabLine();
    }

    /**
     * Every git-tracked file, repo-relative. `git ls-files` IS the exclusion of `vendor/` and
     * friends — they are gitignored, so no second list of them is kept here to drift.
     *
     * @return list<string>
     */
    private function trackedFiles(): array
    {
        $out = (string) shell_exec('git -C '.escapeshellarg(base_path()).' ls-files -z 2>/dev/null');
        $files = array_values(array_filter(explode("\0", $out), static fn ($p) => $p !== ''));

        // An empty census is a measurement that did not happen, never a clean result.
        $this->assertNotEmpty($files, 'git ls-files returned nothing for '.base_path().' — this guard did not run');

        return $files;
    }

    public function test_the_owner_doc_carries_exactly_the_line_the_renderer_emits(): void
    {
        // ⛔ THE LOCKSTEP. The doc's template and the emitted line are the same string with the
        // install's own values substituted, so the doc cannot describe a line the command no
        // longer prints — which is the drift that put a bare `php` and a `>>` in five places.
        $lines = self::crontabLinesFor((string) file_get_contents(base_path(self::OWNER)));

        $this->assertSame([self::template()], $lines, self::OWNER.' must carry exactly the template '
            .'App\Bridge\Scheduling\TickAdoptionNotice renders, and nothing else crontab-shaped for bridge:tick');

        // ⚑ AND THE HORIZON BESIDE IT, for the same reason one step further on. The doc tells
        // the reader to declare a number that must match the template's CADENCE; a `600` left
        // beside a template that moved to five minutes arms the freshness alarm twice as wide as
        // the line runs, so a dead tick reads fresh. The renderer derives both from one constant
        // — this is the doc's copy held against that derivation.
        $this->assertStringContainsString(
            'BRIDGE_JOBS_TICK_EXPECTED_EVERY='.TickAdoptionNotice::horizonS(),
            (string) file_get_contents(base_path(self::OWNER)),
        );
    }

    public function test_no_other_tracked_file_restates_the_crontab_line(): void
    {
        $offenders = [];

        foreach ($this->trackedFiles() as $rel) {
            if ($rel === self::OWNER || in_array($rel, self::HISTORY, true)
                || str_starts_with($rel, self::EXCLUDED_PREFIX)) {
                continue;
            }
            $path = base_path($rel);
            if (! is_file($path) || filesize($path) > 2_000_000) {
                continue;
            }
            if (self::crontabLinesFor((string) file_get_contents($path)) !== []) {
                $offenders[] = $rel;
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'These files restate the bridge:tick crontab line:',
            '  '.implode("\n  ", $offenders),
            self::OWNER.' § Adopting the tick owns the template, and `php artisan bridge:provision-tools`',
            'prints the real one for the install in front of the reader. Point at those instead of',
            'copying the line — every copy carried both of the defects DL-361 fixed.',
        ]));
    }

    /**
     * THE CONTROL (canon #9). Both assertions above are only evidence if the predicate can say
     * yes and no — and this file is the natural place to get that wrong, because a predicate
     * that matched nothing would pass the census vacuously AND report a clean repo.
     */
    public function test_the_predicate_discriminates(): void
    {
        // Positives: the owner's own template, and the plausible restatements this guard exists
        // to catch — the old bare-`php` form, the old appending form, another schedule.
        foreach ([
            'the owner template' => self::template(),
            'the pre-DL-361 form' => '0,10,20,30,40,50 * * * * cd /x && php artisan bridge:tick >> /x/t.log 2>&1',
            'an indented copy' => '  */5 * * * * cd /x && php artisan bridge:tick',
            'another cadence' => '@reboot ignored'."\n".'7 * * * * /usr/bin/php /x/artisan bridge:tick',
        ] as $why => $fixture) {
            $this->assertNotSame([], self::crontabLinesFor($fixture), "the predicate missed a copy ({$why})");
        }

        // Negatives, pinned so the guard is not passing by matching everything: a crontab line
        // for a DIFFERENT command (docs/writeback.md's reconcile example is a live one), and
        // prose that names the command without writing a line.
        foreach ([
            'a crontab line for another command' => '17 * * * *  cd /x && php artisan bridge:reconcile >> "$HOME/r.log" 2>&1',
            'prose about the tick' => 'add one crontab line running `php artisan bridge:tick` every ten minutes',
            'the command with no schedule' => 'php artisan bridge:tick',
        ] as $why => $fixture) {
            $this->assertSame([], self::crontabLinesFor($fixture), "the predicate matched a non-copy ({$why})");
        }
    }
}
