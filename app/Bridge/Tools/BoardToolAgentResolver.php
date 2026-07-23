<?php

namespace App\Bridge\Tools;

use App\Bridge\Exceptions\InsecureSecretPermsException;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\BoardToolsConfig;
use App\Bridge\Support\SecretFile;
use Illuminate\Support\Facades\Log;

/**
 * Resolves a board-tools Bearer token to the agent it belongs to (DL-217) — a
 * NEW token→agent index built from the roster's `board_tools` blocks (the
 * AgentRegistry indexes ids only; it carries no tokens). Resolution is an
 * ITERATE-AND-hash_equals scan over the roster, NOT a map keyed by the secret (a
 * hash-keyed index and constant-time contradict each other; the roster is small
 * and the bearer sits behind the loopback gate regardless).
 *
 * Failure isolation (fail-closed at every layer):
 *  - A config-SHAPE error already threw at AgentConfig load (a provisioning bug
 *    must be loud) — this class only sees well-formed BoardToolsConfig blocks.
 *  - A RUNTIME missing/unreadable/insecure token file fails closed FOR THAT AGENT
 *    ONLY: it is excluded from the index and the reason accumulated (surfaced by
 *    bridge:check) — one misconfigured agent must not disable board tools
 *    fleet-wide.
 *  - A token-value COLLISION (two agents resolving to the same bearer) fails
 *    closed for BOTH: neither is indexed, and the collision is accumulated (same
 *    posture as the AgentRegistry id-collision bypass) — a shared secret can
 *    never authenticate as an ambiguous identity.
 */
final class BoardToolAgentResolver
{
    /**
     * @var list<array{agent: string, token: string, config: BoardToolsConfig}>
     */
    private array $entries = [];

    /**
     * @var list<array{type: string, message: string}> problems (bearer_unreadable |
     *                                                 collision) for bridge:check to render — TYPED so the check can split severity
     *                                                 (under default-ON both types FAIL: a dead/ambiguous bearer is a broken
     *                                                 enablement, not a transient board-state warn).
     */
    private array $problems = [];

    /**
     * @param  list<AgentConfig>  $configs
     */
    public function __construct(array $configs)
    {
        // First pass: read each enabled agent's token, isolating per-agent read
        // failures (accumulate, don't throw).
        $candidates = [];
        foreach ($configs as $cfg) {
            $bt = $cfg->boardTools;
            // `tokenPath === null` here is an INVARIANT ASSERTION, not a live
            // fallback: enabled === true ⟹ tokenPath !== null (BoardToolsConfig
            // guarantees it — a suppressed/disabled config is enabled=false). The
            // check is a belt-and-suspenders skip, unreachable for an enabled agent.
            if ($bt === null || ! $bt->enabled || $bt->tokenPath === null) {
                continue;
            }
            $token = $this->readToken($cfg->agentName, $bt->tokenPath);
            if ($token === null) {
                continue;   // problem already accumulated
            }
            $candidates[] = ['agent' => $cfg->agentName, 'token' => $token, 'config' => $bt];
        }

        // Second pass: exclude any token value shared by more than one agent
        // (fail closed for all of them).
        $countByToken = [];
        foreach ($candidates as $c) {
            $countByToken[$c['token']] = ($countByToken[$c['token']] ?? 0) + 1;
        }
        $collidedTokens = [];
        foreach ($candidates as $c) {
            if (($countByToken[$c['token']] ?? 0) > 1) {
                $collidedTokens[$c['token']] = true;

                continue;
            }
            $this->entries[] = $c;
        }
        foreach (array_keys($collidedTokens) as $token) {
            $sharers = [];
            $allFromChannel = true;
            foreach ($candidates as $c) {
                if ($c['token'] === $token) {
                    $sharers[] = $c['agent'];
                    if (! $c['config']->bearerFromChannel) {
                        $allFromChannel = false;
                    }
                }
            }
            sort($sharers);
            // Source-aware cure: when every sharer reuses its channel token as the
            // bearer, the fix is distinct CHANNEL tokens (there is nothing to mint);
            // otherwise at least one carries an explicit alias, so mint distinct ones.
            $cure = $allFromChannel
                ? 'Each reuses its channel token as the bearer — give each agent a DISTINCT channel token (channel.auth.token_path).'
                : 'Mint a DISTINCT token per agent (board_tools.auth.token_path).';
            $message = 'board_tools: the same auth token is shared by multiple agents ('.implode(', ', $sharers).') — board tools are DISABLED for all of them (an ambiguous bearer cannot authenticate). '.$cure;
            $this->problems[] = ['type' => 'collision', 'message' => $message];
            Log::warning($message);
        }
    }

    /**
     * Resolve a presented Bearer token to its agent, or null when it matches no
     * readable, non-colliding roster entry. hash_equals over each candidate — the
     * agent name is DERIVED here, never taken from the request.
     */
    public function resolve(string $bearer): ?ResolvedBoardToolAgent
    {
        if ($bearer === '') {
            return null;
        }
        foreach ($this->entries as $entry) {
            if (hash_equals($entry['token'], $bearer)) {
                return new ResolvedBoardToolAgent($entry['agent'], $entry['config']);
            }
        }

        return null;
    }

    /**
     * Problems accumulated at build (unreadable token files, token collisions) —
     * rendered by bridge:check so a silent per-agent lockout is loud pre-deploy.
     * Each entry is TYPED (bearer_unreadable | collision) so the check can split
     * severity.
     *
     * @return list<array{type: string, message: string}>
     */
    public function problems(): array
    {
        return $this->problems;
    }

    private function readToken(string $agentName, string $path): ?string
    {
        try {
            $token = SecretFile::read($path);
        } catch (InsecureSecretPermsException $e) {
            $this->problems[] = ['type' => 'bearer_unreadable', 'message' => "board_tools: agent {$agentName}: ".$e->getMessage().' — board tools disabled for this agent until fixed'];

            return null;
        }
        if ($token === null || $token === '') {
            $this->problems[] = ['type' => 'bearer_unreadable', 'message' => "board_tools: agent {$agentName}: no token at {$path} — board tools disabled for this agent until a token (chmod 600) is placed"];

            return null;
        }

        return $token;
    }
}
