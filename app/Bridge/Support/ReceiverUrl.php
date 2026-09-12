<?php

namespace App\Bridge\Support;

use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollectionInterface;
use Throwable;

/**
 * THE RECEIVER URL A `(provider, scope)` SUBSCRIPTION MUST POINT AT, and the predicate
 * that decides whether a live upstream subscription IS that one (card#9150).
 *
 * ⛔ WHY IT IS A PRIMITIVE AND NOT TWO STRING LITERALS. `bridge:provision` composes this
 * URL to create a kanban subscription and matches the upstream's live list against it to
 * stay idempotent; since card#9150 `bridge:check` composes the SAME URL to ask whether a
 * github repo still carries a webhook pointing here. Those two derivations had to be the
 * same string or the check would report a live hook as MISSING — and a missing hook is a
 * `fail`, so a one-character drift between two copies would red a healthy install and move
 * its exit code. One home makes that unrepresentable rather than watched for.
 *
 * ⛔ THERE ARE TWO MATCH PREDICATES HERE AND THEY DELIBERATELY DISAGREE (card#9150 r1).
 * `https://host/github?b=owner/repo` and `https://host/github?b=owner%2Frepo` route
 * IDENTICALLY at the receiver — `VerifyHmacSignature` reads the scope with
 * `$request->query('b')`, and PHP decodes a query value once on the way into that bag — but
 * they are not the same string, and the two callers need opposite answers about them:
 *
 *   - {@see self::matchesExactly()} — `bridge:provision`'s, byte equality. UNCHANGED, and
 *     deliberately not widened: what provision treats as an ALREADY-EXISTING subscription
 *     decides whether it creates one, which is a change to what the system accepts and was
 *     explicitly not authorised. It is also the safer direction there — provision writes,
 *     and a create it should not have made is recoverable while an adoption it should not
 *     have made silently leaves a subscription nobody reconciles.
 *   - {@see self::deliversTo()} — `bridge:check`'s, encoding-insensitive. It REPORTS, and
 *     its negative arm is a `fail` that moves the exit code, so an equivalent spelling
 *     reading as absent reds a HEALTHY install. Measured on a live consumer install
 *     registering `?b=PupFuzz%2Fmezzanine`: under byte equality that seat's working webhook
 *     was reported missing, which inverts the ruling this whole leg rests on — `fail` was
 *     chosen because a deaf agent is a broken install, not to red a working one.
 *
 * ⛔ AND A THIRD PREDICATE THAT IS NOT A MATCH AT ALL (card#9150 r6). {@see self::reachesThisInstall()}
 * asks whether the URL {@see self::for()} COMPOSES would be received by THIS APP — the question
 * neither comparison above can ask, because both are symmetric relations between two URLs and
 * neither takes the app as an argument. Its absence was a SILENT false-`ok`: on a mis-set
 * `BRIDGE_RECEIVER_BASE_URL` the hook an operator creates from the documented payload URL is
 * byte-equal to what this install composes, so `deliversTo()` answers YES about two URLs that
 * both reach nothing. It is `bridge:check`'s alone; provision has no use for it, because a
 * subscription it registers is validated by the upstream that receives it.
 *
 * ⛔ THE DIVERGENCE IS THE POINT AND IS STATED ON BOTH SIDES, because two copies of one rule
 * that quietly differ is the drift defect this fleet keeps filing. Neither predicate may be
 * "simplified" into the other: they answer different questions — *is this the subscription I
 * would create?* versus *would this hook deliver here?* — and the operator ruled explicitly
 * against pointing both at one widened predicate.
 */
final class ReceiverUrl
{
    /**
     * The URL an upstream subscription for `(provider, scope)` must call back on.
     *
     * The scope rides in the query string UNENCODED — that is the spelling
     * `bridge:provision` has always registered and the one `docs/writeback.md` § *The repo
     * webhook* tells an operator to paste into GitHub, so composing it any other way would
     * make every existing install's hook read as a stranger.
     */
    public static function for(string $receiverBaseUrl, string $provider, string $scopeId): string
    {
        return rtrim($receiverBaseUrl, '/')."/{$provider}?b={$scopeId}";
    }

