<?php

namespace Tests\Unit\Bridge\Check\Checks;

use App\Bridge\Adapters\WebhookAdapterFactory;
use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Checks\InstallEndpointUrlsCheck;
use App\Bridge\Support\Finding;
use App\Bridge\Support\ReceiverUrl;
use App\Bridge\Support\Severity;
use App\Bridge\Validation\ScopeId;
use Illuminate\Routing\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MaterializesChecks;
use Tests\TestCase;

/**
 * The endpoint-URL legs (DL-242 stage 6, DL-374), and specifically that the two fields are
 * held to DIFFERENT floors (#3574) while the receiver's is held to one MORE floor than its
 * syntax (card#9280).
 *
 * THE HTTPS FLOOR IS UNREACHED BY EVERYTHING ELSE THAT DRIVES THIS CHECK — measured at
 * whole-suite scope. One golden fixture fails a URL, and it fails the RECEIVER's, on the
 * plain-http leg; no fixture and no command-level test ever configures a cleartext
 * `providers.kanban.api_base_url`, so nothing exercises the leg that guards the endpoint
 * carrying the bearer token and the provision-time HMAC secret. `UrlValidatorTest` owns
 * whether `secureHttpUrl()` DECIDES correctly; what nothing owned until this file is
 * whether this check routes that URL to it.
 *
 * THE PAIRED CASE IS THE LOAD-BEARING ONE. Asserting each leg against a URL the other
 * would also reject proves nothing about the routing — a check that sent both fields to
 * `httpUrl()` would pass exactly the same assertions. Feeding ONE `http://` URL to BOTH
 * fields in a single run is what separates them: the receiver's must be accepted and the
 * kanban one refused, in the same call, so the difference cannot be an artifact of the
 * input.
 *
 * ⭐ THE RECEIVER BASES IN THIS FILE ALL CARRY `/webhooks`, AND THAT IS NOW LOAD-BEARING
 * RATHER THAN DECORATIVE (card#9280). Until the route leg existed, every case here declared
 * the BARE HOST and passed; the leg reds both of them, which is how its arm was first seen
 * to fail. A base with no receiver path is the exact fault this check now owns, so a fixture
 * that means *a healthy install* has to be spelled like one.
 */
class InstallEndpointUrlsCheckTest extends TestCase
{
    use MaterializesChecks;

    private const HEALTHY_RECEIVER = 'https://bridge.example.com/webhooks';

    public function test_both_urls_unset_is_not_a_fault(): void
    {
        config(['bridge.receiver_base_url' => null, 'bridge.providers.kanban.api_base_url' => null]);

        $this->assertSame([], $this->findings());
    }

    public function test_well_formed_urls_yield_nothing(): void
    {
        config([
            'bridge.receiver_base_url' => self::HEALTHY_RECEIVER,
            'bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3',
        ]);

        $this->assertSame([], $this->findings());
    }

    public function test_a_malformed_receiver_url_is_reported_with_the_validators_message(): void
    {
        config([
            'bridge.receiver_base_url' => 'ftp://bridge.example.com',
            'bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3',
        ]);

        $findings = $this->findings();

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertSame(
            "bridge.receiver_base_url 'ftp://bridge.example.com' must use http or https",
            $findings[0]->message,
        );
        // ⛔ THE CONTROL ON THE ROUTE LEG'S GATE, and it is not implied by the count above
        // on its own reading. `ftp://bridge.example.com` composes a receiver URL this app
        // routes NOWHERE, so an ungated route leg would answer `false` and add a SECOND fail
        // for ONE fault — naming routing as the cause of what is a scheme error, with one
        // remedy between them. The count reds if that happens; this line says what it means.
        $this->assertStringNotContainsString('reaches NO route', $findings[0]->message);
    }

    public function test_one_cleartext_url_passes_the_receiver_leg_and_is_refused_by_the_kanban_leg(): void
    {
        config([
            'bridge.receiver_base_url' => 'http://example.com/webhooks',
            'bridge.providers.kanban.api_base_url' => 'http://example.com',
        ]);

        $findings = $this->findings();

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertStringStartsWith("bridge.providers.kanban.api_base_url 'http://example.com' must use https", $findings[0]->message);
        // That the receiver leg stayed silent is carried by the count plus the prefix
        // above, not by this line. What this adds is narrower: the refusal names only the
        // field it is about, so a validator call given the wrong label would not slip
        // through on a prefix that happens to match.
        $this->assertStringNotContainsString('receiver_base_url', $findings[0]->message);
    }

    /**
     * The control for the case above: the https floor is loopback-aware, so the refusal
     * is about exposure on the wire and not about the scheme alone. Without this, the
     * paired assertion would also pass against a leg that rejected every `http://` URL,
     * and a local dev rig would be unable to run a clean `bridge:check`.
     */
    public function test_a_loopback_cleartext_kanban_url_is_accepted(): void
    {
        config([
            'bridge.receiver_base_url' => null,
            'bridge.providers.kanban.api_base_url' => 'http://127.0.0.1:8000/api/v3',
        ]);

        $this->assertSame([], $this->findings());
    }

