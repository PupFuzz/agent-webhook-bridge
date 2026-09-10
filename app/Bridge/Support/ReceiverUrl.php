<?php

namespace App\Bridge\Support;

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
     * SPELLINGS. The endpoint (everything before the `?`) must match byte for byte — a
     * different host or path is a different install and is none of this method's business to
     * normalise — and the query is compared as PARSED PARAMETERS, which decodes each value
     * exactly once, which is precisely what `$request->query('b')` gives
     * `VerifyHmacSignature`. So `?b=owner%2Frepo` and `?b=owner/repo` are one hook, because
     * the receiver cannot tell them apart either.
     *
     * ⛔ DECODED ONCE, NEVER REPEATEDLY, AND `%252F` THEREFORE DOES **NOT** MATCH — decided
     * from the receiver's behaviour rather than from taste. `?b=owner%252Frepo` arrives as
     * the literal scope `owner%2Frepo`, which `App\Bridge\Validation\ScopeId` REFUSES (`%` is
     * outside its character class), so the receiver answers `invalid_scope` 400 and the hook
     * delivers NOTHING. Reporting it as absent is the correct verdict, not a false negative:
     * that is exactly the state the `fail` arm exists to name.
     *
     * ⚠ THE COMPARISON IS OVER THE WHOLE PARAMETER MAP, so a hook carrying an EXTRA query
     * parameter — or a fragment, which is never transmitted to a server at all — reads as
     * absent even though the receiver would ignore it. That is a known, deliberate bound and
     * not an oversight: the ruling authorised normalising ENCODING and nothing else, and
     * widening past it here would be the second unreviewed widening of a predicate whose
     * negative arm moves an exit code.
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

        if ($liveEndpoint !== $ourEndpoint) {
            return false;
        }

        parse_str($liveQuery, $liveParams);
        parse_str($ourQuery, $ourParams);

        return $liveParams === $ourParams;
    }

    /**
     * `[everything before the first `?`, everything after it]`.
     *
     * A URL with no `?` yields an empty query, which `parse_str` turns into an empty map —
     * so an endpoint-only hook compares unequal to one carrying `b`, without a special case.
     *
     * @return array{0: string, 1: string}
     */
    private static function split(string $url): array
    {
        $at = strpos($url, '?');

        return $at === false
            ? [$url, '']
            : [substr($url, 0, $at), substr($url, $at + 1)];
    }
}
