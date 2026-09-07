<?php

namespace Tests\Feature\Console\Check;

use App\Bridge\Check\NextSteps;
use App\Console\Commands\Bridge\CheckCommand;
use App\Models\BoardToolsClientCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Support\CheckGolden\BootsGoldenInstall;
use Tests\Support\CheckGolden\GoldenInstall;
use Tests\TestCase;

/**
 * `bridge:check`'s NEXT STEPS block (card#8959, DL-352).
 *
 * ONE INSTALL, THREE AGENTS, ONE PER STATE, because the block's whole claim is a
 * DISCRIMINATION: it names the agents that owe work and omits the one that does not. A
 * fixture with only incomplete agents would green on a derivation that named every agent
 * on the install, which is the version of this feature that is worse than no feature —
 * an agent told to re-provision a seat that is already working.
 *
 * ⚠ WHAT "OMITS THE THIRD AGENT" IS ASSERTED OVER, stated because the obvious assertion is
 * wrong here: the finished agent's NAME appears all over the report (its own board-tools
 * legs print it), so a whole-output `assertStringNotContainsString` would be asserting
 * something false. The assertions run over {@see self::nextStepLines()} — the block alone —
 * which is the population the claim is about.
 *
 * THE EXIT CONTRACT IS ASSERTED ON THE POSITIVE CASE, and that is the strongest form
 * available rather than a weaker one chosen for convenience. "Identical to the same run
 * without the feature" cannot be measured from inside the suite (the feature is not
 * switchable), so what is pinned instead is the property that would be violated: an install
 * that PRINTS the block still exits 0. A derivation that ever yielded a `fail` — the one
 * severity {@see CheckCommand::emitFinding()} flips the exit on
 * — reds here.
 */
class CheckNextStepsTest extends TestCase
{
    use BootsGoldenInstall;
    use RefreshDatabase;

    /**
     * A bearer that is present and 0600 but distinct per agent — two agents sharing one
     * token VALUE collide and are both excluded from the index (fail closed), which would
     * make the finished agent owe a step and destroy the discrimination this fixture is.
     */
    private const BEARER_B = 'bearer-value-b';

    private const BEARER_C = 'bearer-value-c';

    protected function tearDown(): void
    {
        $this->tearDownGoldenInstall();
        parent::tearDown();
    }

    public function test_the_block_names_the_incomplete_agents_in_order_with_their_commands(): void
    {
        $output = $this->runCheck();
        $lines = $this->nextStepLines($output);

        $this->assertNotSame([], $lines, 'the install has two incomplete agents and printed no NEXT STEPS block');

        // The HEADING plus one line per incomplete agent, and nothing else: a fourth line
        // would mean an agent was named that owes nothing.
        $this->assertCount(3, $lines);
        $this->assertStringContainsString('NEXT STEPS', $lines[0]);

        // ORDER IS ASSERTED, not just membership. The block is a sequence of things to do,
        // and config order is the only order this run has; a set-wise assertion would green
        // on a derivation that emitted them in hash order.
        $this->assertStringStartsWith('next step 1/2 — agent-a:', $lines[1]);
        $this->assertStringStartsWith('next step 2/2 — agent-b:', $lines[2]);

        // The COMMAND is the payload of the whole block — an entry that named the right
        // agent and the wrong command is the failure mode a name-only assertion misses.
        $this->assertStringContainsString('php artisan bridge:provision-tools --agent=agent-a', $lines[1]);
        $this->assertStringContainsString('php artisan bridge:check', $lines[2]);

        // The finished agent is absent FROM THE BLOCK (its name is legitimately elsewhere in
        // the report — see the class docblock).
        $this->assertStringNotContainsString('agent-c', implode("\n", $lines));

        // Every entry points at the runbook: a next step with no destination is a nag.
        $this->assertStringContainsString(NextSteps::DOC, $lines[1]);
        $this->assertStringContainsString(NextSteps::DOC, $lines[2]);
    }

