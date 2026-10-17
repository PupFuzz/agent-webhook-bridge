<?php

namespace Tests\Unit\Support;

use App\Bridge\Exceptions\CardTextRulesException;
use App\Bridge\Support\CardTextCheck;
use App\Bridge\Support\CardTextRules;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MirrorParityTestCase;

/**
 * THE CHECK BEHIND THE BRIDGE'S PORT OF THE TOOLKIT'S CARD-TEXT CHECKS (card#11987).
 *
 * {@see CardTextCheck} reads a vendored copy of agent-board-toolkit's `bin/_card-text-rules.json`
 * through {@see CardTextRules}; the toolkit's `kbcard` is the authority. This class holds the port
 * against the published corpus, both ways, through {@see MirrorParityTestCase}; the other half — does
 * `kbcard` still answer the same — is `bin/card-text-parity.sh`. The legs below are the ones a
 * VENDORED FILE and a rule-file READER add: the bytes are the pinned bytes, the description side has
 * a comparator control of its own, and a file this reader cannot apply is refused, never passed.
 */
class CardTextParityTest extends MirrorParityTestCase
{
    private const TITLE_TAIL = 'this was a dry run; no request is ever sent.';

    private const DESCRIPTION_TAIL = 'This was a dry run; no request is ever sent.';

    protected static function mirrorClass(): string
    {
        return CardTextCheck::class;
    }

    protected static function corpusPath(): string
    {
        return 'docs/card-text-parity-corpus.json';
    }

    protected static function comparatorControl(): array
    {
        return [
            'method' => 'title',
            'args' => ['Fix lib/parse.c', 'title-check', self::TITLE_TAIL],
            'right' => 'title-check: the title breaks the plain-language card rules (README.md § Plain-language card text) — '.self::TITLE_TAIL."\n"
                ."  T2 (no file path): lib/parse.c — move the path to the Technical notes\n"
                ."  suggested title: Fix\n"
                ."  technical notes line (paste it below the description's Technical notes divider): lib/parse.c",
            'wrong' => null,
            'wrong_fragment' => 'T2 (no file path): lib/parse.c',
        ];
    }

    /**
     * ⚠ THE SECOND COMPARATOR CONTROL, FOR THE DESCRIPTION SIDE. The base class's control exercises
     * one method; a description arm that answered null for everything would pass every passing
     * vector. This feeds the comparator a description known to fail.
     */
    public function test_the_comparator_tells_a_refused_description_from_a_passing_one(): void
    {
        $this->assertNull(static::disagreement('description', ["Summary\nTechnical notes (for agents)\nIMPACT: RUNTIME", 'description-check', self::DESCRIPTION_TAIL], null));

        $said = static::disagreement('description', ["Summary\nIMPACT: RUNTIME\nTechnical notes (for agents)", 'description-check', self::DESCRIPTION_TAIL], null);
        $this->assertIsString($said, 'the comparator answered AGREEMENT on a description the rules refuse, so every description vector is vacuous.');
        $this->assertStringContainsString('the IMPACT line sits above', $said);
    }

    /** Both methods publish refused AND passing text, or a reader that always or never refused would agree with them. */
    public function test_the_corpus_publishes_refused_and_passing_text_for_both_methods(): void
    {
        foreach (['title', 'description'] as $method) {
            $vectors = static::corpus()['vectors'][$method];
            $this->assertNotSame([], array_filter($vectors, static fn (array $v): bool => $v['expect'] !== null), "no refused {$method} vector");
            $this->assertNotSame([], array_filter($vectors, static fn (array $v): bool => $v['expect'] === null), "no passing {$method} vector");
        }
    }

    /** The vendored copy is the pinned copy: a local edit to it reds here, not at the far end. */
    public function test_the_vendored_rule_file_is_the_pinned_bytes(): void
    {
        $bytes = file_get_contents(static::repoRoot().'/'.CardTextRules::VENDORED_PATH);
        $this->assertIsString($bytes);
        $this->assertSame(CardTextCheck::SOURCE_SHA256, hash('sha256', $bytes), CardTextRules::VENDORED_PATH.' is not the file CardTextCheck::SOURCE_SHA256 pins. It is a VERBATIM copy of the toolkit\'s bin/_card-text-rules.json at SOURCE_COMMIT and is never edited here: re-vendor, update both constants, and re-run bin/card-text-parity.sh.');
    }

    public function test_the_corpus_ships_a_runner_the_far_end_can_execute(): void
    {
        $this->assertSame('bin/card-text-parity.sh', static::corpus()['mirror']['runner'] ?? null, 'the toolkit authority is BASH, so a prose recipe is not enough — the far end needs a program, and this corpus must name it.');
    }

    /**
     * A rule file this reader cannot apply is REFUSED at load, as kbcard refuses it — never read as
     * "no rules" and passed. Each case is the vendored file with ONE thing changed, so a refusal can
     * only be about that change; the control is the unchanged file, which loads.
     *
     * @return array<string, array{\Closure(array<string, mixed>): array<string, mixed>, string}>
     */
    public static function unreadableRuleFiles(): array
    {
        return [
            'another schema_version' => [fn (array $d): array => ['schema_version' => 2] + $d, 'schema_version'],
            'an unknown check kind' => [function (array $d): array {
                $d['title']['rules'][0]['checks'][] = ['kind' => 'glob', 'pattern' => '*'];

                return $d;
            }, "kind 'glob'"],
            'a pattern matching the empty string' => [function (array $d): array {
                $d['title']['rules'][0]['checks'][] = ['kind' => 'ere', 'boundary' => 'none', 'ere' => 'x*'];

                return $d;
            }, 'matches the empty string'],
            'an alternation whose readers could disagree' => [function (array $d): array {
                $d['title']['rules'][0]['checks'][] = ['kind' => 'ere', 'boundary' => 'none', 'ere' => '(ab|abc)'];

                return $d;
            }, 'is a prefix of'],
            'a backslash inside a bracket expression' => [function (array $d): array {
                $d['title']['rules'][0]['checks'][] = ['kind' => 'ere', 'boundary' => 'none', 'ere' => '[\\d]+'];

                return $d;
            }, 'backslash inside a bracket expression'],
            'no D1 divider' => [function (array $d): array {
                $d['description']['rules'] = [];

                return $d;
            }, 'no D1'],
        ];
    }

    /** @param \Closure(array<string, mixed>): array<string, mixed> $edit */
    #[DataProvider('unreadableRuleFiles')]
    public function test_a_rule_file_this_reader_cannot_apply_is_refused_rather_than_read(\Closure $edit, string $why): void
    {
        $doc = json_decode((string) file_get_contents(static::repoRoot().'/'.CardTextRules::VENDORED_PATH), true);
        $this->assertIsArray($doc);
        CardTextRules::fromJson((string) json_encode($doc), 'control');

        try {
            CardTextRules::fromJson((string) json_encode($edit($doc)), 'edited');
            $this->fail("a rule file with {$why} loaded");
        } catch (CardTextRulesException $e) {
            $this->assertStringContainsString($why, $e->getMessage());
        }
    }
}
