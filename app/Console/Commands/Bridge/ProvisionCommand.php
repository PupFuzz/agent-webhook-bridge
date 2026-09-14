<?php

namespace App\Console\Commands\Bridge;

use App\Bridge\Exceptions\ConfigException;
use App\Bridge\Exceptions\InsecureSecretPermsException;
use App\Bridge\Exceptions\UnreadableSecretException;
use App\Bridge\Provision\KanbanProvisionClient;
use App\Bridge\Provision\ProvisionResult;
use App\Bridge\Provision\WebhookProvisioner;
use App\Bridge\Provision\WritebackIdentityOffer;
use App\Bridge\Support\AgentConfig;
use App\Bridge\Support\ReceiverUrl;
use App\Bridge\Support\SecretFile;
use App\Bridge\Support\SecretScrubber;
use App\Bridge\Support\SubscriptionRegistry;
use App\Bridge\Support\TokenPath;
use App\Bridge\Support\UntrustedText;
use App\Bridge\Support\UrlValidator;
use Illuminate\Http\Client\RequestException;
use Illuminate\Routing\Router;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Throwable;

/**
 * Ensure each declared (provider, scope) subscription exists on kanban-board,
 * pointing at the agent's receiver URL with a per-scope HMAC secret. Idempotent
 * (existing subscriptions are detected by receiver-URL match and left alone).
 *
 * Only the `kanban` provider is provisionable via API; other providers (e.g.
 * GitHub, whose webhooks are configured in repo settings) are skipped with a
 * non-zero exit. `--reconcile` fixes inactive / filter-drifted subscriptions
 * (delete + recreate reusing the secret); without it, drift is reported with a
 * non-zero exit. URL-drift orphan cleanup is manual (no local registry — the
 * live API is the source of truth).
 *
 * A receiver base `bridge:check` rejects as a URL is REFUSED for the whole run, in every mode,
 * before anything is sent upstream or written locally — see {@see self::receiverBaseRefusal()}.
 * A subscription whose composed receiver URL reaches no receiver route in this app is
 * REFUSED on its own, before anything is sent upstream or written for it, unless
 * `--allow-unreachable-receiver` is given — see {@see self::mayRegister()}.
 */
class ProvisionCommand extends BridgeCommand
{
    private const ALLOW_UNREACHABLE_RECEIVER = 'allow-unreachable-receiver';

    protected $signature = 'bridge:provision {--agent= : limit to one agent} {--dry-run : preview, change nothing} {--list : show live subscriptions and exit} {--reconcile : fix inactive/filter-drifted subscriptions (delete + recreate, reusing the secret)} {--'.self::ALLOW_UNREACHABLE_RECEIVER.' : register a receiver URL even though it reaches no receiver route in this app (e.g. an install behind a proxy that rewrites the request path)}';

    protected $description = 'Register webhook subscriptions on kanban-board for each agent config';

