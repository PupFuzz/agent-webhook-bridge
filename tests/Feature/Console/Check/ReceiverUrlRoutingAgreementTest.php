<?php

namespace Tests\Feature\Console\Check;

use App\Bridge\Support\ReceiverUrl;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `ReceiverUrl::deliversTo()`'s endpoint rule, DERIVED FROM THE ROUTER RATHER THAN ENUMERATED
 * (card#9150 r3).
 *
 * ⛔ WHY THIS EXISTS. Three rounds of this card each found ONE more spelling that the receiver
 * routes and the predicate called absent — percent-encoding, then a trailing slash — and each
 * was fixed as a member. A hand-enumerated list of spellings is a list that is wrong again at
 * the next round; what closes the class is asserting the PROPERTY: for any spelling of THIS
 * install's endpoint, `deliversTo()` must agree with whether that URL actually reaches the
 * receiver's route.
 *
 * ⭐ THE ORACLE IS THE REAL ROUTER, not a second copy of the rule. Each case is matched
 * through `app('router')->getRoutes()->match()`, so this test cannot agree with a
 * reimplementation of itself — and a future Laravel or Symfony release that changes what the
 * matcher tolerates reds here instead of silently making the predicate wrong in either
 * direction.
 *
 * ⚠ SCOPED TO SPELLINGS OF ONE ENDPOINT, and that bound is real: a URL on a DIFFERENT host
 * also routes on this box, and is legitimately a different install. Host, port and scheme are
 * not part of what a router can answer for, so they are pinned in
 * `Tests\Unit\Support\ReceiverUrlTest` against RFC 3986 instead.
 *
 * ⛔ BOTH DIRECTIONS ARE FAILURES, and they are not symmetric in cost. Predicate `false` on a
 * routable spelling is a `fail` that reds a healthy install — loud, and the defect this card
 * kept re-finding. Predicate `true` on a NON-routable one is the inverse and is WORSE: the
 * hook delivers nothing, `bridge:check` reports `ok`, and the agent is deaf with nothing
 * saying so.
 */
class ReceiverUrlRoutingAgreementTest extends TestCase
{
    private const HOST = 'https://bridge.example.com';

    private const CANONICAL = '/webhooks/github';

    /**
     * Spellings of this install's own receiver endpoint.
     *
     * ⚑ The expectation column is deliberately absent: what each SHOULD be is the router's to
     * say, and writing it here by hand would re-create the enumeration this class replaces.
     *
     * @return list<array{0: string}>
     */
    public static function spellings(): array
    {
        return array_map(fn (string $p): array => [$p], [
            '/webhooks/github',        // canonical
            '/webhooks/github/',       // trailing slash — the r2 finding
            '/webhooks/github//',      // several trailing slashes
            '//webhooks/github',       // LEADING double slash — the r3 claim
            '///webhooks/github',
            '/webhooks//github',       // interior double slash
            '/Webhooks/github',        // path case
            '/webhooks/GitHub',        // provider-segment case
            '/webhooks/gitlab',        // a different provider entirely
            '/webhooks',               // the prefix alone
        ]);
    }

    #[DataProvider('spellings')]
    public function test_the_predicate_agrees_with_the_router_on_every_spelling(string $path): void
    {
        $url = self::HOST.$path.'?b=owner/repo';
        $receiver = self::HOST.self::CANONICAL.'?b=owner/repo';

        $this->assertSame(
            $this->reachesTheReceiverRoute($url),
            ReceiverUrl::deliversTo($url, $receiver),
            "deliversTo() disagrees with the ROUTER about `{$path}`. If the router routes it and the predicate says no, "
                .'this is the false-`fail` class again — a healthy install reddened. If the router does NOT route it and '
                .'the predicate says yes, it is the inverse and worse: a dead hook reported ok, agent deaf, check green.',
        );
    }

    public function test_the_oracle_discriminates(): void
    {
        // ⛔ THE CONTROL. Every assertion above compares two values; if the oracle answered a
        // constant, the whole class would pass over a predicate that did too. It must say YES
        // to the canonical spelling and NO to something the receiver plainly does not serve.
        $this->assertTrue($this->reachesTheReceiverRoute(self::HOST.self::CANONICAL.'?b=owner/repo'));
        $this->assertFalse($this->reachesTheReceiverRoute(self::HOST.'/not-a-receiver-path?b=owner/repo'));
        // And it separates a different PROVIDER from a different SPELLING — the distinction
        // the first cut of the oracle could not make.
        $this->assertFalse($this->reachesTheReceiverRoute(self::HOST.'/webhooks/gitlab?b=owner/repo'));
    }

    /**
     * Does a POST to this URL reach the receiver's route at all?
     *
     * ⭐ MATCHED ON THE REQUEST, which is what makes this an oracle rather than an opinion.
     * ⚠ It asks about ROUTING ONLY — not about the HMAC, the secret or the scope — because
     * routing is the whole of what a URL SPELLING can decide.
     *
     * ⛔ `Request::path()` IS DELIBERATELY NOT CONSULTED, and this is the correction that
     * matters most here. `path()` is `trim(getPathInfo(), '/')`, so it folds LEADING slashes
     * away — but the matcher reads `getPathInfo()`, which keeps them. Measured through this
     * app's kernel: `//webhooks/github?b=…` has `path() === 'webhooks/github'` and yet matches
     * NO ROUTE and answers **404**. A predicate mirroring `path()` would therefore call that
     * dead spelling equivalent and report a green `ok` for a webhook that delivers nothing.
     */
    private function reachesTheReceiverRoute(string $url): bool
    {
        return $this->routeIdentity($url) === $this->routeIdentity(self::HOST.self::CANONICAL.'?b=owner/repo');
    }

    /**
     * What the router RESOLVES this URL to: the matched route plus its parameters, or null.
     *
     * ⚑ THE PARAMETERS ARE PART OF THE IDENTITY, and leaving them out was the first cut's
     * error. `webhooks/{provider}` matches `/webhooks/gitlab` and `/webhooks/GitHub` just as
     * it matches `/webhooks/github`, so a route-URI-only oracle called a DIFFERENT PROVIDER a
     * spelling of the same endpoint — which would have demanded the predicate answer `true`
     * for a subscription this install does not hold. Two URLs are spellings of ONE endpoint
     * exactly when the router resolves them to the same route with the same parameters.
     */
    private function routeIdentity(string $url): ?string
    {
        $request = Request::create($url, 'POST', [], [], [], [], '{}');

        try {
            $route = app('router')->getRoutes()->match($request);
        } catch (\Throwable) {
            return null;
        }

        return $route->uri().' '.json_encode($route->parameters(), JSON_THROW_ON_ERROR);
    }
}
