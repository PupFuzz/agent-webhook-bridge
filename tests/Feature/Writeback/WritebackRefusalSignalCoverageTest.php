<?php

namespace Tests\Feature\Writeback;

use Tests\Support\SourceScan;
use Tests\TestCase;

/**
 * The class guard for DL-274/DL-285 (card#5312, card#5968).
 *
 * DL-274 fixed the minting primitive — `WritebackAlertNotifier::warnAndNotify` (and, since
 * DL-314, its withheld-id twin `warnAndNotifyCardIdWithheld`, which pairs identically and
 * differs only in what the PUSH carries) does the
 * durable log and the live push in one call, so an arm that uses it cannot log a refusal
 * without alerting on it. What that does NOT prevent is the next arm being written with a
 * bare `Log::warning(...)` and no notifier at all, which is exactly how 11 of 12 arms came
 * to be live-silent in the first place. Fixing N copies without a guard leaves the N+1th
 * to mint the bug again (canon #7).
 *
 * POPULATION, re-derived every run rather than counted once: every `Log::warning(` and
 * `Log::error(` call site in `app/Bridge/Handlers/Kanban*Handler.php`. `Log::error` is IN
 * the population deliberately — a permanent refusal that only writes an error line is the
 * same defect at a different level, and excluding it would report clean over a population
 * that had been narrowed to exclude the sibling.
 *
 * STATED BOUND: `Log::info` is NOT in the population. Those are MOSTLY the "not tracked" /
 * normal branches, which docs/writeback.md records as deliberately quiet — but the exclusion
 * is by LEVEL, not by kind, so a real refusal written at info level is invisible here. That
 * is not hypothetical: `kanban_coord_card_move`'s belongs-to-mapped-board refusal was exactly
 * that — it sat at info level through DL-285's sweep and stayed there until card#7133, with this
 * guard green over it the whole time. A green run says "no bare warning/error is unaccounted
 * for", never "no refusal is silent".
 *
 * ⭐ THAT BOUND STILL HOLDS, and it is why this class carries a SECOND, level-independent leg
 * (card#7138 / DL-292). Widening the population by LEVEL is not the closure — `Log::info` is
 * also the ~34 not-tracked / success / policy sites, and pulling them in would drown the
 * signal. The closure is by KIND, and it is structural: the belongs-to-mapped-board refusal
 * now lives in ONE primitive that owns the compare AND the report, and
 * {@see test_every_board_id_read_in_app_is_dispositioned} reds on a read of a card's
 * `board_id` that nobody has answered for — at any log level, or at none. It closes exactly one
 * refusal KIND; every other kind is still covered only by the level-keyed leg above.
 *
 * ⭐ THE TWO LEGS DO NOT SHARE A POPULATION, and since card#8530 the KIND leg's is the WHOLE of
 * `app/` on the tokenizer — the same derivation the pre-read enforcer uses, spelled once in
 * {@see SourceScan::sitesInApp}. It walked two hand-written globs before that
 * (the handlers, plus `app/Console/Commands/Bridge/*.php` since DL-301, `bridge:reconcile
 * --fix` being the first caller of the primitive outside the handler files), and carried a
 * STATED BOUND for a writer anywhere else in `app/` — a declaration with no check, over a
 * population the OTHER half of the same boundary was already deriving in full. The LEVEL-keyed
 * leg is unchanged and still sees only the six handlers: `KanbanClient`'s three correlation
 * diagnostics and `CardCollapse`'s archive-contract error are a real sibling shape at the
 * shared-client layer, where there is no `(repo, outcome)` tuple to dedup on; they are recorded
 * as a remainder on card#5968 rather than silently included or silently dropped.
 *
 * Both legs are ACCOUNTED-FOR lists rather than prohibitions, and both hold set equality, not
 * subset: a new bare log call or a new board read reds, and so does a list entry whose site has
 * gone (a stale exemption is its own defect).
 */
