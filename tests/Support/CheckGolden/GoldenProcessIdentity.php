<?php

namespace Tests\Support\CheckGolden;

use App\Bridge\Support\ProcessIdentity;
use App\Bridge\Support\SystemProcessIdentity;

/**
 * The pinned {@see ProcessIdentity} for a golden capture: a NON-ROOT run that owns every file the
 * fixture wrote. Without it `github.token_file` and `agent.kanban_user_roster` answer from the
 * host's uid, and a capture taken as root renders a different arm than one taken as anyone else.
 *
 * Whether a file EXISTS still comes from the real filesystem, so a fixture that leaves a file out
 * still reads as absent.
 */
final class GoldenProcessIdentity implements ProcessIdentity
{
    /** Normalised to `(uid <UID>)` by {@see GoldenCapture}. */
    public const UID = 4242;

    public function euid(): ?int
    {
        return self::UID;
    }

    public function ownerOf(string $path): ?int
    {
        return (new SystemProcessIdentity)->ownerOf($path) === null ? null : self::UID;
    }

    public function accountName(int $uid): ?string
    {
        return null;
    }
}
