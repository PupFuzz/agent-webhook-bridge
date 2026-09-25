<?php

namespace App\Bridge\Writeback;

use App\Bridge\Support\CardTokenGrammar;
use App\Bridge\Support\DlTokenGrammar;

/**
 * The comment the bridge posts on a pull request whose merge or close could not be correlated to a
 * card (DL-390, card#9569): what was read, from where, on which board, why it failed, and what to
 * run. Until this existed the only record was a bridge log line (and, for the handler's refusals, a
 * `writeback_move_failed` alert), so the board could disagree with what shipped while nothing on
 * GitHub said so.
 *
 * ⛔ NO AUTHOR-CONTROLLED BYTE REACHES THE BODY, BY CONSTRUCTION RATHER THAN BY ESCAPING. A PR title
 * and a head ref are chosen by whoever opened the pull request, and a comment the bridge authors is
 * rendered as markdown under the token owner's GitHub identity — so an echoed `@mention` notifies,
 * an image link fetches, and an HTML comment can forge this class's own marker. Neither string is
 * quoted (GitHub already shows both on the page this comment lands on). A token is carried as a
 * KIND, an INTEGER and a SOURCE from a closed set, and re-spelled here (`card#<int>`, `DL-<int>`);
 * the outcome and cause are checked against closed sets; everything else rendered is an integer.
 * A DL is re-spelled from its digits too, and a sentence or a `--dl` remedy never takes one out of
 * the token list: it names the DL the classifier looked up (`dl`, offered to `--dl` only when
 * `title_closes_dl`) or the one the stamp offered (`stamp_dl`). The list is a listing, not a source.
 * This is the whitelist arm `UntrustedText`'s docblock prefers wherever a value's shape permits one:
 * an escape's blind spot is whatever its author failed to think of, a whitelist's is what it admits.
 *
 * ⛔ NOTHING THE BOARD KNOWS ABOUT ANOTHER PULL REQUEST OR BOARD IS RENDERED. On a board mapped by
 * several repos, the `pr_number` / `pr_url` a card already carries can name a pull request in a
 * PRIVATE repo, and this comment can land on a PUBLIC one. So a dropped ref is named by its KEY only,
 * and a card on a foreign board is never given that board's id.
 *
 * ⭐ ONE COMMENT PER (pull request, outcome). {@see marker()} is derived from the outcome alone and is
 * the comment's FIRST LINE, so a redelivery, a `bridge:replay`, a re-close or a reopen-then-close of
 * the same pull request re-derives the same marker and finds it already there
 * ({@see PrCorrelationCommenter}). A later failure for the same outcome with a different cause, or on
 * another card of a bundled DL, posts nothing more: it is in the bridge log.
 */
final class PrCorrelationComment
{
    /** The durable reaction the classifier emits for a failure it decides itself. */
    public const HANDLER = 'github_pr_correlation_comment';

    /** The payload key carrying the classifier's evidence to either handler. */
    public const EVIDENCE_KEY = 'pr_correlation';

    /** What every outcome's {@see marker()} starts with. */
    public const MARKER_PREFIX = '<!-- agent-webhook-bridge:pr-correlation ';

    /** The outcomes that comment: a merge or a close. `opened` / `reopened` / `started` never do. */
    public const OUTCOMES = [PrOutcome::INTEGRATION_MERGE, PrOutcome::RELEASE_MERGE, 'closed_unmerged'];

    /** A DL token parsed, no card on the mapped board carries it, and no card token parsed to fall back to. */
    public const DL_UNRESOLVED = 'dl_unresolved';

    /** No card was selected — no token parsed, or only a DL no card carries — and a card- or DL-shaped spelling that does not parse is present. */
    public const TOKEN_UNREADABLE = 'token_unreadable';

