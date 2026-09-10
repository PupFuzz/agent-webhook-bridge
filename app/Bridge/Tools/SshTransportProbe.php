<?php

namespace App\Bridge\Tools;

use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;

/**
 * The offline SSH-transport pinned-line + sshd-posture probe for `bridge:check`
 * (Finding D, card 4952). Every assertion is OUTCOME-based and fails SAFE:
 *
 *  - The pinned `authorized_keys` line for an ssh agent must (exactly-once) force
 *    `bridge:tools-call --agent=<X>` and DENY pty + all forwarding (asserted via the
 *    {@see AuthorizedKeysLine} last-writer-wins capability model, never a `restrict`
 *    keyword match). On a FIPS seat its key algorithm must be FIPS-approved (an
 *    ed25519 key would never authenticate ⇒ FAIL).
 *  - The `authorized_keys` PATHS are resolved from the Match-resolved `sshd -T` when this
 *    process can run it. `sshd -T` needs root (it loads host private keys); run
 *    unprivileged, the path falls back to the account's assumed default, so any verdict
 *    drawn there may be about the WRONG FILE — it reports UNVERIFIED + the
 *    `sudo bridge:check` cert step (F1 + DR2-3), NEVER a false OK and NEVER a hard fail
 *    (new-surface installs stay exit-0 with a loud line, not a CI red).
 *    PLURAL, and every token expanded: `AuthorizedKeysFile` names a whitespace-separated
 *    LIST (the OpenSSH default is two files) and accepts `%%`, `%h`, `%u` and `%U`. The
 *    pinned line may sit in ANY of them, so all are read; an absent line is only the
 *    authoritative "not wired" FAIL when every one of them was actually consulted, and
 *    an entry this run cannot resolve or read is named rather than skipped (card#8976).
 *    ⭐ AND THAT NAMING HAPPENS BESIDE A LINE THAT *WAS* FOUND TOO (card#8976 r3): finding
 *    the line needs one file, concluding it is the only one sshd honours needs the set, so
 *    the certifying arm keeps its verdict and discloses what it did not cover.
 *    ⭐ CONSULTED, not present: a file that is NOT THERE gives sshd no keys, so it counts
 *    toward that population — the FAIL is withheld only for an entry this run could not
 *    RESOLVE or could not LOOK AT ({@see AuthorizedKeysRead}). Reading a missing file as an
 *    unread one made the FAIL unreachable on the OpenSSH default, whose second file is
 *    absent on essentially every host.
 *    ⭐ FILES, not path strings: two entries can name ONE file (a symlinked or hard-linked
 *    `authorized_keys2`, or one file spelled two ways), and every claim here — the
 *    population, the list printed, the COUNT the ambiguity FAIL is taken on — is about
 *    files, so they are deduplicated by {@see SshProbeEnvironment::fileIdentity()} before
 *    anything is read. Keyed by the string, one physical line counted once per spelling and
 *    the ambiguity FAIL fired on an install that had exactly one (card#8976 r2).
 *    (THERE IS NO sshd-POSTURE LEG. An earlier revision of this docblock described a
 *    required `PasswordAuthentication no` check; card#5091 RETIRED that leg — the
 *    account-level drop-in it certified locked out an operator sharing the ssh account —
 *    and left this text behind. The forced-command key is the sole boundary.)
 *
 * Emits {@see Finding}s over the shared {@see Severity} vocabulary;
 * only `fail` flips `bridge:check`'s exit. It constructs **Ok/Fail/Unvalidated** today and
 * NO `warn` at all — a statement about these legs, not a constraint on the vocabulary. The
 * UNVERIFIED legs above were the open question {@see Severity} used to name; DL-251 settled
 * it and swept them, because a leg that could not read the file, or read the wrong one,
 * did not answer its own question. ABSENT pinned line at an ASSUMED (non-authoritative)
 * path ⇒ `unvalidated` (the AuthorizedKeysFile may be relocated); a PRESENT-BUT-BAD line,
 * or an absent line at an AUTHORITATIVE (root-resolved) path THE RUN CONSULTED IN FULL,
 * ⇒ `fail` (DR2-3b — the qualification is the paragraph above, and it is not a footnote:
 * an authoritative path whose population this run only PARTLY consulted reports
 * `unvalidated`, because the line may be in the part it never saw).
 */
