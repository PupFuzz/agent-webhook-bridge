<?php

namespace Tests\Feature\Classifiers;

use App\Bridge\Classifiers\CoordinationClassifier;
use App\Bridge\Dispatch\Actor;
use App\Bridge\Dispatch\ClassifyContext;
use App\Bridge\Dispatch\ClassifyResult;
use App\Bridge\Support\AgentConfig;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * DL-200 — the `coord-card-move` family: a coordination issue CLOSING moves its
 * tracking card to the terminal; a REOPEN revives it. The unshipped sibling of
 * DL-198's create leg (roundtable #18(b)).
 *
 * Classifier-level only (no HTTP): asserts the emitted targets. The tag lookup,
 * the actor-gate and the board write are the handler's, tested separately.
 */
class CoordinationCardMoveClassifierTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/coordmove-'.uniqid();
        File::ensureDirectoryExists($this->dir);
        $this->writeMapping(['board_id' => 8, 'stages' => ['opened' => 50], 'move_coord_cards' => true,
            'coord_card_stage_id' => 21, 'coord_card_terminal_stage_id' => 99]);
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

    private function classify(string $title, string $eventType = 'issues.closed', int $number = 4, string $repo = 'org/coord', string $provider = 'github'): ClassifyResult
    {
        $agent = AgentConfig::fromArray('me', [
            'identity' => ['github_user_id' => 99],
            'subscriptions' => [],
            'classifier' => ['class' => CoordinationClassifier::class, 'config' => ['families' => ['coord-card-move']]],
        ]);

        return (new CoordinationClassifier)->classify(new ClassifyContext(
            $eventType,
            ['issue' => ['number' => $number, 'title' => $title, 'html_url' => 'https://github.com/'.$repo.'/issues/'.$number]],
            new Actor(id: '99', name: null, isKnownAgent: false),
            $provider,
            $repo,
            $agent,
        ));
    }

    public function test_closed_issue_emits_one_terminal_move_target(): void
    {
        $result = $this->classify('[QUERY] something', 'issues.closed', 7);

        $this->assertCount(1, $result->targets);
        $target = $result->targets[0];
        $this->assertSame('kanban_coord_card_move', $target->handler);
        $this->assertSame('issue-7', $target->targetId);
        $this->assertSame([
            'repo' => 'org/coord',
            'issue_number' => 7,
            'sid' => 'QUERY-7',
            'disposition' => 'terminal',
        ], $target->payload);
    }

    public function test_reopened_issue_emits_a_revive_target(): void
    {
        // The reopen composition (roundtable #18): create-if-absent is the
        // coord-card-create family's job; THIS family carries revive-if-present.
        // The tag lookup in each handler makes them mutually exclusive.
        $result = $this->classify('[BRIEF] x', 'issues.reopened', 12);

        $this->assertCount(1, $result->targets);
        $this->assertSame('revive', $result->targets[0]->payload['disposition']);
        $this->assertSame('BRIEF-12', $result->targets[0]->payload['sid']);
    }

    public function test_emits_no_intent_and_no_wake(): void
    {
        // Machine-only, exactly like the create leg: a board write is not a message.
        $result = $this->classify('[QUERY] x', 'issues.closed');

        $this->assertSame([], $result->intents);
    }

    public function test_move_coord_cards_off_emits_nothing(): void
    {
        // DL-204 (#4357): the opt-out gate is now EXPLICIT move_coord_cards:false — omission
        // defaults the leg ON where a terminal is configured (see the default-on sibling below).
        $this->writeMapping(['board_id' => 8, 'stages' => ['opened' => 50], 'move_coord_cards' => false,
            'coord_card_stage_id' => 21, 'coord_card_terminal_stage_id' => 99]);

        $this->assertSame([], $this->classify('[QUERY] x', 'issues.closed')->targets);
    }

    public function test_move_coord_cards_defaults_on_without_the_flag_and_emits(): void
    {
        // DL-204 fleet default: a complete move config (terminal present) WITHOUT move_coord_cards
        // resolves the leg ON, so the family emits the move target — the classifier self-gate reads
        // the same WritebackConfig default the handler does.
        $this->writeMapping(['board_id' => 8, 'stages' => ['opened' => 50],
            'coord_card_stage_id' => 21, 'coord_card_terminal_stage_id' => 99]);

        $targets = $this->classify('[QUERY] x', 'issues.closed')->targets;
        $this->assertCount(1, $targets);
        $this->assertSame('terminal', $targets[0]->payload['disposition']);
    }

    public function test_non_prefixed_closed_emits_terminal_move_under_population_all(): void
    {
        // #4553: population=all moves a non-prefixed card (by-ref keyed in the handler).
        // sid is null; disposition still derives from the event.
        $this->writeMapping(['board_id' => 8, 'stages' => ['opened' => 50], 'move_coord_cards' => true,
            'coord_card_stage_id' => 21, 'coord_card_terminal_stage_id' => 99, 'issue_population' => 'all']);

        $t = $this->classify('a plain non-prefixed title', 'issues.closed', 7)->targets[0];
        $this->assertSame('kanban_coord_card_move', $t->handler);
        $this->assertNull($t->payload['sid']);
        $this->assertSame('terminal', $t->payload['disposition']);
        $this->assertSame(7, $t->payload['issue_number']);
    }

    public function test_non_prefixed_reopen_revives_under_population_all(): void
    {
        $this->writeMapping(['board_id' => 8, 'stages' => ['opened' => 50], 'move_coord_cards' => true,
            'coord_card_stage_id' => 21, 'coord_card_terminal_stage_id' => 99, 'issue_population' => 'all']);

        $t = $this->classify('a plain non-prefixed title', 'issues.reopened', 7)->targets[0];
        $this->assertNull($t->payload['sid']);
        $this->assertSame('revive', $t->payload['disposition']);
    }

    public function test_non_prefixed_not_moved_under_prefixed_default(): void
    {
        // Byte-identical DL-200: the default (prefixed) never moves a non-prefixed card.
        $this->assertSame([], $this->classify('a plain non-prefixed title', 'issues.closed')->targets);
    }

    public function test_opened_is_not_a_move(): void
    {
        // opened belongs to the CREATE leg. If the move family also fired here it
        // would move a just-created card — the two legs must not overlap.
        $this->assertSame([], $this->classify('[QUERY] x', 'issues.opened')->targets);
    }

    public function test_edited_is_ignored(): void
    {
        // A title/body edit is not a lifecycle transition.
        $this->assertSame([], $this->classify('[QUERY] x', 'issues.edited')->targets);
    }

    public function test_unrecognized_prefix_is_not_moved(): void
    {
        // The move-set must equal the create-set — a card that was never created
        // by sid can't be correlated by it. PROPOSAL is deliberately not carded.
        $this->assertSame([], $this->classify('[PROPOSAL] x', 'issues.closed')->targets);
        $this->assertSame([], $this->classify('no prefix here', 'issues.closed')->targets);
    }

    public function test_non_github_provider_is_ignored(): void
    {
        $this->assertSame([], $this->classify('[QUERY] x', 'issues.closed', 4, 'org/coord', 'kanban')->targets);
    }

    public function test_unmapped_repo_emits_nothing(): void
    {
        $this->assertSame([], $this->classify('[QUERY] x', 'issues.closed', 4, 'other/repo')->targets);
    }

    public function test_family_not_enabled_emits_nothing(): void
    {
        // Default families are [coord-message] — the move leg must be explicitly enabled.
        $agent = AgentConfig::fromArray('me', [
            'identity' => ['github_user_id' => 99],
            'subscriptions' => [],
            'classifier' => ['class' => CoordinationClassifier::class],
        ]);

        $result = (new CoordinationClassifier)->classify(new ClassifyContext(
            'issues.closed',
            ['issue' => ['number' => 4, 'title' => '[QUERY] x', 'html_url' => 'https://x/4']],
            new Actor(id: '99', name: null, isKnownAgent: false),
            'github',
            'org/coord',
            $agent,
        ));

        $this->assertSame([], $result->targets);
    }

    public function test_reopen_emits_both_legs_targets_under_distinct_handlers(): void
    {
        // The CLASSIFY half of the reopen composition: on issues.reopened both families
        // emit, and they must land on DISTINCT handlers.
        //
        // Why distinct handlers is load-bearing: both targets default their debounceKey
        // to the same targetId ("issue-N"), so they'd collide if the dispatcher coalesced
        // on debounceKey alone. It doesn't — it keys on (handler, debounceKey) — and that
        // is pinned at the layer that can actually prove it, by the real dispatcher, in
        // DispatchServiceTest ("Coalescing keys on (handler, debounceKey)…", via
        // SameKeyDistinctHandlersClassifier). This test does NOT re-prove that: it can't.
        // Recomputing the dispatcher's key here would assert a string this file built
        // itself and pass even if the dispatcher's rule changed underneath it (verified —
        // mutating DispatchService's key reds DispatchServiceTest, not this file).
        // So: assert only what classify-time can honestly show — two targets, two handlers.
        $this->writeMapping(['board_id' => 8, 'stages' => ['opened' => 50],
            'create_coord_cards' => true, 'move_coord_cards' => true,
            'coord_card_stage_id' => 21, 'coord_card_terminal_stage_id' => 99]);

        $agent = AgentConfig::fromArray('me', [
            'identity' => ['github_user_id' => 99],
            'subscriptions' => [],
            'classifier' => ['class' => CoordinationClassifier::class,
                'config' => ['families' => ['coord-card-create', 'coord-card-move']]],
        ]);
        $result = (new CoordinationClassifier)->classify(new ClassifyContext(
            'issues.reopened',
            ['issue' => ['number' => 4, 'title' => '[QUERY] x', 'html_url' => 'https://x/4']],
            new Actor(id: '99', name: null, isKnownAgent: false),
            'github',
            'org/coord',
            $agent,
        ));

        $this->assertCount(2, $result->targets, 'both legs must emit on reopened');
        $handlers = array_map(fn ($t) => $t->handler, $result->targets);
        sort($handlers);
        $this->assertSame(['kanban_coord_card', 'kanban_coord_card_move'], $handlers);
    }

    public function test_declares_issues_as_a_consumed_event_type(): void
    {
        // DL-196: a family that consumes an event type must declare it, or
        // bridge:check reports the arriving event as unconsumed.
        $cfg = AgentConfig::fromArray('me', [
            'identity' => ['github_user_id' => 99],
            'subscriptions' => [],
            'classifier' => ['class' => CoordinationClassifier::class, 'config' => ['families' => ['coord-card-move']]],
        ])->classifierConfig;

        // Qualified since card #4354 — the move family declares exactly the
        // actions its dispatch guard accepts (closed → terminal, reopened → revive).
        $events = (new CoordinationClassifier)->consumedEventTypes($cfg);
        $this->assertContains('issues.closed', $events);
        $this->assertContains('issues.reopened', $events);
    }
}
