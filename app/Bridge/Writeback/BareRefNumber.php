<?php

namespace App\Bridge\Writeback;

use App\Bridge\Support\ExternalReferenceNormalizer;

/**
 * The single definition of "which pull request / issue does this BARE correlation NUMBER
 * name" — the `pr_number` / `issue_number` sibling of {@see PrUrlRef}, which answers the
 * same question for a `pr_url`.
 *
 * ⛔ NOT the `dl_number` path, and that is not an omission to tidy up: a DL is compared
 * against a token parsed out of PR text, not against a number an event carries, so its
 * consumers ({@see KanbanClient::correlateDl}, `ReconcileCommand::closes`) call the
 * normalizer with NO positive-bare admission — `DL-000` canonicalizes to `"0"` and really
 * does name a decision. Routing them through here would narrow what they match.
 *
 * A bare number reaches the bridge in several spellings for one identifier (kanban's
 * payload gives an int or a JSON number, the durable inbox JSON round-trip gives a
 * numeric string, an operator's `kbcard` stamp gives `'085'`), so every consumer that
 * asked with a local `(int)` cast was answering a DIFFERENT question from the kanban
 * server, which derives the card's `github_pr` / `github_issue` ref from the very same
 * payload key through {@see ExternalReferenceNormalizer} (kanban DL-251, mirrored here by
 * DL-309). `'1.5'` cast to PR **1** — a real, unrelated pull request — where the server
 * derives no ref at all, and nothing on either side reports the disagreement.
 *
 * Extracted at the FOURTH call site (canon #5): DL-309 closed the two sites its
 * investigation named and left the shape open, so `TrackedCardRef`, the corroboration
 * gate and both `KanbanClient` scan correlations now share one predicate rather than four
 * spellings of it (DL-311). A second implementation of "same PR" is exactly what lets two
 * readers disagree about one card and one pull request.
 *
 * TWO HALVES, and they are not interchangeable — the distinction DL-309 turned on:
 *   - ADMISSION ({@see canonical}'s guard) — which raw values are a bare number at all.
 *     A POSITIVE BARE number, and deliberately no wider: `-5` canonicalizes to the ref
 *     `"5"` and `'#85'` to `"85"`, both refs the kanban server really does index, so
 *     admitting them would start correlating cards off values nobody meant as a PR
 *     number. That widening is a separate ruling nobody has made.
 *   - DERIVATION (the normalizer call) — WHICH identifier an admitted value names. This
 *     is the half that must never be a local cast.
 */
final class BareRefNumber
{
    /**
     * The canonical ref an admitted bare number names, or null when the value is not a
     * positive bare number (ADMISSION) or names no single decorated integer (DERIVATION —
     * `'1.5'`, `'2026-08-23'`, `'PR 12 of 34'`). Null is "this names no identifier" and is
     * the fail-closed answer at every consumer: it can never be read as "the same one".
     *
     * @param  string  $system  an {@see ExternalReferenceNormalizer} numeric system slug
     */
    public static function canonical(string $system, mixed $value, ExternalReferenceNormalizer $refs): ?string
    {
        if (! is_numeric($value) || (float) $value <= 0) {
            return null;
        }

        return $refs->canonicalize($system, $value);
    }

    /**
     * Do these two values name the SAME identifier? Fail-closed on both sides: a value
     * naming nothing compares equal to nothing, INCLUDING to another value naming nothing
     * — which is what stops "unknown" reading as "same".
     */
    public static function namesSame(string $system, mixed $a, mixed $b, ExternalReferenceNormalizer $refs): bool
    {
        $canon = self::canonical($system, $a, $refs);

        return $canon !== null && $canon === self::canonical($system, $b, $refs);
    }
}
