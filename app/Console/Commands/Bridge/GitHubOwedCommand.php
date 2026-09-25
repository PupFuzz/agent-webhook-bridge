<?php

namespace App\Console\Commands\Bridge;

use App\Bridge\Exceptions\MalformedStateFileException;
use App\Bridge\Exceptions\UnreadableFileException;
use App\Bridge\Support\RedactedErrorText;
use App\Bridge\Writeback\GitHubWriteAttempt;
use App\Bridge\Writeback\GitHubWriteDebt;
use App\Bridge\Writeback\PrCorrelationCommenter;
use App\Bridge\Writeback\ProtocolInvalidLabeler;
use App\Bridge\Writeback\WritebackConfig;
use Throwable;

/**
 * Finish the GitHub writes this install DECIDED on and could not land — the DL-408
 * `protocol:invalid` label (card#10242 / DL-419) and the DL-390 correlation comment
 * (card#10365 / DL-422), both read from the one {@see GitHubWriteDebt} record. The rerunnable
 * backstop for the bridge's GitHub writes, the shape {@see ReconcileCommand} is for the card-move
 * writeback.
 *
 * Default is REPORT-ONLY: a line per owed write and a summary, exit 0, nothing sent. `--fix`
 * re-attempts them. Either mode exits non-zero on a record it cannot read (below).
 *
 * ⛔ IT DECIDES NOTHING. Every entry is a verdict the classifier or the move handler already reached
 * at the event; this command carries out the write that verdict called for. It never re-classifies,
 * never re-renders a comment (it posts the body the event rendered), never writes to a thread the
 * bridge did not already decide to write to, and — like every path in this app — never REMOVES a
 * label.
 *
 * ⛔ NOTHING RUNS IT BUT AN OPERATOR. There is no timer, no gate and no job behind it, which is
 * what keeps a permanent refusal from becoming an unbounded retry: the bridge re-attempts an
 * outward write only when a person asks it to. `docs/writeback.md` § *A refused GitHub write is
 * remembered* is the operator's side of that.
 *
 * Safety posture, each re-using the write path's own gates rather than restating them:
 *  - the install's consent is re-asked per entry, so a write the operator has since switched off is
 *    forgotten, never made: a label's repo must still be in `BRIDGE_PROTOCOL_INVALID_LABEL_REPOS`, a
 *    comment's repo must still be mapped in `writeback.json` (a `writeback.json` that cannot be read
 *    answers neither way, so that entry stays owed and nothing is sent);
 *  - an entry past {@see GitHubWriteDebt::EXPIRY_SECONDS} is not in `owed()` at all, so a stale
 *    write is never sent;
 *  - `--limit` bounds the run, because each entry is at least one request with its own timeout;
 *  - a repair that failed stays owed, is counted apart, and reds the run — a failed repair
 *    reported as a done one is the defect this command exists to end. Every way an entry LEAVES
 *    the record is listed once, in `docs/writeback.md` § *When an entry leaves the record*;
 *  - a record it cannot open or parse is NAMED and reds the run in both modes, before anything
 *    is sent — reading it as empty would be the all-clear this command must never give falsely;
 *  - it REFUSES to run as root, or as a user other than the record's owner, in both modes, naming
 *    the user to run as — {@see GitHubWriteDebt::writerRefusal()} owns why.
 */
class GitHubOwedCommand extends BridgeCommand
{
    protected $signature = 'bridge:github-owed '
        .'{--fix : re-attempt the owed GitHub writes (default is report-only)} '
        .'{--repo= : only this repo (owner/name, matched case-insensitively)} '
        .'{--limit=50 : attempt at most this many owed writes in one run}';

    protected $description = 'Re-attempt the GitHub writes (protocol:invalid labels, correlation comments) this install still owes (report-only unless --fix)';

