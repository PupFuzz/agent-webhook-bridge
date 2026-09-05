<?php

namespace App\Bridge\Support;

/**
 * THE ONE CREDENTIAL REDACTOR IN THIS APP (card#8433). Every surface that puts text the
 * bridge did not compose — or a config value the bridge did not choose — into a durable
 * store or an operator-facing stream passes it through here first.
 *
 * ⛔ WHY IT IS ITS OWN CLASS. The regexes lived on {@see RefusalContext}, whose documented
 * subject is *"the shared vocabulary for a 4xx refusal"*. A second need (an exception
 * message stored on a `scheduled_jobs` row) had no business reaching into a
 * refusal-vocabulary class for its scrubber, and the next reader who agreed with that would
 * have written a second redactor — which is the defect, not the style (canon #5). One home,
 * correctly named, so the next need finds it instead of minting a sibling.
 *
 * ⚑ TWO ENTRY POINTS OVER ONE SHARED POSITIONAL RULE, and the split is about what the
 * CALLER KNOWS, not about convenience.
 *  - {@see self::text()} works on TEXT SOMEONE ELSE COMPOSED — an upstream error body, a
 *    third-party `App\Bridge\Scheduling\JobHandler`'s exception message. It matches
 *    credential SHAPES (a sensitive key's value, an auth scheme, a vendor prefix), and it
 *    additionally strips the userinfo/query/fragment of any `http(s)://…` run it finds —
 *    a JSON-escaped `https:\/\/…` included, which is the form the one body this app scrubs
 *    most actually arrives in — because those components are credential-bearing by POSITION
 *    whatever they are named.
 *  - {@see self::url()} works on a URL VALUE WE ARE ABOUT TO INTERPOLATE OURSELVES. It puts
 *    the same positional rule over the WHOLE value — including one with NO SCHEME, which
 *    the embedded-URL pass cannot recognise and which is exactly what
 *    {@see UrlValidator::httpUrl()} quotes on the branch that refuses the scheme.
 *
 * ⛔ THE POSITIONAL RULE IS WHY LEG B REDACTS AT THE INTERPOLATION RATHER THAN AT A READER
 * OF THE FINISHED MESSAGE (canon #20). Shape-matching alone cannot see `?k=…`; it takes
 * knowing the substring is a URL at all. Where WE hold the value we know that for certain,
 * so it is said there. {@see self::text()}'s embedded-URL pass is the best a reader of
 * somebody else's finished string can do — never a reason to stop doing the first.
 *
 * ⚠ OVER-REDACTION IS DELIBERATE, inherited from the rule this class was hoisted out of: a
 * benign field whose key merely contains a sensitive word loses its value too, and a URL's
 * query string is dropped whether or not it held anything. A leaked credential is the
 * failure mode designed against; a redacted-but-benign field is not. Three consequences of
 * that choice, stated so no caller reads the output as minimal:
 *  - The userinfo binds at the LAST at-sign before whitespace, so one sitting in a PATH or a
 *    QUERY is taken for a userinfo terminator and the scheme aside, everything in front of
 *    it — host and path included — is replaced. The alternative, stopping the userinfo at
 *    the first `/`, `?` or `#`, cannot reach an at-sign behind any of those three, and `/`
 *    is in the base64 alphabet while `?` and `#` are ordinary generated-password
 *    characters, so that bound echoed real credentials. Both directions are worked in
 *    {@see self::stripCredentialComponents()} and pinned in `Tests\Unit\Support\SecretScrubberTest`.
 *  - The redaction of a query/fragment runs to the next WHITESPACE, so non-whitespace text
 *    following a redacted URL is dropped with it — a comma-joined second URL, a closing
 *    bracket, a trailing sentence period (see {@see self::text()} for why it is not
 *    narrowed).
 *  - A `[REDACTED]` in the output therefore marks that SOMETHING credential-shaped or
 *    credential-positioned was there; it never bounds how much of the surrounding text went.
 *
 * ⚠ IT REDACTS AT THE WRITE, SO IT SAYS NOTHING ABOUT ALREADY-STORED TEXT. Rows and cache
 * markers written before this class shipped still hold whatever the raw text was, and the
 * readers still print it verbatim (`bridge:jobs`, `bridge:check`). No migration rewrites
 * them: the `bridge:{jobs,retention,standup}:last-error` markers age out at their 30-day
 * TTL floor, and a `scheduled_jobs.last_error` / `.last_summary` row is overwritten the next
 * time that job runs. Stated in DL-344's bounds as a known limit rather than left to be
 * assumed clean.
 *
 * ⚠ WHAT IT DOES NOT DO, stated so no caller reads more into it. It does not redact a
 * secret carried in a URL's PATH (`https://host/hooks/T0/B0/<secret>`), because a path is
 * indistinguishable from the route an operator needs to see in the very message that quotes
 * it, and no shipped outbound call site of this app uses one (enumerated on card#8433:
 * `KanbanHttpClient`, `GitHubReadClient`, `ChannelPushTransport`, `BoardToolsHttpProbeCheck`).
 * The vendor-prefix rule below still catches a GitHub token sitting in one. This is the same
 * line `GuzzleHttp\Psr7\Utils::redactUriForMessage()` draws for every URI this app puts on
 * the wire, so the two agree rather than disagreeing quietly.
 */
