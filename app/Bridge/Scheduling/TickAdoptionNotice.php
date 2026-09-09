<?php

namespace App\Bridge\Scheduling;

use App\Bridge\Tools\SafePathShape;

/**
 * The INSTALL-TIME notice that offers this install its `bridge:tick` crontab line
 * (card#9058 / DL-361) — printed by `bridge:provision-tools`, once per run.
 *
 * WHAT IT CLOSES. The crontab line was reachable only by READING — it stood, by hand, in
 * `CLAUDE_DEPLOYMENT.md`, `.env.example`, `bridge:tick`'s own class docblock, a released changelog
 * entry and `docs/periodic-jobs.md`, and every MECHANIZED surface was silent. Worse, the freshness
 * alarm cannot bootstrap itself —
 * {@see TickPosture::resolve()} arms it off `BRIDGE_JOBS_TICK_EXPECTED_EVERY`, so it only ever
 * fires for an operator who already read the document. An operator who never read it gets no
 * alarm, ever. That is `bridge:prune`'s DL-012 failure one level earlier: not *scheduled
 * nowhere and nothing said so* but *never discovered and nothing said so*.
 *
 * ⛔ IT IS NOT ON `bridge:check`, AND THAT IS THE DESIGN. `App\Bridge\Check\Checks\JobsPostureCheck` is
 * SILENT on an install that adopted nothing, deliberately: an install that never wanted a tick
 * is not failing by not ticking, and a green "nothing to report" line on every preflight run is
 * the noise that design refused. So the offer lives on a surface that fires at ENABLEMENT time
 * and stops firing once the answer is known.
 *
 * ⭐ THE TICK IS PER BRIDGE INSTALL, NOT PER AGENT, AND THIS CLASS IS SHAPED BY THAT. Read off
 * the code rather than assumed: `scheduled_jobs` has no agent column (one registry per install
 * DB), {@see TickRecord::KEY} and {@see JobScheduler}'s lock/marker keys carry no agent segment,
 * and `BRIDGE_JOBS_TICK_EXPECTED_EVERY` is one install-level value. A second crontab line
 * therefore buys NOTHING — it loses the non-blocking pass lock or falls inside the shared
 * `min_pass_interval` and skips — while costing a second log file and a second thing to keep
 * alive. So the notice says so in as many words, and its CALLER prints it once per RUN rather
 * than once per agent: the scope is stated in the text AND held by the structure, because a
 * sentence that a reader has to believe is weaker than an output that cannot repeat.
 *
 * ⛔ AND IT IS SELF-LIMITING, which is the property that makes it safe to put on a per-agent
 * command. An install that DECLARED a horizon gets nothing at all — from then on the whole
 * subject belongs to `bridge:check`'s `jobs.posture` leg, which reports the state, the staleness
 * and whether anything reads the alarm. An install that is already TICKING with no declaration
 * gets the declaration ask and NO crontab line, because it does not need a second one — and an
 * install whose declaration is SET BUT UNREADABLE is told that, rather than the ask, because it
 * has already answered in a way nothing can read. A step that reappeared for every agent after it
 * was already satisfied would be a worse defect than the silence it replaces.
 *
 * ⚠ IT IS PURE. The posture, the base path, the interpreter and the reason this install's own
 * declaration cannot be read all arrive as constructor arguments; nothing here reads the cache,
 * the config or the filesystem, so a test drives every arm.
 *
 * ⚑ ITS READER MAY BE AN AGENT. `laravel/pao` deletes a fixed glyph set and collapses runs of
 * spaces for an AI-agent reader, so no line opens on a word the cleaned text would read as
 * structure, and the two lines meant to be COPIED (the crontab line, the env line) are
 * flush-left — which is also what makes them paste cleanly.
 */
