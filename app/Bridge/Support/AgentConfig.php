<?php

namespace App\Bridge\Support;

use App\Bridge\Classifiers\InboxOnlyClassifier;
use App\Bridge\Exceptions\ConfigException;
use App\Bridge\Validation\SocketPath;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * A parsed per-agent config (`<config_dir>/<agent>.yml`). The FILENAME is the
 * canonical agent name — and the agent's echo "self" — so there is no
 * `identity.self` (that was a denormalization that could silently drift from the
 * filename and break echo suppression). Per-INSTALL settings live in
 * `config/bridge.php`, NOT here: the receiver base URL and each provider's API
 * base URL are the same for every agent on an install. The API token is read by
 * convention from `<secret_dir>/<provider>/token` (override per agent with
 * `api.<provider>.token_path` only when an agent authenticates as a distinct
 * account).
 *
 * Sections: `identity` (the agent's own immutable upstream ids — they build the
 * AgentRegistry and auto-seed self echo-suppression), `subscriptions`,
 * `echo_suppression` (lists OTHER agents only; self is derived from the filename
 * + identity ids), `classifier`, `channel`, `surface`.
 */
final class AgentConfig
{
    /**
     * @var list<string>
     */
    private const KNOWN_TOP_LEVEL_KEYS = [
        'identity', 'subscriptions', 'echo_suppression', 'surface', 'classifier', 'channel', 'api',
    ];

    /**
     * @param  list<SubscriptionConfig>  $subscriptions
     * @param  array<string, string>  $tokenPathOverrides  provider => explicit token path (overrides the convention)
     * @param  array<mixed>  $raw
     */
    public function __construct(
        public readonly string $agentName,
        public readonly IdentityConfig $identity,
        public readonly array $subscriptions,
        public readonly EchoSuppressionConfig $echoSuppression,
        public readonly string $classifierClass,
        public readonly ClassifierConfig $classifierConfig,
        public readonly ChannelConfig $channel,
        public readonly array $tokenPathOverrides,
        public readonly bool $surfaceSilentDropWarnings,
        public readonly array $raw,
    ) {}

    public static function load(string $agentName, string $configDir): self
    {
        $path = rtrim($configDir, '/')."/{$agentName}.yml";
        if (! is_file($path)) {
            throw new ConfigException("config file not found: {$path}");
        }

        try {
            $parsed = Yaml::parseFile($path);
        } catch (ParseException $e) {
            throw new ConfigException("config file {$path} is not valid YAML: {$e->getMessage()}");
        }

        if ($parsed === null) {
            $parsed = [];
        }
        if (! is_array($parsed)) {
            throw new ConfigException("config must be a YAML mapping at top level; got {$path}");
        }

        return self::fromArray($agentName, $parsed);
    }

    /**
     * @param  array<mixed>  $raw
     */
    public static function fromArray(string $agentName, array $raw): self
    {
        self::warnUnknownTopLevelKeys($raw, $agentName);

        $identity = IdentityConfig::fromArray(self::section($raw, 'identity'));

        $subsRaw = $raw['subscriptions'] ?? [];
        if (! is_array($subsRaw)) {
            throw new ConfigException('subscriptions must be a list');
        }
        $subscriptions = [];
        foreach (array_values($subsRaw) as $idx => $entry) {
            if (! is_array($entry)) {
                throw new ConfigException("subscriptions[{$idx}]: entry must be a mapping");
            }
            try {
                array_push($subscriptions, ...SubscriptionConfig::expand($entry));
            } catch (ConfigException $e) {
                throw new ConfigException("subscriptions[{$idx}]: {$e->getMessage()}");
            }
        }

        // Self echo-suppression is DERIVED — the agent's own identity ids are
        // auto-seeded into the echo-id set (no hand-listed treat_as_echo_ids of
        // self, which drifts), and self-by-name is the filename (agentName).
        // treat_as_echo / treat_as_signal name OTHER agents only.
        $echoRaw = EchoSuppressionConfig::fromArray(self::section($raw, 'echo_suppression'));
        $echo = new EchoSuppressionConfig(
            treatAsEcho: $echoRaw->treatAsEcho,
            treatAsSignal: $echoRaw->treatAsSignal,
            treatAsEchoIds: array_values(array_unique([...$echoRaw->treatAsEchoIds, ...$identity->selfIds()])),
        );

        $surface = self::requireMapping($raw, 'surface');
        $silentDrop = $surface['silent_drop_warnings'] ?? true;
        if (! is_bool($silentDrop)) {
            throw new ConfigException('surface.silent_drop_warnings must be a boolean');
        }

        $classifierSection = self::requireMapping($raw, 'classifier');
        $classifierClass = self::resolveClassifierClass($classifierSection);
        $classifierConfig = ClassifierConfig::fromClassifierSection($classifierSection);

        $channel = self::resolveChannel(self::requireMapping($raw, 'channel'));

        return new self(
            agentName: $agentName,
            identity: $identity,
            subscriptions: $subscriptions,
            echoSuppression: $echo,
            classifierClass: $classifierClass,
            classifierConfig: $classifierConfig,
            channel: $channel,
            tokenPathOverrides: self::resolveTokenOverrides(self::section($raw, 'api')),
            surfaceSilentDropWarnings: $silentDrop,
            raw: $raw,
        );
    }

    /**
     * The API token path for a provider: the per-agent override if set, else the
     * `<secret_dir>/<provider>/token` convention.
     */
    public function tokenPath(string $secretDir, string $provider): string
    {
        return $this->tokenPathOverrides[$provider] ?? TokenPath::for($secretDir, $provider);
    }

