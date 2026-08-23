<?php

namespace App\Bridge\Tools;

use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;

/**
 * The offline SSH-transport pinned-line + sshd-posture probe for `bridge:check`
 * (Finding D, card 4952). Every assertion is OUTCOME-based and fails SAFE:
 *
 *  - The pinned `authorized_keys` line for an ssh agent must (exactly-once) force
 *    `bridge:tools-call --agent=<X>` and DENY pty + all forwarding (asserted via the
 *    {@see AuthorizedKeysLine} last-writer-wins capability model, never a `restrict`
 *    keyword match). On a FIPS seat its key algorithm must be FIPS-approved (an
 *    ed25519 key would never authenticate ⇒ FAIL).
 *  - The `authorized_keys` PATH is resolved from the Match-resolved `sshd -T` when this
 *    process can run it. `sshd -T` needs root (it loads host private keys); run
 *    unprivileged, the path falls back to the account's assumed default, so any verdict
 *    drawn there may be about the WRONG FILE — it reports UNVERIFIED + the
 *    `sudo bridge:check` cert step (F1 + DR2-3), NEVER a false OK and NEVER a hard fail
 *    (new-surface installs stay exit-0 with a loud line, not a CI red).
 *    (THERE IS NO sshd-POSTURE LEG. An earlier revision of this docblock described a
 *    required `PasswordAuthentication no` check; card#5091 RETIRED that leg — the
 *    account-level drop-in it certified locked out an operator sharing the ssh account —
 *    and left this text behind. The forced-command key is the sole boundary.)
 *
 * Emits {@see Finding}s over the shared {@see Severity} vocabulary;
 * only `fail` flips `bridge:check`'s exit. It constructs **Ok/Fail/Unvalidated** today and
 * NO `warn` at all — a statement about these legs, not a constraint on the vocabulary. The
 * UNVERIFIED legs above were the open question {@see Severity} used to name; DL-251 settled
 * it and swept them, because a leg that could not read the file, or read the wrong one,
 * did not answer its own question. ABSENT pinned line at an ASSUMED (non-authoritative)
 * path ⇒ `unvalidated` (the AuthorizedKeysFile may be relocated); a PRESENT-BUT-BAD line,
 * or an absent line at an AUTHORITATIVE (root-resolved) path, ⇒ `fail` (DR2-3b).
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

    /**
     * The forced-command account's home (for the default authorized_keys path + %h).
     *
     * The `?? ''` narrows the un-lookup-able account back to the phantom-home value, and is
     * never reached with it: {@see self::configuredAccountUnresolved} returns a finding for
     * exactly that state and every caller of this method sits behind that early return.
     */
    private function forcedCommandHome(): string
    {
        return $this->sshAccount !== null
            ? ($this->env->homeForUser($this->sshAccount) ?? '')
            : $this->env->runUserHome();
    }

    /**
     * A CONFIGURED ssh_account whose home does not resolve cannot be certified — every
     * account-dependent leg would otherwise build a phantom path from an empty home (e.g.
     * `/.ssh/authorized_keys`) and mis-certify against it. Gated strictly on a non-null
     * sshAccount: the unset fallback (runUserHome, which can also be '') keeps its
     * pre-4977 non-authoritative behavior, untouched — that arm now reports `unvalidated`
     * rather than `warn` (DL-251), which is a severity change and not a behavior one.
     *
     * TWO CAUSES, TWO SEVERITIES (DL-259, card#5698). Both block certification, so both
     * report; they differ in what this run is entitled to say about WHY:
     *   - the account database answered "no such account" ⇒ a MEASURED config fault, and
     *     the `fail` is earned exactly as before;
     *   - this process cannot look accounts up at all (no posix_getpwnam) ⇒ nothing was
     *     measured, and the old code spent that as the same accusation — hard-failing
     *     `bridge:check` over a perfectly valid account on any host without the extension.
     *     That is limb (a) of {@see Severity}'s rule, so it is `unvalidated`.
     */
    private function configuredAccountUnresolved(): ?Finding
    {
        if ($this->sshAccount === null) {
            return null;
        }

        $home = $this->env->homeForUser($this->sshAccount);
        if ($home === null) {
            return Finding::unvalidated("board_tools.ssh_account '{$this->sshAccount}' could NOT be resolved: this PHP process has no posix_getpwnam, so it cannot look OS accounts up at all and never consulted the account database — whether the account exists is UNKNOWN and its absence is NOT a conclusion this run may draw. The SSH transport is left uncertified; enable the posix extension, or certify from a host that has it.");
        }
        if ($home === '') {
            return Finding::fail("board_tools.ssh_account '{$this->sshAccount}' does not resolve to an OS account on this host — the SSH transport cannot be certified");
        }

        return null;
    }

    /**
     * @return list<Finding>
     */
    public function probePinnedLine(string $agentName): array
    {
        if (($unresolved = $this->configuredAccountUnresolved()) !== null) {
            return [$unresolved];
        }

        $findings = [];
        [$path, $authoritative] = $this->authorizedKeysPath();
        $content = $this->env->readAuthorizedKeys($path);

        if ($content === null) {
            $findings[] = $authoritative
                ? Finding::fail("no readable authorized_keys at {$path} (resolved from sshd -T) — no pinned line for agent {$agentName}")
                : Finding::unvalidated("could not read {$path} (assumed default; the AuthorizedKeysFile may be relocated — re-run as root to resolve it) — the pinned line for agent {$agentName} is UNVERIFIED");

            return $findings;
        }

        $matches = array_values(array_filter(
            AuthorizedKeysLine::parseFile($content),
            fn (AuthorizedKeysLine $l) => $l->forcesToolsCallFor($agentName),
        ));

        if ($matches === []) {
            $findings[] = $authoritative
                ? Finding::fail("no authorized_keys line forces bridge:tools-call --agent={$agentName} at {$path} — the ssh transport for this agent is not wired")
                : Finding::unvalidated("no authorized_keys line forces bridge:tools-call --agent={$agentName} at {$path} (assumed default; may be at a relocated AuthorizedKeysFile) — UNVERIFIED, re-run as root");

            return $findings;
        }
        if (count($matches) > 1) {
            $findings[] = Finding::fail("more than one authorized_keys line forces bridge:tools-call --agent={$agentName} — ambiguous; leave exactly one");

            return $findings;
        }

        $line = $matches[0];
        if (! $line->deniesShellAndForwarding()) {
            $granted = implode(', ', $line->grantedCapabilities());
            $findings[] = Finding::fail("the pinned line for agent {$agentName} still grants: {$granted} — the forced command must deny pty + agent/X11/port-forwarding (use `restrict`, or the enumerated no-pty,no-agent-forwarding,no-X11-forwarding,no-port-forwarding form on a FIPS seat)");
        } else {
            $findings[] = Finding::ok("the pinned line for agent {$agentName} forces bridge:tools-call and denies pty + all forwarding");
        }

        if ($this->env->fipsEnabled()) {
            if (! $line->keyAlgorithmIsFipsApproved()) {
                $findings[] = Finding::fail("FIPS mode is enabled but the pinned key for agent {$agentName} is `".($line->keyAlgorithm ?? 'unknown').'` — a FIPS sshd rejects it (use an ECDSA P-256 key: ssh-keygen -t ecdsa -b 256)');
            } else {
                $findings[] = Finding::ok("the pinned key for agent {$agentName} (`{$line->keyAlgorithm}`) is FIPS-approved");
            }
        }

        return $findings;
    }

    /**
     * The opt-in `--probe-tools-ssh=<user@host>` LIVE leg: round-trip a real
     * `board_my_cards` over ssh (the forced command runs server-side) and assert
     * reachable → JSON-clean stdout → ok:true → the returned scope header identifies
     * a configured ssh agent (the same observable `--probe-tools` uses).
     * The header is what the answering agent is CONFIGURED for — an identity echo
     * that certifies which agent this key resolved to, never a reading of the rows;
     * BoardToolsScopeHeader owns both spellings of it (DL-302), and both the ok line
     * and the mismatch tail name WHICH spelling this responder answered under — the
     * ssh target is a REMOTE install, so this line is the only place the version skew
     * the fallback tolerates is observable at all (card#7325, DL-304).
     *
     * @param  list<array{agent: string, board_id: ?int, swimlane_id: ?int}>  $expectedScopes
     * @return list<Finding>
     */
    public function probeLive(string $target, array $expectedScopes): array
    {
        $r = $this->env->sshRoundTrip($target, (string) json_encode(['tool' => 'board_my_cards']));
        if ($r['exit'] !== 0) {
            return [Finding::fail("ssh {$target} exited {$r['exit']} — unreachable or the forced command failed (stderr: ".trim($r['stderr']).')')];
        }

        $decoded = json_decode($r['stdout'], true);
        if (! is_array($decoded) || ! array_key_exists('ok', $decoded)) {
            return [Finding::fail("ssh {$target}: stdout is not a clean board-tools JSON envelope — got: ".substr(trim($r['stdout']), 0, 200))];
        }
        if ($decoded['ok'] !== true) {
            $error = is_string($decoded['error'] ?? null) ? $decoded['error'] : 'unknown';

            return [Finding::fail("ssh {$target}: board_my_cards did not succeed (error: {$error})")];
        }

        $result = $decoded['result'] ?? null;
        $header = BoardToolsScopeHeader::read(is_array($result) ? $result : []);
        $gotBoard = $header->boardId;
        $gotSwimlane = $header->swimlaneId;
        foreach ($expectedScopes as $scope) {
            if ($gotBoard === $scope['board_id'] && $gotSwimlane === $scope['swimlane_id']) {
                return [Finding::ok("ssh {$target}: board_my_cards ok; window scoped to board {$gotBoard} / swimlane {$gotSwimlane} (matches agent {$scope['agent']}). The scope header is an identity echo — matching it certifies that this pinned key resolved to THAT agent, not that the bridge-side lane filter ran (config matching config is true whatever the rows held); the measured half is the response's own board_id/board_observed. ".$header->boardSpelling->note())];
            }
        }

        return [Finding::fail("ssh {$target}: IDENTITY MISMATCH — board_my_cards answered for board=".($gotBoard ?? 'null').' swimlane='.($gotSwimlane ?? 'null').' which matches no configured ssh agent. '.$header->boardSpelling->mismatchCause(
            credential: 'the pinned key',
            credentialFix: 'look for a mis-pinned key or a stale forced-command --agent',
            routeFix: "check what {$target}'s forced command actually ran — a relay, or any JSON responder that is not board_my_cards, answers a probe exactly this way",
        ).' It says nothing about the bridge-side lane filter, which this response has no observable for. '.$header->boardSpelling->note())];
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
}
