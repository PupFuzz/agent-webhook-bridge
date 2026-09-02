<?php

namespace Tests\Feature\Classifiers;

use App\Bridge\Classifiers\CoordinationClassifier;
use App\Bridge\Dispatch\Actor;
use App\Bridge\Dispatch\ClassifyContext;
use App\Bridge\Dispatch\ClassifyResult;
use App\Bridge\Handlers\KanbanCoordCardHandler;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\ClassifierConfig;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The `coord-card-create` family of CoordinationClassifier (DL-198): a coordination
 * issue opened/reopened with a recognized `[PREFIX]` title emits ONE
 * `kanban_coord_card` writeback target (no intent). Byte-exact `stableId` +
 * config-gated (create_coord_cards) + board-level (no recipient gate).
 */
class CoordinationCardCreateClassifierTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/coordcard-'.uniqid();
        File::ensureDirectoryExists($this->dir);
        $this->writeMapping(['board_id' => 8, 'stages' => ['opened' => 50], 'create_coord_cards' => true, 'coord_card_stage_id' => 21]);
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

    /**
     * @param  list<string>  $labels
     * @param  array<string, mixed>  $extra  merged at the TOP level of the webhook payload
     *                                       (GitHub puts `changes` there, beside `issue`)
     */
    private function classify(string $title, string $eventType = 'issues.opened', int $number = 4, string $repo = 'org/coord', string $provider = 'github', array $labels = [], array $extra = []): ClassifyResult
    {
        $agent = AgentConfig::fromArray('me', [
            'identity' => ['github_user_id' => 99],
            'subscriptions' => [],
            'classifier' => ['class' => CoordinationClassifier::class, 'config' => ['families' => ['coord-card-create']]],
        ]);

        return (new CoordinationClassifier)->classify(new ClassifyContext(
            $eventType,
            ['issue' => [
                'number' => $number,
                'title' => $title,
                'html_url' => 'https://github.com/'.$repo.'/issues/'.$number,
                'labels' => array_map(fn (string $l) => ['name' => $l], $labels),
            ]] + $extra,
            new Actor(id: '99', name: null, isKnownAgent: false),
            $provider,
            $repo,
            $agent,
        ));
    }

    /**
     * An `issues.edited` webhook that changed the TITLE — GitHub's `changes` envelope
     * beside the issue, with the title as it stood BEFORE the edit.
     *
     * @return array<string, mixed>
     */
    private function retitle(string $from): array
    {
        return ['changes' => ['title' => ['from' => $from]]];
    }

    public function test_recognized_prefix_emits_one_coord_card_target_with_no_intent(): void
    {
        $r = $this->classify('[QUERY] can we ship?');

        $this->assertSame([], $r->intents);   // machine-only, no wake
        $this->assertCount(1, $r->targets);
        $t = $r->targets[0];
        $this->assertSame('kanban_coord_card', $t->handler);
        $this->assertSame('issue-4', $t->targetId);
        $this->assertSame('org/coord', $t->payload['repo']);
        $this->assertSame(4, $t->payload['issue_number']);
        $this->assertSame('QUERY-4', $t->payload['sid']);
        $this->assertSame('query', $t->payload['itype']);
        $this->assertSame('[QUERY] can we ship?', $t->payload['title']);
        $this->assertSame('https://github.com/org/coord/issues/4', $t->payload['issue_url']);
    }

    public function test_the_issues_labels_reach_the_handler_lowercased(): void
    {
        // card#6371: the create stage is derived from the issue's `stage:*` label, so the
        // label set is part of the target payload — the handler must not have to re-fetch
        // the issue to learn the priority the webhook already carried.
        $t = $this->classify('[TASK] do the thing', labels: ['Stage:Later', 'from:pm'])->targets[0];

        $this->assertSame(['stage:later', 'from:pm'], $t->payload['labels']);
    }

    public function test_an_unlabelled_issue_carries_an_empty_label_list(): void
    {
        $t = $this->classify('[TASK] do the thing')->targets[0];

        $this->assertSame([], $t->payload['labels']);
    }

    public function test_each_recognized_prefix_maps_to_its_itype(): void
    {
        foreach ([
            '[BRIEF] x' => ['BRIEF-4', 'brief'],
            '[ANNOUNCE] x' => ['ANNOUNCE-4', 'announce'],
            '[QUERY] x' => ['QUERY-4', 'query'],
            '[REVIEW] x' => ['REVIEW-4', 'review'],
            '[TASK] x' => ['TASK-4', 'task'],
        ] as $title => [$sid, $itype]) {
            $t = $this->classify($title)->targets[0];
            $this->assertSame($sid, $t->payload['sid'], $title);
            $this->assertSame($itype, $t->payload['itype'], $title);
        }
    }

    public function test_itype_is_the_unanchored_priority_scan_not_the_sid_prefix(): void
    {
        // The reconcile's _itype is an UNANCHORED, priority-ordered substring scan
        // (BRIEF > ANNOUNCE > QUERY > REVIEW, else task), distinct from the ANCHORED sid
        // first-prefix. On a multi-bracket title they diverge — the bridge must match the
        // reconcile's _itype so the type: tag / priority don't churn on the next pass.
        foreach ([
            '[REVIEW] of [BRIEF]' => ['REVIEW-4', 'brief'],    // sid=anchored REVIEW; itype=BRIEF (scanned first)
            '[QUERY] about [BRIEF]' => ['QUERY-4', 'brief'],
            '[TASK] see [QUERY]' => ['TASK-4', 'query'],        // TASK not in the scan → QUERY wins
            '[REVIEW] plain' => ['REVIEW-4', 'review'],
        ] as $title => [$sid, $itype]) {
            $t = $this->classify($title)->targets[0];
            $this->assertSame($sid, $t->payload['sid'], $title);
            $this->assertSame($itype, $t->payload['itype'], $title);
        }
    }

    public function test_prefix_match_has_no_trailing_boundary_query_x_matches(): void
    {
        // Byte-exact to the reconcile's anchored regex (NO trailing boundary):
        // `[QUERY]x` DOES match → QUERY-4 (a `(?=\s|$)` guard would orphan it).
        $t = $this->classify('[QUERY]x immediately after')->targets[0];
        $this->assertSame('QUERY-4', $t->payload['sid']);
    }

    public function test_prefix_is_case_insensitive_but_sid_is_upper(): void
    {
        $t = $this->classify('[query] lowercase prefix')->targets[0];
        $this->assertSame('QUERY-4', $t->payload['sid']);
        $this->assertSame('query', $t->payload['itype']);
    }

    public function test_leading_whitespace_is_trimmed_like_python_strip(): void
    {
        $t = $this->classify('   [TASK] padded')->targets[0];
        $this->assertSame('TASK-4', $t->payload['sid']);
    }

    public function test_proposal_prefix_is_not_carded(): void
    {
        $this->assertSame([], $this->classify('[PROPOSAL] not owned')->targets);
    }

    public function test_unprefixed_title_is_not_carded(): void
    {
        $this->assertSame([], $this->classify('just a plain title')->targets);
    }

    public function test_unrecognized_prefix_is_not_carded(): void
    {
        $this->assertSame([], $this->classify('[NOTE] unknown prefix')->targets);
    }

    public function test_non_prefixed_carded_under_population_all(): void
    {
        // #4553: population=all cards a non-prefixed issue by the github_issue by-ref
        // key. sid is null (no id: tag); itype falls back to 'task'.
        $this->writeMapping(['board_id' => 8, 'stages' => ['opened' => 50], 'create_coord_cards' => true, 'coord_card_stage_id' => 21, 'issue_population' => 'all']);

        $r = $this->classify('a plain non-prefixed title');
        $this->assertSame([], $r->intents);
        $this->assertCount(1, $r->targets);
        $t = $r->targets[0];
        $this->assertSame('kanban_coord_card', $t->handler);
        $this->assertSame('issue-4', $t->targetId);
        $this->assertNull($t->payload['sid']);
        $this->assertSame(4, $t->payload['issue_number']);
        $this->assertSame('task', $t->payload['itype']);
        $this->assertSame('a plain non-prefixed title', $t->payload['title']);
        $this->assertSame('https://github.com/org/coord/issues/4', $t->payload['issue_url']);
    }

    public function test_non_prefixed_not_carded_under_prefixed_default(): void
    {
        // The default (prefixed) is byte-identical DL-198: a non-prefixed issue is never
        // carded even with create_coord_cards on. (Guards the fork against widening the default.)
        $this->assertSame([], $this->classify('a plain non-prefixed title')->targets);
    }

    public function test_prefixed_still_carded_under_population_all(): void
    {
        $this->writeMapping(['board_id' => 8, 'stages' => ['opened' => 50], 'create_coord_cards' => true, 'coord_card_stage_id' => 21, 'issue_population' => 'all']);
        $t = $this->classify('[QUERY] still carded')->targets[0];
        $this->assertSame('QUERY-4', $t->payload['sid']);   // prefixed path unchanged (tag key)
        $this->assertSame(4, $t->payload['issue_number']);
    }

    public function test_reopened_also_emits(): void
    {
        $this->assertCount(1, $this->classify('[REVIEW] reopen me', 'issues.reopened')->targets);
    }

    public function test_other_issue_action_is_ignored(): void
    {
        $this->assertSame([], $this->classify('[QUERY] x', 'issues.closed')->targets);
        // A BARE `issues.edited` — a body edit, or any edit carrying no `changes.title` —
        // stays the no-op it always was. Since DL-341 the same action DOES emit when it
        // carried a real title change; that arm is pinned by the retitle tests below, and
        // this line is what keeps it from having widened `edited` wholesale.
        $this->assertSame([], $this->classify('[QUERY] x', 'issues.edited')->targets);
        $this->assertSame([], $this->classify('[QUERY] x', 'issues.edited', extra: ['changes' => ['body' => ['from' => 'old body']]])->targets);
    }

    // ---- DL-341: the retitle arm (the coord sibling of DL-328) ----

    public function test_a_retitle_emits_a_rename_target_carrying_the_previous_title(): void
    {
        // The target carries BOTH sides of the change: the new title to write and
        // `name_from`, the title as it stood before the edit — the only string that can
        // prove the card's name is still the one the bridge stamped.
        $r = $this->classify('[QUERY] can we ship it THIS week?', 'issues.edited', extra: $this->retitle('[QUERY] can we ship?'));

        $this->assertSame([], $r->intents);   // machine-only, no wake
        $this->assertCount(1, $r->targets);
        $t = $r->targets[0];
        $this->assertSame('kanban_coord_card', $t->handler);
        $this->assertSame('issue-4', $t->targetId);
        $this->assertSame('org/coord', $t->payload['repo']);
        $this->assertSame(4, $t->payload['issue_number']);
        $this->assertSame('QUERY-4', $t->payload['sid']);
        $this->assertSame(KanbanCoordCardHandler::RENAMED_DISPOSITION, $t->payload['disposition']);
        $this->assertSame('[QUERY] can we ship it THIS week?', $t->payload['title']);
        $this->assertSame('[QUERY] can we ship?', $t->payload['name_from']);
    }

    public function test_a_prefix_changing_retitle_keys_the_correlation_on_the_old_prefix(): void
    {
        // ⭐ The card carries the tag the bridge stamped at birth — `id:QUERY-4` — and
        // `stableId` reads the ANCHORED prefix, so keying on the NEW title would search for
        // `id:TASK-4`, find nothing, and restamp nothing. The sid must come from `name_from`.
        $t = $this->classify('[TASK] ship it', 'issues.edited', extra: $this->retitle('[QUERY] can we ship?'))->targets[0];

        $this->assertSame('QUERY-4', $t->payload['sid']);
        $this->assertSame('query', $t->payload['itype']);   // the carded thread's itype, not the new title's
        $this->assertSame('[TASK] ship it', $t->payload['title']);
    }

    public function test_a_retitle_to_the_same_title_emits_nothing(): void
    {
        // Nothing changed ⇒ no write to make. Refused HERE so the handler never sees a
        // rename target whose two strings are equal.
        $this->assertSame([], $this->classify('[QUERY] x', 'issues.edited', extra: $this->retitle('[QUERY] x'))->targets);
    }

    public function test_a_retitle_with_an_empty_previous_title_emits_nothing(): void
    {
        // An empty `from` is read as NO retitle, never as an empty previous name: an empty
        // previous name would compare equal to nothing and prove nothing.
        $this->assertSame([], $this->classify('[QUERY] x', 'issues.edited', extra: ['changes' => ['title' => ['from' => '']]])->targets);
    }

    public function test_a_retitle_of_a_non_prefixed_issue_emits_nothing(): void
    {
        // ⛔ THE STATED GAP, pinned so it stays a decision rather than drifting into one.
        // Under `issue_population: all` a non-prefixed issue's card is correlated BY-REF and
        // carries no `id:` tag, so the restamp — which resolves by tag alone — has no key
        // for it. Those cards keep their birth name; docs/writeback.md states it.
        $this->writeMapping(['board_id' => 8, 'stages' => ['opened' => 50], 'create_coord_cards' => true, 'coord_card_stage_id' => 21, 'issue_population' => 'all']);

        $this->assertSame([], $this->classify('a plain issue, edited', 'issues.edited', extra: $this->retitle('a plain issue'))->targets);
    }

    public function test_a_retitle_on_a_mapping_without_create_coord_cards_emits_nothing(): void
    {
        // Same opt-in gate as the create arm: a repo the bridge never cards owns no name here.
        $this->writeMapping(['board_id' => 8, 'stages' => ['opened' => 50]]);

        $this->assertSame([], $this->classify('[QUERY] y', 'issues.edited', extra: $this->retitle('[QUERY] x'))->targets);
    }

    public function test_a_retitle_on_an_unmapped_repo_emits_nothing(): void
    {
        $this->assertSame([], $this->classify('[QUERY] y', 'issues.edited', 4, 'other/repo', extra: $this->retitle('[QUERY] x'))->targets);
    }

    public function test_consumed_event_types_declares_the_edited_action_with_the_family(): void
    {
        // DL-196: the declaration and the dispatch guard read the same two constants, so a
        // family that acts on an action it never declared cannot exist. `issues.edited` is
        // the retitle arm's, and it must not appear for a family that does not carry it.
        $types = (new CoordinationClassifier)->consumedEventTypes(ClassifierConfig::fromClassifierSection(
            ['class' => CoordinationClassifier::class, 'config' => ['families' => ['coord-card-create']]],
        ));
        sort($types);
        $this->assertSame(['issues.edited', 'issues.opened', 'issues.reopened'], $types);

        $this->assertNotContains('issues.edited', (new CoordinationClassifier)->consumedEventTypes(ClassifierConfig::fromClassifierSection(
            ['class' => CoordinationClassifier::class, 'config' => ['families' => ['coord-card-move', 'coord-card-relane']]],
        )));
    }

    public function test_non_github_provider_is_ignored(): void
    {
        $this->assertSame([], $this->classify('[QUERY] x', 'issues.opened', 4, 'org/coord', 'kanban')->targets);
    }

    public function test_mapping_without_create_coord_cards_emits_nothing(): void
    {
        // Opt-in gate: default-off ⇒ byte-identical no-op even for a recognized prefix.
        $this->writeMapping(['board_id' => 8, 'stages' => ['opened' => 50]]);

        $this->assertSame([], $this->classify('[QUERY] x')->targets);
    }

    public function test_unmapped_repo_emits_nothing(): void
    {
        $this->assertSame([], $this->classify('[QUERY] x', 'issues.opened', 4, 'other/repo')->targets);
    }

    public function test_family_disabled_by_default_emits_nothing(): void
    {
        // The family is NOT a default — an agent without it in classifier.config.families
        // never cards (back-compat: default families are [coord-message]).
        $agent = AgentConfig::fromArray('me', [
            'identity' => ['github_user_id' => 99],
            'subscriptions' => [],
            'classifier' => ['class' => CoordinationClassifier::class],
        ]);

        $r = (new CoordinationClassifier)->classify(new ClassifyContext(
            'issues.opened',
            ['issue' => ['number' => 4, 'title' => '[QUERY] x', 'html_url' => 'https://x/4', 'labels' => []]],
            new Actor(id: '99', name: null, isKnownAgent: false),
            'github',
            'org/coord',
            $agent,
        ));

        $this->assertSame([], $r->targets);
    }
}
