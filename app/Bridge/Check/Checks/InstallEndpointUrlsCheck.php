<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Adapters\WebhookAdapterFactory;
use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\Check\CheckDisposition;
use App\Bridge\Check\CheckSlot;
use App\Bridge\Check\Silence;
use App\Bridge\Support\Finding;
use App\Bridge\Support\ReceiverUrl;
use App\Bridge\Support\SecretScrubber;
use App\Bridge\Support\UrlValidator;
use Illuminate\Routing\Router;
use Throwable;

/**
 * The per-install endpoint URLs, migrated out of `CheckCommand::handle()`
 * (DL-242 stage 6).
 *
 * TWO URLS UNDER THREE DIFFERENT FLOORS, and the differences are the point (#3574,
 * card#9280): the receiver's own base URL need only be a well-formed http(s) URL that
 * ALSO composes a receiver URL this app would route, while the kanban API base URL
 * carries the API token and the provision-time HMAC secret, so cleartext http would put
 * both on the wire and it is held to https. One check rather than two because they are
 * one config concern rendered by one authority; the field name in each message is what
 * distinguishes them for the operator.
 *
 * SILENT WHEN BOTH ARE WELL-FORMED, and silent when either is unset — an install that has
 * not been provisioned yet has no endpoints, which is not a fault. Yielding nothing is
 * how a conditionally-silent leg migrates under the byte-identical output contract. Stage 8
 * did NOT convert that silence into a finding — measurement showed most registered checks
 * are silent on a healthy install — it made the silence COUNTED, as
 * {@see CheckDisposition::Silent} in the run inventory.
 *
 * THE SYNTAX VERDICT TEXTS ARE `UrlValidator`'s. It composes every refusal — whitespace,
 * not a URL, wrong scheme, no host, and the https floor's own longer explanation — and this
 * check renders the thrown message unchanged. {@see self::receiverRouteFindings()} owns
 * the one verdict text this class composes itself.
 *
 * ONLY THE PLAIN-HTTP LEG HAS A GOLDEN FIXTURE (a non-URL receiver base). Nothing in the
 * fixture set has ever rendered the https-floor refusal, so a green golden run is not
 * evidence for the leg that guards the token — `InstallEndpointUrlsCheckTest` asserts
 * both, and asserts them as a pair: the same `http://` URL that the receiver leg accepts
 * must be refused by the kanban leg, which is what proves the two floors are not
 * interchangeable.
 *
 * @see CheckSlot::Providers
 */
final class InstallEndpointUrlsCheck implements Check
{
    /**
     * The scope the ROUTE leg composes with, and a value this leg's verdict must not
     * depend on.
     *
     * ⛔ IT IS A CONSTANT AND NOT A DECLARED SUBSCRIPTION, WHICH IS THE WHOLE REASON THE
     * `fail` CAN LIVE HERE. *Does the composed receiver URL reach a route in this app* is
     * a property of `BRIDGE_RECEIVER_BASE_URL` and of this app's route table — it rests on
     * no premise about any repo's hook list, about any agent's YAML, or about any upstream
     * this run could not reach. A leg that read real scopes would answer differently on an
     * install with no subscriptions yet and would be judging the roster, not the value.
     *
     * It matches `App\Bridge\Validation\ScopeId::PATTERN`, so the composed URL is one the
     * receiver would accept on the scope axis; `InstallEndpointUrlsCheckTest` pins the
     * verdict as invariant across that pattern's shape classes, so this value is a probe
     * rather than an input. It is PUBLIC so that test reads the value this leg actually
     * composes with instead of restating the literal — a restated copy would go on passing
     * after this one moved.
     */
    public const PROBE_SCOPE = 'probe-scope';

    public function id(): string
    {
        return 'install.endpoint_urls';
    }

    /**
     * @return iterable<Finding|Silence>
     */
    public function run(CheckContext $ctx): iterable
    {
        $receiverBaseUrl = (string) config('bridge.receiver_base_url');
        $receiverIsWellFormed = false;

        foreach ([
            'receiver_base_url' => ['url' => $receiverBaseUrl, 'secure' => false],
            // secret-bearing (token + provision-time HMAC secret) — https floor (#3574)
            'providers.kanban.api_base_url' => ['url' => (string) config('bridge.providers.kanban.api_base_url'), 'secure' => true],
        ] as $field => $spec) {
            if ($spec['url'] === '') {
                continue;
            }
            try {
                $spec['secure']
                    ? UrlValidator::secureHttpUrl($spec['url'], "bridge.{$field}")
                    : UrlValidator::httpUrl($spec['url'], "bridge.{$field}");
                if ($field === 'receiver_base_url') {
                    $receiverIsWellFormed = true;
                }
            } catch (Throwable $e) {
                yield Finding::fail($e->getMessage());
            }
        }

        // ⛔ THE ROUTE LEG IS GATED ON THE SYNTAX LEG HAVING PASSED, and the gate is not
        // defensive tidying: *would this app route it* presupposes a URL, so an `ftp://`
        // or whitespace-bearing value would otherwise draw TWO fails for ONE fault with
        // ONE remedy — and the second one would name routing as the cause of a paste error.
        if ($receiverIsWellFormed) {
            yield from $this->receiverRouteFindings($receiverBaseUrl);
        }

        yield Silence::because('every configured endpoint URL validated — and the receiver base, WHERE SET, also composes a receiver URL this app routes — while an unset one is skipped rather than judged, because absence is this install not using that endpoint');
    }

