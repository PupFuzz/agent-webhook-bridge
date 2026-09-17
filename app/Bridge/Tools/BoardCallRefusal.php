<?php

namespace App\Bridge\Tools;

use App\Bridge\Exceptions\ToolRefusalException;
use App\Bridge\Support\RefusalContext;
use App\Bridge\Support\SecretScrubber;
use App\Bridge\Support\UntrustedText;
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
 * A 422 on a write is the board refusing a VALUE the write carried — deterministic, and the
 * backstop that makes {@see KanbanFieldLimits}'s mirrored caps safe to go stale. (Not necessarily
 * kanban's validator: a proxy can answer 422, so no refusal names one as the author.) Since
 * DL-384 that refusal relays the board's own reason ({@see boardReason}), so a stale cap costs
 * the seat nothing it cannot read. A read
 * sends no such value, so a 422 there is a malformed-query/API-surface fault the bridge cannot
 * name to the seat, and it stays retryable. This is the split {@see RefusalContext} already makes
 * between `readReason()` and `writeReason()` for the writeback's alert vocabulary, for the same
 * reason: one status map over two different operator hypotheses states neither.
 *
 * ⚠ THE CALLER STILL OWNS ITS OWN LOG LINE AND ITS OWN SENTENCE. What is single-sourced here
 * is the DECISION (permanent vs retryable) and the shared VOCABULARY — the read refusal's whole
 * message ({@see readRefusal}), the per-route-per-status cause ({@see readCause}), the gates a
 * write 403 must send an operator to audit ({@see writeGatesClause}), the bridge's value-bounds
 * clause ({@see bridgeBoundsClause}), the board's own 422 reason ({@see boardReason}) and the
 * over-long-name refusal ({@see overLongName}). What
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
     * What the bridge's own length checks ESTABLISHED, stated to the seat on a 422 the BOARD
     * answered to a card write — and nothing more (DL-384).
     *
     * ⛔ A WRITE THAT REACHED THE BOARD PASSED THOSE CHECKS, so this clause says they passed and
     * never sends the seat to shorten a field. The text it replaced named the two mirrored caps
     * beside an instruction to shorten `title`, `description` or tags, and a seat whose values
     * were already inside both did exactly that (rt#484). What the board DID refuse is
     * {@see boardReason}'s to relay.
     *
     * ⛔ "EACH TAG YOU PASSED", NOT "EACH TAG", AND NO CONCLUSION DRAWN FROM THE PASS. The check runs
     * on the caller's tags only. A tag the bridge writes itself (`created-by:<agent>`, bounded only
     * by the configured agent name, and on a correction every tag it keeps from the card) is not
     * bounded here. So a pass does not establish that the board refused something other than these
     * bounds, and the clause names the unchecked tags instead of saying it did. The `idem:` stamp is
     * not among them: {@see BoardCreateCardTool::idemTag} caps the key before any request (card#9588).
     *
     * @param  string  $nameArgument  what the calling tool's own argument for kanban's `name` is called
     */
    public static function bridgeBoundsClause(string $nameArgument): string
    {
        return "The bridge's own length checks passed before it sent: any `{$nameArgument}` you sent is within ".KanbanFieldLimits::NAME_MAX.' characters and each tag you passed within '.KanbanFieldLimits::TAG_MAX.'. Those checks do not cover a tag the bridge writes itself: its `created-by:` stamp, and on a correction the tags it keeps from the card.';
    }

    /** A 422 body over this many bytes is sized, never parsed — a validator's answer is a small fraction of it. */
    public const RELAY_MAX_BODY_BYTES = 65536;

    /** The most field errors {@see boardReason} relays; the remainder is counted, not shown. */
    public const RELAY_MAX_ENTRIES = 5;

    /** The most characters of relayed entries; the first entry is shown whatever its size, each span being bounded already. */
    public const RELAY_MAX_CHARS = 1000;

    private const RELAY_MAX_DEPTH = 32;

    private const RELAY_NO_REASON = "The board's 422 body named no field and carried no message, so it gave no reason to relay.";

    /**
     * THE BOARD'S OWN REASON FOR A 422, relayed to the seat: each field the 422 body names
     * and its message, redacted and bounded (DL-384). ONE primitive for every write on this door,
     * so every tool's 422 relays the same way.
     *
     * ⛔ THE BODY IS UNTRUSTED — an upstream artefact, possibly not kanban's at all (a proxy can
     * answer 422) — so every shape has a named answer and none of them throws:
     *  - an empty body, a body over {@see RELAY_MAX_BODY_BYTES} (sized, not parsed) and a body that
     *    is not JSON each get a sentence saying so, and NOTHING of the body is relayed;
     *  - `errors` is walked as whatever it is: a field → list of messages (Laravel's shape), a
     *    nested object (flattened to a dotted path), a bare string (relayed without a field).
     *    A value that is not a string is not a message and is skipped;
     *  - with no usable `errors`, a string `message` is relayed, labelled as naming no field.
     *    Beside field errors it is not: Laravel's `message` summarises the first of them.
     *
     * ⛔ DECODE, THEN REDACT EACH ENTRY, THEN ESCAPE AND BOUND IT. Redacting the RAW body first was
     * the obvious order and is the wrong one: kanban's JSON escapes `/` as `\/`, and a credential
     * whose alphabet includes `/` is cut at the backslash by {@see SecretScrubber}'s run, leaving its
     * tail to be decoded into the relay. A field name is scrubbed as the bare text it is. A message
     * is scrubbed TWICE, and neither pass alone is enough: first as the bare message, so JSON
     * embedded in it is seen as JSON (inside the pair below its quotes are escaped, and the
     * scrubber's JSON-key rule cannot match it); then as the one-pair JSON object
     * `{"<path>":"<message>"}`, so the key rule also sees the key a flattened entry would otherwise
     * lose (`payload.api_token` → `[REDACTED]`). The bare pass runs first, so the relay is never
     * weaker than {@see SecretScrubber::text()} on the message; an entry that does not survive the
     * round trip is shown as `[REDACTED]`. Then {@see UntrustedText::forOperator()} collapses
     * whitespace, escapes control and bidi characters and bounds the span, so a message cannot
     * forge a second line of the refusal.
     *
     * ⚠ WHAT IT DOES NOT DO: the redaction is at least {@see SecretScrubber::text()}'s on each field
     * name and message, with every bound that class states; the bounds are on SIZE, not on meaning —
     * a message can still say anything a single escaped line can.
     */
    public static function boardReason(RequestException $e): string
    {
        $raw = $e->response->body();
        if (BoardToolArgs::trimmed($raw) === '') {
            return "The board's 422 carried no body, so it gave no reason to relay.";
        }
        $bytes = strlen($raw);
        if ($bytes > self::RELAY_MAX_BODY_BYTES) {
            return "The board's 422 body is {$bytes} bytes, over the ".self::RELAY_MAX_BODY_BYTES.'-byte bound the bridge relays from, so none of it is shown.';
        }
        $body = json_decode($raw, true, self::RELAY_MAX_DEPTH);
        if (! is_array($body)) {
            return "The board's 422 body is not JSON the bridge can read ({$bytes} bytes), so none of it is relayed.";
        }

        $entries = self::errorEntries($body['errors'] ?? null, '');
        if ($entries === []) {
            $message = $body['message'] ?? null;

            return is_string($message) && BoardToolArgs::trimmed($message) !== ''
                ? 'The board named no field; its own message (redacted and bounded by the bridge) is: '.self::relayedSpan('message', $message)
                : self::RELAY_NO_REASON;
        }

        $shown = [];
        $used = 0;
        foreach ($entries as [$path, $message]) {
            $entry = ($path === '' ? '' : '`'.UntrustedText::forOperator(SecretScrubber::text($path)).'`: ').self::relayedSpan($path, $message);
            $size = mb_strlen($entry);
            if (count($shown) === self::RELAY_MAX_ENTRIES || ($shown !== [] && $used + $size > self::RELAY_MAX_CHARS)) {
                break;
            }
            $shown[] = $entry;
            $used += $size;
        }
        $omitted = count($entries) - count($shown);

        return "The board's own reason (its text, redacted and bounded by the bridge): ".implode(' | ', $shown).($omitted > 0 ? " [{$omitted} MORE NOT SHOWN]" : '');
    }

    /**
     * Every (path, message) pair under a 422 body's `errors`, in body order.
     *
     * @return list<array{string, string}>
     */
    private static function errorEntries(mixed $value, string $path): array
    {
        if (is_string($value)) {
            return BoardToolArgs::trimmed($value) === '' ? [] : [[$path, $value]];
        }
        if (! is_array($value)) {
            return [];
        }

        $entries = [];
        $isList = array_is_list($value);
        foreach ($value as $key => $child) {
            $childPath = $isList ? $path : ($path === '' ? (string) $key : "{$path}.{$key}");
            array_push($entries, ...self::errorEntries($child, $childPath));
        }

        return $entries;
    }

    /** One message, redacted bare and then with its own key in view, then escaped and bounded — see {@see boardReason}. */
    private static function relayedSpan(string $path, string $message): string
    {
        $pair = json_encode([$path => SecretScrubber::text($message)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_FORCE_OBJECT);
        $redacted = is_string($pair) ? json_decode(SecretScrubber::text($pair), true) : null;
        $value = is_array($redacted) ? ($redacted[$path] ?? null) : null;

        return is_string($value) ? UntrustedText::forOperator($value) : '[REDACTED]';
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
