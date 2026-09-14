<?php

namespace App\Bridge\IdleNudge;

use App\Bridge\Support\BridgePaths;

/**
 * Decides, from one snapshot, which declared agents are owed a nudge (card#9422 / DL-380).
 *
 * PURE: no IO except the one inbox read it is handed as a closure, so every branch is a
 * fixture. The job owns reading, pushing and recording.
 *
 * ⭐ THE JOIN (Mezzanine FLEET-STATE.md § 8.3.3 rule 1, relayed on rt#479). A seat belongs to
 * this bridge only when its own `install_id` is the configured install (strict string
 * equality). Within the install, declarers of a name are counted over `checked`, `unchecked`,
 * `disagreed` — and any check value not in the documented set, counted rather than trusted —
 * BEFORE anything resolves: more than one declarer resolves to NOTHING
 * (`duplicate_declaration`); a lone declarer resolves only when `checked` or `unchecked`
 * (otherwise `no_declaring_seat`). `undeclared` never counts. The bridge never picks one.
 *
 * ⭐ PENDING WORK IS WORK THAT WAS PUSHED AT AN IDLE SEAT. An agent is measurable only when its
 * YAML routes every staged intent to its channel (`channel.route_intents: true`): that is the
 * operator's declared intent that this agent be woken for its intents, and a nudge is a second
 * attempt at a wake the operator already asked for. On any other agent most intents were
 * deliberately inbox-only, and nudging on them would turn that choice into a delayed wake.
 * Pending = an unseen line (via {@see BridgePaths::unseenInboxLines()})
 * staged AFTER the seat's current idle edge (a) and old enough that a wake it caused would
 * already be visible (b).
 *
 * ⚑ TWO CLOCKS, NEVER SUBTRACTED FROM EACH OTHER. `idleAge = server_time − idle_since` is on
 * Mezzanine's clock; `intentAge = dbNow − ts` is on the bridge DB's clock (`ts` is
 * `webhook_events.received_at`, DB-stamped). The predicates compare the two AGES, so clock
 * skew between the hosts cancels. What does not cancel is the error BUDGET, spelled out where
 * each predicate applies it.
 */
final class IdleNudgeEvaluator
{
    /**
     * How long after a push a woken seat must be visible as not idle before the bridge treats
     * the push as missed: push → reporter event → Mezzanine fold → snapshot. ⚠ UNVERIFIED: the
     * only measurement is Mezzanine's own ~14 s push-to-first-command probe (rt#478); live
     * measurement is owed.
     */
    public const WAKE_GRACE_S = 120;

    /**
     * The resolution of `webhook_events.received_at` on SQLite (`useCurrent()` stamps whole
     * seconds). Budgeted everywhere rather than per driver: a pessimistic second costs a nudge
     * at most one second late.
     */
    public const TS_QUANTUM_S = 1.0;

    public const PENDING_CAP = 10;

    /** Mezzanine's closed `render_state` set (rt#479 issuecomment-5657493757). */
    public const RENDER_STATES = ['retired', 'disabled', 'offline', 'stale', 'catching_up', 'working', 'idle', 'blocked', 'stalled', 'unknown'];

    private const RESOLVING_CHECKS = ['checked', 'unchecked'];

