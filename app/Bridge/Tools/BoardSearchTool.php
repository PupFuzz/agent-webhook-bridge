<?php

namespace App\Bridge\Tools;

use App\Bridge\Exceptions\ToolRefusalException;
use App\Bridge\Support\BoardToolsConfig;
use App\Bridge\Support\ExternalReferenceNormalizer;
use App\Bridge\Writeback\BoardReadRefused;
use App\Bridge\Writeback\BoardStructure;
use App\Bridge\Writeback\KanbanClient;
use App\Bridge\Writeback\KanbanFieldLimits;
use App\Bridge\Writeback\SearchPage;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

/**
 * board_search (card#10832 Stage 2, DL-437; rt#572 asks 3–5) — the cards on the seat's own board
 * matching caller-named filters, returning MATCHES ONLY: no lane list, no column list, no grouping.
 * `summary: true` returns counts and no rows. A read tool on the board-tools door;
 * {@see BoardToolsRegistry} is the set that door offers.
 *
 * ⭐ EVERY FILTER IS KANBAN'S, NEVER A BRIDGE-SIDE SCAN. Each one rides kanban's own search DSL
 * (`QueryParser`, source-read at kanban `origin/dev` 54a63399): `tags:"t"` per tag (ANDed),
 * `workflow_stage_id=a,b`, `name:"needle"` (a LIKE `%needle%`), `updated_at>=:YYYY-MM-DD` (a DATE
 * compare), `swimlane_id=<lane>|none`, and the `archived` switch. The two kanban has no term for are
 * answered from kanban reads too, and are bounded by construction rather than by a walk:
 *  - `tags_any` — kanban's DSL has no OR. One search per tag, merged by id. Every search returns the
 *    NEWEST `limit` of its own matches, so the newest `limit` of the union is exactly the newest
 *    `limit` of the merge (a card among the union's newest `limit` is among the newest `limit` of
 *    every tag it carries). The union's SIZE is exact only when every search was complete; otherwise
 *    it is a lower bound and the window says so.
 *  - `pr_number` — no search term reads it (a `custom_field_pr_number` term compares a raw stored
 *    value whose type is the board's choice, while the bridge correlates PRs through kanban's
 *    canonicalized by-ref index — DL-029/147). The by-ref index answers every LIVE card carrying the
 *    PR, and each is then re-asked through the search with every other filter and `id=<n>`, so
 *    kanban still decides every predicate. The by-ref index hard-excludes archived cards, so
 *    `pr_number` with `include_archived` is REFUSED, naming that gap.
 *
 * ⛔ A FILTER KANBAN DID NOT APPLY IS A REFUSAL, NEVER AN ANSWER. kanban's search runs a term it
 * does not recognise as FREE TEXT, at 200 — a count of something else that looks exactly like a
 * count of the thing asked. Every search this tool sends is checked against kanban's DL-282
 * disclosure (`meta.free_text_terms`, kanban v0.47.0): a term there, or no disclosure at all, and
 * the whole call refuses. {@see confirmed}.
 *
 * ⛔ THE WINDOW IS HONEST OR THE CALL REFUSES. Rows are the NEWEST `limit` matches (kanban orders the
 * search by id descending and ids are allocated monotonically), in that order. `total` is kanban's
 * own `meta.total` for the population, so `truncated` is true exactly when more matched than were
 * returned, and `total_is_lower_bound` is true only on a `tags_any` union the bridge could not size
 * exactly — where `truncated` is then true too, because the window cannot be shown complete.
 * `limit` is capped at {@see KanbanClient::SEARCH_LIMIT}, kanban's own page cap, so ONE request per
 * search is the whole window: nothing is walked.
 *
 * ⚠ IT CROSSES LANES by default (`lane: any`) — the third read on this door that does, after
 * DL-383's `tag` read and DL-435's `board_get_cards`, and the first whose population is
 * caller-FILTERED rather than caller-NAMED. It is bounded to the seat's own configured board: every
 * search carries `board_id=<board>`, kanban's disclosure confirms it applied, and a row naming
 * another board refuses the call without its content.
 */
