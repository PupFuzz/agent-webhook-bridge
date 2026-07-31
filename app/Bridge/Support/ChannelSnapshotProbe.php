<?php

namespace App\Bridge\Support;

/**
 * Certifies the DEPLOYED channel-server snapshot for `bridge:check` (card 5108).
 *
 * The socket/HTTP liveness legs certify the RUNNING process; nothing certified the
 * FILES the next session respawns from. A stale or unlaunchable `examples/channel-servers`
 * copy therefore reported `channel socket live` and exited 0, and the failure only
 * materialized at the next session start — as live-wake silently never coming back.
 *
 * WHAT IT DOES NOT DO: it never executes node, so it never answers "will this
 * LAUNCH?" — only "is this deployment there, and is it behind the checkout?".
 * Loadability is measured by `bin/check-channel-snapshot.py`, run ON THE SEAT as the
 * OS user whose session launches the server (DL-237). That is a boundary, not a gap
 * in coverage: the bridge commonly runs as a different OS user than the agent, so a
 * launch from HERE would certify the entry loads for the BRIDGE's user — a different
 * PATH, a different node — which is a proxy for the question, and swapping one proxy
 * for another is what DL-237 stopped doing.
 *
 * Emits {@see Finding}s over the shared {@see Severity} vocabulary — the same primitive the
 * SSH transport probe emits; only `fail` flips `bridge:check`'s exit. `unvalidated`
 * (card 5170) is NOT a verdict about the deployment — it says the leg did not run, so a
 * green `bridge:check` is not evidence the snapshot is sound; `ok` means measured-and-clean
 * and must never carry a not-measured finding. Branch order is load-bearing
 * (see {@see self::probe()}).
 */
final class ChannelSnapshotProbe
{
    /** The channel server's entry point — the file the agent's MCP client launches. */
    public const ENTRY_FILE = 'agent-webhook-bridge-channel.mjs';

    /** The directory `npm ci` creates, and the one the dependency leg owns. */
    private const NODE_MODULES = 'node_modules';

    /**
     * The seat-side launch-assert this probe DELEGATES the loadability question to
     * (DL-237). Named in the one disclosure {@see self::probe()} emits per PROBE CALL that
     * reached the legs — on every branch, not just where the retired completeness leg used
     * to answer it.
     */
    private const LAUNCH_ASSERT = 'bin/check-channel-snapshot.py';

