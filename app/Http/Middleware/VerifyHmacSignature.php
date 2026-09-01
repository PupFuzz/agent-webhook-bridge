<?php

namespace App\Http\Middleware;

use App\Bridge\Adapters\WebhookAdapterFactory;
use App\Bridge\Http\PlainTextResponse;
use App\Bridge\Support\WebhookSecretFailure;
use App\Bridge\Support\WebhookSecretResolver;
use App\Bridge\Validation\ProviderName;
use App\Bridge\Validation\ScopeId;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The receiver's security gate.
 * Resolves the adapter from the {provider} route segment and the scope from
 * the `?b=` query, loads the per-(provider, scope) HMAC secret (through
 * {@see WebhookSecretResolver}, which owns WHERE it lives and
 * exactly how its bytes are normalized, so a signature PRODUCER can apply the same
 * rule instead of restating it), and verifies the signature with a constant-time
 * compare — all before the request reaches the controller. Preserves the exact
 * status contract so kanban-board's retry behaviour is unchanged (it retries
 * 5xx/429, not other 4xx):
 *
 *   body_too_large (EnvelopeSizeLimit, BEFORE this)      → 413
 *   invalid_provider / unknown_provider / invalid_scope → 400
 *   unknown_scope (secret file absent or unreadable)    → 401
 *   sig_mismatch                                        → 401
 *   config_secret_dir_* / secret_perms_insecure         → 500
 *   empty_secret_file                                   → 500
 *
 * 413 is a deterministic 4xx (a body over `bridge.max_body_bytes`, 256 KB default
 * — which "covers every real provider" per config/bridge.php). kanban-board does
 * NOT retry it, so an over-limit delivery is dropped — correct, since a retry of
 * the same too-big body can't succeed. Realistic payloads (a 50–100 KB GitHub
 * push diff) sit well under the ceiling.
 *
 * On success it stashes the resolved adapter + raw body + scope + provider on
 * the request so the controller doesn't re-resolve or re-read them.
 */
class VerifyHmacSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $provider = $request->route('provider');
        $provider = is_string($provider) ? $provider : '';
        if (! ProviderName::matches($provider)) {
            return $this->fail('invalid_provider', 400);
        }
        if (! WebhookAdapterFactory::supports($provider)) {
            return $this->fail('unknown_provider', 400);
        }

        $scopeId = $request->query('b');
        if (! is_string($scopeId) || ! ScopeId::matches($scopeId)) {
            return $this->fail('invalid_scope', 400);
        }

        $secret = WebhookSecretResolver::resolve($provider, $scopeId);
        if ($secret instanceof WebhookSecretFailure) {
            return $this->failSecret($secret);
        }

        $body = $request->getContent();
        $adapter = WebhookAdapterFactory::for($provider);

        if (! $adapter->verifySignature($request, $body, $secret)) {
            return $this->fail('sig_mismatch', 401);
        }

        $request->attributes->set('bridge.provider', $provider);
        $request->attributes->set('bridge.scope_id', $scopeId);
        $request->attributes->set('bridge.body', $body);

        return $next($request);
    }

    /**
     * The receiver's HTTP contract over the secret-resolution vocabulary — the mapping
     * that decides whether kanban-board RETRIES (5xx) or drops (4xx), so it lives here,
     * beside the status contract this class documents, rather than on the enum that a
     * CLI reads too. Exhaustive on purpose: a new case must be given a status
     * deliberately instead of inheriting one from a `default` arm.
     *
     * A group/world-readable secret is 500 (not 401) so kanban-board holds and
     * redelivers once it is fixed, rather than the secret being silently trusted
     * (DL-010).
     *
     * ⛔ `SecretUnreadable` answers `unknown_scope` DELIBERATELY, and the collapse is the
     * contract rather than an oversight: the caller is unauthenticated, so it learns
     * nothing about this install's filesystem — and, load-bearing, an OS permission
     * fault must not flip the upstream's retry decision. `bridge:sign` reads the finer
     * case and tells the operator which of the two it actually is.
     */
    private function failSecret(WebhookSecretFailure $failure): Response
    {
        return match ($failure) {
            WebhookSecretFailure::ConfigSecretDirMissing,
            WebhookSecretFailure::ConfigSecretDirNotAbsolute,
            WebhookSecretFailure::SecretPermsInsecure,
            WebhookSecretFailure::EmptySecretFile => $this->fail($failure->value, 500),
            WebhookSecretFailure::UnknownScope,
            WebhookSecretFailure::SecretUnreadable => $this->fail(WebhookSecretFailure::UnknownScope->value, 401),
        };
    }

    private function fail(string $reason, int $code): Response
    {
        return PlainTextResponse::make($reason, $code);
    }
}
