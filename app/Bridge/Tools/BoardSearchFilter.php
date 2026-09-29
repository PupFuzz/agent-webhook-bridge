<?php

namespace App\Bridge\Tools;

/**
 * `board_search`'s filters, spelled as kanban search `q` terms — the ONE place that spelling lives
 * ({@see BoardSearchTool}). Every term is a kanban `QueryParser` structured filter (source-read at
 * kanban `origin/dev` 54a63399); none is free text, and the tool refuses any answer whose disclosure
 * says otherwise. Values arrive validated: tags through {@see BoardTagTerm}, the name without `"` or a
 * control character, the date as `YYYY-MM-DD`, the lane as a numeric id or `none`.
 */
final class BoardSearchFilter
{
    /**
     * @param  list<string>  $tagsAll
     * @param  list<int>|null  $stages
     */
    public function __construct(
        private readonly array $tagsAll,
        private readonly ?array $stages,
        private readonly ?string $nameContains,
        private readonly ?string $updatedSince,
        private readonly ?string $swimlane,
    ) {}

    /** @param  list<int>  $stages */
    public function withStages(array $stages): self
    {
        return new self($this->tagsAll, $stages, $this->nameContains, $this->updatedSince, $this->swimlane);
    }

    /** Whether any filter here narrows the board — the question `pr_number`'s re-ask depends on. */
    public function narrows(): bool
    {
        return $this->tagsAll !== [] || $this->stages !== null || $this->nameContains !== null || $this->updatedSince !== null || $this->swimlane !== null;
    }

    /**
     * The `q` terms after the board scope. `$tag` is one more exact tag (a `tags_any` member or a
     * `summary_tags` tag), `$tagCounted` a second one, and `$id` narrows to one card.
     */
    public function terms(?string $tag = null, ?string $tagCounted = null, ?int $id = null): string
    {
        $terms = $id === null ? [] : ["id={$id}"];
        foreach ([...$this->tagsAll, ...array_filter([$tag, $tagCounted], fn (?string $t): bool => $t !== null)] as $t) {
            $terms[] = "tags:\"{$t}\"";
        }
        if ($this->stages !== null) {
            $terms[] = 'workflow_stage_id='.implode(',', $this->stages);
        }
        if ($this->nameContains !== null) {
            $terms[] = "name:\"{$this->nameContains}\"";
        }
        if ($this->updatedSince !== null) {
            $terms[] = "updated_at>=:{$this->updatedSince}";
        }
        if ($this->swimlane !== null) {
            $terms[] = "swimlane_id={$this->swimlane}";
        }

        return implode(' ', $terms);
    }
}
