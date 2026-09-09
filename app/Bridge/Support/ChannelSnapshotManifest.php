<?php

namespace App\Bridge\Support;

use App\Bridge\Exceptions\PathResolvesToNoFileException;
use App\Bridge\Exceptions\UnreadableFileException;

/**
 * The channel-server snapshot MANIFEST: read a `package.json`'s version, and order two of
 * those versions the way the declared authority does (card#8974 r3).
 *
 * ⭐ WHY THIS IS ITS OWN CLASS, AND IT IS A SECURITY BOUNDARY RATHER THAN TIDINESS.
 * {@see ChannelSnapshotProbe} is the class that walks a DEPLOYED channel-server directory —
 * a path under another OS user's home that this process cannot trust — and its NARROW
 * PUBLIC SURFACE IS ITSELF AN INVARIANT: `bin/test_check_channel_snapshot.py` pins every
 * `ChannelSnapshotProbe::` call in `app/` to exactly `probe()`, taking only inert arguments,
 * so nothing executable can be smuggled in on the way. These four members are utilities the
 * probe USES; they are not entry points into the untrusted-directory walk. Hoisting them
 * ONTO the probe to share them (the first cut of card#8974) widened that surface from one
 * entry point to four and was caught by the guard, correctly.
 *
 * ⛔ THE SECOND CALLER IS NOT READING AN UNTRUSTED PATH AT ALL.
 * `App\Bridge\Check\Checks\BoardToolsClientHalfCheck` reads THIS CHECKOUT's own bundled
 * `examples/channel-servers/package.json` to compare a seat-reported client version against
 * it — a tracked file belonging to the bridge — so routing that read through the probe was
 * wrong on its own terms, before any guard fired.
 *
 * ⚑ NO `new`, NO EXEC PRIMITIVE — and that half IS enforced rather than intended: this
 * class is on the probe's `_PROBE_COLLABORATORS` list, so the same no-exec scan the probe
 * gets is run over this file too. Keep it that way — it is reachable from the probe.
 * ⚠ It said NO IMPORTS as well until card#9121, and that clause was never the enforced one
 * (the no-`use` assertion is taken on `ChannelSnapshotProbe.php` alone, which is where the
 * pinned public surface lives). It now imports the two refusal TYPES {@see self::readManifest()}
 * catches; the reader it calls is same-namespace, and all three files were added to
 * `_PROBE_COLLABORATORS` in the same change, so the scan follows the hop rather than
 * stopping at a boundary that had moved.
 *
 * ⚠ THE COMPARATOR IS A CONFORMANCE CONTRACT WITH `bin/provision-board-tools.py`, not a
 * free choice of semantics; {@see self::compareVersions()} carries the rule, the vector
 * table's two homes, and the measured bound.
 */
final class ChannelSnapshotManifest
{
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
     * Read a package.json's `version`, keeping the FOUR causes of "no version" apart
     * — they are four different operator situations and only one of them wants the
     * destructive re-copy. `version` is `''` when the file parses but declares none
     * (what the python authority's `_package_version` returns).
     *
     * ⭐ THE READER IS {@see UntrustedPathContents} (card#9121, adopting card#9037's
     * primitive). The DEPLOYED manifest this leg's first caller passes lives under another
     * OS user's home — the account being inspected chooses what this process opens — and
     * the shape this replaces was `is_file()` plus an unbounded `@file_get_contents()`,
     * where `is_file()` follows the link and answers about the TARGET. The BUNDLED manifest
     * the other two callers pass is this checkout's own tracked file and is not an untrusted
     * path at all; it goes through the same reader because ONE function reads both and a
     * second, laxer reader for the trusted operand is a fork of one behaviour (canon #5).
     * The only cost on that operand is that a SYMLINKED bundled `package.json` would now be
     * refused rather than followed — this repo tracks a regular file there.
     *
     * @return array{status: 'ok'|'absent'|'unreadable'|'malformed', version: string}
     */
    public static function readManifest(string $path): array
    {
        try {
            $raw = UntrustedPathContents::read($path, 'package.json');
        } catch (PathResolvesToNoFileException) {
            // ESTABLISHING: a reader following this path gets no bytes, ever — and every
            // shape that reaches this arm was `is_file()`-false before the migration, so
            // it lands where it always landed. It IS the `absent` operator situation: the
            // deployment has no manifest, and the re-copy is the answer.
            return ['status' => 'absent', 'version' => ''];
        } catch (UnreadableFileException) {
            // WITHHOLDING: something is at the path (a refusal is only raised after an
            // `lstat` found it) and this process did not read it. Which is what `unreadable`
            // has always meant here — see {@see self::manifestReason()} for the wording that
            // had to stop naming permissions as the sole cause.
            return ['status' => 'unreadable', 'version' => ''];
        }
        if ($raw === null) {
            return ['status' => 'absent', 'version' => ''];
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
     *
     * It travels WITH {@see self::readManifest()} because a second phrasing of "is not
     * present" beside this one is how an operator ends up reading two different sentences
     * for one file state.
     */
    public static function manifestReason(string $status): string
    {
        return match ($status) {
            'absent' => 'is not present',
            // ⚠ NOT "is not readable by this user" any more (card#9121). Since the read went
            // through the guarded reader, a permission denial is one of several causes that
            // reach this status — a path whose bytes cannot be attributed to the deployment
            // is another — and naming the one an operator can act on as if it were the only
            // one is a wrong-but-specific cause. What every cause shares, and all this
            // status establishes, is that something is at the path and this run did not read it.
            'unreadable' => 'exists but was NOT read by this process',
            default => 'does not parse as a JSON object',
        };
    }
}
