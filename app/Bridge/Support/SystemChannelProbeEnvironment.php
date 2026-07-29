<?php

namespace App\Bridge\Support;

/**
 * The real-host {@see ChannelProbeEnvironment} — one `stream_socket_client` attempt,
 * closed at once (DL-242 stage 5b).
 *
 * A FAILED CONNECT IS AN ANSWER, NOT AN ERROR: nothing is listening, which is the
 * verdict the caller warns on. So the attempt is silenced (`@`) and the transport's
 * message is returned rather than raised.
 */
final class SystemChannelProbeEnvironment implements ChannelProbeEnvironment
{
    /**
     * Seconds. `bridge:check` is a preflight an operator waits on, and this runs once
     * per configured agent — a listener that cannot answer in half a second is
     * indistinguishable from a dead one for live-wake's purposes anyway.
     */
    private const TIMEOUT = 0.5;

    /** @return array{connected: bool, error: string} */
    public function probe(string $dsn): array
    {
        // Seeded so the shape holds even for a failure the transport reports without a
        // message; the by-ref errno is written and never read (nothing renders it).
        $errstr = '';
        $conn = @stream_socket_client($dsn, $errno, $errstr, self::TIMEOUT);
        if ($conn !== false) {
            fclose($conn);

            return ['connected' => true, 'error' => ''];
        }

        return ['connected' => false, 'error' => $errstr];
    }
}