final class BoardSearchTool implements Tool
{
    /** The largest `limit`: kanban's own per-page cap, so one request per search is the whole window. */
    public const MAX_LIMIT = KanbanClient::SEARCH_LIMIT;

    /** `limit` when the caller names none — borrowed from `board_my_cards`' per-list card-count cap. */
    public const DEFAULT_LIMIT = BoardMyCardsTool::DEFAULT_MAX_CARDS;

    /**
     * The most kanban requests one call may fan out to — BORROWED, not chosen: the per-call ceiling
     * `board_get_cards` already accepted against kanban's per-user rate limit, which every board-tools
     * call and the writeback share (DL-435 bound (d)). A call whose fan-out would exceed it is refused
     * before the fan-out is sent, with its own count; see `docs/board-tools.md` § `board_search` Cost.
     */
    public const REQUEST_CEILING = 3 * BoardGetCardsTool::MAX_IDS + 1;

    public const LANE_MINE = 'mine';

    public const LANE_ANY = 'any';

    public const LANE_NONE = 'none';

    /** @var list<string> */
    public const LANES = [self::LANE_MINE, self::LANE_ANY, self::LANE_NONE];

    public function name(): string
    {
        return 'board_search';
    }

    public function acceptedArguments(): array
    {
        return ['tags_all', 'tags_any', 'stage', 'pr_number', 'name_contains', 'updated_since', 'include_archived', 'lane', 'summary', 'summary_tags', 'fields', 'limit'];
    }

    public function refusedArgumentReason(string $key): ?string
    {
        return BoardCardProjection::refusedDescriptionArgument($key);
    }