    /**
     * @param  array<string, bool>  $agents  declared agent name => its `channel.route_intents`
     * @param  array<string, int>  $nudged  agent => the `idle_since` (epoch ms) it was last nudged for
     * @param  callable(string): list<array<mixed>>  $unseenLines  throws {@see InboxUnreadable}
     * @param  callable(string, list<string>): array<string, float|false|null>  $pushTimes  agent + line ids => each
     *                                                                                      line's last push time (epoch s, DB clock); false =
     *                                                                                      never pushed (age from ts); null or absent =
     *                                                                                      unreadable; throws {@see PushTimeUnreadable}
     */
    public function evaluate(
        FleetSnapshot $snapshot,
        string $install,
        array $agents,
        array $nudged,
        callable $unseenLines,
        callable $pushTimes,
        float $dbNowS,
        int $defaultAfterS,
    ): Evaluation {
        $tally = ['foreign' => 0, 'unmapped' => 0, 'malformed_name' => 0, 'unknown_agent' => 0, 'declarer' => 0];
        /** @var array<string, list<array<mixed>>> $declarers */
        $declarers = [];

        foreach ($snapshot->seats as $seat) {
            if (! is_string($seat['install_id'] ?? null) || $seat['install_id'] !== $install) {
                $tally['foreign']++;

                continue;
            }

            $name = $seat['protocol_agent_name'] ?? null;
            if ($name === null || ($seat['protocol_agent_name_check'] ?? null) === 'undeclared') {
                $tally['unmapped']++;

                continue;
            }
            if (! is_string($name)) {
                $tally['malformed_name']++;

                continue;
            }
            if (! array_key_exists($name, $agents)) {
                $tally['unknown_agent']++;

                continue;
            }

            // A declarer with a malformed seat_id still COUNTS toward a duplicate: a broken
            // identity is not evidence the seat does not claim the name.
            $tally['declarer']++;
            $declarers[$name][] = $seat;
        }

        $verdicts = [];
        $names = array_keys($agents);
        sort($names);
        foreach ($names as $agent) {
            $verdicts[] = $this->agent(
                $agent, $agents[$agent], $declarers[$agent] ?? [], $snapshot, $install, $nudged, $unseenLines, $pushTimes, $dbNowS, $defaultAfterS,
            );
        }

        return new Evaluation($tally, $verdicts);
    }

