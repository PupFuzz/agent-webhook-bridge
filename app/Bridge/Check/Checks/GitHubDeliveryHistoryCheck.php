<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\Check\DeliveryHistory\DeliveryHistoryState;
use App\Bridge\Check\DeliveryHistory\ScopeDeliveryHistory;
use App\Bridge\Check\NextSteps;
use App\Bridge\Check\Silence;
use App\Bridge\Retention\RetentionConfig;
use App\Bridge\Support\DbClock;
use App\Bridge\Support\Finding;
use App\Bridge\Support\HumanAge;
use App\Bridge\Support\RedactedErrorText;
use App\Bridge\Support\UntrustedText;
use App\Models\WebhookEvent;
use DateTimeImmutable;
use DateTimeZone;
use Generator;
use Throwable;
use UnexpectedValueException;

/**
 * Has each DECLARED github subscription gone quiet, judged against that scope's OWN delivery record? (DL-382)
 *
 * ⭐ THE GAP IT CLOSES. `GitHubWebhookSubscriptionCheck` answers the same question by reading the repo's hook list,
 * and enumerating a repo's hooks needs `admin:repo_hook` on it. A seat subscribed to a repo it does not administer —
 * the ordinary coordination shape, and exactly the shape of the 2026-08 incident in which a subscription went deaf on
 * more than one seat for weeks — gets `COULD NOT LOOK` from that leg on every run. This leg needs no token, no repo
 * admin and no network: the bridge already knows what it declared and what its receiver recorded.
 *
 * ⛔ IT WITNESSES THE DELIVERY SIDE ONLY, AND EVERY VERDICT SAYS SO ({@see self::DELIVERY_SIDE_ONLY}). It reads what the
 * receiver RECORDED. A delivery that is recorded and then dropped before any agent wakes — the pre-classify echo drop
 * an inline shared-account `identity.github_user_id` causes is the measured case — is a perfectly healthy delivery to
 * this leg, so an agent deaf for that reason reads healthy here. That is a bound on the instrument, not a gap to close
 * inside it; `AgentCoordinationIdentityCheck` is the leg for that combination.
 *
 * ⛔ `warn`, NEVER `fail`, AND THE REASON IS THE INSTRUMENT. A silent record is an inference from ABSENCE: a genuinely
 * quiet repo produces the same observable as a deaf subscription, so this leg cannot establish a broken install and must
 * not move the exit code on one. Loud is the yellow line and the NEXT STEPS entry. A record this run could not read,
 * and a record too short to derive a threshold from while the silence is still inside the floor, are `unvalidated`
 * (Severity limb (a) and limb (c)): neither is a healthy verdict, and neither is a silence this leg measured as
 * abnormal.
 *
 * THE THRESHOLD AND ITS DERIVATION are {@see ScopeDeliveryHistory}'s to own, and every line that uses one prints the
 * derivation's inputs, so an operator can check the arithmetic without reading the code.
 *
 * ⚑ A LINE PRINTS INSTANTS AND DURATIONS OF THE RECORD, NEVER AN AGE MEASURED AGAINST NOW. The silence is stated as
 * *since <the last recorded delivery>*, so a line over a fixed record is the same line on every run that reaches the
 * same verdict — the golden captures pin records at fixed instants, and an age would move them daily.
 *
 * ⚠ THE RECORD IS `webhook_events` FOR THE SCOPE SPELLED EXACTLY AS DECLARED, and three things follow that the lines
 * state rather than leave to be discovered: retention prunes it (so a silence longer than the retention window reads as
 * no delivery at all); a GitHub ping is never recorded; and a delivery the receiver REFUSED — a bad signature, a scope
 * mismatch — is never recorded either, so it reads as silence here. The comparison is byte-exact rather than left to
 * the column's collation, because the dispatcher matches a subscription's spelling exactly and a case-insensitive
 * MariaDB collation would otherwise count a row that wakes nothing; {@see WebhookEvent::scopeForExactScope()} owns it.
 */
final class GitHubDeliveryHistoryCheck implements Check
{
    public const ID = 'github.delivery_history';

    /**
     * The bound every verdict carries, and the phrase the tests pin it by.
     *
     * A CONSTANT RATHER THAN A PHRASE REPEATED PER ARM, because the arm that dropped it would ship as a false
     * all-clear for exactly the class it cannot see.
     */
    public const DELIVERY_SIDE_ONLY = 'THIS LEG WITNESSES THE DELIVERY SIDE ONLY';

