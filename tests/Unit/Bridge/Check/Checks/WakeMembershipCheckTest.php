<?php

namespace Tests\Unit\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Checks\WakeMembershipCheck;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use Tests\TestCase;

/**
 * The DL-213 `comment_to` advisory (DL-242 stage 5a).
 *
 * The golden fixture `agent-ci-failure-patterns-and-wake-membership` pins the firing
 * case. The three gates that keep it quiet, and the `catch` on the lazy key, are
 * measured only here — and the gates are the substance of the leg: the warn exists
 * PRECISELY to reach installs a default flip cannot, so a check that fired on the
 * absent-key population would be scolding the population the flip already fixed.
 *
 * (Golden fixtures are NAMED, never `{@see}`-linked: pint's docblock fixer turns a
 * fully-qualified `{@see}` into a real `use`.)
 */
class WakeMembershipCheckTest extends TestCase
{
    public function test_it_warns_when_an_explicit_membership_omits_comment_to(): void
    {
        $findings = $this->findingsFor(['wake_membership' => ['to_me']]);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Warn, $findings[0]->severity);
        $this->assertStringContainsString(
            'agent prod-agent: classifier.config.wake_membership = [to_me] is set explicitly and omits comment_to',
            $findings[0]->message,
        );
    }

    /**
     * The absent-key population — the one the DL-213 default flip DOES reach. Warning
     * here would be the inversion of the leg's purpose, so this is a control, not a
     * completeness box.
     */
    public function test_it_is_silent_when_membership_is_not_set_explicitly(): void
    {
        $this->assertSame([], $this->findingsFor([]));
    }

    public function test_it_is_silent_when_an_explicit_membership_already_includes_comment_to(): void
    {
        $this->assertSame([], $this->findingsFor(['wake_membership' => ['to_me', 'comment_to']]));
    }

    /**
     * The family gate. `wake_membership` only governs the coord-message family, so an
     * agent that does not run it is unaffected however narrow its list is. An empty
     * families list means the classifier's own default applies, which includes
     * coord-message — hence the gate is `empty OR contains`, not `contains`.
     */
    public function test_it_is_silent_when_the_coord_message_family_is_off(): void
    {
        $this->assertSame([], $this->findingsFor([
            'families' => ['impl-ci-wake'],
            'wake_membership' => ['to_me'],
        ]));
    }

    /**
     * The lazy-key catch, the same shape as the CI-filter leg's: without it a malformed
     * value would abort the whole run rather than fail the agent that carries it.
     */
    public function test_a_malformed_membership_fails_that_agent_rather_than_aborting_the_run(): void
    {
        $findings = $this->findingsFor(['wake_membership' => 'not-a-list']);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Fail, $findings[0]->severity);
        $this->assertStringStartsWith(
            'agent prod-agent: classifier.config.wake_membership — ',
            $findings[0]->message,
        );
        $this->assertStringContainsString('must be a list of strings', $findings[0]->message);
    }

    /**
     * @param  array<mixed>  $classifierConfig
     * @return list<Finding>
     */
    private function findingsFor(array $classifierConfig): array
    {
        $config = AgentConfig::fromArray('prod-agent', [
            'identity' => ['kanban_user_id' => 1],
            'subscriptions' => [],
            'classifier' => ['config' => $classifierConfig],
        ]);

        return iterator_to_array(
            (new WakeMembershipCheck)->runFor($config, new CheckContext),
            false,
        );
    }
}
