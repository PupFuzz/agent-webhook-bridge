<?php

namespace App\Bridge\Check;

use App\Bridge\Check\EventConsumers\EventConsumerReconciliation;
use App\Bridge\Check\EventConsumers\EventConsumerScope;
use App\Bridge\Support\Finding;
use JsonException;

/**
 * `bridge:check --format=json` — the run as DATA (card#5229, DL-249 stage 9).
 *
 * THE SECOND RENDERER OVER THE SAME REPORT, which is the split stage 8 built the seam
 * for: `CheckCommand`'s text methods own the operator's sentences, this owns the machine
 * document, and both read the same {@see CheckInventory} + {@see CheckResult}s.
 * {@see CheckInventory} carries no prose precisely so that neither renderer's voice ends
 * up inside the other.
 *
 * IT IS A WRITE CONTRACT, WHICH IS WHY THE STAGE WAS GATED. Once a machine consumer
 * parses this you cannot un-ship it, so the document carries {@see self::SCHEMA_VERSION}
 * and `CheckJsonContractTest` pins the exact key set at every level — a key added,
 * renamed or dropped reds there before it reaches a consumer. The version is the
 * consumer's guard; the test is ours.
 *
 * WHY THE DOCUMENT CARRIES `findings_outside_registry` AT ALL, since a registry-shaped
 * document would be tidier: four fail-soft envelopes in `CheckCommand::handle()` print
 * findings that belong to no registered check — a malformed agent YAML, an unloadable
 * `writeback.json`, and two client-construction failures. Two of them set the exit code.
 * Omitting them would produce a document whose `checks` are all clean and whose `ok` is
 * false, with nothing in it explaining why: a machine-readable false clean, which is the
 * exact defect this program exists to remove.
 *
 * WHAT IT DOES NOT PROMISE, stated because an unstated bound reads as a guarantee:
 *  - The `message` strings are OPERATOR PROSE and are NOT part of the contract. They are
 *    carried so a document is diagnosable by a human, and they have been reworded before
 *    (DL-236) and will be again. A consumer keying on message text has re-created the
 *    coupling this surface exists to break.
 *  - `checks[].disposition` inherits {@see CheckInventory}'s bounds verbatim — it cannot
 *    see a leg nobody wrote as a check, a `not_run_reason` is the envelope's claim about
 *    itself, and a per-agent check that ran for two of three agents is one entry.
 *  - `event_consumers.scopes[].observed` is bounded to events that were SUBSCRIBED and
 *    DELIVERED; it can never show an event the install is not subscribed to.
 */
final class CheckJsonRenderer
{
    /**
     * The document's shape version.
     *
     * BUMP IT WHEN THE SHAPE CHANGES IN A WAY A CONSUMER CAN SEE — a removed or renamed
     * key, a changed type, a changed meaning. An ADDED key is deliberately not a bump:
     * consumers are told to ignore unknown keys, and versioning additive growth would
     * make the number churn until it means nothing.
     */
    public const SCHEMA_VERSION = 1;

    /**
     * The document, as a value — the shape `CheckJsonContractTest` asserts against
     * without going through an encoder.
     *
     * @param  bool  $ok  the run's verdict, the SAME variable that decides the exit code
     * @param  list<CheckResult>  $results
     * @param  list<Finding>  $unattributed  findings from `handle()`'s fail-soft envelopes, which belong to no registered check
     * @return array<string, mixed>
     */
    public function document(
        bool $ok,
        array $results,
        CheckInventory $inventory,
        array $unattributed,
        EventConsumerReconciliation $eventConsumers,
        AgentScopeCoverage $agentScopeCoverage,
    ): array {
        return [
            'schema' => self::SCHEMA_VERSION,
            'ok' => $ok,
            'agent_scope_coverage' => $this->agentScopeCoverage($agentScopeCoverage),
            'checks' => $this->checks($results, $inventory),
            'findings_outside_registry' => array_map(
                fn (Finding $f): array => $this->finding($f, null),
                $unattributed,
            ),
            'inventory' => $this->inventory($inventory),
            'event_consumers' => $this->eventConsumers($eventConsumers),
        ];
    }

