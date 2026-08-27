<?php

namespace Tests\Support;

use App\Bridge\Tools\CallProvenance;
use App\Bridge\Tools\ServingProcessEnvironment;
use Tests\Unit\Tools\SystemServingProcessEnvironmentTest;

/**
 * In-memory {@see ServingProcessEnvironment} — each of the three facts is a constructor
 * field, so every arm that uses it STATES its fixture and none reads the ambient process.
 *
 * ⛔ EACH FIELD IS `?bool`, BECAUSE THE SEAM HAS THREE STATES AND A FAKE THAT CARRIED TWO
 * WOULD MAKE THE THIRD UNTESTABLE. `null` is "the serving process could not establish this",
 * the value {@see CallProvenance::of()} must never let reach the stronger verdict — and the
 * real probe returns it on a reachable, measured host configuration.
 *
 * ⛔ IT EXISTS BECAUSE ONE OF THE THREE FACTS CANNOT BE FAKED ANY OTHER WAY. A suite cannot
 * give itself a controlling terminal, nor take its own away, so a test that measured the
 * real process would be green or red by accident of how phpunit was launched — which is
 * exactly the shape of the evidence that let card#7836's first predicate ship wrong. The
 * REAL probe is measured separately and on real processes, in
 * {@see SystemServingProcessEnvironmentTest}.
 */
final class FakeServingProcessEnvironment implements ServingProcessEnvironment
{
    public function __construct(
        private ?bool $sshSession = false,
        private ?bool $controllingTerminal = false,
        private ?bool $ptyMarker = false,
    ) {}

    public function hasSshSession(): ?bool
    {
        return $this->sshSession;
    }

    public function hasControllingTerminal(): ?bool
    {
        return $this->controllingTerminal;
    }

    public function carriesPtyMarker(): ?bool
    {
        return $this->ptyMarker;
    }
}
