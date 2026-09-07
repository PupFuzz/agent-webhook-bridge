<?php

namespace App\Bridge\Scheduling;

/**
 * A validated request to put one instance in the periodic-job registry (card#8425 / DL-325).
 *
 * ⭐ CONSTRUCTION IS THE VALIDATION. Every field a job instance must carry —
 * `{name, handler, interval, owner, docs_ref, justification, enabled}` — is a REQUIRED
 * constructor argument with no default, so an instance that omits one cannot be built, let
 * alone stored. The alternative (an array reaching {@see JobRegistry} and being checked
 * there) puts the rules one layer away from the type that represents the thing, which is
 * how a second caller ends up bypassing half of them.
 *
 * ⭐⛔ THE `justification` FIELD IS A REQUIRED DOCUMENTATION SLOT, NOT A GATE — and the
 * distinction is the point of this paragraph, not a hedge at the end of it (rt#341,
 * sola-pm). **It filters nothing.** Nobody is consulted, nothing queues, insertion stays
 * programmatic and runtime, and the answer is never judged — by a human or by this class.
 * {@see self::JUSTIFICATION_FLOOR} is a LENGTH floor and that is its entire reach: it stops
 * an empty string, a dash and an `n/a`, and it stops nothing else. A fluent sentence that is
 * wrong, copy-pasted, or a constant supplied by a generic caller passes it, and passing it
 * establishes only that some text of that length was supplied.
 *
 * The rule it serves is *a periodic job is the last resort; consider a non-cron solution
 * first*, which is in direct tension with "instances are free" — and the tension is resolved
 * by DOCUMENTING rather than by gating. What the field buys is at REVIEW time, over a
 * population of ~1–3 rows per install: the inserter pays exactly one sentence, and the
 * registry's enumeration then answers *"why is this periodic?"* for every row instead of only
 * *"what runs?"*. A periodic population that grew for bad reasons is visible at a glance
 * rather than by archaeology. That is the same thing `App\Bridge\Check\Silence` buys one
 * subsystem over: a required sentence cannot be added across N call sites without somebody
 * writing N sentences.
 *
 * ⛔ WHY THE BOUND IS STATED THIS LOUDLY. A field described as a check is one a future reader
 * trusts to have filtered something, and this one has filtered nothing — so the population it
 * enumerates would be read as vetted when it is only DOCUMENTED. A field whose limits are
 * stated is honest; the same field described as a control is the decoration this design
 * refuses everywhere else.
 */
final class JobSpec
{
    /**
     * Instance names are lower-case handles: they appear in `bridge:jobs remove <name>`,
     * in log lines and in check output, so a name with a space or a shell metacharacter is
     * a name somebody will get wrong at 3am.
     */
    private const NAME = '/^[a-z0-9][a-z0-9._-]{0,119}$/';

    /**
     * A floor on the cadence, in seconds. It is NOT a performance guard — it is the tick's
     * own resolution: the crontab line is a 5/10/15-minute-class interval, so an instance
     * asking for 10 seconds is asking for something no ingress on this design can deliver,
     * and storing it would mean the registry states a cadence the install does not run.
     */
    public const MIN_INTERVAL_S = 60;

    /** A month. Past this, "periodic" is not what the caller means; a job is not a reminder. */
    public const MAX_INTERVAL_S = 2678400;

    /**
     * The shortest thing that can be a reason, in characters. Deliberately small: the
     * friction is meant to be one sentence, not an essay, and a large floor would buy padding
     * rather than thought.
     *
     * ⛔ IT IS A LENGTH TEST AND NOTHING MORE. It refuses an empty answer; it does not, and
     * cannot, tell a good reason from a bad one — see this class's docblock, which states
     * that bound rather than leaving a reader to assume this number filtered something.
     */
    public const JUSTIFICATION_FLOOR = 20;

    /**
     * @throws JobSpecException on any field that would store an instance the install cannot
     *                          honestly run or enumerate
     */
    public function __construct(
        public readonly string $name,
        public readonly string $handler,
        public readonly int $intervalS,
        public readonly string $owner,
        public readonly string $docsRef,
        public readonly string $justification,
        public readonly bool $enabled = true,
        /** @var array<mixed> */
        public readonly array $payload = [],
    ) {
        if (preg_match(self::NAME, $name) !== 1) {
            throw new JobSpecException(
                "job name '{$name}' is not a handle — lower-case letters, digits, '.', '_' and '-', starting alphanumeric, at most 120 characters"
            );
        }
        if (trim($handler) === '') {
            throw new JobSpecException("job '{$name}' names no handler — a job entry may only reference a handler that exists in bridge code");
        }
        if ($intervalS < self::MIN_INTERVAL_S || $intervalS > self::MAX_INTERVAL_S) {
            throw new JobSpecException(
                "job '{$name}' asks for a ".$intervalS.'s interval; the registry accepts '
                .self::MIN_INTERVAL_S.'..'.self::MAX_INTERVAL_S.'s (the tick is a 5/10/15-minute-class clock, so a shorter cadence is one no ingress delivers)'
            );
        }
        if (trim($owner) === '') {
            throw new JobSpecException("job '{$name}' names no owner — an unowned periodic job is one nobody is answerable for");
        }
        if (trim($docsRef) === '') {
            throw new JobSpecException("job '{$name}' carries no docs reference — enumerating a job nobody can look up answers half the question");
        }
        if (mb_strlen(trim($justification)) < self::JUSTIFICATION_FLOOR) {
            throw new JobSpecException(
                "job '{$name}' carries no justification. A periodic job is the LAST resort in this design: say, in one sentence, why this cannot run "
                .'off the after-response event gate (see docs/periodic-jobs.md). This is a required DOCUMENTATION SLOT, not an approval gate — nobody is '
                .'consulted, nothing judges the answer, and it is stored and printed by `bridge:jobs`.'
            );
        }
    }
}