    /**
     * Every cause that comments. The handler-side ones ARE the `writeback_move_failed` reason
     * codes those refusals already emit, so the comment and the alert name one cause with one string.
     * The refusals about the INSTALL rather than the pull request (`mapped_board_unreadable_to_this_token`,
     * `board_scope_lookup_unfiltered`, a kanban 4xx, a malformed payload) are deliberately absent: the
     * PR author can do nothing about them, and a comment naming the card there would be a
     * wrong-but-specific accusation. A pin is not a cause either, but a pinned card is still stamped,
     * so a ref that stamp drops is reported like any other. Nor does a merge that claims to finish
     * nothing comment (DL-305), whether its card correlated or its token did not: the classifier
     * attaches no evidence there, since a title citing another card or DL is routine.
     */
    private const CAUSES = [
        self::DL_UNRESOLVED,
        self::TOKEN_UNREADABLE,
        'card_token_near_miss',
        MappedBoardGuard::REASON_ID_OUTSIDE_MAPPED_BOARD,
        // The multi-board sibling of the line above (card#9850 / DL-404). It is a member for
        // the same reason: the pull request cited a card this install does not write to, which
        // is something its author can see and fix. Its unreadable-board sibling
        // (`declared_board_unreadable_to_this_token`) is deliberately absent, exactly as
        // `mapped_board_unreadable_to_this_token` is — that one is about the INSTALL.
        MappedBoardGuard::REASON_ID_OUTSIDE_DECLARED_BOARDS,
        MappedBoardGuard::REASON,
        'card_token_uncorroborated',
        'correlation_ref_not_stamped',
    ];

    private const SOURCES = ['head branch', 'title'];

    private const KINDS = ['card', 'dl'];

    private const REF_KEYS = ['dl_number', 'pr_number', 'pr_url'];

    /**
     * @param  list<array{kind: string, id: ?int, parsed: bool, source: string}>  $tokens
     * @param  list<string>  $droppedRefs
     */
    private function __construct(
        public readonly string $repo,
        public readonly int $prNumber,
        public readonly string $outcome,
        private readonly string $cause,
        private readonly ?int $cardId,
        private readonly int $boardId,
        /**
         * @var list<int> every board this repo's mapping declares, mapped board first (card#9850).
         *                Read only for the declared-set refusal, which is decided BEFORE any narrowing;
         *                on a mapping narrowed onto an additional board (a later cause) it is not that
         *                list — {@see WritebackMapping::perDeclaredBoard()} carries `boards` forward
         */
        private readonly array $declaredBoardIds,
        /** $boardId is an ADDITIONAL declared board the card resolved on, not the repo's mapped board (card#9850) */
        private readonly bool $onAdditionalDeclaredBoard,
        private readonly ?int $stageId,
        private readonly array $tokens,
        private readonly array $droppedRefs,
        private readonly ?string $dl,
        private readonly bool $titleClosesDl,
        private readonly ?string $stampDl,
    ) {}

    public static function isCause(string $reason): bool
    {
        return in_array($reason, self::CAUSES, true);
    }

    /**
     * The evidence the classifier attaches to a merge or close: the PR number and every card- or
     * DL-shaped token on each surface, head branch first. `[]` for an outcome that never comments or
     * an event with no PR number, so every other target's payload is byte-identical to before.
     * Which spellings parse is {@see CardTokenGrammar}'s and {@see DlTokenGrammar}'s to say.
     *
     * @return array<string, array{pr_number: int, tokens: list<array{kind: string, id: ?int, parsed: bool, source: string}>}>
     */
    public static function evidence(string $outcome, ?int $prNumber, string $headRef, string $title): array
    {
        if ($prNumber === null || ! in_array($outcome, self::OUTCOMES, true)) {
            return [];
        }

        $tokens = [];
        foreach (['head branch' => $headRef, 'title' => $title] as $source => $text) {
            $card = CardTokenGrammar::parse($text);
            if ($card !== null) {
                $tokens[] = ['kind' => 'card', 'id' => $card, 'parsed' => true, 'source' => $source];
            } elseif (CardTokenGrammar::looksLikeCardToken($text)) {
                $tokens[] = ['kind' => 'card', 'id' => CardTokenGrammar::nearMissCardId($text), 'parsed' => false, 'source' => $source];
            }
            $dl = DlTokenGrammar::parse($text);
            if ($dl !== null) {
                $tokens[] = ['kind' => 'dl', 'id' => (int) substr($dl, strlen('DL-')), 'parsed' => true, 'source' => $source];
            } elseif (DlTokenGrammar::looksLikeDlToken($text)) {
                $tokens[] = ['kind' => 'dl', 'id' => null, 'parsed' => false, 'source' => $source];
            }
        }

        return [self::EVIDENCE_KEY => ['pr_number' => $prNumber, 'tokens' => $tokens]];
    }

