<?php

namespace App\Bridge\Writeback;

use App\Bridge\Support\ForeignText;
use App\Bridge\Support\ReceiverUrl;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Log;
use Throwable;
use UnexpectedValueException;

/**
 * Read-only GitHub PR-state client for the reconciler (bridge:reconcile, DL-183).
 * The event-driven writeback derives a card's stage from the webhook body; the
 * reconciler instead recomputes it from GitHub GROUND TRUTH — GET the PR and read
 * its current state/merged/base. That closes RC-B (a webhook dropped during a
 * bridge outage is never re-delivered — GitHub fires each once).
 *
 * Token-agnostic: constructed with an already-resolved token by the caller. The
 * reconciler resolves one token PER REPO (GitHubTokenResolver, DL-185) — the store
 * map routes each repo to its own least-privilege PAT — so the client is built per
 * repo rather than owning resolution itself.
 *
 * Verb-only + throws on non-2xx: the caller (ReconcileCommand) decides that a
 * per-card 4xx/5xx is warn + skip, never abort the whole run.
 *
 * ⚑ IT IS NO LONGER PR-STATE ONLY (card#9150). {@see self::hasRepoWebhookFor} reads the
 * repo's WEBHOOK list. It lives here rather than in a sibling client because everything
 * that made this class the right shape for a PR read is the same for a hook read — the
 * resolved-token constructor, the UA/Accept/api-version headers GitHub requires, the
 * timeout, and throw-on-non-2xx so the caller owns the posture. A second read-only GitHub
 * client would be a second place for those to drift (canon #5). What it is NOT is a
 * write client: nothing here creates, edits or deletes a hook.
 *
 * ⚑ IT ALSO READS PULL-REQUEST COMMENTS (DL-390): {@see self::hasIssueCommentStartingWith}, kept
 * here for the same reasons the hook read is. The bridge's GitHub writes are
 * {@see GitHubWriteClient}'s, and this class still makes none.
 */
final class GitHubReadClient
{
    public const API_BASE = GitHubApi::BASE;

    /** Kept under a human-interactive command's patience; a slow GitHub is skipped per card. */
    public const TIMEOUT_SECONDS = 15;

    /** GitHub's maximum page size for `GET /repos/{repo}/hooks`; a smaller one only costs pages. */
    private const HOOK_PAGE_SIZE = 100;

    /**
     * How many hook pages {@see self::hasRepoWebhookFor} will walk before giving up.
     *
     * A BOUND, NOT A BELIEF ABOUT REPOS. It exists so an unbounded loop cannot be driven by
     * an upstream that keeps answering full pages; reaching it reports "I did not finish"
     * (`null`), never "absent". At 100 per page that is 1,000 hooks on one repo — GitHub's
     * own documented ceiling is far below it — so the honest answer at the cap is that
     * something is answering this URL that is not a repo's hook list.
     */
    private const HOOK_PAGE_LIMIT = 10;

    /** GitHub's maximum page size for `GET /repos/{repo}/issues/{n}/comments`. */
    private const COMMENT_PAGE_SIZE = 100;

    /**
     * How many comment pages {@see self::hasIssueCommentStartingWith} walks before it answers "not
     * established". A bound on a loop, not a belief about pull requests, exactly as HOOK_PAGE_LIMIT.
     */
    private const COMMENT_PAGE_LIMIT = 10;

    /** GitHub's maximum page size for `GET /repos/{repo}/actions/runs`. */
    private const RUNS_PAGE_SIZE = 100;

    /**
     * How many run pages {@see self::workflowRunsForHead} walks before it throws. A bound on a loop,
     * not a belief about how many runs one commit has, exactly as HOOK_PAGE_LIMIT.
     */
    private const RUNS_PAGE_LIMIT = 10;

    /**
     * @param  string  $token  an already-resolved GitHub read token (resolution is the caller's — GitHubTokenResolver)
     * @param  ?int  $timeoutSeconds  per-request timeout override — the SYNCHRONOUS promote-on-release
     *                                writeback leg (DL-207) runs inside a webhook request and passes a
     *                                tighter budget than reconcile's human-interactive 15s default so a
     *                                board scan can't stack N slow reads past the FPM request ceiling.
     */
    public function __construct(private string $token, private ?int $timeoutSeconds = null) {}