    /**
     * @param  ?string  $serverPath  the agent's resolved `channel.server_path` (already
     *                               normalized to a directory), or null when undeclared
     * @param  string  $bundledDir  this checkout's `examples/channel-servers`
     * @return list<Finding>
     */
    public static function probe(?string $serverPath, string $bundledDir): array
    {
        if ($serverPath === null) {
            return [Finding::unvalidated('channel.server_path not declared — snapshot not validated')];
        }

        // Resolve NON-STRICTLY. A strict resolve (realpath ⇒ false, or a throwing
        // resolver) errors instead of returning a verdict on exactly the seat that
        // most needs one, so fall back to the link target and then the literal.
        $resolved = self::resolveNonStrict($serverPath);
        $where = $resolved === $serverPath ? $serverPath : "{$serverPath} → {$resolved}";

        // EXISTENCE BEFORE CLASSIFICATION — load-bearing ordering. A dangling symlink
        // resolves (non-strictly) to a STALE path that differs from the checkout, so
        // classifying first would drop it into the snapshot branch and version-compare
        // against a directory that is not there: a fatal condition misreported as a
        // drift question.
        if (! is_dir($resolved)) {
            if (($unverified = self::visibleOrUnverified($resolved, $where)) !== null) {
                return [$unverified];
            }

            // Resolvable, just not a directory. AgentConfig normalizes only the
            // `.mjs` ENTRY suffix to its directory, so an operator pointing at
            // `server.js` / a wrapper script lands here — a different operator
            // action than a dangling link, and reporting "repoint the symlink"
            // for it sends them after a symlink that isn't the problem.
            if (file_exists($resolved)) {
                return [Finding::fail("channel server path {$where} names a file, not the channel-server directory — point channel.server_path at the deployed DIRECTORY (only the ".self::ENTRY_FILE.' entry form is normalized to its directory for you)')];
            }

            return [Finding::fail("channel server path does not resolve (dangling symlink or removed directory): {$where} — the MCP server will not launch at next session start; the link target moved, repoint the symlink (or re-deploy the directory)")];
        }

        $deployedDir = realpath($resolved) ?: $resolved;
        $bundledReal = realpath($bundledDir);

        // ONE traversability gate for BOTH legs, asked here rather than per-stat.
        // EVERY stat they make is a DIRECT CHILD of $deployedDir — package.json,
        // the entry, node_modules — so they all turn on the same question (can
        // this process traverse INTO the deployed directory?) and fail together.
        // Asking it per-leg emitted the same WARN twice and named a FILE as the
        // "channel server path"; +x on the DIRECTORY is what the operator has to
        // grant, so that is what the message names. Reaching either leg PROVES the
        // +x for direct children, which is why neither re-guards its own stats.
        // BOUND on that proof — read before adding a stat here: it covers the
        // directory ENTRY only. A child that is itself a symlink resolves to its
        // target, whose ancestors are outside this gate, so an EACCES there still
        // reads as absence. Not worth a per-stat guard (that is the double-WARN this
        // hoist removed) because it needs an operator to symlink INDIVIDUAL files out
        // of an otherwise-traversable deployment — a shape the channel-server README
        // rules out ("copy or symlink the WHOLE directory"). Do not read this comment
        // as a blanket licence: a stat on a path that is NOT a direct child of the
        // deployed directory needs its own guard. No leg makes one today — the
        // completeness walk that did was retired with its leg (DL-237) — so adding
        // one means adding that guard with it.
        if (($unverified = self::visibleOrUnverified($deployedDir.'/'.self::ENTRY_FILE, $deployedDir)) !== null) {
            return [$unverified];
        }

        // Classify on the RESOLVED REALPATH, never a string/prefix test on the
        // configured path: the symlinked topology (~/agent-webhook-bridge-channel →
        // <checkout>/examples/channel-servers) LOOKS external but resolves internal,
        // and a prefix test would emit a meaningless self-compare. Realpath equally
        // handles a link into a DIFFERENT bridge checkout (resolves outside ⇒ a
        // genuine snapshot ⇒ the version compare is meaningful).
        if ($bundledReal !== false && $deployedDir === $bundledReal) {
            // Repo-direct: the version compare would be a self-compare — the
            // deployment and the reference are one directory, so there is no drift
            // question to answer. The presence leg below still runs on it.
            $findings = [Finding::ok("channel server path {$where} IS this checkout's examples/channel-servers — no snapshot to drift, version compare skipped")];
        } else {
            $findings = self::versionLeg($deployedDir, $bundledDir);
        }

        // The presence leg needs NO branch-2 special case: the checkout's own copy
        // has to carry an entry file and a node_modules exactly as a snapshot does.
        $findings = array_merge($findings, self::presenceLeg($deployedDir));

        // ONE launch disclosure per probe call that actually reached the legs (DL-237),
        // and UNIFORM on purpose. The first shape put it on the version-EQUAL branch
        // alone — a like-for-like replacement for the completeness leg that used to
        // live there — and that was wrong about what the disclosure is FOR. It does
        // not stand in for that leg; it says launchability was never measured, which
        // is equally true on a STALE, a NEWER and a REPO-DIRECT deployment. The
        // repo-direct case is the one that settles it: that is the topology the
        // channel-server README recommends, and it would have gotten a fully green
        // run with no disclosure at all — "green check, dark seat" reintroduced by
        // the fix for it.
        //
        // NOT a second line beside a single action (the DL-229 (h) objection), which
        // was weighed and does not reach this: (h) rejects two findings pointing at
        // the SAME remediation, and this one points at a different one — run the
        // seat-side assert, not re-copy the directory.
        //
        // It is also emitted UNCONDITIONALLY of what the legs found, including beside
        // a presence FAIL. Suppressing it there would make it depend on another leg's
        // severity, so a later change to that leg would silently change whether this
        // appears; and the sentence stays true regardless — the legs ran, the launch
        // still did not.
        //
        // The early returns above deliberately never reach here. An undeclared path,
        // the dangling / names-a-file fatals and the not-visible WARN each already
        // say the legs did not run, and a second not-measured line beside those IS
        // the (h) shape.
        return array_merge($findings, [self::launchNotMeasured($deployedDir)]);
    }

