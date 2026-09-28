<?php

namespace App\Bridge\ClientUpdate;

use App\Bridge\Tools\BoardToolDispatcher;

/**
 * What a board-tools call says about WHO made it (card#10567 B4): the optional `caller` and
 * `launch` keys of the request body, reduced to values the fleet ledger may store.
 *
 *   caller   one of {@see ExemptCaller}'s values — a probe, a self-certification, an operator.
 *   launch   {id, bridge_release}: sent by a client started through the updater's entry point,
 *            naming the launch it belongs to and the bridge release that launch runs (design
 *            review r3-M4 — without it nothing on the wire says which release is running).
 *
 * ⛔ NEITHER CAN REFUSE A CALL. Like `client_version` (DL-364), an absent, mistyped or malformed
 * value is read as absent; the call proceeds exactly as it would without it. The doors read these
 * off the wire and hand them to {@see BoardToolDispatcher}, which records them and branches on
 * nothing.
 *
 * ⛔ WHITELISTED, NOT SANITISED: every value is printed by `bridge:client-fleet`, so a launch id is
 * `[0-9A-Za-z-]`, at most 64 characters, and a release is bare `X.Y.Z` — anything else is dropped
 * whole, never truncated.
 */
final class CallerReport
{
    private const LAUNCH_ID = '/\A[0-9A-Za-z-]{1,64}\z/';

    private const BARE_RELEASE = '/\A[0-9]{1,9}\.[0-9]{1,9}\.[0-9]{1,9}\z/';

    private function __construct(
        public readonly ?ExemptCaller $caller,
        public readonly ?string $launchId,
        public readonly ?string $launchBridgeRelease,
    ) {}

    public static function fromCall(mixed $caller, mixed $launch): self
    {
        $exempt = is_string($caller) ? ExemptCaller::tryFrom($caller) : null;
        $id = null;
        $release = null;
        if (is_array($launch)
            && is_string($launch['id'] ?? null) && preg_match(self::LAUNCH_ID, $launch['id']) === 1
            && is_string($launch['bridge_release'] ?? null) && preg_match(self::BARE_RELEASE, $launch['bridge_release']) === 1) {
            $id = $launch['id'];
            $release = $launch['bridge_release'];
        }

        return new self($exempt, $id, $release);
    }

    /** A call that said nothing about its caller: what every client before the ledger sends. */
    public static function unreported(): self
    {
        return new self(null, null, null);
    }

    public function isExempt(): bool
    {
        return $this->caller !== null;
    }
}
