<?php

namespace App\Bridge\Tools;

use App\Bridge\Support\ChannelSnapshotManifest;
use Illuminate\Support\Facades\Log;
use UnexpectedValueException;

/**
 * The sentence that names each argument the caller's channel client does not declare, the client
 * version that first declared it, and "update your channel client" (card#10566 / DL-426). Two
 * surfaces append it:
 *
 * - {@see BoardToolDispatcher}, to a refusal, over the accepted keys the call SENT: the argument
 *   arrived, so the client sent it.
 * - `board_my_cards`, to a truncated window's `remedy`, over the arguments that remedy ADVISES
 *   (operator ruling, card#10566 comment 7131): the remedy keeps naming them, and the sentence
 *   says which version declares them.
 *
 * Either way the list is argument keys handed over by the caller of this class, never scanned out
 * of finished wording, and the only claim made about each is what the capability table says of
 * the reported version.
 *
 * ⛔ TEXT ONLY. Nothing branches on the result, and no status or accepted value changes with it
 * (DL-364 Decision 2).
 */
final class ClientUpdateClause
{
    /**
     * {@see for()} over the bundled table, or '' when the table does not read. An unreadable table
     * is a broken deploy: it is logged, and the text goes out without the sentence rather than
     * the call failing.
     *
     * @param  list<string>  $arguments
     */
    public static function fromBundledTable(?string $version, string $tool, array $arguments): string
    {
        if ($arguments === []) {
            return '';
        }

        try {
            $caps = ClientCapabilities::bundled();
        } catch (UnexpectedValueException $e) {
            Log::warning('agent-tools: client capability table unreadable; the text carries no client-update clause', ['tool' => $tool, 'error' => $e->getMessage()]);

            return '';
        }

        return self::for($caps, $version, $tool, $arguments);
    }

    /**
     * '' when no listed argument is one the caller's client can be shown to lack; otherwise the
     * sentence, opening with a space so it appends to finished text.
     *
     * - A reported version: an argument it does not declare. A version the table cannot order
     *   (not bare `X.Y.Z`, or newer than it records) declares nothing it can be shown to lack.
     * - No usable version (`null`): an argument that a client older than
     *   {@see ClientVersion::FIRST_REPORTING_SNAPSHOT} — the clients that report none — can
     *   call the tool without declaring.
     * - An argument or tool the table does not carry (an operator-registered tool): never.
     *
     * @param  list<string>  $arguments  argument keys, in the order the sentence names them
     */
    public static function for(ClientCapabilities $caps, ?string $version, string $tool, array $arguments): string
    {
        $lacking = [];
        foreach ($arguments as $argument) {
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