    /**
     * The ONE "we did not launch it" disclosure, spelled once and emitted once per PROBE
     * CALL that reached the legs ({@see self::probe()} on why it is uniform).
     *
     * NOT once per RUN, which three comments here used to say. `ChannelSnapshotCheck` is a
     * PER-AGENT check, so a two-agent install that declares `channel.server_path` twice
     * renders this line twice from one check id — which is exactly why `bridge:check`'s
     * closing tally counts FINDINGS and not checks.
     *
     * `unvalidated` and never `warn`: there is no obstruction here and nothing the
     * operator did wrong — the measurement is one this process structurally cannot
     * make, because the bridge commonly runs as a DIFFERENT OS user than the agent
     * (DL-227's same-box topology). Launching from here would certify that the entry
     * loads for the BRIDGE's user — its PATH, its node, its read access — which is a
     * proxy for the question again, and "assert the thing, not a proxy" is the whole
     * reason the file-set compare was retired (DL-237). Per DL-236 (c) the severity
     * never flips the exit code; it renders plain and feeds the run's closing tally.
     */
    private static function launchNotMeasured(string $deployedDir): Finding
    {
        return Finding::unvalidated("channel server snapshot at {$deployedDir} was NOT launch-tested — bridge:check never executes node, so a green run here is not evidence the entry will LOAD at the next session start (every leg above is stat-derived: a deployment missing a module the entry imports satisfies all of them and still dies on ERR_MODULE_NOT_FOUND). Run ".self::LAUNCH_ASSERT." {$deployedDir} ON THAT SEAT, as the OS user whose session launches the channel server — launching it from here would only prove it for the bridge's user, a different PATH and a different node");
    }

    /**
     * Compare two channel-server `package.json` versions the way the DECLARED
     * AUTHORITY does — `_version_tuple` in `bin/provision-board-tools.py`, the shipped
     * provisioner that decides whether a deployed snapshot gets replaced. Split on
     * `.`, take each chunk's LEADING digits (0 when it has none), compare element-wise
     * as integers; a shorter tuple sorts lower.
     *
     * DO NOT USE PHP's `version_compare()` HERE. It is the obvious reach and it is
     * wrong: it honors the pre-release/build tags the authority deliberately DROPS, so
     * it disagrees on 3 of the 7 pinned vectors (`0.8.0-rc1` vs `0.8.0`, `0.8.0` vs
     * `0.8.0+build5`, `1.0.0-alpha` vs `1.0.0`) — `bridge:check` would report "stale"
     * on a snapshot `bin/provision-board-tools.py` calls up to date, and re-syncing
     * would never clear the warning.
     *
     * The vector table is asserted in BOTH suites, in lockstep:
     * `tests/Unit/Support/ChannelSnapshotProbeTest.php` and
     * `bin/test_provision_board_tools.py` (class `VersionComparatorLockstep`).
     *
     * CONFORMANCE BOUND (measured, not assumed): the two agree for ASCII-digit
     * versions whose numeric chunks fit PHP's integer range. Outside that they
     * cannot: python's `\d` is Unicode-aware and its ints are arbitrary-precision,
     * while PHP saturates at PHP_INT_MAX and does not match non-ASCII digits at
     * all (they read as 0). Neither class is reachable through an npm `version`
     * field, so the divergence is documented rather than chased — and `/u` on the
     * pattern below does NOT close it: `[0-9]` is an ASCII class with or without
     * the modifier (`preg_match('/^[0-9]+/u', '٣٢')` matches nothing, exactly as
     * it does unmodified). Reaching those digits would take `\d` + `/u`, which
     * then matches `٣٢` and casts it to 0 — still not python's 32 — while `/u`
     * alone newly breaks on invalid UTF-8, where `preg_match` returns false and
     * the chunk collapses to 0 (`2\xff` reads as 2 today).
     *
     * @return int negative when $a is older, 0 when equal, positive when $a is newer
     */
    public static function compareVersions(string $a, string $b): int
    {
        $ta = self::versionTuple($a);
        $tb = self::versionTuple($b);
        $shared = min(count($ta), count($tb));
        for ($i = 0; $i < $shared; $i++) {
            if ($ta[$i] !== $tb[$i]) {
                return $ta[$i] <=> $tb[$i];
            }
        }

        return count($ta) <=> count($tb);
    }

