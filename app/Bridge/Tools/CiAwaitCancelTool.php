<?php

namespace App\Bridge\Tools;

use App\Bridge\CiAwait\CiAwaitService;
use App\Bridge\Exceptions\ToolRefusalException;
use App\Bridge\Support\BoardToolsConfig;
use App\Bridge\Support\RedactedErrorText;
use App\Bridge\Writeback\KanbanClient;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * ci_await_cancel (card#11200 / DL-452) — remove the calling seat's OWN await on one head, so no
 * `ci_settled` or `ci_await_expired` is sent for it.
 *
 * ⛔ SELF-SCOPED, like {@see CiAwaitTool}: only an await the calling seat registered is ever
 * removed, and no argument can name another seat. Cancelling a head this seat does not await
 * answers `cancelled: false` — whether nothing was registered, it already settled or expired, or
 * only ANOTHER seat awaits it — so a hook may cancel unconditionally.
 */
final class CiAwaitCancelTool implements Tool
{
    public function name(): string
    {
        return 'ci_await_cancel';
    }

    public function acceptedArguments(): array
    {
        return ['repo', 'head_sha'];
    }

    public function refusedArgumentReason(string $key): ?string
    {
        return CiAwaitArgs::identityReason($key);
    }

    public function call(array $args, BoardToolsConfig $cfg, KanbanClient $client, string $agentName): array
    {
        $repo = CiAwaitArgs::repo($args, $this->name());
        $headSha = CiAwaitArgs::headSha($args, $this->name());

        try {
            $cancelled = app(CiAwaitService::class)->cancel($agentName, $repo, $headSha);
        } catch (QueryException $e) {
            Log::warning('ci_await_cancel: the await store could not be read', ['agent' => $agentName] + RedactedErrorText::logContext($e));

            throw new ToolRefusalException('ci_await_cancel: this bridge could not reach its await store (its `ci_awaits` table is missing or the database did not answer), so whether an await was removed is unknown. This is an INSTALL fault — `php artisan migrate` creates the table; tell your operator.', installFault: true, reason: 'install_fault.ci_await_store_unavailable');
        }

        Log::info('ci_await_cancel: done', ['agent' => $agentName, 'repo' => $repo, 'head_sha' => $headSha, 'cancelled' => $cancelled]);

        return ['repo' => $repo, 'head_sha' => $headSha, 'cancelled' => $cancelled];
    }
}
