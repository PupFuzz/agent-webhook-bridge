<?php

namespace App\Bridge\Writeback;

use App\Bridge\Support\RedactedErrorText;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Adds the `protocol:invalid` label to the issue or pull request a coordination comment was posted on,
 * when `CoordinationClassifier` could not attribute that comment (card#10218 / DL-408). The classifier
 * decides; `GitHubProtocolInvalidLabelHandler` carries the decision here. (Named, not
 * `{@see}`-linked: pint turns a docblock FQCN into a real `use`, and this namespace depends on no
 * handler or classifier.)
 *
 * ⛔ THE LABEL SAYS "COULD NOT ATTRIBUTE", NOTHING MORE. It is applied on exactly one fact the
 * classifier already computes for routing: a created comment whose actor it could not recover — no
 * `scope_author_map` entry, no body `FROM:` line, and no registry name for the sender. No protocol
 * rule is checked here or anywhere in PHP; the coordination framework's own checks stay the arbiter.
 *
 * ⛔ ADD-ONLY. Nothing here removes the label: a later attributed post does not repair the earlier
 * one, and a removal would erase a label a human or the framework's arbiter set for a reason the
 * bridge never saw.
 *
 * ⛔ OPT-IN PER REPO, DEFAULT OFF: `bridge.protocol_invalid_label.repos`
 * (`BRIDGE_PROTOCOL_INVALID_LABEL_REPOS`). The classifier emits no target for a repo not listed, and
 * this class re-checks the list before writing, because a target is classifier-emitted and a custom
 * classifier can emit one for any repo.
 *
 * ⛔ NOTHING HERE THROWS, RETRIES OR ALERTS, and no routing OUTCOME depends on it — not what is
 * staged, not what is pushed, not the status the receiver answers. Routing does WAIT on it: the
 * handler is a `DurableReaction`, and the dispatcher runs durable handlers before best-effort ones,
 * so the agent's wake follows this POST by up to {@see TIMEOUT_SECONDS} (the latency sentence on
 * that constant is where that cost is stated). The label is a report
 * about an event whose routing is already decided, so a failure to write it must not become a 5xx
 * (a redelivery storm) and must not change what the agents receive. Every way it can fail is ONE
 * `Log::warning` whose message starts `protocol_invalid_label: NOT applied` and whose `reason` names
 * the step: `repo_not_enabled`, `payload_invalid`, `token_unresolved`, `add_refused` (an HTTP error
 * answer, any 4xx or 5xx, with `status` — a 403 is a token without Issues or Pull requests WRITE),
 * `add_failed` (the POST did not complete, a transport failure included), `unexpected` (anything
 * outside those steps — worded "NOT applied, or not confirmed", because it can also fire after a
 * POST that landed). Error text goes through {@see RedactedErrorText}.
 *
 * ⛔ ONE POSTING IDENTITY ON EVERY PATH, the {@see PrCorrelationCommenter} rule: the token is the
 * receiver's placed file and nothing else ({@see GitHubTokenResolver::resolveFromFile()}), so a
 * `bridge:replay` from a shell writes as the receiver or not at all.
 */
final class ProtocolInvalidLabeler
{
    /** The durable reaction the classifier emits. */
    public const HANDLER = 'github_protocol_invalid_label';

    /** The label every seat's inbox sweep queries — this exact name, or the sweep does not see it. */
    public const LABEL = 'protocol:invalid';

    /** Per request to GitHub. One attempt is one POST, so it can add at most this to the delivery. */
    public const TIMEOUT_SECONDS = 4;

    /**
     * Every comment this instance has already attempted, WHATEVER came of it — {@see OncePerKey}
     * owns that rule and its lifetime. Here the key is what makes ONE write per EVENT instead of one
     * per agent: the classifier runs once per subscribed agent and each of them emits the same
     * target for the same comment.
     */
    private OncePerKey $attempted;