    /**
     * @return list<int>
     */
    public static function versionTuple(string $version): array
    {
        $parts = [];
        foreach (explode('.', $version) as $chunk) {
            // [0-9], not \d: the ASCII scope is deliberate and is the bound the
            // lockstep with the python authority actually holds over (see
            // {@see self::compareVersions()}), so it is spelled out rather than
            // left to whether the /u modifier happens to be present.
            $parts[] = preg_match('/^[0-9]+/', $chunk, $m) === 1 ? (int) $m[0] : 0;
        }

        return $parts;
    }

    /**
     * WARN leg: is the deployed copy older than the one this checkout ships? Never a
     * fail — a stale snapshot still launches, it just lacks newer fixes.
     *
     * It is a DRIFT question and only a drift question — on EVERY branch, not just
     * the stale one. A green `is current` is not a loadability verdict: the DL-230
     * incident is a current version stamp cherry-picked onto a deployment missing a
     * module the entry imports, and nothing in this leg would see it. The check that
     * would is a LAUNCH, it belongs to the seat, and {@see self::probe()} discloses
     * its absence ONCE for the whole run rather than per branch (DL-237) — so this
     * leg deliberately says nothing about it and no branch here carries a special
     * case for it.
     *
     * PRECONDITION (established by the caller's one gate): $deployedDir is
     * traversable by this process, so the `package.json` stat below is conclusive.
     *
     * @return list<Finding>
     */
    private static function versionLeg(string $deployedDir, string $bundledDir): array
    {
        $resync = self::resyncCommand($deployedDir, $bundledDir);
        $deployed = self::readManifest($deployedDir.'/package.json');
        if ($deployed['status'] !== 'ok') {
            // ONE cause per message, and the destructive advice ONLY where it is the
            // answer. "missing or unreadable … cp -R" was false for three of these
            // four causes and wrong for two: `cp -R` does not fix a permission denial
            // (the copy lands with the same ownership problem) and does not need to
            // replace a whole directory to fix one corrupt manifest — while it DOES
            // overwrite the entry file and every other local edit on its way past.
            $advice = match ($deployed['status']) {
                'absent' => $resync,
                'unreadable' => "re-run bridge:check as the agent's user, or grant it read access to the file",
                default => 'repair the manifest — its `version` field is what the staleness compare reads',
            };

            return [Finding::unvalidated("channel server snapshot at {$deployedDir}: package.json ".self::manifestReason($deployed['status'])." — cannot tell whether the deployed copy is stale; {$advice}")];
        }

        // The BUNDLED manifest is this checkout's own file and deliberately does NOT
        // go through visibleOrUnverified: an unreadable one already lands on the warn
        // below, which names its own cause and carries no destructive remediation —
        // whereas the guard's message would talk about the AGENT's deployed directory
        // while naming a checkout file.
        $bundled = self::readManifest($bundledDir.'/package.json');
        if ($bundled['status'] !== 'ok') {
            // The action is SPELLED OUT. It was found silent while its sibling — the
            // unenumerable-reference warn the completeness leg carried — named its
            // remedy, and one of a matched pair being silent is a divergence, not a
            // message lacking polish: the operator reads the silent half as
            // unactionable when the fix is the same class of thing (restore or repair
            // a tracked file). DL-236 (h) fixed it; the sibling has since gone with
            // its leg (DL-237), so this is the only survivor of that pair — the
            // reason it spells its action is unchanged.
            return [Finding::unvalidated("this checkout's {$bundledDir}/package.json ".self::manifestReason($bundled['status'])." — the deployed snapshot at {$deployedDir} (version {$deployed['version']}) cannot be version-compared; that file is tracked in this checkout, so restore or repair it, and check that this process can read it")];
        }

        $comparison = self::compareVersions($deployed['version'], $bundled['version']);
        if ($comparison < 0) {
            return [Finding::warn("channel server snapshot at {$deployedDir} is STALE (deployed {$deployed['version']} < bundled {$bundled['version']}) — the next session starts on the older copy; {$resync}. To stop it recurring, deploy as a SYMLINK to {$bundledDir} rather than a copy: it resolves into the checkout, so there is nothing left to drift (a copy is still the answer when the deployment is on another host or another OS user's filesystem — see docs/multi-host.md)")];
        }

        // `>=`, and no second line for the NEWER case. Until DL-237 one existed, and
        // its entire content was "completeness against X SKIPPED" — an announcement
        // about a leg that no longer runs on any branch. Removed rather than
        // rewritten: operator-facing output naming machinery that is gone is worse
        // than no line. Whether the deployment will LAUNCH is not this leg's question
        // on any branch either; {@see self::probe()} discloses that once, uniformly.
        return [Finding::ok("channel server snapshot at {$deployedDir} is current (deployed {$deployed['version']} >= bundled {$bundled['version']})")];
    }

