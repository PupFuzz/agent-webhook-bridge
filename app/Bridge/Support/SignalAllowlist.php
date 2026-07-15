<?php

namespace App\Bridge\Support;

use App\Bridge\Dispatch\Actor;
use App\Bridge\Exceptions\ConfigException;
use Closure;

/**
 * Optional positive filter applied AFTER echo suppression. When treat_as_signal
 * is set, only events authored by the named agents reach classify; everything
 * else is dropped. (One carve-out, DL-203 — the same one {@see EchoSuppression}
 * carries: for a github writeback-emitting classifier a non-signal actor's event
 * DOES reach classify, and the dispatcher strips the result to machine-only
 * targets; the agent surface still never fires.) Empty list → allow all. Matched against actor.name (from the
 * registry), so a name must be a real agent (a <name>.yml) — an unknown name is
 * fail-closed (a typo would silently classify everything NOT-IN-SIGNAL).
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
                throw new ConfigException(sprintf(
                    'treat_as_signal references name(s) with no matching agent config: %s — they would '.
                    'never match (events by them classified NOT-IN-SIGNAL). Add the <name>.yml or fix the typo.',
                    implode(', ', $unknown),
                ));
            }
        }

        return new self(
            fn (Actor $actor): bool => $actor->name !== null && in_array($actor->name, $allowlist, true)
        );
    }
}
