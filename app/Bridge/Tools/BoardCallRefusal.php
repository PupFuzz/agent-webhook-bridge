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
 * is the DECISION (permanent vs retryable) and the shared VOCABULARY — the read refusal's whole
 * message ({@see readRefusal}), the per-route-per-status cause ({@see readCause}), the gates a
 * write 403 must send an operator to audit ({@see writeGatesClause}), the bridge's value-bounds
 * clause ({@see bridgeBoundsClause}) and the over-long-name refusal ({@see overLongName}). What
 * a refused WRITE means is still the caller's, because it is a property of that write (a create
 * that did not happen, a correction whose card vanished between the check and the PATCH) — but
 * the enumeration of WHAT COULD HAVE REFUSED IT is not, because that is a property of kanban.
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
     * ⭐ THE CAUSE IS A PROPERTY OF THE ROUTE, NOT OF THE STATUS ALONE — {@see BoardReadRoute}
     * carries which, and its cases carry the kanban source each claim is read from. A 401 is
     * route-independent (the token was not accepted at the door at all), but 403 and 404 are
     * not: a card SEARCH is floored to the caller's member boards and answers zero rows for
     * the rest, so membership cannot 403 there, while a BOARD-SCOPED read authorizes the board
     * itself, so membership is exactly what 403s. Stating either route's cause on the other
     * rules the true one out BY NAME, which is worse than saying nothing: the operator audits
     * the thing that is fine and stops.
     *
     * @param  401|403|404  $status
     */
    public static function readCause(BoardReadRoute $route, int $status): string
    {
        if ($status === 401) {
            return "the bridge's writeback token was not accepted at all — it has been revoked, rotated or replaced with a value the board does not know";
        }

        return match ($route) {
            BoardReadRoute::Search => match ($status) {
                403 => "the bridge's writeback token was recognised but not permitted to READ — kanban gates the API on per-token abilities, and this one lacks `read` (on a card SEARCH board membership does NOT produce a 403: kanban floors the query to the caller's own boards and answers zero rows instead)",
                404 => 'the board answered 404 for the card search itself, which is an API-surface fault rather than a missing card',
            },
            BoardReadRoute::BoardScoped => match ($status) {
                403 => "the board refused the read, and this route is authorized by TWO independent gates that both need auditing: the writeback token's abilities (kanban gates the API per token and a GET needs `read`), and the writeback USER's membership of that board — a board-scoped read authorizes the board itself, so a writeback user never added to it, or removed from it, is refused here (unlike a card search, which answers zero rows instead). A 403 cannot say which of the two refused",
                404 => 'the board answered 404 for the BOARD ITSELF — the configured id does not resolve to a board this route can see: no board carries it, or the board is in the trash (a trashed board is not resolved on this route). A missing API surface is the other, less likely candidate',
            },
        };
    }

    /**
     * The shared refusal for a read the board refused permanently. $what names what the bridge
     * was trying to read and $consequence what the call did NOT do — the two halves a seat needs
     * that differ per tool; everything else, including the INSTALL-fault framing and the
     * do-not-retry instruction, is one sentence for the whole door.
     *
     * ⛔ $route IS NOT DECORATION — see {@see readCause}. A caller whose `try` block spans both
     * route classes cannot name one truthfully, so it must be split before it calls this.
     *
     * @param  401|403|404  $status
     */
    public static function readRefusal(string $tool, BoardReadRoute $route, int $status, string $what, string $consequence): ToolRefusalException
    {
        return new ToolRefusalException("{$tool}: the bridge could not read {$what} (the board answered {$status}) — {$consequence}. This is an INSTALL fault, not something your arguments can fix: ".self::readCause($route, $status).'. Retrying will not change it; report it to your operator.');
    }

    /**
     * The gates that can answer 403 on a board WRITE, enumerated for the operator — the
     * write-side counterpart of {@see readCause}, and single-sourced for the same reason:
     * DL-326 wrote this enumeration out longhand inside `board_correct_card`, DL-339 copied
     * the shape onto `board_create_card`, and the second copy inherited the first's omission
     * (kanban's board write gate) with nothing red. A gate this clause does not name is a gate
     * the operator does not audit, so the enumeration gets ONE site.
     *
     * $verb and $rolePermission are what genuinely differ per write (a POST needs `task.create`,
     * a PATCH `task.update`); $roleNote carries any per-tool nuance about the ROLE gate.
     *
     * ⚠ THE THIRD GATE IS THE ONE NEITHER TOOL NAMED: kanban's `BoardWriteGate` denies every
     * write to an ARCHIVED or trashed board with a 403 whatever the token and the role allow
     * (kanban DL-062 → `TaskPolicy::create`/`update` → `BoardWriteGate::check`, and kanban's
     * own `@response 403 scenario="board is archived (write-gate denial …)"` on that route).
     * An operator whose board was archived audits abilities and role, finds both correct, and
     * never learns — while the fix is one click. Source-read from the kanban tree, declared for
     * a consumer in `docs/kanban-integration-contract.md` § 2; this repo cannot check it.
     */
    public static function writeGatesClause(string $verb, string $rolePermission, string $roleNote = ''): string
    {
        return "THREE independent gates answer 403 here and EVERY ONE needs auditing: the token's abilities (a {$verb} needs `write`), the writeback user's board role, which needs `{$rolePermission}` (a user that is not a member of the board holds no role at all){$roleNote}, and the board's own WRITE GATE — an archived or trashed board refuses every write with a 403 however the token and the role are set, and unarchiving it is the whole fix. A 403 cannot say which of the three refused.";
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