    /**
     * Would the URL {@see self::for()} COMPOSES actually be RECEIVED BY THIS APP — the
     * question the two match predicates structurally cannot ask (card#9150 r6).
     *
     * ⛔ THE DEFECT THIS CLOSES IS THE ONE {@see self::deliversTo()} CANNOT SEE, BECAUSE THAT
     * PREDICATE IS SYMMETRIC. It compares two URLs and never asks whether either of them is
     * this install's receiver — so feed it a live hook and a receiver composed from a mis-set
     * `BRIDGE_RECEIVER_BASE_URL` and they can be EQUAL, which an operator who pastes the
     * payload URL exactly as `bridge:check` and `docs/writeback.md` instruct makes equal BY
     * CONSTRUCTION. Measured: base `https://bridge.example.com` composes `…/github?b=<scope>`
     * and base `…/webhooks/webhooks` composes `…/webhooks/webhooks/github?b=<scope>`; both
     * compared `true` and both answer `NotFoundHttpException` at this app. That is a SILENT
     * false-`ok` — the agent is deaf, `bridge:check` is green, and no surface says so — which
     * is the exact failure the whole leg exists to prevent.
     *
     * ⭐ THE ORACLE IS THE RECEIVER'S OWN ROUTER, NEVER A RULE ABOUT PATHS SOMEBODY WROTE.
     * `$routes` is this app's compiled route collection, and the two terms below are exactly
     * what `App\Http\Middleware\VerifyHmacSignature` reads before it will accept a delivery:
     * the `{provider}` segment the route binds, and the scope it takes out of
     * `$request->query('b')`.
     *
     * ⛔ ROUTE MATCHING ALONE IS **NOT** THE PREDICATE, and the second term is not decoration.
     * A base carrying its own query — `…/webhooks/github?z=1` — matches the route perfectly
     * and swallows the composed `?b=` INSIDE that query's value, so the middleware reads no
     * scope at all and answers `invalid_scope` 400. Measured through `Request::create()`:
     * the route resolves, `query('b')` is `null`, the delivery feeds nothing.
     * `ReceiverUrlRoutingAgreementTest` generates that member and reds on a route-only
     * implementation of this method.
     *
     * ⛔ THE ROUTE COLLECTION IS A PARAMETER, NOT AN `app('router')` CALL, so this class keeps
     * its total independence from the container: `bridge:provision` uses it too, and a Support
     * primitive that resolves its own collaborators cannot be reasoned about from its
     * arguments — nor pointed at a route table other than the ambient one by a test.
     *
     * ⚠ WHAT IT CANNOT SEE, AND WHY A CALLER MUST NOT RENDER `false` AS A FAULT OF THE WEBHOOK.
     * This compares the composed URL's PATH against this app's own route table, which is the
     * real delivery path only while the app is served at the root of the host the base URL
     * names — the deployment `CLAUDE_DEPLOYMENT.md` documents (an Apache vhost whose
     * DocumentRoot is the app's `public/`, routing `/webhooks/*`). An install behind a proxy
     * that REWRITES the path would answer `false` here and deliver perfectly well, and nothing
     * on this box can measure that hop — the same class of unmeasurable as the scheme, host and
     * port, which {@see self::deliversTo()} holds fixed for the same reason. That is why
     * `App\Bridge\Check\Checks\GitHubWebhookSubscriptionCheck` renders it `unvalidated`
     * (Severity limb (a) — a read, probe or query that threw or was SKIPPED: on `false` that
     * check asks GitHub nothing at all, so no comparison ever runs) and never `fail`. ⚠ THIS
     * CITED LIMB (c) UNTIL r7 AND THAT WAS THE WRONG LIMB: the comparand here resolves to
     * exactly one perfectly comparable URL: what is missing is the MEASUREMENT, not the value.
     *
     * ⛔ THERE ARE NOW TWO CALLERS AND THEY RENDER `false` AT DIFFERENT SEVERITIES ON PURPOSE
     * (card#9280 / DL-374). `App\Bridge\Check\Checks\InstallEndpointUrlsCheck` renders it
     * **`fail`**, and moves `bridge:check`'s exit code with it. Read the paragraph above as
     * scoped to what it actually says — a caller must not render `false` as a fault of THE
     * WEBHOOK — rather than as a rule that no caller may fail: the two are asking about
     * different subjects. That check is judging `BRIDGE_RECEIVER_BASE_URL` against this app's
     * own route table, which is the whole of what it claims, and it needs no premise about any
     * repo's hook list to say the value is wrong; the github leg would be convicting a repo's
     * webhook on a comparison it declined to make. ⚠ The path-rewriting-proxy residual named
     * above is REAL for the new caller too and is not closed by it — it is disclosed in that
     * leg's own shipped verdict text, so the one install shape this can be wrong about is told
     * what it is looking at. A change here that widens or narrows what `false` means moves an
     * exit code; `InstallEndpointUrlsCheckTest` and the `receiver-url-unreachable` golden
     * fixture both red on it.
     */
    public static function reachesThisInstall(string $receiverUrl, string $provider, string $scopeId, RouteCollectionInterface $routes): bool
    {
        try {
            $request = Request::create($receiverUrl, 'POST', [], [], [], [], '{}');
            $route = $routes->match($request);
        } catch (Throwable) {
            // EVERY way this app can decline the URL is one answer — it does not deliver here.
            // `match()` throws for no route and for a route registered under another verb, and
            // `Request::create()` itself throws on a URI the framework will not build at all.
            // Distinguishing them would be inventing a vocabulary no caller can act on
            // differently: the remedy for all of them is the same env var.
            return false;
        }

        return $route->parameter('provider') === $provider
            && $request->query('b') === $scopeId;
    }

