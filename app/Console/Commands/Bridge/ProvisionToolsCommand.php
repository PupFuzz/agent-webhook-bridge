<?php

namespace App\Console\Commands\Bridge;

use App\Bridge\Check\NextSteps;
use App\Bridge\Exceptions\UnreadableSecretException;
use App\Bridge\Scheduling\TickAdoptionNotice;
use App\Bridge\Scheduling\TickRecord;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\SecretFile;
use App\Bridge\Support\SubscriptionRegistry;
use App\Bridge\Tools\AgentNameShape;
use App\Bridge\Tools\BoardToolsSetupPacket;
use App\Bridge\Tools\GitRefProbe;
use App\Bridge\Tools\PublicKeyLineShape;
use App\Bridge\Tools\SafePathShape;
use App\Bridge\Tools\SshAccountShape;
use App\Bridge\Tools\SshEndpointShape;
use App\Bridge\Tools\SshProbeEnvironment;
use App\Bridge\Tools\SshTransportProbe;
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
    protected $signature = 'bridge:provision-tools
        {--agent= : limit to one agent}
        {--dry-run : preview, change nothing}
        {--host-a= : ssh transport only; the host the seat will ssh to, filled into the setup packet}
        {--ssh-port= : ssh transport only; the ssh port the seat should use}
        {--pubkey-from= : ssh transport only; the .pub file holding the key line the seat posted}';

    protected $description = 'Mint the per-agent board-tools Bearer token(s) for agents with a DEDICATED board_tools.auth.token_path (DL-217; channel-token-reuse agents need no mint), and print the ssh-transport SETUP PACKET (DL-357)';

    public function handle(SshProbeEnvironment $sshEnv, GitRefProbe $gitRef): int
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

        // The three packet options describe ONE agent's exchange with ONE seat, so they
        // are meaningless over the whole roster — a --host-a applied to every ssh agent
        // would print each of them a packet naming a host only one of them talks to.
        $packetOptions = array_keys(array_filter([
            '--host-a' => $this->strOption('host-a'),
            '--ssh-port' => $this->strOption('ssh-port'),
            '--pubkey-from' => $this->strOption('pubkey-from'),
        ], fn (?string $v) => $v !== null));
        if ($packetOptions !== [] && $only === null) {
            $this->error(implode(' / ', $packetOptions).' fill ONE agent\'s setup packet — pass --agent=<name> too.');

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
                    $this->info("{$label} SKIP — no board_tools block; run `".NextSteps::PROVISION."{$cfg->agentName}` for a paste-ready skeleton, then re-run to mint the bearer");
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
                // ssh agents mint NO bridge-side secret (the private key is host-B's, and
                // this command never edits authorized_keys). Provisioning for this transport
                // is PRINTING the per-agent SETUP PACKET (card#8971 / DL-357) — the whole
                // three-actor exchange, not the two invocations that used to stand for it.
                // ⚠ --dry-run does not change it: the packet mutates nothing, so a
                // preview of it and the thing itself are the same bytes.
                if (! $this->printSetupPacket($cfg->agentName, $bt->sshAccount, $sshEnv, $gitRef)) {
                    $rc = self::FAILURE;
                }

                continue;
            }
            if ($packetOptions !== []) {
                // Reached only via --agent, so this is the named agent and the operator
                // asked for a packet the http door has no steps for.
                $this->error("{$label} ".implode(' / ', $packetOptions).' are ssh-transport options and this agent is on the http transport (board_tools.transport: http) — it has no key to pin and no packet to print.');
                $rc = self::FAILURE;

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
                try {
                    $value = SecretFile::read($path);
                } catch (UnreadableSecretException $e) {
                    // Minting is the operator's own act, so this process IS the subject:
                    // it cannot mint over a bearer it cannot read (that would silently
                    // rotate a live agent's token), and it cannot report the existing one.
                    $this->error("{$label} FAIL — ".$e->getMessage().'; re-run as a user that can read it');
                    $rc = self::FAILURE;

                    continue;
                }
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

        $clean = $this->reportCollisions($all, $tokenValues);

        $this->printTickNotice();

        return $clean ? $rc : self::FAILURE;
    }

    /**
     * The install-wide TICK offer (card#9058 / DL-361) — {@see TickAdoptionNotice} owns every
     * word of it and every arm of when it says nothing.
     *
     * ⭐ ONCE PER RUN, NEVER ONCE PER AGENT, AND THAT PLACEMENT IS THE DESIGN. `bridge:tick` is
     * ONE line per bridge install: the registry table carries no agent column, and neither the
     * last-tick record's cache key nor the scheduler's lock/marker keys carry an agent segment.
     * Printed inside the per-agent loop it would tell N onboarding agents to each add their own
     * line — a worse defect than the silence it replaces — so it sits OUTSIDE the loop, where a
     * roster-wide run prints it exactly once and a per-agent run prints the same single offer.
     * ⚑ That also makes it transport-agnostic: the ssh SETUP PACKET is where an ssh agent's
     * enablement lives, but an http-transport install needs the tick just as much and has no
     * packet to carry it.
     *
     * ⛔ IT CANNOT MOVE THE EXIT CODE. It returns nothing and is called after the verdict is
     * already decided; a periodic ingress this install has not adopted is not a provisioning
     * fault.
     *
     * ⚠ THE IMPURITY IS HERE, NOT IN THE NOTICE. The posture and the reason a declaration cannot
     * be read come from the cache and the config ({@see TickRecord::posture()},
     * {@see TickRecord::declarationProblem()}), the base path and `PHP_BINARY` from this process —
     * so the renderer stays a pure function of them and every arm is drivable from a test.
     */
    private function printTickNotice(): void
    {
        $lines = TickAdoptionNotice::forThisInstall()->lines();

        if ($lines === []) {
            return;
        }

        $this->line('');
        foreach ($lines as $line) {
            $this->line($line);
        }
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
     * Print the BOARD-TOOLS SETUP PACKET for one ssh-transport agent (card#8971,
     * DL-357). Returns false when the packet could not be rendered honestly.
     *
     * This command mints no bridge-side secret for ssh (the private key lives on the
     * seat; it never edits `authorized_keys`), so provisioning for this transport IS the
     * packet. {@see BoardToolsSetupPacket} owns every word of it; this method is the
     * boundary layer that resolves the host facts and refuses the inputs that would make
     * a step wrong — {@see packetValuesAreRenderable} for the values that reach a rendered
     * command line, {@see pubkeyFileIsUsable} for the file whose CONTENT reaches one.
     *
     * ⛔ THE ACCOUNT IS NOT RE-DERIVED HERE. `board_tools.ssh_account ?? runUser()` is
     * {@see SshTransportProbe::forcedCommandAccount()}'s rule, and it is the rule
     * `bridge:check` certifies against — a second copy in this command is a second answer
     * able to disagree with the line the operator reads two commands later. (The old
     * guidance printer had exactly that copy, spelled `getenv('USER') ?: '<bridge-user>'`,
     * which resolves the LOGIN user rather than the effective one and answered
     * `<bridge-user>` on any host that does not export `USER`.)
     *
     * @param  ?string  $sshAccount  board_tools.ssh_account (null ⇒ the invoking run-user)
     */
    private function printSetupPacket(string $agentName, ?string $sshAccount, SshProbeEnvironment $sshEnv, GitRefProbe $gitRef): bool
    {
        $account = (new SshTransportProbe($sshEnv, $sshAccount))->forcedCommandAccount();
        $pubkeyDir = storage_path('app/board-tools');
        $artisan = base_path('artisan');

        $pubkeyPath = $this->strOption('pubkey-from');
        if (! $this->packetValuesAreRenderable($agentName, $artisan, $account, $sshAccount, $pubkeyDir, $pubkeyPath)) {
            return false;
        }
        if ($pubkeyPath !== null && ! $this->pubkeyFileIsUsable($agentName, $pubkeyPath)) {
            return false;
        }

        // uid == euid ⇒ the pin writes THIS account's own authorized_keys, which it can
        // already do. Anything else — including either fact being unmeasurable — takes
        // the `sudo` form: an unnecessary sudo costs a prompt, a missing one costs a
        // failed pin, so the unmeasured case belongs on the safe side of the compare.
        $accountUid = $sshEnv->uidForUser($account);
        $euid = $sshEnv->euid();
        $pinNeedsSudo = ! ($accountUid !== null && $euid !== null && $accountUid === $euid);

        $this->info("[{$agentName}] ssh transport — no bridge-side secret is minted (the private key lives on the agent's own seat). The setup packet below is the whole enablement exchange; hand each step to the actor it names.");
        $this->line('');
        foreach ((new BoardToolsSetupPacket(
            agent: $agentName,
            account: $account,
            accountConfigured: $sshAccount !== null,
            artisan: $artisan,
            script: base_path('bin/provision-board-tools.py'),
            pubkeyDir: $pubkeyDir,
            hostA: $this->strOption('host-a'),
            sshPort: $this->strOption('ssh-port'),
            pubkeyPath: $pubkeyPath,
            gitRef: $gitRef->headOnPushedBranch(base_path()),
            version: $this->installVersion(),
            pinNeedsSudo: $pinNeedsSudo,
        ))->lines() as $line) {
            $this->line($line);
        }

        // A packet whose STEP 3 says "not rendered" is a packet the PM cannot finish, so
        // it does not exit 0 — the two remedies are printed inside the step.
        return $account !== 'root';
    }

    /**
     * Every value the packet INTERPOLATES into a command, checked before it renders one.
     * Reports the cause and returns false at the first bad value.
     *
     * ⛔ THE PACKET IS PASTE-READY TEXT, AND THAT IS EXACTLY WHY THE VALUES ARE CHECKED
     * HERE. Its steps are commands an impl agent and an operator paste into their own
     * shells, so an unvalidated `--host-a`, `--ssh-port` or path does not stay a bad
     * option — it becomes a shell fragment on somebody else's box, one of them at a `sudo`
     * prompt. Refusing is not a courtesy to the parser; it is the only point at which this
     * command is still the party that can decline.
     *
     * ⭐ THE POPULATION IS THE VALUES {@see BoardToolsSetupPacket} INTERPOLATES, NOT THE
     * OPTIONS THIS COMMAND TAKES — and the two are not the same list, which is how the
     * first pass came to check the flags and miss half the install-derived values. Read in
     * that direction there are EIGHT non-constant values reaching a command line: the
     * AGENT name, the ACCOUNT, the `artisan` path, the `storage/app/board-tools` DIR, the
     * SCRIPT path, `--host-a`, `--ssh-port` and the `--pubkey-from` path (whose CONTENT is
     * a ninth question, and {@see pubkeyFileIsUsable}'s). Every one has a shape here
     * except the SCRIPT path, which is `base_path('bin/provision-board-tools.py')` — the same
     * `base_path()` the `artisan` leg below already proves, plus a suffix drawn entirely
     * from the accepted class, so a check for it could not fail while that one passed.
     * The seat-only placeholders (`<its-checkout>` and friends) are class constants.
     *
     * ⚑ `artisan`, the ACCOUNT and the STORAGE DIR ARE CHECKED HERE EVEN THOUGH THIS
     * COMMAND TAKES NO FLAG FOR ANY OF THEM. `base_path('artisan')` and
     * `storage_path('app/board-tools')` are this install's own paths and the account is
     * `board_tools.ssh_account ?? runUser()` — none of them answered a shape question on
     * the way in, and all three are rendered into STEP 3's pin command, which an operator
     * runs privileged. `provision-board-tools.py --role a` refuses the first two against
     * `_ARTISAN_RE` and the third against `_SSH_ACCOUNT_RE`, so an install outside those
     * classes produced a packet whose STEP 3 was guaranteed to be refused AFTER the
     * privileged window had been spent. {@see SafePathShape} / {@see SshAccountShape} are
     * those same rules, read from the python by a lockstep test.
     *
     * @param  string  $account  the RESOLVED forced-command account
     * @param  ?string  $sshAccount  `board_tools.ssh_account` as configured, or null — carried
     *                               ONLY so a refusal can name which file to fix
     */
    private function packetValuesAreRenderable(string $agentName, string $artisan, string $account, ?string $sshAccount, string $pubkeyDir, ?string $pubkeyPath): bool
    {
        $label = "[{$agentName}]";

        if (! AgentNameShape::isAgentName($agentName)) {
            $this->error("{$label} the agent name is rendered into the pinned forced command and into a .pub file name, and `provision-board-tools.py --role a` refuses any name outside ^".AgentNameShape::BODY_PATTERN.'$ — rename the agent config, then re-run.');

            return false;
        }
        if (! SafePathShape::isSafePath($artisan)) {
            $this->error("{$label} this install's artisan path ({$artisan}) is outside the character class `provision-board-tools.py --role a` accepts (^".SafePathShape::BODY_PATTERN.'$), so its STEP 3 would be refused after the operator had already run it. Move the checkout to a path without spaces or shell metacharacters, then re-run.');

            return false;
        }

        if (! SshAccountShape::isAccountName($account)) {
            // ⛔ THE REFUSAL NAMES WHERE THE VALUE CAME FROM, because the two sources take
            // opposite remedies: a bad `ssh_account` is one line in one YAML file, while a
            // bad run user means this command is running as the wrong account entirely and
            // editing the YAML would be editing a file that is already correct.
            $source = $sshAccount !== null
                ? "board_tools.ssh_account in {$agentName}.yml names it"
                : "board_tools.ssh_account is unset, so it fell back to this process's own run user";
            $fix = $sshAccount !== null
                ? "Set board_tools.ssh_account in {$agentName}.yml to the account that should serve board tools, then re-run."
                : "Set board_tools.ssh_account in {$agentName}.yml to the account that should serve board tools — left unset, this command answers with whatever user it happens to run as — then re-run.";
            $this->error("{$label} the forced-command account `{$account}` ({$source}) is outside the character class `provision-board-tools.py --role a` accepts for --ssh-account (^".SshAccountShape::BODY_PATTERN.'$). It is rendered into STEP 1\'s ssh target and into the `sudo -u <account> python3 …` line an operator pastes at a ROOT prompt, so it is refused rather than escaped. '.$fix);

            return false;
        }
        if (! SafePathShape::isSafePath($pubkeyDir)) {
            $this->error("{$label} this install's board-tools storage path ({$pubkeyDir}) is outside the character class `provision-board-tools.py --role a` accepts (^".SafePathShape::BODY_PATTERN.'$). It is rendered into STEP 2\'s `mkdir -p` and `cat >` lines and into STEP 3\'s default --pubkey-from, so no path in this packet would be safe to paste. Move this install\'s storage/ to a path without spaces or shell metacharacters (it follows the checkout unless LARAVEL_STORAGE_PATH relocates it), then re-run.');

            return false;
        }

        $hostA = $this->strOption('host-a');
        if ($hostA !== null && ! SshEndpointShape::isHost($hostA)) {
            $this->error("{$label} --host-a {$hostA} is not a host name, an IPv4 address or a [IPv6] literal. It is rendered into an ssh target the seat pastes into its own shell, so it is refused rather than escaped — pass the host the seat will ssh to.");

            return false;
        }

        $sshPort = $this->strOption('ssh-port');
        if ($sshPort !== null && ! SshEndpointShape::isPort($sshPort)) {
            $this->error("{$label} --ssh-port {$sshPort} is not a port number 1-65535. It is rendered into the seat's `--ssh-port` argument, so it is refused rather than escaped.");

            return false;
        }

        if ($pubkeyPath !== null && ! SafePathShape::isSafePath($pubkeyPath)) {
            $this->error("{$label} --pubkey-from {$pubkeyPath} is outside the character class the pin command accepts (^".SafePathShape::BODY_PATTERN.'$). The path is rendered into STEP 3, which an operator runs as a privileged command — save the key under a path without spaces or shell metacharacters (the packet suggests one), then re-run.');

            return false;
        }

        return true;
    }

    /**
     * Is `--pubkey-from` a file this command may name in a pin command? Reports the
     * cause and returns false when not.
     *
     * ⛔ IT VALIDATES THE CONTENT, NOT ONLY THE PATH, and the reason is who wrote it: the
     * bytes came from an agent on ANOTHER box, through a PM that pastes mechanically.
     * The shape check is {@see PublicKeyLineShape}, which is the same positive allowlist
     * `bin/provision-board-tools.py --role a` will apply — checking here means the
     * operator is never handed a pin command that is going to be refused after they have
     * spent a privileged window on it, and a two-line paste is refused before the second
     * line can become an UNRESTRICTED key.
     */
    private function pubkeyFileIsUsable(string $agentName, string $path): bool
    {
        $label = "[{$agentName}]";
        if (! is_file($path)) {
            $this->error("{$label} --pubkey-from {$path} is not a regular file — save the key line the seat posted there first (STEP 2), then re-run.");

            return false;
        }
        $content = @file_get_contents($path);
        if (! is_string($content)) {
            $this->error("{$label} --pubkey-from {$path} could not be read by this process — fix its permissions and re-run.");

            return false;
        }
        if (! PublicKeyLineShape::isSingleAuthorizedKeyLine(rtrim($content, "\n"))) {
            $this->error("{$label} --pubkey-from {$path} does not hold exactly ONE well-formed public-key line (rejected: unknown key type / non-base64 blob / more than one line). This is what the seat pastes into a file, so a bad one is refused HERE rather than at the pin. Re-save just the single `<keytype> <base64> [comment]` line the seat printed.");

            return false;
        }

        return true;
    }

    /** This install's `VERSION`, or `unknown` — only ever printed, never compared. */
    private function installVersion(): string
    {
        $raw = @file_get_contents(base_path('VERSION'));

        return is_string($raw) && trim($raw) !== '' ? trim($raw) : 'unknown';
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
