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
 *  - The userinfo binds at the LAST at-sign, WHATEVER LIES BEFORE IT, so one sitting in a
 *    PATH or a QUERY is taken for a userinfo terminator and the scheme aside, everything in
 *    front of it — host and path included — is replaced. Every narrower bound tried here has
 *    LEAKED: stopping at the first `/`, `?` or `#` cannot reach an at-sign behind any of
 *    those three, and `/` is in the base64 alphabet while `?` and `#` are ordinary
 *    generated-password characters; stopping at WHITESPACE cannot reach one behind a space,
 *    and a space is exactly what a paste error leaves in a userinfo — the leak card#9528
 *    records, on `UrlValidator`'s *check for paste errors* branch. Every direction is worked
 *    in {@see self::stripCredentialComponents()} and pinned in `Tests\Unit\Support\SecretScrubberTest`.
 *  - ⛔ THAT BINDING IS A PROPERTY OF THE VALUE HANDED TO THE RULE, NOT OF WHAT {@see self::text()}
 *    FINDS, and the difference is a LIVE BOUND rather than a detail: `text()`'s embedded-URL
 *    run itself still ends at WHITESPACE, so in somebody else's finished string an `@` sitting
 *    behind a space is never handed to the rule at all —
 *    `text('url is https://svc:pw <secret>@host/x and more')` comes back VERBATIM. The run is
 *    not widened because crossing whitespace would swallow the rest of every message that
 *    quotes a URL. The shape is closed where the value is OURS instead: {@see self::url()}
 *    puts the rule over the WHOLE value, and the `bridge:check` / `bridge:provision` config
 *    doors refuse such a value outright (`UrlValidator::configDoorHttpUrl()`).
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

    /**
     * What a key may carry AROUND a {@see self::SENSITIVE} word and still be one key: anything
     * up to the previous REAL delimiter, bound by EXCLUSION rather than by an alphabet.
     *
     * ⛔ WHY THE WORD IS NOT MATCHED ON ITS OWN, BOUNDED BY `\b` (card#9528). `\b` before the
     * alternation cannot fire inside `api_token=`, because `_` is a word character — so
     * `api_token=<secret>` went out verbatim, while `my-password=<secret>` was caught. That made
     * the rule's reach a function of which SPELLINGS the list happened to enumerate, and the
     * list can never be complete: a key is whatever the far end named its field. Matching the
     * word ANYWHERE IN THE KEY is the rule the JSON leg has always used.
     *
     * ⛔ AND WHY THE KEY IS NOT SPELLED AS AN ALPHABET EITHER — the same defect one layer in,
     * caught in this card's R1 review. `[A-Za-z0-9._-]*` moved the dependence from `SENSITIVE`'s
     * word list to a CHARACTER list, and missed the bracketed nested form every PHP, Rails and
     * Laravel query uses: `params[api_token]=` and its percent-encoded `user%5Btoken%5D=` both
     * went out verbatim. The delimiters below are the ones that genuinely END a key — the pair
     * separator, the start of a query, the `=` that splits the pair, whitespace, and the quote
     * that ends a JSON string — which is the same bound this rule's own VALUE run has always
     * used (`[^&\s"]+`), now applied to both halves of the pair instead of one.
     */
    private const KEY_CHARS = '[^&?=\s"]*';

    /**
     * What separates an auth scheme from its value: one or more characters that are NOT a
     * VISIBLE ASCII character. Bound by exclusion, so no separator has to be enumerated.
     *
     * ⛔ THE ENUMERATION IS THE DEFECT, NOT THE PARTICULAR LIST (card#9528 comment 5426 and its
     * R1 review). This started as `\s`, which is ASCII-only without the `u` modifier, so
     * `Bearer<U+00A0><token>` went out unredacted while `UntrustedText::forOperator()` collapsed
     * the separator to a plain space — the token printed as though the redactor had looked at it
     * and passed it. Replacing `\s` with `\s` + the `Zs` table fixed U+00A0 and left U+2028,
     * U+2029, U+0085 and U+180E leaking the same way, because `Zs` is not the class the defect
     * belongs to: the class is *whatever the operator's surface renders as a space*, and no
     * Unicode category names it. So the rule stops asking WHICH character this is. A credential
     * separator is never a visible ASCII character, and every space — ASCII, Unicode, present
     * or future — is covered by that one sentence. `SecretScrubberTest` derives its population
     * from `UntrustedText::forOperator()`'s own rendering rather than from any table.
     *
     * ⛔ THE `u` MODIFIER IS STILL NOT USED, and now cannot be needed: on input that is not
     * valid UTF-8 `preg_replace` returns null, and this class casts to string, so one stray byte
     * anywhere in a foreign body would silently empty the whole text. A byte-level exclusion has
     * no such failure mode and needs no well-formed input to be correct.
     *
     * ⚠ THE BOUND, STATED: a VISIBLE ASCII character between the scheme and its value — the
     * `:` of a hand-written `Bearer: <token>` — is not a separator here and is not treated as
     * one. It is not the wire form (RFC 7235 writes `auth-scheme 1*SP token68`), and admitting
     * it would make the rule eat ordinary prose after every `bearer.` in a sentence.
     */
    private const SCHEME_SEPARATOR = '[^\x21-\x7E]+';

    /**
     * ONE character of an auth-scheme's value: anything that is not a TRUE delimiter of it.
     *
     * ⛔ AN ALPHABET HERE IS THE SAME DEFECT AS AN ALPHABET ANYWHERE ELSE IN THIS CLASS, and
     * this constant is where the R1 review of card#9528 found it still standing. The run was
     * `[A-Za-z0-9._~+\/=|-]`, so it ended at the first character the list forgot — `:` `%` `!`
     * `'` `,` `;` `(` were all outside it — and `Basic user:<secret>` came back as
     * `Basic [REDACTED]:<secret>`: the DANGEROUS form, because the output CARRIES `[REDACTED]`
     * and an operator, a reviewer and any presence-of-`[REDACTED]` assertion all read the leak
     * as a redaction. A credential in an HTTP header cannot contain whitespace or a bare `"`;
     * anything else it can. So the run ends at those, and at nothing else — the same shape the
     * `key=value` rule's own value run and the `mzr_` prefix rule already used.
     *
     * ⛔ `\\.` ADMITS ANY BACKSLASH ESCAPE, and without it the run ended inside the secret
     * (comment 5425): the bodies this class most often reads are JSON, where a forward slash is
     * written `\/`, so `Bearer abc\/<secret>` redacted `abc` and printed the rest. The backslash
     * is excluded from the class for the same reason it is in the embedded-URL run — an
     * ambiguous alternation (a backslash matching either arm) is what makes a quantifier
     * backtrack exponentially.
     */
    private const SCHEME_VALUE = '(?:\\\\.|[^\s"\\\\])';

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
        // A BACKSLASH ESCAPE IS PART OF THE RUN, NOT THE END OF IT, because a JSON body escapes:
        // Symfony's `JsonResponse::DEFAULT_ENCODING_OPTIONS` does NOT set
        // `JSON_UNESCAPED_SLASHES`, so kanban's 4xx bodies — the only input
        // {@see RefusalContext::from()} ever has — carry `https:\/\/…`. Keyed on `://`
        // alone this pass was inert on exactly the surface it was written for.
        // ⛔ ADMITTING `\/` ALONE WAS THE SAME BUG ONE ESCAPE FURTHER IN (card#9528 (b)): a
        // userinfo carrying a raw `"` is written `\"`, the run stopped at that backslash BEFORE
        // the `@`, and the positional rule below was handed a run with no userinfo in it. In a
        // JSON string EVERY backslash begins an escape, so `\\.` is the rule and `\/` was a
        // special case of it.
        // ⚠ `'` IS NOT EXCLUDED, and `"` still is. `'` is an RFC 3986 sub-delim — legal
        // UNENCODED in a userinfo, so a real password carries one and stopping there leaked it
        // (the shape `ProvisionCommand` was masking with a caller-side substitution). `"` is
        // illegal there, is what DELIMITS a URL inside a JSON string, and is now refused at the
        // config door by {@see UrlValidator::httpUrl()} — so admitting it would buy nothing and
        // cost every JSON body the rest of its text.
        $text = (string) preg_replace_callback(
            '#\bhttps?:(?:\\\\?/){2}(?:\\\\.|[^\s<>"\\\\])+#i',
            static fn (array $m): string => self::stripCredentialComponents($m[0]),
            $text,
        );

        // JSON string value of any key CONTAINING a sensitive word: "api_token":"…" → "api_token":"[REDACTED]"
        $text = (string) preg_replace(
            '/("[^"]*(?:'.self::SENSITIVE.')[^"]*"\s*:\s*)"(?:[^"\\\\]|\\\\.)*"/i',
            '$1"'.self::REDACTED.'"',
            $text,
        );

        // A credential echoed in HEADER form: `Authorization: <value>`, `X-Api-Key: <value>`.
        // It matched NO rule until card#9528's R1 review — the rule below needs an `=`, the
        // JSON rule needs quotes, and an opaque `Authorization:` value is not a `Bearer`
        // scheme — so the value went out verbatim under a name this class's own SENSITIVE
        // list already knows.
        //
        // ⛔ THE SENSITIVE WORD MUST END THE KEY HERE, WHICH IS THE OPPOSITE OF THE RULE BELOW
        // AND IS DELIBERATE. There, the far end names the field, so the word is matched
        // ANYWHERE in the key. Here the key is a HEADER NAME — a closed vocabulary in which a
        // credential is called `api_token`, `x-api-key`, `client_secret` — while a compound
        // that uses the word as a MODIFIER (`secret_dir:`, `token_path:`) names a PATH that
        // `bridge:check` prints and an operator has to be able to read in that very line.
        // Matching key-contains here would redact an operator's own configuration out of the
        // diagnostic that exists to show it to them.
        //
        // ⚠ THE VALUE RUNS TO THE END OF THE LINE, stopping at a `"`. It takes the whole line
        // because a header value legitimately carries spaces (`Authorization: Bearer <token>`)
        // and ending at the first one would leave the credential itself standing; it stops at
        // `"` so a JSON body keeps everything after the string this matched.
        // ⚠ STATED COST: a line whose key is a bare ambiguous word — `the token: expired` —
        // loses the rest of that line. It is the same prose cost the auth-scheme rules carry,
        // on the side this class declares it errs on.
        $text = (string) preg_replace(
            '/((?<![^&?=\s"])'.self::KEY_CHARS.'(?:'.self::SENSITIVE.'):[ \t]*)[^\r\n"]+/i',
            '$1'.self::REDACTED,
            $text,
        );

        // query / form-encoded: token=abc&… → token=[REDACTED]&…
        // The key is matched by CONTAINING a sensitive word, not by BEING one —
        // {@see self::KEY_CHARS} owns why.
        //
        // ⚠ THE VALUE RUN ENDS AT WHITESPACE, AND THAT IS A BOUND RATHER THAN A CLEARANCE.
        // For genuine form-encoding a space IS the end of the value, but this class mostly
        // reads PROSE, where `password=a <secret>` leaves `password=[REDACTED] <secret>` —
        // the shape this card calls the dangerous one, because the output carries
        // `[REDACTED]`. It is not widened because crossing whitespace here would eat the
        // rest of every sentence that mentions a form field, and a value with a space in it
        // is not what an encoder emits; a credential that reaches this rule with a literal
        // space in it is covered only as far as that space.
        $text = (string) preg_replace(
            '/((?<![^&?=\s"])'.self::KEY_CHARS.'(?:'.self::SENSITIVE.')'.self::KEY_CHARS.'=)[^&\s"]+/i',
            '$1'.self::REDACTED,
            $text,
        );

        // HTTP `Bearer`/`Basic` auth schemes echoed as raw text (e.g. an echoed
        // Authorization header). These keywords are never followed by a prose word in
        // an error body, so redact the value at ANY length — a short-but-real token
        // must not slip through. Both bounds are by EXCLUSION: the separator is
        // {@see self::SCHEME_SEPARATOR} and the value {@see self::SCHEME_VALUE}, and each
        // constant owns why enumerating its own characters was the defect.
        $text = (string) preg_replace(
            '/\b(Bearer|Basic)'.self::SCHEME_SEPARATOR.self::SCHEME_VALUE.'+/i',
            '$1 '.self::REDACTED,
            $text,
        );

        // GitHub's `token <pat>` scheme. Unlike Bearer/Basic, bare `token` DOES occur
        // in prose ("token expired", "token cannot write …"), so require a
        // credential-LONG value (>=16 characters of the value alphabet, an escape pair
        // counting as one) to avoid mangling the very reason the body exists to surface.
        // Keyed/short credentials stay covered by the JSON/query/Bearer rules.
        $text = (string) preg_replace(
            '/\btoken'.self::SCHEME_SEPARATOR.self::SCHEME_VALUE.'{16,}/i',
            'token '.self::REDACTED,
            $text,
        );

        // Defense-in-depth: unambiguous secret PREFIXES redacted wherever they appear,
        // even un-keyed / in an unexpected body shape — these tokens never occur in
        // prose. Covers GitHub PATs/OAuth/app tokens (`ghp_`/`gho_`/`ghu_`/`ghs_`/`ghr_`)
        // and fine-grained PATs (`github_pat_`), and Mezzanine fleet tokens (`mzr_`), whose
        // alphabet is not pinned here — so that one runs to the next delimiter rather than
        // stopping at the first character outside a guessed charset.
        return (string) preg_replace(
            '/\b(?:gh[opusr]_[A-Za-z0-9]+|github_pat_[A-Za-z0-9_]+|mzr_[^\s"\'<>]+)/',
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
        // userinfo — everything up to the LAST `@`, whatever it contains.
        //
        // ⛔ THE BINDING IS THE LAST `@`, NOT THE FIRST DELIMITER, AND THAT IS THE WHOLE
        // POINT. A userinfo bounded by `[^/?#]*` cannot reach an `@` sitting behind a `/`,
        // `?` or `#` — and `/` is in the base64 alphabet while `?` and `#` are ordinary
        // generated-password characters, so `svc:pa/ss@host` echoed the entire credential
        // and `svc:pa?ss@host` echoed its head followed by `[REDACTED]`, which reads as
        // redacted to an operator and to any presence assertion. The scheme is optional;
        // see {@see self::url()} for why.
        //
        // ⛔ `[^\s]*` WAS THE SAME MISTAKE IN ITS LAST SURVIVING SPELLING (card#9528 (a)). It
        // could not reach an `@` behind a SPACE, and a space in a userinfo is exactly what a
        // paste error leaves — so `https://svc:pa ss@host` came back WHOLE on
        // {@see UrlValidator::httpUrl()}'s *contains whitespace; check for paste errors*
        // branch, the one branch such a value is guaranteed to reach. `.*` with `s` is the
        // binding: no character terminates a userinfo except the `@` that ends it.
        //
        // ⚠ WHAT THAT BINDING COSTS, worked: `https://h/@you/x` → `https://***@you/x`,
        // `https://board.example/api?to=a@b.example` → `https://***@b.example`, and now also
        // `https://svc:pw@remote host@x` → `https://***@x` — an at-sign ANYWHERE later takes
        // the host, the path and any prose between with it. Over-redaction is the side this
        // class declares it errs on, and every narrower bound tried here leaked.
        $value = (string) preg_replace(
            '#^([A-Za-z][A-Za-z0-9+.-]*:(?:\\\\?/){2})?.*@#s',
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