    /**
     * `bridge:provision`'s predicate: is `$liveUrl` BYTE-FOR-BYTE the URL this install would
     * register?
     *
     * ⛔ DO NOT WIDEN THIS TO {@see self::deliversTo()}. What provision treats as an
     * already-existing subscription decides whether it CREATES one — a change to what the
     * system accepts, which is operator-gated in this repo and was explicitly refused when
     * card#9150 r1 widened the check's half. A test asserts this half is unmoved
     * (`ProvisionTest::test_provision_does_not_adopt_a_percent_encoded_subscription_url`), so
     * a future edit that collapses the two reds rather than shipping.
     *
     * `null` is accepted because both callers read the field out of a decoded API response
     * where it may be absent; an answer with no URL matches nothing, which is the safe
     * direction for both of them (provision creates, check reports missing).
     */
    public static function matchesExactly(?string $liveUrl, string $receiverUrl): bool
    {
        return $liveUrl === $receiverUrl;
    }

    /**
     * `bridge:check`'s predicate: would a hook whose delivery URL is `$liveUrl` deliver TO
     * THIS INSTALL'S RECEIVER, for the scope `$receiverUrl` names?
     *
     * ⛔ DELIBERATELY NOT {@see self::matchesExactly()}, and that divergence is stated on
     * that method too — see this class's docblock for the ruling and the measured install
     * that forced it. This side reports; that side writes.
     *
     * ⭐ THE EQUIVALENCE CLASS IS THE RECEIVER'S OWN ROUTING, NOT A HAND-PICKED SET OF
     * SPELLINGS, and each half of it is MEASURED against this app rather than reasoned:
     *
     *   - the QUERY is compared as PARSED PARAMETERS, which decodes each value exactly once
     *     — precisely what `$request->query('b')` hands `VerifyHmacSignature` — so
     *     `?b=owner%2Frepo` and `?b=owner/repo` are one hook;
     *   - TRAILING SLASHES on the path are dropped, because the compiled route tolerates them.
     *     Measured through the real router: `/webhooks/github?b=…`, `/webhooks/github/?b=…`
     *     and `/webhooks/github//?b=…` all resolve to the SAME route with the SAME parameters,
     *     so a hook spelled with one is LIVE;
     *   - the PATH is percent-DECODED (after the trim — see the comment on that expression for
     *     why the order is load-bearing), because the matcher decodes segment content:
     *     `/webhooks/git%68ub` and `/webhooks%2Fgithub` both route as `provider=github`;
     *   - a FRAGMENT is DISCARDED WITH EVERYTHING AFTER IT, before the query is even looked
     *     for — it is never transmitted, so `…/github?b=owner/repo#frag` is the same delivery
     *     as `…/github?b=owner/repo`, while `…/github#x?b=owner/repo` sends NO query at all
     *     and is therefore a different one. {@see self::split()} owns that and says why the
     *     order is the whole of it;
     *   - the SCHEME and HOST are lowercased and an explicit `:443`/`:80` matching the scheme
     *     is dropped — RFC 3986 §6.2.2.1/§6.2.3 syntax-based normalization, i.e. properties of
     *     how the delivery REACHES this box rather than of this app. ⚠ Stated as the standard
     *     rather than as a probe, because nothing here can drive DNS or a real TLS connect.
     *
     * ⛔ BOTH THE PATH RULE AND THE QUERY RULE ARE PINNED AGAINST THE RECEIVER ITSELF, OVER
     * GENERATED POPULATIONS. `Tests\Feature\Console\Check\ReceiverUrlRoutingAgreementTest`
     * derives path spellings from the canonical path and query spellings from the canonical
     * query by mechanical transforms, and requires this predicate to agree with what the
     * request actually resolves to — the matched route WITH its parameters, and the scope
     * `VerifyHmacSignature` then reads out of it. ⚠ The query was held FIXED there for one
     * round, on the stated ground that a router cannot answer for it. True of scheme/host/
     * port; false of the query, which this box answers with the same call the middleware
     * makes — and the half of the predicate that produced the ORIGINAL live defect was the
     * half left judged by a hand-written table.
     *
     * ⚠ THAT IS A MUCH LARGER DENOMINATOR THAN A LIST — IT IS NOT A PROOF THE CLASS IS CLOSED,
     * and saying it was is how this card kept re-finding the same defect. r3 shipped that
     * claim over a TEN-ELEMENT LITERAL LIST; a generated population then found **15** further
     * disagreements it scored zero on, every one a percent-encoded path character (a live
     * spelling: `/webhooks/git%68ub` routes, kernel 401). The generator enumerates TRANSFORMS
     * rather than spellings, which is a far smaller thing to be wrong about — but it is still
     * something someone wrote, so a transform nobody thought of is still invisible. Read this
     * as *the population is derived and wide*, never as *the class is closed*.
     *
     * ⛔ AND IT NORMALISES *LESS* THAN `Request::path()`, WHICH IS THE CORRECTION THAT MATTERS.
     * A review round proposed mirroring `path()` — `'/'.trim($path, '/')`, which folds LEADING
     * slashes too — on the premise that `//webhooks/github?b=…` is live. **Measured through
     * this app's kernel, it is not:** `path()` does answer `webhooks/github` for it, but the
     * MATCHER reads `getPathInfo()`, which keeps the leading slashes, so it matches NO ROUTE
     * and answers **404**. Adopting that change makes a DEAD spelling compare equal and reports
     * a green `ok` for a hook that delivers nothing — the inverse defect, and the worse one,
     * because a false `fail` is loud and a false `ok` is silent. The agreement test above reds
     * on exactly that mutation.
     *
     * ⛔ PATH CASE IS NOT FOLDED EITHER, measured in the same direction: `/Webhooks/github?b=…`
     * answers **404** and `/webhooks/GitHub?b=…` answers **400 `invalid_provider`**. Those
     * genuinely deliver nothing.
     *
     * ⛔ DECODED ONCE, NEVER REPEATEDLY, AND `%252F` THEREFORE DOES **NOT** MATCH — decided
     * from the receiver's behaviour rather than from taste. `?b=owner%252Frepo` arrives as
     * the literal scope `owner%2Frepo`, which `App\Bridge\Validation\ScopeId` REFUSES (`%` is
     * outside its character class), so the receiver answers `invalid_scope` 400 and the hook
     * delivers NOTHING. Reporting it as absent is the correct verdict, not a false negative:
     * that is exactly the state the `fail` arm exists to name.
     *
     * ⚠ WHAT IS NORMALISED IS THE LIST ABOVE, AND NOTHING ELSE IS CLAIMED. Any other spelling
     * the receiver would tolerate but this predicate has not been shown to reads as ABSENT,
     * and on this leg that is a `fail` that moves the exit code. The one member of that
     * residual which is KNOWN and measured is a hook carrying an EXTRA query parameter: the
     * receiver reads only `b` and would deliver, this compares the whole parameter map and
     * says absent.
     *
     * ⛔ THAT SENTENCE ONCE CLAIMED THE RESIDUAL WAS ONE-DIRECTIONAL, AND IT WAS FALSE — which
     * is worth more than the bug it hid (card#9150 r5). It named *"a fragment"* as a second
     * known member reading as absent. The trailing fragment does; a fragment BEFORE the query
     * did the opposite — it read as PRESENT for a hook that delivers nothing, because
     * {@see self::split()} cut on `?` alone. The claim was the stated basis for accepting a
     * wide residual (*it is only ever the loud direction*), so being wrong about its DIRECTION
     * was worse than being wrong about its membership: a maintainer reading it would not go
     * looking for a false `ok`. ⭐ The residual is now asserted rather than described —
     * `ReceiverUrlRoutingAgreementTest` requires `deliversTo()` `true` ⇒ the receiver agrees,
     * over EVERY member of both generated populations with no exception, and requires full
     * agreement over the complement of the extra-parameter residual, expressed as a predicate
     * on the URL's parameter keys rather than as a list of members somebody wrote down.
     *
     * ⛔ THAT RESIDUAL IS OPEN, NOT CLOSED, AND THIS DELIBERATELY DOES NOT ENUMERATE IT. An
     * earlier revision listed two members and called them "a known, deliberate bound" — an
     * exhaustive-list claim over a population nobody had derived, and the trailing-slash
     * spelling was already outside it and already reddening a healthy install. The honest
     * shape is the positive statement above (what IS normalised, each with its evidence) plus
     * this warning; a reader who needs the negative set must derive it against the receiver,
     * as `Tests\Unit\Support\ReceiverUrlTest` does for the members it pins.
     *
     * ⚑ `===` ON TWO ARRAYS IS ORDER-SENSITIVE, and that cannot bite while the bound above
     * holds: {@see self::for()} composes exactly ONE parameter, so any live URL that agrees
     * on the map at all agrees on its order too. It is named because it stops being inert the
     * moment someone relaxes the extra-parameter bound — that change has to bring an
     * order-insensitive comparison with it.
     */
    public static function deliversTo(?string $liveUrl, string $receiverUrl): bool
    {
        if ($liveUrl === null) {
            return false;
        }

        // NO `$liveUrl === $receiverUrl` FAST PATH. It would be a second implementation of
        // "equal" sitting in front of the real one, and the two could drift the moment
        // anything below changes — for a saving of one string split on a list of at most a
        // few dozen hooks, once per scope, per `bridge:check` run.
        [$liveEndpoint, $liveQuery] = self::split($liveUrl);
        [$ourEndpoint, $ourQuery] = self::split($receiverUrl);

        if (self::normaliseEndpoint($liveEndpoint) !== self::normaliseEndpoint($ourEndpoint)) {
            return false;
        }

        parse_str($liveQuery, $liveParams);
        parse_str($ourQuery, $ourParams);

        return $liveParams === $ourParams;
    }

