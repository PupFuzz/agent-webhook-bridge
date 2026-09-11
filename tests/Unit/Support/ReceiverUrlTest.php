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

            // A DIFFERENT install stays different — the endpoint is reduced, never dissolved.
            ['different host', 'https://someone-else.example.net/webhooks/github?b=owner/repo', self::RECEIVER, false, false],
            ['different path', 'https://bridge.example.com/webhooks/kanban?b=owner/repo', self::RECEIVER, false, false],

            // ⭐ THE SPELLINGS THAT REACH THE SAME ROUTE, each one a `fail` on a HEALTHY
            // install until it was normalised. The trailing-slash rows are MEASURED through
            // the real router (all three reach the same middleware and fail at the same
            // point); the host-case and default-port rows are RFC 3986 §6.2.2.1/§6.2.3
            // syntax-based normalization — properties of how a delivery reaches the box, which
            // no test here can drive.
            ['trailing slash on the path', 'https://bridge.example.com/webhooks/github/?b=owner/repo', self::RECEIVER, true, false],
            ['several trailing slashes', 'https://bridge.example.com/webhooks/github//?b=owner/repo', self::RECEIVER, true, false],
            ['host case differs', 'https://BRIDGE.Example.COM/webhooks/github?b=owner/repo', self::RECEIVER, true, false],
            ['scheme case differs', 'HTTPS://bridge.example.com/webhooks/github?b=owner/repo', self::RECEIVER, true, false],
            ['explicit default port', 'https://bridge.example.com:443/webhooks/github?b=owner/repo', self::RECEIVER, true, false],

            // ⛔ AND THE OPPOSITE DIRECTION, which is what stops the normalisation dissolving
            // the endpoint. Both are MEASURED to deliver NOTHING — `/Webhooks/github` answers
            // 404 (no route) and `/webhooks/GitHub` answers 400 `invalid_provider` — so
            // calling them equivalent would invent a hook that does not work.
            ['path case differs', 'https://bridge.example.com/Webhooks/github?b=owner/repo', self::RECEIVER, false, false],
            ['provider segment case differs', 'https://bridge.example.com/webhooks/GitHub?b=owner/repo', self::RECEIVER, false, false],
            // ⛔ http IS NOT https, and this row is the one over-normalisation direction that
            // was unwitnessed: forcing the scheme to `https` left this class green. The
            // regression it admits is the INVERSE defect — a hook registered at `http://…`
            // would report `ok` while GitHub's delivery takes a 301 it does not follow, so the
            // agent is deaf and the check is green.
            ['scheme differs (http vs https)', 'http://bridge.example.com/webhooks/github?b=owner/repo', self::RECEIVER, false, false],
            // A NON-default port is a different endpoint, not a spelling.
            ['explicit non-default port', 'https://bridge.example.com:8443/webhooks/github?b=owner/repo', self::RECEIVER, false, false],
            // Credentials in the userinfo are preserved, so a credentialed endpoint never
            // equals an uncredentialed one.
            ['userinfo present', 'https://svc:pw@bridge.example.com/webhooks/github?b=owner/repo', self::RECEIVER, false, false],

            ['different scope', 'https://bridge.example.com/webhooks/github?b=owner/other', self::RECEIVER, false, false],
            // The stated bound: an extra parameter the receiver would ignore still reads as
            // absent, because the ruling authorised normalising ENCODING and nothing else.
            ['extra query parameter', self::RECEIVER.'&x=1', self::RECEIVER, false, false],
            ['no query at all', 'https://bridge.example.com/webhooks/github', self::RECEIVER, false, false],

            // ⛔ A FRAGMENT IS NEVER TRANSMITTED, AND ITS POSITION DECIDES EVERYTHING (r5).
            // Before the `#` the query does not exist on the wire at all: GitHub POSTs
            // `/webhooks/github` with NO query string, `query('b')` is null and the receiver
            // answers `invalid_scope` 400 — so `absent` is the true verdict, and answering
            // `true` here was the SILENT false-`ok` this leg exists to prevent. After the `#`
            // the fragment is dropped and the delivery is byte-identical to the canonical one,
            // so the hook is LIVE. Both measured through `Request::create()->query('b')`.
            ['fragment before the query', 'https://bridge.example.com/webhooks/github#x?b=owner/repo', self::RECEIVER, false, false],
            ['empty fragment before the query', 'https://bridge.example.com/webhooks/github#?b=owner/repo', self::RECEIVER, false, false],
            ['fragment after the query', self::RECEIVER.'#frag', self::RECEIVER, true, false],

            // Both callers read this field out of a decoded API response where it may be
            // absent; no URL matches nothing, which is the safe direction for each.
            ['null url', null, self::RECEIVER, false, false],
        ];
    }

    public function test_a_plus_in_the_path_is_a_literal_plus_and_never_a_space(): void
    {
        // ⛔ THE ONE PROPERTY THAT SEPARATES `rawurldecode` FROM `urldecode`, and it is NOT
        // witnessable through the agreement suite: that population is generated from THIS
        // install's canonical path, which contains neither `+` nor `%20`, so the two functions
        // are extensionally identical over it and a mutation between them reds nothing there.
        // ⚠ That used to say *"over all 41 spellings"* — a count of a GENERATED population,
        // which is a restatement that goes stale silently, and had (it was 40). The claim is a
        // predicate now, asserted where the population lives:
        // `ReceiverUrlRoutingAgreementTest::test_the_path_population_witnesses_no_plus_and_no_percent_20`.
        // Measured, not assumed — which is why the case is pinned HERE, where the base URL is
        // a parameter.
        //
        // `+` is a LITERAL PLUS in a path (RFC 3986); only a QUERY treats it as a space. So a
        // receiver served under `/web+hooks` must not match a hook spelled `/web%20hooks`:
        // `urldecode` folds both to `/web hooks` and calls them one endpoint, which is the
        // over-normalisation direction — a hook that delivers nothing reported `ok`.
        $receiver = ReceiverUrl::for('https://bridge.example.com/web+hooks', 'github', 'owner/repo');

        $this->assertSame('https://bridge.example.com/web+hooks/github?b=owner/repo', $receiver);
        $this->assertFalse(
            ReceiverUrl::deliversTo('https://bridge.example.com/web%20hooks/github?b=owner/repo', $receiver),
            'a percent-encoded SPACE is not a literal PLUS — folding them is `urldecode`, and it invents an endpoint',
        );
        // The same path spelled with its `+` percent-encoded IS the same endpoint.
        $this->assertTrue(ReceiverUrl::deliversTo('https://bridge.example.com/web%2Bhooks/github?b=owner/repo', $receiver));
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