final class SecretScrubber
{
    /**
     * Key- and scheme-name fragments whose adjacent value is a probable credential.
     * `[_-]?` tolerates the api_key / api-key / apikey spellings.
     */
    private const SENSITIVE = 'authorization|bearer|token|secret|passwd|password|api[_-]?key|access[_-]?key|access[_-]?token|refresh[_-]?token|client[_-]?secret|private[_-]?key|credential|x-api-key';

    private const REDACTED = '[REDACTED]';

    /**
     * Redact credential-adjacent values that arbitrary text — an upstream error body, an
     * echoed request inside it, a third-party handler's exception message — could carry:
     * the credential-bearing components of any embedded URL, JSON values of a sensitive
     * key, query/form `key=value` pairs, `Bearer`/`Basic`/`token` auth-scheme values, and
     * unambiguous vendor token prefixes.
     */
    public static function text(string $text): string
    {
        // Embedded URLs first, so a query value can never reach the shape rules below as a
        // partial match.
        //
        // ⚠ THE RUN ENDS AT WHITESPACE, SO EVERYTHING FROM THE `?`/`#` TO THE NEXT SPACE IS
        // DROPPED WITH THE QUERY — not just a trailing sentence period. Following text that
        // is not whitespace-separated goes too: a comma-joined second URL
        // (`…/?k=1,https://b.example/?j=2` → `…/?[REDACTED]`) and a closing bracket
        // (`(https://x/?k=1)` → `(https://x/?[REDACTED]`). ⛔ NOT NARROWED ON PURPOSE: every
        // way to end the run earlier (stop at `,` or `)`, trim trailing punctuation) ends it
        // INSIDE a query that legitimately contains that character, and the tail then
        // survives unredacted — trading a diagnostic loss for a credential leak, which is
        // the wrong side to be wrong on. The bound is stated on the class and in DL-344.
        //
        // `\/` is admitted into the scheme and the run because a JSON body escapes forward
        // slashes: Symfony's `JsonResponse::DEFAULT_ENCODING_OPTIONS` does NOT set
        // `JSON_UNESCAPED_SLASHES`, so kanban's 4xx bodies — the only input
        // {@see RefusalContext::from()} ever has — carry `https:\/\/…`. Keyed on `://`
        // alone this pass was inert on exactly the surface it was written for.
        $text = (string) preg_replace_callback(
            '#\bhttps?:(?:\\\\?/){2}(?:\\\\/|[^\s<>"\'\\\\])+#i',
            static fn (array $m): string => self::stripCredentialComponents($m[0]),
            $text,
        );

        // JSON string value of any key CONTAINING a sensitive word: "api_token":"…" → "api_token":"[REDACTED]"
        $text = (string) preg_replace(
            '/("[^"]*(?:'.self::SENSITIVE.')[^"]*"\s*:\s*)"(?:[^"\\\\]|\\\\.)*"/i',
            '$1"'.self::REDACTED.'"',
            $text,
        );

        // query / form-encoded: token=abc&… → token=[REDACTED]&…
        $text = (string) preg_replace(
            '/\b((?:'.self::SENSITIVE.')=)[^&\s"]+/i',
            '$1'.self::REDACTED,
            $text,
        );

        // HTTP `Bearer`/`Basic` auth schemes echoed as raw text (e.g. an echoed
        // Authorization header). These keywords are never followed by a prose word in
        // an error body, so redact the value at ANY length — a short-but-real token
        // must not slip through.
        $text = (string) preg_replace(
            '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]+/i',
            '$1 '.self::REDACTED,
            $text,
        );

        // GitHub's `token <pat>` scheme. Unlike Bearer/Basic, bare `token` DOES occur
        // in prose ("token expired", "token cannot write …"), so require a
        // credential-LONG value (>=16 of the token charset) to avoid mangling the very
        // reason the body exists to surface. Keyed/short credentials stay covered by
        // the JSON/query/Bearer rules.
        $text = (string) preg_replace(
            '/\btoken\s+[A-Za-z0-9._~+\/=-]{16,}/i',
            'token '.self::REDACTED,
            $text,
        );

        // Defense-in-depth: unambiguous secret PREFIXES redacted wherever they appear,
        // even un-keyed / in an unexpected body shape — these tokens never occur in
        // prose. Covers GitHub PATs/OAuth/app tokens (`ghp_`/`gho_`/`ghu_`/`ghs_`/`ghr_`)
        // and fine-grained PATs (`github_pat_`).
        return (string) preg_replace(
            '/\b(?:gh[opusr]_[A-Za-z0-9]+|github_pat_[A-Za-z0-9_]+)/',
            self::REDACTED,
            $text,
        );
    }

