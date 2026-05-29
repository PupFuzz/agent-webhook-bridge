<?php

namespace App\Bridge\Support;

use App\Bridge\Dispatch\Actor;
use Illuminate\Support\Facades\Log;

/**
 * Reader for the shared agents.json identity registry — translates raw actor
 * ids into friendly agent names. Missing/corrupt file degrades to an empty
 * registry (classifiers still work, just without friendly-name enrichment).
 *
 * Collisions on either identity axis (kanban_user_id / github_login)
 * are detected at construction; the colliding key is BYPASSED in the lookup
 * (attribution returns null → the raw id surfaces) rather than silently
 * mis-attributing every event to the last-listed agent. A warning names the
 * sharing agents.
 */
final class AgentRegistry
{
    public const SCHEMA_VERSION = 1;

    /** @var array<int, RegisteredAgent> */
    private array $byKanbanUid = [];

    /** @var array<string, RegisteredAgent> */
    private array $byGithubLogin = [];

    /** @var array<string, RegisteredAgent> */
    private array $byName = [];

    /**
     * @param  list<RegisteredAgent>  $agents
     */
    public function __construct(private array $agents)
    {
        $this->byKanbanUid = $this->buildIntLookup(
            fn (RegisteredAgent $a) => $a->kanbanUserId,
            'kanban_user_id',
        );
        $this->byGithubLogin = $this->buildStringLookup(
            fn (RegisteredAgent $a) => $a->githubLogin,
            'github_login',
        );
        foreach ($agents as $a) {
            $this->byName[$a->name] = $a;
        }
    }

    /**
     * @param  callable(RegisteredAgent): ?int  $key
     * @return array<int, RegisteredAgent>
     */
    private function buildIntLookup(callable $key, string $axis): array
    {
        $counts = [];
        foreach ($this->agents as $a) {
            $k = $key($a);
            if ($k !== null) {
                $counts[$k] = ($counts[$k] ?? 0) + 1;
            }
        }
        $collided = array_keys(array_filter($counts, fn (int $n) => $n > 1));
        $this->warnCollisions($collided, $key, $axis);

        $lookup = [];
        foreach ($this->agents as $a) {
            $k = $key($a);
            if ($k !== null && ! in_array($k, $collided, true)) {
                $lookup[$k] = $a;
            }
        }

        return $lookup;
    }

    /**
     * @param  callable(RegisteredAgent): ?string  $key
     * @return array<string, RegisteredAgent>
     */
    private function buildStringLookup(callable $key, string $axis): array
    {
        $counts = [];
        foreach ($this->agents as $a) {
            $k = $key($a);
            if ($k !== null) {
                $counts[$k] = ($counts[$k] ?? 0) + 1;
            }
        }
        $collided = array_keys(array_filter($counts, fn (int $n) => $n > 1));
        $this->warnCollisions($collided, $key, $axis);

        $lookup = [];
        foreach ($this->agents as $a) {
            $k = $key($a);
            if ($k !== null && ! in_array($k, $collided, true)) {
                $lookup[$k] = $a;
            }
        }

        return $lookup;
    }

    /**
     * @param  list<int|string>  $collided
     * @param  callable(RegisteredAgent): (int|string|null)  $key
     */
    private function warnCollisions(array $collided, callable $key, string $axis): void
    {
        foreach ($collided as $value) {
            $shared = [];
            foreach ($this->agents as $a) {
                if ($key($a) === $value) {
                    $shared[] = $a->name;
                }
            }
            sort($shared);
            Log::warning(sprintf(
                'agent registry: %s %s is shared by multiple agents (%s); attribution '.
                'will be bypassed for events from this identity — Actor.name will be null '.
                'and the raw id surfaces. Set a distinct %s per agent.',
                $axis,
                (string) $value,
                implode(', ', $shared),
                $axis,
            ));
        }
    }

    public static function load(string $path): self
    {
        if (! is_file($path)) {
            Log::warning("agent registry not found at {$path}; degrading to empty registry");

            return new self([]);
        }

        $raw = json_decode((string) file_get_contents($path), true);
        if (! is_array($raw)) {
            Log::warning("agent registry at {$path} is not valid JSON / not an object; degrading to empty registry");

            return new self([]);
        }

        $agentsRaw = $raw['agents'] ?? [];
        if (! is_array($agentsRaw)) {
            return new self([]);
        }

        $agents = [];
        foreach ($agentsRaw as $a) {
            if (! is_array($a) || ! isset($a['name'])) {
                Log::warning("agent registry at {$path} has a malformed entry (missing 'name'); skipping it");

                continue;
            }
            $uid = $a['kanban_user_id'] ?? null;
            $gh = $a['github_login'] ?? null;
            $agents[] = new RegisteredAgent(
                name: (string) (is_scalar($a['name']) ? $a['name'] : ''),
                kanbanUserId: is_numeric($uid) ? (int) $uid : null,
                scope: isset($a['scope']) && is_scalar($a['scope']) ? (string) $a['scope'] : '',
                githubLogin: is_scalar($gh) ? (string) $gh : null,
            );
        }

        return new self($agents);
    }

    public function byKanbanUserId(int|string|null $uid): ?RegisteredAgent
    {
        if ($uid === null || ! is_numeric($uid)) {
            return null;
        }

        return $this->byKanbanUid[(int) $uid] ?? null;
    }

    public function byGithubLogin(?string $login): ?RegisteredAgent
    {
        if ($login === null || $login === '') {
            return null;
        }

        return $this->byGithubLogin[$login] ?? null;
    }

    public function byName(string $name): ?RegisteredAgent
    {
        return $this->byName[$name] ?? null;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->byName);
    }

    /**
     * Build an Actor from a webhook row's actor_id + parsed payload. Tries
     * kanban (numeric user_id) first, then GitHub (sender.login string).
     *
     * @param  array<mixed>  $payload
     */
    public function actorFromEvent(?string $actorIdRaw, array $payload): Actor
    {
        $aid = $actorIdRaw;
        if ($aid === null) {
            $userId = $payload['user_id'] ?? null;
            $aid = is_scalar($userId) ? (string) $userId : null;
        }

        $reg = null;
        if ($aid !== null) {
            $reg = $this->byKanbanUserId($aid) ?? $this->byGithubLogin($aid);
        }

        return new Actor(
            id: $aid,
            name: $reg?->name,
            isKnownAgent: $reg !== null,
            rawEnvelope: $payload,
        );
    }
}
