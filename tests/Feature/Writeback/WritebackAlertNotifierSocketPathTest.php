<?php

namespace Tests\Feature\Writeback;

use App\Bridge\Support\PastedSecretShape;
use App\Bridge\Writeback\WritebackAlertNotifier;
use ReflectionMethod;
use Tests\TestCase;

/**
 * card#11261 — the alert socket's format gate prints the configured value in the exception the
 * notifier then logs. A token pasted as `alert_channel.socket` must reach it as a fingerprint.
 */
class WritebackAlertNotifierSocketPathTest extends TestCase
{
    public function test_a_token_pasted_as_the_alert_socket_is_refused_as_a_fingerprint(): void
    {
        $pasted = str_repeat('ab12', 16);

        $message = $this->refusalFor($pasted);

        $this->assertSame("writeback alert socket is not a valid absolute path (no '..'): ".PastedSecretShape::displayPathSetting($pasted), $message);
        $this->assertStringContainsString(PastedSecretShape::fingerprint($pasted), $message);
        $this->assertStringNotContainsString($pasted, $message);
    }

    public function test_a_relative_path_is_still_printed_as_written(): void
    {
        $this->assertSame("writeback alert socket is not a valid absolute path (no '..'): rel/alert.sock", $this->refusalFor('rel/alert.sock'));
    }

    private function refusalFor(string $socket): string
    {
        $validate = new ReflectionMethod(WritebackAlertNotifier::class, 'validateSocketPath');

        try {
            $validate->invoke(new WritebackAlertNotifier, $socket);
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }
        $this->fail('a non-absolute alert socket was accepted');
    }
}