    public function call(array $args, BoardToolsConfig $cfg, KanbanClient $client, string $agentName): array
    {
        // Every argument first, so a refused call reads nothing.
        $boardId = (int) $cfg->boardId;
        $tagsAll = $this->tagList($args, 'tags_all');
        $tagsAny = $this->tagList($args, 'tags_any');
        $prNumber = $this->prNumber($args);
        $name = $this->nameContains($args);
        $since = $this->updatedSince($args);
        $archived = $this->flag($args, 'include_archived');
        $lane = $this->lane($args);
        $summary = $this->flag($args, 'summary');
        $summaryTags = $this->summaryTags($args, $summary);
        $fields = $this->fields($args, $summary);
        $limit = $this->limit($args, $summary);
        $stageArg = $this->stageArgument($args);

        if ($prNumber !== null && $archived) {
            throw new ToolRefusalException('board_search: `pr_number` cannot be combined with `include_archived: true`. kanban finds a card by its PR number only through its by-ref index, which answers LIVE cards and has no switch for archived ones, and its search has no PR-number term — so the archived side of that match cannot be read, and an answer leaving it out would look complete. Drop one of the two; `board_get_cards` reads known ids on either side.');
        }
        if ($summary && count($tagsAny) > 1) {
            throw new ToolRefusalException('board_search: `summary` cannot count a `tags_any` of more than one tag. kanban\'s search has no OR, so each tag is its own count, and a card carrying two of them would be counted twice in any sum — the bridge does not report a count it cannot stand behind. Count one tag at a time, or use `summary_tags` to count each tag on its own.');
        }

        $sides = $archived ? [false, true] : [false];
        $variants = $tagsAny === [] ? [null] : $tagsAny;
        if (! $summary && $prNumber === null) {
            $this->withinCeiling(count($variants) * count($sides), 'one search per `tags_any` tag per archive side');
        }

        $structure = null;
        if ($stageArg !== null || $summary || in_array('stage', $fields, true)) {
            try {
                $structure = $client->boardStructure($boardId);
            } catch (RequestException $e) {
                throw $this->readRefusal($e, $agentName, BoardReadRoute::BoardScoped, "the structure of your board {$boardId}");
            }
        }
        $stageNames = $structure->stageNames ?? [];
        $stages = $stageArg === null ? null : $this->resolveStages($stageArg, $stageNames, $boardId);

        $filter = new BoardSearchFilter($tagsAll, $stages, $name, $since, $lane === self::LANE_ANY ? null : ($lane === self::LANE_MINE ? (string) (int) $cfg->swimlaneId : 'none'));
        $echo = array_filter([
            'tags_all' => $tagsAll ?: null,
            'tags_any' => $tagsAny ?: null,
            'stage' => $stages,
            'pr_number' => $prNumber,
            'name_contains' => $name,
            'updated_since' => $since,
        ], fn ($v): bool => $v !== null) + ['include_archived' => $archived, 'lane' => $lane];

        if ($prNumber !== null) {
            $matches = $this->byPrNumber($client, $boardId, $prNumber, $filter, $variants, $agentName);

            return $summary
                ? $this->summaryResult($boardId, $echo, $this->tallyByStage($matches, $stages, $structure), $this->tallyByTag($client, $boardId, $matches, $filter, $summaryTags, $agentName), count($matches))
                : $this->rowsResult($boardId, $echo, $fields, $limit, $cfg, $stageNames, array_map(fn (array $r): array => ['row' => $r, 'archived' => false], $matches), count($matches), false, $archived);
        }

        if ($summary) {
            return $this->summaryByCounts($client, $boardId, $echo, $filter, $variants[0], $stages, $structure, $summaryTags, $sides, $agentName);
        }

        $all = [];
        $total = 0;
        $lowerBound = false;
        foreach ($sides as $side) {
            $byId = [];
            $sideTotals = [];
            $complete = true;
            foreach ($variants as $variant) {
                $page = $this->search($client, $boardId, $filter->terms($variant), $limit, $side, $agentName);
                $sideTotals[] = (int) $page->total;
                $complete = $complete && count((array) $page->rows) >= (int) $page->total;
                foreach ((array) $page->rows as $row) {
                    $byId[(int) $row['id']] ??= $row;
                }
            }
            if (count($variants) === 1) {
                $total += $sideTotals[0];
            } elseif ($complete) {
                $total += count($byId);
            } else {
                $total += max(count($byId), max($sideTotals));
                $lowerBound = true;
            }
            foreach ($byId as $row) {
                $all[] = ['row' => $row, 'archived' => $side];
            }
        }

        return $this->rowsResult($boardId, $echo, $fields, $limit, $cfg, $stageNames, $all, $total, $lowerBound, $archived);
    }

    /**
     * The window over `$matches`: the newest `$limit` by id, newest first.
     *
     * @param  array<string, mixed>  $echo
     * @param  list<string>  $fields
     * @param  array<int, string>  $stageNames
     * @param  list<array{row: array<string, mixed>, archived: bool}>  $matches
     * @return array<string, mixed>
     */
    private function rowsResult(int $boardId, array $echo, array $fields, int $limit, BoardToolsConfig $cfg, array $stageNames, array $matches, int $total, bool $lowerBound, bool $flagArchived): array
    {
        usort($matches, fn (array $a, array $b): int => (int) $b['row']['id'] <=> (int) $a['row']['id']);
        $kept = array_slice($matches, 0, $limit);
        $descriptionCap = in_array(BoardCardProjection::OPT_IN_FIELD, $fields, true) ? $cfg->descriptionMaxBytes : null;

        $cards = [];
        foreach ($kept as $match) {
            $row = $match['row'];
            $card = BoardCardProjection::select(
                BoardCardProjection::withPosition(BoardCardProjection::withSwimlane(BoardCardProjection::project($row, $stageNames, $descriptionCap), $row), $row),
                $fields,
            );
            if ($flagArchived) {
                $card['archived'] = $match['archived'];
            }
            $cards[] = $card;
        }

        return [
            'configured_board_id' => $boardId,
            'filters' => $echo,
            'fields' => $fields,
            'cards' => $cards,
            'window' => [
                'total' => $total,
                'returned' => count($cards),
                'limit' => $limit,
                // A lower-bound total cannot show the window complete, so it is never reported uncut.
                'truncated' => $lowerBound || $total > count($cards),
                'total_is_lower_bound' => $lowerBound,
            ],
        ];
    }

