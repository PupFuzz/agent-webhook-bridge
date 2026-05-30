# Provider adapters

How to add support for a new webhook-emitting upstream. The bridge ships two reference adapters (kanban-board + GitHub); either is a good template for a third.

## One PHP class per provider

Every provider-specific concern lives in **one PHP adapter class** implementing the `WebhookAdapter` contract. Adding a provider means:

1. `app/Bridge/Adapters/<Provider>Adapter.php` — the adapter class
2. One entry in `WebhookAdapterFactory` — a slug in `SUPPORTED` and a `match` arm
3. A per-scope HMAC secret on disk (written by `bridge:provision` if the provider is API-provisionable; written manually for providers like GitHub whose webhooks are configured in their own UI)

The middleware, controller, dispatch loop, and CLI commands are all provider-agnostic.

## The WebhookAdapter contract

```php
namespace App\Bridge\Contracts;

use App\Bridge\Adapters\EventDto;
use Illuminate\Http\Request;

interface WebhookAdapter
{
    /**
     * Constant-time verification of the provider's signature header against
     * the raw body bytes (HMAC-SHA256). Returns false on any mismatch or a
     * malformed/absent signature header.
     */
    public function verifySignature(Request $request, string $body, string $secret): bool;

    /**
     * Extract the bridge envelope from the (already verified) request + body.
     *
     * @throws InvalidEnvelopeException on undecodable JSON, a missing
     *                                  required field/header, a non-scalar
     *                                  field, or an over-length delivery_id.
     */
    public function parse(Request $request, string $body): EventDto;

    /**
     * Whether this is a provider connectivity-test ("ping") event, which the
     * receiver accepts and no-ops (no scope check, no persistence).
     */
    public function isPing(EventDto $event): bool;
}
```

`verifySignature` and `parse` are intentionally split: verification runs in `VerifyHmacSignature` middleware (before the controller sees the request); `parse` runs in the controller once the request is trusted. The raw body bytes are stashed on the request (`bridge.body`) so both methods see the same unmodified bytes.

`EventDto` carries only the four bridge routing fields:

```php
final class EventDto
{
    public function __construct(
        public readonly string $deliveryId,  // unique per delivery — the dedup gate
        public readonly string $scopeId,     // subscription scope (board id, repo full_name, …)
        public readonly string $eventType,   // dotted name (e.g. "task.moved", "pull_request.opened")
        public readonly ?string $actorId,    // null for system-emitted events
    ) {}
}
```

## How HMAC verification plugs in

Most providers use HMAC-SHA256 with a `sha256=<hex>` prefix in a custom header — the same scheme as kanban-board and GitHub. `AbstractWebhookAdapter` provides the shared constant-time verify path. Subclass it and declare the header name:

```php
namespace App\Bridge\Adapters;

final class AcmeAdapter extends AbstractWebhookAdapter
{
    protected function signatureHeader(): string
    {
        // Must carry a `sha256=<hex>` HMAC of the raw body (like kanban-board's
        // X-Kanban-Signature / GitHub's X-Hub-Signature-256). NOT the right base
        // class for an opaque-token header such as GitLab's X-Gitlab-Token.
        return 'X-Acme-Signature';
    }

    public function parse(Request $request, string $body): EventDto
    {
        // ...
    }

    public function isPing(EventDto $event): bool
    {
        return false;   // adjust if the provider sends a connectivity-test event
    }
}
```

`AbstractWebhookAdapter::verifySignature` reads the named header, checks the `sha256=` prefix, and does a `hash_equals` constant-time compare — one auditable path for all `sha256=<hex>` providers.

