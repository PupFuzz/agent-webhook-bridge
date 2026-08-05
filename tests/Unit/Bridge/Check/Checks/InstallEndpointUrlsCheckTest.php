<?php

namespace Tests\Unit\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Checks\InstallEndpointUrlsCheck;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use Tests\Support\MaterializesChecks;
use Tests\TestCase;

/**
 * The two endpoint-URL legs (DL-242 stage 6), and specifically that they are held to
 * DIFFERENT floors (#3574).
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
 */
class InstallEndpointUrlsCheckTest extends TestCase
{
    use MaterializesChecks;

    public function test_both_urls_unset_is_not_a_fault(): void
    {
        config(['bridge.receiver_base_url' => null, 'bridge.providers.kanban.api_base_url' => null]);

        $this->assertSame([], $this->findings());
    }

    public function test_well_formed_urls_yield_nothing(): void
    {
        config([
            'bridge.receiver_base_url' => 'https://bridge.example.com',
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
    }

    public function test_one_cleartext_url_passes_the_receiver_leg_and_is_refused_by_the_kanban_leg(): void
    {
        config([
            'bridge.receiver_base_url' => 'http://example.com',
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

    /** @return list<Finding> */
    private function findings(): array
    {
        return $this->findingsOf((new InstallEndpointUrlsCheck), new CheckContext);
    }
}
