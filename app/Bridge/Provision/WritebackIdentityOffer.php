<?php

namespace App\Bridge\Provision;

use App\Bridge\Exceptions\ConfigException;
use App\Bridge\Exceptions\InsecureSecretPermsException;
use App\Bridge\Exceptions\UnreadableSecretException;
use App\Bridge\Support\BridgePaths;
use App\Bridge\Support\FileContents;
use App\Bridge\Support\SecretFile;
use App\Bridge\Support\UrlValidator;
use App\Bridge\Writeback\WritebackConfig;
use Throwable;

/**
 * Setup's OFFER of the writeback `identity_id` it can resolve from the token the operator
 * has already placed (card#9141 / DL-369) — never a silent write.
 *
 * ⭐ WHY AN OFFER AND NOT A RESOLVE-AND-WRITE. `identity_id` is first the echo-gate, and
 * second a declaration of which kanban user the writeback authenticates as; docs/writeback.md
 * § 1 owns the separation requirement and what it costs when two writers share one user.
 * Accepting whatever the presented token resolves to would take an operator who pasted the
 * WRONG token and hand them a config that looks correct and is not — the one failure this
 * whole path must not make easier. So the id and the display NAME are shown, any second
 * token this install's config names that resolves to the same user is reported first, and
 * the write happens only on an affirmative answer.
 *
 * ⛔ FAIL SOFT, NEVER FAIL CLOSED. No token yet, an unreachable API, a 401/403, a body that
 * is not JSON, no `.data.id`, no display name — every one of them returns a plan carrying
 * the NAMED cause and the by-hand recipe, and setup's own outcome is untouched. This class
 * raises nothing that could abort a provisioning run, and an install with no network must
 * stay runnable.
 */
final class WritebackIdentityOffer
{
    public function __construct(
        private readonly KanbanIdentityResolver $resolver = new KanbanIdentityResolver,
    ) {}

    public static function path(string $configDir): string
    {
        return rtrim($configDir, '/').'/writeback.json';
    }

    /**
     * Is there a `writeback.json` that declares no `identity_id`? The whole of the
     * precondition, in one predicate both the offer and the dry-run notice read.
     *
     * ⚠ A writeback.json that will not PARSE answers false rather than raising: `bridge:check`
     * owns reporting a malformed one, and a second, differently-worded parse error raised
     * from inside provisioning would send the operator to a second surface for one fault.
     */
    public static function isPending(string $configDir): bool
    {
        if (! is_file(self::path($configDir))) {
            return false;                       // absent ⇒ writeback is off; there is nothing to declare
        }

        try {
            $config = WritebackConfig::load($configDir);
        } catch (Throwable) {
            return false;
        }

        return $config !== null && $config->identityId === null;
    }

    /**
     * @param  list<string>  $otherTokenPaths  every OTHER kanban token this install's config
     *                                         names, for the same-user comparison
     * @param  bool  $canConfirm  whether a human will SEE this run's question and can ANSWER it.
     *                            `App\Console\Commands\Bridge\BridgeCommand::canPromptToConfirm()`
     *                            owns that predicate, its terms and the measurements behind them;
     *                            nothing here restates them, and no count of them is written.
     *                            False ⇒ no offer is prepared AT ALL, and no request is made
     */
    public function prepare(string $configDir, string $writebackTokenPath, string $apiBaseUrl, array $otherTokenPaths, bool $canConfirm): WritebackIdentityOfferPlan
    {
        if (! self::isPending($configDir)) {
            return WritebackIdentityOfferPlan::nothingToOffer();
        }

        // ⛔ CANNOT ASK ⇒ NO OFFER, AND THE CHECK IS FIRST — before the token read and before
        // the request. An offer nobody can answer is not a cheaper offer, it is two defects,
        // and every one of them was measured at the real command rather than reasoned about;
        // the gate that decides this bit owns the evidence and the reasons.
        // ⚠ The cause is stated as the DISJUNCTION it is: this method is handed ONE BIT, so
        // naming any single half specifically would be the wrong cause most of the time. It
        // states the CONDITIONS asking needs — and then, for the skip-prompts half ONLY, it
        // does spell the flags out, because an operator staring at this line cannot follow a
        // pointer the way a doc reader can. ⛔ That makes this the ONE restatement of the flag
        // list the repo keeps outside the predicate, so it is GUARDED rather than trusted:
        // `Tests\Unit\Docs\InteractivityFlagListGuardTest` re-derives the set from
        // `Application::configureIO` itself and reds if this string stops naming a condition
        // that clears interactivity. Do not add a second such copy; point at the predicate.
        if (! $canConfirm) {
            return $this->fallback(
                $configDir,
                $apiBaseUrl,
                'this run cannot ask for confirmation — asking needs a terminal it can print the question to and read '
                    .'the answer from, and a run that was not told to skip prompts (-n / -q / --silent / a negative '
                    .'SHELL_VERBOSITY) — and this value is never written unconfirmed',
            );
        }

        // ⛔ THE SAME TRANSPORT FLOOR THE PROVISIONING LOOP APPLIES, ASSERTED HERE RATHER THAN
        // INHERITED FROM IT. This request presents the writeback bearer token, and the loop's
        // own `secureHttpUrl` call runs per kanban SUBSCRIPTION — an install that declares none
        // reaches this line having validated nothing. Refusing is a fail-soft fallback, not a
        // throw: the operator is told the config is the fault and setup still finishes.
        try {
            UrlValidator::secureHttpUrl($apiBaseUrl, 'bridge.providers.kanban.api_base_url');
        } catch (ConfigException $e) {
            return $this->fallback($configDir, $apiBaseUrl, $e->getMessage());
        }

        try {
            $token = SecretFile::read($writebackTokenPath);
        } catch (InsecureSecretPermsException|UnreadableSecretException $e) {
            return $this->fallback($configDir, $apiBaseUrl, $e->getMessage());
        }

        if ($token === null) {
            return $this->fallback($configDir, $apiBaseUrl, "there is no writeback token at {$writebackTokenPath} yet");
        }

        $resolution = $this->resolver->resolve($apiBaseUrl, $token);
        $identity = $resolution->identity;
        if ($identity === null) {
            return $this->fallback($configDir, $apiBaseUrl, (string) $resolution->failure);
        }

        return new WritebackIdentityOfferPlan(
            $identity,
            [
                sprintf(
                    'writeback: %s declares no identity_id. The writeback token at %s resolves to kanban user %d ("%s").',
                    self::path($configDir),
                    $writebackTokenPath,
                    $identity->id,
                    $identity->name,
                ),
                '  ⛔ Accept only if that is the writeback\'s OWN kanban user — docs/writeback.md § 1 owns why it must be '
                    .'neither a human\'s nor one your board tooling already authenticates as.',
            ],
            $this->compareOtherTokens($identity, $apiBaseUrl, $otherTokenPaths),
        );
    }

