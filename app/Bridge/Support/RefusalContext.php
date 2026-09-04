<?php

namespace App\Bridge\Support;

use App\Bridge\Writeback\MappedBoardGuard;
use Illuminate\Http\Client\RequestException;

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
 * contract, not a style choice, and it is why {@see self::from()} — not the scrubber —
 * owns the truncation.
 *
 * The scrubbing itself is {@see SecretScrubber}'s. It used to live here, and card#8433
 * moved it out when a second subject (a third-party job handler's exception message)
 * needed it: a class documented as the 4xx-refusal vocabulary is not where the app's
 * credential redactor belongs, and leaving it here is how a second redactor gets written.
 */
final class RefusalContext
{
    private const MAX_BODY = 500;

    /**
     * @return array{status: int, body: string}
     */
    public static function from(RequestException $e): array
    {
        return [
            'status' => $e->response->status(),
            'body' => self::truncate(SecretScrubber::text($e->response->body())),
        ];
    }

    /**
     * Whether a kanban refusal is PERMANENT (a 4xx the client caused) rather than
     * retryable. Load-bearing: a permanent refusal is swallowed + logged with a
     * {@see self::from()} context, while a non-permanent one (5xx / transport) is
     * rethrown so the receiver returns 5xx and kanban re-delivers the webhook.
     */
    public static function isPermanent(RequestException $e): bool
    {
        $status = $e->response->status();

        return $status >= 400 && $status < 500;
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

    private static function truncate(string $body): string
    {
        if (mb_strlen($body) <= self::MAX_BODY) {
            return $body;
        }

        return mb_substr($body, 0, self::MAX_BODY).'…(truncated)';
    }
}
