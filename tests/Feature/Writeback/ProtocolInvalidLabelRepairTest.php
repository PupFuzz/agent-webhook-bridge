<?php

namespace Tests\Feature\Writeback;

use App\Bridge\Writeback\ProtocolInvalidLabelDebt;
use App\Bridge\Writeback\ProtocolInvalidLabeler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\UnattributableCommentHarness;
use Tests\TestCase;

/**
 * card#10242 / DL-419: a `protocol:invalid` write the install DECIDED on and could not land is
 * remembered, and `bridge:relabel` discharges it once the cause clears.
 *
 * ⛔ THE TRANSITION IS THE SUBJECT, NEVER THE END STATE. A test that only asserts the label is on
 * the thread at the end certifies whatever put it there, so every leg here asserts the sequence:
 * the write is refused and OWED, the operator clears the cause, the repair applies it, and the
 * debt is gone. The report-only leg is the CONTROL for that sequence — the identical state, the
 * identical command, one flag apart — and it must leave the thread exactly as the defect left it.
 */
class ProtocolInvalidLabelRepairTest extends TestCase
{
    use RefreshDatabase;
    use UnattributableCommentHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpUnattributableComment();
    }

    protected function tearDown(): void
    {
        $this->tearDownUnattributableComment();
        parent::tearDown();
    }

    // --- the transition -----------------------------------------------------------------------------

    public function test_a_refused_write_is_owed_and_a_later_run_applies_it(): void
    {
        $this->fakePeers();

        // (1) DECIDED AND REFUSED. GitHub was asked; the label is not on the thread.
        $this->githubAnswer = 403;
        $this->dispatch('d1', $this->comment('created', 'no from line here'));
        $this->assertSame([self::LABELS_URL], array_column($this->github, 'url'));
        $this->assertSame([[self::REPO, 42, 'add_refused', 403, 1]], $this->owedTuples());

        // (2) THE OPERATOR GRANTS THE TOKEN Issues WRITE. Nothing about the install changes.
        $this->github = [];
        $this->githubAnswer = 200;

        $this->artisan('bridge:relabel', ['--fix' => true])
            ->expectsOutputToContain('applied   '.self::REPO.'#42 — '.ProtocolInvalidLabeler::LABEL)
            ->assertSuccessful();

        // (3) APPLIED, AND THE DEBT IS DISCHARGED.
        $this->assertSame([self::LABELS_URL], array_column($this->github, 'url'));
        $this->assertSame('{"labels":["protocol:invalid"]}', $this->github[0]['body']);
        $this->assertSame('Bearer gh-test-token', $this->github[0]['auth']);
        $this->assertSame([], ProtocolInvalidLabelDebt::owed());
    }

    public function test_the_default_run_is_report_only_and_leaves_the_thread_as_the_defect_left_it(): void
    {
        // The CONTROL for the leg above: same install, same owed write, same command, no --fix.
        $this->fakePeers();
        $this->githubAnswer = 403;
        $this->dispatch('d1', $this->comment('created', 'no from line here'));
        $this->github = [];
        $this->githubAnswer = 200;

        // The report LINE is asserted, not just the exit code: a broken interpolation would leave
        // the operator an exit 0 and nothing they can act on, which is silent.
        $this->artisan('bridge:relabel')
            ->expectsOutputToContain('owed  '.self::REPO.'#42 — add_refused (403)')
            ->assertSuccessful();

        $this->assertSame([], $this->github, 'a report-only run must reach GitHub for nothing');
        $this->assertSame([[self::REPO, 42, 'add_refused', 403, 1]], $this->owedTuples());
    }

    public function test_a_repair_that_is_refused_again_stays_owed_and_the_run_reds(): void
    {
        $this->fakePeers();
        $this->githubAnswer = 403;
        $this->dispatch('d1', $this->comment('created', 'no from line here'));
        $this->github = [];

        // The operator did not actually fix it. A failed repair is never reported as a done one.
        $this->artisan('bridge:relabel', ['--fix' => true])
            ->expectsOutputToContain('still owed '.self::REPO.'#42 — add_refused (403)')
            ->assertFailed();

        $this->assertSame([self::LABELS_URL], array_column($this->github, 'url'));
        $this->assertSame([[self::REPO, 42, 'add_refused', 403, 2]], $this->owedTuples());
    }

    public function test_a_successful_write_discharges_a_debt_an_earlier_comment_left(): void
    {
        // The repair route that needs no command: the next unattributable comment on the same
        // thread writes the label the first one could not.
        $this->fakePeers();
        $this->githubAnswer = 403;
        $this->dispatch('d1', $this->comment('created', 'no from line here'));
        $this->assertCount(1, ProtocolInvalidLabelDebt::owed());

        $this->githubAnswer = 200;
        $this->dispatch('d2', $this->comment('created', 'still no from line', commentId: 9002));

        $this->assertSame([], ProtocolInvalidLabelDebt::owed());
    }

    // --- the population: which failures are recoverable and which are terminal -----------------------

    /**
     * ⛔ THE RULE IS NOT "EVERY FAILURE". A retried write that can never land is an unbounded
     * retry against a permanent refusal, and a retried write the INSTALL excluded is an outward
     * write the operator switched off. Both halves are asserted here, on one instrument.
     *
     * @return array<string, array{0: int|string|null, 1: string, 2: bool}>
     */
    public static function failureArms(): array
    {
        return [
            // recoverable: an operator act, or time, can make the identical request succeed
            'no token file resolves' => [null, 'token_unresolved', true],
            '401 the token is bad or expired' => [401, 'add_refused', true],
            '403 the token has no Issues write' => [403, 'add_refused', true],
            '404 the token cannot SEE the repo' => [404, 'add_refused', true],
            '429 rate limited' => [429, 'add_refused', true],
            '500 a GitHub fault' => [500, 'add_refused', true],
            '502 a GitHub fault' => [502, 'add_refused', true],
            'the request never completed' => ['transport', 'add_failed', true],
            // terminal: the identical request will be refused for the same reason forever
            '410 the thread is gone' => [410, 'add_refused', false],
            '422 GitHub rejected the request' => [422, 'add_refused', false],
            '400 the request is malformed' => [400, 'add_refused', false],
            '451 unavailable for legal reasons' => [451, 'add_refused', false],
        ];
    }

    #[DataProvider('failureArms')]
    public function test_only_a_recoverable_failure_is_owed(int|string|null $answer, string $reason, bool $owed): void
    {
        if ($answer === null) {
            File::delete($this->dir.'/github/token');
        }
        $this->fakePeers();
        $this->githubAnswer = $answer ?? 200;

        $this->dispatch('d1', $this->comment('created', 'no from line here'));

        $this->assertSame(
            $owed ? [[self::REPO, 42, $reason, is_int($answer) ? $answer : null, 1]] : [],
            $this->owedTuples(),
        );
    }

    public function test_a_target_with_no_repo_and_number_is_owed_nothing(): void
    {
        $this->fakePeers();
        Log::spy();

        $this->handle(['repo' => self::REPO, 'number' => '42']);

        $this->assertSame([], $this->github);
        $this->assertSame([], ProtocolInvalidLabelDebt::owed(), 'a target that names no thread names nothing to repair');
    }

    public function test_a_repo_this_install_does_not_write_is_owed_nothing(): void
    {
        $this->fakePeers();
        Log::spy();

        $this->handle(['repo' => 'acme/other', 'number' => 42, 'comment_id' => 1]);

        $this->assertSame([], $this->github);
        $this->assertSame([], ProtocolInvalidLabelDebt::owed(), 'the install excluded this repo — a debt would queue a write it switched off');
    }

    public function test_a_two_hundred_that_does_not_confirm_the_label_is_not_applied_and_stays_owed(): void
    {
        // A 2xx is the server's CLAIM. `POST .../labels` answers with the labels now on the thread,
        // so an answer that does not carry ours is an unconfirmed write, not a done one.
        $this->fakePeers();
        $this->githubOkBody = [['name' => 'to:alpha']];
        Log::spy();

        $this->dispatch('d1', $this->comment('created', 'no from line here'));

        // The POSITIVE arm is the discriminator, and it was watched red with the confirmation
        // removed. ⛔ The negative it replaces — `Log::shouldNotHaveReceived('info', [<message>])` —
        // is a DECORATION on this facade: the directive matches a full argument list and the call
        // takes two, so it passes whatever the code does. The owed entry below is what says the
        // write was not taken as applied; the two arms are exclusive branches of one `if`.
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => ($context['catalog_id'] ?? null) === 'protocol_invalid_label.add_unconfirmed')->once();
        $this->assertSame([[self::REPO, 42, 'add_unconfirmed', null, 1]], $this->owedTuples());
    }

    // --- the command's own refusals and bounds -------------------------------------------------------

    public function test_a_repo_dropped_from_the_list_is_forgotten_rather_than_written(): void
    {
        $this->fakePeers();
        $this->githubAnswer = 403;
        $this->dispatch('d1', $this->comment('created', 'no from line here'));
        $this->github = [];
        $this->githubAnswer = 200;

        // The operator switched this repo off between the failure and the repair.
        config(['bridge.protocol_invalid_label.repos' => []]);

        $this->artisan('bridge:relabel', ['--fix' => true])->assertSuccessful();

        $this->assertSame([], $this->github, 'the install no longer writes this repo — the repair must not either');
        $this->assertSame([], ProtocolInvalidLabelDebt::owed());
    }

    public function test_an_owed_write_older_than_the_window_is_not_repaired(): void
    {
        $this->fakePeers();
        $this->githubAnswer = 403;
        $this->dispatch('d1', $this->comment('created', 'no from line here'));
        $this->assertCount(1, ProtocolInvalidLabelDebt::owed());
        $this->github = [];
        $this->githubAnswer = 200;

        $this->travel(ProtocolInvalidLabelDebt::EXPIRY_SECONDS + 60)->seconds();

        $this->assertSame([], ProtocolInvalidLabelDebt::owed());
        $this->artisan('bridge:relabel', ['--fix' => true])->assertSuccessful();
        $this->assertSame([], $this->github);
    }

    public function test_the_run_attempts_no_more_than_the_limit(): void
    {
        $this->fakePeers();
        $this->githubAnswer = 403;
        foreach ([11, 12, 13] as $i => $number) {
            $this->dispatch('d'.$i, $this->comment('created', 'no from line here', number: $number, commentId: 9000 + $number));
        }
        $this->assertCount(3, ProtocolInvalidLabelDebt::owed());
        $this->github = [];
        $this->githubAnswer = 200;

        $this->artisan('bridge:relabel', ['--fix' => true, '--limit' => 2])->assertFailed();

        $this->assertCount(2, $this->github);
        $this->assertCount(1, ProtocolInvalidLabelDebt::owed());
    }

    public function test_repo_scopes_the_run(): void
    {
        $this->fakePeers();
        config(['bridge.protocol_invalid_label.repos' => ['Acme/Coord', 'acme/other']]);
        $this->githubAnswer = 403;
        $this->dispatch('d1', $this->comment('created', 'no from line here'));
        $this->handle(['repo' => 'acme/other', 'number' => 7, 'comment_id' => 5]);
        $this->assertCount(2, ProtocolInvalidLabelDebt::owed());
        $this->github = [];
        $this->githubAnswer = 200;

        $this->artisan('bridge:relabel', ['--fix' => true, '--repo' => 'ACME/Other'])->assertSuccessful();

        $this->assertSame(['https://api.github.com/repos/acme/other/issues/7/labels'], array_column($this->github, 'url'));
        $this->assertSame([[self::REPO, 42, 'add_refused', 403, 1]], $this->owedTuples());
    }

    public function test_an_install_owing_nothing_says_so_and_exits_clean(): void
    {
        $this->artisan('bridge:relabel', ['--fix' => true])->assertSuccessful();

        $this->assertSame([], ProtocolInvalidLabelDebt::owed());
    }

    // --- the record itself ---------------------------------------------------------------------------

    public function test_two_unattributable_comments_on_one_thread_owe_one_label(): void
    {
        $this->fakePeers();
        $this->githubAnswer = 403;
        $this->dispatch('d1', $this->comment('created', 'no from line here'));
        $this->dispatch('d2', $this->comment('created', 'still none', commentId: 9002));

        // The label is a property of the THREAD, so the second comment bumps the attempt count
        // rather than minting a second owed write.
        $this->assertSame([[self::REPO, 42, 'add_refused', 403, 2]], $this->owedTuples());
    }

    public function test_the_record_is_capped_and_says_when_it_drops_something(): void
    {
        // The cap is a BOUND, so the state it bounds is constructed rather than accumulated: one
        // mutation over an over-full record is the whole measurement, and driving it through the
        // write path 500 times would measure the write path instead.
        File::ensureDirectoryExists($this->dir.'/state');
        $entries = [];
        foreach (range(1, ProtocolInvalidLabelDebt::MAX_ENTRIES + 1) as $number) {
            $entries[strtolower(self::REPO).'#'.$number] = [
                'repo' => self::REPO, 'number' => $number, 'comment_id' => null,
                'first_failed_at' => now()->utc()->subSeconds(ProtocolInvalidLabelDebt::MAX_ENTRIES + 1 - $number)->format('Y-m-d\\TH:i:s\\Z'),
                'last_failed_at' => now()->utc()->format('Y-m-d\\TH:i:s\\Z'),
                'attempts' => 1, 'reason' => 'add_refused', 'status' => 403,
            ];
        }
        File::put(ProtocolInvalidLabelDebt::path(), (string) json_encode(['owed' => $entries]));
        $this->assertCount(ProtocolInvalidLabelDebt::MAX_ENTRIES + 1, ProtocolInvalidLabelDebt::owed());
        Log::spy();

        ProtocolInvalidLabelDebt::settle(self::REPO, 999999, null, 'add_refused', 403);

        $owed = ProtocolInvalidLabelDebt::owed();
        $this->assertCount(ProtocolInvalidLabelDebt::MAX_ENTRIES, $owed);
        $this->assertSame([3, 999999], [$owed[0]['number'], $owed[array_key_last($owed)]['number']],
            'at the cap the OLDEST go: the two oldest of the 502 are dropped and the newest — the write just refused — is kept');
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => ($context['catalog_id'] ?? null) === 'protocol_invalid_label.owed_record_pruned'
            && ($context['dropped'] ?? null) === 2)->once();
    }

    public function test_an_unreadable_record_is_reported_rather_than_read_as_empty(): void
    {
        File::ensureDirectoryExists($this->dir.'/state');
        File::put(ProtocolInvalidLabelDebt::path(), 'not json');
        Log::spy();

        $this->assertSame([], ProtocolInvalidLabelDebt::owed());

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => ($context['catalog_id'] ?? null) === 'protocol_invalid_label.owed_record_unreadable')->once();
    }

    public function test_a_record_the_bridge_cannot_write_never_reaches_routing(): void
    {
        // The bookkeeping is not allowed to break the delivery it books.
        $this->fakePeers();
        // The record's own LOCK path is a directory, so the mutation cannot take it. Nothing else
        // in the state dir is touched — a broken state dir would fail the inbox staging instead,
        // which is a different (and loud) failure.
        File::ensureDirectoryExists(ProtocolInvalidLabelDebt::path().'.lock');
        Log::spy();
        $this->githubAnswer = 403;

        $this->dispatch('d1', $this->comment('created', 'no from line here'));

        $this->assertCount(1, $this->pushes, 'routing is unchanged by a failure to record the debt');
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => ($context['catalog_id'] ?? null) === 'protocol_invalid_label.owed_record_unwritable')->once();
    }

    // --- helpers -------------------------------------------------------------------------------------

    /**
     * The owed writes as `[repo, number, reason, status, attempts]` — the fields a reader acts on,
     * with the timestamps (which no assertion here is about) left out.
     *
     * @return list<array{0: string, 1: int, 2: string, 3: ?int, 4: int}>
     */
    private function owedTuples(): array
    {
        return array_map(
            fn (array $e): array => [$e['repo'], $e['number'], $e['reason'], $e['status'], $e['attempts']],
            ProtocolInvalidLabelDebt::owed(),
        );
    }
}