class WritebackRefusalSignalCoverageTest extends TestCase
{
    /**
     * Every bare `Log::warning`/`Log::error` in the writeback handlers, each with the
     * reason it does NOT route through the paired alert primitive.
     *
     * @var array<string, string>
     */
    private const ALLOWED = [
        // Paired by hand with notifyUnpark/notifyRevive: these are conditional OVERRIDE
        // notices with their own gate and their own signal `type`, not refusals (DL-274).
        'kanban_move_card: auto-unparked a card from a parked stage' => 'paired with notifyUnpark — a distinct signal type, not a refusal',
        'kanban_move_card: revived a card from the abandon stage on PR reopen' => 'paired with notifyRevive — a distinct signal type, not a refusal',
        // A documented FAIL-OPEN diagnostic: the move still happens, so nothing was refused.
        'kanban_move_card: could not read board stage order for the no-regression guard — allowing the move' => 'fail-open diagnostic — the move proceeds, no refusal to signal',
        // The SECOND fail-open route of the same guard (card#8761), and the one that had no
        // line at all: the preload read answered, with an empty order or without one of the
        // two stage ids. Same disposition as its twin above for the same reason — the move
        // proceeds — and it is listed BESIDE it deliberately: a guard with one instrumented
        // route and one silent one reports its common case as its rare one.
        'kanban_move_card: could not place this move in the board stage order for the no-regression guard — allowing the move' => 'fail-open diagnostic — the move proceeds, no refusal to signal',
        // TYPE-NARROWING for a state WritebackConfig::load refuses at parse time (it
        // rejects a promote_on_release mapping missing either stage, and validates every
        // stage value as numeric). An alert here could never be seen to fail (canon #9).
        'kanban_promote_released: mapping is missing the Shipped and/or Released stage; ignoring' => 'unreachable type-narrowing — load fails closed first (KanbanPromoteReleasedHandlerTest pins that)',
        // A config-gap diagnostic on a create that SUCCEEDS: the card is created in the
        // next lane the issue declares that the map does carry, else the default lane —
        // so nothing was refused and there is no failure to signal. Routing it would emit
        // a writeback_move_failed alert for a completed create (DL-286).
        'kanban_coord_card: the issue declares a lane that is not mapped in coord_card_lane_stage_ids — creating in the next mapped lane it declares, else the default lane; add the lane to the mapping if this board has that column' => 'config-gap diagnostic — the create proceeds in a mapped lane, no refusal to signal',
        // The move handler's twin of the line above (card#6393): the same config-gap
        // diagnostic on a revive/relane that SUCCEEDS — the card lands in the next lane
        // the issue declares that the map does carry, else the default lane. Nothing was
        // refused, so there is no failure to signal.
        'kanban_coord_card_move: the issue declares a lane that is not mapped in coord_card_lane_stage_ids — moving to the next mapped lane it declares, else the default lane; add the lane to the mapping if this board has that column' => 'config-gap diagnostic — the move proceeds to a mapped lane, no refusal to signal',
        // Log::error, and its twin lives in the shared CardCollapse primitive where there
        // is no (repo, outcome) dedup tuple. Recorded as a remainder on card#5968; routing
        // one copy and not the other would be a fresh asymmetry.
        'kanban_dependabot_card: archive returned 200 but the card is not archived (archived_at null) — kanban _action:archive contract may have changed; NOT retrying' => 'Log::error with a shared-primitive twin in CardCollapse — filed, not orphaned',
    ];

    /** The card field a board-membership decision has to read; the population's first half. */
    private const CARD_BOARD_FIELD = 'board_id';

    /** The refusal reason code the primitive owns; the population's second half. */
    private const REFUSAL_REASON = 'card_not_on_mapped_board';

