<?php

namespace Tests\Feature\Console\Check;

use App\Bridge\Support\ReceiverUrl;
use App\Http\Middleware\VerifyHmacSignature;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * `ReceiverUrl::deliversTo()` against WHAT THIS INSTALL'S RECEIVER ACTUALLY DOES with a URL,
 * over generated populations rather than enumerated ones (card#9150 r3/r4/r5).
 *
 * ⛔ WHY THIS EXISTS. Three rounds of this card each found ONE more spelling that the receiver
 * routes and the predicate called absent — percent-encoding, then a trailing slash — and each
 * was fixed as a member. A hand-enumerated list of spellings is a list that is wrong again at
 * the next round; what closes the class is asserting the PROPERTY: for any spelling of THIS
 * install's receiver URL, `deliversTo()` must agree with whether that URL actually delivers.
 *
 * ⭐ THE ORACLE IS THE RECEIVER ITSELF, not a second copy of the rule — and it has TWO terms,
 * because delivering here takes both:
 *   - the ROUTE the request matches, WITH its resolved parameters
 *     (`app('router')->getRoutes()->match()`);
 *   - the SCOPE the receiver then reads out of it, `$request->query('b')` — literally the
 *     expression `App\Http\Middleware\VerifyHmacSignature` uses.
 * A URL that routes but hands that middleware a different `b` (or none) reaches the receiver
 * and is answered `invalid_scope` 400: it delivers NOTHING, and an oracle keyed on the route
 * alone cannot see that. So the identity is both terms, and
 * `test_the_oracle_discriminates` proves the second one is not an inert suffix.
 *
 * ⛔ THE QUERY WAS HELD FIXED HERE FOR ONE ROUND AND THAT WAS THE r4 DEFECT, MOVED RATHER THAN
 * REMOVED. This class said its population was *"transforms over the PATH, with scheme, host,
 * port and query held fixed (those are pinned in `ReceiverUrlTest` … which a router cannot
 * answer for)"*. That justification is TRUE of scheme/host/port — nothing here can drive DNS
 * or a TLS connect — and FALSE of the query, which this box answers with the same call the
 * middleware makes. So the half of the predicate that produced the ORIGINAL live defect
 * (`?b=owner%2Frepo` reported missing on a healthy install) was still being judged by a
 * hand-written table, and a live false-`ok` was sitting in it: `…/webhooks/github#x?b=owner/repo`
 * parsed as canonical-endpoint + `b=owner/repo` and answered `true`, while the receiver sees a
 * FRAGMENT — never transmitted — and reads no `b` at all. Both halves are generated now.
 *
 * ⛔ AND THE RECEIVER ARGUMENT WAS HELD FIXED FOR FIVE ROUNDS, WHICH WAS THE r6 DEFECT — the
 * SAME defect one axis further out. Every member of both populations above is compared against
 * the hand-written constant {@see self::receiver()}, which is canonical BY CONSTRUCTION, so the
 * one input the oracle never varied was the one an operator actually sets. `deliversTo()` is
 * SYMMETRIC: it asks whether two URLs are the same delivery and never whether either of them is
 * THIS install's — so a receiver composed from a mis-set `BRIDGE_RECEIVER_BASE_URL` compared
 * `true` against a hook spelled the way `bridge:check` and `docs/writeback.md` tell the operator
 * to spell it (which makes the two EQUAL by construction), while every delivery answered 404.
 * `ok`, deaf agent, nothing said so. ⭐ The third population is therefore generated over the
 * RECEIVER argument, from the receiver BASE URL an operator sets, and the leg under test is no
 * longer the bare predicate: it is what `GitHubWebhookSubscriptionCheck` actually reports —
 * {@see ReceiverUrl::reachesThisInstall()} AND {@see ReceiverUrl::deliversTo()}. Asserting the
 * predicate alone here is what let the composite be wrong while the predicate was right.
 *
 * ⚠ WHAT IS STILL HELD FIXED, and why it is not the same mistake: SCHEME, HOST and PORT. No
 * test on this box can measure whether a delivery reaches this install over them, so they are
 * pinned in `Tests\Unit\Support\ReceiverUrlTest` against RFC 3986 §6.2.2.1/§6.2.3 instead. A
 * URL on a different host also routes here and is legitimately a DIFFERENT install.
 *
 * ⛔ BOTH DIRECTIONS ARE FAILURES, AND THEY ARE NOT SYMMETRIC — which is why they are asserted
 * differently below rather than as one `assertSame`. Predicate `true` on a URL that delivers
 * nothing is the SILENT defect: `bridge:check` reports `ok`, the agent is deaf, nothing says
 * so. Predicate `false` on one that delivers is LOUD: it reds a healthy install, which is the
 * defect this card kept re-finding. Leg 1 admits NO exception. Leg 2 is asserted over the
 * complement of the disclosed residual, expressed as a PREDICATE rather than a prose list.
 */
class ReceiverUrlRoutingAgreementTest extends TestCase
{
    private const HOST = 'https://bridge.example.com';

    private const CANONICAL = '/webhooks/github';

    private const QUERY = '?b=owner/repo';

    /**
     * The two values an INSTALL actually holds — the env var and the declared scope — from
     * which `ReceiverUrl::for()` derives everything above.
     *
     * ⚠ `BASE_PATH` IS NOT `CANONICAL` MINUS A SEGMENT, AND THE DIFFERENCE IS THE r6 DEFECT:
     * `BRIDGE_RECEIVER_BASE_URL` ALREADY ENDS IN THE RECEIVER PATH, and `for()` appends the
     * provider. `test_the_composition_and_the_canonical_constants_are_one_url` is the
     * assertion that these two descriptions of one URL cannot drift apart.
     */
    private const BASE_PATH = '/webhooks';

    private const SCOPE = 'owner/repo';

    private static function receiver(): string
    {
        return self::HOST.self::CANONICAL.self::QUERY;
    }

    /**
     * Spellings of this install's receiver PATH — GENERATED, never listed.
     *
     * ⛔ A LITERAL LIST WAS THE DEFECT THIS CLASS WAS SUPPOSED TO FIX, and it shipped with one
     * (card#9150 r4). The ORACLE was derived from the router, but the POPULATION it judged was
     * ten hand-picked strings — so *"the next spelling nobody thought of reds here"* was false
     * by construction: a spelling nobody thought of is, precisely, not in a list somebody
     * wrote. Review generated a population mechanically and found **15** disagreements the
     * committed list scored zero on.
     *
     * ⭐ SO THE POPULATION IS DERIVED FROM THE CANONICAL PATH BY MECHANICAL TRANSFORMS, each
     * one a way a hand-pasted URL legitimately differs from the composed one:
     *   - percent-encode ONE character (`/webhooks/git%68ub` — measured to route, kernel 401);
     *   - duplicate ONE slash (the r3 leading-slash family);
     *   - flip ONE character's case;
     *   - insert a dot-segment, or vary the trailing bytes.
     * Nothing here says what the ANSWER should be for any of them — that is the receiver's to
     * say, which is the whole point.
     *
     * ⚠ ITS BOUND: these are transforms over the PATH. The QUERY has its own generator
     * ({@see self::queryRegions()}) rather than being held fixed — see the class docblock for
     * why holding it fixed was itself a defect. A generator is a much larger and self-widening
     * population than a list, and it is still a generator: it enumerates the transforms
     * someone thought of. What it buys is that the transforms are a far smaller thing to be
     * wrong about than the spellings they produce.
     *
     * ⛔ IT DEDUPLICATES NOTHING, deliberately. An earlier revision ran `array_unique` here and
     * silently dropped a member the generator emitted twice — which made every restatement of
     * the population's size wrong by one, on two surfaces, while looking fine.
     * `test_neither_generator_emits_one_member_twice` is the guard; the duplicate is fixed at
     * the emission site instead of hidden at the exit.
     *
     * @return list<array{0: string}>
     */
    public static function spellings(): array
    {
        $canonical = self::CANONICAL;
        $out = [$canonical];

        for ($i = 0; $i < strlen($canonical); $i++) {
            $char = $canonical[$i];
            $head = substr($canonical, 0, $i);
            $tail = substr($canonical, $i + 1);

            // ⛔ NOT THE LEADING SLASH. Encoding index 0 yields `%2Fwebhooks/github`, which is
            // no longer a PATH at all: appended to the host it makes the authority
            // `bridge.example.com%2Fwebhooks`, and the framework rejects the URI outright
            // (`Invalid URI: Host is malformed`). That is outside this population by
            // definition — these are spellings of the path, with the authority held fixed.
            // ⚠ The analogous REAL member, `/%2Fwebhooks/github` with the leading slash kept,
            // is a path and is reachable from the encode arm at index 1.
            if ($i > 0) {
                $out[] = $head.'%'.strtoupper(bin2hex($char)).$tail;
            }

            // Duplicate this one slash.
            if ($char === '/') {
                $out[] = $head.'//'.$tail;
            }

            // Flip this one character's case.
            $flipped = ctype_upper($char) ? strtolower($char) : strtoupper($char);
            if ($flipped !== $char) {
                $out[] = $head.$flipped.$tail;
            }
        }

        foreach (['/', '//', '/.', '/..', '%2F'] as $suffix) {
            $out[] = $canonical.$suffix;
        }

        // ⚠ `/webhooks//github` is NOT listed here: the slash-duplication arm above already
        // emits it. It was, and `array_unique` swallowed the collision.
        foreach (['/webhooks/./github', '/webhooks/../webhooks/github', '/./webhooks/github'] as $dotted) {
            $out[] = $dotted;
        }

        return array_map(fn (string $p): array => [$p], $out);
    }

    /**
     * Spellings of everything AFTER this install's receiver path — GENERATED, same discipline.
     *
     * ⭐ WHY THE FRAGMENT TRANSFORM IS THE POINT OF THIS GENERATOR. A `#` is not a delimiter
     * the predicate may read left-to-right: it terminates the URL for transmission purposes,
     * so a `#` BEFORE the `?` means there is no query on the wire at all, and a `#` AFTER it
     * means the fragment is dropped and the query stands. Those are opposite answers from one
     * character, and which one applies is decided by POSITION — so the transform inserts it at
     * every index rather than at the two a reviewer would think of. r4 shipped a predicate
     * that split on `?` alone, which got the first case backwards in the silent direction.
     *
     * The other transforms are the ways a hand-pasted query legitimately differs from the
     * composed one: percent-encode one character (the ORIGINAL live defect, `?b=owner%2Frepo`,
     * generated here rather than listed), flip one character's case, and vary the parameter
     * SET — extra, duplicated, empty, absent, array-valued, reordered.
     *
     * ⚠ ITS BOUND, stated because {@see self::spellings()} has one and the absence of a bound
     * reads as a claim (card#9150 r6). These are transforms over the QUERY REGION — everything
     * after the canonical path — with the path held fixed, because the path has its own
     * generator. And the two LITERAL blocks below are SUPPLEMENTS to the generated base rather
     * than the population: the `&…` block varies the parameter set (which no single-character
     * transform reaches), and the second block carries whole-region spellings the transforms
     * above do NOT describe — a `+` and a `%20` (in a query, unlike a path, `+` IS a space, and
     * both sides must agree on that), an ARRAY-valued `b[]`, and the double-encoded `%252F`
     * whose verdict is decided by what `ScopeId` refuses rather than by any transform. They are
     * a supplement and not a list of everything that could be wrong: a spelling nobody thought
     * of is, precisely, not in a block somebody wrote.
     *
     * @return list<array{0: string}>
     */
    public static function queryRegions(): array
    {
        $canonical = self::QUERY;
        $out = [$canonical, ''];

        // Percent-encode ONE character. ⛔ NOT index 0: that is the `?` itself, and encoding it
        // makes the whole region PATH — the other population's subject, not this one's.
        for ($i = 1; $i < strlen($canonical); $i++) {
            $out[] = substr($canonical, 0, $i).'%'.strtoupper(bin2hex($canonical[$i])).substr($canonical, $i + 1);
        }

        // A FRAGMENT AT EVERY POSITION, empty and non-empty.
        for ($i = 0; $i <= strlen($canonical); $i++) {
            $out[] = substr($canonical, 0, $i).'#'.substr($canonical, $i);
            $out[] = substr($canonical, 0, $i).'#frag'.substr($canonical, $i);
        }

        // Flip ONE character's case.
        for ($i = 1; $i < strlen($canonical); $i++) {
            $char = $canonical[$i];
            $flipped = ctype_upper($char) ? strtolower($char) : strtoupper($char);
            if ($flipped !== $char) {
                $out[] = substr($canonical, 0, $i).$flipped.substr($canonical, $i + 1);
            }
        }

        foreach (['&x=1', '&b=owner/repo', '&b=other/repo', '&', '&&'] as $extra) {
            $out[] = $canonical.$extra;
        }

        foreach (['?', '?b', '?b=', '?=owner/repo', '?b[]=owner/repo', '?b=owner%252Frepo', '?b=owner+repo', '?b=owner%20repo', '?x=1&b=owner/repo'] as $other) {
            $out[] = $other;
        }

        return array_map(fn (string $q): array => [$q], $out);
    }

    /**
     * Spellings of the RECEIVER BASE URL — the one input the two generators above hold fixed,
     * and the one an operator actually types (card#9150 r6).
     *
     * ⛔ WHY THIS POPULATION IS THE SYMMETRIC HALF AND NOT THREE MORE CASES. The r6 defect was
     * not a missing member: it was that `deliversTo()` compares two URLs SYMMETRICALLY and the
     * oracle fed it a receiver that was canonical by construction, so the whole receiver axis
     * was certified by nothing. Adding the three mis-settings that happened to be noticed would
     * re-mint exactly the defect r4 fixed on the path axis. So the transforms are the same
     * mechanical ones — percent-encode one character, duplicate one slash, flip one character's
     * case — applied to the base URL's PATH, plus the STRUCTURAL mis-settings, which are the
     * shapes this value is actually got wrong in:
     *   - the receiver path omitted entirely (`https://host`), which composes `/github` — the
     *     measured row that reported `ok` while every delivery 404'd;
     *   - the receiver path DOUBLED, which is the one `docs/writeback.md` records as having
     *     shipped in the docs: *"the doubled path answers 404 … delivers nothing, silently,
     *     forever"*;
     *   - the provider segment pasted into the base, with and without a query of its own — the
     *     `?z=1` member ROUTES and is the one that proves route matching alone is not the
     *     predicate;
     *   - a fragment in the base, which truncates the URL on the wire before the query exists;
     *   - a trailing slash, which `for()` trims and which must therefore stay a LIVE install.
     * Nothing here says what the answer should be for any of them — that is the receiver's to
     * say, which is the whole point of the class.
     *
     * @return list<array{0: string}>
     */
    public static function receiverBases(): array
    {
        $path = self::BASE_PATH;
        $out = [self::HOST.$path];

        for ($i = 0; $i < strlen($path); $i++) {
            $char = $path[$i];
            $head = substr($path, 0, $i);
            $tail = substr($path, $i + 1);

            // ⛔ NOT INDEX 0, for {@see self::spellings()}'s reason: encoding the leading slash
            // makes the authority malformed rather than producing a path.
            if ($i > 0) {
                $out[] = self::HOST.$head.'%'.strtoupper(bin2hex($char)).$tail;
            }
            if ($char === '/') {
                $out[] = self::HOST.$head.'//'.$tail;
            }
            $flipped = ctype_upper($char) ? strtolower($char) : strtoupper($char);
            if ($flipped !== $char) {
                $out[] = self::HOST.$head.$flipped.$tail;
            }
        }

        foreach (['', $path.'/', $path.$path, $path.'/github', $path.'/github?z=1', $path.'#x', $path.'/.', $path.'/..'] as $suffix) {
            $out[] = self::HOST.$suffix;
        }

        return array_map(fn (string $b): array => [$b], $out);
    }

    #[DataProvider('spellings')]
    public function test_the_predicate_agrees_with_the_receiver_on_every_path_spelling(string $path): void
    {
        $this->assertTheLegAgreesWithTheReceiver(self::HOST.$path.self::QUERY, self::receiver(), "path `{$path}`");
    }

    #[DataProvider('queryRegions')]
    public function test_the_predicate_agrees_with_the_receiver_on_every_query_spelling(string $region): void
    {
        $this->assertTheLegAgreesWithTheReceiver(self::HOST.self::CANONICAL.$region, self::receiver(), "query region `{$region}`");
    }

    #[DataProvider('receiverBases')]
    public function test_the_leg_agrees_with_the_receiver_on_every_receiver_base_url(string $base): void
    {
        // ⭐ THE LIVE URL IS THE COMPOSED ONE, which is the END-TO-END DEFECT and not a
        // convenient choice: `GitHubWebhookSubscriptionCheck`'s `fail` line and
        // `docs/writeback.md` both instruct the operator to paste `<BRIDGE_RECEIVER_BASE_URL>`
        // + `/github?b=<scope>` as the payload URL, so on a mis-set base the hook GitHub holds
        // IS the string this install composes — the two arguments are equal by construction and
        // any symmetric predicate must answer yes.
        $receiver = ReceiverUrl::for($base, 'github', self::SCOPE);

        $this->assertTheLegAgreesWithTheReceiver($receiver, $receiver, "receiver base `{$base}`");
    }

    /**
     * WHAT `bridge:check`'s LEG REPORTS for one (live hook, composed receiver) pair — BOTH
     * halves of it, which is the r6 correction.
     *
     * ⛔ THE SUBJECT OF THIS CLASS IS THE COMPOSITE, NEVER `deliversTo()` ALONE. That predicate
     * answers *are these two URLs one delivery*; the leg's green line claims *a live webhook
     * delivers to THIS INSTALL*, and the gap between those two sentences is an entire input the
     * predicate never looks at. A suite that asserts the half certifies the half.
     */
    private function legReportsALiveHook(string $live, string $receiver): bool
    {
        return ReceiverUrl::reachesThisInstall($receiver, 'github', self::SCOPE, app('router')->getRoutes())
            && ReceiverUrl::deliversTo($live, $receiver);
    }

    /**
     * The two legs, and the asymmetry between them IS the design.
     *
     * ⛔ LEG 1 IS UNIVERSAL AND TAKES NO EXCEPTION. A reported-live the receiver does not back
     * is the silent false-`ok`: `bridge:check` says the subscription is wired, the hook
     * delivers nothing, and nothing on any surface says so. No residual, no bound and no
     * operator ruling may ever admit one, so this leg is asserted over EVERY member of ALL
     * THREE populations with no condition in front of it.
     *
     * ⛔ AND `$delivers` REQUIRES A NON-NULL IDENTITY, which is not pedantry once the receiver
     * varies: two URLs that both reach NO route have equal (null) identities, so the plain
     * `===` this leg used while the receiver was fixed would have called a mis-set install's
     * dead hook a delivery — agreeing with the predicate, and both wrong.
     *
     * ⚠ LEG 2 IS CONDITIONAL, AND THE CONDITION IS THE DISCLOSED RESIDUAL ITSELF RATHER THAN A
     * PROXY FOR IT (card#9150 r6). `deliversTo()` compares the WHOLE parsed parameter map while
     * the receiver reads only `b`, so a hook carrying an EXTRA parameter the receiver would
     * ignore still reads as absent — the loud false-`fail`, and widening it would change what
     * `bridge:check` accepts, which is operator-gated. ⛔ The condition used to be *the URL's
     * parameter keys EQUAL the composed one's*, described in this docblock as *the complement
     * of the extra-parameter residual* — and those are not the same set: a URL carrying NO
     * parameters, or a different parameter instead of `b`, has unequal keys and is not an
     * extra-parameter residual, so it silently dropped to one-way judgement for a reason the
     * bound does not cover. Nothing was hiding there — each such member was measured to agree
     * in both directions — which is exactly why the condition is now the residual as WRITTEN:
     * the URL's keys are a STRICT SUPERSET of this install's. Computed from the RECEIVER's own
     * parse, so the bound cannot drift away from the predicate it describes.
     *
     * ⚠ WHAT THAT WIDENING DOES **NOT** BUY, stated because a wider denominator reads as more
     * coverage: over the populations as they stand today, every member it newly admits is one
     * that does NOT deliver — delivering requires the receiver to read `b=<this scope>`, which
     * requires the key — so the added assertion is leg 1's contrapositive and catches nothing
     * leg 1 would miss. It is a BOUND MADE TRUE, not new coverage. What it buys is the future:
     * a member that delivers while carrying a different key set (a receiver that grew a second
     * parameter) lands in the strong leg instead of silently dropping out of it.
     *
     * ⚠ A URL THE FRAMEWORK WILL NOT BUILD AT ALL is judged one-way too, and for a different
     * reason: there is no receiver's-eye parameter set to test the condition against, so the
     * condition cannot be evaluated rather than being false. Leg 1 still covers it, in the
     * direction that matters.
     */
    private function assertTheLegAgreesWithTheReceiver(string $live, string $receiver, string $what): void
    {
        $liveIdentity = $this->deliveryIdentity($live);
        $delivers = $liveIdentity !== null && $liveIdentity === $this->deliveryIdentity(self::receiver());
        $reported = $this->legReportsALiveHook($live, $receiver);

        // ⚑ WRITTEN AS AN IMPLICATION RATHER THAN GUARDED BY `if ($reported)`, so that EVERY
        // member of all three populations lands at least one assertion. A data set that asserts
        // nothing is a decoration — it reports that the runner reached it, not that anything
        // about it is true — and PHPUnit only whispers that as `risky`.
        $this->assertTrue(
            ! $reported || $delivers,
            "bridge:check's leg reports a LIVE hook for {$what} and the RECEIVER does not back it. This is the "
                .'silent direction: the hook delivers nothing, bridge:check reports ok, the agent is deaf and no '
                .'surface says so.',
        );

        if ($this->isJudgedInBothDirections($live)) {
            $this->assertSame(
                $delivers,
                $reported,
                "bridge:check's leg disagrees with the RECEIVER about {$what}, and that URL does not carry the "
                    .'extra parameters the disclosed residual is made of. If the receiver delivers and the leg says '
                    .'no, this is the false-`fail` class again: a healthy install reddened, exit code moved.',
            );
        }
    }

    /**
     * Is `$url` OUTSIDE the disclosed extra-parameter residual — i.e. does the two-way leg
     * above judge it?
     *
     * The residual is the set of URLs carrying every parameter this install composes AND MORE:
     * the receiver reads only `b` and would deliver, while `deliversTo()` compares the whole
     * map and says absent. Everything else — equal keys, fewer, or different ones — is judged
     * in both directions.
     */
    private function isJudgedInBothDirections(string $url): bool
    {
        $keys = $this->queryKeys($url);
        $ours = $this->queryKeys(self::receiver());
        if ($keys === null || $ours === null) {
            return false;
        }

        return $keys === $ours || array_values(array_intersect($keys, $ours)) !== $ours;
    }

    public function test_the_oracle_discriminates(): void
    {
        // ⛔ THE CONTROL. Every assertion above compares two values; if the oracle answered a
        // constant, the whole class would pass over a predicate that did too.
        $canonical = $this->deliveryIdentity(self::receiver());
        $this->assertNotNull($canonical);
        $this->assertNotSame($canonical, $this->deliveryIdentity(self::HOST.'/not-a-receiver-path'.self::QUERY));
        // It separates a different PROVIDER from a different SPELLING — the distinction the
        // first cut of the oracle could not make.
        $this->assertNotSame($canonical, $this->deliveryIdentity(self::HOST.'/webhooks/gitlab'.self::QUERY));

        // ⭐ AND THE SECOND TERM IS LOAD-BEARING, not an inert suffix on the identity string.
        // A different scope reaches the SAME route with the SAME parameters and is still not a
        // delivery to this subscription — the receiver answers `invalid_scope` 400. Asserted
        // as: the whole identity differs while the ROUTE half of it does not, which is a claim
        // a route-only oracle cannot make and this one must.
        $otherScope = $this->deliveryIdentity(self::HOST.self::CANONICAL.'?b=other/repo');
        $this->assertNotSame($canonical, $otherScope);
        $this->assertSame(
            explode(' b=', (string) $canonical)[0],
            explode(' b=', (string) $otherScope)[0],
            'the route half of the identity must AGREE here — if it does not, this control is passing for the '
                .'wrong reason and says nothing about the query term.',
        );
    }

    public function test_the_composition_and_the_canonical_constants_are_one_url(): void
    {
        // ⛔ THE JOIN BETWEEN THE TWO DESCRIPTIONS OF THIS INSTALL, asserted rather than kept
        // in step by hand. Everything above is built from `CANONICAL` + `QUERY`; the receiver
        // population is built from `BASE_PATH` + `SCOPE` through the shipped composer. If those
        // two drift, every assertion in this class keeps passing while judging a URL no install
        // composes — and the r6 defect is precisely a wrong belief about what `for()` produces
        // from a base URL.
        $this->assertSame(self::receiver(), ReceiverUrl::for(self::HOST.self::BASE_PATH, 'github', self::SCOPE));
    }

    public function test_each_term_of_the_reachability_guard_is_load_bearing(): void
    {
        // ⛔ THE CONTROL ON THE r6 GUARD. `reachesThisInstall()` is a conjunction of three
        // things that can each be got wrong independently, and a guard that answered a constant
        // — or that dropped a term — would leave the composite leg exactly as blind as it was.
        // Each assertion below moves ONE term and nothing else.
        $routes = app('router')->getRoutes();

        $this->assertTrue(
            ReceiverUrl::reachesThisInstall(self::receiver(), 'github', self::SCOPE, $routes),
            'the canonical composition must REACH this install, or the guard reds every healthy run',
        );

        // (1) THE ROUTE. The measured row that shipped `ok` while every delivery 404'd.
        $this->assertFalse(ReceiverUrl::reachesThisInstall(
            ReceiverUrl::for(self::HOST, 'github', self::SCOPE), 'github', self::SCOPE, $routes,
        ));

        // (2) THE PROVIDER the route BINDS — not the one the caller hoped for.
        $this->assertFalse(ReceiverUrl::reachesThisInstall(self::receiver(), 'gitlab', self::SCOPE, $routes));

        // (3) THE SCOPE the receiver READS. A base carrying its own query routes perfectly and
        // swallows the composed `?b=` inside that query's value, so the middleware reads no
        // scope and answers `invalid_scope` 400 — the member a route-only guard calls healthy.
        $withOwnQuery = ReceiverUrl::for(self::HOST.self::BASE_PATH.'/github?z=1', 'github', self::SCOPE);
        $this->assertNotNull(
            $this->deliveryIdentity($withOwnQuery),
            'this control is only evidence about the SCOPE term while that URL still ROUTES — if it stopped '
                .'routing, the assertion below would pass on the route term and say nothing.',
        );
        $this->assertFalse(ReceiverUrl::reachesThisInstall($withOwnQuery, 'github', self::SCOPE, $routes));
    }

    public function test_the_oracles_scope_key_and_provider_segment_are_the_ones_the_receiver_reads(): void
    {
        // ⛔ THE RESTATEMENT THIS CLASS COULD NOT SEE. `deliveryIdentity()` reads the scope with
        // `$request->query('b')` and the provider out of the matched route's parameters because
        // `VerifyHmacSignature` does — two copies of one rule. The `for()` side is guarded
        // (`Tests\Unit\Support\ReceiverUrlTest`); the MIDDLEWARE side was not, so if the
        // receiver's parameter name moved, this oracle would keep judging `b`, every assertion
        // in this class would keep passing, and the predicate they certify would be wrong with
        // nothing red anywhere.
        //
        // Tied BEHAVIOURALLY, through the real middleware on the real route rather than by
        // re-reading its source: a well-formed scope under the key the receiver reads gets PAST
        // the scope gate, and under any other key is refused AT it.
        $refuse = function (string $url): Response {
            $request = Request::create($url, 'POST', [], [], [], [], '{}');
            $route = app('router')->getRoutes()->match($request);
            $request->setRouteResolver(fn () => $route);

            return (new VerifyHmacSignature)->handle($request, fn (): Response => new Response('reached the controller'));
        };

        $accepted = $refuse(self::receiver());
        $this->assertStringNotContainsString('invalid_scope', (string) $accepted->getContent());
        $this->assertStringNotContainsString('invalid_provider', (string) $accepted->getContent());
        $this->assertStringNotContainsString('unknown_provider', (string) $accepted->getContent());

        // The same scope under a DIFFERENT key is not a scope at all to the receiver.
        $otherKey = $refuse(self::HOST.self::CANONICAL.'?scope='.self::SCOPE);
        $this->assertSame(400, $otherKey->getStatusCode());
        $this->assertStringContainsString('invalid_scope', (string) $otherKey->getContent());

        // And the PROVIDER the oracle reads off the route is the one the middleware resolves —
        // both of its refusals, because they are decided by two different authorities
        // (`ProviderName`'s charset, then `WebhookAdapterFactory`'s registry) and a segment that
        // reached neither would prove nothing about where the middleware looks.
        $unsupported = $refuse(self::HOST.'/webhooks/nosuchprovider'.self::QUERY);
        $this->assertSame(400, $unsupported->getStatusCode());
        $this->assertStringContainsString('unknown_provider', (string) $unsupported->getContent());

        $malformed = $refuse(self::HOST.'/webhooks/NOT-a-provider'.self::QUERY);
        $this->assertSame(400, $malformed->getStatusCode());
        $this->assertStringContainsString('invalid_provider', (string) $malformed->getContent());
    }

    public function test_the_two_way_leg_still_covers_the_whole_path_population(): void
    {
        // ⛔ THE DENOMINATOR OF A CONDITIONAL LEG, ASSERTED RATHER THAN ASSUMED. Leg 2 of
        // {@see self::assertAgreesWithTheReceiver()} fires only where the live URL carries
        // exactly the parameters this install composes, and a member outside that condition is
        // judged in ONE direction only — still sound, and strictly weaker. Before r5 the path
        // population was asserted in BOTH directions unconditionally, so if a path member ever
        // falls out of leg 2 the suite QUIETLY loses the half that catches a false `fail` —
        // the loud defect this card was filed for — while staying green. Nothing would say so.
        // Measured today: 40 of 40. Asserted as a property, so it stays true rather than being
        // a figure somebody re-checks.
        //
        // ⚠ THE QUERY POPULATION IS DELIBERATELY *NOT* REQUIRED TO BE FULLY COVERED — members
        // that carry a different parameter SET are precisely the disclosed residual, and
        // demanding two-way agreement on them would be demanding the widening the operator
        // ruled against. The three named below are the ones that must never drift out of the
        // strong leg, each because a real defect lived there.
        foreach (self::spellings() as $row) {
            $this->assertSame(
                $this->queryKeys(self::receiver()),
                $this->queryKeys(self::HOST.$row[0].self::QUERY),
                "the path spelling `{$row[0]}` no longer carries this install's parameter set, so it has dropped out "
                    .'of the two-way agreement leg and is now judged in one direction only.',
            );
        }

        foreach ([
            self::QUERY,                    // the canonical — if this drops out, nothing is two-way
            '?b=owner%2Frepo',              // r1's LIVE false-`fail`, measured on a consumer install
            '?b=owner/repo#frag',           // r5's false-`fail`: a trailing fragment IS a live hook
        ] as $region) {
            $this->assertSame(
                $this->queryKeys(self::receiver()),
                $this->queryKeys(self::HOST.self::CANONICAL.$region),
                "the query spelling `{$region}` must be judged in BOTH directions — a one-way verdict here cannot "
                    .'catch the false-`fail` that this exact spelling produced on a healthy install.',
            );
        }
    }

    public function test_the_two_way_leg_still_covers_the_receiver_spellings_a_real_defect_lived_in(): void
    {
        // ⛔ THE SAME DENOMINATOR DISCIPLINE ON THE NEW AXIS. The receiver population is NOT
        // required to be fully two-way — a base carrying its own query legitimately composes a
        // URL with a different parameter set, which is the disclosed residual's shape — so the
        // four below are named, each because a verdict about it is load-bearing in a direction
        // a one-way leg cannot catch.
        foreach ([
            // the canonical — if this drops out, nothing is two-way
            self::BASE_PATH,
            // an ORDINARY env value: `for()` trims it, and a guard that rejected it would red
            // healthy installs by the fleet
            self::BASE_PATH.'/',
            // the measured false-`ok`: composes `/github`, which answers 404
            '',
            // the doubled path docs/writeback.md records as a shipped, silent defect
            self::BASE_PATH.self::BASE_PATH,
        ] as $suffix) {
            $composed = ReceiverUrl::for(self::HOST.$suffix, 'github', self::SCOPE);
            $this->assertTrue(
                $this->isJudgedInBothDirections($composed),
                "the receiver base `{$suffix}` must be judged in BOTH directions — a one-way verdict here cannot "
                    .'catch the defect this exact spelling produced.',
            );
        }
    }

    public function test_no_generator_emits_one_member_twice(): void
    {
        // ⛔ WHY A DUPLICATE IS A DEFECT AND NOT A HARMLESS RE-RUN. `spellings()` used to end in
        // `array_unique`, and the generator emitted `/webhooks//github` twice — from the
        // slash-duplication arm and again from a literal list. The collision was invisible, and
        // every surface that restated the population's size was therefore wrong by one while
        // reading as measured. The fix is at the emission site; this is the guard that keeps it
        // there, over BOTH generators so the new one cannot repeat it.
        foreach ([
            'spellings' => self::spellings(),
            'queryRegions' => self::queryRegions(),
            'receiverBases' => self::receiverBases(),
        ] as $name => $rows) {
            $members = array_map(fn (array $row): string => $row[0], $rows);
            $duplicates = array_keys(array_filter(
                array_count_values($members),
                fn (int $times): bool => $times > 1,
            ));

            $this->assertSame([], $duplicates, "{$name}() emits the same member more than once.");
        }
    }

    public function test_the_path_population_witnesses_no_plus_and_no_percent_20(): void
    {
        // ⛔ THIS IS THE DERIVATION BEHIND A CLAIM MADE ELSEWHERE, replacing the figure that
        // used to carry it. `Tests\Unit\Support\ReceiverUrlTest` pins `rawurldecode` against
        // `urldecode` on the ground that the two are extensionally IDENTICAL over this
        // population, so a mutation between them reds nothing here. The discriminating
        // character is `+`: `urldecode` turns it into a space and `rawurldecode` does not, and
        // this install's canonical path contains neither it nor the `%20` it would be confused
        // with. That was stated as *"all 41 spellings"* — a count of a population that is
        // generated, i.e. a restatement that goes stale silently. It is a predicate now.
        //
        // ⚠ THE PATH POPULATION ONLY. The QUERY population deliberately DOES carry `+` and
        // `%20`, because in a query a `+` really is a space and both sides must agree on that.
        foreach (self::spellings() as $row) {
            $this->assertStringNotContainsString('+', $row[0]);
            $this->assertStringNotContainsStringIgnoringCase('%20', $row[0]);
        }
    }

    /**
     * WHAT THIS URL ACTUALLY DELIVERS TO THIS INSTALL: the route it matches, its resolved
     * parameters, and the scope the receiver then reads — or `null` for anything that reaches
     * no route at all.
     *
     * ⚑ THE PARAMETERS ARE PART OF THE IDENTITY, and leaving them out was the first cut's
     * error. `webhooks/{provider}` matches `/webhooks/gitlab` and `/webhooks/GitHub` just as
     * it matches `/webhooks/github`, so a route-URI-only oracle called a DIFFERENT PROVIDER a
     * spelling of the same endpoint.
     *
     * ⚑ AND `query('b')` IS PART OF IT TOO, which is what r5 added. Routing is NOT the whole
     * of what a URL spelling can decide: `VerifyHmacSignature` reads the scope out of the
     * query, and a URL that routes here while handing it a different `b` — or none, which is
     * what a fragment before the `?` produces — is answered `invalid_scope` 400 and feeds this
     * install nothing.
     *
     * ⚠ IT ASKS ABOUT ROUTING AND SCOPE ONLY — not the HMAC or the secret, which no URL
     * spelling can decide.
     *
     * ⛔ `Request::path()` IS DELIBERATELY NOT CONSULTED, and this is the correction that
     * matters most here. `path()` is `trim(getPathInfo(), '/')`, so it folds LEADING slashes
     * away — but the matcher reads `getPathInfo()`, which keeps them. Measured through this
     * app's kernel: `//webhooks/github?b=…` has `path() === 'webhooks/github'` and yet matches
     * NO ROUTE and answers **404**. A predicate mirroring `path()` would therefore call that
     * dead spelling equivalent and report a green `ok` for a webhook that delivers nothing.
     *
     * ⛔ THE CONSTRUCTION IS INSIDE THE TRY, and it was not: a URL the framework refuses to
     * build at all threw straight out of the data set and ERRORED the run instead of answering
     * *this does not deliver*. The oracle must be TOTAL over whatever the generators produce,
     * or a transform nobody has thought of yet takes the suite down rather than reporting a
     * verdict. ⚠ CI caught that and this box did not report it, for a reason worth knowing: an
     * ERROR is not a FAILURE, and a summary that reads only the failure count calls such a run
     * green.
     *
     * ⚠ IT IS A STRING, SO TWO DISTINCT ROUTES SHARING ONE URI PATTERN FOR ONE VERB WOULD
     * COLLIDE. Recorded rather than defended against: the booted collection holds no POST
     * pattern registered twice, so it cannot bite today — and a future colliding route is now
     * a known shape rather than a surprise.
     */
    private function deliveryIdentity(string $url): ?string
    {
        try {
            $request = Request::create($url, 'POST', [], [], [], [], '{}');
            $route = app('router')->getRoutes()->match($request);
        } catch (\Throwable) {
            return null;
        }

        return $route->uri()
            .' '.json_encode($route->parameters(), JSON_THROW_ON_ERROR)
            .' b='.json_encode($request->query('b'), JSON_THROW_ON_ERROR);
    }

    /**
     * The parameter NAMES the receiver would see, sorted — `null` for a URL it cannot build.
     *
     * ⚠ READ FROM THE RECEIVER'S PARSE, not from the predicate's, so the bound stated in
     * {@see self::assertAgreesWithTheReceiver()} is a property of the URL rather than a
     * restatement of the thing it is bounding.
     *
     * @return list<string>|null
     */
    private function queryKeys(string $url): ?array
    {
        try {
            $keys = array_map(strval(...), array_keys(Request::create($url, 'POST', [], [], [], [], '{}')->query->all()));
        } catch (\Throwable) {
            return null;
        }

        sort($keys);

        return $keys;
    }
}
