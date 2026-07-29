<?php

namespace Tests\Unit\Bridge\Support;

use App\Bridge\Support\SystemChannelProbeEnvironment;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The production side of the channel-probe seam, against REAL endpoints (DL-242 stage 5b).
 *
 * WHY IT EXISTS AT ALL: nothing reached either `stream_socket_client` call before this
 * stage. Both were disclosed gaps under the golden harness, no unit test covered them,
 * and the only exercise they ever got was an operator running `bridge:check` on an
 * install with a live channel. So this is the first evidence the probe works — and the
 * seam's other side is a fake, which proves nothing about the connect itself.
 *
 * REAL LISTENERS, NOT MOCKS, ON BOTH TRANSPORTS. A test that faked the socket layer would
 * be asserting the same code the fake replaces. Each transport is asserted LIVE and then
 * again after the listener closes, because "something is listening" is the only question
 * the caller asks and the two answers must be told apart on a real host.
 *
 * THE ERROR TEXT IS ASSERTED AS NON-EMPTY, NEVER BY ITS WORDING. It is the transport's
 * own, it reaches the operator's message, and it is platform-dependent — which is exactly
 * why the golden harness pins it rather than capturing it. Asserting `Connection refused`
 * here would re-import the host dependency the seam exists to remove.
 */
class SystemChannelProbeEnvironmentTest extends TestCase
{
    private string $dir;

    /** @var resource|null */
    private $server = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/probe-env-'.uniqid();
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            fclose($this->server);
        }
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_a_live_unix_socket_answers_connected_with_no_error(): void
    {
        $path = $this->dir.'/live.sock';
        $this->server = $this->bind('unix://'.$path);

        $this->assertSame(
            ['connected' => true, 'error' => ''],
            (new SystemChannelProbeEnvironment)->probe('unix://'.$path),
        );
    }

    /**
     * The stale-socket case the caller's warn text is about, and the reason a present
     * socket FILE proves nothing: closing the listener leaves the path on disk, so
     * existence and liveness diverge here exactly as they do on a host whose session died.
     */
    public function test_a_unix_socket_whose_listener_closed_answers_not_connected(): void
    {
        $path = $this->dir.'/stale.sock';
        $server = $this->bind('unix://'.$path);
        fclose($server);

        $result = (new SystemChannelProbeEnvironment)->probe('unix://'.$path);

        $this->assertFileExists($path, 'the socket file must survive its listener — otherwise this asserts the absent-path case instead');
        $this->assertFalse($result['connected']);
        $this->assertNotSame('', $result['error']);
    }

    public function test_an_absent_unix_socket_answers_not_connected_rather_than_raising(): void
    {
        $result = (new SystemChannelProbeEnvironment)->probe('unix://'.$this->dir.'/no-such.sock');

        $this->assertFalse($result['connected']);
        $this->assertNotSame('', $result['error']);
    }

    public function test_a_live_loopback_listener_answers_connected_with_no_error(): void
    {
        $this->server = $this->bind('tcp://127.0.0.1:0');

        $this->assertSame(
            ['connected' => true, 'error' => ''],
            (new SystemChannelProbeEnvironment)->probe('tcp://'.$this->addressOf($this->server)),
        );
    }

    public function test_a_loopback_port_nothing_listens_on_answers_not_connected(): void
    {
        // The port is bound, read back, then released — so it is a real port that was
        // reachable a moment ago, rather than a number guessed to be free.
        $server = $this->bind('tcp://127.0.0.1:0');
        $address = $this->addressOf($server);
        fclose($server);

        $result = (new SystemChannelProbeEnvironment)->probe('tcp://'.$address);

        $this->assertFalse($result['connected']);
        $this->assertNotSame('', $result['error']);
    }

    /** @return resource */
    private function bind(string $dsn)
    {
        $server = stream_socket_server($dsn, $errno, $errstr);
        $this->assertNotFalse($server, "could not bind {$dsn}: {$errstr}");

        return $server;
    }

    /** @param resource $server */
    private function addressOf($server): string
    {
        $name = stream_socket_get_name($server, false);
        $this->assertIsString($name);

        return $name;
    }
}
