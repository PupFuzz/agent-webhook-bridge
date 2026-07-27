<?php

namespace App\Bridge\Support;

/**
 * The severity vocabulary every `bridge:check` probe finding speaks (card 5178).
 *
 * Before this enum the vocabulary was implemented twice — once per probe — as bare
 * strings, so a typo'd or newly-invented severity fell through the renderer's
 * else-branch and printed GREEN, i.e. an unknown severity was reported as certified
 * clean. As an enum inside {@see Finding} that state is unrepresentable, and the
 * renderer's `match` is exhaustive, so a fifth case is a static-analysis error at
 * every consumer rather than a green line.
 *
 * The renderer is NAMED, never imported: it is `CheckCommand::emitFinding()` in
 * `app/Console`, and this vocabulary must not take a dependency on its consumer.
 *
 * WHAT EACH CASE MEANS HERE IS ITS RENDERING, and only that — the mapping below is
 * mechanical and true by construction of that method:
 *
 *   - {@see self::Fail}        error() + flips `bridge:check`'s exit. The ONLY case that does.
 *   - {@see self::Warn}        warn() (yellow); never touches the exit.
 *   - {@see self::Unvalidated} line() (plain) + counted into the run's closing tally.
 *   - {@see self::Ok}          info() (green).
 *
 * IT DOES NOT DEFINE WHEN TO PICK ONE. The `warn` ↔ `unvalidated` boundary in
 * particular is NOT settled: the candidate discriminator ("unvalidated = the check
 * did not run and nothing is wrong; warn = something is anomalous and a named repair
 * exists") holds at the ChannelSnapshotProbe sites but not at CheckCommand's
 * event-consumer fail-soft catch, which DL-236 assigned `unvalidated` for a genuine
 * anomaly. Settling that is a behavior change to operator-facing output and is
 * tracked as card 5291 — do not encode a when-to-pick rule here that the code does
 * not obey.
 */
enum Severity: string
{
    /** Something is wrong and proven wrong. Renders as an error; flips the exit code. */
    case Fail = 'fail';

    /** Renders yellow. Never flips the exit code. */
    case Warn = 'warn';

    /**
     * The leg did NOT run, so a green `bridge:check` is not evidence about it
     * (card 5170). Renders plain and is tallied; never flips the exit code.
     */
    case Unvalidated = 'unvalidated';

    /** Measured and clean. Renders green. Must never carry a not-measured finding. */
    case Ok = 'ok';
}
