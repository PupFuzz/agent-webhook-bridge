<?php

namespace Tests\Unit\Bridge\Check\EventConsumers;

use App\Bridge\Check\EventConsumers\EventConsumerReconciler;
use App\Bridge\Check\EventConsumers\EventConsumerReconciliation;
use App\Models\WebhookEvent;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * The observed-vs-consumed reconciliation as DATA (card#5229 / DL-249 stage 9).
 *
 * WHY THIS IS A SEPARATE CLASS FROM `EventFollowsConsumerCheckTest`, when the two drive
 * the same code: that one asserts the SENTENCES an operator reads, and every claim in it
 * is satisfied by a reconciliation that computes only what those sentences need. The
 * fields below are the ones a MACHINE consumer reads, and three of them are true of a
 * scope the check is deliberately silent about — so no assertion phrased in terms of
 * findings can reach them. That gap is the whole reason card#5229 exists: the data and
 * the prose are not the same surface, and a test suite that only checks the prose lets
 * the data rot.
 */
class EventConsumerReconcilerTest extends TestCase
{
    use RefreshDatabase;

    private const SCOPE = 'owner/repo';

    private const PINNED = '2026-01-01 00:00:00';

    public function test_the_declaration_half_is_computed_for_a_scope_where_nothing_arrived(): void
    {
        // THE PROPERTY THE PROSE CANNOT WITNESS, and the one leg (c) reads. The check
        // stays silent on a scope with no arrivals — correctly: nothing was dropped — so
        // if the reconciler skipped the declaration walk in that case, every finding
        // assertion would still pass and the JSON surface would answer "this scope
        // consumes nothing" for a fully-configured install.
        //
        // It is also the half `observed` STRUCTURALLY cannot supply: an event nobody
        // subscribed to never arrives, so no diff over arrivals can name it.
        $result = $this->reconcile([$this->consumer('wb', consumed: ['pull_request', 'issues.opened'])]);

        $scope = $result->scopes[0];
        $this->assertSame([], $scope->observed);
        $this->assertSame(['pull_request', 'issues'], $scope->consumed);
        $this->assertSame(['pull_request'], $scope->bare);
        $this->assertSame(['issues' => ['opened']], $scope->qualified);
        $this->assertSame(['wb'], $scope->agents);
    }

    public function test_bare_and_qualified_are_separate_facts_about_the_same_type(): void
    {
        // THE TIER, which is the thing card#5229 forbids flattening. `consumed` alone
        // cannot express it: `issues` appears there whether one consumer owns the whole
        // type or another merely handles one of its actions, and a consumer that read
        // only `consumed` would report every unlisted action as a drop.
        $result = $this->reconcile([
            $this->consumer('wb', consumed: ['issues.opened']),
            $this->consumer('coord', consumed: ['issues']),
        ]);

        $scope = $result->scopes[0];
        $this->assertSame(['issues'], $scope->consumed);
        $this->assertSame(['issues'], $scope->bare);
        $this->assertSame(['issues' => ['opened']], $scope->qualified);
    }

    public function test_a_bare_declaration_keeps_every_arrived_action_out_of_the_unlisted_set(): void
    {
        $this->arrived('issues.labeled');
        $this->arrived('issues.edited');

        $bareOwned = $this->reconcile([$this->consumer('wb', consumed: ['issues'])])->scopes[0];
        $qualifiedOnly = $this->reconcile([$this->consumer('wb', consumed: ['issues.opened'])])->scopes[0];

        // Same arrivals, same type, opposite answers — the delta IS the tier.
        $this->assertSame([], $bareOwned->unlistedActions());
        // MEMBERSHIP, sorted: these two arrivals tie on count, so their order is the DB's
        // group order and asserting it here would pin a driver detail this test is not
        // about. Descending-count ordering has its own test on the rendering side.
        $unlisted = array_keys($qualifiedOnly->unlistedActions()['issues']);
        sort($unlisted);
        $this->assertSame(['edited', 'labeled'], $unlisted);
        // And neither is an alarm: the type is consumed either way.
        $this->assertSame([], $bareOwned->unconsumed());
        $this->assertSame([], $qualifiedOnly->unconsumed());
    }

    public function test_undeclared_carries_the_agent_and_class_separately_rather_than_a_rendered_string(): void
    {
        // The disclosure the card calls load-bearing. It is structured so a consumer can
        // mark the row ADVISORY without re-deriving the fact from a sentence; the
        // rendered `Class (agent x)` form belongs to the check, and lives only there.
        $result = $this->reconcile([
            $this->consumer('wb', consumed: [], declared: false),
            $this->consumer('coord', consumed: ['issues']),
        ]);

        $this->assertSame(
            [['agent' => 'wb', 'class' => 'App\Bridge\Classifiers\Custom']],
            $result->scopes[0]->undeclared,
        );
    }

    public function test_unconsumed_is_the_warn_population_as_data(): void
    {
        // The motivating defect, closed: this is the set a downstream parser used to have
        // to recover by matching "but no enabled classifier consumes it" — a sentence
        // DL-236 already reworded once in this command.
        $this->arrived('issues.closed');
        $this->arrived('release.published');

        $scope = $this->reconcile([$this->consumer('wb', consumed: ['issues'])])->scopes[0];

        $this->assertSame(['release'], $scope->unconsumed());
        $this->assertSame(['count' => 1, 'last' => self::PINNED], $scope->observed['release']);
    }

    public function test_a_failure_truncates_the_walk_and_is_carried_as_a_value(): void
    {
        // FAIL-SOFT WITHOUT LOSING EITHER FACT. The scopes already reconciled survive
        // (the check owes their findings), the failure is reported (an empty result is
        // not a clean install), and nothing throws out of here — an exception crossing
        // this boundary would discard the completed scopes, because CheckRunner
        // materializes a check's findings before anything renders them.
        $this->arrived('issues.closed', 'owner/a');
        $this->arrived('issues.closed', 'owner/b');
        $reads = 0;
        DB::listen(function (QueryExecuted $query) use (&$reads) {
            if (str_contains($query->sql, 'webhook_events') && ++$reads === 2) {
                throw new RuntimeException('db hiccup');
            }
        });

        $result = (new EventConsumerReconciler)->reconcile([
            'owner/a' => [$this->consumer('wb', consumed: [])],
            'owner/b' => [$this->consumer('wb', consumed: [])],
        ]);

        $this->assertSame('db hiccup', $result->error);
        $this->assertCount(1, $result->scopes);
        $this->assertSame('owner/a', $result->scopes[0]->scope);
    }

    public function test_a_clean_walk_reports_no_error_so_an_empty_result_is_readable(): void
    {
        // The discriminating control for the case above: without it, `error` could be set
        // unconditionally and every assertion there would still pass, leaving a consumer
        // unable to tell a failed measurement from a clean one — which is the ONLY thing
        // the field exists to let them do.
        $this->assertNull($this->reconcile([$this->consumer('wb', consumed: [])])->error);
        $this->assertNull((new EventConsumerReconciler)->reconcile([])->error);
    }

    /**
     * @param  list<array{agent: string, class: string, consumed: list<string>, declared: bool}>  $consumers
     */
    private function reconcile(array $consumers): EventConsumerReconciliation
    {
        return (new EventConsumerReconciler)->reconcile([self::SCOPE => $consumers]);
    }

    /**
     * @param  list<string>  $consumed
     * @return array{agent: string, class: string, consumed: list<string>, declared: bool}
     */
    private function consumer(string $agent, array $consumed, bool $declared = true): array
    {
        return [
            'agent' => $agent,
            'class' => 'App\Bridge\Classifiers\Custom',
            'consumed' => $consumed,
            'declared' => $declared,
        ];
    }

    private function arrived(string $eventType, string $scope = self::SCOPE): void
    {
        $event = WebhookEvent::create([
            'delivery_id' => uniqid('d-', true),
            'provider' => 'github',
            'scope_id' => $scope,
            'event_type' => $eventType,
            'payload' => ['x' => 1],
        ]);
        // `received_at` is not fillable (DB-defaulted to the insert clock) and it is part
        // of the data under assertion, so leaving it at the clock would make these
        // assertions depend on the minute the suite ran in.
        $event->forceFill(['received_at' => self::PINNED])->save();
    }
}