    public function handle(): int
    {
        $limit = $this->parseLimit();
        if ($limit === null) {
            return self::FAILURE;
        }
        $repoFilter = $this->strOption('repo');

        // ⛔ BEFORE ANYTHING IS READ, in both modes: run as root, the report answers "nothing owed"
        // over a record the receiver has been locked out of, and --fix is what locks it out.
        $refusal = GitHubWriteDebt::writerRefusal();
        if ($refusal !== null) {
            $this->error("bridge:github-owed: REFUSED — {$refusal}. Nothing was read, sent or written.");

            return self::FAILURE;
        }

        $owed = $this->owedInScope($repoFilter);
        if ($owed === null) {
            return self::FAILURE;
        }
        if ($owed === []) {
            $this->info('nothing owed: every GitHub write this install decided on has been made, refused for good, or aged out of the repair window'
                .($repoFilter !== null ? " (scoped to {$repoFilter})" : ''));

            return self::SUCCESS;
        }

        if (! $this->option('fix')) {
            foreach ($owed as $entry) {
                $this->line('owed  '.self::subject($entry)." — {$entry['reason']}"
                    .($entry['status'] === null ? '' : " ({$entry['status']})")
                    ." · first failed {$entry['first_failed_at']} · {$entry['attempts']} attempt(s)");
            }
            $this->newLine();
            $this->info(count($owed).' GitHub write(s) owed. Fix the cause first — a 403 is the placed GitHub '
                .'token file without Issues or Pull requests WRITE — then re-run with --fix to write them.');

            return self::SUCCESS;
        }

        $labeler = new ProtocolInvalidLabeler;
        $commenter = new PrCorrelationCommenter;
        $landed = $dropped = $terminal = $stillOwed = 0;

        foreach (array_slice($owed, 0, $limit) as $entry) {
            $subject = self::subject($entry);

            $withdrawn = $this->consentWithdrawn($entry);
            if ($withdrawn === false) {
                // writeback.json could not be read: whether the install still makes this write is
                // unknown, so nothing is sent and nothing is forgotten.
                $stillOwed++;

                continue;
            }
            if ($withdrawn !== null) {
                GitHubWriteDebt::forget($entry['kind'], $entry['repo'], $entry['number'], $entry);
                $this->warn("dropped   {$subject} — {$withdrawn}; nothing was written");
                $dropped++;

                continue;
            }

            $attempt = $this->attempt($entry, $labeler, $commenter);
            if ($attempt->landed) {
                $this->line("done      {$subject} — {$attempt->describe()}");
                $landed++;
            } elseif ($attempt->owed) {
                $this->warn("still owed {$subject} — {$attempt->describe()}; the cause has not cleared");
                $stillOwed++;
            } else {
                $this->warn("terminal  {$subject} — {$attempt->describe()}; this write can never land and is no longer owed");
                $terminal++;
            }
        }

        $after = $this->owedInScope($repoFilter);
        if ($after === null) {
            return self::FAILURE;
        }
        $remaining = count($after);
        $this->newLine();
        $this->line("{$landed} done, {$stillOwed} still owed, {$terminal} refused for good, {$dropped} dropped (no longer written by this install); {$remaining} owed after this run.");

        if ($remaining > 0) {
            // ⛔ NON-ZERO WHENEVER ANYTHING IS STILL OWED, so a caller reading only the exit code
            // cannot mistake a partial repair for a finished one — `bridge:writeback-exposure`'s
            // rule, for the same reason.
            $this->error("{$remaining} GitHub write(s) are still owed — fix the cause and re-run.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * The owed write, as the operator reads it: the thread, then which write.
     *
     * @param  array<string, mixed>&array{kind: string, repo: string, number: int}  $entry
     */
    private static function subject(array $entry): string
    {
        $write = $entry['kind'] === GitHubWriteDebt::KIND_COMMENT
            ? 'the `'.(is_string($entry['outcome'] ?? null) ? $entry['outcome'] : '?').'` correlation comment'
            : ProtocolInvalidLabeler::LABEL;

        return "{$entry['repo']}#{$entry['number']} [{$write}]";
    }

    /**
     * Has the install switched this write off since it was owed? The reason it has, null when it
     * has not, and false when that cannot be told (said here, once).
     *
     * @param  array<string, mixed>&array{kind: string, repo: string, number: int}  $entry
     */
    private function consentWithdrawn(array $entry): string|false|null
    {
        if ($entry['kind'] === GitHubWriteDebt::KIND_LABEL) {
            return ProtocolInvalidLabeler::enabledFor($entry['repo']) ? null : 'this repo is no longer in BRIDGE_PROTOCOL_INVALID_LABEL_REPOS';
        }

        try {
            $mapping = WritebackConfig::loadDefault()?->mappingFor($entry['repo']);
        } catch (Throwable $e) {
            $this->warn('still owed '.self::subject($entry).' — writeback.json cannot be read, so whether this install still posts '
                .'correlation comments on this repo is unknown and nothing was sent: '.RedactedErrorText::of($e));

            return false;
        }

        return $mapping === null ? 'this repo is no longer mapped in writeback.json' : null;
    }

    /** @param  array<string, mixed>&array{kind: string, repo: string, number: int}  $entry */
    private function attempt(array $entry, ProtocolInvalidLabeler $labeler, PrCorrelationCommenter $commenter): GitHubWriteAttempt
    {
        if ($entry['kind'] === GitHubWriteDebt::KIND_COMMENT) {
            // The record's own shape check guarantees both are strings (GitHubWriteDebt::shaped()).
            return $commenter->repost($entry['repo'], $entry['number'], (string) $entry['outcome'], (string) $entry['body']);
        }

        return $labeler->apply([
            'repo' => $entry['repo'], 'number' => $entry['number'], 'comment_id' => $entry['comment_id'] ?? null,
        ]);
    }

    /**
     * What is owed in scope, or null once the record's being unreadable has been SAID — the caller
     * then exits non-zero. The two causes print different remedies because they have different
     * ones: a permissions fault is uid-relative (the receiver may read it fine), a malformed file
     * is wrong for every reader.
     *
     * @return list<array<string, mixed>&array{kind: string, repo: string, number: int, first_failed_at: string, last_failed_at: string, attempts: int, reason: string, status: ?int}>|null
     */
    private function owedInScope(?string $repoFilter): ?array
    {
        try {
            return $this->inScope(GitHubWriteDebt::owed(), $repoFilter);
        } catch (UnreadableFileException $e) {
            $this->error('cannot tell what is owed: '.$e->getMessage().'. The receiver writes this record mode 0600 — '
                .'run bridge:github-owed as the user the receiver runs as. If that user cannot read it either, give the file '
                .'and its .lock back to that user — until then the receiver records no refused GitHub write. Nothing was sent.');
        } catch (MalformedStateFileException $e) {
            $this->error('cannot tell what is owed: '.$e->getMessage().'. The bridge will not rewrite it, so until it is '
                .'corrected or removed by hand no refused GitHub write is recorded (each is logged instead). Nothing was sent.');
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>&array{kind: string, repo: string, number: int, first_failed_at: string, last_failed_at: string, attempts: int, reason: string, status: ?int}>  $owed
     * @return list<array<string, mixed>&array{kind: string, repo: string, number: int, first_failed_at: string, last_failed_at: string, attempts: int, reason: string, status: ?int}>
     */
    private function inScope(array $owed, ?string $repoFilter): array
    {
        if ($repoFilter === null) {
            return $owed;
        }

        // Matched the way the write sites match a payload repo — case-insensitively.
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
