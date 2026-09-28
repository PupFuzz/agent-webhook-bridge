<?php

namespace App\Bridge\ClientUpdate;

use App\Bridge\Tools\ClientVersion;
use App\Models\SeatClientEvent;
use JsonException;

/**
 * One line of a seat's install log (`install-log.jsonl`), as `client_report` carries it (card#10567
 * B4): the EXACT line bytes, so the bridge can hash them and follow the log's hash chain.
 *
 * A line is a JSON object:
 *   install_id   the seat's install id (the report's own)
 *   seq          1, 2, 3 … within that install
 *   action       bootstrap | install | refuse | approval_owed | fail | skip | pointer_recovered
 *                | entry_replace | prune
 *   result       ok | refused | failed | skipped
 *   actor        launch | provision
 *   prev_sha256  sha256 of the previous line's bytes; null on seq 1
 *   launch_id    REQUIRED on an `actor: launch` line — it is what groups a launch's lines into one
 *                outcome, and what lets a later launch supersede a failed one; without it a
 *                failure would be cleared by its own launch's first call
 *   optional:    time, from_bridge_release, to_bridge_release, client_version, pack_sha256,
 *                files_json_sha256, manifest_sha256, source, reason, launch_id (on a provision line)
 *
 * ⭐ AN OPEN FORMAT (card#10567 B4 review r1). Every field listed above is validated when present,
 * and a line whose listed field is malformed is REFUSED — so is the report carrying it, and nothing
 * from that report is stored; the refusal names the field. A key NOT listed here is accepted and
 * ignored: it stays in the stored line bytes, which are what the chain hashes, so a later client
 * can add fields without this bridge refusing its log or breaking its chain.
 */
final class InstallLogEntry
{
    public const ACTIONS = ['bootstrap', 'install', 'refuse', 'approval_owed', 'fail', 'skip', 'pointer_recovered', 'entry_replace', 'prune'];

    public const RESULTS = ['ok', 'refused', 'failed', 'skipped'];

    public const ACTORS = ['launch', 'provision'];

    public const MAX_LINE_BYTES = 4096;

    private const SHA256 = '/\A[0-9a-f]{64}\z/';

    private const ID = '/\A[0-9A-Za-z-]{1,64}\z/';

    private const RELEASE = '/\A[0-9]{1,9}\.[0-9]{1,9}\.[0-9]{1,9}\z/';

    private function __construct(
        public readonly string $line,
        public readonly string $lineSha256,
        public readonly string $installId,
        public readonly int $seq,
        public readonly string $action,
        public readonly string $result,
        public readonly string $actor,
        public readonly ?string $prevSha256,
        public readonly ?string $time,
        public readonly ?string $fromBridgeRelease,
        public readonly ?string $toBridgeRelease,
        public readonly ?string $clientVersion,
        public readonly ?string $packSha256,
        public readonly ?string $filesJsonSha256,
        public readonly ?string $source,
        public readonly ?string $reason,
        public readonly ?string $launchId,
    ) {}

    /**
     * @throws InstallLogRefused naming what is wrong with the line
     */
    public static function parse(mixed $line, string $installId): self
    {
        if (! is_string($line) || $line === '') {
            throw new InstallLogRefused('is not a non-empty string (each entry is one install-log line, verbatim)');
        }
        if (strlen($line) > self::MAX_LINE_BYTES) {
            throw new InstallLogRefused('is longer than '.self::MAX_LINE_BYTES.' bytes');
        }
        try {
            $e = json_decode($line, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InstallLogRefused('is not valid JSON');
        }
        if (! is_array($e) || array_is_list($e)) {
            throw new InstallLogRefused('is not a JSON object');
        }
        if (($e['install_id'] ?? null) !== $installId) {
            throw new InstallLogRefused('names a different install_id than the report');
        }
        $seq = $e['seq'] ?? null;
        if (! is_int($seq) || $seq < 1) {
            throw new InstallLogRefused('has no positive integer `seq`');
        }

        $actor = self::oneOf($e, 'actor', self::ACTORS);
        $launchId = self::optional($e, 'launch_id', self::ID);
        if ($actor === 'launch' && $launchId === null) {
            throw new InstallLogRefused('is an `actor: launch` line with no `launch_id` — every launch line names its launch');
        }
        self::optional($e, 'manifest_sha256', self::SHA256);

        return new self(
            line: $line,
            lineSha256: hash('sha256', $line),
            installId: $installId,
            seq: $seq,
            action: self::oneOf($e, 'action', self::ACTIONS),
            result: self::oneOf($e, 'result', self::RESULTS),
            actor: $actor,
            prevSha256: self::optional($e, 'prev_sha256', self::SHA256),
            time: self::optionalText($e, 'time', 40),
            fromBridgeRelease: self::optional($e, 'from_bridge_release', self::RELEASE),
            toBridgeRelease: self::optional($e, 'to_bridge_release', self::RELEASE),
            clientVersion: self::optionalClientVersion($e),
            packSha256: self::optional($e, 'pack_sha256', self::SHA256),
            filesJsonSha256: self::optional($e, 'files_json_sha256', self::SHA256),
            source: self::optionalText($e, 'source', 255),
            reason: self::optionalText($e, 'reason', SeatClientEvent::REASON_MAX_CHARS),
            launchId: $launchId,
        );
    }

    /**
     * @param  array<array-key, mixed>  $e
     * @param  list<string>  $allowed
     */
    private static function oneOf(array $e, string $key, array $allowed): string
    {
        $v = $e[$key] ?? null;
        if (! is_string($v) || ! in_array($v, $allowed, true)) {
            throw new InstallLogRefused("has no `{$key}` among ".implode(', ', $allowed));
        }

        return $v;
    }

    /**
     * @param  array<array-key, mixed>  $e
     */
    private static function optional(array $e, string $key, string $pattern): ?string
    {
        $v = $e[$key] ?? null;
        if ($v === null) {
            return null;
        }
        if (! is_string($v) || preg_match($pattern, $v) !== 1) {
            throw new InstallLogRefused("has a malformed `{$key}`");
        }

        return $v;
    }

    /**
     * @param  array<array-key, mixed>  $e
     */
    private static function optionalClientVersion(array $e): ?string
    {
        $v = $e['client_version'] ?? null;
        if ($v === null) {
            return null;
        }
        $version = ClientVersion::fromCall($v);
        if ($version === null) {
            throw new InstallLogRefused('has a malformed `client_version`');
        }

        return $version;
    }

    /**
     * Free text the seat wrote. Stored as sent; every printer escapes it (UntrustedText).
     *
     * @param  array<array-key, mixed>  $e
     */
    private static function optionalText(array $e, string $key, int $maxBytes): ?string
    {
        $v = $e[$key] ?? null;
        if ($v === null) {
            return null;
        }
        if (! is_string($v) || strlen($v) > $maxBytes) {
            throw new InstallLogRefused("has a `{$key}` that is not a string of at most {$maxBytes} bytes");
        }

        return $v;
    }
}
