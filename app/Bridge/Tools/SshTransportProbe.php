<?php

namespace App\Bridge\Tools;

/**
 * The offline SSH-transport pinned-line + sshd-posture probe for `bridge:check`
 * (Finding D, card 4952). Every assertion is OUTCOME-based and fails SAFE:
 *
 *  - The pinned `authorized_keys` line for an ssh agent must (exactly-once) force
 *    `bridge:tools-call --agent=<X>` and DENY pty + all forwarding (asserted via the
 *    {@see AuthorizedKeysLine} last-writer-wins capability model, never a `restrict`
 *    keyword match). On a FIPS seat its key algorithm must be FIPS-approved (an
 *    ed25519 key would never authenticate ⇒ FAIL).
 *  - The sshd password-auth OUTCOME (`PasswordAuthentication no` for the bridge user,
 *    from the Match-resolved `sshd -T`) is a REQUIRED, root-verified leg — a parallel
 *    auth path that bypasses the forced command. `sshd -T` needs root; run
 *    unprivileged it emits an explicit UNVERIFIED warn + the `sudo bridge:check` cert
 *    step (F1 + DR2-3), NEVER a false OK and NEVER a hard fail (new-surface installs
 *    stay exit-0 with a loud warn, not a CI red).
 *
 * Severity ∈ {ok, warn, fail}; only `fail` flips `bridge:check`'s exit. ABSENT
 * pinned line at an ASSUMED (non-authoritative) path ⇒ warn (the AuthorizedKeysFile
 * may be relocated); a PRESENT-BUT-BAD line, or an absent line at an AUTHORITATIVE
 * (root-resolved) path, ⇒ fail (DR2-3b).
 */
final class SshTransportProbe
{
    /**
     * @param  ?string  $sshAccount  the OS account the SSH forced command runs as
     *                               (board_tools.ssh_account). Null ⇒ the invoking
     *                               run-user (byte-identical to pre-4977).
     */
    public function __construct(private SshProbeEnvironment $env, private ?string $sshAccount = null) {}

    /**
     * The OS account the forced command runs as — what sshd posture and
     * authorized_keys must be certified against. Defaults to the invoking run-user.
     */
    public function forcedCommandAccount(): string
    {
        return $this->sshAccount ?? $this->env->runUser();
    }

    /** The forced-command account's home (for the default authorized_keys path + %h). */
    private function forcedCommandHome(): string
    {
        return $this->sshAccount !== null
            ? $this->env->homeForUser($this->sshAccount)
            : $this->env->runUserHome();
    }

    /**
     * A CONFIGURED ssh_account that does not resolve to an OS account (homeForUser ⇒ '')
     * cannot be certified — every account-dependent leg would otherwise build a phantom
     * path from an empty home (e.g. `/.ssh/authorized_keys`) and mis-certify against it.
     * Gated strictly on a non-null sshAccount: the unset fallback (runUserHome, which can
     * also be '') keeps its pre-4977 non-authoritative warn behavior, untouched.
     *
     * @return string|null the fail message, or null when there is nothing to report
     */
    private function configuredAccountUnresolved(): ?string
    {
        if ($this->sshAccount !== null && $this->env->homeForUser($this->sshAccount) === '') {
            return "board_tools.ssh_account '{$this->sshAccount}' does not resolve to an OS account on this host — the SSH transport cannot be certified";
        }

        return null;
    }

    /**
     * @return list<array{severity: string, message: string}>
     */
    public function probePinnedLine(string $agentName): array
    {
        if (($unresolved = $this->configuredAccountUnresolved()) !== null) {
            return [$this->fail($unresolved)];
        }

        $findings = [];
        [$path, $authoritative] = $this->authorizedKeysPath();
        $content = $this->env->readAuthorizedKeys($path);

        if ($content === null) {
            $findings[] = $authoritative
                ? $this->fail("no readable authorized_keys at {$path} (resolved from sshd -T) — no pinned line for agent {$agentName}")
                : $this->warn("could not read {$path} (assumed default; the AuthorizedKeysFile may be relocated — re-run as root to resolve it) — the pinned line for agent {$agentName} is UNVERIFIED");

            return $findings;
        }

        $matches = array_values(array_filter(
            AuthorizedKeysLine::parseFile($content),
            fn (AuthorizedKeysLine $l) => $l->forcesToolsCallFor($agentName),
        ));

        if ($matches === []) {
            $findings[] = $authoritative
                ? $this->fail("no authorized_keys line forces bridge:tools-call --agent={$agentName} at {$path} — the ssh transport for this agent is not wired")
                : $this->warn("no authorized_keys line forces bridge:tools-call --agent={$agentName} at {$path} (assumed default; may be at a relocated AuthorizedKeysFile) — UNVERIFIED, re-run as root");

            return $findings;
        }
        if (count($matches) > 1) {
            $findings[] = $this->fail("more than one authorized_keys line forces bridge:tools-call --agent={$agentName} — ambiguous; leave exactly one");

            return $findings;
        }

        $line = $matches[0];
        if (! $line->deniesShellAndForwarding()) {
            $granted = implode(', ', $line->grantedCapabilities());
            $findings[] = $this->fail("the pinned line for agent {$agentName} still grants: {$granted} — the forced command must deny pty + agent/X11/port-forwarding (use `restrict`, or the enumerated no-pty,no-agent-forwarding,no-X11-forwarding,no-port-forwarding form on a FIPS seat)");
        } else {
            $findings[] = $this->ok("the pinned line for agent {$agentName} forces bridge:tools-call and denies pty + all forwarding");
        }

        if ($this->env->fipsEnabled()) {
            if (! $line->keyAlgorithmIsFipsApproved()) {
                $findings[] = $this->fail("FIPS mode is enabled but the pinned key for agent {$agentName} is `".($line->keyAlgorithm ?? 'unknown').'` — a FIPS sshd rejects it (use an ECDSA P-256 key: ssh-keygen -t ecdsa -b 256)');
            } else {
                $findings[] = $this->ok("the pinned key for agent {$agentName} (`{$line->keyAlgorithm}`) is FIPS-approved");
            }
        }

        return $findings;
    }

