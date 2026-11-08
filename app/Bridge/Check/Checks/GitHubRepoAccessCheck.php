<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Silence;
use App\Bridge\Support\Finding;
use App\Bridge\Writeback\GitHubAccess;
use App\Bridge\Writeback\GitHubReadLeg;
use App\Bridge\Writeback\GitHubRepoAccess;
use App\Bridge\Writeback\GitHubRepoProbe;
use App\Bridge\Writeback\GitHubTokenResolver;
use App\Bridge\Writeback\GitHubWriteClient;
use App\Bridge\Writeback\GitHubWriteLeg;
use App\Bridge\Writeback\TokenResolution;

/**
 * Can the token each repo ACTUALLY resolves do what that repo's legs ask of it — per repo, per leg,
 * writing nothing? (card#12968)
 *
 * `github.token_file` judges each token SOURCE once: does it resolve, does GitHub accept it, does a
 * classic token carry the scope. It cannot see a fine-grained token's permissions, and it does not
 * ask per repo. So a repo mapped to a read-only store key, or re-pointed at a write token that
 * lacks a read permission (a `write_token_path` answers every read for its repo, not only its
 * writes), passed it while every write — or every read — on that repo was refused. This asks the
 * token {@see GitHubTokenResolver::resolveFor()} gives each repo with a leg switched on
 * ({@see GitHubTokenFileCheck::CONSUMERS}) for the repo itself, each read the repo's legs declare,
 * and each write they declare: a comment by a POST GitHub cannot accept
 * ({@see GitHubWriteClient::probeCommentWrite()}), a label from that answer plus the account's role.
 * The verdicts are {@see GitHubRepoProbe::access()}'s; the finding names the source by
 * {@see TokenResolution::sourceLabel()} — a store key by display name, never a path or the token —
 * and, for a refusal, only the legs that depend on what was refused.
 *
 * ⛔ WARN, NEVER FAIL. A refused leg drops what it decided and logs it; the delivery's answer does
 * not move, and `github.token_file` already fails where a source is proven unusable for every
 * reader. A leg GitHub did not answer in a way that measures anything (a 401, a rate limit, a 5xx,
 * no answer) is UNVALIDATED, never folded into a pass or a refusal. A repo whose token does not
 * resolve is `github.token_file`'s to report, and is skipped here.
 *
 * `workflow_run` reads are not asked here: `ci_await.awaits` asks them, for the repos `ci_await`
 * accepts, with the same resolution.
 *
 * Cost: per repo, one read, one per declared read, and — where a leg writes — one issue-list read
 * and one write probe.
 */
final class GitHubRepoAccessCheck implements Check
{
    public function id(): string
    {
        return 'github.repo_access';
    }

    /**
     * @return iterable<Finding|Silence>
     */
    public function run(CheckContext $ctx): iterable
    {
        if ($ctx->writebackUnread) {
            yield Silence::because('writeback.json did not load, so which repo resolves which token was not determined — github.token_file reports that');

            return;
        }

        // Per repo: every runtime leg on it, and which of them depend on each read and each write.
        /** @var array<string, array{legs: list<string>, reads: array<string, array{leg: GitHubReadLeg, by: list<string>}>, writes: array<string, array{leg: GitHubWriteLeg, by: list<string>}>}> $repos */
        $repos = [];
        foreach (GitHubTokenFileCheck::CONSUMERS as $consumer) {
            $name = $consumer::fileTokenLeg();
            foreach ($consumer::fileTokenRepos($ctx->writeback) as $repo) {
                $repos[$repo] ??= ['legs' => [], 'reads' => [], 'writes' => []];
                $repos[$repo]['legs'][] = $name;
                foreach ($consumer::fileTokenReads() as $read) {
                    $repos[$repo]['reads'][$read->name] ??= ['leg' => $read, 'by' => []];
                    $repos[$repo]['reads'][$read->name]['by'][] = $name;
                }
                foreach ($consumer::fileTokenWrites() as $write) {
                    $repos[$repo]['writes'][$write->name] ??= ['leg' => $write, 'by' => []];
                    $repos[$repo]['writes'][$write->name]['by'][] = $name;
                }
            }
        }

        $probe = new GitHubRepoProbe(GitHubTokenResolver::forWriteback($ctx->writeback));
        foreach ($repos as $repo => $declared) {
            $access = $probe->access($repo, array_column($declared['reads'], 'leg'), array_column($declared['writes'], 'leg'));
            if ($access !== null) {
                yield self::finding($repo, $declared, $access);
            }
        }

        yield Silence::because('no repo has a leg that reaches GitHub switched on, or none of them resolves a token — github.token_file reports a token that does not resolve');
    }

