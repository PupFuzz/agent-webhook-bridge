<?php

namespace Tests\Support\CheckGolden;

use App\Bridge\Tools\AuthorizedKeysRead;
use App\Bridge\Tools\SshProbeEnvironment;

/**
 * The pinned host facts the golden harness's ssh fixtures run against (DL-242 stage 1).
 *
 * The ssh legs read the real host — root-ness, FIPS, `sshd -T`, the run-user's home, and
 * a live ssh round trip. Every one of those is a host input in exactly the sense
 * {@see PinnedHost} exists to eliminate, so an ssh fixture that inherited them would
 * capture the operator's box (or CI's runner) rather than an install shape. All five are
 * constructor fields here, and the homes are literals rather than the real `$HOME`.
 *
 * WHY AN SSH FIXTURE HAD TO EXIST AT ALL: before stage 1 no golden fixture reached
 * `checkSshTransport()` or the `--probe-tools-ssh` leg, so the golden suite was green on
 * two of the three call sites stage 1 migrates — a pass where failure was not possible.
 */
final class GoldenSshEnvironment implements SshProbeEnvironment
{
    /** @param array{exit: int, stdout: string, stderr: string} $roundTrip */
    public function __construct(
        private readonly string $authorizedKeys = '',
        private readonly bool $root = false,
        private readonly bool $fips = false,
        private readonly ?string $sshdConfig = null,
        private readonly array $roundTrip = ['exit' => 255, 'stdout' => '', 'stderr' => 'no fixture round trip configured'],
    ) {}

    public function isRoot(): bool
    {
        return $this->root;
    }

    public function fipsEnabled(): bool
    {
        return $this->fips;
    }

    public function runUser(): string
    {
        return 'bridge';
    }

    public function runUserHome(): string
    {
        return '/home/bridge';
    }

    public function homeForUser(string $user): ?string
    {
        return "/home/{$user}";
    }

    /**
     * Pinned, like every other host fact here: the golden corpus never renders the setup
     * packet (that is `bridge:provision-tools`, not `bridge:check`), so these two answer
     * the interface without introducing a runner-dependent input.
     */
    public function uidForUser(string $user): ?int
    {
        return null;
    }

    public function euid(): ?int
    {
        return null;
    }

    public function sshdEffectiveConfig(?string $forUser = null): ?string
    {
        // Non-root cannot run `sshd -T` (it loads host private keys), and the probe
        // treats null as UNVERIFIED rather than "posture is fine" — keep that coupling.
        return $this->root ? $this->sshdConfig : null;
    }

    /**
     * The empty default models a file this run COULD NOT READ, not an absent one, and the
     * distinction is load-bearing since card#8976: the fixtures that pass no keys
     * (`board-tools-ssh-default-transport-advisory`) exist to render the UNVERIFIABLE
     * setup, which is what an unreadable file produces. An absent file is a CONSULTED
     * file — over a root-resolved path it earns the authoritative FAIL — and no golden
     * fixture is root, so none of them can reach that arm to capture it.
     */
    public function readAuthorizedKeys(string $path): AuthorizedKeysRead
    {
        return $this->authorizedKeys === ''
            ? AuthorizedKeysRead::unreadable()
            : AuthorizedKeysRead::text($this->authorizedKeys);
    }

    /** @return array{exit: int, stdout: string, stderr: string} */
    public function sshRoundTrip(string $target, string $stdin): array
    {
        return $this->roundTrip;
    }
}