    /**
     * One-shot auth/scope probe for a repo (`GET /repos/{repo}`). Throws
     * RequestException on any non-2xx — used at startup to fail LOUDLY when the
     * token can't see a private repo (404), is expired/revoked (401), or lacks
     * scope (403), instead of every per-card getPull silently 404-ing and the run
     * exiting 0 (the degraded-read-must-be-loud posture, DL-026 lineage).
     */
    public function probeRepo(string $repo): void
    {
        $this->http()->get(self::API_BASE."/repos/{$repo}")->throw();
    }

    /**
     * The scopes this token carries when it is a CLASSIC token: GitHub reports them in the
     * `X-OAuth-Scopes` header of an authenticated answer (GitHub Docs, *Scopes for OAuth apps*).
     * Null when the answer carries no such header — a fine-grained or installation token, whose
     * permissions no header reports — so a caller can say UNMEASURED rather than guess (card#11201).
     * An empty list is a classic token with no scope at all.
     *
     * `GET /rate_limit` because every valid token may read it and it does not count against the
     * rate limit (GitHub Docs, *Rate limit*). Throws RequestException on any non-2xx.
     *
     * @return ?list<string>
     */
    public function oauthScopes(): ?array
    {
        $response = $this->http()->get(self::API_BASE.'/rate_limit')->throw();
        if (! $response->toPsrResponse()->hasHeader('X-OAuth-Scopes')) {
            return null;
        }

        return array_values(array_filter(
            array_map(trim(...), explode(',', $response->header('X-OAuth-Scopes'))),
            fn (string $scope): bool => $scope !== '',
        ));
    }

