<?php

namespace Tests\Fixtures;

use App\Bridge\Contracts\Classifier;
use App\Bridge\Dispatch\ClassifyContext;
use App\Bridge\Dispatch\ClassifyResult;
use App\Bridge\Dispatch\ReactionTarget;

/**
 * Emits one real `kanban_move_card` target per event, for the card and outcome the event body's
 * `probe` object names (defaults: card 5, `merged`) — so a receiver-level test drives the
 * production move handler through the route, the dispatcher and the owed-write queue with a
 * different outcome per delivery. With `probe.with_probes: true` it also emits a second DURABLE
 * target (`test_durable_probe`) and a best-effort one (`test_push_probe`), which a test
 * registers as {@see RecordingDurableHandler} / {@see RecordingHandler}; with `probe.only_push:
 * true` it emits the best-effort one ALONE — a delivery with no durable target. `probe.extra` is
 * merged into the move's payload (e.g. `stamp_pr`).
 */
class OwedWriteProbeClassifier implements Classifier
{
    public function classify(ClassifyContext $ctx): ClassifyResult
    {
        $probe = is_array($ctx->payload['probe'] ?? null) ? $ctx->payload['probe'] : [];
        $card = (int) ($probe['card'] ?? 5);

        if (($probe['only_push'] ?? false) === true) {
            return new ClassifyResult(targets: [ReactionTarget::make('test_push_probe', 'probe')]);
        }

        $extra = is_array($probe['extra'] ?? null) ? $probe['extra'] : [];
        $targets = [ReactionTarget::make('kanban_move_card', (string) $card, payload: [
            'card_id' => $card, 'repo' => 'owner/repo', 'outcome' => (string) ($probe['outcome'] ?? 'merged'),
        ] + $extra)];
        if (($probe['with_probes'] ?? false) === true) {
            $targets[] = ReactionTarget::make('test_durable_probe', 'probe');
            $targets[] = ReactionTarget::make('test_push_probe', 'probe');
        }

        return new ClassifyResult(targets: $targets);
    }
}
