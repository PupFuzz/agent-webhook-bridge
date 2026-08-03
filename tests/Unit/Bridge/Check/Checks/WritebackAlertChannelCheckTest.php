<?php

namespace Tests\Unit\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Checks\WritebackAlertChannelCheck;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use App\Bridge\Writeback\AlertChannel;
use App\Bridge\Writeback\WritebackConfig;
use Illuminate\Support\Facades\File;
use Tests\Support\MaterializesChecks;
use Tests\TestCase;

/**
 * THE WHOLE CHECK, because the golden suite reaches NONE of it: `alert_channel` is
 * optional and absent from all seven writeback fixtures, so every branch below is
 * justified by reading alone until this test exists. (`docs/check-golden-coverage.md`
 * would not list the gap either — it enumerates predicates in `CheckCommand::handle()`,
 * and this check's predicates never lived there as top-level `if`s.)
 *
 * The url leg's assertions are deliberately about DEFERRAL, not about url grammar:
 * `LocalhostUrl` is the authority the runtime sender enforces, and the userinfo case
 * below is the one a hand-rolled copy in this check once dropped — green-lighting a url
 * the sender then refused. Restating the grammar here would re-create exactly that
 * second authority, so these tests pin only that the verdict is the sender's.
 */
class WritebackAlertChannelCheckTest extends TestCase
{
    use MaterializesChecks;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/wb-alert-check-'.uniqid();
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_an_unconfigured_alert_channel_reports_nothing(): void
    {
        $this->assertSame([], $this->findings(null));

        // The discriminating control, in-test: the same check on the same context shape
        // DOES yield once a channel is present, so the emptiness above is the null leg
        // and not a check that can never speak.
        $this->assertNotSame([], $this->findings(new AlertChannel(url: 'http://127.0.0.1:9010/alert')));
    }

    public function test_both_transports_set_is_reported_as_neither(): void
    {
        $findings = $this->findings(new AlertChannel(socket: $this->dir.'/alert.sock', url: 'http://127.0.0.1:9010/alert'));

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Warn, $findings[0]['severity']);
        $this->assertStringContainsString('specify exactly one of socket or url', $findings[0]['message']);
        // Warn, never fail: a bad alert channel must not fail the install check, because
        // at runtime it only makes the (caught) alert push fail — the move still happens.
        $this->assertStringContainsString('the writeback move is unaffected', $findings[0]['message']);
    }

    public function test_a_channel_with_neither_transport_is_reported_the_same_way(): void
    {
        $findings = $this->findings(new AlertChannel(tokenPath: $this->dir.'/token'));

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Warn, $findings[0]['severity']);
        $this->assertStringContainsString('specify exactly one of socket or url', $findings[0]['message']);
    }

    public function test_a_socket_whose_parent_dir_is_missing_warns_about_the_channel_server(): void
    {
        $socket = $this->dir.'/not-created-yet/alert.sock';

        $findings = $this->findings(new AlertChannel(socket: $socket));

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Warn, $findings[0]['severity']);
        $this->assertStringContainsString(
            'socket parent dir '.$this->dir.'/not-created-yet does not exist',
            $findings[0]['message'],
        );
    }

    /**
     * The socket file itself is NOT required to exist — the channel server creates it at
     * start, so requiring it would warn on every healthy pre-start install.
     */
    public function test_a_socket_whose_parent_dir_exists_is_ok_even_before_the_server_creates_it(): void
    {
        $socket = $this->dir.'/alert.sock';
        $this->assertFileDoesNotExist($socket);

        $findings = $this->findings(new AlertChannel(socket: $socket));

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Ok, $findings[0]['severity']);
        $this->assertStringContainsString("socket {$socket} (parent dir present)", $findings[0]['message']);
    }

    public function test_a_loopback_url_is_ok(): void
    {
        $findings = $this->findings(new AlertChannel(url: 'http://127.0.0.1:9010/alert'));

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Ok, $findings[0]['severity']);
        $this->assertStringContainsString('url http://127.0.0.1:9010/alert (localhost)', $findings[0]['message']);
    }

    public function test_a_non_loopback_url_is_warned_with_the_senders_own_verdict(): void
    {
        $findings = $this->findings(new AlertChannel(url: 'http://example.com/alert'));

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Warn, $findings[0]['severity']);
        $this->assertStringContainsString(
            'writeback.json alert_channel: url must point at 127.0.0.1, localhost, or [::1]',
            $findings[0]['message'],
        );
        $this->assertStringContainsString('the alert push will fail (caught) until fixed', $findings[0]['message']);
    }

    /**
     * The regression the deferral exists for: a loopback host with a userinfo component
     * is rejected by the runtime sender, so the check must reject it too.
     */
    public function test_a_userinfo_url_is_rejected_exactly_as_the_sender_rejects_it(): void
    {
        $findings = $this->findings(new AlertChannel(url: 'http://user:pw@127.0.0.1:9010/alert'));

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Warn, $findings[0]['severity']);
        $this->assertStringContainsString('must not contain a userinfo component', $findings[0]['message']);
    }

    /**
     * The card#5698 arm: the parent dir exists, but this process cannot traverse to it, so
     * the "does not exist" verdict was an accusation the stat could not support.
     */
    public function test_a_socket_parent_dir_that_cannot_be_seen_is_unvalidated_not_reported_missing(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $this->markTestSkipped('root bypasses directory permission checks');
        }
        $parent = $this->dir.'/locked';
        File::ensureDirectoryExists($parent.'/inner');
        chmod($parent, 0000);

        try {
            $findings = $this->findings(new AlertChannel(socket: $parent.'/inner/alert.sock'));
        } finally {
            chmod($parent, 0755);
        }

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Unvalidated, $findings[0]['severity']);
        $this->assertStringContainsString('is not visible to this user', $findings[0]['message']);
        $this->assertStringNotContainsString('does not exist', $findings[0]['message']);
    }

    /** @return list<array{severity: Severity, message: string}> */
    private function findings(?AlertChannel $channel): array
    {
        $ctx = new CheckContext;
        $ctx->writeback = new WritebackConfig(7, [], $channel);

        return array_map(
            fn (Finding $f) => ['severity' => $f->severity, 'message' => $f->message],
            $this->findingsOf((new WritebackAlertChannelCheck), $ctx),
        );
    }
}