    public function handle(): int
    {
        $configDir = (string) config('bridge.config_dir');
        $secretDir = (string) config('bridge.secret_dir');
        if ($configDir === '' || $secretDir === '') {
            $this->error('bridge.config_dir and bridge.secret_dir must be configured (set BRIDGE_DIR)');

            return self::FAILURE;
        }

        $receiverBaseUrl = (string) config('bridge.receiver_base_url');
        if ($receiverBaseUrl === '') {
            $this->error('bridge.receiver_base_url (BRIDGE_RECEIVER_BASE_URL) must be configured');

            return self::FAILURE;
        }
        $refusal = $this->receiverBaseRefusal($receiverBaseUrl);
        if ($refusal !== null) {
            $this->error(OutputFormatter::escape($refusal));

            return self::FAILURE;
        }

        $allAgents = (new SubscriptionRegistry($configDir))->agentConfigs();
        $agents = $allAgents;
        $only = $this->strOption('agent');
        if ($only !== null) {
            $agents = array_values(array_filter($agents, fn (AgentConfig $a) => $a->agentName === $only));
        }

        $provisioner = new WebhookProvisioner($secretDir);
        $rc = self::SUCCESS;

        foreach ($agents as $agent) {
            foreach ($agent->subscriptions as $sub) {
                $label = "[{$agent->agentName}] {$sub->provider}:{$sub->scopeId}";

                if ($sub->provider !== 'kanban') {
                    $this->warn("{$label} SKIP — provider '{$sub->provider}' is not API-provisionable");
                    $rc = self::FAILURE;

                    continue;
                }

                try {
                    $token = $this->readToken($agent, $sub->provider, $secretDir);
                } catch (InsecureSecretPermsException $e) {
                    // A group/world-readable token is a hard failure, not a skip:
                    // any co-tenant can read it and write upstream (DL-010).
                    $this->error("{$label} FAIL — ".$e->getMessage());
                    $rc = self::FAILURE;

                    continue;
                } catch (UnreadableSecretException $e) {
                    // This command provisions AS the operator, and the token it needs is
                    // the one IT will present — so unlike the checks, the read that
                    // failed is the read that matters, and a definite failure is earned.
                    $this->error("{$label} FAIL — ".$e->getMessage());
                    $rc = self::FAILURE;

                    continue;
                }
                if ($token === null) {
                    // "unreadable" until card#5778, which was the wrong half of the split
                    // even then and is now provably so: an unreadable token throws above,
                    // so null is absent-or-blank and nothing else.
                    $this->warn("{$label} SKIP — no token at {$agent->tokenPath($secretDir, $sub->provider)} (place one, chmod 600)");
                    $rc = self::FAILURE;

                    continue;
                }

                $apiBaseUrl = (string) config("bridge.providers.{$sub->provider}.api_base_url");
                if ($apiBaseUrl === '') {
                    $this->warn("{$label} SKIP — no API base url configured (BRIDGE_".strtoupper($sub->provider).'_API_BASE_URL)');
                    $rc = self::FAILURE;

                    continue;
                }

                UrlValidator::secureHttpUrl($apiBaseUrl, "bridge.providers.{$sub->provider}.api_base_url");
                $client = new KanbanProvisionClient($apiBaseUrl, $token);
                $receiverUrl = ReceiverUrl::for($receiverBaseUrl, $sub->provider, $sub->scopeId);
                $shown = $this->shownReceiverUrl($receiverUrl, $sub->provider, $sub->scopeId);

                if (! $this->option('list') && ! $this->mayRegister($label, $sub->provider, $sub->scopeId, $receiverUrl, $shown)) {
                    $rc = self::FAILURE;

                    continue;
                }

                try {
                    if ($this->option('list')) {
                        $this->listScope($client, $label, $sub->provider, $sub->scopeId);

                        continue;
                    }

                    $result = $provisioner->ensure(
                        $client, $sub->provider, $sub->scopeId, $receiverUrl,
                        $sub->eventFilter ?: null, (bool) $this->option('dry-run'), (bool) $this->option('reconcile'),
                    );
                    $this->reportResult($label, $result, $shown);
                    if (in_array($result->status, ['drift', 'cannot_reconcile'], true)) {
                        $rc = self::FAILURE;   // operator must act (re-run with --reconcile, or fix the secret)
                    }
                } catch (Throwable $e) {
                    // ⛔ FOREIGN BYTES ON AN OPERATOR'S TERMINAL, escaped at the write for
                    // the reason `ReconcileCommand`'s read arm spells out: this arm relays a
                    // `RequestException` carrying the kanban RESPONSE BODY, there is no
                    // `Finding` and no renderer in the path, and Guzzle's body-summary gate
                    // passes `\r` (card#9121, DL-366).
                    $this->error("{$label} API error: ".UntrustedText::forOperator($this->apiErrorText($e, $receiverUrl, $shown)));
                    $rc = self::FAILURE;
                }
            }
        }

        $this->offerWritebackIdentity($configDir, $secretDir, $allAgents);

        return $rc;
    }