    /**
     * Null when this target does not comment: an outcome outside {@see OUTCOMES}, a cause outside
     * {@see CAUSES}, or no evidence (a payload built by anything but the classifier's merge/close arms,
     * which is what keeps `bridge:reconcile` and every other caller of the move handler silent here).
     *
     * $mapping is the one the refusal was decided against — for a cause decided after the card
     * resolved on a declared board, the mapping NARROWED onto that board, so the board and stage
     * id this comment names are the ones the card's own board uses (card#9850 / DL-404).
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $refusalContext  `dropped` => the ref keys a stamp dropped
     */
    public static function fromPayload(array $payload, string $cause, WritebackMapping $mapping, array $refusalContext = []): ?self
    {
        $repo = $payload['repo'] ?? null;
        $outcome = $payload['outcome'] ?? null;
        $evidence = $payload[self::EVIDENCE_KEY] ?? null;
        if (! is_string($repo) || ! is_string($outcome) || ! in_array($outcome, self::OUTCOMES, true)
            || ! self::isCause($cause) || ! is_array($evidence) || ! is_int($evidence['pr_number'] ?? null)) {
            return null;
        }

        $cardId = $payload['card_id'] ?? null;
        $dropped = $refusalContext['dropped'] ?? null;
        $dl = self::dl($payload['dl'] ?? null);
        if ($cause === self::DL_UNRESOLVED && $dl === null) {
            return null;
        }

        return new self(
            $repo,
            $evidence['pr_number'],
            $outcome,
            $cause,
            is_int($cardId) || (is_string($cardId) && ctype_digit($cardId)) ? (int) $cardId : null,
            $mapping->boardId,
            $mapping->declaredBoardIds(),
            $mapping->isOnAdditionalDeclaredBoard(),
            $mapping->stageFor($outcome),
            self::renderableTokens($evidence['tokens'] ?? null),
            is_array($dropped) ? array_values(array_intersect(self::REF_KEYS, $dropped)) : [],
            $dl,
            ($payload['title_closes_dl'] ?? null) === true,
            self::dl($payload['stamp_dl'] ?? null),
        );
    }

    /** The comment's first line and its dedupe key: one per pull request and outcome. */
    public function marker(): string
    {
        return self::markerFor($this->outcome);
    }

    /**
     * {@see marker()} for $outcome, without a comment to render — the key a comment the bridge
     * already rendered is deduped and repaired by ({@see GitHubWriteDebt}).
     */
    public static function markerFor(string $outcome): string
    {
        return self::MARKER_PREFIX."outcome={$outcome} -->";
    }

    /**
     * Whether $body is one of these comments: it starts with the marker every outcome's marker
     * starts with, which is the same starts-with test the dedupe reads the pull request with. This
     * is the only comment the bridge posts to GitHub, so it is how the bridge knows its own post
     * when that post comes back to it as an `issue_comment` event.
     */
    public static function isBridgePost(string $body): bool
    {
        return str_starts_with($body, self::MARKER_PREFIX);
    }

