<?php

namespace App\Bridge\Tools;

use App\Bridge\Exceptions\InsecureSecretPermsException;
use App\Bridge\Exceptions\UnreadableSecretException;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\BoardToolsConfig;
use App\Bridge\Support\Finding;
use App\Bridge\Support\PathVisibility;
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
     * @var list<Finding> problems for bridge:check to render, in TWO groups that the severity
     *                    splits on — is the fault the same for every reader, or only for this one?
     *                    MEASURED faults, both FAIL under default-ON — a dead or ambiguous bearer
     *                    is a broken ENABLEMENT, never a transient board-state warn (card#5292):
     *                    an absent bearer under a readable dir, an insecure-perms bearer, and a
     *                    token collision.
     *                    THIS BUILD'S OWN BLINDNESS, `unvalidated`, never flips the exit: a token
     *                    file this process could not SEE (card#5698), and one that is present and
     *                    this process could not READ (card#5778). Neither says anything about the
     *                    web user's read, and the controller builds this class as that user.
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
            // This is the HTTP token→agent index — an ssh-transport agent must NEVER
            // be reachable by presenting a bearer over the HTTP door (its identity is
            // the pinned forced-command --agent, not a token). The `transport !==
            // 'http'` exclusion is the load-bearing skip. `tokenPath === null` is then
            // a belt-and-suspenders skip: for an enabled HTTP agent the invariant
            // enabled ⟹ tokenPath !== null holds, and a contradictory ssh+auth block
            // is already loader-rejected (DR2-1) — so this second clause only ever
            // fires on the transport-excluded rows the first clause already caught.
            if ($bt === null || ! $bt->enabled || $bt->transport !== 'http' || $bt->tokenPath === null) {
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
            $this->problems[] = Finding::fail($message);
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
     *
     * EACH PROBLEM CARRIES ITS OWN SEVERITY, because only the build knows which of them
     * it actually MEASURED. This is the discrimination card#5292 removed (DL-251) and
     * expressly invited back — "restore it if and when a caller genuinely needs to
     * discriminate" — and it reopens NEITHER of the two rulings behind that removal:
     * `bearer_unreadable` and `collision` are two MEASURED faults, their both-FAIL
     * disposition is DL-217 v7's, and both still FAIL. What card#5698 adds is a THIRD
     * situation neither ruling covered — the build could not see the token file at all —
     * where a `fail` is a false accusation about a runtime this process cannot observe
     * (the controller builds its own resolver as the WEB user, which may read the file
     * fine). card#5778 adds the FOURTH on the same ground: the file IS there and this
     * process could not READ it. That state used to reach no severity at all, because it
     * left `SecretFile::read` as an undocumented `ErrorException` — 500ing the board-tools
     * door and aborting `bridge:check` rather than being classified. Note this SPLITS the
     * old `bearer_unreadable` label: the perms half stays a measured `fail`, the
     * uid-relative half is now `unvalidated`. Carrying {@see Finding} rather than the old
     * `type` string also hands the consumer the severity directly instead of a tag it has
     * to interpret.
     *
     * @return list<Finding>
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
            $this->problems[] = Finding::fail("board_tools: agent {$agentName}: ".$e->getMessage().' — board tools disabled for this agent until fixed');

            return null;
        } catch (UnreadableSecretException $e) {
            // The SAME ruling as the not-visible arm below, one gate earlier: a mode that
            // stopped THIS process is no evidence about the receiver's read, and this
            // class is built in both places — by `bridge:check` as the operator, and by
            // the controller as the web user. `unvalidated`, not `fail`, or the operator
            // is told an agent's tools are broken on a runtime this run never observed.
            $this->problems[] = Finding::unvalidated("board_tools: agent {$agentName}: ".$e->getMessage());

            return null;
        }
        if ($token === null || $token === '') {
            // A blank-but-readable file is MEASURED and keeps the definite claim; only an
            // unseeable one is unvalidated (card#5698).
            $this->problems[] = PathVisibility::unverifiedUnlessVisible($path, "board_tools: agent {$agentName}: bearer token at {$path}")
                ?? Finding::fail("board_tools: agent {$agentName}: no token at {$path} — board tools disabled for this agent until a token (chmod 600) is placed");

            return null;
        }

        return $token;
    }
}
