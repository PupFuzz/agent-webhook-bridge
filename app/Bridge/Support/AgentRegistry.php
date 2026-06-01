<?php

namespace App\Bridge\Support;

use App\Bridge\Dispatch\Actor;
use Illuminate\Support\Facades\Log;

/**
 * Translates a raw actor id into a friendly agent name. The roster is built by
 * SCANNING the per-agent YAMLs (each declares its own immutable `identity` ids)
 * — there is no separate agents.json roster (it duplicated the YAMLs). The only
 * separate file is `shared-identities.json`, declaring upstream accounts shared
 * by several agents (absent when none).
 *
 * Recognition keys on IMMUTABLE numeric ids only — kanban `user_id` and GitHub
 * `sender.id`. GitHub usernames are renameable, so `github_login` is a
 * display-only label, never a matching key (DL-002). Matching is provider-aware:
 * a kanban `user_id` and a github `sender.id` that are the same integer never
 * cross-match.
 *
 * Two ways an account maps to agents:
 *   - per-agent `identity.kanban_user_id` / `github_user_id` → one account, one
 *     agent (attribution sets Actor.name).
 *   - `shared_identities[]` → one account, many agents. Attribution can't pick
 *     one, so Actor.name stays null and a custom classifier re-attributes
 *     (DL-002 / DL-005).
 *
 * Accidental collisions on a per-agent axis (the same id on two agents) are
 * detected at construction and bypassed (Actor.name null, raw id surfaces)
 * rather than mis-attributing; a warning names the sharing agents.
 */
final class AgentRegistry
{
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
                        '(no %s.yml); the reference is ignored.',
                        $s->githubUserId,
                        $name,
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
            'Give each agent a distinct identity.kanban_user_id.',
        );
        // A github_user_id declared shared takes precedence over a per-agent
        // entry carrying the same id — exclude it from the unique lookup so the
        // shared bypass wins deterministically.
        $this->byGithubUid = $this->buildIntLookup(
            fn (RegisteredAgent $a) => isset($this->sharedGithubIds[$a->githubUserId]) ? null : $a->githubUserId,
            'github_user_id',
            'Give each agent a distinct identity.github_user_id, or declare the shared account once in shared-identities.json.',
        );
        foreach ($agents as $a) {
            if ($a->githubUserId !== null && $a->githubLogin !== null && ! isset($this->driftLogins[$a->githubUserId])) {
                $this->driftLogins[$a->githubUserId] = $a->githubLogin;
            }
        }
    }

    /**
     * Build the registry from the scanned per-agent configs (each carrying its
     * own identity ids) plus the shared-identities declaration.
     *
     * @param  list<AgentConfig>  $configs
     * @param  list<SharedIdentity>  $sharedIdentities
     */
    public static function fromAgentConfigs(array $configs, array $sharedIdentities = []): self
    {
        $agents = array_map(
            fn (AgentConfig $c): RegisteredAgent => new RegisteredAgent(
                name: $c->agentName,
                kanbanUserId: $c->identity->kanbanUserId,
                githubUserId: $c->identity->githubUserId,
                githubLogin: $c->identity->githubLogin,
            ),
            $configs,
        );

        return new self($agents, $sharedIdentities);
    }

    /**
     * Read the optional shared-identities.json from the config dir. Missing →
     * none. Malformed → none, with a warning (it's a declared-once policy file,
     * not load-bearing for routing — a corrupt one degrades to "no shared
     * accounts", which surfaces the raw id rather than mis-attributing).
     *
     * @return list<SharedIdentity>
     */
    public static function loadSharedIdentities(string $configDir): array
    {
        $path = rtrim($configDir, '/').'/shared-identities.json';
        if (! is_file($path)) {
            return [];
        }

        $raw = json_decode((string) file_get_contents($path), true);
        if (! is_array($raw)) {
            Log::warning("shared-identities.json at {$path} is not valid JSON / not an object; ignoring it");

            return [];
        }

        return self::parseSharedIdentities($raw['shared_identities'] ?? [], $path);
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
                Log::warning("shared-identities.json at {$path} has an entry without a numeric github_user_id; skipping it");

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
     * Is this a github account declared shared (shared-identities.json)? The
     * pre-classify echo gate uses this to NOT wholesale-suppress a shared
     * account's events from an auto-seeded self id — they must reach classify so
     * the DL-005 re-attribution can decide per agent (DL-007).
     */
    public function isSharedGithubId(int|string|null $id): bool
    {
        return $id !== null && is_numeric($id) && isset($this->sharedGithubIds[(int) $id]);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->byName);
    }

    /**
     * Build an Actor from a verified event's actor_id + parsed payload. Matching
     * is provider-aware: kanban events match `kanban_user_id`, GitHub events
     * match the immutable `github_user_id`. A GitHub account in shared_identities
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
     * Warn (once per drifted login) when an incoming GitHub event's username no
     * longer matches the login configured for that immutable account id.
     * Recognition is unaffected (it keys on the id).
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
