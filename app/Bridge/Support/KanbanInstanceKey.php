<?php

namespace App\Bridge\Support;

/**
 * The BOARD-INSTANCE key a coord roster's `kanban_user_id` object is keyed by: the kanban HOST
 * of an API base, read exactly as the toolkit's `kb_url_host` (`bin/_kb-board-lib.sh`) reads it
 * (card#10869). The roster's owner is the framework and its reader of record is the toolkit
 * (toolkit README § "The card owner — the kanban assignee"), so this is a PORT of that parser,
 * not a second definition: a host the two spell differently is a seat whose id this bridge
 * looks up under a key nobody wrote.
 *
 * The rule, in `kb_url_host`'s order: a leading `scheme://` is stripped only when the string
 * STARTS with one; the authority ends at the first `/`, `?` or `#`; userinfo is everything up
 * to the LAST `@`; a bracketed IPv6 literal keeps everything through its first `]`, anything
 * else is cut at its first `:` (the port); then ONE trailing dot is dropped. It is NOT
 * case-folded — the toolkit compares hosts byte-for-byte.
 *
 * ⚠ PARITY IS PINNED ON THIS SIDE ONLY. `KanbanInstanceKeyTest` holds this class to the rows of
 * the toolkit's own `tests/kb-host-guard-selftest.sh` § kb_url_host, copied at the toolkit
 * commit the test names. No corpus is published that BOTH ends execute, so a later change to
 * `kb_url_host` is not detectable from this repo — `docs/config-schema.md` § identity
 * `coord_seat` says so where an operator reads it.
 */
final class KanbanInstanceKey
{
    public static function of(string $apiBase): string
    {
        $u = $apiBase;
        if (preg_match('/^[A-Za-z][A-Za-z0-9+.\-]*:\/\//', $u) === 1) {
            $u = substr($u, (int) strpos($u, '://') + 3);
        }
        $u = (string) preg_replace('/[\/?#].*\z/s', '', $u);
        $at = strrpos($u, '@');
        if ($at !== false) {
            $u = substr($u, $at + 1);
        }
        if (str_starts_with($u, '[') && str_contains($u, ']')) {
            $u = substr($u, 0, (int) strpos($u, ']') + 1);
        } else {
            $colon = strpos($u, ':');
            if ($colon !== false) {
                $u = substr($u, 0, $colon);
            }
        }

        return str_ends_with($u, '.') ? substr($u, 0, -1) : $u;
    }
}
