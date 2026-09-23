<?php

namespace App\Bridge\Support;

use App\Bridge\Contracts\Handler;
use App\Bridge\Handlers\ChannelPushHandler;
use App\Bridge\Handlers\GitHubPrCorrelationCommentHandler;
use App\Bridge\Handlers\GitHubProtocolInvalidLabelHandler;
use App\Bridge\Handlers\KanbanBlockReasonHandler;
use App\Bridge\Handlers\KanbanCoordCardHandler;
use App\Bridge\Handlers\KanbanCoordCardMoveHandler;
use App\Bridge\Handlers\KanbanDependabotCardHandler;
use App\Bridge\Handlers\KanbanMoveCardHandler;
use App\Bridge\Handlers\KanbanPromoteReleasedHandler;
use App\Bridge\Handlers\LogIntentHandler;
use App\Bridge\Handlers\RegistryAppendHandler;
use App\Bridge\Handlers\SpawnDetachedHandler;
use App\Bridge\Writeback\PrCorrelationComment;
use App\Bridge\Writeback\ProtocolInvalidLabeler;

/**
 * Resolves a ReactionTarget's handler name to a Handler instance. Ships always-on
 * defaults (log_intent, registry_append, channel_push, kanban_move_card,
 * kanban_promote_released, kanban_dependabot_card, kanban_block_reason,
 * kanban_coord_card, kanban_coord_card_move, github_pr_correlation_comment,
 * github_protocol_invalid_label — `known()`
 * is the live set); the
 * highest-blast-radius spawn_detached is opt-in (DL-011)
 * — registered only when $spawnDetachedEnabled (wired from
 * config('bridge.spawn.enabled') by BridgeServiceProvider). Operators register
 * additional handlers (e.g. from a service provider). When spawn_detached is not
 * registered, a classifier emitting it resolves to null → the dispatcher records
 * a best-effort note, not an execution.
 *
 * The kanban writeback handlers (kanban_move_card DL-020, kanban_promote_released
 * DL-207, kanban_dependabot_card DL-024, kanban_block_reason DL-193, kanban_coord_card
 * DL-198, kanban_coord_card_move DL-200, and the github_pr_correlation_comment report DL-390) are always-on
 * because they are INERT without `writeback.json` + a writeback token (they no-op,
 * unlike spawn_detached which would execute), and the classifier only emits them
 * for configured repos/opt-ins. github_protocol_invalid_label (DL-408) is always-on for
 * the same reason with its own inert state: it writes nothing for a repo absent from
 * `bridge.protocol_invalid_label.repos`, which is empty by default.
 */
final class HandlerRegistry
{
    /**
     * The registry key for the live-wake push handler, owned HERE because this table is
     * what {@see resolve} matches a ReactionTarget against — the name is a property of
     * the mapping, not of any one class an operator may replace under it.
     *
     * Named because the key was spelled as a bare literal at every site that EMITS,
     * RESOLVES or GUARDS this handler, with no site holding it (card#9172). Divergence
     * between any two of them is silent — a target nothing resolves, or a guard that
     * quietly stops covering the handler it was written for. Which sites those are is a
     * grep, not a list to maintain here:
     * `command grep -rn "CHANNEL_PUSH|'channel_push'" app/`.
     */
    public const CHANNEL_PUSH = 'channel_push';

    /**
     * @var array<string, Handler>
     */
    private array $handlers;

    public function __construct(bool $spawnDetachedEnabled = false)
    {
        $this->handlers = [
            'log_intent' => new LogIntentHandler,
            'registry_append' => new RegistryAppendHandler,
            self::CHANNEL_PUSH => new ChannelPushHandler,
            'kanban_move_card' => new KanbanMoveCardHandler,
            'kanban_promote_released' => new KanbanPromoteReleasedHandler,
            'kanban_dependabot_card' => new KanbanDependabotCardHandler,
            'kanban_block_reason' => new KanbanBlockReasonHandler,
            'kanban_coord_card' => new KanbanCoordCardHandler,
            'kanban_coord_card_move' => new KanbanCoordCardMoveHandler,
            PrCorrelationComment::HANDLER => new GitHubPrCorrelationCommentHandler,
            ProtocolInvalidLabeler::HANDLER => new GitHubProtocolInvalidLabelHandler,
        ];
        if ($spawnDetachedEnabled) {
            $this->handlers['spawn_detached'] = new SpawnDetachedHandler;
        }
    }

    public function register(string $name, Handler $handler): void
    {
        $this->handlers[$name] = $handler;
    }

    public function resolve(string $name): ?Handler
    {
        return $this->handlers[$name] ?? null;
    }

    /**
     * The live-wake push handler, non-null by construction: the constructor always registers
     * one and {@see register()} can only replace it, never remove it. A caller that needs
     * THIS handler therefore owes no "not registered" branch — it would be dead code.
     */
    public function channelPush(): Handler
    {
        return $this->handlers[self::CHANNEL_PUSH];
    }

    /**
     * @return list<string>
     */
    public function known(): array
    {
        $names = array_keys($this->handlers);
        sort($names);

        return $names;
    }
}
