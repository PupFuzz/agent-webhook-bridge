<?php

namespace App\Bridge\Tools;

use App\Bridge\Exceptions\ToolRefusalException;
use App\Bridge\Support\RefusalContext;
use App\Bridge\Writeback\KanbanFieldLimits;
use Illuminate\Http\Client\RequestException;

/**
 * WHICH KANBAN 4xx A BOARD TOOL ANSWERS AS A NAMED REFUSAL, AND WHICH IT LEAVES TO
 * {@see BoardToolDispatcher}'s RETRYABLE 502 — ONE classifier for every tool on the door
 * (card#8486). DL-326 built this mapping inside {@see BoardCorrectCardTool}, where it was
 * correct and unreachable to its two siblings: `board_my_cards` and `board_create_card`
 * sent every board 4xx to the 502, so a rotated writeback token (401) or a narrowed token
 * scope (403) put a seat into the retry loop DL-020 exists to warn about, for a cause no
 * number of retries can change. The mapping is hoisted rather than copied because a second
 * copy would let two tools disagree about one refusal — the same reason
 * {@see CallerTagPolicy} owns the tag vocabulary and {@see KanbanFieldLimits} the caps.
 *
 * ⛔ THIS IS NOT {@see RefusalContext::isPermanent}, AND MUST NOT BE CONSOLIDATED WITH IT.
 * That one answers a DIFFERENT question for the WEBHOOK path — *may this handler swallow
 * the event, or must it 5xx so kanban re-delivers?* — over the whole 400–499 range, where
 * swallowing a retryable status is the expensive mistake. Here the caller is a SEAT holding
 * a live call, the fallback is a 502 it may retry, and the vocabulary is the set of causes
 * this door can actually NAME. A status outside the sets below (400, 408, 429 …) is left on
 * the retryable path deliberately: the bridge has no diagnosis to offer for it, and a rate
 * limit really does clear.
 *
 * ⭐ READ AND WRITE ARE SEPARATE SETS BECAUSE 422 MEANS SOMETHING ONLY A WRITE CAN MEAN.
 * A 422 on a write is kanban's own validator refusing a VALUE the caller sent — deterministic,
 * and the backstop that makes {@see KanbanFieldLimits}'s mirrored caps safe to go stale. A read
 * sends no such value, so a 422 there is a malformed-query/API-surface fault the bridge cannot
 * name to the seat, and it stays retryable. This is the split {@see RefusalContext} already makes
 * between `readReason()` and `writeReason()` for the writeback's alert vocabulary, for the same
 * reason: one status map over two different operator hypotheses states neither.
 *
 * ⚠ THE CALLER STILL OWNS ITS OWN LOG LINE AND ITS OWN SENTENCE. What is single-sourced here
 * is the DECISION (permanent vs retryable) and the shared vocabulary — the read refusal's whole
 * message ({@see readRefusal}), the per-status cause ({@see readCause}), the bridge's value-bounds
 * clause ({@see bridgeBoundsClause}) and the over-long-name refusal ({@see overLongName}). A write
 * refusal's message is NOT here: what a refused write means is a property of the write
 * (a create that did not happen, a correction whose card vanished between the check and the
 * PATCH), so each tool composes it and this class only says whether one is owed.
 */
final class BoardCallRefusal
{
    /**
     * The status of a READ the board refused PERMANENTLY, or null when the fault may clear
     * (⇒ rethrow, and the dispatcher answers the retryable 502).
     *
     * @return 401|403|404|null
     */
    public static function permanentOnRead(RequestException $e): ?int
    {
        return match ($e->response->status()) {
            401 => 401,
            403 => 403,
            404 => 404,
            default => null,
        };
    }

    /**
     * The status of a WRITE the board refused PERMANENTLY, or null when the fault may clear.
     * As {@see permanentOnRead}, plus 422 — see the class docblock for why that one arm differs.
     *
     * @return 401|403|404|422|null
     */
    public static function permanentOnWrite(RequestException $e): ?int
    {
        return match ($e->response->status()) {
            401 => 401,
            403 => 403,
            404 => 404,
            422 => 422,
            default => null,
        };
    }

