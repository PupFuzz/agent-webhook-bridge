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

    private function classify(string $title, string $eventType = 'issues.opened', int $number = 4, string $repo = 'org/coord', string $provider = 'github'): ClassifyResult
    {
        $agent = AgentConfig::fromArray('me', [
            'identity' => ['github_user_id' => 99],
            'subscriptions' => [],
            'classifier' => ['class' => CoordinationClassifier::class, 'config' => ['families' => ['coord-card-create']]],
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
        $this->assertSame([], $this->classify('[QUERY] x', 'issues.edited')->targets);
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
