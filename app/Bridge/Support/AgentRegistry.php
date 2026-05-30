<?php

namespace App\Bridge\Support;

use App\Bridge\Dispatch\Actor;
use Illuminate\Support\Facades\Log;

/**
 * Reader for the shared agents.json identity registry — translates raw actor
 * ids into friendly agent names. Missing/corrupt/outdated file degrades to an
 * empty registry (classifiers still work, just without friendly-name
 * enrichment, and echo suppression falls back to raw-id matching).
 *
 * Recognition keys on IMMUTABLE numeric ids only — kanban `user_id` and GitHub
 * `sender.id`. GitHub usernames are renameable, so `github_login` is a
 * display-only label, never a matching key (DL-002). Matching is
 * provider-aware: a kanban `user_id` and a github `sender.id` that happen to
 * be the same integer never cross-match.
 *
 * Two ways an account maps to agents:
 *   - per-agent `kanban_user_id` / `github_user_id` → one account, one agent
 *     (attribution sets Actor.name).
 *   - `shared_identities[]` → one account, many agents (e.g. four agents under
 *     one GitHub login because the platform forces shared credentials).
 *     Attribution can't pick one agent, so Actor.name stays null and a custom
 *     classifier re-attributes. This is the intentional, declared-once form of
 *     the shared-login collision bypass.
 *
 * Accidental collisions on a per-agent axis (the same id on two agent entries)
 * are still detected at construction and bypassed (Actor.name null, raw id
 * surfaces) rather than silently mis-attributing to the last-listed agent; a
 * warning names the sharing agents and points at shared_identities.
 */
final class AgentRegistry
{
    public const SCHEMA_VERSION = 2;

    /** @var array<int, RegisteredAgent> */
    private array $byKanbanUid = [];

    /** @var array<int, RegisteredAgent> */
    private array $byGithubUid = [];

    /** @var array<string, RegisteredAgent> */
    private array $byName = [];

    /** @var array<int, SharedIdentity> keyed by github_user_id */
    private array $sharedGithubIds = [];

    /** @var array<int, string> github_user_id → configured login, for the stale-login drift warning */
    private array $driftLogins = [];

    /** @var array<string, bool> dedup guard so a drifted login warns once per registry instance */
    private array $driftWarned = [];

    /**
     * @param  list<RegisteredAgent>  $agents
     * @param  list<SharedIdentity>  $sharedIdentities
     */
    public function __construct(private array $agents, array $sharedIdentities = [])
    {
        foreach ($agents as $a) {
            $this->byName[$a->name] = $a;
        }
        foreach ($sharedIdentities as $s) {
            $this->sharedGithubIds[$s->githubUserId] = $s;
            foreach ($s->agentNames as $name) {
                if (! isset($this->byName[$name])) {
                    Log::warning(sprintf(
                        'agent registry: shared_identities github_user_id %d references unknown agent "%s" '.
                        '(not present in agents[]); the reference is ignored.',
                        $s->githubUserId,
                        $name,
                    ));
                }
            }
            if ($s->githubLogin !== null) {
                $this->driftLogins[$s->githubUserId] = $s->githubLogin;
            }
        }

        $this->byKanbanUid = $this->buildIntLookup(
            fn (RegisteredAgent $a) => $a->kanbanUserId,
            'kanban_user_id',
            'Set a distinct kanban_user_id per agent.',
        );
        // A github_user_id declared shared takes precedence over a per-agent
        // entry carrying the same id — exclude it from the unique lookup so the
        // shared bypass wins deterministically.
        $this->byGithubUid = $this->buildIntLookup(
            fn (RegisteredAgent $a) => isset($this->sharedGithubIds[$a->githubUserId]) ? null : $a->githubUserId,
            'github_user_id',
            'Give each agent a distinct github_user_id, or declare the shared account once under shared_identities.',
        );
        foreach ($agents as $a) {
            if ($a->githubUserId !== null && $a->githubLogin !== null && ! isset($this->driftLogins[$a->githubUserId])) {
                $this->driftLogins[$a->githubUserId] = $a->githubLogin;
            }
        }
    }

    /**
     * @param  callable(RegisteredAgent): ?int  $key
     * @return array<int, RegisteredAgent>
     */
    private function buildIntLookup(callable $key, string $axis, string $guidance): array
    {
        $counts = [];
        foreach ($this->agents as $a) {
            $k = $key($a);
            if ($k !== null) {
                $counts[$k] = ($counts[$k] ?? 0) + 1;
            }
        }
        $collided = array_keys(array_filter($counts, fn (int $n) => $n > 1));
        $this->warnCollisions($collided, $key, $axis, $guidance);

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
     * @param  list<int>  $collided
     * @param  callable(RegisteredAgent): ?int  $key
     */
    private function warnCollisions(array $collided, callable $key, string $axis, string $guidance): void
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
                'and the raw id surfaces. %s',
                $axis,
                (string) $value,
                implode(', ', $shared),
                $guidance,
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

        $version = $raw['schema_version'] ?? null;
        if ($version !== self::SCHEMA_VERSION) {
            Log::warning(sprintf(
                'agent registry at %s has schema_version %s; this build requires %d. Migrate the file '.
                '(github_login is no longer a matching key — replace it with the immutable github_user_id, '.
                'and declare shared accounts under shared_identities). Degrading to empty registry until then.',
                $path,
                is_scalar($version) ? (string) $version : 'missing',
                self::SCHEMA_VERSION,
            ));

            return new self([]);
        }

        return new self(
            self::parseAgents($raw['agents'] ?? [], $path),
            self::parseSharedIdentities($raw['shared_identities'] ?? [], $path),
        );
    }