    public function body(): string
    {
        [$headline, $why, $remedy] = $this->explain();
        [$lookedOn, $pointedAt] = $this->boardsLookedOn();
        $card = $this->cardId === null ? 'none' : (string) $this->cardId;
        $tokens = $this->tokens === []
            ? '  - none'
            : implode("\n", array_map(self::renderToken(...), $this->tokens));

        return <<<BODY
            {$this->marker()}
            <!-- cause={$this->cause} card={$card} -->
            **{$headline}**

            - **Event:** `{$this->outcome}` on this pull request (#{$this->prNumber})
            - {$lookedOn}
            - **Tokens read** (a card token in the head branch outranks one in the title; the title and branch name are not quoted here):
            {$tokens}
            - **Cause:** `{$this->cause}`. {$why}

            **Remedy**, with `kbcard` pointed at {$pointedAt} (`kbcard stages` maps a workflow stage id to the column name `--column` takes):

            ```
            {$remedy}
            ```

            <sub>Posted once per pull request and outcome by agent-webhook-bridge (DL-390).</sub>
            BODY;
    }

    /**
     * The "looked on" line and the board the remedy points `kbcard` at — the two places the body
     * names a board outside the cause sentence, so they say the SAME thing it does.
     *
     * A refusal across the declared set (card#9850 / DL-404) checked every declared board, and
     * its cause sentence names them all; naming only the mapped board here would contradict it.
     * The per-outcome stage clause is dropped on that cause and nowhere else: a stage id is
     * meaningful only on its own board, and this card was established on none of them, so
     * quoting the mapped board's would name a destination this refusal never had.
     *
     * A cause decided after the card resolved on an ADDITIONAL declared board names that board
     * and its own stage, and does not call it "the board this repository is mapped to" — it is
     * not. Every other cause keeps the single-board text to the byte.
     *
     * @return array{0: string, 1: string} the looked-on line (without its list marker), the remedy's board
     */
    private function boardsLookedOn(): array
    {
        if ($this->cause === MappedBoardGuard::REASON_ID_OUTSIDE_DECLARED_BOARDS) {
            $checked = $this->checkedBoards();

            return [
                "**Boards looked on:** {$checked}, the boards this repository's mapping declares, in the order they were checked.",
                "whichever of {$checked} holds the card this pull request finishes",
            ];
        }
        $stage = $this->stageId === null ? '' : " The `{$this->outcome}` outcome moves a card to workflow stage {$this->stageId}.";
        $which = $this->onAdditionalDeclaredBoard ? 'the declared board this card was found on' : 'the board this repository is mapped to';

        return [
            "**Board looked on:** board {$this->boardId}, {$which}.{$stage}",
            "board {$this->boardId}",
        ];
    }

    /** The declared boards, in the order they were checked — one spelling for every line that names them. */
    private function checkedBoards(): string
    {
        return implode(', ', array_map(static fn (int $id): string => "board {$id}", $this->declaredBoardIds));
    }

    /** @return array{0: string, 1: string, 2: string} headline, cause sentence, remedy commands */
    private function explain(): array
    {
        $board = "board {$this->boardId}";
        $card = $this->cardId === null ? 'the card' : "card#{$this->cardId}";
        $task = $this->cardId === null ? '<card-id>' : (string) $this->cardId;
        $byHand = "kbcard patch --task <card-id> --pr {$this->prNumber}\nkbcard move --task <card-id> --column <column>";
        $notMoved = 'Board not updated: no card was moved for this pull request.';

        return match ($this->cause) {
            self::DL_UNRESOLVED => [
                $notMoved,
                "No card on {$board} carries `dl_number` {$this->dl}, and no card token parsed to fall back to."
                    .($this->titleClosesDl ? '' : " The title does not close {$this->dl}, so the remedy does not stamp it."),
                $this->titleClosesDl
                    ? "kbcard patch --task <card-id> --dl {$this->dl} --pr {$this->prNumber}\nkbcard move --task <card-id> --column <column>"
                    : $byHand,
            ],
            self::TOKEN_UNREADABLE => [
                $notMoved,
                'A card- or DL-shaped token is present but does not parse, so no card was selected. Card tokens: '
                    .CardTokenGrammar::describe().'. DL tokens: '.DlTokenGrammar::describe().'.',
                $byHand,
            ],
            'card_token_near_miss' => [
                $notMoved,
                "A DL token selected {$card}, but a card-shaped token that does not parse appears to name a different card. Which card this pull request is about is unknown, so the move was refused.",
                $byHand,
            ],
            MappedBoardGuard::REASON_ID_OUTSIDE_MAPPED_BOARD => [
                $notMoved,
                "{$card} is not a card on {$board}: it does not exist there, or it is on a board this install does not write to. Nothing was read or written.",
                $byHand,
            ],
            MappedBoardGuard::REASON_ID_OUTSIDE_DECLARED_BOARDS => [
                $notMoved,
                // ⛔ It says WHICH boards were checked and never where the card is. The boards
                // are this install's own config; where the card actually lives was not measured,
                // and this comment is published on a PULL REQUEST — the widest surface any
                // writeback record reaches — so naming it would put a cross-install value on a
                // durable public page (card#8375, and the card#9850 non-goal).
                "{$card} is not a card on any board this install writes to for this repo (checked: {$this->checkedBoards()}"
                    .'). It does not exist on any of them, or it is on a board this install does not write to. Nothing was read or written.',
                $byHand,
            ],
            MappedBoardGuard::REASON => [
                $notMoved,
                "{$card} is on a different board than {$board}, so it was not moved.",
                $byHand,
            ],
            'card_token_uncorroborated' => [
                $notMoved,
                "{$card} is named only in the title, the head branch does not name it, and {$card} already tracks a different pull request. A title can cite another card, so the move was refused.",
                "# only if this pull request does finish {$card}:\nkbcard move --task {$task} --column <column>",
            ],
            default => $this->unstampedRef($card, $task),
        };
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function unstampedRef(string $card, string $task): array
    {
        $keys = $this->droppedRefs === [] ? 'correlation ref' : implode(' and ', array_map(static fn (string $k): string => "`{$k}`", $this->droppedRefs));
        $was = count($this->droppedRefs) > 1 ? 'were' : 'was';
        $notRecorded = "the {$keys} this pull request carries {$was} not recorded on {$card}";
        $why = "{$card} already carries a different {$keys}, and the first value written wins, so {$notRecorded}. Nothing else is said here about the card's refs, and its stage is decided separately.";
        $prRefDropped = $this->droppedRefs === [] || array_intersect(['pr_number', 'pr_url'], $this->droppedRefs) !== [];

        if ($this->outcome === 'closed_unmerged' && $prRefDropped) {
            $stage = $this->stageId === null ? 'its `closed_unmerged` stage' : "workflow stage {$this->stageId}";

            return [
                "Check {$card}: it tracks a different pull request than the one just closed.",
                $why." If the pull request {$card} tracks supersedes this one, this close may have moved {$card} to {$stage} even though its work continues there.",
                "kbcard show --task {$task}\n# if this close moved it, put it back:\nkbcard move --task {$task} --column <the column it was in>",
            ];
        }

        $flags = array_filter([
            $prRefDropped ? "--pr {$this->prNumber}" : '',
            in_array('dl_number', $this->droppedRefs, true) && $this->stampDl !== null ? '--dl '.$this->stampDl : '',
        ]);

        return [
            "Correlation incomplete: {$notRecorded}.",
            $why,
            "kbcard show --task {$task}\n# only if what this pull request carries should replace what {$card} carries:\nkbcard patch --task {$task} ".implode(' ', $flags),
        ];
    }

    /** A `DL-<digits>` value from the payload, re-spelled from its digits; null for anything else. */
    private static function dl(mixed $value): ?string
    {
        return is_string($value) && preg_match('/\ADL-(\d+)\z/', $value, $m) === 1 ? 'DL-'.$m[1] : null;
    }

    /** @param  array{kind: string, id: ?int, parsed: bool, source: string}  $t */
    private static function renderToken(array $t): string
    {
        $from = "from the {$t['source']}";
        if ($t['parsed'] && $t['id'] !== null) {
            return '  - `'.($t['kind'] === 'dl' ? 'DL-' : 'card#')."{$t['id']}` {$from}";
        }
        $names = $t['kind'] === 'card' && $t['id'] !== null ? " (it appears to name card {$t['id']})" : '';

        return '  - a '.($t['kind'] === 'dl' ? 'DL' : 'card')."-shaped token {$from} that does not parse{$names}";
    }

    /** @return list<array{kind: string, id: ?int, parsed: bool, source: string}> */
    private static function renderableTokens(mixed $tokens): array
    {
        $out = [];
        foreach (is_array($tokens) ? $tokens : [] as $t) {
            if (is_array($t)
                && in_array($t['kind'] ?? null, self::KINDS, true)
                && in_array($t['source'] ?? null, self::SOURCES, true)
                && is_bool($t['parsed'] ?? null)
                && (($t['id'] ?? null) === null || is_int($t['id']))) {
                $out[] = ['kind' => $t['kind'], 'id' => $t['id'] ?? null, 'parsed' => $t['parsed'], 'source' => $t['source']];
            }
        }

        return $out;
    }
}
