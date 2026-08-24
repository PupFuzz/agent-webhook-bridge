<?php

namespace Tests\Feature\Classifiers;

use App\Bridge\Classifiers\CoordinationClassifier;
use App\Bridge\Dispatch\Actor;
use App\Bridge\Dispatch\ClassifyContext;
use App\Bridge\Dispatch\ClassifyResult;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\ClassifierConfig;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * card#6393 instance 2 — the `coord-card-relane` family: a coordination `[TASK]` that
 * gains a `stage:*` label AFTER it was carded emits a `relane` move target, so the
 * card follows the label instead of the consumer's lane→label writeback converging the
 * label back to the lane the card was created in.
 *
 * Classifier-level only (no HTTP): asserts the emitted targets. The lane gates and the
 * board write are the handler's, tested in KanbanCoordCardMoveHandlerTest.
 *
 * THE FILTER IS THE HIGH-RISK SURFACE, and the reason is a measured one: `issues.labeled`
 * is a HIGH-VOLUME action on a live coordination repo — **641** such deliveries had
 * already arrived on the reference install and been dropped as action-undeclared
 * (operator measurement, last delivery 2026-08-20 15:31:53 UTC). Enabling this family
 * turns that existing stream into board writes, so the common case for it is "do
 * nothing", and a filter wrong in the PERMISSIVE direction does not misfire once — it
 * misfires across a live stream. The three declining legs below
 * (`test_a_non_stage_label_emits_nothing`,
 * `test_the_filter_reads_the_announced_label_not_the_issues_label_set`,
 * `test_a_stage_prefixed_label_outside_the_lane_vocabulary_emits_nothing`) are that
 * direction, one shape each.
 */
class CoordinationCardRelaneClassifierTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/coordrelane-'.uniqid();
        File::ensureDirectoryExists($this->dir);
        $this->writeMapping(['board_id' => 8, 'stages' => ['opened' => 50], 'move_coord_cards' => true,
            'create_coord_cards' => true, 'coord_card_stage_id' => 21, 'coord_card_terminal_stage_id' => 99,
            'coord_card_lane_stage_ids' => ['now' => 40, 'next' => 41, 'later' => 42, 'maybe' => 43]]);
        config(['bridge.config_dir' => $this->dir, 'bridge.secret_dir' => $this->dir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /** @param array<string, mixed> $mapping */
    private function writeMapping(array $mapping, string $repo = 'org/coord'): void
    {
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => [$repo => $mapping],
        ]));
    }

    /** @param list<string> $families */
    private function agent(array $families = ['coord-card-relane']): AgentConfig
    {
        return AgentConfig::fromArray('me', [
            'identity' => ['github_user_id' => 99],
            'subscriptions' => [],
            'classifier' => ['class' => CoordinationClassifier::class, 'config' => ['families' => $families]],
        ]);
    }

    /**
     * A FULL-SHAPE `issues.labeled` body — the whole field set and nesting a delivery
     * carries, deliberately NOT reduced to the keys the classifier reads, so a classifier
     * reaching for the wrong key (the issue's label set instead of the event's own
     * `label.name`, say) is caught here rather than passing over a payload the test
     * tailored to it. What it pins is that a `labeled` delivery announces the added label
     * at the TOP LEVEL as `label.name`.
     *
     * ⚠ PROVENANCE IS NOT ATTESTED: this is a MODELLED body, not one captured from a
     * stored delivery — no delivery corpus was reachable from the branch that wrote it.
     * A leg that needs a captured payload must say so and capture one.
     *
     * Whether `issue.labels` ALSO already carries it is deliberately left as a PARAMETER
     * rather than baked in: that is the one thing about this payload nobody here has verified
     * (no delivery corpus was reachable from this branch, and nothing was read that
     * states it either way), so both shapes are driven through the classifier — present in
     * `test_a_stage_label_added_to_a_task_emits_one_relane_target`, absent in
     * `test_the_announced_label_is_unioned_into_the_lane_inputs` — and the emitted lane
     * inputs must be identical either way. Both shapes are the ADD direction; the union
     * does not reach a snapshot still listing a REMOVED lane label, and no leg here claims
     * it does (DL-290 Decision 7's bound).
     *
     * @param  list<string>  $issueLabelNames
     * @return array<string, mixed>
     */
    private function labeledBody(string $addedLabel, array $issueLabelNames, string $title = '[TASK] wire the thing'): array
    {
        $label = fn (string $name, int $id) => [
            'id' => $id,
            'node_id' => 'LA_kwDOTU6AGs8AAAACrMYD'.$id,
            'url' => 'https://api.github.com/repos/org/coord/labels/'.$name,
            'name' => $name,
            'color' => '0e8a16',
            'default' => false,
            'description' => null,
        ];

        return [
            'action' => 'labeled',
            'issue' => [
                'id' => 3456789,
                'node_id' => 'I_kwDOTU6AGs6h1',
                'number' => 4,
                'title' => $title,
                'state' => 'open',
                'html_url' => 'https://github.com/org/coord/issues/4',
                'user' => ['login' => 'pupfuzz', 'id' => 99, 'type' => 'User'],
                'labels' => array_values(array_map(fn (string $n, int $i) => $label($n, 11488592650 + $i), $issueLabelNames, array_keys($issueLabelNames))),
                'body' => 'FROM: pm',
            ],
            'label' => $label($addedLabel, 11488592716),
            'repository' => ['full_name' => 'org/coord', 'name' => 'coord'],
            'sender' => ['login' => 'pupfuzz', 'id' => 99, 'type' => 'User'],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function classify(array $payload, string $eventType = 'issues.labeled', ?AgentConfig $agent = null): ClassifyResult
    {
        $agent ??= $this->agent();

        return (new CoordinationClassifier)->classify(new ClassifyContext(
            $eventType,
            $payload,
            new Actor(id: '99', name: null, isKnownAgent: false),
            'github',
            'org/coord',
            $agent,
        ));
    }

    public function test_a_stage_label_added_to_a_task_emits_one_relane_target(): void
    {
        $result = $this->classify($this->labeledBody('stage:now', ['from:pm', 'stage:now']));

        $this->assertCount(1, $result->targets);
        $target = $result->targets[0];
        $this->assertSame('kanban_coord_card_move', $target->handler);
        $this->assertSame('issue-4', $target->targetId);
        $this->assertSame([
            'repo' => 'org/coord',
            'issue_number' => 4,
            'sid' => 'TASK-4',
            'disposition' => 'relane',
            'title' => '[TASK] wire the thing',
            'labels' => ['from:pm', 'stage:now'],
        ], $target->payload);
    }

    public function test_a_non_stage_label_emits_nothing(): void
    {
        // THE FILTER, on the shape that dominates a coordination repo's label traffic:
        // `to:*`/`from:*` addressing labels. An issue gaining one states no lane, so
        // nothing may move.
        $result = $this->classify($this->labeledBody('to:sola-pm', ['from:pm', 'to:sola-pm', 'stage:now']));

        $this->assertSame([], $result->targets);
        $this->assertSame([], $result->intents);
    }

    public function test_the_filter_reads_the_announced_label_not_the_issues_label_set(): void
    {
        // The mutation this pins: filtering on "the issue HAS a stage:* label" instead
        // of "this delivery ANNOUNCED one" would relane on every unrelated label edit to
        // any already-sequenced issue — the bulk of a live stream.
        $result = $this->classify($this->labeledBody('to:kanban-solo', ['stage:later', 'to:kanban-solo']));

        $this->assertSame([], $result->targets);
    }

    public function test_a_stage_prefixed_label_outside_the_lane_vocabulary_emits_nothing(): void
    {
        // THE PERMISSIVE-DIRECTION FAILURE, and it is not hypothetical: a
        // `str_starts_with($name, 'stage:')` trigger accepts every label an install
        // invents in that namespace. `CoordLaneStages::resolveLane` then recognizes none
        // of them, the placement falls back to DEFAULT_LANE, and the handler MOVES the
        // card there — so adding `stage:done` to a `[TASK]` sitting in `now` would demote
        // it to `later` and the consumer's lane→label pass would rewrite the issue to
        // match. That is this card's own defect, minted by the fix for it.
        //
        // The trigger is therefore the LANE VOCABULARY, not the prefix. Driven over the
        // whole out-of-vocabulary shape rather than one example, because the population
        // is open — an install can invent any suffix.
        foreach (['stage:done', 'stage:blocked', 'stage:someday', 'stage:', 'stage:nowish', 'staged:now'] as $label) {
            $this->assertSame([], $this->classify($this->labeledBody($label, ['from:pm', $label]))->targets, "announced label {$label} must not trigger a relane");
        }
    }

    public function test_every_lane_the_model_knows_does_trigger(): void
    {
        // The other side of the leg above — without it, a filter that declined
        // EVERYTHING would pass the permissive-direction tests and ship an inert family.
        foreach (['now', 'next', 'later', 'maybe'] as $lane) {
            $result = $this->classify($this->labeledBody("stage:{$lane}", ['from:pm']));

            $this->assertCount(1, $result->targets, "stage:{$lane} must trigger a relane");
            $this->assertSame('relane', $result->targets[0]->payload['disposition']);
        }
    }

    public function test_the_announced_label_is_unioned_into_the_lane_inputs(): void
    {
        // The union leg, driving the UNVERIFIED shape: a delivery whose `issue.labels`
        // snapshot does NOT yet include the label it announces. The union settles that
        // shape — a `labeled` event's label set IS "the issue's labels INCLUDING the one
        // announced", and stating it costs nothing (the trigger label is already in hand
        // for the filter). Its twin,
        // test_a_stage_label_added_to_a_task_emits_one_relane_target, drives the other
        // shape and must produce the same lane inputs.
        //
        // BOUND: this is the ADD direction only. A union cannot subtract, so a snapshot
        // still listing a just-REMOVED lane label is unaffected by it and resolves by
        // LANES order — DL-290 Decision 7 records that as an accepted bound, and nothing
        // here asserts otherwise.
        $result = $this->classify($this->labeledBody('stage:now', ['from:pm']));

        $this->assertCount(1, $result->targets);
        $this->assertSame(['from:pm', 'stage:now'], $result->targets[0]->payload['labels']);
    }

    public function test_a_mixed_case_stage_label_is_lowercased_like_every_other_label(): void
    {
        $result = $this->classify($this->labeledBody('Stage:Now', ['from:pm']));

        $this->assertCount(1, $result->targets);
        $this->assertSame(['from:pm', 'stage:now'], $result->targets[0]->payload['labels']);
    }

    public function test_the_family_is_not_a_default(): void
    {
        // Opt-in: an install that never enabled it classifies a `labeled` delivery
        // exactly as it did before card#6393 — nothing.
        $result = $this->classify($this->labeledBody('stage:now', ['stage:now']), agent: $this->agent(['coord-message', 'coord-card-create', 'coord-card-move']));

        $this->assertSame([], $result->targets);
    }

    public function test_unlabeled_emits_nothing(): void
    {
        // Deliberately not consumed: removing a `stage:*` label states no lane, and
        // re-deriving on it would move the card to the `later` default — inventing a
        // sequencing decision nobody made.
        $body = $this->labeledBody('stage:now', ['from:pm']);
        $body['action'] = 'unlabeled';

        $this->assertSame([], $this->classify($body, 'issues.unlabeled')->targets);
    }

    public function test_a_kanban_provider_event_emits_nothing(): void
    {
        $result = (new CoordinationClassifier)->classify(new ClassifyContext(
            'task.updated',
            $this->labeledBody('stage:now', ['stage:now']),
            new Actor(id: '99', name: null, isKnownAgent: false),
            'kanban',
            '8',
            $this->agent(),
        ));

        $this->assertSame([], $result->targets);
    }

    public function test_a_mapping_without_move_coord_cards_emits_nothing(): void
    {
        // A relane IS the bridge moving a coord card; `move_coord_cards` is where the
        // operator says it may.
        $this->writeMapping(['board_id' => 8, 'stages' => ['opened' => 50], 'create_coord_cards' => true,
            'coord_card_stage_id' => 21, 'move_coord_cards' => false,
            'coord_card_lane_stage_ids' => ['now' => 40, 'later' => 42]]);

        $this->assertSame([], $this->classify($this->labeledBody('stage:now', ['stage:now']))->targets);
    }

    public function test_a_mapping_without_a_lane_model_emits_nothing(): void
    {
        // The family's second config gate, and it is tested at the CLASSIFIER because the
        // cost of leaving it to the handler is two kanban reads (a cardsByTag search plus a
        // getCard per correlated card) per `issues.labeled` delivery, forever, to discover
        // there is no lane to write. The handler still refuses — this keeps the refusal off
        // the network.
        $this->writeMapping(['board_id' => 8, 'stages' => ['opened' => 50], 'move_coord_cards' => true,
            'coord_card_stage_id' => 21, 'coord_card_terminal_stage_id' => 99]);

        $this->assertSame([], $this->classify($this->labeledBody('stage:now', ['stage:now']))->targets);
    }

    public function test_an_unmapped_repo_emits_nothing(): void
    {
        $this->writeMapping(['board_id' => 8, 'stages' => ['opened' => 50]], repo: 'org/other');

        $this->assertSame([], $this->classify($this->labeledBody('stage:now', ['stage:now']))->targets);
    }

    public function test_a_non_prefixed_issue_is_skipped_under_the_prefixed_population(): void
    {
        // The relane-set is a subset of the create-set: an issue that was never carded
        // under this key cannot be correlated by it.
        $result = $this->classify($this->labeledBody('stage:now', ['stage:now'], title: 'plain title, no prefix'));

        $this->assertSame([], $result->targets);
    }

    public function test_emits_no_intent_and_no_wake(): void
    {
        $result = $this->classify($this->labeledBody('stage:now', ['stage:now']));

        $this->assertSame([], $result->intents);
        $this->assertNull($result->reattributedActor);
    }

    public function test_consumed_event_types_declares_the_labeled_action_only_when_enabled(): void
    {
        // DL-196: the declaration and the dispatch guard read one const, so a family
        // that acts on an action it never declared cannot exist.
        $this->assertSame(
            ['issues.labeled'],
            (new CoordinationClassifier)->consumedEventTypes(ClassifierConfig::fromClassifierSection(['class' => CoordinationClassifier::class, 'config' => ['families' => ['coord-card-relane']]])),
        );
        $this->assertNotContains(
            'issues.labeled',
            (new CoordinationClassifier)->consumedEventTypes(ClassifierConfig::fromClassifierSection(['class' => CoordinationClassifier::class, 'config' => ['families' => ['coord-card-create', 'coord-card-move']]])),
        );
    }
}