    /**
     * Does this repo carry a webhook whose delivery URL is `$receiverUrl` — and, where the
     * answer is no, how many webhooks does it carry? (card#9150, widened by card#9717)
     *
     * ⛔ IT RETURNS A VERDICT AND A COUNT, AND THAT IS A SECURITY BOUNDARY RATHER THAN A STYLE
     * CHOICE. The hook list is the WHOLE FLEET's: every other install's receiver endpoint is in
     * the response body. Matching INSIDE this method is what makes "no other endpoint can reach
     * an operator log, a finding or a traceback" true by construction — a `list<string>`
     * return would put that guarantee back on every caller's discipline, and the first
     * caller to interpolate its result into a diagnostic would publish the fleet. ⚠ THE COUNT
     * card#9717 ADDS IS A PROPERTY OF THE LIST AND NEVER A VALUE FROM IT, which is the whole
     * reason it is an `int`: {@see GitHubHookListAnswer} owns that boundary and why anything
     * richer would breach it.
     *
     * ⚠ THE COUNT IS ANSWERED ONLY WHERE THE ENUMERATION RAN TO THE END. A match returns
     * mid-walk, so a count on that path would be *hooks seen so far* under the name *hooks on
     * the repo*; the page bound and every unreadable-body path establish nothing at all.
     *
     * ⭐ THE THIRD ANSWER IS THE POINT. `null` means THIS READ DID NOT ESTABLISH EITHER —
     * the enumeration hit {@see self::HOOK_PAGE_LIMIT}, or a 200 came back carrying
     * something other than a JSON list (a proxy, a cache, an auth portal — the class
     * {@see self::warnUnreadableBody} exists for). Collapsing that into `false` would tell
     * the caller the hook is GONE on evidence that measured nothing, and the caller
     * (`GitHubWebhookSubscriptionCheck`, whose absent arm is a `fail` that moves
     * `bridge:check`'s exit code) would red a healthy install off a bad proxy.
     *
     * Throws RequestException on any non-2xx, like every other read here: a 403 or 404 is a
     * token that may not enumerate hooks on this repo, which is the caller's to classify —
     * NOT an empty result (an unreadable API response is not an empty one).
     */
    public function hasRepoWebhookFor(string $repo, string $receiverUrl): GitHubHookListAnswer
    {
        // ⛔ HOISTED ABOVE THE PAGE LOOP (card#9150 r3). Declared per page, its `return null`
        // fired at the end of whichever page saw the unreadable entry and pre-empted every
        // later page — so a malformed entry on page 1 SILENCED a real matching hook on page 2.
        // Measured: 99 foreign + 1 unreadable on page 1, the match on page 2, answered
        // `unvalidated` where `ok` was earned. The direction was safe (never a false `fail`),
        // but the claim *a match still wins* was FALSE across a page boundary while three
        // surfaces asserted it unconditionally.
        $unreadableElement = false;
        // ⚠ HOOKS SEEN, WHICH IS THE REPO'S HOOK COUNT ONLY WHERE THE WALK REACHES THE SHORT
        // PAGE BELOW — the one place this is read. Every other exit abandons it rather than
        // reporting a partial walk as a total (card#9717).
        $hooksSeen = 0;
        // card#11283: a MATCH no longer ends the walk, because every matching hook's `active` and
        // `events` are wanted, not the first one's. ⛔ `found` keeps exactly its old meaning: once
        // a hook has matched, NOTHING later in the walk — a failed page, a body that is not a list,
        // an unreadable entry, the page bound — takes the verdict away; it only makes the two new
        // facts unknown (null). Walking past the match costs a request only on a repo whose
        // matching hook sits on a FULL page.
        $match = null;
        for ($page = 1; $page <= self::HOOK_PAGE_LIMIT; $page++) {
            try {
                $body = $this->http()->get(self::API_BASE."/repos/{$repo}/hooks", [
                    'per_page' => self::HOOK_PAGE_SIZE,
                    'page' => $page,
                ])->throw()->json();
            } catch (Throwable $e) {
                if ($match !== null) {
                    return GitHubHookListAnswer::found();
                }

                throw $e;
            }

            if ($match !== null && (! is_array($body) || ! array_is_list($body))) {
                return GitHubHookListAnswer::found();
            }
            if (! is_array($body) || ! array_is_list($body)) {
                self::warnUnreadableBody(
                    "the webhook-list read for {$repo} returned a 200 whose body is not a JSON list of hooks — whether a hook points at this install is UNKNOWN, not false, and a consumer that reads it as \"no such hook\" would convict a healthy install",
                    ['repo' => $repo, 'read' => 'list-hooks', 'page' => $page],
                );

                return GitHubHookListAnswer::undetermined();
            }

            // ⛔ AN ELEMENT THIS PROJECTION CANNOT READ MAKES THE ENUMERATION INCOMPLETE, and
            // it must reach `null` rather than falling through to `false` (card#9150 r2). The
            // container-level guard above already routes an unreadable 200 to `null`; before
            // this, an entry with no readable `config.url` — the SAME cause, an upstream shape
            // change — fell past these type tests into the short-page `return false` below and
            // convicted the install. On a shape change that is EVERY install at once, every
            // exit code moved, off a read that established nothing.
            //
            // ⚑ A MATCH STILL WINS. The flag is only consulted when no hook matched, so one
            // malformed entry beside a readable matching one still answers `true` — an
            // unreadable element casts doubt on an ABSENCE, never on a hit.
            foreach ($body as $hook) {
                $config = is_array($hook) ? ($hook['config'] ?? null) : null;
                $url = is_array($config) ? ($config['url'] ?? null) : null;
                if (! is_string($url)) {
                    $unreadableElement = true;

                    continue;
                }
                // `deliversTo`, NOT `matchesExactly` (card#9150 r1): a hook spelled
                // `?b=owner%2Frepo` delivers here exactly as `?b=owner/repo` does, and this
                // method's negative answer becomes a `fail` that moves an exit code.
                // `ReceiverUrl` owns why the two predicates differ and why provision keeps
                // the exact one.
                if (ReceiverUrl::deliversTo($url, $receiverUrl)) {
                    $match = HookDeliverySettings::fold($match, $hook);
                }
            }

            $hooksSeen += count($body);

            // A SHORT PAGE IS THE END OF THE LIST, which is what makes `false` an
            // EXHAUSTED enumeration rather than "not on page 1" — the distinction the
            // `fail` severity below this rests on. ⚑ The flag is consulted HERE, at the one
            // place an ABSENCE is about to be asserted, so an unreadable entry anywhere in
            // the walk unmakes that absence while never pre-empting a later page's match.
            if (count($body) < self::HOOK_PAGE_SIZE) {
                if ($match !== null) {
                    return GitHubHookListAnswer::found($match);
                }
                if ($unreadableElement) {
                    self::warnUnreadableBody(
                        "the webhook-list read for {$repo} returned a 200 carrying at least one hook entry with no readable `config.url` — this run could not enumerate the repo's hooks, so whether one points at this install is UNKNOWN, not false",
                        ['repo' => $repo, 'read' => 'list-hooks', 'page' => $page],
                    );

                    return GitHubHookListAnswer::undetermined();
                }

                return GitHubHookListAnswer::exhausted($hooksSeen);
            }
        }

        return $match !== null ? GitHubHookListAnswer::found() : GitHubHookListAnswer::undetermined();
    }

