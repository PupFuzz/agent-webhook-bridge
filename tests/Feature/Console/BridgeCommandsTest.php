<?php

namespace Tests\Feature\Console;

use App\Bridge\Support\BridgePaths;
use App\Bridge\Writeback\KanbanClient;
use App\Console\Commands\Bridge\InboxCommand;
use App\Console\Commands\Bridge\ReplayCommand;
use App\Models\AgentDispatch;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BridgeCommandsTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/cli-'.uniqid();
        File::ensureDirectoryExists($this->dir);
        config(['bridge.config_dir' => $this->dir, 'bridge.secret_dir' => $this->dir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function writeAgent(): void
    {
        File::put($this->dir.'/prod-agent.yml', "identity:\n  kanban_user_id: 137\n"
            ."subscriptions:\n  - provider: kanban\n    scopes: [5]\n");
    }

    private function event(): WebhookEvent
    {
        return WebhookEvent::create([
            'delivery_id' => 'evt-1', 'provider' => 'kanban', 'scope_id' => '5',
            'event_type' => 'task.created', 'actor_id' => '999',
            'payload' => ['subject_id' => 42, 'board_id' => 5, 'payload' => ['name' => 'Ship it']],
        ]);
    }

    public function test_check_passes_with_valid_config(): void
    {
        $this->writeAgent();
        $this->artisan('bridge:check')->assertExitCode(0);
    }

    public function test_check_fails_without_secret_dir(): void
    {
        config(['bridge.secret_dir' => null]);
        $this->artisan('bridge:check')->assertExitCode(1);
    }

    public function test_check_fails_on_configured_provider_without_adapter(): void
    {
        // B-15: a config('bridge.providers') key with no WebhookAdapterFactory
        // adapter is dead config (the receiver would 400 unknown_provider).
        $this->writeAgent();
        config(['bridge.providers.gitlab' => ['api_base_url' => 'https://gitlab.example.com/api/v4']]);
        $this->artisan('bridge:check')
            ->expectsOutputToContain('no adapter')
            ->assertExitCode(1);
    }

    public function test_check_warns_on_missing_writeback_token(): void
    {
        $this->writeAgent();
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => ['board_id' => 8, 'stages' => ['merged' => 52]]],
        ]));
        // The board-visibility probe (DL-026) is reachable here; with no token the
        // factory throws before any HTTP, but fake to harden against a real call.
        Http::fake();
        $this->artisan('bridge:check')
            ->expectsOutputToContain('writeback token')
            ->assertExitCode(0);   // warn, not fail
        Http::assertNothingSent();
    }

    public function test_check_fails_on_malformed_writeback_json(): void
    {
        $this->writeAgent();
        File::put($this->dir.'/writeback.json', 'not json {');
        $this->artisan('bridge:check')
            ->expectsOutputToContain('writeback.json')
            ->assertExitCode(1);
    }

    private function writeWritebackWithToken(): void
    {
        $this->writeAgent();
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => ['board_id' => 8, 'stages' => ['merged' => 52]]],
        ]));
        File::ensureDirectoryExists($this->dir.'/kanban');
        File::put($this->dir.'/kanban/writeback-token', 'wb-token');
        chmod($this->dir.'/kanban/writeback-token', 0o600);
        config(['bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3']);
    }

    public function test_check_warns_when_the_writeback_token_sees_zero_cards(): void
    {
        // DL-026: a 200 + empty board read = blind/degraded token (user not a
        // board member / wrong board_id). bridge:check must surface it LOUDLY at
        // config time, but stay a warning (exit 0) — an empty new board is legit.
        $this->writeWritebackWithToken();
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => []])]);

        $this->artisan('bridge:check')
            ->expectsOutputToContain('sees 0 cards on board 8')
            ->assertExitCode(0);
    }

    public function test_check_reports_visible_card_count_when_token_can_see_the_board(): void
    {
        $this->writeWritebackWithToken();
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => [
            ['id' => 1, 'payload' => []],
            ['id' => 2, 'payload' => []],
        ]])]);

        $this->artisan('bridge:check')
            ->expectsOutputToContain('sees 2 card(s) on board 8')
            ->assertExitCode(0);
    }

    public function test_check_warns_when_a_board_exceeds_the_paging_safety_ceiling(): void
    {
        // DL-028: every page comes back full ⇒ the walk hits the MAX_PAGES ceiling.
        // bridge:check surfaces it (warn, not fail — a >10k-card board is exotic but real).
        $this->writeWritebackWithToken();
        $full = array_fill(0, KanbanClient::SEARCH_LIMIT, ['id' => 1, 'payload' => []]);
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => $full])]);

        // Assert on the early, wrap-safe token; the full message is "… exceeds
        // the 10000-card safety ceiling …" (console line-wrap can split the tail).
        $this->artisan('bridge:check')
            ->expectsOutputToContain('exceeds the')
            ->assertExitCode(0);
    }

    public function test_check_warns_when_the_board_read_fails(): void
    {
        $this->writeWritebackWithToken();
        Http::fake(['*/tasks/search.json*' => Http::response(['error' => 'forbidden'], 403)]);

        $this->artisan('bridge:check')
            ->expectsOutputToContain('could not read board 8')
            ->assertExitCode(0);
    }

    public function test_check_confirms_a_mapping_swimlane_id_that_exists_on_the_board(): void
    {
        // DL-027: when a mapping pins a swimlane_id, bridge:check validates it
        // against the board's lanes so a deleted/wrong lane is caught at config
        // time rather than as a silent 422-no-op on the first created card.
        $this->writeAgent();
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => ['board_id' => 8, 'swimlane_id' => 31, 'stages' => ['merged' => 52]]],
        ]));
        File::ensureDirectoryExists($this->dir.'/kanban');
        File::put($this->dir.'/kanban/writeback-token', 'wb-token');
        chmod($this->dir.'/kanban/writeback-token', 0o600);
        config(['bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3']);
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 1, 'payload' => []]]]),
            '*/boards/8/preload.json' => Http::response(['data' => ['swimlanes' => [['id' => 31], ['id' => 32]]]]),
        ]);

        $this->artisan('bridge:check')
            ->expectsOutputToContain('swimlane_id 31 ok on board 8')
            ->assertExitCode(0);
    }

    public function test_check_warns_when_a_mapping_swimlane_id_is_not_on_the_board(): void
    {
        $this->writeAgent();
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => ['board_id' => 8, 'swimlane_id' => 99, 'stages' => ['merged' => 52]]],
        ]));
        File::ensureDirectoryExists($this->dir.'/kanban');
        File::put($this->dir.'/kanban/writeback-token', 'wb-token');
        chmod($this->dir.'/kanban/writeback-token', 0o600);
        config(['bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3']);
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 1, 'payload' => []]]]),
            '*/boards/8/preload.json' => Http::response(['data' => ['swimlanes' => [['id' => 31], ['id' => 32]]]]),
        ]);

        $this->artisan('bridge:check')
            ->expectsOutputToContain('swimlane_id 99 not found on board 8')
            ->assertExitCode(0);
    }

    public function test_check_skips_the_board_probe_without_a_base_url_and_makes_no_request(): void
    {
        // Guard-lock (S3): the probe block IS reached (writeback.json + mapping),
        // but with no api_base_url the factory throws → the probe self-skips and
        // must make NO stray network call. Locks the base-url guard so a refactor
        // that drops it fails here.
        $this->writeAgent();
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => ['board_id' => 8, 'stages' => ['merged' => 52]]],
        ]));
        File::ensureDirectoryExists($this->dir.'/kanban');
        File::put($this->dir.'/kanban/writeback-token', 'wb-token');
        chmod($this->dir.'/kanban/writeback-token', 0o600);
        // Explicitly unset api_base_url (the dev .env populates it) → the factory
        // throws ConfigException → the probe self-skips, no network call.
        config(['bridge.providers.kanban.api_base_url' => null]);
        Http::fake();

        $this->artisan('bridge:check')
            ->expectsOutputToContain('skipped board-visibility probe')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_check_warns_on_group_accessible_config_dir(): void
    {
        // DL-014: the config dir holds secrets → warn (not fail) if it's not 0700.
        $this->writeAgent();
        chmod($this->dir, 0o755);
        $this->artisan('bridge:check')
            ->expectsOutputToContain('group/world-accessible')
            ->assertExitCode(0);
    }

    public function test_inbox_collapses_duplicate_ids_on_read(): void
    {
        // DL-012: the writer is append-only, so a partial-staging redelivery can
        // leave two lines with the same id — bridge:inbox must surface it once.
        File::ensureDirectoryExists($this->dir.'/state');
        File::put($this->dir.'/state/inbox.jsonl',
            json_encode(['id' => 'e:agent:0', 'ts' => 1.0, 'kind' => 'new_card', 'summary' => 'dup'])."\n".
            json_encode(['id' => 'e:agent:0', 'ts' => 1.0, 'kind' => 'new_card', 'summary' => 'dup'])."\n",
        );

        $this->artisan('bridge:inbox', ['--hook-format' => 'plain'])
            ->expectsOutputToContain('new_card')
            ->assertExitCode(0);

        // Surfaced once → cursor records exactly one id; a second run is silent.
        $this->assertSame(['e:agent:0'], json_decode((string) File::get($this->dir.'/state/inbox-seen.json'), true));
        $this->artisan('bridge:inbox', ['--hook-format' => 'plain'])->doesntExpectOutputToContain('new_card')->assertExitCode(0);
    }

    public function test_prune_deletes_events_and_dispatches_older_than(): void
    {
        $old = $this->event();
        $old->received_at = now()->subDays(40);
        $old->save();
        AgentDispatch::create(['webhook_event_id' => $old->id, 'agent_name' => 'prod-agent']);

        $recent = WebhookEvent::create([
            'delivery_id' => 'evt-2', 'provider' => 'kanban', 'scope_id' => '5',
            'event_type' => 'task.created', 'actor_id' => '1', 'payload' => ['x' => 1],
        ]);

        $this->artisan('bridge:prune', ['--older-than' => '30d'])->assertExitCode(0);

        $this->assertNull(WebhookEvent::find($old->id));                 // deleted
        $this->assertSame(0, AgentDispatch::where('webhook_event_id', $old->id)->count());   // cascade
        $this->assertNotNull(WebhookEvent::find($recent->id));           // recent kept
    }

    public function test_prune_nulls_payloads_older_than_keeping_the_row(): void
    {
        $e = $this->event();
        $e->received_at = now()->subDays(10);
        $e->save();

        $this->artisan('bridge:prune', ['--null-payloads-older-than' => '7d'])->assertExitCode(0);

        $e->refresh();
        $this->assertNotNull($e->id);          // row kept (dedup gate + audit)
        $this->assertNull($e->payload);        // body shed
    }

    public function test_prune_trims_inbox_lines_and_seen_cursor(): void
    {
        File::ensureDirectoryExists($this->dir.'/state');
        $oldTs = (float) now()->subDays(40)->format('U.u');
        $newTs = (float) now()->format('U.u');
        File::put($this->dir.'/state/inbox.jsonl',
            json_encode(['id' => 'old:0', 'ts' => $oldTs, 'kind' => 'x', 'summary' => 'old'])."\n".
            json_encode(['id' => 'new:0', 'ts' => $newTs, 'kind' => 'x', 'summary' => 'new'])."\n",
        );
        File::put($this->dir.'/state/inbox-seen.json', json_encode(['old:0', 'new:0']));

        $this->artisan('bridge:prune', ['--older-than' => '30d'])->assertExitCode(0);

        $remaining = array_column(BridgePaths::readJsonl($this->dir.'/state/inbox.jsonl'), 'id');
        $this->assertSame(['new:0'], $remaining);                        // old line trimmed
        $this->assertSame(['new:0'], json_decode((string) File::get($this->dir.'/state/inbox-seen.json'), true));   // seen bounded
    }

    public function test_prune_dry_run_changes_nothing(): void
    {
        $old = $this->event();
        $old->received_at = now()->subDays(40);
        $old->save();

        $this->artisan('bridge:prune', ['--older-than' => '30d', '--dry-run' => true])->assertExitCode(0);

        $this->assertNotNull(WebhookEvent::find($old->id));   // still there
    }

    public function test_prune_requires_a_window(): void
    {
        $this->artisan('bridge:prune')->assertExitCode(1);
    }

    public function test_prune_rejects_an_absurd_window_without_deleting(): void
    {
        // A 20-digit value would overflow now()->subDays() into a FUTURE cutoff
        // and wipe everything — it must be rejected, changing nothing.
        $old = $this->event();
        $old->received_at = now()->subDays(40);
        $old->save();

        $this->artisan('bridge:prune', ['--older-than' => '99999999999999999999d'])->assertExitCode(1);
        $this->assertNotNull(WebhookEvent::find($old->id));
    }

    public function test_check_fails_on_invalid_inbox_layout(): void
    {
        $this->writeAgent();
        config(['bridge.inbox_layout' => 'bogus']);
        $this->artisan('bridge:check')
            ->expectsOutputToContain('BRIDGE_INBOX_LAYOUT')
            ->assertExitCode(1);
    }

    public function test_check_fails_on_cross_user_group_without_per_agent_layout(): void
    {
        $this->writeAgent();
        // group read under shared/both would expose the shared inbox → refused.
        config(['bridge.inbox_group' => 'agent-bridge', 'bridge.inbox_layout' => 'both']);
        $this->artisan('bridge:check')
            ->expectsOutputToContain('per-agent')
            ->assertExitCode(1);
    }

    public function test_check_passes_with_per_agent_layout_and_group(): void
    {
        $this->writeAgent();
        config(['bridge.inbox_group' => 'agent-bridge', 'bridge.inbox_layout' => 'per-agent']);
        $this->artisan('bridge:check')->assertExitCode(0);
    }

    public function test_check_fails_on_unresolvable_classifier_fqcn(): void
    {
        File::put($this->dir.'/prod-agent.yml', "identity:\n  kanban_user_id: 137\n"
            ."subscriptions:\n  - provider: kanban\n    scopes: [5]\n"
            ."classifier:\n  class: 'App\\Bridge\\Classifiers\\NoSuchClassifier'\n");

        // A bad FQCN would be a dispatch error; bridge:check catches it early.
        $this->artisan('bridge:check')->assertExitCode(1);
    }

    public function test_check_fails_on_malformed_receiver_url(): void
    {
        $this->writeAgent();
        config(['bridge.receiver_base_url' => 'not a url']);
        $this->artisan('bridge:check')
            ->expectsOutputToContain('receiver_base_url')
            ->assertExitCode(1);
    }

    public function test_check_fails_on_unknown_treat_as_signal_name(): void
    {
        $this->writeAgent();   // prod-agent
        File::put($this->dir.'/pm.yml', "identity:\n  kanban_user_id: 100\n"
            ."subscriptions:\n  - provider: kanban\n    scopes: [6]\n"
            ."echo_suppression:\n  treat_as_signal: [ghost]\n");

        // 'ghost' has no config → fail-closed (would 5xx at dispatch); caught at preflight.
        $this->artisan('bridge:check')
            ->expectsOutputToContain('treat_as_signal')
            ->assertExitCode(1);
    }

    public function test_check_warns_on_unknown_default_agent(): void
    {
        $this->writeAgent();
        config(['bridge.default_agent' => 'ghost']);
        $this->artisan('bridge:check')
            ->expectsOutputToContain('BRIDGE_DEFAULT_AGENT')
            ->assertExitCode(0);   // warn, not fail
    }

    public function test_stats_reports_counts(): void
    {
        $event = $this->event();
        AgentDispatch::create(['webhook_event_id' => $event->id, 'agent_name' => 'a', 'processed_at' => now()]);
        AgentDispatch::create(['webhook_event_id' => $event->id, 'agent_name' => 'b', 'error_message' => 'boom']);

        $this->artisan('bridge:stats')->assertExitCode(0);
    }

    public function test_inspect_shows_event_or_fails(): void
    {
        $event = $this->event();
        $this->artisan('bridge:inspect', ['id' => $event->id])
            ->expectsOutputToContain('evt-1')
            ->assertExitCode(0);

        $this->artisan('bridge:inspect', ['id' => 99999])->assertExitCode(1);
    }

    public function test_replay_reprocesses_an_errored_dispatch(): void
    {
        $this->writeAgent();
        $event = $this->event();
        AgentDispatch::create([
            'webhook_event_id' => $event->id, 'agent_name' => 'prod-agent', 'error_message' => 'old failure',
        ]);

        $this->artisan('bridge:replay', ['id' => $event->id])->assertExitCode(0);

        $dispatch = AgentDispatch::where('agent_name', 'prod-agent')->firstOrFail();
        $this->assertNotNull($dispatch->processed_at);   // re-ran and succeeded
        $this->assertNull($dispatch->error_message);
    }

    public function test_replay_force_reruns_succeeded_dispatch(): void
    {
        $this->writeAgent();
        $event = $this->event();
        $done = AgentDispatch::create([
            'webhook_event_id' => $event->id, 'agent_name' => 'prod-agent', 'processed_at' => now()->subDay(),
        ]);
        $originalProcessedAt = $done->processed_at;

        $this->artisan('bridge:replay', ['id' => $event->id, '--force' => true])->assertExitCode(0);

        // --force cleared processed_at, so it re-ran and got a fresh timestamp.
        $this->assertTrue($done->fresh()->processed_at->greaterThan($originalProcessedAt));
    }

    public function test_inbox_surfaces_unseen_then_is_silent(): void
    {
        File::ensureDirectoryExists($this->dir.'/state');
        File::put($this->dir.'/state/inbox.jsonl',
            json_encode(['id' => 'evt-1:prod-agent:0', 'ts' => 1.0, 'kind' => 'new_card', 'summary' => 'card 42'])."\n");

        $this->artisan('bridge:inbox', ['--hook-format' => 'plain'])
            ->expectsOutputToContain('new_card')
            ->assertExitCode(0);

        // Seen advanced → second run surfaces nothing (no new output).
        $this->artisan('bridge:inbox', ['--hook-format' => 'plain'])
            ->doesntExpectOutput()
            ->assertExitCode(0);
    }

    public function test_inbox_agent_flag_filters_shared_inbox_and_isolates_cursor(): void
    {
        File::ensureDirectoryExists($this->dir.'/state');
        File::put($this->dir.'/state/inbox.jsonl',
            json_encode(['id' => 'e:pm:0', 'ts' => 1.0, 'agent' => 'pm', 'kind' => 'new_card', 'summary' => 'pm card'])."\n"
            .json_encode(['id' => 'e:backend:0', 'ts' => 1.0, 'agent' => 'backend', 'kind' => 'new_card', 'summary' => 'backend card'])."\n");

        $this->artisan('bridge:inbox', ['--agent' => 'pm', '--hook-format' => 'plain'])
            ->expectsOutputToContain('pm card')
            ->assertExitCode(0);

        // pm saw ONLY its own line (strict filtering proof) and its cursor is separate.
        $this->assertSame(['e:pm:0'], json_decode((string) File::get($this->dir.'/state/inbox-seen-pm.json'), true));

        // backend's cursor is untouched by pm's mark-seen → backend still surfaces its own.
        $this->artisan('bridge:inbox', ['--agent' => 'backend', '--hook-format' => 'plain'])
            ->expectsOutputToContain('backend card')
            ->assertExitCode(0);
    }

    public function test_inbox_reads_per_agent_file_when_present(): void
    {
        File::ensureDirectoryExists($this->dir.'/state');
        File::put($this->dir.'/state/inbox-pm.jsonl',
            json_encode(['id' => 'e:pm:0', 'ts' => 1.0, 'agent' => 'pm', 'kind' => 'new_card', 'summary' => 'from per-agent file'])."\n");

        $this->artisan('bridge:inbox', ['--agent' => 'pm', '--hook-format' => 'plain'])
            ->expectsOutputToContain('from per-agent file')
            ->assertExitCode(0);
    }

    public function test_inbox_honors_default_agent_env(): void
    {
        config(['bridge.default_agent' => 'pm']);
        File::ensureDirectoryExists($this->dir.'/state');
        File::put($this->dir.'/state/inbox.jsonl',
            json_encode(['id' => 'e:pm:0', 'ts' => 1.0, 'agent' => 'pm', 'kind' => 'new_card', 'summary' => 'pm card'])."\n");

        // Bare bridge:inbox (no --agent) surfaces the default agent and uses its cursor.
        $this->artisan('bridge:inbox', ['--hook-format' => 'plain'])
            ->expectsOutputToContain('pm card')
            ->assertExitCode(0);
        $this->assertFileExists($this->dir.'/state/inbox-seen-pm.json');
        $this->assertFileDoesNotExist($this->dir.'/state/inbox-seen.json');
    }

    public function test_inbox_no_cursor_advance_leaves_intents_unseen(): void
    {
        File::ensureDirectoryExists($this->dir.'/state');
        File::put($this->dir.'/state/inbox.jsonl',
            json_encode(['id' => 'e:x:0', 'ts' => 1.0, 'kind' => 'new_card', 'summary' => 'card 42'])."\n");

        // Peek without advancing → the next run still surfaces it.
        $this->artisan('bridge:inbox', ['--hook-format' => 'plain', '--no-cursor-advance' => true])
            ->expectsOutputToContain('card 42')
            ->assertExitCode(0);
        $this->assertFileDoesNotExist($this->dir.'/state/inbox-seen.json');

        $this->artisan('bridge:inbox', ['--hook-format' => 'plain'])
            ->expectsOutputToContain('card 42')
            ->assertExitCode(0);
    }

    public function test_stats_agent_flag_scopes_metrics(): void
    {
        $event = $this->event();
        AgentDispatch::create(['webhook_event_id' => $event->id, 'agent_name' => 'pm', 'processed_at' => now()]);
        AgentDispatch::create(['webhook_event_id' => $event->id, 'agent_name' => 'backend', 'error_message' => 'boom']);

        $this->artisan('bridge:stats', ['--agent' => 'pm'])
            ->expectsOutputToContain('[pm]')
            ->assertExitCode(0);
    }

    public function test_inbox_build_output_envelope_logic(): void
    {
        $cmd = new InboxCommand;
        $lines = [['id' => 'x', 'kind' => 'new_card', 'summary' => 'hi']];

        $this->assertStringNotContainsString('hookSpecificOutput', $cmd->buildOutput($lines, 'plain', 'SessionStart'));

        $wrapped = $cmd->buildOutput($lines, 'claude-code', 'PreToolUse');
        $this->assertStringContainsString('"hookSpecificOutput"', $wrapped);
        $this->assertStringContainsString('"hookEventName":"PreToolUse"', $wrapped);

        // auto: wrap only for additionalContext-supporting events.
        $this->assertStringContainsString('hookSpecificOutput', $cmd->buildOutput($lines, 'auto', 'SessionStart'));
        $this->assertStringNotContainsString('hookSpecificOutput', $cmd->buildOutput($lines, 'auto', 'Stop'));
        $this->assertStringNotContainsString('hookSpecificOutput', $cmd->buildOutput($lines, 'auto', null));
    }

    public function test_replay_command_constructs_without_resolving_dispatch_service(): void
    {
        // #2054: ReplayCommand must NOT constructor-inject DispatchService — its
        // bind reads every agent YAML and console bootstrap instantiates every
        // command, so injecting it would make one malformed YAML crash EVERY
        // artisan command (incl. bridge:check). Constructing the command must
        // not touch the config / resolve DispatchService.
        config(['bridge.config_dir' => '/nonexistent-'.uniqid()]);

        $cmd = new ReplayCommand;

        $this->assertInstanceOf(ReplayCommand::class, $cmd);
    }
}
