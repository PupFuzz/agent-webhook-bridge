<?php

namespace Tests\Feature\Console\Check;

use App\Bridge\Check\CheckJsonRenderer;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\CheckGolden\GoldenInstall;
use Tests\Support\CheckGolden\PinnedHost;
use Tests\TestCase;

/**
 * THE GUARD ON `bridge:check --format=json`'s WRITE CONTRACT (card#5229 / DL-249 stage 9).
 *
 * This is the reason stage 9 was gated. A JSON shape is a write contract: once a machine
 * consumer parses it you cannot un-ship it, so per the fleet write-contract rule the
 * document is VERSIONED ({@see CheckJsonRenderer::SCHEMA_VERSION}) and carries its own
 * guard — this class. The version is what a consumer keys on; this is what stops the
 * shape moving under them without anyone deciding to move it.
 *
 * THE KEY SETS ARE ASSERTED WITH `assertSame` AGAINST LITERALS, at every level, and that
 * strictness is the point rather than an inconvenience: a *contains* assertion greens on
 * a renamed key, and a renamed key is exactly the change that breaks a consumer silently.
 * WHEN THIS REDS, IT IS USUALLY RIGHT — update the literal in the SAME commit as the
 * renderer change so the diff shows both halves, and decide DELIBERATELY whether the
 * change is additive (no bump) or consumer-visible (bump the version).
 *
 * WHAT IT DELIBERATELY DOES NOT ASSERT: the `message` strings. They are operator prose,
 * they are excluded from the contract by name, and pinning them here would re-create the
 * text coupling this whole surface exists to break.
 *
 * The corpus-wide properties — that every one of the 33 install shapes produces a
 * parseable document whose verdict and counts agree with the committed text capture —
 * live in {@see CheckGoldenTest}, which owns the install-shape corpus. Rebuilding that
 * corpus here would be the second copy this program keeps carding.
 */
class CheckJsonContractTest extends TestCase
{
    use RefreshDatabase;

    private ?GoldenInstall $install = null;

    private ?PinnedHost $host = null;

    protected function tearDown(): void
    {
        $this->host?->restore();
        $this->install?->destroy();
        $this->host = null;
        $this->install = null;
        parent::tearDown();
    }

    public function test_the_document_declares_its_schema_and_exactly_these_top_level_keys(): void
    {
        $doc = $this->document('minimal');

        $this->assertSame(1, CheckJsonRenderer::SCHEMA_VERSION, 'the shipped schema version moved — is that deliberate?');
        $this->assertSame(CheckJsonRenderer::SCHEMA_VERSION, $doc['schema']);
        $this->assertSame(
            ['schema', 'ok', 'checks', 'findings_outside_registry', 'inventory', 'event_consumers'],
            array_keys($doc),
        );
    }

    public function test_every_registered_check_is_one_entry_of_a_pinned_shape(): void
    {
        $doc = $this->document('minimal');

        // Driven by the inventory, not by what ran: the 13-of-37 that never got to look
        // are rows with a disposition, not absences. A results-driven document would omit
        // them, which is the green-because-never-looked shape one level up.
        $this->assertSame($doc['inventory']['registered'], count($doc['checks']));
        $this->assertGreaterThan(0, $doc['inventory']['not_run']);

        foreach ($doc['checks'] as $entry) {
            $this->assertSame(['id', 'disposition', 'not_run_reason', 'findings'], array_keys($entry));
            $this->assertContains($entry['disposition'], ['reported', 'silent', 'not-requested', 'not-run']);
            foreach ($entry['findings'] as $finding) {
                $this->assertSame(['severity', 'agent', 'message'], array_keys($finding));
                $this->assertContains($finding['severity'], ['fail', 'warn', 'unvalidated', 'ok']);
            }
        }

        $ids = array_column($doc['checks'], 'id');
        $this->assertSame(array_values(array_unique($ids)), $ids, 'a check id appears twice — per-agent results must group under one entry');
    }

    public function test_the_inventory_block_shape_is_pinned_and_self_conserving(): void
    {
        $inventory = $this->document('minimal')['inventory'];

        $this->assertSame(
            ['registered', 'ran', 'reported', 'silent', 'not_requested', 'not_run', 'not_run_reasons', 'unexplained_not_run'],
            array_keys($inventory),
        );
        // The same arithmetic the text line carries, asserted on the document so the two
        // renderers cannot drift into disagreeing about one run.
        $this->assertSame($inventory['ran'], $inventory['reported'] + $inventory['silent']);
        $this->assertSame(
            $inventory['registered'],
            $inventory['ran'] + $inventory['not_requested'] + $inventory['not_run'],
        );
        $this->assertSame([], $inventory['unexplained_not_run']);
    }

    public function test_the_opt_in_dispositions_are_per_check_where_the_text_line_only_counts_them(): void
    {
        // The resolved opt-in-probe decision's third bullet, which is a stage-9 deliverable
        // in its own right: the text renderer says "2 opt-in probes not requested" and the
        // document says WHICH two. A consumer cannot recover the names from the count.
        $notRequested = array_column(
            array_filter($this->document('minimal')['checks'], fn (array $c): bool => $c['disposition'] === 'not-requested'),
            'id',
        );

        $this->assertSame(['board_tools.http_live_probe', 'board_tools.ssh_live_probe'], array_values($notRequested));
    }