    /**
     * @param  array{legs: list<string>, reads: array<string, array{leg: GitHubReadLeg, by: list<string>}>, writes: array<string, array{leg: GitHubWriteLeg, by: list<string>}>}  $declared
     */
    private static function finding(string $repo, array $declared, GitHubRepoAccess $access): Finding
    {
        $legs = implode(', ', $declared['legs']);
        $wrote = $access->with(GitHubAccess::Wrote);
        $refused = $access->with(GitHubAccess::Refused);
        $unmeasured = $access->with(GitHubAccess::Unmeasured);
        $notMeasured = $unmeasured === [] ? '' : ' NOT measured: '.self::list($unmeasured).' — not evidence the token is good or bad; re-run bridge:check.';

        if ($wrote !== []) {
            return Finding::warn("github repo access: the write probe on {$repo}, made with the token from {$access->source}, was ACCEPTED: ".self::list([$wrote[0]]).'.');
        }
        if ($refused !== []) {
            $needs = implode('; ', array_unique(array_column($refused, 'needs')));
            $dependents = implode(', ', array_values(array_unique(array_merge(...array_map(fn (array $leg): array => self::dependents($leg['subject'], $declared), $refused)))));

            return Finding::warn("github repo access: {$repo} resolves its GitHub token from {$access->source}, and GitHub REFUSES that token to ".self::list($refused).". The legs on {$repo} that need it — {$dependents} — are refused on every attempt and drop what they decided (the bridge logs each with token_source). "
                ."Give the token at {$access->source} {$needs} on {$repo}, or point {$repo} at a token that has it (its write_token_path in writeback.json, or its [git-credential-map] key in the coord credential store), then re-run bridge:check.{$notMeasured}");
        }
        if ($unmeasured !== []) {
            $granted = $access->with(GitHubAccess::Granted);

            return Finding::unvalidated("github repo access: whether the token {$repo} resolves from {$access->source} can serve {$legs} was NOT established.{$notMeasured}".($granted === [] ? '' : ' Measured: it can '.self::list($granted).'.'));
        }

        return Finding::ok("github repo access: the token {$repo} resolves from {$access->source} can ".self::list($access->legs)." for {$legs}.");
    }

    /**
     * The runtime legs that depend on one probed subject: every leg on the repo for the repo read,
     * else the legs that declared that read or write.
     *
     * @param  array{legs: list<string>, reads: array<string, array{leg: GitHubReadLeg, by: list<string>}>, writes: array<string, array{leg: GitHubWriteLeg, by: list<string>}>}  $declared
     * @return list<string>
     */
    private static function dependents(string|GitHubReadLeg|GitHubWriteLeg $subject, array $declared): array
    {
        return match (true) {
            $subject instanceof GitHubReadLeg => $declared['reads'][$subject->name]['by'],
            $subject instanceof GitHubWriteLeg => $declared['writes'][$subject->name]['by'],
            default => $declared['legs'],
        };
    }

    /** @param  list<array{subject: string|GitHubReadLeg|GitHubWriteLeg, what: string, needs: string, access: GitHubAccess, answer: string}>  $legs */
    private static function list(array $legs): string
    {
        return implode('; ', array_map(fn (array $leg): string => "{$leg['what']} ({$leg['answer']})", $legs));
    }
}
