<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\Support\ExternalReferenceNormalizer;
use App\Bridge\Support\Finding;
use App\Bridge\Writeback\WritebackConfig;
use Throwable;

/**
 * #3399: are this board's DL cards actually eligible to self-move? (DL-242 stage 3b)
 *
 * On a ref-mode writeback the by-ref lookup on a SHARED board filters by the event's repo
 * `source`, which the kanban derives from a card's `pr_url`. There a dl_number card with no
 * pr_url (source=null), or a pr_url whose owner/repo matches no repo mapped to that board,
 * is EXCLUDED by the lookup and silently never self-moves — indistinguishable from a
 * legitimate no-match in the dispatch ledger. That is the one writeback failure that stays
 * invisible even to an operator reading the ledger, which is why it is checked at config
 * time. Warn (never fail) so it is named + actionable (root cause closed by
 * `kbcard --pr-url` + the on-ramp docs). On a NON-shared board the qualifier is omitted
 * (DL-174) so source=null is fine and not warned; a derived source naming a repo NOT mapped
 * to the board still warns everywhere (operator error). Per board (deduped across mappings).
 * The three legs that DID NOT ANSWER report `unvalidated` instead, not `warn` (DL-251): a
 * board read that threw, a read that hit the page ceiling so the cards past it were never
 * examined, and a read that came back with NO CARDS AT ALL.
 *
 * WHY A 0-CARD READ WITHHOLDS THE ALL-CLEAR (DL-258, card#5701). The all-clear below is a
 * definite claim about a population, and an empty read is not evidence about a population —
 * kanban answers `200` with no rows identically for a genuinely empty board and for one
 * whose cards the writeback token's user cannot see, so `flagged === 0` was reached by
 * examining nothing. `Severity::Ok`'s own docblock states the invariant that made this a
 * defect rather than untidiness: ok "MUST NEVER carry a not-measured finding". It also
 * contradicted itself within one run, which was the tell — {@see WritebackBoardStateCheck}'s
 * DL-029 probe already prints "token sees 0 cards … EITHER the board is empty … OR the
 * token's user isn't a member" in the same output this leg was certifying green.
 *
 * NOTHING AVAILABLE HERE CAN DISAMBIGUATE IT, which is why the fix withholds rather than
 * re-measures. `visibility()` reports `total === 0` for both worlds and says so itself;
 * `byRefAvailable()` folds "board not accessible" together with "kanban predates by-ref" and
 * probes only the FIRST mapped board. A second read would buy no discrimination.
 *
 * THIS IS NOT IN TENSION WITH DL-256, though the two rulings point opposite ways on the word
 * "empty". There, an empty `data.swimlanes` was a REAL answer (measured present-and-empty on
 * every production board) and only an ABSENT collection was could-not-see — the payload
 * carried the distinction. Here it carries none: both worlds produce the same rows. Empty is
 * an answer when the response can still say which empty it is.
 *
 * THE MODE GATE MOVED IN WITH THE LEG. Inline it sat on the caller as
 * `if (correlation === 'ref') { checkWritebackSourceCoverage(…) }`; a check owns the
 * conditions that decide whether it applies (plan constraint (a) — "not applicable" is the
 * check's answer, never an absent registration).
 *
 * THE BOARD READ IS WRAPPED PER BOARD, as the inline leg already wrapped it: an escaping
 * throw would discard the findings this generator already yielded for earlier boards,
 * where the inline code had already printed them (see {@see WritebackBoardStateCheck} for
 * the full statement of that stage-3b constraint). Nothing else here reaches the network —
 * {@see ExternalReferenceNormalizer} is pure string work and
 * {@see WritebackConfig::boardIsShared} counts an in-memory array.
 */
final class WritebackSourceCoverageCheck implements Check
{
    public function id(): string
    {
        return 'writeback.source_coverage';
    }

    /**
     * @return iterable<Finding>
     */
    public function run(CheckContext $ctx): iterable
    {
        $writeback = $ctx->writeback;
        $client = $ctx->client;
        if ($writeback === null || $client === null || $writeback->mappings === []) {
            return;   // nothing to report; the run inventory records the disposition (DL-242 stage 8)
        }
        if (config('bridge.writeback.correlation', 'ref') !== 'ref') {
            return;   // scan mode does not repo-qualify, so there is no coverage gap to find
        }

        // repos mapped to each board, canonicalized to match the kanban's derived source.
        $refs = new ExternalReferenceNormalizer;
        $reposByBoard = [];
        foreach ($writeback->mappings as $repo => $mapping) {
            $reposByBoard[$mapping->boardId][] = $refs->canonicalizeSource((string) $repo);
        }
        foreach ($reposByBoard as $boardId => $repos) {
            try {
                $read = $client->readBoardCards($boardId);
            } catch (Throwable $e) {
                yield Finding::unvalidated("writeback: could not read board {$boardId} to check dl source coverage — ".$e->getMessage());

                continue;
            }
            $flagged = 0;
            foreach ($read['cards'] as $card) {
                $payload = is_array($card['payload'] ?? null) ? $card['payload'] : [];
                $dl = $payload['dl_number'] ?? null;
                if (! is_scalar($dl) || (string) $dl === '') {
                    continue;   // not a DL card
                }
                $id = is_scalar($card['id'] ?? null) ? (string) $card['id'] : '?';
                $externalLink = is_string($card['external_link'] ?? null) ? $card['external_link'] : null;
                $source = $refs->sourceFor($payload, $externalLink);
                if ($source === null) {
                    if ($writeback->boardIsShared((int) $boardId)) {
                        yield Finding::warn("writeback: card {$id} (DL {$dl}) on SHARED board {$boardId} has dl_number but source=null (no repo / pr_url / issue_url / html_url / external_link to derive it from) — the repo-qualified by-ref lookup EXCLUDES it, so it will NEVER self-move. Stamp a repo-qualified pr_url (kbcard patch --pr-url …/<owner>/<repo>/pull/0).");
                        $flagged++;
                    }
                    // non-shared board: the qualifier is omitted (DL-174) — null source correlates fine.
                } elseif (! in_array($source, $repos, true)) {
                    yield Finding::warn("writeback: card {$id} (DL {$dl}) on board {$boardId} has source={$source}, which matches no repo mapped to that board (".implode(', ', $repos).') — no mapped event will move it.');
                    $flagged++;
                }
            }
            if ($read['truncated']) {
                yield Finding::unvalidated("writeback: dl source-coverage check on board {$boardId} is INCOMPLETE — the board read hit the page ceiling; cards beyond it were not checked.");
            } elseif ($read['cards'] === []) {
                yield Finding::unvalidated("writeback: dl source-coverage on board {$boardId} is UNVERIFIED — the board read returned 0 cards, which kanban answers identically for an empty board and for one whose cards this token's user cannot see, so no card was examined and there is no all-clear to give. If you expect cards on that board, verify board_id and the token user's membership.");
            } elseif ($flagged === 0) {
                yield Finding::ok("writeback: dl_number cards on board {$boardId} all have a mapped source (self-move-eligible)");
            }
        }
    }
}
