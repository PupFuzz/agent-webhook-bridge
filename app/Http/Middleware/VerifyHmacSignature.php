<?php

namespace App\Http\Middleware;

use App\Bridge\Adapters\WebhookAdapterFactory;
use App\Bridge\Support\SecretFile;
use App\Bridge\Support\SecretPath;
use App\Bridge\Validation\ProviderName;
use App\Bridge\Validation\ScopeId;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The receiver's security gate.
 * Resolves the adapter from the {provider} route segment and the scope from
 * the `?b=` query, loads the per-(provider, scope) HMAC secret, and verifies
 * the signature with a constant-time compare — all before the request reaches
 * the controller. Preserves the exact status contract so kanban-board's retry
 * behaviour is unchanged (it retries 5xx/429, not other 4xx):
 *
 *   invalid_provider / unknown_provider / invalid_scope → 400
 *   unknown_scope (no secret file) / sig_mismatch       → 401
 *   config_secret_dir_* / empty_secret_file             → 500
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

        $secret = $this->loadSecret($provider, $scopeId, $error);
        if ($secret === null) {
            return $error;
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
     * Load + trim the per-(provider, scope) secret. Returns the secret on
     * success, or null with $error set to the response to return. The secret
     * is keyed on the URL's (provider, scope) — the canonical one-secret-per-
     * scope umbrella model — never on subscriber identity.
     */
    private function loadSecret(string $provider, string $scopeId, ?Response &$error): ?string
    {
        $secretDir = config('bridge.secret_dir');
        if (! is_string($secretDir) || trim($secretDir) === '') {
            $error = $this->fail('config_secret_dir_missing', 500);

            return null;
        }
        if (! str_starts_with($secretDir, '/')) {
            $error = $this->fail('config_secret_dir_not_absolute', 500);

            return null;
        }

        // Shared path shape (see SecretPath) — the contract provisioning writes to.
        $secretPath = SecretPath::for($secretDir, $provider, $scopeId);

        // Fail-closed on a group/world-readable secret (DL-010): a co-tenant who
        // can read it forges valid signatures, so a leaked-perms secret is no
        // boundary. 500 (not 401) so kanban-board holds + redelivers once fixed,
        // rather than the secret being silently trusted. Checked before the read
        // so an *absent* secret still 401s (unknown_scope) — isInsecure is false
        // for a missing file.
        if (SecretFile::isInsecure($secretPath)) {
            $error = $this->fail('secret_perms_insecure', 500);

            return null;
        }

        $raw = @file_get_contents($secretPath);
        if ($raw === false) {
            $error = $this->fail('unknown_scope', 401);

            return null;
        }

        $secret = trim($raw);
        if ($secret === '') {
            $error = $this->fail('empty_secret_file', 500);

            return null;
        }

        return $secret;
    }

    private function fail(string $reason, int $code): Response
    {
        return response($reason, $code, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
