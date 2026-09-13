<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Silence;
use App\Bridge\Classifiers\CoordinationClassifier;
use App\Bridge\Support\Finding;

/**
 * Report an agent that runs `CoordinationClassifier` AND declares an inline
 * `identity.github_user_id` (card#9152 / DL-373).
 *
 * WHAT GOES WRONG WHEN IT IS THE SHARED ACCOUNT. `AgentRegistry` maps a github
 * `sender.id` to an agent name through exactly that key, so registering the account a
 * whole roundtable posts under to ONE agent resolves EVERY participant's post to that
 * agent. `DispatchService`'s pre-classify echo gate then drops the dispatch as
 * `echo: own write` — and the `FROM:`-line re-attribution that makes suppression
 * per-agent on a shared account never runs, because it short-circuits on a named actor
 * (`Actor::$name` non-null means the registry already decided). The seat's live-wake is
 * structurally dead while every other signal — the webhook, the socket, the exit code —
 * says the install is healthy. The same key breaks an impl-wake seat one family over:
 * `CoordinationClassifier::makeImplIntent()`'s docblock already states the invariant.
 *
 * ⛔ IT IS A `warn` AND IT DISCLOSES WHAT IT CANNOT TELL APART, BECAUSE THE COMBINATION
 * IS NOT ALWAYS WRONG — that is a measured claim here, not a hedge. Two install states
 * produce this exact config and NO local read separates them: the id may be the account
 * several agents post under (the defect above), or it may genuinely be this agent's own,
 * with each agent on its own account — the topology `docs/consumer-guide.md` § Coordination
 * intents calls the DEFAULT for an install that shares no account, and rates the strongest
 * of its three attribution paths. That second install WORKS, end to end, and
 * `Tests\Feature\Dispatch\DispatchServiceTest::test_a_distinct_account_coord_install_suppresses_its_own_post_and_delivers_a_peers()`
 * is the witness: two coord agents with distinct ids, the poster's own dispatch suppressed
 * and the other agent's DELIVERED with the wake leaving the box. Who else posts under an
 * upstream account is a fact that lives upstream — no preflight reads it off a config dir.
 * `App\Bridge\Support\Severity` corollary (A) is the rule that settles the severity: the
 * MEASUREMENT completed (the key is there, the classifier is that one), and only what it
 * IMPLIES is ambiguous, so the finding is a `warn` that says so — never `unvalidated`,
 * which would claim the install stopped this leg from looking, and never `fail`, which
 * would red a documented working install.
 *
 * THE THIRD STATE IS SEPARATED RATHER THAN FOLDED IN. When `shared-identities.json`
 * declares that same account, the registry's shared entry takes precedence and the inline
 * key is INERT — attribution is correct today. That is a different sentence to the
 * operator and a different remedy, so it is a different arm: the key is a duplicate of a
 * declaration that already exists, and the day the shared entry is edited away it becomes
 * the live defect in silence.
 *
 * ⚑ THE PREDICATE MATCHES THE CLASSIFIER FQCN EXACTLY, SO A SUBCLASS ESCAPES IT.
 * Preflight cannot load a classifier in-process (DL-025: a stale-signature class is an
 * uncatchable `E_COMPILE_ERROR`), and `App\Bridge\Support\ClassifierResolver::probeImplements()`
 * — the out-of-process probe this command uses everywhere else — reads `class_implements`,
 * so it answers about INTERFACES and cannot be asked "is this a subclass of that class".
 * The runtime half of this card covers that population instead: `DispatchService` holds
 * the resolved INSTANCE and tests it with `instanceof`, so a subclass is warned about
 * when the gate actually fires. Stated here so a reader does not read this leg's silence
 * as a clean answer for a classifier that extends the shipped one.
 *
 * ⚑ A NON-NUMERIC `identity.github_user_id` IS INVISIBLE HERE, and that is
 * `App\Bridge\Support\IdentityConfig::fromArray()`'s doing, not this leg's: it keeps the key only when
 * `is_numeric`, so `github_user_id: "mybot"` parses to null and reaches neither the
 * registry nor this check. Such a config is silently identity-less rather than
 * mis-attributing, so it is not this leg's defect — but nothing reports it either.
 *
 * IT RUNS POST-LOOP, IN THE ROSTER SLOT, BECAUSE IT READS THE SHARED-IDENTITY
 * DECLARATION. `CheckContext::$registry` and `CheckContext::$sharedIdentities` are
 * published after the per-agent loop finishes, so a `PerAgentCheck` in the loop would see
 * the registry as null and could only report the two-arm question as one arm.
 */
final class AgentCoordinationIdentityCheck implements Check
{
    public function id(): string
    {
        return 'agent.coordination_identity';
    }

    /**
     * @return iterable<Finding|Silence>
     */
    public function run(CheckContext $ctx): iterable
    {
        if ($ctx->registry === null) {
            yield Silence::because('no agent registry was built, so nothing can be said about which github account id is declared shared — the build failure is reported where it happens');

            return;
        }

        foreach ($ctx->configs as $config) {
            $id = $config->identity->githubUserId;
            if ($id === null || ltrim($config->classifierClass, '\\') !== CoordinationClassifier::class) {
                continue;
            }

            $name = $config->agentName;

            if ($ctx->registry->isSharedGithubId($id)) {
                yield Finding::warn(
                    "agent {$name}: identity.github_user_id = {$id} is declared inline on an agent running CoordinationClassifier, "
                    .'AND shared-identities.json declares that same account shared. The shared declaration WINS, so attribution is '
                    .'correct today and this key is doing nothing — it is a second copy of a declaration that already exists one file '
                    .'over. Delete the key: the day the shared entry is edited or removed, this copy silently becomes the account→agent '
                    .'mapping that drops every coordination post as `echo: own write`.'
                );

                continue;
            }

            yield Finding::warn(
                "agent {$name}: identity.github_user_id = {$id} is declared inline on an agent running CoordinationClassifier, and "
                ."no shared-identities.json entry declares that account shared. ⚠ IF SEVERAL AGENTS POST UNDER ACCOUNT {$id} THIS "
                ."SEAT IS DEAF: the registry resolves every event from it to \"{$name}\", the pre-classify echo gate drops each one "
                .'as `echo: own write` before classify, and the FROM:-line re-attribution that makes suppression per-agent on a '
                .'shared account never runs (it short-circuits on a named actor). Nothing else reports that — the webhook still '
                ."verifies, the dispatch row is still written, and this command still exits 0. ⚑ IF ACCOUNT {$id} IS THIS AGENT'S "
                .'ALONE this config is CORRECT and this line is expected: that is the distinct-account topology docs/consumer-guide.md '
                .'§ Coordination intents calls the default for an install sharing no account. WHICH ONE IT IS CANNOT BE READ FROM '
                .'THIS HOST — who else posts under an upstream account is not a fact any local read establishes — so this is a '
                .'question, not a verdict. If the account is shared: delete the key (preferred — the shared-account model needs no '
                .'per-agent github id), or declare the account once in shared-identities.json.'
            );
        }

        yield Silence::because('no agent pairs CoordinationClassifier with an inline identity.github_user_id — the combination this leg exists for, which most installs correctly never write');
    }
}