    /**
     * @param  list<array<mixed>>  $declared
     * @param  array<string, int>  $nudged
     * @param  callable(string): list<array<mixed>>  $unseenLines
     * @param  callable(string, list<string>): array<string, float|false|null>  $pushTimes
     */
    private function agent(
        string $agent,
        bool $routeIntents,
        array $declared,
        FleetSnapshot $snapshot,
        string $install,
        array $nudged,
        callable $unseenLines,
        callable $pushTimes,
        float $dbNowS,
        int $defaultAfterS,
    ): AgentVerdict {
        $verdict = fn (string $code, ?NudgePlan $plan = null): AgentVerdict => new AgentVerdict($agent, $code, $plan);

        if ($routeIntents !== true) {
            return $verdict('not_push_routed');
        }
        if (count($declared) > 1) {
            return $verdict('duplicate_declaration');
        }
        if ($declared === [] || ! in_array($declared[0]['protocol_agent_name_check'] ?? null, self::RESOLVING_CHECKS, true)) {
            return $verdict('no_declaring_seat');
        }

        $seat = $declared[0];
        $seatId = $seat['seat_id'] ?? null;
        if (! is_string($seatId) || $seatId === '') {
            return $verdict('malformed_seat_identity');
        }

        $render = $seat['render_state'] ?? null;
        if (! is_string($render) || ! in_array($render, self::RENDER_STATES, true)) {
            return $verdict('unrecognised_state');
        }
        if ($render === 'retired' || ($seat['retired'] ?? null) !== null) {
            // Mezzanine never serves a retired seat; one on the wire is its defect, reported
            // as such rather than read as a state.
            return $verdict('served_retired');
        }
        if ($render !== 'idle') {
            return $verdict('not_idle');
        }

        $derivation = $seat['derivation'] ?? null;
        $foldLagMs = is_array($derivation) ? ($derivation['fold_lag_ms'] ?? null) : null;
        if (! is_int($foldLagMs) || $foldLagMs < 0) {
            return $verdict('fold_lag_unreadable');
        }
        // Kept although `fleet.fold` must already read `ok`: that envelope threshold is
        // Mezzanine's to move, and this row is the one that protects the IDLE VERDICT itself —
        // a seat whose last applied event is older than the wake grace may be idle on paper
        // and working in fact. The additive form in (b) below protects something else.
        if ($foldLagMs >= self::WAKE_GRACE_S * 1000) {
            return $verdict('fold_lag');
        }

        $idleSinceMs = FleetSnapshot::instantMs($seat['idle_since'] ?? null);
        if ($idleSinceMs === null || $idleSinceMs > $snapshot->serverTimeMs) {
            return $verdict('no_idle_since');
        }

        $suspect = ! array_key_exists('idle_nudge_after_s', $seat);
        if ($suspect) {
            $horizonS = $defaultAfterS;
        } else {
            $declaredHorizon = $seat['idle_nudge_after_s'];
            if (! is_int($declaredHorizon) || $declaredHorizon < 1) {
                return $verdict('malformed_horizon');
            }
            $horizonS = $declaredHorizon;
        }

        $idleAgeS = ($snapshot->serverTimeMs - $idleSinceMs) / 1000;
        if ($idleAgeS < $horizonS) {
            return $verdict('idle_within_horizon');
        }

        if (($nudged[$agent] ?? null) === $idleSinceMs) {
            return $verdict('already_nudged');
        }

        try {
            $lines = $unseenLines($agent);
        } catch (InboxUnreadable) {
            return $verdict('inbox_unreadable');
        }

        $foldLagS = $foldLagMs / 1000;
        // (b) old enough that a wake it caused would be visible. Every overstatement of an age
        // here runs the unsafe way (it would call a fresh push old), so the transit and the ts
        // quantum are subtracted, and the seat's own fold lag is added: a wake cannot show
        // before Mezzanine has folded the events that carry it.
        $oldEnough = fn (float $ageS): bool => $ageS - $snapshot->transitS - self::TS_QUANTUM_S >= self::WAKE_GRACE_S + $foldLagS;

        $candidates = [];
        foreach ($lines as $line) {
            $ts = $line['ts'] ?? null;
            if (! is_int($ts) && ! is_float($ts)) {
                continue;
            }
            $intentAgeS = $dbNowS - (float) $ts;

            // (a) staged after the idle edge, judged on `ts`. Budget: `dbNow` is read after the
            // response, so intentAge is overstated by up to the transit plus the DB read, and a
            // SQLite `ts` truncated to the second overstates it by up to a second more. Both push
            // an intent staged just after the edge to "before" — a missed nudge. A clock STEP on
            // either host between the readings can go either way; the (agent, idle_since) dedupe
            // bounds that to one wrong nudge or one miss per idle period.
            if (! ($intentAgeS < $idleAgeS) || ! $oldEnough($intentAgeS)) {
                continue;
            }
            $candidates[] = $line;
        }

        if ($candidates === []) {
            return $verdict('nothing_pending');
        }

        // ⛔ `ts` IS WHEN THE EVENT WAS FIRST RECEIVED, NOT WHEN THE LINE WAS LAST PUSHED. A
        // redelivery or `bridge:replay` re-stages the line with the original `ts` and pushes it
        // again NOW, so (b) is re-judged from the later of `ts` and the dispatch's DB-stamped
        // push time. A line whose dispatch never COMPLETED was never pushed and keeps `ts`. A line
        // whose push time cannot be read makes the whole agent unmeasured: treating it as old
        // would nudge inside a fresh wake, and dropping it would hide work.
        try {
            $pushedAt = $pushTimes($agent, array_map(fn (array $line): string => (string) ($line['id'] ?? ''), $candidates));
        } catch (PushTimeUnreadable) {
            return $verdict('push_time_unreadable');
        }

        $pending = [];
        foreach ($candidates as $line) {
            $id = (string) ($line['id'] ?? '');
            $ts = (float) $line['ts'];
            $pushed = $pushedAt[$id] ?? null;
            if ($pushed === null) {
                return $verdict('push_time_unreadable');
            }
            // `false`: the dispatch never completed, so no push reached this line. Decision 1
            // nudges on it, aged from its receipt.
            if (! $oldEnough($dbNowS - ($pushed === false ? $ts : max($ts, $pushed)))) {
                continue;
            }

            $pending[] = [
                'id' => (string) ($line['id'] ?? ''),
                'kind' => $line['kind'] ?? null,
                'subject_id' => $line['subject_id'] ?? null,
                'summary' => is_string($line['summary'] ?? null) ? mb_strimwidth($line['summary'], 0, 200, '…') : null,
                'ts' => $ts,
            ];
        }

        if ($pending === []) {
            return $verdict('nothing_pending');
        }

        usort($pending, fn (array $a, array $b): int => $a['ts'] <=> $b['ts']);

        return $verdict('nudge', new NudgePlan(
            agent: $agent,
            installId: $install,
            seatId: $seatId,
            idleSinceMs: $idleSinceMs,
            serverTimeMs: $snapshot->serverTimeMs,
            idleAgeS: (int) floor($idleAgeS),
            horizonS: $horizonS,
            suspect: $suspect,
            pendingTotal: count($pending),
            pending: array_slice($pending, 0, self::PENDING_CAP),
        ));
    }
}
