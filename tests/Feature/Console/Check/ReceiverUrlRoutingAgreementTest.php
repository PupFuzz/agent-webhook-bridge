<?php

namespace Tests\Feature\Console\Check;

use App\Bridge\Support\ReceiverUrl;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('spellings')]
    public function test_the_predicate_agrees_with_the_receiver_on_every_path_spelling(string $path): void
    {
        $this->assertAgreesWithTheReceiver(self::HOST.$path.self::QUERY, "path `{$path}`");
    }

    #[DataProvider('queryRegions')]
    public function test_the_predicate_agrees_with_the_receiver_on_every_query_spelling(string $region): void
    {
        $this->assertAgreesWithTheReceiver(self::HOST.self::CANONICAL.$region, "query region `{$region}`");
    }

    /**
     * The two legs, and the asymmetry between them IS the design.
     *
     * ⛔ LEG 1 IS UNIVERSAL AND TAKES NO EXCEPTION. A `true` the receiver does not back is the
     * silent false-`ok`: `bridge:check` says the subscription is wired, the hook delivers
     * nothing, and nothing on any surface says so. No residual, no bound and no operator
     * ruling may ever admit one, so this leg is asserted over EVERY member of BOTH populations
     * with no condition in front of it.
     *
     * ⚠ LEG 2 IS CONDITIONAL, AND THE CONDITION IS THE DISCLOSED RESIDUAL WRITTEN AS A
     * PREDICATE RATHER THAN AS A PROSE LIST. `deliversTo()` compares the WHOLE parsed
     * parameter map while the receiver reads only `b`, so a hook carrying an extra parameter
     * the receiver would ignore still reads as absent — the loud false-`fail`, and widening it
     * would change what `bridge:check` accepts, which is operator-gated. That residual is
     * exactly the set of URLs whose parameter KEYS differ from the composed one's, computed
     * here from the RECEIVER's own parse — so the bound cannot drift away from the predicate
     * it describes, and a new spelling that lands inside it is reported rather than assumed.
     */
    private function assertAgreesWithTheReceiver(string $url, string $what): void
    {
        $delivers = $this->deliveryIdentity($url) === $this->deliveryIdentity(self::receiver());
        $predicate = ReceiverUrl::deliversTo($url, self::receiver());

        // ⚑ WRITTEN AS AN IMPLICATION RATHER THAN GUARDED BY `if ($predicate)`, so that EVERY
        // member of both populations lands at least one assertion. A data set that asserts
        // nothing is a decoration — it reports that the runner reached it, not that anything
        // about it is true — and PHPUnit only whispers that as `risky`.
        $this->assertTrue(
            ! $predicate || $delivers,
            "deliversTo() says YES to {$what} and the RECEIVER does not back it. This is the silent direction: "
                .'the hook delivers nothing, bridge:check reports ok, the agent is deaf and no surface says so.',
        );

        if ($this->queryKeys($url) === $this->queryKeys(self::receiver())) {
            $this->assertSame(
                $delivers,
                $predicate,
                "deliversTo() disagrees with the RECEIVER about {$what}, and that URL carries exactly the parameters "
                    .'this install composes — so it is not the disclosed extra-parameter residual. If the receiver '
                    .'delivers and the predicate says no, this is the false-`fail` class again: a healthy install '
                    .'reddened, exit code moved.',
            );
        }
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

    public function test_neither_generator_emits_one_member_twice(): void
    {
        // ⛔ WHY A DUPLICATE IS A DEFECT AND NOT A HARMLESS RE-RUN. `spellings()` used to end in
        // `array_unique`, and the generator emitted `/webhooks//github` twice — from the
        // slash-duplication arm and again from a literal list. The collision was invisible, and
        // every surface that restated the population's size was therefore wrong by one while
        // reading as measured. The fix is at the emission site; this is the guard that keeps it
        // there, over BOTH generators so the new one cannot repeat it.
        foreach (['spellings' => self::spellings(), 'queryRegions' => self::queryRegions()] as $name => $rows) {
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
