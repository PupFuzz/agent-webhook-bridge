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
 *
 * ⚑ `problem` IS ABOUT THE MEZZANINE KEYS ONLY, and binds only when some agent needs Mezzanine
 * ({@see IdleNudgeSources::mezzanineNeeded()}): an install whose agents are all seat-record-
 * sourced (rt#562) needs nothing else. The value is computed either way so the two readers ask
 * the same question of the same object.
 *
 * ⭐ UNSET IS NOT INVALID (card#10918 / DL-441). The nudge is ON by default, so a required key
 * nobody set is the NOT-SET-UP state of every install with a Mezzanine-sourced agent, where a
 * value somebody set wrongly is a broken config. `problem` covers both — the job cannot read the
 * fleet either way — and `unsetKeys` says which of them it is, so the preflight can `warn` on
 * the first and keep `fail` for the second. An invalid value outranks an unset one: it is
 * named FIRST in `problem` and it alone decides the severity (`unsetKeys` stays empty, so the
 * preflight `fail`s), because it is the one an operator already acted on — but every unset key
 * is still named after it, so fixing the invalid value does not reveal them one run later.
 *
 * ⛔ "NOBODY SET IT" MEANS NOBODY TOUCHED `BRIDGE_IDLE_NUDGE_ENABLED` AT ALL, NOT "IT READS
 * TRUE" (card#10918 / DL-441 review round 1). `enabled` alone cannot tell apart an install that
 * never wrote the key (the NOT-SET-UP state the default-on flip creates) from one that
 * EXPLICITLY wrote `BRIDGE_IDLE_NUDGE_ENABLED=true` and stopped short of the Mezzanine keys that
 * flag needs — the second is an operator who acted and left the job unusable, which is what
 * `unsetKeys` used to (and must again) treat as a `fail`, not a `warn` nobody is told to act on
 * urgently. `bridge.idle_nudge.enabled_explicit` is the `env()` read with no default — null
 * when the key is unset; otherwise a bool for `true`/`false` and the RAW STRING for every other
 * spelling (`1`, `yes`, `on` — `env()` casts only the `true`/`false` words) — and `unsetKeys` is
 * populated (routing the preflight to `warn`) only when it is null. Any value at all routes the
 * same unset keys into `problem` alone, so `unsetKeys` is empty and the preflight falls through
 * to its existing `fail` branch, exactly as it did before this entry.
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
        /**
         * The required Mezzanine keys nobody set, when that is the WHOLE of `problem`; empty when
         * `problem` names a value that was set wrongly, or is null.
         *
         * @var list<string>
         */
        public readonly array $unsetKeys,
    ) {}

    /**
     * The ONE enabled predicate, shared by the job and by the receiver's push-time stamp — which
     * installs pay for that stamp is stated once, on `DispatchService::stampPushAttempt()`.
     */
    public static function enabled(): bool
    {
        return (bool) config('bridge.idle_nudge.enabled');
    }

    public static function fromConfig(): self
    {
        $enabled = self::enabled();
        $explicit = config('bridge.idle_nudge.enabled_explicit');
        $install = self::nonEmptyString(config('bridge.idle_nudge.install'));
        $rawToken = self::nonEmptyString(config('bridge.idle_nudge.token_path'));
        $timeout = self::positiveInt(config('bridge.idle_nudge.timeout'));
        $defaultAfter = self::positiveInt(config('bridge.idle_nudge.default_after'));

        $baseUrl = null;
        $invalid = null;
        /** @var array<string, string> $unset key => why it is required */
        $unset = [];
        if ($enabled) {
            $rawBaseUrl = config('bridge.idle_nudge.base_url');
            if (self::nonEmptyString($rawBaseUrl) === null) {
                $unset['BRIDGE_IDLE_NUDGE_BASE_URL'] = 'BRIDGE_IDLE_NUDGE_BASE_URL is unset — the Mezzanine the fleet snapshot is read from';
            } else {
                try {
                    $baseUrl = rtrim(UrlValidator::secureHttpUrl($rawBaseUrl, 'BRIDGE_IDLE_NUDGE_BASE_URL'), '/');
                } catch (ConfigException $e) {
                    // UrlValidator composes its message through SecretScrubber::url().
                    $invalid = $e->getMessage();
                }
            }
            if ($install === null) {
                $unset['BRIDGE_IDLE_NUDGE_INSTALL'] = 'BRIDGE_IDLE_NUDGE_INSTALL is unset — it is REQUIRED: the fleet token reads every install, and without the install id this bridge serves, a seat of another install named like a local agent would be nudged here';
            }
            if ($rawToken === null) {
                $unset['BRIDGE_IDLE_NUDGE_TOKEN_PATH'] = 'BRIDGE_IDLE_NUDGE_TOKEN_PATH is unset — the fleet_read token is read from a FILE, never inline';
            }

            $invalid ??= match (true) {
                $timeout === null || $timeout < self::TIMEOUT_MIN || $timeout > self::TIMEOUT_MAX => 'BRIDGE_IDLE_NUDGE_TIMEOUT must be a whole number of seconds in '.self::TIMEOUT_MIN.'…'.self::TIMEOUT_MAX.' (refused, not clamped)',
                $defaultAfter === null || $defaultAfter < self::DEFAULT_AFTER_MIN || $defaultAfter > self::DEFAULT_AFTER_MAX => 'BRIDGE_IDLE_NUDGE_DEFAULT_AFTER must be a whole number of seconds in '.self::DEFAULT_AFTER_MIN.'…'.self::DEFAULT_AFTER_MAX.' (refused, not clamped)',
                default => null,
            };
        }
        $reasons = [...($invalid === null ? [] : [$invalid]), ...array_values($unset)];
        $problem = $reasons === [] ? null : implode('; ', $reasons);
        // Reached with `$unset` non-empty only while `enabled` is true, so a set key here is an
        // explicit enable in whatever spelling `env()` passed through — an operator who acted
        // and left the job unusable: a plain MISCONFIGURED `problem` (the preflight's `fail`).
        // Only null (nobody touched the key) keeps them in `unsetKeys` (the NOT-SET-UP `warn`).
        $unsetKeys = ($invalid === null && $explicit === null) ? array_keys($unset) : [];

        return new self(
            enabled: $enabled,
            baseUrl: $baseUrl,
            tokenPath: $rawToken === null ? null : PathHelper::expandUser($rawToken),
            install: $install,
            timeoutS: $timeout ?? 0,
            defaultAfterS: $defaultAfter ?? 0,
            problem: $problem,
            unsetKeys: $unsetKeys,
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
