<?php

namespace App\Bridge\Tools;

use App\Http\Controllers\AgentTools\AgentToolsController;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Support\Str;

/**
 * HOW A BOARD TOOL NORMALISES A STRING IT IS ABOUT TO COMPARE OR STORE — one owner for
 * every door (card#9155). The values that MATTER here are caller-supplied, because those
 * are the ones the two doors disagree about; the primitive is used on a few
 * bridge- or board-supplied values too ({@see CallerTagPolicy::isPreserved}'s card tags
 * and install hold tags, {@see BoardMyCardsTool}'s board stage NAMES) so that one
 * vocabulary is folded exactly one way rather than two. A board tool is reached through TWO front doors and only ONE of them
 * has Laravel's global `TrimStrings` + `ConvertEmptyStringsToNull` in front of it:
 * {@see AgentToolsController} is an HTTP route, so
 * the middleware has already rewritten `args` before the controller reads them, while
 * `bridge:tools-call` json_decodes STDIN itself and hands the tool the caller's literal
 * bytes. Every tool that normalises its own arguments therefore has to reproduce the
 * middleware exactly, or the same call means two different things depending on how the
 * calling seat happens to be wired.
 *
 * ⛔ PHP'S ASCII `trim()` IS NOT THAT NORMALISATION, AND THE GAP IS BY CONSTRUCTION
 * RATHER THAN BY ACCIDENT. {@see TrimStrings::transform} calls {@see Str::trim}, which
 * strips `\s` PLUS {@see Str::INVISIBLE_CHARACTERS} — a set including `\x{00A0}`,
 * `\x{200B}` and `\x{FEFF}`. **Measured:** `trim("\u{00A0}") === ''` is FALSE and
 * `Str::trim("\u{00A0}") === ''` is TRUE. So a title of one non-breaking space arrived
 * NULL at the HTTP door (⇒ refused) and passed `trim($title) === ''` at the ssh door
 * (⇒ a card born with a visually blank title, which no board view can show and no
 * search can find). Four sites hand-rolled that trim; this is where they now agree.
 *
 * ⭐ THE DELEGATION IS BY IDENTITY, AND THAT IS THE WHOLE POINT. This class calls the
 * framework's own function; it does not re-list `Str::INVISIBLE_CHARACTERS`, and it must
 * never grow a hand-rolled character class. A copied list is a restatement of Laravel's
 * set that goes stale the moment they add a codepoint — which is precisely the drift
 * this card exists to close, re-minted one layer down. `Tests\Unit\Tools\BoardToolArgsTest`
 * pins the identity against the middleware itself rather than against a fixture list, so
 * a framework upgrade that widens the set widens this with it.
 *
 * ⚠ ORDER OF OPERATIONS AT THE CALL SITE, AND IT IS NOT SIMPLY "TRIM FIRST" —
 * DL-367 Decision 3 owns the reasoning and this is the operative half. Answer EMPTINESS
 * with `emptyAfterTrim()` and STORE `trimmed()`, both before anything else looks at the
 * value: those two are the properties the HTTP door's middleware already gives, and
 * adopting them is what makes the doors agree about what a blank value means and about
 * what lands on the card. ⛔ But leave a tool's pre-existing CHARSET and LENGTH guards
 * on the value AS SENT. They are conservative there (raw within a cap implies the
 * trimmed value is; raw printable-ASCII implies the trimmed value is), and moving one
 * onto the trimmed value makes the ssh door ACCEPT a padded value it refuses today —
 * a permissive change to what the system accepts, which is operator-gated in this repo
 * and is NOT what card#9155 approved. The residual asymmetry is disclosed in DL-367
 * § Bounds and pinned by a test rather than left to a reader to rediscover.
 */
final class BoardToolArgs
{
    /**
     * The caller's string as the HTTP door's middleware would have handed it over.
     */
    public static function trimmed(string $value): string
    {
        return Str::trim($value);
    }

    /**
     * Whether the caller sent NOTHING — where "nothing" is the middleware's definition,
     * not PHP's. True for `""`, for ASCII whitespace, and for a value made only of
     * invisible characters, which is what a seat sees as blank and what the HTTP door
     * has always collapsed to null.
     */
    public static function emptyAfterTrim(string $value): bool
    {
        return self::trimmed($value) === '';
    }
}