    /**
     * `summary: true` off kanban's own counts: one `limit=1` search per count, read for `meta.total`.
     *
     * @param  array<string, mixed>  $echo
     * @param  list<int>|null  $stages
     * @param  list<string>  $summaryTags
     * @param  list<bool>  $sides
     * @return array<string, mixed>
     */
    private function summaryByCounts(KanbanClient $client, int $boardId, array $echo, BoardSearchFilter $filter, ?string $variant, ?array $stages, ?BoardStructure $structure, array $summaryTags, array $sides, string $agentName): array
    {
        $scope = $stages ?? $this->boardStageOrder($structure);
        $this->withinCeiling(count($sides) * (1 + count($scope) + count($summaryTags)), 'per archive side, one count for the total, one per stage in scope and one per `summary_tags` tag');

        $total = 0;
        $byStage = array_fill_keys($scope, 0);
        $byTag = array_fill_keys($summaryTags, 0);
        foreach ($sides as $side) {
            $total += $this->count($client, $boardId, $filter->terms($variant), $side, $agentName);
            foreach ($scope as $stageId) {
                $byStage[$stageId] += $this->count($client, $boardId, $filter->withStages([$stageId])->terms($variant), $side, $agentName);
            }
            foreach ($summaryTags as $tag) {
                $byTag[$tag] += $this->count($client, $boardId, $filter->terms($variant, $tag), $side, $agentName);
            }
        }

        return $this->summaryResult($boardId, $echo, $this->stageCounts($byStage, $structure), $this->tagCounts($byTag), $total);
    }

    /**
     * @param  array<string, mixed>  $echo
     * @param  list<array{id: int, name: ?string, count: int}>  $byStage
     * @param  list<array{tag: string, count: int}>  $byTag
     * @return array<string, mixed>
     */
    private function summaryResult(int $boardId, array $echo, array $byStage, array $byTag, int $total): array
    {
        $result = [
            'configured_board_id' => $boardId,
            'filters' => $echo,
            'summary' => [
                'total' => $total,
                'total_is_lower_bound' => false,
                'truncated' => false,
                'by_stage' => $byStage,
                // Each count is its own read, so a card moving between two of them can make the
                // columns disagree with the total by the cards that moved; said, not hidden.
                'stage_counts_sum_to_total' => array_sum(array_column($byStage, 'count')) === $total,
            ],
        ];
        if ($byTag !== []) {
            $result['summary']['by_tag'] = $byTag;
        }

        return $result;
    }

    /**
     * Every live card carrying `$prNumber` (kanban's by-ref index), each re-asked through the search
     * with every other filter when there is any — so kanban decides every predicate.
     *
     * @param  list<string|null>  $variants
     * @return list<array<string, mixed>>
     */
    private function byPrNumber(KanbanClient $client, int $boardId, int $prNumber, BoardSearchFilter $filter, array $variants, string $agentName): array
    {
        try {
            $rows = $client->cardRowsByRef($boardId, ExternalReferenceNormalizer::SYSTEM_GITHUB_PR, (string) $prNumber);
        } catch (RequestException $e) {
            throw $this->readRefusal($e, $agentName, BoardReadRoute::BoardScoped, "your board {$boardId}'s PR-number index");
        }
        if ($rows === null) {
            $this->unreadable($boardId, 'the by-ref read of PR '.$prNumber, $agentName);
        }
        $candidates = $this->onThisBoard($rows, $boardId, $agentName);
        if (! $filter->narrows() && $variants === [null]) {
            return $candidates;
        }

        $this->withinCeiling(count($candidates) * count($variants), 'one search per card carrying the PR number per `tags_any` tag, to apply the other filters');
        $matches = [];
        foreach ($candidates as $candidate) {
            foreach ($variants as $variant) {
                $page = $this->search($client, $boardId, $filter->terms($variant, null, (int) $candidate['id']), 1, false, $agentName);
                if ((array) $page->rows !== []) {
                    $matches[] = ((array) $page->rows)[0];

                    continue 2;
                }
            }
        }

        return $matches;
    }

