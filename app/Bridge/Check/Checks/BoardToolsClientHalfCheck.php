<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\PerAgentCheck;
use App\Bridge\Check\Silence;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\ChannelSnapshotProbe;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use App\Bridge\Tools\BoardToolDispatcher;
use App\Bridge\Tools\CallProvenance;
use App\Bridge\Tools\ClientHalfLedger;
use App\Bridge\Tools\ClientVersion;
use Throwable;

/**
 * Does the CALLING SEAT's half of the board-tools door work? (card#7756 / DL-313)
 *
 * WHAT THIS CLOSES. Every other board-tools leg observes the BRIDGE side — the pinned
 * `authorized_keys` line, the flipped transport default, the writeback token's board
 * window. None of them can see the seat: its keypair, its seeded `known_hosts`, the
 * `BRIDGE_TOOLS_*` entries in its own `.mcp.json`, its deployed channel server. So a seat
 * that was NEVER WIRED client-side and a seat whose pinned line is merely absent printed
 * the same lines — and the two take OPPOSITE remedies. An operator acted on the wrong one
 * and spent a privileged remediation window on it.
 *
 * ⛔ THE BRIDGE MAY NOT SIMPLY GO AND LOOK. Reading `~<ssh_account>/.mcp.json` is the
 * obvious mechanism and is refused: an account may only read its own files. It is the same
 * rule that keeps `channel.server_path` an operator DECLARATION rather than an inference
 * off the agent's MCP config (DL-229), and the same one that stops `bridge:check` executing
 * the seat's channel server (DL-237). So the seat REPORTS BY CALLING — and it already does,
 * without a new tool or a single seat-side change: a successful board-tools call is the
 * report ({@see ClientHalfLedger}). ⚠ What the ledger records is that the DOOR opened for
 * this agent, and it cannot tell a seat's call from one the bridge itself made with that
 * agent's bearer or pinned key; the `ok` arm below says so in its own text.
 *
 * ⭐ TWO SEVERITIES, NOT THREE, AND "NEVER WIRED" IS NOT ONE OF THEM. A seat that can report
 * is BY DEFINITION wired, so "never wired" is unobservable from here — it is the same
 * absence as "wired, and quiet". The leg therefore reports:
 *   - a fresh successful call ⇒ `ok`, and the line claims ONLY what the row carries: a
 *     successful call was recorded for this agent, at that time, over that transport.
 *     ⛔ THE ROW IS EVIDENCE ABOUT THE CALL, NOT ABOUT THE CALLER. `bridge:check
 *     --probe-tools`, `bin/provision-board-tools.py --self-cert` and a hand-run
 *     `bridge:tools-call --agent=X` on this host all reach {@see BoardToolDispatcher}'s
 *     success point and stamp the same row, and the enablement runbook has the operator
 *     run the first of those BEFORE the seat's channel server is even restarted — so a
 *     green line straight after enablement may be the bridge's own call. The message
 *     names those three rather than leaving the reader to infer a seat behind the row.
 *   - no record, or one older than the freshness window ⇒ `unvalidated`. The leg did not
 *     answer its own question ({@see Severity} limb (a)/(c)) and NOTHING here
 *     distinguishes never-wired from merely-idle.
 *
 * ⭐ THE `ok` SEVERITY CARRIES TWO DIFFERENT CLAIMS SINCE card#7836 / DL-316, AND THE
 * DIFFERENCE IS THE WHOLE CARD. DL-313's caveat above was CORRECT and it was also all the
 * leg had: an adversarial review reproduced a green line from a process with no keypair, no
 * `known_hosts`, no `.mcp.json` and no channel server. The ssh door CAN discriminate, so
 * {@see CallProvenance} is recorded at the write site and this leg reports the stronger
 * claim exactly where it is earned:
 *   - `call_provenance = sshd` ⇒ the serving process had the shape of the pinned, pty-less
 *     forced command. ⭐ WHICH TERMS ESTABLISH THAT, WHAT IT RULES OUT, AND THE TWO THINGS IT
 *     DOES NOT are owned by {@see CallProvenance}'s docblock — this one does not restate
 *     them, and the `ok` message below is the RENDERING of that enumeration, so the two
 *     cannot drift apart without a test noticing.
 *   - anything else — `not_sshd`, or NULL for a row written before this column existed —
 *     keeps DL-313's wording UNCHANGED, to the byte. NULL is not "measured false"; it is a
 *     row from a writer that never asked, and an absent measurement may not be spent as
 *     either verdict.
 * ⛔ THE STRONGER LINE STILL DOES NOT NAME THE CALLER, AND SAYS SO ITSELF — a line reading
 * "the seat called" would be false on a host where `--probe-tools-ssh` had just run, and
 * false again for a cron entry that inherited `SSH_CONNECTION`. The remainder is PRINTED,
 * not merely known, because `Severity::Ok` is identical on both arms and the message is the
 * only place the difference can live.
 *
 * ⭐ THE REPORTED LINE ALSO CARRIES THE SEAT'S OWN SNAPSHOT VERSION SINCE card#8974 /
 * DL-364, AND THAT IS THE ONE THING HERE THAT CAN `warn`. Everything else this command
 * knows about the door is the BRIDGE's half; the seat's deployed channel-server version is
 * a fact only the seat can supply, and until it did, a tool absent from a STALE seat copy
 * and a tool absent from this bridge rendered identically — measured: a 0.4.4 seat against
 * a bridge bundling 0.9.12, with the missing tool attributed to the bridge. The line now
 * prints the reported version beside the version this checkout bundles:
 *   - reported and OLDER than the bundled snapshot ⇒ `warn`. It is a MEASURED conclusion
 *     about a real install fault, which is what separates it from the two `unvalidated`
 *     arms below — nothing stopped this measurement; it came back stale.
 *   - reported and at or ahead of the bundled snapshot ⇒ `ok`, versions printed.
 *   - NOT reported ⇒ `ok`, and the line says a client older than
 *     {@see ClientVersion::FIRST_REPORTING_SNAPSHOT} — or a caller that is not a channel
 *     server at all — sends no version. ⛔ An absent report is NOT a stale seat and must
 *     never be warned as one: it is the shape every pre-DL-364 client produces.
 *   - reported but this checkout's own bundled manifest could not be read ⇒ `ok`, and the
 *     line says the comparison was NOT MADE. ⛔ The whole finding deliberately does NOT
 *     become `unvalidated` there: its subject is the SEAT's report, which WAS measured, and
 *     spending a blind read of the BRIDGE's own file as if the seat had gone silent is the
 *     misattribution this card exists to remove, inverted.
 *
 * NEVER `fail`, on every arm: a `fail` would exit non-zero over a seat that is idle by
 * choice, or over a snapshot an operator has decided not to re-deploy yet. The UNREPORTED
 * arms stay `unvalidated` and never `warn` — telling an operator something is wrong when
 * the honest statement is that this run learned nothing is the defect the leg was built
 * against. The MESSAGE carries that bound rather than leaving the severity to imply it —
 * the remedy for an UNREPORTED seat is to ASK THE SEAT, never to re-provision it.
 *
 * ⚑ THE AGE IS ALWAYS PRINTED, INCLUDING ON THE GREEN LINE. The TTL exists only to stop an
 * ancient stamp reading as current; an operator judging "3h ago" for themselves is worth
 * more than a boolean they have to trust, and it is what makes a seat drifting toward
 * silence visible BEFORE it crosses the window.
 */
