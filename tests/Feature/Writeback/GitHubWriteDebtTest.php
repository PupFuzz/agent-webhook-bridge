<?php

namespace Tests\Feature\Writeback;

use App\Bridge\Exceptions\MalformedStateFileException;
use App\Bridge\Support\ProcessIdentity;
use App\Bridge\Support\SystemProcessIdentity;
use App\Bridge\Writeback\GitHubWriteDebt;
use App\Bridge\Writeback\ProtocolInvalidLabeler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\UnattributableCommentHarness;
use Tests\TestCase;

/**
 * card#10242 / DL-419: a `protocol:invalid` write the install DECIDED on and could not land is
 * remembered, and `bridge:github-owed` discharges it once the cause clears — and the record's own
 * mechanics (bounds, unreadable and malformed files, the owner rule), which since card#10365 /
 * DL-422 are shared with the correlation comment. That writer's own transition is asserted end to
 * end through its merge event in `PrCorrelationCommentTest`.
 *
 * ⛔ THE TRANSITION IS THE SUBJECT, NEVER THE END STATE. A test that only asserts the label is on
 * the thread at the end certifies whatever put it there, so every leg here asserts the sequence:
 * the write is refused and OWED, the operator clears the cause, the repair applies it, and the
 * debt is gone. The report-only leg is the CONTROL for that sequence — the identical state, the
 * identical command, one flag apart — and it must leave the thread exactly as the defect left it.
 */
