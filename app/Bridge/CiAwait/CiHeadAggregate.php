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
 * `ci_await` applies to the list it reads.
 *
 * ⭐ ONCE PER STATE PER AGENT, BY LEDGER. {@see CiHeadSettlementLedger::claim()} runs in the
 * transaction that stages the event: the last two runs of a head completing in two concurrent
 * deliveries both see "settled", and only one claim succeeds. A run that starts later (an
 * `on: workflow_run` follow-on, a re-run) changes the fingerprint once it completes, so the head
 * settles again and the agent gets a second event — never silence, never the same state twice.
 *
 * ⭐ A `ci_await` IS LEFT IN PLACE. The aggregate decides from the runs the bridge has SEEN, so it can
 * settle before GitHub's full run list is terminal; deleting the agent's await would end the wait on
 * a partial view. The await keeps its own settle on GitHub's list, and both senders record the
 * settled state in the ledger, so the same state is never sent twice in either order — an await
 * that settles to the state already sent is forgotten with no event, one that settles to a new
 * state sends it.
 *
 * Staged to the inbox always (as `impl_ci` was) and pushed live where `impl_ci` would have been —
 * `channel.route_intents: true` — or where the agent has a `ci_await` on the head, which promises a push.
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
        $mine = static fn () => CiAwait::query()->where('agent', $agent->agentName)->where('repo', $key)->where('head_sha', $headSha);
        $pr = CiHeadRunTracker::prOf($repoName, $headSha) ?? $mine()->value('pr');
        $pr = $pr === null ? null : (int) $pr;
        $intent = new Intent(
            kind: CiAwaitService::SETTLED,
            subjectId: "ci:{$repoName}@{$headSha}",
            provider: 'bridge',
            actor: new Actor(id: null),
            summary: $head->settledSummary($repoName, $headSha, $pr),
            payload: $head->settledPayload($repoName, $headSha, $pr, Carbon::now()),
        );

        $awaited = $mine()->exists();
        $emitted = DB::transaction(function () use ($agent, $key, $headSha, $fingerprint, $intent): bool {
            if (! CiHeadSettlementLedger::claim($agent->agentName, $key, $headSha, $fingerprint)) {
                return false;
            }
            $this->intents->stageAuthored($agent->agentName, "ci_head:{$agent->agentName}:{$key}@{$headSha}:{$fingerprint}", microtime(true), $intent);

            return true;
        });
        if (! $emitted) {
            return false;
        }

        Log::info('bridge ci_head: ci_settled emitted', ['agent' => $agent->agentName, 'repo' => $repoName, 'head_sha' => $headSha, 'awaited' => $awaited]);
        if ($agent->channel->routeIntents || $awaited) {
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