    /**
     * The operator-facing CAUSE of a read the board refused — the clause that says what the
     * seat's operator should go and look at.
     *
     * ⛔ THE 403 CAUSE NAMES THE TOKEN'S ABILITIES, NOT ITS BOARD MEMBERSHIP, and the correction
     * matters because the wrong cause sends the operator to audit something that cannot produce
     * this status: kanban's search FLOORS a caller to its member boards and answers
     * 200-with-zero-rows for the rest, so a membership gap arrives as an EMPTY read, never as a
     * 403. What does answer 403 on these routes is the Sanctum ability gate (kanban DL-055: a GET
     * needs `read`), i.e. a token issued with too narrow a scope.
     *
     * A 401 is the same class of permanent: the token was not accepted at all — revoked, rotated,
     * or replaced by a value the board does not know.
     *
     * @param  401|403|404  $status
     */
    public static function readCause(int $status): string
    {
        return match ($status) {
            401 => "the bridge's writeback token was not accepted at all — it has been revoked, rotated or replaced with a value the board does not know",
            403 => "the bridge's writeback token was recognised but not permitted to READ — kanban gates the API on per-token abilities, and this one lacks `read` (board membership does NOT produce a 403: an unreadable board answers zero rows instead)",
            404 => 'the board answered 404 for the card search itself, which is an API-surface fault rather than a missing card',
        };
    }

    /**
     * The shared refusal for a read the board refused permanently. $what names what the bridge
     * was trying to read and $consequence what the call did NOT do — the two halves a seat needs
     * that differ per tool; everything else, including the INSTALL-fault framing and the
     * do-not-retry instruction, is one sentence for the whole door.
     *
     * @param  401|403|404  $status
     */
    public static function readRefusal(string $tool, int $status, string $what, string $consequence): ToolRefusalException
    {
        return new ToolRefusalException("{$tool}: the bridge could not read {$what} (the board answered {$status}) — {$consequence}. This is an INSTALL fault, not something your arguments can fix: ".self::readCause($status).'. Retrying will not change it; report it to your operator.');
    }

    /**
     * The bridge's own value bounds, stated to the seat on a 422 the BOARD answered.
     *
     * ⛔ IT IS BRIDGE-AUTHORED BECAUSE THE BOARD'S BODY IS NEVER ECHOED: that body is an upstream
     * artefact whose shape and contents this door does not control, and the bounded statement
     * below is what a caller can act on. It exists because {@see KanbanFieldLimits}'s caps mirror
     * rules that live in kanban's repo and can therefore go stale — reaching a board 422 with the
     * bridge-side bounds satisfied is exactly the signal that one has.
     */
    public static function bridgeBoundsClause(): string
    {
        return 'The bridge bounds `name` at '.KanbanFieldLimits::NAME_MAX.' characters and each tag at '.KanbanFieldLimits::TAG_MAX.' before it sends, so reaching this means a value broke a kanban rule the bridge does not mirror (or one that has moved).';
    }

    /**
     * The refusal for a title/name longer than kanban's own cap, or null when it fits — the
     * DIAGNOSTIC half of {@see KanbanFieldLimits} (that class states what a mirror is worth):
     * an over-long value is named to the caller BEFORE the request, instead of arriving as a
     * board 422 the seat reads as a retryable `502 upstream board error` and loops on.
     *
     * mb_strlen, because Laravel's `max` sizes a string that way — and unlike a tag, a title is
     * not charset-constrained, so bytes and characters genuinely differ here.
     */
    public static function overLongName(string $tool, string $field, string $value, string $nothingHappened): ?ToolRefusalException
    {
        if (mb_strlen($value) <= KanbanFieldLimits::NAME_MAX) {
            return null;
        }

        return new ToolRefusalException("{$tool}: `{$field}` is ".mb_strlen($value).' characters — kanban accepts at most '.KanbanFieldLimits::NAME_MAX.' (`name => string|max:255`), so the board would reject the write. '.$nothingHappened.'; shorten it.');
    }
}
