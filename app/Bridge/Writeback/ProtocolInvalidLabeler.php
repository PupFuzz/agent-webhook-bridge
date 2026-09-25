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
 * ⭐ BUT A DECIDED WRITE THAT DID NOT LAND IS REMEMBERED (card#10242 / DL-419). Add-only is about
 * what the label MEANS; it was never a reason to forget a write the install decided on and GitHub
 * refused. {@see ProtocolInvalidLabelDebt} records the ones whose cause can still clear — a token
 * without Issues write is the realistic one — and `bridge:relabel` finishes them once it has.
 * Nothing re-attempts on its own: the retry is an operator act, so a permanent refusal can never
 * become an unbounded retry, and the record's own expiry bounds how stale a repair may be.
 *
 * ⛔ OPT-IN PER REPO, DEFAULT OFF: `bridge.protocol_invalid_label.repos`
 * (`BRIDGE_PROTOCOL_INVALID_LABEL_REPOS`). The classifier emits no target for a repo not listed, and
 * this class re-checks the list before writing, because a target is classifier-emitted and a custom
 * classifier can emit one for any repo. The repair re-asks the same question at repair time, so a
 * repo the operator has since removed is forgotten rather than written.
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
 * `add_failed` (the POST did not complete, a transport failure included), `add_unconfirmed` (the
 * POST was ACCEPTED and GitHub's answer did not list the label), `unexpected` (anything
 * outside those steps — worded "NOT applied, or not confirmed", because it can also fire after a
 * POST that landed). Error text goes through {@see RedactedErrorText}.
 *
 * ⭐ A 2xx IS THE SERVER'S CLAIM, NOT THE OUTCOME. `POST .../labels` answers with the label set the
 * thread now carries, so the write CONFIRMS itself out of that same answer — free, no second
 * request — and a 2xx whose body does not carry the label is `add_unconfirmed` rather than
 * `applied`. The cost, accepted: a body this cannot read reads as unconfirmed, which owes one
 * idempotent re-attempt and never a false success.
 *
 * ⛔ ONE POSTING IDENTITY ON EVERY PATH, the {@see PrCorrelationCommenter} rule: the token is the
 * receiver's placed file and nothing else ({@see GitHubTokenResolver::resolveFromFile()}), so a
 * `bridge:replay` or a `bridge:relabel` from a shell writes as the receiver or not at all.
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
     * The arms. Every value below `APPLIED` is a `reason` on this class's warnings AND the census
     * {@see ProtocolInvalidLabelDebt::retriable()} rules over — one vocabulary, so an arm cannot be
     * added to the log without the repair rule being asked about it.
     */
    public const APPLIED = 'applied';

    public const REASON_PAYLOAD_INVALID = 'payload_invalid';

    public const REASON_REPO_NOT_ENABLED = 'repo_not_enabled';

    public const REASON_TOKEN_UNRESOLVED = 'token_unresolved';

    public const REASON_ADD_REFUSED = 'add_refused';

    public const REASON_ADD_FAILED = 'add_failed';

    public const REASON_ADD_UNCONFIRMED = 'add_unconfirmed';

    public const REASON_UNEXPECTED = 'unexpected';

    /** Claimed but not attempted: this instance already tried this thread — {@see $attempted}. */
    public const DEDUPED = 'deduped';

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
    public function apply(array $payload): ProtocolInvalidLabelAttempt
    {
        try {
            return $this->label($payload);
        } catch (Throwable $e) {
            Log::warning('protocol_invalid_label: NOT applied, or not confirmed — an unexpected failure outside the steps below; routing is unchanged', [
                'catalog_id' => 'protocol_invalid_label.unexpected_failure',
                'repo' => $payload['repo'] ?? null, 'number' => $payload['number'] ?? null,
                'reason' => self::REASON_UNEXPECTED, 'error' => RedactedErrorText::of($e),
            ]);

            // ⛔ OWED EVEN THOUGH THE POST MAY HAVE LANDED — this arm can fire after a successful
            // write (a log sink that throws on the success line is the shipped case). The
            // re-attempt is an idempotent add that confirms itself, so a debt recorded here for a
            // write that DID land costs one POST and then clears; the other direction costs a
            // thread the arbiter never sweeps.
            $this->owe($payload, self::REASON_UNEXPECTED, null);

            return new ProtocolInvalidLabelAttempt(self::REASON_UNEXPECTED);
        }
    }

    /** @param  array<mixed>  $payload */
    private function label(array $payload): ProtocolInvalidLabelAttempt
    {
        $repo = $payload['repo'] ?? null;
        $number = $payload['number'] ?? null;
        if (! is_string($repo) || $repo === '' || ! is_int($number) || $number < 1) {
            Log::warning('protocol_invalid_label: NOT applied — the target does not name a repo and an issue number; routing is unchanged', [
                'catalog_id' => 'protocol_invalid_label.payload_invalid',
                'reason' => self::REASON_PAYLOAD_INVALID,
            ]);

            return new ProtocolInvalidLabelAttempt(self::REASON_PAYLOAD_INVALID);
        }
        $context = ['repo' => $repo, 'number' => $number, 'comment_id' => $payload['comment_id'] ?? null];

        if (! self::enabledFor($repo)) {
            Log::warning('protocol_invalid_label: NOT applied — this repo is not in BRIDGE_PROTOCOL_INVALID_LABEL_REPOS; routing is unchanged', ['catalog_id' => 'protocol_invalid_label.repo_not_enabled'] + $context + [
                'reason' => self::REASON_REPO_NOT_ENABLED,
            ]);

            return new ProtocolInvalidLabelAttempt(self::REASON_REPO_NOT_ENABLED);
        }

        if (! $this->attempted->claim($repo, $number, is_scalar($context['comment_id']) ? (string) $context['comment_id'] : null)) {
            return new ProtocolInvalidLabelAttempt(self::DEDUPED);
        }

        $resolution = $this->tokens->resolveFromFile();
        if (! $resolution->ok()) {
            Log::warning('protocol_invalid_label: NOT applied — no GitHub token file resolves (only the receiver\'s token file is used here, never the credential store or GH_TOKEN); routing is unchanged', ['catalog_id' => 'protocol_invalid_label.no_token'] + $context + [
                'reason' => self::REASON_TOKEN_UNRESOLVED, 'problem' => $resolution->problem,
            ]);

            return $this->failure($payload, self::REASON_TOKEN_UNRESOLVED, null);
        }

        try {
            $labels = (new GitHubWriteClient((string) $resolution->token, self::TIMEOUT_SECONDS))->addLabels($repo, $number, [self::LABEL]);
        } catch (RequestException $e) {
            Log::warning('protocol_invalid_label: NOT applied — GitHub answered the label request with an HTTP error (a 403 is a token without Issues or Pull requests WRITE); not retried in this run, and routing is unchanged', ['catalog_id' => 'protocol_invalid_label.add_http_error'] + $context + [
                'reason' => self::REASON_ADD_REFUSED, 'status' => $e->response->status(), 'error' => RedactedErrorText::of($e),
            ]);

            return $this->failure($payload, self::REASON_ADD_REFUSED, $e->response->status());
        } catch (Throwable $e) {
            Log::warning('protocol_invalid_label: NOT applied — the label request could not be sent to GitHub; not retried in this run, and routing is unchanged', ['catalog_id' => 'protocol_invalid_label.add_failed'] + $context + [
                'reason' => self::REASON_ADD_FAILED, 'error' => RedactedErrorText::of($e),
            ]);

            return $this->failure($payload, self::REASON_ADD_FAILED, null);
        }

        // Case-insensitive: a repo already carrying the label under another spelling can answer
        // with THAT spelling, and an exact compare would then keep a landed write owed until expiry.
        if (! in_array(strtolower(self::LABEL), array_map(strtolower(...), $labels), true)) {
            Log::warning('protocol_invalid_label: NOT applied — GitHub ACCEPTED the label request and its answer does not carry the label, so the write is not confirmed; routing is unchanged', ['catalog_id' => 'protocol_invalid_label.add_unconfirmed'] + $context + [
                'reason' => self::REASON_ADD_UNCONFIRMED, 'labels_answered' => count($labels),
            ]);

            return $this->failure($payload, self::REASON_ADD_UNCONFIRMED, null);
        }

        ProtocolInvalidLabelDebt::forget($repo, $number);
        Log::info('protocol_invalid_label: applied', ['catalog_id' => 'protocol_invalid_label.applied'] + $context);

        return new ProtocolInvalidLabelAttempt(self::APPLIED);
    }

    /**
     * Record this failure against the thread, and say which arm it was.
     *
     * @param  array<mixed>  $payload
     */
    private function failure(array $payload, string $reason, ?int $status): ProtocolInvalidLabelAttempt
    {
        $this->owe($payload, $reason, $status);

        return new ProtocolInvalidLabelAttempt($reason, $status);
    }

    /**
     * ⛔ THE ENABLED-LIST IS RE-ASKED HERE, not assumed from the caller's arm. `apply()`'s catch-all
     * reaches this having passed through no gate at all, so without it an unexpected failure on a
     * repo this install does not write would queue that repo's write for repair.
     *
     * @param  array<mixed>  $payload
     */
    private function owe(array $payload, string $reason, ?int $status): void
    {
        $repo = $payload['repo'] ?? null;
        $number = $payload['number'] ?? null;
        if (! is_string($repo) || $repo === '' || ! is_int($number) || $number < 1 || ! self::enabledFor($repo)) {
            return;
        }
        $commentId = $payload['comment_id'] ?? null;

        ProtocolInvalidLabelDebt::settle($repo, $number, is_scalar($commentId) ? (string) $commentId : null, $reason, $status);
    }
}