    /**
     * The document, encoded.
     *
     * PRETTY-PRINTED ON PURPOSE, and the reason is DIFFABILITY, not any committed
     * artifact — nothing under `tests/` stores one of these; the guard builds its
     * documents by running the command. A real install's document runs to thousands of
     * bytes, and the thing an operator actually does with two of them is `diff` them
     * (before/after an upgrade, or a healthy seat against a sick one). On one line that
     * diff is the whole document. `JSON_UNESCAPED_SLASHES` serves the same end: every
     * install path in here is otherwise rendered unreadable.
     *
     * ENCODING IS TOTAL, and the two flags are what make it so rather than a hope.
     * `JSON_INVALID_UTF8_SUBSTITUTE` removes the only reachable failure — an exception
     * message carrying invalid UTF-8 reaches this through a fail-soft envelope — leaving
     * `JSON_THROW_ON_ERROR`'s {@see JsonException} unreachable for a fixed-depth array of
     * scalars. It is set anyway, because the alternative return type is `false`, and a
     * `false` silently rendered as an empty line is a worse failure than a stack trace.
     *
     * @param  list<CheckResult>  $results
     * @param  list<Finding>  $unattributed
     */
    public function encode(
        bool $ok,
        array $results,
        CheckInventory $inventory,
        array $unattributed,
        EventConsumerReconciliation $eventConsumers,
        AgentScopeCoverage $agentScopeCoverage,
    ): string {
        return json_encode(
            $this->document($ok, $results, $inventory, $unattributed, $eventConsumers, $agentScopeCoverage),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Every REGISTERED check, in registration order, whether or not it ran.
     *
     * DRIVEN BY THE INVENTORY, NOT BY THE RESULTS, which is the same direction stage 8
     * chose for exactly the same reason: results exist only for checks that ran, so a
     * results-driven list silently omits the 13-of-37 that never got to look. Walking the
     * registration list makes a check that produced nothing a visible row with a
     * disposition rather than an absence.
     *
     * `not_run_reason` IS GATED ON THE DISPOSITION, and the raw map is not the same thing.
     * {@see CheckRunner::noteNotRun()} attaches a reason to every check in a slot without
     * knowing whether that slot will go on to run — a per-agent check whose slot was noted
     * for the agents that aborted, then ran for one that did not, carries a reason AND
     * reports. Emitting the raw entry there puts *"every parsed agent aborted before this
     * leg"* beside `"disposition": "reported"` and a finding, which is a document
     * contradicting itself — and a consumer keying on a non-null reason to mean *did not
     * run* would be precisely wrong on it. **The disposition is the authority; the reason
     * only ever explains it.** {@see CheckInventory::notRunReasons()} and
     * {@see CheckInventory::unexplainedNotRun()} already filter this way; this was the one
     * reader that did not.
     *
     * @param  list<CheckResult>  $results
     * @return list<array<string, mixed>>
     */
    private function checks(array $results, CheckInventory $inventory): array
    {
        /** @var array<string, list<array<string, mixed>>> $findingsById */
        $findingsById = [];
        foreach ($results as $result) {
            foreach ($result->findings as $finding) {
                $findingsById[$result->id][] = $this->finding($finding, $result->agent);
            }
        }

        $out = [];
        foreach ($inventory->dispositions as $id => $disposition) {
            $out[] = [
                'id' => $id,
                'disposition' => $disposition->value,
                'not_run_reason' => $disposition === CheckDisposition::NotRun
                    ? $inventory->notRunReasons[$id] ?? null
                    : null,
                'findings' => $findingsById[$id] ?? [],
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function finding(Finding $finding, ?string $agent): array
    {
        return [
            'severity' => $finding->severity->value,
            'agent' => $agent,
            'message' => $finding->message,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function inventory(CheckInventory $inventory): array
    {
        return [
            'registered' => $inventory->registered(),
            'ran' => $inventory->ran(),
            'reported' => $inventory->count(CheckDisposition::Reported),
            'silent' => $inventory->count(CheckDisposition::Silent),
            'not_requested' => $inventory->count(CheckDisposition::NotRequested),
            'not_run' => $inventory->count(CheckDisposition::NotRun),
            'not_run_reasons' => $inventory->notRunReasons(),
            'unexplained_not_run' => $inventory->unexplainedNotRun(),
        ];
    }

    /**
     * Which agents this run never finished reading (card#5698).
     *
     * TOP-LEVEL, NOT NESTED UNDER `event_consumers`, because the fact is about the RUN and
     * not about that one derivation: the same gap short-changes the writeback orphan and
     * coord-card-move legs, whose `checks[]` findings carry it as prose. A consumer reading
     * `event_consumers.scopes[].unconsumed` — the one derived value the gap can inflate —
     * must read `complete` first, exactly as it must read `event_consumers.error` first.
     *
     * `scopes` IS NULL, NEVER `[]`, WHERE THE AGENT'S CONFIG DID NOT PARSE. An empty list
     * is the real answer for an agent with no github subscription, and collapsing the two
     * would tell a consumer that a run which knows nothing knows the scope set is empty.
     *
     * @return array<string, mixed>
     */
    private function agentScopeCoverage(AgentScopeCoverage $coverage): array
    {
        return [
            // FIRST, and the only key a consumer must read: an `unread_agents` it does not
            // look at is a gap it has silently treated as measured.
            'complete' => $coverage->isComplete(),
            'unread_agents' => array_map(
                static fn (array $entry): array => [
                    'agent' => $entry['agent'],
                    'github_scopes' => $entry['scopes'],
                ],
                $coverage->unreadAgents(),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function eventConsumers(EventConsumerReconciliation $reconciliation): array
    {
        return [
            // FIRST, and non-nullable: an empty `scopes` means "nothing to report" only
            // when this is null. A consumer that reads the list without reading this has
            // turned a failed measurement into a clean bill of health.
            'error' => $reconciliation->error,
            'scopes' => array_map($this->scope(...), $reconciliation->scopes),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function scope(EventConsumerScope $scope): array
    {
        return [
            'scope' => $scope->scope,
            'agents' => $scope->agents,
            // Every possibly-empty MAP is cast to an object, because PHP's empty array
            // encodes as `[]` and a consumer indexing it would then have to handle two
            // types for one field. The nested maps below cannot be empty by construction
            // (a key exists only because it has an entry), so they are left alone.
            'observed' => (object) $scope->observed,
            'observed_actions' => (object) $scope->observedActions,
            'consumed' => $scope->consumed,
            'bare' => $scope->bare,
            'qualified' => (object) $scope->qualified,
            'undeclared' => $scope->undeclared,
            // SEPARATE FROM `undeclared`, NEVER FOLDED INTO IT (card#5698): these
            // classifiers DO declare, and this run could not read what. A consumer that
            // unions the two is back to the conflation the field exists to remove — and
            // like `agent_scope_coverage.complete`, a non-empty `unreadable` is a bound on
            // `unconsumed`, which can only be over-inclusive while it is non-empty.
            'unreadable' => $scope->unreadable,
            // DERIVED, and emitted rather than left to the consumer: these two ARE what
            // the WARN and INFO lines say, and the whole point of the surface is that a
            // consumer never re-implements the projection rule to get them. Both come
            // from the same object in the same pass as the operands above, so they cannot
            // drift from them.
            'unconsumed' => $scope->unconsumed(),
            'unlisted_actions' => (object) $scope->unlistedActions(),
        ];
    }
}