    /**
     * ⭐ THE CARD'S SUBJECT (card#9280). Both members are a perfectly well-formed https URL
     * — nothing in the syntax floor has anything to say about either — and both compose a
     * receiver URL that reaches no route in this app, so before this leg the run exited 0
     * with no red anywhere while no delivery could arrive.
     *
     * The two spellings are the ones `docs/config-schema.md` names as what an operator
     * actually types: the BARE HOST (the value looks like a host, and `/webhooks` reads like
     * part of the route rather than part of the value) and the DOUBLED PATH (pasting the
     * documented payload URL into the base). They are two members of a class, not the class.
     *
     * @return list<array{0: string}>
     */
    public static function unreachableReceiverBases(): array
    {
        return [
            'the bare host — the receiver path left off the value' => ['https://bridge.example.com'],
            'the receiver path doubled — the payload URL pasted as the base' => ['https://bridge.example.com/webhooks/webhooks'],
        ];
    }

    #[DataProvider('unreachableReceiverBases')]
    public function test_a_receiver_base_that_reaches_no_route_in_this_app_is_a_fail(string $base): void
    {
        config([
            'bridge.receiver_base_url' => $base,
            'bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3',
        ]);

        $findings = $this->findings();

        $this->assertCount(1, $findings);
        // ⛔ `Fail` IS THE ASSERTION, not an incidental property: this severity is the only
        // one that moves `bridge:check`'s exit code, which is the whole of what the card
        // asked for and what the operator approved. A round that softened this to `warn`
        // would leave every other assertion in this method passing.
        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertStringContainsString("bridge.receiver_base_url '{$base}'", $findings[0]->message);
        // ⛔ THE HEADLINE'S STATED SCOPE IS THE SET THE LEG FOUND, and the set is DERIVED here
        // for the same reason the leg derives it (card#9280 r2, SF-1). The arm fires on *at
        // least one* provider unreachable, so a flat `reaches NO route in THIS application`
        // claimed something about every provider that the predicate does not establish. This
        // assertion reds if the headline goes back to the absolute, and reds if the leg stops
        // naming what it actually measured.
        $this->assertStringContainsString(
            'composes a receiver URL that reaches NO route in THIS application for provider(s) '
                .implode(', ', WebhookAdapterFactory::SUPPORTED),
            $findings[0]->message,
        );
        // ⭐ THE DISCLOSURE HALF (canon #7). A router answers about path and query; this leg
        // must not leave an operator reading it as a verdict on whether deliveries arrive,
        // and must name the one install shape it can be WRONG about. Asserted, not trusted
        // to review: the ruling that allows a `fail` here at all rests on the line saying so.
        //
        // ⛔ THE MECHANISM CLAUSE IS ASSERTED BESIDE THE PROMISE, AND IT IS THE HALF THAT WAS
        // MISSING (card#9280 r2). The `establishes NOTHING about … host` sentence shipped while
        // the host could decide the verdict outright — `Request::create()` refuses an IDN /
        // `~` / `+` / `%20` host, which answered *unreachable* — so this file was watching a
        // FALSE sentence red. The substitution named here is what makes it true, and
        // `test_the_route_verdict_does_not_move_with_the_authority_or_the_scheme` is what
        // makes it true of the CODE rather than of the prose.
        $this->assertStringContainsString(
            'with a canonical authority substituted for its userinfo, host and port',
            $findings[0]->message,
        );
        $this->assertStringContainsString('establishes NOTHING about that value\'s scheme, host, port or userinfo', $findings[0]->message);
        $this->assertStringContainsString('REWRITES the request path', $findings[0]->message);
    }

    /**
     * ⛔ THE PROVIDER POPULATION IS DERIVED, NEVER WRITTEN DOWN TWICE. The receiver base
     * serves every provider this receiver has an adapter for, and `routes/webhooks.php`
     * leaves `{provider}` unconstrained TODAY — so a leg hard-coded to `github` would pass
     * every other assertion in this file while going blind for `kanban` the day that segment
     * gains a constraint. Reading the constant makes a provider added to it arrive covered,
     * and reds the moment the leg stops asking about one.
     */
    public function test_the_route_leg_asks_about_every_provider_this_receiver_supports(): void
    {
        config(['bridge.receiver_base_url' => 'https://bridge.example.com']);

        $findings = $this->findings();

        $this->assertCount(1, $findings);
        // ⛔ THE PRESENCE WITNESS ON THE DERIVATION ITSELF. The loop below is an assertion
        // ABOUT a population, so it certifies whatever that population turns out to be — an
        // emptied constant would make every iteration vacuous and this test green over a leg
        // that asked about nothing at all. The derived set has to be non-empty to be derived.
        $this->assertNotSame([], WebhookAdapterFactory::SUPPORTED, 'the derived provider population is empty, so the loop below asserts nothing');
        foreach (WebhookAdapterFactory::SUPPORTED as $provider) {
            $this->assertStringContainsString($provider, $findings[0]->message);
        }
    }