    /**
     * Every site in `app/` naming those two, each with the SUBJECT it reads and — where that
     * subject is a card — what the read decides and why it is not a writeback refusal.
     *
     * ⛔ FOUR SITES READ A CARD ROW AND COMPARE IT TO A CONFIGURED BOARD OUTSIDE THE
     * PRIMITIVE, and all four sit on the BOARD-TOOLS door, whose config axis is
     * `BoardToolsConfig` (one agent, one board) and NOT `WritebackMapping` (one repo → one
     * board). That is the whole reason they are dispositioned rather than routed:
     * `MappedBoardGuard::refuses()` takes a mapping this layer does not have and cannot
     * invent, and its refusal mints a `writeback_move_failed` alert with a `(repo, outcome,
     * reason)` dedup tuple and a `WritebackBoardDivergence` row — for a call with no repo,
     * on three of the four for a call that SUCCEEDED. The entries say which.
     *
     * @var array<string, string>
     */
    private const DISPOSITIONED = [
        // ── The primitive itself: the one owner of the compare, the reason and the report.
        // A red HERE means the rule moved, which the two assertions below also catch.
        'Bridge/Writeback/MappedBoardGuard.php::(file scope)#1' => 'the REASON constant — the one place the refusal reason code is minted (DL-292)',
        'Bridge/Writeback/MappedBoardGuard.php::belongs#1' => 'THE predicate, verbatim as DL-292 minutes it: the `is_numeric` conjunct',
        'Bridge/Writeback/MappedBoardGuard.php::belongs#2' => 'THE predicate: the `(int)` compare against the mapping\'s board',
        'Bridge/Writeback/MappedBoardGuard.php::boardContext#1' => 'renders the card_board/mapped_board pair EVERY writeback record carries (DL-300); the verdict is `belongs()`\'s, not a second compare',

        // ── Board-tools door, `BoardToolsConfig` axis. Reads a CARD (or a card-shaped search
        // row) and compares it to the agent's configured board — the shape this leg exists to
        // find, dispositioned rather than routed for the reason in the const docblock.
        'Bridge/Tools/BoardCorrectCardTool.php::matchingRow#1' => 'REPORT+REFUSE, and the refusal is deliberately NON-DISCLOSING: re-establishes on the ROWS that the board-scoped `cardRowsOnBoard($boardId, $cardId)` search answered, because an unrecognised search term degrades to free text and still answers 200 (DL-326). No match → `notYoursMessage()`, nothing written. Routing it through the primitive would mint a writeback alert with no repo AND report the card\'s actual board, collapsing the not-yours / not-mine / unreadable-board answers DL-326 Decision 9 keeps identical',
        'Bridge/Tools/BoardCreateCardTool.php::placement#1' => 'REPORT-ONLY, not a refusal, by design: `is_numeric($card[...])` on the create/idempotency read-back, whose id came from `cardsByTag($cfg->boardId, …)` / `createCard($cfg->boardId, …)` (that producing read is the pre-read class\'s `placement#1` entry). The compare that follows warns and reports WHERE THE CARD ACTUALLY IS on a create that SUCCEEDED (DL-299) — and over TWO axes, board and swimlane, of which the primitive carries one',
        'Bridge/Tools/BoardCreateCardTool.php::placement#2' => 'the `(int)` half of the same read (DL-299)',
        'Bridge/Tools/BoardCreateCardTool.php::placement#3' => 'not a read at all: the field NAME as a label, listing which axis of the read-back was unusable in the report',
        'Bridge/Tools/BoardMyCardsTool.php::observedBoard#1' => 'REPORT-ONLY on a READ path: the board the returned ROWS are on, so the response states where they actually are and nothing is dropped (DL-302 Decision 3, which measured routing this through the primitive and DECLINED it — reshaping `belongs()` to serve a read projection is a gated change to a security guard)',

        // ── Not a card at all. These read a CONFIG block, a webhook envelope or a response
        // header that happens to spell the same key; there is no card and no tenant decision.
        'Bridge/Writeback/WritebackConfig.php::load#1' => 'CONFIG, not a card: `writeback.json`\'s mapping must declare a numeric board — this is the OTHER side of the compare, the value `$mapping->boardId` later carries',
        'Bridge/Writeback/WritebackConfig.php::load#2' => 'CONFIG: the `is_numeric` half of that parse-time validation',
        'Bridge/Writeback/WritebackConfig.php::load#3' => 'CONFIG: the cast that builds `WritebackMapping::$boardId`',
        'Bridge/Writeback/CoordConfigTerminals.php::terminalNamesForBoardId#1' => 'CONFIG: selects the `kanban.boards[]` entry describing a board id — a config block lookup, not a card membership decision',
        'Bridge/Writeback/CoordConfigTerminals.php::terminalNamesForBoardId#2' => 'CONFIG: the compare that picks that block',
        'Bridge/Writeback/CoordConfigTerminals.php::issuePopulationsForBoardId#1' => 'CONFIG: the same block selection for the issue-population axis',
        'Bridge/Writeback/CoordConfigTerminals.php::issuePopulationsForBoardId#2' => 'CONFIG: the compare that picks that block',
        'Bridge/Support/BoardToolsConfig.php::build#1' => 'CONFIG: the per-agent YAML key naming the board this agent writes to',
        'Bridge/Adapters/KanbanAdapter.php::parse#1' => 'WEBHOOK ENVELOPE: the delivery\'s scope id, read at the receiver door before any card exists',
        'Bridge/Classifiers/InboxOnlyClassifier.php::newCardIntent#1' => 'WEBHOOK PAYLOAD: copies the envelope\'s board onto the staged intent; nothing is compared and nothing is written',
        'Bridge/Classifiers/InboxOnlyClassifier.php::lifecycleIntent#1' => 'WEBHOOK PAYLOAD: the same copy on the lifecycle families',
        'Bridge/Tools/BoardToolsScopeHeader.php::read#1' => 'RESPONSE HEADER: the LEGACY spelling of another install\'s scope echo, read newest-first behind `configured_board_id` (DL-302/DL-304). It describes a responder, not a card',
        'Bridge/Tools/SshTransportProbe.php::probeLive#1' => 'PROBE EXPECTATION: the board an operator\'s expected-scope entry declares, compared to that header echo — config against config, on a diagnostic that writes nothing',
    ];