    /**
     * Make a URL VALUE safe to interpolate into a message we are composing.
     *
     * ⚑ SCHEME, HOST, PORT AND PATH SURVIVE ON PURPOSE. Every caller is telling the
     * operator that THIS value is malformed — "no host component", "must use https",
     * "contains whitespace; check for paste errors" — and a message that quotes
     * `[REDACTED]` back at them names no value they can find in their own config. What is
     * removed is exactly the part no such verdict is ever ABOUT.
     */
    public static function url(string $value): string
    {
        return self::text(self::stripCredentialComponents($value));
    }

    /**
     * Drop a URL's userinfo, query and fragment — the three components a credential lives
     * in by position — keeping scheme, host, port and path.
     *
     * ⚠ IT IS LEXICAL, NOT `parse_url()`-BASED, AND THAT IS LOAD-BEARING. The callers that
     * need it most are validating a value that does NOT parse: `parse_url()` returns false
     * on a whitespace-bearing URL, and a redactor that gave up there would echo the raw
     * value on precisely the branch a paste error lands on.
     */
    private static function stripCredentialComponents(string $value): string
    {
        // userinfo — everything up to the LAST `@` before whitespace.
        //
        // ⛔ THE BINDING IS THE LAST `@`, NOT THE FIRST DELIMITER, AND THAT IS THE WHOLE
        // POINT. A userinfo bounded by `[^/?#]*` cannot reach an `@` sitting behind a `/`,
        // `?` or `#` — and `/` is in the base64 alphabet while `?` and `#` are ordinary
        // generated-password characters, so `svc:pa/ss@host` echoed the entire credential
        // and `svc:pa?ss@host` echoed its head followed by `[REDACTED]`, which reads as
        // redacted to an operator and to any presence assertion. The scheme is optional;
        // see {@see self::url()} for why.
        //
        // ⚠ WHAT THAT BINDING COSTS, worked: `https://h/@you/x` → `https://***@you/x`, and
        // `https://board.example/api?to=a@b.example` → `https://***@b.example`. An at-sign
        // outside a userinfo takes the host and path with it. Over-redaction is the side
        // this class declares it errs on, and the alternative bound leaked.
        $value = (string) preg_replace(
            '#^([A-Za-z][A-Za-z0-9+.-]*:(?:\\\\?/){2})?[^\s]*@#',
            '${1}***@',
            $value,
        );

        // query + fragment, from the first delimiter on. The delimiter is kept so the
        // result says WHICH component was dropped rather than implying it was never there.
        $cut = strcspn($value, '?#');
        if ($cut < strlen($value)) {
            $value = substr($value, 0, $cut).$value[$cut].self::REDACTED;
        }

        return $value;
    }
}
