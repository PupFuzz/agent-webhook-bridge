<?php

namespace Tests\Support;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A stateful stand-in for GitHub's issue-comments endpoints (and the add-labels one, for a run that
 * finishes both kinds of owed write): a POSTed comment is returned by
 * every later GET of the same pull request, so a dedupe that reads comments back is exercised
 * against what was actually written rather than against a canned answer.
 */
final class GitHubIssueCommentsStub
{
    /** @var list<array{method: string, url: string}> */
    public array $requests = [];

    /** @var array<int, list<string>> PR number → comment bodies stored */
    private array $stored = [];

    /** @var array<int, true> PR numbers whose comment list also carries an entry with no `body` */
    private array $unreadable = [];

    /** @var array<int, list<string>> issue or PR number → labels added */
    private array $labels = [];

    /** @var array<int, list<string>> PR number → comment bodies POSTed (attempted, stored or not) */
    private array $attempted = [];

    /**
     * Public and mutable so a test can CLEAR the cause mid-run — the operator granting the token
     * write — and watch what the bridge does with the write it owed.
     */
    public function __construct(
        public int $postStatus = 201,
        public bool $postFailsInTransport = false,
        public int $listStatus = 200,
        /** What a 2xx POST answers with, in place of the created comment — a body that does not carry it */
        public ?array $postAnswer = null,
    ) {}

    /** @return list<string> the bodies POSTed to this pull request, stored or not */
    public function posts(int $number): array
    {
        return $this->attempted[$number] ?? [];
    }

    /** @return list<string> the bodies ON the pull request now — what a reader of the page sees */
    public function stored(int $number): array
    {
        return $this->stored[$number] ?? [];
    }

    /** @return list<string> the labels added to this issue or pull request */
    public function labels(int $number): array
    {
        return $this->labels[$number] ?? [];
    }

    /** A comment somebody else already left on the pull request. */
    public function seed(int $number, string $body): void
    {
        $this->stored[$number][] = $body;
    }

    /** A comment entry the list answers without a `body`, so the list cannot be read to the end. */
    public function seedUnreadable(int $number): void
    {
        $this->unreadable[$number] = true;
    }

    public function answer(Request $request): PromiseInterface
    {
        $this->requests[] = ['method' => $request->method(), 'url' => $request->url()];
        if ($request->method() === 'POST' && preg_match('#^https://api\.github\.com/repos/[^/]+/[^/]+/issues/(\d+)/labels$#', $request->url(), $m) === 1) {
            // Answered as GitHub's *Add labels to an issue* does: the label set the thread now carries.
            $labels = $request->data()['labels'] ?? [];
            foreach (is_array($labels) ? $labels : [] as $label) {
                $this->labels[(int) $m[1]][] = (string) $label;
            }

            return Http::response(array_map(static fn (string $name): array => ['name' => $name], $this->labels[(int) $m[1]] ?? []));
        }
        if (preg_match('#^https://api\.github\.com/repos/[^/]+/[^/]+/issues/(\d+)/comments(\?.*)?$#', $request->url(), $m) !== 1) {
            return Http::response(['message' => 'not stubbed: '.$request->url()], 599);
        }
        $number = (int) $m[1];

        if ($request->method() === 'GET') {
            if ($this->listStatus !== 200) {
                return Http::response(['message' => 'Resource not accessible by personal access token'], $this->listStatus);
            }

            return Http::response(array_merge(
                array_map(static fn (string $body): array => ['body' => $body], $this->stored[$number] ?? []),
                isset($this->unreadable[$number]) ? [['id' => 1]] : [],
            ));
        }

        $body = $request->data()['body'] ?? null;
        $this->attempted[$number][] = is_string($body) ? $body : '';
        if ($this->postFailsInTransport) {
            return Http::failedConnection()($request);
        }
        if ($this->postStatus >= 200 && $this->postStatus < 300 && is_string($body)) {
            $this->stored[$number][] = $body;

            return Http::response($this->postAnswer ?? ['id' => count($this->stored[$number]), 'body' => $body], $this->postStatus);
        }

        return Http::response(['message' => 'Resource not accessible by personal access token'], $this->postStatus);
    }
}
