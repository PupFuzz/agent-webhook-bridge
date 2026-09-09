<?php

namespace App\Bridge\Tools;

/**
 * The per-agent BOARD-TOOLS SETUP PACKET printed by `bridge:provision-tools --agent=X`
 * for an ssh-transport agent (card#8971, DL-357).
 *
 * WHAT IT REPLACES AND WHY. The command used to print two ready-to-run
 * `provision-board-tools.py --role a|b` invocations. Both are still there, but two
 * invocations are not the job: enabling an impl seat is a FIVE-step exchange between
 * THREE actors on TWO boxes — the PM agent on host A, the impl agent on its own seat,
 * and a HUMAN operator who decides the posted key is really that seat's — and every
 * value that has to cross between them (the key line, the fingerprint, the seat's
 * project dir, the ref this box runs) was left for the reader to invent. The packet
 * states the whole exchange in the order it happens, marks each step with WHO runs it,
 * and says plainly which values only the seat can supply.
 *
 * ⛔ STEP 3 IS A PROCESS CONTROL, AND THIS CLASS NEVER PRETENDS OTHERWISE. The pin is
 * the sole board-tools security boundary, and its only check is a person choosing which
 * key line gets pinned. Nothing mechanical here enforces that a human made the choice —
 * an account that can write its own `authorized_keys` can pin anything, and any holder
 * of the `.pub` can compute its fingerprint. So STEP 3 is addressed to the OPERATOR
 * inside the framework's `USER ACTION REQUIRED` banner, the PM is told in as many words
 * to stop and hand it over, and `--expect-fingerprint` is described as a TRANSCRIPTION
 * guard (right file, right seat) and never as a checkpoint.
 * `docs/board-tools-enablement.md` owns the long form.
 *
 * ⚠ IT IS PURE, AND EVERY HOST FACT ARRIVES AS A CONSTRUCTOR ARGUMENT. Nothing here
 * reads a file, runs a process or looks an account up — the caller does that through
 * {@see SshProbeEnvironment} / {@see GitRefProbe} — so a test drives every arm (root
 * account, no-sudo pin, unresolvable uid, git-ref known or not) without a real host.
 *
 * ⚑ ITS READER IS AN AGENT, AND THAT READER RECEIVES NEITHER THE GLYPHS NOR THE INDENT.
 * `laravel/pao` binds its own `OutputStyle` when an AI agent runs the command (never
 * under `runningUnitTests()`, so a test reads the uncleaned bytes), and its
 * `OutputCleaner` DELETES a fixed glyph set and COLLAPSES runs of spaces. So no line may
 * OPEN on a word the cleaned text would read as structure — `⚠ STEP 1 …` inside STEP 4
 * arrives as `STEP 1 …`, a step heading to the one reader who cannot see it is not one —
 * and STEP 2's heredoc body and terminator are flush-left for a second reason besides the
 * shell's: a flush-left line has no leading run to collapse. `CheckCommand`'s NEXT STEPS
 * block states the same constraint over the same reader.
 */
final class BoardToolsSetupPacket
{
    /** The seat-only placeholders, named once so the header and the steps cannot drift. */
    private const CHECKOUT = '<its-checkout>';

    private const PROJECT_DIR = '<its-claude-project-dir>';

    private const CHANNEL_KEY = '<its-mcp-servers-key>';

    /** The heredoc terminator STEP 2 writes the posted key line with (see stepTwo()). */
    private const HEREDOC_TAG = 'BOARDTOOLS_PUBKEY';

