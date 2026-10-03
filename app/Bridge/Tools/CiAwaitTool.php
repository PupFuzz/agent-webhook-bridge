<?php

namespace App\Bridge\Tools;

use App\Bridge\CiAwait\CiAwaitConfig;
use App\Bridge\CiAwait\CiAwaitService;
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
 * and writes none, so it works on any install whose seat has board tools, coordination repo or not.
 *
 * ⛔ A REPO THIS INSTALL RECEIVES NO GITHUB EVENTS FOR IS REFUSED (`repo_not_received`): no agent
 * here subscribes to it, so no `workflow_run.completed` would ever arrive for it.
 */
final class CiAwaitTool implements Tool
{
    public function name(): string
    {
        return 'ci_await';
    }

    public function acceptedArguments(): array
    {
        return ['repo', 'head_sha', 'pr'];
    }

    public function refusedArgumentReason(string $key): ?string
    {
        return CiAwaitArgs::identityReason($key);
    }

    /** The `pr` column is an unsigned 32-bit integer; a larger number cannot be stored. */
    public const PR_MAX = 4294967295;

    public function call(array $args, BoardToolsConfig $cfg, KanbanClient $client, string $agentName): array
    {
        $repo = CiAwaitArgs::repo($args, $this->name());
        $headSha = CiAwaitArgs::headSha($args, $this->name());
        $pr = $args['pr'] ?? null;
        if ($pr !== null && (! is_int($pr) || $pr < 1 || $pr > self::PR_MAX)) {
            throw new ToolRefusalException('ci_await: `pr`, when sent, must be a positive integer no larger than '.self::PR_MAX.' (the pull request number) or null. Nothing was stored.', reason: 'bad_arguments');
        }

        try {
            $ttl = CiAwaitConfig::ttlSeconds();
            $maxPerSeat = CiAwaitConfig::maxPerSeat();
            $cooldown = CiAwaitConfig::readCooldownSeconds();
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
            throw new ToolRefusalException("ci_await: this bridge receives no GitHub events for `{$repo}` — no agent on this install subscribes to it — so nothing would ever tell it the CI there finished. Nothing was stored. Poll with ci-read instead, or ask your operator to subscribe this install to that repo.", reason: 'repo_not_received');
        }

        $service = app(CiAwaitService::class);
        // ⛔ ONLY THE STORE ITSELF may answer "nothing was stored": everything after it runs on an
        // await that exists, so its failures are `unmeasured`, never this refusal.
        try {
            $load = $service->seatLoad($agentName, $configured, $headSha);
            if (! $load['this_head'] && $load['others'] >= $maxPerSeat) {
                throw new ToolRefusalException("ci_await: you are already awaiting {$load['others']} head(s), and this bridge allows {$maxPerSeat} per seat (BRIDGE_CI_AWAIT_MAX_PER_SEAT). Nothing was stored. Cancel a wait you no longer need with ci_await_cancel, or let one settle or expire; re-registering a head you already await is always allowed.", reason: 'too_many_awaits');
            }
            $refreshed = $service->store($agentName, $configured, $headSha, $pr, $ttl);
        } catch (QueryException $e) {
            Log::warning('ci_await: the await store could not be written', ['agent' => $agentName] + RedactedErrorText::logContext($e));

            throw new ToolRefusalException('ci_await: this bridge could not store the await (its `ci_awaits` table is missing or the database did not answer). Nothing was stored. This is an INSTALL fault — `php artisan migrate` creates the table; tell your operator.', installFault: true, reason: 'install_fault.ci_await_store_unavailable');
        }

        $result = $service->evaluateRegistration($agentName, $configured, $headSha, $pr, $cooldown);
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
            'runs_total' => $result['runs_total'],
            'runs_completed' => $result['runs_completed'],
        ];
        if ($result['read_error'] !== null) {
            $response['read_error'] = $result['read_error'];
        }
        if ($result['read_skipped'] !== null) {
            $response['read_skipped'] = $result['read_skipped'];
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