    public function test_every_bare_log_call_in_a_writeback_handler_is_accounted_for(): void
    {
        $files = glob(base_path('app/Bridge/Handlers/Kanban*Handler.php')) ?: [];
        $this->assertGreaterThanOrEqual(6, count($files), 'the handler population came back short — the glob, not the code, is what changed');

        $found = [];
        foreach ($files as $file) {
            foreach (self::bareLogCalls((string) file_get_contents($file), basename($file)) as $site) {
                $found[$site] = true;
            }
        }

        // Set equality in both directions, order-independent (glob order is filesystem
        // order). An empty $found cannot pass: ALLOWED is non-empty, so a scanner that
        // stopped working reds on the missing side rather than reporting a clean repo.
        $allowed = array_keys(self::ALLOWED);
        $actual = array_keys($found);
        sort($allowed);
        sort($actual);
        $this->assertSame(
            $allowed,
            $actual,
            'a Log::warning/Log::error in a writeback handler is not routed through a WritebackAlertNotifier pairing method (warnAndNotify / warnAndNotifyCardIdWithheld) and is not on the accounted-for list. '
            .'Either route it (a permanent refusal must emit a live signal — DL-274/DL-285) or add it to ALLOWED with the reason it is deliberately quiet.',
        );
    }

    /**
     * The KIND-keyed leg (card#7138 / DL-292, whole-`app/` since card#8530) — the one the
     * level-keyed leg above cannot be.
     *
     * The belongs-to-mapped-board (DL-009) security rule was written out three times, in three
     * non-equivalent spellings, which is how it came to carry two different report severities
     * (card#7133) — and the level-keyed leg was green over that for the defect's whole life,
     * because the third copy reported at `Log::info`. Both defects have ONE cause: the rule was
     * duplicated. `MappedBoardGuard` now owns the compare and the refusal report together, so a
     * fourth copy cannot be minted with a different predicate, a different reason code, or a
     * different log level — and this method is what makes that structural rather than a
     * convention.
     *
     * POPULATION, re-derived every run over the WHOLE of `app/` on PHP's own tokenizer
     * ({@see SourceScan::sitesInApp}): every occurrence of the card field name `board_id` or the
     * reason literal `card_not_on_mapped_board` as a string literal that is NOT an array-literal
     * KEY. A membership decision cannot be written without READING the field — the card's board
     * comes back under that key and nowhere else — so a fourth copy lands in the population
     * whatever level it reports at, whatever spelling reads the field (`$card['board_id']`,
     * `data_get($card, 'board_id')`, a bare argument), and wherever in `app/` it is written.
     * Each site is keyed `<path under app/>::<enclosing function>#<ordinal>` and answered on
     * {@see DISPOSITIONED} — set equality in both directions, so a new read reds and so does a
     * disposition whose site has gone.
     *
     * ⭐ WHY A DISPOSITION LIST AND NOT A WIDER GLOB (card#8530). Until this leg landed it walked
     * two hand-written globs — `app/Bridge/Handlers/Kanban*Handler.php` plus
     * `app/Console/Commands/Bridge/*.php` — and expected the set to be EMPTY, with a STATED BOUND
     * covering "a writer anywhere else in `app/`". That bound was a declaration with no check:
     * `BoardCreateCardTool::placement()` had been reading a card's board and comparing it to the
     * configured one, outside both globs, and the pre-read enforcer (card#8440) found it only
     * because IT walked all of `app/`. The two halves of ONE boundary derived two different
     * populations, so a `app/Bridge/Tools/` method that resolves an id board-scoped and then
     * hand-writes the compare to decide a WRITE was invisible to both.
     *
     * ⛔ WIDENING THE GLOB WAS MEASURED AND REJECTED, TWICE. DL-302 rejected it on the merits —
     * `app/Bridge/Tools/` has no `WritebackMapping` at all, so routing its compares through
     * `MappedBoardGuard::refuses()` would mint a writeback refusal alert, with its dedup tuple and
     * its outcome, for a call that SUCCEEDED — and card#8440's review rejected it again as a
     * shape: a wider glob with an empty expectation buys one exemption and no coverage, and its
     * false reds push authors to launder non-refusal compares through the refusal primitive.
     *
     * ⭐ WHAT THE LIST IS NOT is an exemption register. Every entry names the SUBJECT the site
     * reads — a card row? a config block? a webhook envelope? a response header? — and, where it
     * does read a card, what the compare decides and why that is not a writeback refusal. A read
     * whose subject is a CARD and whose compare decides a WRITE has exactly one correct
     * disposition, which is to route it through the primitive; adding it here instead is a review
     * event, not a formality.
     *
     * The expectation is no longer empty, so it is a measurement for the ordinary reason (both
     * directions of a set equality). Two further assertions keep it from going vacuous:
     * {@see test_the_board_scanner_discriminates_a_real_read_from_a_comment} proves the scanner
     * finds planted sites and skips prose, and the primitive-side assertions below red if the
     * guard is deleted or renamed rather than reporting a clean repo over a rule that has
     * vanished.
     *
     * STATED BOUNDS:
     *  - **`tests/` is not in the population**, and neither is anything outside `app/` — `bin/`,
     *    `database/`, `routes/`, `config/`.
     *  - **An array-literal KEY `'board_id' => …` is excluded** by construction. That is a field
     *    being WRITTEN into a payload or a response, not a card's board being read, and it is ~19
     *    sites of noise an author would learn to wave through. A membership decision must READ
     *    the field, and every read spelling still lands.
     *  - **The literal is matched EXACTLY.** A partial spelling — the `q=board_id={$id}` search
     *    term, the `configured_board_id` scope-header key, `coord_board_id` — is a different name
     *    and is not in the population.
     *  - **The list says what each site READS, not what it does with it.** A site dispositioned as
     *    reading a config block is taken on the reviewer's word, exactly as the pre-read class's
     *    board-scoped arm is; what is machine-checked is that the SET has not moved.
     */
    public function test_every_board_id_read_in_app_is_dispositioned(): void
    {
        $declared = array_keys(self::DISPOSITIONED);
        sort($declared);

        $derived = array_keys(SourceScan::sitesInApp(self::boardFieldSiteAt(...)));
        sort($derived);

        $this->assertSame(
            $declared,
            $derived,
            'the set of sites in app/ naming the card field `board_id` or the reason literal '
            .'`card_not_on_mapped_board` is not the set this class dispositions. A NEW site is an '
            .'unanswered question: if it READS A CARD\'s board to decide anything, the DL-009 '
            .'belongs-to-mapped-board rule and its refusal report belong to MappedBoardGuard::refuses() '
            .'(DL-292) — a second copy is how one guard came to carry two severities and three '
            .'predicates (card#7133). Route it through the primitive, or extend the primitive if the new '
            .'arm needs something it does not offer. Only if the site reads something that is NOT a card '
            .'on a mapped board (a config block, a webhook envelope, a response header), or reads a card '
            .'for something that is NOT a tenant refusal, does it belong on DISPOSITIONED — with the '
            .'subject it reads named in the value. A MISSING site is the other direction of the same '
            .'red: a disposition outliving the code it dispositioned is a stale exemption — delete it.',
        );

        // The other direction, and the reason this list is not merely a census: the rule still
        // EXISTS, and exists THERE. Without this, a derived set that matched the dispositions
        // would be reporting that NOBODY owns the belongs-to-mapped-board rule — indistinguishable
        // from one owner owning it.
        //
        // Asserted on CONTENT, never on line offsets. An offset pin reds on an unrelated docblock
        // edit, and its only remediation — "re-derive the line numbers" — is the same action that
        // absorbs a real deletion without a second thought; this repo already treats that shape as
        // a defect, which is why `bin/check-doc-refs.php` forbids line-number citations in the
        // `CLAUDE_*.md` set. It also pins what it claims: the old offset form stayed green with
        // `is_numeric` deleted, because the line still contained `board_id`.
        $primitive = (string) file_get_contents(base_path('app/Bridge/Writeback/MappedBoardGuard.php'));

        // ⛔ The predicate is pinned VERBATIM, and that coupling is deliberate: this is a
        // security guard whose accepted set is minuted in DL-292 with a vector table. Changing
        // the compare without moving the minute is exactly how the recorded approved set and
        // the shipped set drift apart. Reds if `is_numeric` is deleted — which the offset form
        // did not.
        $this->assertStringContainsString(
            'is_numeric($card[\'board_id\'] ?? null) && (int) $card[\'board_id\'] === $mapping->boardId',
            $primitive,
            'MappedBoardGuard::belongs() no longer spells the DL-292 predicate verbatim. If the compare was '
            .'CHANGED, that is a gated behaviour change to a security guard: update DL-292\'s vector table and '
            .'docs/writeback.md in the same commit, then this string. If the guard was REMOVED, the '
            .'disposition list above is a list of sites nothing polices any more and this class has stopped '
            .'guarding anything.',
        );
        $this->assertStringContainsString(
            "public const REASON = 'card_not_on_mapped_board';",
            $primitive,
            'MappedBoardGuard no longer owns the refusal reason code — if it moved, the set assertion '
            .'above is scanning for a literal that nothing in the tree mints any more.',
        );
    }