    public function test_a_not_run_check_carries_its_envelope_reason(): void
    {
        $byId = array_column($this->document('minimal')['checks'], null, 'id');

        $this->assertSame('not-run', $byId['writeback.config']['disposition']);
        $this->assertSame(
            'this install has no readable writeback.json, so the PR-to-card writeback is off',
            $byId['writeback.config']['not_run_reason'],
        );
        // A check that RAN carries no reason — the field is not a slot for prose.
        $this->assertNull($byId['install.config_dir']['not_run_reason']);
    }

    public function test_an_envelope_failure_is_in_the_document_and_the_verdict_agrees_with_it(): void
    {
        // THE FALSE-CLEAN GUARD. A malformed agent YAML is reported by a fail-soft
        // envelope in `handle()`, not by any registered check. Omitting it would produce
        // a document whose every check is clean and whose `ok` is false, with nothing in
        // it naming the cause — machine-readable false clean, which is the exact defect
        // this program exists to remove.
        $doc = $this->document('agent-yaml-malformed');

        $this->assertFalse($doc['ok']);
        $this->assertCount(1, $doc['findings_outside_registry']);
        $this->assertSame(['severity', 'agent', 'message'], array_keys($doc['findings_outside_registry'][0]));
        $this->assertSame('fail', $doc['findings_outside_registry'][0]['severity']);
        $this->assertStringContainsString('is not valid YAML', $doc['findings_outside_registry'][0]['message']);
    }

    public function test_a_fail_anywhere_forces_ok_false(): void
    {
        // The invariant that makes `emitUnattributed()` safe to leave out of the `$ok`
        // decision: a future envelope that forgets its `$ok = false` reds HERE rather
        // than shipping a document that contradicts itself.
        foreach (['minimal', 'agent-yaml-malformed'] as $shape) {
            $doc = $this->document($shape);
            $severities = array_merge(
                array_column($doc['findings_outside_registry'], 'severity'),
                array_merge(...array_map(
                    fn (array $c): array => array_column($c['findings'], 'severity'),
                    $doc['checks'],
                ) ?: [[]]),
            );

            $this->assertSame(
                ! in_array('fail', $severities, true),
                $doc['ok'],
                "shape '{$shape}': the document's verdict disagrees with the findings it carries",
            );
        }
    }

    public function test_a_per_agent_check_is_one_entry_whose_findings_name_their_agent(): void
    {
        // THE ONE ATTRIBUTION THE TEXT RENDERER HAS NO PLACE FOR. A check yields
        // display-ready messages and nothing frames them per check, so the operator reads
        // the agent name only because the message happens to contain it. The document
        // carries it as a field — and carries it WITHOUT breaking the id keying the
        // inventory uses, which is why a per-agent check is ONE entry with N findings
        // rather than N entries.
        //
        // ADDED BECAUSE MUTATION FOUND IT UNWITNESSED: dropping per-agent results from
        // `CheckRunner::results()` left the entire suite green, so nothing was asserting
        // that half of the document at all.
        $byId = array_column($this->document('two-agents-missing-secrets')['checks'], null, 'id');

        $secrets = $byId['agent.webhook_secrets'];
        $this->assertSame('reported', $secrets['disposition']);
        $this->assertSame(['alpha', 'beta'], array_column($secrets['findings'], 'agent'));
        foreach ($secrets['findings'] as $finding) {
            $this->assertSame('warn', $finding['severity']);
        }

        // A GLOBAL check's findings carry a null agent — the field distinguishes the two
        // scopes rather than being a decorative copy of something in the message.
        $this->assertNull($byId['install.endpoint_urls']['findings'][0]['agent'] ?? null);
    }

    public function test_the_event_consumer_reconciliation_is_data_not_prose(): void
    {
        // card#5229's deliverable, end to end. Everything a consumer needs is a field:
        // nothing here requires matching a sentence, which is what breaks — toward a
        // FALSE CLEAN — the next time a finding is reworded.
        $block = $this->document('event-consumer-unconsumed-type')['event_consumers'];

        $this->assertSame(['error', 'scopes'], array_keys($block));
        $this->assertNull($block['error']);
        $this->assertCount(1, $block['scopes']);

        $scope = $block['scopes'][0];
        $this->assertSame(
            ['scope', 'agents', 'observed', 'observed_actions', 'consumed', 'bare', 'qualified', 'undeclared', 'unconsumed', 'unlisted_actions'],
            array_keys($scope),
        );
        $this->assertSame('owner/repo', $scope['scope']);
        $this->assertSame(['wb'], $scope['agents']);
        $this->assertSame(['issues'], $scope['unconsumed']);
        $this->assertContains('pull_request', $scope['consumed']);
        $this->assertContains('pull_request', $scope['bare']);
        $this->assertSame(['count' => 1, 'last' => '2026-01-01 00:00:00'], $scope['observed']['issues']);
        $this->assertSame([], $scope['undeclared']);
    }