final class TickAdoptionNotice
{
    /**
     * The cadence the offered line runs at. ⭐ ONE FIGURE, TWO OUTPUTS — the schedule field and
     * the horizon the operator is then told to declare are both DERIVED from it
     * ({@see self::schedule()}, {@see self::horizonS()}), because a hand-typed `600` beside a
     * hand-typed `0,10,20,…` is two numbers that can disagree, and the disagreement would arm
     * the freshness alarm against an interval the line does not run at.
     */
    private const EVERY_MINUTES = 10;

    /** The section that owns the line's explanation; every copy points here. */
    public const OWNER_DOC = 'docs/periodic-jobs.md § Adopting the tick';

    /**
     * @param  TickPosture  $posture  this install's tick posture, resolved by the caller
     * @param  string  $basePath  this install's absolute base path (`base_path()`)
     * @param  string  $phpBinary  the absolute interpreter THIS process is running under
     *                             (`PHP_BINARY`), or an empty string when it cannot be named
     * @param  string|null  $declarationProblem  why this install's existing
     *                                           `BRIDGE_JOBS_TICK_EXPECTED_EVERY` cannot be
     *                                           read ({@see TickRecord::declarationProblem()}),
     *                                           or null when there is nothing wrong with it —
     *                                           including the ordinary case of none at all
     */
    public function __construct(
        private readonly TickPosture $posture,
        private readonly string $basePath,
        private readonly string $phpBinary,
        private readonly ?string $declarationProblem = null,
    ) {}

    /**
     * The notice, as lines — EMPTY when this install has already answered the question.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        // A declared horizon IS the adoption knob (TickRecord::declaredHorizon()), so from here
        // on `bridge:check`'s jobs.posture leg owns the whole subject — the state, the
        // staleness, and whether anything reads the alarm. Repeating the offer under it would
        // be a second answer able to disagree with the first.
        if ($this->posture->adopted) {
            return [];
        }

        // Recorded but undeclared: the line EXISTS and is running. What is missing is the alarm,
        // and handing this install a crontab line would be handing it a duplicate.
        if ($this->posture->state === TickState::Undeclared) {
            return $this->alreadyTicking();
        }

        return $this->notAdopted();
    }

    /** The five crontab fields the offered line runs on. */
    public static function schedule(): string
    {
        return implode(',', range(0, 59, self::EVERY_MINUTES)).' * * * *';
    }

    /** The horizon that matches {@see self::schedule()}, in seconds. */
    public static function horizonS(): int
    {
        return self::EVERY_MINUTES * 60;
    }

    /**
     * The whole crontab line, ready to paste — or null when this install's own base path could
     * not be rendered into one.
     *
     * ⛔ THE INTERPRETER IS ABSOLUTE AND IT IS MEASURED, NOT GUESSED. `cron` runs with a minimal
     * `PATH` that is nothing like an interactive shell's, so a bare `php` is an ASSUMPTION about
     * the operator's crontab environment stated as if it were a fact — and a line that cannot
     * find its interpreter fails in exactly the silent way this whole subsystem exists to
     * report on. `PHP_BINARY` is the one answer this process can establish rather than infer:
     * it is the interpreter running the command that prints the line.
     *
     * ⚠ WHAT THAT DOES AND DOES NOT ESTABLISH — it names the interpreter THIS process runs
     * under, which is evidence about the box and not a claim about what the crontab account will
     * be able to execute. It is strictly better than the bare name it replaces, and it is not a
     * guarantee.
     *
     * ⛔ AND THE REDIRECT TRUNCATES. `>>` grew `tick.log` without bound — six lines an hour,
     * forever, with no rotation anywhere in this repo and nothing that tails it. `>` keeps the
     * LAST tick's summary and needs no rotation at all, which is the honest weighting given
     * where the durable account actually lives: the registry ROW ({@see JobScheduler} writes the
     * outcome there precisely because "nobody tails a log line" is DL-012's finding), the app
     * log, and the exit code.
     */
    public function crontabLine(): ?string
    {
        // A path carrying a space or a shell metacharacter does not make a line that runs; it
        // makes a line that breaks. Same rule, same owner, as the setup packet's paths.
        if (! SafePathShape::isSafePath($this->basePath)) {
            return null;
        }

        return self::schedule()." cd {$this->basePath} && {$this->interpreter()} {$this->basePath}/artisan"
            ." bridge:tick > {$this->basePath}/storage/logs/tick.log 2>&1";
    }

