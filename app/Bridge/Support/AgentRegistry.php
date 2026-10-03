<?php

namespace App\Bridge\Support;

use App\Bridge\Dispatch\Actor;
use App\Bridge\Exceptions\CoordRosterUnreadableException;
use App\Bridge\Exceptions\UnreadableFileException;
use Closure;
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
 *   - per-agent kanban user id / `identity.github_user_id` → one account, one
 *     agent (attribution sets Actor.name). The kanban id is the COORD ROSTER's
 *     (DL-450, {@see AgentKanbanUsers}), not a YAML key.
 *   - `shared_identities[]` → one account, many agents. Attribution can't pick
 *     one, so Actor.name stays null and a custom classifier re-attributes
 *     (DL-002 / DL-005).
 *
 * Accidental collisions on a per-agent axis (the same id on two agents) are
 * detected when that axis is built and bypassed (Actor.name null, raw id
 * surfaces) rather than mis-attributing; a warning names the sharing agents.
 *
 * ⭐ THE KANBAN AXIS IS BUILT ON FIRST USE when its ids come from the roster
 * ({@see fromAgentConfigs} with no map). Only a kanban event asks it, so a github
 * delivery never reads the roster and never fails on it; a kanban event that
 * needs it on an install whose roster cannot be read THROWS
 * {@see CoordRosterUnreadableException}, and the delivery answers 5xx (DL-450).
 */
final class AgentRegistry
{
    /** @var array<int, RegisteredAgent>|null null until the kanban axis is first asked for */
    private ?array $byKanbanUid = null;

    /** @var array<string, int>|null agent name → kanban user id, collided ids included */
    private ?array $kanbanUserIds = null;

    /** @var (Closure(): array<string, int>)|null where the map comes from when it is read on first use */
    private ?Closure $kanbanSource = null;

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

    /** @var list<string> id-collision warnings accumulated at construction, for bridge:check to surface */
    private array $collisions = [];

    /**
     * @param  list<RegisteredAgent>  $agents
     * @param  list<SharedIdentity>  $sharedIdentities
     * @param  array<string, int>|(Closure(): array<string, int>)  $kanbanUserIds  agent name → kanban
     *                                                                             user id, or a source of
     *                                                                             that map read on first use
     */
    public function __construct(private array $agents, array $sharedIdentities = [], array|Closure $kanbanUserIds = [])
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

