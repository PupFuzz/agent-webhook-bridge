<?php

namespace App\Bridge\Tools;

/**
 * Is `--host-a` / `--ssh-port` something the setup packet may render into an ssh command?
 *
 * ⚠ UNLIKE {@see SafePathShape} / {@see AgentNameShape} / {@see PublicKeyLineShape}, THIS
 * ONE HAS NO PYTHON OWNER TO STAY IN LOCKSTEP WITH, and it is not a copy of anything: the
 * python leg receives the host and port already joined into `--ssh-target`/`--ssh-port` on
 * the SEAT, and constrains neither. What both rules restate is the world — RFC 1123 label
 * syntax and the 16-bit TCP port space — so there is no repo constant either side could
 * drift from. Host and port live together because they are one endpoint, arriving from one
 * operator, refused for one reason.
 *
 * ⛔ THE REASON IT IS REFUSED RATHER THAN ESCAPED. `--host-a` is interpolated into the ssh
 * target of a STEP 1 command an impl agent pastes into its own shell, and `--ssh-port`
 * into `--ssh-port <n>` beside it. A renderer that escaped instead would be inventing a
 * quoting contract for a string the reader retypes by hand anyway; a host name that is not
 * a host name is a typo or an injection attempt, and neither should render.
 */
final class SshEndpointShape
{
    /** One RFC 1123 label: alphanumeric ends, hyphens inside, 63 chars max. */
    private const LABEL = '[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?';

    /**
     * A host name (dotted RFC 1123 labels, optional trailing dot), an IPv4 address, or a
     * BRACKETED IPv6 literal — the form ssh itself requires, since a bare IPv6 address is
     * ambiguous with `host:port`.
     */
    public static function isHost(string $candidate): bool
    {
        if (str_starts_with($candidate, '[') && str_ends_with($candidate, ']')) {
            return filter_var(substr($candidate, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }
        if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return true;
        }
        if (strlen($candidate) > 253) {
            return false;
        }

        return preg_match('#\A'.self::LABEL.'(?:\.'.self::LABEL.')*\.?\z#', $candidate) === 1;
    }

    /** A port sshd could be listening on: digits only, 1-65535. */
    public static function isPort(string $candidate): bool
    {
        return preg_match('/\A[0-9]{1,5}\z/', $candidate) === 1
            && (int) $candidate >= 1
            && (int) $candidate <= 65535;
    }
}
