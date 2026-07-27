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
 * Severity ∈ {ok, warn, unvalidated, fail}; only `fail` flips `bridge:check`'s exit.
 * `unvalidated` (card 5170) is NOT a verdict about the deployment — it says the leg did
 * not run, so a green `bridge:check` is not evidence the snapshot is sound; `ok` means
 * measured-and-clean and must never carry a not-measured finding. Otherwise the same
 * `{severity, message}` finding shape the SSH transport probe emits. Branch order
 * is load-bearing (see {@see self::probe()}).
 */
final class ChannelSnapshotProbe
{
    /** The channel server's entry point — the file the agent's MCP client launches. */
    public const ENTRY_FILE = 'agent-webhook-bridge-channel.mjs';

    /** The directory `npm ci` creates, and the one the dependency leg owns. */
    private const NODE_MODULES = 'node_modules';

    /**
     * How many missing paths the completeness FAIL names before it summarizes. The
     * reference set grows every time the channel server gains a test file, and this
     * message is one console line — an operator needs the shape of the gap, not a
     * transcript of it.
     */
    private const MISSING_LIST_CAP = 8;

    private const PATH_PRESENT = 'present';

    private const PATH_ABSENT = 'absent';

    private const PATH_BLOCKED = 'blocked';

    /**
     * @param  ?string  $serverPath  the agent's resolved `channel.server_path` (already
     *                               normalized to a directory), or null when undeclared
     * @param  string  $bundledDir  this checkout's `examples/channel-servers`
     * @return list<array{severity: string, message: string}> each severity ∈ {ok, warn, unvalidated, fail}
     */
    public static function probe(?string $serverPath, string $bundledDir): array
    {
        if ($serverPath === null) {
            return [self::unvalidated('channel.server_path not declared — snapshot not validated')];
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
                return [self::fail("channel server path {$where} names a file, not the channel-server directory — point channel.server_path at the deployed DIRECTORY (only the ".self::ENTRY_FILE.' entry form is normalized to its directory for you)')];
            }

            return [self::fail("channel server path does not resolve (dangling symlink or removed directory): {$where} — the MCP server will not launch at next session start; the link target moved, repoint the symlink (or re-deploy the directory)")];
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
        // as a blanket licence: a new stat on a path that is not a direct child, or
        // that is expected to be a symlink, needs its own guard. The completeness
        // walk (DL-230) is the first leg that stats DEEPER than a direct child, and
        // it carries exactly that — {@see self::deployedState()} re-asks the question
        // per subdirectory as it descends.
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
            // Repo-direct: the version compare would be a self-compare, and so would
            // the completeness compare — which is why the latter lives inside the
            // version leg rather than beside it (DL-230).
            $findings = [self::ok("channel server path {$where} IS this checkout's examples/channel-servers — no snapshot to drift, version compare skipped")];
        } else {
            $findings = self::versionLeg($deployedDir, $bundledDir);
        }

        // The presence leg needs NO branch-2 special case: the checkout's own copy
        // has to carry an entry file and a node_modules exactly as a snapshot does.
        return array_merge($findings, self::presenceLeg($deployedDir));
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
     * It also OWNS the version gate in front of the completeness leg (DL-230), which
     * is why the two live in one function: the completeness question may only be
     * asked of a snapshot whose version EQUALS this checkout's, and the two
     * `package.json` reads that answer that are right here. The repo-direct branch
     * never reaches this leg, so it skips completeness structurally — comparing the
     * checkout's file set against itself is a no-op by construction.
     *
     * PRECONDITION (established by the caller's one gate): $deployedDir is
     * traversable by this process, so the `package.json` stat below is conclusive.
     *
     * @return list<array{severity: string, message: string}>
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

            return [self::warn("channel server snapshot at {$deployedDir}: package.json ".self::manifestReason($deployed['status'])." — cannot tell whether the deployed copy is stale; {$advice}")];
        }

