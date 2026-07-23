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
                // and this command never edits authorized_keys) — GENERATING the guided
                // root-run setup script IS the provisioning action for this transport
                // (card 4952 / DL-226). Informational, exit 0.
                $this->printSshScaffold($cfg->agentName, $bt->sshAccount);

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
     * GENERATE + print the guided root-run SSH-transport setup script for an ssh agent
     * (card 4952 / DL-226). "Guided" = generate + verify, NOT silent automation: the
     * script is printed for the operator to review and run as root on THIS (bridge)
     * host (`sudo bash <script>`) — root surfaces stay operator-run. It wires
     * IDEMPOTENTLY (append-or-verify; it never clobbers existing config):
     *   (a) the forced-command authorized_keys line (enumerated no-* flags — FIPS-safe;
     *       the artisan path + agent are substituted, host B's PUBLIC key is a filled-in
     *       placeholder),
     *   (b) an sshd `Match User <account>` drop-in setting `PasswordAuthentication no`
     *       AND the three idle/concurrency backstop directives bridge:check now
     *       HARD-ASSERTS via probeSshdPosture (ClientAliveInterval / ClientAliveCountMax
     *       / MaxSessions, all > 0 — so this script's output PASSES that leg),
     *   (c) an sshd validate-then-reload (validate before reload so a bad drop-in never
     *       takes sshd down), and it documents
     *   (d) the FIPS ECDSA P-256 keygen to run FIRST on host B (NEVER ed25519 — a FIPS
     *       sshd rejects it).
     * The operator then certifies with `bridge:check --probe-tools-ssh=<user@host>`
     * (a real board_my_cards round-trip). The private key never touches the bridge.
     *
     * @param  ?string  $sshAccount  board_tools.ssh_account (null ⇒ the invoking run-user)
     */
    private function printSshScaffold(string $agentName, ?string $sshAccount = null): void
    {
        $account = $sshAccount ?? (string) (getenv('USER') ?: '<bridge-user>');
        $artisan = base_path('artisan');
        $forced = 'command="php '.$artisan.' bridge:tools-call --agent='.$agentName.'",no-pty,no-agent-forwarding,no-X11-forwarding,no-port-forwarding';

        $this->info("[{$agentName}] ssh transport — GENERATED setup script below (no secret is minted on the bridge; the private key lives on host B). Review it, save it, and run it on THIS host as root: `sudo bash <script>`. It is idempotent (append-or-verify; never clobbers existing config). Then certify from host B with `bridge:check --probe-tools-ssh=<user@this-host>`.");
        $this->line('');
        foreach ([
            '#!/usr/bin/env bash',
            "# bridge board-tools ssh setup — agent: {$agentName}, account: {$account}",
            '# GENERATED by bridge:provision-tools (DL-226). Run on the BRIDGE host as root.',
            'set -euo pipefail',
            '',
            "AGENT='{$agentName}'",
            "SSH_ACCOUNT='{$account}'",
            'DROPIN="/etc/ssh/sshd_config.d/${SSH_ACCOUNT}-board-tools.conf"',
            '',
            '# (d) On HOST B (the CALLING host) FIRST, generate a FIPS-approved key and copy',
            '#     its PUBLIC half here. ECDSA P-256 is FIPS-approved; a FIPS sshd REJECTS',
            '#     ed25519. Non-FIPS hosts may choose another algorithm. Run THIS on host B:',
            "#       ssh-keygen -t ecdsa -b 256 -f ~/.ssh/{$agentName}-board-tools -C '{$agentName}-board-tools'",
            '#     then paste the contents of that .pub file below.',
            "HOST_B_PUBKEY='PASTE HOST-B PUBLIC KEY HERE'",
            '',
            '# Guard on the SHAPE of an authorized_keys line, not on the placeholder',
            '# literal above: a naive first-match `sed s|<placeholder>|<key>|` rewrites the',
            '# value line AND any guard line that repeats the same literal, so such a guard',
            '# would compare the key against itself and always pass. A key-shape test greens',
            '# ONLY once a real ecdsa-/ssh-/sk- key is in place.',
            'case "$HOST_B_PUBKEY" in',
            '  ecdsa-*|ssh-*|sk-*) : ;;',
            '  *) echo "ERROR: set HOST_B_PUBKEY to host B\'s public key (an ecdsa-.../ssh-.../sk-... authorized_keys line) before running." >&2; exit 1 ;;',
            'esac',
            '',
            '# (a) Forced-command authorized_keys line (append-or-verify; enumerated no-*',
            '#     flags work on FIPS and non-FIPS seats). Denies pty + all forwarding.',
            "FORCED='{$forced}'",
            'HOME_DIR="$(getent passwd "$SSH_ACCOUNT" | cut -d: -f6)"',
            'if [ -z "$HOME_DIR" ]; then echo "ERROR: account $SSH_ACCOUNT has no home dir" >&2; exit 1; fi',
            'AUTHZ="${HOME_DIR}/.ssh/authorized_keys"',
            'install -d -m 700 -o "$SSH_ACCOUNT" "${HOME_DIR}/.ssh"',
            'touch "$AUTHZ"; chmod 600 "$AUTHZ"; chown "$SSH_ACCOUNT" "$AUTHZ"',
            'if grep -qF -- "bridge:tools-call --agent=${AGENT}\"" "$AUTHZ"; then',
            '  echo "authorized_keys: a forced-command line for agent ${AGENT} already present — leaving it (verify it pins the intended key)."',
            'else',
            '  printf \'%s\\n\' "${FORCED} ${HOST_B_PUBKEY}" >> "$AUTHZ"',
            '  echo "authorized_keys: appended the forced-command line for agent ${AGENT}."',
            'fi',
            '',
            '# (b) sshd Match drop-in (append-or-verify): closes the parallel password-auth',
            '#     path AND sets the idle/concurrency backstop bridge:check HARD-ASSERTS',
            '#     (ClientAliveInterval / ClientAliveCountMax / MaxSessions, all > 0).',
            'if [ -f "$DROPIN" ]; then',
            '  echo "sshd drop-in $DROPIN already exists — leaving it (verify it sets PasswordAuthentication no + ClientAliveInterval/ClientAliveCountMax/MaxSessions > 0)."',
            'else',
            '  cat > "$DROPIN" <<EOF',
            'Match User ${SSH_ACCOUNT}',
            '    PasswordAuthentication no',
            '    ClientAliveInterval 300',
            '    ClientAliveCountMax 2',
            '    MaxSessions 10',
            'EOF',
            '  chmod 644 "$DROPIN"',
            '  echo "sshd drop-in written: $DROPIN"',
            'fi',
            '',
            '# (c) Validate BEFORE reload so a bad drop-in never takes sshd down.',
            'sshd -t',
            'systemctl reload sshd 2>/dev/null || systemctl reload ssh 2>/dev/null || service ssh reload',
            '',
            'echo "Done. Certify from host B: bridge:check --probe-tools-ssh=<user@this-host>"',
        ] as $line) {
            $this->line($line);
        }
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
