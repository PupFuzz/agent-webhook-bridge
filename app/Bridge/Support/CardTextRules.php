<?php

namespace App\Bridge\Support;

use App\Bridge\Exceptions\CardTextRulesException;

/**
 * The plain-language card-text rules (titles T1-T6, the description's filing bar and D1), read from
 * the ONE declaration both writers share: agent-board-toolkit's `bin/_card-text-rules.json`, vendored
 * byte-for-byte at {@see VENDORED_PATH}. Nothing here restates a limit, a list or a pattern; the
 * file is the rule. What this class owns is the MATCHING ALGORITHM, ported from `bin/kbcard`
 * (`_kbc_title_findings`, `_kbc_title_bar`, `_kbc_mint_bar`) as the toolkit README's "The rule
 * file's schema" defines it for a PCRE reader, plus the refusal text, which is kbcard's line for
 * line so a seat sees the same reason and rewrite from either writer.
 *
 * The pin ({@see CardTextCheck}), the published-copy check and the behaviour corpus are `docs/card-text-parity-corpus.json`'s
 * (held here by `Tests\Unit\Support\CardTextParityTest`, against the toolkit by
 * `bin/card-text-parity.sh`).
 *
 * The reading rules, as kbcard applies them and the README's "The rule file's schema" states them:
 *  - lines split on LF only, so a CR stays on its line (it is not a blank, and `[^A-Z0-9_]` matches it);
 *  - "blank" is exactly the six ASCII blanks (`kb_is_blank`), never PHP's default `trim()` set, which
 *    strips NUL and not FF;
 *  - the FIRST divider line wins, and "text above it" looks only above that one;
 *  - a `prefix` check makes ONE pass per value in file order, so `SECURITY: CLASS: x` reports only
 *    `SECURITY:`;
 *  - lengths are Unicode code points (kbcard asks jq), cut offsets are bytes (kbcard runs under C).
 *
 * POSIX ERE takes the LONGEST alternative and PCRE the FIRST that matches. The two agree when no
 * alternative can match where another also matches, so {@see guardPattern()} refuses to load a file
 * holding any alternation other than prefix-free literals or a one-character class beside `$` (the
 * only two shapes the file uses). Other differences between POSIX leftmost-longest and PCRE
 * backtracking are NOT guarded by construction; the parity corpus is what holds them.
 */
final class CardTextRules
{
    public const SCHEMA = 'agent-board-toolkit/card-text-rules';

    public const SCHEMA_VERSION = 1;

    public const VENDORED_PATH = 'resources/card-text-rules.json';

    /** kbcard's blank set (`kb_is_blank`): space, tab, CR, LF, VT, FF — nothing else. */
    private const BLANKS = " \t\r\n\x0B\f";

    private static ?self $vendored = null;

    /**
     * @param  list<array{id: string, summary: string, hint: string, checks: list<array{kind: string, limit: int, values: list<string>, pattern: string}>}>  $titleRules
     * @param  array{lead: string, trail: string, label: string, label_space: string, label_sep: string, kind_end: string, value: string, divider: string, kinds: list<array{kind: string, requires_line: string, requires_text: string}>}  $description
     */
    private function __construct(private readonly array $titleRules, private readonly array $description) {}

    /** The vendored copy, loaded once per process. Throws {@see CardTextRulesException} rather than pass unchecked text. */
    public static function vendored(): self
    {
        return self::$vendored ??= self::fromFile(dirname(__DIR__, 3).'/'.self::VENDORED_PATH);
    }

    public static function fromFile(string $path): self
    {
        $raw = is_file($path) && is_readable($path) ? file_get_contents($path) : false;
        if (! is_string($raw)) {
            throw new CardTextRulesException("the card-text rule file is not readable: {$path} — the board tools refuse a title or description they cannot check");
        }

        return self::fromJson($raw, $path);
    }