    /**
     * ⭐ THE PROBE SCOPE IS A PROBE, AND THIS IS WHAT ENTITLES IT TO BE A CONSTANT. The leg
     * composes `<base>/<provider>?b=<scope>` with a scope of its own rather than with a
     * declared subscription, which is what lets the `fail` rest on no premise about any
     * repo, any agent YAML or any upstream — but only if the verdict does not DEPEND on the
     * value it picked. That is an assumption about `ReceiverUrl::for()` and about this app's
     * router, not a tautology: a scope carrying `&`, `#` or `/` un-encoded would change the
     * composed URL's query, and a route that constrained the scope would change the answer.
     *
     * ⚠ THE SHAPE CLASSES ARE `ScopeId`'s OWN — numeric, `org/repo`, hyphenated, dotted —
     * read off the docblock of the validator that decides what a real scope may be. That is
     * a derived population and a WIDE one; it is NOT a proof the class is closed, and a
     * spelling `ScopeId::PATTERN` admits that nobody thought of is still invisible here. The
     * guard below is what keeps the derivation honest if that pattern narrows.
     *
     * @return list<array{0: string}>
     */
    public static function scopeShapeClasses(): array
    {
        return [
            'numeric — a kanban board id' => ['5'],
            'owner/repo — a github scope' => ['owner/repo'],
            'hyphenated' => ['some-owner/some-repo'],
            'dotted slug' => ['owner.example/repo.name'],
        ];
    }

    #[DataProvider('scopeShapeClasses')]
    public function test_the_route_verdict_does_not_depend_on_which_scope_is_probed(string $scope): void
    {
        $this->assertTrue(ScopeId::matches($scope), 'the derived shape class is no longer a scope this receiver would accept');
        $this->assertTrue(
            ScopeId::matches(InstallEndpointUrlsCheck::PROBE_SCOPE),
            'the leg probes with a scope the receiver would REFUSE, so its composed URL is not one a delivery could use',
        );

        $routes = app(Router::class)->getRoutes();

        // ⛔ DERIVED, NOT RESTATED (card#9280 r2, SF-3). A second literal copy of the provider
        // list here would keep passing over `['github', 'kanban']` after the leg's own derived
        // population moved, which is the drift the constant exists to make unrepresentable.
        $this->assertNotSame([], WebhookAdapterFactory::SUPPORTED, 'the derived provider population is empty, so the loops below assert nothing');
        foreach (WebhookAdapterFactory::SUPPORTED as $provider) {
            foreach ([self::HEALTHY_RECEIVER => true, 'https://bridge.example.com' => false] as $base => $expected) {
                $this->assertSame(
                    $expected,
                    ReceiverUrl::reachesThisInstall(ReceiverUrl::for((string) $base, $provider, $scope), $provider, $scope, $routes),
                    "the verdict on '{$base}' moved with the scope, so the leg's constant is an input and not a probe",
                );
            }
        }
    }

    /**
     * ⭐ THE CARD'S r2 SUBJECT: A HOST `Request::create()` REFUSES IS NOT A ROUTING VERDICT
     * (card#9280 r2, MF-1). `https://brücke.example.com/webhooks` passes every syntax floor —
     * `UrlValidator::httpUrl()` accepts it — and composes a receiver URL whose PATH and QUERY
     * route here perfectly. Symfony still refuses to BUILD that URI (`BadRequestException:
     * Invalid URI: Host is malformed.`), which the predicate used to answer `false` to, and
     * this leg renders `false` as a **`fail` that moves `bridge:check`'s exit code**. So an
     * install that receives its deliveries correctly — the host travels in a header this
     * router never consults, and the delivery is IDNA-encoded on the wire — exited 1 on the
     * spelling of its own hostname. THE OPERATOR AUTHORISED A `fail` ON *reaches no route*,
     * never on *the URI parser did not like your host*.
     *
     * ⛔ THE SECOND ARM IS THE CONTROL AND THE TEST IS WORTHLESS WITHOUT IT. Substituting a
     * canonical authority could have made EVERYTHING reachable; the same refused host with the
     * receiver path left off must still FAIL, because that install really is deaf. One arm
     * proves the class is closed, the other proves it was closed by a substitution and not by
     * a blanket `true`.
     */
    public function test_a_host_the_uri_parser_refuses_is_judged_on_its_path_and_query_alone(): void
    {
        config([
            'bridge.receiver_base_url' => 'https://brücke.example.com/webhooks',
            'bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3',
        ]);

        $this->assertSame(
            [],
            $this->findings(),
            'a base whose PATH and QUERY route here reds the leg because of a character in its HOSTNAME — an exit-code move on an axis this leg does not judge',
        );

        config(['bridge.receiver_base_url' => 'https://brücke.example.com']);

        $findings = $this->findings();

        $this->assertCount(1, $findings, 'the same refused host with NO receiver path is genuinely unreachable and must still fail');
        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertStringContainsString('reaches NO route in THIS application', $findings[0]->message);
    }

