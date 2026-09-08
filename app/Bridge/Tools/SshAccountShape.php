<?php

namespace App\Bridge\Tools;

/**
 * Is a string an account name the setup packet may render into a command?
 *
 * ⛔ THIS IS A LOCKSTEP COPY AND THE PYTHON IS THE OWNER, as with {@see SafePathShape},
 * {@see AgentNameShape} and {@see PublicKeyLineShape}: `bin/provision-board-tools.py`'s
 * `_SSH_ACCOUNT_RE` is what `--role a --ssh-account` refuses on, and a test reads it out
 * of the python source and reds if this disagrees.
 *
 * ⚠ THE VALUE IS NOT AN OPTION OF ANY COMMAND HERE — it is
 * `board_tools.ssh_account ?? runUser()` ({@see SshTransportProbe::forcedCommandAccount()}),
 * so it arrives either from an agent's own YAML or from this process's user, and neither
 * is a source that has answered a shape question on the way in. It reaches STEP 1's ssh
 * target, STEP 3's `--ssh-account`, and STEP 3's `sudo -u <account> python3 …` — a line an
 * operator pastes at a ROOT prompt. The exact-string `root` test the packet branches on is
 * also only worth trusting once the value is known to be one word.
 */
final class SshAccountShape
{
    /** The class python's `_SSH_ACCOUNT_RE` allows, without its anchors (see {@see SafePathShape}). */
    public const BODY_PATTERN = '[a-z_][a-z0-9_-]*';

    public static function isAccountName(string $candidate): bool
    {
        return preg_match('#\A'.self::BODY_PATTERN.'\z#', $candidate) === 1;
    }
}
