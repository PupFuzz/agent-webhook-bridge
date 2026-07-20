<?php

namespace App\Bridge\Tools;

use App\Bridge\Exceptions\ToolRefusalException;
use App\Bridge\Support\BoardToolsConfig;
use App\Bridge\Writeback\CardCollapse;
use App\Bridge\Writeback\KanbanClient;
use Illuminate\Support\Facades\Log;

/**
 * board_create_card (DL-217) — create a card in the CALLING AGENT's OWN swimlane.
 * The write scope is forced from {@see BoardToolsConfig}: swimlane_id and
 * create_stage_id come from config, args cannot name a lane or stage. The card is
 * born UNTRIAGED deliberately (the fleet triage contract is "new cards surface to
 * the PM's triage pass" — an agent-captured card is exactly what triage is for),
 * so a caller-supplied `triaged` tag is REFUSED.
 *
 * Provenance + correlation are bridge-stamped, never caller-forgeable:
 *  - `created-by:<agent>` — the audit stamp; a caller cannot forge another
 *    agent's stamp (the `created-by:` prefix is reserved).
 *  - `idem:<agent>:<key>` — when an idempotency_key is passed, the correlation
 *    key for the FULL DL-198 pattern (correlate-before-create + post-create
 *    re-read + CardCollapse); the `idem:` prefix is reserved so a caller cannot
 *    poison a future idempotency probe.
 *
 * Caller tags matching a reserved prefix (`created-by:` / `idem:` / `id:` /
 * `type:`) or the bare `triaged` are refused (422-class) — no forging an audit
 * stamp, no poisoning idempotency, no hijacking the coord adoption/type keys, no
 * defeating born-untriaged. The idempotency_key is charset-constrained to
 * `[A-Za-z0-9.-]{1,64}`: it becomes a stored tag AND a `tags:"…"` search needle,
 * where `"`/`*`/`_`/`%` mis-split or wildcard-over-match the kanban tokenizer
 * (the exact-match branch has no ESCAPE clause), so an out-of-charset key could
 * correlate — and via the collapse leg, archive — the WRONG card.
 */
final class BoardCreateCardTool implements Tool
{
    /** Tag prefixes a caller may not supply (provenance / correlation / adoption). */
    private const RESERVED_PREFIXES = ['created-by:', 'idem:', 'id:', 'type:'];

    /** Bare tags a caller may not supply — `triaged` would defeat born-untriaged. */
    private const RESERVED_BARE = ['triaged'];

    public function call(array $args, BoardToolsConfig $cfg, KanbanClient $client, string $agentName): array
    {
        $title = $this->requireTitle($args);
        $description = $this->optionalDescription($args);
        $callerTags = $this->sanitizeCallerTags($args);
        $idemKey = $this->validateIdempotencyKey($args);

        $boardId = (int) $cfg->boardId;
        $tags = $callerTags;
        $tags[] = "created-by:{$agentName}";
        $idemTag = null;
        if ($idemKey !== null) {
            $idemTag = "idem:{$agentName}:{$idemKey}";
            $tags[] = $idemTag;

            // Correlate-before-create (DL-198 leg 1): a prior call with the same
            // key already minted the card → return it, no second create.
            $existing = $client->cardsByTag($boardId, $idemTag);
            if ($existing !== []) {
                sort($existing);
                $hitId = $existing[0];
                Log::info('board_create_card: idempotency hit — returning the existing card, no create', ['agent' => $agentName, 'idem_tag' => $idemTag, 'card_id' => $hitId]);

                return ['created' => false, 'idempotent_hit' => true, 'card_id' => $hitId, 'board_id' => $boardId, 'swimlane_id' => (int) $cfg->swimlaneId];
            }
        }

        $newId = $client->createCard(
            $boardId,
            (int) $cfg->createStageId,
            $title,
            [],                       // payload {} in v1 — no by-ref keys, no origin (identity rides the tag)
            $tags,
            (int) $cfg->swimlaneId,   // FORCED from config — a caller can never name a lane
            $description,
        );
        Log::info('board_create_card: created', ['agent' => $agentName, 'card_id' => $newId, 'board_id' => $boardId, 'stage' => (int) $cfg->createStageId, 'swimlane_id' => (int) $cfg->swimlaneId, 'idem_tag' => $idemTag]);

        // Post-create re-read + collapse (DL-198 leg 2): two concurrent same-key
        // calls can both correlate-empty and both create; the deterministic
        // lowest-id survivor is what closes that race.
        if ($idemTag !== null) {
            $live = $client->cardsByTag($boardId, $idemTag);
            if (count($live) > 1) {
                CardCollapse::toSurvivor($client, array_fill_keys($live, []), 'board_create_card', ['agent' => $agentName, 'idem_tag' => $idemTag]);
                // The collapse keeps the deterministic lowest id (so racing workers
                // converge); report that survivor, not the id kanban happened to
                // return to THIS worker.
                $newId = min($live);
            }
        }

        return ['created' => true, 'idempotent_hit' => false, 'card_id' => $newId, 'board_id' => $boardId, 'swimlane_id' => (int) $cfg->swimlaneId];
    }

    public function name(): string
    {
        return 'board_create_card';
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function requireTitle(array $args): string
    {
        $title = $args['title'] ?? null;
        if (! is_string($title) || trim($title) === '') {
            throw new ToolRefusalException('board_create_card: `title` is required and must be a non-empty string');
        }

        return $title;
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function optionalDescription(array $args): ?string
    {
        if (! array_key_exists('description', $args) || $args['description'] === null) {
            return null;
        }
        $description = $args['description'];
        if (! is_string($description)) {
            throw new ToolRefusalException('board_create_card: `description` must be a string when provided');
        }

        return $description;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<string>
     */
    private function sanitizeCallerTags(array $args): array
    {
        if (! array_key_exists('tags', $args) || $args['tags'] === null) {
            return [];
        }
        $raw = $args['tags'];
        if (! is_array($raw) || ! array_is_list($raw)) {
            throw new ToolRefusalException('board_create_card: `tags` must be a list of strings');
        }
        $tags = [];
        foreach ($raw as $tag) {
            if (! is_string($tag) || $tag === '') {
                throw new ToolRefusalException('board_create_card: `tags` entries must be non-empty strings');
            }
            foreach (self::RESERVED_PREFIXES as $prefix) {
                if (str_starts_with($tag, $prefix)) {
                    throw new ToolRefusalException("board_create_card: the tag `{$tag}` uses the reserved prefix `{$prefix}` and cannot be caller-supplied (provenance/correlation/adoption tags are bridge-stamped)");
                }
            }
            if (in_array($tag, self::RESERVED_BARE, true)) {
                throw new ToolRefusalException("board_create_card: the tag `{$tag}` is reserved — tool-created cards are born untriaged by design (they surface to the triage pass)");
            }
            $tags[] = $tag;
        }

        return $tags;
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function validateIdempotencyKey(array $args): ?string
    {
        if (! array_key_exists('idempotency_key', $args) || $args['idempotency_key'] === null) {
            return null;
        }
        $key = $args['idempotency_key'];
        if (! is_string($key) || preg_match('/^[A-Za-z0-9.-]{1,64}$/', $key) !== 1) {
            throw new ToolRefusalException('board_create_card: `idempotency_key` must match [A-Za-z0-9.-]{1,64} — other characters (notably " * _ %) are kanban tag-search metacharacters that could correlate the wrong card');
        }

        return $key;
    }
}