final class SshTransportProbe
{
    /**
     * How many BYTES of the matched line's key-algorithm field an operator message may
     * echo. {@see self::keyAlgorithmForMessage()} owns the reason it is bounded at all.
     * The figure is DERIVED, not picked: the longest key type OpenSSH defines is
     * `sk-ecdsa-sha2-nistp256-cert-v01@openssh.com` — 43 ASCII bytes (`ssh -Q key`,
     * OpenSSH 9.6p1) — so 64 cannot truncate a real algorithm name, only a field that is
     * carrying something else.
     */
    private const KEY_ALGORITHM_ECHO_MAX = 64;

    /**
     * @param  ?string  $sshAccount  the OS account the SSH forced command runs as
     *                               (board_tools.ssh_account). Null ⇒ the invoking
     *                               run-user (byte-identical to pre-4977).
     */
    public function __construct(private SshProbeEnvironment $env, private ?string $sshAccount = null) {}

    /**
     * The OS account the forced command runs as — what sshd posture and
     * authorized_keys must be certified against. Defaults to the invoking run-user.
     */
    public function forcedCommandAccount(): string
    {
        return $this->sshAccount ?? $this->env->runUser();
    }

    /**
     * The forced-command account's home (for the default authorized_keys path + %h).
     *
     * The `?? ''` narrows the un-lookup-able account back to the phantom-home value, and is
     * never reached with it: {@see self::configuredAccountUnresolved} returns a finding for
     * exactly that state and every caller of this method sits behind that early return.
     */
    private function forcedCommandHome(): string
    {
        return $this->sshAccount !== null
            ? ($this->env->homeForUser($this->sshAccount) ?? '')
            : $this->env->runUserHome();
    }

    /**
     * A CONFIGURED ssh_account whose home does not resolve cannot be certified — every
     * account-dependent leg would otherwise build a phantom path from an empty home (e.g.
     * `/.ssh/authorized_keys`) and mis-certify against it. Gated strictly on a non-null
     * sshAccount: the unset fallback (runUserHome, which can also be '') keeps its
     * pre-4977 non-authoritative behavior, untouched — that arm now reports `unvalidated`
     * rather than `warn` (DL-251), which is a severity change and not a behavior one.
     *
     * TWO CAUSES, TWO SEVERITIES (DL-259, card#5698). Both block certification, so both
     * report; they differ in what this run is entitled to say about WHY:
     *   - the account database answered "no such account" ⇒ a MEASURED config fault, and
     *     the `fail` is earned exactly as before;
     *   - this process cannot look accounts up at all (no posix_getpwnam) ⇒ nothing was
     *     measured, and the old code spent that as the same accusation — hard-failing
     *     `bridge:check` over a perfectly valid account on any host without the extension.
     *     That is limb (a) of {@see Severity}'s rule, so it is `unvalidated`.
     */
    private function configuredAccountUnresolved(): ?Finding
    {
        if ($this->sshAccount === null) {
            return null;
        }

        $home = $this->env->homeForUser($this->sshAccount);
        if ($home === null) {
            return Finding::unvalidated("board_tools.ssh_account '{$this->sshAccount}' could NOT be resolved: this PHP process has no posix_getpwnam, so it cannot look OS accounts up at all and never consulted the account database — whether the account exists is UNKNOWN and its absence is NOT a conclusion this run may draw. The SSH transport is left uncertified; enable the posix extension, or certify from a host that has it.");
        }
        if ($home === '') {
            return Finding::fail("board_tools.ssh_account '{$this->sshAccount}' does not resolve to an OS account on this host — the SSH transport cannot be certified");
        }

        return null;
    }

