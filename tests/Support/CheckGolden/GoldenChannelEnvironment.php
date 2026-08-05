<?php

namespace Tests\Support\CheckGolden;

use App\Bridge\Support\ChannelProbeEnvironment;

/**
 * The pinned channel-endpoint liveness answer the golden fixtures run against
 * (DL-242 stage 5b).
 *
 * BOUND FOR EVERY FIXTURE, THOUGH NONE REACHES IT TODAY — and that is the point. The
 * unix probe needs a real socket FILE no fixture creates, and the one `channel.url`
 * fixture has no port, so the golden corpus reaches neither connect site. A fixture author who
 * later writes an explicit port would, without this, capture whether the operator's box
 * (or CI's runner) happens to have something listening on it — a host input of exactly
 * the class {@see PinnedHost} exists to eliminate, arriving silently as a green run.
 *
 * NOT CONFIGURABLE, unlike {@see GoldenSshEnvironment}. That one is parameterised
 * because ssh fixtures pin four different round trips; here every fixture wants the same
 * answer, and a live-endpoint fixture would need to justify itself by naming which
 * predicate it newly observes. The error text is deliberately not a plausible transport
 * message — if it ever surfaces in a golden file, it should read as a pin rather than as
 * a real host's diagnosis.
 */
final class GoldenChannelEnvironment implements ChannelProbeEnvironment
{
    /** @return array{connected: bool, error: string} */
    public function probe(string $dsn): array
    {
        return ['connected' => false, 'error' => 'pinned by the golden harness: no fixture endpoint'];
    }
}
