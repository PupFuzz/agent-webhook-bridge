<?php

namespace App\Bridge\Validation;

/**
 * Filesystem validation of a loopback Unix-domain-socket endpoint: the path must
 * point at an existing, non-symlink real socket. Shared by ChannelPushHandler
 * (the live-wake push) and WritebackAlertNotifier (the FR-4 alert push) so the
 * stat sequence — and the uid-mismatch diagnostic — can't drift between them.
 *
 * The FORMAT precheck (absolute / no-`..`) stays with each caller: the handler's
 * agent-socket gate and the notifier's {@see SocketPath} gate accept different
 * sets, so unifying it here would change what each surface accepts/rejects.
 *
 * Distinguishes a missing PARENT dir (a uid mismatch after a host restore — the
 * path pins /run/user/<uid>) from a missing socket (the channel server isn't
 * up): the first is a config bug needing a repoint, the second resolves itself
 * when the server starts. `$diagnoseUidMismatch` gates the uid narrative so a
 * classifier-supplied path (attacker-influenced) gets the plain parent-dir
 * message and its missing dir is never misattributed to a config/uid problem
 * (canon #10 honest attribution).
 */
final class SocketEndpoint
{
    /**
     * @param  string  $subject  Message subject for the socket itself (e.g. "channel_push: payload.socket").
     * @param  string  $parentSubject  Message subject for parent-dir diagnostics (e.g. "channel_push: socket").
     * @param  string  $configField  Config field named in the uid-mismatch repoint advice (e.g. "channel.socket").
     *
     * @throws EndpointValidationException
     */
    public static function assertValid(
        string $path,
        string $subject,
        string $parentSubject,
        string $configField,
        bool $diagnoseUidMismatch,
    ): void {
        clearstatcache(true, $path);
        if (! file_exists($path) && ! is_link($path)) {
            if (! is_dir(dirname($path))) {
                if ($diagnoseUidMismatch) {
                    throw new EndpointValidationException($parentSubject.' parent dir '.dirname($path)." does not exist — likely a uid mismatch after a host restore (the path pins /run/user/<uid>); repoint {$configField} or derive it with \${XDG_RUNTIME_DIR}: {$path}");
                }
                throw new EndpointValidationException($parentSubject.' parent dir '.dirname($path)." does not exist: {$path}");
            }
            throw new EndpointValidationException("{$subject} does not exist (start the channel server first): {$path}");
        }
        // lstat-based: a symlink at the socket path is a TOCTOU vector (a same-uid
        // attacker could swap its target between check and connect).
        if (is_link($path)) {
            throw new EndpointValidationException("{$subject} must not be a symlink: {$path}");
        }
        if (filetype($path) !== 'socket') {
            throw new EndpointValidationException("{$subject} is not a Unix domain socket: {$path}");
        }
    }
}
