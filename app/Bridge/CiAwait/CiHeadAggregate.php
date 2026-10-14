<?php

namespace App\Bridge\CiAwait;

use App\Bridge\Dispatch\Actor;
use App\Bridge\Dispatch\Intent;
use App\Bridge\Dispatch\IntentLog;
use App\Bridge\Handlers\CiHeadAggregateHandler;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\AuthoredIntentPush;
use App\Bridge\Support\HandlerRegistry;
use App\Bridge\Support\RedactedErrorText;
use App\Models\CiAwait;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The per-head aggregate `ci_settled` (card#11667): ONE event per settled state of a head to each
 * agent that would otherwise have been sent a per-run `impl_ci` for every finished workflow run.
 *
 * ⭐ WHO GETS IT IS DECIDED BY DISPATCH, NOT HERE. The `impl-ci-wake` family attaches a
 * {@see CiHeadAggregateHandler} target to a completed `workflow_run` exactly for the agents it
 * stages `impl_ci` for, and the dispatcher runs it after its own echo / signal gates — so the
 * audience is the per-run audience by construction.
 *
 * ⭐ DECIDED FROM TRACKED STATE — NO GITHUB READ. The head's runs are what `workflow_run`
 * deliveries reported ({@see CiHeadRunTracker}), judged by {@see HeadRuns} — the same predicate
 * `ci_await` applies to the list it reads. ⛔ So it can settle on a PARTIAL view: a workflow GitHub
 * has queued that no delivery has reported yet is not in the list (card#11938). The event says so —
 * `basis: delivered_runs`, `all_terminal: null`, and a summary that says the runs delivered so far
 * are complete, not that the head is.
 *
 * ⭐ ONCE PER STATE PER AGENT, BY LEDGER. {@see CiHeadSettlementLedger::claim()} runs in the
 * transaction that stages the event: the last two runs of a head completing in two concurrent
 * deliveries both see "settled", and only one claim succeeds. A run that starts later (an
 * `on: workflow_run` follow-on, a re-run) changes the fingerprint once it completes, so the head
 * settles again and the agent gets a second event — never silence, never the same state twice.
 *
 * ⛔ AN AGENT WITH A `ci_await` ON THE HEAD GETS NOTHING FROM HERE (card#11938). Its await promises
 * a `ci_settled` when every run GITHUB lists is terminal, and the partial view above broke that
 * promise: a seat was woken green over one workflow of two. So the aggregate neither stages, pushes
 * nor records a state for an awaited agent, and the await's own settle on its GitHub read is the one
 * it gets — never silenced by the ledger, which the await writes but does not consult. ⚠ The check
 * runs inside the transaction but is not ordered against a concurrent registration: an await stored
 * while a delivery is settling the head can still be sent this event (DL-482 bound 1). Once the
 * await is answered and gone, the agent is like any other: a state the await already sent is not
 * sent again, and a new one (a re-run, a late run) is, with `basis: delivered_runs`.
 *
 * Staged to the inbox always (as `impl_ci` was) and pushed live where `impl_ci` would have been —
 * `channel.route_intents: true`.
 */
final class CiHeadAggregate
{
    public function __construct(
        private readonly HandlerRegistry $handlers,
        private readonly IntentLog $intents,
    ) {}

    /** @return bool whether a `ci_settled` was emitted to `$agent` */
    public function settleFor(AgentConfig $agent, string $repoName, string $headSha): bool
    {
        $head = HeadRuns::of(CiHeadRunTracker::runsOf($repoName, $headSha));
        if (! $head->settled()) {
            return false;
        }
        $key = CiAwaitService::key($repoName);
        $fingerprint = $head->fingerprint();
        $pr = CiHeadRunTracker::prOf($repoName, $headSha);
        $intent = new Intent(
            kind: CiAwaitService::SETTLED,
            subjectId: "ci:{$repoName}@{$headSha}",
            provider: 'bridge',
            actor: new Actor(id: null),
            summary: $head->settledSummary($repoName, $headSha, $pr, HeadRuns::BASIS_DELIVERED_RUNS),
            payload: $head->settledPayload($repoName, $headSha, $pr, Carbon::now(), HeadRuns::BASIS_DELIVERED_RUNS),
        );

        $awaited = false;
        $emitted = DB::transaction(function () use ($agent, $key, $headSha, $fingerprint, $intent, &$awaited): bool {
            $awaited = CiAwait::query()->where('agent', $agent->agentName)->where('repo', $key)->where('head_sha', $headSha)->exists();
            if ($awaited || ! CiHeadSettlementLedger::claim($agent->agentName, $key, $headSha, $fingerprint)) {
                return false;
            }
            $this->intents->stageAuthored($agent->agentName, "ci_head:{$agent->agentName}:{$key}@{$headSha}:{$fingerprint}", microtime(true), $intent);

            return true;
        });
        if ($awaited) {
            Log::info('bridge ci_head: ci_settled not sent — the agent has a ci_await on the head, which settles on its own GitHub read', ['agent' => $agent->agentName, 'repo' => $repoName, 'head_sha' => $headSha]);
        }
        if (! $emitted) {
            return false;
        }

        Log::info('bridge ci_head: ci_settled emitted', ['agent' => $agent->agentName, 'repo' => $repoName, 'head_sha' => $headSha]);
        if ($agent->channel->routeIntents) {
            try {
                (new AuthoredIntentPush($this->handlers))->sendTo($intent, $agent);
            } catch (Throwable $e) {
                Log::warning('bridge ci_head: the live push of ci_settled failed — the intent is staged in the agent\'s inbox, which bridge:inbox surfaces', [
                    'agent' => $agent->agentName, 'repo' => $repoName, 'head_sha' => $headSha,
                ] + RedactedErrorText::logContext($e));
            }
        }

        return true;
    }
}
