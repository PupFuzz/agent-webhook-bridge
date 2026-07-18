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
     * Systems whose ref is a pure integer identifier: strip every non-digit and
     * leading zeros so "DL-028", "DL-28", "28", "#28" all canonicalize equal.
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
     * (e.g. a `dl_number` with no digits) — the caller then derives no ref row
     * (a malformed display field must not become a correlatable key, nor 422 the
     * task write). Unknown systems are stored/compared verbatim (trimmed, capped).
     */
    public function canonicalize(string $system, int|string $value): ?string
    {
        $value = trim((string) $value);

        if (in_array($system, self::NUMERIC_SYSTEMS, true)) {
            $digits = preg_replace('/\D+/', '', $value) ?? '';
            if ($digits === '') {
                return null;
            }
            // Strip leading zeros, keeping at least one digit.
            $canonical = ltrim($digits, '0');

            return $canonical === '' ? '0' : $canonical;
        }

        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, self::REF_MAX);
    }
}
