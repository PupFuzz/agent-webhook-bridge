<?php

namespace App\Console\Commands\Bridge;

use App\Bridge\Support\CardTokenGrammar;
use App\Bridge\Support\RedactedErrorText;
use App\Bridge\Support\UntrustedText;
use App\Bridge\Writeback\GitHubReadClient;
use App\Bridge\Writeback\GitHubTokenResolver;
use App\Bridge\Writeback\KanbanClient;
use App\Bridge\Writeback\MappedBoardGuard;
use App\Bridge\Writeback\WritebackClientFactory;
use App\Bridge\Writeback\WritebackConfig;
use App\Bridge\Writeback\WritebackMapping;
use Throwable;

/**
 * IS THIS INSTALL EXPOSED TO THE ONE-REPO-ONE-BOARD DEFECT? (card#9850 / DL-404)
 *
 * ⛔ THE DEFECT IT MEASURES. `writeback.json` keyed its mapping by REPO and each mapping
 * carried exactly one `board_id`. A coordination repo's own pull requests cite cards on the
 * SPRINT boards, not on the coordination board its mapping names — so every such merge wrote
 * to the mapped board, or nowhere, and the sprint card the branch cited was never touched.
 * The board then reports as unfinished work that is merged and shipped, and the sprint
 * burn-down is derived from that board. The fix is the optional `boards` key; this command
 * answers, per install, whether that key is NEEDED and not yet present.
 *
 * ⛔⛔ WHY IT IS A COMMAND AN OPERATOR RUNS AND NOT A FLEET NUMBER SOMEBODY DERIVES. An
 * install's `writeback.json` lives on that install's own machine and there is no cross-install
 * read path — so the exposed FLEET is not derivable from any one seat, and this command does
 * not pretend otherwise: its closing line states its population, its evaluated count and its
 * unreachable count, and then says *Fleet-wide: not derivable* in as many words. Running it on
 * N installs and adding up is the only route to a fleet figure, and N installs is still not a
 * fleet.
 *
 * ⛔⛔ AND IT NEVER LEARNS WHERE AN OFF-SET CARD ACTUALLY IS. The obvious implementation asks
 * kanban which board each cited card is on — an UNSCOPED read of an id parsed out of
 * author-controlled text, against a kanban id space that is GLOBAL across the instance, which
 * is the exact read `MappedBoardGuard` exists to prevent (card#8375) and which would print a
 * cross-install value onto stdout. So exposure is established the other way round and entirely
 * within the boundary: each cited card id is probed AGAINST THE MAPPING'S OWN DECLARED BOARDS,
 * with the same board-scoped lookup the runtime guard uses
 * ({@see MappedBoardGuard::locateOnDeclaredBoards}), and a card that resolves on none of them
 * is exposure. That establishes THAT the mapping cannot express the destination without ever
 * establishing — or printing — what the destination is.
 *
 * ⚑ The off-set card IDS are printed, and that is a considered line rather than an oversight:
 * they were read out of THIS operator's own repo's pull-request titles, so the operator can
 * already see them, and they are what makes the finding actionable (they are the cards to
 * check when deciding which board to add to `boards`). What is never printed is any board
 * those ids resolve on, because that was never measured. The distinction is DL-314's: the
 * local operator's surface may carry an id this bridge did not establish as its own; the
 * pushed channel may not, and nothing here pushes.
 */
