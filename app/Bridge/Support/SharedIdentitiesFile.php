<?php

namespace App\Bridge\Support;

/**
 * The result of ONE read of `shared-identities.json`: what state the file was in, and —
 * when it parsed — what it declared (card#5546).
 *
 * IT IS A TYPE BECAUSE THERE ARE EXACTLY TWO CONSUMERS AND ONE READ, not because a
 * result object is tidier. The registry build needs the identities and the preflight
 * report needs the STATE, and they run in the same `bridge:check` pass — so before this
 * type the second consumer read the file again, and the reader logs (a permissions fault,
 * a non-object, and one line per wrongly-shaped entry), which made a wrongly-shaped file
 * warn twice per run. That is the extract-at-the-second-caller threshold, met: one read,
 * one result, both consumers served from it.
 *
 * THE MALFORMED LAYER DELIBERATELY DOES NOT HOIST INTO {@see FileContents}. That guard
 * owns the absent-vs-unreadable discrimination — which this type CONSUMES rather than
 * copies — and stops there because what a malformed BODY means is per-caller policy:
 * `WritebackConfig::load` raises `ConfigException` for its malformed shape, because a
 * null there would silently mean "writeback is off", while this file degrades fail-soft
 * because its other caller is the receiver, which must not 5xx over an optional policy
 * file. Pushing the parse verdict down would force one of those two postures on the other.
 *
 * THE STATE IS RECORDED AT THE READ, and every consumer reports it as of that moment
 * rather than re-measuring. That is a narrowing, not a new exposure: the arm this replaces
 * discriminated the file's state and THEN re-read it to count, so the file could change
 * between the verdict and the number it was rendered with.
 */
final class SharedIdentitiesFile
{
    /**
     * @param  string  $path  the resolved path, carried so consumers name the same file the
     *                        read named — a consumer re-deriving it from the config dir is a
     *                        second copy of the rtrim rule
     * @param  list<SharedIdentity>  $identities  empty for every state but
     *                                            {@see SharedIdentitiesFileState::Parsed}, which is what lets a caller
     *                                            wanting only the list ignore the state and keep the fail-soft contract
     */
    private function __construct(
        public readonly string $path,
        public readonly SharedIdentitiesFileState $state,
        public readonly array $identities = [],
    ) {}

    public static function absent(string $path): self
    {
        return new self($path, SharedIdentitiesFileState::Absent);
    }

    public static function unreadable(string $path): self
    {
        return new self($path, SharedIdentitiesFileState::Unreadable);
    }

    public static function malformed(string $path): self
    {
        return new self($path, SharedIdentitiesFileState::Malformed);
    }

    /** @param  list<SharedIdentity>  $identities */
    public static function parsed(string $path, array $identities): self
    {
        return new self($path, SharedIdentitiesFileState::Parsed, $identities);
    }
}
