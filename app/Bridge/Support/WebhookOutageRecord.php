<?php

namespace App\Bridge\Support;

use App\Bridge\Exceptions\UnreadableFileException;
use Closure;

/**
 * The webhook receiver's own record of an unbroken run of 5xx responses, kept in a state file
 * so it survives the failure it describes (card#10158).
 *
 * ⛔ WHY A FILE AND NOT A ROW. The outage this exists to surface is the database being
 * unreachable: every delivery 500s and writes no `webhook_events` row, so the one DB-backed
 * delivery-health instrument (`GitHubDeliveryHistoryCheck`) reads it as plain silence. The
 * failing deliveries are the watcher; nothing here touches the database, the cache or the
 * network, so it records a dead-DB outage as faithfully as any other 5xx cause.
 *
 * ⛔ BRIDGE VOCABULARY ONLY: status codes, counts and UTC timestamps. Never an exception
 * message, a response body or anything from the request — a connection error carries the DB
 * user and host, and `bridge:inbox` prints this record into every seat's context.
 *
 * ⚑ STATES. `failing` is present from the first 5xx until the next 2xx. That 2xx moves it to
 * `recovered` (replacing any earlier recovery) and `bridge:inbox` shows each consumer the
 * recovery once, within {@see self::NOTICE_WINDOW_SECONDS}. A 4xx is a refused request, not
 * evidence either way, and neither counts nor clears; the receiver's ping reply is a 2xx that
 * never reaches the dispatch path, so it is neutral too ({@see self::NEUTRAL_ATTRIBUTE}).
 *
 * ⚑ CONCURRENCY — WHAT IS GUARANTEED AND WHAT IS NOT.
 *  - The file is replaced by rename ({@see BridgePaths::writeFileAtomic()}), so an unlocked
 *    reader sees one complete record, never a torn one.
 *  - Every read-modify-write holds LOCK_EX on a sibling `.lock` file, so concurrent 5xx
 *    deliveries never lose an increment: `count` is exactly the number of 5xx outcomes
 *    recorded since the run began.
 *  - NOT guaranteed: that "consecutive" is a total order. Overlapping deliveries finish in
 *    whatever order they finish, and a 2xx whose unlocked peek ran just before a concurrent
 *    5xx created the record does not clear it — the next 2xx does. Both only matter while
 *    successes and failures are interleaving, which is not the outage this surfaces.
 */
final class WebhookOutageRecord
{
    public const FILE = 'webhook-5xx.json';

    /** The per-consumer "recovery already shown" cursor, written only by `bridge:inbox`. */
    public const NOTICE_SEEN_FILE = 'webhook-5xx-notice-seen.json';

    /**
     * How long after a recovery `bridge:inbox` still shows it to a consumer that has not seen
     * it. Bounded so a seat started weeks later is not handed a stale remedy as news.
     */
    public const NOTICE_WINDOW_SECONDS = 7 * 86400;

    /** Request attribute the receiver sets on a 2xx that proves nothing about processing. */
    public const NEUTRAL_ATTRIBUTE = 'bridge.outcome_neutral';

    public static function path(): string
    {
        return BridgePaths::stateDir().'/'.self::FILE;
    }

    public static function noticeSeenPath(): string
    {
        return BridgePaths::stateDir().'/'.self::NOTICE_SEEN_FILE;
    }

    /**
     * Record one webhook request's final status. Throws on a state-file fault; the caller on
     * the request path must contain that.
     */
    public static function observe(int $status, bool $neutral): void
    {
        if ($status >= 500) {
            self::recordFailure($status);
        } elseif ($status >= 200 && $status < 300 && ! $neutral) {
            self::recordSuccess();
        }
    }

    private static function recordFailure(int $status): void
    {
        self::locked(function () use ($status): void {
            $record = self::read() ?? ['failing' => null, 'recovered' => null];
            $now = self::now();
            $failing = $record['failing'];

            $record['failing'] = [
                'since' => $failing['since'] ?? $now,
                'last_at' => $now,
                'count' => ($failing['count'] ?? 0) + 1,
                'last_status' => $status,
            ];

            self::write($record);
        });
    }

