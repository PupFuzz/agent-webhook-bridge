<?php

namespace Tests\Unit\Docs;

use App\Bridge\Scheduling\TickAdoptionNotice;
use App\Bridge\Scheduling\TickPosture;
use Tests\TestCase;

/**
 * Every PASTEABLE CRONTAB LINE this repository ships, censused (card#9058 / DL-361).
 *
 * ⭐ THE DUPLICATION IS WHAT LET THE DEFECTS SURVIVE, so removing them without a guard would
 * leave the mechanism that minted them. The `bridge:tick` line stood, by hand, in FIVE places —
 * `docs/periodic-jobs.md`, `CLAUDE_DEPLOYMENT.md`, `.env.example`, `TickCommand`'s docblock and a
 * released `docs/CHANGELOG.md` entry — and BOTH of its defects were in every one of them: a bare
 * `php` that assumes cron's minimal `PATH`, and a `>>` redirect growing a `tick.log` that nothing
 * in this repo rotates. Four copies are now DELETED and point at the owner; the owner's template
 * is held in LOCKSTEP with the renderer that emits the real thing.
 *
 * ⭐ AND THE REDIRECT RULE IS A CLASS, NOT A PROPERTY OF THE TICK. DL-361 Decision 5 ruled that an
 * appended log with nothing to rotate it is a defect, and explicitly rejected *"keep `>>` and ship
 * a logrotate stanza"*. That reasoning is about the LOG, not about which command writes it — and
 * the round that ruled it left `docs/writeback.md`'s `bridge:reconcile` example appending 25 lines
 * a day to an unrotated file. So the guard is over the CLASS: no pasteable crontab line in a
 * tracked file may append. One guard, both instances, and every future one — a guard scoped to the
 * instance that produced it is the same defect as a doc copy scoped to the surface that produced
 * it.
 *
 * ⭐ THE PREDICATE IS A CENSUS, NOT A LIST. A test naming the files it just fixed would go quiet
 * the moment a new surface started restating a line — which is exactly how the tick population
 * grew to five in the first place. So it is derived on every run from `git ls-files`: a new copy
 * joins the denominator by existing.
 *
 * ⚠ WHAT IT CANNOT CATCH, stated so a green run is not read as more than it is. The predicate
 * keys on a crontab SCHEDULE — five fields, each valid for its own position, at least one of them
 * containing a `*` — followed by a command on the same source line. So (a) prose describing a line
 * without writing one ("run `bridge:tick` from cron every ten minutes") passes, and so does a copy
 * wrapped across two source lines: it catches a restated LINE, not a restated idea; (b) an
 * all-numeric schedule (`0 3 1 1 1`) is not matched — the `*` requirement is what keeps prose and
 * PHP arithmetic (`60 * 1024 * 1024,`) out of the census, and a starless schedule is the price;
 * and (c) `@daily` / `@reboot` shorthands are not schedules here at all.
 * {@see self::test_the_predicate_discriminates()} pins both directions.
 */
class CrontabLineCensusTest extends TestCase
{
    /** The one file allowed to carry the `bridge:tick` template. */
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
     * writes a line writes it as an ASSERTION against the renderer — including this file's own
     * near-miss fixtures — so it is a guard on the line, not a restatement an operator could
     * follow and act on. A drifted assertion reds by failing, which is the mechanism the census
     * is standing in for everywhere else. ⚠ The hole this leaves, stated: a doc-shaped FIXTURE
     * under `tests/` could carry a stale line and pass here.
     */
    private const EXCLUDED_PREFIX = 'tests/';

    /** The placeholders the OWNER's template uses in place of a real install's values. */
    private const TEMPLATE_BASE = '/path/to/bridge';

    private const TEMPLATE_PHP = '/path/to/php';

    /** One crontab field: numbers, ranges, steps, lists and `*`. */
    private const FIELD = '[-0-9*/,]+';

