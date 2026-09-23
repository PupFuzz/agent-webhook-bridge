<?php

namespace Tests\Feature\Writeback;

use App\Bridge\Classifiers\CoordinationClassifier;
use App\Bridge\Dispatch\Actor;
use App\Bridge\Dispatch\ClassifyContext;
use App\Bridge\Dispatch\ClassifyResult;
use App\Bridge\Dispatch\Intent;
use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\ClassifierResolver;
use App\Bridge\Writeback\PrCorrelationComment;
use App\Bridge\Writeback\ProtocolInvalidLabeler;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * card#10218 / DL-408, the claim-bound control: over a matrix of coordination events, the
 * `protocol:invalid` label ONLY EVER ADDS ITS OWN TARGET. With the opt-in key unset it adds
 * nothing at all, and with the key set every case classifies to the same intents, the same other
 * targets and the same re-attributed actor, byte for byte.
 *
 * ⭐ WHY THIS EXISTS RATHER THAN A SENTENCE IN A PR BODY. Two claims the feature makes are
 * UNIVERSALS over events — "unset, classification is what it was" and "agent-facing output does
 * not move" — and a universal pinned by one payload shape is pinned by nothing. The population is
 * DERIVED here (the product of the dimensions below, re-computed every run by
 * {@see cases()}), never a figure written down, so a dimension that grows moves the denominator
 * instead of silently leaving the claim narrower than it reads.
 *
 * ⛔ WHAT IT SPANS, AND WHAT IT DOES NOT. This is the CLASSIFIER's answer — where the feature's
 * only decision lives, and the surface the default-off claim is about. Everything the dispatcher
 * then derives (inbox staging, the `channel_push` wake and its bytes) is a function of the intents
 * and non-durable targets compared here, so equality here is what makes "nothing agent-facing
 * moves" true downstream. The two consequences that are NOT visible from here have their own
 * dispatcher-level tests in {@see ProtocolInvalidLabelTest}, and this control does not restate
 * them: the ledger row an unaddressed agent records
 * (`test_a_listed_repo_changes_only_the_non_addressed_agents_ledger_row_and_nothing_agent_facing`)
 * and the echo/signal-gated agent's DL-203 strip
 * (`test_a_gated_agent_still_labels_because_the_reaction_is_durable`).
 */
class ProtocolInvalidLabelMatrixTest extends TestCase
{
    private const REPO = 'acme/coord';

    /** @var array<string, AgentConfig> the serving agent of each config case, loaded from its own single-agent install */
    private array $agents = [];

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        ClassifierResolver::flush();
        $this->dir = sys_get_temp_dir().'/protocol-invalid-matrix-'.uniqid();