    /**
     * @param  mixed  $agentsRaw
     * @return list<RegisteredAgent>
     */
    private static function parseAgents($agentsRaw, string $path): array
    {
        if (! is_array($agentsRaw)) {
            return [];
        }

        $agents = [];
        foreach ($agentsRaw as $a) {
            if (! is_array($a) || ! isset($a['name'])) {
                Log::warning("agent registry at {$path} has a malformed entry (missing 'name'); skipping it");

                continue;
            }
            $uid = $a['kanban_user_id'] ?? null;
            $guid = $a['github_user_id'] ?? null;
            $gh = $a['github_login'] ?? null;
            $agents[] = new RegisteredAgent(
                name: (string) (is_scalar($a['name']) ? $a['name'] : ''),
                kanbanUserId: is_numeric($uid) ? (int) $uid : null,
                scope: isset($a['scope']) && is_scalar($a['scope']) ? (string) $a['scope'] : '',
                githubUserId: is_numeric($guid) ? (int) $guid : null,
                githubLogin: is_scalar($gh) ? (string) $gh : null,
            );
        }

        return $agents;
    }

    /**
     * @param  mixed  $sharedRaw
     * @return list<SharedIdentity>
     */
    private static function parseSharedIdentities($sharedRaw, string $path): array
    {
        if (! is_array($sharedRaw)) {
            return [];
        }

        $shared = [];
        foreach ($sharedRaw as $s) {
            $guid = is_array($s) ? ($s['github_user_id'] ?? null) : null;
            if (! is_array($s) || ! is_numeric($guid)) {
                Log::warning("agent registry at {$path} has a shared_identities entry without a numeric github_user_id; skipping it");

                continue;
            }
            $login = $s['github_login'] ?? null;
            $agentNames = isset($s['agents']) && is_array($s['agents'])
                ? array_values(array_filter(array_map(
                    fn ($n) => is_scalar($n) ? (string) $n : null,
                    $s['agents'],
                )))
                : [];
            $shared[] = new SharedIdentity(
                githubUserId: (int) $guid,
                githubLogin: is_scalar($login) ? (string) $login : null,
                agentNames: $agentNames,
            );
        }

        return $shared;
    }

    public function byKanbanUserId(int|string|null $uid): ?RegisteredAgent
    {
        if ($uid === null || ! is_numeric($uid)) {
            return null;
        }

        return $this->byKanbanUid[(int) $uid] ?? null;
    }

    public function byGithubUserId(int|string|null $uid): ?RegisteredAgent
    {
        if ($uid === null || ! is_numeric($uid)) {
            return null;
        }

        return $this->byGithubUid[(int) $uid] ?? null;
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
     * Build an Actor from a verified event's actor_id + parsed payload.
     * Matching is provider-aware: kanban events match `kanban_user_id`, GitHub
     * events match the immutable `github_user_id` (the adapter puts
     * `sender.id` in actor_id). A GitHub account declared in shared_identities
     * resolves to a null name on purpose (custom classifier re-attributes).
     *
     * @param  array<mixed>  $payload
     */
    public function actorFromEvent(string $provider, ?string $actorId, array $payload): Actor
    {
        if ($provider === 'github') {
            return $this->githubActor($actorId, $payload);
        }

        // kanban (and any same-shaped provider): immutable integer user_id.
        $aid = $actorId;
        if ($aid === null) {
            $userId = $payload['user_id'] ?? null;
            $aid = is_scalar($userId) ? (string) $userId : null;
        }
        $reg = $aid !== null ? $this->byKanbanUserId($aid) : null;

        return new Actor(id: $aid, name: $reg?->name, isKnownAgent: $reg !== null, rawEnvelope: $payload);
    }

    /**
     * @param  array<mixed>  $payload
     */
    private function githubActor(?string $actorId, array $payload): Actor
    {
        if ($actorId === null || ! is_numeric($actorId)) {
            return new Actor(id: $actorId, name: null, isKnownAgent: false, rawEnvelope: $payload);
        }

        $id = (int) $actorId;
        $this->warnLoginDrift($id, $payload);

        // Shared account → can't attribute to one agent; defer to the classifier.
        if (isset($this->sharedGithubIds[$id])) {
            return new Actor(id: $actorId, name: null, isKnownAgent: false, rawEnvelope: $payload);
        }

        $reg = $this->byGithubUserId($id);

        return new Actor(id: $actorId, name: $reg?->name, isKnownAgent: $reg !== null, rawEnvelope: $payload);
    }

    /**
     * Warn (once per drifted login) when the username on an incoming GitHub
     * event no longer matches the login configured for that immutable account
     * id — so a rename surfaces as an actionable log line instead of silent
     * stale display. Recognition is unaffected (it keys on the id).
     *
     * @param  array<mixed>  $payload
     */
    private function warnLoginDrift(int $id, array $payload): void
    {
        $configured = $this->driftLogins[$id] ?? null;
        if ($configured === null) {
            return;
        }

        $sender = $payload['sender'] ?? null;
        $current = is_array($sender) && isset($sender['login']) && is_scalar($sender['login'])
            ? (string) $sender['login']
            : null;
        if ($current === null || $current === $configured) {
            return;
        }

        $guard = $id.':'.$current;
        if (isset($this->driftWarned[$guard])) {
            return;
        }
        $this->driftWarned[$guard] = true;

        Log::warning(sprintf(
            'agent registry: configured github_login "%s" is stale; account %d is now "%s". '.
            'Recognition is unaffected (it keys on github_user_id), but update github_login for correct display.',
            $configured,
            $id,
            $current,
        ));
    }
}
