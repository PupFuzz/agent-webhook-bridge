<?php

namespace Tests\Unit\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Checks\EventFollowsConsumerCheck;
use App\Bridge\Check\EventConsumers\EventConsumerReconciler;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use App\Models\WebhookEvent;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * `event.follows_consumer` (card#4183 / DL-196), migrated whole in DL-242 stage 7a.
 *
 * IT COVERS THE CHECK'S RENDERING AND THE RECONCILER FEEDING IT, as one composition —
 * see {@see self::findings()}. DL-249 stage 9 moved the derivation into
 * {@see EventConsumerReconciler} so a second front door could read it; the DATA that
 * front door emits is asserted separately in `EventConsumerReconcilerTest`, and what
 * stays here is every claim about the operator-facing sentences.
 *
 * THE UNIT TESTS ARE THE WHOLE PROOF FOR THREE OF THIS CHECK'S FOUR MESSAGE SHAPES.
 * Measured from the rendered golden files, exactly one of the 33 fixtures prints an
 * `event-consumer:` line — `event-consumer-unconsumed-type`, which renders the
 * unconsumed-type warn for one type, one agent, with a pinned last-seen. The action
 * inventory, the undeclared-classifier warn, and the `unvalidated` skip line have NO
 * golden coverage at all. (`event-consumer-nothing-arrived` prints no line by design: it
 * is an ABSENCE assertion — a scope with consumers but no arrived events stays silent —
 * and it is invisible to a grep for the prefix. It is the fixture NAMED for that path,
 * not the only one taking it: measured from the BUILDERS, because a rendered file cannot
 * witness a silent path, 8 fixtures boot a github-subscribed agent and 7 of them have no
 * arrived events.)
 *
 * Nor does `docs/check-golden-coverage.md` speak for any of it in either direction:
 * that instrument walks `handle()` only, so neither this check's predicates nor the
 * method's before it were ever enumerated there. Absence from that file is not
 * evidence of protection.
 *
 * TWO FORMAT ARMS ARE DELIBERATELY NOT ASSERTED HERE, and the reason is that they
 * cannot be reached, not that they were missed: both `unknown` renderings (the
 * inventory's and the warn's) require `last_seen` to arrive non-scalar or empty, and
 * `received_at` is `timestamp(3)` NOT NULL with a DB-side default, so `MAX(received_at)`
 * over a non-empty group is always a scalar timestamp string. They are inherited
 * unchanged by this migration (output-neutral); filed as card#5555 rather than tested or
 * deleted here, because deleting them changes no output only BECAUSE they never fire —
 * which is exactly the kind of edit a byte-identical migration stage must not smuggle in.
 */
class EventFollowsConsumerCheckTest extends TestCase
{
    use RefreshDatabase;

    private const SCOPE = 'owner/repo';

    private const PINNED = '2026-01-01 00:00:00';

    public function test_a_scope_map_that_is_empty_yields_nothing(): void
    {
        // The reason no install without github subscriptions pays for a query at all:
        // the reconciler has nothing to walk, so the renderer has nothing to say.
        $this->assertSame([], $this->findings(new CheckContext));
    }

    public function test_a_context_whose_reconciliation_never_ran_is_silent_rather_than_clean(): void
    {
        // DL-249 stage 9's one new arm, and the ONLY test that hands the check a context
        // `CheckCommand` cannot produce — it publishes the reconciliation unconditionally.
        // It exists because the field is nullable and null is not an empty reconciliation:
        // an empty one is "no scope had anything to say", null is "nobody asked". Both
        // render nothing here, and asserting that is what keeps a future edit from making
        // null mean the FIRST of those.
        $ctx = new CheckContext;
        $ctx->githubScopeConsumers = [self::SCOPE => [$this->consumer('wb', consumed: [])]];
        $this->arrived('issues.closed');

        $this->assertNull($ctx->eventConsumers);
        $this->assertSame([], iterator_to_array((new EventFollowsConsumerCheck)->run($ctx), false));
    }

    public function test_a_scope_where_nothing_arrived_is_silent(): void
    {
        // Nothing arrived ⇒ nothing was dropped. This is the DISCRIMINATING CONTROL for
        // every positive case below: it is the same consumer map, the same query, and
        // the same code path up to the point where the observed set decides.
        $ctx = $this->context([$this->consumer('wb', consumed: [])]);

        $this->assertSame([], $this->findings($ctx));
    }

    public function test_an_arrived_type_no_classifier_consumes_warns_with_its_count_and_last_seen(): void
    {
        $this->arrived('issues.closed');
        $this->arrived('issues.opened');
        $ctx = $this->context([$this->consumer('wb', consumed: ['pull_request'])]);

        $findings = $this->findings($ctx);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Warn, $findings[0]->severity);
        $this->assertSame(
            "event-consumer: github:owner/repo has received 'issues' (2x, last ".self::PINNED.' UTC)'
                .' but no enabled classifier consumes it — the event is silently dropped on arrival'
                .' (agent(s) subscribed: wb). A last-seen predating your subscription fix is remediated'
                ." history, not live drift. Add a consuming family, or drop 'issues' from the"
                .' subscription via coord:setup-bridge.',
            $findings[0]->message,
        );
    }

    public function test_the_count_sums_every_action_of_one_top_level_type(): void
    {
        // The top-level projection is what the WARN counts, so two actions of one type
        // are ONE warn carrying their SUM — not two warns, and not the last group's count.
        $this->arrived('issues.closed');
        $this->arrived('issues.closed');
        $this->arrived('issues.opened');
        $ctx = $this->context([$this->consumer('wb', consumed: [])]);

        $findings = $this->findings($ctx);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString("'issues' (3x,", $findings[0]->message);
    }

    public function test_a_qualified_declaration_covers_its_whole_top_level_type_for_the_warn_compare(): void
    {
        // `issues.opened` PROJECTS to `issues`, so an arrived `issues.closed` is NOT
        // unconsumed — the type is owned, the action is not, which is the inventory's
        // job below and never the warn's. Reverting the projection turns every
        // qualified-declaring install into a false unconsumed WARN.
        $this->arrived('issues.closed');
        $ctx = $this->context([$this->consumer('wb', consumed: ['issues.opened'])]);

        $findings = $this->findings($ctx);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Ok, $findings[0]->severity);
        $this->assertStringNotContainsString('no enabled classifier consumes it', $findings[0]->message);
    }

    public function test_the_action_inventory_names_the_unlisted_actions_of_a_qualified_only_type(): void
    {
        $this->arrived('issues.closed');
        $ctx = $this->context([$this->consumer('wb', consumed: ['issues.opened'])]);

        $findings = $this->findings($ctx);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Ok, $findings[0]->severity);
        $this->assertSame(
            "event-consumer: github:owner/repo 'issues' actions observed but not action-declared"
                .' by any family: closed (1x, last '.self::PINNED.' UTC)'
                .' — arrived-and-dropped at the action level (informational; the type itself is consumed).',
            $findings[0]->message,
        );
    }

    public function test_a_bare_declaration_of_the_type_suppresses_the_action_inventory(): void
    {
        // A bare `issues` means the family OWNS the type — every action is covered, so
        // an inventory line would be noise about actions that are in fact consumed.
        $this->arrived('issues.closed');
        $ctx = $this->context([$this->consumer('wb', consumed: ['issues'])]);

        $this->assertSame([], $this->findings($ctx));
    }

    public function test_a_declared_action_is_not_inventoried_and_an_undeclared_sibling_is(): void
    {
        $this->arrived('issues.opened');
        $this->arrived('issues.closed');
        $ctx = $this->context([$this->consumer('wb', consumed: ['issues.opened'])]);

        $findings = $this->findings($ctx);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('closed (1x,', $findings[0]->message);
        $this->assertStringNotContainsString('opened (', $findings[0]->message);
    }

    public function test_a_type_whose_every_arrived_action_is_declared_is_silent(): void
    {
        // The inventory's own discriminating control: qualified-only coverage that is
        // COMPLETE prints nothing. Without it, "the inventory fires for qualified-only
        // types" would be indistinguishable from "the inventory fires for every
        // qualified-only type, listing whatever it finds".
        $this->arrived('issues.opened');
        $ctx = $this->context([$this->consumer('wb', consumed: ['issues.opened'])]);

        $this->assertSame([], $this->findings($ctx));
    }

    public function test_the_inventory_orders_actions_by_descending_count(): void
    {
        // `uasort` by count desc: the operator reads the loudest drop first. Insertion
        // order here is deliberately the OPPOSITE of the expected order, so a dropped
        // sort is visible rather than coincidentally correct.
        $this->arrived('issues.labeled');
        $this->arrived('issues.closed');
        $this->arrived('issues.closed');
        $this->arrived('issues.closed');
        $this->arrived('issues.assigned');
        $this->arrived('issues.assigned');
        $ctx = $this->context([$this->consumer('wb', consumed: ['issues.opened'])]);

        $findings = $this->findings($ctx);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString(
            'closed (3x, last '.self::PINNED.' UTC), assigned (2x, last '.self::PINNED
                .' UTC), labeled (1x, last '.self::PINNED.' UTC)',
            $findings[0]->message,
        );
    }

    public function test_an_actionless_type_never_enters_the_action_inventory(): void
    {
        // `push` has no action to compare, so it cannot be "observed but not
        // action-declared" — and the guard for that is the arrived side (no `.action`
        // suffix ⇒ no inventory row), NOT the bare-declaration rule. The declaration
        // here is deliberately QUALIFIED so the bare rule cannot be what produces the
        // silence: only the actionless guard can.
        $this->arrived('push');
        $ctx = $this->context([$this->consumer('wb', consumed: ['push.x'])]);

        $this->assertSame([], $this->findings($ctx));
    }

    public function test_the_consumed_set_is_the_union_across_every_agent_on_the_scope(): void
    {
        // The AIMLA case: two agents subscribe one scope and the bridge dispatches to
        // both, so neither agent's set alone decides. Per-agent evaluation would warn
        // that `pull_request` is dropped while the second agent consumes it.
        $this->arrived('issues.closed');
        $this->arrived('pull_request.opened');
        $ctx = $this->context([
            $this->consumer('wb', consumed: ['issues']),
            $this->consumer('coord', consumed: ['pull_request']),
        ]);

        $this->assertSame([], $this->findings($ctx));
    }

    public function test_the_warn_names_every_subscribed_agent_once(): void
    {
        $this->arrived('release.published');
        $ctx = $this->context([
            $this->consumer('wb', consumed: ['issues']),
            $this->consumer('coord', consumed: ['issues']),
        ]);

        $findings = $this->findings($ctx);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('(agent(s) subscribed: wb, coord)', $findings[0]->message);
    }

    public function test_an_undeclared_classifier_warns_before_the_unconsumed_warns_it_qualifies(): void
    {
        // sola's #22: an undeclared classifier MIGHT consume the type without saying so,
        // so the warn below it may be a false positive. Order is part of the contract —
        // the caveat has to precede what it qualifies to be read as qualifying it.
        $this->arrived('issues.closed');
        $ctx = $this->context([$this->consumer('wb', consumed: [], declared: false)]);

        $findings = $this->findings($ctx);

        $this->assertCount(2, $findings);
        $this->assertSame(Severity::Warn, $findings[0]->severity);
        $this->assertSame(
            'event-consumer: scope github:owner/repo has an enabled classifier'
                .' App\Bridge\Classifiers\Custom (agent wb) that does not declare its consumed events'
                .' (App\Bridge\Contracts\DeclaresConsumedEvents) — the following unconsumed-event'
                .' WARNING(s) MAY be a false positive if that classifier actually consumes them',
            $findings[0]->message,
        );
        $this->assertStringContainsString('no enabled classifier consumes it', $findings[1]->message);
    }

    public function test_the_inventory_carries_the_undeclared_caveat_only_when_one_is_on_the_scope(): void
    {
        // Same install twice, differing ONLY in the second consumer's `declared` flag:
        // the caveat is the whole delta, so its absence in the first case is a verdict.
        $this->arrived('issues.closed');
        $declaring = $this->context([
            $this->consumer('wb', consumed: ['issues.opened']),
            $this->consumer('coord', consumed: ['issues.opened']),
        ]);
        $undeclaring = $this->context([
            $this->consumer('wb', consumed: ['issues.opened']),
            $this->consumer('coord', consumed: ['issues.opened'], declared: false),
        ]);

        $withoutCaveat = $this->findings($declaring);
        $withCaveat = $this->findings($undeclaring);

        $this->assertCount(1, $withoutCaveat);
        $this->assertStringEndsWith('the type itself is consumed).', $withoutCaveat[0]->message);
        $this->assertCount(1, $withCaveat);
        $this->assertStringEndsWith(
            'the type itself is consumed). An undeclared classifier on this scope may consume'
                .' some of these (possible false inventory).',
            $withCaveat[0]->message,
        );
    }

    public function test_the_last_seen_is_trimmed_to_seconds_so_both_drivers_agree(): void
    {
        // `received_at` is timestamp(3): MariaDB's MAX() returns the fractional part and
        // SQLite returns the stored string, so the rendered line would differ BY DRIVER
        // without the 19-char trim — and the golden corpus, captured on SQLite, would
        // pin a string the MariaDB matrix cannot produce.
        $this->arrived('issues.closed');
        DB::table('webhook_events')->update(['received_at' => self::PINNED.'.123']);
        $ctx = $this->context([$this->consumer('wb', consumed: [])]);

        $findings = $this->findings($ctx);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('last '.self::PINNED.' UTC)', $findings[0]->message);
        $this->assertStringNotContainsString('.123', $findings[0]->message);
    }

    public function test_a_row_with_an_empty_event_type_is_skipped_rather_than_warned_about(): void
    {
        // `event_type` is NOT NULL but an empty string is storable, and it would
        // otherwise become an observed top-level type of `''` — a warn naming no event
        // at all, which no operator can act on.
        $this->arrived('');
        $ctx = $this->context([$this->consumer('wb', consumed: [])]);

        $this->assertSame([], $this->findings($ctx));
    }

    public function test_a_query_failure_yields_unvalidated_after_the_findings_already_produced(): void
    {
        // THE STAGE-3b CONSTRAINT, ASSERTED RATHER THAN ASSUMED. CheckRunner materializes
        // a check's findings before the caller renders any of them, so a catch left in
        // the CALLER would discard the scopes this check got through before the failure —
        // where the inline code it replaced had already PRINTED them. A test asserting
        // only the `unvalidated` line passes under that wrong placement too; the PAIRING
        // is what distinguishes the two.
        //
        // The throw is INJECTED (a listener on the second `webhook_events` read) because
        // the failure this catch exists for — a DB hiccup mid-iteration — has no natural
        // trigger in a test DB. What is under test is the catch's PLACEMENT, and the
        // injection fires at the real call site, inside the loop, after scope A's
        // findings exist.
        $this->arrived('issues.closed', 'owner/a');
        $this->arrived('issues.closed', 'owner/b');
        $ctx = new CheckContext;
        $ctx->githubScopeConsumers = [
            'owner/a' => [$this->consumer('wb', consumed: [])],
            'owner/b' => [$this->consumer('wb', consumed: [])],
        ];
        $reads = 0;
        DB::listen(function (QueryExecuted $query) use (&$reads) {
            if (str_contains($query->sql, 'webhook_events') && ++$reads === 2) {
                throw new RuntimeException('db hiccup');
            }
        });

        $findings = $this->findings($ctx);

        $this->assertCount(2, $findings);
        $this->assertSame(Severity::Warn, $findings[0]->severity);
        $this->assertStringContainsString('github:owner/a has received', $findings[0]->message);
        $this->assertSame(Severity::Unvalidated, $findings[1]->severity);
        $this->assertSame('event-consumer: check skipped — db hiccup', $findings[1]->message);
    }

    /**
     * @param  list<array{agent: string, class: string, consumed: list<string>, declared: bool}>  $consumers
     */
    private function context(array $consumers): CheckContext
    {
        $ctx = new CheckContext;
        $ctx->githubScopeConsumers = [self::SCOPE => $consumers];

        return $ctx;
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
        // `received_at` is not fillable (DB-defaulted to the insert clock) and it is
        // RENDERED, so leaving it at the clock would make every message assertion here
        // depend on the minute the suite ran in.
        $event->forceFill(['received_at' => self::PINNED])->save();
    }

    /**
     * Drive the same two halves `CheckCommand::handle()` does: reconcile, publish on the
     * context, then render.
     *
     * DL-249 stage 9 split the derivation out ({@see EventConsumerReconciler}), and this
     * helper deliberately drives BOTH rather than handing the check a pre-built
     * reconciliation. Every assertion in this class is about what the operator reads, and
     * a renderer fed synthetic input proves the sentences while saying nothing about
     * whether the query still produces what they describe — the two halves have to
     * compose, and this is the only place that is checked end to end.
     *
     * @return list<Finding>
     */
    private function findings(CheckContext $ctx): array
    {
        $ctx->eventConsumers = (new EventConsumerReconciler)->reconcile($ctx->githubScopeConsumers);

        return iterator_to_array((new EventFollowsConsumerCheck)->run($ctx), false);
    }
}