    public static function fromJson(string $json, string $source): self
    {
        $doc = json_decode($json, true);
        if (! is_array($doc) || ($doc['schema'] ?? null) !== self::SCHEMA || ($doc['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            throw new CardTextRulesException("{$source} is not a schema_version ".self::SCHEMA_VERSION.' card-text rule file (schema '.self::SCHEMA.') — this reader refuses any other, as kbcard does');
        }

        return new self(self::titleRules($doc, $source), self::descriptionRules($doc, $source));
    }

    /**
     * kbcard's refusal for a title, or null when it passes. `$verb` and `$tail` are the caller's
     * (kbcard's dry run says `title-check` / "this was a dry run; no request is ever sent.").
     */
    public function titleRefusal(string $title, string $verb, string $tail): ?string
    {
        [$findings, $rewrite] = $this->titleFindings($title);
        if ($findings === []) {
            return null;
        }

        $byId = [];
        foreach ($this->titleRules as $rule) {
            $byId[$rule['id']] = $rule;
        }
        $lines = [];
        $tokens = [];
        $all = [];
        $limit = null;
        foreach ($findings as $f) {
            if ($f[0] === 'L') {
                $lines[] = "  {$f[1]} ({$byId[$f[1]]['summary']}): the title is {$f[2]} characters, over the limit of {$f[3]} — shorten it";
                $limit = $f[3];
            } else {
                $tokens[$f[1]][] = $f[2];
                $all[] = $f[2];
            }
        }
        foreach ($tokens as $id => $toks) {
            $hint = $byId[$id]['hint'];
            $lines[] = "  {$id} ({$byId[$id]['summary']}): ".implode(', ', $toks).($hint !== '' ? " — {$hint}" : '');
        }

        $out = ["{$verb}: the title breaks the plain-language card rules (README.md § Plain-language card text) — {$tail}", ...$lines];
        if ($all !== []) {
            if ($rewrite !== '') {
                $limit ??= $this->smallestLengthLimit();
                $length = mb_strlen($rewrite, 'UTF-8');
                $out[] = "  suggested title: {$rewrite}";
                if ($limit !== null && $length > $limit) {
                    $out[] = "    (still {$length} characters, over the limit of {$limit} — shorten it)";
                }
            } else {
                $out[] = '  suggested title: nothing is left once those are cut — write the title in plain words';
            }
            $out[] = "  technical notes line (paste it below the description's Technical notes divider): ".implode(', ', $all);
        }

        return implode("\n", $out);
    }

    /**
     * kbcard's refusal for a description (the filing bar, then D1), or null when it passes.
     */
    public function descriptionRefusal(string $description, string $verb, string $tail): ?string
    {
        $d = $this->description;
        $labelRe = $d['lead'].str_replace(' ', $d['label_space'], $d['label']).$d['label_sep'];
        $lines = explode("\n", $description);
        $divider = strtoupper($d['divider']);

        $kind = null;
        $kindAt = -1;
        $dividerAt = -1;
        $textAbove = false;
        $requiredRe = null;
        foreach ($lines as $n => $line) {
            $up = strtoupper($line);
            if ($kind === null) {
                foreach ($d['kinds'] as $k) {
                    if (self::matches($labelRe.$k['kind'].$d['kind_end'], $up)) {
                        $kind = $k;
                        $kindAt = $n;
                        if ($k['requires_line'] !== '') {
                            $requiredRe = $d['lead'].str_replace(' ', $d['label_space'], $k['requires_line']).$d['label_sep'].$d['value'];
                        }
                        break;
                    }
                }
            }
            if ($dividerAt < 0) {
                $rest = $up;
                if (preg_match('@'.$d['lead'].'@D', $rest, $m) === 1) {
                    $rest = substr($rest, strlen($m[0]));
                }
                if (preg_match('@'.$d['trail'].'@D', $rest, $m) === 1) {
                    $rest = substr($rest, 0, strlen($rest) - strlen($m[0]));
                }
                if ($rest === $divider) {
                    $dividerAt = $n;
                } elseif (trim($line, self::BLANKS) !== '') {
                    $textAbove = true;
                }
            }
        }

        $hasRequiredLine = false;
        if ($requiredRe !== null) {
            foreach ($lines as $line) {
                if (self::matches($requiredRe, strtoupper($line))) {
                    $hasRequiredLine = true;
                    break;
                }
            }
        }

        $label = $d['label'];
        $clauses = [];
        foreach ($d['kinds'] as $k) {
            if ($k['requires_line'] !== '') {
                $clauses[] = "{$k['kind']} also needs a '{$k['requires_line']}: {$k['requires_text']}' line";
            }
        }
        $need = "the filing bar (README.md § kbcard create-card — the filing bar) needs one line in the description: {$label}: "
            .implode(' | ', array_column($d['kinds'], 'kind')).($clauses !== [] ? ' — '.implode(', ', $clauses) : '').". {$tail}";
        if ($kind === null) {
            return "{$verb}: the card's description states no {$label} line — {$need}";
        }
        if ($kind['requires_line'] !== '' && ! $hasRequiredLine) {
            return "{$verb}: {$label} is {$kind['kind']} and no non-empty '{$kind['requires_line']}:' line is in the description — {$need}";
        }

        $div = $d['divider'];
        $d1 = "D1 (README.md § Plain-language card text): write a plain-language summary first, then the line '{$div}', then the agent detail below it — paths, card numbers and the {$label} line. {$tail}";
        if ($dividerAt < 0) {
            return "{$verb}: the description has no '{$div}' line — {$d1}";
        }
        if (! $textAbove) {
            return "{$verb}: the description has no plain-language text above the '{$div}' line — {$d1}";
        }
        if ($kindAt < $dividerAt) {
            return "{$verb}: the {$label} line sits above the '{$div}' line — move it below. {$d1}";
        }

        return null;
    }

    /**
     * T1-T6 over `$title`: the findings in the order kbcard prints them — `['L', id, length, limit]`
     * for a length rule, `['F', id, token, 0]` for a token — and the working copy left at the end, which
     * is the suggested rewrite. Each token is cut (replaced by a space, blanks folded) before the next
     * match is looked for, so a token is reported once, under the first check that finds it.
     *
     * @return array{0: list<array{0: string, 1: string, 2: string, 3: int}>, 1: string}
     */
    private function titleFindings(string $title): array
    {
        $length = mb_strlen(trim($title, self::BLANKS), 'UTF-8');
        $work = self::fold($title);
        $findings = [];
        foreach ($this->titleRules as $rule) {
            foreach ($rule['checks'] as $check) {
                if ($check['kind'] === 'max_length') {
                    if ($length > $check['limit']) {
                        $findings[] = ['L', $rule['id'], (string) $length, $check['limit']];
                    }
                } elseif ($check['kind'] === 'prefix') {
                    foreach ($check['values'] as $value) {
                        if (str_starts_with($work, $value)) {
                            $findings[] = ['F', $rule['id'], $value, 0];
                            $work = self::fold(' '.substr($work, strlen($value)));
                        }
                    }
                } else {
                    while (preg_match($check['pattern'], $work, $m, PREG_OFFSET_CAPTURE) === 1) {
                        [$token, $at] = $m[1];
                        if ($token === '') {
                            throw new CardTextRulesException("rule {$rule['id']} matched the empty string on a title — the title rules cannot be applied");
                        }
                        $findings[] = ['F', $rule['id'], $token, 0];
                        $work = self::fold(substr($work, 0, $at).' '.substr($work, $at + strlen($token)));
                    }
                }
            }
        }

        return [$findings, $work];
    }

    private function smallestLengthLimit(): ?int
    {
        $limits = [];
        foreach ($this->titleRules as $rule) {
            foreach ($rule['checks'] as $check) {
                if ($check['kind'] === 'max_length') {
                    $limits[] = $check['limit'];
                }
            }
        }

        return $limits === [] ? null : min($limits);
    }

    /** Every run of the six blanks folded to one space and the ends trimmed — kbcard's working copy. */
    private static function fold(string $text): string
    {
        return trim((string) preg_replace('@[ \t\r\n\x0B\f]+@', ' ', $text), ' ');
    }

    private static function matches(string $ere, string $subject): bool
    {
        return preg_match('@'.$ere.'@D', $subject) === 1;
    }

    /**
     * @param  array<mixed>  $doc
     * @return list<array{id: string, summary: string, hint: string, checks: list<array{kind: string, limit: int, values: list<string>, pattern: string}>}>
     */
    private static function titleRules(array $doc, string $source): array
    {
        $rules = $doc['title']['rules'] ?? null;
        if (! is_array($rules) || ! array_is_list($rules) || $rules === []) {
            throw new CardTextRulesException("{$source}: title.rules is not a non-empty list");
        }
        $out = [];
        foreach ($rules as $i => $rule) {
            $id = is_array($rule) ? ($rule['id'] ?? null) : null;
            $summary = is_array($rule) ? ($rule['summary'] ?? null) : null;
            $hint = is_array($rule) ? ($rule['hint'] ?? '') : null;
            $checks = is_array($rule) ? ($rule['checks'] ?? null) : null;
            if (! is_string($id) || $id === '' || ! is_string($summary) || ! is_string($hint) || ! is_array($checks) || ! array_is_list($checks)) {
                throw new CardTextRulesException("{$source}: title.rules[{$i}] is not {id, summary, hint?, checks[]}");
            }
            $compiled = [];
            foreach ($checks as $check) {
                $compiled[] = self::titleCheck(is_array($check) ? $check : [], "{$source}: rule {$id}");
            }
            $out[] = ['id' => $id, 'summary' => $summary, 'hint' => $hint, 'checks' => $compiled];
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $check
     * @return array{kind: string, limit: int, values: list<string>, pattern: string}
     */
    private static function titleCheck(array $check, string $where): array
    {
        $kind = $check['kind'] ?? null;
        if ($kind === 'max_length') {
            $limit = $check['limit'] ?? null;
            if (! is_int($limit) || $limit < 0) {
                throw new CardTextRulesException("{$where}: a max_length check carries no non-negative integer limit");
            }

            return ['kind' => 'max_length', 'limit' => $limit, 'values' => [], 'pattern' => ''];
        }
        if ($kind === 'prefix') {
            $values = $check['values'] ?? null;
            if (! is_array($values) || ! array_is_list($values) || $values === []) {
                throw new CardTextRulesException("{$where}: a prefix check carries no values");
            }
            foreach ($values as $value) {
                if (! is_string($value) || $value === '') {
                    throw new CardTextRulesException("{$where}: a prefix check carries a value that is not a non-empty string");
                }
            }

            return ['kind' => 'prefix', 'limit' => 0, 'values' => $values, 'pattern' => ''];
        }
        if ($kind !== 'ere') {
            throw new CardTextRulesException("{$where} has a check of kind '".(is_string($kind) ? $kind : json_encode($kind))."', which this reader cannot apply — update the bridge with the rule file");
        }

        $ere = $check['ere'] ?? null;
        $boundary = $check['boundary'] ?? null;
        $ignoreCase = $check['ignore_case'] ?? false;
        if (! is_string($ere) || $ere === '' || ! in_array($boundary, ['word', 'start', 'none'], true) || ! is_bool($ignoreCase)) {
            throw new CardTextRulesException("{$where}: an ere check is not {ere, boundary: word|start|none, ignore_case: bool}");
        }
        self::guardPattern($ere, $where);
        if (preg_match('@\A(?:'.$ere.')\z@', '') === 1) {
            throw new CardTextRulesException("{$where}: the pattern '{$ere}' matches the empty string — the title rules cannot be applied");
        }
        $core = '('.$ere.')';
        $pattern = '@'.match ($boundary) {
            'word' => '(?<![A-Za-z0-9_])'.$core.'(?![A-Za-z0-9_])',
            'start' => '\A'.$core,
            default => $core,
        }.'@D'.($ignoreCase ? 'i' : '');

        return ['kind' => 'ere', 'limit' => 0, 'values' => [], 'pattern' => $pattern];
    }

    /**
     * @param  array<mixed>  $doc
     * @return array{lead: string, trail: string, label: string, label_space: string, label_sep: string, kind_end: string, value: string, divider: string, kinds: list<array{kind: string, requires_line: string, requires_text: string}>}
     */
    private static function descriptionRules(array $doc, string $source): array
    {
        $desc = is_array($doc['description'] ?? null) ? $doc['description'] : [];
        $bar = is_array($desc['filing_bar'] ?? null) ? $desc['filing_bar'] : [];
        $patterns = [
            'lead' => $desc['line_lead_ere'] ?? null,
            'trail' => $desc['line_trail_ere'] ?? null,
            'label_space' => $bar['label_space_ere'] ?? null,
            'label_sep' => $bar['label_sep_ere'] ?? null,
            'kind_end' => $bar['kind_end_ere'] ?? null,
            'value' => $bar['value_ere'] ?? null,
        ];
        foreach ($patterns as $key => $ere) {
            if (! is_string($ere) || $ere === '') {
                throw new CardTextRulesException("{$source}: the description's {$key} pattern is missing");
            }
            self::guardPattern($ere, "{$source}: description {$key}");
        }
        if (! str_starts_with($patterns['lead'], '^') || ! str_ends_with($patterns['trail'], '$')) {
            throw new CardTextRulesException("{$source}: line_lead_ere must be anchored with ^ and line_trail_ere with \$");
        }

        $label = $bar['label'] ?? null;
        if (! is_string($label) || ! self::isLiteralLabel($label)) {
            throw new CardTextRulesException("{$source}: filing_bar.label is not literal uppercase text");
        }
        $kinds = [];
        foreach (is_array($bar['kinds'] ?? null) ? $bar['kinds'] : [] as $k) {
            $kind = is_array($k) ? ($k['kind'] ?? null) : null;
            $line = is_array($k) ? ($k['requires_line'] ?? '') : null;
            $text = is_array($k) ? ($k['requires_text'] ?? '') : null;
            if (! is_string($kind) || preg_match('@\A[A-Z0-9-]+\z@', $kind) !== 1 || ! is_string($line) || ($line !== '' && ! self::isLiteralLabel($line)) || ! is_string($text)) {
                throw new CardTextRulesException("{$source}: a filing_bar kind is not {kind, requires_line?, requires_text?} in literal uppercase text");
            }
            $kinds[] = ['kind' => $kind, 'requires_line' => $line, 'requires_text' => $text];
        }
        if ($kinds === []) {
            throw new CardTextRulesException("{$source}: filing_bar.kinds is empty");
        }

        $divider = null;
        foreach (is_array($desc['rules'] ?? null) ? $desc['rules'] : [] as $rule) {
            if (is_array($rule) && ($rule['id'] ?? null) === 'D1' && is_string($rule['divider'] ?? null) && $rule['divider'] !== '') {
                $divider = $rule['divider'];
            }
        }
        if ($divider === null) {
            throw new CardTextRulesException("{$source}: description.rules holds no D1 with a divider");
        }

        $out = ['label' => $label, 'divider' => $divider, 'kinds' => $kinds] + array_map('strval', $patterns);
        $labelRe = $out['lead'].str_replace(' ', $out['label_space'], $label).$out['label_sep'];
        foreach ($kinds as $k) {
            self::compiles($labelRe.$k['kind'].$out['kind_end'], $source);
            if ($k['requires_line'] !== '') {
                self::compiles($out['lead'].str_replace(' ', $out['label_space'], $k['requires_line']).$out['label_sep'].$out['value'], $source);
            }
        }
        self::compiles($out['trail'], $source);

        return $out;
    }

    private static function isLiteralLabel(string $text): bool
    {
        return preg_match('@\A[A-Z0-9-]+(?: [A-Z0-9-]+)*\z@', $text) === 1;
    }

    private static function compiles(string $ere, string $where): void
    {
        if (@preg_match('@'.$ere.'@D', '') === false) {
            throw new CardTextRulesException("{$where}: the pattern '{$ere}' does not compile as PCRE");
        }
    }

    /**
     * The README's portability rules, enforced at load: no `@` (the delimiter), no backslash inside a
     * bracket expression (POSIX reads it literally, PCRE as an escape), the pattern compiles, and every
     * alternation is one this reader can prove picks what POSIX's longest-match would.
     */
    private static function guardPattern(string $ere, string $where): void
    {
        if (str_contains($ere, '@')) {
            throw new CardTextRulesException("{$where}: the pattern '{$ere}' contains '@', the delimiter a PCRE reader uses");
        }
        self::compiles($ere, $where);

        $frames = [['']];
        $len = strlen($ere);
        for ($i = 0; $i < $len; $i++) {
            $c = $ere[$i];
            $top = count($frames) - 1;
            $alt = count($frames[$top]) - 1;
            if ($c === '\\') {
                $frames[$top][$alt] .= substr($ere, $i, 2);
                $i++;
            } elseif ($c === '[') {
                $end = self::bracketEnd($ere, $i, $where);
                $frames[$top][$alt] .= substr($ere, $i, $end - $i + 1);
                $i = $end;
            } elseif ($c === '(') {
                $frames[] = [''];
            } elseif ($c === ')' && $top > 0) {
                $group = array_values(array_pop($frames));
                self::guardAlternatives($group, $ere, $where);
                $frames[$top - 1][count($frames[$top - 1]) - 1] .= '('.implode('|', $group).')';
            } elseif ($c === '|') {
                $frames[$top][] = '';
            } else {
                $frames[$top][$alt] .= $c;
            }
        }
        self::guardAlternatives(array_values($frames[0]), $ere, $where);
    }

    /** The offset of the `]` closing the bracket expression that opens at `$start`. */
    private static function bracketEnd(string $ere, int $start, string $where): int
    {
        $len = strlen($ere);
        $j = $start + 1;
        if ($j < $len && $ere[$j] === '^') {
            $j++;
        }
        if ($j < $len && $ere[$j] === ']') {
            $j++;
        }
        while ($j < $len) {
            if ($ere[$j] === '[' && $j + 1 < $len && in_array($ere[$j + 1], [':', '.', '='], true)) {
                $close = strpos($ere, $ere[$j + 1].']', $j + 2);
                if ($close === false) {
                    break;
                }
                $j = $close + 2;

                continue;
            }
            if ($ere[$j] === '\\') {
                throw new CardTextRulesException("{$where}: the pattern '{$ere}' has a backslash inside a bracket expression, which POSIX and PCRE read differently");
            }
            if ($ere[$j] === ']') {
                return $j;
            }
            $j++;
        }

        throw new CardTextRulesException("{$where}: the pattern '{$ere}' has an unclosed bracket expression");
    }

    /** @param  list<string>  $alternatives */
    private static function guardAlternatives(array $alternatives, string $ere, string $where): void
    {
        if (count($alternatives) < 2) {
            return;
        }
        $literal = array_filter($alternatives, static fn (string $a): bool => preg_match('@\A[A-Za-z0-9-]+\z@', $a) === 1);
        if (count($literal) === count($alternatives)) {
            $folded = array_map('strtolower', $alternatives);
            foreach ($folded as $i => $a) {
                foreach ($folded as $j => $b) {
                    if ($i !== $j && str_starts_with($b, $a)) {
                        throw new CardTextRulesException("{$where}: in the pattern '{$ere}' the alternative '{$alternatives[$i]}' is a prefix of '{$alternatives[$j]}' — POSIX takes the longest and PCRE the first, so the two readers would disagree");
                    }
                }
            }

            return;
        }
        if (count($alternatives) === 2 && in_array('$', $alternatives, true)) {
            $other = $alternatives[0] === '$' ? $alternatives[1] : $alternatives[0];
            if ($other !== '' && $other[0] === '[' && self::bracketEnd($other, 0, $where) === strlen($other) - 1) {
                return;
            }
        }

        throw new CardTextRulesException("{$where}: the pattern '{$ere}' has an alternation ('".implode('|', $alternatives)."') this reader cannot prove gives the same answer under POSIX longest-match and PCRE first-match — only prefix-free literals, or a one-character class beside \$, are accepted");
    }
}
