<?php

namespace App\Bridge\Writeback;

use App\Bridge\Support\ReceiverUrl;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
 */
final class GitHubReadClient
{
    public const API_BASE = 'https://api.github.com';

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
     * Does this repo carry a webhook whose delivery URL is `$receiverUrl`? (card#9150)
     *
     * ⛔ IT RETURNS A BOOLEAN, AND THAT IS A SECURITY BOUNDARY RATHER THAN A STYLE CHOICE.
     * The hook list is the WHOLE FLEET's: every other install's receiver endpoint is in the
     * response body. Matching INSIDE this method is what makes "no other endpoint can reach
     * an operator log, a finding or a traceback" true by construction — a `list<string>`
     * return would put that guarantee back on every caller's discipline, and the first
     * caller to interpolate its result into a diagnostic would publish the fleet.
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
    public function hasRepoWebhookFor(string $repo, string $receiverUrl): ?bool
    {
        for ($page = 1; $page <= self::HOOK_PAGE_LIMIT; $page++) {
            $body = $this->http()->get(self::API_BASE."/repos/{$repo}/hooks", [
                'per_page' => self::HOOK_PAGE_SIZE,
                'page' => $page,
            ])->throw()->json();

            if (! is_array($body) || ! array_is_list($body)) {
                self::warnUnreadableBody(
                    "the webhook-list read for {$repo} returned a 200 whose body is not a JSON list of hooks — whether a hook points at this install is UNKNOWN, not false, and a consumer that reads it as \"no such hook\" would convict a healthy install",
                    ['repo' => $repo, 'read' => 'list-hooks', 'page' => $page],
                );

                return null;
            }

            foreach ($body as $hook) {
                $config = is_array($hook) ? ($hook['config'] ?? null) : null;
                $url = is_array($config) ? ($config['url'] ?? null) : null;
                // `deliversTo`, NOT `matchesExactly` (card#9150 r1): a hook spelled
                // `?b=owner%2Frepo` delivers here exactly as `?b=owner/repo` does, and this
                // method's negative answer becomes a `fail` that moves an exit code.
                // `ReceiverUrl` owns why the two predicates differ and why provision keeps
                // the exact one.
                if (ReceiverUrl::deliversTo(is_string($url) ? $url : null, $receiverUrl)) {
                    return true;
                }
            }

            // A SHORT PAGE IS THE END OF THE LIST, which is what makes `false` an
            // EXHAUSTED enumeration rather than "not on page 1" — the distinction the
            // `fail` severity below this rests on.
            if (count($body) < self::HOOK_PAGE_SIZE) {
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
     * @return array{state: string, merged: ?bool, base_ref: string, html_url: string, merge_commit_sha: string, title: string, head_ref: string}
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
            'title' => is_string($pr['title'] ?? null) ? $pr['title'] : '',
            'head_ref' => is_string($head) ? $head : '',
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
     * The CAUSE clause both unreadable-200 lines in this client end with. It is a const and not
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
        Log::warning("github read: {$what}; ".self::UNREADABLE_BODY_CAUSE, $context);
    }

    private function http(): PendingRequest
    {
        return Http::withToken($this->token)
            ->withHeaders([
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
                'User-Agent' => 'agent-webhook-bridge',   // GitHub rejects a UA-less request
            ])
            ->timeout($this->timeoutSeconds ?? self::TIMEOUT_SECONDS);
    }
}