    public function test_a_possibly_empty_map_encodes_as_an_object_rather_than_an_empty_list(): void
    {
        // PHP encodes an empty array as `[]`, so a map field would change JSON TYPE
        // depending on whether it happened to have entries — and a consumer indexing it
        // would have to handle both. Asserted on the encoded bytes, because the array the
        // renderer builds cannot express the difference: this is a property of the
        // ENCODING, and a test on the value would pass under the defect.
        $encoded = $this->encoded('event-consumer-unconsumed-type');

        $this->assertStringContainsString('"qualified": {}', $encoded);
        $this->assertStringContainsString('"unlisted_actions": {}', $encoded);
        $this->assertStringNotContainsString('"qualified": []', $encoded);
        $this->assertStringNotContainsString('"observed": []', $encoded);
    }

    public function test_the_document_is_the_only_thing_on_stdout_even_when_an_envelope_reported(): void
    {
        // The parse guarantee, on the shape most likely to break it: `agent-yaml-malformed`
        // is the one whose diagnosis comes from OUTSIDE the registry, which in text mode
        // is a bare `error()` line. If any emitter were left ungated, the stream would
        // carry that line beside the document and `json_decode` would refuse the lot.
        $raw = $this->encoded('agent-yaml-malformed');

        $this->assertSame('{', $raw[0]);
        $this->assertNotNull(json_decode($raw, true), 'stdout is not a single JSON document: '.substr($raw, 0, 200));
        $this->assertStringNotContainsString('checks: 37 registered', $raw, 'the text inventory line leaked into the json stream');
    }

    public function test_an_unknown_format_fails_closed_without_emitting_a_report(): void
    {
        // Fail-closed rather than defaulting to text: a typo'd --format that silently
        // produced the operator report on stdout with a zero exit is the same false-clean
        // shape one layer up — the consumer's parser would see nothing and report nothing.
        $this->build('minimal');
        $exit = Artisan::call('bridge:check', ['--format' => 'yaml']);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString("unknown --format 'yaml'", $output);
        $this->assertStringNotContainsString('agent config ok:', $output);
    }

    // ---- fixture plumbing ----

    /**
     * The install shapes this class asserts against, deliberately few and named for what
     * each one proves. Breadth over the whole corpus is {@see CheckGoldenTest}'s.
     */
    private function build(string $name): void
    {
        $install = new GoldenInstall('json-'.$name);
        $host = new PinnedHost($install->path());
        $this->install = $install;
        $this->host = $host;
        $host->resetAmbientState();

        $kanbanAgent = "identity:\n  kanban_user_id: 137\nsubscriptions:\n  - provider: kanban\n    scopes: [5]\n";

        switch ($name) {
            case 'minimal':
                $install->boot()->agent('prod-agent', $kanbanAgent);
                break;

            case 'agent-yaml-malformed':
                $install->boot()->agent('prod-agent', "identity:\n  kanban_user_id: 137\nsubscriptions: [\n");
                break;

            case 'two-agents-missing-secrets':
                // Two agents, each with a github subscription and no secret file: the
                // per-agent secret/token legs report once PER AGENT, which is the only
                // shape that can tell one grouped entry from two.
                $githubAgent = "identity:\n  github_user_id: 555\n"
                    ."subscriptions:\n  - provider: github\n    scopes: [\"owner/repo\"]\n"
                    ."classifier:\n  class: App\\Bridge\\Classifiers\\GitHubPrCardMoveClassifier\n";
                $install->boot()->agent('alpha', $githubAgent)->agent('beta', $githubAgent);
                break;

            case 'event-consumer-unconsumed-type':
                $install->boot()->agent('wb', "identity:\n  github_user_id: 555\n"
                    ."subscriptions:\n  - provider: github\n    scopes: [\"owner/repo\"]\n"
                    ."classifier:\n  class: App\\Bridge\\Classifiers\\GitHubPrCardMoveClassifier\n");
                $event = WebhookEvent::create([
                    'delivery_id' => 'e1', 'provider' => 'github', 'scope_id' => 'owner/repo',
                    'event_type' => 'issues.closed', 'actor_id' => '1', 'payload' => ['x' => 1],
                ]);
                // Pinned: the arrival timestamp is asserted, so a clock read here would
                // make the assertion expire overnight.
                $event->forceFill(['received_at' => '2026-01-01 00:00:00'])->save();
                break;

            default:
                $this->fail("unknown json-contract shape: {$name}");
        }

        $host->apply(fpmPresent: false, coordConfig: null);
    }

    private function encoded(string $name): string
    {
        $this->build($name);
        Artisan::call('bridge:check', ['--format' => 'json']);

        return trim(Artisan::output());
    }

    /** @return array<string, mixed> */
    private function document(string $name): array
    {
        $decoded = json_decode($this->encoded($name), true);
        $this->assertIsArray($decoded, "bridge:check --format=json did not emit a JSON object for '{$name}'");

        return $decoded;
    }
}
