<?php

namespace App\Bridge\Writeback;

use App\Bridge\Exceptions\MalformedStateFileException;
use App\Bridge\Exceptions\UnreadableFileException;
use App\Bridge\Support\BridgePaths;
use App\Bridge\Support\FileContents;
use App\Bridge\Support\ProcessIdentity;
use App\Bridge\Support\RedactedErrorText;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * What the install still OWES GitHub: the writes it DECIDED on and could not land — the DL-408
 * `protocol:invalid` label ({@see ProtocolInvalidLabeler}, card#10242 / DL-419) and the DL-390
 * correlation comment ({@see PrCorrelationCommenter}, card#10365 / DL-422). ONE record for both,
 * one file, one lock, one owner rule and one reader, `bridge:github-owed`: an operator whose token
 * lacked Issues write fixes it once and finishes every write that refusal cost, rather than learning
 * one command per writer. Hoisted here at its second caller (canon #5), never duplicated per writer.
 *
 * ⛔ THE DECISION IS ALREADY MADE WHEN A ROW LANDS HERE. Nothing in this class decides WHETHER a
 * write should happen — the classifier or the move handler did that, at the event. It records that
 * a DECIDED write did not land, so the same write can be finished later. That is why the repair is
 * not a re-classification: re-deriving the verdict now would answer about the install's config
 * TODAY, and a label or a comment is a claim about an event. A comment row therefore carries the
 * BODY the event rendered, not the inputs to render it again.
 *
 * ⛔ WHY A RECORD EXISTS AT ALL — the routes that look like a retry are not one. Neither write fails
 * the delivery (routing must not depend on a report), so the dispatch completes; GitHub recognises a
 * redelivery as the same delivery and a processed dispatch is not re-run, and `bridge:replay` skips
 * processed rows without `--force`. And no later event reliably re-attempts either: a label's trigger
 * is a comment being CREATED, and a pull request MERGES ONCE, so a refused merge comment has no later
 * event of that outcome. Without this file there is no surface an operator can ask *which writes did
 * we fail to make*, and the answer is not derivable from anything else the bridge keeps.
 *
 * ⛔ NOT EVERY FAILURE IS OWED. The writer decides, per arm, with its OWN predicate
 * ({@see ProtocolInvalidLabeler::retriable()}, {@see PrCorrelationCommenter::retriable()}), because
 * the reason vocabulary is the writer's; the per-status half both share is {@see retriableStatus()}.
 * {@see settle()} takes that answer, so every arm of every writer goes through one call shape.
 *
 * ⛔ THE KEY IS THE WRITE, NOT THE EVENT. A label is one per thread, so two unattributable comments
 * on one thread owe ONE write; a correlation comment is one per (pull request, outcome), its dedupe
 * key on GitHub. The repo is lower-cased into the key because GitHub's repo names are
 * case-insensitive; the stored `repo` keeps the spelling the event carried, which is what the write
 * uses.
 *
 * ⚑ BOUNDED IN BOTH DIRECTIONS, because an unbounded owed list is its own defect. An entry older
 * than {@see EXPIRY_SECONDS} is not repaired — a write made long after its event is a claim about a
 * thread that has moved on — and the file holds at most {@see MAX_ENTRIES}, the OLDEST dropped first.
 * Both drops are logged, never silent.
 *
 * ⛔ NOTHING HERE THROWS INTO THE DELIVERY. The writers are on the request path, so every mutation
 * is wrapped: a state dir that cannot be written is one warning and the routing it was booking is
 * untouched.
 *
 * ⛔ A RECORD THAT CANNOT BE READ IS NEVER *NOTHING OWED*, and there are three answers, not two:
 * absent (nothing owed), rows, or present-and-unreadable — a file this process cannot open
 * ({@see UnreadableFileException}, the realistic case being the receiver facing a `0600` record
 * another user wrote — see {@see writerRefusal()} for the one such write it cannot refuse) or
 * cannot parse ({@see MalformedStateFileException}).
 * {@see owed()} THROWS on the third, so the operator's surface cannot render it as an all-clear;
 * the request-path peek in {@see forget()} reports it and changes nothing; and a write STOPS —
 * the file is left byte-for-byte as it was and the write is logged as unrecorded. There is
 * nothing correct to do to such a file automatically: replacing it destroys entries this process
 * could not read, and moving it aside takes them off the one surface the operator reads. So the
 * bridge says so and leaves it where `bridge:github-owed` will keep naming it until a person acts.
 */
final class GitHubWriteDebt
{
    public const FILE = 'github-writes-owed.json';

    /** The `protocol:invalid` label on an issue or pull request. Extra field: `comment_id`. */
    public const KIND_LABEL = 'protocol_invalid_label';

    /** The DL-390 correlation comment on a pull request. Extra fields: `outcome`, `body`. */
    public const KIND_COMMENT = 'pr_correlation_comment';

    /**
     * How long an owed write stays repairable. Both writes report on an EVENT, and the further the
     * write drifts from it the less it describes: the thread has moved on, a labelled comment may
     * have been deleted, a card the correlation comment names may have been moved by hand. Seven
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
     * Record the outcome of one decided write: owed when the writer's own predicate says its cause
     * can still clear, forgotten when it cannot. ONE call site shape for every arm of every writer,
     * so an arm cannot be added that records nothing and nothing says so.
     *
     * A label row keeps the FIRST `comment_id` (the comment that decided it); a comment row keeps
     * the LATEST `body` — the event path, had the first attempt failed and the latest landed, would
     * have posted the latest, so that is the write still owed.
     *
     * @param  self::KIND_*  $kind
     * @param  array<string, mixed>  $write  the kind's extra fields — {@see KIND_LABEL}, {@see KIND_COMMENT}
     */
    public static function settle(string $kind, string $repo, int $number, array $write, string $reason, ?int $status, bool $owed): void
    {
        if (! $owed) {
            self::forget($kind, $repo, $number, $write);

            return;
        }

        self::mutate(function (array $rows) use ($kind, $repo, $number, $write, $reason, $status): array {
            $key = self::key($kind, $repo, $number, $write);
            $now = self::now();
            $existing = $rows[$key] ?? null;
            if ($kind === self::KIND_LABEL && is_array($existing) && array_key_exists('comment_id', $existing)) {
                $write['comment_id'] = $existing['comment_id'];
            }
            $rows[$key] = [
                'kind' => $kind,
                'repo' => $repo,
                'number' => $number,
            ] + $write + [
                'first_failed_at' => is_array($existing) && is_string($existing['first_failed_at'] ?? null) ? $existing['first_failed_at'] : $now,
                'last_failed_at' => $now,
                'attempts' => is_array($existing) && is_int($existing['attempts'] ?? null) ? $existing['attempts'] + 1 : 1,
                'reason' => $reason,
                'status' => $status,
            ];

            return $rows;
        });
    }

    /**
     * Drop one owed write — it landed, it never can, or this install no longer makes it.
     *
     * ⭐ IT PEEKS BEFORE IT LOCKS, and that is not an optimisation for its own sake: this runs on the
     * SUCCESS path of every write, and on a healthy install the record does not exist — one
     * `is_file()` that answers no, instead of creating a lock file and rewriting a state file to
     * remove a key that was never there, on the FPM request path. The race it accepts is a
     * concurrent delivery recording this same write between the peek and the return, and its whole
     * cost is one stale entry that the next repair discharges — an idempotent add, or a comment
     * whose dedupe read finds it already there.
     *
     * @param  self::KIND_*  $kind
     * @param  array<string, mixed>  $write  only a comment's `outcome` is read, as part of its key
     */
    public static function forget(string $kind, string $repo, int $number, array $write = []): void
    {
        $key = self::key($kind, $repo, $number, $write);
        $present = false;
        foreach (self::read() as $entry) {
            $present = $present || self::keyOf($entry) === $key;
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
     * @return list<array<string, mixed>&array{kind: string, repo: string, number: int, first_failed_at: string, last_failed_at: string, attempts: int, reason: string, status: ?int}>
     *
     * @throws UnreadableFileException the record is present and this process cannot open it
     * @throws MalformedStateFileException the record is present and is not one this class wrote
     */
    public static function owed(): array
    {
        $rows = array_values(array_filter(self::load(), self::live(...)));
        usort($rows, fn (array $a, array $b): int => [$a['first_failed_at'], $a['repo'], $a['number'], $a['kind']] <=> [$b['first_failed_at'], $b['repo'], $b['number'], $b['kind']]);

        return $rows;
    }

    /**
     * Can the identical request to an issue or pull request still succeed, judged by its HTTP status
     * alone? The half every writer's own predicate shares; a writer whose endpoint documents a
     * further ambiguous code widens it there, never here.
     *
     * ⛔ 404 IS OWED, and it is the one worth reading twice. GitHub answers 404 both for a thread
     * that is GONE and for a repo the token cannot SEE — those are indistinguishable in the
     * response, by design, and the second is an operator act away from clearing. Reading it as
     * terminal would silently drop exactly the case this record exists for; the expiry is what
     * bounds the other reading.
     *
     * The rest, per code: 401/403 the token (expired, or without Issues or Pull requests write — and
     * GitHub answers a primary or SECONDARY rate limit with 403 as well as 429, per "Rate limits for
     * the REST API"); 408 and 429 time and rate; every 5xx GitHub's own fault. A 4xx not named here
     * — 400, 410, 422, 451 — refuses the identical request for a reason no waiting changes.
     */
    public static function retriableStatus(int $status): bool
    {
        return $status >= 500
            || $status === 401
            || $status === 403
            || $status === 404
            || $status === 408
            || $status === 429;
    }

    /**
     * Why THIS process must not write the record, or null when it may. The one owner of that rule:
     * {@see mutate()} refuses on it and `bridge:github-owed` refuses on it before reading anything.
     *
     * ⛔ A RECORD IS OWNED BY WHOEVER LAST WROTE IT, `0600` (`writeFileAtomic()`'s `tempnam`), and
     * only the receiver's user writes the refused writes into it. So a writer running as anyone else
     * does not merely write a row — it takes the file off the receiver, whose every later refused
     * write is then logged and lost, while the new owner reads the record fine and is told nothing is
     * owed (r3 M1). Two refusals follow:
     *  - ROOT NEVER WRITES IT, present or absent: root is never the receiver's user, and a record (or
     *    a lock file) root creates is one the receiver cannot open;
     *  - A PRESENT RECORD IS REPLACED ONLY BY ITS OWNER, since that owner is, by the rule above, the
     *    last user that could write it — and a present `.lock` is held to the same rule, since a lock
     *    the receiver cannot open stops its writes as surely as a record it cannot open.
     * ⚑ An ABSENT record created by a non-root user other than the receiver's is NOT refused: nothing
     * this process can read says which user the receiver runs as. `CLAUDE_DEPLOYMENT.md` names it.
     * An effective uid this process cannot read (no posix extension) is unmeasured and refuses
     * nothing, the `bridge:jobs install-tick` root refusal's reading of the same fact.
     */
    public static function writerRefusal(): ?string
    {
        $identity = app(ProcessIdentity::class);
        $euid = $identity->euid();
        if ($euid === null) {
            return null;
        }
        $path = self::path();
        $owner = $identity->ownerOf($path);
        $ownerName = $owner === null ? null : ($identity->accountName($owner) ?? "uid {$owner}");
        // ⛔ A ROOT-OWNED RECORD HAS NO USER TO RUN AS — root is refused below — so the only
        // remedy is to hand the file back; "run it as root" would send the operator in a circle.
        $giveBack = "give {$path} and its .lock back to the user the receiver runs as";

        if ($euid === 0) {
            return 'this process runs as root, and a record root writes is one the receiver cannot open — every refused write after it would go unrecorded; '
                .match (true) {
                    $owner === null => 'run it as the user the receiver runs as',
                    $owner === 0 => "{$path} is already owned by root: {$giveBack}",
                    default => "{$path} is owned by {$ownerName}: run it as {$ownerName}",
                };
        }
        // The lock is asked the same question: a `.lock` the receiver cannot open fails every write
        // in withLock() while the record itself still reads fine to its owner (r4 MINOR-1).
        foreach ([$path => $owner, "{$path}.lock" => $identity->ownerOf("{$path}.lock")] as $file => $fileOwner) {
            if ($fileOwner === null || $fileOwner === $euid) {
                continue;
            }
            $fileOwnerName = $identity->accountName($fileOwner) ?? "uid {$fileOwner}";
            $me = $identity->accountName($euid) ?? "uid {$euid}";

            // The same sentence reaches the operator's terminal and the receiver's log, so it
            // names both remedies: the owner may be the receiver's user, or may be the one write
            // this method cannot refuse (above).
            return "{$file} is owned by {$fileOwnerName} and this process runs as {$me}; replacing it would hand it to {$me} — "
                .($fileOwner === 0 ? $giveBack : "run it as {$fileOwnerName}, or, if {$fileOwnerName} is not the user the receiver runs as, {$giveBack}");
        }

        return null;
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
            // Asked BEFORE the lock: a lock file root creates locks the receiver out as surely as
            // a record root writes.
            $refusal = self::writerRefusal();
            if ($refusal !== null) {
                throw new RuntimeException($refusal);
            }
            BridgePaths::withLock($path, function () use ($change, $path): void {
                $owed = [];
                // load(), never read(): a record this process cannot read or parse THROWS here,
                // so the rename in writeFileAtomic() — which needs only the directory — never
                // replaces entries nobody read. The catch below turns that into one warning.
                foreach (self::load() as $entry) {
                    $owed[self::keyOf($entry)] = $entry;
                }
                BridgePaths::writeFileAtomic(
                    $path,
                    json_encode(['owed' => (object) self::prune($change($owed))], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
                );
            });
        } catch (Throwable $e) {
            // The bookkeeping must never break the delivery it books (DL-408: routing does not
            // depend on either write). The cost of landing here is that the write stays unrepairable
            // and unlisted, which is what this line is for — and when the cause is a record this
            // process cannot read or parse, `error` names it and `bridge:github-owed` reds on it; when
            // it is a writer running as the wrong user, `error` says which user to run as.
            Log::warning('github_write_debt: the record of GitHub writes this install still owes could not be updated and was left exactly as it was — a refused write this was recording is not in it and `bridge:github-owed` will not repair it; routing is unchanged', [
                'catalog_id' => 'github_write_debt.record_unwritable',
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
            Log::warning('github_write_debt: dropped owed GitHub writes from the record — they were past the repair window or over its cap, and those writes will never be made', [
                'catalog_id' => 'github_write_debt.record_pruned',
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
     * @return list<array<string, mixed>&array{kind: string, repo: string, number: int, first_failed_at: string, last_failed_at: string, attempts: int, reason: string, status: ?int}>
     */
    private static function read(): array
    {
        try {
            return self::load();
        } catch (UnreadableFileException|MalformedStateFileException $e) {
            Log::warning('github_write_debt: the record of GitHub writes this install still owes could not be read — nothing was removed from it; `bridge:github-owed` names the problem', [
                'catalog_id' => 'github_write_debt.record_unreadable',
                'path' => self::path(), 'problem' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * The record's rows; [] only when there is NO FILE. Every entry or nothing: see the loop.
     *
     * @return list<array<string, mixed>&array{kind: string, repo: string, number: int, first_failed_at: string, last_failed_at: string, attempts: int, reason: string, status: ?int}>
     *
     * @throws UnreadableFileException
     * @throws MalformedStateFileException
     */
    private static function load(): array
    {
        $path = self::path();
        $raw = FileContents::read($path, 'owed GitHub-write record');
        if ($raw === null) {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded) || ! is_array($decoded['owed'] ?? null)) {
            throw MalformedStateFileException::notARecord($path, match (true) {
                is_array($decoded) => 'no "owed" map',
                json_last_error() !== JSON_ERROR_NONE => 'not valid JSON',
                default => 'valid JSON, but not an object',
            });
        }

        $rows = [];
        foreach ($decoded['owed'] as $entry) {
            // ⛔ ONE MIS-SHAPED ENTRY MAKES THE WHOLE RECORD MALFORMED. Skipping it would hide it
            // from owed() and drop it silently on the next rewrite — the file-level fault, one
            // entry at a time.
            if (! self::shaped($entry)) {
                throw MalformedStateFileException::notARecord($path, 'an entry that is not an owed GitHub write');
            }
            $rows[] = $entry;
        }

        return $rows;
    }

    /**
     * The fields every row carries, then the fields its KIND carries. A comment row's `body` must
     * start with its own outcome's marker: that marker is what the dedupe reads GitHub for, so a
     * body without it could be posted twice, and a body that is not the bridge's own comment must
     * never reach a pull request out of this file.
     *
     * @phpstan-assert-if-true array<string, mixed>&array{kind: string, repo: string, number: int, first_failed_at: string, last_failed_at: string, attempts: int, reason: string, status: ?int} $entry
     */
    private static function shaped(mixed $entry): bool
    {
        if (! (is_array($entry)
            && is_string($entry['repo'] ?? null) && $entry['repo'] !== ''
            && is_int($entry['number'] ?? null) && $entry['number'] >= 1
            && is_string($entry['first_failed_at'] ?? null) && strtotime($entry['first_failed_at']) !== false
            && is_string($entry['last_failed_at'] ?? null)
            && is_int($entry['attempts'] ?? null)
            && is_string($entry['reason'] ?? null)
            && (is_int($entry['status'] ?? null) || ($entry['status'] ?? null) === null))) {
            return false;
        }

        return match ($entry['kind'] ?? null) {
            self::KIND_LABEL => is_string($entry['comment_id'] ?? null) || (array_key_exists('comment_id', $entry) && $entry['comment_id'] === null),
            self::KIND_COMMENT => is_string($entry['outcome'] ?? null)
                && in_array($entry['outcome'], PrCorrelationComment::OUTCOMES, true)
                && is_string($entry['body'] ?? null)
                && str_starts_with($entry['body'], PrCorrelationComment::markerFor($entry['outcome'])),
            default => false,
        };
    }

    /** @param  array<string, mixed>&array{kind: string, repo: string, number: int}  $entry */
    private static function keyOf(array $entry): string
    {
        return self::key($entry['kind'], $entry['repo'], $entry['number'], $entry);
    }

    /** @param  array<string, mixed>  $write */
    private static function key(string $kind, string $repo, int $number, array $write): string
    {
        $key = $kind.'|'.strtolower($repo).'#'.$number;

        return $kind === self::KIND_COMMENT ? $key.'|'.(is_string($write['outcome'] ?? null) ? $write['outcome'] : '') : $key;
    }

    private static function now(): string
    {
        return now()->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
