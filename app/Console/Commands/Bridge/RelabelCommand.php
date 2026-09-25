<?php

namespace App\Console\Commands\Bridge;

use App\Bridge\Exceptions\MalformedStateFileException;
use App\Bridge\Exceptions\UnreadableFileException;
use App\Bridge\Writeback\ProtocolInvalidLabelDebt;
use App\Bridge\Writeback\ProtocolInvalidLabeler;

/**
 * Finish the `protocol:invalid` label writes this install DECIDED on and could not land
 * (card#10242 / DL-419) — the rerunnable backstop for the DL-408 write, the shape
 * {@see ReconcileCommand} is for the card-move writeback.
 *
 * Default is REPORT-ONLY: a line per owed thread and a summary, exit 0, nothing sent. `--fix`
 * re-attempts them. Either mode exits non-zero on a record it cannot read (below).
 *
 * ⛔ IT DECIDES NOTHING ABOUT WHICH THREADS DESERVE THE LABEL. Every entry it reads is a verdict
 * the classifier already reached at the event under DL-408's rules; this command carries out the
 * write that verdict called for. It never re-classifies, never labels a thread the bridge did not
 * already decide to label, and — like every path in this app — it never REMOVES a label.
 *
 * ⛔ NOTHING RUNS IT BUT AN OPERATOR. There is no timer, no gate and no job behind it, which is
 * what keeps a permanent refusal from becoming an unbounded retry: the bridge re-attempts an
 * outward write only when a person asks it to. `docs/writeback.md` § *A refused label write is
 * remembered* is the operator's side of that.
 *
 * Safety posture, each re-using the write path's own gates rather than restating them:
 *  - the enabled-list is re-asked per entry, so a repo the operator has REMOVED from
 *    `BRIDGE_PROTOCOL_INVALID_LABEL_REPOS` since the failure is forgotten, never written;
 *  - an entry past {@see ProtocolInvalidLabelDebt::EXPIRY_SECONDS} is not in `owed()` at all, so a
 *    stale write is never sent;
 *  - `--limit` bounds the run, because each entry is one POST with its own timeout;
 *  - a repair that failed stays owed, is counted apart, and reds the run — a failed repair
 *    reported as a done one is the defect this command exists to end. Every way an entry LEAVES
 *    the record is listed once, in `docs/writeback.md` § *When an entry leaves the record*;
 *  - a record it cannot open or parse is NAMED and reds the run in both modes, before anything
 *    is sent — reading it as empty would be the all-clear this command must never give falsely;
 *  - it REFUSES to run as root, or as a user other than the record's owner, in both modes, naming
 *    the user to run as — {@see ProtocolInvalidLabelDebt::writerRefusal()} owns why.
 */
class RelabelCommand extends BridgeCommand
{
    protected $signature = 'bridge:relabel '
        .'{--fix : re-attempt the owed label writes (default is report-only)} '
        .'{--repo= : only this repo (owner/name, matched case-insensitively)} '
        .'{--limit=50 : attempt at most this many owed writes in one run}';

    protected $description = 'Re-attempt the protocol:invalid label writes this install still owes (report-only unless --fix)';

