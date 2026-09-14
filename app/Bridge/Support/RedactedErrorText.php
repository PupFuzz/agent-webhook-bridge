<?php

namespace App\Bridge\Support;

use Closure;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * THE ONE ROUTE FROM A CAUGHT `Throwable` TO TEXT A LOG, A DURABLE RECORD OR AN OPERATOR MAY
 * SEE (card#9486): redacted by {@see SecretScrubber}, and for a response body, bounded AFTER
 * the redaction.
 *
 * ⛔ A `RequestException`'s `getMessage()` IS NEVER THE INPUT. Laravel's constructor bakes a
 * body summary into the message already cut at `RequestException::$truncateAt`. A cut inside an
 * echoed URL's userinfo leaves a password with no `@` after it, and a cut inside a JSON value
 * leaves a token with no closing quote — shapes no reader of the finished string can recognise,
 * so "scrub `getMessage()`" printed the head of a credential (measured on card#9278, review R2).
 * The text is rebuilt from the status and the FULL body instead, redacted, then bounded.
 *
 * ⛔ REDACT, THEN BOUND — THE ORDER IS THE CONTRACT. It was {@see RefusalContext::from()}'s
 * before it was hoisted here, and {@see self::body()} is still what that method calls, so the
 * log context of a refusal and the text of a caught exception cannot disagree about it.
 *
 * ⚠ WHAT IT DOES NOT DO:
 *  - It does not ESCAPE for a terminal. A caller printing to one still wraps the result in
 *    {@see UntrustedText::forOperator()}; logs and durable records are different sinks.
 *  - It cannot recover a message that ALREADY EMBEDS a truncated one — an exception thrown as
 *    `new X('…'.$requestException->getMessage())` carries the cut in its own text, and this
 *    class sees only that text. Such a wrapper is where the fix belongs.
 *  - Its redaction is {@see SecretScrubber::text()}'s, with every bound that class states.
 *
 * `Tests\Feature\Support\ExceptionMessageRedactionCensusTest` reds on a `getMessage()` handed
 * straight to a redactor or an escape anywhere in `app/`.
 */
final class RedactedErrorText
{
    private const MAX_BODY = 500;

    /**
     * @param  (Closure(string): string)|null  $redactFirst  a redaction only the CALLER can make —
     *                                                       a value it holds and knows is sensitive — run on the unbounded text
     *                                                       before the scrubber, so the bound can never cut a value it must match
     */
    public static function of(Throwable $e, ?Closure $redactFirst = null): string
    {
        $redactFirst ??= static fn (string $text): string => $text;

        if (! $e instanceof RequestException) {
            return SecretScrubber::text($redactFirst($e->getMessage()));
        }

        $status = "HTTP request returned status code {$e->response->status()}";
        $body = self::body($redactFirst($e->response->body()));

        // Guzzle's `Message::bodySummary()` rule, which the message this replaces was gated on:
        // a body with anything outside printable text and `\n\r\t` is omitted, not shown. Keeping
        // it keeps a binary or control-byte body off every sink that used to receive the summary.
        if ($body === '' || preg_match('/[^\pL\pM\pN\pP\pS\pZ\n\r\t]/u', $body) !== 0) {
            return $status;
        }

        return "{$status}: {$body}";
    }

    /** A response body, redacted in full and then bounded. */
    public static function body(string $body): string
    {
        $body = SecretScrubber::text($body);
        if (mb_strlen($body) <= self::MAX_BODY) {
            return $body;
        }

        return mb_substr($body, 0, self::MAX_BODY).'…(truncated)';
    }
}
