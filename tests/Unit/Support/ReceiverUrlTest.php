<?php

namespace Tests\Unit\Support;

use App\Bridge\Support\ReceiverUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The TWO receiver-URL match predicates, side by side (card#9150 r1).
 *
 * ⛔ THE SUBJECT IS THE DISAGREEMENT, not either predicate alone. `bridge:provision` matches
 * BYTE-FOR-BYTE and `bridge:check` matches by what the receiver would ROUTE, and every case
 * below is fed to BOTH so the divergence is a measured property rather than two docblocks
 * claiming it. A future edit that "simplifies" them into one reds here whichever direction it
 * collapses.
 *
 * ⭐ WHY THEY DIVERGE. `?b=owner%2Frepo` and `?b=owner/repo` are the same hook to the
 * receiver — `VerifyHmacSignature` reads the scope through `$request->query('b')`, which
 * decodes once — and a live consumer install registers the encoded form. Byte equality
 * therefore reported that seat's HEALTHY webhook as missing, and the check's negative arm is
 * a `fail` that moves the exit code, so it reddened a working install. The check was widened
 * and provision deliberately was not: what provision treats as already-existing decides
 * whether it CREATES, which is a change to what the system accepts.
 */
class ReceiverUrlTest extends TestCase
{
    private const RECEIVER = 'https://bridge.example.com/webhooks/github?b=owner/repo';

    /**
     * `[label, live hook URL, receiver URL, deliversTo, matchesExactly]`.
     *
     * @return list<array{0: string, 1: ?string, 2: string, 3: bool, 4: bool}>
     */
    public static function cases(): array
    {
        $encoded = 'https://bridge.example.com/webhooks/github?b=owner%2Frepo';

        return [
            ['identical', self::RECEIVER, self::RECEIVER, true, true],

            // ⭐ THE CASE THE ROUND EXISTS FOR, and the only row where the two predicates
            // disagree in the direction that matters: the hook is live, provision would still
            // create beside it (deliberately), and the check must not call it missing.
            ['encoded hook, plain receiver', $encoded, self::RECEIVER, true, false],
            // THE MIRROR, which is why the predicate is symmetric rather than special-casing
            // one side. Unreachable from this product's own config today — `ScopeId` refuses a
            // `%` in a declared scope — but the predicate is not the place to encode that.
            ['plain hook, encoded receiver', self::RECEIVER, $encoded, true, false],

            // ⛔ DOUBLE ENCODING IS NOT EQUIVALENT, decided from the receiver: it decodes ONCE
            // to the literal `owner%2Frepo`, which `ScopeId` refuses, so the delivery is a 400
            // and the hook feeds nothing. Absent is the correct answer.
            ['double-encoded hook', 'https://bridge.example.com/webhooks/github?b=owner%252Frepo', self::RECEIVER, false, false],

            // The endpoint is never normalised — a different install is a different install.
            ['different host', 'https://someone-else.example.net/webhooks/github?b=owner/repo', self::RECEIVER, false, false],
            ['different path', 'https://bridge.example.com/webhooks/kanban?b=owner/repo', self::RECEIVER, false, false],
            ['host case differs', 'https://BRIDGE.example.com/webhooks/github?b=owner/repo', self::RECEIVER, false, false],

            ['different scope', 'https://bridge.example.com/webhooks/github?b=owner/other', self::RECEIVER, false, false],
            // The stated bound: an extra parameter the receiver would ignore still reads as
            // absent, because the ruling authorised normalising ENCODING and nothing else.
            ['extra query parameter', self::RECEIVER.'&x=1', self::RECEIVER, false, false],
            ['no query at all', 'https://bridge.example.com/webhooks/github', self::RECEIVER, false, false],

            // Both callers read this field out of a decoded API response where it may be
            // absent; no URL matches nothing, which is the safe direction for each.
            ['null url', null, self::RECEIVER, false, false],
        ];
    }

    #[DataProvider('cases')]
    public function test_both_predicates_answer_as_specified(string $label, ?string $live, string $receiver, bool $delivers, bool $exact): void
    {
        $this->assertSame($delivers, ReceiverUrl::deliversTo($live, $receiver), "deliversTo disagreed on: {$label}");
        $this->assertSame($exact, ReceiverUrl::matchesExactly($live, $receiver), "matchesExactly disagreed on: {$label}");
    }

    public function test_the_two_predicates_actually_disagree_somewhere(): void
    {
        // ⛔ THE CONTROL ON THE TABLE ABOVE. Every row is asserted against both predicates, so
        // a collapse of one into the other reds — but only if some row separates them. Without
        // this, deleting the encoded rows would leave a green suite over two identical
        // predicates and the divergence would be documented and untested.
        $separating = array_filter(self::cases(), static fn (array $c): bool => $c[3] !== $c[4]);

        $this->assertNotEmpty(
            $separating,
            'no case distinguishes deliversTo from matchesExactly, so this suite would pass if they were the same predicate',
        );
    }

    public function test_for_composes_the_unencoded_spelling_provision_has_always_registered(): void
    {
        // The composition is shared by both commands and is NOT part of the divergence: a
        // change here would move what `bridge:provision` writes upstream.
        $this->assertSame(self::RECEIVER, ReceiverUrl::for('https://bridge.example.com/webhooks', 'github', 'owner/repo'));
        $this->assertSame(self::RECEIVER, ReceiverUrl::for('https://bridge.example.com/webhooks/', 'github', 'owner/repo'));
    }
}