    /**
     * @param  array<mixed>  $api
     * @return array<string, string>
     */
    private static function resolveTokenOverrides(array $api): array
    {
        $overrides = [];
        foreach ($api as $provider => $cfg) {
            if (is_array($cfg) && isset($cfg['token_path']) && is_string($cfg['token_path']) && $cfg['token_path'] !== '') {
                $overrides[(string) $provider] = PathHelper::expandUser($cfg['token_path']);
            }
        }

        return $overrides;
    }

    /**
     * @param  array<mixed>  $classifier
     */
    private static function resolveClassifierClass(array $classifier): string
    {
        $class = $classifier['class'] ?? InboxOnlyClassifier::class;
        if (! is_string($class) || $class === '') {
            throw new ConfigException('classifier.class must be a non-empty string FQCN');
        }

        return ltrim($class, '\\');
    }

    /**
     * @param  array<mixed>  $channel
     */
    private static function resolveChannel(array $channel): ChannelConfig
    {
        $socketStr = null;
        $socket = $channel['socket'] ?? null;
        if ($socket !== null) {
            $socketStr = is_scalar($socket) ? (string) $socket : '';
            // Expand ${XDG_RUNTIME_DIR}/${uid} first (DL-039) so a uid-agnostic
            // literal resolves to the concrete path the rest of the pipeline
            // validates + stores — the uid drops out of config, surviving a
            // host/uid restore. Throws on an unresolvable token (fail-closed).
            $socketStr = PathHelper::expandRuntimeTokens($socketStr);
            if (! SocketPath::isValid($socketStr)) {
                throw new ConfigException("channel.socket '{$socketStr}' must be an absolute path with no '..' segment or null byte");
            }
        }

        // channel.url — for the SSH-tunneled remote-host case (multi-host.md).
        // Shape-validated here; the loopback/SSRF check is the channel_push
        // handler's (single source of truth). Mutually exclusive with socket.
        $urlStr = null;
        $url = $channel['url'] ?? null;
        if ($url !== null) {
            $urlStr = is_scalar($url) ? (string) $url : '';
            if ($urlStr === '' || preg_match('/\s/', $urlStr) === 1) {
                throw new ConfigException("channel.url '{$urlStr}' must be a non-empty URL with no whitespace");
            }
        }
        if ($socketStr !== null && $urlStr !== null) {
            throw new ConfigException('channel.socket and channel.url are mutually exclusive — set exactly one');
        }

        // channel.route_intents: the dispatcher auto-pushes every staged intent
        // to this agent's channel (the config-driven form of EventDrivenClassifier).
        $routeIntents = $channel['route_intents'] ?? false;
        if (! is_bool($routeIntents)) {
            throw new ConfigException('channel.route_intents must be a boolean');
        }
        if ($routeIntents && $socketStr === null && $urlStr === null) {
            throw new ConfigException('channel.route_intents requires channel.socket or channel.url (nowhere to route)');
        }

        // channel.auth.token_path (DL-008): file holding the Bearer token the
        // dispatcher attaches on a routed push over the HTTP transport (the
        // cross-user / SSH-tunnel case where loopback-bind is not the trust
        // boundary). Nested under `auth` so a future non-Bearer scheme is an
        // additive key, not a breaking rename. Shape-validated here; the file is
        // read fail-closed (incl. a 0600 perms check) at push time by
        // ChannelToken, the single source of truth.
        $tokenPath = null;
        $auth = $channel['auth'] ?? null;
        if ($auth !== null) {
            if (! is_array($auth)) {
                throw new ConfigException('channel.auth must be a mapping');
            }
            $raw = $auth['token_path'] ?? null;
            if ($raw !== null) {
                if (! is_string($raw) || $raw === '') {
                    throw new ConfigException('channel.auth.token_path must be a non-empty path');
                }
                $tokenPath = PathHelper::expandUser($raw);
            }
        }
        // A Bearer token is meaningful only on the HTTP transport (channel.url);
        // a UDS channel's trust boundary is its 0600 filesystem perms. Reject the
        // socket+token (or token-without-endpoint) combo rather than silently
        // ignore it — fail-closed config posture, and matches the docs.
        if ($tokenPath !== null && $urlStr === null) {
            throw new ConfigException('channel.auth.token_path requires the HTTP transport (channel.url) — a UDS channel uses filesystem permissions, not a Bearer token');
        }

        return new ChannelConfig(
            socket: $socketStr,
            url: $urlStr,
            routeIntents: $routeIntents,
            tokenPath: $tokenPath,
        );
    }

    /**
     * Read a top-level section as an array, defaulting to empty (tolerant — a
     * non-mapping value is coerced to empty).
     *
     * @param  array<mixed>  $raw
     * @return array<mixed>
     */
    private static function section(array $raw, string $key): array
    {
        $value = $raw[$key] ?? [];

        return is_array($value) ? $value : [];
    }

    /**
     * Read a top-level section that MUST be a mapping when present. An absent or
     * null section defaults to empty; a present non-mapping value (e.g. a scalar
     * `classifier: SomeName`) is malformed and throws rather than degrading. The
     * message names the bare top-level key.
     *
     * @param  array<mixed>  $raw
     * @return array<mixed>
     */
    private static function requireMapping(array $raw, string $key): array
    {
        return ConfigMapping::require($raw, $key, $key);
    }

    /**
     * @param  array<mixed>  $raw
     */
    private static function warnUnknownTopLevelKeys(array $raw, string $agentName): void
    {
        foreach (array_keys($raw) as $key) {
            if (! in_array((string) $key, self::KNOWN_TOP_LEVEL_KEYS, true)) {
                Log::warning("unknown top-level key in {$agentName}.yml: '{$key}' — possible typo? Recognized: ".implode(', ', self::KNOWN_TOP_LEVEL_KEYS));
            }
        }
    }
}
