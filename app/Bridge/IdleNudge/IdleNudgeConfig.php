<?php

namespace App\Bridge\IdleNudge;

use App\Bridge\Exceptions\ConfigException;
use App\Bridge\Support\PathHelper;
use App\Bridge\Support\UrlValidator;

/**
 * The idle nudge's resolved configuration (card#9422 / DL-380), read once and validated once,
 * so the handler that acts on it and the `bridge:check` leg that reports it cannot disagree
 * about what the install asked for — the `StandupConfig` / `JobsConfig` shape.
 *
 * ⛔ A NUMBER OUTSIDE ITS BOUND IS REFUSED, NEVER CLAMPED (the `JobsConfig` rule): clamping
 * turns a typo into a silently different install.
 *
 * ⛔ THE INSTALL ID IS REQUIRED. Mezzanine's fleet token reads the WHOLE fleet, and agent names
 * recur across installs; with no install to filter on, a foreign seat named like a local agent
 * would nudge the local seat on somebody else's idleness.
 */
final class IdleNudgeConfig
{
    public const TIMEOUT_MIN = 1;

    public const TIMEOUT_MAX = 30;

    public const DEFAULT_AFTER_MIN = 120;

    public const DEFAULT_AFTER_MAX = 86400;

    private function __construct(
        public readonly bool $enabled,
        public readonly ?string $baseUrl,
        public readonly ?string $tokenPath,
        public readonly ?string $install,
        public readonly int $timeoutS,
        public readonly int $defaultAfterS,
        public readonly ?string $problem,
    ) {}

    public static function fromConfig(): self
    {
        $enabled = (bool) config('bridge.idle_nudge.enabled');
        $install = self::nonEmptyString(config('bridge.idle_nudge.install'));
        $rawToken = self::nonEmptyString(config('bridge.idle_nudge.token_path'));
        $timeout = self::positiveInt(config('bridge.idle_nudge.timeout'));
        $defaultAfter = self::positiveInt(config('bridge.idle_nudge.default_after'));

        $baseUrl = null;
        $problem = null;
        if ($enabled) {
            try {
                $baseUrl = rtrim(UrlValidator::secureHttpUrl(config('bridge.idle_nudge.base_url'), 'BRIDGE_IDLE_NUDGE_BASE_URL'), '/');
            } catch (ConfigException $e) {
                // UrlValidator composes its message through SecretScrubber::url().
                $problem = $e->getMessage();
            }

            $problem ??= match (true) {
                $install === null => 'BRIDGE_IDLE_NUDGE_INSTALL is unset — it is REQUIRED: the fleet token reads every install, and without the install id this bridge serves, a seat of another install named like a local agent would be nudged here',
                $rawToken === null => 'BRIDGE_IDLE_NUDGE_TOKEN_PATH is unset — the fleet_read token is read from a FILE, never inline',
                $timeout === null || $timeout < self::TIMEOUT_MIN || $timeout > self::TIMEOUT_MAX => 'BRIDGE_IDLE_NUDGE_TIMEOUT must be a whole number of seconds in '.self::TIMEOUT_MIN.'…'.self::TIMEOUT_MAX.' (refused, not clamped)',
                $defaultAfter === null || $defaultAfter < self::DEFAULT_AFTER_MIN || $defaultAfter > self::DEFAULT_AFTER_MAX => 'BRIDGE_IDLE_NUDGE_DEFAULT_AFTER must be a whole number of seconds in '.self::DEFAULT_AFTER_MIN.'…'.self::DEFAULT_AFTER_MAX.' (refused, not clamped)',
                default => null,
            };
        }

        return new self(
            enabled: $enabled,
            baseUrl: $baseUrl,
            tokenPath: $rawToken === null ? null : PathHelper::expandUser($rawToken),
            install: $install,
            timeoutS: $timeout ?? 0,
            defaultAfterS: $defaultAfter ?? 0,
            problem: $problem,
        );
    }

    private static function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function positiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && ctype_digit(trim($value)) ? (int) trim($value) : null;
    }
}
