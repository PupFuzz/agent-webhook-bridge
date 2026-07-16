<?php

namespace App\Bridge\Support;

use Illuminate\Http\Client\RequestException;

/**
 * Log context for a kanban 4xx refusal: the HTTP status PLUS the response body,
 * verbatim but truncated and credential-scrubbed.
 *
 * Status alone cannot tell a permission refusal (403) from a validation refusal
 * (422) from a state refusal (404) — the server states the actual reason in the
 * body, and every writeback handler previously discarded it, so a real incident
 * (DL-204: a 403 authz refusal) was indistinguishable from a config typo. This
 * hands the operator what the server actually said instead of a guessed cause.
 *
 * The body is scrubbed BEFORE truncation: a credential could otherwise be split
 * across the truncation boundary, leaving its head unredacted. Over-redaction is
 * deliberate — a benign key that merely contains a sensitive word loses its value
 * too; a leaked token is the failure mode we design against, a redacted-but-benign
 * field is not.
 */
final class RefusalContext
{
    private const MAX_BODY = 500;

    /**
     * Key- and scheme-name fragments whose adjacent value is a probable credential.
     * `[_-]?` tolerates the api_key / api-key / apikey spellings.
     */
    private const SENSITIVE = 'authorization|bearer|token|secret|passwd|password|api[_-]?key|access[_-]?key|access[_-]?token|refresh[_-]?token|client[_-]?secret|private[_-]?key|credential|x-api-key';

    private const REDACTED = '[REDACTED]';

    /**
     * @return array{status: int, body: string}
     */
    public static function from(RequestException $e): array
    {
        return [
            'status' => $e->response->status(),
            'body' => self::truncate(self::scrub($e->response->body())),
        ];
    }

    /**
     * Redact credential-adjacent values a kanban error body — or an echoed request
     * inside it — could carry: JSON values of a sensitive key, query/form `key=value`
     * pairs, and `Bearer`/`Basic`/`token` auth-scheme values.
     */
    public static function scrub(string $body): string
    {
        // JSON string value of any key CONTAINING a sensitive word: "api_token":"…" → "api_token":"[REDACTED]"
        $body = (string) preg_replace(
            '/("[^"]*(?:'.self::SENSITIVE.')[^"]*"\s*:\s*)"(?:[^"\\\\]|\\\\.)*"/i',
            '$1"'.self::REDACTED.'"',
            $body,
        );

        // query / form-encoded: token=abc&… → token=[REDACTED]&…
        $body = (string) preg_replace(
            '/\b((?:'.self::SENSITIVE.')=)[^&\s"]+/i',
            '$1'.self::REDACTED,
            $body,
        );

        // HTTP `Bearer`/`Basic` auth schemes echoed as raw text (e.g. an echoed
        // Authorization header). These keywords are never followed by a prose word in
        // an error body, so redact the value at ANY length — a short-but-real token
        // must not slip through.
        $body = (string) preg_replace(
            '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]+/i',
            '$1 '.self::REDACTED,
            $body,
        );

        // GitHub's `token <pat>` scheme. Unlike Bearer/Basic, bare `token` DOES occur
        // in prose ("token expired", "token cannot write …"), so require a
        // credential-LONG value (>=16 of the token charset) to avoid mangling the very
        // reason the body exists to surface. Keyed/short credentials stay covered by
        // the JSON/query/Bearer rules.
        $body = (string) preg_replace(
            '/\btoken\s+[A-Za-z0-9._~+\/=-]{16,}/i',
            'token '.self::REDACTED,
            $body,
        );

        // Defense-in-depth: unambiguous secret PREFIXES redacted wherever they appear,
        // even un-keyed / in an unexpected body shape — these tokens never occur in
        // prose. Covers GitHub PATs/OAuth/app tokens (`ghp_`/`gho_`/`ghu_`/`ghs_`/`ghr_`)
        // and fine-grained PATs (`github_pat_`).
        return (string) preg_replace(
            '/\b(?:gh[opusr]_[A-Za-z0-9]+|github_pat_[A-Za-z0-9_]+)/',
            self::REDACTED,
            $body,
        );
    }

    private static function truncate(string $body): string
    {
        if (mb_strlen($body) <= self::MAX_BODY) {
            return $body;
        }

        return mb_substr($body, 0, self::MAX_BODY).'…(truncated)';
    }
}
