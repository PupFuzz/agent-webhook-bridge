<?php

namespace App\Bridge\Support;

/**
 * THE HOST FACTS ABOUT ASKING A HUMAN A QUESTION AT THIS PROCESS'S TERMINAL — one per
 * DIRECTION, because a question needs both and each can be absent on its own:
 *  - {@see self::hasKeyboard()} — stdin: can an answer come back?
 *  - {@see self::hasScreen()}   — stdout: will the question be seen? The confirmation
 *    `Command::confirm()` renders is written to STDOUT, not stderr (measured — see that
 *    method), so this is the stream the operator either reads or does not.
 *
 * ⛔ NEITHER IS THE GATE, AND NEITHER IS CONSENT. This interface answers what the HOST is;
 * whether this run may ask is `App\Console\Commands\Bridge\BridgeCommand::canPromptToConfirm()`,
 * which composes both of these with the operator's own instruction not to be interacted
 * with. Every one-fact spelling of that gate has been shipped and falsified in this repo —
 * the reasons live with the gate, in ONE place, and are deliberately not restated here.
 *
 * ⚠ IT IS A SEAM BECAUSE IT IS NOT CONSTRUCTIBLE IN-PROCESS. A test cannot give phpunit's
 * own stdin or stdout a terminal, and whether they HAVE one differs between a developer's
 * shell and CI — so a command that read the real predicate directly would be green locally
 * and take the other branch in CI, or the reverse. Bound in `BridgeServiceProvider`;
 * replaced with `$this->app->instance()` in tests, exactly as {@see ChannelProbeEnvironment} is.
 */
interface TerminalProbe
{
    /** Is stdin a terminal — is there a human who can ANSWER — rather than a pipe, a file, `/dev/null`, or a closed descriptor? */
    public function hasKeyboard(): bool;

    /** Is stdout a terminal — will a human SEE the question — rather than a redirect into a file or a pipe? */
    public function hasScreen(): bool;
}