    /**
     * @param  string  $agent  the board-tools agent name (the pinned `--agent=`)
     * @param  string  $account  the forced-command account, resolved by
     *                           {@see SshTransportProbe::forcedCommandAccount()} — never
     *                           re-derived here — AND ALREADY SHAPE-CHECKED by the caller
     *                           against {@see SshAccountShape}, which is what lets the
     *                           `=== 'root'` branch below mean what it says
     * @param  bool  $accountConfigured  whether `board_tools.ssh_account` set it (false ⇒
     *                                   it fell back to this process's own user, which the
     *                                   header has to disclose)
     * @param  string  $artisan  absolute path to this install's `artisan`
     * @param  string  $script  absolute path to this install's `bin/provision-board-tools.py`
     * @param  string  $pubkeyDir  absolute `storage_path('app/board-tools')`, ALREADY
     *                             SHAPE-CHECKED by the caller against {@see SafePathShape} —
     *                             where STEP 2 writes the posted key line (F12: never a
     *                             `<BRIDGE>` token the reader has to expand)
     * @param  ?string  $hostA  `--host-a`, or null while the PM has not supplied it
     * @param  ?string  $sshPort  `--ssh-port`, verbatim, or null for the default
     * @param  ?string  $pubkeyPath  a `--pubkey-from` path THAT HAS ALREADY BEEN VALIDATED
     *                               by the caller (exists, regular, readable, and one
     *                               well-formed key line per {@see PublicKeyLineShape}), or
     *                               null on the first pass
     * @param  ?array{sha: string, branch: string}  $gitRef  what {@see GitRefProbe} could say
     * @param  string  $version  this install's `VERSION`, for the sentence used when it could not
     * @param  bool  $pinNeedsSudo  false ONLY when the forced-command account's uid and this
     *                              process's euid were both established AND are equal
     */
    public function __construct(
        private readonly string $agent,
        private readonly string $account,
        private readonly bool $accountConfigured,
        private readonly string $artisan,
        private readonly string $script,
        private readonly string $pubkeyDir,
        private readonly ?string $hostA,
        private readonly ?string $sshPort,
        private readonly ?string $pubkeyPath,
        private readonly ?array $gitRef,
        private readonly string $version,
        private readonly bool $pinNeedsSudo,
    ) {}

    /**
     * The packet, as lines. The caller prints them verbatim.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        return [
            ...$this->header(),
            '',
            ...$this->stepOne(),
            '',
            ...$this->stepTwo(),
            '',
            ...$this->stepThree(),
            '',
            ...$this->stepFour(),
            '',
            ...$this->stepFive(),
            '',
            ...$this->footer(),
        ];
    }

    /** @return list<string> */
    private function header(): array
    {
        $accountNote = $this->accountConfigured
            ? 'board_tools.ssh_account names it'
            : "board_tools.ssh_account unset ⇒ this process's user; set it in {$this->agent}.yml if that is wrong";

        return [
            "BOARD-TOOLS SETUP PACKET — agent {$this->agent} (ssh transport)",
            'roles and handoff: docs/board-tools-enablement.md',
            $this->refLine(),
            "forced command runs as: {$this->account} ({$accountNote}); {$this->account} must be able to run "
                ."`php {$this->artisan} bridge:tools-call` — the bridge's own storage/ writable by it",
            'values only the seat knows: '.self::CHECKOUT.' '.self::PROJECT_DIR.' '.self::CHANNEL_KEY
                .' — '.self::CHANNEL_KEY.' is your `.mcp.json` `mcpServers` key if you have one; on a fresh seat, '
                .'the key you will pass to --dangerously-load-development-channels server:<key>',
            ...($this->hostA === null
                ? ['not supplied to this command: --host-a=<host-A> — re-run with it and STEP 1/STEP 2 fill in']
                : []),
        ];
    }

    /**
     * WHAT REF THE SEAT SHOULD CLONE — stated only when a seat could actually get it.
     *
     * ⚠ The known arm names THIS BOX'S LAST-FETCHED VIEW rather than claiming the sha is
     * on the remote right now: nothing fetched, and a stale `@{upstream}` still answers.
     * The unknown arm does NOT fall back to a sha — an unpushed or detached HEAD is a ref
     * the seat's clone does not contain — it names the VERSION and hands the question to
     * the PM, which is a smaller lie than a ref that does not resolve.
     */
    private function refLine(): string
    {
        if ($this->gitRef === null) {
            return "this box runs VERSION {$this->version}; if it tracks an unreleased branch, the seat clones "
                .'that branch — ask the PM which';
        }

        return "this box runs {$this->gitRef['sha']} on {$this->gitRef['branch']} (in this box's last-fetched view "
            ."of it) — the seat clones that branch and checks out {$this->gitRef['sha']}";
    }

    /** @return list<string> */
    private function stepOne(): array
    {
        $target = $this->hostA === null ? "{$this->account}@<host-A>" : "{$this->account}@{$this->hostA}";
        $port = $this->sshPort === null ? '' : " --ssh-port {$this->sshPort}";

        return [
            "STEP 1 — IMPL AGENT {$this->agent}, on its seat. Prerequisites: python3 ≥ 3.8; ssh, ssh-keygen, "
                .'ssh-keyscan; Node ≥ 20 + npm + a reachable registry/cache; its own clone at the ref above.',
            '    python3 '.self::CHECKOUT."/bin/provision-board-tools.py --role b --agent {$this->agent}"
                ." --ssh-target {$target}{$port} --project-dir ".self::PROJECT_DIR.' --channel-name '.self::CHANNEL_KEY,
            '  Post the printed PUBLIC key line to the PM. Keep the printed `Fingerprint:` line visible on the seat '
                .'— the operator reads it at STEP 3.',
        ];
    }