    /**
     * Does any comment on issue or pull request $number START WITH $prefix? (DL-390)
     *
     * Matched here and answered as a boolean, for the hook read's reason: no caller holds other
     * people's comment text. STARTS WITH, not contains, so a comment that merely QUOTES the prefix
     * is not mistaken for the one that carries it.
     *
     * `null` means THIS READ ESTABLISHED NEITHER: the walk hit {@see self::COMMENT_PAGE_LIMIT}, a 200
     * carried something that is not a list of comments, or an entry had no readable `body`. A caller
     * deduping a write on this answer must read `null` as "cannot tell", never as "absent" — on a
     * response-shape change every entry is unreadable, and "absent" would re-post on every event.
     *
     * Throws RequestException on any non-2xx, like every other read here.
     */
    public function hasIssueCommentStartingWith(string $repo, int $number, string $prefix): ?bool
    {
        $unreadableElement = false;

        for ($page = 1; $page <= self::COMMENT_PAGE_LIMIT; $page++) {
            $body = $this->http()->get(self::API_BASE."/repos/{$repo}/issues/{$number}/comments", [
                'per_page' => self::COMMENT_PAGE_SIZE,
                'page' => $page,
            ])->throw()->json();

            if (! is_array($body) || ! array_is_list($body)) {
                self::warnUnreadableBody(
                    "the comment-list read for {$repo}#{$number} returned a 200 whose body is not a JSON list of comments — whether a comment is already there is UNKNOWN, not false",
                    ['repo' => $repo, 'number' => $number, 'read' => 'list-issue-comments', 'page' => $page],
                );

                return null;
            }

            foreach ($body as $comment) {
                $text = is_array($comment) ? ($comment['body'] ?? null) : null;
                if (! is_string($text)) {
                    $unreadableElement = true;

                    continue;
                }
                if (str_starts_with($text, $prefix)) {
                    return true;
                }
            }

            if (count($body) < self::COMMENT_PAGE_SIZE) {
                if ($unreadableElement) {
                    self::warnUnreadableBody(
                        "the comment-list read for {$repo}#{$number} returned a 200 carrying at least one comment with no readable `body` — whether a comment is already there is UNKNOWN, not false",
                        ['repo' => $repo, 'number' => $number, 'read' => 'list-issue-comments', 'page' => $page],
                    );

                    return null;
                }

                return false;
            }
        }

        return null;
    }