        // The BUNDLED manifest is this checkout's own file and deliberately does NOT
        // go through visibleOrUnverified: an unreadable one already lands on the warn
        // below, which names its own cause and carries no destructive remediation —
        // whereas the guard's message would talk about the AGENT's deployed directory
        // while naming a checkout file.
        $bundled = self::readManifest($bundledDir.'/package.json');
        if ($bundled['status'] !== 'ok') {
            // The action is SPELLED OUT, matching the unenumerable-reference warn
            // below: both say "this checkout's X could not be read", and one of a
            // matched pair naming its remedy while the other does not is a
            // divergence, not a stylistic choice.
            return [self::warn("this checkout's {$bundledDir}/package.json ".self::manifestReason($bundled['status'])." — the deployed snapshot at {$deployedDir} (version {$deployed['version']}) cannot be version-compared; that file is tracked in this checkout, so restore or repair it, and check that this process can read it")];
        }

        $comparison = self::compareVersions($deployed['version'], $bundled['version']);
        if ($comparison < 0) {
            // STALE ⇒ the completeness leg is SKIPPED ENTIRELY (DL-230), and no line
            // says so on purpose: an older whole-directory copy is legitimately
            // missing files this checkout has since added, and this warn already
            // carries the identical remediation. A second line beside it would point
            // at the same single action (the DL-229 (h) objection).
            return [self::warn("channel server snapshot at {$deployedDir} is STALE (deployed {$deployed['version']} < bundled {$bundled['version']}) — the next session starts on the older copy; {$resync}. To stop it recurring, deploy as a SYMLINK to {$bundledDir} rather than a copy: it resolves into the checkout, so there is nothing left to drift (a copy is still the answer when the deployment is on another host or another OS user's filesystem — see docs/multi-host.md)")];
        }

        $current = self::ok("channel server snapshot at {$deployedDir} is current (deployed {$deployed['version']} >= bundled {$bundled['version']})");

        // NEWER than this checkout ⇒ skipped, and SAID rather than left to fall out
        // of the `>=` above: the older reference is not authoritative for a newer
        // deployment, so a file it does not ship may simply not exist here yet, and
        // "missing" would be a claim about this checkout, not about the deployment.
        if ($comparison > 0) {
            return [$current, self::ok("channel server snapshot at {$deployedDir} is NEWER than this checkout (deployed {$deployed['version']} > bundled {$bundled['version']}) — completeness against {$bundledDir} SKIPPED: an older reference cannot say what a newer snapshot should contain")];
        }

