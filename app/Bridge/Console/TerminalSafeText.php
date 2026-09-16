<?php

namespace App\Bridge\Console;

use App\Bridge\Support\UntrustedText;

/**
 * The transform the console output choke applies to every formatted write, whatever
 * wrote it and whoever authored the bytes (card#9251, DL-393).
 *
 * ⭐ STRIP, NEVER ESCAPE. {@see UntrustedText::forOperator()} is a
 * DIAGNOSTIC: it must be reversible, so it doubles backslashes and hex-escapes what it hides.
 * This is a safety net under output nobody asked to inspect, so it removes the bytes and
 * touches nothing else — a backslash, a whitespace run and a newline reach the stream exactly
 * as composed, and a value `forOperator()` already escaped passes through unchanged.
 *
 * THE ORDER IS LOAD-BEARING: invalid UTF-8 is scrubbed to U+FFFD FIRST, then the class is
 * stripped. A `/u` pattern fails on malformed input, and a lone `0x9B` — half of a C1 CSI a
 * producer split across two writes — is not a codepoint the pattern could match; scrubbed,
 * it is U+FFFD and never reaches the terminal raw.
 *
 * THE CLASS: C0 except `\n` and `\t` (so `\r` IS stripped — it returns the cursor to column 0
 * and overwrites the line above), DEL, C1 (U+0080–U+009F), and `\p{Cf}` (bidi overrides and
 * isolates, zero-width characters, the soft hyphen). `\t` is kept: it moves the cursor right
 * within a line and forges nothing. Combining marks, private-use codepoints and `Lo`/`So`
 * look-alikes such as U+3164 pass, for the reasons DL-366 bound (3) records.
 */
final class TerminalSafeText
{
    private const STRIPPED = '/[\x{0000}-\x{0008}\x{000B}-\x{001F}\x{007F}-\x{009F}]|\p{Cf}/u';

    public static function strip(string $text): string
    {
        $substitute = mb_substitute_character();
        mb_substitute_character(0xFFFD);
        try {
            $scrubbed = mb_scrub($text, 'UTF-8');
        } finally {
            mb_substitute_character($substitute);
        }

        return preg_replace(self::STRIPPED, '', $scrubbed) ?? self::asciiOnly($scrubbed);
    }

    /**
     * ⛔ FAIL CLOSED, AND NEVER SILENT. `preg_replace` answers null only on a PCRE failure —
     * not reachable from input once `mb_scrub` has run and the pattern has no backtracking,
     * but reachable from the host's PCRE limits. Returning the scrubbed text would pass the
     * class raw at exactly that moment; returning '' would print nothing. What is left is a
     * transform with no regex: printable ASCII, `\n` and `\t` survive, every other byte is a `?`.
     */
    private static function asciiOnly(string $scrubbed): string
    {
        $out = '';
        $length = strlen($scrubbed);
        for ($i = 0; $i < $length; $i++) {
            $byte = ord($scrubbed[$i]);
            if ($byte >= 0x20 && $byte <= 0x7E || $byte === 0x0A || $byte === 0x09) {
                $out .= $scrubbed[$i];
            } elseif ($byte >= 0x80) {
                $out .= '?';
            }
        }

        return $out;
    }
}