    /**
     * The PR's ground-truth state. Throws RequestException on any non-2xx (a
     * deleted PR 404s — the caller warns + skips that card once the repo probe
     * has confirmed the token CAN see the repo).
     *
     * `merge_commit_sha` is GitHub's post-merge commit for a MERGED PR (the sha the
     * promote-on-release leg tests for reachability from `main`, DL-207). For an OPEN
     * PR GitHub populates it with a transient TEST-merge sha that is on no branch —
     * so a consumer must gate on `merged === true` before trusting it, never on
     * emptiness (it is rarely empty).
     *
     * `title` is the CLOSURE surface (card#7348 / DL-305): the reconciler recomputes the
     * same merge decision the event path makes, so it must read the same closing form out
     * of the same field, or the backstop would re-apply on a later pass exactly the move
     * the event path had just declined — the defect reintroduced through the leg that
     * exists to repair it. It is projected here rather than derived at the call site
     * because this is the only place that knows the GitHub response shape. An absent
     * title reads as `''`, which carries no closing form: the safe direction.
     *
     * `head_ref` is the SECOND closure surface (card#7348 / DL-308) and is projected for
     * the same lockstep reason: the event path reads `pull_request.head.ref` off the
     * webhook body, so the reconciler must read the same field out of the REST object or
     * the backstop would re-plan on a schedule exactly the move the event path allowed —
     * or, worse here, decline one the event path made. GitHub retains `head.ref` on the PR
     * record after the branch is DELETED (which is the normal post-merge state of every PR
     * this leg reads), so it is available on the whole population; `head.repo` is the field
     * that goes null on a deleted fork, and nothing here reads it. An absent ref reads as
     * `''`, which names no card: the safe direction, and the same one the title takes.
     *
     * ⛔ `title` AND `head_ref` ARE THE TWO FIELDS ON THIS PROJECTION A STRANGER CHOOSES,
     * so they leave here as {@see ForeignText} and not as `string` (card#9200, DL-366). Both
     * are authored by whoever opened the PR — on a public repo, anyone with a fork — and
     * MEASURED, not assumed: GitHub accepts a branch ref carrying U+202E and U+200B and
     * returns it byte-identical (card#9266). They are the two that cannot simply be escaped
     * here, because `RevertGrammar` and `NoCloseGrammar` match on the RAW bytes and an
     * escaped ref would match nothing; `ForeignText` keeps the raw bytes reachable through a
     * named, pinned accessor while making an interpolation a build error. ⚑ THE OTHER FIVE
     * KEYS STAY `string` BECAUSE THEIR AUTHOR IS GITHUB, NOT THE PR'S OPENER: `state`,
     * `merged` and `merge_commit_sha` are generated, `html_url` is composed by GitHub from
     * the repo and number, and `base_ref` names a branch that must already exist in the BASE
     * repo — a fork's opener cannot create one there.
     *
     * ⭐ `merged` IS NULLABLE, and the null is the whole point (card#8787). It was a plain
     * `bool` collapsed from `($pr['merged'] ?? false) === true`, so a 200 whose body carried
     * no `merged` at all was byte-identical to an honest `merged: false` — and both silently
     * meant "not merged" to every consumer. `null` now says the third thing that was true all
     * along and unsayable: THE ANSWER DID NOT CARRY A MERGE STATE. Every consumer's existing
     * falsy test (`! $pr['merged']`, `!== true`) reads null exactly as it read the collapsed
     * false, so nothing any caller decides moves; what changes is that a caller CAN now tell
     * the two apart, and {@see warnUnreadableBody} names the cause here for the callers that
     * cannot.
     *
     * @return array{state: string, merged: ?bool, base_ref: string, html_url: string, merge_commit_sha: string, title: ForeignText, head_ref: ForeignText}
     */
    public function getPull(string $repo, int $number): array
    {
        $pr = $this->http()->get(self::API_BASE."/repos/{$repo}/pulls/{$number}")->throw()->json();
        $pr = is_array($pr) ? $pr : [];
        $base = is_array($pr['base'] ?? null) ? ($pr['base']['ref'] ?? '') : '';
        $head = is_array($pr['head'] ?? null) ? ($pr['head']['ref'] ?? '') : '';
        $merged = $pr['merged'] ?? null;
        if (! is_bool($merged)) {
            self::warnUnreadableBody(
                "the pull read for {$repo}#{$number} returned a 200 whose body carries no readable `merged` flag — the PR's merge state is UNKNOWN, not false, and a consumer that reads it as \"not merged yet\" degrades silently",
                ['repo' => $repo, 'pr' => $number, 'read' => 'get-pull'],
            );
            $merged = null;
        }

        return [
            'state' => is_string($pr['state'] ?? null) ? $pr['state'] : '',
            'merged' => $merged,
            'base_ref' => is_string($base) ? $base : '',
            'html_url' => is_string($pr['html_url'] ?? null) ? $pr['html_url'] : '',
            'merge_commit_sha' => is_string($pr['merge_commit_sha'] ?? null) ? $pr['merge_commit_sha'] : '',
            'title' => ForeignText::of(is_string($pr['title'] ?? null) ? $pr['title'] : ''),
            'head_ref' => ForeignText::of(is_string($head) ? $head : ''),
        ];
    }

    /**
     * The GitHub compare `status` of `base...head` (`ahead` | `behind` | `identical`
     * | `diverged`), for the promote-on-release reachability test (DL-207). Called as
     * `compareStatus($repo, $mergeSha, PrOutcome::RELEASE_BASE)`: `ahead`/`identical`
     * means `main` is ahead-of / equal-to the merge sha ⇒ the sha is an ancestor of
     * `main` ⇒ the card's work is ON main ⇒ released. `behind`/`diverged` ⇒ not on
     * main. Only the `status` scalar is read, so the 250-commit cap on the compare's
     * `commits[]` is irrelevant (no truncation risk). `base`/`head` may each be a raw
     * SHA or a ref. Throws RequestException on any non-2xx (a bad/unknown sha 404s —
     * the caller warns + skips that card, as with getPull).
     *
     * ⭐ `''` MEANS "THE ANSWER CARRIED NO STATUS", never "not reachable" (card#8787). A real
     * compare always names one of the four statuses, so the empty string is unreachable from a
     * body this projection could read — which is what makes it a usable sentinel and why the
     * signature does not need a null. The caller may (and does) act on it as a distinct cause;
     * {@see warnUnreadableBody} names it here for the whole read, once, whoever is calling.
     */
    public function compareStatus(string $repo, string $base, string $head): string
    {
        $cmp = $this->http()->get(self::API_BASE."/repos/{$repo}/compare/{$base}...{$head}")->throw()->json();
        $cmp = is_array($cmp) ? $cmp : [];
        $status = $cmp['status'] ?? null;
        if (! is_string($status)) {
            self::warnUnreadableBody(
                "the compare read for {$repo} {$base}...{$head} returned a 200 whose body carries no readable `status` — reachability is UNKNOWN, not \"not reachable\", and a consumer that reads it as a negative degrades silently",
                ['repo' => $repo, 'base' => $base, 'head' => $head, 'read' => 'compare'],
            );

            return '';
        }

        return $status;
    }