    private static function recordSuccess(): void
    {
        // The common case — a healthy install — is decided by this one unlocked read of a
        // rename-replaced file, so a 2xx never waits on the lock unless there is a run to end.
        if ((self::read()['failing'] ?? null) === null) {
            return;
        }

        self::locked(function (): void {
            $record = self::read();
            $failing = $record['failing'] ?? null;
            if ($record === null || $failing === null) {
                return;   // a concurrent 2xx already ended the run
            }

            $record['recovered'] = [
                'since' => $failing['since'],
                'last_failure_at' => $failing['last_at'],
                'count' => $failing['count'],
                'last_status' => $failing['last_status'],
                'recovered_at' => self::now(),
            ];
            $record['failing'] = null;

            self::write($record);
        });
    }

    /**
     * The record, or null when none has been written. A present file whose content is not a
     * record this class wrote reads as null too: it is only ever replaced whole, so that is a
     * hand edit, and the next observation overwrites it.
     *
     * @return array{failing: array{since: string, last_at: string, count: int, last_status: int}|null, recovered: array{since: string, last_failure_at: string, count: int, last_status: int, recovered_at: string}|null}|null
     *
     * @throws UnreadableFileException
     */
    public static function read(): ?array
    {
        $raw = FileContents::read(self::path(), 'webhook 5xx record');
        if ($raw === null) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return null;
        }

        $failing = $decoded['failing'] ?? null;
        $recovered = $decoded['recovered'] ?? null;

        return [
            'failing' => self::shaped($failing, ['since', 'last_at'], ['count', 'last_status']) ? $failing : null,
            'recovered' => self::shaped($recovered, ['since', 'last_failure_at', 'recovered_at'], ['count', 'last_status']) ? $recovered : null,
        ];
    }

    /**
     * The id `bridge:inbox` marks a recovery seen under — one per run, so a later outage's
     * recovery is news again.
     *
     * @param  array{since: string, recovered_at: string}  $recovered
     */
    public static function noticeId(array $recovered): string
    {
        return 'webhook-5xx-recovered:'.$recovered['since'].'/'.$recovered['recovered_at'];
    }

    /**
     * Whether $recovered is still inside the window in which a consumer that has not seen it
     * is shown it.
     *
     * @param  array{recovered_at: string}  $recovered
     */
    public static function noticeIsCurrent(array $recovered): bool
    {
        $at = strtotime($recovered['recovered_at']);

        return $at !== false && now()->getTimestamp() - $at <= self::NOTICE_WINDOW_SECONDS;
    }

    public static function noticeSeenBy(string $consumer, string $noticeId): bool
    {
        return in_array(self::seenKey($consumer, $noticeId), BridgePaths::readSeen(self::noticeSeenPath()), true);
    }

    /**
     * Mark a recovery seen for one consumer. Keys for any other recovery are dropped in the
     * same write, so the cursor holds at most one run's consumers.
     */
    public static function markNoticeSeen(string $consumer, string $noticeId): void
    {
        $key = self::seenKey($consumer, $noticeId);
        $prefix = $noticeId.'|';

        BridgePaths::updateSeenLocked(
            self::noticeSeenPath(),
            fn (array $seen) => array_values(array_unique([
                ...array_filter($seen, fn (string $k) => str_starts_with($k, $prefix)),
                $key,
            ])),
        );
    }

    private static function seenKey(string $consumer, string $noticeId): string
    {
        return $noticeId.'|'.$consumer;
    }

    /**
     * @param  list<string>  $strings
     * @param  list<string>  $ints
     */
    private static function shaped(mixed $value, array $strings, array $ints): bool
    {
        if (! is_array($value)) {
            return false;
        }
        foreach ($strings as $k) {
            if (! is_string($value[$k] ?? null)) {
                return false;
            }
        }
        foreach ($ints as $k) {
            if (! is_int($value[$k] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  Closure(): void  $body
     */
    private static function locked(Closure $body): void
    {
        BridgePaths::ensureDir(BridgePaths::stateDir());
        $lockPath = self::path().'.lock';
        $h = @fopen($lockPath, 'c');
        if ($h === false) {
            throw new \RuntimeException("bridge: failed to open {$lockPath}");
        }

        try {
            if (! flock($h, LOCK_EX)) {
                throw new \RuntimeException("bridge: failed to lock {$lockPath}");
            }
            $body();
        } finally {
            @flock($h, LOCK_UN);
            @fclose($h);
        }
    }

    /** @param  array<string, mixed>  $record */
    private static function write(array $record): void
    {
        BridgePaths::writeFileAtomic(self::path(), (string) json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    private static function now(): string
    {
        return now()->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