    public function __construct(private readonly GitHubTokenResolver $tokens = new GitHubTokenResolver)
    {
        $this->attempted = new OncePerKey;
    }

    /** Whether this install writes the label on $repo. Case-insensitive: GitHub's repo names are. */
    public static function enabledFor(string $repo): bool
    {
        $repos = config('bridge.protocol_invalid_label.repos', []);
        if (! is_array($repos)) {
            return false;
        }
        foreach ($repos as $enabled) {
            if (is_string($enabled) && strcasecmp($enabled, $repo) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<mixed>  $payload  `repo`, `number` (the issue or pull request) and `comment_id`
     */
    public function apply(array $payload): void
    {
        try {
            $this->label($payload);
        } catch (Throwable $e) {
            Log::warning('protocol_invalid_label: NOT applied, or not confirmed — an unexpected failure outside the steps below; routing is unchanged', [
                'catalog_id' => 'protocol_invalid_label.unexpected_failure',
                'repo' => $payload['repo'] ?? null, 'number' => $payload['number'] ?? null,
                'reason' => 'unexpected', 'error' => RedactedErrorText::of($e),
            ]);
        }
    }

    /** @param  array<mixed>  $payload */
    private function label(array $payload): void
    {
        $repo = $payload['repo'] ?? null;
        $number = $payload['number'] ?? null;
        if (! is_string($repo) || $repo === '' || ! is_int($number) || $number < 1) {
            Log::warning('protocol_invalid_label: NOT applied — the target does not name a repo and an issue number; routing is unchanged', [
                'catalog_id' => 'protocol_invalid_label.payload_invalid',
                'reason' => 'payload_invalid',
            ]);

            return;
        }
        $context = ['repo' => $repo, 'number' => $number, 'comment_id' => $payload['comment_id'] ?? null];

        if (! self::enabledFor($repo)) {
            Log::warning('protocol_invalid_label: NOT applied — this repo is not in BRIDGE_PROTOCOL_INVALID_LABEL_REPOS; routing is unchanged', ['catalog_id' => 'protocol_invalid_label.repo_not_enabled'] + $context + [
                'reason' => 'repo_not_enabled',
            ]);

            return;
        }

        if (! $this->attempted->claim($repo, $number, is_scalar($context['comment_id']) ? (string) $context['comment_id'] : null)) {
            return;
        }

        $resolution = $this->tokens->resolveFromFile();
        if (! $resolution->ok()) {
            Log::warning('protocol_invalid_label: NOT applied — no GitHub token file resolves (only the receiver\'s token file is used here, never the credential store or GH_TOKEN); routing is unchanged', ['catalog_id' => 'protocol_invalid_label.no_token'] + $context + [
                'reason' => 'token_unresolved', 'problem' => $resolution->problem,
            ]);

            return;
        }

        try {
            (new GitHubWriteClient((string) $resolution->token, self::TIMEOUT_SECONDS))->addLabels($repo, $number, [self::LABEL]);
        } catch (RequestException $e) {
            Log::warning('protocol_invalid_label: NOT applied — GitHub answered the label request with an HTTP error (a 403 is a token without Issues or Pull requests WRITE); not retried, and routing is unchanged', ['catalog_id' => 'protocol_invalid_label.add_http_error'] + $context + [
                'reason' => 'add_refused', 'status' => $e->response->status(), 'error' => RedactedErrorText::of($e),
            ]);

            return;
        } catch (Throwable $e) {
            Log::warning('protocol_invalid_label: NOT applied — the label request could not be sent to GitHub; not retried, and routing is unchanged', ['catalog_id' => 'protocol_invalid_label.add_failed'] + $context + [
                'reason' => 'add_failed', 'error' => RedactedErrorText::of($e),
            ]);

            return;
        }
        Log::info('protocol_invalid_label: applied', ['catalog_id' => 'protocol_invalid_label.applied'] + $context);
    }
}