    /**
     * The repo's recently-MERGED pull requests, most recently updated first — the evidence
     * half of the multi-board exposure audit (card#9850 / DL-404): which cards does this
     * repo's own merged work actually cite?
     *
     * ⚑ A SAMPLE, AND IT SAYS SO. GitHub has no "list merged" filter, so this asks for closed
     * pull requests sorted by update time and keeps the ones carrying a `merged_at` — ONE
     * page, $limit rows, never paginated. It is therefore a WINDOW on the repo's history and
     * never its census: the caller must state the window, and MUST NOT read an empty result
     * as "this repo cites no cards". Sorted by `updated` rather than `created` because a
     * stale-but-recently-touched PR is more likely to reflect the citation grammar in force
     * today than an old one that has not moved.
     *
     * ⛔ THE UNREADABLE-200 CASE IS NOT SILENTLY EMPTY. A body this projection cannot read is
     * reported the way every other read here reports one and yields no rows — which is why
     * the caller treats "no rows" as UNMEASURED rather than as a clean bill.
     *
     * Throws RequestException on any non-2xx; the caller decides whether that is a skip or a
     * failure, exactly as with {@see getPull}.
     *
     * @return list<array{number: int, title: ForeignText, head_ref: ForeignText}>
     */
    public function recentlyMergedPullRequests(string $repo, int $limit): array
    {
        $body = $this->http()->get(self::API_BASE."/repos/{$repo}/pulls", [
            'state' => 'closed', 'sort' => 'updated', 'direction' => 'desc', 'per_page' => $limit,
        ])->throw()->json();

        if (! is_array($body) || ! array_is_list($body)) {
            self::warnUnreadableBody(
                "the closed-pull-request list for {$repo} returned a 200 whose body is not a list of pull requests, so this repo's merged history is UNMEASURED, not empty",
                ['repo' => $repo, 'read' => 'list-pulls'],
            );

            return [];
        }

        $merged = [];
        foreach ($body as $pr) {
            if (! is_array($pr) || ! is_numeric($pr['number'] ?? null) || ($pr['merged_at'] ?? null) === null) {
                continue;
            }
            $head = is_array($pr['head'] ?? null) ? ($pr['head']['ref'] ?? '') : '';
            $merged[] = [
                'number' => (int) $pr['number'],
                'title' => ForeignText::of(is_string($pr['title'] ?? null) ? $pr['title'] : ''),
                'head_ref' => ForeignText::of(is_string($head) ? $head : ''),
            ];
        }

        return $merged;
    }

    /**
     * The assets of the published release tagged `$tag`, or null when GitHub answers 404 for that
     * tag — no published release carries it, or this token cannot see the repo (GitHub answers
     * both the same way). Read by `bridge:client-pack:install` (DL-430).
     *
     * ⛔ A 200 whose body carries no readable asset list THROWS rather than answering "no
     * assets": the caller would otherwise report a release that carries no client pack, which is
     * a claim about the release this read never established.
     *
     * @return ?list<array{id: int, name: string, size: int, digest: ?string}>
     */
    public function releaseAssets(string $repo, string $tag): ?array
    {
        $response = $this->http()->get(self::API_BASE."/repos/{$repo}/releases/tags/".rawurlencode($tag));
        if ($response->status() === 404) {
            return null;
        }
        $body = $response->throw()->json();
        if (! is_array($body) || ! is_array($body['assets'] ?? null) || ! array_is_list($body['assets'])) {
            throw new UnexpectedValueException("the release read for {$repo} {$tag} returned a 200 whose body carries no readable asset list; ".self::UNREADABLE_BODY_CAUSE);
        }

        $assets = [];
        foreach ($body['assets'] as $asset) {
            if (! is_array($asset) || ! is_int($asset['id'] ?? null) || ! is_string($asset['name'] ?? null) || ! is_int($asset['size'] ?? null)) {
                throw new UnexpectedValueException("the release read for {$repo} {$tag} carries an asset without an integer id, a name and an integer size; ".self::UNREADABLE_BODY_CAUSE);
            }
            $assets[] = [
                'id' => $asset['id'],
                'name' => $asset['name'],
                'size' => $asset['size'],
                'digest' => is_string($asset['digest'] ?? null) ? $asset['digest'] : null,
            ];
        }

        return $assets;
    }

