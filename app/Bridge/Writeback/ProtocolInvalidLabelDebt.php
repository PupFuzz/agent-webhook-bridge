<?php

namespace App\Bridge\Writeback;

use App\Bridge\Exceptions\MalformedStateFileException;
use App\Bridge\Exceptions\UnreadableFileException;
use App\Bridge\Support\BridgePaths;
use App\Bridge\Support\FileContents;
use App\Bridge\Support\RedactedErrorText;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What the install still OWES GitHub: the `protocol:invalid` writes {@see ProtocolInvalidLabeler}
 * decided on and could not land (card#10242 / DL-419). `bridge:relabel` is the reader.
 *
 * ⛔ THE DECISION IS ALREADY MADE WHEN A ROW LANDS HERE. Nothing in this class decides WHETHER a
 * thread should carry the label — the classifier did that, at the event, under DL-408's rules. It
 * records that a DECIDED write did not land, so the same write can be finished later. That is why
 * the repair is not a re-classification: re-deriving the verdict now would answer about the
 * install's config TODAY, and the label is a claim about an event.
 *
 * ⛔ WHY A RECORD EXISTS AT ALL — the two routes that look like a retry are not one. A failed label
 * never fails the delivery (DL-408: routing must not depend on it), so the dispatch completes;
 * GitHub recognises a redelivery as the same delivery and a processed dispatch is not re-run, and
 * `bridge:replay` skips processed rows without `--force`. The trigger is a comment being CREATED,
 * so no later event for that comment re-attempts it either. Without this file there is no surface
 * an operator can ask *which threads did we fail to label*, and the answer is not derivable from
 * anything else the bridge keeps: the outcome of the write lived only in a log line.
 *
 * ⛔ NOT EVERY FAILURE IS OWED, and {@see retriable()} is the whole rule. Two arms are excluded on
 * purpose and neither is a gap:
 *  - the install said NO (`repo_not_enabled`) — queueing that write would re-open an outward write
 *    the operator switched off;
 *  - there was no target (`payload_invalid`) — a row needs a repo and an issue number to name.
 * Among HTTP refusals the split is between a status an operator act or time can clear and one that
 * will refuse the identical request forever; {@see retriableStatus()} holds it, per code.
 *
 * ⛔ THE KEY IS THE THREAD, NOT THE COMMENT. One label per issue or pull request, so two
 * unattributable comments on one thread owe ONE write and bump one attempt count. The repo is
 * lower-cased into the key because the enabled-list is matched case-insensitively and GitHub's repo
 * names are; the stored `repo` keeps the spelling the event carried, which is what the write uses.
 *
 * ⚑ BOUNDED IN BOTH DIRECTIONS, because an unbounded owed list is its own defect. An entry older
 * than {@see EXPIRY_SECONDS} is not repaired — a label applied long after the comment is a claim
 * about a thread that has moved on, and the comment may not even exist any more — and the file
 * holds at most {@see MAX_ENTRIES}, the OLDEST dropped first. Both drops are logged, never silent.
 *
 * ⛔ NOTHING HERE THROWS INTO THE DELIVERY. The writer is on the request path, so every mutation is
 * wrapped: a state dir that cannot be written is one warning and the routing it was booking is
 * untouched.
 *
 * ⛔ A RECORD THAT CANNOT BE READ IS NEVER *NOTHING OWED*, and there are three answers, not two:
 * absent (nothing owed), rows, or present-and-unreadable — a file this process cannot open
 * ({@see UnreadableFileException}, the realistic case being `bridge:relabel` run as a user other
 * than the receiver's, since the record is `0600`) or cannot parse ({@see MalformedStateFileException}).
 * {@see owed()} THROWS on the third, so the operator's surface cannot render it as an all-clear;
 * the request-path peek in {@see forget()} reports it and changes nothing; and a write SETS THE
 * FILE ASIDE before replacing it, so what it held is kept for the operator rather than destroyed.
 */
final class ProtocolInvalidLabelDebt
{
    public const FILE = 'protocol-invalid-labels-owed.json';

    /**
     * How long an owed write stays repairable. The label reports on a COMMENT, and the further the
     * write drifts from it the less it describes: the thread has moved on, the comment may have been
     * deleted, and an arbiter sweeping the label finds a thread whose offending post is gone. Seven
     * days is the same window {@see App\Bridge\Support\WebhookOutageRecord} bounds a stale remedy
     * with, for the same reason.
     */
    public const EXPIRY_SECONDS = 7 * 86400;

    /** Entries kept. A token with no Issues write on a busy repo is what fills this. */
    public const MAX_ENTRIES = 500;

    public static function path(): string
    {
        return BridgePaths::stateDir().'/'.self::FILE;
    }

    /**
     * Record the outcome of one decided write: owed when the failure can still clear, forgotten
     * when it cannot and when it landed. ONE call site shape for every arm, so an arm cannot be
     * added that records nothing and nothing says so.
     */
    public static function settle(string $repo, int $number, ?string $commentId, string $reason, ?int $status): void
    {
        if (! self::retriable($reason, $status)) {
            self::forget($repo, $number);

            return;
        }

        self::mutate(function (array $owed) use ($repo, $number, $commentId, $reason, $status): array {
            $key = self::key($repo, $number);
            $now = self::now();
            $existing = $owed[$key] ?? null;
            $owed[$key] = [
                'repo' => $repo,
                'number' => $number,
                'comment_id' => is_array($existing) ? ($existing['comment_id'] ?? $commentId) : $commentId,
                'first_failed_at' => is_array($existing) && is_string($existing['first_failed_at'] ?? null) ? $existing['first_failed_at'] : $now,
                'last_failed_at' => $now,
                'attempts' => is_array($existing) && is_int($existing['attempts'] ?? null) ? $existing['attempts'] + 1 : 1,
                'reason' => $reason,
                'status' => $status,
            ];

            return $owed;
        });
    }

    /**
     * Drop what is owed on one thread — it landed, it never can, or this install no longer writes it.
     *
     * ⭐ IT PEEKS BEFORE IT LOCKS, and that is not an optimisation for its own sake: this runs on the
     * SUCCESS path of every label write, and on a healthy install the record does not exist — one
     * `is_file()` that answers no, instead of creating a lock file and rewriting a state file to
     * remove a key that was never there, on the FPM request path. The race it accepts is a
     * concurrent delivery recording this same thread between the peek and the return, and its whole
     * cost is one stale entry that the next repair discharges with an idempotent add.
     */
    public static function forget(string $repo, int $number): void
    {
        $key = self::key($repo, $number);
        $present = false;
        foreach (self::read() as $entry) {
            $present = $present || self::key($entry['repo'], $entry['number']) === $key;
        }
        if (! $present) {
            return;
        }

        self::mutate(function (array $owed) use ($key): array {
            unset($owed[$key]);

            return $owed;
        });
    }

    /**
     * What is still owed, oldest failure first. Expired entries are hidden here and dropped by the
     * next mutation — a pure read never rewrites the file it is answering about.
     *
     * @return list<array{repo: string, number: int, comment_id: ?string, first_failed_at: string, last_failed_at: string, attempts: int, reason: string, status: ?int}>
     *
     * @throws UnreadableFileException the record is present and this process cannot open it
     * @throws MalformedStateFileException the record is present and is not one this class wrote
     */
    public static function owed(): array
    {
        $rows = array_values(array_filter(self::load(), self::live(...)));
        usort($rows, fn (array $a, array $b): int => [$a['first_failed_at'], $a['repo'], $a['number']] <=> [$b['first_failed_at'], $b['repo'], $b['number']]);

        return $rows;
    }

    /**
     * Can the identical write still land? The census this answers over is the `reason` set
     * {@see ProtocolInvalidLabeler}'s log arms emit, and it is asked at every one of them.
     *
     * A reason this does not name is OWED. That is a reasoned default and not a catch-all: a
     * recorded write that turns out unrepairable costs one bounded, expiring row and one refused
     * POST, while a dropped one costs a thread the arbiter never sweeps, permanently — and the arm
     * this default actually serves today is `unexpected`, which is worded *"NOT applied, OR NOT
     * CONFIRMED"* precisely because the bridge does not know which.
     */
    public static function retriable(string $reason, ?int $status): bool
    {
        return match ($reason) {
            // the install excluded this repo, and no target was named: neither is a failed write
            ProtocolInvalidLabeler::REASON_REPO_NOT_ENABLED,
            ProtocolInvalidLabeler::REASON_PAYLOAD_INVALID => false,
            ProtocolInvalidLabeler::REASON_ADD_REFUSED => $status !== null && self::retriableStatus($status),
            default => true,
        };
    }

    /**
     * ⛔ 404 IS OWED, and it is the one worth reading twice. GitHub answers 404 both for a thread
     * that is GONE and for a repo the token cannot SEE — those are indistinguishable in the
     * response, by design, and the second is an operator act away from clearing. Reading it as
     * terminal would silently drop exactly the case this record exists for; the expiry is what
     * bounds the other reading.
     *
     * The rest, per code: 401/403 the token (expired, or without Issues or Pull requests write);
     * 408 and 429 time and rate; every 5xx GitHub's own fault. A 4xx not named here — 400, 410,
     * 422, 451 — refuses the identical request for a reason no waiting changes.
     */
    private static function retriableStatus(int $status): bool
    {
        return $status >= 500
            || $status === 401
            || $status === 403
            || $status === 404
            || $status === 408
            || $status === 429;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private static function live(array $entry): bool
    {
        // now(), never time(): the app's clock is the one every other timestamp in this record is
        // written against, so a reader and a writer cannot disagree about which entries are live.
        $at = is_string($entry['first_failed_at'] ?? null) ? strtotime($entry['first_failed_at']) : false;

        return $at !== false && $at > now()->getTimestamp() - self::EXPIRY_SECONDS;
    }

    /**
     * @param  \Closure(array<string, array<string, mixed>>): array<string, array<string, mixed>>  $change
     */
    private static function mutate(\Closure $change): void
    {
        $path = null;
        try {
            // Resolved ONCE: the catch arm must not re-enter a call that can itself throw, or a
            // failure here would leave the delivery by way of the handler meant to contain it.
            $path = self::path();
            BridgePaths::withLock($path, function () use ($change, $path): void {
                $owed = [];
                foreach (self::loadForRewrite($path) as $entry) {
                    $owed[self::key($entry['repo'], $entry['number'])] = $entry;
                }
                BridgePaths::writeFileAtomic(
                    $path,
                    (string) json_encode(['owed' => (object) self::prune($change($owed))], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
                );
            });
        } catch (Throwable $e) {
            // The bookkeeping must never break the delivery it books (DL-408: routing does not
            // depend on this label). The cost of landing here is that the write stays unrepairable
            // and unlisted, which is what this line is for.
            Log::warning('protocol_invalid_label: the record of writes this install still owes could not be updated — that write is now unlisted and `bridge:relabel` will not repair it; routing is unchanged', [
                'catalog_id' => 'protocol_invalid_label.owed_record_unwritable',
                'path' => $path, 'error' => RedactedErrorText::of($e),
            ]);
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $owed
     * @return array<string, array<string, mixed>>
     */
    private static function prune(array $owed): array
    {
        // ⛔ AT THE CAP THE OLDEST GO, which is the expiry's reasoning applied one step earlier: a
        // write further from the comment it reports describes less, and the oldest entries are the
        // ones about to age out anyway. Keeping them instead would drop the write most likely to
        // still be worth making.
        $live = array_filter($owed, self::live(...));
        uasort($live, fn (array $a, array $b): int => $b['first_failed_at'] <=> $a['first_failed_at']);
        $kept = array_slice($live, 0, self::MAX_ENTRIES, true);

        $dropped = count($owed) - count($kept);
        if ($dropped > 0) {
            Log::warning('protocol_invalid_label: dropped owed label writes from the record — they were past the repair window or over its cap, and those threads stay unlabelled', [
                'catalog_id' => 'protocol_invalid_label.owed_record_pruned',
                'dropped' => $dropped, 'kept' => count($kept),
                'expiry_seconds' => self::EXPIRY_SECONDS, 'cap' => self::MAX_ENTRIES,
            ]);
        }

        return $kept;
    }

    /**
     * The request path's read: TOTAL, because it runs inside a delivery. A record it cannot read is
     * reported and answered as empty — safe only because this answer feeds nothing but
     * {@see forget()}'s peek, which then changes nothing. Never the operator's read: that is
     * {@see owed()}, which says so instead.
     *
     * @return list<array{repo: string, number: int, comment_id: ?string, first_failed_at: string, last_failed_at: string, attempts: int, reason: string, status: ?int}>
     */
    private static function read(): array
    {
        try {
            return self::load();
        } catch (UnreadableFileException|MalformedStateFileException $e) {
            Log::warning('protocol_invalid_label: the record of writes this install still owes could not be read — nothing was removed from it; `bridge:relabel` names the problem', [
                'catalog_id' => 'protocol_invalid_label.owed_record_unreadable',
                'path' => self::path(), 'problem' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * The rows a write starts from. A record this process cannot read is MOVED ASIDE first, never
     * overwritten: the rename in {@see BridgePaths::writeFileAtomic()} needs only the directory,
     * so it would replace a file it could not open — destroying every entry in it — without a
     * word. A failed move throws, and {@see mutate()} then writes nothing.
     *
     * @return list<array{repo: string, number: int, comment_id: ?string, first_failed_at: string, last_failed_at: string, attempts: int, reason: string, status: ?int}>
     */
    private static function loadForRewrite(string $path): array
    {
        try {
            return self::load();
        } catch (MalformedStateFileException $e) {
            self::setAside($path, 'corrupt', $e);
        } catch (UnreadableFileException $e) {
            self::setAside($path, 'unreadable', $e);
        }

        return [];
    }

    private static function setAside(string $path, string $state, \RuntimeException $problem): void
    {
        $aside = $path.'.'.$state.'-'.now()->utc()->format('Ymd\THis.u\Z');
        if (! @rename($path, $aside)) {
            $reason = error_get_last()['message'] ?? 'rename failed';

            throw new \RuntimeException("bridge: failed to set {$path} aside as {$aside} ({$reason}) — not replacing a record whose entries could not be read");
        }

        Log::warning('protocol_invalid_label: the record of writes this install still owes could not be read, so it was SET ASIDE before this write replaced it — the threads it held are not in the record now and `bridge:relabel` will not repair them; the set-aside file names them', [
            'catalog_id' => 'protocol_invalid_label.owed_record_set_aside',
            'path' => $path, 'set_aside_as' => $aside, 'problem' => $problem->getMessage(),
        ]);
    }

    /**
     * The record's rows; [] only when there is NO FILE.
     *
     * @return list<array{repo: string, number: int, comment_id: ?string, first_failed_at: string, last_failed_at: string, attempts: int, reason: string, status: ?int}>
     *
     * @throws UnreadableFileException
     * @throws MalformedStateFileException
     */
    private static function load(): array
    {
        $path = self::path();
        $raw = FileContents::read($path, 'protocol-invalid owed-label record');
        if ($raw === null) {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded) || ! is_array($decoded['owed'] ?? null)) {
            throw MalformedStateFileException::notARecord($path, is_array($decoded) ? 'no "owed" map' : 'not valid JSON');
        }

        $rows = [];
        foreach ($decoded['owed'] as $entry) {
            if (self::shaped($entry)) {
                $rows[] = $entry;
            }
        }

        return $rows;
    }

    /**
     * @phpstan-assert-if-true array{repo: string, number: int, comment_id: ?string, first_failed_at: string, last_failed_at: string, attempts: int, reason: string, status: ?int} $entry
     */
    private static function shaped(mixed $entry): bool
    {
        return is_array($entry)
            && is_string($entry['repo'] ?? null) && $entry['repo'] !== ''
            && is_int($entry['number'] ?? null) && $entry['number'] >= 1
            && (is_string($entry['comment_id'] ?? null) || ($entry['comment_id'] ?? null) === null)
            && is_string($entry['first_failed_at'] ?? null) && strtotime($entry['first_failed_at']) !== false
            && is_string($entry['last_failed_at'] ?? null)
            && is_int($entry['attempts'] ?? null)
            && is_string($entry['reason'] ?? null)
            && (is_int($entry['status'] ?? null) || ($entry['status'] ?? null) === null);
    }

    private static function key(string $repo, int $number): string
    {
        return strtolower($repo).'#'.$number;
    }

    private static function now(): string
    {
        return now()->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
