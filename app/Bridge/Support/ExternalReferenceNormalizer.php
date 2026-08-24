<?php

namespace App\Bridge\Support;

/**
 * VENDORED from kanban-board's `App\Services\ExternalReferenceNormalizer`
 * (DL-147/163) — the single normalization authority for task external references.
 * The bridge must canonicalize refs/sources/repos EXACTLY as the kanban server
 * does so that `ref`-mode by-ref lookups (server-canonicalized) and the bridge's
 * own client-side derivations (scan-mode correlation, dependabot repo attribution,
 * `bridge:check` source-coverage) agree on the same keys.
 *
 * **KEEP IN SYNC** with the kanban source of truth. This is a deliberate, faithful
 * mirror (the bridge is a separate runtime/repo and cannot import the kanban class)
 * so a future drift is caught by a 1:1 diff against the upstream file. Do not
 * "improve" it locally — change the kanban authority, then re-mirror here.
 *
 * **Mirrored through kanban DL-251** (bridge DL-309 — a drift that had already SHIPPED
 * on both sides is what that pair is: for a while the two authorities answered a
 * malformed value differently and nothing reported it). ⚠ A `DL-NNN` in this file names
 * a KANBAN decision unless it says otherwise — this repo's log has its own DL-163 and DL-251,
 * neither of which is the one above.
 *
 *   1. PAYLOAD_KEY_TO_SYSTEM — which display custom-field key derives which
 *      machine `system`.
 *   2. per-system canonicalization — `"DL-028"` / `"DL-28"` / `"28"` → `"28"`.
 */
class ExternalReferenceNormalizer
{
    public const SYSTEM_DL = 'dl';

    public const SYSTEM_GITHUB_PR = 'github_pr';

    public const SYSTEM_GITHUB_ISSUE = 'github_issue';

    /** Display custom-field key → external-reference system slug. */
    private const PAYLOAD_KEY_TO_SYSTEM = [
        'dl_number' => self::SYSTEM_DL,
        'pr_number' => self::SYSTEM_GITHUB_PR,
        'issue_number' => self::SYSTEM_GITHUB_ISSUE,
    ];

    /**
     * Payload keys whose GitHub URL value yields the card's source `owner/repo`
     * when no explicit `repo` key is present (DL-163). Order = preference.
     */
    private const SOURCE_URL_KEYS = ['pr_url', 'issue_url', 'html_url'];

    /**
     * Systems whose ref is a pure integer identifier: ONE integer, optionally
     * decorated, with leading zeros stripped — so "DL-028", "DL-28", "28", "#28"
     * all canonicalize equal. A value carrying anything other than one integer
     * (no digits, or several digit runs) names no identifier and canonicalizes
     * to null (kanban DL-251).
     */
    private const NUMERIC_SYSTEMS = [
        self::SYSTEM_DL,
        self::SYSTEM_GITHUB_PR,
        self::SYSTEM_GITHUB_ISSUE,
    ];

    private const REF_MAX = 255;

    /** A well-formed system slug. */
    public const SYSTEM_REGEX = '/^[a-z0-9_]{1,32}$/';

    /**
     * The `system` a display payload key derives, or null if the key is not a
     * correlation source.
     */
    public function systemForPayloadKey(string $payloadKey): ?string
    {
        return self::PAYLOAD_KEY_TO_SYSTEM[$payloadKey] ?? null;
    }

