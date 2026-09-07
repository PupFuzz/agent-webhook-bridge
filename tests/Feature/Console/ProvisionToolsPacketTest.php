<?php

namespace Tests\Feature\Console;

use App\Bridge\Tools\AgentNameShape;
use App\Bridge\Tools\GitRefProbe;
use App\Bridge\Tools\PublicKeyLineShape;
use App\Bridge\Tools\SafePathShape;
use App\Bridge\Tools\SshProbeEnvironment;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The BOARD-TOOLS SETUP PACKET `bridge:provision-tools --agent=X` prints for an
 * ssh-transport agent (card#8971, DL-357).
 *
 * ⭐ EVERY HOST FACT THE PACKET BRANCHES ON IS BOUND HERE, and that is the point of the
 * two seams. The account's uid, this process's euid and the checkout's git ref decide
 * whether STEP 3 says `sudo`, whether STEP 3 renders at all, and whether the ref line
 * names a sha or a version. A test that inherited any of them from the runner would pass
 * or fail by accident of which account and which checkout CI happened to use — and the
 * `sudo` arm in particular is a line an operator runs as root, so "it was right on the
 * developer's box" is not a standard it may be held to.
 */
class ProvisionToolsPacketTest extends TestCase
{
    private const PUBKEY = 'ecdsa-sha2-nistp256 AAAAE2VjZHNhLXNoYTItbmlzdHAyNTYAAAAIbmlzdHAyNTYAAABBBGV4YW1wbGVrZXlibG9i impl-board-tools';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/packet-'.uniqid();
        File::ensureDirectoryExists($this->dir);
        config(['bridge.config_dir' => $this->dir, 'bridge.secret_dir' => $this->dir]);
        $this->bindEnv();
        $this->bindGitRef(['sha' => 'e6d4064', 'branch' => 'dev']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    // ─── the header's account line ────────────────────────────────────────────

    public function test_the_header_names_the_run_user_when_ssh_account_is_unset(): void
    {
        // `board_tools.ssh_account ?? runUser()` is SshTransportProbe's rule and the one
        // bridge:check certifies against; the packet reads it through the probe rather
        // than re-deriving it, so this asserts the two cannot disagree.
        $this->writeSshAgent(sshAccount: null);

        $out = $this->runPacket();

        $this->assertStringContainsString('forced command runs as: bridge (board_tools.ssh_account unset', $out);
        $this->assertStringContainsString('set it in impl.yml if that is wrong', $out);
    }

    public function test_the_header_names_the_configured_account_when_ssh_account_is_set(): void
    {
        $this->writeSshAgent(sshAccount: 'bridge-user');

        $out = $this->runPacket();

        $this->assertStringContainsString('forced command runs as: bridge-user (board_tools.ssh_account names it)', $out);
        $this->assertStringNotContainsString('ssh_account unset', $out);
    }

    public function test_the_header_names_the_precondition_on_the_forced_command_account(): void
    {
        $this->writeSshAgent(sshAccount: 'bridge-user');

        $this->assertStringContainsString(
            'bridge-user must be able to run `php '.base_path('artisan')." bridge:tools-call` — the bridge's own storage/ writable by it",
            $this->runPacket(),
        );
    }

    // ─── STEP 3: sudo or not, and the root refusal ────────────────────────────

    public function test_the_pin_prints_without_sudo_when_the_account_is_this_process_s_own(): void
    {
        // ⭐ THE CONTROL FOR THIS TEST IS THE INVERTED COMPARE. Flipping
        // ProvisionToolsCommand's `$accountUid === $euid` to `!==` reds it — and that
        // mutation is a REAL regression, not a synthetic one: it is exactly the shape
        // that would tell an operator to `sudo` for a pin into their own
        // authorized_keys, spending a privileged window the design exists to avoid.
        $this->writeSshAgent(sshAccount: null);
        $this->bindEnv(uids: ['bridge' => 1001], euid: 1001);

        $out = $this->runPacket();

        $this->assertStringContainsString('as bridge on this box (no sudo — bridge IS the account this command runs as', $out);
        $this->assertStringContainsString('    python3 '.base_path('bin/provision-board-tools.py').' --role a --agent impl', $out);
        $this->assertStringNotContainsString('    sudo python3 ', $out);
    }

    public function test_the_pin_prints_with_sudo_when_the_account_uid_could_not_be_established(): void
    {
        // A host with no posix_getpwnam measures NOTHING, and an unmeasured fact must
        // reach the safe arm: an unnecessary sudo costs a prompt, a missing one costs a
        // failed pin.
        $this->writeSshAgent(sshAccount: 'bridge-user');
        $this->bindEnv(uids: [], euid: 1001);

        $out = $this->runPacket();

        $this->assertStringContainsString('    sudo python3 '.base_path('bin/provision-board-tools.py').' --role a --agent impl', $out);
        $this->assertStringContainsString('as root on this box (`sudo`', $out);
    }

    public function test_the_pin_prints_with_sudo_when_the_account_is_a_different_user(): void
    {
        $this->writeSshAgent(sshAccount: 'bridge-user');
        $this->bindEnv(uids: ['bridge-user' => 1002], euid: 1001);

        $this->assertStringContainsString('    sudo python3 ', $this->runPacket());
    }

    public function test_step_three_carries_the_logged_in_as_someone_else_line(): void
    {
        $this->writeSshAgent(sshAccount: 'bridge-user');

        $this->assertStringContainsString(
            'Logged in as someone else? `sudo -u bridge-user python3 …` (or `sudo python3 …` for the root arm).',
            $this->runPacket(),
        );
    }

    public function test_a_packet_resolved_to_root_refuses_to_render_step_three_and_exits_non_zero(): void
    {
        // The card-4977 trap one surface over: run under sudo with no ssh_account, the
        // account resolves to root, and a rendered STEP 3 would pin the key into root's
        // authorized_keys and run the forced command as root. Refusing to render it is
        // the whole behavior — printing a pin command for the wrong account would be
        // worse than printing none.
        $this->writeSshAgent(sshAccount: null);
        $this->bindEnv(runUser: 'root', uids: ['root' => 0], euid: 0);

        $exit = Artisan::call('bridge:provision-tools', ['--agent' => 'impl']);
        $out = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('STEP 3 — NOT RENDERED.', $out);
        $this->assertStringContainsString('this command was run under sudo and no board_tools.ssh_account is set', $out);
        $this->assertStringContainsString('Run bridge:provision-tools as the bridge user, or set board_tools.ssh_account in impl.yml', $out);
        $this->assertStringNotContainsString('--role a --agent impl', $out);
        // The rest of the packet still prints — the PM needs STEP 1 to brief the seat.
        $this->assertStringContainsString('STEP 1 — IMPL AGENT impl', $out);
    }

    public function test_a_configured_ssh_account_of_root_names_itself_as_the_cause(): void
    {
        // ⛔ BOTH ROUTES TO `root` ARE REAL, and naming the wrong one sends the operator
        // to edit a file that is already correct. This install did NOT run under sudo —
        // it was told to serve board tools as root.
        $this->writeSshAgent(sshAccount: 'root');
        $this->bindEnv(runUser: 'bridge', uids: ['bridge' => 1001, 'root' => 0], euid: 1001);

        $exit = Artisan::call('bridge:provision-tools', ['--agent' => 'impl']);
        $out = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('board_tools.ssh_account in impl.yml names `root`', $out);
        $this->assertStringNotContainsString('run under sudo', $out);
    }

    public function test_step_three_is_rendered_inside_the_user_action_required_banner(): void
    {
        // The packet's reader is an agent and its human already scans for this shape
        // (CLAUDE_AGENTBOARD.md § User-action gating). The labels and their order are
        // fixed, so they are asserted rather than described.
        $this->writeSshAgent(sshAccount: 'bridge-user');

        $out = $this->runPacket();
        $banner = substr($out, (int) strpos($out, '━━━ USER ACTION REQUIRED ━━━'));

        $this->assertStringContainsString('━━━ USER ACTION REQUIRED ━━━', $banner);
        $this->assertLessThan(strpos($banner, 'Context:'), strpos($banner, 'Question: OPERATOR'));
        $this->assertLessThan(strpos($banner, 'Expected response:'), strpos($banner, 'Context:'));
        $this->assertStringContainsString('━━━━━━━━━━━━━━━━━━━━━━━━━━━━', $banner);
    }

    public function test_the_pin_is_named_a_process_control_and_the_fingerprint_only_a_transcription_guard(): void
    {
        // ⛔ The honest security statement, asserted at the surface an operator reads.
        // Nothing mechanical enforces that a human chose the key, so a packet that
        // implied --expect-fingerprint made the pin safe would be the false claim.
        $this->writeSshAgent(sshAccount: 'bridge-user');

        $out = $this->runPacket();

        $this->assertStringContainsString('`--expect-fingerprint` is a TRANSCRIPTION guard', $out);
        $this->assertStringContainsString('never a checkpoint: anyone holding the `.pub` can compute it', $out);
        $this->assertStringContainsString('PM: this step is not yours.', $out);
        $this->assertStringContainsString('do not compute the fingerprint from the file', $out);
    }

    // ─── STEP 2: the posted key line never enters a shell command line ────────

    public function test_step_two_writes_the_posted_key_with_a_quoted_heredoc_and_never_interpolates_it(): void
    {
        // ⛔ THE HAZARD IS THE PROVENANCE OF THE STRING. The key line is composed on
        // another box and pasted mechanically by an agent; a single quote in it would
        // end the quoting of any `printf '%s\n' '<key>'` form and hand the remainder to
        // the shell. The heredoc body and terminator must be flush-left or the leading
        // spaces land in the file.
        $this->writeSshAgent(sshAccount: 'bridge-user');
        $storage = storage_path('app/board-tools');

        $out = $this->runPacket();

        $this->assertStringContainsString("cat > {$storage}/impl.pub <<'BOARDTOOLS_PUBKEY'", $out);
        $this->assertStringContainsString("\n<paste the ONE key line the seat posted — nothing else>\nBOARDTOOLS_PUBKEY\n", $out);
        $this->assertStringContainsString("chmod 644 {$storage}/impl.pub", $out);
        $this->assertStringContainsString('with your own file-write tool, or the heredoc below', $out);
        // The two shapes that would put the value on a command line, asserted absent by
        // name — the packet must not even DEMONSTRATE them.
        $this->assertStringNotContainsString('printf', $out);
        $this->assertStringNotContainsString("'%s", $out);
        $this->assertStringContainsString('offers no one-liner that puts the key between quotes', $out);
    }

    public function test_the_storage_path_is_rendered_absolute_rather_than_as_a_placeholder(): void
    {
        $this->writeSshAgent(sshAccount: 'bridge-user');

        $out = $this->runPacket();

        $this->assertStringContainsString(storage_path('app/board-tools'), $out);
        $this->assertStringNotContainsString('<BRIDGE>', $out);
    }

    // ─── --pubkey-from ────────────────────────────────────────────────────────

    public function test_a_supplied_pubkey_path_is_the_one_step_three_pins(): void
    {
        $this->writeSshAgent(sshAccount: 'bridge-user');
        $path = $this->dir.'/posted.pub';
        File::put($path, self::PUBKEY."\n");

        $out = $this->runPacket(['--pubkey-from' => $path, '--host-a' => 'hostA.example']);

        $this->assertStringContainsString("--pubkey-from {$path} --expect-fingerprint", $out);
        $this->assertStringContainsString("is the key line in {$path} the one agent impl's seat printed?", $out);
    }

    public function test_a_missing_pubkey_file_is_refused_before_any_packet_is_printed(): void
    {
        // ⭐ CONTROL: deleting the `is_file` leg from pubkeyFileIsUsable() reds this —
        // the run then falls through to the unreadable-file arm and the cause an
        // operator is handed stops naming the thing that is actually wrong.
        $this->writeSshAgent(sshAccount: 'bridge-user');

        $exit = Artisan::call('bridge:provision-tools', ['--agent' => 'impl', '--pubkey-from' => $this->dir.'/nope.pub']);
        $out = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('is not a regular file', $out);
        $this->assertStringNotContainsString('BOARD-TOOLS SETUP PACKET', $out);
    }

    public function test_a_pubkey_file_that_is_not_one_well_formed_key_line_is_refused(): void
    {
        // Two lines: the second would land in authorized_keys as an UNRESTRICTED key.
        $this->writeSshAgent(sshAccount: 'bridge-user');
        $path = $this->dir.'/two.pub';
        File::put($path, self::PUBKEY."\nssh-rsa AAAAB3NzaC1yc2E= attacker\n");

        $exit = Artisan::call('bridge:provision-tools', ['--agent' => 'impl', '--pubkey-from' => $path]);
        $out = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('does not hold exactly ONE well-formed public-key line', $out);
        $this->assertStringNotContainsString('BOARD-TOOLS SETUP PACKET', $out);
    }

    public function test_the_php_pubkey_allowlist_is_in_lockstep_with_the_python_owner(): void
    {
        // ⛔ ONE RULE, TWO IMPLEMENTATIONS, SO THE DRIFT IS GUARDED. The python decides
        // what actually gets pinned; this PHP copy only decides what the packet will
        // offer to pin. They must accept the same strings, so this reads the python's
        // own constants out of the file and compares — a new key type added to one side
        // reds until it is added to the other.
        $python = (string) file_get_contents(base_path('bin/provision-board-tools.py'));

        preg_match('/_KEY_TYPES = \((.*?)\)\n/s', $python, $m);
        $this->assertNotEmpty($m, 'could not locate _KEY_TYPES in bin/provision-board-tools.py');
        preg_match_all('/"([^"]+)"/', $m[1], $types);

        $this->assertSame($types[1], PublicKeyLineShape::KEY_TYPES);

        // ⭐ THE BODY HALF IS READ OUT OF THE CONSTANT, NOT GREPPED FOR ANYWHERE IN THE
        // FILE. A `assertStringContainsString('…the tail…', $python)` would go on passing
        // over a rewritten `_KEY_LINE_RE` as long as the old tail survived somewhere — a
        // docstring, a second regex — which is a lockstep test that has stopped reading
        // the thing it is in lockstep with.
        preg_match('/_KEY_LINE_RE = re\.compile\(\n(.*?)\n\)/s', $python, $line);
        $this->assertNotEmpty($line, 'could not locate _KEY_LINE_RE in bin/provision-board-tools.py');
        // The python expression is `r"(?:" + <joined types> + r") <body>"`; its LAST raw
        // string closes the type group and then carries the body half verbatim.
        preg_match_all('/r"([^"]*)"/', $line[1], $frags);
        $tail = (string) end($frags[1]);
        $this->assertStringStartsWith(')', $tail, "_KEY_LINE_RE's last fragment should close the key-type group");
        $this->assertSame(substr($tail, 1), PublicKeyLineShape::BODY_PATTERN);

        // And the composed matcher behaves: the allowlist accepts, a CR does not.
        $this->assertTrue(PublicKeyLineShape::isSingleAuthorizedKeyLine(self::PUBKEY));
        $this->assertFalse(PublicKeyLineShape::isSingleAuthorizedKeyLine(self::PUBKEY."\rssh-rsa AAAA= x"));
        $this->assertFalse(PublicKeyLineShape::isSingleAuthorizedKeyLine('ssh-dss AAAAB3NzaC1kc3M= x'));

        // ⚑ ONE DELIBERATE ASYMMETRY, DECLARED RATHER THAN DRIFTED INTO. The caller here
        // trims the file with `rtrim($content, "\n")`; the python trims it with
        // `raw.strip("\n")`, which also drops LEADING newlines. So a file that begins
        // with a blank line is accepted by the python and refused here — the PHP side is
        // a strict SUBSET, which is the safe direction for a pre-check whose whole job is
        // never to offer a pin the python will refuse.
        $this->assertStringContainsString('key = raw.strip("\n")', $python);
    }

    // ─── the ref line ─────────────────────────────────────────────────────────

    public function test_the_ref_line_names_the_sha_and_branch_when_head_is_on_its_upstream(): void
    {
        $this->writeSshAgent(sshAccount: 'bridge-user');

        $this->assertStringContainsString(
            "this box runs e6d4064 on dev (in this box's last-fetched view of it) — the seat clones that branch and checks out e6d4064",
            $this->runPacket(),
        );
    }

    public function test_the_ref_line_falls_back_to_the_version_when_head_is_not_on_its_upstream(): void
    {
        // ⭐ CONTROL: making SystemGitRefProbe answer with the sha regardless of the
        // `merge-base --is-ancestor` result — i.e. dropping the ancestor leg — reds
        // this. The mutation is the live defect it guards: an unpushed HEAD would be
        // printed as the ref to check out, and the seat's clone does not contain it.
        $this->writeSshAgent(sshAccount: 'bridge-user');
        $this->bindGitRef(null);

        $out = $this->runPacket();

        $version = trim((string) file_get_contents(base_path('VERSION')));
        $this->assertStringContainsString("this box runs VERSION {$version}; if it tracks an unreleased branch, the seat clones that branch — ask the PM which", $out);
        $this->assertStringNotContainsString('last-fetched view', $out);
    }

    // ─── STEP 5's root variant ────────────────────────────────────────────────

    public function test_step_five_adds_the_privileged_recheck_only_on_the_sudo_variant(): void
    {
        $this->writeSshAgent(sshAccount: 'bridge-user');
        $this->bindEnv(uids: ['bridge-user' => 1002], euid: 1001);
        $sudoVariant = $this->runPacket();

        $this->assertStringContainsString('Until `sudo php artisan bridge:check` runs once, the entry stays at `bridge_side_unverified`', $sudoVariant);
        $this->assertStringContainsString('board_tools: agent impl: client half REPORTED', $sudoVariant);

        $this->bindEnv(uids: ['bridge-user' => 1001], euid: 1001);
        $selfVariant = $this->runPacket();

        $this->assertStringNotContainsString('bridge_side_unverified', $selfVariant);
        $this->assertStringContainsString("STEP 5 — PM: php artisan bridge:check — impl's NEXT STEPS line clears", $selfVariant);
    }

    // ─── the option guards ────────────────────────────────────────────────────

    public function test_the_packet_options_are_refused_without_an_agent(): void
    {
        $this->writeSshAgent(sshAccount: 'bridge-user');

        foreach ([['--host-a' => 'h'], ['--ssh-port' => '2222'], ['--pubkey-from' => '/x']] as $opts) {
            $exit = Artisan::call('bridge:provision-tools', $opts);
            $this->assertSame(1, $exit, 'expected rc 1 for '.array_key_first($opts).' with no --agent');
            $this->assertStringContainsString("fill ONE agent's setup packet — pass --agent=<name> too.", Artisan::output());
        }
    }

    public function test_the_packet_options_are_refused_for_an_http_agent(): void
    {
        File::put($this->dir.'/impl.yml', "identity:\n  kanban_user_id: 1\nsubscriptions: []\n"
            ."board_tools:\n  enabled: true\n  transport: http\n  auth:\n    token_path: {$this->dir}/tok\n  board_id: 10\n  swimlane_id: 4\n  create_stage_id: 55\n");

        $exit = Artisan::call('bridge:provision-tools', ['--agent' => 'impl', '--host-a' => 'hostA.example']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('are ssh-transport options and this agent is on the http transport', Artisan::output());
    }

    public function test_dry_run_prints_the_same_packet(): void
    {
        // The packet mutates nothing, so a preview of it and the thing itself are the
        // same bytes — a --dry-run that printed something else would be describing a
        // command that does not exist.
        $this->writeSshAgent(sshAccount: 'bridge-user');

        $live = $this->runPacket();
        $dry = $this->runPacket(['--dry-run' => true]);

        $this->assertSame($live, $dry);
    }

    public function test_the_ssh_agent_still_mints_no_bridge_side_secret(): void
    {
        $this->writeSshAgent(sshAccount: 'bridge-user');

        $this->runPacket();

        $this->assertFileDoesNotExist($this->dir.'/impl-board-tools-token');
    }

    public function test_the_step_one_and_four_lines_carry_the_seat_only_placeholders(): void
    {
        $this->writeSshAgent(sshAccount: 'bridge-user');

        $out = $this->runPacket(['--host-a' => 'hostA.example', '--ssh-port' => '2222']);

        $this->assertStringContainsString(
            'python3 <its-checkout>/bin/provision-board-tools.py --role b --agent impl --ssh-target bridge-user@hostA.example --ssh-port 2222 --project-dir <its-claude-project-dir> --channel-name <its-mcp-servers-key>',
            $out,
        );
        $this->assertStringContainsString(
            'python3 <its-checkout>/bin/provision-board-tools.py --role b --certify-only --agent impl --project-dir <its-claude-project-dir> --channel-name <its-mcp-servers-key>',
            $out,
        );
        $this->assertStringContainsString('claude --dangerously-load-development-channels server:<its-mcp-servers-key>', $out);
        // Measured on three live seats (roundtable #419): STEP 1 repoints the channel
        // server's `args` and leaves the previous copy on disk, so a seat that reads
        // STEP 4 as "nothing moved" deletes the wrong tree or debugs the wrong one.
        // ⚑ IT DOES NOT OPEN ON `STEP 1`, and that is not a style choice: `laravel/pao`
        // deletes the glyph and collapses the indent for an AI-agent reader (the packet's
        // actual audience), and this line would then begin `STEP 1 repointed …` INSIDE the
        // STEP 4 block — a step heading, to the one reader who cannot see it is not one.
        $this->assertStringContainsString(
            "⚠ the channel server's `args` in this seat's .mcp.json were repointed by STEP 1 at <its-claude-project-dir>/.channel-server/… — a copy it deploys there.",
            $out,
        );
        $this->assertStringContainsString('delete it only once this seat is certified.', $out);
    }

    public function test_the_header_says_when_host_a_has_not_been_supplied(): void
    {
        $this->writeSshAgent(sshAccount: 'bridge-user');

        $this->assertStringContainsString('not supplied to this command: --host-a=<host-A>', $this->runPacket());
        $this->assertStringNotContainsString('not supplied to this command', $this->runPacket(['--host-a' => 'hostA.example']));
    }

    public function test_the_packet_points_at_the_new_role_doc_and_the_same_box_wrapper(): void
    {
        $this->writeSshAgent(sshAccount: 'bridge-user');

        $out = $this->runPacket();

        $this->assertStringContainsString('roles and handoff: docs/board-tools-enablement.md', $out);
        $this->assertStringContainsString('docs/board-tools.md § Same-box SSH enablement — the one-shot wrapper (card 5090)', $out);
    }

    // ─── the values that reach a rendered command line ────────────────────────

    public function test_a_host_a_that_is_not_a_host_is_refused_before_any_packet_is_printed(): void
    {
        // ⭐ CONTROL: drop the `\z` anchor from SshEndpointShape::isHost() and this reds —
        // the value then matches on its leading label and the packet renders STEP 1 as
        // `--ssh-target bridge-user@hostA.example; rm -rf ~ ` for an impl agent to paste
        // into its own shell. The whole packet is text somebody else executes, which is
        // why a value that is not what it claims to be is refused rather than escaped.
        $this->writeSshAgent(sshAccount: 'bridge-user');

        $exit = Artisan::call('bridge:provision-tools', ['--agent' => 'impl', '--host-a' => 'hostA.example; rm -rf ~']);
        $out = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('is not a host name, an IPv4 address or a [IPv6] literal', $out);
        $this->assertStringContainsString('refused rather than escaped', $out);
        $this->assertStringNotContainsString('BOARD-TOOLS SETUP PACKET', $out);
    }

    public function test_the_host_forms_ssh_itself_takes_still_render(): void
    {
        // ⛔ THE OTHER HALF OF THE CONTROL. A guard that refused everything would pass the
        // test above and be a defect — so the three forms an operator legitimately has
        // are asserted to reach the packet, bracketed IPv6 included (a bare one is
        // ambiguous with `host:port`, which is why ssh requires the brackets).
        $this->writeSshAgent(sshAccount: 'bridge-user');

        foreach (['hostA.example', 'host-a1.sub.example.com.', '10.0.0.7', '[2001:db8::1]'] as $host) {
            $out = $this->runPacket(['--host-a' => $host]);
            $this->assertStringContainsString("--ssh-target bridge-user@{$host} ", $out, "expected {$host} to render");
        }
    }

    public function test_an_ssh_port_that_is_not_a_port_is_refused_before_any_packet_is_printed(): void
    {
        $this->writeSshAgent(sshAccount: 'bridge-user');

        foreach (['2222; id', '0', '70000', ' 22'] as $port) {
            $exit = Artisan::call('bridge:provision-tools', ['--agent' => 'impl', '--host-a' => 'hostA.example', '--ssh-port' => $port]);
            $out = Artisan::output();

            $this->assertSame(1, $exit, "expected rc 1 for --ssh-port {$port}");
            $this->assertStringContainsString('is not a port number 1-65535', $out);
            $this->assertStringNotContainsString('BOARD-TOOLS SETUP PACKET', $out);
        }
    }

    public function test_a_pubkey_path_carrying_shell_metacharacters_is_refused_before_the_pin_is_rendered(): void
    {
        // The path is rendered into STEP 3 — the one command in the packet an operator
        // runs privileged — so it is refused for its SHAPE before the file is even looked
        // for. The character class is the python's `_ARTISAN_RE`, which is what `--role a`
        // will apply to the same value.
        $this->writeSshAgent(sshAccount: 'bridge-user');

        $exit = Artisan::call('bridge:provision-tools', ['--agent' => 'impl', '--pubkey-from' => $this->dir.'/impl.pub; cat /etc/shadow']);
        $out = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('is outside the character class the pin command accepts', $out);
        $this->assertStringContainsString('an operator runs as a privileged command', $out);
        // The SHAPE refusal, not the missing-file one — a bad path must not be reported
        // as merely absent, or the operator re-saves the file and hits the same wall.
        $this->assertStringNotContainsString('is not a regular file', $out);
        $this->assertStringNotContainsString('BOARD-TOOLS SETUP PACKET', $out);
    }

    public function test_an_agent_name_outside_the_python_class_is_refused(): void
    {
        // ⚠ THE NAME IS A FILE NAME, AND NOTHING VALIDATES IT ON THE WAY IN. It becomes
        // `bridge:tools-call --agent=<name>` inside the pinned forced command's double
        // quotes and `<dir>/<name>.pub` in STEP 2's heredoc, so `--role a` refuses
        // anything outside `_AGENT_RE` — and a packet that rendered it anyway would be
        // handing over a STEP 3 guaranteed to be refused after the sudo.
        File::put($this->dir.'/im$(id)pl.yml', "identity:\n  kanban_user_id: 1\nsubscriptions: []\n"
            ."board_tools:\n  transport: ssh\n  ssh_account: bridge-user\n  board_id: 10\n  swimlane_id: 4\n  create_stage_id: 55\n");

        $exit = Artisan::call('bridge:provision-tools', ['--agent' => 'im$(id)pl']);
        $out = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('refuses any name outside ^[a-z0-9_-]+$', $out);
        $this->assertStringNotContainsString('BOARD-TOOLS SETUP PACKET', $out);
    }

    public function test_an_artisan_path_the_python_would_refuse_stops_the_packet_here(): void
    {
        // ⛔ THE VALUE IS NOT AN OPTION OF THIS COMMAND — it is `base_path('artisan')`,
        // this install's own path, rendered into STEP 3. An install whose checkout sits
        // under a directory with a space produced a packet whose pin was going to be
        // refused by `--role a` AFTER the operator had spent the privileged window on it.
        // That is the F17 residue, closed at the only point that can still decline.
        $this->writeSshAgent(sshAccount: 'bridge-user');
        $this->app->setBasePath($this->dir.'/checkout with a space');

        $exit = Artisan::call('bridge:provision-tools', ['--agent' => 'impl']);
        $out = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString("this install's artisan path", $out);
        $this->assertStringContainsString('its STEP 3 would be refused after the operator had already run it', $out);
        $this->assertStringNotContainsString('BOARD-TOOLS SETUP PACKET', $out);
    }

    public function test_the_php_path_and_agent_shapes_are_in_lockstep_with_the_python_owner(): void
    {
        // ⛔ ONE RULE, TWO IMPLEMENTATIONS, SO THE DRIFT IS GUARDED — the same arrangement
        // PublicKeyLineShape is under, and for the same reason: the python is what
        // actually refuses the pin, and this copy only decides what the packet will offer
        // to pin. Widening either regex alone reds here.
        $python = (string) file_get_contents(base_path('bin/provision-board-tools.py'));

        preg_match('/^_ARTISAN_RE = re\.compile\(r"\^(.*)\$"\)$/m', $python, $artisan);
        $this->assertNotEmpty($artisan, 'could not locate _ARTISAN_RE in bin/provision-board-tools.py');
        $this->assertSame($artisan[1], SafePathShape::BODY_PATTERN);

        preg_match('/^_AGENT_RE = re\.compile\(r"\^(.*)\$"\)$/m', $python, $agent);
        $this->assertNotEmpty($agent, 'could not locate _AGENT_RE in bin/provision-board-tools.py');
        $this->assertSame($agent[1], AgentNameShape::BODY_PATTERN);

        // ⚑ AND THE ANCHORS ARE THIS SIDE'S, NOT THE PATTERN'S. The python applies its
        // `^…$` through `fullmatch`; PHP's `$` would accept a trailing newline, so both
        // classes compose `\A…\z` around the shared body — asserted, because a newline
        // riding into a rendered command line is exactly what these shapes exist to stop.
        $this->assertFalse(SafePathShape::isSafePath("/opt/bridge/artisan\n"));
        $this->assertFalse(AgentNameShape::isAgentName("impl\n"));
        $this->assertTrue(SafePathShape::isSafePath('/opt/bridge/artisan'));
        $this->assertTrue(AgentNameShape::isAgentName('impl'));
    }

    // ─── helpers ──────────────────────────────────────────────────────────────

    private function writeSshAgent(?string $sshAccount): void
    {
        $account = $sshAccount === null ? '' : "  ssh_account: {$sshAccount}\n";
        File::put($this->dir.'/impl.yml', "identity:\n  kanban_user_id: 1\nsubscriptions: []\n"
            ."board_tools:\n  transport: ssh\n{$account}  board_id: 10\n  swimlane_id: 4\n  create_stage_id: 55\n");
    }

    /** @param array<string, bool|string> $options */
    private function runPacket(array $options = []): string
    {
        Artisan::call('bridge:provision-tools', ['--agent' => 'impl'] + $options);

        return Artisan::output();
    }

    /** @param array<string, int> $uids */
    private function bindEnv(string $runUser = 'bridge', array $uids = [], ?int $euid = null): void
    {
        $this->app->instance(SshProbeEnvironment::class, new PacketSshEnvironment($runUser, $uids, $euid));
    }

    /** @param ?array{sha: string, branch: string} $ref */
    private function bindGitRef(?array $ref): void
    {
        $this->app->instance(GitRefProbe::class, new class($ref) implements GitRefProbe
        {
            /** @param ?array{sha: string, branch: string} $ref */
            public function __construct(private readonly ?array $ref) {}

            /** @return ?array{sha: string, branch: string} */
            public function headOnPushedBranch(string $dir): ?array
            {
                return $this->ref;
            }
        });
    }
}

/**
 * The packet's host facts, every one a constructor field. `uidForUser` returns null for
 * any account not in the map, which is the interface's UNMEASURED state — the one the
 * `sudo` decision must fall to.
 */
final class PacketSshEnvironment implements SshProbeEnvironment
{
    /** @param array<string, int> $uids */
    public function __construct(
        private readonly string $user,
        private readonly array $uids,
        private readonly ?int $euidValue,
    ) {}

    public function isRoot(): bool
    {
        return false;
    }

    public function fipsEnabled(): bool
    {
        return false;
    }

    public function runUser(): string
    {
        return $this->user;
    }

    public function runUserHome(): string
    {
        return '/home/'.$this->user;
    }

    public function homeForUser(string $user): ?string
    {
        return '/home/'.$user;
    }

    public function uidForUser(string $user): ?int
    {
        return $this->uids[$user] ?? null;
    }

    public function euid(): ?int
    {
        return $this->euidValue;
    }

    public function sshdEffectiveConfig(?string $forUser = null): ?string
    {
        return null;
    }

    public function readAuthorizedKeys(string $path): ?string
    {
        return null;
    }

    /** @return array{exit: int, stdout: string, stderr: string} */
    public function sshRoundTrip(string $target, string $stdin): array
    {
        return ['exit' => 255, 'stdout' => '', 'stderr' => 'not used by the packet'];
    }
}