    /**
     * @return list<Finding>
     */
    public function probePinnedLine(string $agentName): array
    {
        if (($unresolved = $this->configuredAccountUnresolved()) !== null) {
            return [$unresolved];
        }

        $findings = [];
        [$paths, $authoritative, $unresolvable] = $this->authorizedKeysPaths();

        // ⭐ THREE OUTCOMES PER FILE, NOT TWO (card#8976 r2). A file that is NOT THERE was
        // consulted — it supplies sshd no keys, so the absence it leaves is ESTABLISHED and
        // the authoritative FAIL below is earned. Only a file this run could not LOOK at
        // leaves the population incomplete. {@see AuthorizedKeysRead} owns the distinction;
        // collapsing it made `$unreadable` non-empty on every OpenSSH default install
        // (`.ssh/authorized_keys2` is absent on essentially every host), which put the
        // exit-code-bearing FAIL out of reach exactly where it was needed.
        /** @var array<string, string> $read  path => text, for the files that carried one */
        $read = [];
        /** @var list<string> $consulted every path whose contribution this run established */
        $consulted = [];
        /** @var list<string> $unreadable every path this run could not look at at all */
        $unreadable = [];
        foreach ($paths as $path) {
            $result = $this->env->readAuthorizedKeys($path);
            if (! $result->consulted) {
                $unreadable[] = $path;

                continue;
            }
            $consulted[] = $path;
            if ($result->text !== null) {
                $read[$path] = $result->text;
            }
        }

        if ($consulted === []) {
            // NOT ONE FILE ANSWERED, so there is no population to draw an absence over at
            // all — this arm reports the READ, where every arm below reports the LINE. An
            // UNRESOLVABLE entry is not a file this run may report on (it does not know its
            // path), and `$unresolvable` is non-empty only on the root-resolved branch, so
            // the non-authoritative arm is always the one assumed default path.
            $findings[] = $authoritative
                ? Finding::unvalidated("no authorized_keys file could be consulted for agent {$agentName}: this run ".$this->unconsulted($unreadable, $unresolvable).' — the pinned line is UNVERIFIED, and its absence is NOT a conclusion this run may draw')
                : Finding::unvalidated('could not read '.implode(' ', $unreadable)." (assumed default; the AuthorizedKeysFile may be relocated — re-run as root to resolve it) — the pinned line for agent {$agentName} is UNVERIFIED");

            return $findings;
        }

        /** @var list<array{path: string, line: AuthorizedKeysLine}> $matches */
        $matches = [];
        foreach ($read as $path => $content) {
            foreach (AuthorizedKeysLine::parseFile($content) as $l) {
                if ($l->forcesToolsCallFor($agentName)) {
                    $matches[] = ['path' => $path, 'line' => $l];
                }
            }
        }

        if ($matches === []) {
            // ABSENCE is a claim about the WHOLE set of files sshd consults for this
            // account, so one file this run could not LOOK AT (or an entry it could not
            // resolve) unmakes it — the line may be in exactly that one. Root-ness makes
            // the PATHS authoritative; it does not make a partial search complete.
            // ⛔ A file that was not THERE does not unmake it, and reading it as though it
            // did is what put this FAIL out of reach on the two-file OpenSSH default: sshd
            // takes no keys from a file that does not exist, so that file is searched, and
            // the search over it came back empty.
            if ($unreadable !== [] || $unresolvable !== []) {
                $findings[] = Finding::unvalidated("no authorized_keys line forces bridge:tools-call --agent={$agentName} — this run consulted ".implode(' ', $consulted).' and did NOT consult every file sshd names for that account: it '.$this->unconsulted($unreadable, $unresolvable).'. The line may be in one of those, so this is UNVERIFIED, not absent');

                return $findings;
            }

            $findings[] = $authoritative
                ? Finding::fail("no authorized_keys line forces bridge:tools-call --agent={$agentName} at ".implode(' ', $paths).' — the ssh transport for this agent is not wired')
                : Finding::unvalidated("no authorized_keys line forces bridge:tools-call --agent={$agentName} at ".implode(' ', $paths).' (assumed default; may be at a relocated AuthorizedKeysFile) — UNVERIFIED, re-run as root');

            return $findings;
        }
        if (count($matches) > 1) {
            $in = implode(' ', array_values(array_unique(array_map(fn (array $m) => $m['path'], $matches))));
            $findings[] = Finding::fail("more than one authorized_keys line forces bridge:tools-call --agent={$agentName} (in {$in}) — ambiguous; leave exactly one");

            return $findings;
        }

        $line = $matches[0]['line'];
        $foundIn = $matches[0]['path'];
        if (! $line->deniesShellAndForwarding()) {
            $granted = implode(', ', $line->grantedCapabilities());
            $findings[] = Finding::fail("the pinned line for agent {$agentName} still grants: {$granted} — the forced command must deny pty + agent/X11/port-forwarding (use `restrict`, or the enumerated no-pty,no-agent-forwarding,no-X11-forwarding,no-port-forwarding form on a FIPS seat) (found in {$foundIn})");
        } else {
            $findings[] = Finding::ok("the pinned line for agent {$agentName} forces bridge:tools-call and denies pty + all forwarding (found in {$foundIn})");
        }

        // ⭐ THE MATCH ARMS' POPULATION IS AS INCOMPLETE AS THE ABSENCE ARM'S (card#8976 r3).
        // Finding the line needs ONE file; concluding it is the ONLY one sshd honours needs
        // the set. Decision 4b's own security argument is about the SECOND line: an account
        // whose other file carries a forced-command line for this agent granting pty or
        // forwarding is a state sshd honours, and dropping `$unreadable`/`$unresolvable` here
        // ships exactly the `ok` that argument calls WRONG — with the file unreadable rather
        // than merely unread, which is less knowable, not more. It does NOT withhold the
        // verdict (what was read WAS read, and `unvalidated` moves no exit code): it says,
        // beside it, what the verdict does not cover.
        if ($unreadable !== [] || $unresolvable !== []) {
            $findings[] = Finding::unvalidated("the pinned line for agent {$agentName} was found in {$foundIn}, but this run "
                .$this->unconsulted($unreadable, $unresolvable)
                .' — sshd reads every file it names, so a second forced-command line for this agent may sit in one of those and grant what this one denies. The verdict above covers only what was read');
        }

        if ($this->env->fipsEnabled()) {
            // An empty declaration is skipped by the renderer, so the `unknown` arm — where
            // nothing foreign is echoed at all — needs no branch here.
            $algorithmEcho = $this->keyAlgorithmEcho($line->keyAlgorithm) ?? '';
            if (! $line->keyAlgorithmIsFipsApproved()) {
                $findings[] = Finding::fail("FIPS mode is enabled but the pinned key for agent {$agentName} is ".$this->keyAlgorithmForMessage($line->keyAlgorithm).' — a FIPS sshd rejects it (use an ECDSA P-256 key: ssh-keygen -t ecdsa -b 256)')->carryingUntrusted($algorithmEcho);
            } else {
                $findings[] = Finding::ok("the pinned key for agent {$agentName} (".$this->keyAlgorithmForMessage($line->keyAlgorithm).') is FIPS-approved')->carryingUntrusted($algorithmEcho);
            }
        }

        return $findings;
    }