    /**
     * The card's source repo (`owner/repo`) used to repo-qualify its refs on a
     * multi-repo board (DL-163), or null when the card carries no parseable
     * source. A source applies to ALL of a card's refs (`dl` + `github_pr` +
     * `github_issue`) — a card tracks one repo. Preference order:
     *   1. explicit `payload.repo`,
     *   2. a GitHub URL in a payload key ({@see SOURCE_URL_KEYS}),
     *   3. the card's top-level `external_link` (the canonical kanban field for
     *      "the URL this card tracks") when it's a GitHub URL — so a card that
     *      stores its PR URL there rather than in payload still qualifies, with
     *      no producer migration.
     *
     * @param  array<string, mixed>  $payload
     */
    public function sourceFor(array $payload, ?string $externalLink = null): ?string
    {
        // Trust an explicit `payload.repo` only when it's a plausible `owner/repo`
        // (carries a path separator). A producer that writes a SHORT adapter alias
        // there (e.g. cc-coordination-framework's `"DEV"`) must NOT have it win over
        // a parseable GitHub URL and get stored as a useless source — fall through
        // to the URL sources instead.
        $repo = $payload['repo'] ?? null;
        if (is_string($repo) && trim($repo) !== '' && str_contains($repo, '/')) {
            return $this->canonicalizeSource($repo);
        }

        foreach (self::SOURCE_URL_KEYS as $key) {
            $fromPayload = isset($payload[$key]) && is_string($payload[$key]) ? $this->repoFromGitHubUrl($payload[$key]) : null;
            if ($fromPayload !== null) {
                return $fromPayload;
            }
        }

        return is_string($externalLink) ? $this->repoFromGitHubUrl($externalLink) : null;
    }

    /**
     * The canonical `owner/repo` from a GitHub web URL
     * (`github.com/<owner>/<repo>/{pull,issues,commit,tree,blob}/…`), or null.
     */
    public function repoFromGitHubUrl(string $url): ?string
    {
        if (preg_match('#github\.com/([^/]+/[^/]+?)(?:\.git)?/(?:pull|issues|commit|tree|blob)/#i', $url, $m) === 1) {
            return $this->canonicalizeSource($m[1]);
        }

        return null;
    }

    /**
     * Canonicalize a source repo for storage + lookup: trim, lower-case (GitHub
     * `owner/repo` is case-insensitive), cap to the column width. Returns null
     * for an empty value so a blank `source` is stored/queried as "unqualified".
     */
    public function canonicalizeSource(int|string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return mb_strtolower(mb_substr($value, 0, self::REF_MAX));
    }

    /**
     * Canonicalize a raw ref value for a system. Returns the canonical string,
     * or null when the value carries no usable identifier for a numeric system
     * — the caller then derives no ref row (a malformed display field must not
     * become a correlatable key, nor 422 the task write). Unknown systems are
     * stored/compared verbatim (trimmed, capped).
     *
     * A numeric system's ref is ONE decorated integer (kanban DL-251). A value
     * carrying no digits ("TBD") or SEVERAL digit runs ("1.5", "2026-08-23") names
     * no single identifier, so it derives nothing rather than the concatenation of
     * its runs — which would be a real, DIFFERENT pull request or decision.
     */
    public function canonicalize(string $system, float|int|string $value): ?string
    {
        // A number-typed correlation field stores a JSON number, so a float
        // reaches here. Naming it in the signature is what stops PHP's weak-mode
        // coercion truncating 1.5 to 1 before the value is read, and rendering it
        // faithfully — ahead of the system branch, so a non-numeric system is not
        // truncated either — makes the derived ref a property of the VALUE rather
        // than of the JSON type it happened to arrive as. A plain (string) cast is
        // not faithful: PHP renders 1.0e15 as "1.0E+15" despite it being an exact
        // integer, so an in-range whole number goes through int.
        if (is_float($value)) {
            $value = (is_finite($value) && fmod($value, 1.0) === 0.0 && abs($value) < (float) PHP_INT_MAX)
                ? (string) (int) $value
                : (string) $value;
        }

        $value = trim((string) $value);

        if (in_array($system, self::NUMERIC_SYSTEMS, true)) {
            if (preg_match('/^\D*(\d+)\D*$/', $value, $m) !== 1) {
                return null;
            }
            // Strip leading zeros, keeping at least one digit.
            $canonical = ltrim($m[1], '0');

            return $canonical === '' ? '0' : $canonical;
        }

        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, self::REF_MAX);
    }
}
