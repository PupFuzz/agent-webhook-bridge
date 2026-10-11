<?php

namespace App\Bridge\Tools;

use App\Bridge\CiAwait\CiAwaitConfig;
use App\Bridge\CiAwait\CiAwaitService;
use App\Bridge\CiAwait\OverdueDeadline;
use App\Bridge\Exceptions\ConfigException;
use App\Bridge\Exceptions\ToolRefusalException;
use App\Bridge\Support\BoardToolsConfig;
use App\Bridge\Support\RedactedErrorText;
use App\Bridge\Writeback\KanbanClient;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ci_await (card#11200 / DL-452) — the calling seat declares that it is waiting for CI on one head
 * SHA, and the bridge sends it ONE `ci_settled` intent when every workflow run on that head is
 * terminal, instead of the seat polling GitHub. {@see CiAwaitService} owns detection, the sweep,
 * the claim that makes it one event per await, and the read-failure rules; this class owns the door.
 *
 * ⛔ SELF-SCOPED. The await belongs to the agent the front door resolved; no argument names a
 * seat ({@see CiAwaitArgs::identityReason()} only picks the refusal's message). It reads no board
 * and writes none, so it is served to every enabled `board_tools` agent, scoped or scope-less (card#11283;
 * {@see ServedTools}), coordination repo or not.
 *
 * ⛔ A REPO THIS INSTALL RECEIVES NO GITHUB EVENTS FOR IS REFUSED (`repo_not_received`): no agent
 * here subscribes to it, so no `workflow_run.completed` would ever arrive for it.
 *
 * ⛔ A REPO THIS INSTALL'S TOKEN CANNOT READ IS REFUSED (`repo_unreadable`, card#11600) when the
 * registration's own read says so (a 404, no token for any reader, or a confirmed 401/403): the await was stored
 * for that read and is removed again — the seat's EARLIER await on the head too, when this call was
 * a refresh, and the answer says which. A FIRST 401 or non-rate-limited 403 is not refused: it waits
 * for a confirming read — and a registration whose own read IS that confirming read (at least a
 * minute later, same status) is refused like a 404. Marked an install fault — no argument the seat sends can fix it.
 *
 * ⭐ OVERDUE (card#11674). Every stored await carries `overdue_at`: the repo's normal CI time from
 * now ({@see OverdueDeadline}), or `overdue_after_seconds` when the seat sends it. Past it, still
 * unsettled, the seat gets ONE `ci_await_overdue` and the await stays; that event — not a poll — is
 * the seat's cue to check CI by hand.
 */
final class CiAwaitTool implements SelfScopedTool
{
    public function name(): string
    {
        return 'ci_await';
    }

    public function acceptedArguments(): array
    {
        return ['repo', 'head_sha', 'pr', 'overdue_after_seconds'];
    }

    public function refusedArgumentReason(string $key): ?string
    {
        return CiAwaitArgs::identityReason($key);
    }

    /** The `pr` column is an unsigned 32-bit integer; a larger number cannot be stored. */
    public const PR_MAX = 4294967295;

    /** The board scope and the kanban client are never read: {@see SelfScopedTool}. */
    public function call(array $args, BoardToolsConfig $cfg, KanbanClient $client, string $agentName): array
    {
        return $this->callAsSeat($args, $agentName);
    }

    public function callAsSeat(array $args, string $agentName): array
    {
        $repo = CiAwaitArgs::repo($args, $this->name());
        $headSha = CiAwaitArgs::headSha($args, $this->name());
        $pr = $args['pr'] ?? null;
        if ($pr !== null && (! is_int($pr) || $pr < 1 || $pr > self::PR_MAX)) {
            throw new ToolRefusalException('ci_await: `pr`, when sent, must be a positive integer no larger than '.self::PR_MAX.' (the pull request number) or null. Nothing was stored.', reason: 'bad_arguments');
        }
        $overdueAfter = $args['overdue_after_seconds'] ?? null;
        if ($overdueAfter !== null && (! is_int($overdueAfter) || $overdueAfter < CiAwaitConfig::TTL_MIN || $overdueAfter > CiAwaitConfig::TTL_MAX)) {
            throw new ToolRefusalException('ci_await: `overdue_after_seconds`, when sent, must be a whole number of seconds from '.CiAwaitConfig::TTL_MIN.' to '.CiAwaitConfig::TTL_MAX.' (how long after this call the wait is overdue) or null. Nothing was stored.', reason: 'bad_arguments');
        }

        try {
            $ttl = CiAwaitConfig::ttlSeconds();
            $maxPerSeat = CiAwaitConfig::maxPerSeat();
            $cooldown = CiAwaitConfig::readCooldownSeconds();
            $seatReads = CiAwaitConfig::seatReadsPerHour();
            $overdueDefault = CiAwaitConfig::overdueDefaultSeconds();
        } catch (ConfigException $e) {
            throw new ToolRefusalException('ci_await: this bridge cannot store an await — '.$e->getMessage().'. Nothing was stored. This is an INSTALL fault; tell your operator.', installFault: true, reason: 'install_fault.ci_await_config_invalid');
        }

        try {
            $configured = CiAwaitService::receivedRepo($repo);
        } catch (ConfigException $e) {
            Log::warning('ci_await: the agent configs could not be read to find the repo', ['agent' => $agentName, 'error' => $e->getMessage()]);

            throw new ToolRefusalException('ci_await: this bridge could not read its agent configs to tell whether it receives GitHub events for `'.$repo.'`. Nothing was stored. This is an INSTALL fault; tell your operator (bridge:check names the file).', installFault: true, reason: 'install_fault.agent_config_unreadable');
        }
        if ($configured === null) {
            throw new ToolRefusalException("ci_await: this bridge receives no GitHub events for `{$repo}` — no agent on this install subscribes to it — so nothing would ever tell it the CI there finished. Nothing was stored. Tell your operator: an agent on this install must subscribe to that repo, and the repo's webhook must send Workflow runs here.", reason: 'repo_not_received');
        }

        $service = app(CiAwaitService::class);
        // ⛔ ONLY THE STORE ITSELF may answer "nothing was stored": everything after it runs on an
        // await that exists, so its failures are `unmeasured`, never this refusal.
        try {
            $load = $service->seatLoad($agentName, $configured, $headSha);
            if (! $load['this_head'] && $load['others'] >= $maxPerSeat) {
                throw new ToolRefusalException("ci_await: you are already awaiting {$load['others']} head(s), and this bridge allows {$maxPerSeat} per seat (BRIDGE_CI_AWAIT_MAX_PER_SEAT). Nothing was stored. Cancel a wait you no longer need with ci_await_cancel, or let one settle or expire; re-registering a head you already await is always allowed.", reason: 'too_many_awaits');
            }
            $overdue = $overdueAfter !== null ? OverdueDeadline::override($overdueAfter) : OverdueDeadline::forRepo($configured, $overdueDefault);
            $refreshed = $service->store($agentName, $configured, $headSha, $pr, $ttl, $overdue);
        } catch (QueryException $e) {
            Log::warning('ci_await: the await store could not be written', ['agent' => $agentName] + RedactedErrorText::logContext($e));

            throw new ToolRefusalException('ci_await: this bridge could not store the await (its `ci_awaits` table is missing or the database did not answer). Nothing was stored. This is an INSTALL fault — `php artisan migrate` creates the table; tell your operator.', installFault: true, reason: 'install_fault.ci_await_store_unavailable');
        }

        $result = $service->evaluateRegistration($agentName, $configured, $headSha, $pr, $cooldown, $seatReads);
        if ($result['unreadable'] !== null) {
            Log::warning('ci_await: refused — GitHub will not let this install read the repo', ['agent' => $agentName, 'repo' => $configured, 'head_sha' => $headSha, 'error' => $result['read_error']]);

            throw new ToolRefusalException("ci_await: this bridge cannot read `{$configured}`'s workflow runs on GitHub — {$result['read_error']} — so nothing would ever tell you the CI there finished. ".($refreshed ? 'Your existing await on this head was removed.' : 'Nothing was stored.').' Tell your operator, who can '.CiAwaitService::unreadableRemedy($configured).'.', installFault: true, reason: 'repo_unreadable');
        }
        try {
            $deliveryKnown = CiAwaitService::hasRecordedWorkflowRun($configured);
        } catch (Throwable $e) {
            Log::warning('ci_await: whether the repo has delivered workflow runs could not be read', ['agent' => $agentName] + RedactedErrorText::logContext($e));
            $deliveryKnown = null;
        }

        Log::info('ci_await: registered', ['agent' => $agentName, 'repo' => $configured, 'head_sha' => $headSha, 'state' => $result['state']]);

        $response = [
            'repo' => $configured,
            'head_sha' => $headSha,
            'pr' => $result['pr'],
            'state' => $result['state'],
            'refreshed' => $refreshed,
            'expires_at' => $result['expires_at'],
            'overdue_at' => $result['overdue_at'],
            'overdue_basis' => $result['overdue_basis'],
            'runs_total' => $result['runs_total'],
            'runs_completed' => $result['runs_completed'],
        ];
        if ($result['read_error'] !== null) {
            $response['read_error'] = $result['read_error'];
        }
        if ($result['read_skipped'] !== null) {
            $response['read_skipped'] = $result['read_skipped'];
        }
        if ($result['overdue_sent_at'] !== null) {
            $response['overdue_sent_at'] = $result['overdue_sent_at'];
        }
        if ($result['retry_not_before'] !== null) {
            $response['retry_not_before'] = $result['retry_not_before'];
        }
        if ($deliveryKnown === false && $result['state'] !== 'settled') {
            $response['warning'] = "this bridge holds no stored workflow_run delivery from {$configured}: if that repo's webhook does not send Workflow runs here, only the ci-await-sweep's own reads settle this await — at least one sweep interval after CI finishes, and only while that sweep runs. (None stored is not proof — retention prunes old deliveries.)";
        }

        return $response;
    }
}
