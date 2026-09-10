<?php

namespace App\Bridge\Provision;

use App\Bridge\Support\KanbanHttpClient;
use App\Bridge\Support\SecretScrubber;
use Illuminate\Http\Client\ConnectionException;

/**
 * Asks kanban `GET <api_base_url>/users/current.json` who a token is (DL-369). The single
 * home for that question — the id it answers is what `writeback.json`'s `identity_id`
 * declares, and the same call is what compares a SECOND token against it.
 *
 * ⛔ THE BASE URL ALREADY ENDS IN `/api/v3`. {@see self::endpoint()} is the only place the
 * URL is built, and the message on the fallback path prints THAT value rather than
 * re-spelling it, so the operator's by-hand retry cannot be handed a different URL from the
 * one this actually requested. Appending a second `/api/v3` answers 404, and a 404 reads as
 * a wrong endpoint rather than a doubled path — measured on the reference install.
 *
 * ⛔ THE TOKEN NEVER REACHES ARGV. It is read and presented inside this process, as a bearer
 * header on a request this process makes (the DL-322 class this repo has already fixed once
 * in its own smoke test). No subprocess, no shell, nothing in `/proc/<pid>/cmdline`.
 *
 * ⛔ THE RESPONSE BODY IS SENSITIVE AS A CLASS — whatever this API version returns, not a
 * list of which keys are (an enumeration written for this endpoint disagreed with the
 * endpoint within days, and a stale list reads as permission to print everything it forgot
 * to name). Exactly two values leave this class: `.data.id` and `.data.name`. Nothing else
 * from the body reaches a message, a log or a return value — which is also why an upstream
 * exception carrying a response body is NOT caught here and turned into a printable string:
 * only {@see ConnectionException}, whose message is a transport diagnosis, is.
 *
 * ⚠ WHAT THE NAME GUARD DOES NOT CLOSE, stated because refusing control characters looks
 * like it settles the question: a name built from ORDINARY characters that merely READS like
 * another account's — a homoglyph, a lookalike spelling, a trailing-space variant — is
 * accepted and is indistinguishable from the real one on this surface. The guard is about
 * whether the name renders AS ITSELF, never about whether it is the name you expected;
 * that judgement is the operator's, which is why the offer is a confirmation and not a check.
 * Bidi-override and zero-width characters (`\p{Cf}`) are NOT refused — they are a real
 * reordering vector and deliberately out of scope here, because they were not measured and
 * a guard written from a guess is one nobody can size.
 *
 * ⚠ The body is decoded HERE rather than through the client's `json()` helper: that helper
 * reads a process-global decoding-flags setting, so a JSON_THROW_ON_ERROR set anywhere else
 * in the app would convert this fail-soft path into a thrown exception inside setup.
 */
final class KanbanIdentityResolver
{
    /**
     * The one spelling of the endpoint. `rtrim` — NOT a `/api/v3` of our own — because the
     * configured base already carries the API prefix.
     */
    public static function endpoint(string $baseUrl): string
    {
        return rtrim($baseUrl, '/').'/users/current.json';
    }

    public function resolve(string $baseUrl, string $token): KanbanIdentityResolution
    {
        try {
            $response = KanbanHttpClient::configured($baseUrl, $token)->get(self::endpoint($baseUrl));
        } catch (ConnectionException $e) {
            return KanbanIdentityResolution::failed(
                'the API did not answer ('.SecretScrubber::text($e->getMessage()).')'
            );
        }

        if (! $response->successful()) {
            return KanbanIdentityResolution::failed(match ($response->status()) {
                401 => 'the API rejected that token (401) — it is not a token this board accepts',
                403 => 'the API refused that token (403) — it authenticates, but not for its own user record',
                default => 'the API answered HTTP '.$response->status(),
            });
        }

        $body = json_decode($response->body(), true);
        if (! is_array($body)) {
            return KanbanIdentityResolution::failed('the response body was not JSON');
        }

        // ⛔ `.data.id`, never a bare `.id`: the user record sits inside a `data` envelope, so
        // the obvious spelling reads null — which looks like "this endpoint does not answer
        // that question" when it just did.
        $data = $body['data'] ?? null;
        $id = is_array($data) ? ($data['id'] ?? null) : null;
        if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
            return KanbanIdentityResolution::failed('the response carried no numeric `.data.id`');
        }

        // No `is_array($data)` here: reaching this line means `.data.id` resolved, which is
        // only possible through an array `data` — a second guard would be unreachable code.
        $name = $data['name'] ?? null;
        $name = is_string($name) ? trim($name) : '';
        if ($name === '') {
            return KanbanIdentityResolution::failed(
                'the response carried no display name at `.data.name` — the id is not offered without one to recognise it by'
            );
        }

        // ⛔ AN UNRENDERABLE NAME IS REFUSED HERE, NOT ESCAPED AT THE RENDER, because the
        // render's escape is `OutputFormatter::escape()` — which escapes `<` and `>` and
        // NOTHING ELSE. A name carrying a newline draws EXTRA OPERATOR-FACING LINES (measured:
        // a forged success line, byte-identical in shape to the real one, above an
        // "ignore the warning below"); a name carrying ESC rewrites the line already printed.
        // This is the one surface whose whole purpose is verbatim human recognition, and the
        // account it names is precisely the account the operator is being asked to distrust —
        // so a name that cannot be shown as itself resolves to NO OFFER, which this method
        // already has a home for. Refusing at the resolver rather than at one call site means
        // every future consumer of a {@see KanbanIdentity} inherits the guard (canon #5).
        // `!== 0` covers BOTH outcomes: 1 is a control character, false is a name that is not
        // valid UTF-8 — also unrenderable, and also not something to print to find out.
        if (preg_match('/\p{Cc}/u', $name) !== 0) {
            return KanbanIdentityResolution::failed(
                'the display name at `.data.name` carries a control character (or is not valid UTF-8), so it cannot be shown '
                .'verbatim — and a name you cannot read is not one you can check the account against'
            );
        }

        return KanbanIdentityResolution::resolved(new KanbanIdentity((int) $id, $name));
    }
}
