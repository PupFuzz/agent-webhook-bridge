<?php

namespace Tests\Unit\Support;

use App\Bridge\ClientUpdate\ClientPackManifest;
use App\Bridge\Support\ChannelSnapshotManifest;
use PHPUnit\Framework\TestCase;

/**
 * `ChannelSnapshotManifest::CLIENT_RELEASE` is a COPY of the client-pack release grammar, kept
 * there so the snapshot probe's scanned closure does not grow (its docblock says why). A copy
 * with nothing checking it drifts; this is the check (DL-445).
 */
class ClientRootReleaseGrammarTest extends TestCase
{
    public function test_the_probe_side_copy_is_the_client_pack_release_grammar(): void
    {
        $this->assertSame(ClientPackManifest::STRICT_VERSION, ChannelSnapshotManifest::CLIENT_RELEASE);
    }
}
