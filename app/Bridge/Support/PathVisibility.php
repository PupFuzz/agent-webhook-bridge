<?php

namespace App\Bridge\Support;

/**
 * THE guard in front of any `bridge:check` verdict that would ASSERT ABSENCE off a
 * stat (card#5698).
 *
 * A path we cannot SEE is not a path that is gone: `is_dir()`/`is_file()` return
 * false for EACCES exactly as they do for ENOENT, so on an untraversable path
 * "absent" is not a conclusion this process is entitled to draw — and the bridge
 * routinely runs as a different OS user than the agent (DL-227's same-box topology),
 * with an agent's own directories commonly 0700 to that user. A leg that skips this
 * guard reports a confident config accusation ("no secret at …", "parent dir does
 * not exist") on evidence that cannot tell the accusation apart from its own
 * blindness, and sends the operator after the wrong fix.
 *
 * HOISTED, NOT INVENTED. {@see ChannelSnapshotProbe} solved this for the channel
 * snapshot legs (DL-230/DL-237) and nothing else adopted it, so the same defect went
 * on being minted at every new stat — which is how card#5698's class reached its
 * member count. This is that class's ONE consolidation: the predicate and the message
 * live here, and a new stat-bearing leg adopts them rather than re-deriving the
 * reasoning. Do not re-privatize a copy into a check.
 *
 * SEVERITY IS `unvalidated`, per the rule in {@see Severity}: the leg did not answer
 * its own question because of a fact about the install the operator cannot know
 * (limb 2 — a capability THIS PROCESS lacks). It is not a verdict about the artifact.
 *
 * WHERE THIS GUARD IS THE WRONG ANSWER, and both live cases are in-tree — adopt it
 * only when the run's CONSEQUENCE actually differs between the two worlds. (Both are
 * NAMED, never `{@see}`-linked: pint rewrites a docblock FQCN into a real `use`, and an
 * import from `Check\Checks` here would invert the layer — this primitive sits BELOW its
 * consumers.)
 *  - `InstallConfigDirCheck`'s `is_dir()` LEG stays `fail`, because
 *    `CheckCommand` gates its whole agent loop on the same `is_dir()`. Whichever world
 *    it is in, THIS RUN certainly produced a degraded result — a fact about the run,
 *    not about the directory — so the exit code is earned. Only its message needed the
 *    two causes. (Its permissions leg is a different leg with a different consequence and
 *    DOES adopt this guard, via `DirectoryPermissions` — card#5774. The exemption is
 *    per-leg, which is the granularity the `Severity` rule itself uses; do not read it as
 *    "this check is exempt".)
 *  - `AgentApiTokenCheck` needs no guard: its message already says "not readable",
 *    which is TRUE under EACCES and ENOENT alike. A claim its evidence supports is not
 *    a member of this class, however much it looks like one.
 *
 * The converse is unavoidable and accepted: under an untraversable ancestor a
 * genuinely absent path also reports unverified rather than its real verdict. The
 * process cannot distinguish them — no heuristic can — so these legs are CONCLUSIVE
 * ONLY when the checking process can traverse to the path.
 */
final class PathVisibility
{
    /**
     * Returns the "could not be validated" finding when this process cannot stat
     * $path at all, and null when it can — so a call site reads as the guard it is:
     *
     *     yield PathVisibility::unverifiedUnlessVisible($p, "…") ?? Finding::warn("… absent …");
     *
     * @param  string  $display  what to NAME in the message: the subject the operator
     *                           would recognize, which is NOT always $path. A leg that
     *                           probes a child to ask about its container (the channel
     *                           probe stats the entry file to ask about the deployed
     *                           DIRECTORY) names the container; a leg whose subject IS
     *                           the file (a secret, a token) names the file. Both shapes
     *                           are live and both read correctly, because the message
     *                           below supplies the cause and the remedy itself — it says
     *                           a directory ABOVE denies traversal and asks for traversal,
     *                           never for a chmod of whatever is named here.
     */
    public static function unverifiedUnlessVisible(string $path, string $display): ?Finding
    {
        if (self::ancestorIsTraversable($path)) {
            return null;
        }

        return self::notVisibleFinding($display);
    }

    /**
     * The same finding for a caller that has ALREADY established non-visibility and holds
     * the answer — a failed READ that classified its own cause, rather than a leg about to
     * stat (`ChannelToken`, NAMED: this primitive must not import a consumer).
     *
     * It exists so that caller reuses the message instead of paraphrasing it, which is the
     * duplication this class was hoisted to end. Re-statting through
     * {@see self::unverifiedUnlessVisible} would be the alternative, and it would measure a
     * second time — a file can change between the read and the re-check, and then the two
     * measurements disagree about one throw.
     */
    public static function notVisibleFinding(string $display): Finding
    {
        return Finding::unvalidated("{$display} is not visible to this user — a directory above it denies this process traversal (the bridge commonly runs as a different OS user than the agent, and an agent's own directories are often 0700), so this leg could NOT be validated and \"absent\" is NOT a conclusion this run is entitled to draw; re-run bridge:check as the owning user, or grant it traversal");
    }

    /**
     * Can this process even ANSWER "does $path exist?" — i.e. is the nearest
     * existing ancestor directory traversable (+x) by us? Readability (+r) is
     * deliberately NOT required: a 0711 directory answers stat on its children fine.
     */
    public static function ancestorIsTraversable(string $path): bool
    {
        $dir = dirname($path);
        while (! is_dir($dir)) {
            $parent = dirname($dir);
            if ($parent === $dir) {
                return false;
            }
            $dir = $parent;
        }

        return is_executable($dir);
    }
}