    /**
     * The authority spellings the verdict must be INVARIANT across.
     *
     * ⭐ THE LAST FOUR MEMBERS ARE THE ONES THAT DECIDE THIS TEST, and they are not exotica:
     * `Request::create()` refuses each of them outright, so before card#9280 r2 every one of
     * them answered *unreachable* on an install whose path and query route perfectly. The
     * first five are ordinary authorities the substitution must not disturb.
     *
     * @return list<array{0: string}>
     */
    public static function authoritySpellings(): array
    {
        return [
            'the canonical host' => ['bridge.example.com'],
            'some entirely different host' => ['a.completely.different.host'],
            'an explicit port' => ['bridge.example.com:8443'],
            'credentials in the userinfo' => ['svc:pw@bridge.example.com'],
            'an IPv6 literal with a port' => ['[2001:db8::1]:8443'],
            'a UNICODE (IDN) host — refused by Request::create()' => ['brücke.example.com'],
            'a tilde in the host — refused by Request::create()' => ['~bridge.example.com'],
            'a percent-escape in the host — refused by Request::create()' => ['bridge%20one.example.com'],
            'a plus in the host — refused by Request::create()' => ['bridge+1.example.com'],
        ];
    }

    /**
     * ⭐ THE GUARD BEHIND THE SHIPPED DISCLOSURE (canon #16 — the one restatement that cannot
     * become a pointer, because an operator at a terminal cannot follow a `{@see}`). The
     * `fail` line tells the operator this leg establishes NOTHING about the value's scheme,
     * host, port or userinfo. That sentence is a CLAIM ABOUT THE CODE, and it shipped FALSE:
     * a refused host decided the verdict by itself. It is true now for two DIFFERENT reasons,
     * and this test asserts both because they can break independently:
     *
     *   - USERINFO, HOST and PORT are true BY CONSTRUCTION —
     *     {@see ReceiverUrl::CANONICAL_AUTHORITY} replaces them before
     *     the router is ever asked, so no spelling of them can reach the matcher;
     *   - the SCHEME is true BY MEASUREMENT of `routes/webhooks.php`, which constrains none.
     *     It is deliberately NOT substituted (a route declaring `->secure()` makes it a real
     *     term, and folding it away would answer for a scheme nobody configured — the silent
     *     direction), so if that file ever gains one, THIS assertion reds and the shipped
     *     sentence has to move with it. The drift cannot happen quietly in either direction.
     *
     * ⛔ THE `assertFalse` ARM IS NOT SYMMETRY, IT IS THE DISCRIMINATOR. A substitution that
     * made every authority route would satisfy the `assertTrue` arm over the whole provider
     * table while destroying the leg; the bare-host arm is the same authority reaching nothing
     * because its PATH reaches nothing, which is the fault this leg exists for.
     */
    #[DataProvider('authoritySpellings')]
    public function test_the_route_verdict_does_not_move_with_the_authority_or_the_scheme(string $authority): void
    {
        $routes = app(Router::class)->getRoutes();
        $scope = InstallEndpointUrlsCheck::PROBE_SCOPE;

        $this->assertNotSame([], WebhookAdapterFactory::SUPPORTED, 'the derived provider population is empty, so the loops below assert nothing');

        foreach (WebhookAdapterFactory::SUPPORTED as $provider) {
            foreach (['https', 'http'] as $scheme) {
                $base = "{$scheme}://{$authority}";

                $this->assertTrue(
                    ReceiverUrl::reachesThisInstall(ReceiverUrl::for($base.'/webhooks', $provider, $scope), $provider, $scope, $routes),
                    "the verdict moved with the AUTHORITY or the SCHEME on '{$base}/webhooks' — the shipped fail line says it establishes nothing about either",
                );

                $this->assertFalse(
                    ReceiverUrl::reachesThisInstall(ReceiverUrl::for($base, $provider, $scope), $provider, $scope, $routes),
                    "'{$base}' reaches no receiver path and must still be unreachable — a substitution that makes every authority route deletes the leg",
                );
            }
        }
    }

    /** @return list<Finding> */
    private function findings(): array
    {
        return $this->findingsOf((new InstallEndpointUrlsCheck), new CheckContext);
    }
}
