<?php

namespace App\Bridge\Support;

use App\Bridge\Writeback\MappedBoardGuard;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;

/**
 * The shared vocabulary for a 4xx refusal: the LOG CONTEXT ({@see from}) — status
 * plus the response body, verbatim but truncated and credential-scrubbed — and the
 * ALERT REASON strings ({@see writeReason} / {@see readReason}) the refusal arms
 * dedup on. Both live here because both are derived from the same response status,
 * and a second copy of either would let two arms disagree about one refusal.
 *
 * Status alone cannot tell a permission refusal (403) from a validation refusal
 * (422) from a state refusal (404) — the server states the actual reason in the
 * body, and every writeback handler previously discarded it, so a real incident
 * (DL-204: a 403 authz refusal) was indistinguishable from a config typo. This
 * hands the operator what the server actually said instead of a guessed cause.
 *
 * The body is scrubbed BEFORE truncation: a credential could otherwise be split
 * across the truncation boundary, leaving its head unredacted. ⛔ That ORDER is the
 * contract, not a style choice. Since card#9486 {@see RedactedErrorText::body()} owns it,
 * because a caught exception's text needs the same order and a second copy could drift.
 *
 * The scrubbing itself is {@see SecretScrubber}'s. It used to live here, and card#8433
 * moved it out when a second subject (a third-party job handler's exception message)
 * needed it: a class documented as the 4xx-refusal vocabulary is not where the app's
 * credential redactor belongs, and leaving it here is how a second redactor gets written.
 */
final class RefusalContext
{
    /**
     * @return array{status: int, body: string}
     */
    public static function from(RequestException $e): array
    {
        return [
            'status' => $e->response->status(),
            'body' => RedactedErrorText::body($e->response->body()),
        ];
    }

    /**
     * The 4xx statuses that say "not now" rather than "not this request": the identical
     * request can succeed once time passes. 408 is a request the server stopped waiting for;
     * 429 is a rate limit (card#10849). kanban's `throttle:api` / weighted throttle answer 429
     * BEFORE the controller runs, so a 429'd write was not applied and a retry cannot double
     * it. 409 and 425 are deliberately absent: both are state refusals the same request does
     * not get past by waiting.
     */
    private const RATE_LIMIT_STATUSES = [408, 429];

    /**
     * Whether a refusal is PERMANENT (a 4xx the client caused, which the identical request
     * cannot get past by waiting) rather than retryable. Load-bearing: a permanent refusal
     * is swallowed + logged with a {@see self::from()} context, while a non-permanent one
     * (5xx, transport, or a {@see isRateLimited} status) is rethrown out of the handler.
     *
     * WHAT HAPPENS TO THE RETHROW IS NOT THIS CLASS'S DECISION, and it splits (card#10849 /
     * DL-440): a rate limit is caught by `App\Bridge\Writeback\OwedWriteQueue::drain()`, which
     * records the write as OWED and lets the delivery answer 200 — the bridge retries it
     * itself, honouring {@see retryAfterSeconds}. Anything else transient propagates to a 5xx
     * as it always did.
     *
     * ⛔ UNTIL card#10849 THIS WAS "EVERY 4xx", and a kanban 429 was swallowed as a refusal:
     * a rate-limited card move was DROPPED — the card stayed in the wrong column and the
     * operator got a `{verb}_4xx` alert naming a refusal that waiting would have cleared.
     */
    public static function isPermanent(RequestException $e): bool
    {
        $status = $e->response->status();

        return $status >= 400 && $status < 500 && ! self::isRateLimited($e);
    }

    /**
     * Whether a refusal says "not now" — a rate limit (429) or a request timeout (408). The
     * one predicate the owed-write queue and the two loop handlers ask (card#10849 / DL-440);
     * WHICH client raised it is known structurally at every call site that needs to know, so
     * the exception carries no source.
     */
    public static function isRateLimited(RequestException $e): bool
    {
        return in_array($e->response->status(), self::RATE_LIMIT_STATUSES, true);
    }

    /**
     * The refusal's `Retry-After`, in whole seconds from now, or null when it carries none the
     * bridge can read. RFC 9110 allows delta-seconds or an HTTP-date; a date already past reads
     * as 0, never as a negative wait. A value that is neither is null — the caller's own
     * backoff floor then decides, which is the same answer as a header that was never sent.
     */
    public static function retryAfterSeconds(RequestException $e): ?int
    {
        $value = trim($e->response->header('Retry-After'));
        if ($value === '') {
            return null;
        }
        if (ctype_digit($value)) {
            return (int) $value;
        }

        $at = \DateTimeImmutable::createFromFormat(\DATE_RFC7231, $value, new \DateTimeZone('UTC'));
        if ($at === false) {
            return null;
        }

        return max(0, $at->getTimestamp() - Carbon::now()->getTimestamp());
    }