    /** Whether {@see self::crontabLine()} could name the interpreter absolutely. */
    public function interpreterIsNamed(): bool
    {
        return str_starts_with($this->phpBinary, '/') && SafePathShape::isSafePath($this->phpBinary);
    }

    private function interpreter(): string
    {
        return $this->interpreterIsNamed() ? $this->phpBinary : 'php';
    }

    /**
     * Nothing declared and nothing ever recorded — the offer.
     *
     * @return list<string>
     */
    private function notAdopted(): array
    {
        return [
            // ⛔ A NO-RECORD CLAIM, NEVER A NO-TICK ONE. This arm is reached from
            // TickState::Unmeasured, whose own contract is *nothing measured*, never *dead* — a
            // cleared cache, a lapsed TTL, a CACHE_PREFIX/APP_NAME change or a CACHE_STORE switch
            // all land an install with a WORKING crontab line here. "runs no periodic tick" would
            // convert an unmeasured state into a positive claim about the box, and the operator
            // who believes it adds the duplicate line this notice's whole scope exists to prevent.
            'PERIODIC TICK — the bridge has NO RECORD of a periodic tick on this INSTALL, and ONE crontab line adopts it.',
            '  ⛔ ONE LINE PER INSTALL, NEVER PER AGENT. The registry, the last-tick record and the horizon are all '
                .'install-wide — no row and no key is scoped to an agent — so onboarding another agent here needs no '
                .'second line, and a second line would lose the shared pass lock and skip.',
            "  OPERATOR — in the seat-owner account's OWN crontab (`crontab -e`), never root's:",
            ...$this->offeredLine(),
            ...$this->declarationAsk(
                '  Then declare the interval it runs at, in seconds, so a dead line goes LOUD instead of sitting silent:',
                'BRIDGE_JOBS_TICK_EXPECTED_EVERY='.self::horizonS(),
            ),
            '  ⚠ a .env edit is INERT under `php artisan config:cache` until the cache is rebuilt.',
            '  Then wire something that ASKS — `php artisan bridge:jobs --assert-tick`, from a session-start hook or '
                .'any periodic runbook step. A declared horizon nothing ever reads is a dead alarm that reads as coverage.',
            '  ⚠ a line already in a crontab here? Then the bridge has no record of a tick from it — which is not the '
                .'same as no tick having run: a cleared cache store loses the record too. `php artisan bridge:jobs` '
                .'shows what the bridge can see — investigate that line rather than adding a second one.',
            "  Never required: the registry also runs off the inbound webhook's after-response gate, so an install "
                .'that adds nothing keeps working exactly as it does today. '.self::OWNER_DOC,
        ];
    }

    /**
     * The ask that turns a crontab line into an ALARM — or, when this install already sets
     * `BRIDGE_JOBS_TICK_EXPECTED_EVERY` to something unreadable, the reason it is not one.
     *
     * ⛔ WITHOUT THIS ARM THE NOTICE CONTRADICTS THE OPERATOR'S OWN `.env`. A malformed
     * declaration (`=ten`, `=0`, `=-5`) is not numeric, so {@see TickRecord::declaredHorizon()}
     * returns null and the posture reads UNADOPTED — indistinguishable here from an install that
     * never declared anything. Printed as the ordinary ask, this surface then tells an operator
     * to add a key their `.env` already assigns (a duplicate assignment) or to declare an
     * interval they believe they declared, and NOTHING says the value cannot be read.
     * {@see TickRecord::declarationProblem()} is the sentence for exactly that state, and the
     * reader this whole surface is built for is the one who never opened `bridge:check`.
     *
     * @param  string  $ask  the ordinary sentence, when the declaration is readable
     * @param  string  $value  the flush-left, pasteable assignment that follows it
     * @return list<string>
     */
    private function declarationAsk(string $ask, string $value): array
    {
        if ($this->declarationProblem === null) {
            return [$ask, $value];
        }

        return [
            '  ⛔ THIS INSTALL ALREADY SETS THE KEY AND THE VALUE CANNOT BE READ — '.$this->declarationProblem,
            '  FIX THAT VALUE IN PLACE rather than adding the key again (a second assignment is not a second '
                .'answer): it is the number of SECONDS between that crontab line\'s runs.',
        ];
    }

