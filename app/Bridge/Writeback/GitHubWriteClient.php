<?php

namespace App\Bridge\Writeback;

use Illuminate\Http\Client\Response;

/**
 * The bridge's GitHub WRITES (DL-390). Kept apart from {@see GitHubReadClient} because that class's
 * contract is that it writes nothing, and a caller holding one should be able to rely on that. A
 * future GitHub write extends this class rather than minting a sibling.
 *
 * Token-agnostic like its sibling: constructed with an already-resolved token
 * ({@see GitHubTokenResolver}). Each write throws RequestException on any non-2xx, so the caller owns
 * what a refusal means; {@see probeCommentWrite()}, which writes nothing, returns its answer instead.
 *
 * ⚑ PERMISSIONS (docs.github.com, "Permissions required for fine-grained personal access tokens"):
 * creating an issue or pull-request comment needs Issues OR Pull requests WRITE. Adding labels to an
 * issue or pull request needs the same, per GitHub's REST reference for "Add labels to an issue"
 * (read 2026-09-22). A read-only token gets a 403 here, and nowhere earlier.
 */
final class GitHubWriteClient
{
    /**
     * The issue number {@see probeCommentWrite()} posts to: the largest a signed 32-bit id holds, a
     * number no repo reaches, so the probe has no thread to land on.
     */
    public const WRITE_PROBE_ISSUE = 2147483647;

    public function __construct(private string $token, private int $timeoutSeconds) {}

    /**
     * Can this token create a comment on `$repo`, asked WITHOUT writing anything (card#12968)? A
     * well-formed comment POST to {@see WRITE_PROBE_ISSUE}, which does not exist. Returns GitHub's
     * answer, unclassified — the caller judges it: a 403 is refused at the permission check, before
     * the issue is looked up; a 404 got past it, but a 404 is also what a token that cannot see the
     * repo at all gets, and what a CLASSIC token missing a scope gets (GitHub Docs, *Troubleshooting
     * the REST API*), so it means "can write" only where the same token has just read the repo and is
     * not a classic token short of the scope.
     * The body is well-formed on purpose: an empty one would add a second unknown (whether GitHub
     * validates the body before it checks the permission). Throws only when no answer came back.
     */
    public function probeCommentWrite(string $repo): Response
    {
        return GitHubApi::request($this->token, $this->timeoutSeconds)
            ->post(GitHubApi::BASE."/repos/{$repo}/issues/".self::WRITE_PROBE_ISSUE.'/comments', ['body' => 'agent-webhook-bridge write-access probe (bridge:check); addressed to an issue that does not exist']);
    }

    /**
     * A comment on issue or pull request $number — GitHub numbers the two in one space.
     *
     * ⭐ IT RETURNS THE BODY GITHUB SAYS IT STORED, for {@see addLabels()}'s reason: a 2xx is the
     * server's CLAIM. `POST .../comments` answers `201` with the created comment (GitHub's REST
     * reference, *Create an issue comment*: "Same response schema as Get an issue comment"), whose
     * `body` is the raw markdown under the default media type, so the caller confirms its own write
     * out of the same response. Null when the answer carries no readable `body` — unconfirmed, never
     * guessed confirmed.
     */
    public function createIssueComment(string $repo, int $number, string $body): ?string
    {
        $answer = GitHubApi::request($this->token, $this->timeoutSeconds)
            ->post(GitHubApi::BASE."/repos/{$repo}/issues/{$number}/comments", ['body' => $body])
            ->throw()
            ->json();

        return is_array($answer) && is_string($answer['body'] ?? null) ? $answer['body'] : null;
    }

    /**
     * Adds $labels to issue or pull request $number. ADDITIVE: GitHub adds them "to the issue's
     * existing labels" (its REST reference); replacing the set is a different endpoint. Which also
     * makes it IDEMPOTENT — adding a label a thread already carries writes nothing new — so a
     * caller may safely re-attempt one it is unsure landed.
     *
     * ⭐ IT RETURNS WHAT GITHUB SAYS IS ON THE THREAD NOW, because a 2xx is the server's CLAIM and
     * not the outcome. This endpoint answers with the resulting label set, so a caller can CONFIRM
     * its own write out of the same response rather than inferring it from the status — for free,
     * with no extra request. A name this cannot read is omitted rather than guessed, so an
     * unreadable body reads as "not confirmed" and never as "confirmed".
     *
     * @param  list<string>  $labels
     * @return list<string> the label names GitHub answered with
     */
    public function addLabels(string $repo, int $number, array $labels): array
    {
        $response = GitHubApi::request($this->token, $this->timeoutSeconds)
            ->post(GitHubApi::BASE."/repos/{$repo}/issues/{$number}/labels", ['labels' => $labels])
            ->throw();

        $names = [];
        foreach (is_array($body = $response->json()) ? $body : [] as $label) {
            if (is_array($label) && is_string($label['name'] ?? null)) {
                $names[] = $label['name'];
            }
        }

        return $names;
    }
}