    /**
     * `summary_tags` over a complete, id-known population: each tag counted by kanban, one `id=<n>`
     * search per card per tag, so the tag match is kanban's and never a second one written here.
     *
     * @param  list<array<string, mixed>>  $matches
     * @param  list<string>  $summaryTags
     * @return list<array{tag: string, count: int}>
     */
    private function tallyByTag(KanbanClient $client, int $boardId, array $matches, BoardSearchFilter $filter, array $summaryTags, string $agentName): array
    {
        $this->withinCeiling(count($matches) * count($summaryTags), 'one search per matching card per `summary_tags` tag');
        $byTag = array_fill_keys($summaryTags, 0);
        foreach ($summaryTags as $tag) {
            foreach ($matches as $row) {
                $byTag[$tag] += $this->count($client, $boardId, $filter->terms(null, $tag, (int) $row['id']), false, $agentName);
            }
        }

        return $this->tagCounts($byTag);
    }

    /**
     * @param  list<array<string, mixed>>  $matches
     * @param  list<int>|null  $stages
     * @return list<array{id: int, name: ?string, count: int}>
     */
    private function tallyByStage(array $matches, ?array $stages, ?BoardStructure $structure): array
    {
        $byStage = array_fill_keys($stages ?? $this->boardStageOrder($structure), 0);
        foreach ($matches as $row) {
            $stageId = (int) $row['workflow_stage_id'];
            $byStage[$stageId] = ($byStage[$stageId] ?? 0) + 1;
        }

        return $this->stageCounts($byStage, $structure);
    }

    /**
     * The board's stage ids, in column order: the named stages as the board orders them, then any
     * stage the board carries without a name.
     *
     * @return list<int>
     */
    private function boardStageOrder(?BoardStructure $structure): array
    {
        if ($structure === null) {
            return [];
        }

        return array_values(array_unique([...array_keys($structure->stageNames), ...$structure->stageIds]));
    }

    /**
     * @param  array<int, int>  $byStage
     * @return list<array{id: int, name: ?string, count: int}>
     */
    private function stageCounts(array $byStage, ?BoardStructure $structure): array
    {
        $out = [];
        foreach ($byStage as $id => $count) {
            $out[] = ['id' => $id, 'name' => $structure->stageNames[$id] ?? null, 'count' => $count];
        }

        return $out;
    }

    /**
     * @param  array<string, int>  $byTag
     * @return list<array{tag: string, count: int}>
     */
    private function tagCounts(array $byTag): array
    {
        $out = [];
        foreach ($byTag as $tag => $count) {
            $out[] = ['tag' => (string) $tag, 'count' => $count];
        }

        return $out;
    }

    private function count(KanbanClient $client, int $boardId, string $terms, bool $archivedOnly, string $agentName): int
    {
        return (int) $this->search($client, $boardId, $terms, 1, $archivedOnly, $agentName)->total;
    }

    /** One search, confirmed ({@see confirmed}) — the only way this tool reads the search. */
    private function search(KanbanClient $client, int $boardId, string $terms, int $limit, bool $archivedOnly, string $agentName): SearchPage
    {
        try {
            $page = $client->searchPage($boardId, $terms, $limit, $archivedOnly);
        } catch (RequestException $e) {
            throw $this->readRefusal($e, $agentName, BoardReadRoute::Search, "your board {$boardId}");
        }

        return $this->confirmed($page, $boardId, $agentName);
    }