    public function test_the_seat_side_entry_states_the_bridge_cannot_verify_it_and_refuses_the_probe_shortcut(): void
    {
        // DL-229 IS THE WHOLE POINT OF THIS ENTRY. The bridge may not read the seat's own
        // files, so this state is an ABSENCE OF EVIDENCE and the line has to say so — an
        // entry that read as "the seat is broken, re-provision it" would send an operator to
        // spend a privileged remediation window on a seat that may be fine (card#7756).
        $lines = $this->nextStepLines($this->runCheck());

        $this->assertStringContainsString('NOT VERIFIABLE FROM HERE', $lines[2]);
        $this->assertStringContainsString('DL-229', $lines[2]);

        // ⛔ AND THE SELF-FALSIFYING CURE IS NAMED AND REFUSED. `--probe-tools` reaches the
        // dispatcher's success point and stamps the SAME ledger row this state is derived
        // from (docs/board-tools.md step 6 says so), so recommending it as the verification
        // would clear the line without the seat ever calling — the block would then certify
        // its own advice.
        $this->assertStringContainsString('Do NOT clear this line with --probe-tools', $lines[2]);
    }

    public function test_the_json_document_carries_the_same_two_entries_with_their_states(): void
    {
        $doc = $this->runCheckAsJson();

        $this->assertSame(
            [
                ['agent' => 'agent-a', 'state' => 'no_block', 'command' => 'php artisan bridge:provision-tools --agent=agent-a', 'doc' => NextSteps::DOC],
                ['agent' => 'agent-b', 'state' => 'seat_side_unreported', 'command' => 'php artisan bridge:check', 'doc' => NextSteps::DOC],
            ],
            $doc['next_steps'],
        );
    }

    public function test_an_install_with_nothing_outstanding_prints_no_block_at_all(): void
    {
        // ⛔ NOT AN EMPTY HEADING, AND NOT A REASSURANCE. A block that appears on every run
        // is one the reader learns to scroll past, which costs exactly the installs it
        // exists for. This is the arm that would silently go missing if the heading were
        // printed unconditionally and only the entries were gated.
        $this->bootGoldenInstall('next-steps-nothing-outstanding', function (GoldenInstall $i) {
            $this->fakeBoard();
            $i->boot()
                ->agent('agent-c', $this->boardToolsAgentYaml($i->path('bearer-c')))
                ->secret('bearer-c', self::BEARER_C)
                ->secret('kanban/writeback-token', 'wb-token');
            $this->recordClientHalfCallFor('agent-c');
        });

        $exit = Artisan::call('bridge:check');
        $output = Artisan::output();

        $this->assertStringNotContainsString('NEXT STEPS', $output);
        $this->assertSame([], $this->nextStepLines($output));
        $this->assertSame(0, $exit);

        // The CONTROL for the assertion above, and the reason it measures anything: this
        // install DOES reach the client-half leg and DOES report it green. Without this the
        // silence would be indistinguishable from a fixture that never reached the
        // derivation at all.
        $this->assertStringContainsString('agent-c: client half REPORTED', $output);
        $this->assertSame([], $this->runCheckAsJsonForCurrentInstall()['next_steps']);
    }

    public function test_an_explicit_opt_out_owes_nothing_and_silences_the_line(): void
    {
        // ⭐ THE `no_block` LINE MAKES A PROMISE TO THE OPERATOR — *"put `enabled: false`
        // under a `board_tools:` key and this line goes away"* — and a promise printed to
        // operators with nothing asserting it is the shape this repo calls a comment. This
        // is that assertion, run against the exact spelling the line prints.
        //
        // It is also the only place the DELIBERATE-decline arm is separable from the
        // DEFAULT-suppressed one: both end up `enabled === false`, and only
        // `suppressedReason` tells them apart.
        $this->bootGoldenInstall('next-steps-opt-out', function (GoldenInstall $i) {
            $i->boot()->agent('agent-a', $this->kanbanOnlyAgentYaml()."board_tools:\n  enabled: false\n");
        });

        Artisan::call('bridge:check');

        $this->assertSame([], $this->nextStepLines(Artisan::output()));
    }

    public function test_printing_the_block_does_not_move_the_exit_code(): void
    {
        $exit = Artisan::call('bridge:check', $this->bootThreeAgentInstall());
        $output = Artisan::output();

        $this->assertStringContainsString('NEXT STEPS', $output, 'the fixture must reach the block for this to say anything');
        $this->assertSame(0, $exit, 'the NEXT STEPS block is output only — it may not flip the verdict');
    }

