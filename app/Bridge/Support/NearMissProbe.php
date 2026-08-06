<?php

namespace App\Bridge\Support;

/**
 * THE near-miss probe: "does this text APPEAR to name a `<stem>` token?", asked
 * only where the owning grammar's `parse()` already returned null — so a match
 * means a near-miss, and without it the branch publishes, the token correlates
 * to nothing, and nobody is told.
 *
 * WHY THIS CLASS EXISTS. DL-250 built this mechanism on {@see CardTokenGrammar}
 * and its reasoning was never about cards: the separators are DATA, and the
 * pattern and the vector set that covers it are two consumers of that one
 * definition rather than two lists someone must keep in step. When
 * {@see DlTokenGrammar} needed the same probe (card#5310) the choice was one
 * owner or a second copy of rule-shaped logic inside a second grammar — the
 * restatement shape DL-250 exists to remove. So the mechanism moved here and
 * each grammar binds it to its own stem; the ACCEPT-SETs stay per-grammar,
 * because those genuinely differ.
 *
 * The probe is deliberately NOT derivable from any grammar's pattern — the
 * near-miss set is a different set (DL-239(h), upheld by DL-250(1)). It is
 * looser on purpose: `\b<stem>s?(<separator>)?\d`. The `s?` is the whole silent
 * plural family DL-250 found — `\b<stem>` matches inside `<stem>s`, the optional
 * separator group cannot consume the `s`, and `\d` then fails against it with no
 * second word boundary to retry from.
 *
 * STATED BOUND, inherited rather than re-decided: the digit class is ASCII (no
 * `/u`) per DL-231, so a Unicode-digit token stays silent at runtime on every
 * stem. That is the ratified position, not an oversight — the pre-merge CI lint
 * (`.github/workflows/pr-title-lint.yml`) covers the class by name instead, and
 * since card#5961 it does so for BOTH stems: it grew a `looks_dl=`/`good_dl=`
 * arm beside its card one, so `DL-<U+0663>239` is no longer silent on both
 * surfaces at once.
 */
final class NearMissProbe
{
    /**
     * The separators an author types where a real one belongs — one human
     * behaviour, so ONE list, shared by every stem (card#5310). A grammar's own
     * ACCEPTED separators are in here too and that is inert by construction:
     * the probe is consulted only where `parse()` returned null, so no text the
     * grammar accepts can reach it. Each grammar pins that implication itself,
     * because it is a property of that grammar's pattern, not of this list.
     *
     * A bare space is NOT here and must not be added: DL-201 ruled prose
     * ("supports card 2") stays silent, so `card 123` is not a near-miss.
     * Everything derived from this list inherits that ruling — a
     * separator-derived corpus cannot widen into it, which is one more reason to
     * derive rather than list. The space-then-hash member is a LITERAL ` #`
     * rather than `\s#` (DL-250(5)), so the bash predicate in
     * `pr-title-lint.yml` and this engine can be tied answer-set to answer-set.
     *
     * @var list<string>
     */
    public const SEPARATORS = ['-', '#', '_', ':', '.', ' #'];

    public function __construct(private readonly string $stem) {}

    /**
     * Does this text appear to name a `<stem>` token? Ask this; never recompile
     * the separators into a second pattern.
     */
    public function matches(string $text): bool
    {
        return preg_match($this->pattern(), $text) === 1;
    }

    /**
     * One text per cell of the probe's domain — {singular, plural} × {no
     * separator} ∪ {@see self::SEPARATORS} — so the shapes the probe must
     * RECOGNISE are derived from the separator data rather than typed out beside
     * it. Which cells then WARN is the owning grammar's `parse()` answer, not
     * this list's: the cells that correlate must stay silent.
     *
     * @return list<string>
     */
    public function vectors(string $sampleId): array
    {
        $vectors = [];
        foreach (array_merge([''], self::SEPARATORS) as $separator) {
            $vectors[] = "{$this->stem}{$separator}{$sampleId}";
            $vectors[] = "{$this->stem}s{$separator}{$sampleId}";
        }

        return $vectors;
    }

    private function pattern(): string
    {
        $separators = implode('|', array_map(
            fn (string $s) => preg_quote($s, '/'),
            self::SEPARATORS,
        ));

        return '/\b'.preg_quote($this->stem, '/').'s?(?:'.$separators.')?\d/i';
    }
}