        return array_merge([$current], self::completenessLeg($deployedDir, $bundledDir, $deployed['version']));
    }

    /**
     * FAIL leg, and ONLY for a snapshot whose version EQUALS this checkout's
     * (DL-230). Does the deployment hold every file a whole-directory copy of
     * `examples/channel-servers` delivers?
     *
     * WHY THE GATE IS THE WHOLE DESIGN. Ungated, this leg reddens the one population
     * that legitimately lacks reference files — a copy taken at an older tag, which
     * is missing everything the checkout has added since (a pre-v0.69.0 copy reads 4
     * of 10 missing) — and for that population the verdict is unactionable anyway,
     * because the STALE warn already carries the identical remediation. That is why
     * DL-229 cut it. Version-EQUAL is a different population entirely: any change to
     * any TRACKED file under `examples/channel-servers/` requires a `package.json`
     * version bump (the DL-038 guard in
     * `.github/workflows/channel-server-supply-chain.yml` enforces it on every PR),
     * so at equal versions the tracked file sets match. That is what makes the
     * incident this leg exists for visible: an entry + `package.json` cherry-picked
     * from the current reference (carrying the version stamp with them) onto an older
     * `node_modules`, with a sibling module left behind — every other leg green,
     * `node` dead on `ERR_MODULE_NOT_FOUND`.
     *
     * THE MESSAGE CLAIMS ONLY WHAT WAS MEASURED — the {@see self::presenceLeg()}
     * discipline, and read this before "improving" it back. It is tempting to state
     * what the FAIL usually means ("this deployment was assembled file-by-file rather
     * than copied whole"), and that claim does not hold: the DL-038 guard governs the
     * TRACKED set, while the reference set below is the WORKING TREE (its only
     * exclusion is `node_modules`). An UNTRACKED stray in this checkout — a `.orig`
     * / `.rej` / editor backup, which `git apply --3way` mints and no `.gitignore`
     * here covers — joins the reference set with no version bump behind it, so a
     * FAITHFUL whole-directory copy taken before it landed reads as missing a file.
     * The verdict is still right for that operator (re-copying does deliver it); a
     * sentence telling them they assembled their deployment by hand is not, and a
     * diagnostic that is confidently wrong about what its operator did is the
     * DL-229 (f) defect this leg's own gate exists to avoid. So: the two counts, the
     * missing paths, the consequence, the remediation — no cause. DL-230 (f).
     *
     * EXTRA files in the deployment are NOT a defect and are never looked at: local
     * modules, scratch files and an operator's own additions are their business. Only
     * the reference direction is checked, and no file's CONTENT is read.
     *
     * @return list<array{severity: string, message: string}>
     */
    private static function completenessLeg(string $deployedDir, string $bundledDir, string $version): array
    {
        $reference = self::referenceFileSet($bundledDir);
        if ($reference === null) {
            // A set-derived verdict is no more ours to draw than a stat-derived one
            // when the read behind it did not happen. Letting an unreadable reference
            // directory collapse to an empty set would certify "nothing is missing"
            // — a false GREEN, precisely the defect class this leg exists to close.
            // This is the BUNDLED side, so it gets its own accurate message rather
            // than the deployed directory's visibility WARN. (A reference that reads
            // fine but is genuinely empty cannot reach here: the version leg needs
            // its package.json first.)
            return [self::warn("this checkout's reference set at {$bundledDir} could not be enumerated — the deployed snapshot at {$deployedDir} could NOT be checked for completeness; check that this process can read the checkout's examples/channel-servers")];
        }

        // A BLOCKED path is dropped from the accounting, never from the WALK. DL-230
        // (e)'s rule is "absence is not conclusive where we could not see" — it is
        // not "one unseeable path voids every seen one", and returning here on the
        // first block did exactly that: a genuinely absent module plus a 0700
        // `tests/` emitted the visibility WARN alone and exited 0, which is this
        // card's own defect class (green check, dark seat) in a narrow
        // sub-population. Blocked DIRECTORIES are deduped because they are what an
        // operator chmods, and the blocked-path COUNT is carried into the FAIL so
        // the missing list is never read as a complete accounting.
        $missing = [];
        $blockedDirs = [];
        $unchecked = 0;
        foreach ($reference as $relative) {
            $blockedDir = null;
            $state = self::deployedState($deployedDir, $relative, $blockedDir);
            if ($state === self::PATH_BLOCKED) {
                $blockedDirs[(string) $blockedDir] = true;
                $unchecked++;

                continue;
            }
            if ($state === self::PATH_ABSENT) {
                $missing[] = $relative;
            }
        }

        $unverified = array_map(
            static fn (string $dir): array => self::unverifiedWarn($dir),
            array_keys($blockedDirs),
        );

        if ($missing !== []) {
            $caveat = $unchecked > 0
                ? " A further {$unchecked} reference path(s) could not be checked at all (a directory in the deployment denies this process traversal — see the accompanying warning), so this list is what was SEEN to be missing, not necessarily all of it."
                : '';

            return array_merge([self::fail("channel server snapshot at {$deployedDir} claims version {$version} — the same version this checkout ships — but is MISSING ".count($missing).' of the '.count($reference).' files a whole-directory copy of '.$bundledDir.' delivers: '.self::summarize($missing).'.'.$caveat.' When a missing file is a module the entry imports, node dies on ERR_MODULE_NOT_FOUND at next session start. '.self::resyncCommand($deployedDir, $bundledDir))], $unverified);
        }

        // Nothing SEEN to be missing. With blocked paths that is not "complete" —
        // the ok text below would be a claim about files this process never stat'ed.
        if ($unverified !== []) {
            return $unverified;
        }

        return [self::ok("channel server snapshot at {$deployedDir} holds every file this checkout's ".count($reference).'-file reference set ships (extra files of your own are not a finding)')];
    }

    /**
     * Every file a `shutil.copytree(..., ignore=ignore_patterns("node_modules"))` of
     * $dir delivers, as sorted relative paths — RECURSIVE, dotfiles INCLUDED,
     * anything named `node_modules` excluded at ANY depth. Null when a directory
     * could not be read at all (see {@see self::completenessLeg()} on why an empty
     * result may not be treated as "nothing to compare").
     *
     * THE ENUMERATION AUTHORITY IS `_deploy_snapshot` in
     * `bin/provision-board-tools.py` — the shipped provisioner that actually writes
     * these deployments. This mirrors its `copytree` call rather than choosing its
     * own rule, and the conformance is pinned on BOTH sides in lockstep:
     * `tests/Unit/Support/ChannelSnapshotProbeTest` and `bin/test_provision_board_tools.py`
     * (class `SnapshotFileSetLockstep`) run the SAME synthetic tree through the two
     * implementations. Public for exactly that reason, as `versionTuple` is.
     *
     * @return ?list<string>
     */
    public static function referenceFileSet(string $dir): ?array
    {
        $files = self::collectReferenceFiles($dir, '');
        if ($files !== null) {
            sort($files);
        }

        return $files;
    }

    /**
     * @return ?list<string>
     */
    private static function collectReferenceFiles(string $dir, string $prefix): ?array
    {
        $entries = @scandir($dir);
        if ($entries === false) {
            return null;
        }

        $files = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === self::NODE_MODULES) {
                continue;
            }
            $path = $dir.'/'.$entry;
            if (is_dir($path)) {
                $nested = self::collectReferenceFiles($path, $prefix.$entry.'/');
                if ($nested === null) {
                    return null;
                }
                $files = array_merge($files, $nested);

                continue;
            }
            $files[] = $prefix.$entry;
        }

        return $files;
    }

    /**
     * Does the deployment hold its counterpart of the reference-relative path
     * $relative — and are we ENTITLED TO SAY? Walks the path one segment at a time
     * because the caller's hoisted `+x` gate covers DIRECT CHILDREN of the deployed
     * directory only: a reference file under `tests/` is stat'ed through a
     * subdirectory whose own traversability is an independent question, and a `0700`
     * `tests/` would otherwise read as "all of its files are missing" and hand out
     * the destructive re-copy — the DL-229 round-1 blocker, one level down.
     *
     * PATH_ABSENT is conclusive precisely because the segment above it was proven
     * traversable first. The first iteration re-asks what the hoisted gate already
     * proved; that keeps the walk's precondition local rather than inherited, and
     * from the second segment on it is the only thing asking.
     *
     * @param  ?string  $blockedDir  set to the directory that denied traversal
     * @return self::PATH_*
     */
    private static function deployedState(string $deployedDir, string $relative, ?string &$blockedDir): string
    {
        $path = $deployedDir;
        $segments = explode('/', $relative);
        $last = array_key_last($segments);
        foreach ($segments as $i => $segment) {
            if (! is_executable($path)) {
                $blockedDir = $path;

                return self::PATH_BLOCKED;
            }
            $path .= '/'.$segment;
            if (! ($i === $last ? file_exists($path) : is_dir($path))) {
                return self::PATH_ABSENT;
            }
        }

        return self::PATH_PRESENT;
    }

    /**
     * @param  list<string>  $missing
     */
    private static function summarize(array $missing): string
    {
        $shown = array_slice($missing, 0, self::MISSING_LIST_CAP);
        $rest = count($missing) - count($shown);

        return implode(', ', $shown).($rest > 0 ? " (+{$rest} more)" : '');
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
     * This leg answers "is there anything here to launch?", never "does this match
     * the reference?" — that second question belongs to
     * {@see self::completenessLeg()}, which may only be asked of a VERSION-EQUAL
     * snapshot (DL-230). No file's CONTENT is read by either.
     *
     * This is NOT a load test — nothing here executes node — so the messages claim
     * only what was stat'ed.
     *
     * PRECONDITION (established by the caller's one gate): $deployedDir is
     * traversable by this process, and both stats below are DIRECT CHILDREN of it,
     * so both are conclusive.
     *
     * @return list<array{severity: string, message: string}>
     */
    private static function presenceLeg(string $deployedDir): array
    {
        $entry = $deployedDir.'/'.self::ENTRY_FILE;
        if (! is_file($entry)) {
            return [self::fail("channel server entry {$entry} does not exist — channel.server_path does not point at a channel-server deployment; the MCP server will not launch at next session start")];
        }

        if (! is_dir($deployedDir.'/'.self::NODE_MODULES)) {
            return [self::fail("channel server dependencies are not installed at {$deployedDir} (no node_modules) — the entry's bare imports (the MCP SDK, hono) die on ERR_MODULE_NOT_FOUND at next session start; run npm ci in {$deployedDir}")];
        }

        return [self::ok("channel server deployment at {$deployedDir} has its entry file and node_modules — a presence check, not a load test: nothing here executes node, and whether the installed dependency TREE is complete is npm ci's business")];
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
     * them are DIRECT children of it, so neither re-guards). A THIRD population — the
     * deployed SUBDIRECTORIES the completeness walk descends into — is an independent
     * question again, and is guarded inside that walk ({@see self::deployedState()}),
     * which reaches the same message through {@see self::unverifiedWarn()}. The one
     * deliberately unguarded stat is this CHECKOUT's own bundled `package.json`
     * ({@see self::versionLeg()}), which lands on its own accurate, non-destructive
     * WARN — as does the checkout's own reference enumeration.
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
     * @return ?array{severity: string, message: string}
     */
    private static function visibleOrUnverified(string $path, string $display): ?array
    {
        if (self::ancestorIsTraversable($path)) {
            return null;
        }

        return self::unverifiedWarn($display);
    }

    /**
     * The ONE "could not be validated" message, spelled once. Reached either through
     * {@see self::visibleOrUnverified()} (which establishes the denial by stat) or
     * directly from the completeness walk, which establishes it segment-by-segment as
     * it descends and so already KNOWS which directory denied traversal — routing
     * that through the stat guard would only re-derive an answer it just computed.
     *
     * @return array{severity: string, message: string}
     */
    private static function unverifiedWarn(string $display): array
    {
        return self::warn("channel server path {$display} is not visible to this user — a directory above it denies this process traversal (the bridge commonly runs as a different OS user than the agent, and the deployed directory itself is often 0700), so the snapshot could NOT be validated; re-run bridge:check as the agent's user, or grant it traversal");
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

    /**
     * @return array{severity: string, message: string}
     */
    private static function ok(string $message): array
    {
        return ['severity' => 'ok', 'message' => $message];
    }

    /**
     * @return array{severity: string, message: string}
     */
    private static function warn(string $message): array
    {
        return ['severity' => 'warn', 'message' => $message];
    }

    /**
     * @return array{severity: string, message: string}
     */
    private static function unvalidated(string $message): array
    {
        return ['severity' => 'unvalidated', 'message' => $message];
    }

    /**
     * @return array{severity: string, message: string}
     */
    private static function fail(string $message): array
    {
        return ['severity' => 'fail', 'message' => $message];
    }
}
