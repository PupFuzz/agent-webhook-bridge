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
        $this->assertStringContainsString('reaches NO route in THIS application', $findings[0]->message);
        // ⭐ THE DISCLOSURE HALF (canon #7). A router answers about path and query; this leg
        // must not leave an operator reading it as a verdict on whether deliveries arrive,
        // and must name the one install shape it can be WRONG about. Asserted, not trusted
        // to review: the ruling that allows a `fail` here at all rests on the line saying so.
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

        foreach (['github', 'kanban'] as $provider) {
            foreach ([self::HEALTHY_RECEIVER => true, 'https://bridge.example.com' => false] as $base => $expected) {
                $this->assertSame(
                    $expected,
                    ReceiverUrl::reachesThisInstall(ReceiverUrl::for((string) $base, $provider, $scope), $provider, $scope, $routes),
                    "the verdict on '{$base}' moved with the scope, so the leg's constant is an input and not a probe",
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