    public function handle(): int
    {
        $limit = $this->parseLimit();
        if ($limit === null) {
            return self::FAILURE;
        }
        $repoFilter = $this->strOption('repo');

        // ⛔ BEFORE ANYTHING IS READ, in both modes: run as root, the report answers "nothing owed"
        // over a record the receiver has been locked out of, and --fix is what locks it out.
        $refusal = ProtocolInvalidLabelDebt::writerRefusal();
        if ($refusal !== null) {
            $this->error("bridge:relabel: REFUSED — {$refusal}. Nothing was read, sent or written.");

            return self::FAILURE;
        }

        $owed = $this->owedInScope($repoFilter);
        if ($owed === null) {
            return self::FAILURE;
        }
        if ($owed === []) {
            $this->info('nothing owed: every protocol:invalid label this install decided on has been written, refused for good, or aged out of the repair window'
                .($repoFilter !== null ? " (scoped to {$repoFilter})" : ''));

            return self::SUCCESS;
        }

        if (! $this->option('fix')) {
            foreach ($owed as $entry) {
                $this->line("owed  {$entry['repo']}#{$entry['number']} — {$entry['reason']}"
                    .($entry['status'] === null ? '' : " ({$entry['status']})")
                    ." · first failed {$entry['first_failed_at']} · {$entry['attempts']} attempt(s)");
            }
            $this->newLine();
            $this->info(count($owed).' label write(s) owed. Fix the cause first — a 403 is the placed GitHub '
                .'token file without Issues or Pull requests WRITE — then re-run with --fix to write them.');

            return self::SUCCESS;
        }

        $labeler = new ProtocolInvalidLabeler;
        $applied = $dropped = $terminal = $stillOwed = 0;

        foreach (array_slice($owed, 0, $limit) as $entry) {
            $thread = "{$entry['repo']}#{$entry['number']}";

            if (! ProtocolInvalidLabeler::enabledFor($entry['repo'])) {
                ProtocolInvalidLabelDebt::forget($entry['repo'], $entry['number']);
                $this->warn("dropped   {$thread} — this repo is no longer in BRIDGE_PROTOCOL_INVALID_LABEL_REPOS; nothing was written");
                $dropped++;

                continue;
            }

            $attempt = $labeler->apply([
                'repo' => $entry['repo'], 'number' => $entry['number'], 'comment_id' => $entry['comment_id'],
            ]);

            if ($attempt->applied()) {
                $this->line("applied   {$thread} — ".ProtocolInvalidLabeler::LABEL);
                $applied++;
            } elseif ($attempt->repairable()) {
                $this->warn("still owed {$thread} — {$attempt->describe()}; the cause has not cleared");
                $stillOwed++;
            } else {
                $this->warn("terminal  {$thread} — {$attempt->describe()}; this write can never land and is no longer owed");
                $terminal++;
            }
        }

        $after = $this->owedInScope($repoFilter);
        if ($after === null) {
            return self::FAILURE;
        }
        $remaining = count($after);
        $this->newLine();
        $this->line("{$applied} applied, {$stillOwed} still owed, {$terminal} refused for good, {$dropped} dropped (repo no longer listed); {$remaining} owed after this run.");

        if ($remaining > 0) {
            // ⛔ NON-ZERO WHENEVER ANYTHING IS STILL OWED, so a caller reading only the exit code
            // cannot mistake a partial repair for a finished one — `bridge:writeback-exposure`'s
            // rule, for the same reason.
            $this->error("{$remaining} label write(s) are still owed — fix the cause and re-run.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * What is owed in scope, or null once the record's being unreadable has been SAID — the caller
     * then exits non-zero. The two causes print different remedies because they have different
     * ones: a permissions fault is uid-relative (the receiver may read it fine), a malformed file
     * is wrong for every reader.
     *
     * @return list<array{repo: string, number: int, comment_id: ?string, first_failed_at: string, last_failed_at: string, attempts: int, reason: string, status: ?int}>|null
     */
    private function owedInScope(?string $repoFilter): ?array
    {
        try {
            return $this->inScope(ProtocolInvalidLabelDebt::owed(), $repoFilter);
        } catch (UnreadableFileException $e) {
            $this->error('cannot tell what is owed: '.$e->getMessage().'. The receiver writes this record mode 0600 — '
                .'run bridge:relabel as the user the receiver runs as. If that user cannot read it either, give the file '
                .'and its .lock back to that user — until then the receiver records no refused label write. Nothing was sent.');
        } catch (MalformedStateFileException $e) {
            $this->error('cannot tell what is owed: '.$e->getMessage().'. The bridge will not rewrite it, so until it is '
                .'corrected or removed by hand no refused label write is recorded (each is logged instead). Nothing was sent.');
        }

        return null;
    }

    /**
     * @param  list<array{repo: string, number: int, comment_id: ?string, first_failed_at: string, last_failed_at: string, attempts: int, reason: string, status: ?int}>  $owed
     * @return list<array{repo: string, number: int, comment_id: ?string, first_failed_at: string, last_failed_at: string, attempts: int, reason: string, status: ?int}>
     */
    private function inScope(array $owed, ?string $repoFilter): array
    {
        if ($repoFilter === null) {
            return $owed;
        }

        // Matched the way the write site matches a payload repo — case-insensitively (DL-408).
        return array_values(array_filter($owed, fn (array $e): bool => strcasecmp($e['repo'], $repoFilter) === 0));
    }

    private function parseLimit(): ?int
    {
        // A SCALAR, not a string: `$this->artisan(..., ['--limit' => 2])` hands an
        // int where the shell hands a string, and a parser that only knows the shell's shape
        // refuses every programmatic call — silently, as a FAILURE exit a caller can misread as
        // the run having been attempted.
        $raw = $this->option('limit');
        if (! is_scalar($raw) || ! ctype_digit((string) $raw) || (int) $raw < 1) {
            $this->error('--limit must be a positive integer');

            return null;
        }

        return (int) (string) $raw;
    }
}