    /**
     * ⛔ A search answer is used only when kanban SAYS it applied every term as a filter, carries a
     * count, and returned only rows of this board. kanban answers an unrecognised term as free text
     * at 200 (its search degrades rather than erroring), so without the first check a filter an
     * older kanban does not know — `swimlane_id=none` before v0.45.0 — would come back as a count of
     * cards whose TEXT matches it. The disclosure is kanban's DL-282 partition (v0.47.0); a response
     * without it is undisclosed, which is refused as surely as a free-texted term, because the two
     * cannot be told apart.
     */
    private function confirmed(SearchPage $page, int $boardId, string $agentName): SearchPage
    {
        if ($page->rows === null) {
            $this->unreadable($boardId, 'the search', $agentName);
        }
        if ($page->freeTextTerms === null) {
            throw new ToolRefusalException("board_search: kanban's search did not say how it read the filters (no `meta.free_text_terms` — a kanban older than v0.47.0, or something other than kanban answering), so the bridge cannot show your filters were applied rather than searched as text. NO cards were returned. This is an INSTALL fault; report it to your operator.", installFault: true);
        }
        if ($page->freeTextTerms !== []) {
            Log::warning('board_search: kanban searched a filter term as free text — refusing without an answer', [
                'agent' => $agentName, 'board_id' => $boardId, 'free_text_terms' => $page->freeTextTerms,
            ]);

            throw new ToolRefusalException('board_search: kanban searched '.implode(', ', array_map(fn (string $t): string => "`{$t}`", $page->freeTextTerms)).' as TEXT instead of applying it as a filter — its search does not know that filter, so any answer would count something else. NO cards were returned. This is an INSTALL fault (the kanban is older than the filter); report it to your operator.', installFault: true);
        }
        if ($page->total === null) {
            throw new ToolRefusalException("board_search: kanban's search answered without a match count (`meta.total`), so no window or count could be stated honestly. NO cards were returned. This is an INSTALL fault; report it to your operator.", installFault: true);
        }
        $this->onThisBoard($page->rows, $boardId, $agentName);

        return $page;
    }

