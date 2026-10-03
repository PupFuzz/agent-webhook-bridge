<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Silence;
use App\Bridge\Handlers\KanbanPromoteReleasedHandler;
use App\Bridge\Support\Finding;
use App\Bridge\Support\PathVisibility;
use App\Bridge\Support\ProcessIdentity;
use App\Bridge\Support\RedactedErrorText;
use App\Bridge\Support\UntrustedText;
use App\Bridge\Writeback\GitHubReadClient;
use App\Bridge\Writeback\GitHubTokenFileConsumer;
use App\Bridge\Writeback\GitHubTokenResolver;
use App\Bridge\Writeback\GitHubWriteDebt;
use App\Bridge\Writeback\PrCorrelationCommenter;
use App\Bridge\Writeback\ProtocolInvalidLabeler;
use App\Bridge\Writeback\TokenFileFault;
use App\Bridge\Writeback\TokenResolution;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * Can the legs that reach GitHub with ONLY the placed token file actually do so? (card#11201)
 *
 * ⭐ THE GAP THIS CLOSES. Three runtime legs resolve their GitHub token from
 * `<secret_dir>/github/token` (or `providers.github.token_path`) and from nothing else: under
 * PHP-FPM `GH_TOKEN` is absent and the credential-store helper is CLI-only (DL-184). With no
 * usable file each one drops what it decided and logs it — nothing retries on its own, and the
 * delivery's answer does not move. The one leg that probed the file used to sit inside
 * `promote_on_release` in `WritebackMappingConfigCheck`, so an install with that switch off was
 * told nothing while every DL-390 correlation comment it decided was dropped — on a peer install
 * for weeks, and the cards it was meant to explain sat unexplained. That probe now lives here,
 * once, for every consumer (canon #5).
 *
 * ⭐ THE CONSUMERS DECLARE THEMSELVES — {@see self::CONSUMERS} is the registry, and each entry's
 * name and on/off predicate live on the consumer ({@see GitHubTokenFileConsumer}). This class
 * keeps no list of leg names or switches. `GitHubTokenFileConsumerRegistryTest` reds when a
 * class under `app/` calls `->resolveFromFile(` or `->resolveFor(` and is neither registered
 * here nor ruled CLI-only in that test — a lexical census, so a call it cannot spell is not in it.
 *
 * ⛔ SEVERITY — `fail` WHERE THE FILE IS PROVEN UNUSABLE FOR EVERY READER, and that is a
 * deliberate departure from the `warn` the promote-only probe carried. The comment and the label
 * are an OPERATOR's signal: a correlation comment is the only surface that says why a merge did
 * not move its card, so dropping it silently is the defect, not a degraded mode. And the comment
 * is on wherever a repo is mapped — it has no switch — so an install missing the file has a
 * broken enablement, the shape `bridge:check` fails elsewhere (a missing repo webhook, an
 * unreadable `.env` flag). Where the leg could not tell — this process may not read a file the
 * receiver's user can, or cannot see the directory, or GitHub did not answer — the line is
 * `unvalidated` and the exit code does not move: a fail there would convict a healthy install.
 *
 * ⚑ RESOLVED IS NOT USABLE. A resolved token is put to ONE read GitHub does not count against the
 * rate limit, and the answer is judged only as far as it goes: a `401` is a dead token for every
 * leg; a CLASSIC token's scopes come back in a header, and a writing leg needs `repo` (or
 * `public_repo`, for public repos only) — scope only: repo access and SAML SSO authorisation are
 * not asked, and the `ok` line says so; a fine-grained or installation token reports no
 * permissions on any read this bridge makes, so its write access is said to be UNMEASURED, by
 * name, and never passed. An EMPTY scopes header is "no scope" only on a token with a classic
 * prefix; on any other it is unmeasured too.
 *
 * ⚑ READ BY THIS RUN IS NOT READ BY THE RECEIVER. The file is owner-only, so this run read it as
 * its owner or as root; an answer GitHub would pass becomes `ok` only where nothing says the receiver's
 * user cannot read it, and never on a root run — `unvalidated` otherwise. {@see receiverRead()} owns
 * the comparison and what it cannot see. A `fail` stands as it is: it holds for every reader.
 *
 * ⚑ WHAT A MISSING FILE ALREADY COST is counted where the bridge keeps it: a comment or label
 * dropped for want of a token is recorded in {@see GitHubWriteDebt} for `bridge:github-owed`.
 * That record keeps a write for its own expiry window, so an older drop is not counted, and
 * promote-on-release keeps no such record (its drop raises a durable alert instead).
 */
final class GitHubTokenFileCheck implements Check
{
    public const ID = 'github.token_file';

    /**
     * THE REGISTRY: every runtime leg that reaches GitHub with the placed token file only.
     *
     * @var list<class-string<GitHubTokenFileConsumer>>
     */
    public const CONSUMERS = [
        PrCorrelationCommenter::class,
        ProtocolInvalidLabeler::class,
        KanbanPromoteReleasedHandler::class,
    ];

    /** Per request. One request per run, so this bounds what the leg adds to `bridge:check`. */
    private const TIMEOUT_SECONDS = 10;

    /** The reason both writers record when no token file resolved — one vocabulary, asserted equal by test. */
    private const DROPPED_FOR_NO_TOKEN = PrCorrelationCommenter::REASON_TOKEN_UNRESOLVED;

    public function id(): string
    {
        return self::ID;
    }

    /**
     * @return iterable<Finding|Silence>
     */
    public function run(CheckContext $ctx): iterable
    {
        /** @var array<string, array{repos: list<string>, writes: bool}> $enabled */
        $enabled = [];
        foreach (self::CONSUMERS as $consumer) {
            $repos = $consumer::fileTokenRepos($ctx->writeback);
            if ($repos !== []) {
                $enabled[$consumer::fileTokenLeg()] = ['repos' => $repos, 'writes' => $consumer::fileTokenWrites()];
            }
        }

        $resolver = new GitHubTokenResolver;
        $resolution = $resolver->resolveFromFile();
        $path = $resolver->tokenPath();

        if (! $resolution->ok()) {
            if ($enabled !== []) {
                yield $this->unresolved($resolution, $path, $enabled);
            }
            if ($ctx->writebackUnread) {
                yield Finding::unvalidated("github token file: {$resolution->problem}, and writeback.json did not load (see the error above), so whether a leg switched on by writeback.json needs this file on this install was NOT determined. Fix writeback.json and re-run bridge:check.");
            }
            yield from $this->dropsOwed($enabled);
            yield Silence::because('no token file resolves, and no leg that needs one is switched on — nothing on this install would reach GitHub with it');

            return;
        }

        if ($enabled !== []) {
            yield $this->usability($resolution, $path, $enabled);
        }
        if ($ctx->writebackUnread) {
            yield Finding::unvalidated("github token file: a token file resolves ({$resolution->source}), and writeback.json did not load (see the error above), so whether a leg switched on by writeback.json needs it — and whether it can serve that leg — was NOT determined. Fix writeback.json and re-run bridge:check.");
        }
        if ($enabled === []) {
            yield Silence::because('a token file resolves and no leg that uses it is switched on, so there is nothing to put it to');

            return;
        }

        yield from $this->dropsOwed($enabled);
    }

    /**
     * No token file resolved and at least one leg needs it.
     *
     * @param  array<string, array{repos: list<string>, writes: bool}>  $enabled
     */
    private function unresolved(TokenResolution $resolution, string $path, array $enabled): Finding
    {
        $legs = self::describe($enabled);
        if ($resolution->fileFault === TokenFileFault::Unreadable) {
            return Finding::unvalidated("github token file: {$resolution->problem} — THIS process could not read it, which says nothing about the user the receiver runs as, so whether {$legs} can reach GitHub was NOT determined. Re-run bridge:check as the receiver's user.");
        }
        if ($resolution->fileFault === TokenFileFault::Absent) {
            $unseen = PathVisibility::unverifiedUnlessVisible($path, "github token file at {$path}");
            if ($unseen !== null) {
                return $unseen;
            }
        }

        $writing = self::writing($enabled);
        $writeClause = $writing === [] ? '' : ' with Issues and Pull requests WRITE for '.self::describe($writing).', then run `php artisan bridge:github-owed --fix` to make the comments and labels already owed';

        return Finding::fail("github token file: {$resolution->problem}. These legs reach GitHub with this file and nothing else, so they are INERT on this install: {$legs}. Each GitHub request they decide is dropped and logged, and nothing retries it on its own. Place a token at {$path} (chmod 600, owned by the user the receiver runs as){$writeClause}.");
    }

    /**
     * A token file resolved: can it serve the legs? One read, judged only as far as it answers, and
     * the receiver's own read of the file judged by {@see receiverRead()}.
     *
     * @param  array<string, array{repos: list<string>, writes: bool}>  $enabled
     */
    private function usability(TokenResolution $resolution, string $path, array $enabled): Finding
    {
        $source = (string) $resolution->source;
        [$receiverMayRead, $reader] = $this->receiverRead($path);
        try {
            $scopes = (new GitHubReadClient((string) $resolution->token, self::TIMEOUT_SECONDS))->oauthScopes();
        } catch (RequestException $e) {
            $status = $e->response->status();
            if ($status === 401) {
                return Finding::fail("github token file: GitHub REFUSES the token in {$source} (HTTP 401 — expired or revoked), so these are INERT on this install: ".self::describe($enabled).'. Replace the token, then re-run bridge:check.');
            }

            return Finding::unvalidated("github token file: GitHub answered HTTP {$status} to a read made with the token in {$source}, so whether it can serve ".self::describe($enabled)." was NOT measured — this is not evidence the token is bad. Re-run bridge:check. {$reader}");
        } catch (Throwable $e) {
            return Finding::unvalidated("github token file: could NOT reach GitHub to try the token in {$source} (".UntrustedText::forOperator(RedactedErrorText::of($e)).'), so whether it can serve '.self::describe($enabled)." was NOT measured — this is not evidence the token is bad. Re-run bridge:check once api.github.com is reachable. {$reader}");
        }

        $writing = self::writing($enabled);
        if ($writing === []) {
            return $this->passUnlessUnread($receiverMayRead, $reader, "GitHub accepts the token in {$source} for ".self::describe($enabled).' — no leg here writes, so its write access is not asked.');
        }
        $writers = self::describe($writing);
        $unmeasured = match (true) {
            $scopes === null => "it is not a classic token (GitHub's answer carries no X-OAuth-Scopes)",
            $scopes === [] && ! self::isClassic((string) $resolution->token) => "GitHub's answer names no scope and the token carries no classic-token prefix (`ghp_` or `gho_`), so that is not read as a classic token with no scope",
            default => null,
        };
        if ($unmeasured !== null) {
            return Finding::unvalidated("github token file: write scope UNMEASURED — GitHub accepts the token in {$source}, but {$unmeasured}, and no read this bridge makes reports a fine-grained or installation token's own permissions. {$writers} need Issues and Pull requests WRITE; without it GitHub refuses every write 403, and `php artisan bridge:github-owed` lists what was refused. {$reader}");
        }
        /** @var list<string> $scopes */
        $carried = $scopes === [] ? 'no scope at all' : 'scopes: '.UntrustedText::forOperator(implode(', ', $scopes));
        if (in_array('repo', $scopes, true)) {
            return $this->passUnlessUnread($receiverMayRead, $reader, "the classic token in {$source} carries the `repo` scope, which {$writers} need to write (scope only; repo access and SSO authorisation not measured).");
        }
        if (in_array('public_repo', $scopes, true)) {
            return Finding::warn("github token file: the classic token in {$source} carries `public_repo` and not `repo` ({$carried}), so {$writers} can write on a PUBLIC repo only — on a private one GitHub refuses every write 403. Add the `repo` scope if any of those repos is private. {$reader}");
        }

        return Finding::fail("github token file: the classic token in {$source} carries neither `repo` nor `public_repo` ({$carried}), so GitHub refuses every comment and label it would write — INERT on this install: {$writers}. Replace it with a token carrying `repo`, then run `php artisan bridge:github-owed --fix`.");
    }

    /**
     * The one way an answer GitHub would pass becomes a finding: `ok` only where nothing says the
     * receiver's user cannot read the file, `unvalidated` otherwise.
     */
    private function passUnlessUnread(bool $receiverMayRead, string $reader, string $measured): Finding
    {
        return $receiverMayRead
            ? Finding::ok("github token file: {$measured} {$reader}")
            : Finding::unvalidated("github token file: NOT established that the receiver can read it — {$reader} What this run did measure: {$measured}");
    }

    /**
     * Can the RECEIVER's user read the token file this run read? Not by reading it: the file is
     * owner-only (`SecretFile` refuses any group or world bit), so this run read it because it is
     * the file's owner or root, and neither says who the receiver runs as.
     *
     * ⭐ THE COMPARISON IS WITH THE OWED GITHUB-WRITE RECORD — or its lock
     * ({@see GitHubWriteDebt::ownedFiles()}, the entries the receiver opens) — and ONLY where its
     * state dir is writable by its owner alone and that owner also owns the record. The receiver
     * must write in that dir, and nobody but the dir's owner (or root) can create a file there, so
     * the record's owner is then the receiver's user. The record alone is not enough:
     * {@see GitHubWriteDebt::writerRefusal()} cannot refuse the FIRST write of an absent record by
     * a non-root user other than the receiver's (`StateWriterRefusal`'s docblock says so), and
     * `bridge:replay --force` reaches that write as the operator — so in a group-writable state dir
     * the operator can own the record. Neither the state dir's owner alone nor `storage/logs` is
     * used for the same reason: either may be group-writable by design (docs/multi-agent.md). A
     * root-owned record is no witness — root is never the receiver's user.
     *
     * ⛔ A ROOT RUN NEVER PASSES. Root traverses a root-owned `0700` directory above a token the
     * receiver cannot reach, so even matching owners do not establish the receiver's read there.
     *
     * ⛔ A STATE DIR THIS RUN CANNOT TRAVERSE answers the question the other way: the receiver writes
     * there, so a run that cannot see in is not the receiver's user.
     *
     * Returns [whether a pass may stand, the clause the finding carries]. A pass may stand where a
     * non-root run finds matching owners, or finds no such record — then the clause says what was
     * not measured, as `agent.kanban_user_roster` does. On a healthy install the record is absent,
     * so that disclosure is the common answer.
     *
     * @return array{0: bool, 1: string}
     */
    private function receiverRead(string $path): array
    {
        $identity = app(ProcessIdentity::class);
        $name = fn (int $uid): string => $identity->accountName($uid) ?? "uid {$uid}";
        $measure = "Run `sudo -u <pool user> php artisan bridge:check` to measure the receiver's PHP-FPM pool user.";
        $tokenOwner = $identity->ownerOf($path);
        $root = $identity->euid() === 0;

        if ($tokenOwner === 0) {
            return [false, "{$path} is owned by root, which the receiver never runs as, and a token file is readable by its owner alone — so the receiver cannot read it: chown it to the user the receiver runs as. {$measure}"];
        }
        $stateDir = dirname(GitHubWriteDebt::path());
        if (! PathVisibility::ancestorIsTraversable(GitHubWriteDebt::path())) {
            return [false, "this run cannot see into {$stateDir}, where the receiver writes its state, so it does not run as the receiver's user, and reading a token file it owns says nothing about whether the receiver can. {$measure}"];
        }
        $witness = $tokenOwner === null ? null : self::receiverOwnedRecord($identity, $stateDir);
        if ($witness !== null) {
            [$file, $owner] = $witness;
            if ($owner !== $tokenOwner) {
                return [false, "{$path} is owned by {$name($tokenOwner)}, but {$file} — in a state dir only {$name($owner)} can write, where the receiver must write — is owned by {$name($owner)}, and a token file is readable by its owner alone, so the receiver most likely cannot read it: chown it to {$name($owner)} if that is the user the receiver runs as. {$measure}"];
            }
            if ($root) {
                return [false, "{$path} shares its owner, {$name($owner)}, with {$file}, but this run is root, which reaches files the receiver may not (a root-owned directory above the token is no barrier to root), so that does not establish the receiver's read. {$measure}"];
            }

            return [true, "{$path} is owned by {$name($owner)}, the owner of {$file}, in a state dir only {$name($owner)} can write — and the receiver must write there, so it reads the token file as its owner."];
        }
        if ($root) {
            return [false, "this run is root, which reads any file, so its read says nothing about the user the receiver runs as. {$measure}"];
        }

        return [true, "Whether the receiver's PHP-FPM pool user can read it was not measured: run `sudo -u <pool user> php artisan bridge:check`."];
    }

    /**
     * The first record file the receiver opens whose owner is evidence of the receiver's user: a
     * non-root owner that also owns `$stateDir`, a dir no group or other user can write. Null
     * when there is none.
     *
     * @return ?array{0: string, 1: int}
     */
    private static function receiverOwnedRecord(ProcessIdentity $identity, string $stateDir): ?array
    {
        clearstatcache(true, $stateDir);
        $perms = @fileperms($stateDir);
        $dirOwner = $identity->ownerOf($stateDir);
        if ($perms === false || ($perms & 0o022) !== 0 || $dirOwner === null || $dirOwner === 0) {
            return null;
        }
        foreach (GitHubWriteDebt::ownedFiles() as $file => $receiverOpens) {
            if ($receiverOpens && $identity->ownerOf($file) === $dirOwner) {
                return [$file, $dirOwner];
            }
        }

        return null;
    }

    /**
     * Only a prefix CLASS is read, never the token: `ghp_` is a classic personal access token and
     * `gho_` an OAuth app token, the two GitHub reports scopes for. A pre-2021 classic token has no
     * prefix and so reads as unmeasured — the safe direction.
     */
    private static function isClassic(string $token): bool
    {
        return str_starts_with($token, 'ghp_') || str_starts_with($token, 'gho_');
    }

    /**
     * The writes this install already decided and did not make for want of a token file, from the
     * record `bridge:github-owed` reads.
     *
     * @param  array<string, array{repos: list<string>, writes: bool}>  $enabled
     * @return iterable<Finding>
     */
    private function dropsOwed(array $enabled): iterable
    {
        if (self::writing($enabled) === []) {
            return;
        }
        $unseen = PathVisibility::unverifiedUnlessVisible(GitHubWriteDebt::path(), 'github token file: the record of GitHub writes this install owes, at '.GitHubWriteDebt::path().',');
        if ($unseen !== null) {
            yield $unseen;

            return;
        }
        try {
            $owed = GitHubWriteDebt::owed();
        } catch (Throwable $e) {
            yield Finding::unvalidated('github token file: could NOT read the record of GitHub writes this install owes ('.RedactedErrorText::of($e).'), so the writes a missing token file already cost were NOT counted. `php artisan bridge:github-owed` names the cause.');

            return;
        }
        $dropped = array_values(array_filter($owed, fn (array $row): bool => $row['reason'] === self::DROPPED_FOR_NO_TOKEN));
        if ($dropped === []) {
            return;
        }
        $days = intdiv(GitHubWriteDebt::EXPIRY_SECONDS, 86400);
        yield Finding::warn('github token file: '.count($dropped).' GitHub write(s) decided since '.$dropped[0]['first_failed_at']
            ." were NOT made because no token file resolved — `php artisan bridge:github-owed` lists them and `--fix` makes them once a usable token file is in place. The record keeps a write for {$days} days, so an older drop is not counted here.");
    }

    /** @param  array<string, array{repos: list<string>, writes: bool}>  $legs */
    private static function describe(array $legs): string
    {
        $parts = [];
        foreach ($legs as $name => $leg) {
            $parts[] = "{$name} on ".implode(', ', $leg['repos']);
        }

        return implode('; ', $parts);
    }

    /**
     * @param  array<string, array{repos: list<string>, writes: bool}>  $legs
     * @return array<string, array{repos: list<string>, writes: bool}>
     */
    private static function writing(array $legs): array
    {
        return array_filter($legs, fn (array $leg): bool => $leg['writes']);
    }
}