    public function test_the_board_scanner_discriminates_a_real_read_from_a_comment(): void
    {
        // The KIND scanner's own control. Every arm below would read as "there is a board_id
        // here" to a grep, and the ones that are NOT sites are the ways this population would
        // otherwise fill with noise an author learns to wave through. Both directions on one
        // fixture: prose mentions and array-literal keys are skipped, every read spelling is
        // found — subscript, bare argument, either quote style, at every log level and at none —
        // a partial spelling is not the field, the second read in a body gets its own ordinal,
        // and a read outside any named body is keyed to the file scope rather than dropped.
        $source = <<<'PHP'
        <?php
        class Fixture
        {
            /** A docblock naming `board_id` and 'card_not_on_mapped_board'. */
            public function decides(array $card, array $mapping): void
            {
                // A comment mentioning $card['board_id'] and 'card_not_on_mapped_board'.
                $payload = ['board_id' => $cfg['board_id'], 'reason' => 'card_not_on_mapped_board'];
                if (($card['board_id'] ?? null) !== $mapping->boardId) {
                    Log::info('quiet refusal', ['card_board' => $card['board_id'] ?? null]);
                }
                $wider = data_get($card, 'board_id');
                $doubleQuoted = $card["board_id"] ?? null;
                $unrelated = [$result['configured_board_id'] ?? null, $row['coord_board_id'] ?? null, "board_id={$id}"];
            }
        }

        $loose = fn (array $row) => $row['board_id'] ?? null;
        PHP;

        $this->assertSame(
            [
                'Fixture.php::decides#1' => 'board_id',
                'Fixture.php::decides#2' => 'card_not_on_mapped_board',
                'Fixture.php::decides#3' => 'board_id',
                'Fixture.php::decides#4' => 'board_id',
                'Fixture.php::decides#5' => 'board_id',
                'Fixture.php::decides#6' => 'board_id',
                'Fixture.php::(file scope)#1' => 'board_id',
            ],
            SourceScan::sites($source, 'Fixture.php', self::boardFieldSiteAt(...)),
        );
    }