    /**
     * The rows, each shown to be a card of THIS board with an integer id — the tenant boundary,
     * decided by {@see BoardScopedRow::forCard}, the one owner of "this row IS card N on board B"
     * on this door. A row that is not refuses the call without its content.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function onThisBoard(array $rows, int $boardId, string $agentName): array
    {
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            if (! is_int($id) || BoardScopedRow::forCard([$row], $boardId, $id) === null) {
                Log::warning('board_search: a read returned a row that is not a card of the configured board — refusing without an answer', [
                    'agent' => $agentName, 'board_id' => $boardId, 'card_id' => is_scalar($id) ? $id : null,
                ]);

                throw new ToolRefusalException("board_search: kanban answered a card that is not on your board {$boardId} (or carries no usable id) — a BROKEN READ, so NO cards were returned. This is an INSTALL fault; report it to your operator.", installFault: true);
            }
        }

        return $rows;
    }

    private function unreadable(int $boardId, string $read, string $agentName): never
    {
        $message = "board_search: {$read} of board {$boardId} answered a 200 whose body carried no card collection — kanban's response shape may have changed, or something other than kanban may be answering; NO cards were returned";
        Log::warning($message, ['agent' => $agentName, 'board_id' => $boardId]);

        throw new BoardReadRefused($message);
    }

    private function withinCeiling(int $requests, string $how): void
    {
        if ($requests > self::REQUEST_CEILING) {
            throw new ToolRefusalException("board_search: this call would send {$requests} kanban requests ({$how}), over the per-call ceiling of ".self::REQUEST_CEILING.' — every board-tools call and the bridge\'s own writeback share one per-minute kanban budget. NO cards were returned; narrow the call (fewer tags, fewer stages, or a filter that matches fewer cards).');
        }
    }

    /**
     * @param  list<int|string>  $stageArg
     * @param  array<int, string>  $stageNames
     * @return list<int>
     */
    private function resolveStages(array $stageArg, array $stageNames, int $boardId): array
    {
        return array_values(array_unique(array_map(fn (int|string $s): int => BoardStageArgument::resolve($s, $stageNames, $boardId, $this->name()), $stageArg)));
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<int|string>|null
     */
    private function stageArgument(array $args): ?array
    {
        if (! array_key_exists('stage', $args)) {
            return null;
        }
        $stage = $args['stage'];
        if (! is_array($stage) || ! array_is_list($stage) || $stage === []) {
            throw new ToolRefusalException('board_search: `stage` must be a non-empty LIST of columns — each a numeric stage id or a stage name. Omit it to search every column; an empty or non-list value is refused rather than ignored.');
        }
        foreach ($stage as $s) {
            if (! is_int($s) && ! is_string($s)) {
                throw new ToolRefusalException('board_search: each `stage` entry must be a numeric stage id or a stage NAME as a string — '.json_encode($s).' is neither, and is never coerced.');
            }
        }

        return $stage;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<string>
     */
    private function tagList(array $args, string $key): array
    {
        if (! array_key_exists($key, $args)) {
            return [];
        }
        $list = $args[$key];
        if (! is_array($list) || ! array_is_list($list) || $list === []) {
            throw new ToolRefusalException("board_search: `{$key}` must be a non-empty LIST of tags (for example [\"lane:A\"]). Omit it to filter on no tag; an empty or non-list value is refused rather than ignored.");
        }
        $tags = [];
        foreach ($list as $raw) {
            $tag = is_string($raw) ? BoardToolArgs::trimmed($raw) : '';
            if ($tag === '') {
                throw new ToolRefusalException("board_search: every `{$key}` entry must be a non-empty tag, as a string — ".json_encode($raw).' is not one. Tags are matched exactly and never coerced.');
            }
            BoardTagTerm::check($tag, $this->name(), $key);
            $tags[] = $tag;
        }

        return array_values(array_unique($tags));
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<string>
     */
    private function summaryTags(array $args, bool $summary): array
    {
        if (array_key_exists('summary_tags', $args) && ! $summary) {
            throw new ToolRefusalException('board_search: `summary_tags` counts cards per tag in a `summary: true` call, and this call is not one — it would change nothing, so it is refused rather than ignored.');
        }

        return $this->tagList($args, 'summary_tags');
    }

    /** @param  array<string, mixed>  $args */
    private function prNumber(array $args): ?int
    {
        if (! array_key_exists('pr_number', $args)) {
            return null;
        }
        $pr = $args['pr_number'];
        if (! is_int($pr) || $pr < 1) {
            throw new ToolRefusalException('board_search: `pr_number` must be a positive integer (a pull-request number) — '.json_encode($pr).' is not one. A decorated string (`"#42"`) is refused, never coerced.');
        }

        return $pr;
    }

    /** @param  array<string, mixed>  $args */
    private function nameContains(array $args): ?string
    {
        if (! array_key_exists('name_contains', $args)) {
            return null;
        }
        $raw = $args['name_contains'];
        $needle = is_string($raw) ? BoardToolArgs::trimmed($raw) : '';
        if ($needle === '') {
            throw new ToolRefusalException('board_search: `name_contains` must be a non-empty string. Omit it to filter on no name; an empty value is refused rather than ignored.');
        }
        if (str_contains($needle, '"') || preg_match('/[\x00-\x1F\x7F]/', $needle) === 1) {
            throw new ToolRefusalException('board_search: `name_contains` may not contain `"` or a control character — kanban\'s search term carries the text inside quotes, so either would end the term early and search for something else.');
        }
        if (mb_strlen($needle) > KanbanFieldLimits::NAME_MAX) {
            throw new ToolRefusalException('board_search: `name_contains` is '.mb_strlen($needle).' characters — a card name is at most '.KanbanFieldLimits::NAME_MAX.', so no card can contain it.');
        }

        return $needle;
    }

    /** @param  array<string, mixed>  $args */
    private function updatedSince(array $args): ?string
    {
        if (! array_key_exists('updated_since', $args)) {
            return null;
        }
        $raw = $args['updated_since'];
        $date = is_string($raw) ? BoardToolArgs::trimmed($raw) : '';
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new ToolRefusalException('board_search: `updated_since` must be a calendar DATE, `YYYY-MM-DD` — '.json_encode($raw).' is not one. kanban filters `updated_at` by date, not by time of day, so a timestamp is refused rather than rounded.');
        }

        return $date;
    }

    /** @param  array<string, mixed>  $args */
    private function flag(array $args, string $key): bool
    {
        if (! array_key_exists($key, $args)) {
            return false;
        }
        if (! is_bool($args[$key])) {
            throw new ToolRefusalException("board_search: `{$key}` must be a boolean when provided (an empty or null value included). Omit it for false.");
        }

        return $args[$key];
    }

    /** @param  array<string, mixed>  $args */
    private function lane(array $args): string
    {
        if (! array_key_exists('lane', $args)) {
            return self::LANE_ANY;
        }
        $lane = is_string($args['lane']) ? BoardToolArgs::trimmed($args['lane']) : null;
        if (! in_array($lane, self::LANES, true)) {
            throw new ToolRefusalException('board_search: `lane` must be one of '.implode(', ', array_map(fn (string $l): string => "`{$l}`", self::LANES)).' — `mine` is your own swimlane, `none` the cards in no lane, `any` every lane (the default).');
        }

        return $lane;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<string>
     */
    private function fields(array $args, bool $summary): array
    {
        if ($summary && array_key_exists('fields', $args)) {
            throw new ToolRefusalException('board_search: `fields` selects what each returned card carries, and a `summary: true` call returns no cards — it would change nothing, so it is refused rather than ignored.');
        }
        $fields = BoardCardProjection::fieldsArgument($args, $this->name());
        if ($fields === [] && ! $summary) {
            throw new ToolRefusalException('board_search: `fields: []` would return every match as an empty card. Name the fields you want, or pass `summary: true` for counts with no cards.');
        }

        return $fields;
    }

    /** @param  array<string, mixed>  $args */
    private function limit(array $args, bool $summary): int
    {
        if (! array_key_exists('limit', $args)) {
            return self::DEFAULT_LIMIT;
        }
        if ($summary) {
            throw new ToolRefusalException('board_search: `limit` is the number of cards returned, and a `summary: true` call returns none — it would change nothing, so it is refused rather than ignored.');
        }
        $limit = $args['limit'];
        if (! is_int($limit) || $limit < 1 || $limit > self::MAX_LIMIT) {
            throw new ToolRefusalException('board_search: `limit` must be an integer from 1 to '.self::MAX_LIMIT.' (kanban\'s own page size) when provided — it is the number of cards returned, newest first (default '.self::DEFAULT_LIMIT.'). To see past it, narrow the filters.');
        }

        return $limit;
    }

    /**
     * A 4xx the BOARD answered on one of this tool's reads, mapped to a named refusal. Which
     * statuses refuse and which are re-thrown is {@see BoardCallRefusal}'s.
     */
    private function readRefusal(RequestException $e, string $agentName, BoardReadRoute $route, string $what): \Throwable
    {
        $status = BoardCallRefusal::permanentOnRead($e);
        if ($status === null) {
            return $e;
        }

        Log::warning('board_search: the board refused a read', [
            'agent' => $agentName, 'route' => $route->name, 'status' => $status,
        ]);

        return BoardCallRefusal::readRefusal($this->name(), $route, $status, $what, 'so NO cards were returned');
    }
}