        // ONE CONFIG DIR PER CONFIG CASE, each holding exactly that agent. The label's predicate is
        // install-level (DL-408 decision 2a), so an install holding every case's declarations at
        // once would exempt every case and the matrix would measure nothing.
        foreach ($this->classifierConfigs() as $case => $block) {
            $dir = $this->dir.'/'.$case;
            File::ensureDirectoryExists($dir);
            File::put($dir.'/alpha.yml', "subscriptions:\n  - provider: github\n    scopes: [\"".self::REPO."\"]\n"
                ."classifier:\n  class: '".CoordinationClassifier::class."'\n".$block
                ."channel:\n  url: http://127.0.0.1:8788/\n  route_intents: false\n");
            $this->agents[$case] = AgentConfig::load('alpha', $dir);
        }
    }

    protected function tearDown(): void
    {
        ClassifierResolver::flush();
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_the_label_adds_its_own_target_and_moves_nothing_else_in_any_case(): void
    {
        $classifier = new CoordinationClassifier;
        $moved = [];
        $labelledOff = [];
        $labelledOn = 0;
        $withIntents = 0;
        $cases = 0;

        foreach ($this->cases() as $name => [$case, $agent]) {
            $cases++;
            config(['bridge.config_dir' => $this->dir.'/'.$case['config']]);

            config(['bridge.protocol_invalid_label.repos' => []]);
            $off = $classifier->classify($this->context($case, $agent));

            config(['bridge.protocol_invalid_label.repos' => [self::REPO]]);
            $on = $classifier->classify($this->context($case, $agent));

            if ($this->render($off) !== $this->render($on, stripLabel: true)) {
                $moved[] = $name;
            }
            if ($this->labels($off) !== 0) {
                $labelledOff[] = $name;
            }
            $labelledOn += $this->labels($on);
            $withIntents += $on->intents === [] ? 0 : 1;
        }

        $this->assertSame([], array_slice($moved, 0, 5), "{$cases} cases: the label moved something other than its own target");
        $this->assertSame([], array_slice($labelledOff, 0, 5), "{$cases} cases: a label target was emitted with the opt-in key UNSET");

        // THE CONTROL ON THE MATRIX ITSELF: a population that never labels, or never classifies
        // anything, would satisfy both assertions above while measuring nothing.
        $this->assertGreaterThan(0, $labelledOn, 'no case in the matrix emitted a label with the key set — the matrix cannot see the feature at all');
        $this->assertGreaterThan(0, $withIntents, 'no case in the matrix produced an intent — the matrix is not classifying coordination events');
    }

    public function test_the_matrix_covers_the_product_of_its_dimensions(): void
    {
        // The denominator, RE-DERIVED rather than recalled: the case list is the full product, and
        // every dimension carries more than one member (a dimension that collapsed to one would
        // shrink the population silently while every assertion above stayed green).
        $sizes = array_map('count', [
            $this->events(), $this->bodies(), $this->labelSets(), $this->titles(),
            $this->classifierConfigs(), $this->actors(),
        ]);

        $this->assertSame(array_product($sizes), iterator_count($this->cases()));
        $this->assertSame([], array_values(array_filter($sizes, static fn (int $n): bool => $n < 2)));
    }

    /** @return array<string, string> case name => the `classifier:` YAML the agent carries under it */
    private function classifierConfigs(): array
    {
        return [
            'plain' => '',
            'scope_author_map' => "  config:\n    scope_author_map:\n      ".self::REPO.": beta\n",
            'drop_title_all_of' => "  config:\n    drop_title_all_of:\n      - ['back-merge']\n",
            'inbox_stage' => "  config:\n    coord_non_addressed_disposition: inbox_stage\n",
            'extra_actions' => "  config:\n    coord_extra_actions:\n      issue_comment: [edited]\n",
            'narrow_wake' => "  config:\n    wake_membership: [to_me]\n",
            'wide_wake' => "  config:\n    wake_membership: [to_me, to_all, comment_to, bare_reply_to_own_thread, from_me]\n",
        ];
    }

    /** @return list<string> */
    private function events(): array
    {
        return ['issue_comment.created', 'issue_comment.edited', 'issues.opened', 'pull_request.opened'];
    }

    /** @return array<string, string> */
    private function bodies(): array
    {
        return [
            'bare' => 'a body with no addressing at all',
            'from' => "FROM: beta\n\nhello",
            'to_self' => "TO: alpha\n\nhello",
            'to_other' => "TO: gamma\n\nhello",
            'bridge_post' => PrCorrelationComment::MARKER_PREFIX."outcome=merged -->\n**report**",
            'empty' => '',
        ];
    }

    /** @return array<string, list<string>> */
    private function labelSets(): array
    {
        return [
            'none' => [],
            'to_self' => ['to:alpha'],
            'from_self' => ['from:alpha'],
            'to_all_from_other' => ['to:all', 'from:beta'],
        ];
    }

    /** @return array<string, string> */
    private function titles(): array
    {
        return [
            'query' => '[QUERY] a thread',
            'noise' => 'back-merge paper trail anchor',
            'empty' => '',
        ];
    }

    /** @return array<string, Actor> */
    private function actors(): array
    {
        return [
            'shared' => new Actor(id: '555'),                                  // the shared account: name null
            'named_self' => new Actor(id: '600', name: 'alpha', isKnownAgent: true),
            'named_other' => new Actor(id: '601', name: 'beta', isKnownAgent: true),
        ];
    }

    /**
     * Every case, as the product of the dimensions. A generator so the denominator is re-computed
     * per run and never held as a figure.
     *
     * @return \Generator<string, array{0: array{event: string, body: string, labels: list<string>, title: string, actor: Actor, config: string}, 1: AgentConfig}>
     */
    private function cases(): \Generator
    {
        foreach ($this->events() as $event) {
            foreach ($this->bodies() as $bodyName => $body) {
                foreach ($this->labelSets() as $labelName => $labels) {
                    foreach ($this->titles() as $titleName => $title) {
                        foreach (array_keys($this->classifierConfigs()) as $config) {
                            foreach ($this->actors() as $actorName => $actor) {
                                yield "{$event}|{$bodyName}|{$labelName}|{$titleName}|{$config}|{$actorName}" => [
                                    ['event' => $event, 'body' => $body, 'labels' => $labels, 'title' => $title, 'actor' => $actor, 'config' => $config],
                                    $this->agents[$config],
                                ];
                            }
                        }
                    }
                }
            }
        }
    }

    /** @param  array{event: string, body: string, labels: list<string>, title: string, actor: Actor, config: string}  $case */
    private function context(array $case, AgentConfig $agent): ClassifyContext
    {
        [$type, $action] = explode('.', $case['event']);
        $subject = [
            'number' => 42,
            'title' => $case['title'],
            'body' => $case['body'],
            'labels' => array_map(static fn (string $n): array => ['name' => $n], $case['labels']),
            'html_url' => 'https://github.com/'.self::REPO.'/issues/42',
        ];
        $payload = ['action' => $action, 'repository' => ['full_name' => self::REPO], 'sender' => ['id' => 555]];
        $payload[$type === 'pull_request' ? 'pull_request' : 'issue'] = $subject;
        if ($type === 'issue_comment') {
            $payload['comment'] = [
                'id' => 9001,
                'body' => $case['body'],
                'html_url' => 'https://github.com/'.self::REPO.'/issues/42#issuecomment-9001',
                'created_at' => '2026-09-22T00:00:00Z',
            ];
            $payload['issue']['body'] = 'the opening post';
        }

        return new ClassifyContext($case['event'], $payload, $case['actor'], 'github', self::REPO, $agent);
    }

    private function labels(ClassifyResult $result): int
    {
        return count(array_filter($result->targets, static fn (ReactionTarget $t): bool => $t->handler === ProtocolInvalidLabeler::HANDLER));
    }

    /** Everything a classification decides, as bytes. */
    private function render(ClassifyResult $result, bool $stripLabel = false): string
    {
        $targets = array_filter(
            $result->targets,
            fn (ReactionTarget $t): bool => ! $stripLabel || $t->handler !== ProtocolInvalidLabeler::HANDLER,
        );

        return json_encode([
            'intents' => array_map(static fn (Intent $i): array => $i->toArray(), $result->intents),
            'targets' => array_map(
                static fn (ReactionTarget $t): array => [$t->handler, $t->targetId, $t->debounceKey, $t->debounceSeconds, $t->payload],
                array_values($targets),
            ),
            'reattributed' => $result->reattributedActor === null ? null : [
                $result->reattributedActor->id,
                $result->reattributedActor->name,
                $result->reattributedActor->isKnownAgent,
            ],
        ], JSON_THROW_ON_ERROR);
    }
}