    /**
     * Why this run may not use `$receiverBaseUrl` at all, or null when it may.
     *
     * ⛔ THE RULE IS {@see UrlValidator::httpUrl()}, the one `install.endpoint_urls` fails
     * `bridge:check` on for this field — never a scheme check of this command's own. Not
     * `secureHttpUrl()`: that is the floor for the endpoints that carry a secret, and
     * `bridge:check` does not hold the receiver base to it.
     *
     * It gates `--list` too, as the unset base above does: a value that is not a URL composes
     * no receiver URL to compare a live subscription against. `--allow-unreachable-receiver`
     * does not bypass it — that flag answers for a proxy that rewrites the PATH, and no proxy
     * makes a base that is not an http(s) URL deliverable. The message is the validator's,
     * which quotes the value through `SecretScrubber::url()`.
     */
    private function receiverBaseRefusal(string $receiverBaseUrl): ?string
    {
        try {
            UrlValidator::httpUrl($receiverBaseUrl, 'bridge.receiver_base_url');

            return null;
        } catch (ConfigException $e) {
            return 'REFUSED — '.$e->getMessage().'; nothing was sent upstream or written. '
                ."Fix BRIDGE_RECEIVER_BASE_URL in this install's .env and re-run (bridge:check fails on the same value). "
                .'--'.self::ALLOW_UNREACHABLE_RECEIVER.' does not apply: it overrides only the receiver-route check.';
        }
    }

    /**
     * May this run hand `$receiverUrl` to the provisioner — which creates, deletes and
     * recreates the upstream subscription and writes the per-scope secret?
     *
     * ⛔ THE PREDICATE IS {@see ReceiverUrl::reachesThisInstall()}, the one
     * `install.endpoint_urls` fails `bridge:check` on — never a second rule about receiver
     * paths. It is asked of the URL this subscription would actually register, so the gate
     * runs per subscription and a dry run previews the refusal a real run would print.
     * `--list` is exempt: it only reads.
     *
     * ⚠ `false` from that predicate is a verdict on PATH and QUERY against this app's router
     * only. An install behind a proxy that rewrites the request path answers `false` and
     * delivers, and nothing on this box can measure that hop — which is what the override
     * exists for, and why the refusal names it.
     *
     * `$shown` is `$receiverUrl` as {@see self::shownReceiverUrl()} renders it.
     */
    private function mayRegister(string $label, string $provider, string $scopeId, string $receiverUrl, string $shown): bool
    {
        if (ReceiverUrl::reachesThisInstall($receiverUrl, $provider, $scopeId, app(Router::class)->getRoutes())) {
            return true;
        }

        $flag = '--'.self::ALLOW_UNREACHABLE_RECEIVER;

        if ($this->option(self::ALLOW_UNREACHABLE_RECEIVER)) {
            $this->warn(OutputFormatter::escape(
                "{$label} OVERRIDE — {$shown} reaches no receiver route in this app; provisioning it anyway because {$flag} was given."
            ));

            return true;
        }

        $this->error(OutputFormatter::escape(
            "{$label} REFUSED — the receiver URL {$shown} reaches no receiver route in this app, so a delivery to it would be refused here; nothing was sent upstream or written. "
            .'Likely cause: BRIDGE_RECEIVER_BASE_URL, which must already end in the receiver path (see .env.example) — a bare host, that path doubled, or a base carrying its own query (which swallows the ?b= appended after it) looks like this. '
            .'Fix it in this install\'s .env and re-run (bridge:check fails on the same value). '
            ."Only the path and query were checked, against this app's own router: if this install is served behind something that REWRITES the request path, re-run with {$flag} to provision it anyway."
        ));

        return false;
    }