    /**
     * The opt-in `--probe-tools-ssh=<user@host>` LIVE leg: round-trip a real
     * `board_my_cards` over ssh (the forced command runs server-side) and assert
     * reachable → JSON-clean stdout → ok:true → the returned scope header identifies
     * a configured ssh agent (the same observable `--probe-tools` uses).
     * The header is what the answering agent is CONFIGURED for — an identity echo
     * that certifies which agent this key resolved to, never a reading of the rows;
     * BoardToolsScopeHeader owns both spellings of it (DL-302), and both the ok line
     * and the mismatch tail name WHICH spelling this responder answered under — the
     * ssh target is a REMOTE install, so this line is the only place the version skew
     * the fallback tolerates is observable at all (card#7325, DL-304).
     *
     * @param  list<array{agent: string, board_id: ?int, swimlane_id: ?int}>  $expectedScopes
     * @return list<Finding>
     */
    public function probeLive(string $target, array $expectedScopes): array
    {
        $r = $this->env->sshRoundTrip($target, (string) json_encode(['tool' => 'board_my_cards']));
        if ($r['exit'] !== 0) {
            // DECLARED, NOT ESCAPED HERE (card#9121, DL-366). Everything this leg echoes
            // below crossed the wire from a REMOTE host: its stderr, its stdout, and the
            // `error` string inside its envelope are bytes THAT host chose, and each was
            // being interpolated verbatim into a line on the operator's terminal. The rule
            // that makes them safe there has one owner — `UntrustedText` (NAMED, not
            // `{@see}`-linked and not spelled out: pint's docblock fixer turns a qualified
            // reference into a real `use`, and importing a `Support` class here for a
            // comment would add an import nothing executes) — and the renderer applies it;
            // these sites say only WHERE the foreign span is, which is the one fact no
            // renderer can recover from a flat message string.
            $stderr = trim($r['stderr']);

            return [Finding::fail("ssh {$target} exited {$r['exit']} — unreachable or the forced command failed (stderr: ".$stderr.')')->carryingUntrusted($stderr)];
        }

        $decoded = json_decode($r['stdout'], true);
        if (! is_array($decoded) || ! array_key_exists('ok', $decoded)) {
            $snippet = substr(trim($r['stdout']), 0, 200);

            return [Finding::fail("ssh {$target}: stdout is not a clean board-tools JSON envelope — got: ".$snippet)->carryingUntrusted($snippet)];
        }
        if ($decoded['ok'] !== true) {
            $error = is_string($decoded['error'] ?? null) ? $decoded['error'] : 'unknown';

            return [Finding::fail("ssh {$target}: board_my_cards did not succeed (error: {$error})")->carryingUntrusted($error)];
        }

        $result = $decoded['result'] ?? null;
        $header = BoardToolsScopeHeader::read(is_array($result) ? $result : []);
        $gotBoard = $header->boardId;
        $gotSwimlane = $header->swimlaneId;
        foreach ($expectedScopes as $scope) {
            if ($gotBoard === $scope['board_id'] && $gotSwimlane === $scope['swimlane_id']) {
                return [Finding::ok("ssh {$target}: board_my_cards ok; window scoped to board {$gotBoard} / swimlane {$gotSwimlane} (matches agent {$scope['agent']}). The scope header is an identity echo — matching it certifies that this pinned key resolved to THAT agent, not that the bridge-side lane filter ran (config matching config is true whatever the rows held); the measured half is the response's own board_id/board_observed. ".$header->boardSpelling->note())];
            }
        }

        return [Finding::fail("ssh {$target}: IDENTITY MISMATCH — board_my_cards answered for board=".($gotBoard ?? 'null').' swimlane='.($gotSwimlane ?? 'null').' which matches no configured ssh agent. '.$header->boardSpelling->mismatchCause(
            credential: 'the pinned key',
            credentialFix: 'look for a mis-pinned key or a stale forced-command --agent',
            routeFix: "check what {$target}'s forced command actually ran; a relay, or any JSON responder that is not board_my_cards, answers a probe exactly this way",
        ).' It says nothing about the bridge-side lane filter, which this response has no observable for. '.$header->boardSpelling->note())];
    }