class GitHubWriteDebtTest extends TestCase
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

        $this->artisan('bridge:github-owed', ['--fix' => true])
            ->expectsOutputToContain('done      '.self::REPO.'#42 ['.ProtocolInvalidLabeler::LABEL.'] — applied')
            ->assertSuccessful();

        // (3) APPLIED, AND THE DEBT IS DISCHARGED.
        $this->assertSame([self::LABELS_URL], array_column($this->github, 'url'));
        $this->assertSame('{"labels":["protocol:invalid"]}', $this->github[0]['body']);
        $this->assertSame('Bearer gh-test-token', $this->github[0]['auth']);
        $this->assertSame([], GitHubWriteDebt::owed());
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
        $this->artisan('bridge:github-owed')
            ->expectsOutputToContain('owed  '.self::REPO.'#42 [protocol:invalid] — add_refused (403)')
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
        $this->artisan('bridge:github-owed', ['--fix' => true])
            ->expectsOutputToContain('still owed '.self::REPO.'#42 [protocol:invalid] — add_refused (403)')
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
        $this->assertCount(1, GitHubWriteDebt::owed());

        $this->githubAnswer = 200;
        $this->dispatch('d2', $this->comment('created', 'still no from line', commentId: 9002));

        $this->assertSame([], GitHubWriteDebt::owed());
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
        $this->assertSame([], GitHubWriteDebt::owed(), 'a target that names no thread names nothing to repair');
    }

    public function test_a_repo_this_install_does_not_write_is_owed_nothing(): void
    {
        $this->fakePeers();
        Log::spy();

        $this->handle(['repo' => 'acme/other', 'number' => 42, 'comment_id' => 1]);

        $this->assertSame([], $this->github);
        $this->assertSame([], GitHubWriteDebt::owed(), 'the install excluded this repo — a debt would queue a write it switched off');
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

    public function test_the_confirmation_matches_the_label_name_case_insensitively(): void
    {
        // A repo that already carries the label under another spelling answers the add with THAT
        // spelling; the thread carries the label, so the write is confirmed, not owed.
        $this->fakePeers();
        $this->githubOkBody = [['name' => 'to:alpha'], ['name' => 'Protocol:Invalid']];
        Log::spy();

        $this->dispatch('d1', $this->comment('created', 'no from line here'));

        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context = []) => ($context['catalog_id'] ?? null) === 'protocol_invalid_label.applied')->once();
        $this->assertSame([], $this->owedTuples());
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

        $this->artisan('bridge:github-owed', ['--fix' => true])->assertSuccessful();

        $this->assertSame([], $this->github, 'the install no longer writes this repo — the repair must not either');
        $this->assertSame([], GitHubWriteDebt::owed());
    }

    public function test_an_owed_write_older_than_the_window_is_not_repaired(): void
    {
        $this->fakePeers();
        $this->githubAnswer = 403;
        $this->dispatch('d1', $this->comment('created', 'no from line here'));
        $this->assertCount(1, GitHubWriteDebt::owed());
        $this->github = [];
        $this->githubAnswer = 200;

        $this->travel(GitHubWriteDebt::EXPIRY_SECONDS + 60)->seconds();

        $this->assertSame([], GitHubWriteDebt::owed());
        $this->artisan('bridge:github-owed', ['--fix' => true])->assertSuccessful();
        $this->assertSame([], $this->github);
    }

    public function test_a_fresh_refusal_of_a_write_whose_row_has_expired_is_owed_from_now(): void
    {
        // The expired row is still in the FILE (a pure read never rewrites it), so the next refusal
        // of the same write meets it. Inheriting its first_failed_at would prune the fresh
        // refusal in the very write that records it.
        GitHubWriteDebt::settle(GitHubWriteDebt::KIND_LABEL, self::REPO, 42, ['comment_id' => '7'], 'add_refused', 403, true);
        $this->travel(GitHubWriteDebt::EXPIRY_SECONDS + 60)->seconds();
        $this->assertSame([], GitHubWriteDebt::owed());

        GitHubWriteDebt::settle(GitHubWriteDebt::KIND_LABEL, self::REPO, 42, ['comment_id' => '9'], 'add_refused', 403, true);

        $this->assertSame([[self::REPO, 42, 'add_refused', 403, 1]], $this->owedTuples());
        $this->assertSame('9', GitHubWriteDebt::owed()[0]['comment_id'], 'an expired row lends the fresh one nothing');
    }

    public function test_the_run_attempts_no_more_than_the_limit(): void
    {
        $this->fakePeers();
        $this->githubAnswer = 403;
        foreach ([11, 12, 13] as $i => $number) {
            $this->dispatch('d'.$i, $this->comment('created', 'no from line here', number: $number, commentId: 9000 + $number));
        }
        $this->assertCount(3, GitHubWriteDebt::owed());
        $this->github = [];
        $this->githubAnswer = 200;

        $this->artisan('bridge:github-owed', ['--fix' => true, '--limit' => 2])->assertFailed();

        $this->assertCount(2, $this->github);
        $this->assertCount(1, GitHubWriteDebt::owed());
    }

    public function test_repo_scopes_the_run(): void
    {
        $this->fakePeers();
        config(['bridge.protocol_invalid_label.repos' => ['Acme/Coord', 'acme/other']]);
        $this->githubAnswer = 403;
        $this->dispatch('d1', $this->comment('created', 'no from line here'));
        $this->handle(['repo' => 'acme/other', 'number' => 7, 'comment_id' => 5]);
        $this->assertCount(2, GitHubWriteDebt::owed());
        $this->github = [];
        $this->githubAnswer = 200;

        $this->artisan('bridge:github-owed', ['--fix' => true, '--repo' => 'ACME/Other'])->assertSuccessful();

        $this->assertSame(['https://api.github.com/repos/acme/other/issues/7/labels'], array_column($this->github, 'url'));
        $this->assertSame([[self::REPO, 42, 'add_refused', 403, 1]], $this->owedTuples());
    }

    public function test_an_install_owing_nothing_says_so_and_exits_clean(): void
    {
        $this->artisan('bridge:github-owed', ['--fix' => true])->assertSuccessful();

        $this->assertSame([], GitHubWriteDebt::owed());
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

    public function test_an_owed_label_keeps_the_first_comment_id_that_named_it(): void
    {
        GitHubWriteDebt::settle(GitHubWriteDebt::KIND_LABEL, self::REPO, 41, ['comment_id' => null], 'add_refused', 403, true);
        GitHubWriteDebt::settle(GitHubWriteDebt::KIND_LABEL, self::REPO, 41, ['comment_id' => '77'], 'add_refused', 403, true);
        GitHubWriteDebt::settle(GitHubWriteDebt::KIND_LABEL, self::REPO, 41, ['comment_id' => '88'], 'add_refused', 403, true);

        // A null never pins the row: the first comment that NAMED the thread is the one kept.
        $this->assertSame(['77'], array_column(GitHubWriteDebt::owed(), 'comment_id'));
    }

    public function test_the_record_is_capped_and_says_when_it_drops_something(): void
    {
        // The cap is a BOUND, so the state it bounds is constructed rather than accumulated: one
        // mutation over an over-full record is the whole measurement, and driving it through the
        // write path 500 times would measure the write path instead.
        File::ensureDirectoryExists($this->dir.'/state');
        $entries = [];
        foreach (range(1, GitHubWriteDebt::MAX_ENTRIES + 1) as $number) {
            $entries['protocol_invalid_label|'.strtolower(self::REPO).'#'.$number] = [
                'kind' => GitHubWriteDebt::KIND_LABEL, 'repo' => self::REPO, 'number' => $number, 'comment_id' => null,
                'first_failed_at' => now()->utc()->subSeconds(GitHubWriteDebt::MAX_ENTRIES + 1 - $number)->format('Y-m-d\\TH:i:s\\Z'),
                'last_failed_at' => now()->utc()->format('Y-m-d\\TH:i:s\\Z'),
                'attempts' => 1, 'reason' => 'add_refused', 'status' => 403,
            ];
        }
        File::put(GitHubWriteDebt::path(), (string) json_encode(['owed' => $entries]));
        $this->assertCount(GitHubWriteDebt::MAX_ENTRIES + 1, GitHubWriteDebt::owed());
        Log::spy();

        GitHubWriteDebt::settle(GitHubWriteDebt::KIND_LABEL, self::REPO, 999999, ['comment_id' => null], 'add_refused', 403, true);

        $owed = GitHubWriteDebt::owed();
        $this->assertCount(GitHubWriteDebt::MAX_ENTRIES, $owed);
        $this->assertSame([3, 999999], [$owed[0]['number'], $owed[array_key_last($owed)]['number']],
            'at the cap the OLDEST go: the two oldest of the 502 are dropped and the newest — the write just refused — is kept');
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => ($context['catalog_id'] ?? null) === 'github_write_debt.record_pruned'
            && ($context['dropped'] ?? null) === 2)->once();
    }

    public function test_a_corrupt_record_is_not_read_as_nothing_owed(): void
    {
        File::ensureDirectoryExists($this->dir.'/state');
        File::put(GitHubWriteDebt::path(), 'not json');

        try {
            GitHubWriteDebt::owed();
            $this->fail('a record that does not parse answered as a list of what is owed');
        } catch (MalformedStateFileException $e) {
            $this->assertStringContainsString(GitHubWriteDebt::path(), $e->getMessage());
        }
    }

    /**
     * ⛔ THE OPERATOR'S SURFACE IS THE SUBJECT. The record is the receiver's, so the realistic
     * reader that cannot parse or open it is `bridge:github-owed`; asserting only the class's answer
     * would leave the command free to turn it back into "nothing owed", exit 0. Both modes are
     * asserted because `--fix`'s exit code is the contract a script reads.
     */
    public function test_a_corrupt_record_reds_the_command_in_both_modes_and_names_the_file(): void
    {
        File::ensureDirectoryExists($this->dir.'/state');
        File::put(GitHubWriteDebt::path(), 'not json');

        foreach ([[], ['--fix' => true]] as $options) {
            $this->artisan('bridge:github-owed', $options)
                ->expectsOutputToContain(GitHubWriteDebt::path().' is not a record this bridge wrote')
                ->doesntExpectOutputToContain('nothing owed')
                ->assertFailed();
        }

        $this->assertSame('not json', File::get(GitHubWriteDebt::path()), 'a report never rewrites the file it is reporting on');
    }

    public function test_a_record_this_user_cannot_read_reds_the_command_in_both_modes_and_names_the_file(): void
    {
        $this->skipWhenModeZeroCannotRefuseARead();
        $this->fakePeers();
        $this->githubAnswer = 403;
        $this->dispatch('d1', $this->comment('created', 'no from line here'));
        $this->assertCount(1, GitHubWriteDebt::owed());
        $this->github = [];
        $this->githubAnswer = 200;

        chmod(GitHubWriteDebt::path(), 0);
        try {
            foreach ([[], ['--fix' => true]] as $options) {
                $this->artisan('bridge:github-owed', $options)
                    ->expectsOutputToContain('at '.GitHubWriteDebt::path().' could not be read by this process')
                    ->doesntExpectOutputToContain('nothing owed')
                    ->assertFailed();
            }
            [, $out] = $this->relabel([]);
            $this->assertStringContainsString('give the file and its .lock back to that user', $out, 'the lock is the second file the receiver must be able to open');
        } finally {
            chmod(GitHubWriteDebt::path(), 0600);
        }

        $this->assertSame([], $this->github, 'a run that cannot read what is owed must write nothing');
        $this->assertCount(1, GitHubWriteDebt::owed(), 'the entry is still there once the file is readable again');
    }

    /**
     * ⛔ A WRITE THAT MEETS A RECORD IT CANNOT READ STOPS. Replacing the file destroys the entries
     * nobody read, and moving it aside takes them off the operator's surface — so the file is left
     * byte-for-byte, the write is logged as unrecorded, and the operator's command keeps naming the
     * file. Both halves are asserted, because either alone certifies a system that loses the entries.
     */
    public function test_a_corrupt_record_is_left_byte_identical_by_a_write_and_the_command_still_reds_on_it(): void
    {
        File::ensureDirectoryExists($this->dir.'/state');
        $torn = '{"owed": {"acme/coord#41": {"repo": "Acme/Coord", "number": 41';
        File::put(GitHubWriteDebt::path(), $torn);
        Log::spy();

        GitHubWriteDebt::settle(GitHubWriteDebt::KIND_LABEL, self::REPO, 43, ['comment_id' => null], 'add_refused', 403, true);

        $this->assertSame($torn, File::get(GitHubWriteDebt::path()), 'a write never replaces a record it could not parse');
        $this->assertSame([GitHubWriteDebt::path().'.lock'], $this->siblingsOfTheRecord(), 'nothing is moved or written beside it');
        $this->assertUnwritableNaming(GitHubWriteDebt::path().' is not a record this bridge wrote (not valid JSON)');

        foreach ([[], ['--fix' => true]] as $options) {
            $this->artisan('bridge:github-owed', $options)
                ->expectsOutputToContain(GitHubWriteDebt::path().' is not a record this bridge wrote')
                ->doesntExpectOutputToContain('nothing owed')
                ->assertFailed();
        }
    }

    public function test_a_record_this_user_cannot_read_is_left_byte_identical_by_a_write(): void
    {
        // The realistic producer: `bridge:github-owed --fix` run as the OPERATOR rewrites the record,
        // and `writeFileAtomic()` leaves it 0600 and owned by that user, so the receiver's next
        // write finds a file it cannot open — and a rename over it would destroy what it holds.
        $this->skipWhenModeZeroCannotRefuseARead();
        GitHubWriteDebt::settle(GitHubWriteDebt::KIND_LABEL, self::REPO, 41, ['comment_id' => null], 'add_refused', 403, true);
        $held = File::get(GitHubWriteDebt::path());
        chmod(GitHubWriteDebt::path(), 0);
        Log::spy();

        try {
            GitHubWriteDebt::settle(GitHubWriteDebt::KIND_LABEL, self::REPO, 43, ['comment_id' => null], 'add_refused', 403, true);
        } finally {
            chmod(GitHubWriteDebt::path(), 0600);
        }

        $this->assertSame($held, File::get(GitHubWriteDebt::path()));
        $this->assertSame([GitHubWriteDebt::path().'.lock'], $this->siblingsOfTheRecord());
        $this->assertSame([[self::REPO, 41, 'add_refused', 403, 1]], $this->owedTuples(), 'the write the record could not take is not in it — the warning says so');
        $this->assertUnwritableNaming('at '.GitHubWriteDebt::path().' could not be read by this process');
    }

    /**
     * An entry in a well-formed record that is not one this class writes is the file-level fault
     * one entry at a time: skipped, it would read as nothing owed and vanish on the next rewrite.
     */
    public function test_a_mis_shaped_entry_makes_the_record_corrupt_reported_and_never_dropped(): void
    {
        File::ensureDirectoryExists($this->dir.'/state');
        GitHubWriteDebt::settle(GitHubWriteDebt::KIND_LABEL, self::REPO, 42, ['comment_id' => null], 'add_refused', 403, true);
        $decoded = json_decode(File::get(GitHubWriteDebt::path()), true);
        $decoded['owed']['protocol_invalid_label|acme/coord#41'] = ['number' => '41'] + $decoded['owed']['protocol_invalid_label|'.strtolower(self::REPO).'#42'];
        $record = (string) json_encode($decoded);
        File::put(GitHubWriteDebt::path(), $record);
        Log::spy();

        foreach ([[], ['--fix' => true]] as $options) {
            $this->artisan('bridge:github-owed', $options)
                ->expectsOutputToContain(GitHubWriteDebt::path().' is not a record this bridge wrote (an entry that is not an owed GitHub write)')
                ->doesntExpectOutputToContain('nothing owed')
                ->assertFailed();
        }

        GitHubWriteDebt::settle(GitHubWriteDebt::KIND_LABEL, self::REPO, 43, ['comment_id' => null], 'add_refused', 403, true);

        $this->assertSame($record, File::get(GitHubWriteDebt::path()), 'the rewrite that would drop the entry never happens');
        $this->assertUnwritableNaming('(an entry that is not an owed GitHub write)');
    }

    public function test_the_request_path_peek_reports_an_unreadable_record_and_changes_nothing(): void
    {
        // `forget()` runs on every successful label write, so it must not throw into the
        // delivery; it reports the record and leaves it for the operator.
        File::ensureDirectoryExists($this->dir.'/state');
        File::put(GitHubWriteDebt::path(), 'not json');
        Log::spy();

        GitHubWriteDebt::forget(GitHubWriteDebt::KIND_LABEL, self::REPO, 42);

        $this->assertSame('not json', File::get(GitHubWriteDebt::path()));
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => ($context['catalog_id'] ?? null) === 'github_write_debt.record_unreadable')->once();
    }

    public function test_a_record_the_bridge_cannot_write_never_reaches_routing(): void
    {
        // The bookkeeping is not allowed to break the delivery it books.
        $this->fakePeers();
        // The record's own LOCK path is a directory, so the mutation cannot take it. Nothing else
        // in the state dir is touched — a broken state dir would fail the inbox staging instead,
        // which is a different (and loud) failure.
        File::ensureDirectoryExists(GitHubWriteDebt::path().'.lock');
        Log::spy();
        $this->githubAnswer = 403;

        $this->dispatch('d1', $this->comment('created', 'no from line here'));

        $this->assertCount(1, $this->pushes, 'routing is unchanged by a failure to record the debt');
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => ($context['catalog_id'] ?? null) === 'github_write_debt.record_unwritable')->once();
    }

    // --- who may write the record ---------------------------------------------------------------------

    /**
     * ⛔ THE PRODUCER OF AN UNREADABLE RECORD IS A WRITER RUNNING AS THE WRONG USER. `writeFileAtomic()`
     * leaves the record `0600` and owned by whoever wrote it, so a `sudo bridge:github-owed --fix` hands it
     * to root: the receiver can no longer open it, every later refused write goes unrecorded, and root
     * — who CAN read it — is told nothing is owed. The write is refused at the primitive, so every
     * route to it (a relabel, a `bridge:replay --force`) is covered, and the record is left as it was.
     *
     * The suite is not root and cannot chown, so the process identity is the seam; the OWNER is still
     * the real file's, read through the default implementation.
     */
    public function test_a_write_never_replaces_a_record_another_user_owns(): void
    {
        GitHubWriteDebt::settle(GitHubWriteDebt::KIND_LABEL, self::REPO, 41, ['comment_id' => null], 'add_refused', 403, true);
        $held = File::get(GitHubWriteDebt::path());
        $owner = (int) fileowner(GitHubWriteDebt::path());
        $this->runAs($owner + 1, [$owner => 'www-data']);
        Log::spy();

        GitHubWriteDebt::settle(GitHubWriteDebt::KIND_LABEL, self::REPO, 43, ['comment_id' => null], 'add_refused', 403, true);
        GitHubWriteDebt::forget(GitHubWriteDebt::KIND_LABEL, self::REPO, 41);

        $this->assertSame($held, File::get(GitHubWriteDebt::path()), 'a write never replaces a record another user owns');
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => ($context['catalog_id'] ?? null) === 'github_write_debt.record_unwritable'
            && str_contains((string) ($context['error'] ?? ''), 'owned by www-data'))->twice();
    }

    public function test_a_write_as_root_never_creates_the_record_or_its_lock(): void
    {
        $this->runAs(0);
        Log::spy();

        GitHubWriteDebt::settle(GitHubWriteDebt::KIND_LABEL, self::REPO, 43, ['comment_id' => null], 'add_refused', 403, true);

        $this->assertFileDoesNotExist(GitHubWriteDebt::path());
        $this->assertFileDoesNotExist(GitHubWriteDebt::path().'.lock', 'a root-owned lock locks the receiver out as surely as a root-owned record');
        $this->assertUnwritableNaming('this process runs as root');
    }

    public function test_a_write_as_root_never_replaces_the_record(): void
    {
        GitHubWriteDebt::settle(GitHubWriteDebt::KIND_LABEL, self::REPO, 41, ['comment_id' => null], 'add_refused', 403, true);
        $held = File::get(GitHubWriteDebt::path());
        $owner = (int) fileowner(GitHubWriteDebt::path());
        $this->runAs(0, [$owner => 'www-data']);
        Log::spy();

        GitHubWriteDebt::settle(GitHubWriteDebt::KIND_LABEL, self::REPO, 43, ['comment_id' => null], 'add_refused', 403, true);

        $this->assertSame($held, File::get(GitHubWriteDebt::path()));
        $this->assertUnwritableNaming('this process runs as root');
    }

    /**
     * The reviewer's sequence (r3 M1), on the operator's surface: a refused write is owed, and the
     * operator runs the repair as root. The command refuses before it sends or writes anything, in
     * both modes, and names the user to run as — never the all-clear root would otherwise print.
     */
    public function test_relabel_as_root_refuses_in_both_modes_and_names_the_user_to_run_as(): void
    {
        $this->fakePeers();
        $this->githubAnswer = 403;
        $this->dispatch('d1', $this->comment('created', 'no from line here'));
        $held = File::get(GitHubWriteDebt::path());
        $owner = (int) fileowner(GitHubWriteDebt::path());
        $this->github = [];
        $this->githubAnswer = 200;
        $this->runAs(0, [$owner => 'www-data']);

        foreach ([[], ['--fix' => true]] as $options) {
            [$code, $out] = $this->relabel($options);
            $this->assertSame(1, $code);
            $this->assertStringContainsString('REFUSED — this process runs as root', $out);
            $this->assertStringContainsString('run it as www-data', $out);
            $this->assertStringNotContainsString('nothing owed', $out);
        }

        $this->assertSame([], $this->github, 'a refused run sends nothing');
        $this->assertSame($held, File::get(GitHubWriteDebt::path()));
    }

    public function test_relabel_as_root_with_no_record_refuses_rather_than_creating_one(): void
    {
        $this->runAs(0);

        [$code, $out] = $this->relabel(['--fix' => true]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('REFUSED — this process runs as root', $out);
        $this->assertStringContainsString('run it as the user the receiver runs as', $out);
        $this->assertStringNotContainsString('nothing owed', $out);

        $this->assertFileDoesNotExist(GitHubWriteDebt::path());
    }

    public function test_relabel_as_a_user_who_does_not_own_the_record_refuses_and_names_the_owner(): void
    {
        GitHubWriteDebt::settle(GitHubWriteDebt::KIND_LABEL, self::REPO, 41, ['comment_id' => null], 'add_refused', 403, true);
        $owner = (int) fileowner(GitHubWriteDebt::path());
        $this->runAs($owner + 1, [$owner => 'www-data']);

        [$code, $out] = $this->relabel(['--fix' => true]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('REFUSED — '.GitHubWriteDebt::path().' is owned by www-data', $out);
        $this->assertStringContainsString('run it as www-data', $out);
        $this->assertSame([], $this->github, 'a refused run sends nothing');
    }

    /**
     * A record ALREADY root-owned (a `sudo --fix` from before this refusal existed) has no user to
     * run as — root is refused — so both the receiver's log and the operator's terminal must say to
     * hand the file back, never "run it as root".
     */
    public function test_a_root_owned_record_is_answered_with_giving_it_back_never_with_running_as_root(): void
    {
        GitHubWriteDebt::settle(GitHubWriteDebt::KIND_LABEL, self::REPO, 41, ['comment_id' => null], 'add_refused', 403, true);
        $held = File::get(GitHubWriteDebt::path());
        $this->runAs(1000, [0 => 'root', 1000 => 'www-data'], owner: 0);
        Log::spy();

        GitHubWriteDebt::settle(GitHubWriteDebt::KIND_LABEL, self::REPO, 43, ['comment_id' => null], 'add_refused', 403, true);
        [$code, $out] = $this->relabel(['--fix' => true]);

        $this->assertSame($held, File::get(GitHubWriteDebt::path()));
        $this->assertUnwritableNaming('give '.GitHubWriteDebt::path().' and its .lock back to the user the receiver runs as');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('is owned by root and this process runs as www-data', $out);
        $this->assertStringContainsString('back to the user the receiver runs as', $out);
        $this->assertStringNotContainsString('run it as root', $out);

        $this->runAs(0, [0 => 'root'], owner: 0);
        [$code, $out] = $this->relabel([]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('already owned by root', $out);
        $this->assertStringNotContainsString('run it as root', $out);
    }

    /**
     * r4 MINOR-1: the lock is the record's second file. A `.lock` the receiver cannot open fails
     * every write in `withLock()` while the record itself reads fine, so the owner's report would
     * say nothing is owed. The owner rule is asked of the lock as well — with the record present
     * and owned by this process, and with no record at all.
     */
    #[DataProvider('recordPresence')]
    public function test_a_lock_another_user_owns_refuses_the_write_and_the_report(bool $recordPresent): void
    {
        GitHubWriteDebt::settle(GitHubWriteDebt::KIND_LABEL, self::REPO, 41, ['comment_id' => null], 'add_refused', 403, true);
        if (! $recordPresent) {
            File::delete(GitHubWriteDebt::path());
        }
        $held = $recordPresent ? File::get(GitHubWriteDebt::path()) : null;
        $me = (int) fileowner(GitHubWriteDebt::path().'.lock');
        $lock = GitHubWriteDebt::path().'.lock';
        $this->runAs($me, [$me => 'www-data', $me + 1 => 'deploy'], owners: [$lock => $me + 1]);
        Log::spy();

        GitHubWriteDebt::settle(GitHubWriteDebt::KIND_LABEL, self::REPO, 43, ['comment_id' => null], 'add_refused', 403, true);
        [$code, $out] = $this->relabel([]);

        $this->assertSame($held, $recordPresent ? File::get(GitHubWriteDebt::path()) : null);
        $this->assertFileExists($lock);
        $this->assertUnwritableNaming($lock.' is owned by deploy');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('REFUSED — '.$lock.' is owned by deploy and this process runs as www-data', $out);
        $this->assertStringNotContainsString('nothing owed', $out);
    }

    /** @return array<string, array{bool}> */
    public static function recordPresence(): array
    {
        return ['record present, owned by this process' => [true], 'no record' => [false]];
    }

    /** The control for the refusals above: the owner itself, not root, is let through. */
    public function test_relabel_as_the_records_owner_is_not_refused(): void
    {
        GitHubWriteDebt::settle(GitHubWriteDebt::KIND_LABEL, self::REPO, 41, ['comment_id' => null], 'add_refused', 403, true);
        $owner = (int) fileowner(GitHubWriteDebt::path());
        $this->runAs($owner, [$owner => 'www-data']);

        $this->artisan('bridge:github-owed')
            ->expectsOutputToContain('owed  '.self::REPO.'#41')
            ->doesntExpectOutputToContain('REFUSED')
            ->assertSuccessful();
    }

    // --- what the record is made of ------------------------------------------------------------------

    /**
     * r3 m1: an entry `json_encode` cannot encode must not turn the rewrite into an empty file. A
     * custom classifier can hand the labeler any scalar as a comment id; the shipped one cannot.
     */
    public function test_an_entry_that_cannot_be_encoded_leaves_the_record_byte_identical(): void
    {
        GitHubWriteDebt::settle(GitHubWriteDebt::KIND_LABEL, self::REPO, 42, ['comment_id' => null], 'add_refused', 403, true);
        $held = File::get(GitHubWriteDebt::path());
        Log::spy();

        GitHubWriteDebt::settle(GitHubWriteDebt::KIND_LABEL, self::REPO, 43, ['comment_id' => "\xff\xfe"], 'add_refused', 403, true);

        $this->assertSame($held, File::get(GitHubWriteDebt::path()), 'a failed encode never replaces the record');
        $this->assertSame([[self::REPO, 42, 'add_refused', 403, 1]], $this->owedTuples());
        $this->assertUnwritableNaming('Malformed UTF-8');
    }

    public function test_valid_json_that_is_not_an_object_is_not_called_invalid_json(): void
    {
        File::ensureDirectoryExists($this->dir.'/state');
        File::put(GitHubWriteDebt::path(), '42');

        $this->artisan('bridge:github-owed')
            ->expectsOutputToContain(GitHubWriteDebt::path().' is not a record this bridge wrote (valid JSON, but not an object)')
            ->assertFailed();
    }

    // --- helpers -------------------------------------------------------------------------------------

    /**
     * Run the rest of the test as $euid. The OWNER of a file is read from the real file unless $owner
     * overrides it, so by default a record this test wrote is owned by the suite's own uid.
     *
     * @param  array<int, string>  $names
     * @param  int|null  $owner  the owner to report for a file that EXISTS, in place of its real one
     * @param  array<string, int>  $owners  per-path overrides of $owner, for a file that EXISTS
     */
    private function runAs(int $euid, array $names = [], ?int $owner = null, array $owners = []): void
    {
        $this->app->instance(ProcessIdentity::class, new class($euid, $names, $owner, $owners) implements ProcessIdentity
        {
            /**
             * @param  array<int, string>  $names
             * @param  array<string, int>  $owners
             */
            public function __construct(private int $euid, private array $names, private ?int $owner, private array $owners) {}

            public function euid(): ?int
            {
                return $this->euid;
            }

            public function ownerOf(string $path): ?int
            {
                $real = (new SystemProcessIdentity)->ownerOf($path);

                return $real === null ? null : ($this->owners[$path] ?? $this->owner ?? $real);
            }

            public function accountName(int $uid): ?string
            {
                return $this->names[$uid] ?? null;
            }
        });
    }

    /**
     * One run, its exit code and ALL of its output — `expectsOutputToContain` consumes a line on its
     * first match, so two facts on one line cannot both be asserted through it.
     *
     * @param  array<string, mixed>  $options
     * @return array{0: int, 1: string}
     */
    private function relabel(array $options): array
    {
        $code = Artisan::call('bridge:github-owed', $options);

        return [$code, Artisan::output()];
    }

    /** @return list<string> every file beside the record, the record itself excluded */
    private function siblingsOfTheRecord(): array
    {
        return array_values(array_diff(glob(GitHubWriteDebt::path().'*') ?: [], [GitHubWriteDebt::path()]));
    }

    private function assertUnwritableNaming(string $problem): void
    {
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => ($context['catalog_id'] ?? null) === 'github_write_debt.record_unwritable'
            && ($context['path'] ?? null) === GitHubWriteDebt::path()
            && str_contains((string) ($context['error'] ?? ''), $problem))->once();
    }

    /**
     * Mode 0 refuses a read only to a process without CAP_DAC_OVERRIDE; as root the file opens
     * anyway, and the test would construct a READABLE file and assert about an unreadable one.
     */
    private function skipWhenModeZeroCannotRefuseARead(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('running as root: a mode-0 file is still readable, so an unreadable record cannot be constructed');
        }
    }

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
            GitHubWriteDebt::owed(),
        );
    }
}