    /**
     * ⛔ THE POSTED KEY LINE NEVER ENTERS A SHELL COMMAND LINE. STEP 2 writes it with a
     * QUOTED heredoc (or the PM's own file-write tool), never `printf '%s\n' '<key>'`:
     * the value is a string an agent on ANOTHER box composed, the PM is an agent that
     * will paste it mechanically, and a single quote in it ends the quoting and hands
     * the rest to the shell. The heredoc body and terminator are flush-left because a
     * leading space would land inside the file — the packet says so rather than leaving
     * a reader to discover it as a pubkey that fails the shape check.
     *
     * @return list<string>
     */
    private function stepTwo(): array
    {
        $path = "{$this->pubkeyDir}/{$this->agent}.pub";
        $hostA = $this->hostA ?? '<host-A>';

        return [
            'STEP 2 — PM: save the key line the seat posted, then re-run this command with it.',
            "  Write it to {$path} — with your own file-write tool, or the heredoc below. ⛔ Never put the "
                .'seat\'s key line inside a shell command line (a quote in it ends the quoting), which is why '
                .'this packet offers no one-liner that puts the key between quotes. The body and the terminator '
                .'are flush-left on purpose — a leading space would land IN the file.',
            "mkdir -p {$this->pubkeyDir}",
            "cat > {$path} <<'".self::HEREDOC_TAG."'",
            '<paste the ONE key line the seat posted — nothing else>',
            self::HEREDOC_TAG,
            "chmod 644 {$path}",
            "    php artisan bridge:provision-tools --agent={$this->agent} --host-a={$hostA} --pubkey-from={$path}",
            '  PM: STOP at the end of that re-run. Hand STEP 3 to the operator. Do not run it yourself, and do not '
                .'compute the fingerprint from the file — the point of STEP 3 is that a PERSON decides this key is '
                .'the seat\'s.',
        ];
    }

    /**
     * STEP 3, inside the framework's `USER ACTION REQUIRED` banner (labels and order
     * fixed; see `CLAUDE_AGENTBOARD.md § User-action gating`). The banner is the handoff
     * surface — the packet's reader is an agent, and this is the shape its human already
     * scans for.
     *
     * ⛔ RESOLVED ACCOUNT `root` ⇒ NO STEP 3 AT ALL. That state means the packet itself
     * was run under `sudo` with no `board_tools.ssh_account`, so the account line above
     * names root, the pin would land in `/root/.ssh/authorized_keys`, and the forced
     * command would run as root — the card-4977 trap, one surface over. Rendering a pin
     * command for it would be rendering the wrong pin, so the step names the two fixes
     * instead and the command exits non-zero.
     *
     * @return list<string>
     */
    private function stepThree(): array
    {
        if ($this->account === 'root') {
            // ⛔ THE CAUSE IS READ OFF THE CONFIG, NOT GUESSED. Both routes to `root` are
            // real — this command run under `sudo` with no ssh_account, or an
            // ssh_account that literally says root — and naming the wrong one sends the
            // operator to edit a file that is already correct.
            $cause = $this->accountConfigured
                ? "board_tools.ssh_account in {$this->agent}.yml names `root`"
                : 'this command was run under sudo and no board_tools.ssh_account is set';

            return [
                "STEP 3 — NOT RENDERED. The forced-command account resolved to `root` ({$cause}). The pin would go "
                    ."to root's authorized_keys and the forced command would run as root, which is not what the ssh "
                    .'transport is for. Run bridge:provision-tools as the bridge user, or set '
                    ."board_tools.ssh_account in {$this->agent}.yml to the account that should serve board tools, "
                    .'then re-run for a packet whose STEP 3 pins the right account.',
            ];
        }

        $path = $this->pubkeyPath ?? "{$this->pubkeyDir}/{$this->agent}.pub";
        $pin = "python3 {$this->script} --role a --agent {$this->agent} --artisan {$this->artisan}"
            ." --ssh-account {$this->account} --pubkey-from {$path}"
            .' --expect-fingerprint <the SHA256:… from the seat\'s `Fingerprint:` line>';

        $as = $this->pinNeedsSudo
            ? "as root on this box (`sudo` — the pin writes {$this->account}'s authorized_keys and that is not the "
                .'account this command runs as)'
            : "as {$this->account} on this box (no sudo — {$this->account} IS the account this command runs as, and "
                .'it can already write its own authorized_keys)';

        return [
            '━━━ USER ACTION REQUIRED ━━━',
            "Question: OPERATOR — is the key line in {$path} the one agent {$this->agent}'s seat printed? If it is, "
                ."pin it, {$as}:",
            '    '.($this->pinNeedsSudo ? 'sudo ' : '').$pin,
            "    Logged in as someone else? `sudo -u {$this->account} python3 …` (or `sudo python3 …` for the root arm).",
            'Context: this pin is the ONLY board-tools security boundary, and the only thing standing behind it is '
                .'you deciding this key belongs to that seat. `--expect-fingerprint` is a TRANSCRIPTION guard — right '
                .'file, right seat — never a checkpoint: anyone holding the `.pub` can compute it. It pins ONE '
                ."forced-command line at {$this->account}'s DEFAULT authorized_keys path, which the pin command "
                ."prints (sshd's AuthorizedKeysFile is not resolved by it). Idempotent; a DIFFERENT key for "
                ."{$this->agent} is refused, not replaced.",
            'Expected response: run the command above, or decline and say what should happen instead. PM: this step '
                .'is not yours.',
            '━━━━━━━━━━━━━━━━━━━━━━━━━━━━',
        ];
    }