    public function id(): string
    {
        return self::ID;
    }

    /**
     * @return iterable<Finding|Silence>
     */
    public function run(CheckContext $ctx): iterable
    {
        $scopes = $ctx->githubSubscriptionsByScope();
        if ($scopes === []) {
            yield Silence::because('no agent this run could read declares a github subscription, so there is no delivery record to judge');

            return;
        }

        $record = $this->recordClause();
        $unread = array_map(static fn (int|string $scope): string => (string) $scope, array_keys($scopes));

        // FAIL-SOFT OVER THE WHOLE WALK, with the scopes already judged kept: a read that throws for one scope is a
        // store this run cannot read, and the scopes after it were not measured either — so they are NAMED as
        // unmeasured rather than walked past.
        try {
            $now = DbClock::now()->getTimestamp();
            foreach ($scopes as $rawScope => $agents) {
                $scope = (string) $rawScope;
                $history = $this->historyOf($scope);
                array_shift($unread);

                yield $this->verdict($ctx, $scope, $agents, $history, $now, $record);
            }
        } catch (Throwable $e) {
            $names = implode(', ', array_map(UntrustedText::forOperator(...), $unread));

            yield Finding::unvalidated('github delivery history: COULD NOT READ this install\'s delivery record ('.UntrustedText::forOperator(RedactedErrorText::of($e)).'), so the delivery history of '.count($unread)." declared github scope(s) was NOT checked ({$names}). This run says nothing about whether they are delivering.");
        }
    }

    /**
     * The one finding for one scope, and — on a loud state — its NEXT STEPS publication.
     *
     * ⛔ THE PUBLICATION AND THE FINDING ARE ONE ACT, the rule `GitHubWebhookSubscriptionCheck::reportMissing()` follows:
     * the block's population is the loud arms' population by construction, not by two call sites agreeing.
     *
     * @param  list<string>  $agents
     */
    private function verdict(CheckContext $ctx, string $scope, array $agents, ScopeDeliveryHistory $history, int $now, string $record): Finding
    {
        $state = $history->stateAt($now);
        if ($state->isLoud()) {
            $ctx->githubDeliverySilent[] = ['scope' => $scope, 'agents' => $agents];
        }

        $shown = UntrustedText::forOperator($scope);
        $head = "github delivery history: {$shown} (subscribed by ".implode(', ', $agents).')';
        $bound = self::DELIVERY_SIDE_ONLY.': it reads what this install\'s receiver RECORDED, so a delivery that is recorded and then dropped before any agent wakes — an echo drop at the dispatch gate, a classifier that stages nothing — counts here as a delivery, and an agent deaf for that reason reads healthy to this leg.';
        $last = $history->lastAt === null ? '' : gmdate('Y-m-d H:i:s', $history->lastAt).' UTC';
        $floor = self::exact(ScopeDeliveryHistory::FLOOR_SECONDS);
        $remedy = "If {$shown} is an active repo, look at its webhook: someone with admin:repo_hook opens the repo's Settings then Webhooks, confirms a hook delivers to <BRIDGE_RECEIVER_BASE_URL>/github?b={$shown}, and reads its Recent Deliveries — a non-2xx there is a refusal this record cannot see. Then re-run bridge:check. See ".NextSteps::DELIVERY_DOC.'.';

        return match ($state) {
            DeliveryHistoryState::NeverDelivered => Finding::warn(
                "{$head} — this install has recorded NO delivery for the scope spelled exactly {$shown} anywhere in its retained record. Either no webhook delivers here for that repo (deleted, never added, or every delivery refused at the receiver), or nothing has happened on the repo since the record began, and this leg cannot tell those apart. {$remedy} {$record} {$bound}"
            ),

            DeliveryHistoryState::PastThreshold => Finding::warn(
                "{$head} — SILENT since the last recorded delivery at {$last}, past the ".self::exact((int) $history->derivedThreshold())." of silence this scope's own record calls routine. {$this->derivation($history)} A genuinely quiet repo reads exactly like a deaf one here. {$remedy} {$record} {$bound}"
            ),

            DeliveryHistoryState::UnderivedPastFloor => Finding::warn(
                "{$head} — SILENT since the last recorded delivery at {$last}, past the {$floor} floor. CANNOT DERIVE a threshold of this scope's own: {$this->underivableReason($history)} — so only the floor was applied. {$remedy} {$record} {$bound}"
            ),

            DeliveryHistoryState::WithinThreshold => Finding::ok(
                "{$head} — the last recorded delivery was at {$last}, inside the ".self::exact((int) $history->derivedThreshold())." of silence this scope's own record calls routine. {$this->derivation($history)} {$record} {$bound}"
            ),

            DeliveryHistoryState::UnderivedWithinFloor => Finding::unvalidated(
                "{$head} — CANNOT DERIVE a silence threshold from this scope's record: {$this->underivableReason($history)}. The last recorded delivery was at {$last}, inside the {$floor} floor — and that is NOT a healthy verdict: this run cannot say what an ordinary silence is for this scope, so it cannot say this one is ordinary. {$record} {$bound}"
            ),
        };
    }