    /**
     * One endpoint, reduced to the spellings this receiver cannot tell apart.
     *
     * ⛔ USED ONLY BY {@see self::deliversTo()}. `matchesExactly()` never calls it: provision
     * compares what it would WRITE, and normalising there would change what it treats as an
     * existing subscription.
     *
     * ⚠ THE USERINFO IS PRESERVED AND CASE-SENSITIVE. A receiver base URL can legitimately
     * carry credentials (`EndpointUrlRedactionTest` pins that shape), and folding it away
     * would make a credentialed endpoint equal an uncredentialed one. It never leaves this
     * method — the caller gets a bool.
     *
     * A value `parse_url` cannot read as an absolute URL is returned with trailing slashes
     * trimmed and nothing else assumed about it: that is the one normalisation that needs no
     * structure.
     */
    private static function normaliseEndpoint(string $endpoint): string
    {
        $parts = parse_url($endpoint);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return rtrim($endpoint, '/');
        }

        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? null;
        if (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) {
            $port = null;
        }

        $userinfo = isset($parts['user'])
            ? $parts['user'].(isset($parts['pass']) ? ':'.$parts['pass'] : '').'@'
            : '';

        return $scheme.'://'.$userinfo.strtolower($parts['host'])
            .($port === null ? '' : ':'.$port)
            // ⛔ TRIM THE RAW PATH, THEN DECODE — the ORDER is measured, not stylistic, and
            // both orders are wrong the other way round. The matcher tolerates a trailing
            // slash on the RAW path but decodes segment CONTENT, and those are different
            // things: `/webhooks/github/` routes, `/webhooks/github%2F` does NOT, while
            // `/webhooks%2Fgithub` and `/webhooks/git%68ub` both route as `provider=github`.
            // Decoding first turns that `%2F` into a trailing slash the trim then eats, so the
            // predicate answers `true` for a spelling that delivers NOTHING — the silent
            // false-`ok`. Trimming first keeps it as content, where the router keeps it.
            //
            // Path case is NOT folded — see deliversTo()'s docblock for the measurement that
            // decided it.
            .rawurldecode(rtrim($parts['path'] ?? '', '/'));
    }

    /**
     * `[endpoint, query]` — of the URL AS IT WOULD BE SENT.
     *
     * A URL with no `?` yields an empty query, which `parse_str` turns into an empty map —
     * so an endpoint-only hook compares unequal to one carrying `b`, without a special case.
     *
     * ⛔ THE FRAGMENT IS CUT FIRST, AND THE ORDER IS THE WHOLE OF THIS METHOD (card#9150 r5).
     * A `#` does not delimit a field here — it TERMINATES the URL: everything from it onward
     * is a fragment, which is never transmitted. So it must be discarded BEFORE the `?` is
     * looked for, never reinterpreted as query. Splitting on `?` alone read
     * `…/webhooks/github#x?b=owner/repo` as the canonical endpoint plus `b=owner/repo` and
     * answered YES — while the receiver gets `POST /webhooks/github` with NO query string,
     * `VerifyHmacSignature` reads `query('b')` as `null` and answers `invalid_scope` 400.
     * ⭐ That is the SILENT false-`ok` this whole leg exists to prevent: the hook feeds
     * nothing, forever, and `bridge:check` calls the subscription healthy. The mirror case —
     * a fragment AFTER the query — was the loud one: the fragment bytes landed in the scope
     * value and reddened a hook that delivers correctly. One cut fixes both, because both are
     * the same misreading of one character.
     *
     * `Tests\Feature\Console\Check\ReceiverUrlRoutingAgreementTest::queryRegions()` generates a
     * `#` at EVERY index rather than at the two positions a reviewer would think of, because
     * which answer is right is decided by that position.
     *
     * @return array{0: string, 1: string}
     */
    private static function split(string $url): array
    {
        $hash = strpos($url, '#');
        if ($hash !== false) {
            $url = substr($url, 0, $hash);
        }

        $at = strpos($url, '?');

        return $at === false
            ? [$url, '']
            : [substr($url, 0, $at), substr($url, $at + 1)];
    }
}
