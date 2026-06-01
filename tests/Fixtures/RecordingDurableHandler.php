<?php

namespace Tests\Fixtures;

use App\Bridge\Contracts\DurableReaction;

/** Like RecordingHandler, but marked DurableReaction (runs first; throw propagates). */
class RecordingDurableHandler extends RecordingHandler implements DurableReaction {}
