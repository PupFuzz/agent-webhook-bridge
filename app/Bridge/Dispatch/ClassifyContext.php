<?php

namespace App\Bridge\Dispatch;

use App\Bridge\Contracts\Classifier;
use App\Bridge\Support\AgentConfig;

/**
 * Everything a {@see Classifier} needs to classify one
 * event for one serving agent, as a SINGLE context object so the classify()
 * signature never has to grow again (DL-025).
 *
 * Adding a field here is non-breaking for every implementor — that is the whole
 * point. The prior positional signature broke custom classifiers twice with an
 * UNCATCHABLE E_COMPILE_ERROR: DL-002 (provider-aware actor) and DL-022 (the
 * serving $agent). A parameter object ends that class of break for good.
 *
 * - eventType / payload: the event and its parsed body.
 * - actor: the resolved author (provider-aware, DL-002).
 * - scopeId: the receiver-extracted scope (board_id / repo full_name); provider
 *   is its symmetric peer.
 * - agent: the serving agent — the dispatcher invokes classify() once per
 *   subscribed agent, enabling per-agent (recipient-aware) decisions from
 *   `agent->agentName` / identity. Classifier instances are shared + cached per
 *   class (see ClassifierResolver), so per-event state lives HERE, never on the
 *   classifier instance.
 */
final class ClassifyContext
{
    /**
     * @param  array<mixed>  $payload  the parsed event body
     */
    public function __construct(
        public readonly string $eventType,
        public readonly array $payload,
        public readonly Actor $actor,
        public readonly string $provider,
        public readonly string $scopeId,
        public readonly AgentConfig $agent,
    ) {}
}