    /**
     * The inclusive bounds of each of the five schedule fields, in order — minute, hour,
     * day-of-month, month, day-of-week. ⭐ THIS IS WHAT MAKES THE PREDICATE MEAN *crontab*. A
     * shape-only match ("five space-separated tokens of digits and stars") reads a markdown card
     * list (`8336, 8375, 8351, 8286, 8306,`) and a PHP expression (`60 * 1024 * 1024,`) as
     * schedules — both are live in this repo — and a census that reports those is a census
     * somebody switches off.
     */
    private const FIELD_RANGES = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 7]];

    // ─── the predicate ────────────────────────────────────────────────────────

    /**
     * THE PREDICATE. Every source line carrying a real crontab SCHEDULE followed by a command.
     *
     * @return list<string>
     */
    private static function crontabLinesIn(string $contents): array
    {
        $pattern = '~(?:^|[\s`\'"(])('.self::FIELD.'(?:\s+'.self::FIELD.'){4})\s+\S~';

        $hits = [];
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            if (preg_match_all($pattern, $line, $matches) < 1) {
                continue;
            }
            foreach ($matches[1] as $candidate) {
                if (self::isSchedule($candidate)) {
                    $hits[] = trim($line);
                    break;
                }
            }
        }

        return $hits;
    }

    /**
     * Whether five whitespace-separated tokens are a crontab schedule — field by field, against
     * that field's own range, with at least one `*` somewhere in it.
     */
    private static function isSchedule(string $candidate): bool
    {
        $fields = preg_split('/\s+/', trim($candidate)) ?: [];

        if (count($fields) !== count(self::FIELD_RANGES)) {
            return false;
        }

        $sawStar = false;

        foreach ($fields as $i => $field) {
            [$low, $high] = self::FIELD_RANGES[$i];

            foreach (explode(',', $field) as $item) {
                $parts = explode('/', $item);

                // `*/5`, `0-30/5` — one step at most, and it is a number.
                if (count($parts) > 2 || ($parts[1] ?? '1') === '' || ! ctype_digit($parts[1] ?? '1')) {
                    return false;
                }

                if ($parts[0] === '*') {
                    $sawStar = true;

                    continue;
                }

                $bounds = explode('-', $parts[0]);

                if (count($bounds) > 2) {
                    return false;
                }

                foreach ($bounds as $number) {
                    if ($number === '' || ! ctype_digit($number)
                        || (int) $number < $low || (int) $number > $high) {
                        return false;
                    }
                }
            }
        }

        return $sawStar;
    }

    /**
     * Every crontab line running `bridge:tick`.
     *
     * @return list<string>
     */
    private static function tickLinesIn(string $contents): array
    {
        return array_values(array_filter(
            self::crontabLinesIn($contents),
            static fn (string $line) => str_contains($line, 'bridge:tick'),
        ));
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
     * Every git-tracked file this census may judge, repo-relative — history, `tests/` and
     * anything unreadable or huge already removed. `git ls-files` IS the exclusion of `vendor/`
     * and friends: they are gitignored, so no second list of them is kept here to drift.
     *
     * @return list<string>
     */
    private function censusedFiles(): array
    {
        $out = (string) shell_exec('git -C '.escapeshellarg(base_path()).' ls-files -z 2>/dev/null');
        $tracked = array_values(array_filter(explode("\0", $out), static fn ($p) => $p !== ''));

        // An empty census is a measurement that did not happen, never a clean result.
        $this->assertNotEmpty($tracked, 'git ls-files returned nothing for '.base_path().' — this guard did not run');

        $censused = [];
        foreach ($tracked as $rel) {
            if (in_array($rel, self::HISTORY, true) || str_starts_with($rel, self::EXCLUDED_PREFIX)) {
                continue;
            }
            $path = base_path($rel);
            if (! is_file($path) || filesize($path) > 2_000_000) {
                continue;
            }
            $censused[] = $rel;
        }

        return $censused;
    }

    // ─── the tick line has ONE owner ──────────────────────────────────────────

    public function test_the_owner_doc_carries_exactly_the_line_the_renderer_emits(): void
    {
        // ⛔ THE LOCKSTEP. The doc's template and the emitted line are the same string with the
        // install's own values substituted, so the doc cannot describe a line the command no
        // longer prints — which is the drift that put a bare `php` and a `>>` in five places.
        $lines = self::tickLinesIn((string) file_get_contents(base_path(self::OWNER)));

        $this->assertSame([self::template()], $lines, self::OWNER.' must carry exactly the template '
            .'App\Bridge\Scheduling\TickAdoptionNotice renders, and nothing else crontab-shaped for bridge:tick');
    }

    public function test_no_other_tracked_file_restates_the_crontab_line(): void
    {
        $offenders = [];

        foreach ($this->censusedFiles() as $rel) {
            if ($rel === self::OWNER) {
                continue;
            }
            if (self::tickLinesIn((string) file_get_contents(base_path($rel))) !== []) {
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
     * ⭐ AND THE HORIZON FIGURE WITH IT — the same census, one step further on.
     *
     * ⛔ THIS IS THE LEG THE FIRST ROUND DID NOT HAVE, AND ITS ABSENCE RE-MINTED THE DEFECT. The
     * renderer derives the schedule AND the horizon from one constant precisely so a hand-typed
     * `600` cannot disagree with a hand-typed `0,10,20,…`; but the guard covered the LINE only, so
     * the round that deleted four copies of the line left two hand-typed `600`s standing and
     * AUTHORED a third. Set `EVERY_MINUTES = 5` and an operator following either copy declares a
     * 600s horizon against a 300s line — `TickPosture::graceS()` then fires staleness at 1260s
     * instead of 660s, so a DEAD TICK READS FRESH for twenty-one minutes instead of eleven. The
     * figure is now owned exactly where the line is: rendered by the notice, carried once by the
     * owner doc, and pointed at from everywhere else.
     */
    public function test_no_tracked_file_hand_types_the_horizon_figure(): void
    {
        $offenders = [];

        foreach ($this->censusedFiles() as $rel) {
            $contents = (string) file_get_contents(base_path($rel));
            if (preg_match_all('/BRIDGE_JOBS_TICK_EXPECTED_EVERY=(\d+)/', $contents, $matches) < 1) {
                continue;
            }
            foreach ($matches[0] as $hit) {
                // The OWNER doc is the one copy allowed to carry the figure — and only the
                // figure the renderer actually derives.
                if ($rel === self::OWNER && $hit === 'BRIDGE_JOBS_TICK_EXPECTED_EVERY='.TickAdoptionNotice::horizonS()) {
                    continue;
                }
                $offenders[] = $rel.': '.$hit;
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'These files hand-type the tick horizon figure:',
            '  '.implode("\n  ", $offenders),
            'It is DERIVED from TickAdoptionNotice::EVERY_MINUTES, the same constant the offered',
            'schedule comes off, so a copy is a second number free to disagree with the line —',
            'and a horizon wider than the line arms the freshness alarm to read a dead tick as',
            'fresh. '.self::OWNER.' § Adopting the tick is the one copy; point at it.',
        ]));
    }

    public function test_the_owner_doc_carries_the_horizon_the_renderer_derives(): void
    {
        // The other direction of the leg above: the owner is ALLOWED the figure, so something
        // must assert it is the right one, or deleting it everywhere would pass vacuously.
        $this->assertStringContainsString(
            'BRIDGE_JOBS_TICK_EXPECTED_EVERY='.TickAdoptionNotice::horizonS(),
            (string) file_get_contents(base_path(self::OWNER)),
        );
    }

    // ─── the class: a pasteable line never appends to an unrotated log ────────

    /**
     * ⭐ THE CLASS, NOT THE INSTANCE (DL-361 Decision 5, ruled repo-wide). An appended log with
     * nothing to rotate it grows without bound — that is a property of the LOG, and it does not
     * become acceptable because a different command writes it. `>` keeps the last run and needs
     * no rotation at all; an operator who wants the history is told, on each surface, to use `>>`
     * and rotate it themselves. There is no logrotate stanza anywhere in this repository, which
     * is the fact that makes the ruling bite.
     */
    public function test_no_pasteable_crontab_line_appends_to_a_log(): void
    {
        $offenders = [];

        foreach ($this->censusedFiles() as $rel) {
            foreach (self::crontabLinesIn((string) file_get_contents(base_path($rel))) as $line) {
                if (str_contains($line, '>>')) {
                    $offenders[] = $rel.': '.$line;
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'These pasteable crontab lines APPEND to a log nothing rotates:',
            '  '.implode("\n  ", $offenders),
            'DL-361 Decision 5 is repo-wide: `>` keeps the last run and needs no rotation, the',
            'durable account of what ran is the registry row / the app log / the exit code, and',
            '"keep >> and ship a logrotate stanza" was considered and rejected. An operator who',
            'wants the history is told to use >> and rotate it themselves — in prose, beside the',
            'line, not in the line this repo hands them.',
        ]));
    }

    // ─── the control ──────────────────────────────────────────────────────────

    /**
     * THE CONTROL (canon #9). Every assertion above is only evidence if the predicate can say
     * yes AND no — and this file is the natural place to get that wrong, because a predicate that
     * matched nothing would pass every census vacuously AND report a clean repo.
     */
    public function test_the_predicate_discriminates(): void
    {
        // Positives: the owner's own template, the plausible restatements the tick census exists
        // to catch, and the two shapes the CLASS guard exists to catch — including the daily
        // `23 4 * * *` form, which a shape-only "four bare stars" predicate misses entirely.
        foreach ([
            'the owner template' => self::template(),
            'the pre-DL-361 form' => '0,10,20,30,40,50 * * * * cd /x && php artisan bridge:tick >> /x/t.log 2>&1',
            'an indented copy' => '  */5 * * * * cd /x && php artisan bridge:tick',
            'another cadence' => '@reboot ignored'."\n".'7 * * * * /usr/bin/php /x/artisan bridge:tick',
            'an hourly line for another command' => '17 * * * *  cd /x && php artisan bridge:reconcile > /x/r.log 2>&1',
            'a daily line whose first two fields are numbers' => '23 4 * * *  cd /x && php artisan bridge:reconcile --fix > /x/r.log 2>&1',
            'a range and a step' => '0-30/5 2 1 * * /usr/bin/php /x/artisan bridge:reconcile',
        ] as $why => $fixture) {
            $this->assertNotSame([], self::crontabLinesIn($fixture), "the predicate missed a crontab line ({$why})");
        }

        // Negatives, pinned so the guard is not passing by matching everything — including the
        // two live shapes in this repo that a shape-only predicate reads as schedules.
        foreach ([
            'prose about the tick' => 'add one crontab line running `php artisan bridge:tick` every ten minutes',
            'the command with no schedule' => 'php artisan bridge:tick',
            'a markdown list of card ids' => '- **8336, 8375, 8351, 8286, 8306, 8286** — the cards in this release',
            'PHP arithmetic' => '$this->assertGreaterThan(60 * 1024 * 1024, $bytes, "big enough to matter");',
            'a minute field out of range' => '99 * * * * php artisan bridge:tick',
            'four fields, not five' => '* * * * php artisan bridge:tick',
        ] as $why => $fixture) {
            $this->assertSame([], self::crontabLinesIn($fixture), "the predicate matched a non-line ({$why})");
        }

        // And the tick filter over it says yes and no as well, or the tick census and the class
        // census would be the same test twice.
        $reconcile = '17 * * * *  cd /x && php artisan bridge:reconcile >> /x/r.log 2>&1';
        $this->assertNotSame([], self::crontabLinesIn($reconcile), 'the class predicate must see a reconcile line');
        $this->assertSame([], self::tickLinesIn($reconcile), 'the tick filter must not claim a reconcile line');
        $this->assertNotSame([], self::tickLinesIn(self::template()), 'the tick filter must see the tick template');
    }

    /**
     * THE OTHER HALF OF THE CONTROL: the denominator is real AND the guards above are judging
     * something. A census whose file list came back empty — a `git` that is not on `PATH`, a
     * checkout that is not a repo — reports every leg above as clean; so does a live census over
     * which the predicate happens to match nothing, which is the shape an all-absences guard
     * degrades into as the docs it was written for move around.
     */
    public function test_the_census_denominator_is_real(): void
    {
        $files = $this->censusedFiles();

        $this->assertGreaterThan(100, count($files), 'the census covered implausibly few tracked files');
        $this->assertContains(self::OWNER, $files);
        $this->assertNotContains('docs/CHANGELOG.md', $files, 'history must be carved out, not censused');

        // ⛔ THE PRESENCE WITNESS FOR THE `>>` LEG. That leg asserts an ABSENCE over every
        // censused file, so it passes just as green over a repo in which the predicate matches
        // no line at all. Something has to show it is looking at real crontab lines.
        $seen = [];
        foreach ($files as $rel) {
            $seen = [...$seen, ...self::crontabLinesIn((string) file_get_contents(base_path($rel)))];
        }

        $this->assertNotSame([], $seen, 'the class guard examined no crontab line anywhere — it is asserting nothing');
        $this->assertContains(self::template(), $seen, "the owner's own template must be among the lines it judges");
    }
}
