<?php

namespace App\Console\Commands\Bridge;

use Illuminate\Console\Command;

/**
 * Base for the bridge:* commands. Holds the small shared option-parsing helper
 * so each command doesn't re-implement the "non-empty string option or null"
 * coercion (e.g. --agent).
 */
abstract class BridgeCommand extends Command
{
    /**
     * A console option coerced to a non-empty string, or null when absent/blank.
     */
    protected function strOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
