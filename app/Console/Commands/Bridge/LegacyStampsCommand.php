<?php

namespace App\Console\Commands\Bridge;

use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\RedactedErrorText;
use App\Bridge\Support\UntrustedText;
use App\Bridge\Tools\BoardCreateCardTool;
use App\Bridge\Writeback\KanbanClient;
use App\Bridge\Writeback\WritebackClientFactory;
use Throwable;

/**
 * HOW MANY CARDS STILL CARRY THE LEGACY STAMP TAGS ON THIS INSTALL'S BOARDS? (card#11987)
 *
 * `board_create_card` writes its provenance (`created_by`) and idempotency (`idempotency_key`) stamps
 * as payload keys on a board that registers them, and the legacy tags `created-by:<agent>` /
 * `idem:<agent>:<key>` on one that does not; every READ of a stamp accepts both spellings, so a card
 * minted before its board registered the keys is still the seat's and still correlates. This command
 * measures what retiring the tag half of those reads would orphan: per enabled board-tools agent, the
 * live and archived cards on its board carrying its legacy tags, and whether that board registers the
 * two keys yet (a board that does not keeps minting tags).
 *
 * The tag read may be retired only when every install that serves board tools reports zero here AND
 * every board registers both keys. ⛔ Like `bridge:writeback-exposure`, this is THIS install only: a
 * board-tools config lives on its own machine and there is no cross-install read path, so the closing
 * line says *Fleet-wide: not derivable*. An agent whose board could not be read is `unmeasured`, never
 * folded into zero, and makes the exit non-zero.
 */
class LegacyStampsCommand extends BridgeCommand
{
    protected $signature = 'bridge:legacy-stamps';

    protected $description = 'Count the cards on THIS install\'s board-tools boards that still carry the legacy created-by:/idem: stamp tags';

    public function handle(): int
    {
        $configDir = (string) config('bridge.config_dir');
        $agents = [];
        $unloadable = 0;
        foreach ($configDir === '' ? [] : (glob(rtrim($configDir, '/').'/*.yml') ?: []) as $file) {
            $name = basename($file, '.yml');
            try {
                $cfg = AgentConfig::load($name, $configDir);
            } catch (Throwable $e) {
                $this->error("agent config {$name} could not be loaded, so its board is not counted: ".RedactedErrorText::of($e));
                $unloadable++;

                continue;
            }
            $bt = $cfg->boardTools;
            if ($bt !== null && $bt->enabled && $bt->boardId !== null) {
                $agents[$name] = $bt->boardId;
            }
        }

        if ($agents === []) {
            $this->error("no agent config in {$configDir} enables board tools with a board, so there is no population to count");
            $this->line("0 agents with a board on this box, 0 measured, {$unloadable} unloadable. Fleet-wide: not derivable.");

            return self::FAILURE;
        }

        try {
            $client = WritebackClientFactory::make();
        } catch (Throwable $e) {
            $this->error('the kanban writeback client could not be built, so NO board could be read: '.RedactedErrorText::of($e));
            $this->line(count($agents).' agents with a board on this box, 0 measured, '.count($agents).' unmeasured. Fleet-wide: not derivable.');

            return self::FAILURE;
        }

        $this->line('legacy stamp tags — THIS INSTALL ONLY');
        $this->line('  per agent: the live and archived cards on its board tagged created-by:<agent> and idem:<agent>:*,');
        $this->line('             and whether the board registers the created_by / idempotency_key payload keys');
        $this->newLine();

        $measured = 0;
        $unmeasured = 0;
        $legacy = 0;
        $minting = 0;
        foreach ($agents as $name => $boardId) {
            try {
                [$counts, $registers] = $this->measure($client, $name, $boardId);
            } catch (Throwable $e) {
                $this->warn("  {$name} (board {$boardId}): unmeasured — ".UntrustedText::forOperator(RedactedErrorText::of($e)));
                $unmeasured++;

                continue;
            }
            $measured++;
            $legacy += array_sum($counts);
            $minting += in_array(false, $registers, true) ? 1 : 0;
            $this->line("  {$name} (board {$boardId}): created-by: {$counts['created-by live']} live, {$counts['created-by archived']} archived;"
                ." idem: {$counts['idem live']} live, {$counts['idem archived']} archived;"
                .' registers '.BoardCreateCardTool::CREATED_BY_KEY.': '.($registers[0] ? 'yes' : 'NO').', '
                .BoardCreateCardTool::IDEMPOTENCY_KEY.' (string, filterable): '.($registers[1] ? 'yes' : 'NO'));
        }

        $this->newLine();
        $this->line(count($agents)." agents with a board on this box, {$measured} measured, {$unmeasured} unmeasured; {$legacy} legacy-tagged cards, {$minting} measured boards still minting tags. Fleet-wide: not derivable.");

        return $unmeasured === 0 && $unloadable === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array{0: array<string, int>, 1: array{0: bool, 1: bool}}
     */
    private function measure(KanbanClient $client, string $agent, int $boardId): array
    {
        $fields = $client->boardCustomFields($boardId);
        if ($fields === null) {
            throw new \RuntimeException('the custom-field read carried no collection');
        }
        $counts = [];
        foreach (['created-by' => "created-by:{$agent}", 'idem' => BoardCreateCardTool::idemTag($agent, '*')] as $label => $tag) {
            foreach (['live' => false, 'archived' => true] as $side => $archived) {
                $total = $client->tagTotal($boardId, $tag, $archived)->total;
                if ($total === null) {
                    throw new \RuntimeException("the {$side} search for {$tag} carried no count (a kanban that predates paginated search)");
                }
                $counts["{$label} {$side}"] = $total;
            }
        }

        return [$counts, [
            $fields->accepts(BoardCreateCardTool::CREATED_BY_KEY, $agent),
            BoardCreateCardTool::writesIdempotencyKeyToPayload($fields, BoardCreateCardTool::idemValue($agent, 'k')),
        ]];
    }
}