    /**
     * Every file sshd consults for this account, and whether that set is authoritative.
     * ⭐ Once each: it is a set of FILES, not of entries — {@see self::oneEntryPerFile()}.
     *
     * `AuthorizedKeysFile` names a LIST (`man 5 sshd_config`: *"Multiple files may be
     * listed, separated by whitespace"*, and the OpenSSH DEFAULT is the two-file
     * `.ssh/authorized_keys .ssh/authorized_keys2`), so the singular reading this
     * replaced made the root run's authoritative absent-line FAIL a claim about a
     * SUBSET — a line pinned in the second file was reported as "not wired" (card#8976).
     *
     * The third slot is the entries that could NOT be resolved to a path (see
     * {@see self::expandTokens()}); each is already rendered with its reason, because
     * the caller's only honest use for them is to name them.
     *
     * @return array{0: list<string>, 1: bool, 2: list<string>} [paths, authoritative, unresolvable entries]
     */
    private function authorizedKeysPaths(): array
    {
        if ($this->env->isRoot()) {
            // Resolve the AuthorizedKeysFile from the forced-command account's
            // Match-resolved config; unset ⇒ null (the global config, byte-identical
            // to pre-4977 which passed no -C).
            $cfg = $this->env->sshdEffectiveConfig($this->sshAccount);
            if ($cfg !== null) {
                $resolved = $this->extractAuthorizedKeysFiles($cfg);
                if ($resolved !== null) {
                    return [$this->oneEntryPerFile($resolved[0]), true, $resolved[1]];
                }
            }
        }

        return [[$this->homePrefix().'/.ssh/authorized_keys'], false, []];
    }