    /**
     * The one re-sync instruction both WARN sites hand out, spelled once.
     */
    private static function resyncCommand(string $deployedDir, string $bundledDir): string
    {
        return "re-copy the WHOLE directory (cp -R {$bundledDir}/. {$deployedDir}/) then run npm ci in it";
    }

    /**
     * FAIL leg: are the two things a launch NEEDS actually on disk? Both are
     * definitively fatal at the next session start and invisible to every other
     * check, and each is a single existence test with no false-positive surface:
     *
     *  - the ENTRY file. Absent, `channel.server_path` is not pointing at a
     *    channel-server deployment at all (an empty or wrong directory), and there
     *    is nothing for the MCP client to launch.
     *  - `node_modules`. Bare import specifiers RESOLVING is npm's business, but the
     *    directory being absent is not: the entry then dies on
     *    `ERR_MODULE_NOT_FOUND` — exactly what a `cp -R`'d snapshot whose operator
     *    stopped before `npm ci` looks like.
     *
     * This leg answers "is there anything here to launch?", and NOTHING ELSE. It is
     * not asked "does this match the reference?" — that comparison was retired
     * (DL-237) after a launch proved strictly more precise than it in both
     * directions — and it is not asked "will this launch?" either: that is the seat's
     * own {@see self::LAUNCH_ASSERT}, run there because the bridge's OS user is not
     * the agent's. No file's CONTENT is read here.
     *
     * This is NOT a load test — nothing in this class executes node — so the messages
     * claim only what was stat'ed.
     *
     * PRECONDITION (established by the caller's one gate): $deployedDir is
     * traversable by this process, and both stats below are DIRECT CHILDREN of it,
     * so both are conclusive.
     *
     * @return list<Finding>
     */
    private static function presenceLeg(string $deployedDir): array
    {
        $entry = $deployedDir.'/'.self::ENTRY_FILE;
        if (! is_file($entry)) {
            return [Finding::fail("channel server entry {$entry} does not exist — channel.server_path does not point at a channel-server deployment; the MCP server will not launch at next session start")];
        }

        if (! is_dir($deployedDir.'/'.self::NODE_MODULES)) {
            return [Finding::fail("channel server dependencies are not installed at {$deployedDir} (no node_modules) — the entry's bare imports (the MCP SDK, hono) die on ERR_MODULE_NOT_FOUND at next session start; run npm ci in {$deployedDir}")];
        }

        return [Finding::ok("channel server deployment at {$deployedDir} has its entry file and node_modules — a presence check, not a load test: nothing here executes node, and whether the installed dependency TREE is complete is npm ci's business")];
    }

    /**
     * Read a package.json's `version`, keeping the FOUR causes of "no version" apart
     * — they are four different operator situations and only one of them wants the
     * destructive re-copy. `version` is `''` when the file parses but declares none
     * (what the python authority's `_package_version` returns).
     *
     * @return array{status: 'ok'|'absent'|'unreadable'|'malformed', version: string}
     */
    private static function readManifest(string $path): array
    {
        if (! is_file($path)) {
            return ['status' => 'absent', 'version' => ''];
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return ['status' => 'unreadable', 'version' => ''];
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return ['status' => 'malformed', 'version' => ''];
        }
        $version = $decoded['version'] ?? '';

        return ['status' => 'ok', 'version' => is_scalar($version) ? (string) $version : ''];
    }

