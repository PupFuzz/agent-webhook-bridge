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
 * ⚠ THE MATCH IS EXACT, AND THAT BOUND IS THE CONTRACT, not an implementation detail a
 * caller may hope is looser. `https://host/github?b=owner/repo` and
 * `https://host/github?b=owner%2Frepo` route identically at the receiver and are NOT equal
 * here. That is the behavior `bridge:provision` has always had (it would create a second
 * subscription rather than adopt the first), and this class preserves it rather than
 * quietly widening what an already-shipped command accepts. The consequence is on the
 * READER: a consumer that reports a negative match must say it matched by exact URL, so an
 * operator who typed an equivalent spelling can see the cause instead of hunting a hook
 * that is in front of them.
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
     * Is `$liveUrl` — the callback URL an upstream reports for one of ITS subscriptions —
     * the one this install would register?
     *
     * `null` is accepted because both callers read the field out of a decoded API response
     * where it may be absent; an answer with no URL matches nothing, which is the safe
     * direction for both of them (provision creates, check reports missing).
     */
    public static function matches(?string $liveUrl, string $receiverUrl): bool
    {
        return $liveUrl === $receiverUrl;
    }
}