    /**
     * ⭐ ONE ENTRY PER FILE, NOT PER SPELLING (card#8976 r2). Two `AuthorizedKeysFile`
     * entries may name ONE file — `.ssh/authorized_keys2` symlinked or hard-linked to
     * `.ssh/authorized_keys`, or the same file spelled two ways — and every claim below
     * this line is a claim about FILES: the population the absent-line FAIL is drawn over,
     * the list it prints, and above all the count the ambiguity FAIL is taken on. Left
     * keyed by the path string, ONE physical line was counted once per spelling and
     * *"more than one authorized_keys line forces …; leave exactly one"* failed, exit 1,
     * an install that has exactly one — the very false-FAIL class this card exists to
     * remove, minted by its own fix, with a remedy the operator cannot follow.
     *
     * The RAW spelling of the first entry naming each file survives, because it is what the
     * operator has to go and edit; {@see SshProbeEnvironment::fileIdentity()} is compared
     * and never printed. Entries this run cannot resolve to a file compare by their
     * normalised path, so two spellings of one ABSENT file can survive as two — harmless
     * (an absent file contributes no line to count), and the arm that would print both
     * names one file that is not there twice rather than accusing over a line that exists.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function oneEntryPerFile(array $paths): array
    {
        $seen = [];
        $perFile = [];
        foreach ($paths as $path) {
            $identity = $this->env->fileIdentity($path);
            if (isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;
            $perFile[] = $path;
        }

        return $perFile;
    }

    /**
     * The forced-command account's home AS A PATH PREFIX — trailing slashes stripped, so a
     * `pw_dir` carrying one (`/home/agent/`) yields one spelling and not two.
     *
     * ⛔ IT IS ONE PRIMITIVE BECAUSE BOTH ARMS ASK ONE QUESTION (card#8976 r2): what does
     * this home JOIN to a separator. The relative-entry arm stripped and the `%h` expansion
     * did not, so on such an account `%h/.ssh/authorized_keys` and `.ssh/authorized_keys` —
     * the same file, and a spelling pair the OpenSSH default and a hand-written config
     * commonly mix — resolved to two different strings for it.
     * {@see self::oneEntryPerFile()} makes that harmless where it lands; two arms
     * disagreeing about one home directory is the defect underneath, and it would have kept
     * minting spellings for anything else that compares them. ⚠ It is the JOIN that is
     * normalised and never the home itself — `{@see self::expandTokens()}` uses this only
     * where the entry supplies the separator, because `%h` followed by anything else is a
     * concatenation and stripping there would name a DIFFERENT file.
     */
    private function homePrefix(): string
    {
        return rtrim($this->forcedCommandHome(), '/');
    }

    /**
     * @return ?array{0: list<string>, 1: list<string>} [paths, unresolvable entries], or
     *                                                  null when the config names no directive
     */
    private function extractAuthorizedKeysFiles(string $sshdConfig): ?array
    {
        foreach (preg_split('/\n/', $sshdConfig) ?: [] as $line) {
            if (preg_match('/^\s*authorizedkeysfile\s+(.+)$/i', $line, $m) !== 1) {
                continue;
            }

            $entries = array_values(array_filter(preg_split('/\s+/', trim($m[1])) ?: [], fn (string $e) => $e !== ''));
            if ($entries === []) {
                return null;
            }

            $paths = [];
            $unresolvable = [];
            foreach ($entries as $entry) {
                ['path' => $expanded, 'reason' => $reason] = $this->expandTokens($entry);
                if ($expanded === null) {
                    $unresolvable[] = "`{$entry}` ({$reason})";

                    continue;
                }
                // sshd: "After expansion, AuthorizedKeysFile is taken to be an absolute
                // path or one relative to the user's home directory." `~` is NOT a token.
                $paths[] = str_starts_with($expanded, '/')
                    ? $expanded
                    : $this->homePrefix().'/'.$expanded;
            }

            return [$paths, $unresolvable];
        }

        return null;
    }