final class BoardToolsClientHalfCheck implements PerAgentCheck
{
    /**
     * The registry id, as a constant so a reader of this run's results can SELECT this
     * check by id rather than by matching its prose (card#8959) — the same reason
     * {@see SshPinnedLineCheck::ID} is one, and the same bound: selecting by id is what
     * keeps a check later registered in the same slot from silently feeding a consumer
     * that never asked for it.
     */
    public const ID = 'board_tools.client_half';

    /**
     * @param  string  $bundledDir  this checkout's `examples/channel-servers` — the
     *                              reference snapshot a reported client version is compared
     *                              against. Injected exactly as {@see ChannelSnapshotCheck}
     *                              takes it, rather than resolved from `base_path()` inside
     *                              the check, so a test can drive a KNOWN bundled version
     *                              against a known reported one; reading it here would make
     *                              every version assertion a function of whatever this
     *                              repo's snapshot happens to be on the day it runs.
     */
    public function __construct(private readonly string $bundledDir) {}

    public function id(): string
    {
        return self::ID;
    }

    /**
     * @return iterable<Finding|Silence>
     */
    public function runFor(AgentConfig $config, CheckContext $ctx): iterable
    {
        $bt = $config->boardTools;
        // The slot's loop runs over `$ctx->boardToolsEnabled`, so the caller never asks
        // this about a disabled agent. The guard is here because THIS CHECK's subject is
        // the seat behind an ENABLED block: an agent with no block has no client half, and
        // a declared silence is how that is said (a suppressed block is reported by its own
        // check, and an undeclared silence would read as this leg falling off its end).
        if ($bt === null || ! $bt->enabled) {
            yield Silence::because('this agent has no enabled board_tools block, so there is no client half to report on — a suppressed block is reported by its own check');

            return;
        }

        $name = $config->agentName;
        $ttl = self::ttlSeconds();

        try {
            // ⚑ THE WHOLE ROW IS RESOLVED HERE, INSIDE THE ENVELOPE, AND THE PLACEMENT IS
            // THE WHOLE POINT. Eloquent applies an enum cast LAZILY, on attribute access —
            // NOT on hydration — so a backing value this build cannot interpret throws a
            // `ValueError` at the READ. Read at the arm below, that throw is outside this
            // try and ABORTS `bridge:check`, which is the one thing a diagnostic command
            // may not do (CheckRunner deliberately does not catch). Measured, not reasoned:
            // a row inserted with an unknown value hydrates cleanly and throws on access.
            // The reader touches the cast for exactly that reason, so the throw arrives at
            // THIS call rather than wherever a field is first read.
            $record = ClientHalfLedger::lastSuccess($name);
        } catch (Throwable $e) {
            // Limb (a): the read never completed, so the absence below would be this run's
            // failure rather than the seat's silence. An unmigrated install is the live
            // cause — `bridge:check` must not ABORT on it (CheckRunner deliberately does
            // not catch), and must not report a green or a red it did not measure.
            yield Finding::unvalidated("board_tools: agent {$name}: could NOT read the client-half record — {$e->getMessage()}. This run says nothing about the seat's board-tools client half in either direction. If this install has not run `php artisan migrate` since the upgrade that added board_tools_client_calls, run it and re-run bridge:check.");

            return;
        }

        // ⛔ PLAIN TEXT, NO GLYPHS, AND NOT AS A STYLE PREFERENCE. `laravel/pao` binds its own
        // `OutputStyle` when it detects an AI AGENT running the command (never under
        // `runningUnitTests()`), and its `OutputCleaner` DELETES a fixed set of characters —
        // `⚠ ✔ ✖ → ● ▶` among them — collapses runs of spaces, and rewrites `...` to `..`.
        // A golden fixture is captured under the test path, so it would keep a glyph the
        // agent reading this line never receives: the emphasis would be asserted and absent
        // on exactly the surface that matters most here. Uppercase survives both readers.
        $remedy = 'ASK THE SEAT to make one board-tools call (board_my_cards) and re-run bridge:check — do NOT re-provision the seat on the strength of this line.';
        $blind = 'the bridge cannot tell a seat that was never wired from one that is simply idle — it may not read the seat\'s own keypair or .mcp.json (an account may only read its own files), so the seat has to tell it, and calling IS the telling.';

        if ($record === null) {
            yield Finding::unvalidated("board_tools: agent {$name}: client half UNREPORTED — this install has recorded no successful board-tools call from that seat. THIS IS NOT EVIDENCE THE SEAT IS UNWIRED: {$blind} {$remedy}");

            return;
        }

        // Carbon 3 returns a SIGNED float here, and both facts matter: the cast is what
        // keeps `humanAge()` integral, and the floor at zero is for a stamp in the future —
        // a clock stepping backwards on this host, which would otherwise render as a
        // negative age on a green line.
        $age = (int) max(0, $record->lastSuccessAt->diffInSeconds(now()));
        if ($age > $ttl) {
            yield Finding::unvalidated("board_tools: agent {$name}: client half UNREPORTED — the seat's last successful board-tools call was ".self::humanAge($age).' ago (over '.$record->transport.'), older than the '.self::humanAge($ttl)." freshness window, so this run says nothing about whether it still works. THIS IS NOT EVIDENCE THE SEAT IS UNWIRED: {$blind} {$remedy}");

            return;
        }

        // ⚑ THE PREDICATE IS THE PROVENANCE ALONE, not provenance-AND-transport. Only
        // `bridge:tools-call` can write `sshd` — the HTTP controller states `NotSshd` as a
        // constant — and that command refuses any agent whose transport is not `ssh`, so
        // `transport === 'ssh'` is already implied and re-asserting it here would be a guard
        // over a state no writer can produce. NULL falls through with `not_sshd`: an
        // unmeasured row is not a measured negative, and neither is proven. `$provenance`
        // was resolved inside the fail-soft envelope above, for the reason stated there.
        // ONE clause, appended to BOTH green lines, so the two arms cannot drift into
        // reporting the seat's version differently — they differ in what they claim about
        // the CALLER, and nothing about the version depends on that.
        [$stale, $versionClause] = $this->versionClause($record->clientVersion);

        if ($record->provenance === CallProvenance::Sshd) {
            $sshd = ("board_tools: agent {$name}: client half REPORTED THROUGH THE SSH DOOR — a successful board-tools call for this agent was recorded ".self::humanAge($age).' ago, over '.$record->transport.", and the process that served it carried sshd's session environment, had NO CONTROLLING TERMINAL, and carried no SSH_TTY — the shape of the pinned pty-less forced command. THAT RULES OUT what a bare record could not: the `bridge:check --probe-tools` HTTP probe and every other http call, since that door states its provenance as a constant and never measures; EVERY hand-run FROM A TERMINAL — an ssh login shell, a tmux pane, a screen window, this host's own console — because a terminal hand-run keeps its controlling terminal even when stdin is a pipe, and this process had none; a hand-run whose lineage held a pty and still carried SSH_TTY; and anything running with no ssh session environment at all. TWO THINGS IT DOES NOT RULE OUT, so it STILL DOES NOT NAME THE CALLER: ANY OTHER PTY-LESS ssh INVOCATION of this command, `ssh <host> '<command>'` included — `bridge:check --probe-tools-ssh` and `provision-board-tools.py --self-cert` drive exactly that and are INDISTINGUISHABLE from the seat here, so if either has been run since, this line may be that run; and a hand-run from a TERMINAL-LESS context carrying SSH_CONNECTION — a cron entry or a systemd user unit after `systemctl --user import-environment`, an agent tool harness, or a setsid wrapper.").' '.$versionClause;

            yield $stale ? Finding::warn($sshd) : Finding::ok($sshd);

            return;
        }

        $reported = "board_tools: agent {$name}: client half REPORTED — a successful board-tools call for this agent was recorded ".self::humanAge($age).' ago, over '.$record->transport.". THAT IS THE CALL, NOT THE CALLER: `bridge:check --probe-tools`, `provision-board-tools.py --self-cert` and a hand-run `bridge:tools-call --agent={$name}` on this host stamp the same row, so a recorded call means the door OPENED — not necessarily that the seat opened it. ".$versionClause;

        yield $stale ? Finding::warn($reported) : Finding::ok($reported);
    }

