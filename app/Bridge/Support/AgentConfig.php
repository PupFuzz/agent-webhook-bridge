<?php

namespace App\Bridge\Support;

use App\Bridge\Classifiers\InboxOnlyClassifier;
use App\Bridge\Exceptions\ConfigException;
use App\Bridge\Validation\ChannelName;
use App\Bridge\Validation\SocketPath;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * A parsed per-agent config (<config_dir>/<agent>.yml). v0.12 adaptation of
 * the v0.11 shape: the `db` and `secrets.base_dir` sections are gone — the DB
 * connection and the HMAC secret dir now come from Laravel's .env /
 * config/bridge.php (one install per agent). Those keys are still TOLERATED in
 * a YAML (no warning) so migrating configs load cleanly; their values are
 * ignored. `classifier.module` (a Python path) becomes `classifier.class` (a
 * PHP FQCN). Everything else (identity / api / receiver / subscriptions /
 * echo_suppression / channel / surface) is preserved.
 */
final class AgentConfig
{
    /**
     * Recognized top-level keys. `db`/`secrets` are retained (ignored) so a
     * migrated v0.11 YAML doesn't trip the unknown-key warning.
     *
     * @var list<string>
     */
    private const KNOWN_TOP_LEVEL_KEYS = [
        'identity', 'api', 'receiver', 'subscriptions', 'echo_suppression',
        'db', 'secrets', 'surface', 'classifier', 'channel',
    ];

    /**
     * @param  array<string, ProviderApiConfig>  $api
     * @param  list<SubscriptionConfig>  $subscriptions
     * @param  array<mixed>  $raw
     */
    public function __construct(
        public readonly string $agentName,
        public readonly string $selfIdentity,
        public readonly array $api,
        public readonly string $receiverBaseUrl,
        public readonly array $subscriptions,
        public readonly EchoSuppressionConfig $echoSuppression,
        public readonly string $classifierClass,
        public readonly string $channelName,
        public readonly ?string $channelSocket,
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

        $identity = self::section($raw, 'identity');
        $selfIdentity = $identity['self'] ?? null;
        if (! is_string($selfIdentity) || $selfIdentity === '') {
            throw new ConfigException(
                'identity.self must be a non-empty string (set it to the agent name as it appears in agents.json)'
            );
        }

        $apiRaw = self::section($raw, 'api');
        if ($apiRaw === []) {
            throw new ConfigException('api section is required with at least one provider configured');
        }
        $api = [];
        foreach ($apiRaw as $name => $cfg) {
            $api[(string) $name] = ProviderApiConfig::fromArray((string) $name, is_array($cfg) ? $cfg : []);
        }

        $receiver = self::section($raw, 'receiver');
        $receiverBaseUrl = rtrim(self::validateUrl($receiver['base_url'] ?? null, 'receiver.base_url'), '/');

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

        $echo = EchoSuppressionConfig::fromArray(self::section($raw, 'echo_suppression'));

        $surface = self::requireMapping($raw, 'surface');
        $silentDrop = $surface['silent_drop_warnings'] ?? true;
        if (! is_bool($silentDrop)) {
            throw new ConfigException('surface.silent_drop_warnings must be a boolean');
        }

        $classifierClass = self::resolveClassifierClass(self::requireMapping($raw, 'classifier'));

        [$channelName, $channelSocket] = self::resolveChannel(self::requireMapping($raw, 'channel'), $selfIdentity);

        return new self(
            agentName: $agentName,
            selfIdentity: $selfIdentity,
            api: $api,
            receiverBaseUrl: $receiverBaseUrl,
            subscriptions: $subscriptions,
            echoSuppression: $echo,
            classifierClass: $classifierClass,
            channelName: $channelName,
            channelSocket: $channelSocket,
            surfaceSilentDropWarnings: $silentDrop,
            raw: $raw,
        );
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
     * @return array{string, ?string}
     */
    private static function resolveChannel(array $channel, string $selfIdentity): array
    {
        $explicitName = $channel['name'] ?? null;
        if ($explicitName !== null) {
            $name = is_scalar($explicitName) ? (string) $explicitName : '';
            if (! ChannelName::matches($name)) {
                throw new ConfigException("channel.name '{$name}' must be lowercase letters/digits/underscore/hyphen, non-empty");
            }
            $channelName = $name;
        } else {
            // Fall back to identity.self UNVALIDATED — lazily validated only if
            // a default socket path is derived at dispatch time.
            $channelName = $selfIdentity;
        }

        $socket = $channel['socket'] ?? null;
        if ($socket !== null) {
            $socketStr = is_scalar($socket) ? (string) $socket : '';
            if (! SocketPath::isValid($socketStr)) {
                throw new ConfigException("channel.socket '{$socketStr}' must be an absolute path with no '..' segment or null byte");
            }

            return [$channelName, $socketStr];
        }

        return [$channelName, null];
    }

    private static function validateUrl(mixed $value, string $field): string
    {
        if (! is_string($value) || $value === '') {
            throw new ConfigException("{$field} must be a non-empty string URL");
        }
        if (preg_match('/\s/', $value) === 1) {
            throw new ConfigException("{$field} '{$value}' contains whitespace; check for paste errors");
        }
        $parts = parse_url($value);
        if ($parts === false) {
            throw new ConfigException("{$field} '{$value}' is not a valid URL");
        }
        if (! in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
            throw new ConfigException("{$field} '{$value}' must use http or https");
        }
        if (($parts['host'] ?? '') === '') {
            throw new ConfigException("{$field} '{$value}' must have a host component");
        }

        return $value;
    }

    /**
     * Read a top-level section as an array, defaulting to empty (tolerant —
     * a non-mapping value is coerced to empty). Used for sections the loader
     * accesses field-by-field with its own per-field validation.
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
     * Read a top-level section that MUST be a mapping when present. An absent
     * or null section defaults to empty; a present non-mapping value (e.g. a
     * scalar from `classifier: SomeName` instead of `classifier: {class: ...}`)
     * is a malformed config and throws rather than silently degrading to
     * defaults. Mirrors lib/config.py's `isinstance(dict)` checks for the
     * surface/classifier/channel sections.
     *
     * @param  array<mixed>  $raw
     * @return array<mixed>
     */
    private static function requireMapping(array $raw, string $key): array
    {
        $value = $raw[$key] ?? null;
        if ($value === null) {
            return [];
        }
        if (! is_array($value)) {
            throw new ConfigException("{$key} must be a mapping");
        }

        return $value;
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