    /** @return list<string> */
    private function stepFour(): array
    {
        return [
            "STEP 4 — IMPL AGENT {$this->agent}: certify from the seat (no re-deploy, no keygen — it reads the "
                .'target and key THIS seat recorded in its own .mcp.json):',
            '    python3 '.self::CHECKOUT."/bin/provision-board-tools.py --role b --certify-only --agent {$this->agent}"
                .' --project-dir '.self::PROJECT_DIR.' --channel-name '.self::CHANNEL_KEY,
            '  then start its session: claude --dangerously-load-development-channels server:'.self::CHANNEL_KEY,
            // NOT A BANNER, DELIBERATELY. The packet's one `USER ACTION REQUIRED` banner is
            // STEP 3 (one per output, fixed labels — CLAUDE_AGENTBOARD.md). This line is
            // addressed to the PM reading the packet: it tells them the restart is an ask
            // they RAISE with the operator when the seat turns out to have a session
            // already up, not something they or the seat can do on the seat's behalf.
            // ⚠ KEEP THE TOKEN `/mcp reconnect` IN ONE LITERAL: splitting it drops this
            // file out of `ActivationPhraseLockstepTest`'s census SILENTLY. Splitting the
            // REST of the phrase is safe — it reds. That bound is in that test's docblock.
            '  session already running on that seat? /mcp reconnect does not stop the previous channel server — restart the session: '
                .'hand the restart to the OPERATOR as their action (a seat without GNU screen — Windows '
                .'included — cannot restart itself). docs/board-tools-enablement.md § Activating on a running seat',
            '  ⚠ the channel server\'s `args` in this seat\'s .mcp.json were repointed by STEP 1 at '
                .self::PROJECT_DIR.'/.channel-server/… — a copy it deploys there. Any previous copy '
                .'is left on disk untouched and is no longer what the session runs; delete it only '
                .'once this seat is certified.',
        ];
    }

    /**
     * ⚠ THE ROOT VARIANT'S EXTRA SENTENCE IS ABOUT WHAT `bridge:check` CAN READ, not
     * about the pin. When the forced-command account is not the invoking one, an
     * unprivileged `bridge:check` cannot read its 0600 `authorized_keys`, so the agent's
     * NEXT STEPS entry sits at `bridge_side_unverified` — a leg that could not look, not
     * a fault — until the command runs once as an account that can.
     *
     * @return list<string>
     */
    private function stepFive(): array
    {
        return [
            "STEP 5 — PM: php artisan bridge:check — {$this->agent}'s NEXT STEPS line clears once STEP 4's call is "
                .'on the ledger. ⛔ Never use --probe-tools / --probe-tools-ssh from this box as the seat\'s proof: '
                .'they stamp the same ledger row FROM HERE.',
            ...($this->pinNeedsSudo
                ? ['  Until `sudo php artisan bridge:check` runs once, the entry stays at `bridge_side_unverified` '
                    ."— this box cannot read {$this->account}'s authorized_keys unprivileged. The seat's own proof "
                    .'is the `board_tools: agent '.$this->agent.': client half REPORTED` line.']
                : []),
        ];
    }

    /** @return list<string> */
    private function footer(): array
    {
        return [
            'Seat is an OS user on THIS box? The five steps above collapse into one root-run wrapper: '
                .'docs/board-tools.md § Same-box SSH enablement — the one-shot wrapper (card 5090)',
        ];
    }
}
