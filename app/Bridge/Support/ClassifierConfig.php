<?php

namespace App\Bridge\Support;

use App\Bridge\Exceptions\ConfigException;

/**
 * Typed view over a classifier's `classifier.config` YAML block — the
 * parameterization surface for a shared, config-driven classifier (roundtable #8:
 * one reference classifier + a thin per-project config, so no install forks the
 * bridge).
 *
 * Before this, config reached a classifier only through the untyped
 * {@see AgentConfig::$raw} blob, so a shared base could not be parameterized
 * without ad-hoc per-classifier parsing. This is the single typed home for the
 * cross-cutting knobs a shared base needs — the scope→author-agent map (§1
 * primary attribution) and the enabled event-family set (the config-gated
 * pipeline) — plus GENERIC typed accessors ({@see strings()} / {@see string()} /
 * {@see section()}) for family-specific config (e.g. an `impl-ci-wake` family's
 * wake-conclusions and CI-name patterns) that firms up as families land, WITHOUT
 * another contract change each time.
 *
 * The shared-ACCOUNT declaration deliberately does NOT live here — it stays in
 * `shared-identities.json` (its authoritative home), which is what resolves a
 * shared-account event to `Actor.name === null`; the classifier keys on that
 * null-ness, not on a duplicated id (canon #5).
 *
 * Fail-closed, matching the {@see AgentConfig} posture: a present-but-malformed
 * key throws {@see ConfigException}; an ABSENT `classifier.config` block yields an
 * all-defaults instance ({@see empty()}) so a classifier that reads config still
 * works unconfigured (back-compat — an unset block is not an error).
 */
final class ClassifierConfig
{
    /**
     * @param  array<string, string>  $scopeAuthorMap  scope_id (lowercased) => the sole author-agent on that repo
     * @param  list<string>  $enabledFamilies  the event families this classifier runs (empty ⇒ the classifier's own default)
     * @param  array<mixed>  $raw  the full `classifier.config` mapping, for family-specific typed reads
     */
    private function __construct(
        public readonly array $scopeAuthorMap,
        public readonly array $enabledFamilies,
        public readonly array $raw,
    ) {}

    /**
     * The all-defaults instance — an absent or empty `classifier.config` block.
     */
    public static function empty(): self
    {
        return new self(scopeAuthorMap: [], enabledFamilies: [], raw: []);
    }

    /**
     * Parse the `config` sub-block of a `classifier:` YAML section. An absent
     * `config` key ⇒ {@see empty()}; a present non-mapping ⇒ throws.
     *
     * @param  array<mixed>  $classifier  the whole `classifier:` mapping (with `class` + optional `config`)
     */
    public static function fromClassifierSection(array $classifier): self
    {
        $config = $classifier['config'] ?? null;
        if ($config === null) {
            return self::empty();
        }
        if (! is_array($config)) {
            throw new ConfigException('classifier.config must be a mapping');
        }

        return new self(
            scopeAuthorMap: self::parseScopeAuthorMap($config),
            enabledFamilies: self::parseStringList($config, 'families'),
            raw: $config,
        );
    }

    // ---- generic typed accessors for family-specific config (firms up as families land) ----

    /**
     * A family-specific sub-mapping (e.g. `classifier.config.impl_ci_wake`), or an
     * empty array when absent. A present non-mapping throws.
     *
     * @return array<mixed>
     */
    public function section(string $key): array
    {
        $value = $this->raw[$key] ?? null;
        if ($value === null) {
            return [];
        }
        if (! is_array($value)) {
            throw new ConfigException("classifier.config.{$key} must be a mapping");
        }

        return $value;
    }

    /**
     * A list of non-empty strings at a top-level config key (e.g. wake-conclusions),
     * lowercased for case-insensitive matching. Absent ⇒ `$default`.
     *
     * @param  list<string>  $default
     * @return list<string>
     */
    public function strings(string $key, array $default = []): array
    {
        if (! array_key_exists($key, $this->raw)) {
            return $default;
        }

        return self::parseStringList($this->raw, $key);
    }

    /**
     * A list of "phrase groups" at a top-level config key — each group a list of
     * non-empty substrings, lowercased for case-insensitive matching. A subject
     * matches a group when it contains EVERY substring of that group (AND within a
     * group, OR across groups). Used by title drop-filters (e.g. a back-merge
     * paper-trail anchor). Absent ⇒ `[]`; a present non-list, or a non-list group,
     * or an empty substring, throws. An empty group is ignored (dropped from the
     * result) — a zero-substring group would vacuously match every title.
     *
     * @return list<list<string>>
     */
    public function stringGroups(string $key): array
    {
        $raw = $this->raw[$key] ?? null;
        if ($raw === null) {
            return [];
        }
        if (! is_array($raw)) {
            throw new ConfigException("classifier.config.{$key} must be a list of string-groups");
        }

        $out = [];
        foreach (array_values($raw) as $group) {
            if (! is_array($group)) {
                throw new ConfigException("classifier.config.{$key} entries must each be a list of strings");
            }
            $substrings = [];
            foreach (array_values($group) as $entry) {
                if (! is_scalar($entry) || (string) $entry === '') {
                    throw new ConfigException("classifier.config.{$key} substrings must be non-empty strings");
                }
                $substrings[] = strtolower((string) $entry);
            }
            if ($substrings !== []) {
                $out[] = $substrings;
            }
        }

        return $out;
    }

    /**
     * A single scalar string at a top-level config key (e.g. `release_branch`),
     * or `$default` when absent. A present non-scalar throws.
     */
    public function string(string $key, string $default): string
    {
        $value = $this->raw[$key] ?? null;
        if ($value === null) {
            return $default;
        }
        if (! is_scalar($value)) {
            throw new ConfigException("classifier.config.{$key} must be a scalar string");
        }

        return (string) $value;
    }

    // ---- parsing helpers (fail-closed) ----

    /**
     * `scope_author_map: { "owner/repo": agent }` → lowercased scope_id => agent.
     * A repo with exactly one author agent, so a label-less impl event attributes
     * to that agent (§1 primary path). Malformed entries throw.
     *
     * @param  array<mixed>  $config
     * @return array<string, string>
     */
    private static function parseScopeAuthorMap(array $config): array
    {
        $raw = $config['scope_author_map'] ?? null;
        if ($raw === null) {
            return [];
        }
        if (! is_array($raw)) {
            throw new ConfigException('classifier.config.scope_author_map must be a mapping of scope_id => agent');
        }

        $out = [];
        foreach ($raw as $scope => $agent) {
            if (! is_string($scope) || $scope === '' || ! is_string($agent) || $agent === '') {
                throw new ConfigException('classifier.config.scope_author_map entries must be non-empty scope_id => agent strings');
            }
            $out[strtolower($scope)] = $agent;
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $source
     * @return list<string>
     */
    private static function parseStringList(array $source, string $key): array
    {
        $raw = $source[$key] ?? [];
        if (! is_array($raw)) {
            throw new ConfigException("classifier.config.{$key} must be a list of strings");
        }

        $out = [];
        foreach (array_values($raw) as $entry) {
            if (! is_scalar($entry) || (string) $entry === '') {
                throw new ConfigException("classifier.config.{$key} entries must be non-empty strings");
            }
            $out[] = strtolower((string) $entry);
        }

        return $out;
    }
}
