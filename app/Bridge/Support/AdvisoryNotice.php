<?php

namespace App\Bridge\Support;

/**
 * A dependency-advisory notice: a GitHub issue the framework's `Dependency advisory notices`
 * workflow opens per (advisory, package) on an adopter repo, carrying the advisory label
 * ({@see ClassifierConfig::ADVISORY_LABEL_DEFAULT}) and no addressing (DL-506).
 *
 * The one predicate both readers ask: `CoordinationClassifier` builds the `impl_advisory` intent
 * from it, and `DispatchService` lets that intent past the `treat_as_signal` gate with it — the
 * notice is opened by `github-actions[bot]`, which no allowlist names. Two copies of the
 * predicate would let the gate and the classifier disagree about which event is a notice.
 */
final class AdvisoryNotice
{
    public const KIND = 'impl_advisory';

    /**
     * The `issues` actions that announce a notice: the producer opening it, a closed notice
     * reopened, and the label added to an issue already open. Not `edited` (the producer
     * re-states affected refs in a comment, never by editing), not `closed` (the advisory is no
     * longer reported, nothing to act on), and not comments (follow-ups on a notice the seat was
     * already woken for).
     */
    public const ACTIONS = ['opened', 'reopened', 'labeled'];

    /** The dropped reason when the other delivery of the same notice already woke this agent. */
    public const ALREADY_CARRIED = 'impl_advisory: this notice was already carried to this agent by the other delivery of its opening (issues.opened / issues.labeled)';

    private function __construct(
        public readonly string $subjectId,
        public readonly string $action,
        public readonly int $number,
        public readonly string $title,
        public readonly string $url,
    ) {}

    /**
     * The notice this event announces to `$agent`, or null when it is not one — not an `issues`
     * event of {@see ACTIONS}, not sent by one of the agent's `advisory_actors`, not carrying its
     * advisory label, or the agent opted out.
     * The subject id is `<repo>#<number>`, unique across the repos one agent serves.
     *
     * @param  array<mixed>  $payload
     */
    public static function of(AgentConfig $agent, string $provider, string $scopeId, string $eventType, array $payload): ?self
    {
        $cfg = $agent->classifierConfig;
        if (! $cfg->advisoryWake || $provider !== 'github' || ! str_starts_with($eventType, 'issues.')) {
            return null;
        }
        $action = substr($eventType, strlen('issues.'));
        if (! in_array($action, self::ACTIONS, true)) {
            return null;
        }
        // Bound to the producer: anyone else who can label or reopen an issue would otherwise pass
        // the `treat_as_signal` gate and re-wake every seat at will.
        if (! GithubAccountList::matches($cfg->advisoryActors, is_array($payload['sender'] ?? null) ? $payload['sender'] : [])) {
            return null;
        }

        $issue = is_array($payload['issue'] ?? null) ? $payload['issue'] : [];
        $number = $issue['number'] ?? null;
        if (! is_int($number)) {
            return null;
        }

        $labelled = $action === 'labeled'
            ? self::labelName($payload['label'] ?? null) === $cfg->advisoryLabel
            : in_array($cfg->advisoryLabel, array_map(self::labelName(...), is_array($issue['labels'] ?? null) ? $issue['labels'] : []), true);
        if (! $labelled) {
            return null;
        }

        return new self(
            subjectId: $scopeId.'#'.$number,
            action: $action,
            number: $number,
            title: is_string($issue['title'] ?? null) ? $issue['title'] : '',
            url: is_string($issue['html_url'] ?? null) ? $issue['html_url'] : '',
        );
    }

    /** @return list<string> the qualified event types a classifier declares for the notice */
    public static function eventTypes(ClassifierConfig $cfg): array
    {
        return $cfg->advisoryWake ? array_map(static fn (string $a): string => 'issues.'.$a, self::ACTIONS) : [];
    }

    private static function labelName(mixed $label): ?string
    {
        return is_array($label) && is_string($label['name'] ?? null) ? strtolower($label['name']) : null;
    }
}
