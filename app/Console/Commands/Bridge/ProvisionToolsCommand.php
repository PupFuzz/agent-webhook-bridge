<?php

namespace App\Console\Commands\Bridge;

use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\SecretFile;
use App\Bridge\Support\SubscriptionRegistry;
use Throwable;

/**
 * Mint the per-agent board-tools Bearer (DL-217) — the EXPLICIT-OVERRIDE path.
 * Under the default-ON model an agent reuses its channel token as the tools bearer
 * (nothing to mint); this command mints only for an agent that declares a DEDICATED
 * `board_tools.auth.token_path` (agents reusing the channel token are skipped —
 * their channel token is provisioned elsewhere). Idempotent: an existing 0600 token
 * is left alone
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

    protected $description = 'Mint the per-agent board-tools Bearer token(s) for agents with a DEDICATED board_tools.auth.token_path (DL-217; channel-token-reuse agents need no mint)';

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

            if (! $bt->enabled) {
                // Disabled (enabled: false) OR default-suppressed (a default-on block
                // that could not be satisfied). Nothing to mint either way; the
                // suppressed case is where bridge:check FAILs, so name it.
                if ($bt->suppressedReason !== null) {
                    $this->warn("{$label} SKIP — board_tools present but default-on could not be satisfied ({$bt->suppressedReason}); bridge:check FAILs on it. Fix the config, or set enabled: false to stage it silently.");
                } else {
                    $this->info("{$label} SKIP — board_tools is disabled (enabled: false); nothing to mint.");
                }

                continue;
            }
            if ($bt->transport === 'ssh') {
                // ssh agents mint NO bridge-side secret (the private key is host-B's,
                // and this command never edits authorized_keys) — scaffold-by-print IS
                // the provisioning action for this transport (card 4952). Informational,
                // exit 0.
                $this->printSshScaffold($cfg->agentName);

                continue;
            }
            if ($bt->tokenPath === null) {
                continue;   // defensive: an enabled HTTP agent ⇒ tokenPath non-null by construction (ssh agents handled above)
            }
            if ($bt->bearerFromChannel) {
                // Default path: the tools bearer reuses the agent's channel token —
                // there is no dedicated board_tools.auth.token_path to mint here (the
                // channel token is provisioned elsewhere). This command is the
                // explicit-override path only.
                $this->info("{$label} SKIP — board_tools reuses the channel token as its bearer (no explicit board_tools.auth.token_path) — nothing to mint here.");

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
            if ($bt === null || ! $bt->enabled || $bt->tokenPath === null || $bt->bearerFromChannel
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

    /**
     * Scaffold-by-print the SSH-forced-command transport wiring for an ssh agent
     * (card 4952). Prints, never edits: the authorized_keys pinned line to install on
     * THIS (bridge) host, the FIPS-approved keygen recipe to run on host B (NEVER
     * ed25519 — a FIPS sshd rejects it), the `Match User` sshd hardening drop-in, and
     * the `sudo bridge:check` certification gate (DR2-3a: ssh enablement is NOT
     * certified until a root bridge:check verifies the sshd posture).
     */
    private function printSshScaffold(string $agentName): void
    {
        $user = (string) (getenv('USER') ?: '<bridge-user>');
        $artisan = base_path('artisan');
        $this->info("[{$agentName}] ssh transport — scaffold only (no secret is minted on the bridge; the private key lives on host B). Install the pieces below, then run `sudo bridge:check` to certify.");
        $this->line('');
        $this->line('# 1. On host B (the CALLING host), generate a FIPS-approved board-tools key.');
        $this->line('#    ECDSA P-256 is FIPS-approved; a FIPS sshd REJECTS ed25519 — do not use it here.');
        $this->line("    ssh-keygen -t ecdsa -b 256 -f ~/.ssh/{$agentName}-board-tools -C '{$agentName}-board-tools'");
        $this->line('#    (Non-FIPS host may choose another algorithm; the recipe is parameterized, not pinned.)');
        $this->line('');
        $this->line("# 2. On THIS (bridge) host, add ONE line to the {$user} authorized_keys, forcing");
        $this->line('#    the command and denying pty + all forwarding (the enumerated form works on');
        $this->line('#    FIPS and non-FIPS; restrict is the non-FIPS shorthand). Paste host B public key:');
        $this->line('    command="php '.$artisan.' bridge:tools-call --agent='.$agentName.'",no-pty,no-agent-forwarding,no-X11-forwarding,no-port-forwarding <PASTE HOST-B PUBLIC KEY>');
        $this->line('');
        $this->line('# 3. Scope the sshd hardening to the bridge user with a Match drop-in (box-wide is');
        $this->line('#    opt-in). Closes the password-auth path that would bypass the forced command:');
        $this->line("    # /etc/ssh/sshd_config.d/{$user}-board-tools.conf");
        $this->line("    Match User {$user}");
        $this->line('        PasswordAuthentication no');
        $this->line('');
        $this->line('# 4. Certify (the sshd-posture legs need root):');
        $this->line('    sudo bridge:check');
    }

    private function printSkeleton(string $agentName): void
    {
        $this->warn("[{$agentName}] no board_tools block — add the block below to {$agentName}.yml (fill the placeholders). With an HTTP channel that already sets channel.auth.token_path, board tools default ON and reuse that channel token as the bearer — nothing to mint. Add an explicit auth.token_path (commented below) only for a DEDICATED tools bearer, then re-run this command to mint it. This command does NOT edit YAML.");
        foreach ([
            'board_tools:',
            '  board_id: <your product board id>',
            '  swimlane_id: <your own swimlane id — the forced write scope + read-isolation boundary>',
            '  create_stage_id: <the stage new cards land in, e.g. backlog>',
            '  # optional:',
            '  # shared_swimlane_id: <a shared cross-system swimlane also included in board_my_cards>',
            '  # coord_board_id: <a coordination board to read cards addressed to you from>',
            '  # address_tags: ["repo:<self>"]   # requires coord_board_id',
            '  # For a DEDICATED tools bearer instead of reusing the channel token,',
            '  # add the two lines below and re-run bridge:provision-tools to mint it (chmod 600):',
            '  #   enabled: true',
            '  #   auth:',
            "  #     token_path: /abs/path/to/{$agentName}-board-tools-token",
        ] as $line) {
            $this->line($line);
        }
    }
}