    /**
     * Expand the four tokens `man 5 sshd_config` documents for `AuthorizedKeysFile`
     * (`%%`, `%h`, `%u`, `%U`) — or REFUSE, carrying the reason with it, when this run
     * cannot. The refusal is the point: a token nobody expanded must not silently become
     * part of a path this probe then certifies against, and the reason travels with it so
     * the finding can name a specific cause instead of re-deriving a plausible one.
     *
     * SINGLE-PASS by construction (a scan, not `str_replace`): a chained replace expands
     * the `%h` that `%%h` — a literal `%h` — leaves behind, and would send the probe at
     * the home directory when sshd reads a file called `%h`.
     *
     * @return array{path: string, reason: null}|array{path: null, reason: string}
     */
    private function expandTokens(string $entry): array
    {
        $out = '';
        for ($i = 0, $len = strlen($entry); $i < $len; $i++) {
            if ($entry[$i] !== '%') {
                $out .= $entry[$i];

                continue;
            }

            $token = $i + 1 < $len ? $entry[$i + 1] : '';
            switch ($token) {
                case '%':
                    $out .= '%';
                    break;
                case 'h':
                    // ⚠ THE STRIP IS THE JOIN'S, NOT THE HOME'S, and the condition is the
                    // difference between a spelling and a FILE. `%h/` + a home ending in
                    // `/` is one file spelled with a doubled slash, so the entry's own `/`
                    // is the separator and the home's is dropped — which is exactly what
                    // the relative-entry arm does with the same home. `%h` followed by
                    // anything else is a CONCATENATION (sshd substitutes `pw_dir`
                    // verbatim): stripping there would resolve `%hfoo` under `/home/a/` to
                    // `/homefoo` instead of `/home/a/foo` — a different file, read as
                    // absent, and an authoritative "not wired" FAIL over a file sshd never
                    // consults. That is the defect class this card exists to remove, so
                    // the normalisation stops where it stops being one.
                    $out .= ($entry[$i + 2] ?? '') === '/'
                        ? $this->homePrefix()
                        : $this->forcedCommandHome();
                    break;
                case 'u':
                    $out .= $this->forcedCommandAccount();
                    break;
                case 'U':
                    $uid = $this->env->uidForUser($this->forcedCommandAccount());
                    if ($uid === null) {
                        return ['path' => null, 'reason' => 'the numeric uid of account `'.$this->forcedCommandAccount().'` could not be established here, so %U cannot be expanded'];
                    }
                    $out .= (string) $uid;
                    break;
                default:
                    // A token this probe does not know, or a trailing bare `%`. sshd
                    // refuses to start on one, so this is a shape that should not reach a
                    // running host — but it is external input, and reading it as "probably
                    // a literal" would build a path nobody verified.
                    return ['path' => null, 'reason' => $token === ''
                        ? 'it ends in a bare `%`, which names no token'
                        : "`%{$token}` is not one of the %%, %h, %u and %U that sshd_config documents for AuthorizedKeysFile, so this run cannot say what path sshd resolves it to"];
            }
            $i++;
        }

        // Only reachable from a `%h`/`%u` that expanded to nothing — the unset-account
        // fallback tolerates an empty run-user home (a CONFIGURED account that does not
        // resolve is refused earlier, by configuredAccountUnresolved()). Prefixing an
        // empty expansion would build `/` and certify against the filesystem root.
        return $out === ''
            ? ['path' => null, 'reason' => 'it expands to an empty path — the home directory of account `'.$this->forcedCommandAccount().'` is not known here']
            : ['path' => $out, 'reason' => null];
    }