    /**
     * Offer the writeback `identity_id` this install has not declared, resolved from the
     * writeback token it already holds (card#9141 / DL-369).
     *
     * ⛔ IT CANNOT MOVE `$rc`, AND IS CALLED WHERE THAT IS OBVIOUS — after the provisioning
     * loop, returning void. Whether kanban can be asked who a token is says nothing about
     * whether this install's webhook subscriptions are correct, and a setup command that
     * started failing because an unrelated offer could not be made would be exactly the
     * fail-closed behaviour this must not have.
     *
     * ⚑ WHY HERE AND NOT IN `App\Bridge\Check\Checks\WritebackIdentityCheck`, which is where the
     * missing value is currently WARNED about: that leg is an offline reporter inside a
     * read-only command — it makes no network call and writes nothing, by design and by its
     * own docblock — and the operator's ruling is that this resolve must SHOW, ASK and only
     * then WRITE. A confirmed mutation does not belong in a check; the check instead names
     * this command as the remedy.
     *
     * ⛔ THE CONFIRMATION IS THE GATE, AND {@see BridgeCommand::canPromptToConfirm()} DECIDES
     * WHETHER THIS RUN MAY ASK AT ALL — including what "may ask" means, which is stated there
     * and deliberately not restated here; three revisions of this comment were wrong before it
     * held, each falsified by measurement at the real command rather than reasoned away.
     * Where this run may not ask, the offer is not prepared AT ALL — no request, no question.
     * There is still deliberately no `--yes`: an unattended accept is the silent write the
     * offer shape exists to prevent.
     *
     * @param  list<AgentConfig>  $agents  UNFILTERED by --agent: the identity is install-scoped,
     *                                     and a narrowed comparison population would report a
     *                                     clean result over tokens it never looked at
     */
    private function offerWritebackIdentity(string $configDir, string $secretDir, array $agents): void
    {
        if ($this->option('list')) {
            return;
        }

        $apiBaseUrl = (string) config('bridge.providers.kanban.api_base_url');
        if ($apiBaseUrl === '' || ! WritebackIdentityOffer::isPending($configDir)) {
            return;
        }

        // ⚠ The dry-run NOTICE is a claim about what a rerun would do, so it is only true of a
        // run that could be asked. Where this one could not, fall through instead: the offer
        // composes the honest cause and makes no request on that path either.
        if ($this->option('dry-run') && $this->canPromptToConfirm()) {
            $this->line(sprintf(
                'writeback: %s declares no identity_id — a run without --dry-run offers the value resolved from the writeback token.',
                WritebackIdentityOffer::path($configDir),
            ));

            return;
        }

        $offer = new WritebackIdentityOffer;

        try {
            $plan = $offer->prepare(
                $configDir,
                TokenPath::forWriteback($secretDir, 'kanban'),
                $apiBaseUrl,
                $this->otherKanbanTokenPaths($secretDir, $agents),
                $this->canPromptToConfirm(),
            );
        } catch (Throwable $e) {
            // ⛔ THE CLASS, NOT THE MESSAGE, ON THIS HALF ONLY. Setup completing is the
            // guarantee, so nothing here may abort it — and the message is withheld because an
            // HTTP-layer exception can carry the response BODY, which is sensitive as a class.
            // Every cause an operator can act on is produced by the resolver as a named
            // fallback, which never puts the body in one.
            $this->warn(sprintf(
                'writeback: the identity_id offer could not run (%s) — nothing was written; docs/writeback.md § 2 has the by-hand recipe.',
                $e::class,
            ));

            return;
        }

        // ⚠ ESCAPED, because these lines interpolate values this command did not choose —
        // a kanban display name, a path — and the console INTERPRETS `<…>` style tags. The
        // operator is being asked to recognise an account by that name, so a name the
        // renderer silently rewrote is one they cannot check against the board.
        foreach ($plan->notes as $note) {
            $this->line(OutputFormatter::escape($note));
        }
        foreach ($plan->warnings as $warning) {
            $this->warn(OutputFormatter::escape($warning));
        }

        $identity = $plan->offered;
        if ($identity === null) {
            return;
        }

        if (! $this->confirm("Write identity_id {$identity->id} into writeback.json?", false)) {
            $this->line('  Nothing was written.');

            return;
        }

        try {
            $offer->commit($configDir, $identity->id);
            $this->info("  ✓ writeback.json identity_id = {$identity->id}.");
        } catch (Throwable $e) {
            // ⚑ THE MESSAGE, HERE. A failed write is the arm whose cause is BOTH the useful
            // half and safe to print: every message on this path is composed by this app —
            // the FILE it could not write, plus whatever reason its own write primitive could
            // name (⚠ measured: `error_get_last()` gave nothing under `@` here, so that reason
            // can be the primitive's fallback list rather than the errno) — and no upstream
            // response body can reach it. Withholding it, as the resolve half must, would
            // leave a read-only or full filesystem reported as a bare exception class.
            $this->warn(sprintf(
                'writeback: identity_id %d was NOT written — %s. Nothing else changed; put the number in by hand (docs/writeback.md § 2).',
                $identity->id,
                OutputFormatter::escape($e->getMessage()),
            ));
        }
    }

