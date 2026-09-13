<?php

namespace App\Bridge\Check;

/**
 * ONE agent's next board-tools enablement step (card#8959, DL-352).
 *
 * IT CARRIES DATA AND NO PROSE, for the reason {@see CheckInventory} carries none: two
 * renderers read it — {@see CheckJsonRenderer} emits it as a `next_steps` entry and
 * `CheckCommand` writes the operator's sentence from it — and a sentence on the value
 * object would put one renderer's voice inside the other. What the two DO share is the
 * `command` and the `doc`, and they share them by reading this rather than by each
 * composing their own: a machine consumer that ran a different command from the one the
 * report printed would be the drift this field exists to prevent.
 */
final class NextStep
{
    public function __construct(
        public readonly string $agent,
        public readonly NextStepState $state,
        /** The one shell command to run next, ready to run as printed. */
        public readonly string $command,
        /** The section that owns the runbook this step is a pointer into. */
        public readonly string $doc,
        /**
         * The subscription SCOPE this step is about — non-null on
         * {@see NextStepState::GithubWebhookMissing} and null on every other state
         * (card#9150).
         *
         * ⚑ NULLABLE BECAUSE THE POPULATION IS, not because it is optional information. Four
         * of the five states are properties of an AGENT and have no scope to name; the fifth
         * is a property of an (agent, scope) pair, and a step that could not name the repo
         * would leave its own remedy unusable — *add a webhook* is not an instruction until
         * you know to which repo. The alternative, an empty string, would make "no scope" and
         * "a scope spelled as nothing" one value on the machine surface.
         */
        public readonly ?string $scope = null,
    ) {}
}