    /**
     * Write the confirmed id into `writeback.json`, keeping every other key. Called only
     * after an affirmative answer.
     *
     * ⚠ The file is re-encoded, so formatting is normalised (JSON has no comments to lose).
     * The existing file's mode is preserved — this writes through the same primitive the
     * rest of the package writes state with, and does not create the file.
     */
    public function commit(string $configDir, int $identityId): void
    {
        $path = self::path($configDir);
        $raw = json_decode((string) FileContents::read($path, 'writeback.json'), true);
        if (! is_array($raw)) {
            throw new ConfigException("writeback.json at {$path} is not a valid JSON object");
        }

        $updated = ['identity_id' => $identityId] + $raw;
        BridgePaths::writeFile(
            $path,
            (string) json_encode($updated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n",
            LOCK_EX,
        );
    }

    /**
     * The by-hand path, with the cause NAMED and the URL this actually requested printed —
     * so the retry the operator makes is the request that just failed, not a re-spelling of
     * it that might differ.
     */
    private function fallback(string $configDir, string $apiBaseUrl, string $cause): WritebackIdentityOfferPlan
    {
        return new WritebackIdentityOfferPlan(null, [], [
            sprintf('writeback: %s declares no identity_id, and it could not be resolved — %s.', self::path($configDir), $cause),
            sprintf('  By hand: GET %s with the writeback token, and read `.data.id`.', KanbanIdentityResolver::endpoint($apiBaseUrl)),
            '  The copy-paste recipe, which keeps the token out of argv, is docs/writeback.md § 2. Setup is unaffected — nothing was written.',
        ]);
    }

    /**
     * ⛔ MECHANISES THE CHECK docs/writeback.md § 1 CURRENTLY ASKS THE OPERATOR TO DO BY HAND,
     * over the population it can actually see: the kanban token paths this install's own
     * config names. A token belonging to a board CLI elsewhere on the host is outside that
     * population and stays invisible — so a clean comparison here is NOT a separation claim,
     * and a token that could not be resolved is reported UNCHECKED rather than passed over,
     * because silence would read as cleared.
     *
     * @param  list<string>  $otherTokenPaths
     * @return list<string>
     */
    private function compareOtherTokens(KanbanIdentity $identity, string $apiBaseUrl, array $otherTokenPaths): array
    {
        $warnings = [];

        foreach ($otherTokenPaths as $path) {
            try {
                $other = SecretFile::read($path);
            } catch (InsecureSecretPermsException|UnreadableSecretException $e) {
                $warnings[] = $this->unchecked($path, $identity, $e->getMessage());

                continue;
            }

            if ($other === null) {
                continue;   // no token at that path — nothing was skipped, so nothing is unchecked
            }

            $resolution = $this->resolver->resolve($apiBaseUrl, $other);
            $resolved = $resolution->identity;
            if ($resolved === null) {
                $warnings[] = $this->unchecked($path, $identity, (string) $resolution->failure);

                continue;
            }

            if ($resolved->id === $identity->id) {
                $warnings[] = sprintf(
                    '⛔ %s resolves to the SAME kanban user (%d) — this install holds two kanban tokens that are one '
                        .'board user. docs/writeback.md § 1 owns what that costs; check which token you placed before accepting.',
                    $path,
                    $identity->id,
                );
            }
        }

        return $warnings;
    }

    private function unchecked(string $path, KanbanIdentity $identity, string $cause): string
    {
        return sprintf(
            '⚠ %s could not be resolved (%s) — it is UNCHECKED against user %d, not cleared.',
            $path,
            $cause,
            $identity->id,
        );
    }
}
