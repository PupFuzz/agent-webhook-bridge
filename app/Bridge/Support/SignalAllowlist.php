<?php

namespace App\Bridge\Support;

use App\Bridge\Dispatch\Actor;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Optional positive filter applied AFTER echo suppression (T-015). When
 * treat_as_signal is set, only events authored by the named agents reach
 * classify; everything else is dropped. Empty list → allow all (current
 * behaviour). Matched against actor.name (from the registry), so allowlisting
 * by raw id requires the agent to be in agents.json first.
 */
final class SignalAllowlist
{
    /**
     * @param  Closure(Actor): bool  $predicate
     */
    public function __construct(private Closure $predicate) {}

    public function isSignal(Actor $actor): bool
    {
        return ($this->predicate)($actor);
    }

    /**
     * @param  list<string>  $treatAsSignal
     */
    public static function default(array $treatAsSignal, ?AgentRegistry $registry = null): self
    {
        $allowlist = array_values(array_unique(array_map(strval(...), $treatAsSignal)));

        if ($allowlist === []) {
            return new self(fn (Actor $actor): bool => true);
        }

        if ($registry !== null) {
            $unknown = array_values(array_diff($allowlist, $registry->names()));
            if ($unknown !== []) {
                Log::warning(sprintf(
                    'treat_as_signal references name(s) not in agents.json: %s — these entries '.
                    'will never match, so events by those names are classified NOT-IN-SIGNAL. '.
                    'Add the agent to agents.json or remove the entry.',
                    implode(', ', $unknown),
                ));
            }
        }

        return new self(
            fn (Actor $actor): bool => $actor->name !== null && in_array($actor->name, $allowlist, true)
        );
    }
}