    /**
     * One release asset's bytes, by its id under `$repo`. The URL is built here from the repo and
     * the id, never taken from a response body, so the token is sent only to the API base; GitHub
     * redirects the download to its storage host, and the HTTP client drops the `Authorization`
     * header on that cross-origin redirect.
     *
     * ⛔ `replaceHeaders`, NOT `accept()`: `accept()` APPENDS to the API's JSON `Accept`, and with
     * both values GitHub answers the asset's JSON metadata instead of its bytes (measured against a
     * live release, card#10567).
     */
    public function releaseAssetBytes(string $repo, int $assetId): string
    {
        return $this->http()
            ->replaceHeaders(['Accept' => 'application/octet-stream'])
            ->get(self::API_BASE."/repos/{$repo}/releases/assets/{$assetId}")
            ->throw()
            ->body();
    }

    /**
     * Every workflow run GitHub lists for one head SHA (`GET /repos/{repo}/actions/runs?head_sha=`),
     * walked to the end of the list — the read behind `ci_settled` (card#11200 / DL-452).
     *
     * ⛔ IT ANSWERS THE WHOLE LIST OR THROWS. The caller decides "every run is terminal" from
     * this, and a partial list can make that true of a head where it is not — so a 200 whose body
     * is not a readable run list, a run entry without a readable integer `id` or `status`, and a
     * walk that reaches {@see self::RUNS_PAGE_LIMIT} each throw {@see UnexpectedValueException}
     * rather than return what was seen. A non-2xx throws RequestException, like every read here.
     *
     * ⛔ AND THE WALK MUST BE CONSISTENT. Pages are separate requests, so a run created or deleted
     * between them shifts rows across a page boundary: a created run pushes a row already seen onto
     * the next page (a duplicate fills the count while the new, unfinished run is never seen), and
     * a deleted one pulls an unseen row back onto a page already read. Three checks, all of which
     * must hold or the walk throws:
     *  - runs are keyed by `id`, and every page must report the SAME `total_count`;
     *  - the distinct ids seen must equal that `total_count` (on a single page too, where it catches
     *    a body whose count disagrees with its own rows);
     *  - after a walk of more than one page, page 1 is READ AGAIN and must carry the same
     *    `total_count` and the same set of ids. A run created and another deleted between pages
     *    keep the count and the distinct-id total intact, but GitHub lists runs newest first, so
     *    the created run lands on page 1 and changes its id set. A single-page list is not read
     *    again: one response cannot shift against itself.
     * What is NOT checked: that GitHub lists a new run at the top (taken from its newest-first
     * ordering, not measured), and any change after the re-read — a run created then is a run the
     * read did not see, the same as one created just after it.
     *
     * Each row carries the run's `run_attempt` (null when the body names none), so a caller can
     * tell a delivery for an earlier attempt of a re-run from one for the attempt now listed.
     *
     * @return list<array{id: int, workflow: string, status: string, conclusion: ?string, html_url: string, event: string, run_attempt: ?int}>
     */
    public function workflowRunsForHead(string $repo, string $headSha): array
    {
        $runs = [];
        $total = null;
        $firstPageIds = [];
        for ($page = 1; $page <= self::RUNS_PAGE_LIMIT; $page++) {
            [$pageTotal, $list] = $this->workflowRunsPage($repo, $headSha, $page);
            if ($total !== null && $pageTotal !== $total) {
                throw new UnexpectedValueException("the workflow-run list for {$repo}@{$headSha} changed while it was being read (total_count {$total}, then {$pageTotal} on page {$page}) — a run was created or deleted between pages, so the pages do not add up to one list");
            }
            $total = $pageTotal;
            foreach ($list as $run) {
                $runs[$run['id']] = $run;
            }
            if ($page === 1) {
                $firstPageIds = array_column($list, 'id');
            }

            if (count($list) < self::RUNS_PAGE_SIZE || count($runs) >= $total) {
                if (count($runs) !== $total) {
                    throw new UnexpectedValueException("the workflow-run list for {$repo}@{$headSha} reported total_count {$total} and its pages carried ".count($runs).' distinct run(s) — the pages do not add up to one list, so whether every run is terminal is unknown');
                }
                if ($page > 1) {
                    [$againTotal, $again] = $this->workflowRunsPage($repo, $headSha, 1);
                    $againIds = array_column($again, 'id');
                    sort($againIds);
                    sort($firstPageIds);
                    if ($againTotal !== $total || $againIds !== $firstPageIds) {
                        throw new UnexpectedValueException("the workflow-run list for {$repo}@{$headSha} changed while it was being read (page 1, read again after the last page, no longer lists the same runs) — a run was created or deleted during the walk, so the pages do not add up to one list");
                    }
                }

                return array_values($runs);
            }
        }

        throw new UnexpectedValueException("the workflow-run list for {$repo}@{$headSha} did not end within ".self::RUNS_PAGE_LIMIT.' pages of '.self::RUNS_PAGE_SIZE.' — the list was not read to its end, so whether every run is terminal is unknown');
    }

