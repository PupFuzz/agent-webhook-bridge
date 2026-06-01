<?php

namespace Tests\Fixtures;

use App\Bridge\Contracts\Handler;
use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Support\AgentConfig;

/** Best-effort handler that records its tag to HandlerRecorder (optionally throws). */
class RecordingHandler implements Handler
{
    public function __construct(private string $tag, private bool $throw = false) {}

    public function handle(ReactionTarget $target, AgentConfig $agent): void
    {
        HandlerRecorder::$calls[] = $this->tag;
        if ($this->throw) {
            throw new \RuntimeException("{$this->tag} failed");
        }
    }
}
