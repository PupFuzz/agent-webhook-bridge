<?php

namespace App\Bridge\Support;

use App\Bridge\Exceptions\UnreadableFileException;

/**
 * THE guard in front of any read that would otherwise treat a file it cannot READ as a file
 * that is not THERE (card#5789) — the read-side sibling of {@see PathVisibility}, which draws
 * the same line for a stat.
 *
 * The shape this replaces is `if (! is_file($p)) { return <benign>; }` followed by an
 * unguarded `file_get_contents($p)` (or `file($p)` — the member that shape's own grep could
 * not find). On a present-but-unreadable file the benign early-return never fires
 * (`is_file()` is TRUE) and the read's warning is converted by Laravel's error handler into
 * an uncaught `ErrorException` — an outcome none of the five call sites documented, and
 * which the three that answer a benign `[]` were explicitly designed NOT to produce. Worse, the
 * outcome was decided by whatever error handler happened to be installed: under a handler
 * that only logs — `tinker`'s, for one — the same read returns false and the unreadable file
 * silently became "absent" instead. One input, two contradictory outcomes, neither documented.
 * Raising it as a TYPE here makes the answer identical in every context.
 *
 * HOISTED, NOT INVENTED. `TokenFile::readTrimmed` solved this for the secret readers (DL-262)
 * and nothing else adopted it, so the same defect went on being minted at every new read —
 * which is how card#5789's class reached its member count. This is that class's ONE
 * consolidation: `TokenFile` now layers trimming and the secret subtype ON this rather than
 * owning a second copy of the discrimination. Do not re-privatize a copy into a caller.
 *
 * WHY THE READ ITSELF IS THE AUTHORITY, and not an `is_readable()` gate: that predicate
 * answers for the REAL uid (wrong under setuid) and cannot see an ACL, an immutable bit, or a
 * filesystem that refuses the open — so it both misses failures and, worse, invites the caller
 * to treat its `false` as absence. The open's own return value is the only answer that is
 * never a proxy.
 *
 * ⚠ THE SUPPRESSION BELOW IS LOAD-BEARING AND MUST NOT BE COPIED OUT OF HERE. It is safe only
 * because the throw on the next line re-raises the state as something a caller can switch on.
 * An `@` at a call site with no throw behind it converts the third state into the "absent"
 * answer — card#5698's defect, minted once per site.
 */
final class FileContents
{
    /**
     * The file's bytes; null when it is ABSENT. Throws when it is present and THIS process
     * could not read it — which is not the same claim, and not a claim about any other
     * reader (see the class docblock).
     *
     * @param  string  $subject  what to NAME in the message: the thing the operator would
     *                           recognize ("seen-cursor", "writeback.json", "secret file"),
     *                           which is not always the basename of $path. Mirrors the
     *                           `$display` parameter on PathVisibility, for the same reason —
     *                           the message supplies the cause and the remedy itself, so the
     *                           caller only has to supply the noun.
     *
     * @throws UnreadableFileException
     */
    public static function read(string $path, string $subject): ?string
    {
        if (! is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            // Past tense on the presence half deliberately: the gate above OBSERVED a regular
            // file, and asserting it is still there would be a claim about a moment this
            // process never measured (the file can be unlinked in between). The permissions
            // reading is what the operator needs and it holds either way.
            throw new UnreadableFileException(
                "{$subject} at {$path} could not be read by this process (a regular file was "
                .'found at the path, so this is a permissions fault rather than an absence) — '
                .'ownership and mode are relative to the asking user, so another OS user may read it fine'
            );
        }

        return $raw;
    }
}