    /**
     * The opt-in `--probe-tools-ssh=<user@host>` LIVE leg: round-trip a real
     * `board_my_cards` over ssh (the forced command runs server-side) and assert
     * reachable → JSON-clean stdout → ok:true → the returned scope header
     * (board_id/swimlane_id) equals a configured ssh agent's lane (the same
     * swimlane-isolation observable `--probe-tools` uses).
     *
     * @param  list<array{agent: string, board_id: ?int, swimlane_id: ?int}>  $expectedScopes
     * @return list<array{severity: string, message: string}>
     */
    public function probeLive(string $target, array $expectedScopes): array
    {
        $r = $this->env->sshRoundTrip($target, (string) json_encode(['tool' => 'board_my_cards']));
        if ($r['exit'] !== 0) {
            return [$this->fail("ssh {$target} exited {$r['exit']} — unreachable or the forced command failed (stderr: ".trim($r['stderr']).')')];
        }

        $decoded = json_decode($r['stdout'], true);
        if (! is_array($decoded) || ! array_key_exists('ok', $decoded)) {
            return [$this->fail("ssh {$target}: stdout is not a clean board-tools JSON envelope — got: ".substr(trim($r['stdout']), 0, 200))];
        }
        if ($decoded['ok'] !== true) {
            $error = is_string($decoded['error'] ?? null) ? $decoded['error'] : 'unknown';

            return [$this->fail("ssh {$target}: board_my_cards did not succeed (error: {$error})")];
        }

        $result = $decoded['result'] ?? null;
        $gotBoard = is_array($result) && is_numeric($result['board_id'] ?? null) ? (int) $result['board_id'] : null;
        $gotSwimlane = is_array($result) && is_numeric($result['swimlane_id'] ?? null) ? (int) $result['swimlane_id'] : null;
        foreach ($expectedScopes as $scope) {
            if ($gotBoard === $scope['board_id'] && $gotSwimlane === $scope['swimlane_id']) {
                return [$this->ok("ssh {$target}: board_my_cards ok; window scoped to board {$gotBoard} / swimlane {$gotSwimlane} (matches agent {$scope['agent']})")];
            }
        }

        return [$this->fail("ssh {$target}: ISOLATION — board_my_cards returned board_id=".($gotBoard ?? 'null').' swimlane_id='.($gotSwimlane ?? 'null').' which matches no configured ssh agent lane; the window is not scoped as expected')];
    }

    /**
     * @return array{0: string, 1: bool} [path, authoritative]
     */
    private function authorizedKeysPath(): array
    {
        if ($this->env->isRoot()) {
            // Resolve the AuthorizedKeysFile from the forced-command account's
            // Match-resolved config; unset ⇒ null (the global config, byte-identical
            // to pre-4977 which passed no -C).
            $cfg = $this->env->sshdEffectiveConfig($this->sshAccount);
            if ($cfg !== null) {
                $resolved = $this->extractAuthorizedKeysFile($cfg);
                if ($resolved !== null) {
                    return [$resolved, true];
                }
            }
        }

        return [rtrim($this->forcedCommandHome(), '/').'/.ssh/authorized_keys', false];
    }

    private function extractAuthorizedKeysFile(string $sshdConfig): ?string
    {
        foreach (preg_split('/\n/', $sshdConfig) ?: [] as $line) {
            if (preg_match('/^\s*authorizedkeysfile\s+(.+)$/i', $line, $m) === 1) {
                $first = preg_split('/\s+/', trim($m[1]))[0] ?? '';
                if ($first === '') {
                    return null;
                }
                $first = str_replace(['%h', '%u'], [$this->forcedCommandHome(), $this->forcedCommandAccount()], $first);
                if ($first[0] !== '/') {
                    $first = rtrim($this->forcedCommandHome(), '/').'/'.$first;
                }

                return $first;
            }
        }

        return null;
    }

    /** @return array{severity: string, message: string} */
    private function ok(string $message): array
    {
        return ['severity' => 'ok', 'message' => $message];
    }

    /** @return array{severity: string, message: string} */
    private function warn(string $message): array
    {
        return ['severity' => 'warn', 'message' => $message];
    }

    /** @return array{severity: string, message: string} */
    private function fail(string $message): array
    {
        return ['severity' => 'fail', 'message' => $message];
    }
}