    /**
     * The kanban tokens this install's config names, EXCEPT the writeback's own — the
     * population the same-user comparison can actually see. Per-agent `api.kanban.token_path`
     * overrides are honoured by asking each agent for its own path rather than re-deriving
     * the convention here.
     *
     * @param  list<AgentConfig>  $agents
     * @return list<string>
     */
    private function otherKanbanTokenPaths(string $secretDir, array $agents): array
    {
        $paths = [TokenPath::for($secretDir, 'kanban')];
        foreach ($agents as $agent) {
            $paths[] = $agent->tokenPath($secretDir, 'kanban');
        }

        return array_values(array_diff(array_unique($paths), [TokenPath::forWriteback($secretDir, 'kanban')]));
    }

    private function readToken(AgentConfig $agent, string $provider, string $secretDir): ?string
    {
        return SecretFile::read($agent->tokenPath($secretDir, $provider));
    }

    /**
     * A receiver URL as this command prints it: the part in front of the composed
     * `/<provider>?b=<scope>` suffix through {@see SecretScrubber::url()} — the same redactor
     * `bridge:check` quotes the base through — and the suffix kept as composed.
     *
     * ⛔ EVERY LINE THAT PRINTS A RECEIVER URL GOES THROUGH HERE, because a receiver base may
     * carry credentials in its userinfo or query and each line is a separate chance to print
     * them. The suffix is split off first because scrubbing the whole URL would redact the
     * `?b=<scope>` the operator reads the line for, and it carries nothing of the base: this
     * app composes it ({@see ReceiverUrl::for()}). A URL that does not end in this
     * subscription's suffix — a live row someone else registered — is scrubbed whole.
     */
    private function shownReceiverUrl(string $url, string $provider, string $scopeId): string
    {
        $suffix = ReceiverUrl::for('', $provider, $scopeId);
        if (! str_ends_with($url, $suffix)) {
            return SecretScrubber::url($url);
        }

        return SecretScrubber::url(substr($url, 0, -strlen($suffix))).$suffix;
    }

