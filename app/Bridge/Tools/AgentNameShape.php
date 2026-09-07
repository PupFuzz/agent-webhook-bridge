<?php

namespace App\Bridge\Tools;

/**
 * Is a string an agent name the setup packet may render into a pin command?
 *
 * ⛔ THIS IS A LOCKSTEP COPY AND THE PYTHON IS THE OWNER, as with {@see SafePathShape} and
 * {@see PublicKeyLineShape}: `bin/provision-board-tools.py`'s `_AGENT_RE` is what `--role a`
 * refuses on, and a test reads it out of the python source and reds if this disagrees.
 *
 * ⚠ The name reaches TWO places that make it more than a label: the `authorized_keys`
 * forced command (`bridge:tools-call --agent=<name>`, inside double quotes) and the
 * `<pubkey-dir>/<name>.pub` path STEP 2 writes. It is read from an agent's own YAML file
 * name, which the packet does not get to assume anything about.
 */
final class AgentNameShape
{
    /** The class python's `_AGENT_RE` allows, without its anchors (see {@see SafePathShape}). */
    public const BODY_PATTERN = '[a-z0-9_-]+';

    public static function isAgentName(string $candidate): bool
    {
        return preg_match('#\A'.self::BODY_PATTERN.'\z#', $candidate) === 1;
    }
}
