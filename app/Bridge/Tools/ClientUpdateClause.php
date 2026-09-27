<?php

namespace App\Bridge\Tools;

use App\Bridge\Support\ChannelSnapshotManifest;

/**
 * The sentence {@see BoardToolDispatcher} appends to a board-tools refusal when the call SENT an
 * accepted argument its channel client does not declare (card#10566 / DL-426): each such
 * argument, the client version that first declared it, and "update your channel client".
 *
 * It is built from the keys the call sent, never from the refusal's wording, so every claim in
 * it is about something the bridge received: the argument arrived, so the client sent it, and
 * the capability table says whether the reported version declares it.
 *
 * ⛔ TEXT ONLY. Nothing branches on the result, and no status or accepted value changes with it
 * (DL-364 Decision 2).
 */
final class ClientUpdateClause
{
    /**
     * '' when no sent argument is one the caller's client can be shown to lack; otherwise the
     * sentence, opening with a space so it appends to a finished refusal.
     *
     * - A reported version: an argument it does not declare. A version the table cannot order
     *   (not bare `X.Y.Z`, or newer than it records) declares nothing it can be shown to lack.
     * - No usable version (`null`): an argument that a client older than
     *   {@see ClientVersion::FIRST_REPORTING_SNAPSHOT} — the clients that report none — can
     *   call the tool without declaring.
     * - An argument or tool the table does not carry (an operator-registered tool): never.
     *
     * @param  list<string>  $sent  the accepted argument keys the call sent, in the order sent
     */
    public static function for(ClientCapabilities $caps, ?string $version, string $tool, array $sent): string
    {
        $lacking = [];
        foreach ($sent as $argument) {
            if (! $caps->tables($tool, $argument)) {
                continue;
            }
            $lacks = $version === null
                ? self::anUnreportingClientCanLack($caps, $tool, $argument)
                : $caps->declares($version, $tool, $argument) === ClientDeclaration::No;
            if ($lacks) {
                $lacking[] = "`{$argument}` (first declared by client ".$caps->since($tool, $argument).')';
            }
        }
        if ($lacking === []) {
            return '';
        }

        $one = count($lacking) === 1;
        $list = $one ? $lacking[0] : implode(', ', array_slice($lacking, 0, -1)).' and '.$lacking[count($lacking) - 1];
        $it = $one ? 'it' : 'them';

        if ($version === null) {
            return ' This call reported no channel-client version. Channel clients before '.ClientVersion::FIRST_REPORTING_SNAPSHOT
                ." report none, and some of them do not declare {$list}; if yours is one, update your channel client so its tool schema describes {$it}.";
        }

        return " Your channel client, version {$version}, does not declare {$list}; update your channel client so its tool schema describes {$it}.";
    }

    /**
     * The oldest client with the tool lacks the argument, and that client reports no version —
     * which is what makes "some of them do not declare" true of the clients that report none.
     */
    private static function anUnreportingClientCanLack(ClientCapabilities $caps, string $tool, string $argument): bool
    {
        $oldest = $caps->since($tool);

        return ChannelSnapshotManifest::compareVersions($oldest, ClientVersion::FIRST_REPORTING_SNAPSHOT) < 0
            && $caps->declares($oldest, $tool, $argument) === ClientDeclaration::No;
    }
}