    /**
     * Every delivery instant recorded for exactly this spelling, oldest first.
     *
     * ⛔ NOT ACTUALLY STREAMED: `->cursor()` hands back a PHP generator, but pdo_mysql buffers the full result set
     * on the client before this leg sees a single row — the generator paces CONSTRUCTING objects from an
     * already-arrived buffer, not the network read. It is used for the constant memory that buys, not for early bytes.
     *
     * ⚑ A `received_at` THAT IS NOT A STRING THROWS rather than being skipped: the column is NOT NULL and DB-defaulted,
     * so a non-string is a driver this leg does not understand, and skipping it would shorten the record silently.
     */
    private function historyOf(string $scope): ScopeDeliveryHistory
    {
        $rows = WebhookEvent::query()
            ->forExactScope('github', $scope)
            ->select(['received_at'])
            ->orderBy('received_at')
            ->toBase()
            ->cursor();

        $instants = (static function () use ($rows): Generator {
            $utc = new DateTimeZone('UTC');
            foreach ($rows as $row) {
                $receivedAt = $row->received_at ?? null;
                if (! is_string($receivedAt)) {
                    throw new UnexpectedValueException('webhook_events.received_at came back as '.get_debug_type($receivedAt).', not a timestamp string');
                }

                yield (new DateTimeImmutable($receivedAt, $utc))->getTimestamp();
            }
        })();

        return ScopeDeliveryHistory::fromAscendingTimestamps($instants);
    }

    private function derivation(ScopeDeliveryHistory $history): string
    {
        return 'Derivation: threshold = max(floor '.self::exact(ScopeDeliveryHistory::FLOOR_SECONDS).', '.ScopeDeliveryHistory::GAP_FACTOR.' x the SECOND-longest gap between consecutive recorded deliveries, '.self::exact((int) $history->secondLongestGap).') = '.self::exact((int) $history->derivedThreshold()).", over {$history->deliveries} recorded deliveries spanning ".HumanAge::floored($history->span()).'; the single longest gap ('.self::exact((int) $history->longestGap).') is left out, so one past outage cannot teach this leg that deafness is routine.';
    }

    private function underivableReason(ScopeDeliveryHistory $history): string
    {
        return "the record holds {$history->deliveries} recorded delivery(ies) spanning ".HumanAge::floored($history->span()).', and a threshold needs at least '.ScopeDeliveryHistory::MIN_GAPS.' gaps between deliveries across a record spanning at least '.self::exact(ScopeDeliveryHistory::MIN_SPAN_SECONDS).' — two weekly cycles, so the scope\'s routine gap can appear twice: once to be excluded as the longest, once still standing to derive from';
    }

    /**
     * What record was read and what it cannot hold, per this install's CURRENT retention config — a prune run under
     * an earlier config, or by hand with `bridge:prune`, is not visible from here.
     */
    private function recordClause(): string
    {
        $retention = RetentionConfig::fromConfig();
        $pruned = $retention->enabled && $retention->isUsable() && $retention->olderThanDays !== null
            ? ", from which rows older than {$retention->olderThanDays}d are pruned (bridge.retention.older_than)"
            : ", which this install's current retention config does not prune";

        return "The record read is this install's webhook_events store{$pruned}; a GitHub ping is never recorded, and neither is a delivery the receiver refused (a bad signature, a scope mismatch).";
    }

    /** A comparand printed beside an age: the floored unit for the eye, the seconds for the comparison. */
    private static function exact(int $seconds): string
    {
        return HumanAge::floored($seconds)." ({$seconds}s)";
    }
}