    public function test_the_doc_pointer_names_a_heading_that_docs_board_tools_actually_has(): void
    {
        // A POINTER WITH NO CHECK IS A COMMENT. The constant is printed to operators and
        // emitted to machine consumers, so a heading renamed in the doc would leave both
        // following a section that does not exist — and nothing else in this repo joins the
        // two. Asserted against the doc's own heading line, not against a second copy of it.
        [, $heading] = explode(' § ', NextSteps::DOC, 2);

        $this->assertStringContainsString(
            "\n## {$heading}\n",
            (string) file_get_contents(base_path('docs/board-tools.md')),
            'NextSteps::DOC names a section docs/board-tools.md does not have',
        );
    }

    // ---- fixture plumbing ----

    /**
     * Three agents, one per state, in the order `glob()` returns them:
     *   agent-a — no `board_tools:` block at all;
     *   agent-b — an enabled block with a readable bearer and NO recorded call;
     *   agent-c — the same, WITH a fresh recorded call.
     *
     * @return array<string, mixed> the args `bridge:check` should be called with
     */
    private function bootThreeAgentInstall(): array
    {
        $this->bootGoldenInstall('next-steps-three-agents', function (GoldenInstall $i) {
            $this->fakeBoard();
            $i->boot()
                ->agent('agent-a', $this->kanbanOnlyAgentYaml())
                ->agent('agent-b', $this->boardToolsAgentYaml($i->path('bearer-b')))
                ->agent('agent-c', $this->boardToolsAgentYaml($i->path('bearer-c')))
                ->secret('bearer-b', self::BEARER_B)
                ->secret('bearer-c', self::BEARER_C)
                ->secret('kanban/writeback-token', 'wb-token');
            $this->recordClientHalfCallFor('agent-c');
        });

        return [];
    }

    private function runCheck(): string
    {
        Artisan::call('bridge:check', $this->bootThreeAgentInstall());

        return Artisan::output();
    }

    /** @return array<string, mixed> */
    private function runCheckAsJson(): array
    {
        $args = $this->bootThreeAgentInstall();

        return $this->decode($args);
    }

    /** @return array<string, mixed> */
    private function runCheckAsJsonForCurrentInstall(): array
    {
        return $this->decode([]);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function decode(array $args): array
    {
        Artisan::call('bridge:check', $args + ['--format' => 'json']);
        $decoded = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($decoded, 'bridge:check --format=json did not emit a JSON object');

        return $decoded;
    }

    /**
     * The heading plus every `next step N/M` line, and nothing else — the population every
     * claim in this class is about.
     *
     * @return list<string>
     */
    private function nextStepLines(string $output): array
    {
        return array_values(array_filter(
            explode("\n", $output),
            static fn (string $line): bool => str_starts_with($line, 'NEXT STEPS') || str_starts_with($line, 'next step '),
        ));
    }

    private function kanbanOnlyAgentYaml(): string
    {
        return "identity:\n  kanban_user_id: 137\n"
            ."subscriptions:\n  - provider: kanban\n    scopes: [5]\n";
    }

    /**
     * The http twin of the golden corpus' board-tools fixture: the bearer sits on
     * `board_tools.auth` and NOT on `channel.auth`, because a channel bearer is legal only
     * alongside a `channel.url` and would throw at load (card#5552), taking the whole plane
     * with it.
     */
    private function boardToolsAgentYaml(string $tokenPath): string
    {
        return $this->kanbanOnlyAgentYaml()
            ."board_tools:\n  transport: http\n  board_id: 10\n  swimlane_id: 4\n  create_stage_id: 55\n"
            ."  auth:\n    token_path: {$tokenPath}\n";
    }

    /**
     * A recorded call well inside the freshness window — the state that makes an agent owe
     * nothing.
     */
    private function recordClientHalfCallFor(string $agent): void
    {
        BoardToolsClientCall::query()->create([
            'agent' => $agent,
            'transport' => 'http',
            'last_success_at' => now()->subMinutes(5),
        ]);
    }

    private function fakeBoard(): void
    {
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [], 'meta' => ['total' => 0]]),
            '*/boards/10/preload.json' => Http::response(['data' => ['workflows' => [['stages' => [
                ['id' => 55, 'name' => 'Backlog', 'position' => 1024.0],
            ]]], 'swimlanes' => [['id' => 4, 'name' => 'Default']]]]),
        ]);
    }
}
