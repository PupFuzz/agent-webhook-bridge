<?php

namespace Tests\Feature\Handlers;

use App\Bridge\Dispatch\ReactionTarget;
use App\Bridge\Handlers\KanbanDependabotCardHandler;
use App\Bridge\Support\AgentConfig;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * ⚠ EVERY `getCard` FIXTURE HERE CARRIES `board_id` (DL-298, card#7211), and it is not
 * decoration: `cardsForRepo` now re-checks each row it read against the mapped board and
 * refuses one that does not name it, so a row without the field is refused like a foreign
 * one. Kanban returns `board_id` on every task row, so a fixture omitting it was never a
 * realistic response — the mapped-board leg belongs to
 * `tests/Feature/Writeback/ResolvedRowBoardGuardTest.php`, which owns the foreign-row
 * control and its paired presence witness.
 */
class KanbanDependabotCardHandlerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/dbcard-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/kanban');
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => [
                'board_id' => 8,
                'stages' => ['opened' => 50, 'merged' => 52, 'merged_to_main' => 53, 'closed_unmerged' => 49],
                'create_dependabot_cards' => true,
            ]],
        ]));
        File::put($this->dir.'/kanban/writeback-token', 'wb-token');
        chmod($this->dir.'/kanban/writeback-token', 0o600);
        config([
            'bridge.config_dir' => $this->dir,
            'bridge.secret_dir' => $this->dir,
            'bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3',
            // Fakes the scan correlation path; pin scan (default is now `ref`, DL-031).
            'bridge.writeback.correlation' => 'scan',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private const ALERT_URL = 'http://127.0.0.1:9936/';

    /**
     * setUp's mapping WITH an alert channel. This handler had no notifier wiring of any
     * kind before card#5968, so none of its refusal arms could signal.
     */
    private function writeWritebackWithAlert(): void
    {
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'alert_channel' => ['url' => self::ALERT_URL],
            'mappings' => ['owner/repo' => [
                'board_id' => 8,
                'stages' => ['opened' => 50, 'merged' => 52, 'merged_to_main' => 53, 'closed_unmerged' => 49],
                'create_dependabot_cards' => true,
            ]],
        ]));
    }

    private function isAlertPush(Request $r): bool
    {
        return $r->method() === 'POST' && str_starts_with($r->url(), self::ALERT_URL);
    }

    private function handle(string $outcome, int $pr = 42): void
    {
        (new KanbanDependabotCardHandler)->handle(
            ReactionTarget::make('kanban_dependabot_card', "pr-{$pr}", payload: [
                'repo' => 'owner/repo', 'outcome' => $outcome, 'pr_number' => $pr,
                'pr_title' => 'chore(deps): Bump x from 1 to 2', 'pr_url' => 'https://github.com/owner/repo/pull/'.$pr,
            ]),
            AgentConfig::fromArray('prod-agent', ['identity' => ['kanban_user_id' => 1], 'subscriptions' => []]),
        );
    }

    /**
     * The DL-328 rename target — the retitle leg's own entry point. `name_from` is the
     * title the PR carried BEFORE the edit, i.e. the string the bridge stamped on the card
     * at birth; `pr_title` is what it carries now.
     */
    private function handleRename(string $nameFrom, string $title, int $pr = 42): void
    {
        (new KanbanDependabotCardHandler)->handle(
            ReactionTarget::make('kanban_dependabot_card', "pr-{$pr}", payload: [
                'repo' => 'owner/repo', 'outcome' => KanbanDependabotCardHandler::RENAMED_OUTCOME, 'pr_number' => $pr,
                'pr_title' => $title, 'pr_url' => 'https://github.com/owner/repo/pull/'.$pr,
                'name_from' => $nameFrom,
            ]),
            AgentConfig::fromArray('prod-agent', ['identity' => ['kanban_user_id' => 1], 'subscriptions' => []]),
        );
    }

    /**
     * A correlated dependabot card on the mapped board carrying $name. `$boardId` is the
     * ROW's own spelling of its board — the accepted interval (DL-292) admits the numeric
     * string, which is what lets a card#7212 record be forced apart from the config value.
     */
    private function fakeCardNamed(string $name, int|string $boardId = 8): void
    {
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 7, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42]]]]),
            '*/tasks/7.json' => Http::response(['data' => ['id' => 7, 'board_id' => $boardId, 'name' => $name, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42, 'pr_url' => 'https://github.com/owner/repo/pull/42']]]),
        ]);
    }

    public function test_retitle_restamps_a_card_whose_name_is_still_the_one_the_bridge_stamped(): void
    {
        // The live incident (roundtable 212): dependabot retitled the PR 45 minutes before
        // merging it, and the card kept asserting a version that never shipped. The retitle
        // here is a DOWNGRADE (7.0.2 → 6.0.3) — the direction a fix assuming monotonic
        // version bumps would get wrong, and the direction the reported drift ran.
        $this->fakeCardNamed('chore(deps): Bump typescript from 5.9.0 to 7.0.2');

        $this->handleRename(
            'chore(deps): Bump typescript from 5.9.0 to 7.0.2',
            'chore(deps): Bump typescript from 5.9.0 to 6.0.3',
        );

        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), '/tasks/7.json')
            && $r['name'] === 'chore(deps): Bump typescript from 5.9.0 to 6.0.3'
            && ! isset($r['task'])                     // DL-219: flat field write
            && $r->data() === ['name' => 'chore(deps): Bump typescript from 5.9.0 to 6.0.3']);
        // A retitle writes the name and NOTHING else: no column move, no archive, no create.
        Http::assertNotSent(fn ($r) => $r->method() === 'PATCH' && (isset($r['workflow_stage_id']) || isset($r['_action'])));
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_retitle_restamps_every_matching_card_of_a_create_race(): void
    {
        // ⚑ THE >1-CARD RULING, pinned (DL-328 Decision 5): a create race leaves two cards
        // carrying the SAME bridge-stamped name, so both are the bridge's and both are
        // restamped. Restamping only a survivor would leave the twin asserting the version
        // that never shipped — this leg's whole defect, re-minted on the duplicate — and
        // this arm deliberately does not collapse: retiring a duplicate belongs to the move
        // path (DL-198), which is where the survivor is chosen.
        $from = 'chore(deps): Bump typescript from 5.9.0 to 7.0.2';
        $to = 'chore(deps): Bump typescript from 5.9.0 to 6.0.3';
        $row = fn (int $id, string $name) => ['id' => $id, 'board_id' => 8, 'name' => $name, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42, 'pr_url' => 'https://github.com/owner/repo/pull/42']];
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [
                ['id' => 7, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42]],
                ['id' => 9, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42]],
            ]]),
            '*/tasks/7.json' => Http::response(['data' => $row(7, $from)]),
            '*/tasks/9.json' => Http::response(['data' => $row(9, $from)]),
        ]);

        $this->handleRename($from, $to);

        foreach ([7, 9] as $id) {
            Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), "/tasks/{$id}.json")
                && $r->data() === ['name' => $to]);
        }
        // Name-only on BOTH: no collapse rides along, so neither twin is archived here.
        Http::assertNotSent(fn ($r) => $r->method() === 'PATCH' && isset($r['_action']));
    }

    public function test_retitle_leaves_a_human_renamed_card_alone(): void
    {
        // ⭐ THE CONTROL, and without it this change is indistinguishable from the naive
        // "always overwrite the name from the upstream title" fix that destroys a human
        // edit on the next webhook. The card's name differs from the title the bridge
        // stamped (`name_from`) by exactly the human's edit ⇒ the bridge does not own it
        // ⇒ NO write at all. Same event, same card, same everything else as the test above.
        $this->fakeCardNamed('typescript bump — HOLD, breaks the build (see #221)');

        $this->handleRename(
            'chore(deps): Bump typescript from 5.9.0 to 7.0.2',
            'chore(deps): Bump typescript from 5.9.0 to 6.0.3',
        );

        Http::assertNotSent(fn ($r) => in_array($r->method(), ['PATCH', 'POST'], true));
    }

    public function test_retitle_leaves_a_card_whose_name_differs_by_one_character(): void
    {
        // The test is BYTE-equality, not resemblance: a name that merely looks machine-made
        // is not evidence the machine wrote it. One trailing space is the whole difference.
        $this->fakeCardNamed('chore(deps): Bump typescript from 5.9.0 to 7.0.2 ');

        $this->handleRename(
            'chore(deps): Bump typescript from 5.9.0 to 7.0.2',
            'chore(deps): Bump typescript from 5.9.0 to 6.0.3',
        );

        Http::assertNotSent(fn ($r) => in_array($r->method(), ['PATCH', 'POST'], true));
    }

    public function test_retitle_with_no_correlated_card_writes_nothing(): void
    {
        // Never tracked ⇒ nothing to restamp, and emphatically NOT a create: the retitle
        // leg only ever corrects a name that already exists.
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => []])]);

        $this->handleRename('chore(deps): Bump x from 1 to 2', 'chore(deps): Bump x from 1 to 3');

        Http::assertNotSent(fn ($r) => in_array($r->method(), ['PATCH', 'POST'], true));
    }

    public function test_retitle_with_a_malformed_payload_alerts_and_writes_nothing(): void
    {
        // A rename target with no `name_from` carries no ownership evidence — a
        // deterministic upstream bug, so it alerts + no-ops rather than writing the name
        // it cannot justify (and rather than throwing into a redelivery storm).
        $this->writeWritebackWithAlert();
        Http::fake([
            self::ALERT_URL.'*' => Http::response('', 204),
            '*/tasks/search.json*' => Http::response(['data' => []]),
        ]);

        (new KanbanDependabotCardHandler)->handle(
            ReactionTarget::make('kanban_dependabot_card', 'pr-42', payload: [
                'repo' => 'owner/repo', 'outcome' => KanbanDependabotCardHandler::RENAMED_OUTCOME, 'pr_number' => 42,
                'pr_title' => 'chore(deps): Bump x from 1 to 3', 'pr_url' => 'https://github.com/owner/repo/pull/42',
            ]),
            AgentConfig::fromArray('prod-agent', ['identity' => ['kanban_user_id' => 1], 'subscriptions' => []]),
        );

        Http::assertSent(fn (Request $r) => $this->isAlertPush($r)
            && $r['reason'] === 'dependabot_card_rename_payload_invalid'
            && $r['repo'] === 'owner/repo'
            && $r['card_id'] === null
            && $r['issue_number'] === 42);
        Http::assertNotSent(fn ($r) => $r->method() === 'PATCH');
    }

    public function test_the_restamp_record_reads_the_rows_own_board_and_is_not_a_second_copy_of_the_mapped_one(): void
    {
        // card#7212 on the DL-328 write site: a record that only PROVED both keys present would
        // pass against a "fix" rendering `mapped_board` into both slots, so the two values are
        // forced apart through the accepted interval (DL-292) — the ROW says the numeric string
        // `'8'`, the CONFIG says int 8 — and pinned with `===`. Group-B, like the archive and
        // move arms: the id came from a board-scoped search, so this row is the only reading of
        // where the name write landed, and `cardsForRepo`'s DL-298 gate does not substitute for
        // it (a gate emits evidence only when it REFUSES).
        $this->fakeCardNamed('chore(deps): Bump typescript from 5.9.0 to 7.0.2', boardId: '8');
        Log::spy();

        $this->handleRename(
            'chore(deps): Bump typescript from 5.9.0 to 7.0.2',
            'chore(deps): Bump typescript from 5.9.0 to 6.0.3',
        );

        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), '/tasks/7.json') && isset($r['name']));
        Log::shouldHaveReceived('info')->withArgs(fn (string $m, array $ctx) => str_contains($m, 'restamped name from the upstream retitle')
            && $ctx['card_board'] === '8'      // the ROW's spelling, verbatim
            && $ctx['mapped_board'] === 8);    // the CONFIG's, unchanged
    }

    public function test_the_left_alone_record_also_names_the_board_the_read_landed_on(): void
    {
        // The no-write arm carries the pair too: it names a card this delivery read and made a
        // decision about, so "which board was that card on" stays answerable on the path where
        // nothing was written — the same reason the success record exists at all.
        $this->fakeCardNamed('typescript bump — HOLD, breaks the build (see #221)', boardId: '8');
        Log::spy();

        $this->handleRename(
            'chore(deps): Bump typescript from 5.9.0 to 7.0.2',
            'chore(deps): Bump typescript from 5.9.0 to 6.0.3',
        );

        Log::shouldHaveReceived('info')->withArgs(fn (string $m, array $ctx) => str_contains($m, 'not `changes.title.from`; not restamped')
            && $ctx['card_board'] === '8' && $ctx['mapped_board'] === 8);
    }

    public function test_opened_with_no_existing_card_creates_one_at_the_opened_stage(): void
    {
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => []]),
            '*/tasks.json' => Http::response(['data' => ['id' => 99]], 201),
        ]);

        $this->handle('opened');

        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/tasks.json')
            && $r['board_id'] === 8
            && $r['workflow_stage_id'] === 50
            && $r['payload']['pr_number'] === 42
            && $r['payload']['pr_url'] === 'https://github.com/owner/repo/pull/42'
            && $r['payload']['origin'] === 'dependabot'
            // Lock the payload key SET to the constant bridge:check validates (#2949),
            // so the create payload and the check's required-key list can't drift.
            && array_keys($r['payload']) === KanbanDependabotCardHandler::CREATE_PAYLOAD_KEYS
            && in_array('dependencies', $r['tags'], true)
            && in_array('triaged', $r['tags'], true));
    }

    public function test_mapping_swimlane_id_is_applied_to_a_created_card(): void
    {
        // DL-027: a per-mapping swimlane_id lands the created card in that lane.
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => [
                'board_id' => 8, 'swimlane_id' => 31,
                'stages' => ['opened' => 50, 'merged' => 52, 'merged_to_main' => 53, 'closed_unmerged' => 49],
                'create_dependabot_cards' => true,
            ]],
        ]));
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => []]),
            '*/tasks.json' => Http::response(['data' => ['id' => 99]], 201),
        ]);

        $this->handle('opened');

        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/tasks.json')
            && ($r['swimlane_id'] ?? null) === 31);
    }

    public function test_no_swimlane_id_omits_the_key_from_the_create(): void
    {
        // setUp's mapping has no swimlane_id → the POST must not carry the key at all.
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => []]),
            '*/tasks.json' => Http::response(['data' => ['id' => 99]], 201),
        ]);

        $this->handle('opened');

        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/tasks.json')
            && ! array_key_exists('swimlane_id', $r->data()));
    }

    public function test_existing_card_is_moved_not_recreated(): void
    {
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 7, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42]]]]),
            '*/tasks/7.json' => Http::response(['data' => ['id' => 7, 'board_id' => 8, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42, 'pr_url' => 'https://github.com/owner/repo/pull/42']]]),
        ]);

        $this->handle('merged');   // existing card at 50, target stage 52

        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), '/tasks/7.json') && $r['workflow_stage_id'] === 52);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_repo_attribution_is_case_insensitive(): void
    {
        // Parity with the kanban server's `source` semantics (GitHub owner/repo is
        // case-insensitive): a card whose stored pr_url differs only in CASE from
        // the event repo is still attributed to it and moved. The pre-normalizer
        // exact-string match would have dropped it (latent bug).
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 7, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42]]]]),
            '*/tasks/7.json' => Http::response(['data' => ['id' => 7, 'board_id' => 8, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42, 'pr_url' => 'https://github.com/Owner/Repo/pull/42']]]),
        ]);

        $this->handle('merged');   // event repo is owner/repo (setUp); card url is Owner/Repo

        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), '/tasks/7.json') && $r['workflow_stage_id'] === 52);
    }

    public function test_already_in_target_stage_is_a_noop(): void
    {
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 7, 'workflow_stage_id' => 52, 'payload' => ['pr_number' => 42]]]]),
            '*/tasks/7.json' => Http::response(['data' => ['id' => 7, 'board_id' => 8, 'workflow_stage_id' => 52, 'payload' => ['pr_number' => 42, 'pr_url' => 'https://github.com/owner/repo/pull/42']]]),
        ]);

        $this->handle('merged');

        Http::assertNotSent(fn ($r) => in_array($r->method(), ['PATCH', 'POST'], true));
    }

    public function test_closed_unmerged_with_no_card_creates_nothing(): void
    {
        Http::fake(['*/tasks/search.json*' => Http::response(['data' => []])]);

        $this->handle('closed_unmerged');

        Http::assertNotSent(fn ($r) => in_array($r->method(), ['PATCH', 'POST'], true));
    }

    public function test_closed_unmerged_archives_an_existing_card_not_moves_it(): void
    {
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 7, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42]]]]),
            '*/tasks/7.json' => Http::response(['data' => ['id' => 7, 'board_id' => 8, 'archived_at' => '2026-06-19T00:00:00+00:00', 'payload' => ['pr_number' => 42, 'pr_url' => 'https://github.com/owner/repo/pull/42']]]),
        ]);

        $this->handle('closed_unmerged');   // DL-161: dependabot close-unmerged retires the card

        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), '/tasks/7.json') && $r['_action'] === 'archive');
        Http::assertNotSent(fn ($r) => $r->method() === 'PATCH' && isset($r['workflow_stage_id']));   // archived, not moved
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_closed_unmerged_archives_even_with_no_closed_unmerged_stage_mapped(): void
    {
        // The fix's load-bearing case: archive needs no stage mapping, so a card
        // is retired on close even when the operator never mapped closed_unmerged.
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => [
                'board_id' => 8,
                'stages' => ['opened' => 50, 'merged' => 52, 'merged_to_main' => 53],   // NO closed_unmerged
                'create_dependabot_cards' => true,
            ]],
        ]));
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 7, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42]]]]),
            '*/tasks/7.json' => Http::response(['data' => ['id' => 7, 'board_id' => 8, 'archived_at' => '2026-06-19T00:00:00+00:00', 'payload' => ['pr_number' => 42, 'pr_url' => 'https://github.com/owner/repo/pull/42']]]),
        ]);

        $this->handle('closed_unmerged');

        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), '/tasks/7.json') && $r['_action'] === 'archive');
    }

    public function test_closed_unmerged_archive_not_confirmed_logs_and_noops_does_not_throw(): void
    {
        // A 200 whose archived_at is null (wrong-verb / contract break) is
        // deterministic — it must NOT propagate into a ~5xx retry storm. The
        // handler logs LOUD (error) and no-ops.
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 7, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42]]]]),
            '*/tasks/7.json' => Http::response(['data' => ['id' => 7, 'board_id' => 8, 'archived_at' => null, 'payload' => ['pr_number' => 42, 'pr_url' => 'https://github.com/owner/repo/pull/42']]]),
        ]);
        Log::spy();

        $this->handle('closed_unmerged');   // must not throw

        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $msg) => str_contains($msg, 'not archived'));
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_closed_unmerged_archives_all_matching_cards(): void
    {
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [
                ['id' => 7, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42]],
                ['id' => 8, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42]],
            ]]),
            '*/tasks/7.json' => Http::response(['data' => ['id' => 7, 'board_id' => 8, 'archived_at' => '2026-06-19T00:00:00+00:00', 'payload' => ['pr_number' => 42, 'pr_url' => 'https://github.com/owner/repo/pull/42']]]),
            '*/tasks/8.json' => Http::response(['data' => ['id' => 8, 'board_id' => 8, 'archived_at' => '2026-06-19T00:00:00+00:00', 'payload' => ['pr_number' => 42, 'pr_url' => 'https://github.com/owner/repo/pull/42']]]),
        ]);

        $this->handle('closed_unmerged');

        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), '/tasks/7.json') && $r['_action'] === 'archive');
        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), '/tasks/8.json') && $r['_action'] === 'archive');
    }

    public function test_create_collapses_a_concurrently_created_duplicate(): void
    {
        // The create-or-move race (#2982): the pre-create correlate sees no card,
        // but by the time the create returns a concurrent delivery for the same PR
        // has also created one. The post-create re-correlate now sees BOTH → the
        // handler keeps the lowest id (99) and archives the racer (100).
        $prUrl = 'https://github.com/owner/repo/pull/42';
        Http::fake([
            '*/tasks/search.json*' => Http::sequence()
                ->push(['data' => []])                                  // pre-create correlate: empty → create
                ->push(['data' => [                                     // post-create re-correlate: the race surfaced
                    ['id' => 100, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42]],
                    ['id' => 99, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42]],
                ]]),
            '*/tasks.json' => Http::response(['data' => ['id' => 99]], 201),
            // Both cards are attributed to THIS repo via pr_url (the cross-repo guard).
            '*/tasks/99.json' => Http::response(['data' => ['id' => 99, 'board_id' => 8, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42, 'pr_url' => $prUrl]]]),
            '*/tasks/100.json' => Http::response(['data' => ['id' => 100, 'board_id' => 8, 'workflow_stage_id' => 50, 'archived_at' => '2026-06-20T00:00:00+00:00', 'payload' => ['pr_number' => 42, 'pr_url' => $prUrl]]]),
        ]);

        $this->handle('opened');

        // The racer (higher id) is archived; the survivor (lowest id) is never PATCH-archived.
        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), '/tasks/100.json') && ($r['_action'] ?? null) === 'archive');
        Http::assertNotSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), '/tasks/99.json'));
    }

    public function test_create_does_not_collapse_a_same_pr_number_card_from_another_repo(): void
    {
        // Cross-repo guard: on a board shared across repos, a same-numbered PR in
        // ANOTHER repo correlates by bare PR number but is a DISTINCT card. The
        // handler attributes each card by its pr_url and must NOT archive the
        // foreign-repo card (id 100, repo `other/repo`).
        Http::fake([
            '*/tasks/search.json*' => Http::sequence()
                ->push(['data' => []])
                ->push(['data' => [
                    ['id' => 100, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42]],
                    ['id' => 99, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42]],
                ]]),
            '*/tasks.json' => Http::response(['data' => ['id' => 99]], 201),
            '*/tasks/99.json' => Http::response(['data' => ['id' => 99, 'board_id' => 8, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42, 'pr_url' => 'https://github.com/owner/repo/pull/42']]]),
            '*/tasks/100.json' => Http::response(['data' => ['id' => 100, 'board_id' => 8, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42, 'pr_url' => 'https://github.com/other/repo/pull/42']]]),
        ]);

        $this->handle('opened');   // our repo is owner/repo (setUp)

        // Only our card (99) survives uncollapsed; the foreign-repo card (100) is untouched.
        Http::assertNotSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), '/tasks/100.json'));
        Http::assertNotSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), '/tasks/99.json'));
    }

    public function test_no_duplicate_after_create_archives_nothing(): void
    {
        // The common path: the post-create re-correlate sees only the card we made
        // → no archive, no extra writes beyond the create.
        Http::fake([
            '*/tasks/search.json*' => Http::sequence()
                ->push(['data' => []])
                ->push(['data' => [['id' => 99, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42]]]]),
            '*/tasks.json' => Http::response(['data' => ['id' => 99]], 201),
            '*/tasks/99.json' => Http::response(['data' => ['id' => 99, 'board_id' => 8, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42, 'pr_url' => 'https://github.com/owner/repo/pull/42']]]),
        ]);

        $this->handle('opened');

        Http::assertNotSent(fn ($r) => $r->method() === 'PATCH');   // nothing archived/moved
    }

    public function test_move_path_collapses_pre_existing_duplicates_and_moves_the_survivor(): void
    {
        // Self-heal: duplicates minted before this guard shipped are collapsed on
        // the PR's next non-terminal event. merge with two correlated cards → the
        // racer (id 8) is archived, only the survivor (id 7) advances to the stage.
        $prUrl = 'https://github.com/owner/repo/pull/42';
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [
                ['id' => 7, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42]],
                ['id' => 8, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42]],
            ]]),
            '*/tasks/7.json' => Http::response(['data' => ['id' => 7, 'board_id' => 8, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42, 'pr_url' => $prUrl]]]),
            '*/tasks/8.json' => Http::response(['data' => ['id' => 8, 'board_id' => 8, 'workflow_stage_id' => 50, 'archived_at' => '2026-06-20T00:00:00+00:00', 'payload' => ['pr_number' => 42, 'pr_url' => $prUrl]]]),
        ]);

        $this->handle('merged');   // target stage 52

        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), '/tasks/8.json') && ($r['_action'] ?? null) === 'archive');
        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), '/tasks/7.json') && ($r['workflow_stage_id'] ?? null) === 52);
        // The archived duplicate is never moved.
        Http::assertNotSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), '/tasks/8.json') && isset($r['workflow_stage_id']));
    }

    public function test_card_with_unparseable_pr_url_is_never_archived(): void
    {
        // Conservative contract (cross-repo guard): a card whose repo can't be
        // attributed (absent/malformed pr_url) is DROPPED — never archived or moved
        // on a guess. Pins cardRepo's null-drop so a future classifier that stops
        // populating pr_url can't make the handler start archiving on a bad key.
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 7, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42]]]]),
            '*/tasks/7.json' => Http::response(['data' => ['id' => 7, 'board_id' => 8, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42, 'pr_url' => '']]]),
        ]);

        $this->handle('closed_unmerged');

        Http::assertNotSent(fn ($r) => $r->method() === 'PATCH');   // unattributable → dropped, not archived
    }

    public function test_opt_out_mapping_ignores_the_target(): void
    {
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => ['board_id' => 8, 'stages' => ['opened' => 50]]],   // no create_dependabot_cards
        ]));
        Http::fake();

        $this->handle('opened');

        Http::assertNothingSent();
    }

    // ---- #75 / card-4485: card_id_tag_template ----

    public function test_card_id_tag_template_stamps_a_rendered_id_tag_on_create(): void
    {
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/repo' => [
                'board_id' => 8,
                'stages' => ['opened' => 50, 'merged' => 52, 'merged_to_main' => 53, 'closed_unmerged' => 49],
                'create_dependabot_cards' => true,
                'card_id_tag_template' => 'id:DEV-pr-{n}',
            ]],
        ]));
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => []]),
            '*/tasks.json' => Http::response(['data' => ['id' => 99]], 201),
        ]);

        $this->handle('opened', 166);

        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/tasks.json')
            && in_array('id:DEV-pr-166', $r['tags'], true)
            && in_array('dependencies', $r['tags'], true)
            && in_array('triaged', $r['tags'], true));
    }

    public function test_card_id_tag_template_supports_repo_placeholder(): void
    {
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['AIMLA/magento' => [
                'board_id' => 8,
                'stages' => ['opened' => 50, 'merged' => 52, 'merged_to_main' => 53, 'closed_unmerged' => 49],
                'create_dependabot_cards' => true,
                'card_id_tag_template' => 'id:dep:{repo}#{n}',
            ]],
        ]));
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => []]),
            '*/tasks.json' => Http::response(['data' => ['id' => 99]], 201),
        ]);

        (new KanbanDependabotCardHandler)->handle(
            ReactionTarget::make('kanban_dependabot_card', 'pr-166', payload: [
                'repo' => 'AIMLA/magento', 'outcome' => 'opened', 'pr_number' => 166,
                'pr_title' => 'chore(deps): Bump x from 1 to 2', 'pr_url' => 'https://github.com/AIMLA/magento/pull/166',
            ]),
            AgentConfig::fromArray('prod-agent', ['identity' => ['kanban_user_id' => 1], 'subscriptions' => []]),
        );

        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/tasks.json')
            && in_array('id:dep:magento#166', $r['tags'], true));
    }

    public function test_the_repo_placeholder_renders_the_configured_spelling_not_the_payloads(): void
    {
        // card#7124 review, the sibling of the promote leg's token probe found in the same
        // audit pass. `{repo}` renders into a PERSISTED `id:` tag an external tag-keyed
        // reader correlates on. Until DL-293 this line was reachable only when the payload
        // spelling equalled the key, so the tag's casing was never a choice; now it is, and
        // the operator's own spelling is the one that belongs in text they declared.
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['AIMLA/Magento' => [
                'board_id' => 8,
                'stages' => ['opened' => 50, 'merged' => 52, 'merged_to_main' => 53, 'closed_unmerged' => 49],
                'create_dependabot_cards' => true,
                'card_id_tag_template' => 'id:dep:{repo}#{n}',
            ]],
        ]));
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => []]),
            '*/tasks.json' => Http::response(['data' => ['id' => 99]], 201),
        ]);

        (new KanbanDependabotCardHandler)->handle(
            ReactionTarget::make('kanban_dependabot_card', 'pr-166', payload: [
                'repo' => 'aimla/magento', 'outcome' => 'opened', 'pr_number' => 166,
                'pr_title' => 'chore(deps): Bump x from 1 to 2', 'pr_url' => 'https://github.com/aimla/magento/pull/166',
            ]),
            AgentConfig::fromArray('prod-agent', ['identity' => ['kanban_user_id' => 1], 'subscriptions' => []]),
        );

        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/tasks.json')
            && in_array('id:dep:Magento#166', $r['tags'], true));
        Http::assertNotSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/tasks.json')
            && in_array('id:dep:magento#166', $r['tags'], true));
    }

    public function test_no_card_id_tag_template_leaves_tags_back_compat(): void
    {
        // setUp's mapping carries no card_id_tag_template — the tags must be
        // exactly ['dependencies', 'triaged'], no id: tag added (back-compat).
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => []]),
            '*/tasks.json' => Http::response(['data' => ['id' => 99]], 201),
        ]);

        $this->handle('opened');

        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/tasks.json')
            && $r['tags'] === ['dependencies', 'triaged']);
    }

    // --- card#5968 / DL-285: this handler's refusals are keyed by the PULL REQUEST. The
    //     body carries it in `issue_number` — GitHub numbers issues and PRs in one
    //     space, so the signal gained one field rather than two. ---

    public function test_kanban_4xx_alerts_with_the_pr_number_and_a_null_card_id(): void
    {
        $this->writeWritebackWithAlert();
        Log::spy();
        Http::fake([
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            '*/tasks/search.json*' => Http::response(['data' => []]),
            '*/tasks.json' => Http::response(['error' => 'unknown payload key'], 422),
        ]);

        $this->handle('opened');

        Http::assertSent(fn (Request $r) => $this->isAlertPush($r)
            && $r['type'] === 'writeback_move_failed'
            && $r['reason'] === 'dependabot_card_4xx'
            && $r['repo'] === 'owner/repo'
            && $r['outcome'] === 'dependabot_card'
            && $r['card_id'] === null
            && $r['issue_number'] === 42);
        // withArgs BEFORE once(): the empty correlation read emits its own DL-026 0-card
        // warning in this fixture, so the count is scoped to the refusal's own line.
        Log::shouldHaveReceived('warning')->withArgs(fn (string $m, array $ctx) => str_contains($m, 'kanban refused (4xx)')
            && $ctx['status'] === 422)->once();
    }

    public function test_the_alert_outcome_is_synthetic_not_the_events_pr_outcome(): void
    {
        // The event's own outcome (opened/merged/closed_unmerged) would split the dedup
        // marker per PR state and re-alert one misconfiguration on each — so the tuple
        // carries a constant naming the reaction instead.
        $this->writeWritebackWithAlert();
        Http::fake([
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            '*/tasks/search.json*' => Http::response(['data' => []]),
            '*/tasks.json' => Http::response(['error' => 'unknown payload key'], 422),
        ]);

        $this->handle('merged');

        Http::assertSent(fn (Request $r) => $this->isAlertPush($r) && $r['outcome'] === 'dependabot_card');
        Http::assertNotSent(fn (Request $r) => $this->isAlertPush($r) && $r['outcome'] === 'merged');
    }

    public function test_malformed_payload_alerts_on_the_empty_repo_key(): void
    {
        $this->writeWritebackWithAlert();
        Http::fake([self::ALERT_URL.'*' => Http::response(['ok' => true])]);

        (new KanbanDependabotCardHandler)->handle(
            ReactionTarget::make('kanban_dependabot_card', 'pr-42', payload: ['repo' => null, 'outcome' => 'opened', 'pr_number' => 42]),
            AgentConfig::fromArray('prod-agent', ['identity' => ['kanban_user_id' => 1], 'subscriptions' => []]),
        );

        Http::assertSent(fn (Request $r) => $this->isAlertPush($r)
            && $r['reason'] === 'dependabot_card_payload_invalid'
            && $r['repo'] === ''
            && $r['card_id'] === null
            && $r['issue_number'] === 42);
    }

    public function test_absent_writeback_json_still_logs_and_cannot_push(): void
    {
        File::delete($this->dir.'/writeback.json');
        Log::spy();
        Http::fake([self::ALERT_URL.'*' => Http::response(['ok' => true])]);

        $this->handle('opened');

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $m) => str_contains($m, 'writeback not configured'));
        Http::assertNotSent(fn (Request $r) => $this->isAlertPush($r));
    }

    public function test_a_5xx_still_throws_and_never_alerts(): void
    {
        $this->writeWritebackWithAlert();
        Http::fake([
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            '*/tasks/search.json*' => Http::response(['data' => []]),
            '*/tasks.json' => Http::response(['error' => 'boom'], 503),
        ]);

        try {
            $this->handle('opened');
            $this->fail('a 5xx must propagate for redelivery');
        } catch (RequestException) {
            // expected
        }
        Http::assertNotSent(fn (Request $r) => $this->isAlertPush($r));
    }

    // --- card#7212: the success record names the board the write LANDED on ---

    public function test_an_archive_records_the_cards_own_board_beside_the_mapped_one(): void
    {
        // PRESENCE on a GROUP-B arm (card#7211): this id came out of a board-scoped search, so
        // the card's board is not implied by anything upstream — reading it here is the only way
        // the record can say where the write landed. The DL-298 re-check in cardsForRepo() gates
        // the write; it emits nothing on the path it passes, which is what this record is for.
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 7, 'payload' => ['pr_number' => 42]]]]),
            '*/tasks/7.json' => Http::response(['data' => ['id' => 7, 'board_id' => 8, 'archived_at' => '2026-06-19T00:00:00+00:00', 'payload' => ['pr_number' => 42, 'pr_url' => 'https://github.com/owner/repo/pull/42']]]),
        ]);
        Log::spy();

        $this->handle('closed_unmerged');

        Log::shouldHaveReceived('info')->withArgs(fn (string $m, array $ctx) => str_contains($m, 'archived (closed-unmerged)')
            && $ctx['card_board'] === 8 && $ctx['mapped_board'] === 8);
    }

    public function test_the_archive_record_reads_the_rows_own_board_and_is_not_a_second_copy_of_the_mapped_one(): void
    {
        // ⭐⭐ THE DIVERGENCE CONTROL. A test asserting only that both keys are PRESENT passes
        // against a "fix" rendering `mapped_board` into both slots — today's defect wearing the
        // new field's name — so the two values are forced APART and pinned with `===`.
        //
        // ⛔ WHAT FORCES THEM APART HERE, and why it is no longer a foreign board. Until DL-298
        // this arm ran NO membership compare, so a board-8 mapping genuinely archived a board-12
        // card and that was the divergence. `cardsForRepo()` now re-checks the row through
        // MappedBoardGuard (card#7211), so a foreign row never reaches the archive — which is
        // why the control moves onto the accepted INTERVAL (DL-292), exactly as the token-path
        // arms already did: `is_numeric` + `(int)` admits the numeric STRING '8' onto a mapped
        // board of 8, so a reading of the ROW gives '8' where an echo of the mapping gives int 8.
        // The gate closing the foreign-board case does not retire this leg — the gate emits
        // evidence only when it REFUSES, and this record is what answers "did this ever happen?"
        // on the path the gate passes.
        //
        // ⛔ Seen to fail: with the success arm echoing the mapped board (or, as it did,
        // logging no board at all) this assertion reds — `card_board` is absent, or int 8.
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 7, 'payload' => ['pr_number' => 42]]]]),
            '*/tasks/7.json' => Http::response(['data' => ['id' => 7, 'board_id' => '8', 'archived_at' => '2026-06-19T00:00:00+00:00', 'payload' => ['pr_number' => 42, 'pr_url' => 'https://github.com/owner/repo/pull/42']]]),
        ]);
        Log::spy();

        $this->handle('closed_unmerged');

        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), '/tasks/7.json') && $r['_action'] === 'archive');
        Log::shouldHaveReceived('info')->withArgs(fn (string $m, array $ctx) => str_contains($m, 'archived (closed-unmerged)')
            && $ctx['card_board'] === '8'      // the ROW's spelling, verbatim
            && $ctx['mapped_board'] === 8);    // the CONFIG's, unchanged
    }

    public function test_the_move_record_reads_the_rows_own_board_and_is_not_a_second_copy_of_the_mapped_one(): void
    {
        // The archive arm's twin, and a separate site: the survivor is resolved by the same
        // search and moved. Same divergence through the accepted interval, same `===` pinning.
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 7, 'payload' => ['pr_number' => 42]]]]),
            '*/tasks/7.json' => Http::response(['data' => ['id' => 7, 'board_id' => '8', 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42, 'pr_url' => 'https://github.com/owner/repo/pull/42']]]),
        ]);
        Log::spy();

        $this->handle('merged');

        Log::shouldHaveReceived('info')->withArgs(fn (string $m, array $ctx) => $m === 'kanban_dependabot_card: moved'
            && $ctx['card_board'] === '8' && $ctx['mapped_board'] === 8);
    }

    public function test_a_collapse_archive_records_the_archived_cards_own_board_beside_the_mapped_one(): void
    {
        // PRESENCE on the SHARED collapse primitive (CardCollapse), which is where the
        // card#7212 review found the record still missing: the pair was threaded into the
        // handlers' own archive/move arms but not into the kernel they both delegate the
        // duplicate-archive to, so this write recorded NO board at all.
        $prUrl = 'https://github.com/owner/repo/pull/42';
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [
                ['id' => 7, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42]],
                ['id' => 8, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42]],
            ]]),
            '*/tasks/7.json' => Http::response(['data' => ['id' => 7, 'board_id' => 8, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42, 'pr_url' => $prUrl]]]),
            '*/tasks/8.json' => Http::response(['data' => ['id' => 8, 'board_id' => 8, 'workflow_stage_id' => 50, 'archived_at' => '2026-06-20T00:00:00+00:00', 'payload' => ['pr_number' => 42, 'pr_url' => $prUrl]]]),
        ]);
        Log::spy();

        $this->handle('merged');

        Log::shouldHaveReceived('info')->withArgs(fn (string $m, array $ctx) => str_contains($m, 'archived duplicate card sharing the same correlation key')
            && $ctx['card_id'] === 8 && $ctx['card_board'] === 8 && $ctx['mapped_board'] === 8);
    }

    public function test_the_collapse_archive_record_reads_the_rows_own_board_not_a_copy_of_the_mapped_one(): void
    {
        // ⭐⭐ THE DIVERGENCE CONTROL for the collapse kernel, and the arm MOST likely to
        // touch a foreign card: the ids come from `correlatePr()` — a board-scoped search —
        // and the collapse fires exactly when that search returned MORE rows than expected.
        // `cardsForRepo()` filters on the repo parsed from the card's `pr_url`; since DL-298
        // it filters on the BOARD too, so the foreign-board duplicate is now refused rather
        // than archived and the divergence is forced through the accepted interval instead
        // (see the archive arm above for why that substitution is the right one).
        //
        // ⛔ Seen to fail: before the mapping was threaded into CardCollapse::toSurvivor()
        // this record carried neither key, so `$ctx['card_board']` was undefined.
        $prUrl = 'https://github.com/owner/repo/pull/42';
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [
                ['id' => 7, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42]],
                ['id' => 8, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42]],
            ]]),
            '*/tasks/7.json' => Http::response(['data' => ['id' => 7, 'board_id' => 8, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42, 'pr_url' => $prUrl]]]),
            '*/tasks/8.json' => Http::response(['data' => ['id' => 8, 'board_id' => '8', 'workflow_stage_id' => 50, 'archived_at' => '2026-06-20T00:00:00+00:00', 'payload' => ['pr_number' => 42, 'pr_url' => $prUrl]]]),
        ]);
        Log::spy();

        $this->handle('merged');

        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_contains($r->url(), '/tasks/8.json') && ($r['_action'] ?? null) === 'archive');
        Log::shouldHaveReceived('info')->withArgs(fn (string $m, array $ctx) => str_contains($m, 'archived duplicate card sharing the same correlation key')
            && $ctx['card_board'] === '8'      // the ROW's spelling, verbatim
            && $ctx['mapped_board'] === 8);    // the CONFIG's, unchanged
    }

    public function test_a_collapse_archive_that_did_not_take_records_the_pair_too(): void
    {
        // THE ONE RULE (WritebackSuccessBoardRecordTest's docblock owns it): the pair goes on
        // every record reporting a write kanban ACCEPTED, including the one reporting that an
        // accepted archive did NOT take — a 200-not-archived on a FOREIGN card is exactly the
        // cross-board touch this record exists to make visible, and it is the loudest arm.
        $prUrl = 'https://github.com/owner/repo/pull/42';
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [
                ['id' => 7, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42]],
                ['id' => 8, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42]],
            ]]),
            '*/tasks/7.json' => Http::response(['data' => ['id' => 7, 'board_id' => 8, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42, 'pr_url' => $prUrl]]]),
            // archived_at absent on the PATCH response ⇒ the 200-that-didn't-archive branch.
            '*/tasks/8.json' => Http::response(['data' => ['id' => 8, 'board_id' => '8', 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42, 'pr_url' => $prUrl]]]),
        ]);
        Log::spy();

        $this->handle('merged');

        Log::shouldHaveReceived('error')->withArgs(fn (string $m, array $ctx) => str_contains($m, 'duplicate archive returned 200 but the card is not archived')
            && $ctx['card_board'] === '8' && $ctx['mapped_board'] === 8);
    }

    /**
     * card#8454 / DL-335 — the ARCHIVE arm. Before this guard a closed-unmerged dependabot
     * PR retired a card a human had parked, while `bridge:reconcile` and the release-promote
     * sweep both skipped it: the backstop could not even see what the event path had taken
     * off the board. The pinned card here carries a `block_reason`, one of PinGuard's two
     * signals; the tag half rides the move leg below.
     */
    public function test_closed_unmerged_does_not_archive_a_pinned_card(): void
    {
        $this->writeWritebackWithAlert();
        Http::fake([
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 7, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42]]]]),
            '*/tasks/7.json' => Http::response(['data' => ['id' => 7, 'board_id' => 8, 'block_reason' => 'holding this one', 'payload' => ['pr_number' => 42, 'pr_url' => 'https://github.com/owner/repo/pull/42']]]),
        ]);
        Log::spy();

        $this->handle('closed_unmerged');

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
        Http::assertSent(fn (Request $r) => $this->isAlertPush($r)
            && $r['type'] === 'writeback_move_failed'
            && $r['reason'] === 'pinned_no_automove'
            && $r['repo'] === 'owner/repo'
            && $r['outcome'] === 'dependabot_card'
            && $r['card_id'] === 7
            && $r['issue_number'] === 42);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $m, array $ctx) => str_contains($m, 'archive refused — card is pinned')
            && $ctx['card_id'] === 7 && $ctx['card_board'] === 8 && $ctx['mapped_board'] === 8)->once();
    }

    /**
     * The control for the leg above: the SAME fixture with no pin signal archives, so the
     * assertion is about the pin and not about a fixture that never reaches the write.
     */
    public function test_the_same_closed_unmerged_fixture_without_a_pin_is_archived(): void
    {
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 7, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42]]]]),
            '*/tasks/7.json' => Http::response(['data' => ['id' => 7, 'board_id' => 8, 'block_reason' => '', 'archived_at' => '2026-06-19T00:00:00+00:00', 'payload' => ['pr_number' => 42, 'pr_url' => 'https://github.com/owner/repo/pull/42']]]),
        ]);

        $this->handle('closed_unmerged');

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_contains($r->url(), '/tasks/7.json') && $r['_action'] === 'archive');
    }

    /**
     * card#8454 / DL-335 — the MOVE arm, on the other PinGuard signal (`no-automove`). The
     * refusal is taken where the move handler takes its own: AFTER the already-in-target-stage
     * no-op, so a pinned card that needs no write raises no alert.
     */
    public function test_a_pinned_card_is_not_moved_to_the_outcomes_stage(): void
    {
        $this->writeWritebackWithAlert();
        Http::fake([
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 7, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42]]]]),
            '*/tasks/7.json' => Http::response(['data' => ['id' => 7, 'board_id' => 8, 'workflow_stage_id' => 50, 'tags' => ['no-automove'], 'payload' => ['pr_number' => 42, 'pr_url' => 'https://github.com/owner/repo/pull/42']]]),
        ]);
        Log::spy();

        $this->handle('merged');   // target stage 52, card sits at 50

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
        Http::assertSent(fn (Request $r) => $this->isAlertPush($r)
            && $r['reason'] === 'pinned_no_automove'
            && $r['outcome'] === 'dependabot_card'
            && $r['card_id'] === 7
            && $r['issue_number'] === 42);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $m, array $ctx) => str_contains($m, 'move refused — card is pinned')
            && $ctx['card_id'] === 7)->once();
    }

    /**
     * The control for the leg above: the SAME fixture with an unrecognised tag moves.
     */
    public function test_the_same_move_fixture_without_a_pin_is_moved(): void
    {
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 7, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42]]]]),
            '*/tasks/7.json' => Http::response(['data' => ['id' => 7, 'board_id' => 8, 'workflow_stage_id' => 50, 'tags' => ['dependencies'], 'payload' => ['pr_number' => 42, 'pr_url' => 'https://github.com/owner/repo/pull/42']]]),
        ]);

        $this->handle('merged');

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_contains($r->url(), '/tasks/7.json') && ($r['workflow_stage_id'] ?? null) === 52);
    }

    /**
     * A pinned card ALREADY in the outcome's stage raises no refusal signal: there was no
     * write to refuse, and an alert there would be a false permanent-failure report.
     */
    public function test_a_pinned_card_already_in_the_target_stage_does_not_alert(): void
    {
        $this->writeWritebackWithAlert();
        Http::fake([
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            '*/tasks/search.json*' => Http::response(['data' => [['id' => 7, 'workflow_stage_id' => 52, 'payload' => ['pr_number' => 42]]]]),
            '*/tasks/7.json' => Http::response(['data' => ['id' => 7, 'board_id' => 8, 'workflow_stage_id' => 52, 'tags' => ['no-automove'], 'payload' => ['pr_number' => 42, 'pr_url' => 'https://github.com/owner/repo/pull/42']]]),
        ]);

        $this->handle('merged');   // target stage 52 — already there

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
        Http::assertNotSent(fn (Request $r) => $this->isAlertPush($r));
    }

    /**
     * card#8523 / DL-340 — THE R1 REPRODUCTION from card#8454 comment 2258, now the guard.
     * DL-335 refused the survivor's MOVE on a pinned card but left `CardCollapse` pin-blind,
     * and `handle()` calls it BEFORE that consult — so on a create race (two cards for one
     * repo+PR) the pinned TWIN was archived anyway and only the survivor's move was withheld.
     * The reviewer measured it: `PATCH /tasks/9.json {"_action":"archive"}` went out against
     * the post-DL-335 handler.
     *
     * ⭐ The fix is in the PRIMITIVE, not in this handler's call ORDER (canon #5): a
     * pre-collapse consult would have covered this caller and left the coord create leg and
     * the board tool, and it would have refused the WHOLE delivery — DL-335 alternative (b),
     * which the operator did not ask for. Per-card inside the loop means the pinned twin
     * survives and the unpinned survivor still moves, which is what a hold on ONE card means.
     */
    public function test_a_pinned_duplicate_twin_is_not_archived_by_the_collapse(): void
    {
        $this->writeWritebackWithAlert();
        $prUrl = 'https://github.com/owner/repo/pull/42';
        Http::fake([
            self::ALERT_URL.'*' => Http::response(['ok' => true]),
            '*/tasks/search.json*' => Http::response(['data' => [
                ['id' => 7, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42]],
                ['id' => 9, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42]],
            ]]),
            '*/tasks/7.json' => Http::response(['data' => ['id' => 7, 'board_id' => 8, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42, 'pr_url' => $prUrl]]]),
            '*/tasks/9.json' => Http::response(['data' => ['id' => 9, 'board_id' => 8, 'workflow_stage_id' => 50, 'block_reason' => 'human parked this twin', 'tags' => ['no-automove'], 'payload' => ['pr_number' => 42, 'pr_url' => $prUrl]]]),
        ]);
        Log::spy();

        $this->handle('merged');

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH' && str_contains($r->url(), '/tasks/9.json'));
        // The survivor still moves — a hold is a property of the CARD, not of the delivery,
        // and this is the witness that the refusal is not just an inert fixture.
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_contains($r->url(), '/tasks/7.json')
            && ($r['workflow_stage_id'] ?? null) === 52);
        // The collapse's own alert outcome is the SUBSYSTEM, not this handler's synthetic
        // `dependabot_card`, so a collapse refusal and a move refusal never share a dedup
        // marker and cannot silence each other.
        Http::assertSent(fn (Request $r) => $this->isAlertPush($r)
            && $r['reason'] === 'pinned_no_automove'
            && $r['outcome'] === 'kanban_dependabot_card'
            && $r['repo'] === 'owner/repo'
            && $r['card_id'] === 9);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $m, array $ctx) => str_contains($m, 'duplicate archive refused — card is pinned')
            && $ctx['card_id'] === 9 && $ctx['survivor'] === 7 && $ctx['card_board'] === 8)->once();
    }

    /**
     * The control for the leg above, on the SAME fixture: with no pin on card 9 the collapse
     * archives it exactly as it did before, so the assertion above is about the pin and not
     * about a fixture that never reaches the write.
     */
    public function test_the_same_duplicate_fixture_without_a_pin_is_archived(): void
    {
        $prUrl = 'https://github.com/owner/repo/pull/42';
        Http::fake([
            '*/tasks/search.json*' => Http::response(['data' => [
                ['id' => 7, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42]],
                ['id' => 9, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42]],
            ]]),
            '*/tasks/7.json' => Http::response(['data' => ['id' => 7, 'board_id' => 8, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42, 'pr_url' => $prUrl]]]),
            '*/tasks/9.json' => Http::response(['data' => ['id' => 9, 'board_id' => 8, 'workflow_stage_id' => 50, 'payload' => ['pr_number' => 42, 'pr_url' => $prUrl]]]),
        ]);

        $this->handle('merged');

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_contains($r->url(), '/tasks/9.json')
            && ($r['_action'] ?? null) === 'archive');
    }
}
