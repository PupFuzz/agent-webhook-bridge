<?php

namespace Tests\Unit\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Checks\AgentCoordinationIdentityCheck;
use App\Bridge\Classifiers\CoordinationClassifier;
use App\Bridge\Classifiers\InboxOnlyClassifier;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\AgentRegistry;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use App\Bridge\Support\SharedIdentity;
use Tests\Support\MaterializesChecks;
use Tests\TestCase;

/**
 * The `agent.coordination_identity` leg (card#9152 / DL-373).
 *
 * NO GOLDEN FIXTURE PAIRS THE TWO — no install shape in the golden set runs
 * `CoordinationClassifier` with an inline `identity.github_user_id` — so the golden
 * harness cannot tell this check's arms apart and a green run there is not evidence for
 * it. Every arm below is therefore this file's, including the discrimination between the
 * LIVE arm and the arm a `shared-identities.json` entry neutralizes, which is the whole
 * reason the check reads the registry instead of the config alone.
 *
 * (Golden fixtures and `bridge:check` are NAMED, never `{@see}`-linked: pint's docblock
 * fixer turns a fully-qualified `{@see}` into a real `use`.)
 */
class AgentCoordinationIdentityCheckTest extends TestCase
{
    use MaterializesChecks;

    /**
     * The defect this leg exists for: the roundtable's shared account claimed inline by
     * one participant. Nothing else on the install reports it — the webhook verifies, the
     * dispatch row is written, and the command still exits 0.
     */
    public function test_an_inline_github_user_id_on_a_coordination_agent_is_reported(): void
    {
        $findings = $this->findingsFor([$this->coordAgent('me', 269788076)]);

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Warn, $findings[0]->severity);
        $this->assertStringContainsString('agent me: identity.github_user_id = 269788076', $findings[0]->message);
        $this->assertStringContainsString('no shared-identities.json entry declares that account shared', $findings[0]->message);
        $this->assertStringContainsString('THIS SEAT IS DEAF', $findings[0]->message);
    }

    /**
     * The state the card's "that combination is ALWAYS wrong" does not cover, and the
     * reason this check reads the registry: `AgentRegistry` EXCLUDES an id declared shared
     * from its per-agent lookup, so the inline key resolves nobody and attribution is
     * correct today. Reporting it with the LIVE arm's sentence would be a false claim
     * about a working install; staying silent would leave a duplicate declaration that
     * becomes the defect the moment the shared entry is edited.
     */
    public function test_the_same_key_is_reported_differently_when_the_account_is_declared_shared(): void
    {
        $findings = $this->findingsFor(
            [$this->coordAgent('me', 269788076)],
            [new SharedIdentity(269788076, 'roundtable-bot', ['me', 'peer'])],
        );

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Warn, $findings[0]->severity);
        $this->assertStringContainsString('The shared declaration WINS', $findings[0]->message);
        $this->assertStringNotContainsString('THIS SEAT IS DEAF', $findings[0]->message);
    }

    /** The correct coordination config — no per-agent github id at all. */
    public function test_a_coordination_agent_with_no_github_identity_is_silent(): void
    {
        $this->assertSame([], $this->findingsFor([$this->coordAgent('me', null)]));
    }

    /**
     * The key is only a defect UNDER a classifier that re-attributes after classify. On
     * every other classifier a per-agent github id is the ordinary single-account model
     * (DL-002 Path A), so this leg must not touch it.
     */
    public function test_an_inline_github_user_id_under_another_classifier_is_silent(): void
    {
        $this->assertSame([], $this->findingsFor([
            AgentConfig::fromArray('other', [
                'identity' => ['github_user_id' => 269788076],
                'classifier' => ['class' => InboxOnlyClassifier::class],
            ]),
        ]));
    }

    /** One agent's defect must not be reported against its innocent neighbours. */
    public function test_only_the_offending_agent_is_named_on_a_mixed_roster(): void
    {
        $findings = $this->findingsFor([
            $this->coordAgent('clean', null),
            $this->coordAgent('broken', 269788076),
        ]);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('agent broken:', $findings[0]->message);
    }

    /**
     * A context the derivation never populated. `CheckCommand` builds the registry only
     * when at least one agent config parsed, so a null registry and an empty roster arrive
     * together — there is nothing to report, and the check must say so rather than
     * dereference it.
     */
    public function test_it_is_silent_when_no_registry_was_built(): void
    {
        $this->assertSame([], $this->findingsOf(new AgentCoordinationIdentityCheck, new CheckContext));
    }

    private function coordAgent(string $name, ?int $githubUserId): AgentConfig
    {
        return AgentConfig::fromArray($name, [
            'identity' => $githubUserId === null ? [] : ['github_user_id' => $githubUserId],
            'classifier' => ['class' => CoordinationClassifier::class],
        ]);
    }

    /**
     * The context PRODUCTION hands this check: `CheckCommand` publishes the parsed configs
     * and ONE registry built from them plus the shared-identity declaration, after the
     * per-agent loop. Building the registry any other way here would test a composition
     * the command never performs.
     *
     * @param  list<AgentConfig>  $configs
     * @param  list<SharedIdentity>  $shared
     * @return list<Finding>
     */
    private function findingsFor(array $configs, array $shared = []): array
    {
        $ctx = new CheckContext;
        $ctx->configs = $configs;
        $ctx->registry = AgentRegistry::fromAgentConfigs($configs, $shared);

        return $this->findingsOf(new AgentCoordinationIdentityCheck, $ctx);
    }
}