    /**
     * The operator-facing account of what this run did NOT consult, by name. Both lists
     * can be non-empty at once and they are different claims — one is a file whose path
     * is known and whose contents are not, the other an entry whose path is not known.
     *
     * @param  list<string>  $unreadable
     * @param  list<string>  $unresolvable
     */
    private function unconsulted(array $unreadable, array $unresolvable): string
    {
        $parts = [];
        if ($unreadable !== []) {
            $parts[] = 'could not read '.implode(' ', $unreadable);
        }
        if ($unresolvable !== []) {
            $parts[] = 'could not resolve the AuthorizedKeysFile entry '.implode(', ', $unresolvable);
        }

        return implode(', and ', $parts);
    }

    /**
     * The matched line's key-algorithm field, QUOTED AND BOUNDED for an operator message.
     *
     * ⛔ THIS IS THE ONE PIECE OF `authorized_keys` CONTENT ANY MESSAGE HERE ECHOES, and it
     * is NOT a whitespace-bounded token: {@see AuthorizedKeysLine} splits the first field on
     * UNQUOTED whitespace, so a `"`-quoted field carries the rest of that line's text into
     * this string at whatever length the file gives it. Every other value these findings
     * print is a path, a raw `AuthorizedKeysFile` entry or a capability keyword. Bounding it
     * is what keeps the FIPS sentence readable — and the stated scope of what these arms
     * echo true — under a hostile line; a real algorithm name (`ssh-ed25519`,
     * `ecdsa-sha2-nistp256`, `rsa-sha2-512`) is far inside the limit, so nothing an operator
     * has to act on is ever cut.
     *
     * ⚠ `mb_strcut`, not `substr`: the bound is a BYTE budget, and a raw byte cut can split
     * a multi-byte character. ⛔ THE CONSEQUENCE HERE IS QUIET CORRUPTION, NOT A FAILED
     * RENDER, and the difference is which renderer receives it — `CheckJsonRenderer`
     * (NAMED rather than `{@see}`-linked; pint would import it) encodes with
     * `JSON_INVALID_UTF8_SUBSTITUTE`, so a split character is replaced with U+FFFD, in
     * silence, inside the one field an operator is being asked to act on.
     * {@see BoardMyCardsTool} states the LOUDER version of this for its own byte cap — a
     * failed encode for the whole response — and that consequence belongs to ITS renderer,
     * which sets no substitute flag. It is not this one's, and reading it across was wrong.
     */
    private function keyAlgorithmForMessage(?string $algorithm): string
    {
        $cut = $this->keyAlgorithmEcho($algorithm);
        if ($cut === null) {
            return '`unknown`';
        }

        return $cut === $algorithm ? '`'.$algorithm.'`' : '`'.$cut.'` (truncated)';
    }

    /**
     * The exact span of the key algorithm that reaches the operator's line, or null when
     * nothing foreign does — what {@see Finding::carryingUntrusted()} declares at the two
     * FIPS legs (card#9121, DL-366).
     *
     * ⭐ ONE DERIVATION, CALLED BY THE DISPLAY METHOD, never a second cut beside it. The
     * declaration is matched by EXACT SUBSTRING at render time, so a sibling that re-derived
     * the same `mb_strcut` could drift by one byte and the escape would then silently not
     * apply — a guard that fails open with nothing red. This is the reader; the other method
     * formats what it returns.
     *
     * The bytes are foreign for the reason DL-363 established about this file:
     * `authorized_keys` lives under the INSPECTED account's home, so that account chose
     * them, and `bridge:check` reads it routinely as root. The echo cap bounds the LENGTH;
     * it validates no shape, so an ESC or a bidi override in the algorithm token reached the
     * terminal intact.
     */
    private function keyAlgorithmEcho(?string $algorithm): ?string
    {
        return $algorithm === null
            ? null
            : mb_strcut($algorithm, 0, self::KEY_ALGORITHM_ECHO_MAX, 'UTF-8');
    }
}