    /**
     * An exception from a provisioning call, as the `API error` line relays it, with the
     * receiver URL's credentials removed. The URL was sent to kanban, so a refusal body can
     * echo it back.
     *
     * ⛔ A `RequestException` IS REBUILT FROM ITS FULL RESPONSE BODY, NOT READ FROM
     * `getMessage()`. That message is already cut at `RequestException::$truncateAt`, and a
     * cut inside an echoed URL's userinfo leaves a password with no `@` after it, which no
     * redactor can recognise as a userinfo. Redacting the full body and leaving the bound to
     * `UntrustedText::forOperator()` puts redaction ahead of that bound.
     *
     * Each echo of `$receiverUrl`, raw or with JSON-escaped slashes, becomes `$shown` first.
     * That match is by value, so it holds for a scheme {@see SecretScrubber::text()} does not
     * read as a URL (it reads only http and https). `text()` then covers every other
     * credential-shaped span in the body, and it also removes the query of an echoed http(s)
     * URL, so `?b=<scope>` shows as `?[REDACTED]` on this line.
     */
    private function apiErrorText(Throwable $e, string $receiverUrl, string $shown): string
    {
        $text = $e instanceof RequestException
            ? "HTTP request returned status code {$e->response->status()}: {$e->response->body()}"
            : $e->getMessage();

        $jsonSlashes = static fn (string $url): string => str_replace('/', '\\/', $url);
        $text = str_replace([$receiverUrl, $jsonSlashes($receiverUrl)], [$shown, $jsonSlashes($shown)], $text);

        return SecretScrubber::text($text);
    }

    private function reportResult(string $label, ProvisionResult $result, string $url): void
    {
        match ($result->status) {
            'exists' => $this->info("{$label} EXISTS — webhook id={$result->webhookId} → {$url}"),
            'created' => $this->info("{$label} CREATED — webhook id={$result->webhookId} → {$url}"),
            'would_create' => $this->line("{$label} DRY-RUN — would create → {$url}"),
            'reconciled' => $this->info("{$label} RECONCILED ({$result->detail}) — webhook id={$result->webhookId} → {$url}"),
            'would_reconcile' => $this->line("{$label} DRY-RUN — would reconcile ({$result->detail}) → {$url}"),
            'cannot_reconcile' => $this->error("{$label} CANNOT RECONCILE — {$result->detail}; delete the live webhook and re-run to create fresh"),
            default => $this->warn("{$label} DRIFT ({$result->detail}) — webhook id={$result->webhookId}; re-run with --reconcile to fix"),
        };
    }

    private function listScope(KanbanProvisionClient $client, string $label, string $provider, string $scopeId): void
    {
        $subs = $client->listWebhooks($scopeId);
        if ($subs === []) {
            $this->line("{$label} (no subscriptions)");

            return;
        }
        foreach ($subs as $sub) {
            // ⛔ A SUBSCRIPTION ROW IS FOREIGN TEXT (card#9200, DL-366 Decision 12's shape).
            // These are RAW kanban API rows, and a row's `url` is chosen by whoever registered
            // the subscription on that board — not by this install. A `url` carrying
            // `\x1B[2K\r` plus a plausible row overwrites the line this loop just printed, on
            // the run an operator reads to decide whether to `--reconcile`.
            // ⚠ NEITHER CENSUS IN card#9200 COULD SEE THIS ARM: Decision 11's counts
            // `getMessage()` and this is the SUCCESS path of a read; Decision 12's was over
            // `bridge:check`'s findings and there is no `Finding` and no renderer here.
            // `$active` is NOT escaped — it is one of two words this file chose.
            // ⚠ THE DISPLAY BOUND APPLIES TO THE WHOLE VALUE HERE, and that is stated rather
            // than discovered: these are values, not spans inside bridge prose, so a row whose
            // `url` runs past `UntrustedText::MAX_CHARS` is shown truncated — with the marker
            // naming the source length, never silently.
            // ⛔ SCRUBBED BEFORE IT IS BOUNDED, and scrubbed at all because a row this install
            // registered carries the receiver base it was composed from, credentials included.
            // The order matters: a truncation ahead of the redactor can cut off the `@` that
            // ends a userinfo, and the redactor then finds no userinfo to remove.
            $active = ($sub['active'] ?? false) ? 'active' : 'INACTIVE';
            $id = UntrustedText::forOperator((string) $sub['id']);
            $url = UntrustedText::forOperator(isset($sub['url']) ? $this->shownReceiverUrl((string) $sub['url'], $provider, $scopeId) : '?');
            $this->line("{$label} id={$id} {$active} → {$url}");
        }
    }
}