    /**
     * ⭐ THE THIRD FLOOR ON `receiver_base_url`, AND THE ONE THIS CLASS COMPOSES ITSELF
     * (card#9280): a value that is a perfectly well-formed http(s) URL and still composes a
     * receiver URL that reaches NO route in this application. Until this leg existed that
     * fault had no home on any surface that moves an exit code — `bridge:check` exited 0
     * while nothing GitHub sent could arrive, so a cron gating on that exit code read a
     * healthy install. ⚠ It is a GAP that predates the github-subscription leg rather than
     * anything that leg lost: measured against `origin/dev`, no exit code has ever caught
     * this shape.
     *
     * ⛔ THIS LEG MOVES `bridge:check`'s EXIT CODE — an install whose composed receiver URL
     * matches no route here previously exited 0 and now exits non-zero. That was put to the
     * operator with the counter-argument below and chosen deliberately.
     *
     * ⛔ THE PREDICATE IS {@see ReceiverUrl::reachesThisInstall()}, NOT A SECOND COPY OF IT.
     * `bridge:provision` composes the receiver URL, `App\Bridge\Check\Checks\GitHubWebhookSubscriptionCheck`
     * composes it, and this leg composes it; a rule about receiver paths written out a
     * second time here would be free to drift from the router that actually decides, and
     * the drift would red healthy installs.
     *
     * ⚠ THE COUNTER-ARGUMENT, RECORDED BECAUSE IT IS TRUE AND WAS OVERRULED RATHER THAN
     * REFUTED (DL-374). `GitHubWebhookSubscriptionCheck` renders the SAME predicate's
     * `false` as `unvalidated` and never `fail`, partly because an install served behind
     * something that REWRITES the request path answers `false` here and delivers perfectly
     * — so this leg CAN red a working install, and nothing on this box can measure that hop.
     * Two things make the ruling different on this key: the claims are different — that leg
     * would be convicting a repo's WEBHOOK on a premise it cannot establish, while this one
     * judges a CONFIG VALUE against this app's own route table, which is the whole of what
     * it claims — and the shipped verdict text says the proxy case out loud, so the one
     * install this can be wrong about is told, in the line itself, what it is looking at.
     *
     * ⚠ WHAT IT CANNOT ESTABLISH, STATED IN THE SHIPPED LINE AND NOT ONLY HERE. A router
     * answers about PATH and QUERY. The SCHEME, HOST, PORT and USERINFO of the configured
     * value are invisible to it (DL-368 bound (a)), nothing here can drive DNS or a TLS
     * connect, and no part of this leg asks any provider whether it can reach this install
     * — so a base that PASSES is not evidence that a delivery arrives, and this leg never
     * says it is. It closes the ROUTE half of *is this install actually reachable* and
     * declares the rest unmeasured.
     *
     * THE PROVIDERS ARE DERIVED FROM {@see WebhookAdapterFactory::SUPPORTED}, never written
     * out here: the receiver base serves every provider this receiver has an adapter for,
     * and `routes/webhooks.php` leaves the `{provider}` segment unconstrained TODAY — a
     * constraint added there tomorrow could make the answer provider-dependent, which a
     * github-only probe would not see. A provider added to that constant arrives covered.
     *
     * @return iterable<Finding>
     */
    private function receiverRouteFindings(string $receiverBaseUrl): iterable
    {
        $routes = app(Router::class)->getRoutes();

        $unreachable = [];
        foreach (WebhookAdapterFactory::SUPPORTED as $provider) {
            $composed = ReceiverUrl::for($receiverBaseUrl, $provider, self::PROBE_SCOPE);
            if (! ReceiverUrl::reachesThisInstall($composed, $provider, self::PROBE_SCOPE, $routes)) {
                $unreachable[] = $provider;
            }
        }

        if ($unreachable === []) {
            return;
        }

        // ⛔ THE VALUE IS QUOTED THROUGH `SecretScrubber::url()`, exactly as every other
        // refusal this check renders does (card#8433) — a receiver base may legitimately
        // carry credentials in its userinfo, and this is a NEW operator-facing rendering of
        // that same config value. `EndpointUrlRedactionTest` plants a canary on this arm.
        yield Finding::fail(
            "bridge.receiver_base_url '".SecretScrubber::url($receiverBaseUrl)."' reaches NO route in THIS application: "
            .'the receiver URL this install composes from it — <BRIDGE_RECEIVER_BASE_URL>/<provider>?b=<scope> — matches no receiver route here for provider(s) '
            .implode(', ', $unreachable)
            .', so a delivery to it is refused before the receiver ever reads the scope and NO webhook composed from this value — which is how .env.example and docs/writeback.md tell you to compose one — can deliver here. '
            .'This key is the receiver\'s PUBLIC BASE and ALREADY ENDS IN the receiver path (see .env.example, and docs/writeback.md section The repo webhook) — '
            .'a bare host, or one with that path doubled, is what this looks like. Fix BRIDGE_RECEIVER_BASE_URL in this install\'s .env and re-run bridge:check. '
            .'⚠ This leg matched PATH and QUERY through this app\'s own router: it establishes NOTHING about that value\'s scheme, host, port or userinfo, and nothing about '
            .'whether any provider\'s network can reach this install — a base that passes it is not evidence that a delivery arrives. '
            .'⚠ If this install is served behind something that REWRITES the request path, the delivery path is not this app\'s route table, this line is what a WORKING '
            .'install looks like from here, and that hop cannot be measured on this box.'
        );
    }
}