    /**
     * The alert `reason` for a refused WRITE (a PATCH kanban rejected), keyed on the
     * status an operator acts on differently: 403 = the token can READ this card but
     * not write it — the scope-narrowed-token shape that a read-only probe never
     * reveals, and the one a `getCard`-only signal cannot distinguish; 404 = the card
     * is gone. Every other 4xx keeps the catch-all, so the split adds vocabulary
     * without silently re-labelling the rest.
     *
     * $verb names the failing call (`movecard`, `stamp`, …) because the dedup tuple is
     * `(repo, outcome, reason)`: two arms of one event sharing a reason would suppress
     * each other, so whichever arrived second would alert zero times.
     */
    public static function writeReason(string $verb, RequestException $e): string
    {
        return match ($e->response->status()) {
            403 => $verb.'_403_not_writable_by_this_token',
            404 => $verb.'_404_no_such_card',
            default => $verb.'_4xx',
        };
    }

    /**
     * The alert `reason` for a refused READ. 404 = no such card; 403 = the card exists
     * and this token could not read it — TWO causes, and a 403 cannot choose between
     * them; the ⛔ note below is where they are stated, and this sentence deliberately
     * does not pick one. That is a different operator hypothesis from
     * {@see writeReason}'s 403, which is why the two are separate helpers rather than
     * one status map.
     *
     * ⛔ THE 403 SLUG NAMES TWO CAUSES BECAUSE THERE ARE TWO, AND A 403 CANNOT CHOOSE
     * (DL-314, card#7846). It reads `_403_foreign_card_id_or_token_scope`: either (a) a
     * FOREIGN install's card id was correlated onto this bridge — kanban's card id space
     * is GLOBAL across every board on a shared instance and `card#NNNN` is parsed as a
     * literal out of author-controlled text, so an id naming another install's card
     * reaches the read intact — or (b) this token's scope is missing a board of its OWN
     * (rotation, lost membership). The prior slug, `_403_not_visible_to_this_token`,
     * stated only (b): it named the TOKEN as the thing at fault, and the operator who
     * hit (a) live went looking at their own token's scope for a card that was never
     * theirs. ⛔ Do NOT "improve" this into one hypothesis: nothing in a 403 response
     * distinguishes them. A slug that picked one on the STATUS alone would be
     * wrong-but-specific, which this file already rules worse than an honest generic
     * (canon #10, and the same reasoning that keeps the GitHub reads flat).
     *
     * ⭐ $foreignIdExcluded IS THE OTHER EVIDENCE — and it is precisely the thing DL-314
     * recorded as the deferred option: a BOARD-SCOPED read of the id against this install's
     * own board. Since card#8375 `kanban_move_card` — and since card#8415 the
     * `kanban_block_reason` draft overlay — makes exactly that read BEFORE it calls
     * `getCard` ({@see MappedBoardGuard::refusesCardIdOutsideMappedBoard}).
     * By the time either 403 arm fires, cause (a) has been ruled out BY A MEASUREMENT: the same
     * token read this id back off the mapped board moments earlier. Those callers pass true and
     * get `{verb}_403_token_scope`, naming the one cause left.
     * ⛔ THE FLAG IS A CLAIM ABOUT THE CALL SITE, NEVER A PREFERENCE. Pass it only where a
     * board-scoped establishment of THIS id precedes the failing read, or where the failing
     * read is ITSELF board-scoped (a 403 on a query naming our own board says nothing about
     * whose card the id is). No shipped arm passes the default today — the two-cause slug is
     * kept, and pinned in `RefusalContextTest`, because it is the honest answer for an arm
     * that makes no such check, not because one currently exists.
     */
    public static function readReason(string $verb, RequestException $e, bool $foreignIdExcluded = false): string
    {
        return match ($e->response->status()) {
            404 => $verb.'_404_no_such_card',
            403 => $foreignIdExcluded ? $verb.'_403_token_scope' : $verb.'_403_foreign_card_id_or_token_scope',
            default => $verb.'_4xx',
        };
    }
}