    /**
     * How a non-`ok` {@see self::readManifest()} status reads in a message.
     */
    private static function manifestReason(string $status): string
    {
        return match ($status) {
            'absent' => 'is not present',
            'unreadable' => 'exists but is not readable by this user',
            default => 'does not parse as a JSON object',
        };
    }

    /**
     * THE guard in front of every verdict in this class that would either ASSERT
     * ABSENCE or hand out DESTRUCTIVE remediation off a stat. Returns the "could not
     * be validated" WARN when this process cannot stat $path at all, and null when it
     * can. TWO call sites, one per population whose traversability is an INDEPENDENT
     * question: the configured path (before the existence verdict), and the deployed
     * directory (once, covering every stat the version + presence legs make — all of
     * them are DIRECT children of it, so neither re-guards). Two is the complete set
     * again as of DL-237: the third population DL-230 added — the deployed
     * SUBDIRECTORIES its completeness walk descended into — went with that walk, and
     * nothing here stats below a direct child any more. The one deliberately
     * unguarded stat is this CHECKOUT's own bundled `package.json`
     * ({@see self::versionLeg()}), which lands on its own accurate, non-destructive
     * WARN.
     *
     * A path we cannot SEE is not a path that is gone: `is_dir()`/`is_file()` return
     * false for EACCES exactly as they do for ENOENT, so on an untraversable path
     * "absent" is not a conclusion we are entitled to draw — and the bridge routinely
     * runs as a different OS user than the agent (DL-227's same-box topology), with
     * the DEPLOYED DIRECTORY ITSELF commonly 0700 to that user. Asserting the fatal
     * there would be the confident-wrong-answer this whole check exists to avoid, and
     * its remediation (`cp -R` over a healthy current deployment) is destructive.
     *
     * The converse is unavoidable and accepted: under an untraversable directory a
     * genuinely dangling path also reports unverified rather than fatal. The process
     * cannot distinguish them — no heuristic can — so the snapshot legs are
     * CONCLUSIVE ONLY when the bridge can traverse to the deployed directory. Said
     * plainly in `docs/config-schema.md`.
     *
     * @param  string  $display  what to NAME in the message. Required, because at
     *                           the deployed-directory site $path is a CHILD probe
     *                           (the entry file) while `+x` on the DIRECTORY is what
     *                           the operator has to grant — naming $path there sends
     *                           them to chmod a file.
     */
    private static function visibleOrUnverified(string $path, string $display): ?Finding
    {
        if (self::ancestorIsTraversable($path)) {
            return null;
        }

        return self::unverifiedWarn($display);
    }

    /**
     * The ONE "could not be validated" message, spelled once and kept separate from
     * the guard's control flow. Reached through {@see self::visibleOrUnverified()},
     * which is its only caller since DL-237 retired the completeness walk — that walk
     * established the denial segment-by-segment as it descended, so it already knew
     * which directory had denied traversal and called this directly rather than
     * re-deriving the answer through a second stat.
     */
    private static function unverifiedWarn(string $display): Finding
    {
        return Finding::unvalidated("channel server path {$display} is not visible to this user — a directory above it denies this process traversal (the bridge commonly runs as a different OS user than the agent, and the deployed directory itself is often 0700), so the snapshot could NOT be validated; re-run bridge:check as the agent's user, or grant it traversal");
    }

    /**
     * Can this process even ANSWER "does $path exist?" — i.e. is the nearest
     * existing ancestor directory traversable (+x) by us? Readability (+r) is
     * deliberately NOT required: a 0711 directory answers stat on its children fine.
     * Only ever called through {@see self::visibleOrUnverified()}.
     */
    private static function ancestorIsTraversable(string $path): bool
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

    /**
     * realpath() when the path fully exists; else the symlink's target (the STALE
     * path a dangling link points at, which the existence branch reports); else the
     * literal. Never false — the caller always has a concrete path to name.
     */
    private static function resolveNonStrict(string $path): string
    {
        $real = realpath($path);
        if ($real !== false) {
            return $real;
        }
        if (is_link($path)) {
            $target = readlink($path);
            if ($target !== false) {
                return str_starts_with($target, '/') ? $target : dirname($path).'/'.$target;
            }
        }

        return $path;
    }
}
