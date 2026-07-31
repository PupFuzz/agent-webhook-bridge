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
 * WHEN TO PICK `unvalidated` — THE RULE (DL-251, card 5291). It replaces a paragraph
 * that said the boundary was not settled and named a candidate discriminator the code
 * did not obey ("the check did not run and nothing is wrong"), which failed on
 * `CheckCommand`'s event-consumer catch — a genuine anomaly DL-236 had already assigned
 * `unvalidated`.
 *
 * It applies **PER LEG, NOT PER CHECK.** Several checks deliberately carry many legs —
 * `WritebackBoardStateCheck` and `WritebackMappingConfigCheck` both say ONE CHECK, NOT ONE
 * PER LEG in their own docblocks, and give the reason — so a leg's question is the narrowest
 * question whose answer its finding reports, not the check's subject.
 *
 *   **`unvalidated` ⟺ THE LEG DID NOT ANSWER ITS OWN QUESTION.**
 *
 *   1. Answered → `ok` / `warn` / `fail`, by the answer.
 *   2. Not answered, because of a fact about the INSTALL the operator cannot know →
 *      `unvalidated`.
 *   3. Not answered, because the operator did not ASK (an opt-in flag is absent) → no
 *      finding at all; the `not-requested` disposition carries it. Using a severity here
 *      would dilute the one signal with a precise job — the ruling in
 *      `docs/CHECK-REGISTRY-PLAN.md` § Resolved design decision.
 *
 * A leg is UNANSWERED when any of:
 *   **(a)** the measurement did not complete — a read, probe or query threw or was skipped;
 *   **(b)** it completed but the leg cannot stand behind it, because it may have measured
 *       the WRONG SUBJECT (an `authorized_keys` at an assumed path sshd may not consult);
 *   **(c)** a COMPARISON leg's comparand does not resolve to exactly one COMPARABLE value
 *       — absent, plural, or unmappable into the namespace being compared against.
 *
 * SO THE SEVERITY KEEPS EXACTLY ONE MEANING: *"I should have measured this and the install
 * stopped me."*
 *
 * WHAT IS **NOT** `unvalidated`:
 *   - **World-ambiguity is not measurement-ambiguity.** A finding may disclose that two
 *     install states could produce its answer and stay `warn` — "the token sees 0 cards"
 *     was measured; what it implies is what is ambiguous.
 *   - **A skipped DOWNSTREAM leg rides the DISPOSITION axis, not this one.** The
 *     discriminator is a property of the code — the ENVELOPE'S WIDTH. An envelope wrapping
 *     one construction can name the cause, so its skipped slot gets `warn` + a not-run
 *     reason; an envelope wrapping construction AND the run cannot name a cause, so it
 *     reports `unvalidated`.
 *   - **An insufficient euid is not an invocation fact.** A withheld `--probe-tools-ssh`
 *     is a request the operator declined to make; a leg that runs unconditionally and
 *     cannot read the file it needs is a CAPABILITY THE PROCESS LACKS, which is limb 2.
 *
 * ⚠ WHAT THE RULE DOES NOT BUY, because the disclosure narrows rather than dies: it is
 * keyed on what a leg CONCLUDED, so it can only make DISCLOSED blindness precise. A leg
 * that fails to NOTICE it did not measure something is outside the rule entirely, and it
 * is not necessarily silent — it may say nothing, or it may report the conclusion it
 * would have drawn had the measurement happened. Both shapes are live:
 * `ReconcileRepoTokensCheck`'s unreachable-GitHub arm said nothing until DL-251 split it
 * from `Ok`, and `EventFollowsConsumerCheck`'s undeclared-classifier advisory `warn`s off
 * a swallowed throw where the classifier was never asked at all (card#5698 owns that
 * class; DL-251 did not touch it). The `fail` and `ok` populations were not audited
 * against this rule when it was written (DL-251) — it settles the `warn` ↔ `unvalidated`
 * boundary and only that.
 */
enum Severity: string
{
    /** Something is wrong and proven wrong. Renders as an error; flips the exit code. */
    case Fail = 'fail';

    /** Renders yellow. Never flips the exit code. */
    case Warn = 'warn';

    /**
     * The leg did not answer its own question because the install stopped it, so a green
     * `bridge:check` is not evidence about it (card 5170; the rule is in the class
     * docblock). Renders plain and is tallied; never flips the exit code.
     */
    case Unvalidated = 'unvalidated';

    /** Measured and clean. Renders green. Must never carry a not-measured finding. */
    case Ok = 'ok';
}