    /**
     * One page of {@see self::workflowRunsForHead()}'s walk: its `total_count` and its runs.
     *
     * @return array{0: int, 1: list<array{id: int, workflow: string, status: string, conclusion: ?string, html_url: string, event: string, run_attempt: ?int}>}
     */
    private function workflowRunsPage(string $repo, string $headSha, int $page): array
    {
        $body = $this->http()->get(self::API_BASE."/repos/{$repo}/actions/runs", [
            'head_sha' => $headSha,
            'per_page' => self::RUNS_PAGE_SIZE,
            'page' => $page,
            'exclude_pull_requests' => 'true',
        ])->throw()->json();

        $list = is_array($body) ? ($body['workflow_runs'] ?? null) : null;
        if (! is_array($list) || ! array_is_list($list) || ! is_int($body['total_count'] ?? null)) {
            throw new UnexpectedValueException("the workflow-run list for {$repo}@{$headSha} returned a 200 whose body is not a run list with a total_count; ".self::UNREADABLE_BODY_CAUSE);
        }
        $runs = [];
        foreach ($list as $run) {
            if (! is_array($run) || ! is_int($run['id'] ?? null) || ! is_string($run['status'] ?? null)) {
                throw new UnexpectedValueException("the workflow-run list for {$repo}@{$headSha} carries a run with no readable integer `id` or `status`, so whether every run is terminal is unknown; ".self::UNREADABLE_BODY_CAUSE);
            }
            $runs[] = [
                'id' => $run['id'],
                'workflow' => is_string($run['name'] ?? null) ? $run['name'] : '',
                'status' => $run['status'],
                'conclusion' => is_string($run['conclusion'] ?? null) ? $run['conclusion'] : null,
                'html_url' => is_string($run['html_url'] ?? null) ? $run['html_url'] : '',
                'event' => is_string($run['event'] ?? null) ? $run['event'] : '',
                'run_attempt' => is_int($run['run_attempt'] ?? null) ? $run['run_attempt'] : null,
            ];
        }

        return [$body['total_count'], $runs];
    }

    /**
     * The CAUSE clause every unreadable-200 report in this client ends with. It is a const and not
     * a repeated literal because it is the only part of those lines that is the SAME fact —
     * what an unreadable body means and what to look at — while each read's consequence
     * legitimately differs. It is deliberately NOT shared with {@see KanbanClient}'s twin: that
     * one names KANBAN as the shape that may have changed, and the two ends fail for different
     * reasons and are fixed by different people.
     */
    private const UNREADABLE_BODY_CAUSE = "GitHub's response shape may have changed, or something other than GitHub (a proxy, a cache, an auth portal) may be answering this URL";

    /**
     * The degraded-read report for a 200 this client's projections could not read (card#8787).
     *
     * ⛔ IT REPORTS, IT DOES NOT THROW, and that is a decision about the CALLERS, not timidity
     * about the read. `getPull` has two — `KanbanPromoteReleasedHandler::promoteIfReleased()`
     * and `bridge:reconcile`'s per-card recompute — and `compareStatus` has one; a throw here
     * would turn a per-card degradation into an aborted reconcile run (its `catch (Throwable)`
     * arm would swallow it back into a per-card skip anyway) to serve the one caller that
     * already has a per-card skip. The consumer that must SAY something about a specific card
     * owns that half; this line owns the half no consumer can see, which is WHY the projection
     * is empty.
     *
     * @param  array<string, mixed>  $context
     */
    private static function warnUnreadableBody(string $what, array $context): void
    {
        Log::warning("github read: {$what}; ".self::UNREADABLE_BODY_CAUSE, ['catalog_id' => 'github_read_client.body_unreadable', 'handler' => BoardMoverScope::handler(), 'webhook_event_id' => BoardMoverScope::webhookEventId(), 'op' => BoardMoverScope::op()] + $context);
    }

    private function http(): PendingRequest
    {
        return GitHubApi::request($this->token, $this->timeoutSeconds ?? self::TIMEOUT_SECONDS);
    }
}
