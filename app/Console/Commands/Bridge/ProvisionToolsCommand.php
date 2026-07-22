<?php

namespace App\Console\Commands\Bridge;

use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\SecretFile;
use App\Bridge\Support\SubscriptionRegistry;
use Throwable;

/**
 * Mint the per-agent board-tools Bearer (DL-217) for each agent that declares an
 * ENABLED `board_tools` block. Idempotent: an existing 0600 token is left alone
 * ("already minted"); an absent one is minted (cryptographically random, written
 * 0600); an existing group/world-readable one is a hard FAILURE (a co-tenant could
 * read it and drive the board as that agent — the DL-010 posture, mirroring
 * bridge:provision's InsecureSecretPermsException handling). A token value shared
 * by two agents fails BOTH closed by name — an ambiguous bearer authenticates as
 * NEITHER at request time (BoardToolAgentResolver), so surfacing the collision at
 * provision time beats letting the first live call refuse.
 *
 * The command NEVER edits an agent YAML: for an agent explicitly named with
 * --agent that has no board_tools block, it prints a paste-ready skeleton and
 * exits non-zero (nothing was provisioned). The token VALUE is never printed —
 * only its path.
 */
class ProvisionToolsCommand extends BridgeCommand
{
    protected $signature = 'bridge:provision-tools {--agent= : limit to one agent} {--dry-run : preview, change nothing}';

    protected $description = 'Mint the per-agent board-tools Bearer token(s) for agents with an enabled board_tools block (DL-217)';

    public function handle(): int
    {
        $configDir = (string) config('bridge.config_dir');
        if ($configDir === '') {
            $this->error('bridge.config_dir must be configured (set BRIDGE_DIR)');

            return self::FAILURE;
        }

        $all = (new SubscriptionRegistry($configDir))->agentConfigs();
        $only = $this->strOption('agent');
        $targets = $only !== null
            ? array_values(array_filter($all, fn (AgentConfig $a) => $a->agentName === $only))
            : $all;

        if ($only !== null && $targets === []) {
            $this->error("no agent config named '{$only}' in {$configDir}");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $rc = self::SUCCESS;

        // agent name => resolved token value (existing-secure or freshly-minted), for
        // the cross-agent collision scan after the per-agent pass.
        $tokenValues = [];

        foreach ($targets as $cfg) {
            $bt = $cfg->boardTools;
            $label = "[{$cfg->agentName}]";

            if ($bt === null) {
                if ($only !== null) {
                    // Explicitly named an agent with no block — scaffold it (print only,
                    // never edit the YAML) and fail (nothing was provisioned).
                    $this->printSkeleton($cfg->agentName);
                    $rc = self::FAILURE;
                } else {
                    $this->info("{$label} SKIP — no board_tools block; run bridge:provision-tools --agent={$cfg->agentName} for a paste-ready skeleton, then re-run to mint the bearer");
                }

                continue;
            }

            if (! $bt->enabled || $bt->tokenPath === null) {
                $this->info("{$label} SKIP — board_tools is present but disabled; set enabled: true (with an auth.token_path) to provision the bearer");

                continue;
            }

            $path = $bt->tokenPath;
            if (is_file($path)) {
                if (SecretFile::isInsecure($path)) {
                    $this->error("{$label} FAIL — ".SecretFile::permsMessage($path).' (a co-tenant could read this bearer and drive the board as this agent)');
                    $rc = self::FAILURE;

                    continue;
                }
                $value = SecretFile::read($path);
                if ($value === null) {
                    $this->error("{$label} FAIL — bearer file {$path} is empty; remove it and re-run to mint a fresh one");
                    $rc = self::FAILURE;

                    continue;
                }
                $this->info("{$label} already minted — {$path}");
                $tokenValues[$cfg->agentName] = $value;

                continue;
            }

            if ($dryRun) {
                $this->line("{$label} DRY-RUN — would mint a new bearer at {$path}");

                continue;
            }
            $value = bin2hex(random_bytes(32));
            $this->writeSecret($path, $value);
            $this->info("{$label} MINTED — {$path}");
            $tokenValues[$cfg->agentName] = $value;
        }

        return $this->reportCollisions($all, $tokenValues) ? $rc : self::FAILURE;
    }

    /**
     * A token value shared by ≥2 agents fails BOTH closed at request time (DL-217).
     * Read the FULL roster (not just the acted-on targets) so a --agent run still
     * catches a clash with a sibling; report a collision only when a target is
     * involved (an unrelated sibling pair is bridge:check's fleet-wide concern, not
     * this per-agent mint's). Returns false when a reported collision involved a target.
     *
     * @param  list<AgentConfig>  $all
     * @param  array<string, string>  $targetValues
     */
    private function reportCollisions(array $all, array $targetValues): bool
    {
        $rosterValues = $targetValues;
        foreach ($all as $cfg) {
            if (array_key_exists($cfg->agentName, $rosterValues)) {
                continue;
            }
            $bt = $cfg->boardTools;
            if ($bt === null || ! $bt->enabled || $bt->tokenPath === null
                || ! is_file($bt->tokenPath) || SecretFile::isInsecure($bt->tokenPath)) {
                continue;
            }
            try {
                $value = SecretFile::read($bt->tokenPath);
            } catch (Throwable) {
                continue;
            }
            if ($value !== null) {
                $rosterValues[$cfg->agentName] = $value;
            }
        }

        $agentsByValue = [];
        foreach ($rosterValues as $agent => $value) {
            $agentsByValue[$value][] = $agent;
        }

        $clean = true;
        foreach ($agentsByValue as $sharers) {
            if (count($sharers) < 2 || array_intersect($sharers, array_keys($targetValues)) === []) {
                continue;
            }
            sort($sharers);
            $this->error('FAIL — the same board_tools bearer value is shared by agents ('.implode(', ', $sharers).'); an ambiguous bearer authenticates as NONE of them at request time (DL-217 fail-closed). Mint a DISTINCT token per agent.');
            $clean = false;
        }

        return $clean;
    }

    private function writeSecret(string $path, string $value): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0o700, true);
        }
        // Umask-safe: pin 0600 on the empty file BEFORE the secret bytes land, so the
        // token is never briefly world-readable on a multi-tenant host.
        touch($path);
        chmod($path, 0o600);
        file_put_contents($path, $value, LOCK_EX);
    }

    private function printSkeleton(string $agentName): void
    {
        $this->warn("[{$agentName}] no board_tools block — add the block below to {$agentName}.yml (fill the placeholders), then re-run bridge:provision-tools --agent={$agentName} to mint the bearer. This command does NOT edit YAML.");
        foreach ([
            'board_tools:',
            '  enabled: true',
            '  auth:',
            "    token_path: /abs/path/to/{$agentName}-board-tools-token   # this command mints it (chmod 600)",
            '  board_id: <your product board id>',
            '  swimlane_id: <your own swimlane id — the forced write scope + read-isolation boundary>',
            '  create_stage_id: <the stage new cards land in, e.g. backlog>',
            '  # optional:',
            '  # shared_swimlane_id: <a shared cross-system swimlane also included in board_my_cards>',
            '  # coord_board_id: <a coordination board to read cards addressed to you from>',
            '  # address_tags: ["repo:<self>"]   # requires coord_board_id',
        ] as $line) {
            $this->line($line);
        }
    }
}