    /**
     * The version half of a green line: `[isStale, clause]` (card#8974 / DL-364).
     *
     * ⛔ `isStale` IS TRUE ON EXACTLY ONE INPUT — a reported version that COMPARED older
     * than the bundled one. Every other shape (no report, an unreadable bundled manifest, a
     * current or newer client) is a green line, because none of them measured a stale seat
     * and a `warn` an operator cannot act on is worse than silence.
     *
     * The comparator is {@see ChannelSnapshotProbe::compareVersions()} and NOT PHP's
     * `version_compare()`, for the reason that method's own docblock gives at length: the
     * DECLARED AUTHORITY on whether a deployed snapshot is behind is
     * `bin/provision-board-tools.py`, and `version_compare()` disagrees with it on
     * pre-release and build tags — so this leg would report STALE on a snapshot the
     * provisioner calls current, and re-deploying would never clear the line.
     *
     * @return array{0: bool, 1: string}
     */
    private function versionClause(?string $reported): array
    {
        if ($reported === null) {
            return [false, 'CLIENT VERSION NOT REPORTED (client < '.ClientVersion::FIRST_REPORTING_SNAPSHOT.') — the reference channel server sends its own snapshot version from '.ClientVersion::FIRST_REPORTING_SNAPSHOT.' onward, so this call came from an older copy, or from a caller that is not a channel server at all (`bridge:check --probe-tools`, `provision-board-tools.py --self-cert`, a hand-run `bridge:tools-call`). THAT IS NOT EVIDENCE THE SEAT IS STALE — nothing was compared. Re-deploy the seat\'s channel server to get the comparison.'];
        }

        $bundled = ChannelSnapshotProbe::readManifest($this->bundledDir.'/package.json');
        if ($bundled['status'] !== 'ok' || $bundled['version'] === '') {
            $why = $bundled['status'] !== 'ok'
                ? ChannelSnapshotProbe::manifestReason($bundled['status'])
                : 'declares no version';

            return [false, "The seat reports client version {$reported}, NOT COMPARED — this checkout's {$this->bundledDir}/package.json {$why}, so there was no bundled version to compare it against. That file is tracked in this checkout: restore or repair it, and check that this process can read it. The seat's own report above is unaffected."];
        }

        if (ChannelSnapshotProbe::compareVersions($reported, $bundled['version']) < 0) {
            return [true, "CLIENT VERSION {$reported} IS OLDER THAN THE {$bundled['version']} THIS BRIDGE BUNDLES — that seat runs a STALE channel server, so a tool it does not offer may be missing from ITS copy rather than from this bridge, and reading that as a bridge fault sends the remedy to the wrong side. Re-copy this checkout's {$this->bundledDir} over the seat's deployed directory, run npm ci in it, and RESTART that session: the version is read when the channel server starts, so a re-deploy on its own does not change what this line reports."];
        }

        return [false, "Client version {$reported} is at or ahead of the {$bundled['version']} this bridge bundles."];
    }

    /**
     * How old a stamp may be and still read as current, in seconds.
     *
     * A non-positive value would make every stamp stale the instant it was written — an
     * operator's typo turning a green fleet plain — so it falls back to the shipped
     * default rather than being obeyed. That is not a silent correction of a meaningful
     * setting: there is no legitimate zero-second freshness window.
     */
    private static function ttlSeconds(): int
    {
        $configured = (int) config('bridge.board_tools.client_half_ttl');

        return $configured > 0 ? $configured : 7 * 86400;
    }

    /**
     * A duration an operator reads at a glance — `45s`, `12m`, `3h`, `9d`.
     *
     * FLOORED, NEVER ROUNDED, so the printed number is a lower bound on the real age and
     * "3h" can never be read off something that happened four hours ago. The unit is the
     * largest whole one, because the question this answers is "recent, or not really", and
     * a seat that last called `9d` ago is not made clearer by 217 hours.
     */
    private static function humanAge(int $seconds): string
    {
        return match (true) {
            $seconds < 60 => $seconds.'s',
            $seconds < 3600 => intdiv($seconds, 60).'m',
            $seconds < 86400 => intdiv($seconds, 3600).'h',
            default => intdiv($seconds, 86400).'d',
        };
    }
}
