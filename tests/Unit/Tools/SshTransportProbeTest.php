<?php

namespace Tests\Unit\Tools;

use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use App\Bridge\Tools\AuthorizedKeysRead;
use App\Bridge\Tools\SshProbeEnvironment;
use App\Bridge\Tools\SshTransportProbe;
use PHPUnit\Framework\TestCase;

/**
 * The bridge:check SSH-transport probe (card 4952, Finding D). Drives every root-gated /
 * FIPS / sshd branch through an in-memory {@see SshProbeEnvironment} fake — no root, no
 * sshd, no /proc. Asserts the DR2-3 severity split (unverifiable ⇒ warn, present-but-bad
 * ⇒ fail) and the FIPS-ed25519 red. (The sshd account-posture leg was retired in card 5091 —
 * see test_posture_probe_is_retired_no_account_hardening_assertions.)
 */
class SshTransportProbeTest extends TestCase
{
    private const GOOD_LINE = 'command="php artisan bridge:tools-call --agent=me",restrict ssh-ed25519 AAAAKEYBLOB me';

    /** @param list<Finding> $findings */
    private function hasSeverity(array $findings, Severity $severity): bool
    {
        foreach ($findings as $f) {
            if ($f->severity === $severity) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<Finding>  $findings
     */
    private function firstMatching(array $findings, string $needle): ?Finding
    {
        foreach ($findings as $f) {
            if (str_contains($f->message, $needle)) {
                return $f;
            }
        }

        return null;
    }

    // ─── pinned-line OUTCOME ──────────────────────────────────────────────────

    public function test_good_line_produces_no_fail(): void
    {
        $env = new FakeSshProbeEnvironment(authorizedKeys: self::GOOD_LINE);
        $findings = (new SshTransportProbe($env))->probePinnedLine('me');
        $this->assertFalse($this->hasSeverity($findings, Severity::Fail));
        $this->assertTrue($this->hasSeverity($findings, Severity::Ok));
    }

    public function test_present_but_bad_line_at_assumed_path_fails(): void
    {
        // DR2-3b: a line found at the assumed default path that grants a pty is
        // authoritative-enough to FAIL (not merely warn) even unprivileged.
        $env = new FakeSshProbeEnvironment(authorizedKeys: 'command="php artisan bridge:tools-call --agent=me",restrict,pty ssh-ed25519 AAAA me');
        $findings = (new SshTransportProbe($env))->probePinnedLine('me');
        $this->assertTrue($this->hasSeverity($findings, Severity::Fail));
    }

    public function test_absent_line_at_assumed_path_is_unvalidated_not_a_fail(): void
    {
        // DR2-3b: pure ABSENCE at an assumed (non-authoritative) path must never be a
        // false FAIL — the AuthorizedKeysFile may be relocated, so the file just read may
        // not be the one sshd consults. DL-251 §1(b) re-reads that as UNVALIDATED rather
        // than warn: the read COMPLETED, but the leg cannot stand behind it, because it
        // may have measured the wrong subject. `sudo` is not a flag the operator declined
        // to pass — this leg runs unconditionally, so insufficient euid is a capability
        // the process lacks, not a request that was never made.
        $env = new FakeSshProbeEnvironment(authorizedKeys: "# empty\n");
        $findings = (new SshTransportProbe($env))->probePinnedLine('me');
        $this->assertFalse($this->hasSeverity($findings, Severity::Fail));
        $this->assertTrue($this->hasSeverity($findings, Severity::Unvalidated));
        // The discriminating control: the SAME absence at an AUTHORITATIVE path is still a
        // FAIL (see the test below), so this arm is keyed on authority, not on absence.
        $this->assertFalse($this->hasSeverity($findings, Severity::Warn));
    }

    public function test_absent_line_at_authoritative_path_fails(): void
    {
        // As root, sshd -T resolves the real AuthorizedKeysFile — absence there is a
        // definitive "not wired" FAIL.
        $env = new FakeSshProbeEnvironment(
            authorizedKeys: "# empty\n",
            isRoot: true,
            sshdConfig: "authorizedkeysfile /etc/ssh/keys/%u\npasswordauthentication no\n",
        );
        $findings = (new SshTransportProbe($env))->probePinnedLine('me');
        $this->assertTrue($this->hasSeverity($findings, Severity::Fail));
    }

    public function test_ambiguous_duplicate_lines_fail(): void
    {
        $env = new FakeSshProbeEnvironment(authorizedKeys: self::GOOD_LINE."\n".self::GOOD_LINE."\n");
        $findings = (new SshTransportProbe($env))->probePinnedLine('me');
        $this->assertTrue($this->hasSeverity($findings, Severity::Fail));
    }

    // ─── AuthorizedKeysFile resolution (card#8976) ────────────────────────────
    // `sshd -T` names a LIST of files and four expansion tokens. Resolving only the first
    // file, or leaving a token unexpanded, makes the root run's AUTHORITATIVE absent-line
    // FAIL a claim about a file sshd never consults — a false fail on a correctly-wired
    // seat, and the OpenSSH DEFAULT is two files (`man 5 sshd_config`).

    public function test_pinned_line_in_the_second_default_file_is_found_not_a_false_fail(): void
    {
        // The OpenSSH DEFAULT AuthorizedKeysFile is TWO files. A line pinned in the second
        // is wired correctly; resolving only the first turned it into a root-run FAIL that
        // sent the operator to re-pin a line already present.
        $env = new FakeSshProbeEnvironment(
            isRoot: true,
            sshdConfig: "authorizedkeysfile .ssh/authorized_keys .ssh/authorized_keys2\n",
            keysByPath: [
                '/home/bridge/.ssh/authorized_keys' => "# no pin here\n",
                '/home/bridge/.ssh/authorized_keys2' => self::GOOD_LINE,
            ],
        );

        $findings = (new SshTransportProbe($env))->probePinnedLine('me');

        $this->assertFalse($this->hasSeverity($findings, Severity::Fail));
        $this->assertTrue($this->hasSeverity($findings, Severity::Ok));
        $this->assertSame(
            ['/home/bridge/.ssh/authorized_keys', '/home/bridge/.ssh/authorized_keys2'],
            $env->readPaths,
        );
        // The finding names WHICH file carries the line — with more than one candidate,
        // "the pinned line" alone does not tell an operator where to look.
        $this->assertNotNull($this->firstMatching($findings, 'found in /home/bridge/.ssh/authorized_keys2'));
    }

    public function test_every_documented_token_expands_exactly_once(): void
    {
        // `man 5 sshd_config`: AuthorizedKeysFile accepts %%, %h, %U and %u, and a path
        // that is not absolute after expansion is taken relative to the home directory.
        // Expansion is SINGLE-PASS, so `%%h` is the literal `%h` and never the home.
        $env = new FakeSshProbeEnvironment(
            isRoot: true,
            sshdConfig: "authorizedkeysfile /etc/ssh/keys/%u-%U-%%.pub %h/.ssh/ak2 %%h\n",
            userUids: ['bridge' => 1001],
            keysByPath: ['/etc/ssh/keys/bridge-1001-%.pub' => self::GOOD_LINE],
        );

        $findings = (new SshTransportProbe($env))->probePinnedLine('me');

        $this->assertSame([
            '/etc/ssh/keys/bridge-1001-%.pub',   // %u, %U and %% — absolute, taken as-is
            '/home/bridge/.ssh/ak2',             // %h
            '/home/bridge/%h',                   // %%h is a LITERAL %h, and is relative
        ], $env->readPaths);
        $this->assertFalse($this->hasSeverity($findings, Severity::Fail));
        $this->assertTrue($this->hasSeverity($findings, Severity::Ok));
    }

    public function test_percent_u_with_no_numeric_uid_is_named_never_guessed(): void
    {
        // uidForUser ⇒ null is UNMEASURED (no posix_getpwnam, or no such account). A token
        // this run cannot expand must not become a path it then certifies against: it is
        // named, and the absence of a pinned line is not a conclusion the run may draw.
        $env = new FakeSshProbeEnvironment(
            isRoot: true,
            sshdConfig: "authorizedkeysfile /etc/ssh/keys/%U\n",
            userUids: [],   // the interface's UNMEASURED state
        );

        $findings = (new SshTransportProbe($env))->probePinnedLine('me');

        $this->assertTrue($this->hasSeverity($findings, Severity::Unvalidated));
        $this->assertFalse($this->hasSeverity($findings, Severity::Fail));
        $this->assertFalse($this->hasSeverity($findings, Severity::Ok));
        $this->assertNotNull($this->firstMatching($findings, '/etc/ssh/keys/%U'));
        // No guessed path was read — not the literal `%U`, not a stripped one.
        $this->assertSame([], $env->readPaths);
    }

    public function test_absent_line_at_an_authoritative_path_names_every_file_it_read(): void
    {
        // The FAIL is authoritative, so it must account for its whole population: an
        // operator told "not wired" has to know which files were searched.
        $env = new FakeSshProbeEnvironment(
            isRoot: true,
            sshdConfig: "authorizedkeysfile .ssh/authorized_keys .ssh/authorized_keys2\n",
            keysByPath: [
                '/home/bridge/.ssh/authorized_keys' => "# no pin here\n",
                '/home/bridge/.ssh/authorized_keys2' => "# nor here\n",
            ],
        );

        $findings = (new SshTransportProbe($env))->probePinnedLine('me');

        $this->assertTrue($this->hasSeverity($findings, Severity::Fail));
        $fail = $this->firstMatching($findings, 'not wired');
        $this->assertNotNull($fail);
        $this->assertStringContainsString('/home/bridge/.ssh/authorized_keys ', $fail->message);
        $this->assertStringContainsString('/home/bridge/.ssh/authorized_keys2', $fail->message);
    }

    public function test_an_unreadable_second_file_blocks_the_authoritative_absent_claim(): void
    {
        // One file readable, one not: the pinned line COULD be in the one that was not
        // read, so "not wired" is not establishable. Root-ness makes the PATH authoritative,
        // never the absence — the leg reports what it could not consult, by name.
        $env = new FakeSshProbeEnvironment(
            isRoot: true,
            sshdConfig: "authorizedkeysfile .ssh/authorized_keys .ssh/authorized_keys2\n",
            keysByPath: ['/home/bridge/.ssh/authorized_keys' => "# no pin here\n"],   // file 2 unreadable
        );

        $findings = (new SshTransportProbe($env))->probePinnedLine('me');

        $this->assertTrue($this->hasSeverity($findings, Severity::Unvalidated));
        $this->assertFalse($this->hasSeverity($findings, Severity::Fail));
        $unverified = $this->firstMatching($findings, 'UNVERIFIED');
        $this->assertNotNull($unverified);
        $this->assertStringContainsString('/home/bridge/.ssh/authorized_keys ', $unverified->message);
        $this->assertStringContainsString('/home/bridge/.ssh/authorized_keys2', $unverified->message);
    }

    public function test_an_unresolvable_entry_does_not_block_a_line_that_was_found(): void
    {
        // PRESENCE is establishable from one readable file even when a sibling entry is
        // not resolvable; only the ABSENCE claim needs the whole population.
        $env = new FakeSshProbeEnvironment(
            isRoot: true,
            sshdConfig: "authorizedkeysfile %h/.ssh/authorized_keys /etc/ssh/keys/%U\n",
            keysByPath: ['/home/bridge/.ssh/authorized_keys' => self::GOOD_LINE],
        );

        $findings = (new SshTransportProbe($env))->probePinnedLine('me');

        $this->assertTrue($this->hasSeverity($findings, Severity::Ok));
        $this->assertFalse($this->hasSeverity($findings, Severity::Fail));
    }

    // ─── the MATCH arms' population (card#8976 round 3) ───────────────────────
    // Finding the line needs ONE file; concluding it is the ONLY line sshd honours needs
    // the whole set. DL-359 Decision 4b's security argument is about the SECOND line — an
    // `ok` for an account whose other file grants pty or forwarding for the same agent is
    // WRONG, not merely incomplete — and it does not stop applying because the other file
    // was UNREADABLE rather than unread. The first two cases below are the same disclosure
    // over the two halves `unconsulted()` renders; the third is their discriminating
    // control.

    public function test_a_found_line_beside_an_unreadable_file_discloses_what_it_did_not_cover(): void
    {
        $env = new FakeSshProbeEnvironment(
            isRoot: true,
            sshdConfig: "authorizedkeysfile .ssh/authorized_keys .ssh/authorized_keys2\n",
            keysByPath: ['/home/bridge/.ssh/authorized_keys' => self::GOOD_LINE],   // file 2 unreadable
        );

        $findings = (new SshTransportProbe($env))->probePinnedLine('me');

        // The verdict itself is UNCHANGED — what was read was read, and this disclosure
        // moves no exit code. That is the whole reason it may be added without re-opening
        // the ratified exit-code table.
        $this->assertTrue($this->hasSeverity($findings, Severity::Ok));
        $this->assertFalse($this->hasSeverity($findings, Severity::Fail));

        $disclosure = $this->firstMatching($findings, 'The verdict above covers only what was read');
        $this->assertNotNull($disclosure, 'the ok certified over a population this run did not consult, and said nothing about it');
        $this->assertSame(Severity::Unvalidated, $disclosure->severity);
        $this->assertStringContainsString('/home/bridge/.ssh/authorized_keys2', $disclosure->message);
    }

    public function test_a_found_line_beside_an_unresolvable_entry_names_that_entry(): void
    {
        // The other half of the same claim: an entry whose `%U` this run cannot expand is a
        // file it never reached, so the set it certified over is short by one there too.
        // Same install shape as test_an_unresolvable_entry_does_not_block_a_line_that_was_found
        // and a DIFFERENT subject: that one pins that presence still certifies, this one that
        // the refused entry is still named beside it. Both must hold; neither implies the other.
        $env = new FakeSshProbeEnvironment(
            isRoot: true,
            sshdConfig: "authorizedkeysfile %h/.ssh/authorized_keys /etc/ssh/keys/%U\n",
            keysByPath: ['/home/bridge/.ssh/authorized_keys' => self::GOOD_LINE],
        );

        $findings = (new SshTransportProbe($env))->probePinnedLine('me');

        $this->assertTrue($this->hasSeverity($findings, Severity::Ok));
        $this->assertFalse($this->hasSeverity($findings, Severity::Fail));

        $disclosure = $this->firstMatching($findings, 'The verdict above covers only what was read');
        $this->assertNotNull($disclosure, 'the refused entry was dropped from the certifying arm rather than named');
        $this->assertSame(Severity::Unvalidated, $disclosure->severity);
        $this->assertStringContainsString('/etc/ssh/keys/%U', $disclosure->message);
    }

    public function test_a_fully_consulted_population_adds_no_disclosure_to_the_ok(): void
    {
        // THE DISCRIMINATING CONTROL for the two cases above: with every named file
        // accounted for, the certifying arm must stay a single clean line. Without it the
        // pair would pass just as well against a leg that appended the disclosure always.
        $env = new FakeSshProbeEnvironment(
            isRoot: true,
            sshdConfig: "authorizedkeysfile .ssh/authorized_keys .ssh/authorized_keys2\n",
            keysByPath: ['/home/bridge/.ssh/authorized_keys' => self::GOOD_LINE],
            absentPaths: ['/home/bridge/.ssh/authorized_keys2'],
        );

        $findings = (new SshTransportProbe($env))->probePinnedLine('me');

        $this->assertTrue($this->hasSeverity($findings, Severity::Ok));
        $this->assertFalse($this->hasSeverity($findings, Severity::Unvalidated));
        $this->assertNull($this->firstMatching($findings, 'The verdict above covers only what was read'));
    }

    // ─── consulted vs unread (card#8976 round 2) ──────────────────────────────
    // A file that is NOT THERE gives sshd no keys, so it belongs to the population the
    // authoritative absent-line FAIL is a claim about. Reading it as a file that was not
    // CONSULTED withheld that FAIL on the OpenSSH default — two files, of which
    // `.ssh/authorized_keys2` is absent on essentially every host — so `sudo bridge:check`
    // exited 0 over a genuinely unwired agent.

    public function test_the_default_two_file_shape_with_a_missing_second_file_still_fails(): void
    {
        // THE DEFAULT INSTALL: a real `.ssh/authorized_keys` carrying no pin, and no
        // `.ssh/authorized_keys2` at all. Nothing was withheld from this run — it saw both
        // answers — so the absence is ESTABLISHED and the exit-code-bearing FAIL is earned.
        $env = new FakeSshProbeEnvironment(
            isRoot: true,
            sshdConfig: "authorizedkeysfile .ssh/authorized_keys .ssh/authorized_keys2\n",
            keysByPath: ['/home/bridge/.ssh/authorized_keys' => "# no pin here\n"],
            absentPaths: ['/home/bridge/.ssh/authorized_keys2'],
        );

        $findings = (new SshTransportProbe($env))->probePinnedLine('me');

        $this->assertTrue($this->hasSeverity($findings, Severity::Fail));
        $this->assertFalse($this->hasSeverity($findings, Severity::Unvalidated));
        $fail = $this->firstMatching($findings, 'not wired');
        $this->assertNotNull($fail);
        // Its DISCRIMINATING CONTROL is one line up:
        // test_an_unreadable_second_file_blocks_the_authoritative_absent_claim states the
        // SAME two-file shape with the second file UNREADABLE instead of absent, and gets
        // `unvalidated`. One input differs, and it is the one this pair is about.
        $this->assertStringContainsString('/home/bridge/.ssh/authorized_keys2', $fail->message);
    }

    public function test_every_named_file_absent_is_an_established_absence(): void
    {
        // No authorized_keys anywhere the root-resolved config names. sshd would take no
        // key from any of them, which is the whole content of "not wired".
        $env = new FakeSshProbeEnvironment(
            isRoot: true,
            sshdConfig: "authorizedkeysfile .ssh/authorized_keys .ssh/authorized_keys2\n",
            absentPaths: ['/home/bridge/.ssh/authorized_keys', '/home/bridge/.ssh/authorized_keys2'],
        );

        $findings = (new SshTransportProbe($env))->probePinnedLine('me');

        $this->assertTrue($this->hasSeverity($findings, Severity::Fail));
        $this->assertNotNull($this->firstMatching($findings, 'not wired'));
    }

    public function test_every_named_file_unreadable_withholds_the_fail(): void
    {
        // The control for the case above, and a verdict that MOVED: this shape used to
        // FAIL ("no readable authorized_keys at …"), which is the same conflation one
        // coordinate over — a root run that could not open the file (a squashed NFS root,
        // an ACL, SELinux) measured nothing, and nothing is not an absence.
        $env = new FakeSshProbeEnvironment(
            isRoot: true,
            sshdConfig: "authorizedkeysfile .ssh/authorized_keys .ssh/authorized_keys2\n",
            keysByPath: ['/some/other/path' => self::GOOD_LINE],   // neither named path is in the map
        );

        $findings = (new SshTransportProbe($env))->probePinnedLine('me');

        $this->assertTrue($this->hasSeverity($findings, Severity::Unvalidated));
        $this->assertFalse($this->hasSeverity($findings, Severity::Fail));
        $unverified = $this->firstMatching($findings, 'could not read');
        $this->assertNotNull($unverified);
        $this->assertStringContainsString('/home/bridge/.ssh/authorized_keys ', $unverified->message);
        $this->assertStringContainsString('/home/bridge/.ssh/authorized_keys2', $unverified->message);
    }

    public function test_the_same_pin_in_both_default_files_is_ambiguous_and_fails(): void
    {
        // ⚠ THE ONE SHAPE THAT STARTS FAILING (DL-359 Decision 4): an operator who copied
        // the pinned line into BOTH default files was exit-0 while only the first file was
        // read, and is exit-1 now that both are. sshd reads both, so two lines really do
        // force the command and the ambiguity is real — the same verdict a duplicate
        // WITHIN one file has always drawn.
        $env = new FakeSshProbeEnvironment(
            isRoot: true,
            sshdConfig: "authorizedkeysfile .ssh/authorized_keys .ssh/authorized_keys2\n",
            keysByPath: [
                '/home/bridge/.ssh/authorized_keys' => self::GOOD_LINE,
                '/home/bridge/.ssh/authorized_keys2' => self::GOOD_LINE,
            ],
        );

        $findings = (new SshTransportProbe($env))->probePinnedLine('me');

        $this->assertTrue($this->hasSeverity($findings, Severity::Fail));
        $ambiguous = $this->firstMatching($findings, 'more than one authorized_keys line');
        $this->assertNotNull($ambiguous);
        $this->assertStringContainsString('/home/bridge/.ssh/authorized_keys ', $ambiguous->message);
        $this->assertStringContainsString('/home/bridge/.ssh/authorized_keys2', $ambiguous->message);
    }

    // ─── two entries, ONE file (card#8976 r2) ─────────────────────────────────
    // Nothing stops two AuthorizedKeysFile entries resolving to the same file: a symlinked
    // or hard-linked `.ssh/authorized_keys2`, or one file spelled two ways. Every claim the
    // probe draws is about FILES, so keying them by the path STRING counts one physical
    // line once per spelling — and the ambiguity FAIL then fires, exit 1, on an install
    // that has exactly one, with a remedy ("leave exactly one") the operator cannot follow.

    public function test_two_entries_naming_one_file_are_read_once_and_are_not_ambiguous(): void
    {
        // ⭐ THE FALSE exit-1. Both entries answer the same text because they ARE the same
        // file — which is what makes the string-keyed count read one line as two.
        $env = new FakeSshProbeEnvironment(
            isRoot: true,
            sshdConfig: "authorizedkeysfile .ssh/authorized_keys .ssh/authorized_keys2\n",
            keysByPath: [
                '/home/bridge/.ssh/authorized_keys' => self::GOOD_LINE,
                '/home/bridge/.ssh/authorized_keys2' => self::GOOD_LINE,
            ],
            fileIdentities: [
                '/home/bridge/.ssh/authorized_keys' => 'inode:1',
                '/home/bridge/.ssh/authorized_keys2' => 'inode:1',
            ],
        );

        $findings = (new SshTransportProbe($env))->probePinnedLine('me');

        $this->assertFalse($this->hasSeverity($findings, Severity::Fail));
        $this->assertTrue($this->hasSeverity($findings, Severity::Ok));
        $this->assertNull($this->firstMatching($findings, 'more than one authorized_keys line'));
        // Read ONCE, under the spelling sshd lists first — which is the one an operator
        // sent to edit it has to open.
        $this->assertSame(['/home/bridge/.ssh/authorized_keys'], $env->readPaths);
        // Closing paren included: without it this needle is a PREFIX of the alias spelling
        // and would pass on the very message it exists to rule out.
        $this->assertNotNull($this->firstMatching($findings, 'found in /home/bridge/.ssh/authorized_keys)'));
        // ⛔ ITS DISCRIMINATING CONTROL IS
        // test_the_same_pin_in_both_default_files_is_ambiguous_and_fails, directly above:
        // the SAME fixture — same config, same two paths, the same line in both — differing
        // ONLY in whether those paths name one file, and it must still FAIL. The pair is
        // what proves this arm now asks about file identity rather than being switched off.
    }

    public function test_an_absent_line_over_two_entries_naming_one_file_names_that_file_once(): void
    {
        // The FAIL is authoritative, so it prints the population it searched — and one file
        // listed under two spellings is ONE file to go and look at. Printing both names
        // sends an operator to a second file that does not exist.
        $env = new FakeSshProbeEnvironment(
            isRoot: true,
            sshdConfig: "authorizedkeysfile .ssh/authorized_keys .ssh/authorized_keys2\n",
            keysByPath: [
                '/home/bridge/.ssh/authorized_keys' => "# no pin here\n",
                '/home/bridge/.ssh/authorized_keys2' => "# no pin here\n",
            ],
            fileIdentities: [
                '/home/bridge/.ssh/authorized_keys' => 'inode:1',
                '/home/bridge/.ssh/authorized_keys2' => 'inode:1',
            ],
        );

        $findings = (new SshTransportProbe($env))->probePinnedLine('me');

        // The verdict is unchanged — one file, searched, with no pinned line in it.
        $this->assertTrue($this->hasSeverity($findings, Severity::Fail));
        $fail = $this->firstMatching($findings, 'not wired');
        $this->assertNotNull($fail);
        $this->assertSame(1, substr_count($fail->message, '/home/bridge/.ssh/authorized_keys'));
        $this->assertStringNotContainsString('authorized_keys2', $fail->message);
    }

    public function test_a_home_with_a_trailing_slash_yields_one_spelling_for_one_file(): void
    {
        // ⛔ NEEDS NO ODD CONFIG — the OpenSSH default's own two spellings. `%h/.ssh/…`
        // interpolated the home RAW while a relative entry stripped its trailing slash, so
        // an account whose pw_dir carries one produced `/home/agent//.ssh/authorized_keys`
        // and `/home/agent/.ssh/authorized_keys` for ONE file. Both arms take the home from
        // one primitive now, so the two entries collapse before any identity is consulted —
        // asserted on readPaths, which is what the fake can see.
        $env = new FakeSshProbeEnvironment(
            isRoot: true,
            sshdConfig: "authorizedkeysfile %h/.ssh/authorized_keys .ssh/authorized_keys\n",
            userHomes: ['device' => '/home/device/'],
            keysByPath: ['/home/device/.ssh/authorized_keys' => self::GOOD_LINE],
        );

        $findings = (new SshTransportProbe($env, 'device'))->probePinnedLine('me');

        $this->assertSame(['/home/device/.ssh/authorized_keys'], $env->readPaths);
        $this->assertFalse($this->hasSeverity($findings, Severity::Fail));
        $this->assertTrue($this->hasSeverity($findings, Severity::Ok));
    }

    public function test_percent_h_not_followed_by_a_separator_stays_a_concatenation(): void
    {
        // ⛔ THE BOUND ON THE STRIP ABOVE. sshd substitutes `pw_dir` verbatim, so `%hfoo`
        // under a home of `/home/device/` is `/home/device/foo`. Stripping the home's
        // trailing slash unconditionally would send this probe at `/home/devicefoo` — a
        // file sshd never consults, read as absent, and an authoritative "not wired" FAIL
        // over a correctly wired account. Normalising a JOIN is not licence to rewrite a
        // concatenation.
        $env = new FakeSshProbeEnvironment(
            isRoot: true,
            sshdConfig: "authorizedkeysfile %hfoo\n",
            userHomes: ['device' => '/home/device/'],
            keysByPath: ['/home/device/foo' => self::GOOD_LINE],
        );

        $findings = (new SshTransportProbe($env, 'device'))->probePinnedLine('me');

        $this->assertSame(['/home/device/foo'], $env->readPaths);
        $this->assertTrue($this->hasSeverity($findings, Severity::Ok));
    }

    // ─── FIPS ─────────────────────────────────────────────────────────────────

    public function test_fips_mode_with_ed25519_key_fails(): void
    {
        $env = new FakeSshProbeEnvironment(authorizedKeys: self::GOOD_LINE, fips: true);
        $findings = (new SshTransportProbe($env))->probePinnedLine('me');
        $this->assertTrue($this->hasSeverity($findings, Severity::Fail));
    }

    public function test_fips_mode_with_ecdsa_key_passes(): void
    {
        $env = new FakeSshProbeEnvironment(
            authorizedKeys: 'command="php artisan bridge:tools-call --agent=me",restrict ecdsa-sha2-nistp256 AAAA me',
            fips: true,
        );
        $findings = (new SshTransportProbe($env))->probePinnedLine('me');
        $this->assertFalse($this->hasSeverity($findings, Severity::Fail));
    }

    public function test_the_fips_message_bounds_the_key_algorithm_field_it_echoes(): void
    {
        // ⛔ THE KEY-ALGORITHM FIELD IS FILE CONTENT, and it is not bounded at whitespace:
        // AuthorizedKeysLine splits the first field on UNQUOTED whitespace, so a quoted
        // field carries the rest of the line into the message. The echo is bounded so the
        // record of what these arms print stays true under a hostile line.
        $long = str_repeat('x', 200);
        $env = new FakeSshProbeEnvironment(
            authorizedKeys: 'command="php artisan bridge:tools-call --agent=me",restrict "'.$long.' trailing-marker" AAAA me',
            fips: true,
        );

        $findings = (new SshTransportProbe($env))->probePinnedLine('me');

        $fail = $this->firstMatching($findings, 'a FIPS sshd rejects it');
        $this->assertNotNull($fail);
        $this->assertStringContainsString('(truncated)', $fail->message);
        $this->assertStringNotContainsString('trailing-marker', $fail->message);
        // The bound is on the ECHO, not on the sentence: everything after it survives, so
        // an assertion on the length of the whole message would be measuring the wrong
        // thing. What is measured is the quoted span itself.
        $this->assertSame(1, preg_match('/is `([^`]*)`/', $fail->message, $m));
        // 64 is SshTransportProbe::KEY_ALGORITHM_ECHO_MAX, which is private — spelled here
        // deliberately, so moving the bound has to come through this assertion.
        $this->assertLessThanOrEqual(64, strlen($m[1]));
    }

    // ─── retired: sshd account posture (card 5091) ────────────────────────────
    // The password-auth + idle/concurrency posture assertions were REMOVED: they demanded
    // the account-level `Match User` drop-in that card 5091 retired (it locked out an
    // operator sharing the ssh-account). The sole board-tools boundary is now the pinned
    // forced-command key (probePinnedLine, above) + the live round-trip (probeLive, below).
    // The absence guard below fails RED if probeSshdPosture is reintroduced.

    public function test_posture_probe_is_retired_no_account_hardening_assertions(): void
    {
        $this->assertFalse(
            method_exists(SshTransportProbe::class, 'probeSshdPosture'),
            'the retired sshd account-posture leg (operator-lockout, card 5091) must not return',
        );
    }

    // ─── forced-command account resolution (card 4977) ───────────────────────

    public function test_split_topology_resolves_the_configured_ssh_account_not_the_invoker(): void
    {
        // Invoking account is root (sudo bridge:check); the forced command runs as
        // `device`. The authorized_keys resolution must target `device`, never root.
        $env = new FakeSshProbeEnvironment(
            authorizedKeys: self::GOOD_LINE,
            isRoot: true,
            sshdConfig: "authorizedkeysfile %h/.ssh/authorized_keys\n",
            runUser: 'root',
            runUserHome: '/root',
            userHomes: ['device' => '/home/device'],
        );
        $probe = new SshTransportProbe($env, 'device');

        $findings = $probe->probePinnedLine('me');

        // authorized_keys resolved via device's %h, NOT /root/...
        $this->assertContains('/home/device/.ssh/authorized_keys', $env->readPaths);
        $this->assertNotContains('/root/.ssh/authorized_keys', $env->readPaths);

        // The sshd -T AuthorizedKeysFile lookup targeted `device`, never root.
        $this->assertContains('device', $env->sshdQueriedUsers);
        $this->assertNotContains('root', $env->sshdQueriedUsers);

        // The pinned forced-command line for device resolved clean.
        $this->assertFalse($this->hasSeverity($findings, Severity::Fail));
        $this->assertTrue($this->hasSeverity($findings, Severity::Ok));
    }

    public function test_fallback_unset_account_queries_the_invoking_account_exactly_as_before(): void
    {
        // canon #6: ssh_account unset ⇒ byte-identical to pre-4977 — the authorized_keys
        // sshd query passes NO -C (null) and the keys path uses the invoking run-user's home.
        $env = new FakeSshProbeEnvironment(
            authorizedKeys: self::GOOD_LINE,
            isRoot: true,
            sshdConfig: "authorizedkeysfile .ssh/authorized_keys\n",
            runUser: 'bridge',
            runUserHome: '/home/bridge',
        );
        $probe = new SshTransportProbe($env);   // no ssh_account

        $probe->probePinnedLine('me');

        $this->assertSame('bridge', $probe->forcedCommandAccount());
        // The keys-path sshd query passed no -C (null) — byte-identical to pre-4977.
        $this->assertContains(null, $env->sshdQueriedUsers);
        // The default keys path used the invoking run-user's home.
        $this->assertContains('/home/bridge/.ssh/authorized_keys', $env->readPaths);
    }

    // ─── configured ssh_account that does not resolve (card 4977, Defect 2) ────

    public function test_configured_ssh_account_that_does_not_resolve_fails_not_phantom_path(): void
    {
        // A configured ssh_account with no OS account (homeForUser ⇒ '') must fail honestly
        // on every account-dependent leg — never certify against a phantom
        // '/.ssh/authorized_keys' built from the empty home.
        $env = new FakeSshProbeEnvironment(
            authorizedKeys: self::GOOD_LINE,
            isRoot: true,
            sshdConfig: "authorizedkeysfile %h/.ssh/authorized_keys\n",
            userHomes: ['ghost' => ''],   // '' models an account posix_getpwnam cannot resolve
        );
        $probe = new SshTransportProbe($env, 'ghost');

        $pinned = $probe->probePinnedLine('me');

        $this->assertTrue($this->hasSeverity($pinned, Severity::Fail));
        $this->assertFalse($this->hasSeverity($pinned, Severity::Ok));
        $this->assertNotNull($this->firstMatching($pinned, 'does not resolve to an OS account'));
        // No phantom-path read attempted (the leg fails before authorizedKeysPath).
        $this->assertNotContains('/.ssh/authorized_keys', $env->readPaths);
    }

    public function test_account_lookup_capability_absent_is_unvalidated_never_the_accusation(): void
    {
        // DL-259 (card#5698): homeForUser ⇒ null models a host with no posix_getpwnam, so
        // the account database was never consulted. The old code returned '' for that too
        // and spent it as the exit-code-bearing "does not resolve to an OS account on this
        // host" — hard-failing bridge:check over an account that may be perfectly valid.
        // The certification still cannot proceed, so it must still REPORT; what it may not
        // do is accuse.
        $env = new FakeSshProbeEnvironment(
            authorizedKeys: self::GOOD_LINE,
            isRoot: true,
            sshdConfig: "authorizedkeysfile %h/.ssh/authorized_keys\n",
            userHomes: ['device' => null],
        );
        $probe = new SshTransportProbe($env, 'device');

        $pinned = $probe->probePinnedLine('me');

        $this->assertTrue($this->hasSeverity($pinned, Severity::Unvalidated));
        $this->assertFalse($this->hasSeverity($pinned, Severity::Fail));
        $this->assertFalse($this->hasSeverity($pinned, Severity::Ok));
        // The claim names the missing capability, and never the account's existence.
        $this->assertNotNull($this->firstMatching($pinned, 'no posix_getpwnam'));
        $this->assertNull($this->firstMatching($pinned, 'does not resolve to an OS account'));
        // Same phantom-path guarantee as the measured arm: it stops before the read.
        $this->assertNotContains('/.ssh/authorized_keys', $env->readPaths);
    }

    public function test_unset_account_with_empty_run_user_home_is_unvalidated_not_hard_fail(): void
    {
        // canon #6: the UNSET fallback path (runUserHome ⇒ '') is a pre-existing edge,
        // deliberately unchanged in KIND — it stays non-authoritative, never the
        // configured-account hard-fail. Pins that the Defect-2 gate is sshAccount-strict.
        // DL-251 §1(a) moves the non-authoritative arm to `unvalidated`: the read did not
        // complete at all here, so nothing was measured.
        $env = new FakeSshProbeEnvironment(
            authorizedKeys: '',   // nothing readable at the assumed default path
            runUserHome: '',
        );
        $probe = new SshTransportProbe($env);   // no ssh_account

        $findings = $probe->probePinnedLine('me');

        $this->assertTrue($this->hasSeverity($findings, Severity::Unvalidated));
        $this->assertFalse($this->hasSeverity($findings, Severity::Fail));
    }

    // ─── live probe ───────────────────────────────────────────────────────────

    public function test_live_probe_clean_matching_scope_passes(): void
    {
        $env = new FakeSshProbeEnvironment(
            authorizedKeys: self::GOOD_LINE,
            sshStdout: (string) json_encode(['ok' => true, 'tool' => 'board_my_cards', 'result' => [
                'board_id' => 10, 'board_observed' => true, 'configured_board_id' => 10, 'swimlane_id' => 4,
            ]]),
        );
        $findings = (new SshTransportProbe($env))->probeLive('me@host', [['agent' => 'me', 'board_id' => 10, 'swimlane_id' => 4]]);
        $this->assertFalse($this->hasSeverity($findings, Severity::Fail));
        $this->assertTrue($this->hasSeverity($findings, Severity::Ok));
        // card#7325: this leg crosses a HOST, so its line is the ONLY place the remote
        // install's header spelling is observable — a healthy one says so explicitly.
        $this->assertStringContainsString('Header spelling: `configured_board_id`', $findings[0]->message);
        $this->assertStringNotContainsString('Header spelling: LEGACY', $findings[0]->message);
    }

    /**
     * The window is EMPTY, so the tool observed no board and answers `board_id: null`
     * (DL-302) — the scope header is what this leg certifies against, and reading the
     * observation instead would fail every agent whose lane happens to be empty.
     */
    public function test_live_probe_certifies_from_the_header_when_no_board_was_observed(): void
    {
        $env = new FakeSshProbeEnvironment(
            authorizedKeys: self::GOOD_LINE,
            sshStdout: (string) json_encode(['ok' => true, 'tool' => 'board_my_cards', 'result' => [
                'board_id' => null, 'board_observed' => false, 'configured_board_id' => 10, 'swimlane_id' => 4,
            ]]),
        );
        $findings = (new SshTransportProbe($env))->probeLive('me@host', [['agent' => 'me', 'board_id' => 10, 'swimlane_id' => 4]]);
        $this->assertFalse($this->hasSeverity($findings, Severity::Fail));
        $this->assertTrue($this->hasSeverity($findings, Severity::Ok));
    }

    /**
     * A remote install predating the DL-302 header rename answers the scope under the old
     * `board_id` key alone. This leg crosses a HOST outright (the ssh target is a different
     * install from the one running bridge:check), so a strict read would report an identity
     * mismatch for a version skew. Its HTTP twin
     * (`BoardToolsHttpProbeCheckTest::test_a_responder_predating_the_header_rename_is_read_under_the_old_key`)
     * covers the same tolerance on the HTTP leg, which can meet a skewed responder too —
     * `--probe-tools` POSTs to an operator-supplied vhost and this repo runs prod + dev
     * installs co-resident at independent versions (CLAUDE.md rule 7). ⛔ Do not delete
     * either with a reading of this repo — card#7325 (DL-304) owns when the fallback
     * goes, and the answer depends on installs neither test can see.
     */
    public function test_live_probe_accepts_a_responder_predating_the_header_rename(): void
    {
        $env = new FakeSshProbeEnvironment(
            authorizedKeys: self::GOOD_LINE,
            sshStdout: (string) json_encode(['ok' => true, 'tool' => 'board_my_cards', 'result' => ['board_id' => 10, 'swimlane_id' => 4]]),
        );
        $findings = (new SshTransportProbe($env))->probeLive('me@host', [['agent' => 'me', 'board_id' => 10, 'swimlane_id' => 4]]);
        $this->assertTrue($this->hasSeverity($findings, Severity::Ok));
        // ⚠ ACCEPTED, NEVER SILENT (card#7325, DL-304). The board compared here came from
        // a key that a DL-302-or-later responder uses for a row OBSERVATION, so the ok
        // line reports the skew: this is the measurement the fallback's removal condition
        // is read off, and it is about a host this repo cannot otherwise see.
        $this->assertStringContainsString('Header spelling: LEGACY', $findings[0]->message);
        $this->assertStringContainsString('legacy `board_id` spelling', $findings[0]->message);
        $this->assertStringContainsString('card#7325', $findings[0]->message);
    }

    public function test_live_probe_dirty_stdout_fails(): void
    {
        $env = new FakeSshProbeEnvironment(
            authorizedKeys: self::GOOD_LINE,
            sshStdout: "PHP Warning: something\n".json_encode(['ok' => true, 'result' => ['board_id' => 10, 'swimlane_id' => 4]]),
        );
        $findings = (new SshTransportProbe($env))->probeLive('me@host', [['agent' => 'me', 'board_id' => 10, 'swimlane_id' => 4]]);
        $this->assertTrue($this->hasSeverity($findings, Severity::Fail));
    }

    public function test_live_probe_identity_mismatch_fails(): void
    {
        $env = new FakeSshProbeEnvironment(
            authorizedKeys: self::GOOD_LINE,
            sshStdout: (string) json_encode(['ok' => true, 'result' => ['configured_board_id' => 99, 'swimlane_id' => 99]]),
        );
        $findings = (new SshTransportProbe($env))->probeLive('me@host', [['agent' => 'me', 'board_id' => 10, 'swimlane_id' => 4]]);
        $this->assertTrue($this->hasSeverity($findings, Severity::Fail));
        $this->assertStringContainsString('IDENTITY MISMATCH — board_my_cards answered for board=99 swimlane=99', $findings[0]->message);
        // This responder DID identify itself, as another agent — the key cause and the
        // key remediation belong here, and this is the presence witness that keeps the
        // absent branch below from being satisfied by collapsing both onto one wording.
        $this->assertStringContainsString("resolved to a DIFFERENT agent's window", $findings[0]->message);
        $this->assertStringContainsString('mis-pinned key or a stale forced-command --agent', $findings[0]->message);
    }

    /**
     * ⛔ THE CAUSE THE ABSENT BRANCH HAS, NOT THE ONE THE OTHER BRANCH HAS (card#7325).
     * *The pinned key resolved to a DIFFERENT agent* is unavailable here — nothing
     * identified the responder at all, and the provenance sentence in the same string
     * says so. Reachable without a mis-pinned anything: the forced command can land on a
     * relay, or on any JSON responder that is not `board_my_cards`, and the key
     * remediation would send the operator at a key that is doing its job.
     */
    public function test_live_probe_an_absent_header_names_no_identity_rather_than_a_wrong_key(): void
    {
        $env = new FakeSshProbeEnvironment(
            authorizedKeys: self::GOOD_LINE,
            sshStdout: (string) json_encode(['ok' => true, 'result' => []]),
        );
        $findings = (new SshTransportProbe($env))->probeLive('me@host', [['agent' => 'me', 'board_id' => 10, 'swimlane_id' => 4]]);
        $this->assertTrue($this->hasSeverity($findings, Severity::Fail));
        $this->assertStringContainsString('IDENTITY MISMATCH — board_my_cards answered for board=null swimlane=null', $findings[0]->message);
        $this->assertStringContainsString('Header spelling: NEITHER', $findings[0]->message);
        $this->assertStringContainsString("does not show which agent the pinned key reached — check what me@host's forced command actually ran", $findings[0]->message);
        $this->assertStringNotContainsString('DIFFERENT agent', $findings[0]->message);
        $this->assertStringNotContainsString('mis-pinned key', $findings[0]->message);
    }

    public function test_live_probe_unreachable_fails(): void
    {
        $env = new FakeSshProbeEnvironment(authorizedKeys: self::GOOD_LINE, sshExit: 255, sshStderr: 'Connection refused');
        $findings = (new SshTransportProbe($env))->probeLive('me@host', [['agent' => 'me', 'board_id' => 10, 'swimlane_id' => 4]]);
        $this->assertTrue($this->hasSeverity($findings, Severity::Fail));
    }
}

/**
 * In-memory {@see SshProbeEnvironment} — every host fact is a constructor field so a
 * test drives the root / FIPS / sshd / ssh-round-trip branches deterministically.
 */
class FakeSshProbeEnvironment implements SshProbeEnvironment
{
    /** @var list<?string> every `user=` passed to sshd -T (null = no -C). */
    public array $sshdQueriedUsers = [];

    /** @var list<string> every path readAuthorizedKeys was asked for. */
    public array $readPaths = [];

    /**
     * @param  array<string, ?string>  $userHomes  home dir per named account (homeForUser);
     *                                             '' = no such account, null = cannot look up
     * @param  array<string, ?string>  $keysByPath  per-PATH authorized_keys text, for the
     *                                              multi-file AuthorizedKeysFile cases. When
     *                                              non-empty it REPLACES $authorizedKeys as
     *                                              the answer: a path that is absent from the
     *                                              map (or maps to null/'') reads as
     *                                              UNREADABLE, which is how a 0600 file this
     *                                              run may not open is modelled.
     * @param  list<string>  $absentPaths  the paths with NO FILE at them — the third state
     *                                     {@see AuthorizedKeysRead} exists for, and NOT the
     *                                     same fixture as an unreadable one: an absent file
     *                                     was consulted and contributes nothing, so an
     *                                     absence drawn over it is established. It wins over
     *                                     $keysByPath so a case cannot state both.
     * @param  array<string, string>  $fileIdentities  the paths that name the SAME PHYSICAL
     *                                                 FILE, as path => shared identity token (a path
     *                                                 absent from the map is its own identity, i.e. its
     *                                                 own file). This is the ALIASED shape — one file
     *                                                 reachable under two `AuthorizedKeysFile` entries,
     *                                                 by symlink, hard link or a second spelling.
     *                                                 ⚑ STATED, not measured: what the real filesystem
     *                                                 answers for a symlink, a hard link and a `//`
     *                                                 spelling is measured in
     *                                                 SystemSshProbeEnvironmentTest (NAMED rather than
     *                                                 `{@see}`-linked; pint would import it), and this
     *                                                 map is what the PROBE does with that answer.
     */
    public function __construct(
        private string $authorizedKeys = '',
        private bool $isRoot = false,
        private bool $fips = false,
        private ?string $sshdConfig = null,
        private int $sshExit = 0,
        private string $sshStdout = '',
        private string $sshStderr = '',
        private string $runUser = 'bridge',
        private string $runUserHome = '/home/bridge',
        /** @var array<string, ?string> */
        private array $userHomes = [],
        /** @var array<string, ?int> */
        private array $userUids = [],
        private ?int $euid = null,
        /** @var array<string, ?string> */
        private array $keysByPath = [],
        /** @var list<string> */
        private array $absentPaths = [],
        /** @var array<string, string> */
        private array $fileIdentities = [],
    ) {}

    public function isRoot(): bool
    {
        return $this->isRoot;
    }

    public function fipsEnabled(): bool
    {
        return $this->fips;
    }

    public function runUser(): string
    {
        return $this->runUser;
    }

    public function runUserHome(): string
    {
        return $this->runUserHome;
    }

    public function homeForUser(string $user): ?string
    {
        // A null entry models a host with no posix_getpwnam — the lookup never happened.
        return array_key_exists($user, $this->userHomes) ? $this->userHomes[$user] : "/home/{$user}";
    }

    public function uidForUser(string $user): ?int
    {
        return array_key_exists($user, $this->userUids) ? $this->userUids[$user] : null;
    }

    public function euid(): ?int
    {
        return $this->euid;
    }

    public function sshdEffectiveConfig(?string $forUser = null): ?string
    {
        $this->sshdQueriedUsers[] = $forUser;

        // Mirrors the real impl: unavailable (null) unless root.
        return $this->isRoot ? $this->sshdConfig : null;
    }

    public function readAuthorizedKeys(string $path): AuthorizedKeysRead
    {
        $this->readPaths[] = $path;

        if (in_array($path, $this->absentPaths, true)) {
            return AuthorizedKeysRead::absent();
        }
        if ($this->keysByPath !== []) {
            $text = $this->keysByPath[$path] ?? null;

            return ($text === null || $text === '')
                ? AuthorizedKeysRead::unreadable()
                : AuthorizedKeysRead::text($text);
        }

        return $this->authorizedKeys === ''
            ? AuthorizedKeysRead::unreadable()
            : AuthorizedKeysRead::text($this->authorizedKeys);
    }

    public function fileIdentity(string $path): string
    {
        return $this->fileIdentities[$path] ?? $path;
    }

    public function sshRoundTrip(string $target, string $stdin): array
    {
        return ['exit' => $this->sshExit, 'stdout' => $this->sshStdout, 'stderr' => $this->sshStderr];
    }
}