    /**
     * The pasteable line and the two things a reader must know about it — or, when this
     * install's base path cannot be rendered into a command, the reason there is no line.
     *
     * @return list<string>
     */
    private function offeredLine(): array
    {
        $line = $this->crontabLine();

        if ($line === null) {
            return [
                "  ⛔ NO LINE IS RENDERED. This install's base path ({$this->basePath}) is outside the character class "
                    .'a pasted command may carry (^'.SafePathShape::BODY_PATTERN.'$) — a space or a shell '
                    .'metacharacter in it would break the line rather than run it. Write the line by hand from the '
                    .'template in '.self::OWNER_DOC.', quoting the paths.',
            ];
        }

        return [
            $line,
            // ⛔ THE TWO ARMS ARE MUTUALLY EXCLUSIVE, not a claim plus a caveat. Printing "the
            // interpreter is ABSOLUTE" above a line that fell back to a bare `php` would be the
            // notice contradicting its own output — a reader who trusts the sentence never
            // re-reads the line, which is the one direction this must not fail in.
            ...($this->interpreterIsNamed()
                ? ['  ⚑ the interpreter is ABSOLUTE and is the one THIS command is running under: cron\'s PATH is '
                    .'minimal, so a bare `php` would be an assumption about it rather than a fact.']
                : ['  ⚠ THE INTERPRETER IS A BARE `php` — this process could not name its own absolutely, so the '
                    .'line above assumes cron\'s PATH resolves it. CHECK that it does, or replace it with the '
                    .'absolute path before adding the line.']),
            '  ⚑ `>` and not `>>` — the file holds the LAST tick only, so nothing has to rotate it. The durable '
                .'account of what ran is the registry (`php artisan bridge:jobs`), the app log and the exit code. Want '
                .'the history instead? Use `>>` and rotate it yourself.',
        ];
    }

    /**
     * A tick IS arriving and no horizon is declared — so the line exists and the ALARM does not.
     *
     * ⛔ IT OFFERS NO CRONTAB LINE. Handing one to an install already ticking is handing it a
     * duplicate, which is the exact defect this notice's per-install scope exists to avoid.
     *
     * ⚑ AND THE ASK IS {@see self::declarationAsk()}'S, not this method's, because THIS is the arm
     * where an unreadable declaration is worst: the operator is being asked to declare an interval
     * they believe they already declared.
     *
     * @return list<string>
     */
    private function alreadyTicking(): array
    {
        return [
            'PERIODIC TICK — already running on this bridge INSTALL (last tick recorded '.(int) $this->posture->ageS
                .'s ago), so do NOT add a crontab line: the tick is ONE line per install, never one per agent.',
            ...$this->declarationAsk(
                '  What is missing is the ALARM. Declare the interval YOUR existing line runs at, in seconds, so a '
                    .'dead line goes LOUD instead of sitting silent:',
                'BRIDGE_JOBS_TICK_EXPECTED_EVERY=<seconds between that line\'s runs>',
            ),
            '  ⚠ a .env edit is INERT under `php artisan config:cache` until the cache is rebuilt. Then wire something '
                .'that ASKS — `php artisan bridge:jobs --assert-tick`. '.self::OWNER_DOC,
        ];
    }
}
