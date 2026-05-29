<?php

namespace App\Bridge\Support;

use App\Bridge\Dispatch\Actor;
use Closure;

/**
 * Predicate-based filter applied BEFORE classify(). When isEcho()
 * is true the dispatch is marked done but skipped — no classify, no surface.
 * Predicate-based (not a bare id list) so consumers can filter by name, raw
 * id, or anything on the Actor.
 */
final class EchoSuppression
{
    /**
     * @param  Closure(Actor): bool  $predicate
     */
    public function __construct(private Closure $predicate) {}

    public function isEcho(Actor $actor): bool
    {
        return ($this->predicate)($actor);
    }

    /**
     * Common-case predicate: an event is an echo when the actor's friendly
     * name matches self/treat_as_echo, OR the raw id matches treat_as_echo_ids.
     * The raw-id check is the load-bearing safety net — it works even when the
     * registry is missing (so actor.name is null for everyone).
     *
     * @param  list<string>  $treatAsEcho
     * @param  list<string>  $treatAsEchoIds
     */
    public static function default(string $selfIdentity, array $treatAsEcho = [], array $treatAsEchoIds = []): self
    {
        $echoNames = array_values(array_unique([$selfIdentity, ...$treatAsEcho]));
        $echoIds = array_map(strval(...), $treatAsEchoIds);

        return new self(function (Actor $actor) use ($echoNames, $echoIds): bool {
            if ($actor->name !== null && in_array($actor->name, $echoNames, true)) {
                return true;
            }

            return $actor->id !== null && in_array($actor->id, $echoIds, true);
        });
    }
}