        if ($kanbanUserIds instanceof Closure) {
            $this->kanbanSource = $kanbanUserIds;
        } else {
            $this->buildKanbanAxis($kanbanUserIds);
        }
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
     * own github ids) plus the shared-identities declaration, and each agent's
     * kanban user id.
     *
     * @param  list<AgentConfig>  $configs
     * @param  list<SharedIdentity>  $sharedIdentities
     * @param  ?array<string, int>  $kanbanUserIds  null ⇒ the RUNTIME source: the coord roster,
     *                                              read on the first kanban lookup and throwing
     *                                              there when it cannot be read. A map ⇒ exactly
     *                                              those ids — `bridge:check` passes the ones it
     *                                              could read, and reports the roster itself.
     */
    public static function fromAgentConfigs(array $configs, array $sharedIdentities = [], ?array $kanbanUserIds = null): self
    {
        $agents = array_map(
            fn (AgentConfig $c): RegisteredAgent => new RegisteredAgent(
                name: $c->agentName,
                githubUserId: $c->identity->githubUserId,
                githubLogin: $c->identity->githubLogin,
            ),
            $configs,
        );

        return new self($agents, $sharedIdentities, $kanbanUserIds ?? static fn (): array => AgentKanbanUsers::of($configs)->ids());
    }

    /**
     * @param  array<string, int>  $ids
     */
    private function buildKanbanAxis(array $ids): void
    {
        $this->kanbanUserIds = $ids;
        $this->byKanbanUid = $this->buildIntLookup(
            fn (RegisteredAgent $a) => $ids[$a->name] ?? null,
            'kanban_user_id',
            'Each agent\'s kanban user id is its coord roster seat\'s (identity.coord_seat, else the agent name), so two agents resolving to one id are two agents on one seat, or two seats the roster gives one id: keep one bridge agent per seat, and one kanban user per seat.',
        );
    }

    /**
     * @return array<int, RegisteredAgent>
     *
     * @throws CoordRosterUnreadableException
     */
    private function kanbanLookup(): array
    {
        if ($this->byKanbanUid === null) {
            $source = $this->kanbanSource;
            $this->buildKanbanAxis($source === null ? [] : $source());
        }

        return $this->byKanbanUid ?? [];
    }

    /**
     * The kanban user id the given agent has — INCLUDING an id another agent shares, which
     * {@see byKanbanUserId} deliberately resolves to nobody. Self echo-suppression wants this
     * raw form: an id two agents share is still each one's own write.
     *
     * @throws CoordRosterUnreadableException when the roster is the source and cannot be read
     */
    public function kanbanUserIdOf(string $name): ?int
    {
        $this->kanbanLookup();

        return $this->kanbanUserIds[$name] ?? null;
    }

    /**
     * THE read of the optional shared-identities.json — every consumer is served from
     * one call per run (card#5546). Missing → none. Malformed → none, with a warning
     * (it's a declared-once policy file, not load-bearing for routing — a corrupt one
     * degrades to "no shared accounts", which surfaces the raw id rather than
     * mis-attributing).
     *
     * IT LOGS, so calling it twice is not free: a second call re-emits the permissions
     * warning, the not-an-object warning, and one line per wrongly-shaped entry. Callers
     * that need both the list and the state read once and pass the result along.
     */
    public static function readSharedIdentities(string $configDir): SharedIdentitiesFile
    {
        $path = rtrim($configDir, '/').'/shared-identities.json';
        try {
            $contents = FileContents::read($path, 'shared-identities.json');
        } catch (UnreadableFileException $e) {
            // The ONE site in card#5789's class that degrades rather than propagates, and the
            // fail-soft contract above is why: this loader's other caller is the receiver,
            // which must not 5xx over an optional policy file. It already answered [] for a
            // file it could not PARSE; answering [] for one it could not READ is the same
            // ruling reaching the same state. What makes that safe for a preflight report is
            // the STATE recorded here: SharedIdentitiesCheck consumes it and pronounces the
            // operator-facing discrimination on it (DL-259), instead of reading the file again.
            Log::warning($e->getMessage().' — ignoring it; agents sharing an account lose their attribution');

            return SharedIdentitiesFile::unreadable($path);
        }
        if ($contents === null) {
            return SharedIdentitiesFile::absent($path);
        }

        $raw = json_decode($contents, true);
        if (! is_array($raw)) {
            Log::warning("shared-identities.json at {$path} is not valid JSON / not an object; ignoring it");

            return SharedIdentitiesFile::malformed($path);
        }

        return SharedIdentitiesFile::parsed($path, self::parseSharedIdentities($raw['shared_identities'] ?? [], $path));
    }

    /**
     * The fail-soft list every RUNTIME caller wants: the states are collapsed onto the
     * empty list they have always answered, so this contract is unchanged. A caller that
     * must tell those states apart reads {@see self::readSharedIdentities()} instead.
     *
     * @return list<SharedIdentity>
     */
    public static function loadSharedIdentities(string $configDir): array
    {
        return self::readSharedIdentities($configDir)->identities;
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
            $message = sprintf(
                'agent registry: %s %s is shared by multiple agents (%s); attribution '.
                'will be bypassed for events from this identity — Actor.name will be null '.
                'and the raw id surfaces. %s',
                $axis,
                (string) $value,
                implode(', ', $shared),
                $guidance,
            );
            $this->collisions[] = $message;
            Log::warning($message);
        }
    }

    /**
     * Id-collision warnings accumulated as each axis was built (empty when every
     * kanban/github id is distinct); asking for them builds a deferred kanban axis.
     * bridge:check renders these to the operator console — they otherwise only reach
     * the log, where a silent mis-attribution misconfig goes unnoticed.
     *
     * @return list<string>
     */
    public function collisions(): array
    {
        $this->kanbanLookup();

        return $this->collisions;
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
            $agentNames = array_values(array_filter(StringList::coerce($s['agents'] ?? null)));
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
        $uid = self::numericUid($uid);

        return $uid === null ? null : ($this->kanbanLookup()[$uid] ?? null);
    }

    public function byGithubUserId(int|string|null $uid): ?RegisteredAgent
    {
        $uid = self::numericUid($uid);

        return $uid === null ? null : ($this->byGithubUid[$uid] ?? null);
    }

    /**
     * Normalize a raw actor id to the immutable integer key the lookups use, or
     * null when it is absent / non-numeric (so a non-numeric id never `(int)`-
     * coerces to 0 and false-matches an agent). The single guard for every
     * numeric-id lookup on this registry.
     */
    private static function numericUid(int|string|null $uid): ?int
    {
        return ($uid !== null && is_numeric($uid)) ? (int) $uid : null;
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
        $id = self::numericUid($id);

        return $id !== null && isset($this->sharedGithubIds[$id]);
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
     * is provider-aware: kanban events match the roster's kanban user ids, GitHub events
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
        $id = self::numericUid($actorId);
        if ($id === null) {
            return new Actor(id: $actorId, name: null, isKnownAgent: false, rawEnvelope: $payload);
        }

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