If your provider uses a different signing scheme (e.g. GitLab's `X-Gitlab-Token`, which carries the raw secret rather than a HMAC), implement `WebhookAdapter` directly without extending `AbstractWebhookAdapter` and write your own `verifySignature` (for an opaque token: a constant-time compare of the header against the on-disk secret).

The abstract class exposes parse helpers:

| Helper | What it does |
|---|---|
| `$this->decodeJson($body)` | JSON-decode to `array<mixed>`, throws `InvalidEnvelopeException` on failure |
| `$this->requireScalar($decoded, $key)` | Return a required scalar field as `string`; throws if absent or non-scalar |
| `$this->optionalScalar($decoded, $key)` | Return a scalar field as `?string`; returns `null` if absent or null |
| `$this->assertDeliveryIdLength($id)` | Throw if `strlen > 64` (the `webhook_events.delivery_id` column width) |

## Implementing parse

`parse` receives the verified request and its raw body. It must return an `EventDto`. Any fatal condition throws `InvalidEnvelopeException` — the controller turns that into a 400.

**Event type in the body** (like kanban-board):

```php
public function parse(Request $request, string $body): EventDto
{
    $decoded = $this->decodeJson($body);

    $deliveryId = $this->requireScalar($decoded, 'delivery_id');
    $this->assertDeliveryIdLength($deliveryId);

    return new EventDto(
        deliveryId: $deliveryId,
        scopeId:    $this->requireScalar($decoded, 'project_id'),
        eventType:  $this->requireScalar($decoded, 'event_name'),
        actorId:    $this->optionalScalar($decoded, 'user_username'),
    );
}
```

**Event type in a header** (like GitHub):

```php
public function parse(Request $request, string $body): EventDto
{
    $deliveryId = $request->header('X-YourProvider-Delivery');
    $eventName  = $request->header('X-YourProvider-Event');

    if (! is_string($deliveryId) || $deliveryId === '') {
        throw new InvalidEnvelopeException('missing_header:X-YourProvider-Delivery');
    }
    if (! is_string($eventName) || $eventName === '') {
        throw new InvalidEnvelopeException('missing_header:X-YourProvider-Event');
    }
    $this->assertDeliveryIdLength($deliveryId);

    $decoded = $this->decodeJson($body);
    $action  = $this->optionalScalar($decoded, 'action');
    $eventType = $action !== null ? "{$eventName}.{$action}" : $eventName;

    return new EventDto(
        deliveryId: $deliveryId,
        scopeId:    $this->requireScalar($decoded, 'project') . '/' . $this->requireScalar($decoded, 'repo'),
        eventType:  $eventType,
        actorId:    $this->optionalScalar($decoded, 'sender'),
    );
}
```

## Register in WebhookAdapterFactory

Add two things — a slug in `SUPPORTED` and a `match` arm in `for`:

```php
final class WebhookAdapterFactory
{
    public const SUPPORTED = ['kanban', 'github', 'your_provider'];  // 1. add slug

    public static function for(string $provider): WebhookAdapter
    {
        return match ($provider) {
            'kanban'        => new KanbanAdapter,
            'github'        => new GitHubAdapter,
            'your_provider' => new YourProviderAdapter,              // 2. add arm
            default         => throw new UnknownProviderException($provider),
        };
    }
}
```

After this change the middleware will accept `POST /webhooks/your_provider?b=<scope>` requests.

## Secret on disk

The middleware loads the per-`(provider, scope)` HMAC secret from:

```
<bridge.secret_dir>/<provider>/webhook-secret-scope-<scope>
```

For API-provisionable providers, `bridge:provision` writes this file before creating the subscription. For providers like GitHub, write the secret manually:

```bash
mkdir -p "$BRIDGE_SECRET_DIR/your_provider"
# generate the same secret you entered in the provider's webhook settings
echo -n "your-hmac-secret" > "$BRIDGE_SECRET_DIR/your_provider/webhook-secret-scope-<scope>"
chmod 600 "$BRIDGE_SECRET_DIR/your_provider/webhook-secret-scope-<scope>"
```

Scope values containing `/` (like GitHub's `org/repo`) are URL-encoded on disk so the secret stays a single-segment filename: `webhook-secret-scope-org%2Frepo`. The middleware and provisioner share `SecretPath::for($secretDir, $provider, $scopeId)` — do not encode manually.

## API-provisionable providers (optional)

If the upstream exposes a webhook management API you can automate the subscription lifecycle via `bridge:provision`. GitHub webhooks are configured in repo settings and are not provisionable by the bridge. kanban-board is fully provisionable.

For a new API-provisionable provider, add a provision client modeled on `KanbanProvisionClient`:

```php
namespace App\Bridge\Provision;

use Illuminate\Support\Facades\Http;

final class YourProviderProvisionClient
{
    public function __construct(
        private string $baseUrl,
        private string $token,
    ) {}

    /** @return list<array<string, mixed>> */
    public function listWebhooks(string $scopeId): array { /* ... */ }

    /** @return array<string, mixed> */
    public function createWebhook(string $scopeId, string $url, string $secret, ?array $eventFilter): array { /* ... */ }

    public function deleteWebhook(int|string $webhookId): void { /* ... */ }
}
```

`WebhookProvisioner` drives the idempotent ensure/reconcile loop. Use `app/Console/Commands/Bridge/ProvisionCommand.php` as the template for a new provider's command.

Secret-ordering invariant (enforced in `WebhookProvisioner::createWithSecret`): the per-scope HMAC secret is written **before** the upstream subscription is created so a create-succeeds-but-secret-write-fails window can never leave the receiver unable to verify deliveries.

## Tests for a new adapter

- **Unit test** — `tests/Feature/Adapters/<Provider>AdapterTest.php`. Construct the adapter directly, pass crafted `Request::create(...)` objects, and assert on `parse` output + `verifySignature` return values. See `WebhookReceiveTest` for how to construct signed requests.
- **Feature test** — extend or parallel `WebhookReceiveTest` to cover the full HTTP path through middleware + adapter, asserting on the status contract (kanban-board retries 5xx/429 and does not retry other 4xx).

---

## Shipped adapter: kanban-board

| Concern | Value |
|---|---|
| Class | `App\Bridge\Adapters\KanbanAdapter` |
| HMAC header | `X-Kanban-Signature: sha256=<hex>` |
| Algorithm | HMAC-SHA256 via `AbstractWebhookAdapter` |
| `delivery_id` source | Body field `delivery_id` |
| `event_type` source | Body field `event` (e.g. `task.moved`) |
| `scope_id` source | Body field `board_id` (cast to string) |
| `actor_id` source | Body field `user_id` (null for system events) |
| Ping events | None — kanban-board does not emit `ping` |
| Event filter syntax | Globs: `task.*`, `comment.*`; `null` for all |
| API-provisionable | Yes — `bridge:provision` registers subscriptions via kanban-board's webhook API |

## Shipped adapter: GitHub

| Concern | Value |
|---|---|
| Class | `App\Bridge\Adapters\GitHubAdapter` |
| HMAC header | `X-Hub-Signature-256: sha256=<hex>` |
| Algorithm | HMAC-SHA256 via `AbstractWebhookAdapter` |
| `delivery_id` source | `X-GitHub-Delivery` request header (UUID) |
| `event_type` source | `X-GitHub-Event` header + body `action` field, composite: `pull_request.opened`; events without an action (e.g. `push`) use the bare header value |
| `scope_id` source | Body `repository.full_name` (e.g. `org/repo`; stored on disk as `org%2Frepo`) |
| `actor_id` source | Body `sender.id` (the immutable numeric account id — usernames rename, so the login is never the matching key; see DL-002) |
| Ping events | YES — `X-GitHub-Event: ping` sent on every subscription creation. `isPing` returns true; receiver 200-pongs without persisting. |
| Event filter syntax | Per-event-name list (no globs): `events: ["push", "pull_request"]`; `["*"]` for all |
| API-provisionable | No — webhooks are configured in repo/org settings; write the HMAC secret to disk manually (see "Secret on disk" above) |

Notable wire-shape differences from kanban-board:

- **Event type composition** — kanban-board's `task.moved` is the full event name in the body; GitHub's `pull_request.opened` is assembled by the adapter from the `X-GitHub-Event` header and the body's `action` field.
- **Scope contains `/`** — GitHub's `org/repo` slug is URL-encoded to `%2F` for the on-disk secret filename so scopes `foo` and `foo/bar` can coexist without filesystem collision.
- **Actor is the immutable numeric id, not the username** — `sender.id` is the matching key (each agent's `identity.github_user_id` in its `<agent>.yml`), because GitHub usernames are renameable and a rename must not break recognition or echo-suppression (DL-002). `sender.login` is still in the payload for surface display, and an `identity` block may carry it as a display-only `github_login` label (a drift warning fires if it goes stale).

## Adding a third provider — quick checklist

1. Write `app/Bridge/Adapters/<Provider>Adapter.php` extending `AbstractWebhookAdapter` (or implementing `WebhookAdapter` directly for non-`sha256=` signing schemes).
2. Add the slug to `WebhookAdapterFactory::SUPPORTED` and a `match` arm to `WebhookAdapterFactory::for`.
3. Write the HMAC secret to disk (manually or via a new provision command).
4. If the provider is API-provisionable: write a provision client + command modeled on `KanbanProvisionClient` + `ProvisionCommand`.
5. Add adapter tests (unit + feature HTTP path).