    /**
     * The PREDICATE this leg owns: is the token at $index the card's board field name or the
     * refusal reason code, written as CODE rather than as an array-literal key?
     *
     * The two names are spelled here as LITERALS rather than read off `MappedBoardGuard`'s
     * constants. Pointing the scanner at the constant would make a rename of the reason code
     * invisible — the population would follow the rename and report clean — where the point of
     * this leg is that a change to the shared vocabulary is a review event. The assertions above
     * pin the same two strings against the primitive's source for the same reason.
     *
     * ⛔ `null` is the ONLY absence ({@see SourceScan::sites}); the value returned is the name
     * that matched, so a red says which vocabulary the new site joined.
     *
     * @param  list<array{0: int|string, 1: string}>  $tokens
     */
    private static function boardFieldSiteAt(array $tokens, int $index): ?string
    {
        if ($tokens[$index][0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }

        // An array-literal KEY is a field being WRITTEN — a payload, a response body, a
        // reason-string map. A membership decision has to READ the field, and every read
        // spelling still lands.
        if (($tokens[$index + 1][1] ?? null) === '=>') {
            return null;
        }

        foreach ([self::CARD_BOARD_FIELD, self::REFUSAL_REASON] as $name) {
            if ($tokens[$index][1] === "'".$name."'" || $tokens[$index][1] === '"'.$name.'"') {
                return $name;
            }
        }

        return null;
    }

    public function test_the_scanner_discriminates_a_real_call_from_a_comment(): void
    {
        // The instrument's own control. Without it, a scanner that matched nothing would
        // report a clean repo — and one that matched comment prose would report noise as
        // defects. Both directions are exercised on a fixture whose answer is known.
        $source = <<<'PHP'
        <?php
        // A prose mention of Log::warning( inside a line comment.
        /** A docblock mentioning Log::error( as well. */
        Log::info('not in the population at all', []);
        Log::warning('subject: a real bare warning', ['k' => 'v']);
                Log::error('subject: a real bare error', []);
        $this->alerts->warnAndNotify('subject: routed, not bare', [], 'r', 'o', null, 'x');
        PHP;

        $this->assertSame(
            ['subject: a real bare warning', 'subject: a real bare error'],
            self::bareLogCalls($source, 'Fixture.php'),
        );
    }

    /**
     * The message of every `Log::warning(`/`Log::error(` call in $source, skipping `//`
     * and `*` comment lines. A call whose message is not a single-quoted literal on the
     * same line degrades to `<file>:<line>` rather than vanishing — an unparsed site must
     * surface as an unexpected entry, never as a silent absence.
     *
     * @return list<string>
     */
    private static function bareLogCalls(string $source, string $file): array
    {
        $sites = [];
        foreach (SourceScan::codeLines($source) as $n => $line) {
            if (preg_match('/\bLog::(?:warning|error)\(/', $line) !== 1) {
                continue;
            }
            $sites[] = preg_match("/\bLog::(?:warning|error)\('((?:[^'\\\\]|\\\\.)*)'/", $line, $m) === 1
                ? str_replace("\\'", "'", $m[1])
                : $file.':'.$n;
        }

        return $sites;
    }
}
