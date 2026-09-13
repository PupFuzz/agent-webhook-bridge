<?php

namespace Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * The bundled reference channel server's SOURCE, read as a test subject — the one read
 * shared by every PHP leg that has to check a claim about the far end of the channel seam
 * against the file this repo actually ships (canon #7 CHECK, canon #5).
 *
 * ⭐ WHY A PHP TEST READS A `.mjs` AT ALL. The channel server is the DECLARING end of the
 * push contract and the bridge is the CONSUMING end, and every property the bridge depends
 * on — that the server sends `client_version`, that it sends the delivery-receipt header
 * this app parses — is a property of THAT file which no amount of PHP-side testing can
 * observe. `ClientVersionTest` learned it the expensive way: a version-comparison leg
 * "can never see the difference between a release that reports and one that does not", and
 * stayed green through exactly that case. So the join is asserted against the file.
 *
 * ⛔ THE NON-VACUITY GUARD IS THE POINT OF THE SHARED READ, not the `file_get_contents`.
 * Every assertion built on this source is a `assertStringContainsString` — an ABSENCE
 * check — and an absence in an empty string, a moved file or a file that is no longer the
 * channel server passes every one of them at once. So the source is established as the
 * channel server here, once, before any caller looks for anything in it.
 *
 * ⚠ WHAT IT CANNOT ESTABLISH, stated because a caller could otherwise read it wider: this
 * is the BUNDLED reference server only. `channel.url` / `channel.socket` are
 * operator-configurable, so an endpoint this repo did not ship is outside this population
 * and is covered instead by the bridge REPORTING what that endpoint declared rather than
 * assuming for it.
 */
final class BundledChannelServer
{
    /** Repo-relative path of the bundled entry point, as `base_path()` takes it. */
    public const ENTRY = 'examples/channel-servers/agent-webhook-bridge-channel.mjs';

    public static function source(): string
    {
        $source = @file_get_contents(base_path(self::ENTRY));

        Assert::assertNotFalse($source, self::ENTRY.' did not read, so anything asserted over it measured nothing');
        // assertTrue over assertStringContainsString: PHPUnit prints a failed haystack in
        // full, and this one is the whole server.
        Assert::assertTrue(
            str_contains((string) $source, 'CallToolRequestSchema'),
            self::ENTRY.' did not read as the channel server, so anything asserted over it measured nothing',
        );

        return (string) $source;
    }
}
