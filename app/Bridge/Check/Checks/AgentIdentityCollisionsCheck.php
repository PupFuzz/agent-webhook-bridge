<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\Support\Finding;

/**
 * Surface the agent-roster id collisions found while building the registry, migrated
 * out of `CheckCommand::handle()` (DL-242 stage 5c).
 *
 * A collision is two agent YAMLs claiming the same kanban user id / github user id /
 * github login. The bridge does not fail on it — it BYPASSES attribution for that
 * identity, so `Actor.name` comes back null and the raw id surfaces downstream. That is
 * a degradation an operator can only see if something says so, which is why it is a
 * preflight warn rather than a runtime error.
 *
 * IT READS THE REGISTRY OFF THE CONTEXT AND NEVER BUILDS ONE. `AgentRegistry` finds and
 * logs collisions AT CONSTRUCTION — `collisions()` only returns what the build already
 * accumulated — so a check that built its own instance would re-log every collision on a
 * colliding install. Two checks read this registry; one build is the only shape that does
 * not double-log. The build stays in `handle()` as derivation (plan constraint (c)).
 *
 * THE MESSAGE IS `AgentRegistry`'s, NOT THIS CLASS'S. It composes the collision text from
 * the axis, the value and the sharing agents; duplicating that here would be a second
 * copy to keep in step with the code that decides.
 *
 * NO GOLDEN FIXTURE REACHES A COLLISION — no install in the fixture set has two agents
 * sharing an identity, so the golden harness cannot tell this walk's two branches apart
 * and a green run is not evidence for it. THE COMMAND-LEVEL SUITE DOES REACH IT: mutating
 * the walk reds `BridgeCommandsTest::test_check_surfaces_an_id_collision_on_the_console`,
 * which writes two agents sharing a `kanban_user_id` and asserts the console text at exit
 * 0. `AgentIdentityCollisionsCheckTest` is what pins one finding per colliding axis.
 * (Named, never `{@see}`-linked: pint would turn the FQCN into a real `use`.)
 */
final class AgentIdentityCollisionsCheck implements Check
{
    public function id(): string
    {
        return 'agent.identity_collisions';
    }

    /**
     * @return iterable<Finding>
     */
    public function run(CheckContext $ctx): iterable
    {
        if ($ctx->registry === null) {
            return;
        }

        foreach ($ctx->registry->collisions() as $warning) {
            yield Finding::warn($warning);
        }
    }
}