class WritebackExposureCommand extends BridgeCommand
{
    protected $signature = 'bridge:writeback-exposure
        {--pull-sample=50 : how many of each repo\'s most recently updated closed pull requests to read}';

    protected $description = 'Report which of THIS install\'s writeback mappings cite cards off their declared boards';

    /** Verdicts, spelled once so the per-mapping lines and the tally cannot disagree. */
    private const EXPOSED = 'EXPOSED';

    private const NOT_EXPOSED = 'not exposed';

    private const UNREACHABLE = 'unreachable';

    public function handle(): int
    {
        $sample = max(1, (int) $this->option('pull-sample'));
        $configDir = (string) config('bridge.config_dir');
        $path = rtrim($configDir, '/').'/writeback.json';

        try {
            $writeback = $configDir === '' ? null : WritebackConfig::load($configDir);
        } catch (Throwable $e) {
            // A malformed file is fail-closed at load for the RUNTIME, and it is unmeasurable
            // here for the same reason: nothing can be said about mappings that did not parse.
            $this->error("writeback.json at {$path} could not be loaded: ".RedactedErrorText::of($e));
            $this->line('0 mappings reachable on this box, 0 evaluated, 0 exposed, 0 unreachable. Fleet-wide: not derivable.');

            return self::FAILURE;
        }
        if ($writeback === null || $writeback->mappings === []) {
            // NOT a clean bill. No writeback.json means the writeback is off on this install,
            // so there is no population to evaluate — which is a different sentence from
            // "evaluated, and none exposed", and the exit code says so.
            $this->error($writeback === null
                ? "no writeback.json at {$path} — the writeback is OFF on this install, so there is no mapping population to evaluate"
                : "writeback.json at {$path} declares no mappings, so there is no population to evaluate");
            $this->line('0 mappings reachable on this box, 0 evaluated, 0 exposed, 0 unreachable. Fleet-wide: not derivable.');

            return self::FAILURE;
        }

        $this->line('writeback multi-board exposure — THIS INSTALL ONLY');
        $this->line("  population: every mapping in {$path}");
        $this->line("  per mapping: the {$sample} most recently UPDATED closed pull requests that merged, and every");
        $this->line('               `card#` token in their titles and head refs, each probed against THAT MAPPING\'S');
        $this->line('               OWN declared boards with a board-scoped (board, id) lookup');
        $this->newLine();

        $evaluated = 0;
        $exposed = 0;
        $unreachable = 0;
        $resolver = new GitHubTokenResolver;

        try {
            $kanban = WritebackClientFactory::make();
        } catch (Throwable $e) {
            // One cause for every mapping: no mapping can be evaluated, and reporting each as
            // separately unreachable would present one fault as N findings.
            $this->error('the kanban writeback client could not be built, so NO mapping could be evaluated: '.RedactedErrorText::of($e));
            $this->line(count($writeback->mappings).' mappings reachable on this box, 0 evaluated, 0 exposed, '
                .count($writeback->mappings).' unreachable. Fleet-wide: not derivable.');

            return self::FAILURE;
        }

        foreach ($writeback->mappings as $repo => $mapping) {
            $verdict = $this->evaluate($resolver, $kanban, (string) $repo, $mapping, $sample);
            $this->line(sprintf('  %-46s %s', (string) $repo, $verdict['line']));
            match ($verdict['verdict']) {
                self::EXPOSED => [$evaluated++, $exposed++],
                self::NOT_EXPOSED => [$evaluated++],
                default => [$unreachable++],
            };
        }

        $this->newLine();
        $this->line(sprintf(
            '%d mappings reachable on this box, %d evaluated, %d exposed, %d unreachable. Fleet-wide: not derivable.',
            count($writeback->mappings), $evaluated, $exposed, $unreachable,
        ));
        $this->line('LIMITS — read these before quoting the numbers above:');
        $this->line('  · The pull-request read is a SAMPLE, not a census. A mapping reported `not exposed` cites no');
        $this->line('    off-board card IN THAT WINDOW; widen it with --pull-sample before reading it as a clean bill.');
        $this->line('  · A mapping whose sample carries no `card#` token at all is reported as citing nothing, which');
        $this->line('    is a statement about the sample and not about the repo.');
        $this->line('  · `unreachable` means this box could not ASK — no github token for the repo, a refused read, a');
        $this->line('    board this writeback token cannot see. It is never counted as clean.');
        $this->line('  · Exposure is established by a card resolving on NONE of the mapping\'s declared boards. Which');
        $this->line('    board it is really on was NOT measured and is deliberately not obtainable here: learning it');
        $this->line('    takes an unscoped read of an author-supplied id (card#8375), and a value that reaches stdout');
        $this->line('    has already crossed that boundary.');
        $this->line("  · This is ONE install. {$path} is not visible from any other seat, so no count here");
        $this->line('    generalises. Run it per install and report each separately.');

        return $unreachable > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * One mapping's verdict, and the sentence that justifies it.
     *
     * @return array{verdict: string, line: string}
     */
    private function evaluate(GitHubTokenResolver $resolver, KanbanClient $kanban, string $repo, WritebackMapping $mapping, int $sample): array
    {
        $boards = $mapping->declaredBoardIds();
        $declares = 'declares board'.(count($boards) > 1 ? 's ' : ' ').implode('+', $boards);

        $token = $resolver->resolveFor($repo);
        if (! $token->ok()) {
            return $this->unreachable("{$declares} — no github token for this repo, so its merged pull requests could not be read ({$token->problem})");
        }

        try {
            $pulls = (new GitHubReadClient((string) $token->token))->recentlyMergedPullRequests($repo, $sample);
        } catch (Throwable $e) {
            return $this->unreachable("{$declares} — the merged pull requests could not be read: ".UntrustedText::forOperator(RedactedErrorText::of($e)));
        }

        $cited = [];
        foreach ($pulls as $pull) {
            // BOTH surfaces, because a repo can carry the token on either and a title-only
            // citation is exactly the shape a coordination PR uses. `parseAll`, not `parse`:
            // one PR citing two boards' cards would otherwise report on its leftmost token.
            foreach ([$pull['title']->rawForMatching(), $pull['head_ref']->rawForMatching()] as $text) {
                foreach (CardTokenGrammar::parseAll($text) as $cardId) {
                    $cited[$cardId] = true;
                }
            }
        }
        $cited = array_keys($cited);

        if ($cited === []) {
            return ['verdict' => self::NOT_EXPOSED, 'line' => sprintf(
                '%s — %d merged PR(s) sampled, no `card#` token in any of them -> %s IN THIS SAMPLE (it cites nothing here, which is not the same as citing nothing)',
                $declares, count($pulls), self::NOT_EXPOSED,
            )];
        }

        $offSet = [];
        $unresolved = [];
        $declared = $mapping->perDeclaredBoard();
        foreach ($cited as $cardId) {
            try {
                $located = MappedBoardGuard::locateOnDeclaredBoards($kanban, $declared, $cardId);
            } catch (Throwable $e) {
                $unresolved[] = "card#{$cardId} (".UntrustedText::forOperator(RedactedErrorText::of($e)).')';

                continue;
            }
            if ($located['on'] !== null) {
                continue;
            }
            if ($located['refused'] !== null || $located['unfiltered']) {
                // A board that could not be asked, or an answer that establishes nothing, is
                // NOT evidence the card is off the declared set — reading it that way would
                // manufacture exposure out of an install fault.
                $unresolved[] = "card#{$cardId} (".($located['unfiltered']
                    ? 'the board-scoped lookup answered a row that does not name this card on that board, so it narrowed on neither term'
                    : 'a declared board refused the lookup').')';

                continue;
            }
            $offSet[] = $cardId;
        }

        if ($offSet !== []) {
            // A positive establishment stands on its own: whatever else could not be asked,
            // THIS repo demonstrably cites a card its mapping cannot name a destination for.
            return ['verdict' => self::EXPOSED, 'line' => sprintf(
                '%s — %d merged PR(s) sampled, %d cited card(s), %d on NO declared board (%s) -> %s',
                $declares, count($pulls), count($cited), count($offSet),
                implode(', ', array_map(static fn (int $id): string => "card#{$id}", $offSet)),
                self::EXPOSED,
            )];
        }
        if ($unresolved !== []) {
            return $this->unreachable(sprintf('%s — %d merged PR(s) sampled, %d cited card(s), and %d could not be placed: %s',
                $declares, count($pulls), count($cited), count($unresolved), implode('; ', $unresolved)));
        }

        return ['verdict' => self::NOT_EXPOSED, 'line' => sprintf(
            '%s — %d merged PR(s) sampled, %d cited card(s), every one on a declared board -> %s',
            $declares, count($pulls), count($cited), self::NOT_EXPOSED,
        )];
    }

    /** @return array{verdict: string, line: string} */
    private function unreachable(string $why): array
    {
        return ['verdict' => self::UNREACHABLE, 'line' => $why.' -> '.self::UNREACHABLE];
    }
}
