<?php

namespace App\Bridge\Writeback;

/**
 * The bridge's GitHub WRITES (DL-390). Kept apart from {@see GitHubReadClient} because that class's
 * contract is that it writes nothing, and a caller holding one should be able to rely on that. A
 * future GitHub write extends this class rather than minting a sibling.
 *
 * Token-agnostic like its sibling: constructed with an already-resolved token
 * ({@see GitHubTokenResolver}). Throws RequestException on any non-2xx, so the caller owns what a
 * refusal means.
 *
 * ⚑ PERMISSIONS (docs.github.com, "Permissions required for fine-grained personal access tokens"):
 * creating an issue or pull-request comment needs Issues OR Pull requests WRITE. Adding labels to an
 * issue or pull request needs the same, per GitHub's REST reference for "Add labels to an issue"
 * (read 2026-09-22). A read-only token gets a 403 here, and nowhere earlier.
 */
final class GitHubWriteClient
{
    public function __construct(private string $token, private int $timeoutSeconds) {}

    /** A comment on issue or pull request $number — GitHub numbers the two in one space. */
    public function createIssueComment(string $repo, int $number, string $body): void
    {
        GitHubApi::request($this->token, $this->timeoutSeconds)
            ->post(GitHubApi::BASE."/repos/{$repo}/issues/{$number}/comments", ['body' => $body])
            ->throw();
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
