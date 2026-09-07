<?php

namespace App\Bridge\Tools;

/**
 * Is a string a path the setup packet may put into a command someone pastes into a shell?
 *
 * ⛔ THIS IS A LOCKSTEP COPY AND THE PYTHON IS THE OWNER, exactly as
 * {@see PublicKeyLineShape} is. `bin/provision-board-tools.py`'s `_ARTISAN_RE` is the
 * definition — it is what `--role a` actually refuses on — and this class exists only so
 * the packet refuses the same strings BEFORE it renders a STEP 3 the operator would spend
 * a privileged window on. A test reads `_ARTISAN_RE` out of the python source and reds if
 * this file disagrees; change the python first, then this.
 *
 * ⚠ IT IS A CHARACTER CLASS, NOT A PATH VALIDATOR, and the difference is the point: it
 * says nothing about whether the path exists, is absolute, or is the right file. What it
 * decides is whether the value can carry a quote, a space, a `;`, a `$` or a backtick into
 * a rendered command line — which is the only question a renderer is entitled to answer.
 */
final class SafePathShape
{
    /**
     * The class python's `_ARTISAN_RE` allows, WITHOUT its `^`/`$` anchors — held bare
     * because that is the half the lockstep test compares character for character, and
     * because PHP's `$` (unlike python's `fullmatch`) would accept a trailing newline.
     */
    public const BODY_PATTERN = '[A-Za-z0-9_./-]+';

    public static function isSafePath(string $candidate): bool
    {
        return preg_match('#\A'.self::BODY_PATTERN.'\z#', $candidate) === 1;
    }
}
